"""Calibration is a fitted model applied to measured data.

Two properties matter more than the arithmetic. It must do nothing at all unless
somebody chose to apply it, and a corrected reading must never look like a raw
one. A silent correction is indistinguishable from a wrong sensor.
"""

from __future__ import annotations

import math
import random

import pytest

from qv_acq.calibration import (
    IDENTITY,
    AxisCalibration,
    SensorCalibration,
    coverage,
    load,
    residual_error,
    solve,
)
from qv_acq.measurement import ChannelValue, QualityStatus, ValueClass

# This unit's actual fault, measured 2026-08-03: gravity read 0.9898 g with the
# vector on X and 0.9625 g with it on Z.
TRUE_GAIN = (0.990, 1.004, 0.962)
TRUE_OFFSET = (0.004, -0.002, 0.006)

SIX_POSITIONS = [(1, 0, 0), (-1, 0, 0), (0, 1, 0), (0, -1, 0), (0, 0, 1), (0, 0, -1)]


def measured(truth, noise=0.0005, seed=7):
    rng = random.Random(seed)
    return tuple(
        TRUE_GAIN[i] * truth[i] + TRUE_OFFSET[i] + rng.gauss(0, noise) for i in range(3)
    )


# --- doing nothing unless asked ---------------------------------------------

def test_no_calibration_file_means_no_calibration() -> None:
    assert load(__import__("pathlib").Path("/nonexistent/calibration.yaml")) == {}


def test_identity_leaves_a_reading_untouched() -> None:
    channels = {"accel_z": ChannelValue(0.9624, "g", QualityStatus.GOOD)}

    assert IDENTITY.apply(channels)["accel_z"].value == 0.9624


def test_an_identity_axis_does_not_reclassify_the_reading() -> None:
    # A gain of exactly 1.0 changes nothing, so the value is still what the
    # sensor said and must keep saying so.
    calibration = SensorCalibration("S1", {"accel_z": AxisCalibration(1.0, 0.0)})
    channels = {"accel_z": ChannelValue(0.9624, "g", QualityStatus.GOOD)}

    assert calibration.apply(channels)["accel_z"].value_class is ValueClass.NATIVE


def test_a_malformed_file_runs_uncalibrated_rather_than_failing(tmp_path) -> None:
    # Refusing to start over a bad calibration would take monitoring down for
    # the sake of a correction.
    path = tmp_path / "calibration.yaml"
    path.write_text("{{{ not yaml")

    assert load(path) == {}


# --- a corrected value says so ----------------------------------------------

def test_a_corrected_reading_is_marked_processed() -> None:
    calibration = SensorCalibration("S1", {"accel_z": AxisCalibration(0.962, 0.006)})
    channels = {"accel_z": ChannelValue(0.9624, "g", QualityStatus.GOOD)}

    result = calibration.apply(channels)["accel_z"]

    assert result.value_class is ValueClass.PROCESSED
    assert result.value == pytest.approx((0.9624 - 0.006) / 0.962)


def test_correction_preserves_quality_and_raw_words() -> None:
    calibration = SensorCalibration("S1", {"accel_z": AxisCalibration(0.962)})
    channels = {"accel_z": ChannelValue(0.5, "g", QualityStatus.IMPLAUSIBLE, raw=(1024,))}

    result = calibration.apply(channels)["accel_z"]

    # The raw word is the evidence; a correction must not erase it.
    assert result.raw == (1024,)
    assert result.quality is QualityStatus.IMPLAUSIBLE


def test_a_missing_reading_is_not_invented() -> None:
    calibration = SensorCalibration("S1", {"accel_z": AxisCalibration(0.962)})
    channels = {"accel_z": ChannelValue(None, "g", QualityStatus.BAD)}

    assert calibration.apply(channels)["accel_z"].value is None


# --- the fit ----------------------------------------------------------------

def test_it_recovers_the_gains_it_was_given() -> None:
    positions = [measured(t, seed=i) for i, t in enumerate(SIX_POSITIONS)]
    axes = solve(positions)

    for i, key in enumerate(("accel_x", "accel_y", "accel_z")):
        assert axes[key].gain == pytest.approx(TRUE_GAIN[i], abs=0.002)


def test_the_fit_removes_the_error_it_was_fitted_to() -> None:
    positions = [measured(t, seed=i) for i, t in enumerate(SIX_POSITIONS)]
    axes = solve(positions)

    # Before: gravity reads between 0.962 and 1.004 depending on orientation.
    before = max(abs(math.sqrt(sum(v * v for v in p)) - 1.0) for p in positions)
    assert before > 0.01

    assert residual_error(positions, axes) < 0.001


def test_too_few_positions_is_refused() -> None:
    with pytest.raises(ValueError, match="at least 6"):
        solve([(1, 0, 0), (0, 1, 0), (0, 0, 1)])


# --- refusing a fit that would look authoritative and mean nothing ----------

def test_six_well_spread_positions_have_full_coverage() -> None:
    assert coverage([measured(t, seed=i) for i, t in enumerate(SIX_POSITIONS)]) > 0.9


def test_six_nearly_identical_positions_have_none() -> None:
    # The dangerous case: six captures all taken flat. The solver would return
    # confident numbers constraining only one axis.
    flat = [measured((0.02 * i, 0.01 * i, 1.0), seed=i) for i in range(6)]

    assert coverage(flat) < 0.1


def test_coverage_is_limited_by_the_worst_axis() -> None:
    # X and Y fully turned over, Z never. One unconstrained axis is enough to
    # make the whole calibration untrustworthy.
    partial = [(1, 0, 0), (-1, 0, 0), (0, 1, 0), (0, -1, 0), (0.7, 0.7, 0), (-0.7, -0.7, 0)]

    assert coverage(partial) < 0.1
