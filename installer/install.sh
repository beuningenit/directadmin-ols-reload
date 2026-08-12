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

mkdir -p "$TARGET"
for item in "$PLUGIN_SRC"/*; do
    base=$(basename "$item")
    if [ "$base" = "config" ]; then
        continue
    fi
    rm -rf "${TARGET:?}/$base"
    cp -a "$item" "$TARGET/"
done
mkdir -p "$TARGET/config"
cp "$PLUGIN_SRC/config/allowed_resellers.example" "$TARGET/config/allowed_resellers.example"
echo "OK: plugin files copied to $TARGET (existing config preserved)"

sh "$TARGET/scripts/install.sh"
