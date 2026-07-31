"""Forwarder behaviour against a stubbed ingestion API."""

from __future__ import annotations

import time
from pathlib import Path

import pytest

from qv_acq.forwarder import Forwarder, ForwarderConfig
from qv_acq.measurement import ChannelValue, Measurement, QualityStatus, utc_now
from qv_acq.profiles import loader
from qv_acq.spool import Spool


class StubTransport:
    """Records what was sent and replays scripted responses."""

    def __init__(self, responses=None) -> None:
        self.calls: list[tuple[str, dict]] = []
        self.responses = list(responses or [])

    def post(self, url: str, payload: dict, *, token: str, timeout: float):
        self.calls.append((url, payload))
        self.token = token
        if self.responses:
            return self.responses.pop(0)
        accepted = len(payload.get("measurements", []))
        return 202, {"accepted": accepted, "duplicates": 0, "rejected": 0, "errors": []}


def measurement(sequence: int, run_id: str = "run-a") -> Measurement:
    return Measurement(
        appliance_id="QV-EDGE-TEST", run_id=run_id, adapter_id="A1", bus_id="B1",
        sensor_id="SENSOR-001", sensor_model="WTVB01-485", profile_version="1.0.0",
        slave_id=0x50, group_key="acceleration", sequence=sequence,
        timestamp_utc=utc_now(), monotonic_ns=time.monotonic_ns(),
        channels={"accel_z": ChannelValue(value=0.993, unit="g", quality=QualityStatus.GOOD)},
    )


@pytest.fixture
def spool(tmp_path: Path) -> Spool:
    with Spool(tmp_path / "spool.db") as s:
        yield s


def forwarder(spool: Spool, transport: StubTransport, **kwargs) -> Forwarder:
    config = ForwarderConfig(token="test-token", batch_size=10, **kwargs)
    return Forwarder(spool, config, transport=transport, sleeper=lambda _: None)


class TestDelivery:
    def test_drains_the_spool(self, spool: Spool) -> None:
        for i in range(1, 26):
            spool.append(measurement(i))
        transport = StubTransport()

        result = forwarder(spool, transport).drain_once()

        assert result.batches == 3  # 10 + 10 + 5
        assert result.delivered == 25
        assert spool.backlog() == 0

    def test_sends_the_bearer_token(self, spool: Spool) -> None:
        spool.append(measurement(1))
        transport = StubTransport()
        forwarder(spool, transport).drain_once()
        assert transport.token == "test-token"

    def test_sends_the_full_envelope(self, spool: Spool) -> None:
        spool.append(measurement(1))
        transport = StubTransport()
        forwarder(spool, transport).drain_once()

        _, payload = transport.calls[0]
        envelope = payload["measurements"][0]
        assert envelope["schema_version"] == "1.0"
        assert envelope["run_id"] == "run-a"
        assert envelope["measurements"]["accel_z"]["unit"] == "g"

    def test_nothing_to_send_is_not_an_error(self, spool: Spool) -> None:
        transport = StubTransport()
        result = forwarder(spool, transport).drain_once()
        assert result.batches == 0 and transport.calls == []


class TestFailureHandling:
    def test_server_error_leaves_the_backlog_intact(self, spool: Spool) -> None:
        for i in range(1, 6):
            spool.append(measurement(i))
        transport = StubTransport([(500, {"message": "boom"})])

        result = forwarder(spool, transport).drain_once()

        assert result.failures == 1
        assert result.delivered == 0
        assert spool.backlog() == 5, "a failed batch must stay queued"

    def test_retry_after_recovery_delivers_everything(self, spool: Spool) -> None:
        for i in range(1, 6):
            spool.append(measurement(i))
        transport = StubTransport([(503, {})])
        fwd = forwarder(spool, transport)

        fwd.drain_once()
        assert spool.backlog() == 5
        result = fwd.drain_once()  # server is back

        assert result.delivered == 5
        assert spool.backlog() == 0

    def test_auth_failure_stops_immediately(self, spool: Spool) -> None:
        """A bad token will not fix itself; retrying only burns the budget.

        Without this, repeated 401s would push perfectly good measurements past
        max_retries and into dead-letter.
        """
        for i in range(1, 6):
            spool.append(measurement(i))
        transport = StubTransport([(401, {"message": "Unauthenticated."})])

        result = forwarder(spool, transport).run(iterations=5)

        assert result.fatal
        assert "authentication failed" in result.stopped_reason
        assert len(transport.calls) == 1, "must not keep hammering a rejected token"
        assert spool.backlog() == 5

    def test_backoff_grows_with_consecutive_failures(self, spool: Spool) -> None:
        spool.append(measurement(1))
        transport = StubTransport([(500, {}), (500, {}), (500, {})])
        fwd = forwarder(spool, transport, backoff_seconds=2.0)

        assert fwd.backoff_delay() == pytest.approx(5.0)  # idle interval
        fwd.drain_once()
        first = fwd.backoff_delay()
        fwd.drain_once()
        second = fwd.backoff_delay()

        assert second > first

    def test_backoff_is_capped(self, spool: Spool) -> None:
        spool.append(measurement(1))
        transport = StubTransport([(500, {})] * 30)
        fwd = forwarder(spool, transport, backoff_seconds=2.0, backoff_max_seconds=60.0)
        for _ in range(20):
            fwd.drain_once()
        assert fwd.backoff_delay() <= 60.0

    def test_dead_letters_are_not_retried_forever(self, spool: Spool) -> None:
        spool.append(measurement(1))
        transport = StubTransport([(500, {})] * 10)
        fwd = forwarder(spool, transport, max_retries=3)
        for _ in range(5):
            fwd.drain_once()

        # Past the retry ceiling the record stops being offered, so one poisoned
        # measurement cannot block everything behind it.
        assert spool.pending(10, max_retries=3) == []
        assert spool.dead_letters(max_retries=3) == 1


class TestDuplicatesAndRejects:
    def test_duplicates_count_as_success(self, spool: Spool) -> None:
        """Duplicates mean a previous attempt landed before we marked it."""
        for i in range(1, 4):
            spool.append(measurement(i))
        transport = StubTransport([(202, {"accepted": 0, "duplicates": 3, "rejected": 0})])

        result = forwarder(spool, transport).drain_once()

        assert result.duplicates == 3
        assert spool.backlog() == 0, "already-ingested records must be cleared, not retried"

    def test_rejected_envelopes_are_reported_and_cleared(self, spool: Spool) -> None:
        for i in range(1, 4):
            spool.append(measurement(i))
        transport = StubTransport([
            (202, {"accepted": 2, "duplicates": 0, "rejected": 1,
                   "errors": [{"index": 1, "error": "missing required field: run_id"}]}),
        ])

        result = forwarder(spool, transport).drain_once()

        # A malformed envelope will never be accepted; retrying it forever would
        # block the queue behind it.
        assert result.rejected == 1
        assert spool.backlog() == 0


class TestProfileAnnouncement:
    def test_announces_provenance_for_every_channel(self, spool: Spool) -> None:
        profile = loader.get("WTVB01-485")
        transport = StubTransport([(200, {"channels": 60, "trustworthy": True})])

        assert forwarder(spool, transport).announce_profile(
            "QV-EDGE-001", "SENSOR-001", profile, 0x50
        )

        _, payload = transport.calls[0]
        assert payload["sensor_model"] == "WTVB01-485"
        assert payload["verification_status"] == "verified"
        assert payload["slave_id"] == 0x50

        by_key = {c["channel_key"]: c for c in payload["channels"]}
        accel = by_key["accel_z"]
        assert accel["register_address"] == 0x36
        assert accel["quantity"] == "acceleration"
        assert accel["unit"] == "g"
        assert accel["scale"] == pytest.approx(0.00048828125)

        velocity = by_key["vib_velocity_x"]
        assert velocity["scale"] == pytest.approx(0.01)
        assert velocity["unit"] == "mm/s"

    def test_announcement_failure_is_reported_not_raised(self, spool: Spool) -> None:
        profile = loader.get("WTVB01-485")
        transport = StubTransport([(500, {"message": "boom"})])
        assert not forwarder(spool, transport).announce_profile(
            "QV-EDGE-001", "SENSOR-001", profile, 0x50
        )
