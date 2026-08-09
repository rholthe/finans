#!/usr/bin/env bash
# Deploy-script for finans.holthe.org på ny VPS (Docker-basert, 3 containere:
# web/worker/scheduler bygget fra samme image).
# Kjøres på serveren: ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

echo "==> Henter siste kode"
git pull --ff-only

echo "==> Bygger nye images (web, worker, scheduler)"
docker compose build

echo "==> Bytter til nye containere"
docker compose up -d

echo "==> Venter på at appen er oppe"
sleep 3

echo "==> Migrerer database"
docker exec finans-web php artisan migrate --force

echo "==> Cacher config/ruter/views"
docker exec finans-web php artisan config:cache
docker exec finans-web php artisan route:cache
docker exec finans-web php artisan view:cache

echo "==> Restarter alle tre containere slik at endringer tas i bruk"
docker compose restart

echo "==> Ferdig"
