"""Configuration validation, metrics rendering and the service entrypoint."""

from __future__ import annotations

import asyncio
from pathlib import Path

import pytest
import yaml

from qv_acq.config import ApplianceConfig
from qv_acq.metrics import MetricsRenderer, render_engine_metrics
from qv_acq.profiles import loader
from qv_acq.sdnotify import SystemdNotifier
from qv_acq.service import AcquisitionService, main
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.server import SimulatorServer

REPO_CONFIG = Path(__file__).resolve().parents[2] / "deploy" / "quakevault" / "acquisition.yaml"


def config_dict(port: str, tmp_path: Path, **overrides) -> dict:
    base = {
        "appliance_id": "QV-EDGE-TEST",
        "spool": {"path": str(tmp_path / "spool.db")},
        "metrics": {"path": str(tmp_path / "metrics.prom"), "interval_seconds": 0.2},
        "buses": [
            {
                "bus_id": "BUS-001",
                "adapter_id": "A1",
                "port": port,
                "baud": 115200,
                "timeout": 0.5,
                "sensors": [
                    {
                        "sensor_id": "SENSOR-001",
                        "model": "WTVB01-485",
                        "slave_id": 0x50,
                        "groups": ["motion"],
                        "poll_hz": {"motion": 20},
                    }
                ],
            }
        ],
    }
    base.update(overrides)
    return base


class TestConfig:
    def test_shipped_example_is_valid(self) -> None:
        """The config we install must parse; a typo here breaks every install."""
        config = ApplianceConfig.model_validate(yaml.safe_load(REPO_CONFIG.read_text()))
        assert config.appliance_id
        assert config.sensor_count() == 1
        assert config.buses[0].sensors[0].model == "WTVB01-485"

    def test_shipped_example_only_references_real_groups(self) -> None:
        config = ApplianceConfig.model_validate(yaml.safe_load(REPO_CONFIG.read_text()))
        profile = loader.get("WTVB01-485")
        known = {g.key for g in profile.register_groups}
        for sensor in config.buses[0].sensors:
            assert set(sensor.groups or []) <= known
            assert set(sensor.poll_hz) <= known

    def test_shipped_example_uses_a_stable_device_alias(self) -> None:
        config = ApplianceConfig.model_validate(yaml.safe_load(REPO_CONFIG.read_text()))
        port = str(config.buses[0].port)
        assert "ttyUSB" not in port, "config must not reference a renumbering kernel name"
        assert port.startswith("/dev/quakevault-rs485-")

    def test_duplicate_slave_id_on_one_bus_is_rejected(self, tmp_path: Path) -> None:
        raw = config_dict("/dev/null", tmp_path)
        raw["buses"][0]["sensors"].append(
            {"sensor_id": "SENSOR-002", "model": "WTVB01-485", "slave_id": 0x50}
        )
        with pytest.raises(ValueError, match="duplicate slave_id"):
            ApplianceConfig.model_validate(raw)

    def test_duplicate_sensor_id_is_rejected(self, tmp_path: Path) -> None:
        raw = config_dict("/dev/null", tmp_path)
        raw["buses"][0]["sensors"].append(
            {"sensor_id": "SENSOR-001", "model": "WTVB01-485", "slave_id": 0x51}
        )
        with pytest.raises(ValueError, match="duplicate sensor_id"):
            ApplianceConfig.model_validate(raw)

    def test_two_buses_cannot_share_a_port(self, tmp_path: Path) -> None:
        raw = config_dict("/dev/null", tmp_path)
        second = dict(raw["buses"][0])
        second["bus_id"] = "BUS-002"
        raw["buses"] = [raw["buses"][0], second]
        with pytest.raises(ValueError, match="share a serial port"):
            ApplianceConfig.model_validate(raw)

    def test_negative_poll_rate_is_rejected(self, tmp_path: Path) -> None:
        raw = config_dict("/dev/null", tmp_path)
        raw["buses"][0]["sensors"][0]["poll_hz"] = {"motion": -1}
        with pytest.raises(ValueError, match="must be positive"):
            ApplianceConfig.model_validate(raw)

    def test_unverified_profile_is_refused_at_startup(self, tmp_path: Path) -> None:
        """The verification gate must block deployment, not just alarms."""
        raw = config_dict("/dev/null", tmp_path)
        config = ApplianceConfig.model_validate(raw)
        profile = loader.get("WTVB01-485")
        original = profile.verification_status
        object.__setattr__(profile, "verification_status", "candidate")
        try:
            with pytest.raises(ValueError, match="not 'verified'"):
                config.buses[0].sensors[0].to_binding()
        finally:
            object.__setattr__(profile, "verification_status", original)

    def test_missing_file_is_reported_clearly(self, tmp_path: Path) -> None:
        with pytest.raises(FileNotFoundError, match="configuration not found"):
            ApplianceConfig.load(tmp_path / "absent.yaml")


class TestMetrics:
    def test_absent_values_are_omitted_not_zeroed(self) -> None:
        renderer = MetricsRenderer()
        renderer.add("thing", None, labels={"a": "b"})
        renderer.add("thing", 5, labels={"a": "b"})
        rendered = renderer.render()
        assert rendered.count("quakevault_thing{") == 1

    def test_labels_are_escaped(self) -> None:
        renderer = MetricsRenderer()
        renderer.add("thing", 1, labels={"name": 'a"b'})
        assert '\\"' in renderer.render()

    def test_write_is_atomic(self, tmp_path: Path) -> None:
        renderer = MetricsRenderer()
        renderer.add("thing", 1)
        target = tmp_path / "m.prom"
        renderer.write(target)
        assert target.read_text().strip().endswith("1")
        assert not list(tmp_path.glob("*.tmp")), "temp file left behind"

    def test_engine_metrics_include_measured_rate_and_breaker(self) -> None:
        stats = {
            "appliance_id": "QV",
            "buses": [
                {
                    "bus_id": "B1", "adapter_id": "A1", "port": "/dev/x", "baud": 9600,
                    "bus_utilisation": 0.25,
                    "tasks": [
                        {
                            "sensor_id": "S1", "group": "motion",
                            "configured_hz": 5.0, "measured_hz": 4.9, "jitter_ms": 2.0,
                            "mean_latency_ms": 30.0, "polls_ok": 10, "polls_failed": 1,
                            "missed_polls": 0, "retries": 2, "breaker": "closed",
                        }
                    ],
                }
            ],
        }
        spool = {"backlog": 3, "total": 10, "size_bytes": 4096, "counters": {"duplicates_rejected": 1}}
        text = render_engine_metrics(stats, spool, uptime_seconds=12.0).render()

        assert "quakevault_poll_measured_hz" in text
        assert "quakevault_poll_configured_hz" in text
        assert 'quakevault_circuit_breaker_state{' in text
        assert 'state="closed"} 1' in text
        assert 'state="open"} 0' in text
        assert "quakevault_spool_backlog" in text
        assert "quakevault_spool_duplicates_rejected_total" in text
        assert "quakevault_uptime_seconds" in text


class TestSystemdNotifier:
    def test_disabled_outside_systemd(self, monkeypatch) -> None:
        monkeypatch.delenv("NOTIFY_SOCKET", raising=False)
        notifier = SystemdNotifier()
        assert not notifier.enabled
        # Must be a silent no-op, not an error.
        notifier.ready()
        notifier.watchdog()
        notifier.stopping()

    def test_watchdog_interval_is_half_the_configured_period(self, monkeypatch) -> None:
        monkeypatch.setenv("WATCHDOG_USEC", "60000000")
        assert SystemdNotifier.watchdog_interval() == 30.0

    def test_watchdog_interval_absent_without_systemd(self, monkeypatch) -> None:
        monkeypatch.delenv("WATCHDOG_USEC", raising=False)
        assert SystemdNotifier.watchdog_interval() is None

    def test_malformed_watchdog_value_is_ignored(self, monkeypatch) -> None:
        monkeypatch.setenv("WATCHDOG_USEC", "not-a-number")
        assert SystemdNotifier.watchdog_interval() is None


class TestServiceRun:
    def test_runs_and_writes_spool_and_metrics(self, tmp_path: Path) -> None:
        device = SimulatedDevice(profile=loader.get("WTVB01-485"), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            config = ApplianceConfig.model_validate(config_dict(server.port, tmp_path))
            service = AcquisitionService(config, use_lock=False)
            code = asyncio.run(service.run(duration=1.0))

        assert code == 0
        assert service.sink.accepted > 5
        metrics = (tmp_path / "metrics.prom").read_text()
        assert "quakevault_poll_measured_hz" in metrics
        assert "quakevault_spool_backlog" in metrics
        assert (tmp_path / "spool.db").exists()

    def test_check_mode_validates_without_touching_hardware(self, tmp_path: Path, capsys) -> None:
        path = tmp_path / "acquisition.yaml"
        path.write_text(yaml.safe_dump(config_dict("/dev/does-not-exist", tmp_path)))
        assert main(["--config", str(path), "--check"]) == 0
        assert "OK:" in capsys.readouterr().out

    def test_bad_config_exits_two_not_one(self, tmp_path: Path) -> None:
        """Exit 2 is wired to RestartPreventExitStatus in the unit file.

        A configuration error must stop the service rather than restart-loop.
        """
        path = tmp_path / "acquisition.yaml"
        path.write_text(yaml.safe_dump({"appliance_id": "X"}))  # no buses
        assert main(["--config", str(path), "--check"]) == 2

    def test_missing_config_exits_two(self, tmp_path: Path) -> None:
        assert main(["--config", str(tmp_path / "nope.yaml"), "--check"]) == 2


class TestForwarderSettings:
    def test_shipped_example_configures_the_forwarder(self) -> None:
        config = ApplianceConfig.model_validate(yaml.safe_load(REPO_CONFIG.read_text()))
        assert config.forwarder.enabled
        assert config.forwarder.base_url.endswith("/ingest")
        assert config.forwarder.token_env == "QV_INGEST_TOKEN"

    def test_token_never_appears_in_the_config_file(self) -> None:
        """The credential lives in a 0600 env file, not in world-readable YAML."""
        text = REPO_CONFIG.read_text().lower()
        assert "token:" not in text
        assert "bearer" not in text

    def test_token_is_read_from_the_environment(self, monkeypatch) -> None:
        config = ApplianceConfig.model_validate(yaml.safe_load(REPO_CONFIG.read_text()))
        monkeypatch.setenv("QV_INGEST_TOKEN", "secret-value")
        assert config.forwarder.token() == "secret-value"

    def test_missing_token_is_empty_not_an_exception(self, monkeypatch) -> None:
        config = ApplianceConfig.model_validate(yaml.safe_load(REPO_CONFIG.read_text()))
        monkeypatch.delenv("QV_INGEST_TOKEN", raising=False)
        assert config.forwarder.token() == ""


class TestForwarderService:
    def _config(self, tmp_path: Path) -> ApplianceConfig:
        raw = config_dict("/dev/null", tmp_path)
        raw["forwarder"] = {"enabled": True, "base_url": "http://127.0.0.1:9/ingest",
                            "announce_profiles": False}
        return ApplianceConfig.model_validate(raw)

    def test_missing_token_exits_two(self, tmp_path: Path, monkeypatch) -> None:
        """Exit 2 is wired to RestartPreventExitStatus in the unit file.

        A missing credential is not transient; restart-looping would only hide it.
        """
        from qv_acq.forwarder_service import ForwarderService

        monkeypatch.delenv("QV_INGEST_TOKEN", raising=False)
        service = ForwarderService(self._config(tmp_path))
        assert service.run(iterations=1) == 2

    def test_writes_forwarder_metrics(self, tmp_path: Path, monkeypatch) -> None:
        from qv_acq.forwarder import ForwardResult
        from qv_acq.forwarder_service import ForwarderService

        monkeypatch.setenv("QV_INGEST_TOKEN", "t")
        service = ForwarderService(self._config(tmp_path))
        service.write_metrics(ForwardResult(delivered=5, duplicates=1))
        service.spool.close()

        text = (tmp_path / "forwarder.prom").read_text()
        assert "quakevault_forwarder_backlog" in text
        assert "quakevault_forwarder_delivered_total" in text
        assert "quakevault_forwarder_dead_letters" in text

    def test_disabled_forwarder_exits_cleanly(self, tmp_path: Path) -> None:
        from qv_acq.forwarder_service import main

        raw = config_dict("/dev/null", tmp_path)
        raw["forwarder"] = {"enabled": False}
        path = tmp_path / "acquisition.yaml"
        path.write_text(yaml.safe_dump(raw))
        assert main(["--config", str(path)]) == 0
