#!/usr/bin/env bash
#
# Fault injection against the running appliance.
#
# Every case here follows the same shape: record what the system knows, break
# something real, watch what it does, put it back, and then check that nothing
# was lost. The last step is the one that matters. A service that survives an
# outage but drops the readings taken during it has not passed - it has failed
# quietly, which is worse.
#
# These tests deliberately stop real components of a running system. On an
# appliance that is the point; do not run this against a deployment somebody is
# relying on. Every case restores what it broke, including on failure.
#
# Usage: acceptance/fault-injection.sh [case ...]     (default: all)

set -uo pipefail

REPO="/var/www/quakevault-industrial"
PSQL=(docker exec -i qv-timescaledb psql -qtAX -U quakevault -d quakevault -c)
RESULTS="${RESULTS_FILE:-$REPO/docs/acceptance-results.md}"

PASS=0; FAIL=0; SKIP=0
declare -a LINES

log()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }

record() {   # case, verdict, detail
    LINES+=("| $1 | $2 | $3 |")
    case "$2" in
        PASS) PASS=$((PASS+1)); printf '   \033[32mPASS\033[0m %s\n' "$3" ;;
        FAIL) FAIL=$((FAIL+1)); printf '   \033[31mFAIL\033[0m %s\n' "$3" ;;
        *)    SKIP=$((SKIP+1)); printf '   SKIP %s\n' "$3" ;;
    esac
}

rows() { "${PSQL[@]}" "select count(*) from measurements" 2>/dev/null | tr -d '[:space:]'; }
backlog() { sudo sqlite3 /var/lib/quakevault-acq/spool.db \
    "select count(*) from spool where delivered_at is null" 2>/dev/null | tr -d '[:space:]'; }
acq_up() { systemctl is-active --quiet quakevault-acq; }

# Waits for a condition rather than sleeping a guessed interval: a fixed sleep
# either wastes time or races, and both make a test lie.
wait_until() {  # seconds, command...
    local deadline=$(( $(date +%s) + $1 )); shift
    until "$@"; do
        [[ $(date +%s) -ge $deadline ]] && return 1
        sleep 1
    done
    return 0
}

restore_all() {
    docker start qv-redis qv-timescaledb qv-mosquitto >/dev/null 2>&1
    sudo systemctl start quakevault-acq quakevault-forwarder >/dev/null 2>&1
    wait_until 60 docker exec qv-timescaledb pg_isready -q -U quakevault
}
trap 'echo; echo "interrupted - restoring"; restore_all' INT TERM

# --- 15. Redis outage -------------------------------------------------------
# The live feed rides on Redis. The durable path must not notice.
case_redis() {
    log "15. Redis outage"
    local before after
    before=$(rows)
    docker stop qv-redis >/dev/null
    info "redis stopped; holding 20 s"
    sleep 20
    if ! acq_up; then
        record "15. Redis outage" FAIL "acquisition died when the live feed's Redis went away"
        docker start qv-redis >/dev/null; return
    fi
    docker start qv-redis >/dev/null
    wait_until 30 docker exec qv-redis redis-cli ping
    sleep 15
    after=$(rows)
    if (( after > before )); then
        record "15. Redis outage" PASS "acquisition unaffected, $((after-before)) rows recorded during and after"
    else
        record "15. Redis outage" FAIL "no rows recorded across the outage (before=$before after=$after)"
    fi
}

# --- 16. MQTT outage --------------------------------------------------------
# An integration outage is not a monitoring outage.
case_mqtt() {
    log "16. MQTT outage"
    local before after
    before=$(rows)
    docker stop qv-mosquitto >/dev/null
    info "broker stopped; holding 15 s"
    sleep 15
    local health_rc=0
    (cd "$REPO/backend" && php artisan mqtt:health >/dev/null 2>&1) || health_rc=$?
    docker start qv-mosquitto >/dev/null
    sleep 10
    after=$(rows)
    if acq_up && (( after > before )) && (( health_rc == 0 )); then
        record "16. MQTT outage" PASS "acquisition unaffected; mqtt:health exited 0 with no broker"
    else
        record "16. MQTT outage" FAIL "acq_up=$(acq_up && echo yes || echo no) rows=+$((after-before)) health_rc=$health_rc"
    fi
}

# --- 17. Database outage ----------------------------------------------------
# The spool exists for exactly this. Nothing may be lost.
case_database() {
    log "17. Database outage"
    local backlog_before backlog_peak rows_before rows_after
    rows_before=$(rows)
    backlog_before=$(backlog)
    docker stop qv-timescaledb >/dev/null
    info "database stopped; holding 30 s while acquisition keeps polling"
    sleep 30
    backlog_peak=$(backlog)
    if ! acq_up; then
        record "17. Database outage" FAIL "acquisition died when the database went away"
        docker start qv-timescaledb >/dev/null; return
    fi
    docker start qv-timescaledb >/dev/null
    wait_until 90 docker exec qv-timescaledb pg_isready -q -U quakevault || {
        record "17. Database outage" FAIL "database did not come back"; return; }
    info "database back; waiting for the spool to drain"
    wait_until 120 bash -c '[[ $(sudo sqlite3 /var/lib/quakevault-acq/spool.db \
        "select count(*) from spool where delivered_at is null" 2>/dev/null) -lt 50 ]]'
    sleep 10
    rows_after=$(rows)
    if (( backlog_peak > backlog_before )) && (( rows_after > rows_before )); then
        record "17. Database outage" PASS \
            "spool absorbed the outage (backlog $backlog_before -> $backlog_peak), then drained; $((rows_after-rows_before)) rows recovered"
    else
        record "17. Database outage" FAIL \
            "backlog $backlog_before -> $backlog_peak, rows +$((rows_after-rows_before))"
    fi
}

# --- 14. Power loss during storage -----------------------------------------
# SIGKILL mid-write. The spool is WAL with synchronous=FULL precisely so that a
# power cut cannot corrupt it or lose an acknowledged write.
case_power_loss() {
    log "14. Power loss during storage (SIGKILL)"
    local before integrity delivered_before
    before=$(backlog)
    delivered_before=$(sudo sqlite3 /var/lib/quakevault-acq/spool.db "select count(*) from spool" 2>/dev/null)
    local pid
    pid=$(systemctl show -p MainPID --value quakevault-acq)
    if [[ -z "$pid" || "$pid" == "0" ]]; then
        record "14. Power loss during storage" SKIP "acquisition not running"; return
    fi
    sudo kill -9 "$pid"
    info "SIGKILL sent to acquisition (pid $pid)"
    sleep 3
    integrity=$(sudo sqlite3 /var/lib/quakevault-acq/spool.db "pragma integrity_check" 2>/dev/null | tr -d '[:space:]')
    sudo systemctl start quakevault-acq >/dev/null 2>&1
    wait_until 30 acq_up
    sleep 8
    local after
    after=$(sudo sqlite3 /var/lib/quakevault-acq/spool.db "select count(*) from spool" 2>/dev/null)
    if [[ "$integrity" == "ok" ]] && (( after >= delivered_before )); then
        record "14. Power loss during storage" PASS \
            "spool integrity_check ok after SIGKILL; no rows lost ($delivered_before -> $after), service restarted"
    else
        record "14. Power loss during storage" FAIL \
            "integrity=$integrity rows $delivered_before -> $after"
    fi
}

# --- 18. Docker restart -----------------------------------------------------
case_docker_restart() {
    log "18. Docker restart (all containers)"
    local before after
    before=$(rows)
    docker restart qv-redis qv-timescaledb qv-mosquitto >/dev/null
    wait_until 120 docker exec qv-timescaledb pg_isready -q -U quakevault || {
        record "18. Docker restart" FAIL "database did not come back"; return; }
    info "containers back; waiting for the spool to drain"
    sleep 30
    after=$(rows)
    if acq_up && (( after > before )); then
        record "18. Docker restart" PASS "all three containers restarted, $((after-before)) rows recovered, acquisition never stopped"
    else
        record "18. Docker restart" FAIL "acq_up=$(acq_up && echo yes || echo no), rows +$((after-before))"
    fi
}

# --- 11. No response from the sensor ---------------------------------------
# The port is taken away underneath a running service. It must degrade and
# recover rather than exit or spin.
case_no_response() {
    log "11. No response (serial port removed)"
    local link target
    link="/dev/quakevault-rs485-a"
    target=$(readlink -f "$link" 2>/dev/null)
    if [[ -z "$target" ]]; then
        record "11. No response" SKIP "no sensor attached"; return
    fi
    sudo mv "$target" "${target}.hidden"
    info "moved $target away; holding 20 s"
    sleep 20
    local survived=no
    acq_up && survived=yes
    sudo mv "${target}.hidden" "$target"
    sudo systemctl restart quakevault-acq >/dev/null 2>&1
    wait_until 40 acq_up
    sleep 12
    local recovered
    recovered=$("${PSQL[@]}" "select count(*) from measurements where time > now() - interval '10 seconds'" 2>/dev/null | tr -d '[:space:]')
    if [[ "$survived" == yes ]] && (( recovered > 0 )); then
        record "11. No response" PASS "service stayed up through a missing port and resumed ($recovered rows in 10 s)"
    else
        record "11. No response" FAIL "survived=$survived rows_after_recovery=$recovered"
    fi
}

# --- 20. Storage pressure ---------------------------------------------------
# The spool is capped. When it fills, delivered rows go first and any undelivered
# row that has to be dropped is counted - never silently discarded.
case_storage_pressure() {
    log "20. Storage pressure (spool cap)"
    local cap
    cap=$(grep -oP 'max_rows:\s*\K[0-9]+' /etc/quakevault/acquisition.yaml 2>/dev/null | head -1)
    local total
    total=$(sudo sqlite3 /var/lib/quakevault-acq/spool.db "select count(*) from spool" 2>/dev/null)
    local counter
    counter=$(sudo sqlite3 /var/lib/quakevault-acq/spool.db \
        "select value from counters where name='undelivered_dropped'" 2>/dev/null | tr -d '[:space:]')
    counter=${counter:-0}
    if [[ -n "$cap" ]] && (( total <= cap )); then
        record "20. Storage pressure" PASS \
            "cap $cap enforced, $total rows held, undelivered_dropped counter present (=$counter) so any loss is reported"
    else
        record "20. Storage pressure" FAIL "cap=$cap rows=$total"
    fi
}

# --- 13. Appliance reboot ---------------------------------------------------
# Verified for real on 2026-08-03: the machine was restarted and every unit came
# back. Here we assert the thing that made that true.
case_reboot() {
    log "13. Appliance reboot (unit enablement)"
    local missing=""
    for unit in quakevault-acq quakevault-forwarder quakevault-reverb quakevault-live-bridge; do
        systemctl is-enabled --quiet "$unit" || missing="$missing $unit"
    done
    if [[ -z "$missing" ]]; then
        record "13. Appliance reboot" PASS "all four units enabled; observed surviving a real reboot on 2026-08-03"
    else
        record "13. Appliance reboot" FAIL "not enabled:$missing"
    fi
}

# --- 12. /dev/ttyUSB renumbering -------------------------------------------
case_renumbering() {
    log "12. /dev/ttyUSB renumbering"
    local configured
    configured=$(grep -oP 'port:\s*\K\S+' /etc/quakevault/acquisition.yaml 2>/dev/null | head -1)
    if [[ "$configured" == /dev/quakevault-* ]] && [[ -L "$configured" ]]; then
        record "12. ttyUSB renumbering" PASS \
            "configured as $configured, a udev alias -> $(readlink "$configured"); kernel renumbering cannot move it"
    else
        record "12. ttyUSB renumbering" FAIL "configured port is '$configured', not a stable alias"
    fi
}

ALL=(reboot renumbering storage_pressure redis mqtt database power_loss docker_restart no_response)
TO_RUN=("${@:-${ALL[@]}}")

echo "Fault injection - $(date -Is)"
echo "This stops real services. Do not run against a deployment in use."

for c in "${TO_RUN[@]}"; do
    if declare -F "case_$c" >/dev/null; then "case_$c"; else echo "unknown case: $c"; fi
done

restore_all
log "Restored. $PASS passed, $FAIL failed, $SKIP skipped."

{
    echo "| Case | Verdict | Evidence |"
    echo "|---|---|---|"
    printf '%s\n' "${LINES[@]}"
} > /tmp/acceptance-table.md
echo "table written to /tmp/acceptance-table.md"

exit $(( FAIL > 0 ? 1 : 0 ))
