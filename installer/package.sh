#!/bin/sh
set -eu

REPO_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PLUGIN_SRC="$REPO_DIR/plugin"
DIST_DIR="$REPO_DIR/dist"

version=$(grep '^version=' "$PLUGIN_SRC/plugin.conf" | head -n 1 | cut -d= -f2)
if [ -z "$version" ]; then
    echo "ERROR: could not read version from plugin.conf" >&2
    exit 1
fi

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir "$stage/openlitespeed_reload"
cp -a "$PLUGIN_SRC/." "$stage/openlitespeed_reload/"
find "$stage/openlitespeed_reload/config" -type f ! -name allowed_resellers.example -delete
chmod 755 "$stage/openlitespeed_reload/reseller/index.html" \
    "$stage/openlitespeed_reload/reseller/menu.json.raw" \
    "$stage/openlitespeed_reload/admin/index.html" \
    "$stage/openlitespeed_reload/scripts/install.sh" \
    "$stage/openlitespeed_reload/scripts/uninstall.sh"

mkdir -p "$DIST_DIR"
archive="$DIST_DIR/openlitespeed_reload-$version.tar.gz"
tar -czf "$archive" -C "$stage" openlitespeed_reload
echo "OK: built $archive"
