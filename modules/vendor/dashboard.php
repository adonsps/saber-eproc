<?php
// modules/vendor/dashboard.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever be on profile.php — bounce back rather than let procurement browse the
// vendor's dashboard under their identity.
vendor_staff_edit_restrict();

// 2. Fetch Vendor Data
$user_id = $_SESSION['user_id'];
$vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if(!$vendor) die("Vendor profile not found.");

// --- CEK KELENGKAPAN PROFIL & DOKUMEN ---
// Aturan kelengkapan kini terpusat di includes/profile_helper.php agar dipakai
// bersama oleh Sourcing (gate tombol Exception Request).
require_once $path_to_root . 'includes/profile_helper.php';
require_once $path_to_root . 'includes/vendor_profile_draft.php';
$profile_check       = isVendorProfileComplete($vendor);
$is_profile_complete = $profile_check['complete'];
$missing_docs        = $profile_check['missing'];

// isVendorProfileComplete() reads the row, and profile_action.php now writes
// uploaded documents to that row BEFORE it validates anything (so a rejected
// save stops throwing the vendor's uploads away). Counting those provisional
// documents as "complete" is what opened Pra-Kualifikasi for a profile that was
// still wrong. While the receipts for that commit are pending, the profile is
// not finished: the vendor either saves it cleanly or presses "Buang draf".
if (!empty(vendor_profile_doc_rollback_get($vendor_id))) {
    $is_profile_complete = false;
    $missing_docs[]      = 'Simpan ulang profil Anda — dokumen dari percobaan simpan terakhir belum diterima (perbaiki isian yang ditolak, atau tekan "Buang draf" di halaman Profil)';
}
// ----------------------------------------

// 3. Count Registered Products
$prod_count = 0;
$vp_sql = "SELECT COUNT(*) as count FROM vendor_products WHERE vendor_id = " . $vendor['id'] . " AND is_active = 1";
$vp_res = $conn->query($vp_sql);
if ($vp_res) {
    $prod_count = $vp_res->fetch_assoc()['count'];
}

// 4. CHECK SAMPLE REQUESTS
$sample_requests_map = [];
$req_sql = "SELECT r.*, p.item_name FROM product_sample_requests r 
            JOIN vendor_products p ON r.product_id = p.id
            WHERE r.vendor_id = ? AND r.status = 'pending'
            ORDER BY r.created_at DESC";
$req_stmt = $conn->prepare($req_sql);
$req_stmt->bind_param("i", $vendor['id']);
$req_stmt->execute();
$req_res = $req_stmt->get_result();
while($row = $req_res->fetch_assoc()) {
    $sample_requests_map[$row['product_id']] = $row;
}

$page_title = "Dasbor Vendor";
include $path_to_root . 'includes/header.php';
?>

<style>
    /* Efek animasi muncul yang halus */
    .fade-in {
        animation: fadeIn 0.4s ease-in-out;
    }
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }

    /* Global Card Hover */
    .metric-card {
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        border-radius: 12px;
        overflow: hidden;
        position: relative;
    }
    .metric-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important;
    }
    .metric-icon {
        position: absolute;
        right: 15px;
        bottom: 10px;
        font-size: 4rem;
        opacity: 0.15;
        z-index: 0;
    }
    .metric-content {
        position: relative;
        z-index: 1;
    }
    /* HIGH CONTRAST GRADIENTS */
    .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #198754 0%, #0f5132 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
    .bg-gradient-info    { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); }

    /* MODERN WELCOME BANNER */
    .welcome-banner {
        background: linear-gradient(135deg, #ffffff 0%, #f1f5f9 100%);
        border-radius: 24px;
        position: relative;
        overflow: hidden;
        box-shadow: 0 10px 30px rgba(0,0,0,0.04);
        margin-bottom: 2.5rem;
        border: 1px solid rgba(13, 110, 253, 0.08);
        transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
    }
    .welcome-banner:hover {
        box-shadow: 0 20px 40px rgba(13, 110, 253, 0.08);
        transform: translateY(-4px);
    }
    .welcome-banner::after {
        content: "\f2b5"; /* Handshake icon */
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        position: absolute;
        top: -10px;
        right: -10px;
        font-size: 14rem;
        color: rgba(13, 110, 253, 0.03);
        transform: rotate(-10deg);
        z-index: 0;
        pointer-events: none;
        transition: all 0.6s ease;
    }
    .welcome-banner:hover::after {
        transform: rotate(0deg) scale(1.1);
        color: rgba(13, 110, 253, 0.05);
    }
    
    /* EXCLUSIVE APPROVED BANNER (PREMIUM DARK GREEN) */
    .approved-banner {
        background: linear-gradient(135deg, #0f3e22 0%, #1a5b3a 100%) !important; 
        border-left: 6px solid #d4af37 !important; /* Gold border accent */
        box-shadow: 0 15px 35px rgba(15, 62, 34, 0.25) !important;
        color: white;
    }
    .approved-banner::after {
        content: "\f559"; /* Award/Medal icon */
        color: rgba(255, 255, 255, 0.03) !important; 
        font-size: 16rem !important;
        transform: rotate(-15deg) translateY(-20px) !important;
    }
    
    /* Shimmer Effect for Approved Banner */
    .shimmer-effect {
        position: relative;
    }
    .shimmer-effect::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 50%;
        height: 100%;
        background: linear-gradient(to right, rgba(255,255,255,0) 0%, rgba(255,255,255,0.15) 50%, rgba(255,255,255,0) 100%);
        transform: skewX(-20deg);
        animation: shimmer 5s infinite;
        z-index: 1;
        pointer-events: none;
    }
    @keyframes shimmer {
        0% { left: -100%; }
        100% { left: 200%; }
    }

    /* Premium Verified Badge Card (Dark Theme) */
    .verified-badge-card {
        background: rgba(255, 255, 255, 0.05) !important;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        cursor: default;
        border: 1px solid rgba(255, 255, 255, 0.1) !important; 
    }
    .verified-badge-card:hover {
        transform: translateY(-5px) scale(1.03);
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.3) !important;
        border-color: rgba(212, 175, 55, 0.5) !important; /* Gold border on hover */
    }

    .banner-content { position: relative; z-index: 2; }
    .status-pill {
        display: inline-flex;
        align-items: center;
        padding: 0.5rem 1.2rem;
        border-radius: 50rem;
        background: rgba(13, 110, 253, 0.08);
        color: #0d6efd;
        font-weight: 700;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 1.2px;
        margin-bottom: 1.25rem;
        border: 1px solid rgba(13, 110, 253, 0.1);
    }
    /* Specific Status Pill for Exclusive Approved Banner */
    .status-pill-success {
        background: rgba(255, 255, 255, 0.1) !important; 
        color: #d4af37 !important; /* Gold text */
        border: 1px solid rgba(212, 175, 55, 0.3) !important;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        backdrop-filter: blur(5px);
    }

    .btn-interactive {
        transition: all 0.3s ease;
    }
    .btn-interactive:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.15) !important;
    }
    
    /* Custom button styling for the exclusive banner */
    .btn-gold {
        background-color: #d4af37;
        color: #0f3e22;
        border-color: #d4af37;
    }
    .btn-gold:hover {
        background-color: #bfa033;
        color: #0a2916;
        border-color: #bfa033;
        box-shadow: 0 8px 20px rgba(212, 175, 55, 0.4) !important;
    }
    
    .btn-outline-gold {
        background-color: transparent;
        color: #d4af37;
        border-color: #d4af37;
    }
    .btn-outline-gold:hover {
        background-color: rgba(212, 175, 55, 0.1);
        color: #fff;
        border-color: #d4af37;
    }

    /* Text utility for dark background */
    .text-gold { color: #d4af37 !important; }

    /* MODERN INTERACTIVE STEPPER - FIXED ALIGNMENT */
    .stepper-wrapper {
        background: white;
        border-radius: 20px;
        padding: 2.5rem 2rem;
        box-shadow: 0 4px 20px rgba(0,0,0,0.02);
        position: relative;
    }
    .step-item {
        position: relative;
        display: flex;
        flex-direction: column;
        align-items: center;
        flex: 1;
        z-index: 2;
    }
    .step-circle {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        background: #fff;
        color: #ced4da;
        border: 2px solid #e9ecef;
        transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        margin-bottom: 0.75rem;
        position: relative;
        z-index: 5;
    }
    .step-item.active .step-circle {
        background: #0d6efd;
        color: white;
        border-color: #0d6efd;
        box-shadow: 0 0 20px rgba(13, 110, 253, 0.35);
        transform: scale(1.2);
    }
    .step-item.completed .step-circle {
        background: #198754;
        color: white;
        border-color: #198754;
        box-shadow: 0 0 15px rgba(25, 135, 84, 0.2);
    }
    .step-item.warning .step-circle {
        background: #ffc107;
        color: #000;
        border-color: #ffc107;
        box-shadow: 0 0 20px rgba(255, 193, 7, 0.3);
    }
    .step-label {
        font-size: 0.75rem;
        font-weight: 700;
        color: #adb5bd;
        text-align: center;
        transition: color 0.3s ease;
    }
    .step-item.active .step-label, .step-item.completed .step-label {
        color: #212529;
    }
    
    /* THE LINE FIX: Centers correctly between steps */
    .progress-line {
        position: absolute;
        top: 22px; 
        left: 50px; 
        right: 50px;
        height: 4px;
        background: #f1f3f5;
        z-index: 1;
        border-radius: 10px;
    }
    .progress-fill {
        height: 100%;
        background: linear-gradient(to right, #198754, #20c997);
        transition: width 1s cubic-bezier(0.65, 0, 0.35, 1);
        border-radius: 10px;
    }
    
    .position-absolute.top-0.start-100.translate-middle.p-2.bg-gold.border.border-warning.rounded-circle.shadow-lg {
      display: flex;
      justify-content: center;
      align-items: center;
      width: 40px; 
      height: 40px;
    }

    /* ========================================= */
    /* TOUR WALKTHROUGH CSS (SPOTLIGHT EFFECT)   */
    /* ========================================= */
    .tour-overlay {
        position: fixed;
        top: 0; left: 0; width: 100vw; height: 100vh;
        background: rgba(0, 0, 0, 0.85); 
        z-index: 9998;
        backdrop-filter: blur(4px); 
    }
    
    /* PENGHANCUR PENJARA Z-INDEX: Matikan efek CSS yang menjebak tombol */
    body.tour-locked .welcome-banner,
    body.tour-locked .welcome-banner:hover,
    body.tour-locked .banner-content {
        transform: none !important;
        z-index: auto !important;
        overflow: visible !important;
    }
    
    /* ANTI BLINKING: Matikan efek melayang saat hover yang bikin tombol berkedip */
    body.tour-locked .btn-interactive,
    body.tour-locked .btn-interactive:hover {
        transform: none !important;
    }

    .tour-highlight, .tour-highlight:hover {
        position: relative !important;
        z-index: 10000 !important; 
        pointer-events: auto !important;
        animation: pulseHighlight 2s infinite;
        background-color: #0d6efd !important; 
    }
    
    @keyframes pulseHighlight {
        0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.8); }
        70% { box-shadow: 0 0 0 20px rgba(13, 110, 253, 0); }
        100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); }
    }
    
    .tour-tooltip {
        position: absolute;
        z-index: 10001;
        background: white;
        padding: 1.5rem;
        border-radius: 16px;
        width: 320px;
        box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        border-top: 5px solid #0d6efd;
    }
    .tour-tooltip::before {
        content: '';
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        border-width: 0 10px 10px 10px;
        border-style: solid;
        border-color: transparent transparent #0d6efd transparent;
    }
    body.tour-locked {
        overflow: hidden; 
    }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand d-flex align-items-center gap-2 text-decoration-none" href="/vms/workspace/pages/dashboard_mgr.php" title="Switch to Workspace">
            <?= vss_brand_logo() ?>
            <span style="font-size: 16px; font-weight: 800; letter-spacing: -0.2px; color: #0F1E36;">Saber eProc</span>
        </a>

        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-nav position-relative" data-bs-toggle="modal" data-bs-target="#notificationModal">
                <i class="fas fa-bell"></i>
                <?php if (count($sample_requests_map) > 0): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.6rem;">
                        <?= count($sample_requests_map) ?>
                    </span>
                <?php endif; ?>
            </button>

            <a href="profile.php" class="btn btn-sm btn-nav">
                <i class="fas fa-user-edit me-1"></i> Profil
            </a>
            <a href="contacts.php" class="btn btn-sm btn-nav" title="Kelola Narahubung">
                <i class="fas fa-users me-1"></i> Narahubung
            </a>

            <?php
            // A vendor session identifies a company, not a staff member, so the
            // profile control shows company name + type instead of user/role.
            $navbar_profile_name     = $vendor['company_name'];
            $navbar_profile_subtitle = $vendor['company_type'];
            $navbar_profile_logout   = 'Keluar';
            include $path_to_root . 'includes/navbar_profile.php';
            ?>
        </div>
    </div>
</nav>

<div class="container pb-5">

    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if(isset($_GET['error'])): ?>
        <!-- Redirects that refuse an action land here with ?error= (pq_form.php's
             incomplete-profile gate, submit_pq.php's already-submitted guard).
             Without this block those messages were dropped silently and the
             vendor just saw the dashboard again with no explanation. -->
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-triangle-exclamation me-2"></i> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php $status = $vendor['registration_status']; ?>
    <?php if (!$is_profile_complete && in_array($status, ['registration_submitted', 'registered', 'invited_pq', 'migrated', 'revision_requested'])): ?>
        <div id="banner_profil_belum_lengkap" class="alert shadow-sm border-0 rounded-4 mb-4 border-start border-danger border-4 position-relative overflow-hidden d-none fade-in" style="background-color: #fff5f5;">
            <div class="position-absolute top-0 end-0 h-100 opacity-10 pe-3 pt-3" style="font-size: 5rem; z-index: 0; color: #dc3545;">
                <i class="fas fa-file-signature"></i>
            </div>
            <div class="position-relative z-1 p-2">
                <h5 class="alert-heading fw-bold text-danger"><i class="fas fa-lock me-2"></i> Profil Belum Lengkap!</h5>
                <p class="mb-3 text-dark">Anda <strong>wajib</strong> melengkapi informasi dan dokumen legal berikut sebelum dapat mengakses Formulir Pra-Kualifikasi:</p>
                <ul class="mb-4 small fw-bold text-dark">
                    <?php foreach($missing_docs as $doc): ?>
                        <li class="mb-1"><i class="fas fa-times text-danger me-2"></i><?= $doc ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="profile.php" class="btn btn-danger rounded-pill px-4 py-2 fw-bold shadow-sm">
                    Lengkapi Profil Sekarang <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($status == 'approved'): ?>
        <div class="welcome-banner approved-banner p-4 p-md-5 shimmer-effect">
            <div class="banner-content row align-items-center">
                
                <div class="col-lg-7 col-md-12 mb-4 mb-lg-0">
                    <div class="status-pill status-pill-success">
                        <i class="fas fa-crown me-2"></i> Pra-Kualifikasi Selesai
                    </div>
                    <h2 class="fw-bold mb-3 text-white">Selamat Bergabung, <?= htmlspecialchars($vendor['contact_person']) ?>!</h2>
                    <p class="mb-4 fs-6 pe-md-4" style="line-height: 1.6; color: rgba(255,255,255,0.8);">
                        Selamat! <strong><?= htmlspecialchars($vendor['company_name']) ?></strong> telah melewati semua tahap pra-kualifikasi dan secara resmi <strong>LULUS</strong>. Anda sekarang adalah vendor yang terdaftar di ekosistem Procurement Kawan Lama Group.
                    </p>
                    
                    <div class="d-flex flex-wrap gap-3">
                        <a href="download_result.php" target="_blank" class="btn btn-gold rounded-pill px-4 py-2 fw-bold shadow-sm btn-interactive d-inline-flex align-items-center">
                            <i class="fa-solid fa-file-pdf fs-5 me-2"></i> Unduh Hasil
                        </a>
                        <a href="product_registration.php" class="btn btn-outline-gold rounded-pill px-4 py-2 fw-bold shadow-sm btn-interactive d-inline-flex align-items-center">
                            <i class="fas fa-box-open me-2"></i> Tambah Produk
                        </a>
                    </div>
                </div>

                <div class="col-lg-5 col-md-12 text-lg-end">
                    <div class="p-4 rounded-4 shadow-lg d-inline-block verified-badge-card text-start position-relative">
                        
                        <div class="position-absolute top-0 start-100 translate-middle p-2 bg-gold border border-warning rounded-circle shadow-lg" style="background-color: #d4af37;" title="Verified Tier">
                            <i class="fas fa-star text-white" style="font-size: 0.85rem;"></i>
                        </div>
                        
                        <div class="d-flex align-items-center mb-3 border-bottom border-secondary pb-3">
                            <div class="p-3 rounded-circle me-3 shadow-sm d-flex align-items-center justify-content-center" style="background: linear-gradient(135deg, #d4af37 0%, #aa8c2c 100%); width: 55px; height: 55px;">
                                <i class="fas fa-medal text-white fs-3"></i>
                            </div>
                            <div>
                                <div class="small fw-bold text-uppercase" style="color: rgba(255,255,255,0.6); font-size: 0.7rem; letter-spacing: 1px;">Status Vendor</div>
                                <div class="fw-bolder fs-5 text-gold">DISETUJUI</div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mt-2">
                            <div class="small fw-bold me-4" style="color: rgba(255,255,255,0.7);"><i class="fas fa-shield-check me-1"></i> Pra-Kualifikasi:</div>
                            <div class="fw-bold small text-white"><i class="fas fa-check text-gold me-1"></i> LULUS</div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
    <?php else: ?>
        <div class="welcome-banner p-4 p-md-5">
            <div class="banner-content d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                <div>
                    <div class="status-pill"><i class="fa-solid fa-handshake me-2 text-warning"></i> Pra-Kualifikasi</div>
                    <h2 class="fw-bold text-dark mb-2">Halo, <?= htmlspecialchars($vendor['contact_person']) ?>! 👋</h2>
                    <p class="text-secondary mb-0 opacity-75 fs-6">Senang melihat Anda kembali. Pantau progres Pra-Kualifikasi dan kelola profil bisnis Anda dengan mudah.</p>
                </div>
                <div class="mt-4 mt-md-0">
                    <a href="profile.php" id="btn-main-profile" class="btn btn-primary rounded-pill px-4 py-3 fw-bold shadow-sm btn-interactive">
                        <i class="fas fa-user-circle me-2"></i> Isi Profil
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="stepper-wrapper mb-5">
        <h6 class="text-muted text-uppercase fw-bold mb-4 small px-2" style="letter-spacing: 1px;">Alur Registrasi</h6>
        <?php
        $steps = [
            'registration_submitted' => 1,
            'registered' => 2,
            'min_info_submitted' => 2,
            'invited_pq' => 2,
            'migrated' => 2,
            'pq_submitted' => 3,
            'revision_requested' => 3, 
            'returned_to_sourcing' => 4,
            'under_review_sourcing' => 4,
            'under_review_vm' => 4,
            'under_review_vm_head' => 4,
            'approved' => 5
        ];
        $current_step = isset($steps[$status]) ? $steps[$status] : 1;
        $progress_width = ($current_step - 1) * 25;

        function renderModernStep($index, $label, $current_step, $status) {
            $isActive = ($index == $current_step);
            $isCompleted = ($index < $current_step);
            $isWarning = ($isActive && ($status == 'revision_requested' || $status == 'revision_needed'));
            
            $stateClass = '';
            if ($isCompleted) $stateClass = 'completed';
            elseif ($isWarning) $stateClass = 'warning active';
            elseif ($isActive) $stateClass = 'active';

            echo '
            <div class="step-item '.$stateClass.'">
                <div class="step-circle">
                    '. ($isCompleted ? '<i class="fas fa-check"></i>' : $index) .'
                </div>
                <div class="step-label">'.$label.'</div>
            </div>';
        }
        ?>

        <div class="position-relative d-flex justify-content-between align-items-start">
            <div class="progress-line">
                <div class="progress-fill" style="width: <?= $progress_width ?>%;"></div>
            </div>

            <?php 
            renderModernStep(1, "Registrasi", $current_step, $status);
            renderModernStep(2, "Pengisian Pra-Kualifikasi", $current_step, $status);
            renderModernStep(3, "Penilaian", $current_step, $status);
            renderModernStep(4, "Verifikasi", $current_step, $status);
            renderModernStep(5, "Disetujui", $current_step, $status);
            ?>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-gradient-primary metric-card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Akun Saya</h6>
                    <h5 class="fw-bold mb-0 text-truncate text-white" style="max-width: 250px;">
                        <?= htmlspecialchars($vendor['company_name']) ?>
                    </h5>
                    <div class="mt-3">
                        <?php 
                            $badge_class = 'bg-secondary';
                            
                            // Custom Status Mapping to Hide "PQ" and use friendly Indonesian
                            $status_labels = [
                                'registration_submitted' => 'PENDAFTARAN DIKIRIM',
                                'registered' => 'TERDAFTAR',
                                'min_info_submitted' => 'INFO MINIMAL DIKIRIM',
                                'invited_pq' => 'DIUNDANG',
                                'migrated' => 'DIDAFTARKAN',
                                'pq_submitted' => 'DIAJUKAN',
                                'revision_requested' => 'PERLU REVISI',
                                'returned_to_sourcing' => 'DIKEMBALIKAN KE SOURCING',
                                'under_review_sourcing' => 'TINJAUAN SOURCING',
                                'under_review_vm' => 'TINJAUAN PROCUREMENT',
                                'under_review_vm_head' => 'TINJAUAN PROCUREMENT',
                                'approved' => 'DISETUJUI',
                                'rejected' => 'DITOLAK'
                            ];
                            
                            $status_label = isset($status_labels[$status]) ? $status_labels[$status] : strtoupper(str_replace('_', ' ', $status));
                            
                            if($status == 'approved') $badge_class = 'bg-success';
                            if($status == 'revision_requested') $badge_class = 'bg-warning text-dark';
                            if($status == 'rejected') $badge_class = 'bg-danger';
                        ?>
                        <span class="badge <?= $badge_class ?> rounded-pill px-3 py-2 border border-light">
                            <?= $status_label ?>
                        </span>
                    </div>
                </div>
                <i class="fas fa-building metric-icon text-white"></i>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-gradient-success metric-card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Pra-Kualifikasi</h6>
                    
                    <?php if (!$is_profile_complete && in_array($status, ['registration_submitted', 'registered', 'invited_pq', 'migrated', 'revision_requested'])): ?>
                        <h4 class="fw-bold mb-3 text-white">Tindakan Tertunda</h4>
                        <button type="button" class="btn btn-light text-danger fw-bold w-100 shadow-sm" onclick="tampilkanBannerProfil()">
                            <i class="fas fa-lock me-2"></i> Lengkapi Profil Dahulu
                        </button>
                        
                    <?php elseif ($status == 'invited_pq' || $status == 'registered' || $status == 'migrated'): ?>
                        <h4 class="fw-bold mb-3 text-white">Siap untuk Penilaian</h4>
                        <a href="pq_form.php" class="btn btn-light text-success fw-bold w-100 shadow-sm">Isi Pra-Kualifikasi</a>

                    <?php elseif ($status == 'returned_to_sourcing' && !empty($_SESSION['impersonator'])): ?>
                        <!-- Direct Registration only: 'returned_to_sourcing' normally means
                             only the Sourcing REVIEWER can act (via review_pq.php), not the
                             vendor themselves — but here Sourcing IS the one in this session
                             (impersonating), so route to the editable form instead of the
                             read-only view. -->
                        <h4 class="fw-bold mb-3 text-warning">Dikembalikan oleh VM</h4>
                        <a href="pq_form.php" class="btn btn-warning text-dark w-100 fw-bold shadow-sm">
                            <i class="fas fa-edit me-1"></i> Perbarui Pra-Kualifikasi
                        </a>

                    <?php elseif (in_array($status, ['pq_submitted', 'under_review_sourcing', 'under_review_vm', 'approved', 'returned_to_sourcing', 'under_review_vm_head'])): ?>
                        <h4 class="fw-bold mb-3 text-white">Diajukan</h4>
                        <a href="view_pq.php" class="btn btn-outline-light w-100 fw-bold">Lihat Pengajuan</a>
                        
                    <?php elseif ($status == 'revision_requested'): ?>
                        <h4 class="fw-bold mb-3 text-warning">Perlu Revisi</h4>
                        <a href="pq_form.php" class="btn btn-warning text-dark w-100 fw-bold shadow-sm">
                            <i class="fas fa-edit me-1"></i> Perbarui Pra-Kualifikasi
                        </a>
                        
                    <?php else: ?>
                        <h4 class="fw-bold mb-3 opacity-50">Belum Dimulai</h4>
                        <button class="btn btn-outline-light w-100" disabled>Belum Ada Tindakan</button>
                    <?php endif; ?>
                </div>
                <i class="fas fa-clipboard-check metric-icon text-white"></i>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-gradient-info metric-card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Katalog Produk</h6>
                    <h1 class="display-4 fw-bold mb-0 text-white"><?= $prod_count ?></h1>
                    <div class="small text-white-50 mb-3">Barang Terdaftar</div>
                    <a href="product_registration.php" class="btn btn-outline-light w-100 fw-bold">
                        <i class="fas fa-plus-circle me-1"></i> Tambah Produk
                    </a>
                </div>
                <i class="fas fa-box-open metric-icon text-white"></i>
            </div>
        </div>
    </div>
    
    <?php if ($prod_count > 0): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Produk Terbaru Saya</h6>
            <a href="product_registration.php" class="btn btn-sm btn-link text-decoration-none small">Lihat Semua <i class="fas fa-arrow-right ms-1"></i></a>
        </div>
        <div class="card-body p-0">
             <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light-subtle">
                        <tr class="text-muted small">
                            <th class="ps-4">DETAIL BARANG</th>
                            <th>KLASIFIKASI</th>
                            <th>HARGA & MOQ</th>
                            <th>SPESIFIKASI</th>
                            <th class="text-end pe-4">AKSI</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $p_sql = "SELECT * FROM vendor_products WHERE vendor_id = {$vendor['id']} AND is_active = 1 ORDER BY id DESC"; 
                        $p_res = $conn->query($p_sql);
                        while($prod = $p_res->fetch_assoc()):
                            $has_request = isset($sample_requests_map[$prod['id']]);
                            $req_data = $has_request ? $sample_requests_map[$prod['id']] : null;
                            $row_class = $has_request ? "table-warning" : ""; 
                            $img_path = $prod['product_image'];
                            if(!empty($img_path)) {
                                $img_path .= "?v=" . time();
                            }
                        ?>
                        <tr class="<?= $row_class ?>">
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <?php if(!empty($prod['product_image'])): ?>
                                        <img src="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($img_path) ?>" class="rounded me-3 border" style="width: 44px; height: 44px; object-fit: cover;">
                                    <?php else: ?>
                                        <div class="me-3 rounded bg-light d-flex align-items-center justify-content-center border" style="width: 44px; height: 44px;">
                                            <i class="fas fa-box text-secondary opacity-50"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <div class="fw-bold text-dark mb-0"><?= htmlspecialchars($prod['item_name']) ?></div>
                                        <small class="text-muted small text-uppercase" style="font-size:0.65rem;"><i class="fas fa-tag me-1"></i><?= htmlspecialchars($prod['brand']) ?></small>
                                        <?php if ($has_request): ?>
                                            <div class="mt-1">
                                                <button type="button" class="btn btn-xs btn-danger text-white py-0 px-2 fw-bold" style="font-size: 0.6rem;" data-bs-toggle="modal" data-bs-target="#requestDetailModal<?= $prod['id'] ?>">SAMPEL DIMINTA</button>
                                            </div>
                                            <div class="modal fade" id="requestDetailModal<?= $prod['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-danger text-white">
                                                            <h5 class="modal-title">Permintaan Sampel</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            <p><strong>Item:</strong> <?= htmlspecialchars($prod['item_name']) ?></p>
                                                            <div class="alert alert-light border">
                                                                <strong>Catatan:</strong> "<?= htmlspecialchars($req_data['request_note']) ?>"
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                                                            <a href="acknowledge_sample.php?req_id=<?= $req_data['id'] ?>" class="btn btn-danger">Tandai Terkirim</a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="d-flex flex-column">
                                    <span class="badge bg-primary mb-1 align-self-start"><?= htmlspecialchars($prod['category'] ?: 'General') ?></span>
                                    <div class="small text-muted" style="font-size: 0.8rem;">
                                        <?= htmlspecialchars($prod['sub_category'] ?: '-') ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div class="fw-bold text-dark small">Rp <?= number_format($prod['price_idr'], 0, ',', '.') ?></div>
                                <div class="text-muted" style="font-size: 0.75rem;">Min: <?= $prod['moq'] ?> <?= $prod['uom'] ?></div>
                            </td>
                            <td>
                                <div class="text-truncate" style="max-width: 200px;" title="<?= htmlspecialchars($prod['description']) ?>">
                                    <?= htmlspecialchars($prod['description']) ?>
                                </div>
                                <?php if(!empty($prod['add_info'])): ?>
                                    <span class="badge bg-light text-secondary border mt-1"><?= ucfirst($prod['add_info']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end pe-4">
                                <a href="product_registration.php?edit_id=<?= $prod['id'] ?>" class="btn btn-sm btn-light border" title="Ubah Produk">
                                    <i class="fas fa-edit text-primary"></i>
                                </a>
                                <a href="product_action.php?remove_id=<?= $prod['id'] ?>" 
                                   class="btn btn-sm btn-outline-danger" 
                                   onclick="return confirm('Apakah Anda yakin ingin menghapus produk ini dari katalog?');">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-white text-center">
            <a href="product_registration.php" class="text-decoration-none small fw-bold">Daftarkan Lebih Banyak Produk</a>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="notificationModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-warning text-dark border-0">
                <h6 class="modal-title fw-bold"><i class="fas fa-bell me-2"></i>Notifikasi</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <?php if (count($sample_requests_map) > 0): ?>
                    <div class="list-group list-group-flush">
                        <?php foreach($sample_requests_map as $req): ?>
                            <div class="list-group-item py-3">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="fw-bold text-primary"><?= htmlspecialchars($req['item_name']) ?></span>
                                    <span class="text-muted small"><?= date('d M', strtotime($req['created_at'])) ?></span>
                                </div>
                                <p class="mb-2 small text-secondary">"<?= htmlspecialchars($req['request_note']) ?>"</p>
                                <div class="text-end">
                                    <a href="acknowledge_sample.php?req_id=<?= $req['id'] ?>" class="btn btn-sm btn-success px-3">Tandai Terkirim</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fas fa-bell-slash fa-2x mb-2 opacity-25"></i>
                        <p class="mb-0">Tidak ada notifikasi baru</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function tampilkanBannerProfil() {
    const banner = document.getElementById('banner_profil_belum_lengkap');
    if (banner) {
        banner.classList.remove('d-none');
        window.scrollTo({ top: banner.offsetTop - 100, behavior: 'smooth' });
    }
}

// ========================================================
// FITUR WALKTHROUGH PAKSA UNTUK VENDOR BARU
// ========================================================
<?php if (!$is_profile_complete): ?>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- FITUR BARU: CEK MEMORI BROWSER ---
    // Jika vendor sudah pernah mengklik tombol ini selama sesi browser aktif, 
    // hentikan fungsi layar gelap dan biarkan dashboard tampil normal.
    if (sessionStorage.getItem('walkthrough_profile_done') === 'true') {
        return; 
    }
    // --------------------------------------

    const targetBtn = document.getElementById('btn-main-profile');
    
    if (targetBtn) {
        // 1. Kunci layar agar tidak bisa di-scroll
        document.body.classList.add('tour-locked');

        // 2. Buat kegelapan (Overlay)
        const overlay = document.createElement('div');
        overlay.className = 'tour-overlay fade-in';
        document.body.appendChild(overlay);

        // 3. BEBASKAN TOMBOL ASLI DARI "PENJARA Z-INDEX"
        const welcomeBanner = targetBtn.closest('.welcome-banner');
        const bannerContent = targetBtn.closest('.banner-content');
        
        if (welcomeBanner) welcomeBanner.style.overflow = 'visible';
        if (bannerContent) bannerContent.style.zIndex = 'auto'; 
        
        targetBtn.classList.add('tour-highlight');
        
        // --- FITUR BARU: SIMPAN MEMORI SAAT TOMBOL DIKLIK ---
        targetBtn.addEventListener('click', function(e) {
            sessionStorage.setItem('walkthrough_profile_done', 'true');
            window.location.href = 'profile.php';
        });

        // 4. Buat Kotak Pesan Panduan (Tooltip)
        const tooltip = document.createElement('div');
        tooltip.className = 'tour-tooltip fade-in';
        
        // --- FITUR BARU: SIMPAN MEMORI JUGA SAAT KOTAK KUNING DIKLIK ---
        tooltip.innerHTML = `
            <h5 class="fw-bold text-primary mb-2">👋 Selamat Datang!</h5>
            <p class="text-dark mb-3" style="font-size: 0.9rem; line-height:1.5;">Langkah pertama Anda adalah melengkapi data perusahaan. Menu Pra-Kualifikasi saat ini masih <strong class="text-danger">terkunci</strong>.</p>
            <div class="p-2 bg-warning bg-opacity-25 rounded border border-warning" style="cursor: pointer;" onclick="sessionStorage.setItem('walkthrough_profile_done', 'true'); window.location.href='profile.php'">
                <p class="text-dark fw-bold mb-0 text-center" style="font-size: 0.85rem;">
                    <i class="fas fa-hand-pointer text-primary me-2 fa-bounce"></i>Klik tombol di atas untuk mulai!
                </p>
            </div>
        `;
        document.body.appendChild(tooltip);

        // 5. Posisikan kotak pesan melayang tepat di bawah tombol
        function updatePositions() {
            const rect = targetBtn.getBoundingClientRect();
            
            tooltip.style.top = (rect.bottom + window.scrollY + 15) + 'px';
            let leftPos = rect.left + (rect.width / 2) - (320 / 2);
            if (leftPos < 10) leftPos = 10; // Jaga agar tidak terpotong di layar HP
            tooltip.style.left = leftPos + 'px';
        }
        
        setTimeout(updatePositions, 50); 
        window.addEventListener('resize', updatePositions);
        
        // Pastikan layar ada di paling atas
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});
<?php endif; ?>
// ========================================================
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>