<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SHOW FULL COLUMNS FROM users WHERE Field = 'email'");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
