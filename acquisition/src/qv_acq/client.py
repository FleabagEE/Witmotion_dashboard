"""Profile-driven Modbus reader.

Turns a register group into decoded, quality-flagged engineering values. This is
the boundary between the protocol and everything above it: nothing downstream
sees a register number.
"""

from __future__ import annotations

import time
from dataclasses import dataclass, field
from enum import StrEnum

from pymodbus.client import ModbusSerialClient

from .decode import decode, plausible
from .profiles.schema import RegisterGroup, SensorProfile


class Quality(StrEnum):
    GOOD = "good"
    #: Decoded, but outside the profile's declared engineering range. Strong
    #: evidence of a wrong register map, word order, or slave identity.
    IMPLAUSIBLE = "implausible"
    #: No response, bad CRC, or an exception reply.
    BAD = "bad"


@dataclass
class ChannelReading:
    key: str
    value: float | None
    unit: str
    quality: Quality
    raw: list[int] = field(default_factory=list)


@dataclass
class GroupReading:
    slave_id: int
    group_key: str
    profile_model: str
    profile_version: str
    channels: dict[str, ChannelReading]
    latency_ms: float
    quality: Quality
    error: str | None = None

    @property
    def ok(self) -> bool:
        return self.quality is not Quality.BAD

    def values(self) -> dict[str, float | None]:
        return {key: reading.value for key, reading in self.channels.items()}


class ModbusReader:
    """Synchronous reader for one serial port.

    Exactly one reader may own a port at a time; ownership is enforced a layer up
    by the device manager's advisory lock, not here.
    """

    def __init__(self, port: str, *, baud: int = 9600, timeout: float = 1.0) -> None:
        self.port = port
        self.baud = baud
        self.timeout = timeout
        self._client: ModbusSerialClient | None = None

    def connect(self) -> None:
        client = ModbusSerialClient(
            port=self.port,
            baudrate=self.baud,
            bytesize=8,
            parity="N",
            stopbits=1,
            timeout=self.timeout,
        )
        if not client.connect():
            raise ConnectionError(f"could not open {self.port} at {self.baud} baud")
        self._client = client

    def close(self) -> None:
        if self._client is not None:
            self._client.close()
            self._client = None

    def __enter__(self) -> "ModbusReader":
        self.connect()
        return self

    def __exit__(self, *_exc: object) -> None:
        self.close()

    def read_group(
        self,
        profile: SensorProfile,
        group: RegisterGroup,
        *,
        slave_id: int | None = None,
    ) -> GroupReading:
        if self._client is None:
            raise RuntimeError("reader is not connected")

        slave = slave_id if slave_id is not None else profile.serial.default_slave_id
        started = time.monotonic()
        error: str | None = None
        registers: list[int] | None = None

        try:
            response = self._client.read_holding_registers(
                address=group.start_address,
                count=group.register_count,
                device_id=slave,
            )
            if response is None or response.isError():
                error = str(response) if response is not None else "no response"
            else:
                registers = list(response.registers)
        except Exception as exc:  # noqa: BLE001 - transport faults are expected
            error = f"{type(exc).__name__}: {exc}"

        latency_ms = (time.monotonic() - started) * 1000.0

        if registers is None:
            return GroupReading(
                slave_id=slave,
                group_key=group.key,
                profile_model=profile.model,
                profile_version=profile.profile_version,
                channels={
                    channel.key: ChannelReading(channel.key, None, channel.unit, Quality.BAD)
                    for channel in group.channels
                },
                latency_ms=latency_ms,
                quality=Quality.BAD,
                error=error,
            )

        channels: dict[str, ChannelReading] = {}
        worst = Quality.GOOD

        for channel in group.channels:
            start = channel.address - group.start_address
            words = registers[start : start + channel.word_count]
            value = decode(
                words,
                channel.data_type,
                word_order=channel.word_order,
                scale=channel.scale,
                offset=channel.offset,
            )
            quality = (
                Quality.GOOD
                if plausible(value, minimum=channel.minimum, maximum=channel.maximum)
                else Quality.IMPLAUSIBLE
            )
            if quality is Quality.IMPLAUSIBLE:
                worst = Quality.IMPLAUSIBLE
            channels[channel.key] = ChannelReading(
                key=channel.key,
                value=value,
                unit=channel.unit,
                quality=quality,
                raw=list(words),
            )

        return GroupReading(
            slave_id=slave,
            group_key=group.key,
            profile_model=profile.model,
            profile_version=profile.profile_version,
            channels=channels,
            latency_ms=latency_ms,
            quality=worst,
        )
