#!/usr/bin/env bash
# Nightly backup: the database and uploaded files (logos, photos) into /var/backups/hotel, kept BACKUP_KEEP_DAYS days,
# and copied off the server to BACKUP_REMOTE (an rclone remote:path) when set. Run by cron as www-data.
set -euo pipefail
APP=/var/www/hotel
OUT=/var/backups/hotel
cd "$APP"

env_get() { grep -E "^$1=" .env | tail -1 | cut -d= -f2- | sed 's/^"//; s/"$//'; }
DB=$(env_get DB_DATABASE); USER=$(env_get DB_USERNAME); PASS=$(env_get DB_PASSWORD); HOST=$(env_get DB_HOST)
KEEP=$(env_get BACKUP_KEEP_DAYS); KEEP=${KEEP:-30}
REMOTE=$(env_get BACKUP_REMOTE)
STAMP=$(date +%Y-%m-%d_%H%M)
mkdir -p "$OUT"

# Database (consistent snapshot without locking the hotel out)
MYSQL_PWD="$PASS" mysqldump --single-transaction --quick --routines -h "${HOST:-127.0.0.1}" -u "$USER" "$DB" | gzip > "$OUT/db_$STAMP.sql.gz"
# Uploaded files
tar -czf "$OUT/files_$STAMP.tar.gz" -C "$APP" storage/app/public public/uploads 2>/dev/null || true

# A dump under 1 KB means it failed
[ "$(stat -c%s "$OUT/db_$STAMP.sql.gz")" -gt 1024 ] || { echo "$(date) backup FAILED: database dump is empty"; exit 1; }

find "$OUT" -name '*.gz' -mtime +"$KEEP" -delete

if [ -n "$REMOTE" ]; then
  rclone copy "$OUT/db_$STAMP.sql.gz" "$REMOTE/" && rclone copy "$OUT/files_$STAMP.tar.gz" "$REMOTE/"
fi
echo "$(date) backup ok: db_$STAMP.sql.gz${REMOTE:+ (copied to $REMOTE)}"
# Restore:  gunzip -c db_<stamp>.sql.gz | mysql -u hotel -p hotel
