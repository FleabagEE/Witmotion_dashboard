"""Units in the register map, checked against what the quantity can be.

These are transcribed by hand from a vendor table, and a wrong unit does not
announce itself: the number decodes, the chart draws, and nothing fails. The
whole profile carried `unit: g` on its shape descriptors - peak factor,
kurtosis, skewness - which are ratios of moments and have no units at all. The
clearest one was "X velocity kurtosis" in g: a statistic of a velocity, labelled
with an acceleration.

It reached a page reading "0.000 g" beside a name that cannot be measured in g,
and stayed there because nothing in the suite had an opinion about units.
"""

from __future__ import annotations

import pathlib

import pytest
import yaml

PROFILE = pathlib.Path(__file__).resolve().parents[2] / "profiles" / "wtvb01-485.v1.yaml"


def channels() -> list[dict]:
    document = yaml.safe_load(PROFILE.read_text())
    return [c for group in document["register_groups"] for c in group["channels"]]


# Ratios of moments, or of one amplitude to another. Dimensionless by
# construction, whatever the underlying series was measured in.
DIMENSIONLESS = ("cf_", "kurtosis_", "peak_index_", "skewness_",
                 "waveform_index_", "pulse_coefficient_", "velocity_kurtosis_")


@pytest.mark.parametrize("channel", channels(), ids=lambda c: c["key"])
def test_shape_descriptors_carry_no_unit(channel: dict) -> None:
    if not channel["key"].startswith(DIMENSIONLESS):
        pytest.skip("not a shape descriptor")

    assert channel["unit"] == "1", (
        f"{channel['key']} ({channel['label']}) is a ratio and cannot be "
        f"measured in {channel['unit']!r}"
    )


@pytest.mark.parametrize("channel", channels(), ids=lambda c: c["key"])
def test_a_variance_is_squared(channel: dict) -> None:
    if not channel["key"].startswith("variance_"):
        pytest.skip("not a variance")

    # Variance is the mean squared deviation, so its unit is the square of the
    # series'. Labelling it g makes it look comparable to an RMS, which it is
    # not - the two differ by a squaring, not a constant.
    assert channel["unit"] == "g^2", (
        f"{channel['key']} is a variance of an acceleration and must be g^2, "
        f"not {channel['unit']!r}"
    )


def test_the_label_and_the_unit_agree_about_the_quantity() -> None:
    """A velocity statistic must not be labelled with an acceleration unit."""
    wrong = []
    for channel in channels():
        label = channel["label"].lower()
        unit = channel["unit"]

        if "velocity" in label and unit not in ("mm/s", "1"):
            wrong.append(f"{channel['key']}: {channel['label']!r} in {unit!r}")
        if "displacement" in label and unit not in ("um", "mm", "1"):
            wrong.append(f"{channel['key']}: {channel['label']!r} in {unit!r}")

    assert not wrong, "label and unit disagree:\n  " + "\n  ".join(wrong)
