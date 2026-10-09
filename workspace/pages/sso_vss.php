<?php
// workspace/pages/sso_vss.php
session_start();

// 1. Pastikan user masuk melalui VSS
if (!isset($_SESSION['user_id'])) {
    // Jika tidak ada sesi VSS, lempar kembali ke halaman login VSS
    header("Location: ../../login.php");
    exit;
}

$vss_user_id = $_SESSION['user_id'];

try {
    // 2. KONEKSI KE DATABASE VSS (Untuk mengambil Email berdasarkan ID user yang sedang login)
    $db_host = getenv('DB_HOST') ?: "localhost";
    $db_vss_pass = "Xcel007!"; 
    $vss_pdo = new PDO("mysql:host=$db_host;dbname=u894129685_vmsportal;charset=utf8mb4", "u894129685_adonisvm", $db_vss_pass);
    $vss_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt_vss = $vss_pdo->prepare("SELECT email FROM users WHERE id = ? AND is_active = 1");
    $stmt_vss->execute([$vss_user_id]);
    $vss_user = $stmt_vss->fetch(PDO::FETCH_ASSOC);

    if ($vss_user) {
        $email = $vss_user['email'];

        // 3. KONEKSI KE DATABASE WORKSPACE (Untuk Cek Akses & Role)
        require_once '../config/database.php'; // Menggunakan koneksi PDO bawaan Workspace

        $stmt_ws = $pdo->prepare("SELECT id, username, name, role FROM users WHERE email = ?");
        $stmt_ws->execute([$email]);
        $ws_user = $stmt_ws->fetch(PDO::FETCH_ASSOC);

        if ($ws_user) {
            // 4. Timpa / Set Sesi menjadi mode Workspace
            $_SESSION['user_id']  = $ws_user['id'];
            $_SESSION['name']     = $ws_user['name'];
            $_SESSION['role']     = $ws_user['role'];
            $_SESSION['username'] = $ws_user['username'];
            $_SESSION['email']    = $email;

            // 5. Routing cerdas ke Dashboard yang tepat (Berdasarkan referensi Login Anda)
            if ($ws_user['role'] === 'Manager' || $ws_user['role'] === 'Category Manager') {
                header("Location: dashboard_mgr.php");
            } elseif (in_array($ws_user['role'], ['Project Director', 'QS'])) {
                header("Location: dashboard_project.php");
            } elseif ($ws_user['role'] === 'Admin') {
                header("Location: dashboard_admin.php");
            } elseif ($ws_user['role'] === 'STC') {
                header("Location: dashboard_stc.php");
            } else {
                // Default untuk PIC
                header("Location: dashboard_pic.php");
            }
            exit;
        } else {
            // Jika email VSS tidak ditemukan di tabel users Workspace
            echo "<script>alert('Akses Ditolak: Akun Anda tidak terdaftar dalam sistem Workspace.'); window.history.back();</script>";
            exit;
        }
    } else {
        echo "<script>alert('Gagal memverifikasi identitas VSS Anda.'); window.history.back();</script>";
        exit;
    }
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>