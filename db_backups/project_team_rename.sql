-- Phase B-8: GP Project team naming source-of-truth rename.
-- Canonical team names (used by dashboard, and later Cost Savings / Cost Avoidance / SLA):
--   Group 1 -> Project Konstruksi Retail            (short code PKR)
--   Group 2 -> Project Konstruksi Properti          (short code PKP)
--   Group 3 -> Maintenance & Facility Management    (short code MFM, unchanged)
-- Scoped by project_group_number only. Does NOT touch p_group mappings or officer mappings.
-- Idempotent: safe to re-run.

UPDATE project_head_groups SET project_group_name = 'Project Konstruksi Retail'         WHERE project_group_number = 1;
UPDATE project_head_groups SET project_group_name = 'Project Konstruksi Properti'        WHERE project_group_number = 2;
UPDATE project_head_groups SET project_group_name = 'Maintenance & Facility Management'  WHERE project_group_number = 3;

UPDATE project_pgroups     SET project_group_name = 'Project Konstruksi Retail'         WHERE project_group_number = 1;
UPDATE project_pgroups     SET project_group_name = 'Project Konstruksi Properti'        WHERE project_group_number = 2;
UPDATE project_pgroups     SET project_group_name = 'Maintenance & Facility Management'  WHERE project_group_number = 3;
