#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-./storage/backups/storage}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"
SOURCE_DIR="${SOURCE_DIR:-./storage/app}"

mkdir -p "$BACKUP_DIR"
STAMP="$(date +%Y%m%d_%H%M%S)"
OUT_FILE="$BACKUP_DIR/storage_${STAMP}.tar.gz"

echo "Creating storage backup: $OUT_FILE"
tar -czf "$OUT_FILE" -C "$SOURCE_DIR" .

echo "Applying retention: $RETENTION_DAYS days"
find "$BACKUP_DIR" -type f -name "*.tar.gz" -mtime +"$RETENTION_DAYS" -delete

echo "Backup completed."

