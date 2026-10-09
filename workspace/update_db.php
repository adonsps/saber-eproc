<?php
require_once 'config/database.php';
try {
    $pdo->exec("ALTER TABLE prs ADD COLUMN canvassing_status VARCHAR(255) DEFAULT NULL;");
    echo "Column added successfully.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
