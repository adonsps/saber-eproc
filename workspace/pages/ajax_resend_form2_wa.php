<?php
// workspace/pages/ajax_resend_form2_wa.php
//
// Vendor Evaluation v2 — Form 2 WhatsApp-only RE-SEND (LIVE). Category-Manager
// only. Re-sends ONLY the WhatsApp reminder that previously failed, ignoring the
// 7-day cadence, via the shared ve2_form2_resend_wa() in
// workspace/includes/vendor_eval_v2_form2_reminder.php.
//
// LIVE gate: preview=false + the VE2_FORM2_SEND_CONFIRM token below. Email is
// never sent by this endpoint. It is idempotent — a recipient who already has a
// status='sent' WhatsApp row with the identical message body is skipped, so
// clicking twice never double-sends.
//
// Also honours the same vendor_eval_v2_form2_send_settings kill switch the cron
// and the main "Send Real Reminder" button check (ve2_form2_live_send_enabled()):
// with live_send_enabled=0 nothing goes out here either. Fails closed.

require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ve2wa_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Category Manager') {
    ve2wa_json(403, ['success' => false, 'message' => 'Not authorised to send Vendor Evaluation Form 2 WhatsApp re-sends.']);
}
require_category_manager();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ve2wa_json(405, ['success' => false, 'message' => 'POST required.']);
}

require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_sender.php';          // ve2_web_base_url()
require_once '../includes/vendor_eval_v2_form2_reminder.php';
require_once '../includes/vendor_eval_v2_form2_kill_switch.php';

// Master kill switch — same one the cron and the main reminder button read.
// Fails closed: any DB error or missing table blocks the send.
if (!ve2_form2_live_send_enabled($pdo)) {
    ve2wa_json(423, [
        'success' => false,
        'message' => 'Live sending is currently disabled by the Form 2 kill switch (vendor_eval_v2_form2_send_settings.live_send_enabled = 0). Nothing was sent.',
    ]);
}

try {
    $base_url = ve2_web_base_url();
} catch (RuntimeException $e) {
    ve2wa_json(500, ['success' => false, 'message' => 'Cannot build a safe worklist link: ' . $e->getMessage()]);
}

try {
    $result = ve2_form2_resend_wa($pdo, [
        'base_url'  => $base_url,
        'preview'   => false, // LIVE WhatsApp re-send — see file header
        'confirm'   => VE2_FORM2_SEND_CONFIRM,
        'run_label' => 'wa_resend_manual',
    ]);
} catch (Throwable $e) {
    error_log('[ajax_resend_form2_wa] ' . $e->getMessage());
    ve2wa_json(500, ['success' => false, 'message' => 'WhatsApp re-send failed. Check the server log — some messages may have been sent before the failure.']);
}

ve2wa_json(200, [
    'success' => (bool)$result['ok'],
    'message' => $result['ok']
        ? sprintf(
            'WhatsApp re-send done: %d sent, %d failed, %d skipped (already delivered), across %d PO(s).',
            $result['wa_sent'], $result['wa_failed'], $result['wa_skipped'], $result['po_count']
        )
        : implode(' ', $result['errors']),
    'result' => $result,
]);
