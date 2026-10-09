<?php
// pages/ajax_sla_table.php
// Server-side data source for the #slaTable DataTables on sla_report.php. Team/status
// filtering and sorting-by-computed-status can't be a raw SQL WHERE/ORDER BY (they only
// exist after the persona filter + per-row business-logic loop in get_sla_computed_rows()),
// so this endpoint re-runs that same shared logic every request and then filters/searches/
// sorts/paginates the resulting in-memory array — same business rules, same access model,
// just executed per request instead of once at page load and shipped to the browser.
require_once '../includes/auth.php';
require_sla_access();
require_once '../config/database.php';
require_once '../includes/sla_helper.php';
require_once '../includes/project_team_helper.php';

header('Content-Type: application/json');

// The sortable value of every column of one shaped row, as a packed list indexed by
// DataTables column index (mirrors the <thead> in sla_report.php). Sorting 30k+ rows
// on THIS instead of on the shaped rows themselves is what keeps the endpoint inside
// its memory limit: the shaped row is built, projected, and thrown away again, so only
// one of them is ever resident (see the pipeline at the bottom of this file).
// 20/21 = hidden porg/pgroup, 22 = the History column — none of them orderable, so
// they are absent here and fall through to the PR-date default in sla_table_compare().
function sla_table_sort_key($row) {
    return [
        (string)$row['ticket'],
        (string)($row['escalated'] ? 1 : 0),
        (string)$row['status'],
        (string)$row['dur'],   // lexicographic, matches the old default (no custom sort-type was registered client-side)
        (string)$row['line_item'],
        (string)$row['item'],
        (string)$row['type'],
        (string)$row['pic'],
        (string)$row['team'],
        (string)$row['d'],
        (string)$row['cno'],   // Canvassing No (stacked w/ its own date on screen)
        (string)$row['cnd'],   // Canvassing Date — hidden column, exists for the exports
        (string)$row['cv'],
        (string)$row['cdesc'], // Canvassing Description — its leading code sets the SLA target
        (string)$row['lv'][0],
        (string)$row['lv'][1],
        (string)$row['lv'][2],
        (string)$row['lv'][3],
        (string)$row['lv'][4],
        (string)$row['d'],
    ];
}

// $a/$b are sla_table_sort_key() projections, not shaped rows.
function sla_table_compare($a, $b, $col) {
    // Anything outside the projection (unorderable/hidden columns) sorts by PR date,
    // which is what the switch this replaced did in its default branch.
    if (!isset($a[$col], $b[$col])) $col = 9;
    return strcmp($a[$col], $b[$col]);
}

// DataTables' default client-side sort supports shift-click multi-column sort
// (order[0], order[1], ...); this replicates that by comparing column-by-column
// until a tie-break is found, instead of only ever looking at order[0].
function sla_table_compare_multi($a, $b, $orderList) {
    foreach ($orderList as $o) {
        $cmp = sla_table_compare($a, $b, $o['column']);
        if ($cmp !== 0) return $o['dir'] === 'asc' ? $cmp : -$cmp;
    }
    return 0;
}

// Reproduces the per-column "filter"-type values DataTables' default global search box
// used to search against client-side (see sla_report.php's column render functions),
// so the smart-search box keeps matching the same things it matched before.
function sla_table_search_haystack($row) {
    $fmtDate = function($iso) {
        if (!$iso) return '-';
        $ts = strtotime($iso);
        return $ts ? date('d M Y', $ts) : '-';
    };
    return [
        (string)($row['ticket'] ?? ''),
        // Matches the column's 'filter' render: 1/0, not the "Escalated"/"not escalated" label.
        (string)(($row['escalated'] ?? false) ? 1 : 0),
        (string)($row['status'] ?? ''),
        (string)($row['dur'] ?? ''),
        (string)($row['line_item'] ?? ''),
        (string)($row['item'] ?? ''),
        (string)($row['type'] ?? ''),
        (string)($row['pic'] ?? ''),
        (string)($row['team'] ?? ''),
        $fmtDate($row['d'] ?? ''),
        (string)($row['cno'] ?? ''),
        $fmtDate($row['cnd'] ?? ''),
        $fmtDate($row['cv'] ?? ''),
        // Matches the column's 'filter' render: an empty description searches as '-'.
        (($row['cdesc'] ?? '') !== '' ? (string)$row['cdesc'] : '-'),
        (string)($row['lv'][0] ?? ''),
        (string)($row['lv'][1] ?? ''),
        (string)($row['lv'][2] ?? ''),
        ($row['lv'][3] !== '' ? $row['lv'][3] : '-'),
        (string)($row['lv'][4] ?? ''),
        (string)($row['d'] ?? ''),
    ];
}

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;

try {
    $start  = isset($_POST['start']) ? (int)$_POST['start'] : 0;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 50;
    $searchValue = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';

    // sla_report.php prepends a row-select checkbox column for Category Manager
    // only, which shifts every DataTables column index by one for that role.
    // sla_table_compare() is written against the unshifted layout, so normalise
    // here — without this, a Category Manager clicking "Ticket No" would sort by
    // "Line Item" (and so on down the row).
    $col_offset = (($_SESSION['role'] ?? '') === 'Category Manager') ? 1 : 0;

    $orderList = [];
    if (isset($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            if (!isset($o['column'])) continue;
            $orderList[] = [
                'column' => (int)$o['column'] - $col_offset,
                'dir' => (isset($o['dir']) && strtolower($o['dir']) === 'asc') ? 'asc' : 'desc',
            ];
        }
    }
    if (empty($orderList)) $orderList[] = ['column' => 9, 'dir' => 'desc'];

    $gf = sla_normalize_gf($_POST['gf'] ?? '');

    $ctx = resolve_sla_persona($pdo, $_SESSION);
    $computed = get_sla_computed_rows($pdo, $ctx);

    $recordsTotal = count($computed['rows']);

    $filtered = sla_gf_filter($computed['rows'], $gf, false);

    // Search and sort WITHOUT keeping a shaped copy of every row.
    //
    // This used to be array_map('sla_shape_row_for_client', $filtered), which held the
    // computed set and a full shaped set at the same time — at production volume
    // (30k+ lines for a Category Manager) that exhausted a 256M limit outright, and
    // the fatal landed inside sla_shape_row_for_client() itself. Only 50 rows are ever
    // returned, so the other ~33k are shaped purely to be searched, sorted and
    // discarded. Each row is therefore shaped, reduced to its sort projection (plus a
    // search test against the same shaped values as before), and released again.
    //
    // Behaviour is unchanged: the search still matches sla_table_search_haystack() and
    // the sort still compares the same per-column values, because both still read a
    // real shaped row — one at a time instead of all at once.
    $words = ($searchValue !== '') ? preg_split('/\s+/', $searchValue, -1, PREG_SPLIT_NO_EMPTY) : [];
    $index = []; // [['k' => key into $filtered, 'v' => sort projection], ...]
    foreach (array_keys($filtered) as $i) {
        $shapedRow = sla_shape_row_for_client($filtered[$i]);

        if ($words) {
            $hay = strtolower(implode(' ', sla_table_search_haystack($shapedRow)));
            foreach ($words as $w) {
                if (strpos($hay, strtolower($w)) === false) { $shapedRow = null; break; }
            }
            if ($shapedRow === null) continue;
        }

        $index[] = ['k' => $i, 'v' => sla_table_sort_key($shapedRow)];
    }
    $recordsFiltered = count($index);

    usort($index, function($a, $b) use ($orderList) {
        return sla_table_compare_multi($a['v'], $b['v'], $orderList);
    });

    // Only now is anything shaped for output — the page DataTables actually asked for.
    // $length <= 0 means "no paging" (the export-all-pages path), which still shapes
    // every matching row, exactly as before.
    //
    // Reduce to bare row keys and drop the projections BEFORE shaping: on the
    // export-all path the page is the whole set, so holding both would put the
    // projections and a full shaped set in memory at the same time — more than the
    // array_map() this replaced ever used.
    $pageKeys = array_column(($length > 0) ? array_slice($index, $start, $length) : $index, 'k');
    unset($index);
    $page = [];
    foreach ($pageKeys as $k) {
        $page[] = sla_shape_row_for_client($filtered[$k]);
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $page,
    ]);
} catch (Throwable $e) {
    // Log the real exception server-side only — DataTables shows the "error" field to the
    // end user via alert(), so it must never contain raw PDO/SQL details.
    error_log('ajax_sla_table.php: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Gagal memuat data SLA. Coba muat ulang halaman.',
    ]);
}
