#!/bin/sh
set -e
cd /var/www/html
php artisan storage:link || true
php artisan config:cache
php artisan migrate --force
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf