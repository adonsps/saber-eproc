<?php
// pages/ajax_aon_stock_alert_dismiss.php
//
// Records that the signed-in user has closed the "AON SKUs are running out"
// pop-up, so it stops opening by itself for the rest of this login session.
// Fire-and-forget: the browser does not wait on the reply, and losing it only
// means the pop-up opens once more.
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_stock_alert.php';

aon_stock_alert_mark_dismissed();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
