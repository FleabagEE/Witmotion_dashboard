# Troubleshooting

Every entry here is a fault that actually happened on this appliance, with what
it looked like and what it turned out to be. Symptoms are listed the way they
present, not the way they are caused — you rarely know the cause when you start
looking.

---

## The dashboard shows "silent" and the charts say "no data in this window"

**First check whether acquisition is running.** The commonest cause is that
somebody stopped it and did not start it again — the serial port is exclusive, so
every register probe and calibration step requires stopping the service.

```bash
systemctl is-active quakevault-acq quakevault-forwarder
```

```bash
sudo systemctl start quakevault-acq quakevault-forwarder
```

If it is active but nothing arrives, work down the chain:

```bash
sudo journalctl -u quakevault-acq -n 40 --no-pager
```

| What the log says | What it means |
|---|---|
| `could not open /dev/quakevault-rs485-a` | Adapter unplugged, or the udev rule did not fire |
| `PortBusyError` | A second instance, or a probe left running. Exit code 2 — systemd will not restart-loop on it |
| `Address family not supported` | The unit's `RestrictAddressFamilies` is blocking a socket it needs |
| Nothing at all | The service is up and quiet — look at the forwarder next |

**The gap is only in the database, not in the record.** Acquisition writes to the
spool first. If the forwarder was down, the data is still there and will drain
when it comes back:

```bash
sudo sqlite3 /var/lib/quakevault-acq/spool.db "select count(*) from spool where delivered_at is null"
```

---

## Readings look wrong by a factor of 100

**Almost certainly the displacement range mode.** The sensor has two — 60000 µm
at 1 µm resolution and 600 µm at 0.01 µm — and **nothing in the Modbus data says
which is active.** Reading the whole configuration block before and after a
switch shows no register change at all.

Check it against physics rather than against the setting:

```bash
cd /var/www/quakevault-industrial/backend && php artisan measurements:check-units
```

Tap the sensor first — it needs some excitation and will say so rather than
invent a verdict.

| Result | Meaning |
|---|---|
| ratio ≈ **1** | Counts are micrometres. The profile is correct. |
| ratio ≈ **0.01** | Displacement is 100× too large: the device is in fine mode while the profile still scales counts as micrometres |

The fix is one number: `scale: 0.01` on `vib_displacement_*` in
`profiles/wtvb01-485.v1.yaml`, then restart acquisition.

**The setting does not survive a power cycle without SAVE.** It was changed on a
Windows machine, the sensor was unplugged to carry it back, and it had reverted.
The manual documents a separate SAVE register at 0x00.

---

## Tapping the sensor does nothing on the Acceleration card

**That is the expected behaviour and the card is not broken.**

Registers 0x34–0x36 are filtered inside the device. Measured: 390 reads at
32.5 Hz over 12 s produced **zero** value changes, and during real taps
(velocity 4.4 mm/s, displacement 222 µm — implying roughly 2 g) the acceleration
span across a whole second was 0.0000 g.

**Look at the Acceleration amplitude card instead.** Registers 0x37–0x39 carry
the vibration component and respond to every tap. The vendor's own software makes
the same split: its "Acceleration" chart sits flat while "Acceleration Amplitude"
beside it spikes.

Raising the baud rate does not help. The measurement above was taken at three and
a half times the appliance's normal polling rate on the same 9600 link.

---

## Tilting the sensor takes seconds to show

Also expected. Measured across four movements: the tilt reading keeps moving
**9.0 s** after the sensor physically stops, drifting **4.33°**.

```bash
cd /var/www/quakevault-industrial/backend && php artisan measurements:check-tilt-response
```

It is the device filtering its acceleration registers, not the appliance — the
live path measures about 25 ms end to end. It cannot be corrected in software:
inverting an unspecified filter would be guesswork presented as a reading.

For "has the mounting moved", nine seconds is invisible against a wall that
shifts over days. It only shows in a hand test.

---

## The Acceleration card shows a flat line at ~1 g and no vibration

Gravity. At rest the three axes carry about 1 g between them, which forces the
axis to span 0.77 g while the vibration is under 0.01 g — the signal ends up at
about 1% of the plot height.

Click **remove static offset** on the card. Measured on live data, the axis span
goes from 0.7583 g to 0.0005 g — a 1553× magnification.

**But turn it off again for a tilt test.** Tilt *is* a change in the static
offset, so removing it hides exactly what a tilt test is looking for. That is why
the card is absolute by default.

---

## The badge says "polling" instead of "websocket"

Data is still correct — it has fallen back to one-second REST polling. Check the
websocket services:

```bash
systemctl is-active quakevault-reverb quakevault-live-bridge
```

```bash
sudo journalctl -u quakevault-live-bridge -n 20 --no-pager
```

| Cause | Fix |
|---|---|
| `read error on connection` in the bridge | The `live` Redis connection needs `read_timeout: 0` — a blocking SUBSCRIBE idles between readings |
| Bridge alive but no frames | phpredis applies the key prefix to channel names; the `live` connection must have `prefix: ''` |
| Reverb not listening | Port 9080. 8080 is taken on this host by an unrelated service |
| Frontend never connects | `frontend/.env` must carry `VITE_REVERB_*`. Vite inlines them at build time, so rebuild after changing |

---

## An alarm is showing but nobody was notified

**Check whether it is provisional.**

```bash
cd /var/www/quakevault-industrial/backend && php artisan tinker --execute="
foreach (DB::table('alarm_definitions')->get() as \$d)
  echo \$d->name.' -> '.(\$d->thresholds_confirmed_at ? 'confirmed' : 'PROVISIONAL').PHP_EOL;"
```

A provisional alarm is displayed and never notifies. That is deliberate: the
DIN 4150-3 and BS 7385-2 tables were reconstructed because the standards are
copyrighted and unavailable, so they are marked `candidate` until somebody with
the published document confirms them:

```bash
php artisan alarms:confirm-thresholds
```

It requires a name and a reference. Nobody can turn notifications on anonymously.

---

## Disk is filling

Two known sources, both now bounded — check they are still bounded.

**Measurements.** ~4.4 GB/day uncompressed at 600k rows/hour. Compression runs
after 2 days:

```bash
docker exec -i qv-timescaledb psql -qtAX -U quakevault -d quakevault -c "
select job_id, config::text from timescaledb_information.jobs where proc_name='policy_compression';"
```

**Replay protection.** `ingested_polls` grows ~1.3M rows/day and is pruned daily
to 7 days. If the scheduler has not been running it will be large:

```bash
php artisan ingest:prune-idempotency --days=7 --dry-run
```

It reached 3.9M rows and 1857 MB in three days before pruning existed. Deleting
returns the space for reuse but not to the filesystem — `VACUUM FULL
ingested_polls` does that, and takes an exclusive lock, so do it in a window.

---

## A command fails with `ModuleNotFoundError: No module named 'qv_acq'`

The package is not installed into the venv:

```bash
/var/www/quakevault-industrial/.venv/bin/pip install -e /var/www/quakevault-industrial/acquisition
```

Use the console entry points rather than `python -m`:

```bash
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-probe --start 0x34 --count 20
```

---

## `Could not open input file: artisan`

`artisan` lives in the Laravel project directory:

```bash
cd /var/www/quakevault-industrial/backend && php artisan <command>
```

---

## Deciding whether a problem is the appliance or the sensor

This is the question worth answering first, and there are two tools for it.

**Is the register updating at all?**

```bash
sudo systemctl stop quakevault-acq
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-probe --start 0x34 --count 6 --update-rate 20
sudo systemctl start quakevault-acq
```

Move the sensor throughout. A register reported `STATIC` is limited by the
device; no amount of polling speed or baud rate will help.

**Do the readings agree with each other?**

```bash
php artisan measurements:check-units
```

`v = 2πfA` ties displacement, velocity and frequency together, and the device
reports all three independently. If they disagree by a factor of a hundred, a
unit is wrong somewhere — which is something no register map can tell you.

---

## The dashboard shows an error page: `RedisException: Connection refused`

The data stack is down. Sessions live in Redis, so every page load throws before
it can render.

```bash
systemctl status quakevault-stack
docker compose ps
```

Start it, and the dashboard recovers on the next request:

```bash
sudo systemctl start quakevault-stack
```

**Your readings are not lost.** Acquisition writes to a local spool that is
independent of the database; it held sixteen hours of readings through exactly
this failure on 2026-08-06 without dropping one. Confirm:

```bash
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-spool status
```

The line that matters is `lost`.

## The dashboard says "Readings are behind"

The spool is holding readings the database has not caught up with. This is the
spool working, not failing, and it clears itself at roughly 2,600 measurement
rows a second — an overnight outage drains in a few minutes.

Watch it go down:

```bash
systemctl status quakevault-forwarder
```

If the number is *growing* rather than shrinking while the database is up, the
forwarder is losing the race and that is a real problem. Check the ingest API is
answering and the database is not overloaded.

## The dashboard says "Readings are not being delivered"

The forwarder has stopped reporting. Sensors are still recording to disk, but
nothing is moving those readings into the database, and **every figure on the
dashboard is older than it looks**.

```bash
systemctl status quakevault-forwarder
journalctl -u quakevault-forwarder -n 50
sudo systemctl restart quakevault-forwarder
```

## The dashboard mentions readings "parked past the retry ceiling"

A long outage burns the retry budget of perfectly healthy readings, and they get
filed alongside genuinely undeliverable ones. 31,307 readings were stranded this
way on 2026-08-06.

```bash
sudo -u quakevault-acq /var/www/quakevault-industrial/.venv/bin/qv-spool retry-dead-letters
```

That is a dry run. Add `--confirm` to do it. Safe to repeat — delivery is
idempotent, so anything that already landed counts as a duplicate rather than
being written twice.

It is deliberately manual. If a reading is genuinely undeliverable this cycles
it straight back to the ceiling, and an operator making that call is what
separates recovering from an outage from hiding a fault.

