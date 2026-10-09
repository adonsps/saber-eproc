<?php
require_once 'config/database.php';
$tables = ['users', 'rfqs', 'rfq_committee'];
foreach($tables as $t) {
    echo "--- Table: $t ---\n";
    try {
        $stmt = $pdo->query("DESCRIBE $t");
        foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            echo "{$row['Field']} - {$row['Type']}\n";
        }
    } catch(Exception $e) { echo "Table not found\n"; }
}
