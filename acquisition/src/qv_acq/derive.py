"""Quantities computed from measured registers rather than read from one.

The WTVB01-485 has no inclination register. Its 0x3D-0x3F are permanently zero
and 0x37-0x39 are angular *rate* - they read 10-15 counts with the sensor held
at a 12 degree tilt, which no angle register could.

Inclination is therefore derived from the acceleration vector, which is the one
register block independently confirmed on this unit (three axes summing to
0.993 g at rest). A stationary accelerometer measures nothing but gravity, so its
direction gives orientation exactly - this is what an inclinometer is.

The catch, and the reason for the quality gate below: that is only true while the
sensor is stationary. Any real acceleration adds to gravity and the derived angle
becomes meaningless. It cannot be filtered out, because a single accelerometer
cannot distinguish tilting from accelerating. So the derivation reports its own
validity instead of pretending otherwise.
"""

from __future__ import annotations

import math

from .measurement import ChannelValue, QualityStatus, ValueClass

#: Vector magnitude, in g, within which the sensor is treated as stationary
#: enough for orientation to mean anything. Gravity is 1 g; anything outside this
#: band is being accelerated, and the angles are not orientation.
QUASI_STATIC_BAND = (0.85, 1.15)

#: Below this magnitude the direction of the vector is numerically meaningless -
#: free fall, or a failed read - and no angle is reported at all.
MIN_MAGNITUDE = 0.1


def inclination(
    ax: float | None,
    ay: float | None,
    az: float | None,
) -> dict[str, ChannelValue]:
    """Roll, pitch and total tilt in degrees, from the gravity vector.

    Returns an empty mapping when any axis is missing: a partial vector has no
    direction, and substituting zero would silently invent an orientation.

    Yaw is deliberately absent. Rotation about the gravity vector does not change
    the gravity vector, so it is not observable from an accelerometer at all. It
    needs a magnetometer or a gyro integrated from a known start, and this device
    exposes neither in a documented register. Reporting it would be fabrication.
    """
    if ax is None or ay is None or az is None:
        return {}

    magnitude = math.sqrt(ax * ax + ay * ay + az * az)
    if magnitude < MIN_MAGNITUDE:
        return {}

    # Roll about the X axis and pitch about the Y axis, the usual aerospace
    # convention. atan2 rather than asin so the full +/-180 range is covered and
    # a vertical mounting does not divide by zero.
    roll = math.degrees(math.atan2(ay, az))
    pitch = math.degrees(math.atan2(-ax, math.sqrt(ay * ay + az * az)))

    # Angle between the sensor's Z axis and gravity: one number for "how far off
    # vertical is it", independent of which way it leaned.
    tilt = math.degrees(math.acos(max(-1.0, min(1.0, az / magnitude))))

    quality = (
        QualityStatus.GOOD
        if QUASI_STATIC_BAND[0] <= magnitude <= QUASI_STATIC_BAND[1]
        else QualityStatus.IMPLAUSIBLE
    )

    return {
        key: ChannelValue(
            value=round(value, 3),
            unit="deg",
            quality=quality,
            value_class=ValueClass.DERIVED,
        )
        for key, value in (
            ("incl_roll", roll),
            ("incl_pitch", pitch),
            ("incl_tilt", tilt),
        )
    }


def acceleration_magnitude(
    ax: float | None,
    ay: float | None,
    az: float | None,
) -> dict[str, ChannelValue]:
    """Vector magnitude of acceleration, in g.

    Worth having on its own: at rest it should read 1.000 g, so a persistent
    departure is direct evidence that the acceleration scaling is wrong or an
    axis has failed. It is how this unit's register map was confirmed in the
    first place.
    """
    if ax is None or ay is None or az is None:
        return {}

    magnitude = math.sqrt(ax * ax + ay * ay + az * az)

    return {
        "accel_magnitude": ChannelValue(
            value=round(magnitude, 5),
            unit="g",
            quality=QualityStatus.GOOD,
            value_class=ValueClass.DERIVED,
        )
    }


def derive_for_group(group_key: str, channels: dict[str, ChannelValue]) -> dict[str, ChannelValue]:
    """Extra channels for a decoded group, or an empty mapping.

    Driven by group key so the acquisition engine does not need to know what any
    particular derivation means.
    """
    if group_key != "motion":
        return {}

    ax = channels.get("accel_x")
    ay = channels.get("accel_y")
    az = channels.get("accel_z")
    if ax is None or ay is None or az is None:
        return {}

    # A derivation from a bad read is a bad derivation. Better to emit nothing
    # than an angle computed from a value the decoder already distrusts.
    if any(c.quality is QualityStatus.BAD for c in (ax, ay, az)):
        return {}

    derived = inclination(ax.value, ay.value, az.value)
    derived.update(acceleration_magnitude(ax.value, ay.value, az.value))

    return derived
