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
| `hotel-reverb.service` | The real-time server for guest chat and calls (Laravel Reverb), run by systemd |
| `turnserver.conf` | coturn, the relay that makes guest voice calls work on mobile data |

## Live chat & calls

Guest chat and calls work out of the box: the apps and pages check for news every few seconds. Two extras make them
instant and reliable:

1. **Real-time server (instant messages and ringing).** On the server:
   ```
   cd /var/www/hotel && composer require laravel/reverb
   sudo cp deploy/hotel-reverb.service /etc/systemd/system/ && sudo systemctl enable --now hotel-reverb
   ```
   Then in `.env` (or Hotel Settings → Live Chat & Calls) set `REALTIME_ENABLED=true`, `REVERB_APP_ID`,
   `REVERB_APP_KEY`, `REVERB_APP_SECRET` (any random values, e.g. `openssl rand -hex 16`) and `REVERB_HOST=<domain>`,
   and `php artisan optimize`. Nginx already passes `/app` to it (`nginx.conf`).
2. **Call relay (TURN).** Without it many calls on mobile data ring and answer but carry no sound. Install coturn
   (`server-setup.sh` does, or `sudo apt install coturn`), fill in `turnserver.conf` and copy it to
   `/etc/turnserver.conf`, then set `WEBRTC_TURN_URLS` and `WEBRTC_TURN_SECRET`. A paid TURN service works too
   (`WEBRTC_TURN_USER` / `WEBRTC_TURN_PASS`).

Who answers which section (Reception, Bar, Laundry …), their hours and whether guests can call them: Guest Chat →
Sections on the website.

Check the server at any time: `php artisan hotel:launch-check` (red = fix before going live).
