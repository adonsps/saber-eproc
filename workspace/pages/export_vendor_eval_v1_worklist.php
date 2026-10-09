<?php
// pages/export_vendor_eval_v1_worklist.php
//
// Full (unpaginated) XLSX export of the "PO Evaluation Worklist" table on
// vendor_eval_v2_results.php's PO Worklist tab. Honors the same status
// legend + per-column "Apply Filter" boxes as ajax_vendor_eval_v1_table.php
// (shared filtering via v1_worklist_filter_rows() in
// includes/vendor_eval_v2_results_helper.php) and the same default
// red-on-top / most-recent-GR-first sort, so the download always matches
// what the manager is currently looking at.
require_once '../includes/auth.php';
require_once '../includes/vendor_eval_v2_access.php';
ve2_require_ajax_access();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_results_helper.php';
require_once '../vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

$status = trim((string)($_GET['status'] ?? ''));

$colFiltersRaw = $_GET['colFilters'] ?? '{}';
$colFilters    = json_decode(is_string($colFiltersRaw) ? $colFiltersRaw : '{}', true);
if (!is_array($colFilters)) $colFilters = [];

$quant_config = $pdo->query("SELECT * FROM quant_eval_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$quant_config) {
    $quant_config = ['qualitative_weight_percent' => 50, 'otf_weight_percent' => 25, 'top_weight_percent' => 25];
}

$has_bu = ve2_has_table($pdo, 'pgroup_business_unit_mappings');
$status_sql = ve1_worklist_status_sql($quant_config, ve1_today_jakarta(), $has_bu);
$rows = $pdo->query($status_sql)->fetchAll(PDO::FETCH_ASSOC);

[$rows] = v1_worklist_filter_rows($rows, $status, $colFilters);

usort($rows, fn($a, $b) => v1_compare_multi($a, $b, [['column' => 0, 'dir' => 'asc']]));

$writer = new Writer();
$writer->openToBrowser('PO_Evaluation_Worklist_' . date('Ymd_His') . '.xlsx');

$writer->addRow(Row::fromValues([
    'Status', 'PO Number', 'Qualitative Score', 'OTF Score', 'TOP Score',
    'Final Score', 'Requestors', 'Vendor', 'Delivery Date', 'Latest GR', 'Business Unit',
]));

foreach ($rows as $r) {
    $writer->addRow(Row::fromValues([
        $r['v1_status'] ?? '',
        $r['po_number'] ?? '',
        $r['avg_score']   !== null ? number_format((float)$r['avg_score'], 2)   : '--',
        $r['otf_score']   !== null ? (int)$r['otf_score']                       : '--',
        $r['top_score']   !== null ? (int)$r['top_score']                       : '--',
        $r['final_score'] !== null ? number_format((float)$r['final_score'], 2) : 'Inc. Data',
        $r['all_requestors'] ?? '',
        $r['vendor_name'] ?? '',
        $r['delivery_date'] ? date('d M Y', strtotime($r['delivery_date'])) : '-',
        $r['gr_date']       ? date('d M Y', strtotime($r['gr_date']))       : '-',
        $r['business_units'] ?? '',
    ]));
}

$writer->close();
