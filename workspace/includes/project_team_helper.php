<?php
// includes/project_team_helper.php
// Shared Project-team derivation for the analytics pages (Cost Savings, Cost
// Avoidance, SLA Tracking).
//
// Active rows in project_pgroups are the source of truth for Project detection.
// When a PR's p_group matches an ACTIVE project_pgroups row, the row belongs to
// a Project team (PKR / PKP / MFM by project_group_number) and that overrides
// any Non-Project team / pillar / PIC mapping. Otherwise the page keeps its
// existing Non-Project mapping logic.
//
// Short codes are a UI-only abbreviation derived from the group number (the
// canonical full names live in the DB); this mirrors dashboard_mgr.php /
// dashboard_project_head.php so the analytics pages stay consistent.
//
//   project_group_number 1 => PKR
//   project_group_number 2 => PKP
//   project_group_number 3 => MFM
//
// Fail-open compatibility: if the project_pgroups table is not deployed yet the
// map is empty and every consumer silently keeps legacy behaviour (never fatal),
// matching the guard style used in dashboard_mgr.php.

/**
 * Build a [ p_group => team_code ] lookup for every ACTIVE project_pgroups row.
 * Returns an empty array (legacy behaviour) when the table is absent.
 */
function load_project_team_map(PDO $pdo) {
    $map = [];
    try {
        $rows = $pdo->query("SELECT p_group, project_group_number FROM project_pgroups WHERE status = 'ACTIVE'")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $map; // table not deployed -> fail open, no Project buckets
    }

    $short_codes = [1 => 'PKR', 2 => 'PKP', 3 => 'MFM'];
    foreach ($rows as $r) {
        $gnum = (int)$r['project_group_number'];
        $map[(string)$r['p_group']] = $short_codes[$gnum] ?? ('G' . $gnum);
    }
    return $map;
}

/**
 * Resolve the Project team code for a p_group, or null if it is not an active
 * Project PGroup (caller then falls back to Non-Project mapping).
 */
function project_team_for($p_group, array $project_team_map) {
    if ($p_group === null) {
        return null;
    }
    return $project_team_map[(string)$p_group] ?? null;
}
