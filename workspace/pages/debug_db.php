<?php
// pages/debug_db.php
require_once '../includes/auth.php';
require_manager();
require_once '../config/database.php';

echo "<h2>Database Diagnostics</h2>";
echo "Connecting to: " . htmlspecialchars($db) . "<br>";

try {
    $stmt = $pdo->query("DESCRIBE prs");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<h3>Current Columns in `prs`:</h3><pre>";
    print_r($columns);
    echo "</pre>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Failed describing prs table: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>Attempting to add `pillar` column manually:</h3>";
try {
    $pdo->exec("ALTER TABLE prs ADD COLUMN pillar VARCHAR(100) DEFAULT NULL");
    echo "<p style='color: green;'>Successfully added `pillar` column!</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Failed adding `pillar` column: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h3>Attempting to select `pillar` from `prs`:</h3>";
try {
    $stmt = $pdo->query("SELECT pillar FROM prs LIMIT 1");
    echo "<p style='color: green;'>Successfully selected `pillar`!</p>";
} catch (PDOException $e) {
    echo "<p style='color: red;'>Failed selecting `pillar`: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
