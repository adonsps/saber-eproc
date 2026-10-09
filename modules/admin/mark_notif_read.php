<?php
// modules/admin/mark_notif_read.php
session_start();
require '../../config.php';

header('Content-Type: application/json');

// ── Auth guard ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    die(json_encode(['status' => 'error', 'message' => 'Unauthorized']));
}

// ── CSRF guard (token sent via fetch body) ───────────────────────────────────
$submitted_token = $_POST['csrf_token'] ?? '';
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid CSRF token']));
}

$uid = (int)$_SESSION['user_id'];
$id  = intval($_POST['notif_id'] ?? 0);

if ($id > 0) {
    // Mark single notification — FIX: use prepared statement, original used raw interpolation
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND recipient_id = ?");
    $stmt->bind_param("ii", $id, $uid);
    $stmt->execute();
} else {
    // Mark all in-app notifications for this user
    $stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE recipient_id = ? AND channel = 'inapp'");
    $stmt->bind_param("i", $uid);
    $stmt->execute();
}

echo json_encode(['status' => 'success']);
?>