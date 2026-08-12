#!/bin/sh
set -eu

REPO_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PLUGIN_SRC="$REPO_DIR/plugin"
TARGET=/usr/local/directadmin/plugins/openlitespeed_reload

if [ "$(id -u)" != "0" ]; then
    echo "ERROR: run this installer as root" >&2
    exit 1
fi
if [ ! -f "$PLUGIN_SRC/plugin.conf" ]; then
    echo "ERROR: plugin sources not found at $PLUGIN_SRC" >&2
    exit 1
fi

umask 022
mkdir -p "$TARGET"
chown root:root "$TARGET"
chmod 755 "$TARGET"
for item in "$PLUGIN_SRC"/*; do
    base=$(basename "$item")
    if [ "$base" = "config" ]; then
        continue
    fi
    rm -rf "${TARGET:?}/$base"
    cp -R "$item" "$TARGET/"
done
mkdir -p "$TARGET/config"
cp "$PLUGIN_SRC/config/allowed_resellers.example" "$TARGET/config/allowed_resellers.example"
chown -R root:root "$TARGET"
chmod -R go-w "$TARGET"
chmod 700 "$TARGET/config"
echo "OK: plugin files copied to $TARGET as root:root (existing config preserved)"

sh "$TARGET/scripts/install.sh"
