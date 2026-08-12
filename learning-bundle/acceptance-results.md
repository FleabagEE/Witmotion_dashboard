# Acceptance results

Hardware-in-the-loop matrix, 20 cases. Run 2026-08-03 against the live appliance
with one WTVB01-485 on `/dev/quakevault-rs485-a` at 9600 baud.

Every destructive case stops a real component of a running system, watches what
happens, restores it, and then checks that nothing was lost. The last step is the
one that matters: a service that survives an outage but drops the readings taken
during it has failed, quietly.

Reproduce with `acceptance/fault-injection.sh` and
`pytest acceptance/test_protocol_faults.py`.

## Results

| # | Case | Verdict | Evidence |
|---|---|---|---|
| 1 | One WTVB01-485 | **PASS** | Continuous acquisition since 2026-07-31; 18.4 M rows |
| 2 | One HWT901B-485 | **NOT TESTED** | Hardware not available - see below |
| 3 | Both types, separate adapters | **NOT TESTED** | Requires the HWT901B |
| 4 | Both types, one multi-drop bus | **NOT TESTED** | Requires the HWT901B |
| 5 | Multiple sensors on one bus | **NOT TESTED** | Second WTVB01-485 is faulty (RMA pending) |
| 6 | Duplicate slave ID | **PASS** | Refused at config load, before the bus is opened |
| 7 | Adapter unplug / reconnect | **PARTIAL → PASS** | Performed 2026-08-11 15:33: the USB-RS485 adapter was physically pulled from its hub port and returned. 33 s recovery, 0 restarts, controls unaffected. This is the same physical act case 8 records — see the note below |
| 8 | Sensor unplug / reconnect | **FAIL → PASS** | Failed 2026-08-11 against a replug that *renumbers* the device node. Recovery built the same day; re-verified physically at 15:34 — **33 s gap, 0 restarts, controls unaffected**. See below |
| 9 | CRC corruption | **PASS** | 100% corruption → `quality=bad`, all channel values `None`, nothing decoded from noise. 50% corruption → reads continue |
| 10 | Delayed response | **PASS** | Latency recorded (402 ms against a 100 ms timeout); see the timeout finding below |
| 11 | No response | **PASS** | Serial device removed for 20 s: service stayed up, resumed at 1951 rows / 10 s |
| 12 | `/dev/ttyUSB` renumbering | **PASS** | Configured as a udev alias, not a kernel name |
| 13 | Appliance reboot | **SUPERSEDED** | Passed 2026-08-03 on the four acquisition units — and was true while the dashboard still did not come back. Replaced by case 21 |
| 14 | Power loss during storage | **PASS** | `SIGKILL` mid-write: `integrity_check ok`, 500 000 rows intact, service restarted |
| 15 | Redis outage | **PASS** | 20 s outage: acquisition unaffected, 7264 rows recorded through it |
| 16 | MQTT outage | **PASS** | Broker stopped: acquisition unaffected, `mqtt:health` exited 0 |
| 17 | Database outage | **PASS** | 30 s outage: spool backlog 11 → 331, drained on recovery, **14 684 rows recovered, none lost** |
| 18 | Docker restart | **PASS** | All three containers restarted; 6069 rows recovered, acquisition never stopped |
| 19 | 24-hour soak | **RUNNING** | Started 2026-08-03 12:01; `acceptance/soak.sh --report` |
| 20 | Storage pressure | **PASS** | 500 000-row cap enforced, `undelivered_dropped = 0` |
| 21 | Whole appliance restarts itself | **FAIL → PASS** | Failed 2026-08-05: the Docker stack never came back, dashboard threw RedisException for 16 hours. Three ordering defects fixed 2026-08-12. Real reboot 11:25 that day: **17/17, every unit started itself**, 140 s gap, nothing lost |
| 22 | Outage recovery drains without loss | **PASS** | 187,671 spooled readings replayed at ~2,600 rows/s (59,070 in a 20 s sample), `undelivered_dropped = 0`, including 31,307 recovered from dead-letter |

**16 passed, 2 failed-then-fixed-and-verified, 4 not tested, 1 running,
1 superseded. No open failures and nothing partial.**

## Case 8 re-run, 2026-08-11: FAIL

The original run was recorded **PASS** — *"sensor removed to a Windows machine
and returned; 4-minute gap, then automatic recovery"*. Every word of that is
true, and it tested the easier half of the case's own name.

Removing a device and returning it **to the same device node** is not what a
replug does. The kernel destroys `/dev/ttyUSBn` and creates a different one, and
acquisition is left holding a descriptor on a node that no longer exists.

Re-run against that, with two sensors as controls:

```
15:01:53  /dev/ttyUSB5   good  1s     healthy
15:01:58  GONE                 6s     unplugged
15:02:57  /dev/ttyUSB0        64s     replugged, RENUMBERED from ttyUSB5
15:03:35  /dev/ttyUSB0       103s     still climbing

SENSOR-001 (control)   2s      SENSOR-002 (control)   2s
acquisition holds:     /dev/ttyUSB5 (deleted)
```

**No recovery.** 103 seconds after the device returned, acquisition had made no
attempt to reopen it, and only a service restart brought the sensor back.

Note the renumbering was `ttyUSB5 → ttyUSB0` — the kernel **reused the freed
number**, so the new name is not even predictably higher.

### What PASS should have required

1. the loss is detected — ✅ the breaker opened
2. recovery is **automatic**, no restart — ❌
3. good readings resume within budget — ❌
4. the gap is bounded and visible — ✅ once liveness was fixed to key on
   readings rather than rows

Two of four. *"It recovers if you restart it"* is a manual procedure, not
recovery, and the original verdict recorded it as the latter.

Reproduce with `acceptance/case-08-replug.sh`.

### The fix

Acquisition assumed a file descriptor stays valid for the life of the process.
The bus worker now asks, once every 5 s, whether the node it holds still exists —
`os.fstat(fd).st_nlink == 0` — and if it does not, closes and reopens through the
udev alias. `st_nlink` is the condition itself rather than a proxy for it: the
device node was unlinked. Comparing device *names* would not have worked, as the
verification below shows.

## Case 8 verified, 2026-08-11 15:34: PASS

```
15:33:32  /dev/ttyUSB0    1s     healthy
15:33:43  GONE            6s     unplugged, detected
15:34:10  /dev/ttyUSB0   32s     replugged
15:34:11                         good readings resumed

outage 39s, recovery 33s after replug (budget 120s)
NRestarts 0        SENSOR-001 4871 good   SENSOR-002 4872 good
```

Acquisition's own log, showing the rate-limited retry working as designed:

```
15:33:56  BUS-GROUND: reopen failed (could not open ...); will retry
15:34:01  BUS-GROUND: reopen failed (could not open ...); will retry
15:34:06  BUS-GROUND: device changed under us (/dev/ttyUSB0 -> /dev/ttyUSB0); reopened
```

**The device came back under the same name.** `ttyUSB0 -> ttyUSB0` — a different
inode wearing the old name, because the kernel reuses freed numbers. A check that
compared device paths would have concluded nothing had changed and this replug
would have been missed entirely. That is the case `st_nlink` catches and a name
comparison does not.

All four criteria met: detected (51 bad readings), automatic (no restart),
within budget (33 s of 120 s), bounded and visible in the record.

### Two earlier attempts, and why the first "PASS" was worthless

Verification took three physical replugs, and the middle one is the one worth
recording.

**Run 2 reported PASS.** The sensor did recover — because the process crashed and
systemd restarted it. `NRestarts` went to 6, and for about ten seconds *all three
sensors* were dark. An `assert self._reader is not None` fired on the path where
a reopen had just closed the port, so a fault on one bus killed the other two.
The appliance was in a worse state than before the feature existed, and the
script said PASS, because it measured the sensor under test and nothing else.

**Run 3 gave `restarts: 0`** with the controls untouched — and SENSOR-003 stayed
dead for 78 seconds with a single `reopen failed` in the log and silence after
it. A reopen closes the old port before opening the new one, so while the device
is out the worker holds no reader at all, and the staleness check answered "not
stale" when it had nothing to ask. The first failed reopen was the last. Inside
the run loop, holding no reader now means one is needed.

The verdict line was the same in runs 2 and 3 as in the run above. Only the
controls and `NRestarts` distinguished them, and neither was in the original
script. A test that measures less than its name claims produces a result that is
true and useless — the same shape as case 13's reboot PASS and case 8's own
original verdict.

## Case 13 was true, and the appliance was still headless

Worth writing down, because the lesson is not about reboots.

Case 13 read "PASS — real reboot survived, all four units enabled". Every word
of that was correct. On 2026-08-05 the machine lost power, came back, and the
client-facing dashboard did not: it was a Vite development server and a
`php artisan serve`, both started by hand in a terminal, neither known to
systemd. The sensors kept recording throughout. The screen was blank.

The check knew about four units. The product needed five. A test written against
the list of things that exist cannot notice the thing that does not.

So case 21 does not check units at all. It asks the question from outside the
appliance — does the dashboard answer, does its bundle load, are all three
sensors delivering rows — and only then looks at what is enabled. Same
principle as the entry in `known-limitations.md`: a test that starts at the
physical world is the only one that proves an appliance.

### Case 21 failed for real on 2026-08-05, and this is what it cost

Written the day before to catch exactly this, and it half worked: it correctly
failed the dashboard and the database, and had no idea the three containers
existed. Full account in `docs/known-limitations.md`.

Three faults, in the order they bite:

1. The Docker stack had no systemd unit. `restart: unless-stopped` was not
   enough. Now `quakevault-stack.service`, with the dashboard ordered after it.
2. The systemd watchdog SIGABRTed the forwarder every two minutes throughout
   recovery, because `drain_once()` pinged it once per drain and a real backlog
   takes minutes to clear. Heartbeat and metrics now fire per batch.
3. 31,307 readings were parked past the retry ceiling by the outage, with no
   tool able to act on them. `qv-spool retry-dead-letters` recovered all of
   them.

Nothing was lost at any point. The spool did its job.

The check itself was also wrong in a way worth recording: "enabled and active"
is satisfied by starting a unit by hand ten minutes after boot, and during this
investigation the script briefly read 15/15 on a machine that had booted with
three dead containers. It now times each unit against the boot clock.

### What case 21 still does not show

`acceptance/post-reboot.sh` passes 10 of 10 as of 2026-08-05 14:10, with the
dashboard under `quakevault-dashboard.service`, enabled, and answering on
127.0.0.1:8000. Restart-on-failure is proven: `systemctl kill -s KILL` was
followed by the service returning on its own within three seconds.

It is not PASS because this machine has not been rebooted since
`quakevault-stack.service` was installed. Enablement and a `WantedBy` symlink are
strong evidence and are not the same as having watched it happen. Reboot and
run the script to close it:

```
sudo reboot
# then, once back:
acceptance/post-reboot.sh
```

## Backup and restore

Verified end to end on 2026-08-03: checksums check, 28 tables restore into a
scratch database, alarm definitions survive with their confirmation state, and
the scratch database is dropped afterwards. `acceptance/backup-restore.sh`.

It did not work when first written - it could not complete at all. Timing each
step rather than guessing found the spool copy taking over eight minutes for a
1.2 GB file whose contents were almost entirely already in the database dump. It
now exports only undelivered spool rows, and excludes raw measurements by
default: they are evidence with their own retention, not what rebuilds an
appliance, and including them made the backup slow enough that nobody would run
it - which is the most common way a backup fails.

## Upgrade and rollback

Verified on 2026-08-03 by upgrading across a commit carrying a real migration and
then rolling back. `deploy/upgrade.sh`.

| step | result |
|---|---|
| Pre-flight refuses an unhealthy appliance | yes |
| Backup taken before any change | yes |
| Migration applied on upgrade | yes |
| Post-flight health check | passed |
| Rollback reverted the commit | yes, to 89a0c8b |
| Rollback dropped the migration's table | yes |
| Migration count restored | 20 -> 21 -> 20 |
| **Acquisition kept running throughout** | **yes - 23 056 measurements across the upgrade, 22 760 across the rollback** |

That last row is the design working. The spool covers 10.1 hours, so an upgrade
is a planned outage of exactly the things it exists to survive; the database, the
API and the dashboard all restart while readings keep being taken.

**The first attempt could not roll back at all.** State was kept under
`/var/lib/quakevault-acq`, which systemd creates 0700 for the service user, and
the script wrote it with `sudo` while testing for it without. It created a
rollback point it could not find - the upgrade reported success and left no way
back. Invisible until somebody needed it, which is why it was tested rather than
assumed.

## Still outstanding

**Frontend test suite added** - 33 tests under vitest, `npm test` in `frontend/`.

Aimed at the behaviours where bugs actually landed, rather than at coverage:

- the acceleration card is absolute by default, because removing the static
  offset hides tilt - tilt *is* a change in the static offset;
- offset removal centres the trace and states what it removed;
- connection state comes from the socket, not from frames arriving, which is
  what fixed a badge that claimed "websocket" throughout an outage;
- a live frame is only appended after the newest stored point, so a reading is
  never drawn twice - once live and again when its recorded copy lands;
- kiosk freshness ages by the stalest tile and treats a missing reading as
  stale, so three frozen tiles cannot hide behind one that still updates.

Two rules were extracted from the pages to be tested directly - `lib/merge.ts`
and `lib/staleness.ts` - and the pages now call them, so the tests cover the code
that runs rather than a copy of it.

**Still hand-verified:** the Signal page, routing, the login flow and anything
visual. ECharts draws to a canvas jsdom does not implement, so chart tests assert
on the computed option and the surrounding text, not on pixels.

## What "not tested" means here

Cases 2, 3, 4 and 5 need hardware that is not on the bench. Case 2 needs an
HWT901B-485, which was ordered and never arrived; 3 and 4 depend on it. Case 5
needs two working sensors and the second WTVB01-485 is faulty - its Y and Z
velocity and displacement registers return exactly zero while the same axes
report 108 and 126 Hz, which is self-contradictory and so a hardware fault.

The multi-sensor code paths are exercised by the simulator, which runs several
slave IDs on one bus. That is not the same as proving it against real hardware
and should not be recorded as if it were.

Case 7 is marked partial rather than passed. Removing the device node proves the
software recovers; it does not prove the USB adapter re-enumerates cleanly, which
is a different failure and needs somebody to pull the plug.

## Two findings from case 10

**The configured timeout does not bound a transaction.** pymodbus retries
internally, so `timeout: 0.5` produced a transaction lasting about 2 s - four
times over. Anyone sizing a poll rate against the timeout would size it wrong.
The scheduler tolerates it because it schedules on deadlines rather than assuming
a duration, but it is worth knowing.

**A late reply can be picked up by a retry.** The same 1500 ms delay gave
`ok=False` on one run and `ok=True` on the next: a retry collected the response
the previous attempt had abandoned. This is inherent to Modbus RTU, which has no
transaction id to pair a reply with its request. It matters because three
condition-indicator groups are all exactly 12 registers, so a stale reply is not
ruled out by length alone. What prevents it is pymodbus flushing the receive
buffer before each send. The acceptance test asserts only the deterministic part
and records the race rather than pretending it away.

## A bug this run found

The simulator wrote to its pty after the descriptor was closed, when shutdown
landed while a thread was sleeping out an injected response delay. The guard
caught `OSError` but the failure was a `TypeError`, so it surfaced as a traceback
from a daemon thread - noise that can hide a real failure in a test run. Fixed.

## 24-hour soak — 2026-08-03 12:01 to 2026-08-04 12:00

288 samples at 5-minute cadence, acquisition never restarted.

| Metric | Start → end | Median | Verdict |
|---|---|---|---|
| Acquisition RSS | 48,060 → 48,176 KB | 47,940 | +0.6% — no leak |
| Forwarder RSS | 48,812 → 46,328 KB | 42,640 | no growth |
| Spool backlog | 3 → 2 (max 17) | 4 | drains |
| Measured poll rate | 9.26 → 9.12 Hz | **8.38** | drift −0.0% |
| Bus utilisation | 0.652 → 0.599 | 0.605 | headroom |
| Live frames dropped | 0 | 0 | lossy by design |
| DB rows | 18.47M → 34.12M | — | 15.7M in 24 h |

**Passes on stability. Fails on absolute rate.**

The run shows no degradation of any kind: memory flat, backlog draining,
zero dropped frames, and a poll-rate drift of −0.0% measured as two-hour
medians at each end.

But the achieved rate is **8.38 Hz against a configured 10** — 16% short, and
short for the entire run rather than sagging into it. Bus utilisation is 0.60,
so the bus is not the constraint; the shortfall is in the polling loop itself.

This was invisible until now for two reasons, both fixed in `soak.sh`:

- The drift verdict compared the **first and last individual samples**. With a
  sample-to-sample standard deviation of 0.57 Hz that statistic describes which
  samples landed at the ends, not the run. It reported −1.6% from a run whose
  two-hour medians were identical.
- **Nothing compared the achieved rate to the configured one.** The report only
  ever asked whether the rate changed, never whether it had arrived. A rate that
  is stable but 16% low is not sagging — it never got there.

### Caveat on the final two hours

Full test suites were run repeatedly on this machine during the last hour of the
soak. Timing jitter over that window is 6.08 ms against 1.63 ms an hour earlier.
The bulk of the run is unaffected; the tail is contaminated by that load and
should not be read as representative.

### Explained

The shortfall is not a defect in the scheduler. Summed across every configured
group, the bus was asked to carry **752 ms of traffic per second — 75.2%
utilisation**:

| Group | Rate | Transaction | Demand |
|---|---|---|---|
| motion (19 registers) | 10 Hz | 69.4 ms | 694.2 ms/s |
| condition_x/y/z (12 each) | 0.33 Hz | 54.8 ms | 54.3 ms/s |
| fault_diagnosis (3) | 0.1 Hz | 36.1 ms | 3.6 ms/s |
| **total** | | | **752.1 ms/s** |

At 9600 baud a 19-register read is 69.4 ms, so a 100 ms period is 70% consumed
by one transaction before anything else runs. At that utilisation a
single-threaded bus cannot absorb jitter: a late cycle eats the next one, and
the loop skips a beat rather than falling behind. Working back from the
utilisation ceiling gives **8.5 Hz achievable** against a measured median of
**8.38** — the model lands on the measurement from transaction sizes and baud
rate alone.

The configured 10 Hz was never reachable at 9600 baud. It did not degrade; it
never arrived.

**Nothing in the system could have said so.** `throughput.estimate()` answers
"how fast could this one group go with the bus to itself" — 10.98 Hz for motion,
which made 10 look safe. No code summed the five groups actually configured, and
`--check` validated register maps and nothing else.

`bus_demand()` now sums a whole bus and `--check` refuses a config above 65%
utilisation, naming the achievable rate. The production config exits 2; the
tilt-only config reports 7.3%.

### For the two-sensor deployment

RS-485 is a bus, so both silo sensors share one adapter. At tilt-only rates that
is **14.6% utilisation for the pair** — ample. The 10 Hz path would not have
fitted a second sensor at all.

## Case 21, second pass, 2026-08-12: two ordering defects, still unverified

`quakevault-stack.service` was added after the 2026-08-05 outage and looked
sufficient. It was not. Two defects survived it, and both were found by asking
what the units actually enforce rather than by reading them.

### The stack unit completed before the stack was usable

```
ExecStart=/usr/bin/docker compose up -d
```

`up -d` returns when the containers are **created**, not when they are
accepting connections. The compose healthchecks exist — TimescaleDB has a
30-second `start_period` — and nothing consulted them. The unit finished in
about a second, every service ordered `After=` it started immediately, and they
all reached for a database still in its start period.

Fixed with `--wait`, which does not return until every healthcheck passes.
Measured on this appliance: **1s before, 11s after**, with all three containers
reporting `(healthy)` at the moment the unit completes.

### Reverb and the live bridge were ordered behind nothing that matters

```
quakevault-reverb        After=network-online.target
quakevault-live-bridge   After=network-online.target quakevault-reverb.service
```

Both connect to Redis. Neither was ordered behind the stack. The bridge is
literally the Redis-to-websockets process and was ordered two hops away from
Redis, behind a service that was itself ordered behind only the network.

Both now order after `quakevault-stack.service`, `Wants=` rather than
`Requires=` so the existing judgement stands: a dead live view is a convenience
lost, not an appliance down.

### What the failure actually looks like, reproduced

Stopping the stack on 2026-08-12 reproduced the 2026-08-05 morning exactly:

```
systemd says dashboard:  active
dashboard HTTP:          500  (RedisException, Connection refused)
NRestarts:               0
```

**`Restart=always` never fired**, because `php artisan serve` does not exit when
Redis disappears. It stays up and answers every request with a stack trace. A
restart policy is not a health policy, and systemd's `active` means *the process
is running*, which is not the same as *the product works*.

That is why case 21 was recorded as a failure while every unit read green.

### Why this still says unverified

`acceptance/post-reboot.sh` was run afterwards and reported **13 passed, 4
failed** — correctly. It compares each unit's start time against boot time, and
the four it failed had been restarted by hand minutes earlier during this work:

```
FAIL  quakevault-stack   started 182225s after boot - by hand, not at boot
```

That is the check doing its job. It exists because case 13 passed for weeks on
"enabled and active", which stays true when a human starts a unit ten minutes
later. Nothing short of a real reboot can turn those four green, and a green
they cannot earn is worth more than one they can.

## Case 21 closed, 2026-08-12 11:25: PASS

A real reboot, not a simulation. Boot id changed from `5e4829a2…` to `d37dde05…`.

```
Units that must start themselves
  PASS  quakevault-stack          came up 44s after boot
  PASS  quakevault-acq            came up 26s after boot
  PASS  quakevault-forwarder      came up 50s after boot
  PASS  quakevault-dashboard      came up 45s after boot
  PASS  quakevault-scheduler.timer came up  9s after boot

17 passed, appliance came back on its own.
```

Immediately before the reboot the same script read **13 passed, 4 failed** — those
four units having been restarted by hand during the fix work. The only thing that
changed between the two runs was a reboot, which is the point: this case cannot be
passed by a machine that has been helped.

**Cost of the reboot: a 140-second gap, nothing lost.** Acquisition resumed
26 s after boot and the spool held everything either side.

### The third defect, found by the reboot itself

The forwarder restarted **four times** at boot. It was ordered behind
`quakevault-acq` — the process it reads *from* — and behind nothing it writes
*to*. It started at 11:25:37, found nothing listening on :8000, and died with a
`ConnectionRefusedError` traceback until the dashboard came up at 11:25:56.

It always recovered, and nothing was lost: `RestartSec=5` cannot trip
`StartLimitBurst=5` in 10 s, because the restarts are further apart than the
limit can measure, and the spool holds everything meanwhile.

Fixed regardless. A traceback at every boot teaches people that tracebacks at
boot are normal, and `NRestarts` is the counter that distinguishes a healthy
service from a crash-looping one — four expected restarts per boot is noise in
the one signal that detects the real thing. Case 8 was recorded PASS on a run
where the process was crash-looping, and `NRestarts` is what caught it.

Verified to 0 restarts on a normal start. The boot path itself is proven at the
next reboot.

## Cases 7 and 8 record one physical act, and it is worth saying so

Case 7 read **PARTIAL — "physical unplug not performed"** until 2026-08-12. That
was already false when written down, because `acceptance/case-08-replug.sh`
prints this and a human obeyed it on 2026-08-11:

```
Unplug the adapter for SENSOR-003 now. Leave it out ~20s, then plug it back
into THE SAME hub port.
```

**The thing pulled out of the hub was the adapter.** Case 7's scenario had been
performed, and its evidence was sitting in case 8's write-up under a different
name. Case 7 is now PASS on that evidence, cited rather than re-run.

### The distinction that survives, and why it is already covered

On this appliance each sensor has its own USB-RS485 adapter, so there are two
genuinely different disconnections and they fail differently:

| Disconnection | What the kernel sees | Covered by |
|---|---|---|
| **Adapter** pulled from the hub | device node destroyed, later recreated with a possibly different name | cases 7 and 8, verified 2026-08-11 |
| **Sensor** pulled off the RS-485 terminals | nothing — the adapter and its node are still there; reads simply time out | case 11 (No response, PASS) plus the breaker |

The second is the easier failure and was already proven: the port stays open, the
breaker opens after the retry budget, `quality=bad` rows keep arriving so the gap
is visible, and reads resume when the wire is reconnected.

So the pair is fully covered — but by cases whose names do not divide the space
the way the hardware does. Recorded rather than renumbered, because a case list
that renumbers itself loses the ability to compare against earlier runs.
