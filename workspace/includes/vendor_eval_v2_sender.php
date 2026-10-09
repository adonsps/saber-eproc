<?php
// workspace/includes/vendor_eval_v2_sender.php
//
// Vendor Evaluation v2 — shared invite/reminder sender library.
//
// This file is a PURE LIBRARY: it defines constants and functions only.
// Including it performs no DB connection, no auth, no output, no WA/email and
// no side effects of any kind. fonnte.php and mailer.php are pulled in lazily,
// only on the code path that actually dispatches a message — so `preview` mode
// and plain `require_once` of this file can never send anything.
//
// Consumers:
//   • tools/send_vendor_eval_v2_invites.php  — CLI, dry-run/test/production modes
//   • workspace/pages/ajax_send_wa.php       — web, manager-only reminder sender
//   • workspace/pages/dashboard_mgr.php      — CSRF token only
//
// Core invariants:
//   • One requestor  = one email + one WhatsApp message (never one per PO).
//   • Links always point at <base>/public_eval_v2_worklist.php?token=<public_token>
//     using requestors.public_token — never a per-PO token, never /vms/pages/.
//   • requestors.manager_username is never read (manager_email only).
//   • A channel counts as "sent" only when the provider confirms it:
//     Fonnte must return status=true; sendTenderEmail() must return true.
//   • Every dispatch attempt is audited in vendor_eval_v2_invite_log.
//   • v1 `vendor_evaluations` is never read or written here.

// Manual override lookup (ve2_load_overrides / ve2_override_set). Pure library — a
// manually-assigned question set wins over auto routing; the L3→L2→Default chain and
// all send/scheduling logic below are otherwise unchanged.
require_once __DIR__ . '/vendor_eval_v2_routing.php';

// ── Constants (guarded so CLI/web can both define their own extras) ──────────

/** Sentinel used by vendor_eval_question_set_taxonomy_map for "any level". */
if (!defined('VE2_SENTINEL_ANY'))      { define('VE2_SENTINEL_ANY', '**ANY**'); }

/** Audit table. Sending is refused when this table is absent. */
if (!defined('VE2_INVITE_LOG_TABLE'))  { define('VE2_INVITE_LOG_TABLE', 'vendor_eval_v2_invite_log'); }

/** Shared placeholder WA number seeded into non-production requestor rows. */
if (!defined('VE2_PLACEHOLDER_WA'))    { define('VE2_PLACEHOLDER_WA', '62817209700'); }

/** Hard ceilings for the web sender — a blast larger than this is refused. */
if (!defined('VE2_MAX_REQUESTORS_PER_RUN')) { define('VE2_MAX_REQUESTORS_PER_RUN', 20); }
if (!defined('VE2_MAX_POS_PER_RUN'))        { define('VE2_MAX_POS_PER_RUN', 200); }

/** The web sender refuses to dispatch unless the operator echoes this token back,
 *  which the dashboard only supplies once the confirmation checkbox is ticked. */
if (!defined('VE2_WEB_SEND_CONFIRM')) {
    define('VE2_WEB_SEND_CONFIRM', 'I_UNDERSTAND_THIS_SENDS_REAL_EMAIL_AND_WHATSAPP');
}

/** Hosts allowed to originate a worklist link. Override with a comma-separated
 *  VE2_ALLOWED_HOSTS constant (e.g. in config) when deploying elsewhere. */
if (!defined('VE2_ALLOWED_HOSTS')) {
    define('VE2_ALLOWED_HOSTS', 'vssportal.online,www.vssportal.online,localhost,127.0.0.1,::1');
}

/** Every worklist link must be served from this directory. Catches the old
 *  `/vms/pages/...` link bug at generation time instead of in the requestor's inbox. */
if (!defined('VE2_PAGES_PATH_SUFFIX')) { define('VE2_PAGES_PATH_SUFFIX', '/workspace/pages'); }

// ── Small helpers ────────────────────────────────────────────────────────────

/** Mask a phone number for display/response: 6281*****734. */
function ve2_mask_phone(string $wa): string
{
    $d = preg_replace('/\D+/', '', $wa);
    $n = strlen($d);
    if ($n === 0)  { return ''; }
    if ($n <= 6)   { return str_repeat('*', $n); }
    return substr($d, 0, 4) . str_repeat('*', $n - 7) . substr($d, -3);
}

/** Strip any occurrence of the Fonnte token (or other secret) from a log line. */
function ve2_redact_secrets(string $text): string
{
    if (defined('FONNTE_TOKEN') && FONNTE_TOKEN !== '') {
        $text = str_replace(FONNTE_TOKEN, '***REDACTED***', $text);
    }
    return $text;
}

/** Generate a run identifier for vendor_eval_v2_invite_log.run_id (VARCHAR(64)). */
function ve2_new_run_id(string $label = 'cli'): string
{
    $label = preg_replace('/[^a-z0-9_]/i', '', $label) ?: 'run';
    return date('Ymd_His') . '_' . $label . '_' . bin2hex(random_bytes(4));
}

// ── CSRF (session-scoped; used by dashboard_mgr.php + ajax_send_wa.php) ──────

function ve2_csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }
    if (empty($_SESSION['_csrf_ve2_web'])) {
        $_SESSION['_csrf_ve2_web'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['_csrf_ve2_web'];
}

function ve2_csrf_check(string $submitted): bool
{
    return $submitted !== ''
        && !empty($_SESSION['_csrf_ve2_web'])
        && hash_equals($_SESSION['_csrf_ve2_web'], $submitted);
}

// ── URL construction ─────────────────────────────────────────────────────────

/** The one and only worklist link shape. */
function ve2_worklist_url(string $base_url, string $token): string
{
    return rtrim($base_url, '/') . '/public_eval_v2_worklist.php?token=' . urlencode($token);
}

/** Validate base URL shape. Returns a list of hard errors (empty = ok). */
function ve2_base_url_errors(string $base_url, bool $is_production): array
{
    $errs = [];
    $is_localhost = str_contains($base_url, 'localhost') || str_contains($base_url, '127.0.0.1');

    if ($is_production && $is_localhost) {
        $errs[] = 'base URL must not be localhost/127.0.0.1 for production send';
    }
    if (str_contains($base_url, 'dashboard_mgr.php')) {
        $errs[] = 'base URL must not contain dashboard_mgr.php (use the pages directory only)';
    }
    if (preg_match('/\.php$/i', $base_url)) {
        $errs[] = 'base URL must not end with .php (point at the pages directory, not a page)';
    }
    $path = (string)parse_url($base_url, PHP_URL_PATH);
    if (!str_ends_with(rtrim($path, '/'), VE2_PAGES_PATH_SUFFIX)) {
        $errs[] = 'base URL path must end with ' . VE2_PAGES_PATH_SUFFIX
                . " (got '" . ($path === '' ? '(none)' : $path) . "') — this is what produced the broken /vms/pages links";
    }
    return $errs;
}

/**
 * Derive the public base URL for worklist links from the running request.
 *
 * Production resolves to https://vssportal.online/vms/workspace/pages,
 * local XAMPP/Docker to http://localhost[:port]/workspace/pages, because the
 * calling page itself lives in workspace/pages. Define VE2_PUBLIC_BASE_URL to
 * override. Throws RuntimeException rather than emitting a poisoned link.
 */
function ve2_web_base_url(): string
{
    if (defined('VE2_PUBLIC_BASE_URL') && VE2_PUBLIC_BASE_URL !== '') {
        return rtrim(VE2_PUBLIC_BASE_URL, '/');
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9._:\[\]-]+$/', $host)) {
        throw new RuntimeException('Cannot derive a safe base URL: request Host header is missing or malformed.');
    }

    // Host allowlist — a forged Host header must never end up inside an invite link.
    $bare = strtolower(preg_replace('/:\d+$/', '', $host));
    $allowed = array_filter(array_map('trim', explode(',', strtolower(VE2_ALLOWED_HOSTS))));
    if (!in_array($bare, $allowed, true)) {
        throw new RuntimeException(
            "Host '{$bare}' is not an approved link host. Add it to a VE2_ALLOWED_HOSTS constant before sending."
        );
    }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
          || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $dir = '/' . trim($dir, '/');

    $base = ($https ? 'https' : 'http') . '://' . $host . rtrim($dir, '/');

    // Localhost stays legal here so dev keeps working; ve2_send_reminders warns instead.
    $errs = ve2_base_url_errors($base, false);
    if (!empty($errs)) {
        throw new RuntimeException('Derived base URL rejected: ' . implode('; ', $errs));
    }
    return $base;
}

// ── Question-set routing (same precedence as public_eval_v2.php) ─────────────

/** Load the active taxonomy → question-set routing map. */
function ve2_load_taxonomy_map(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT qs.id, qs.set_code, qs.set_name, m.map_level, m.taxonomy_level_2, m.taxonomy_level_3
          FROM vendor_eval_question_set_taxonomy_map m
          JOIN vendor_eval_question_sets qs ON qs.id = m.question_set_id
         WHERE qs.is_active = 1 AND m.is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $l3 = [];
    $l2 = [];
    $default = null;
    foreach ($rows as $m) {
        if ($m['map_level'] === 'l3') {
            $l3[$m['taxonomy_level_2'] . '||' . $m['taxonomy_level_3']] = $m;
        } elseif ($m['map_level'] === 'l2' && $m['taxonomy_level_3'] === VE2_SENTINEL_ANY) {
            $l2[$m['taxonomy_level_2']] = $m;
        } elseif ($m['map_level'] === 'default') {
            $default = $m;
        }
    }
    return ['l3' => $l3, 'l2' => $l2, 'default' => $default];
}

/** Resolve a question set for a taxonomy pair: exact L3 → L2(+ANY L3) → default. */
function ve2_resolve_set(string $l2, ?string $l3, array $l3map, array $l2map, ?array $default): ?array
{
    $l2 = trim($l2);
    $l3 = trim((string)$l3);
    if ($l3 !== '' && isset($l3map[$l2 . '||' . $l3])) { return $l3map[$l2 . '||' . $l3]; }
    if (isset($l2map[$l2]))                            { return $l2map[$l2]; }
    if ($default !== null)                             { return $default; }
    return null;
}

// ── Message builders ─────────────────────────────────────────────────────────

/**
 * Build the requestor WhatsApp message.
 *
 * $opt: footer (string), max_list (int, 0 = list every PO).
 */
function ve2_build_wa_message(string $name, int $pending, array $po_numbers, string $worklist_url, array $opt = []): string
{
    $footer   = (string)($opt['footer'] ?? '');
    $max_list = array_key_exists('max_list', $opt) ? (int)$opt['max_list'] : 5;

    $shown  = ($max_list > 0) ? array_slice($po_numbers, 0, $max_list) : $po_numbers;
    $hidden = count($po_numbers) - count($shown);

    $pos_text = implode("\n", array_map(fn($po) => "  • {$po}", $shown));
    if ($hidden > 0) {
        $pos_text .= "\n  ... dan {$hidden} PO lainnya";
    }

    $msg = "Halo *{$name}*,\n\n"
        . "Anda memiliki *{$pending} Purchase Order* yang menunggu evaluasi vendor.\n\n"
        . "PO yang perlu dievaluasi:\n{$pos_text}\n\n"
        . "Silakan buka daftar evaluasi Anda melalui link berikut:\n"
        . "{$worklist_url}\n\n"
        . "Mohon diselesaikan dalam 3 hari kerja.";

    if ($footer !== '') {
        $msg .= "\n\n" . $footer;
    }
    return $msg;
}

/**
 * Build the requestor email as [subject, html_body].
 *
 * $po_rows = list of ['po_number','vendor_name','taxonomy_level_2','taxonomy_level_3'].
 * $opt keys:
 *   subject     (string) — default keeps the CLI's historical subject line
 *   footer      (string)
 *   max_rows    (int)    — table row cap; 0 renders every PO (web sender uses 0)
 *   is_test     (bool)   — appends the CLI test-send provenance block
 *   orig_email  (string) — test block only
 *   mgr_mail    (string) — test block only
 *   test_email  (string) — test block only
 */
function ve2_build_email(string $name, int $pending, array $po_rows, string $worklist_url, array $opt = []): array
{
    $subject    = (string)($opt['subject'] ?? "Vendor Evaluation Required - {$pending} PO Pending");
    $footer     = (string)($opt['footer'] ?? '');
    $max_rows   = array_key_exists('max_rows', $opt) ? (int)$opt['max_rows'] : 10;
    $is_test    = (bool)($opt['is_test'] ?? false);
    $orig_email = (string)($opt['orig_email'] ?? '');
    $mgr_mail   = (string)($opt['mgr_mail'] ?? '');
    $test_email = (string)($opt['test_email'] ?? '');

    $shown  = ($max_rows > 0) ? array_slice($po_rows, 0, $max_rows) : $po_rows;
    $hidden = count($po_rows) - count($shown);

    $po_table_rows = '';
    foreach ($shown as $idx => $po_row) {
        $cat = htmlspecialchars((string)($po_row['taxonomy_level_2'] ?? ''));
        if (!empty($po_row['taxonomy_level_3'])) {
            $cat .= ' &gt; ' . htmlspecialchars((string)$po_row['taxonomy_level_3']);
        }
        $bg = $idx % 2 === 0 ? '#ffffff' : '#f9f9f9';
        $po_table_rows .= "<tr style=\"background:{$bg};\">"
            . '<td style="padding:6px 10px;text-align:center;border:1px solid #ddd;">' . ($idx + 1) . '</td>'
            . '<td style="padding:6px 10px;font-family:monospace;border:1px solid #ddd;">' . htmlspecialchars((string)$po_row['po_number']) . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;">' . htmlspecialchars((string)($po_row['vendor_name'] ?? '')) . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;">' . $cat . '</td>'
            . "</tr>\n";
    }

    $more_pos_html = '';
    if ($hidden > 0) {
        $more_pos_html = '<p style="margin:4px 0 12px;font-size:13px;color:#555;">... dan '
            . $hidden . ' PO lainnya.</p>';
    }

    $footer_color = $is_test ? '#cc0000' : '#aaaaaa';
    $footer_html  = $footer !== ''
        ? '<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">'
          . '<p style="color:' . $footer_color . ';font-size:12px;font-weight:bold;margin:4px 0;">'
          . htmlspecialchars($footer) . '</p>'
        : '';

    $test_contact_html = '';
    if ($is_test) {
        $test_contact_html = '<p style="font-size:11px;color:#888;margin:4px 0;">'
            . '[TEST SEND] Email redirected to test contact: ' . htmlspecialchars($test_email) . '<br>'
            . '[TEST SEND] Original requestor email: ' . htmlspecialchars($orig_email) . '<br>'
            . '[TEST SEND] Would CC manager: ' . htmlspecialchars($mgr_mail) . ' (NOT CC\'d in test mode)'
            . '</p>';
    }

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:sans-serif;font-size:14px;color:#333;max-width:700px;margin:0 auto;padding:20px;">'
        . '<p>Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>'
        . '<p>Anda memiliki <strong>' . $pending . ' Purchase Order</strong> yang memerlukan evaluasi vendor.</p>'
        . '<p><strong>Daftar PO:</strong></p>'
        . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;">'
        . '<thead><tr style="background:#f0f0f0;">'
        . '<th style="padding:8px 10px;text-align:center;border:1px solid #ddd;width:40px;">No</th>'
        . '<th style="padding:8px 10px;text-align:left;border:1px solid #ddd;">PO Number</th>'
        . '<th style="padding:8px 10px;text-align:left;border:1px solid #ddd;">Vendor</th>'
        . '<th style="padding:8px 10px;text-align:left;border:1px solid #ddd;">Category</th>'
        . '</tr></thead>'
        . '<tbody>' . $po_table_rows . '</tbody>'
        . '</table>'
        . $more_pos_html
        . '<p style="margin-top:16px;">Silakan akses daftar evaluasi Anda melalui link berikut:<br>'
        . '<a href="' . htmlspecialchars($worklist_url) . '">' . htmlspecialchars($worklist_url) . '</a></p>'
        . '<p>Terima kasih,<br>Tim Procurement</p>'
        . $footer_html
        . $test_contact_html
        . '</body></html>';

    return [$subject, $body];
}

// ── Send plan: selected PO numbers → one entry per requestor ─────────────────

/**
 * Group the selected PO numbers by requestor and decide what is sendable.
 *
 * Requestor resolution mirrors public_eval_v2_worklist.php exactly: a PO belongs
 * to a requestor when the trimmed name appears in requestor_names_json AND that
 * exact name exists in `requestors`. Anything the worklist would not display is
 * skipped here rather than mailed as a dead link.
 *
 * Returns ['plan' => [name => [...]], 'skipped' => [ [po_number, requestor_name, reason] ]].
 */
function ve2_build_send_plan(PDO $pdo, array $po_numbers, string $base_url, string $channel = 'both'): array
{
    $wanted = [];
    foreach ($po_numbers as $po) {
        $po = trim((string)$po);
        if ($po !== '') { $wanted[$po] = true; }
    }
    $wanted = array_keys($wanted);
    if (empty($wanted)) {
        return ['plan' => [], 'skipped' => []];
    }

    $need_email = ($channel === 'email' || $channel === 'both');
    $need_wa    = ($channel === 'wa'    || $channel === 'both');
    $skipped    = [];

    // 1. Taxonomy rows for the selected POs.
    $ph   = implode(',', array_fill(0, count($wanted), '?'));
    $stmt = $pdo->prepare("
        SELECT po_number, vendor_name, taxonomy_level_2, taxonomy_level_3, status, requestor_names_json
          FROM vendor_eval_po_taxonomy
         WHERE po_number IN ({$ph})
    ");
    $stmt->execute($wanted);
    $tax = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $tax[(string)$row['po_number']] = $row;
    }

    // 2. Requestor contacts. manager_username is deliberately not selected.
    $req_rows = $pdo->query("
        SELECT requestor_name, email, whatsapp_number, manager_email, public_token
          FROM requestors
    ")->fetchAll(PDO::FETCH_ASSOC);
    $requestors = [];
    foreach ($req_rows as $r) {
        $requestors[trim((string)$r['requestor_name'])] = $r;
    }

    // 3. Already-submitted (po, requestor) pairs.
    $sub_stmt = $pdo->prepare("
        SELECT po_number, requestor_name
          FROM vendor_eval_submissions
         WHERE status = 'submitted' AND po_number IN ({$ph})
    ");
    $sub_stmt->execute($wanted);
    $submitted = [];
    foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $submitted[$s['po_number'] . '|' . trim((string)$s['requestor_name'])] = true;
    }

    $map       = ve2_load_taxonomy_map($pdo);
    $overrides = ve2_load_overrides($pdo);   // manual override wins over auto routing

    // 4. Classify.
    $plan = [];
    foreach ($wanted as $po) {
        if (!isset($tax[$po])) {
            $skipped[] = ['po_number' => $po, 'requestor_name' => '', 'reason' => 'no Vendor Eval v2 taxonomy row for this PO'];
            continue;
        }
        $row = $tax[$po];

        if ((string)$row['status'] !== 'mapped' && !isset($overrides[$po])) {
            $skipped[] = ['po_number' => $po, 'requestor_name' => '', 'reason' => "PO taxonomy status is '{$row['status']}' (not mapped) and no manual override exists"];
            continue;
        }

        $names = json_decode((string)($row['requestor_names_json'] ?? '[]'), true);
        if (!is_array($names) || empty($names)) {
            $skipped[] = ['po_number' => $po, 'requestor_name' => '', 'reason' => 'no requestor recorded on this PO'];
            continue;
        }

        $seen = [];
        foreach ($names as $raw_name) {
            $name = trim((string)$raw_name);
            if ($name === '' || isset($seen[$name])) { continue; }
            $seen[$name] = true;

            if (!isset($requestors[$name])) {
                // Auto-register missing requestor with requested dummy values
                $dummy_email   = 'sigit.ramadan@kawanlamacorp.com';
                $dummy_wa      = '6281232934734';
                $dummy_manager = 'sigit.ramadan@kawanlamacorp.com';
                $public_token  = bin2hex(random_bytes(16));
                
                try {
                    $ins = $pdo->prepare("INSERT IGNORE INTO requestors (requestor_name, email, whatsapp_number, manager_email, public_token) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$name, $dummy_email, $dummy_wa, $dummy_manager, $public_token]);
                    
                    $requestors[$name] = [
                        'requestor_name'  => $name,
                        'email'           => $dummy_email,
                        'whatsapp_number' => $dummy_wa,
                        'manager_email'   => $dummy_manager,
                        'public_token'    => $public_token
                    ];
                } catch (Exception $e) {
                    $skipped[] = ['po_number' => $po, 'requestor_name' => $name, 'reason' => 'Failed to auto-register requestor: ' . $e->getMessage()];
                    continue;
                }
            }
            if (isset($submitted[$po . '|' . $name])) {
                $skipped[] = ['po_number' => $po, 'requestor_name' => $name, 'reason' => 'evaluation already submitted'];
                continue;
            }

            $r     = $requestors[$name];
            $email = trim((string)($r['email'] ?? ''));
            $wa    = trim((string)($r['whatsapp_number'] ?? ''));
            $token = trim((string)($r['public_token'] ?? ''));

            $blocked = [];
            if ($token === '')               { $blocked[] = 'missing public_token'; }
            if ($need_email && $email === '') { $blocked[] = 'missing email'; }
            if ($need_wa    && $wa    === '') { $blocked[] = 'missing WhatsApp number'; }
            if (!empty($blocked)) {
                $skipped[] = ['po_number' => $po, 'requestor_name' => $name, 'reason' => implode('; ', $blocked)];
                continue;
            }

            $set = $overrides[$po]
                ?? ve2_resolve_set((string)$row['taxonomy_level_2'], $row['taxonomy_level_3'], $map['l3'], $map['l2'], $map['default']);
            if ($set === null) {
                $skipped[] = ['po_number' => $po, 'requestor_name' => $name, 'reason' => 'evaluation form not configured for this PO category'];
                continue;
            }

            if (!isset($plan[$name])) {
                $plan[$name] = [
                    'requestor_name'  => $name,
                    'email'           => $email,
                    'whatsapp_number' => $wa,
                    'manager_email'   => trim((string)($r['manager_email'] ?? '')),
                    'public_token'    => $token,
                    'worklist_url'    => ve2_worklist_url($base_url, $token),
                    'placeholder_wa'  => ($wa === VE2_PLACEHOLDER_WA),
                    'pos'             => [],
                ];
            }
            $plan[$name]['pos'][] = [
                'po_number'        => $po,
                'vendor_name'      => (string)($row['vendor_name'] ?? ''),
                'taxonomy_level_2' => (string)($row['taxonomy_level_2'] ?? ''),
                'taxonomy_level_3' => (string)($row['taxonomy_level_3'] ?? ''),
            ];
        }
    }

    // Sort each requestor's POs by number. Without this the email body — and so
    // its message_hash and idempotency_key — would depend on the order the caller
    // happened to list the POs, letting an identical reminder dodge the dedup.
    foreach ($plan as &$req_entry) {
        usort($req_entry['pos'], fn($a, $b) => strcmp($a['po_number'], $b['po_number']));
    }
    unset($req_entry);

    ksort($plan);
    return ['plan' => $plan, 'skipped' => $skipped];
}

// ── Invite log ───────────────────────────────────────────────────────────────

function ve2_invite_log_exists(PDO $pdo): bool
{
    return count($pdo->query('SHOW TABLES LIKE ' . $pdo->quote(VE2_INVITE_LOG_TABLE))->fetchAll(PDO::FETCH_NUM)) > 0;
}

/** idempotency_key = sha256(run_id|requestor_name|channel|sorted_po_csv|message_hash).
 *  run_id is folded in deliberately: separate reminder runs for the same
 *  requestor/PO-set/body must land as separate audit rows, not collide on
 *  UNIQUE(idempotency_key). Cross-run duplicate suppression is intentionally
 *  not supported — see ve2_send_reminders(). */
function ve2_idempotency_key(string $run_id, string $name, string $channel, array $sorted_po_numbers, string $message_hash): string
{
    return hash('sha256', $run_id . '|' . $name . '|' . $channel . '|' . implode(',', $sorted_po_numbers) . '|' . $message_hash);
}

/** Upsert one audit row (unique on idempotency_key). Never throws. */
function ve2_log_invite(PDO $pdo, array $row): bool
{
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . VE2_INVITE_LOG_TABLE . '
                (run_id, requestor_name, channel, recipient, cc_recipient, pending_po_count,
                 po_numbers_json, message_hash, idempotency_key, status, sent_at, error_message)
             VALUES
                (:run_id,:req,:channel,:recipient,:cc,:cnt,:pos,:mhash,:idem,:status,:sent_at,:err)
             ON DUPLICATE KEY UPDATE
                run_id        = VALUES(run_id),
                status        = VALUES(status),
                sent_at       = VALUES(sent_at),
                recipient     = VALUES(recipient),
                cc_recipient  = VALUES(cc_recipient),
                error_message = VALUES(error_message)'
        );
        $stmt->execute([
            ':run_id'    => $row['run_id'],
            ':req'       => $row['requestor_name'],
            ':channel'   => $row['channel'],
            ':recipient' => $row['recipient'],
            ':cc'        => $row['cc_recipient'] ?? null,
            ':cnt'       => count($row['po_numbers']),
            ':pos'       => json_encode($row['po_numbers'], JSON_UNESCAPED_UNICODE),
            ':mhash'     => $row['message_hash'],
            ':idem'      => $row['idempotency_key'],
            ':status'    => $row['status'],
            ':sent_at'   => $row['status'] === 'sent' ? date('Y-m-d H:i:s') : null,
            ':err'       => $row['error_message'] ?? null,
        ]);
        return true;
    } catch (PDOException $e) {
        error_log('[vendor_eval_v2_sender] invite log write failed for '
            . $row['requestor_name'] . '/' . $row['channel'] . ': ' . ve2_redact_secrets($e->getMessage()));
        return false;
    }
}

// ── Orchestrator ─────────────────────────────────────────────────────────────

/**
 * Group the selected POs by requestor and send one email + one WhatsApp each.
 *
 * $opts:
 *   base_url        (string, required)
 *   channel         'both' | 'email' | 'wa'   default 'both'
 *   preview         (bool)  plan only — no sends, no DB writes. Default false.
 *   cc_manager      (bool)  CC requestors.manager_email. Default false.
 *   max_requestors  (int)   default VE2_MAX_REQUESTORS_PER_RUN
 *   max_pos         (int)   default VE2_MAX_POS_PER_RUN
 *   run_id          (string)
 *   sender_label    (string) run_id prefix when run_id is not supplied
 *
 * A channel is only ever reported as sent when the provider said so. There is
 * no cross-run duplicate suppression: calling this again with the same PO set
 * sends again, by design — see ve2_idempotency_key().
 */
function ve2_send_reminders(PDO $pdo, array $po_numbers, array $opts = []): array
{
    $base_url  = rtrim((string)($opts['base_url'] ?? ''), '/');
    $channel   = (string)($opts['channel'] ?? 'both');
    $preview   = (bool)($opts['preview'] ?? false);
    $cc_mgr    = (bool)($opts['cc_manager'] ?? false);
    $max_reqs  = (int)($opts['max_requestors'] ?? VE2_MAX_REQUESTORS_PER_RUN);
    $max_pos   = (int)($opts['max_pos'] ?? VE2_MAX_POS_PER_RUN);
    $run_id    = (string)($opts['run_id'] ?? ve2_new_run_id((string)($opts['sender_label'] ?? 'web')));

    $result = [
        'ok'               => false,
        'preview'          => $preview,
        'run_id'           => $run_id,
        'base_url'         => $base_url,
        'requestor_count'  => 0,
        'po_count'         => 0,
        'email_sent'       => 0,
        'email_failed'     => 0,
        'wa_sent'          => 0,
        'wa_failed'        => 0,
        'skipped'          => 0,
        'requestors'       => [],
        'skipped_pos'      => [],
        'errors'           => [],
        'warnings'         => [],
    ];

    if (!in_array($channel, ['both', 'email', 'wa'], true)) {
        $result['errors'][] = "Invalid channel '{$channel}'. Use both | email | wa.";
        return $result;
    }
    if ($base_url === '') {
        $result['errors'][] = 'base_url is required.';
        return $result;
    }
    $is_localhost = str_contains($base_url, 'localhost') || str_contains($base_url, '127.0.0.1');
    $url_errs = ve2_base_url_errors($base_url, false);
    if (!empty($url_errs)) {
        $result['errors'] = array_merge($result['errors'], $url_errs);
        return $result;
    }
    if ($is_localhost) {
        $result['warnings'][] = 'Links point at localhost — recipients outside this machine cannot open them.';
    }
    if (count($po_numbers) > $max_pos) {
        $result['errors'][] = 'Too many POs selected (' . count($po_numbers) . " > {$max_pos}). Narrow the selection.";
        return $result;
    }

    $built            = ve2_build_send_plan($pdo, $po_numbers, $base_url, $channel);
    $plan             = $built['plan'];
    $result['skipped_pos'] = $built['skipped'];
    $result['skipped']     = count($built['skipped']);

    $sendable_pos = [];
    foreach ($plan as $req) {
        foreach ($req['pos'] as $p) { $sendable_pos[$p['po_number']] = true; }
    }
    $result['requestor_count'] = count($plan);
    $result['po_count']        = count($sendable_pos);

    if (empty($plan)) {
        $result['errors'][] = 'No sendable PO remains after grouping — nothing was sent.';
        return $result;
    }
    if (count($plan) > $max_reqs) {
        $result['errors'][] = 'Selection resolves to ' . count($plan) . " requestors (limit {$max_reqs}). Narrow the selection.";
        return $result;
    }

    // Preview: describe the plan, touch nothing.
    if ($preview) {
        foreach ($plan as $req) {
            $result['requestors'][] = [
                'requestor_name' => $req['requestor_name'],
                'po_count'       => count($req['pos']),
                'po_numbers'     => array_column($req['pos'], 'po_number'),
                'email'          => ['recipient' => $req['email'], 'status' => 'pending'],
                'wa'             => ['recipient' => ve2_mask_phone($req['whatsapp_number']), 'status' => 'pending'],
                'placeholder_wa' => $req['placeholder_wa'],
            ];
            if ($req['placeholder_wa']) {
                $result['warnings'][] = "{$req['requestor_name']} still uses the placeholder WhatsApp number.";
            }
        }
        $result['ok'] = true;
        return $result;
    }

    // Real send from here on. No audit table ⇒ no send.
    if (!ve2_invite_log_exists($pdo)) {
        $result['errors'][] = VE2_INVITE_LOG_TABLE . ' table is missing — refusing to send without an audit trail.';
        return $result;
    }

    $need_email = ($channel === 'email' || $channel === 'both');
    $need_wa    = ($channel === 'wa'    || $channel === 'both');
    // Vendor Eval WA uses its own Fonnte token/device — never the shared
    // includes/fonnte.php helper that RFQ and other features call.
    if ($need_wa)    { require_once __DIR__ . '/vendor_eval_v2_fonnte.php'; }
    if ($need_email) { require_once __DIR__ . '/mailer.php'; }

    foreach ($plan as $req) {
        $name    = $req['requestor_name'];
        $pos     = $req['pos'];
        $pending = count($pos);
        $po_nums = array_column($pos, 'po_number');
        sort($po_nums);

        $entry = [
            'requestor_name' => $name,
            'po_count'       => $pending,
            'po_numbers'     => $po_nums,
            'email'          => ['recipient' => $req['email'], 'status' => 'skipped', 'error' => ''],
            'wa'             => ['recipient' => ve2_mask_phone($req['whatsapp_number']), 'status' => 'skipped', 'error' => ''],
            'placeholder_wa' => $req['placeholder_wa'],
        ];

        // ── Email ────────────────────────────────────────────────────────────
        if ($need_email) {
            [$subject, $body] = ve2_build_email($name, $pending, $pos, $req['worklist_url'], [
                'subject'  => "Vendor Evaluation Request - {$pending} PO Pending",
                'max_rows' => 0,   // every selected PO for this requestor
            ]);
            $hash = hash('sha256', $body);
            $idem = ve2_idempotency_key($run_id, $name, 'email', $po_nums, $hash);
            $cc   = ($cc_mgr && $req['manager_email'] !== '') ? $req['manager_email'] : null;

            $ok = sendTenderEmail($req['email'], $name, $subject, $body, true, $cc);
            if ($ok === true) {
                $entry['email']['status'] = 'sent';
                $result['email_sent']++;
            } else {
                $entry['email']['status'] = 'failed';
                $entry['email']['error']  = 'mailer rejected the message';
                $result['email_failed']++;
            }
            ve2_log_invite($pdo, [
                'run_id'          => $run_id,
                'requestor_name'  => $name,
                'channel'         => 'email',
                'recipient'       => $req['email'],
                'cc_recipient'    => $cc,
                'po_numbers'      => $po_nums,
                'message_hash'    => $hash,
                'idempotency_key' => $idem,
                'status'          => $ok === true ? 'sent' : 'failed',
                'error_message'   => $ok === true ? null : 'sendTenderEmail returned false',
            ]);
        }

        // ── WhatsApp ─────────────────────────────────────────────────────────
        if ($need_wa) {
            $wa_msg = ve2_build_wa_message($name, $pending, $po_nums, $req['worklist_url']);
            $hash   = hash('sha256', $wa_msg);
            $idem   = ve2_idempotency_key($run_id, $name, 'wa', $po_nums, $hash);

            $wa = ve2_send_fonnte_wa($req['whatsapp_number'], $wa_msg);
            $ok = is_array($wa) && !empty($wa['ok']);
            if ($ok) {
                $entry['wa']['status'] = 'sent';
                $result['wa_sent']++;
            } else {
                $entry['wa']['status'] = 'failed';
                $entry['wa']['error']  = is_array($wa) ? (string)($wa['reason'] ?? 'provider did not confirm delivery')
                                                       : 'provider did not confirm delivery';
                $result['wa_failed']++;
            }
            ve2_log_invite($pdo, [
                'run_id'          => $run_id,
                'requestor_name'  => $name,
                'channel'         => 'wa',
                'recipient'       => $req['whatsapp_number'],
                'cc_recipient'    => null,
                'po_numbers'      => $po_nums,
                'message_hash'    => $hash,
                'idempotency_key' => $idem,
                'status'          => $ok ? 'sent' : 'failed',
                'error_message'   => $ok ? null : substr($entry['wa']['error'], 0, 500),
            ]);
        }

        if ($req['placeholder_wa']) {
            $result['warnings'][] = "{$name} still uses the placeholder WhatsApp number.";
        }
        $result['requestors'][] = $entry;
    }

    // "ok" means nothing failed.
    $result['ok'] = ($result['email_failed'] + $result['wa_failed']) === 0;
    return $result;
}
