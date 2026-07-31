"""Profile-driven simulated Modbus device.

The simulator is built from the same YAML profile the acquisition engine uses, so
a profile error surfaces in simulation instead of being discovered on site.
"""

from __future__ import annotations

from dataclasses import dataclass, field

from ..encode import encode
from ..profiles.schema import SensorProfile
from .signals import SignalSpec, default_for, vertical_gravity


@dataclass
class SimulatedDevice:
    """One Modbus slave synthesising register values from signal specs."""

    profile: SensorProfile
    slave_id: int
    signals: dict[str, SignalSpec] = field(default_factory=dict)
    seed: int = 0

    def __post_init__(self) -> None:
        for index, channel in enumerate(self.profile.channels):
            if channel.key in self.signals:
                continue
            if channel.key == "accel_z":
                # A stationary unit reads ~1 g vertically. Simulating this
                # correctly is what keeps "gravity is not vibration" honest all
                # the way through to the dashboard.
                self.signals[channel.key] = vertical_gravity(seed=self.seed + index)
            else:
                self.signals[channel.key] = default_for(
                    channel.quantity, seed=self.seed + index
                )

    def value(self, channel_key: str, t: float) -> float:
        """Engineering value for one channel at elapsed time *t*."""
        return self.signals[channel_key].value_at(t)

    def register_bank(self, t: float) -> dict[int, int]:
        """Full address -> word map at elapsed time *t*."""
        bank: dict[int, int] = {}
        for channel in self.profile.channels:
            words = encode(
                self.value(channel.key, t),
                channel.data_type,
                word_order=channel.word_order,
                scale=channel.scale,
                offset=channel.offset,
            )
            for index, word in enumerate(words):
                bank[channel.address + index] = word
        return bank

    def read(self, start: int, count: int, t: float) -> list[int]:
        """Read a contiguous window; unmapped registers read as zero."""
        bank = self.register_bank(t)
        return [bank.get(start + index, 0) for index in range(count)]
