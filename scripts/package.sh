#!/usr/bin/env bash
# Build the distributable app archive: <outdir>/atrium_secureshare-<version>.tar.gz
#
# The archive holds a single top-level atrium_secureshare/ directory — the layout
# Nextcloud expects, so it can be unpacked straight into custom_apps/ (and is the
# shape the App Store consumes).
#
# Requires: node/npm, php/composer, tar.
# Usage: scripts/package.sh [outdir]   (default: ./dist)
set -euo pipefail

APP_ID="atrium_secureshare"

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"
OUT_DIR="${1:-$REPO_ROOT/dist}"

# appinfo/info.xml is the single source of truth for the app version (release-please
# keeps it in step with the git tag).
VERSION="$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml | head -1)"
[ -n "$VERSION" ] || { echo "no <version> found in appinfo/info.xml" >&2; exit 1; }

echo ">> Building frontend"
[ -d node_modules ] || npm ci
npm run build

echo ">> Installing PHP dependencies (vendor/, production only)"
command -v composer >/dev/null || { echo "composer not found on PATH" >&2; exit 1; }
composer install --no-dev --optimize-autoloader --no-interaction

echo ">> Collecting third-party license notices"
# Generated from the production vendor/ and the node_modules/ tree just built
# above, so the shipped notices always match the shipped dependencies.
scripts/collect-licenses.sh "$REPO_ROOT/THIRD-PARTY-LICENSES"

echo ">> Staging $APP_ID $VERSION"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/$APP_ID"
# Ship only what the app needs at runtime — not src/, node_modules, tests or
# build config. vendor/ carries the bundled Composer deps (firebase/php-jwt);
# l10n/ holds the catalogs the server auto-loads for the user's language (the .js
# in it were regenerated from the .json source by the build above).
cp -r appinfo lib js vendor templates img l10n "$STAGE/$APP_ID/"
cp LICENSE THIRD-PARTY-LICENSES "$STAGE/$APP_ID/"

mkdir -p "$OUT_DIR"
TARBALL="$OUT_DIR/$APP_ID-$VERSION.tar.gz"
tar -czf "$TARBALL" -C "$STAGE" "$APP_ID"
echo "wrote $TARBALL"
