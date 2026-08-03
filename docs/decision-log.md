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

---

## ADR-018 — Reports are evidence, so they must be reproducible

**Decision.** Every report stores its parameters, software version, processing
version, standard-table status, and a SHA-256 of its own content. Regenerating
with the same parameters and processing version yields the same checksum.

**Context.** A vibration report may end up attached to a damage claim or a
dispute with a contractor. A document that cannot be reproduced is an assertion,
not evidence. `PROCESSING_VERSION` is bumped whenever the arithmetic changes, so
an old report is never silently reinterpreted under new rules.

**Cost.** Changing a statistic means bumping the version and accepting that old
and new reports will not match - which is the correct outcome, stated openly.

Two things the report refuses to obscure:

- **Gaps are reported as gaps.** A quiet hour and an hour with the sensor offline
  look identical on a chart. Every report states coverage and gap minutes, and
  the PDF carries a caution when data is missing, because absence of a reading is
  not evidence that the structure was still.
- **Caveats go at the top.** While the guideline tables remain unverified, the
  PDF opens with a box saying so and stating that the document is not a
  compliance assessment. A reader who stops after the first page must still know.

---

## ADR-019 — MQTT is outbound only

**Decision.** The appliance publishes to MQTT and never subscribes for data.
Measurements enter through the authenticated ingestion API alone.

**Context.** MQTT is an integration surface: a broker credential may end up in a
SCADA system, a third-party gateway, or a contractor's laptop. If MQTT could
write to the historical record, any of those becomes a route to forge readings
or alarms. Publishing only means a compromised broker can mislead somebody's
screen but cannot corrupt the evidence.

The broker enforces this too. `allow_anonymous false`, and the ACL grants the
`integration` user read-only on `quakevault/#`, verified by observing that a
publish from that credential is discarded rather than delivered.

**Cost.** Integrations that want to push data must use the ingestion API and hold
an appliance token. That is the intended friction.

Details that matter:

- **Alarms are QoS 1 and not retained.** An integration must not miss an alarm to
  a dropped packet, but a retained one would be replayed to every new subscriber
  and misread as live.
- **Status and health are retained.** A subscriber connecting at any moment
  learns the current state immediately instead of waiting for the next event.
- **A last will is registered.** If the publisher dies, subscribers are told the
  appliance went offline rather than being left with a stale retained `online`.
- **`provisional` travels on the wire.** An integration receives alarms raised
  from unverified thresholds - it is consuming data, not being paged - but
  nothing downstream can mistake one for confirmed.
- **Publishing never breaks monitoring.** Every failure is logged and swallowed:
  an unreachable broker is an integration outage, and the database still holds
  the truth.

## ADR-020: A lossy live feed alongside the durable path, not instead of it

**Status.** Accepted.

**Context.** Shaking the sensor moved the traces about a second later. That
second is not waste, it is the durable path doing its job: decode, write to the
spool, forward in order, insert, and only then let the browser poll for it. The
guarantee is that nothing is lost when the database or the network are down, and
it is the right guarantee for a record. It is the wrong one for somebody standing
at a wall display tapping a structure and watching for a response.

Making the durable path faster was the tempting fix and the wrong one. Every
second shaved off it trades away some of the delivery guarantee.

**Decision.** Measurements take two paths. The durable one is unchanged. A second,
explicitly lossy path publishes each reading to Redis the moment it is decoded;
a bridge relays it onto websockets and the browser merges those frames onto the
end of the stored series.

The live path may drop frames whenever it is convenient to do so. It is a view,
not a record. Anything acted upon - an alarm, a report, a threshold - is read
from the stored series, which is why `LiveMeasurement` carries no alarm state at
all: there is then nothing on that channel that could be mistaken for
authoritative.

**Result.** Median lag from the moment the sensor is read to the frame arriving in
the browser is 8 ms (n=171, p95 12 ms, max 22 ms), against 0.43-1.64 s measured
on the polling path.

**Cost and how it is bounded.**

- **Publishing happens off the acquisition thread.** A blocking network call in
  the poll loop would turn a Redis hiccup into missed polls, and the poll loop is
  the one thing here that must not be delayed.
- **The queue is bounded and drops the newest frame.** When the consumer falls
  behind, the publisher never blocks and never grows without bound. Dropped
  frames are counted and exported.
- **The tee sends to the spool first and lets it raise.** Live sinks run after
  and their failures are swallowed. A dashboard must never be able to stop a
  measurement being recorded; `test_durable_sink_still_receives_when_the_live_sink_fails`
  is the pin.
- **The bridge caps each sensor and group at 15 Hz.** A browser cannot render
  faster, and pushing every frame would spend the client's main thread on work
  nobody can see.
- **The frontend merges only frames newer than the newest stored point**, so a
  live frame and its durable copy cannot both be plotted.

**One deliberate loosening of the sandbox.** `quakevault-acq.service` ran with
`RestrictAddressFamilies=AF_UNIX`, which the live feed hit immediately -
`Address family not supported by protocol`. Notably it failed exactly as designed:
the durable path was untouched, the log said `live view degraded only`, and
nothing was lost. `AF_INET` is now allowed. It is one socket family; the unit
still holds no capabilities and runs under a restricted syscall filter, and
`systemd-analyze security` still rates it 1.6 OK. With the live feed disabled,
`AF_UNIX` alone remains sufficient.

**Two configuration traps, both now pinned by tests.** A blocking `SUBSCRIBE`
sits idle between readings, which the default read timeout treats as a dead
connection; and phpredis applies the key prefix to channel names, so the default
prefix would have subscribed to a channel nobody publishes to. The first crashed
loudly. The second would have looked healthy and delivered nothing, which is
worse, and is why `LiveBridgeTest` asserts both.

**Not addressed.** Reverb runs on port 9080 rather than its default 8080, which
is occupied on this host by an unrelated service. The frontend reads it from
`frontend/.env` at build time, so changing it requires a rebuild.

## ADR-021: Vibration magnitudes are unsigned, and a bench cannot prove a decode

**Status.** Accepted. Profile 1.0.0 -> 1.1.0.

**What happened.** Three seconds of hand-shaking produced velocity readings of
-295 mm/s and displacements of -12387 um on registers declared non-negative.
The quality gate flagged them, which is what it is for, but the flag was
treating good data as bad and hiding a decode fault underneath.

The raw words settled it. Two adjacent samples of a smoothly rising magnitude:

    raw 31932 -> +319.32 mm/s
    raw 33530 -> -320.06 mm/s

A 640 mm/s reversal between consecutive readings is not physics. Velocity
(0x3A-0x3C) and displacement (0x41-0x43) are unsigned magnitudes and were being
decoded as signed, so everything above 32767 counts came back inverted.

**Why the verification gate missed it.** The gate checked 585 samples against the
manufacturer's table and against physical plausibility, and it was right about
every one. A stationary sensor never exceeds 32767 counts on these registers, so
the whole bench campaign exercised only the half of the range where signed and
unsigned agree. The decode was correct everywhere it was tested and wrong
exactly in the regime the instrument exists to measure.

The lesson is not "test harder". It is that a register's *type* cannot be
inferred from data that never reaches the boundary, and the profile schema
defaults to int16 - so every magnitude register silently inherited the wrong
type unless explicitly told otherwise. Three did.

**Also wrong: the plausibility bound.** Velocity was capped at 120 mm/s, under
what the hardware emits. 113 genuine readings between 120 and 320 mm/s were
marked implausible, and because the spectrum endpoint reads only `good` rows,
the loudest part of a real event was the part being discarded. The maximum is
now the full representable range. A bound that rejects real large events is
worse than a loose one; the bound exists to catch a wrong register map, not to
enforce a spec sheet.

**The history was recoverable.** 143 inverted readings were recomputed from the
raw register words stored beside every measurement, and 113 were re-flagged. That
is what storing raw registers buys: a decode can be found wrong months later, and
without them the only correct response would have been to discard the affected
record. The rows still carry `profile_version: 1.0.0`, which is accurate - that
is the version they were captured under, and an audit should be able to see it.

**Still open.** The 36 condition indicators (0x47-0x6A) are decoded as signed and
have not been re-examined. Some must be signed - skewness is negative for a
left-tailed distribution - and others cannot be, such as RMS and absolute
average. They sit near zero on this bench and carry exactly the latent risk
velocity did.

## ADR-022: A transient is an event, not a spectrum

**Status.** Accepted.

**Context.** With the shake data recovered, the periodogram reported a peak at
0.026 Hz over a 15-minute window with a false-alarm probability of zero -
statistically overwhelming, and meaningless. A periodogram assumes the signal is
stationary. A three-second tap inside a fifteen-minute record is not, and an
isolated burst correlates best with a wave slow enough that the window holds one
half-cycle of it. The "finding" described the window length.

This is the same class of error as the drift peak in ADR-020's page: a method
applied outside its assumptions returns a confident number rather than an error.

**Decision.** The window is split into ten blocks and the share of energy in the
busiest one is measured. Stationary content sits near 0.1; half or more in a
single block means the record holds an event rather than sustained vibration.
When that fires, the spectrum is still drawn - the energy is real and worth
seeing - but no component is reported and the UI says why, in the terms an
operator can act on: narrow the window to the event.

Measured on the real shake: 98% concentration over 15 minutes, 54% over one
minute. Both correctly refused.

**Cost.** A genuinely impulsive structure excited repeatedly could be called
transient when a spectrum would have been informative. Refusing to answer is the
right failure here: the alternative is a number that looks like a resonance and
is an artefact of the window.
