"""Deterministic signal generation for the sensor simulators.

Everything is a pure function of elapsed time and a seed, so a failing soak test
can be replayed exactly. No wall-clock reads and no global RNG.
"""

from __future__ import annotations

import math
import random
from dataclasses import dataclass, field
from typing import Literal

Waveform = Literal["constant", "sine", "noise", "drift", "harmonic", "stuck", "implausible"]


@dataclass
class SignalSpec:
    """Description of one simulated channel."""

    waveform: Waveform = "constant"
    base: float = 0.0
    amplitude: float = 0.0
    frequency_hz: float = 1.0
    noise: float = 0.0
    drift_per_second: float = 0.0
    harmonics: tuple[float, ...] = field(default_factory=tuple)
    phase: float = 0.0
    seed: int = 0

    def value_at(self, t: float) -> float:
        """Engineering value at elapsed time *t* seconds."""
        rng = random.Random((self.seed, round(t, 6)).__hash__())
        value = self.base + self.drift_per_second * t

        if self.waveform == "sine":
            value += self.amplitude * math.sin(2 * math.pi * self.frequency_hz * t + self.phase)
        elif self.waveform == "harmonic":
            value += self.amplitude * math.sin(2 * math.pi * self.frequency_hz * t + self.phase)
            for index, weight in enumerate(self.harmonics, start=2):
                value += self.amplitude * weight * math.sin(
                    2 * math.pi * self.frequency_hz * index * t + self.phase
                )
        elif self.waveform == "noise":
            value += rng.gauss(0.0, max(self.amplitude, 1e-12))
        elif self.waveform == "stuck":
            return self.base
        elif self.waveform == "implausible":
            # Deliberately outside any sane engineering range, to prove the
            # plausibility checks and quality flags actually fire.
            return self.base * 1000.0 + 1e6

        if self.noise:
            value += rng.gauss(0.0, self.noise)
        return value


def tilt_event(base: float, magnitude: float, at: float, duration: float) -> "EventSpec":
    """A step change that returns to baseline: a mount shifting and settling."""
    return EventSpec(base=base, magnitude=magnitude, start=at, duration=duration)


@dataclass
class EventSpec:
    """A transient superimposed on a baseline."""

    base: float = 0.0
    magnitude: float = 0.0
    start: float = 0.0
    duration: float = 1.0

    def value_at(self, t: float) -> float:
        if t < self.start or t > self.start + self.duration:
            return self.base
        # Raised-cosine so the transient has no discontinuity to ring on.
        progress = (t - self.start) / self.duration
        return self.base + self.magnitude * 0.5 * (1 - math.cos(2 * math.pi * progress))


#: Channel presets by quantity, used when a simulated device is not given an
#: explicit signal for a channel. Values are plausible for a healthy machine.
DEFAULTS: dict[str, SignalSpec] = {
    "acceleration": SignalSpec(waveform="sine", base=0.0, amplitude=0.02, frequency_hz=3.0, noise=0.002),
    "angular_velocity": SignalSpec(waveform="noise", base=0.0, amplitude=0.5, noise=0.1),
    "magnetic_field": SignalSpec(waveform="constant", base=300.0, noise=5.0),
    "attitude_angle": SignalSpec(waveform="sine", base=0.0, amplitude=0.5, frequency_hz=0.05, noise=0.01),
    "quaternion": SignalSpec(waveform="constant", base=0.5, noise=0.001),
    "vibration_velocity": SignalSpec(waveform="harmonic", base=2.5, amplitude=0.4, frequency_hz=0.1, harmonics=(0.3, 0.15), noise=0.05),
    "vibration_displacement": SignalSpec(waveform="sine", base=40.0, amplitude=6.0, frequency_hz=0.08, noise=1.0),
    "vibration_angle": SignalSpec(waveform="sine", base=0.0, amplitude=0.3, frequency_hz=0.05, noise=0.01),
    "dominant_frequency": SignalSpec(waveform="constant", base=50.0, noise=0.5),
    "temperature": SignalSpec(waveform="drift", base=24.0, drift_per_second=0.001, noise=0.05),
    "device_time": SignalSpec(waveform="constant", base=0.0),
    "identity": SignalSpec(waveform="constant", base=0.0),
    "diagnostic": SignalSpec(waveform="constant", base=0.0),
}


def default_for(quantity: str, seed: int = 0) -> SignalSpec:
    spec = DEFAULTS.get(quantity, SignalSpec())
    return SignalSpec(**{**spec.__dict__, "seed": seed})


def vertical_gravity(seed: int = 0) -> SignalSpec:
    """A stationary accelerometer's vertical axis: ~1 g, which is not motion."""
    return SignalSpec(waveform="sine", base=1.0, amplitude=0.01, frequency_hz=2.0, noise=0.002, seed=seed)
