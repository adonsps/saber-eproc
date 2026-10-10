<?php
// pages/ajax_check_ticket.php
// Real-time check whether a ticket_no already exists in prs table.
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$ticket_no = trim($_GET['ticket_no'] ?? '');

if ($ticket_no === '') {
    echo json_encode(['exists' => false, 'ticket_no' => '']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM prs WHERE ticket_no = ?");
    $stmt->execute([$ticket_no]);
    $count = (int)$stmt->fetchColumn();

    echo json_encode([
        'exists'    => ($count > 0),
        'count'     => $count,
        'ticket_no' => $ticket_no
    ]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'exists' => false]);
}
