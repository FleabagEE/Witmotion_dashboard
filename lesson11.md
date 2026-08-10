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

---

*Next: Lesson 12 — Three Questions and the Evidence Trail.*
