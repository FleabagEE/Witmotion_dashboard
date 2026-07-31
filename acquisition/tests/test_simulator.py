"""Encoding round-trips and end-to-end acquisition against the simulator.

These tests exercise the full stack below the ingestion API - profile, encoder,
raw RTU framing, pty transport, third-party Modbus client, decoder, quality
flags - with no hardware attached.
"""

from __future__ import annotations

import pytest

from qv_acq import decode as dec
from qv_acq import encode as enc
from qv_acq.client import ModbusReader, Quality
from qv_acq.profiles import loader
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.rtu import FaultInjection, handle_request
from qv_acq.simulator.server import SimulatorServer
from qv_acq.simulator.signals import SignalSpec


class TestEncode:
    @pytest.mark.parametrize("value", [0, 1, -1, 32767, -32768])
    def test_int16_round_trip(self, value: int) -> None:
        words = enc.encode_raw(value, "int16")
        assert dec.decode_raw(words, "int16") == value

    @pytest.mark.parametrize("word_order", ["big", "little"])
    def test_int32_round_trip_both_word_orders(self, word_order: str) -> None:
        words = enc.encode_raw(-123456, "int32", word_order=word_order)
        assert dec.decode_raw(words, "int32", word_order=word_order) == -123456

    def test_float32_round_trip(self) -> None:
        words = enc.encode_raw(3.5, "float32")
        assert dec.decode_raw(words, "float32") == pytest.approx(3.5)

    def test_scaled_round_trip_within_quantisation(self) -> None:
        scale = dec.full_scale_factor(16.0)
        words = enc.encode(1.0, "int16", scale=scale)
        assert dec.decode(words, "int16", scale=scale) == pytest.approx(1.0, abs=scale)

    def test_out_of_range_clamps_by_default(self) -> None:
        assert dec.decode_raw(enc.encode_raw(99999, "int16"), "int16") == 32767

    def test_out_of_range_can_raise(self) -> None:
        with pytest.raises(dec.DecodeError):
            enc.encode_raw(99999, "int16", clamp=False)

    def test_zero_scale_rejected(self) -> None:
        with pytest.raises(dec.DecodeError):
            enc.encode(1.0, "int16", scale=0.0)

    def test_every_profile_channel_round_trips(self) -> None:
        # Guards against a profile declaring a scale that cannot represent its
        # own declared range.
        for model in ("WTVB01-485", "HWT901B-485"):
            profile = loader.get(model)
            for channel in profile.channels:
                target = channel.maximum if channel.maximum is not None else 1.0
                target = min(target, 100.0)
                words = enc.encode(
                    target,
                    channel.data_type,
                    word_order=channel.word_order,
                    scale=channel.scale,
                    offset=channel.offset,
                )
                back = dec.decode(
                    words,
                    channel.data_type,
                    word_order=channel.word_order,
                    scale=channel.scale,
                    offset=channel.offset,
                )
                assert back == pytest.approx(target, abs=abs(channel.scale)), (
                    f"{model}:{channel.key} failed round trip"
                )


class TestRtuFraming:
    def _device(self) -> SimulatedDevice:
        return SimulatedDevice(profile=loader.get("HWT901B-485"), slave_id=0x50)

    def _request(self, slave: int = 0x50, start: int = 0x34, count: int = 6) -> bytes:
        from qv_acq.crc import append_crc

        return append_crc(
            bytes([slave, 0x03]) + start.to_bytes(2, "big") + count.to_bytes(2, "big")
        )

    def test_valid_request_is_answered(self) -> None:
        devices = {0x50: self._device()}
        response = handle_request(self._request(), devices, 0.0)
        assert response is not None
        assert response[0] == 0x50 and response[1] == 0x03
        assert response[2] == 12  # byte count for six registers

    def test_bad_crc_gets_silence(self) -> None:
        frame = bytearray(self._request())
        frame[-1] ^= 0xFF
        assert handle_request(bytes(frame), {0x50: self._device()}, 0.0) is None

    def test_other_slave_gets_silence(self) -> None:
        assert handle_request(self._request(slave=0x51), {0x50: self._device()}, 0.0) is None

    def test_write_function_is_refused(self) -> None:
        from qv_acq.crc import append_crc

        frame = append_crc(bytes([0x50, 0x06, 0x00, 0x00, 0x00, 0x01]))
        response = handle_request(frame, {0x50: self._device()}, 0.0)
        assert response is not None
        assert response[1] == 0x86  # exception bit set
        assert response[2] == 0x01  # illegal function

    def test_excessive_count_is_refused(self) -> None:
        response = handle_request(self._request(count=200), {0x50: self._device()}, 0.0)
        assert response is not None and response[1] == 0x83

    def test_drop_fault_produces_silence(self) -> None:
        faults = FaultInjection(drop_probability=1.0)
        assert handle_request(self._request(), {0x50: self._device()}, 0.0, faults=faults) is None

    def test_crc_fault_produces_invalid_frame(self) -> None:
        from qv_acq.crc import verify

        faults = FaultInjection(crc_error_probability=1.0)
        response = handle_request(self._request(), {0x50: self._device()}, 0.0, faults=faults)
        assert response is not None and not verify(response)


class TestEndToEnd:
    def test_reads_gravity_from_simulated_hwt901b(self) -> None:
        profile = loader.get("HWT901B-485")
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        group = next(g for g in profile.register_groups if g.key == "inertial")

        with SimulatorServer({0x50: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                reading = reader.read_group(profile, group, slave_id=0x50)

        assert reading.ok
        assert reading.quality is Quality.GOOD
        assert set(reading.channels) == {c.key for c in group.channels}

        # A stationary unit reads about 1 g vertically. This is gravity, and the
        # simulator models it so the distinction survives to the dashboard.
        assert reading.channels["accel_z"].value == pytest.approx(1.0, abs=0.05)
        assert abs(reading.channels["accel_x"].value) < 0.2
        assert reading.channels["accel_z"].unit == "g"

    def test_reads_vibration_from_simulated_wtvb01(self) -> None:
        profile = loader.get("WTVB01-485")
        device = SimulatedDevice(profile=profile, slave_id=0x51)
        group = profile.register_groups[0]

        with SimulatorServer({0x51: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                reading = reader.read_group(profile, group, slave_id=0x51)

        assert reading.ok
        assert reading.channels["temperature"].value == pytest.approx(24.0, abs=1.0)
        assert reading.channels["vib_velocity_x"].value > 0
        assert reading.channels["vib_velocity_x"].unit == "mm/s"

    def test_two_sensor_models_share_one_bus(self) -> None:
        vibration = loader.get("WTVB01-485")
        inertial = loader.get("HWT901B-485")
        devices = {
            0x50: SimulatedDevice(profile=inertial, slave_id=0x50),
            0x51: SimulatedDevice(profile=vibration, slave_id=0x51),
        }

        with SimulatorServer(devices) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                inertial_reading = reader.read_group(
                    inertial,
                    next(g for g in inertial.register_groups if g.key == "attitude"),
                    slave_id=0x50,
                )
                vibration_reading = reader.read_group(
                    vibration, vibration.register_groups[0], slave_id=0x51
                )

        assert inertial_reading.ok and vibration_reading.ok
        assert "roll" in inertial_reading.channels
        assert "vib_displacement_x" in vibration_reading.channels
        # Mixed buses must not leak channels between models.
        assert "roll" not in vibration_reading.channels

    def test_unanswered_slave_is_flagged_bad_not_crashed(self) -> None:
        profile = loader.get("HWT901B-485")
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        group = profile.register_groups[0]

        with SimulatorServer({0x50: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=0.3) as reader:
                reading = reader.read_group(profile, group, slave_id=0x60)

        assert not reading.ok
        assert reading.quality is Quality.BAD
        assert all(channel.value is None for channel in reading.channels.values())
        assert reading.error

    def test_total_dropout_is_survived(self) -> None:
        profile = loader.get("HWT901B-485")
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        group = profile.register_groups[0]

        with SimulatorServer({0x50: device}, faults=FaultInjection(drop_probability=1.0)) as server:
            with ModbusReader(server.port, baud=115200, timeout=0.3) as reader:
                reading = reader.read_group(profile, group, slave_id=0x50)

        assert reading.quality is Quality.BAD
        assert reading.latency_ms > 0

    def test_corrupt_crc_is_rejected_not_decoded(self) -> None:
        profile = loader.get("HWT901B-485")
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        group = profile.register_groups[0]
        faults = FaultInjection(crc_error_probability=1.0)

        with SimulatorServer({0x50: device}, faults=faults) as server:
            with ModbusReader(server.port, baud=115200, timeout=0.3) as reader:
                reading = reader.read_group(profile, group, slave_id=0x50)

        # A corrupted frame must never reach the decoder as if it were data.
        assert reading.quality is Quality.BAD

    def test_implausible_value_is_flagged_but_still_returned(self) -> None:
        profile = loader.get("HWT901B-485")
        device = SimulatedDevice(
            profile=profile,
            slave_id=0x50,
            signals={"temperature": SignalSpec(waveform="constant", base=999.0)},
        )
        group = next(g for g in profile.register_groups if g.key == "attitude")

        with SimulatorServer({0x50: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                reading = reader.read_group(profile, group, slave_id=0x50)

        # Clamped at encode time to the int16 ceiling, which still decodes far
        # outside the declared -40..125 degC range.
        assert reading.channels["temperature"].quality is Quality.IMPLAUSIBLE
        assert reading.quality is Quality.IMPLAUSIBLE
        assert reading.channels["temperature"].value is not None

    def test_repeated_reads_show_the_signal_changing(self) -> None:
        import time as _time

        profile = loader.get("HWT901B-485")
        device = SimulatedDevice(profile=profile, slave_id=0x50)
        group = next(g for g in profile.register_groups if g.key == "inertial")

        with SimulatorServer({0x50: device}) as server:
            with ModbusReader(server.port, baud=115200, timeout=1.0) as reader:
                first = reader.read_group(profile, group, slave_id=0x50)
                _time.sleep(0.15)
                second = reader.read_group(profile, group, slave_id=0x50)

        assert first.ok and second.ok
        assert first.channels["accel_z"].value != second.channels["accel_z"].value
