# Administrator manual

For the person who installs, upgrades and keeps the appliance running.

---

## What runs where

| Component | Where | Unit |
|---|---|---|
| Acquisition (Python) | systemd | `quakevault-acq` |
| Spool forwarder | systemd | `quakevault-forwarder` |
| WebSocket server (Reverb) | systemd, port **9080** | `quakevault-reverb` |
| Live bridge (Redis → WS) | systemd | `quakevault-live-bridge` |
| Wall display | systemd, optional | `quakevault-kiosk` |
| TimescaleDB | Docker, port 5432 | `qv-timescaledb` |
| Redis | Docker, port **6380** | `qv-redis` |
| Mosquitto | Docker, port **1884** | `qv-mosquitto` |

Ports 6379, 1883 and 8080 are occupied on this host by unrelated products. The
non-default ports are deliberate, not accidental.

```bash
systemctl is-active quakevault-acq quakevault-forwarder quakevault-reverb quakevault-live-bridge
```

```bash
docker ps --format '{{.Names}}: {{.Status}}' | grep qv-
```

---

## Install

```bash
docker compose up -d
```

```bash
sudo ./deploy/install-acquisition.sh
```

The installer creates the service user, installs the units and udev rules, and
installs the Python package editable so the operator tools resolve. Without that
last step only the units can run anything — every `qv-*` command fails with
`ModuleNotFoundError`.

Validate configuration before starting:

```bash
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-acq --check --config /etc/quakevault/acquisition.yaml
```

**The service refuses to start on a sensor whose profile is not `verified`.** An
unconfirmed register map cannot reach production by accident.

---

## Configuration

| File | Holds |
|---|---|
| `/etc/quakevault/acquisition.yaml` | Buses, sensors, poll rates, spool sizing. World-readable |
| `/etc/quakevault/forwarder.env` | The ingest token. **Mode 0600** — never in the YAML |
| `/etc/quakevault/calibration.yaml` | Per-unit accelerometer correction. Absent = identity |
| `backend/.env` | Database, Redis, Reverb, MQTT |
| `frontend/.env` | `VITE_REVERB_*`. **Inlined at build time — rebuild after changing** |
| `profiles/*.yaml` | Register maps and scale factors |

### Poll rates

```yaml
poll_hz:
  motion: 10          # one 19-register transaction, 16 channels
  condition_x: 0.33
  fault_diagnosis: 0.1
```

Rates must fit the bus. Check after any change:

```bash
sudo grep bus_utilisation /var/lib/quakevault-acq/metrics.prom
```

Keep it under about 0.8. Currently 0.649 at ~9.4 Hz. The capacity model is
`acquisition/src/qv_acq/throughput.py`; Modbus overhead is per *transaction*, so
merging groups is usually cheaper than raising a rate.

---

## Users and roles

```bash
cd backend && php artisan tinker --execute="
\$u = new App\Models\User;
\$u->name='…'; \$u->email='…'; \$u->role='engineer'; \$u->active=true;
\$u->password=bcrypt('…'); \$u->save();"
```

Roles: `kiosk`, `viewer`, `operator`, `engineer`, `auditor`, `administrator`.
Abilities are in `app/Support/Roles.php`. A kiosk carries `read` only — a screen
in a corridor cannot acknowledge an alarm.

---

## Turning alarm notifications on

Structural alarms are `provisional` until somebody with the published standard
confirms the tables. Provisional alarms display and never notify.

```bash
php artisan alarms:confirm-thresholds
```

It requires a name and a reference. Nobody can enable notifications anonymously,
and the audit trail records who did.

Structural definitions also need the asset described: which standard
(DIN 4150-3 or BS 7385-2), structure class, and whether the sensor is on the
foundation or an upper floor. Those change the limits several-fold.

---

## Backup

```bash
./acceptance/backup-restore.sh
```

Backs up **and verifies the restore** into a scratch database. An untested backup
is a belief, not a backup.

Default excludes raw measurements — they grow 4.4 GB/day and are evidence with
their own retention, not what rebuilds an appliance. `BACKUP_DAYS=1` includes a
day; `BACKUP_DAYS=all` everything, slowly.

Written to `/var/backups/quakevault/`, with SHA-256 checksums.

---

## Upgrade and rollback

```bash
./deploy/upgrade.sh <git-ref>
```

```bash
./deploy/upgrade.sh --rollback
```

```bash
./deploy/upgrade.sh --status
```

**Acquisition does not stop.** The spool covers 10.1 hours, so an upgrade is a
planned outage of exactly the things it exists to survive. Measured across a real
upgrade: 23,056 readings recorded during it.

Pre-flight refuses to upgrade an unhealthy appliance — otherwise nothing can
distinguish what the upgrade broke from what was already broken. Any failing step
rolls back automatically, migrations first and then the checkout.

On a development machine, `COMPOSER_ARGS=""` keeps dev dependencies.

---

## Routine maintenance

Scheduled automatically — verify the scheduler is running:

| Task | When |
|---|---|
| `alarms:sweep` | every minute |
| `mqtt:health` | every minute |
| `ingest:prune-idempotency --days=7` | daily 03:20 |

Compression runs after 2 days; retention drops measurements after 365 days.

**Watch disk.** ~4.4 GB/day uncompressed, roughly 160 GB/year with compression.

```bash
df -h /
docker exec -i qv-timescaledb psql -qtAX -U quakevault -d quakevault -c "select pg_size_pretty(pg_database_size('quakevault'))"
```

---

## After touching the sensor's configuration

The displacement range mode is **not readable over Modbus**. After any change in
the vendor software, or after the sensor is re-seated:

```bash
php artisan measurements:check-units
```

Tap the sensor first. A ratio near 1 means units are right; near 0.01 means the
device is in fine mode while the profile still scales counts as micrometres.

Settings do not persist without **SAVE** in the vendor tool.

---

## Calibration

```bash
sudo systemctl stop quakevault-acq
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-calibrate capture --label z-up
# repeat for z-down, x-up, x-down, y-up, y-down
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-calibrate solve
sudo systemctl start quakevault-acq
```

Nothing is applied until the result is installed to
`/etc/quakevault/calibration.yaml`. Absent means identity — the appliance never
applies a correction nobody chose.

A capture refuses itself if the sensor was moving; the solve refuses below 0.8
axis coverage. A calibration fitted from six near-identical positions looks
authoritative and constrains nothing, which is worse than none.

---

## Security notes

- Acquisition holds no capabilities and runs under a restricted syscall filter
  (`systemd-analyze security`: 1.6 OK). It has `AF_INET` only because the live
  feed needs one local socket.
- The forwarder cannot touch serial devices at all.
- MQTT is one-way. Measurements enter only through the authenticated ingestion
  API, so a compromised broker can mislead a screen but cannot forge a record.
- The ingest token carries `ingest` and nothing else — it cannot read.
- Audit events are append-only, enforced by a PostgreSQL trigger.
