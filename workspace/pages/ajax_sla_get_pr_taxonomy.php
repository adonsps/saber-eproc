<?php
// pages/ajax_sla_get_pr_taxonomy.php
// Taxonomy lookup for the line-item modal on sla_report.php. The queries live in
// includes/pr_taxonomy_lookup.php, shared with the Command Center copy — this
// one asks for the payload without the PIC columns, which it never reads.
require_once '../includes/auth.php';
// Same gate as sla_report.php, this endpoint's only caller. This file used to
// load no auth at all, so an unauthenticated POST could read any ticket's lines
// and its taxonomy.
require_sla_access();
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
        'data'   => pr_taxonomy_lookup($pdo, $ticket_no, false),
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
