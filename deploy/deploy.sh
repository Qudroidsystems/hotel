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

php artisan hotel:launch-check --brief || true
echo "Deployed $(git rev-parse --short HEAD) at $(date)."
