#!/usr/bin/env bash
#
# Deploy-script for Finans (prod: finans.example.com, /var/www/finans).
#
# Kjøres som brukeren som eier koden (ragnar) – ingen sudo nødvendig:
#   ./deploy.sh
#
# Kø-arbeideren resirkuleres med `queue:restart` (grasiøs: arbeideren
# avslutter etter pågående jobb og Supervisor starter den på nytt).
# Apache (mod_php) trenger ingen restart.

set -euo pipefail

cd "$(dirname "$0")"

echo "==> Maintenance-modus på"
php artisan down --render="errors::503" || true
# Sørg for at appen alltid kommer opp igjen, også ved feil underveis.
trap 'php artisan up || true' EXIT

echo "==> Henter siste kode"
git pull --ff-only

echo "==> PHP-avhengigheter"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Bygger frontend"
npm ci
npm run build

echo "==> Migrerer database"
php artisan migrate --force

echo "==> Cacher config/ruter/views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Resirkulerer kø-arbeider"
php artisan queue:restart

echo "==> Ferdig"
