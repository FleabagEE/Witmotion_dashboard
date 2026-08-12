# Lesson 5 — The Forwarder: Retry, Backoff, Circuit Breaking

**Modules:** `acquisition/src/qv_acq/forwarder.py`, `forwarder_service.py`
**Supporting:** `spool.py`, ADR-010, `deploy/systemd/quakevault-forwarder.service`

---

Lesson 1 built the spool: the place a reading goes so that nothing downstream can
destroy it. This lesson is about the process that empties it, which is a
different job with a different failure mode.

The spool's job is to **never lose**. The forwarder's job is to **never give up
and never make things worse by trying too hard.** Those pull in opposite
directions, and most of the design is about where the line sits.

---

## Facts to have in front of you

```
batch_size            200 records per POST
request_timeout       20.0 s
backoff_seconds       2.0     doubling per consecutive failure
backoff_max_seconds   300.0   the ceiling
```

```python
def backoff_delay(self) -> float:
    delay = self.config.backoff_seconds * (2 ** (self._consecutive_failures - 1))
    return min(delay, self.config.backoff_max_seconds)
```

2, 4, 8, 16 … 256, then 300 for ever. Eight failures to reach the ceiling.

And the number the lesson is really about:

```
2026-08-06:  187,671 readings spooled during a 16-hour outage
              31,307 of them crossed the retry ceiling and were parked
```

---

## Why a separate process at all

The forwarder is a different **process** from acquisition, not a thread. Lesson 2
covered why the three buses are threads; this is the other half of that decision.

Threads give you "does not block". Processes give you "**cannot harm**".

The forwarder does the things that go wrong in ways you cannot predict: it opens
sockets, it parses responses, it holds TLS state, it waits on a server somebody
else operates. A hung HTTP call, a memory leak in a client library, a `SIGKILL`
from a watchdog — none of it may reach the thing polling sensors.

This was watched happening. On 2026-08-06 the forwarder was killed by its
watchdog **every two minutes for hours** while acquisition polled all three
sensors without missing a reading. Same machine, same disk, different processes.

> Put a process boundary exactly where irreversible work meets retryable work.

---

## Three mechanisms, three different failures

They are easy to confuse because all three are "stop trying so hard". They defend
against different things.

| Mechanism | Defends against | Where |
|---|---|---|
| **Retry** | a transient failure — one dropped packet, one restart | per record, `retry_count` |
| **Backoff** | *you* being the problem — hammering a struggling server | per consecutive failure, exponential |
| **Circuit breaking** | wasting the bus on something known dead | per sensor, in the engine |

Backoff is the one whose purpose is most often misunderstood. It is **not**
politeness and it is not about your own efficiency. A server that is failing
because it is overloaded gets *more* overloaded if every client retries
immediately, and every client retrying immediately is how a brief degradation
becomes an outage. Exponential backoff is the client's contribution to the
server's recovery.

The 300-second ceiling matters for the opposite reason. Unbounded doubling means
that after a long outage the first retry lands hours after the server came back.
The ceiling bounds *recovery latency* — the appliance notices the world is
healthy again within five minutes, for ever.

---

## At-least-once, and why that is the right choice

The forwarder marks a record delivered **only after the API confirms**. If it
dies between the POST landing and the mark being written, the record is sent
again.

That is at-least-once. The alternative — mark first, then send — is at-most-once,
and loses data on exactly the same crash.

Neither is exactly-once, which is not available over a network you do not
control. What *is* available is at-least-once delivery plus **idempotent
receipt**, which is indistinguishable from exactly-once at the far end and is
what ADR-010's key provides:

```
appliance : run_id : sensor : group : sequence
```

The design rule underneath:

> **Choose the failure you can repair downstream.** A duplicate can be discarded
> by a key. A lost reading cannot be recovered by anything.

---

## The retry ceiling, and the day it did the wrong thing correctly

A record that has failed *N* times stops being retried. Without that, one
malformed record at the head of an ordered queue blocks everything behind it for
ever — a poison message, and the reason every durable queue has this feature.

The ceiling cannot tell a poisonous record from a healthy one that met a long
outage.

On 2026-08-06 TimescaleDB was down for sixteen hours. Every record spooled during
it was retried, failed, retried, failed. **31,307 of them burned their entire
retry budget on an outage that had nothing to do with them** — two hours and
thirty-eight minutes of real measurements from all three sensors, sitting intact
on disk, counted by a metric, and reachable by nothing.

The appliance could tell you exactly how much data it had stranded. It offered no
way to get it back.

That is the defect worth studying, because **nothing was broken**. The ceiling
worked. The spool worked. The metric worked. Every component did what it was
specified to do, and the system as a whole lost 31,307 readings to a design that
had never been asked what happens when the ceiling is reached by healthy data.

The fix was `revive_dead_letters()`, and three decisions inside it are the lesson:

**It is idempotent.** Delivery is idempotent at the API, so a record that did
land before its counter was bumped comes back as a duplicate, not a double write.
An operator who runs it twice under stress does no damage.

**It is bounded.** `limit` exists so 31,307 records do not arrive as one
thundering herd at a server that has just come back from being down.

**It is not automatic.** This is the interesting one:

> *If a record is genuinely undeliverable this will cycle it to the ceiling
> again, and an operator choosing to retry is the difference between recovering
> an outage and hiding a real fault.*

Automatic revival turns the ceiling into a slow infinite loop and destroys the
signal it exists to produce. The operator's judgement is a required input,
because only they know whether the outage is over.

---

## Design questions

**Q1.** Backoff is per-*consecutive-failure*, held in the forwarder's memory, and
resets on the first success. What breaks if you make it per-record instead? And
what breaks if you persist the counter across restarts?

**Q2.** `batch_size = 200`. Argue for 20 and for 2000. Then say what actually
decides it — and note that the number is not primarily about throughput.

**Q3.** A dead letter is invisible until somebody looks at a metric. Design how
the appliance should surface 31,307 stranded readings — bearing in mind that this
repository has three separate instances of an alarm nobody read.

**Q4.** The forwarder crash-looped four times on the reboot of 2026-08-12,
because it was ordered behind acquisition and behind nothing it writes to. It
always recovered and nothing was lost. Was fixing it worth doing? Argue no first.

---

## Answers

### A1 — Where the failure counter lives

**Per-consecutive-failure, in memory, reset on success** is right, and both
alternatives are worse in instructive ways.

**Per-record backoff** confuses two questions. A record's `retry_count` asks *is
this record bad?* The backoff delay asks *is the far end bad?* Those have
different answers and different remedies. Make the delay per-record and a healthy
batch behind a briefly-failing server acquires 200 independent schedules, all
firing at different times, and the appliance loses the one behaviour it wants
during an outage: **quiet, then a single decisive retry.**

**Persisting the counter across restarts** is the more tempting mistake, and it
reintroduces exactly what ADR-010 rejected. A persisted counter is state that can
be lost, corrupted, or resumed wrong. Worse, it is state whose *only* effect is
to make the appliance slower to recover: a forwarder restarted after a long
outage would begin at a 300-second delay because of failures that happened before
it existed. The far end may be perfectly healthy now. The counter is a claim
about the present, and the present is exactly what a restart has no information
about.

The general rule:

> **State that only describes "how badly is it going right now" should not
> survive the process that measured it.**

Contrast with the spool, which persists absolutely, because it describes
something irreversible. Backoff describes a mood; the spool describes facts.

### A2 — What decides the batch size

**For 20:** a failure costs less. If the POST times out at 20 s, you have risked
20 records, not 2000. Memory per request is trivial. A partial outage degrades
gently.

**For 2000:** far fewer round trips. During drain-down after a sixteen-hour
outage, 187,671 records at 20 per batch is 9,384 requests; at 2000 it is 94. The
measured drain rate was **~2,600 rows/s** — that is a batching achievement, not a
database one.

**What actually decides it** is neither throughput nor memory. It is:

1. **The request timeout.** A batch must be comfortably completable inside
   `request_timeout = 20 s` on the worst hardware you ship to, or you get
   timeouts that look like server failure, which trigger backoff, which slows
   drain-down, which lengthens the outage you are recovering from. This is the
   binding constraint.
2. **The retry blast radius.** A failed batch re-sends whole. Large batches mean
   a single flaky record re-transmits 1,999 healthy ones alongside it, repeatedly.
3. **The API's own limits.** The ingest endpoint bounds request size deliberately
   — *"so one request cannot be used to exhaust memory"* — and the client must sit
   inside a limit the server enforces, not guess at it.

200 sits where a batch completes in well under 20 s and a bad record drags 199
others rather than 1,999. It is a compromise between recovery speed and retry
waste, and it should be **measured on the target hardware**, not chosen.

### A3 — Surfacing a dead letter

The hard part is not detection. `dead_letters()` already returns the count; the
appliance knew the number all along. The hard part is that **this repository has
proven three times that an alarm nobody reads is indistinguishable from no
alarm**:

- the mosquitto healthcheck failed 7,665 consecutive times against a working
  broker;
- the SHM validation timers failed every five minutes for six days;
- `systemd-analyze verify` printed the `~@resources` warning on every run, for
  months.

So a design that adds a fourth channel of unread output is not a design. What
distinguishes a signal that gets read:

**It must appear where somebody is already looking.** The dashboard, next to the
data — not a log, not a metric endpoint, not a systemd unit state. The client
opens the dashboard; nobody opens `journalctl` unprompted.

**It must be phrased as a loss, in units the reader cares about.** Not
`dead_letters: 31307`. *"2 h 38 min of measurements from all three sensors are on
disk and not in the database."* The first is a number; the second is a fact
somebody will act on.

**It must be actionable in the place it appears**, with the remedy attached —
because the remedy is deliberately manual, and an operator who has to go and find
out how to revive dead letters will not.

**It must not be able to be true and invisible.** The condition should be part of
whatever already says the appliance is healthy, so that "healthy" cannot be
displayed while 31,307 readings are stranded. That is the same failure as
`quakevault-dashboard` reporting `active` while serving HTTP 500 — a green light
whose definition is narrower than the reader assumes.

### A4 — Whether a self-healing crash loop is worth fixing

**The case for no**, and it is not weak. The forwarder recovered on its own every
time. Nothing was lost — the spool held everything. `RestartSec=5` cannot trip
`StartLimitBurst=5` in 10 s, because the restarts are further apart than the
limit can measure, so it cannot even fail permanently. The fix is a
systemd ordering change on a working appliance, and every change to a working
appliance carries risk. That risk is not zero, and the benefit is aesthetic.

**Why it was fixed anyway** — two reasons, and the second is the real one:

**A traceback at every boot teaches people that tracebacks at boot are normal.**
The next engineer sees `ConnectionRefusedError` in the boot log, remembers it is
expected, and stops reading. The one after that inherits a boot log with a known
harmless traceback in it, and does not know which line is the harmless one.

**`NRestarts` is the instrument that detects real crash loops.** It is what
caught acceptance case 8 recording PASS while the process was crash-looping and
taking all three sensors down for ten seconds. Four *expected* restarts on every
boot is noise injected directly into the one signal that detects the thing you
most need to detect.

That is the general principle, and it is worth more than the specific fix:

> **A diagnostic signal with known-benign noise in it has been disabled, slowly,
> by people acting reasonably.**

The same argument covers the mosquitto healthcheck, the SHM timers and
`systemd-analyze verify`. Every one of them was ignorable for a good reason, and
every one of them stopped working as a signal the moment it became ignorable.

---

## What to take away

1. **Retry, backoff and circuit breaking defend against three different
   failures.** Retry: transient fault. Backoff: you being the problem. Breaker:
   spending a shared resource on something known dead.
2. **Choose the failure you can repair downstream.** At-least-once plus an
   idempotency key beats any attempt at exactly-once over a network you do not
   own.
3. **A retry ceiling cannot tell poison from a long outage.** If you have one,
   you need a deliberate, idempotent, bounded, *manual* way back.
4. **State describing "how badly is it going right now" should not outlive the
   process that measured it.** Persist facts; do not persist moods.
5. **Known-benign noise disables a signal.** Fix the noise even when it is
   harmless, because the signal is what you will need at 03:00.

---

## Where this pattern appears elsewhere

- **Distributed systems:** exponential backoff with jitter is the canonical
  client-side contribution to server recovery; AWS and Google both publish it as
  a requirement rather than a courtesy. Kafka's idempotent producer uses
  `producer_id + epoch + sequence`, which is structurally identical to ADR-010's
  run id, arrived at independently.
- **Automotive:** CAN controllers implement error-active → error-passive →
  bus-off as a hardware state machine — a circuit breaker in silicon, and
  recovery from bus-off is deliberately not automatic in most stacks, for the
  same reason `revive_dead_letters()` is manual.
- **Industrial:** SCADA historians buffer to local storage when the link drops
  and backfill on reconnection; store-and-forward is the defining feature, not an
  add-on.
- **Aerospace:** flight data recorders never depend on a downlink. The recording
  path and the transmission path are separated for exactly the reason acquisition
  and the forwarder are separate processes.
- **RTOS and kernel:** deferred work — bottom halves, tasklets, workqueues —
  exists to keep the irreversible, time-critical half short and push everything
  retryable somewhere it can take as long as it needs.

---

## Design questions for you

Answer these before Lesson 6 (or before revisiting it — Lesson 6 is already
written).

1. Backoff here has **no jitter**. With one appliance that is harmless. Describe
   the failure mode with two hundred appliances reporting to one server, and say
   whether you would add jitter to this codebase today.

2. A3 argues a dead-letter warning must appear where somebody is already looking.
   Take that seriously and design it end to end for this appliance: where exactly
   on the dashboard, what it says, what the operator clicks, and what stops it
   becoming the fourth ignored alarm.

3. The forwarder marks a record delivered only after the API confirms. Suppose
   the API returns 200 but its own database write is later rolled back. Which
   layer should detect that, and what evidence would let anybody notice at all?
   (`IngestService::evaluateAlarms()` sitting outside the ingest transaction is
   the same question from the other side.)

4. The retry ceiling is a number. Whatever you choose, some outage is longer than
   it. Given that, is the ceiling worth having — or is the right design an
   unbounded queue with a poison detector that identifies *why* a record fails
   rather than counting *how often*? Design the detector, then say what it costs.
