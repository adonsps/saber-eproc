<?php
// pages/ajax_cost_savings_summary.php
//
// Server-side aggregation for the Cost Savings Matrix: the five KPI cards, the
// "Savings Impact by Team" chart, the team filter buttons and the initial tab
// counters — all from ONE grouped query (includes/cost_savings_helper.php).
//
// This replaces the page's per-row PHP loop, which hydrated every eligible PO
// line and every purchases_2025 row just to produce a handful of totals. Cards
// and chart are returned together, as in ajax_cost_avoidance_charts.php, so they
// can never land out of sync from two separate async requests.
require_once '../includes/auth.php';
require_once '../includes/cost_savings_access.php';
$cs_role = cs_require_access();
require_once '../config/database.php';
require_once '../includes/cost_savings_helper.php';

header('Content-Type: application/json');

try {
    // AON mode is a capability of the ROLE, never a request parameter — see
    // cs_is_aon_only(). Passing it in would let an STC session ask for the
    // unrestricted dataset simply by calling this endpoint directly.
    $ctx = cs_context($pdo, cs_is_aon_only($cs_role));
    cs_prepare_benchmark($pdo, $ctx);

    $filters = cs_normalize_filters($_POST['gf'] ?? '');

    // ONE query for everything below (see cs_breakdown_matrix()): the Team
    // breakdown, the tab counters, the chart, AND the Taxonomy Level 2 drill-
    // down options are all sliced from this same matrix in PHP.
    $matrix    = cs_breakdown_matrix($pdo, $ctx, $filters);
    $breakdown = cs_breakdown_from_matrix($matrix, $filters['level2']);

    // Cards and tab counters both fold the selected Team — previously the
    // cards ignored the Team filter entirely (always a company-wide grand
    // total), which was the original, pre-existing behavior and made the KPI
    // cards look frozen when only Team changed.
    $totals = cs_totals($breakdown, $filters['team']);
    $counts = $totals;

    // Team filter list every team, Unassigned included — as the page did.
    $teams = array_keys($breakdown);

    // Taxonomy Level 2 options scoped to the current Team/Year/Quarter/Month
    // filters — the filter bar's team -> taxonomy drill-down. Sliced from the
    // matrix built BEFORE the level2 filter was applied, so it never narrows
    // itself out.
    $level2Options = cs_matrix_level2_options($matrix, $filters['team']);
    $chart = ['teams' => [], 'spend' => [], 'benchmark' => [], 'savings' => [], 'pct' => []];
    foreach ($breakdown as $team => $row) {
        if ($team === 'Unassigned') continue;

        $pct = ($row['benchmark'] > 0) ? round(($row['savings'] / $row['benchmark']) * 100, 2) : 0;

        // A team that spent MORE than its 2025 benchmark (e.g. TIGS) has a
        // genuinely negative savings/pct — floor()ing that to 0 here used to
        // silently hide a real cost increase behind a flat, neutral-looking
        // bar. The chart renderer already colors negative bars red
        // (csRenderChart() in cost_savings.php), so the true value belongs
        // here; only the KPI card intentionally floors at 0 (see csRenderCards()'s
        // "current spend is X% lower..." vs "...in line with..." wording).
        $chart['teams'][]     = $team;
        $chart['spend'][]     = $row['spend'];
        $chart['benchmark'][] = $row['benchmark'];
        $chart['savings'][]   = $row['savings'];
        $chart['pct'][]       = $pct;
    }

    echo json_encode([
        'cards' => [
            'totalCurrentSpend'   => $totals['total_current_spend'],
            'total2025Benchmark'  => $totals['total_2025_benchmark'],
            'totalSavings'        => $totals['total_savings'],
            'matchedCurrentSpend' => $totals['matched_current_spend'],
            'matchedCount'        => $totals['matched_count'],
            'eligibleCount'       => $totals['eligible_count'],
            'reviewCount'         => $totals['review_count'],
            'coveragePct'         => $totals['coverage_pct'],
            'overallSavingsPct'   => $totals['overall_savings_pct'],
            'savingLines'         => $totals['saving_lines'],
            'increaseLines'       => $totals['increase_lines'],
        ],
        'counts' => [
            'matched'   => $counts['matched_count'],
            'review'    => $counts['review_count'],
            'unmatched' => $counts['unmatched_count'],
            'excluded'  => $counts['excluded_count'],
        ],
        'teams' => $teams,
        'level2Options' => $level2Options,
        'chart' => $chart,
        'has2025' => $ctx['has_2025'],
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — never send raw PDO/SQL details
    // to the client.
    error_log('ajax_cost_savings_summary.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'cards'   => null,
        'counts'  => null,
        'teams'   => [],
        'level2Options' => [],
        'chart'   => ['teams' => [], 'spend' => [], 'benchmark' => [], 'savings' => [], 'pct' => []],
        'has2025' => true,
        'error'   => 'Gagal memuat ringkasan Cost Savings. Coba muat ulang halaman.',
    ]);
}
