<?php
// pages/ajax_vendor_eval_v1_table.php
//
// Server-side data source for #v1MonitorTable (PO Evaluation Worklist) on
// vendor_eval_v2_results.php.
//
// In-memory server-side (same technique as ajax_dashboard_table.php /
// ajax_sla_table.php), not SQL-side WHERE/LIMIT: the base query — prs JOIN
// master_vendor JOIN quant_top_scores, two correlated per-PO subqueries
// against vendor_evaluations, GROUPed BY po_number+vendor_name, then wrapped
// with the status/final_score CASE (ve1_worklist_status_sql()) — runs ONCE
// per request into a PHP array; every status/column filter, the sort and the
// page slice all happen against that one array afterwards.
//
// This replaces an earlier version that ran that same query 2-3x per request
// (once per COUNT for recordsTotal, again for recordsFiltered, again for the
// paginated SELECT) via nested derived tables. prs has 20k+ rows, so each of
// those executions cost the same JOIN+GROUP BY regardless of how few PO rows
// actually matched the filters — that repetition, not missing indexes, was
// the actual slowdown. Fetching once and filtering in PHP fixes that
// regardless of dataset size; idx_po_vendor_group (config/database.php) is a
// separate, complementary fix for the GROUP BY itself.
require_once '../includes/auth.php';
require_once '../includes/vendor_eval_v2_access.php';
ve2_require_ajax_access();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_results_helper.php';

header('Content-Type: application/json');

// v1_display_field(), v1_sort_value(), v1_compare(), v1_compare_multi() and
// v1_worklist_filter_rows() now live in includes/vendor_eval_v2_results_helper.php,
// shared with export_vendor_eval_v1_worklist.php so paging and export can
// never disagree about what a filter/sort means.

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;

try {
    $start  = max(0, isset($_POST['start']) ? (int)$_POST['start'] : 0);
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 25;
    if ($length <= 0 || $length > 500) $length = 25; // guard against an unbounded page

    $status = trim((string)($_POST['status'] ?? ''));

    $colFiltersRaw = $_POST['colFilters'] ?? '{}';
    $colFilters    = json_decode(is_string($colFiltersRaw) ? $colFiltersRaw : '{}', true);
    if (!is_array($colFilters)) $colFilters = [];

    $quant_config = $pdo->query("SELECT * FROM quant_eval_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    if (!$quant_config) {
        $quant_config = ['qualitative_weight_percent' => 50, 'otf_weight_percent' => 25, 'top_weight_percent' => 25];
    }

    // The one expensive execution for this whole request — see file header.
    $has_bu = ve2_has_table($pdo, 'pgroup_business_unit_mappings');
    $status_sql = ve1_worklist_status_sql($quant_config, ve1_today_jakarta(), $has_bu);
    $rows = $pdo->query($status_sql)->fetchAll(PDO::FETCH_ASSOC);

    // Status legend filter + per-column "Apply Filter" boxes (data-v1col 1..10).
    // Matches the old SQL endpoint's semantics: recordsTotal is the count for
    // the active status (before the per-column boxes), not the grand total
    // across every status.
    [$rows, $records_total] = v1_worklist_filter_rows($rows, $status, $colFilters);
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

    usort($rows, fn($a, $b) => v1_compare_multi($a, $b, $orderList));

    $page = array_slice($rows, $start, $length);

    $data = [];
    foreach ($page as $r) {
        $data[] = [
            'status'          => $r['v1_status'],
            'poNumber'        => $r['po_number'],
            'avgScore'        => $r['avg_score']   !== null ? (float)$r['avg_score']   : null,
            'otfScore'        => $r['otf_score']   !== null ? (int)$r['otf_score']     : null,
            'otfStatus'       => $r['otf_status'],
            'topScore'        => $r['top_score']   !== null ? (int)$r['top_score']     : null,
            'vendorTop'       => $r['vendor_top'],
            'finalScore'      => $r['final_score'] !== null ? (float)$r['final_score'] : null,
            'allRequestors'   => $r['all_requestors'],
            'evalCount'       => (int)$r['eval_count'],
            'totalRequestors' => (int)$r['total_requestors'],
            'vendorName'      => $r['vendor_name'],
            'mvId'            => $r['mv_id'] !== null ? (int)$r['mv_id'] : null,
            'deliveryDate'    => $r['delivery_date'],
            'grDate'          => $r['gr_date'],
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
    error_log('ajax_vendor_eval_v1_table.php: ' . $e->getMessage());
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => 'Gagal memuat PO Evaluation Worklist. Coba muat ulang halaman.',
    ]);
}
