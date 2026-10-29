#!/usr/bin/env bash
# Put a new version live:  cd /var/www/hotel && bash deploy/deploy.sh
set -euo pipefail
cd /var/www/hotel

php artisan down --retry=30 || true
trap 'php artisan up' EXIT

git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan db:seed --class=PermissionTableSeeder --force   # new permissions, if any
php artisan optimize:clear
php artisan optimize
sudo -n systemctl reload php8.3-fpm 2>/dev/null || true
# The real-time server picks up the new code (only when it's installed).
if systemctl list-unit-files hotel-reverb.service >/dev/null 2>&1; then php artisan reverb:restart 2>/dev/null || sudo -n systemctl restart hotel-reverb 2>/dev/null || true; fi

php artisan hotel:launch-check --brief || true
echo "Deployed $(git rev-parse --short HEAD) at $(date)."
