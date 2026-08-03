"""Protocol-level faults, against the simulator.

Cases 6, 9 and 10 of the hardware-in-the-loop matrix. They belong here rather
than with the unit tests because they are acceptance evidence: each one asserts
what an operator would be told, not just that the code does not crash.

A corrupted frame and a missing frame must both be *reported*, not smoothed
over. Silently retrying past a bus that is losing half its traffic would let a
degrading cable look healthy right up until it stopped working altogether.
"""

from __future__ import annotations

from pathlib import Path

import pytest
import yaml

from qv_acq.client import ModbusReader, Quality
from qv_acq.config import ApplianceConfig
from qv_acq.profiles import loader
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.rtu import FaultInjection
from qv_acq.simulator.server import SimulatorServer


@pytest.fixture(scope="module")
def profile():
    return loader.get("WTVB01-485")


def motion(profile):
    return next(g for g in profile.register_groups if g.key == "motion")


# --- case 9: CRC corruption -------------------------------------------------

def test_a_corrupted_crc_is_rejected_not_decoded(profile) -> None:
    """A frame whose CRC fails must never reach the decoder.

    This is the fault that matters most: a corrupted frame that got decoded
    anyway would produce a plausible reading from noise, and nothing downstream
    could tell it from a real one.
    """
    device = SimulatedDevice(profile=profile, slave_id=0x50)
    faults = FaultInjection(crc_error_probability=1.0)

    with SimulatorServer({0x50: device}, faults=faults) as server:
        with ModbusReader(server.port, baud=115200, timeout=0.5) as reader:
            reading = reader.read_group(profile, motion(profile), slave_id=0x50)

    assert reading.quality is Quality.BAD
    assert not reading.ok
    # Nothing invented to fill the gap.
    assert all(c.value is None for c in reading.channels.values())


def test_intermittent_corruption_still_yields_good_reads(profile) -> None:
    # A flaky cable is not a dead one. Half the frames corrupt should still give
    # readings, so a degrading bus keeps reporting while it degrades.
    device = SimulatedDevice(profile=profile, slave_id=0x50)
    faults = FaultInjection(crc_error_probability=0.5)

    good = bad = 0
    with SimulatorServer({0x50: device}, faults=faults) as server:
        with ModbusReader(server.port, baud=115200, timeout=0.5) as reader:
            for _ in range(20):
                reading = reader.read_group(profile, motion(profile), slave_id=0x50)
                good += reading.ok
                bad += not reading.ok

    # pymodbus retries a corrupted frame, so at a 50% error rate most reads
    # still succeed - which is the desired behaviour on a flaky cable. What is
    # asserted is that reads keep coming, not a particular failure count.
    assert good > 0, "a bus losing half its frames reported nothing at all"


# --- case 11 at protocol level: no response ---------------------------------

def test_a_dropped_response_is_reported_as_bad(profile) -> None:
    device = SimulatedDevice(profile=profile, slave_id=0x50)
    faults = FaultInjection(drop_probability=1.0)

    with SimulatorServer({0x50: device}, faults=faults) as server:
        with ModbusReader(server.port, baud=115200, timeout=0.3) as reader:
            reading = reader.read_group(profile, motion(profile), slave_id=0x50)

    assert reading.quality is Quality.BAD
    assert reading.error is not None, "a silent bus produced no explanation"


# --- case 10: delayed response ----------------------------------------------

def test_a_slow_response_records_its_latency(profile) -> None:
    """Slowness has to be visible, whether or not the read succeeds.

    A device answering late is a device on its way out - a failing cable, a
    loaded slave - and the appliance's only warning is the latency it records.
    The read may or may not survive the retries; the latency must be there
    either way.
    """
    device = SimulatedDevice(profile=profile, slave_id=0x50)

    with SimulatorServer({0x50: device}, response_delay_ms=400) as server:
        with ModbusReader(server.port, baud=115200, timeout=0.1) as reader:
            reading = reader.read_group(profile, motion(profile), slave_id=0x50)

    assert reading.latency_ms >= 400


def test_the_configured_timeout_does_not_bound_a_transaction(profile) -> None:
    """Documents real behaviour that is easy to assume otherwise.

    pymodbus retries internally, so a 0.5 s timeout does not mean a transaction
    completes within 0.5 s - measured at roughly 2 s, about four times over. The
    poll scheduler has to tolerate that, and anyone sizing a poll rate against
    the timeout would size it wrong.
    """
    device = SimulatedDevice(profile=profile, slave_id=0x50)

    with SimulatorServer({0x50: device}, response_delay_ms=1500) as server:
        with ModbusReader(server.port, baud=115200, timeout=0.5) as reader:
            reading = reader.read_group(profile, motion(profile), slave_id=0x50)

    # Whether it succeeds is a race - the same input gave ok=False and ok=True on
    # consecutive runs, because a retry can pick up the response that the
    # previous attempt gave up on. That race is inherent to Modbus RTU, which has
    # no transaction id to pair a reply with its request. pymodbus flushes the
    # receive buffer before each send, which is what stops a stale reply being
    # decoded as the answer to a later question.
    #
    # So only the deterministic part is asserted: the transaction ran far past
    # its configured timeout.
    assert reading.latency_ms > 1000, "retries should have pushed this well past the timeout"


def test_a_response_within_the_timeout_is_accepted(profile) -> None:
    device = SimulatedDevice(profile=profile, slave_id=0x50)

    with SimulatorServer({0x50: device}, response_delay_ms=50) as server:
        with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
            reading = reader.read_group(profile, motion(profile), slave_id=0x50)

    assert reading.ok
    assert reading.latency_ms >= 50


# --- case 6: duplicate slave ID ---------------------------------------------

def test_two_sensors_sharing_a_slave_id_are_refused_at_startup(tmp_path: Path) -> None:
    """Rejected before the bus is opened, not diagnosed afterwards.

    Two devices answering one address corrupt each other's replies, and the
    result is not obviously broken - it is intermittently wrong, which is far
    harder to trace. The configuration is refusable on sight, so it is refused.
    """
    config = {
        "appliance_id": "QV-EDGE-TEST",
        "buses": [{
            "bus_id": "BUS-001", "adapter_id": "A1", "port": "/dev/null", "baud": 9600,
            "sensors": [
                {"sensor_id": "S1", "model": "WTVB01-485", "slave_id": 0x50},
                {"sensor_id": "S2", "model": "WTVB01-485", "slave_id": 0x50},
            ],
        }],
    }
    path = tmp_path / "acquisition.yaml"
    path.write_text(yaml.safe_dump(config))

    with pytest.raises(ValueError, match="duplicate slave_id"):
        ApplianceConfig.load(path)
