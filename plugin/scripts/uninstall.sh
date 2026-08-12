#!/bin/sh
set -eu

CONFIG_DIR=/etc/directadmin-openlitespeed-reload
ALLOWLIST_FILE="$CONFIG_DIR/allowed_resellers"
PLUGIN_DIR=/usr/local/directadmin/plugins/openlitespeed_reload
LOG_FILE=/var/log/directadmin-openlitespeed-reload.log
LOGROTATE_FILE=/etc/logrotate.d/directadmin-openlitespeed-reload

if [ "$(id -u)" != "0" ]; then
    echo "ERROR: uninstall must run as root" >&2
    exit 1
fi

rm -f "$LOGROTATE_FILE"
echo "OK: logrotate configuration removed"

if [ -f "$PLUGIN_DIR/plugin.conf" ]; then
    sed -i 's/^installed=.*/installed=no/' "$PLUGIN_DIR/plugin.conf"
    sed -i 's/^active=.*/active=no/' "$PLUGIN_DIR/plugin.conf"
    echo "OK: plugin marked inactive"
fi

if [ -s "$ALLOWLIST_FILE" ]; then
    echo "OK: authorized resellers kept at $ALLOWLIST_FILE"
    echo "    Reinstalling or uploading a newer version will pick them up automatically."
    echo "    To erase them, run: rm -rf $CONFIG_DIR"
else
    echo "OK: no authorized resellers configured"
fi

echo "NOTE: audit log kept at $LOG_FILE (remove manually if no longer wanted)"
echo "Uninstall tasks complete."
