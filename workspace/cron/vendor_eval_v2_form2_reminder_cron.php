<?php
declare(strict_types=1);

// workspace/cron/vendor_eval_v2_form2_reminder_cron.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail, New Store) daily reminder
// cron. As of 2026-07-30, $cron_wants_live below is hard-coded true — the
// cron is CODE-READY to send real WA/email. It remains BLOCKED from
// actually sending until vendor_eval_v2_form2_send_settings.live_send_enabled
// is manually set to 1 (see tools/toggle_vendor_eval_v2_form2_live_send.php);
// that flip is a separate, deliberate step the user performs directly, not
// something this cron or this commit does. Until then every run is a dry
// run. The shared function this cron calls, ve2_form2_send_reminders() (see
// workspace/includes/vendor_eval_v2_form2_reminder.php), has had the
// live-send code path since 2026-07-29 (used by the manual "Send Real
// Reminder" button, workspace/pages/ajax_send_form2_reminder.php).
//
// TWO independent gates must both be true before this cron can ever send for
// real: (1) $cron_wants_live in ve2f2_cron_main() below (now true), and (2)
// the vendor_eval_v2_form2_send_settings kill switch (defaults OFF — see
// workspace/includes/vendor_eval_v2_form2_kill_switch.php and
// tools/toggle_vendor_eval_v2_form2_live_send.php). Flipping only one of the
// two does nothing.
//
// CLI only — same guard as workspace/cron/process_k2_import_queue.php. A
// stray web hit must 404 with no output, not leak a path via a stack trace.
//
// Deployment (once "go live" is explicitly approved in a later phase):
// register a DAILY Hostinger cron job using the same "full absolute path +
// /usr/bin/php prefix" convention already confirmed working for
// workspace/cron/process_k2_import_queue.php (see
// db_backups/k2_drive_automation_migration_readme.md), e.g.:
//   /usr/bin/php /home/<hostinger-user>/domains/<domain>/<path-to-repo>/workspace/cron/vendor_eval_v2_form2_reminder_cron.php
// Do NOT copy workspace/cron/rfq_deadline_notifier.php's own cron
// registration as a template — that one is still broken/unfixed in
// production as of this writing. The exact absolute path above can only be
// filled in by whoever has Hostinger cron-panel access; this session has no
// way to read or confirm it, so registering the actual cron job is a manual
// follow-up step, not something done here.
//
// Forces Asia/Jakarta explicitly, independent of config/database.php's UTC
// session default — safe: date_default_timezone_set() is a per-process PHP
// setting, not a MySQL session variable, so it cannot leak into or be
// affected by any other concurrent request/connection.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(0);
}

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/vendor_eval_v2_form2_reminder.php';
require_once __DIR__ . '/../includes/vendor_eval_v2_form2_kill_switch.php';

const VE2F2_CRON_LOG_DIR = __DIR__ . '/../../db_backups/local_runtime_exports';

/** Production base URL — see db_backups/vendor_eval_v2_deployment_handover.txt section 6.
 *  CLI has no Host header to derive this from the way the web sender does;
 *  override with a VE2_PUBLIC_BASE_URL constant (e.g. in a local config) for
 *  non-production runs. */
function ve2f2_cron_base_url(): string
{
    if (defined('VE2_PUBLIC_BASE_URL') && VE2_PUBLIC_BASE_URL !== '') {
        return rtrim(VE2_PUBLIC_BASE_URL, '/');
    }
    return 'https://vssportal.online/vms/workspace/pages';
}

function ve2f2_cron_report(array $result): string
{
    $lines   = [];
    $lines[] = str_repeat('=', 78);
    $lines[] = 'Vendor Evaluation v2 — Form 2 Reminder Cron — '
        . ($result['preview'] ? 'DRY RUN (nothing was sent)' : 'LIVE RUN');
    $lines[] = 'Run at (Asia/Jakarta) : ' . date('Y-m-d H:i:s');
    $lines[] = 'run_id                : ' . $result['run_id'];
    $lines[] = 'kill_switch_enabled   : ' . ($result['kill_switch_enabled'] ? 'ON' : 'OFF (blocks live send regardless of code state)');
    $lines[] = 'base_url              : ' . $result['base_url'];
    $lines[] = 'worklist_url          : ' . ($result['worklist_url'] ?? '(none)');
    $lines[] = 'cadence_days          : ' . $result['cadence_days'];
    $lines[] = 'candidate_count       : ' . $result['candidate_count'] . ' (currently Form 2 eligible)';
    $lines[] = 'skipped_complete      : ' . $result['skipped_complete'] . ' (both teams already submitted)';
    $lines[] = 'due_first  (' . count($result['due_first']) . ')       : ' . implode(', ', array_keys($result['due_first']));
    $lines[] = 'due_weekly (' . count($result['due_weekly']) . ')      : ' . implode(', ', array_keys($result['due_weekly']));

    if (!empty($result['not_due'])) {
        $lines[] = 'not_due (eligible but not reminded today):';
        foreach ($result['not_due'] as $po => $reason) {
            $lines[] = "  - {$po}: {$reason}";
        }
    }

    $lines[] = 'recipient_count       : ' . $result['recipient_count'];
    foreach ($result['recipients'] as $r) {
        $lines[] = sprintf(
            '  - %-20s email=%s (%s)  wa=%s (%s)',
            $r['name'],
            $r['email']['recipient'] !== '' ? $r['email']['recipient'] : '(none)',
            $r['email']['status'],
            $r['wa']['recipient'] !== '' ? $r['wa']['recipient'] : '(none)',
            $r['wa']['status']
        );
    }

    if ($result['message_preview'] !== null) {
        $lines[] = str_repeat('-', 78);
        $lines[] = 'Sample WA message (as seen by ' . $result['message_preview']['sample_recipient'] . '):';
        $lines[] = $result['message_preview']['wa_message'];
        $lines[] = str_repeat('-', 78);
        $lines[] = 'Sample email subject: ' . $result['message_preview']['email_subject'];
    }

    if (!empty($result['warnings'])) {
        $lines[] = 'WARNINGS:';
        foreach ($result['warnings'] as $w) { $lines[] = '  - ' . $w; }
    }
    if (!empty($result['errors'])) {
        $lines[] = 'ERRORS:';
        foreach ($result['errors'] as $e) { $lines[] = '  - ' . $e; }
    }
    $lines[] = str_repeat('=', 78);

    return implode("\n", $lines) . "\n";
}

function ve2f2_cron_main(): int
{
    global $pdo;

    // Code-level intent switch — cron go-live has now been explicitly
    // approved (2026-07-30). This alone does NOT make the cron send: the
    // separate kill switch below (vendor_eval_v2_form2_send_settings,
    // default OFF) must ALSO independently be enabled. Until someone sets
    // live_send_enabled=1 in that table, $preview below stays true.
    $cron_wants_live = true;

    // Separate, explicit kill switch (vendor_eval_v2_form2_send_settings,
    // default OFF) — see workspace/includes/vendor_eval_v2_form2_kill_switch.php.
    // Read unconditionally (not short-circuited) so its state is always visible
    // in the report below, even while $cron_wants_live is still false.
    $kill_switch_enabled = ve2_form2_live_send_enabled($pdo);
    $preview = !($cron_wants_live && $kill_switch_enabled);

    $result = ve2_form2_send_reminders($pdo, [
        'base_url'  => ve2f2_cron_base_url(),
        'preview'   => $preview,
        'channel'   => 'both',
        'run_label' => 'cron',
        // Mirrors the manual "Shoot Reminder" button's own confirm-gate
        // (see ajax_send_form2_reminder.php) — ve2_form2_send_reminders()
        // refuses to send live unless this exactly matches
        // VE2_FORM2_SEND_CONFIRM, even when preview=false. Safe to pass
        // unconditionally: it's a no-op while preview=true.
        'confirm'   => VE2_FORM2_SEND_CONFIRM,
    ]);
    $result['kill_switch_enabled'] = $kill_switch_enabled;

    $report = ve2f2_cron_report($result);
    echo $report;

    if (!is_dir(VE2F2_CRON_LOG_DIR)) {
        @mkdir(VE2F2_CRON_LOG_DIR, 0775, true);
    }
    $log_path = VE2F2_CRON_LOG_DIR . '/vendor_eval_v2_form2_reminder_dryrun_' . date('Ymd_His') . '.txt';
    @file_put_contents($log_path, $report);

    return empty($result['errors']) ? 0 : 1;
}

exit(ve2f2_cron_main());
