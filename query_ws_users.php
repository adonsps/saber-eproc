<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SELECT email, role, is_active FROM users WHERE email LIKE '%kawanlama%' LIMIT 10");
foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['email'] . " | " . $row['role'] . " | " . ($row['is_active'] ?? 'null') . "\n";
}
