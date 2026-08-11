# Fix report — two of three sensors had no offline alarm

**Date:** 2026-08-10
**Commits:** `ff254a8`, `dae403d`, and the fingerprint round below
**Severity:** High — a failed sensor would not have alarmed
**Found by:** answering the question *"if one of the sensors failed, how does our
system find out which one has the problem?"*

---

## Summary

The appliance has three layers for identifying a failed sensor. Checking the
second one found it did not exist for two of the three sensors.

If SENSOR-002 (mid) or SENSOR-003 (ground) had stopped answering, **no alarm
would have been raised and nobody would have been emailed.** The failure would
have appeared on the health page and made structural movement unavailable — both
of which require somebody to be looking at the screen.

Nothing was lost and no data was affected. The gap was in detection, not in
recording.

---

## How the problem was found

The question was about design, not about a suspected bug. The three layers are:

| Layer | Mechanism | Answers |
|---|---|---|
| 1 | `SensorHealth` — six checks per sensor | *Which sensor, and why* |
| 2 | `sensor_offline` liveness alarm | *Tell somebody, now* |
| 3 | Cross-sensor disagreement | *Is it the sensor or the structure?* |

Layer 1 and 3 were verified working. Layer 2 was queried out of habit rather than
suspicion:

```
SENSOR-001 liveness definitions: 1
SENSOR-002 liveness definitions: 0  <-- NO OFFLINE ALARM
SENSOR-003 liveness definitions: 0  <-- NO OFFLINE ALARM
```

---

## What was wrong

`AlarmEvaluator::provisionLivenessDefaults()` creates a per-sensor offline alarm
with thresholds derived from that sensor's own configured poll rates. It was
written, it was correct, and it was covered by two tests.

**It was called by nothing outside the test suite.**

```bash
$ grep -rn "provisionLivenessDefaults" app/ database/ tests/
tests/Feature/AlarmEngineTest.php:352:  ...provisionLivenessDefaults($this->sensor->fresh());
tests/Feature/AlarmEngineTest.php:627:  ...provisionLivenessDefaults($this->sensor);
```

The single definition that existed, `liveness:sensor:1`, was created by hand
during development. The other two sensors were registered later and never got
one.

Meanwhile `alarms:sweep` — scheduled every minute, and working correctly — walked
all three sensors looking for definitions to evaluate, and found one:

```php
Schedule::command('alarms:sweep')->everyMinute()->withoutOverlapping();
```

The sweep was not broken. It faithfully evaluated every liveness alarm that
existed. There was simply nothing to evaluate for two of the three sensors.

---

## Why it mattered more than it looks

**Ground is the reference sensor.** Subtracting it is what separates a passing
lorry, a blast or a seismic event from the silo actually settling. It is the
single most load-bearing instrument in the installation — and it was also the
least watched.

Losing it silently would have degraded every structural claim the product makes,
with the dashboard continuing to show plausible-looking numbers derived from two
sensors instead of three.

---

## Root cause

Not a logic error. A **wiring** error, in the sense of nothing calling something.

This is the seventh instance of one pattern in this repository:

1. the calibration subsystem
2. the bus capacity model
3. the scheduler
4. `publishMeasurements`
5. the dashboard process itself
6. the `quality` label that five of seven readers ignored
7. **this**

Every one was complete, correct, tested code that production never invoked.

### The variation that makes this one worse

The mechanism **had been invoked once, by hand, for one sensor.**

That is more dangerous than never having run at all. There *was* a liveness alarm
on the dashboard. It *did* fire correctly during testing. That working example is
precisely the evidence that stops anyone asking whether it covers everything.

> **One working instance is not coverage. Count them.**

---

## The fix

### 1. Coverage is now structural, not a remembered step

`alarms:sweep` provisions a missing liveness definition before evaluating. A
sensor registered at two in the morning is covered within the minute.

```php
if (! $evaluator->hasLivenessCoverage($sensor)) {
    $evaluator->provisionLivenessDefaults($sensor);
    $provisioned[] = $sensor->sensor_id;
    $sensor->load('channels');
}
```

Putting it in the sweep rather than in a new command was deliberate. A command is
another thing to know about, and the reason this bug existed is that somebody had
to know about `provisionLivenessDefaults()` and did not.

### 2. Only when absent

The thresholds are self-confirming — derived from the appliance's own poll rates
rather than from an engineering judgement about the structure, so they satisfy
ADR-015 without a named person. That makes re-deriving them look harmless.

It is not. An operator who widens the window would have their edit reverted
**once a minute, for ever, with no record of it.** So the sweep provisions only
where nothing exists, and there is a test pinning that.

### 3. It says so when it acts

```
$ php artisan alarms:sweep
provisioned a missing liveness alarm for 2 sensor(s): SENSOR-002, SENSOR-003
liveness sweep: 3 sensor(s) checked, 0 alarm change(s)
```

A self-healer that heals quietly hides the fact that a gap existed. The operator
should know their appliance was uncovered until that moment.

### 4. `LivenessCoverageTest` — the construction

Five tests that make the gap unable to recur:

| Test | Prevents |
|---|---|
| every active sensor ends up covered | a fourth sensor added silently uncovered |
| the sweep covers a sensor that has none | the exact live condition on 2026-08-10 |
| an operator edit survives a sweep | the self-healer reverting a human decision |
| retired sensors are not provisioned | permanent alarm noise from decommissioned units |
| thresholds come from the sensor's own rate | a 1-per-minute channel alarming between its own polls |

---

## Verification

**Coverage before and after:**

```
before:  SENSOR-001: 1    SENSOR-002: 0    SENSOR-003: 0
after:   SENSOR-001: 1    SENSOR-002: 1    SENSOR-003: 1

liveness:sensor:1  advisory=30  warning=120  critical=360
liveness:sensor:2  advisory=30  warning=120  critical=360
liveness:sensor:3  advisory=30  warning=120  critical=360
```

**Idempotent** — the second sweep produced no warning and made no changes.

**The tests genuinely bite.** A guard that never fires is worthless, and this
repository has shipped several. Disabling the self-heal (`if (false)`) fails four
of the five:

```
result: failed | failed: 4
 - test_the_sweep_covers_a_sensor_that_has_no_offline_alarm
 - test_every_active_sensor_ends_up_covered
 - test_it_does_not_overwrite_an_operator_edit
 - test_thresholds_come_from_the_sensor_own_poll_rate
```

**Full suite:** 338 backend, 114 frontend, 356 acquisition — all passing.

---

## Files changed

| File | Change |
|---|---|
| `app/Services/AlarmEvaluator.php` | added `hasLivenessCoverage()` |
| `app/Console/Commands/SweepAlarms.php` | provision when absent, warn when acting |
| `tests/Feature/LivenessCoverageTest.php` | new, 5 tests |
| `docs/known-limitations.md` | incident recorded |

---

## Follow-up: the same gap in the gravity check (fixed same day, `dae403d`)

Listed below as open, then closed immediately, because it was the identical
shape one layer along.

Liveness catches a sensor that has gone **quiet**. Nothing caught a sensor that
answers every poll, on time, with numbers that are **wrong** — a failed axis, a
wrong scale factor, a wrong register map, a mount that has come loose. That fault
appeared on the health page and nowhere else.

It is the more dangerous of the two. Silence is conspicuous; a sensor reading
0.71 g keeps delivering plausible-looking numbers into the settlement
calculation and the charts.

The check is a physical invariant: **an accelerometer at rest measures 1 g
whatever way it faces.** Orientation cancels out of the magnitude, so it needs no
baseline, no reference sensor and no assumption about the structure.

**Built as `sensor_gravity`**, provisioned by the same sweep and on the same
terms:

| | |
|---|---|
| advisory `0.02 g` | beyond the spread of uncalibrated units here — 1.00089, 0.98761, 0.99277 g |
| warning `0.05 g` | the tolerance `SensorHealth` already fails at, so page and alarm cross at one point |
| critical `0.15 g` | far above calibration error, far below the ~30% a failed axis costs |
| persistence `300 s` | a unit being carried to its mount reads whatever the hand does |

Two decisions worth recording. The magnitude comes from `SensorHealth` rather
than being recomputed — two implementations of the same physics would eventually
disagree, and a dashboard calling a sensor healthy while an alarm calls it faulty
is worse than either answer alone. And `requires_verified_profile` is false:
whether a sensor reads 1 g is *how you find out a register map is wrong*, so
gating it on the map being verified would disable the check exactly where it is
most needed.

Verified against the live appliance — all three sensors sit below advisory, so no
false alarm — and the tests bite: disabling the provisioning fails two of them.

## Third round: the commissioning fingerprint (`PENDING`)

The three limitations listed at the end of the last round shared one root cause:
the appliance had no idea what any individual sensor looked like when it was
working. Every judgement was against a general truth — *accelerometers read
1 g* — which has to stay loose enough for parts that are each wrong in their own
way. Here they read **1.00090, 0.98759 and 0.99276 g**.

`SensorFingerprint` records what a sensor reads at rest: magnitude, per-axis
static components, temperature, and the observed quantisation step. Captured
with `sensor:fingerprint capture`, alongside `tilt:baseline capture`, as a
commissioning step.

### Gap 2 — absolute versus relative: **closed**

A unit that has always read 0.98759 g and now reads 0.955 g has moved by far
more than any calibration error while remaining comfortably inside the absolute
1 g tolerance, invisible to the general check. Compared against its own
signature it is obvious.

New `sensor_drift` alarm, thresholds set as multiples of the **measured**
quantisation step (~0.0005 g) rather than as round numbers: 0.005 advisory,
0.010 warning, 0.030 critical, held for an hour because thermal drift is real
and reverses.

### Gap 3 — cross-sensor arbitration: **closed**

`arbitrate()` compares a sensor's drift against its peers'. One unit moving
while its neighbours hold still is that unit. All three moving together is the
environment, and reporting that as three simultaneous instrument faults would
send somebody to replace three working parts. The drift alarm refuses to raise
in that case.

This is reference differencing applied to health rather than to the structure,
and it works for the same reason: **what is common to every sensor is not a
property of any one of them.**

### Gap 1 — cross-axis blindness: **not closable, and now measured**

The plan was a noise-floor check — a live MEMS part is never perfectly still, so
zero variance means a dead channel. Before building it I measured:

```
SENSOR-001 z=+1.00055 (sd 0.000144)
SENSOR-002 z=+0.98730 (sd 0.000000)   <-- zero
SENSOR-003 z=+0.99268 (sd 0.000000)   <-- zero
```

Two of three healthy sensors have **exactly zero variance**. The quantisation
step is 0.000507 g and they are stiller than that, so each sits inside a single
code for ten minutes at a time. SENSOR-001 differs only because it happens to
straddle a code boundary and dithers between two values.

**A variance-collapse check would have alarmed on two of three good sensors on
the day it shipped.** It was not built.

Nor is the magnitude check any help here: z carries 0.99 g while x and y carry
0.002–0.027 g, so losing a horizontal axis moves |a| by less than one code. A
dead horizontal axis is **not detectable at rest on this hardware** — by any
arithmetic on these numbers.

That is now stated in `limitations` on every health report rather than left for
somebody to discover. It is deliberately **not** a check: one that can never
resolve would sit at `unknown` for ever on a healthy sensor and drag its status
down, which teaches people to ignore the word.

### Three things this round got wrong first

**Required a quiet hour.** The first capture refused on all three sensors —
this bench peaks at 0.04–0.09 g from people at a desk, and a site with traffic
may never give a clean hour. A commissioning step that cannot complete is not a
safeguard; somebody would eventually widen the threshold until it passed, which
is the same as not having one. Now filters **quiet minutes within** the window,
which is what `TiltMonitor` already does against the same 0.02 g.

**Rendered verdicts on noise.** Idle drift is ~0.00003 g against a 0.0005 g
step, and the first `arbitrate()` confidently reported *"this unit"* for it. It
now refuses to judge anything below what the hardware can resolve.

**Let a moving structure degrade the instrument verdict.** Resting magnitude
genuinely cannot be measured while a silo shakes — but reporting that as
`unknown` meant a structure doing something interesting made its own sensors
look unwell. Exactly the confusion the page exists to prevent. A disturbed
window is now a limitation, not a check state; a *missing* fingerprint is a
`warn`, matching how the tilt baseline and calibration checks already report an
undone commissioning step.

### Verified

```
$ php artisan sensor:fingerprint capture --minutes=60
SENSOR-001 captured: |a| = 1.000902 g  (x +0.00235, y +0.02678, z +1.00054)
SENSOR-002 captured: |a| = 0.987591 g  (x +0.00665, y +0.02282, z +0.98730)
SENSOR-003 captured: |a| = 0.992762 g  (x +0.01207, y +0.00509, z +0.99268)

$ php artisan sensor:fingerprint drift
SENSOR-001  0.000034 g   resolvable above 0.000507 g   below resolution
SENSOR-002  0.000009 g   resolvable above 0.000500 g   below resolution
SENSOR-003  0.000003 g   resolvable above 0.000500 g   below resolution
```

All three sensors covered by liveness, gravity and drift alarms. No new alarms
raised. 350 backend, 114 frontend, 356 acquisition.

---

## Still open

- **Structural cross-sensor comparison is still manual.** Health-level
  arbitration is now automatic (above), but the *structural* version — a sensor
  reporting movement its neighbours do not see, when a silo cannot move in one
  place only — still needs a human reading the Structure page.
- **A dead horizontal axis remains undetectable at rest.** Measured, not
  assumed — see the third round above. It is detectable only when something
  moves, since all three axes should respond to a real event and one that stays
  flat while its siblings move is dead. That check needs a stimulus, so it
  cannot be verified on a quiet bench and has not been built. It is the right
  thing to add once the sensors are on the silo.

---

## The lesson

The bug was not in any of the code involved. `provisionLivenessDefaults()` was
right. `alarms:sweep` was right. The scheduler was running. Every test passed.

**Check what calls it, not just what it does.** And when a feature demonstrably
works, that is the moment to ask how many instances it actually covers — because
a working example is the strongest possible argument against looking closer.
