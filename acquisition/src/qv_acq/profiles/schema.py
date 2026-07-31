"""Versioned sensor capability model.

A profile is data, not code. Adding a Modbus sensor must never require touching
the acquisition engine - only a new YAML profile plus fixtures.
"""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, Field, field_validator, model_validator

from ..decode import WORD_COUNT

VerificationStatus = Literal["verified", "candidate", "unverified"]
Quantity = Literal[
    "acceleration",
    "angular_velocity",
    "magnetic_field",
    "attitude_angle",
    "quaternion",
    "vibration_velocity",
    "vibration_displacement",
    "vibration_angle",
    "dominant_frequency",
    "temperature",
    "device_time",
    "identity",
    "diagnostic",
]
ValueClass = Literal["native", "processed", "derived", "simulated", "imported"]


class Channel(BaseModel):
    """One decoded measurement, sourced from one or more contiguous registers."""

    key: str = Field(pattern=r"^[a-z0-9_]+$", max_length=60)
    label: str = Field(max_length=120)
    quantity: Quantity
    unit: str = Field(max_length=20)
    address: int = Field(ge=0, le=0xFFFF)
    data_type: str = "int16"
    word_order: Literal["big", "little"] = "big"
    scale: float = 1.0
    offset: float = 0.0
    value_class: ValueClass = "native"
    minimum: float | None = None
    maximum: float | None = None
    notes: str = ""

    @field_validator("data_type")
    @classmethod
    def known_data_type(cls, value: str) -> str:
        if value not in WORD_COUNT:
            raise ValueError(f"unsupported data type {value!r}")
        return value

    @model_validator(mode="after")
    def range_is_ordered(self) -> "Channel":
        if (
            self.minimum is not None
            and self.maximum is not None
            and self.minimum >= self.maximum
        ):
            raise ValueError(f"channel {self.key}: minimum must be below maximum")
        return self

    @property
    def word_count(self) -> int:
        return WORD_COUNT[self.data_type]

    @property
    def end_address(self) -> int:
        """Exclusive end of the register window this channel occupies."""
        return self.address + self.word_count


class RegisterGroup(BaseModel):
    """A set of channels read in a single Modbus transaction."""

    key: str = Field(pattern=r"^[a-z0-9_]+$", max_length=60)
    label: str = Field(max_length=120)
    function_code: Literal[3, 4] = 3
    default_poll_hz: float = Field(gt=0, le=1000)
    channels: list[Channel] = Field(min_length=1)

    @model_validator(mode="after")
    def channels_are_contiguous(self) -> "RegisterGroup":
        ordered = sorted(self.channels, key=lambda c: c.address)
        for previous, current in zip(ordered, ordered[1:]):
            if current.address < previous.end_address:
                raise ValueError(
                    f"group {self.key}: channels {previous.key} and {current.key} "
                    "occupy overlapping registers"
                )
        return self

    @property
    def start_address(self) -> int:
        return min(channel.address for channel in self.channels)

    @property
    def register_count(self) -> int:
        """Total registers spanned, including any gaps between channels.

        Reading a contiguous span in one transaction is cheaper than issuing
        several small reads, so gaps are deliberately included.
        """
        return max(channel.end_address for channel in self.channels) - self.start_address


class ProtectedCommand(BaseModel):
    """A write that must never fire during discovery or without step-up auth."""

    key: str = Field(pattern=r"^[a-z0-9_]+$", max_length=60)
    label: str = Field(max_length=120)
    address: int = Field(ge=0, le=0xFFFF)
    function_code: Literal[6, 16] = 6
    requires_step_up: bool = True
    description: str = ""


class SerialDefaults(BaseModel):
    baud: int = 9600
    bits_per_char: int = 10
    supported_bauds: list[int] = Field(
        default_factory=lambda: [4800, 9600, 19200, 38400, 57600, 115200, 230400]
    )
    default_slave_id: int = Field(default=0x50, ge=1, le=247)
    turnaround_ms: float = Field(default=5.0, ge=0)


class SensorProfile(BaseModel):
    """Complete, versioned description of one Modbus sensor model."""

    schema_version: str = "1.0"
    profile_version: str
    manufacturer: str
    model: str
    protocol: Literal["modbus_rtu"] = "modbus_rtu"
    verification_status: VerificationStatus
    verification_notes: str = ""
    serial: SerialDefaults = Field(default_factory=SerialDefaults)
    register_groups: list[RegisterGroup] = Field(min_length=1)
    protected_commands: list[ProtectedCommand] = Field(default_factory=list)
    limitations: list[str] = Field(default_factory=list)

    @model_validator(mode="after")
    def channel_keys_unique(self) -> "SensorProfile":
        seen: set[str] = set()
        for group in self.register_groups:
            for channel in group.channels:
                if channel.key in seen:
                    raise ValueError(f"duplicate channel key {channel.key!r}")
                seen.add(channel.key)
        return self

    @property
    def channels(self) -> list[Channel]:
        return [c for group in self.register_groups for c in group.channels]

    def channel(self, key: str) -> Channel:
        for candidate in self.channels:
            if candidate.key == key:
                return candidate
        raise KeyError(f"profile {self.model} has no channel {key!r}")

    def capabilities(self) -> set[Quantity]:
        return {channel.quantity for channel in self.channels}

    def is_trustworthy(self) -> bool:
        """Whether this profile may drive alarms without operator confirmation."""
        return self.verification_status == "verified"
