-- Schema for the Form 2 cron live-send kill switch.
--
-- Singleton settings row (id=1) + audit log, mirroring the existing
-- maintenance_settings / maintenance_log pattern (see maintenance_tables.sql).
--
-- Purpose: workspace/cron/vendor_eval_v2_form2_reminder_cron.php must never
-- send real WA/email just because a future edit flips its own hard-coded
-- preview=true to preview=false. This is a SEPARATE gate the cron checks in
-- addition to that flag — both must independently agree before anything is
-- sent. Defaults to OFF (0) so a fresh apply of this schema never opens the
-- gate by itself. Does not affect the manual "Shoot Reminder" button
-- (workspace/pages/ajax_send_form2_reminder.php) or its confirm-token gate;
-- this switch is cron-specific by design (see task discussion 2026-07-30).
--
-- SAFETY:
--   - Additive / re-runnable only: CREATE TABLE IF NOT EXISTS, INSERT IGNORE.
--     No DROP, DELETE, or TRUNCATE anywhere in this file.
--   - Not wired into workspace/config/database.php auto-migration — deliberate,
--     manually-applied migration, consistent with vendor_eval_store_opening_dates
--     and pgroup_business_unit_mappings.
--
-- Before applying: confirm DB identity and take a backup.
--   SELECT DATABASE(), USER(), @@hostname, VERSION();

CREATE TABLE IF NOT EXISTS vendor_eval_v2_form2_send_settings (
    id                 TINYINT UNSIGNED NOT NULL DEFAULT 1,
    live_send_enabled  TINYINT(1) NOT NULL DEFAULT 0,
    updated_by         VARCHAR(255) DEFAULT NULL,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vendor_eval_v2_form2_send_settings_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    action      ENUM('ON','OFF') NOT NULL,
    note        TEXT DEFAULT NULL,
    changed_by  VARCHAR(255) NOT NULL,
    changed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO vendor_eval_v2_form2_send_settings (id, live_send_enabled) VALUES (1, 0);
