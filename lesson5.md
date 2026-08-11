# Lesson 5 — The Forwarder

**Module:** `acquisition/src/qv_acq/forwarder.py`, `forwarder_service.py`
**Related decisions:** ADR-003, ADR-010

---

## Why it exists

The spool (Lesson 1) is a promise: *an outage costs latency, not data*. The forwarder is the code that has to keep it.

Its docstring states the separation plainly:

> Runs as its own process, separate from acquisition. That separation is the point: a hung HTTP request, an expired token or a database outage must never stall the serial polling loop.

Two processes, not two threads. ADR-003 explains why acquisition never enters a container at all: container restarts must not interrupt polling, and a serial device inside a container widens the blast radius of a web-tier compromise to physical hardware.

**Isolation is measured in what a fault cannot reach.** A thread shares an address space and a fate. A process shares neither.

Note also the dependency discipline:

> Uses only the standard library. The appliance ships a Python environment we control, and one fewer dependency in the path between a sensor and its data is worth more than a nicer HTTP client.

That sentence would be pretentious in a web service. On an appliance that must run untouched for years on a client's site, it is correct: every dependency in the critical path is a future CVE, a future breaking change, and a future thing you cannot debug at 2 a.m.

---

## What problem it solves

### 1. Not all failures deserve the same response

This is the central intelligence of the module. Read `drain_once()`:

```python
if status in (401, 403):
    # Credentials will not fix themselves. Stop rather than burn the
    # retry budget and push good measurements toward dead-letter.
    result.stopped_reason = f"authentication failed (HTTP {status})"
    result.fatal = True
```

and in the service:

```python
if result.fatal:
    # A rejected token will not fix itself. Exit 2 so systemd stops
    # rather than restart-looping against a credential problem.
```

matched by the unit file:

```ini
RestartPreventExitStatus=2
```

Three layers agreeing on one idea: **classify failures by whether retrying can possibly help.**

- A 500, a timeout, a refused connection — *transient*. Retry with backoff.
- A 401 — *permanent*. Retrying is not merely useless, it is harmful: every attempt burns retry budget on healthy records and pushes them toward the dead-letter ceiling.

A retry loop that cannot tell these apart converts a five-minute credential problem into permanent data loss. That is not hypothetical here — it is structurally the same mechanism that stranded 31,307 readings during the real outage.

### 2. Delivery must be at-least-once, and the consumer idempotent

Nothing in this design attempts exactly-once. It cannot: the forwarder can crash in the window between the API committing and the spool recording `delivered_at`. So the system is built to make that window harmless.

```python
payload = {
    "batch_uid": records[0].idempotency_key[:64],
    "measurements": [record.payload for record in records],
}
...
# 2xx means the batch is durably recorded. Duplicates are a success:
# they mean a previous attempt landed before we could mark it delivered.
```

**"Duplicates are a success."** That single comment shows a designer who has thought the failure through rather than hoping it away. The key comes from ADR-010:

`appliance:run_id:sensor:group:sequence`

And ADR-010 exists because of a real, counted bug:

> Sequence numbers restart at 1 whenever the service restarts. With a key of `appliance:sensor:group:sequence`, the first measurements after every restart collided with the previous run's and were rejected as duplicates. This was not theoretical: the live spool had already lost **98 real measurements** across three restarts, counted under `duplicates_rejected` and otherwise invisible.

That is the whole discipline in one paragraph. The deduplication mechanism — the thing protecting data — was itself destroying data, and the *only* reason anyone found out is that the drop was counted. Lesson 1's rule, arriving with a body count: **for every way data can be lost, a number must go up.**

### 3. Retries must be bounded, and boundedness has a cost

```python
self.spool.mark_failed([r.id for r in records], f"HTTP {status}")
result.failures += 1
self._consecutive_failures += 1
```

with `backoff_delay()` growing on consecutive failures, and a `max_retries` ceiling beyond which records become dead letters.

The ceiling exists so one poison record cannot block the queue behind it forever. But — and this is the lesson the outage taught — **the ceiling cannot distinguish a poison record from a healthy one that met a long outage.** Sixteen hours of failures burned the budget of perfectly good readings.

There is no clean fix. Every bounded-retry system has this ambiguity. What the design *can* do is make the resulting state recoverable and visible, which is why `qv-spool retry-dead-letters` exists and why it is manual.

### 4. Long recovery must not look like death

Covered in Lesson 1, restated here because it belongs to this module:

```python
def drain_once(self, on_batch: Callable[[ForwardResult], None] | None = None):
```

The hook exists because `drain_once()` loops until the spool is empty, and after a real outage that is minutes of honest work — against a `WatchdogSec=120`. The watchdog killed the recovery every two minutes.

**A supervisor's liveness definition must match the component's real work distribution — including its worst case, which is usually the recovery case.** Lesson 7 goes deeper.

---

## What would happen if it were removed

| Remove | Consequence |
|---|---|
| Separate process | A hung HTTP request stalls serial polling. Lesson 1's guarantee is void. |
| Fatal/transient classification | A bad token restart-loops forever, burning retry budget until healthy data is dead-lettered. |
| Idempotency key | Every crash-replay double-inserts, or (with the pre-ADR-010 key) silently drops. Both happened. |
| `run_id` in the key | The 98-measurement loss, on every restart, forever, invisible. |
| Retry ceiling | One undeliverable record blocks everything behind it permanently. |
| Per-batch heartbeat | Every long recovery is killed by its own watchdog. |

---

## The embedded principles

**1. Fault isolation is measured by what a fault cannot reach.** Process boundaries, dedicated service accounts, no serial devices in containers.

**2. Classify errors by recoverability, not by severity.** The useful axis is "can retrying help?", not "how bad is it?".

**3. Exactly-once is a fiction; build at-least-once plus idempotence.** Then make duplicates cheap and *count* them.

**4. Every bound needs an escape hatch.** A retry ceiling without a way to release what it caught is a data-loss mechanism with good intentions.

**5. Minimise dependencies in the critical path.** On an appliance, a dependency is a liability you inherit for the product's lifetime.

---

## Design patterns in play

| Pattern | Where |
|---|---|
| **Competing consumer / queue drain** | `drain_once()` |
| **Exponential backoff** | `backoff_delay()` |
| **Circuit breaker (coarse)** | `fatal` → exit 2 → supervisor stops |
| **Dead letter queue** | `max_retries` ceiling |
| **Idempotent receiver** | `batch_uid` + `idempotency_key` |
| **Epoch / generation counter** | `run_id` |
| **Progress callback for liveness** | `on_batch` |

---

## Where you will meet this again

### Automotive
- **CAN error confinement** is this module in silicon. Every controller keeps TEC/REC counters; at 128 it goes *error-passive* (backs off, still participates); at 256 it goes *bus-off* (removes itself entirely) and requires a defined recovery sequence. Transient errors are tolerated, persistent ones cause self-removal so one sick node cannot destroy the bus. That is bounded retry, backoff, and circuit breaking, standardised in 1991.
- **UDS response `0x78` (requestCorrectlyReceived-ResponsePending)** exists so a tester distinguishes "slow" from "broken" — the same distinction as backlog versus stopped.

### Aerospace
- **ACARS / CPDLC** store-and-forward with acknowledgement and retransmission over intermittent links.
- **Store-and-forward telemetry on spacecraft**: no ground contact for hours, buffer, then dump with sequence numbers and gap-fill requests. Deep-space missions live and die on idempotent replay.

### Industrial / PLC
- **SCADA historian store-and-forward** with replay on reconnect — the direct commercial equivalent.
- **OPC UA subscriptions** with queue size, discard policy and keep-alive; sequence numbers allow republish of missed notifications.
- **MQTT Sparkplug B** uses a sequence number and a "birth/death" certificate so a consumer can detect a gap rather than assume continuity.

### Robotics
- **ROS 2 / DDS reliable QoS** with a bounded writer history: retransmit until acknowledged or until the history overflows — bounded retry with an explicit overflow policy.
- **rosbag2 recording** to disk while the network is unavailable.

### Linux kernel
- **TCP retransmission with exponential backoff**, and `tcp_retries2` as the ceiling past which the connection is declared dead. Also `ETIMEDOUT` vs `ECONNREFUSED` — permanent versus transient, at the syscall level.
- **`netdev_watchdog`** fires when a transmit queue stalls, and the driver resets the ring — supervisor-driven recovery.
- **USB transfer retries and port reset escalation**: retry, then reset the endpoint, then reset the port, then give up and unbind.

### RTOS firmware
- **LoRaWAN confirmed uplinks** — retry with backoff and a duty-cycle cap; the cap is a hard ceiling exactly like `max_retries`.
- **Flash write retry with bad-block marking** — a block that keeps failing is retired, which is a dead letter for storage.
- **Task heartbeats to a supervisor task** during long operations, so a firmware update does not trip the watchdog mid-write. The exact bug this repo hit.

---

## The connective tissue

Lessons 1–4 built the acquisition side: capture reliably, schedule correctly, decode carefully, describe honestly. Lesson 5 is the first module whose job is to talk to something it does not control.

The mental shift matters. Inside the appliance you can guarantee things. Across a boundary you can only *assume, verify and recover*. Every design decision in this file is an answer to "what do I do when the other side does not behave?" — and the best answer is almost never "try again immediately, forever".

---

## Design questions

**1.** The forwarder treats 401/403 as fatal and exits 2 so systemd stops restarting. But a token can also be *rotated* — briefly invalid, then valid. Your appliance now stops permanently on a transient credential blip and needs a site visit. Redesign the policy. What distinguishes "rotating" from "revoked" from the client's side, and what does your answer cost in the revoked case?

**2.** Batches carry `batch_uid = records[0].idempotency_key[:64]` — the key of the *first* record. Under what re-batching scenario does that produce a batch_uid collision or a false duplicate? Propose a better batch identity and say what it costs.

**3.** The retry ceiling cannot distinguish poison from outage. Design an automatic discriminator — perhaps error-class history, or the failure rate of *other* records at the same moment. Then argue whether an appliance like this should ship it, given that a wrong automatic decision here silently discards structural evidence.

**4.** ADR-003 puts acquisition on the host and everything else in containers, accepting "two deployment surfaces" as the cost. Suppose you must now deploy 400 of these appliances. Does that cost change your answer? Design the deployment model you would actually run at that scale, and be explicit about what you would give up.

Design questions
1. The forwarder treats 401/403 as fatal and exits 2 so systemd stops restarting. But a token can also be rotated — briefly invalid, then valid. Your appliance now stops permanently on a transient credential blip and needs a site visit. Redesign the policy. What distinguishes "rotating" from "revoked" from the client's side, and what does your answer cost in the revoked case?

2. Batches carry batch_uid = records[0].idempotency_key[:64] — the key of the first record. Under what re-batching scenario does that produce a batch_uid collision or a false duplicate? Propose a better batch identity and say what it costs.

3. The retry ceiling cannot distinguish poison from outage. Design an automatic discriminator — perhaps error-class history, or the failure rate of other records at the same moment. Then argue whether an appliance like this should ship it, given that a wrong automatic decision here silently discards structural evidence.

4. ADR-003 puts acquisition on the host and everything else in containers, accepting "two deployment surfaces" as the cost. Suppose you must now deploy 400 of these appliances. Does that cost change your answer? Design the deployment model you would actually run at that scale, and be explicit about what you would give up.
please answer the question


Ran 3 commands
Before answering Q2 I checked the live data, and it isn't hypothetical — the collision is happening now.

Lesson 5 — my answers
Q1. Token rotation versus revocation
The honest starting point
From the client side, you cannot distinguish them. Rotation and revocation both return 401. There is no field, no header, no timing signature that reliably separates "this credential is being replaced right now" from "this credential is dead for ever."

You could ask the server to say which — token_expired vs token_revoked — but you cannot depend on it. If the appliance was stolen, the server may deliberately decline to explain.

So stop trying to classify the response. The discriminator is not the answer, it is the duration. Rotation resolves in seconds or minutes. Revocation never does.

The redesign
Replace "exit immediately" with bounded escalation:

Elapsed	Behaviour
0 – 5 min	retry with exponential backoff, quietly
5 min – 24 h	keep retrying at a 5-minute cap; raise a local alarm, publish to MQTT, show it on the dashboard
beyond 24 h	exit 2, systemd stops, site visit warranted
Throughout: acquisition never stops and the spool keeps filling. A credential problem must cost delivery, not data — the same trade as every other outage.

What it costs in the revoked case
A genuinely revoked appliance keeps knocking for 24 hours. About 300 requests a day at the backoff cap — negligible load.

The real question is whether that delay matters, and here the answer is unusually clean: the token carries the ingest ability only. A revoked appliance still trying can offer data nobody accepts. It cannot read history, change configuration, or delete anything.

Revocation of an append-only credential is not urgent. Twenty-four hours of futile knocking costs nothing that matters.

If the token could read, I would answer differently — the window would need to be minutes, and I would accept the site visits.

And one thing about the current design regardless: exit 2 requires a human either way. Between silently absent and loudly broken, loudly broken is strictly better — the appliance should stay up, keep spooling, and keep saying what is wrong.

Q2. batch_uid — the collision is live
The re-batching scenario in the question is real, but there is a simpler defect underneath it. Checked on your appliance just now:

And in the recorded data:

Truncated mid-group-key. The sequence number — the only part that varies between batches — is cut off entirely.

So every batch from that (appliance, run, sensor, group) shares one batch_uid. With insertOrIgnore, only the first is ever recorded. 551 batch rows exist for hundreds of thousands of delivered records.

What is actually damaged
Not your measurements. Those deduplicate on their own full key in ingested_polls, which is not truncated. The audit trail is damaged — ingest_batches is supposed to record what was offered, accepted, rejected and when, and most of it was silently discarded.

That is the table you would reach for to answer "what did the appliance send us on the night of the 4th?"

The re-batching case, which also holds
After retry-dead-letters revives records, or after the cap prunes some, pending() can return a different 200 records that happen to start with the same record. Same batch_uid, different contents — and the surviving audit row describes the wrong batch. If the first was partial and the second fully accepted, the record says partial for ever.

Better identity
A content hash. Identical retries collide — which is correct, that is the same batch. Different contents never collide. And 64 hex chars fits exactly.

Cost: you can no longer tell two genuine retries apart, so per-attempt timing analysis needs a second field. If you want both, keep a random attempt_uid alongside the content hash. Hashing 200 short strings is free.

This one is a live defect, not an exercise. Want it fixed?

Q3. Automatic poison/outage discriminator
The mechanism, in increasing cost and confidence:

Error class. 4xx (except 408/429) means the record is wrong and will never succeed — dead-letter on first failure, not the tenth. 5xx/network means the sink is wrong and must not count against the record at all.
Cohort correlation. Increment retry_count only when the failure was idiosyncratic — other records in the same batch or window succeeded. Whole-batch failure increments a sink counter and freezes per-record ones.
Bisection. Batch of 200 fails while the sink is demonstrably healthy? Split and retry halves. Eight round trips isolate one record, and that is a proof rather than an inference.
Should it ship, given that a wrong decision discards structural evidence?
The premise deserves challenging first: does it discard anything?

Dead-lettering does not delete. It parks. A wrong classification costs delay, not data — provided nothing downstream deletes parked records.

Except something does. The spool cap evicts delivered rows first, then undelivered oldest-first — and a parked record is undelivered. Park 31,307 records, let the spool fill, and they are dropped as ordinary backlog with no indication they were special.

So the answer is conditional, and the condition is the interesting part:

Make parked records exempt from cap eviction — or evicted last, with their own counter — before you automate the classification at all.

With that in place, ship tiers 1–3. Their worst outcome is a wrong label on data you still hold, and a wrong label is recoverable by a human with qv-spool retry-dead-letters.

Without it, ship none of them. Automating a decision whose errors are irreversible is how you lose evidence quietly.

Automation is safe exactly to the degree that being wrong is reversible. Make it reversible first, then automate.

Automatic release of dead letters stays manual regardless — for the reason in Lesson 1: it would have silently repaired the 31,307 stranded readings, and nobody would have learned that a long outage burns the retry budget of healthy data.

Q4. Four hundred appliances
Does the cost change the answer?
Not the principle. Entirely the mechanism.

ADR-003's reasoning is physical: serial devices must not be reachable from a web tier, and a container restart must not interrupt polling. At 400 sites that argument gets stronger, because you cannot drive to a site to unwedge an appliance.

What breaks at 400 is not the architecture — it is install-acquisition.sh. Hand-run scripts across 400 machines produce 400 subtly different machines, and by year two nobody knows what any of them is running.

What I would actually run
An immutable OS image. The whole appliance built as one artefact — acquisition, its venv, udev rules, systemd units, container digests. No per-site package installation, ever.
A/B partitions with automatic rollback. Update writes the inactive slot, reboots into it, and rolls back if a post-boot health check fails. acceptance/post-reboot.sh is already the seed of that check — it just needs to gate the commit.
Containers pinned by digest inside the image, never pulled at boot. A site with no internet must still come up. (The 2026-08-06 outage was a mild version of this.)
Configuration on a separate persistent partition. /etc/quakevault, baselines, fingerprints, calibration — an image update must never touch the sensor map.
Outbound fleet telemetry: image version, unit states, spool health, undelivered_dropped. You cannot poll 400 sites; they must report.
No per-site config management. Convergence by image, not by Ansible over SSH.
What I would give up, explicitly
The ability to fix one appliance quickly. No hot patching — a fix is a new image and a reboot. That is the point, and it will hurt badly the first time a client is waiting.

This session is the honest counter-example. Several real bugs were found and fixed here in minutes by editing a file and restarting a unit. At 400 units that same workflow is precisely what produces 400 divergent appliances, and it has to go.

Per-site customisation. Anything site-specific must be expressible as data in the config partition. Whatever cannot be becomes a new image variant, and once you have forty variants you have lost the entire benefit.

Disk, doubled by A/B. Cheap and worth it.

Development speed. You must keep a developer path that does not require building an image — and then accept that the developer path and the production path differ, which is its own risk.

The trade in one line: you give up the ability to change one appliance, in exchange for knowing what all four hundred are running. Below about ten units that is a bad trade. Above about fifty it is the only one that works.



---

*Next: Lesson 6 — Identity: naming devices, owning ports, and the 98 measurements that vanished.*
