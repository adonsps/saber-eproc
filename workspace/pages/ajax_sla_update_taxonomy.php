<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
header('Content-Type: application/json');

// Write endpoint: refuses the read-only Viewer role with 403 JSON. This file
// carried no auth include at all before, so the gate also brings it in line
// with the rest of the AJAX surface (401 JSON on an expired session).
ws_require_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$ticket_no = trim($_POST['ticket_no'] ?? '');
$ticket_line_item = trim($_POST['ticket_line_item'] ?? '');
$master_data_id = (int)($_POST['master_data_id'] ?? 0);

if (empty($ticket_no) || empty($ticket_line_item)) {
    echo json_encode(['status' => 'error', 'message' => 'Ticket No and Line Item are required.']);
    exit;
}

if ($master_data_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Master Data ID.']);
    exit;
}

try {
    // Only update master_data_id to preserve the historical PIC assignment!
    // No last_updated_by/date stamp: those columns track assigned_pic only, and
    // this endpoint deliberately leaves the PIC alone (see includes/pr_audit_stamp.php).
    $updateStmt = $pdo->prepare("UPDATE prs SET master_data_id = ? WHERE ticket_no = ? AND ticket_line_item = ?");
    $updateStmt->execute([$master_data_id, $ticket_no, $ticket_line_item]);

    echo json_encode([
        'status' => 'success', 
        'message' => 'Taxonomy mapped successfully.',
        'updated_id' => $master_data_id
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
