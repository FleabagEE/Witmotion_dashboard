# Lesson 10 — The Refusal to Compute

**Modules:** `dsp.py`, `throughput.py` (`spectral_verdict`)
**Related decisions:** ADR-006, ADR-007, ADR-014

> The second of the three lessons where the real architecture lives.

---

## Why it exists

Every module so far solves a problem by doing something. This one solves a problem by **declining**.

That is a harder discipline than it sounds, because the code to produce the output already exists, it runs, and the result looks professional. `dsp.py` contains a complete acceleration-to-displacement chain. Point it at the wrong channel and it will hand you a beautiful curve.

> Nothing in this module should ever be pointed at those channels: integrating a quasi-DC register produces a smooth, plausible, entirely fictional curve, which on a structural monitoring appliance is the most dangerous output available.

**The most dangerous output available.** Not an error. Not a crash. A smooth, plausible, entirely fictional curve — one that a structural engineer would look at and believe.

---

## What problem it solves

### 1. An algorithm's preconditions are part of the algorithm

```python
This is the chain a seismograph runs, and it exists here ready for a sensor that
streams raw acceleration. **The WTVB01-485 does not.** Its acceleration register
is filtered inside the device - measured at 2% sample-to-sample variation and 499
distinct values across three hours - and its velocity and displacement outputs are
unsigned severity magnitudes computed on-device, not signals.

`refuse_if_not_a_waveform` exists to enforce that.
```

Study how that claim is supported. Not "the datasheet says it is filtered". Measured, on the actual hardware: **2% sample-to-sample variation, 499 distinct values across three hours.** A genuine acceleration waveform from a real accelerometer does not look like that. Somebody characterised the device rather than assuming it.

The register *is* acceleration. It is in the right units. It decodes correctly. Its quality is `GOOD`. And it is still not a waveform, because it is quasi-static — and double-integrating a quasi-static signal is a mathematically valid operation on physically meaningless input.

> **Type-correct is not the same as semantically valid.** A function that operates on "an array of floats" has preconditions that no type system will enforce, and those preconditions must be checked at runtime by something that knows the physics.

`refuse_if_not_a_waveform` is a **precondition guard on physics rather than on types**. That is a category of check most engineers never write.

### 2. Sampling theory does not survive contact with a polled bus

ADR-006 is the sharpest decision in the repository:

> **Decision.** Spectral features are capped at 0.4x the *measured* rate. Requests between 0.4x and Nyquist are refused with an explanation; requests above Nyquist are refused outright.
>
> **Context.** Polled Modbus is non-uniformly sampled. Nyquist assumes a uniform grid; jitter smears spectra and fabricates sidebands that resemble bearing faults.
>
> **Cost.** The product will appear less capable than competitors willing to plot a spectrum regardless. That is the intended trade.

Three things to take from this.

**Nyquist is a theorem with hypotheses.** *f*s/2 assumes uniform sampling. Polled Modbus is not uniform — the engine measures the jitter and reports it. Applying the theorem outside its hypotheses does not give you a slightly worse answer; it gives you a confident wrong one.

**The failure mode is specific and named.** Jitter "fabricates sidebands that resemble bearing faults". Not noise, not blur — *artefacts that look like the exact fault you are hunting for*. A tool that invents the signature of the thing you are looking for is worse than no tool.

**The cost is accepted in writing.** A competitor will plot that spectrum. This product will look weaker in a demo. The ADR says so and takes the trade anyway.

That is what architectural courage looks like on paper: not the decision, but the willingness to record the commercial cost of the decision where your own sales team will read it.

And note the graded refusal — between 0.4× and Nyquist, refused *with an explanation*; above Nyquist, refused outright. Two different wrongnesses get two different answers. The first is a judgement call the appliance explains; the second is not arguable.

### 3. Some errors cannot be fixed downstream

```
ANTI-ALIASING CANNOT BE APPLIED HERE
------------------------------------

Anti-aliasing must happen before sampling, in analog or at the acquisition rate.
By the time a sample reaches this module the aliasing, if any, is already in it
and no filter can undo it. `decimate` below anti-aliases before *reducing* a rate
we already have, which is a different and legitimate operation.
```

This is the most valuable paragraph in the module for a firmware architect, because it names an **irreversibility boundary**.

Aliasing is information destruction. A 60 Hz component sampled at 10 Hz does not become noise you can filter out — it *becomes* a 0 Hz component, indistinguishable from a real one. No amount of downstream cleverness recovers it.

Most software problems are reversible: bad data can be reprocessed, a bug can be fixed and history recomputed. **Signal acquisition has hard irreversibility boundaries, and knowing where they are is the difference between an architect and an implementer.** Everything before the boundary must be right the first time.

The paragraph then distinguishes the legitimate case — anti-aliasing before decimation — so the rule does not get cargo-culted into "never filter".

### 4. Operation order encodes physics

```
THE ORDER MATTERS AND IS NOT NEGOTIABLE
---------------------------------------

Integration turns any constant offset into a ramp, and any ramp into a parabola.
So the signal is high-passed *before* each integration, never only at the end:

    detrend -> taper -> highpass -> integrate -> highpass -> integrate -> highpass

Double integration also amplifies low frequency by 1/omega squared, which is why
the same cutoff is applied again after each step rather than relying on the first
one to have been enough.
```

A one-milligal DC offset — far below anything you would call an error — becomes, after double integration over sixty seconds, a displacement of about 1.8 metres. Your silo appears to have moved two metres. Every number in the chain is arithmetically correct.

This is why "detrend, then integrate twice, then filter at the end" is wrong even though it looks equivalent. **Filtering after integration cannot remove what integration has already amplified into the signal's dominant term.**

The repeated high-pass after *each* stage is the same insight applied twice, and the docstring says why: "rather than relying on the first one to have been enough".

### 5. Dependencies are a design choice, not a convenience

> Written in pure Python on purpose. The acquisition service runs on four dependencies and adding numpy and scipy to an appliance for a few hundred lines of well-understood arithmetic is a poor trade — especially when the arithmetic can be checked against closed-form answers instead of trusted.

Two arguments, and the second is the better one. Fewer dependencies matters on an appliance. But *"checked against closed-form answers instead of trusted"* is the real justification: you can test a hand-written trapezoidal integrator against a sine wave whose integral you know analytically. Testing that you called `scipy.integrate.cumtrapz` correctly is a much weaker claim.

**On a system whose output people rely on, verifiability can outrank convenience.**

---

## What would happen if it were removed

| Remove | Consequence |
|---|---|
| `refuse_if_not_a_waveform` | Smooth, plausible, fictional displacement curves presented to structural engineers. |
| 0.4× cap | Spectra with fabricated sidebands that mimic real fault signatures. |
| Pre-integration high-pass | A milligal of offset becomes metres of apparent displacement. |
| The anti-aliasing note | Someone eventually "fixes" aliasing with a filter and believes it worked. |
| Graded refusal | All refusals look like bugs; users route around them. |

Every one of these produces **confident, professional-looking, wrong output**. That is the through-line of this entire course.

---

## The embedded principles

**1. Know the preconditions of every algorithm you ship, and enforce them at runtime.**

**2. Theorems have hypotheses. Verify them against your actual system, not the idealised one.**

**3. Identify irreversibility boundaries and get everything before them right.**

**4. Refusing to produce output is a legitimate — sometimes the only correct — behaviour.**

**5. Explain refusals.** An unexplained refusal is a bug report; an explained one is a boundary the user can respect.

**6. Characterise your hardware. Do not trust its datasheet.** 2% variation and 499 distinct values is what characterisation looks like.

**7. Prefer verifiable arithmetic to convenient arithmetic** where the output carries consequence.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Guard clause / precondition assertion** | `refuse_if_not_a_waveform` |
| **Capability gating on measured conditions** | `spectral_verdict` against measured rate |
| **Graded response** | refuse-with-explanation vs refuse-outright |
| **Pipeline with mandatory ordering** | the detrend→taper→highpass→integrate chain |
| **Fail-closed** | uncertain input yields no output, not a guess |

---

## Where you will meet this again

### Automotive
- **Sensor plausibility and rationality checks** in engine and brake control: cross-check two sensors, and on disagreement enter a limp-home state rather than picking one. Refusing to act on unreliable input.
- **ISO 26262 safety mechanisms** with diagnostic coverage — the standard is largely about detecting when you cannot trust an input and defining what you do instead.
- **ABS/ESC inhibit conditions**: the controller declines to intervene when wheel-speed signals are implausible, because intervening on bad data is worse than not intervening.

### Aerospace
- **Air data validity flags and the ADIRU voting**: when sensors disagree, flag invalid rather than average. Averaging a good and a bad value produces a plausible number that is wrong — precisely this lesson. Several accidents trace to systems that used a value they should have rejected.
- **ARINC 429's "No Computed Data" SSM** — a first-class way for a transmitter to say *I could compute this but I do not vouch for it.*
- **Flight envelope protection degradation**: as sensor confidence drops, the system withdraws capability in defined stages rather than failing silently.

### Industrial / PLC
- **NAMUR NE 107 "Out of Specification"** — a distinct device state meaning "still working, but outside conditions where I can be trusted".
- **Analyser validity outputs** feeding control loops: a chromatograph declares its result invalid and the controller holds its last good value rather than acting on garbage.
- **ISO 10816 / ISO 20816** define measurement conditions, not just limits. A number outside the specified conditions is not a smaller-confidence result; it is out of scope. ADR-014's structural-vs-machine-condition distinction is the same boundary.

### Robotics
- **Covariance gating in Kalman filters**: reject a measurement whose innovation exceeds a chi-squared threshold. Formal refusal to fuse.
- **VIO/SLAM failure detection** — declaring tracking lost rather than emitting an increasingly wrong pose. A pose estimator that never says "lost" is far more dangerous than one that does.
- **`sensor_msgs` covariance of −1** meaning "unknown", so consumers can decline.

### Linux kernel
- **Drivers returning `-EINVAL` for unsupported sample rates** rather than approximating. ALSA will refuse a rate it cannot deliver exactly instead of resampling behind your back.
- **`clk_round_rate()`** — ask what you would actually get *before* committing, so the caller can decide. That is `spectral_verdict` in the clock framework.
- **IIO buffer watermark and timestamp validity**: the subsystem exposes whether timestamps are hardware or software, because the difference changes what you may compute.

### RTOS firmware
- **ADC acquisition-time and impedance constraints**: sample too fast for the source impedance and you get a wrong reading with no error flag. The datasheet precondition that everyone violates once.
- **The anti-aliasing filter on the board** is this lesson in hardware. If it is absent, no firmware can fix it — the irreversibility boundary sits in front of the ADC, and the architect's job is to know that before layout.
- **Watchdog-safe DSP**: knowing your FFT's worst-case execution time before enabling it in a control loop.

---

## The connective tissue

This lesson completes an argument that began in Lesson 3.

- Lesson 3: a value outside its declared range is `IMPLAUSIBLE`, not `GOOD`.
- Lesson 4: an unverified register map may not drive alarms.
- Lesson 6: one response is not a device.
- Lesson 10: a signal that is not a waveform gets no spectrum, and a rate that cannot support a frequency gets a refusal.

Four modules, one philosophy:

> **The appliance never converts uncertainty into confidence, and never produces output it cannot justify.**

That is the property that makes a monitoring product trustworthy. Not accuracy — *calibrated humility*. Accuracy is what you claim; humility is what makes the claim believable.

---

## Design questions

**1.** `refuse_if_not_a_waveform` must decide whether a series is a waveform. Design the actual test. What statistic separates a genuine acceleration waveform from a device-filtered quasi-DC register — and what happens on a genuinely still structure, where a real waveform is also nearly flat?

**2.** The 0.4× factor sits below Nyquist's 0.5 with margin for jitter. Derive a principled figure instead: given measured jitter σ and sample rate *f*s, what cap would you justify, and how would you validate it experimentally on this hardware?

**3.** A client insists on a spectrum above the cap for a report. Design the response. Consider: refusing outright, producing it with an indelible watermark, producing it into a separate non-evidential export, or a signed override. Argue for one, including what happens when that report is later attached to a legal claim.

**4.** ADR-006 accepts appearing less capable than competitors. Suppose you now compete for a contract you will lose on that basis. Write the technical argument you would put to the client — and then answer honestly whether there is a design that keeps the integrity *and* the capability, or whether this is a genuine, permanent trade.

---

*Next: Lesson 11 — The Alarm State Machine: the hard part is never the comparison.*
