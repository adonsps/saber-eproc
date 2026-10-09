-- ====================================================================
-- prs — Manager Dashboard performance indexes
--
-- The Manager Workload Dashboard (workspace/pages/ajax_dashboard_data.php)
-- filters and, above all, sorts every PR query by `prs.created_at DESC`.
-- On the workspace database's 20k+ prs rows, missing these indexes turns
-- each request into a full-table filesort and the dashboard appears to
-- "hang" with no error anywhere.
--
-- Mirrors, exactly, the indexes applied at runtime by the auto-migrate
-- block in:
--   workspace/config/database.php   (the u894129685_workspace connection)
-- which runs on every request. idx_created_at is the one the dashboard
-- rewrite depends on; the other four support its WHERE/JOIN filters.
--
-- ---------------------------------------------------------------
-- WHICH DATABASE?
--   u894129685_workspace  (where `prs` lives — NOT the vmsportal DB).
--   Local Docker: docker exec -i <db_container> \
--       mysql -u u894129685_workspace007 -p u894129685_workspace < db_backups/prs_dashboard_indexes.sql
-- ---------------------------------------------------------------
--
-- WHEN DO I NEED TO RUN THIS?
--   Normally: NEVER. config/database.php applies all of it automatically
--   the first time any page is loaded, on every environment. Pull the
--   code, open a page, done.
--
--   Run it by hand only to put the indexes in place WITHOUT booting the
--   app — a DBA applying changes ahead of a deploy, or reviewing the DDL
--   in one place. This file is documentation + a manual escape hatch; it
--   is NOT the mechanism that keeps teammates in sync — config.php is.
--
-- SAFETY:
--   - Additive and re-runnable only. No DROP, DELETE, or TRUNCATE.
--     Running it twice is a no-op.
--   - Every ADD INDEX is information_schema-guarded. MySQL 8 (local
--     Docker) has no "ADD INDEX IF NOT EXISTS", and MariaDB (prod) and
--     MySQL differ on other shortcuts, so the guard pattern below is used
--     because it works identically on both.
-- ====================================================================

-- assigned_pic — workload counts + Non-Project PIC filter.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_assigned_pic');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_assigned_pic` (`assigned_pic`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- status_ticket — the "NOT IN ('Cancel', ...)" filter on every query.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_status_ticket');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_status_ticket` (`status_ticket`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- pr_date — the "pr_date >= '2026-05-01'" recency filter.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_pr_date');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_pr_date` (`pr_date`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- p_group — Project PGroup exclusion + pgroup_team_mappings JOIN.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_p_group');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_p_group` (`p_group`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- created_at — THE dashboard sort key: every query ends "ORDER BY
-- prs.created_at DESC". An ascending index is scanned backwards for DESC,
-- so a plain (created_at) index is sufficient — no descending index needed
-- (which also keeps it valid on older MariaDB that ignores index DESC).
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_created_at');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_created_at` (`created_at`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ====================================================================
-- SLA Report (workspace/pages/sla_report.php) server-side query keys.
-- Added alongside the server-side rewrite of the SLA Report page.
-- ====================================================================

-- tr_date_gp — the SLA report's main sort/date-range-filter column.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_tr_date_gp');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_tr_date_gp` (`tr_date_gp`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- canvassing_date_approver_1 — the working-days SLA duration calculation.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_canvassing_date_approver_1');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_canvassing_date_approver_1` (`canvassing_date_approver_1`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- po_number — AON status detection (no canvassing date + a PO number present).
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_po_number');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_po_number` (`po_number`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- master_data_id — the taxonomy (Level 0..4) JOIN key to master_data.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_master_data_id');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_master_data_id` (`master_data_id`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- ====================================================================
-- Vendor Evaluation v2 PO Worklist (pages/ajax_vendor_eval_v1_table.php)
-- server-side query key.
-- ====================================================================

-- po_number + vendor_name — the worklist's base query GROUPs BY both columns
-- over the whole table. idx_po_number above only orders by po_number, so the
-- GROUP BY still filesorts/temp-tables every 20k+ rows; this composite index
-- lets MySQL read the group order straight off the index instead.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_po_vendor_group');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_po_vendor_group` (`po_number`, `vendor_name`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
