"""Change a WTVB01-485's Modbus address.

Every unit ships as 0x50. Two of them on one bus answer simultaneously and
corrupt each other's replies, which reads as noise rather than as a fault - so
each sensor must be readdressed **alone** before any of them share wires.

This writes register 0x1A (manual 10.4.3) and then the save command at 0x00
(10.4.1). Both are marked ``requires_step_up`` in the profile, because a wrong
address makes a device look absent.

WHAT MAKES THIS SAFE ENOUGH TO AUTOMATE
---------------------------------------

The dangerous version of this tool writes an address and reports success. This
one refuses to write until it has established that exactly one device is
listening, and refuses to report success until it has found the device again at
its new address.

    1. Sweep the address space. More than one responder and it stops: with two
       devices at 0x50 there is no way to address one of them, and writing would
       change both or neither.
    2. Refuse if anything already answers at the target address.
    3. Write, save, wait for the reboot.
    4. Read back at the new address. If that fails, sweep again and say where
       the device actually is.

Step 4 is the one that matters. A device that moved somewhere unexpected is
recoverable - it is still on the bus and a sweep finds it - but only if somebody
is told. Reporting success without checking is how a sensor becomes "faulty".
"""

from __future__ import annotations

import argparse
import sys
import time

from pymodbus.client import ModbusSerialClient

from .probe import DEFAULT_PORT

#: Manual 10.4.3. Holding register carrying the device's own Modbus address.
ADDRESS_REGISTER = 0x1A

#: Manual 10.4.1. Writing zero here saves configuration and reboots.
SAVE_REGISTER = 0x00
SAVE_VALUE = 0x00

#: Modbus RTU permits 1-247. 0 is the broadcast address and must never be set.
MIN_ADDRESS = 1
MAX_ADDRESS = 247

#: The unit reboots after saving. Measured at well under two seconds; three is
#: slack so a slow reboot is not mistaken for a lost device.
REBOOT_SECONDS = 3.0


def responds(client: ModbusSerialClient, slave: int) -> bool:
    """Whether anything answers at this address."""
    try:
        response = client.read_holding_registers(0x34, count=1, slave=slave)
    except Exception:  # noqa: BLE001
        return False

    return response is not None and not response.isError()


def sweep(client: ModbusSerialClient, lo: int = MIN_ADDRESS, hi: int = MAX_ADDRESS,
          *, quiet: bool = False) -> list[int]:
    """Every address that answers.

    Slow by design: one transaction per address at 9600 baud. It runs only when
    something needs establishing, never as part of the happy path.
    """
    found = []

    for slave in range(lo, hi + 1):
        if responds(client, slave):
            found.append(slave)
            if not quiet:
                print(f"    found a device at 0x{slave:02X}")

    return found


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        prog="qv-set-address",
        description="Change one WTVB01-485's Modbus address. One sensor on the bus, alone.",
    )
    parser.add_argument("--to", required=True, type=lambda v: int(v, 0),
                        help="new address, 1-247, e.g. 0x51")
    parser.add_argument("--from", dest="current", type=lambda v: int(v, 0),
                        help="current address; found by sweeping if omitted")
    parser.add_argument("--port", default=DEFAULT_PORT)
    parser.add_argument("--baud", type=int, default=9600)
    parser.add_argument("--timeout", type=float, default=1.0)
    parser.add_argument("--yes", action="store_true",
                        help="skip the confirmation prompt")
    args = parser.parse_args(argv)

    target = args.to

    if not MIN_ADDRESS <= target <= MAX_ADDRESS:
        print(f"Address must be {MIN_ADDRESS}-{MAX_ADDRESS} (0 is broadcast).", file=sys.stderr)
        return 2

    client = ModbusSerialClient(port=args.port, baudrate=args.baud, timeout=args.timeout)

    if not client.connect():
        print(f"Could not open {args.port} at {args.baud} baud.", file=sys.stderr)
        print("  Is the acquisition service still holding it?", file=sys.stderr)
        print("    sudo systemctl stop quakevault-acq", file=sys.stderr)
        return 2

    try:
        # 1. Establish that exactly one device is listening.
        print(f"{args.port} @ {args.baud} baud")
        print("  sweeping for devices...")
        present = sweep(client)

        if not present:
            print("\nNothing answered. Check wiring, power and A/B polarity.", file=sys.stderr)
            return 1

        if len(present) > 1:
            addresses = ", ".join(f"0x{a:02X}" for a in present)
            print(f"\n{len(present)} devices are on this bus ({addresses}).", file=sys.stderr)
            print("Readdressing needs one at a time: with two at the same address",
                  file=sys.stderr)
            print("there is no way to speak to one of them alone.", file=sys.stderr)
            return 1

        current = present[0]

        if args.current is not None and args.current != current:
            print(f"\nExpected a device at 0x{args.current:02X}, found 0x{current:02X}.",
                  file=sys.stderr)
            return 1

        if current == target:
            print(f"\nAlready at 0x{target:02X}. Nothing to do.")
            return 0

        # 2. Refuse to create a collision.
        if responds(client, target):
            print(f"\nSomething already answers at 0x{target:02X}. Choose another address.",
                  file=sys.stderr)
            return 1

        print(f"\n  one device, at 0x{current:02X}")
        print(f"  will become            0x{target:02X}")

        if not args.yes:
            reply = input("\nProceed? [y/N] ").strip().lower()
            if reply != "y":
                print("Nothing was written.")
                return 0

        # 3. Write and save.
        print("\n  writing address...")
        response = client.write_register(ADDRESS_REGISTER, target, slave=current)

        if response is None or response.isError():
            print(f"Write refused: {response}", file=sys.stderr)
            return 1

        print("  saving and rebooting...")
        try:
            client.write_register(SAVE_REGISTER, SAVE_VALUE, slave=current)
        except Exception:  # noqa: BLE001
            # The device may reboot mid-transaction and never answer. That is
            # not a failure; the read-back decides.
            pass

        time.sleep(REBOOT_SECONDS)

        # 4. Prove it, or say where the device went.
        if responds(client, target):
            print(f"\n  confirmed at 0x{target:02X}")
            return 0

        print(f"\nNo response at 0x{target:02X} after the write. Sweeping to locate it...",
              file=sys.stderr)
        found = sweep(client)

        if found:
            addresses = ", ".join(f"0x{a:02X}" for a in found)
            print(f"The device is at {addresses}. It is not lost - readdress from there.",
                  file=sys.stderr)
        else:
            print("Nothing answers anywhere. Power-cycle the sensor and sweep again:",
                  file=sys.stderr)
            print("  qv-set-address --to 0x51   (it sweeps before doing anything)",
                  file=sys.stderr)

        return 1

    finally:
        client.close()


if __name__ == "__main__":
    sys.exit(main())
