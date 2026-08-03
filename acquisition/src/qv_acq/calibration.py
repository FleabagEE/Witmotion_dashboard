"""Per-unit accelerometer calibration.

The register decoding is correct - that was established by the magnitude varying
with orientation rather than staying uniformly low. What remains is the sensor's
own gain error: on this unit gravity reads 0.9898 g with the vector on X and
0.9625 g with it on Z, so the axes disagree with each other by about 3%. That is
ordinary for an uncalibrated consumer MEMS part (+/-3-5% is typical) and it is a
property of the device, not of the software reading it.

It matters here because inclination is derived from the direction of that vector.
A 3.8% error on one axis is worth roughly two degrees of tilt error near 45
degrees, which is large for something reported as an inclinometer.

Two decisions govern this file:

**Absent means identity.** With no calibration file, every gain is 1.0 and every
offset 0.0, and readings pass through untouched. An appliance must never apply a
correction nobody chose to apply.

**A corrected value says so.** Calibrated channels are marked PROCESSED rather
than NATIVE, so the record distinguishes what the sensor said from what the
appliance concluded. A calibration is a fitted model, and a reader is entitled to
know one was involved.
"""

from __future__ import annotations

import logging
import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import yaml

from .measurement import ChannelValue, ValueClass

log = logging.getLogger("qv_acq.calibration")

DEFAULT_PATH = Path("/etc/quakevault/calibration.yaml")


@dataclass(frozen=True)
class AxisCalibration:
    """``true = (measured - offset) / gain`` for one channel."""

    gain: float = 1.0
    offset: float = 0.0

    def apply(self, value: float) -> float:
        if self.gain == 0:
            return value
        return (value - self.offset) / self.gain

    @property
    def is_identity(self) -> bool:
        return self.gain == 1.0 and self.offset == 0.0


@dataclass(frozen=True)
class SensorCalibration:
    sensor_id: str
    axes: dict[str, AxisCalibration]
    #: Free text recording who produced this and how. Carried so a calibration
    #: can be traced to a procedure rather than appearing as bare numbers.
    note: str = ""

    def apply(self, channels: dict[str, ChannelValue]) -> dict[str, ChannelValue]:
        out = dict(channels)
        for key, axis in self.axes.items():
            channel = out.get(key)
            if channel is None or channel.value is None or axis.is_identity:
                continue
            out[key] = ChannelValue(
                value=axis.apply(channel.value),
                unit=channel.unit,
                quality=channel.quality,
                # Not NATIVE any more: a fitted correction has been applied and
                # the record should not imply the sensor reported this.
                value_class=ValueClass.PROCESSED,
                raw=channel.raw,
            )
        return out


IDENTITY = SensorCalibration(sensor_id="", axes={})


def load(path: Path = DEFAULT_PATH) -> dict[str, SensorCalibration]:
    """Read calibrations by sensor id. A missing file is normal, not an error."""
    if not path.exists():
        return {}

    try:
        raw: Any = yaml.safe_load(path.read_text()) or {}
    except Exception as exc:  # noqa: BLE001
        # Refusing to start over a malformed calibration would take monitoring
        # down for the sake of a correction. Uncalibrated is the safe fallback,
        # and it is loud.
        log.error("calibration file %s is unreadable (%s); running uncalibrated", path, exc)
        return {}

    out: dict[str, SensorCalibration] = {}
    for sensor_id, entry in (raw.get("sensors") or {}).items():
        axes = {
            key: AxisCalibration(
                gain=float(values.get("gain", 1.0)),
                offset=float(values.get("offset", 0.0)),
            )
            for key, values in (entry.get("axes") or {}).items()
        }
        out[sensor_id] = SensorCalibration(
            sensor_id=sensor_id, axes=axes, note=str(entry.get("note", "")),
        )
        log.info("loaded calibration for %s: %s", sensor_id, {k: v.gain for k, v in axes.items()})

    return out


def solve(positions: list[tuple[float, float, float]]) -> dict[str, AxisCalibration]:
    """Fit per-axis gain and offset from still readings in varied orientations.

    A stationary accelerometer measures exactly 1 g whichever way it is turned,
    so the true readings lie on a unit sphere and the measured ones lie on an
    offset, axis-scaled ellipsoid. Fitting that ellipsoid recovers the gain and
    offset of each axis.

    Needs at least six positions and, more importantly, positions that actually
    differ: six readings all taken flat constrain the Z axis and say nothing
    about X or Y. The caller is responsible for the spread; ``coverage`` below
    measures it.

    Solved by alternating least squares rather than a closed form. It is a few
    lines, converges in a handful of iterations for this well-conditioned case,
    and avoids taking a linear-algebra dependency into the acquisition service
    for something run once per unit.
    """
    if len(positions) < 6:
        raise ValueError(f"need at least 6 positions, got {len(positions)}")

    gains = [1.0, 1.0, 1.0]
    offsets = [0.0, 0.0, 0.0]

    for _ in range(200):
        # Corrected vectors under the current estimate, normalised to unit
        # length: that is what they should have been.
        targets = []
        for measured in positions:
            corrected = [
                (measured[i] - offsets[i]) / gains[i] if gains[i] else measured[i]
                for i in range(3)
            ]
            norm = math.sqrt(sum(c * c for c in corrected)) or 1.0
            targets.append([c / norm for c in corrected])

        # Least-squares refit of measured = gain * target + offset, per axis.
        for i in range(3):
            n = len(positions)
            mean_t = sum(t[i] for t in targets) / n
            mean_m = sum(p[i] for p in positions) / n
            cov = sum((targets[j][i] - mean_t) * (positions[j][i] - mean_m) for j in range(n))
            var = sum((targets[j][i] - mean_t) ** 2 for j in range(n))
            if var > 1e-12:
                gains[i] = cov / var
                offsets[i] = mean_m - gains[i] * mean_t

    return {
        "accel_x": AxisCalibration(gains[0], offsets[0]),
        "accel_y": AxisCalibration(gains[1], offsets[1]),
        "accel_z": AxisCalibration(gains[2], offsets[2]),
    }


def coverage(positions: list[tuple[float, float, float]]) -> float:
    """How well the positions span all three axes, from 0 to 1.

    A calibration fitted from orientations that barely differ is worse than
    none: it looks authoritative and constrains almost nothing. This is reported
    so a thin fit can be refused rather than trusted.
    """
    if not positions:
        return 0.0

    # Span of each axis across the set. A full set reaching +1 and -1 on every
    # axis spans 2.0 on each.
    spans = [
        max(p[i] for p in positions) - min(p[i] for p in positions)
        for i in range(3)
    ]

    return min(1.0, min(spans) / 2.0)


def residual_error(
    positions: list[tuple[float, float, float]],
    axes: dict[str, AxisCalibration],
) -> float:
    """RMS departure from 1 g after correction. The number that says it worked."""
    keys = ("accel_x", "accel_y", "accel_z")
    total = 0.0
    for measured in positions:
        corrected = [axes[keys[i]].apply(measured[i]) for i in range(3)]
        total += (math.sqrt(sum(c * c for c in corrected)) - 1.0) ** 2

    return math.sqrt(total / len(positions))
