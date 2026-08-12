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

## Tilt lags the sensor by about nine seconds

Inclination is derived from the acceleration registers, and those are filtered
inside the device. Measured on 2026-08-03 across four movements:

- **settling time 9.0 s median, 10.0 s worst**
- **drift after the sensor physically stopped: 4.33 deg median, 5.78 deg worst**

The measurement is only possible because acceleration amplitude (0x37-0x39) and
tilt arrive in the same Modbus transaction and share a timestamp. Amplitude
responds immediately, so it says when the motion actually ended; tilt says how
long it took to catch up. Without the fast channel there is no way to separate
"the operator moved it slowly" from "the sensor is lagging" - the reading looks
like it is tracking either way.

```bash
php artisan measurements:check-tilt-response --minutes=30
```

**It is not the appliance.** The live path measures about 25 ms end to end,
websocket included. Nine seconds of it belongs to the device.

**It cannot be corrected in software.** The filter is not documented - the manual
describes no bandwidth or filter register, only that "advanced digital filtering
technology is adopted" - and undoing an unspecified filter would be guesswork
presented as a reading.

**It rarely matters for the purpose.** Inclination here answers "has the mounting
moved", and a wall shifts over days. Nine seconds is invisible against that. The
lag only appears in a hand test, which is exactly where it is most alarming and
least important.

**Worth trying if it does matter:** the vendor software exposes a sampling-rate
control (32K down to 64 Hz, each labelled with a measurable frequency band).
Whether it governs the acceleration filter is unknown. Change it, save it - the
setting does not persist without SAVE - and repeat the measurement above.

## Signed waveforms need a different sensor

The dashboard shows velocity and displacement as non-negative because that is
what the device outputs. It is not a display choice.

- **Displacement and velocity are unsigned severity magnitudes.** The manual's
  formula is `DX(um) = ((DXH << 8) | DXL)` with no sign extension, and across the
  entire recorded history neither channel has produced a single negative value in
  any orientation. The device measures how far, not which way.
- **Acceleration is signed** (`int16`, +/-16 g) but is filtered inside the
  device: 99 194 samples over three hours changed on 2.0% of adjacent reads and
  took only 499 distinct values. There is no waveform in it to integrate.

**Anti-aliasing cannot be applied after the fact.** It has to happen before
sampling. Whatever the device did internally at 256 Hz is already baked into the
number we read at 9 Hz; no filter reaches back before the ADC.

**The processing chain exists and is tested** - `acquisition/src/qv_acq/dsp.py`.
Detrend, taper, high-pass, integrate, high-pass, integrate, high-pass, producing
acceleration, velocity and displacement all oscillating about zero. Verified
against closed-form answers rather than against itself: 1 g at 10 Hz gives
156.1 mm/s and 2484 um, which is what `A/(2*pi*f)` and `A/(2*pi*f)^2` require.

It refuses to run on this sensor's data. `refuse_if_not_a_waveform` rejects a
series where fewer than 5% of adjacent samples differ, and the WTVB01-485
acceleration register measures 2%. Integrating a quasi-static register produces a
smooth, plausible, entirely fabricated curve - the most dangerous output a
structural monitoring appliance could show.

**What a signed waveform requires:** a sensor that streams raw acceleration time
series - a MEMS accelerometer with a raw output at 100-1000 Hz, or a
strong-motion accelerograph. Modbus RTU request/response is also the wrong
transport; even at 230400 baud a 3-register read caps near 60-70 Hz, giving about
30 Hz usable. The day such a sensor is connected, `dsp.process()` works
immediately.

## Long-term tilt monitoring: thermal drift dominates

The application is two sensors on a concrete silo watching for settlement. The
quantity of interest is a slow change in tilt over months. The obstacle is not
resolution - it is that a MEMS accelerometer's zero point moves with
temperature, and an outdoor structure cycles roughly 20 degC between night and
afternoon.

Measured on the bench unit over a quiet indoor period: tilt correlated with chip
temperature at **r = +0.755, slope +0.0457 deg/degC**. Extrapolated to a 20 degC
swing that is **0.9 degrees of apparent tilt appearing and disappearing every
day** - larger than the settlement anybody would be looking for. The silo would
seem to lean each afternoon and recover each night.

That figure came from 10 samples across 0.94 degC and must not be trusted as a
number. Its order of magnitude is the point.

**The design consequence.** Tilt is never reported against zero. It is reported
against a baseline captured at commissioning, with a temperature model fitted
over a learning window, and what is alarmed is the residual - the movement
temperature does not explain.

```bash
php artisan tilt:baseline thermal     # fit and inspect the model
php artisan tilt:baseline capture     # record the commissioning reference
php artisan tilt:baseline             # current deviation from it
```

**Two guards, both earned.** The model refuses itself when the temperature range
is under 2 degC, because a slope fitted indoors cannot be extrapolated to
February. And it refuses when tilt moved more than 1 degree over the window,
because that is a re-orientation rather than drift - fitted over a bench day in
which the sensor was picked up and set down, the model produced a slope of
-1.22 deg/degC, implying 24 degrees across a normal day, and reported itself
usable. It was fitting re-orientations against the weather.

**Resolution.** One LSB of the +/-16 g 16-bit accelerometer is about 0.028
degrees at small angles. Averaging improves that as 1/sqrt(N), so an hour of
samples reaches roughly 0.0004 degrees - which is why a settlement monitor
integrates over minutes instead of reacting in milliseconds. The sensor's
nine-second internal filter, a liability for vibration work, costs nothing here.

**What this cannot separate.** A concrete silo genuinely leans slightly when one
side is in the sun. That is real movement of the structure, not instrument
error, and no amount of compensation distinguishes "the silo is warm" from "the
silo is failing" without the seasonal record. The thermal model is therefore
reported rather than silently applied.

## The WTVB01-485 reports acceleration as unsigned magnitude

**Confirmed 2026-08-04 by physical experiment. This is the most consequential
limitation in the project and it invalidates work built on top of it.**

Registers 0x34–0x36 return the *absolute value* of each acceleration component.
The sign is not transmitted and cannot be recovered.

### Evidence

The sensor was rotated through 180° — aluminium mounting face up, then flat
against the table — and read from the database with the service running
normally:

| Orientation | accel_x | accel_y | accel_z |
|---|---|---|---|
| Mounting face up | 0.0146 | 0.0083 | **+0.9624** |
| Mounting face down | 0.0139 | 0.0130 | **+0.9629** |

A signed device goes from +0.96 to −0.96. This one moved by 0.0005 g.

**The decisive test.** The flip above depends on the unit being placed as
described, so it was repeated in a form that cannot be misplaced: tilt one edge
up, then the opposite edge, about the same axis.

| Position | accel_x | Angle |
|---|---|---|
| One edge raised | **+0.5152** | 32.4° |
| Opposite edge raised | **+0.4307** | 26.6° |

A signed device returns −0.43 for the second. Both are positive. Lifting the
left edge and lifting the right edge are indistinguishable, which is
direction-blindness demonstrated rather than inferred. (The magnitudes differ
only because the two angles were not matched by hand.)

Supporting evidence:

- **Zero negative values in 7,501,843 acceleration rows** spanning three days.
  This was originally cited as strong proof and is not: registers 0x34–0x36 are
  quasi-static and barely respond to vibration — during the 469 mm/s shake,
  accel_x moved 0.001 g, from 0.0967 to 0.0977 — so a mostly-upright sensor
  would read positive on every axis whether or not it can represent negatives.
  It corroborates; it does not establish.
- **`incl_pitch` is negative in 100% of its 854,458 rows**, because it is
  computed from `-ax` and `ax` is never negative. The sign was never measured;
  the formula manufactured it.
- **The decoder is not at fault.** Register 0x60 decodes to −1.2320 through the
  same `int16` path, so negatives do arrive when the device sends them.

No signed acceleration or angle register exists anywhere in 0x30–0x7F. 0x3D–0x3F,
where WitMotion's WT-series carries roll/pitch/yaw, read zero on this model.

### What still works

**Total tilt from the mounting axis.** With Y along the silo axis, the angle
between gravity and Y is `arccos(|ay| / |a|)`, which is unambiguous over
0°–90° because `|ay|` is large and never folds through zero. A settlement
alarm on *how far it has leaned* is sound.

**Gain calibration.** Two opposed positions give `|gain + offset|` and
`|gain − offset|`, which separates them: gain 0.96265, offset −0.00025 g. The
3.7% error is almost pure gain, and a uniform gain cancels under normalisation,
so it does not affect an angle — only a magnitude.

### What does not work

**Direction of lean.** Only the octant-ambiguous gravity vector is available.
For a wall-mounted sensor `|ax|` and `|az|` both sit near zero, which is exactly
where an unsigned value folds: a lean of +0.1° and one of −0.1° are identical,
and a slow oscillation reads as a constant offset.

**Six-position calibration as designed.** `z-up` and `z-down` return the same
vector, so the sphere fit has no orientation information to solve.

**`incl_roll` and `incl_pitch`.** Their signs are artefacts of the formula.

**The direction decomposition added in efaac1f**, and the gravity-vector
deviation in 8500a34, both assume signed components. The morning was spent
replacing `incl_tilt` because it could not see a transverse lean; the
replacement cannot see one either, for a different reason.

### Consequence for the silo

Two sensors reporting unsigned magnitude can tell you the structure has moved
and by how much. They cannot tell you which way, and they cannot separate a lean
toward the monitored face from one away from it. If direction matters — and for
distinguishing foundation settlement from shell bending across two heights, it
does — this device cannot supply it. The HWT901B-485 is an inclinometer with
dedicated signed angle registers; no profile for it exists in this project yet.

## The settlement alarm has never been able to fire (fixed 2026-08-04)

The one behaviour this appliance exists for could not happen, and had not been
able to happen from the day the alarm was provisioned.

`AlarmEvaluator::definitionsFor()` filtered candidate definitions on both
channel and quantity:

```php
if ($definition->channel_key !== null && $definition->channel_key !== $channelKey) continue;
if ($definition->quantity !== null && $definition->quantity !== $channelQuantity) continue;
```

`tilt_deviation` is synthetic — computed by `TiltCheck`, never decoded from a
register — so it has no row in `channels` and its quantity resolves to `null`.
The definition declares `quantity = 'inclination'`. `'inclination' !== null`, so
the second test rejected it on every evaluation.

Everything around it worked. The definition was enabled with the right
thresholds. `tilt:check` ran every five minutes. `deviation()` correctly
reported 4.7° of movement against a 3° critical threshold. The definition was
then silently filtered out before it could be judged.

Nothing caught it because no test covered the tilt path into the alarm engine.
The engine had tests; the deviation calculation had tests; the route between
them had none, and it was broken.

**Fix.** Quantity is a *broadening* filter — "every inclination channel on this
sensor" — and only applies to definitions not pinned to a specific channel. Once
a definition names its channel and that channel matched, quantity can only
reject. `TiltAlarmTest` now drives a silo past the threshold and asserts an
alarm appears; it was verified to fail against the old filter.

### Still true after the fix

The alarm raises and displays. It **notifies nobody**, for two reasons that are
both by design and both unresolved:

- **No notification channels are configured.** Zero rows in
  `notification_channels`, zero deliveries ever sent.
- **The thresholds are unconfirmed**, so every event is marked `provisional`,
  and `NotificationDispatcher` suppresses provisional alarms before any other
  gate. Nobody has put their name to 3°.

On a remote silo, an alarm that only reaches a dashboard is not an alarm.

## Nothing installed the scheduler (fixed 2026-08-04)

The appliance ran for days with no Laravel scheduler. There was no cron entry and
no systemd unit, so `tilt:check` — the only thing that compares a structure
against its baseline and raises an alarm — had never executed once. Four other
scheduled tasks had never run either.

The appliance polled its sensors, stored every reading, served a dashboard with
live charts and healthy sensors, and evaluated nothing.

It was found because somebody asked whether tilting the sensor by hand would
trigger an alarm. Both existing tests passed on the broken appliance:
`alarms:test-notification` proves SMTP, and `alarms:selftest` drives the
dispatcher directly. Neither goes near the scheduler, so neither could have
noticed.

**Fixed in three places, because one was how it happened.**

- `deploy/systemd/quakevault-scheduler.{service,timer}` tick every minute.
- `deploy/install-acquisition.sh` installs and enables them, so a new appliance
  cannot ship without one. It previously installed only acquisition and the
  forwarder.
- Acceptance case 13 now requires the timer to be enabled, and a new case 14
  asserts the heartbeat is under three minutes old. Enabled is not the same as
  running: a timer can be enabled while its service fails on every tick, and
  nothing downstream would say so.

A heartbeat is written each tick and the System page leads with a critical
banner when it goes stale.

### The shape of this failure

Three separate mechanisms on this appliance were complete, correct and never
invoked: the calibration subsystem, the bus capacity model, and the scheduler.
Each had a file format or an interface, working code behind it, and tests. None
of them was wired to anything, and in every case the dashboard looked healthy.

A test that exercises a component proves the component. Only a test that starts
at the physical world — tilting the sensor, pulling the cable — proves the
appliance.

---

## The dashboard did not survive a reboot (fixed 2026-08-05)

The PC lost power. It came back. The sensors came back with it — acquisition,
the forwarder and the scheduler are all systemd units, so they resumed on their
own and did not miss a reading.

The dashboard did not, because it was not a unit. It was a Vite development
server on 5173 and a `php artisan serve` on 8000, both started by hand in a
terminal weeks earlier and never written down anywhere. On an appliance whose
entire purpose is to be looked at, the half that gets looked at was the half
with no supervision.

Acceptance case 13, "appliance reboot", said **PASS**. It was not wrong. It
checked that four units were enabled, and all four were. The dashboard was not
in the list because the list was written from the set of units that existed,
and the missing unit is by definition not in that set.

**Fixed.**

- `deploy/systemd/quakevault-dashboard.service` — enabled, `Restart=always`,
  `PHP_CLI_SERVER_WORKERS=8` so one slow query cannot block the kiosk.
- The frontend now builds into `backend/public/` and Laravel serves it. One
  process, one port, no CORS surface. `deploy/build-dashboard.sh` builds it and
  runs the frontend tests first.
- `deploy/install-acquisition.sh` installs and enables the unit, and warns if no
  built dashboard is present.
- Case 13 is superseded by case 21, `acceptance/post-reboot.sh`, which asks
  from outside the appliance: does the dashboard answer, does its bundle load,
  are all three sensors delivering rows, has the scheduler ticked. It looks at
  what is enabled last and only as corroboration.

Two things fell out of the fix. The kiosk unit had pointed at
`http://127.0.0.1:8000/kiosk` for weeks; under the old split that port served
Laravel's welcome page, so the wall display had never worked. And the browser
tab said `frontend`.

### The shape of this failure, again

This is the fifth instance of one pattern on this appliance, and the first where
the missing piece was the product itself rather than a subsystem: calibration,
the bus capacity model, the scheduler, `publishMeasurements`, and now the
dashboard process. Every one was complete, tested code that nothing invoked.

The scheduler entry above ends: *only a test that starts at the physical world
proves the appliance*. This one narrows it. Case 13 did start at the physical
world — somebody really did reboot the machine. It still passed, because after
the reboot it asked the appliance about itself instead of asking what a person
standing in front of the screen would see.

Start at the physical world, and finish at the user.

---

## The outage the appliance survived, and the three faults it exposed (2026-08-06)

The client opened the dashboard in Firefox and got `RedisException: Connection
refused`. The appliance had rebooted the evening before and the three Docker
containers — TimescaleDB, Redis, Mosquitto — never came back. Sixteen hours.

**Nothing was lost.** That is the headline and it is worth stating first: the
spool held all 187,671 readings on disk, `undelivered_dropped` was 0 throughout,
and the appliance was roughly a day from the 500,000-row cap when it was caught.
The design worked. What failed was everything around it.

### 1. The data plane had no unit

`docker-compose.yml` carries `restart: unless-stopped` and the containers were
still down sixteen hours later. Across four reboots that evening the journal
does not settle why, and an appliance is not the place to establish it by
inference. `deploy/systemd/quakevault-stack.service` now states the requirement
instead of trusting a policy held inside the daemon's own database, and the
dashboard is ordered after it — `Wants`, not `Requires`, so a dashboard that can
explain the problem beats one that refuses to start.

### 2. The watchdog killed the recovery

When the database came back, the forwarder began replaying correctly at about
2,600 rows a second. systemd SIGABRTed it every two minutes for the entire
recovery.

`drain_once()` loops until the spool is empty and the caller pinged the watchdog
once per drain. With 84,000 records at a second per batch of 200, one honest
call takes seven minutes against a `WatchdogSec=120`. Progress survived, because
delivery commits per batch — but the service looked like a crash loop, and its
metrics stayed frozen at `delivered 0, backlog 187671` for the duration. The one
moment an operator most needs to watch recovery happen was the one moment the
appliance reported nothing at all.

The heartbeat and the metrics now fire per batch. **A spool that absorbs an
outage and then trips the watchdog while draining has moved the outage rather
than absorbed it.**

### 3. Two and a half hours of data was parked with no way to get it back

The retry ceiling exists so one poisonous record cannot block the queue behind
it. It cannot tell a poisonous record from a healthy one that met a long outage:
a database down for hours burns the retry budget of everything spooled during
it. 31,307 readings — 02:02 to 04:40 UTC, all three sensors — were parked for
good.

The appliance counted them in a Prometheus gauge and shipped nothing that could
act on the number. `qv-spool` is new: `status` answers "did we lose anything"
without opening SQLite by hand, and `retry-dead-letters` returns parked records
to the queue. Dry run by default, never automatic — an operator choosing to
retry is the difference between recovering an outage and hiding a real fault.
All 31,307 were recovered.

### The check that passed while this was true

`acceptance/post-reboot.sh` was written the day before precisely to catch this
class of failure, and it half worked: it correctly failed the dashboard and the
database. It had no idea the containers existed.

Worse, it could be satisfied by hand. "Enabled and active" stays true when
somebody starts a unit ten minutes after boot while debugging — which is exactly
what happened during this investigation, and for a while the script read 15/15
on a machine that had come up with three dead containers. It now compares each
unit's `ActiveEnterTimestamp` against the boot clock and fails anything that
started more than three minutes late, saying so in as many words: *started 1064s
after boot — by hand, not at boot.*

### The shape of this failure, a sixth time

Yesterday's entry ended: *start at the physical world, and finish at the user.*
That was right and incomplete. This check did finish at the user — it asked
whether the dashboard answered — and still passed a broken appliance, because it
never looked below the application at the things it stands on.

Check the floor as well as the roof. And a verification that a human can satisfy
by hand is not a verification.

---

## Nothing in the product could say "your data is safe" (fixed 2026-08-06)

A postscript to the outage above, and arguably the most useful thing to come out
of it.

For sixteen hours the appliance was in a state it had no vocabulary for. Every
sensor was healthy. The structure was still. Not one reading reached the
database. All three facts were true at once, and the product could express the
first two and not the third.

`SensorHealth` sees silence and says *"Silent for 900s. A dead sensor and a
still structure look identical on a chart."* True in general, wrong here, and
wrong in the expensive direction: it reads as a broken instrument and sends
somebody out to a silo to check a cable that is not broken.

The distinction that was missing:

```
readings not arriving — and safe on disk        be patient
readings not arriving — and being lost          act now
```

The appliance knew which one it was. The forwarder wrote the backlog, the dead
letter count and `undelivered_dropped` to a Prometheus file every second — mode
0600, owned by `quakevault-acq`, unreadable by the account serving the
dashboard. The one number that would have told an operator "nothing is lost, be
patient" was being written continuously to a file nothing else could open.

**Fixed.** `App\Services\DeliveryHealth` reads that file; `/api/v1/sensor-health`
returns it as `delivery`; `DeliveryBanner` shows it above the movement figures
when there is something to say and stays silent otherwise. Metrics are now
written 0644, which they always should have been — they are counts of readings,
with nothing in them to protect.

Three decisions worth recording:

**Delivery is not folded into the overall status.** A backlog is not a sick
sensor. Collapsing them would let a patient, correctly-working spool turn every
instrument on the page amber, and the page would then be lying about the
hardware.

**A stale metrics file is a failure, not a healthy backlog.** Past two minutes,
every number in it describes a forwarder that has stopped. Reporting `backlog
12, all fine` from a dead forwarder is worse than reporting nothing.

**The banner says the figures are stale.** That is the actual harm — not the
backlog itself but a movement reading from last night being read as current by
somebody deciding whether a silo is safe.

### The shape of this one

Not a component nothing invoked. A *question the product never asked*. The data
existed, the file was being written, every part worked — and no code path
anywhere turned it into a sentence a human could read.

Ask what the person standing in front of the screen needs to know, then check
whether anything in the system is capable of telling them.

---

## The live feed had been dead for days, silently (fixed 2026-08-10)

Reported as a UI bug: on the Live page's 2-second window the charts "disappear
and come out repeatedly". The flicker was real and was the last link in a chain
that started with a security fix.

**The chain, from the bottom.**

`backend/.env` was tightened from 664 to 600 earlier in the project — correct,
it holds the database password. But `quakevault-reverb` and
`quakevault-live-bridge` run as `www-data`, and 600 locked them out.

Reverb did not fail. It started, could not read `.env`, silently fell back to
framework defaults, and bound **0.0.0.0:8080** instead of the configured
**127.0.0.1:9080**. The frontend has the port inlined at build time from
`frontend/.env` and was connecting to 9080, where nothing was listening. It also
exited every sixty seconds and was restarted by systemd — `NRestarts` had
reached **312** — and nothing anywhere reported any of this.

The visible consequence was a badge reading `polling` instead of `websocket`,
which is easy to read as a preference rather than a fault.

**Why that produced a flicker.** A reading travels sensor → spool → forwarder →
API → database, and the forwarder drains on a one-second cycle. Stored readings
therefore land a second or two behind the sensor. A query for "the last two
seconds" of stored data returns one point, or none — measured live: 1 point at
2 s, 4 at 5 s, 59 at 60 s. At a 500 ms refetch the window slid past the newest
row twice a second, `hasData` went false, the card showed "no data in this
window", and the next tick drew the trace again.

**The 2-second window is shorter than the appliance's own delivery latency.** It
was never going to work on stored data. The websocket exists for exactly this
(ADR-020) and it was down.

**Fixed in four places.**

- `backend/.env` is now `640 quakelogic:www-data` — unreadable to everyone else,
  which was the point of tightening it, and readable by the two services that
  need it. Reverb now binds 9080 and has run without a restart since.
- `WaveformCard` holds the empty-window message back for 1.5 s. A momentary gap
  is not news; a window that stays empty still reports itself. Errors are still
  shown immediately — an error is not a gap.
- The Live page now explains, when a sub-5-second window is chosen and the
  socket is down, that the window needs the live feed. It previously drew a
  flickering empty chart, which reads as a broken dashboard rather than a
  missing feed.
- `acceptance/post-reboot.sh` checks that something is listening on the
  websocket port the frontend was *built* to use, and that `.env` has the exact
  ownership its services require.

**What was deliberately not done.** The last good data is not held on screen to
cover the gap. That would present a reading from before the gap as current, and
on this appliance a stale figure passed off as live is the failure mode taken
most seriously — the same reason `index.html` is served `no-store` and the
delivery banner says "every figure on this page is older than it looks".

### The shape of this failure

A security change with a correct rationale broke a subsystem two layers away,
and the subsystem's response to being broken was to keep running on defaults.
**Silent fallback to a default is how a configuration error becomes a mystery.**
Reverb should have refused to start rather than binding a port nobody asked for.

It also joins the running theme: the appliance had no opinion about whether its
own live feed was reachable. 312 restarts, several days, no alarm, no banner, no
line in any check — until a human noticed a chart flickering.

---

## The build script deleted the dashboard before it knew it could replace it (fixed 2026-08-10)

Found while fixing something else, which is the only reason it was found at all.

`deploy/build-dashboard.sh` cleared `backend/public/assets` and `index.html`,
then ran the build. A TypeScript error a second later meant the build never
produced a replacement, and the live appliance answered 503 to every visitor
until somebody noticed and rebuilt it.

The destructive step ran unconditionally. The step that justified it did not.

That ordering had a stated rationale — Vite writes content-hashed filenames, so
without a clear every upgrade leaves old bundles behind for ever. The rationale
was correct. The sequencing turned a compile error into an outage.

**Fixed by building to staging and swapping only on success.** A failed build
now costs nothing: the previous dashboard is still being served and was never at
risk. Verified by deliberately breaking the typecheck — the script exits 1, the
deployed `index.html` is byte-identical, the dashboard still answers 200, and
the staging directory is cleaned by an EXIT trap.

The swap itself is ordered so the page is never inconsistent:

1. new asset files are copied in alongside the old — content-hashed names, so
   nothing collides
2. `index.html` is replaced by a single rename, atomic within a filesystem
3. only then are the superseded assets removed

At every instant the `index.html` being served names bundles that exist on disk.
The obvious order — clear, then write — leaves a window where the page loads and
its scripts 404. Short, but real, and on a wall display somebody is looking at
it.

Two smaller things came with it. `npx tsc --noEmit` now runs before the build
rather than inside it, because that is the error that caused this. And the
script refuses to deploy if `index.html` names an asset that was not emitted —
cheap to check, and the last place a white screen can be caught before it is
serving.

### The shape of this failure

A destructive step placed before its own justification. The question to ask of
any deployment script: **if the next line fails, what is the state of the thing
users are looking at?** Here the answer was "gone", and nothing in the script
acknowledged that.

---

## Implausible readings were treated as measurements (fixed 2026-08-10)

Found while writing Lesson 3 of the architecture course, which asked what damage
an implausible value can do downstream. In this repo the answer was not
hypothetical.

The decoder does the right thing: a value outside the profile's declared range is
kept, forwarded, and labelled `implausible`. Keeping it is correct — the range
comes from a datasheet, ADR-005 treats datasheet metadata as suspect until
verified, and discarding at the edge would destroy the evidence needed to fix a
wrong register map.

**But keeping it creates an obligation on every reader, and most readers had not
met it.**

| Consumer | Before |
|---|---|
| `SensorHealth` | correct — counts non-good as rejected |
| `SpectrumController` | correct — filters, and reports what it rejected |
| `AlarmEvaluator` | rejected only `bad` |
| `TiltMonitor` (×2) | no filter |
| `TiltController` | no filter |
| `ReadController` (×2) | no filter — `avg/min/max` included implausible |

So an out-of-range value could raise a critical alarm and email somebody; it
polluted every chart bucket's mean; it corrupted `max_value`, which is worse,
because peak is exactly what a threshold assessment reads; and it entered the
settlement calculation, the appliance's primary claim.

This was live. The 26 standing alarms on this bench included displacement peaks
of 18,573 µm against a 500 µm limit, from a register whose range mode the
appliance cannot read. Those were not the silo moving 18 mm.

**Fixed.** `AlarmEvaluator` now refuses `implausible` as well as `bad`.
`TiltMonitor`, `TiltController` and both `ReadController` aggregates filter to
good readings. The chart buckets additionally report `n` and `ok` — samples, and
how many of them the appliance believes — because a bucket that is mostly
implausible still plots a point, and there was previously no way to tell it from
a confident one.

**The construction, not the fix.** `MeasurementQualityContractTest` enumerates
every file querying `measurements` and requires each to either filter on quality
or appear in an exemption list with a stated reason. Diagnostic and repair tools
are exempt — seeing rejected readings is their entire job. A second test fails if
an exemption goes stale, so the list cannot quietly become a licence. Verified to
bite: a probe file with a naive `avg(value)` query fails the suite, and passes
once removed.

**The rollup too, rebuilt the same day.** `measurements_hourly` counted
`degraded_samples` and then averaged every value anyway. A continuous aggregate
cannot be altered in place, so it was rebuilt from raw:
`2026_08_10_000001_rebuild_measurement_rollup_quality_filtered`.

Built alongside the original, materialised over the full history, sanity-checked
for bucket count and start time, and only then swapped — so a failure halfway
would have left the appliance serving the old rollup rather than nothing. The
refresh and retention policies belong to the old view's hypertable id and die
with it, so they are re-added explicitly and were verified scheduled afterwards.

What it changed, on the worst-affected buckets: `incl_tilt` on 2026-08-04 15:00
went from mean 1.338° / max 50.595° to mean 0.782° / max 21.178°;
`vib_frequency_y` from mean 6.59 Hz / max 404.1 Hz to 2.79 Hz / 299.5 Hz. 302 of
14,378 buckets contained degraded samples.

**The migration refuses to run once raw data no longer covers the rollup.** Raw
`measurements` drops after 365 days; the rollup keeps ten years, and outliving
the raw rows is the entire reason it exists. Re-materialising in 2028 would
silently replace nine years of aggregated history with one. The guard compares
the oldest raw hour against the oldest bucket and aborts with an explanation
rather than proceeding. On 2026-08-10 they were identical — `2026-07-31 19:00`
both — so the rebuild was lossless.

### The shape of this failure

Not a missing mechanism. The mechanism existed, was correct, and was labelled —
and then five of seven readers ignored the label.

**Data that carries its own caveat is only as good as the discipline of everyone
who reads it.** A caveat in a column is a convention; a caveat the query cannot
ignore is a construction. The Lesson 2 rule applies unchanged: move the check to
where violation is impossible, not to where it is merely documented.

---

## Two of three sensors had no offline alarm (fixed 2026-08-10)

Asked in passing: *if one of the sensors failed, how would the system know which
one?* Three layers answer that — per-sensor health checks, a liveness alarm, and
cross-sensor disagreement. Checking the second one found it was not there.

```
SENSOR-001 liveness definitions: 1
SENSOR-002 liveness definitions: 0
SENSOR-003 liveness definitions: 0
```

`provisionLivenessDefaults()` was written, correct, and covered by two tests. It
was called by **nothing outside the test suite**. SENSOR-001 had an offline alarm
because somebody once ran it by hand during development; the other two never got
one. `alarms:sweep` ran every minute, dutifully checked all three sensors, and
found a definition for one.

So on a live appliance, for two of three sensors: **if the sensor died, no alarm
was raised and nobody was emailed.** It would appear on the health page as
`fail`, and structural movement would go unavailable — both of which require
somebody to be looking.

The mid and ground sensors are the ones this mattered most for. Ground is the
reference: without it the appliance cannot separate a passing lorry from the silo
settling, and losing it silently degrades every structural claim the product
makes.

**Fixed in the sweep rather than in a command.** `alarms:sweep` now provisions a
missing liveness definition before evaluating, so a sensor registered at two in
the morning is covered within the minute and coverage stops being a step somebody
has to know about. It provisions **only when absent** — these thresholds are
self-confirming, derived from the appliance's own poll rates rather than from an
engineering judgement, but an operator may still have widened them, and
re-deriving every minute would revert that edit silently for ever. And it says so
when it acts:

```
provisioned a missing liveness alarm for 2 sensor(s): SENSOR-002, SENSOR-003
```

`LivenessCoverageTest` is the construction: every active sensor must end up
covered, retired sensors must not be, an operator edit must survive a sweep, and
the thresholds must come from the sensor's own configured rate so a channel
polled once a minute does not alarm between its own polls. Verified to bite —
disabling the self-heal fails four of the five.

### The shape of this failure, a seventh time

Complete, correct, tested code that production never invoked. The others were the
calibration subsystem, the bus capacity model, the scheduler,
`publishMeasurements`, the dashboard process, and the quality label that five of
seven readers ignored.

The variation here is worth naming: **the mechanism had been invoked once, by
hand, for one sensor.** That is more dangerous than never having run at all,
because the feature demonstrably worked — there was a liveness alarm on the
dashboard, and it fired correctly — which is exactly the evidence that stops
anyone asking whether it covers everything.

**One working instance is not coverage.** Count them.

---

## A dead horizontal axis is not detectable at rest (measured 2026-08-11)

Recorded because the obvious check would have been wrong, and only measurement
showed it.

Building a per-axis health check, the plan was a noise floor: a live MEMS part is
never perfectly still, so an axis with zero variance is a dead channel. Measured
over ten minutes before writing it:

```
SENSOR-001 z=+1.00055 (sd 0.000144)
SENSOR-002 z=+0.98730 (sd 0.000000)
SENSOR-003 z=+0.99268 (sd 0.000000)
```

Two of three healthy sensors report **exactly zero variance**. The quantisation
step is 0.000507 g and they are stiller than that, so each sits inside a single
code for ten minutes at a time. SENSOR-001 differs only because it happens to
straddle a code boundary and dithers between two adjacent values.

**A variance-collapse check would have alarmed on two of three good sensors on
the day it shipped.** It was not built.

The magnitude check does not cover the gap either. In a vertical mounting z
carries 0.99 g while x and y carry 0.002–0.027 g, so losing a horizontal axis
moves |a| by less than one quantisation code. It is invisible to any arithmetic
on these numbers.

### Correction, 2026-08-11: this was overstated, and it is now detectable

Both statements above are true and both are about the **static** channels. I
concluded from them that the fault was undetectable at rest, and did not check
the **amplitude** channels, which are a different measurement entirely — the AC
component, with a noise floor an order of magnitude above quantisation.

Measured over an hour, all nine of them:

```
zeros     0 of 3,599      every channel, every sensor
distinct  23 to 74        never fewer than 23 values in an hour
minimum   0.0049-0.0068 g  ten to fourteen codes above zero
```

**A live axis is never flat.** So an amplitude channel that is constant, or
zero, while its siblings vary is not measuring anything — whatever the cause.

Built as the `axis_response` health check. One axis flat with siblings varying
is a `fail` naming the axis. All three flat is *not* attributed to any axis: that
is the whole sensor, or an installation with no vibration at all, and this data
cannot separate them. Too few samples reports `unknown` rather than guessing.

The original conclusion was drawn from the two routes tried rather than from the
data as a whole. **"I could not find a way" is not "there is no way", and the
difference belongs in the wording.**

### The shape of this one

Not a fault. A **check not written**, because the reasoning behind it was
plausible and the measurement contradicted it.

The variance idea is correct for most accelerometers and wrong for this one, and
nothing short of looking at the data would have said so. It would have shipped
as a confident, well-tested, well-commented check that was false on two thirds of
the fleet.

**Measure the assumption before building on it** — particularly the assumptions
that sound like physics.

---

## The batch audit trail was mostly not recorded (fixed 2026-08-11)

Found by answering a design question about `batch_uid` — not by anything failing.

The forwarder identified each batch as `records[0].idempotency_key[:64]`: the
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

So every batch from one `(appliance, run, sensor, group)` shared a single id.
`ingest_batches` writes with `insertOrIgnore`, so only the first was ever
recorded: **551 audit rows for hundreds of thousands of delivered records.**

### What was and was not damaged

**The measurements were never at risk.** They deduplicate on their own,
untruncated key in `ingested_polls`, and that key was never shortened.

**The audit trail was.** `ingest_batches` records what was offered, accepted,
rejected and when — the table you would reach for to answer *"what did the
appliance send us on the night of the 4th"*. Most of it was silently dropped.

### The second defect, which truncation does not explain

Even at full length, the first record's key is the wrong identity. After
`retry-dead-letters` revives records, or the cap prunes some, a later batch can
legitimately begin with the same record and carry entirely different contents —
same id, different batch, and the surviving audit row describes the wrong one.

### Fixed

`batch_uid` is now `sha256` of the batch's sorted idempotency keys. Two batches
holding the same records are the same batch, which is exactly what an idempotent
retry is; two holding different records cannot collide. Sorted, so a re-ordering
of identical contents is still recognised as the same batch.

SHA-256 hex is 64 characters — the column width exactly. **Nothing is truncated,
and nothing can be.**

Historical rows are left as they are. They are a record of what happened, and the
old format is part of that.

Measured after deploying: **29 audit rows in two minutes**, against 551 in the
previous eleven days.

Six tests pin it, including one asserting that two batches whose keys are
identical for their first 64 characters produce different ids. Verified to bite:
restoring the old expression fails three of them.

### The shape of this one

Nothing failed. No alarm, no error, no missing measurement — the appliance
behaved correctly in every respect a test or a user would notice, while quietly
recording 0.3% of its own audit trail.

**A truncation is a silent lossy compression of an identifier.** Any time a value
is cut to fit a column, ask what varies in the part being cut. Here the answer
was: everything that mattered.

---

## The magnitude fingerprint did not work, proven by a real swap (2026-08-11)

The only entry here written from a physical experiment run without knowing the
answer in advance. It is also the only one where a shipped, tested feature was
removed rather than fixed.

### What was built

`SensorFingerprint::crossMatch()` identified a sensor by its resting magnitude.
Each unit reads slightly differently — 1.00090, 0.98759 and 0.99276 g — so a
swap should make two of them read like each other. It was tested, the tests were
mutation-checked, and it was recorded as the answer to the swap problem.

### What happened

Two adapters were physically exchanged between hub ports. The appliance:

- **detected nothing** — `looks_like: null` on both sensors
- **actively suppressed** the drift alarm that did fire, as *"shared
  (environmental)"*, because both sensors moved by a similar amount at the same
  instant

A real sensor swap produced no alarm, no email and no failing check.

### Why

The magnitudes moved — but away from each other, not toward:

| Slot | Should read (its new unit's baseline) | Actually read |
|---|---|---|
| SENSOR-001 | 0.98759 | 1.02659 |
| SENSOR-002 | 1.00090 | 0.96365 |

Each unit's magnitude shifted about **0.038 g from its own baseline** when
handled — a z-only offset with x and y unchanged, consistent with cable strain
flexing the package, which is a well-known MEMS effect.

```
difference between units      0.0133 g    <- the signal
shift caused by handling      0.0380 g    <- the noise
```

**You cannot identify a thing by a property that changes more when you touch it
than it differs between things.**

The tests passed because they fed clean synthetic magnitudes with no handling
term. They tested the arithmetic and not the physics.

### What did work

Chip temperature, unambiguously, in one five-minute bucket:

```
19:10   S1 26.22   S2 25.74
19:15   S1 25.76   S2 26.26     <- exchanged
```

The untouched third sensor continued its slow warming with no discontinuity.
Replaying the window through offset-from-group-mean logic identifies both
swapped slots and leaves the third alone.

### And why it is a bench check, not a monitor

Because it only works while the sensors share an environment. On a silo — one
in sun at the top, one in shade at the ground — ambient differences of ten or
twenty degrees bury a half-degree chip bias, and each unit converges to its new
position's temperature within the hour, erasing the evidence.

Shipping it as a continuous field check would be the same error again in new
clothes. It is `sensor:verify-wiring`, run deliberately on the bench before
mounting, and it refuses when the biases are closer than 0.25 C.

### The suppression was the worse bug

Missing a detection is a gap. **Silencing a detection that fired is a
regression**, and the arbitration did the second. The fix is not a cleverer
suppression rule:

> **Never silence. Explain.**

The drift alarm now always raises, carrying the arbitration's opinion in its
metadata. An operator can read *"every sensor moved together, this looks
environmental"* and judge. They cannot read an alarm that was never raised.

### The shape of this one

Third time this week a plausible physical assumption failed on contact with the
hardware — after zero-variance-means-dead-axis, and after
a-dead-horizontal-axis-is-undetectable.

The difference is that this one had shipped. It had tests, a mutation check, a
docstring explaining its reasoning, and an entry in `fixproblem.md` describing it
as the fix. All of that was true about the code and false about the world.

**Test the physics, not the arithmetic.** A synthetic fixture agrees with
whatever assumption produced it.

---

## Three sensors were dead for 37 minutes and the appliance said they were fine (fixed 2026-08-11)

Found while explaining `portlock.py`, by checking a claim rather than repeating
it. The claim was mine, made half an hour earlier: *"your sensors are fine — all
three recording 255 samples per minute."*

They were not recording anything.

### What happened

Two adapters were unplugged and replugged. The kernel destroyed `ttyUSB0/1/2`
and created `ttyUSB3/4/5`. Acquisition kept its original file descriptors:

```
fd 11 -> /dev/ttyUSB0 (deleted)
fd 17 -> /dev/ttyUSB1 (deleted)
fd 23 -> /dev/ttyUSB2 (deleted)
```

Every circuit breaker tripped `open`. The breaker did its job perfectly.

### Why nothing noticed

**A failed poll still produces an envelope.** The engine records every channel
with a null value and `quality = 'bad'`, because a failure that leaves no trace
is worse than one that does. That is correct behaviour and it had an unintended
consequence:

```
last 5 minutes:   3,366 rows,  0 with a value,  0 good
last good reading: 37 minutes earlier
```

`last_measurement_at` advances on every envelope. Liveness keyed on it, so it was
asking *"is anything arriving"* rather than *"is anything being measured"*.

**A sensor can be entirely dead while rows keep arriving, indefinitely.**

And `count(*)` in a diagnostic query counts those rows exactly like real
measurements — which is how the wrong conclusion was reached. The same
row-versus-reading distinction that `value_good` and
`MeasurementQualityContractTest` were built to enforce in the product that
morning, made by hand in a query that afternoon.

### Fixed

`sensors.last_good_measurement_at`, advanced only when an envelope contains a
reading with a value and `quality = 'good'`. Liveness keys on it.

Kept **separate** from `last_measurement_at` rather than replacing it. They
answer different questions and both are worth having:

| | Question |
|---|---|
| `last_measurement_at` | is the pipeline delivering anything at all |
| `last_good_measurement_at` | is the sensor actually measuring |

Collapsing them would lose the ability to distinguish *"the sensor is silent"*
from *"the sensor is answering with rubbish"* — different faults, different
repairs. Backfilled from history so the alarm does not fire on every sensor the
moment it deploys.

Two tests pin it: a sensor whose rows arrive while its readings stopped must
alarm, and a healthy one must not. Verified to bite — reverting the field fails
exactly the first.

### And a claim in the acceptance matrix that is now suspect

Case 8, *"sensor unplug / reconnect"*, is recorded **PASS** with *"4-minute gap,
then automatic recovery"*. That test removed and returned the **same device
node**. A real replug **renumbers** it, and acquisition does not recover from
that — it holds the old descriptor until restarted.

Case 8 tested the easier half of its own name. It needs re-running against a
renumbering replug, and until then its verdict does not mean what it says.

### The shape of this one

Not a missing mechanism. Two correct behaviours composing into a lie: recording
failed polls as rows is right, and advancing a liveness timestamp on arrival is
right, and together they produce a dead sensor that reports as healthy for as
long as you like.

**Count what you mean, not what is easy to count.** A row is not a reading, and
every query that forgets it will agree with you.
