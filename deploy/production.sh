#!/usr/bin/env bash
# Production's deploy, run on the box: CI pipes it over SSH after pushing the image,
# and `bin/deploy production` does the same after loading an image it built itself
# (PULL=0, so the loaded image isn't replaced by the registry's).
set -euo pipefail

cd /data/coolify/services/eg4gsc4ok8g0cwkcsso48wso
# Coolify names the container app-<service dir>; derived so the literal never
# appears here (it matches the Dify API-key pattern secret scanners look for).
APP_CONTAINER="app-$(basename "$PWD")"
# The image running now, so a deploy that doesn't come up can be rolled back.
PREVIOUS=$(docker inspect --format '{{.Image}}' "$APP_CONTAINER" 2>/dev/null || true)

if [ "${PULL:-1}" = 1 ]; then
  docker pull ghcr.io/anarzone/expadu/app:latest
fi

# Production's compose says pull_policy: always, so a plain `up` would fetch the
# registry's image again: over a loaded image (PULL=0) or a rolled-back one.
docker compose up -d --pull never app
sleep 5

# The entrypoint migrates before PHP-FPM starts; these are the deploy-time
# catalogue steps (import, source check, release, dossiers), run as www-data so
# the log files they create stay writable by the app.
artisan() { docker exec -u www-data "$APP_CONTAINER" php artisan "$@"; }
artisan bureaucracy:import-tasks --retire-missing
artisan bureaucracy:verify-sources
artisan bureaucracy:compile-catalogue --deploy
artisan bureaucracy:migrate-dossiers --apply --all

# Health: /up answers within a minute, or the previous image comes back and the
# deploy fails. Pruned only after, so the previous image is still there to return to.
for attempt in $(seq 1 12); do
  if curl -fsS -o /dev/null https://app.expadu.com/up; then
    docker image prune -f
    exit 0
  fi
  sleep 5
done

echo "Production did not answer /up after the deploy; rolling back."
if [ -n "$PREVIOUS" ]; then
  docker tag "$PREVIOUS" ghcr.io/anarzone/expadu/app:latest
  docker compose up -d --pull never app
fi
exit 1
