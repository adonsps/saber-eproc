-- ====================================================================
-- Vendor Evaluation v2 — PO Item Category (Barang/Jasa) Manual Override (05)
--
-- Additive migration. Independent of files 01-04 (no FK on any of them).
--
-- Purpose:
--   Barang (goods) vs Jasa (services) is NOT stored anywhere for a PO — the
--   Form 2 (Project Retail — New Store) worklist derives it LIVE, at render
--   time, from a keyword match against that PO's prs.item_description lines
--   (see workspace/includes/vendor_eval_v2_item_category.php). This table
--   holds ONLY the manual corrections a filler makes when that live guess is
--   wrong ("move POs between these categories" per the 2026-08-19 request) —
--   it is a pure override layer, not a cache of every PO's classification.
--   A row here always wins over the live keyword guess for that po_number.
--
-- Scope:
--   Used ONLY by public_eval_v2_project_worklist.php (Form 2 / Project-Ops
--   shared worklist) and its ajax_po_item_category_override.php endpoint.
--   Deliberately NOT wired into public_eval_v2_worklist.php (the general
--   worklist) — that page must not be touched for this feature.
--
-- SAFETY:
--   - Additive / re-runnable only: CREATE TABLE IF NOT EXISTS. No DROP,
--     DELETE, TRUNCATE, or ALTER of any existing table. Touches no data.
--   - No FK to prs/vendor_eval_po_taxonomy — po_number is free-text, same
--     loose-coupling convention as vendor_eval_po_question_set_override.
--   - v1 `vendor_evaluations` and all v1 files are NEVER referenced.
--   - Not wired into workspace/config/database.php auto-migration —
--     deliberately manually applied, like files 01-04.
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), VERSION();
-- ====================================================================

CREATE TABLE IF NOT EXISTS `vendor_eval_po_item_category` (
  `id`            INT           NOT NULL AUTO_INCREMENT,
  `po_number`     VARCHAR(255)  NOT NULL,
  `item_category` ENUM('barang','jasa') NOT NULL,
  `updated_by`    VARCHAR(255)  DEFAULT NULL
                     COMMENT 'Free-text name typed by the (no-login) worklist filler, same convention as assigned_by',
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_item_category_po` (`po_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
