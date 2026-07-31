"""Fixtures pinning the WTVB01-485 register map to the manufacturer's formulas.

Every expected value below is computed by hand from the formula printed in the
WTVB01-485 Datasheet and User Guide (document V260508), section 10.4, and
cross-checked against raw counts actually observed on the connected unit.

If a future profile edit changes a scale factor, these fail.
"""

from __future__ import annotations

import pytest

from qv_acq.client import Quality
from qv_acq.decode import decode
from qv_acq.profiles import loader
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.server import SimulatorServer
from qv_acq.client import ModbusReader
from qv_acq.simulator.signals import SignalSpec


@pytest.fixture(scope="module")
def profile():
    return loader.get("WTVB01-485")


class TestManualFormulas:
    """Manual 10.4.5, 10.4.6, 10.4.8, 10.4.9, 10.4.10."""

    @pytest.mark.parametrize(
        ("key", "raw", "expected", "unit"),
        [
            # 10.4.5  AX = raw/32768*16 g. 1988 counts is the Z axis observed at
            # rest on the real unit; it must decode to roughly 1 g (gravity).
            ("accel_z", 1988, 0.970703125, "g"),
            ("accel_x", 424, 0.20703125, "g"),
            ("accel_x", -2048, -1.0, "g"),
            # 10.4.6  VX (mm/s) = raw/100. 2010 counts observed under tapping.
            ("vib_velocity_x", 2010, 20.10, "mm/s"),
            ("vib_velocity_x", 9979, 99.79, "mm/s"),
            ("vib_velocity_x", 112, 1.12, "mm/s"),  # ISO 10816 zone A/B boundary
            ("vib_velocity_x", 280, 2.80, "mm/s"),  # zone B/C boundary
            ("vib_velocity_x", 710, 7.10, "mm/s"),  # zone C/D boundary
            # 10.4.8  Temperature = raw/100 degC.
            ("temperature", 2417, 24.17, "degC"),
            ("temperature", -1000, -10.0, "degC"),
            # 10.4.9  DX (um) = raw, unscaled.
            ("vib_displacement_x", 215, 215.0, "um"),
            ("vib_displacement_x", 30000, 30000.0, "um"),
            # 10.4.10 HZX (Hz) = raw/10.
            ("vib_frequency_x", 619, 61.9, "Hz"),
            ("vib_frequency_x", 500, 50.0, "Hz"),
            ("vib_frequency_z", 1258, 125.8, "Hz"),
        ],
    )
    def test_channel_decodes_per_manual(self, profile, key, raw, expected, unit) -> None:
        channel = profile.channel(key)
        assert channel.unit == unit
        value = decode(
            [raw & 0xFFFF],
            channel.data_type,
            word_order=channel.word_order,
            scale=channel.scale,
            offset=channel.offset,
        )
        assert value == pytest.approx(expected, abs=1e-6)

    def test_iso_10816_zones_are_resolvable(self, profile) -> None:
        """The scale must distinguish the standard's zone boundaries.

        This is the check that exposed the original bad hypothesis: at 1 mm/s per
        count these three zones collapse into adjacent integers.
        """
        channel = profile.channel("vib_velocity_x")
        zones = [1.12, 2.80, 7.10]
        counts = [round(z / channel.scale) for z in zones]
        assert counts == [112, 280, 710]
        assert len(set(counts)) == 3


class TestProfileStructure:
    def test_profile_is_verified(self, profile) -> None:
        assert profile.verification_status == "verified"
        assert profile.is_trustworthy()

    def test_addresses_match_manufacturer_table(self, profile) -> None:
        expected = {
            "accel_x": 0x34, "accel_y": 0x35, "accel_z": 0x36,
            "vib_velocity_x": 0x3A, "vib_velocity_y": 0x3B, "vib_velocity_z": 0x3C,
            "temperature": 0x40,
            "vib_displacement_x": 0x41, "vib_displacement_y": 0x42, "vib_displacement_z": 0x43,
            "vib_frequency_x": 0x44, "vib_frequency_y": 0x45, "vib_frequency_z": 0x46,
            "time_yymm": 0x30, "time_ddhh": 0x31, "time_mmss": 0x32, "time_ms": 0x33,
        }
        actual = {c.key: c.address for c in profile.channels}
        assert actual == expected

    def test_reserved_registers_are_not_mapped(self, profile) -> None:
        """0x3D-0x3F are documented Reserved and read zero on hardware."""
        mapped = {c.address for c in profile.channels}
        assert mapped.isdisjoint({0x3D, 0x3E, 0x3F})

    def test_undocumented_registers_are_not_mapped(self, profile) -> None:
        """0x37-0x39 are live on the unit but absent from the register table.

        Excluded on purpose: an undocumented register may move or vanish in a
        firmware revision, so it must never back a measurement channel.
        """
        mapped = {c.address for c in profile.channels}
        assert mapped.isdisjoint({0x37, 0x38, 0x39})

    def test_summary_group_spans_temp_through_frequency(self, profile) -> None:
        group = next(g for g in profile.register_groups if g.key == "vibration_summary")
        assert group.start_address == 0x40
        assert group.register_count == 7  # 0x40..0x46 inclusive

    def test_capabilities(self, profile) -> None:
        assert profile.capabilities() == {
            "acceleration",
            "vibration_velocity",
            "vibration_displacement",
            "dominant_frequency",
            "temperature",
            "device_time",
        }


class TestAgainstSimulator:
    def test_verified_profile_reads_end_to_end(self, profile) -> None:
        """Drive the simulator with counts observed on the real unit."""
        device = SimulatedDevice(
            profile=profile,
            slave_id=0x50,
            signals={
                "temperature": SignalSpec(waveform="constant", base=24.17),
                "vib_velocity_x": SignalSpec(waveform="constant", base=20.10),
                "vib_displacement_x": SignalSpec(waveform="constant", base=215.0),
                "vib_frequency_x": SignalSpec(waveform="constant", base=61.9),
            },
        )
        summary = next(g for g in profile.register_groups if g.key == "vibration_summary")
        velocity = next(g for g in profile.register_groups if g.key == "vibration_velocity")

        with SimulatorServer({0x50: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                summary_reading = reader.read_group(profile, summary, slave_id=0x50)
                velocity_reading = reader.read_group(profile, velocity, slave_id=0x50)

        assert summary_reading.quality is Quality.GOOD
        assert velocity_reading.quality is Quality.GOOD
        assert summary_reading.channels["temperature"].value == pytest.approx(24.17, abs=0.01)
        assert summary_reading.channels["vib_displacement_x"].value == pytest.approx(215.0, abs=1)
        assert summary_reading.channels["vib_frequency_x"].value == pytest.approx(61.9, abs=0.1)
        assert velocity_reading.channels["vib_velocity_x"].value == pytest.approx(20.10, abs=0.01)
