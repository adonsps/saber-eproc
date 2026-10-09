#!/bin/bash
# dump_db.sh - Backup local Docker databases

# Create db_backups directory if not exists
mkdir -p db_backups

echo "Dumping local database u894129685_workspace..."
docker exec -i vms-db-1 mysqldump -u root -psecret --no-create-db --skip-comments u894129685_workspace > db_backups/workspace_dump.sql

echo "Dumping local database u894129685_vmsportal..."
docker exec -i vms-db-1 mysqldump -u root -psecret --no-create-db --skip-comments u894129685_vmsportal > db_backups/vmsportal_dump.sql

echo "Done! Backup files generated in db_backups/ folder."
chmod 644 db_backups/workspace_dump.sql
chmod 644 db_backups/vmsportal_dump.sql
