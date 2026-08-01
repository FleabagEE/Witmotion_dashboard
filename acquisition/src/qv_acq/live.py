"""Low-latency live feed, alongside the durable path.

The spool is the system of record: everything is written there first and
forwarded in order, so nothing is lost when the database or network are down.
That guarantee costs about a second of latency, which is right for history and
wrong for somebody watching a wall display while they tap a structure.

So measurements take two paths. The durable one is unchanged. This one publishes
the same reading to Redis the instant it is decoded, for a websocket server to
fan out to browsers. It is explicitly lossy: if Redis is down, or slow, or the
buffer is full, the reading is dropped from *this* path and nothing else notices.
A live view that stalls acquisition would be a far worse trade than a live view
that skips a frame.
"""

from __future__ import annotations

import json
import logging
import threading
import time
from queue import Empty, Full, Queue

from .measurement import Measurement

log = logging.getLogger("qv_acq.live")

DEFAULT_CHANNEL = "quakevault:live"


class RedisLivePublisher:
    """Fire-and-forget publisher on a background thread.

    Publishing happens off the acquisition thread on purpose. A blocking network
    call in the poll loop would turn a Redis hiccup into missed polls, and the
    poll loop is the one thing in this service that must not be delayed.
    """

    def __init__(
        self,
        url: str = "redis://127.0.0.1:6380/0",
        channel: str = DEFAULT_CHANNEL,
        *,
        max_queued: int = 500,
        client=None,
    ) -> None:
        self.url = url
        self.channel = channel
        self._queue: Queue[str] = Queue(maxsize=max_queued)
        self._client = client
        self._stop = threading.Event()
        self._thread: threading.Thread | None = None

        self.published = 0
        self.dropped = 0
        self.failures = 0

    def start(self) -> None:
        if self._thread is not None:
            return
        self._thread = threading.Thread(target=self._run, name="live-publisher", daemon=True)
        self._thread.start()

    def stop(self) -> None:
        self._stop.set()
        if self._thread is not None:
            self._thread.join(timeout=2.0)
            self._thread = None

    def __call__(self, measurement: Measurement) -> None:
        """Sink interface. Never raises, never blocks."""
        payload = {
            "sensor_id": measurement.sensor_id,
            "group": measurement.group_key,
            "t": int(measurement.timestamp_utc.timestamp() * 1000),
            "quality": str(measurement.status),
            "values": {
                key: channel.value
                for key, channel in measurement.channels.items()
                if channel.value is not None
            },
        }
        try:
            self._queue.put_nowait(json.dumps(payload, separators=(",", ":")))
        except Full:
            # The consumer is behind. Drop the newest rather than block the poll
            # loop or grow without bound; a live view may skip a frame.
            self.dropped += 1

    def _connect(self):
        if self._client is not None:
            return self._client
        import redis  # imported lazily so the package is optional

        self._client = redis.Redis.from_url(
            self.url, socket_timeout=2.0, socket_connect_timeout=2.0
        )
        return self._client

    def _run(self) -> None:
        backoff = 1.0
        while not self._stop.is_set():
            try:
                message = self._queue.get(timeout=0.25)
            except Empty:
                continue

            try:
                self._connect().publish(self.channel, message)
                self.published += 1
                backoff = 1.0
            except Exception as exc:  # noqa: BLE001
                self.failures += 1
                self._client = None
                if self.failures % 50 == 1:
                    log.warning("live publish failed (%s); live view degraded only", exc)
                # Back off rather than spin: the durable path is unaffected, so
                # there is no urgency here.
                time.sleep(min(backoff, 10.0))
                backoff *= 2

    def stats(self) -> dict[str, int]:
        return {
            "published": self.published,
            "dropped": self.dropped,
            "failures": self.failures,
            "queued": self._queue.qsize(),
        }


class TeeSink:
    """Sends each measurement to several sinks, isolating their failures.

    The durable sink runs first and is allowed to raise; anything after it is
    best-effort. Ordering matters: a live view must never be able to prevent a
    measurement being written to the spool.
    """

    def __init__(self, durable, *best_effort) -> None:
        self.durable = durable
        self.best_effort = best_effort

    def __call__(self, measurement: Measurement) -> None:
        self.durable(measurement)
        for sink in self.best_effort:
            try:
                sink(measurement)
            except Exception as exc:  # noqa: BLE001
                log.debug("best-effort sink failed: %s", exc)
