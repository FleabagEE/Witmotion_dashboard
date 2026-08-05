#!/usr/bin/env bash
# Install or update the QuakeVault acquisition service.
#
# Idempotent and non-destructive: safe to re-run. An existing configuration is
# never overwritten - the shipped example is installed alongside it as
# acquisition.yaml.new for the operator to merge.
set -euo pipefail

REPO="${REPO:-/var/www/quakevault-industrial}"
SERVICE_USER="quakevault-acq"
CONFIG_DIR="/etc/quakevault"
STATE_DIR="/var/lib/quakevault-acq"
UNIT="quakevault-acq.service"
SCHEDULER_UNIT="quakevault-scheduler.service"
SCHEDULER_TIMER="quakevault-scheduler.timer"
FORWARDER_UNIT="quakevault-forwarder.service"
DASHBOARD_UNIT="quakevault-dashboard.service"

log()  { printf '  %s\n' "$*"; }
fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "must run as root (try: sudo $0)"
[[ -d "$REPO" ]]  || fail "repository not found at $REPO (override with REPO=...)"

echo "QuakeVault acquisition installer"
echo

echo "1. Operating system"
if [[ -r /etc/os-release ]]; then
    . /etc/os-release
    log "detected ${PRETTY_NAME:-unknown}"
    [[ "${ID:-}" == "ubuntu" ]] || log "WARNING: only Ubuntu is supported; continuing anyway"
else
    log "WARNING: /etc/os-release missing; cannot verify the distribution"
fi

echo "2. Service account"
if id "$SERVICE_USER" &>/dev/null; then
    log "$SERVICE_USER already exists"
else
    useradd --system --no-create-home --home-dir /nonexistent \
            --shell /usr/sbin/nologin "$SERVICE_USER"
    log "created $SERVICE_USER"
fi
if id -nG "$SERVICE_USER" | tr ' ' '\n' | grep -qx dialout; then
    log "already in dialout"
else
    usermod -aG dialout "$SERVICE_USER"
    log "added to dialout"
fi

echo "3. Directories"
install -d -o "$SERVICE_USER" -g "$SERVICE_USER" -m 0750 "$STATE_DIR"
install -d -o root -g root -m 0755 "$CONFIG_DIR"
log "$STATE_DIR and $CONFIG_DIR ready"

echo "4. Python environment"
if [[ -x "$REPO/.venv/bin/python" ]]; then
    log "virtualenv present"
else
    python3 -m venv "$REPO/.venv"
    log "virtualenv created"
fi
"$REPO/.venv/bin/pip" -q install --upgrade pip
"$REPO/.venv/bin/pip" -q install "pymodbus>=3.6" pyserial pydantic pyyaml

# Install the package itself, not just its dependencies. Without this the only
# things that can run are the systemd units, which carry their own PYTHONPATH -
# every operator tool fails with ModuleNotFoundError, and the test suite does not
# notice because pytest sets pythonpath in pyproject.toml. Editable, so the
# checked-out tree stays the single source of truth.
"$REPO/.venv/bin/pip" -q install -e "$REPO/acquisition"
log "dependencies installed"

echo "5. udev rules"
if [[ -f "$REPO/deploy/udev/99-quakevault-rs485.rules" ]]; then
    install -m 0644 "$REPO/deploy/udev/99-quakevault-rs485.rules" /etc/udev/rules.d/
    udevadm control --reload-rules
    udevadm trigger --subsystem-match=tty
    log "installed and reloaded"
    shopt -s nullglob
    aliases=(/dev/quakevault-rs485-*)
    if (( ${#aliases[@]} )); then
        log "stable aliases: ${aliases[*]}"
    else
        log "WARNING: no /dev/quakevault-rs485-* alias appeared."
        log "         The rule keys on USB topology; if the adapter moved ports,"
        log "         update KERNELS in the rules file."
    fi
    shopt -u nullglob
else
    log "WARNING: udev rules not found in the repository"
fi

echo "6. Configuration"
if [[ -f "$CONFIG_DIR/acquisition.yaml" ]]; then
    install -m 0644 "$REPO/deploy/quakevault/acquisition.yaml" "$CONFIG_DIR/acquisition.yaml.new"
    log "existing config preserved; shipped example written to acquisition.yaml.new"
else
    install -m 0644 "$REPO/deploy/quakevault/acquisition.yaml" "$CONFIG_DIR/acquisition.yaml"
    log "installed default configuration"
fi

echo "7. Validate configuration"
if sudo -u "$SERVICE_USER" \
     env PYTHONPATH="$REPO/acquisition/src" \
     "$REPO/.venv/bin/python" -m qv_acq.service --check \
     --config "$CONFIG_DIR/acquisition.yaml"; then
    log "configuration valid"
else
    log "WARNING: configuration did not validate. The service will refuse to start"
    log "         until this is fixed. This is expected on a fresh install whose"
    log "         sensors are not wired yet."
fi

echo "8. Forwarder credential"
if [[ -f "$CONFIG_DIR/forwarder.env" ]]; then
    log "existing forwarder.env preserved"
else
    install -m 0600 -o root -g root "$REPO/deploy/quakevault/forwarder.env.example" \
        "$CONFIG_DIR/forwarder.env"
    log "installed forwarder.env (mode 0600) - set QV_INGEST_TOKEN before starting"
fi

echo "9. systemd units"
install -m 0644 "$REPO/deploy/systemd/$UNIT" /etc/systemd/system/
install -m 0644 "$REPO/deploy/systemd/$FORWARDER_UNIT" /etc/systemd/system/

# The scheduler is not optional and was, for a long time, simply absent.
#
# tilt:check runs every five minutes and is the only thing that compares a
# structure against its baseline and raises an alarm. An appliance without this
# timer polls its sensors, stores every reading, serves a dashboard that looks
# entirely healthy, and evaluates nothing. It shipped that way here and nobody
# noticed until somebody asked whether tilting the sensor would trigger an alarm.
install -m 0644 "$REPO/deploy/systemd/$SCHEDULER_UNIT" /etc/systemd/system/
install -m 0644 "$REPO/deploy/systemd/$SCHEDULER_TIMER" /etc/systemd/system/

# The dashboard, for the same reason and from the same lesson. It was two
# processes started by hand in a terminal; a power cut brought the sensors back
# under systemd and left the screen dark. On an appliance whose entire purpose
# is to be looked at, that is the visible half of the product failing while the
# invisible half carries on perfectly.
install -m 0644 "$REPO/deploy/systemd/$DASHBOARD_UNIT" /etc/systemd/system/

systemctl daemon-reload
systemctl enable "$UNIT" "$FORWARDER_UNIT" "$SCHEDULER_TIMER" "$DASHBOARD_UNIT" >/dev/null
log "installed and enabled $UNIT, $FORWARDER_UNIT and $DASHBOARD_UNIT"

if [[ -f "$REPO/backend/public/index.html" ]]; then
    log "dashboard assets present"
else
    log "WARNING: no built dashboard in backend/public."
    log "         The service will start and answer with a plain 503 saying so."
    log "         Build it with: $REPO/deploy/build-dashboard.sh"
fi

echo
echo "Install complete."
echo
echo "  Start:   systemctl start $UNIT $FORWARDER_UNIT $SCHEDULER_TIMER $DASHBOARD_UNIT"
echo "  Status:  systemctl status $UNIT"
echo "  Logs:    journalctl -u $UNIT -u $FORWARDER_UNIT -f"
echo "  Token:   set QV_INGEST_TOKEN in $CONFIG_DIR/forwarder.env"
echo "  Config:  $CONFIG_DIR/acquisition.yaml"
echo "  Hardening report: systemd-analyze security $UNIT"
echo
echo "Scheduled evaluation runs from $SCHEDULER_TIMER. Without it the appliance"
echo "polls, stores and displays normally and evaluates nothing - no settlement is"
echo "compared against its baseline and no alarm can be raised. Confirm with:"
echo
echo "  systemctl list-timers $SCHEDULER_TIMER"
echo
echo "The service will not start until every configured sensor uses a profile"
echo "marked 'verified'. That gate is deliberate: an unconfirmed register map"
echo "produces plausible-looking numbers rather than an obvious failure."
