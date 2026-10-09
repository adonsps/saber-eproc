<?php
// workspace/pages/ajax_vendor_eval_tech_detail.php
//
// Read-only drill-down endpoint for the Technical Department Vendor Evaluation
// results page (vendor_eval_results_tech.php). Given ?po=<po_number>, returns
// both teams' Form 2 submissions + per-criterion answers as JSON for the modal.
//
// SECURITY: gated to the Technical Department role (ve2_tech_require_ajax_access)
// AND scope-checked — the requested PO's PGroup must be within the caller's
// team scope, so a user can never read a PO outside their scope by guessing the
// number. No DB writes. No WA/email. No external API.

require_once '../includes/auth.php';                       // require_login()
require_once '../config/database.php';                     // $pdo
require_once '../includes/vendor_eval_v2_tech_results.php'; // gate + scope + detail helpers

header('Content-Type: application/json');

$access  = ve2_tech_require_ajax_access($pdo);   // 403s + exits unless allowed
$pgroups = $access['pgroups'];

$po_number = trim((string)($_GET['po'] ?? ''));
if ($po_number === '' || strlen($po_number) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid PO number.']);
    exit;
}

// Scope guard: the PO must belong to one of the caller's in-scope PGroups.
if (!ve2_tech_po_in_scope($pdo, $po_number, $pgroups)) {
    http_response_code(403);
    echo json_encode(['error' => 'This purchase order is outside your team\'s access scope.']);
    exit;
}

try {
    $detail = ve2_tech_po_detail($pdo, $po_number);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'A system error occurred while loading the evaluation detail.']);
    exit;
}

echo json_encode($detail);
