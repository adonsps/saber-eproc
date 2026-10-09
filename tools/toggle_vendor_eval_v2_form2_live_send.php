<?php
// tools/toggle_vendor_eval_v2_form2_live_send.php
//
// Flip the Form 2 cron live-send kill switch (vendor_eval_v2_form2_send_settings).
// See db_backups/vendor_eval_v2_form2_send_kill_switch_01_schema.sql and
// workspace/includes/vendor_eval_v2_form2_kill_switch.php for what this gates:
// the cron will not send for real unless this switch is ON *and* the cron's
// own hard-coded preview flag has separately been flipped to false. Does not
// affect the manual "Shoot Reminder" button, which has its own gate.
//
// Usage:
//   Show current state:
//     docker-compose exec app php tools/toggle_vendor_eval_v2_form2_live_send.php
//   Turn ON (requires identity + explicit confirm):
//     docker-compose exec app php tools/toggle_vendor_eval_v2_form2_live_send.php --set=on  --confirm-db=u894129685_workspace --by="Your Name" --note="reason"
//   Turn OFF:
//     docker-compose exec app php tools/toggle_vendor_eval_v2_form2_live_send.php --set=off --confirm-db=u894129685_workspace --by="Your Name" --note="reason"

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

require_once __DIR__ . '/../workspace/config/database.php';
require_once __DIR__ . '/../workspace/includes/vendor_eval_v2_form2_kill_switch.php';

$set        = null;
$confirmDb  = null;
$changedBy  = null;
$note       = '';

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--set=')) { $set = strtolower(substr($arg, 6)); }
    if (str_starts_with($arg, '--confirm-db=')) { $confirmDb = substr($arg, 13); }
    if (str_starts_with($arg, '--by=')) { $changedBy = substr($arg, 5); }
    if (str_starts_with($arg, '--note=')) { $note = substr($arg, 7); }
}

$identity = $pdo->query('SELECT DATABASE() AS db, USER() AS usr, @@hostname AS host, VERSION() AS ver')->fetch(PDO::FETCH_ASSOC);
echo "DB identity: DATABASE()={$identity['db']}  USER()={$identity['usr']}  @@hostname={$identity['host']}  VERSION()={$identity['ver']}\n";

$currentlyEnabled = ve2_form2_live_send_enabled($pdo);
echo 'Current state: ' . ($currentlyEnabled ? 'ON (live send allowed by this switch)' : 'OFF (live send blocked by this switch)') . "\n";

if ($set === null) {
    echo "\n[READ ONLY] No --set=on|off given — nothing changed. See file header for usage.\n";
    exit(0);
}

if (!in_array($set, ['on', 'off'], true)) {
    exit("--set must be 'on' or 'off'.\n");
}

// Expected DB name per CLAUDE.md — Docker's @@hostname is a container ID that
// changes on recreation, so identity is confirmed via DATABASE() + VERSION()
// (MySQL 8.0.x), not a hard-coded hostname. See CLAUDE.md "Local DB Identity Guard".
if ($confirmDb !== 'u894129685_workspace') {
    exit("Refusing to change the switch: --confirm-db=u894129685_workspace is required and must match exactly.\n");
}
if ($identity['db'] !== 'u894129685_workspace' || !str_starts_with((string)$identity['ver'], '8.0')) {
    exit("Refusing to change the switch: unexpected DB identity (see printed values above).\n");
}
if ($changedBy === null || trim($changedBy) === '') {
    exit("--by=\"Your Name\" is required so the audit log records who flipped this.\n");
}

ve2_form2_set_live_send_enabled($pdo, $set === 'on', trim($changedBy), $note);

echo "\nDone. Switch is now " . ($set === 'on' ? 'ON' : 'OFF') . ".\n";
if ($set === 'on') {
    echo "Reminder: the cron itself still hard-codes preview=true in this build (see\n"
       . "workspace/cron/vendor_eval_v2_form2_reminder_cron.php header) — flipping this\n"
       . "switch alone does NOT make the cron send. Both gates must agree.\n";
}
