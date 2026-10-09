<?php
// pages/ajax_aon_review_save.php
// Save an STC's review decision (On Review / Ignore) + remarks for one
// repeat-purchase alert SKU (see aon_repeat_alert.php). One row per SKU —
// clearing the decision never erases who last checked it / when.
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';

if (!aon_can_access($_SESSION['role'] ?? '')) {
    aon_json_error('You do not have permission to review AON alerts.', 403);
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    aon_json_error('Invalid request payload.');
}

$sku_code = aon_nullable_string($input['sku_code'] ?? '');
if ($sku_code === null) {
    aon_json_error('Missing SKU code.');
}

$review_status = aon_nullable_string($input['review_status'] ?? '');
if ($review_status !== null) {
    $review_status = strtoupper($review_status);
    if (!in_array($review_status, ['REVIEW', 'IGNORE'], true)) {
        aon_json_error('Invalid review status.');
    }
}

$remarks = aon_nullable_string($input['remarks'] ?? '');
if ($review_status === 'IGNORE' && $remarks === null) {
    aon_json_error('Remarks are required when marking as Ignore.');
}

$is_checked = $review_status !== null ? 1 : 0;
$actor      = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);

try {
    $stmt = $pdo->prepare("
        INSERT INTO aon_alert_reviews (sku_code, is_checked, review_status, remarks, checked_by, checked_at, updated_by)
        VALUES (?, ?, ?, ?, ?, IF(? = 1, NOW(), NULL), ?)
        ON DUPLICATE KEY UPDATE
            is_checked    = VALUES(is_checked),
            review_status = VALUES(review_status),
            remarks       = VALUES(remarks),
            checked_by    = IF(VALUES(is_checked) = 1, VALUES(checked_by), checked_by),
            checked_at    = IF(VALUES(is_checked) = 1, NOW(), checked_at),
            updated_by    = VALUES(updated_by)
    ");
    $stmt->execute([$sku_code, $is_checked, $review_status, $remarks, $actor, $is_checked, $actor]);

    echo json_encode(['status' => 'success']);
} catch (\PDOException $e) {
    aon_json_error('Failed to save review. Please try again.', 500);
}
