-- Schema for pgroup_business_unit_mappings: maps a PGroup (project_pgroups.p_group)
-- to its Business Unit, sourced from an external BU/PGroup mapping workbook.
--
-- mapping_confidence is a categorical provenance label, not a numeric score
-- (e.g. "family-based", "inferred, not in source file", "user-provided") -
-- values are preserved as-is from the source CSV so reporting can distinguish
-- how each mapping was sourced.
--
-- SAFETY:
--   - Additive / re-runnable only: CREATE TABLE IF NOT EXISTS.
--     No DROP, DELETE, or TRUNCATE anywhere in this file.
--   - No data is loaded here (see tools/import_pgroup_business_unit_mappings.php
--     for the dry-run/apply seeding tool).
--   - Not wired into workspace/config/database.php auto-migration —
--     this remains a deliberate, manually-applied migration, consistent
--     with project_pgroups (see project_pgroup_assignment_phase_a.sql).
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), @@hostname, VERSION();

CREATE TABLE IF NOT EXISTS pgroup_business_unit_mappings (
    p_group             VARCHAR(20) PRIMARY KEY,
    business_unit_code  VARCHAR(50) NULL,
    business_unit_name  VARCHAR(255) NULL,
    mapping_confidence  VARCHAR(50) NULL,
    notes               TEXT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_pgroup_bu_mappings_code (business_unit_code),
    KEY idx_pgroup_bu_mappings_confidence (mapping_confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
