<?php
// alter_sku_dictionary_mapping.php
require_once __DIR__ . '/config/database.php';

try {
    $pdo->exec("
        ALTER TABLE sku_dictionary 
        ADD COLUMN level_0 VARCHAR(150) AFTER sku,
        ADD COLUMN level_1 VARCHAR(150) AFTER level_0,
        ADD COLUMN level_2 VARCHAR(150) AFTER level_1,
        ADD COLUMN level_3 VARCHAR(150) AFTER level_2,
        ADD COLUMN level_4 VARCHAR(255) AFTER level_3,
        ADD COLUMN mapping_status VARCHAR(50) AFTER level_4;
    ");
    echo "SKU Dictionary table altered successfully to include Level 0-4 and mapping status!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
