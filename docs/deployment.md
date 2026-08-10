# Deployment

Every systemd unit in this repository points its `Documentation=` here.

The appliance is a single machine on a client's site. Nobody is going to log in
and start anything by hand, and if the power goes at three in the morning the
whole thing has to be back before anybody arrives. That is the standard
everything below is written against.

---

## What runs

| Unit | What it does | If it stops |
|---|---|---|
| `quakevault-stack` | TimescaleDB, Redis and Mosquitto via Docker Compose | **The dashboard throws RedisException at every visitor.** Sensors keep recording to the spool and nothing is lost, but the screen is an error page |
| `quakevault-acq` | Polls the sensors over RS-485 | No new readings. The dashboard keeps showing the old ones, so this failure is invisible without the health page |
| `quakevault-forwarder` | Drains the local spool into the database | Readings accumulate on disk, nothing lost, dashboard freezes |
| `quakevault-dashboard` | Serves the dashboard **and** the API | The screen is blank. Sensors keep recording |
| `quakevault-scheduler.timer` | Ticks the Laravel scheduler every minute | **Nothing is ever evaluated.** No settlement check, no alarm, no email — and the dashboard looks perfectly healthy while this is true |
| `quakevault-reverb` | Websocket server for live updates | Charts stop updating live; reloading the page still works |
| `quakevault-live-bridge` | Redis → websocket bridge | Same as above |
| `quakevault-kiosk` | Full-screen browser for a wall display | Optional; only installed where there is a screen |

Three of those failure columns describe an appliance that looks healthy, or at
least alive, while the client sees nothing useful. All three were real: the
scheduler was absent for weeks, the dashboard was two hand-started processes
until a power cut proved it, and the data stack stayed down for sixteen hours
after a reboot while every sensor kept recording perfectly.

This is why `acceptance/post-reboot.sh` asks its questions from outside — and
why it checks the containers underneath the application as well as the screen
in front of it.

---

## Installing

```bash
sudo ./deploy/install-acquisition.sh
./deploy/build-dashboard.sh
sudo systemctl start quakevault-stack quakevault-acq quakevault-forwarder \
                    quakevault-scheduler.timer quakevault-dashboard
```

The installer is idempotent. It never overwrites an existing
`/etc/quakevault/acquisition.yaml` — the shipped example lands beside it as
`acquisition.yaml.new` for you to merge.

`build-dashboard.sh` runs the frontend tests before it builds. That is not
ceremony: the build lands directly in the directory the live appliance serves
from, so a broken bundle is a blank screen on a client's wall.

---

## The dashboard and the API are one process

The frontend is compiled into `backend/public/` and served by Laravel. The
catch-all in `backend/routes/web.php` returns `index.html` for anything that is
not `/api/` or `/storage/`, so client-side routes like `/events` survive a
bookmark and a refresh.

This replaced a Vite development server on 5173 alongside `php artisan serve` on
8000. That arrangement worked and was wrong: two unsupervised processes, two
ports, a CORS surface, and nothing that came back after a reboot.

For frontend development the dev server is still the right tool — `npm run dev`
proxies `/api` to 8000 and gives you hot reload. It is simply not what a client
stands in front of.

### After changing the frontend

```bash
./deploy/build-dashboard.sh
sudo systemctl restart quakevault-dashboard
```

The restart is belt and braces; `index.html` is served `no-store` precisely so
that a browser cannot hold a copy naming bundles that no longer exist.

---

## Who can reach it

The dashboard binds to `127.0.0.1:8000` — this machine only.

To let other machines on the site network view it:

```bash
sudo install -d -m 0755 /etc/quakevault
echo 'QUAKEVAULT_HTTP_HOST=0.0.0.0' | sudo tee -a /etc/quakevault/dashboard.env
sudo systemctl restart quakevault-dashboard
```

Read this before doing it. The API is token-authenticated and role-checked, and
it has never been exposed to a network anybody else can reach. Exposing it puts
a login page on the site LAN. On a client site that is usually correct and it is
still a decision, which is why it is not the default.

`php artisan serve` is PHP's built-in server. It is fine for the load an
appliance sees — a handful of browsers polling every fifteen seconds, with
`PHP_CLI_SERVER_WORKERS=8` so one slow query cannot block the others. It is not
a public web server. If this ever faces anything larger than a site LAN, put
nginx and php-fpm in front of it.

---

## After a reboot

```bash
acceptance/post-reboot.sh
```

Fifteen checks, read-only, about half a minute. It asks whether the containers
are running, whether the dashboard answers, whether its bundle loads, whether
all three sensors are delivering rows, and whether anything was lost.

It also times every unit against the boot clock and fails anything that started
more than three minutes late — *started 1064s after boot, by hand, not at boot*.
That check exists because "enabled and active" is satisfiable by starting a unit
yourself while debugging, and during the 2026-08-06 investigation the script
briefly read all-green on a machine that had booted with three dead containers.

Run it after every reboot and after every upgrade. A green run is the only
evidence that matters.

---

## After an outage

The spool absorbs a database or API outage by holding readings on disk. It has
been measured against a sixteen-hour one: 187,671 readings held, none lost.

```bash
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-spool status
```

The line that matters is `lost`. Everything else is latency, which the appliance
recovers from on its own. Measured on 2026-08-06: 59,070 measurement rows
landed in a 20-second sample, about 2,600 a second, and a sixteen-hour backlog
cleared in a few minutes.

`parked` counts readings the retry ceiling gave up on. A long outage burns the
retry budget of healthy records, so after one this number is usually recoverable
data rather than a fault:

```bash
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-spool retry-dead-letters --confirm
```

Dry run without `--confirm`. Re-running is safe — delivery is idempotent, so
anything that already landed is counted as a duplicate rather than written
twice. It is deliberately not automatic: if a record is genuinely undeliverable
this cycles it back to the ceiling, and an operator making that choice is the
difference between recovering an outage and hiding a real fault.

---

## Upgrading

```bash
./deploy/upgrade.sh <git-ref>
./deploy/upgrade.sh --rollback
```

Acquisition keeps running throughout — measured at 23,056 readings recorded
across a real upgrade. Rebuild the dashboard afterwards if the frontend changed.

---

## Logs

```bash
journalctl -u quakevault-dashboard -f
journalctl -u quakevault-acq -f
journalctl -u quakevault-scheduler -n 50
```

The scheduler is a oneshot that runs every minute, so its journal is the record
of whether anything has been evaluated at all.

---

## See also

- `docs/troubleshooting.md` — symptoms and what they mean
- `docs/administrator-manual.md` — users, roles and thresholds
- `docs/acceptance-results.md` — what has actually been tested, and what has not
- `docs/known-limitations.md` — including why so many of the faults found here
  were working components that nothing ever called
