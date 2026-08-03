# QuakeVault Industrial — Technical Summary

Sensor-to-browser pipeline for structural vibration monitoring (WTVB01-485 over
Modbus RTU / RS-485). Generated from the code at commit `329756f`.

---

## Four corrections to the brief before the detail

The questions assume an architecture that differs from what is actually built.
The differences are load-bearing, so they come first:

1. **There is no MCU and no firmware we control.** The WTVB01-485 is a sealed
   commercial sensor with its own DSP. We are a Modbus *master* reading holding
   registers from it. Nothing in this repo runs on the device.
2. **We compute no DSP at all.** FFT, integration and all statistics happen
   *inside the sensor*. Velocity, displacement and dominant frequency are read
   as registers, not derived. See §3.
3. **There is no direct serial→WebSocket relay.** There are two paths from the
   sensor: a durable one (system of record) and a lossy live one (the view).
   Both run; neither replaces the other. See §2.
4. **The stack is Python + PHP, not Node.** Acquisition is Python 3.12; the API
   and WebSocket server are Laravel 13 / PHP 8.4.

---

## 1. Data Model & Protocol Definition

### 1.1 WebSocket frame (Redis → bridge → browser)

Emitted by `acquisition/src/qv_acq/live.py`, broadcast unchanged by
`App\Events\LiveMeasurement`. One frame per register group per poll, **not** one
frame per sensor — a browser receives several frames per second with different
`group` values.

```json
{
  "sensor_id": "SENSOR-001",
  "group": "acceleration",
  "t": 1785544083388,
  "quality": "good",
  "values": { "accel_x": 0.9384765625, "accel_y": 0.048828125, "accel_z": 0.30224609375 }
}
```

- `t` — **milliseconds since epoch, stamped at the moment of the Modbus read**,
  not at broadcast. This is what lets the browser measure its own end-to-end lag.
- `quality` — `good` | `implausible` | `bad`. `implausible` means the value
  decoded but fell outside the profile's declared engineering range, which is
  strong evidence of a wrong register map, word order or slave ID.
- `values` — **channels that read nothing are omitted, never sent as `0.0`.** A
  missing reading plotted as zero looks like a still structure rather than an
  absent one.

**Deliberately absent: any alarm, level or threshold field.** The live channel is
lossy by design, so nothing on it may be actionable. Asserted by
`LiveBridgeTest::test_the_live_channel_carries_no_alarm_or_threshold_state`.

### 1.2 Channel keys by group

| `group` | Channel keys | Unit | Poll rate |
|---|---|---|---|
| `acceleration` | `accel_x/y/z` | g | 8 Hz |
| `vibration_velocity` | `vib_velocity_x/y/z` | mm/s | 4 Hz |
| `vibration_summary` | `temperature`, `vib_displacement_x/y/z`, `vib_frequency_x/y/z` | °C, µm, Hz | 4 Hz |
| `condition_x/y/z` | 12 each — `rms_accel_*`, `kurtosis_*`, `skewness_*`, `cf_*`, … | mixed | 0.33 Hz |
| `fault_diagnosis` | fault words | bitfield | 0.1 Hz |

### 1.3 Stored-series shape (REST, the authoritative path)

```ts
interface SeriesPoint {
  t: number          // epoch ms, bucket start
  v: number | null   // avg(value) in bucket
  lo: number | null  // min(value) — preserves peaks through downsampling
  hi: number | null  // max(value)
}
```

`lo`/`hi` exist so downsampling cannot hide a transient. Averaging alone would
erase exactly the peak a vibration limit is written against.

### 1.4 Parsing: raw words → physical units

Two stages, both declarative. **Scale factors live in the profile, not in code** —
`profiles/wtvb01-485.v1.yaml`:

```yaml
# Acceleration. Manual 10.4.5: AX = AX[15:0]/32768*16 g.
- {key: accel_x, unit: g,    address: 0x34, scale: 0.00048828125, minimum: -16, maximum: 16}
# Vibration velocity. Manual 10.4.6: VX (mm/s) = raw/100.
- {key: vib_velocity_x, unit: mm/s, address: 0x3A, scale: 0.01,   minimum: 0, maximum: 120}
# Temperature. Manual 10.4.8: TEMP[15:0]/100 degC.
- {key: temperature,    unit: degC, address: 0x40, scale: 0.01,   minimum: -40, maximum: 125}
# Displacement. Manual 10.4.9: DX(um) = raw, no scaling.
- {key: vib_displacement_x, unit: um, address: 0x41, scale: 1.0,  minimum: 0, maximum: 32767}
# Dominant frequency. Manual 10.4.10: HZX (Hz) = raw/10.
- {key: vib_frequency_x, unit: Hz,  address: 0x44, scale: 0.1,    minimum: 0, maximum: 300}
```

The conversion itself — `acquisition/src/qv_acq/decode.py`:

```python
def decode_raw(words, data_type, *, word_order="big"):
    if data_type == "uint16":
        return words[0]
    if data_type == "int16":
        # Signed 16-bit two's complement. WitMotion returns signed values for
        # acceleration, angular velocity, angle, magnetic field and temperature.
        return words[0] - 0x10000 if words[0] & 0x8000 else words[0]
    ...

def apply_scaling(raw, *, scale=1.0, offset=0.0):
    """Convert a raw value to engineering units: ``raw * scale + offset``."""
    return raw * scale + offset
```

Applied per channel in `client.py`, which also quality-flags on the spot:

```python
value = decode(words, channel.data_type, word_order=channel.word_order,
               scale=channel.scale, offset=channel.offset)
quality = (Quality.GOOD
           if plausible(value, minimum=channel.minimum, maximum=channel.maximum)
           else Quality.IMPLAUSIBLE)
```

**Nothing downstream of `client.py` ever sees a register number.**

### 1.5 Two register-map traps the verification gate caught

Both would have shipped confident nonsense:

- `0x44-0x46` are **dominant frequency**, not a second velocity block as the
  summary table's layout suggests.
- Velocity is `raw/100`, not `raw/32768*full_scale` like acceleration.
- `0x37-0x39` respond to excitation on the live unit but appear **nowhere** in
  the manufacturer's table — almost certainly the underlying IMU's angular-rate
  registers. They are deliberately left unmapped: an undocumented register can
  move or vanish in a firmware revision.

The service **refuses to start** on a sensor whose profile is not
`verification_status: verified`.

---

## 2. Backend & Streaming Setup

### 2.1 Topology

```
WTVB01-485 ──RS-485/Modbus RTU──> USB adapter (/dev/quakevault-rs485-a, udev alias)
     │
     └─> qv_acq (Python, systemd) ──┬─> SQLite spool ─> forwarder ─> Laravel API ─> TimescaleDB
                                    │      DURABLE PATH: ordered, idempotent, survives power loss
                                    │
                                    └─> Redis pub/sub ─> live:bridge (PHP) ─> Reverb (WS) ─> browser
                                           LIVE PATH: lossy by design, p50 8 ms
```

The udev alias matters: `/dev/ttyUSB0` renumbers across reboots and replugs.

### 2.2 The serial read (Python — `client.py`)

```python
client = ModbusSerialClient(port=self.port, baudrate=self.baud,
                            bytesize=8, parity="N", stopbits=1, timeout=self.timeout)

response = self._client.read_holding_registers(
    address=group.start_address, count=group.register_count, device_id=slave,
)
```

### 2.3 Bus serialisation (`engine.py`)

RS-485 is half-duplex: two overlapping transactions corrupt each other. The
guarantee is structural rather than a lock:

```python
# One thread per bus. This is what serialises the bus: no two
# transactions on this port can ever overlap.
self._executor = ThreadPoolExecutor(max_workers=1, thread_name_prefix=f"bus-{self.bus_id}")
```

Scheduling is earliest-deadline-first, so a fast group cannot starve a slow one:

```python
due = [t for t in self.tasks if t.due(now)]
due.sort(key=lambda t: t.next_due)   # oldest deadline first
for task in due:
    measurement = await self._poll(loop, task)
    task.schedule_next(time.monotonic())
    self.sink(measurement)
```

### 2.4 The relay (`live.py` — the actual serial→WS hop)

```python
def __call__(self, measurement: Measurement) -> None:
    """Sink interface. Never raises, never blocks."""
    payload = {...}
    try:
        self._queue.put_nowait(json.dumps(payload, separators=(",", ":")))
    except Full:
        # The consumer is behind. Drop the newest rather than block the poll
        # loop or grow without bound; a live view may skip a frame.
        self.dropped += 1
```

The tee is where the priority is enforced:

```python
class TeeSink:
    def __call__(self, measurement: Measurement) -> None:
        self.durable(measurement)          # allowed to raise
        for sink in self.best_effort:
            try:
                sink(measurement)
            except Exception as exc:
                log.debug("best-effort sink failed: %s", exc)   # swallowed
```

**A dashboard can never cost you a measurement.** Pinned by
`test_durable_sink_still_receives_when_the_live_sink_fails`.

### 2.5 Sampling rates, and why they are what they are

Configured in `/etc/quakevault/acquisition.yaml`:

```yaml
poll_hz:
  acceleration: 8         # fast-moving quantities get the bandwidth
  vibration_velocity: 4
  vibration_summary: 4
  condition_x: 0.33       # computed on-device anyway; polling harder buys nothing
  condition_y: 0.33
  condition_z: 0.33
  fault_diagnosis: 0.1
```

Sum ≈ **17.1 group-reads/s**. Measured in the browser: **171 frames in 10 s** —
an exact match, i.e. no frames are being lost between sensor and screen at these
rates.

Rates are not guesses. `throughput.py` models the bus from first principles:

```
request  = addr(1) + func(1) + start(2) + count(2) + crc(2)      = 8 bytes
response = addr(1) + func(1) + bytecount(1) + 2N data + crc(2)   = 5 + 2N bytes
+ 3.5 char-times idle each way + device turnaround + USB-bridge latency
```

USB latency is charged by bridge chip (`ch340: 4.0 ms`, `ftdi: 1.5`, `cp210x: 2.0`)
— cheap CH340 parts are materially worse and FTDI's latency timer is tunable.
Measured bus utilisation at these rates is **0.572**, leaving headroom for retries.

### 2.6 Buffering — four bounded stages, no unbounded queue anywhere

| Stage | Mechanism | Overflow behaviour |
|---|---|---|
| Live publisher | `Queue(maxsize=500)`, background thread | Drops **newest**, counts it. Never blocks the poll loop. |
| Bridge | Rate cap per `sensor\|group` | 15 Hz cap; excess counted as `throttled` |
| Spool | SQLite WAL, `synchronous=FULL`, 500 000 rows | Delivered rows purged first; undelivered dropped only if still full, **counted and logged, never silent** |
| Forwarder | Batches of 200, 1 s interval | Exponential backoff; SHA-256 idempotency key per batch |
| Browser | `liveFrames` capped at 4000, windowed | Oldest evicted |

The forwarder interval was **5 s → 1 s**: it was the dominant term in end-to-end
latency on the durable path, and everything upstream of it is sub-second.

The spool's idempotency key includes a `run_id`, added after **98 measurements
were silently lost**: sequence numbers restart at 1 on each service start, so
keys collided across restarts and the second run's rows were discarded as
duplicates.

---

## 3. DSP & Signal Calculations

### 3.1 Where the DSP happens: entirely on the sensor

**We run no FFT and no numerical integration.** Velocity, displacement and
dominant frequency are read from registers `0x3A-0x3C`, `0x41-0x43` and
`0x44-0x46`. The WTVB01-485 computes them internally from its own high-rate
sampling.

This is the single most important property of the design, from the profile:

```yaml
# These matter more than they look. The device computes them internally from
# its own high-rate sampling, so they are NOT limited by our polling rate -
# this is how the appliance does real condition monitoring despite the Modbus
# bandwidth ceiling documented in known-limitations.md.
```

Polling at 8 Hz would, by Nyquist, resolve nothing above 4 Hz. But the sensor's
own 108–126 Hz dominant-frequency readings are valid, because they are computed
device-side at full rate and we are merely *transporting* the result.

### 3.2 Filters, windowing, smoothing: none, deliberately

**No cut-off frequency, no moving average, no windowing function is applied
anywhere in this codebase.** Grep confirms it. Two reasons:

- The sensor's internal DSP is a black box. Filtering its already-processed
  outputs would compound two unknown transfer functions.
- Structural vibration limits are written against *peak* particle velocity. A
  moving average attenuates exactly the transient the limit exists to catch.

The only data reduction is **server-side time-bucketing for display**, and it
carries min/max precisely so peaks survive:

```sql
SELECT time_bucket(make_interval(secs => ?), time) AS t,
       avg(value) AS value, min(value) AS min_value, max(value) AS max_value
FROM measurements
WHERE sensor_id = ? AND channel_key = ? AND time BETWEEN ? AND ?
GROUP BY t ORDER BY t LIMIT ?
```

### 3.3 The honesty gate on spectral analysis

Polled Modbus is **non-uniformly sampled**, so Nyquist overstates what is
defensible. `spectral_verdict()` refuses to produce a spectrum the hardware
cannot support, and returns an explanation the UI must show:

```python
usable = sustainable_poll_hz * 0.40
if requested_hz <= usable:
    return True, f"Requested {requested_hz:.1f} Hz is within the {usable:.1f} Hz defensible band…"
if requested_hz <= sustainable_poll_hz / 2:
    return False, ("… sits between the defensible band and Nyquist. "
                   "Polled Modbus sampling is non-uniform; spectra in this range smear. "
                   "Use a dedicated capture window on an otherwise idle bus.")
return False, ("… exceeds Nyquist. Aliasing would fabricate spectral content. "
               "Increase baud, reduce sensors per bus, or use a sensor with native spectral output.")
```

We advertise **0.4 × fs**, not fs/2.

---

## 4. Frontend Framework & State Management

### 4.1 Stack

| Purpose | Library |
|---|---|
| UI | React 19.2 + TypeScript 6.0 |
| Build | Vite 8.2 |
| Styling | Tailwind 4.3 |
| Charts | **ECharts 6.1** via `echarts-for-react` 3.0 |
| Server state | TanStack React Query 5.101 |
| Routing | React Router 7.18 |
| WebSocket | `laravel-echo` 2.4 + `pusher-js` 8.6 → Laravel Reverb |
| Lint | oxlint 1.75 |

ECharts with `animation: false` — chart animation on a live waveform adds
apparent latency the data doesn't have.

### 4.2 State: two sources, merged with strict precedence

```tsx
// Live frames are only ever appended after the newest stored point, so a
// websocket frame can never overwrite or reorder recorded history.
const series = useMemo(() => {
  if (!stored) return undefined
  if (liveFrames.length === 0) return stored

  const merged: Record<string, SeriesPoint[]> = {}
  for (const [key, points] of Object.entries(stored)) {
    const lastStored = points.length ? points[points.length - 1].t : 0
    const extra: SeriesPoint[] = []
    for (const frame of liveFrames) {
      const value = frame.values[key]
      if (value !== undefined && frame.t > lastStored) {
        extra.push({ t: frame.t, v: value, lo: value, hi: value })
      }
    }
    merged[key] = extra.length ? [...points, ...extra] : points
  }
  return merged
}, [stored, liveFrames])
```

The `frame.t > lastStored` guard is what stops a reading appearing twice — once
live, once when its durable copy lands.

### 4.3 Subscription and buffer bounding

```tsx
useEffect(() => {
  if (!selected) return
  setLiveFrames([])
  const unsubscribe = subscribeToSensor(selected, (frame) => {
    setConnected(true)
    setLiveFrames((previous) => {
      const cutoff = Date.now() - windowRef.current
      const next = [...previous, frame].filter((f) => f.t >= cutoff)
      // Bounded: a long window at a high poll rate would otherwise grow the
      // buffer until the tab slows down.
      return next.length > 4000 ? next.slice(next.length - 4000) : next
    })
  })
  return unsubscribe
}, [selected])
```

### 4.4 Reconnection handling

Driven by the socket's own state, not by frame arrival:

```ts
export function subscribeToConnectionState(onState: (state: LiveState) => void): () => void {
  const connection = liveConnection()
  if (!connection) { onState('disconnected'); return () => {} }

  const pusher = connection.connector.pusher
  const handler = ({ current }: { current: string }) => {
    onState(current === 'connected' ? 'connected' : 'disconnected')
  }
  handler({ current: pusher.connection.state })   // report the state we are already in
  pusher.connection.bind('state_change', handler)
  return () => pusher.connection.unbind('state_change', handler)
}
```

```tsx
useEffect(() => {
  return subscribeToConnectionState((state) => {
    const live = state === 'connected'
    setConnected(live)
    if (!live) setLiveFrames([])   // stale frames would look current until the window slid past them
  })
}, [])
```

**Why not infer it from frames.** The first version set `connected` on frame
arrival and never cleared it. A frame only proves the socket was up *when it was
sent*, so the badge latched on and kept claiming `websocket` throughout an
outage - describing 1-second polled data as live. The data was never wrong; the
label describing its freshness was, which on a monitoring appliance is the worse
failure of the two.

**Verified by pulling the server out from under it** (`systemctl stop
quakevault-reverb`):

| Stage | Socket | Badge | Data |
|---|---|---|---|
| Steady state | `connected` | `websocket` | 17 frames/s |
| Reverb stopped | `unavailable` | `polling` | still updating via REST (28.91 → 28.84 °C) |
| Reverb restarted | `connected` | `websocket` | 52 frames resumed |

Reconnection itself is pusher-js's, with its own backoff — recovery took tens of
seconds, during which the UI honestly reported `polling`. `subscribeToSensor`
returns a teardown that runs on sensor change and unmount.

There is no "MCU reset event" to handle. Device-side faults surface instead
through the `fault_diagnosis` register group and through `quality: bad` frames
when the sensor stops answering; liveness is evaluated server-side by
`AlarmEvaluator::evaluateLiveness()`.

**Gap: there is no frontend test suite.** No vitest, no testing-library — the
behaviour above is verified by hand and by hand only, unlike the backend (140
tests) and acquisition (212). Anything in `frontend/` can regress silently.

---

## Measured performance

| Metric | Value |
|---|---|
| Sensor read → browser (live path) | **p50 8 ms, p95 12 ms, max 22 ms** (n=171) |
| Same, through systemd-managed services | p50 12 ms, p95 36 ms (n=115) |
| Sensor read → browser (durable path, prior) | 0.43 – 1.64 s |
| Frames delivered vs configured | 17.1 /s vs 17.1 /s configured — no loss |
| Bus utilisation | 0.572 |
| Test suites | 140 backend, 212 acquisition — all green |

---

## Known constraints worth carrying into any consumer of this data

- **Structural thresholds are `candidate`, not confirmed.** DIN 4150-3 and
  BS 7385-2 are copyrighted and unavailable to this project, so their tables were
  reconstructed and are flagged as unverified. Alarms raised from them are marked
  `provisional`: displayed, **never** used to notify. `provisional` travels on the
  MQTT wire too, so no integration can mistake one for confirmed.
- **One of the two WTVB01-485 units is faulty.** Unit 1 reported VY/VZ/DY/DZ as
  exactly zero across 1505/1505 samples while simultaneously reporting 108 Hz and
  126 Hz dominant frequencies on those same axes — internally contradictory. Unit
  2 reports all six. Firmware is sound; the hardware is not. RMA drafted.
- **Whether the sensor's velocity register is peak or aggregate is unconfirmed**
  and is an open question in the RMA. It matters: PPV limits are peak-based.
