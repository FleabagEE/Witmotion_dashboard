"""The probe is how a register map gets checked, so it must not mislead.

Its whole job is to show what a word could mean before anyone commits to an
interpretation. Two register faults on this appliance were found with it; both
would have been missed by a tool that quietly applied one decoding and moved on.
"""

from __future__ import annotations

from qv_acq.probe import channel_map, describe, signed16


def test_signed_conversion_at_the_boundary() -> None:
    # The exact boundary that inverted vibration velocity for a whole session.
    assert signed16(32767) == 32767
    assert signed16(32768) == -32768
    assert signed16(33530) == -32006


def test_the_profile_labels_known_registers() -> None:
    mapping = channel_map("WTVB01-485")

    assert mapping[0x34][0] == "accel_x"
    assert mapping[0x3A][0] == "vib_velocity_x"
    assert mapping[0x44][0] == "vib_frequency_x"


def test_an_unknown_model_labels_nothing_rather_than_failing() -> None:
    # Probing a device with no profile yet is the normal case when identifying
    # one, and must still work.
    assert channel_map("NOT-A-REAL-SENSOR") == {}
    assert channel_map(None) == {}


def test_a_mapped_register_is_shown_in_engineering_units() -> None:
    line = describe(1971, ("accel_z", "g", 0.00048828125, "int16"))

    assert "accel_z" in line
    assert "0.9624" in line
    assert "[int16]" in line


def test_a_mapped_unsigned_register_does_not_go_negative() -> None:
    # 33530 counts is 335.30 mm/s. Read as signed it would be -320.06, which is
    # exactly the fault this tool is used to catch.
    line = describe(33530, ("vib_velocity_y", "mm/s", 0.01, "uint16"))

    assert "335.30" in line
    assert "-320" not in line


def test_an_unmapped_register_offers_every_candidate() -> None:
    # Nothing claims 0x37. The point is to show what it *might* be rather than
    # pick one, so a real quantity is not dismissed for lack of a label.
    line = describe(2185, None)

    assert "unmapped" in line
    assert "int16=" in line and "uint16=" in line
    assert "deg" in line and "g" in line


def test_a_zero_register_is_called_out_plainly() -> None:
    # A permanently-zero register is evidence in itself: it is how 0x3D-0x3F
    # were ruled out as the angle registers.
    assert describe(0, None) == "unmapped, reads zero"


def test_a_negative_candidate_is_shown_for_unmapped_words() -> None:
    line = describe(0xFFFF, None)

    assert "int16=-1" in line
    assert "uint16=65535" in line
