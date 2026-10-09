<?php
// workspace/cron/contract_maintenance_reminder_cron.php
//
// The auto half of the Contract Maintenance reminder. The manual half is the
// "Shoot Reminder" button on the dashboard (pages/contract_maintenance.php +
// pages/ajax_contract_maintenance_shoot_reminder.php), which sends about
// whatever contracts the Category Manager selects, whenever they press it.
//
// THIS CRON runs TWO independent tracks in one invocation, so only the one
// crontab entry documented below is needed:
//
//   Main track   Every contract EXCEPT output_type = 'PO' rows.
//   PO track     Only output_type = 'PO' rows, with PO-specific wording
//                ("the work continues, raise a new PO") -- 2026-09-07
//                revision, since a PO is just the funding document behind a
//                contract's work, not the work itself, and PO documents may
//                lapse before the underlying work/contract does.
//
// Each track sends about a contract up to THREE times over its life, once
// each the first time it crosses into 3, 2 and 1 calendar month(s) of its
// output_end_date (expiry is judged by the output/PO document's own end date,
// not contract_end_date -- see contract_maintenance_status() in includes/
// contract_maintenance_helper.php). Each of the three stages is a one-shot,
// not a repeat cadence -- see contract_maintenance_reminder_stage_for() in
// includes/contract_maintenance_reminder.php for exactly how a contract's
// current stage is picked, and config/database.php's comment on
// contract_maintenance_reminder_log for why dedup is a NOT EXISTS check per
// stage rather than a UNIQUE constraint (a manual send must still be allowed
// to repeat). The two tracks' stage values never collide (PO track logs
// PO_T1M/PO_T2M/PO_T3M, see CONTRACT_MAINTENANCE_REMINDER_PO_STAGE_PREFIX),
// and their row-selection WHERE clauses are mutually exclusive, so no
// contract can ever be swept by both tracks in the same run.
//
// Delegates everything to contract_maintenance_reminder_dispatch_auto() /
// contract_maintenance_po_reminder_dispatch_auto() -- the ONE place per track
// that decides what's due, builds the message, sends it, and records what
// went out. This script only prints results; it must not reimplement any of
// that logic itself, or the two can drift apart the way an earlier version of
// this file already did once.
//
// DRY RUN BY DEFAULT, same convention as aon_reminder_cron.php: with no group
// ID in workspace/config/contract_maintenance_wa_groups.php there is nowhere
// to send, so each track's message is worked out, printed, and NOTHING is
// recorded, a dry run must not consume a contract's one-shot stage. Pass
// --dry-run to force the same behaviour (for both tracks) once a group IS
// configured.
//
// CLI only -- same guard as workspace/cron/aon_reminder_cron.php. A stray web
// hit must 404 with no output, not leak a path via a stack trace.
//
// Deployment: register a DAILY cron job on the production VPS (see
// INFRASTRUCTURE.md -- this is a raw Ubuntu VPS running docker compose, NOT
// the shared-hosting layout aon_reminder_cron.php's own comment describes, so
// that file's "/usr/bin/php /home/<user>/domains/..." form will NOT work here
// either -- the app only runs inside the vms_prod_app container, which is the
// only place with the right PHP extensions and DB_HOST=db networking). There
// is no time-of-day constraint here the way AON's weekly Monday slot has, so
// any time of day is fine. From the VPS, as root:
//   crontab -e
// then add one line (adjust the repo path if it differs from the documented
// /var/www/vms):
//   0 8 * * * cd /var/www/vms && /usr/bin/docker compose -f docker-compose.prod.yml exec -T vms_prod_app php workspace/cron/contract_maintenance_reminder_cron.php >> /var/log/contract_maintenance_reminder_cron.log 2>&1
// Registering the job is a manual follow-up (needs VPS root access), not
// something done here.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(0);
}

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/contract_maintenance_reminder.php';

$argvFlags = array_slice($argv ?? [], 1);
$forceDry  = in_array('--dry-run', $argvFlags, true);
$today     = contract_maintenance_reminder_today();

echo str_repeat('=', 72), "\n";
echo "Contract Maintenance Reminder Cron\n";
echo 'Run at (Asia/Jakarta) : ', date('Y-m-d H:i:s'), "\n";

$groups = contract_maintenance_reminder_group_ids();
$dry    = $forceDry || empty($groups);

echo 'Mode                  : ', ($dry
        ? 'DRY RUN, nothing sent, nothing recorded'
          . ($forceDry ? ' (--dry-run)' : ' (no group configured in config/contract_maintenance_wa_groups.php)')
        : 'LIVE, sending to ' . count($groups) . ' group(s)'), "\n";

/**
 * Runs one track end to end (print due rows, honor --dry-run, otherwise
 * dispatch and print the result) and returns an exit code for that track.
 * Kept local to this script -- the actual due/dispatch logic it calls into
 * lives in includes/contract_maintenance_reminder.php, this just prints.
 */
function contract_maintenance_reminder_cron_run_track($pdo, $today, $forceDry, $label, $due_fn, $dispatch_fn, $heading, $note) {
    echo "\n", str_repeat('-', 72), "\n";
    echo 'Track                 : ', $label, "\n";

    // A forced dry run still has to go through the real due-rows function so
    // the printed preview matches exactly what a live run would have sent --
    // computed here directly, since the dispatch function only skips
    // sending/recording when the group list itself is empty, not on
    // --dry-run.
    $due = $due_fn($pdo, $today);
    echo 'Contracts due today   : ', count($due), "\n";

    if (empty($due)) {
        echo "Nothing to send for this track.\n";
        return 0;
    }

    foreach ($due as $r) {
        printf("  #%-5d %-24s %-30s %-6s until %s\n",
            $r['id'],
            $r['item_category'],
            substr((string)($r['description'] ?? ''), 0, 30),
            $r['stage'],
            $r['soonest_date']
        );
    }

    if ($forceDry) {
        // Preview only: build and print the message without sending or
        // recording anything, even if a group IS configured -- see
        // contract_maintenance_reminder_send()'s dry-run behaviour, which the
        // dispatch function would otherwise skip straight past since
        // $groups is non-empty.
        $message = contract_maintenance_reminder_build_message($due, $heading, null, $today, $note);
        echo "\n", str_repeat('-', 72), "\n", $message, "\n", str_repeat('-', 72), "\n\n";
        echo "Dry run (--dry-run) -- the contracts above stay due and will be picked up by the next live run.\n";
        return 0;
    }

    $result = $dispatch_fn($pdo, $today);

    if ($result['dry_run']) {
        echo "\nNo WhatsApp group configured -- the contracts above stay due and will be picked up once one is.\n";
        return 0;
    }

    if (!$result['sent']) {
        echo "\n", ($result['error'] ?: 'Send failed'), " -- leaving every contract due so the next run retries.\n";
        return 1;
    }

    echo "\nRecorded {$result['recorded']} of {$result['due']} reminders in contract_maintenance_reminder_log.\n";
    return 0;
}

$mainExit = contract_maintenance_reminder_cron_run_track(
    $pdo, $today, $forceDry,
    'Main (AON/PPH/contracts)',
    'contract_maintenance_reminder_due_rows',
    'contract_maintenance_reminder_dispatch_auto',
    '⏰ *AUTO-REMINDER: KONTRAK AKAN BERAKHIR*',
    null
);

$poExit = contract_maintenance_reminder_cron_run_track(
    $pdo, $today, $forceDry,
    'PO',
    'contract_maintenance_po_reminder_due_rows',
    'contract_maintenance_po_reminder_dispatch_auto',
    '⏰ *AUTO-REMINDER: PO AKAN BERAKHIR*',
    'PO ini akan segera berakhir. Pekerjaan/kontrak tetap berjalan seperti biasa -- mohon segera buat PO baru agar pekerjaan tetap tercover secara administratif.'
);

echo "\n", str_repeat('=', 72), "\n";
exit(max($mainExit, $poExit));
