#!/usr/bin/env bash
#
# Upgrade the appliance, and be able to undo it.
#
# The rule this is built around: acquisition does not stop. The spool exists so
# that the database, the API and the dashboard can all be down without losing a
# reading, and an upgrade is just a planned outage of exactly those things.
# Measured at 10.1 hours of coverage, which is far longer than any upgrade.
#
# Every step that can fail is checked, and any failure rolls the whole thing
# back to the commit and schema it started from. An upgrade that fails halfway
# and leaves an appliance in neither state is worse than one that refuses to
# start.
#
#   deploy/upgrade.sh <git-ref>     upgrade to a tag, branch or commit
#   deploy/upgrade.sh --rollback    undo the last upgrade
#   deploy/upgrade.sh --status      what version is running, and what it came from
#
# DRY_RUN=1 walks the whole thing without changing anything.
# COMPOSER_ARGS="" keeps dev dependencies (use on a development machine).

set -uo pipefail

REPO="/var/www/quakevault-industrial"
# Deployment state, not acquisition data - and deliberately not under
# /var/lib/quakevault-acq, which systemd creates 0700 for the service user. The
# script wrote there with sudo and then tested for the file without it, so it
# created a rollback point it could never find: the upgrade reported success and
# left no way back. Kept beside the backups, which are the other deployment
# artefact, and every access goes through sudo so the asymmetry cannot return.
STATE="/var/backups/quakevault/upgrade-state"
DRY_RUN="${DRY_RUN:-0}"

log()  { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
ok()   { printf '   \033[32m%s\033[0m\n' "$*"; }
bad()  { printf '   \033[31m%s\033[0m\n' "$*"; }

run() {
    if [[ "$DRY_RUN" == "1" ]]; then
        info "[dry run] $*"
        return 0
    fi
    "$@"
}

current_sha()  { git -C "$REPO" rev-parse HEAD 2>/dev/null; }
migration_count() {
    docker exec -i qv-timescaledb psql -qtAX -U quakevault -d quakevault \
        -c "select count(*) from migrations" 2>/dev/null | tr -d '[:space:]'
}

# --- health ------------------------------------------------------------------
# Used twice: before, to refuse upgrading something already broken, and after, to
# decide whether the upgrade worked.
health() {
    local failures=0

    systemctl is-active --quiet quakevault-acq || { bad "acquisition is not running"; failures=$((failures+1)); }
    docker exec qv-timescaledb pg_isready -q -U quakevault 2>/dev/null \
        || { bad "database is not ready"; failures=$((failures+1)); }

    local fresh
    fresh=$(docker exec -i qv-timescaledb psql -qtAX -U quakevault -d quakevault \
        -c "select count(*) from measurements where time > now() - interval '2 minutes'" 2>/dev/null | tr -d '[:space:]')
    if [[ -z "$fresh" || "$fresh" -lt 1 ]]; then
        bad "no measurements in the last 2 minutes"
        failures=$((failures+1))
    else
        info "measurements in the last 2 minutes: $fresh"
    fi

    # The API answering matters more than the process existing: a backend that
    # boots but cannot serve is the failure an upgrade is most likely to cause.
    local api
    api=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 \
        http://127.0.0.1:8000/api/internal/v1/ingest/health 2>/dev/null)
    if [[ "$api" == "200" || "$api" == "401" ]]; then
        info "API responding ($api)"
    else
        bad "API not responding (got '${api:-nothing}')"
        failures=$((failures+1))
    fi

    return $failures
}

# --- rollback ----------------------------------------------------------------
do_rollback() {
    log "Rollback"

    sudo test -f "$STATE" || { bad "no upgrade state at $STATE - nothing to roll back to"; exit 1; }

    local prev_sha prev_migrations
    prev_sha=$(sudo grep '^sha=' "$STATE" | cut -d= -f2)
    prev_migrations=$(sudo grep '^migrations=' "$STATE" | cut -d= -f2)

    info "returning to $prev_sha (had $prev_migrations migrations)"

    local now_migrations
    now_migrations=$(migration_count)
    if [[ -n "$prev_migrations" && "$now_migrations" -gt "$prev_migrations" ]]; then
        local steps=$(( now_migrations - prev_migrations ))
        info "rolling back $steps migration(s)"
        # Before the code, not after. A migration's down() lives in the version
        # that wrote it, so reverting the checkout first would take the schema
        # change out of reach of the only code that knows how to undo it.
        run bash -c "cd $REPO/backend && php artisan migrate:rollback --step=$steps --force"
    fi

    run git -C "$REPO" checkout --quiet "$prev_sha" || { bad "could not check out $prev_sha"; exit 1; }
    rebuild
    restart_services

    if health; then
        ok "Rolled back to $prev_sha and healthy"
        run sudo rm -f "$STATE"
        return 0
    fi
    bad "Rolled back, but the appliance is still unhealthy - needs a person"
    return 1
}

rebuild() {
    info "installing backend dependencies"
    # --no-dev is right for an appliance and wrong for the bench, where it
    # removes phpunit and takes the test suite with it.
    #
    # ${VAR-default}, not ${VAR:-default}. The colon form substitutes the default
    # when the variable is empty as well as unset, so COMPOSER_ARGS="" - the
    # obvious way to ask for "no extra flags" - was silently ignored and --no-dev
    # ran anyway. It did exactly that on this machine and removed the test suite.
    run bash -c "cd $REPO/backend && composer install --no-interaction ${COMPOSER_ARGS---no-dev --optimize-autoloader} --quiet" \
        || info "composer install reported a problem (continuing to the health check)"
    info "installing the acquisition package"
    run bash -c "$REPO/.venv/bin/pip -q install -e $REPO/acquisition"
    info "building the frontend"
    run bash -c "cd $REPO/frontend && npm ci --silent && npm run build --silent" \
        || info "frontend build reported a problem (continuing to the health check)"
    run bash -c "cd $REPO/backend && php artisan config:clear >/dev/null && php artisan route:clear >/dev/null"
}

restart_services() {
    # Acquisition deliberately last and never stopped: it keeps polling and
    # spooling through the whole upgrade, which is the point of having a spool.
    info "restarting services"
    run sudo systemctl restart quakevault-reverb quakevault-live-bridge quakevault-forwarder
    run sudo systemctl reload-or-restart quakevault-acq
    sleep 8
}

# --- upgrade -----------------------------------------------------------------
do_upgrade() {
    local target="$1"
    log "Pre-flight"

    if ! health; then
        bad "The appliance is not healthy now. Fix that before upgrading."
        bad "Upgrading a broken system makes it impossible to tell what the upgrade broke."
        exit 1
    fi
    ok "healthy"

    git -C "$REPO" rev-parse --verify --quiet "$target^{commit}" >/dev/null \
        || { bad "unknown git ref: $target"; exit 1; }

    local from to before_migrations
    from=$(current_sha)
    to=$(git -C "$REPO" rev-parse "$target")
    before_migrations=$(migration_count)

    if [[ "$from" == "$to" ]]; then
        info "already at $to - nothing to do"
        exit 0
    fi

    log "Backup"
    run "$REPO/acceptance/backup-restore.sh" backup >/dev/null || {
        bad "backup failed - refusing to upgrade without one"; exit 1; }
    ok "backup written"

    # Recorded before anything changes, so a rollback has somewhere to go even if
    # the upgrade dies at the first step.
    run sudo mkdir -p "$(dirname "$STATE")"
    run sudo tee "$STATE" >/dev/null <<EOFSTATE
sha=$from
migrations=$before_migrations
target=$to
at=$(date -Is)
EOFSTATE

    log "Upgrade $from -> $to"
    run git -C "$REPO" checkout --quiet "$to" || { bad "checkout failed"; do_rollback; exit 1; }

    rebuild

    info "running migrations"
    if ! run bash -c "cd $REPO/backend && php artisan migrate --force"; then
        bad "migration failed"
        do_rollback
        exit 1
    fi

    restart_services

    log "Post-flight"
    if health; then
        ok "Upgraded to $to and healthy"
        info "roll back with: deploy/upgrade.sh --rollback"
        return 0
    fi

    bad "unhealthy after upgrade - rolling back"
    do_rollback
    exit 1
}

do_status() {
    log "Status"
    info "running:  $(current_sha)"
    info "branch:   $(git -C "$REPO" rev-parse --abbrev-ref HEAD 2>/dev/null)"
    info "migrations applied: $(migration_count)"
    if sudo test -f "$STATE"; then
        info "last upgrade came from: $(sudo grep '^sha=' "$STATE" | cut -d= -f2)"
        info "at: $(sudo grep '^at=' "$STATE" | cut -d= -f2-)"
        info "rollback available"
    else
        info "no upgrade recorded - nothing to roll back to"
    fi
    echo
    health && ok "healthy" || bad "unhealthy"
}

case "${1:-}" in
    --rollback) do_rollback ;;
    --status)   do_status ;;
    "")         echo "usage: $0 <git-ref> | --rollback | --status"; exit 2 ;;
    *)          do_upgrade "$1" ;;
esac
