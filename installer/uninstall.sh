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

if [ -x "$TARGET/scripts/uninstall.sh" ]; then
    sh "$TARGET/scripts/uninstall.sh"
fi

rm -rf "$TARGET"
echo "OK: removed $TARGET"
echo "Uninstall complete."
