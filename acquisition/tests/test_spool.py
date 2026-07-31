"""Spool durability, ordering, idempotency and bounded growth."""

from __future__ import annotations

import asyncio
import os
import signal
import subprocess
import sys
import time
from pathlib import Path

import pytest

from qv_acq.engine import AcquisitionEngine, SensorBinding
from qv_acq.measurement import ChannelValue, Measurement, QualityStatus, utc_now
from qv_acq.profiles import loader
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.server import SimulatorServer
from qv_acq.spool import Spool, SpoolCorruption, SpoolingSink, replay

SRC = str(Path(__file__).resolve().parents[1] / "src")


def make_measurement(
    sequence: int, *, sensor_id: str = "SENSOR-001", run_id: str = "run-a"
) -> Measurement:
    return Measurement(
        appliance_id="QV-EDGE-TEST",
        run_id=run_id,
        adapter_id="A1",
        bus_id="BUS-001",
        sensor_id=sensor_id,
        sensor_model="WTVB01-485",
        profile_version="1.0.0",
        slave_id=0x50,
        group_key="acceleration",
        sequence=sequence,
        timestamp_utc=utc_now(),
        monotonic_ns=time.monotonic_ns(),
        channels={"accel_z": ChannelValue(value=0.993, unit="g", quality=QualityStatus.GOOD)},
    )


@pytest.fixture
def spool(tmp_path: Path) -> Spool:
    with Spool(tmp_path / "spool.db") as s:
        yield s


class TestBasics:
    def test_append_and_read_back(self, spool: Spool) -> None:
        assert spool.append(make_measurement(1)) is True
        records = spool.pending()
        assert len(records) == 1
        assert records[0].payload["measurements"]["accel_z"]["value"] == 0.993

    def test_duplicate_is_rejected_not_duplicated(self, spool: Spool) -> None:
        measurement = make_measurement(1)
        assert spool.append(measurement) is True
        assert spool.append(measurement) is False
        assert spool.total() == 1
        assert spool.counters()["duplicates_rejected"] == 1

    def test_replay_is_ordered_oldest_first(self, spool: Spool) -> None:
        for i in range(1, 21):
            spool.append(make_measurement(i))
        records = spool.pending(limit=20)
        assert [r.sequence for r in records] == list(range(1, 21))

    def test_delivery_clears_backlog(self, spool: Spool) -> None:
        for i in range(1, 6):
            spool.append(make_measurement(i))
        assert spool.backlog() == 5
        spool.mark_delivered([r.id for r in spool.pending()])
        assert spool.backlog() == 0
        assert spool.delivered() == 5

    def test_failure_increments_retry_count(self, spool: Spool) -> None:
        spool.append(make_measurement(1))
        ids = [r.id for r in spool.pending()]
        spool.mark_failed(ids, "boom")
        assert spool.pending()[0].retry_count == 1

    def test_dead_letters_are_excluded_from_replay(self, spool: Spool) -> None:
        spool.append(make_measurement(1))
        for _ in range(3):
            spool.mark_failed([r.id for r in spool.pending(max_retries=99)])
        assert spool.pending(max_retries=3) == []
        assert spool.dead_letters(max_retries=3) == 1


class TestBoundedGrowth:
    def test_cap_is_enforced(self, tmp_path: Path) -> None:
        with Spool(tmp_path / "s.db", max_rows=50) as s:
            for i in range(1, 121):
                s.append(make_measurement(i))
            assert s.total() <= 50

    def test_delivered_rows_are_dropped_before_undelivered(self, tmp_path: Path) -> None:
        with Spool(tmp_path / "s.db", max_rows=20) as s:
            for i in range(1, 16):
                s.append(make_measurement(i))
            s.mark_delivered([r.id for r in s.pending(limit=10)])
            for i in range(16, 31):
                s.append(make_measurement(i))
            counters = s.counters()
            assert counters.get("delivered_pruned", 0) > 0
            # Undelivered data is only sacrificed once delivered rows run out.
            assert counters.get("undelivered_dropped", 0) < counters["delivered_pruned"]

    def test_dropping_undelivered_data_is_counted_never_silent(self, tmp_path: Path) -> None:
        with Spool(tmp_path / "s.db", max_rows=10) as s:
            for i in range(1, 41):
                s.append(make_measurement(i))
            assert s.counters().get("undelivered_dropped", 0) > 0

    def test_cap_can_refuse_to_sacrifice_undelivered(self, tmp_path: Path) -> None:
        with Spool(tmp_path / "s.db", max_rows=10, drop_undelivered_when_full=False) as s:
            for i in range(1, 41):
                s.append(make_measurement(i))
            assert s.counters().get("undelivered_dropped", 0) == 0
            assert s.total() == 40  # growth preferred over data loss


class TestIntegrity:
    def test_clean_spool_verifies(self, spool: Spool) -> None:
        for i in range(1, 11):
            spool.append(make_measurement(i))
        assert spool.verify() == []

    def test_tampered_payload_is_detected(self, tmp_path: Path) -> None:
        path = tmp_path / "s.db"
        with Spool(path) as s:
            s.append(make_measurement(1))
            s._db.execute("UPDATE spool SET payload = ? WHERE id = 1", ('{"tampered":true}',))
            assert s.verify() == [1]
            with pytest.raises(SpoolCorruption, match="failed its checksum"):
                s.pending()


class TestCrashSafety:
    def test_survives_sigkill_mid_write(self, tmp_path: Path) -> None:
        """Pull the plug during sustained writes; committed rows must survive.

        This is the scenario the spool exists for, so it is tested by actually
        killing a process rather than by asserting that WAL mode is enabled.
        """
        path = tmp_path / "crash.db"
        # Self-contained writer, so the subprocess needs nothing from this module.
        script = f"""
import sys, time
sys.path.insert(0, {SRC!r})
from qv_acq.spool import Spool
from qv_acq.measurement import Measurement, ChannelValue, QualityStatus, utc_now
s = Spool({str(path)!r})
i = 0
while True:
    i += 1
    s.append(Measurement(
        appliance_id="QV", run_id="crash-run", adapter_id="A1", bus_id="B1", sensor_id="S1",
        sensor_model="WTVB01-485", profile_version="1.0.0", slave_id=80,
        group_key="acceleration", sequence=i, timestamp_utc=utc_now(),
        monotonic_ns=time.monotonic_ns(),
        channels={{"accel_z": ChannelValue(value=0.993, unit="g", quality=QualityStatus.GOOD)}},
    ))
"""
        proc = subprocess.Popen([sys.executable, "-c", script], stdout=subprocess.DEVNULL)
        time.sleep(2.0)
        os.kill(proc.pid, signal.SIGKILL)
        proc.wait(timeout=5)
        assert proc.returncode == -signal.SIGKILL

        with Spool(path) as reopened:
            assert reopened.total() > 0, "no records survived the kill"
            assert reopened.verify() == [], "corruption after SIGKILL"
            sequences = [r.sequence for r in reopened.pending(limit=100_000)]
            # Ordered, gapless, and still writable after recovery.
            assert sequences == sorted(sequences)
            assert sequences == list(range(1, len(sequences) + 1))
            assert reopened.append(make_measurement(len(sequences) + 1)) is True


class TestReplay:
    def test_drains_in_order(self, spool: Spool) -> None:
        for i in range(1, 26):
            spool.append(make_measurement(i))
        seen: list[int] = []

        def deliver(records) -> bool:
            seen.extend(r.sequence for r in records)
            return True

        result = replay(spool, deliver, batch_size=10)
        assert seen == list(range(1, 26))
        assert result["delivered"] == 25
        assert result["backlog"] == 0

    def test_stops_at_first_failure_preserving_order(self, spool: Spool) -> None:
        for i in range(1, 31):
            spool.append(make_measurement(i))
        calls = {"n": 0}

        def deliver(records) -> bool:
            calls["n"] += 1
            return calls["n"] == 1  # first batch lands, second fails

        result = replay(spool, deliver, batch_size=10)
        assert result["delivered"] == 10
        assert result["backlog"] == 20
        # Order must hold: the failed batch stays at the head of the queue.
        assert [r.sequence for r in spool.pending(limit=5)] == [11, 12, 13, 14, 15]

    def test_redelivery_after_restart_is_idempotent(self, spool: Spool) -> None:
        for i in range(1, 11):
            spool.append(make_measurement(i))
        replay(spool, lambda records: True, batch_size=10)
        # A crashed forwarder may re-offer the same measurements.
        for i in range(1, 11):
            assert spool.append(make_measurement(i)) is False
        assert spool.backlog() == 0


class TestEngineIntegration:
    def test_engine_writes_through_to_the_spool(self, tmp_path: Path) -> None:
        profile = loader.get("WTVB01-485")
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("acceleration",), poll_hz={"acceleration": 20},
        )

        with Spool(tmp_path / "spool.db") as spool:
            sink = SpoolingSink(spool)
            with SimulatorServer({0x50: device}) as server:
                engine = AcquisitionEngine("QV-EDGE-TEST", sink)
                engine.add_bus(
                    bus_id="BUS-001", adapter_id="A1", port=server.port,
                    baud=115200, sensors=[binding], use_lock=False,
                )
                asyncio.run(engine.run(duration=1.0))

            assert sink.accepted > 5
            assert sink.duplicates == 0
            assert spool.backlog() == sink.accepted
            assert spool.verify() == []
            sequences = [r.sequence for r in spool.pending(limit=1000)]
            assert sequences == list(range(1, len(sequences) + 1))


class TestRestartSafety:
    def test_restarting_the_service_does_not_drop_measurements(self, spool: Spool) -> None:
        """Regression: sequence numbers restart at 1 on every service start.

        With an idempotency key of appliance:sensor:group:sequence, the first N
        measurements after a restart collided with the previous run's and were
        silently rejected as duplicates. Found in production data: 98 real
        measurements lost across three restarts. The key now includes a run id.
        """
        for i in range(1, 21):
            assert spool.append(make_measurement(i, run_id="run-a")) is True

        # Service restarts: sequence counters go back to 1.
        for i in range(1, 21):
            assert spool.append(make_measurement(i, run_id="run-b")) is True, (
                f"measurement {i} of the new run was rejected as a duplicate"
            )

        assert spool.total() == 40
        assert spool.counters().get("duplicates_rejected", 0) == 0

    def test_replay_within_a_run_is_still_idempotent(self, spool: Spool) -> None:
        """The fix must not weaken deduplication inside a single run."""
        for i in range(1, 11):
            spool.append(make_measurement(i, run_id="run-a"))
        for i in range(1, 11):
            assert spool.append(make_measurement(i, run_id="run-a")) is False
        assert spool.total() == 10

    def test_run_id_is_recorded_for_forensics(self, spool: Spool) -> None:
        spool.append(make_measurement(1, run_id="run-xyz"))
        row = spool._db.execute("SELECT run_id FROM spool").fetchone()
        assert row["run_id"] == "run-xyz"
