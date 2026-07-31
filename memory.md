# Durable Project Decisions

Long-lived facts and decisions for the QuakeVault industrial monitoring
appliance. Session-specific detail does not belong here.

## Scope

Enterprise industrial monitoring appliance for RS-485 Modbus RTU sensors on
dedicated Ubuntu hosts. Supported sensor: WTVB01-485 only. The HWT901B-485 was
evaluated and retired on 2026-07-31 (ADR-009) because the WTVB01 covers every
needed channel; its profile survives in acquisition/tests/fixtures/ purely to keep
the capability model under test. Further sensors are added as profiles, never as
engine changes.

## Repository and stack

- Standalone repo at `/var/www/quakevault-industrial` (ADR-001). Distinct from
  `/var/www/quakevault-shm` and `/var/www/dashboard.quakelogic.net/laravel`,
  which remain in service and are not modified by this project.
- Policy: run the latest stable of everything (operator decision, 2026-07-31).
- Verified stack as of 2026-07-31: PostgreSQL **18.4** + TimescaleDB **2.29**,
  Redis **8.10**, Laravel **13.23** on PHP **8.4.23**, Node 22, Python 3.12.3,
  pymodbus 3.14, pydantic 2.13. Mosquitto for outbound integration only.
- PostgreSQL 18 gotcha: the Docker volume must mount at `/var/lib/postgresql`,
  NOT `/var/lib/postgresql/data`. Mounting the old path makes the container
  refuse to start; 18 keeps its cluster in a major-version subdirectory so
  `pg_upgrade --link` can run without crossing a mount boundary.
- Our Redis is published on 6380; the host's 6379 belongs to another product.
- PHP needs `php8.4-pgsql` installed; it is not in the base install.

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
  **WTVB01-485 is `verified`** as of 2026-07-31, against manufacturer document
  V260508 (firmware package Version 10059) and confirmed on hardware.
- Verified scalings: acceleration 0x34-0x36 `raw/32768*16` g; vibration velocity
  0x3A-0x3C `raw/100` mm/s; temperature 0x40 `raw/100` degC; displacement
  0x41-0x43 `raw` um; dominant frequency 0x44-0x46 `raw/10` Hz; 36 condition
  indicators 0x47-0x6A `raw/1000`; fault words 0x6B-0x6D unscaled.
- Registers 0x37-0x39 are live but absent from the manufacturer table, so they
  stay unmapped. 0x3D-0x3F are documented Reserved and read zero.
- Two errors the verification gate caught, both of which would have shipped a
  confidently wrong dashboard: 0x44-0x46 is frequency, not velocity; and velocity
  is `raw/100`, not `raw`.
- OPEN: VY/VZ (0x3B/0x3C) and DY/DZ (0x42/0x43) read exactly zero on the unit in
  hand, while all three frequency axes report non-zero. Y/Z velocity and
  displacement alarms stay disabled pending a second unit. The sensor was sitting
  loose on a desk during testing, which may contribute.

## Measured bus capacity

CH340, 5 ms turnaround, 20% safety margin:

| Config | Sustainable poll | Defensible spectral band |
|---|---|---|
| WTVB01, 9600, 1 sensor | 13.4 Hz | 5.4 Hz |
| WTVB01, 115200, 1 sensor | 58.6 Hz | 23.5 Hz |

Above ~57600 baud the bottleneck is device turnaround plus USB latency, not wire
time: 9600 to 115200 gains 4.8x, 115200 to 230400 gains 13%.

## Signal-processing position

- Polled Modbus is **not uniformly sampled**. Spectral features are capped at
  0.4x the measured rate; requests above that are refused with an explanation
  (ADR-006). Machine-vibration spectra are out of scope over Modbus polling; the device's own
  condition indicators cover condition monitoring instead.
- WTVB01-485 reports aggregates (RMS velocity, displacement, dominant
  frequency), not waveform. Trend them; do not reconstruct spectra from them.
- Derived translational velocity/displacement is optional, off by default,
  labelled `derived`, and stored with full provenance.

## Unresolved manufacturer questions

1. Why VY/VZ and DY/DZ read exactly zero while HZY/HZZ report real frequencies.
2. Actual device turnaround (currently assumed 5 ms).
3. Meaning of the fault-diagnosis bitfields at 0x6B-0x6D; the manual names the
   registers but documents no bit semantics.
