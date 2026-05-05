#!/bin/sh
set -e

# Ensure storage is writable by www-data BEFORE we run any artisan
# command — otherwise any Log::* call from a migration (running as
# root in this entrypoint) creates laravel.log root-owned, and the
# PHP-FPM workers running as www-data 500 on every request that logs.
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "Running migrations..."
php /var/www/html/artisan migrate --force

echo "Running seeders..."
php /var/www/html/artisan db:seed --force

echo "Checking GTFS data..."
GTFS_COUNT=$(php /var/www/html/artisan tinker --execute="echo \App\Models\Gtfs\GtfsStop::count();" 2>/dev/null | tail -1)
if [ "$GTFS_COUNT" = "0" ] || [ -z "$GTFS_COUNT" ]; then
    echo "No GTFS data — importing (this takes a few minutes on first deploy)..."
    php /var/www/html/artisan gtfs:refresh || echo "GTFS import failed, will retry on next weekly schedule"
fi

echo "Caching config..."
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache
php /var/www/html/artisan view:cache

# After all root-run artisan commands, ensure ownership is www-data
# again (cache files just got written as root).
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true

echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
