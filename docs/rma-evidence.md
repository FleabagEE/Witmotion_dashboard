# RMA evidence — spare WTVB01-485, Z axis

**Corrected 2026-08-03.** An earlier version of this evidence was wrong in its
reasoning while right in its conclusion. Both are recorded below, because a
warranty claim should not rest on an argument the vendor can dismantle.

## The claim

On the spare unit, the **Z axis vibration velocity output does not respond to
vibration the same device's accelerometer measures.**

## Evidence

Capture `docs/captures/2026-07-31-mounted-tap.csv`, 1505 samples, single
session, sensor tapped by hand. Excitation is taken from the device's own
acceleration amplitude registers (0x37-0x39); response from its velocity
registers (0x3A-0x3C). Threshold 0.05 g, which is 102 raw counts.

| Axis | Amplitude reg | Samples above 0.05 g | Velocity reg | Reported velocity | Peak |
|---|---|---|---|---|---|
| X | 0x37 | 51 | 0x3A | **10** | 0.42 mm/s |
| Y | 0x38 | 12 | 0x3B | 0 | — |
| Z | 0x39 | **125** | 0x3C | **0** | — |

**Z was excited two and a half times as often as X and never reported velocity
once.** X, under weaker excitation in the same session, reported ten times.

**Y is not part of this claim.** It was excited only 12 times, which is too few
for its silence to mean anything. Claiming a fault there would be claiming
something the data does not support.

## Control

The second unit, same firmware (Version 10059), same capture procedure —
`docs/captures/2026-07-31-unit2-all-axes.csv`, 1509 samples:

| Axis | Excited | Reported velocity | Peak |
|---|---|---|---|
| X | 618 | 442 | 326.54 mm/s |
| Y | 608 | 402 | 325.66 mm/s |
| Z | 612 | 448 | 327.30 mm/s |

All three axes respond on that unit. The firmware and the register map are
sound; the fault is in this specific device.

## What the earlier version got wrong

It argued that Y and Z reporting a dominant frequency while velocity read zero
was self-contradictory — "a sensor cannot detect the frequency of a vibration it
measures as zero".

**That argument does not hold.** The device estimates dominant frequency from the
acceleration signal, and does so at excitation levels below the threshold at
which it reports velocity. The combination is normal behaviour and appears on the
healthy unit too.

It also claimed the Y axis was faulty. The data does not support that.

The conclusion survives on the comparative evidence above, which is stronger: the
same device, the same session, one axis excited harder than another and mute
while the other responded.

## Reproducing

```bash
cd backend && php artisan sensors:check-axes --minutes=30
```

Tap the sensor along each axis first. The check reports UNTESTED rather than
passing an axis it never excited.
