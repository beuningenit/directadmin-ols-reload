#!/bin/sh
set -eu

TARGET=/usr/local/directadmin/plugins/openlitespeed_reload
CONFIG_DIR=/etc/directadmin-openlitespeed-reload
LOG_FILE=/var/log/directadmin-openlitespeed-reload.log
PURGE=0

for arg in "$@"; do
    case "$arg" in
        --purge)
            PURGE=1
            ;;
        *)
            echo "Usage: $0 [--purge]" >&2
            echo "  --purge  also delete $CONFIG_DIR (authorized resellers and secret)" >&2
            exit 1
            ;;
    esac
done

if [ "$(id -u)" != "0" ]; then
    echo "ERROR: run this uninstaller as root" >&2
    exit 1
fi

if [ -d "$TARGET" ]; then
    if [ -f "$TARGET/scripts/uninstall.sh" ] && [ ! -L "$TARGET/scripts/uninstall.sh" ]; then
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
else
    echo "Plugin is not installed at $TARGET."
fi

if [ "$PURGE" = "1" ]; then
    rm -rf "$CONFIG_DIR"
    echo "OK: removed $CONFIG_DIR (authorized resellers and secret erased)"
    if ls /root/openlitespeed_reload-allowed_resellers-*.bak >/dev/null 2>&1; then
        rm -f /root/openlitespeed_reload-allowed_resellers-*.bak
        echo "OK: removed legacy allowlist backups from /root"
    fi
    echo "NOTE: audit log kept at $LOG_FILE"
else
    if [ -d "$CONFIG_DIR" ]; then
        echo "OK: configuration kept at $CONFIG_DIR (re-run with --purge to erase it)"
    fi
fi

echo "Uninstall complete."
