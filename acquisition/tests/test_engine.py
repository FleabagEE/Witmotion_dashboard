"""Acquisition engine behaviour, exercised end to end against the simulator."""

from __future__ import annotations

import asyncio
from pathlib import Path

import pytest

from qv_acq.engine import (
    AcquisitionEngine,
    BreakerConfig,
    BreakerState,
    RetryConfig,
    SensorBinding,
)
from qv_acq.measurement import QualityStatus, ValueClass
from qv_acq.portlock import PortBusyError, PortLock
from qv_acq.profiles import loader
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.rtu import FaultInjection
from qv_acq.simulator.server import SimulatorServer


def wtvb() -> object:
    return loader.get("WTVB01-485")


def collector() -> tuple[list, callable]:
    seen: list = []
    return seen, seen.append


async def run_engine(server, bindings, *, duration=1.2, **bus_kwargs):
    seen, sink = collector()
    engine = AcquisitionEngine("QV-EDGE-TEST", sink)
    engine.add_bus(
        bus_id="BUS-001",
        adapter_id="RS485-ADAPTER-001",
        port=server.port,
        baud=115200,
        sensors=bindings,
        use_lock=False,
        **bus_kwargs,
    )
    await engine.run(duration=duration)
    return seen, engine


class TestPortLock:
    def test_second_holder_is_refused(self, tmp_path: Path) -> None:
        first = PortLock("/dev/null", lock_dir=tmp_path)
        second = PortLock("/dev/null", lock_dir=tmp_path)
        first.acquire()
        try:
            with pytest.raises(PortBusyError, match="already owned"):
                second.acquire()
        finally:
            first.release()

    def test_lock_is_reusable_after_release(self, tmp_path: Path) -> None:
        with PortLock("/dev/null", lock_dir=tmp_path):
            pass
        with PortLock("/dev/null", lock_dir=tmp_path) as lock:
            assert lock.held

    def test_alias_and_target_share_a_lock(self, tmp_path: Path) -> None:
        """A udev alias must not let a second process in through the back door."""
        alias = tmp_path / "quakevault-rs485-a"
        target = tmp_path / "ttyUSB0"
        target.touch()
        alias.symlink_to(target)
        assert PortLock(str(alias), lock_dir=tmp_path).path == PortLock(
            str(target), lock_dir=tmp_path
        ).path


class TestEngine:
    @pytest.mark.parametrize("group_key", ["motion", "condition_x"])
    def test_polls_and_emits_measurements(self, group_key: str) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001",
            profile=profile,
            slave_id=0x50,
            groups=(group_key,),
            poll_hz={group_key: 20},
        )

        with SimulatorServer({0x50: device}) as server:
            seen, engine = asyncio.run(run_engine(server, [binding]))

        assert len(seen) > 5
        first = seen[0]
        assert first.ok
        assert first.status is QualityStatus.GOOD
        assert first.sensor_model == "WTVB01-485"
        assert first.group_key == group_key
        assert first.bus_id == "BUS-001"

    def test_sequence_numbers_are_monotonic_and_gapless(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 25},
        )
        with SimulatorServer({0x50: device}) as server:
            seen, _ = asyncio.run(run_engine(server, [binding]))

        sequences = [m.sequence for m in seen]
        assert sequences == list(range(1, len(sequences) + 1))

    def test_idempotency_keys_are_unique(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 25},
        )
        with SimulatorServer({0x50: device}) as server:
            seen, _ = asyncio.run(run_engine(server, [binding]))

        keys = [m.idempotency_key() for m in seen]
        assert len(keys) == len(set(keys))

    def test_envelope_is_schema_versioned(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50, groups=("motion",)
        )
        with SimulatorServer({0x50: device}) as server:
            seen, _ = asyncio.run(run_engine(server, [binding]))

        envelope = seen[0].as_dict()
        assert envelope["schema_version"] == "1.0"
        assert envelope["appliance_id"] == "QV-EDGE-TEST"
        assert envelope["timestamp_utc"].endswith("Z")
        assert set(envelope["quality"]) == {"status", "crc_valid", "stale", "latency_ms", "error"}
        assert envelope["measurements"]["accel_z"]["unit"] == "g"

    def test_measured_rate_is_reported_not_configured_rate(self) -> None:
        """The engine must never claim the configured rate as achieved."""
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 30},
        )
        with SimulatorServer({0x50: device}) as server:
            _, engine = asyncio.run(run_engine(server, [binding]))

        task = engine.buses[0].stats()["tasks"][0]
        assert task["configured_hz"] == 30.0
        assert task["measured_hz"] is not None
        assert task["jitter_ms"] is not None
        assert task["mean_latency_ms"] is not None

    def test_bus_utilisation_is_tracked(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50, groups=("motion",)
        )
        with SimulatorServer({0x50: device}) as server:
            _, engine = asyncio.run(run_engine(server, [binding]))

        utilisation = engine.buses[0].stats()["bus_utilisation"]
        assert 0.0 < utilisation <= 1.0

    def test_multiple_sensors_share_one_bus(self) -> None:
        profile = wtvb()
        devices = {
            0x50: SimulatedDevice(profile=profile, slave_id=0x50),
            0x51: SimulatedDevice(profile=profile, slave_id=0x51),
        }
        bindings = [
            SensorBinding(sensor_id="SENSOR-001", profile=profile, slave_id=0x50, groups=("motion",)),
            SensorBinding(sensor_id="SENSOR-002", profile=profile, slave_id=0x51, groups=("motion",)),
        ]
        with SimulatorServer(devices) as server:
            seen, _ = asyncio.run(run_engine(server, bindings))

        by_sensor = {m.sensor_id for m in seen}
        assert by_sensor == {"SENSOR-001", "SENSOR-002"}

    def test_independent_poll_rates_per_group(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion", "condition_x"),
            poll_hz={"motion": 20, "condition_x": 2},
        )
        with SimulatorServer({0x50: device}) as server:
            seen, _ = asyncio.run(run_engine(server, [binding], duration=1.5))

        fast = sum(1 for m in seen if m.group_key == "motion")
        slow = sum(1 for m in seen if m.group_key == "condition_x")
        assert fast > slow * 2, f"expected fast group to dominate, got {fast} vs {slow}"

    def test_simulated_data_is_labelled(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50, groups=("motion",)
        )
        with SimulatorServer({0x50: device}) as server:
            seen, _ = asyncio.run(run_engine(server, [binding], simulated=True))

        assert all(m.simulated for m in seen)
        assert all(
            c.value_class is ValueClass.SIMULATED for m in seen for c in m.channels.values()
        )
        assert seen[0].as_dict()["simulated"] is True


class TestResilience:
    def test_total_dropout_opens_the_circuit_breaker(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 20},
        )
        faults = FaultInjection(drop_probability=1.0)

        with SimulatorServer({0x50: device}, faults=faults) as server:
            seen, engine = asyncio.run(
                run_engine(
                    server, [binding], duration=1.5, timeout=0.15,
                    retry=RetryConfig(max_attempts=1),
                    breaker=BreakerConfig(failure_threshold=2, cooldown_seconds=0.4),
                )
            )

        assert seen, "engine must keep emitting measurements even when reads fail"
        assert all(m.status is QualityStatus.BAD for m in seen)
        task = engine.buses[0].tasks[0]
        assert task.breaker is not BreakerState.CLOSED
        assert task.polls_failed > 0

    def test_breaker_throttles_a_dead_sensor(self) -> None:
        """An open breaker must cut poll attempts, not merely flag them."""
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 50},
        )
        faults = FaultInjection(drop_probability=1.0)

        with SimulatorServer({0x50: device}, faults=faults) as server:
            seen, _ = asyncio.run(
                run_engine(
                    server, [binding], duration=1.5, timeout=0.1,
                    retry=RetryConfig(max_attempts=1),
                    breaker=BreakerConfig(failure_threshold=2, cooldown_seconds=0.5),
                )
            )

        # Unthrottled at 50 Hz this would be ~75 attempts; the breaker holds it
        # to roughly one probe per cooldown.
        assert len(seen) < 20, f"breaker failed to throttle: {len(seen)} polls"

    def test_recovers_after_faults_clear(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        faults = FaultInjection(drop_probability=1.0)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 20},
        )

        async def scenario(server):
            seen, sink = collector()
            engine = AcquisitionEngine("QV-EDGE-TEST", sink)
            engine.add_bus(
                bus_id="BUS-001", adapter_id="A1", port=server.port, baud=115200,
                sensors=[binding], use_lock=False, timeout=0.15,
                retry=RetryConfig(max_attempts=1),
                breaker=BreakerConfig(failure_threshold=2, cooldown_seconds=0.3),
            )
            task = asyncio.create_task(engine.run())
            await asyncio.sleep(0.8)
            server.faults.drop_probability = 0.0  # the bus comes back
            await asyncio.sleep(1.2)
            engine.stop()
            await task
            return seen, engine

        with SimulatorServer({0x50: device}, faults=faults) as server:
            seen, engine = asyncio.run(scenario(server))

        assert any(m.status is QualityStatus.BAD for m in seen)
        assert any(m.ok for m in seen), "engine never recovered after faults cleared"
        assert engine.buses[0].tasks[0].breaker is BreakerState.CLOSED

    def test_intermittent_faults_are_survived(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        faults = FaultInjection(drop_probability=0.3, crc_error_probability=0.2)
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=profile, slave_id=0x50,
            groups=("motion",), poll_hz={"motion": 20},
        )
        with SimulatorServer({0x50: device}, faults=faults, seed=7) as server:
            seen, engine = asyncio.run(
                run_engine(server, [binding], duration=1.5, timeout=0.2,
                           breaker=BreakerConfig(failure_threshold=5, cooldown_seconds=0.2))
            )

        assert any(m.ok for m in seen)
        task = engine.buses[0].stats()["tasks"][0]
        assert task["retries"] >= 0 and task["polls_ok"] > 0

    def test_unknown_group_is_rejected_at_configuration_time(self) -> None:
        binding = SensorBinding(
            sensor_id="SENSOR-001", profile=wtvb(), slave_id=0x50, groups=("nonexistent",)
        )
        with pytest.raises(KeyError, match="nonexistent"):
            binding.selected_groups()
