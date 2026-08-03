#!/usr/bin/env bash
#
# Launch the wall display and keep it up.
#
# A kiosk that dies quietly at 3am is worse than no kiosk: nobody is watching the
# screen that shows nobody is watching. So this waits for the dashboard to be
# genuinely reachable before starting, and systemd restarts it whenever it exits.
#
# The browser is launched with a dedicated profile directory so a crashed session
# cannot leave a "restore pages?" prompt sitting over the display forever - which
# is the usual way an unattended kiosk ends up showing a dialog instead of data.

set -euo pipefail

URL="${KIOSK_URL:-http://127.0.0.1:8000/kiosk}"
PROFILE="${KIOSK_PROFILE:-/var/lib/quakevault-kiosk/profile}"
WAIT_SECONDS="${KIOSK_WAIT_SECONDS:-120}"

log() { printf '%s %s\n' "$(date -Is)" "$*"; }

find_browser() {
    for candidate in chromium chromium-browser google-chrome firefox; do
        if command -v "$candidate" >/dev/null 2>&1; then
            echo "$candidate"
            return 0
        fi
    done
    return 1
}

# Wait for the dashboard rather than racing it. On a cold boot the browser
# usually wins against PHP and the database, and a kiosk that opened on a
# connection error would sit there until somebody noticed.
log "waiting for $URL"
deadline=$(( $(date +%s) + WAIT_SECONDS ))
until curl -fsS --max-time 3 -o /dev/null "$URL"; do
    if [[ $(date +%s) -ge $deadline ]]; then
        log "dashboard did not become reachable within ${WAIT_SECONDS}s"
        exit 1   # systemd restarts us; the next attempt starts the wait afresh
    fi
    sleep 2
done
log "dashboard is up"

BROWSER="$(find_browser)" || { log "no supported browser installed"; exit 1; }
log "launching $BROWSER"

mkdir -p "$PROFILE"
# Clear the crash flags. Without this a hard power cut leaves the restore prompt
# covering the display on every subsequent boot.
if [[ -f "$PROFILE/Default/Preferences" ]]; then
    sed -i 's/"exit_type":"[^"]*"/"exit_type":"Normal"/; s/"exited_cleanly":false/"exited_cleanly":true/' \
        "$PROFILE/Default/Preferences" || true
fi

case "$BROWSER" in
    firefox)
        exec "$BROWSER" --kiosk --profile "$PROFILE" "$URL"
        ;;
    *)
        exec "$BROWSER" \
            --kiosk \
            --user-data-dir="$PROFILE" \
            --no-first-run \
            --disable-session-crashed-bubble \
            --disable-infobars \
            --noerrdialogs \
            --disable-translate \
            --disable-features=TranslateUI \
            --check-for-update-interval=31536000 \
            --autoplay-policy=no-user-gesture-required \
            "$URL"
        ;;
esac
