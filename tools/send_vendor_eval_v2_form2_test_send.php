<?php
// tools/send_vendor_eval_v2_form2_test_send.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail, New Store) reminder engine —
// TEST-RECIPIENT send.
//
// Sole purpose: fire exactly one real WhatsApp message and one real email to
// a single, hardcoded, pre-approved personal test contact, so the actual
// Fonnte / SMTP send path (ve2_send_fonnte_wa() / sendTenderEmail()) can be
// verified end-to-end BEFORE the Form 2 "Shoot Reminder" button or the daily
// cron are ever allowed to go live against the real
// vendor_eval_v2_form2_team_contacts roster (~10 people).
//
// This tool is DELIBERATELY separate from that roster and from the manual
// "Shoot Reminder" button / cron:
//   - It never reads vendor_eval_v2_form2_team_contacts.
//   - It never calls ve2_form2_send_reminders() with preview=false against
//     the real roster — it builds and sends its own single test message.
//   - It writes NOTHING to vendor_eval_v2_invite_log, so a test run can never
//     be mistaken for a real reminder in the cadence/dedupe classification
//     that ve2_form2_classify_po() runs against that table.
//   - The recipient WA/email are hardcoded constants below, and the
//     --test-wa / --test-email flags must echo them back exactly. This is
//     defence in depth against fat-fingering a real contact into this tool.
//
// Usage:
//   docker-compose exec app php tools/send_vendor_eval_v2_form2_test_send.php \
//     --test-wa=<approved test WA>          (must equal FORM2_APPROVED_TEST_WA)
//     --test-email=<approved test email>    (must equal FORM2_APPROVED_TEST_EMAIL)
//     --confirm-test-send=I_UNDERSTAND_THIS_SENDS_A_REAL_TEST_WA_AND_EMAIL
//     [--channel=wa|email|both]             default: both
//     [--base-url=URL]                      default: http://localhost:8080/workspace/pages
//     [--allow-localhost-url]               required if base URL is localhost (the local default is)
//
// SAFETY:
//   - CLI-only. Refuses web/browser access.
//   - Sends ONLY to the hardcoded approved test WA/email below — never to
//     any value read from the database.
//   - No DB writes of any kind (SELECT only, to read due Form 2 POs and the
//     worklist link so the test message is realistic).
//   - Does not touch vendor_eval_v2_form2_team_contacts, requestors, or v1
//     vendor_evaluations.

// ── CLI guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: docker-compose exec app php tools/send_vendor_eval_v2_form2_test_send.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

$tool_start = microtime(true);
$root = dirname(__DIR__);

// ── Approved test contact (hardcoded per user request — see file header) ───
// Set once, by hand, before the first real run. Left empty means "not yet
// configured" and the tool refuses to send.
define('FORM2_APPROVED_TEST_WA', '');
define('FORM2_APPROVED_TEST_EMAIL', '');

define('FORM2_TEST_SEND_CONFIRM', 'I_UNDERSTAND_THIS_SENDS_A_REAL_TEST_WA_AND_EMAIL');

// ── Argument parsing ─────────────────────────────────────────────────────────
$opts = [
    'base_url'            => 'http://localhost:8080/workspace/pages',
    'channel'             => 'both',
    'test_wa'             => null,
    'test_email'          => null,
    'confirm_test_send'   => null,
    'allow_localhost_url' => false,
];
$invalid_channel = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--base-url=(.+)$/', $arg, $m)) {
        $opts['base_url'] = rtrim($m[1], '/');
    } elseif (preg_match('/^--channel=(wa|email|both)$/', $arg, $m)) {
        $opts['channel'] = $m[1];
    } elseif (preg_match('/^--channel=(.+)$/', $arg, $m)) {
        $invalid_channel = $m[1];
    } elseif (preg_match('/^--test-wa=(.+)$/', $arg, $m)) {
        $opts['test_wa'] = trim($m[1]);
    } elseif (preg_match('/^--test-email=(.+)$/', $arg, $m)) {
        $opts['test_email'] = trim($m[1]);
    } elseif (preg_match('/^--confirm-test-send=(.+)$/', $arg, $m)) {
        $opts['confirm_test_send'] = trim($m[1]);
    } elseif ($arg === '--allow-localhost-url') {
        $opts['allow_localhost_url'] = true;
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "[WARN] Unknown option: $arg\n");
    }
}

if ($invalid_channel !== null) {
    exit("\n[ABORT] Invalid --channel='{$invalid_channel}'. Use one of: wa | email | both.\n\n");
}

echo "\n=======================================================================\n";
echo " Vendor Evaluation v2 — Form 2 Reminder — TEST-RECIPIENT SEND\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n";
echo "[WARN] REAL SEND to a hardcoded personal test contact only.\n";
echo "[WARN] The real Form 2 team roster (vendor_eval_v2_form2_team_contacts) is never read.\n";
echo "[WARN] Nothing is written to vendor_eval_v2_invite_log in this mode.\n\n";

// ── Guard: approved contact must be configured ──────────────────────────────
if (FORM2_APPROVED_TEST_WA === '' || FORM2_APPROVED_TEST_EMAIL === '') {
    exit(
        "[ABORT] FORM2_APPROVED_TEST_WA / FORM2_APPROVED_TEST_EMAIL are not configured in this file.\n"
        . "        Set them by hand to the approved personal test contact before running.\n\n"
    );
}

// ── Guard: flags must echo the hardcoded approved contact exactly ──────────
$errors = [];
if ($opts['test_wa'] !== FORM2_APPROVED_TEST_WA) {
    $errors[] = '--test-wa must be exactly ' . FORM2_APPROVED_TEST_WA . ' (got: ' . ($opts['test_wa'] ?? '(unset)') . ')';
}
if ($opts['test_email'] !== FORM2_APPROVED_TEST_EMAIL) {
    $errors[] = '--test-email must be exactly ' . FORM2_APPROVED_TEST_EMAIL . ' (got: ' . ($opts['test_email'] ?? '(unset)') . ')';
}
if ($opts['confirm_test_send'] !== FORM2_TEST_SEND_CONFIRM) {
    $errors[] = '--confirm-test-send must be exactly ' . FORM2_TEST_SEND_CONFIRM;
}
if (!empty($errors)) {
    echo "[ABORT] Guard validation failed — nothing was sent:\n";
    foreach ($errors as $e) { echo "  - {$e}\n"; }
    echo "\n";
    exit(1);
}

// ── Base URL validation ──────────────────────────────────────────────────────
$is_localhost_url = str_contains($opts['base_url'], 'localhost') || str_contains($opts['base_url'], '127.0.0.1');

require_once $root . '/workspace/includes/vendor_eval_v2_sender.php'; // ve2_base_url_errors(), ve2_mask_phone()

$url_errs = ve2_base_url_errors($opts['base_url'], false);
if (!empty($url_errs)) {
    echo "[ABORT] Base URL rejected:\n";
    foreach ($url_errs as $e) { echo "  - {$e}\n"; }
    echo "\n";
    exit(1);
}
if ($is_localhost_url && !$opts['allow_localhost_url']) {
    exit(
        "[ABORT] Base URL contains localhost and --allow-localhost-url was not passed.\n"
        . "        Add --allow-localhost-url to proceed with a local test send.\n\n"
    );
}

// ── DB connect (read-only use: due POs + worklist link, for realistic content) ──
require_once $root . '/workspace/config/database.php';
require_once $root . '/workspace/includes/vendor_eval_v2_form2_reminder.php';

$id = $pdo->query("SELECT DATABASE() AS db, USER() AS usr, @@hostname AS host, VERSION() AS ver")
          ->fetch(PDO::FETCH_ASSOC);
echo " DB   : {$id['db']}\n";
echo " User : {$id['usr']}\n\n";

$worklist_url = ve2_form2_worklist_link($pdo, $opts['base_url']);
if ($worklist_url === null) {
    exit("[ABORT] Form 2 shared worklist link has not been generated yet — generate it on the Requestor Contacts tab first.\n");
}

$due = ve2_form2_build_due_list($pdo);
$due_first  = $due['due_first'];
$due_weekly = $due['due_weekly'];

// If nothing is currently due, use one obviously-synthetic sample row so the
// test message still exercises the real template instead of rendering an
// empty PO list — this is still a REAL send, just with placeholder content.
if (empty($due_first) && empty($due_weekly)) {
    $due_first = [
        'TEST-PO-0000' => [
            'vendor_name'        => '(synthetic test vendor — not a real PO)',
            'sites'              => ['TEST SITE'],
            'store_opening_date' => date('Y-m-d'),
        ],
    ];
    echo "[NOTE] No Form 2 PO is currently due — using one synthetic sample PO so the test message is realistic.\n\n";
}

$test_name  = 'Test Recipient';
$footer     = '--- TEST SEND (Form 2 reminder engine) — DO NOT FORWARD / NOT A REAL TEAM NOTIFICATION ---';
$wa_msg     = ve2_form2_build_wa_message($test_name, $due_first, $due_weekly, $worklist_url) . "\n\n" . $footer;
[$subject, $body] = ve2_form2_build_email($test_name, $due_first, $due_weekly, $worklist_url);
$subject    = '[TEST SEND] ' . $subject;
$body       = str_replace('</body></html>', '<hr><p style="color:#cc0000;font-size:12px;font-weight:bold;">' . htmlspecialchars($footer) . '</p></body></html>', $body);

$need_email = ($opts['channel'] === 'email' || $opts['channel'] === 'both');
$need_wa    = ($opts['channel'] === 'wa'    || $opts['channel'] === 'both');

echo "=======================================================================\n";
echo " TEST-SEND EXECUTION — Channel: {$opts['channel']}\n";
echo "=======================================================================\n";

$wa_sent = false; $wa_err = ''; $em_sent = false; $em_err = ''; $send_count = 0;

if ($need_wa) {
    require_once $root . '/workspace/includes/vendor_eval_v2_fonnte.php';
    echo "Dispatching WA to " . ve2_mask_phone(FORM2_APPROVED_TEST_WA) . " ...\n";
    $wa_res = ve2_send_fonnte_wa(FORM2_APPROVED_TEST_WA, $wa_msg);
    if (is_array($wa_res) && !empty($wa_res['ok'])) {
        $wa_sent = true; $send_count++;
        echo "[OK] WA accepted by Fonnte.\n\n";
    } else {
        $wa_err = is_array($wa_res) ? (string)($wa_res['reason'] ?? 'provider did not confirm') : 'provider did not confirm';
        echo "[FAIL] WA send failed: {$wa_err}\n\n";
    }
}

if ($need_email) {
    require_once $root . '/workspace/includes/mailer.php';
    echo "Sending email to " . FORM2_APPROVED_TEST_EMAIL . " ...\n";
    $ok = sendTenderEmail(FORM2_APPROVED_TEST_EMAIL, $test_name, $subject, $body, true);
    if ($ok) {
        $em_sent = true; $send_count++;
        echo "[OK] Email sent to " . FORM2_APPROVED_TEST_EMAIL . "\n\n";
    } else {
        $em_err = 'sendTenderEmail returned false — check workspace/includes/email_error_debug.txt';
        echo "[FAIL] Email send failed: {$em_err}\n\n";
    }
}

echo "=======================================================================\n";
echo " TEST-SEND RESULT\n";
echo "=======================================================================\n";
printf(" %-40s %s\n", "WA:",    ($wa_sent ? 'SENT' : ($need_wa ? "FAILED: {$wa_err}" : 'skipped')));
printf(" %-40s %s\n", "Email:", ($em_sent ? 'SENT' : ($need_email ? "FAILED: {$em_err}" : 'skipped')));
printf(" %-40s %d (max 1 WA + 1 email)\n", "Total sends this run:", $send_count);
echo "\n[CONFIRM] No real Form 2 team contact received any message.\n";
echo "[CONFIRM] No DB writes occurred. No invite log written in test mode.\n";
echo "\nDone in " . round(microtime(true) - $tool_start, 2) . "s.\n\n";

exit(($need_wa && !$wa_sent) || ($need_email && !$em_sent) ? 1 : 0);
