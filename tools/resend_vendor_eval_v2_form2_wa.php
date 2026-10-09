<?php
// tools/resend_vendor_eval_v2_form2_wa.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail, New Store) WhatsApp-ONLY re-send (CLI).
//
// This is a THIN CLI WRAPPER around ve2_form2_resend_wa() in
// workspace/includes/vendor_eval_v2_form2_reminder.php — the single shared
// implementation also used by the browser endpoints
// (ajax_preview_resend_form2_wa.php / ajax_resend_form2_wa.php). Keeping one
// implementation means the CLI and the button can never diverge.
//
// WHY THIS EXISTS
//   A Form 2 reminder run sent email successfully to the whole team roster but
//   every WhatsApp failed (the VE2 Fonnte token was missing on the server after
//   the infra migration). Because the invite log records the email rows as
//   status='sent' for those POs, the normal 7-day cadence now classifies them
//   as "already reminded" — so the standard sender/button will NOT re-attempt
//   the WhatsApp copy for 7 days. This re-sends ONLY the WhatsApp message,
//   ignoring cadence. Email is never touched.
//
// SAFETY MODEL
//   - CLI-only. Refuses browser access.
//   - DRY-RUN is the default: no sends, no DB writes.
//   - --apply requires --confirm-db (must equal live DATABASE()) AND
//     --confirm-wa-resend=<exact token>. Optional --confirm-host is checked
//     against @@hostname only if supplied (Docker hostnames are volatile).
//   - WhatsApp only. The mailer is never loaded.
//   - Idempotent (enforced in the shared function): a recipient who already has
//     a status='sent' WhatsApp row with the SAME message body is skipped, so
//     re-running --apply never double-sends. Original FAILED rows never block.
//   - Circuit breaker: aborts if the eligible+incomplete PO count exceeds --max
//     (default VE2_FORM2_MAX_DUE_POS).
//   - Writes ONLY to the audit table vendor_eval_v2_invite_log.
//   - No secrets printed; phone numbers are masked.
//
// USAGE
//   Dry-run (default, safe):
//     php tools/resend_vendor_eval_v2_form2_wa.php --base-url=https://vssportal.online/workspace/pages
//
//   Single-recipient live test first (recommended):
//     php tools/resend_vendor_eval_v2_form2_wa.php --apply \
//       --confirm-db=u894129685_workspace \
//       --confirm-wa-resend=I_UNDERSTAND_THIS_RESENDS_REAL_WHATSAPP_TO_FORM2_TEAM \
//       --only-recipient="Ade Kusmana"
//
//   Full live re-send to the whole roster:
//     php tools/resend_vendor_eval_v2_form2_wa.php --apply \
//       --confirm-db=u894129685_workspace \
//       --confirm-wa-resend=I_UNDERSTAND_THIS_RESENDS_REAL_WHATSAPP_TO_FORM2_TEAM
//
//   On the Docker VPS, run inside the app container for PHP/extension parity:
//     docker compose -f docker-compose.prod.yml exec vms_prod_app \
//       php tools/resend_vendor_eval_v2_form2_wa.php ...

// ── CLI guard ────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only.\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from the command line.\n");
}

$root = dirname(__DIR__);

// Pure libraries — no DB connection, no output, no sends on require.
require_once $root . '/workspace/includes/vendor_eval_v2_sender.php';
require_once $root . '/workspace/includes/vendor_eval_v2_form2_reminder.php'; // ve2_form2_resend_wa(), VE2_FORM2_* constants

const EXPECTED_DB       = 'u894129685_workspace';
const WA_RESEND_CONFIRM = 'I_UNDERSTAND_THIS_RESENDS_REAL_WHATSAPP_TO_FORM2_TEAM';

// ── Argument parsing ──────────────────────────────────────────────────────────
$apply          = false;
$base_url       = 'https://vssportal.online/workspace/pages';
$confirm_db     = null;
$confirm_host   = null;   // optional; informational under Docker
$confirm_token  = null;
$only_recipient = '';     // exact contact name, for a single-recipient live test
$max_pos        = VE2_FORM2_MAX_DUE_POS;
$allow_localhost = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply')                          { $apply = true; }
    elseif ($arg === '--allow-localhost-url')        { $allow_localhost = true; }
    elseif (str_starts_with($arg, '--base-url='))    { $base_url = rtrim(substr($arg, 11), '/'); }
    elseif (str_starts_with($arg, '--confirm-db='))  { $confirm_db = substr($arg, 13); }
    elseif (str_starts_with($arg, '--confirm-host=')) { $confirm_host = substr($arg, 15); }
    elseif (str_starts_with($arg, '--confirm-wa-resend=')) { $confirm_token = substr($arg, 20); }
    elseif (str_starts_with($arg, '--only-recipient=')) { $only_recipient = trim(substr($arg, 17)); }
    elseif (str_starts_with($arg, '--max='))         { $max_pos = max(1, (int)substr($arg, 6)); }
    else { exit("Unknown argument: {$arg}\n"); }
}

echo "== Vendor Eval v2 — Form 2 WhatsApp-only re-send ==\n";
echo $apply ? "[APPLY] Live WhatsApp send mode.\n" : "[DRY RUN] No WhatsApp will be sent, no rows written.\n";
echo "Base URL : {$base_url}\n";
if ($only_recipient !== '') echo "Filter   : only recipient \"{$only_recipient}\"\n";
echo "Max POs  : {$max_pos}\n\n";

// ── Base URL validation (same rules the sender enforces) ──────────────────────
$is_localhost = str_contains($base_url, 'localhost') || str_contains($base_url, '127.0.0.1');
if ($is_localhost && !$allow_localhost) {
    exit("[ABORT] Base URL is localhost. Pass --allow-localhost-url only for local testing.\n");
}

// ── DB connect + identity ─────────────────────────────────────────────────────
require_once $root . '/workspace/config/database.php';
$id = $pdo->query("SELECT DATABASE() AS db, USER() AS usr, @@hostname AS host, VERSION() AS ver")->fetch(PDO::FETCH_ASSOC);
echo " DB   : {$id['db']}\n User : {$id['usr']}\n Host : {$id['host']}\n Ver  : {$id['ver']}\n\n";

// ── Apply-mode gates (CLI-specific, on top of the shared function's own gate) ──
if ($apply) {
    if ($id['db'] !== EXPECTED_DB || $confirm_db !== EXPECTED_DB) {
        exit("[ABORT] --confirm-db must equal the live DATABASE() ('" . EXPECTED_DB . "'). Got confirm='" . ($confirm_db ?? '(none)') . "', live='{$id['db']}'.\n");
    }
    if ($confirm_token !== WA_RESEND_CONFIRM) {
        exit("[ABORT] --confirm-wa-resend token missing or wrong. Nothing sent.\n");
    }
    if ($confirm_host !== null && $confirm_host !== (string)$id['host']) {
        exit("[ABORT] --confirm-host '{$confirm_host}' != live @@hostname '{$id['host']}'.\n");
    }
}

// ── Delegate to the single shared implementation ──────────────────────────────
$res = ve2_form2_resend_wa($pdo, [
    'base_url'       => $base_url,
    'preview'        => !$apply,
    'confirm'        => $apply ? VE2_FORM2_SEND_CONFIRM : '',
    'max_pos'        => $max_pos,
    'only_recipient' => $only_recipient,
    'run_label'      => 'wa_resend_cli',
]);

if (!empty($res['errors'])) {
    exit("[ABORT] " . implode(' ', $res['errors']) . "\n");
}

echo "Worklist : {$res['worklist_url']}\n";
echo "Eligible+incomplete POs referenced: {$res['po_count']}\n";
if ($res['run_id'] !== '') echo "Run ID   : {$res['run_id']}\n";
echo "\n";

foreach ($res['recipients'] as $rec) {
    $line = "  - {$rec['name']} ({$rec['wa']}): " . strtoupper($rec['status']);
    if (!empty($rec['error'])) $line .= " — {$rec['error']}";
    echo $line . "\n";
}

echo "\n== Summary ==\n";
if ($apply) {
    echo "WhatsApp sent   : {$res['wa_sent']}\n";
    echo "WhatsApp failed : {$res['wa_failed']}\n";
    echo "Skipped         : {$res['wa_skipped']}\n";
    echo ($res['wa_failed'] === 0 ? "OK — done.\n" : "Some sends failed — inspect " . VE2_INVITE_LOG_TABLE . ".error_message before retrying.\n");
} else {
    echo "Would send      : {$res['would_send']}\n";
    echo "Skipped         : {$res['wa_skipped']}\n";
    echo "[DRY RUN] Re-run with --apply (+ --confirm-db and --confirm-wa-resend) to send.\n";
}
