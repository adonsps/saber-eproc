-- Migration: Direct Registration feature (Sourcing fills registration + PQ
-- on a vendor's behalf via session impersonation).
--
-- Both changes below are also self-applied automatically by config.php on
-- first request after deploy (see the two migration blocks there) — this
-- file exists purely as a documented reference of what changed, matching
-- the convention of the other db_backups/*.sql files. No manual step is
-- required to apply this in any environment; config.php already handles it
-- idempotently.

-- 1. Audit trail: which Sourcing user created a vendor via Direct
--    Registration. NULL for normal self-registered vendors. Also the flag
--    every "block return-to-vendor" guard checks (VM review, Sourcing
--    review, both UI and server-side).
ALTER TABLE vendors
  ADD COLUMN registered_by_sourcing_id INT NULL AFTER sub_company_type;

-- 2. Widen pq_submissions.risk_category from the old 3-tier enum
--    ('Low Risk','Medium Risk','High Risk') to the 5-tier scale required by
--    finalizeSubmissionScoring() / update_assessment.php / review_pq.php
--    (see includes/pq_risk_helper.php). Existing rows are recomputed from
--    risk_percentage using the same thresholds, then the column is locked
--    down to the final enum.
ALTER TABLE pq_submissions
  MODIFY risk_category VARCHAR(20) NULL DEFAULT NULL;

UPDATE pq_submissions SET risk_category = CASE
  WHEN max_possible_weighted_score <= 0 THEN NULL
  WHEN risk_percentage <= 15 THEN 'Very Low'
  WHEN risk_percentage <= 29 THEN 'Low'
  WHEN risk_percentage <= 50 THEN 'Moderate'
  WHEN risk_percentage <= 61 THEN 'High'
  ELSE 'Critical' END;

ALTER TABLE pq_submissions
  MODIFY risk_category ENUM('Very Low','Low','Moderate','High','Critical') NULL DEFAULT NULL;
