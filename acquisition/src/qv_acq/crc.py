"""Modbus RTU CRC-16 (polynomial 0xA001, little-endian on the wire)."""

from __future__ import annotations


def crc16(payload: bytes) -> int:
    """Return the Modbus RTU CRC-16 of *payload* as an integer."""
    crc = 0xFFFF
    for byte in payload:
        crc ^= byte
        for _ in range(8):
            if crc & 0x0001:
                crc = (crc >> 1) ^ 0xA001
            else:
                crc >>= 1
    return crc


def crc_bytes(payload: bytes) -> bytes:
    """Return the two CRC bytes appended to a Modbus RTU frame (low byte first)."""
    return crc16(payload).to_bytes(2, "little")


def append_crc(payload: bytes) -> bytes:
    return payload + crc_bytes(payload)


def verify(frame: bytes) -> bool:
    """Validate a complete RTU frame whose final two bytes are the CRC."""
    if len(frame) < 4:
        return False
    return crc16(frame[:-2]) == int.from_bytes(frame[-2:], "little")
