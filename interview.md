# QuakeVault Industrial — interview notes

Private working notes. Not part of the repository, not for the client.

---

## Read this first: how to be honest about it

You built this with an AI assistant. In an interview, that is completely normal
in 2026 and not something to hide — but there is one rule that decides whether
this project helps you or damages you:

> **Only claim what you can explain under follow-up questions.**

An interviewer will pick one thing you mention and go three levels deeper. If you
say "I implemented a circuit breaker" they will ask what happens when the
cooldown expires, why half-open exists, and what you chose for the threshold and
why. If you can answer, the AI involvement is irrelevant. If you cannot, the
project becomes evidence against you.

So the safe and truthful framing is:

> *"I designed and drove this appliance end to end — the hardware bring-up,
> protocol verification, the acceptance testing, and the engineering decisions.
> I used an AI assistant heavily for implementation. Every decision in the
> decision log I can defend, because I had to make the call."*

That is a strong position. Plenty of candidates cannot defend a decision log at
all.

**Before any interview**, re-read and be able to explain without notes:

- `fixproblem.md` — pick three rounds and know them cold
- `docs/decision-log.md` — the ADRs, especially 003, 005, 010, 013
- `docs/acceptance-results.md` — cases 8 and 21
- `question.md` sections 1, 2, 3 (spool, threads vs processes)

---

## 1. What the project is

**A structural-health-monitoring appliance for an industrial silo.** Three
accelerometers on the structure (top, mid, ground reference) plus a fourth
inertial sensor, feeding a local database and a browser dashboard, running
unattended on a small Linux box at the site.

It answers three questions that look identical from outside and are not:

| Question | Service |
|---|---|
| Is the structure moving? | `StructureMovement` |
| Can this instrument be believed? | `SensorHealth` |
| Are the readings arriving at all? | `DeliveryHealth` |

### The pipeline

```
sensor ──RS-485──▶ ACQUISITION ──writes──▶ SPOOL ──reads──▶ FORWARDER ──HTTP──▶ API ──▶ DATABASE
   Modbus RTU      Python, systemd        SQLite file      Python, systemd    Laravel   TimescaleDB
                   3 bus threads          on local disk                                 + React dashboard
```

**The load-bearing idea:** a sensor reading at 09:14:22 can only happen at
09:14:22, but writing it to a database can happen any time. One is irreversible,
one is retryable — so a local disk write sits between them, and acquisition can
never be blocked by anything slow or absent.

### Scale

```
181 commits              79 million measurements over two weeks
26 ADRs                  22 hardware-in-the-loop acceptance cases
15 recorded defect rounds
~6,400 lines Python      ~12,400 PHP      ~6,500 TypeScript      ~11,400 docs
945 tests (408 Python, 415 PHP, 122 frontend)
```

### The hardware reality

- **WTVB01-485** vibration sensors, Modbus RTU over RS-485, 9600 baud
- **HWT901B-TTL** AHRS on WitMotion's own protocol — a second driver written
  from scratch after proving the sensor did not speak Modbus
- CH340 USB adapters with **no serial numbers**, so device identity comes from
  physical USB topology via udev
- Everything runs from systemd on the host; only the database, Redis and MQTT
  are containerised, and no serial device is ever passed into a container

---

## 2. Questions you might be asked

### 2a. Embedded / firmware fundamentals this project covers

**Serial and buses**

- Why is RS-485 half duplex a problem, and how do you guarantee serialisation?
  *(One thread per bus. `ThreadPoolExecutor(max_workers=1)`, plus an await loop.
  Two in-flight transactions do not queue, they collide, and the result is
  corrupted frames decoded into plausible numbers.)*
- What is the difference between Modbus RTU and a streaming protocol, and what
  does that change in your driver? *(Polled request/response vs unsolicited
  frames. The Wit protocol streams at 10 Hz, so every read must resynchronise on
  a header and discard traffic it did not ask for — 42 of 43 packets in one
  measured read.)*
- How do you frame and validate? *(CRC-16 for Modbus; sum-of-bytes for Wit.
  Step **one byte** on a checksum failure, not a whole frame, because a header
  byte can appear inside a payload.)*

**Devices and the OS**

- A USB serial device is unplugged and replugged. What happens to your file
  descriptor? *(The kernel destroys `/dev/ttyUSBn`; you hold a descriptor on an
  unlinked inode. Every read fails forever and nothing about the descriptor says
  why. Detect with `os.fstat(fd).st_nlink == 0` — the condition itself, not a
  proxy. Comparing device **names** fails: the kernel reuses freed numbers, and
  a real replug came back as the same `ttyUSB0`.)*
- How do you know which physical sensor is which? *(udev rules keyed on USB
  topology, because these adapters carry no serial number. Consequence: moving a
  cable silently reassigns identity, which is undetectable once mounted.)*
- Walk me through systemd for an appliance. *(`Type=notify`, `WatchdogSec`,
  `Restart=always`, `RestartPreventExitStatus`, boot ordering with
  `After=`/`Wants=`, and `docker compose up --wait` so a oneshot does not
  complete before the thing it started is usable.)*

**Reliability patterns**

- Circuit breaker: why, and what are the three states?
- Retry vs backoff vs debounce — what different failure does each address?
- At-least-once vs at-most-once delivery: which did you choose and why?
  *(At-least-once plus an idempotency key. Choose the failure you can repair
  downstream — a duplicate can be discarded by a key, a lost reading cannot be
  recovered by anything.)*
- What is a dead letter, and what happens when a healthy record hits the retry
  ceiling because of a long outage? *(31,307 readings were stranded exactly this
  way. The ceiling cannot tell poison from a long outage.)*

**Signals and measurement**

- How do you choose an alarm threshold? *(From the measured noise floor, not the
  datasheet, and against the largest perturbation between baseline and decision.)*
- What is quantisation and why does it break thresholds? *(One channel reads a
  single code for two hours. Any threshold finer than a code is unreachable, any
  variance-based check reads zero, hysteresis has no values to sit between.)*
- Nyquist and non-uniform sampling. *(Polled Modbus jitters, so spectra above
  0.4× the measured rate are refused rather than plotted — jitter smears spectra
  and fabricates sidebands that look like bearing faults.)*
- Monotonic vs wall-clock time, and where each belongs.

**Testing**

- How do you test firmware without hardware, and what does that miss?
- What is mutation testing and why does it matter here?
- Difference between example-based and property-based tests.

### 2b. Questions specifically about this project

Have a crisp answer for each of these. They are the ones an interviewer will ask
after hearing your introduction.

1. **Why a spool file rather than a queue in memory or a direct database write?**
2. **Why are the three buses threads but acquisition and the forwarder separate
   processes?** *(Threads when parts must share and cooperate; processes when
   they must be unable to harm each other. During one outage the forwarder was
   killed every two minutes for hours while acquisition never missed a reading.)*
3. **Why is acquisition not in Docker?** *(Container restarts must never
   interrupt acquisition, and passing `/dev/ttyUSB*` into a container widens a
   web-tier compromise to physical hardware. Proved by accident: a power cut left
   the dashboard down for 16 hours and acquisition lost nothing.)*
4. **How do you know your register map is right?** *(You do not, until hardware
   says so. A wrong map does not fail — it produces plausible numbers. Two faults
   were found by moving the sensor and watching which registers changed, and
   neither was visible from stored data.)*
5. **How do you tell a dead sensor from a still structure?** *(You cannot, from
   one sensor. Hence three health services, liveness alarms keyed on the last
   *good reading* rather than the last row, and a ground reference to subtract.)*
6. **What happens when the site loses power?** *(Two measured reboots: 140 s and
   133 s of blindness, nothing lost. The spool covers the gap and every unit
   starts itself.)*

### 2c. The hard follow-ups — where an interviewer will push

These separate a candidate who did the work from one who watched it happen.

- *"You said you used a circuit breaker. What threshold, and why that number?"*
- *"Your identity check uses temperature. What is its noise floor and what
  margin does that give you?"* *(0.548 °C separation against ~0.057 °C drift —
  about 9.6×, and it degrades to 8× over three days because the noise grows with
  the observation window.)*
- *"Show me a bug your tests did not catch, and why."*
- *"What in this system would you build differently?"*
- *"What does this appliance not do?"* — have `docs/known-limitations.md` in mind.
  A candidate who can list their system's limits is far more convincing than one
  who claims it has none.

---

## 3. How to introduce it

### The 30-second version

> *"I built a structural-health-monitoring appliance for an industrial silo —
> three RS-485 accelerometers on a Linux box, running unattended at site. It
> polls the sensors over Modbus, spools every reading to local disk before doing
> anything else with it, and serves a dashboard.*
>
> *The interesting engineering is in what happens when things go wrong: it
> survived a 16-hour database outage with zero data loss, and recovers from a
> power cut in about two minutes without anyone touching it."*

Then stop. Let them pick the thread.

### The 2-minute version — lead with a failure, not a feature

Do not walk through the architecture. Tell one story properly. This one is the
best because it is short, physical, and every step is a real engineering
decision:

> *"One of the sensors stopped reporting. The service was running, systemd said
> everything was healthy, and the dashboard showed three healthy sensors — and it
> had been like that for 37 minutes.*
>
> *Two things were wrong. The sensor had been unplugged and replugged, and the
> kernel destroys the device node and makes a new one — so acquisition was
> holding a file descriptor on an inode that no longer had a name. Every read
> failed forever and nothing about the descriptor said why.*
>
> *The second thing was worse. Our liveness alarm keyed on the last row written,
> and failed polls still write rows — with null values and a bad quality flag,
> deliberately, so a failure leaves a trace. So we had 3,366 rows, zero actual
> readings, and an appliance reporting healthy.*
>
> *We fixed the detection by checking `st_nlink` on the descriptor — that is
> the condition itself rather than a proxy for it — and we fixed the alarm to key
> on the last **good** reading rather than the last row. Then I unplugged the
> sensor for real to verify it: 33 seconds to recover, zero restarts, and the
> other two sensors unaffected.*
>
> *The first attempt at that fix was worse than the bug — it recovered by
> crashing, and took all three sensors down for ten seconds. The acceptance
> script reported PASS, because it only watched the sensor under test."*

**Why this story works:** it has a physical cause, a subtle second-order failure,
a specific technical fix, hardware verification, and an honest admission that
the first fix was wrong. That last part is what senior engineers listen for.

### Other stories worth having ready

**The alarm that inverted severity.** Persistence and hysteresis deadlocked: a
value oscillating across the critical threshold changed target on every sample,
which reset the persistence countdown every time, so the alarm never fired. Six
hours of vibration at three times the warning limit reported `normal`, while a
steady third of that reported `warning`. Neither mechanism was wrong alone —
hysteresis only damps once the alarm is *in* a level, and persistence was what
stopped it getting there.

**The identity check disproved by hand.** We identified sensors by their resting
acceleration — 0.0133 g apart, 26× the quantisation step, apparently ample. Then
two sensors were physically swapped and the check detected nothing: simply
picking a unit up shifts it 0.038 g from cable strain. The signal was a third of
the noise. *You cannot identify a thing by a property that changes more when you
touch it than it differs between things.* The tests passed because synthetic
fixtures contain no handling term — they tested the arithmetic, not the physics.

**The 16-hour outage.** Power cut, the container stack did not come back, the
dashboard threw an exception on every page load for 16 hours. Acquisition never
missed a reading, held 187,671 of them on disk, and drained at ~2,600 rows/s when
the database returned with zero dropped. That is the entire argument for keeping
acquisition out of the container and putting a disk write between the
irreversible and retryable halves.

### The sentence to close on

> *"The thing I took from it is that most of the bugs were not in the code —
> they were checks that passed while measuring the wrong thing. A test that has
> never failed is a hypothesis, not a guarantee."*

That is a genuinely senior observation, it is true of this project, and you can
back it with three examples.

---

## 4. Vocabulary check

Be able to define these in one sentence each, without hesitating:

`half duplex` · `CRC-16` · `idempotency key` · `at-least-once` · `dead letter` ·
`circuit breaker` · `hysteresis` · `debounce` · `persistence window` ·
`latching alarm` · `store and forward` · `back-pressure` · `watchdog` ·
`sd_notify` · `udev rule` · `quantisation step` · `noise floor` · `Nyquist` ·
`monotonic clock` · `hypertable` · `continuous aggregate` · `append-only trigger`

---

## 5. Weak spots to shore up before interviewing

Honest assessment of where this project would *not* support a claim:

- **No RTOS, no bare metal, no MCU firmware.** This is Linux userspace. Do not
  let anyone believe you have written interrupt handlers or scheduled tasks
  under FreeRTOS. If the role is bare-metal, say so and pitch this as systems
  and instrumentation experience instead.
- **No C or C++ in this project.** If the job asks for it, that is a real gap and
  the honest answer is that you have worked at the layer above and would need to
  come up to speed.
- **No hardware design** — no schematic, no PCB, no signal integrity work.
- **Single site, single client.** No fleet management, no OTA update at scale.

What it *does* support strongly: protocol bring-up from a datasheet, hardware
verification discipline, failure-mode analysis, reliability patterns, and testing
that is actually adversarial rather than confirmatory.
