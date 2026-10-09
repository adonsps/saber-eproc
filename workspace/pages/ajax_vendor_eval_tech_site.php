<?php
// workspace/pages/ajax_vendor_eval_tech_site.php
//
// Read-only JSON endpoint: the POs at one site (prs.site_allocation) within the
// logged-in Technical Department account's PGroup scope, each with its Form 2
// evaluation status/grade. Backs the site drill-down modal on
// vendor_eval_results_tech.php. No DB writes, no external calls.

require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_tech_results.php';

header('Content-Type: application/json');

$access  = ve2_tech_require_ajax_access($pdo);   // 403 JSON + exit unless allowed
$pgroups = $access['pgroups'];

$site = trim((string)($_GET['site'] ?? ''));
if ($site === '' || strlen($site) > 150) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid site.']);
    exit;
}

if (!ve2_tech_site_in_scope($pdo, $site, $pgroups)) {
    http_response_code(403);
    echo json_encode(['error' => 'This site is outside your team\'s access scope.']);
    exit;
}

try {
    $pos = ve2_tech_site_pos($pdo, $site, $pgroups);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'A system error occurred while loading the site detail.']);
    exit;
}

echo json_encode(['site_allocation' => $site, 'pos' => $pos]);
