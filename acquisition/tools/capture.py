#!/usr/bin/env python3
"""Capture a register block during a physical stimulus and find what woke up.

Commissioning tool for the one question a passive dump cannot answer: which
registers are unpopulated, and which were merely idle. It records a quiet
baseline first, then keeps sampling while the operator excites the sensor, and
reports the registers that changed behaviour between the two.

Read-only: emits function code 0x03 and nothing else.

Usage:
    capture.py --slave 0x50 --start 0x30 --count 33 --baseline 10 --duration 90 \
        --out /var/lib/quakevault-acq/tap-test.csv
"""

from __future__ import annotations

import argparse
import csv
import statistics
import sys
import time
from dataclasses import dataclass

from pymodbus.client import ModbusSerialClient


def signed(word: int) -> int:
    return word - 0x10000 if word & 0x8000 else word


@dataclass
class Sample:
    t: float
    registers: list[int]


def capture(client, slave: int, start: int, count: int, duration: float) -> tuple[list[Sample], int]:
    samples: list[Sample] = []
    failures = 0
    began = time.monotonic()

    while time.monotonic() - began < duration:
        try:
            response = client.read_holding_registers(address=start, count=count, device_id=slave)
        except Exception:  # noqa: BLE001
            response = None
        if response is None or response.isError():
            failures += 1
            continue
        samples.append(Sample(time.monotonic() - began, list(response.registers)))

    return samples, failures


def write_csv(path: str, samples: list[Sample], start: int) -> None:
    if not samples:
        return
    count = len(samples[0].registers)
    with open(path, "w", newline="", encoding="utf-8") as handle:
        writer = csv.writer(handle)
        writer.writerow(["t_seconds"] + [f"0x{start + i:04X}" for i in range(count)])
        for sample in samples:
            writer.writerow([f"{sample.t:.4f}"] + [signed(w) for w in sample.registers])


def analyse(samples: list[Sample], start: int, baseline_seconds: float) -> None:
    if not samples:
        print("no samples captured")
        return

    count = len(samples[0].registers)
    baseline = [s for s in samples if s.t <= baseline_seconds]
    active = [s for s in samples if s.t > baseline_seconds]

    if not baseline or not active:
        print("insufficient samples to split baseline from stimulus window")
        return

    print(
        f"\n{len(samples)} samples over {samples[-1].t:.1f} s "
        f"({len(baseline)} baseline, {len(active)} stimulus)\n"
    )

    woke_up: list[tuple[int, float, float, float]] = []
    amplified: list[tuple[int, float, float]] = []
    header = f"{'addr':>6} {'base max':>10} {'base sd':>9} {'stim max':>10} {'stim sd':>9}  verdict"
    print(header)
    print("-" * len(header))

    for index in range(count):
        address = start + index
        base_vals = [signed(s.registers[index]) for s in baseline]
        stim_vals = [signed(s.registers[index]) for s in active]
        base_peak = max(abs(v) for v in base_vals)
        stim_peak = max(abs(v) for v in stim_vals)
        base_sd = statistics.pstdev(base_vals) if len(base_vals) > 1 else 0.0
        stim_sd = statistics.pstdev(stim_vals) if len(stim_vals) > 1 else 0.0

        if base_peak == 0 and stim_peak > 0:
            verdict = "*** WOKE UP ***"
            woke_up.append((address, stim_peak, base_sd, stim_sd))
        elif base_sd > 0 and stim_sd > base_sd * 3:
            verdict = "amplified"
            amplified.append((address, base_sd, stim_sd))
        elif stim_peak == 0:
            verdict = "still zero"
        else:
            verdict = ""

        print(
            f"0x{address:04X} {base_peak:>10} {base_sd:>9.2f} {stim_peak:>10} {stim_sd:>9.2f}  {verdict}"
        )

    print("\n=== RESULT ===")
    if woke_up:
        print("Registers that were zero at rest and carried data under stimulus:")
        for address, peak, _, sd in woke_up:
            print(f"  0x{address:04X}  peak {peak}, stimulus stdev {sd:.2f}")
        print("These are the vibration output channels.")
    else:
        print("No register moved from zero to non-zero.")
        print("Either the stimulus never reached the sensor, or the zero blocks")
        print("are genuinely unpopulated on this firmware.")

    if amplified:
        print("\nRegisters whose variability rose sharply (already live at rest):")
        for address, base_sd, stim_sd in amplified:
            print(f"  0x{address:04X}  stdev {base_sd:.2f} -> {stim_sd:.2f}")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", default="/dev/quakevault-rs485-a")
    parser.add_argument("--baud", type=int, default=9600)
    parser.add_argument("--slave", type=lambda v: int(v, 0), default=0x50)
    parser.add_argument("--start", type=lambda v: int(v, 0), default=0x30)
    parser.add_argument("--count", type=int, default=33)
    parser.add_argument("--baseline", type=float, default=10.0, help="quiet seconds at the start")
    parser.add_argument("--duration", type=float, default=90.0)
    parser.add_argument("--timeout", type=float, default=0.5)
    parser.add_argument("--out", default="")
    args = parser.parse_args(argv)

    client = ModbusSerialClient(
        port=args.port, baudrate=args.baud, bytesize=8, parity="N", stopbits=1, timeout=args.timeout
    )
    if not client.connect():
        print(f"could not open {args.port}")
        return 1

    print(f"capturing {args.duration:.0f} s from 0x{args.start:04X} (+{args.count} registers)")
    print(f"first {args.baseline:.0f} s treated as the quiet baseline")
    sys.stdout.flush()

    try:
        samples, failures = capture(client, args.slave, args.start, args.count, args.duration)
    finally:
        client.close()

    print(f"captured {len(samples)} samples, {failures} failed reads")
    if args.out:
        write_csv(args.out, samples, args.start)
        print(f"raw data written to {args.out}")

    analyse(samples, args.start, args.baseline)
    return 0 if samples else 1


if __name__ == "__main__":
    sys.exit(main())
