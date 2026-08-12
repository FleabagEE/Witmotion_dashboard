# Fix report — sensor coverage, and what checking it uncovered

Four rounds, all starting from one question: *if a sensor failed, how would we
know?* Each answer exposed the next gap.

| Round | Fault | Commit |
|---|---|---|
| 1 | Two of three sensors had no offline alarm | `ff254a8` |
| 2 | Nothing alarmed on a sensor that talks but cannot be believed | `dae403d` |
| 3 | Every judgement was against a general truth, never the sensor's own | `dc6816c` |
| 4 | 99.7% of the batch audit trail was never recorded | `56df9eb` |
| 5 | A dead axis was called undetectable; it was not | `cd953f1` |
| 6 | A sensor swap was detected, then suppressed as environmental | `7fa88ba` |
| 7 | **Round 6's fix did not work.** A real swap disproved it | `e805ea7` |

---

## Round 1 — two of three sensors had no offline alarm

**Date:** 2026-08-10
**Commits:** `ff254a8`, `dae403d`, `dc6816c`, `56df9eb`
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

## Third round: the commissioning fingerprint (`dc6816c`)

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

## Fourth round: the batch audit trail was mostly not recorded (`56df9eb`)

Found by answering a design question about `batch_uid`, not by anything failing.

### What was wrong

The forwarder identified each batch as `records[0].idempotency_key[:64]` — the
first record's key, truncated to the column width. Both halves of that were
wrong.

An idempotency key is `appliance:run:sensor:group:sequence`, and `run_id` is 32
hex characters. On this appliance the prefix alone reaches 73:

```
QV-EDGE-001:c0c63898e03a47918baebb544674522c:SENSOR-001:condition_x:12345
|<------------------------ 64 ------------------------>|
```

**The sequence — the only part that differs between batches — was cut off
entirely.** The damage is visible in the recorded ids, truncated mid-word:

```
QV-EDGE-001:c0c63898e03a47918baebb544674522c:SENSOR-002:fault_di   offered=2
```

Every batch from one `(appliance, run, sensor, group)` therefore shared a single
id. `ingest_batches` writes with `insertOrIgnore`, so only the first was ever
recorded: **551 audit rows for hundreds of thousands of delivered records.**

### The second defect, which truncation does not explain

Even at full length, the first record's key is the wrong identity. Batch
composition is *"the oldest 200 still-eligible records"*, and that set is not
stable:

```
Batch A  = [R1 … R200]                    uid = key(R1)
R1…R50 hit the retry ceiling, excluded.
R51…R200 deliver successfully.
retry-dead-letters --confirm  →  R1…R50 revived
Batch B  = [R1…R50, R201…R350]            uid = key(R1)   ← same id, different batch
```

Not hypothetical: 31,307 records were revived exactly this way on 2026-08-06. A
change to `batch_size` produces the same effect with nothing but a config edit.

### What was and was not damaged

**The measurements were never at risk.** They deduplicate on their own,
untruncated key in `ingested_polls`, which was never shortened.

**The audit trail was.** `ingest_batches` records what was offered, accepted,
rejected and when — the table you would reach for to answer *"what did the
appliance send us on the night of the 4th"*. Roughly 0.3% of it survived, and
where two batches collided the surviving row describes the wrong one.

### The fix

```python
batch_uid = sha256("\n".join(sorted(idempotency_keys))).hexdigest()
```

Content-addressed. Two batches holding the same records **are** the same batch —
which is exactly what an idempotent retry is — and two holding different records
cannot collide. Sorted, so a re-ordering of identical contents is still one
batch. SHA-256 hex is 64 characters, the column width exactly: **nothing is
truncated, and nothing can be.**

Historical rows are left alone. They record what happened, and the old format is
part of that.

### What it costs, stated

| Cost | Severity |
|---|---|
| Ids are opaque — no longer greppable by sensor or run | real, mitigated by the existing `appliance_id` column |
| Two attempts at one batch are indistinguishable | needs a separate `attempt_uid` if per-retry analysis is ever wanted |
| Not time-ordered, unlike a ULID | covered by `received_at` |
| **A deliberate replay is silently absorbed** | the sharpest one — identical content hashes identically, so a disaster-recovery replay would leave no audit row |
| Hashing 200 short strings per batch | microseconds |

The complete design is **content hash for identity plus a random id for
occurrence**. Only the first half shipped, because that is what fixes the live
defect; the second is worth adding if this appliance ever supports replay.

### Verified

```
before:  551 audit rows accumulated over 11 days
after:   29 audit rows in 2 minutes

40ac2c70e434721ad33236b7be27dc2f7e36750f112afe7a962a914d7ea6eedc  offered=3
bb66ae360f5545dc3c9e83e27c1d1676dc0928e4bb26e29d123d88e0017ffe91  offered=2
```

Six tests, including one asserting that two batches whose keys are identical for
their first 64 characters produce different ids. Verified to bite: restoring the
old expression fails three of them. 362 acquisition tests pass.

### The shape of this one

Nothing failed. No alarm, no error, no missing measurement — the appliance
behaved correctly in every respect a test or a user would notice, while quietly
recording 0.3% of its own audit trail.

> **A truncation is a silent lossy compression of an identifier.** Whenever a
> value is cut to fit a column, ask what varies in the part being cut. Here the
> answer was: everything that mattered.

---

## Round 5 — the dead-axis limitation was overstated, and the check now exists

Round 3 concluded that a dead horizontal axis is undetectable at rest, and
recorded it as a permanent limitation. That conclusion was wrong.

Two things were established and both were correct:

- the magnitude cannot see it — z carries 0.99 g while x and y carry
  0.002–0.027 g, so losing a horizontal axis moves |a| by less than one
  quantisation code
- the static noise floor cannot see it either — two of three healthy sensors sit
  at *exactly* zero variance, because they are stiller than the 0.000507 g step

Both are about the **static** channels. The **amplitude** channels are a
different measurement — the AC component — and were never checked. Measured over
an hour, all nine:

```
zeros     0 of 3,599      every channel, every sensor
distinct  23 to 74        never fewer than 23 values in an hour
minimum   0.0049-0.0068 g  ten to fourteen codes above zero
```

**A live axis is never flat.** An amplitude channel that is constant, or zero,
while its siblings vary is not measuring — whatever the underlying cause.

### Built as `axis_response`

| Situation | Result |
|---|---|
| all three varying | `pass`, with the distinct count per axis |
| one or two flat, siblings varying | `fail`, naming the axis |
| all three flat | **not attributed to any axis** — that is the whole sensor, or an installation with no vibration at all, and this data cannot separate them |
| under 60 samples | `unknown` — a short window legitimately holds few values |

A stuck channel latched at a plausible non-zero value counts as flat too, and is
harder to spot by eye than one reading zero.

### Verified

All three sensors pass on live hardware: *all three responding (x: 6 values,
y: 7 values, z: 10 values)*. Five tests, verified to bite — disabling the flat
detection fails three of them.

One fixture bug fell out: `SensorHealthTest::healthy()` wrote no amplitude
channels at all, so it had been describing a sensor with three dead channels and
calling it healthy. It now writes what a healthy unit actually produces.

### The lesson

The original limitation was written from the two approaches tried, not from the
data as a whole. It was thorough about what it had examined and silent about
what it had not.

> **"I could not find a way" is not "there is no way", and the difference belongs
> in the wording.** A limitation recorded in a shipped document is a claim about
> the world, and it will be believed.

---

## Round 6 — a sensor swap was detectable, then suppressed

> ⚠️ **The fix described in this round was wrong and has been removed.** A real
> physical swap on 2026-08-11 disproved it. Read round 7 before acting on
> anything below. The *diagnosis* here still holds; the *remedy* did not.

Asked while reading Lesson 6: *how does the system know which sensor is top, mid
and ground, and can the adapters be plugged in a different order?*

**They cannot.** Position comes entirely from USB topology:

```
socket 1-1.1  →  /dev/quakevault-rs485-p1  →  SENSOR-001  →  top
socket 1-1.2  →  /dev/quakevault-rs485-p2  →  SENSOR-002  →  mid
socket 1-1.4  →  /dev/quakevault-rs485-p4  →  SENSOR-003  →  ground
```

Nothing about a sensor carries its own identity. The CH341 adapters have no
serial number — the same udev file keys on `ATTRS{serial}` for FTDI and CP210x
parts, and falls back to `KERNELS` for these precisely because there is nothing
else. All three answer at address 0x50, and the part has no WHO_AM_I register.

Move two adapters between sockets and top and mid exchange places, permanently
and silently.

### The part I had wrong

Lesson 3 claimed the gravity fingerprint would catch a swap. Checking the
arithmetic against what was actually built: **it would not.**

These read 1.00090, 0.98759 and 0.99276 g. Swapping the first two moves each by
0.0133 g — twenty-six times the resolution and well past the 0.010 g warning. So
the drift check fires.

Then `arbitrate()` suppresses it. Both sensors drifted by the same amount at the
same moment, which is precisely the signature of a shared environmental cause,
so the alarm is withheld as *"every sensor has moved similarly"*.

**The mechanism built to stop one fault being reported three times turned a swap
into no report at all.**

### The fix

A swap has a signature drift alone does not: **the two sensors moved toward each
other's baselines.** Checkable, not inferable.

`crossMatch()` compares every sensor's current magnitude against every stored
baseline. A sensor "looks like" another when it sits dramatically closer to that
baseline than to its own — half the distance, a wide margin that ordinary drift
never reaches. **Mutual resemblance is the proof:** one sensor resembling another
can be coincidence, two resembling each other simultaneously cannot.

`arbitrate()` now checks for a swap *before* deciding anything is shared, so the
suppression cannot hide it. Surfaced as an `identity` check on the health page
and as `SWAPPED with …` in `sensor:fingerprint drift`.

### When it cannot answer

Two sensors whose baselines are closer together than the hardware can resolve are
not separable this way, and it says so rather than guessing. The live pairs are
0.0133, 0.0081 and 0.0052 g apart against a 0.0005 g step — ten to twenty-seven
times resolvable. Two units from one production batch might not be.

### Verified

No false positives live — all three report `looks_like: null` with drift under
0.0005 g. Five tests, including one asserting a swap is *not* suppressed as
environmental; disabling the override fails exactly that one.

### Still the better answer, and the distinction that matters

Detection is the fallback. But note that **identity has two halves here, and
they fail independently:**

| | Has an identity? | So you cannot ask |
|---|---|---|
| The **sensor** (WTVB01-485) | no WHO_AM_I, no serial | *"which sensor are you?"* |
| The **adapter** (CH341) | no USB serial number | *"which adapter are you?"* |

That second one is why udev keys on topology at all — the same rules file keys
on `ATTRS{serial}` for FTDI (`0403`) and CP210x (`10c4`) parts, because those
chips carry one.

So the two preventions fix **different** failures:

| Fix | Fixes | Does not fix |
|---|---|---|
| **FTDI or CP210x adapters** | adapters moved between USB sockets — identity follows the adapter | a sensor moved from one adapter to another |
| **Distinct Modbus addresses** (0x50/0x51/0x52) | both — the wrong device simply does not answer | — |

**Which matters depends on the silo wiring, and that decision comes first.**
Three separate cable runs means three adapters and a real socket-order problem,
so FTDI is worth buying before mounting. One shared RS-485 run means one adapter
and no order to get wrong — but then distinct addresses become *mandatory*,
because otherwise all three sensors answer simultaneously on a half-duplex line.

On a silo, *"which cable did the electrician put back where"* is not a
hypothetical. **Label the USB sockets, not just the sensors** — the sockets are
what actually decide.

---

## Round 7 — the fix from round 6 did not work, and a real swap proved it

The only round here written from a physical experiment whose outcome was not
known in advance. It is also the only one where a shipped, tested feature was
**deleted** rather than repaired.

### The test

Two adapters were physically exchanged between hub ports 1 and 2, with the third
left alone as a control.

### The result

| | |
|---|---|
| `crossMatch()` | `looks_like: null` on both — **detected nothing** |
| `arbitrate()` | **"shared (environmental)"** — suppressed the drift alarm that *had* fired |
| Health `identity` check | did not fire |
| Alarm, email | nothing |

**A real sensor swap produced no alarm, no email and no failing check.**

### Why

The magnitudes moved, but *away* from each other rather than toward:

| Slot | Should read (its new unit's baseline) | Actually read |
|---|---|---|
| SENSOR-001 | 0.98759 | 1.02659 |
| SENSOR-002 | 1.00090 | 0.96365 |

Each unit shifted about **0.038 g from its own baseline** simply by being
handled — a z-only offset with x and y unchanged, consistent with cable strain
flexing the package, a well-known MEMS effect.

```
difference between units      0.0133 g    ← the signal
shift caused by handling      0.0380 g    ← the noise
```

> **You cannot identify a thing by a property that changes more when you touch it
> than it differs between things.**

The tests passed because they fed clean synthetic magnitudes with no handling
term. **They tested the arithmetic, not the physics.**

### What did work

Chip temperature, in a single five-minute bucket:

```
19:10   S1 26.22   S2 25.74
19:15   S1 25.76   S2 26.26     ← exchanged
```

The untouched third sensor continued warming with no discontinuity. Replaying
that window through offset-from-group-mean logic marks both swapped slots and
leaves the third alone — validated against the real event, not a fixture.

### Why it ships as a bench command, not a monitor

It works only while the sensors share an environment. On a silo — one in sun at
the top, one in shade at the ground — ambient differences of ten or twenty
degrees bury a half-degree chip bias, and each unit converges to its new
position's temperature within the hour, erasing the evidence.

Shipping it as a continuous field check would be the same error in new clothes.
So: `sensor:verify-wiring`, run deliberately before mounting, refusing when the
biases are within 0.25 °C.

### The suppression was the worse bug

Missing a detection is a gap. **Silencing a detection that fired is a
regression**, and the arbitration did the second.

The fix is not a cleverer suppression rule:

> **Never silence. Explain.**

The drift alarm now always raises and carries the arbitration's opinion in its
metadata. An operator can read *"every sensor moved together, this looks
environmental"* and judge. They cannot read an alarm that was never raised.

### The shape of this one

Third time this week a plausible physical assumption failed on contact with
hardware — after *zero variance means a dead axis*, and after *a dead horizontal
axis is undetectable*.

This one is the most instructive because **it had already shipped**: tests, a
mutation check, a docstring arguing its reasoning, and an entry in this very
document calling it the fix. All of that was true about the code and false about
the world.

**Test the physics, not the arithmetic.** A synthetic fixture agrees with
whatever assumption produced it.

---

## Round 8 — a replug left the sensor dead for ever, and two fixes for it were worse (`ef1d73d`)

### The defect

Acceptance case 8 was recorded **PASS**: *"sensor removed to a Windows machine
and returned; 4-minute gap, then automatic recovery."* True, and it tested the
easier half of its own name — the device returned as the **same node**.

A real replug does not do that. The kernel destroys `/dev/ttyUSBn` and creates a
different one, and acquisition is left holding a file descriptor on a node that
no longer exists. It never noticed. On the re-run the sensor was still dead 103
seconds after being plugged back in, and only a service restart revived it.

This had already happened by accident: three sensors, 3,366 rows, **zero
readings**, thirty-seven minutes, nothing alarming. That incident is round 7's
`last_good_measurement_at`. This round is the other half — not noticing it, but
recovering from it.

### The fix

The bus worker asks, once every 5 s, whether the node it holds still exists:

```python
return os.fstat(fileno()).st_nlink == 0
```

`st_nlink == 0` means *this inode has no name in the filesystem any more*. Not a
proxy for the condition — the condition itself. The obvious alternative, storing
the device path and comparing it, would have failed on the very replug used to
verify this:

```
device changed under us (/dev/ttyUSB0 -> /dev/ttyUSB0); reopened
```

Same name, different inode, because the kernel reuses freed numbers. A path
comparison concludes nothing has changed.

### Two fixes that were worse than the defect

Both passed the acceptance script.

**Attempt 1 recovered by dying.** `_blocking_read` still held
`assert self._reader is not None`, which fires on exactly the path where a
reopen has just closed the port. The process crashed, systemd restarted it, the
sensor came back — and `NRestarts` went to 6 while **all three sensors** were
dark for about ten seconds. A fault on one bus had killed the other two. The
three buses are separate threads on separate ports precisely so that cannot
happen; an assertion put the coupling back. `_blocking_read` now returns a bad
`GroupReading`, which opens that bus's breaker and leaves the others alone.

**Attempt 2 tried once and gave up.** `restarts: 0`, controls untouched — and
SENSOR-003 stayed dead for 78 seconds with a single `reopen failed` in the log
and silence after it. A reopen closes the old port before opening the new one,
so while the device is out the worker holds *no reader*, and `_device_is_stale()`
answered "not stale" because it had nothing to ask. The first failed reopen was
the last.

Fixing it meant deleting `test_no_connection_is_not_stale` — written an hour
earlier, well commented, passing. It asserted the assumption that cost the
sensor 78 seconds. **A test can be the thing preserving the bug.**

### Verification

```
15:33:43  GONE           unplugged
15:34:06  reopened       (after two rate-limited failures at :56 and :01)
15:34:11  good readings resumed

outage 39s, recovery 33s of a 120s budget, NRestarts 0
SENSOR-001 4871 good, SENSOR-002 4872 good
```

### The harness was the real problem

It reported PASS for both bad attempts. It watched the sensor under test and
nothing else, so its verdict was **true and useless** — the identical shape to
case 13's reboot PASS, and to case 8's own original verdict. Third instance in
this repository.

Restart count and control-sensor liveness are now part of the verdict rather
than commentary beside it. And a preflight aborts if the control query returns
nothing, because that check's failure mode is silence: an escaping bug made it
return an empty string, `worst_control` stayed 0, and criterion 6 passed
**without ever being evaluated**. A check that cannot fail is not a check.

---

## Round 9 — sweeping the repository for claims nothing enforced

After `discovery.py` was caught asserting a read-only guarantee it did not have
(ADR-026), the obvious question was how many other comments in this repository
promise something *cannot* happen without a test behind them.

### Method

Parse every Python and PHP file, extract docstrings, docblocks and comments, and
keep lines containing an absolute — *cannot*, *never*, *impossible*, *must not*,
*always*, *refuses to*, *by construction*, *structurally* — with hedged sentences
(*may*, *might*, *usually*) dropped.

**235 claim-shaped lines.** Most are honest and welcome: statements of what a
check *cannot see*, which is the right way to write about a limitation, and
rationale about the world rather than promises about our code. Those are not
defects.

What matters is the smaller set asserting a **runtime invariant**, and for each
of those the test is not "is it true" but **"what happens if I break it"**. Every
finding below was confirmed by mutating the code and watching the suite.

### Already protected

Checked and found to have real tests behind them: ordered spool replay, batch_uid
collision resistance past 64 characters, the circuit breaker, dead-letter
envelopes not being retried for ever, refusal of a duplicate slave address,
refusal of the broadcast address, refusal to boot on an unverified profile, and
the address tool confirming a device answers after a write. `address_cli.py` in
particular is thoroughly covered — twelve tests for a tool with one job.

### Four claims nothing kept true

| Claim | Broke it by | Suite said |
|---|---|---|
| "no two transactions on this port can ever overlap" | `gather()` over due tasks + widening the pool | **passed** |
| bus executor is one thread | `max_workers=8` | **passed** |
| "a fault in it must not be able to lose a reading" | removing the try/except around derivation | **passed** |
| alarms evaluated outside the ingest transaction | moving the call inside the closure | **passed** |

All four were *true* when checked. Nothing was keeping them true.

The half-duplex one is the sharpest. Two overlapping transactions on RS-485 do
not queue, they collide, and the result is not silence but corrupted frames
decoded into plausible numbers — the failure ADR-005 exists to prevent, arriving
by a different road.

### Two comments that were wrong

**"Oldest deadline first, so a fast group cannot starve a slow one."** The sort
does not prevent starvation. Every task that is due is polled in the pass that
finds it, whatever order they are in — replacing the sort with `due.reverse()`
starves nothing. What the sort provides is *bounded lateness*: the group waiting
longest goes first. The comment now says that, and `_due_in_order()` was
extracted so the scheduling policy can be called from a test at all.

**"An alarm-engine fault must never roll back data that arrived correctly."**
True, but the transaction boundary is not what provides it. `evaluateAlarms()`
catches `\Throwable` per reading, so a PHP fault never reaches the transaction
wherever the call sits. The boundary earns its place against *database* faults:
PostgreSQL aborts a transaction on any statement error and refuses everything
after it, so an alarm write that errors inside the ingest transaction takes the
measurements with it however well the PHP exception is caught. Two mechanisms,
different faults; the comment credited one with both.

Its docblock had also drifted three methods away from the function it described.

### Three tests of my own that proved nothing

Worth recording, because the sweep kept catching the same disease in its cure.

1. **A starvation test that never touched the sort.** Written to cover the
   claim, passed with the sort reversed. It was testing the poll-everything
   loop, which is a real property — just not the one the comment named.
2. **A deadline-ordering test that could not see a batch.** It inferred batch
   boundaries from poll timings; batches were nearly always one task, so there
   was no order to get wrong. Passed with `due.reverse()`. Replaced with a
   direct call to `_due_in_order()`.
3. **A transaction test whose fault was swallowed.** It threw a `RuntimeException`
   from the alarm engine — which `evaluateAlarms()` catches per reading, so
   nothing reached the transaction and the measurements survived regardless of
   where the call sat. A *database* fault does reach it, but `RefreshDatabase`
   wraps each test in a transaction of its own, so the Postgres abort poisons
   the test's own connection and even the assertion fails with 25P02. Settled on
   asserting `DB::transactionLevel()` at the moment the alarm engine runs, which
   is what the docblock actually claims.

### The rule

**Any comment asserting that something cannot happen is a missing test until
proven otherwise** — and the proof is a mutation, not a reading. Three of the
four gaps here sat under comments so clearly written that they stopped anyone
looking, which is the mechanism by which good documentation becomes a hazard.

---

## Round 10 — a hardening directive that had never done anything

`systemd-analyze verify` was printing this on four units, and had been all along:

```
System call ~@resources is not known, ignoring
```

Each carried:

```
SystemCallFilter=@system-service
SystemCallFilter=~@privileged ~@resources
```

**One `~` makes the whole line a denylist. It must not be repeated.** systemd
parsed the second token as a syscall literally named `~@resources`, found no such
syscall, and ignored it. `@resources` was never denied.

### Measured, not assumed

The first attempt to measure this was wrong and its own sanity check caught it:
`systemd-analyze syscall-filter @system-service` returns *nested group names*,
not syscalls, so comparing its output against `@resources` compared group names
to syscall names and found an empty intersection. That would have been reported
as "the fix changes nothing" — comfortable, and false. The check that saved it
was asserting `read` appears in the allowlist; it did not, which is impossible
for a working service and meant the method was broken.

Flattened properly, all thirteen `@resources` syscalls were reachable:

```
ioprio_set  mbind  migrate_pages  move_pages  nice  sched_setaffinity
sched_setattr  sched_setparam  sched_setscheduler  set_mempolicy
set_mempolicy_home_node  setpriority  setrlimit
```

Effective allowlist **356 → 343**, all thirteen now absent, verified one at a
time rather than by the absence of a warning.

### Why this was not a one-character fix

Correcting the syntax genuinely starts blocking those calls, so it is a
behaviour change wearing a typo's clothes. Applied one unit at a time — reverb,
live bridge, forwarder, acquisition, least critical first — restarting and
checking each before the next. All four run clean: 0 restarts, no EPERM in any
log, three sensors at 1-2 s, 1,767 rows in 30 s, dashboard 200, 392 + 369 tests
passing.

Deliberately sequenced **after** the reboot that closed case 21. Changing syscall
filters immediately before a reboot test would have confounded it: if something
had failed to come back, nothing would say which change did it.

### The pattern, again

This is round 9's finding in a new place. A directive that stated a security
property, was believed by everyone who read it, and did nothing — with the tool
that detects it printing the warning the whole time, in output nobody read.

Same shape as the mosquitto healthcheck failing 7,665 times against a working
broker, and the SHM validation timers failing every five minutes for six days.

**A warning nobody reads is indistinguishable from no warning.**

---

## Round 11 — tilt monitoring covered one sensor of three

Found while trying to answer a much smaller question: whether 0.5 deg was a
sensible tilt warning threshold.

```
SENSOR-001: raw +0.8224 deg, temp explains +0.0000, movement +0.8224 deg
SENSOR-002: no baseline - not commissioned for tilt
SENSOR-003: no baseline - not commissioned for tilt
1 sensor(s) checked, 0 alarm transition(s)
```

**Nothing was broken.** `tilt:check` was scheduled and running every five
minutes. `SensorHealth` already returned `warn` on the missing baseline for both
sensors. The command reported each skip honestly. Every component did its job.

What no component did was add up. *"1 sensor(s) checked"* is true and does not
say **of how many**, and no screen, command or endpoint anywhere stated the one
sentence that matters:

> **Tilt monitoring covers 1 of 3 sensors.**

A silo whose middle and ground positions cannot detect settlement is a different
product from the one the client believes they have. Third instance of this exact
shape after the liveness alarm (round 1) and the gravity check: a capability
provisioned by hand for one sensor and never extended to the others.

### Why this one cannot self-heal

Round 1 was fixed by having `alarms:sweep` provision what was missing. Tilt must
not be treated that way, and `TiltCheck` already said why:

> A sensor that has not been commissioned has nothing to be measured against,
> and inventing a baseline from whatever it reads today would silently define
> its current lean as correct.

A silo already leaning is precisely the case the product exists to catch.
Auto-capturing would record that lean as the zero point and report a moving
structure as still — worse than no monitoring, because it looks like monitoring.

So `MonitoringCoverage` fixes nothing. It makes the gap impossible to miss:
counted, named, with the remedy attached, in the health endpoint next to the
data and in the command's own output.

### Two corrections to what I reported along the way

**"No tilt alarm has ever fired" was wrong.** The query behind it used a 7-day
window; the event was raised on 2026-08-05 18:50 and today is the 12th, so it
fell a few hours outside. `tilt-deviation-SENSOR-001` has been in `warning` the
whole time and still is. A narrow check reported as a broad conclusion — the
same error this document keeps recording.

**The first version of the fleet-status test proved nothing.** It asserted that
coverage does not worsen the top-level status, and passed with the mutation
applied — because in its fixture every sensor read `fail` for want of any
measurements, already the worst state, so folding coverage in changed no answer.
Rebuilt with a sensor that reports data and reads `warn`; it now fails on that
mutation. Six of the nine tests were mutation-checked one at a time.

### Verification

| Mutation | Caught by |
|---|---|
| treat a present-but-empty baseline as coverage | `test_an_empty_baseline_is_not_a_baseline` |
| count retired sensors | `test_retired_sensors_are_not_counted_against_coverage` |
| `warn` instead of `fail` at zero coverage | `test_no_coverage_at_all_fails_rather_than_warns` |
| always offer a remedy | `test_full_coverage_passes_and_offers_no_remedy` |
| drop coverage from the endpoint | `test_the_health_endpoint_carries_coverage` |
| fold coverage into fleet status | `test_coverage_does_not_make_the_fleet_status_worse` |

378 backend tests pass.

---

## Still open

- **Structural cross-sensor comparison is still manual.** Health-level
  arbitration is now automatic (above), but the *structural* version — a sensor
  reporting movement its neighbours do not see, when a silo cannot move in one
  place only — still needs a human reading the Structure page.
- ~~A dead horizontal axis remains undetectable at rest.~~ **Wrong, and fixed
  the same day.** See round 5 below.

---

## The lesson

The bug was not in any of the code involved. `provisionLivenessDefaults()` was
right. `alarms:sweep` was right. The scheduler was running. Every test passed.

**Check what calls it, not just what it does.** And when a feature demonstrably
works, that is the moment to ask how many instances it actually covers — because
a working example is the strongest possible argument against looking closer.
