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


## The acceleration registers do not carry a waveform

0x34-0x36 are documented as acceleration and decode correctly, but they are not
an instantaneous signal. Measured on the connected unit:

- 390 reads in 12 s at 32.5 Hz - **zero** value changes, one distinct value;
- 75,937 stored samples over three hours - only 3,093 changes, so the register
  content is static for 96% of reads;
- during real taps (velocity 4.4-5.2 mm/s, displacement 104-237 um) the
  acceleration span across the whole second was 0.0000-0.0015 g. A 237 um
  excursion near 50 Hz implies roughly 2 g, and none of it appeared.

The register tracks slow changes - tilt moves it reliably - and rejects
transients. The manual states that "advanced digital filtering technology is
adopted" and documents no sampling-rate or bandwidth register at all; only SAVE,
BAUD and the device address are configurable in section 6.4.

**Raising the baud rate cannot fix this.** The measurement above was taken at
32.5 Hz polling, three and a half times the appliance's normal rate, and the
value still never changed. The limit is the device's output, not the link.

What the device *does* give at full rate is computed internally from its own
256 Hz sampling: vibration velocity, displacement and dominant frequency all
respond to taps that acceleration misses entirely. Those are the channels to use
for vibration; acceleration is the channel for orientation.

To confirm this on any unit, or to test whether a configuration change has helped:

```bash
sudo systemctl stop quakevault-acq
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-probe \
    --start 0x34 --count 3 --update-rate 20
sudo systemctl start quakevault-acq
```

Tap the sensor throughout. A register carrying an instantaneous quantity changes
on nearly every read; this one reports STATIC.

**Unresolved.** The vendor Windows software exposes a sampling-rate control
(256 Hz, measurable 1-64 Hz) and a displacement range mode, neither of which
appears in the manual's register table. Those writes land in undocumented
registers - 0x2A is one of them, holding 50 and matching the vendor's "frequency
base 50". Whether one of them also governs the acceleration output is not known,
and it is not worth guessing at blind writes to find out.

## Kiosk mode

A wall display for a control room: four figures, large, no controls. Reached by
signing in as a `kiosk` user, which renders the display and nothing else - no
navigation, no sign-out, no route anywhere.

That is presentation, not the boundary. The boundary is the token: a kiosk
carries only `read`, so a screen anyone can walk up to cannot acknowledge an
alarm or change a setting even if somebody reaches the API behind it.
`KioskTest` proves it against a real alarm record, and proves an operator can
acknowledge the same one - otherwise the first test would pass for the wrong
reason.

**Staleness is stated, not implied.** An unattended screen showing a plausible
frozen number is worse than a blank one, so the display tracks the age of its
newest reading against a ticking clock and says "no data - last reading 26s ago"
in red, dimming the figures. Verified by stopping acquisition and watching it
change.

Install:

```bash
sudo useradd -r -m -d /home/quakevault-kiosk quakevault-kiosk
sudo install -m 0644 deploy/systemd/quakevault-kiosk.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now quakevault-kiosk
```

The launcher waits for the dashboard to answer before starting a browser, rather
than racing it - on a cold boot the browser usually wins against PHP and the
database, and a kiosk that opened on a connection error would sit there until
somebody noticed. systemd restarts it whenever it exits, rate-limited to 20
attempts in 300 s.

**Not addressed.** The unit assumes an X session on `:0` with a logged-in seat.
A headless appliance needs a display manager configured for autologin first; that
is site setup and is not scripted here.

## The displacement range mode is invisible to the appliance

The WTVB01-485 has two displacement range settings, chosen in the vendor Windows
software: **60000 um at 1 um resolution** and **600 um at 0.01 um**. Switching
between them changes what a count in 0x41-0x43 is worth by a factor of a hundred,
and nothing in the Modbus data announces it. Reading the configuration block
0x00-0x2F before and after a switch showed no register change, so the setting is
either written elsewhere or not read back at all.

An appliance still scaling counts as micrometres after a switch to the fine mode
would over-report displacement a hundredfold - and the readings would look
entirely plausible. Two orders of magnitude is the difference between cosmetic
and structural on any guideline table.

**Detected rather than trusted.** For sinusoidal motion `v = 2*pi*f*A`, so the
device's three vibration outputs are not independent, and a hundredfold error in
displacement shows up as a hundredfold error in the implied frequency - which the
device reports separately. That makes the mismatch visible without knowing which
register holds the mode:

```bash
php artisan measurements:check-units
```

Confirmed against live data on 2026-08-03: implied 1.6 Hz against a measured
1.5 Hz, ratio 0.84. The profile's scale of 1 um per count is correct for the
60000 um mode.

Run it after any change to the sensor's configuration, and after a sensor is
replaced. It needs some excitation in the window - tap the sensor first - and
says so rather than inventing a verdict when the structure is still.

**The mode may not survive a power cycle.** Changed to 600 um / 0.01 um in the
vendor software on 2026-08-03, then unplugged to move the sensor between
machines: the check afterwards read a ratio of 1.02, meaning the device had
reverted to 60000 um. The manual documents a separate SAVE register at 0x00
(section 6.4.1), and a parameter written without it applies to the running
device only. Anything set in the vendor tool must be saved, and confirmed with
this check after the sensor is next re-seated - the setting is not readable back
over Modbus, so there is no other way to know.

**Which mode to choose.** The fine mode resolves 0.01 um, a hundred times better,
and structural vibration displacement is usually microns to tens of microns.
But a hand tap on this bench produced excursions well past 600 um, which would
clip. Fine mode suits a mounted sensor on a real structure; coarse mode suits
bench testing where the sensor gets handled.
