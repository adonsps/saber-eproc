-- ============================================================================
-- manual_cost_avoidance.sql
-- ----------------------------------------------------------------------------
-- Schema dump for the `manual_cost_avoidance` table (Manual Input Cost
-- Avoidance — records of avoided cost NOT tied to any PR/PO; fully separate
-- from `prs`).
--
-- This is the FINAL/consolidated schema, identical to the single auto-migrate
-- in workspace/config/database.php:
--   * `category` is VARCHAR(255) to match master_data.level_4.
--   * `team` and `quotation_link` are already present (no post-hoc ALTERs).
--   * Foreign key fk_manual_ca_category locks `category` to a real
--     master_data.level_4 value (ON UPDATE CASCADE, ON DELETE SET NULL).
--
-- PREREQUISITE: `master_data` must already exist (with its UNIQUE KEY on
-- level_4) BEFORE running this file, otherwise the foreign key creation fails
-- with errno 150. Import workspace_dump.sql (or at least master_data) first.
--
-- MANUAL STEP: review, then execute this file by hand, e.g.
--     mysql -u <user> -p <database> < db_backups/manual_cost_avoidance.sql
-- Non-destructive: uses CREATE TABLE IF NOT EXISTS, no DROP / TRUNCATE / DELETE.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `manual_cost_avoidance` (
  `id`                   int NOT NULL AUTO_INCREMENT,
  `entry_date`           date NOT NULL,
  `vendor_name`          varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_description`     varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity`             decimal(15,4) NOT NULL DEFAULT '1.0000',
  `uom`                  varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_price_before`    decimal(15,2) DEFAULT NULL,
  `avoided_price`        decimal(15,2) DEFAULT NULL,
  `total_cost_avoidance` decimal(15,2) NOT NULL,
  `currency`             varchar(5) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IDR',
  `category`             varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `team`                 varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remarks`              text COLLATE utf8mb4_unicode_ci,
  `quotation_link`       varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type`          varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'MANUAL',
  `is_active`            tinyint(1) NOT NULL DEFAULT '1',
  `created_by`           varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by`           varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at`           timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_entry_date` (`entry_date`),
  KEY `idx_active` (`is_active`),
  KEY `idx_category` (`category`),
  KEY `idx_team` (`team`),
  CONSTRAINT `fk_manual_ca_category`
    FOREIGN KEY (`category`) REFERENCES `master_data` (`level_4`)
    ON DELETE SET NULL
    ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
