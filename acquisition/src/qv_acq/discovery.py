"""Read-only Modbus discovery with confidence scoring.

Finds responders on a bus, then decides what they are - and, just as importantly,
admits when it does not know. A monitoring appliance that silently guesses a
sensor model will decode the wrong registers and alarm on nonsense, so discovery
returns one of four verdicts and only the strongest permits unattended use.

Safety rules, enforced structurally rather than by convention:

  * only function code 0x03 is ever emitted. Discovery cannot save
    configuration, change a slave address or baud rate, unlock, calibrate, or
    factory-reset a device, because it has no code path that builds a write;
  * the scanned address range is bounded and caller-supplied;
  * a responder must answer consistently more than once before it is believed,
    so line noise is not mistaken for a device.
"""

from __future__ import annotations

import time
from collections import Counter
from dataclasses import dataclass, field
from enum import StrEnum
from typing import Iterable, Sequence

from pymodbus.client import ModbusSerialClient

from .decode import decode, plausible
from .profiles import loader
from .profiles.schema import SensorProfile

#: Baud rates the WitMotion family supports.
STANDARD_BAUDS = (9600, 115200, 19200, 38400, 57600, 4800, 230400)

#: Confidence needed before a device may be used without operator confirmation.
IDENTIFIED_THRESHOLD = 0.85
AMBIGUOUS_THRESHOLD = 0.50


class Verdict(StrEnum):
    IDENTIFIED = "identified"
    AMBIGUOUS = "ambiguous_requires_confirmation"
    UNKNOWN = "unknown_modbus_device"
    DUPLICATE_SUSPECTED = "duplicate_slave_id_suspected"


@dataclass
class Classification:
    model: str | None
    confidence: float
    verdict: Verdict
    evidence: list[str] = field(default_factory=list)
    scores: dict[str, float] = field(default_factory=dict)

    @property
    def usable_unattended(self) -> bool:
        return self.verdict is Verdict.IDENTIFIED


@dataclass
class DiscoveredDevice:
    slave_id: int
    baud: int
    classification: Classification
    reads_attempted: int
    reads_succeeded: int
    response_consistency: float

    def as_dict(self) -> dict[str, object]:
        return {
            "slave_id": self.slave_id,
            "baud": self.baud,
            "model": self.classification.model,
            "confidence": round(self.classification.confidence, 3),
            "verdict": str(self.classification.verdict),
            "evidence": self.classification.evidence,
            "scores": {k: round(v, 3) for k, v in self.classification.scores.items()},
            "reads_attempted": self.reads_attempted,
            "reads_succeeded": self.reads_succeeded,
            "response_consistency": round(self.response_consistency, 3),
        }


class Discovery:
    """Bounded, read-only sweep of an RS-485 bus."""

    def __init__(
        self,
        port: str,
        *,
        bauds: Sequence[int] = STANDARD_BAUDS,
        slave_ids: Iterable[int] = range(1, 248),
        timeout: float = 0.25,
        confirm_reads: int = 3,
        profiles: dict[str, SensorProfile] | None = None,
    ) -> None:
        self.port = port
        self.bauds = tuple(bauds)
        self.slave_ids = tuple(slave_ids)
        self.timeout = timeout
        self.confirm_reads = max(2, confirm_reads)
        self.profiles = profiles if profiles is not None else loader.load_all()
        for slave in self.slave_ids:
            if not 1 <= slave <= 247:
                raise ValueError(f"slave id {slave} outside the Modbus range 1-247")

    # -- transport ----------------------------------------------------------

    def _read(self, client, slave: int, start: int, count: int) -> list[int] | None:
        """The only device interaction in this module. Function 0x03 only."""
        try:
            response = client.read_holding_registers(address=start, count=count, device_id=slave)
        except Exception:  # noqa: BLE001 - probing is best-effort
            return None
        if response is None or response.isError():
            return None
        return list(response.registers)

    # -- scanning -----------------------------------------------------------

    def scan(self) -> list[DiscoveredDevice]:
        found: list[DiscoveredDevice] = []
        for baud in self.bauds:
            client = ModbusSerialClient(
                port=self.port, baudrate=baud, bytesize=8, parity="N",
                stopbits=1, timeout=self.timeout,
            )
            if not client.connect():
                continue
            try:
                for slave in self.slave_ids:
                    device = self._probe(client, slave, baud)
                    if device is not None:
                        found.append(device)
            finally:
                client.close()
            if found:
                # A bus runs at one baud rate. Once devices answer, scanning the
                # remaining rates only adds time and noise.
                break
        return found

    def _probe(self, client, slave: int, baud: int) -> DiscoveredDevice | None:
        """Confirm a responder, then classify it."""
        probe_start, probe_count = 0x34, 4
        samples: list[list[int]] = []
        attempts = 0

        for _ in range(self.confirm_reads):
            attempts += 1
            registers = self._read(client, slave, probe_start, probe_count)
            if registers is not None:
                samples.append(registers)
            time.sleep(0.01)

        if not samples:
            return None
        if len(samples) < 2:
            # A single answer among several attempts is not a device; it is noise
            # or a collision.
            return None

        consistency = _consistency(samples)
        classification = self._classify(client, slave, samples, consistency, attempts, len(samples))
        return DiscoveredDevice(
            slave_id=slave,
            baud=baud,
            classification=classification,
            reads_attempted=attempts,
            reads_succeeded=len(samples),
            response_consistency=consistency,
        )

    # -- classification -----------------------------------------------------

    def _classify(
        self,
        client,
        slave: int,
        samples: list[list[int]],
        consistency: float,
        attempts: int,
        successes: int,
    ) -> Classification:
        evidence: list[str] = []

        duplicate = _duplicate_signature(consistency, attempts, successes)
        if duplicate is not None:
            evidence.append(duplicate)
            return Classification(
                model=None, confidence=0.0, verdict=Verdict.DUPLICATE_SUSPECTED, evidence=evidence
            )

        scores: dict[str, float] = {}
        per_profile_evidence: dict[str, list[str]] = {}
        for model, profile in self.profiles.items():
            score, notes = self._score_profile(client, slave, profile)
            scores[model] = score
            per_profile_evidence[model] = notes

        if not scores:
            return Classification(
                model=None, confidence=0.0, verdict=Verdict.UNKNOWN,
                evidence=["no sensor profiles are installed to compare against"],
            )

        best_model = max(scores, key=lambda m: scores[m])
        best = scores[best_model]
        evidence.extend(per_profile_evidence[best_model])

        runners_up = sorted((s for m, s in scores.items() if m != best_model), reverse=True)
        if runners_up and best - runners_up[0] < 0.10 and best >= AMBIGUOUS_THRESHOLD:
            evidence.append(
                f"another profile scored within 0.10 ({runners_up[0]:.2f} vs {best:.2f}); "
                "operator confirmation required"
            )
            return Classification(best_model, best, Verdict.AMBIGUOUS, evidence, scores)

        if best >= IDENTIFIED_THRESHOLD:
            verdict = Verdict.IDENTIFIED
        elif best >= AMBIGUOUS_THRESHOLD:
            verdict = Verdict.AMBIGUOUS
        else:
            verdict = Verdict.UNKNOWN
            evidence.append(
                f"best profile match {best_model} scored only {best:.2f}; treating as an "
                "unknown Modbus device rather than guessing"
            )
        return Classification(best_model if verdict is not Verdict.UNKNOWN else None,
                              best, verdict, evidence, scores)

    def _score_profile(self, client, slave: int, profile: SensorProfile) -> tuple[float, list[str]]:
        """Score a profile against the device: does its map explain what we read?

        Three signals, because no one of them discriminates on its own:

        ``correctness``
            Of the channels that said anything at all, how many were in range.
            Catches a map that decodes live data into nonsense.

        ``coverage``
            How much of the profile's declared map is demonstrably populated.
            This is what separates two profiles that share their anchor
            channels: a map claiming registers the device leaves empty explains
            the device less well than one whose registers all carry data.

        ``anchors``
            Physics that a wrong map cannot fake - gravity, ambient temperature.

        Registers reading exactly zero are treated as *uninformative*, never as
        passes. Zero satisfies nearly every plausibility range, so counting it as
        a pass lets an unrelated profile score as highly as the right one. Zero is
        also genuinely ambiguous on real hardware: an idle vibration channel and
        an unpopulated register look identical.
        """
        live_in_range = 0
        out_of_range = 0
        silent = 0
        ranged_channels = 0
        notes: list[str] = []

        for group in profile.register_groups:
            registers = self._read(client, slave, group.start_address, group.register_count)
            if registers is None:
                notes.append(f"group {group.key} at 0x{group.start_address:02X} did not respond")
                ranged_channels += sum(
                    1 for c in group.channels if c.minimum is not None or c.maximum is not None
                )
                continue

            for channel in group.channels:
                if channel.minimum is None and channel.maximum is None:
                    continue
                ranged_channels += 1
                offset = channel.address - group.start_address
                words = registers[offset : offset + channel.word_count]
                if len(words) != channel.word_count:
                    continue
                if not any(words):
                    silent += 1
                    continue
                value = decode(
                    words, channel.data_type, word_order=channel.word_order,
                    scale=channel.scale, offset=channel.offset,
                )
                if plausible(value, minimum=channel.minimum, maximum=channel.maximum):
                    live_in_range += 1
                else:
                    out_of_range += 1
                    notes.append(
                        f"{channel.key} decoded {value:.4g} {channel.unit}, outside its declared "
                        f"range [{channel.minimum}, {channel.maximum}]"
                    )

        speaking = live_in_range + out_of_range
        correctness = live_in_range / speaking if speaking else 0.0
        coverage = live_in_range / ranged_channels if ranged_channels else 0.0

        anchor, anchor_notes = self._anchor_bonus(client, slave, profile)
        notes.extend(anchor_notes)

        score = min(1.0, correctness * 0.40 + coverage * 0.35 + anchor * 0.25)
        notes.insert(
            0,
            f"{live_in_range} live in-range, {out_of_range} out of range, {silent} reading zero "
            f"of {ranged_channels} ranged channels (correctness {correctness:.2f}, "
            f"coverage {coverage:.2f}, anchors {anchor:.2f})",
        )

        # An idle sensor is indistinguishable from a partly wrong map without
        # stimulus, so confidence is capped by physics, not by a defect. Say so,
        # and say what would raise it - otherwise an operator sees perfect
        # correctness and perfect anchors alongside a hedged verdict and has no
        # idea what to do about it.
        if ranged_channels and silent / ranged_channels >= 0.4 and out_of_range == 0:
            notes.append(
                f"{silent} of {ranged_channels} channels read exactly zero, which is normal for a "
                "vibration sensor at rest but is also what a wrong register map looks like. "
                "Excite the sensor (run the machine, or tap the mount) and re-scan to raise "
                "confidence."
            )
        return score, notes

    def _anchor_bonus(self, client, slave: int, profile: SensorProfile) -> tuple[float, list[str]]:
        """Physics-based checks that a wrong register map cannot fake.

        A plausibility range is weak evidence: zero satisfies most of them. These
        are the checks where the right answer is known in advance.
        """
        notes: list[str] = []
        awarded = 0.0
        possible = 0.0

        temperature = next((c for c in profile.channels if c.quantity == "temperature"), None)
        if temperature is not None:
            possible += 1.0
            words = self._read(client, slave, temperature.address, temperature.word_count)
            if words is not None:
                value = decode(words, temperature.data_type, scale=temperature.scale,
                               offset=temperature.offset)
                if -20.0 <= value <= 80.0:
                    awarded += 1.0
                    notes.append(f"temperature {value:.2f} degC is a plausible ambient reading")
                else:
                    notes.append(f"temperature {value:.2f} degC is not a plausible ambient reading")

        accel = [c for c in profile.channels if c.quantity == "acceleration"]
        if len(accel) >= 3:
            possible += 1.0
            axes = sorted(accel, key=lambda c: c.address)[:3]
            words = self._read(client, slave, axes[0].address, 3)
            if words is not None and len(words) == 3:
                values = [
                    decode([w], a.data_type, scale=a.scale, offset=a.offset)
                    for w, a in zip(words, axes)
                ]
                magnitude = sum(v * v for v in values) ** 0.5
                # A stationary triaxial accelerometer must read 1 g total. No
                # wrong scale factor lands on 1.0 by accident.
                if 0.80 <= magnitude <= 1.25:
                    awarded += 1.0
                    notes.append(f"acceleration vector magnitude {magnitude:.3f} g matches gravity")
                else:
                    notes.append(
                        f"acceleration vector magnitude {magnitude:.3f} g does not match gravity "
                        "(device may be in motion, or the scale factor is wrong)"
                    )

        return (awarded / possible if possible else 0.0), notes


def _consistency(samples: list[list[int]]) -> float:
    """How reproducible repeated reads of the same block were.

    1.0 means every read returned identical registers. Live measurements move, so
    a healthy sensor sits below 1.0; what matters is that it is not near zero.
    """
    if len(samples) < 2:
        return 0.0
    per_register = []
    for index in range(len(samples[0])):
        values = Counter(sample[index] for sample in samples if index < len(sample))
        per_register.append(values.most_common(1)[0][1] / len(samples))
    return sum(per_register) / len(per_register) if per_register else 0.0


def _duplicate_signature(consistency: float, attempts: int, successes: int) -> str | None:
    """Heuristic for two devices answering on one address.

    Modbus offers no way to ask "is anyone else using this address". When two
    devices reply to the same frame their responses collide on the wire, so the
    symptom is a high failure rate combined with mutually contradictory answers
    on the reads that do survive. Reported as suspected, never as certain.
    """
    failure_rate = 1.0 - (successes / attempts) if attempts else 0.0
    if failure_rate >= 0.34 and consistency < 0.5:
        return (
            f"suspected duplicate slave id: {failure_rate:.0%} of reads failed and surviving "
            f"responses disagreed (consistency {consistency:.2f}). Two devices answering one "
            "address collide on the wire. Disconnect all but one and re-scan."
        )
    return None
