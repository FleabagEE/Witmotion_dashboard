"""Read registers straight off the sensor and show what they contain.

This is the tool for answering "is 0x44 really frequency?" without guessing. It
reads a span, decodes every word several plausible ways at once, and marks which
ones the profile claims. Two register-map faults on this appliance were found
exactly this way and neither was visible from stored data:

  * 0x44-0x46 turned out to be dominant frequency, not a second velocity block;
  * velocity and displacement were unsigned, which only showed when a hard shake
    pushed a raw word past 32767 and the signed reading flipped negative.

``--watch`` is the mode that finds things. It re-reads continuously and marks
every register that moved since the last sweep, so moving the sensor and seeing
what changes tells you what a register is - far more directly than any table.

The port is exclusive. The acquisition service holds it while running, so stop it
first:

    sudo systemctl stop quakevault-acq
    sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/python \\
        -m qv_acq.probe --watch
    sudo systemctl start quakevault-acq
"""

from __future__ import annotations

import argparse
import sys
import time

from pymodbus.client import ModbusSerialClient

from .profiles import loader

DEFAULT_PORT = "/dev/quakevault-rs485-a"


def signed16(word: int) -> int:
    return word - 0x10000 if word & 0x8000 else word


def channel_map(model: str | None) -> dict[int, tuple[str, str, float, str]]:
    """Address -> (channel key, unit, scale, data type) from the profile."""
    if not model:
        return {}
    try:
        profile = loader.get(model)
    except Exception:  # noqa: BLE001 - an unknown model is not fatal here
        return {}

    return {
        channel.address: (channel.key, channel.unit, channel.scale, channel.data_type)
        for group in profile.register_groups
        for channel in group.channels
    }


def describe(word: int, mapped: tuple[str, str, float, str] | None) -> str:
    """What this word means, decoded every way worth considering."""
    if mapped is not None:
        key, unit, scale, data_type = mapped
        raw = word if data_type.startswith("u") else signed16(word)
        return f"{key:<22} {raw * scale:>12.4f} {unit:<6} [{data_type}]"

    if word == 0:
        return "unmapped, reads zero"

    # Unmapped and non-zero: show the candidates so a real quantity is not
    # dismissed just because nothing claims the address yet.
    s = signed16(word)
    return (
        f"unmapped   int16={s:<7} uint16={word:<6} "
        f"/100={s / 100:>9.2f}  /1000={s / 1000:>8.3f}  "
        f"/32768*180={s / 32768 * 180:>8.2f}deg  /32768*16={s / 32768 * 16:>7.3f}g"
    )


def read_span(client: ModbusSerialClient, slave: int, start: int, count: int) -> list[int] | None:
    # Modbus caps one read at 125 registers; longer spans are split.
    words: list[int] = []
    remaining = count
    address = start
    while remaining > 0:
        chunk = min(remaining, 125)
        response = client.read_holding_registers(address=address, count=chunk, device_id=slave)
        if response is None or response.isError():
            return None
        words.extend(response.registers)
        address += chunk
        remaining -= chunk
    return words


def sweep(client, slave, start, count, mapping, previous, show_all) -> list[int] | None:
    words = read_span(client, slave, start, count)
    if words is None:
        print("read failed - is the acquisition service still holding the port?")
        return None

    for i, word in enumerate(words):
        address = start + i
        mapped = mapping.get(address)
        changed = previous is not None and previous[i] != word

        # In watch mode the interesting registers are the ones that moved.
        if not show_all and previous is not None and not changed:
            continue

        marker = "*" if changed else " "
        delta = ""
        if changed and previous is not None:
            delta = f"  ({previous[i]:>6} -> {word:>6})"
        print(f"{marker} 0x{address:02X}  {word:>6}  {describe(word, mapped)}{delta}", flush=True)

    return words


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        prog="python -m qv_acq.probe",
        description="Read and decode sensor registers directly.",
        epilog="Stop quakevault-acq first: it holds the serial port exclusively.",
    )
    p.add_argument("--port", default=DEFAULT_PORT)
    p.add_argument("--baud", type=int, default=9600)
    p.add_argument("--slave", type=lambda v: int(v, 0), default=0x50)
    p.add_argument("--start", type=lambda v: int(v, 0), default=0x30,
                   help="first register, decimal or 0x hex (default 0x30)")
    p.add_argument("--count", type=int, default=64, help="registers to read (default 64)")
    p.add_argument("--model", default="WTVB01-485",
                   help="profile used to label known registers; '' to label none")
    p.add_argument("--watch", action="store_true",
                   help="re-read continuously and print only what changed")
    p.add_argument("--interval", type=float, default=0.5, help="seconds between sweeps in --watch")
    return p


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    mapping = channel_map(args.model or None)

    client = ModbusSerialClient(
        port=args.port, baudrate=args.baud, bytesize=8, parity="N", stopbits=1, timeout=1.0,
    )
    if not client.connect():
        print(f"cannot open {args.port}.")
        print("If the acquisition service is running it owns the port:")
        print("    sudo systemctl stop quakevault-acq")
        return 2

    end = args.start + args.count - 1
    print(f"{args.port} @ {args.baud} baud, slave 0x{args.slave:02X}, "
          f"registers 0x{args.start:02X}-0x{end:02X}", flush=True)
    if args.watch:
        print("watching - move, tilt or tap the sensor; changed registers are marked *")
        print("Ctrl-C to stop\n", flush=True)
    else:
        print()

    try:
        previous = sweep(client, args.slave, args.start, args.count, mapping, None, True)
        if previous is None:
            return 1

        while args.watch:
            time.sleep(args.interval)
            words = sweep(client, args.slave, args.start, args.count, mapping, previous, False)
            if words is None:
                return 1
            previous = words
    except KeyboardInterrupt:
        print("\nstopped")
    finally:
        client.close()

    return 0


if __name__ == "__main__":
    sys.exit(main())
