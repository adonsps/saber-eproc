-- ============================================================================
-- project_analytics_targets.sql
-- ----------------------------------------------------------------------------
-- Seeds the Cost Avoidance KPI targets for the three Project teams so the
-- Cost Avoidance Matrix chart renders an explicit target line for each:
--
--     target_PKR  => 4.5   (Project Konstruksi Retail)
--     target_PKP  => 4.5   (Project Konstruksi Properti)
--     target_MFM  => 4.5   (Maintenance & Facility Management)
--
-- Approved Cost Avoidance target values: PKR / PKP / MFM = 4.5 (percent).
--
-- Properties:
--   * Idempotent  - safe to re-run; ON DUPLICATE KEY UPDATE refreshes the value.
--   * Scoped      - touches ONLY the three target_* keys above.
--   * Non-destructive - no DROP / TRUNCATE / DELETE.
--
-- The application already falls back to 4.5 in code when these rows are absent,
-- so executing this file simply makes the stored targets explicit / editable.
--
-- MANUAL STEP: review, then execute this file by hand after approval, e.g.
--     mysql -u <user> -p <database> < db_backups/project_analytics_targets.sql
-- It is intentionally NOT executed automatically by the application.
-- ============================================================================

INSERT INTO system_settings (setting_key, setting_value) VALUES
    ('target_PKR', '4.5'),
    ('target_PKP', '4.5'),
    ('target_MFM', '4.5')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
