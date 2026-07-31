# TASKS

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

| MQTT topic + payload schema | todo | Architect | measurement schema | Schema tests | — |
| Ingestion API contract | todo | Backend | measurement schema | OpenAPI + contract tests | — |
| Database design (hypertables) | done | Backend | measurement schema | 7 migrations applied, policies verified | TimescaleDB 2.29/pg18 |

## Phase 3 — Hardware foundation

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Create `quakevault-acq` service account | done | DevSecOps | root access | Account exists, opens port | uid 995, dialout only |
| udev stable adapter naming | done | DevSecOps | service account | Alias resolves after trigger | `/dev/quakevault-rs485-a` |
| Verify WTVB01-485 register map | done | Controls | dialout, probe, manual | Manual V260508 + hardware, 20 fixtures | **verified** |
| Resolve VY/VZ/DY/DZ reading zero | done | Controls | second unit | Two units compared, 140 s each | Unit 1 faulty; model sound |
| Three-axis check in commissioning | todo | QA | acceptance | Confirm all axes respond before trust | A dead axis reads as a still building |
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
| Human users + roles | todo | Backend | skeleton | Auth feature tests | — |
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
| Notifications | todo | Backend | alarms | Delivery-status tests | — |
| Reports (PDF/CSV) | todo | Backend | alarms | Reproducibility test | — |
| Audit trail | todo | DevSecOps | auth | Coverage test | — |

## Phase 5 — Frontend

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Design system | todo | UI/UX | — | Contrast + a11y audit | — |
| Overview | todo | Frontend | API | Component tests | — |
| Live Monitor | todo | Frontend | API | Smoothness at target rate | — |
| Signal Analysis (gated) | todo | Signal | throughput model | Refuses out-of-band requests | — |
| Alarm Center | todo | Frontend | alarms | Workflow tests | — |
| Kiosk mode | todo | Frontend | auth roles | Survives reboot | — |

## Phase 6 — Hardening and validation

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Fault injection | todo | QA | Phase 3–5 | 20-case HIL matrix | — |
| 24-hour soak | todo | QA | engine | No unbounded growth | — |
| Backup / restore | todo | DevSecOps | DB | Restore drill | — |
| Upgrade / rollback | todo | DevSecOps | deploy | Rollback drill | — |
| Acceptance report | todo | QA | all | Criteria signed off | — |

## Blocked — operator action required

1. Return the faulty first unit under warranty (RMA text drafted at
   ~/Downloads/WTVB01-485-RMA-request.txt).
2. Supply DIN 4150-3 and/or BS 7385-2 standard text so the guideline tables can
   be promoted from candidate to verified.
3. Confirm structure class, measurement position, and whether the concern is
   transient (blasting, piling) or long-term (traffic, settlement).
