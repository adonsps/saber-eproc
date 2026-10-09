<?php
// workspace/pages/ajax_send_form2_reminder.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail, New Store) manual "Shoot
// Reminder" button. Category-Manager only. Calls the exact same
// ve2_form2_send_reminders() the cron uses — see
// workspace/includes/vendor_eval_v2_form2_reminder.php for the shared logic.
//
// LIVE SEND as of 2026-07-29: preview=false + the VE2_FORM2_SEND_CONFIRM
// token below. Clicking this button now sends real WA/email to every row in
// vendor_eval_v2_form2_team_contacts for every due PO.
//
// As of 2026-07-30 this endpoint ALSO checks the same
// vendor_eval_v2_form2_send_settings kill switch the cron checks (see
// ve2_form2_live_send_enabled() below) — sitting alongside, not replacing,
// the Category-Manager + confirm-token checks. This makes the switch a
// genuine single master control over BOTH send paths: with
// live_send_enabled=0, neither the cron nor this button can send live, no
// matter who clicks what. The preview/confirm-dialog step
// (ajax_preview_form2_reminder.php) is unaffected and still always runs —
// only the actual live-send attempt is blocked when the switch is off.

require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ve2f2_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// require_category_manager() redirects, which an AJAX caller cannot follow.
// Answer JSON first, then keep the canonical guard as defence in depth.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Category Manager') {
    ve2f2_json(403, ['success' => false, 'message' => 'Not authorised to send Vendor Evaluation Form 2 reminders.']);
}
require_category_manager();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ve2f2_json(405, ['success' => false, 'message' => 'POST required.']);
}

require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_sender.php';          // ve2_web_base_url()
require_once '../includes/vendor_eval_v2_form2_reminder.php';
require_once '../includes/vendor_eval_v2_form2_kill_switch.php';

// Master kill switch — same one the cron reads. Checked here in addition to,
// not instead of, the Category-Manager + confirm-token checks above/below.
// Fails closed (see ve2_form2_live_send_enabled()): any DB error or missing
// table blocks the send, it never lets one through.
if (!ve2_form2_live_send_enabled($pdo)) {
    ve2f2_json(423, [
        'success' => false,
        'message' => 'Live sending is currently disabled by the Form 2 kill switch (vendor_eval_v2_form2_send_settings.live_send_enabled = 0). Nothing was sent. Ask whoever owns that switch to enable it, then retry.',
    ]);
}

try {
    $base_url = ve2_web_base_url();
} catch (RuntimeException $e) {
    ve2f2_json(500, ['success' => false, 'message' => 'Cannot build a safe worklist link: ' . $e->getMessage()]);
}

try {
    $result = ve2_form2_send_reminders($pdo, [
        'base_url'  => $base_url,
        'preview'   => false, // LIVE SEND — see file header
        'confirm'   => VE2_FORM2_SEND_CONFIRM,
        'channel'   => 'both',
        'run_label' => 'manual',
    ]);
} catch (Throwable $e) {
    error_log('[ajax_send_form2_reminder] ' . $e->getMessage());
    ve2f2_json(500, ['success' => false, 'message' => 'Reminder send failed. Check the server log — some messages may have already been sent before the failure.']);
}

ve2f2_json(200, [
    'success' => (bool)$result['ok'],
    'message' => $result['ok']
        ? sprintf(
            '%d new PO(s), %d follow-up PO(s) due. %d recipient(s) — %d email sent (%d failed), %d WA sent (%d failed).',
            count($result['due_first']), count($result['due_weekly']), $result['recipient_count'],
            $result['email_sent'], $result['email_failed'], $result['wa_sent'], $result['wa_failed']
        )
        : implode(' ', $result['errors']),
    'result' => $result,
]);
