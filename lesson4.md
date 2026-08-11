# Lesson 4 — Profiles as Data, Not Code

**Module:** `acquisition/src/qv_acq/profiles/schema.py`, `profiles/loader.py`
**Related decisions:** ADR-004, ADR-005, ADR-009

> This is one of the three lessons where the real architecture lives. Read it twice.

---

## Why it exists

The schema's first sentence is the whole design:

> A profile is data, not code. Adding a Modbus sensor must never require touching the acquisition engine — only a new YAML profile plus fixtures.

Ask why that rule is worth enforcing. The naive alternative is obvious and looks fine at first:

```
if model == "WTVB01-485":
    read_registers(0x34, 12)
elif model == "HWT901B-485":
    read_registers(0x3D, 20)
```

ADR-004 tells you exactly why that rots:

> The two profiles built so far overlap on only acceleration and temperature — asserted by test. Any code branching on model name would rot immediately.

Two devices from **the same manufacturer**, in the same product family, over the same protocol, share almost nothing. Now imagine the fifth vendor. Every `elif` is a permanent tax on the engine, and the engine is the one module you least want to churn — it is the real-time path you proved correct in Lesson 2.

**Separate the mechanism from the description.** The engine knows how to poll a bus, decode a window, flag quality and schedule deadlines. It knows nothing about any specific sensor. What a sensor *is* lives in data.

---

## What problem it solves

### 1. The engine must not know about devices

`Channel` in the schema is a complete description of how to turn registers into meaning:

```python
key, label, quantity, unit, address, data_type, word_order,
scale, offset, value_class, minimum, maximum, notes
```

That is everything the decoder needs and nothing about which device it came from. The engine consumes `RegisterGroup` and `Channel`; a new sensor is a new YAML file and fixtures.

### 2. Quantities must be a closed vocabulary

Look at the `Quantity` literal — a fixed enumeration: `acceleration`, `vibration_velocity`, `attitude_angle`, `condition_indicator`, `fault_code`, and more. Not a free-form string.

This matters more than it appears. If a profile could declare `quantity: "accel"` on one sensor and `"acceleration"` on another, then every downstream consumer — the alarm engine matching on quantity, the units checker, the chart axis labels — silently fails to match. A closed vocabulary makes cross-sensor logic possible **at all**.

And note the annotations carried alongside:

```python
# Statistical descriptors computed on the device (RMS, kurtosis, crest
# factor...). These are the backbone of condition monitoring and,
# crucially, are computed on-sensor from its own high-rate sampling - so they
# are not limited by how fast we can poll the bus.
"condition_indicator",
# Vendor fault/diagnosis words. Opaque bit fields; never scaled.
"fault_code",
```

"Never scaled" is a decoding rule attached to a *category*, not to a channel. That is a taxonomy doing real work.

### 3. Provenance must survive the pipeline

```python
ValueClass = Literal["native", "processed", "derived", "simulated", "imported"]
```

Five classes. `measurement.py` explains why:

> the provenance needed to tell native values apart from processed, derived, simulated and imported ones, which is what stops a computed number from being mistaken for a measured one.

This is **taint tracking** for engineering data. A value the sensor reported and a value your appliance calculated are different kinds of claim, and a reader — or a court — is entitled to know which they are looking at. Lesson 9 shows the same idea applied to calibration.

The engine even enforces it defensively; from `engine.py`:

> Letting it keep the DERIVED class would put a number on the record whose class does not match how it was produced, which is the one thing the value class exists to prevent.

### 4. Trust must be a gate, not a comment

This is ADR-005, and it is the most important idea in the lesson:

```python
VerificationStatus = Literal["verified", "candidate", "unverified"]
```

> **Decision.** Only `verified` may drive alarms or notifications.
>
> **Context.** A wrong register map does not fail loudly — it produces plausible numbers. Silently guessing an address is how a monitoring product tells a customer a machine is healthy when it is not.
>
> **Cost.** Hardware commissioning is a gate, not a formality. Promotion to `verified` requires a probe transcript recorded in `register-maps.md`.

Sit with the shape of that decision.

The product is *capable* of alarming on an unverified profile. The numbers decode. The charts draw. Everything works. The architecture deliberately **withholds** the most valuable capability until a human has produced physical evidence — a probe transcript against real hardware, checked in.

This is what separates instrumentation engineering from software engineering. In most software, "it runs" is close to "it works". In instrumentation, a system that runs beautifully on a wrong register map is not a partial success; it is the worst possible outcome, because it is confidently wrong and nobody can tell.

**Confidence must be earned externally and encoded internally.**

---

## What would happen if it were removed

| Remove | Consequence |
|---|---|
| Profiles as data | Every new sensor edits the real-time engine. The module you most need stable becomes the one that changes most often. |
| Closed `Quantity` vocabulary | Cross-sensor logic becomes string-matching guesswork. The alarm engine cannot reliably find "all velocity channels". |
| `ValueClass` | Computed values become indistinguishable from measured ones. A report attached to a damage claim can no longer say what it is. |
| `verification_status` | The appliance alarms on guessed register maps. ADR-005 notes this discipline **already caught two real errors on this project**. |
| Pydantic schema validation | A malformed profile fails at 3 a.m. on a bus, not at load time on a bench. |

That last row is worth naming: validating the profile at load is **fail-fast at the earliest possible moment**. A `data_type` the decoder does not support should be caught when the YAML is read, not when the first poll arrives.

---

## The embedded principles

**1. Mechanism in code, policy in data.** The oldest rule in systems design, and nowhere does it pay more than in device support.

**2. Describe hardware declaratively.** The thing you are describing is not logic. It is a fact about a physical part, and facts belong in data files that can be reviewed, versioned and diffed by someone who has never read your engine.

**3. Version your descriptions.** `profile_version` travels with every measurement. When you discover that address `0x41` was wrong all along, you need to know which stored readings were decoded under the wrong map.

**4. Encode trust levels in the type system.** Not in documentation, not in a wiki. A three-state literal that the alarm engine checks.

**5. Retain retired descriptions.** ADR-009 keeps the HWT901B profile as a fixture even though the sensor left the product — because it is the only thing that proves the engine is genuinely multi-device rather than accidentally shaped around one sensor.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Table-driven / interpreter** | The engine executes a description rather than branching on model |
| **Schema validation at the boundary** | pydantic, at load time |
| **Hardware abstraction layer** | Profiles are the HAL; the engine is device-agnostic |
| **Capability model** | `profile.capabilities()` — what this device can do, as data |
| **Provenance / taint tracking** | `ValueClass` |
| **Trust levels as a gate** | `VerificationStatus` |

---

## Where you will meet this again

This is the pattern with the strongest cross-domain presence in the whole course. Almost every mature embedded ecosystem invented it independently.

### Automotive
- **DBC, LDF, FIBEX, ARXML** — CAN signals, LIN schedules, and entire ECU configurations as data. Nobody writes `if (message_id == 0x123)` in production automotive; a generator consumes the description.
- **AUTOSAR ECU extract** — the ECU's whole communication behaviour is a description compiled into configuration, not hand-written logic.
- **ODX / UDS diagnostic descriptions** — the diagnostic tool learns the ECU from a data file.

### Aerospace
- **ARINC 424** navigation databases, **ARINC 665/615A** loadable software parts. The distinction between *software* and *loadable data* is formalised, certified, and audited separately — which is precisely the profile/engine split, taken to the level of regulation.

### Industrial / PLC
- **GSD (PROFIBUS), GSDML (PROFINET), EDS (EtherNet/IP), ESI (EtherCAT), IODD (IO-Link)** — every fieldbus has a device description file format. The engineering tool reads it and configures the master. This repo's profiles are a GSD file by another name.
- **FDT/DTM** and **EDDL** exist so that instrument vendors ship descriptions rather than drivers.

### Robotics
- **URDF / SDF** — robot kinematics as data.
- **ros2_control hardware interfaces** and YAML controller descriptions — the controller does not know your motor.

### Linux kernel
- **Device Tree** is the single closest analogue in this entire course. The kernel was drowning in board files, each a wall of `if (machine_is_foo())`. Device Tree moved hardware description out of code entirely, and it is now how virtually all non-x86 Linux boots. The problem it solved is exactly ADR-004's, at a scale of thousands of boards.
- **ACPI tables** do the same job on x86.
- **USB and PCI device descriptors** — devices describe themselves; the OS matches drivers to descriptions.

### RTOS firmware
- **Zephyr uses Device Tree directly**, generating build-time configuration from `.dts`.
- **Board Support Packages** and pin-mux configuration tools (STM32CubeMX, TI SysConfig) generate initialisation from a description you edited in a GUI, for the same reason.

---

## The connective tissue

Lesson 3 built one trust boundary. Lesson 4 asks who is allowed to define what happens at it — and answers: a versioned, validated, human-reviewed description, whose trust level is itself part of the data.

The deepest idea here is not the file format. It is this:

> **A monitoring system's most dangerous state is confident and wrong. The architecture's job is to make that state unreachable without a human signature.**

You will meet the same idea in Lesson 11, where alarm *thresholds* — not register maps — require the same named human confirmation before they may page anybody.

---

## Design questions

**1.** `verification_status` gates alarms but not display: unverified profiles still draw charts. Justify that split. Then find the hole — what could a user reasonably conclude from an unverified chart that they should not?

**2.** ADR-009 retains the retired HWT901B profile purely as a test fixture, to prove the engine is genuinely device-agnostic. That fixture costs maintenance forever. Argue for deleting it, then against, and state the general rule you would apply to "tests for hardware we no longer sell".

**3.** Profiles carry `profile_version`, and every measurement stores it. Design what happens when you discover today that a channel's `scale` has been wrong for six months. Walk through the migration: what happens to stored data, to alarm history, to a report already delivered to a client, and to a court case that cites it.

**4.** The hardest one. ADR-005 requires a probe transcript before promotion to `verified`. That is a human process protecting a machine-readable flag — a convention guarding a construction, which is the inverse of Lesson 2's advice. Can you design a *construction* that makes promotion-without-evidence impossible rather than merely against the rules? If you can, say what it costs. If you cannot, say why this is one of the cases where a convention is the honest answer.


**Q1.**  Alarms gated, display not
The justification
Charts are how you verify a register map. Gating display would make promotion impossible — you'd be reduced to reading raw registers in a terminal to decide whether a map is right. The tool you need to do the work would be locked behind the work being done.

And an unverified reading is not wrong, it is unattested. Withholding it destroys the operator's ability to sanity-check, which is the only thing that ever moves a profile from unverified to verified.

The principle underneath, and it is a good one:

Unverified data may inform a human. It may not trigger a machine.

Alarms act — they wake someone at 03:00, send email, write a permanent record. Acting on unattested data is the harm. Showing it to a person who can judge it is the opposite.

The hole
The absence of an alarm reads as evidence of safety, and it is not.

A user sees a chart at 2 mm/s with a threshold line drawn at 3, and no alarm. The reasonable conclusion — we are under the limit — is unsupported, because the alarm was never armed. The silence means nothing, and nothing on the screen says so.

Worse, and specific to this code: WaveformCard draws threshold lines and labels them "(unconfirmed)" based on limits.confirmed — which is threshold confirmation (ADR-015), a different trust chain from profile verification (ADR-005). So a chart from an unverified profile can draw a confirmed threshold line.

A confirmed threshold plotted against an unattested scale is a meaningless comparison rendered as a meaningful one. The chart composes two independent trust chains and says nothing about the join.

Two smaller holes in the same family: the axis label says mm/s with full authority, when an unverified map may have the right register and the wrong scale; and a trend looks valid even under a wrong scale, so "it's rising" survives — unless the register is wrong entirely, in which case something else is rising.

The guardrail
The caveat must live inside the plot frame, not in the page header. A pill next to the title is lost the moment somebody screenshots the chart into an email — and that screenshot is what reaches the client.

No threshold lines on an unverified trace. Drawing the comparison manufactures the meaning.
Axis carries the doubt: mm/s (unverified scale).
Watermark the plot area, so a crop cannot separate the number from its provenance.
Q2. The retired HWT901B fixture
For deleting
Maintenance forever, against hardware nobody can check it against. And that's the sharp version of the problem: when the schema changes and someone updates the fixture to keep tests green, they are editing a description of a device they cannot verify. It drifts into a self-consistent fantasy that still feels like coverage. Dead weight also attracts cargo-culting — the next profile gets written by copying it. And git remembers; restore it the day you need it.

Against deleting
It is the only thing stopping a "profile-driven" architecture from quietly becoming a single-sensor one. With one production profile, every abstraction boundary is untested, and code that assumes WTVB01 channel keys would never be noticed. ADR-004 records that the two profiles "overlap on only acceleration and temperature — asserted by test", so it has already earned its keep. The cost of keeping it is bounded and known; the cost of discovering the architecture rotted is unbounded — you find out when a customer wants sensor #2 and "add a profile" turns into a rewrite.

The rule
Keep the fixture when it constrains the shape of your code. Delete it when it only describes a device.

A fixture asserting "the engine has no knowledge of any specific model" tests your code, and stays valid forever whether or not the hardware exists. A fixture asserting "register 0x3A is quaternion W" tests the device — and once you can't check that against hardware, it is unverifiable and should go.

So: keep it, but stop letting it pretend to be a real device. Strip it to the minimum that exercises capability divergence, and rename it so it cannot be mistaken for a real map. Its value is structural, not factual, and retaining real-looking register addresses is a liability with no upside — somebody will eventually trust them.

Q3. A scale wrong for six months
Checking the schema first changes this answer completely:

The raw registers are retained. So this is not a data-loss event, it is a re-interpretation event. Everything below follows from that.

(Note in passing: processing_version exists as a column and is NULL on every row. That is precisely the mechanism this scenario needs, and nothing populates it — the eighth instance of the pattern in this repo, found by answering a design question.)

Stored data — append, never edit
Bump profile_version, re-decode from raw_registers under the new version, and keep both. Queries take the latest version per (time, sensor, channel). Derived channels, which have no raw registers, are recomputed from the corrected natives.

Overwriting in place would destroy the evidence that the error ever existed and make every prior report irreproducible.

Alarm history — annotate, never re-run
Do not re-evaluate. Alarm events are a record of what the system did and told people, not what it should have done. Silently re-running the evaluator would delete an event that was emailed, leaving an operator who acknowledged it with no trace of what they acknowledged.

The old event stays, annotated: raised under profile 1.2.0; the scale for this channel was corrected on X; re-assessment under 1.3.0 gives Y. Append-only (ADR-016), applied to alarms.

A report already delivered
ADR-018 says reports are evidence, therefore reproducible. So the delivered report must stay reproducible as delivered — pinned to the profile version it used. If you cannot reproduce what you sent, you cannot defend it.

Then issue a correction notice: a new report referencing the original by id, stating what changed and by how much. Never reissue under the same identifier. Superseded, not erased.

A court case citing it
This is where the whole design is tested, and where "just fix the number" ends a company.

You must be able to say under oath: here is exactly what the instrument recorded; here is the map used to interpret it; here is when we learned it was wrong; here is who authorised the change; here is the corrected interpretation. This appliance can — raw registers, per-sample profile version, append-only audit.

An edited record is worse than no record. The opposing expert's next question is "how do we know what else you changed?", and there is no good answer.

And the disclosure is proactive. A monitoring company that quietly corrects data it has already certified has a far bigger problem than a scale factor.

Corrections are appends, never edits.

Q4. Can promotion-without-evidence be made impossible?
Partly — and finding the boundary is the answer.

What you can construct
Today verification_status is a plain field, and the transcript is prose in a markdown appendix. Make the flag derived rather than set: a profile is verified iff a machine-readable transcript exists that satisfies checkable conditions.

That makes four things impossible rather than merely against the rules:

Failure	Made impossible by
Transcript doesn't cover every channel	compare transcript keys against the register set
Transcript is for a different profile version	hash the register map into the transcript
Transcript contradicts the map	re-decode the transcript with the map, compare
Editing a profile after promotion	invalidate verified on any change to the register set
That last one is a live hole. I checked: verification_status has no binding to the map at all. The gate protects promotion and not a single byte of what happens afterwards. Someone can edit a verified profile's addresses and it stays verified. That is worth fixing regardless of the rest of this answer.

What you cannot construct
That a human ran the probe against real hardware rather than a simulator.

You could sign the transcript — but signing only helps if the key is held by something that only signs real probes. You could bind it to a device serial — except these sensors have no unique identity, which is exactly what Lesson 3 established. There is nothing on the far side to bind to.

You could require realistic timing and noise in the transcript, but now you have built a forgery detector, which is a heuristic, not a proof.

Any artefact a machine can check is an artefact a human can forge, unless it is bound to something the human does not control.

Why this is the honest answer, not a failure
Lesson 2's advice is not inverted here — it is bounded, and the boundary has a name:

A construction can enforce internal consistency. Only a person can attest to correspondence with the physical world.

Every system has a surface where it meets reality, and that surface is always crossed by a human. At that crossing, a signature is not a fallback — it is the only thing capable of carrying the meaning. Which is exactly why ADR-015 also requires a named person for thresholds, and why the dead-letter recovery in Lesson 1 keeps a human in the loop.

The cost of building what you can: the transcript must become machine-readable, qv-probe must emit it, the loader must validate it, and every profile change must re-run the gate. Real work — and it converts four failure modes from "against the rules" to "cannot happen", leaving one irreducible act of human attestation, clearly labelled as such.

That labelling is the point. A convention you have deliberately reduced to its irreducible core, and named, is a very different thing from a convention you never examined.


---

*Next: Lesson 5 — The Forwarder: retry, backoff, circuit breaking and at-least-once delivery.*
