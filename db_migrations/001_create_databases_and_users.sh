#!/bin/bash
# db_migrations/001_create_databases_and_users.sh
#
# Bootstraps the app databases/users expected by workspace/config/database.php
# and config.php for the Docker local-dev environment (DB_HOST=db).
#
# Runs automatically only on first container creation against an empty volume
# (MySQL's docker-entrypoint-initdb.d mechanism). Real credentials are supplied
# via environment variables (see .env.example) - nothing sensitive is hardcoded
# in this file.
#
# For an existing volume/container, apply manually instead, e.g.:
#   docker compose exec -T -e WORKSPACE_DB_PASSWORD=... -e VMSPORTAL_DB_PASSWORD=... \
#     db bash /docker-entrypoint-initdb.d/001_create_databases_and_users.sh

set -euo pipefail

: "${MYSQL_ROOT_PASSWORD:?MYSQL_ROOT_PASSWORD must be set (see .env.example)}"
: "${WORKSPACE_DB_PASSWORD:?WORKSPACE_DB_PASSWORD must be set (see .env.example)}"
: "${VMSPORTAL_DB_PASSWORD:?VMSPORTAL_DB_PASSWORD must be set (see .env.example)}"

echo "[001_create_databases_and_users] Creating databases and app users..."

MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql -u root <<-EOSQL
    CREATE DATABASE IF NOT EXISTS u894129685_workspace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
    CREATE DATABASE IF NOT EXISTS u894129685_vmsportal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

    CREATE USER IF NOT EXISTS 'u894129685_workspace007'@'%' IDENTIFIED BY '${WORKSPACE_DB_PASSWORD}';
    CREATE USER IF NOT EXISTS 'u894129685_adonisvm'@'%' IDENTIFIED BY '${VMSPORTAL_DB_PASSWORD}';

    GRANT ALL PRIVILEGES ON u894129685_workspace.* TO 'u894129685_workspace007'@'%';
    GRANT ALL PRIVILEGES ON u894129685_vmsportal.* TO 'u894129685_adonisvm'@'%';

    FLUSH PRIVILEGES;
EOSQL

echo "[001_create_databases_and_users] Done."
