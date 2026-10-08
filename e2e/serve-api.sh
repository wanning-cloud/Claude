#!/bin/sh
# Starts the PHP API with a fresh TEST database for Playwright. Never used on the server.
set -e
DIR=$(mktemp -d)
cat > "$DIR/test.env" <<ENV
APP_ENV=dev
DEV_AUTH_USER=Markus (Test)
DATABASE_PATH=$DIR/test.sqlite
ADMIN_TOKEN_SECRET=e2e-secret
CRON_KEY=e2e-cron-key-000000000000000
FEED_URL=http://127.0.0.1:9/feed.xml
ENV
APP_ENV=test DATABASE_PATH="$DIR/test.sqlite" php apps/api/bin/seed-test.php
exec env PHP_CLI_SERVER_WORKERS=4 ANALYTICS_ENV_FILE="$DIR/test.env" php -S 127.0.0.1:8787 -t apps/api/public apps/api/public/router-dev.php
