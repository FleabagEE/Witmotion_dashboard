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

*Next: Lesson 3 — The Wire (`crc.py`, `decode.py`, `client.py`): framing, checksums, byte order, plausibility limits, and where trust in a sensor ends.*
