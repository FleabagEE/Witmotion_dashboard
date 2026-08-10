# Lesson 3 — The Wire

**Module:** `acquisition/src/qv_acq/client.py`
**Supporting cast:** `crc.py`, `decode.py`
**Related decisions:** ADR-005

---

## Why it exists

`client.py` opens with the sentence that defines the module:

> This is the boundary between the protocol and everything above it: nothing downstream sees a register number.

That is a **trust boundary**. Below it, bytes arrive from a device you did not build, over a differential pair that runs past contactors and VFDs. Above it, the rest of the appliance treats values as facts.

Every serious embedded system has exactly one place where "bytes that arrived" become "a measurement". The quality of the whole product is determined by how paranoid that one place is.

---

## What problem it solves

**A wrong byte is indistinguishable from a real reading.**

This is the defining hazard of instrumentation. A corrupted HTTP response fails to parse and throws. A corrupted Modbus register decodes perfectly into a number — just the wrong one. `0x0FA0` is 4000; flip one bit and you have 4008. Both are valid `int16`. Both scale to plausible acceleration. Nothing anywhere will object.

So the module defends in three independent layers.

### Layer 1 — CRC: did the frame survive the wire?

```python
def crc16(payload: bytes) -> int:
    crc = 0xFFFF
    for byte in payload:
        crc ^= byte
        ...
```

Modbus RTU CRC-16, polynomial `0xA001`, low byte first. Note what this is and is not: **an integrity check, not a security control.** It catches noise. It does not catch a malicious sender, and it does not catch the far more common industrial failure — a frame that is perfectly intact and completely wrong because you asked the wrong device or the wrong address.

### Layer 2 — Decode: are these bytes the type we think?

`decode.py` is pure. No I/O, no client, no state:

> Kept deliberately free of I/O so every decoding rule is unit-testable against fixtures without hardware or a simulator.

This is the **functional core / imperative shell** split, and in embedded work it is worth more than it is in application software. Decoding rules are where the subtle bugs live — sign extension, word order, scaling — and they are exactly the rules you cannot exercise if testing them requires a device on a desk.

Look at what the code knows that a naive implementation would not:

```python
if data_type == "int16":
    # Signed 16-bit two's complement. WitMotion returns signed values for
    # acceleration, angular velocity, angle, magnetic field and temperature.
    return words[0] - 0x10000 if words[0] & 0x8000 else words[0]
```

and:

```python
``word_order`` controls 32-bit assembly only: ``big`` places the high-order
word first (the WitMotion and general Modbus convention), ``little`` swaps
them for devices that publish CDAB ordering.
```

**Modbus does not specify 32-bit word order.** The standard defines 16-bit registers and stops. Every vendor picked their own convention, and the industry names them ABCD, CDAB, BADC, DCBA. A `float32` read with the wrong word order does not error — it produces a wildly wrong but perfectly well-formed float. Making word order a per-channel property in the profile is an admission that the protocol is under-specified and that the appliance must be told, not left to guess.

### Layer 3 — Plausibility: is this number possible?

This is the layer most implementations skip, and it is the best idea in the module.

```python
class Quality(StrEnum):
    GOOD = "good"
    #: Decoded, but outside the profile's declared engineering range. Strong
    #: evidence of a wrong register map, word order, or slave identity.
    IMPLAUSIBLE = "implausible"
    #: No response, bad CRC, or an exception reply.
    BAD = "bad"
```

Three states, not two. Read that middle comment again — it does not say "out of range". It says **what being out of range is evidence of**.

That is diagnostic reasoning encoded in a type. A single implausible reading might be a genuine transient. A channel that is *persistently* implausible is telling you that your register map, your word order, or your slave ID is wrong. The appliance has turned "the numbers look weird" — the vaguest complaint in instrumentation — into a machine-readable signal.

And crucially, `IMPLAUSIBLE` is **not** `BAD`. The reading is kept, flagged, and forwarded. You do not throw away evidence of your own misconfiguration.

---

## What would happen if it were removed

| Remove | Symptom |
|---|---|
| CRC check | Line noise becomes measurements. Random spikes that look like real events, unreproducible, worst during electrical activity — exactly when a structural monitor is most trusted. |
| Pure `decode.py` | Decoding rules become untestable without hardware. Sign-extension and word-order bugs ship. |
| Per-channel `word_order` | 32-bit channels silently wrong on any vendor using CDAB. Values are smooth and plausible. |
| `IMPLAUSIBLE` state | A wrong register map is indistinguishable from a healthy sensor. This is ADR-005's entire premise. |
| Quality flags entirely | Downstream cannot tell "nothing was measured" from "a low value was measured" — and Lesson 11 shows that distinction is what stops an alarm engine reporting a dead sensor as a healthy machine. |

---

## The embedded principles

**1. Validate at the boundary, once, thoroughly.** Not scattered checks downstream. One place where bytes become facts, and it is the paranoid place.

**2. Layer independent checks.** CRC catches corruption. Type decoding catches structure. Plausibility catches semantics. Each catches a class the others cannot: a CRC cannot tell you that you are talking to the wrong device, and a range check cannot tell you a byte flipped.

**3. Keep the decoder pure.** Anything that requires hardware to test will not be tested at the edges, and the edges are where instrument bugs live.

**4. Never let "unusual" be silently equal to "fine" or to "broken".** Reserve a third state. Most quality taxonomies are richer than good/bad for exactly this reason.

**5. Under-specified protocols must be configured, not guessed.** Word order lives in the profile because the standard does not settle it.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Anti-corruption layer** | `client.py` — no register numbers escape upward |
| **Functional core / imperative shell** | pure `decode.py`, I/O-bearing `client.py` |
| **Layered validation / defence in depth** | CRC → type → range |
| **Quality tagging / taint propagation** | `Quality` travels with every value |
| **Table-driven decoding** | `WORD_COUNT`, channel definitions from the profile |

---

## Where you will meet this again

### Automotive
- **CAN** stacks distinguish form errors, stuff errors, CRC errors and ACK errors — different failure classes get different names, exactly like this `Quality` enum.
- **AUTOSAR SWC ports** carry data with validity, and E2E Protection wraps safety-relevant signals in CRC plus an alive counter, because a stale-but-intact frame is its own hazard.
- **DBC files** define signal endianness per signal (`@0`/`@1`) for the same reason `word_order` is per channel.

### Aerospace
- **ARINC 429** is the purest ancestor of this design: every word carries a **Sign/Status Matrix** with Normal Operation, Functional Test, No Computed Data, and Failure Warning. "No Computed Data" is `IMPLAUSIBLE` — the transmitter is alive and telling you it cannot vouch for this value.
- **Air data computers** cross-compare and flag disagreement rather than picking a winner silently.

### Industrial / PLC
- **OPC UA StatusCode** is a whole taxonomy: `Good`, `Uncertain`, `Bad` — with `Uncertain_SensorNotAccurate`, `Uncertain_LastUsableValue`. The middle tier exists industry-wide because two states are not enough.
- **HART** and **NAMUR NE43** define out-of-range current bands (< 3.6 mA, > 21 mA) specifically so a sensor fault is distinguishable from a low reading. That is a plausibility gate implemented in analog.
- **PROFIBUS/PROFINET** modules carry per-channel diagnosis alongside process data.

### Robotics
- **`sensor_msgs/Imu`** carries covariance matrices, and `-1` in the first element means "this quantity is unknown" — a structured admission of ignorance.
- **`NavSatFix`** has a `status` field; fusion nodes are expected to gate on it rather than consume blindly.

### Linux kernel
- **`skb` checksum states** — `CHECKSUM_NONE`, `CHECKSUM_UNNECESSARY`, `CHECKSUM_COMPLETE`, `CHECKSUM_PARTIAL`. The kernel tracks not just whether data is valid but *how much validation has already occurred*.
- **IIO subsystem** separates raw registers from scaled values with `_raw`, `_scale`, `_offset` sysfs attributes — the same raw/engineering split as `apply_scaling()`.
- Driver code that validates descriptors from a device before trusting them: a USB device can lie about its own descriptors, and the kernel checks.

### RTOS firmware
- **UART framing, parity and overrun errors** are distinct flags in every peripheral, not one "error" bit — the hardware designers made the same taxonomy choice.
- **Sensor driver self-test and WHO_AM_I registers**: confirm identity before trusting data. `IMPLAUSIBLE` is the software equivalent for devices that lack a WHO_AM_I.

---

## The connective tissue

Lesson 2's engine protects the *timing* of the physical world. Lesson 3 protects the *meaning* of what came back. Together they enforce a rule worth internalising:

> **A measurement is not a number. It is a number plus a claim about how much you should believe it.**

Strip the claim and everything downstream is forced to assume total confidence — which is how a monitoring product tells a customer a building is fine when the register map was wrong all along.

---

## Design questions

**1.** `IMPLAUSIBLE` readings are kept and forwarded rather than discarded. Defend that choice, then argue the opposite: what damage can an implausible value do once it is in a time-series database that downstream code may query without checking quality? Design the guardrail.
Q1. Keeping IMPLAUSIBLE readings
The defence
"Implausible" is a claim about the profile's declared range, not about physics. That range came from a datasheet, and this repo's entire posture (ADR-005) is that register maps and their metadata are suspect until verified.

Ans: Discarding at the edge destroys the evidence needed to fix the map. When displacement reads 18,573 µm against a declared maximum, the interesting question is "is the range mode wrong?" — and known-limitations.md already records that the WTVB01-485's displacement range mode is invisible to the appliance. You can only answer that question with the values you'd have thrown away. The asymmetry settles it: a bad value that is labelled bad is recoverable. A discarded good value is not. Keep and label is correct.
 So an implausible value today can raise a critical alarm and send an email; it pollutes every chart bucket's mean; and it corrupts max_value, which is worse than the mean because peak is exactly what an ISO assessment reads. It also enters the settlement calculation — the appliance's primary claim.

Storing quality beside the value creates an obligation on every reader. Obligations that live in documentation are the ones that get missed.

That is the same failure class as everything else in this repo: a mechanism that exists and isn't invoked.

The guardrail
Principle: make the safe path the default and the unsafe path explicit. Don't ask readers to remember.
1. Stop exposing raw value to queries. Add a generated column or view value_good, NULL when quality <> 'good'. SQL aggregates skip NULLs, so avg(value_good) is automatically right. Anyone who genuinely wants everything must name value — now a visible, reviewable choice.
2. Every aggregate carries samples and samples_good. A bucket that is 80% implausible must be able to say so, and the chart can render it differently instead of drawing a confident line through junk.
3. Alarms require good quality. At minimum, mark events raised on implausible data as provisional. A critical alarm from an out-of-range value is precisely the false alarm that destroys trust in the whole system.
4. A contract test that enumerates consumers — every query touching measurements must either filter quality or use value_good. That's the construction that makes the convention impossible to violate silently.
And: alarm on the rate of implausible readings. SensorHealth warns above 1%. A channel going 100% implausible is a register-map or scaling fault, and that is the actual diagnosis worth shouting.




**2.** The CRC catches corruption but cannot detect that you polled the wrong slave ID and got a valid frame from the wrong device. Given that these sensors have no `WHO_AM_I` register, design a runtime identity check that would catch a mis-wired bus after a maintenance visit. What are its false-positive risks?
ANS: The address isn't identity either — it's configurable at 0x1A. Swap two units between buses and both still answer where expected.
Design: a commissioning fingerprint of {|a|, direction, temperature offset relative to the sensor mean, noise floor}, stored with the baseline. Recompute over a quiet window at every startup and daily. Divergence on ≥2 of 4 → "sensor identity uncertain, verify mounting."

Two details that matter:

It must run at startup, because a maintenance visit ends in a restart.
Cross-match, don't just compare to history. A swap shows up as A now matching B's fingerprint and B matching A's. That's far stronger evidence than "A changed" — it's the signature of an exchange rather than a drift.
The false-positive risks, and one is fatal
The fingerprint is built from the measurand. A 3° structural tilt changes the gravity direction — that is the instrument doing its job. So "identity changed" and "the silo moved" are the same observation. You cannot separate them from inside.

Also: recalibration legitimately changes the gain; thermal drift moves both temperature offset and tilt (already documented as dominating long-term); re-mounting the same sensor reads as a swap; and two units from the same production batch may have gains within noise of each other — which produces false negatives, the more dangerous direction.

Honest conclusion: this check must be framed as "identity uncertain — confirm", never as an automatic fault. It is strongest immediately after a maintenance window, where a swap is plausible and structural change in that window is not, and weakest during continuous operation.

Better: don't need it. Give each sensor a distinct firmware address (0x50/0x51/0x52) so a swap is detectable by address alone. qv-set-address already exists for this. Prevention beats fingerprinting; the fingerprint is the fallback for units that share an address.


**3.** Plausibility limits come from the profile's declared `minimum`/`maximum`. Who sets those, and what happens when a real structural event legitimately exceeds them — a blast, an earthquake? Design the policy. Note that "widen the limits" and "trust the sensor" are the same decision viewed from two ends.
ANS: The framing that resolves this: the limit is a statement about the instrument, not about the structure.

Outside the device's range → not a measurement at all. The device cannot represent it, so the reading is an artifact.
Inside the device's range but outside what you expect structurally → a completely different thing, and calling it implausible is the error.
Policy: plausibility limits are device measurement limits, never engineering expectations. If the part measures ±16 g, the bound is ±16 g. A blast producing 12 g is GOOD data that happens to be alarming. Setting the bound at 2 g "because a silo shouldn't exceed that" makes the instrument blind to exactly the events it exists for.

Then a real event exceeding the device range is saturation, which deserves its own quality state — not IMPLAUSIBLE, and certainly not GOOD. A saturated reading means "the event was at least this big", which is real information that must never be averaged as if it were a measurement. Add SATURATED to QualityStatus.

Full policy:

1. Limits = datasheet device range, with provenance under ADR-005's verification gate.
2. Never field-adjustable. Changing them is a profile version bump, reviewed, with the reason recorded.
3. Structural expectations live in alarm thresholds — separate, confirmable by a named person (ADR-015), and freely adjustable.
4. SATURATED where the raw register sits at its rail.
5. When events genuinely exceed the device range, the fix is hardware — a wider-range sensor — not a metadata edit.
Your observation is exactly right, and it's why (2) matters: widening a limit after an event trips it is retroactively deciding to believe data you previously declared unbelievable, with no new evidence. That is editing the record.


**4.** Modbus leaves 32-bit word order unspecified, so this repo made it per-channel configuration. Name two other places in *any* embedded protocol you know where the specification is silent and vendors diverged. For each, say whether you would handle it by configuration, by auto-detection, or by refusing to support the ambiguous case — and why.
ANS: Q4. Two more places the spec is silent
(1) Modbus RTU inter-frame timing above 19200 baud → configuration.

The spec says 3.5 character times idle between frames, then says above 19200 baud use fixed 1.75 ms / 0.75 ms. Plenty of vendors keep scaling by character time, and USB-serial bridges wreck the timing anyway by batching bytes. Two masters, same wire, different framing decisions.

Configuration, because the failure is intermittent and load-dependent. An auto-detector sampling a quiet bus will confidently conclude something that stops being true under load. Ship a tool that measures and recommends; make a human write it down — same shape as the turnaround measurement.

(2) I²C repeated-start vs stop-then-start on register reads → refuse.

The spec permits both; some parts require repeated-start and lock up on stop-start. This is the case where the wrong guess doesn't give you wrong data — it wedges the bus, and recovery needs a power cycle or nine manual clock pulses.

Auto-detection is impossible when the failure mode is "the bus stops responding." Configuration is nearly as bad, because a wrong config is discovered in the field at 3 a.m. So: support only the sequence the part's datasheet explicitly states, and refuse to run a device whose datasheet doesn't state it. That refusal is the same instinct as ADR-005 refusing to let unverified register maps drive alarms.

(Worth noting: CAN has this identically — the standard says nothing about byte order for multi-byte signals, which is why DBC files carry per-signal Intel/Motorola. The industry landed exactly where this repo did on Modbus word order. When a spec is silent about representation, configuration wins, because the ambiguity is stable per-device and knowable in advance.)

And where auto-detection is right — completing the triad: scaling ambiguities like 0.1 °C vs 0.01 °C units, where 2640 / 264 / 26.4 is instantly discriminable against a plausible physical range. Detect it, then pin it and alarm if it ever changes.

Auto-detect when a wrong guess is loudly and immediately wrong.
Configure when a wrong guess is quietly and plausibly wrong.
Refuse when a wrong guess damages the bus or the device.

---

*Next: Lesson 4 — Profiles as Data, Not Code.*
