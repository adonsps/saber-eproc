<?php
// modules/vendor/profile.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';
require_once $path_to_root . 'includes/vendor_profile_draft.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$vendor_id = (int) ($_SESSION['vendor_id'] ?? 0);

// "Buang draf": throw away the recovered input and go back to the stored data.
// The redirect carries ?draft_discarded=1 so the localStorage autosave at the
// bottom of the page drops its copy too — otherwise it would restore what the
// vendor just discarded.
if (isset($_GET['discard_draft'])) {
    // Documents from a rejected attempt were already written to the row (see
    // profile_action.php) — discarding the typing without undoing them would
    // leave the vendor stuck with a document they never managed to save.
    vendor_profile_doc_rollback_apply($conn, $vendor_id);
    vendor_profile_draft_clear();

    // "Buang Perubahan" in the unsaved-changes modal discards on the way to
    // somewhere else, so it passes the destination along. Same-origin relative
    // paths only — the same guard profile_action.php puts on redirect_after_save.
    $next       = trim($_GET['next'] ?? '');
    $next_parts = $next !== '' ? parse_url($next) : false;
    if ($next_parts !== false && empty($next_parts['scheme']) && empty($next_parts['host']) && strpos($next, '//') !== 0) {
        header("Location: " . $next);
        exit();
    }

    $tab = ($_GET['tab'] ?? '') !== '' ? "&tab=" . urlencode($_GET['tab']) : '';
    header("Location: profile.php?draft_discarded=1" . $tab);
    exit();
}

// 2. Fetch Current Data
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    die("Vendor profile not found.");
}

// Derived Contractor/Supplier track (see includes/vendor_business_model.php) —
// still used to gate the SBUJK document, which stays Contractor-only. The WHT
// Tax Type/Code dropdowns below are shown and required for every vendor;
// non-construction vendors (Supplier) pick the "N/A" entry in the list.
$company_type_lower = strtolower($vendor['company_type'] ?? '');
$is_contractor_vendor = strpos($company_type_lower, 'contractor') !== false || strpos($company_type_lower, 'kontraktor') !== false;

// Profile lock: read-only while in the PQ / penilaian pipeline (unlocks on
// approval or a revision request). See includes/vendor_context.php.
$status_locked = vendor_profile_is_locked($vendor['registration_status'] ?? '');

// Staff override. Sourcing/VM reach this page through
// modules/shared/edit_vendor_profile.php, which impersonates the vendor, so the
// lock has to distinguish "the vendor is looking at their own frozen profile"
// from "procurement is fixing it mid-review". Which roles get which half is
// decided in includes/vendor_context.php (today every reviewing role gets
// profile fields AND legal documents); the two flags are kept separate here
// so the page keeps working if the document half is ever narrowed again.
// Outside the locked statuses nothing here applies, so the vendor's normal
// experience and the Direct Registration flow are both untouched.
$staff_edits_profile = $status_locked && vendor_staff_can_edit_profile();
$staff_edits_docs    = $status_locked && vendor_staff_can_edit_documents();

$profile_locked = $status_locked && !$staff_edits_profile;
// Documents are read-only whenever the whole profile is, plus the one case the
// profile is editable but documents are not (a staff role without the document half).
$docs_locked    = $profile_locked || ($staff_edits_profile && !$staff_edits_docs);

// Staff correction session (not a real vendor, not Direct Registration). The
// conditional "wajib" documents — SPPKP when PKP, Sertifikasi UMKM when UMKM,
// Surat Pernyataan when the account holder differs — are still required of the
// vendor, but a reviewer swapping ONE wrong file on a profile that already
// lacks another of them must not be refused for that: the JS below leaves the
// `required` flag off those file inputs in this mode, and profile_action.php
// skips the matching server checks the same way it already does for SBUJK.
$staff_correction = vendor_staff_edit_session();

// Bentuk Badan Hukum + UMKM freeze once the vendor has submitted PQ (both decide
// which question set is scored), so their inputs render disabled.
$status = $vendor['registration_status'] ?? '';
$is_entity_locked = !in_array($status, ['registration_submitted', 'registered', 'invited_pq', 'migrated', 'revision_requested']) && !empty($vendor['legal_entity_type']);

// Draft recovery: when the previous save was rejected, render what the vendor
// typed instead of the last saved row. Disabled fields post nothing, so their
// draft values are ignored in favour of the stored ones.
$profile_draft = $profile_locked ? [] : vendor_profile_draft_get($vendor_id);
$form = vendor_profile_form_values($vendor, $profile_draft, $is_entity_locked ? ['legal_entity_type', 'is_umkm'] : []);

// Current CP's own login credentials (email + username) — shown read-only.
// These come from `users`, NOT from `vendors`: with multiple CPs per vendor,
// vendors.email is the company record and is not this user's login id.
$my_username = '';
$my_email    = '';
$uStmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$uStmt->bind_param("i", $user_id);
$uStmt->execute();
$uRow = $uStmt->get_result()->fetch_assoc();
if ($uRow) {
    $my_username = (string) ($uRow['username'] ?? '');
    $my_email    = (string) ($uRow['email'] ?? '');
}

// Invitation ID (vendor_invitations.invite_code — the IVT-YYMMXXX code quoted in
// the invitation email). vendor_invitations has no vendor_id; the link is the
// address the invite was sent to, which becomes the company email at registration.
// A vendor can be invited more than once, so prefer the invitation that was
// actually claimed (registered_at set), then the most recent. Empty for vendors
// who self-registered without an invite. Shown read-only like email/username.
$invite_code = '';
try {
    $icStmt = $conn->prepare("
        SELECT invite_code
          FROM vendor_invitations
         WHERE invitee_email = ? AND invite_code IS NOT NULL AND invite_code <> ''
      ORDER BY (registered_at IS NOT NULL) DESC, registered_at DESC, created_at DESC
         LIMIT 1
    ");
    $icStmt->bind_param("s", $vendor['email']);
    $icStmt->execute();
    $icRow = $icStmt->get_result()->fetch_assoc();
    if ($icRow) {
        $invite_code = (string) ($icRow['invite_code'] ?? '');
    }
} catch (mysqli_sql_exception $e) {
    // 1146 = vendor_invitations table not created yet (fresh install, invite page
    // never opened). Leave the code blank rather than break the profile page.
    if ($e->getCode() != 1146) {
        error_log("[profile] invite_code lookup failed: " . $e->getMessage());
    }
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

// With Holding Tax (WHT) reference lists — static per Finance's tax code table,
// not stored in the DB, so the vendor picks from a fixed list here. "N/A" lets
// a Supplier (non-construction) vendor satisfy the required field when none of
// the tax articles below apply to them.
$wht_tax_codes = [
    'N/A - Bukan Objek Pemotongan PPh di Atas' => [
        'N/A',
    ],
    'W1 - Withholding Tax Art 4(2)' => [
        '04 - Perencanaan Konstruksi (Kualifikasi Usaha) 4%',
        '05 - Perencanaan Konstruksi (Tanpa Kualifikasi) 6%',
        '06 - Pengawasan Konstruksi (Kualifikasi Usaha) 4%',
        '07 - Pengawasan Konstruksi (Tanpa Kualifikasi) 6%',
        '08 - Sewa Tanah/Bangunan 10%',
        '09 - Dividen (Pribadi) 10%',
        '10 - Hadiah Undian 25%',
        '11 - Pelaksanaan Konstruksi 2022 (Usaha Kecil) 1.75%',
        '12 - Pelaksanaan Konstruksi 2022 (Lainnya) 2.65%',
        '13 - Perencanaan Konstruksi 2022 (Kualifikasi Usaha) 3.5%',
    ],
    'W2 - Withholding Tax Art 21 (Acc. per Month)' => [
        '01 - Honorarium kepada Tenaga Ahli',
        '02 - Honorarium kepada Tenaga Ahli (Non)',
        '07 - Hadiah dan Penghargaan 5%',
        '08 - Hadiah dan Penghargaan 15%',
        '09 - Hadiah dan Penghargaan 25%',
        '10 - Hadiah dan Penghargaan 30%',
        '17 - Imbalan kepada Penasihat, Penceramah, Moderator',
        '18 - Imbalan kepada Pengarang, Peneliti, Penerjemah',
        '19 - Imbalan kepada Pemberi Jasa dalam Segala Bidang',
        '20 - Imbalan kepada Agen Iklan',
    ],
    'W3 - Withholding Tax Art 23' => [
        '01 - Dividen (Perusahaan) Kepemilikan > 25% 0%',
        '02 - Dividen (Perusahaan) Kepemilikan > 25% 15%',
        '04 - Bunga 15%',
        '06 - Royalti 15%',
        '08 - Hadiah dan Penghargaan 15%',
        '10 - Sewa Harta 2%',
        '12 - Jasa Teknik 2%',
        '14 - Jasa Manajemen 2%',
        '16 - Jasa Konsultan 2%',
        '18 - Jasa Penilai 2%',
    ],
    'W3 - Withholding Tax Art 26' => [
        '01 - Dividen 10%',
        '02 - Dividen 15%',
        '03 - Dividen (Non COD) 20%',
        '04 - Bunga 10%',
        '05 - Bunga (Non COD) 20%',
        '06 - Royalti 10%',
        '07 - Royalti 15%',
        '08 - Royalti (Non COD) 20%',
        '09 - Sewa Harta 10%',
        '10 - Sewa Harta (Non COD) 20%',
    ],
    'W3 - Withholding Tax Art 15' => [
        '01 - Pelayaran 1.2%',
    ],
    'W3 - Withholding Tax Art PP23' => [
        '01 - UMKM 0.5%',
    ],
];
$wht_tax_types = array_keys($wht_tax_codes);

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

    .custom-file-upload-label {
        display: flex;
        align-items: stretch;
        width: 100%;
        min-width: 0;
        padding: 0;
        overflow: hidden;
        cursor: pointer;
    }
    .custom-file-upload-button,
    .custom-file-upload-icon {
        display: flex;
        align-items: center;
        flex-shrink: 0;
        padding: 0.25rem 0.75rem;
        background: #f8f9fa;
    }
    .custom-file-upload-button { border-right: 1px solid #dee2e6; }
    .custom-file-upload-icon { border-left: 1px solid #dee2e6; }
    .custom-file-upload-name {
        align-self: center;
        min-width: 0;
        padding: 0.25rem 0.75rem;
        overflow: hidden;
        color: #495057;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    #deleteFileConfirmModal .modal-dialog { max-width: 430px; }
    #deleteFileConfirmModal .modal-content {
        border-radius: 22px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
    }
    #deleteFileConfirmModal .delete-modal-accent {
        height: 5px;
        background: linear-gradient(90deg, #dc3545, #ff6b6b, #dc3545);
    }
    #deleteFileConfirmModal .delete-modal-icon {
        position: relative;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 78px;
        height: 78px;
        border: 8px solid #fff;
        border-radius: 50%;
        margin-top: -39px;
        color: #dc3545;
        background: #fff1f2;
        box-shadow: 0 8px 24px rgba(220, 53, 69, 0.2);
        font-size: 1.65rem;
    }
    #deleteFileConfirmModal .delete-modal-warning {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.4rem 0.75rem;
        border-radius: 999px;
        color: #b42332;
        background: #fff1f2;
        font-size: 0.75rem;
        font-weight: 600;
    }
    #deleteFileConfirmModal .modal-footer .btn {
        min-width: 145px;
        border-radius: 10px;
        padding-top: 0.65rem;
        padding-bottom: 0.65rem;
    }
    #deleteFileConfirmModal .btn-danger {
        border: 0;
        background: linear-gradient(135deg, #dc3545, #b91c2d);
        box-shadow: 0 7px 16px rgba(220, 53, 69, 0.24);
    }
    #deleteFileConfirmModal .btn-danger:hover {
        transform: translateY(-1px);
        box-shadow: 0 9px 20px rgba(220, 53, 69, 0.3);
    }
    #deleteFileConfirmModal.show .modal-content {
        animation: deleteModalEnter 0.25s ease-out;
    }
    #unsavedChangesModal .modal-dialog { max-width: 470px; }
    #unsavedChangesModal .modal-content {
        border-radius: 22px;
        box-shadow: 0 24px 70px rgba(15, 23, 42, 0.22);
    }
    #unsavedChangesModal .modal-header {
        position: relative;
        overflow: hidden;
        background: linear-gradient(135deg, #0d6efd, #0756c7) !important;
    }
    #unsavedChangesModal .modal-header::after {
        content: '';
        position: absolute;
        right: 45px;
        bottom: -35px;
        width: 95px;
        height: 95px;
        border-radius: 50%;
        background: rgba(255, 255, 255, 0.08);
    }
    #unsavedChangesModal .modal-title,
    #unsavedChangesModal .btn-close {
        position: relative;
        z-index: 1;
    }
    #unsavedChangesModal .modal-title {
        color: #fff !important;
    }
    #unsavedChangesModal .modal-footer .btn {
        flex: 1;
        min-width: 0;
        border-radius: 11px;
        padding: 0.7rem 1rem;
    }
    @media (max-width: 480px) {
        #unsavedChangesModal .modal-footer {
            flex-direction: column;
        }
        #unsavedChangesModal .modal-footer .btn {
            width: 100%;
        }
    }
    @keyframes deleteModalEnter {
        from { opacity: 0; transform: translateY(12px) scale(0.97); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
</style>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Profil Perusahaan</span>
        <?php if (!vendor_staff_edit_session()): ?>
        <a href="dashboard.php" class="btn btn-sm btn-nav"><i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
        <?php endif; ?>
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

            <?php // On a partial save the green + red pair above already says what
                  // went through and what did not, so this would only be a third
                  // banner repeating it.
            ?>
            <?php if (!empty($profile_draft) && !isset($_GET['msg'])): ?>
                <div class="alert alert-info border-0 shadow-sm rounded-4 d-flex align-items-start gap-2" role="alert">
                    <i class="fas fa-rotate-left mt-1"></i>
                    <div class="flex-grow-1">
                        <strong>Data yang Anda isi sebelumnya sudah dipulihkan.</strong>
                        Silakan perbaiki bagian yang bermasalah, lalu klik <strong>Submit</strong> lagi.
                        <div class="small mt-1">
                            <i class="fas fa-paperclip me-1"></i>Berkas/dokumen yang Anda unggah pada percobaan itu sudah tersimpan — periksa daftar dokumen di tab <strong>Dokumen Legal</strong>, unggah ulang hanya bila ada yang belum muncul. Menekan <strong>Buang draf</strong> akan mengembalikannya ke dokumen sebelumnya.
                        </div>
                    </div>
                    <a href="profile.php?discard_draft=1" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                        <i class="fas fa-xmark me-1"></i>Buang draf
                    </a>
                </div>
            <?php endif; ?>

            <?php if (!empty($vendor['last_updated_at'])): ?>
                <div class="text-muted small mb-3">
                    <i class="fas fa-clock-rotate-left me-1"></i>
                    Terakhir diperbarui oleh <strong><?= htmlspecialchars($vendor['last_updated_by_name'] ?: '—') ?></strong>
                    pada <?= htmlspecialchars(date('d M Y, H:i', strtotime($vendor['last_updated_at']))) ?> WIB
                </div>
            <?php endif; ?>

            <?php if ($profile_locked): ?>
                <div class="alert alert-warning border-0 shadow-sm rounded-4">
                    <i class="fas fa-lock text-warning me-2"></i><strong>Profil terkunci.</strong>
                    Data profil Anda sedang dalam proses <strong>Pra-Kualifikasi &amp; Penilaian</strong> oleh tim Procurement, sehingga tidak dapat diubah untuk sementara.
                    Anda dapat memperbaruinya kembali setelah permohonan <strong>disetujui</strong> atau diminta melakukan <strong>revisi</strong>.
                </div>
            <?php elseif ($staff_edits_profile): ?>
                <div class="alert alert-info border-0 shadow-sm rounded-4">
                    <i class="fas fa-user-shield text-primary me-2"></i><strong>Mode koreksi Procurement.</strong>
                    Profil ini terkunci untuk vendor karena sedang dalam proses <strong>Pra-Kualifikasi &amp; Penilaian</strong>,
                    <?php if ($staff_edits_docs): ?>
                        namun Anda dapat mengubah <strong>data profil dan Dokumen Legal</strong>.
                    <?php else: ?>
                        namun Anda dapat mengubah <strong>data profil saja</strong> &mdash;
                        tab <strong>Dokumen Legal</strong> tetap hanya-baca untuk peran Anda.
                    <?php endif; ?>
                    Setiap perubahan tercatat atas nama Anda.
                </div>
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
                                    <div class="col-md-6"><label class="form-label small fw-bold text-muted">Email (ID Pengguna)</label><input type="text" class="form-control bg-light" value="<?= htmlspecialchars($my_email) ?>" readonly></div>
                                </div>
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Username (ID Masuk Alternatif)</label>
                                        <input type="text" class="form-control bg-light font-monospace" value="<?= $my_username !== '' ? htmlspecialchars($my_username) : '(belum diatur)' ?>" readonly>
                                        <div class="form-text small" style="font-size:0.7rem;"><i class="fas fa-circle-info me-1"></i>Anda dapat masuk dengan email <strong>atau</strong> username ini. Ubah di halaman <a href="contacts.php">Kelola Narahubung</a>.</div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-muted">Kode Undangan</label>
                                        <input type="text" class="form-control bg-light font-monospace" value="<?= $invite_code !== '' ? htmlspecialchars($invite_code) : '—' ?>" readonly>
                                        <div class="form-text small" style="font-size:0.7rem;"><i class="fas fa-circle-info me-1"></i>Nomor Undangan (Invitation ID) dari email undangan Anda. Tidak dapat diubah.</div>
                                    </div>
                                </div>

                                <hr class="my-4">
                                <h6 class="fw-bold mb-3 text-secondary"><i class="fas fa-edit me-2"></i>Detail Perusahaan</h6>

                                <?php
                                // $status / $is_entity_locked are resolved at the top of this file,
                                // where the draft-recovery values are built from them.
                                // Direct Registration: Sourcing fills this form on the vendor's
                                // behalf and may not have every one of these details on hand yet
                                // (e.g. bank info, PIC finance) at profile-completion time — relax
                                // the HTML5 `required` attribute so they can save partial progress
                                // and come back, matching the flow's resumable design elsewhere.
                                $is_impersonating = !empty($_SESSION['impersonator']);
                                $req = $is_impersonating ? '' : 'required';
                                ?>
                                <div id="tour-target-badan-hukum" class="mb-4 p-3 bg-light border border-secondary border-opacity-25 rounded-3" style="transition: all 0.3s ease;">
                                    <label class="form-label fw-bold text-dark">Bentuk Badan Hukum <span class="text-danger">*</span></label>
                                    <select name="legal_entity_type" id="legal_entity_type" class="form-select border-secondary fw-bold text-dark shadow-sm" style="background-color: <?= $is_entity_locked ? '#e9ecef' : '#ffffff' ?>;" <?= $req ?> <?= $is_entity_locked ? 'disabled' : '' ?>>
                                        <option value="" disabled <?= empty($form['legal_entity_type']) ? 'selected' : '' ?>>Pilih Bentuk Badan Hukum...</option>
                                        <option value="Badan Usaha" <?= (($form['legal_entity_type'] ?? '') == 'Badan Usaha') ? 'selected' : '' ?>>Badan Usaha (PT, CV, Firma, dll)</option>
                                        <option value="Perseorangan" <?= (($form['legal_entity_type'] ?? '') == 'Perseorangan') ? 'selected' : '' ?>>Perseorangan (Individu)</option>
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

                                <?php
                                    // Jumlah Karyawan — digits only. Rendered with "." thousand
                                    // separators (1.000); profile_action.php strips the separators
                                    // back out before the integer is stored.
                                    $raw_employee_count = preg_replace('/\D/', '', (string) ($form['number_of_employees'] ?? ''));
                                    $employee_count_display = ($raw_employee_count !== '')
                                        ? number_format((int) $raw_employee_count, 0, ',', '.')
                                        : '';
                                ?>
                                <div class="mb-4 p-3 bg-light border border-secondary border-opacity-25 rounded-3">
                                    <label for="number_of_employees" class="form-label fw-bold text-dark">Jumlah Karyawan <span class="text-danger">*</span></label>
                                    <input type="text" name="number_of_employees" id="number_of_employees"
                                           class="form-control border-secondary fw-bold text-dark shadow-sm"
                                           inputmode="numeric" autocomplete="off" placeholder="Contoh: 1.000"
                                           pattern="[1-9][0-9.]*" title="Masukkan angka lebih dari 0 (contoh: 1.000)."
                                           value="<?= htmlspecialchars($employee_count_display) ?>" <?= $req ?>>
                                </div>

                                <?php /* ── SPPKP Declaration (Badan Usaha & Perseorangan) ── */ ?>
                                <div id="sppkp-declaration-container"
                                     class="mb-4 p-3 border border-info border-opacity-50 rounded-3 bg-white shadow-sm">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="is_sppkp" id="is_sppkp_check" value="1"
                                               <?= ($form['is_sppkp'] ?? 1) ? 'checked' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark" for="is_sppkp_check">
                                            Terdaftar sebagai Pengusaha Kena Pajak (PKP) / SPPKP
                                        </label>
                                    </div>
                                    <div class="form-text small text-muted mt-1 ms-4">
                                        <i class="fas fa-info-circle me-1 text-info"></i>
                                        Centang jika perusahaan/usaha Anda memiliki Surat Pengukuhan Pengusaha Kena Pajak (SPPKP).
                                        Dokumen SPPKP akan menjadi wajib di tab <strong>Dokumen Legal</strong>.
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">WHT Tax Type <span class="text-danger">*</span></label>
                                        <select name="wht_tax_type" id="wht_tax_type" class="form-select" autocomplete="off" <?= $req ?>>
                                            <option value="" <?= empty($form['wht_tax_type']) ? 'selected' : '' ?>>-- Pilih WHT Tax Type --</option>
                                            <?php foreach ($wht_tax_types as $type): ?>
                                            <?php if ($is_contractor_vendor && $type === 'N/A - Bukan Objek Pemotongan PPh di Atas') continue; ?>
                                            <option value="<?= htmlspecialchars($type) ?>" <?= (($form['wht_tax_type'] ?? '') === $type) ? 'selected' : '' ?>><?= htmlspecialchars($type) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">WHT Tax Code <span class="text-danger">*</span></label>
                                        <select name="wht_tax_code" id="wht_tax_code" class="form-select" autocomplete="off" <?= $req ?>>
                                            <option value="" <?= empty($form['wht_tax_code']) ? 'selected' : '' ?>>-- Pilih Kode WHT --</option>
                                            <?php foreach ($wht_tax_codes as $group_label => $codes): ?>
                                            <?php if ($is_contractor_vendor && $group_label === 'N/A - Bukan Objek Pemotongan PPh di Atas') continue; ?>
                                            <optgroup label="<?= htmlspecialchars($group_label) ?>">
                                                <?php foreach ($codes as $code): ?>
                                                <option value="<?= htmlspecialchars($code) ?>" <?= (($form['wht_tax_code'] ?? '') === $code) ? 'selected' : '' ?>><?= htmlspecialchars($code) ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <?php /*
                                    UMKM declaration. This is not cosmetic: an UMKM vendor is
                                    pre-qualified with the lighter standalone 'Perseorangan'
                                    question set instead of its company_type's full set, so it
                                    is locked at the same moment as Bentuk Badan Hukum —
                                    swapping question sets mid-review would strand the answers
                                    already scored against the other set.
                                */ ?>
                                <div id="umkm-declaration-container"
                                     class="mb-4 p-3 border border-info border-opacity-50 rounded-3 bg-white shadow-sm">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="is_umkm" id="is_umkm_check" value="1"
                                               <?= !empty($form['is_umkm']) ? 'checked' : '' ?>
                                               <?= $is_entity_locked ? 'disabled' : '' ?>>
                                        <label class="form-check-label fw-bold text-dark" for="is_umkm_check">
                                            Apakah Anda UMKM?
                                        </label>
                                    </div>
                                    <div class="form-text small text-muted mt-1 ms-4">
                                        <i class="fas fa-info-circle me-1 text-info"></i>
                                        Centang jika usaha Anda termasuk <strong>Usaha Mikro, Kecil dan Menengah (UMKM)</strong>.
                                    </div>
                                    <?php if ($is_entity_locked): ?>
                                        <div class="form-text small mt-2 ms-4 text-danger fw-bold">
                                            <i class="fas fa-lock me-1"></i> Terkunci karena Anda sudah mengajukan Pra-Kualifikasi.
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
                                        
                                        <label class="form-label fw-bold text-primary small mb-2 position-relative z-1"><i class="fas fa-magic me-1"></i> Deteksi Kategori Otomatis <span class="text-danger">*</span></label>
                                        <p class="text-dark small mb-2 position-relative z-1" style="line-height:1.4;">Ceritakan secara singkat barang/jasa yang Anda sediakan. AI kami akan otomatis menyarankan kategori yang paling pas untuk Anda.</p>

                                        <div class="input-group position-relative z-1 shadow-sm">
                                            <textarea id="ai_biz_desc" name="business_description" class="form-control border-primary border-opacity-50" rows="2" placeholder="Contoh: Perusahaan kami adalah distributor laptop, printer, kertas HVS, serta melayani instalasi jaringan server..." <?= $req ?>><?= htmlspecialchars($form['business_description'] ?? '') ?></textarea>
                                            <button type="button" id="btn_ai_suggest" class="btn btn-primary px-3 d-flex flex-column justify-content-center align-items-center fw-bold" style="min-width: 130px; transition: all 0.3s;">
                                                <i class="fas fa-robot mb-1 fs-5"></i> Coba AI
                                            </button>
                                        </div>
                                        <div class="invalid-feedback">Mohon ceritakan barang/jasa utama perusahaan Anda terlebih dahulu.</div>
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

                                    <input type="hidden" name="core_business" id="core_business_input" value="<?= htmlspecialchars($form['core_business'] ?? '') ?>">
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nama Direktur / Pemilik <span class="text-danger">*</span></label>
                                        <input type="text" id="director_name" name="director_name" class="form-control js-no-placeholder" value="<?= htmlspecialchars($form['director_name'] ?? '') ?>" placeholder="Nama Lengkap Direktur / Pemilik" <?= $req ?>>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Kontak Direktur (No. HP)</label>
                                        <input type="tel" id="dirPhoneInput" class="form-control" placeholder="812 3456 7890" autocomplete="off">
                                        <input type="hidden" name="director_phone" id="dirPhoneHidden" value="<?= htmlspecialchars($form['director_phone'] ?? '') ?>">
                                        <div id="dirPhoneStatus" class="phone-status-profile empty">
                                            <i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label small fw-bold">Tanggal Pendirian</label>
                                    <input type="date" name="establishment_date" class="form-control" value="<?= htmlspecialchars($form['establishment_date'] ?? '') ?>">
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Contact Person / Narahubung <span class="text-danger">*</span></label>
                                        <input type="text" id="contact_person" name="contact_person" class="form-control js-no-placeholder" value="<?= htmlspecialchars($form['contact_person'] ?? '') ?>" <?= $req ?>>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nomor Telepon (WA) <span class="text-danger">*</span></label>
                                        <input type="tel" id="profilePhoneInput" class="form-control" placeholder="812 3456 7890" <?= $req ?> autocomplete="off">
                                        <input type="hidden" name="phone" id="profilePhoneHidden" value="<?= htmlspecialchars($form['phone'] ?? '') ?>">
                                        <div id="profilePhoneStatus" class="phone-status-profile empty">
                                            <i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">PIC Finance <span class="text-danger">*</span></label>
                                        <input type="text" id="pic_finance" name="pic_finance" class="form-control js-no-placeholder" value="<?= htmlspecialchars($form['pic_finance'] ?? '') ?>" placeholder="Nama PIC Finance" <?= $req ?>>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">
                                            Email PIC Finance <span class="text-danger">*</span>
                                            <span tabindex="0"
                                                  data-bs-toggle="tooltip"
                                                  data-bs-placement="top"
                                                  title="Disarankan menggunakan email grup tim finance (contoh: finance@perusahaan.com) agar notifikasi diterima oleh seluruh anggota tim, bukan hanya satu individu."
                                                  style="cursor:pointer;">
                                                <i class="fas fa-info-circle text-primary ms-1"></i>
                                            </span>
                                        </label>
                                        <input type="email" name="email_pic_finance" class="form-control" value="<?= htmlspecialchars($form['email_pic_finance'] ?? '') ?>" placeholder="finance@perusahaan.com" <?= $req ?>>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nama Bank <span class="text-danger">*</span></label>
                                        <input type="text" id="bank_name" name="bank_name" class="form-control js-no-placeholder" value="<?= htmlspecialchars($form['bank_name'] ?? '') ?>" placeholder="Contoh: BCA, Mandiri, BNI" <?= $req ?>>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Cabang Bank <span class="text-danger">*</span></label>
                                        <input type="text" id="bank_branch" name="bank_branch" class="form-control js-no-placeholder" value="<?= htmlspecialchars($form['bank_branch'] ?? '') ?>" placeholder="Contoh: KCU Sudirman" <?= $req ?>>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>

                                <?php
                                // "Nama Pemilik Rekening sama dengan Nama Perusahaan?" is a UI-only
                                // toggle — nothing is stored for it in `vendors`. Its checked state is
                                // derived by comparing the saved bank_holder_name to company_name
                                // (case/whitespace-insensitive); only a rejected submission's session
                                // draft (which does carry the posted checkbox value) overrides that.
                                $bank_company_name = $vendor['company_name'] ?? '';
                                if (array_key_exists('bank_holder_is_company', $form)) {
                                    $bank_holder_same = !empty($form['bank_holder_is_company']);
                                } else {
                                    $stored_holder_name = trim((string) ($form['bank_holder_name'] ?? ''));
                                    $bank_holder_same = $stored_holder_name === ''
                                        || mb_strtolower($stored_holder_name) === mb_strtolower(trim($bank_company_name));
                                }
                                ?>
                                <div class="mb-3">
                                    <label class="form-label small fw-bold" for="bank_holder_is_company_check">Nama Pemilik Rekening sama dengan Nama Perusahaan? <span class="text-danger">*</span></label>
                                    <select class="form-select" name="bank_holder_is_company" id="bank_holder_is_company_check" <?= $req ?>>
                                        <option value="1" <?= $bank_holder_same ? 'selected' : '' ?>>Sama dengan Nama Perusahaan</option>
                                        <option value="0" <?= !$bank_holder_same ? 'selected' : '' ?>>Berbeda dengan Nama Perusahaan</option>
                                    </select>
                                    <div class="form-text small text-muted mt-1">
                                        <i class="fas fa-info-circle me-1 text-info"></i>
                                        Pilih "Sama dengan Nama Perusahaan" jika rekening bank terdaftar atas nama <strong><?= htmlspecialchars($bank_company_name) ?></strong>. Jika berbeda, isi nama pemilik rekening secara manual dan unggah <strong>Surat Pernyataan Perbedaan Nama Rekening</strong> pada tab Dokumen Legal.
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold">Nama Pemilik Rekening <span class="text-danger">*</span></label>
                                        <input type="text" id="bank_holder_name" name="bank_holder_name" class="form-control js-no-placeholder"
                                               value="<?= htmlspecialchars($bank_holder_same ? $bank_company_name : ($form['bank_holder_name'] ?? '')) ?>"
                                               placeholder="Harus sesuai nama perusahaan" maxlength="100" <?= $req ?>
                                               <?= $bank_holder_same ? 'readonly' : '' ?>>
                                        <div class="invalid-feedback"></div>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="docs" role="tabpanel" aria-labelledby="docs-tab">
                        <div class="card shadow-sm border-0 mb-4">
                            <div class="card-body p-4">
                                <div class="alert alert-light border-0 shadow-sm mb-4"><i class="fas fa-info-circle text-primary me-2"></i> <small>Mohon unggah dokumen resmi Anda dalam format <strong>PDF, JPG, atau PNG</strong>. Maksimal <strong>3MB</strong> untuk Akta Pendirian, Akta Perubahan, SBUJK, NIB, NPWP, KTP, SPPKP, SK Pengesahan Badan Hukum, Sertifikasi UMKM, dan Surat Pernyataan Perbedaan Nama Rekening; <strong>1MB</strong> untuk Tax Statement Letter dan Pakta Integritas; <strong>10MB</strong> untuk Profil Perusahaan (ComPro); <strong>5MB</strong> untuk Buku Rekening.</small></div>
                                <?php if ($docs_locked && !$profile_locked): ?>
                                <div class="alert alert-warning border-0 shadow-sm mb-4">
                                    <i class="fas fa-lock text-warning me-2"></i>
                                    <small><strong>Dokumen Legal hanya-baca.</strong> Peran Anda tidak dapat mengganti atau
                                    menghapus berkas legal vendor yang sedang dinilai. Anda tetap dapat
                                    mengubah data profil pada tab <strong>Informasi Umum</strong>.</small>
                                </div>
                                <?php endif; ?>

                                <div class="card border-0 shadow-sm rounded-4 overflow-hidden position-relative mb-4" style="background: linear-gradient(135deg, #f0f7ff 0%, #e0edff 100%);">
                                    <div class="position-absolute top-50 end-0 translate-middle-y text-primary opacity-10 pe-3 d-none d-md-block" style="z-index: 0;"><i class="fas fa-file-signature fa-8x"></i></div>
                                    <div class="card-body p-3 position-relative z-1">
                                        <h6 class="fw-bold text-primary mb-2"><i class="fas fa-download me-2"></i>Template Dokumen</h6>

                                        <div class="d-flex align-items-center gap-3 py-2 border-bottom border-primary border-opacity-10">
                                            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center flex-shrink-0 shadow-sm" style="width:36px; height:36px;"><i class="fas fa-file-pdf"></i></div>
                                            <div class="flex-grow-1" style="min-width:0;">
                                                <div class="fw-bold small text-dark">Pakta Integritas Kawan Lama Group</div>
                                                <div class="small text-muted">Unduh, baca, tandatangani, lalu unggah salinannya pada kolom Pakta Integritas di bawah.</div>
                                            </div>
                                            <a href="<?= $path_to_root ?>../uploads/templates/Pakta_Integritas_Kawan_Lama_Group.pdf" target="_blank" download="Pakta_Integritas_Kawan_Lama_Group.pdf" class="btn btn-sm btn-primary rounded-pill fw-bold shadow-sm px-3 flex-shrink-0 btn-interactive"><i class="fas fa-cloud-download-alt me-1"></i> Unduh PDF</a>
                                        </div>

                                        <div class="d-flex align-items-center gap-3 py-2 border-bottom border-primary border-opacity-10">
                                            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center flex-shrink-0 shadow-sm" style="width:36px; height:36px;"><i class="fas fa-file-pdf"></i></div>
                                            <div class="flex-grow-1" style="min-width:0;">
                                                <div class="fw-bold small text-dark">Tax Statement Letter</div>
                                                <div class="small text-muted">Unduh, baca, tandatangani, lalu unggah salinannya pada kolom Tax Statement Letter di bawah.</div>
                                            </div>
                                            <a href="<?= $path_to_root ?>../uploads/templates/tax_statement_letter.pdf" target="_blank" download="tax_statement_letter.pdf" class="btn btn-sm btn-primary rounded-pill fw-bold shadow-sm px-3 flex-shrink-0 btn-interactive"><i class="fas fa-cloud-download-alt me-1"></i> Unduh PDF</a>
                                        </div>

                                        <div class="d-flex align-items-center gap-3 py-2 doc-bank-holder-diff-row" style="<?= $bank_holder_same ? 'display:none;' : '' ?>">
                                            <div class="rounded-circle bg-white text-primary d-flex align-items-center justify-content-center flex-shrink-0 shadow-sm" style="width:36px; height:36px;"><i class="fas fa-file-pdf"></i></div>
                                            <div class="flex-grow-1" style="min-width:0;">
                                                <div class="fw-bold small text-dark">Surat Pernyataan Perbedaan Nama Rekening</div>
                                                <div class="small text-muted">Unduh, baca, tandatangani, lalu unggah salinannya pada kolom Surat Pernyataan Perbedaan Nama Rekening di bawah.</div>
                                            </div>
                                            <a href="<?= $path_to_root ?>../uploads/templates/Surat_Pernyataan_Perbedaan_Nama_Rekening.pdf" target="_blank" download="Surat_Pernyataan_Perbedaan_Nama_Rekening.pdf" class="btn btn-sm btn-primary rounded-pill fw-bold shadow-sm px-3 flex-shrink-0 btn-interactive"><i class="fas fa-cloud-download-alt me-1"></i> Unduh PDF</a>
                                        </div>
                                    </div>
                                </div>

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

                                    // Label batas ukuran per dokumen: company_profile 10MB; Akta Pendirian/Perubahan,
                                    // SBUJK, NIB, NPWP, KTP, SPPKP, SK Pengesahan Badan Hukum, Sertifikasi UMKM,
                                    // Surat Pernyataan Perbedaan Nama Rekening 3MB; Tax Statement Letter/Pakta
                                    // Integritas 1MB; Buku Rekening 5MB.
                                    $docs_3mb = ['deed_of_establishment_file', 'deed_of_amendment_file', 'business_entity_cert_file', 'nib_file', 'npwp_file', 'director_id_card_file', 'sppkp_file', 'decree_of_legal_entity_file', 'umkm_cert_file', 'bank_holder_statement_letter_file'];
                                    $docs_1mb = ['business_ethics_commitment_file', 'tax_statement_letter_file'];
                                    if ($inputName === 'company_profile') {
                                        $emptyFileText = 'No file chosen (Max Size 10 MB)';
                                    } elseif (in_array($inputName, $docs_3mb, true)) {
                                        $emptyFileText = 'No file chosen (Max Size 3 MB)';
                                    } elseif (in_array($inputName, $docs_1mb, true)) {
                                        $emptyFileText = 'No file chosen (Max Size 1 MB)';
                                    } else {
                                        $emptyFileText = 'No file chosen (Max Size 5 MB)';
                                    }

                                    if (!empty($dbValue)) {
                                        echo '      <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded border saved-file-display" data-file-type="'.$inputName.'">';
                                        echo '          <div class="text-truncate me-2"><i class="fas fa-file-pdf text-danger me-2"></i><a href="'.$rootPath.'../uploads/'.htmlspecialchars($dbValue).'" target="_blank" class="text-decoration-none fw-bold text-dark">Lihat Dokumen</a></div>';
                                        echo '          <div class="flex-shrink-0"><button type="button" class="btn btn-sm btn-outline-primary me-1" onclick="document.getElementById(\'input_'.$inputName.'\').classList.toggle(\'d-none\');" title="Ganti Dokumen"><i class="fas fa-edit"></i></button><button type="button" class="btn btn-sm btn-outline-danger saved-file-delete-button" data-file-type="'.$inputName.'" title="Hapus Dokumen"><i class="fas fa-trash-alt"></i></button></div>';
                                        echo '      </div>';
                                        echo '      <div id="input_'.$inputName.'" class="mt-2 d-none fade-in">';
                                        echo '          <input type="file" id="file_replace_'.$inputName.'" name="'.$inputName.'" class="visually-hidden custom-file-upload-input" accept=".pdf,.jpg,.jpeg,.png">';
                                        echo '          <div class="d-flex align-items-stretch gap-1">';
                                        echo '          <label for="file_replace_'.$inputName.'" class="form-control form-control-sm custom-file-upload-label flex-grow-1">';
                                        echo '              <span class="custom-file-upload-button">Choose File</span>';
                                        echo '              <span class="custom-file-upload-name flex-grow-1" data-empty-text="'.htmlspecialchars($emptyFileText, ENT_QUOTES).'">'.htmlspecialchars($emptyFileText).'</span>';
                                        echo '              <span class="custom-file-upload-icon"><i class="fas fa-upload"></i></span>';
                                        echo '          </label>';
                                        echo '          <button type="button" class="btn btn-sm btn-outline-danger d-none custom-file-clear-button" data-file-input="file_replace_'.$inputName.'" title="Hapus file yang dipilih"><i class="fas fa-trash-alt"></i></button>';
                                        echo '          </div>';
                                        echo '      </div>';
                                    } else {
                                        echo '      <input type="file" id="file_'.$inputName.'" name="'.$inputName.'" class="visually-hidden custom-file-upload-input" accept=".pdf,.jpg,.jpeg,.png">';
                                        echo '      <div class="d-flex align-items-stretch gap-1">';
                                        echo '      <label for="file_'.$inputName.'" class="form-control form-control-sm custom-file-upload-label flex-grow-1">';
                                        echo '          <span class="custom-file-upload-button">Choose File</span>';
                                        echo '          <span class="custom-file-upload-name flex-grow-1" data-empty-text="'.htmlspecialchars($emptyFileText, ENT_QUOTES).'">'.htmlspecialchars($emptyFileText).'</span>';
                                        echo '          <span class="custom-file-upload-icon"><i class="fas fa-upload"></i></span>';
                                        echo '      </label>';
                                        echo '      <button type="button" class="btn btn-sm btn-outline-danger d-none custom-file-clear-button" data-file-input="file_'.$inputName.'" title="Hapus file yang dipilih"><i class="fas fa-trash-alt"></i></button>';
                                        echo '      </div>';
                                    }
                                    
                                    echo '  </div></div>';
                                }

                                renderDocRow("KTP Direktur / Pemilik <span class='text-danger'>*</span>", "director_id_card_file", $vendor['director_id_card_file'] ?? '', $path_to_root, "director_id_number", $form['director_id_number'] ?? '', "KTP", $vendor['director_id_status'] ?? '', 'doc-all');
                                renderDocRow("Halaman Depan Buku Rekening <span class='text-danger'>*</span>", "bank_cover", $vendor['bank_cover_path'] ?? '', $path_to_root, "bank_account_number", $form['bank_account_number'] ?? '', "Rekening", $vendor['bank_account_status'] ?? '', 'doc-all');
                                renderDocRow(!$bank_holder_same ? "Surat Pernyataan Perbedaan Nama Rekening <span class='text-danger'>*</span>" : "Surat Pernyataan Perbedaan Nama Rekening", "bank_holder_statement_letter_file", $vendor['bank_holder_statement_letter_file'] ?? '', $path_to_root, null, '', '', '', 'doc-bank-holder-diff');
                                renderDocRow("NPWP (Tax ID) <span class='text-danger'>*</span>", "npwp_file", $vendor['npwp_path'] ?? '', $path_to_root, "npwp_number", $form['npwp_number'] ?? '', "NPWP", $vendor['npwp_status'] ?? '', 'doc-all', 'npwp_name', $form['npwp_name'] ?? '', 'Nama Pemilik NPWP Terekstrak', 'company_address', $form['company_address'] ?? '', 'Alamat Wajib Pajak Terekstrak');
                                renderDocRow("Pakta Integritas Kawan Lama Group <span class='text-danger'>*</span>", "business_ethics_commitment_file", $vendor['business_ethics_commitment_file'] ?? '', $path_to_root, null, '', '', '', 'doc-all');
                                renderDocRow("Tax Statement Letter <span class='text-danger'>*</span>", "tax_statement_letter_file", $vendor['tax_statement_letter_file'] ?? '', $path_to_root, null, '', '', '', 'doc-all');
                                renderDocRow("Profil Perusahaan / Brosur", "company_profile", $vendor['company_profile_file'] ?? '', $path_to_root, null, '', '', '', 'doc-all');
                                renderDocRow("Dokumen NIB <span class='text-danger'>*</span>", "nib_file", $vendor['nib_path'] ?? '', $path_to_root, "nib_number", $form['nib_number'] ?? '', "NIB", $vendor['nib_status'] ?? '', 'doc-bu');
                                renderDocRow("Akta Pendirian <span class='text-danger'>*</span>", "deed_of_establishment_file", $vendor['deed_of_establishment_file'] ?? '', $path_to_root, "deed_of_establishment_number", $form['deed_of_establishment_number'] ?? '', "Akta", $vendor['deed_of_establishment_status'] ?? '', 'doc-bu');
                                renderDocRow("SK Pengesahan Badan Hukum <span class='text-danger'>*</span>", "decree_of_legal_entity_file", $vendor['decree_of_legal_entity_file'] ?? '', $path_to_root, "decree_of_legal_entity_number", $form['decree_of_legal_entity_number'] ?? '', "SK", $vendor['decree_of_legal_entity_status'] ?? '', 'doc-bu');
                                renderDocRow("Akta Perubahan", "deed_of_amendment_file", $vendor['deed_of_amendment_file'] ?? '', $path_to_root, null, '', '', '', 'doc-bu');
                                renderDocRow(($form['is_sppkp'] ?? 1) ? "SPPKP <span class='text-danger'>*</span>" : "SPPKP", "sppkp_file", $vendor['sppkp_path'] ?? '', $path_to_root, null, '', '', '', 'doc-sppkp');
                                renderDocRow(!empty($form['is_umkm']) ? "Sertifikasi UMKM <span class='text-danger'>*</span>" : "Sertifikasi UMKM", "umkm_cert_file", $vendor['umkm_cert_file'] ?? '', $path_to_root, "umkm_cert_number", $form['umkm_cert_number'] ?? '', "Surat Keterangan UMKM", $vendor['umkm_cert_status'] ?? '', 'doc-umkm');

                                if ($is_contractor_vendor) {
                                    renderDocRow("Sertifikat Badan Usaha Jasa Konstruksi (SBUJK) <span class='text-danger' id='sbujk_required_star'>*</span>", "business_entity_cert_file", $vendor['business_entity_cert_file'] ?? '', $path_to_root, null, '', '', '', 'doc-contractor');
                                }
                                ?>

                            </div>
                        </div>

                        <?php if (!$profile_locked): ?>
                        <div class="card border-0 shadow-sm rounded-4 mb-4" id="dataAccuracySection">
                            <div class="card-body p-4">
                                <div class="d-flex align-items-center gap-3 mb-3">
                                    <div class="d-flex align-items-center justify-content-center flex-shrink-0" style="width: 44px; height: 44px; background: #eef3ff; border-radius: 12px;">
                                        <i class="fas fa-clipboard-check text-primary"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold text-dark">Konfirmasi Kesesuaian Data</div>
                                        <div class="text-muted small">Wajib dicentang sebelum menyimpan</div>
                                    </div>
                                </div>
                                <label class="d-flex align-items-start gap-2 p-3 rounded-3 border data-accuracy-item" style="cursor: pointer;">
                                    <input type="checkbox" name="data_accuracy_confirmed" value="1" class="form-check-input mt-1 flex-shrink-0 data-accuracy-check" required>
                                    <span class="small">Saya menyatakan bahwa seluruh data dan dokumen (identitas perusahaan, rekening bank, NPWP, dan dokumen legal lainnya) yang saya isi/unggah pada formulir ini sudah benar, asli, dan sesuai dengan data sebenarnya, serta saya bertanggung jawab penuh atas kebenarannya.</span>
                                </label>
                                <div class="small text-danger mt-2 d-none" id="dataAccuracyFeedback"><i class="fas fa-exclamation-circle me-1"></i> Centang kotak ini untuk melanjutkan.</div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                </div>
                <?php if (!$profile_locked): ?>
                <div class="fixed-bottom bg-white border-top p-3 shadow-lg" style="z-index: 1030;">
                    <div class="container d-flex flex-wrap justify-content-end align-items-center gap-2">
                        <span class="small text-muted me-auto d-none d-md-inline"><i class="fas fa-circle-info me-1"></i>Simpan Draft menyimpan progres tanpa memeriksa kelengkapan. Submit mengirim profil untuk Pra-Kualifikasi.</span>
                        <button type="submit" name="save_draft" value="1" formnovalidate id="saveDraftButton" class="btn btn-outline-secondary px-4 fw-bold"><i class="fas fa-pen-to-square me-2"></i> Simpan Draft</button>
                        <button type="submit" name="update_profile" class="btn btn-primary px-5 fw-bold shadow-sm"><i class="fas fa-paper-plane me-2"></i> Submit</button>
                    </div>
                </div>
                <div style="height: 80px;"></div>
                <?php endif; ?>

            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="unsavedChangesModal" tabindex="-1" aria-labelledby="unsavedChangesModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 bg-primary text-white px-4 py-3">
                <h5 class="modal-title fw-bold" id="unsavedChangesModalLabel"><i class="fas fa-info-circle me-2"></i>Perubahan Belum Disimpan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted mb-0" style="line-height: 1.6;">Anda memiliki perubahan yang belum disimpan. Simpan sebagai draf sebelum meninggalkan halaman? Profil tetap dapat Anda Submit nanti.</p>
            </div>
            <div class="modal-footer border-0 bg-light px-4 py-3 d-flex flex-nowrap gap-3">
                <button type="button" class="btn btn-outline-danger fw-semibold" id="discardChangesButton"><i class="fas fa-trash-alt me-1"></i> Buang Perubahan</button>
                <button type="button" class="btn btn-primary fw-bold shadow-sm" id="saveBeforeLeaveButton"><i class="fas fa-pen-to-square me-1"></i> Simpan Draft</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteFileConfirmModal" tabindex="-1" aria-labelledby="deleteFileConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 overflow-hidden">
            <div class="delete-modal-accent"></div>
            <div class="modal-header border-0 pb-0 px-4 pt-3">
                <span class="delete-modal-warning"><i class="fas fa-shield-alt"></i> Tindakan perlu konfirmasi</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body px-4 pt-5 pb-4 text-center">
                <div class="delete-modal-icon mb-3">
                    <i class="fas fa-trash-alt"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2" id="deleteFileConfirmModalLabel">Hapus file ini?</h5>
                <p class="text-muted small mx-auto mb-0" id="deleteFileConfirmMessage" style="max-width: 330px; line-height: 1.65;">File yang dipilih akan dihapus.</p>
            </div>
            <div class="modal-footer border-0 bg-light px-4 py-3 justify-content-center gap-2">
                <button type="button" class="btn btn-outline-secondary fw-semibold" data-bs-dismiss="modal"><i class="fas fa-times me-1"></i> Batal</button>
                <button type="button" class="btn btn-danger fw-bold" id="confirmDeleteFileButton"><i class="fas fa-trash-alt me-1"></i> Ya, Hapus</button>
            </div>
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
    .data-accuracy-item { transition: border-color .15s ease, background-color .15s ease; }
    .data-accuracy-item:has(.data-accuracy-check:checked),
    .data-accuracy-item.checked { border-color: #10b981 !important; background: #f0fdf4; }
    .data-accuracy-item.is-invalid-item { border-color: #dc3545 !important; background: #fff5f5; }

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

    const profileFormElement = document.getElementById('profileForm');
    let hasUnsavedChanges = false;
    let isSubmittingProfile = false;
    let pendingNavigationUrl = '';
    let pendingNavigationSavePath = '';
    let dirtyTrackingReady = false;

    function markProfileDirty() {
        if (!dirtyTrackingReady) return;
        hasUnsavedChanges = true;
    }

    // Used by the draft-discard button: dropping the draft means there is nothing
    // left worth warning about on the way out.
    window.__profileClearDirty = function() {
        hasUnsavedChanges = false;
    };

    const deleteFileModalElement = document.getElementById('deleteFileConfirmModal');
    const deleteFileModal = deleteFileModalElement ? new bootstrap.Modal(deleteFileModalElement) : null;
    const deleteFileMessage = document.getElementById('deleteFileConfirmMessage');
    const confirmDeleteFileButton = document.getElementById('confirmDeleteFileButton');
    let pendingDeleteAction = null;

    function showDeleteFileConfirmation(message, action) {
        if (!deleteFileModal || !confirmDeleteFileButton) return;

        deleteFileMessage.textContent = message;
        pendingDeleteAction = action;
        deleteFileModal.show();
    }

    document.querySelectorAll('.custom-file-upload-input').forEach(input => {
        input.addEventListener('change', function() {
            const fileNameLabel = document.querySelector(`label[for="${this.id}"] .custom-file-upload-name`);
            const clearButton = document.querySelector(`.custom-file-clear-button[data-file-input="${this.id}"]`);
            if (!fileNameLabel) return;

            const existingError = document.getElementById(`file-size-error-${this.id}`);
            if (existingError) existingError.remove();

            if (this.files.length) {
                const file = this.files[0];
                const threeMbDocs = ['deed_of_establishment_file', 'deed_of_amendment_file', 'business_entity_cert_file', 'nib_file', 'npwp_file', 'director_id_card_file', 'sppkp_file', 'decree_of_legal_entity_file', 'umkm_cert_file', 'bank_holder_statement_letter_file'];
                const oneMbDocs = ['business_ethics_commitment_file', 'tax_statement_letter_file'];
                const maxSizeMb = this.name === 'company_profile' ? 10 : (threeMbDocs.includes(this.name) ? 3 : (oneMbDocs.includes(this.name) ? 1 : 5));

                if (file.size > maxSizeMb * 1024 * 1024) {
                    const errorMessage = document.createElement('div');
                    errorMessage.id = `file-size-error-${this.id}`;
                    errorMessage.className = 'file-size-error mt-2 p-2 rounded border border-danger';
                    errorMessage.style.cssText = 'background:#f8d7da;font-size:0.8rem;';
                    errorMessage.innerHTML = `<i class="fas fa-exclamation-triangle text-danger me-1"></i> <strong>File terlalu besar (${(file.size / (1024 * 1024)).toFixed(1)}MB).</strong> Maksimal ${maxSizeMb}MB per dokumen. Silakan kompres atau pilih file yang lebih kecil.`;

                    const inputWrapper = this.nextElementSibling;
                    if (inputWrapper) inputWrapper.insertAdjacentElement('afterend', errorMessage);

                    this.value = '';
                    fileNameLabel.textContent = fileNameLabel.dataset.emptyText;
                    if (clearButton) clearButton.classList.add('d-none');
                    setTimeout(() => { if (errorMessage.parentElement) errorMessage.remove(); }, 10000);
                    return;
                }
            }

            fileNameLabel.textContent = this.files.length
                ? this.files[0].name
                : fileNameLabel.dataset.emptyText;

            if (clearButton) {
                clearButton.classList.toggle('d-none', !this.files.length);
            }

            if (this.files.length) markProfileDirty();
        });
    });

    document.querySelectorAll('.custom-file-clear-button').forEach(button => {
        button.addEventListener('click', function() {
            const input = document.getElementById(this.dataset.fileInput);
            if (!input) return;

            showDeleteFileConfirmation('File yang baru dipilih akan dihapus dari form.', function() {
                input.value = '';
                input.dispatchEvent(new Event('change'));
                markProfileDirty();
            });
        });
    });

    document.querySelectorAll('.saved-file-delete-button').forEach(button => {
        button.addEventListener('click', function() {
            const fileType = this.dataset.fileType;
            const savedFileDisplay = document.querySelector(`.saved-file-display[data-file-type="${fileType}"]`);
            const emptyFileInput = document.getElementById(`input_${fileType}`);

            showDeleteFileConfirmation('Dokumen akan dihapus setelah Anda klik Simpan Draft atau Submit.', function() {
                let deleteInput = profileFormElement.querySelector(`input[name="delete_files[]"][value="${fileType}"]`);
                if (!deleteInput) {
                    deleteInput = document.createElement('input');
                    deleteInput.type = 'hidden';
                    deleteInput.name = 'delete_files[]';
                    deleteInput.value = fileType;
                    profileFormElement.appendChild(deleteInput);
                }

                if (savedFileDisplay) savedFileDisplay.classList.add('d-none');
                if (emptyFileInput) {
                    emptyFileInput.classList.remove('d-none', 'mt-2');
                }
                markProfileDirty();
            });
        });
    });

    if (confirmDeleteFileButton) {
        confirmDeleteFileButton.addEventListener('click', function() {
            if (!pendingDeleteAction) return;

            const action = pendingDeleteAction;
            pendingDeleteAction = null;
            deleteFileModal.hide();
            action();
        });
    }

    if (deleteFileModalElement) {
        deleteFileModalElement.addEventListener('hidden.bs.modal', function() {
            pendingDeleteAction = null;
        });
    }

    const unsavedChangesModalElement = document.getElementById('unsavedChangesModal');
    const unsavedChangesModal = unsavedChangesModalElement ? new bootstrap.Modal(unsavedChangesModalElement) : null;
    const discardChangesButton = document.getElementById('discardChangesButton');
    const saveBeforeLeaveButton = document.getElementById('saveBeforeLeaveButton');

    if (profileFormElement) {
        profileFormElement.addEventListener('input', markProfileDirty);
        profileFormElement.addEventListener('change', markProfileDirty);
        profileFormElement.addEventListener('submit', function() {
            isSubmittingProfile = true;
        });
    }

    document.querySelectorAll('a[href]').forEach(link => {
        link.addEventListener('click', function(event) {
            if (!hasUnsavedChanges || isSubmittingProfile || this.target === '_blank' || this.hasAttribute('download')) return;

            const href = this.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

            event.preventDefault();
            pendingNavigationUrl = this.href;
            const destination = new URL(this.href, window.location.href);
            pendingNavigationSavePath = destination.origin === window.location.origin
                ? destination.pathname + destination.search + destination.hash
                : '';
            if (unsavedChangesModal) unsavedChangesModal.show();
        });
    });

    if (discardChangesButton) {
        discardChangesButton.addEventListener('click', function() {
            // Clearing the page's dirty flag is only a third of the job: the
            // localStorage autosave and the session draft would both put the
            // discarded input straight back on the next visit, and a document
            // committed by a rejected save would stay on the row. Route through
            // the server's discard so all three go, then carry on to wherever
            // the vendor was heading.
            hasUnsavedChanges = false;
            if (typeof window.__profileDiscardLocalDraft === 'function') window.__profileDiscardLocalDraft();
            window.location.href = 'profile.php?discard_draft=1'
                + (pendingNavigationSavePath ? '&next=' + encodeURIComponent(pendingNavigationSavePath) : '');
        });
    }

    if (saveBeforeLeaveButton) {
        saveBeforeLeaveButton.addEventListener('click', function() {
            if (!profileFormElement) return;

            let redirectInput = profileFormElement.querySelector('input[name="redirect_after_save"]');
            if (!redirectInput) {
                redirectInput = document.createElement('input');
                redirectInput.type = 'hidden';
                redirectInput.name = 'redirect_after_save';
                profileFormElement.appendChild(redirectInput);
            }
            redirectInput.value = pendingNavigationSavePath;
            unsavedChangesModal.hide();
            // Draf, bukan Submit: vendor yang sedang meninggalkan halaman tidak
            // boleh tertahan oleh pemeriksaan kelengkapan.
            const profileDraftButton = document.getElementById('saveDraftButton');
            if (profileDraftButton) profileDraftButton.click();
        });
    }

    window.addEventListener('beforeunload', function(event) {
        if (!hasUnsavedChanges || isSubmittingProfile) return;
        event.preventDefault();
        event.returnValue = '';
    });

    setTimeout(function() {
        dirtyTrackingReady = true;
    }, 0);

    // Initialize all Bootstrap tooltips with zero delay so they appear
    // immediately when the cursor lands on the trigger icon.
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el, { delay: { show: 0, hide: 0 } });
    });
    
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

    // --- WHT TAX TYPE -> TAX CODE (sinkron) ---
    const whtCodeMap = <?= json_encode($wht_tax_codes, JSON_UNESCAPED_UNICODE) ?>;
    const whtSelectedType = <?= json_encode($form['wht_tax_type'] ?? '') ?>;
    const whtSelectedCode = <?= json_encode($form['wht_tax_code'] ?? '') ?>;
    const whtTypeSelect = document.getElementById('wht_tax_type');
    const whtCodeSelect = document.getElementById('wht_tax_code');

    function populateWhtCodes(selectedType, selectedCode) {
        if (!whtCodeSelect) return;
        whtCodeSelect.innerHTML = '<option value="">-- Pilih Kode WHT --</option>';
        (whtCodeMap[selectedType] || []).forEach(code => {
            const opt = document.createElement('option');
            opt.value = code;
            opt.textContent = code;
            if (code === selectedCode) opt.selected = true;
            whtCodeSelect.appendChild(opt);
        });
        whtCodeSelect.disabled = !selectedType;
    }

    // Exposed so the localStorage draft-restore below can rebuild the Code
    // list when it sets wht_tax_type directly (it doesn't dispatch a 'change'
    // event on the select, so this listener alone would never see it).
    window.__profileSyncWhtCode = populateWhtCodes;

    if (whtTypeSelect && whtCodeSelect) {
        // Force the Type select back to the server-known value: browsers restore
        // <select> state from history/bfcache on reload, which can silently
        // diverge from $form and leave the Code list populated for the wrong type.
        whtTypeSelect.value = whtSelectedType;
        populateWhtCodes(whtTypeSelect.value, whtSelectedCode);
        whtTypeSelect.addEventListener('change', function() {
            populateWhtCodes(this.value, '');
        });
    }

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

    // Draft restore hook: the badges are built from `selectedBusinesses`, so a
    // restored CSV has to come back in through here, not just into the hidden input.
    window.__profileSetCoreBusiness = function(csv) {
        selectedBusinesses = (csv || '').split(',').filter(Boolean);
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
    const sppkpCheckbox  = document.getElementById('is_sppkp_check');
    const umkmCheckbox   = document.getElementById('is_umkm_check');
    const bankHolderSameSelect = document.getElementById('bank_holder_is_company_check');
    // See $staff_correction at the top of the file: a reviewer's correction
    // session never has a conditional document made `required` on it.
    const STAFF_CORRECTION = <?= $staff_correction ? 'true' : 'false' ?>;

    function syncBankHolderDoc() {
        const isDifferent = bankHolderSameSelect ? bankHolderSameSelect.value !== '1' : <?= !$bank_holder_same ? 'true' : 'false' ?>;

        document.querySelectorAll('.doc-bank-holder-diff').forEach(el => {
            el.style.display = isDifferent ? 'flex' : 'none';
        });

        document.querySelectorAll('.doc-bank-holder-diff-row').forEach(el => {
            el.style.display = isDifferent ? 'flex' : 'none';
        });

        const statementFileInput = document.getElementById('file_bank_holder_statement_letter_file');
        if (statementFileInput) statementFileInput.required = isDifferent && !STAFF_CORRECTION;
    }

    // Sync SPPKP doc row visibility: visible whenever the checkbox is checked,
    // regardless of legal entity type (individuals can be PKP too).
    // Also controls the required attribute so the browser gate kicks in when the
    // file is missing and the vendor tries to submit.
    function syncSppkpDoc() {
        const isSppkp = sppkpCheckbox ? sppkpCheckbox.checked : <?= ($form['is_sppkp'] ?? 1) ? 'true' : 'false' ?>;
        const show    = isSppkp;

        document.querySelectorAll('.doc-sppkp').forEach(el => {
            el.style.display = show ? 'flex' : 'none';
        });

        // Only set required when the row is visible AND no doc has been uploaded yet
        // (when a doc exists the file input is hidden inside d-none, so required would block submit).
        const sppkpFileInput = document.getElementById('file_sppkp_file');
        if (sppkpFileInput) sppkpFileInput.required = show && !STAFF_CORRECTION;
    }

    // Sync Sertifikasi UMKM doc row visibility: same rule as SPPKP, keyed off
    // "Apakah Anda UMKM?" instead — required whenever that box is checked,
    // regardless of legal entity type.
    function syncUmkmDoc() {
        const isUmkm = umkmCheckbox ? umkmCheckbox.checked : <?= !empty($form['is_umkm']) ? 'true' : 'false' ?>;
        const show   = isUmkm;

        document.querySelectorAll('.doc-umkm').forEach(el => {
            el.style.display = show ? 'flex' : 'none';
        });

        const umkmFileInput = document.getElementById('file_umkm_cert_file');
        if (umkmFileInput) umkmFileInput.required = show && !STAFF_CORRECTION;
    }

    const isContractorVendor = <?= $is_contractor_vendor ? 'true' : 'false' ?>;

    if (entityDropdown) {
        entityDropdown.addEventListener('change', function() {
            const isBadanUsaha = (this.value === 'Badan Usaha');
            document.querySelectorAll('.doc-bu').forEach(el => { el.style.display = isBadanUsaha ? 'flex' : 'none'; });

            // SBUJK: wajib untuk kontraktor Badan Usaha, opsional untuk Perseorangan.
            document.querySelectorAll('.doc-contractor').forEach(el => { el.style.display = 'flex'; });

            const sbujkFileInput = document.getElementById('file_business_entity_cert_file');
            if (sbujkFileInput) sbujkFileInput.required = isBadanUsaha && isContractorVendor && !STAFF_CORRECTION;
            const sbujkStar = document.getElementById('sbujk_required_star');
            if (sbujkStar) sbujkStar.style.display = isBadanUsaha ? 'inline' : 'none';
        });
        entityDropdown.dispatchEvent(new Event('change'));
    }

    if (sppkpCheckbox) {
        sppkpCheckbox.addEventListener('change', syncSppkpDoc);
    }
    syncSppkpDoc();

    if (umkmCheckbox) {
        umkmCheckbox.addEventListener('change', syncUmkmDoc);
    }
    syncUmkmDoc();

    if (bankHolderSameSelect) {
        bankHolderSameSelect.addEventListener('change', syncBankHolderDoc);
    }
    syncBankHolderDoc();

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

                // Batas ukuran per dokumen: Akta Pendirian/Perubahan, SBUJK, NIB, NPWP, KTP,
                // SPPKP, SK Pengesahan Badan Hukum, Sertifikasi UMKM, Surat Pernyataan
                // Perbedaan Nama Rekening maks 3MB; Pakta Integritas maks 1MB; lainnya maks 5MB.
                const threeMbDocs = ['deed_of_establishment_file', 'deed_of_amendment_file', 'business_entity_cert_file', 'nib_file', 'npwp_file', 'director_id_card_file', 'sppkp_file', 'decree_of_legal_entity_file', 'umkm_cert_file', 'bank_holder_statement_letter_file'];
                const oneMbDocs = ['business_ethics_commitment_file', 'tax_statement_letter_file'];
                const baseName = fileInputId.replace('file_replace_', '').replace('file_', '');
                const maxSizeMb = threeMbDocs.includes(baseName) ? 3 : (oneMbDocs.includes(baseName) ? 1 : 5);
                const maxSize = maxSizeMb * 1024 * 1024;
                const maxLabel = maxSizeMb + 'MB';
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
                    errDiv.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-1"></i> <strong>File terlalu besar (' + sizeMB + 'MB).</strong> Maksimal ' + maxLabel + ' per dokumen. Silakan kompres atau pilih file yang lebih kecil.';
                    fileInput.parentElement.appendChild(errDiv);
                    
                    // Clear file input
                    this.value = '';
                    this.dispatchEvent(new Event('change'));
                    
                    // Auto-remove error after 10 seconds
                    setTimeout(() => { if (errDiv.parentElement) errDiv.remove(); }, 10000);
                    
                    return; // Stop — do NOT call AI
                }
                
                // Remove any previous file size error if user picks a valid file
                const prevErr = fileInput.parentElement.querySelector('.file-size-error');
                if (prevErr) prevErr.remove();
                // Remove any previous NPWP 16-digit rejection when a new file is picked
                const prevNpwpDigitErr = fileInput.parentElement.querySelector('.npwp-digit-error');
                if (prevNpwpDigitErr) prevNpwpDigitErr.remove();

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

                    // --- NPWP must carry the new 16-digit format (legacy cards are 15 digits) ---
                    if (docType === 'NPWP' && data.npwp_digit_invalid === true) {
                        // Reject: clear the extracted fields and force a re-upload.
                        textField.value = '';
                        textField.placeholder = 'Masukkan Nomor Secara Manual';
                        if (textField2) textField2.value = '';
                        if (textField3) textField3.value = '';
                        if (hiddenStatus) hiddenStatus.value = 'invalid';
                        statusLabel.innerHTML = '<span class="badge bg-danger"><i class="fas fa-times-circle me-1"></i> Bukan 16 Digit</span>';

                        const prevNpwpErr = fileInput.parentElement.querySelector('.npwp-digit-error');
                        if (prevNpwpErr) prevNpwpErr.remove();
                        const npwpErr = document.createElement('div');
                        npwpErr.className = 'npwp-digit-error mt-2 p-2 rounded border border-danger';
                        npwpErr.style.cssText = 'background:#f8d7da;font-size:0.8rem;';
                        npwpErr.innerHTML = '<i class="fas fa-exclamation-triangle text-danger me-1"></i> <strong>' +
                            (data.npwp_digit_message || 'Harap unggah kembali file dokumen NPWP dengan 16 digit.') + '</strong>';
                        fileInput.parentElement.appendChild(npwpErr);

                        // Drop the invalid file so it is not submitted with the form,
                        // and refresh the filename label / clear button via its listener.
                        fileInput.value = '';
                        fileInput.dispatchEvent(new Event('change'));
                        return;
                    }

                    if (data.is_valid_document && data.extracted_number) {
                        // Clear any prior NPWP 16-digit rejection now that a valid file was read.
                        const staleNpwpErr = fileInput.parentElement.querySelector('.npwp-digit-error');
                        if (staleNpwpErr) staleNpwpErr.remove();
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
    setupAiExtraction('file_umkm_cert_file', 'text_field_umkm_cert_file', 'ocr_status_umkm_cert_file', 'ocr_container_umkm_cert_file', 'hidden_status_umkm_cert_file', 'UMKM');
    setupAiExtraction('file_replace_umkm_cert_file', 'text_field_umkm_cert_file', 'ocr_status_umkm_cert_file', 'ocr_container_umkm_cert_file', 'hidden_status_umkm_cert_file', 'UMKM');

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

    // ── Kontak Direktur Phone ─────────────────────────────────────────
    const dirPhoneEl     = document.getElementById('dirPhoneInput');
    const dirPhoneHidden = document.getElementById('dirPhoneHidden');
    const dirPhoneStatus = document.getElementById('dirPhoneStatus');

    const dirIti = window.intlTelInput(dirPhoneEl, {
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

    // Draft restore hook: a restored number must go through intl-tel-input,
    // otherwise the visible field stays empty and the next sync wipes the hidden one.
    window.__profilePhoneItis = { phone: profileIti, director_phone: dirIti };

    const existingDirPhone = dirPhoneHidden.value;
    if (existingDirPhone) {
        const dirPhoneToSet = /^\d+$/.test(existingDirPhone) ? '+' + existingDirPhone : existingDirPhone;
        dirIti.setNumber(dirPhoneToSet);
    }

    function syncDirPhone() {
        const fullNumber = dirIti.getNumber();
        dirPhoneHidden.value = fullNumber;

        const inputVal = dirPhoneEl.value.replace(/\s/g, '');

        if (!inputVal || inputVal.length < 4) {
            dirPhoneStatus.className = 'phone-status-profile empty';
            dirPhoneStatus.innerHTML = '<i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>';
            return;
        }

        if (dirIti.isValidNumber()) {
            const countryData = dirIti.getSelectedCountryData();
            const countryName = countryData.name || '';
            dirPhoneStatus.className = 'phone-status-profile valid';
            dirPhoneStatus.innerHTML = '<i class="fas fa-check-circle"></i> <span>Nomor valid — ' + countryName + ' (' + fullNumber + ')</span>';
        } else {
            const errorMap = ["Nomor tidak valid", "Kode negara tidak valid", "Nomor terlalu pendek", "Nomor terlalu panjang", "Nomor tidak valid"];
            const errorCode = dirIti.getValidationError();
            const errorMsg  = errorMap[errorCode] || "Nomor tidak valid";
            dirPhoneStatus.className = 'phone-status-profile invalid';
            dirPhoneStatus.innerHTML = '<i class="fas fa-times-circle"></i> <span>' + errorMsg + '</span>';
        }
    }

    dirPhoneEl.addEventListener('input', syncDirPhone);
    dirPhoneEl.addEventListener('countrychange', syncDirPhone);
    dirPhoneEl.addEventListener('open:countrydropdown', syncDirPhone);
    setTimeout(syncDirPhone, 1000);

    const profileForm = document.getElementById('profileForm');
    const submitBtn = document.querySelector('button[name="update_profile"]');

    let npwpAnomalyAcknowledged = false;
    const companyName = <?= json_encode($vendor['company_name'] ?? '') ?>;
    const legalEntityType = <?= json_encode($form['legal_entity_type'] ?? '') ?>;

    // --- DATA ACCURACY DECLARATION ---
    // A single required checkbox; the existing checkValidity()/reportValidity()
    // gate further below still blocks Simpan and switches to this tab until
    // it's ticked. The only thing overridden here is the *look* of that
    // failure: the browser's default validation bubble is suppressed in favor
    // of an inline message that matches the rest of the form.
    const dataAccuracyFeedback = document.getElementById('dataAccuracyFeedback');
    document.querySelectorAll('.data-accuracy-check').forEach(cb => {
        const item = cb.closest('.data-accuracy-item');
        cb.addEventListener('change', function() {
            item.classList.toggle('checked', this.checked);
            if (this.checked) {
                item.classList.remove('is-invalid-item');
                if (dataAccuracyFeedback) dataAccuracyFeedback.classList.add('d-none');
            }
        });
        cb.addEventListener('invalid', function(e) {
            e.preventDefault();
            item.classList.add('is-invalid-item');
            if (dataAccuracyFeedback) dataAccuracyFeedback.classList.remove('d-none');
            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    });

    // Nama Pemilik Rekening: locked to companyName while the "sama dengan Nama
    // Perusahaan?" box is checked, free text otherwise. Whatever was typed
    // manually is stashed on the input itself (not a closure variable) right
    // as it gets locked, so a draft/autosave restore that sets .value directly
    // — bypassing this function — is never clobbered by a later re-sync.
    const bankHolderSelect = document.getElementById('bank_holder_is_company_check');
    const bankHolderInput  = document.getElementById('bank_holder_name');

    function syncBankHolderName() {
        if (!bankHolderInput) return;
        const same = bankHolderSelect ? bankHolderSelect.value === '1' : <?= $bank_holder_same ? 'true' : 'false' ?>;
        if (same) {
            if (!bankHolderInput.readOnly) bankHolderInput.dataset.manualValue = bankHolderInput.value;
            bankHolderInput.value = companyName;
            bankHolderInput.readOnly = true;
            bankHolderInput.classList.add('bg-light');
        } else {
            if (bankHolderInput.readOnly) bankHolderInput.value = bankHolderInput.dataset.manualValue || '';
            bankHolderInput.readOnly = false;
            bankHolderInput.classList.remove('bg-light');
        }
        bankHolderInput.dispatchEvent(new Event('input'));
    }

    if (bankHolderSelect) {
        bankHolderSelect.addEventListener('change', syncBankHolderName);
    }
    syncBankHolderName();

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
                
                // offsetParent is null once any ancestor is display:none — the
                // doc rows hidden for non-Badan-Usaha / non-UMKM vendors. A
                // required field in there can never be focused, so
                // reportValidity() below shows nothing at all and Simpan just
                // dies silently.
                if (container && !container.classList.contains('d-none') && input.offsetParent !== null) {
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
                return;
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

<?php if (!$profile_locked): ?>
<script>
// ── AUTOSAVE DRAF LOKAL ────────────────────────────────────────────────────
// The session draft in profile_action.php only covers a REJECTED save. This
// covers the other way input gets lost: closing or refreshing the tab before
// pressing Simpan at all. Every keystroke is mirrored into localStorage and
// restored on the next visit, until a save actually succeeds.
//
// Files are excluded — a browser never lets script refill an <input type="file">.
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('profileForm');
    if (!form || !window.localStorage) return;

    const STORAGE_KEY = 'vendor_profile_draft:<?= (int) $vendor_id ?>:<?= (int) $user_id ?>';
    const MAX_AGE_MS  = 3 * 24 * 60 * 60 * 1000;
    // Form plumbing, not vendor input.
    const SKIP = ['update_profile', 'apply_npwp_as_company_name', 'data_accuracy_confirmed', 'redirect_after_save', 'delete_files[]'];
    <?php
    // Fields the autosave must not track. legal_entity_type / is_umkm are frozen
    // after PQ submission. When only the documents are read-only (Sourcing's
    // override) the whole Dokumen Legal tab joins them: those inputs render
    // disabled, so restoring a stale draft value into them would show a number
    // that the server is going to ignore anyway.
    $autosave_locked = $is_entity_locked ? ['legal_entity_type', 'is_umkm'] : [];
    if ($docs_locked && !$profile_locked) {
        $autosave_locked = array_merge($autosave_locked, [
            'nib_number', 'npwp_number', 'director_id_number',
            'deed_of_establishment_number', 'decree_of_legal_entity_number',
            'bank_account_number', 'npwp_name', 'company_address',
            'umkm_cert_number',
        ]);
    }
    ?>
    const LOCKED = <?= json_encode($autosave_locked) ?>;

    function trackedFields() {
        return Array.from(form.querySelectorAll('input[name], select[name], textarea[name]')).filter(el =>
            el.type !== 'file' && !SKIP.includes(el.name) && !LOCKED.includes(el.name)
        );
    }

    function collect() {
        const values = {};
        trackedFields().forEach(el => {
            values[el.name] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
        });
        return values;
    }

    // Set once the vendor discards. Without it, clearing the draft and then
    // navigating away is pointless: the pagehide handler below runs on the way
    // out and writes the very values that were just thrown away back in.
    let discarded = false;

    function clearDraft() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
    }

    // Used by the unsaved-changes modal's "Buang Perubahan", which lives in a
    // different script block further up the page.
    window.__profileDiscardLocalDraft = function() {
        discarded = true;
        clearDraft();
    };

    // A save that went through lands here with ?msg=, and "Buang draf" with
    // ?draft_discarded=1 — either way the stored copy is spent.
    const query = new URLSearchParams(window.location.search);
    if (query.has('msg') || query.has('draft_discarded')) clearDraft();

    // Baseline = what the server just rendered (already including any session
    // draft). Only fields that differ from it are worth restoring.
    const baseline = collect();

    let stored = null;
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (raw) stored = JSON.parse(raw);
    } catch (e) { stored = null; }

    if (stored && (Date.now() - (stored.savedAt || 0)) > MAX_AGE_MS) {
        clearDraft();
        stored = null;
    }

    if (stored && stored.values) {
        const changed = Object.keys(stored.values).filter(name =>
            name in baseline && String(stored.values[name]) !== String(baseline[name])
        );

        if (changed.length === 0) {
            clearDraft(); // Nothing left to recover — the page already shows it.
        } else {
            const itis = window.__profilePhoneItis || {};
            changed.forEach(name => {
                const value = stored.values[name];
                form.querySelectorAll('[name="' + CSS.escape(name) + '"]').forEach(el => {
                    if (el.type === 'file') return;
                    if (el.type === 'checkbox') { el.checked = !!Number(value); return; }
                    el.value = value;

                    // Reveal the OCR box when a restored number would otherwise sit hidden.
                    const ocrBox = el.closest('[id^="ocr_container_"]');
                    if (ocrBox && String(value).trim() !== '') ocrBox.classList.remove('d-none');
                });

                if (itis[name] && String(value).trim() !== '') {
                    itis[name].setNumber(/^\d+$/.test(value) ? '+' + value : value);
                }
                if (name === 'core_business' && typeof window.__profileSetCoreBusiness === 'function') {
                    window.__profileSetCoreBusiness(value);
                }
            });

            // wht_tax_code's <option> list is rebuilt from wht_tax_type by JS, so a
            // restored type (or code) must re-run that sync — setting el.value above
            // doesn't dispatch 'change' and never rebuilds the Code list on its own.
            if ((changed.includes('wht_tax_type') || changed.includes('wht_tax_code')) &&
                typeof window.__profileSyncWhtCode === 'function') {
                const typeEl = form.querySelector('[name="wht_tax_type"]');
                const codeEl = form.querySelector('[name="wht_tax_code"]');
                const restoredCode = 'wht_tax_code' in stored.values
                    ? stored.values['wht_tax_code']
                    : (codeEl ? codeEl.value : '');
                if (typeEl) {
                    window.__profileSyncWhtCode(typeEl.value, restoredCode);
                }
            }

            // The hidden `*_status` flags travel with the OCR numbers; on their own
            // they are invisible to the vendor, so they do not warrant a banner
            // (this is also what keeps it from doubling up with the server-side one).
            if (changed.some(name => !name.endsWith('_status'))) showRestoreNotice();
            // Re-run the dependent UI (SPPKP row visibility, phone status, dirty flag)
            // once the page's own dirty tracking has finished booting.
            setTimeout(function() {
                form.dispatchEvent(new Event('change', { bubbles: true }));
                const sppkp = document.getElementById('is_sppkp_check');
                if (sppkp) sppkp.dispatchEvent(new Event('change', { bubbles: true }));
                const umkm = document.getElementById('is_umkm_check');
                if (umkm) umkm.dispatchEvent(new Event('change', { bubbles: true }));
                const bankHolderSame = document.getElementById('bank_holder_is_company_check');
                if (bankHolderSame) bankHolderSame.dispatchEvent(new Event('change', { bubbles: true }));
            }, 60);
        }
    }

    function showRestoreNotice() {
        const notice = document.createElement('div');
        notice.className = 'alert alert-warning border-0 shadow-sm rounded-4 d-flex align-items-start gap-2';
        notice.innerHTML =
            '<i class="fas fa-clock-rotate-left mt-1"></i>' +
            '<div class="flex-grow-1"><strong>Isian Anda yang belum sempat disimpan telah dipulihkan.</strong> ' +
            'Periksa kembali datanya, lalu klik <strong>Submit</strong>.' +
            '<div class="small mt-1"><i class="fas fa-paperclip me-1"></i>Berkas/dokumen tidak ikut dipulihkan — silakan pilih ulang bila ada.</div></div>';

        const discard = document.createElement('button');
        discard.type = 'button';
        discard.className = 'btn btn-sm btn-outline-secondary flex-shrink-0';
        discard.innerHTML = '<i class="fas fa-xmark me-1"></i>Buang draf';
        discard.addEventListener('click', function() {
            window.__profileDiscardLocalDraft();
            if (typeof window.__profileClearDirty === 'function') window.__profileClearDirty();
            // Route through the server so a session draft, if any, goes with it.
            window.location.href = 'profile.php?discard_draft=1';
        });
        notice.appendChild(discard);

        form.parentNode.insertBefore(notice, form);
    }

    let saveTimer = null;
    function persist() {
        if (discarded) return;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ savedAt: Date.now(), values: collect() }));
        } catch (e) { /* quota / private mode — autosave is best-effort */ }
    }

    function scheduleSave() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(persist, 400);
    }

    form.addEventListener('input', scheduleSave);
    form.addEventListener('change', scheduleSave);
    window.addEventListener('pagehide', function() {
        clearTimeout(saveTimer);
        persist();
    });
});
</script>
<?php endif; ?>

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

<?php if ($docs_locked && !$profile_locked): ?>
<script>
// Documents read-only (client side): the staff editor may change profile fields
// but not legal documents — Sourcing's half of the override. Only the Dokumen
// Legal pane is frozen; Informasi Umum stays fully editable. The real guard is
// server-side in profile_action.php; this just keeps the UI honest.
document.addEventListener('DOMContentLoaded', function () {
    var pane = document.getElementById('docs');
    if (!pane) return;
    // Disable the OCR number fields and the file pickers. Hidden inputs (the
    // *_status companions) are left alone so they still post their 'saved'
    // marker instead of silently resetting a verification badge.
    pane.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (el.type === 'hidden') return;
        el.disabled = true;
    });
    // Kill the replace / delete / clear affordances on saved documents; "View"
    // is a plain link and stays. Buttons inside the pane are all doc controls.
    pane.querySelectorAll('button').forEach(function (b) {
        b.disabled = true;
        b.classList.add('d-none');
    });
    // The upload labels are <label for="...">, which stay clickable even when
    // their input is disabled — grey them out and stop the click.
    pane.querySelectorAll('.custom-file-upload-label').forEach(function (l) {
        l.classList.add('disabled', 'bg-light', 'text-muted');
        l.style.pointerEvents = 'none';
    });
});
</script>
<?php endif; ?>

<?php if ($profile_locked): ?>
<script>
// Profile lock (client side): make every field read-only while the profile is
// in the PQ/penilaian pipeline. The real guard is server-side in
// profile_action.php — this only prevents accidental edits in the UI.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('profileForm');
    if (!form) return;
    // Disable data inputs (skip hidden fields and tab-switch buttons).
    form.querySelectorAll('input, select, textarea').forEach(function (el) {
        if (el.type === 'hidden') return;
        el.disabled = true;
    });
    // Neutralise any submit buttons and the "save before leaving" button.
    form.querySelectorAll('button[name="update_profile"], button[type="submit"]').forEach(function (b) {
        b.disabled = true;
        b.style.display = 'none';
    });
    var leaveBtn = document.getElementById('saveBeforeLeaveButton');
    if (leaveBtn) leaveBtn.style.display = 'none';

    // Disabling the <input type="file"> above does NOT stop its associated
    // <label for="..."> from still opening the file picker, nor does it touch
    // the Ganti/Hapus buttons next to each saved document — both are separate
    // elements the vendor could otherwise still act on even though the actual
    // save/delete is rejected server-side (profile_action.php). Same fix as
    // the Sourcing-override docs lock below, applied to the whole form.
    form.querySelectorAll('.doc-row button').forEach(function (b) {
        b.disabled = true;
        b.classList.add('d-none');
    });
    form.querySelectorAll('.doc-row .custom-file-upload-label').forEach(function (l) {
        l.classList.add('disabled', 'bg-light', 'text-muted');
        l.style.pointerEvents = 'none';
    });
});
</script>
<?php endif; ?>

<script>
// Live warning for required free-text fields (director/contact/PIC names,
// bank details — anything with class js-no-placeholder): flags a value the
// moment it has no letter/digit (e.g. "-", ".", "--") instead of only
// catching it on submit. Mirrors the "no real content" rule enforced
// server-side in profile_action.php (vendor_is_blank_placeholder /
// vendor_has_invalid_name_characters) so the two never disagree about what
// counts as filled. Email fields are excluded — type="email" already gets
// its own browser-native format validation.
document.addEventListener('DOMContentLoaded', function () {
    var HAS_CONTENT = /[a-zA-Z0-9]/;
    // Letters, digits, spaces and the punctuation real company/bank names use.
    // Keeps out keyboard-mash junk like "@bca-" that HAS_CONTENT alone misses.
    var VALID_CHARS = /^[A-Za-z0-9\s.,'&/()-]*$/;
    // Catches "-word" / "word-" (e.g. "-sadad", "sda -") — real names don't
    // start or end with a bare hyphen, even though "-" is allowed mid-string.
    var EDGE_DASH = /^-|-$/;
    document.querySelectorAll('.js-no-placeholder').forEach(function (input) {
        var feedback = input.parentElement.querySelector('.invalid-feedback');
        function check() {
            var value = input.value.trim();
            var message = '';
            if (value !== '' && !HAS_CONTENT.test(value)) {
                message = 'Isi dengan data yang valid, bukan tanda seperti "' + value + '".';
            } else if (value !== '' && !VALID_CHARS.test(value)) {
                message = 'Isi tidak boleh mengandung simbol seperti "@", "#", dsb.';
            } else if (value !== '' && EDGE_DASH.test(value)) {
                message = 'Isi tidak boleh diawali/diakhiri tanda hubung "-".';
            }
            if (message) {
                input.setCustomValidity(message);
                input.classList.add('is-invalid');
                if (feedback) feedback.textContent = message;
            } else {
                input.setCustomValidity('');
                input.classList.remove('is-invalid');
                if (feedback) feedback.textContent = '';
            }
        }
        input.addEventListener('input', check);
        check();
    });
});

// Jumlah Karyawan: keep the field digits-only and re-insert "." thousand
// separators as the vendor types. The server strips the separators again before
// storing, so the posted value does not have to be clean. The caret is kept in
// place by counting digits, not characters, either side of it.
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('number_of_employees');
    if (!el) return;

    function reformat() {
        var digitsBeforeCaret = el.value.slice(0, el.selectionStart || 0).replace(/\D/g, '').length;
        var digits = el.value.replace(/\D/g, '').replace(/^0+(?=\d)/, '');
        var formatted = digits === '' ? '' : Number(digits).toLocaleString('id-ID');
        el.value = formatted;

        var pos = 0, seen = 0;
        while (pos < formatted.length && seen < digitsBeforeCaret) {
            if (/\d/.test(formatted.charAt(pos))) seen++;
            pos++;
        }
        try { el.setSelectionRange(pos, pos); } catch (e) {}
    }

    el.addEventListener('input', reformat);
    reformat();
});
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
