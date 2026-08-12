#!/bin/sh
set -eu

PLUGIN_DIR=/usr/local/directadmin/plugins/openlitespeed_reload
DA_BIN=/usr/local/directadmin/directadmin
PHP_BIN=/usr/local/bin/php
LOG_FILE=/var/log/directadmin-openlitespeed-reload.log
LOGROTATE_FILE=/etc/logrotate.d/directadmin-openlitespeed-reload
ALLOWLIST_FILE="$PLUGIN_DIR/config/allowed_resellers"
SECRET_FILE="$PLUGIN_DIR/config/secret"
FORCE="${OLS_RELOAD_FORCE:-0}"

fail() {
    echo "ERROR: $1" >&2
    exit 1
}

note() {
    echo "OK: $1"
}

warn_or_fail() {
    if [ "$FORCE" = "1" ]; then
        echo "WARNING (forced past): $1" >&2
    else
        fail "$1 (set OLS_RELOAD_FORCE=1 to override)"
    fi
}

if [ "$(id -u)" != "0" ]; then
    fail "installer must run as root"
fi
if [ ! -f "$PLUGIN_DIR/plugin.conf" ]; then
    fail "plugin files not found at $PLUGIN_DIR"
fi
if [ ! -x "$DA_BIN" ]; then
    fail "DirectAdmin binary not found at $DA_BIN"
fi

da_version_output=$("$DA_BIN" version 2>/dev/null || "$DA_BIN" v 2>/dev/null || true)
da_version=$(printf '%s\n' "$da_version_output" | grep -oE '[0-9]+\.[0-9]+(\.[0-9]+)?' | head -n 1 || true)
if [ -z "$da_version" ]; then
    warn_or_fail "could not determine the DirectAdmin version"
else
    da_major=${da_version%%.*}
    da_rest=${da_version#*.}
    da_minor=${da_rest%%.*}
    if [ "$da_major" -gt 1 ] || { [ "$da_major" -eq 1 ] && [ "$da_minor" -ge 689 ]; }; then
        note "DirectAdmin $da_version supports reseller_run_as=root"
    else
        warn_or_fail "DirectAdmin $da_version is older than 1.689 and does not support reseller_run_as=root; please update DirectAdmin"
    fi
fi

SYSTEMCTL=""
for candidate in /usr/bin/systemctl /bin/systemctl; do
    if [ -x "$candidate" ]; then
        SYSTEMCTL="$candidate"
        break
    fi
done
if [ -z "$SYSTEMCTL" ]; then
    fail "systemctl not found; this plugin requires systemd"
fi
note "systemctl found at $SYSTEMCTL"

load_state=$("$SYSTEMCTL" show lsws.service --property=LoadState --value 2>/dev/null || true)
if [ "$load_state" = "loaded" ]; then
    note "lsws systemd unit is present"
else
    warn_or_fail "lsws systemd unit not found (LoadState=${load_state:-unknown})"
fi
if [ -x /usr/local/lsws/bin/openlitespeed ]; then
    note "OpenLiteSpeed binary is present"
else
    warn_or_fail "OpenLiteSpeed binary /usr/local/lsws/bin/openlitespeed not found; this plugin targets OpenLiteSpeed only"
fi

if [ ! -x "$PHP_BIN" ]; then
    fail "PHP CLI not found at $PHP_BIN"
fi
php_version_id=$("$PHP_BIN" -r 'echo PHP_VERSION_ID;' 2>/dev/null || echo 0)
if [ "$php_version_id" -lt 70400 ]; then
    fail "PHP 7.4 or newer is required at $PHP_BIN"
fi
note "PHP CLI version $("$PHP_BIN" -r 'echo PHP_VERSION;') is supported"

chown -R root:root "$PLUGIN_DIR"
find "$PLUGIN_DIR" -type d -exec chmod 755 {} +
find "$PLUGIN_DIR" -path "$PLUGIN_DIR/config" -prune -o -type f -exec chmod 644 {} +
chmod 755 "$PLUGIN_DIR/reseller/index.html" "$PLUGIN_DIR/reseller/menu.json.raw" "$PLUGIN_DIR/admin/index.html"
chmod 755 "$PLUGIN_DIR"/scripts/*.sh
mkdir -p "$PLUGIN_DIR/config"
chmod 700 "$PLUGIN_DIR/config"
note "ownership and permissions applied"

if [ ! -f "$ALLOWLIST_FILE" ]; then
    (umask 077; : > "$ALLOWLIST_FILE")
    note "created empty allowlist at $ALLOWLIST_FILE"
else
    note "existing allowlist preserved"
fi
chown root:root "$ALLOWLIST_FILE"
chmod 600 "$ALLOWLIST_FILE"

if [ ! -f "$SECRET_FILE" ]; then
    if command -v openssl >/dev/null 2>&1; then
        (umask 077; openssl rand -hex 32 > "$SECRET_FILE")
    else
        (umask 077; od -An -N32 -tx1 /dev/urandom | tr -d ' \n' > "$SECRET_FILE")
    fi
    note "generated request-validation secret"
else
    note "existing request-validation secret preserved"
fi
chown root:root "$SECRET_FILE"
chmod 600 "$SECRET_FILE"

if [ -f "$PLUGIN_DIR/config/allowed_resellers.example" ]; then
    chown root:root "$PLUGIN_DIR/config/allowed_resellers.example"
    chmod 600 "$PLUGIN_DIR/config/allowed_resellers.example"
fi

if [ ! -f "$LOG_FILE" ]; then
    (umask 077; : > "$LOG_FILE")
    note "created audit log at $LOG_FILE"
fi
chown root:root "$LOG_FILE"
chmod 600 "$LOG_FILE"

cat > "$LOGROTATE_FILE" <<'EOF'
/var/log/directadmin-openlitespeed-reload.log {
    monthly
    rotate 6
    compress
    delaycompress
    missingok
    notifempty
    create 0600 root root
}
EOF
chmod 644 "$LOGROTATE_FILE"
note "logrotate configuration installed"

if grep -q '^installed=' "$PLUGIN_DIR/plugin.conf"; then
    sed -i 's/^installed=.*/installed=yes/' "$PLUGIN_DIR/plugin.conf"
else
    echo 'installed=yes' >> "$PLUGIN_DIR/plugin.conf"
fi
if grep -q '^active=' "$PLUGIN_DIR/plugin.conf"; then
    sed -i 's/^active=.*/active=yes/' "$PLUGIN_DIR/plugin.conf"
else
    echo 'active=yes' >> "$PLUGIN_DIR/plugin.conf"
fi
note "plugin marked active and installed"

echo ""
echo "Installation complete."
echo "Reseller page: /CMD_PLUGINS_RESELLER/openlitespeed_reload/index.html"
echo "Admin page:    /CMD_PLUGINS_ADMIN/openlitespeed_reload/index.html"
echo "Authorize a reseller by adding its username on its own line in:"
echo "  $ALLOWLIST_FILE"
