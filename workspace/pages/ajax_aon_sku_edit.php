<?php
// pages/ajax_aon_sku_edit.php
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

if (!aon_can_access($_SESSION['role'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data || empty($data['id'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing SKU id.']);
    exit;
}

$id = (int)$data['id'];

$clean_str = fn($v) => trim((string)($v ?? ''));
$clean_num = function ($v) {
    $v = str_replace([',', ' '], '', trim((string)($v ?? '')));
    return ($v !== '' && is_numeric($v)) ? (float)$v : null;
};
$clean_qty = function ($v) {
    // Store qty as VARCHAR string, stripped of formatting
    $v = str_replace([',', ' '], '', trim((string)($v ?? '')));
    return ($v !== '' && is_numeric($v)) ? $v : null;
};
// price_2025 is pipe-delimited (one price per 2025 item name, aligned
// index-for-index with previous_item). A blank segment is allowed and kept as
// an empty slot so the alignment with previous_item is preserved — the STC may
// know a name but not its price. A non-numeric (non-blank) segment is invalid,
// so the whole value is dropped rather than storing garbage. Returns null when
// every segment is blank.
$clean_price_2025 = function ($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $parts = [];
    $has_value = false;
    foreach (explode('|', $v) as $part) {
        $part = str_replace([',', ' '], '', trim($part));
        if ($part === '') { $parts[] = ''; continue; }
        if (!is_numeric($part)) return null;
        $parts[] = $part;
        $has_value = true;
    }
    return $has_value ? implode('|', $parts) : null;
};
$clean_date = function ($v) {
    $v = trim((string)($v ?? ''));
    if ($v === '') return null;
    $ts = strtotime($v);
    return $ts ? date('Y-m-d', $ts) : null;
};

$item_name  = $clean_str($data['item_name']);
if ($item_name === '') {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'Item Name cannot be empty.']);
    exit;
}

try {
    $prev_item = $clean_str($data['previous_item'] ?? '');
    $prev_item = $prev_item !== '' ? $prev_item : null;

    $updated_by = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);

    // Columns every AON maintainer owns, STC included: what the SKU is called,
    // its 2025 history, its contract reference, and who is chasing it.
    $sets = [
        'item_name'     => $item_name,
        'previous_item' => $prev_item,
        'contract_no'   => $clean_str($data['contract_no'] ?? '') ?: null,
        'handler'       => $clean_str($data['handler'] ?? '')     ?: null,
        'price_2025'    => $clean_price_2025($data['price_2025'] ?? ''),
    ];

    // The commercial terms come from the Price Per Vendor import and are the
    // Category Manager's / Manager's to set. For anyone else these columns are
    // left out of the statement entirely rather than re-written with whatever
    // was posted -- the edit form only disables the inputs, which a hand-built
    // request ignores. See includes/aon_helper.php.
    if (aon_can_edit_contract_fields($_SESSION['role'] ?? '')) {
        $sets += [
            'vendor'      => $clean_str($data['vendor'] ?? '')   ?: null,
            'category'    => $clean_str($data['category'] ?? '') ?: null,
            'valid_from'  => $clean_date($data['valid_from'] ?? ''),
            'valid_until' => $clean_date($data['valid_until'] ?? ''),
            'price'       => $clean_num($data['price_2026'] ?? ''),
            'quantity'    => $clean_qty($data['quantity'] ?? ''),
            'used_qty'    => $clean_qty($data['used_qty'] ?? ''),
            'remain_qty'  => $clean_qty($data['remain_qty'] ?? ''),
        ];
    }

    // Keys are the fixed literals above, never anything from $data.
    $assignments = implode(', ', array_map(
        fn($col) => "$col = ?",
        array_keys($sets)
    ));

    $stmt = $pdo->prepare("
        UPDATE aon_skus SET
            $assignments,
            updated_by = ?,
            updated_at = NOW()
        WHERE id = ? AND is_deleted = 0
    ");

    $stmt->execute([
        ...array_values($sets),
        $updated_by,
        $id,
    ]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'SKU not found.']);
        exit;
    }

    echo json_encode(['status' => 'success']);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
