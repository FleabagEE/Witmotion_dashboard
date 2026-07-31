"""Appliance configuration.

One YAML file describes the whole acquisition service: which buses exist, which
sensors sit on them, and where state is written. Validation is strict and happens
at startup, because a configuration error that surfaces three hours into a soak
test is far more expensive than one that refuses to boot.
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any

import yaml
from pydantic import BaseModel, Field, field_validator, model_validator

from .engine import BreakerConfig, RetryConfig, SensorBinding
from .profiles import loader

DEFAULT_CONFIG_PATH = Path("/etc/quakevault/acquisition.yaml")


class SpoolConfig(BaseModel):
    path: Path = Path("/var/lib/quakevault-acq/spool.db")
    max_rows: int = Field(default=500_000, ge=1_000)
    drop_undelivered_when_full: bool = True


class MetricsConfig(BaseModel):
    #: Prometheus textfile-collector target. A file rather than an HTTP server:
    #: the acquisition service should not open a listening socket on an
    #: appliance, and node_exporter already solves scraping.
    path: Path = Path("/var/lib/quakevault-acq/metrics.prom")
    interval_seconds: float = Field(default=10.0, gt=0)
    enabled: bool = True


class ForwarderSettings(BaseModel):
    """How the spool reaches the ingestion API."""

    enabled: bool = True
    base_url: str = "http://127.0.0.1:8000/api/internal/v1/ingest"
    # The token is read from the environment, never from this file: the config is
    # world-readable and version-controlled, an appliance credential is neither.
    token_env: str = "QV_INGEST_TOKEN"
    batch_size: int = Field(default=200, ge=1, le=1000)
    interval_seconds: float = Field(default=5.0, gt=0)
    request_timeout: float = Field(default=20.0, gt=0)
    max_retries: int = Field(default=10, ge=1)
    verify_tls: bool = True
    ca_bundle: str | None = None
    announce_profiles: bool = True

    def token(self) -> str:
        return os.environ.get(self.token_env, "")


class SensorConfig(BaseModel):
    sensor_id: str = Field(min_length=1, max_length=80)
    model: str = Field(min_length=1)
    slave_id: int | None = Field(default=None, ge=1, le=247)
    groups: list[str] | None = None
    poll_hz: dict[str, float] = Field(default_factory=dict)

    @field_validator("poll_hz")
    @classmethod
    def rates_are_positive(cls, value: dict[str, float]) -> dict[str, float]:
        for key, rate in value.items():
            if rate <= 0:
                raise ValueError(f"poll_hz for {key} must be positive")
        return value

    def to_binding(self) -> SensorBinding:
        profile = loader.get(self.model)
        if not profile.is_trustworthy():
            # Refusing here rather than at first alarm: a profile that is not
            # verified must never reach production silently.
            raise ValueError(
                f"sensor {self.sensor_id}: profile {self.model} is "
                f"'{profile.verification_status}', not 'verified'. Verify the register map "
                "before deploying it (see docs/register-maps.md)."
            )
        return SensorBinding(
            sensor_id=self.sensor_id,
            profile=profile,
            slave_id=self.slave_id,
            groups=tuple(self.groups) if self.groups else None,
            poll_hz=dict(self.poll_hz),
        )


class BusConfig(BaseModel):
    bus_id: str = Field(min_length=1, max_length=80)
    adapter_id: str = Field(min_length=1, max_length=80)
    port: Path
    baud: int = 9600
    timeout: float = Field(default=1.0, gt=0)
    sensors: list[SensorConfig] = Field(min_length=1)
    retry_max_attempts: int = Field(default=2, ge=1, le=10)
    breaker_failure_threshold: int = Field(default=3, ge=1)
    breaker_cooldown_seconds: float = Field(default=5.0, gt=0)

    @model_validator(mode="after")
    def sensor_ids_and_slaves_are_unique(self) -> "BusConfig":
        ids = [s.sensor_id for s in self.sensors]
        if len(set(ids)) != len(ids):
            raise ValueError(f"bus {self.bus_id}: duplicate sensor_id")
        slaves = [s.slave_id for s in self.sensors if s.slave_id is not None]
        if len(set(slaves)) != len(slaves):
            # Two devices on one address collide on the wire; catching it in
            # config is far cheaper than diagnosing it as a wiring fault.
            raise ValueError(
                f"bus {self.bus_id}: duplicate slave_id. Two devices cannot share an "
                "address on one RS-485 bus."
            )
        return self

    def retry(self) -> RetryConfig:
        return RetryConfig(max_attempts=self.retry_max_attempts)

    def breaker(self) -> BreakerConfig:
        return BreakerConfig(
            failure_threshold=self.breaker_failure_threshold,
            cooldown_seconds=self.breaker_cooldown_seconds,
        )


class ApplianceConfig(BaseModel):
    appliance_id: str = Field(min_length=1, max_length=80)
    buses: list[BusConfig] = Field(min_length=1)
    spool: SpoolConfig = Field(default_factory=SpoolConfig)
    metrics: MetricsConfig = Field(default_factory=MetricsConfig)
    forwarder: ForwarderSettings = Field(default_factory=ForwarderSettings)
    simulated: bool = False

    @model_validator(mode="after")
    def buses_and_ports_are_unique(self) -> "ApplianceConfig":
        ids = [b.bus_id for b in self.buses]
        if len(set(ids)) != len(ids):
            raise ValueError("duplicate bus_id")
        ports = [str(b.port) for b in self.buses]
        if len(set(ports)) != len(ports):
            raise ValueError("two buses cannot share a serial port")
        return self

    @classmethod
    def load(cls, path: str | Path = DEFAULT_CONFIG_PATH) -> "ApplianceConfig":
        target = Path(path)
        if not target.is_file():
            raise FileNotFoundError(f"configuration not found: {target}")
        with target.open("r", encoding="utf-8") as handle:
            raw: Any = yaml.safe_load(handle)
        if not isinstance(raw, dict):
            raise ValueError(f"{target}: configuration must be a YAML mapping")
        return cls.model_validate(raw)

    def sensor_count(self) -> int:
        return sum(len(b.sensors) for b in self.buses)
