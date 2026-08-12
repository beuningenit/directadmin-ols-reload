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

mkdir -p "$DIST_DIR"
plain_tar="$DIST_DIR/openlitespeed_reload-$version.tar"
tarball="$plain_tar.gz"
rm -f "$plain_tar" "$tarball"
tar --owner=0 --group=0 --numeric-owner --mode='u=rwX,go=rX' \
    -cf "$plain_tar" -C "$stage" \
    openlitespeed_reload/plugin.conf \
    openlitespeed_reload/lib \
    openlitespeed_reload/images \
    openlitespeed_reload/config
tar --owner=0 --group=0 --numeric-owner --mode='u=rwx,go=rx' \
    -rf "$plain_tar" -C "$stage" \
    openlitespeed_reload/reseller \
    openlitespeed_reload/admin \
    openlitespeed_reload/scripts
gzip -fn "$plain_tar"
echo "OK: built $tarball"
echo "    Upload this file in DirectAdmin: Admin > Plugin Manager > Add Plugin"

zipfile="$DIST_DIR/openlitespeed_reload-$version.zip"
if command -v zip >/dev/null 2>&1; then
    rm -f "$zipfile"
    (cd "$stage" && zip -qr "$zipfile" openlitespeed_reload)
    echo "OK: built $zipfile (archive copy; DirectAdmin Plugin Manager requires the .tar.gz)"
elif python3 -c 'import shutil' >/dev/null 2>&1; then
    rm -f "$zipfile"
    (cd "$stage" && ZIP_BASE="${zipfile%.zip}" python3 -c 'import os, shutil; shutil.make_archive(os.environ["ZIP_BASE"], "zip", ".", "openlitespeed_reload")')
    echo "OK: built $zipfile (archive copy; DirectAdmin Plugin Manager requires the .tar.gz)"
else
    echo "NOTE: zip and python3 not found, skipped optional .zip archive"
fi
