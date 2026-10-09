<?php
// production_migration.php
// Place this in the 'workspace' directory or any directory where config/database.php is accessible.
// Run this file once by visiting it in your browser or executing via command line.
// Example: https://vssportal.online/vms/workspace/production_migration.php
// After it completes successfully, please delete this file.

require_once 'config/database.php'; // Adjust path if necessary

echo "<h2>Production Database Migration for AI Suggestions</h2>";

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Add Columns to master_data
    $stmt = $pdo->query("SHOW COLUMNS FROM master_data LIKE 'level_5'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE master_data ADD COLUMN level_5 VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN status VARCHAR(50) DEFAULT 'active'");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN ai_confidence INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN google_drive_link VARCHAR(255) DEFAULT NULL");
        $pdo->exec("ALTER TABLE master_data ADD COLUMN item_group_owner VARCHAR(255) DEFAULT NULL");
        echo "<p>✅ Added new columns to master_data.</p>";
        
        // 2. Adjust Unique Key constraint
        try {
            $pdo->exec("ALTER TABLE master_data DROP INDEX level_4");
            echo "<p>✅ Dropped old unique index on level_4.</p>";
        } catch (\PDOException $e) {
            echo "<p>⚠️ Could not drop old index (maybe it was already removed): " . $e->getMessage() . "</p>";
        }
        
        $pdo->exec("ALTER TABLE master_data ADD UNIQUE KEY unique_level4_5 (level_4, level_5)");
        echo "<p>✅ Created new unique index (level_4, level_5).</p>";
    } else {
        echo "<p>✅ master_data columns already exist.</p>";
    }

    // 3. Create ai_golden_rules table
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_golden_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rule_text TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    echo "<p>✅ ai_golden_rules table checked/created.</p>";
    
// 4. Add gp_officer to prs table
    $stmt = $pdo->query("SHOW COLUMNS FROM prs LIKE 'gp_officer'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE prs ADD COLUMN gp_officer VARCHAR(255) DEFAULT NULL AFTER assigned_by_ai");
        echo "<p>✅ Added gp_officer column to prs table.</p>";
    } else {
        $pdo->exec("ALTER TABLE prs MODIFY COLUMN gp_officer VARCHAR(255) DEFAULT NULL AFTER assigned_by_ai");
        echo "<p>✅ gp_officer column position updated in prs table.</p>";
    }

    echo "<h3>Migration Complete! Please delete this file for security.</h3>";

} catch (\PDOException $e) {
    echo "<h3>❌ Error during migration:</h3>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>
