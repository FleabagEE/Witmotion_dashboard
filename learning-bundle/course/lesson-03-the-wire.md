# Lesson 3 — The Wire

**Modules:** `acquisition/src/qv_acq/crc.py`, `decode.py`, `encode.py`, `client.py`
**Supporting:** `profiles/schema.py`, `measurement.py`, ADR-005

> **Status:** design questions and answers only. The teaching narrative
> (framing, CRC, byte order, the trust boundary) is still to be written.

---

## Facts to have in front of you

| Fact | Where |
|---|---|
| Four quality states: `GOOD`, `IMPLAUSIBLE`, `STALE`, `BAD` | `measurement.py` |
| `IMPLAUSIBLE` = decoded, but outside the profile's declared range | same |
| Implausible readings are **kept and forwarded**, not discarded | `client.py` |
| A group's quality is the **worst** of its channels | `client.py` |
| Plausibility bounds come from `channel.minimum` / `channel.maximum` | profile YAML |
| Modbus leaves 32-bit word order unspecified → per-channel config | `decode.py` |
| These sensors have **no WHO_AM_I register** | `docs/register-maps.md` |
| Modbus address is configurable at `0x1A`, so it is not identity | `address_cli.py` |

**Which consumers actually honour `quality`:**

| Consumer | Honours it? |
|---|---|
| `SensorHealth` | ✅ counts non-good as "rejected" |
| `AlarmEvaluator` | ⚠️ rejects only `bad` — implausible can raise an alarm |
| `TiltMonitor` | ❌ no filter |
| `SpectrumAnalyzer` | ❌ no filter |
| `ReadController` aggregates | ❌ `avg/min/max` include implausible |

---

## Design questions

**Q1.** `IMPLAUSIBLE` readings are kept and forwarded rather than discarded.
Defend that choice, then argue the opposite: what damage can an implausible value
do once it is in a time-series database that downstream code may query without
checking quality? Design the guardrail.

**Q2.** CRC catches corruption but cannot detect that you polled the wrong slave
ID and got a valid frame from the wrong device. Given that these sensors have no
WHO_AM_I register, design a runtime identity check that would catch a mis-wired
bus after a maintenance visit. What are its false-positive risks?

**Q3.** Plausibility limits come from the profile's declared minimum/maximum. Who
sets those, and what happens when a real structural event legitimately exceeds
them — a blast, an earthquake? Design the policy. Note that "widen the limits"
and "trust the sensor" are the same decision viewed from two ends.

**Q4.** Modbus leaves 32-bit word order unspecified, so this repo made it
per-channel configuration. Name two other places in any embedded protocol where
the specification is silent and vendors diverged. For each, say whether you would
handle it by configuration, by auto-detection, or by refusing to support the
ambiguous case — and why.

---

## Answers

### A1 — Keeping implausible readings

#### The defence

**"Implausible" is a claim about the profile's declared range, not about
physics.** That range came from a datasheet, and this repo's entire posture
(ADR-005) is that register maps and their metadata are suspect until verified.

Discarding at the edge destroys the evidence needed to fix the map. When
displacement reads 18,573 µm against a declared maximum, the interesting question
is *"is the range mode wrong?"* — and `known-limitations.md` already records that
the displacement range mode is invisible to the appliance. You can only answer
that with the values you would have thrown away.

The asymmetry settles it: **a bad value that is labelled bad is recoverable. A
discarded good value is not.**

#### The opposite — and it is not hypothetical here

The label only protects you if consumers honour it. In this repo, most do not
(see the table above). So an implausible value today can:

- **raise a critical alarm and send an email** — `AlarmEvaluator` rejects only
  `bad`
- **pollute every chart bucket's mean** — one 18,573 µm sample in a 60-sample
  bucket shifts it by ~300 µm
- **corrupt `max_value`**, which is worse than the mean, because peak is exactly
  what a peak-based alarm or an ISO assessment reads
- **enter the settlement calculation**, the appliance's primary claim

> **Storing quality beside the value creates an obligation on every reader.
> Obligations that live in documentation are the ones that get missed.**

The same failure class as everything else here: a mechanism that exists and is
not invoked.

#### The guardrail

Principle: **make the safe path the default and the unsafe path explicit.** Do
not ask readers to remember.

1. **Stop exposing raw `value` to queries.** Add a generated column or view
   `value_good`, NULL when `quality <> 'good'`. SQL aggregates skip NULLs, so
   `avg(value_good)` is automatically right. Anyone who genuinely wants
   everything must name `value` — now a visible, reviewable choice.
2. **Every aggregate carries `samples` and `samples_good`.** A bucket that is 80%
   implausible must be able to say so, and the chart can render it differently
   instead of drawing a confident line through junk.
3. **Alarms require good quality** — or at minimum, mark events raised on
   implausible data as `provisional`. A critical alarm from an out-of-range value
   is precisely the false alarm that destroys trust in the whole system.
4. **A contract test that enumerates consumers**: every query touching
   `measurements` must either filter quality or use `value_good`. That is the
   construction that makes the convention impossible to violate silently.

And alarm on the *rate* of implausible readings. `SensorHealth` warns above 1%. A
channel going 100% implausible is a register-map or scaling fault, and that is
the actual diagnosis worth shouting.

### A2 — Runtime identity without WHO_AM_I

The Modbus address is not identity either — it is configurable at `0x1A`. Swap
two units between buses and both still answer where expected.

**What is available as a fingerprint:**

| Signal | Strength here |
|---|---|
| Gravity **direction** | Weak — the install spec mounts all three *in the same orientation*, deliberately destroying this discriminator |
| Gravity **magnitude** | **Strong.** 1.00082 / 0.98659 / 0.99374 g — 1.4% apart, stable per unit, already recorded |
| Temperature offset | Moderate; drifts with environment |
| Noise floor | Distinguishing, but needs a long window |

**Design:** a commissioning fingerprint of {|a|, direction, temperature offset
relative to the sensor mean, noise floor}, stored with the baseline. Recompute
over a quiet window at every startup and daily. Divergence on ≥2 of 4 components
raises *"sensor identity uncertain, verify mounting."*

Two details that matter:

- **It must run at startup**, because a maintenance visit ends in a restart.
- **Cross-match, do not merely compare to history.** A swap shows up as *A now
  matching B's fingerprint and B matching A's*. That is far stronger evidence
  than "A changed" — it is the signature of an exchange rather than a drift.

#### The false-positive risks, and one is fatal

**The fingerprint is built from the measurand.** A 3° structural tilt changes the
gravity direction — that is the instrument doing its job. So *"identity changed"*
and *"the silo moved"* are the same observation. You cannot separate them from
inside the instrument.

Also: recalibration legitimately changes the gain; thermal drift moves both
temperature offset and tilt; re-mounting the same sensor reads as a swap; and two
units from one production batch may have gains within noise of each other — which
produces false *negatives*, the more dangerous direction.

**Honest conclusion:** this check must be framed as *"identity uncertain —
confirm"*, never as an automatic fault. It is strongest immediately after a
maintenance window, where a swap is plausible and structural change in that
window is not, and weakest during continuous operation.

### Correction, 2026-08-11: the fingerprint alone would not have caught it

This answer said a cross-matched fingerprint would detect a swap. Built and
checked against the real numbers, the *drift* half of it fires — 0.0133 g, or
twenty-six times the resolution — and is then **suppressed**.

Both swapped sensors drift by the same amount at the same moment, which is
exactly the signature the cross-sensor arbitration treats as a shared
environmental cause. The mechanism built to stop one fault being reported three
times turned a swap into no report at all.

Cross-matching had to be built as its own thing, checked *before* anything is
called shared: a swap's real signature is that the two sensors moved **toward
each other's baselines**, and mutual resemblance is proof where one-sided
resemblance is coincidence. See `SensorFingerprint::crossMatch()`.

**The lesson is not about swaps.** A detector and a suppressor can each be
correct and still combine into silence. Whenever you add a rule that *withholds*
an alarm, enumerate what it withholds.

### And prevention still beats both

Note also that identity has two halves here, and the fingerprint addresses
neither directly:

- the **sensor** has no WHO_AM_I and no serial
- the **adapter** (CH341) has no USB serial number either, which is why udev
  keys on physical topology — see the same rules file keying on
  `ATTRS{serial}` for FTDI and CP210x parts

So an FTDI adapter fixes *adapters moved between sockets*, and distinct Modbus
addresses fix *sensors moved between adapters*. They are different failures.
**Prevention beats fingerprinting**; the fingerprint is the fallback for units
that share both an address and a nameless adapter.

### A3 — Who sets plausibility limits

**The framing that resolves this: the limit is a statement about the instrument,
not about the structure.**

- Outside the *device's* range → not a measurement at all. The device cannot
  represent it, so the reading is an artifact.
- Inside the device's range but outside what you *expect structurally* → a
  completely different thing, and calling it implausible is the error.

**Policy: plausibility limits are device measurement limits, never engineering
expectations.** If the part measures ±16 g, the bound is ±16 g. A blast producing
12 g is GOOD data that happens to be alarming. Setting the bound at 2 g "because
a silo should not exceed that" makes the instrument blind to exactly the events
it exists for.

A real event exceeding the device range is then **saturation**, which deserves
its own quality state — not `IMPLAUSIBLE`, and certainly not `GOOD`. A saturated
reading means *"the event was at least this big"*, real information that must
never be averaged as though it were a measurement.

**Full policy:**

1. Limits = datasheet device range, with provenance under ADR-005's verification
   gate.
2. **Never field-adjustable.** Changing them is a profile version bump, reviewed,
   with the reason recorded.
3. Structural expectations live in **alarm thresholds** — separate, confirmable
   by a named person (ADR-015), and freely adjustable.
4. Add `SATURATED` to `QualityStatus`, set where the raw register sits at its
   rail.
5. When events genuinely exceed the device range, the fix is **hardware** — a
   wider-range sensor — not a metadata edit.

The question's observation is exactly right, and it is why (2) matters: widening
a limit *after* an event trips it is retroactively deciding to believe data you
previously declared unbelievable, with no new evidence. **That is editing the
record.**

### A4 — Two more places the spec is silent

**(1) Modbus RTU inter-frame timing above 19200 baud → configuration.**

The spec says 3.5 character times idle between frames, then says above 19200 baud
use fixed 1.75 ms / 0.75 ms. Plenty of vendors keep scaling by character time,
and USB-serial bridges wreck the timing anyway by batching bytes. Two masters,
same wire, different framing decisions.

Configuration, because the failure is **intermittent and load-dependent**. An
auto-detector sampling a quiet bus will confidently conclude something that stops
being true under load. Ship a tool that measures and *recommends*; make a human
write it down — the same shape as the turnaround measurement in Lesson 2.

**(2) I²C repeated-start versus stop-then-start on register reads → refuse.**

The spec permits both; some parts require repeated-start and lock up on
stop-start. Here the wrong guess does not give you wrong data — **it wedges the
bus**, and recovery needs a power cycle or nine manual clock pulses.

Auto-detection is impossible when the failure mode is "the bus stops responding."
Configuration is nearly as bad, because a wrong config is discovered in the field
at 3 a.m. So: support only the sequence the part's datasheet explicitly states,
and **refuse to run a device whose datasheet does not state it.** The same
instinct as ADR-005 refusing to let unverified register maps drive alarms.

*Worth noting:* CAN has this identically — the standard says nothing about byte
order for multi-byte signals, which is why DBC files carry per-signal
Intel/Motorola. The industry landed exactly where this repo did on Modbus word
order. **When a spec is silent about representation, configuration wins**,
because the ambiguity is stable per-device and knowable in advance.

**Where auto-detection *is* right**, completing the triad: scaling ambiguities
like 0.1 °C versus 0.01 °C units, where 2640 / 264 / 26.4 is instantly
discriminable against a plausible physical range. Detect it, then **pin it** and
alarm if it ever changes.

> **Auto-detect when a wrong guess is loudly and immediately wrong.**
> **Configure when a wrong guess is quietly and plausibly wrong.**
> **Refuse when a wrong guess damages the bus or the device.**

---

## Open defect found while writing this

`TiltMonitor` and `SpectrumAnalyzer` apply no quality filter, `AlarmEvaluator`
rejects only `bad`, and the chart aggregates average implausible values in. The
guardrail in A1 is not an exercise — it is unbuilt work.

---

*Next: **Lesson 4 — Profiles as data, not code.** Register maps as versioned
data, and the decision that an unverified map may not drive an alarm.*
