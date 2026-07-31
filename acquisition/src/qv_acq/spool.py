"""Durable local spool.

Acquisition must keep running when Redis, the database, MQTT or the network are
unavailable. Measurements are written here first and forwarded afterwards, so a
downstream outage costs latency rather than data.

Design choices that matter:

  * SQLite in WAL mode with ``synchronous=FULL``. A `kill -9` or power cut
    between transactions loses only the uncommitted transaction; everything
    already committed survives, and there is a test that pulls the plug to prove
    it rather than asserting it.
  * Every row carries a SHA-256 of its payload, so silent corruption is
    detectable instead of being forwarded as though it were good data.
  * ``idempotency_key`` is UNIQUE. Replay after a crash re-offers records that
    may already have been delivered; the constraint makes that a no-op.
  * Disk usage is bounded. When the cap is reached the oldest *delivered* rows
    go first; only if that is not enough are undelivered rows dropped, and every
    such drop is counted and logged rather than passing silently.
"""

from __future__ import annotations

import hashlib
import json
import sqlite3
from dataclasses import dataclass
from datetime import UTC, datetime
from pathlib import Path
from types import TracebackType
from typing import Any, Iterable, Sequence

from .measurement import Measurement

SCHEMA = """
CREATE TABLE IF NOT EXISTS spool (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    idempotency_key  TEXT    NOT NULL UNIQUE,
    appliance_id     TEXT    NOT NULL,
    run_id           TEXT    NOT NULL DEFAULT '',
    sensor_id        TEXT    NOT NULL,
    group_key        TEXT    NOT NULL,
    sequence         INTEGER NOT NULL,
    timestamp_utc    TEXT    NOT NULL,
    payload          TEXT    NOT NULL,
    checksum         TEXT    NOT NULL,
    retry_count      INTEGER NOT NULL DEFAULT 0,
    last_error       TEXT,
    created_at       TEXT    NOT NULL,
    delivered_at     TEXT
);
CREATE INDEX IF NOT EXISTS spool_undelivered ON spool (delivered_at, id);
CREATE INDEX IF NOT EXISTS spool_sensor ON spool (sensor_id, group_key, sequence);

CREATE TABLE IF NOT EXISTS spool_counters (
    name  TEXT PRIMARY KEY,
    value INTEGER NOT NULL DEFAULT 0
);
"""


def checksum_of(payload: str) -> str:
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def canonical(envelope: dict[str, Any]) -> str:
    return json.dumps(envelope, sort_keys=True, separators=(",", ":"), default=str)


@dataclass(frozen=True)
class SpooledRecord:
    id: int
    idempotency_key: str
    sensor_id: str
    group_key: str
    sequence: int
    payload: dict[str, Any]
    retry_count: int

    @property
    def envelope(self) -> dict[str, Any]:
        return self.payload


class SpoolCorruption(RuntimeError):
    """Raised when a stored row fails its checksum."""


class Spool:
    """Append-and-forward buffer backed by SQLite."""

    def __init__(
        self,
        path: str | Path,
        *,
        max_rows: int = 500_000,
        drop_undelivered_when_full: bool = True,
    ) -> None:
        self.path = Path(path)
        self.max_rows = max_rows
        self.drop_undelivered_when_full = drop_undelivered_when_full
        self.path.parent.mkdir(parents=True, exist_ok=True)
        self._db = sqlite3.connect(self.path, isolation_level=None, timeout=10.0)
        self._db.row_factory = sqlite3.Row
        self._db.execute("PRAGMA journal_mode=WAL")
        # Durability over throughput: this buffer exists precisely for the case
        # where the machine dies unexpectedly.
        self._db.execute("PRAGMA synchronous=FULL")
        self._db.executescript(SCHEMA)
        self._migrate()

    def _migrate(self) -> None:
        """Additive migrations for spools created by an earlier version."""
        columns = {row["name"] for row in self._db.execute("PRAGMA table_info(spool)")}
        if "run_id" not in columns:
            self._db.execute("ALTER TABLE spool ADD COLUMN run_id TEXT NOT NULL DEFAULT ''")

    # -- writing ------------------------------------------------------------

    def append(self, measurement: Measurement) -> bool:
        """Store one measurement. Returns False if it was already spooled."""
        envelope = measurement.as_dict()
        payload = canonical(envelope)
        try:
            self._db.execute(
                """
                INSERT INTO spool (idempotency_key, appliance_id, run_id, sensor_id, group_key,
                                   sequence, timestamp_utc, payload, checksum, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    measurement.idempotency_key(),
                    measurement.appliance_id,
                    measurement.run_id,
                    measurement.sensor_id,
                    measurement.group_key,
                    measurement.sequence,
                    envelope["timestamp_utc"],
                    payload,
                    checksum_of(payload),
                    datetime.now(UTC).isoformat(),
                ),
            )
        except sqlite3.IntegrityError:
            self._bump("duplicates_rejected")
            return False
        self._enforce_cap()
        return True

    def _enforce_cap(self) -> None:
        total = self.total()
        if total <= self.max_rows:
            return
        excess = total - self.max_rows

        cursor = self._db.execute(
            "DELETE FROM spool WHERE id IN "
            "(SELECT id FROM spool WHERE delivered_at IS NOT NULL ORDER BY id LIMIT ?)",
            (excess,),
        )
        removed = cursor.rowcount or 0
        self._bump("delivered_pruned", removed)

        remaining = excess - removed
        if remaining > 0 and self.drop_undelivered_when_full:
            cursor = self._db.execute(
                "DELETE FROM spool WHERE id IN "
                "(SELECT id FROM spool WHERE delivered_at IS NULL ORDER BY id LIMIT ?)",
                (remaining,),
            )
            # Never silent: a dropped undelivered record is lost data and the
            # operator must be able to see that it happened.
            self._bump("undelivered_dropped", cursor.rowcount or 0)

    # -- reading ------------------------------------------------------------

    def pending(self, limit: int = 100, *, max_retries: int | None = None) -> list[SpooledRecord]:
        """Oldest-first batch awaiting delivery. Ordered replay, always."""
        sql = "SELECT * FROM spool WHERE delivered_at IS NULL"
        params: list[Any] = []
        if max_retries is not None:
            sql += " AND retry_count < ?"
            params.append(max_retries)
        sql += " ORDER BY id LIMIT ?"
        params.append(limit)

        records = []
        for row in self._db.execute(sql, params):
            if checksum_of(row["payload"]) != row["checksum"]:
                self._bump("corrupt_rows")
                raise SpoolCorruption(
                    f"spool row {row['id']} ({row['idempotency_key']}) failed its checksum"
                )
            records.append(
                SpooledRecord(
                    id=row["id"],
                    idempotency_key=row["idempotency_key"],
                    sensor_id=row["sensor_id"],
                    group_key=row["group_key"],
                    sequence=row["sequence"],
                    payload=json.loads(row["payload"]),
                    retry_count=row["retry_count"],
                )
            )
        return records

    # -- delivery outcomes --------------------------------------------------

    def mark_delivered(self, ids: Sequence[int]) -> int:
        if not ids:
            return 0
        now = datetime.now(UTC).isoformat()
        cursor = self._db.execute(
            f"UPDATE spool SET delivered_at = ? WHERE id IN ({','.join('?' * len(ids))})",
            [now, *ids],
        )
        return cursor.rowcount or 0

    def mark_failed(self, ids: Sequence[int], error: str | None = None) -> int:
        if not ids:
            return 0
        cursor = self._db.execute(
            "UPDATE spool SET retry_count = retry_count + 1, last_error = ? "
            f"WHERE id IN ({','.join('?' * len(ids))})",
            [error, *ids],
        )
        return cursor.rowcount or 0

    # -- introspection ------------------------------------------------------

    def backlog(self) -> int:
        return int(self._db.execute("SELECT COUNT(*) FROM spool WHERE delivered_at IS NULL").fetchone()[0])

    def total(self) -> int:
        return int(self._db.execute("SELECT COUNT(*) FROM spool").fetchone()[0])

    def delivered(self) -> int:
        return int(self._db.execute("SELECT COUNT(*) FROM spool WHERE delivered_at IS NOT NULL").fetchone()[0])

    def dead_letters(self, max_retries: int) -> int:
        return int(
            self._db.execute(
                "SELECT COUNT(*) FROM spool WHERE delivered_at IS NULL AND retry_count >= ?",
                (max_retries,),
            ).fetchone()[0]
        )

    def verify(self) -> list[int]:
        """Full checksum sweep. Returns the ids of corrupted rows."""
        bad = [
            row["id"]
            for row in self._db.execute("SELECT id, payload, checksum FROM spool")
            if checksum_of(row["payload"]) != row["checksum"]
        ]
        if bad:
            self._bump("corrupt_rows", len(bad))
        return bad

    def purge_delivered(self, keep_last: int = 0) -> int:
        cursor = self._db.execute(
            "DELETE FROM spool WHERE delivered_at IS NOT NULL AND id NOT IN "
            "(SELECT id FROM spool WHERE delivered_at IS NOT NULL ORDER BY id DESC LIMIT ?)",
            (keep_last,),
        )
        return cursor.rowcount or 0

    def counters(self) -> dict[str, int]:
        return {row["name"]: row["value"] for row in self._db.execute("SELECT * FROM spool_counters")}

    def stats(self) -> dict[str, Any]:
        return {
            "path": str(self.path),
            "total": self.total(),
            "backlog": self.backlog(),
            "delivered": self.delivered(),
            "size_bytes": self.path.stat().st_size if self.path.exists() else 0,
            "counters": self.counters(),
        }

    def _bump(self, name: str, amount: int = 1) -> None:
        if amount <= 0:
            return
        self._db.execute(
            "INSERT INTO spool_counters (name, value) VALUES (?, ?) "
            "ON CONFLICT(name) DO UPDATE SET value = value + excluded.value",
            (name, amount),
        )

    # -- lifecycle ----------------------------------------------------------

    def close(self) -> None:
        self._db.close()

    def __enter__(self) -> "Spool":
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        self.close()


class SpoolingSink:
    """Engine sink that writes straight to the spool.

    Deliberately the only thing between the engine and disk: the engine must
    never block on the network, so forwarding is a separate concern.
    """

    def __init__(self, spool: Spool) -> None:
        self.spool = spool
        self.accepted = 0
        self.duplicates = 0

    def __call__(self, measurement: Measurement) -> None:
        if self.spool.append(measurement):
            self.accepted += 1
        else:
            self.duplicates += 1


def replay(
    spool: Spool,
    deliver: "callable[[list[SpooledRecord]], bool]",
    *,
    batch_size: int = 100,
    max_retries: int = 10,
    max_batches: int | None = None,
) -> dict[str, int]:
    """Drain the spool in order, stopping at the first batch that fails.

    Ordering is preserved on purpose. Skipping a failed batch to make progress
    would reorder measurements, and downstream aggregates assume time order.
    """
    delivered = failed = batches = 0
    while True:
        if max_batches is not None and batches >= max_batches:
            break
        records = spool.pending(batch_size, max_retries=max_retries)
        if not records:
            break
        batches += 1
        ids = [r.id for r in records]
        if deliver(records):
            delivered += spool.mark_delivered(ids)
        else:
            failed += spool.mark_failed(ids, "delivery failed")
            break
    return {
        "delivered": delivered,
        "failed": failed,
        "batches": batches,
        "backlog": spool.backlog(),
    }
