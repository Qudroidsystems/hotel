# Putting the hotel system on a live server

Follow the launch guide (shared with you as "B Luxury — Launch Guide"). The files here:

| File | What it is |
| --- | --- |
| `server-setup.sh` | One-time install on Ubuntu 24.04: Nginx, PHP 8.3, MariaDB, Composer, Certbot, rclone |
| `env.production.example` | The live `.env`: copy to `/var/www/hotel/.env` and fill in the `<...>` values |
| `nginx.conf` | The website's Nginx site; then `certbot --nginx -d <domain>` for HTTPS |
| `crontab` | The scheduler (every timed job) and the nightly backup: `sudo crontab -u www-data deploy/crontab` |
| `backup.sh` | Database + uploaded files to `/var/backups/hotel`, kept 30 days, copied to `BACKUP_REMOTE` |
| `deploy.sh` | Put a new version live: `bash deploy/deploy.sh` |

Check the server at any time: `php artisan hotel:launch-check` (red = fix before going live).
