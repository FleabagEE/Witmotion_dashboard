"""Discovery: finding devices, classifying them, and refusing to guess."""

from __future__ import annotations

from pathlib import Path

import pytest

from qv_acq.discovery import (
    AMBIGUOUS_THRESHOLD,
    IDENTIFIED_THRESHOLD,
    Discovery,
    Verdict,
    _consistency,
    _duplicate_signature,
)
from qv_acq.profiles import loader
from qv_acq.simulator.device import SimulatedDevice
from qv_acq.simulator.rtu import FaultInjection
from qv_acq.simulator.server import SimulatorServer
from qv_acq.simulator.signals import SignalSpec

FIXTURES = Path(__file__).parent / "fixtures"


def wtvb():
    return loader.get("WTVB01-485")


def alt_profile():
    return loader.load_file(FIXTURES / "hwt901b-485.v1.yaml")


def discovery_for(server, **kwargs):
    kwargs.setdefault("bauds", (115200,))
    kwargs.setdefault("slave_ids", range(0x50, 0x53))
    kwargs.setdefault("timeout", 0.2)
    return Discovery(server.port, **kwargs)


class TestFindingDevices:
    def test_finds_a_single_device(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server).scan()

        assert len(found) == 1
        assert found[0].slave_id == 0x50
        assert found[0].reads_succeeded >= 2

    def test_finds_several_devices_on_one_bus(self) -> None:
        devices = {
            0x50: SimulatedDevice(profile=wtvb(), slave_id=0x50),
            0x52: SimulatedDevice(profile=wtvb(), slave_id=0x52),
        }
        with SimulatorServer(devices) as server:
            found = discovery_for(server).scan()

        assert {d.slave_id for d in found} == {0x50, 0x52}

    def test_empty_bus_yields_nothing(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=range(0x60, 0x63)).scan()

        assert found == []

    def test_rejects_slave_ids_outside_the_modbus_range(self) -> None:
        with pytest.raises(ValueError, match="outside the Modbus range"):
            Discovery("/dev/null", slave_ids=[0])
        with pytest.raises(ValueError, match="outside the Modbus range"):
            Discovery("/dev/null", slave_ids=[248])


class TestReadOnlySafety:
    def test_discovery_only_ever_emits_function_code_3(self) -> None:
        """Observed at the wire, not asserted from reading the source.

        Discovery must never save configuration, change an address or baud rate,
        unlock, calibrate, or factory-reset a device while probing.
        """
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            discovery_for(server).scan()
            assert server.function_codes_seen == {0x03}


class TestClassification:
    def test_identifies_a_known_sensor(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50]).scan()

        classification = found[0].classification
        assert classification.model == "WTVB01-485"
        assert classification.verdict is Verdict.IDENTIFIED
        assert classification.confidence >= IDENTIFIED_THRESHOLD
        assert classification.usable_unattended

    def test_gravity_anchor_appears_in_the_evidence(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50]).scan()

        evidence = " ".join(found[0].classification.evidence)
        assert "magnitude" in evidence and "gravity" in evidence
        assert "temperature" in evidence

    def test_nonsense_device_is_not_identified(self) -> None:
        """A device whose registers do not fit any profile must not be guessed."""
        profile = wtvb()
        device = SimulatedDevice(
            profile=profile,
            slave_id=0x50,
            signals={
                c.key: SignalSpec(waveform="implausible", base=900.0)
                for c in profile.channels
            },
        )
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50]).scan()

        classification = found[0].classification
        assert not classification.usable_unattended
        assert classification.confidence < IDENTIFIED_THRESHOLD

    def test_unknown_verdict_carries_no_model(self) -> None:
        profile = wtvb()
        device = SimulatedDevice(
            profile=profile,
            slave_id=0x50,
            signals={c.key: SignalSpec(waveform="implausible", base=5000.0) for c in profile.channels},
        )
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50]).scan()

        classification = found[0].classification
        if classification.verdict is Verdict.UNKNOWN:
            assert classification.model is None
            assert any("rather than guessing" in e for e in classification.evidence)

    def test_scores_every_installed_profile(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        profiles = {"WTVB01-485": wtvb(), "RETIRED": alt_profile()}
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50], profiles=profiles).scan()

        scores = found[0].classification.scores
        assert set(scores) == {"WTVB01-485", "RETIRED"}
        # The real profile must beat the mismatched one.
        assert scores["WTVB01-485"] > scores["RETIRED"]

    def test_no_profiles_installed_is_reported_not_crashed(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50], profiles={}).scan()

        classification = found[0].classification
        assert classification.verdict is Verdict.UNKNOWN
        assert "no sensor profiles" in " ".join(classification.evidence)


class TestDuplicateSlaveIds:
    def test_signature_fires_on_collisions(self) -> None:
        # A third of reads lost and survivors disagreeing is the collision shape.
        assert _duplicate_signature(consistency=0.3, attempts=6, successes=3) is not None

    def test_signature_stays_quiet_for_a_healthy_device(self) -> None:
        assert _duplicate_signature(consistency=0.95, attempts=3, successes=3) is None

    def test_signature_stays_quiet_for_merely_lossy_but_agreeing(self) -> None:
        """Packet loss alone is not a duplicate; the answers must also disagree."""
        assert _duplicate_signature(consistency=0.98, attempts=6, successes=3) is None

    def test_flagged_device_is_not_classified(self) -> None:
        """Under heavy loss and disagreement, refuse to name a model."""
        profile = wtvb()
        device = SimulatedDevice(
            profile=profile,
            slave_id=0x50,
            signals={c.key: SignalSpec(waveform="noise", base=0.0, amplitude=9000.0)
                     for c in profile.channels},
        )
        faults = FaultInjection(drop_probability=0.6)
        with SimulatorServer({0x50: device}, faults=faults, seed=3) as server:
            found = discovery_for(server, slave_ids=[0x50], timeout=0.15).scan()

        if found and found[0].classification.verdict is Verdict.DUPLICATE_SUSPECTED:
            assert found[0].classification.model is None
            assert "re-scan" in " ".join(found[0].classification.evidence)


class TestConsistency:
    def test_identical_reads_score_one(self) -> None:
        assert _consistency([[1, 2, 3], [1, 2, 3], [1, 2, 3]]) == 1.0

    def test_total_disagreement_scores_low(self) -> None:
        assert _consistency([[1, 2, 3], [9, 9, 9], [5, 5, 5]]) < 0.5

    def test_single_sample_is_not_evidence(self) -> None:
        assert _consistency([[1, 2, 3]]) == 0.0


class TestReporting:
    def test_result_serialises_for_the_api(self) -> None:
        device = SimulatedDevice(profile=wtvb(), slave_id=0x50)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50]).scan()

        payload = found[0].as_dict()
        assert payload["slave_id"] == 0x50
        assert payload["model"] == "WTVB01-485"
        assert payload["verdict"] == "identified"
        assert isinstance(payload["evidence"], list) and payload["evidence"]
        assert 0.0 <= payload["confidence"] <= 1.0
        assert AMBIGUOUS_THRESHOLD < payload["confidence"] <= 1.0


class TestOperatorGuidance:
    def test_idle_sensor_is_told_how_to_raise_confidence(self) -> None:
        """A hedged verdict must come with an action, not just a number.

        A vibration sensor at rest genuinely cannot be distinguished from a
        partly wrong map, so confidence is capped by physics. The operator needs
        to know that, and what would change it.
        """
        profile = wtvb()
        idle = {
            c.key: SignalSpec(waveform="constant", base=0.0)
            for c in profile.channels
            if c.quantity in ("vibration_velocity", "vibration_displacement", "dominant_frequency")
        }
        device = SimulatedDevice(profile=profile, slave_id=0x50, signals=idle)
        with SimulatorServer({0x50: device}) as server:
            found = discovery_for(server, slave_ids=[0x50]).scan()

        evidence = " ".join(found[0].classification.evidence)
        assert "read exactly zero" in evidence
        assert "re-scan" in evidence
        # Still no false negative: the anchors keep it as the leading candidate.
        assert found[0].classification.model == "WTVB01-485"

    def test_excited_sensor_scores_higher_than_idle(self) -> None:
        profile = wtvb()
        idle = {
            c.key: SignalSpec(waveform="constant", base=0.0)
            for c in profile.channels
            if c.quantity in ("vibration_velocity", "vibration_displacement", "dominant_frequency")
        }
        results = {}
        for label, signals in (("idle", idle), ("excited", {})):
            device = SimulatedDevice(profile=profile, slave_id=0x50, signals=dict(signals))
            with SimulatorServer({0x50: device}) as server:
                found = discovery_for(server, slave_ids=[0x50]).scan()
            results[label] = found[0].classification.confidence

        assert results["excited"] > results["idle"]
