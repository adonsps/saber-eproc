<?php
// pages/ajax_vendor_eval_v2_table.php
//
// Server-side data source for #evalGradeTable (v2 Evaluation Grade Results) on
// vendor_eval_v2_results.php. Filtering (vendor/l2/l3/grade/date range) is
// client-side state, not a page reload: the filter form, grade-summary cards
// and donut-chart click all update one JS object and call table.ajax.reload(),
// which sends that object here as a `gf` JSON blob — same convention as
// ajax_dashboard_table.php's `gf`. ve2_grade_where_from_filters() is the one
// place that turns it into SQL, so the page's initial render and every reload
// after it can never disagree about what "filtered" means.
//
// In-memory server-side (same technique as ajax_vendor_eval_v1_table.php /
// ajax_dashboard_table.php), not SQL-side grade/search/column filtering: the
// aggregation query (ve2_grade_status_sql() — vendor_eval_submissions grouped
// by vendor/L2/L3, LEFT JOINed against a full GROUP BY of prs for gr_date)
// runs ONCE per request into a PHP array; the grade filter, search box,
// column "Apply Filter" boxes, sort and page slice all happen against that
// one array afterwards. This replaces an earlier version that ran the same
// aggregation 2-3x per request (once per COUNT for recordsTotal, again for
// recordsFiltered, again for the paginated SELECT) via nested derived tables
// — prs has 30k+ rows, so each execution re-paid the same JOIN+GROUP BY
// regardless of how few grouped rows actually matched. The gf-derived
// pre-aggregation WHERE (vendor/l2/l3/date range) still runs in SQL, since it
// changes which submissions feed the aggregation and therefore the aggregate
// values themselves (avg_score, po_count, ...) — but it's identical across
// the total/filtered/paginated views within one request, so it only needs to
// run with the query's single execution, same as before.
require_once '../includes/auth.php';
require_once '../includes/vendor_eval_v2_access.php';
ve2_require_ajax_access();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_results_helper.php';

header('Content-Type: application/json');

// ve2_grade_display_field(), ve2_grade_sort_value(), ve2_grade_compare(),
// ve2_grade_compare_multi() and ve2_grade_filter_rows() now live in
// includes/vendor_eval_v2_results_helper.php, shared with
// export_vendor_eval_v2_results.php so paging and export can never disagree
// about what a filter/sort means.

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;

try {
    $start  = max(0, isset($_POST['start']) ? (int)$_POST['start'] : 0);
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 25;
    if ($length <= 0 || $length > 500) $length = 25; // guard against an unbounded page
    $search = isset($_POST['search']['value']) ? trim((string)$_POST['search']['value']) : '';

    $colFiltersRaw = $_POST['colFilters'] ?? '{}';
    $colFilters    = json_decode(is_string($colFiltersRaw) ? $colFiltersRaw : '{}', true);
    if (!is_array($colFilters)) $colFilters = [];

    $gf = json_decode($_POST['gf'] ?? '', true);
    if (!is_array($gf)) $gf = [];
    [$where_sql, $params, $f_grade] = ve2_grade_where_from_filters($gf);
    $grade_sql = ve2_grade_status_sql($pdo, $where_sql);

    // The one expensive execution for this whole request — see file header.
    $stmt = $pdo->prepare($grade_sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Grade filter + free-text search + per-column "Apply Filter" boxes
    // (data-egcol 0..7 — col 8/Detail has no box).
    [$rows, $records_total] = ve2_grade_filter_rows($rows, $f_grade, $search, $colFilters);
    $records_filtered = count($rows);

    $orderList = [];
    if (isset($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            if (!isset($o['column'])) continue;
            $orderList[] = [
                'column' => (int)$o['column'],
                'dir'    => (isset($o['dir']) && strtolower($o['dir']) === 'asc') ? 'asc' : 'desc',
            ];
        }
    }
    if (empty($orderList)) $orderList[] = ['column' => 0, 'dir' => 'asc'];

    usort($rows, fn($a, $b) => ve2_grade_compare_multi($a, $b, $orderList));

    $page = array_slice($rows, $start, $length);

    $data = [];
    foreach ($page as $r) {
        $data[] = [
            'vendor'          => $r['vendor_name'],
            'l2'              => $r['taxonomy_level_2'],
            'l3'              => $r['taxonomy_level_3'],
            'poCount'         => (int)$r['po_count'],
            'avgScore'        => (float)$r['avg_score'],
            'grade'           => $r['grade'],
            'requestorCount'  => (int)$r['requestor_count'],
            'businessUnits'   => $r['business_units'],
            'grDate'          => $r['gr_date'],
            // Pre-formatted server-side (Asia/Jakarta) rather than left as a raw
            // UTC string for the browser to convert — avoids depending on the
            // viewer's local timezone matching Jakarta.
            'lastSubmittedAt' => ve2_format_date($r['last_submitted_at']),
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $records_total,
        'recordsFiltered' => $records_filtered,
        'data'            => $data,
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — DataTables surfaces the "error"
    // field to the user, so it must never carry raw PDO/SQL details.
    error_log('ajax_vendor_eval_v2_table.php: ' . $e->getMessage());
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => 'Gagal memuat Evaluation Grade Results. Coba muat ulang halaman.',
    ]);
}
