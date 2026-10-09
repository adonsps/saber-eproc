<?php
$page_title = "Masuk | Saber eProc";
$path_to_root = "./";

// ?redirect= datang dari jalur sesi-habis (logout.php meneruskannya lewat query
// karena sesinya sudah dihancurkan) dan deep-link email. Dipindah ke sesi supaya
// tidak hilang bila pengguna salah kata sandi lebih dulu.
require_once __DIR__ . '/includes/auth_intended.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_GET['redirect'])) {
    auth_remember_intended($_GET['redirect']);
}

// Guard di modules/** melempar pengguna beda-role ke sini, bukan ke dashboard-nya.
// Tanpa blok ini mereka mendarat di form login tanpa penjelasan. Pemetaannya sama
// dengan "5. Role-Based Redirection" di login_action.php agar tidak ada pasangan
// role/dashboard yang saling melempar; role di luar peta tetap melihat form.
if (isset($_SESSION['user_id'])) {
    $portal_home = [
        'vendor'               => 'modules/vendor/dashboard.php',
        'sourcing'             => 'modules/sourcing/dashboard.php',
        'vendor_mgt'           => 'modules/vendormgt/dashboard.php',
        'vendormgt_head'       => 'modules/vendormgt/dashboard.php',
        'admin'                => 'modules/admin/dashboard.php',

        // Workspace — cerminan routing di login_action.php langkah 5.
        'Manager'              => 'workspace/pages/dashboard_mgr.php',
        'Category Manager'     => 'workspace/pages/dashboard_mgr.php',
        'Viewer'               => 'workspace/pages/dashboard_mgr.php',
        'Project Director'     => 'workspace/pages/dashboard_project.php',
        'QS'                   => 'workspace/pages/dashboard_project.php',
        'Admin'                => 'workspace/pages/dashboard_admin.php',
        'Technical Department' => 'workspace/pages/dashboard_tech.php',
        'STC'                  => 'workspace/pages/dashboard_stc.php',
        'PIC'                  => 'workspace/pages/dashboard_pic.php',
    ][$_SESSION['role'] ?? ''] ?? null;

    if ($portal_home === null) {
        auth_abandon_partial_login();
    } else {
        if (($_SESSION['role'] ?? '') === 'vendor' && ($_SESSION['status'] ?? '') === 'min_info_submitted') {
            $portal_home = 'modules/vendor/complete_profile.php';
        }
        header("Location: " . $portal_home);
        exit();
    }
}

include 'includes/header.php';
?>

<style>
    body { 
        background-color: #f8fafc; 
    }
    
    .login-page-wrapper {
        min-height: 100vh;
        display: flex;
        align-items: center;
        padding: 2rem 0;
    }

    .login-card-modern {
        border-radius: 20px;
        overflow: hidden;
        box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        border: 1px solid #e2e8f0;
        background-color: #fff;
    }
    
    .login-sidebar {
        background: linear-gradient(145deg, #0F1E36 0%, #1e3a8a 55%, #1e40af 100%);
        position: relative;
        color: white;
    }
    
    .login-sidebar::before {
        content: '';
        position: absolute;
        top: -60px;
        right: -60px;
        width: 280px;
        height: 280px;
        background: radial-gradient(circle, rgba(0, 144, 255, 0.22) 0%, rgba(0, 144, 255, 0) 70%);
        border-radius: 50%;
        z-index: 0;
    }
    
    .login-sidebar::after {
        content: "\f007"; /* User icon */
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        position: absolute;
        bottom: -20px;
        right: -20px;
        font-size: 14rem;
        color: rgba(255,255,255,0.03);
        transform: rotate(-15deg);
        z-index: 0;
        pointer-events: none;
    }
    
    .sidebar-content {
        position: relative;
        z-index: 1;
    }

    /* Custom Input Styling */
    .form-group-custom { 
        position: relative; 
        margin-bottom: 1.5rem; 
    }
    
    .form-control-custom {
        background-color: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1.2rem 0.85rem 3rem;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        color: #0f172a;
    }
    
    .form-control-custom:focus {
        background-color: #ffffff;
        border-color: #1e40af;
        box-shadow: 0 0 0 0.25rem rgba(30, 64, 175, 0.15);
    }
    
    .input-icon {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        left: 1.2rem;
        color: #94a3b8;
        font-size: 1.1rem;
        transition: color 0.2s ease;
        z-index: 5;
        pointer-events: none;
    }
    
    .form-group-custom:focus-within .input-icon { 
        color: #1e40af; 
    }

    .form-label-custom {
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #475569;
        margin-bottom: 0.4rem;
        letter-spacing: 0.5px;
        display: block;
    }

    .password-toggle {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        right: 1.2rem;
        cursor: pointer;
        color: #94a3b8;
        z-index: 10;
        transition: color 0.2s;
    }
    .password-toggle:hover { color: #1e40af; }

    .btn-login {
        border-radius: 12px;
        padding: 0.85rem 2rem;
        font-weight: 700;
        font-size: 1.05rem;
        letter-spacing: -0.01em;
        background-color: #1e40af;
        border-color: #1e40af;
        transition: all 0.2s ease;
        box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2);
    }
    .btn-login:hover {
        background-color: #1e3a8a;
        border-color: #1e3a8a;
        transform: translateY(-2px);
        box-shadow: 0 8px 18px rgba(30, 64, 175, 0.3);
    }
</style>

<div class="container login-page-wrapper">
    <div class="row justify-content-center w-100 m-0">
        <div class="col-xl-9 col-lg-10">
            <div class="login-card-modern">
                <div class="row g-0">
                    
                    <div class="col-md-5 login-sidebar p-5 d-none d-md-flex flex-column justify-content-between">
                        <div class="sidebar-content">
                            <div class="mb-4">
                                <div class="d-flex align-items-center gap-2 mb-4">
                                    <div class="bg-white rounded-3 p-1.5 shadow-sm d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                                        <?= vss_brand_logo(32) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold fs-5 text-white lh-1">SABER <span style="color:#00D8FF;">eProc</span></div>
                                        <small class="text-white-50" style="font-size: 0.68rem; letter-spacing: 1px;">ENTERPRISE WORKSPACE</small>
                                    </div>
                                </div>
                                <span class="badge bg-white text-primary rounded-pill px-3 py-1.5 mb-3 fw-bold shadow-sm" style="color: #1e40af !important;">Portal Vendor & Internal</span>
                                <h2 class="fw-bold mb-3 display-6 text-white">Selamat Datang</h2>
                                <p class="opacity-75" style="line-height: 1.6;">Kelola profil vendor, pengajuan pra-kualifikasi, katalog produk, dan proses tender dalam ekosistem terpadu Saber eProc.</p>
                            </div>
                        </div>
                        
                        <div class="sidebar-content mt-5 pt-4 border-top border-light border-opacity-25">
                            <p class="small mb-0 opacity-75">&copy; <?= date('Y') ?> Saber Enterprise. All rights reserved.</p>
                        </div>
                    </div>

                    <div class="col-md-7 p-4 p-md-5 bg-white">
                        
                        <div class="mb-4 pb-2 border-bottom">
                            <h3 class="fw-bold text-dark mb-1">Masuk ke Saber eProc</h3>
                            <p class="text-muted small mb-0">Platform Manajemen Pengadaan & Vendor Sourcing</p>
                        </div>

                        <?php if(isset($_GET['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 border-0 border-start border-4 border-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2 text-danger"></i> <?= htmlspecialchars($_GET['error']) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if(isset($_GET['msg'])): ?>
                            <div class="alert alert-success alert-dismissible fade show shadow-sm rounded-3 border-0 border-start border-4 border-success" role="alert">
                                <i class="fas fa-check-circle me-2 text-success"></i> <?= htmlspecialchars($_GET['msg']) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <form action="login_action.php" method="POST" class="mt-4" autocomplete="off">

                            <?php if (!empty($_GET['redirect'])): ?>
                            <input type="hidden" name="redirect" value="<?= htmlspecialchars($_GET['redirect'], ENT_QUOTES) ?>">
                            <?php endif; ?>

                            <div class="form-group-custom">
                                <label class="form-label-custom">Email atau Username</label>
                                <div class="position-relative">
                                    <i class="fas fa-user input-icon"></i>
                                    <input type="text" name="email" class="form-control form-control-custom" placeholder="nama@perusahaan.com atau username" required autocomplete="off">
                                </div>
                                <div class="form-text small text-muted mt-1" style="font-size: 0.72rem;"><i class="fas fa-circle-info me-1"></i>Anda dapat masuk menggunakan email atau username akun Anda.</div>
                            </div>
                            
                            <div class="form-group-custom mb-5">
                                <div class="d-flex justify-content-between align-items-end mb-1">
                                    <label class="form-label-custom mb-0">Kata Sandi</label>
                                    <a href="forgot_password.php" class="text-decoration-none small fw-bold text-primary" style="font-size: 0.8rem;">Lupa Kata Sandi?</a>
                                </div>
                                <div class="position-relative">
                                    <i class="fas fa-lock input-icon"></i>
                                    <input type="password" name="password" id="loginPassword" class="form-control form-control-custom" placeholder="••••••••" required autocomplete="new-password">
                                    <i class="fas fa-eye password-toggle" id="toggleLoginPassword" title="Tampilkan/Sembunyikan Sandi"></i>
                                </div>
                                <div class="text-end mt-2">
                                    <a href="https://docs.google.com/document/d/1LnKeaEBQ4y5OB-NBhAoGKdniWLhIkZxX8oKr8vO0wAQ/export?format=pdf" target="_blank" class="text-decoration-none text-muted" style="font-size:0.78rem;font-weight:600;transition:color 0.2s;">
                                        <i class="fas fa-question-circle me-1"></i>Butuh Bantuan?
                                    </a>
                                </div>
                            </div>
                            

                            <div class="d-grid gap-2">
                                <button type="submit" name="login_btn" class="btn btn-primary btn-login d-flex justify-content-center align-items-center">
                                    Masuk <i class="fas fa-sign-in-alt ms-2"></i>
                                </button>
                            </div>
                        </form>

                        <div class="text-center mt-5 pt-3 border-top">
                            <span class="text-muted small me-2">Belum punya akun?</span>
                            <a href="register.php" class="text-decoration-none fw-bold text-primary">
                                Daftar Sebagai Vendor Baru
                            </a>
                        </div>
                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        // Password Visibility Toggle
        const togglePassword = document.getElementById('toggleLoginPassword');
        const passwordInput = document.getElementById('loginPassword');

        if(togglePassword && passwordInput) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Toggle the eye / eye-slash icon
                this.classList.toggle('fa-eye');
                this.classList.toggle('fa-eye-slash');
            });
        }
    });
</script>

<?php include 'includes/footer.php'; ?>