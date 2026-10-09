<?php
// modules/vendormgt/exception_request_review.php
// VM Head reviews a Sourcing-submitted Exception Request and approves/rejects it.
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/profile_helper.php';
require_once $path_to_root . 'includes/exception_request_helper.php';

// Security: VM Head only
if (!isset($_SESSION['user_id'])) {
    // Not logged in (e.g. arriving from the email deep-link): send to login, then
    // return to this exact review page after a successful login.
    $back = rawurlencode($_SERVER['REQUEST_URI'] ?? '');
    header("Location: " . $path_to_root . "login.php" . ($back !== '' ? "?redirect={$back}" : ""));
    exit();
}
if ($_SESSION['role'] !== 'vendormgt_head') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

$can_decide_er = canDecideExceptionRequest((int)$_SESSION['user_id']);

$vendor_id = intval($_GET['id'] ?? 0);
if (!$vendor_id) {
    header("Location: dashboard.php");
    exit();
}

// Fetch vendor.
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    die("Vendor tidak ditemukan.");
}

// Fetch every Exception Request submit/review cycle for this vendor (newest first).
// The most recent row is the current cycle; any earlier rows are history
// (e.g. previous rejections that led to a re-submission).
$erStmt = $conn->prepare("
    SELECT err.*, su.full_name AS submitted_by_name, su.email AS submitted_by_email,
           ru.full_name AS reviewed_by_name
    FROM exception_requests err
    LEFT JOIN users su ON err.submitted_by = su.id
    LEFT JOIN users ru ON err.reviewed_by = ru.id
    WHERE err.vendor_id = ?
    ORDER BY err.id DESC
");
$erStmt->bind_param("i", $vendor_id);
$erStmt->execute();
$er_requests = $erStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$er_current  = $er_requests[0] ?? null;
$er_history  = array_slice($er_requests, 1);

$er_status   = $er_current['status'] ?? 'none';
$is_pending  = ($er_status === 'under_review_vm_head');
$profile     = isVendorProfileComplete($vendor);

// Beban ER si sourcing pengaju: vendor dengan ER aktif yang PQ-nya belum selesai
// (termasuk vendor yang sedang direview ini) — konteks keputusan untuk VM Head.
$sourcing_er_load = !empty($er_current['submitted_by'])
    ? getExceptionRequestPqDebt($conn, (int)$er_current['submitted_by'])
    : null;

// Exception Request status badge (used in the submission + decision sections below)
$er_badge = [
    'under_review_vm_head' => ['bg-dark text-warning', 'Menunggu Persetujuan'],
    'approved'             => ['bg-success', 'Disetujui'],
    'rejected'             => ['bg-danger', 'Ditolak'],
    'none'                 => ['bg-secondary', '-'],
][$er_status] ?? ['bg-secondary', '-'];

$page_title = "Review Exception Request: " . $vendor['company_name'];
include $path_to_root . 'includes/header.php';

// Helper function to render a document row (mirrors view_profile.php)
function renderDocRow($label, $filePath, $rootPath, $ocrNumber = null, $ocrStatus = 'empty') {
    echo '<div class="d-flex justify-content-between align-items-center py-3 border-bottom border-secondary border-opacity-10">';
    echo '  <div>';
    echo '      <div class="fw-bold text-dark small mb-1"><i class="fas fa-file-alt text-primary me-2"></i>' . $label . '</div>';

    if ($ocrNumber !== null) {
        if (!empty($ocrNumber)) {
            echo '<div class="small text-muted mb-1">Extracted ID: <strong class="text-dark">' . htmlspecialchars($ocrNumber) . '</strong></div>';
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
        echo '  <a href="' . $rootPath . '../uploads/' . htmlspecialchars($filePath) . '" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm flex-shrink-0" style="font-size: 0.7rem;"><i class="fas fa-external-link-alt me-1"></i> View</a>';
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
    .er-decision-col {
        position: static;
    }
    @media (min-width: 992px) {
        .er-decision-col {
            position: sticky;
            top: 84px;
            align-self: flex-start;
        }
    }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Review Exception Request
        </span>
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5" style="max-width: 1200px;">

    <!-- TOP HEADER CARD -->
    <div class="profile-header p-4 p-md-5 mb-4 shadow-sm border border-light">
        <div class="profile-header-content d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h2 class="fw-bold text-dark mb-1"><?= htmlspecialchars($vendor['company_name']) ?></h2>
                <div class="text-muted d-flex align-items-center flex-wrap gap-3 mt-2">
                    <span><i class="fas fa-briefcase me-1"></i> <?= htmlspecialchars($vendor['company_type']) ?></span>
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

    <!-- EXCEPTION REQUEST SUBMISSION DETAILS (directly below vendor banner) -->
    <div class="card er-card border-0 mb-4">
        <div class="card-body" style="padding: 1.75rem 2rem;">
            <h5 class="section-title mb-3"><i class="fas fa-bolt"></i> Exception Request Submission Details</h5>

            <div class="alert alert-warning border-0 small mb-3 py-2">
                <i class="fas fa-info-circle me-2"></i>Note: The Pre-Qualification (PQ) process has not been completed for this vendor.
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="field-label text-muted d-block text-uppercase mb-1">Submitted By (Sourcing)</label>
                    <div class="fw-bold text-dark"><i class="fas fa-user-tie text-secondary me-1"></i> <?= htmlspecialchars($er_current['submitted_by_name'] ?? '-') ?></div>
                    <div class="small text-muted"><i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($er_current['submitted_by_email'] ?? '-') ?></div>
                    <?php if ($sourcing_er_load !== null): ?>
                    <div class="small mt-2">
                        <span class="badge <?= $sourcing_er_load['full'] ? 'bg-danger' : 'bg-warning text-dark' ?>">
                            <i class="fas fa-bolt me-1"></i>
                            Sourcing ini memiliki <?= $sourcing_er_load['count'] ?> vendor Exception Request aktif (PQ belum selesai)
                        </span>
                        <?php if ($sourcing_er_load['count'] > 0): ?>
                        <div class="text-muted mt-1" style="font-size:0.75rem;">
                            <?= htmlspecialchars(implode(', ', array_column($sourcing_er_load['vendors'], 'company_name'))) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="field-label text-muted d-block text-uppercase mb-1">Submission Time</label>
                    <div class="fw-bold text-dark"><i class="fas fa-clock text-secondary me-1"></i> <?= !empty($er_current['submitted_at']) ? date('d M Y H:i', strtotime($er_current['submitted_at'])) : '-' ?></div>
                </div>
            </div>

            <div class="mb-3">
                <label class="field-label text-muted d-block text-uppercase mb-1">Kondisi Vendor</label>
                <div class="p-2 px-3 bg-light rounded text-secondary small" style="line-height:1.6;"><?= htmlspecialchars($er_current['reason'] ?? '-') ?></div>
            </div>

            <div class="mb-3">
                <label class="field-label text-muted d-block text-uppercase mb-1">Sourcing Notes</label>
                <div class="p-2 px-3 bg-light rounded text-secondary small" style="line-height:1.6;"><?= nl2br(htmlspecialchars(($er_current['submitted_notes'] ?? '') ?: '-')) ?></div>
            </div>

            <?php
                $er_rejections = array_filter($er_history, fn($r) => $r['status'] === 'rejected');
            ?>
            <?php if (!empty($er_rejections)): ?>
            <div class="mb-3">
                <label class="field-label text-danger d-block text-uppercase mb-1"><i class="fas fa-history me-1"></i> Previous VM Head Rejection History</label>
                <div class="p-2 px-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded text-secondary small" style="line-height:1.6; max-height: 220px; overflow-y: auto;">
                    <?php foreach ($er_rejections as $r): ?>
                        <div class="mb-2 pb-2 border-bottom border-danger border-opacity-25">
                            <div class="fw-bold"><?= !empty($r['reviewed_at']) ? date('d M Y H:i', strtotime($r['reviewed_at'])) : '-' ?> — <?= htmlspecialchars($r['reviewed_by_name'] ?? 'VM Head') ?></div>
                            <div style="white-space: pre-line;"><?= htmlspecialchars($r['review_notes'] ?? '') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Profile completeness -->
            <?php if ($profile['complete']): ?>
                <div class="alert alert-success border-0 small mb-0 py-2"><i class="fas fa-check-circle me-2"></i>Vendor profile is <strong>complete</strong>. All required documents have been uploaded.</div>
            <?php else: ?>
                <div class="alert alert-danger border-0 small mb-0 py-2">
                    <i class="fas fa-exclamation-triangle me-2"></i>Vendor profile is <strong>incomplete</strong>:
                    <ul class="mb-0 mt-2"><?php foreach ($profile['missing'] as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4">
        <!-- MAIN COLUMN: CORPORATE INFO + DOCUMENTS -->
        <div class="col-lg-8">
            <div class="row g-4">
                <!-- GENERAL INFO -->
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
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['contact_person'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Phone Number</label>
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-phone text-secondary me-1"></i> <?= htmlspecialchars($vendor['phone'] ?? '-') ?></div>
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
                        <div class="fw-bold text-primary"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email'] ?? '-') ?></div>
                    </div>

                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Company Address <span class="text-muted fw-normal text-lowercase">(from NPWP)</span></label>
                        <div class="fw-bold text-dark" style="line-height:1.5;"><i class="fas fa-map-marker-alt text-secondary me-1"></i> <?= !empty($vendor['company_address']) ? nl2br(htmlspecialchars($vendor['company_address'])) : '-' ?></div>
                    </div>

                    <hr class="my-4 border-light">

                    <h5 class="section-title"><i class="fas fa-university"></i> Banking Details</h5>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Bank Name</label>
                            <div class="fw-bold text-dark"><i class="fas fa-university text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Bank Branch</label>
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

                <!-- DOCUMENTS -->
                <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4 p-md-5">
                    <h5 class="section-title"><i class="fas fa-folder-open"></i> Legal Documents</h5>

                    <div class="d-flex flex-column gap-1">
                        <?php
                        $entity_type = $vendor['legal_entity_type'] ?? '';
                        $comp_type   = strtolower($vendor['company_type'] ?? '');

                        // 1. Dokumen Dasar (semua vendor)
                        renderDocRow("KTP Direktur / Pemilik", $vendor['director_id_card_file'] ?? '', $path_to_root, $vendor['director_id_number'] ?? '', $vendor['director_id_status'] ?? '');
                        renderDocRow("NPWP (Tax ID)", $vendor['npwp_path'] ?? '', $path_to_root, $vendor['npwp_number'] ?? '', $vendor['npwp_status'] ?? '');
                        renderDocRow("Halaman Depan Buku Rekening", $vendor['bank_cover_path'] ?? '', $path_to_root, $vendor['bank_account_number'] ?? '', $vendor['bank_account_status'] ?? '');
                        renderDocRow("Surat Pernyataan Perbedaan Nama Rekening", $vendor['bank_holder_statement_letter_file'] ?? '', $path_to_root);
                        renderDocRow("Pakta Integritas Kawan Lama Group", $vendor['business_ethics_commitment_file'] ?? '', $path_to_root);
                        renderDocRow("Tax Statement Letter", $vendor['tax_statement_letter_file'] ?? '', $path_to_root);
                        renderDocRow("Profil Perusahaan / Brosur", $vendor['company_profile_file'] ?? '', $path_to_root);

                        // 2. Dokumen Khusus Badan Usaha
                        if ($entity_type === 'Badan Usaha') {
                            echo '<div class="mt-3 mb-2"><span class="badge bg-primary bg-opacity-10 text-primary border border-primary border-opacity-25 rounded-pill px-3 py-1" style="font-size:0.7rem;"><i class="fas fa-building me-1"></i> Business Entity Documents</span></div>';
                            renderDocRow("Dokumen NIB", $vendor['nib_path'] ?? '', $path_to_root, $vendor['nib_number'] ?? '', $vendor['nib_status'] ?? '');
                            renderDocRow("Akta Pendirian", $vendor['deed_of_establishment_file'] ?? '', $path_to_root, $vendor['deed_of_establishment_number'] ?? '', $vendor['deed_of_establishment_status'] ?? '');
                            renderDocRow("SK Pengesahan Badan Hukum", $vendor['decree_of_legal_entity_file'] ?? '', $path_to_root, $vendor['decree_of_legal_entity_number'] ?? '', $vendor['decree_of_legal_entity_status'] ?? '');
                            renderDocRow("Akta Perubahan", $vendor['deed_of_amendment_file'] ?? '', $path_to_root);
                            renderDocRow("SPPKP", $vendor['sppkp_path'] ?? '', $path_to_root);
                        }

                        // 3. Dokumen Khusus Kontraktor
                        if (strpos($comp_type, 'contractor') !== false || strpos($comp_type, 'kontraktor') !== false) {
                            echo '<div class="mt-3 mb-2"><span class="badge bg-warning bg-opacity-10 text-warning border border-warning border-opacity-25 rounded-pill px-3 py-1" style="font-size:0.7rem;"><i class="fas fa-hard-hat me-1"></i> Contractor Documents</span></div>';
                            renderDocRow("Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)", $vendor['business_entity_cert_file'] ?? '', $path_to_root);
                        }
                        ?>
                    </div>
                </div>
            </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: DECISION (floating) -->
        <div class="col-lg-4 er-decision-col">
    <div class="card er-card border-0">
        <div class="card-body" style="padding: 1.75rem 2rem;">
            <h5 class="section-title mb-3"><i class="fas fa-gavel"></i> VM Head Decision</h5>

            <?php if ($is_pending && $can_decide_er): ?>
                <form method="POST" action="exception_request_action.php" id="erDecisionForm">
                    <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
                    <div class="mb-3">
                        <label class="form-label fw-bold small mb-2">VM Head Notes</label>
                        <textarea name="vm_notes" id="vmNotes" class="form-control" rows="3"
                                  placeholder="Decision notes (required if rejecting)..."></textarea>
                        <div id="vmNotesError" class="d-none align-items-center text-danger small mt-2">
                            <i class="fas fa-exclamation-circle me-1"></i> Notes are required when rejecting the submission.
                        </div>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-success fw-bold"
                                data-bs-toggle="modal" data-bs-target="#erApproveModal">
                            <i class="fas fa-check me-1"></i> Approve Exception Request
                        </button>
                        <button type="button" class="btn btn-outline-danger fw-bold"
                                data-bs-toggle="modal" data-bs-target="#erRejectModal">
                            <i class="fas fa-times me-1"></i> Reject
                        </button>
                    </div>
                </form>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var rejectBtn = document.getElementById('erRejectConfirm');
                    var notes     = document.getElementById('vmNotes');
                    var errBox    = document.getElementById('vmNotesError');
                    if (!rejectBtn || !notes || !errBox) return;

                    // Block reject submit when the note is empty: stay on the review page,
                    // pop an alert, close the confirm dialog, and highlight the notes field.
                    rejectBtn.addEventListener('click', function (e) {
                        if (notes.value.trim() === '') {
                            e.preventDefault();
                            var modalEl = document.getElementById('erRejectModal');
                            var instance = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
                            if (instance) instance.hide();
                            errBox.classList.remove('d-none');
                            errBox.classList.add('d-flex');
                            notes.classList.add('is-invalid');
                            var toastEl = document.getElementById('vmNotesToast');
                            if (toastEl && window.bootstrap && bootstrap.Toast) {
                                bootstrap.Toast.getOrCreateInstance(toastEl, { delay: 4000 }).show();
                            } else {
                                alert('Catatan penolakan wajib diisi terlebih dahulu.');
                            }
                            notes.focus();
                        }
                    });

                    // Clear the feedback as soon as the user starts typing.
                    notes.addEventListener('input', function () {
                        errBox.classList.add('d-none');
                        errBox.classList.remove('d-flex');
                        notes.classList.remove('is-invalid');
                    });
                });
                </script>
            <?php elseif ($is_pending && !$can_decide_er): ?>
                <div class="alert alert-secondary border-0 mb-0 py-2 small">
                    <i class="fas fa-lock me-1"></i>
                    Akun Anda tidak memiliki izin untuk memutuskan Exception Request ini. Silakan hubungi Administrator untuk bantuan.
                </div>
            <?php else: ?>
                <div class="alert alert-secondary border-0 mb-0 py-2 small">
                    This submission has already been processed (<strong><?= htmlspecialchars($er_badge[1]) ?></strong>).
                    <?php if (!empty($er_current['review_notes'])): ?>
                        <div class="mt-2"><strong>VM Head Notes:</strong> <?= nl2br(htmlspecialchars($er_current['review_notes'])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
        </div>
    </div>

</div>

<?php if ($is_pending && $can_decide_er): ?>

<!-- Confirm: Reject -->
<div class="modal fade" id="erRejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 14px;">
            <div class="modal-body text-center p-4">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-danger bg-opacity-10 text-danger mb-3" style="width:56px;height:56px;">
                    <i class="fas fa-times-circle fa-2x"></i>
                </div>
                <h6 class="fw-bold text-dark mb-2">Reject Exception Request Submission?</h6>
                <p class="text-secondary small mb-4">Make sure you have filled in the <strong>reason notes</strong> for rejection. This action will return the process to the normal (PQ) track.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="reject" id="erRejectConfirm" form="erDecisionForm" class="btn btn-danger fw-bold px-4">
                        <i class="fas fa-times me-1"></i> Yes, Reject
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Confirm: Approve -->
<div class="modal fade" id="erApproveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow" style="border-radius: 14px;">
            <div class="modal-body text-center p-4">
                <div class="d-inline-flex align-items-center justify-content-center rounded-circle bg-success bg-opacity-10 text-success mb-3" style="width:56px;height:56px;">
                    <i class="fas fa-check-circle fa-2x"></i>
                </div>
                <h6 class="fw-bold text-dark mb-2">Approve Exception Request?</h6>
                <p class="text-secondary small mb-4">Vendor akan dapat didaftarkan ke K2 <strong>tanpa menunggu PQ</strong>. Pastikan semua dokumen telah ditinjau.</p>
                <div class="d-flex gap-2 justify-content-center">
                    <button type="button" class="btn btn-light fw-bold px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="action" value="approve" form="erDecisionForm" class="btn btn-success fw-bold px-4">
                        <i class="fas fa-check me-1"></i> Yes, Approve
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast: rejection notes required -->
<div class="toast-container position-fixed top-0 start-50 translate-middle-x p-3" style="z-index: 1090;">
    <div id="vmNotesToast" class="toast border-0 shadow-lg" role="alert" aria-live="assertive" aria-atomic="true"
         style="background:#dc3545; color:#fff; border-radius:12px;">
        <div class="d-flex align-items-center">
            <div class="toast-body d-flex align-items-center gap-2 fw-semibold">
                <i class="fas fa-exclamation-circle fa-lg"></i>
                <span>Catatan penolakan wajib diisi terlebih dahulu.</span>
            </div>
            <button type="button" class="btn-close btn-close-white me-3 ms-auto" data-bs-dismiss="toast" aria-label="Close"></button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include $path_to_root . 'includes/footer.php'; ?>
