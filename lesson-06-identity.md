# Lesson 6 — Identity

**Modules:** `discovery.py`, `portlock.py`, `deploy/udev/99-quakevault-rs485.rules`, `measurement.py`
**Related decisions:** ADR-008, ADR-010

---

## Why it exists

Every other lesson assumes you know *which device you are talking to* and *which
run a reading belongs to*. This lesson is about earning those assumptions.

Identity failures are the most under-rated hazard in embedded systems because
they do not look like failures. They look like data. Two sensors swap places
after a maintenance visit and your silo appears to lean the other way. The
record is complete, plausible, and describing a building that does not exist.

---

## What problem it solves

### 1. Kernel names are not identity

ADR-008 is blunt about the hardware reality:

> The CH340 in the field exposes no USB serial number, so two identical adapters
> produce colliding by-id names. `/dev/ttyUSB0` renumbers across reboots and
> replugs.

Three naming schemes, all inadequate:

| Scheme | Why it fails here |
|---|---|
| `/dev/ttyUSB0` | Assigned in enumeration order. Boot order varies; replug reorders. |
| `/dev/serial/by-id/` | Derived from the USB serial number. **These adapters have none**, so two identical parts collide. |
| MAC / device UUID | Does not exist on a CH340. |

So the appliance keys on the one thing that is genuinely stable: **physical USB
topology.**

```
KERNELS=="1-1.1"  →  /dev/quakevault-rs485-p1
KERNELS=="1-1.2"  →  /dev/quakevault-rs485-p2
KERNELS=="1-1.4"  →  /dev/quakevault-rs485-p4
```

#### What `1-1.1` actually is, on this appliance

Worth being precise, because it changes what a technician looks at. The three
adapters are not plugged into the PC. They are on a **4-port hub**:

```
PC socket ──▶ hub (1-1) ──┬── hub port 1 (1-1.1) ──▶ p1 ──▶ top
                          ├── hub port 2 (1-1.2) ──▶ p2 ──▶ mid
                          ├── hub port 3         ──▶ (empty)
                          └── hub port 4 (1-1.4) ──▶ p4 ──▶ ground
```

`1-1` is the hub; `.1`, `.2`, `.4` are its downstream ports. Which produces two
mistakes with **opposite** severities:

| Mistake | Result |
|---|---|
| An adapter moved **between hub ports** | top and mid exchange identity, silently. The dangerous one |
| The **hub** moved to a different PC socket | `1-1` becomes `1-2`, all three symlinks vanish, acquisition fails loudly |

The second is safe by accident — it fails in the noticeable direction. Worth
knowing which of your cables is which before you decide how carefully to label
them.

And ADR-008 states the cost without flinching:

> udev rules become site-specific when adapters move between ports.
> Commissioning must record the topology, and reseating an adapter into a
> different port is a configuration change.

**That last sentence is the whole lesson.** When your identity is topological,
moving a cable is not maintenance — it is reconfiguration. A good architecture
does not hide that; it states it so the commissioning procedure can account for
it.

The general principle: **identity must be derived from something that does not
change for reasons unrelated to identity.** Enumeration order changes for
reasons that have nothing to do with which sensor is at the top of a silo.
Socket position does not.

### 2. Two owners of one port is a disguised failure

```python
"""Exclusive ownership of a serial port.

Two processes polling one RS-485 bus interleave their frames. The symptom is a
storm of CRC errors and timeouts that looks exactly like a wiring fault, and
operators lose days to it. An advisory lock makes the second process fail
immediately with a clear message instead.
"""
```

Note what the lock is optimising for. It is not preventing data corruption in
some abstract sense — Lesson 3's CRC already catches the corrupted frames. **It
is preventing a misleading symptom.**

The failure without a lock is not "it breaks". It is "it produces exactly the
signature of a hardware fault", and a team spends three days with a multimeter on
a cable that is perfectly fine.

> **Design your failure modes to point at their own cause.** A system that fails
> in a way that blames the wrong subsystem costs more than one that fails hard.

And one more detail worth stealing:

> The lock is held on a lockfile keyed to the resolved device path, not on the
> device node itself, so it works with the udev aliases the appliance uses and
> survives the alias and the real node being different inodes.

The naive implementation — `flock` the device node — breaks the moment you
introduce symlinked aliases, because the alias and the target are different
inodes. Someone hit that and wrote down the resolution.

### 3. Discovery must not be able to do damage

```python
Safety rules, enforced structurally rather than by convention:

  * only function code 0x03 is ever emitted. Discovery cannot save
    configuration, change a slave address or baud rate, unlock, calibrate, or
    factory-reset a device, because it has no code path that builds a write;
```

Lesson 2's principle again, applied to safety rather than concurrency. The
guarantee is not "we are careful not to write". It is "**there is no code here
that can construct a write frame**".

That distinction survives contact with a future contributor in a hurry. A comment
saying "don't add writes here" does not.

The second rule is equally good:

> a responder must answer consistently more than once before it is believed, so
> line noise is not mistaken for a device.

Identity requires *repeatability*. One response is an event; several consistent
responses are evidence. And discovery returns four graded verdicts rather than a
boolean — the same refusal-to-overclaim you met in Lesson 3's `IMPLAUSIBLE` and
Lesson 4's `verification_status`. A pattern is emerging: **this appliance never
converts uncertainty into confidence for free.**

### 4. A measurement's identity must include which run produced it

ADR-010, and the best debugging story in the repository:

> Sequence numbers restart at 1 whenever the service restarts. With a key of
> `appliance:sensor:group:sequence`, the first measurements after every restart
> collided with the previous run's and were rejected by the spool's UNIQUE
> constraint as duplicates. This was not theoretical: the live spool had already
> lost **98 real measurements** across three restarts.

The fix is an **epoch** — a `run_id` minted once per continuous acquisition run:

```
appliance:run_id:sensor:group:sequence
```

Study why the alternatives were rejected:

> Persisting the last sequence per (sensor, group) and resuming from it would
> work, but adds startup state that can itself be lost or corrupted, and a wrong
> resume silently overwrites history. A timestamp-based key is not stable under
> replay. A run id is stateless, survives crashes, and keeps deduplication exact
> inside a run.

**Prefer stateless identity to persisted counters.** Persisted state at startup
is state that can be corrupted, and a corrupted sequence counter does not fail —
it overwrites history.

And the consequence is accepted openly:

> Measurements from different runs can no longer be deduplicated against each
> other. That is correct: they are genuinely different measurements. Anything
> downstream that wants "the latest reading per channel" must order by timestamp,
> not assume sequence numbers are globally comparable.

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

**1. Identity must derive from something stable for identity's reasons.** Not
enumeration order, not discovery order, not whatever the OS assigned this boot.

**2. Exclusive resources need enforced exclusivity, and the violation must be
legible.** Fail fast with a clear message rather than producing symptoms that
blame the hardware.

**3. Dangerous capabilities should be structurally absent, not merely unused.**
If a tool must not write, give it no code that can write.

**4. Evidence requires repetition.** One response is noise; consistent responses
are a device.

**5. Restarts create epochs.** Any identity built from a counter that resets must
include something that says *which* reset it belongs to.

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
- **CAN node identity is the message ID**, allocated at design time in the DBC —
  never discovered at runtime, precisely because discovered identity is unstable.
- **ECU serial and VIN pairing**: replacing an ECU requires an explicit
  programming step, because identity is not allowed to silently follow the part.
- **Ignition cycle counters** in DTC aging are epochs: a fault counted in this
  cycle is not the same as one from three cycles ago.

### Aerospace
- **LRU part number plus serial number**, tracked in a configuration index.
  Swapping a line-replaceable unit is a recorded configuration change — the exact
  discipline ADR-008 demands for reseating an adapter.
- **Flight leg / flight number** partitions recorded data into epochs, so
  parameters from two flights are never compared as one series.

### Industrial / PLC
- **EtherCAT** has this problem and solved it twice: auto-increment addressing
  follows *cable position*, and Station Alias is written to the slave's EEPROM so
  identity survives re-cabling. The tension between "position is identity" and
  "the device knows who it is" is precisely ADR-008's trade.
- **PROFINET device names** are assigned by the engineering tool and stored in the
  device, so a replacement part adopts the name of the one it replaced.
- **HART long address** (manufacturer + device type + device ID) exists because
  the polling address alone was not unique enough.

### Robotics
- **DDS GUIDs** identify participants across restarts, and DDS liveliness detects
  a participant that went away and came back as a new one.
- **ROS 2 node name collisions** produce exactly the disguised-symptom failure:
  two nodes publishing to one topic looks like a jittery sensor.
- **`/dev/input` and camera enumeration** — every roboticist has been bitten by
  cameras swapping index across boot, and the fix is always udev rules keyed on
  topology or serial.

### Linux kernel
- **Persistent device naming** is a solved problem the kernel had to solve
  painfully: `sda` ordering was unreliable, so `/dev/disk/by-uuid/`, `by-path/`,
  `by-id/` all exist, and network interfaces moved to `enp0s3`-style *predictable*
  names derived from topology. This appliance's udev rules are the same answer to
  the same question.
- **`O_EXCL` on block devices** and `flock` — exclusive ownership enforced by the
  kernel.
- **Boot ID** (`/proc/sys/kernel/random/boot_id`) is a `run_id`.

### RTOS firmware
- **1-Wire ROM IDs and I²C address conflicts** — the classic embedded identity
  problem, usually solved by an address-select pin, which is topology.
- **Sensor `WHO_AM_I` registers** — confirm identity before trusting data.
- **Power-on session counters in NVM** so logged events can be attributed to a
  boot session.

---

## The connective tissue

There is a shape running through Lessons 3, 4 and 6 now, and it is worth naming
explicitly:

- Lesson 3: a reading whose plausibility is unproven is marked `IMPLAUSIBLE`.
- Lesson 4: a register map whose correctness is unproven may not drive alarms.
- Lesson 6: a responder seen once is not yet a device; a port with two owners
  fails loudly.

**Uncertainty is never silently promoted to confidence.** That is not three
coincidental decisions. It is one philosophy applied at three layers, and it is
the single most transferable thing in this repository.

---

## Design questions

**1.** Topological identity means reseating an adapter is a configuration change.
Now suppose a client's technician swaps two adapters during maintenance and tells
nobody. Design a detection mechanism. What signal could distinguish "sensors
swapped" from "the structure genuinely moved"?

> **This one was actually run.** Before reading the postscript below, answer it
> yourself — the experiment produced a result that most reasonable designs get
> wrong, including the one this repository shipped.

**2.** EtherCAT solved the same problem with Station Alias written into the
slave's own memory. These sensors have writable configuration registers. Argue
for and against writing an identity into each sensor at commissioning, given
ADR-005's warning about protected registers and the fact that the address-change
tooling in this repo was never trusted.

**3.** `run_id` makes deduplication exact within a run and impossible across runs,
and ADR-010 accepts that. Construct a scenario where cross-run deduplication is
genuinely needed, and design a mechanism that provides it without reintroducing
persisted-counter fragility.

**4.** Discovery is safe because it contains no code that can build a write frame.
But `qv-set-address` exists elsewhere in the same package and *can* write. What
stops a future refactor from sharing a helper between them and quietly giving
discovery write capability? Design the structural guard — and say honestly
whether it survives a determined contributor.




Q1. Detecting an undeclared swap
I can't answer this blind. I ran it, and I got it wrong — so let me give you the reasoning I should have had, then what actually happened.

What most designs reach for, and why it fails
The instinct is to identify a sensor by its measurand — its vibration signature, its noise floor, its RMS magnitude. It feels right: that's the channel you care about, it's rich, it's always there.

It is the worst available choice, for a reason that should have been obvious before I wrote a line:

Two sensors bolted to one structure see nearly the same excitation. That is the entire point of mounting them on one structure.

Which produces a trap with three jaws:

The between-sensor difference is the structural signal. Use it for identity and you've spent your measurement on bookkeeping.
At rest, that difference is below the noise floor. Measured on your hardware: separation 0.0133 g, within-sensor variation 0.038 g. Signal about a third of noise. Not marginal — inverted.
A shared structure is a shared cause. Any event large enough to distinguish the sensors moves all of them together.
When you physically swapped two units, the cross-match detected nothing. Worse — arbitrate() decided the sensors disagreed for an unknown reason and suppressed the drift alarm that would otherwise have fired. I had built a feature that made the appliance blinder than before it existed.

The property an identifying signal must have
Requirement	Why
Property of the device, not the location	A location-derived signature identifies the mounting point, which is what you already know
Persistent over months	A baseline you can't re-derive later is not a baseline
Larger than its own noise	See above
Orthogonal to the measurand	Otherwise identity and measurement compete for one channel
Temperature offset from the group mean qualifies. Each unit self-heats differently — regulator efficiency, die variation, packaging, thermal path to the mount. It is not what you're measuring, so spending it costs nothing. That's what's shipped now, gated at MIN_TEMPERATURE_SPREAD = 0.25 °C, refusing to judge below that rather than guessing.

It is still weak. Three sensors in one thermal environment with similar self-heating degenerates to nothing, and it honestly says so.

The half of the question that's actually interesting
What distinguishes "swapped" from "the structure genuinely moved"?

There is a clean answer, and it isn't statistical — it's conservation.

So: compute a permutation-invariant statistic over the group — the sorted vector of per-sensor fingerprints. Compare to baseline.

Sorted vector unchanged, assignment changed → someone moved the labels
Sorted vector changed → physics, or a fault
That's a genuine discriminator, it needs no threshold tuning, and it gets stronger with more sensors rather than weaker.

Two more signatures worth stacking:

Time. A swap is a step discontinuity — instantaneous and anti-correlated, A takes B's value as B takes A's. A silo has mass; structural change has a rise time.
Access. This is the strongest and cheapest signal, and it is not in the measurand at all.
That last one connects to what we built this afternoon. A swap cannot happen without a disconnection. You physically cannot exchange two adapters without unplugging both — and as of today the appliance detects and logs exactly that (st_nlink == 0, device changed under us). Two unplug/replug events within minutes is near-proof that the topological binding may no longer hold.

The design I'd defend
Three layers, cheapest and strongest first — the inverse of the order I shipped them:

Event layer. Any disconnect invalidates the identity binding. Mark the sensor identity_unconfirmed and say so on the dashboard. This is the fieldbus pattern — a link event forces re-enumeration, it doesn't ask the network to prove itself statistically.
Permutation layer. On reconnection, test the sorted-multiset invariant.
Fingerprint layer. Temperature, only when spread permits.
I built (3) alone, from synthetic fixtures generated by the same assumption I was testing. They agreed with me perfectly. Your hands on two USB plugs disagreed in about four seconds.

Q2. Writing identity into the sensor
For
The principle is correct and the whole industry converged on it. Station Alias in EtherCAT EEPROM, CANopen node-ID, PROFIBUS station address, DIP switches on I/O modules — every fieldbus that has lived long enough independently reached the same conclusion: topological identity is a human-error surface, and the fix is to make identity travel with the device.

It would collapse Q1 entirely. No statistics, no 0.25 °C threshold, no permutation algebra. Read the register, compare, done. And it would have made today's replug work simpler too — a reopened port could confirm which sensor came back rather than assuming.

Against
ADR-005 verified the read half of the map. Nobody ever verified the write half. Reads were promoted to verified on hardware with a recorded probe transcript. qv-set-address has never been through that gate. Using it would be driving behaviour from an unverified map — the precise thing ADR-005 exists to forbid.
Asymmetric blast radius. A wrong read yields a wrong number, and the appliance has layers that catch wrong numbers. A wrong write can change baud or address and commit it to flash, leaving a sensor that no longer answers — on a silo, possibly at height, possibly behind a permit.
There is no identity register. You'd repurpose a config register that the datasheet calls unused. "Unused" is a datasheet claim, which is exactly the category ADR-005 says to distrust — and a firmware revision may reclaim it without telling anyone.
SAVE_REGISTER / SAVE_VALUE semantics and flash endurance are unverified. Commissioning would become an operation that can damage hardware. The gate becomes more dangerous than what it gates.
My position
Right principle, wrong device, wrong time — and there's a better place to put it.

You don't need identity in the sensor. You need it anywhere in the chain that doesn't change when someone reseats a plug. The chain is sensor → RS-485 → USB adapter → hub port → kernel name, and the failure is at the last two links.

FTDI adapters carry a genuine USB serial number in silicon. Move identity there and you get: intrinsic, readable, no write path, no flash, no ADR-005 problem, about $10 a unit. It identifies the cable, not the sensor — but the sensor-to-cable binding is glued, cable-tied, and disturbed far less often than cable-to-port.

Your CH341s have no serial number at all, which is why the udev rules key on physical topology (KERNELS=="1-1.1") and why reseating is a configuration change in the first place. That is the actual root cause, and it's fixable for the price of lunch.

If you ever do want identity in the sensor: promote the write map through the same ADR-005 gate the read map went through, on a spare unit, and prove recovery — write, change back, power-cycle, confirm — before it touches anything mounted.

Q3. When cross-run dedup is genuinely needed
The scenario
Reprocessing after a decode defect.

A scale factor is found wrong six months in. You want to re-derive corrected measurements from the raw frames. The corrected value is the same physical observation — same sensor, same instant — and it must replace the original, not sit beside it.

But reprocessing runs in a new process, so a new run_id, so a new key, so the server receives two rows describing one moment in time. Now every query has to know which is real, forever.

This isn't hypothetical for this repo: processing_version already exists in the schema. It's one of the eight things built and never wired up — which means the day someone needs it is the day they discover the identity model can't express it.

The mechanism
The mistake would be treating run_id:sequence as the identity. It isn't — it identifies a delivery. Add a second key that identifies an observation:

Content-addressed. Stateless. A pure function of data already in the row.

run_id:sequence keeps doing exactly what ADR-010 designed it for — exact dedup and replay safety within a run
UNIQUE(observation_key, processing_version) lets a reprocessed value land beside the original; readers select the highest version. Nothing is destroyed, and the correction is auditable — which for a monitoring product that may be read in a dispute matters more than saving a row.
Why this doesn't reintroduce what ADR-010 rejected
ADR-010 rejected persisted mutable state: a counter that must be written, read back, resumed correctly, and which silently overwrites history when resumed wrong.

observation_key persists nothing. There is no counter, no resume, no corruption path. Wipe the appliance and the same observation still hashes to the same key.

The honest cost
It moves the fragility rather than eliminating it, and it moves it onto the clock.

Two observations colliding in one timestamp bucket become one row, silently — which is the exact failure ADR-010 was built to prevent, reappearing in a new place. And an NTP step backwards can make a later observation collide with an earlier one.

So the timestamp component must come from a monotonic clock anchored once per run, and a clock step invalidates that anchor for that run. Adding a disambiguating counter would be walking straight back into what ADR-010 refused.

Worth noting where you've seen this: Kafka's idempotent producer uses producer_id + epoch + sequence — structurally identical to ADR-010's run id, arrived at independently. Git object IDs are the content-addressed half. Both patterns, one system.

Q4. The structural guard — which isn't there
I went to check this before answering, and the answer is more interesting than the question expects.

discovery.py opens with:

"Safety rules, enforced structurally rather than by convention: only function code 0x03 is ever emitted. Discovery cannot save configuration, change a slave address... because it has no code path that builds a write."

Then, twelve lines later:

ModbusSerialClient has write_register. It's the same class address_cli.py uses to write the address register at address_cli.py:203.

Discovery is already holding a fully write-capable object. The guarantee isn't structural — it's that discovery's current source happens not to call a method it can reach. That's convention. And there is no test asserting it: I grepped tests/test_discovery.py for any check on function codes or write calls, and there is none.

So your question assumes a refactor would have to share a helper to grant discovery write capability. It doesn't. Discovery has it now. One line, anywhere in the file, and nothing in the repository would fail.

The guard that would actually work
Remove the capability instead of forbidding its use.

Discovery's functions take ReadOnlyModbus. They never see a ModbusSerialClient. Writing from discovery stops being a policy violation and becomes an AttributeError — a thing that cannot be expressed rather than a thing that mustn't be done.

Backed by two cheap things:

an AST test asserting discovery.py neither imports pymodbus directly nor calls any write_* attribute — so the property fails the build, not the code review
naming the danger in the module path, so from ..dangerous.address_write import ... inside discovery is visible in a diff at a glance
Does it survive a determined contributor?
No. And it shouldn't be sold as if it does.

Anyone determined can construct a client inside discovery, reach through _client, or delete the test. There is no defence in a single-repo Python package against a contributor who wants past it.

What it survives is the actual threat, which was never malice. It's a competent, tired person at the end of a Friday who sees two modules that both "talk to a bus", factors out a shared _client() helper because that is what good engineering looks like, and grants write capability to discovery without ever forming the thought.

A structural guard raises the cost of an accident to near-infinity and the cost of an intention to near-zero.

For a safety property among trusted contributors, that is the correct trade — and it's why Rust's unsafe is a keyword rather than a prohibition: the point isn't that you can't, it's that you must say so, in a token that review can grep for. Same shape as __user annotations with sparse in the kernel, MISRA deviations requiring written justification, and ARINC 653 partitioning in avionics where the hardware simply cannot address another partition's memory.

If you need to stop a hostile contributor, the answer was never in the type system. It's signed commits, protected branches, and not giving them merge rights.



---

## Postscript to Q1 — the experiment, and what it destroyed

On 2026-08-11 two adapters were physically exchanged between hub ports, with the
third left as a control. This is the only claim in the whole course tested
against hardware with the outcome unknown in advance.

**What had been built and shipped:** a fingerprint keyed on each unit's resting
magnitude. They read 1.00090, 0.98759 and 0.99276 g, so a swap should make two of
them read like each other. It had tests. The tests were mutation-checked. It was
documented as the answer to this exact question.

**What happened:** nothing. `looks_like: null` on both sensors. And worse — the
cross-sensor arbitration took the drift alarm that *did* fire and **suppressed
it** as "shared (environmental)", because both sensors moved by a similar amount
at the same instant.

A real sensor swap produced no alarm, no email, and no failing check.

**Why:**

```
difference between units      0.0133 g   ← the signal
shift caused by handling      0.0380 g   ← the noise
```

Moving a sensor shifts its own magnitude by about 0.038 g — a z-only offset with
x and y unchanged, consistent with cable strain flexing the package.

> **You cannot identify a thing by a property that changes more when you touch it
> than it differs between things.**

The tests passed because they fed clean synthetic magnitudes with no handling
term. **They tested the arithmetic, not the physics.**

**What did work:** chip temperature, in a single five-minute bucket.

```
19:10   S1 26.22   S2 25.74
19:15   S1 25.76   S2 26.26     ← exchanged
```

The untouched third sensor warmed steadily with no discontinuity.

**And why it is still not a monitor.** Temperature identifies a unit only while
every sensor shares an environment. On a silo — one in sun at the top, one in
shade at the ground — ambient differences of ten or twenty degrees bury a
half-degree chip bias, and each unit converges to its new position's temperature
within the hour, erasing the evidence.

So it ships as `sensor:verify-wiring`: a **bench command**, run deliberately
before mounting, refusing when the biases are within 0.25 °C. Encoding "only
valid while they share air" into *how it is invoked* is more honest than a
background check that silently becomes wrong the day you mount them.

**Three lessons, in order of how much they cost:**

1. **Test the physics, not the arithmetic.** A synthetic fixture agrees with
   whatever assumption produced it.
2. **Never silence, explain.** Missing a detection is a gap; silencing one that
   fired is a regression. The drift alarm now always raises and carries the
   arbitration's opinion as metadata.
3. **Prevention beats detection.** Distinct Modbus addresses — EtherCAT's Station
   Alias answer, which is exactly design question 2 — would have made the swap
   impossible to miss rather than merely detectable. This result is the strongest
   argument for it.

Full account: `fixproblem.md` round 7, and `docs/known-limitations.md`.

---

*Next: Lesson 7 — Supervision: watchdogs, readiness, restart policy, and the
two-minute timer that killed a working recovery.*
