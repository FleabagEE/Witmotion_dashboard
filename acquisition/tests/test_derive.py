"""Inclination is derived, and must say when it cannot be trusted.

A single accelerometer cannot tell tilting from accelerating: both change the
measured vector and nothing in the reading distinguishes them. The derivation is
exact while the sensor is stationary and meaningless while it is not, so most of
what matters here is that it reports which case it is in.
"""

from __future__ import annotations

import math

import pytest

from qv_acq.derive import derive_for_group, inclination
from qv_acq.measurement import ChannelValue, QualityStatus, ValueClass


def values(result):
    return {key: channel.value for key, channel in result.items()}


# --- the geometry -----------------------------------------------------------

def test_a_level_sensor_reads_no_tilt() -> None:
    result = values(inclination(0.0, 0.0, 1.0))

    assert result["incl_roll"] == pytest.approx(0.0)
    assert result["incl_pitch"] == pytest.approx(0.0)
    assert result["incl_tilt"] == pytest.approx(0.0)


def test_lying_on_its_side_is_ninety_degrees() -> None:
    # Gravity entirely on X: pitched fully over.
    result = values(inclination(1.0, 0.0, 0.0))

    assert result["incl_pitch"] == pytest.approx(-90.0)
    assert result["incl_tilt"] == pytest.approx(90.0)


def test_roll_and_pitch_are_about_different_axes() -> None:
    rolled = values(inclination(0.0, 1.0, 0.0))
    pitched = values(inclination(1.0, 0.0, 0.0))

    assert rolled["incl_roll"] == pytest.approx(90.0)
    assert pitched["incl_roll"] == pytest.approx(0.0)
    assert pitched["incl_pitch"] == pytest.approx(-90.0)


def test_inverted_is_a_hundred_and_eighty_degrees() -> None:
    # Upside down. acos must not be tripped by rounding past -1.
    assert values(inclination(0.0, 0.0, -1.0))["incl_tilt"] == pytest.approx(180.0)


def test_the_real_wall_orientation() -> None:
    # The reading recorded while the unit sat on the bench at 2026-08-03 16:22.
    result = values(inclination(0.850, 0.005, 0.490))

    assert result["incl_tilt"] == pytest.approx(60.0, abs=0.1)


def test_tilt_is_independent_of_direction() -> None:
    # Leaning the same amount two different ways is the same tilt.
    a = values(inclination(0.5, 0.0, math.sqrt(0.75)))["incl_tilt"]
    b = values(inclination(0.0, 0.5, math.sqrt(0.75)))["incl_tilt"]

    assert a == pytest.approx(b)


# --- knowing when it is meaningless ----------------------------------------

def test_a_stationary_sensor_is_good_quality() -> None:
    result = inclination(0.0, 0.0, 1.0)

    assert all(c.quality is QualityStatus.GOOD for c in result.values())


def test_acceleration_beyond_gravity_is_flagged() -> None:
    # 2.1 g total: the sensor is being moved, so these angles describe the
    # motion, not the mounting. They are still returned - the numbers are real -
    # but nothing may read them as orientation.
    result = inclination(2.0, 0.5, 0.5)

    assert all(c.quality is QualityStatus.IMPLAUSIBLE for c in result.values())


def test_free_fall_produces_no_angle_at_all() -> None:
    # Near-zero magnitude: the vector has no direction to report and the
    # arithmetic would amplify noise into a confident answer.
    assert inclination(0.001, 0.0, 0.001) == {}


def test_a_partial_vector_is_refused() -> None:
    # Substituting zero for a missing axis would invent an orientation.
    assert inclination(0.5, None, 0.5) == {}


def test_yaw_is_never_reported() -> None:
    # Rotation about gravity does not change the gravity vector, so it is not
    # observable from an accelerometer. Reporting it would be fabrication.
    assert "incl_yaw" not in inclination(0.1, 0.2, 0.97)


# --- provenance and wiring --------------------------------------------------

def test_derived_values_are_marked_as_derived() -> None:
    for channel in inclination(0.0, 0.0, 1.0).values():
        assert channel.value_class is ValueClass.DERIVED
        assert channel.unit == "deg"


def test_magnitude_confirms_the_acceleration_scaling() -> None:
    # A stationary sensor must measure exactly gravity. A persistent departure
    # is direct evidence the scale factor is wrong or an axis has died.
    result = derive_for_group("motion", {
        "accel_x": ChannelValue(0.207, "g", QualityStatus.GOOD),
        "accel_y": ChannelValue(0.005, "g", QualityStatus.GOOD),
        "accel_z": ChannelValue(0.971, "g", QualityStatus.GOOD),
    })

    assert result["accel_magnitude"].value == pytest.approx(0.993, abs=0.001)


def test_only_the_acceleration_group_derives_anything() -> None:
    assert derive_for_group("vibration_velocity", {
        "vib_velocity_x": ChannelValue(1.0, "mm/s", QualityStatus.GOOD),
    }) == {}


def test_a_bad_read_derives_nothing() -> None:
    # An angle computed from a value the decoder already distrusts would look
    # exactly as authoritative as one computed from a good read.
    assert derive_for_group("motion", {
        "accel_x": ChannelValue(None, "g", QualityStatus.BAD),
        "accel_y": ChannelValue(0.0, "g", QualityStatus.GOOD),
        "accel_z": ChannelValue(1.0, "g", QualityStatus.GOOD),
    }) == {}
