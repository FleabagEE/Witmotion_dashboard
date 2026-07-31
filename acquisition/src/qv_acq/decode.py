"""Register decoding: raw Modbus words to engineering units.

Kept deliberately free of I/O so every decoding rule is unit-testable against
fixtures without hardware or a simulator.
"""

from __future__ import annotations

import struct
from typing import Sequence

DataType = str

#: Registers consumed by each supported data type.
WORD_COUNT: dict[DataType, int] = {
    "int16": 1,
    "uint16": 1,
    "int32": 2,
    "uint32": 2,
    "float32": 2,
}


class DecodeError(ValueError):
    """Raised when a register window cannot be decoded as requested."""


def _words_to_bytes(words: Sequence[int], word_order: str) -> bytes:
    ordered = list(words) if word_order == "big" else list(reversed(words))
    out = bytearray()
    for word in ordered:
        if not 0 <= word <= 0xFFFF:
            raise DecodeError(f"register value {word} outside 16-bit range")
        out += word.to_bytes(2, "big")
    return bytes(out)


def decode_raw(
    words: Sequence[int],
    data_type: DataType,
    *,
    word_order: str = "big",
) -> int | float:
    """Decode *words* into a raw numeric value, before scaling.

    ``word_order`` controls 32-bit assembly only: ``big`` places the high-order
    word first (the WitMotion and general Modbus convention), ``little`` swaps
    them for devices that publish CDAB ordering.
    """
    if data_type not in WORD_COUNT:
        raise DecodeError(f"unsupported data type {data_type!r}")
    if word_order not in ("big", "little"):
        raise DecodeError(f"unsupported word order {word_order!r}")

    expected = WORD_COUNT[data_type]
    if len(words) != expected:
        raise DecodeError(
            f"{data_type} needs {expected} register(s), received {len(words)}"
        )

    if data_type == "uint16":
        return words[0]
    if data_type == "int16":
        # Signed 16-bit two's complement. WitMotion returns signed values for
        # acceleration, angular velocity, angle, magnetic field and temperature.
        return words[0] - 0x10000 if words[0] & 0x8000 else words[0]

    payload = _words_to_bytes(words, word_order)
    if data_type == "int32":
        return int.from_bytes(payload, "big", signed=True)
    if data_type == "uint32":
        return int.from_bytes(payload, "big", signed=False)
    return struct.unpack(">f", payload)[0]


def apply_scaling(raw: int | float, *, scale: float = 1.0, offset: float = 0.0) -> float:
    """Convert a raw value to engineering units: ``raw * scale + offset``."""
    return raw * scale + offset


def full_scale_factor(full_scale: float, counts: int = 32768) -> float:
    """Scale factor for WitMotion's ``raw / counts * full_scale`` convention.

    Example: +/-16 g acceleration over a signed 16-bit range is
    ``full_scale_factor(16.0)`` == 16/32768.
    """
    if counts <= 0:
        raise DecodeError("counts must be positive")
    return full_scale / counts


def decode(
    words: Sequence[int],
    data_type: DataType,
    *,
    word_order: str = "big",
    scale: float = 1.0,
    offset: float = 0.0,
) -> float:
    """Decode and scale in one step, returning engineering units."""
    return apply_scaling(
        decode_raw(words, data_type, word_order=word_order),
        scale=scale,
        offset=offset,
    )


def plausible(
    value: float,
    *,
    minimum: float | None = None,
    maximum: float | None = None,
) -> bool:
    """Range check used for discovery confidence and quality flagging.

    A decoded value outside the profile's declared engineering range is strong
    evidence that the register map, byte order, or slave identity is wrong.
    """
    if minimum is not None and value < minimum:
        return False
    if maximum is not None and value > maximum:
        return False
    return True
