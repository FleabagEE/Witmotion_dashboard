#!/usr/bin/env python3
"""Sample a register block repeatedly and report which registers are alive.

Commissioning tool. A single register dump is ambiguous: zeros may mean "this
device has no such channel" or "this channel is genuinely at rest". Watching the
same block over time separates the two, and the movement pattern identifies the
sensor far more reliably than a static snapshot.

Read-only: emits function code 0x03 and nothing else.

Usage:
    observe.py --port /dev/quakevault-rs485-a --slave 0x50 --start 0x30 \
        --count 33 --samples 40 --interval 0.1
"""

from __future__ import annotations

import argparse
import statistics
import sys
import time

from pymodbus.client import ModbusSerialClient


def signed(word: int) -> int:
    return word - 0x10000 if word & 0x8000 else word


def collect(client, slave: int, start: int, count: int, samples: int, interval: float):
    series: list[list[int]] = []
    failures = 0
    timestamps: list[float] = []

    for _ in range(samples):
        began = time.monotonic()
        try:
            response = client.read_holding_registers(address=start, count=count, device_id=slave)
        except Exception:  # noqa: BLE001
            response = None
        if response is None or response.isError():
            failures += 1
        else:
            series.append(list(response.registers))
            timestamps.append(began)
        time.sleep(interval)

    return series, failures, timestamps


def report(series: list[list[int]], start: int, timestamps: list[float]) -> None:
    if not series:
        print("no successful reads")
        return

    count = len(series[0])
    print(f"\n{len(series)} samples of {count} registers from 0x{start:04X}\n")
    print(f"{'addr':>6} {'first':>8} {'min':>8} {'max':>8} {'span':>8} {'stdev':>9}  state")
    print("-" * 70)

    live, static, zero = [], [], []
    for index in range(count):
        column = [sample[index] for sample in series]
        signed_column = [signed(word) for word in column]
        low, high = min(signed_column), max(signed_column)
        span = high - low
        stdev = statistics.pstdev(signed_column) if len(signed_column) > 1 else 0.0
        address = start + index

        if all(word == 0 for word in column):
            state, bucket = "always zero", zero
        elif span == 0:
            state, bucket = "static", static
        else:
            state, bucket = "LIVE", live
        bucket.append(address)

        print(
            f"0x{address:04X} {signed_column[0]:>8} {low:>8} {high:>8} {span:>8} {stdev:>9.2f}  {state}"
        )

    def fmt(addresses: list[int]) -> str:
        return ", ".join(f"0x{a:02X}" for a in addresses) or "none"

    print(f"\nLIVE (changing):  {fmt(live)}")
    print(f"static (nonzero): {fmt(static)}")
    print(f"always zero:      {fmt(zero)}")

    if len(timestamps) > 1:
        gaps = [b - a for a, b in zip(timestamps, timestamps[1:])]
        mean_gap = statistics.mean(gaps)
        print(
            f"\neffective sample rate: {1 / mean_gap:.2f} Hz "
            f"(jitter stdev {statistics.pstdev(gaps) * 1000:.2f} ms)"
        )

    print(
        "\nInterpretation: 'always zero' over a long window means the channel is "
        "unpopulated OR genuinely at rest - excite the sensor and re-run to tell "
        "them apart. 'LIVE' registers carry real measurements."
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", default="/dev/quakevault-rs485-a")
    parser.add_argument("--baud", type=int, default=9600)
    parser.add_argument("--slave", type=lambda v: int(v, 0), default=0x50)
    parser.add_argument("--start", type=lambda v: int(v, 0), default=0x30)
    parser.add_argument("--count", type=int, default=33)
    parser.add_argument("--samples", type=int, default=40)
    parser.add_argument("--interval", type=float, default=0.1)
    parser.add_argument("--timeout", type=float, default=0.5)
    args = parser.parse_args(argv)

    client = ModbusSerialClient(
        port=args.port, baudrate=args.baud, bytesize=8, parity="N", stopbits=1, timeout=args.timeout
    )
    if not client.connect():
        print(f"could not open {args.port}")
        return 1

    try:
        series, failures, timestamps = collect(
            client, args.slave, args.start, args.count, args.samples, args.interval
        )
    finally:
        client.close()

    print(f"reads: {len(series)} ok, {failures} failed")
    report(series, args.start, timestamps)
    return 0 if series else 1


if __name__ == "__main__":
    sys.exit(main())
