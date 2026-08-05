#!/usr/bin/env bash
#
# Build the dashboard into the API's public directory.
#
# The appliance serves one thing from one process. This compiles the frontend
# and puts it where the API will find it; see backend/routes/web.php for why.
#
# Safe to re-run. The only thing removed is the previous build's assets, and
# that removal is deliberate: Vite writes content-hashed filenames, so without
# it every upgrade leaves the old bundles behind for ever.

set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PUBLIC="$REPO/backend/public"

cd "$REPO/frontend"

echo "==> Installing frontend dependencies"
npm ci --silent

echo "==> Running the frontend tests"
# Building an untested dashboard onto a live appliance is how a white screen
# gets deployed at five o'clock on a Friday.
npx vitest run --reporter=dot

echo "==> Clearing the previous build"
# Named explicitly rather than globbed. public/ also holds index.php, and a
# careless wildcard here would take the application down with the old assets.
rm -rf "$PUBLIC/assets"
rm -f "$PUBLIC/index.html" "$PUBLIC/vite.svg"

echo "==> Building"
npm run build

if [[ ! -f "$PUBLIC/index.html" ]]; then
    echo "Build reported success but produced no index.html in $PUBLIC." >&2
    echo "Check build.outDir in frontend/vite.config.ts." >&2
    exit 1
fi

echo
echo "Dashboard built into $PUBLIC"
echo "Restart the service to be certain it is being served:"
echo
echo "    sudo systemctl restart quakevault-dashboard"
