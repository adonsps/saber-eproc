<?php
require_once 'pages/../config/database.php';
$stmt = $pdo->query("SHOW INDEXES FROM prs");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
