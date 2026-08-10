# Lesson 2 — The Acquisition Engine

**Module:** `acquisition/src/qv_acq/engine.py`
**Supporting cast:** `throughput.py`, `portlock.py`
**Related decisions:** ADR-003, ADR-006, ADR-007

---

## Why it exists

Lesson 1 established the boundary between the unrepeatable and the repeatable. The engine lives entirely on the unrepeatable side. It is the only code in this appliance that touches the physical world, and once it misses a moment, that moment is gone.

Everything in this module is a consequence of one sentence in its docstring:

> the bus is half duplex and two in-flight transactions corrupt each other

That is not a software constraint. It is a property of a differential pair with one driver enabled at a time. **The architecture of this module is the shape of that physical fact, expressed in code.**

This is the first thing that separates firmware architecture from application architecture. In application software you choose your concurrency model to suit your throughput goals. In firmware you *discover* your concurrency model by reading the datasheet and the wiring diagram. You do not get to pick.

---

## What problem it solves

Four problems, each of which has a naive answer that fails in a different way.

### 1. Serialisation on a shared medium

**Naive:** poll each sensor in its own task, use a lock around the port.

**Actual:**
```python
# One thread per bus. This is what serialises the bus: no two
# transactions on this port can ever overlap.
self._executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix=f"bus-{self.bus_id}")
```

Read the docstring's phrasing again — *"which is what makes 'serial within a bus' true by construction rather than by convention."*

That distinction is the entire lesson. A lock is a convention: it works as long as every future contributor remembers to take it. A single-worker executor is a construction: there is no code path, present or future, that can produce two concurrent transactions on that port, because there is only one thread that can execute them.

**Prefer constructions to conventions.** When you can make a hazard structurally impossible, do that instead of documenting a rule. Every rule you write down is a rule someone will eventually not read.

### 2. Cadence without drift

This is the most important twenty lines in the file.

```python
def schedule_next(self, now: float) -> None:
    if self.next_due == 0.0:
        self.next_due = now + self.interval
        return
    # Count skipped slots rather than letting the schedule drift, so a slow
    # bus shows up as missed polls instead of a silently reduced rate.
    missed = 0
    while self.next_due <= now:
        self.next_due += self.interval
        missed += 1
    if missed > 1:
        self.missed_polls += missed - 1
```

The naive version is `sleep(interval)` at the bottom of a loop. It is wrong, and it is wrong in the way that is hardest to detect.

If each cycle takes 1.0 s of work plus a 1.0 s sleep, your "1 Hz" sensor samples at 0.5 Hz. Nothing errors. The data looks fine. Every timestamp is plausible. Your FFT is quietly wrong by a factor of two, and your settlement trend is stretched across twice the real time.

The correct model is **absolute deadlines, advanced by a fixed interval**. `next_due += interval`, never `next_due = now + interval`. Late work does not move the schedule; it consumes slack.

And then the part that matters even more than the correctness: **lateness is counted.** `self.missed_polls` turns a silent rate degradation into a number an operator can see. Compare with `measured_hz`, computed from observed completions rather than from configuration:

```python
@property
def measured_hz(self) -> float | None:
    """Effective rate from observed completions, not from configuration."""
```

ADR-007 makes this a product commitment: *the measured rate is a first-class value*. The appliance reports what it actually achieved beside what it was asked to achieve, and never substitutes one for the other.

> **A system that degrades silently is worse than one that fails loudly.** Silent degradation converts a maintenance event into a data-integrity event, and you discover it months later when someone questions a trend.

### 3. Fairness between poll rates

```python
# Oldest deadline first, so a fast group cannot starve a slow one.
due.sort(key=lambda t: t.next_due)
```

One line, and it is **earliest-deadline-first scheduling**. Without it, a 10 Hz group and a 0.1 Hz group in the same ready set would be serviced in list order, and under sustained load the slow group could be perpetually deferred — the classic starvation failure.

EDF is provably optimal for uniprocessor scheduling in the sense that if any schedule meets all deadlines, EDF does. This bus worker is a uniprocessor: one thread, one medium.

### 4. One dead device must not starve the healthy ones

```python
def due(self, now: float) -> bool:
    if self.breaker is BreakerState.OPEN:
        if now - self.opened_at < self.breaker_config.cooldown_seconds:
            return False
        # Cooldown elapsed: allow exactly one probe through.
        self.breaker = BreakerState.HALF_OPEN
        return True
    return now >= self.next_due
```

Consider a three-sensor bus where one sensor dies. Each failed transaction costs the full 1.0 s timeout, times `max_attempts`. Without a breaker, the dead sensor consumes the majority of the bus's time budget every cycle, and the two *healthy* sensors start missing their deadlines.

**A fault in one device becomes a fault in the system.** That is the thing to prevent.

The breaker's placement is worth studying: it is inside `due()`, not inside the poll. An OPEN breaker means the task never becomes ready, so it consumes *zero* bus time — not "fails fast", but "does not participate". And `_sleep_for()` excludes OPEN tasks from the next-wakeup calculation, so a dead sensor doesn't even influence the loop's sleep.

HALF_OPEN lets exactly one probe through. Not a burst — one. That is what makes recovery cheap: a device that is still dead costs one timeout per cooldown period rather than a full retry storm.

---

## What would happen if it were removed

Take each piece away individually and predict the symptom. This is a useful exercise on any module — if you cannot name the symptom, you do not understand why the code is there.

| Remove | Symptom | Why it is nasty |
|---|---|---|
| Single-thread-per-bus | CRC error storms, timeouts | Looks *exactly* like a wiring fault. Teams replace cables for days. |
| Deadline scheduling | Sample rate quietly lower than configured | No error. Data looks fine. All downstream analysis is wrong by an unknown factor. |
| Missed-poll counting | Nothing visible | You lose the only evidence that the above is happening. |
| EDF ordering | Slow groups starve under load | Intermittent, load-dependent, unreproducible on the bench. |
| Circuit breaker | One dead sensor degrades all sensors on that bus | The failure *spreads*, so diagnosis starts at the wrong device. |
| `PortLock` | Two processes interleave frames | Same CRC storm as row 1, and the docstring says it plainly: *"operators lose days to it."* |

Notice how many of these produce **misleading symptoms rather than error messages**. That is the signature of a real-time bug. In application software, bugs usually announce themselves. In firmware, the most expensive bugs disguise themselves as hardware faults.

---

## The embedded principles

**1. The concurrency model encodes physics.** Concurrent across buses, serial within a bus. That sentence is a description of the wiring, not a description of the software.

**2. Absolute deadlines, never relative sleeps.** Advance the deadline by the period; let lateness consume slack; count it when slack runs out.

**3. Isolate blocking at the edge.** The blocking `pymodbus` client lives in exactly one thread per bus. `async` above, blocking below, one clean boundary. The rest of the system never inherits the driver's blocking behaviour.

**4. Prove schedulability before you run.** `throughput.py` computes whether the requested schedule is even physically possible — request 8 bytes, response 5+2N, 3.5 character-times of interframe idle, device turnaround, and USB-serial latency *by bridge chip*:

```python
USB_LATENCY_MS = {
    "ch340": 4.0,
    "ftdi": 1.5,
    "cp210x": 2.0,
    "unknown": 4.0,
}
```

That table is an architect's fingerprint. Someone measured real hardware and encoded the result where the scheduler can use it. The `unknown` entry defaults to the *worst* value — an unrecognised adapter is assumed to be the slow one, so an unknown chip cannot silently produce an optimistic schedule.

**5. Budget utilisation well below 100%.** `MAX_SUSTAINED_UTILISATION = 0.65`. Three sensors at 1 Hz measure 21.9%, comfortably inside it.

Worth noticing: the Liu & Layland bound for rate-monotonic scheduling of many tasks converges on ln 2 ≈ **0.693**. This repo's 0.65 was chosen as engineering margin for a half-duplex bus, not derived from that theorem — but the closeness is not a coincidence. Both numbers answer the same question: *how much of a shared serial resource can you commit before jitter destroys your deadlines?* Somewhere near two-thirds is the recurring empirical answer across real-time engineering.

**6. Make resource ownership exclusive and the violation legible.** `PortLock` exists so the second process fails immediately with a clear message rather than producing symptoms that point at the cable.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Active Object** | `BusWorker` owns its resource and its thread; all access is through it |
| **Earliest Deadline First** | `due.sort(key=lambda t: t.next_due)` |
| **Circuit Breaker** (CLOSED / OPEN / HALF_OPEN) | `TaskRuntime.due()`, `record_failure()` |
| **Bounded retry with exponential backoff** | `RetryConfig.delay_for()` |
| **Half-Sync/Half-Async** | async scheduler above, blocking driver thread below |
| **Advisory lock / mutual exclusion on a device** | `PortLock` |
| **Schedulability analysis** | `throughput.py` |

The composition matters more than any individual pattern. Retry, breaker and deadline scheduling interact: retries consume the time budget, which causes missed deadlines, which the breaker prevents from becoming permanent. Get one wrong and the others amplify it.

---

## Where you will meet this again

### Automotive
- **LIN** schedule tables are literally this: fixed slots, fixed periods, a master that will not let a slave overrun its window.
- **FlexRay** static segment — time-triggered slots allocated at design time, exactly the "prove schedulability before you run" principle, enforced by the controller.
- **CAN bus load budgeting** — the field convention of staying under 40–50% busload for the same reason `MAX_SUSTAINED_UTILISATION` exists: latency variance explodes as you approach saturation.
- **AUTOSAR OS schedule tables** and the OSEK task model — fixed-period tasks with deadline monitoring; a task that overruns raises a protection hook rather than quietly slipping.
- **DTC debouncing** in the Diagnostic Event Manager is the circuit breaker in different clothing: a fault must persist before it is believed, and it must clear before it is forgotten.

### Aerospace
- **ARINC 653** major/minor frame partition scheduling — the purest form of "the schedule is proven before the system runs." Partitions get fixed time windows; overrun is a health-monitor event, not a slowdown.
- **Time-Triggered Architecture** (Kopetz) and **TTEthernet** — the entire philosophy of this engine's deadline model, taken to its conclusion.
- **DO-178C worst-case execution time analysis** — you must *demonstrate* your timing, which is what `throughput.py` does in miniature.

### Industrial / PLC
- **IEC 61131-3 cyclic scan** with a scan-time watchdog. "Scan overrun" is precisely `missed_polls`, promoted to a fault the controller can act on.
- **PROFIBUS DP token rotation time** — a budgeted, provable cycle across a shared serial medium; the same arithmetic as `throughput.py`.
- **EtherCAT cycle time** and distributed clocks — cadence as a first-class engineered quantity.
- **Modbus RTU itself** — the 3.5 character-time interframe gap in this code *is* the standard.

### Robotics
- **ROS 2 executors** and the callback-group model — deciding what may run concurrently and what must be serialised is exactly the bus-worker decision.
- The most common ROS bug in the wild is `rate.sleep()` misuse producing drift, which is the `sleep(interval)` mistake this module deliberately avoids.
- **Control-loop deadline monitoring** — a controller that runs late is a controller with different gains than the one you tuned.

### Linux kernel drivers
- **`i2c_adapter` / SPI bus locks** — the kernel serialises transactions per bus for the identical half-duplex-medium reason. One adapter, one transaction.
- **`hrtimer` with `HRTIMER_MODE_ABS`** and `hrtimer_forward_now()` — the kernel's own version of `next_due += interval`, for the same anti-drift reason.
- **Threaded IRQs** — top half fast and non-blocking, bottom half allowed to sleep. Half-Sync/Half-Async at the driver level.
- **Device error recovery / link resets** in PCIe and USB — back off, cool down, probe once. A circuit breaker.

### RTOS firmware
- **`vTaskDelayUntil()` versus `vTaskDelay()`** is *the* canonical instance of this lesson. `vTaskDelay()` is relative and drifts; `vTaskDelayUntil()` takes a previous-wake-time and advances it by the period. If you remember one cross-reference from this lesson, make it this one — it is the same bug and the same fix as `schedule_next()`.
- **Rate-monotonic scheduling** and the utilisation bound discussed above.
- **Watchdog and deadline miss handlers** — Lesson 7 will show how this appliance got that interaction wrong under recovery load.

---

## The connective tissue

Lesson 1's spool exists because the physical world will not wait. Lesson 2's engine exists because the physical world will not wait *and* it will not let you talk to two devices at once.

Both modules are, at bottom, the same discipline: **identify what is irreversible, protect it structurally rather than procedurally, and make every degradation countable.**

A useful test when you review any real-time module: pick any way it could run late or lose data, and ask *"what number goes up?"* If the answer is "none", you have found a place where the system will fail silently.

---

## Design questions

Answer as an architect. As before, some of these have no clean answer.

**1.** The circuit breaker is **per sensor**, keyed on consecutive failures of that device. Suppose the *bus* fails — a broken A/B pair, or the adapter unplugged. Every sensor on it fails independently and each opens its own breaker. Describe what that costs, and design a breaker hierarchy that handles device-level and bus-level faults distinctly. What new failure mode does your hierarchy introduce?

**2.** `schedule_next()` counts missed slots but never skips ahead to realign. After a 30-second stall, the loop will fire many polls back-to-back trying to catch up. Is that the correct behaviour for a structural monitoring appliance? Contrast it with the alternative — jump `next_due` to the next future slot and record the gap — and say which you would choose for (a) this silo, (b) a servo control loop, (c) a billing meter.

**3.** `throughput.py` bases its estimate on `DEFAULT_TURNAROUND_MS = 5.0`, described as "measured per device during commissioning and written back into the profile." Design that feedback loop. Who measures it, when, what happens if the measured value would make the *currently running* configuration unschedulable, and how do you stop a bad measurement from silently reducing the sample rate of a deployed appliance?

**4.** Sharpest one. The single-worker executor makes bus serialisation true by construction. Name three other hazards in an embedded system that are usually handled by convention — a documented rule, a code review checklist, a comment — and for each, propose a construction that makes violation impossible. Then say honestly which of your three constructions costs more than the rule it replaces.

---
1. What it costs
First, a correction that makes it worse: the breaker isn't per sensor. acquisition/src/qv_acq/engine.py:101 is "Scheduling state, breaker and metrics for one (sensor, group) pair", and the WTVB01-485 profile has six register groups. Three sensors on one bus is 18 breakers, not 3.

The sharp cost isn't wasted CPU. It's that the parameter which made the breaker safe is the one that makes it dangerous at scale.

breaker_cooldown_seconds: 5.0 was sized for one dead device on a healthy bus — one probe per 5 s, ~20% duty, harmless. Apply it to 18 tasks with the bus down:

RS-485 is half-duplex, one serialised thread. It can service roughly one probe per second. You are demanding three to four times what exists, permanently. The cooldown throttles nothing; the line saturates with probes for the entire fault.

And because motion polls at 10 Hz while the other five groups poll at 1 Hz, those breakers cross the threshold at different moments and their cooldowns drift permanently out of phase. It isn't a storm you can characterise — it's an irregular dribble, forever.

Second cost, and worse: 18 alarms saying "sensor offline" and none saying "bus down." The appliance has no vocabulary for the medium failed. It can only make claims about devices, so a wiring fault is reported as 18 simultaneous device failures — technically true, operationally a lie, and it sends a technician up a silo with the wrong spare part.

2. The premise is false — check it against the code
Before designing anything, read acquisition/src/qv_acq/engine.py:140:

The loop advances next_due until it is strictly greater than now. It does not fire back-to-back. It already does exactly what the question calls "the alternative": jump to the next future slot and record the gap. The comment above it says so.

After a 30-second stall at 10 Hz: 300 iterations, next_due lands one interval in the future, missed_polls += 299, and due() returns False until the next real slot.

The question is authoritative-sounding and wrong about the code in front of it. That is the single most useful thing in this lesson. A convincing description of a defect is not evidence of one — I have made this exact mistake in this repo twice this week, and both times the code was right and my reading was not.

One genuine observation the question missed: the loop is O(gap ÷ interval). A suspended laptop resuming after four hours gives 144,000 iterations per task × 18 tasks. Harmless here, but it's an unbounded loop over wall-clock time where floor() division would be O(1). That's the real (minor) defect.

The underlying question is still worth answering
(a) This silo — realign and count. Correct as built.

Catch-up would be actively dishonest. The polls would fire now and be stamped now, fabricating a burst of readings that describes a moment the sensor was never asked about. You'd be inventing data whose only property is that it looks continuous. For settlement monitoring, a recorded gap is information; a fabricated burst is corruption.

(b) Servo loop — neither.

A missed deadline in a control loop is not a scheduling event, it is a failure. Catch-up is the worst option available: applying a stale command to a plant that has since moved is how you get instability, overshoot, or a broken axis. Realign-and-count is barely better, because it silently accepts that the controller's model of time no longer matches reality.

The correct behaviour is to detect the overrun and act: hold the last safe output, degrade to a reduced-authority mode, or fault the axis. In hard real-time, a deadline miss is a safety event with a defined response, not a statistic.

(c) Billing meter — change the measurement so the question disappears.

Neither policy is right, because sampling is the wrong model. A billing meter must not lose the integral, and any sampling scheme makes revenue a function of scheduler jitter.

The answer is to read a monotonic cumulative counter from the device rather than an instantaneous rate. Then a missed poll costs resolution and never costs money — the device did the integration, and you're only reading a total that is correct whenever you happen to read it.

The architectural move is not choosing a scheduling policy. It's noticing that for one of the three systems, the right fix is to make the measured quantity immune to scheduling.

3. The turnaround feedback loop
First, the docstring contains a design error:

#: Measured per device during commissioning and written back into the profile.

It must not go in the profile. ADR-004 says profiles are data describing a sensor model. Turnaround is not a property of the WTVB01-485 — it's a property of this installation: this cable length, this CH341, this temperature. Writing it into the shared model means one silo's 40-metre run silently changes the capacity model for every appliance that ever loads that profile.

It belongs where calibration and tilt baselines already live: per-sensor commissioning data.

Who measures: the engine, continuously — not a commissioning tool. TaskRuntime already keeps a _latencies deque; turnaround is observed transaction time minus computed wire time. A number measured once on a bench, at bench temperature, with a bench cable, describes a bench.

When it becomes eligible: use p95, not the mean — capacity must be sized for the slow tail, and a mean is exactly the statistic that hides it. Require a minimum sample count and a minimum window (say 10,000 transactions and one hour), and require two independent windows to agree before the value is even proposed.

Guard the estimator, or it measures the wrong thing: discard samples taken while the breaker is not CLOSED, during startup, or on any transaction that involved a retry. A retried transaction's latency is not turnaround — it's a timeout plus a turnaround, and folding those together produces a number that grows every time the bus has a bad day.

If the measurement makes the running config unschedulable — three outcomes, none automatic:

Measured utilisation	Action
< 0.65	Adopt. Good news, no decision needed.
0.65 – 1.0	Provisional finding. Keep polling — the bus is empirically coping, since we measured it while it ran. Surface it for an engineer.
≥ 1.0	Fault. The configuration is impossible and the appliance's own behaviour is the evidence.
Even at ≥ 1.0, do not auto-reduce the rate. Silently lowering the sample rate changes what the instrument is: the advertised spectral limit is 0.4× the measured rate (ADR-006), so every downstream claim shifts under the operator without anyone deciding. The appliance already reports measured rate alongside configured — that honesty is the mechanism. Let it show the discrepancy; don't let it resolve the discrepancy by itself.

How a bad measurement is prevented from reducing the rate: by construction. Nothing auto-applies. The loop proposes, a human disposes — the same shape as retry-dead-letters --confirm and ADR-015's named threshold confirmation. This repo has the pattern twice already; a third use needs no new invention.

And record provenance with the number: sample count, window, adapter chip, temperature. A capacity figure with no provenance is the unverified-register-map problem in ADR-005 wearing different clothes.

4. Convention → construction
Hazard 1: ISR-context vs task-context APIs
xQueueSendFromISR from a task, or xQueueSend from an ISR, compiles cleanly and corrupts the scheduler intermittently. The protection is a naming suffix and documentation.

Construction: make context a type. The queue's send method requires an IsrContext token constructible only by the interrupt prologue. Rust does this natively; in C an opaque struct passed by the ISR shim gets most of the way.

Cost: cheaper than the rule. Some parameter threading and a few duplicated helpers, against a failure that is silent, intermittent, and typically found on someone else's hardware.

Hazard 2: Units
This repo ships an artisan command, measurements:check-units. That command is a runtime check standing in for a missing type — µm vs mm, g vs m/s², and the WTVB01-485 reports displacement in µm from a register whose range mode isn't readable.

Construction: newtypes. Micrometres and Millimetres with no implicit conversion; arithmetic that refuses mismatched units. Violation becomes inexpressible.

Cost: moderate, and worth it. Noisier arithmetic, explicit unwrapping at serialisation boundaries, some runtime cost in Python. But an entire command plus documentation currently exists to catch what a type would prevent for free.

Hazard 3: Exclusive access to the bus — the one that costs more
In-process serialisation is already by construction: ThreadPoolExecutor(max_workers=1). Excellent, and the reason bus interleaving cannot happen within the service.

Across processes it is PortLock — advisory. A process that doesn't ask still gets the port. Run qv-probe while quakevault-acq is running and you interleave frames on a half-duplex line. The protection is the convention "everything takes the lock."

Construction: OS-level exclusive ownership. The udev rule gives the node to quakevault-acq alone; every operator tool goes through a control socket rather than opening the port.

Cost: higher than the rule it replaces, and I would not build it.

qv-probe, qv-set-address and qv-calibrate exist precisely for the situation where the service is stopped and you are debugging hardware. Routing them through the running service inverts the dependency: the tools you need when things are broken now require the broken thing to be working. That is a worse failure than the interleaving it prevents.

I'd keep the convention and make it loud instead — the tools should detect the lock, refuse to start, and say "stop quakevault-acq first."

The general rule
Constructions that move a check to compile time or the type system are almost always cheaper than the rule they replace.

Constructions that require new runtime coordination — IPC, brokers, ownership protocols — usually cost more, and frequently relocate the failure rather than removing it.

Hazards 1 and 2 are the first kind. Hazard 3 is the second, and recognising that is worth more than building it.



*Next: Lesson 3 — The Wire (`crc.py`, `decode.py`, `client.py`): framing, checksums, byte order, plausibility limits, and where trust in a sensor ends.*
