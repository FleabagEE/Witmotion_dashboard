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

**Context.** The two profiles built so far overlap on only acceleration and
temperature - asserted by test. Any code branching on model name would rot
immediately. See ADR-009 for why the second profile is retained as a fixture even
though its sensor was retired.

**Cost.** Profile schema changes are migrations. Profiles carry a
`profile_version` and every stored sample records the version that decoded it.

---

## ADR-005 — Unverified register maps cannot drive alarms

**Decision.** Profiles carry `verification_status` of `unverified`, `candidate`,
or `verified`. Only `verified` may drive alarms or notifications. Both profiles
began as unverified; WTVB01-485 was promoted on 2026-07-31 after its map was
transcribed from the manufacturer table and confirmed on hardware.

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

---

## ADR-009 — HWT901B-485 retired from the product

**Decision.** Ship support for the WTVB01-485 only. The HWT901B-485 is removed
from `profiles/` and kept solely as a test fixture.

**Context.** The WTVB01 turned out to cover everything the appliance needs. Its
verified map provides triaxial acceleration, vibration velocity, displacement
and dominant frequency, temperature, and 36 on-device condition indicators. The
HWT901B would have added attitude, magnetic field and quaternion - useful for an
inclinometer product, but not for condition monitoring - at the cost of a second
register map to verify and maintain.

**Cost.** The capability model now has one production profile, which is exactly
when a "profile-driven" architecture quietly rots into a single-sensor one. The
retired profile therefore stays in `acquisition/tests/fixtures/`: its
capabilities barely overlap the WTVB01's, so the tests that use it fail loudly if
anything starts assuming one sensor model. Deleting it would leave the capability
model untested until the next sensor is added - precisely when a regression would
cost the most.

---

## ADR-010 — Measurement identity includes a run id

**Decision.** The idempotency key is
`appliance:run_id:sensor:group:sequence`. A run id is minted once per continuous
acquisition run and carried on every measurement.

**Context.** Sequence numbers restart at 1 whenever the service restarts. With a
key of `appliance:sensor:group:sequence`, the first measurements after every
restart collided with the previous run's and were rejected by the spool's UNIQUE
constraint as duplicates. This was not theoretical: the live spool had already
lost **98 real measurements** across three restarts, counted under
`duplicates_rejected` and otherwise invisible.

Alternatives considered. Persisting the last sequence per (sensor, group) and
resuming from it would work, but adds startup state that can itself be lost or
corrupted, and a wrong resume silently overwrites history. A timestamp-based key
is not stable under replay. A run id is stateless, survives crashes, and keeps
deduplication exact inside a run - which is what replay safety actually needs.

**Cost.** Measurements from different runs can no longer be deduplicated against
each other. That is correct: they are genuinely different measurements. Anything
downstream that wants "the latest reading per channel" must order by timestamp,
not assume sequence numbers are globally comparable.

---

## ADR-011 — Application tier in Docker, one database, Redis on 6380

**Decision.** TimescaleDB and Redis run under Docker Compose, published on
loopback only. Acquisition stays on the host (ADR-003).

**Context.** The host already runs a Redis on 6379 belonging to another product.
Sharing it would couple this appliance's queues and cache to software this
project does not control, so ours is published on 6380 instead.

**Cost.** Two Redis instances on one development machine. On a real appliance
there is only one, and the port is configuration.

---

## ADR-012 — ISO 10816 limits live on the asset, and are defaults not baselines

**Decision.** The machine class sits on the asset. Alarm definitions derived from
it record `source: iso10816` (or `iso10816_inferred` when the class was guessed
from rated power), and every derived definition says so in its description.

**Context.** The standard's zone boundaries depend on machine size and mounting
stiffness, not on the sensor. A sensor moved from a 5 kW pump to a 200 kW
compressor must inherit different limits without being reconfigured. Mapping is
advisory at the A/B boundary, warning at B/C, critical at C/D - a machine in
zone B is fine, so advisory means "no longer as-new", not "something is wrong".

**Cost.** These are class defaults, not a baseline measured on the specific
machine, and they will be wrong for unusual installations. Where a real baseline
exists, deviation-from-baseline is the better rule. Rated power alone cannot
determine mounting stiffness, so an inferred class is labelled as inferred rather
than presented as fact.

---

## ADR-013 — Alarm state machine: persistence, hysteresis, debounce, latching

**Decision.** Every alarm definition carries hysteresis, separate raise and clear
persistence windows, a debounce interval, and a latching flag.

**Context.** Deciding that 8.2 exceeds 7.1 is the easy part. Without hysteresis a
value resting on a boundary raises and clears endlessly; without persistence a
single noisy sample pages somebody at 03:00; without debounce a flapping input
produces a transition storm; without latching a transient excursion disappears
before anyone sees it. Raise and clear budgets are separate because operators
generally want a fast raise and a slow clear.

**Cost.** More state per alarm, and a genuinely more complex evaluator. The
candidate level and its timestamp are stored so a partially satisfied condition
survives a restart rather than resetting its countdown.

A failed read never alarms. A null value with 'bad' quality is not a low
reading - nothing was measured. Sensor liveness is a separate condition type, so
that a dead sensor reports as dead rather than as a healthy machine.

---

## ADR-014 — Structural monitoring, not machine condition monitoring

**Decision.** The deployment target is structural: sensors mounted on buildings
and walls. ISO 10816 is therefore the wrong standard, and assets declare a
`monitoring_domain` of `structural` or `machinery` rather than the code assuming
one. Structural limits come from DIN 4150-3 / BS 7385-2.

**Context.** Confirmed by the operator on 2026-07-31 after the first sensor was
mounted on a wall. The two families answer different questions and are not
interchangeable: ISO 10816 asks whether a machine is healthy, using RMS velocity
graded by machine class; DIN 4150-3 asks whether a building will be damaged,
using peak particle velocity graded by structure type and, crucially, by
frequency. The same velocity is far more damaging at 3 Hz than at 80 Hz, so a
single threshold cannot express the standard.

The ISO 10816 support is kept, not deleted: it is correct for machinery, and the
appliance is meant to serve both.

**Cost.** Three things now need answering that machine monitoring did not raise,
recorded in `known-limitations.md`:

1. **The standards require all three axes.** Both evaluate the largest of three
   orthogonal components. The confirmed WTVB01-485 defect leaves velocity on the
   X axis only, so a compliant assessment cannot be produced with this hardware
   as it stands. This turns the defect from an inconvenience into a blocker for
   the stated use case.
2. **PPV is a peak, not an average.** The WTVB01 reports an aggregated velocity
   whose relationship to peak is not documented. Converting between them needs an
   assumption about waveform shape that transient events - blasting, piling,
   passing traffic - violate precisely when the measurement matters most.
3. **Damage limits are not complaint limits.** These values are cosmetic-damage
   guidelines. Occupants perceive vibration far below them, so a building can be
   generating complaints while every alarm reads green. Human response is a
   different standard again (BS 6472 / ISO 2631).

The transcribed tables ship as `candidate`, never `verified`, until checked
against the standard text - the same gate the register maps pass through, for the
same reason.

---

## ADR-015 — Thresholds must be confirmed by a named person before they notify

**Decision.** Alarm definitions carry `thresholds_confirmed_at`,
`thresholds_confirmed_by` and `thresholds_reference`. Until a named person
records that they checked the numbers against a real source, alarms raised from
those thresholds are marked `provisional`: shown on the dashboard, never sent to
anybody. An event keeps the status it was raised under, so confirming later does
not retroactively make a past alarm look authoritative.

**Context.** DIN 4150-3 and BS 7385-2 are copyrighted documents that must be
purchased, and this project does not hold them. The shipped guideline values are
transcribed from working knowledge and cannot be verified against the source.
That is not a reason to ship them silently as though they were authoritative:
mistaking `sensitive` for `commercial` would be a factor of seven at low
frequency, and a listed building would sit unmonitored until it was seven times
past the damage threshold.

The register-map gate (ADR-005) already caught two errors of exactly this kind on
this project. The same discipline applies to numbers that decide whether a
building is at risk.

Liveness thresholds are exempt and self-confirm, because they derive from this
appliance's own poll configuration rather than an external document. There is
nothing for a human to check them against.

**Cost.** A fresh install raises no actionable alarms until somebody signs off.
That is the intended behaviour: it forces the question of who owns the numbers to
be answered before the product is relied upon, rather than after an incident.
`alarms:confirm-thresholds --list` shows exactly what is outstanding.

---

## ADR-016 — The audit trail is append-only in the database, not by convention

**Decision.** `audit_events` carries a `BEFORE UPDATE OR DELETE` trigger that
raises. There is no Eloquent model for it, so there is no `->update()` or
`->delete()` for anybody to reach for either.

**Context.** An audit trail that the application can edit is not evidence. A bug,
a careless migration or a compromised web account could otherwise rewrite history
silently. Removing the log wholesale still requires DDL rights, which the
application database user should not hold in production.

Actor name and role are denormalised onto each row, so a record stays readable
after the account is renamed or deleted.

**Cost.** Audit rows cannot be corrected, only superseded. That is the intended
property.

---

## ADR-017 — Notification value is in what it refuses to send

**Decision.** Every notification passes four gates - provisional status, severity
floor, deduplication, rate ceiling - plus quiet hours, and every suppression is
recorded with its reason.

**Context.** A system that floods people is worse than one that stays silent: a
flooded channel gets muted, and then the message that mattered is missed too. So
a repeat of the same condition is dropped, a flapping input cannot exceed the
hourly ceiling, and quiet hours hold everything except critical - because a
critical alarm at 3am is exactly what somebody signed up for.

Alarms raised from unconfirmed thresholds never notify at all (ADR-015). They
appear on the dashboard; that is the extent of what unverified numbers have
earned.

Notification fires on escalation only. Telling somebody an alarm became less
severe, or cleared itself, is noise the dashboard already shows.

**Cost.** Suppression means somebody can ask "why was I not told". Every
suppressed delivery is therefore stored with its reason rather than discarded, so
that question always has an answer.

Escalation targets are marked `escalation_only` and excluded from first dispatch.
Without that, escalating merely tells the same person twice.
