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
        # Core measurement channels. Condition indicators are covered separately
        # in TestConditionIndicators.
        assert {k: actual[k] for k in expected} == expected

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

    def test_motion_group_is_one_transaction_from_accel_to_frequency(self, profile) -> None:
        # Acceleration, velocity, displacement, frequency and temperature in a
        # single read. Split across three, Modbus per-transaction overhead held
        # velocity and displacement to 4 Hz; merged they all run at ~9 Hz for
        # less bus time, and share one timestamp instead of three.
        group = next(g for g in profile.register_groups if g.key == "motion")

        assert group.start_address == 0x34
        assert group.register_count == 19  # 0x34..0x46 inclusive

    def test_the_motion_span_deliberately_covers_unmapped_registers(self, profile) -> None:
        # 0x37-0x39 and 0x3D-0x3F fall inside the span and are read and thrown
        # away. A gap inside one transaction is free; a second transaction to
        # skip it would not be.
        group = next(g for g in profile.register_groups if g.key == "motion")
        addresses = {c.address for c in group.channels}

        assert addresses.isdisjoint({0x37, 0x38, 0x39, 0x3D, 0x3E, 0x3F})
        assert len(group.channels) == 13

    def test_capabilities(self, profile) -> None:
        assert profile.capabilities() == {
            "acceleration",
            "vibration_velocity",
            "vibration_displacement",
            "dominant_frequency",
            "temperature",
            "device_time",
            "condition_indicator",
            "fault_code",
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
        motion = next(g for g in profile.register_groups if g.key == "motion")

        with SimulatorServer({0x50: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                reading = reader.read_group(profile, motion, slave_id=0x50)

        assert reading.quality is Quality.GOOD
        assert reading.channels["temperature"].value == pytest.approx(24.17, abs=0.01)
        assert reading.channels["vib_displacement_x"].value == pytest.approx(215.0, abs=1)
        assert reading.channels["vib_frequency_x"].value == pytest.approx(61.9, abs=0.1)
        assert reading.channels["vib_velocity_x"].value == pytest.approx(20.10, abs=0.01)


class TestConditionIndicators:
    """Manual 10.4.11-10.4.14. Every indicator is raw/1000."""

    @pytest.mark.parametrize("axis", ["x", "y", "z"])
    def test_each_axis_has_twelve_indicators(self, profile, axis) -> None:
        group = next(g for g in profile.register_groups if g.key == f"condition_{axis}")
        assert len(group.channels) == 12
        assert group.register_count == 12

    def test_blocks_are_consecutive_and_adjacent(self, profile) -> None:
        starts = {
            k: next(g for g in profile.register_groups if g.key == f"condition_{k}").start_address
            for k in ("x", "y", "z")
        }
        # Manual 10.4.11-10.4.13: 0x47-0x52, 0x53-0x5E, 0x5F-0x6A.
        assert starts == {"x": 0x47, "y": 0x53, "z": 0x5F}

    def test_x_block_uses_consecutive_layout_not_summary_table(self, profile) -> None:
        """The 10.3 summary table omits 0x4D-0x4F; 10.4.11 does not.

        Hardware sided with the detailed section: 0x4D-0x4F carry live
        acceleration statistics while 0x50-0x52 sit at zero, matching their Y
        and Z counterparts exactly.
        """
        assert profile.channel("peak_index_x").address == 0x4D
        assert profile.channel("pulse_coefficient_x").address == 0x4E
        assert profile.channel("skewness_x").address == 0x4F
        assert profile.channel("rms_velocity_x").address == 0x50

    @pytest.mark.parametrize(
        ("key", "raw", "expected"),
        [
            ("rms_accel_x", 1234, 1.234),
            ("kurtosis_x", 3000, 3.0),
            ("cf_x", 1414, 1.414),
            ("skewness_x", -1892, -1.892),
            ("rms_velocity_x", 2800, 2.8),
            ("rms_displacement_z", 500, 0.5),
        ],
    )
    def test_indicator_scaling(self, profile, key, raw, expected) -> None:
        channel = profile.channel(key)
        assert channel.scale == 0.001
        value = decode([raw & 0xFFFF], channel.data_type, scale=channel.scale)
        assert value == pytest.approx(expected, abs=1e-9)

    def test_all_three_axes_are_symmetric(self, profile) -> None:
        suffixes = lambda axis: sorted(  # noqa: E731
            c.key.removesuffix(f"_{axis}")
            for c in next(g for g in profile.register_groups if g.key == f"condition_{axis}").channels
        )
        assert suffixes("x") == suffixes("y") == suffixes("z")

    def test_fault_words_are_unscaled_bitfields(self, profile) -> None:
        for key in ("fault_x", "fault_y", "fault_z"):
            channel = profile.channel(key)
            assert channel.scale == 1.0
            assert channel.data_type == "uint16"
            assert channel.quantity == "fault_code"

    def test_condition_indicators_bypass_the_polling_ceiling(self, profile) -> None:
        """These are computed on-device, so they are not rate-limited by us.

        Documented as a capability rather than asserted numerically: the point is
        that RMS/kurtosis here come from the sensor's own sampling, not from our
        3.5 Hz register polling.
        """
        indicators = [c for c in profile.channels if c.quantity == "condition_indicator"]
        assert len(indicators) == 36


# --- unsigned magnitude registers -------------------------------------------
#
# Added after a hard shake on 2026-08-03 revealed velocity and displacement were
# decoded as signed. Every one of these registers is a magnitude that only
# exceeds 32767 counts during a real event, so a stationary bench test cannot
# reach the bug. These fix the boundary explicitly.

def channel(key: str):
    for group in loader.get("WTVB01-485").register_groups:
        for ch in group.channels:
            if ch.key == key:
                return ch
    raise AssertionError(f"no channel {key}")


@pytest.mark.parametrize("key", [
    "vib_velocity_x", "vib_velocity_y", "vib_velocity_z",
    "vib_displacement_x", "vib_displacement_y", "vib_displacement_z",
    "vib_frequency_x", "vib_frequency_y", "vib_frequency_z",
])
def test_magnitude_registers_are_unsigned(key: str) -> None:
    # A magnitude cannot be negative. Signed decoding inverts everything above
    # 32767 counts, which is the large-event regime and nothing else.
    assert channel(key).data_type == "uint16"


@pytest.mark.parametrize("key", ["accel_x", "accel_y", "accel_z", "temperature"])
def test_genuinely_signed_registers_stay_signed(key: str) -> None:
    # The inverse mistake would be just as bad: acceleration resolves gravity
    # onto axes that point downwards, and temperature goes below zero.
    assert channel(key).data_type == "int16"


def test_the_exact_reading_that_exposed_the_bug() -> None:
    # Two consecutive samples from the shake. Under the old signed decoding
    # these read +319.32 and -320.06 mm/s: a 640 mm/s reversal between adjacent
    # readings of a smoothly rising magnitude.
    ch = channel("vib_velocity_y")
    before = decode([31932], ch.data_type, scale=ch.scale)
    after = decode([33530], ch.data_type, scale=ch.scale)

    assert before == pytest.approx(319.32)
    assert after == pytest.approx(335.30)
    # The whole point: the magnitude keeps rising across the boundary.
    assert after > before


def test_velocity_spans_the_full_unsigned_range() -> None:
    ch = channel("vib_velocity_x")
    assert decode([65535], ch.data_type, scale=ch.scale) == pytest.approx(655.35)
    # And the declared plausibility bound admits it, rather than flagging a real
    # event as implausible and excluding it from analysis.
    assert ch.maximum >= 655.35


def test_displacement_spans_the_full_unsigned_range() -> None:
    ch = channel("vib_displacement_x")
    assert decode([65535], ch.data_type, scale=ch.scale) == pytest.approx(65535)
    assert ch.maximum >= 65535
