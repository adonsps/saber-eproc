<?php
// workspace/cron/aon_reminder_cron.php
//
// The clock half of the AON reminder. The import hook is the other half.
//
// The QUANTITY reminder is sent by the IMPORT, not by a clock: every AON upload
// and every automated K2 import batch calls aon_reminder_after_import() the
// moment its data is committed (pages/upload_aon.php,
// cron/process_k2_import_queue.php), so a SKU that only became low because of
// that import is chased within seconds. For that half this cron is a safety net
// -- a quiet week must not mean nobody hears about a contract being drained.
//
// The EXPIRY reminder is the other way round. A date does not change when a
// spreadsheet is uploaded, and its weekly repeat is pinned to a slot, so THIS
// CRON IS THE PRIMARY TRIGGER for it and not a fallback.
//
//   LOWQTY  remaining qty at or below 30% of the contract qty  -- daily
//   T2M     two months before valid_until                      -- once
//   T1M     one month before valid_until                       -- once
//   WEEKLY  through the final month  -- every Monday, 14:00 Asia/Jakarta,
//           moving to the next working day when that Monday is a company
//           holiday (company_holidays, the same calendar the SLA countdown uses)
//
// ─────────────────────────────────────────────────────────────────────────────
// REGISTER THIS AT OR AFTER 14:00 WIB. The weekly stage refuses to fire before
// 14:00 Asia/Jakarta -- that is what stops a nine-in-the-morning AON import from
// bringing the week's message forward. A cron registered at, say, 02:00 would
// therefore run every day, report the quantity half correctly, and silently
// never send the weekly expiry digest at all. 14:05 leaves the schedule some
// slack without drifting into the afternoon.
// ─────────────────────────────────────────────────────────────────────────────
//
// It shares everything else with the import hook: the same rules, the same
// message, the same per-SKU-per-reason flag (includes/aon_reminder.php). Running
// it after an import that already sent therefore reports "nothing due" rather
// than repeating the group message, so it is safe to schedule daily regardless
// of how often the imports run.
//
// DRY RUN BY DEFAULT, in the only sense that matters: with no group ID in
// workspace/config/aon_reminder_wa.php there is nowhere to send, so the whole
// message is worked out, printed, and NOTHING is recorded -- a dry run must not
// consume a one-shot stage or today's slot for a message it only previewed.
// Pass --dry-run to force the same behaviour once a group IS configured.
//
// CLI only -- same guard as workspace/cron/process_k2_import_queue.php. A stray
// web hit must 404 with no output, not leak a path via a stack trace.
//
// Deployment: register a DAILY Hostinger cron job using the same "full absolute
// path + /usr/bin/php prefix" convention already confirmed working for
// workspace/cron/process_k2_import_queue.php, e.g.:
//   /usr/bin/php /home/<hostinger-user>/domains/<domain>/<path-to-repo>/workspace/cron/aon_reminder_cron.php
// The absolute path can only be filled in by whoever has Hostinger cron-panel
// access, so registering the job is a manual follow-up, not something done here.
// Do NOT copy workspace/cron/rfq_deadline_notifier.php's registration as a
// template -- that one is still broken in production.
//
// Forces Asia/Jakarta explicitly, independent of config/database.php's UTC
// session default: a reminder that says "expires today" has to agree with the
// reader's calendar, and date_default_timezone_set() is a per-process PHP
// setting, so it cannot leak into any other request or connection. The scheduler
// itself no longer depends on this -- includes/aon_reminder.php pins Jakarta on
// every date it computes, because the web SAPI calls it too -- but the output
// below is printed with plain date(), so it stays.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(0);
}

date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/aon_reminder.php';

$argvFlags = array_slice($argv ?? [], 1);
$forceDry  = in_array('--dry-run', $argvFlags, true);
$today     = aon_reminder_today();

echo str_repeat('=', 72), "\n";
echo "AON Reminder Cron\n";
echo 'Run at (Asia/Jakarta) : ', date('Y-m-d H:i:s'), "\n";

$groups = aon_reminder_group_ids();
$dry    = $forceDry || empty($groups);

echo 'Mode                  : ', ($dry
        ? 'DRY RUN — nothing sent, nothing recorded'
          . ($forceDry ? ' (--dry-run)' : ' (no group configured in config/aon_reminder_wa.php)')
        : 'LIVE — sending to ' . count($groups) . ' group(s)'), "\n";

// One call, so this prints exactly what the import hook would have sent. The
// rows come back on the result, which is what the per-SKU listing below reads --
// deliberately not a second aon_reminder_due_rows() call, which would be a
// second answer to the same question and could disagree with what was sent.
$res = aon_reminder_dispatch($pdo, ['today' => $today, 'dry_run' => $dry, 'source' => 'cron']);

// Spelled out because "the weekly digest did not go out" is otherwise
// indistinguishable from a bug, and the usual cause -- this job registered
// before 14:00 -- is invisible from the message itself.
$weeklyWhen = $res['weekly_send_date'];
echo 'Weekly slot this week : ', $weeklyWhen,
     ($weeklyWhen === $today ? ' (today)' : ''), "\n";
echo 'Weekly stage          : ', ($res['weekly_open']
        ? 'OPEN — final-month contracts repeat in this run'
        : ($weeklyWhen === $today
            ? 'closed — before ' . AON_REMINDER_WEEKLY_HOUR . ':00 WIB; register this cron at 14:00 or later'
            : 'closed — not this week\'s send day')), "\n";

echo 'Reminders due today   : ', $res['due'],
     ' (qty ', $res['low'], ', expiry ', $res['expiring'], ")\n";

if ($res['due'] === 0) {
    echo "Nothing to send — every AON SKU that needs chasing has already been reminded.\n";
    echo str_repeat('=', 72), "\n";
    exit(0);
}

foreach ($res['rows'] as $d) {
    printf("  %-14s %-8s %-8s until %s  (%s)%s\n",
        $d['sku_code'],
        $d['due_expiring'] ? $d['stage'] : '-',
        $d['due_low'] ? 'LOWQTY' : '-',
        substr((string)$d['valid_until'], 0, 10) ?: '—',
        $d['days_left'] === null ? 'no end date' : (int)$d['days_left'] . ' days left',
        trim((string)$d['handler']) === '' ? '  [unclaimed]' : '  ' . $d['handler']
    );
}

echo "\n", str_repeat('-', 72), "\n", $res['message'], "\n", str_repeat('-', 72), "\n\n";

if ($dry) {
    echo "Dry run — the reminders above stay due and will be picked up by the next live send.\n";
    echo str_repeat('=', 72), "\n";
    exit(0);
}

printf("Accepted by %d of %d group(s).\n", $res['accepted'], $res['groups']);

if (!$res['sent']) {
    echo "\nNothing was accepted by the provider — leaving every reminder due so the next run retries.\n";
    echo str_repeat('=', 72), "\n";
    exit(1);
}

echo "Recorded {$res['recorded']} of {$res['due']} reminders in aon_reminder_log.\n";
echo str_repeat('=', 72), "\n";
exit(0);
