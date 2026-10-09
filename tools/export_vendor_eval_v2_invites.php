<?php
// tools/export_vendor_eval_v2_invites.php
//
// Vendor Evaluation v2 — invite preview/export CLI tool.
// Generates token-URL invite CSV and TXT preview files.
//
// Usage:
//   C:\xampp\php\php.exe tools/export_vendor_eval_v2_invites.php [OPTIONS]
//
// Options:
//   --base-url=URL            Base URL for token links (default: http://localhost/workspace/pages)
//   --out-dir=PATH            Output directory (default: db_backups/local_runtime_exports)
//   --limit-requestors=N      Export only first N requestors (optional)
//   --include-zero-pending    Include requestors with 0 pending evaluations (default: off)
//   --format=csv|txt|both     Output format (default: both)
//
// SAFETY:
//   - CLI-only. Refuses web/browser access.
//   - No DB writes. SELECT queries only.
//   - No WA, email, Fonnte, PHPMailer, Gemini, or API calls.
//   - Does NOT touch vendor_evaluations (v1).
//   - --send is not implemented and will abort if passed.

// ── CLI guard ─────────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/export_vendor_eval_v2_invites.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

// ── --send guard (not implemented) ────────────────────────────────────────────
foreach ($argv as $arg) {
    if ($arg === '--send' || str_starts_with($arg, '--send=')) {
        exit(
            "\n[ABORT] --send is not supported.\n"
            . "Sending is not implemented in this tool. This tool exports previews only.\n"
            . "To send invites, use a dedicated send tool once contacts are verified.\n\n"
        );
    }
}

$tool_start = microtime(true);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
$_SERVER['REQUEST_URI'] = '';
define('RUNNING_AS_CLI_TOOL', true);

$root = dirname(__DIR__);
require_once $root . '/workspace/config/database.php';

// ── Argument parsing ──────────────────────────────────────────────────────────
$opts = [
    'base_url'             => 'http://localhost/workspace/pages',
    'out_dir'              => $root . '/db_backups/local_runtime_exports',
    'limit_requestors'     => null,
    'include_zero_pending' => false,
    'format'               => 'both',
];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--base-url=(.+)$/', $arg, $m)) {
        $opts['base_url'] = rtrim($m[1], '/');
    } elseif (preg_match('/^--out-dir=(.+)$/', $arg, $m)) {
        $opts['out_dir'] = $m[1];
    } elseif (preg_match('/^--limit-requestors=(\d+)$/', $arg, $m)) {
        $opts['limit_requestors'] = (int)$m[1];
    } elseif ($arg === '--include-zero-pending') {
        $opts['include_zero_pending'] = true;
    } elseif (preg_match('/^--format=(csv|txt|both)$/', $arg, $m)) {
        $opts['format'] = $m[1];
    } elseif (str_starts_with($arg, '--')) {
        fwrite(STDERR, "[WARN] Unknown option: $arg\n");
    }
}

// ── Output directory ──────────────────────────────────────────────────────────
$out_dir = $opts['out_dir'];
if (!is_dir($out_dir)) {
    mkdir($out_dir, 0755, true);
}
$ts       = date('Ymd_His');
$csv_path = $out_dir . '/vendor_eval_v2_invite_preview_token_' . $ts . '.csv';
$txt_path = $out_dir . '/vendor_eval_v2_invite_preview_token_summary_' . $ts . '.txt';

// ── Constants ─────────────────────────────────────────────────────────────────
const LOCAL_TEST_WA      = '62817209700';
const LOCAL_TEST_MANAGER = 'fsthicc@gmail.com';

// ── Banner ────────────────────────────────────────────────────────────────────
echo "\n";
echo "=======================================================================\n";
echo " Vendor Evaluation v2 — Invite Preview Exporter\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n";
echo " Base URL : {$opts['base_url']}\n";
echo " Out dir  : {$out_dir}\n";
echo " Format   : {$opts['format']}\n";
if ($opts['limit_requestors']) {
    echo " Limit    : first {$opts['limit_requestors']} requestors\n";
}
if ($opts['include_zero_pending']) {
    echo " Filter   : include zero-pending requestors\n";
} else {
    echo " Filter   : pending > 0 only (use --include-zero-pending to change)\n";
}
echo "\n";

// ── Localhost warning ─────────────────────────────────────────────────────────
if (str_contains($opts['base_url'], 'localhost') || str_contains($opts['base_url'], '127.0.0.1')) {
    echo "[WARN] Base URL contains localhost — URLs are NOT production-ready.\n";
    echo "       Use --base-url=https://your-production-domain.com/workspace/pages before sending.\n\n";
}

// ── DB identity check ─────────────────────────────────────────────────────────
$id = $pdo->query("SELECT DATABASE() AS db, USER() AS usr, @@hostname AS host, VERSION() AS ver")
          ->fetch(PDO::FETCH_ASSOC);
echo " DB   : {$id['db']}\n";
echo " User : {$id['usr']}\n";
echo " Host : {$id['host']}\n";
echo " Ver  : {$id['ver']}\n\n";

if ($id['db'] !== 'u894129685_workspace') {
    exit("[ABORT] Unexpected database '{$id['db']}'. Expected u894129685_workspace. Aborting.\n");
}

// ── Step 1: Load requestor summary counts ────────────────────────────────────
echo "Loading requestor data...\n";

$summary_sql = "
    SELECT
        r.requestor_name,
        r.email,
        r.whatsapp_number,
        r.manager_email,
        r.public_token,
        SUM(CASE
            WHEN t.status = 'mapped'
             AND NOT EXISTS (
                 SELECT 1 FROM vendor_eval_submissions s
                  WHERE s.po_number   = t.po_number
                    AND s.requestor_name = r.requestor_name
                    AND s.status      = 'submitted'
             ) THEN 1 ELSE 0
        END) AS pending_count,
        SUM(CASE
            WHEN t.status = 'mapped'
             AND EXISTS (
                 SELECT 1 FROM vendor_eval_submissions s
                  WHERE s.po_number   = t.po_number
                    AND s.requestor_name = r.requestor_name
                    AND s.status      = 'submitted'
             ) THEN 1 ELSE 0
        END) AS submitted_count,
        SUM(CASE WHEN t.status = 'needs_review' THEN 1 ELSE 0 END) AS needs_review_count,
        SUM(CASE WHEN t.status NOT IN ('mapped','needs_review') THEN 1 ELSE 0 END) AS unresolved_count
    FROM requestors r
    JOIN vendor_eval_po_taxonomy t
      ON JSON_SEARCH(t.requestor_names_json, 'one', r.requestor_name) IS NOT NULL
    WHERE r.public_token IS NOT NULL
    GROUP BY r.requestor_name, r.email, r.whatsapp_number, r.manager_email, r.public_token
    ORDER BY r.requestor_name
";

$all_requestors = $pdo->query($summary_sql)->fetchAll(PDO::FETCH_ASSOC);
echo " Found " . count($all_requestors) . " requestors with public_token in vendor_eval_po_taxonomy.\n";

// ── Step 2: Load all pending POs (for sample + preview text) ─────────────────
echo "Loading pending PO details...\n";

$pending_sql = "
    SELECT
        r.requestor_name,
        t.po_number,
        t.vendor_name,
        t.taxonomy_level_2,
        t.taxonomy_level_3,
        t.mapped_at
    FROM requestors r
    JOIN vendor_eval_po_taxonomy t
      ON JSON_SEARCH(t.requestor_names_json, 'one', r.requestor_name) IS NOT NULL
    WHERE r.public_token IS NOT NULL
      AND t.status = 'mapped'
      AND NOT EXISTS (
          SELECT 1 FROM vendor_eval_submissions s
           WHERE s.po_number      = t.po_number
             AND s.requestor_name = r.requestor_name
             AND s.status         = 'submitted'
      )
    ORDER BY r.requestor_name, t.mapped_at DESC
";

$pending_rows = $pdo->query($pending_sql)->fetchAll(PDO::FETCH_ASSOC);

// Group pending POs by requestor
$pending_by_req = [];
foreach ($pending_rows as $row) {
    $pending_by_req[$row['requestor_name']][] = $row;
}
echo " Found " . count($pending_rows) . " total pending mapped evaluations.\n\n";

// ── Step 3: Build export rows ─────────────────────────────────────────────────
$export_rows = [];

foreach ($all_requestors as $r) {
    $name    = (string)$r['requestor_name'];
    $pending = (int)$r['pending_count'];

    if (!$opts['include_zero_pending'] && $pending === 0) {
        continue;
    }
    if ($opts['limit_requestors'] !== null && count($export_rows) >= $opts['limit_requestors']) {
        break;
    }

    $token    = (string)($r['public_token'] ?? '');
    $email    = (string)($r['email'] ?? '');
    $wa       = (string)($r['whatsapp_number'] ?? '');
    $mgr_mail = (string)($r['manager_email'] ?? '');

    // Flags
    $uses_local_wa      = ($wa === LOCAL_TEST_WA)      ? 'YES' : 'no';
    $uses_local_manager = ($mgr_mail === LOCAL_TEST_MANAGER) ? 'YES' : 'no';
    $missing_email      = ($email === '')     ? 'YES' : 'no';
    $missing_wa         = ($wa === '')        ? 'YES' : 'no';
    $missing_mgr        = ($mgr_mail === '')  ? 'YES' : 'no';
    $missing_token      = ($token === '')     ? 'YES' : 'no';

    // Token URLs
    $worklist_url = $opts['base_url'] . '/public_eval_v2_worklist.php?token=' . urlencode($token);

    // Sample pending POs (all, grouped)
    $my_pending   = $pending_by_req[$name] ?? [];
    $sample_pos   = array_slice(array_column($my_pending, 'po_number'), 0, 10);
    $sample_vends = array_slice(array_column($my_pending, 'vendor_name'), 0, 10);
    $sample_form_urls = array_map(function ($po) use ($opts, $token) {
        return $opts['base_url'] . '/public_eval_v2.php?po=' . urlencode($po) . '&token=' . urlencode($token);
    }, array_slice($sample_pos, 0, 5));

    // WA preview (Indonesian, first 5 POs)
    $wa_pos_list = implode("\n", array_map(
        fn($po) => "  • {$po}",
        array_slice($sample_pos, 0, 5)
    ));
    if ($pending > 5) {
        $wa_pos_list .= "\n  ... dan " . ($pending - 5) . " PO lainnya";
    }
    $wa_preview = ($pending > 0)
        ? "LOCAL TEST PREVIEW ONLY — NOT SENT\n"
          . "To: {$wa}\n"
          . str_repeat('-', 40) . "\n"
          . "Halo {$name},\n\n"
          . "Anda memiliki *{$pending} Purchase Order* yang menunggu evaluasi vendor.\n\n"
          . "PO yang perlu dievaluasi:\n{$wa_pos_list}\n\n"
          . "Silakan buka daftar evaluasi Anda melalui link berikut:\n"
          . "{$worklist_url}\n\n"
          . "Mohon selesaikan evaluasi dalam 3 hari kerja. Terima kasih."
        : "(no pending — skipped)";

    // Email subject
    $email_subject = ($pending > 0)
        ? "Vendor Evaluation Required - {$pending} PO Pending"
        : "(no pending — skipped)";

    // Email body preview (first 10 PO/vendor/taxonomy rows)
    $email_po_rows = '';
    foreach (array_slice($my_pending, 0, 10) as $idx => $po_row) {
        $l3  = !empty($po_row['taxonomy_level_3']) ? " > {$po_row['taxonomy_level_3']}" : '';
        $email_po_rows .= sprintf(
            "  %2d. PO %-20s  Vendor: %-40s  Kategori: %s%s\n",
            $idx + 1,
            $po_row['po_number'],
            $po_row['vendor_name'],
            $po_row['taxonomy_level_2'],
            $l3
        );
    }
    if ($pending > 10) {
        $email_po_rows .= "      ... dan " . ($pending - 10) . " PO lainnya.\n";
    }

    $email_body = ($pending > 0)
        ? "LOCAL TEST PREVIEW ONLY — NOT SENT\n"
          . "To: {$email}\n"
          . "CC: {$mgr_mail}\n"
          . "Subject: {$email_subject}\n"
          . str_repeat('-', 40) . "\n"
          . "Dear {$name},\n\n"
          . "Anda memiliki {$pending} Purchase Order yang memerlukan evaluasi vendor.\n\n"
          . "Daftar PO:\n{$email_po_rows}\n"
          . "Silakan akses daftar evaluasi Anda melalui link berikut:\n"
          . "{$worklist_url}\n\n"
          . "Mohon selesaikan evaluasi dalam waktu 3 hari kerja.\n\n"
          . "Terima kasih,\nTim Procurement"
        : "(no pending — skipped)";

    $export_rows[] = [
        'requestor_name'                => $name,
        'email'                         => $email,
        'whatsapp_number'               => $wa,
        'manager_email'                 => $mgr_mail,
        'pending_count'                 => $pending,
        'submitted_count'               => (int)$r['submitted_count'],
        'needs_review_count'            => (int)$r['needs_review_count'],
        'worklist_token_url'            => $worklist_url,
        'sample_pending_pos'            => implode(', ', $sample_pos),
        'sample_vendors'                => implode(', ', $sample_vends),
        'sample_form_urls'              => implode("\n", $sample_form_urls),
        'wa_preview'                    => $wa_preview,
        'email_subject_preview'         => $email_subject,
        'email_body_preview'            => $email_body,
        'uses_local_test_manager_email' => $uses_local_manager,
        'uses_local_test_wa'            => $uses_local_wa,
        'missing_email'                 => $missing_email,
        'missing_wa'                    => $missing_wa,
        'missing_manager_email'         => $missing_mgr,
        'missing_public_token'          => $missing_token,
    ];
}

// ── Step 4: Aggregate warnings ────────────────────────────────────────────────
$total_pending   = array_sum(array_column($export_rows, 'pending_count'));
$total_submitted = array_sum(array_column($export_rows, 'submitted_count'));
$total_nr        = array_sum(array_column($export_rows, 'needs_review_count'));
$cnt_local_wa    = count(array_filter($export_rows, fn($r) => $r['uses_local_test_wa'] === 'YES'));
$cnt_local_mgr   = count(array_filter($export_rows, fn($r) => $r['uses_local_test_manager_email'] === 'YES'));
$cnt_miss_email  = count(array_filter($export_rows, fn($r) => $r['missing_email'] === 'YES'));
$cnt_miss_wa     = count(array_filter($export_rows, fn($r) => $r['missing_wa'] === 'YES'));
$cnt_miss_mgr    = count(array_filter($export_rows, fn($r) => $r['missing_manager_email'] === 'YES'));
$cnt_miss_token  = count(array_filter($export_rows, fn($r) => $r['missing_public_token'] === 'YES'));

// ── Step 5: Write CSV ─────────────────────────────────────────────────────────
$wrote_csv = false;
$wrote_txt = false;

if ($opts['format'] === 'csv' || $opts['format'] === 'both') {
    $fh = fopen($csv_path, 'w');
    if ($fh === false) {
        fwrite(STDERR, "[ERROR] Cannot write CSV to: {$csv_path}\n");
    } else {
        fputcsv($fh, array_keys($export_rows[0] ?? []));
        foreach ($export_rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
        $wrote_csv = true;
        echo "CSV written: {$csv_path} (" . round(filesize($csv_path) / 1024, 1) . " KB)\n";
    }
}

// ── Step 6: Write TXT summary ─────────────────────────────────────────────────
if ($opts['format'] === 'txt' || $opts['format'] === 'both') {
    $lines   = [];
    $lines[] = "VENDOR EVALUATION v2 — INVITE PREVIEW (TOKEN URLS)";
    $lines[] = "LOCAL TEST PREVIEW ONLY — NOT SENT";
    $lines[] = "Generated: " . date('Y-m-d H:i:s');
    $lines[] = "Base URL : " . $opts['base_url'];
    $lines[] = str_repeat('=', 80);

    foreach ($export_rows as $r) {
        $lines[] = '';
        $lines[] = "[{$r['requestor_name']}] — {$r['pending_count']} pending / {$r['submitted_count']} submitted / {$r['needs_review_count']} needs_review";
        $lines[] = "  Email    : {$r['email']}" . ($r['missing_email'] === 'YES' ? ' [MISSING]' : '');
        $lines[] = "  WA       : {$r['whatsapp_number']}" . ($r['missing_wa'] === 'YES' ? ' [MISSING]' : '') . ($r['uses_local_test_wa'] === 'YES' ? ' [LOCAL TEST WA]' : '');
        $lines[] = "  Manager  : {$r['manager_email']}" . ($r['missing_manager_email'] === 'YES' ? ' [MISSING]' : '') . ($r['uses_local_test_manager_email'] === 'YES' ? ' [LOCAL TEST EMAIL]' : '');
        $lines[] = "  Worklist : {$r['worklist_token_url']}";
        $lines[] = "  POs      : {$r['sample_pending_pos']}";
        $lines[] = "  Vendors  : {$r['sample_vendors']}";
        if ($r['pending_count'] > 0) {
            $lines[] = '';
            $lines[] = "  WA PREVIEW:";
            foreach (explode("\n", $r['wa_preview']) as $wl) {
                $lines[] = "    {$wl}";
            }
            $lines[] = '';
            $lines[] = "  EMAIL PREVIEW:";
            foreach (explode("\n", $r['email_body_preview']) as $el) {
                $lines[] = "    {$el}";
            }
        }
        $lines[] = str_repeat('-', 80);
    }

    $lines[] = '';
    $lines[] = "SUMMARY";
    $lines[] = "  Requestors exported        : " . count($export_rows);
    $lines[] = "  Total pending evaluations  : {$total_pending}";
    $lines[] = "  Total submitted            : {$total_submitted}";
    $lines[] = "  Total needs_review         : {$total_nr}";
    $lines[] = "  Missing email              : {$cnt_miss_email}";
    $lines[] = "  Missing WA                 : {$cnt_miss_wa}";
    $lines[] = "  Missing manager_email      : {$cnt_miss_mgr}";
    $lines[] = "  Missing public_token       : {$cnt_miss_token}";
    $lines[] = "  Local test WA              : {$cnt_local_wa} requestors";
    $lines[] = "  Local test manager email   : {$cnt_local_mgr} requestors";
    $lines[] = '';
    $lines[] = "LOCAL TEST PREVIEW ONLY — NOT SENT";

    file_put_contents($txt_path, implode("\n", $lines) . "\n");
    $wrote_txt = true;
    echo "TXT written: {$txt_path} (" . round(filesize($txt_path) / 1024, 1) . " KB)\n";
}

// ── Step 7: Console summary ───────────────────────────────────────────────────
echo "\n";
echo "=======================================================================\n";
echo " SUMMARY\n";
echo "=======================================================================\n";
printf(" %-36s %d\n",  "Requestors exported:",        count($export_rows));
printf(" %-36s %d\n",  "Total pending evaluations:",  $total_pending);
printf(" %-36s %d\n",  "Total submitted:",            $total_submitted);
printf(" %-36s %d\n",  "Total needs_review:",         $total_nr);
printf(" %-36s %d\n",  "Missing email:",              $cnt_miss_email);
printf(" %-36s %d\n",  "Missing WA:",                 $cnt_miss_wa);
printf(" %-36s %d\n",  "Missing manager_email:",      $cnt_miss_mgr);
printf(" %-36s %d\n",  "Missing public_token:",       $cnt_miss_token);
printf(" %-36s %d requestors\n", "Local test WA (62817209700):", $cnt_local_wa);
printf(" %-36s %d requestors\n", "Local test manager email:",    $cnt_local_mgr);
echo "\n";

// ── Step 8: Loud warnings ─────────────────────────────────────────────────────
$has_warn = false;

if (str_contains($opts['base_url'], 'localhost') || str_contains($opts['base_url'], '127.0.0.1')) {
    echo "[WARN] URLs are localhost-only — NOT production-ready.\n";
    $has_warn = true;
}
if ($cnt_local_wa > 0) {
    echo "[WARN] {$cnt_local_wa} requestor(s) still use LOCAL TEST WA 62817209700.\n";
    $has_warn = true;
}
if ($cnt_local_mgr > 0) {
    echo "[WARN] {$cnt_local_mgr} requestor(s) still use LOCAL TEST manager email (fsthicc@gmail.com).\n";
    $has_warn = true;
}
if ($cnt_miss_token > 0) {
    echo "[WARN] {$cnt_miss_token} requestor(s) are MISSING a public_token — they cannot be reached.\n";
    $has_warn = true;
}
if ($cnt_miss_email > 0) {
    echo "[WARN] {$cnt_miss_email} requestor(s) have no email address.\n";
    $has_warn = true;
}
if ($cnt_miss_wa > 0) {
    echo "[WARN] {$cnt_miss_wa} requestor(s) have no WhatsApp number.\n";
    $has_warn = true;
}
if (!$has_warn) {
    echo "[OK] No warnings. Contacts look production-ready.\n";
}

echo "\n";
echo "[REMINDER] This tool ONLY exports previews. No WA or email was sent.\n";
echo "           Sending is not implemented. Use a dedicated send tool after contacts are verified.\n";

$elapsed = round(microtime(true) - $tool_start, 2);
echo "\nDone in {$elapsed}s.\n\n";
