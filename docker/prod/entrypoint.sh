#!/bin/sh
set -e

echo "Running migrations..."
php /var/www/html/artisan migrate --force

echo "Running seeders..."
php /var/www/html/artisan db:seed --force

echo "Caching config..."
php /var/www/html/artisan config:cache
php /var/www/html/artisan route:cache
php /var/www/html/artisan view:cache

echo "Starting supervisor..."
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
