"""Unified measurement model.

Every sensor profile publishes the same normalised envelope, so nothing
downstream - ingestion, alarms, MQTT, the dashboard - needs to know which model
produced a reading. The envelope also carries the provenance needed to tell
native values apart from processed, derived, simulated and imported ones, which
is what stops a computed number from being mistaken for a measured one.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import UTC, datetime
from enum import StrEnum
from typing import Any

SCHEMA_VERSION = "1.0"


class QualityStatus(StrEnum):
    GOOD = "good"
    #: Decoded, but outside the profile's declared engineering range.
    IMPLAUSIBLE = "implausible"
    #: Older than the channel's staleness budget.
    STALE = "stale"
    #: No response, bad CRC, or an exception reply.
    BAD = "bad"


class ValueClass(StrEnum):
    NATIVE = "native"
    PROCESSED = "processed"
    DERIVED = "derived"
    SIMULATED = "simulated"
    IMPORTED = "imported"


@dataclass(frozen=True)
class ChannelValue:
    value: float | None
    unit: str
    quality: QualityStatus
    value_class: ValueClass = ValueClass.NATIVE
    raw: tuple[int, ...] = ()

    def as_dict(self) -> dict[str, Any]:
        return {
            "value": self.value,
            "unit": self.unit,
            "quality": str(self.quality),
            "class": str(self.value_class),
            "raw": list(self.raw),
        }


@dataclass
class Measurement:
    """One poll of one register group from one sensor."""

    appliance_id: str
    adapter_id: str
    bus_id: str
    sensor_id: str
    sensor_model: str
    profile_version: str
    slave_id: int
    group_key: str
    sequence: int
    timestamp_utc: datetime
    monotonic_ns: int
    channels: dict[str, ChannelValue] = field(default_factory=dict)
    status: QualityStatus = QualityStatus.GOOD
    crc_valid: bool = True
    stale: bool = False
    latency_ms: float = 0.0
    error: str | None = None
    simulated: bool = False

    @property
    def ok(self) -> bool:
        return self.status is not QualityStatus.BAD

    def values(self) -> dict[str, float | None]:
        return {key: channel.value for key, channel in self.channels.items()}

    def as_dict(self) -> dict[str, Any]:
        """Schema-versioned envelope, ready for ingestion or MQTT."""
        return {
            "schema_version": SCHEMA_VERSION,
            "appliance_id": self.appliance_id,
            "adapter_id": self.adapter_id,
            "bus_id": self.bus_id,
            "sensor_id": self.sensor_id,
            "sensor_model": self.sensor_model,
            "profile_version": self.profile_version,
            "slave_id": self.slave_id,
            "group": self.group_key,
            "sequence": self.sequence,
            "timestamp_utc": self.timestamp_utc.isoformat().replace("+00:00", "Z"),
            "measurements": {k: v.as_dict() for k, v in self.channels.items()},
            "quality": {
                "status": str(self.status),
                "crc_valid": self.crc_valid,
                "stale": self.stale,
                "latency_ms": round(self.latency_ms, 3),
                "error": self.error,
            },
            "simulated": self.simulated,
        }

    def idempotency_key(self) -> str:
        """Stable key so replayed batches cannot double-insert."""
        return f"{self.appliance_id}:{self.sensor_id}:{self.group_key}:{self.sequence}"


def utc_now() -> datetime:
    return datetime.now(UTC)
