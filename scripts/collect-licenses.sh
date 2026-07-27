#!/usr/bin/env bash
# Assemble THIRD-PARTY-LICENSES from the production Composer (PHP) and npm
# (frontend) dependencies. Run after `composer install --no-dev` and the frontend
# build so vendor/ and node_modules/ are populated. The output ships with the app
# (scripts/package.sh puts it into the release archive).
#
# Usage: scripts/collect-licenses.sh [output-file]   (default: ./THIRD-PARTY-LICENSES)
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
OUT="${1:-$ROOT/THIRD-PARTY-LICENSES}"

{
	echo "Atrium Secureshare (Nextcloud app) — Third-Party License Notices"
	echo "================================================================="
	echo
	echo "This app is licensed under AGPL-3.0-or-later (see LICENSE). It bundles the"
	echo "third-party components below, each under its own license."
	echo
	echo "################################################################################"
	echo "# Composer (PHP) dependencies — production only"
	echo "################################################################################"
	echo

	# Summary of every installed production package (composer reads the installed
	# set, which is production-only after `composer install --no-dev`).
	if command -v composer >/dev/null 2>&1; then
		composer licenses --format=text 2>/dev/null || true
		echo
	fi

	find vendor -type f \
		\( -iname 'LICENSE*' -o -iname 'NOTICE*' -o -iname 'COPYING*' \) 2>/dev/null |
		sort |
		while IFS= read -r lic; do
			pkg=$(dirname "${lic#vendor/}")
			echo "================================================================================"
			echo "$pkg — $(basename "$lic")"
			echo "================================================================================"
			echo
			cat "$lic"
			echo
		done

	echo
	echo "################################################################################"
	echo "# npm dependencies (frontend, runtime only)"
	echo "################################################################################"
	echo
} >"$OUT"

node "$ROOT/scripts/collect-npm-licenses.mjs" >>"$OUT"

echo "wrote $OUT"
