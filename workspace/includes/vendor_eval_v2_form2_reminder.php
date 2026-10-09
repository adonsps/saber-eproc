<?php
// workspace/includes/vendor_eval_v2_form2_reminder.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail — New Store) reminder engine.
//
// SINGLE SOURCE OF TRUTH for the Form 2 reminder send logic. Both
// workspace/cron/vendor_eval_v2_form2_reminder_cron.php (daily, automatic)
// and workspace/pages/ajax_send_form2_reminder.php (manual, Category-Manager
// "Shoot Reminder" button) call ve2_form2_send_reminders() — the logic is
// implemented exactly once.
//
// *** LIVE SEND requires preview=false AND opts['confirm'] === VE2_FORM2_SEND_CONFIRM ***
// preview=true is the default and safe path — no send functions are ever
// touched on that path, matching every other caller in this codebase.
// CURRENT STATE (2026-07-30, corrected from a stale note that used to say
// both callers still hard-code preview=true):
//   - workspace/pages/ajax_send_form2_reminder.php (manual "Shoot Reminder"
//     button) hard-codes preview=false and HAS been used for a real send —
//     see vendor_eval_v2_invite_log run_id vef2_20260729_073708_manual_*.
//   - workspace/cron/vendor_eval_v2_form2_reminder_cron.php (daily cron)
//     still hard-codes its live-intent flag false, AND additionally requires
//     the vendor_eval_v2_form2_send_settings kill switch (default OFF) to be
//     on — see workspace/includes/vendor_eval_v2_form2_kill_switch.php. Going
//     live for the cron is a separate, explicit approval step.
// A send-volume circuit breaker (VE2_FORM2_MAX_DUE_POS, default 50) sits in
// ve2_form2_send_reminders() itself below, so it protects both callers
// uniformly. Per CLAUDE.md: "Never send WA/email unless explicitly
// requested" — the confirm-token guard is the enforcement mechanism for that
// rule at the function-call boundary.
//
// Why this file does NOT reuse vendor_eval_v2_sender.php's
// ve2_build_send_plan() / ve2_resolve_set() / ve2_load_taxonomy_map():
// those implement FORM 1's per-requestor routing — group selected PO numbers
// by the names in vendor_eval_po_taxonomy.requestor_names_json, then pick a
// question set via the L3 -> L2 -> Default taxonomy chain. Neither applies
// to Form 2:
//   - Recipients are the FIXED vendor_eval_v2_form2_team_contacts roster —
//     ALL of them, for every due PO, regardless of brand/BU. There is no
//     per-requestor grouping.
//   - The question set is FORCED to VE_PROJECT_RETAIL_V1
//     (ve2_load_forced_set() in vendor_eval_v2_form2_team_engine.php) —
//     never resolved through the L3/L2/Default map.
//   - Most Form 2 POs are pre-GR construction POs and have NO
//     vendor_eval_po_taxonomy row at all (that table only ever gets a row
//     once gr_date is populated — see vendor_eval_v2_form2_team_engine.php).
//     Routing a Form 2 PO through ve2_build_send_plan() would skip nearly
//     every real candidate as "no taxonomy row", and for the rare PO that DID
//     have one, the L3/L2/Default resolver could silently pick a different
//     question set than the one the form actually forces. This divergence
//     was flagged in review weeks ago and never exercised end-to-end because
//     Form 1 never routed a Form 2 PO through it. It is "fixed" here simply
//     by never sending Form 2 POs through that resolver — this file's own
//     candidate scan / classification never touches vendor_eval_po_taxonomy
//     as a routing source (only as a display-only label, same as
//     vendor_eval_v2_form2_team_engine.php does).
//
// What IS reused from vendor_eval_v2_sender.php (generic, routing-free):
// ve2_mask_phone(), ve2_new_run_id(), ve2_base_url_errors(),
// ve2_invite_log_exists(), VE2_INVITE_LOG_TABLE. The audit table itself
// (vendor_eval_v2_invite_log) is also reused as-is — Form 2 rows are
// distinguished purely by a run_id prefix (VE2_FORM2_REMINDER_LOG_PREFIX),
// no schema change.
//
// PURE LIBRARY on require: no DB connection, no output, no side effects.
// fonnte.php / mailer.php are required lazily, only inside the live-send
// branch of ve2_form2_send_reminders() (same discipline as
// vendor_eval_v2_sender.php's ve2_send_reminders()) — a plain require_once
// of this file, or any preview=true call, can never send anything.

require_once __DIR__ . '/vendor_eval_v2_sender.php';            // generic helpers only — see note above
require_once __DIR__ . '/vendor_eval_v2_form2_eligibility.php'; // ve2_form2_po_eligibility()
require_once __DIR__ . '/vendor_eval_v2_form2_team_engine.php'; // ve2_form2_final_grade()
require_once __DIR__ . '/vendor_eval_v2_form2_worklist_token.php'; // ve2_form2_worklist_token_get()

/** Send-volume circuit breaker: if a single run would trigger sends for more
 *  than this many due POs (due_first + due_weekly combined), abort the whole
 *  run — no roster query, no message building, no sends on either channel —
 *  rather than trust the eligibility scan. Guards against an eligibility-logic
 *  bug (e.g. a bad pgroup pattern or a missing WHERE clause) silently
 *  blasting far more people/POs than intended; a real, legitimate 51+-PO day
 *  is expected to be rare enough that this should be raised deliberately, not
 *  routinely. Applies in both preview and live mode so the breaker's
 *  behavior is visible/testable via dry-run, not just discovered at send time. */
if (!defined('VE2_FORM2_MAX_DUE_POS')) { define('VE2_FORM2_MAX_DUE_POS', 1000); }

if (!defined('VE2_FORM2_REMINDER_CADENCE_DAYS')) { define('VE2_FORM2_REMINDER_CADENCE_DAYS', 7); }
/** run_id prefix used to scope dedupe/cadence queries to Form 2 rows only, inside the shared vendor_eval_v2_invite_log table. */
if (!defined('VE2_FORM2_REMINDER_LOG_PREFIX'))   { define('VE2_FORM2_REMINDER_LOG_PREFIX', 'vef2'); }
/** Callers must echo this back in $opts['confirm'] to unlock preview=false. Prevents an
 *  accidental preview=false call (typo, bad default, copy-pasted opts array) from sending
 *  real WA/email to the whole Form 2 team roster. */
if (!defined('VE2_FORM2_SEND_CONFIRM')) {
    define('VE2_FORM2_SEND_CONFIRM', 'I_UNDERSTAND_THIS_SENDS_REAL_WA_AND_EMAIL_TO_FORM2_TEAM_CONTACTS');
}

// ── Worklist link ────────────────────────────────────────────────────────────

/** The one Form 2 worklist link shape (single global token). Null if no token row exists yet. */
function ve2_form2_worklist_link(PDO $pdo, string $base_url): ?string
{
    $token = ve2_form2_worklist_token_get($pdo);
    if ($token === null) {
        return null;
    }
    return rtrim($base_url, '/') . '/public_eval_v2_project_worklist.php?token=' . urlencode($token);
}

// ── Candidate scan ───────────────────────────────────────────────────────────

/**
 * Every currently Form-2-eligible PO, with display detail. Mirrors the exact
 * candidate source + eligibility gate used by
 * public_eval_v2_project_worklist.php (PGroup pattern + is_new_store = 1,
 * then ve2_form2_po_eligibility() per candidate) so the reminder engine and
 * the worklist page can never disagree on who is eligible.
 */
function ve2_form2_scan_eligible_pos(PDO $pdo): array
{
    $po_stmt = $pdo->query("
        SELECT p.po_number, MAX(p.vendor_name) AS vendor_name, MAX(p.gr_date) AS gr_date,
               MAX(p.p_group) AS p_group
          FROM prs p
         WHERE p.po_number IS NOT NULL AND p.po_number != ''
           AND p.is_new_store = 1
           AND (p.p_group = 'A77' OR p.p_group REGEXP '^[A-Z]97$')
         GROUP BY p.po_number
         ORDER BY p.po_number
    ");
    $candidates = $po_stmt->fetchAll(PDO::FETCH_ASSOC);

    $eligible = [];
    foreach ($candidates as $c) {
        $elig = ve2_form2_po_eligibility($pdo, (string)$c['po_number']);
        if ($elig['eligible']) {
            $eligible[(string)$c['po_number']] = [
                'po_number'          => (string)$c['po_number'],
                'vendor_name'        => $c['vendor_name'],
                'p_group'            => $c['p_group'],
                'sites'              => array_keys($elig['sites']),
                'store_opening_date' => $elig['max_open_date'],
            ];
        }
    }
    return $eligible;
}

// ── Reminder classification (first-send vs weekly follow-up) ─────────────────

/**
 * JSON literal for matching one PO number inside a po_numbers_json array via
 * JSON_CONTAINS(po_numbers_json, CAST(? AS JSON)).
 *
 * po_numbers_json is built from json_encode(array_keys(...)) elsewhere in
 * this file (see ve2_form2_send_reminders()) — and PHP silently casts any
 * array key matching a canonical decimal-integer pattern (e.g. "4200274187")
 * to a real int, so those keys get JSON-encoded as bare numbers, not quoted
 * strings. JSON_CONTAINS is type-strict: JSON_QUOTE('4200274187') ("4200274187",
 * a JSON string) never matches the stored JSON number 4200274187 — this was
 * the bug (silently broke the 7-day cadence dedupe: every PO reclassified as
 * first-send forever, no matter how many times it was actually sent).
 *
 * Fix: reproduce PHP's own array-key cast rule directly — put $po_number
 * through it and JSON-encode whichever type comes out — so this always
 * matches however the same value would have been stored.
 */
function ve2_form2_po_number_json_literal(string $po_number): string
{
    $probe = [$po_number => true];
    $key   = array_key_first($probe);
    return is_int($key) ? (string)$key : json_encode($po_number, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Whether/why one PO is due a Form 2 reminder today, using
 * vendor_eval_v2_invite_log as the sole source of truth for "has this PO
 * been reminded before" — robust to a missed cron day (catches up on the
 * next run rather than permanently skipping the PO), unlike trying to infer
 * an exact eligibility-transition date from Store Opening Date edits.
 *
 * Returns ['due' => bool, 'kind' => 'first'|'weekly'|null, 'reason' => string,
 *          'last_sent_at' => ?string, 'days_since_last' => ?int].
 */
function ve2_form2_classify_po(PDO $pdo, string $po_number, int $cadence_days = VE2_FORM2_REMINDER_CADENCE_DAYS): array
{
    $out = [
        'due' => true, 'kind' => 'first',
        'reason' => 'no prior Form 2 reminder on record for this PO',
        'last_sent_at' => null, 'days_since_last' => null,
    ];

    if (!ve2_invite_log_exists($pdo)) {
        return $out; // table missing — treat as first-send (caller surfaces a warning)
    }

    $stmt = $pdo->prepare("
        SELECT MAX(sent_at) AS last_sent_at
          FROM " . VE2_INVITE_LOG_TABLE . "
         WHERE run_id LIKE ? AND status = 'sent' AND JSON_CONTAINS(po_numbers_json, CAST(? AS JSON))
    ");
    $stmt->execute([VE2_FORM2_REMINDER_LOG_PREFIX . '%', ve2_form2_po_number_json_literal($po_number)]);
    $last_sent_at = $stmt->fetchColumn();

    if ($last_sent_at === null || $last_sent_at === false) {
        return $out; // no prior sent row → first-send
    }

    $tz    = new DateTimeZone('Asia/Jakarta');
    $today = new DateTime('now', $tz);
    $last  = new DateTime($last_sent_at, $tz);
    $days_since = (int)$today->diff($last)->days;

    $out['last_sent_at']     = $last_sent_at;
    $out['days_since_last']  = $days_since;

    if ($last->format('Y-m-d') === $today->format('Y-m-d')) {
        $out['due']    = false;
        $out['kind']   = null;
        $out['reason'] = 'already reminded today';
        return $out;
    }
    if ($days_since < $cadence_days) {
        $out['due']    = false;
        $out['kind']   = null;
        $out['reason'] = "waiting for weekly cadence ({$days_since}/{$cadence_days} days since last reminder)";
        return $out;
    }

    $out['kind']   = 'weekly';
    $out['reason'] = "weekly follow-up due ({$days_since} days since last reminder)";
    return $out;
}

/**
 * Every eligible PO, classified into due_first / due_weekly / not_due,
 * skipping POs where both teams have already submitted (no further
 * reminders needed, ever). One batched submission-status query — no N+1.
 */
function ve2_form2_build_due_list(PDO $pdo, int $cadence_days = VE2_FORM2_REMINDER_CADENCE_DAYS): array
{
    $candidates = ve2_form2_scan_eligible_pos($pdo);

    $due_first  = [];
    $due_weekly = [];
    $not_due    = [];
    $skipped_complete = 0;

    if (!empty($candidates)) {
        $po_list = array_keys($candidates);
        $ph = implode(',', array_fill(0, count($po_list), '?'));
        $sub_stmt = $pdo->prepare("
            SELECT po_number, team, total_score, score_band, submitted_at
              FROM vendor_eval_submissions
             WHERE po_number IN ($ph) AND team IN ('ops','project') AND status = 'submitted'
        ");
        $sub_stmt->execute($po_list);
        $subs_by_po = [];
        foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subs_by_po[$row['po_number']][$row['team']] = $row;
        }

        foreach ($candidates as $po_number => $c) {
            $ops     = $subs_by_po[$po_number]['ops']     ?? null;
            $project = $subs_by_po[$po_number]['project'] ?? null;
            if (ve2_form2_final_grade($ops, $project) !== null) {
                $skipped_complete++;
                continue;
            }

            $cls = ve2_form2_classify_po($pdo, $po_number, $cadence_days);
            $row = $c + ['last_sent_at' => $cls['last_sent_at'], 'days_since_last' => $cls['days_since_last']];

            if (!$cls['due']) {
                $not_due[$po_number] = $cls['reason'];
                continue;
            }
            if ($cls['kind'] === 'first') {
                $due_first[$po_number] = $row;
            } else {
                $due_weekly[$po_number] = $row;
            }
        }
    }

    return [
        'due_first'        => $due_first,
        'due_weekly'       => $due_weekly,
        'not_due'          => $not_due,
        'skipped_complete' => $skipped_complete,
        'candidate_count'  => count($candidates),
    ];
}

// ── Message builders ─────────────────────────────────────────────────────────

/** Cap on PO lines listed inline in the WA message before falling back to
 *  "...dan N PO lainnya". WhatsApp/Fonnte impose no single documented hard
 *  character limit, but very long messages risk provider-side truncation or
 *  splitting; 10 lines keeps the message comfortably short while still being
 *  useful at a glance — the worklist link always has the full list. */
if (!defined('VE2_FORM2_WA_MAX_POS')) { define('VE2_FORM2_WA_MAX_POS', 10); }

function ve2_form2_build_wa_message(string $name, array $due_first, array $due_weekly, string $worklist_url): string
{
    $lines = [];
    foreach ($due_first as $po => $c) {
        $site = implode(', ', $c['sites']);
        $lines[] = "  • {$po} — " . ($c['vendor_name'] !== '' ? $c['vendor_name'] : '(vendor not specified)') . " ({$site}) [BARU]";
    }
    foreach ($due_weekly as $po => $c) {
        $site = implode(', ', $c['sites']);
        $lines[] = "  • {$po} — " . ($c['vendor_name'] !== '' ? $c['vendor_name'] : '(vendor not specified)') . " ({$site}) [SUSULAN]";
    }

    $total  = count($lines);
    $shown  = ($total > VE2_FORM2_WA_MAX_POS) ? array_slice($lines, 0, VE2_FORM2_WA_MAX_POS) : $lines;
    $hidden = $total - count($shown);

    $pos_text = implode("\n", $shown);
    if ($hidden > 0) {
        $pos_text .= "\n  ... dan {$hidden} PO lainnya. Lihat worklist lengkap di link di bawah.";
    }

    return "Halo *{$name}*,\n\n"
        . "Berikut daftar PO Project Retail (New Store) yang membutuhkan evaluasi vendor dari tim Anda:\n\n"
        . "{$pos_text}\n\n"
        . "Silakan buka worklist evaluasi melalui link berikut:\n{$worklist_url}\n\n"
        . "Mohon diselesaikan sesegera mungkin.";
}

/** Sanity ceiling on PO rows rendered in the email table before falling back to a
 *  single "+N more" summary row. Email has a real HTML table (not WhatsApp's plain-text
 *  line limit), so this is a soft guard against something absurd, not expected to
 *  trigger in normal use — kept far more generous than VE2_FORM2_WA_MAX_POS. */
if (!defined('VE2_FORM2_EMAIL_MAX_POS')) { define('VE2_FORM2_EMAIL_MAX_POS', 25); }

/** Returns [subject, html_body]. */
function ve2_form2_build_email(string $name, array $due_first, array $due_weekly, string $worklist_url): array
{
    $subject = 'Vendor Evaluation Reminder — Project Retail (New Store)';

    $entries = [];
    foreach ($due_first as $po => $c) {
        $entries[] = ['po' => $po, 'c' => $c, 'badge' => 'New', 'badge_color' => '#4f46e5'];
    }
    foreach ($due_weekly as $po => $c) {
        $entries[] = ['po' => $po, 'c' => $c, 'badge' => 'Follow-up', 'badge_color' => '#d97706'];
    }

    $total  = count($entries);
    $shown  = ($total > VE2_FORM2_EMAIL_MAX_POS) ? array_slice($entries, 0, VE2_FORM2_EMAIL_MAX_POS) : $entries;
    $hidden = $total - count($shown);

    $rows = '';
    foreach ($shown as $e) {
        $po     = $e['po'];
        $c      = $e['c'];
        $site   = htmlspecialchars(implode(', ', $c['sites']));
        $opened = $c['store_opening_date'] !== null ? date('d M Y', strtotime((string)$c['store_opening_date'])) : '-';
        $rows .= '<tr>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;font-family:monospace;">' . htmlspecialchars((string)$po) . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;">' . htmlspecialchars((string)($c['vendor_name'] ?? '')) . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;">' . $site . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;">' . htmlspecialchars($opened) . '</td>'
            . '<td style="padding:6px 10px;border:1px solid #ddd;text-align:center;">'
            . '<span style="background:' . $e['badge_color'] . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;">' . $e['badge'] . '</span>'
            . '</td></tr>';
    }
    if ($hidden > 0) {
        $rows .= '<tr><td colspan="5" style="padding:8px 10px;border:1px solid #ddd;text-align:center;font-style:italic;color:#555;">'
            . '... dan ' . $hidden . ' PO lainnya. Lihat worklist lengkap di link di bawah.'
            . '</td></tr>';
    }

    $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:sans-serif;font-size:14px;color:#333;max-width:700px;margin:0 auto;padding:20px;">'
        . '<p>Dear <strong>' . htmlspecialchars($name) . '</strong>,</p>'
        . '<p>The following Project Retail (New Store) purchase orders need a vendor evaluation from your team:</p>'
        . '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:13px;">'
        . '<thead><tr style="background:#f0f0f0;">'
        . '<th style="padding:8px 10px;border:1px solid #ddd;text-align:left;">PO Number</th>'
        . '<th style="padding:8px 10px;border:1px solid #ddd;text-align:left;">Vendor</th>'
        . '<th style="padding:8px 10px;border:1px solid #ddd;text-align:left;">Site</th>'
        . '<th style="padding:8px 10px;border:1px solid #ddd;text-align:left;">Store Opening Date</th>'
        . '<th style="padding:8px 10px;border:1px solid #ddd;text-align:center;">Status</th>'
        . '</tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<p style="margin-top:16px;">Please open the shared worklist to submit your evaluation:<br>'
        . '<a href="' . htmlspecialchars($worklist_url) . '">' . htmlspecialchars($worklist_url) . '</a></p>'
        . '<p>Terima kasih,<br>Tim Procurement</p>'
        . '</body></html>';

    return [$subject, $body];
}

// ── Orchestrator (the ONE shared send function) ───────────────────────────────

/**
 * Scan for due Form 2 POs, classify each (first-send / weekly follow-up),
 * and either preview or actually notify the full
 * vendor_eval_v2_form2_team_contacts roster. preview=true (the default) never
 * touches sendTenderEmail()/ve2_send_fonnte_wa(). preview=false additionally
 * requires opts['confirm'] === VE2_FORM2_SEND_CONFIRM — without it, no send
 * function is called and no error-free "nothing to do" path can silently
 * become a live send. See file header for why both current callers still
 * hard-code preview=true.
 *
 * $opts:
 *   base_url      (string, required)
 *   preview       (bool)   default true. false = live send (needs 'confirm').
 *   confirm       (string) required, exactly VE2_FORM2_SEND_CONFIRM, when preview=false
 *   channel       'both' | 'email' | 'wa'   default 'both'
 *   run_label     (string) e.g. 'cron' | 'manual'
 *   cadence_days  (int)    default VE2_FORM2_REMINDER_CADENCE_DAYS
 */
function ve2_form2_send_reminders(PDO $pdo, array $opts = []): array
{
    $base_url  = rtrim((string)($opts['base_url'] ?? ''), '/');
    $preview   = array_key_exists('preview', $opts) ? (bool)$opts['preview'] : true;
    $channel   = (string)($opts['channel'] ?? 'both');
    $run_label = (string)($opts['run_label'] ?? 'run');
    $cadence   = (int)($opts['cadence_days'] ?? VE2_FORM2_REMINDER_CADENCE_DAYS);

    $result = [
        'ok'               => false,
        'preview'          => $preview,
        'run_id'           => '',
        'base_url'         => $base_url,
        'worklist_url'     => null,
        'cadence_days'     => $cadence,
        'due_first'        => [],
        'due_weekly'       => [],
        'not_due'          => [],
        'skipped_complete' => 0,
        'candidate_count'  => 0,
        'circuit_breaker_tripped' => false,
        'recipient_count'  => 0,
        'recipients'       => [],
        'message_preview'  => null,
        'email_sent'       => 0,
        'email_failed'     => 0,
        'wa_sent'          => 0,
        'wa_failed'        => 0,
        'errors'           => [],
        'warnings'         => [],
    ];

    if (!$preview && (string)($opts['confirm'] ?? '') !== VE2_FORM2_SEND_CONFIRM) {
        $result['errors'][] = "Live sending refused: opts['confirm'] must exactly equal VE2_FORM2_SEND_CONFIRM. Nothing was sent.";
        return $result;
    }

    if (!in_array($channel, ['both', 'email', 'wa'], true)) {
        $result['errors'][] = "Invalid channel '{$channel}'. Use both | email | wa.";
        return $result;
    }
    if ($base_url === '') {
        $result['errors'][] = 'base_url is required.';
        return $result;
    }
    $url_errs = ve2_base_url_errors($base_url, false);
    if (!empty($url_errs)) {
        $result['errors'] = array_merge($result['errors'], $url_errs);
        return $result;
    }
    if (str_contains($base_url, 'localhost') || str_contains($base_url, '127.0.0.1')) {
        $result['warnings'][] = 'Links point at localhost — recipients outside this machine cannot open them.';
    }

    $invite_log_exists = ve2_invite_log_exists($pdo);
    if (!$invite_log_exists) {
        if (!$preview) {
            $result['errors'][] = VE2_INVITE_LOG_TABLE . ' table is missing — refusing to send without an audit trail.';
            return $result;
        }
        $result['warnings'][] = VE2_INVITE_LOG_TABLE . ' table is missing — dedupe/cadence classification cannot see prior sends; every due PO will classify as first-send.';
    }

    $result['run_id'] = VE2_FORM2_REMINDER_LOG_PREFIX . '_' . ve2_new_run_id($run_label);

    $worklist_url = ve2_form2_worklist_link($pdo, $base_url);
    if ($worklist_url === null) {
        $result['errors'][] = 'Form 2 shared worklist link has not been generated yet — generate it on the Requestor Contacts tab before enabling reminders.';
        return $result;
    }
    $result['worklist_url'] = $worklist_url;

    $due = ve2_form2_build_due_list($pdo, $cadence);
    $result['due_first']        = $due['due_first'];
    $result['due_weekly']       = $due['due_weekly'];
    $result['not_due']          = $due['not_due'];
    $result['skipped_complete'] = $due['skipped_complete'];
    $result['candidate_count']  = $due['candidate_count'];

    if (empty($due['due_first']) && empty($due['due_weekly'])) {
        $result['ok'] = true; // nothing due is a normal, successful outcome
        return $result;
    }

    $due_total = count($due['due_first']) + count($due['due_weekly']);
    if ($due_total > VE2_FORM2_MAX_DUE_POS) {
        $result['circuit_breaker_tripped'] = true;
        $result['errors'][] = sprintf(
            'Circuit breaker tripped: %d PO(s) due for a Form 2 reminder this run, which exceeds the safety '
            . 'ceiling of %d. Aborting — no roster was queried and nothing was sent on either channel. This '
            . 'guards against an eligibility-logic bug blasting far more recipients than intended; if %d is '
            . 'genuinely legitimate today, raise VE2_FORM2_MAX_DUE_POS deliberately rather than bypassing this check.',
            $due_total, VE2_FORM2_MAX_DUE_POS, $due_total
        );
        return $result;
    }

    $roster = $pdo->query("
        SELECT name, email, whatsapp_number FROM vendor_eval_v2_form2_team_contacts ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
    if (empty($roster)) {
        $result['errors'][] = 'No Form 2 team contacts configured — add contacts on the Requestor Contacts tab first.';
        return $result;
    }
    $result['recipient_count'] = count($roster);

    $need_email = ($channel === 'email' || $channel === 'both');
    $need_wa    = ($channel === 'wa'    || $channel === 'both');

    $po_nums = array_keys($due['due_first'] + $due['due_weekly']);
    sort($po_nums);

    // One representative message sample — the template is identical for every
    // recipient bar the greeting name, so a single sample fully previews/documents
    // what every recipient receives (or received, for a live run).
    $sample_name = (string)($roster[0]['name'] ?? 'Team');
    $wa_msg = ve2_form2_build_wa_message($sample_name, $due['due_first'], $due['due_weekly'], $worklist_url);
    [$subject, $email_body] = ve2_form2_build_email($sample_name, $due['due_first'], $due['due_weekly'], $worklist_url);
    $result['message_preview'] = [
        'sample_recipient' => $sample_name,
        'wa_message'        => $wa_msg,
        'email_subject'     => $subject,
        'email_body_html'   => $email_body,
    ];

    // ── Preview: describe the plan, touch nothing. ──────────────────────────
    if ($preview) {
        foreach ($roster as $r) {
            $name  = trim((string)$r['name']);
            $email = trim((string)($r['email'] ?? ''));
            $wa    = trim((string)($r['whatsapp_number'] ?? ''));

            $entry = [
                'name'  => $name,
                'email' => ['recipient' => $email, 'status' => 'not requested'],
                'wa'    => ['recipient' => ve2_mask_phone($wa), 'status' => 'not requested'],
            ];
            if ($need_email) {
                $entry['email']['status'] = $email !== '' ? 'would_send' : 'skipped (no email on file)';
            }
            if ($need_wa) {
                $entry['wa']['status'] = $wa !== '' ? 'would_send' : 'skipped (no WhatsApp number on file)';
            }
            $result['recipients'][] = $entry;
        }

        $result['ok'] = true;
        return $result;
    }

    // ── Live send from here on. Every dispatch attempt is audited. ─────────
    if ($need_wa)    { require_once __DIR__ . '/vendor_eval_v2_fonnte.php'; }
    if ($need_email) { require_once __DIR__ . '/mailer.php'; }

    foreach ($roster as $r) {
        $name  = trim((string)$r['name']);
        $email = trim((string)($r['email'] ?? ''));
        $wa    = trim((string)($r['whatsapp_number'] ?? ''));

        $entry = [
            'name'  => $name,
            'email' => ['recipient' => $email, 'status' => 'skipped', 'error' => ''],
            'wa'    => ['recipient' => ve2_mask_phone($wa), 'status' => 'skipped', 'error' => ''],
        ];

        if ($need_email) {
            if ($email === '') {
                $entry['email']['status'] = 'skipped (no email on file)';
            } else {
                [$recipient_subject, $recipient_body] = ve2_form2_build_email($name, $due['due_first'], $due['due_weekly'], $worklist_url);
                $hash = hash('sha256', $recipient_body);
                $idem = ve2_idempotency_key($result['run_id'], $name, 'email', $po_nums, $hash);

                $ok = sendTenderEmail($email, $name, $recipient_subject, $recipient_body, true);
                if ($ok === true) {
                    $entry['email']['status'] = 'sent';
                    $result['email_sent']++;
                } else {
                    $entry['email']['status'] = 'failed';
                    $entry['email']['error']  = 'mailer rejected the message';
                    $result['email_failed']++;
                }
                ve2_log_invite($pdo, [
                    'run_id'          => $result['run_id'],
                    'requestor_name'  => $name,
                    'channel'         => 'email',
                    'recipient'       => $email,
                    'cc_recipient'    => null,
                    'po_numbers'      => $po_nums,
                    'message_hash'    => $hash,
                    'idempotency_key' => $idem,
                    'status'          => $ok === true ? 'sent' : 'failed',
                    'error_message'   => $ok === true ? null : 'sendTenderEmail returned false',
                ]);
            }
        }

        if ($need_wa) {
            if ($wa === '') {
                $entry['wa']['status'] = 'skipped (no WhatsApp number on file)';
            } else {
                $recipient_wa_msg = ve2_form2_build_wa_message($name, $due['due_first'], $due['due_weekly'], $worklist_url);
                $hash = hash('sha256', $recipient_wa_msg);
                $idem = ve2_idempotency_key($result['run_id'], $name, 'wa', $po_nums, $hash);

                $wa_res = ve2_send_fonnte_wa($wa, $recipient_wa_msg);
                $ok = is_array($wa_res) && !empty($wa_res['ok']);
                if ($ok) {
                    $entry['wa']['status'] = 'sent';
                    $result['wa_sent']++;
                } else {
                    $entry['wa']['status'] = 'failed';
                    $entry['wa']['error']  = is_array($wa_res) ? (string)($wa_res['reason'] ?? 'provider did not confirm delivery')
                                                                : 'provider did not confirm delivery';
                    $result['wa_failed']++;
                }
                ve2_log_invite($pdo, [
                    'run_id'          => $result['run_id'],
                    'requestor_name'  => $name,
                    'channel'         => 'wa',
                    'recipient'       => $wa,
                    'cc_recipient'    => null,
                    'po_numbers'      => $po_nums,
                    'message_hash'    => $hash,
                    'idempotency_key' => $idem,
                    'status'          => $ok ? 'sent' : 'failed',
                    'error_message'   => $ok ? null : substr($entry['wa']['error'], 0, 500),
                ]);
            }
        }

        $result['recipients'][] = $entry;
    }

    // "ok" means nothing failed.
    $result['ok'] = ($result['email_failed'] + $result['wa_failed']) === 0;
    return $result;
}

/**
 * WhatsApp-ONLY re-send of the Form 2 reminder, deliberately IGNORING the 7-day
 * cadence. Single source of truth for both the browser endpoints
 * (ajax_preview_resend_form2_wa.php / ajax_resend_form2_wa.php) and the CLI
 * (tools/resend_vendor_eval_v2_form2_wa.php).
 *
 * Why it ignores cadence: when a reminder run's email succeeds but WhatsApp
 * fails, the email rows are logged status='sent', so ve2_form2_classify_po()
 * marks those POs "already reminded" and ve2_form2_send_reminders() will not
 * retry the WhatsApp for 7 days. This function bridges that gap. It NEVER
 * touches email.
 *
 * Idempotency (this is the "won't send twice" guarantee): a recipient who
 * already has a status='sent' WA row with the SAME message body (message_hash)
 * is skipped. So calling this repeatedly never double-sends the same WhatsApp;
 * the original status='failed' rows never block a genuine retry. Because the
 * message body is deterministic (same PO set + same worklist link => same
 * hash), the first successful send makes every later call a no-op skip.
 *
 * $opts:
 *   base_url       (string, required)
 *   preview        (bool)   default true. false = live send (needs 'confirm').
 *   confirm        (string) required, == VE2_FORM2_SEND_CONFIRM, when preview=false
 *   max_pos        (int)    default VE2_FORM2_MAX_DUE_POS (circuit breaker)
 *   only_recipient (string) optional exact contact name — single-recipient test
 *   run_label      (string) default 'wa_resend'
 */
function ve2_form2_resend_wa(PDO $pdo, array $opts = []): array
{
    $base_url  = rtrim((string)($opts['base_url'] ?? ''), '/');
    $preview   = array_key_exists('preview', $opts) ? (bool)$opts['preview'] : true;
    $max_pos   = (int)($opts['max_pos'] ?? VE2_FORM2_MAX_DUE_POS);
    $only      = isset($opts['only_recipient']) ? trim((string)$opts['only_recipient']) : '';
    $run_label = (string)($opts['run_label'] ?? 'wa_resend');

    $result = [
        'ok' => false, 'preview' => $preview, 'run_id' => '',
        'base_url' => $base_url, 'worklist_url' => null,
        'po_count' => 0, 'po_numbers' => [],
        'recipient_count' => 0, 'recipients' => [],
        'wa_sent' => 0, 'wa_failed' => 0, 'wa_skipped' => 0, 'would_send' => 0,
        'errors' => [], 'warnings' => [],
    ];

    if (!$preview && (string)($opts['confirm'] ?? '') !== VE2_FORM2_SEND_CONFIRM) {
        $result['errors'][] = "Live WhatsApp re-send refused: opts['confirm'] must exactly equal VE2_FORM2_SEND_CONFIRM. Nothing was sent.";
        return $result;
    }
    if ($base_url === '') { $result['errors'][] = 'base_url is required.'; return $result; }
    $url_errs = ve2_base_url_errors($base_url, false);
    if (!empty($url_errs)) { $result['errors'] = array_merge($result['errors'], $url_errs); return $result; }
    if (!ve2_invite_log_exists($pdo)) {
        $result['errors'][] = VE2_INVITE_LOG_TABLE . ' table is missing — refusing to send without an audit trail.';
        return $result;
    }

    $worklist_url = ve2_form2_worklist_link($pdo, $base_url);
    if ($worklist_url === null) {
        $result['errors'][] = 'Form 2 shared worklist link has not been generated yet — generate it on the Requestor Contacts tab first.';
        return $result;
    }
    $result['worklist_url'] = $worklist_url;

    // Eligible + incomplete POs, cadence deliberately ignored.
    $candidates = ve2_form2_scan_eligible_pos($pdo);
    $due = [];
    if (!empty($candidates)) {
        $po_list = array_keys($candidates);
        $ph = implode(',', array_fill(0, count($po_list), '?'));
        $sub_stmt = $pdo->prepare("
            SELECT po_number, team, total_score, score_band, submitted_at
              FROM vendor_eval_submissions
             WHERE po_number IN ($ph) AND team IN ('ops','project') AND status = 'submitted'
        ");
        $sub_stmt->execute($po_list);
        $subs_by_po = [];
        foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subs_by_po[$row['po_number']][$row['team']] = $row;
        }
        foreach ($candidates as $po => $c) {
            $ops     = $subs_by_po[$po]['ops']     ?? null;
            $project = $subs_by_po[$po]['project'] ?? null;
            if (ve2_form2_final_grade($ops, $project) !== null) {
                continue; // both teams already submitted — never needs a reminder
            }
            $due[$po] = $c;
        }
    }

    $po_count = count($due);
    $result['po_count'] = $po_count;
    if ($po_count === 0) {
        $result['ok'] = true; // nothing to re-send is a normal success
        return $result;
    }
    if ($po_count > $max_pos) {
        $result['errors'][] = sprintf(
            'Circuit breaker: %d eligible PO(s) exceeds the ceiling of %d. Aborting — nothing sent.',
            $po_count, $max_pos
        );
        return $result;
    }
    $po_nums = array_keys($due);
    sort($po_nums);
    $result['po_numbers'] = $po_nums;

    $roster = $pdo->query("SELECT name, email, whatsapp_number FROM vendor_eval_v2_form2_team_contacts ORDER BY name")
                  ->fetchAll(PDO::FETCH_ASSOC);
    if (empty($roster)) { $result['errors'][] = 'No Form 2 team contacts configured.'; return $result; }

    if (!$preview) {
        require_once __DIR__ . '/vendor_eval_v2_fonnte.php';
        if (ve2_fonnte_token() === '') {
            $result['errors'][] = 'VE2 Fonnte token is not configured in this environment — nothing sent.';
            return $result;
        }
    }

    $run_id = VE2_FORM2_REMINDER_LOG_PREFIX . '_' . ve2_new_run_id($run_label);
    $result['run_id'] = $run_id;

    $dupe_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM " . VE2_INVITE_LOG_TABLE . "
          WHERE channel = 'wa' AND status = 'sent' AND requestor_name = ? AND message_hash = ?"
    );

    foreach ($roster as $r) {
        $name = trim((string)$r['name']);
        $wa   = trim((string)($r['whatsapp_number'] ?? ''));
        if ($only !== '' && strcasecmp($name, $only) !== 0) {
            continue;
        }

        $entry = ['name' => $name, 'wa' => ve2_mask_phone($wa), 'status' => 'skipped'];
        if ($wa === '') {
            $entry['status'] = 'skipped (no WhatsApp number on file)';
            $result['wa_skipped']++; $result['recipients'][] = $entry; continue;
        }

        // Same message the failed reminder would have carried: all POs as first-send.
        $msg  = ve2_form2_build_wa_message($name, $due, [], $worklist_url);
        $hash = hash('sha256', $msg);

        $dupe_stmt->execute([$name, $hash]);
        if ((int)$dupe_stmt->fetchColumn() > 0) {
            $entry['status'] = 'skipped (identical WhatsApp already delivered)';
            $result['wa_skipped']++; $result['recipients'][] = $entry; continue;
        }

        if ($preview) {
            $entry['status'] = 'would_send';
            $result['would_send']++; $result['recipients'][] = $entry; continue;
        }

        $idem   = ve2_idempotency_key($run_id, $name, 'wa', $po_nums, $hash);
        $wa_res = ve2_send_fonnte_wa($wa, $msg);
        $ok     = is_array($wa_res) && !empty($wa_res['ok']);
        ve2_log_invite($pdo, [
            'run_id'          => $run_id,
            'requestor_name'  => $name,
            'channel'         => 'wa',
            'recipient'       => $wa,
            'cc_recipient'    => null,
            'po_numbers'      => $po_nums,
            'message_hash'    => $hash,
            'idempotency_key' => $idem,
            'status'          => $ok ? 'sent' : 'failed',
            'error_message'   => $ok ? null : substr((string)($wa_res['reason'] ?? 'provider did not confirm delivery'), 0, 500),
        ]);
        if ($ok) { $entry['status'] = 'sent';  $result['wa_sent']++; }
        else     { $entry['status'] = 'failed'; $entry['error'] = (string)($wa_res['reason'] ?? 'unknown'); $result['wa_failed']++; }
        $result['recipients'][] = $entry;
    }

    $result['recipient_count'] = count($result['recipients']);
    $result['ok'] = $preview ? true : ($result['wa_failed'] === 0);
    return $result;
}
