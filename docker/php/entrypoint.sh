#!/bin/sh
set -e

cd /var/www/html

[ -f .env ] || cp .env.example .env
[ -d vendor ] || composer install --no-interaction --prefer-dist

grep -q '^APP_KEY=.\+' .env || php artisan key:generate --no-interaction

if [ "$RUN_MIGRATIONS" = "true" ]; then
    until pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" >/dev/null 2>&1; do
        sleep 1
    done
    php artisan migrate --force --no-interaction
fi

exec "$@"
