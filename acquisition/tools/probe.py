#!/usr/bin/env python3
"""Read-only Modbus RTU probe used to verify register maps on real hardware.

This tool is the gate between a `candidate` profile and a `verified` one. It is
deliberately incapable of writing: no function code other than 0x03/0x04 is ever
emitted, so it cannot save configuration, change a slave address or baud rate, or
trigger calibration.

Usage:
    probe.py --port /dev/ttyUSB0 --baud 9600 --slave 0x50 --start 0x30 --count 33
    probe.py --port /dev/ttyUSB0 --scan-baud --scan-slave 1-32
"""

from __future__ import annotations

import argparse
import sys
import time
from dataclasses import dataclass

from pymodbus.client import ModbusSerialClient

SUPPORTED_BAUDS = (4800, 9600, 19200, 38400, 57600, 115200, 230400)

#: Interpretations printed for each register so an operator can recognise a real
#: measurement. Temperature is the fastest confirmation: near ambient it lands in
#: a narrow, unmistakable band.
SCALINGS = {
    "raw_u16": lambda r: r,
    "raw_i16": lambda r: r - 0x10000 if r & 0x8000 else r,
    "/100 (degC)": lambda r: (r - 0x10000 if r & 0x8000 else r) / 100.0,
    "x16/32768 (g)": lambda r: (r - 0x10000 if r & 0x8000 else r) * 16 / 32768,
    "x2000/32768 (deg/s)": lambda r: (r - 0x10000 if r & 0x8000 else r) * 2000 / 32768,
    "x180/32768 (deg)": lambda r: (r - 0x10000 if r & 0x8000 else r) * 180 / 32768,
}


@dataclass
class ProbeResult:
    slave: int
    baud: int
    start: int
    registers: list[int]


def connect(port: str, baud: int, timeout: float) -> ModbusSerialClient:
    client = ModbusSerialClient(
        port=port, baudrate=baud, bytesize=8, parity="N", stopbits=1, timeout=timeout
    )
    if not client.connect():
        raise SystemExit(f"could not open {port} at {baud} baud")
    return client


def read_block(client: ModbusSerialClient, slave: int, start: int, count: int):
    """Read holding registers, returning None on any error or exception reply."""
    try:
        response = client.read_holding_registers(address=start, count=count, device_id=slave)
    except Exception:  # noqa: BLE001 - probing is best-effort by design
        return None
    if response is None or response.isError():
        return None
    return list(response.registers)


def scan(port: str, bauds, slaves, timeout: float) -> list[ProbeResult]:
    """Find responding devices. Read-only, bounded, and safe to repeat."""
    found: list[ProbeResult] = []
    for baud in bauds:
        client = connect(port, baud, timeout)
        try:
            for slave in slaves:
                registers = read_block(client, slave, 0x34, 4)
                if registers is None:
                    continue
                # Require a repeated consistent answer before claiming a hit, so
                # line noise cannot be mistaken for a device.
                time.sleep(0.05)
                if read_block(client, slave, 0x34, 4) is None:
                    continue
                print(f"  responder: slave 0x{slave:02X} ({slave}) at {baud} baud")
                found.append(ProbeResult(slave, baud, 0x34, registers))
        finally:
            client.close()
    return found


def dump(result: ProbeResult) -> None:
    print(f"\nslave 0x{result.slave:02X} at {result.baud} baud, from 0x{result.start:02X}\n")
    header = f"{'addr':>6} {'raw':>7} " + " ".join(f"{name:>21}" for name in SCALINGS)
    print(header)
    print("-" * len(header))
    for index, raw in enumerate(result.registers):
        address = result.start + index
        cells = " ".join(f"{fn(raw):>21.4f}" for fn in SCALINGS.values())
        print(f"0x{address:04X} {raw:>7} {cells}")
    print(
        "\nConfirm temperature first: a register reading near ambient under the "
        "'/100 (degC)' column identifies the block. Record this transcript in "
        "docs/register-maps.md before promoting a profile to verified."
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", default="/dev/ttyUSB0")
    parser.add_argument("--baud", type=int, default=9600)
    parser.add_argument("--slave", type=lambda v: int(v, 0), default=0x50)
    parser.add_argument("--start", type=lambda v: int(v, 0), default=0x30)
    parser.add_argument("--count", type=int, default=33)
    parser.add_argument("--timeout", type=float, default=1.0)
    parser.add_argument("--scan-baud", action="store_true")
    parser.add_argument("--scan-slave", default="", help="inclusive range, e.g. 1-32")
    args = parser.parse_args(argv)

    if args.count < 1 or args.count > 125:
        parser.error("count must be between 1 and 125 (Modbus limit)")

    if args.scan_baud or args.scan_slave:
        bauds = SUPPORTED_BAUDS if args.scan_baud else (args.baud,)
        if args.scan_slave:
            low, _, high = args.scan_slave.partition("-")
            slaves = range(int(low, 0), int(high or low, 0) + 1)
        else:
            slaves = (args.slave,)
        print(f"scanning {args.port}: {len(bauds)} baud rate(s), {len(list(slaves))} address(es)")
        results = scan(args.port, bauds, slaves, args.timeout)
        if not results:
            print("no responders found")
            return 1
        for result in results:
            client = connect(args.port, result.baud, args.timeout)
            try:
                registers = read_block(client, result.slave, args.start, args.count)
            finally:
                client.close()
            if registers:
                dump(ProbeResult(result.slave, result.baud, args.start, registers))
        return 0

    client = connect(args.port, args.baud, args.timeout)
    try:
        registers = read_block(client, args.slave, args.start, args.count)
    finally:
        client.close()

    if registers is None:
        print(
            f"no response from slave 0x{args.slave:02X} at {args.baud} baud.\n"
            "Try --scan-baud --scan-slave 1-32, and check A/B polarity, "
            "120 ohm termination, and common ground."
        )
        return 1

    dump(ProbeResult(args.slave, args.baud, args.start, registers))
    return 0


if __name__ == "__main__":
    sys.exit(main())
