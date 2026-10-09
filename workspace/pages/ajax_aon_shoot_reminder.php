<?php
// pages/ajax_aon_shoot_reminder.php
//
// The Category Manager's "Shoot Reminder" button on Master AON: sends the AON
// contract/quantity reminder to the STC WhatsApp group on demand, instead of
// waiting for the next AON import to trigger it.
//
// Same schedule, same message, same log as the automatic send — it is literally
// the same call (aon_reminder_dispatch, includes/aon_reminder.php). In
// particular it respects the per-SKU-per-day flag: a SKU already sent about
// today is NOT sent again, so pressing this after an import that already fired
// reports "nothing new" rather than repeating the group message. That is the
// point of the button — to close the gap when nothing has been imported for a
// while, not to give one person a way to message the group repeatedly.
//
// CATEGORY MANAGER ONLY. aon_can_access() also lets STC and Manager in, and
// this is not an STC control: an STC acts on their own SKUs through the pop-up,
// while messaging the whole group is a lead's call.
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';

if (($_SESSION['role'] ?? '') !== 'Category Manager') {
    aon_json_error('Only a Category Manager can send the AON reminder.', 403);
}

// POST only: this sends a WhatsApp message to a group, which is not something a
// link, a prefetch, or a stray page refresh should be able to do.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    aon_json_error('Method not allowed.', 405);
}

require_once '../config/database.php';
require_once '../includes/aon_reminder.php';

$outcome = aon_reminder_dispatch($pdo, ['source' => 'manual_cm']);

// One sentence the button can show as-is. The counts are SKUs, not messages —
// the group gets a single digest however many SKUs are in it.
$n = (int)$outcome['due'];
if ($n === 0) {
    $message = 'Nothing new to send — every AON SKU that needs chasing has already been reminded today.';
} elseif ($outcome['sent']) {
    $message = 'Reminder sent to the STC WhatsApp group for ' . $n . ' SKU' . ($n === 1 ? '' : 's') . '.';
} elseif ($outcome['dry_run']) {
    $message = $n . ' SKU' . ($n === 1 ? '' : 's') . ' due, but no WhatsApp group is configured — nothing was sent.';
} else {
    $message = 'WhatsApp did not accept the message. Nothing was marked as sent, so it can be retried.';
}

echo json_encode([
    'status'   => ($outcome['error'] === null || $n === 0) ? 'success' : 'error',
    'message'  => $message,
    'due'      => $n,
    'sent'     => (bool)$outcome['sent'],
    'dry_run'  => (bool)$outcome['dry_run'],
    'recorded' => (int)$outcome['recorded'],
]);
