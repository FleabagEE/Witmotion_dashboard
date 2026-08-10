# Lesson 4 — Profiles as Data, Not Code

**Module:** `acquisition/src/qv_acq/profiles/schema.py`, `profiles/loader.py`
**Related decisions:** ADR-004, ADR-005, ADR-009

> This is one of the three lessons where the real architecture lives. Read it twice.

---

## Why it exists

The schema's first sentence is the whole design:

> A profile is data, not code. Adding a Modbus sensor must never require touching the acquisition engine — only a new YAML profile plus fixtures.

Ask why that rule is worth enforcing. The naive alternative is obvious and looks fine at first:

```
if model == "WTVB01-485":
    read_registers(0x34, 12)
elif model == "HWT901B-485":
    read_registers(0x3D, 20)
```

ADR-004 tells you exactly why that rots:

> The two profiles built so far overlap on only acceleration and temperature — asserted by test. Any code branching on model name would rot immediately.

Two devices from **the same manufacturer**, in the same product family, over the same protocol, share almost nothing. Now imagine the fifth vendor. Every `elif` is a permanent tax on the engine, and the engine is the one module you least want to churn — it is the real-time path you proved correct in Lesson 2.

**Separate the mechanism from the description.** The engine knows how to poll a bus, decode a window, flag quality and schedule deadlines. It knows nothing about any specific sensor. What a sensor *is* lives in data.

---

## What problem it solves

### 1. The engine must not know about devices

`Channel` in the schema is a complete description of how to turn registers into meaning:

```python
key, label, quantity, unit, address, data_type, word_order,
scale, offset, value_class, minimum, maximum, notes
```

That is everything the decoder needs and nothing about which device it came from. The engine consumes `RegisterGroup` and `Channel`; a new sensor is a new YAML file and fixtures.

### 2. Quantities must be a closed vocabulary

Look at the `Quantity` literal — a fixed enumeration: `acceleration`, `vibration_velocity`, `attitude_angle`, `condition_indicator`, `fault_code`, and more. Not a free-form string.

This matters more than it appears. If a profile could declare `quantity: "accel"` on one sensor and `"acceleration"` on another, then every downstream consumer — the alarm engine matching on quantity, the units checker, the chart axis labels — silently fails to match. A closed vocabulary makes cross-sensor logic possible **at all**.

And note the annotations carried alongside:

```python
# Statistical descriptors computed on the device (RMS, kurtosis, crest
# factor...). These are the backbone of condition monitoring and,
# crucially, are computed on-sensor from its own high-rate sampling - so they
# are not limited by how fast we can poll the bus.
"condition_indicator",
# Vendor fault/diagnosis words. Opaque bit fields; never scaled.
"fault_code",
```

"Never scaled" is a decoding rule attached to a *category*, not to a channel. That is a taxonomy doing real work.

### 3. Provenance must survive the pipeline

```python
ValueClass = Literal["native", "processed", "derived", "simulated", "imported"]
```

Five classes. `measurement.py` explains why:

> the provenance needed to tell native values apart from processed, derived, simulated and imported ones, which is what stops a computed number from being mistaken for a measured one.

This is **taint tracking** for engineering data. A value the sensor reported and a value your appliance calculated are different kinds of claim, and a reader — or a court — is entitled to know which they are looking at. Lesson 9 shows the same idea applied to calibration.

The engine even enforces it defensively; from `engine.py`:

> Letting it keep the DERIVED class would put a number on the record whose class does not match how it was produced, which is the one thing the value class exists to prevent.

### 4. Trust must be a gate, not a comment

This is ADR-005, and it is the most important idea in the lesson:

```python
VerificationStatus = Literal["verified", "candidate", "unverified"]
```

> **Decision.** Only `verified` may drive alarms or notifications.
>
> **Context.** A wrong register map does not fail loudly — it produces plausible numbers. Silently guessing an address is how a monitoring product tells a customer a machine is healthy when it is not.
>
> **Cost.** Hardware commissioning is a gate, not a formality. Promotion to `verified` requires a probe transcript recorded in `register-maps.md`.

Sit with the shape of that decision.

The product is *capable* of alarming on an unverified profile. The numbers decode. The charts draw. Everything works. The architecture deliberately **withholds** the most valuable capability until a human has produced physical evidence — a probe transcript against real hardware, checked in.

This is what separates instrumentation engineering from software engineering. In most software, "it runs" is close to "it works". In instrumentation, a system that runs beautifully on a wrong register map is not a partial success; it is the worst possible outcome, because it is confidently wrong and nobody can tell.

**Confidence must be earned externally and encoded internally.**

---

## What would happen if it were removed

| Remove | Consequence |
|---|---|
| Profiles as data | Every new sensor edits the real-time engine. The module you most need stable becomes the one that changes most often. |
| Closed `Quantity` vocabulary | Cross-sensor logic becomes string-matching guesswork. The alarm engine cannot reliably find "all velocity channels". |
| `ValueClass` | Computed values become indistinguishable from measured ones. A report attached to a damage claim can no longer say what it is. |
| `verification_status` | The appliance alarms on guessed register maps. ADR-005 notes this discipline **already caught two real errors on this project**. |
| Pydantic schema validation | A malformed profile fails at 3 a.m. on a bus, not at load time on a bench. |

That last row is worth naming: validating the profile at load is **fail-fast at the earliest possible moment**. A `data_type` the decoder does not support should be caught when the YAML is read, not when the first poll arrives.

---

## The embedded principles

**1. Mechanism in code, policy in data.** The oldest rule in systems design, and nowhere does it pay more than in device support.

**2. Describe hardware declaratively.** The thing you are describing is not logic. It is a fact about a physical part, and facts belong in data files that can be reviewed, versioned and diffed by someone who has never read your engine.

**3. Version your descriptions.** `profile_version` travels with every measurement. When you discover that address `0x41` was wrong all along, you need to know which stored readings were decoded under the wrong map.

**4. Encode trust levels in the type system.** Not in documentation, not in a wiki. A three-state literal that the alarm engine checks.

**5. Retain retired descriptions.** ADR-009 keeps the HWT901B profile as a fixture even though the sensor left the product — because it is the only thing that proves the engine is genuinely multi-device rather than accidentally shaped around one sensor.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Table-driven / interpreter** | The engine executes a description rather than branching on model |
| **Schema validation at the boundary** | pydantic, at load time |
| **Hardware abstraction layer** | Profiles are the HAL; the engine is device-agnostic |
| **Capability model** | `profile.capabilities()` — what this device can do, as data |
| **Provenance / taint tracking** | `ValueClass` |
| **Trust levels as a gate** | `VerificationStatus` |

---

## Where you will meet this again

This is the pattern with the strongest cross-domain presence in the whole course. Almost every mature embedded ecosystem invented it independently.

### Automotive
- **DBC, LDF, FIBEX, ARXML** — CAN signals, LIN schedules, and entire ECU configurations as data. Nobody writes `if (message_id == 0x123)` in production automotive; a generator consumes the description.
- **AUTOSAR ECU extract** — the ECU's whole communication behaviour is a description compiled into configuration, not hand-written logic.
- **ODX / UDS diagnostic descriptions** — the diagnostic tool learns the ECU from a data file.

### Aerospace
- **ARINC 424** navigation databases, **ARINC 665/615A** loadable software parts. The distinction between *software* and *loadable data* is formalised, certified, and audited separately — which is precisely the profile/engine split, taken to the level of regulation.

### Industrial / PLC
- **GSD (PROFIBUS), GSDML (PROFINET), EDS (EtherNet/IP), ESI (EtherCAT), IODD (IO-Link)** — every fieldbus has a device description file format. The engineering tool reads it and configures the master. This repo's profiles are a GSD file by another name.
- **FDT/DTM** and **EDDL** exist so that instrument vendors ship descriptions rather than drivers.

### Robotics
- **URDF / SDF** — robot kinematics as data.
- **ros2_control hardware interfaces** and YAML controller descriptions — the controller does not know your motor.

### Linux kernel
- **Device Tree** is the single closest analogue in this entire course. The kernel was drowning in board files, each a wall of `if (machine_is_foo())`. Device Tree moved hardware description out of code entirely, and it is now how virtually all non-x86 Linux boots. The problem it solved is exactly ADR-004's, at a scale of thousands of boards.
- **ACPI tables** do the same job on x86.
- **USB and PCI device descriptors** — devices describe themselves; the OS matches drivers to descriptions.

### RTOS firmware
- **Zephyr uses Device Tree directly**, generating build-time configuration from `.dts`.
- **Board Support Packages** and pin-mux configuration tools (STM32CubeMX, TI SysConfig) generate initialisation from a description you edited in a GUI, for the same reason.

---

## The connective tissue

Lesson 3 built one trust boundary. Lesson 4 asks who is allowed to define what happens at it — and answers: a versioned, validated, human-reviewed description, whose trust level is itself part of the data.

The deepest idea here is not the file format. It is this:

> **A monitoring system's most dangerous state is confident and wrong. The architecture's job is to make that state unreachable without a human signature.**

You will meet the same idea in Lesson 11, where alarm *thresholds* — not register maps — require the same named human confirmation before they may page anybody.

---

## Design questions

**1.** `verification_status` gates alarms but not display: unverified profiles still draw charts. Justify that split. Then find the hole — what could a user reasonably conclude from an unverified chart that they should not?

**2.** ADR-009 retains the retired HWT901B profile purely as a test fixture, to prove the engine is genuinely device-agnostic. That fixture costs maintenance forever. Argue for deleting it, then against, and state the general rule you would apply to "tests for hardware we no longer sell".

**3.** Profiles carry `profile_version`, and every measurement stores it. Design what happens when you discover today that a channel's `scale` has been wrong for six months. Walk through the migration: what happens to stored data, to alarm history, to a report already delivered to a client, and to a court case that cites it.

**4.** The hardest one. ADR-005 requires a probe transcript before promotion to `verified`. That is a human process protecting a machine-readable flag — a convention guarding a construction, which is the inverse of Lesson 2's advice. Can you design a *construction* that makes promotion-without-evidence impossible rather than merely against the rules? If you can, say what it costs. If you cannot, say why this is one of the cases where a convention is the honest answer.

---

*Next: Lesson 5 — The Forwarder: retry, backoff, circuit breaking and at-least-once delivery.*
