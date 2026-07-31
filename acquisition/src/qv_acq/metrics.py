"""Prometheus-compatible metrics, rendered to a textfile.

Written for node_exporter's textfile collector rather than served over HTTP: an
acquisition service on an appliance should not open a listening socket, and
scraping is already a solved problem.

The file is written atomically (temp file plus rename) because a collector
scraping a half-written file would report nonsense.
"""

from __future__ import annotations

import os
from pathlib import Path
from typing import Any, Iterable

PREFIX = "quakevault"


def _escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def _labels(pairs: dict[str, Any]) -> str:
    if not pairs:
        return ""
    inner = ",".join(f'{k}="{_escape(str(v))}"' for k, v in sorted(pairs.items()))
    return "{" + inner + "}"


class MetricsRenderer:
    """Builds a Prometheus exposition-format document."""

    def __init__(self) -> None:
        self._lines: list[str] = []
        self._declared: set[str] = set()

    def add(
        self,
        name: str,
        value: float | int | None,
        *,
        labels: dict[str, Any] | None = None,
        help_text: str = "",
        metric_type: str = "gauge",
    ) -> None:
        if value is None:
            return  # An absent measurement is absent, not zero.
        full = f"{PREFIX}_{name}"
        if full not in self._declared:
            if help_text:
                self._lines.append(f"# HELP {full} {help_text}")
            self._lines.append(f"# TYPE {full} {metric_type}")
            self._declared.add(full)
        self._lines.append(f"{full}{_labels(labels or {})} {value}")

    def render(self) -> str:
        return "\n".join(self._lines) + "\n"

    def write(self, path: str | Path) -> None:
        target = Path(path)
        target.parent.mkdir(parents=True, exist_ok=True)
        temp = target.with_suffix(target.suffix + ".tmp")
        temp.write_text(self.render(), encoding="utf-8")
        os.replace(temp, target)  # atomic: a scrape never sees a partial file


def render_engine_metrics(
    engine_stats: dict[str, Any],
    spool_stats: dict[str, Any] | None = None,
    *,
    uptime_seconds: float | None = None,
    extra: Iterable[tuple[str, float, dict[str, Any]]] = (),
) -> MetricsRenderer:
    renderer = MetricsRenderer()
    appliance = engine_stats.get("appliance_id", "unknown")

    if uptime_seconds is not None:
        renderer.add(
            "uptime_seconds", round(uptime_seconds, 1),
            labels={"appliance": appliance},
            help_text="Seconds since the acquisition service started",
            metric_type="counter",
        )

    for bus in engine_stats.get("buses", []):
        bus_labels = {"appliance": appliance, "bus": bus["bus_id"], "adapter": bus["adapter_id"]}
        renderer.add(
            "bus_utilisation_ratio", bus["bus_utilisation"], labels=bus_labels,
            help_text="Fraction of wall-clock time the bus spent in transactions",
        )

        for task in bus.get("tasks", []):
            labels = {**bus_labels, "sensor": task["sensor_id"], "group": task["group"]}
            renderer.add(
                "poll_configured_hz", task["configured_hz"], labels=labels,
                help_text="Configured poll rate",
            )
            renderer.add(
                "poll_measured_hz", task["measured_hz"], labels=labels,
                help_text="Measured effective poll rate. Authoritative; the configured rate is a request",
            )
            renderer.add("poll_jitter_ms", task["jitter_ms"], labels=labels,
                         help_text="Standard deviation of intervals between completed polls")
            renderer.add("poll_latency_ms", task["mean_latency_ms"], labels=labels,
                         help_text="Mean Modbus transaction latency")
            renderer.add("polls_total", task["polls_ok"], labels={**labels, "result": "ok"},
                         help_text="Completed polls", metric_type="counter")
            renderer.add("polls_total", task["polls_failed"], labels={**labels, "result": "failed"},
                         metric_type="counter")
            renderer.add("polls_missed_total", task["missed_polls"], labels=labels,
                         help_text="Scheduled polls skipped because the bus could not keep up",
                         metric_type="counter")
            renderer.add("poll_retries_total", task["retries"], labels=labels,
                         help_text="Retried transactions", metric_type="counter")
            # Exposed as a per-state boolean so alerting can match on state
            # rather than decoding an enum from a number.
            for state in ("closed", "open", "half_open"):
                renderer.add(
                    "circuit_breaker_state", 1 if task["breaker"] == state else 0,
                    labels={**labels, "state": state},
                    help_text="Circuit breaker state, one-hot",
                )

    if spool_stats:
        spool_labels = {"appliance": appliance}
        renderer.add("spool_backlog", spool_stats["backlog"], labels=spool_labels,
                     help_text="Measurements written but not yet forwarded")
        renderer.add("spool_total", spool_stats["total"], labels=spool_labels,
                     help_text="Rows currently held in the spool")
        renderer.add("spool_size_bytes", spool_stats["size_bytes"], labels=spool_labels,
                     help_text="Spool database size on disk")
        for name, value in spool_stats.get("counters", {}).items():
            renderer.add(f"spool_{name}_total", value, labels=spool_labels, metric_type="counter")

    for name, value, labels in extra:
        renderer.add(name, value, labels=labels)

    return renderer
