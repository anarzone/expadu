#!/usr/bin/env bash
# Staging's deploy, run on the box: CI pipes it over SSH after pushing the image,
# and `bin/deploy staging` does the same after loading an image it built itself
# (PULL=0, so the loaded image isn't replaced by the registry's).
#
# A failing step fails the deploy; without it the catalogue steps hit missing
# tables and the deploy still showed green.
set -euo pipefail

cd /data/staging
# The image running now, so a deploy that doesn't come up can be rolled back.
PREVIOUS=$(docker inspect --format '{{.Image}}' staging-app 2>/dev/null || true)

if [ "${PULL:-1}" = 1 ]; then
  docker compose pull
fi

# Staging's compose says pull_policy: always, so a plain `up` would fetch the
# registry's image again: over a loaded image (PULL=0) or a rolled-back one.
docker compose up -d --force-recreate --pull never
sleep 5

# Staging's compose replaces the image entrypoint (fix-nginx.sh), so nothing
# migrates or caches on boot: migrate and build the framework caches here (as the
# image's entrypoint does on prod), before the deploy-time catalogue steps
# (import, source check, release, dossiers), all run as www-data so the files
# they write stay writable by the app.
artisan() { docker exec -u www-data staging-app php artisan "$@"; }
artisan migrate --force
artisan config:cache
artisan route:cache
artisan view:cache
artisan event:cache || echo "event:cache failed; events are discovered at runtime"
artisan filament:optimize || echo "filament:optimize failed; panels resolve at runtime"
artisan schedule:clear-cache || echo "Could not clear schedule locks"
artisan bureaucracy:import-tasks --retire-missing
artisan bureaucracy:verify-sources
artisan bureaucracy:compile-catalogue --deploy
artisan bureaucracy:migrate-dossiers --apply --all

# Health: /up answers within a minute, or the previous image comes back and the
# deploy fails. Pruned only after, so the previous image is still there to return to.
for attempt in $(seq 1 12); do
  if curl -fsS -o /dev/null https://app.staging.expadu.com/up; then
    docker image prune -f
    exit 0
  fi
  sleep 5
done

echo "Staging did not answer /up after the deploy; rolling back."
if [ -n "$PREVIOUS" ]; then
  docker tag "$PREVIOUS" "$(docker inspect --format '{{.Config.Image}}' staging-app)"
  docker compose up -d --force-recreate --pull never
fi
exit 1
