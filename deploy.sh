#!/usr/bin/env bash
# Deploy-script for prod (Docker-basert: 3 containere – web/worker/scheduler –
# bygget fra samme image, se docker-compose.yml).
# Kjøres på serveren fra prosjektroten: ./deploy.sh
set -euo pipefail
cd "$(dirname "$0")"

# Første pass: hent koden og start scriptet på nytt.
#
# `git pull` kan endre dette scriptet mens det kjører. Bash leser scriptet
# fra den allerede åpne inoden, så resten av kjøringen ville fortsatt brukt
# den GAMLE versjonen - endringer i deploy-flyten slo derfor først inn ved
# neste deploy. `exec` erstatter prosessen med den nye fila, én gang, styrt
# av DEPLOY_REEXEC slik at det ikke kan bli en løkke.
if [ "${DEPLOY_REEXEC:-}" != "1" ]; then
    SCRIPT="$PWD/$(basename "$0")"

    echo "==> Henter siste kode"
    git pull --ff-only

    echo "==> Starter deploy-scriptet på nytt fra den nye versjonen"
    export DEPLOY_REEXEC=1
    exec "$SCRIPT" "$@"
fi

echo "==> Bygger nye images (web, worker, scheduler)"
docker compose build

echo "==> Bytter til nye containere"
# Gjenoppretter containerne, som også plukker opp endringer i .env (verdiene der
# injiseres ved container-opprettelse). Config-/rute-/view-cache bygges av
# docker/entrypoint.sh ved oppstart, så den trenger ikke gjøres her - og den
# overlever dermed også gjenopprettinger utenom deploy.
docker compose up -d

echo "==> Venter på at appen er oppe"
sleep 3

echo "==> Migrerer database"
docker exec finans-web php artisan migrate --force

echo "==> Kontrollerer at cachen ble bygget ved oppstart"
for file in config.php routes-v7.php; do
    if docker exec finans-web test -f "bootstrap/cache/${file}"; then
        echo "    ok: bootstrap/cache/${file}"
    else
        echo "    ADVARSEL: bootstrap/cache/${file} mangler - se 'docker compose logs finans-web'" >&2
    fi
done

echo "==> Ferdig"
