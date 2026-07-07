#!/bin/sh
set -e

mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs/services bootstrap/cache

if [ -z "$APP_KEY" ]; then
    echo "WARNING: APP_KEY is not set. Generating an ephemeral one for this boot only." >&2
    echo "Set a permanent APP_KEY in Coolify's environment variables (see COOLIFY.md)." >&2
    export APP_KEY="$(php artisan key:generate --show)"
fi

echo "Waiting for database at ${DB_HOST:-mysql}:${DB_PORT:-3306}..."
attempt=0
until php artisan db:show > /dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 30 ]; then
        echo "Database did not become reachable in time." >&2
        exit 1
    fi
    sleep 2
done

php artisan storage:link --no-interaction 2>/dev/null || true
php artisan migrate --force --no-interaction
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www-data:www-data storage bootstrap/cache

exec "$@"
