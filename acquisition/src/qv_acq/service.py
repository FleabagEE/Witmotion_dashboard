"""Acquisition service entrypoint.

Wires configuration, spool, engine and metrics together and runs until told to
stop. Shutdown is graceful on SIGTERM and SIGINT: buses are stopped, the spool is
flushed and closed, and systemd is told what is happening.
"""

from __future__ import annotations

import argparse
import asyncio
import logging
import signal
import sys
import time
from pathlib import Path

from .config import DEFAULT_CONFIG_PATH, ApplianceConfig
from .engine import AcquisitionEngine
from .metrics import render_engine_metrics
from .portlock import PortBusyError
from .sdnotify import SystemdNotifier
from .spool import Spool, SpoolingSink

log = logging.getLogger("qv_acq.service")


class AcquisitionService:
    def __init__(self, config: ApplianceConfig, *, use_lock: bool = True) -> None:
        self.config = config
        self.use_lock = use_lock
        self.notifier = SystemdNotifier()
        self.spool = Spool(
            config.spool.path,
            max_rows=config.spool.max_rows,
            drop_undelivered_when_full=config.spool.drop_undelivered_when_full,
        )
        self.sink = SpoolingSink(self.spool)
        self.engine = AcquisitionEngine(config.appliance_id, self.sink)
        self._started = time.monotonic()
        self._stopping = False

        for bus in config.buses:
            self.engine.add_bus(
                bus_id=bus.bus_id,
                adapter_id=bus.adapter_id,
                port=str(bus.port),
                baud=bus.baud,
                sensors=[sensor.to_binding() for sensor in bus.sensors],
                timeout=bus.timeout,
                retry=bus.retry(),
                breaker=bus.breaker(),
                use_lock=use_lock,
                simulated=config.simulated,
            )

    @property
    def uptime(self) -> float:
        return time.monotonic() - self._started

    def write_metrics(self) -> None:
        if not self.config.metrics.enabled:
            return
        renderer = render_engine_metrics(
            self.engine.stats(), self.spool.stats(), uptime_seconds=self.uptime
        )
        renderer.write(self.config.metrics.path)

    async def _metrics_loop(self, stop: asyncio.Event) -> None:
        interval = self.config.metrics.interval_seconds
        while not stop.is_set():
            try:
                self.write_metrics()
            except OSError as exc:  # a full disk must not stop acquisition
                log.warning("metrics write failed: %s", exc)
            try:
                await asyncio.wait_for(stop.wait(), timeout=interval)
            except asyncio.TimeoutError:
                pass

    async def _watchdog_loop(self, stop: asyncio.Event) -> None:
        interval = SystemdNotifier.watchdog_interval()
        if interval is None or not self.notifier.enabled:
            return
        while not stop.is_set():
            self.notifier.watchdog()
            backlog = self.spool.backlog()
            self.notifier.status(
                f"{self.config.sensor_count()} sensor(s) on {len(self.config.buses)} bus(es); "
                f"spool backlog {backlog}"
            )
            try:
                await asyncio.wait_for(stop.wait(), timeout=interval)
            except asyncio.TimeoutError:
                pass

    async def run(self, duration: float | None = None) -> int:
        stop = asyncio.Event()
        loop = asyncio.get_running_loop()

        def request_stop(signame: str) -> None:
            if not self._stopping:
                self._stopping = True
                log.info("received %s, shutting down", signame)
                self.notifier.stopping("draining buses")
                self.engine.stop()
                stop.set()

        for signame in ("SIGTERM", "SIGINT"):
            try:
                loop.add_signal_handler(getattr(signal, signame), request_stop, signame)
            except (NotImplementedError, RuntimeError):
                pass  # not available on every platform or in every loop

        helpers = [
            asyncio.create_task(self._metrics_loop(stop)),
            asyncio.create_task(self._watchdog_loop(stop)),
        ]
        self.notifier.ready(
            f"acquiring from {self.config.sensor_count()} sensor(s) "
            f"on {len(self.config.buses)} bus(es)"
        )
        log.info(
            "acquisition started: appliance=%s sensors=%d buses=%d",
            self.config.appliance_id, self.config.sensor_count(), len(self.config.buses),
        )

        exit_code = 0
        try:
            await self.engine.run(duration=duration)
        except PortBusyError as exc:
            # A second instance is a configuration error, not a transient fault.
            # Exiting non-zero lets systemd surface it instead of restart-looping
            # forever against a port it will never get.
            log.error("%s", exc)
            exit_code = 2
        except Exception:  # noqa: BLE001
            log.exception("acquisition failed")
            exit_code = 1
        finally:
            stop.set()
            for task in helpers:
                task.cancel()
            await asyncio.gather(*helpers, return_exceptions=True)
            try:
                self.write_metrics()
            except OSError:
                pass
            self.spool.close()
            log.info(
                "acquisition stopped after %.1fs; %d measurements spooled",
                self.uptime, self.sink.accepted,
            )
        return exit_code


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description="QuakeVault acquisition service")
    parser.add_argument("--config", type=Path, default=DEFAULT_CONFIG_PATH)
    parser.add_argument("--duration", type=float, default=None,
                        help="run for N seconds then exit (testing and smoke checks)")
    parser.add_argument("--check", action="store_true",
                        help="validate the configuration and exit without touching hardware")
    parser.add_argument("--no-lock", action="store_true",
                        help="skip the exclusive port lock (tests only)")
    parser.add_argument("--log-level", default="INFO")
    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    logging.basicConfig(
        level=getattr(logging, args.log_level.upper(), logging.INFO),
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )

    try:
        config = ApplianceConfig.load(args.config)
    except Exception as exc:  # noqa: BLE001
        log.error("configuration error: %s", exc)
        return 2

    if args.check:
        try:
            for bus in config.buses:
                for sensor in bus.sensors:
                    sensor.to_binding()
        except Exception as exc:  # noqa: BLE001
            log.error("configuration error: %s", exc)
            return 2
        print(
            f"OK: {config.appliance_id}, {len(config.buses)} bus(es), "
            f"{config.sensor_count()} sensor(s)"
        )
        return 0

    service = AcquisitionService(config, use_lock=not args.no_lock)
    return asyncio.run(service.run(duration=args.duration))


if __name__ == "__main__":
    sys.exit(main())
