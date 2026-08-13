# Lesson 11 — The Alarm State Machine

**Modules:** `backend/app/Services/AlarmEvaluator.php`, `NotificationDispatcher.php`
**Related decisions:** ADR-013, ADR-015, ADR-017

> The last of the three lessons where the real architecture lives.

---

## Why it exists

The evaluator's docstring opens by dismissing the obvious:

> The hard part of an alarm engine is not deciding that 8.2 exceeds 7.1. It is everything around that.

Every engineer who has not built one thinks an alarm system is a comparison. Every engineer who has built one knows the comparison is perhaps 2% of the work, and that the other 98% is about **human attention** — a resource more scarce and more easily destroyed than CPU or bandwidth.

---

## What problem it solves

### 1. Four mechanisms, four distinct failure modes

ADR-013 lists them, and each corresponds to a specific way a naive threshold check fails:

| Mechanism | The failure it prevents |
|---|---|
| **Hysteresis** | A value resting on the boundary raises and clears hundreds of times |
| **Persistence** | One noisy sample wakes somebody at 03:00 |
| **Debounce** | A flapping input produces a transition storm |
| **Latching** | A transient excursion vanishes before anyone sees it |

They are not interchangeable and you cannot substitute one for another. Hysteresis is about *value*; persistence and debounce are about *time*; latching is about *memory*. A system with only hysteresis still floods on a noisy sensor. One with only persistence still chatters at the boundary.

**Hysteresis** is the Schmitt trigger, moved into software. Raise at 7.1, clear at 6.8. The gap must exceed the noise amplitude or the mechanism does nothing.

**Persistence** with separate raise and clear budgets, and ADR-013 explains why they differ:

> Raise and clear budgets are separate because operators generally want a fast raise and a slow clear.

That asymmetry is a *human* requirement, not a signal-processing one. Tell me quickly that something is wrong; be slow to tell me it is fine again. An engine with one symmetric persistence window cannot express it.

**Latching** is the subtlest:

> so a transient excursion still demands acknowledgement after the value has returned to normal — the machine did something, and somebody should see it even if the evidence is gone.

A 200 ms spike at 03:00 that has cleared by 08:00 is invisible on any chart an operator will actually look at. Latching converts a moment into an obligation.

And note the state-persistence detail:

> The candidate level and its timestamp are stored so a partially satisfied condition survives a restart rather than resetting its countdown.

Without that, a service restart silently resets every in-progress persistence timer. On an appliance that restarts for upgrades, a condition could persist for hours and never raise. **State machines that span time must survive process lifetime.**

### 2. A missing reading is not a low reading

The most important twenty lines in the file:

```php
// A failed read is not a low reading. Alarming on it would report a
// machine as healthy when in fact nothing was measured at all; sensor
// liveness is a separate condition type.
if ($value === null || $quality === 'bad') {
```

and ADR-013:

> A failed read never alarms. A null value with 'bad' quality is not a low reading — nothing was measured. Sensor liveness is a separate condition type, so that a dead sensor reports as dead rather than as a healthy machine.

Consider the naive implementation. Vibration alarm: raise if velocity > 7.1 mm/s. Sensor dies, read fails, value is null. `null > 7.1` is false in most languages. **No alarm. Green dashboard. Dead sensor.**

The system reports maximum health at the exact moment it has lost the ability to observe anything at all.

This is Lesson 3's quality taxonomy paying its debt. `BAD` exists so that this branch can exist. And the resolution is not "alarm on null" — it is that **absence of evidence is its own condition type**, monitored separately.

> **The absence of a measurement must never be processed as a value.** Every alarm system must answer "what happens when the input is missing?" explicitly, and the answer is never "compare it anyway".

### 3. Thresholds must be owned by a named human

ADR-015, and it is the same shape as ADR-005 from Lesson 4:

> **Decision.** Until a named person records that they checked the numbers against a real source, alarms raised from those thresholds are marked `provisional`: shown on the dashboard, never sent to anybody.
>
> **Context.** DIN 4150-3 and BS 7385-2 are copyrighted documents that must be purchased, and this project does not hold them. The shipped guideline values are transcribed from working knowledge and cannot be verified against the source. That is not a reason to ship them silently as though they were authoritative: mistaking `sensitive` for `commercial` would be a factor of seven at low frequency, and a listed building would sit unmonitored until it was seven times past the damage threshold.

Then the connection is made explicitly:

> The register-map gate (ADR-005) already caught two errors of exactly this kind on this project. The same discipline applies to numbers that decide whether a building is at risk.

**The same gate, applied twice, to the two things that can be confidently wrong: how you read the sensor, and what you compare the reading to.**

Two details show real care. Liveness thresholds self-confirm, because they derive from the appliance's own poll configuration — there is no external document for a human to check them against, so demanding a signature would be theatre. And:

> An event keeps the status it was raised under, so confirming later does not retroactively make a past alarm look authoritative.

You cannot backdate credibility. An alarm raised under provisional thresholds stays provisional in the record forever, even after the thresholds are confirmed.

The accepted cost:

> A fresh install raises no actionable alarms until somebody signs off. That is the intended behaviour: it forces the question of who owns the numbers to be answered before the product is relied upon, rather than after an incident.

### 4. A notification system is defined by what it suppresses

ADR-017 is titled *"Notification value is in what it refuses to send"*:

> A system that floods people is worse than one that stays silent: a flooded channel gets muted, and then the message that mattered is missed too.

Four gates plus quiet hours — provisional status, severity floor, deduplication, rate ceiling. And notification fires **on escalation only**:

> Telling somebody an alarm became less severe, or cleared itself, is noise the dashboard already shows.

Two mechanisms worth stealing outright.

Every suppression is *recorded with its reason*:

> Suppression means somebody can ask "why was I not told". Every suppressed delivery is therefore stored with its reason rather than discarded, so that question always has an answer.

That question will be asked, possibly by a lawyer. A system that suppresses without an audit trail cannot answer it.

And escalation targets are `escalation_only`:

> Without that, escalating merely tells the same person twice.

Someone watched an escalation path fail in exactly that way.

---

## What would happen if these were removed

| Remove | Consequence |
|---|---|
| Hysteresis | Hundreds of raise/clear pairs from one boundary-resting value |
| Persistence | Pager at 03:00 from a single noisy sample |
| Debounce | Transition storm from a flapping input |
| Latching | Transient excursions invisible; incidents undetected |
| Persisted candidate state | Restarts silently reset every countdown |
| Null/bad guard | **A dead sensor reports as a healthy structure** |
| Confirmation gate | Unverified numbers page people; a listed building monitored at 7× the correct threshold |
| Suppression logging | "Why was I not told?" is unanswerable |
| `escalation_only` | Escalation notifies the same person twice |

---

## The embedded principles

**1. Human attention is the scarcest resource in the system.** Design for it as deliberately as you design for CPU.

**2. Every mechanism addresses one failure mode.** Do not collapse hysteresis, persistence, debounce and latching into "a filter".

**3. Absence of data is a distinct condition, never a value.**

**4. Time-spanning state must outlive the process.**

**5. Asymmetry is a feature.** Fast to raise, slow to clear.

**6. Numbers that trigger action require a named owner.**

**7. Log what you suppress.** Silence must be explainable.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Finite state machine with guards** | inactive → candidate → active → latched |
| **Schmitt trigger** | hysteresis band |
| **Debounce** | transition rate limiting |
| **Latch / sticky bit** | `latching` flag |
| **Persistent state machine** | candidate level + timestamp stored |
| **Rate limiting and deduplication** | notification gates |
| **Four-eyes / dual control** | threshold confirmation |
| **Audit of negative decisions** | suppression records |

---

## Where you will meet this again

### Automotive
- **DTC debouncing** in ISO 14229 / AUTOSAR DEM is this exact state machine: counter-based or time-based debounce, a *pending* DTC (candidate), a *confirmed* DTC (active), *test failed since last clear* (latched), plus aging counters over ignition cycles. The vocabulary differs; the machine is identical.
- **MIL illumination rules** deliberately require a fault to confirm over multiple drive cycles — persistence, standardised by regulation, because a warning lamp that flickers is a warning lamp that gets ignored.
- **Freeze frames** capture conditions at the moment of confirmation, so a transient is still investigable — latching, with evidence attached.

### Aerospace
- **Master Caution / Master Warning with inhibit logic**: alerts are suppressed during takeoff roll and other critical phases, because an alert at the wrong moment is worse than no alert. Suppression as a safety feature.
- **DO-365 / TCAS alert prioritisation** and the general principle of *alert de-cluttering* — the aviation industry's formal recognition that too many alerts kill people.
- **Nuisance-alert analysis** is a certification activity. Someone must demonstrate that the system does not cry wolf.

### Industrial / PLC
- **ISA-18.2 / IEC 62682 (Alarm Management)** is the entire discipline, standardised. It defines alarm rationalisation, prioritisation, deadband, on/off delays, shelving, and a maximum acceptable alarm rate per operator (roughly one every ten minutes; six per ten minutes is "unacceptable"). If you read one external document from this whole course, read a summary of ISA-18.2 — this repository's ADR-013 and ADR-017 are an independent rediscovery of large parts of it.
- **Alarm flood analysis after major incidents** — Texas City and Buncefield both feature operators facing unmanageable alarm rates.
- **Shelving and suppression with mandatory logging**, exactly as ADR-017 requires.

### Robotics
- **Behaviour-tree condition nodes with hysteresis** to stop a robot oscillating between states at a decision boundary.
- **Fault trees and recovery behaviours in Nav2** — graded response rather than a single stop.
- **Watchdog-driven safe stop with latch**: the robot stays stopped until a human acknowledges, because auto-resume after an unexplained fault is how people get hurt.

### Linux kernel
- **Thermal zones with trip points and hysteresis** — `_temp` and `_hyst` per trip, for exactly the boundary-chatter reason.
- **Interrupt storm detection** (`note_interrupt` disabling a screaming IRQ) — a debounce with a permanent latch.
- **`ratelimit` / `printk_ratelimited`**, which reports how many messages it suppressed rather than hiding them. Suppression with an audit trail, in the kernel.
- **EDAC** distinguishing correctable from uncorrectable errors, and counting the correctable ones for trend rather than alerting on each.

### RTOS firmware
- **Button debounce** — the first embedded lesson anyone learns, and structurally the same machine as an industrial alarm.
- **Sticky fault bits in peripheral status registers**, cleared only by explicit write. Hardware latching.
- **Comparator hysteresis** configured in silicon, and brown-out detectors with separate assert and de-assert thresholds — the Schmitt trigger where it originated.

---

## The connective tissue

Lessons 3, 4, 6 and 10 built a system that refuses to convert uncertainty into confidence. Lesson 11 adds the last and most human layer:

> **The system also refuses to convert confidence into noise.**

An appliance that is right about everything and says so a thousand times a day is useless, because the person it is talking to will stop listening — and then it will be right about the thing that mattered, and no one will hear it.

The full arc of this course, in one line: **be accurate, be humble about what you do not know, and be quiet unless it matters.**

---

## Design questions

**1.** Hysteresis, persistence, debounce and latching interact. Construct a configuration where two of them *fight* — where the combination produces behaviour neither would alone. Then propose validation that would catch it before a client's site does.

**2.** ADR-013 stores candidate level and timestamp so persistence survives a restart. But an appliance restarting every 30 seconds in a crash loop could accumulate persistence across restarts during which it was measuring nothing. Design the guard. What evidence proves the appliance was actually observing throughout the persistence window?

**3.** The confirmation gate means a fresh install raises no actionable alarms until somebody signs off. Now suppose a client commissions on a Friday, nobody signs off, and a genuine damaging event occurs on Saturday. Defend the design to that client. Then decide whether you would change it — and if so, what you would replace it with that is not simply "trust the defaults".

**4.** ADR-017 suppresses non-escalating changes as noise. Construct a scenario where an alarm *decreasing* in severity is genuinely urgent information. Does one exist? If it does, redesign the gate; if it does not, prove it — and note that "prove a negative about human needs" is itself an interesting architectural claim.


Q1 — Where persistence and hysteresis fight
The mechanism
targetLevel() applies hysteresis only to levels at or below the current one:

While the alarm sits at normal, every level is above current, so nothing is damped. A value crossing back and forth over critical_at alternates the target between warning and critical.

And persistence resets on every change of candidate:

So with an oscillation period shorter than persistence_seconds, the countdown never completes. The alarm stays normal for ever.

This is a deadlock, not a delay. Hysteresis would break the tie — but hysteresis only engages once you are in a level, and persistence is what prevents you getting there. Each mechanism is disabled by exactly the condition the other creates.

Why it's worse than it looks
The failure is inverted in severity. Mild steady vibration alarms; violent oscillating vibration does not. And oscillating around a threshold is what a real structural event looks like — resonance, an unbalanced machine running up, an aftershock sequence. Steady 4 mm/s is what a fridge compressor looks like.

Your live high_threshold definitions carry persist=3600 and thresholds 3/10 mm/s. This configuration is deployed.

Validation that would catch it before a site does
Not more unit tests of each mechanism — they all pass individually. Two things:

1. A property test over shipped definitions. For every enabled definition, feed a synthetic signal that never drops below the lowest threshold, in several shapes: steady, ramp, square wave at a range of periods, random walk. Assert one invariant:

If the value never returns below the lowest threshold, the alarm must not remain normal for longer than 2× persistence.

That's a claim about the state machine, not about a number, and every configuration must satisfy it. The square-wave-at-half-persistence case fails today.

2. A configuration lint. Some combinations are contradictory on their face:

Twelve of your definitions have this. Look at the code order — latching returns early on any downward transition, so clear_seconds is never read. An operator reading that row believes the alarm clears in 6 hours. It clears when acknowledged, never otherwise. That's not a bug in behaviour; it's a lie in the configuration, and a lint can catch it statically.

The general principle: unit-test each mechanism, but property-test their composition. Bugs in a state machine live in the interactions, and interactions are exactly what example-based tests miss.

Q2 — Persistence surviving a restart it didn't earn
The attack
candidate_since is persisted so a partially satisfied condition survives a restart. But a crash-looping appliance accumulates wall-clock time while measuring nothing. After an hour of 30-second crash loops, candidate_since is an hour old and persistence is "satisfied" on perhaps four samples.

Why uptime is the wrong evidence
The obvious guard — record process start time, reset the candidate if the process restarted — is insufficient, and this appliance has already proved why:

3,366 rows, zero readings, 37 minutes, every breaker open, three sensors reported healthy.

The process was up the whole time. Uptime proves the program was running, not that anything was observed. That's the same distinction as last_measurement_at versus last_good_measurement_at from round 7, and it cost this project a real incident.

The design
Persistence should be counted in observations, not seconds.

Store alongside candidate_since a count of good evaluations contributing to it. Then:

and honour the candidate only when both the elapsed time and the observation count are satisfied.

The evidence that proves observation is the measurements themselves: distinct minutes carrying a good reading on that channel between candidate_since and now, compared against the channel's measured rate — which ADR-007 already stores, so the expected count is known per channel rather than assumed.

On failure, don't discard the candidate — re-base it to the moment observation resumed. Discarding punishes a genuine condition for an unrelated restart; re-basing says "the clock starts when we could see again."

The wider rule:

Any state that survives a restart must be validated against evidence that also survives the restart. A timestamp is not evidence of anything but a clock.

Q3 — Friday commissioning, Saturday event
The defence, honestly given
The alarm fired. It was on the dashboard, at the correct level, with the correct value. What was withheld was the notification, because the threshold it was judged against had no name attached to it.

The reasoning is that a page from an unvalidated number is worse than no page — not for this event, but for every future one, because a channel that pages on numbers nobody stands behind gets muted, and then the page that mattered is missed too. The appliance had 27 such alarms this week.

And I don't think that defence survives contact with a damaged silo. It's an argument about long-run channel health delivered to somebody with an immediate loss. It's true, and it will sound like an excuse, because it is a reason and not an answer.

The real failure is upstream
Nobody signed off, and the appliance let commissioning complete anyway. It reported itself healthy, showed green, and served a dashboard, while being — by its own gate — incapable of telling anyone anything. That is the defect. The gate did its job; the installation process claimed completeness it hadn't earned.

What I'd change
Not "trust the defaults." Three changes, none of which do that:

1. An uncommissioned appliance must not present as commissioned. MonitoringCoverage already does this for tilt — 3 of 4, with the remedy. The same for thresholds: an appliance with unconfirmed limits shows it on every page, and the handover document cannot be generated. Refuse the claim of completeness, not the alarm.

2. Separate the observation from the judgement. The current design conflates "we don't trust this number" with "we will tell you nothing." Those are separable. A provisional alarm can notify with its status attached:

PROVISIONAL — SENSOR-002 vibration 47 mm/s. This threshold (10 mm/s) has not been confirmed by anyone, so we cannot say whether it matters. Somebody should look.

That asserts no authority it lacks, and it reaches a human. Reporting an observation is not the same as certifying a judgement, and only the second needed the signature.

3. Escalate the unsigned state to the installer, not the client. An appliance sitting unconfirmed for 48 hours should be chasing the person who installed it. That's the party who can fix it, and the current design tells nobody.

Q4 — Is a de-escalation ever urgent?
Yes. One exists, and this appliance has already produced it.

The scenario
A vibration alarm is at critical. The sensor's amplitude channel stops producing good readings — a cable works loose, an adapter is replugged, the breaker opens. Values stop arriving or arrive as bad.

The alarm de-escalates toward normal, and under ADR-017, nobody is told.

The appliance has just reported an improvement caused entirely by having stopped looking.

This is not hypothetical:

2026-08-11 — three sensors, 3,366 rows, zero readings, 37 minutes, every circuit breaker open, and the appliance reported three healthy sensors.

If a structural alarm had been active in that window, it would have silently fallen to normal, and the dashboard ADR-017 relies on would have shown green.

Why ADR-017's reasoning doesn't cover it
The ADR says de-escalation is "noise the dashboard already shows." That's a claim about channel, not about value — and it holds only if the dashboard is trustworthy at that moment. In this case the dashboard is showing the same false improvement, from the same cause. Both channels fail together, because they share the failure.

The redesign
Suppress a de-escalation only when the evidence for it is at least as strong as the evidence that raised the alarm.

Phrased as a rule:

"It got better" and "we stopped looking" are different events with the same shape. Only the first is noise.

The appliance already has every input needed — last_good_measurement_at from round 7, breaker state in the engine metrics, quality on every row. What it lacks is the question.

On proving a negative about human needs
You're right that it's an interesting claim, and it's worth naming precisely: ADR-017 does not claim de-escalation is never information. It claims it is never information that needs to interrupt someone.

That's a much narrower and more defensible claim — and it is still falsifiable, which is what makes it a real engineering statement rather than a preference. The counter-example above falsifies it, and it does so without appealing to anybody's feelings: it identifies a case where the de-escalation carries information the dashboard provably cannot, because the dashboard's source has failed in the same way.

The safe form of "nobody needs to be told X" is "nobody needs to be told X, given Y is also true." Name the Y, and the claim becomes testable. ADR-017's unstated Y was "the dashboard is showing the truth" — and once written down, the exception is obvious.


---

*Next: Lesson 12 — Three Questions and the Evidence Trail.*
