#!/usr/bin/env bash
#
# Does the whole appliance come back by itself?
#
# Run this after a real reboot. Not a simulation: enablement can be checked at
# any time, and it was — acceptance case 13 read "PASS, all four units enabled"
# for weeks while the dashboard was two processes started by hand in a terminal.
# The case was true and the appliance was still headless after a power cut,
# because the check knew about four units and the product needed five.
#
# So this asks the question from the outside: is the screen showing data.
#
# Non-destructive. Reads only.

set -uo pipefail

PASS=0
FAIL=0

ok()   { printf '  \033[32mPASS\033[0m  %-42s %s\n' "$1" "${2:-}"; PASS=$((PASS + 1)); }
bad()  { printf '  \033[31mFAIL\033[0m  %-42s %s\n' "$1" "${2:-}"; FAIL=$((FAIL + 1)); }

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="${QUAKEVAULT_BASE_URL:-http://127.0.0.1:8000}"

echo
echo "Post-reboot acceptance"
echo "  up since: $(uptime -p)"
echo "  booted:   $(uptime -s)"
echo "  against:  $BASE"
echo

echo "Units that must start themselves"

# Started at boot, or started by hand afterwards?
#
# "enabled and active" is the check that let acceptance case 13 pass for weeks
# while the appliance did not come back. It stays true if an operator - or
# somebody debugging - starts the unit ten minutes later, which is exactly what
# happened during the 2026-08-06 investigation: every unit read green on a
# machine that had come up with three dead containers.
#
# So each unit is compared against the boot clock. Anything that started more
# than a grace period after boot did not come back on its own, whatever its
# current state says.
BOOT_EPOCH=$(date -d "$(uptime -s)" +%s)
GRACE=180

for unit in quakevault-stack quakevault-acq quakevault-forwarder quakevault-dashboard \
            quakevault-scheduler.timer; do
    enabled=$(systemctl is-enabled "$unit" 2>&1)
    active=$(systemctl is-active "$unit" 2>&1)

    if [[ "$enabled" != "enabled" || "$active" != "active" ]]; then
        bad "$unit" "$enabled, $active"
        continue
    fi

    started=$(systemctl show "$unit" -p ActiveEnterTimestamp --value 2>/dev/null)
    started_epoch=$(date -d "$started" +%s 2>/dev/null || echo 0)
    delay=$(( started_epoch - BOOT_EPOCH ))

    # A timer is "active/waiting"; a service is "active/running". Both count.
    if (( started_epoch == 0 )); then
        ok "$unit" "$enabled, $active (start time unknown)"
    elif (( delay <= GRACE )); then
        ok "$unit" "came up ${delay}s after boot"
    else
        bad "$unit" "started ${delay}s after boot - by hand, not at boot"
    fi
done

echo
echo "The data plane"
# Added after the second outage, and the reason for it.
#
# The first version of this script checked units, the dashboard and the sensors,
# and would have passed while TimescaleDB and Redis were dead: acquisition would
# still be recording to the spool, the units would all be active, and the
# dashboard would be throwing RedisException at every visitor. Yesterday's
# lesson was to finish at the user. This one is to check the floor as well as
# the roof.
for container in qv-timescaledb qv-redis qv-mosquitto; do
    state=$(docker inspect "$container" --format '{{.State.Status}}' 2>/dev/null)
    [[ "$state" == "running" ]] \
        && ok "$container" "running" \
        || bad "$container" "${state:-not found} - the stack did not come back"
done

echo
echo "The screen"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/" 2>/dev/null)
case "$code" in
    200) ok "dashboard answers" "HTTP 200" ;;
    503) bad "dashboard answers" "HTTP 503 - built assets missing, run deploy/build-dashboard.sh" ;;
    500) bad "dashboard answers" "HTTP 500 - usually Redis or the database; check the data plane above" ;;
    *)   bad "dashboard answers" "HTTP ${code:-no response}" ;;
esac

# The bundle, not just the shell. A 200 on index.html with a missing asset is a
# white screen, and a white screen is what a client actually experiences.
asset=$(curl -s --max-time 10 "$BASE/" 2>/dev/null \
        | grep -o 'assets/[^"]*\.js' | head -1)

if [[ -n "$asset" ]]; then
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/$asset")
    [[ "$code" == "200" ]] \
        && ok "dashboard bundle loads" "$asset" \
        || bad "dashboard bundle loads" "$asset returned $code"
else
    bad "dashboard bundle loads" "index.html names no bundle"
fi

# 401 is the right answer to an unauthenticated call. 200 would mean the
# catch-all had swallowed the API and was serving HTML to everything.
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/api/v1/sensor-health")
[[ "$code" == "401" ]] \
    && ok "API answers, still authenticated" "HTTP 401 unauthenticated" \
    || bad "API answers, still authenticated" "HTTP $code (expected 401)"

echo
echo "Data actually flowing since boot"
# The point of the appliance. Units can be active and reading nothing.
#
# Asked through the application rather than psql: the database credentials live
# in backend/.env at mode 600, and a check that needs them copied somewhere else
# is a check that will be run with the wrong ones.
ask() {
    (cd "$REPO/backend" && php artisan tinker --execute="echo $1;" 2>/dev/null | tail -1 | tr -d ' \r')
}

recent="SELECT count(*) c FROM measurements WHERE time > now() - interval '5 minutes'"
rows=$(ask "DB::selectOne(\"$recent\")->c")

if [[ "$rows" =~ ^[0-9]+$ && "$rows" -gt 0 ]]; then
    ok "measurements in the last 5 minutes" "$rows rows"
else
    bad "measurements in the last 5 minutes" "${rows:-query failed}"
fi

distinct="SELECT count(DISTINCT sensor_id) c FROM measurements WHERE time > now() - interval '5 minutes'"
sensors=$(ask "DB::selectOne(\"$distinct\")->c")

if [[ "$sensors" =~ ^[0-9]+$ && "$sensors" -ge 3 ]]; then
    ok "all three sensors reporting" "$sensors distinct"
else
    bad "all three sensors reporting" "${sensors:-query failed} reporting, expected 3"
fi

# Latency is survivable; loss is not. The spool absorbed a sixteen-hour outage
# without losing a reading, and the only number that would have said otherwise
# is this one.
dropped=$(sudo -u quakevault-acq "$REPO/.venv/bin/qv-spool" status 2>/dev/null \
          | grep -c "LOST" || true)
[[ "$dropped" == "0" ]] \
    && ok "no readings dropped at the spool cap" "nothing lost" \
    || bad "no readings dropped at the spool cap" "run: qv-spool status"

# The scheduler is the only thing that evaluates settlement. It was absent for
# weeks and nothing looked wrong; see deploy/systemd/quakevault-scheduler.service.
last=$(systemctl show quakevault-scheduler.service -p ExecMainExitTimestamp --value 2>/dev/null)
[[ -n "$last" ]] \
    && ok "scheduler has ticked since boot" "$last" \
    || bad "scheduler has ticked since boot" "no run recorded"

echo
if (( FAIL == 0 )); then
    printf '\033[32m%d passed, appliance came back on its own.\033[0m\n\n' "$PASS"
    exit 0
fi

printf '\033[31m%d passed, %d failed.\033[0m\n\n' "$PASS" "$FAIL"
exit 1
