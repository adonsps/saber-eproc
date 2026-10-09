<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SHOW COLUMNS FROM rfq_committee");
$res1 = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt = $pdo->query("SHOW COLUMNS FROM rfq_vendors");
$res2 = $stmt->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('schema_dump.txt', print_r($res1, true) . "\n\n" . print_r($res2, true));
