<?php
// pages/ajax_sla_charts.php
// Server-side aggregation for the bar chart + trend line chart on sla_report.php. Both
// are returned from a single call (mirrors the old client updateCharts(), which always
// rebuilt both together) so the two never land out of sync from two separate async calls.
require_once '../includes/auth.php';
require_sla_access();
require_once '../config/database.php';
require_once '../includes/sla_helper.php';
require_once '../includes/project_team_helper.php';

date_default_timezone_set('Asia/Jakarta');

header('Content-Type: application/json');

// Ports buildBarPayload() from sla_report.php's inline <script> 1:1. Percentages are
// always each group's own share of its own total (never recomputed against a grand
// total), so filtering to a single status just isolates that segment — its value stays
// the same number the group showed in the full stacked bar before the filter.
function sla_build_bar_payload($rows, $dim, $sort, $dir = 'desc', $pctIdx = 2) {
    $statuses = ['In Progress', 'Within SLA', 'SLA Breached'];
    $colorByStatus = ['In Progress' => '#facc15', 'Within SLA' => '#22c55e', 'SLA Breached' => '#ef4444'];

    $stats = []; // group => status => ['tickets' => set, 'pos' => set]
    foreach ($rows as $r) {
        if (!in_array($r['statusName'], $statuses, true)) continue; // AON / Out of Scope not charted
        if ($dim === 'team') {
            $g = $r['team_name'];
        } elseif ($dim === 'pic') {
            $g = trim((string)($r['assigned_pic'] ?? ''));
            if ($g === '') $g = 'Unassigned';
        } else {
            $lvl = (int)substr($dim, 1);
            $g = trim((string)($r['level_' . $lvl] ?? ''));
        }
        if ($g === '' || $g === null) $g = 'Unmapped';
        if (!isset($stats[$g])) {
            $stats[$g] = [];
            foreach ($statuses as $s) $stats[$g][$s] = ['tickets' => [], 'pos' => []];
        }
        $stats[$g][$r['statusName']]['tickets'][$r['ticket_no']] = true;
        if (!empty($r['po_number'])) $stats[$g][$r['statusName']]['pos'][$r['po_number']] = true;
    }

    $groupData = [];
    foreach ($stats as $g => $byStatus) {
        $counts = [];
        foreach ($statuses as $s) $counts[] = count($byStatus[$s]['tickets']);
        $groupData[$g] = ['counts' => $counts, 'total' => array_sum($counts)];
    }

    $pctOf = function($g, $i) use ($groupData) {
        return $groupData[$g]['total'] > 0 ? $groupData[$g]['counts'][$i] / $groupData[$g]['total'] : 0;
    };

    // Order is now two independent choices: WHAT to rank by ($sort: 'n' | 'pct') and
    // WHICH WAY ($dir). '%' needs a reference status to mean anything — a group's own
    // total is always 100% of itself — so $pctIdx names which of $statuses it measures
    // (see the caller: the status currently filtered, else SLA Breached).
    $metricOf = function($g) use ($sort, $groupData, $pctOf, $pctIdx) {
        return $sort === 'pct' ? $pctOf($g, $pctIdx) : $groupData[$g]['total'];
    };
    // Ties always break A–Z regardless of $dir: with only a handful of distinct values
    // (especially at 0%), a stable alphabetical fallback keeps the bars from reshuffling
    // between otherwise identical requests.
    $mult = ($dir === 'asc') ? 1 : -1;
    $labels = array_keys($stats);
    usort($labels, function($a, $b) use ($metricOf, $mult) {
        return ($mult * ($metricOf($a) <=> $metricOf($b))) ?: strcmp($a, $b);
    });

    $datasets = [];
    foreach ($statuses as $i => $s) {
        $data = [];
        $rawCounts = [];
        foreach ($labels as $g) {
            $data[] = round($pctOf($g, $i) * 1000) / 10;
            $rawCounts[] = ['tickets' => $groupData[$g]['counts'][$i], 'pos' => count($stats[$g][$s]['pos'])];
        }
        $datasets[] = ['label' => $s, 'backgroundColor' => $colorByStatus[$s], 'data' => $data, 'rawCounts' => $rawCounts];
    }

    return ['labels' => $labels, 'datasets' => $datasets];
}

// Ports buildTrendDatasets() 1:1: achievement % (within / evaluated) per fixed period
// bucket per team, using the same 'wk'/'mo'/'yr' period-key definitions as before.
function sla_build_trend_datasets($rows, $gran, $trendBuckets, $uniqueTeams, $teamColors) {
    $keyField = ['week' => 'wk', 'month' => 'mo', 'year' => 'yr'][$gran];
    $keys = $trendBuckets[$gran]['keys'];

    $stats = []; // periodKey => team => ['total'=>.., 'within'=>..]
    foreach ($rows as $r) {
        if ($r['statusName'] !== 'Within SLA' && $r['statusName'] !== 'SLA Breached') continue;
        $ts = strtotime($r['pr_date']); // TR Date Technical — the SLA start date
        $k = ($keyField === 'wk') ? date('o-\WW', $ts) : (($keyField === 'mo') ? date('Y-m', $ts) : date('Y', $ts));
        $team = $r['team_name'];
        if (!isset($stats[$k])) $stats[$k] = [];
        if (!isset($stats[$k][$team])) $stats[$k][$team] = ['total' => 0, 'within' => 0];
        $stats[$k][$team]['total']++;
        if ($r['statusName'] === 'Within SLA') $stats[$k][$team]['within']++;
    }

    $datasets = [];
    foreach ($uniqueTeams as $idx => $team) {
        $pts = [];
        foreach ($keys as $k) {
            $s = $stats[$k][$team] ?? null;
            $pts[] = ($s && $s['total'] > 0) ? round(($s['within'] / $s['total']) * 10000) / 100 : null;
        }
        $datasets[] = [
            'label' => $team,
            'data' => $pts,
            'borderColor' => $teamColors[$idx % count($teamColors)],
            'fill' => false,
            'tension' => 0.2,
            'spanGaps' => true,
        ];
    }
    return $datasets;
}

try {
    $gf = sla_normalize_gf($_POST['gf'] ?? '');
    $dim = isset($_POST['dim']) ? (string)$_POST['dim'] : 'team';
    $gran = isset($_POST['gran']) ? (string)$_POST['gran'] : 'week';
    $sort = isset($_POST['sort']) ? (string)$_POST['sort'] : 'n';
    $barDir = isset($_POST['barDir']) ? (string)$_POST['barDir'] : 'desc';
    if (!in_array($sort, ['n', 'pct'], true)) $sort = 'n';
    if (!in_array($barDir, ['asc', 'desc'], true)) $barDir = 'desc';
    if (!in_array($gran, ['week', 'month', 'year'], true)) $gran = 'week';

    $itemSearch  = isset($_POST['search']) ? (string)$_POST['search'] : '';
    $itemSortCol = isset($_POST['sortCol']) ? (string)$_POST['sortCol'] : 'count';
    $itemSortDir = isset($_POST['sortDir']) ? (string)$_POST['sortDir'] : 'desc';

    $ctx = resolve_sla_persona($pdo, $_SESSION);
    $computed = get_sla_computed_rows($pdo, $ctx);
    $filteredRows = sla_gf_filter($computed['rows'], $gf, true);

    // Which status the '#%' order measures: whatever the user has filtered to, so the
    // number they are looking at is the number the bars are ranked by. With no status
    // filter (or the 'Adjusted' pseudo-status) it falls back to SLA Breached — index 2 of
    // $statuses inside sla_build_bar_payload().
    $pctIdx = ['In Progress' => 0, 'Within SLA' => 1, 'SLA Breached' => 2][$gf['status']] ?? 2;

    // The main bar chart keeps drawing EVERY group of its own dimension, even after the
    // user clicks one: a segment click filters the table (and the trend/item panels)
    // below, not the chart's own axis. Without this the chart collapses to the single
    // clicked bar — nothing left to compare it against, and no second bar to click.
    // So the bar payload alone is rebuilt with this dimension's own filter lifted;
    // every other filter (date range, status, parent taxonomy levels) still applies.
    $barGf = $gf;
    if ($dim === 'team') {
        $barGf['team'] = [];
        // A PIC belongs to exactly one team, so leaving the PIC filter on would empty
        // every OTHER team and hide them all over again — the same bug, moved.
        $barGf['pic'] = [];
    } else {
        $lvl = (int)substr($dim, 1);
        if ($lvl >= 0 && $lvl < 5) $barGf['tax'][$lvl] = '';
    }
    $barRows = ($barGf === $gf) ? $filteredRows : sla_gf_filter($computed['rows'], $barGf, true);

    $bar = sla_build_bar_payload($barRows, $dim, $sort, $barDir, $pctIdx);

    // Per-PIC breakdown for the drill-down card that appears BELOW the main bar chart
    // once a team is selected. $filteredRows already has gf.team applied, so this is
    // automatically scoped to the selected team(s) — no separate team argument needed.
    // Only built when the client asks for it (withPic=1, i.e. a team is selected), so
    // the default no-team view costs exactly what it did before.
    $picBar = (($_POST['withPic'] ?? '') === '1')
        ? sla_build_bar_payload($filteredRows, 'pic', $sort, $barDir, $pctIdx)
        : null;

    $trendBuckets = sla_trend_buckets();
    $teamColors = sla_team_colors();
    $trendDatasets = sla_build_trend_datasets($filteredRows, $gran, $trendBuckets, $computed['unique_teams'], $teamColors);

    // The item list DOES honour the status filter, unlike the charts above. $filteredRows
    // is already every other filter applied, so narrowing it by status here is exactly
    // sla_gf_filter($computed['rows'], $gf, false) — without a second pass over all rows.
    // 'Adjusted' mirrors sla_gf_filter()'s special case: it's not a statusName value.
    // Both call sla_rev_is_adjusted_line() so the rule has exactly one definition.
    $itemRows = ($gf['status'] === '')
        ? $filteredRows
        : array_values(array_filter($filteredRows, function($r) use ($gf) {
              return $gf['status'] === 'Adjusted'
                  ? sla_rev_is_adjusted_line($r)
                  : ($r['statusName'] ?? '') === $gf['status'];
          }));

    echo json_encode([
        'bar' => $bar,
        'picBar' => $picBar,
        'trend' => ['datasets' => $trendDatasets],
        'items' => sla_build_item_list($computed['rows'], $itemRows, $itemSearch, $itemSortCol, $itemSortDir),
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — never send raw PDO/SQL details to the client.
    error_log('ajax_sla_charts.php: ' . $e->getMessage());
    echo json_encode([
        'bar' => ['labels' => [], 'datasets' => []],
        'picBar' => null,
        'trend' => ['datasets' => []],
        'items' => ['itemTotalCount' => 0, 'itemShownCount' => 0, 'capped' => false, 'items' => []],
        'error' => 'Gagal memuat data chart. Coba lagi.',
    ]);
}
