<?php
// workspace/pages/ajax_preview_resend_form2_wa.php
//
// Vendor Evaluation v2 — Form 2 WhatsApp-only RE-SEND preview. Category-Manager
// only. Read-only: calls ve2_form2_resend_wa() with preview=true, which per its
// contract never touches ve2_send_fonnte_wa() and writes nothing. This file
// hard-codes preview=true and never passes 'confirm' — it cannot be turned into
// a live send by itself.
//
// Sole caller: the "Re-send failed WhatsApp" button's confirm-step on
// master_vendor_evaluation.php, which calls this FIRST to show the real
// recipient / PO counts in the confirmation modal before separately calling the
// LIVE endpoint, ajax_resend_form2_wa.php.

require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function ve2wap_json(int $code, array $payload): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'Category Manager') {
    ve2wap_json(403, ['success' => false, 'message' => 'Not authorised to preview Vendor Evaluation Form 2 WhatsApp re-sends.']);
}
require_category_manager();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ve2wap_json(405, ['success' => false, 'message' => 'POST required.']);
}

require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_sender.php';          // ve2_web_base_url()
require_once '../includes/vendor_eval_v2_form2_reminder.php';

try {
    $base_url = ve2_web_base_url();
} catch (RuntimeException $e) {
    ve2wap_json(500, ['success' => false, 'message' => 'Cannot build a safe worklist link: ' . $e->getMessage()]);
}

try {
    $result = ve2_form2_resend_wa($pdo, [
        'base_url'  => $base_url,
        'preview'   => true, // hard-coded — preview only, permanently. No 'confirm' is ever passed.
        'run_label' => 'wa_resend_ui_preview',
    ]);
} catch (Throwable $e) {
    error_log('[ajax_preview_resend_form2_wa] ' . $e->getMessage());
    ve2wap_json(500, ['success' => false, 'message' => 'Preview failed. Check the server log; nothing was sent (this endpoint never sends).']);
}

ve2wap_json(200, [
    'success' => (bool)$result['ok'],
    'message' => $result['ok']
        ? sprintf(
            '%d recipient(s) would get a WhatsApp about %d PO(s); %d already have it and would be skipped. PREVIEW ONLY — nothing was sent.',
            $result['would_send'], $result['po_count'], $result['wa_skipped']
        )
        : implode(' ', $result['errors']),
    'result' => $result,
]);
