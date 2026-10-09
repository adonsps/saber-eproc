<?php
$_GET['load_mode'] = 'recent';
require 'config/database.php';

$persona = 'COMBINED';
$load_mode = $_GET['load_mode'];
$time_filter_sql = "";
if ($load_mode === 'recent') {
    $time_filter_sql = " AND (prs.created_at >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) OR prs.created_at IS NULL OR prs.pr_date IS NULL) ";
}
echo "Mode: $load_mode, SQL: $time_filter_sql\n";
$stmt = $pdo->query("SELECT count(*) FROM prs WHERE prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != '')) $time_filter_sql");
echo "Recent count: " . $stmt->fetchColumn() . "\n";

$_GET['load_mode'] = 'historical';
$load_mode = $_GET['load_mode'];
$time_filter_sql = "";
if ($load_mode === 'historical') {
    $time_filter_sql = " AND prs.created_at < DATE_SUB(CURDATE(), INTERVAL 60 DAY) AND prs.created_at IS NOT NULL AND prs.pr_date IS NOT NULL ";
}
$stmt = $pdo->query("SELECT count(*) FROM prs WHERE prs.status_ticket NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket') AND (prs.pr_date >= '2026-05-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != '')) $time_filter_sql");
echo "Historical count: " . $stmt->fetchColumn() . "\n";
