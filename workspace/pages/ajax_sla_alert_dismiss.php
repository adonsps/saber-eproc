<?php
// pages/ajax_sla_alert_dismiss.php
//
// Records that the signed-in user has closed the "tickets at their SLA limit"
// pop-up, so it stops opening by itself for the rest of this login session.
// Fire-and-forget: the browser does not wait on the reply, and losing it only
// means the pop-up opens once more.
require_once '../includes/auth.php';
require_login();
// Persists a dismissal (via sla_alert_mark_dismissed()), so it is a write.
ws_require_write();
require_once '../includes/sla_alert.php';

sla_alert_mark_dismissed();

header('Content-Type: application/json');
echo json_encode(['success' => true]);
