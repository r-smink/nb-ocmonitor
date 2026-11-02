#!/usr/bin/env bash
# Database-backup van het panel. Cron (03:30, bewaar 14 dagen):
#   30 3 * * * cd /opt/nb-ocmonitor && ./deploy/backup.sh >> backups/backup.log 2>&1
set -euo pipefail
cd "$(dirname "$0")/.."
mkdir -p backups
chmod 700 backups

STAMP="$(date -u +%Y%m%d-%H%M%S)"
FILE="backups/ocmonitor-${STAMP}.sql.gz"

docker compose exec -T mysql sh -c 'exec mysqldump --single-transaction --quick --no-tablespaces -u root -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip > "$FILE"
chmod 600 "$FILE"
find backups -name 'ocmonitor-*.sql.gz' -mtime +14 -delete

echo "$(date -u +%FT%TZ) backup $FILE ($(du -h "$FILE" | cut -f1))"
