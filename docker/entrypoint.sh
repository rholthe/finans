#!/usr/bin/env bash
set -euo pipefail

if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link
fi

exec "$@"
