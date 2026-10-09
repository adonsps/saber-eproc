<?php
// pages/ajax_contract_maintenance_edit.php
require_once '../includes/auth.php';
require_login();
require_once '../includes/contract_maintenance_helper.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!contract_maintenance_can_access($_SESSION['role'] ?? '', $_SESSION['username'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$clean_str = fn($v) => trim((string)($v ?? ''));
$clean_date = function ($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
};

$actor = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);

// business_unit and site_area arrive as arrays now (multi-select in the
// modal) -- joined into the same comma-separated free-text column each
// already used, not a schema/junction-table change. See
// contract_maintenance_helper.php's contract_maintenance_split_multi() for
// the reverse (reading a stored value back into individual values).
$clean_multi = function ($v) {
    $v = is_array($v) ? $v : ($v === null || $v === '' ? [] : [$v]);
    $v = array_map(fn($x) => trim((string)$x), $v);
    $v = array_values(array_filter($v, fn($x) => $x !== ''));
    return implode(', ', $v);
};

// Insert or update only. No delete endpoint: these records are never removed,
// per the team's call -- is_deleted stays on the table for a possible future
// admin tool, but nothing here ever sets it.
//
// Everything is required except gdrive_link and remarks. docs_reference is no
// longer set from this form (superseded by gdrive_link -- kept as a DB column
// only so historical seed data stays visible/queryable, not writable here).
//
// item_category and business_unit are no longer restricted to a fixed
// in_array() list (like site_area already wasn't): both dropdowns are
// user-extensible now (Select2 tags:true) -- a newly typed value is accepted
// as-is and becomes a selectable option for everyone from then on, once it's
// been used on a saved contract (see contract_maintenance_data.php, which
// merges each field's hardcoded suggestion list with distinct values already
// in the table).
$item_category     = $clean_str($data['item_category'] ?? '');
$business_unit     = $clean_multi($data['business_unit'] ?? '');
$description       = $clean_str($data['description'] ?? '');
$supplier          = $clean_str($data['supplier'] ?? '');
$contract_type     = $clean_str($data['contract_type'] ?? '');
$site_area         = $clean_multi($data['site_area'] ?? '');
$contract_end_date = $clean_date($data['contract_end_date'] ?? '');
$output_type       = $clean_str($data['output_type'] ?? '');
$output_end_date   = $clean_date($data['output_end_date'] ?? '');
$gdrive_link       = $clean_str($data['gdrive_link'] ?? '');

if ($item_category === '' || $business_unit === '' || $description === '' || $supplier === '' || $site_area === '' ||
    !$contract_end_date || !$output_end_date) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'All fields are required, except Google Drive Link and Remarks.']);
    exit;
}
if (strlen($business_unit) > 500) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Too many Business Units selected.']);
    exit;
}
if (strlen($site_area) > 1000) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Too many Site/Area values selected.']);
    exit;
}
if (!in_array($contract_type, contract_maintenance_types(), true)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid contract type.']);
    exit;
}
if (!in_array($output_type, contract_maintenance_output_types(), true)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid output type.']);
    exit;
}

$fields = [
    $item_category,
    $description,
    $supplier,
    $contract_type,
    $business_unit,
    $site_area,
    $contract_end_date,
    $output_type,
    $output_end_date,
    $clean_str($data['docs_reference'] ?? '') ?: null,
    $gdrive_link,
    $clean_str($data['remarks'] ?? '') ?: null,
];

try {
    $id = (int)($data['id'] ?? 0);

    if ($id > 0) {
        $stmt = $pdo->prepare("
            UPDATE contract_maintenance SET
                item_category     = ?,
                description       = ?,
                supplier          = ?,
                contract_type     = ?,
                business_unit     = ?,
                site_area         = ?,
                contract_end_date = ?,
                output_type       = ?,
                output_end_date   = ?,
                docs_reference    = ?,
                gdrive_link       = ?,
                remarks           = ?,
                updated_by        = ?,
                updated_at        = NOW()
            WHERE id = ? AND is_deleted = 0
        ");
        $stmt->execute([...$fields, $actor, $id]);
        if ($stmt->rowCount() === 0) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Contract not found.']);
            exit;
        }
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO contract_maintenance
                (item_category, description, supplier, contract_type, business_unit,
                 site_area, contract_end_date, output_type, output_end_date,
                 docs_reference, gdrive_link, remarks, created_by, updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([...$fields, $actor, $actor]);
    }

    echo json_encode(['status' => 'success']);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
