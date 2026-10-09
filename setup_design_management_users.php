<?php
// setup_design_management_users.php
require_once 'workspace/config/database.php';

try {
    // 1. Database Migrations
    echo "Running Database Migrations...<br>";
    try {
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN pending_deadline DATETIME NULL");
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN deadline_request_by VARCHAR(100) NULL");
        $pdo->exec("ALTER TABLE rfqs ADD COLUMN deadline_request_status VARCHAR(20) NULL");
        echo "✅ Migration successful: deadline columns added to rfqs table.<br>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
            echo "ℹ️ Migration already applied (columns exist).<br>";
        } else {
            echo "❌ Migration failed: " . $e->getMessage() . "<br>";
        }
    }


    // 2. Insert Agnes Sari Diah Astuti
    echo "<br>Adding Project Design User...<br>";
    $agnes_email = 'agnes.astuti@kawanlama.com';
    $agnes_name = 'Agnes Sari Diah Astuti';
    $agnes_pass = password_hash('password123', PASSWORD_DEFAULT);

    // Workspace DB
    $stmt_ws = $pdo->prepare("SELECT id FROM users WHERE email = ? OR username = ?");
    $stmt_ws->execute([$agnes_email, 'agnes.astuti']);
    if (!$stmt_ws->fetch()) {
        $pdo->prepare("INSERT INTO users (username, password, name, email, role) VALUES (?, ?, ?, ?, ?)")
            ->execute(['agnes.astuti', $agnes_pass, $agnes_name, $agnes_email, 'Project Design']);
        echo "✅ Added $agnes_name to Workspace DB.<br>";
    } else {
        $pdo->prepare("UPDATE users SET role = 'Project Design' WHERE email = ? OR username = ?")
            ->execute([$agnes_email, 'agnes.astuti']);
        echo "ℹ️ Updated $agnes_name in Workspace DB.<br>";
    }

    // VMS (VSS) DB
    if ($is_qa) {
        $vss_db = 'u894129685_qa_vmsportal';
        $vss_user = 'u894129685_sigitqa';
    } else {
        $vss_db = 'u894129685_vmsportal';
        $vss_user = 'u894129685_adonisvm';
    }
    $vss_pass = 'Xcel007!';
    
    $vss_conn = new PDO("mysql:host={$host};dbname={$vss_db};charset=utf8mb4", $vss_user, $vss_pass);
    $vss_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt_vss = $vss_conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt_vss->execute([$agnes_email]);
    if (!$stmt_vss->fetch()) {
        $vss_conn->prepare("INSERT INTO users (username, password, name, email, role, business_unit, login_type) VALUES (?, ?, ?, ?, ?, 'Kawan Lama', 'internal')")
                 ->execute(['agnes.astuti', $agnes_pass, $agnes_name, $agnes_email, 'project']);
        echo "✅ Added $agnes_name to VSS DB.<br>";
    } else {
        echo "ℹ️ $agnes_name already exists in VSS DB.<br>";
    }

    // 3. Update Lanny Sularko Role
    echo "<br>Updating Project Management User...<br>";
    $lanny_email = 'lanny.sularko@kawanlamacorp.com';
    $pdo->prepare("UPDATE users SET role = 'Project Management' WHERE email = ? OR username = ?")
        ->execute([$lanny_email, 'lanny.sularko']);
    echo "✅ Updated Lanny Sularko to 'Project Management' in Workspace DB.<br>";

    $vss_conn->prepare("UPDATE users SET role = 'project' WHERE email = ?")
             ->execute([$lanny_email]);
    echo "✅ Updated Lanny Sularko in VSS DB.<br>";

    echo "<br><b>🎉 Setup Complete!</b>";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>
