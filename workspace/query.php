<?php
require_once 'config/database.php';
$stmt = $pdo->query("SELECT * FROM item_taxonomy_db_view LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
