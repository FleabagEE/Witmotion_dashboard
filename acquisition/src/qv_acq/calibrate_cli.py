"""Fit an accelerometer calibration for one physical unit.

Six positions, one per axis pointing up and down. In each, the sensor is held
still while a few seconds of readings are averaged; afterwards the six averages
are fitted to a unit sphere, which recovers the gain and offset of each axis.

Deliberately two steps rather than one interactive session. Capturing appends to
a file, so positions can be taken minutes apart, checked, and re-taken
individually without starting over - and the captures remain on disk as evidence
for what the fitted numbers were derived from.

    # for each of the six orientations:
    qv-calibrate capture --label z-up

    # then, once all six are taken:
    qv-calibrate solve

Nothing is applied until the solved file is installed at
/etc/quakevault/calibration.yaml and the service restarted.
"""

from __future__ import annotations

import argparse
import statistics
import sys
import time
from pathlib import Path

import yaml
from pymodbus.client import ModbusSerialClient

from .calibration import coverage, residual_error, solve
from .decode import decode
from .probe import DEFAULT_PORT

DEFAULT_CAPTURE = Path("/var/lib/quakevault-acq/calibration-captures.yaml")

#: The six orientations. Any six well-spread positions work, but these are easy
#: to hold accurately against a flat surface and cover every axis in both signs.
POSITIONS = ["z-up", "z-down", "x-up", "x-down", "y-up", "y-down"]

ACCEL_SCALE = 16.0 / 32768.0


def read_acceleration(client: ModbusSerialClient, slave: int) -> tuple[float, float, float] | None:
    response = client.read_holding_registers(address=0x34, count=3, device_id=slave)
    if response is None or response.isError():
        return None
    return tuple(decode([w], "int16", scale=ACCEL_SCALE) for w in response.registers)


def capture(args) -> int:
    client = ModbusSerialClient(
        port=args.port, baudrate=args.baud, bytesize=8, parity="N", stopbits=1, timeout=1.0,
    )
    if not client.connect():
        print(f"cannot open {args.port}. Stop quakevault-acq first: it owns the port.")
        return 2

    print(f"Position: '{args.label}'")

    # Wait for the sensor to settle before averaging anything.
    #
    # The device filters its acceleration output with a time constant near 9 s -
    # measured settling to 99% takes about 44 s after a re-orientation. Averaging
    # five seconds immediately after setting the sensor down would average a
    # decay curve, and the spread check does not reliably catch it: mid-settle
    # drift over five seconds is around 0.0095 g, just under the 0.01 g limit.
    # That would produce a calibration that looks clean and is wrong.
    if args.settle > 0:
        print(f"Settling for {args.settle:.0f} s (the sensor filters with a ~9 s "
              f"time constant)...")
        for remaining in range(int(args.settle), 0, -5):
            print(f"  {remaining:>3d} s", end="\r", flush=True)
            time.sleep(min(5, remaining))
        print("       ", end="\r")

    print(f"Averaging for {args.seconds:.0f} s - do not touch it...")

    samples: list[tuple[float, float, float]] = []
    deadline = time.monotonic() + args.seconds
    try:
        while time.monotonic() < deadline:
            reading = read_acceleration(client, args.slave)
            if reading is not None:
                samples.append(reading)
            time.sleep(0.1)
    finally:
        client.close()

    if len(samples) < 10:
        print(f"only {len(samples)} readings; is the sensor connected?")
        return 1

    mean = [statistics.fmean(s[i] for s in samples) for i in range(3)]
    spread = [statistics.pstdev(s[i] for s in samples) for i in range(3)]

    # A position captured while the sensor was still moving corrupts the fit
    # invisibly - the averages look reasonable and the gains come out wrong.
    if max(spread) > args.max_spread:
        print(f"readings varied by {max(spread):.4f} g, above the {args.max_spread} g limit.")
        print("The sensor was still moving. Let it settle and capture again.")
        return 1

    path = Path(args.file)
    path.parent.mkdir(parents=True, exist_ok=True)
    existing = yaml.safe_load(path.read_text()) if path.exists() else {}
    captures = (existing or {}).get("captures", {})
    captures[args.label] = {
        "x": round(mean[0], 6), "y": round(mean[1], 6), "z": round(mean[2], 6),
        "samples": len(samples), "max_spread_g": round(max(spread), 6),
    }
    path.write_text(yaml.safe_dump({"captures": captures}, sort_keys=True))

    magnitude = sum(v * v for v in mean) ** 0.5
    print(f"  x={mean[0]:+.4f}  y={mean[1]:+.4f}  z={mean[2]:+.4f}   |a|={magnitude:.4f} g")
    print(f"saved '{args.label}' to {path}")

    remaining = [p for p in POSITIONS if p not in captures]
    if remaining:
        print(f"still to capture: {', '.join(remaining)}")
    else:
        print("all six captured - run 'solve' next")

    return 0


def solve_command(args) -> int:
    path = Path(args.file)
    if not path.exists():
        print(f"no captures at {path}. Run 'capture' first.")
        return 2

    captures = (yaml.safe_load(path.read_text()) or {}).get("captures", {})
    if len(captures) < 6:
        print(f"only {len(captures)} position(s) captured; six are needed.")
        print(f"missing: {', '.join(p for p in POSITIONS if p not in captures)}")
        return 1

    positions = [(c["x"], c["y"], c["z"]) for c in captures.values()]

    span = coverage(positions)
    print(f"axis coverage: {span:.2f}")
    if span < args.min_coverage:
        # A fit from orientations that barely differ looks authoritative and
        # constrains almost nothing. Refusing is the only safe answer.
        print(f"below the {args.min_coverage} minimum: the positions are too alike to")
        print("constrain all three axes. Re-capture with the sensor genuinely turned")
        print("onto each face, not tilted slightly from one.")
        return 1

    axes = solve(positions)
    residual = residual_error(positions, axes)

    print(f"\nresidual after correction: {residual:.5f} g RMS")
    print("\nfitted:")
    for key, axis in axes.items():
        print(f"  {key}: gain {axis.gain:.5f}  offset {axis.offset:+.5f} g"
              f"   ({(axis.gain - 1) * 100:+.2f}%)")

    document = {
        "sensors": {
            args.sensor_id: {
                "note": f"six-position fit from {path}, residual {residual:.5f} g RMS, "
                        f"coverage {span:.2f}",
                "axes": {
                    key: {"gain": round(axis.gain, 6), "offset": round(axis.offset, 6)}
                    for key, axis in axes.items()
                },
            }
        }
    }

    out = Path(args.out)
    out.write_text(yaml.safe_dump(document, sort_keys=True))
    print(f"\nwritten to {out}")
    print("Nothing is applied until this is installed and the service restarted:")
    print(f"    sudo install -m 0644 {out} /etc/quakevault/calibration.yaml")
    print("    sudo systemctl restart quakevault-acq")

    return 0


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="qv-calibrate",
        description="Fit a six-position accelerometer calibration.",
    )
    sub = p.add_subparsers(dest="command", required=True)

    c = sub.add_parser("capture", help="average one still position")
    c.add_argument("--label", required=True, choices=POSITIONS)
    c.add_argument("--seconds", type=float, default=10.0,
                   help="averaging window once settled")
    c.add_argument("--settle", type=float, default=60.0,
                   help="wait this long after placing the sensor before averaging; "
                        "the device needs about 44 s to reach 99%% after a move")
    c.add_argument("--max-spread", type=float, default=0.01,
                   help="reject the capture if readings vary more than this (g)")
    c.add_argument("--port", default=DEFAULT_PORT)
    c.add_argument("--baud", type=int, default=9600)
    c.add_argument("--slave", type=lambda v: int(v, 0), default=0x50)
    c.add_argument("--file", default=str(DEFAULT_CAPTURE))
    c.set_defaults(func=capture)

    s = sub.add_parser("solve", help="fit gains and offsets from the captures")
    s.add_argument("--file", default=str(DEFAULT_CAPTURE))
    s.add_argument("--sensor-id", default="SENSOR-001")
    s.add_argument("--out", default="/tmp/quakevault-calibration.yaml")
    s.add_argument("--min-coverage", type=float, default=0.8)
    s.set_defaults(func=solve_command)

    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
