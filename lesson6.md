# Lesson 6 — Identity

**Modules:** `discovery.py`, `portlock.py`, `deploy/udev/99-quakevault-rs485.rules`, `measurement.py`
**Related decisions:** ADR-008, ADR-010

---

## Why it exists

Every other lesson assumes you know *which device you are talking to* and *which run a reading belongs to*. This lesson is about earning those assumptions.

Identity failures are the most under-rated hazard in embedded systems because they do not look like failures. They look like data. Two sensors swap places after a maintenance visit and your silo appears to lean the other way. The record is complete, plausible, and describing a building that does not exist.

---

## What problem it solves

### 1. Kernel names are not identity

ADR-008 is blunt about the hardware reality:

> The CH340 in the field exposes no USB serial number, so two identical adapters produce colliding by-id names. `/dev/ttyUSB0` renumbers across reboots and replugs.

Three naming schemes, all inadequate:

| Scheme | Why it fails here |
|---|---|
| `/dev/ttyUSB0` | Assigned in enumeration order. Boot order varies; replug reorders. |
| `/dev/serial/by-id/` | Derived from the USB serial number. **These adapters have none**, so two identical parts collide. |
| MAC / device UUID | Does not exist on a CH340. |

So the appliance keys on the one thing that is genuinely stable: **physical USB topology.**

```
KERNELS=="1-1.1"  →  /dev/quakevault-rs485-p1
KERNELS=="1-1.2"  →  /dev/quakevault-rs485-p2
KERNELS=="1-1.4"  →  /dev/quakevault-rs485-p4
```

The identity of a sensor becomes *which physical socket its adapter is plugged into*. And ADR-008 states the cost without flinching:

> udev rules become site-specific when adapters move between ports. Commissioning must record the topology, and reseating an adapter into a different port is a configuration change.

**That last sentence is the whole lesson.** When your identity is topological, moving a cable is not maintenance — it is reconfiguration. A good architecture does not hide that; it states it so the commissioning procedure can account for it.

This is the general principle: **identity must be derived from something that does not change for reasons unrelated to identity.** Enumeration order changes for reasons that have nothing to do with which sensor is at the top of a silo. Socket position does not.

### 2. Two owners of one port is a disguised failure

```python
"""Exclusive ownership of a serial port.

Two processes polling one RS-485 bus interleave their frames. The symptom is a
storm of CRC errors and timeouts that looks exactly like a wiring fault, and
operators lose days to it. An advisory lock makes the second process fail
immediately with a clear message instead.
"""
```

Note what the lock is optimising for. It is not preventing data corruption in some abstract sense — Lesson 3's CRC already catches the corrupted frames. **It is preventing a misleading symptom.**

The failure without a lock is not "it breaks". It is "it produces exactly the signature of a hardware fault", and a team spends three days with a multimeter on a cable that is perfectly fine.

> **Design your failure modes to point at their own cause.** A system that fails in a way that blames the wrong subsystem costs more than one that fails hard.

And one more detail worth stealing:

> The lock is held on a lockfile keyed to the resolved device path, not on the device node itself, so it works with the udev aliases the appliance uses and survives the alias and the real node being different inodes.

The naive implementation — `flock` the device node — breaks the moment you introduce symlinked aliases, because the alias and the target are different inodes. Someone hit that and wrote down the resolution.

### 3. Discovery must not be able to do damage

```python
Safety rules, enforced structurally rather than by convention:

  * only function code 0x03 is ever emitted. Discovery cannot save
    configuration, change a slave address or baud rate, unlock, calibrate, or
    factory-reset a device, because it has no code path that builds a write;
```

Lesson 2's principle again, applied to safety rather than concurrency. The guarantee is not "we are careful not to write". It is "**there is no code here that can construct a write frame**".

That distinction survives contact with a future contributor in a hurry. A comment saying "don't add writes here" does not.

The second rule is equally good:

> a responder must answer consistently more than once before it is believed, so line noise is not mistaken for a device.

Identity requires *repeatability*. One response is an event; several consistent responses are evidence. And discovery returns four graded verdicts rather than a boolean — the same refusal-to-overclaim you met in Lesson 3's `IMPLAUSIBLE` and Lesson 4's `verification_status`. A pattern is emerging: **this appliance never converts uncertainty into confidence for free.**

### 4. A measurement's identity must include which run produced it

ADR-010, and the best debugging story in the repository:

> Sequence numbers restart at 1 whenever the service restarts. With a key of `appliance:sensor:group:sequence`, the first measurements after every restart collided with the previous run's and were rejected by the spool's UNIQUE constraint as duplicates. This was not theoretical: the live spool had already lost **98 real measurements** across three restarts.

The fix is an **epoch** — a `run_id` minted once per continuous acquisition run:

```
appliance:run_id:sensor:group:sequence
```

Study why the alternatives were rejected:

> Persisting the last sequence per (sensor, group) and resuming from it would work, but adds startup state that can itself be lost or corrupted, and a wrong resume silently overwrites history. A timestamp-based key is not stable under replay. A run id is stateless, survives crashes, and keeps deduplication exact inside a run.

**Prefer stateless identity to persisted counters.** Persisted state at startup is state that can be corrupted, and a corrupted sequence counter does not fail — it overwrites history.

And the consequence is accepted openly:

> Measurements from different runs can no longer be deduplicated against each other. That is correct: they are genuinely different measurements. Anything downstream that wants "the latest reading per channel" must order by timestamp, not assume sequence numbers are globally comparable.

An architect states what their decision breaks downstream, in the same document.

---

## What would happen if it were removed

| Remove | Symptom |
|---|---|
| udev topology aliases | Sensors swap identity across a reboot. Top reads as ground. The data is complete and wrong. |
| `PortLock` | CRC storms that mimic a wiring fault. Days lost at the wrong layer. |
| Discovery's write-free construction | One bad refactor and a scan can factory-reset a commissioned sensor 30 feet up a silo. |
| Multi-response confirmation | Line noise enumerates as a device; the appliance polls a ghost. |
| `run_id` | 98 measurements lost per three restarts — silently, forever. |

---

## The embedded principles

**1. Identity must derive from something stable for identity's reasons.** Not enumeration order, not discovery order, not whatever the OS assigned this boot.

**2. Exclusive resources need enforced exclusivity, and the violation must be legible.** Fail fast with a clear message rather than producing symptoms that blame the hardware.

**3. Dangerous capabilities should be structurally absent, not merely unused.** If a tool must not write, give it no code that can write.

**4. Evidence requires repetition.** One response is noise; consistent responses are a device.

**5. Restarts create epochs.** Any identity built from a counter that resets must include something that says *which* reset it belongs to.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Stable naming / persistent device naming** | udev topology rules |
| **Advisory locking / mutual exclusion** | `PortLock` |
| **Capability restriction by construction** | discovery emits only 0x03 |
| **Graded verdicts over booleans** | discovery's four outcomes |
| **Epoch / generation counter** | `run_id` |
| **Composite key with scope** | `appliance:run_id:sensor:group:sequence` |

---

## Where you will meet this again

### Automotive
- **CAN node identity is the message ID**, allocated at design time in the DBC — never discovered at runtime, precisely because discovered identity is unstable.
- **ECU serial and VIN pairing**: replacing an ECU requires an explicit programming step, because identity is not allowed to silently follow the part.
- **Ignition cycle counters** in DTC aging are epochs: a fault counted in this cycle is not the same as one from three cycles ago.

### Aerospace
- **LRU part number plus serial number**, tracked in a configuration index. Swapping a line-replaceable unit is a recorded configuration change — the exact discipline ADR-008 demands for reseating an adapter.
- **Flight leg / flight number** partitions recorded data into epochs, so parameters from two flights are never compared as one series.

### Industrial / PLC
- **EtherCAT** has this problem and solved it twice: auto-increment addressing follows *cable position*, and Station Alias is written to the slave's EEPROM so identity survives re-cabling. The tension between "position is identity" and "the device knows who it is" is precisely ADR-008's trade.
- **PROFINET device names** are assigned by the engineering tool and stored in the device, so a replacement part adopts the name of the one it replaced.
- **HART long address** (manufacturer + device type + device ID) exists because the polling address alone was not unique enough.

### Robotics
- **DDS GUIDs** identify participants across restarts, and DDS liveliness detects a participant that went away and came back as a new one.
- **ROS 2 node name collisions** produce exactly the disguised-symptom failure: two nodes publishing to one topic looks like a jittery sensor.
- **`/dev/input` and camera enumeration** — every roboticist has been bitten by cameras swapping index across boot, and the fix is always udev rules keyed on topology or serial.

### Linux kernel
- **Persistent device naming** is a solved problem the kernel had to solve painfully: `sda` ordering was unreliable, so `/dev/disk/by-uuid/`, `by-path/`, `by-id/` all exist, and network interfaces moved to `enp0s3`-style *predictable* names derived from topology. This appliance's udev rules are the same answer to the same question.
- **`O_EXCL` on block devices** and `flock` — exclusive ownership enforced by the kernel.
- **Boot ID** (`/proc/sys/kernel/random/boot_id`) is a `run_id`.

### RTOS firmware
- **1-Wire ROM IDs and I²C address conflicts** — the classic embedded identity problem, usually solved by an address-select pin, which is topology.
- **Sensor `WHO_AM_I` registers** — confirm identity before trusting data.
- **Power-on session counters in NVM** so logged events can be attributed to a boot session.

---

## The connective tissue

There is a shape running through Lessons 3, 4 and 6 now, and it is worth naming explicitly:

- Lesson 3: a reading whose plausibility is unproven is marked `IMPLAUSIBLE`.
- Lesson 4: a register map whose correctness is unproven may not drive alarms.
- Lesson 6: a responder seen once is not yet a device; a port with two owners fails loudly.

**Uncertainty is never silently promoted to confidence.** That is not three coincidental decisions. It is one philosophy applied at three layers, and it is the single most transferable thing in this repository.

---

## Design questions

**1.** Topological identity means reseating an adapter is a configuration change. Now suppose a client's technician swaps two adapters during maintenance and tells nobody. Design a detection mechanism. What signal could distinguish "sensors swapped" from "the structure genuinely moved"? Note this is a real problem — bench tap-tests were used on this project for exactly this.

**2.** EtherCAT solved the same problem with Station Alias written into the slave's own memory. These sensors have writable configuration registers. Argue for and against writing an identity into each sensor at commissioning, given ADR-005's warning about protected registers and the fact that the address-change tooling in this repo was never trusted.

**3.** `run_id` makes deduplication exact within a run and impossible across runs, and ADR-010 accepts that. Construct a scenario where cross-run deduplication is genuinely needed, and design a mechanism that provides it without reintroducing persisted-counter fragility.

**4.** Discovery is safe because it contains no code that can build a write frame. But `qv-set-address` exists elsewhere in the same package and *can* write. What stops a future refactor from sharing a helper between them and quietly giving discovery write capability? Design the structural guard — and say honestly whether it survives a determined contributor.

---

*Next: Lesson 7 — Supervision: watchdogs, readiness, restart policy, and the two-minute timer that killed a working recovery.*
