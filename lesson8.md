# Lesson 8 — Observability

**Modules:** `metrics.py`, `backend/app/Services/DeliveryHealth.php`, `spool_cli.py`
**Related decisions:** ADR-007

---

## Why it exists

Lesson 7 ended on the rule that unwired code is worse than absent code. This lesson is its twin:

> **A number nobody can read is worth nothing, and a number nobody looks at is worth almost nothing.**

The appliance proved both halves. During the sixteen-hour outage it *knew* that nothing was lost. The forwarder wrote the backlog, the dead-letter count and `undelivered_dropped` to a file every second. The file was mode `0600`, owned by `quakevault-acq`, and the process serving the dashboard ran as a different account.

The one number that would have told an operator *"your data is safe, be patient"* was being written continuously to a file nothing else could open.

---

## What problem it solves

### 1. Deciding what deserves a counter

The useful discipline is the question from Lesson 2: **for every way this component can degrade or lose data, what number goes up?**

Apply it to the spool and you get the counter set the repo actually has:

| Counter | What it answers |
|---|---|
| `backlog` | How far behind are we? |
| `delivered_total` | Is it moving? |
| `duplicates_rejected` | Is replay working as designed? (This one exposed the ADR-010 bug.) |
| `dead_letters` | What is parked and needs a human? |
| `undelivered_dropped` | **Did we lose data?** |
| `missed_polls` | Did we fail to sample on time? |
| `measured_hz`, `jitter_ms` | Are we actually sampling at the configured rate? |

Note the hierarchy. Exactly one of those means irreversible loss. `qv-spool status` makes the hierarchy explicit rather than leaving it to the reader:

```
  lost                none
```

and when it is not none:

```
  LOST                12,000 undelivered records dropped at the cap
                      These are gone. The spool filled faster than it drained.
```

**Rank your telemetry by consequence, not by availability.** Most dashboards show what was easy to instrument. A good one leads with the number that changes what you do.

### 2. Measured, not configured

ADR-007 again, because it belongs to observability as much as to scheduling:

> Bus contention, retries, and USB latency mean configured rate is a request, not a fact.

`measured_hz` is computed from observed completions. Both numbers are stored; the UI shows measured. Alarms and spectral gating use measured.

This is a general instrumentation law: **never report an intention as an observation.** The configured rate is what you asked for. Reporting it as though it were achieved converts a performance problem into a data-integrity problem, and hides it permanently.

### 3. Telemetry has its own freshness problem

`DeliveryHealth.php` contains the subtlest idea in the module:

```php
// Stale first. Every other number in the file is a claim about a moment
// that may be hours ago, and reporting a healthy backlog from a dead
// forwarder is worse than reporting nothing.
if ($age > self::STALE_SECONDS) {
    return [ 'state' => 'fail', ... ];
}
```

The metrics file says `backlog 12, all fine`. It is three hours old. The forwarder is dead.

A naive health check reads the file, sees a small backlog, and reports green — **using the corpse's last words as evidence of life.**

> **Every piece of cached or reported state must carry its own age, and the age must be checked before the content.** Freshness is a precondition for interpreting a value, not an attribute of it.

This is the same failure class as Lesson 3's `IMPLAUSIBLE`: a number that is well-formed and wrong. Here the malformation is temporal.

### 4. Separate questions must have separate answers

The service exists because sensor health could not express the outage:

```php
 *   the readings are not arriving     - and they are safe on disk, be patient
 *   the readings are not arriving     - and they are being lost, act now
```

`SensorHealth` sees silence and says *"Silent for 900s. A dead sensor and a still structure look identical on a chart."* True in general. Wrong here — and wrong in the expensive direction, because it sends someone up a silo to check a cable that is not broken.

And then the discipline of *not* merging them:

```php
// Not folded into `status`. A backlog is not a sick sensor, and
// collapsing the two would let a patient, correctly-working spool
// turn every instrument on the page amber.
```

**Aggregation destroys information, and the information it destroys is usually the diagnostic information.** A single overall health light is comforting and nearly useless: when it goes amber you still have to ask why, and by aggregating you threw away the answer.

### 5. Telemetry is for humans under pressure

Compare two designs for the dead-letter count.

*Before:* a Prometheus gauge, `quakevault_forwarder_dead_letters 31307`. Technically complete. 31,307 readings sat behind it.

*After:*

```php
'%s reading(s) are parked past the retry ceiling. They are still on
disk and nothing is lost, but they will not be delivered until an
operator releases them. A long outage strands healthy readings this way.'
```

with `'action' => 'qv-spool retry-dead-letters --confirm'`.

**A metric states a fact. Telemetry for operators must state a fact, its consequence, and the next action.** The person reading it is not the person who wrote it, may be under pressure, and may be reading it at 3 a.m.

The banner also refuses to be furniture:

```tsx
// Nothing to say when it is working. The absence is the good news.
if (!delivery || delivery.state === 'pass') return null
```

An indicator that is always visible stops being read, and then it is not read on the day it matters.

---

## What would happen if it were removed

| Remove | Consequence |
|---|---|
| `undelivered_dropped` | You cannot answer "did we lose data?". The only question that matters becomes unanswerable. |
| `duplicates_rejected` | ADR-010's 98-measurement loss stays invisible forever. |
| `measured_hz` | Silent rate degradation. Every downstream analysis wrong by an unknown factor. |
| Staleness check | A dead forwarder reports healthy using stale numbers. |
| Separate delivery health | A safe backlog is reported as a failing sensor. Wrong truck roll. |
| The action string | An operator sees a number they cannot act on — which is what happened. |
| Readable file permissions | All of the above, technically present and practically absent. |

---

## The embedded principles

**1. Instrument the failure modes, not the happy path.** Counting successes is easy and rarely useful. Count the ways you degrade.

**2. Distinguish latency from loss, loudly.** They demand different human responses; conflating them produces either panic or complacency.

**3. Report observations, never intentions.**

**4. Freshness before content.** Check the age of any reported state before believing it.

**5. Do not aggregate away the diagnosis.** Provide the roll-up *and* the components.

**6. Telemetry must be reachable by whoever needs it.** Permissions, ownership and transport are part of the design, not deployment trivia.

**7. Say what to do, not only what is true.**

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Counter vs gauge** | monotonic totals vs point-in-time backlog |
| **Health check with staleness gate** | `DeliveryHealth::current()` |
| **Health taxonomy** | pass / warn / fail / unknown, with `unknown` distinct from `fail` |
| **Separation of concerns in reporting** | sensor vs delivery vs structure |
| **File-based metrics exposition** | Prometheus textfile, atomic replace |
| **Null object / silent-when-healthy UI** | `DeliveryBanner` returns null |

Two implementation details worth stealing: metrics are written to a temp file and `os.replace`d, so a reader never sees a partial file — atomicity in observability, not just in storage. And `unknown` is a distinct state from `fail`: "I could not determine this" is different from "this is broken", and merging them causes either false alarms or false confidence.

---

## Where you will meet this again

### Automotive
- **UDS DTCs with freeze frames and extended data**: a fault code plus the conditions at the moment it set plus occurrence counters and aging. The industry concluded decades ago that "a fault happened" is not enough — you need when, how often, and under what conditions.
- **CAN controller TEC/REC counters** exposed for diagnostics, so bus health is measurable before it is fatal.
- **Service 0x19 subfunctions** exist precisely to let a technician ask focused questions rather than read one aggregate light.

### Aerospace
- **ARINC 604 BITE and Central Maintenance Computers** — correlating faults across LRUs so the *cause* is reported rather than every symptom. The anti-aggregation lesson, industrialised.
- **ACMS / FOQA** continuous parameter recording, distinct from the FDR, for trend monitoring rather than accident investigation.
- **Master Caution / Master Warning with a recall page**: a roll-up *plus* the components. Never the roll-up alone.

### Industrial / PLC
- **PLC diagnostic buffer** — timestamped ring of events surviving power cycles.
- **NAMUR NE 107** classifies device status as Failure / Function Check / Out of Specification / Maintenance Required. Four states, deliberately, for the same reason `unknown` is separate from `fail` here.
- **Sequence of Events recorders** with millisecond timestamps, because in a cascading trip the *order* is the diagnosis.

### Robotics
- **`diagnostic_msgs`** with per-component status and key/value pairs, aggregated by an analyzer that keeps the components visible.
- **Nav2 / MoveIt lifecycle and behaviour-tree introspection** — you can see which node in the plan is failing, not just that planning failed.

### Linux kernel
- **`ethtool -S`** per-driver counters: `rx_dropped`, `tx_fifo_errors`, and more, each naming a distinct failure mode rather than one `errors` total.
- **`/proc/interrupts`, `/proc/pressure/*` (PSI)** — PSI is a particularly good example of measuring *degradation* rather than failure.
- **`printk`'s dropped-message counter** — the kernel refusing to lose data silently, again.
- **tracepoints and `perf`** — instrumentation designed to be present in production, not only in a debug build.

### RTOS firmware
- **FreeRTOS `uxTaskGetStackHighWaterMark()`** — measuring headroom before it becomes a fault, the same instinct as `bus_utilisation` and `MAX_SUSTAINED_UTILISATION`.
- **Queue overflow counters and `xQueueSendFromISR` return codes.** The junior ignores the return code; the professional counts the failure. This is the single most common observability sin in firmware.
- **Reset-reason registers** — the MCU tells you *why* it restarted: watchdog, brown-out, software, pin. Without it, a rebooting product is unfixable.

---

## The connective tissue

Lesson 7 asked who guarantees the system is running. Lesson 8 asks how anybody could tell.

The strongest idea, and the one that generalises furthest beyond this repository:

> **Design the telemetry from the incident backwards.** Imagine the 3 a.m. call. Write down the sentence you would want the appliance to have already said. Then check whether anything in the system is capable of producing that sentence — and remember that "capable" includes being readable by the process that must say it.

Every observability gap in this project failed that test. The data existed. The sentence did not.

---

## Design questions

**1.** `DeliveryHealth` reads a file written by another service account. That coupling is a path, a format, and a permission bit — all deployment concerns leaking into an application service. Design two alternatives (the forwarder pushes its health to the API; the API queries the spool directly) and argue which you would ship on an appliance, and which at a fleet of 400.

**2.** The staleness threshold is 120 seconds, and the forwarder writes per batch. What happens on a healthy but *idle* appliance where batches are rare? Is a heartbeat-on-idle needed, and if so how do you distinguish "idle and fine" from "stuck and quiet"?

**3.** `undelivered_dropped` is the only counter that means loss — but it counts *records*, not *time*. For a structural monitoring appliance, is that the right unit? Design what you would actually want to report to a client who asks "what did I miss?", and say what the appliance would need to record to answer it.

**4.** The banner is silent when healthy so it does not become furniture. But that means an operator has no way to distinguish "delivery is fine" from "the banner is broken". Design the resolution. Note that "always show a small green tick" reintroduces the furniture problem — so justify whatever you choose.

---

*Next: Lesson 9 — Calibration and Time: correcting what the sensor said, and knowing when it said it.*
