<?php
require '../config/database.php';
$start = microtime(true);
$stmt = $pdo->query("SELECT prs.ticket_no, prs.ticket_line_item, prs.master_data_id, prs.pr_date, prs.p_org, prs.requestor_name, prs.item_description, prs.status_ticket, prs.assigned_pic, prs.assigned_by_ai, prs.canvassing_no, prs.canvassing_date, prs.po_number, prs.po_amount, prs.ticket_type FROM prs WHERE prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))");
$rows = $stmt->fetchAll();
$time = microtime(true) - $start;
echo "Query fetched " . count($rows) . " rows in " . number_format($time, 4) . " seconds.\n";
