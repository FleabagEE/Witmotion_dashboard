# Questions and answers — architecture

Plain-language explanations of how this appliance is put together, written in
answer to questions asked while reading the code. Every number in here was
measured on the running machine, not estimated.

**Contents**

1. [The three parts, and what actually couples them](#1-the-three-parts-and-what-actually-couples-them)
2. [One bus, one thread, one sensor?](#2-one-bus-one-thread-one-sensor)
3. [Thread versus process](#3-thread-versus-process)
4. [Why so complicated?](#4-why-so-complicated)
5. [Why go through an HTTP API at all? And what is "the database"?](#5-why-go-through-an-http-api-at-all-and-what-is-the-database)
6. [Does the database serve the website?](#6-does-the-database-serve-the-website)
7. [What is a watchdog, and how did it kill a healthy process 312 times?](#7-what-is-a-watchdog-and-how-did-it-kill-a-healthy-process-312-times)

---

## 1. The three parts, and what actually couples them

```
sensor ──RS-485──▶ ACQUISITION ──writes──▶ SPOOL ──reads──▶ FORWARDER ──HTTP──▶ API ──▶ DATABASE
                   (process 1)             (a file)         (process 2)
```

**Acquisition** asks the sensor for registers, decodes them, writes the reading
to the spool. Then forgets it. **It never sees the database.**

**The spool** is a SQLite file on disk. Not a queue in memory — a file, so it
survives a power cut.

**The forwarder** reads from the spool, POSTs to the API, and marks each record
delivered *only after the API confirms*. If it dies mid-batch, the undelivered
records are still there.

### What influences what

This is the part worth internalising — most of the arrows you would expect
**do not exist**:

| If this fails… | Acquisition | Spool | Forwarder | Your data |
|---|---|---|---|---|
| Database dies | unaffected | grows | retries | **safe on disk** |
| Forwarder dies | unaffected | grows | — | **safe on disk** |
| Acquisition dies | — | stops growing | drains what's there | **gap in the record** |
| Disk fills | writes fail loudly | at cap | drains | oldest *delivered* dropped first |

**Only one row loses data**, and it is the one where the part that talks to
hardware stopped. That is not an accident — it is what the whole shape is for:

> **A sensor reading is a moment that never comes back. Everything downstream
> can be retried.**

So the design puts a disk write between the irreversible half and the retryable
half. Acquisition's only job is to never be blocked by anything slow.

### The one real coupling

The spool is **bounded** — 500,000 records. If the forwarder stays down long
enough the spool fills, and only then does acquisition start losing data. That
is the pressure-release valve, and it is roughly **46 hours** at three readings
a second.

This appliance did it for real: **16 hours down, 187,671 readings held, none
lost, drained in minutes.**

---

## 2. One bus, one thread, one sensor?

Yes — today.

```
BUS-001  →  /dev/quakevault-rs485-p1  →  SENSOR-001 (top)
BUS-002  →  /dev/quakevault-rs485-p2  →  SENSOR-002 (mid)
BUS-003  →  /dev/quakevault-rs485-p4  →  SENSOR-003 (ground)
```

But the rule is **one thread per bus, not per sensor.** They are 1:1 only
because each sensor was wired to its own adapter.

If the silo uses **one shared RS-485 cable**, it becomes one bus, one thread,
three sensors — and that thread polls them in turn, because RS-485 is
half-duplex and only one device may speak at a time.

The thread-per-bus rule is what makes that safe: **serialisation by
construction**, not by a rule somebody has to remember. See
`ThreadPoolExecutor(max_workers=1)` in `acquisition/src/qv_acq/engine.py`.

---

## 3. Thread versus process

**Process** = its own memory. **Thread** = shares memory with its siblings.

Measured on this machine:

```
PID 2992    acquisition   47,740 KB   ← 5 threads share this memory
                                         (main + helper + 3 bus workers)
PID 220059  forwarder     43,412 KB   ← completely separate memory
```

| | Threads (the 3 buses) | Processes (acq vs forwarder) |
|---|---|---|
| Memory | shared | separate |
| One hangs | others keep running | others keep running |
| One **crashes** | can take the process down | **cannot touch the other** |
| Supervised by | the program itself | systemd, independently |
| Talk to each other by | shared variables | the spool file |

### Why each choice was made here

**Buses are threads** because they need to share things — the configuration, the
spool handle — and three processes would mean three copies of everything plus a
way to coordinate them. They only need to not *block* each other, and threads
give that.

**Acquisition and the forwarder are processes** because they need something
stronger than "don't block": they need to be **unable to harm each other at
all**. If the forwarder wedges on a hung HTTP call, leaks memory, or gets
`SIGKILL`ed, acquisition must not notice. Separate memory and separate systemd
supervision give that.

This was watched happening. During the outage of 2026-08-06 the forwarder was
killed by the watchdog **every two minutes for hours**, while acquisition polled
all three sensors without missing a beat. Same machine, different processes.

> **Threads when parts need to share and cooperate.**
> **Processes when they need to be unable to hurt each other.**

The general rule: put a process boundary wherever a hang or crash on one side
must not reach the other. Here that boundary sits exactly where the irreversible
work — reading a sensor — meets the retryable work of delivering it.

---

## 4. Why so complicated?

> *Why do we need acquisition, spool and forwarder? Why not just read the sensor
> and write it to the database?*

### The simple version

```python
while True:
    reading = ask_sensor()          # 50 ms
    database.insert(reading)        # 5 ms... usually
    sleep(1)
```

One process. About twenty lines. It works perfectly on a desk.

### Why it breaks

The database write is **on the same line** as the sensor read, so anything that
slows the database slows the sensor polling:

| Database takes | What happens |
|---|---|
| 5 ms | fine |
| 900 ms | you are late for the next reading |
| 30 s (busy) | you miss 30 readings |
| **down** | you miss **everything** until it comes back |

The real number from this appliance: **the database was down for 16 hours.**

- Simple version: **16 hours of nothing.** Gone, permanently.
- What was actually built: 187,671 readings held on disk, **zero lost**, drained
  in minutes.

### The one idea underneath all of it

```
Reading the sensor at 09:14:22  →  can ONLY happen at 09:14:22
Writing it to the database      →  can happen now, in 5 minutes, or tomorrow
```

One is **irreversible**. The other is **retryable**.

> **When an irreversible operation and an unreliable one share a loop, the
> unreliable one destroys the irreversible one.**

So you separate them — and the seam between them has to be something that never
fails and never waits, which is a local disk write. Microseconds, no network, no
server that can be down.

That is the spool. Not a database, not a queue server, not a message broker.
A file.

### Why the forwarder is a separate process

Because a thread is not enough protection. During the outage the forwarder was
being `SIGKILL`ed every two minutes for hours, and acquisition never missed a
reading on the same machine. Separate memory, separate supervision: one could die
repeatedly without the other noticing.

### What each part costs and buys

| Part | Job | Without it |
|---|---|---|
| Acquisition | talk to hardware, never block | — |
| Spool | absorb every outage downstream | outage = permanent data loss |
| Forwarder | deliver, retry, give up loudly | acquisition waits on the network |

Three parts instead of one is real complexity. **That complexity buys exactly
one thing: an outage costs you time instead of data.**

### When you should *not* build this

If losing data during an outage is acceptable, don't. A room-temperature logger,
a demo, anything you can simply re-measure tomorrow — write the simple loop and
go home.

**You cannot re-measure a silo settling on the night of a storm.** That is the
whole reason.

---

## 5. Why go through an HTTP API at all? And what is "the database"?

> *Why `FORWARDER ──HTTP──▶ API ──▶ DATABASE`? Why not write to the database
> directly? And what does "database" mean here?*

### What "the database" is

**TimescaleDB** — PostgreSQL, in a Docker container. The permanent record. Every
reading ever taken, 64 million rows and counting, indexed by time and sensor so
anything can query it.

The distinction from the spool is the one that matters:

| | Spool | Database |
|---|---|---|
| What | a SQLite **file** | a **server** |
| Purpose | hold readings until delivered | keep them for ever |
| Lifetime | deleted once delivered | 365 days raw, 10 years rolled up |
| Who reads it | only the forwarder | charts, alarms, reports, everything |
| Queryable | barely | fully — that is the point |

The spool is a **waiting room**. The database is the **archive**.

### Why not write to it directly

Because the API does far more than insert. When a batch arrives, `IngestService`:

- **deduplicates** by idempotency key, so a retried batch cannot double-write
- **resolves the sensor** and creates channels from its profile
- **validates** every envelope before anything is stored
- **evaluates alarms** — this is where thresholds are actually checked
- **publishes** to Redis and MQTT for the live feed

Write SQL from the forwarder and **every one of those has to be reimplemented in
Python** — two implementations of the same rules, which will eventually disagree.

And alarms are evaluated *at the moment data arrives*. Bypass the API and nothing
evaluates anything; you would need a separate poller, which is precisely the
missing-scheduler fault this appliance already had once.

### The security reason, which is the strongest one

The forwarder runs as the account that owns the **serial ports**. It is in
`dialout`; it touches physical hardware.

Its credential is a bearer token carrying the **`ingest` ability only**:

> *"Its tokens carry the 'ingest' ability only, so a leaked appliance credential
> cannot read history or change configuration."* — `routes/api.php`

So a compromise of the serial-facing process lets an attacker **add** readings.
It does not let them read history, alter past records, delete evidence, or change
thresholds.

Give that process a database password instead and it gains `UPDATE` and `DELETE`
on the historical record — the thing reports and any future court case rest on.
Same reasoning as ADR-019 keeping MQTT outbound-only: **one door into the record,
and it only opens inward.**

### Two more

**Schema decoupling.** The forwarder speaks a documented JSON contract. The
hourly rollup was rebuilt and 64 million rows re-decoded on 2026-08-11 and the
acquisition side never knew. Holding SQL would have made that migration a
coordinated Python deployment.

**It works over a network.** Today both are on one box. The same code path works
with the appliance on site and the database elsewhere.

### What it costs

Real costs, not zero. HTTP and JSON are slower than direct SQL — about a second
per 200-record batch here — and if the API is down, delivery stops.

That second cost is exactly what the spool absorbs. **The API being down costs
latency, not data**, which is the trade the whole design makes.

---

## 6. Does the database serve the website?

No. **The database never talks to the browser — the API does.** And one of the
two read paths does not touch the database at all.

```
                  WRITE PATH (durable, never loses anything)
sensor ──▶ ACQUISITION ──▶ SPOOL ──▶ FORWARDER ──▶ API ──▶ DATABASE
                │
                │         LIVE PATH (instant, lossy, never stored)
                └────────▶ REDIS ──▶ live-bridge ──▶ REVERB ──▶ browser
                                                     (ws :9080)

                  READ PATH (what the website actually does)
browser ──▶ API ──▶ DATABASE ──▶ API ──▶ JSON ──▶ browser draws the chart
```

### Why the database does not serve the page

It speaks **SQL**; the browser speaks **HTTP and JSON**. Something must sit
between them, and that is the API — the same `quakevault-dashboard` process that
serves the dashboard itself.

The API does three things the database cannot:

- **Authorises.** A `viewer` may read; only a super-admin may change thresholds.
  Role logic, not storage.
- **Shapes the answer.** A 24-hour chart is not 86,400 points. The API buckets
  them and chooses raw data or the hourly rollup depending on the window.
- **Says how much to trust it.** `n` and `ok` on every bucket — samples, and how
  many the appliance believes.

Same one-door principle as the write side: **the database is not reachable from a
browser.** It listens on localhost inside a container. Exposed, every role check
would become optional.

### Why there are two read paths

**The durable path** — browser asks the API, the API queries the database.
Complete, permanent, and **one to two seconds behind** the sensor, because the
reading travelled spool → forwarder → API → database first.

**The live path** — acquisition publishes each reading to Redis *the instant it
is decoded*, bypassing spool and forwarder entirely. Redis → live-bridge →
Reverb → browser over a websocket. Instant, and **deliberately lossy**:

> *"It is explicitly lossy: if Redis is down, or slow … a call in the poll loop
> would turn a Redis hiccup into missed polls."* — `acquisition/src/qv_acq/live.py`

That is why the badge on the Live page matters. `websocket` means the fast path;
`polling` means the durable one — which is why the 2-second window could not work
while Reverb was misconfigured. **Two seconds is shorter than the durable path's
own latency.**

| Page | Path | Why |
|---|---|---|
| Live, 2 sec / 5 sec | websocket | must be instant |
| Live, 1 min and wider | API → database | must be complete |
| Structure, Events, History, reports | API → database | must be permanent and correct |

The rule (ADR-020): **the lossy feed sits alongside the durable one, never
instead of it.** A dropped live frame costs nothing, because the same reading is
already on its way to the database by the slower route.

---

## 7. What is a watchdog, and how did it kill a healthy process 312 times?

> *Explaining `drain_once(on_batch=...)` — why the hook exists at all.*

### What a watchdog is

A watchdog is a supervisor that assumes **silence means death**.

```ini
WatchdogSec=120      # deploy/systemd/quakevault-forwarder.service
```

That line tells systemd: *the forwarder must check in at least every two minutes.
If it does not, it is hung — kill it and restart it.*

The process checks in by calling `notifier.watchdog()`. In watchdog jargon this
is "petting the dog". Miss a pet and you are assumed dead.

This is good and normal. A hung process holding a serial port or a lock is worse
than a dead one, so you want it killed.

### What went wrong

**The forwarder pet the dog once per *drain cycle*, not once per batch.**

```python
result = self.forwarder.drain_once()   # ← all the work happens inside here
self.notifier.watchdog()               # ← pet the dog only after it returns
```

Now look at how long `drain_once()` actually takes, because that is the bug:

| Situation | Records | Batches (200 each) | Time inside `drain_once()` |
|---|---|---|---|
| Normal | ~3 | 1 | well under a second |
| After the 16-hour outage | **84,000** | **422** | **~7 minutes** |

Normally the function returns instantly, pets the dog, and everyone is happy.
After the outage it had seven minutes of genuine work to do before it could
return.

At the two-minute mark, systemd saw silence and killed it:

```
Watchdog timeout (limit 2min)!
Killing process 8918 (python) with signal SIGABRT
```

Restarted five seconds later. Started draining. Killed again at two minutes.
Over and over, for the entire recovery — `NRestarts` reached **312**.

### Why it was hard to see

Three things made it look like something else entirely:

**Progress survived.** Delivery commits after each batch, so each two-minute
window still delivered around 120 batches. Data was moving the whole time.

**The metrics were frozen.** They were also written once per drain — which never
completed. So the operator saw `delivered 0, backlog 187671` while 59,000 rows a
minute were going into the database.

**It looked like a crash loop.** A process being SIGABRTed every two minutes
reads as "broken", not "working hard". It was first diagnosed as the forwarder
being dead. It was the opposite.

### The point

Read the timeout again: **two minutes.** Ask where that number came from.

Somebody looked at the forwarder doing its normal job — a fraction of a second
per cycle — and picked two minutes as a generous margin. It *is* generous, by a
factor of hundreds.

But it was calibrated against the **typical** case. And this component's worst
case is not "something is wrong", it is **"recovering from something that was
wrong"** — which takes hundreds of times longer than normal and is precisely when
you least want it killed.

> The watchdog was armed against the one situation the spool exists to handle.

Think of a night watchman required to phone in every two minutes. Fine on a quiet
night. Then the building floods, he spends seven minutes carrying equipment
upstairs, and is fired for sleeping — on the one shift where he was working
hardest.

### The fix

Pet the dog **per batch**, not per drain:

```python
def progress(partial):
    self.notifier.watchdog()      # still alive, still working
    self.write_metrics(partial)   # and here is proof

result = self.forwarder.drain_once(on_batch=progress)
```

"Alive" now means *I completed a batch in the last two minutes* — true throughout
a seven-minute drain, false if it genuinely hangs. The watchdog still does its
job; it just measures the right thing.

That is what `on_batch` is for. Not a callback for tidiness: without it, systemd
killed a healthy process 312 times.

### The general rule

> **A supervisor's definition of "alive" must match the component's real work
> distribution — including its worst case, which is usually the recovery case.**

The practical version, whenever you set a watchdog or a timeout:

1. What is the **longest legitimate** thing this can do between check-ins?
2. Is that number from the normal case or the worst case?
3. **The worst case is almost always recovery** — draining a backlog, replaying a
   log, rebuilding an index, resyncing after a disconnect.

If the answer to (2) is "normal", you have armed a booby trap that fires only
during an emergency.

The same trap appears in AUTOSAR (a watchdog sized for the main loop, then a DTC
flush after a fault takes longer), in RTOS firmware (a task that must feed the
watchdog but occasionally erases a flash sector), and in Kubernetes liveness
probes killing pods during slow startup.

**Design your buffers for the drain, not the fill — and your watchdogs for the
recovery, not the routine.**

---

## Where this pattern appears elsewhere

The shape has a name — **store-and-forward** — and it turns up wherever something
cannot be re-measured:

- flight data recorders
- SCADA historians buffering to local flash when the link to the server drops
- a CAN bus ECU holding fault codes until the next service visit
- the Linux kernel's `printk` ring buffer, which reports `** N messages dropped **`
  rather than dropping silently

Worked through in detail in
[docs/embedded-course/lesson-01-the-spool.md](docs/embedded-course/lesson-01-the-spool.md).

---

## See also

| Document | What it holds |
|---|---|
| `docs/embedded-course/` | Twelve lessons on the engineering decisions in this repo |
| `fixproblem.md` | Faults found and how each was fixed |
| `docs/known-limitations.md` | Everything this appliance cannot do, and why |
| `docs/decision-log.md` | 25 ADRs — the decisions and what each cost |
