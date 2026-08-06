# Operator manual

For the person watching the dashboard. Nothing here requires a terminal.

---

## The four things worth knowing before you read a number

**1. `verified` next to the sensor name.** It means the register map was
confirmed against the manufacturer's table *and* against the connected hardware.
If it says anything else, the numbers are a picture of an assumption.

**2. `provisional` on an alarm.** The threshold came from a table nobody has
checked against the published standard. **A provisional alarm never sends a
notification** — it is shown so you can see it, not so you can act on it.

**3. `websocket` or `polling` in the header.** `websocket` means readings arrive
within milliseconds. `polling` means the live connection is down and the chart is
refreshing once a second instead. **The data is correct either way** — only its
freshness differs.

**4. `silent` instead of `live`.** The sensor has not reported for over two
minutes. The chart may still show old data; the badge is the thing to believe.

---

## Reading the Live page

| Card | What it is | Watch for |
|---|---|---|
| **Acceleration** | Absolute, gravity included. At rest the three axes total about 1 g | A change in the resting values means the mounting moved |
| **Acceleration amplitude** | The vibration component only | This is the card that responds to a tap |
| **Velocity** | mm/s. The quantity structural limits are written against | Rises with vibration, returns to 0 |
| **Displacement** | µm | Rises with vibration, returns to 0 |
| **Dominant frequency** | Hz, computed inside the sensor | 0 when nothing is vibrating |
| **Inclination — roll / pitch** | Degrees, derived from gravity | Slow drift means the mounting is moving |
| **Tilt from vertical** | One number for "how far off vertical" | The simplest mounting-integrity check |
| **Sensor internal temperature** | The sensor's own silicon, not room air | Reads a little above ambient and lags it |

### Two behaviours that look like faults and are not

**Tapping the sensor barely moves the Acceleration card.** That register is
filtered inside the sensor. Watch **Acceleration amplitude** instead — it
responds to every tap. The vendor's own software behaves identically.

**Tilting takes about nine seconds to settle.** Also the sensor's filtering,
measured at 9.0 s. For "has the mounting moved" — which is what inclination is
for — a wall shifts over days, so nine seconds is invisible. It only shows in a
hand test.

### The "remove static offset" button

On the Acceleration card only. Gravity puts about 1 g on the axes, which squashes
the vibration into roughly 1% of the chart height. The button subtracts it, and
the vibration becomes visible — measured, a 1553× magnification.

**Turn it off for a tilt test.** Tilting *changes* the static offset, so removing
it hides exactly what you are testing. That is why it starts off.

Whatever it removed is printed under the chart. Nothing is hidden.

---

## Time windows

`1 min` through `24 hours`. Longer windows switch to hourly averages, and the
card says **"peaks flattened"** when it does. For looking at an event, use the
shortest window that contains it.

---

## The Signal page

Frequency content, with two answers side by side because they reach different
distances.

**What this appliance sampled** — limited to about 3 Hz by the polling rate. It
will refuse rather than draw something it cannot support:

| Message | Meaning |
|---|---|
| A spectrum with a marked peak | A real, sustained component |
| *"No component is distinguishable from noise"* | Nothing periodic here. A still structure has no spectrum |
| *"This window is not stationary…"* | The window holds an **event**, not sustained vibration. **Narrow the window to the event itself** |
| *"Requested … exceeds Nyquist"* | Beyond what the sampling can support. It will not guess |

**What the sensor reports** — computed on-device at full rate, valid to 300 Hz.
When the two disagree, ours is the one limited by the bus.

---

## Alarms

| Column | |
|---|---|
| **Level** | `warning` or `critical` |
| **Provisional** | Threshold unconfirmed — displayed, never notifies |
| **Value / Threshold** | What was measured against what |
| **Acknowledged** | Who accepted it, and when |

**Acknowledging** records that a person saw it and took responsibility. It needs
a note. It does not clear the condition — if the vibration continues, the alarm
stays active.

Operators and above can acknowledge. A kiosk screen cannot, by design: an
acknowledgement must be attributable to a person, not to a screen in a corridor.

---

## The kiosk display

Four large figures, no controls. The header is the part to watch:

| Header | Meaning |
|---|---|
| `live` (green) | Readings are current |
| `no data — last reading …` (**red**) | **The feed has stopped.** The numbers on screen are stale and dimmed |

A frozen screen showing a plausible number is the failure this guards against, so
staleness is stated rather than left to be inferred from a trace that stopped
moving.

---

## When to call someone

| You see | Do |
|---|---|
| `silent` for more than a few minutes | Check the sensor cable, then escalate |
| Every card at `—` | Acquisition is probably stopped. Escalate |
| Numbers 100× larger than usual | **Do not act on them.** The displacement range mode may have changed. Escalate |
| A critical alarm that is **not** provisional | Follow your site's procedure — this one is against a confirmed threshold |
| A critical alarm that **is** provisional | Note it, escalate for interpretation. It is not a compliance statement |

---

## When the dashboard says readings are behind

You will see one of three messages above the movement figures. They mean
different things and only one of them needs you to do anything.

**"Readings are behind"** — the spool is holding readings the database has not
caught up with yet. This is the appliance working. The readings are on disk and
nothing is lost. It clears itself, usually within minutes. The figures on the
page lag until it does.

**"Readings are parked past the retry ceiling"** — a long outage stranded
healthy readings. Nothing is lost, but they will not arrive until you release
them. The banner shows the exact command; it asks for confirmation before doing
anything, and running it twice is harmless.

**"Readings are not being delivered"** — the forwarder has stopped. Sensors are
still recording, but **every figure on the page is older than it looks**. Do not
read the movement numbers as current. Report this one.

If you see no banner at all, readings are arriving normally. That is the
intended state and it is silent on purpose — a warning that is always on screen
stops being read.

### What none of these mean

None of them means a sensor is broken. Sensor faults appear on the sensor cards
and on the State of Health page, and they are a separate question. A perfectly
healthy sensor can have a large backlog, and a dead sensor can have none.

