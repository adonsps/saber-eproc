<?php
// pages/ajax_process_batch.php
//
// AJAX endpoint behind the "AI Dispatcher" page (auto_assign.php): its
// JavaScript calls this once per batch and reads successCount / remaining /
// fallbackDetails / failedDetails / estimated_tokens by name.
//
// The classification logic itself now lives in
// workspace/includes/ai_ticket_assigner.php so the K2 parser worker
// (workspace/cron/process_k2_import_queue.php) can run the exact same code
// unattended. This file is deliberately a thin transport wrapper -- keep the
// behaviour changes in the library, not here.
header('Content-Type: application/json');

// 1. Prevent PHP from timing out during slow API responses
set_time_limit(120);

require_once '../config/database.php';
require_once '../includes/ai_ticket_assigner.php';

try {
    echo json_encode(ai_assign_process_batch($pdo));
} catch (\Throwable $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
