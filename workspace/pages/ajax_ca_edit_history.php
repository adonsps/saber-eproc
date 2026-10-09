<?php
// pages/ajax_ca_edit_history.php
// Read-only "who changed what price, when" list for the history icon in the
// Initial Quotation Price modal and the Manual Input modal.
//   type=project  key=<canvassing_no>            -> QUOTATION_PROJECT
//   type=ticket   key=<ticket_no>                -> every line of that ticket
//   type=manual   key=<manual_cost_avoidance id> -> MANUAL
// Access mirrors the save it describes: whoever may price that canvassing /
// ticket, or write to that manual entry's team, may read its history.
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/vendor_quotation_helper.php';
require_once '../includes/cost_avoidance_history.php';
require_once '../includes/cost_avoidance_helper.php';

function cah_fail($msg) {
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

try {
    $type = (string)($_POST['type'] ?? $_GET['type'] ?? '');
    $key  = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
    if ($key === '') cah_fail('Nothing to look up.');

    $rows = [];
    if ($type === 'project') {
        $canv = quotation_canvassing_lines($pdo, $key);
        if (!$canv['ok']) cah_fail($canv['message']);
        $auth = quotation_authorize_canvassing($pdo, $_SESSION, $canv['p_group'], $canv['lines']);
        if (!$auth['ok']) cah_fail($auth['message']);
        $rows = ca_hist_list($pdo, CA_HIST_QUOTATION_PROJECT, $key);
    } elseif ($type === 'ticket') {
        $tk = quotation_ticket_lines($pdo, $key);
        if (!$tk['ok']) cah_fail($tk['message']);
        $auth = quotation_authorize_canvassing($pdo, $_SESSION, $tk['p_group'], $tk['lines']);
        if (!$auth['ok']) cah_fail($auth['message']);
        $stmt = $pdo->prepare("
            SELECT id, entity_key, action, old_price, old_currency, old_fx_rate, new_price, new_currency, new_fx_rate,
                   detail, source, actor_name, created_at
            FROM cost_avoidance_edit_history
            WHERE entity_type = ? AND entity_key LIKE ?
            ORDER BY id DESC LIMIT 300
        ");
        $stmt->execute([CA_HIST_QUOTATION_TICKET, str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $key) . '|%']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pub = ca_hist_row_public($r);
            $pub['line'] = substr((string)$r['entity_key'], strlen($key) + 1);
            $rows[] = $pub;
        }
    } elseif ($type === 'manual') {
        $team_scope = ca_resolve_session_team_scope($pdo, (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''));
        if ($team_scope !== null && empty($team_scope)) cah_fail('Not allowed.');
        try {
            ca_assert_manual_team_allowed($team_scope, ca_manual_ca_owner_team($pdo, (int)$key));
        } catch (RuntimeException $e) {
            cah_fail('Outside your assigned team scope.');
        }
        $rows = ca_hist_list($pdo, CA_HIST_MANUAL, (string)(int)$key);
    } else {
        cah_fail('Unknown history type.');
    }

    echo json_encode(['status' => 'success', 'rows' => $rows], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    error_log('[cost-avoidance] ' . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again; if it keeps happening, tell the admin.']);
}
