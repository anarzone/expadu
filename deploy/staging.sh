#!/usr/bin/env bash
# Staging's deploy, run on the box: CI pipes it over SSH after pushing the image,
# and `bin/deploy staging` does the same after loading an image it built itself
# (PULL=0, so the loaded image isn't replaced by the registry's).
#
# A failing step fails the deploy; without it the catalogue steps hit missing
# tables and the deploy still showed green.
# -E: the rollback below also catches a step failing inside artisan().
set -Eeuo pipefail

# One deploy at a time on this box, staging's and production's alike, whoever started it
# (CI or bin/deploy): a second one replacing the container kills the first one's steps, even
# a migration, and its image prune can take the image the first would roll back to. Fifteen
# minutes is far longer than a deploy takes; past that, nothing has started and the deploy fails.
exec 9>/run/lock/expadu-deploy.lock
flock --timeout 900 9 || { echo "Another deploy held the lock for 15 minutes; nothing deployed." >&2; exit 1; }

cd /data/staging
# The image running now, so a deploy that doesn't come up can be rolled back.
PREVIOUS=$(docker inspect --format '{{.Image}}' staging-app 2>/dev/null || true)

if [ "${PULL:-1}" = 1 ]; then
  docker compose pull app
fi

# Staging's compose says pull_policy: always, so a plain `up` would fetch the
# registry's image again: over a loaded image (PULL=0) or a rolled-back one.
# The app only: production plans its routes on this file's motis, which a staging
# deploy mustn't restart. motis has its own weekly swap (infra/motis/swap.sh).
docker compose up -d --no-deps --force-recreate --pull never app

artisan() { docker exec -u www-data staging-app php artisan "$@"; }

# Back to the image that was running, set up again as a deploy sets it up, its catalogue
# rebuilt so it matches that image (a mismatch takes Today and Paperwork down).
# Migrations stay: only the image goes back.
rollback() {
  trap - ERR
  set +e
  if [ -z "$PREVIOUS" ]; then
    echo "No previous image to go back to." >&2
    exit 1
  fi
  docker tag "$PREVIOUS" "$(docker inspect --format '{{.Config.Image}}' staging-app)"
  docker compose up -d --no-deps --force-recreate --pull never app
  sleep 5
  artisan config:cache
  artisan route:cache
  artisan view:cache
  artisan bureaucracy:import-tasks --retire-missing \
    && artisan bureaucracy:verify-sources \
    && artisan bureaucracy:compile-catalogue --deploy \
    || echo "The catalogue didn't rebuild on the previous image: check Today and Paperwork." >&2
  exit 1
}
# Any step that fails from here brings the previous image back, instead of leaving the new
# one running half set up (4 Nov 2026: the catalogue step stopped, Today answered 500).
trap 'echo "A deploy step failed; rolling back." >&2; rollback' ERR

sleep 5

# Staging's compose replaces the image entrypoint (fix-nginx.sh), so nothing
# migrates or caches on boot: migrate and build the framework caches here (as the
# image's entrypoint does on prod), before the deploy-time catalogue steps
# (import, source check, release, dossiers), all run as www-data so the files
# they write stay writable by the app.
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

echo "Staging did not answer /up after the deploy; rolling back." >&2
rollback
