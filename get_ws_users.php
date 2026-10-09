<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SELECT username, email, name, role FROM users LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
