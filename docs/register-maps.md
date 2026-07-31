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

*(none yet - blocked on `dialout` group membership for the acquisition account)*

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
