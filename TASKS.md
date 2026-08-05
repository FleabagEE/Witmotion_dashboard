# TASKS

*Last reconciled against the running system: 2026-08-03.*

Status: `todo` | `in progress` | `blocked` | `done`

## Phase 1 — Repository assessment

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Inventory existing QuakeVault-SHM / HDB codebases | done | Architect | — | Manual review | Neither matches target stack; see ADR-001 |
| Decide repository target | done | Architect | assessment | Operator decision | Standalone repo |
| Decide database engine | done | Architect | assessment | Operator decision | PostgreSQL + TimescaleDB (ADR-002) |
| Inventory host hardware | done | Controls | — | `lsusb`, `/dev/serial/by-id` | CH340 `1a86:7523` on `/dev/ttyUSB0` |

## Phase 2 — Architecture and contracts

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Sensor capability schema | done | Architect | — | pydantic + 12 unit tests | `profiles/schema.py` |
| WTVB01-485 profile | done | Controls | schema | Loads, marked unverified | `wtvb01-485.v1.yaml` |
| HWT901B-485 profile | retired | Controls | — | Moved to tests/fixtures (ADR-009) | Not shipped |
| Modbus CRC-16 | done | Backend | — | Published vector 0x0A84 | `crc.py` |
| Register decoder | done | Backend | — | 11 unit tests | `decode.py` |
| Bus throughput model | done | Controls | — | Hand-calc cross-check | `throughput.py` |
| Read-only probe tool | done | Controls | decoder | Compiles; API verified | `tools/probe.py` |
| Register encoder (inverse of decoder) | done | Backend | decoder | 8 round-trip tests | `encode.py` |
| Modbus RTU simulator (both sensors) | done | Controls | profiles, encoder | 18 tests, end-to-end over pty | `simulator/` |
| Profile-driven reader + quality flags | done | Backend | decoder | End-to-end vs simulator | `client.py` |

| MQTT publication | done | Architect | ingestion | 10 tests + live broker round-trip | `MqttPublisher` |
| Ingestion API contract | **done** | Backend | measurement schema | OpenAPI 3.1 verified against the app both ways | `backend/openapi.yaml`, `ContractTest` |
| Database design (hypertables) | done | Backend | measurement schema | 7 migrations applied, policies verified | TimescaleDB 2.29/pg18 |

## Phase 3 — Hardware foundation

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Create `quakevault-acq` service account | done | DevSecOps | root access | Account exists, opens port | uid 995, dialout only |
| udev stable adapter naming | done | DevSecOps | service account | Alias resolves after trigger | `/dev/quakevault-rs485-a` |
| Verify WTVB01-485 register map | done | Controls | dialout, probe, manual | Manual V260508 + hardware, 20 fixtures | **verified** |
| Resolve VY/VZ/DY/DZ reading zero | done | Controls | second unit | Two units compared, 140 s each | Unit 1 faulty; model sound |
| Three-axis check in commissioning | **done** | QA | acceptance | Comparative: excited above 0.05 g and silent = fault | `sensors:check-axes`, `docs/rma-evidence.md` |
| Model 0x47-0x6A statistical features | todo | Signal | manual 10.4.11-10.4.17 | Fixtures per channel | — |

| CRC-level fault injection in simulator | done | QA | simulator | Corrupt frames rejected, not decoded | `rtu.py` |
| Async acquisition engine | done | Controls | simulator | 18 tests + live hardware | `engine.py` |
| Exclusive port ownership | done | Controls | — | Advisory lock, 3 tests | `portlock.py` |
| Unified measurement model | done | Architect | capability schema | Schema-versioned envelope | `measurement.py` |
| Local spool + replay | done | Backend | engine | SIGKILL mid-write + live outage drill | `spool.py` |
| Discovery + confidence scoring | done | Controls | engine | 21 tests + live hardware | `discovery.py` |
| systemd units (hardened) | done | DevSecOps | engine | systemd-analyze security 1.3 OK | `deploy/systemd/` |
| Service entrypoint + config | done | Backend | engine, spool | 21 tests, live under systemd | `service.py`, `config.py` |
| Prometheus metrics | done | DevSecOps | engine | Textfile collector, atomic write | `metrics.py` |
| Installer (idempotent) | done | DevSecOps | units | Re-run on live host, non-destructive | `deploy/install-acquisition.sh` |

## Phase 4 — Backend

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Laravel skeleton | done | Backend | DB design | Connects to pg18, migrates clean | Laravel 13.23 |
| Human users + roles | done | Backend | skeleton | 24 API tests, role separation | `Roles`, `user:create` |
| Dashboard read API | done | Backend | DB design | Overview, sensors, series, alarms | `ReadController` |
| Ingestion endpoint (idempotent) | done | Backend | API contract | 16 feature tests + live e2e | `IngestService` |
| Spool forwarder | done | Backend | ingestion | 14 tests + live e2e | `forwarder.py` |
| Forwarder systemd unit | done | DevSecOps | forwarder | Runs under systemd, backlog 0 sustained | `quakevault-forwarder.service` |
| Sanctum appliance tokens | done | DevSecOps | skeleton | ingest-only ability enforced | `appliance:token` |
| TimescaleDB policies | done | Backend | DB design | Compression, retention, rollup registered | verified in catalog |
| Alarm engine | done | Backend | ingestion | 22 tests: hysteresis, persistence, latching | `AlarmEvaluator` |
| Sensor liveness alarms | done | Backend | alarm engine | 7 tests + live failure drill | `alarms:sweep` |
| Alarm evaluation on ingest | done | Backend | alarm engine | Wired outside the ingest transaction | `IngestService` |
| Structural standards (DIN/BS) | done | Signal | alarm engine | 15 tests, frequency-interpolated | `StructuralVibration` |
| Full class/position/duration matrix | done | Signal | standards | Every valid combination provisionable | 36 alarm tests |
| Verify DIN 4150-3 / BS 7385-2 tables | blocked | Signal | standard text | Promote candidate -> verified | Needs standard |
| Rate-of-change and baseline alarms | todo | Signal | alarm engine | Needs occupied-building baseline | — |
| Notifications | done | Backend | alarms | 17 tests: dedupe, quiet hours, escalation | `NotificationDispatcher` |
| Reports (PDF/CSV) | done | Backend | alarms | 14 tests incl. reproducible checksum | `ReportGenerator` |
| Audit trail | done | DevSecOps | auth | Append-only, enforced by DB trigger | `AuditLogger` |

## Phase 5 — Frontend

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Design system | done | UI/UX | — | Severity never colour-alone; tabular figures | `components/ui.tsx` |
| Overview | done | Frontend | API | Verified live in browser | `pages/Overview.tsx` |
| Live Monitor | done | Frontend | API | Six waveform cards, verified in browser | `pages/Live.tsx` |
| Signal Analysis (gated) | **done** | Signal | throughput model | Refuses out-of-band requests, transients and drift | `pages/Signal.tsx`, `SpectrumAnalyzer.php` |
| Alarm Center | done | Frontend | alarms | Acknowledge gated by ability | `pages/Alarms.tsx` |
| Kiosk mode | **done** | Frontend | auth roles | Role-gated, staleness stated, systemd unit | `pages/Kiosk.tsx`, `quakevault-kiosk.service` |
| Frontend test suite | **done** | Frontend | — | 33 vitest tests on the behaviours that had bugs | `npm test` |

## Phase 6 — Hardening and validation

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Fault injection | **done** | QA | Phase 3–5 | 14/20 pass, 1 partial, 4 need hardware | `acceptance/fault-injection.sh` |
| 24-hour soak | **done** | QA | engine | Memory +0.6%, backlog drains, zero drops. Poll rate 16% under configured — explained, see below | `docs/acceptance-results.md` |
| Backup / restore | **done** | DevSecOps | DB | Restore verified into a scratch DB | `acceptance/backup-restore.sh` |
| Upgrade / rollback | **done** | DevSecOps | deploy | Real migration applied then reverted | `deploy/upgrade.sh` |
| Acceptance report | **done** | QA | all | 20-case matrix recorded with evidence | `docs/acceptance-results.md` |
| Documentation set | **done** | Tech writer | all | Operator, admin, troubleshooting, API, MQTT, testing | `docs/`, `README.md` |

## Phase 7 — Settlement deployment and enterprise dashboard (2026-08-04/05)

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Pivot to tilt-only acquisition | **done** | Engine | Phase 3 | 1 Hz, bus 60.5% → 7.0%, storage 16.3M → 1.7M rows/day | `deploy/acquisition.tilt-only.yaml` |
| Whole-bus capacity check | **done** | Engine | throughput | `--check` refuses over 65%; predicts 8.5 Hz against 8.38 measured | `bus_demand()`, 6 tests |
| Three-position calibration | **done** | Hardware | unsigned finding | Gravity 0.9626 → 0.9995 g | `deploy/calibration.SENSOR-001.yaml` |
| Wire calibration into acquisition | **done** | Engine | above | It was never loaded; `to_binding()` now takes it | 4 tests |
| Install the scheduler | **done** | DevSecOps | — | `tilt:check` had never run once | `quakevault-scheduler.timer`, acceptance 13/14 |
| Fix the settlement alarm | **done** | Backend | — | Could never fire: synthetic channel filtered on quantity | `TiltAlarmTest` |
| Gravity-vector deviation | **done** | Backend | unsigned finding | Magnitude survives rectification; direction cannot | `TiltMonitorTest` |
| Enterprise roles and thresholds | **done** | Backend | Roles | Operator and engineer both 403; changes audited; edits clear sign-off | `AdministrationTest`, 17 tests |
| User management API and page | **done** | Full stack | above | Last administrator cannot be removed; sessions revoked | `UserController`, `Users.tsx` |
| Event history | **done** | Full stack | audit | Alarms and audit interleaved, gated on `audit` | `EventController`, 7 tests |
| On-screen alarm banner | **done** | Frontend | alarms | Every page; says when nobody was notified | `AlarmBanner.tsx`, 6 tests |
| Email notification | **done** | DevSecOps | dispatcher | Gmail SMTP; proven by a real tilt alarm reaching the inbox | `alarms:channel`, `alarms:selftest` |
| Live charts carry their limits | **done** | Frontend | thresholds | Looked up by quantity, not copied | `WaveformCard` |
| Vibration definitions | **recording** | Backend | — | Placeholders, unconfirmed, deliberately silent | `alarms:vibration-survey` |

### Six silent failures found in this phase

Each would have left the appliance looking healthy while not working. Recorded
because the pattern matters more than the individual bugs.

| Failure | Why nothing noticed |
|---|---|
| Alarm filtered out before evaluation | Definition, command and deviation all correct; the route between them had no test |
| Scheduler never installed | Both alarm tests bypass it. Only tilting the sensor by hand exposed it |
| Calibration never loaded | File format, apply step, solver and CLI all existed and were tested |
| Bus capacity never summed | `estimate()` answered for one group; nothing added up the five configured |
| Acceleration reported unsigned | Every reading plausible; direction silently unmeasurable |
| Chart scaled by its own artifacts | Every plotted point accurate, the picture useless |

## Blocked — hardware not available

| Case | Needs |
|---|---|
| Mid-vs-top differential | A second WTVB01-485 at Modbus 0x51, mounted. Design settled: common mode is foundation rotation, differential is shell bending, and magnitude alone separates them |
| Commissioning baseline | Both sensors mounted on the silo. The current baseline is a bench reading |
| HIL 2 — one HWT901B-485 | An HWT901B-485. Ordered, never arrived. **No longer on the critical path** — magnitude-only monitoring is sufficient for the stated goal |
| HIL 3 — both types, separate adapters | As above |
| HIL 4 — both types, one multi-drop bus | As above |
| HIL 5 — multiple sensors on one bus | A second *working* WTVB01-485. The spare is faulty |

The multi-sensor code paths are exercised by the simulator, which runs several
slave IDs on one bus. That is not the same as proving it against real hardware
and is deliberately not recorded as if it were.

## Blocked — operator action required

1. Return the faulty spare unit under warranty. **The evidence was corrected on
   2026-08-03** - see `docs/rma-evidence.md`. The original argument (a frequency
   reported while velocity read zero) does not hold and should not be sent; the
   claim now rests on Z being excited 125 times without ever reporting velocity
   while X responded under weaker excitation in the same session.
2. Supply DIN 4150-3 and/or BS 7385-2 standard text so the guideline tables can
   be promoted from candidate to verified.
3. ~~Confirm structure class and measurement position~~ — **answered
   2026-08-04.** Long-term settlement of a 30 ft concrete silo; two sensors
   stacked plumb-aligned on one face, mid-height and top; outdoor IP67.

   Four definitions now exist. Tilt movement (0.5 / 3 deg) and sensor liveness
   are confirmed and live. Vibration acceleration and velocity are recording and
   deliberately unconfirmed until `alarms:vibration-survey` supplies real
   numbers.

   **Open question for the structural engineer.** 3 deg on a 30 ft silo is
   479 mm of top movement, roughly H/19, against serviceability criteria that
   are usually H/250 to H/500. It is confirmed in his name and stands as his
   decision. The warning level at 0.5 deg was not specifically chosen by anyone
   and is where an earlier settlement indication would belong.
4. ~~Six-position accelerometer calibration~~ — **superseded 2026-08-05.**
   Six-position is impossible on this model: acceleration is reported as
   unsigned magnitude, so z-up and z-down return the same vector and the
   ellipsoid fit has nothing to solve. A three-position procedure was used
   instead and **is applied** — per-axis gains 0.99756 / 1.00586 / 0.96240,
   gravity now reads 0.9995 g against 0.9626 before.

   The earlier claim that it was "worth ~2 degrees of inclination accuracy" was
   wrong. A gain error cancels when the vector is normalised, so it does not
   affect a tilt angle at all. What it fixes is the magnitude, which is the best
   single indicator that a sensor is healthy.

## Not covered by tests

Narrowed 2026-08-05. The four pages carrying judgement — settlement figures,
thresholds, accounts and history — now have tests, written against the
behaviours that would fail quietly rather than against layout.

| Page | Covered |
|---|---|
| Tilt (settlement) | 8 — readings survive a missing baseline, figures reconcile, discarded minutes stated, direction limitation stated |
| Thresholds | 9 — operator and engineer cannot edit, reason required, sign-off visibly stripped |
| Users | 9 — sole-administrator warning, password reset signs out everywhere, server refusals surfaced |
| Events | 10 — audit absence stated rather than silent, only changed fields shown |
| Alarms, AlarmBanner, WaveformCard | 19 |

Still hand-verified only:

- The Signal page, the login flow, and routing between pages.
- `Overview`, `SensorDetail`, `Live` and `Kiosk`.
- Nothing visual: no screenshot or layout testing.

Every page test above was checked by reintroducing the bug it was written for
and confirming it fails. A test that passes against broken code is worse than no
test, and this project has produced three mechanisms that were fully tested and
never invoked.
