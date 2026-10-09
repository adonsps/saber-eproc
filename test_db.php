<?php
require_once 'workspace/config/database.php';
$stmt = $pdo->query("SELECT level_0, level_1, level_2, level_3, level_4 FROM master_data LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
