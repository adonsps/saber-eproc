<?php
require_once 'config/database.php';
$stmt = $pdo->query("SHOW CREATE TABLE rfq_vendors");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
echo $row['Create Table'];
