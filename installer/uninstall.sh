#!/bin/sh
set -eu

TARGET=/usr/local/directadmin/plugins/openlitespeed_reload

if [ "$(id -u)" != "0" ]; then
    echo "ERROR: run this uninstaller as root" >&2
    exit 1
fi
if [ ! -d "$TARGET" ]; then
    echo "Plugin is not installed at $TARGET, nothing to do."
    exit 0
fi

if [ -f "$TARGET/scripts/uninstall.sh" ]; then
    owner=$(stat -c %u "$TARGET/scripts/uninstall.sh" 2>/dev/null || echo unknown)
    if [ "$owner" = "0" ]; then
        sh "$TARGET/scripts/uninstall.sh"
    else
        echo "WARNING: $TARGET/scripts/uninstall.sh is not owned by root, skipping it" >&2
        rm -f /etc/logrotate.d/directadmin-openlitespeed-reload
    fi
fi

rm -rf "$TARGET"
echo "OK: removed $TARGET"
echo "Uninstall complete."
