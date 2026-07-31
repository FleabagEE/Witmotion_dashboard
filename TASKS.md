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
| HWT901B-485 profile | done | Controls | schema | Loads, scaling asserted | `hwt901b-485.v1.yaml` |
| Modbus CRC-16 | done | Backend | — | Published vector 0x0A84 | `crc.py` |
| Register decoder | done | Backend | — | 11 unit tests | `decode.py` |
| Bus throughput model | done | Controls | — | Hand-calc cross-check | `throughput.py` |
| Read-only probe tool | done | Controls | decoder | Compiles; API verified | `tools/probe.py` |
| Normalised measurement schema | todo | Architect | capability schema | JSON-schema validation | — |
| MQTT topic + payload schema | todo | Architect | measurement schema | Schema tests | — |
| Ingestion API contract | todo | Backend | measurement schema | OpenAPI + contract tests | — |
| Database design (hypertables) | todo | Backend | measurement schema | Migration + volume test | — |

## Phase 3 — Hardware foundation

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Create `quakevault-acq` service account | blocked | DevSecOps | root access | Account exists, in `dialout` | Needs operator |
| Verify register maps on hardware | blocked | Controls | dialout, probe | Transcript in register-maps.md | Gate for alarms |
| udev rules + stable adapter identity | todo | DevSecOps | service account | Survives replug and reboot | — |
| Modbus RTU simulator (both sensors) | todo | Controls | profiles | Acquisition runs with no hardware | — |
| Async acquisition engine | todo | Controls | simulator | Soak against simulator | — |
| Local spool + replay | todo | Backend | engine | Kill -9 mid-write, replay intact | — |
| Discovery + confidence scoring | todo | Controls | engine | Duplicate-ID detection test | — |
| systemd units (hardened) | todo | DevSecOps | engine | `systemd-analyze security` | — |

## Phase 4 — Backend

| Task | Status | Owner role | Depends on | Validation | Result |
|---|---|---|---|---|---|
| Laravel skeleton + Sanctum | todo | Backend | DB design | Auth feature tests | — |
| Ingestion endpoint (idempotent) | todo | Backend | API contract | Replay-safety tests | — |
| TimescaleDB migrations | todo | Backend | DB design | Retention + aggregate tests | — |
| Alarm engine | todo | Backend | ingestion | Hysteresis/debounce tests | — |
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
| 3D orientation (HWT901B) | todo | Frontend | quaternion channels | Visual + axis-convention check | — |
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

1. `sudo usermod -aG dialout quakelogic` (or create `quakevault-acq`) — nothing
   can open `/dev/ttyUSB0` until this lands. Blocks register verification, which
   in turn blocks every alarm path.
2. Confirm whether an HWT901B-485 is physically available for commissioning.
