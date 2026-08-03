"""The live feed must never be able to cost a measurement.

Everything here is about that one property. The live view is a convenience that
can drop frames freely; the spool is the record and must be untouchable by any
failure downstream of it.
"""

from __future__ import annotations

import json
from datetime import datetime, timezone

import pytest

from qv_acq.live import RedisLivePublisher, TeeSink
from qv_acq.measurement import ChannelValue, Measurement, QualityStatus


def measurement(sensor_id: str = "SENSOR-001", **values: float) -> Measurement:
    return Measurement(
        appliance_id="QV-EDGE-TEST",
        run_id="run-1",
        adapter_id="usb-0",
        bus_id="bus-0",
        sensor_id=sensor_id,
        sensor_model="WTVB01-485",
        profile_version="1.0.0",
        slave_id=80,
        group_key="motion",
        sequence=1,
        timestamp_utc=datetime(2026, 7, 31, 12, 0, 0, tzinfo=timezone.utc),
        monotonic_ns=0,
        channels={
            key: ChannelValue(value=value, unit="g", quality=QualityStatus.GOOD)
            for key, value in values.items()
        },
    )


class Recorder:
    def __init__(self) -> None:
        self.seen: list[Measurement] = []

    def __call__(self, m: Measurement) -> None:
        self.seen.append(m)


class Exploding:
    def __call__(self, m: Measurement) -> None:
        raise RuntimeError("redis is on fire")


def test_durable_sink_still_receives_when_the_live_sink_fails() -> None:
    spool = Recorder()
    TeeSink(spool, Exploding())(measurement(accel_x=1.0))

    # The whole point: a broken live feed is invisible to the record.
    assert len(spool.seen) == 1


def test_a_failing_live_sink_does_not_stop_the_next_one() -> None:
    spool, other = Recorder(), Recorder()
    TeeSink(spool, Exploding(), other)(measurement(accel_x=1.0))

    assert len(other.seen) == 1


def test_durable_failures_are_not_swallowed() -> None:
    # The inverse guarantee. If the spool cannot accept a measurement that is a
    # real fault and must surface, not be absorbed by a convenience layer.
    with pytest.raises(RuntimeError):
        TeeSink(Exploding(), Recorder())(measurement(accel_x=1.0))


def test_publishing_never_raises_when_the_queue_is_full() -> None:
    publisher = RedisLivePublisher(max_queued=2, client=object())

    for _ in range(10):
        publisher(measurement(accel_x=1.0))  # no worker thread started, so nothing drains

    assert publisher.dropped == 8
    assert publisher.published == 0


def test_dropping_favours_the_frames_already_queued() -> None:
    publisher = RedisLivePublisher(max_queued=1, client=object())
    publisher(measurement(accel_x=1.0))
    publisher(measurement(accel_x=99.0))

    # The newest frame is dropped rather than evicting a queued one, so the
    # publisher can never block the poll loop waiting for room.
    assert publisher.dropped == 1
    assert json.loads(publisher._queue.get_nowait())["values"]["accel_x"] == 1.0


def test_payload_carries_what_a_dashboard_needs() -> None:
    publisher = RedisLivePublisher(max_queued=4, client=object())
    publisher(measurement(accel_x=0.5, accel_y=-0.25))
    payload = json.loads(publisher._queue.get_nowait())

    assert payload["sensor_id"] == "SENSOR-001"
    assert payload["group"] == "motion"
    assert payload["quality"] == "good"
    # Milliseconds since the epoch, so the browser can measure its own lag
    # against the moment the sensor was actually read.
    assert payload["t"] == 1785499200000
    assert payload["values"] == {"accel_x": 0.5, "accel_y": -0.25}


def test_channels_that_read_nothing_are_omitted_rather_than_sent_as_zero() -> None:
    m = measurement(accel_x=1.0)
    m.channels["accel_y"] = ChannelValue(value=None, unit="g", quality=QualityStatus.BAD)
    publisher = RedisLivePublisher(max_queued=4, client=object())
    publisher(m)

    # A missing reading plotted as 0.0 would look like a still structure rather
    # than an absent one.
    assert json.loads(publisher._queue.get_nowait())["values"] == {"accel_x": 1.0}


def test_a_dead_redis_is_counted_and_survived() -> None:
    class Dead:
        def publish(self, channel: str, message: str) -> None:
            raise ConnectionError("no route to host")

    publisher = RedisLivePublisher(max_queued=4, client=Dead())
    publisher(measurement(accel_x=1.0))
    publisher.start()
    try:
        deadline = 3.0
        step = 0.05
        waited = 0.0
        while publisher.failures == 0 and waited < deadline:
            import time

            time.sleep(step)
            waited += step
    finally:
        publisher.stop()

    assert publisher.failures >= 1
    assert publisher.published == 0
