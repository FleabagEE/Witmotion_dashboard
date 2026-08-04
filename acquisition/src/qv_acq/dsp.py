"""Acceleration time series to velocity and displacement.

This is the chain a seismograph runs, and it exists here ready for a sensor that
streams raw acceleration. **The WTVB01-485 does not.** Its acceleration register
is filtered inside the device - measured at 2% sample-to-sample variation and 499
distinct values across three hours - and its velocity and displacement outputs are
unsigned severity magnitudes computed on-device, not signals. Nothing in this
module should ever be pointed at those channels: integrating a quasi-DC register
produces a smooth, plausible, entirely fictional curve, which on a structural
monitoring appliance is the most dangerous output available.

`refuse_if_not_a_waveform` exists to enforce that.

Written in pure Python on purpose. The acquisition service runs on four
dependencies and adding numpy and scipy to an appliance for a few hundred lines
of well-understood arithmetic is a poor trade - especially when the arithmetic can
be checked against closed-form answers instead of trusted.

THE ORDER MATTERS AND IS NOT NEGOTIABLE
---------------------------------------

Integration turns any constant offset into a ramp, and any ramp into a parabola.
So the signal is high-passed *before* each integration, never only at the end:

    detrend -> taper -> highpass -> integrate -> highpass -> integrate -> highpass

Double integration also amplifies low frequency by 1/omega squared, which is why
the same cutoff is applied again after each step rather than relying on the first
one to have been enough.

ANTI-ALIASING CANNOT BE APPLIED HERE
------------------------------------

Anti-aliasing must happen before sampling, in analog or at the acquisition rate.
By the time a sample reaches this module the aliasing, if any, is already in it
and no filter can undo it. `decimate` below anti-aliases before *reducing* a rate
we already have, which is a different and legitimate operation.

Units are SI throughout: m/s^2 in, m/s and m out. Conversion to g, mm/s and um
happens at the edges, where a reader can see it.
"""

from __future__ import annotations

import math
from dataclasses import dataclass

#: Standard gravity, for converting g to m/s^2.
G = 9.80665

#: Default high-pass corner, in Hz.
#:
#: 0.01 Hz is the usual strong-motion choice: low enough to keep the long-period
#: content of a real structural response, high enough to stop instrument drift
#: being integrated into metres of displacement. It is a default, not a
#: constant - the right value depends on the structure's fundamental period and
#: is an engineering decision, so it is a parameter everywhere.
DEFAULT_HIGHPASS_HZ = 0.01


class NotAWaveform(ValueError):
    """Raised when a series cannot honestly be treated as a time series."""


@dataclass(frozen=True)
class Waveform:
    """A uniformly sampled signal, in SI units."""

    samples: list[float]
    sample_hz: float

    def __post_init__(self) -> None:
        if self.sample_hz <= 0:
            raise ValueError("sample_hz must be positive")

    @property
    def duration(self) -> float:
        return len(self.samples) / self.sample_hz

    def peak(self) -> float:
        return max((abs(s) for s in self.samples), default=0.0)


# --- guards -----------------------------------------------------------------

def refuse_if_not_a_waveform(samples: list[float], *, min_variation: float = 0.05) -> None:
    """Refuse a series that is too static to be a signal.

    The failure this prevents is specific and was measured on the connected
    hardware: a register that changes on 2% of reads, integrated twice, yields a
    smooth curve that looks exactly like a real displacement trace and means
    nothing at all.

    ``min_variation`` is the fraction of adjacent samples that must differ. Real
    accelerometer output at any usable rate differs on nearly every sample -
    noise alone guarantees it - so a threshold as low as 5% still catches the
    quasi-DC case by a wide margin.
    """
    if len(samples) < 3:
        raise NotAWaveform(f"only {len(samples)} sample(s); nothing to process")

    changed = sum(
        1 for i in range(1, len(samples)) if samples[i] != samples[i - 1]
    )
    fraction = changed / (len(samples) - 1)

    if fraction < min_variation:
        raise NotAWaveform(
            f"only {fraction:.1%} of adjacent samples differ - this is a filtered or "
            f"slowly-updated output, not a waveform. Integrating it would produce a "
            f"plausible curve that means nothing. "
            f"(The WTVB01-485 acceleration register measures about 2%.)"
        )


# --- preparation ------------------------------------------------------------

def remove_mean(samples: list[float]) -> list[float]:
    mean = sum(samples) / len(samples)
    return [s - mean for s in samples]


def remove_trend(samples: list[float]) -> list[float]:
    """Least-squares linear detrend.

    Done before filtering rather than instead of it. A ramp left in the record
    becomes a parabola after one integration, and the high-pass would have to
    remove something it was never designed for.
    """
    n = len(samples)
    if n < 2:
        return list(samples)

    mean_x = (n - 1) / 2
    mean_y = sum(samples) / n
    covariance = sum((i - mean_x) * (s - mean_y) for i, s in enumerate(samples))
    variance = sum((i - mean_x) ** 2 for i in range(n))
    slope = covariance / variance if variance > 0 else 0.0

    return [s - (mean_y + slope * (i - mean_x)) for i, s in enumerate(samples)]


def cosine_taper(samples: list[float], fraction: float = 0.05) -> list[float]:
    """Taper both ends to zero over `fraction` of the record.

    A record that starts and ends abruptly has broadband content at the edges
    that the filter rings on, and the ringing is largest exactly where the
    integration constant is least constrained. Tapering costs a little of the
    record and removes an artefact that is easily mistaken for a signal.
    """
    n = len(samples)
    width = max(1, int(n * fraction))
    if width * 2 >= n:
        return list(samples)

    out = list(samples)
    for i in range(width):
        w = 0.5 * (1 - math.cos(math.pi * i / width))
        out[i] *= w
        out[n - 1 - i] *= w

    return out


# --- filtering --------------------------------------------------------------

def _highpass_biquad(cutoff_hz: float, sample_hz: float) -> tuple[float, ...]:
    """Second-order Butterworth high-pass, bilinear transform.

    Returns (b0, b1, b2, a1, a2) already normalised by a0.
    """
    if cutoff_hz <= 0 or cutoff_hz >= sample_hz / 2:
        raise ValueError(
            f"cutoff {cutoff_hz} Hz must be above 0 and below Nyquist ({sample_hz / 2} Hz)"
        )

    w0 = 2 * math.pi * cutoff_hz / sample_hz
    cos_w0 = math.cos(w0)
    # Q = 1/sqrt(2) gives the maximally flat Butterworth response.
    alpha = math.sin(w0) / (2 * (1 / math.sqrt(2)))

    b0 = (1 + cos_w0) / 2
    b1 = -(1 + cos_w0)
    b2 = (1 + cos_w0) / 2
    a0 = 1 + alpha
    a1 = -2 * cos_w0
    a2 = 1 - alpha

    return (b0 / a0, b1 / a0, b2 / a0, a1 / a0, a2 / a0)


def _apply_biquad(samples: list[float], coefficients: tuple[float, ...]) -> list[float]:
    b0, b1, b2, a1, a2 = coefficients
    x1 = x2 = y1 = y2 = 0.0
    out = []

    for x0 in samples:
        y0 = b0 * x0 + b1 * x1 + b2 * x2 - a1 * y1 - a2 * y2
        out.append(y0)
        x2, x1 = x1, x0
        y2, y1 = y1, y0

    return out


def highpass(samples: list[float], cutoff_hz: float, sample_hz: float) -> list[float]:
    """Zero-phase Butterworth high-pass.

    Filtered forwards then backwards. A one-way IIR filter shifts the signal in
    time by a frequency-dependent amount, which would misalign the peak against
    every other channel's timestamp and against any other sensor on the same
    structure. Running it both ways cancels the phase exactly, at the cost of
    doubling the effective order.
    """
    coefficients = _highpass_biquad(cutoff_hz, sample_hz)

    forward = _apply_biquad(samples, coefficients)
    backward = _apply_biquad(list(reversed(forward)), coefficients)

    return list(reversed(backward))


def _lowpass_biquad(cutoff_hz: float, sample_hz: float) -> tuple[float, ...]:
    w0 = 2 * math.pi * cutoff_hz / sample_hz
    cos_w0 = math.cos(w0)
    alpha = math.sin(w0) / (2 * (1 / math.sqrt(2)))

    b0 = (1 - cos_w0) / 2
    b1 = 1 - cos_w0
    b2 = (1 - cos_w0) / 2
    a0 = 1 + alpha
    a1 = -2 * cos_w0
    a2 = 1 - alpha

    return (b0 / a0, b1 / a0, b2 / a0, a1 / a0, a2 / a0)


def decimate(samples: list[float], sample_hz: float, factor: int) -> tuple[list[float], float]:
    """Reduce the sample rate, anti-aliasing first.

    This is the only anti-aliasing that can honestly be done in software: it
    protects a rate reduction we are about to perform. It cannot undo aliasing
    that happened when the signal was first sampled - that is in the data
    permanently, and no filter reaches back before the ADC.
    """
    if factor < 1:
        raise ValueError("factor must be at least 1")
    if factor == 1:
        return list(samples), sample_hz

    new_rate = sample_hz / factor
    # 0.4 of the new Nyquist, the same margin the appliance uses when deciding
    # what spectral content it will claim.
    cutoff = 0.4 * (new_rate / 2)
    coefficients = _lowpass_biquad(cutoff, sample_hz)

    forward = _apply_biquad(samples, coefficients)
    smoothed = list(reversed(_apply_biquad(list(reversed(forward)), coefficients)))

    return smoothed[::factor], new_rate


# --- integration ------------------------------------------------------------

def integrate(samples: list[float], sample_hz: float) -> list[float]:
    """Cumulative trapezoidal integration, starting from zero.

    The integration constant is unknowable - a record cannot say what velocity
    the structure already had - so it is taken as zero and the high-pass that
    follows removes whatever offset that assumption introduced. This is why the
    filter after each integration is not optional.
    """
    dt = 1.0 / sample_hz
    out = [0.0]
    total = 0.0

    for i in range(1, len(samples)):
        total += (samples[i] + samples[i - 1]) * dt / 2
        out.append(total)

    return out


@dataclass(frozen=True)
class MotionResult:
    """Acceleration, velocity and displacement, all about zero."""

    acceleration: Waveform      # m/s^2
    velocity: Waveform          # m/s
    displacement: Waveform      # m
    highpass_hz: float

    def as_engineering_units(self) -> dict[str, list[float]]:
        """g, mm/s and um - the units the dashboard and the standards use."""
        return {
            "acceleration_g": [a / G for a in self.acceleration.samples],
            "velocity_mm_s": [v * 1000 for v in self.velocity.samples],
            "displacement_um": [d * 1e6 for d in self.displacement.samples],
        }


def process(
    acceleration_ms2: list[float],
    sample_hz: float,
    *,
    highpass_hz: float = DEFAULT_HIGHPASS_HZ,
    taper_fraction: float = 0.05,
    check_is_waveform: bool = True,
) -> MotionResult:
    """The full chain: raw acceleration in, three signals about zero out.

    Set ``check_is_waveform=False`` only for synthetic input in tests. On real
    data the guard is the difference between a measurement and a fabrication.
    """
    if check_is_waveform:
        refuse_if_not_a_waveform(acceleration_ms2)

    prepared = cosine_taper(remove_trend(remove_mean(acceleration_ms2)), taper_fraction)
    acceleration = highpass(prepared, highpass_hz, sample_hz)

    # High-pass after every integration, not once at the end. Each integration
    # divides by frequency, so whatever offset survives is amplified next time.
    velocity = highpass(integrate(acceleration, sample_hz), highpass_hz, sample_hz)
    displacement = highpass(integrate(velocity, sample_hz), highpass_hz, sample_hz)

    return MotionResult(
        acceleration=Waveform(acceleration, sample_hz),
        velocity=Waveform(velocity, sample_hz),
        displacement=Waveform(displacement, sample_hz),
        highpass_hz=highpass_hz,
    )
