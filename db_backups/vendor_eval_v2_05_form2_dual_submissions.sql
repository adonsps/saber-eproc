-- ====================================================================
-- Vendor Evaluation v2 — Form 2 dual-team (Ops/Project) submissions
--
-- Additive only. Apply AFTER vendor_eval_v2_01_schema.sql /
-- vendor_eval_v2_02_seed_business_data.sql / vendor_eval_v2_03_seed_
-- requestor_contacts.sql / vendor_eval_v2_04_po_question_set_override.sql
-- (see db_backups/vendor_eval_v2_deployment_handover.txt for the
-- authoritative apply order — this file is a new step 5).
--
-- 1. vendor_eval_submissions.team — NULL for every existing/Form-1 row.
--    A new UNIQUE(po_number, team) index constrains Form 2 to one
--    submission per team per PO. MySQL/MariaDB unique indexes treat
--    NULL as distinct from every other NULL, so Form 1's rows (team
--    IS NULL) are completely unconstrained by this index and Form 1's
--    existing UNIQUE(po_number, requestor_name) behaviour is unchanged.
--
-- 2. vendor_eval_v2_form2_worklist_token — the single shared, global,
--    no-login link used by both the Operations (~4 people) and Project
--    (~10 people) teams to reach the live list of currently Form-2-
--    eligible POs (see vendor_eval_v2_form2_eligibility.php). Exactly
--    one row is expected, ever, for the whole program — not scoped to
--    a site or PO. Regenerating replaces that one row's token, which
--    immediately invalidates the old link (see
--    vendor_eval_v2_form2_worklist_token.php). Enforced by application
--    logic (always operate on the single existing row), not a DB
--    constraint.
-- ====================================================================

-- Every ADD COLUMN / ADD INDEX below is information_schema-guarded. MySQL 8
-- (local Docker) has no "ADD COLUMN/INDEX IF NOT EXISTS"; MariaDB (prod) and
-- MySQL differ on other shortcuts, so this guard pattern is used because it
-- works identically on both (same convention as db_backups/prs_dashboard_indexes.sql).

SET @col_exists := (SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_eval_submissions' AND COLUMN_NAME = 'team');
SET @ddl := IF(@col_exists = 0,
    'ALTER TABLE `vendor_eval_submissions` ADD COLUMN `team` ENUM(''ops'',''project'') NULL DEFAULT NULL AFTER `requestor_name`',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM information_schema.STATISTICS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendor_eval_submissions' AND INDEX_NAME = 'uq_po_team');
SET @ddl := IF(@idx_exists = 0,
    'CREATE UNIQUE INDEX `uq_po_team` ON `vendor_eval_submissions` (`po_number`, `team`)',
    'DO 0');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `vendor_eval_v2_form2_worklist_token` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `token`      VARCHAR(64)  NOT NULL,
  `created_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
