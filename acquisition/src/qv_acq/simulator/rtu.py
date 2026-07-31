"""Raw Modbus RTU frame handling for the simulator.

Frames are assembled by hand rather than delegated to a Modbus library. That is
deliberate: it lets the simulator emit *malformed* traffic - corrupted CRCs, short
byte counts, silence - which is exactly the behaviour the acquisition engine must
survive and which a well-behaved server library will never produce.

It also cross-checks our own CRC implementation, because the client parsing these
frames in the integration tests is a third-party library.
"""

from __future__ import annotations

import random
from dataclasses import dataclass

from ..crc import append_crc, verify
from .device import SimulatedDevice

READ_FUNCTIONS = (0x03, 0x04)

#: Modbus exception codes used by the simulator.
ILLEGAL_FUNCTION = 0x01
ILLEGAL_ADDRESS = 0x02
ILLEGAL_VALUE = 0x03

MAX_REGISTERS = 125


@dataclass
class FaultInjection:
    """Deterministic fault injection for hardware-in-the-loop style testing."""

    drop_probability: float = 0.0
    crc_error_probability: float = 0.0
    exception_probability: float = 0.0
    short_frame_probability: float = 0.0

    def any_enabled(self) -> bool:
        return any(
            (
                self.drop_probability,
                self.crc_error_probability,
                self.exception_probability,
                self.short_frame_probability,
            )
        )


def exception_response(slave: int, function: int, code: int) -> bytes:
    return append_crc(bytes([slave, function | 0x80, code]))


def build_read_response(slave: int, function: int, registers: list[int]) -> bytes:
    payload = bytearray([slave, function, 2 * len(registers)])
    for word in registers:
        payload += (word & 0xFFFF).to_bytes(2, "big")
    return append_crc(bytes(payload))


def corrupt_crc(frame: bytes) -> bytes:
    """Flip a bit in the CRC so the client must reject the frame."""
    corrupted = bytearray(frame)
    corrupted[-1] ^= 0x01
    return bytes(corrupted)


def handle_request(
    frame: bytes,
    devices: dict[int, SimulatedDevice],
    t: float,
    *,
    faults: FaultInjection | None = None,
    rng: random.Random | None = None,
) -> bytes | None:
    """Produce a response frame, or ``None`` when the device stays silent.

    Silence is a valid device behaviour and the engine must handle it: real slaves
    ignore frames with a bad CRC and frames addressed to another unit.
    """
    faults = faults or FaultInjection()
    rng = rng or random.Random(0)

    if len(frame) < 4 or not verify(frame):
        return None

    slave = frame[0]
    function = frame[1]

    if slave not in devices:
        return None

    if faults.drop_probability and rng.random() < faults.drop_probability:
        return None

    if faults.exception_probability and rng.random() < faults.exception_probability:
        return exception_response(slave, function, ILLEGAL_ADDRESS)

    if function not in READ_FUNCTIONS:
        # Writes are never simulated. A profile's protected commands require
        # step-up authentication and are out of scope for an unattended
        # simulator, which must not teach the engine that writes are routine.
        return exception_response(slave, function, ILLEGAL_FUNCTION)

    if len(frame) != 8:
        return exception_response(slave, function, ILLEGAL_VALUE)

    start = int.from_bytes(frame[2:4], "big")
    count = int.from_bytes(frame[4:6], "big")

    if count < 1 or count > MAX_REGISTERS:
        return exception_response(slave, function, ILLEGAL_VALUE)
    if start + count > 0x10000:
        return exception_response(slave, function, ILLEGAL_ADDRESS)

    registers = devices[slave].read(start, count, t)
    response = build_read_response(slave, function, registers)

    if faults.crc_error_probability and rng.random() < faults.crc_error_probability:
        return corrupt_crc(response)
    if faults.short_frame_probability and rng.random() < faults.short_frame_probability:
        return response[: len(response) // 2]

    return response
