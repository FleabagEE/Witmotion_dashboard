#!/usr/bin/env bash
#
# Assemble the documents that carry the engineering reasoning, and nothing else.
#
# WHY THIS IS A SCRIPT AND NOT A FOLDER
# -------------------------------------
#
# A bundle copied by hand is a second source of truth that drifts silently, and
# nothing ever tells you it has. This repository already has that problem in a
# neighbouring project: three near-identical DEPLOYMENT_CHECKLIST.md files, two
# of them the same length, in differently-named directories.
#
# `fixproblem.md` gained four rounds in the week this was written. A copy taken
# on Monday is wrong by Friday and looks fine. So: regenerate, never copy.
#
# WHAT IS IN IT
# -------------
#
# Documents whose value is the reasoning rather than the appliance:
# the course, the defect record, the decision log with its costs, the stated
# limitations, and the acceptance evidence.
#
# WHAT IS DELIBERATELY NOT IN IT
# ------------------------------
#
#   * source code - the client's, not ours to circulate;
#   * runbooks (deployment, troubleshooting, operator and administrator
#     manuals) - they teach this appliance rather than engineering, and they are
#     the parts that read as an operational map of a client installation;
#   * profiles, systemd units, udev rules, compose files - configuration
#     describing a specific installation;
#   * memory.md and TASKS.md - working notes, not teaching.
#
# The split is not only about confidentiality. The reasoning documents are also
# simply the useful ones: nobody is asked "describe your deployment procedure"
# in an interview, and everybody is asked "tell me about a bug you found".
#
# Usage:
#   tools/make-learning-bundle.sh [destination]
#
# Default destination is ./learning-bundle, which is gitignored.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="${1:-$REPO/learning-bundle}"

cd "$REPO"

# Everything the bundle contains, as source paths. Anything not listed here is
# excluded by default, which is the safe direction for a bundle that leaves the
# machine: a new document has to be considered before it travels.
DOCS=(
    docs/embedded-course/README.md
    docs/embedded-course/lesson-01-the-spool.md
    docs/embedded-course/lesson-02-the-acquisition-engine.md
    docs/embedded-course/lesson-03-the-wire.md
    docs/embedded-course/lesson-04-profiles-as-data.md
    docs/embedded-course/lesson-05-the-forwarder.md
    docs/embedded-course/lesson-06-identity.md
    fixproblem.md
    question.md
    docs/decision-log.md
    docs/known-limitations.md
    docs/acceptance-results.md
    docs/register-maps.md
    docs/architecture-summary.md
    docs/testing.md
)

missing=()
for f in "${DOCS[@]}"; do
    [[ -f $f ]] || missing+=("$f")
done

if (( ${#missing[@]} )); then
    printf 'Refusing to build an incomplete bundle. Missing:\n' >&2
    printf '  %s\n' "${missing[@]}" >&2
    printf '\nA bundle that quietly omits a file is worse than no bundle: it looks\n' >&2
    printf 'complete. Fix the list in this script, or the missing document.\n' >&2
    exit 1
fi

rm -rf "$DEST"
mkdir -p "$DEST/course"

for f in "${DOCS[@]}"; do
    case "$f" in
        docs/embedded-course/*) cp "$f" "$DEST/course/$(basename "$f")" ;;
        *)                      cp "$f" "$DEST/$(basename "$f")" ;;
    esac
done

COMMIT=$(git rev-parse --short HEAD)
DATE=$(git log -1 --format=%cd --date=short)
BRANCH=$(git rev-parse --abbrev-ref HEAD)

cat > "$DEST/INDEX.md" <<EOF
# Engineering notes — QuakeVault Industrial

Generated from commit \`$COMMIT\` on branch \`$BRANCH\` ($DATE) by
\`tools/make-learning-bundle.sh\`. **Regenerate rather than edit** — anything
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

The interesting reading is \`fixproblem.md\`. It is not a list of fixes; it is a
record of what was believed, how it turned out to be wrong, and what was
measured to find out — including several cases where a test passed for the wrong
reason and had to be rebuilt before it meant anything.
EOF

{
    printf '# Manifest\n\n'
    printf 'source commit   %s\n' "$COMMIT"
    printf 'source branch   %s\n' "$BRANCH"
    printf 'commit date     %s\n\n' "$DATE"
    printf '%8s  %s\n' 'LINES' 'FILE'
    printf '%8s  %s\n' '-----' '----'
    find "$DEST" -name '*.md' | sort | while read -r f; do
        printf '%8d  %s\n' "$(wc -l < "$f")" "${f#"$DEST"/}"
    done
    printf '\n%8d  total\n' "$(find "$DEST" -name '*.md' -exec cat {} + | wc -l)"
} > "$DEST/MANIFEST.txt"

# A bundle that leaves the machine gets one last look for the things that must
# never travel. Not a security boundary - the file list above is that - but the
# cheap check that catches a document added to DOCS without thinking.
if grep -rIlnE '(BEGIN [A-Z ]*PRIVATE KEY|glpat-|ghp_|xoxb-|password[[:space:]]*=[[:space:]]*[A-Za-z0-9]{8,})' "$DEST" 2>/dev/null; then
    printf '\nThe files above look like they contain a credential. Bundle left in\n' >&2
    printf 'place for inspection; do not upload it until this is understood.\n' >&2
    exit 2
fi

printf 'Bundle written to %s\n' "$DEST"
printf '  %d documents, %d lines\n' \
    "$(find "$DEST" -name '*.md' | wc -l)" \
    "$(find "$DEST" -name '*.md' -exec cat {} + | wc -l)"
printf '  from commit %s (%s)\n' "$COMMIT" "$DATE"
printf '\nRegenerate with this script rather than editing in place.\n'
