# Backup and Restore

## Backup scripts
- MySQL: `scripts/backup_mysql.sh`
- Storage (XML/PDF/files): `scripts/backup_storage.sh`

Both scripts:
- keep backups in `storage/backups/*`
- apply retention using `RETENTION_DAYS` (default `7`)

## Manual execution
```bash
BACKUP_DIR=./storage/backups/mysql \
DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=sicofac DB_USER=root DB_PASSWORD=secret \
./scripts/backup_mysql.sh

BACKUP_DIR=./storage/backups/storage \
SOURCE_DIR=./storage/app \
./scripts/backup_storage.sh
```

## Restore MySQL
```bash
gunzip -c ./storage/backups/mysql/sicofac_YYYYMMDD_HHMMSS.sql.gz | \
mysql -h 127.0.0.1 -P 3306 -u root -p sicofac
```

## Restore storage files
```bash
tar -xzf ./storage/backups/storage/storage_YYYYMMDD_HHMMSS.tar.gz -C ./storage/app
```

## Recommended schedule
- Daily DB backup.
- Daily storage backup.
- Keep minimum 7 days.
- Periodically test restore in a staging environment.

