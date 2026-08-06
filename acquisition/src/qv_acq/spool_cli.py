"""Inspect and repair the local spool.

The spool is the appliance's promise that an outage costs latency and not data.
It kept that promise on 2026-08-06 — a sixteen-hour database outage, 187,671
readings held on disk, none lost — and then the appliance had no way to tell
anybody so, and no way to act on the part that needed a decision.

Two gaps this closes:

  * `qv-spool status` answers "did we lose anything", which previously required
    someone to open the SQLite file by hand and know the schema.
  * `qv-spool retry-dead-letters` recovers readings parked by the retry ceiling.
    A long outage burns the retry budget of everything spooled during it, so
    healthy records get filed alongside genuinely poisonous ones. The appliance
    counted them in a metric and offered nothing that could act on the number.

Read-only unless explicitly asked to write, and never automatic: an operator
deciding to retry is what separates recovering from an outage from papering
over a real fault.
"""

from __future__ import annotations

import argparse
import logging
import sys
from pathlib import Path

from .config import DEFAULT_CONFIG_PATH, ApplianceConfig
from .spool import Spool

log = logging.getLogger("qv_acq.spool_cli")


def _human(n: int) -> str:
    return f"{n:,}"


def status(spool: Spool, max_retries: int) -> int:
    stats = spool.stats()
    dead = spool.dead_letters(max_retries)
    counters = stats["counters"]
    dropped = counters.get("undelivered_dropped", 0)

    print()
    print(f"Spool  {stats['path']}")
    print(f"  size                {stats['size_bytes'] / 1e9:.2f} GB")
    print(f"  total records       {_human(stats['total'])}")
    print(f"  delivered           {_human(stats['delivered'])}")
    print(f"  awaiting delivery   {_human(stats['backlog'])}")
    print(f"  of which parked     {_human(dead)}  (retry_count >= {max_retries})")
    print()

    # The only number that means lost data. Everything else is latency.
    if dropped:
        print(f"  LOST                {_human(dropped)} undelivered records dropped at the cap")
        print("                      These are gone. The spool filled faster than it drained.")
    else:
        print("  lost                none")
    print()

    if dead:
        print(f"{_human(dead)} record(s) are parked past the retry ceiling.")
        print("If they were stranded by an outage that is now over, recover them with:")
        print()
        print("    qv-spool retry-dead-letters --confirm")
        print()

    return 0


def retry_dead_letters(spool: Spool, max_retries: int, *, confirm: bool, limit: int | None) -> int:
    dead = spool.dead_letters(max_retries)

    if not dead:
        print("No parked records. Nothing to do.")
        return 0

    if not confirm:
        # A dry run by default. This writes to the queue the live forwarder is
        # reading, and "I did not realise it would actually do it" is not a
        # thing an appliance should ever make possible.
        print(f"{_human(dead)} record(s) would be returned to the queue.")
        print("Nothing changed. Re-run with --confirm to do it.")
        return 0

    revived = spool.revive_dead_letters(max_retries, limit=limit)
    print(f"Returned {_human(revived)} record(s) to the queue.")
    print("The forwarder will pick them up on its next cycle; watch with:")
    print()
    print("    systemctl status quakevault-forwarder")
    print()
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Inspect and repair the QuakeVault spool")
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG_PATH)
    parser.add_argument("--log-level", default="WARNING")

    sub = parser.add_subparsers(dest="command")
    sub.add_parser("status", help="what is in the spool, and whether anything was lost")

    retry = sub.add_parser("retry-dead-letters", help="return parked records to the queue")
    retry.add_argument("--confirm", action="store_true", help="actually do it")
    retry.add_argument("--limit", type=int, default=None, help="recover at most this many")

    args = parser.parse_args(argv)

    logging.basicConfig(
        level=getattr(logging, args.log_level.upper(), logging.WARNING),
        format="%(levelname)s %(message)s",
    )

    try:
        config = ApplianceConfig.load(args.config)
    except Exception as exc:  # noqa: BLE001
        log.error("configuration error: %s", exc)
        return 2

    max_retries = config.forwarder.max_retries

    with Spool(
        config.spool.path,
        max_rows=config.spool.max_rows,
        drop_undelivered_when_full=config.spool.drop_undelivered_when_full,
    ) as spool:
        if args.command == "retry-dead-letters":
            return retry_dead_letters(
                spool, max_retries, confirm=args.confirm, limit=args.limit
            )

        return status(spool, max_retries)


if __name__ == "__main__":
    sys.exit(main())
