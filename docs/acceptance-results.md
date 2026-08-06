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
| 7 | Adapter unplug / reconnect | **PARTIAL** | Port removal covered by case 11; physical unplug not performed |
| 8 | Sensor unplug / reconnect | **PASS** | Observed: sensor removed to a Windows machine and returned; 4-minute gap, then automatic recovery |
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
| 21 | Whole appliance restarts itself | **FAIL → fixed, unverified** | Real reboot 2026-08-05: the Docker stack never came back and the dashboard threw RedisException for 16 hours. No data lost. Fixes in place; not yet re-tested against a reboot |
| 22 | Outage recovery drains without loss | **PASS** | 187,671 spooled readings replayed at ~59,000/min, `undelivered_dropped = 0`, including 31,307 recovered from dead-letter |

**15 passed, 1 partial, 1 failed-then-fixed, 4 not tested, 1 running, 1 superseded.**

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
