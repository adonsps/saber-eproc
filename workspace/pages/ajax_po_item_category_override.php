<?php
// workspace/pages/ajax_po_item_category_override.php
//
// Vendor Evaluation v2 — manual Barang/Jasa override for the Form 2 (Project
// Retail — New Store) shared worklist ("move POs between these categories"
// when the live keyword guess is wrong).
//
// Access model: same single global no-login token as
// public_eval_v2_project_worklist.php (POST ?token=... in the body) — this
// endpoint is the ONLY write path onto vendor_eval_po_item_category.
//
// Write tables: vendor_eval_po_item_category (via ve2_item_category_set()).
// Safety invariants:
//   • No auth.php / session / login (matches the worklist it serves).
//   • POST only. No WA/email/Gemini/external API calls.
//   • Never touches vendor_evaluations (v1) or v1 tables.
//   • Never used by / wired into public_eval_v2_worklist.php (general worklist).

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only.']);
    exit;
}

require_once '../config/database.php'; // provides $pdo (PDO)
require_once '../includes/vendor_eval_v2_form2_worklist_token.php';
require_once '../includes/vendor_eval_v2_item_category.php';

$token = trim((string)($_POST['token'] ?? ''));
if (!ve2_form2_worklist_token_validate($pdo, $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid or expired worklist link.']);
    exit;
}

$po_number = trim((string)($_POST['po_number'] ?? ''));
$category  = trim((string)($_POST['category'] ?? ''));
$name      = trim((string)($_POST['updated_by'] ?? ''));

if ($po_number === '' || strlen($po_number) > 255) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid PO number.']);
    exit;
}
if (!in_array($category, ['barang', 'jasa'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Category must be barang or jasa.']);
    exit;
}

$ok = ve2_item_category_set($pdo, $po_number, $category, $name !== '' ? $name : null);
if (!$ok) {
    http_response_code(500);
    echo json_encode(['error' => 'Could not save the override. Please try again.']);
    exit;
}

echo json_encode(['ok' => true, 'po_number' => $po_number, 'category' => $category]);
