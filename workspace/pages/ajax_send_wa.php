<?php
// pages/ajax_send_wa.php
//
// Vendor Evaluation v2 — manager-triggered reminder sender (web).
//
// Replaces the withdrawn per-PO blast. All grouping, message building, provider
// checking and audit logging lives in workspace/includes/vendor_eval_v2_sender.php,
// which the guarded CLI sender shares. This file is transport + authorisation only.
//
// Contract
//   POST po_numbers[]  selected PO numbers from the dashboard modal
//   POST mode          'preview' (default) | 'send'
//   POST _csrf_ve2     required for mode=send
//   POST confirm       required for mode=send, must equal VE2_WEB_SEND_CONFIRM
//
// mode=preview performs SELECTs only: no WhatsApp, no email, no DB write.
// mode=send dispatches one email + one WhatsApp per requestor and writes
// vendor_eval_v2_invite_log. A channel is reported as sent only when the
// provider confirmed it.
//
// Manager CC is OFF for the web sender (cc_manager => false) until the first
// controlled production test is signed off. The CLI sender keeps its own
// CC behaviour.

require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

/** Emit JSON and stop. Never leaks provider bodies, tokens or credentials. */
function ve2_json(int $http_code, array $payload): void
{
    http_response_code($http_code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Uniform response shape so the dashboard can render partial failures. */
function ve2_reply(bool $success, string $message, array $r = [], int $http = 200): void
{
    ve2_json($http, [
        'success'         => $success,
        'requestor_count' => (int)($r['requestor_count'] ?? 0),
        'po_count'        => (int)($r['po_count'] ?? 0),
        'email_sent'      => (int)($r['email_sent'] ?? 0),
        'email_failed'    => (int)($r['email_failed'] ?? 0),
        'wa_sent'         => (int)($r['wa_sent'] ?? 0),
        'wa_failed'       => (int)($r['wa_failed'] ?? 0),
        'skipped'         => (int)($r['skipped'] ?? 0),
        'message'         => $message,
        'details'         => [
            'preview'          => (bool)($r['preview'] ?? false),
            'run_id'           => (string)($r['run_id'] ?? ''),
            'requestors'       => $r['requestors'] ?? [],
            'skipped_pos'      => $r['skipped_pos'] ?? [],
            'warnings'         => $r['warnings'] ?? [],
            'errors'           => $r['errors'] ?? [],
        ],
    ]);
}

// ── Authorisation ────────────────────────────────────────────────────────────
// require_manager() redirects, which an AJAX caller cannot follow. Answer JSON
// first, then keep the canonical guard as defence in depth.
if (!isset($_SESSION['user_id'])
    || !in_array($_SESSION['role'] ?? '', ['Manager', 'Category Manager'], true)) {
    ve2_reply(false, 'Not authorised to send Vendor Evaluation reminders.', [], 403);
}
require_manager();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ve2_reply(false, 'POST required.', [], 405);
}

require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_sender.php';

// ── Input ────────────────────────────────────────────────────────────────────
$raw_pos = $_POST['po_numbers'] ?? [];
if (!is_array($raw_pos)) {
    $raw_pos = [$raw_pos];
}

$po_numbers = [];
foreach ($raw_pos as $po) {
    if (!is_scalar($po)) { continue; }
    $po = trim((string)$po);
    if ($po === '' || strlen($po) > 255) { continue; }
    $po_numbers[$po] = true;
}
$po_numbers = array_keys($po_numbers);

if (empty($po_numbers)) {
    ve2_reply(false, 'Select at least one PO before sending reminders.', [], 400);
}
if (count($po_numbers) > VE2_MAX_POS_PER_RUN) {
    ve2_reply(false, 'Too many POs selected (' . count($po_numbers) . ' > ' . VE2_MAX_POS_PER_RUN . '). Narrow the selection.', [], 400);
}

$mode = ($_POST['mode'] ?? 'preview') === 'send' ? 'send' : 'preview';

// ── Send-mode gates: CSRF + explicit human confirmation ──────────────────────
if ($mode === 'send') {
    if (!ve2_csrf_check(trim((string)($_POST['_csrf_ve2'] ?? '')))) {
        ve2_reply(false, 'Session expired or invalid request token. Reload the dashboard and try again.', [], 403);
    }
    if (trim((string)($_POST['confirm'] ?? '')) !== VE2_WEB_SEND_CONFIRM) {
        ve2_reply(false, 'Confirmation missing — nothing was sent.', [], 400);
    }
}

// ── Base URL ─────────────────────────────────────────────────────────────────
try {
    $base_url = ve2_web_base_url();
} catch (RuntimeException $e) {
    ve2_reply(false, 'Cannot build a safe evaluation link: ' . $e->getMessage(), [], 500);
}

// ── Run ──────────────────────────────────────────────────────────────────────
try {
    $r = ve2_send_reminders($pdo, $po_numbers, [
        'base_url'     => $base_url,
        'channel'      => 'both',
        'preview'      => ($mode === 'preview'),
        'cc_manager'   => false,   // deliberately off until the first prod test is signed off
        'sender_label' => 'web',
    ]);
} catch (Throwable $e) {
    error_log('[ajax_send_wa] ' . $e->getMessage());
    ve2_reply(false, 'Reminder sender failed. Check the server log; nothing further was sent.', [], 500);
}

if (!empty($r['errors'])) {
    ve2_reply(false, implode(' ', $r['errors']), $r, 200);
}

// ── Message ──────────────────────────────────────────────────────────────────
if ($mode === 'preview') {
    ve2_reply(true, sprintf(
        '%d requestor(s) will receive 1 email + 1 WhatsApp covering %d PO(s). %d selected PO(s) skipped.',
        $r['requestor_count'], $r['po_count'], $r['skipped']
    ), $r);
}

// Post-send message is deliberately short — the itemised counts this used to
// spell out are still in the response payload (and vendor_eval_v2_invite_log)
// for anyone who needs them; the dashboard no longer renders a stats summary.
ve2_reply(
    (bool)$r['ok'],
    $r['ok'] ? 'Done.' : 'Reminders partially failed. Check the server log for details.',
    $r
);
