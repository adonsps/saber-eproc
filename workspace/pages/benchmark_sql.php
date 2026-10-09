<?php
require_once '../config/database.php';

$start = microtime(true);
$canvassing_status_select = "COALESCE((SELECT status FROM p_canvassing_v2 c WHERE c.canvassing_no = prs.canvassing_no LIMIT 1), prs.status_ticket)";

$sql = "SELECT prs.ticket_no, prs.ticket_line_item, prs.master_data_id, prs.pr_date, prs.p_org, prs.requestor_name, prs.item_description, prs.sku, prs.qty, prs.status_ticket, prs.assigned_pic, prs.assigned_by_ai, prs.canvassing_no, {$canvassing_status_select}, prs.canvassing_date, prs.po_number, prs.po_amount, m.team_name, prs.ticket_type FROM prs LEFT JOIN pgroup_team_mappings m ON prs.p_group = m.p_group WHERE prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != '')) ORDER BY prs.created_at DESC";

try {
    $stmt = $pdo->query($sql);
    $res = $stmt->fetchAll();
    $end = microtime(true);
    echo "Query time: " . ($end - $start) . " seconds\n";
    echo "Row count: " . count($res) . "\n";
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
