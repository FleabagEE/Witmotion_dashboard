# QuakeVault Structural Vibration Monitoring Appliance

Monitors vibration effects on **buildings and structures** using RS-485 Modbus RTU
sensors, on dedicated Ubuntu hosts.

This is structural monitoring, not machine condition monitoring. The governing
standards are DIN 4150-3 and BS 7385-2, which grade *peak* particle velocity by
structure type and frequency - a different question, and different arithmetic,
from asking whether a motor is healthy.

Supported sensor:

- **WTVB01-485** — MEMS vibration sensor. Triaxial acceleration, vibration
  velocity, displacement and dominant frequency, chip temperature, and 36
  on-device statistical indicators.

> **Read `docs/known-limitations.md` before trusting any structural reading.**
> The unit in hand reports vibration velocity on the X axis only (a confirmed
> device defect), and both standards require the maximum of three orthogonal
> components. A compliant assessment cannot be produced until that is resolved.

Additional sensors are added by writing a profile, never by changing the
acquisition engine.

## Layout

```
acquisition/          Python 3.12 host service (never containerised)
  src/qv_acq/
    crc.py            Modbus RTU CRC-16
    decode.py         Register -> engineering units
    throughput.py     Bus capacity and spectral gating
    profiles/         Capability schema + YAML loader
  tools/probe.py      Read-only hardware verification probe
  tests/
profiles/             Versioned sensor profiles (YAML)
deploy/               systemd, udev, nginx, mosquitto, kiosk
docs/                 Architecture, decisions, limitations, register maps
```

## Quick start

```bash
python3 -m venv .venv && .venv/bin/pip install -e acquisition
```

Run the test suite:

```bash
cd acquisition && ../.venv/bin/python -m pytest -q
```

Probe connected hardware (read-only; cannot write to the device):

```bash
.venv/bin/python acquisition/tools/probe.py --port /dev/ttyUSB0 --scan-baud --scan-slave 1-32
```

## Read these first

- [`docs/known-limitations.md`](docs/known-limitations.md) — what this appliance
  can and cannot measure, with the numbers behind each claim
- [`docs/decision-log.md`](docs/decision-log.md) — architecture decisions
- [`docs/register-maps.md`](docs/register-maps.md) — how a register map is
  verified before it is trusted
- [`TASKS.md`](TASKS.md) — current state and what is blocked

## Two things that shape the whole design

**Register maps are guilty until proven innocent.** A wrong map does not throw;
it returns plausible numbers. Profiles therefore carry a `verification_status`,
and only `verified` profiles may drive alarms. Both shipped profiles are
currently unverified pending hardware commissioning.

**Polled Modbus is not a data-acquisition path.** Sampling is slow and, more
importantly, non-uniform. Spectral features are capped at 0.4x the *measured*
rate and refused beyond it. Machine-vibration spectra are out of scope for both
sensors; the appliance trends aggregates and alarms on them instead.
