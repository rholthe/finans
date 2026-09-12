#!/usr/bin/env bash
set -euo pipefail

if [ ! -L /var/www/html/public/storage ]; then
    php artisan storage:link
fi

# Config-/rute-/view-cachen lever i containerens skrivbare lag og forsvinner ved
# hver gjenoppretting (`docker compose up -d`). Bygg den derfor ved hver oppstart
# i stedet for i deploy-scriptet – da speiler den alltid miljøvariablene
# containeren faktisk ble startet med, og alle tre containerne får den.
#
# Cache er ren optimalisering: feiler den, skal containeren fortsatt starte
# (appen leser da config/ruter direkte). Derfor best effort, ikke `set -e`-stopp.
for cache in config route view; do
    if ! php artisan "${cache}:cache"; then
        echo "ADVARSEL: php artisan ${cache}:cache feilet - fortsetter uten ${cache}-cache." >&2
    fi
done

exec "$@"
