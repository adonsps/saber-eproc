-- ============================================================================
-- maintenance_tables.sql
-- ----------------------------------------------------------------------------
-- Creates the maintenance mode tables used by the application:
--
--   maintenance_settings  Stores the current maintenance state.
--   maintenance_log       Stores ON/OFF change history.
--
-- Properties:
--   * Idempotent       - safe to re-run; uses CREATE TABLE IF NOT EXISTS.
--   * Non-destructive  - does not DROP / TRUNCATE / DELETE existing data.
--   * Seeded          - ensures the singleton settings row exists with id = 1.
--
-- MANUAL STEP: review, then execute this file by hand after approval, e.g.
--     mysql -u <user> -p <database> < db_backups/maintenance_tables.sql
-- ============================================================================

CREATE TABLE IF NOT EXISTS maintenance_settings (
    id TINYINT(1) NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT DEFAULT NULL,
    updated_by VARCHAR(255) DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maintenance_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action ENUM('ON','OFF') NOT NULL,
    note TEXT DEFAULT NULL,
    changed_by VARCHAR(255) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO maintenance_settings (id, is_active) VALUES (1, 0);
