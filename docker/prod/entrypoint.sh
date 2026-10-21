#!/bin/sh
set -e

# Ensure storage is writable by www-data BEFORE we run any artisan
# command — otherwise any Log::* call from a migration (running as
# root in this entrypoint) creates laravel.log root-owned, and the
# PHP-FPM workers running as www-data 500 on every request that logs.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

# Boot does only what the new code needs before it serves: the schema and
# the framework caches. Seeding, the Paperwork import and a first GTFS import
# are deploy or one-off steps (see the CI deploy jobs), never boot work — boot
# runs on every restart, and anything slow here is downtime.
echo "Running migrations..."
php /var/www/html/artisan migrate --force

echo "Caching config..."
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache
php /var/www/html/artisan view:cache
# Optional speed-ups: a failure here must not stop the container booting.
php /var/www/html/artisan event:cache || echo "event:cache failed; events are discovered at runtime"
php /var/www/html/artisan filament:optimize || echo "filament:optimize failed; panels resolve at runtime"

# A command killed by the last deploy keeps its withoutOverlapping lock in
# Redis (AOF-persisted), which would skip it for up to a day. Nothing scheduled
# is running yet at this point, so every lock left is stale.
echo "Clearing stale schedule locks..."
php /var/www/html/artisan schedule:clear-cache || echo "Could not clear schedule locks"

# After all root-run artisan commands, ensure ownership is www-data
# again (cache files just got written as root).
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
