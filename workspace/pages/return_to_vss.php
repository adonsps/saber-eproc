<?php
// workspace/pages/return_to_vss.php
session_start();

if (!isset($_SESSION['email'])) {
    header("Location: login.php");
    exit;
}

// Akun lihat-saja (view only) tidak punya tujuan di VSS: role VSS-nya 'viewer',
// yang oleh switch di bawah jatuh ke default (index.php). Lebih penting lagi,
// baris "$_SESSION['role'] = $vss_user['role']" di bawah menimpa role sesi dengan
// ejaan huruf kecil dari tabel VSS — dulu itu melepas seluruh pembatasan Viewer.
// ws_is_viewer() kini membandingkan tanpa peduli besar-kecil huruf sehingga celah
// itu tertutup; penolakan di sini adalah lapis kedua, sekaligus mencegah halaman
// ini menyentuh sesi Viewer sama sekali. Dibandingkan langsung (bukan lewat
// ws_is_viewer()) karena includes/auth.php memanggil session_start() sendiri.
if (strcasecmp((string)($_SESSION['role'] ?? ''), 'Viewer') === 0) {
    header("Location: dashboard_mgr.php");
    exit;
}

$email = $_SESSION['email'];

try {
    // 1. KONEKSI KE DATABASE VSS
    $db_host = getenv('DB_HOST') ?: "localhost";
    if (php_sapi_name() === 'cli-server') {
        $vss_pdo = new PDO("mysql:host=127.0.0.1;dbname=u894129685_vmsportal;charset=utf8mb4", "root", "");
    } else {
        $db_vss_pass = "Xcel007!";
        $vss_pdo = new PDO("mysql:host=$db_host;dbname=u894129685_vmsportal;charset=utf8mb4", "u894129685_adonisvm", $db_vss_pass);
    }
    $vss_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt_vss = $vss_pdo->prepare("SELECT id, email, full_name, role FROM users WHERE email = ? AND is_active = 1");
    $stmt_vss->execute([$email]);
    $vss_user = $stmt_vss->fetch(PDO::FETCH_ASSOC);

    if ($vss_user) {
        // Set VSS session variables
        $_SESSION['user_id'] = $vss_user['id'];
        $_SESSION['role'] = $vss_user['role']; 
        $_SESSION['user_name'] = $vss_user['full_name'];

        // Route to the correct VSS Dashboard
        switch ($vss_user['role']) {
            case 'sourcing':
                header("Location: ../../modules/sourcing/dashboard.php");
                break;
            case 'vendor_mgt':
            case 'vendormgt_head':
                header("Location: ../../modules/vendormgt/dashboard.php");
                break;
            case 'admin':
                header("Location: ../../modules/admin/dashboard.php");
                break;
            default:
                header("Location: ../../index.php");
                break;
        }
        exit;
    } else {
        echo "<script>alert('Akses Ditolak: Akun Anda tidak ditemukan di sistem VSS utama.'); window.history.back();</script>";
        exit;
    }
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage());
}
