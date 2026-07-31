"""Unit tests for the hardware-independent acquisition core."""

from __future__ import annotations

import math

import pytest

from qv_acq import crc, decode, throughput
from qv_acq.profiles import loader


class TestCrc:
    def test_known_vector(self) -> None:
        # Published Modbus RTU example: read 1 holding register from slave 1.
        frame = bytes([0x01, 0x03, 0x00, 0x00, 0x00, 0x01])
        assert crc.crc16(frame) == 0x0A84
        assert crc.crc_bytes(frame) == bytes([0x84, 0x0A])

    def test_round_trip_verifies(self) -> None:
        assert crc.verify(crc.append_crc(b"\x50\x03\x00\x34\x00\x06"))

    def test_single_bit_corruption_is_detected(self) -> None:
        frame = bytearray(crc.append_crc(b"\x50\x03\x00\x34\x00\x06"))
        frame[3] ^= 0x01
        assert not crc.verify(bytes(frame))

    def test_runt_frame_rejected(self) -> None:
        assert not crc.verify(b"\x01\x02")


class TestDecode:
    @pytest.mark.parametrize(
        ("word", "expected"),
        [(0x0000, 0), (0x0001, 1), (0x7FFF, 32767), (0x8000, -32768), (0xFFFF, -1)],
    )
    def test_signed_16_bit(self, word: int, expected: int) -> None:
        assert decode.decode_raw([word], "int16") == expected

    def test_unsigned_16_bit_keeps_high_values(self) -> None:
        assert decode.decode_raw([0xFFFF], "uint16") == 65535

    def test_word_order_changes_32_bit_assembly(self) -> None:
        assert decode.decode_raw([0x0001, 0x0000], "int32", word_order="big") == 0x00010000
        assert decode.decode_raw([0x0001, 0x0000], "int32", word_order="little") == 0x00000001

    def test_float32(self) -> None:
        assert decode.decode_raw([0x3F80, 0x0000], "float32") == pytest.approx(1.0)

    def test_full_scale_factor_matches_witmotion_convention(self) -> None:
        assert decode.full_scale_factor(16.0) == pytest.approx(16 / 32768)

    def test_scaling_produces_engineering_units(self) -> None:
        # 2048 counts at +/-16 g full scale is exactly 1 g.
        value = decode.decode([2048], "int16", scale=decode.full_scale_factor(16.0))
        assert value == pytest.approx(1.0)

    def test_wrong_word_count_is_rejected(self) -> None:
        with pytest.raises(decode.DecodeError):
            decode.decode_raw([1, 2], "int16")

    def test_unknown_type_is_rejected(self) -> None:
        with pytest.raises(decode.DecodeError):
            decode.decode_raw([1], "int24")

    def test_plausibility_bounds(self) -> None:
        assert decode.plausible(5.0, minimum=0, maximum=10)
        assert not decode.plausible(-1.0, minimum=0, maximum=10)
        assert not decode.plausible(11.0, minimum=0, maximum=10)


class TestThroughput:
    def test_char_time_at_9600(self) -> None:
        assert throughput.char_time_ms(9600) == pytest.approx(1.0417, abs=1e-3)

    def test_9600_baud_twelve_registers(self) -> None:
        ms = throughput.transaction_ms(
            9600, 12, turnaround_ms=5.0, usb_latency_ms=throughput.USB_LATENCY_MS["ch340"]
        )
        # Hand calculation: 38.5 ms wire + 7.3 ms idle + 9 ms overhead.
        assert 50.0 < ms < 60.0

    def test_115200_is_dominated_by_turnaround_not_wire_time(self) -> None:
        ms = throughput.transaction_ms(
            115200, 12, turnaround_ms=5.0, usb_latency_ms=throughput.USB_LATENCY_MS["ch340"]
        )
        assert 10.0 < ms < 16.0

    def test_faster_bridge_chip_raises_capacity(self) -> None:
        ch340 = throughput.transaction_ms(115200, 12, usb_latency_ms=throughput.USB_LATENCY_MS["ch340"])
        ftdi = throughput.transaction_ms(115200, 12, usb_latency_ms=throughput.USB_LATENCY_MS["ftdi"])
        assert ftdi < ch340

    def test_additional_sensors_reduce_sustainable_rate(self) -> None:
        one = throughput.estimate(115200, 12, sensors=1)
        two = throughput.estimate(115200, 12, sensors=2)
        assert two.sustainable_poll_hz < one.sustainable_poll_hz
        assert two.sustainable_poll_hz == pytest.approx(one.sustainable_poll_hz / 2, rel=0.02)

    def test_invalid_inputs_rejected(self) -> None:
        with pytest.raises(ValueError):
            throughput.char_time_ms(0)
        with pytest.raises(ValueError):
            throughput.transaction_ms(9600, 0)
        with pytest.raises(ValueError):
            throughput.estimate(9600, 12, sensors=0)

    def test_spectral_verdict_allows_defensible_band(self) -> None:
        allowed, why = throughput.spectral_verdict(80.0, 20.0)
        assert allowed and "defensible" in why

    def test_spectral_verdict_blocks_jitter_zone(self) -> None:
        allowed, why = throughput.spectral_verdict(80.0, 35.0)
        assert not allowed and "non-uniform" in why

    def test_spectral_verdict_blocks_aliasing(self) -> None:
        allowed, why = throughput.spectral_verdict(80.0, 200.0)
        assert not allowed and "Nyquist" in why


class TestProfiles:
    def test_both_sensors_load(self) -> None:
        profiles = loader.load_all()
        assert {"WTVB01-485", "HWT901B-485"} <= set(profiles)

    def test_unknown_model_raises(self) -> None:
        with pytest.raises(KeyError):
            loader.get("NOT-A-SENSOR")

    def test_wtvb01_is_verified_against_manufacturer_table(self) -> None:
        # Promoted 2026-07-31: every address and scale transcribed from the
        # manufacturer register table and confirmed on hardware.
        # See docs/register-maps.md and tests/test_wtvb01_fixtures.py.
        profile = loader.get("WTVB01-485")
        assert profile.verification_status == "verified"
        assert profile.is_trustworthy()

    def test_hwt901b_is_candidate_pending_hardware(self) -> None:
        profile = loader.get("HWT901B-485")
        assert profile.verification_status == "candidate"
        assert not profile.is_trustworthy()

    def test_hwt901b_acceleration_scaling(self) -> None:
        channel = loader.get("HWT901B-485").channel("accel_z")
        value = decode.decode([2048], channel.data_type, scale=channel.scale)
        assert value == pytest.approx(1.0)

    def test_hwt901b_attitude_scaling(self) -> None:
        channel = loader.get("HWT901B-485").channel("pitch")
        assert decode.decode([16384], "int16", scale=channel.scale) == pytest.approx(90.0)

    def test_temperature_scaling(self) -> None:
        channel = loader.get("HWT901B-485").channel("temperature")
        assert decode.decode([2531], "int16", scale=channel.scale) == pytest.approx(25.31)

    def test_quaternion_is_unit_bounded(self) -> None:
        channel = loader.get("HWT901B-485").channel("q0")
        assert decode.decode([16384], "int16", scale=channel.scale) == pytest.approx(0.5)
        assert math.isclose(channel.maximum, 1.0)

    def test_register_group_span_is_contiguous(self) -> None:
        group = next(g for g in loader.get("HWT901B-485").register_groups if g.key == "inertial")
        assert group.start_address == 0x34
        assert group.register_count == 6

    def test_capabilities_differ_between_sensors(self) -> None:
        vibration = loader.get("WTVB01-485").capabilities()
        inertial = loader.get("HWT901B-485").capabilities()
        # The whole point of the capability model: these sensors overlap only on
        # acceleration and temperature, so no UI or alarm logic may assume a
        # channel exists. The WTVB01 does expose acceleration (register table
        # 0x34-0x36, confirmed on hardware), but nothing else inertial.
        assert "attitude_angle" in inertial and "attitude_angle" not in vibration
        assert "vibration_velocity" in vibration and "vibration_velocity" not in inertial
        assert vibration & inertial == {"acceleration", "temperature"}

    def test_protected_commands_require_step_up(self) -> None:
        for model in ("WTVB01-485", "HWT901B-485"):
            for command in loader.get(model).protected_commands:
                assert command.requires_step_up, f"{model}:{command.key} must be gated"

    def test_every_channel_declares_a_unit(self) -> None:
        for model in ("WTVB01-485", "HWT901B-485"):
            for channel in loader.get(model).channels:
                assert channel.unit, f"{model}:{channel.key} has no engineering unit"
