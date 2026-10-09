<?php
require 'workspace/config/database.php';
$stmt = $pdo->query("SELECT email, is_active FROM users WHERE email LIKE '%kawanlama%' LIMIT 5");
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
