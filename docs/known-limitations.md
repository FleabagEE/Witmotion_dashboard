# Known Limitations

Written before implementation, deliberately. Every constraint here is a physical
or protocol limit, not a defect to be fixed later. The dashboard must reflect
these honestly; a condition-monitoring product that overstates its own data is
worse than no product.

## 1. Modbus RTU polling is not a data-acquisition path

The appliance reads registers over a request/response serial protocol. That
imposes two hard limits.

### Rate

Measured from `qv_acq.throughput` for the hardware in the field today (CH340
bridge, 5 ms device turnaround, 20% scheduler safety margin):

| Sensor | Baud | Sensors on bus | Transaction | Sustainable poll | Defensible band |
|---|---|---|---|---|---|
| WTVB01-485 (13 reg) | 9600 | 1 | 56.9 ms | 13.4 Hz | 5.4 Hz |
| WTVB01-485 | 9600 | 2 | 56.9 ms | 6.7 Hz | 2.7 Hz |
| WTVB01-485 | 115200 | 1 | 13.0 ms | 58.6 Hz | 23.5 Hz |
| WTVB01-485 | 115200 | 2 | 13.0 ms | 29.3 Hz | 11.7 Hz |
| WTVB01-485 (FTDI) | 230400 | 1 | ~8 ms | ~97 Hz | ~39 Hz |

Note the shape of the curve: 9600 to 115200 buys a 4.8x improvement, but
115200 to 230400 buys only 13%. Above roughly 57600 the bottleneck stops being
wire time and becomes **device turnaround plus USB round-trip latency**. Buying
baud rate past that point is wasted money; buying a better bridge chip is not.

### Uniformity

More limiting than the rate. Polled sampling jitters by milliseconds per
transaction, driven by kernel scheduling, USB frame timing, and device
turnaround variance. FFT, Welch PSD, and spectrograms all assume a uniform time
grid. Applied to jittered samples they smear peaks and manufacture sidebands
that look like bearing faults.

Consequences, enforced in code by `throughput.spectral_verdict()`:

- Spectral analysis is offered only up to **0.4 x the measured sample rate**, not
  the Nyquist 0.5, and the UI states the limit.
- Between 0.4x and Nyquist the request is refused with an explanation.
- Above Nyquist it is refused outright.
- Every spectrum carries the measured jitter of its source window. Windows
  exceeding the jitter budget are rejected, not silently plotted.

**Machine-vibration spectra are out of scope over Modbus polling.**
Bearing analysis needs hundreds of Hz of clean, uniformly sampled acceleration.
That requires a different acquisition path (dedicated DAQ or a sensor with
onboard FFT), and the appliance must not imply otherwise.

## 2. WTVB01-485 reports aggregates, not waveform

The device performs its own filtering and frequency estimation and exposes
reduced values: RMS vibration velocity, displacement, dominant frequency, angle,
temperature. There is no raw waveform to analyse.

Correct use: trend the aggregates, alarm on ISO-style velocity bands, track
dominant-frequency shift over days. Incorrect use: reconstructing a spectrum
from polled scalars.

The register map is **verified** against the manufacturer table and the connected
unit (see `register-maps.md`), so this profile may drive alarms.

What partly offsets this limitation: the device also computes 36 condition
indicators internally - RMS, kurtosis, crest factor, skewness, peak and pulse
factors per axis. Those come from the sensor's own high-rate sampling, so they
are not constrained by the polling ceiling above. Condition monitoring runs on
those; the polling limit only constrains what we can reconstruct ourselves.

## 3. Structural monitoring: what this hardware cannot currently claim

The deployment target is structural (ADR-014), governed by DIN 4150-3 and
BS 7385-2. Two hardware facts limit what may honestly be claimed against them.

**Per-unit axis verification.** Both standards evaluate the maximum of three
orthogonal component velocities. A healthy WTVB01-485 supplies all three - proven
on a second unit - but the first unit tested reported Y and Z velocity as exactly
zero in 1505 consecutive samples while its own frequency estimator showed energy
on those axes. That failure is silent: nothing about a dead axis looks like an
error, it looks like a still building. Commissioning must therefore confirm all
three axes respond to excitation before a sensor is trusted, and that check
belongs in the acceptance procedure, not in someone's memory.

**Aggregate velocity, not peak.** The standards are defined on peak particle
velocity. The WTVB01 reports an aggregated value whose relationship to peak is
not documented. Any comparison against a guideline value is approximate, and
transient events - blasting, piling, traffic - are exactly the case where the
aggregate underestimates the peak.

**Damage is not annoyance.** DIN 4150-3 values are cosmetic-damage thresholds.
Occupants notice vibration far below them, so a building can generate complaints
while every alarm reads green. Human comfort is BS 6472 / ISO 2631 and is not
implemented.

## 4. HWT901B-485 — retired

Removed from the product on 2026-07-31 (ADR-009). Any limitation previously
listed here no longer applies to what ships.

## 5. Derived translational velocity and displacement

Integrating acceleration accumulates error without bound. Where offered, the
feature is optional, off by default, labelled `derived`, and stored with full
provenance (source channel, filter parameters, integration method, window,
processing version). Derived values never share an axis or a colour with native
values, and never feed an alarm that is presented as a native measurement.

## 6. Non-uniform sampling also affects alarms

Windowed RMS, rate-of-change, and band-energy alarms weight samples by count,
not elapsed time. With jittered polling this biases results. The engine uses
time-weighted aggregation and records the effective sample count per window;
windows with excessive gaps are marked degraded rather than evaluated.

## 7. Single-adapter ownership

Exactly one acquisition process owns a serial port. Two processes on one RS-485
bus interleave frames and produce CRC errors that look like wiring faults. This
is enforced with an advisory lock on the device node.

## 8. Bus wiring is outside software control

CRC error rates are reported but cannot be fixed in software. Termination
(120 ohm at both ends), biasing, cable length, and grounding dominate RS-485
reliability. The troubleshooting guide leads with wiring, not configuration.

## 9. Hardware currently in the field

- Bridge chip is a **CH340** (`1a86:7523`). Adequate for commissioning; for a
  commercial appliance prefer a galvanically isolated FTDI FT232-based adapter.
  Isolation matters on industrial sites, and FTDI's latency timer is tunable to
  1 ms where the CH340's is not.
- CH340 devices expose no USB serial number, so `/dev/serial/by-id/` names
  collide when two are present. Stable identity therefore keys on physical USB
  topology via udev, not the by-id symlink alone.

## Condition indicators are unverified in the large-amplitude regime

The 36 per-axis condition indicators (0x47-0x6A) are decoded as signed 16-bit,
which is the schema default rather than a decision anyone made about them.

Some of them must be signed: skewness is negative for a left-tailed
distribution. Others cannot be: RMS, absolute average and variance have no
meaningful negative value. They are almost certainly a mix, and they are
currently all one type.

This is the same latent fault that was found in vibration velocity and
displacement on 2026-08-03 (ADR-021), and it is invisible for the same reason -
on a bench these registers sit near zero, nowhere near the 32767-count boundary
where signed and unsigned diverge. A stationary test cannot distinguish them.

Resolving it needs either a manufacturer statement of the type per register, or
a controlled excitation large enough to drive each indicator past 32767 counts
while the raw words are captured. Until then, treat any condition indicator
reading above roughly 32 units (raw 32000, scale 0.001) as unverified.

Nothing alarms on these channels, so the exposure is analytical rather than
operational.
