<?php
// workspace/pages/ajax_vendor_eval_tech_profile.php
//
// Read-only JSON endpoint: one vendor's Form 2 quality history within the
// logged-in Technical Department account's PGroup scope, both teams combined
// per PO, split into all-time and year-to-date buckets. Backs the vendor-profile
// modal on vendor_eval_results_tech.php. No DB writes, no external calls.

require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_tech_results.php';

header('Content-Type: application/json');

$access  = ve2_tech_require_ajax_access($pdo);   // 403 JSON + exit unless allowed
$pgroups = $access['pgroups'];

$vendor_name = trim((string)($_GET['vendor'] ?? ''));
if ($vendor_name === '' || strlen($vendor_name) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid vendor.']);
    exit;
}

if (!ve2_tech_vendor_in_scope($pdo, $vendor_name, $pgroups)) {
    http_response_code(403);
    echo json_encode(['error' => 'This vendor is outside your team\'s access scope.']);
    exit;
}

// Optional Year/Month filter — the modal's own time filter, independent of
// the dashboard's global one. When either is present, respond with a single
// 'range' bucket for that window instead of the fixed all_time/ytd pair.
$y_raw = trim((string)($_GET['year'] ?? ''));
$year  = (ctype_digit($y_raw) && strlen($y_raw) === 4) ? (int)$y_raw : null;
$m_raw = trim((string)($_GET['month'] ?? ''));
$month = (ctype_digit($m_raw) && (int)$m_raw >= 1 && (int)$m_raw <= 12) ? (int)$m_raw : null;

try {
    if ($year !== null || $month !== null) {
        $range = ve2_tech_vendor_profile_range($pdo, $vendor_name, $pgroups, $year, $month);
        echo json_encode(['vendor_name' => $vendor_name, 'range' => $range]);
        exit;
    }
    $profile = ve2_tech_vendor_profile($pdo, $vendor_name, $pgroups);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'A system error occurred while loading the vendor profile.']);
    exit;
}

echo json_encode(['vendor_name' => $vendor_name, 'profile' => $profile]);
