-- Schema for vendor_eval_store_opening_dates: tracks the Store Opening Date
-- per SITE (prs.site_allocation), not per PO — one site can have many POs.
--
-- Scope: sites belonging to a PGroup matching [A-Z]97 or A77 (project_pgroups),
-- filtered to prs.is_new_store = 1. Confirmed via direct query: no site is ever
-- shared across two different project groups, so each site has exactly one
-- owning group (PKR = project_group_number 1, PKP = project_group_number 2).
--
-- owning_project_group_number is derived at write time from the PO's p_group
-- via project_pgroups.project_group_number and stored (not recomputed on read)
-- so access control can scope PKR/PKP officers to their own group's rows with
-- a plain equality check.
--
-- SAFETY:
--   - Additive / re-runnable only: CREATE TABLE IF NOT EXISTS.
--     No DROP, DELETE, or TRUNCATE anywhere in this file.
--   - Not wired into workspace/config/database.php auto-migration —
--     this remains a deliberate, manually-applied migration, consistent
--     with pgroup_business_unit_mappings (see pgroup_business_unit_mappings_01_schema.sql).
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), @@hostname, VERSION();

CREATE TABLE IF NOT EXISTS vendor_eval_store_opening_dates (
    site_allocation              VARCHAR(150) NOT NULL PRIMARY KEY,
    store_opening_date           DATE NOT NULL,
    owning_project_group_number  TINYINT UNSIGNED NOT NULL,
    assigned_by                  VARCHAR(100) NOT NULL,
    assigned_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_veso_group (owning_project_group_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
