# Register Maps and Verification

Register maps live in versioned YAML under `profiles/`, never in code. This
document records **how a map is verified** and **what has been verified so far**.

## Verification status

| Status | Meaning | May drive alarms |
|---|---|---|
| `unverified` | Hypothesis. Not confirmed against hardware or a manufacturer table. | No |
| `candidate` | Matches a published manufacturer table; not yet confirmed on the connected unit. | No |
| `verified` | Confirmed on hardware, transcript recorded below, signed off. | Yes |

Enforced by `SensorProfile.is_trustworthy()` and asserted in the test suite.

## Current state

| Model | Profile | Version | Status |
|---|---|---|---|
| HWT901B-485 | `profiles/hwt901b-485.v1.yaml` | 1.0.0 | `candidate` |
| WTVB01-485 | `profiles/wtvb01-485.v1.yaml` | 0.1.0 | `unverified` |

Neither sensor can drive an alarm today. That is intentional and is the gate the
commissioning step exists to clear.

## Why this gate exists

A wrong register map does not throw. It returns numbers, and the numbers look
reasonable. An off-by-one address turns pitch into yaw; a wrong word order turns
a small positive into a large negative; a wrong scale factor turns 0.3 mm/s into
30 mm/s. Every one of those failures reaches the operator as a confident reading
on a dashboard. Guessing silently is the single most damaging thing this codebase
could do, so the schema makes the uncertainty explicit and the engine refuses to
act on it.

## Procedure

Prerequisites: the acquisition account is in `dialout`, the bus is terminated
with 120 ohm at both ends, and only the sensor under test is connected.

1. **Find the device.** Read-only, bounded, and safe to repeat:

   ```
   .venv/bin/python acquisition/tools/probe.py --port /dev/ttyUSB0 \
       --scan-baud --scan-slave 1-32
   ```

   `probe.py` cannot write. It emits function code 0x03 only, so it can never
   save configuration, change address or baud, or start a calibration.

2. **Dump the candidate block.**

   ```
   .venv/bin/python acquisition/tools/probe.py --port /dev/ttyUSB0 \
       --baud 9600 --slave 0x50 --start 0x30 --count 33
   ```

3. **Anchor on temperature.** It is the only channel whose correct value is known
   in advance. Find the register that reads near ambient under the `/100 (degC)`
   column. That fixes the block's base address.

4. **Confirm by stimulus**, one channel at a time:
   - HWT901B-485: rotate the unit 90 degrees and confirm the expected attitude
     channel tracks; hold it still and confirm the vertical acceleration axis
     reads about 1 g while the other two read near zero; confirm angular
     velocity is non-zero only while moving.
   - WTVB01-485: excite the mount and confirm velocity, displacement, and
     dominant frequency respond together and settle to near zero at rest.

5. **Check plausibility bounds.** Every decoded value must sit inside the
   profile's declared range across the full stimulus. A value outside the range
   means the map, word order, or slave identity is wrong.

6. **Record the transcript** in the appendix below, with date, firmware, unit
   serial, and operator.

7. **Promote** `verification_status` to `verified`, bump `profile_version`, and
   add a decode fixture to `acquisition/tests/` pinning at least one known raw
   value per channel.

## Rules

- Never write during discovery. No reset, unlock, calibration, save, baud change,
  address change, or factory reset. Those are `protected_commands` requiring
  step-up authentication and an explicit operator action.
- Firmware variants differ. A verified map is verified for a stated firmware
  version, not a model name.
- Do not expose a channel because a datasheet mentions it. WTVB01-485
  acceleration and HWT901B-485 pressure/altitude stay absent until the connected
  unit is confirmed to populate them.

## Appendix: verification transcripts

### 2026-07-31 — device at slave 0x50, model NOT YET CONFIRMED

```
Adapter:      CH340 1a86:7523, USB topology 1-1, alias /dev/quakevault-rs485-a
Bus scan:     addresses 1-247 at 9600 baud -> exactly one responder, 0x50
Link:         9600 8N1, 40/40 reads succeeded, 0 failures
Sample rate:  5.77 Hz effective, 1.06 ms jitter (33 registers, 80 ms pacing)
Operator:     unattended probe; no stimulus applied (sensor at rest)
```

Register behaviour over 40 samples (~7 s):

| Block | Addresses | Behaviour | Reading |
|---|---|---|---|
| Device time | 0x30-0x33 | 0x30/0x31 static, 0x32 stepping, 0x33 cycling 6-996 | YYMM / DDHH / MMSS / milliseconds |
| Acceleration | 0x34-0x36 | near-static: 424, 10, 1988 | 0.207, 0.005, 0.971 g — **vector magnitude 0.993 g** |
| Angular velocity | 0x37-0x39 | live, 10-15, span 3 | 0.6-0.9 deg/s — bias and noise at rest |
| — | 0x3A-0x3F | **always zero** | unpopulated, or at rest |
| Temperature | 0x40 | live, 2411-2430 | 24.11-24.30 degC |
| — | 0x41-0x46 | **always zero** | unpopulated, or at rest |
| Unidentified | 0x47-0x4F | mixed static and live; 0x48 swings +/-30000 | not interpreted |

**Confirmed by this transcript** (independent of which model is attached):

1. The device follows the WitMotion family base layout: a four-register time
   block at 0x30, acceleration at 0x34-0x36, angular velocity at 0x37-0x39,
   temperature at 0x40.
2. Acceleration scaling `raw/32768*16` g is correct. The three axes at rest sum
   to a vector magnitude of **0.993 g** — that is gravity, and no wrong scale
   factor produces 1.0 by accident.
3. Temperature scaling `raw/100` degC is correct: 24.2 degC against a room that
   is plausibly that temperature.
4. Milliseconds at 0x33 cycling 6-996 confirms the time block and its position.

**Not yet confirmed:**

- **Which sensor this is.** The evidence is compatible with two readings, and
  the difference matters (see below).
- What occupies 0x3A-0x3F and 0x41-0x46. Zero over a 7 s window at rest is
  exactly what both an unpopulated channel and an idle vibration channel look
  like. Only stimulus separates them.
- The identity of 0x47-0x4F.

**The ambiguity.** All-zero magnetometer (0x3A-0x3C) and attitude (0x3D-0x3F)
argue *against* this being an HWT901B: a 9-axis AHRS sitting on a desk should
report a magnetic field, and with 0.207 g on X it is tilted about 12 degrees, so
roll and pitch should be clearly non-zero. That points to the WTVB01-485, whose
vibration outputs would legitimately read zero at rest.

If so, the shipped WTVB01 profile is **wrong**: it placed vibration velocity at
0x34-0x36 and temperature at 0x3A, whereas this device has acceleration at
0x34-0x36 and temperature at 0x40. The vibration outputs must live in one of the
zero blocks. This is precisely the failure the verification gate exists to catch
— the old profile would have reported acceleration as velocity in mm/s.

**Next step:** confirm the model from the case label, then apply stimulus and
re-run `observe.py`. See "Pending experiments" below.

### 2026-07-31 — WTVB01-485 stimulus tests (model confirmed by operator)

Two captures with physical excitation (operator tapping the mounting surface),
full register space 0x00-0x7C, 455 samples over 130 s at 3.5 Hz, zero failed
reads.

**Established beyond reasonable doubt:**

| Registers | Assignment | Evidence |
|---|---|---|
| 0x30-0x33 | Device time (YYMM/DDHH/MMSS/ms) | 0x33 cycles 6-996; 0x32 second-byte steps once per second |
| 0x34-0x36 | Acceleration X/Y/Z, `raw/32768*16` g | Three axes at rest sum to 0.993 g |
| 0x37-0x39 | Angular velocity X/Y/Z | Near zero at rest, responds strongly to excitation |
| 0x40 | Temperature, `raw/100` degC | 24.2 degC, stable across both captures |
| 0x3B-0x3F, 0x42-0x43 | **Unpopulated** | Exactly zero through the hardest excitation |

**Vibration outputs exist and are zero at rest.** Registers that were exactly
zero during quiet windows and carried data under excitation: 0x3A, 0x41,
0x44, 0x45, 0x46, and sporadically several above 0x50.

The strongest candidate triple is **0x44-0x46**: three consecutive registers,
non-zero in 160/156/161 of 455 samples, matching the excitation windows almost
exactly. Peaks 619 / 888 / 1258.

**What probing could NOT establish:**

1. Which quantity each register carries. The device documents 3-axis velocity,
   3-axis displacement and 3-axis frequency. Peaks of 619-1258 are equally
   consistent with 6.2-12.6 mm/s at 0.01 mm/s per count, or 619-1258 um at
   1 um per count. Tapping cannot separate them, because a harder tap raises
   all three at once.
2. The scale factors. Documented ranges are velocity 0-50 mm/s, displacement
   0-30000 um, frequency 1-100 Hz, but the counts-per-unit is not derivable
   from an uncalibrated stimulus.
3. Whether registers above 0x50 are real channels or artefacts. Correlations
   there run 0.995-1.000, but that is confounded: every vibration register is
   zero-when-quiet and positive-when-tapped, so they correlate regardless of
   what they carry. Several are non-zero in only 5-52 of 455 samples, which
   looks more like sparse noise than a channel.

**Undocumented registers.** The manufacturer's parameter list for this model is
"3-axis vibration velocity, 3-axis vibration displacement, 3-axis vibration
frequency, chip temperature, on-chip time" - it does not mention acceleration or
angular velocity. Yet both are unmistakably live at the WitMotion family
addresses. They work, but they are undocumented for this model and a firmware
revision could move or remove them. The profile may expose them only as
`diagnostic`, never as a basis for alarms.

**Conclusion: empirical probing is exhausted.** Everything remaining requires the
manufacturer's Modbus register table. Guessing the velocity/displacement/
frequency assignment from tap data would produce a profile that looks right and
alarms wrongly, which is precisely what ADR-005 exists to prevent. Both profiles
remain unverified.

### Pending experiments

| # | Stimulus | Distinguishes |
|---|---|---|
| 1 | Read the label on the connected unit | Settles the model question outright |
| 2 | Tilt the sensor ~90 degrees, hold, re-run `observe.py` | HWT901B: 0x3D-0x3F come alive. WTVB01: they stay zero while 0x34-0x36 redistribute |
| 3 | Vibrate the mount (tap it, or run a small motor against it) | WTVB01: one zero block comes alive with velocity/displacement/frequency |
| 4 | Connect the second sensor at a different slave address | Confirms multi-drop and gives a side-by-side layout diff |

```
Date:
Model / firmware:
Unit serial:
Adapter (chip, port, topology):
Baud / slave:
Probe transcript:
Stimulus results:
Operator:
```
