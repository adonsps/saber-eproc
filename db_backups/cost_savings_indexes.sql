-- ====================================================================
-- purchases_2025 — Cost Savings server-side query index
--
-- Added alongside the server-side rewrite of the Cost Savings Matrix
-- (workspace/pages/cost_savings.php, ajax_cost_savings_table.php and
--  ajax_cost_savings_summary.php, sharing
--  workspace/includes/cost_savings_helper.php).
--
-- The page used to SELECT every purchases_2025 row and every eligible PO
-- line once per page load and derive the whole Savings Matrix in PHP,
-- rendering all ~15.8k result rows into a 42.89 MB HTML response. Each
-- request now builds the 2025 benchmark — a weighted average unit price
-- per SKU + UOM — as a single GROUP BY (cs_benchmark_aggregate_sql()),
-- so that aggregate runs far more often and must not read whole rows.
--
-- Measured on the local Docker copy (37,527 purchases_2025 rows):
--     before:  type: ALL, key: NULL, rows: 36951, Using temporary
-- The index covers every column the aggregate touches — sku and uom are
-- the grouping key, p_org decides PPN treatment, qty and po_amount are
-- what it sums — so it can be answered from the index alone.
--
-- ---------------------------------------------------------------
-- WHICH DATABASE?
--   u894129685_workspace  (where `purchases_2025` lives — NOT the
--   vmsportal DB).
--   Local Docker: docker exec -i <db_container> \
--       mysql -u u894129685_workspace007 -p u894129685_workspace < db_backups/cost_savings_indexes.sql
-- ---------------------------------------------------------------
--
-- WHEN DO I NEED TO RUN THIS?
--   Normally: NEVER. workspace/config/database.php applies the index
--   automatically the first time any page is loaded, on every
--   environment. Pull the code, open a page, done.
--
--   Run it by hand only to put the index in place WITHOUT booting the
--   app — a DBA applying changes ahead of a deploy, or reviewing the DDL
--   in one place. This file is documentation + a manual escape hatch; it
--   is NOT the mechanism that keeps teammates in sync — config.php is.
--
-- SAFETY:
--   - Additive and re-runnable only. No DROP, DELETE, or TRUNCATE.
--     Running it twice is a no-op.
--   - The ADD INDEX is information_schema-guarded, matching the pattern
--     in db_backups/cost_avoidance_indexes.sql: MySQL 8 (local Docker)
--     has no "ADD INDEX IF NOT EXISTS", and MariaDB (prod) and MySQL
--     differ on other shortcuts, so this guard is used because it works
--     on both.
--   - purchases_2025 may not exist in every environment. The guard below
--     also checks the table, so this file is safe to run there too.
-- ====================================================================

SET @tbl := (SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases_2025');
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases_2025'
               AND INDEX_NAME = 'idx_sku_uom_benchmark');
SET @s := IF(@tbl = 1 AND @idx = 0,
             'ALTER TABLE `purchases_2025` ADD INDEX `idx_sku_uom_benchmark` (`sku`, `uom`, `p_org`, `qty`, `po_amount`)',
             'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
