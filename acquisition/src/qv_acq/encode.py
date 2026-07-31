"""Engineering units to raw Modbus words: the exact inverse of :mod:`decode`.

Used by the simulator to synthesise register banks, and by the (step-up gated)
configuration writer. Keeping it in the same shape as the decoder means a
round-trip test can prove the two agree, which is what stops a scaling bug from
appearing only on real hardware.
"""

from __future__ import annotations

import struct
from typing import Sequence

from .decode import WORD_COUNT, DecodeError

#: Inclusive representable range for each integer data type.
LIMITS: dict[str, tuple[int, int]] = {
    "int16": (-0x8000, 0x7FFF),
    "uint16": (0, 0xFFFF),
    "int32": (-0x80000000, 0x7FFFFFFF),
    "uint32": (0, 0xFFFFFFFF),
}


def _bytes_to_words(payload: bytes, word_order: str) -> list[int]:
    words = [int.from_bytes(payload[i : i + 2], "big") for i in range(0, len(payload), 2)]
    return words if word_order == "big" else list(reversed(words))


def encode_raw(
    raw: int | float,
    data_type: str,
    *,
    word_order: str = "big",
    clamp: bool = True,
) -> list[int]:
    """Encode an already-unscaled value into Modbus registers."""
    if data_type not in WORD_COUNT:
        raise DecodeError(f"unsupported data type {data_type!r}")
    if word_order not in ("big", "little"):
        raise DecodeError(f"unsupported word order {word_order!r}")

    if data_type == "float32":
        return _bytes_to_words(struct.pack(">f", float(raw)), word_order)

    value = int(round(raw))
    low, high = LIMITS[data_type]
    if value < low or value > high:
        if not clamp:
            raise DecodeError(f"{value} is outside the range of {data_type}")
        value = max(low, min(high, value))

    if data_type == "int16":
        return [value & 0xFFFF]
    if data_type == "uint16":
        return [value]

    signed = data_type == "int32"
    payload = value.to_bytes(4, "big", signed=signed)
    return _bytes_to_words(payload, word_order)


def encode(
    value: float,
    data_type: str,
    *,
    word_order: str = "big",
    scale: float = 1.0,
    offset: float = 0.0,
    clamp: bool = True,
) -> list[int]:
    """Encode an engineering value into registers, reversing scale and offset."""
    if scale == 0:
        raise DecodeError("scale must be non-zero")
    return encode_raw(
        (value - offset) / scale,
        data_type,
        word_order=word_order,
        clamp=clamp,
    )


def quantisation_step(scale: float) -> float:
    """Smallest representable change in engineering units for a given scale.

    Round-trip comparisons must tolerate half of this: encoding rounds to the
    nearest count, so a value can never survive better than +/- scale/2.
    """
    return abs(scale)


def pack_group(values: Sequence[tuple[int, list[int]]], start: int, count: int) -> list[int]:
    """Lay channel words into a contiguous register window.

    ``values`` is a sequence of ``(address, words)``. Registers not covered by any
    channel are returned as zero, matching devices that leave gaps reserved.
    """
    bank = [0] * count
    for address, words in values:
        for index, word in enumerate(words):
            position = address + index - start
            if 0 <= position < count:
                bank[position] = word
    return bank
