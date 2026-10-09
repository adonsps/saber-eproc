<?php
// pages/ajax_get_pr_taxonomy.php
// Taxonomy + PIC lookup for the ticket modal on dashboard_mgr.php. The queries
// live in includes/pr_taxonomy_lookup.php, shared with the SLA copy.
require_once '../includes/auth.php';
// Same gate as dashboard_mgr.php, this endpoint's only caller. This file used to
// load no auth at all, so an unauthenticated POST could read any ticket's lines,
// its taxonomy and its assigned PIC.
require_dashboard_view();
require_once '../config/database.php';
require_once '../includes/pr_taxonomy_lookup.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$ticket_no = isset($_POST['ticket_no']) ? trim($_POST['ticket_no']) : '';

if (empty($ticket_no)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Ticket No.']);
    exit;
}

try {
    echo json_encode([
        'status' => 'success',
        'data'   => pr_taxonomy_lookup($pdo, $ticket_no, true),
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
