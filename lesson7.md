# Lesson 7 — Supervision

**Modules:** `sdnotify.py`, `deploy/systemd/*.service`, `acceptance/post-reboot.sh`
**Related decisions:** ADR-003

> This lesson is built almost entirely on things that went wrong on real hardware. That is deliberate.

---

## Why it exists

Every other lesson assumes the process is running. This one asks who guarantees that, and how they can tell.

The appliance's standard is stated in `docs/deployment.md`:

> The appliance is a single machine on a client's site. Nobody is going to log in and start anything by hand, and if the power goes at three in the morning the whole thing has to be back before anybody arrives.

That is a *supervision* requirement, and it turned out to be the hardest requirement in the product. Four separate failures in this repository were supervision failures, not logic failures. The code was correct every time.

---

## What problem it solves

### 1. "Started" is not "ready"

```python
"""Minimal sd_notify client.

systemd's readiness and watchdog protocol is a few datagrams to a Unix socket, so
it is implemented here rather than taking a dependency. A no-op when the service
is run outside systemd, which is what makes the same entrypoint usable in tests
and on a developer machine.
"""
```

The forwarder does not report ready when the process starts. It reports ready when it has announced all three sensor profiles and is able to forward:

```python
self.notifier.ready(f"forwarding to {self.settings.base_url}")
```

**Process alive ≠ service ready.** A supervisor that treats fork-success as readiness will start dependents against a service that cannot yet answer. Ordering another unit `After=` a `Type=simple` service buys you almost nothing; ordering it after a `Type=notify` service that reports readiness honestly buys you correctness.

Note also the graceful degradation: no `NOTIFY_SOCKET`, no supervisor, everything becomes a no-op. **The same entrypoint runs under systemd, under pytest, and on a laptop.** A component that only works inside its production supervisor is a component you cannot test.

### 2. A watchdog must match the work, including the worst case

Here is the failure, and it is the most instructive one in the repository.

```ini
WatchdogSec=120
```

`drain_once()` loops until the spool is empty. On a normal day that is a handful of batches — milliseconds. After the sixteen-hour outage it was 84,000 records at roughly a second per batch of 200: **seven minutes of correct, productive work inside one call.**

The heartbeat was sent once per drain. So systemd `SIGABRT`ed the forwarder at two minutes, every two minutes, for the whole recovery — while it was delivering 59,000 rows a minute perfectly.

Three things are worth extracting.

**The watchdog fired on the healthy path.** Not on a hang. On the system working hard.

**The failure only appears when the buffer is full**, which is the state you are least likely to test. The bench never has an eight-hour backlog.

**The fix is not a longer timeout.** Raising `WatchdogSec` to ten minutes would mean a genuinely hung forwarder goes unnoticed for ten minutes. The fix is a heartbeat that reflects *progress*, not *completion*:

```python
def progress(partial: ForwardResult) -> None:
    self.notifier.watchdog()
    self.write_metrics(partial)
    self.notifier.status(f"draining: {partial.delivered} delivered, {self.spool.backlog()} to go")

result = self.forwarder.drain_once(on_batch=progress)
```

> **A watchdog answers "is it making progress?", not "has it finished?".** Any long operation must define a unit of progress and pet the dog per unit — never per operation.

And notice the deliberate restraint in the hook's placement: it fires on *committed batches only*. A forwarder failing every batch does not pet the dog. That is the difference between a heartbeat and a pulse generator.

### 3. Restart policy must know what restarting cannot fix

```ini
Restart=always
RestartSec=5
# Exit 2 is a rejected or missing token. Restarting cannot fix a credential.
RestartPreventExitStatus=2
```

Lesson 5's fatal/transient classification, now enforced by the supervisor. Restart loops against unfixable conditions are one of the great embedded time sinks: they burn CPU, flood logs, and — as Lesson 5 showed — actively destroy data by consuming retry budget.

**Restart is a hypothesis: "the failure was transient."** When you can prove it was not, say so.

### 4. Dependencies must be ordered, and the ordering must be honest

```ini
After=network-online.target quakevault-stack.service
Wants=network-online.target quakevault-stack.service
```

With the reasoning recorded in the unit itself:

> Wants rather than Requires: if the stack genuinely cannot start, a dashboard that comes up and reports the problem is worth more than one that refuses to run and leaves a blank screen with no explanation.

That is a real architectural choice. `Requires` propagates failure; `Wants` orders without binding fate. On an appliance whose job is to be *looked at*, a degraded screen that explains itself beats a correct refusal to start.

The stack unit exists at all because of the second supervision failure:

> Compose already carries `restart: unless-stopped` and that was not enough: after a reboot the three containers stayed down for sixteen hours, and every page load threw a RedisException.

**Do not delegate a boot-time guarantee to a policy stored inside the thing that also has to start.** Docker's restart policy lives in Docker's own database. If Docker's restoration does not happen as expected, nothing else notices. A systemd unit states the requirement in the same place as every other boot requirement.

### 5. A supervisor can only see what it is told to watch

The third and fourth failures were failures of *verification*, and they are the subtlest.

**Failure three:** acceptance case 13, "appliance reboot", read PASS for weeks. It checked that four units were enabled. All four were. The dashboard was two processes started by hand in a terminal and was not in the list — because the list had been written from the units that existed, and a missing unit is by definition not in that set.

**Failure four:** the replacement check, `post-reboot.sh`, was written specifically to catch this — and could still be satisfied by hand. "Enabled and active" stays true when a person starts a unit ten minutes after boot while debugging. During the investigation it briefly read all-green on a machine that had booted with three dead containers.

The fix:

```bash
BOOT_EPOCH=$(date -d "$(uptime -s)" +%s)
GRACE=180
...
started=$(systemctl show "$unit" -p ActiveEnterTimestamp --value)
delay=$(( started_epoch - BOOT_EPOCH ))
...
bad "$unit" "started ${delay}s after boot - by hand, not at boot"
```

> **A verification that a human can satisfy by hand is not a verification.**

And the accumulated rule from all four: **check the floor as well as the roof, and finish at the user.** Ask from outside whether the dashboard answers, whether its bundle loads, whether the sensors are delivering rows — and ask about the containers underneath, not just the application on top.

---

## What would happen if it were removed

| Remove | Consequence |
|---|---|
| `sd_notify` readiness | Dependents start against a service that cannot answer. Ordering becomes decorative. |
| Watchdog | A genuinely hung forwarder is silent forever; the spool fills and starts dropping. |
| Per-batch heartbeat | Every real recovery is killed by its own supervisor. |
| `RestartPreventExitStatus=2` | Infinite restart loop against a bad credential, destroying data as it goes. |
| `quakevault-stack.service` | Sixteen hours of RedisException. Already happened. |
| Boot-clock timing in the check | Verification passes on a machine that never recovered. Already happened. |

---

## The embedded principles

**1. Readiness, liveness and health are three different questions.** Started, still-progressing, and functioning correctly. Conflating them is the root of most supervision bugs.

**2. Heartbeat on progress, at the finest granularity the work allows.**

**3. Timeouts must be derived from the worst legitimate case**, which is almost always recovery, not steady state.

**4. Restart is a hypothesis about transience.** Encode the cases where the hypothesis is known false.

**5. A component must run outside its supervisor.** No-op when unsupervised, or it becomes untestable.

**6. Verify from outside, and make the verification impossible to satisfy accidentally.**

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Watchdog / heartbeat** | `notifier.watchdog()` |
| **Readiness protocol** | `notifier.ready()`, `Type=notify` |
| **Supervision tree** | systemd units with `After`/`Wants` |
| **Fail-fast on non-recoverable** | `RestartPreventExitStatus=2` |
| **Null object** | `SystemdNotifier` as a no-op without a socket |
| **Progress reporting for liveness** | `on_batch` |

---

## Where you will meet this again

### Automotive
- **AUTOSAR Watchdog Manager** distinguishes three supervision types, and the taxonomy is exactly this lesson: **Alive Supervision** (is the task running at its expected rate), **Deadline Supervision** (did this operation complete within its budget), and **Logical Supervision** (did control flow follow the permitted graph). The forwarder bug was a deadline-supervision limit applied where alive supervision was needed.
- **Window watchdogs** must be petted neither too late *nor too early* — a task in a tight error loop pets faster than normal, so early pets are also a fault. Note how `on_batch` firing only on committed batches gives the same protection.
- **Cyclic ECU wake-up and NM (Network Management)** state machines for ordered start-up and shutdown.

### Aerospace
- **ARINC 653 Health Monitor**, with responses defined per error, per partition, per level. Partition overrun is a *defined event with a defined response*, not a crash.
- **Built-in Test**: power-on BIT, continuous BIT, initiated BIT — three answers to "is it working?" for the same reason readiness, liveness and health are distinct.
- **Command/monitor architectures**, where an independent lane checks the primary.

### Industrial / PLC
- **Scan-time watchdog**: exceed the configured scan time and the PLC faults to STOP. Deadline supervision in the runtime.
- **Safety PLCs and watchdog relays** with a defined safe state on timeout — the physical embodiment of "what happens when the heartbeat stops".
- **First-scan / restart-inhibit logic** so machinery does not resume automatically after a power cut. Compare with `Restart=always`: sometimes the correct restart policy is *no*.

### Robotics
- **ROS 2 lifecycle nodes** — `unconfigured → inactive → active` is a readiness protocol; nothing subscribes until a node is genuinely active.
- **`diagnostic_updater` / `diagnostic_aggregator`** — components report their own health rather than being inferred alive.
- **Deadman switches on teleoperation**: no heartbeat, no motion.

### Linux kernel
- **Hardware watchdog `/dev/watchdog`** with `WDIOC_KEEPALIVE`, and `nowayout` semantics.
- **`hung_task` detector and softlockup/hardlockup detectors** — the kernel watching itself, at different granularities for different failure classes.
- **`netdev_watchdog`** firing on a stalled transmit queue and triggering driver-level reset.
- **systemd itself** — this appliance is using the same mechanisms the whole userland relies on.

### RTOS firmware
- **Independent Watchdog (IWDG) plus Window Watchdog (WWDG)** on STM32 parts — two watchdogs with different failure hypotheses.
- **The canonical firmware bug this repo reproduced**: a long flash erase or OTA write that exceeds the watchdog period. Every firmware engineer learns to either pet during the operation or suspend the dog deliberately — and the second choice must be audited, because a suspended watchdog is no watchdog.
- **Task-level heartbeats aggregated by a supervisor task**, which then pets the hardware dog only if *all* tasks reported. That is the design `post-reboot.sh` arrived at from the other direction.

---

## The connective tissue

Lessons 1–6 built a system that is correct. Lesson 7 is about the system being *running and observed*, and it is where this project bled.

The pattern named in the repo's own notes, six instances now: **complete, tested components that nothing invoked.** Calibration, the bus capacity model, the scheduler, `publishMeasurements`, the dashboard process, and a metrics file nothing could read.

Every one was good code. Every one was unwired. Which yields the rule this lesson exists to teach:

> **Correct code that nothing runs is indistinguishable from code that does not exist — and it is worse, because it looks like coverage.**

---

## Design questions

**1.** The watchdog fix pets on committed batches. Now consider a *slow* database where each batch takes 130 seconds — genuinely working, but slower than `WatchdogSec`. The fix does not help. Design a heartbeat that survives arbitrarily slow but progressing work, without becoming a heartbeat that a hung process could also produce.

**2.** AUTOSAR separates alive, deadline and logical supervision. This appliance has only alive supervision. Design deadline supervision for the acquisition engine — what deadline, measured how, and what response? Then argue whether logical supervision is worth adding here or is over-engineering at this scale.

**3.** The dashboard is `Wants=` the stack so it can come up degraded and explain itself. Argue the opposite: name a component in this appliance where starting degraded is *more* dangerous than not starting, and justify making it `Requires=` — or argue that no such component exists here and say why.

**4.** The boot-clock check uses a three-minute grace period. Construct a legitimate scenario where a unit correctly starts more than three minutes after boot. Then design a check that tolerates it without reopening the hole that let a hand-started unit pass — and say what evidence your check relies on that a human cannot fabricate.

---

*Next: Lesson 8 — Observability: what to count, and why the number nobody can read is worth nothing.*
