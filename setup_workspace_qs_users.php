<?php
// setup_workspace_qs_users.php
// Script to insert QS users into workspace users table AND VSS users table

require_once __DIR__ . '/config.php'; // Provides $conn (MySQLi for VSS)
require_once __DIR__ . '/workspace/config/database.php'; // Provides $pdo (PDO for Workspace)

$qs_users = [
    ['name' => 'Devi', 'email' => 'devi.prm@kawanlama.com'],
    ['name' => 'Devi Retno Wulan Herawati Siagian', 'email' => 'devi.siagian@kawanlama.com'],
    ['name' => 'Devina Susilowati', 'email' => 'b.susilowati@kawanlama.com'],
    ['name' => 'Agnes Sari Diah Astuti', 'email' => 'agnes.astuti@kawanlama.com'],
    ['name' => 'Wahyu Purnayudha', 'email' => 'wahyu.purnayudha@kawanlama.com'],
    ['name' => 'Rina Susanto', 'email' => 'rina.susanto@kawanlama.com'],
    ['name' => 'Adeline Wong', 'email' => 'adeline.wong@kawanlama.com'],
    ['name' => 'Angga Rachmanto', 'email' => 'angga.rachmanto@kawanlama.com'],
    ['name' => 'Djohan', 'email' => 'djohan.prm@kawanlama.com'],
    ['name' => 'Fikri Taufik', 'email' => 'fikri.taufik@kawanlama.com'],
    ['name' => 'Andy Adtnuary', 'email' => 'andy.adtnuary@kawanlama.com'],
    ['name' => 'Lanny Sularko', 'email' => 'lanny.sularko@kawanlamacorp.com'],
];

echo "<h2 style='font-family: sans-serif; color: #0d6efd;'>Setup QS Users in Workspace & VSS</h2>";
echo "<div style='font-family: monospace; font-size: 14px; background: #f4f4f4; padding: 20px; border-radius: 8px;'>";

$success_count = 0;
$skip_count = 0;

$default_password_plain = 'Kawanlama123!';
$hashed_password = password_hash($default_password_plain, PASSWORD_DEFAULT);

foreach ($qs_users as $user) {
    $email = trim(strtolower($user['email']));
    $name = trim($user['name']);
    
    // Use part before @ as username
    $username_parts = explode('@', $email);
    $username = $username_parts[0];
    
    $ws_role = 'QS'; // Role in workspace
    $vss_role = 'project'; // Role in VSS

    // 1. Check and Insert in Workspace
    $stmt_ws_check = $pdo->prepare("SELECT username FROM users WHERE email = ? OR username = ?");
    $stmt_ws_check->execute([$email, $username]);
    
    $ws_inserted = false;
    if ($stmt_ws_check->rowCount() > 0) {
        echo "<span style='color: #ff9800;'>[WORKSPACE SKIPPED]</span> {$email} ({$username}) sudah terdaftar.<br>";
    } else {
        $stmt_ws_insert = $pdo->prepare("INSERT INTO users (username, name, email, role, password) VALUES (?, ?, ?, ?, ?)");
        if ($stmt_ws_insert->execute([$username, $name, $email, $ws_role, $hashed_password])) {
            echo "<span style='color: #4caf50;'>[WORKSPACE SUCCESS]</span> Berhasil mendaftarkan <strong>{$name}</strong> di Workspace.<br>";
            $ws_inserted = true;
        } else {
            echo "<span style='color: #f44336;'>[WORKSPACE ERROR]</span> Gagal mendaftarkan {$email} di Workspace.<br>";
        }
    }

    // 2. Check and Insert in VSS
    $stmt_vss_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt_vss_check->bind_param("s", $email);
    $stmt_vss_check->execute();
    $result_vss_check = $stmt_vss_check->get_result();
    
    $vss_inserted = false;
    if ($result_vss_check->num_rows > 0) {
        echo "<span style='color: #ff9800;'>[VSS SKIPPED]</span> {$email} sudah terdaftar di VSS.<br>";
    } else {
        $stmt_vss_insert = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt_vss_insert->bind_param("ssss", $email, $hashed_password, $name, $vss_role);
        
        if ($stmt_vss_insert->execute()) {
            echo "<span style='color: #4caf50;'>[VSS SUCCESS]</span> Berhasil mendaftarkan <strong>{$name}</strong> di VSS.<br>";
            $vss_inserted = true;
        } else {
            echo "<span style='color: #f44336;'>[VSS ERROR]</span> Gagal mendaftarkan {$email} di VSS: " . $conn->error . "<br>";
        }
    }

    if ($ws_inserted || $vss_inserted) {
        $success_count++;
    } else {
        $skip_count++;
    }
    echo "<br>";
}

echo "<hr>";
echo "<h3>Rekapitulasi:</h3>";
echo "Berhasil Ditambahkan (WS/VSS): <strong>{$success_count}</strong><br>";
echo "Dilewati Keduanya (Sudah Ada): <strong>{$skip_count}</strong><br>";

echo "</div>";
echo "<br><div style='padding: 15px; background: #ffeb3b; border-left: 5px solid #ff9800; font-weight: bold;'>⚠️ PENTING: Segera hapus file ini dari server Anda setelah proses selesai.</div>";
?>
