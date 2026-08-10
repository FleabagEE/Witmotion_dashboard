# Lesson 9 — Calibration and Time

**Modules:** `calibration.py`, `calibrate_cli.py`, `measurement.py`
**Related decisions:** ADR-007, ADR-010

---

## Why it exists

Two subjects in one lesson because they are the same subject: **the difference between what a sensor said and what was true.**

Calibration corrects the value. Timestamps place it. Both are corrections applied by the appliance to a device's raw claim, and both must be traceable, because a corrected measurement is a *conclusion* rather than an *observation*.

---

## Part 1 — Calibration

### The problem, stated physically

```python
"""Per-unit accelerometer calibration.

The register decoding is correct - that was established by the magnitude varying
with orientation rather than staying uniformly low. What remains is the sensor's
own gain error: on this unit gravity reads 0.9898 g with the vector on X and
0.9625 g with it on Z, so the axes disagree with each other by about 3%. That is
ordinary for an uncalibrated consumer MEMS part (+/-3-5% is typical) and it is a
property of the device, not of the software reading it.
"""
```

Read the first paragraph again. Before writing any correction, the author **ruled out a software cause**: magnitude varying with orientation proves the register map and scaling are right, and what remains is device error.

That is the correct order of operations and it is frequently skipped. Applying a fudge factor to compensate for a decoding bug produces a system that is right in one orientation and wrong everywhere else — and *looks calibrated*.

> **Never calibrate away a bug. Establish that the arithmetic is correct first, then correct the physics.**

Then the consequence, quantified:

> A 3.8% error on one axis is worth roughly two degrees of tilt error near 45 degrees, which is large for something reported as an inclinometer.

The error is not reported as a percentage of full scale. It is propagated into **the quantity the product actually claims**. 3.8% sounds small; two degrees on a device sold as an inclinometer does not. Always carry error into the units of your claim.

### Decision one: absent means identity

> **Absent means identity.** With no calibration file, every gain is 1.0 and every offset 0.0, and readings pass through untouched. An appliance must never apply a correction nobody chose to apply.

The default is not "best guess", not "typical value for this part", not "average of the fleet". The default is **do nothing**.

This is a specific instance of a general safety principle: when configuration is missing, the safe state is the *inert* state, not the *helpful* state. A helpful default is an unrecorded decision made by an author who never saw your installation.

Note the honesty in the failure mode this creates: SENSOR-002 and SENSOR-003 currently read 0.9866 g and 0.9937 g, and `SensorHealth` marks their calibration check as `warn` with the text *"Not fitted. Angles are unaffected; the 1 g health check is less sensitive against an already-wrong baseline."* The appliance says exactly what is missing and exactly what it costs.

### Decision two: a corrected value says so

> **A corrected value says so.** Calibrated channels are marked PROCESSED rather than NATIVE, so the record distinguishes what the sensor said from what the appliance concluded. A calibration is a fitted model, and a reader is entitled to know one was involved.

Lesson 4's `ValueClass`, doing real work. This is **provenance tracking**, and its value appears years later: when a calibration is found to be wrong, you must be able to identify every stored value computed under it. If corrected and raw values are indistinguishable in the record, you cannot — and your only options are to distrust everything or to distrust nothing.

The form of the correction is also worth noting:

```python
"""``true = (measured - offset) / gain`` for one channel."""
```

Offset then gain, per axis. Not a rotation matrix, not cross-axis sensitivity terms. This is a **deliberately simple model**, and simple models have a virtue that complex ones do not: you can tell when they are wrong. A full nine-parameter misalignment model fitted from six orientations on a bench will absorb mounting error into what claims to be sensor calibration, and you will never know.

---

## Part 2 — Time

### Two clocks, both required

```python
timestamp_utc=utc_now(), monotonic_ns=time.monotonic_ns(),
```

Every measurement carries both. This is not redundancy; they answer different questions and each is unusable for the other's.

| Clock | Answers | Fails at |
|---|---|---|
| **UTC wall clock** | "When did this happen, in the world?" | Steps. NTP corrections, DST, manual sets. Can go backwards. |
| **Monotonic** | "How long between these two events?" | Meaningless across reboots; not comparable between machines. |

Use UTC to correlate with a blast log, a site diary, or another appliance. Use monotonic to compute intervals, rates and jitter — because a `measured_hz` derived from wall-clock differences will be wrong or infinite the moment NTP steps the clock.

Look where the engine uses each. `measured_hz`, `jitter_ms` and the scheduler's `next_due` all use `time.monotonic()`. The stored timestamp is UTC. Neither is asked to do the other's job.

> **A single clock cannot answer both "when" and "how long". Any system that measures rates and also records events needs both, stored side by side.**

### The reset problem

ADR-010, met in Lessons 5 and 6, is fundamentally a time-and-identity problem: sequence numbers restart at 1, so identity built on them collides across restarts. The `run_id` is an epoch marker — the same role a boot ID plays in the kernel, or an ignition-cycle counter plays in a DTC.

Any counter that resets needs something that says which reset it belongs to.

### What is *not* claimed

Worth noticing for what it tells you about scope. There is no PTP, no GPS discipline, no sub-millisecond synchronisation between the three sensors. For settlement monitored over weeks that is entirely appropriate.

But it bounds the product: you could not use this appliance to compute wave arrival-time differences between top and ground, because you do not know the relative sampling instants to better than the poll jitter. An architect knows which claims their timing architecture forecloses, and says so before a customer discovers it.

---

## What would happen if these were removed

| Remove | Consequence |
|---|---|
| Identity default | Every uncalibrated sensor silently corrected by a number nobody chose. |
| `PROCESSED` marking | Raw and fitted values indistinguishable forever. A wrong calibration becomes unrecoverable. |
| Per-unit calibration | ~2° of tilt error on a device sold as an inclinometer. |
| Monotonic clock | Rates and jitter corrupt on every NTP step. |
| UTC timestamp | No correlation with any external record. Data useless as evidence. |
| `run_id` | Silent duplicate-rejection data loss on every restart. |

---

## The embedded principles

**1. Prove the arithmetic before correcting the physics.**

**2. Missing configuration means inert, not helpful.**

**3. Every transformation must be recorded in the data it transforms.**

**4. Propagate error into the units of your claim.**

**5. Keep correction models simple enough that their wrongness is visible.**

**6. Wall time and elapsed time are different measurements. Store both.**

**7. Every resettable counter needs an epoch.**

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Null object / identity default** | `IDENTITY` calibration |
| **Provenance / taint tracking** | `ValueClass.PROCESSED` |
| **Dual clock** | `timestamp_utc` + `monotonic_ns` |
| **Epoch / generation** | `run_id` |
| **Data-driven configuration** | calibration YAML, like profiles |
| **Separation of measurement and correction** | correction applied at a known layer, marked |

---

## Where you will meet this again

### Automotive
- **End-of-line calibration** written to NvM at manufacture: injector trim, sensor offsets, steering-angle zero. Replace a part and you must re-run calibration — the same "identity unless told otherwise" rule, enforced by service procedure.
- **Steering angle sensor learn** and **throttle position adaptation**: the ECU refuses to use an uncalibrated sensor rather than guessing a zero.
- **Ignition-cycle counters** in DTC aging are epochs.
- **gPTP (802.1AS)** in automotive Ethernet exists because sensor fusion across ECUs needs a shared time base — the thing this appliance deliberately does not claim.

### Aerospace
- **IMU bias, scale factor and misalignment** are the canonical calibration triple, and the reason inertial units cost what they do. This module implements the first two per axis and deliberately omits misalignment.
- **Gyro-compassing / INS alignment on the ground** before flight — a calibration the aircraft refuses to skip.
- **IRIG-B time distribution** and **GPS-disciplined oscillators**: a shared time base is infrastructure, not an afterthought.
- **Air data / inertial disagreement monitors** compare independent estimates rather than trusting one.

### Industrial / PLC
- **Loop calibration certificates and traceability to a standard** — a calibrated instrument carries a document naming who calibrated it, when, and against what. That is `ValueClass.PROCESSED` plus an audit trail, made statutory.
- **Sensor trim vs range setting** in HART devices: two adjustments frequently confused, one correcting the sensor and one scaling the output.
- **Sequence of Events recorders** requiring millisecond time sync, usually IRIG-B or PTP, because trip ordering is the diagnosis.

### Robotics
- **Camera intrinsics and extrinsics**, IMU-camera time offset estimation in VIO. The *time offset* between sensors is itself a calibrated parameter — which is what this appliance would need before comparing wave arrivals.
- **`ros::Time` vs `ros::WallTime`**, and `use_sim_time`. ROS made the wall/logical clock distinction explicit after enough people got it wrong.
- **`tf` timestamps and extrapolation limits**: a transform is only valid near its timestamp, and asking outside that window is an error rather than an estimate.

### Linux kernel
- **`CLOCK_MONOTONIC` vs `CLOCK_REALTIME` vs `CLOCK_BOOTTIME`** — three clocks because there are three questions. `CLOCK_MONOTONIC` not counting suspend is exactly the kind of subtlety that ruins a rate calculation on a device that sleeps.
- **IIO calibration attributes** (`in_accel_x_calibbias`, `calibscale`) — the kernel exposes calibration as data with a defined meaning, and defaults it to identity.
- **`hwclock` vs system time**, and NTP slew versus step: the kernel prefers to *slew* precisely because stepping breaks interval measurement.

### RTOS firmware
- **Factory calibration in OTP/fuses**, read at boot and applied by the driver — most MEMS and temperature sensors ship this way.
- **Tick count versus RTC**: the classic firmware bug is computing an interval from an RTC that a user just adjusted.
- **`vTaskDelayUntil` uses tick count, never wall time** — the same discipline as `next_due` on `monotonic()`.

---

## The connective tissue

There is a single sentence underneath both halves of this lesson:

> **Record what you were told, record what you concluded, and never let the second be mistaken for the first.**

Calibration is the appliance concluding that the sensor was 3.8% off. A timestamp is the appliance concluding when the reading happened. Both are inferences dressed as facts, and both are marked as inferences — one by `ValueClass`, the other by storing the raw monotonic instant alongside the interpreted wall-clock time.

Lesson 12 shows where this pays: a report that may be attached to a damage claim must be able to say which of its numbers the sensor produced and which the appliance did.

---

## Design questions

**1.** "Absent means identity" is safe for calibration. Name a place in an embedded system where the *inert* default is the dangerous one and a helpful default is correct — and derive the rule that distinguishes the two cases.

**2.** The model is per-axis gain and offset, deliberately excluding cross-axis misalignment. Sketch the six-orientation procedure that would fit a full misalignment matrix, then explain how you would keep it from absorbing the *mounting bracket's* error into what claims to be sensor calibration. Is that separable at all?

**3.** Suppose you discover today that SENSOR-001's calibration file has had a transposed axis for three months. Walk through the recovery, using only what the repo records: which stored values are affected, how you identify them, and what you tell a client who already has a report built on them.

**4.** The appliance stores UTC and monotonic per measurement but makes no claim about *relative* timing between the three sensors. Design the minimum change that would let you compare arrival times between top and ground to within a millisecond. State what hardware, what protocol, and — most importantly — what new failure mode you have introduced by depending on it.

---

*Next: Lesson 10 — The Refusal to Compute: when the correct output is "no".*
