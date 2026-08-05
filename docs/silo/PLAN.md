# Three-sensor silo deployment — implementation plan

Started 2026-08-05. Living document: each phase is updated as it lands, and
every phase ends in a tagged commit.

## The installation

Two silos joined at mid-height by a concrete connection. Three WTVB01-485
sensors, **all in the same orientation**, on the same RS-485 bus:

| Sensor | Position | Modbus | Role |
|---|---|---|---|
| SENSOR-001 | Top | 0x50 | movement of the upper structure |
| SENSOR-002 | Mid-height | 0x51 | movement at the joined level |
| SENSOR-003 | Ground | 0x52 | **reference** — what the site does to everything |

The ground sensor is what makes the other two interpretable. A lorry, a distant
blast or a seismic event moves all three; only the difference between them is
the structure. Without it, every site disturbance looks like silo movement.

## What must be true when this is finished

- Alarms on tilt, acceleration amplitude, velocity amplitude and displacement
  amplitude, for every sensor.
- One dashboard showing all three together, not three dashboards.
- A silo diagram with the sensors on it, green or red at a glance.
- State of health for each unit.
- Alarms published over MQTT as well as email.
- Every threshold crossing recorded with a timestamp, already true.

## Phases

Each is a commit. Later phases assume earlier ones.

| # | Phase | Status |
|---|---|---|
| 1 | Multi-sensor data model, bus capacity, provisioning | todo |
| 2 | Alarm definitions for all four quantities on all sensors | todo |
| 3 | Reference-sensor differencing (what the site did vs what the silo did) | todo |
| 4 | MQTT alarm publication | todo |
| 5 | State of health per sensor | todo |
| 6 | Dashboard restructure — multi-sensor, enterprise design | todo |
| 7 | Silo diagram with live sensor status | todo |
| 8 | Documentation, acceptance, tag | todo |

## Decisions taken

**Charting stays ECharts.** The request named "Python's best toolbox for
graphs"; Python plotting libraries do not run in a browser. The reference
dashboard at /var/www/quakevault-shm uses ECharts, and so does this one, so the
house style already agrees. Raised rather than silently substituted.

**Design language follows the reference dashboard**: MetricCard tones, semantic
StatusBadge, sidebar navigation, Tailwind with a dark theme. Adopted rather than
invented so the two products look like siblings.

## Open questions

- Displacement amplitude: the WTVB01-485 reports it in um from a register whose
  range mode is not readable. Recorded in docs/known-limitations.md.
- Vibration thresholds remain unconfirmed placeholders until real site data
  exists. `alarms:vibration-survey` supplies the numbers.
- The 3 deg tilt critical is a seismic criterion; a settlement warning level has
  not been chosen by anybody.
