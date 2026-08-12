# Lesson 2 — The Acquisition Engine

**Module:** `acquisition/src/qv_acq/engine.py`
**Supporting:** `throughput.py`, `portlock.py`, `config.py`

> **Status:** design questions and answers only. The teaching narrative
> (cadence, one thread per bus, the capacity budget) is still to be written.

---

## Facts to have in front of you

Read these out of the code before arguing about any of it.

| Fact | Where |
|---|---|
| The breaker is per **(sensor, group)**, not per sensor | `TaskRuntime`, engine.py |
| The WTVB01-485 profile has **six** register groups | `motion` 10 Hz, `device_time`, `condition_x/y/z`, `fault_diagnosis` at 1 Hz |
| Three sensors on one bus is therefore **18 breakers** | 3 × 6 |
| `breaker_failure_threshold: 3`, `breaker_cooldown_seconds: 5.0` | `deploy/quakevault/acquisition.yaml` |
| `timeout: 1.0`, `retry_max_attempts: 2` | same |
| Bus serialisation is by construction | `ThreadPoolExecutor(max_workers=1)` |
| Cross-process exclusion is **advisory** | `PortLock` |
| `MAX_SUSTAINED_UTILISATION = 0.65` | `throughput.py` |
| `DEFAULT_TURNAROUND_MS = 5.0` | `throughput.py` |

---

## Design questions

**Q1.** The circuit breaker is keyed on consecutive failures of a device.
Suppose the *bus* fails — a broken A/B pair, or the adapter unplugged. Every
sensor on it fails independently and each opens its own breaker. Describe what
that costs, design a breaker hierarchy that handles device-level and bus-level
faults distinctly, and name the new failure mode your hierarchy introduces.

**Q2.** `schedule_next()` counts missed slots. Is catch-up or realignment
correct for a structural monitoring appliance? Contrast the two, and choose for
(a) this silo, (b) a servo control loop, (c) a billing meter.

**Q3.** `throughput.py` bases its estimate on `DEFAULT_TURNAROUND_MS = 5.0`,
"measured per device during commissioning and written back into the profile."
Design that feedback loop. Who measures it, when, what happens if the measured
value would make the *currently running* configuration unschedulable, and how do
you stop a bad measurement from silently reducing the sample rate of a deployed
appliance?

**Q4.** The single-worker executor makes bus serialisation true by construction.
Name three other hazards usually handled by convention — a documented rule, a
review checklist, a comment — and for each propose a construction that makes
violation impossible. Then say honestly which of your three costs more than the
rule it replaces.

---

## Answers

### A1 — Bus fault versus device fault

#### What it costs

**The sharp cost is not wasted CPU. It is that the parameter which made the
breaker safe is the one that makes it dangerous at scale.**

`breaker_cooldown_seconds: 5.0` was sized for one dead device on a healthy bus:
one probe every five seconds, roughly 20% duty, harmless. Apply it to 18 tasks
with the bus down:

```
18 tasks ÷ 5 s cooldown  =  3.6 probes/second demanded
each probe costs up to      timeout(1.0 s) × attempts
```

RS-485 is half-duplex with one serialised thread. It can service roughly one
probe per second. You are demanding three to four times what exists,
**permanently**. The cooldown throttles nothing; the line saturates with probes
for the whole fault.

And because `motion` polls at 10 Hz while the other five groups poll at 1 Hz,
those breakers cross the threshold at different moments and their cooldowns drift
permanently out of phase. It is not a storm you can characterise. It is an
irregular dribble, indefinitely.

**Second cost, and worse: 18 alarms saying "sensor offline" and none saying "bus
down".** The appliance has no vocabulary for *the medium failed*. It can only
make claims about devices, so a wiring fault is reported as 18 simultaneous
device failures — technically true, operationally a lie, and it sends a
technician up a silo with the wrong spare part.

#### The hierarchy

Three levels. The design move is **promotion by evidence, not by counting.**

**Level 0 — Transport.** `SerialException`, `ENODEV`, the device node vanishing.
*Proof* the port is gone, not an inference. Trip immediately: no threshold, no
counting.

The critical part is the recovery probe. **Probe at the layer that failed, with
the cheapest test that can prove recovery** — can we `open()` the node?
Microseconds, not a one-second Modbus timeout. This is what kills the
saturation: during a transport fault the bus carries zero traffic.

**Level 1 — Bus.** Port opens, nothing answers. Inference, so the bar is higher:

- **All** previously-healthy devices must be failing, not *K* of them. "All" is a
  meaningful predicate; *K* is an arbitrary constant you will tune for years.
- A device already dead before this event **does not vote**, or one dead sensor
  permanently biases the bus toward false bus-faults.
- Elect a prober — highest recent success rate before the fault — and **rotate it
  on every failed probe**, so one dead device cannot hold the bus closed.

**Level 2 — Device.** Unchanged.

**The anti-masking rule, which matters more than the rest:** bus-level
suppression **freezes** device counters, never resets them. Suppressed intervals
are recorded as a third state, `not evaluated`, distinct from both `ok` and
`failed`.

Without this, an intermittent bus fault resets the counters faster than a
genuinely dead sensor can accumulate three consecutive failures — and that sensor
is never detected. Not delayed. **Never.** It is the Lesson 1 distinction wearing
different clothes: *not arriving and safe* versus *not arriving and lost* becomes
*not polled* versus *polled and fine*.

#### The new failure mode it introduces

**The cheap probe lies.**

I swapped an expensive truthful test (a real Modbus transaction) for a cheap
partial one (can we open the node). A CH341 re-enumerating on a marginal USB
connector makes the node reappear while the A/B pair is still cut. So: transport
closes, devices resume probing, all fail, bus trips, transport probe succeeds,
repeat.

That is an oscillator whose period is set by **USB enumeration timing rather than
by the actual fault** — harder to diagnose than today's behaviour, because the
logs now show a system confidently transitioning between states instead of one
that is plainly stuck.

Every hierarchy trades a loud failure for a quiet one. This one trades *obviously
saturated* for *plausibly cycling*.

#### What I would actually build — much less

**In this appliance's deployed topology, Level 1 can never distinguish anything.**
The three sensors are on three separate adapters, one sensor per bus. "All
devices on the bus failed" is identical to "the one device failed." Level 1 is
dead code that buys nothing and costs complexity.

So: **Level 0 only.** Catch `SerialException` at the transport, trip a port-level
breaker on evidence, probe recovery by opening the node. That eliminates the
saturation entirely, turns 18 mystery alarms into one true statement, is roughly
forty lines, and works identically whether the silo ends up one-sensor-per-bus or
three sharing a run.

Level 1 gets built the day the topology becomes multi-drop, and not before — with
freeze-don't-reset from the start, because that one is a correctness property
rather than an optimisation.

> **The architect's move is not designing the three-level hierarchy. It is
> noticing that two of the three levels are unjustified for the system in front
> of you, and that 90% of the value sits in the layer where you have evidence
> instead of inference.**

Complexity you can justify on a whiteboard but not against the deployed topology
is complexity that will rot — exactly like the bus capacity model in this repo,
which was complete, correct, tested, and called by nothing.

### A2 — Catch-up versus realignment

**First: verify the premise. It is false.**

```python
missed = 0
while self.next_due <= now:
    self.next_due += self.interval
    missed += 1
if missed > 1:
    self.missed_polls += missed - 1
```

The loop advances `next_due` until it is **strictly greater than `now`**. It does
not fire back-to-back. It already does what "the alternative" describes: jump to
the next future slot and record the gap.

After a 30-second stall at 10 Hz: 300 iterations, `next_due` lands one interval
in the future, `missed_polls += 299`, and `due()` returns False until the next
real slot.

**A convincing description of a defect is not evidence of one.** That is the most
useful thing in this lesson.

One genuine observation the question missed: the loop is **O(gap ÷ interval)**. A
machine resuming from suspend after four hours gives 144,000 iterations per task
across 18 tasks. Harmless here, but it is an unbounded loop over wall-clock time
where floor division would be O(1). That is the real, minor defect.

**The underlying question is still worth answering.**

**(a) This silo — realign and count. Correct as built.** Catch-up would be
dishonest: the polls would fire now and be stamped now, fabricating a burst of
readings describing a moment the sensor was never asked about. A recorded gap is
information; a fabricated burst is corruption.

**(b) Servo loop — neither.** A missed deadline in a control loop is a
**failure**, not a scheduling event. Catch-up is the worst option available:
applying a stale command to a plant that has moved on is how you get instability
or damage. The correct behaviour is to detect the overrun and act — hold the last
safe output, degrade authority, or fault the axis.

**(c) Billing meter — change the measurement so the question disappears.**
Sampling is the wrong model; any sampling scheme makes revenue a function of
scheduler jitter. Read a **monotonic cumulative counter** from the device
instead. A missed poll then costs resolution and never costs money.

> The architectural move is not choosing a scheduling policy. It is noticing that
> for one of the three systems, the right fix is to make the measured quantity
> immune to scheduling.

### A3 — The turnaround feedback loop

**The docstring contains a design error.** `DEFAULT_TURNAROUND_MS` is documented
as "written back into the profile". It must not be. ADR-004 says profiles
describe a *sensor model*; turnaround is a property of *this installation* —
this cable, this adapter, this temperature. Writing it into the shared profile
means one silo's 40-metre run silently changes the capacity model for every
appliance that loads it. It belongs where calibration and tilt baselines already
live: per-sensor commissioning data.

**Who measures:** the engine, continuously — not a commissioning tool.
`TaskRuntime` already keeps a `_latencies` deque; turnaround is observed
transaction time minus computed wire time. A number measured once on a bench, at
bench temperature, with a bench cable, describes a bench.

**When it becomes eligible:** use **p95, not the mean** — capacity must be sized
for the slow tail, and a mean is exactly the statistic that hides it. Require a
minimum sample count *and* a minimum window, and require two independent windows
to agree before the value is proposed.

**Guard the estimator, or it measures the wrong thing:** discard samples taken
while the breaker is not `CLOSED`, during startup, or on any transaction that
involved a retry. A retried transaction's latency is a timeout plus a turnaround,
and folding them together produces a number that grows every time the bus has a
bad day.

**If the measurement makes the running config unschedulable — three outcomes,
none automatic:**

| Measured utilisation | Action |
|---|---|
| < 0.65 | Adopt. Good news, no decision needed |
| 0.65 – 1.0 | Provisional finding. Keep polling — the bus is empirically coping, since we measured it while it ran. Surface for an engineer |
| ≥ 1.0 | Fault. The configuration is impossible and the appliance's own behaviour is the evidence |

**Even at ≥ 1.0, do not auto-reduce the rate.** Silently lowering the sample rate
changes what the instrument *is*: the advertised spectral limit is 0.4× the
measured rate (ADR-006), so every downstream claim shifts under the operator
without anyone deciding. The appliance already reports measured rate alongside
configured — that honesty is the mechanism. Let it show the discrepancy; do not
let it resolve the discrepancy by itself.

**How a bad measurement is prevented from reducing the rate: by construction.**
Nothing auto-applies. The loop proposes, a human disposes — the same shape as
`retry-dead-letters --confirm` and ADR-015's named threshold confirmation. The
repo has the pattern twice already; a third use needs no new invention.

And record **provenance** with the number: sample count, window, adapter chip,
temperature. A capacity figure with no provenance is the unverified-register-map
problem of ADR-005 in different clothes.

### A4 — Convention into construction

**Hazard 1: ISR-context versus task-context APIs.** `xQueueSendFromISR` from a
task, or `xQueueSend` from an ISR, compiles cleanly and corrupts the scheduler
intermittently. The protection is a naming suffix and documentation.

*Construction:* make context a **type**. The queue's send method requires an
`IsrContext` token constructible only by the interrupt prologue. Rust does this
natively; in C an opaque struct passed by the ISR shim gets most of the way.

*Cost: **cheaper** than the rule.* Some parameter threading and a few duplicated
helpers, against a failure that is silent, intermittent, and usually found on
someone else's hardware.

**Hazard 2: Units.** This repo ships an artisan command, `measurements:check-units`
— a **runtime check standing in for a missing type**. µm versus mm, g versus
m/s², with a displacement register whose range mode is not readable.

*Construction:* newtypes. `Micrometres` and `Millimetres` with no implicit
conversion; arithmetic that refuses mismatched units. Violation becomes
inexpressible.

*Cost: moderate, and worth it.* Noisier arithmetic, explicit unwrapping at
serialisation boundaries, some runtime cost in Python. But an entire command plus
documentation currently exists to catch what a type would prevent for free.

**Hazard 3: Exclusive bus access — the one that costs more.** In-process
serialisation is already by construction: `ThreadPoolExecutor(max_workers=1)`.
Across processes it is `PortLock` — **advisory**. A process that does not ask
still gets the port. Run `qv-probe` while `quakevault-acq` is running and you
interleave frames on a half-duplex line.

*Construction:* OS-level exclusive ownership. The udev rule gives the node to
`quakevault-acq` alone; every operator tool goes through a control socket rather
than opening the port.

*Cost: **higher** than the rule, and I would not build it.* `qv-probe`,
`qv-set-address` and `qv-calibrate` exist *precisely for the situation where the
service is stopped and you are debugging hardware*. Routing them through the
running service inverts the dependency: **the tools you need when things are
broken now require the broken thing to be working.** That is a worse failure than
the interleaving it prevents. Keep the convention and make it *loud* — the tools
should detect the lock, refuse to start, and say "stop quakevault-acq first."

> **Constructions that move a check to compile time or the type system are almost
> always cheaper than the rule they replace.**
>
> **Constructions that require new runtime coordination — IPC, brokers, ownership
> protocols — usually cost more, and frequently relocate the failure rather than
> removing it.**

Hazards 1 and 2 are the first kind. Hazard 3 is the second, and recognising that
is worth more than building it.

---

*Next: **Lesson 3 — The wire.** CRC, framing, byte order, and where trust ends.*
