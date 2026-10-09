<?php
require 'config/database.php';
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sku_dictionary (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sku VARCHAR(255) NOT NULL,
            level_0 VARCHAR(150),
            level_1 VARCHAR(150),
            level_2 VARCHAR(150),
            level_3 VARCHAR(150),
            level_4 VARCHAR(255),
            mapping_status VARCHAR(50),
            group_owner VARCHAR(255),
            area VARCHAR(255),
            detail TEXT,
            uom VARCHAR(50),
            est_price DECIMAL(15,2),
            buying_price DECIMAL(15,2),
            hashtag VARCHAR(255),
            article_sap VARCHAR(255),
            validity_from VARCHAR(50),
            validity_to VARCHAR(50),
            max_delivery_time_days INT,
            min_qty INT,
            warranty VARCHAR(255),
            extend_to_group VARCHAR(255),
            is_reguler VARCHAR(10) DEFAULT 'TRUE',
            is_active VARCHAR(10) DEFAULT 'TRUE',
            auto_create_po VARCHAR(10) DEFAULT 'FALSE',
            modified_by VARCHAR(255),
            modified_date VARCHAR(50)
        );
    ");
    echo 'Table created successfully!';
} catch (PDOException $e) {
    echo 'Error: ' . $e->getMessage();
}
