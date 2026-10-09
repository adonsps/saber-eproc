<?php
require 'workspace/config/database.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS rfq_vendor_approvals (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rfq_vendor_id INT NOT NULL,
        committee_username VARCHAR(255) NOT NULL,
        status ENUM('PENDING', 'APPROVED', 'REJECTED') DEFAULT 'PENDING',
        approved_at TIMESTAMP NULL,
        UNIQUE KEY uq_vendor_committee (rfq_vendor_id, committee_username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "Created rfq_vendor_approvals table.\n";
} catch (\PDOException $e) {
    echo "Error creating table: " . $e->getMessage() . "\n";
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM rfq_vendors LIKE 'addition_status'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN addition_status ENUM('APPROVED', 'PENDING', 'REJECTED') DEFAULT 'APPROVED'");
        echo "Added addition_status to rfq_vendors.\n";
    }
} catch (\PDOException $e) {
    echo "Error adding addition_status: " . $e->getMessage() . "\n";
}

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM rfq_vendors LIKE 'addition_reason'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE rfq_vendors ADD COLUMN addition_reason TEXT NULL");
        echo "Added addition_reason to rfq_vendors.\n";
    }
} catch (\PDOException $e) {
    echo "Error adding addition_reason: " . $e->getMessage() . "\n";
}
echo "Migration complete.\n";
