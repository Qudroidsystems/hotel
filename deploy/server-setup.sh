#!/usr/bin/env bash
# One-time server setup for Ubuntu 24.04: Nginx, PHP 8.3, MariaDB, Composer, Certbot, rclone.
#   sudo bash deploy/server-setup.sh
set -euo pipefail

apt-get update
apt-get install -y software-properties-common curl unzip git
apt-get install -y nginx mariadb-server \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-mbstring php8.3-xml php8.3-curl php8.3-gd php8.3-zip php8.3-intl php8.3-bcmath \
  certbot python3-certbot-nginx rclone

# Composer
if ! command -v composer >/dev/null; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# PHP: bigger uploads (room photos, logos) and the hotel's time zone
for ini in /etc/php/8.3/fpm/php.ini /etc/php/8.3/cli/php.ini; do
  sed -i 's/^upload_max_filesize.*/upload_max_filesize = 10M/; s/^post_max_size.*/post_max_size = 12M/; s#^;*date.timezone.*#date.timezone = Africa/Lagos#' "$ini"
done
systemctl restart php8.3-fpm

# Database: MariaDB with the same settings the system is tested on
cat > /etc/mysql/mariadb.conf.d/90-hotel.cnf <<'CNF'
[mysqld]
explicit_defaults_for_timestamp = OFF
character-set-server = utf8mb4
collation-server = utf8mb4_unicode_ci
CNF
systemctl restart mariadb

mkdir -p /var/www /var/backups/hotel
chown www-data:www-data /var/backups/hotel

# Guest voice calls: coturn relays calls on mobile data (configure with deploy/turnserver.conf)
if [ "${WITH_TURN:-yes}" = "yes" ]; then
  apt-get install -y coturn
  sed -i 's/^#*TURNSERVER_ENABLED=.*/TURNSERVER_ENABLED=1/' /etc/default/coturn 2>/dev/null || true
fi

# Firewall: web and SSH (and the call relay's ports when coturn is installed)
if command -v ufw >/dev/null; then
  ufw allow OpenSSH && ufw allow 'Nginx Full'
  if command -v turnserver >/dev/null; then
    ufw allow 3478 && ufw allow 5349/tcp && ufw allow 49152:65535/udp
  fi
  ufw --force enable
fi

echo "Done. Next: create the database (see the launch guide), then clone the code into /var/www/hotel."
