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
| 13 | Appliance reboot | **PASS** | Real reboot survived on 2026-08-03; all four units enabled |
| 14 | Power loss during storage | **PASS** | `SIGKILL` mid-write: `integrity_check ok`, 500 000 rows intact, service restarted |
| 15 | Redis outage | **PASS** | 20 s outage: acquisition unaffected, 7264 rows recorded through it |
| 16 | MQTT outage | **PASS** | Broker stopped: acquisition unaffected, `mqtt:health` exited 0 |
| 17 | Database outage | **PASS** | 30 s outage: spool backlog 11 → 331, drained on recovery, **14 684 rows recovered, none lost** |
| 18 | Docker restart | **PASS** | All three containers restarted; 6069 rows recovered, acquisition never stopped |
| 19 | 24-hour soak | **RUNNING** | Started 2026-08-03 12:01; `acceptance/soak.sh --report` |
| 20 | Storage pressure | **PASS** | 500 000-row cap enforced, `undelivered_dropped = 0` |

**14 passed, 1 partial, 4 not tested, 1 running.**

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
