#!/bin/sh
# Render start script. Docker Command on Render: sh render-start.sh
# Runs every migration dated 2026_11_25 or later (new ones are picked up automatically).
set -e
php artisan optimize:clear
PATHS=$(ls database/migrations | awk '$0 >= "2026_11_25"' | sed 's#^#--path=database/migrations/#' | tr '\n' ' ')
php artisan migrate --force $PATHS
# ZAD_SPEED: routes, views and events are read from one compiled file each.
php artisan route:cache || php artisan route:clear
php artisan view:cache || true
php artisan event:cache || true
php artisan zad:db-diagnose || true
php artisan schedule:work >> storage/logs/scheduler.log 2>&1 &
exec php artisan serve --no-reload --host=0.0.0.0 --port="${PORT:-10000}"
