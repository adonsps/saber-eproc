<?php
// pages/ajax_aon_reminder_ignore.php
//
// Records an STC's "stop reminding me about this SKU" decision, and lifts it
// again on request. Two answers are accepted (includes/aon_reminder.php):
//
//   ON_PROGRESS   the renewal/renegotiation is already under way
//   NOT_RENEWAL   the contract is being dropped — remark mandatory
//
// Both stop the reminders; the status is what says which happened afterwards.
// An opted-out SKU drops out of the pop-up (includes/aon_stock_alert.php) and
// out of every WhatsApp reminder (includes/aon_reminder.php's dispatch, which
// every AON import calls).
//
// The filename predates the two buttons — it was one "Ignore" link. Renaming it
// would break the pop-up and Master AON's own fetch() calls for no gain.
//
// Same access gate as the rest of Master AON: whoever may maintain AON data may
// decide which contracts stop being chased. The decision is stamped with the
// actor either way, so it is never anonymous.
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';

if (!aon_can_access($_SESSION['role'] ?? '')) {
    aon_json_error('You do not have permission to change AON reminders.', 403);
}

require_once '../config/database.php';
require_once '../includes/aon_reminder.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    aon_json_error('Invalid request payload.');
}

$sku_code = trim((string)($input['sku_code'] ?? ''));
if ($sku_code === '') {
    aon_json_error('Missing SKU code.');
}

// Absent means "stop reminding" — the pop-up's buttons only ever turn reminders
// off; turning them back on is an explicit active=false from the details modal.
$active  = array_key_exists('active', $input) ? (bool)$input['active'] : true;
$remarks = (string)($input['remarks'] ?? '');
$actor   = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);

// Anything unrecognised normalizes to null, which the helper treats as the
// stricter of the two answers — so a malformed status cannot drop a contract
// without a reason.
$optout_status = aon_reminder_optout_status($input['status'] ?? null);

$res = aon_reminder_set_optout($pdo, $sku_code, $active, $remarks, $actor, $optout_status);
if (!$res['ok']) {
    aon_json_error($res['error'], 422);
}

echo json_encode([
    'status'         => 'success',
    'sku_code'       => $sku_code,
    'active'         => $active,
    'optout_status'  => $optout_status,
    'optout_label'   => aon_reminder_optout_label($optout_status),
    'remarks'        => trim($remarks),
    'actor'          => $actor,
]);
