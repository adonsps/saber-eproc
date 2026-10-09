<?php
// pages/ajax_ticket_lines.php
// Read-only line lookup for the reassign modal: #prTable is serverSide, so a
// ticket's other lines usually aren't rendered. The write path stays in
// dashboard_mgr.php.
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect (require_manager() would), answer with JSON.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
    exit;
}
$role = $_SESSION['role'] ?? '';
if ($role !== 'Manager' && $role !== 'Category Manager') {
    echo json_encode(['ok' => false, 'message' => 'Manager access required.']);
    exit;
}

require_once '../config/database.php';

$raw = $_POST['tickets'] ?? [];
if (!is_array($raw)) { $raw = [$raw]; }

// Capped so a hand-crafted POST can't ask for the whole table.
$tickets = [];
foreach ($raw as $t) {
    $t = trim((string)$t);
    if ($t !== '') { $tickets[$t] = true; }
}
$tickets = array_slice(array_keys($tickets), 0, 200);

if (empty($tickets)) {
    echo json_encode(['ok' => true, 'tickets' => []]);
    exit;
}

try {
    $ph = implode(',', array_fill(0, count($tickets), '?'));

    // Flagged so the modal can warn; fails OPEN when project_pgroups isn't
    // deployed, like dashboard_mgr.php.
    $project_tickets = [];
    try {
        $chk = $pdo->prepare("SELECT DISTINCT prs.ticket_no FROM prs JOIN project_pgroups pp ON pp.p_group = prs.p_group AND pp.status = 'ACTIVE' WHERE prs.ticket_no IN ($ph)");
        $chk->execute($tickets);
        foreach ($chk->fetchAll(PDO::FETCH_COLUMN) as $t) { $project_tickets[(string)$t] = true; }
    } catch (PDOException $e) {
        $project_tickets = [];
    }

    $stmt = $pdo->prepare("
        SELECT ticket_no, ticket_line_item, item_description, status_ticket, assigned_pic, assigned_by_ai,
               requestor_name, pr_date, p_org, p_group, site_allocation
        FROM prs
        WHERE ticket_no IN ($ph)
        ORDER BY ticket_no, ticket_line_item
    ");
    $stmt->execute($tickets);

    // Ticket-level fields repeat on every line; first row seen wins.
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $t = (string)$r['ticket_no'];
        if (!isset($out[$t])) {
            $out[$t] = [
                'ticket_no'       => $t,
                'is_project'      => isset($project_tickets[$t]),
                'requestor_name'  => (string)($r['requestor_name'] ?? ''),
                'pr_date'         => (string)($r['pr_date'] ?? ''),
                'p_org'           => (string)($r['p_org'] ?? ''),
                'p_group'         => (string)($r['p_group'] ?? ''),
                'site_allocation' => (string)($r['site_allocation'] ?? ''),
                'lines'           => [],
            ];
        }
        $out[$t]['lines'][] = [
            'line'            => (string)$r['ticket_line_item'],
            'description'     => (string)($r['item_description'] ?? ''),
            'status'          => (string)($r['status_ticket'] ?? ''),
            'assigned_pic'    => ($r['assigned_pic'] === null || $r['assigned_pic'] === '') ? null : (string)$r['assigned_pic'],
            'assigned_by_ai'  => (int)($r['assigned_by_ai'] ?? 0),
        ];
    }

    echo json_encode(['ok' => true, 'tickets' => array_values($out)]);
} catch (PDOException $e) {
    // Never leak SQL/PDO internals to the dashboard.
    echo json_encode(['ok' => false, 'message' => 'Could not load the line items for this ticket.']);
}
