<?php
// pages/login.php
require_once __DIR__ . '/../../includes/branding.php';
require_once __DIR__ . '/../../includes/auth_intended.php';
require_once '../config/database.php';
session_start();

// ?redirect= datang dari jalur sesi-habis (logout.php meneruskannya lewat query
// karena sesinya sudah dihancurkan). Dipindah ke sesi supaya tidak hilang bila
// pengguna salah kata sandi lebih dulu — form di bawah POST tanpa query.
if (isset($_GET['redirect'])) {
    auth_remember_intended($_GET['redirect']);
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Menangkap input email dari name="username" sesuai struktur form
    $username_input = trim(strtolower($_POST['username']));
    $password = $_POST['password'];

    try {
        // 1. KONEKSI KE DATABASE VSS (Cek Kredensial Portal Utama)
        $db_host = getenv('DB_HOST') ?: "localhost";
        if (php_sapi_name() === 'cli-server') {
            $vss_pdo = new PDO("mysql:host=127.0.0.1;dbname=u894129685_vmsportal;charset=utf8mb4", "root", "");
        } else {
            $db_vss_pass = "Xcel007!";
            $vss_pdo = new PDO("mysql:host=$db_host;dbname=u894129685_vmsportal;charset=utf8mb4", "u894129685_adonisvm", $db_vss_pass);
        }
        $vss_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Cek kecocokan email dan password di VSS
        $stmt_vss = $vss_pdo->prepare("SELECT id, email, full_name, password_hash FROM users WHERE email = ? AND is_active = 1");
        $stmt_vss->execute([$username_input]);
        $vss_user = $stmt_vss->fetch(PDO::FETCH_ASSOC);

        if ($vss_user && password_verify($password, $vss_user['password_hash'])) {
            
            // 2. KONEKSI KE DATABASE WORKSPACE (Cek Akses/Role)
            // Menggunakan $pdo bawaan dari require '../config/database.php'
            $stmt_ws = $pdo->prepare("SELECT id, username, name, role, team_name FROM users WHERE email = ? AND (is_active = 1 OR is_active IS NULL)");
            $stmt_ws->execute([$username_input]);
            $ws_user = $stmt_ws->fetch(PDO::FETCH_ASSOC);

            if ($ws_user) {
                if ($ws_user['role'] === 'Unassigned Role') {
                    $error = "Akses ditolak: Akun Anda belum ditetapkan role (Unassigned Role). Hubungi Category Manager.";
                } else {
                    // Buang cookie PHPSESSID kembar, kembaran dari login_action.php.
                    // Wajib di sini juga: require_login() melempar pengguna yang
                    // sesinya hilang ke halaman INI, bukan ke login utama, jadi
                    // menambal satu sisi saja menyisakan pintu yang tak pernah
                    // menyembuhkan browser. Lihat includes/auth_intended.php.
                    auth_purge_rival_session_cookies();

                    // Anti session fixation, kembaran dari login_action.php. Wajib di
                    // KEDUA handler: keduanya menulis ke session store yang sama.
                    session_regenerate_id(true);

                    // Login successful
                    $_SESSION['user_id'] = $ws_user['id'];
                    $_SESSION['name'] = $ws_user['name'];
                    $_SESSION['role'] = $ws_user['role'];
                    $_SESSION['username'] = $ws_user['username'];
                    $_SESSION['team_name'] = $ws_user['team_name'];
                    $_SESSION['email'] = $username_input;

                // Kembali ke halaman yang tadi dicoba dibuka (dicatat require_login()).
                // Dashboard di bawah tinggal jadi default landing. Sekali pakai, dan
                // gate halaman tujuan tetap yang memvalidasi role.
                $intended = auth_consume_intended();
                if ($intended !== null) {
                    header("Location: " . $intended);
                    exit;
                }

                // Route users based on their roles
                if ($ws_user['role'] === 'Manager' || $ws_user['role'] === 'Category Manager') {
                    header("Location: dashboard_mgr.php"); 
                } elseif (in_array($ws_user['role'], ['Project Director', 'QS'])) {
                    header("Location: dashboard_project.php");
                } elseif ($ws_user['role'] === 'Admin') {
                    header("Location: dashboard_admin.php");
                } elseif ($ws_user['role'] === 'Technical Department') {
                    header("Location: dashboard_tech.php");
                } else {
                    header("Location: dashboard_pic.php");
                }
                exit;
                }
            } else {
                $error = 'Account is registered in VSS, but lacks Workspace access.';
            }
        } else {
            $error = 'Invalid email or password.';
        }
    } catch (PDOException $e) {
        $error = 'Database Connection Error.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saber eProc | Sign In</title>
    <?= vss_favicon_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; letter-spacing: -0.01em; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen flex items-center justify-center p-4 antialiased">

    <div class="bg-white rounded-2xl shadow-xl flex flex-col md:flex-row w-full max-w-5xl overflow-hidden border border-slate-100">
        
        <div class="md:w-5/12 bg-gradient-to-br from-[#0F1E36] via-[#1e3a8a] to-[#1e40af] text-white p-8 md:p-12 flex flex-col justify-between relative overflow-hidden">
            <div class="absolute top-0 right-0 -mr-16 -mt-16 w-48 h-48 rounded-full bg-cyan-400 opacity-10 blur-2xl"></div>
            <div class="absolute bottom-0 left-0 -ml-16 -mb-16 w-48 h-48 rounded-full bg-blue-400 opacity-10 blur-2xl"></div>

            <div class="relative z-10">
                <div class="flex items-center space-x-3 mb-8">
                    <span class="w-10 h-10 rounded-xl bg-white flex items-center justify-center p-1.5 shadow-md">
                        <?= vss_brand_logo(28) ?>
                    </span>
                    <span class="text-xl font-extrabold tracking-tight">Saber <span class="text-sky-400">eProc</span></span>
                </div>
                
                <h1 class="text-3xl font-extrabold mb-4 leading-tight">Enterprise Procurement<br>Command Center</h1>
                <p class="text-slate-200 text-sm mb-8 leading-relaxed">
                    Streamline your sourcing, track SLA performance, and automate supplier assignments with the intelligent Saber eProc suite.
                </p>

                <div class="space-y-4">
                    <div class="flex items-center text-sm font-semibold text-slate-100">
                        <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center mr-3 shadow-inner">
                            <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                        </div>
                        AI-Powered PR & Taxonomy Dispatching
                    </div>
                    <div class="flex items-center text-sm font-semibold text-slate-100">
                        <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center mr-3 shadow-inner">
                            <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        </div>
                        Smart Multi-Round RFQs & Tenders
                    </div>
                    <div class="flex items-center text-sm font-semibold text-slate-100">
                        <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center mr-3 shadow-inner">
                            <svg class="w-4 h-4 text-sky-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        </div>
                        Real-time SLA, Savings & Cost Avoidance
                    </div>
                </div>
            </div>
            
            <div class="relative z-10 mt-12 text-xs text-slate-400 font-medium">
                &copy; <?= date('Y') ?> Saber Enterprise. All rights reserved.
            </div>
        </div>

        <div class="md:w-7/12 p-8 md:p-14 flex flex-col justify-center">
            <div class="max-w-md w-full mx-auto">
                <h2 class="text-2xl font-bold text-slate-900 mb-2">Welcome back</h2>
                <p class="text-slate-500 text-sm mb-8">Please enter your Saber eProc credentials to access your workspace.</p>
                
                <?php if ($error || isset($_GET['error'])): ?>
                    <div class="bg-rose-50 border border-rose-200 text-rose-700 px-4 py-3 rounded-xl mb-6 text-sm flex items-center">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <?= htmlspecialchars($error ?: $_GET['error']) ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_GET['msg'])): ?>
                    <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl mb-6 text-sm flex items-center">
                        <svg class="w-5 h-5 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <?= htmlspecialchars($_GET['msg']) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="" class="space-y-5">
                    <div>
                        <label class="block text-slate-700 text-sm font-semibold mb-2">Email Address</label>
                        <input type="email" name="username" placeholder="user@saberconsulting.id" required 
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-600 focus:bg-white transition-colors">
                    </div>
                    
                    <div>
                        <label class="block text-slate-700 text-sm font-semibold mb-2">Password</label>
                        <input type="password" name="password" placeholder="••••••••" required 
                            class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-blue-600 focus:bg-white transition-colors">
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-blue-700 hover:bg-blue-800 text-white font-semibold py-3 px-4 rounded-xl shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 flex justify-center items-center">
                            Sign In to Saber eProc
                            <svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</body>
</html>