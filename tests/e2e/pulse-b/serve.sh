#!/bin/sh
# Starts the app on :8791 against the last run's database (for poking around by hand or probe.mjs).
cd "$(dirname "$0")/../../.."
APP_ENV=local APP_DEBUG=false APP_URL=http://127.0.0.1:8791 DB_CONNECTION=sqlite DB_DATABASE="$PWD/tests/e2e/pulse-b/out/e2e.sqlite" \
SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null MAIL_MAILER=log LOG_CHANNEL=single \
PHP_CLI_SERVER_WORKERS=6 exec php artisan serve --port=8791 --no-reload
