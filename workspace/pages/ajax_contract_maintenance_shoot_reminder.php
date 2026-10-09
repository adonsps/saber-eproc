<?php
// pages/ajax_contract_maintenance_shoot_reminder.php
//
// The Category Manager's "Shoot Reminder" button on the Contract Maintenance
// dashboard: ctrl/shift-select specific rows in the "Expired or Expiring"
// table, then send a WhatsApp reminder about exactly those contracts.
//
// CATEGORY MANAGER ONLY, matching ajax_aon_shoot_reminder.php's convention:
// messaging a WA group is a lead's call, stricter than page view access
// (which also admits the named Facility/Property allowlist).
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Category Manager') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only a Category Manager can send this reminder.']);
    exit;
}

// POST only: this sends a WhatsApp message to a group, which is not something a
// link, a prefetch, or a stray page refresh should be able to do.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/contract_maintenance_reminder.php';

$data = json_decode(file_get_contents('php://input'), true);
$ids  = $data['ids'] ?? [];
if (!is_array($ids) || empty($ids)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'No contracts selected.']);
    exit;
}

$actor  = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);
$result = contract_maintenance_reminder_send_manual($pdo, $ids, $actor);

echo json_encode([
    'status'  => $result['status'],
    'message' => $result['message'],
    'count'   => $result['count'],
    'dry_run' => $result['dry_run'],
]);
