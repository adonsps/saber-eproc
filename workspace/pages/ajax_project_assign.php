<?php
// pages/ajax_project_assign.php
// Manual assign / reassign handler for Project Head dashboard.
// - No AI, no external API. assigned_by_ai is always set to 0 (manual) on assign.
// - Update is scoped by ticket_no + p_group (never ticket_no alone).
// - Intercompany / Intercompany New Store rows are read-only (admin_mappings route).
header('Content-Type: application/json');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect, return JSON instead.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

// Write endpoint (UPDATE on assignment): the read-only Viewer role is refused.
ws_require_write();

require_once '../config/database.php';
require_once '../includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date

$intercompany_types = ['intercompany', 'intercompany new store'];

try {
    $allowed_groups = get_project_head_groups($pdo, $_SESSION);
    if (empty($allowed_groups)) {
        echo json_encode(['status' => 'error', 'message' => 'You do not have Project Head access.']);
        exit;
    }

    $ticket_no   = trim((string)($_POST['ticket_no'] ?? ''));
    $p_group     = trim((string)($_POST['p_group'] ?? ''));
    $new_officer = trim((string)($_POST['new_officer'] ?? '')); // '' => unassign

    if ($ticket_no === '' || $p_group === '') {
        echo json_encode(['status' => 'error', 'message' => 'Ticket number and P.Group are required.']);
        exit;
    }

    // 1. The p_group must be an ACTIVE Project p_group inside one of the caller's groups.
    $stmt = $pdo->prepare("
        SELECT project_group_number, project_group_name
        FROM project_pgroups
        WHERE p_group = ? AND status = 'ACTIVE'
        LIMIT 1
    ");
    $stmt->execute([$p_group]);
    $pg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pg || !in_array((int)$pg['project_group_number'], $allowed_groups, true)) {
        echo json_encode(['status' => 'error', 'message' => 'P.Group is not within your authorised Project groups.']);
        exit;
    }
    $group_number = (int)$pg['project_group_number'];

    // 2. The ticket must actually have line(s) in this p_group.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM prs WHERE ticket_no = ? AND p_group = ?");
    $stmt->execute([$ticket_no, $p_group]);
    if ((int)$stmt->fetchColumn() === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Ticket has no lines in this P.Group.']);
        exit;
    }

    // 3. Block Intercompany rows: they keep the admin_mappings / system route.
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM prs
        WHERE ticket_no = ? AND p_group = ?
        AND LOWER(IFNULL(ticket_type, '')) IN ('intercompany', 'intercompany new store')
    ");
    $stmt->execute([$ticket_no, $p_group]);
    if ((int)$stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Intercompany tickets are routed via admin mappings and cannot be reassigned here.']);
        exit;
    }

    // 4. If assigning (not unassigning), the officer must be eligible for THIS p_group.
    if ($new_officer !== '') {
        $stmt = $pdo->prepare("
            SELECT 1 FROM project_pgroup_officers
            WHERE p_group = ? AND officer_name = ? AND active = 1
            LIMIT 1
        ");
        $stmt->execute([$p_group, $new_officer]);
        if (!$stmt->fetchColumn()) {
            echo json_encode(['status' => 'error', 'message' => 'Selected officer is not eligible for this P.Group.']);
            exit;
        }
    }

    // 5. Apply, scoped to ticket_no + p_group, never touching Intercompany lines.
    if ($new_officer === '') {
        // Unassign -> back to clean pending state.
        // The stamp assignments come first and are gated on the PIC really
        // changing: this statement spans every line of the ticket in this
        // p_group, and lines that were already unassigned must keep the trail
        // of whoever last set their PIC. See includes/pr_audit_stamp.php.
        $upd = $pdo->prepare("
            UPDATE prs
            SET " . pr_stamp_pic_set_sql('NULL') . ",
                assigned_pic = NULL, assigned_by_ai = NULL
            WHERE ticket_no = ? AND p_group = ?
            AND LOWER(IFNULL(ticket_type, '')) NOT IN ('intercompany', 'intercompany new store')
        ");
        $upd->execute([pr_stamp_by(), pr_stamp_now(), $ticket_no, $p_group]);
    } else {
        $upd = $pdo->prepare("
            UPDATE prs
            SET " . pr_stamp_pic_set_sql() . ",
                assigned_pic = ?, assigned_by_ai = 0
            WHERE ticket_no = ? AND p_group = ?
            AND LOWER(IFNULL(ticket_type, '')) NOT IN ('intercompany', 'intercompany new store')
        ");
        $upd->execute(array_merge(
            pr_stamp_pic_params($new_officer),
            [$new_officer, $ticket_no, $p_group]
        ));
    }

    echo json_encode([
        'status'       => 'success',
        'ticket_no'    => $ticket_no,
        'p_group'      => $p_group,
        'group'        => $group_number,
        'new_officer'  => ($new_officer === '' ? null : $new_officer),
        'rows_updated' => $upd->rowCount()
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'System Error: ' . $e->getMessage()]);
}
?>
