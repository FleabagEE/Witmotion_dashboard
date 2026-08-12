# Embedded Systems Architecture — Twelve Lessons

*Taught entirely from this repository.*

---

## What this course is

Not a tour of the code. A study of the **decisions** in it — why each module
exists, what it would cost to remove, and which principle it embodies that will
still be true when the language, the framework and the sensor are all gone.

The material is a real appliance that runs on real hardware and has failed in
real ways. Every lesson below is anchored to files you can open, and to
incidents recorded in `docs/known-limitations.md` with dates. Nothing here is
hypothetical.

## Who it is for

Someone who wants to be a **firmware architect** rather than a firmware
programmer. The distinction is not skill with a compiler. It is the ability to
answer, before writing anything:

- What is the irreversible operation in this system?
- What happens to it when everything downstream fails?
- How will anyone know?

Those three questions produce most of what follows.

## Progress

| Lesson | Narrative | Questions | Answers |
|---|---|---|---|
| 1 — The Spool | ✅ | ✅ | ✅ |
| 2 — The acquisition engine | ⬜ | ✅ | ✅ |
| 3 — The wire | ⬜ | ✅ | ✅ |
| 4 — Profiles as data | ✅ | ✅ | ✅ |
| 5 — The forwarder | ✅ | ✅ | ✅ (A4 verified on the 2026-08-12 reboot) |
| 6 — Identity | ✅ | ✅ | ✅ (Q1 tested on hardware) |
| 7–12 | ⬜ | ⬜ | ⬜ |

Lessons 2 and 3 currently hold their design questions and worked answers; the
teaching narrative for each is still to be written.

## How to use it

Read one lesson. Answer its design questions before opening the next — several
have no clean answer, and the argument is the point. Then read the actual
source files listed and check whether you agree with what was done.

Disagreeing with this repository is a valid outcome. Several of its decisions
were wrong for weeks before anyone noticed.

---

## The syllabus

### Part I — The data path

Where the physics meets the software, and where mistakes are unrecoverable.

**Lesson 1 — The Spool: store-and-forward** · [lesson-01-the-spool.md](lesson-01-the-spool.md)
`acquisition/src/qv_acq/spool.py`

Bounded buffers, eviction as an explicit value judgment, durability chosen
rather than defaulted, and the difference between *latency* and *loss*. Anchored
to the outage of 2026-08-06: sixteen hours down, 187,671 readings held, none
lost. Pattern: write-ahead log feeding a bounded producer–consumer queue,
consumed at-least-once with an idempotent sink.

**Lesson 2 — The acquisition engine: cadence and capacity** · [lesson-02-the-acquisition-engine.md](lesson-02-the-acquisition-engine.md)
`engine.py`, `throughput.py`, `portlock.py`

One thread per bus and why that is not an optimisation but a correctness
requirement on a half-duplex line. Budgeting a shared medium before you build on
it — `MAX_SUSTAINED_UTILISATION = 0.65` and where that number comes from. Why a
capacity model that nothing calls is worse than none at all.

**Lesson 3 — The wire: framing, CRC, and where trust ends** · [lesson-03-the-wire.md](lesson-03-the-wire.md)
`crc.py`, `decode.py`, `encode.py`, `client.py`

Every byte from a sensor is untrusted input. Corruption that decodes to a
plausible number is the worst failure mode available, because it is
indistinguishable from a measurement. Range gates, quality flags, and the
discipline of deciding what "bad" means before you see any.

**Lesson 4 — Profiles as data, not code** · *(one of the three that matter most)*
`profiles/schema.py`, `profiles/loader.py`, ADR-004, ADR-005

A register map is configuration, not a `switch` statement. And the decision that
separates instrumentation from a toy: **an unverified register map may not drive
an alarm.** A wrong map returns plausible numbers instead of failing, so the
system must refuse to act on maps whose provenance it cannot establish.

**Lesson 5 — The forwarder: retry, backoff, circuit breaking**
`forwarder.py`, `forwarder_service.py`

At-least-once delivery, exponential backoff, the retry ceiling, and the
dead-letter problem: a ceiling cannot distinguish a poisonous record from a
healthy one that met a long outage. 31,307 readings were stranded by exactly
that on 2026-08-06.

### Part II — Staying alive

An appliance is judged on what it does at 03:00 with nobody watching.

**Lesson 6 — Identity: naming things that move** · [lesson-06-identity.md](lesson-06-identity.md)
`discovery.py`, `portlock.py`, `deploy/udev/`, ADR-008, ADR-010

Why `/dev/ttyUSB0` is not a name, why CH341 adapters have no serial numbers, and
why identity is derived from physical USB topology instead. Advisory locks
against two processes on one bus. Run ids, and what "the same sensor" means
across a reboot.

**Lesson 7 — Supervision: watchdogs and lifecycle**
`sdnotify.py`, `deploy/systemd/*.service`

Watchdog timers, heartbeats, restart policy, dependency ordering, and the
failure this repo actually suffered: a watchdog that killed a recovery because
nobody reasoned about how long a *full* drain takes. `WatchdogSec=120` against
seven minutes of honest work.

**Lesson 8 — Observability: counting the right things**
`metrics.py`, `backend/app/Services/DeliveryHealth.php`

What to count, what a counter obliges you to do, and the sharpest lesson in the
repository: the one metric that would have said *"nothing is lost, be patient"*
was written every second to a file mode `0600` that no other process could open.

### Part III — From volts to meaning

**Lesson 9 — Calibration and time**
`calibration.py`, `measurement.py`, ADR-010

Monotonic clocks versus wall clocks, and why a measurement carries both.
Sequence numbers. What calibration corrects and what it cannot. Why an
uncalibrated sensor reading 0.9866 g is *honest*, not faulty.

**Lesson 10 — The refusal to compute** · *(one of the three that matter most)*
`dsp.py`, ADR-006, ADR-014

Polled Modbus is non-uniformly sampled, so Nyquist does not apply. The appliance
advertises 0.4× the **measured** rate, not the configured one, and refuses
spectra beyond it. Knowing what your data cannot support is worth more than any
algorithm you can implement.

**Lesson 11 — The alarm state machine** · *(one of the three that matter most)*
`AlarmEvaluator.php`, ADR-013, ADR-015, ADR-017

Persistence, hysteresis, debounce, latching — four distinct mechanisms that
juniors collapse into one threshold comparison. Then the harder idea: a
threshold nobody has confirmed by name may not notify anyone, and the value of a
notification system is in **what it refuses to send**.

### Part IV — The human

**Lesson 12 — Three questions and the evidence trail**
`SensorHealth.php`, `DeliveryHealth.php`, `StructureMovement.php`,
`AuditLogger.php`, ADR-016, ADR-018

"What did the structure do", "can the instruments be believed", and "are the
readings arriving" are three different questions with three different answers,
and collapsing any two makes the product lie. Append-only audit. Reports as
evidence, therefore reproducible. Closing lesson: the failure mode that is not a
bug in any module.

---

## The through-line

Six times in this repository, a component was complete, correct, tested — and
invoked by nothing. Calibration. The bus capacity model. The scheduler.
`publishMeasurements`. The dashboard process itself. Each time the system looked
healthy while doing nothing.

Once, every part worked and no code path turned the data into a sentence a human
could read.

The conclusions, in the order they were learned and in increasing generality:

> **Start at the physical world, and finish at the user.**
> A test that exercises a component proves the component. Only a test that
> starts at a sensor and ends at a screen proves the appliance.

> **Check the floor as well as the roof.**
> A post-reboot check that asks the application about itself will pass while the
> database underneath it is dead.

> **A verification a human can satisfy by hand is not a verification.**
> "Enabled and active" stays true when you start the unit yourself while
> debugging.

If you take nothing else from twelve lessons, take those three.

---

## Source material

| Document | What it holds |
|---|---|
| `docs/decision-log.md` | 25 ADRs — the decisions, their context and their cost |
| `docs/known-limitations.md` | Every fault found, dated, with what it cost and what changed |
| `docs/acceptance-results.md` | 22 hardware-in-the-loop cases and, importantly, the ones that are **not tested** |
| `docs/register-maps.md` | Provenance of every register this appliance reads |
