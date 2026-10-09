-- ====================================================================
-- Vendor Evaluation v2 — PO → Question-Set Manual Override (04 of 4)
--
-- Additive migration for the Manual Question Set Assignment Queue.
-- Apply AFTER db_backups/vendor_eval_v2_01_schema.sql (needs
-- vendor_eval_question_sets for the FK). Independent of the 02/03 seed
-- files.
--
-- Purpose:
--   Lets a Category Manager assign a specific question set to a PO that
--   otherwise only matched the catch-all Default routing rule. This is a
--   VISIBILITY / quality tool — it does NOT replace or disable taxonomy
--   routing. At runtime the override is consulted FIRST; the existing
--   L3 -> L2 -> Default chain is unchanged and still applies to every PO
--   without an override.
--
-- SAFETY:
--   - Additive / re-runnable only: CREATE TABLE IF NOT EXISTS. No DROP,
--     DELETE, TRUNCATE, or ALTER of any existing table. Touches no data.
--   - Does NOT modify vendor_eval_question_set_taxonomy_map,
--     vendor_eval_question_sets, or any taxonomy table.
--   - v1 `vendor_evaluations` and all v1 files are NEVER referenced.
--   - Not wired into workspace/config/database.php auto-migration —
--     deliberately manually applied, like files 01-03.
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), VERSION();
-- ====================================================================

CREATE TABLE IF NOT EXISTS `vendor_eval_po_question_set_override` (
  `id`              INT          NOT NULL AUTO_INCREMENT,
  `po_number`       VARCHAR(255) NOT NULL,
  `question_set_id` INT          NOT NULL,
  `assigned_by`     VARCHAR(255) DEFAULT NULL
                       COMMENT 'Category Manager display name ($_SESSION[name]), same convention as reviewed_by',
  `assigned_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_override_po`   (`po_number`),
  KEY        `idx_override_set` (`question_set_id`),
  CONSTRAINT `fk_override_question_set`
    FOREIGN KEY (`question_set_id`) REFERENCES `vendor_eval_question_sets` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
