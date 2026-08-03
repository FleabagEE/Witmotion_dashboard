"""Asynchronous acquisition engine.

Responsibilities, per the appliance design:

  * exactly one owner per serial port, enforced by an advisory lock;
  * strictly serialised request/response scheduling per RS-485 bus, because the
    bus is half duplex and two in-flight transactions corrupt each other;
  * independent poll rates per sensor and per register group;
  * bounded retries with exponential backoff;
  * a per-sensor circuit breaker, so one dead device cannot starve a shared bus;
  * UTC and monotonic timestamps, sequence numbers, quality flags;
  * measured effective sample rate and jitter, reported alongside the configured
    rate - never instead of it.

Buses run concurrently with each other and serially within themselves. The
blocking Modbus client is confined to one worker thread per bus, which is what
makes "serial within a bus" true by construction rather than by convention.
"""

from __future__ import annotations

import asyncio
import statistics
import uuid
import time
from collections import deque
from concurrent.futures import ThreadPoolExecutor
from dataclasses import dataclass, field, replace
from enum import StrEnum
from typing import Awaitable, Callable, Iterable

from .calibration import SensorCalibration, IDENTITY
from .derive import derive_for_group
from .client import ModbusReader, Quality
from .measurement import ChannelValue, Measurement, QualityStatus, ValueClass, utc_now
from .portlock import PortLock
from .profiles.schema import RegisterGroup, SensorProfile

Sink = Callable[[Measurement], None | Awaitable[None]]

_QUALITY_MAP = {
    Quality.GOOD: QualityStatus.GOOD,
    Quality.IMPLAUSIBLE: QualityStatus.IMPLAUSIBLE,
    Quality.BAD: QualityStatus.BAD,
}


class BreakerState(StrEnum):
    CLOSED = "closed"
    OPEN = "open"
    HALF_OPEN = "half_open"


@dataclass
class BreakerConfig:
    failure_threshold: int = 3
    cooldown_seconds: float = 5.0

    def __post_init__(self) -> None:
        if self.failure_threshold < 1:
            raise ValueError("failure_threshold must be >= 1")
        if self.cooldown_seconds <= 0:
            raise ValueError("cooldown_seconds must be positive")


@dataclass
class RetryConfig:
    max_attempts: int = 2
    backoff_seconds: float = 0.05
    backoff_multiplier: float = 2.0

    def delay_for(self, attempt: int) -> float:
        return self.backoff_seconds * (self.backoff_multiplier ** max(0, attempt - 1))


@dataclass
class SensorBinding:
    """One sensor on a bus, and which of its groups to poll."""

    sensor_id: str
    profile: SensorProfile
    slave_id: int | None = None
    groups: tuple[str, ...] | None = None
    poll_hz: dict[str, float] = field(default_factory=dict)
    #: Per-unit accelerometer correction. Identity unless one was fitted, so an
    #: appliance never applies a correction nobody chose.
    calibration: SensorCalibration = IDENTITY

    def resolved_slave_id(self) -> int:
        return self.slave_id if self.slave_id is not None else self.profile.serial.default_slave_id

    def selected_groups(self) -> list[RegisterGroup]:
        if self.groups is None:
            return list(self.profile.register_groups)
        wanted = set(self.groups)
        missing = wanted - {g.key for g in self.profile.register_groups}
        if missing:
            raise KeyError(f"{self.profile.model} has no register group(s): {sorted(missing)}")
        return [g for g in self.profile.register_groups if g.key in wanted]


class TaskRuntime:
    """Scheduling state, breaker and metrics for one (sensor, group) pair."""

    def __init__(
        self,
        binding: SensorBinding,
        group: RegisterGroup,
        *,
        breaker: BreakerConfig,
        rate_window: int = 32,
    ) -> None:
        self.binding = binding
        self.group = group
        self.configured_hz = binding.poll_hz.get(group.key, group.default_poll_hz)
        self.interval = 1.0 / self.configured_hz
        self.breaker_config = breaker

        self.sequence = 0
        self.next_due = 0.0
        self.consecutive_failures = 0
        self.breaker = BreakerState.CLOSED
        self.opened_at = 0.0

        self.polls_ok = 0
        self.polls_failed = 0
        self.missed_polls = 0
        self.retries = 0
        self._completions: deque[float] = deque(maxlen=rate_window)
        self._latencies: deque[float] = deque(maxlen=rate_window)

    # -- scheduling ---------------------------------------------------------

    def due(self, now: float) -> bool:
        if self.breaker is BreakerState.OPEN:
            if now - self.opened_at < self.breaker_config.cooldown_seconds:
                return False
            # Cooldown elapsed: allow exactly one probe through.
            self.breaker = BreakerState.HALF_OPEN
            return True
        return now >= self.next_due

    def schedule_next(self, now: float) -> None:
        if self.next_due == 0.0:
            self.next_due = now + self.interval
            return
        # Count skipped slots rather than letting the schedule drift, so a slow
        # bus shows up as missed polls instead of a silently reduced rate.
        missed = 0
        while self.next_due <= now:
            self.next_due += self.interval
            missed += 1
        if missed > 1:
            self.missed_polls += missed - 1

    # -- outcomes -----------------------------------------------------------

    def record_success(self, now: float, latency_ms: float) -> None:
        self.polls_ok += 1
        self.consecutive_failures = 0
        self.breaker = BreakerState.CLOSED
        self._completions.append(now)
        self._latencies.append(latency_ms)

    def record_failure(self, now: float) -> None:
        self.polls_failed += 1
        self.consecutive_failures += 1
        if self.consecutive_failures >= self.breaker_config.failure_threshold:
            if self.breaker is not BreakerState.OPEN:
                self.breaker = BreakerState.OPEN
            self.opened_at = now

    # -- metrics ------------------------------------------------------------

    @property
    def measured_hz(self) -> float | None:
        """Effective rate from observed completions, not from configuration."""
        if len(self._completions) < 2:
            return None
        span = self._completions[-1] - self._completions[0]
        return (len(self._completions) - 1) / span if span > 0 else None

    @property
    def jitter_ms(self) -> float | None:
        if len(self._completions) < 3:
            return None
        gaps = [b - a for a, b in zip(self._completions, list(self._completions)[1:])]
        return statistics.pstdev(gaps) * 1000.0

    @property
    def mean_latency_ms(self) -> float | None:
        return statistics.mean(self._latencies) if self._latencies else None

    def stats(self) -> dict[str, object]:
        return {
            "sensor_id": self.binding.sensor_id,
            "group": self.group.key,
            "configured_hz": round(self.configured_hz, 3),
            "measured_hz": round(self.measured_hz, 3) if self.measured_hz else None,
            "jitter_ms": round(self.jitter_ms, 3) if self.jitter_ms else None,
            "mean_latency_ms": round(self.mean_latency_ms, 3) if self.mean_latency_ms else None,
            "polls_ok": self.polls_ok,
            "polls_failed": self.polls_failed,
            "missed_polls": self.missed_polls,
            "retries": self.retries,
            "breaker": str(self.breaker),
        }


class BusWorker:
    """Owns one serial port and polls every sensor on it, strictly in turn."""

    def __init__(
        self,
        *,
        bus_id: str,
        adapter_id: str,
        port: str,
        baud: int,
        sensors: Iterable[SensorBinding],
        appliance_id: str,
        run_id: str,
        sink: Sink,
        timeout: float = 1.0,
        retry: RetryConfig | None = None,
        breaker: BreakerConfig | None = None,
        use_lock: bool = True,
        simulated: bool = False,
    ) -> None:
        self.bus_id = bus_id
        self.adapter_id = adapter_id
        self.port = port
        self.baud = baud
        self.appliance_id = appliance_id
        self.run_id = run_id
        self.sink = sink
        self.timeout = timeout
        self.retry = retry or RetryConfig()
        self.simulated = simulated
        self.use_lock = use_lock

        breaker_config = breaker or BreakerConfig()
        self.tasks = [
            TaskRuntime(binding, group, breaker=breaker_config)
            for binding in sensors
            for group in binding.selected_groups()
        ]
        if not self.tasks:
            raise ValueError(f"bus {bus_id} has no register groups to poll")

        self._reader: ModbusReader | None = None
        self._lock: PortLock | None = None
        self._executor: ThreadPoolExecutor | None = None
        self._busy_seconds = 0.0
        self._started = 0.0

    @property
    def bus_utilisation(self) -> float:
        """Fraction of wall-clock time this bus spent in transactions."""
        elapsed = time.monotonic() - self._started if self._started else 0.0
        return min(1.0, self._busy_seconds / elapsed) if elapsed > 0 else 0.0

    def _open(self) -> None:
        if self.use_lock:
            self._lock = PortLock(self.port)
            self._lock.acquire()
        reader = ModbusReader(self.port, baud=self.baud, timeout=self.timeout)
        reader.connect()
        self._reader = reader

    def _close(self) -> None:
        if self._reader is not None:
            self._reader.close()
            self._reader = None
        if self._lock is not None:
            self._lock.release()
            self._lock = None

    def _blocking_read(self, task: TaskRuntime):
        assert self._reader is not None
        return self._reader.read_group(
            task.binding.profile, task.group, slave_id=task.binding.resolved_slave_id()
        )

    async def _poll(self, loop: asyncio.AbstractEventLoop, task: TaskRuntime) -> Measurement:
        reading = None
        for attempt in range(1, self.retry.max_attempts + 1):
            began = time.monotonic()
            reading = await loop.run_in_executor(self._executor, self._blocking_read, task)
            self._busy_seconds += time.monotonic() - began
            if reading.ok:
                break
            if attempt < self.retry.max_attempts:
                task.retries += 1
                await asyncio.sleep(self.retry.delay_for(attempt))

        assert reading is not None
        now = time.monotonic()
        task.sequence += 1
        status = _QUALITY_MAP[reading.quality]

        if reading.ok:
            task.record_success(now, reading.latency_ms)
        else:
            task.record_failure(now)

        return Measurement(
            appliance_id=self.appliance_id,
            run_id=self.run_id,
            adapter_id=self.adapter_id,
            bus_id=self.bus_id,
            sensor_id=task.binding.sensor_id,
            sensor_model=task.binding.profile.model,
            profile_version=task.binding.profile.profile_version,
            slave_id=task.binding.resolved_slave_id(),
            group_key=task.group.key,
            sequence=task.sequence,
            timestamp_utc=utc_now(),
            monotonic_ns=time.monotonic_ns(),
            channels=self._with_derived(task.group.key, {
                key: ChannelValue(
                    value=channel.value,
                    unit=channel.unit,
                    quality=_QUALITY_MAP[channel.quality],
                    value_class=ValueClass.SIMULATED if self.simulated else ValueClass.NATIVE,
                    raw=tuple(channel.raw),
                )
                for key, channel in reading.channels.items()
            }, task.binding.calibration),
            status=status,
            crc_valid=reading.ok,
            latency_ms=reading.latency_ms,
            error=reading.error,
            simulated=self.simulated,
        )

    def _with_derived(
        self,
        group_key: str,
        channels: dict[str, ChannelValue],
        calibration: SensorCalibration = IDENTITY,
    ) -> dict[str, ChannelValue]:
        """Attach derived channels, never at the cost of the measured ones.

        A derivation is arithmetic on values that are already recorded, so a
        fault in it must not be able to lose a reading that was read correctly
        from the bus.
        """
        # Calibration first, so inclination is derived from corrected axes. A
        # 3% per-axis gain error is worth about two degrees of tilt near 45,
        # which would otherwise be baked into every angle.
        channels = calibration.apply(channels)

        try:
            derived = derive_for_group(group_key, channels)
        except Exception:  # noqa: BLE001
            log.exception("derivation failed for group %s; measured channels kept", group_key)
            return channels

        # A value derived from simulated inputs is simulated, whatever the
        # arithmetic. Letting it keep the DERIVED class would put a number on the
        # wire that nothing downstream could tell apart from a real measurement,
        # which is the one thing the value class exists to prevent.
        if self.simulated:
            derived = {
                key: replace(channel, value_class=ValueClass.SIMULATED)
                for key, channel in derived.items()
            }

        channels.update(derived)
        return channels

    async def run(self, stop: asyncio.Event) -> None:
        loop = asyncio.get_running_loop()
        # One thread per bus. This is what serialises the bus: no two
        # transactions on this port can ever overlap.
        self._executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix=f"bus-{self.bus_id}")
        self._started = time.monotonic()
        self._open()
        try:
            while not stop.is_set():
                now = time.monotonic()
                due = [t for t in self.tasks if t.due(now)]
                if not due:
                    await asyncio.sleep(self._sleep_for(now))
                    continue
                # Oldest deadline first, so a fast group cannot starve a slow one.
                due.sort(key=lambda t: t.next_due)
                for task in due:
                    if stop.is_set():
                        break
                    measurement = await self._poll(loop, task)
                    task.schedule_next(time.monotonic())
                    result = self.sink(measurement)
                    if asyncio.iscoroutine(result):
                        await result
        finally:
            self._close()
            self._executor.shutdown(wait=False)
            self._executor = None

    def _sleep_for(self, now: float) -> float:
        upcoming = [t.next_due for t in self.tasks if t.breaker is not BreakerState.OPEN]
        if not upcoming:
            return 0.05
        return max(0.001, min(0.05, min(upcoming) - now))

    def stats(self) -> dict[str, object]:
        return {
            "bus_id": self.bus_id,
            "adapter_id": self.adapter_id,
            "port": self.port,
            "baud": self.baud,
            "bus_utilisation": round(self.bus_utilisation, 4),
            "tasks": [t.stats() for t in self.tasks],
        }


class AcquisitionEngine:
    """Runs every bus concurrently and funnels measurements to one sink."""

    def __init__(self, appliance_id: str, sink: Sink, *, run_id: str | None = None) -> None:
        self.appliance_id = appliance_id
        # One id per continuous run. Sequence numbers restart at 1 on every
        # service start; this is what keeps their idempotency keys distinct.
        self.run_id = run_id or uuid.uuid4().hex
        self.sink = sink
        self.buses: list[BusWorker] = []
        self._stop = asyncio.Event()

    def add_bus(
        self,
        *,
        bus_id: str,
        adapter_id: str,
        port: str,
        baud: int,
        sensors: Iterable[SensorBinding],
        **kwargs: object,
    ) -> BusWorker:
        worker = BusWorker(
            bus_id=bus_id,
            adapter_id=adapter_id,
            port=port,
            baud=baud,
            sensors=sensors,
            appliance_id=self.appliance_id,
            run_id=self.run_id,
            sink=self.sink,
            **kwargs,  # type: ignore[arg-type]
        )
        self.buses.append(worker)
        return worker

    async def run(self, duration: float | None = None) -> None:
        if not self.buses:
            raise RuntimeError("no buses configured")
        self._stop.clear()
        tasks = [asyncio.create_task(bus.run(self._stop)) for bus in self.buses]
        try:
            if duration is not None:
                await asyncio.sleep(duration)
                self._stop.set()
            await asyncio.gather(*tasks)
        finally:
            self._stop.set()
            for task in tasks:
                if not task.done():
                    task.cancel()

    def stop(self) -> None:
        self._stop.set()

    def stats(self) -> dict[str, object]:
        return {
            "appliance_id": self.appliance_id,
            "run_id": self.run_id,
            "buses": [b.stats() for b in self.buses],
        }
