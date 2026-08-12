#!/bin/sh
set -eu

REPO_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
PLUGIN_SRC="$REPO_DIR/plugin"
DIST_DIR="$REPO_DIR/dist"
PLUGIN_ID=openlitespeed_reload

version=$(grep '^version=' "$PLUGIN_SRC/plugin.conf" | head -n 1 | cut -d= -f2)
if [ -z "$version" ]; then
    echo "ERROR: could not read version from plugin.conf" >&2
    exit 1
fi

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -a "$PLUGIN_SRC/." "$stage/"
find "$stage/config" -type f ! -name allowed_resellers.example -delete

mkdir -p "$DIST_DIR"
plain_tar="$DIST_DIR/$PLUGIN_ID.tar"
tarball="$plain_tar.gz"
rm -f "$plain_tar" "$tarball"
tar --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' \
    -cf "$plain_tar" -C "$stage" \
    plugin.conf lib images config
tar --owner=0 --group=0 --numeric-owner --mode='u=rwx,go=rx' \
    -rf "$plain_tar" -C "$stage" \
    reseller admin scripts
gzip -fn "$plain_tar"

if ! tar -tzf "$tarball" | grep -qx 'plugin.conf'; then
    echo "ERROR: plugin.conf is not at the archive root" >&2
    exit 1
fi
if tar -tzf "$tarball" | grep -q "^$PLUGIN_ID/"; then
    echo "ERROR: archive must not contain a top-level plugin directory" >&2
    exit 1
fi
echo "OK: built $tarball (version $version)"
echo "    Upload this file in DirectAdmin: Admin > Plugin Manager > Add Plugin"
echo "    Do not rename it; DirectAdmin derives the plugin id from the archive."

zipfile="$DIST_DIR/$PLUGIN_ID.zip"
if command -v zip >/dev/null 2>&1; then
    rm -f "$zipfile"
    (cd "$stage" && zip -qr "$zipfile" .)
    echo "OK: built $zipfile (inspection copy only; Plugin Manager requires the .tar.gz)"
elif python3 -c 'import shutil' >/dev/null 2>&1; then
    rm -f "$zipfile"
    (cd "$stage" && ZIP_BASE="${zipfile%.zip}" python3 -c 'import os, shutil; shutil.make_archive(os.environ["ZIP_BASE"], "zip", ".")')
    echo "OK: built $zipfile (inspection copy only; Plugin Manager requires the .tar.gz)"
else
    echo "NOTE: zip and python3 not found, skipped optional .zip archive"
fi
