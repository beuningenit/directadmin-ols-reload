#!/bin/sh
set -eu

PLUGIN_DIR=/usr/local/directadmin/plugins/openlitespeed_reload
LOG_FILE=/var/log/directadmin-openlitespeed-reload.log
LOGROTATE_FILE=/etc/logrotate.d/directadmin-openlitespeed-reload
ALLOWLIST_FILE="$PLUGIN_DIR/config/allowed_resellers"

if [ "$(id -u)" != "0" ]; then
    echo "ERROR: uninstall must run as root" >&2
    exit 1
fi

if [ -s "$ALLOWLIST_FILE" ]; then
    backup="/root/openlitespeed_reload-allowed_resellers-$(date +%Y%m%d%H%M%S).bak"
    (umask 077; cat "$ALLOWLIST_FILE" > "$backup")
    echo "OK: allowlist backed up to $backup"
fi

rm -f "$LOGROTATE_FILE"
echo "OK: logrotate configuration removed"

if [ -f "$PLUGIN_DIR/plugin.conf" ]; then
    sed -i 's/^installed=.*/installed=no/' "$PLUGIN_DIR/plugin.conf"
    sed -i 's/^active=.*/active=no/' "$PLUGIN_DIR/plugin.conf"
    echo "OK: plugin marked inactive"
fi

echo "NOTE: audit log kept at $LOG_FILE (remove manually if no longer wanted)"
echo "Uninstall tasks complete."
