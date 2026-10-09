-- ====================================================================
-- Vendor Multi-Contact-Person (multi-CP) + Vendor Account — Schema
--
-- Every table/column/index added to the VMS portal database by the
-- multi-CP vendor model and the vendor-account enhancements, in one
-- re-runnable file.
--
-- Mirrors, exactly, the DDL applied at runtime by:
--   includes/vendor_contacts_schema.php  -> ensure_vendor_contacts_schema()
--   includes/vendor_account_schema.php   -> ensure_vendor_account_schema()
-- both of which are wired into config.php and run on every request.
--
-- ---------------------------------------------------------------
-- WHEN DO I NEED TO RUN THIS?
--
--   Normally: NEVER. config.php applies all of it automatically the
--   first time any page is loaded, on every environment (local Docker
--   and production alike). Pull the code, open a page, done.
--
--   Run it by hand only when you want the schema in place WITHOUT
--   booting the app — e.g. a DBA applying changes ahead of a deploy,
--   restoring into a fresh database, or inspecting/reviewing the DDL
--   in one place instead of reading it out of PHP.
--
--   This file is therefore documentation + a manual escape hatch. It
--   is NOT the mechanism that keeps teammates in sync; config.php is.
-- ---------------------------------------------------------------
--
-- Sections (in dependency order):
--   1. users   — multi-CP columns (identity, invite, email-change)
--   2. users   — username (alternate login id)
--   3. vendors — CP attestation + profile last-updated tracking
--   4. Indexes — users.vendor_id, unique users.username
--   5. vendor_contact_audit — CP lifecycle audit trail
--   6. Backfills — existing vendors become their company's primary CP
--
-- SAFETY:
--   - Additive and re-runnable only. No DROP, DELETE, or TRUNCATE
--     anywhere in this file. Running it twice is a no-op.
--   - Every DDL statement is information_schema-guarded. MariaDB's
--     `ADD COLUMN IF NOT EXISTS` is deliberately NOT used: it is a
--     syntax error on MySQL 8, and local Docker runs MySQL 8 while
--     production runs MariaDB. The guard pattern below works on both.
--   - Backfills are guarded by WHERE clauses, so they match zero rows
--     after the first run.
--   - No test/sample data is loaded. Contact-person rows are real user
--     accounts and are never seeded from a file.
-- ====================================================================


-- ====================================================================
-- 1. users — multi-CP columns
--
--    vendor_id      -> the vendor company this contact person belongs to
--    contact_role   -> 'primary' (vendor admin/owner) | 'member'
--    contact_status -> 'active' | 'invited' | 'revoked'
--
--    vendors.user_id STILL points at the PRIMARY contact, so all legacy
--    "SELECT ... FROM vendors WHERE user_id = ?" queries keep working.
-- ====================================================================

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'vendor_id');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `vendor_id` INT NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'contact_role');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `contact_role` ENUM(''primary'',''member'') NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'contact_status');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `contact_status` ENUM(''active'',''invited'',''revoked'') NOT NULL DEFAULT ''active''', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'phone');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `phone` VARCHAR(50) NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'invited_by_user_id');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `invited_by_user_id` INT NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'verified_at');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `verified_at` DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'last_login_at');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `last_login_at` DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Self-service email change: held pending until the NEW address is verified
-- (the confirmation link is sent only to the new inbox).
-- See modules/vendor/contacts_action.php (change_email) + verify_email_change.php.

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'pending_email');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `pending_email` VARCHAR(150) NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_change_token');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `email_change_token` VARCHAR(64) NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_change_expires');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `email_change_expires` DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ====================================================================
-- 2. users — username (alternate login id)
--
--    Vendors may log in with EITHER their email or this username.
--    Format is enforced in PHP: 4-30 chars of [A-Za-z0-9._-]. The '@'
--    exclusion guarantees a username can never collide with an email.
-- ====================================================================

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'username');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD COLUMN `username` VARCHAR(50) NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ====================================================================
-- 3. vendors — CP attestation + profile last-updated tracking
-- ====================================================================

-- When the primary last confirmed the contact-person list is still current.
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'cp_last_attested_at');
SET @s := IF(@e = 0, 'ALTER TABLE `vendors` ADD COLUMN `cp_last_attested_at` DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Who last edited the company profile, and when. With multiple CPs per vendor
-- the profile is no longer edited by exactly one person, so this is recorded.
SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'last_updated_by_user_id');
SET @s := IF(@e = 0, 'ALTER TABLE `vendors` ADD COLUMN `last_updated_by_user_id` INT NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'last_updated_by_name');
SET @s := IF(@e = 0, 'ALTER TABLE `vendors` ADD COLUMN `last_updated_by_name` VARCHAR(255) NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @e := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'vendors' AND COLUMN_NAME = 'last_updated_at');
SET @s := IF(@e = 0, 'ALTER TABLE `vendors` ADD COLUMN `last_updated_at` DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ====================================================================
-- 4. Indexes
-- ====================================================================

-- Member lookups resolve by vendor company.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_vendor_id');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD INDEX `idx_users_vendor_id` (`vendor_id`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- Username must be globally unique. MySQL/MariaDB both allow multiple NULLs
-- under a UNIQUE index, so this is safe to add BEFORE usernames are filled in.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uniq_users_username');
SET @s := IF(@e = 0, 'ALTER TABLE `users` ADD UNIQUE INDEX `uniq_users_username` (`username`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ====================================================================
-- 5. vendor_contact_audit — CP lifecycle audit trail
--
--    One row per lifecycle action: cp_invited, cp_revoked, cp_reactivated,
--    cp_invite_resent, primary_transferred, email_change_requested,
--    email_changed, username_changed, cp_list_attested.
--    Contact persons are never hard-deleted, so this is the record of
--    who did what.
-- ====================================================================

CREATE TABLE IF NOT EXISTS `vendor_contact_audit` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `vendor_id`      INT NOT NULL,
    `actor_user_id`  INT DEFAULT NULL,
    `actor_name`     VARCHAR(255) DEFAULT NULL,
    `actor_role`     VARCHAR(50) DEFAULT NULL,
    `target_user_id` INT DEFAULT NULL,
    `target_email`   VARCHAR(255) DEFAULT NULL,
    `action`         VARCHAR(50) NOT NULL,
    `details`        TEXT DEFAULT NULL,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vca_vendor` (`vendor_id`),
    KEY `idx_vca_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ====================================================================
-- 6. Backfills
--
--    Both are guarded by WHERE clauses: after the first run they match
--    zero rows and become no-ops. Multi-table UPDATE syntax below works
--    on both MySQL 8 and MariaDB.
-- ====================================================================

-- 6a. Every pre-existing vendor login becomes its company's PRIMARY contact.
--     Without this, legacy vendors have no contact_role and the Narahubung
--     page cannot tell who is allowed to manage the CP list.
UPDATE `users` u
  JOIN `vendors` v ON v.user_id = u.id
   SET u.vendor_id    = v.id,
       u.contact_role = 'primary'
 WHERE u.role = 'vendor'
   AND (u.vendor_id IS NULL OR u.contact_role IS NULL);

-- 6b. Give primary contacts their personal phone from the company phone.
--     users.phone is added NULL by section 1 and is never populated for
--     pre-existing primaries, so the Narahubung page would show "—" for
--     them. Member CPs set their own phone when invited, so only untouched
--     primaries are filled here.
UPDATE `users` u
  JOIN `vendors` v ON v.id = u.vendor_id
   SET u.phone = v.phone
 WHERE u.contact_role = 'primary'
   AND (u.phone IS NULL OR u.phone = '')
   AND v.phone IS NOT NULL AND v.phone <> '';

-- NOTE: a THIRD backfill exists but is intentionally not reproduced here.
-- ensure_vendor_account_schema() generates a unique default username for
-- every vendor account still missing one (backfill_vendor_usernames() in
-- includes/vendor_account_schema.php). It derives a slug from the email
-- local-part and resolves collisions by appending a counter — a loop that
-- does not express cleanly or safely in plain SQL. Usernames stay NULL if
-- you apply this file standalone; they are filled the first time the app
-- runs. NULL usernames are harmless: login falls back to email.
-- ====================================================================
