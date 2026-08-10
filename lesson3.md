# Lesson 3 — The Wire

**Module:** `acquisition/src/qv_acq/client.py`
**Supporting cast:** `crc.py`, `decode.py`
**Related decisions:** ADR-005

---

## Why it exists

`client.py` opens with the sentence that defines the module:

> This is the boundary between the protocol and everything above it: nothing downstream sees a register number.

That is a **trust boundary**. Below it, bytes arrive from a device you did not build, over a differential pair that runs past contactors and VFDs. Above it, the rest of the appliance treats values as facts.

Every serious embedded system has exactly one place where "bytes that arrived" become "a measurement". The quality of the whole product is determined by how paranoid that one place is.

---

## What problem it solves

**A wrong byte is indistinguishable from a real reading.**

This is the defining hazard of instrumentation. A corrupted HTTP response fails to parse and throws. A corrupted Modbus register decodes perfectly into a number — just the wrong one. `0x0FA0` is 4000; flip one bit and you have 4008. Both are valid `int16`. Both scale to plausible acceleration. Nothing anywhere will object.

So the module defends in three independent layers.

### Layer 1 — CRC: did the frame survive the wire?

```python
def crc16(payload: bytes) -> int:
    crc = 0xFFFF
    for byte in payload:
        crc ^= byte
        ...
```

Modbus RTU CRC-16, polynomial `0xA001`, low byte first. Note what this is and is not: **an integrity check, not a security control.** It catches noise. It does not catch a malicious sender, and it does not catch the far more common industrial failure — a frame that is perfectly intact and completely wrong because you asked the wrong device or the wrong address.

### Layer 2 — Decode: are these bytes the type we think?

`decode.py` is pure. No I/O, no client, no state:

> Kept deliberately free of I/O so every decoding rule is unit-testable against fixtures without hardware or a simulator.

This is the **functional core / imperative shell** split, and in embedded work it is worth more than it is in application software. Decoding rules are where the subtle bugs live — sign extension, word order, scaling — and they are exactly the rules you cannot exercise if testing them requires a device on a desk.

Look at what the code knows that a naive implementation would not:

```python
if data_type == "int16":
    # Signed 16-bit two's complement. WitMotion returns signed values for
    # acceleration, angular velocity, angle, magnetic field and temperature.
    return words[0] - 0x10000 if words[0] & 0x8000 else words[0]
```

and:

```python
``word_order`` controls 32-bit assembly only: ``big`` places the high-order
word first (the WitMotion and general Modbus convention), ``little`` swaps
them for devices that publish CDAB ordering.
```

**Modbus does not specify 32-bit word order.** The standard defines 16-bit registers and stops. Every vendor picked their own convention, and the industry names them ABCD, CDAB, BADC, DCBA. A `float32` read with the wrong word order does not error — it produces a wildly wrong but perfectly well-formed float. Making word order a per-channel property in the profile is an admission that the protocol is under-specified and that the appliance must be told, not left to guess.

### Layer 3 — Plausibility: is this number possible?

This is the layer most implementations skip, and it is the best idea in the module.

```python
class Quality(StrEnum):
    GOOD = "good"
    #: Decoded, but outside the profile's declared engineering range. Strong
    #: evidence of a wrong register map, word order, or slave identity.
    IMPLAUSIBLE = "implausible"
    #: No response, bad CRC, or an exception reply.
    BAD = "bad"
```

Three states, not two. Read that middle comment again — it does not say "out of range". It says **what being out of range is evidence of**.

That is diagnostic reasoning encoded in a type. A single implausible reading might be a genuine transient. A channel that is *persistently* implausible is telling you that your register map, your word order, or your slave ID is wrong. The appliance has turned "the numbers look weird" — the vaguest complaint in instrumentation — into a machine-readable signal.

And crucially, `IMPLAUSIBLE` is **not** `BAD`. The reading is kept, flagged, and forwarded. You do not throw away evidence of your own misconfiguration.

---

## What would happen if it were removed

| Remove | Symptom |
|---|---|
| CRC check | Line noise becomes measurements. Random spikes that look like real events, unreproducible, worst during electrical activity — exactly when a structural monitor is most trusted. |
| Pure `decode.py` | Decoding rules become untestable without hardware. Sign-extension and word-order bugs ship. |
| Per-channel `word_order` | 32-bit channels silently wrong on any vendor using CDAB. Values are smooth and plausible. |
| `IMPLAUSIBLE` state | A wrong register map is indistinguishable from a healthy sensor. This is ADR-005's entire premise. |
| Quality flags entirely | Downstream cannot tell "nothing was measured" from "a low value was measured" — and Lesson 11 shows that distinction is what stops an alarm engine reporting a dead sensor as a healthy machine. |

---

## The embedded principles

**1. Validate at the boundary, once, thoroughly.** Not scattered checks downstream. One place where bytes become facts, and it is the paranoid place.

**2. Layer independent checks.** CRC catches corruption. Type decoding catches structure. Plausibility catches semantics. Each catches a class the others cannot: a CRC cannot tell you that you are talking to the wrong device, and a range check cannot tell you a byte flipped.

**3. Keep the decoder pure.** Anything that requires hardware to test will not be tested at the edges, and the edges are where instrument bugs live.

**4. Never let "unusual" be silently equal to "fine" or to "broken".** Reserve a third state. Most quality taxonomies are richer than good/bad for exactly this reason.

**5. Under-specified protocols must be configured, not guessed.** Word order lives in the profile because the standard does not settle it.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Anti-corruption layer** | `client.py` — no register numbers escape upward |
| **Functional core / imperative shell** | pure `decode.py`, I/O-bearing `client.py` |
| **Layered validation / defence in depth** | CRC → type → range |
| **Quality tagging / taint propagation** | `Quality` travels with every value |
| **Table-driven decoding** | `WORD_COUNT`, channel definitions from the profile |

---

## Where you will meet this again

### Automotive
- **CAN** stacks distinguish form errors, stuff errors, CRC errors and ACK errors — different failure classes get different names, exactly like this `Quality` enum.
- **AUTOSAR SWC ports** carry data with validity, and E2E Protection wraps safety-relevant signals in CRC plus an alive counter, because a stale-but-intact frame is its own hazard.
- **DBC files** define signal endianness per signal (`@0`/`@1`) for the same reason `word_order` is per channel.

### Aerospace
- **ARINC 429** is the purest ancestor of this design: every word carries a **Sign/Status Matrix** with Normal Operation, Functional Test, No Computed Data, and Failure Warning. "No Computed Data" is `IMPLAUSIBLE` — the transmitter is alive and telling you it cannot vouch for this value.
- **Air data computers** cross-compare and flag disagreement rather than picking a winner silently.

### Industrial / PLC
- **OPC UA StatusCode** is a whole taxonomy: `Good`, `Uncertain`, `Bad` — with `Uncertain_SensorNotAccurate`, `Uncertain_LastUsableValue`. The middle tier exists industry-wide because two states are not enough.
- **HART** and **NAMUR NE43** define out-of-range current bands (< 3.6 mA, > 21 mA) specifically so a sensor fault is distinguishable from a low reading. That is a plausibility gate implemented in analog.
- **PROFIBUS/PROFINET** modules carry per-channel diagnosis alongside process data.

### Robotics
- **`sensor_msgs/Imu`** carries covariance matrices, and `-1` in the first element means "this quantity is unknown" — a structured admission of ignorance.
- **`NavSatFix`** has a `status` field; fusion nodes are expected to gate on it rather than consume blindly.

### Linux kernel
- **`skb` checksum states** — `CHECKSUM_NONE`, `CHECKSUM_UNNECESSARY`, `CHECKSUM_COMPLETE`, `CHECKSUM_PARTIAL`. The kernel tracks not just whether data is valid but *how much validation has already occurred*.
- **IIO subsystem** separates raw registers from scaled values with `_raw`, `_scale`, `_offset` sysfs attributes — the same raw/engineering split as `apply_scaling()`.
- Driver code that validates descriptors from a device before trusting them: a USB device can lie about its own descriptors, and the kernel checks.

### RTOS firmware
- **UART framing, parity and overrun errors** are distinct flags in every peripheral, not one "error" bit — the hardware designers made the same taxonomy choice.
- **Sensor driver self-test and WHO_AM_I registers**: confirm identity before trusting data. `IMPLAUSIBLE` is the software equivalent for devices that lack a WHO_AM_I.

---

## The connective tissue

Lesson 2's engine protects the *timing* of the physical world. Lesson 3 protects the *meaning* of what came back. Together they enforce a rule worth internalising:

> **A measurement is not a number. It is a number plus a claim about how much you should believe it.**

Strip the claim and everything downstream is forced to assume total confidence — which is how a monitoring product tells a customer a building is fine when the register map was wrong all along.

---

## Design questions

**1.** `IMPLAUSIBLE` readings are kept and forwarded rather than discarded. Defend that choice, then argue the opposite: what damage can an implausible value do once it is in a time-series database that downstream code may query without checking quality? Design the guardrail.

**2.** The CRC catches corruption but cannot detect that you polled the wrong slave ID and got a valid frame from the wrong device. Given that these sensors have no `WHO_AM_I` register, design a runtime identity check that would catch a mis-wired bus after a maintenance visit. What are its false-positive risks?

**3.** Plausibility limits come from the profile's declared `minimum`/`maximum`. Who sets those, and what happens when a real structural event legitimately exceeds them — a blast, an earthquake? Design the policy. Note that "widen the limits" and "trust the sensor" are the same decision viewed from two ends.

**4.** Modbus leaves 32-bit word order unspecified, so this repo made it per-channel configuration. Name two other places in *any* embedded protocol you know where the specification is silent and vendors diverged. For each, say whether you would handle it by configuration, by auto-detection, or by refusing to support the ambiguous case — and why.

---

*Next: Lesson 4 — Profiles as Data, Not Code.*
