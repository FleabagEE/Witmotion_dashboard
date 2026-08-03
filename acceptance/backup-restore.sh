#!/usr/bin/env bash
#
# Backup, and prove the restore.
#
# An untested backup is a belief, not a backup. So this does not stop at writing
# an archive: it restores into a scratch database, counts what came back, and
# compares. A dump that restores into nothing is the failure people discover on
# the day they need it.
#
#   acceptance/backup-restore.sh backup             # write an archive
#   acceptance/backup-restore.sh verify <archive>   # restore it and compare
#   acceptance/backup-restore.sh                    # both, end to end
#
# BOUNDED BY DEFAULT. The measurements hypertable grows about 4.4 GB a day on
# this appliance, and a full logical dump of it took longer than nine minutes
# before being abandoned. That is the wrong shape of backup for an edge device:
# what an appliance actually needs to be rebuilt is its configuration, its
# register profiles, its alarm state and recent evidence - not a year of raw
# samples, which belong to whatever archival mechanism the site already has.
#
# BACKUP_DAYS controls how much raw measurement data to include. Default 0 -
# none. That is deliberate: measurements are evidence with their own retention,
# not the thing that rebuilds an appliance, and including them made the default
# backup slow enough that nobody would run it. A backup nobody runs is the most
# common way backups fail.
#
#   BACKUP_DAYS=0    configuration, schema, state, profiles, spool  (seconds)
#   BACKUP_DAYS=1    the above plus the last day of measurements    (~1 min)
#   BACKUP_DAYS=all  everything                                     (very slow)
#
# The verify step never touches the live database. It restores into a scratch
# one and drops it afterwards.

set -uo pipefail

REPO="/var/www/quakevault-industrial"
DEST="${BACKUP_DIR:-/var/backups/quakevault}"
DB_CONTAINER="qv-timescaledb"
DB_USER="quakevault"
DB_NAME="quakevault"
SCRATCH="quakevault_restore_check"

log() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
info() { printf '   %s\n' "$*"; }
die() { printf '   \033[31m%s\033[0m\n' "$*"; exit 1; }

psql_main() { docker exec -i "$DB_CONTAINER" psql -qtAX -U "$DB_USER" -d "$DB_NAME" -c "$1"; }
psql_scratch() { docker exec -i "$DB_CONTAINER" psql -qtAX -U "$DB_USER" -d "$SCRATCH" -c "$1"; }

do_backup() {
    log "Backup"
    sudo mkdir -p "$DEST"
    local stamp archive days
    days="${BACKUP_DAYS:-0}"
    stamp=$(date -u +%Y%m%dT%H%M%SZ)
    archive="$DEST/quakevault-$stamp"

    # Schema and data separately. A schema-only file is what you read when
    # something looks wrong, and it stays legible.
    info "dumping schema"
    docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" --schema-only \
        | sudo tee "$archive.schema.sql" >/dev/null || die "schema dump failed"

    # Everything except the raw hypertable: alarm definitions and their
    # confirmation state, sensors, channels, assets, users, audit. Small, and
    # the part that cannot be reconstructed from anywhere else.
    info "dumping configuration and state tables"
    docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" -Fc \
        --exclude-table-data='measurements*' --exclude-table-data='_timescaledb_internal.*' \
        | sudo tee "$archive.dump" >/dev/null || die "state dump failed"

    if [[ "$days" == "0" ]]; then
        info "skipping raw measurements (set BACKUP_DAYS to include them)"
    elif [[ "$days" == "all" ]]; then
        info "dumping ALL measurements (very slow - 4.4 GB/day on this appliance)"
        docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" \
            -c "\\copy (select * from measurements) to stdout with csv header" \
            | gzip | sudo tee "$archive.measurements.csv.gz" >/dev/null
    else
        info "dumping the last $days day(s) of measurements"
        docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$DB_NAME" \
            -c "\\copy (select * from measurements where time > now() - interval '$days days') to stdout with csv header" \
            | gzip | sudo tee "$archive.measurements.csv.gz" >/dev/null
    fi

    # Configuration and the register profiles. A database restored without the
    # profile that decoded it is a table of numbers with no units.
    info "capturing configuration and profiles"
    sudo tar czf "$archive.config.tgz" \
        -C / etc/quakevault \
        -C "$REPO" profiles \
        2>/dev/null || die "config capture failed"

    # Only the UNDELIVERED spool rows, not the file.
    #
    # spool.db is 1.2 GB: a 500 000-row cap of JSON envelopes at about 2.4 KB
    # each. Not bloat - the freelist is two pages - just a lot of real data.
    # Copying it took over eight minutes, which is what made the whole backup
    # unrunnable, and it is wasted effort besides: almost all
    # of that is rows already forwarded to the database and therefore already in
    # the dump. What is worth preserving is the handful not yet delivered, and
    # that is usually a few rows.
    if sudo test -f /var/lib/quakevault-acq/spool.db; then
        local pending
        pending=$(sudo sqlite3 /var/lib/quakevault-acq/spool.db \
            "select count(*) from spool where delivered_at is null" 2>/dev/null)
        info "exporting ${pending:-0} undelivered spool row(s)"
        sudo sqlite3 /var/lib/quakevault-acq/spool.db \
            ".mode insert spool" \
            "select * from spool where delivered_at is null" 2>/dev/null \
            | gzip | sudo tee "$archive.spool-pending.sql.gz" >/dev/null \
            || info "spool export failed (continuing - it is not the system of record)"
    fi

    # Checksums last, over everything else. Without them a truncated dump is
    # indistinguishable from a complete one until the restore fails.
    ( cd "$DEST" && sudo sh -c "sha256sum $(basename "$archive").* > $(basename "$archive").sha256" )

    local rows
    if [[ "$days" == "0" ]]; then
        rows=0
    elif [[ "$days" == "all" ]]; then
        rows=$(psql_main "select count(*) from measurements" | tr -d '[:space:]')
    else
        rows=$(psql_main "select count(*) from measurements where time > now() - interval '$days days'" | tr -d '[:space:]')
    fi
    echo "$rows" | sudo tee "$archive.rowcount" >/dev/null
    info "measurements captured: $rows"

    log "Written"
    sudo ls -lh "$DEST"/"$(basename "$archive")".* | awk '{print "   "$5"\t"$9}'
    echo "$archive"
}

do_verify() {
    local archive="$1"
    log "Verify: restore $archive into a scratch database"

    [[ -f "$archive.dump" ]] || die "no dump at $archive.dump"

    info "checking checksums"
    ( cd "$(dirname "$archive")" && sudo sha256sum -c "$(basename "$archive").sha256" >/dev/null 2>&1 ) \
        || die "CHECKSUM MISMATCH - the archive is corrupt"
    info "checksums ok"

    local expected
    expected=$(sudo cat "$archive.rowcount" 2>/dev/null | tr -d '[:space:]')

    docker exec -i "$DB_CONTAINER" psql -qtAX -U "$DB_USER" -d postgres \
        -c "drop database if exists $SCRATCH" >/dev/null 2>&1
    docker exec -i "$DB_CONTAINER" psql -qtAX -U "$DB_USER" -d postgres \
        -c "create database $SCRATCH" >/dev/null 2>&1 || die "could not create scratch database"

    info "restoring schema and state into the scratch database"
    psql_scratch "create extension if not exists timescaledb" >/dev/null 2>&1
    sudo cat "$archive.dump" \
        | docker exec -i "$DB_CONTAINER" pg_restore -U "$DB_USER" -d "$SCRATCH" --no-owner 2>/dev/null

    local tables restored verdict=0
    tables=$(psql_scratch "select count(*) from pg_tables where schemaname='public'" 2>/dev/null | tr -d '[:space:]')
    info "tables restored: ${tables:-0}"

    if [[ -z "$tables" || "$tables" == "0" ]]; then
        printf '   \033[31mFAIL\033[0m restore produced no tables\n'
        verdict=1
    fi

    # The measurements travel as CSV, so the check is that the file is complete
    # and readable - a gzip that unpacks to the expected row count.
    if sudo test -f "$archive.measurements.csv.gz"; then
        restored=$(sudo gzip -dc "$archive.measurements.csv.gz" | tail -n +2 | wc -l)
        info "measurement rows: expected ${expected:-?}, in archive ${restored:-0}"
        if [[ -n "$expected" ]] && (( restored < expected * 99 / 100 )); then
            printf '   \033[31mFAIL\033[0m archive holds %s of %s rows\n' "$restored" "$expected"
            verdict=1
        else
            printf '   \033[32mPASS\033[0m %s measurement rows readable from the archive\n' "$restored"
        fi
    fi

    # An alarm definition with its confirmation state is worth checking
    # separately: restoring measurements but losing who confirmed a threshold
    # would silently turn confirmed alarms back into provisional ones.
    local defs
    defs=$(psql_scratch "select count(*) from alarm_definitions" 2>/dev/null | tr -d '[:space:]')
    info "alarm definitions restored: ${defs:-0}"

    docker exec -i "$DB_CONTAINER" psql -qtAX -U "$DB_USER" -d postgres \
        -c "drop database if exists $SCRATCH" >/dev/null 2>&1
    info "scratch database dropped"

    return $verdict
}

case "${1:-all}" in
    backup) do_backup ;;
    verify) shift; do_verify "$1" ;;
    all)
        archive=$(do_backup | tail -1)
        do_verify "$archive"
        ;;
    *) echo "usage: $0 [backup|verify <archive>|all]"; exit 2 ;;
esac
