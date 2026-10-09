<?php
// migrate_deadline.php
require_once 'workspace/config/database.php';

try {
    $pdo->exec("ALTER TABLE rfqs ADD COLUMN pending_deadline DATETIME NULL");
    $pdo->exec("ALTER TABLE rfqs ADD COLUMN deadline_request_by VARCHAR(100) NULL");
    $pdo->exec("ALTER TABLE rfqs ADD COLUMN deadline_request_status VARCHAR(20) NULL");
    
    echo "Migration successful: pending_deadline, deadline_request_by, and deadline_request_status columns added to rfqs table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Migration already applied or columns exist.\n";
    } else {
        echo "Migration failed: " . $e->getMessage() . "\n";
    }
}
?>
