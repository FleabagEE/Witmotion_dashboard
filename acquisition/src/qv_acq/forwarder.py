"""Drains the local spool into the ingestion API.

Runs as its own process, separate from acquisition. That separation is the point:
a hung HTTP request, an expired token or a database outage must never stall the
serial polling loop. Acquisition writes to disk; this moves it onward.

Uses only the standard library. The appliance ships a Python environment we
control, and one fewer dependency in the path between a sensor and its data is
worth more than a nicer HTTP client.
"""

from __future__ import annotations

import json
import logging
import ssl
import time
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from typing import Callable, Protocol, Sequence

from .profiles.schema import SensorProfile
from .spool import Spool, SpooledRecord

log = logging.getLogger("qv_acq.forwarder")


class Transport(Protocol):
    """Injected so tests exercise the forwarder without a network."""

    def post(self, url: str, payload: dict, *, token: str, timeout: float) -> tuple[int, dict]:
        ...


class HttpTransport:
    def __init__(self, *, verify_tls: bool = True, ca_bundle: str | None = None) -> None:
        self.verify_tls = verify_tls
        self.ca_bundle = ca_bundle

    def post(self, url: str, payload: dict, *, token: str, timeout: float) -> tuple[int, dict]:
        body = json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            url,
            data=body,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                "Authorization": f"Bearer {token}",
            },
        )
        context = None
        if url.startswith("https"):
            context = ssl.create_default_context(cafile=self.ca_bundle)
            if not self.verify_tls:
                # Only for a bench appliance with a self-signed certificate; the
                # installer never enables this by default.
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
        try:
            with urllib.request.urlopen(request, timeout=timeout, context=context) as response:
                return response.status, json.loads(response.read() or b"{}")
        except urllib.error.HTTPError as exc:
            try:
                detail = json.loads(exc.read() or b"{}")
            except Exception:  # noqa: BLE001
                detail = {}
            return exc.code, detail


@dataclass
class ForwarderConfig:
    base_url: str = "http://127.0.0.1:8000/api/internal/v1/ingest"
    token: str = ""
    batch_size: int = 200
    interval_seconds: float = 5.0
    request_timeout: float = 20.0
    max_retries: int = 10
    verify_tls: bool = True
    ca_bundle: str | None = None
    backoff_seconds: float = 2.0
    backoff_max_seconds: float = 300.0

    def endpoint(self, name: str) -> str:
        return f"{self.base_url.rstrip('/')}/{name}"


@dataclass
class ForwardResult:
    batches: int = 0
    delivered: int = 0
    duplicates: int = 0
    rejected: int = 0
    failures: int = 0
    stopped_reason: str = ""
    fatal: bool = False


class Forwarder:
    def __init__(
        self,
        spool: Spool,
        config: ForwarderConfig,
        *,
        transport: Transport | None = None,
        sleeper: Callable[[float], None] = time.sleep,
    ) -> None:
        self.spool = spool
        self.config = config
        self.transport = transport or HttpTransport(
            verify_tls=config.verify_tls, ca_bundle=config.ca_bundle
        )
        self._sleep = sleeper
        self._consecutive_failures = 0

    # -- profile announcement ------------------------------------------------

    def announce_profile(
        self, appliance_id: str, sensor_id: str, profile: SensorProfile, slave_id: int
    ) -> bool:
        """Publish a sensor's register map and decoding provenance.

        Sent before measurements so channels arrive with their quantity, unit and
        scale rather than being provisioned as 'unknown' from a reading.
        """
        payload = {
            "appliance_id": appliance_id,
            "sensor_id": sensor_id,
            "sensor_model": profile.model,
            "manufacturer": profile.manufacturer,
            "protocol": profile.protocol,
            "profile_version": profile.profile_version,
            "verification_status": profile.verification_status,
            "slave_id": slave_id,
            "capabilities": sorted(profile.capabilities()),
            "limitations": list(profile.limitations),
            "channels": [
                {
                    "channel_key": channel.key,
                    "group_key": group.key,
                    "label": channel.label,
                    "quantity": channel.quantity,
                    "unit": channel.unit,
                    "value_class": channel.value_class,
                    "register_address": channel.address,
                    "data_type": channel.data_type,
                    "scale": channel.scale,
                    "offset": channel.offset,
                    "range_min": channel.minimum,
                    "range_max": channel.maximum,
                    "configured_hz": group.default_poll_hz,
                }
                for group in profile.register_groups
                for channel in group.channels
            ],
        }
        status, body = self.transport.post(
            self.config.endpoint("profile"),
            payload,
            token=self.config.token,
            timeout=self.config.request_timeout,
        )
        if status == 200:
            log.info(
                "announced %s (%s, %s) with %d channels",
                sensor_id, profile.model, profile.verification_status, body.get("channels", 0),
            )
            return True
        log.warning("profile announcement for %s failed: HTTP %s %s", sensor_id, status, body)
        return False

    # -- forwarding ----------------------------------------------------------

    def _deliver(self, records: Sequence[SpooledRecord]) -> tuple[bool, dict]:
        payload = {
            "batch_uid": records[0].idempotency_key[:64],
            "measurements": [record.payload for record in records],
        }
        status, body = self.transport.post(
            self.config.endpoint("batch"),
            payload,
            token=self.config.token,
            timeout=self.config.request_timeout,
        )
        # 2xx means the batch is durably recorded. Duplicates are a success:
        # they mean a previous attempt landed before we could mark it delivered.
        return 200 <= status < 300, {"status": status, **body}

    def drain_once(self, on_batch: Callable[[ForwardResult], None] | None = None) -> ForwardResult:
        """Deliver everything currently spooled.

        `on_batch` is called after each successful batch, and exists because
        this loop can legitimately run for a very long time. After a 16-hour
        outage the spool held 84,000 records; at roughly a second per batch of
        200 that is seven minutes of honest work inside one call.

        Nothing observed that. The systemd watchdog was pinged by the caller
        once per drain, so it fired at two minutes and SIGABRTed a forwarder
        that was recovering correctly — every two minutes, for as long as the
        backlog lasted. Progress survived, because delivery is committed per
        batch, but the service looked like a crash loop and its metrics never
        updated: the operator saw `delivered 0` and a frozen backlog while
        59,000 rows a minute were going in.

        The outage is exactly when this must not happen. A spool that survives
        a failure and then trips the watchdog while draining has moved the
        outage rather than absorbed it.
        """
        result = ForwardResult()

        while True:
            records = self.spool.pending(
                self.config.batch_size, max_retries=self.config.max_retries
            )
            if not records:
                break

            ok, detail = self._deliver(records)
            status = detail.get("status", 0)

            if ok:
                ids = [record.id for record in records]
                self.spool.mark_delivered(ids)
                result.batches += 1
                result.delivered += detail.get("accepted", len(records))
                result.duplicates += detail.get("duplicates", 0)
                result.rejected += detail.get("rejected", 0)
                self._consecutive_failures = 0
                if detail.get("rejected"):
                    # Rejected envelopes will never be accepted on retry, so they
                    # are logged rather than left to cycle in the spool forever.
                    log.warning(
                        "%d envelope(s) rejected by the API: %s",
                        detail["rejected"], detail.get("errors", [])[:3],
                    )
                if on_batch is not None:
                    on_batch(result)
                continue

            self.spool.mark_failed([r.id for r in records], f"HTTP {status}")
            result.failures += 1
            self._consecutive_failures += 1

            if status in (401, 403):
                # Credentials will not fix themselves. Stop rather than burn the
                # retry budget and push good measurements toward dead-letter.
                result.stopped_reason = f"authentication failed (HTTP {status})"
                result.fatal = True
                log.error("%s - check the appliance token", result.stopped_reason)
            else:
                result.stopped_reason = f"delivery failed (HTTP {status})"
                log.warning("%s; %d record(s) remain queued", result.stopped_reason, self.spool.backlog())
            break

        return result

    def backoff_delay(self) -> float:
        if self._consecutive_failures == 0:
            return self.config.interval_seconds
        delay = self.config.backoff_seconds * (2 ** (self._consecutive_failures - 1))
        return min(delay, self.config.backoff_max_seconds)

    def run(self, *, iterations: int | None = None, stop: Callable[[], bool] = lambda: False) -> ForwardResult:
        total = ForwardResult()
        completed = 0

        while not stop():
            if iterations is not None and completed >= iterations:
                break
            result = self.drain_once()
            total.batches += result.batches
            total.delivered += result.delivered
            total.duplicates += result.duplicates
            total.rejected += result.rejected
            total.failures += result.failures
            total.stopped_reason = result.stopped_reason or total.stopped_reason

            if result.fatal:
                total.fatal = True
                break

            completed += 1
            if iterations is not None and completed >= iterations:
                break
            self._sleep(self.backoff_delay())

        return total
