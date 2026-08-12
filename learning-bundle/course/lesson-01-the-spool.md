# Lesson 1 — The Spool

**Module:** `acquisition/src/qv_acq/spool.py`
**Supporting:** `forwarder.py`, `spool_cli.py`, ADR-003
**Incident:** `docs/known-limitations.md` — the outage of 2026-08-06

---

We start here because this module has been proven under conditions no test could
have simulated: sixteen hours with the database gone, 187,671 readings held,
**zero lost**. That is the most instructive artefact in the repository — and so
is the fact that everything around it failed while it worked.

---

## 1. Why it exists

Look at where it sits. Acquisition polls three WTVB01-485 sensors over RS-485 at
1 Hz. Downstream sit TimescaleDB, Redis, MQTT and a network. The spool is the
seam between them.

That seam exists because of a property of the **data**, not of the software:

> A sensor reading is a sample of a moment that will never recur.

Miss the poll slot at 09:14:22 and that moment is gone from the universe.
Everything downstream — the insert, the MQTT publish, the websocket push — is
*retryable*. The database will still be there in ten minutes.

So the appliance contains exactly one irreversible operation and a long tail of
reversible ones. The architectural question is where to put the boundary.

**Put the buffer precisely on the line between the unrepeatable and the
repeatable.** That is the entire design. Everything else in the file is
consequence.

---

## 2. What problem it solves

Backpressure — and specifically its **direction**.

Without a spool, a slow database propagates *backwards* into the serial polling
loop. The Modbus transaction on a bus has a 1-second timeout inside a fixed 1 Hz
cadence. If a writer blocks for 400 ms you do not get a late reading; you get a
**missed slot**. Miss enough slots and the record has holes.

Here is what makes this a structural-monitoring problem rather than a software
problem:

> The holes are correlated with the events you care about.

Site power dips, networks wobble and databases choke during storms, during
blasting, during the piling work next door. The moments this appliance exists to
capture are exactly the moments most likely to disturb its downstream. A design
that drops data under stress drops **precisely the data that matters**.

The spool inverts the direction of pressure. Downstream trouble becomes disk
consumption, not producer stalling.

---

## 3. What would happen if it were removed

Not "the appliance becomes less reliable". Concretely:

1. `engine.py` writes straight to Postgres, putting a network round-trip inside
   the bus cadence.
2. Any latency spike above the poll period causes **missed** samples — not
   delayed ones.
3. The sixteen-hour outage becomes sixteen hours of nothing, rather than sixteen
   hours of latency.
4. The appliance has **no way to know what it lost**, because the thing that
   would have counted it is the thing you removed.

Point 4 is the one juniors miss. A buffer is also an accounting mechanism.

---

## 4. The principles it demonstrates

### Never let the slow path stall the fast path

The structural rule of nearly all real-time systems. Here the fast path is a
1 Hz Modbus loop; on an MCU it is your ADC interrupt. Same law, different
timescale.

### Bounded resources, always

```python
max_rows: int = 500_000
```

An unbounded buffer is not a buffer. It is a crash you have deferred and made
harder to diagnose. There is no "just add RAM" on an appliance.

### Eviction policy is a value judgment, and must be written down

Read `_enforce_cap()`. It deletes *delivered* rows first. Only if that is not
enough does it touch undelivered ones — and then:

```python
# Never silent: a dropped undelivered record is lost data and the
# operator must be able to see that it happened.
self._bump("undelivered_dropped", cursor.rowcount or 0)
```

One counter in this entire system means **lost data**. Every other number means
latency. That distinction is the difference between an operator sleeping and an
operator driving to site at midnight.

### Durability is chosen, not defaulted

```python
self._db.execute("PRAGMA journal_mode=WAL")
self._db.execute("PRAGMA synchronous=FULL")
```

`FULL` costs an fsync per transaction. It is correct here because this component
exists *for the case where the machine dies unexpectedly*. Choosing `FULL` over
`NORMAL` is somebody deciding that fsync cost is worth power-cut survival — and
the repo proves it with a test that actually `kill -9`s mid-write rather than
asserting it. Acceptance case 14.

### Integrity at rest

Every row carries a SHA-256 of its payload. Without it, corruption is forwarded
as good data — the worst available failure mode, because it is indistinguishable
from a real measurement. `verify()` sweeps and counts `corrupt_rows`.

### Exactly-once is a lie

You get **at-least-once delivery plus an idempotent consumer**.

```sql
idempotency_key  TEXT NOT NULL UNIQUE
```

Crash-replay re-offers records that may already have landed. The constraint
makes that a no-op, counted as `duplicates_rejected` rather than hidden. Anyone
promising exactly-once delivery across a process boundary is either wrong or
redefining the term.

---

## 5. The design pattern

Four patterns composed:

| Pattern | Role here |
|---|---|
| **Store-and-forward** | The overall shape |
| **Write-ahead log** | SQLite WAL, durable before acknowledged |
| **Bounded queue with explicit eviction** | `max_rows`, delivered-first, counted drops |
| **At-least-once + idempotent sink** | `idempotency_key`, `batch_uid` |

Being able to name them **separately** is what lets you reason about which one
is failing. In the 2026-08-06 incident the store-and-forward was perfect and the
consumer side was broken in three independent ways.

---

## 6. Where this same pattern appears

| Domain | The same thing, different name |
|---|---|
| **Automotive** | AUTOSAR `NvM` and the Diagnostic Event Manager. A DTC with freeze-frame data must survive KL30 loss and be readable later: bounded event memory, oldest-first eviction, overflow explicitly flagged. CAN Tx queues expose overflow counters for exactly the `undelivered_dropped` reason. |
| **Aerospace** | A flight data recorder is this module in a titanium shell — bounded circular buffer, oldest-first, crash-survivable write path. ARINC 429 has no flow control at all, so every receiver buffers or drops, and DO-178C requires you to *state* which. |
| **Industrial / PLC / SCADA** | Called "store and forward" by name. An RTU buffers to local flash when the historian link drops, then replays with original timestamps. OPC UA Historical Access assumes it. This module is that pattern at appliance scale. |
| **Robotics** | DDS turns it into declared QoS: `HISTORY KEEP_LAST depth=N` is `max_rows`; `RELIABILITY RELIABLE` vs `BEST_EFFORT` is your retry policy; `DURABILITY TRANSIENT_LOCAL` is the buffer surviving a subscriber restart. ROS 2 made the eviction policy a contract instead of an accident. |
| **Linux kernel** | The `printk` ring buffer: bounded, overwrites oldest, and prints `** %u printk messages dropped **` — the same refusal to drop silently. Also `kfifo`, and NIC Tx rings whose drop counters surface via `ethtool -S`. |
| **RTOS firmware** | `xQueueSendFromISR()` returns a failure code. The junior ignores it; the professional increments a counter and exposes it. DMA ping-pong buffering is the same decoupling — the ISR fills one half while a slow consumer drains the other, and neither waits. |

If you learn to see this one pattern, you will recognise it in every embedded
system you ever open.

---

## 7. The lesson the incident actually taught

This is the part to remember.

> **The spool was the only component that worked perfectly, and the system still
> failed.**

It absorbed the outage exactly as designed. Then:

- **The watchdog killed the recovery.** `drain_once()` loops until the spool is
  empty; the caller pinged the watchdog once per drain. 84,000 records at
  roughly a second per batch of 200 is seven minutes of honest work against
  `WatchdogSec=120`. systemd SIGABRTed a forwarder that was working, every two
  minutes, for the whole recovery.

- **The retry ceiling stranded healthy data.** It cannot distinguish a poisonous
  record from a healthy one that met a long outage. 31,307 readings — two and a
  half hours from all three sensors — were parked for good, and no tool existed
  to release them.

- **The evidence was unreadable.** The backlog, dead-letter count and
  `undelivered_dropped` were written every second to a file mode `0600`, owned
  by a different service account than the dashboard. The one number that would
  have said *"nothing is lost, be patient"* was being written continuously to a
  file nothing else could open.

Notice what these three have in common. **All of them only appear when the
buffer is full** — which is the one state you are least likely to test, because
filling it takes an outage.

> **Design your buffers for the drain, not the fill.**

Write the test that starts with a full buffer. It is harder to arrange and it is
the only one that matters.

---

## 8. Design questions

Answer as an architect, not from the code. Two of these have no clean answer;
the argument is the point.

**Q1.** The eviction policy drops delivered rows first, then undelivered rows
oldest-first. For a structural-monitoring appliance, is oldest-first correct
among undelivered rows? Argue the other side — and state what you would need to
know about the *data* before you could decide.

**Q2.** `synchronous=FULL` costs an fsync per transaction. Suppose the sample
rate rises from 3/s to 500/s and fsync becomes the bottleneck. Name three
distinct ways to keep power-cut durability at that rate, and say what each one
trades away.

**Q3.** The retry ceiling could not tell "this record is poison" from "the
database was down for six hours". Design a mechanism that distinguishes them
automatically — then argue whether an appliance like this should actually ship
it, or keep the human in the loop.

**Q4.** The spool sits between acquisition and the database. Should there be a
*second* spool between the database and MQTT? Justify with a general rule for
where buffers belong — one you could apply to a system you have never seen.

---

## 9. Read for yourself

| File | What to look for |
|---|---|
| `acquisition/src/qv_acq/spool.py` | The module docstring states four design choices before any code. Note that each names its failure mode. |
| `acquisition/src/qv_acq/spool.py` → `_enforce_cap()` | Eviction order, and the counter that means loss |
| `acquisition/src/qv_acq/spool_cli.py` | An operator interface built *after* the incident that proved it was missing |
| `acquisition/tests/test_spool.py` | Especially the `kill -9` durability test and `TestDeadLetterRecovery` |
| `docs/decision-log.md` → ADR-003 | Why acquisition is never containerised — the decision that makes the spool necessary |
| `docs/known-limitations.md` | The full 2026-08-06 account |

---

## 10. Answers

Written after the questions on purpose. Argue your own case first — two of these
have no clean answer, and the reasoning is the whole exercise.

### A1 — Is oldest-first correct?

**The case against is stronger, and it comes from the same insight the spool was
built on.**

The spool only fills during an outage, and outages correlate with the events you
care about: storms, blasting, power disturbance, the piling rig next door. So the
readings oldest-first discards are the ones from *the beginning of the outage* —
and the beginning of the outage is when whatever caused it happened.

Lightning takes out the network at 02:00. The spool fills for 46 hours, hits the
cap, and begins dropping the oldest: the readings from the moment of the strike,
including the structure's response to it. You keep 46 hours of a quiet silo and
discard the one minute that mattered.

Newest-first is worse — you would stop recording the present and never be able to
trust a current reading during a long outage.

**Neither is right. The correct shape for SHM is decimation by significance:**

- never drop a reading that participated in an alarm or crossed a threshold
- never drop the first and last reading of a contiguous run, so gap boundaries
  survive
- thin the quiet remainder uniformly, degrading *resolution* rather than
  *coverage*

That is the seismograph pattern: a continuous low-rate stream plus triggered
high-rate windows.

**What you would need to know before deciding:**

1. *What fraction of readings are quiet?* Here, overwhelmingly — 0.006 to 0.05 g
   against a 0.5 g warning. Decimation would buy enormous headroom.
2. *Is anything downstream rate-sensitive?* **Decisive, and it kills the idea.**
   ADR-006 advertises spectra at 0.4x the measured rate. Non-uniform decimation
   invalidates every frequency claim the appliance makes. You cannot thin data
   that will be transformed.
3. *What outage is credible?* 46 hours of headroom at three readings a second.
4. *Does anything depend on contiguity?* The run-id and sequence machinery in
   ADR-010 suggests gap detection matters.

**Conclusion: oldest-first is defensible, but the stated reason is wrong.** It is
justified as "keep the recent" when the real justification is "keep the stream
contiguous so the spectral claims stay valid."

And the architect's answer: this policy has never executed —
`undelivered_dropped` stayed 0 through a sixteen-hour outage. **Do not redesign
an untested policy. Make reaching it visible.** Warn at 70% of cap, which turns a
silent data-loss decision into an operations decision while there is still a day
to act.

### A2 — Durability at 500 samples a second

**(a) Group commit.** Accumulate N readings or T milliseconds, one transaction,
one fsync. 500/s at 50 ms batches is 20 fsyncs a second.

*Trades away the guarantee itself.* You now lose up to T milliseconds on a power
cut. "Lose nothing" becomes "lose at most 50 ms" — a different promise that must
be written down as one. Also adds latency to the live path.

**(b) Move durability into hardware.** Enterprise NVMe with power-loss-protection
capacitors guarantees its own cache flush, making `synchronous=NORMAL` safe.

*Trades away visibility.* You now depend on a hardware property nothing in
software verifies. Someone fits a consumer SSD during a repair and durability
vanishes — no code change, no failing test, no symptom until the first power cut.
The worst class of dependency: invisible and correct-looking.

**(c) Own the log.** Append-only segments with `O_DSYNC`, fsync per segment,
torn-tail recovery — scan back from the end on restart and discard past the last
valid checksum.

*Trades away someone else's testing.* SQLite's WAL is exercised by millions of
installations; your log is exercised by you. You also lose SQL over the spool,
which `qv-spool status` depends on.

**Ranking:** (a) unless a bounded loss window is genuinely unacceptable — it
usually is acceptable, and saying so honestly beats pretending. (b) if it is not,
and you control procurement. (c) almost never, unless you ship enough units to
amortise proving it.

The distinction that matters: **(a) changes the guarantee; (b) and (c) preserve
it but relocate the risk** — into procurement, or into your own code. Only one of
the three requires updating the documentation, and that is the one people forget.

### A3 — Poison versus outage

**The discriminator is correlation.** A poisonous record fails while its
neighbours succeed; an outage fails everything.

**Tier 1 — status class.** A 4xx (422 validation, 400 malformed) means the record
is the problem and will never succeed: dead-letter it on the **first** failure,
not the tenth. A 5xx or refused connection means the sink is the problem and must
not count against the record at all. Today `_deliver()` treats 401/403 as fatal
and everything else uniformly, so a malformed envelope burns ten retries and a
database outage burns the retry budget of healthy data. **That is a concrete
defect.**

**Tier 2 — cohort correlation.** `retry_count` increments only when the failure
was idiosyncratic: other records in the same batch or window succeeded. A
whole-batch failure increments a *sink-level* counter and **freezes** the
per-record ones.

**Tier 3 — bisection.** If a batch of 200 fails while the sink is demonstrably
healthy, split and retry halves. Eight round trips isolates one record. That
produces a *proof* rather than an inference, and it is safe because delivery is
idempotent.

**Should it ship?** Tiers 1 to 3, yes — they are classification, not action. A 422
will never succeed; refusing to burn retry budget during an outage cannot lose
data.

**Automatic release of dead letters, no** — and not because humans are wiser.

The dead-letter state is the only place this appliance says *"I hold data I
cannot deliver and I do not know why."* If it can resolve that itself, the
sentence is never uttered. Automatic recovery would have silently fixed the
31,307 stranded readings on 2026-08-06, and nobody would have learned that a long
outage burns the retry budget of healthy data. The design flaw would still be
there, waiting for a worse day.

> **A mechanism that automatically hides the evidence of a design flaw prevents
> the design flaw from being fixed.**

The same reason ADR-015 makes a named person confirm a threshold.

The nuance that makes it shippable: automatic classification, plus automatic
retry for records *provably* classified as outage victims, keeping the human only
for the ambiguous residue. That shrinks the queue to actual poison, where a human
decision is meaningful rather than clerical.

### A4 — A second spool before MQTT?

**No.** The rule:

> **A buffer belongs at every boundary where the upstream side cannot be replayed,
> and nowhere else.**

| Boundary | Upstream replayable? | Verdict |
|---|---|---|
| Acquisition → database | No. The moment is gone for ever | **Buffer required** |
| Database → MQTT | Yes. The database *is* the record | **No buffer** |

If an MQTT publish fails, re-read the row and publish again. Which gives the crisp
form:

> **When upstream is replayable you do not need a buffer, you need a bookmark.**

A buffer is a copy; a cursor is a position. Copies must be kept consistent,
evicted, checksummed and recovered. A cursor is one integer.

A second spool here would be actively harmful. It duplicates the record and
creates a second place that can **disagree** with the database. ADR-019 makes MQTT
outbound-only precisely so a compromised broker cannot corrupt evidence — a spool
feeding MQTT puts a copy of that evidence outside the database's integrity
guarantees. And ADR-020 establishes the live path as *deliberately* lossy: a
dropped frame is acceptable because the durable path has it. Buffering the lossy
path contradicts the decision that made it lossy.

**The corollary, portable to systems you have never seen:** the cost of a missing
buffer is data loss; the cost of an unnecessary buffer is a **second source of
truth**. The second is usually worse, because it fails silently and you now hold
two answers to one question with no way to tell which is right.

What I would add instead: a per-topic cursor recording the last published
measurement id, so a broker outage recovers by replaying from the database. One
column, no duplication, no second truth.


---

*Next: **Lesson 2 — The acquisition engine: cadence and capacity.** One thread
per bus as a correctness requirement rather than an optimisation, and why a
capacity model that nothing calls is worse than no model at all.*
