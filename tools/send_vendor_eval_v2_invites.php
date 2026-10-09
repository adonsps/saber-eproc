<?php
// tools/send_vendor_eval_v2_invites.php
//
// Vendor Evaluation v2 — guarded invite sender.
//
// Three modes:
//   1. DRY-RUN (default)      — SELECT only, no sends, writes a preview CSV.
//   2. TEST-SEND (--test-send)— sends ONLY to the approved test WA/email.
//   3. PRODUCTION (--send)    — sends to REAL requestors, behind heavy guards.
//
// Candidate readiness (per pending PO invite) is resolved the same way the
// public form resolves a question set (L3 -> L2 -> default) so "form not
// configured" candidates are detected and BLOCKED. Sends are grouped per
// requestor (one WA + one email covering all that requestor's pending POs).
//
// Usage:
//   C:\xampp\php\php.exe tools/send_vendor_eval_v2_invites.php [OPTIONS]
//
// Common options:
//   --base-url=URL                Base URL for token links
//                                 (default: http://localhost/workspace/pages)
//   --out-dir=PATH                Dry-run CSV dir (default: db_backups/local_runtime_exports)
//   --channel=wa|email|both       Channels (default: both). Invalid values are refused.
//
// Pilot / scope filters (apply in dry-run AND production):
//   --only-requestor="NAME"       Only this exact requestor's pending batch (0 match => abort)
//   --limit-requestors=N          Only first N distinct requestors after sort (N>=1)
//
// Dry-run options:
//   --allow-localhost-url         Allow localhost base URL in dry-run/test
//   --expected-ready-count=N      Report whether filtered ready+caveat count matches N
//   --allow-placeholder-wa=62817209700  Acknowledge accepted placeholder-WA caveat
//
// Guarded TEST-SEND (all four required together):
//   --test-send
//   --requestor="NAME"            Exactly one requestor
//   --test-wa=62817209700         Must equal the approved test WA
//   --test-email=fsthicc@gmail.com  Must equal the approved test email
//   --confirm-test-send=I_UNDERSTAND_SENDS_TO_MY_TEST_CONTACTS
//   --allow-localhost-url         Required if base URL is localhost
//   --allow-local-test-contacts   Required if the requestor has placeholder contacts
//
// Guarded PRODUCTION SEND (--send) — ALL required:
//   --send
//   --base-url=https://vssportal.online/vms/workspace/pages  (no localhost, not *.php, no dashboard_mgr.php)
//   --confirm-db=u894129685_workspace       Must equal live DATABASE()
//   --confirm-host=<live @@hostname>         Must equal live @@hostname
//   --confirm-production-send=I_UNDERSTAND_THIS_SENDS_REAL_VENDOR_EVAL_INVITES
//   --expected-ready-count=N                 Must equal live (filtered) ready+caveat count
//   --allow-placeholder-wa=62817209700       Required if a WA channel has any placeholder-WA candidate
//   (--only-requestor / --limit-requestors optional pilot filters)
//
// SAFETY:
//   - CLI-only. Refuses web/browser access.
//   - DRY-RUN and TEST-SEND perform NO DB writes (SELECT only).
//   - PRODUCTION SEND writes ONLY to the audit table vendor_eval_v2_invite_log
//     (one row per requestor+channel, idempotent on idempotency_key). It never
//     writes requestors, users, question data, or v1 vendor_evaluations.
//   - Production send refuses if vendor_eval_v2_invite_log is missing.
//   - Email CC to manager_email applies ONLY in production (never in test-send).
//   - No Gemini/external API calls other than WA/email dispatch in send modes.
//   - Does NOT touch vendor_evaluations (v1).

// ── CLI guard ──────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/send_vendor_eval_v2_invites.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

$tool_start = microtime(true);
$run_id     = date('Ymd_His_') . bin2hex(random_bytes(4));

// ── Bootstrap ──────────────────────────────────────────────────────────────────
$_SERVER['REQUEST_URI'] = '';
if (!defined('RUNNING_AS_CLI_TOOL')) {
    define('RUNNING_AS_CLI_TOOL', true);
}
$root = dirname(__DIR__);

// Shared v2 sender library: taxonomy routing, message builders, URL rules and
// invite-log helpers. Pure functions — including it opens no DB connection and
// sends nothing. workspace/pages/ajax_send_wa.php includes the same file, so the
// CLI and the web sender produce identical messages and links.
require_once $root . '/workspace/includes/vendor_eval_v2_sender.php';

// ── Constants ──────────────────────────────────────────────────────────────────
// VE2_SENTINEL_ANY / VE2_INVITE_LOG_TABLE / VE2_PLACEHOLDER_WA now come from the
// shared library. The test/production guard tokens below stay CLI-only.
if (!defined('LOCAL_TEST_WA'))      { define('LOCAL_TEST_WA', '62817209700'); }
if (!defined('LOCAL_TEST_MANAGER')) { define('LOCAL_TEST_MANAGER', 'fsthicc@gmail.com'); }

define('ALLOWED_TEST_WA',    '62817209700');
define('ALLOWED_TEST_EMAIL', 'fsthicc@gmail.com');
define('TEST_SEND_CONFIRM',  'I_UNDERSTAND_SENDS_TO_MY_TEST_CONTACTS');

define('PROD_SEND_CONFIRM',  'I_UNDERSTAND_THIS_SENDS_REAL_VENDOR_EVAL_INVITES');
define('EXPECTED_DB',        'u894129685_workspace');

// ── Argument parsing ───────────────────────────────────────────────────────────
$opts = [
    'base_url'                  => 'http://localhost/workspace/pages',
    'out_dir'                   => $root . '/db_backups/local_runtime_exports',
    'limit_requestors'          => null,
    'only_requestor'            => null,
    'requestor'                 => null,
    'channel'                   => 'both',
    'allow_local_test_contacts' => false,
    'allow_localhost_url'       => false,
    // test-send
    'test_send'                 => false,
    'test_wa'                   => null,
    'test_email'                => null,
    'confirm_test_send'         => null,
    // production send
    'send'                      => false,
    'confirm_db'                => null,
    'confirm_host'              => null,
    'confirm_production_send'   => null,
    'expected_ready_count'      => null,
    'allow_placeholder_wa'      => null,
];
$invalid_channel = null;

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--base-url=(.+)$/', $arg, $m)) {
        $opts['base_url'] = rtrim($m[1], '/');
    } elseif (preg_match('/^--out-dir=(.+)$/', $arg, $m)) {
        $opts['out_dir'] = $m[1];
    } elseif (preg_match('/^--limit-requestors=(-?\d+)$/', $arg, $m)) {
        $opts['limit_requestors'] = (int)$m[1];
    } elseif (preg_match('/^--only-requestor=(.+)$/', $arg, $m)) {
        $opts['only_requestor'] = trim($m[1], '"\'');
    } elseif (preg_match('/^--requestor=(.+)$/', $arg, $m)) {
        $opts['requestor'] = trim($m[1], '"\'');
    } elseif (preg_match('/^--channel=(wa|email|both)$/', $arg, $m)) {
        $opts['channel'] = $m[1];
    } elseif (preg_match('/^--channel=(.+)$/', $arg, $m)) {
        $invalid_channel = $m[1];
    } elseif ($arg === '--allow-local-test-contacts') {
        $opts['allow_local_test_contacts'] = true;
    } elseif ($arg === '--allow-localhost-url') {
        $opts['allow_localhost_url'] = true;
    } elseif ($arg === '--test-send') {
        $opts['test_send'] = true;
    } elseif (preg_match('/^--test-wa=(.+)$/', $arg, $m)) {
        $opts['test_wa'] = trim($m[1]);
    } elseif (preg_match('/^--test-email=(.+)$/', $arg, $m)) {
        $opts['test_email'] = trim($m[1]);
    } elseif (preg_match('/^--confirm-test-send=(.+)$/', $arg, $m)) {
        $opts['confirm_test_send'] = trim($m[1]);
    } elseif ($arg === '--send' || str_starts_with($arg, '--send=')) {
        $opts['send'] = true;
    } elseif (preg_match('/^--confirm-db=(.+)$/', $arg, $m)) {
        $opts['confirm_db'] = trim($m[1]);
    } elseif (preg_match('/^--confirm-host=(.+)$/', $arg, $m)) {
        $opts['confirm_host'] = trim($m[1]);
    } elseif (preg_match('/^--confirm-production-send=(.+)$/', $arg, $m)) {
        $opts['confirm_production_send'] = trim($m[1]);
    } elseif (preg_match('/^--expected-ready-count=(\d+)$/', $arg, $m)) {
        $opts['expected_ready_count'] = (int)$m[1];
    } elseif (preg_match('/^--allow-placeholder-wa=(.+)$/', $arg, $m)) {
        $opts['allow_placeholder_wa'] = trim($m[1]);
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "[WARN] Unknown option: $arg\n");
    }
}

// Invalid --channel value is refused.
if ($invalid_channel !== null) {
    exit("\n[ABORT] Invalid --channel='{$invalid_channel}'. Use one of: wa | email | both.\n\n");
}
// --limit-requestors must be >= 1 whenever provided.
if ($opts['limit_requestors'] !== null && $opts['limit_requestors'] < 1) {
    exit("\n[ABORT] --limit-requestors must be >= 1 (got {$opts['limit_requestors']}).\n\n");
}

// ── Mode determination ─────────────────────────────────────────────────────────
if ($opts['send'] && $opts['test_send']) {
    exit("\n[ABORT] Cannot combine --send (production) with --test-send. Choose one.\n\n");
}
$mode = $opts['send'] ? 'production' : ($opts['test_send'] ? 'test' : 'dryrun');

// Production must not be mixed with test-send flags or the test-send selector.
// (Pilot filters --only-requestor / --limit-requestors ARE allowed in production.)
if ($mode === 'production') {
    $mix = [];
    if ($opts['test_wa'] !== null || $opts['test_email'] !== null || $opts['confirm_test_send'] !== null) {
        $mix[] = "test-send flags (--test-wa/--test-email/--confirm-test-send) must not be used with --send";
    }
    if ($opts['requestor'] !== null) {
        $mix[] = "--requestor is the test-send selector; use --only-requestor for a production pilot";
    }
    if (!empty($mix)) {
        echo "\n[ABORT] Production send flag conflict — nothing was sent:\n";
        foreach ($mix as $e) { echo "  - {$e}\n"; }
        echo "\n";
        exit(1);
    }
}

// ── Banner ─────────────────────────────────────────────────────────────────────
$filters_desc = [];
if ($opts['only_requestor'] !== null)   { $filters_desc[] = "only-requestor=\"{$opts['only_requestor']}\""; }
if ($opts['limit_requestors'] !== null) { $filters_desc[] = "limit-requestors={$opts['limit_requestors']}"; }
$filters_str = empty($filters_desc) ? 'none' : implode(', ', $filters_desc);

echo "\n=======================================================================\n";
echo " Vendor Evaluation v2 — Invite Sender  [MODE: " . strtoupper($mode) . "]\n";
echo " " . date('Y-m-d H:i:s') . "   Run ID : {$run_id}\n";
echo "=======================================================================\n";
echo " Base URL             : {$opts['base_url']}\n";
echo " Channel              : {$opts['channel']}\n";
echo " Filters              : {$filters_str}\n";
if ($mode === 'dryrun')  { echo " Out dir              : {$opts['out_dir']}\n"; }
if ($mode === 'test')    { echo " Test-send WA/email   : " . ($opts['test_wa'] ?? '(unset)') . " / " . ($opts['test_email'] ?? '(unset)') . "\n"; }
echo "\n";
if ($mode === 'dryrun')     { echo "[DRY RUN ONLY — NO WA OR EMAIL WILL BE SENT]\n\n"; }
elseif ($mode === 'test')   { echo "[TEST-SEND MODE — SENDS TO APPROVED TEST CONTACTS ONLY]\n\n"; }
else                        { echo "[PRODUCTION SEND MODE — WILL SEND TO REAL REQUESTORS IF ALL GUARDS PASS]\n\n"; }

// ── Base URL validation ────────────────────────────────────────────────────────
$is_localhost_url = str_contains($opts['base_url'], 'localhost') || str_contains($opts['base_url'], '127.0.0.1');
$url_errs = ve2_base_url_errors($opts['base_url'], $mode === 'production');
if (!empty($url_errs)) {
    echo "[ABORT] Base URL rejected:\n";
    foreach ($url_errs as $e) { echo "  - {$e}\n"; }
    echo "  Expected links look like: https://vssportal.online/vms/workspace/pages/public_eval_v2_worklist.php?token=<token>\n\n";
    exit(1);
}
// Dry-run / test still gate localhost behind --allow-localhost-url (production already rejected it above).
if ($mode !== 'production' && $is_localhost_url && !$opts['allow_localhost_url']) {
    exit(
        "[ABORT] Base URL contains localhost and --allow-localhost-url was not passed.\n"
        . "        Add --allow-localhost-url for local testing, or pass a production --base-url.\n\n"
    );
}

// ── Test-send guard pre-validation (unchanged behavior) ────────────────────────
if ($mode === 'test') {
    $ts_errors = [];
    if ($opts['requestor'] === null) {
        $ts_errors[] = "--requestor is required for --test-send (target exactly one requestor)";
    }
    if ($opts['test_wa'] !== ALLOWED_TEST_WA) {
        $ts_errors[] = "--test-wa must be exactly " . ALLOWED_TEST_WA . " (got: " . ($opts['test_wa'] ?? '(unset)') . ")";
    }
    if ($opts['test_email'] !== ALLOWED_TEST_EMAIL) {
        $ts_errors[] = "--test-email must be exactly " . ALLOWED_TEST_EMAIL . " (got: " . ($opts['test_email'] ?? '(unset)') . ")";
    }
    if ($opts['confirm_test_send'] !== TEST_SEND_CONFIRM) {
        $ts_errors[] = "--confirm-test-send must be exactly " . TEST_SEND_CONFIRM;
    }
    if (!empty($ts_errors)) {
        echo "[ABORT] --test-send validation failed — no message was sent:\n";
        foreach ($ts_errors as $e) { echo "  - {$e}\n"; }
        echo "\nProvide all four required guards to proceed.\n\n";
        exit(1);
    }
    echo "[TEST-SEND] All guards validated. Recipients: WA=" . ALLOWED_TEST_WA . " | Email=" . ALLOWED_TEST_EMAIL . "\n\n";
}

// ── DB connect + identity ──────────────────────────────────────────────────────
require_once $root . '/workspace/config/database.php';

$id = $pdo->query("SELECT DATABASE() AS db, USER() AS usr, @@hostname AS host, VERSION() AS ver")
          ->fetch(PDO::FETCH_ASSOC);
echo " DB   : {$id['db']}\n";
echo " User : {$id['usr']}\n";
echo " Host : {$id['host']}\n";
echo " Ver  : {$id['ver']}\n\n";

if ($id['db'] !== EXPECTED_DB) {
    exit("[ABORT] Unexpected database '{$id['db']}'. Expected " . EXPECTED_DB . ".\n");
}

// ── Invite-log table presence (read-only; gates production send) ────────────────
$invite_log_exists = count(
    $pdo->query("SHOW TABLES LIKE " . $pdo->quote(VE2_INVITE_LOG_TABLE))->fetchAll(PDO::FETCH_NUM)
) > 0;

// ── Load taxonomy -> question-set routing map (active only) ─────────────────────
$map_rows = $pdo->query("
    SELECT qs.id, qs.set_code, qs.set_name, m.map_level, m.taxonomy_level_2, m.taxonomy_level_3
      FROM vendor_eval_question_set_taxonomy_map m
      JOIN vendor_eval_question_sets qs ON qs.id = m.question_set_id
     WHERE qs.is_active = 1 AND m.is_active = 1
")->fetchAll(PDO::FETCH_ASSOC);

$l3map = [];
$l2map = [];
$default_set = null;
foreach ($map_rows as $mm) {
    if ($mm['map_level'] === 'l3') {
        $l3map[$mm['taxonomy_level_2'] . '||' . $mm['taxonomy_level_3']] = $mm;
    } elseif ($mm['map_level'] === 'l2' && $mm['taxonomy_level_3'] === VE2_SENTINEL_ANY) {
        $l2map[$mm['taxonomy_level_2']] = $mm;
    } elseif ($mm['map_level'] === 'default') {
        $default_set = $mm;
    }
}
$overrides = ve2_load_overrides($pdo);   // manual override wins over auto routing

// ── Excluded-candidate counts (submitted / needs_review) for reporting ─────────
$excl = $pdo->query("
    SELECT
      SUM(CASE WHEN t.status='mapped' AND EXISTS (
             SELECT 1 FROM vendor_eval_submissions s
              WHERE s.po_number=t.po_number AND s.requestor_name=r.requestor_name AND s.status='submitted'
          ) THEN 1 ELSE 0 END) AS submitted_excluded,
      SUM(CASE WHEN t.status='needs_review' THEN 1 ELSE 0 END) AS needs_review_excluded
    FROM requestors r
    JOIN vendor_eval_po_taxonomy t
      ON JSON_SEARCH(t.requestor_names_json,'one',r.requestor_name) IS NOT NULL
    WHERE r.public_token IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);
$submitted_excluded    = (int)($excl['submitted_excluded'] ?? 0);
$needs_review_excluded = (int)($excl['needs_review_excluded'] ?? 0);

// ── Load pending candidates (one row per pending (requestor, PO)) ───────────────
$cand_rows = $pdo->query("
    SELECT r.requestor_name, r.email, r.whatsapp_number, r.manager_email, r.public_token,
           t.po_number, t.vendor_name, t.taxonomy_level_2, t.taxonomy_level_3, t.mapped_at
      FROM requestors r
      JOIN vendor_eval_po_taxonomy t
        ON JSON_SEARCH(t.requestor_names_json,'one',r.requestor_name) IS NOT NULL
     WHERE r.public_token IS NOT NULL
       AND t.status='mapped'
       AND NOT EXISTS (
           SELECT 1 FROM vendor_eval_submissions s
            WHERE s.po_number=t.po_number AND s.requestor_name=r.requestor_name AND s.status='submitted'
       )
     ORDER BY r.requestor_name, t.mapped_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Scope filters ──────────────────────────────────────────────────────────────
if ($mode === 'test') {
    // Test-send targets exactly one requestor via --requestor (validated above).
    $needle = strtoupper(trim((string)$opts['requestor']));
    $cand_rows = array_values(array_filter($cand_rows, fn($c) => strtoupper(trim($c['requestor_name'])) === $needle));
    if (empty($cand_rows)) {
        exit("[ABORT] Requestor not found or has no pending candidates: \"{$opts['requestor']}\"\n");
    }
} else {
    // Pilot filters apply to BOTH dry-run and production.
    if ($opts['only_requestor'] !== null) {
        $needle = strtoupper(trim($opts['only_requestor']));
        $cand_rows = array_values(array_filter($cand_rows, fn($c) => strtoupper(trim($c['requestor_name'])) === $needle));
        if (empty($cand_rows)) {
            exit("[ABORT] --only-requestor matched 0 requestors: \"{$opts['only_requestor']}\"\n");
        }
    }
    if ($opts['limit_requestors'] !== null) {
        $keep = [];
        foreach ($cand_rows as $c) {
            $keep[$c['requestor_name']] = true;
            if (count($keep) >= $opts['limit_requestors']) { break; }
        }
        $cand_rows = array_values(array_filter($cand_rows, fn($c) => isset($keep[$c['requestor_name']])));
    }
}

// ── Classify each candidate ────────────────────────────────────────────────────
$candidates = [];
foreach ($cand_rows as $c) {
    $email = (string)($c['email'] ?? '');
    $wa    = (string)($c['whatsapp_number'] ?? '');
    $mgr   = (string)($c['manager_email'] ?? '');
    $token = (string)($c['public_token'] ?? '');

    $set = $overrides[(string)$c['po_number']]
        ?? ve2_resolve_set((string)$c['taxonomy_level_2'], $c['taxonomy_level_3'], $l3map, $l2map, $default_set);

    $blocked = [];
    if ($email === '') { $blocked[] = 'missing email'; }
    if ($wa    === '') { $blocked[] = 'missing WA'; }
    if ($mgr   === '') { $blocked[] = 'missing manager_email'; }
    if ($token === '') { $blocked[] = 'missing public_token'; }
    if ($set === null) { $blocked[] = 'form not configured'; }

    $caveats = [];
    if (empty($blocked) && $wa === VE2_PLACEHOLDER_WA) { $caveats[] = 'placeholder WA'; }

    if (!empty($blocked))      { $class = 'BLOCKED'; }
    elseif (!empty($caveats))  { $class = 'READY_WITH_CAVEATS'; }
    else                       { $class = 'READY'; }

    $candidates[] = [
        'requestor_name'   => (string)$c['requestor_name'],
        'email'            => $email,
        'whatsapp_number'  => $wa,
        'manager_email'    => $mgr,
        'public_token'     => $token,
        'po_number'        => (string)$c['po_number'],
        'vendor_name'      => (string)($c['vendor_name'] ?? ''),
        'taxonomy_level_2' => (string)($c['taxonomy_level_2'] ?? ''),
        'taxonomy_level_3' => (string)($c['taxonomy_level_3'] ?? ''),
        'set_code'         => $set['set_code'] ?? '',
        'set_name'         => $set['set_name'] ?? '',
        'map_level'        => $set['map_level'] ?? '',
        'classification'   => $class,
        'blocked_reasons'  => implode('; ', $blocked),
        'caveats'          => implode('; ', $caveats),
        'worklist_url'     => ve2_worklist_url($opts['base_url'], $token),
    ];
}

// ── Aggregate counts (reflect filters) ─────────────────────────────────────────
$total_candidates = count($candidates);
$ready_clean = 0; $ready_caveat = 0; $blocked_count = 0; $placeholder_wa_count = 0;
$blocked_reason_tally = [];
foreach ($candidates as $c) {
    if ($c['classification'] === 'READY')                    { $ready_clean++; }
    elseif ($c['classification'] === 'READY_WITH_CAVEATS')   { $ready_caveat++; }
    else {
        $blocked_count++;
        foreach (explode('; ', $c['blocked_reasons']) as $br) { $blocked_reason_tally[$br] = ($blocked_reason_tally[$br] ?? 0) + 1; }
    }
    if ($c['whatsapp_number'] === VE2_PLACEHOLDER_WA && $c['classification'] !== 'BLOCKED') { $placeholder_wa_count++; }
}
$ready_total = $ready_clean + $ready_caveat;
$wa_in_channel = ($opts['channel'] === 'wa' || $opts['channel'] === 'both');

// Group ready+caveat candidates by requestor -> send plan.
$plan = [];  // requestor_name => contact + pos[]
foreach ($candidates as $c) {
    if ($c['classification'] === 'BLOCKED') { continue; }
    $n = $c['requestor_name'];
    if (!isset($plan[$n])) {
        $plan[$n] = [
            'requestor_name'  => $n,
            'email'           => $c['email'],
            'whatsapp_number' => $c['whatsapp_number'],
            'manager_email'   => $c['manager_email'],
            'public_token'    => $c['public_token'],
            'worklist_url'    => $c['worklist_url'],
            'pos'             => [],
        ];
    }
    $plan[$n]['pos'][] = [
        'po_number'        => $c['po_number'],
        'vendor_name'      => $c['vendor_name'],
        'taxonomy_level_2' => $c['taxonomy_level_2'],
        'taxonomy_level_3' => $c['taxonomy_level_3'],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════════
// TEST-SEND PATH (approved test contacts only — unchanged guarantees, no CC, no log)
// ═══════════════════════════════════════════════════════════════════════════════
if ($mode === 'test') {
    if (count($plan) > 1) {
        exit("[ABORT] --test-send resolved more than one requestor. Narrow with --requestor.\n");
    }
    if (empty($plan)) {
        exit("[ABORT] No sendable (ready) candidate for requestor \"{$opts['requestor']}\".\n");
    }
    $req  = reset($plan);
    $name = $req['requestor_name'];

    // Local test contact gate (unchanged)
    if (!$opts['allow_local_test_contacts']) {
        $hits = [];
        if ($req['whatsapp_number'] === LOCAL_TEST_WA)      { $hits[] = "local test WA " . LOCAL_TEST_WA; }
        if ($req['manager_email']   === LOCAL_TEST_MANAGER) { $hits[] = "local test manager email " . LOCAL_TEST_MANAGER; }
        if (!empty($hits)) {
            echo "[ABORT] Local test placeholder contacts detected: " . implode(', ', $hits) . "\n";
            echo "        Add --allow-local-test-contacts to continue with placeholder data.\n\n";
            exit(1);
        }
    }

    $pending = count($req['pos']);
    $po_nums = array_column($req['pos'], 'po_number');
    sort($po_nums);
    $footer  = "--- LOCAL TEST SEND — DO NOT FORWARD / NOT A REAL REQUESTOR BLAST ---";
    $wa_msg  = ve2_build_wa_message($name, $pending, $po_nums, $req['worklist_url'], ['footer' => $footer]);
    [$subject, $body] = ve2_build_email($name, $pending, $req['pos'], $req['worklist_url'], [
        'footer'     => $footer,
        'is_test'    => true,
        'orig_email' => $req['email'],
        'mgr_mail'   => $req['manager_email'],
        'test_email' => ALLOWED_TEST_EMAIL,
    ]);

    echo "=======================================================================\n";
    echo " TEST-SEND EXECUTION — Requestor: {$name} | Channel: {$opts['channel']}\n";
    echo "=======================================================================\n";
    echo "[WARN] REAL SEND to test contacts only. Requestor's real contacts are NOT recipients.\n";
    echo "[WARN] No manager CC in test mode. No production invite log written.\n\n";

    $wa_sent = false; $em_sent = false; $em_err = ''; $wa_err = ''; $send_count = 0;

    if ($opts['channel'] === 'wa' || $opts['channel'] === 'both') {
        require_once $root . '/workspace/includes/fonnte.php';
        echo "Dispatching WA to " . ALLOWED_TEST_WA . " ... (requestor real WA: {$req['whatsapp_number']})\n";
        $wa_res = sendFonnteWA(ALLOWED_TEST_WA, $wa_msg);
        if (is_array($wa_res) && !empty($wa_res['ok'])) {
            $wa_sent = true; $send_count++;
            echo "[OK] WA accepted by Fonnte (target: " . ALLOWED_TEST_WA . ")\n\n";
        } else {
            $wa_err = is_array($wa_res) ? (string)($wa_res['reason'] ?? 'provider did not confirm') : 'provider did not confirm';
            echo "[FAIL] WA send failed: {$wa_err}\n\n";
        }
    }
    if ($opts['channel'] === 'email' || $opts['channel'] === 'both') {
        require_once $root . '/workspace/includes/mailer.php';
        echo "Sending email to " . ALLOWED_TEST_EMAIL . " ... (requestor real email: {$req['email']})\n";
        // No CC in test mode (6th arg omitted).
        $result = sendTenderEmail(ALLOWED_TEST_EMAIL, 'Test Recipient', $subject . ' [TEST]', $body, true);
        if ($result) { $em_sent = true; $send_count++; echo "[OK] Email sent to " . ALLOWED_TEST_EMAIL . "\n\n"; }
        else { $em_err = 'sendTenderEmail returned false — check workspace/includes/email_error_debug.txt'; echo "[FAIL] Email send failed: {$em_err}\n\n"; }
    }

    echo "=======================================================================\n";
    echo " TEST-SEND RESULT\n";
    echo "=======================================================================\n";
    printf(" %-40s %s\n", "WA  -> " . ALLOWED_TEST_WA . ":",    ($wa_sent ? 'SENT' : ($wa_err ? "FAILED: {$wa_err}" : 'skipped')));
    printf(" %-40s %s\n", "Email -> " . ALLOWED_TEST_EMAIL . ":", ($em_sent ? 'SENT' : ($em_err ? "FAILED: {$em_err}" : 'skipped')));
    printf(" %-40s %d (max 1 WA + 1 email)\n", "Total sends this run:", $send_count);
    echo "\n[CONFIRM] No real requestors received any message.\n";
    echo "[CONFIRM] No DB writes occurred. No invite log written in test mode.\n";
    echo "\nDone in " . round(microtime(true) - $tool_start, 2) . "s.\n\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════════════
// PRODUCTION SEND PATH (implemented; runs ONLY when every guard passes)
// ═══════════════════════════════════════════════════════════════════════════════
if ($mode === 'production') {
    echo "Validating production guards...\n";
    $errs = [];

    if ($opts['confirm_production_send'] !== PROD_SEND_CONFIRM) {
        $errs[] = "--confirm-production-send must be exactly " . PROD_SEND_CONFIRM;
    }
    if ($opts['confirm_db'] === null || $opts['confirm_db'] !== $id['db']) {
        $errs[] = "--confirm-db must equal live DATABASE() ('{$id['db']}')";
    }
    if ($opts['confirm_host'] === null || $opts['confirm_host'] !== $id['host']) {
        $errs[] = "--confirm-host must equal live @@hostname ('{$id['host']}')";
    }
    if ($opts['expected_ready_count'] === null) {
        $errs[] = "--expected-ready-count is required and must equal live ready+caveat ({$ready_total})";
    } elseif ($opts['expected_ready_count'] !== $ready_total) {
        $errs[] = "--expected-ready-count mismatch: passed {$opts['expected_ready_count']}, live ready+caveat is {$ready_total}";
    }
    if ($blocked_count > 0) {
        $errs[] = "{$blocked_count} BLOCKED candidate(s) exist — resolve or exclude them before sending";
    }
    if ($wa_in_channel && $placeholder_wa_count > 0 && $opts['allow_placeholder_wa'] !== VE2_PLACEHOLDER_WA) {
        $errs[] = "{$placeholder_wa_count} candidate(s) use placeholder WA " . VE2_PLACEHOLDER_WA
                . " — pass --allow-placeholder-wa=" . VE2_PLACEHOLDER_WA . " to acknowledge, or fix the numbers";
    }
    if (!$invite_log_exists) {
        $errs[] = VE2_INVITE_LOG_TABLE . " table missing. Apply migration before production send.";
    }
    if (empty($plan)) {
        $errs[] = "no sendable requestors after filters";
    }

    if (!empty($errs)) {
        echo "\n[ABORT] Production send refused — NOTHING was sent:\n";
        foreach ($errs as $e) { echo "  - {$e}\n"; }
        echo "\n";
        exit(1);
    }

    require_once $root . '/workspace/includes/fonnte.php';
    require_once $root . '/workspace/includes/mailer.php';

    // Detect whether the mailer supports the optional CC parameter.
    $cc_supported = false;
    if (function_exists('sendTenderEmail')) {
        $rf = new ReflectionFunction('sendTenderEmail');
        foreach ($rf->getParameters() as $p) {
            if ($p->getName() === 'cc') { $cc_supported = true; break; }
        }
    }

    echo "[OK] All production guards satisfied.\n";
    echo "     Recipients: " . count($plan) . " requestors | {$ready_total} PO candidates ({$ready_clean} clean + {$ready_caveat} caveat)\n";
    echo "     Manager CC : " . ($cc_supported ? 'ENABLED (email To=requestor, CC=manager_email)' : 'DISABLED (mailer lacks CC param)') . "\n";
    echo "     Audit log  : {$id['db']}." . VE2_INVITE_LOG_TABLE . " (run_id={$run_id})\n";
    if ($wa_in_channel && $placeholder_wa_count > 0) {
        echo "     [NOTE] {$placeholder_wa_count} candidate(s) will send WA to placeholder " . VE2_PLACEHOLDER_WA . " (accepted via flag).\n";
    }
    echo "\n";

    // Prepared statements for idempotent audit logging.
    $log_check = $pdo->prepare("SELECT status FROM " . VE2_INVITE_LOG_TABLE . " WHERE idempotency_key = ? LIMIT 1");
    $log_ins   = $pdo->prepare(
        "INSERT INTO " . VE2_INVITE_LOG_TABLE . "
            (run_id, requestor_name, channel, recipient, cc_recipient, pending_po_count,
             po_numbers_json, message_hash, idempotency_key, status, sent_at, error_message)
         VALUES
            (:run_id,:req,:channel,:recipient,:cc,:cnt,:pos,:mhash,:idem,:status,:sent_at,:err)
         ON DUPLICATE KEY UPDATE
            status=VALUES(status), sent_at=VALUES(sent_at),
            error_message=VALUES(error_message), run_id=VALUES(run_id)"
    );

    $log_row = function (string $name, string $channel, string $recipient, ?string $cc, array $po_nums,
                         string $msg_hash, string $status, ?string $err) use ($log_ins, $run_id) {
        $po_csv = implode(',', $po_nums);
        $idem   = hash('sha256', $name . '|' . $channel . '|' . $po_csv . '|' . $msg_hash);
        try {
            $log_ins->execute([
                ':run_id'    => $run_id,
                ':req'       => $name,
                ':channel'   => $channel,
                ':recipient' => $recipient,
                ':cc'        => $cc,
                ':cnt'       => count($po_nums),
                ':pos'       => json_encode($po_nums, JSON_UNESCAPED_UNICODE),
                ':mhash'     => $msg_hash,
                ':idem'      => $idem,
                ':status'    => $status,
                ':sent_at'   => $status === 'sent' ? date('Y-m-d H:i:s') : null,
                ':err'       => $err,
            ]);
        } catch (\PDOException $e) {
            fwrite(STDERR, "[WARN] Could not write invite log for {$name}/{$channel}: " . $e->getMessage() . "\n");
        }
    };

    $sent_wa = 0; $fail_wa = 0; $sent_em = 0; $fail_em = 0; $skipped = 0;
    foreach ($plan as $req) {
        $name    = $req['requestor_name'];
        $pending = count($req['pos']);
        $po_nums = array_column($req['pos'], 'po_number');
        sort($po_nums);

        // WA channel
        if ($opts['channel'] === 'wa' || $opts['channel'] === 'both') {
            $wa_msg   = ve2_build_wa_message($name, $pending, $po_nums, $req['worklist_url']);
            $msg_hash = hash('sha256', $wa_msg);
            $idem     = hash('sha256', $name . '|wa|' . implode(',', $po_nums) . '|' . $msg_hash);
            $log_check->execute([$idem]);
            if ($log_check->fetchColumn() === 'sent') {
                $skipped++; echo "  [WA-SKIP] {$name} already sent (idempotent)\n";
            } else {
                $wa_res = sendFonnteWA($req['whatsapp_number'], $wa_msg);
                $wa_ok  = is_array($wa_res) && !empty($wa_res['ok']);
                if ($wa_ok) {
                    $sent_wa++;
                    echo "  [WA]    {$name} -> {$req['whatsapp_number']} ({$pending} PO)\n";
                    $log_row($name, 'wa', $req['whatsapp_number'], null, $po_nums, $msg_hash, 'sent', null);
                } else {
                    $fail_wa++;
                    $wa_why = is_array($wa_res) ? (string)($wa_res['reason'] ?? 'provider did not confirm') : 'provider did not confirm';
                    echo "  [WA-FAIL] {$name} -> {$req['whatsapp_number']}: {$wa_why}\n";
                    $log_row($name, 'wa', $req['whatsapp_number'], null, $po_nums, $msg_hash, 'failed', substr($wa_why, 0, 500));
                }
            }
        }

        // Email channel
        if ($opts['channel'] === 'email' || $opts['channel'] === 'both') {
            [$subject, $body] = ve2_build_email($name, $pending, $req['pos'], $req['worklist_url'], [
                'orig_email' => $req['email'],
                'mgr_mail'   => $req['manager_email'],
            ]);
            $msg_hash = hash('sha256', $body);
            $idem     = hash('sha256', $name . '|email|' . implode(',', $po_nums) . '|' . $msg_hash);
            $cc       = $cc_supported ? ($req['manager_email'] ?: null) : null;
            $log_check->execute([$idem]);
            if ($log_check->fetchColumn() === 'sent') {
                $skipped++; echo "  [EMAIL-SKIP] {$name} already sent (idempotent)\n";
            } else {
                $ok = $cc_supported
                    ? sendTenderEmail($req['email'], $name, $subject, $body, true, $cc)
                    : sendTenderEmail($req['email'], $name, $subject, $body, true);
                if ($ok) {
                    $sent_em++;
                    echo "  [EMAIL] {$name} -> {$req['email']}" . ($cc ? " (cc {$cc})" : '') . " ({$pending} PO)\n";
                    $log_row($name, 'email', $req['email'], $cc, $po_nums, $msg_hash, 'sent', null);
                } else {
                    $fail_em++;
                    echo "  [EMAIL-FAIL] {$name} -> {$req['email']}\n";
                    $log_row($name, 'email', $req['email'], $cc, $po_nums, $msg_hash, 'failed', 'sendTenderEmail returned false');
                }
            }
        }
    }

    echo "\n=======================================================================\n";
    echo " PRODUCTION SEND RESULT\n";
    echo "=======================================================================\n";
    printf(" %-28s %d\n", "WA sent (provider OK):", $sent_wa);
    printf(" %-28s %d\n", "WA failures:",     $fail_wa);
    printf(" %-28s %d\n", "Emails sent:",     $sent_em);
    printf(" %-28s %d\n", "Email failures:",  $fail_em);
    printf(" %-28s %d\n", "Skipped (idempotent):", $skipped);
    echo "\n[CONFIRM] Audit rows written to " . VE2_INVITE_LOG_TABLE . " (run_id={$run_id}).\n";
    echo "[CONFIRM] No requestors/users/question/v1 data modified.\n";
    echo "\nDone in " . round(microtime(true) - $tool_start, 2) . "s.\n\n";
    exit(0);
}

// ═══════════════════════════════════════════════════════════════════════════════
// DRY-RUN PATH (default) — SELECT only, writes preview CSV, no sends
// ═══════════════════════════════════════════════════════════════════════════════

$out_dir = $opts['out_dir'];
if (!is_dir($out_dir)) { mkdir($out_dir, 0755, true); }
$ts       = date('Ymd_His');
$csv_path = $out_dir . '/vendor_eval_pilot_send_dry_run_' . $ts . '.csv';

$fh = fopen($csv_path, 'w');
$csv_ok = ($fh !== false);
if ($csv_ok) {
    fputcsv($fh, [
        'requestor_name', 'email', 'whatsapp_number', 'manager_email',
        'po_number', 'vendor_name', 'taxonomy_level_2', 'taxonomy_level_3',
        'resolved_set_code', 'map_level', 'classification', 'blocked_reasons', 'caveats', 'worklist_url',
    ]);
    foreach ($candidates as $c) {
        fputcsv($fh, [
            $c['requestor_name'], $c['email'], $c['whatsapp_number'], $c['manager_email'],
            $c['po_number'], $c['vendor_name'], $c['taxonomy_level_2'], $c['taxonomy_level_3'],
            $c['set_code'], $c['map_level'], $c['classification'], $c['blocked_reasons'], $c['caveats'], $c['worklist_url'],
        ]);
    }
    fclose($fh);
}

// ── Console summary ────────────────────────────────────────────────────────────
echo "=======================================================================\n";
echo " DRY-RUN READINESS SUMMARY\n";
echo "=======================================================================\n";
printf(" %-34s %s\n", "Base URL:",                      $opts['base_url']);
printf(" %-34s %s\n", "Channel:",                       $opts['channel']);
printf(" %-34s %s\n", "Filters applied:",               $filters_str);
printf(" %-34s %d\n", "Total sendable (after filters):", $total_candidates);
printf(" %-34s %d\n", "Ready (clean):",                 $ready_clean);
printf(" %-34s %d\n", "Ready with caveats:",            $ready_caveat);
printf(" %-34s %d\n", "Blocked:",                       $blocked_count);
printf(" %-34s %d\n", "ready + caveat:",                $ready_total);
printf(" %-34s %d\n", "Distinct sendable requestors:",  count($plan));
printf(" %-34s %d\n", "Excluded — already submitted:",  $submitted_excluded);
printf(" %-34s %d\n", "Excluded — needs_review:",       $needs_review_excluded);
printf(" %-34s %d\n", "Placeholder WA candidates:",     $placeholder_wa_count);
printf(" %-34s %s\n", "Manager CC (production email):", "YES (To=requestor.email, CC=manager_email)");
printf(" %-34s %s\n", "Invite log table present:",      ($invite_log_exists ? 'YES' : 'NO'));
printf(" %-34s %s\n", "Localhost URL:",                 ($is_localhost_url ? 'YES' : 'no'));

if (!$invite_log_exists) {
    echo "\n[WARN] " . VE2_INVITE_LOG_TABLE . " is NOT present — production --send will refuse until the\n";
    echo "       migration db_backups/vendor_eval_v2_01_schema.sql (section 8) is applied.\n";
}

if ($blocked_count > 0) {
    echo "\nBLOCKED reason tally:\n";
    foreach ($blocked_reason_tally as $r => $n) { echo "  - {$r}: {$n}\n"; }
}

if ($opts['expected_ready_count'] !== null) {
    $match = ($opts['expected_ready_count'] === $ready_total);
    echo "\n";
    printf(" %-34s %s\n", "--expected-ready-count:", $opts['expected_ready_count']);
    printf(" %-34s %s\n", "Matches live ready+caveat:", ($match ? "YES ({$ready_total})" : "NO (live={$ready_total})"));
}

if ($placeholder_wa_count > 0) {
    if ($opts['allow_placeholder_wa'] === VE2_PLACEHOLDER_WA) {
        echo "\n[NOTE] Placeholder WA caveat acknowledged via --allow-placeholder-wa=" . VE2_PLACEHOLDER_WA . " ({$placeholder_wa_count} candidate(s)).\n";
    } elseif ($wa_in_channel) {
        echo "\n[WARN] {$placeholder_wa_count} candidate(s) use placeholder WA " . VE2_PLACEHOLDER_WA
           . " — production WA send will require --allow-placeholder-wa=" . VE2_PLACEHOLDER_WA . ".\n";
    }
}

// First 5 sample recipients (requestors)
echo "\n-----------------------------------------------------------------------\n";
echo " FIRST 5 SAMPLE RECIPIENTS (requestor-grouped)\n";
echo "-----------------------------------------------------------------------\n";
$i = 0;
foreach ($plan as $req) {
    if ($i++ >= 5) { break; }
    printf(" %d. %-26s  WA=%-13s  email=%s\n", $i, $req['requestor_name'], $req['whatsapp_number'], $req['email']);
    echo "    POs: " . count($req['pos']) . "  |  URL: {$req['worklist_url']}\n";
}

echo "\n";
if ($csv_ok) { echo "CSV written : {$csv_path} (" . round(filesize($csv_path) / 1024, 1) . " KB)\n"; }
else         { fwrite(STDERR, "[ERROR] Could not write CSV: {$csv_path}\n"); }

if ($is_localhost_url) {
    echo "[WARN] URLs are localhost-only — NOT production-ready.\n";
}

echo "\n[DRY RUN ONLY — NO WA OR EMAIL WAS SENT. NO DB WRITES.]\n";
echo "\nDone in " . round(microtime(true) - $tool_start, 2) . "s.\n\n";
