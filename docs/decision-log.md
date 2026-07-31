# Architecture Decision Log

Newest last. Each record states the decision, why, and what it costs.

---

## ADR-001 — Standalone repository

**Decision.** Build in a new repository at `/var/www/quakevault-industrial`
rather than extending `quakevault-shm` or the HDB tremor dashboard.

**Context.** Both existing products are MariaDB, session-authenticated, and
domain-specific to seismic monitoring. The target stack is PostgreSQL with
TimescaleDB, Sanctum, and Mosquitto. Grafting would have meant either bending
the spec or destabilising a product in service.

**Cost.** No reuse of any kind. This is a clean-room build: no code, schema,
container, database, or runtime is shared with any other product, and none is
copied from one. Everything the appliance needs is written here.

---

## ADR-002 — PostgreSQL + TimescaleDB

**Decision.** TimescaleDB hypertables for sample data; plain PostgreSQL tables
for the domain model.

**Context.** At 20 Hz across 13 channels one sensor produces ~22M rows/day.
Native compression, continuous aggregates, and retention policies are exactly
this problem, and hand-rolling them on MariaDB is months of avoidable work.

**Cost.** A third database engine in the estate. Backup, restore, and upgrade
procedures must be written and tested, not assumed.

---

## ADR-003 — Host-side acquisition, never in a container

**Decision.** Acquisition runs as hardened systemd units on the Ubuntu host
under a dedicated `quakevault-acq` account. No serial device is mounted into any
application container.

**Context.** Container restarts must never interrupt acquisition, and passing
`/dev/ttyUSB*` into a container widens the blast radius of a web-tier
compromise to physical hardware.

**Cost.** Two deployment surfaces (host units plus Docker) and a spool/replay
mechanism for the gap between them.

---

## ADR-004 — Profiles are data, not code

**Decision.** Each sensor model is a versioned YAML profile validated by a
pydantic schema. Adding a sensor means adding a profile and fixtures; the
acquisition engine is never edited.

**Context.** WTVB01-485 and HWT901B-485 share exactly one capability
(temperature) - asserted by test. Any code branching on model name would rot
immediately.

**Cost.** Profile schema changes are migrations. Profiles carry a
`profile_version` and every stored sample records the version that decoded it.

---

## ADR-005 — Unverified register maps cannot drive alarms

**Decision.** Profiles carry `verification_status` of `unverified`, `candidate`,
or `verified`. Only `verified` may drive alarms or notifications. WTVB01-485
ships as `unverified`; HWT901B-485 as `candidate`.

**Context.** A wrong register map does not fail loudly - it produces plausible
numbers. Silently guessing an address is how a monitoring product tells a
customer a machine is healthy when it is not.

**Cost.** Hardware commissioning is a gate, not a formality. Promotion to
`verified` requires a probe transcript recorded in `register-maps.md`.

---

## ADR-006 — Advertise 0.4x the measured sample rate, not Nyquist

**Decision.** Spectral features are capped at 0.4x the *measured* rate.
Requests between 0.4x and Nyquist are refused with an explanation; requests above
Nyquist are refused outright. Implemented in `throughput.spectral_verdict()`.

**Context.** Polled Modbus is non-uniformly sampled. Nyquist assumes a uniform
grid; jitter smears spectra and fabricates sidebands that resemble bearing
faults. See `known-limitations.md` for the measured rates.

**Cost.** The product will appear less capable than competitors willing to plot
a spectrum regardless. That is the intended trade.

---

## ADR-007 — Measured rate is a first-class value

**Decision.** Every channel stores both configured and measured effective sample
rate, plus jitter. The UI shows measured. Alarms and spectral gating use
measured.

**Context.** Bus contention, retries, and USB latency mean configured rate is a
request, not a fact.

**Cost.** Extra bookkeeping per poll cycle and a wider sample record.

---

## ADR-008 — Stable device identity from USB topology

**Decision.** Adapter identity derives from physical USB topology via udev
rules, with `/dev/serial/by-id/` as a secondary hint. Stable aliases are created
at `/dev/quakevault-rs485-<adapter-id>`.

**Context.** The CH340 in the field exposes no USB serial number, so two
identical adapters produce colliding by-id names. `/dev/ttyUSB0` renumbers across
reboots and replugs.

**Cost.** udev rules become site-specific when adapters move between ports.
Commissioning must record the topology, and reseating an adapter into a
different port is a configuration change.
