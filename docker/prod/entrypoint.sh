#!/bin/sh
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo 'Falta APP_KEY. Genera una con: echo "base64:$(openssl rand -base64 32)"' >&2
    exit 1
fi

# storage es un volumen: puede venir vacío la primera vez.
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# Solo PHP-FPM prepara la aplicación. Reverb y los comandos de consola no migran ni compilan cachés.
if [ "$1" = "php-fpm" ]; then
    until pg_isready -h "$DB_HOST" -p "${DB_PORT:-5432}" -U "$DB_USERNAME" >/dev/null 2>&1; do
        sleep 1
    done

    php artisan migrate --force --no-interaction
    php artisan optimize --no-interaction
fi

exec "$@"
