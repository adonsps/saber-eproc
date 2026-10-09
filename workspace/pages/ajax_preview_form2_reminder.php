<?php
// workspace/pages/ajax_preview_form2_reminder.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail, New Store) reminder PREVIEW.
// Category-Manager only. Read-only: calls ve2_form2_send_reminders() with
// preview=true, which per that function's own contract never touches
// sendTenderEmail()/ve2_send_fonnte_wa() and writes nothing to
// vendor_eval_v2_invite_log. This file hard-codes preview=true and never
// passes 'confirm' — it cannot be turned into a live send by itself, even
// by a future edit that forgets to change something back.
//
// Sole caller: the "Send Real Reminder" button's confirm-step on
// master_vendor_evaluation.php, which calls this FIRST to show the real
// due-PO / recipient counts in a confirm() dialog before separately calling
// the LIVE endpoint, workspace/pages/ajax_send_form2_reminder.php.
//
// Added 2026-07-29 after an incident: the old single-button flow fired the
// live endpoint directly under a stale "Dry Run" label with zero
// confirmation, and a real send to the real 10-person roster was mistaken
// for a preview. See CLAUDE.md non-negotiable rule: "Never send WA/email
// unless explicitly requested."

require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ve2f2p_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// require_category_manager() redirects, which an AJAX caller cannot follow.
// Answer JSON first, then keep the canonical guard as defence in depth.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Category Manager') {
    ve2f2p_json(403, ['success' => false, 'message' => 'Not authorised to preview Vendor Evaluation Form 2 reminders.']);
}
require_category_manager();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ve2f2p_json(405, ['success' => false, 'message' => 'POST required.']);
}

require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_sender.php';          // ve2_web_base_url()
require_once '../includes/vendor_eval_v2_form2_reminder.php';

try {
    $base_url = ve2_web_base_url();
} catch (RuntimeException $e) {
    ve2f2p_json(500, ['success' => false, 'message' => 'Cannot build a safe worklist link: ' . $e->getMessage()]);
}

try {
    $result = ve2_form2_send_reminders($pdo, [
        'base_url'  => $base_url,
        'preview'   => true, // hard-coded — this endpoint is preview-only, permanently. No 'confirm' is ever passed.
        'channel'   => 'both',
        'run_label' => 'ui_preview',
    ]);
} catch (Throwable $e) {
    error_log('[ajax_preview_form2_reminder] ' . $e->getMessage());
    ve2f2p_json(500, ['success' => false, 'message' => 'Preview failed. Check the server log; nothing was sent (this endpoint never sends).']);
}

ve2f2p_json(200, [
    'success' => (bool)$result['ok'],
    'message' => $result['ok']
        ? sprintf(
            '%d new PO(s), %d follow-up PO(s) due. %d recipient(s) would be notified. PREVIEW ONLY — nothing was sent.',
            count($result['due_first']), count($result['due_weekly']), $result['recipient_count']
        )
        : implode(' ', $result['errors']),
    'result' => $result,
]);
