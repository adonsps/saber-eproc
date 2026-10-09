<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SELECT prs.ticket_no, prs.p_group, m.team_name, prs.assigned_pic, prs.ticket_type FROM prs LEFT JOIN pgroup_team_mappings m ON prs.p_group = m.p_group WHERE prs.assigned_pic LIKE '%Nadia%' OR prs.assigned_pic LIKE '%Josia%' OR prs.assigned_pic LIKE 'MFM%' LIMIT 10");
foreach($stmt->fetchAll() as $row) {
    echo json_encode($row) . "\n";
}
