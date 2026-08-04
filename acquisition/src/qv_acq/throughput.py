"""Modbus RTU bus-capacity calculation.

The appliance must never advertise a sensor's marketing sample rate. Every
configured poll rate is checked against the physics of the bus, and the UI shows
the *measured* rate alongside the configured one.

Frame accounting for function 0x03 (read holding registers):

    request  = addr(1) + func(1) + start(2) + count(2) + crc(2)          = 8 bytes
    response = addr(1) + func(1) + bytecount(1) + 2*N data + crc(2) = 5 + 2N bytes

Modbus RTU requires >= 3.5 character times of idle line between frames. On top of
the wire time, two real-world costs dominate at higher baud rates:

  * device turnaround - time the sensor takes to assemble its reply;
  * USB-serial latency - the round trip through the host USB stack. Low-cost
    CH340 bridges are materially worse here than FTDI parts, whose latency timer
    is tunable.
"""

from __future__ import annotations

from collections.abc import Sequence
from dataclasses import dataclass


#: Bytes on the wire for a function 0x03 request.
REQUEST_BYTES = 8

#: Idle line required between frames, expressed in character times.
INTERFRAME_CHARS = 3.5

#: Conservative default turnaround for WitMotion RS-485 parts, in milliseconds.
#: Measured per device during commissioning and written back into the profile.
DEFAULT_TURNAROUND_MS = 5.0

#: Round-trip USB latency defaults by bridge chip, in milliseconds.
USB_LATENCY_MS = {
    "ch340": 4.0,
    "ftdi": 1.5,
    "cp210x": 2.0,
    "unknown": 4.0,
}


@dataclass(frozen=True)
class BusEstimate:
    """Result of a bus-capacity calculation for one polling cycle."""

    transaction_ms: float
    max_transactions_per_second: float
    sustainable_poll_hz: float
    max_resolvable_hz: float
    bus_utilisation: float

    def as_dict(self) -> dict[str, float]:
        return {
            "transaction_ms": round(self.transaction_ms, 3),
            "max_transactions_per_second": round(self.max_transactions_per_second, 2),
            "sustainable_poll_hz": round(self.sustainable_poll_hz, 2),
            "max_resolvable_hz": round(self.max_resolvable_hz, 2),
            "bus_utilisation": round(self.bus_utilisation, 4),
        }


def char_time_ms(baud: int, bits_per_char: int = 10) -> float:
    """Milliseconds to transmit one character at *baud* (8N1 => 10 bits)."""
    if baud <= 0:
        raise ValueError("baud must be positive")
    return (bits_per_char / baud) * 1000.0


def transaction_ms(
    baud: int,
    register_count: int,
    *,
    bits_per_char: int = 10,
    turnaround_ms: float = DEFAULT_TURNAROUND_MS,
    usb_latency_ms: float = USB_LATENCY_MS["unknown"],
) -> float:
    """Wall-clock milliseconds for one request/response exchange."""
    if register_count <= 0:
        raise ValueError("register_count must be positive")

    tc = char_time_ms(baud, bits_per_char)
    response_bytes = 5 + 2 * register_count
    wire = (REQUEST_BYTES + response_bytes) * tc
    idle = 2 * INTERFRAME_CHARS * tc
    return wire + idle + turnaround_ms + usb_latency_ms


def estimate(
    baud: int,
    register_count: int,
    *,
    sensors: int = 1,
    bits_per_char: int = 10,
    turnaround_ms: float = DEFAULT_TURNAROUND_MS,
    usb_latency_ms: float = USB_LATENCY_MS["unknown"],
    retry_overhead: float = 0.05,
    safety_margin: float = 0.20,
    usable_bandwidth_fraction: float = 0.40,
) -> BusEstimate:
    """Estimate sustainable polling for *sensors* sharing one RS-485 bus.

    ``retry_overhead`` reserves capacity for bounded retries; ``safety_margin``
    keeps the scheduler off the ceiling so jitter does not cascade into missed
    polls. ``usable_bandwidth_fraction`` converts a sample rate into the highest
    frequency we are willing to claim: Nyquist permits fs/2, but polled Modbus is
    not uniformly sampled, so we only advertise 0.4*fs and flag the jitter.
    """
    if sensors < 1:
        raise ValueError("sensors must be >= 1")

    single = transaction_ms(
        baud,
        register_count,
        bits_per_char=bits_per_char,
        turnaround_ms=turnaround_ms,
        usb_latency_ms=usb_latency_ms,
    )
    cycle = single * sensors * (1.0 + retry_overhead)
    max_tps = 1000.0 / single
    sustainable = (1000.0 / cycle) * (1.0 - safety_margin)

    return BusEstimate(
        transaction_ms=single,
        max_transactions_per_second=max_tps,
        sustainable_poll_hz=sustainable,
        max_resolvable_hz=sustainable * usable_bandwidth_fraction,
        bus_utilisation=min(1.0, cycle / 1000.0 * sustainable),
    )


def spectral_verdict(sustainable_poll_hz: float, requested_hz: float) -> tuple[bool, str]:
    """Decide whether spectral analysis up to *requested_hz* is defensible.

    Returns ``(allowed, explanation)``. Callers must surface the explanation in
    the UI rather than silently producing a spectrum the hardware cannot support.
    """
    usable = sustainable_poll_hz * 0.40
    if requested_hz <= usable:
        return True, (
            f"Requested {requested_hz:.1f} Hz is within the {usable:.1f} Hz "
            f"defensible band for a {sustainable_poll_hz:.1f} Hz polled channel."
        )
    if requested_hz <= sustainable_poll_hz / 2:
        return False, (
            f"Requested {requested_hz:.1f} Hz sits between the defensible band "
            f"({usable:.1f} Hz) and Nyquist ({sustainable_poll_hz / 2:.1f} Hz). "
            "Polled Modbus sampling is non-uniform; spectra in this range smear. "
            "Use a dedicated capture window on an otherwise idle bus."
        )
    return False, (
        f"Requested {requested_hz:.1f} Hz exceeds Nyquist "
        f"({sustainable_poll_hz / 2:.1f} Hz) for this channel. Aliasing would "
        "fabricate spectral content. Increase baud, reduce sensors per bus, or "
        "use a sensor with native spectral output."
    )


@dataclass(frozen=True)
class BusDemand:
    """What one bus is actually being asked to carry, across every group."""

    milliseconds_per_second: float
    utilisation: float
    per_group: tuple[tuple[str, float, float, float], ...]
    """(label, poll_hz, transaction_ms, milliseconds_per_second) per group."""

    @property
    def feasible(self) -> bool:
        """Below the ceiling the scheduler needs to hold a rate.

        Not 100%. A single-threaded bus at 75% utilisation with 69 ms
        transactions cannot absorb jitter: every late cycle eats into the next
        one, and the loop starts skipping beats rather than falling behind. The
        24-hour soak asked for 10 Hz at a modelled 75.2% and delivered 8.4.
        """
        return self.utilisation <= MAX_SUSTAINED_UTILISATION


#: Ceiling for the sum of every group's demand on one bus.
#:
#: Measured, not chosen: the appliance was configured for 75.2% and held 8.4 Hz
#: of a requested 10 - a 16% shortfall that persisted for a whole day without
#: degrading, because it was never a degradation. It simply never arrived.
MAX_SUSTAINED_UTILISATION = 0.65


def bus_demand(
    groups: Sequence[tuple[str, int, float]],
    baud: int,
    *,
    bits_per_char: int = 10,
    turnaround_ms: float = DEFAULT_TURNAROUND_MS,
    usb_latency_ms: float = USB_LATENCY_MS["unknown"],
) -> BusDemand:
    """Total demand on one bus from every (label, register_count, poll_hz).

    ``estimate`` answers "how fast could this one group go if it had the bus to
    itself", which is the question nobody was asking. A real appliance polls
    several groups on several sensors down one pair of wires, and the rate any
    one of them achieves depends on all the others. Summing them is the whole
    point, and nothing did it: the acceptance run was configured beyond what the
    bus could carry and `--check` reported OK.
    """
    rows = []
    total = 0.0

    for label, registers, hz in groups:
        if hz <= 0:
            continue
        single = transaction_ms(
            baud,
            registers,
            bits_per_char=bits_per_char,
            turnaround_ms=turnaround_ms,
            usb_latency_ms=usb_latency_ms,
        )
        demand = single * hz
        total += demand
        rows.append((label, hz, single, demand))

    return BusDemand(
        milliseconds_per_second=total,
        utilisation=total / 1000.0,
        per_group=tuple(rows),
    )
