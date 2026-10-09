-- ====================================================================
-- SLA Tracking — schema for the dashboard SLA countdown column
--
-- Ships with the SLA column added to every role dashboard
-- (dashboard_tech / _pic / _admin / _mgr / _project_head) and the
-- rewritten Settings -> SLA page, sharing workspace/includes/sla_rules.php.
--
-- BOTH statements below are also applied automatically, and idempotently,
-- the first time a Manager or Category Manager opens Settings -> SLA:
--     sla_rules_bootstrap($pdo)         -> section 1
--     sla_holidays_ensure_schema($pdo)  -> section 2
-- This file exists so a DBA can apply them ahead of the deploy instead of
-- letting DDL run from a web request. Running it manually is safe and makes
-- the auto-apply a no-op. Verified on MySQL 8 (local Docker); the syntax is
-- identical on MariaDB (production).
-- ====================================================================


-- --------------------------------------------------------------------
-- 1. sla_rules — the editable SLA target per ticket category
--
-- Targets used to be hardcoded in sla_helper.php as `Intercompany ? 3 : 4`.
-- They now live here and are editable on Settings -> SLA. Resolution order
-- for one PR line (see sla_rule_key_for() in includes/sla_rules.php):
--     1. ticket_type Intercompany / Intercompany New Store -> intercompany
--     2. otherwise the P.Group's allowed_pgroups.label:
--          'Team GP Project'     -> gp_project
--          'Team GP Non Project' -> gp_non_project
--     3. P.Group not whitelisted -> default
--
-- Reads fail soft: if this table is missing, the hardcoded catalogue
-- defaults in sla_rule_catalogue() are used, which reproduce the previous
-- behaviour exactly (3 days intercompany, 4 days everything else).
-- --------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS sla_rules (
    rule_key   VARCHAR(50)  NOT NULL,
    rule_label VARCHAR(120) NOT NULL,
    sla_days   INT          NOT NULL DEFAULT 4,
    sort_order INT          NOT NULL DEFAULT 0,
    updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (rule_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT IGNORE so re-running never overwrites a target someone has tuned
-- in the UI.
INSERT IGNORE INTO sla_rules (rule_key, rule_label, sla_days, sort_order) VALUES
    ('intercompany',   'Intercompany',        3, 1),
    ('gp_project',     'Team GP Project',     3, 2),
    ('gp_non_project', 'Team GP Non Project', 4, 3),
    ('default',        'Unmapped P.Group',    4, 4);


-- --------------------------------------------------------------------
-- 2. company_holidays.id — add the missing AUTO_INCREMENT
--
-- PRE-EXISTING BUG, not introduced by this change. `id` is a PRIMARY KEY
-- that was created WITHOUT AUTO_INCREMENT, so every INSERT omitting it
-- lands on id = 0. The first holiday added through Settings -> SLA
-- succeeded; every one after collided on the PK and was silently discarded
-- by the INSERT IGNORE in the add-holiday handler. In other words: on any
-- environment, "Add Holiday" only ever worked once.
--
-- This matters more now because the dashboard SLA countdown skips company
-- holidays (sla_elapsed_days() counts working days and drops any weekday
-- on this calendar), so the ability to add them has to actually work.
--
-- Non-destructive. The seeded rows already carry unique ids 1..12; the
-- UPDATE only re-homes a row left stranded on 0 by a previous failed add,
-- so the ALTER cannot trip over a duplicate.
-- --------------------------------------------------------------------
UPDATE company_holidays
   SET id = (SELECT COALESCE(MAX(t.id), 0) + 1 FROM (SELECT id FROM company_holidays) AS t)
 WHERE id = 0;

ALTER TABLE company_holidays MODIFY id INT NOT NULL AUTO_INCREMENT;


-- --------------------------------------------------------------------
-- NOT DROPPED — still required
--
-- The Settings -> SLA page no longer renders the "Active Mapping Engine"
-- switch or the "PGroup Allocation" editor, but the SLA Report still READS
-- both sources (includes/sla_helper.php: system_settings.sla_mapping_mode
-- and the pgroup_team_mappings join). Their values are simply frozen at
-- whatever they hold today. Do not drop either one.
-- --------------------------------------------------------------------
