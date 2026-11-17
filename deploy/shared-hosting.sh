#!/usr/bin/env bash
#
# Deploy (or update) the app on shared hosting with SSH, e.g. Hostinger.
#
#   bash shared-hosting.sh forge.example.com
#
# Layout: the code lives in ~/domains/<domain>/app and the web root
# (~/domains/<domain>/public_html) becomes a symlink to app/public, so nothing
# outside public/ is reachable from the web. Built assets are committed, so no
# Node is needed on the server. Safe to run again to deploy a new version.
set -euo pipefail

DOMAIN="${1:?Usage: bash shared-hosting.sh <domain>}"
REPO="${REPO:-https://github.com/dev7manojverma-creator/pixelforge-app.git}"
ROOT="$HOME/domains/$DOMAIN"
APP="$ROOT/app"
COMPOSER="$(command -v composer2 || command -v composer)"

[ -d "$ROOT" ] || { echo "No such site: $ROOT. Create the (sub)domain in the hosting panel first."; exit 1; }

if [ -d "$APP/.git" ]; then
    git -C "$APP" pull --ff-only
else
    git clone "$REPO" "$APP"
fi

cd "$APP"
"$COMPOSER" install --no-dev --optimize-autoloader --no-interaction

if [ ! -f .env ]; then
    cp .env.example .env
    sed -i "s#^APP_ENV=.*#APP_ENV=production#; s#^APP_DEBUG=.*#APP_DEBUG=false#; s#^APP_URL=.*#APP_URL=https://$DOMAIN#" .env
    php artisan key:generate --force
    echo
    echo ">> Created $APP/.env. Fill in PIXELFORGE_API_KEY, PIXELFORGE_WEBHOOK_SECRET and ADMIN_EMAILS"
    echo ">> (and the DB_* settings if you use MySQL), then run this script again."
fi

# SQLite needs its database file to exist before the first migration.
grep -q '^DB_CONNECTION=sqlite' .env && touch database/database.sqlite

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Point the web root at public/. An existing public_html is kept as a backup, never deleted.
if [ ! -L "$ROOT/public_html" ]; then
    [ -e "$ROOT/public_html" ] && mv "$ROOT/public_html" "$ROOT/public_html.backup-$(date +%s)"
    ln -s "$APP/public" "$ROOT/public_html"
fi

cat <<EOF

Deployed to https://$DOMAIN

Add these two cron jobs (every minute) in the hosting panel if you have not yet:

  cd $APP && php artisan schedule:run >> /dev/null 2>&1
  cd $APP && php artisan queue:work --max-time=55 --tries=1 >> /dev/null 2>&1

The second one is the queue worker: it runs for 55 seconds and is started again
by cron the next minute, which is how a worker is kept alive without a process manager.
EOF
