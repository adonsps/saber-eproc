<?php
require 'workspace/config/database.php';
$ostmt = $pdo->query("SELECT p_group, project_group_number, officer_name, officer_nip FROM project_pgroup_officers WHERE active = 1");
foreach ($ostmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    echo $o['officer_name'] . "\n";
}
