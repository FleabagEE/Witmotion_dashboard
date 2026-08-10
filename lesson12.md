# Lesson 12 — Three Questions and the Evidence Trail

**Modules:** `SensorHealth.php`, `DeliveryHealth.php`, `StructureMovement.php`, `AuditLogger.php`, `ReportGenerator.php`
**Related decisions:** ADR-016, ADR-018, ADR-019

---

## Why it exists

Lessons 1–11 built a system that acquires correctly, decodes carefully, refuses honestly and alarms sparingly. This lesson is about the two things that remain once the engineering is right:

- **Does the system know which question it is answering?**
- **Can it prove, later, what it said and why?**

Both were learned the hard way here, and the second is what makes this a *product* rather than an instrument.

---

## Part 1 — Three questions that look identical on a chart

The appliance answers three distinct questions, and it took a real outage to discover that the third had no answer at all.

| Question | Service | Failure it describes |
|---|---|---|
| What did the structure do? | `StructureMovement` | The silo is moving |
| Can these instruments be believed? | `SensorHealth` | The sensor is broken |
| Are the readings arriving? | `DeliveryHealth` | The pipeline is stalled |

**All three produce the same symptom: a flat line on a chart.**

`SensorHealth` says it in its own docstring — *"a dead sensor and a perfectly still silo produce the same chart. Every flat line on this dashboard has two explanations and only one of them is good news."*

And during the sixteen-hour database outage, all three were simultaneously true-looking and only one was correct: every sensor healthy, the structure still, and nothing arriving. `SensorHealth` reported *"Silent for 900s"* — which reads as a dead instrument, and would have sent a technician up a silo to check a cable that was not broken.

### The discipline: keep them apart

`SensorHealth` is explicit about what it refuses to consider:

> **WHAT IS DELIBERATELY NOT CHECKED.** Nothing about tilt, movement or vibration. Those describe the structure, and mixing them in would let a genuinely moving silo read as an unhealthy sensor — which is the one confusion this page exists to prevent.

And the health endpoint refuses to merge delivery into the overall status:

> A backlog is not a sick sensor, and collapsing the two would let a patient, correctly-working spool turn every instrument on the page amber.

> **Separate questions must have separate answers, and the separation must be defended against the constant pressure to produce "one overall status".** Aggregation feels like simplification. It is information destruction, and the information it destroys is the diagnostic information.

### The gravity check

Worth calling out as a piece of instrumentation craft:

> **gravity** — does |a| read 1 g at rest, which it must whatever the orientation. The single best evidence an accelerometer is working: a failed axis, a scaling fault or a loose mount all move it off 1.

This is a **self-test derived from physics rather than from the device**. It requires no cooperation from the sensor, no diagnostic register, no vendor support. It works on any accelerometer ever made, and it catches decode errors, axis failures and scaling faults in one number.

Find the invariant your physics guarantees, and check it continuously. That is worth more than any vendor's built-in test, because the vendor's test is written by the same people who wrote the firmware that might be wrong.

### The taxonomy of not-knowing

Both health services distinguish `pass` / `warn` / `fail` / **`unknown`**. The fourth state is the mature one: *"I could not determine this"* is different from *"this is broken"*. Merging them produces either false alarms or false confidence, and you get to choose which.

---

## Part 2 — Evidence

### An audit trail the application cannot edit

ADR-016:

> **Decision.** `audit_events` carries a `BEFORE UPDATE OR DELETE` trigger that raises. There is no Eloquent model for it, so there is no `->update()` or `->delete()` for anybody to reach for either.
>
> **Context.** An audit trail that the application can edit is not evidence.

Two layers, and both are Lesson 2's principle — *constructions over conventions* — applied to integrity:

1. A **database trigger** that raises on update or delete. Not a policy. Not a code review rule. The database refuses.
2. **No ORM model exists.** There is no `AuditEvent::find()->delete()` to write, because there is no class. The dangerous operation is not forbidden; it is *unavailable*.

And the honesty about the limit:

> Removing the log wholesale still requires DDL rights, which the application database user should not hold in production.

The architecture states precisely how far its guarantee extends. It does not claim tamper-proof; it claims tamper-evident against the application tier, and names the privilege boundary beyond which the claim stops.

One more detail:

> Actor name and role are denormalised onto each row, so a record stays readable after the account is renamed or deleted.

Normalisation is correct for operational data and **wrong for historical records**. An audit row must remain readable when the user table has moved on. Evidence is a snapshot, not a join.

The accepted cost:

> Audit rows cannot be corrected, only supersededly. That is the intended property.

### Reports as evidence

ADR-018:

> **Decision.** Every report stores its parameters, software version, processing version, standard-table status, and a SHA-256 of its own content. Regenerating with the same parameters and processing version yields the same checksum.
>
> **Context.** A vibration report may end up attached to a damage claim or a dispute with a contractor. A document that cannot be reproduced is an assertion, not evidence.

`PROCESSING_VERSION` is the sophisticated part:

> bumped whenever the arithmetic changes, so an old report is never silently reinterpreted under new rules.

Without it, improving a statistic silently changes the meaning of every historical report. With it, old and new deliberately do not match — and the ADR takes that on the chin:

> Changing a statistic means bumping the version and accepting that old and new reports will not match — which is the correct outcome, stated openly.

### Two refusals inside the report

**Gaps are reported as gaps:**

> A quiet hour and an hour with the sensor offline look identical on a chart. Every report states coverage and gap minutes, and the PDF carries a caution when data is missing, because **absence of a reading is not evidence that the structure was still.**

That final clause is the entire course compressed into one sentence. It is Lesson 3's `IMPLAUSIBLE`, Lesson 8's staleness gate, Lesson 11's null guard and Lesson 10's refusal — all the same idea, arriving in the document a client actually reads.

**Caveats go at the top:**

> While the guideline tables remain unverified, the PDF opens with a box saying so and stating that the document is not a compliance assessment. A reader who stops after the first page must still know.

Design for the reader who stops early, because that is most readers. A caveat in an appendix is a caveat designed to be missed.

### Integration surfaces are attack surfaces

ADR-019, briefly, because it belongs to evidence:

> The appliance publishes to MQTT and never subscribes for data. Measurements enter through the authenticated ingestion API alone.
>
> If MQTT could write to the historical record, any of those becomes a route to forge readings or alarms. Publishing only means a compromised broker can mislead somebody's screen but cannot corrupt the evidence.

One-way data flow as an architectural guarantee, enforced at the broker too (`allow_anonymous false`, read-only ACL, *verified by observing that a publish from that credential is discarded*). The claim was tested, not assumed.

And the retention subtleties are exactly right: alarms are QoS 1 and **not retained** (a retained alarm replays to every new subscriber and is misread as live); status **is** retained (a subscriber connecting mid-stream learns current state immediately); a **last will** is registered so subscribers are told the appliance died rather than being left with a stale `online`.

Finally:

> Publishing never breaks monitoring. Every failure is logged and swallowed: an unreachable broker is an integration outage, and the database still holds the truth.

**Know which of your outputs is the truth and which is a convenience, and never let the convenience take the truth down with it.**

---

## What would happen if these were removed

| Remove | Consequence |
|---|---|
| Separate health questions | A stalled pipeline reads as a dead sensor; wrong truck roll, wrong diagnosis |
| The `unknown` state | Cannot-determine becomes either false alarm or false confidence |
| Gravity invariant check | Decode errors, axis failures and loose mounts all invisible |
| Append-only trigger | The audit trail is editable, therefore not evidence |
| Denormalised actor | History becomes unreadable after staff changes |
| `PROCESSING_VERSION` | Historical reports silently reinterpreted under new arithmetic |
| Gap reporting | Sensor downtime presented as structural quiet — the most dangerous confusion in the product |
| MQTT outbound-only | A contractor's compromised laptop can forge structural evidence |

---

## The embedded principles

**1. Know which question you are answering, and refuse to merge questions that share a symptom.**

**2. Find invariants your physics guarantees and check them continuously.**

**3. `unknown` is a first-class state.**

**4. Evidence must be structurally immutable, and you must state where the guarantee ends.**

**5. Denormalise historical records. Evidence is a snapshot.**

**6. Version your arithmetic so old conclusions are never silently reinterpreted.**

**7. Report absence explicitly. Missing data is never evidence of a quiet world.**

**8. Data flows one way into the record.**

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Separation of concerns in diagnostics** | three health services |
| **Physical invariant self-test** | \|a\| = 1 g |
| **Append-only / WORM log** | audit trigger, no ORM model |
| **Content addressing** | SHA-256 of report content |
| **Versioned processing** | `PROCESSING_VERSION` |
| **Snapshot denormalisation** | actor name and role on the row |
| **Unidirectional data flow** | MQTT publish-only |
| **Dead man's switch** | MQTT last will |

---

## Where you will meet this again

### Automotive
- **EDR (Event Data Recorder)** — regulated content, protected from modification, admissible in litigation. This is `ReportGenerator` with legal force.
- **Odometer and emissions tamper protection**: checksummed, mirrored, cross-checked, because the value has legal and financial consequence.
- **Secure boot and signed diagnostic sessions** — writes to the record require authenticated authority.

### Aerospace
- **FDR/CVR** with defined parameter sets, protected recording, and controlled read-out. **FOQA/ACMS** as the operational cousin.
- **Configuration management to ARINC 665** — you can prove which software version produced which behaviour on which airframe on which day. That is `PROCESSING_VERSION` at fleet scale, mandated.
- **MEL (Minimum Equipment List)** — a formal statement of what is unserviceable and what you may still conclude with it missing. Gap reporting, institutionalised.

### Industrial / PLC
- **21 CFR Part 11** in pharmaceutical manufacturing: electronic records must be attributable, legible, contemporaneous, original and accurate — with computer-generated, time-stamped audit trails that **cannot obscure previously recorded information**. That last clause is ADR-016's trigger, written into US federal regulation.
- **ALCOA+ data integrity principles** — the same list, expanded, and the source of "absence of a reading is not evidence".
- **Sequence of Events recorders** and **historian data quality flags**, where a gap is stored as a gap rather than interpolated away.

### Robotics
- **rosbag with metadata**: software versions, parameters, and topic list recorded alongside the data, so a run can be reproduced.
- **Deterministic replay** for autonomous-vehicle incident investigation — the same output from the same input and the same software version, or your investigation proves nothing.
- **Safety case documentation** tracing behaviour to the version that produced it.

### Linux kernel
- **`auditd` and the kernel audit subsystem** — a separate, privileged path so the audited subsystem cannot rewrite its own record.
- **IMA/EVM** measuring and appraising file integrity, and **dm-verity** for read-only rootfs integrity: cryptographic evidence of what code was actually running.
- **`printk` timestamps and the dropped-message counter**, one final time: the kernel refusing to let absence pass silently.

### RTOS firmware
- **Secure/measured boot with a chain of hashes** — provable statements about which firmware ran.
- **Black-box logging to protected flash**, with wear-levelled append-only regions.
- **Version and build-ID in every log line**, so a field log can be matched to a binary years later.
- **Self-test on physical invariants at boot** — accelerometer 1 g check, oscillator cross-check against a second time base, reference voltage validation. Exactly the gravity check, at power-on.

---

## The arc of the whole course

Twelve lessons, one philosophy. If you retain nothing else:

> **A monitoring system's most dangerous state is confident and wrong.**

Every module you have studied is a defence against that state, at a different layer:

| Lesson | The defence |
|---|---|
| 1 Spool | Never lose the unrepeatable; count what you drop |
| 2 Engine | Encode physics in structure; make lateness visible |
| 3 Wire | Three quality states, because two are not enough |
| 4 Profiles | Unverified maps may not alarm |
| 5 Forwarder | Classify failures by recoverability |
| 6 Identity | Uncertainty is never promoted to confidence |
| 7 Supervision | Verification a human can fake is not verification |
| 8 Observability | Freshness before content; say what to do |
| 9 Calibration & time | Record what you were told and what you concluded |
| 10 Refusal | Declining is a legitimate output |
| 11 Alarms | Be quiet unless it matters |
| 12 Evidence | Prove later what you said and why |

And the meta-lesson, which this repository paid for in incidents rather than reading: **six times, complete and tested code did nothing because nothing invoked it.** Calibration, the bus capacity model, the scheduler, `publishMeasurements`, the dashboard process, and a metrics file nothing could read.

> Correct code that nothing runs is indistinguishable from code that does not exist — and it is worse, because it looks like coverage.
>
> Start at the physical world. Finish at the user. Check the floor as well as the roof.

---

## Final design questions

**1.** The three health questions are separate services with separate endpoints. A client asks for "one number, red/amber/green, for the boardroom". Design it — and defend whatever information you destroy in the aggregation, or defend refusing the request.

**2.** ADR-016 admits DDL rights defeat the audit trail. Design the next layer: how would you make wholesale deletion *detectable* by a party who does not trust the appliance's operator? Then say what that costs in an air-gapped installation with no external network.

**3.** `PROCESSING_VERSION` means old and new reports deliberately disagree. A contractor's lawyer has a v3 report; you have since shipped v4, which produces a different number for the same period. Walk through what you hand over and what you say. Is the old report still true?

**4.** The capstone. You are architecting a *new* appliance — different sensors, different structure, different client. Which three decisions from this repository would you carry over unchanged, and which one would you deliberately make differently? Justify all four, and be specific about the conditions under which your changed decision would be wrong.

---

*End of course. The questions are the point; the lessons were only scaffolding for them.*
