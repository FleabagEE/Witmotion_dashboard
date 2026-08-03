# API reference

All endpoints are under `/api`. Authentication is a Sanctum bearer token; every
route except `POST /api/v1/login` requires one.

```
Authorization: Bearer <token>
Accept: application/json
```

## Abilities

A token carries abilities, not a role. Roles map to abilities in
`app/Support/Roles.php`, so an endpoint asks for what it needs rather than
listing which roles are senior enough.

| Role | Abilities |
|---|---|
| `kiosk` | `read` |
| `viewer` | `read` |
| `operator` | `read`, `acknowledge` |
| `engineer` | `read`, `acknowledge`, `configure` |
| `auditor` | `read`, `audit` |
| `administrator` | all of the above plus `administer` |

An appliance token carries `ingest` and nothing else. It can write measurements
and read nothing — a compromised appliance credential cannot read the record it
writes to.

---

## Authentication

### `POST /api/v1/login`

The only unauthenticated route.

```json
{ "email": "engineer@example.com", "password": "…", "device_name": "dashboard" }
```

```json
{ "token": "1|abc…", "user": { "name": "…", "email": "…", "role": "engineer" } }
```

### `GET /api/v1/me` · `POST /api/v1/logout`

Current user; revoke the current token.

---

## Reading measurements

### `GET /api/v1/overview`
**Requires** `read`

Appliance summary: sensor counts, online/silent split, active alarms, and the
verification status of every sensor's register map.

### `GET /api/v1/sensors`
**Requires** `read`

```json
{ "data": [ {
  "sensor_id": "SENSOR-001",
  "model": "WTVB01-485",
  "verification_status": "verified",
  "trustworthy": true,
  "online": true,
  "silent_for_seconds": 0
} ] }
```

`verification_status` and `trustworthy` travel with every sensor deliberately. A
reading from a sensor whose register map was never confirmed is a picture of an
assumption, and nothing downstream should have to go and ask.

### `GET /api/v1/sensors/{sensorId}/channels`
**Requires** `read`

Channel metadata: key, unit, quantity, group, and the register it came from.

### `GET /api/v1/sensors/{sensorId}/latest`
**Requires** `read`

```json
{ "data": [ {
  "channel_key": "accel_z", "value": 0.9624, "unit": "g",
  "quality": "good", "source_type": "native", "at": "2026-08-03T20:15:02.618Z"
} ] }
```

`source_type` is `native`, `derived`, `processed` or `simulated`. Inclination is
`derived` — computed from the gravity vector, not read from a register, because
this sensor has none.

### `GET /api/v1/series`
**Requires** `read`

| Parameter | |
|---|---|
| `sensor_id` | required |
| `channel_key` | required |
| `seconds` | window, default 300 |
| `max_points` | default 300 |

### `GET /api/v1/series/multi`
**Requires** `read`

Same, but `channels` is a comma-separated list, **max 20**.

```json
{
  "resolution": "raw_bucketed",
  "series": {
    "accel_x": [ { "t": 1785772643000, "v": 0.9624, "lo": 0.9619, "hi": 0.9629 } ]
  }
}
```

`lo` and `hi` are the min and max within each bucket. They exist so downsampling
cannot hide a transient — averaging alone would erase exactly the peak a
vibration limit is written against.

`resolution` is `raw_bucketed` or `hourly_rollup`. The rollup has peaks
flattened, and the dashboard says so on the card.

**One request, not several.** Splitting the channels across requests would let
each half land at a slightly different moment, which on a chart reads as a skew
between cards that is not in the structure.

### `GET /api/v1/spectrum`
**Requires** `read`

| Parameter | |
|---|---|
| `sensor_id` | required |
| `channel_key` | required |
| `seconds` | default 300 |
| `requested_hz` | optional upper bound |

```json
{
  "analysis": {
    "sample_hz": 8.01, "jitter_ms": 7.0,
    "defensible_max_hz": 3.205, "nyquist_hz": 4.01,
    "allowed": true,
    "explanation": "Requested 3.2 Hz is within the 3.2 Hz defensible band…",
    "spectrum": {
      "frequencies": [], "power": [],
      "peak_hz": 1.5, "false_alarm_probability": 0.0001,
      "peak_significant": true,
      "detrended": true, "lowest_reportable_hz": 0.0199,
      "energy_concentration": 0.12, "transient": false, "transient_note": null
    }
  },
  "device_reported": { "mean_hz": 0.61, "max_hz": 221.8, "note": "…" }
}
```

**Read `allowed` before `spectrum`.** When the requested band exceeds 0.4 × fs
the response carries `spectrum: null` and an `explanation` instead. A blank chart
with a reason is worth more than a plausible one that is wrong.

**Read `transient` too.** A periodogram assumes a stationary signal. A three
second tap inside a fifteen minute window is not, and produces a confident peak
that describes the window length rather than the structure. When `transient` is
true, no component is reported however bright it looks.

`device_reported` is the sensor's own dominant-frequency reading, valid to
300 Hz because it is computed on-device at full rate. Ours stops at a few Hz.
Both travel together because either alone would mislead.

---

## Alarms

### `GET /api/v1/alarms`
**Requires** `read` · `?unacknowledged_only=1` to filter

```json
{ "data": [ {
  "id": 42, "name": "Structural vibration", "level": "critical",
  "value": 8.2, "threshold": 5.0, "unit": "mm/s",
  "provisional": true, "actionable": false,
  "thresholds_confirmed_by": null
} ] }
```

**`provisional` is the field that matters.** It means the threshold came from a
table nobody has confirmed against the published standard. A provisional alarm is
displayed and never notifies. `actionable` is the same judgement stated
positively.

### `POST /api/v1/alarms/{alarm}/acknowledge`
**Requires** `acknowledge`

```json
{ "note": "site checked, piling next door" }
```

A kiosk token cannot do this. An acknowledgement is a person taking
responsibility, and it has to be attributable to that person rather than to a
screen anyone can walk up to.

---

## Ingestion (appliance only)

`/api/internal/v1/…` · **requires the `ingest` ability**

### `POST /api/internal/v1/ingest/batch`

```json
{ "measurements": [ {
  "schema_version": "1.0",
  "appliance_id": "QV-EDGE-001",
  "run_id": "…", "sensor_id": "SENSOR-001",
  "group": "motion", "sequence": 12345,
  "timestamp_utc": "2026-08-03T20:15:02.618679Z",
  "measurements": { "accel_x": {
    "value": 0.9624, "unit": "g", "quality": "good",
    "class": "native", "raw": [1971]
  } }
} ] }
```

**Idempotent** on `appliance:run_id:sensor:group:sequence`. A forwarder that
crashes mid-batch re-offers the same envelopes; the replay is a no-op, not a
duplicate.

`run_id` is in that key for a reason. Sequence numbers restart at 1 on each
service start, so without it a replay after a restart collided with the previous
run and 98 measurements were silently discarded.

**Send microseconds in `timestamp_utc`.** They are preserved end to end. Whole
seconds would collapse the eight readings a second this appliance takes onto one
timestamp and cap any spectral analysis at 0.4 Hz.

**`raw` is worth sending.** Keeping the register words alongside every reading is
what allowed 143 rows to be recomputed months later when a decode was found to be
wrong. Without them the only correct response would have been to discard them.

### `GET /api/internal/v1/ingest/health` · `POST /api/internal/v1/ingest/profile`

Contract check; announce a sensor profile so channels are enriched with units and
register metadata.

---

## Errors

| Status | Meaning |
|---|---|
| 401 | No token, or the token is invalid |
| 403 | The token lacks the ability this route needs |
| 404 | No such sensor, alarm or channel |
| 422 | Validation failed — the body names the field |
| 202 | Batch accepted (may include `duplicates` and `rejected` counts) |

A 202 with `rejected > 0` is a partial success. The response lists each rejected
envelope with its index and reason; the accepted ones are already stored.
