# Testing

Three suites plus an acceptance layer. They check different things and all four
are worth running before a release.

```bash
cd backend && php artisan test                                    # 185
cd frontend && npm test                                           # 33
.venv/bin/python -m pytest acquisition/tests -q                   # 275
.venv/bin/python -m pytest acceptance -q                          # 7
```

## What each covers

**Backend (PHPUnit)** — ingestion idempotency, alarm hysteresis and escalation,
notification gating, role boundaries, spectral gating, unit consistency, report
determinism.

**Frontend (vitest)** — the behaviours where bugs landed: the acceleration card
being absolute by default, connection state coming from the socket rather than
from frames arriving, live frames only appended after the newest stored point,
kiosk freshness ageing by the stalest tile.

ECharts is stubbed rather than avoided, so components mount and tests assert on
the computed chart option and surrounding text. jsdom has no canvas, and pixels
were never where these bugs were.

> `npm test` and `npm run build` check different things. An unused parameter in a
> test fake passed vitest and failed `tsc -b`. Run both.

**Acquisition (pytest)** — CRC, decoding, throughput modelling, spool durability,
forwarder retry, engine scheduling and circuit breaking, derivation, calibration
solving, the register profile against captured fixtures.

**Acceptance (pytest)** — protocol faults against the simulator: CRC corruption,
dropped responses, delayed responses, duplicate slave IDs.

## Fault injection

Stops real services. **Never against a deployment in use.**

```bash
./acceptance/fault-injection.sh                 # all cases
./acceptance/fault-injection.sh redis mqtt      # selected
```

Results in `docs/acceptance-results.md`. Each case restores what it broke,
including on failure.

## Soak

```bash
nohup ./acceptance/soak.sh > /dev/null 2>&1 &
sudo ./acceptance/soak.sh --report
```

Samples every five minutes for 24 hours. It is not testing that the appliance
stays up — a short test shows that — but that nothing drifts: memory that only
grows, a backlog that never returns to zero, a poll rate that sags as the
database fills.

## Backup, upgrade, rollback

```bash
./acceptance/backup-restore.sh          # backs up and verifies the restore
./deploy/upgrade.sh --status
```

## What is not covered

- **Hardware cases 2–5** of the fault matrix need an HWT901B-485 and a second
  working WTVB01-485.
- **The Signal page, routing and login flow** are hand-verified only.
- **Anything visual.** No screenshot or layout testing.

## Writing tests here

Two conventions, both learned the hard way.

**Assert against a real record.** A kiosk test asserted 403 against alarm id 1,
which did not exist, so route-model binding returned 404 and the test passed
while proving nothing. Create the row.

**Assert the inverse too.** The same test now also proves an operator *can*
acknowledge that alarm, so the refusal is known to be about the kiosk's ability
rather than some unrelated obstacle.
