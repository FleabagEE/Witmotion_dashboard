# Engineering notes — QuakeVault Industrial

Generated from commit `618196d` on branch `master` (2026-08-12) by
`tools/make-learning-bundle.sh`. **Regenerate rather than edit** — anything
changed here is lost on the next run, and a hand-edited copy drifts from the
repository without saying so.

This is a three-sensor structural-health-monitoring appliance for a silo:
WTVB01-485 accelerometers on Modbus RTU over RS-485, a Python acquisition
service, a store-and-forward spool, and a Laravel/React dashboard.

## Start here

| Document | Why |
|---|---|
| [fixproblem.md](fixproblem.md) | Eleven rounds of real defects: what broke, the attempts that failed, and what the failure taught. The most useful document here. |
| [question.md](question.md) | Architecture in plain language, written in answer to questions asked while reading the code. |
| [decision-log.md](decision-log.md) | 26 ADRs. Every one states what the decision **cost**, which is the part usually left out. |
| [known-limitations.md](known-limitations.md) | Everything the appliance cannot do, and why. |

## The course

Twelve lessons planned, six written. Each takes one module and asks why it
exists, what breaks without it, and where the same pattern appears in
automotive, aerospace, industrial PLCs, robotics, kernel drivers and RTOS work.

| Lesson | Subject |
|---|---|
| [1](course/lesson-01-the-spool.md) | The spool — store-and-forward, and why a disk write sits between irreversible and retryable work |
| [2](course/lesson-02-the-acquisition-engine.md) | The acquisition engine — threads, buses, breakers, backpressure |
| [3](course/lesson-03-the-wire.md) | The wire — CRC, decoding, and refusing to trust a register map |
| [4](course/lesson-04-profiles-as-data.md) | Profiles as data — why a wrong map produces plausible numbers |
| [5](course/lesson-05-the-forwarder.md) | The forwarder — retry, backoff, circuit breaking, dead letters |
| [6](course/lesson-06-identity.md) | Identity — knowing which sensor is which, and why that is hard |

## Evidence

| Document | Why |
|---|---|
| [acceptance-results.md](acceptance-results.md) | 22 hardware-in-the-loop cases, including the ones that failed and what the failures cost |
| [register-maps.md](register-maps.md) | Probe transcripts. Two register-map faults found by moving a sensor and watching which words changed |
| [architecture-summary.md](architecture-summary.md) | The pipeline end to end |
| [testing.md](testing.md) | What is tested, and how |

## What is not here, and why

Source code, deployment and troubleshooting runbooks, the operator and
administrator manuals, sensor profiles, systemd units, udev rules and compose
files are all excluded. They belong to a client installation rather than to the
engineering reasoning, and none of them teaches anything the documents above do
not.

## If you are reading this as a hiring manager

The interesting reading is `fixproblem.md`. It is not a list of fixes; it is a
record of what was believed, how it turned out to be wrong, and what was
measured to find out — including several cases where a test passed for the wrong
reason and had to be rebuilt before it meant anything.
