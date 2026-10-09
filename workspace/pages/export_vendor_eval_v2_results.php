<?php
// pages/export_vendor_eval_v2_results.php
//
// Full (unpaginated) XLSX export of the "Evaluation Grade" table on
// vendor_eval_v2_results.php's Results tab. Honors the same vendor/L2/L3/
// grade/date-range filters, free-text search and per-column "Apply Filter"
// boxes as ajax_vendor_eval_v2_table.php (shared filtering via
// ve2_grade_filter_rows() in includes/vendor_eval_v2_results_helper.php), so
// the download always matches what the manager is currently looking at.
require_once '../includes/auth.php';
require_once '../includes/vendor_eval_v2_access.php';
ve2_require_ajax_access();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_results_helper.php';
require_once '../vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$colFiltersRaw = $_GET['colFilters'] ?? '{}';
$colFilters    = json_decode(is_string($colFiltersRaw) ? $colFiltersRaw : '{}', true);
if (!is_array($colFilters)) $colFilters = [];

$gf = json_decode($_GET['gf'] ?? '', true);
if (!is_array($gf)) $gf = [];
[$where_sql, $params, $f_grade] = ve2_grade_where_from_filters($gf);
$grade_sql = ve2_grade_status_sql($pdo, $where_sql);

$stmt = $pdo->prepare($grade_sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

[$rows] = ve2_grade_filter_rows($rows, $f_grade, $search, $colFilters);

usort($rows, fn($a, $b) => ve2_grade_compare_multi($a, $b, [['column' => 0, 'dir' => 'asc']]));

$writer = new Writer();
$writer->openToBrowser('Vendor_Evaluation_Grade_Results_' . date('Ymd_His') . '.xlsx');

$writer->addRow(Row::fromValues([
    'Vendor', 'Taxonomy L2', 'Taxonomy L3', 'Evaluated #PO', 'Avg Score',
    'Grade', 'Requestors', 'Business Unit', 'GR Date', 'Last Submitted',
]));

foreach ($rows as $r) {
    $writer->addRow(Row::fromValues([
        $r['vendor_name'] ?? '',
        $r['taxonomy_level_2'] ?? '',
        $r['taxonomy_level_3'] ?? '',
        (int)($r['po_count'] ?? 0),
        $r['avg_score'] !== null ? number_format((float)$r['avg_score'], 2) : '',
        $r['grade'] ?? '',
        (int)($r['requestor_count'] ?? 0),
        $r['business_units'] ?? '',
        $r['gr_date'] ? date('d M Y', strtotime($r['gr_date'])) : 'Pending',
        ve2_format_date($r['last_submitted_at'] ?? null),
    ]));
}

$writer->close();
