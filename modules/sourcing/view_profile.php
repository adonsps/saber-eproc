<?php
// modules/sourcing/view_profile.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Allow BOTH Sourcing and VM)
$allowed_roles = ['sourcing', 'vendor_mgt', 'vendormgt_head'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

if (!isset($_GET['id'])) {
    die("Error: No Vendor ID provided.");
}

$vendor_id = intval($_GET['id']);

// 2. Fetch Full Vendor Data
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    die("Vendor not found.");
}

// WHT Tax Type/Code only apply to construction work (see modules/vendor/profile.php).
$company_type_lower = strtolower($vendor['company_type'] ?? '');
$is_contractor_vendor = strpos($company_type_lower, 'contractor') !== false || strpos($company_type_lower, 'kontraktor') !== false;

// Correction mode: while the profile is locked to the vendor (PQ under review)
// Sourcing/VM can still edit it here, through an impersonation session — see
// modules/shared/edit_vendor_profile.php. The button only exists in that
// window; outside it the vendor maintains their own data.
require_once $path_to_root . 'includes/vendor_context.php';
$staff_edit_available = vendor_profile_is_locked($vendor['registration_status'] ?? '')
    && in_array($_SESSION['role'], vendor_staff_editor_roles(), true);
$staff_edit_docs_too  = in_array($_SESSION['role'], vendor_staff_document_editor_roles(), true);
$staff_edit_url = $path_to_root . 'modules/shared/edit_vendor_profile.php?id=' . (int)$vendor_id
    . '&return_to=' . urlencode('modules/sourcing/view_profile.php?id=' . (int)$vendor_id);
$page_title = "Vendor Profile: " . $vendor['company_name'];
include $path_to_root . 'includes/header.php';

// Per-document "last updated by / at" for the Dokumen Legalitas panel.
$doc_last_update = vendor_doc_last_updates($conn, $vendor_id);

// Helper function to render a document row
function renderDocRow($label, $filePath, $rootPath, $ocrNumber = null, $ocrStatus = 'empty', $docKey = null) {
    global $doc_last_update;
    echo '<div class="d-flex justify-content-between align-items-center py-3 border-bottom border-secondary border-opacity-10">';
    echo '  <div>';
    echo '      <div class="fw-bold text-dark small mb-1"><i class="fas fa-file-alt text-primary me-2"></i>' . $label . '</div>';
    if ($docKey !== null && !empty($doc_last_update[$docKey])) {
        $lu = $doc_last_update[$docKey];
        $lu_verb = $lu['action'] === 'deleted' ? 'dihapus' : 'diubah';
        echo '      <div class="small text-muted fst-italic mb-1" style="font-size:0.65rem;"><i class="fas fa-clock me-1"></i>Terakhir ' . $lu_verb . ' oleh <strong>' . htmlspecialchars($lu['actor_name']) . '</strong> pada ' . htmlspecialchars(date('d M Y, H:i', strtotime($lu['created_at']))) . ' WIB</div>';
    }

    if ($ocrNumber !== null) {
        if (!empty($ocrNumber)) {
            echo '<div class="small text-muted mb-1">Extracted ID: <strong class="text-dark">' . htmlspecialchars($ocrNumber ?? '') . '</strong></div>';
            if ($ocrStatus === 'verified') echo '<span class="badge bg-success" style="font-size: 0.6rem;"><i class="fas fa-check-circle me-1"></i>Verified</span>';
            elseif ($ocrStatus === 'invalid') echo '<span class="badge bg-danger" style="font-size: 0.6rem;"><i class="fas fa-times-circle me-1"></i>Invalid/Mismatched</span>';
            elseif ($ocrStatus === 'unverified') echo '<span class="badge bg-warning text-dark" style="font-size: 0.6rem;"><i class="fas fa-exclamation-triangle me-1"></i>Manually Edited</span>';
            else echo '<span class="badge bg-secondary" style="font-size: 0.6rem;"><i class="fas fa-database me-1"></i>Saved</span>';
        } else {
             echo '<div class="small text-muted fst-italic" style="font-size: 0.65rem;">No ID extracted</div>';
        }
    }
    
    echo '  </div>';
    
    if (!empty($filePath)) {
        echo '  <a href="' . $rootPath . '../uploads/' . htmlspecialchars($filePath ?? '') . '" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm flex-shrink-0" style="font-size: 0.7rem;"><i class="fas fa-external-link-alt me-1"></i> View</a>';
    } else {
        echo '  <span class="badge bg-light text-muted border fst-italic flex-shrink-0" style="font-size: 0.65rem;">Not Uploaded</span>';
    }
    echo '</div>';
}
?>

<style>
    .profile-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 16px;
        position: relative;
        overflow: hidden;
    }
    .profile-header::before {
        content: "\f1ad"; /* Building icon */
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        position: absolute;
        right: -20px;
        bottom: -40px;
        font-size: 12rem;
        color: rgba(0,0,0,0.03);
        transform: rotate(-10deg);
        z-index: 0;
    }
    .profile-header-content {
        position: relative;
        z-index: 1;
    }
    .section-title {
        color: #495057;
        font-size: 0.85rem;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 1.5rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid rgba(13, 110, 253, 0.1);
        display: flex;
        align-items: center;
    }
    .section-title i {
        color: #0d6efd;
        margin-right: 10px;
    }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Vendor Profile Details
        </span>
        <div class="d-flex align-items-center gap-2">
            <?php if ($staff_edit_available): ?>
                <a href="<?= htmlspecialchars($staff_edit_url) ?>" class="btn btn-sm btn-warning fw-bold"
                   title="Profil ini terkunci untuk vendor selama penilaian. Anda dapat mengoreksinya di sini."
                   data-bs-toggle="modal" data-bs-target="#staffEditConfirmModal">
                    <i class="fas fa-user-pen me-1"></i> Koreksi Profil<?= $staff_edit_docs_too ? ' &amp; Dokumen' : '' ?>
                </a>
            <?php endif; ?>
            <a href="<?= $path_to_root ?>modules/vendormgt/vendor_contacts.php?id=<?= (int)$vendor_id ?>" class="btn btn-sm btn-nav-solid" title="Kelola narahubung & alihkan peran utama">
                <i class="fas fa-users me-1"></i> Narahubung
            </a>
            <a href="javascript:window.history.back()" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5" style="max-width: 1000px;">

    <?php if (!empty($_GET['error'])): ?>
        <div class="alert alert-warning border-0 shadow-sm rounded-4 mt-3">
            <i class="fas fa-triangle-exclamation me-2"></i><?= htmlspecialchars($_GET['error']) ?>
        </div>
    <?php endif; ?>

    <!-- TOP HEADER CARD -->
    <div class="profile-header p-4 p-md-5 mb-4 shadow-sm border border-light">
        <div class="profile-header-content d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <?php 
                    $st = $vendor['registration_status'];
                    $badge_class = 'bg-secondary';
                    $label = strtoupper(str_replace('_', ' ', $st));
                    if ($st == 'registration_submitted') { $label = "REGISTRATION SUBMITTED"; $badge_class = 'bg-info text-dark'; }
                    if ($st == 'registered') $badge_class = 'bg-info text-dark';
                    if ($st == 'invited_pq') $badge_class = 'bg-warning text-dark';
                    if ($st == 'pq_submitted') $badge_class = 'bg-primary';
                    if ($st == 'under_review_vm') $badge_class = 'bg-secondary text-white';
                    if ($st == 'under_review_vm_head') $badge_class = 'bg-primary text-white';
                    if ($st == 'approved') $badge_class = 'bg-success';
                    if (in_array($st, ['revision_requested', 'returned_to_sourcing'])) $badge_class = 'bg-danger';
                ?>
                <div class="mb-2">
                    <span class="badge <?= $badge_class ?> rounded-pill px-3 py-1 shadow-sm border border-light"><?= $label ?></span>
                </div>
                <h2 class="fw-bold text-dark mb-1"><?= htmlspecialchars($vendor['company_name'] ?? '') ?></h2>
                <div class="text-muted d-flex align-items-center flex-wrap gap-3 mt-2">
                    <span><i class="fas fa-briefcase me-1"></i> <?= htmlspecialchars($vendor['company_type'] ?? '') ?></span>
                    <span class="opacity-25">|</span>
                    <span><i class="fas fa-sitemap me-1 text-primary"></i> Sub: <strong><?= htmlspecialchars($vendor['sub_company_type'] ?? 'Unclassified') ?></strong></span>
                </div>
            </div>
            
            <div class="mt-4 mt-md-0 text-md-end">
                <div class="small text-muted text-uppercase fw-bold mb-1" style="font-size: 0.7rem;">Registered On</div>
                <div class="fw-bold text-dark fs-5"><i class="fas fa-calendar-alt text-primary me-2"></i> <?= date('d M Y', strtotime($vendor['created_at'])) ?></div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- LEFT COLUMN: GENERAL INFO -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4 p-md-5">
                    <h5 class="section-title"><i class="fas fa-info-circle"></i> Corporate Information</h5>
                    
                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Director Name</label>
                        <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($vendor['director_name'] ?? '-') ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Establishment Date</label>
                        <div class="fw-bold text-dark fs-6"><?= !empty($vendor['establishment_date']) ? date('d F Y', strtotime($vendor['establishment_date'])) : '-' ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Jumlah Karyawan</label>
                        <div class="fw-bold text-dark fs-6"><i class="fas fa-users text-secondary me-1"></i> <?= ($vendor['number_of_employees'] ?? '') !== '' ? number_format((int) $vendor['number_of_employees'], 0, ',', '.') : '-' ?></div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-sm-6 mb-4 mb-sm-0">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Contact Person</label>
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['contact_person'] ?? '') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Phone Number</label>
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-phone text-secondary me-1"></i> <?= htmlspecialchars($vendor['phone'] ?? '') ?></div>
                        </div>
                    </div>

                    <div class="row mb-4">
                        <div class="col-sm-6 mb-4 mb-sm-0">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">PIC Finance</label>
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-user-tie text-secondary me-1"></i> <?= htmlspecialchars($vendor['pic_finance'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Email PIC Finance</label>
                            <div class="fw-bold text-primary fs-6"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email_pic_finance'] ?? '-') ?></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Login Email</label>
                        <div class="fw-bold text-primary"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email'] ?? '') ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Company Address <span class="text-muted fw-normal text-lowercase">(dari NPWP)</span></label>
                        <div class="fw-bold text-dark" style="line-height:1.5;"><i class="fas fa-map-marker-alt text-secondary me-1"></i> <?= !empty($vendor['company_address']) ? nl2br(htmlspecialchars($vendor['company_address'] ?? '')) : '-' ?></div>
                    </div>

                    <hr class="my-4 border-light">

                    <h5 class="section-title"><i class="fas fa-file-invoice-dollar"></i> Informasi Pajak</h5>
                    <div class="row">
                        <div class="col-sm-4 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Status PKP</label>
                            <div class="fw-bold">
                                <?php if (!empty($vendor['is_sppkp'])): ?>
                                    <span class="badge bg-success">PKP</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Non-PKP</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">WHT Tax Type</label>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($vendor['wht_tax_type'] ?: '-') ?></div>
                        </div>
                        <div class="col-sm-4 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">WHT Tax Code</label>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($vendor['wht_tax_code'] ?: '-') ?></div>
                        </div>
                    </div>

                    <hr class="my-4 border-light">

                    <h5 class="section-title"><i class="fas fa-university"></i> Banking Details</h5>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Nama Bank</label>
                            <div class="fw-bold text-dark"><i class="fas fa-university text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Cabang Bank</label>
                            <div class="fw-bold text-dark"><i class="fas fa-map-marker-alt text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_branch'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Account Number</label>
                            <div class="fw-bold text-dark font-monospace"><?= htmlspecialchars($vendor['bank_account_number'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Account Holder</label>
                            <div class="fw-bold text-dark"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_holder_name'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: DOCUMENTS -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4 p-md-5">
                    <h5 class="section-title"><i class="fas fa-folder-open"></i> Dokumen Legalitas</h5>
                    
                    <div class="d-flex flex-column gap-1">
                        <?php 
                        $entity_type = $vendor['legal_entity_type'] ?? '';
                        $comp_type   = strtolower($vendor['company_type'] ?? '');

                        // 1. Dokumen Dasar (semua vendor)
                        renderDocRow("KTP Direktur / Pemilik", $vendor['director_id_card_file'] ?? '', $path_to_root, $vendor['director_id_number'] ?? '', $vendor['director_id_status'] ?? '', docKey: 'director_id_card_file');
                        renderDocRow("NPWP (Tax ID)", $vendor['npwp_path'] ?? '', $path_to_root, $vendor['npwp_number'] ?? '', $vendor['npwp_status'] ?? '', docKey: 'npwp_file');
                        renderDocRow("Halaman Depan Buku Rekening", $vendor['bank_cover_path'] ?? '', $path_to_root, $vendor['bank_account_number'] ?? '', $vendor['bank_account_status'] ?? '', docKey: 'bank_cover');
                        renderDocRow("Surat Pernyataan Perbedaan Nama Rekening", $vendor['bank_holder_statement_letter_file'] ?? '', $path_to_root, docKey: 'bank_holder_statement_letter_file');
                        renderDocRow("Pakta Integritas Kawan Lama Group", $vendor['business_ethics_commitment_file'] ?? '', $path_to_root, docKey: 'business_ethics_commitment_file');
                        renderDocRow("Tax Statement Letter", $vendor['tax_statement_letter_file'] ?? '', $path_to_root, docKey: 'tax_statement_letter_file');
                        renderDocRow("Profil Perusahaan / Brosur", $vendor['company_profile_file'] ?? '', $path_to_root, docKey: 'company_profile');
                        // UMKM applies to Perseorangan and Badan Usaha alike (see is_umkm in
                        // modules/vendor/profile.php), so this row stays outside the
                        // Badan-Usaha-only block below.
                        renderDocRow("Sertifikasi UMKM", $vendor['umkm_cert_file'] ?? '', $path_to_root, $vendor['umkm_cert_number'] ?? '', $vendor['umkm_cert_status'] ?? '', docKey: 'umkm_cert_file');

                        // 2. Dokumen Khusus Badan Usaha
                        if ($entity_type === 'Badan Usaha') {
                            echo '<div class="mt-3 mb-2"><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1" style="font-size:0.7rem;"><i class="fas fa-building me-1"></i> Dokumen Badan Usaha</span></div>';
                            renderDocRow("Dokumen NIB", $vendor['nib_path'] ?? '', $path_to_root, $vendor['nib_number'] ?? '', $vendor['nib_status'] ?? '', docKey: 'nib_file');
                            renderDocRow("Akta Pendirian", $vendor['deed_of_establishment_file'] ?? '', $path_to_root, $vendor['deed_of_establishment_number'] ?? '', $vendor['deed_of_establishment_status'] ?? '', docKey: 'deed_of_establishment_file');
                            renderDocRow("SK Pengesahan Badan Hukum", $vendor['decree_of_legal_entity_file'] ?? '', $path_to_root, $vendor['decree_of_legal_entity_number'] ?? '', $vendor['decree_of_legal_entity_status'] ?? '', docKey: 'decree_of_legal_entity_file');
                            renderDocRow("Akta Perubahan", $vendor['deed_of_amendment_file'] ?? '', $path_to_root, docKey: 'deed_of_amendment_file');
                            renderDocRow("SPPKP", $vendor['sppkp_path'] ?? '', $path_to_root, docKey: 'sppkp_file');
                        }

                        // 3. Dokumen Khusus Kontraktor
                        if (strpos($comp_type, 'contractor') !== false || strpos($comp_type, 'kontraktor') !== false) {
                            echo '<div class="mt-3 mb-2"><span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-3 py-1" style="font-size:0.7rem;"><i class="fas fa-hard-hat me-1"></i> Dokumen Kontraktor</span></div>';
                            renderDocRow("Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)", $vendor['business_entity_cert_file'] ?? '', $path_to_root, docKey: 'business_entity_cert_file');
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/staff_edit_confirm_modal.php'; ?>
<?php include $path_to_root . 'includes/footer.php'; ?>