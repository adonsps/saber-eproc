<?php
// tools/toggle_ai_auto_assign.php
//
// Flip the AI auto-assignment kill switch (system_settings.ai_auto_assign_enabled).
// See workspace/includes/ai_ticket_assigner.php (ai_assign_live_enabled) and
// workspace/cron/process_k2_import_queue.php (k2_worker_run_ai_assignment) for
// what this gates: after each successful K2 PR import the worker will classify
// and assign still-unassigned tickets via Gemini -- but ONLY when this switch is
// ON *and* the worker's own code-level intent flag is true. Both gates must
// agree; flipping this one alone is not sufficient on a build where the code
// flag has been turned off.
//
// This does NOT affect the browser dispatcher (workspace/pages/auto_assign.php).
// That path is an explicit human action and is deliberately never gated here.
//
// Usage:
//   Show current state (read only, the default):
//     docker-compose exec app php tools/toggle_ai_auto_assign.php
//   Turn ON:
//     docker-compose exec app php tools/toggle_ai_auto_assign.php --set=on  --confirm-db=<DATABASE()> --by="Your Name" --note="reason"
//   Turn OFF (instant rollback, no deploy needed):
//     docker-compose exec app php tools/toggle_ai_auto_assign.php --set=off --confirm-db=<DATABASE()> --by="Your Name" --note="reason"
//   Narrow which tickets the WORKER may assign (does not affect auto_assign.php):
//     docker-compose exec app php tools/toggle_ai_auto_assign.php --min-pr-date=2026-08-01 --confirm-db=<DATABASE()> --by="Your Name"
//   Remove the window (worker sees the full eligible queue again):
//     docker-compose exec app php tools/toggle_ai_auto_assign.php --min-pr-date=none --confirm-db=<DATABASE()> --by="Your Name"

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../workspace/config/database.php';
require_once __DIR__ . '/../workspace/includes/ai_ticket_assigner.php';
require_once __DIR__ . '/../workspace/includes/automation/diagnostics_logger.php';

$set        = null;
$minPrDateA = null;
$confirmDb  = null;
$changedBy  = null;
$note       = '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--set=')) { $set = strtolower(substr($arg, 6)); }
    if (str_starts_with($arg, '--min-pr-date=')) { $minPrDateA = trim(substr($arg, 14)); }
    if (str_starts_with($arg, '--confirm-db=')) { $confirmDb = substr($arg, 13); }
    if (str_starts_with($arg, '--by=')) { $changedBy = substr($arg, 5); }
    if (str_starts_with($arg, '--note=')) { $note = substr($arg, 7); }
}

$identity = $pdo->query('SELECT DATABASE() AS db, USER() AS usr, @@hostname AS host, VERSION() AS ver')->fetch(PDO::FETCH_ASSOC);
echo "DB identity: DATABASE()={$identity['db']}  USER()={$identity['usr']}  @@hostname={$identity['host']}  VERSION()={$identity['ver']}\n";

$currentlyEnabled = ai_assign_live_enabled($pdo);
echo 'Current state: ' . ($currentlyEnabled ? 'ON (worker may assign for real)' : 'OFF (worker logs the queue and stops -- no Gemini calls)') . "\n";

$currentWindow = ai_assign_min_pr_date($pdo);
$queueWindowed = null;
$queueAll = null;
try {
    $lastRun = $pdo->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
    $lastRun->execute([AI_ASSIGN_LAST_RUN_SETTING_KEY]);
    $lastRunValue = $lastRun->fetchColumn();
    echo 'Last worker run: ' . ($lastRunValue === false ? '(never)' : (string) $lastRunValue) . "\n";

    $queueAll = ai_assign_queue_count($pdo);
    $queueWindowed = $currentWindow === null ? $queueAll : ai_assign_queue_count($pdo, $currentWindow);
    echo 'Worker window  : ' . ($currentWindow === null
        ? '(none — worker sees the whole eligible queue)'
        : "pr_date >= {$currentWindow}") . "\n";
    echo 'Tickets waiting: ' . number_format($queueWindowed) . ' in window'
        . ($currentWindow === null ? '' : ', ' . number_format($queueAll) . ' total') . "\n";
} catch (\Throwable $e) {
    echo "Last worker run: (unavailable)\n";
}

if ($set === null && $minPrDateA === null) {
    echo "\n[READ ONLY] No --set=on|off or --min-pr-date given — nothing changed. See file header for usage.\n";
    exit(0);
}

if ($set !== null && !in_array($set, ['on', 'off'], true)) {
    exit("--set must be 'on' or 'off'.\n");
}

// Validated up front so a bad date is refused before anything is written.
$newWindow = null;
if ($minPrDateA !== null && strtolower($minPrDateA) !== 'none') {
    $parsedWindow = DateTime::createFromFormat('Y-m-d', $minPrDateA);
    if ($parsedWindow === false || $parsedWindow->format('Y-m-d') !== $minPrDateA) {
        exit("--min-pr-date must be a Y-m-d date (e.g. 2026-08-01), or 'none' to clear it.\n");
    }
    $newWindow = $minPrDateA;
}

// Unlike tools/toggle_vendor_eval_v2_form2_live_send.php, this deliberately does
// NOT hard-code the expected database name. Local and production both run a
// schema named u894129685_workspace (see workspace/config/database.php), so a
// hard-coded name proves nothing about WHICH environment you are on -- exactly
// the trap CLAUDE.md's "--confirm-db alone is not enough" warning describes. What
// it does instead is force the operator to type the live DATABASE() value back,
// having just read the full identity line printed above.
if ($confirmDb === null || $confirmDb !== (string) $identity['db']) {
    exit("Refusing to change the switch: --confirm-db must exactly match the DATABASE() printed above.\n");
}
if ($changedBy === null || trim($changedBy) === '') {
    exit("--by=\"Your Name\" is required so the flip is attributable in the diagnostics log.\n");
}

$writeSetting = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");

if ($minPrDateA !== null) {
    $writeSetting->execute([AI_ASSIGN_MIN_PR_DATE_SETTING_KEY, $newWindow ?? '']);
    echo "\nWorker window is now " . ($newWindow === null ? '(none)' : "pr_date >= {$newWindow}") . ".\n";
    if ($newWindow !== null) {
        echo "  tickets now in window: " . number_format(ai_assign_queue_count($pdo, $newWindow))
           . " of " . number_format((int) $queueAll) . " eligible\n";
    }
    echo "  (auto_assign.php is unaffected — it always sees the whole eligible queue.)\n";
}

if ($set !== null) {
    $writeSetting->execute([AI_ASSIGN_ENABLED_SETTING_KEY, $set === 'on' ? '1' : '0']);
}

// Provenance goes to the same JSONL stream the worker writes its AI_ASSIGN_*
// events to, so the flip and its consequences read in one timeline -- and no new
// audit table is needed for a couple of settings keys.
K2AutomationDiagnosticsLogger::fromEnvironment()->info('AI_ASSIGN_SWITCH_CHANGED', [
    'new_state' => $set === null ? '(unchanged)' : ($set === 'on' ? 'ON' : 'OFF'),
    'previous_state' => $currentlyEnabled ? 'ON' : 'OFF',
    'new_min_pr_date' => $minPrDateA === null ? '(unchanged)' : ($newWindow ?? '(none)'),
    'previous_min_pr_date' => $currentWindow ?? '(none)',
    'changed_by' => trim($changedBy),
    'note' => $note,
    'database' => (string) $identity['db'],
]);

if ($set !== null) {
    echo "\nDone. Switch is now " . ($set === 'on' ? 'ON' : 'OFF') . ".\n";
}
if ($set === 'on') {
    // The whole point of the window is to avoid dumping months of stale backlog
    // onto PICs the first time this is enabled, so say so loudly rather than
    // letting a large number slip by unnoticed.
    $effectiveWindow = $minPrDateA !== null ? $newWindow : $currentWindow;
    $exposed = ai_assign_queue_count($pdo, $effectiveWindow);
    if ($effectiveWindow === null && $exposed > 200) {
        echo "\n  !! NO DATE WINDOW IS SET and " . number_format($exposed) . " tickets are eligible.\n"
           . "     The next runs will work through all of them, including any months-old\n"
           . "     tickets that are still technically Outstanding. If that is not what you\n"
           . "     want, set one now:\n"
           . "       --min-pr-date=YYYY-MM-DD --confirm-db=... --by=\"...\"\n";
    } else {
        echo "  " . number_format($exposed) . " ticket(s) are currently in scope for the worker.\n";
    }
    echo "\nReminder: assignment runs only after a successful K2 PR import, capped per run\n"
       . "(default 20 batches x 15 tickets, 600s). The worker's own code-level intent flag\n"
       . "must also be true — both gates must agree. Re-run with --set=off to roll back\n"
       . "instantly, no deploy needed.\n";
}
