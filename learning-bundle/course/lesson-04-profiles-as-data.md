# Lesson 4 — Profiles as Data, Not Code

**Modules:** `acquisition/src/qv_acq/profiles/schema.py`, `profiles/loader.py`, `profiles/wtvb01-485.v1.yaml`
**Supporting:** `config.py`, ADR-004, ADR-005, `docs/register-maps.md`

---

A register map says *"holding register 0x3A, two words, big-endian, signed, times
0.000001, is velocity in mm/s."* Somebody has to write that down somewhere.

The question this lesson is about is **where**, and it looks like a matter of
taste until you notice what it decides: whether adding a sensor model is a code
change, whether a wrong map can be detected, and whether the appliance is able to
refuse to use one.

---

## Facts to have in front of you

```
profiles/wtvb01-485.v1.yaml      profile_version 1.2.0    verification_status: verified
                                 6 register groups, 59 channels

tests/fixtures/hwt901b-485.v1.yaml                        verification_status: candidate
```

One profile is loaded in production. The other exists only as a test fixture, and
is deliberately not `verified` — which is the whole subject of the second half.

The gate itself is three lines in `profiles/schema.py`:

```python
def is_trustworthy(self) -> bool:
    """Whether this profile may drive alarms without operator confirmation."""
    return self.verification_status == "verified"
```

And the refusal is in `config.py`, at binding construction:

```python
if not profile.is_trustworthy():
    # Refusing here rather than at first alarm: a profile that is not
    # verified must never reach production silently.
    raise ValueError(
        f"sensor {self.sensor_id}: profile {self.model} is "
        f"'{profile.verification_status}', not 'verified'. ..."
    )
```

---

## The decision: why a file and not a `switch`

The alternative every codebase reaches for first:

```python
if model == "WTVB01-485":
    velocity = decode_signed(words[0:2]) * 0.000001
elif model == "HWT901B-485":
    ...
```

This works. It is also the version where **the register map has no existence of
its own.** It cannot be printed, diffed, versioned independently of the software,
validated before deployment, checked by the person holding the manufacturer's
table, or shipped to a customer who bought a sensor you have not met.

Making it data buys five things, and only the first is the obvious one:

| | |
|---|---|
| **Adding a sensor is not a code change** | a YAML file plus fixtures |
| **The map has a version of its own** | `profile_version 1.2.0`, independent of the app |
| **It can be validated before it runs** | pydantic rejects a malformed map at load, not at first read |
| **It can be reviewed by a domain expert** | who reads register tables, not Python |
| **It can carry provenance** | which is what makes ADR-005 possible at all |

That last one is the point of the lesson. **You cannot attach trust metadata to
an `elif` branch.** A profile is an object, so it can carry a field saying how
much anyone should believe it.

---

## The part that separates instrumentation from a toy

> **A wrong register map does not fail loudly. It produces plausible numbers.**

This is the sentence to carry out of the lesson. A wrong pin assignment gives you
garbage and you notice in a minute. A wrong *register* assignment gives you
0.0213 g when the truth is 0.0426 g, and the dashboard renders it beautifully,
and the alarm thresholds are evaluated against it, and the client's monthly
report contains it.

Two such errors were found on this appliance, and neither was visible from
stored data:

- `0x44`–`0x46` turned out to be **dominant frequency**, not a second velocity
  block;
- velocity and displacement were **unsigned**, which only showed when a hard
  shake pushed a raw word past 32767 and the signed reading flipped negative.

Both were found with `qv-probe --watch` — moving the sensor and seeing which
registers changed — not by reasoning harder about the manufacturer's table.

So ADR-005 says: `verification_status` is `unverified`, `candidate`, or
`verified`, and **only `verified` may drive alarms**. Promotion requires a probe
transcript recorded in `docs/register-maps.md`. Hardware commissioning is a gate,
not a formality.

### Where the refusal lives, and why that matters

Notice that `config.py` refuses **at binding construction** — before the port is
opened, before a single register is read. Not at first alarm.

The alternative placements all fail differently:

| Refuse at… | Failure mode |
|---|---|
| first alarm | the appliance ran for weeks looking healthy, then refused at the worst moment |
| first read | a partially-started appliance with some buses up |
| **binding construction** | it never starts, and says exactly which sensor and why |

An appliance that refuses to boot is an obvious, cheap, Monday-morning problem.
An appliance that boots and lies is the expensive one.

---

## Design questions

**Q1.** Profiles are validated by pydantic at load. What class of error does that
catch, and — more usefully — what class does it *not*? Give a concrete example
of a profile that passes every validator and is still wrong.

**Q2.** `verification_status` is a field *in the file being trusted*. Anyone who
can edit the map can also edit its trust level. Is that a real weakness? Argue
both sides, then say what you would actually do.

**Q3.** The gate is binary at the point of use: `verified` or refuse. But the
schema has three levels. What is `candidate` *for*, if it cannot drive alarms?
Design what a `candidate` profile should be permitted to do.

**Q4.** `profile_version` is `1.2.0` and readings are stored with the profile
version that produced them. Why is that worth the column? Construct the scenario
where you would need it — and note that the appliance already has this field and
has never yet needed it.

---

## Answers

### A1 — What a schema validator cannot see

Pydantic catches **structural** error. It knows `minimum` must be below
`maximum`, that `data_type` is one of a known set, that `function_code` is 3 or
4, that `word_order` is `big` or `little`. It rejects a map that is malformed as
a document.

It cannot catch a map that is **well-formed and describes the wrong device**.

Concrete, and it happened here: register `0x44` declared as `velocity_y`,
signed, two words, scale 0.000001, min −1000, max 1000. Every validator passes.
The type is legal, the range is sane, the group is coherent. And the register
actually holds dominant frequency in Hz, so the appliance reports a plausible
velocity that is really a frequency, in units it was never measured in.

The general shape:

> **A schema validates the map against itself. Only hardware validates the map
> against the device.**

That gap is the entire reason `verification_status` exists. If a validator could
establish correctness, ADR-005 would be unnecessary — you would just validate.

This is also why `qv-probe --watch` earns its place in the repository. It is the
only tool here that closes the loop by physical experiment: move the sensor, see
which words change. Everything else reasons about a table.

### A2 — Trust metadata inside the thing being trusted

**The objection is real.** Anyone who can write `verification_status: verified`
into a YAML file can promote an unverified map. The gate stops accidents, not
intent — exactly the property established for `ReadOnlyModbus` in ADR-026.

**The defence, and it is the stronger argument:** the threat model is not a
malicious author. It is a competent engineer who transcribes a register table on
a Friday, tests it against a simulator built from the same transcription, sees
green, and deploys. Nothing in that sequence is dishonest and every step is
normal. The field's job is to make *promotion* a separate, deliberate act with a
recorded artefact behind it — the probe transcript in `docs/register-maps.md`.

Put the trust metadata somewhere else — a database, a signed manifest — and you
gain resistance to an attacker you do not have, while losing the property that
actually matters: **the map and its provenance travel together.** A profile
copied to another appliance carries its own status. A profile in a tarball on a
USB stick still knows it was never verified.

**What I would actually change:** nothing about the location, one thing about the
evidence. The status is currently a claim; the transcript that justifies it lives
in a different file and nothing links them. I would put the probe transcript's
hash in the profile, so that "verified" names the evidence rather than asserting
a conclusion. That converts a claim into a citation, and the sweep in round 9 of
`fixproblem.md` is the argument for why claims decay.

### A3 — What `candidate` is for

If `candidate` can do nothing, it is a comment. The three levels are only worth
having if each unlocks something.

The distinction that makes them useful is **which consequences a reading may
have**, not whether it may be taken:

| Status | Read | Store | Display | Drive an alarm | Appear in a client report |
|---|---|---|---|---|---|
| `unverified` | no | — | — | — | — |
| `candidate` | **yes** | yes, marked | yes, marked | **no** | no |
| `verified` | yes | yes | yes | yes | yes |

`candidate` is the state a map is in **while it is being verified**. You need to
read the device to verify the map — that is what `qv-probe` does — so a status
that forbids reading makes verification impossible. What it must forbid is the
reading having *authority*: no alarm, no notification, no report.

And the readings must be **marked**, permanently, in storage. This is the same
argument as `ValueClass.SIMULATED` in the engine: a number whose provenance
cannot be recovered downstream is a number that will eventually be trusted by
someone who does not know. The appliance already does this for simulated values
and derived values; candidate-profile readings belong in the same family.

The honest note: this repository does not implement that table. `is_trustworthy()`
is binary and `candidate` behaves as `unverified` at the gate. That is defensible
— the HWT901B has never been in the building — but it means the three-level
scheme is currently a two-level scheme with a spare label, and anyone reading the
enum would reasonably assume otherwise.

### A4 — Why the profile version rides along with the reading

Because **the interpretation of a stored reading is not stored in the reading.**

The row says `0.0213`. What that means depends on the scale factor, the sign
convention and the register offset that were in force when it was decoded — all
of which live in the profile, and all of which can be corrected later.

The scenario, and it is the one from `question.md` section 11 and ADR-010's cost:

> 2026-03: you discover the velocity scale factor was wrong by 2×. Six months of
> stored readings are half what they should be. You fix the profile and bump it
> to 1.3.0.
>
> Now: **which stored rows are wrong?** Without the profile version on the row,
> the only answer available is "everything before the deploy, probably, if
> nobody rolled back". With it, the answer is a `WHERE` clause.

That is the difference between a correction you can prove and one you can only
assert — and for a monitoring product whose output may be read in a dispute, the
provable one is the only one worth having.

The second use is subtler and matters more over an appliance's life: it makes
**reprocessing** possible at all. You cannot re-derive corrected values from
stored ones unless you know which interpretation produced them. `profile_version`
plus `processing_version` is what turns "the data is wrong" from a write-off into
a maintenance operation.

It has never yet been needed on this appliance. That is not evidence it was
unnecessary — it is a field whose entire value is that it was recorded *before*
anyone knew they would want it. You cannot backfill provenance.

---

## What to take away

1. **Configuration that describes hardware belongs in data, not in branches** —
   not for elegance, but because data can carry provenance and a branch cannot.
2. **A wrong map produces plausible numbers.** This is the failure mode that
   makes instrumentation different from ordinary software, and it is why a
   schema validator is necessary and nowhere near sufficient.
3. **Refuse early and loudly.** At binding construction, naming the sensor. An
   appliance that will not start is a cheap problem; one that starts and lies is
   not.
4. **Only hardware validates a map against a device.** Everything else validates
   the map against itself, including every test you write from the same
   transcription that produced the map.

---

## Where this pattern appears elsewhere

- **Automotive:** CAN databases (`.dbc` files) describe signal layout as data,
  versioned separately from the ECU software that consumes them; A2L files do the
  same for calibration variables.
- **Industrial:** EtherCAT ESI and CANopen EDS files are device descriptions
  shipped by the vendor and validated by the master before use — the same
  "configuration is a document" idea, with the vendor as the author.
- **Linux kernel:** device tree replaced board files for exactly this reason. The
  board's hardware layout stopped being C code and became a versioned document
  the kernel validates at boot.
- **Aerospace:** ARINC 429 label definitions, and the discipline that a data item
  carries its own source and status bits rather than relying on the receiver to
  remember what it asked for.
- **Test and measurement:** IVI/SCPI instrument drivers, where the capability
  description is data and the framework refuses operations the instrument has not
  declared.

The common thread: in every one of these fields, somebody learned that a
description of hardware embedded in software is a description you cannot audit,
version, or refuse.

---

## Design questions for you

Answer these before Lesson 5.

1. `is_trustworthy()` is checked when a `SensorBinding` is constructed. A profile
   file on disk can be edited while the service is running. What are the possible
   behaviours — reload, ignore until restart, refuse to run with a changed file —
   and which would you choose for an appliance that must not stop?

2. A2 proposes putting the probe transcript's hash in the profile so `verified`
   cites evidence instead of asserting a conclusion. Design that. What exactly is
   hashed, who checks it, when, and what happens on mismatch — bearing in mind
   that a check which fails at 03:00 and stops acquisition has done more harm
   than the stale claim it replaced.

3. The appliance has one verified profile and one candidate fixture. Suppose a
   client buys a sensor from a third manufacturer and sends you a PDF of its
   register table. Walk through what has to happen before that sensor may raise
   an alarm on a silo — and identify which step in your own answer is the one
   most likely to be skipped under schedule pressure.

4. A3 admits `candidate` currently behaves identically to `unverified`. Is that a
   defect to fix, or a label to delete? Argue for deleting it, then argue for
   implementing it, and say which you would ship.
