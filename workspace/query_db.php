<?php
require_once __DIR__ . '/config/database.php';
$stmt = $pdo->query("SELECT ticket_no, ticket_type, assigned_pic FROM prs WHERE assigned_pic = 'Agustriani Utami' AND LOWER(ticket_type) IN ('intercompany', 'intercompany new store') LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
