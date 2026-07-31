# Durable Project Decisions

Long-lived facts and decisions for the QuakeVault industrial monitoring
appliance. Session-specific detail does not belong here.

## Scope

Enterprise industrial monitoring appliance for RS-485 Modbus RTU sensors on
dedicated Ubuntu hosts. Initial sensors: WTVB01-485 (vibration aggregates) and
HWT901B-485 (AHRS / inclinometer). Further sensors are added as profiles, never
as engine changes.

## Repository and stack

- Standalone repo at `/var/www/quakevault-industrial` (ADR-001). Distinct from
  `/var/www/quakevault-shm` and `/var/www/dashboard.quakelogic.net/laravel`,
  which remain in service and are not modified by this project.
- PostgreSQL + TimescaleDB (ADR-002); Laravel + Sanctum; React/TS/Tailwind;
  Redis Streams; Mosquitto for outbound integration only.
- Python 3.12 acquisition service, `pymodbus` 3.14, `pydantic` 2.13.

## Acquisition

- Runs on the host as hardened systemd units under a dedicated
  `quakevault-acq` account. Never inside a container; no serial device is
  mounted into any container (ADR-003).
- Exactly one process owns a serial port, enforced by advisory lock.
- Must survive Docker, Redis, MQTT, and database outages via a durable local
  spool with ordered, idempotent replay.

## Hardware as commissioned

- USB bridge: CH340, `1a86:7523`, at `/dev/ttyUSB0`, symlinked
  `/dev/serial/by-id/usb-1a86_USB_Serial-if00-port0`.
- CH340 exposes no USB serial number, so by-id names collide with a second
  identical adapter. Stable identity keys on USB topology via udev (ADR-008).
- For production, prefer an isolated FTDI FT232 adapter: galvanic isolation on
  industrial sites, and a tunable latency timer the CH340 lacks.
- The `quakelogic` account is **not** in `dialout`. Until fixed, nothing can open
  the port.

## Sensor profiles

- Profiles are versioned YAML validated by pydantic; code never branches on
  model name (ADR-004).
- `verification_status` gates alarms: only `verified` may drive them (ADR-005).
  Current state: HWT901B-485 `candidate`, WTVB01-485 `unverified`.
- WTVB01-485 register addresses are a hypothesis and must be confirmed with
  `tools/probe.py` before use.
- HWT901B-485 scaling (confirmed against the published WitMotion family table,
  pending hardware): acceleration `raw/32768*16` g, angular velocity
  `raw/32768*2000` deg/s, attitude `raw/32768*180` deg, quaternion `raw/32768`,
  temperature `raw/100` degC.
- The two sensors share exactly one capability: temperature. Asserted by test.

## Measured bus capacity

CH340, 5 ms turnaround, 20% safety margin:

| Config | Sustainable poll | Defensible spectral band |
|---|---|---|
| WTVB01, 9600, 1 sensor | 13.4 Hz | 5.4 Hz |
| WTVB01, 115200, 1 sensor | 58.6 Hz | 23.5 Hz |
| HWT901B, 115200, 1 sensor | 64.7 Hz | 25.9 Hz |
| HWT901B, 230400, 1 sensor | 73.3 Hz | 29.3 Hz |
| HWT901B, 230400, 1 sensor, FTDI | 96.6 Hz | 38.6 Hz |

Above ~57600 baud the bottleneck is device turnaround plus USB latency, not wire
time: 9600 to 115200 gains 4.8x, 115200 to 230400 gains 13%.

## Signal-processing position

- Polled Modbus is **not uniformly sampled**. Spectral features are capped at
  0.4x the measured rate; requests above that are refused with an explanation
  (ADR-006). Machine-vibration spectra are out of scope for both sensors.
- WTVB01-485 reports aggregates (RMS velocity, displacement, dominant
  frequency), not waveform. Trend them; do not reconstruct spectra from them.
- Derived translational velocity/displacement is optional, off by default,
  labelled `derived`, and stored with full provenance.

## Unresolved manufacturer questions

1. WTVB01-485 register addresses and whether velocity/displacement/frequency are
   direct engineering integers or full-scale ratios.
2. Whether WTVB01-485 exposes acceleration on the firmware in hand.
3. Actual device turnaround for both parts (currently assumed 5 ms).
4. Whether the connected HWT901B-485 variant populates pressure and altitude.
5. Firmware versions of both units, and whether register layouts differ across
   them.
