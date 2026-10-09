<?php
// pages/ajax_cost_savings_correct.php
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/cost_savings_access.php';
require_login();
// cs_allowed_roles() now admits Viewer onto the Cost Savings page and its read
// endpoints; corrections stay closed to it (cs_can_correct() is CM-only, and
// this refuses the role outright).
ws_require_write();

// Same predicate the page uses to decide whether to render the Action column at
// all, so the button and the endpoint can never disagree about who may correct.
if (!cs_can_correct($_SESSION['role'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Category Managers can submit corrections.']);
    exit;
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request payload.']);
    exit;
}

$ticket_no = trim((string)($input['ticket_no'] ?? ''));
$ticket_line_item = $input['ticket_line_item'] ?? null;
$po_number = trim((string)($input['po_number'] ?? ''));
$sku = trim((string)($input['sku'] ?? ''));
$uom = trim((string)($input['uom'] ?? ''));
$correction_type = trim((string)($input['correction_type'] ?? ''));
$old_team = trim((string)($input['old_team'] ?? ''));
$new_team = $input['new_team'] ?? null;
$old_benchmark = $input['old_benchmark_unit_price'] ?? null;
$new_benchmark = $input['new_benchmark_unit_price'] ?? null;
$old_current_price = $input['old_current_unit_price'] ?? null;
$new_current_price = $input['new_current_unit_price'] ?? null;
$old_uom = trim((string)($input['old_uom'] ?? ''));
$new_uom_2025 = $input['new_uom_2025'] ?? null;
$new_uom_current = $input['new_uom_current'] ?? null;
$reason = trim((string)($input['reason'] ?? ''));

if ($ticket_no === '' || $ticket_line_item === null || $po_number === '' || $sku === '' || $uom === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Missing required ticket/PO identifiers.']);
    exit;
}

$valid_single_types = ['TEAM_REASSIGNMENT', 'BENCHMARK_OVERRIDE', 'CURRENT_PRICE_OVERRIDE', 'UOM_OVERRIDE_2025', 'UOM_OVERRIDE_CURRENT', 'EXCLUDE_FROM_CALCULATION'];
$type_list = ($correction_type === 'BOTH')
    ? ['TEAM_REASSIGNMENT', 'BENCHMARK_OVERRIDE']
    : array_map('trim', explode(',', $correction_type));

if (empty($type_list) || array_diff($type_list, $valid_single_types)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Invalid correction type.']);
    exit;
}

$reassignable_teams = ['CSGSM', 'TIGS', 'FNBOM'];
$wants_team = in_array('TEAM_REASSIGNMENT', $type_list, true);
$wants_benchmark = in_array('BENCHMARK_OVERRIDE', $type_list, true);
$wants_current_price = in_array('CURRENT_PRICE_OVERRIDE', $type_list, true);
$wants_uom_2025 = in_array('UOM_OVERRIDE_2025', $type_list, true);
$wants_uom_current = in_array('UOM_OVERRIDE_CURRENT', $type_list, true);

if ($wants_team) {
    if (!in_array($new_team, $reassignable_teams, true)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid team selection.']);
        exit;
    }
} else {
    $new_team = null;
}

if ($wants_benchmark) {
    if (!is_numeric($new_benchmark) || (float)$new_benchmark <= 0) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Benchmark unit price must be a positive number.']);
        exit;
    }
    $new_benchmark = (float)$new_benchmark;
} else {
    $new_benchmark = null;
}

if ($wants_current_price) {
    if (!is_numeric($new_current_price) || (float)$new_current_price <= 0) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Current PO unit price must be a positive number.']);
        exit;
    }
    $new_current_price = (float)$new_current_price;
} else {
    $new_current_price = null;
}

if ($old_benchmark !== null && !is_numeric($old_benchmark)) {
    $old_benchmark = null;
}

if ($old_current_price !== null && !is_numeric($old_current_price)) {
    $old_current_price = null;
}

if ($wants_uom_2025) {
    $new_uom_2025 = strtoupper(trim((string)$new_uom_2025));
    if ($new_uom_2025 === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Enter a UOM to use for the 2025 match.']);
        exit;
    }
} else {
    $new_uom_2025 = null;
}

if ($wants_uom_current) {
    $new_uom_current = strtoupper(trim((string)$new_uom_current));
    if ($new_uom_current === '') {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Enter a UOM for the current PO.']);
        exit;
    }
} else {
    $new_uom_current = null;
}

// Store the type list as a normalized comma-separated string (e.g.
// "TEAM_REASSIGNMENT,CURRENT_PRICE_OVERRIDE") so multiple correction types
// can be combined without enumerating every combination.
$correction_type = implode(',', $type_list);

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE po_line_corrections
        SET is_active = 0
        WHERE ticket_no = ? AND ticket_line_item = ? AND po_number = ? AND is_active = 1
    ");
    $stmt->execute([$ticket_no, $ticket_line_item, $po_number]);

    $stmt = $pdo->prepare("
        INSERT INTO po_line_corrections
            (ticket_no, ticket_line_item, po_number, sku, uom, correction_type,
             old_team, new_team, old_benchmark_unit_price, new_benchmark_unit_price,
             old_current_unit_price, new_current_unit_price,
             old_uom, new_uom_2025, new_uom_current,
             reason, corrected_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $ticket_no, $ticket_line_item, $po_number, $sku, $uom, $correction_type,
        $old_team !== '' ? $old_team : null, $new_team,
        $old_benchmark !== null ? (float)$old_benchmark : null, $new_benchmark,
        $old_current_price !== null ? (float)$old_current_price : null, $new_current_price,
        $old_uom !== '' ? $old_uom : null, $new_uom_2025, $new_uom_current,
        $reason !== '' ? $reason : null, $_SESSION['name'],
    ]);

    $pdo->commit();

    echo json_encode(['status' => 'success']);
} catch (PDOException $e) {
    // Guarded: if the failure happened before/while beginTransaction(), calling
    // rollBack() would itself throw and bury the real cause.
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Log the real exception server-side only. The message is rendered straight
    // into the correction modal, so it must never carry raw PDO/SQL details.
    error_log('ajax_cost_savings_correct.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Gagal menyimpan koreksi. Coba lagi.']);
}
