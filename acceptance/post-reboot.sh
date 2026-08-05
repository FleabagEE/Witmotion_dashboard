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
for unit in quakevault-acq quakevault-forwarder quakevault-dashboard \
            quakevault-scheduler.timer; do
    enabled=$(systemctl is-enabled "$unit" 2>&1)
    active=$(systemctl is-active "$unit" 2>&1)

    # A timer is "active/waiting"; a service is "active/running". Both count.
    if [[ "$enabled" == "enabled" && "$active" == "active" ]]; then
        ok "$unit" "$enabled, $active"
    else
        bad "$unit" "$enabled, $active"
    fi
done

echo
echo "The screen"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$BASE/" 2>/dev/null)
case "$code" in
    200) ok "dashboard answers" "HTTP 200" ;;
    503) bad "dashboard answers" "HTTP 503 - built assets missing, run deploy/build-dashboard.sh" ;;
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
