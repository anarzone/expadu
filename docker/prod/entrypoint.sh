#!/bin/sh
set -e

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

echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
