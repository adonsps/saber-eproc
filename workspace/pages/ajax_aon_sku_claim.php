<?php
// pages/ajax_aon_sku_claim.php
// Assign an AON SKU to a named STC. The handler must be an actual STC user.
// A SKU can only be claimed while it is unassigned (handler is NULL/empty).
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';

if (!aon_can_access($_SESSION['role'] ?? '')) {
    aon_json_error('You do not have permission to assign AON SKUs.', 403);
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    aon_json_error('Invalid request payload.');
}

// Accept either a single `id` (legacy, one SKU) or an `ids` array (bulk claim)
$ids = [];
if (isset($input['ids']) && is_array($input['ids'])) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $input['ids']), fn($v) => $v > 0)));
} elseif (isset($input['id'])) {
    $id = (int)$input['id'];
    if ($id > 0) $ids = [$id];
}
$handler = isset($input['handler']) ? trim((string)$input['handler']) : '';

if (empty($ids))     aon_json_error('Missing SKU id.');
if ($handler === '') aon_json_error('Please select an STC before confirming.');

// Validate that the chosen handler is an actual STC user
$check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE name = ? AND role = 'STC'");
$check->execute([$handler]);
if ((int)$check->fetchColumn() === 0) {
    aon_json_error('Selected handler is not a valid STC user.', 422);
}

try {
    $updated_by = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);

    $stmt = $pdo->prepare("
        UPDATE aon_skus
        SET handler = ?, updated_by = ?
        WHERE id = ? AND is_deleted = 0 AND (handler IS NULL OR handler = '')
    ");

    $assigned = 0;
    $failed_ids = [];
    foreach ($ids as $id) {
        $stmt->execute([$handler, $updated_by, $id]);
        if ($stmt->rowCount() > 0) {
            $assigned++;
        } else {
            $failed_ids[] = $id;
        }
    }

    if ($assigned === 0) {
        aon_json_error('These SKU(s) have already been assigned or are no longer available.', 409);
    }

    echo json_encode([
        'status'     => 'success',
        'message'    => 'SKU assigned.',
        'owner'      => $handler,
        'assigned'   => $assigned,
        'failed_ids' => $failed_ids,
    ]);
} catch (\PDOException $e) {
    aon_json_error('Failed to assign SKU. Please try again.', 500);
}
