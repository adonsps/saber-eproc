<?php
// pages/ajax_manual_ca_save.php
// Manual Cost Avoidance from the dashboards' "Manual Input" modal
// (includes/manual_ca_modal_ui.php). Same rules as the Manual tab of
// cost_avoidance.php -- both go through includes/manual_ca_helper.php.
//
//   action=init    -> options for the modal (teams in your scope, UoM, currencies
//                     with today's rate)
//   action=save    -> create (no m_id) or update (m_id); editing a saved entry
//                     answers status=confirm first, then saves with confirm_edit=1
//   action=delete  -> soft delete (logged in the history)
//
// Team scope: ca_resolve_session_team_scope() exactly as cost_avoidance.php
// resolves it -- Category Manager / Viewer-read unrestricted, Managers and PICs
// limited to their own team(s); no resolvable team = refused.
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

// View-only accounts can't write.
ws_require_write();

require_once '../config/database.php';
require_once '../includes/manual_ca_helper.php';

function mca_out($payload) {
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    $scope = ca_resolve_session_team_scope($pdo, (int)$_SESSION['user_id'], (string)($_SESSION['role'] ?? ''));
    if ($scope !== null && empty($scope)) {
        mca_out(['status' => 'error', 'message' => 'Manual Cost Avoidance is not available for your account (no team is assigned to it).']);
    }
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'init') {
        $allTeams = ca_manual_team_options();
        $teams = $scope === null ? $allTeams : array_intersect_key($allTeams, array_flip($scope));

        $currencies = [];
        foreach (ca_get_active_currencies($pdo) as $code => $c) {
            if ($code !== 'IDR' && (float)$c['rate_to_idr'] <= 0) continue;
            $currencies[] = ['code' => $code, 'symbol' => $c['symbol'], 'rate_to_idr' => (float)$c['rate_to_idr']];
        }

        mca_out([
            'status'       => 'success',
            'teams'        => $teams,   // code => full name (the stored value)
            'default_team' => $scope !== null && !empty($scope) ? ($allTeams[$scope[0]] ?? '') : '',
            'uoms'         => ['AU', 'BOX', 'BT', 'CAN', 'CAR', 'DPR', 'EA', 'GAL', 'KG', 'M', 'M2', 'M3', 'MON', 'PAA', 'PAC', 'PC', 'RIM', 'ROL', 'SET', 'UNT'],
            'currencies'   => $currencies,
        ]);
    }

    if ($action === 'save') {
        $r = manual_ca_save($pdo, $_SESSION, $scope, $_POST, 'DASHBOARD', !empty($_POST['confirm_edit']));
        if (!empty($r['needs_confirm'])) {
            mca_out(['status' => 'confirm', 'message' => $r['message'], 'changes' => $r['changes']]);
        }
        if (!$r['ok']) mca_out(['status' => 'error', 'message' => $r['message']]);
        mca_out(['status' => 'success', 'id' => $r['id'], 'unchanged' => !empty($r['unchanged'])]);
    }

    if ($action === 'delete') {
        $r = manual_ca_delete($pdo, $_SESSION, $scope, (int)($_POST['m_id'] ?? 0), 'DASHBOARD');
        mca_out($r['ok'] ? ['status' => 'success'] : ['status' => 'error', 'message' => $r['message']]);
    }

    mca_out(['status' => 'error', 'message' => 'Unknown action.']);
} catch (Throwable $e) {
    error_log('[cost-avoidance] ' . $e->getMessage()); mca_out(['status' => 'error', 'message' => 'Something went wrong. Please try again; if it keeps happening, tell the admin.']);
}
