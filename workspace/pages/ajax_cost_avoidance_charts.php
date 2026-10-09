<?php
// pages/ajax_cost_avoidance_charts.php
//
// Server-side aggregation for the Financial Impact Summary charts on
// cost_avoidance.php (PO-based and Mixed), plus the team filter button list and
// the Manual tab's KPI figures.
//
// This replaces the page's old per-row PHP loop, which had to hydrate every PO
// line just to produce a handful of per-team totals. The PO side is now a single
// GROUP BY; the Manual side stays in PHP (see ca_manual_aggregate()). Both charts
// are returned from one call — as in ajax_sla_charts.php — so they can never land
// out of sync from two separate async requests.
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/cost_avoidance_helper.php';
// Admission check only; intentionally discards $scope — this endpoint must
// never be team-restricted (PO chart + Mixed chart both must always show
// every team, for every session). Swapped from require_manager() so the
// newly-admitted PIC-tier sessions (Phase 2) can reach this endpoint at all
// — do NOT wire $scope into ca_from_where()/ca_manual_aggregate() below "for
// consistency" with the rest of this file; that would defeat the entire
// point of this being the one exempt call site.
require_cost_avoidance_access($pdo);

header('Content-Type: application/json');

try {
    $filters = ca_normalize_filters($_POST['gf'] ?? '');
    $ctx     = ca_context($pdo);
    $targets = ca_team_targets($pdo);

    list($from_where, $params) = ca_from_where($ctx, $filters, 'all');
    $team_expr = ca_team_expr($ctx);

    // One pass over the PO lines: total managed spend and total cost avoidance
    // per team. Spend counts EVERY active PO line (that is what the avoidance
    // percentage is measured against), while avoidance only accrues on lines
    // that actually carry a quotation baseline.
    $sql = "SELECT {$team_expr} AS team,
                   SUM(" . ca_po_idr_expr($ctx, true) . ") AS spend,
                   SUM(CASE WHEN " . ca_has_baseline_cond() . "
                            THEN " . ca_avoidance_expr($ctx) . " ELSE 0 END) AS avoidance
            {$from_where}
            GROUP BY team
            ORDER BY team";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $po_financials = [];
    foreach ($stmt as $row) {
        $po_financials[$row['team']] = [
            'spend'     => (float)$row['spend'],
            'avoidance' => (float)$row['avoidance'],
        ];
    }
    $unique_teams = array_keys($po_financials);

    $manual = ca_manual_aggregate($pdo, $filters, ca_effective_currencies($ctx));
    $manual_financials = $manual['team_financials'];

    // --- PO-based chart ---
    $po_chart = ['teams' => [], 'spend' => [], 'avoidance' => [], 'pct' => [], 'targets' => []];
    foreach ($unique_teams as $team) {
        $spend = $po_financials[$team]['spend'];
        $avoid = $po_financials[$team]['avoidance'];
        $po_chart['teams'][]     = $team;
        $po_chart['spend'][]     = $spend;
        $po_chart['avoidance'][] = $avoid;
        $po_chart['pct'][]       = ($spend > 0) ? round(($avoid / $spend) * 100, 2) : 0;
        $po_chart['targets'][]   = $targets[$team] ?? 0;
    }

    // --- Mixed chart: PO spend/avoidance combined with the Manual side, so a
    // team's total baseline and total avoidance show regardless of whether the
    // entry came from a PO or was keyed in manually. ---
    $mixed_teams = array_unique(array_merge($unique_teams, array_keys($manual_financials)));
    sort($mixed_teams);

    $mixed_chart = ['teams' => [], 'spend' => [], 'avoidance' => [], 'pct' => [], 'targets' => []];
    foreach ($mixed_teams as $team) {
        $po  = $po_financials[$team]     ?? ['spend' => 0, 'avoidance' => 0];
        $man = $manual_financials[$team] ?? ['before' => 0, 'avoidance' => 0];

        $spend = $po['spend'] + $man['before'];
        $avoid = $po['avoidance'] + $man['avoidance'];

        $mixed_chart['teams'][]     = $team;
        $mixed_chart['spend'][]     = $spend;
        $mixed_chart['avoidance'][] = $avoid;
        $mixed_chart['pct'][]       = ($spend > 0) ? round(($avoid / $spend) * 100, 2) : 0;
        $mixed_chart['targets'][]   = $targets[$team] ?? 0;
    }

    // Category cards on the Manual tab: keyed by level_2 (the broader taxonomy
    // tier) so it stays a handful of buckets instead of one card per level_4.
    $categories = [];
    foreach ($manual['category_breakdown'] as $name => $amount) {
        $categories[] = ['name' => $name, 'amount' => $amount];
    }

    echo json_encode([
        'po'           => $po_chart,
        'mixed'        => $mixed_chart,
        'teams'        => $unique_teams,
        'manualTotal'  => $manual['total_idr'],
        'manualCats'   => $categories,
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — never send raw PDO/SQL details
    // to the client.
    error_log('ajax_cost_avoidance_charts.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'po'          => ['teams' => [], 'spend' => [], 'avoidance' => [], 'pct' => [], 'targets' => []],
        'mixed'       => ['teams' => [], 'spend' => [], 'avoidance' => [], 'pct' => [], 'targets' => []],
        'teams'       => [],
        'manualTotal' => 0,
        'manualCats'  => [],
        'error'       => 'Gagal memuat data chart Cost Avoidance. Coba lagi.',
    ]);
}
