<?php
// workspace/import_db.php - Helper script to import database dumps

// 1. Basic security check: only run this if requested with a key to prevent unauthorized runs
$secret_key = "import007";
if (($_GET['key'] ?? '') !== $secret_key) {
    die("Access denied. Please provide the correct secret key (e.g. ?key=import007).");
}

$host = getenv('DB_HOST') ?: 'localhost';
$is_qa = (strpos(__DIR__, '/qa/') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '/qa/') !== false);

// Credentials for Workspace DB
if ($host === 'db') {
    $ws_db = 'u894129685_workspace'; $ws_user = 'u894129685_workspace007'; $ws_pass = 'Xcel007!';
} elseif ($is_qa) {
    $ws_db = 'u894129685_workspace_qa'; $ws_user = 'u894129685_workspace1'; $ws_pass = 'Xcel007!';
} else {
    $ws_db = 'u894129685_workspace'; $ws_user = 'u894129685_workspace007'; $ws_pass = 'Xcel007!';
}

// Credentials for VMS Portal DB
if ($host === 'db') {
    $vss_db = 'u894129685_vmsportal'; $vss_user = 'u894129685_adonisvm'; $vss_pass = 'Xcel007!';
} elseif ($is_qa) {
    $vss_db = 'u894129685_qa_vmsportal'; $vss_user = 'u894129685_sigitqa'; $vss_pass = 'Xcel007!';
} else {
    $vss_db = 'u894129685_vmsportal'; $vss_user = 'u894129685_adonisvm'; $vss_pass = 'Xcel007!';
}

function importSqlFile($host, $db, $user, $pass, $filepath) {
    if (!file_exists($filepath)) {
        echo "File not found: " . htmlspecialchars(basename($filepath)) . "<br>";
        return false;
    }
    
    echo "Connecting to $db...<br>";
    try {
        $mysqli = new mysqli($host, $user, $pass, $db);
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        echo "Reading SQL file...<br>";
        $sql = file_get_contents($filepath);
        
        echo "Executing SQL queries for $db (please wait)...<br>";
        if ($mysqli->multi_query($sql)) {
            $queriesCount = 0;
            do {
                if ($result = $mysqli->store_result()) {
                    $result->free();
                }
                $queriesCount++;
            } while ($mysqli->next_result());
            
            echo "Successfully imported " . htmlspecialchars(basename($filepath)) . " into $db ($queriesCount query segments executed)!<br><br>";
            $mysqli->close();
            return true;
        } else {
            throw new Exception("Error executing SQL: " . $mysqli->error);
        }
    } catch (Exception $e) {
        echo "<span style='color: red;'>Failed importing " . htmlspecialchars(basename($filepath)) . ": " . htmlspecialchars($e->getMessage()) . "</span><br><br>";
        return false;
    }
}

echo "<html><head><title>Database Import Utility</title><style>body{font-family:sans-serif;line-height:1.6;padding:20px;max-width:800px;margin:0 auto;background:#f3f4f6;color:#1f2937;}h2{color:#4f46e5;}pre{background:#e5e7eb;padding:15px;border-radius:6px;overflow-x:auto;}</style></head><body>";
echo "<h2>Database Import Utility</h2>";
echo "<p>Running in environment: <strong>" . ($host === 'db' ? 'Docker Local' : ($is_qa ? 'Hostinger QA' : 'Hostinger Production')) . "</strong></p><hr>";

importSqlFile($host, $ws_db, $ws_user, $ws_pass, __DIR__ . '/../db_backups/workspace_dump.sql');
importSqlFile($host, $vss_db, $vss_user, $vss_pass, __DIR__ . '/../db_backups/vmsportal_dump.sql');

echo "<hr><p><strong>Import complete! Remember to delete this file (workspace/import_db.php) for security.</strong></p>";
echo "</body></html>";
