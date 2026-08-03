#!/usr/bin/env bash
#
# 24-hour soak.
#
# Samples the appliance every five minutes and appends one CSV row. The point is
# not that it stays up - a short test shows that - but that nothing drifts:
# memory that only grows, a spool backlog that never returns to zero, a poll rate
# that sags as the database fills. Those are invisible in ten minutes and obvious
# over a day.
#
# Deliberately writes a row at a time and flushes. A soak that loses its record
# when the machine reboots has proved nothing about the reboot.
#
#   nohup acceptance/soak.sh > /dev/null 2>&1 &
#   acceptance/soak.sh --report        # summarise whatever has been collected

set -uo pipefail

OUT="${SOAK_FILE:-/var/lib/quakevault-acq/soak.csv}"
INTERVAL="${SOAK_INTERVAL:-300}"
HOURS="${SOAK_HOURS:-24}"
PSQL=(docker exec -i qv-timescaledb psql -qtAX -U quakevault -d quakevault -c)

q() { "${PSQL[@]}" "$1" 2>/dev/null | tr -d '[:space:]'; }

report() {
    [[ -f "$OUT" ]] || { echo "no soak data at $OUT"; exit 1; }
    python3 - "$OUT" <<'PY'
import csv, sys
rows = list(csv.DictReader(open(sys.argv[1])))
if len(rows) < 2:
    print(f"only {len(rows)} sample(s) so far - too early to report"); sys.exit()

def nums(k):
    out = []
    for r in rows:
        try: out.append(float(r[k]))
        except (ValueError, KeyError, TypeError): pass
    return out

span = (float(rows[-1]['uptime_s']) - float(rows[0]['uptime_s'])) / 3600
print(f"{len(rows)} samples over {span:.1f} h\n")

def line(label, key, unit="", drift=True):
    v = nums(key)
    if not v: return
    txt = f"  {label:<26} first {v[0]:>12,.1f}  last {v[-1]:>12,.1f}  min {min(v):>10,.1f}  max {max(v):>10,.1f} {unit}"
    print(txt)

line("acquisition RSS", "acq_rss_kb", "KB")
line("forwarder RSS", "fwd_rss_kb", "KB")
line("spool backlog", "backlog")
line("spool total", "spool_total")
line("measured poll Hz", "poll_hz", "Hz")
line("bus utilisation", "bus_util")
line("DB rows", "db_rows")
line("live dropped", "live_dropped")

print()
# The verdicts that matter over a day, rather than at any one moment.
rss = nums("acq_rss_kb")
if rss and len(rss) > 3:
    growth = (rss[-1] - rss[0]) / max(rss[0], 1) * 100
    verdict = "OK" if abs(growth) < 25 else "INVESTIGATE - looks like a leak"
    print(f"  acquisition memory drift over the run: {growth:+.1f}%   {verdict}")

bl = nums("backlog")
if bl:
    stuck = bl[-1] > 500 and bl[-1] >= max(bl) * 0.9
    print(f"  spool backlog ends at {bl[-1]:,.0f}   " +
          ("INVESTIGATE - not draining" if stuck else "OK - drains"))

hz = nums("poll_hz")
if hz and len(hz) > 3:
    sag = (hz[-1] - hz[0]) / max(hz[0], 1e-9) * 100
    print(f"  poll rate drift: {sag:+.1f}%   " + ("OK" if abs(sag) < 15 else "INVESTIGATE - rate sagging"))

drop = nums("live_dropped")
if drop:
    print(f"  live frames dropped: {drop[-1]-drop[0]:,.0f} over the run "
          "(lossy by design - the durable path is the record)")
PY
}

[[ "${1:-}" == "--report" ]] && { report; exit 0; }

if [[ ! -f "$OUT" ]]; then
    echo "ts,uptime_s,acq_rss_kb,fwd_rss_kb,backlog,spool_total,db_rows,poll_hz,bus_util,live_dropped,acq_active" \
        | sudo tee "$OUT" >/dev/null
fi

deadline=$(( $(date +%s) + HOURS * 3600 ))
echo "soak running until $(date -d "@$deadline" -Is), sampling every ${INTERVAL}s -> $OUT"

while [[ $(date +%s) -lt $deadline ]]; do
    metrics=$(sudo cat /var/lib/quakevault-acq/metrics.prom 2>/dev/null)
    grab() { echo "$metrics" | grep -m1 "^$1" | awk '{print $NF}'; }

    acq_pid=$(systemctl show -p MainPID --value quakevault-acq)
    fwd_pid=$(systemctl show -p MainPID --value quakevault-forwarder)
    rss() { [[ -n "$1" && "$1" != 0 ]] && awk '/VmRSS/{print $2}' "/proc/$1/status" 2>/dev/null || echo ""; }

    printf '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' \
        "$(date -Is)" \
        "$(cut -d. -f1 /proc/uptime)" \
        "$(rss "$acq_pid")" \
        "$(rss "$fwd_pid")" \
        "$(sudo sqlite3 /var/lib/quakevault-acq/spool.db 'select count(*) from spool where delivered_at is null' 2>/dev/null)" \
        "$(sudo sqlite3 /var/lib/quakevault-acq/spool.db 'select count(*) from spool' 2>/dev/null)" \
        "$(q 'select count(*) from measurements')" \
        "$(grab 'quakevault_poll_measured_hz{.*group="motion"')" \
        "$(grab 'quakevault_bus_utilisation_ratio')" \
        "$(grab 'quakevault_live_dropped_total')" \
        "$(systemctl is-active quakevault-acq)" \
        | sudo tee -a "$OUT" >/dev/null

    sleep "$INTERVAL"
done

echo "soak complete"
report
