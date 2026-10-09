<?php
// modules/vendor/profile.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$vendor_id = $_SESSION['vendor_id'] ?? 0;

// 2. Fetch Current Data
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    die("Vendor profile not found.");
}

// 3. Tarik Data Taxonomy (Kategori & Komoditas)
$tax_stmt = $conn->prepare("SELECT sub_category, GROUP_CONCAT(commodity SEPARATOR ', ') as commodities FROM taxonomy GROUP BY sub_category ORDER BY sub_category ASC");
$tax_stmt->execute();
$tax_res = $tax_stmt->get_result();
$taxonomies = [];
while($row = $tax_res->fetch_assoc()) {
    if(!empty($row['sub_category'])) {
        $taxonomies[] = $row;
    }
}

$page_title = "Profil Perusahaan";
include $path_to_root . 'includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/css/intlTelInput.css">

<style>
    .fade-in { animation: fadeIn 0.3s; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    
    .nav-tabs .nav-link { color: #495057; border: none; border-bottom: 3px solid transparent; padding: 1rem 1.5rem; }
    .nav-tabs .nav-link:hover { border-color: #e9ecef; }
    .nav-tabs .nav-link.active { color: #0d6efd; background-color: transparent; border-bottom: 3px solid #0d6efd; }
    .btn-interactive { transition: transform 0.2s ease, box-shadow 0.2s ease; }
    .btn-interactive:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(13, 110, 253, 0.3) !important; }

    /* TOUR WALKTHROUGH CSS */
    .tour-overlay { position: fixed; top: 0; left: 0; width: 100vw; height: 100vh; background: rgba(0, 0, 0, 0.75); z-index: 9998; backdrop-filter: blur(3px); }
    .tour-highlight { position: relative !important; z-index: 10000 !important; animation: pulseHighlight 2s infinite; background-color: #ffffff !important; }
    @keyframes pulseHighlight { 0% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.4); } 70% { box-shadow: 0 0 0 15px rgba(13, 110, 253, 0); } 100% { box-shadow: 0 0 0 0 rgba(13, 110, 253, 0); } }
    .tour-tooltip { position: absolute; z-index: 10001; background: white; padding: 1.5rem; border-radius: 16px; width: 350px; box-shadow: 0 10px 40px rgba(0,0,0,0.5); border-top: 5px solid #0d6efd; }
    .tour-tooltip::before { content: ''; position: absolute; top: -10px; left: 50%; transform: translateX(-50%); border-width: 0 10px 10px 10px; border-style: solid; border-color: transparent transparent #0d6efd transparent; }

    /* intl-tel-input styling for profile page */
    .iti { width: 100%; }
    .iti .iti__tel-input { background-color: #fff; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.375rem 0.75rem; font-size: 1rem; transition: border-color 0.15s ease, box-shadow 0.15s ease; width: 100%; height: calc(1.5em + 0.75rem + 2px); }
    .iti .iti__tel-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.25); outline: none; }
    .iti__selected-country { border-radius: 0.375rem 0 0 0.375rem; padding: 0 8px 0 12px; }
    .iti__dropdown-content { border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); border: 1px solid #e8e8e8; margin-top: 4px; }
    .iti__search-input { border-radius: 8px; border: 1px solid #dee2e6; padding: 8px 12px; font-size: 0.85rem; }
    .iti__search-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.15rem rgba(13,110,253,.15); outline: none; }
    .iti__country { padding: 8px 12px; }
    .iti__country:hover { background-color: #e8f0fe; }
    .phone-status-profile { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; font-weight: 600; margin-top: 4px; }
    .phone-status-profile.valid { color: #198754; }
    .phone-status-profile.invalid { color: #dc3545; }
    .phone-status-profile.empty { color: #6c757d; }
</style>

<nav class="navbar navbar-dark bg-primary mb-4 shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand"><i class="fas fa-building me-2"></i> Profil Perusahaan</span>
        <a href="dashboard.php" class="btn btn-sm btn-outline-light">Kembali ke Dashboard</a>
    </div>
</nav>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-10"> 
            
            <?php if(isset($_GET['msg'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert"><i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <?php if(isset($_GET['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert"><i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_GET['error']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <form id="profileForm" action="profile_action.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="update_profile" value="1">
                <input type="hidden" name="apply_npwp_as_company_name" id="applyNpwpAsCompanyName" value="">
                
                <ul class="nav nav-tabs mb-4" id="profileTabs" role="tablist">
                    <li class="nav-item" role="presentation"><button class="nav-link active fw-bold" id="general-tab" data-bs-toggle="tab" data-bs-target="#general" type="button" role="tab" aria-controls="general" aria-selected="true"><i class="fas fa-info-circle me-2"></i> Informasi Umum</button></li>
                    <li class="nav-item" role="presentation"><button class="nav-link fw-bold" id="docs-tab" data-bs-toggle="tab" data-bs-target="#docs" type="button" role="tab" aria-controls="docs" aria-selected="false"><i class="fas fa-file-upload me-2"></i> Dokumen Legal</button></li>
                </ul>

                <div class="tab-content" id="profileTabsContent">
                    
                    <div class="tab-pane fade show active" id="general" role="tabpanel" aria-labelledby="general-tab">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-body p-4">
                                <div class="mb-3">
                                    <label class="form-label small fw-bold text-muted">Nama Perusahaan</label>
                                    <input type="text" id="displayCompanyName" class="form-control bg-light" value="<?= htmlspecialchars($vendor['company_name']) ?>" readonly>
                                    <div id="companyNameChangeIndicator" class="d-none mt-2 p-2 rounded-3 border border-success border-opacity-50 d-flex align-items-center gap-2" style="background: #f0fff4; font-size: 0.8rem;">
                                        <i class="fas fa-check-circle text-success"></i>
                                        <span class="text-success fw-bold">Nama akan diperbarui sesuai NPWP saat Anda menyimpan.</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-2 py-0 ms-auto" id="undoCompanyNameChange" style="font-size: 0.7rem;">
                                            <i class="fas fa-undo me-1"></i>Batal
                                        </button>
                                    </div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6"><label class="form-label small fw-bold text-muted">Tipe Perusahaan</label><input type="text" class="form-control bg-light" value="<?= htmlspecialchars($vendor['company_type']) ?>" readonly></div>
                                    <div class="col-md-6"><label class="form-label small fw-bold text-muted">Email (ID Pengguna)</label><input type="text" class="form-control bg-light" value="<?= htmlspecialchars($vendor['email']) ?>" readonly></div>
                                </div>

                                <hr class="my-4">
                                <h6 class="fw-bold mb-3 text-secondary"><i class="fas fa-edit me-2"></i>Detail Perusahaan</h6>

                                <?php 
                                $status = $vendor['registration_status'] ?? '';
                                $is_entity_locked = !in_array($status, ['registered', 'invited_pq', 'revision_requested']) && !empty($vendor['legal_entity_type']);
                                ?>
                                <div id="tour-target-badan-hukum" class="mb-4 p-3 bg-light border border-secondary border-opacity-25 rounded-3" style="transition: all 0.3s ease;">
                                    <label class="form-label fw-bold text-dark">Bentuk Badan Hukum <span class="text-danger">*</span></label>
                                    <select name="legal_entity_type" id="legal_entity_type" class="form-select border-secondary fw-bold text-dark shadow-sm" style="background-color: <?= $is_entity_locked ? '#e9ecef' : '#ffffff' ?>;" required <?= $is_entity_locked ? 'disabled' : '' ?>>
                                        <option value="" disabled <?= empty($vendor['legal_entity_type']) ? 'selected' : '' ?>>Pilih Bentuk Badan Hukum...</option>
                                        <option value="Badan Usaha" <?= (($vendor['legal_entity_type'] ?? '') == 'Badan Usaha') ? 'selected' : '' ?>>Badan Usaha (PT, CV, Firma, dll)</option>
                                        <option value="Perseorangan" <?= (($vendor['legal_entity_type'] ?? '') == 'Perseorangan') ? 'selected' : '' ?>>Perseorangan (Individu)</option>
                                    </select>
                                    
                                    <?php if ($is_entity_locked): ?>
                                        <input type="hidden" name="legal_entity_type" value="<?= htmlspecialchars($vendor['legal_entity_type']) ?>">
                                        <div class="form-text small mt-2 text-danger fw-bold"><i class="fas fa-lock me-1"></i> Bentuk Badan Hukum telah dikunci karena Anda sudah mengajukan Pra-Kualifikasi.</div>
                                    <?php else: ?>
                                        <div class="alert alert-info py-2 px-3 mt-3 mb-2 border-0 shadow-sm" style="font-size: 0.8rem; border-radius: 8px;">
                                            <i class="fas fa-info-circle me-1"></i> <strong>Panduan Pemilihan:</strong><br>
                                            <ul class="mb-0 ps-3 mt-1 text-dark">
                                                <li><strong>Badan Usaha:</strong> Perusahaan resmi seperti PT, CV, Firma, atau Koperasi yang memiliki <b>Akta Pendirian</b>. NIB & Rekening harus atas nama perusahaan.</li>
                                                <li><strong>Perseorangan:</strong> Usaha milik individu (Toko, Warung, Jasa Freelance). Dokumen seperti NIB, NPWP & Rekening menggunakan <b>nama pribadi pemilik</b>.</li>
                                            </ul>
                                        </div>
                                        <div class="alert alert-warning py-2 px-3 mb-0 border-0 d-flex align-items-start shadow-sm" style="font-size: 0.8rem; border-radius: 8px; background-color: #fff3cd; color: #856404;">
                                            <i class="fas fa-exclamation-triangle mt-1 me-2 fs-6"></i>
                                            <div><strong>PENTING:</strong> Pilihan ini <strong>tidak dapat diubah lagi</strong> setelah Anda mengirimkan Formulir Pra-Kualifikasi, kecuali ada permintaan revisi.</div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="mb-4 p-3 border rounded-3 bg-white shadow-sm">
                                    <label class="form-label fw-bold text-dark fs-5 mb-1">Kategori Bisnis Utama <span class="text-danger">*</span></label>
                                    <p class="form-text small mt-0 mb-3 text-muted">Tentukan barang/jasa apa saja yang mencerminkan spesialisasi perusahaan Anda.</p>
                                    
                                    <div class="mb-4 p-3 rounded-3 border border-primary border-opacity-25 shadow-sm position-relative overflow-hidden" style="background-color: #f8fbff;">
                                        <div class="position-absolute top-0 end-0 p-2" style="opacity: 0.03; pointer-events: none; z-index: 0;">
                                            <i class="fas fa-robot fa-5x text-primary" style="transform: rotate(15deg); margin-top:-10px; margin-right:-10px;"></i>
                                        </div>
                                        
                                        <label class="form-label fw-bold text-primary small mb-2 position-relative z-1"><i class="fas fa-magic me-1"></i> Deteksi Kategori Otomatis</label>
                                        <p class="text-dark small mb-2 position-relative z-1" style="line-height:1.4;">Ceritakan secara singkat barang/jasa yang Anda sediakan. AI kami akan otomatis menyarankan kategori yang paling pas untuk Anda.</p>
                                        
                                        <div class="input-group position-relative z-1 shadow-sm">
                                            <textarea id="ai_biz_desc" class="form-control border-primary border-opacity-50" rows="2" placeholder="Contoh: Perusahaan kami adalah distributor laptop, printer, kertas HVS, serta melayani instalasi jaringan server..."></textarea>
                                            <button type="button" id="btn_ai_suggest" class="btn btn-primary px-3 d-flex flex-column justify-content-center align-items-center fw-bold" style="min-width: 130px; transition: all 0.3s;">
                                                <i class="fas fa-robot mb-1 fs-5"></i> Coba AI
                                            </button>
                                        </div>
                                        <div id="ai_feedback" class="form-text small mt-2 d-none fw-bold position-relative z-1"></div>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-end mb-2 mt-4">
                                        <label class="form-label fw-bold text-secondary small mb-0">Atau Pilih Manual dari Daftar:</label>
                                        <button type="button" class="btn btn-sm btn-link text-primary fw-bold text-decoration-none p-0" data-bs-toggle="modal" data-bs-target="#kamusKategoriModal"><i class="fas fa-search me-1"></i> Lihat Kamus Kategori</button>
                                    </div>
                                    <select id="core_business_select" class="form-select border-secondary shadow-sm mb-3">
                                        <option value="" disabled selected>-- Klik Untuk Memilih Kategori Bisnis Anda --</option>
                                        <?php foreach($taxonomies as $tax): ?>
                                            <?php 
                                                $comm = $tax['commodities'];
                                                $display_comm = strlen($comm) > 70 ? substr($comm, 0, 70) . '...' : $comm;
                                            ?>
                                            <option value="<?= htmlspecialchars($tax['sub_category']) ?>">
                                                <?= htmlspecialchars($tax['sub_category']) ?> &nbsp; (Termasuk: <?= htmlspecialchars($display_comm) ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                    <div id="core_business_container" class="d-flex flex-wrap gap-2 pt-2 mb-2">
                                        </div>
                                    
                                    <div id="commodity_helper" class="alert alert-info py-3 px-3 small border-0 shadow-sm d-none mt-3 mb-0" style="border-radius: 12px; background-color: #e7f1ff;">
                                        <h6 class="fw-bold mb-2 text-primary" style="font-size: 0.85rem;"><i class="fas fa-list-ul me-1"></i> Cakupan Barang/Jasa yang Anda Pilih:</h6>
                                        <ul id="commodity_list" class="mb-0 ps-3 text-dark" style="line-height: 1.6;"></ul>
                                    </div>

                                    <input type="hidden" name="core_business" id="core_business_input" value="<?= htmlspecialchars($vendor['core_business'] ?? '') ?>">
                                </div>

                                <div class="mb-3"><label class="form-label small fw-bold">Nama Direktur / Pemilik <span class="text-danger">*</span></label><input type="text" name="director_name" class="form-control" value="<?= htmlspecialchars($vendor['director_name'] ?? '') ?>" placeholder="Nama Lengkap Direktur / Pemilik" required></div>
                                
                                <div class="row mb-3">
                                    <div class="col-md-6"><label class="form-label small fw-bold">Tanggal Pendirian</label><input type="date" name="establishment_date" class="form-control" value="<?= htmlspecialchars($vendor['establishment_date'] ?? '') ?>"></div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nomor Telepon (WA) <span class="text-danger">*</span></label>
                                        <input type="tel" id="profilePhoneInput" class="form-control" placeholder="812 3456 7890" required autocomplete="off">
                                        <input type="hidden" name="phone" id="profilePhoneHidden" value="<?= htmlspecialchars($vendor['phone'] ?? '') ?>">
                                        <div id="profilePhoneStatus" class="phone-status-profile empty">
                                            <i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3"><label class="form-label small fw-bold">Contact Person <span class="text-danger">*</span></label><input type="text" name="contact_person" class="form-control" value="<?= htmlspecialchars($vendor['contact_person'] ?? '') ?>" required></div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">PIC Finance <span class="text-danger">*</span></label>
                                        <input type="text" name="pic_finance" class="form-control" value="<?= htmlspecialchars($vendor['pic_finance'] ?? '') ?>" placeholder="Nama PIC Finance" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Email PIC Finance <span class="text-danger">*</span></label>
                                        <input type="email" name="email_pic_finance" class="form-control" value="<?= htmlspecialchars($vendor['email_pic_finance'] ?? '') ?>" placeholder="email@example.com" required>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">Nama Bank <span class="text-danger">*</span></label>
                                        <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($vendor['bank_name'] ?? '') ?>" placeholder="Contoh: BCA, Mandiri, BNI" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">Cabang Bank <span class="text-danger">*</span></label>
                                        <input type="text" name="bank_branch" class="form-control" value="<?= htmlspecialchars($vendor['bank_branch'] ?? '') ?>" placeholder="Contoh: KCU Sudirman" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label small fw-bold">Nama Pemilik Rekening <span class="text-danger">*</span></label>
                                        <input type="text" name="bank_holder_name" class="form-control" value="<?= htmlspecialchars($vendor['bank_holder_name'] ?? '') ?>" placeholder="Harus sesuai nama perusahaan" maxlength="100" required>
                                    </div>
                                </div>

                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden position-relative mt-5 mb-2" style="background: linear-gradient(135deg, #f0f7ff 0%, #e0edff 100%);">
                                    <div class="position-absolute top-50 end-0 translate-middle-y text-primary opacity-10 pe-3 d-none d-md-block" style="z-index: 0;"><i class="fas fa-file-signature fa-8x"></i></div>
                                    <div class="card-body p-4 position-relative z-1">
                                        <div class="row align-items-center">
                                            <div class="col-md-8 mb-3 mb-md-0">
                                                <h6 class="fw-bold text-primary mb-2"><i class="fas fa-handshake me-2"></i>Pakta Integritas Kawan Lama Group</h6>
                                                <p class="mb-0 small text-dark" style="line-height: 1.6;">Silakan unduh dokumen resmi <strong>Pakta Integritas Kawan Lama Group</strong>. Baca, tandatangani, dan unggah salinannya pada tab <a href="#" onclick="document.getElementById('docs-tab').click(); return false;" class="fw-bold text-decoration-none border-bottom border-primary border-2 pb-1 text-primary">Dokumen Legal</a>.</p>
                                            </div>
                                            <div class="col-md-4 text-md-end text-center mt-2 mt-md-0"><a href="<?= $path_to_root ?>../uploads/templates/Pakta_Integritas_Kawan_Lama_Group.pdf" target="_blank" download="Pakta_Integritas_Kawan_Lama_Group.pdf" class="btn btn-primary rounded-pill fw-bold shadow-sm px-4 py-2 btn-interactive"><i class="fas fa-cloud-download-alt me-2"></i> Unduh PDF</a></div>
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="docs" role="tabpanel" aria-labelledby="docs-tab">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-body p-4">
                                <div class="alert alert-light border-0 shadow-sm mb-4"><i class="fas fa-info-circle text-primary me-2"></i> <small>Mohon unggah dokumen resmi Anda dalam format <strong>PDF, JPG, atau PNG</strong>. Maksimal ukuran 5MB per file.</small></div>

                                <?php 
                                function renderDocRow($label, $inputName, $dbValue, $rootPath, $ocrFieldName = null, $ocrFieldValue = '', $docType = '', $ocrStatus = '', $extraClass = '', $ocrFieldName2 = null, $ocrFieldValue2 = '', $ocrLabel2 = '', $ocrFieldName3 = null, $ocrFieldValue3 = '', $ocrLabel3 = '') {
                                    echo '<div class="row mb-4 border-bottom pb-3 doc-row ' . $extraClass . '">';
                                    echo '  <div class="col-md-5">';
                                    echo '      <label class="form-label small fw-bold mb-2">' . $label . '</label>';
                                    
                                    if ($ocrFieldName !== null) {
                                        $hideClass = empty($ocrFieldValue) ? 'd-none' : '';
                                        echo '      <div id="ocr_container_'.$inputName.'" class="mt-2 p-2 bg-white border border-primary border-opacity-25 rounded shadow-sm ' . $hideClass . '">';
                                        echo '          <div class="d-flex justify-content-between align-items-center mb-2">';
                                        echo '              <label class="small fw-bold text-primary mb-0">Nomor ' . $docType . ' Terekstrak</label>';
                                        echo '              <span id="ocr_status_'.$inputName.'">';
                                        if (!empty($ocrFieldValue)) {
                                            if ($ocrStatus === 'verified') echo '                  <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Terverifikasi</span>';
                                            elseif ($ocrStatus === 'invalid') echo '                  <span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> Tidak Valid</span>';
                                            elseif ($ocrStatus === 'unverified') echo '                  <span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> Belum Diverifikasi</span>';
                                            else echo '                  <span class="badge bg-secondary"><i class="fas fa-database me-1"></i> Data Tersimpan</span>';
                                        }
                                        echo '              </span>';
                                        echo '          </div>';
                                        echo '          <input type="text" id="text_field_'.$inputName.'" name="'.$ocrFieldName.'" class="form-control form-control-sm mb-2" value="'.htmlspecialchars($ocrFieldValue).'" placeholder="Akan terisi otomatis...">';
                                        
                                        if ($ocrFieldName2 !== null) {
                                            echo '          <label class="small fw-bold text-primary mb-1 mt-2">' . $ocrLabel2 . '</label>';
                                            echo '          <input type="text" id="text_field2_'.$inputName.'" name="'.$ocrFieldName2.'" class="form-control form-control-sm" value="'.htmlspecialchars($ocrFieldValue2).'" placeholder="Akan terisi otomatis...">';
                                        }

                                        if ($ocrFieldName3 !== null) {
                                            echo '          <label class="small fw-bold text-primary mb-1 mt-2">' . $ocrLabel3 . '</label>';
                                            echo '          <textarea id="text_field3_'.$inputName.'" name="'.$ocrFieldName3.'" class="form-control form-control-sm" rows="2" placeholder="Akan terisi otomatis...">'.htmlspecialchars($ocrFieldValue3).'</textarea>';
                                        }

                                        $initialStatus = !empty($ocrFieldValue) ? 'saved' : 'empty';
                                        echo '          <input type="hidden" id="hidden_status_'.$inputName.'" name="'.$ocrFieldName.'_status" value="'.$initialStatus.'">';
                                        echo '      </div>';
                                    }

                                    echo '  </div>';
                                    echo '  <div class="col-md-7">';
                                    
                                    if (!empty($dbValue)) {
                                        echo '      <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded border">';
                                        echo '          <div class="text-truncate me-2"><i class="fas fa-file-pdf text-danger me-2"></i><a href="'.$rootPath.'../uploads/'.htmlspecialchars($dbValue).'" target="_blank" class="text-decoration-none fw-bold text-dark">Lihat Dokumen</a></div>';
                                        echo '          <div class="flex-shrink-0"><button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="document.getElementById(\'input_'.$inputName.'\').classList.toggle(\'d-none\');" title="Ganti Dokumen"><i class="fas fa-edit"></i></button><a href="profile_action.php?action=delete_file&file_type='.$inputName.'" class="btn btn-sm btn-outline-danger" onclick="return confirm(\'Apakah Anda yakin ingin menghapus dokumen ini?\');" title="Hapus Dokumen"><i class="fas fa-trash-alt"></i></a></div>';
                                        echo '      </div>';
                                        echo '      <div id="input_'.$inputName.'" class="mt-2 d-none fade-in"><div class="input-group input-group-sm"><input type="file" id="file_replace_'.$inputName.'" name="'.$inputName.'" class="form-control" accept=".pdf,.jpg,.jpeg,.png"><label class="input-group-text">Unggah Baru</label></div></div>';
                                    } else {
                                        echo '      <div class="input-group input-group-sm"><input type="file" id="file_'.$inputName.'" name="'.$inputName.'" class="form-control" accept=".pdf,.jpg,.jpeg,.png"><label class="input-group-text"><i class="fas fa-upload"></i></label></div>';
                                    }
                                    
                                    echo '  </div></div>';
                                }

                                renderDocRow("KTP Direktur / Pemilik <span class='text-danger'>*</span>", "director_id_card_file", $vendor['director_id_card_file'] ?? '', $path_to_root, "director_id_number", $vendor['director_id_number'] ?? '', "KTP", $vendor['director_id_status'] ?? '', 'doc-all');
                                renderDocRow("Halaman Depan Buku Rekening <span class='text-danger'>*</span>", "bank_cover", $vendor['bank_cover_path'] ?? '', $path_to_root, "bank_account_number", $vendor['bank_account_number'] ?? '', "Rekening", $vendor['bank_account_status'] ?? '', 'doc-all');
                                renderDocRow("NPWP (Tax ID) <span class='text-danger'>*</span>", "npwp_file", $vendor['npwp_path'] ?? '', $path_to_root, "npwp_number", $vendor['npwp_number'] ?? '', "NPWP", $vendor['npwp_status'] ?? '', 'doc-all', 'npwp_name', $vendor['npwp_name'] ?? '', 'Nama Pemilik NPWP Terekstrak', 'company_address', $vendor['company_address'] ?? '', 'Alamat Wajib Pajak Terekstrak');
                                renderDocRow("Pakta Integritas Kawan Lama Group <span class='text-danger'>*</span>", "business_ethics_commitment_file", $vendor['business_ethics_commitment_file'] ?? '', $path_to_root, null, '', '', '', 'doc-all');
                                renderDocRow("Profil Perusahaan / Brosur", "company_profile", $vendor['company_profile_file'] ?? '', $path_to_root, null, '', '', '', 'doc-all');
                                renderDocRow("Dokumen NIB <span class='text-danger'>*</span>", "nib_file", $vendor['nib_path'] ?? '', $path_to_root, "nib_number", $vendor['nib_number'] ?? '', "NIB", $vendor['nib_status'] ?? '', 'doc-bu');
                                renderDocRow("Akta Pendirian <span class='text-danger'>*</span>", "deed_of_establishment_file", $vendor['deed_of_establishment_file'] ?? '', $path_to_root, "deed_of_establishment_number", $vendor['deed_of_establishment_number'] ?? '', "Akta", $vendor['deed_of_establishment_status'] ?? '', 'doc-bu');
                                renderDocRow("SK Pengesahan Badan Hukum <span class='text-danger'>*</span>", "decree_of_legal_entity_file", $vendor['decree_of_legal_entity_file'] ?? '', $path_to_root, "decree_of_legal_entity_number", $vendor['decree_of_legal_entity_number'] ?? '', "SK", $vendor['decree_of_legal_entity_status'] ?? '', 'doc-bu');
                                renderDocRow("Akta Perubahan", "deed_of_amendment_file", $vendor['deed_of_amendment_file'] ?? '', $path_to_root, null, '', '', '', 'doc-bu');
                                renderDocRow("SPPKP", "sppkp_file", $vendor['sppkp_path'] ?? '', $path_to_root, null, '', '', '', 'doc-bu');

                                $company_type_lower = strtolower($vendor['company_type'] ?? '');
                                if (strpos($company_type_lower, 'contractor') !== false || strpos($company_type_lower, 'kontraktor') !== false) {
                                    renderDocRow("Surat Ijin Usaha Jasa Konstruksi (SIUJK) <span class='text-danger'>*</span>", "business_entity_cert_file", $vendor['business_entity_cert_file'] ?? '', $path_to_root, null, '', '', '', 'doc-contractor');
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                </div> 
                <div class="fixed-bottom bg-white border-top p-3 shadow-lg" style="z-index: 1030;">
                    <div class="container text-end"><button type="submit" name="update_profile" class="btn btn-primary px-5 fw-bold shadow-sm"><i class="fas fa-save me-2"></i> Simpan Perubahan</button></div>
                </div>
                <div style="height: 80px;"></div>

            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="kamusKategoriModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-book me-2"></i> Kamus Kategori Bisnis Utama</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 bg-light">
                <div class="p-3 border-bottom sticky-top bg-white shadow-sm">
                    <div class="input-group">
                        <span class="input-group-text bg-light border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" id="searchKamus" class="form-control border-start-0 bg-light" placeholder="Ketik nama barang/jasa... (cth: laptop, semen)">
                    </div>
                </div>
                <ul class="list-group list-group-flush" id="listKamus">
                    <?php foreach($taxonomies as $tax): ?>
                    <li class="list-group-item p-4 kamus-item border-bottom">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-primary mb-0 fs-5"><?= htmlspecialchars($tax['sub_category']) ?></h6>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill fw-bold px-3 select-from-kamus" data-val="<?= htmlspecialchars($tax['sub_category']) ?>"><i class="fas fa-plus me-1"></i>Pilih</button>
                        </div>
                        <p class="mb-0 text-dark small" style="line-height: 1.5;"><strong class="text-secondary">Cakupan Komoditas:</strong> <br><?= htmlspecialchars($tax['commodities']) ?></p>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="modal-footer border-0 p-3 bg-white">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup Kamus</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="npwpAnomalyModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 20px;">
            
            <!-- ANIMATED HEADER -->
            <div class="position-relative overflow-hidden" style="background: linear-gradient(135deg, #ff6b35 0%, #f7931e 50%, #ffb347 100%); padding: 2rem 2rem 3.5rem 2rem;">
                <!-- Decorative circles -->
                <div class="position-absolute" style="top: -30px; right: -30px; width: 120px; height: 120px; border-radius: 50%; background: rgba(255,255,255,0.08);"></div>
                <div class="position-absolute" style="bottom: -20px; left: 40px; width: 80px; height: 80px; border-radius: 50%; background: rgba(255,255,255,0.06);"></div>
                <div class="position-absolute" style="top: 10px; left: -15px; width: 50px; height: 50px; border-radius: 50%; background: rgba(255,255,255,0.05);"></div>
                
                <div class="position-relative d-flex align-items-center gap-3" style="z-index: 1;">
                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 52px; height: 52px; background: rgba(255,255,255,0.2); border-radius: 14px; backdrop-filter: blur(10px);">
                        <i class="fas fa-shield-alt text-white fs-4 npwp-icon-pulse"></i>
                    </div>
                    <div>
                        <h5 class="fw-bold text-white mb-1" style="letter-spacing: -0.3px;">Verifikasi Nama Perusahaan</h5>
                        <div class="text-white small" style="opacity: 0.85;">Sistem mendeteksi perbedaan data pada dokumen NPWP</div>
                    </div>
                </div>
            </div>

            <!-- NAME COMPARISON CARD (overlapping header) -->
            <div class="px-4" style="margin-top: -2rem; position: relative; z-index: 2;">
                <div class="bg-white rounded-4 shadow p-0 overflow-hidden" style="border: 1px solid rgba(0,0,0,0.06);">
                    <div class="row g-0">
                        <!-- Company Name Side -->
                        <div class="col-md-5 p-3 d-flex flex-column justify-content-center" style="background: linear-gradient(180deg, #f8faff 0%, #eef3ff 100%); border-right: 1px dashed #d0d8e8;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; background: #dbeafe; border-radius: 8px;">
                                    <i class="fas fa-building text-primary" style="font-size: 0.75rem;"></i>
                                </div>
                                <span class="text-primary fw-bold text-uppercase" style="font-size: 0.6rem; letter-spacing: 1px;">Terdaftar di Sistem</span>
                            </div>
                            <div class="fw-bold text-dark" style="font-size: 1.05rem; line-height: 1.3;" id="anomalyCompanyName">-</div>
                        </div>

                        <!-- VS Divider -->
                        <div class="col-md-2 d-flex align-items-center justify-content-center py-3" style="background: #fff;">
                            <div class="d-flex align-items-center justify-content-center npwp-vs-badge" style="width: 44px; height: 44px; border-radius: 50%; background: linear-gradient(135deg, #ff6b35, #f7931e); box-shadow: 0 4px 15px rgba(247,147,30,0.35);">
                                <i class="fas fa-not-equal text-white" style="font-size: 0.9rem;"></i>
                            </div>
                        </div>

                        <!-- NPWP Name Side -->
                        <div class="col-md-5 p-3 d-flex flex-column justify-content-center" style="background: linear-gradient(180deg, #fffdf5 0%, #fff8e7 100%);">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <div class="d-flex align-items-center justify-content-center" style="width: 28px; height: 28px; background: #fef3c7; border-radius: 8px;">
                                    <i class="fas fa-id-card text-warning" style="font-size: 0.75rem;"></i>
                                </div>
                                <span class="text-warning fw-bold text-uppercase" style="font-size: 0.6rem; letter-spacing: 1px;">Tercantum di NPWP</span>
                            </div>
                            <div class="fw-bold text-dark" style="font-size: 1.05rem; line-height: 1.3;" id="anomalyNpwpName">-</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-body px-4 pt-4 pb-2">
                <!-- Explanation -->
                <div class="d-flex align-items-start gap-3 mb-4 p-3 rounded-3" style="background: #f0f4ff; border-left: 4px solid #3b82f6;">
                    <i class="fas fa-lightbulb text-primary mt-1" style="font-size: 1.1rem;"></i>
                    <div class="small text-dark" style="line-height: 1.6;" id="anomalyExplanation">Pastikan nama pada NPWP sesuai dengan entitas yang mendaftar.</div>
                </div>

                <!-- Action Question -->
                <div class="d-flex align-items-center gap-2 mb-3">
                    <div style="height: 2px; flex: 1; background: linear-gradient(90deg, transparent, #e2e8f0);"></div>
                    <span class="text-secondary fw-bold small text-uppercase px-2" style="font-size: 0.7rem; letter-spacing: 1.5px; white-space: nowrap;">Pilih Tindakan</span>
                    <div style="height: 2px; flex: 1; background: linear-gradient(90deg, #e2e8f0, transparent);"></div>
                </div>

                <!-- Option Cards -->
                <div class="d-flex flex-column gap-3 mb-3">
                    
                    <!-- Option A: Apply NPWP name -->
                    <div class="npwp-option-card" id="optionApplyNpwp" tabindex="0">
                        <div class="npwp-option-radio">
                            <div class="npwp-option-radio-inner"></div>
                        </div>
                        <div class="npwp-option-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-sync-alt text-white"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">Perbarui Nama Perusahaan</div>
                            <div class="text-muted" style="font-size: 0.78rem; line-height: 1.5;">Nama Perusahaan di sistem akan otomatis diperbarui sesuai nama pada NPWP. <span class="text-success fw-bold">Direkomendasikan</span> jika nama NPWP adalah nama resmi.</div>
                        </div>
                        <div class="npwp-option-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>

                    <!-- Option B: Keep company name -->
                    <div class="npwp-option-card" id="optionKeepCompany" tabindex="0">
                        <div class="npwp-option-radio">
                            <div class="npwp-option-radio-inner"></div>
                        </div>
                        <div class="npwp-option-icon" style="background: linear-gradient(135deg, #6b7280, #4b5563);">
                            <i class="fas fa-building text-white"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-bold text-dark mb-1" style="font-size: 0.95rem;">Tidak, Tetap Seperti Ini</div>
                            <div class="text-muted" style="font-size: 0.78rem; line-height: 1.5;">Nama perusahaan tidak berubah. Pilih jika Anda yakin nama yang terdaftar sudah benar.</div>
                        </div>
                        <div class="npwp-option-arrow">
                            <i class="fas fa-chevron-right"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer border-0 px-4 pb-4 pt-0">
                <button type="button" class="btn btn-light border rounded-pill px-4 py-2 fw-bold text-secondary w-100" data-bs-dismiss="modal" style="font-size: 0.85rem;">
                    <i class="fas fa-arrow-left me-2"></i> Batal, Saya Ingin Periksa Kembali
                </button>
            </div>
        </div>
    </div>
</div>

<style>
    /* NPWP Modal Animations */
    @keyframes npwpPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.15); }
    }
    .npwp-icon-pulse { animation: npwpPulse 2s ease-in-out infinite; }

    @keyframes npwpVsBounce {
        0%, 100% { transform: scale(1) rotate(0deg); }
        25% { transform: scale(1.1) rotate(-5deg); }
        75% { transform: scale(1.1) rotate(5deg); }
    }
    .npwp-vs-badge { animation: npwpVsBounce 3s ease-in-out infinite; }

    /* Option Card Styling */
    .npwp-option-card {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 18px;
        border-radius: 14px;
        border: 2px solid #e5e7eb;
        background: #fff;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
    }
    .npwp-option-card::before {
        content: '';
        position: absolute;
        inset: 0;
        opacity: 0;
        transition: opacity 0.25s ease;
        border-radius: 12px;
    }
    #optionApplyNpwp::before { background: linear-gradient(135deg, rgba(16,185,129,0.04), rgba(5,150,105,0.08)); }
    #optionKeepCompany::before { background: linear-gradient(135deg, rgba(107,114,128,0.04), rgba(75,85,99,0.08)); }

    .npwp-option-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0,0,0,0.08);
    }
    .npwp-option-card:hover::before { opacity: 1; }

    #optionApplyNpwp:hover, #optionApplyNpwp:focus { border-color: #10b981; }
    #optionKeepCompany:hover, #optionKeepCompany:focus { border-color: #6b7280; }

    #optionApplyNpwp.selected { border-color: #10b981; background: #f0fdf4; }
    #optionKeepCompany.selected { border-color: #6b7280; background: #f9fafb; }

    .npwp-option-card:active { transform: translateY(0) scale(0.98); }

    /* Radio indicator */
    .npwp-option-radio {
        width: 22px;
        height: 22px;
        border-radius: 50%;
        border: 2px solid #d1d5db;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        transition: all 0.2s ease;
    }
    .npwp-option-radio-inner {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: transparent;
        transition: all 0.2s ease;
        transform: scale(0);
    }
    #optionApplyNpwp.selected .npwp-option-radio { border-color: #10b981; }
    #optionApplyNpwp.selected .npwp-option-radio-inner { background: #10b981; transform: scale(1); }
    #optionKeepCompany.selected .npwp-option-radio { border-color: #6b7280; }
    #optionKeepCompany.selected .npwp-option-radio-inner { background: #6b7280; transform: scale(1); }

    /* Icon circle */
    .npwp-option-icon {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-size: 0.85rem;
        transition: transform 0.2s ease;
    }
    .npwp-option-card:hover .npwp-option-icon { transform: rotate(-8deg) scale(1.05); }

    /* Arrow */
    .npwp-option-arrow {
        color: #d1d5db;
        font-size: 0.75rem;
        flex-shrink: 0;
        transition: all 0.2s ease;
    }
    .npwp-option-card:hover .npwp-option-arrow { color: #9ca3af; transform: translateX(3px); }

    /* Modal entrance */
    #npwpAnomalyModal .modal-content {
        animation: npwpModalSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    }
    @keyframes npwpModalSlideUp {
        from { opacity: 0; transform: translateY(30px) scale(0.96); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    /* Mobile adjustments */
    @media (max-width: 768px) {
        #npwpAnomalyModal .row.g-0 { flex-direction: column; }
        #npwpAnomalyModal .row.g-0 > .col-md-5 { border-right: none !important; border-bottom: 1px dashed #d0d8e8; }
        #npwpAnomalyModal .row.g-0 > .col-md-2 { padding: 0.5rem 0 !important; }
        .npwp-vs-badge { width: 32px !important; height: 32px !important; }
    }
</style>

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/intlTelInput.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // --- FITUR BISNIS UTAMA & KAMUS ---
    const cbSelect = document.getElementById('core_business_select');
    const cbContainer = document.getElementById('core_business_container');
    const cbInput = document.getElementById('core_business_input');
    const commList = document.getElementById('commodity_list');
    const commHelper = document.getElementById('commodity_helper');

    // Buat Peta Kamus untuk dipakai Javascript
    const taxonomyMap = {
        <?php foreach($taxonomies as $tax): ?>
        "<?= addslashes($tax['sub_category']) ?>": "<?= addslashes($tax['commodities']) ?>",
        <?php endforeach; ?>
    };

    let selectedBusinesses = cbInput.value ? cbInput.value.split(',').filter(Boolean) : [];

    function renderBadges() {
        cbContainer.innerHTML = '';
        commList.innerHTML = '';
        
        if (selectedBusinesses.length > 0) {
            commHelper.classList.remove('d-none');
            commHelper.classList.add('fade-in');
        } else {
            commHelper.classList.add('d-none');
        }

        selectedBusinesses.forEach(biz => {
            const badge = document.createElement('span');
            badge.className = 'badge bg-primary d-flex align-items-center py-2 px-3 shadow-sm fade-in';
            badge.style.fontSize = '0.85rem';
            badge.innerHTML = `
                ${biz} 
                <i class="fas fa-times ms-2 bg-white text-primary rounded-circle d-flex align-items-center justify-content-center" style="width:16px; height:16px; font-size:10px; cursor:pointer;" onclick="removeBusiness('${biz}')"></i>
            `;
            cbContainer.appendChild(badge);

            const comms = taxonomyMap[biz] || 'Tidak ada detail.';
            const li = document.createElement('li');
            li.className = "mb-1";
            li.innerHTML = `<strong>${biz}</strong> <br> <span class="text-muted small">${comms}</span>`;
            commList.appendChild(li);
        });
        
        cbInput.value = selectedBusinesses.join(',');
        
        if(selectedBusinesses.length === 0) {
            cbSelect.setAttribute('required', 'required');
        } else {
            cbSelect.removeAttribute('required');
            cbSelect.classList.remove('is-invalid');
        }
    }

    window.removeBusiness = function(biz) {
        selectedBusinesses = selectedBusinesses.filter(item => item !== biz);
        renderBadges();
    };

    if(cbSelect) {
        cbSelect.addEventListener('change', function() {
            const val = this.value;
            if(val && !selectedBusinesses.includes(val)) {
                selectedBusinesses.push(val);
                renderBadges();
            }
            this.value = ''; // Reset select
        });
    }
    renderBadges();

    // Logika Pencarian Kamus
    const searchKamus = document.getElementById('searchKamus');
    if(searchKamus) {
        searchKamus.addEventListener('input', function() {
            let filter = this.value.toLowerCase();
            let items = document.querySelectorAll('.kamus-item');
            items.forEach(item => {
                let text = item.innerText.toLowerCase();
                item.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    }

    // Logika Tombol "Pilih" di dalam Modal Kamus
    document.querySelectorAll('.select-from-kamus').forEach(btn => {
        btn.addEventListener('click', function() {
            const val = this.getAttribute('data-val');
            if(val && !selectedBusinesses.includes(val)) {
                selectedBusinesses.push(val);
                renderBadges();
            }
            
            // Efek klik berhasil
            const originalHTML = this.innerHTML;
            this.innerHTML = '<i class="fas fa-check"></i> Terpilih';
            this.classList.replace('btn-outline-primary', 'btn-success');
            this.classList.add('text-white');
            setTimeout(() => {
                this.innerHTML = originalHTML;
                this.classList.replace('btn-success', 'btn-outline-primary');
                this.classList.remove('text-white');
            }, 1000);
        });
    });
    
    // --- FITUR AI SUGGESTION BIZ (DIPERBAIKI DENGAN FUZZY MATCH) ---
    const btnAiSuggest = document.getElementById('btn_ai_suggest');
    const aiBizDesc = document.getElementById('ai_biz_desc');
    const aiFeedback = document.getElementById('ai_feedback');

    if(btnAiSuggest) {
        btnAiSuggest.addEventListener('click', async function() {
            const desc = aiBizDesc.value.trim();
            if(!desc) {
                alert('Mohon ceritakan barang/jasa utama Anda terlebih dahulu di kotak yang tersedia.');
                aiBizDesc.focus();
                return;
            }

            btnAiSuggest.disabled = true;
            btnAiSuggest.innerHTML = '<i class="fas fa-spinner fa-spin mb-1 fs-5"></i> Proses...';
            aiFeedback.classList.remove('d-none', 'text-success', 'text-danger', 'text-warning');
            aiFeedback.classList.add('text-primary');
            aiFeedback.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Menganalisis kecocokan kategori dengan database...';

            const formData = new FormData();
            formData.append('description', desc);

            try {
                const response = await fetch('suggest_biz_ai.php', { method: 'POST', body: formData });
                const data = await response.json();
                
                console.log("AI Response:", data); // Untuk debugging jika dibutuhkan

                if(data.success && data.categories && data.categories.length > 0) {
                    let addedCount = 0;
                    
                    const taxKeys = Object.keys(taxonomyMap);

                    data.categories.forEach(cat => {
                        const aiCat = cat.toLowerCase().trim();
                        
                        // Fuzzy Match: Cari kunci di taxonomyMap yang sama persis atau mengandung teks balasan AI
                        const matchedKey = taxKeys.find(k => 
                            k.toLowerCase() === aiCat || 
                            k.toLowerCase().includes(aiCat) || 
                            aiCat.includes(k.toLowerCase())
                        );
                        
                        if(matchedKey && !selectedBusinesses.includes(matchedKey)) {
                            selectedBusinesses.push(matchedKey);
                            addedCount++;
                        }
                    });

                    if (addedCount > 0) {
                        renderBadges();
                        aiFeedback.classList.replace('text-primary', 'text-success');
                        aiFeedback.innerHTML = '<i class="fas fa-check-circle me-1"></i> AI berhasil menambahkan ' + addedCount + ' kategori yang cocok ke daftar pilihan Anda di bawah!';
                    } else {
                        aiFeedback.classList.replace('text-primary', 'text-warning');
                        aiFeedback.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> Kategori yang disarankan sudah Anda pilih atau tidak ada kecocokan baru.';
                    }
                } else {
                    aiFeedback.classList.replace('text-primary', 'text-warning');
                    aiFeedback.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i> AI tidak menemukan kategori yang cocok. Silakan pilih secara manual dari kamus.';
                }
            } catch(e) {
                console.error("AI Fetch Error:", e);
                aiFeedback.classList.replace('text-primary', 'text-danger');
                aiFeedback.innerHTML = '<i class="fas fa-times-circle me-1"></i> Gagal menghubungi AI Server. Silakan pilih kategori secara manual.';
            } finally {
                btnAiSuggest.disabled = false;
                btnAiSuggest.innerHTML = '<i class="fas fa-robot mb-1 fs-5"></i> Coba AI';
            }
        });
    }
    // ------------------------------------------------

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('tab') === 'docs') {
        const docsTabElement = document.getElementById('docs-tab');
        if (docsTabElement) new bootstrap.Tab(docsTabElement).show();
    }
    
    const entityDropdown = document.getElementById('legal_entity_type');
    if (entityDropdown) {
        entityDropdown.addEventListener('change', function() {
            const isBadanUsaha = (this.value === 'Badan Usaha');
            document.querySelectorAll('.doc-bu, .doc-contractor').forEach(el => { el.style.display = isBadanUsaha ? 'flex' : 'none'; });
        });
        entityDropdown.dispatchEvent(new Event('change'));
    }

    function setupAiExtraction(fileInputId, textFieldId, statusId, containerId, hiddenStatusId, docType, textField2Id = null, textField3Id = null) {
        const fileInput = document.getElementById(fileInputId);
        const textField = document.getElementById(textFieldId);
        const textField2 = textField2Id ? document.getElementById(textField2Id) : null;
        const textField3 = textField3Id ? document.getElementById(textField3Id) : null;
        const statusLabel = document.getElementById(statusId);
        const container = document.getElementById(containerId);
        const hiddenStatus = document.getElementById(hiddenStatusId);

        if (textField && !textField.dataset.manualListenerAttached) {
            textField.addEventListener('input', function() {
                if (this.value.trim() !== '') {
                    statusLabel.innerHTML = '<span class="badge bg-warning text-dark"><i class="fas fa-exclamation-triangle me-1"></i> Belum Diverifikasi</span>';
                    if (hiddenStatus) hiddenStatus.value = 'unverified';
                } else {
                    statusLabel.innerHTML = '';
                    if (hiddenStatus) hiddenStatus.value = 'empty';
                }
            });
            textField.dataset.manualListenerAttached = 'true';
        }

        if (fileInput && textField) {
            fileInput.addEventListener('change', async function() {
                if (this.files.length === 0) return;

                const file = this.files[0];
                
                // Block files >5MB before AI extraction
                const maxSize = 5 * 1024 * 1024; // 5MB
                if (file.size > maxSize) {
                    const sizeMB = (file.size / (1024 * 1024)).toFixed(1);
                    
                    // Hide OCR container — do NOT show extraction fields
                    if (container) container.classList.add('d-none');
                    
                    // Show error directly next to file input
                    const existingErr = fileInput.parentElement.querySelector('.file-size-error');
                    if (existingErr) existingErr.remove();
                    const errDiv = document.createElement('div');
                    errDiv.className = 'file-size-error mt-2 p-2 rounded border border-danger';
                    errDiv.style.cssText = 'background:#f8d7da;font-size:0.8rem;';
                    errDiv.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-1"></i> <strong>File terlalu besar (' + sizeMB + 'MB).</strong> Maksimal 5MB per dokumen. Silakan kompres atau pilih file yang lebih kecil.';
                    fileInput.parentElement.appendChild(errDiv);
                    
                    // Clear file input
                    this.value = '';
                    
                    // Auto-remove error after 10 seconds
                    setTimeout(() => { if (errDiv.parentElement) errDiv.remove(); }, 10000);
                    
                    return; // Stop — do NOT call AI
                }
                
                // Remove any previous file size error if user picks a valid file
                const prevErr = fileInput.parentElement.querySelector('.file-size-error');
                if (prevErr) prevErr.remove();

                const formData = new FormData();
                formData.append('document', file);
                formData.append('doc_type', docType);

                if (container) { container.classList.remove('d-none'); container.classList.add('fade-in'); }

                textField.value = ''; textField.placeholder = `Mengekstraksi ${docType}...`; textField.disabled = true;
                if(textField2) { textField2.value = ''; textField2.placeholder = `Mengekstraksi Nama...`; textField2.disabled = true; }
                if(textField3) { textField3.value = ''; textField3.placeholder = `Mengekstraksi Alamat...`; textField3.disabled = true; }

                statusLabel.innerHTML = '<span class="badge bg-warning text-dark"><i class="fas fa-spinner fa-spin me-1"></i> Menganalisis...</span>';

                try {
                    const response = await fetch('extract_doc_ai.php', { method: 'POST', body: formData });
                    const data = await response.json();

                    if (data.is_valid_document && data.extracted_number) {
                        textField.value = data.extracted_number;
                        if (textField2 && data.extracted_name) {
                            textField2.value = data.extracted_name;
                        }
                        if (textField3 && data.extracted_address) {
                            textField3.value = data.extracted_address;
                        }
                        
                        statusLabel.innerHTML = `<span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Terverifikasi</span>`;
                        if (hiddenStatus) hiddenStatus.value = 'verified';

                        // --- NPWP NAME ANOMALY: Immediate feedback after extraction ---
                        if (docType === 'NPWP' && data.name_anomaly === true) {
                            npwpAnomalyAcknowledged = false;
                            npwpAnomalyFromSave = false;
                            revertCompanyName();
                            document.getElementById('anomalyCompanyName').textContent = data.anomaly_company_name || companyName;
                            document.getElementById('anomalyNpwpName').textContent = data.anomaly_npwp_name || '';
                            document.getElementById('anomalyExplanation').innerHTML = data.anomaly_reason || '';
                            setTimeout(() => {
                                new bootstrap.Modal(document.getElementById('npwpAnomalyModal')).show();
                            }, 500);
                        }
                    } else {
                        statusLabel.innerHTML = `<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> Tidak Valid</span>`;
                        textField.placeholder = `Masukkan Nomor Secara Manual`;
                        if (textField2) textField2.placeholder = `Masukkan Nama Secara Manual`;
                        if (textField3) textField3.placeholder = `Masukkan Alamat Secara Manual`;
                        if (hiddenStatus) hiddenStatus.value = 'invalid';
                    }
                } catch (err) {
                    statusLabel.innerHTML = '<span class="badge bg-danger"><i class="fas fa-exclamation-triangle me-1"></i> Error Server</span>';
                    textField.placeholder = `Ketik Manual`;
                    if (textField2) textField2.placeholder = `Ketik Manual`;
                    if (textField3) textField3.placeholder = `Ketik Manual`;
                    if (hiddenStatus) hiddenStatus.value = 'invalid';
                } finally {
                    textField.disabled = false;
                    if(textField2) textField2.disabled = false;
                    if(textField3) textField3.disabled = false;
                }
            });
        }
    }

    setupAiExtraction('file_npwp_file', 'text_field_npwp_file', 'ocr_status_npwp_file', 'ocr_container_npwp_file', 'hidden_status_npwp_file', 'NPWP', 'text_field2_npwp_file', 'text_field3_npwp_file');
    setupAiExtraction('file_replace_npwp_file', 'text_field_npwp_file', 'ocr_status_npwp_file', 'ocr_container_npwp_file', 'hidden_status_npwp_file', 'NPWP', 'text_field2_npwp_file', 'text_field3_npwp_file');
    setupAiExtraction('file_nib_file', 'text_field_nib_file', 'ocr_status_nib_file', 'ocr_container_nib_file', 'hidden_status_nib_file', 'NIB');
    setupAiExtraction('file_replace_nib_file', 'text_field_nib_file', 'ocr_status_nib_file', 'ocr_container_nib_file', 'hidden_status_nib_file', 'NIB');
    setupAiExtraction('file_director_id_card_file', 'text_field_director_id_card_file', 'ocr_status_director_id_card_file', 'ocr_container_director_id_card_file', 'hidden_status_director_id_card_file', 'KTP');
    setupAiExtraction('file_replace_director_id_card_file', 'text_field_director_id_card_file', 'ocr_status_director_id_card_file', 'ocr_container_director_id_card_file', 'hidden_status_director_id_card_file', 'KTP');
    setupAiExtraction('file_deed_of_establishment_file', 'text_field_deed_of_establishment_file', 'ocr_status_deed_of_establishment_file', 'ocr_container_deed_of_establishment_file', 'hidden_status_deed_of_establishment_file', 'Akta');
    setupAiExtraction('file_replace_deed_of_establishment_file', 'text_field_deed_of_establishment_file', 'ocr_status_deed_of_establishment_file', 'ocr_container_deed_of_establishment_file', 'hidden_status_deed_of_establishment_file', 'Akta');
    setupAiExtraction('file_decree_of_legal_entity_file', 'text_field_decree_of_legal_entity_file', 'ocr_status_decree_of_legal_entity_file', 'ocr_container_decree_of_legal_entity_file', 'hidden_status_decree_of_legal_entity_file', 'SK');
    setupAiExtraction('file_replace_decree_of_legal_entity_file', 'text_field_decree_of_legal_entity_file', 'ocr_status_decree_of_legal_entity_file', 'ocr_container_decree_of_legal_entity_file', 'hidden_status_decree_of_legal_entity_file', 'SK');
    setupAiExtraction('file_bank_cover', 'text_field_bank_cover', 'ocr_status_bank_cover', 'ocr_container_bank_cover', 'hidden_status_bank_cover', 'Rekening');
    setupAiExtraction('file_replace_bank_cover', 'text_field_bank_cover', 'ocr_status_bank_cover', 'ocr_container_bank_cover', 'hidden_status_bank_cover', 'Rekening');

    const profilePhoneEl = document.getElementById('profilePhoneInput');
    const profilePhoneHidden = document.getElementById('profilePhoneHidden');
    const profilePhoneStatus = document.getElementById('profilePhoneStatus');

    const profileIti = window.intlTelInput(profilePhoneEl, {
        initialCountry: "id",
        preferredCountries: ["id", "sg", "my", "th", "ph", "vn", "jp", "cn", "kr", "au", "sa", "ae"],
        separateDialCode: true,
        nationalMode: false,
        formatAsYouType: true,
        formatOnDisplay: true,
        strictMode: true,
        validationNumberType: "MOBILE",
        countrySearch: true,
        containerClass: "w-100",
        loadUtilsOnInit: "https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/utils.js"
    });

    const existingPhone = profilePhoneHidden.value;
    if (existingPhone) {
        const phoneToSet = /^\d+$/.test(existingPhone) ? '+' + existingPhone : existingPhone;
        profileIti.setNumber(phoneToSet);
    }

    function syncProfilePhone() {
        const fullNumber = profileIti.getNumber();
        profilePhoneHidden.value = fullNumber;

        const inputVal = profilePhoneEl.value.replace(/\s/g, '');

        if (!inputVal || inputVal.length < 4) {
            profilePhoneStatus.className = 'phone-status-profile empty';
            profilePhoneStatus.innerHTML = '<i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>';
            return false;
        }

        if (profileIti.isValidNumber()) {
            const countryData = profileIti.getSelectedCountryData();
            const countryName = countryData.name || '';
            profilePhoneStatus.className = 'phone-status-profile valid';
            profilePhoneStatus.innerHTML = '<i class="fas fa-check-circle"></i> <span>Nomor valid — ' + countryName + ' (' + fullNumber + ')</span>';
            return true;
        } else {
            const errorMap = ["Nomor tidak valid", "Kode negara tidak valid", "Nomor terlalu pendek", "Nomor terlalu panjang", "Nomor tidak valid"];
            const errorCode = profileIti.getValidationError();
            const errorMsg = errorMap[errorCode] || "Nomor tidak valid";
            profilePhoneStatus.className = 'phone-status-profile invalid';
            profilePhoneStatus.innerHTML = '<i class="fas fa-times-circle"></i> <span>' + errorMsg + '</span>';
            return false;
        }
    }

    profilePhoneEl.addEventListener('input', syncProfilePhone);
    profilePhoneEl.addEventListener('countrychange', syncProfilePhone);
    profilePhoneEl.addEventListener('open:countrydropdown', function() { syncProfilePhone(); });
    setTimeout(syncProfilePhone, 1000);

    const profileForm = document.getElementById('profileForm');
    const submitBtn = document.querySelector('button[name="update_profile"]');

    let npwpAnomalyAcknowledged = false;
    const companyName = <?= json_encode($vendor['company_name'] ?? '') ?>;
    const legalEntityType = <?= json_encode($vendor['legal_entity_type'] ?? '') ?>;

    function normalizeName(name) {
        let n = (name || '').toLowerCase().trim();
        n = n.replace(/^(pt\.?|cv\.?|ud\.?|tb\.?|toko\.?|firma\.?|koperasi\.?|yayasan\.?|perum\.?|persero\.?)\s*/i, '');
        n = n.replace(/[.,\-\/\\()]/g, ' ');
        n = n.replace(/\s+/g, ' ').trim();
        return n;
    }

    function similarity(a, b) {
        if (!a || !b) return 0;
        if (a === b) return 1;
        if (a.includes(b) || b.includes(a)) return 0.85;
        const len = Math.max(a.length, b.length);
        if (len === 0) return 1;
        const matrix = [];
        for (let i = 0; i <= a.length; i++) { matrix[i] = [i]; }
        for (let j = 0; j <= b.length; j++) { matrix[0][j] = j; }
        for (let i = 1; i <= a.length; i++) {
            for (let j = 1; j <= b.length; j++) {
                const cost = a[i-1] === b[j-1] ? 0 : 1;
                matrix[i][j] = Math.min(matrix[i-1][j] + 1, matrix[i][j-1] + 1, matrix[i-1][j-1] + cost);
            }
        }
        return 1 - (matrix[a.length][b.length] / len);
    }

    function checkNpwpAnomaly() {
        const npwpNameEl = document.getElementById('text_field2_npwp_file');
        if (!npwpNameEl) return { match: true, reason: '' };
        
        const npwpName = (npwpNameEl.value || '').trim();
        if (!npwpName || !companyName) return { match: true, reason: '' };

        const normCompany = normalizeName(companyName);
        const normNpwp = normalizeName(npwpName);

        if (normCompany === normNpwp) return { match: true, reason: '' };
        const sim = similarity(normCompany, normNpwp);
        if (sim >= 0.7) return { match: true, reason: '' };

        if (legalEntityType === 'Perseorangan') {
            return {
                match: false,
                reason: 'Bentuk badan hukum Anda adalah <b>Perseorangan</b>, sehingga nama NPWP boleh berupa nama pribadi pemilik. Namun pastikan nama tersebut memang sesuai dengan identitas pemilik usaha.'
            };
        }

        return {
            match: false,
            reason: 'Untuk <b>Badan Usaha (PT/CV)</b>, nama pada NPWP seharusnya sama dengan nama perusahaan yang terdaftar. Jika terdapat perbedaan, pastikan Anda mendaftarkan entitas yang benar atau perbaiki data sebelum melanjutkan.'
        };
    }

    let npwpAnomalyFromSave = false;
    
    const optionApplyNpwp = document.getElementById('optionApplyNpwp');
    const optionKeepCompany = document.getElementById('optionKeepCompany');
    const applyNpwpHidden = document.getElementById('applyNpwpAsCompanyName');
    const displayCompanyName = document.getElementById('displayCompanyName');
    const changeIndicator = document.getElementById('companyNameChangeIndicator');
    const originalCompanyName = <?= json_encode($vendor['company_name'] ?? '') ?>;

    function showCompanyNameChange(newName) {
        displayCompanyName.value = newName;
        displayCompanyName.classList.remove('bg-light');
        displayCompanyName.classList.add('bg-success-subtle', 'border-success', 'fw-bold');
        changeIndicator.classList.remove('d-none');
    }

    function revertCompanyName() {
        displayCompanyName.value = originalCompanyName;
        displayCompanyName.classList.add('bg-light');
        displayCompanyName.classList.remove('bg-success-subtle', 'border-success', 'fw-bold');
        changeIndicator.classList.add('d-none');
        applyNpwpHidden.value = '';
    }

    // Helper to clear selection state on both cards
    function clearOptionSelection() {
        if (optionApplyNpwp) optionApplyNpwp.classList.remove('selected');
        if (optionKeepCompany) optionKeepCompany.classList.remove('selected');
    }

    if (optionApplyNpwp) {
        optionApplyNpwp.addEventListener('click', function() {
            clearOptionSelection();
            this.classList.add('selected');
            npwpAnomalyAcknowledged = true;
            const npwpNameVal = document.getElementById('anomalyNpwpName').textContent.trim();
            applyNpwpHidden.value = npwpNameVal;
            showCompanyNameChange(npwpNameVal);
            // Brief delay so user sees the selection before modal closes
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('npwpAnomalyModal')).hide();
                const generalTab = document.getElementById('general-tab');
                if (generalTab && !generalTab.classList.contains('active')) {
                    generalTab.click();
                }
                if (npwpAnomalyFromSave) {
                    npwpAnomalyFromSave = false;
                    submitBtn.click();
                }
            }, 400);
        });
    }

    if (optionKeepCompany) {
        optionKeepCompany.addEventListener('click', function() {
            clearOptionSelection();
            this.classList.add('selected');
            npwpAnomalyAcknowledged = true;
            revertCompanyName();
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('npwpAnomalyModal')).hide();
                if (npwpAnomalyFromSave) {
                    npwpAnomalyFromSave = false;
                    submitBtn.click();
                }
            }, 400);
        });
    }

    // Reset selection state when modal opens
    document.getElementById('npwpAnomalyModal').addEventListener('show.bs.modal', function() {
        clearOptionSelection();
    });

    // Undo button inside the indicator
    const undoBtn = document.getElementById('undoCompanyNameChange');
    if (undoBtn) {
        undoBtn.addEventListener('click', function() {
            revertCompanyName();
            npwpAnomalyAcknowledged = false;
        });
    }

    if (profileForm && submitBtn) {
        submitBtn.addEventListener('click', function(e) {
            syncProfilePhone();

            if (!profileIti.isValidNumber()) {
                e.preventDefault();
                profilePhoneEl.focus();
                profilePhoneStatus.className = 'phone-status-profile invalid';
                profilePhoneStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span>Harap masukkan nomor telepon yang valid sebelum menyimpan.</span>';
                const generalTab = document.getElementById('general-tab');
                if (generalTab && !generalTab.classList.contains('active')) {
                    generalTab.click();
                    setTimeout(() => { profilePhoneEl.focus(); }, 300);
                }
                return;
            }

            // --- NPWP NAME ANOMALY CHECK (Fallback for manual edits) ---
            if (!npwpAnomalyAcknowledged) {
                const result = checkNpwpAnomaly();
                if (!result.match) {
                    e.preventDefault();
                    npwpAnomalyFromSave = true;
                    revertCompanyName();
                    document.getElementById('anomalyCompanyName').textContent = companyName;
                    document.getElementById('anomalyNpwpName').textContent = document.getElementById('text_field2_npwp_file').value.trim();
                    document.getElementById('anomalyExplanation').innerHTML = result.reason;
                    new bootstrap.Modal(document.getElementById('npwpAnomalyModal')).show();
                    return;
                }
            }
            
            if(selectedBusinesses.length === 0) {
                cbSelect.classList.add('is-invalid');
                const generalTab = new bootstrap.Tab(document.getElementById('general-tab'));
                generalTab.show();
            }

            const ocrInputs = document.querySelectorAll('input[id^="text_field_"], input[id^="text_field2_"]');
            ocrInputs.forEach(input => {
                let containerId = input.id.replace('text_field_', 'ocr_container_').replace('text_field2_', 'ocr_container_');
                const container = document.getElementById(containerId);
                
                if (container && !container.classList.contains('d-none')) {
                    input.setAttribute('required', 'required');
                } else {
                    input.removeAttribute('required');
                }
            });

            if (!profileForm.checkValidity()) {
                e.preventDefault(); 
                const firstInvalid = profileForm.querySelector(':invalid');
                if (firstInvalid) {
                    const tabPane = firstInvalid.closest('.tab-pane');
                    if (tabPane && !tabPane.classList.contains('active')) {
                        const tabButton = document.querySelector(`[data-bs-target="#${tabPane.id}"]`);
                        if (tabButton) { tabButton.click(); setTimeout(() => { profileForm.reportValidity(); }, 200); }
                    } else { profileForm.reportValidity(); }
                }
            }
        });
    }

    const npwpNameField = document.getElementById('text_field2_npwp_file');
    if (npwpNameField) {
        npwpNameField.addEventListener('input', function() {
            npwpAnomalyAcknowledged = false;
            revertCompanyName();
        });
    }
});
</script>

<script>
<?php if (empty($vendor['legal_entity_type'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (sessionStorage.getItem('walkthrough_profile_form_done') === 'true') return; 
    const targetBox = document.getElementById('tour-target-badan-hukum');
    const docsTab = document.getElementById('docs-tab');
    if (targetBox) {
        const overlay = document.createElement('div'); overlay.className = 'tour-overlay fade-in'; document.body.appendChild(overlay);
        targetBox.style.position = 'relative'; targetBox.style.zIndex = '10000'; targetBox.style.backgroundColor = '#ffffff'; targetBox.classList.add('tour-highlight', 'shadow-lg');
        if(docsTab) { docsTab.style.position = 'relative'; docsTab.style.zIndex = '10000'; docsTab.style.backgroundColor = '#ffffff'; docsTab.style.borderRadius = '8px'; }

        const tooltip = document.createElement('div'); tooltip.className = 'tour-tooltip fade-in';
        tooltip.innerHTML = `
            <h5 class="fw-bold text-primary mb-2"><i class="fas fa-info-circle me-2"></i>Panduan Pengisian</h5>
            <p class="text-dark mb-3" style="font-size: 0.9rem; line-height:1.5;"><strong>1. Bentuk Badan Hukum:</strong> Pilih tipe perusahaan Anda di sini.<br><br><strong>2. Lengkapi Data:</strong> Pastikan Nama Direktur & Nomor Telepon terisi sesuai dokumen resmi.<br><br><strong>3. Tab Dokumen Legal:</strong> Klik tab <b>Dokumen Legal</b> di atas untuk mengunggah berkas.</p>
            <div class="text-end"><button id="btn-mengerti-tour" class="btn btn-primary fw-bold rounded-pill w-100 shadow-sm"><i class="fas fa-check-circle me-1"></i> Oke, Saya Mengerti</button></div>
        `;
        document.body.appendChild(tooltip);

        function updatePositions() {
            const rect = targetBox.getBoundingClientRect();
            tooltip.style.top = (rect.bottom + window.scrollY + 15) + 'px';
            let leftPos = rect.left + (rect.width / 2) - (350 / 2); if (leftPos < 10) leftPos = 10; tooltip.style.left = leftPos + 'px';
        }
        setTimeout(updatePositions, 50); window.addEventListener('resize', updatePositions);
        
        document.getElementById('btn-mengerti-tour').addEventListener('click', function(e) {
            e.preventDefault(); sessionStorage.setItem('walkthrough_profile_form_done', 'true');
            overlay.remove(); tooltip.remove(); targetBox.classList.remove('tour-highlight', 'shadow-lg'); targetBox.style.zIndex = '';
            if(docsTab) { docsTab.style.zIndex = ''; docsTab.style.backgroundColor = ''; }
        });
        window.scrollTo({ top: targetBox.offsetTop - 150, behavior: 'smooth' });
    }
});
<?php endif; ?>
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>