-- ====================================================================
-- prs — Cost Avoidance server-side query indexes
--
-- Added alongside the server-side rewrite of the Savings Report table and
-- the financial charts on workspace/pages/cost_avoidance.php.
--
-- The page used to SELECT every PO row once per page load and do the rest
-- in PHP. It now issues COUNT / SUM / LIMIT-paginated queries per request
-- (workspace/pages/ajax_cost_avoidance_table.php and
--  workspace/pages/ajax_cost_avoidance_charts.php), so the filters below
-- are hit far more often and must be index-served.
--
-- Measured on the local Docker copy (30,934 prs rows), report scope with a
-- one-month date filter:
--     before:  type: ALL,   key: NULL,                rows: 30934
--     after:   type: range, key: idx_baseline_price,  rows: 3
-- and the Input Baselines queue with the same date filter:
--     after:   type: range, key: idx_canvassing_date, rows: 2888
--
-- The two NOT EXISTS subqueries against purchases_2025 were already
-- index-served by that table's own idx_sku and idx_item_vendor, so no
-- index is needed there.
--
-- ---------------------------------------------------------------
-- WHICH DATABASE?
--   u894129685_workspace  (where `prs` lives — NOT the vmsportal DB).
--   Local Docker: docker exec -i <db_container> \
--       mysql -u u894129685_workspace007 -p u894129685_workspace < db_backups/cost_avoidance_indexes.sql
-- ---------------------------------------------------------------
--
-- WHEN DO I NEED TO RUN THIS?
--   Normally: NEVER. workspace/config/database.php applies both indexes
--   automatically the first time any page is loaded, on every environment.
--   Pull the code, open a page, done.
--
--   Run it by hand only to put the indexes in place WITHOUT booting the
--   app — a DBA applying changes ahead of a deploy, or reviewing the DDL
--   in one place. This file is documentation + a manual escape hatch; it
--   is NOT the mechanism that keeps teammates in sync — config.php is.
--
-- SAFETY:
--   - Additive and re-runnable only. No DROP, DELETE, or TRUNCATE.
--     Running it twice is a no-op.
--   - Every ADD INDEX is information_schema-guarded, matching the pattern
--     in db_backups/prs_dashboard_indexes.sql: MySQL 8 (local Docker) has
--     no "ADD INDEX IF NOT EXISTS", and MariaDB (prod) and MySQL differ on
--     other shortcuts, so this guard is used because it works on both.
-- ====================================================================

-- canvassing_date — the Global Date Filter applied to the Savings Report,
-- the charts, and the Input Baselines queue.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_canvassing_date');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_canvassing_date` (`canvassing_date`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- baseline_price — splits the two scopes the page renders: lines that already
-- have a quotation baseline (Savings Report) from those that do not (Input
-- Baselines queue). Deliberately a single-column index rather than a composite
-- led by po_number: "po_number IS NOT NULL AND po_number != ''" matches 29.5k
-- of 32k rows, so it is useless as a leading column, while baseline_price
-- selectivity differs per environment — leave the choice to the optimizer.
SET @e := (SELECT COUNT(*) FROM information_schema.STATISTICS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'prs' AND INDEX_NAME = 'idx_baseline_price');
SET @s := IF(@e = 0, 'ALTER TABLE `prs` ADD INDEX `idx_baseline_price` (`baseline_price`)', 'DO 0');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
