<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SHOW COLUMNS FROM rfq_committee");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
$stmt2 = $pdo->query("SHOW COLUMNS FROM rfq_vendors");
print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));
