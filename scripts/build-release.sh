#!/bin/sh
# Builds the upload folder for monteur-podcast.de (variant 2, all-inkl):
#   release/analytics/            → podcast-admin/analytics/      (React build + .htaccess)
#   release/analytics/api/        → podcast-admin/analytics/api/  (front controller + .htaccess)
#   release/analytics/api/app/    → code, schema, migrations; denied by .htaccess
# Nothing secret goes in here: analytics.env stays on the server, outside the web root.
set -eu
ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT="$ROOT/release/analytics"
rm -rf "$ROOT/release"
mkdir -p "$OUT/api/app"

npm run --prefix "$ROOT" schema >/dev/null
(cd "$ROOT/apps/web" && npx vite build >/dev/null)
cp -R "$ROOT/apps/web/dist/." "$OUT/"

cp "$ROOT/apps/api/public/index.php" "$ROOT/apps/api/public/.htaccess" "$OUT/api/"
cp -R "$ROOT/apps/api/src" "$ROOT/apps/api/schema" "$ROOT/apps/api/migrations" "$OUT/api/app/"
mkdir -p "$OUT/api/app/data"
cp "$ROOT/apps/api/data/.htaccess" "$OUT/api/app/data/.htaccess"
cp "$ROOT/apps/api/app.htaccess" "$OUT/api/app/.htaccess"
cp "$ROOT/apps/api/bin/cron.php" "$OUT/api/app/cron-cli.php"

# Guard: no env files, databases or dev entry points in the upload.
if find "$OUT" \( -name '*.env' -o -name '*.sqlite*' -o -name 'router-dev.php' -o -name 'seed-test.php' \) | grep -q .; then
  echo "Abbruch: verbotene Datei im Release." >&2
  exit 1
fi
VERSION=$(git -C "$ROOT" rev-parse --short HEAD)
echo "$VERSION $(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$OUT/api/app/VERSION"
echo "Release $VERSION in $OUT"
find "$OUT" -type f | sed "s#$ROOT/##" | sort
