"""Forwarder service entrypoint.

A separate process from acquisition on purpose (ADR-003 in spirit): if the
ingestion API hangs, the token expires or the database is down, serial polling
must keep running and keep spooling. The worst case is a growing backlog, never
a gap in the data.
"""

from __future__ import annotations

import argparse
import logging
import signal
import sys
import time
from pathlib import Path

from .config import DEFAULT_CONFIG_PATH, ApplianceConfig
from .forwarder import Forwarder, ForwarderConfig
from .metrics import MetricsRenderer
from .profiles import loader
from .sdnotify import SystemdNotifier
from .spool import Spool

log = logging.getLogger("qv_acq.forwarder_service")


class ForwarderService:
    def __init__(self, config: ApplianceConfig) -> None:
        self.config = config
        self.settings = config.forwarder
        self.notifier = SystemdNotifier()
        self.spool = Spool(
            config.spool.path,
            max_rows=config.spool.max_rows,
            drop_undelivered_when_full=config.spool.drop_undelivered_when_full,
        )
        self.forwarder = Forwarder(
            self.spool,
            ForwarderConfig(
                base_url=self.settings.base_url,
                token=self.settings.token(),
                batch_size=self.settings.batch_size,
                interval_seconds=self.settings.interval_seconds,
                request_timeout=self.settings.request_timeout,
                max_retries=self.settings.max_retries,
                verify_tls=self.settings.verify_tls,
                ca_bundle=self.settings.ca_bundle,
            ),
        )
        self._stop = False
        self._started = time.monotonic()

    def announce(self) -> int:
        """Publish every configured sensor's profile before forwarding."""
        announced = 0
        for bus in self.config.buses:
            for sensor in bus.sensors:
                profile = loader.get(sensor.model)
                slave_id = sensor.slave_id or profile.serial.default_slave_id
                if self.forwarder.announce_profile(
                    self.config.appliance_id, sensor.sensor_id, profile, slave_id
                ):
                    announced += 1
        return announced

    def write_metrics(self, last) -> None:
        if not self.config.metrics.enabled:
            return
        stats = self.spool.stats()
        renderer = MetricsRenderer()
        labels = {"appliance": self.config.appliance_id}
        renderer.add("forwarder_uptime_seconds", round(time.monotonic() - self._started, 1),
                     labels=labels, metric_type="counter")
        renderer.add("forwarder_backlog", stats["backlog"], labels=labels,
                     help_text="Measurements spooled but not yet delivered")
        renderer.add("forwarder_delivered_total", last.delivered, labels=labels,
                     metric_type="counter")
        renderer.add("forwarder_duplicates_total", last.duplicates, labels=labels,
                     metric_type="counter")
        renderer.add("forwarder_rejected_total", last.rejected, labels=labels,
                     metric_type="counter")
        renderer.add("forwarder_failures_total", last.failures, labels=labels,
                     metric_type="counter")
        renderer.add("forwarder_dead_letters", self.spool.dead_letters(self.settings.max_retries),
                     labels=labels, help_text="Records past the retry ceiling; these need an operator")
        try:
            renderer.write(Path(str(self.config.metrics.path)).with_name("forwarder.prom"))
        except OSError as exc:
            log.warning("metrics write failed: %s", exc)

    def run(self, iterations: int | None = None) -> int:
        def request_stop(signum, _frame) -> None:
            log.info("received signal %s, finishing current batch", signum)
            self._stop = True
            self.notifier.stopping("draining")

        for sig in (signal.SIGTERM, signal.SIGINT):
            try:
                signal.signal(sig, request_stop)
            except ValueError:
                pass

        if not self.forwarder.config.token:
            log.error(
                "no ingest token: set %s in the environment (see forwarder.env)",
                self.settings.token_env,
            )
            return 2

        if self.settings.announce_profiles:
            log.info("announced %d sensor profile(s)", self.announce())

        self.notifier.ready(f"forwarding to {self.settings.base_url}")
        completed = 0
        exit_code = 0

        while not self._stop:
            if iterations is not None and completed >= iterations:
                break
            result = self.forwarder.drain_once()
            self.write_metrics(result)
            backlog = self.spool.backlog()
            self.notifier.watchdog()
            self.notifier.status(f"backlog {backlog}; delivered {result.delivered} this cycle")

            if result.fatal:
                # A rejected token will not fix itself. Exit 2 so systemd stops
                # rather than restart-looping against a credential problem.
                exit_code = 2
                break

            if result.delivered:
                log.info("delivered %d measurement(s); backlog %d", result.delivered, backlog)

            completed += 1
            if iterations is not None and completed >= iterations:
                break
            self._sleep(self.forwarder.backoff_delay())

        self.spool.close()
        return exit_code

    def _sleep(self, seconds: float) -> None:
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline and not self._stop:
            time.sleep(min(0.25, deadline - time.monotonic()))


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="QuakeVault spool forwarder")
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG_PATH)
    parser.add_argument("--iterations", type=int, default=None)
    parser.add_argument("--log-level", default="INFO")
    args = parser.parse_args(argv)

    logging.basicConfig(
        level=getattr(logging, args.log_level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    try:
        config = ApplianceConfig.load(args.config)
    except Exception as exc:  # noqa: BLE001
        log.error("configuration error: %s", exc)
        return 2

    if not config.forwarder.enabled:
        log.info("forwarder disabled in configuration; nothing to do")
        return 0

    return ForwarderService(config).run(iterations=args.iterations)


if __name__ == "__main__":
    sys.exit(main())
