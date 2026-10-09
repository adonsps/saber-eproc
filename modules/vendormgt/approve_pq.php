<?php
// modules/vendormgt/approve_pq.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/pq_risk_helper.php';
require_once $path_to_root . 'includes/pq_calc_details.php';
require_once $path_to_root . 'includes/vendor_business_model.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['vendor_mgt', 'vendormgt_head'])) {
    header("Location: ../../login.php");
    exit();
}

$is_vm_head = ($_SESSION['role'] === 'vendormgt_head');

// --- ID RESOLVER ---
$vendor_id = 0;
$sub_id = 0;

if (isset($_GET['submission_id'])) {
    $sub_id = (int)$_GET['submission_id'];
    $stmt_find = $conn->prepare("SELECT vendor_id FROM pq_submissions WHERE id = ?");
    $stmt_find->bind_param("i", $sub_id);
    $stmt_find->execute();
    if ($row_find = $stmt_find->get_result()->fetch_assoc()) {
        $vendor_id = $row_find['vendor_id'];
    }
} elseif (isset($_GET['id'])) {
    $vendor_id = (int)$_GET['id'];
}

if ($vendor_id == 0) die("Error: No Vendor ID provided.");

// 2. Fetch Vendor Profile
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
if (!$vendor) die("Vendor not found.");

// Direct Registration: Sourcing filled/submitted this on the vendor's behalf
// (see modules/sourcing/direct_registration*.php) rather than the vendor
// registering and submitting themselves — VM should see that distinction.
$direct_registered_by_name = null;
if (!empty($vendor['registered_by_sourcing_id'])) {
    $drStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
    $drStmt->bind_param("i", $vendor['registered_by_sourcing_id']);
    $drStmt->execute();
    $drRow = $drStmt->get_result()->fetch_assoc();
    $direct_registered_by_name = $drRow['full_name'] ?? 'Sourcing';
}

// 3. Fetch Submission Details
if ($sub_id == 0) {
    $stmt_sub = $conn->prepare("SELECT * FROM pq_submissions WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_sub->bind_param("i", $vendor_id);
    $stmt_sub->execute();
    $submission = $stmt_sub->get_result()->fetch_assoc();
    $sub_id = $submission['id'] ?? 0;
} else {
    $stmt_sub = $conn->prepare("SELECT * FROM pq_submissions WHERE id = ?");
    $stmt_sub->bind_param("i", $sub_id);
    $stmt_sub->execute();
    $submission = $stmt_sub->get_result()->fetch_assoc();
}

if (!$submission) die("No submission found.");


// --- APPLY NEW 5-TIER RISK LOGIC FOR INITIAL LOAD (satu sumber di includes/pq_risk_helper.php) ---
$max_score = (float)$submission['max_possible_weighted_score'];
$total_score = (float)$submission['total_weighted_score'];
$risk_percentage = pqRiskPercentage($total_score, $max_score);
$risk_category = pqRiskCategory($risk_percentage);

// Override submission array with newly calculated values for display
$submission['risk_percentage'] = $risk_percentage;
$submission['risk_category'] = $risk_category;


// 4. Fetch PQ Answers (Dynamically pulling weights, options, and flags)
$current_sub = $vendor['sub_company_type'] ?? '';
$main_type = $vendor['company_type'];
// The standalone Perseorangan set is keyed by 'Perseorangan' on BOTH
// variant_target and sub_variant_target. Sourcing already pinned
// sub_company_type there for every vendor on that set — company_type =
// 'Perseorangan' and self-declared UMKM vendors alike — so read it from the
// sub-category rather than from company_type, which stays Supplier/Contractor
// for UMKM.
[$like_main_type, $pq_weight_target] = pqScoringVariants($main_type, $current_sub);
$like_type = '%' . $like_main_type . '%';

// FETCH ALL NEEDED COLUMNS (Including Reviewer Name & ID for Sourcing Badge)
$pq_sql = "SELECT 
            q.id as question_id,
            a.selected_score,
            a.remarks,
            a.calc_details,
            a.attachment_path,
            a.needs_revision,
            a.reviewer_notes,
            a.vm_row_notes,
            a.vm_needs_revision,
            a.reviewer_id,
            u.full_name as reviewer_name,
            q.question_text, 
            q.variant_target,
            (SELECT weight FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1) as weight,
            COALESCE((SELECT help_text FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1), q.help_text) as help_text,
            (SELECT GROUP_CONCAT(sub_variant_target SEPARATOR ',') FROM pq_weight WHERE question_id = q.id) as all_sub_targets,
            sc.section_name 
           FROM pq_questions q 
           LEFT JOIN pq_answers a ON q.id = a.question_id AND a.submission_id = ?
           LEFT JOIN pq_sections sc ON q.section_id = sc.id
           LEFT JOIN users u ON a.reviewer_id = u.id
           WHERE (q.variant_target IS NULL OR q.variant_target = '' OR q.variant_target LIKE ?)
           ORDER BY sc.sort_order, q.id ASC";

$stmt_pq = $conn->prepare($pq_sql);
$stmt_pq->bind_param("ssis", $pq_weight_target, $pq_weight_target, $sub_id, $like_type);
$stmt_pq->execute();
$pq_result = $stmt_pq->get_result();

// Group answers by section
$review_data = [];
while ($row = $pq_result->fetch_assoc()) {
    $is_applicable = false;
    $sv_targets = $row['all_sub_targets'];

    if (empty($sv_targets) || (!empty($pq_weight_target) && stripos($sv_targets, $pq_weight_target) !== false)) {
        $is_applicable = true;
    }
    
    // Only add it to the view if it actually applies to this vendor
    if ($is_applicable) {
        $sec = $row['section_name'] ?? 'General Questions';
        $review_data[$sec][] = $row;
    }
}

// Editable only for the correct role at the correct stage
if ($is_vm_head) {
    $is_editable = ($submission['status'] === 'under_review_vm_head');
} else {
    $is_editable = ($submission['status'] === 'under_review_vm');
}

$page_title = "Approve: " . $vendor['company_name'];
include $path_to_root . 'includes/header.php';

// Helper function for the modal documents
function renderModalDocRow($label, $filePath, $rootPath, $ocrNumber = null, $ocrStatus = 'empty') {
    $docKey = preg_replace('/[^A-Za-z0-9]/', '_', $label);
    
    echo '<div class="py-3 border-bottom border-secondary border-opacity-10">';
    echo '  <div class="d-flex justify-content-between align-items-center">';
    echo '      <div>';
    echo '          <div class="fw-bold text-dark small mb-1"><i class="fas fa-file-alt text-primary me-2"></i>' . $label . '</div>';
    
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
    echo '      </div>';
    
    if (!empty($filePath)) {
        echo '      <a href="' . $rootPath . '../uploads/' . htmlspecialchars($filePath) . '" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm flex-shrink-0" style="font-size: 0.7rem;"><i class="fas fa-external-link-alt me-1"></i> View</a>';
    } else {
        echo '      <span class="badge bg-light text-muted border fst-italic flex-shrink-0" style="font-size: 0.65rem;">Not Uploaded</span>';
    }
    echo '  </div>';

    // Read-only sourcing notes display (populated by initLegalNotes JS)
    echo '  <div id="readonly_note_'.$docKey.'" class="d-none mt-2 p-2 bg-danger bg-opacity-10 rounded border border-danger border-opacity-25 small" data-doc="'.htmlspecialchars($label).'">';
    echo '      <strong class="text-danger d-block mb-1" style="font-size: 0.65rem;"><i class="fas fa-exclamation-triangle me-1"></i> Catatan Revisi Sourcing:</strong>';
    echo '      <span class="text-dark note-text-display"></span>';
    echo '  </div>';
    echo '</div>';
}
?>

<style>
    body { background-color: #f4f7f6; }
    
    .metric-card { transition: transform 0.2s, box-shadow 0.2s; border-radius: 12px; overflow: hidden; position: relative; }
    .metric-icon { position: absolute; right: 15px; bottom: 10px; font-size: 4rem; opacity: 0.15; z-index: 0; }
    .metric-content { position: relative; z-index: 1; }
    
    .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #198754 0%, #0f5132 100%); }
    .bg-gradient-info    { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
    .bg-gradient-orange  { background: linear-gradient(135deg, #fd7e14 0%, #cc5f00 100%); }
    .bg-gradient-danger  { background: linear-gradient(135deg, #dc3545 0%, #842029 100%); }
    
    .hover-scale { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; }
    .hover-scale:hover { transform: scale(1.03); box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }

    /* Filled, polished style for "Return to Vendor" (was a flat outline button) */
    .btn-return-vendor {
        background: linear-gradient(135deg, #f0ad4e 0%, #e08e0b 100%);
        border: none;
        color: #fff;
        box-shadow: 0 3px 8px rgba(224, 142, 11, 0.30);
        transition: transform .2s ease, box-shadow .2s ease;
    }
    .btn-return-vendor:hover,
    .btn-return-vendor:focus {
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 6px 14px rgba(224, 142, 11, 0.45);
    }

    /* --- "VM Notes Required" popup polish --- */
    .notes-required-icon {
        width: 84px;
        height: 84px;
        margin: 0 auto;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #fff;
        font-size: 2rem;
        background: linear-gradient(135deg, #fd7e14 0%, #cc5f00 100%);
        box-shadow: 0 8px 20px rgba(253, 126, 20, 0.35);
        position: relative;
        animation: notesIconPop .35s ease-out;
    }
    .notes-required-icon::after {
        content: "";
        position: absolute;
        inset: -6px;
        border-radius: 50%;
        border: 3px solid rgba(253, 126, 20, 0.35);
        animation: notesIconPulse 1.8s ease-out infinite;
    }
    @keyframes notesIconPop {
        0%   { transform: scale(0.4); opacity: 0; }
        70%  { transform: scale(1.08); opacity: 1; }
        100% { transform: scale(1); }
    }
    @keyframes notesIconPulse {
        0%   { transform: scale(1);   opacity: .7; }
        100% { transform: scale(1.4); opacity: 0; }
    }
    #notesRequiredModal .modal-content { border-radius: 1.25rem; }
    #notesRequiredModal .btn-notes-ok {
        background: linear-gradient(135deg, #fd7e14 0%, #cc5f00 100%);
        border: none;
        color: #fff;
        box-shadow: 0 4px 12px rgba(253, 126, 20, 0.35);
        transition: transform .2s ease, box-shadow .2s ease;
    }
    #notesRequiredModal .btn-notes-ok:hover {
        color: #fff;
        transform: translateY(-2px);
        box-shadow: 0 7px 16px rgba(253, 126, 20, 0.5);
    }

    .note-card {
        background: linear-gradient(to right, #ffffff, #f8f9fc);
        border-left: 5px solid #0dcaf0 !important;
        border-radius: 12px;
        position: relative;
    }
    .note-card::before {
        content: "\f10d";
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        position: absolute;
        top: 15px;
        right: 20px;
        font-size: 3rem;
        color: rgba(13, 202, 240, 0.1);
    }
    
    .company-title-badge {
        display: inline-flex;
        align-items: center;
        background: linear-gradient(135deg, #dc3545 0%, #a5202e 100%);
        color: white;
        padding: 0.5rem 1.5rem;
        border-radius: 50rem; 
        box-shadow: 0 4px 10px rgba(220, 53, 69, 0.3);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .company-title-badge:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(220, 53, 69, 0.4);
    }
    .company-icon-circle {
        background: rgba(255,255,255,0.2);
        border-radius: 50%;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
    }

    /* VM Row Highlighting Style */
    .vm-flag-checkbox { display: none; }
    .vm-flag-label { cursor: pointer; color: #ccc; font-size: 1.2rem; transition: color 0.2s; }
    .vm-flag-label:hover { color: #ffc107; }
    .vm-flag-checkbox:checked + .vm-flag-label { color: #ffc107; }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>PQ Approval
        </span>
        <div class="d-flex align-items-center">
            <span class="me-3 small nav-muted">Status: <strong class="text-uppercase"><?= str_replace('_', ' ', $submission['status']) ?></strong></span>
            <a href="javascript:window.history.back()" class="btn btn-sm btn-nav rounded-pill px-3">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4 pb-5">
    
    <!-- VISUALIZATION -->
    <div class="row mb-4 g-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-gradient-success metric-card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Total Score</h6>
                    <h1 class="display-4 fw-bold mb-0 text-white"><?= number_format((float)$submission['total_weighted_score'], 2) ?></h1>
                    <div class="small text-white-50 mt-1">out of <?= (float)$submission['max_possible_weighted_score'] ?></div>
                </div>
                <i class="fas fa-star metric-icon text-white"></i>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-dark metric-card" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Risk Percentage</h6>
                    <h1 class="display-4 fw-bold mb-0 text-white"><?= number_format((float)$submission['risk_percentage'], 1) ?>%</h1>
                    <div class="small text-white-50 mt-1">Calculated Variance</div>
                </div>
                <i class="fas fa-percentage metric-icon text-white"></i>
            </div>
        </div>

        <div class="col-md-4">
            <?php 
                $risk_cat = $submission['risk_category'];
                $risk_bg = 'bg-gradient-danger'; 
                $text_color = 'text-white'; 
                $icon_color = 'text-white'; 
                $icon = 'fa-times-circle';
                
                if ($risk_cat == 'Very Low') { 
                    $risk_bg = 'bg-gradient-success'; $icon = 'fa-shield-alt'; 
                } elseif ($risk_cat == 'Low') { 
                    $risk_bg = 'bg-gradient-info'; $icon = 'fa-thumbs-up'; 
                } elseif ($risk_cat == 'Moderate') { 
                    $risk_bg = 'bg-gradient-warning'; $text_color = 'text-dark'; $icon_color = 'text-dark'; $icon = 'fa-balance-scale'; 
                } elseif ($risk_cat == 'High') {
                    $risk_bg = 'bg-gradient-orange'; $icon = 'fa-exclamation-triangle';
                }
            ?>
            <div class="card h-100 shadow-sm border-0 <?= $risk_bg ?> metric-card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase small fw-bold mb-2 opacity-75 <?= $text_color ?>">Risk Category</h6>
                    <h2 class="display-5 fw-bold mb-0 <?= $text_color ?>"><?= $risk_cat ?></h2>
                    <div class="small opacity-75 mt-1 <?= $text_color ?>">Classification</div>
                </div>
                <i class="fas <?= $icon ?> metric-icon <?= $icon_color ?>"></i>
            </div>
        </div>
    </div>

    <!-- FEEDBACK FROM SOURCING TEAM -->
    <?php if(!empty($submission['sourcing_notes'])): ?>
        <div class="card border-0 shadow-sm mb-4 note-card">
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-info bg-opacity-10 rounded-circle p-2 me-3">
                        <i class="fas fa-user-tie text-info fs-5"></i>
                    </div>
                    <div>
                        <h6 class="mb-0 fw-bold text-dark">Sourcing Team Assessment Summary</h6>
                        <small class="text-muted">General review and recommendations</small>
                    </div>
                </div>
                <div class="p-3 bg-white rounded-3 border border-light shadow-sm text-secondary fst-italic" style="line-height: 1.6;">
                    "<?= nl2br(htmlspecialchars($submission['sourcing_notes'])) ?>"
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- AJAX Success Message -->
    <div id="ajax_success_msg" class="alert alert-success alert-dismissible fade show shadow-sm mb-4 d-none" role="alert">
        <i class="fas fa-check-circle me-2"></i> <span>VM row notes saved successfully.</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <div class="row">
        <div class="col-lg-9">
            <!-- WRAP ROWS IN FORM FOR AJAX SAVE -->
            <form id="vmReviewForm" onsubmit="submitVMRows(event)">
                <input type="hidden" name="submission_id" value="<?= $sub_id ?>">
                <input type="hidden" name="action" value="save_vm_rows">

                <div class="card shadow-sm border-0 mb-4 rounded-4">
                    <div class="card-header bg-white py-4 border-bottom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center">
                            
                            <!-- COMPANY NAME BADGE -->
                            <div class="company-title-badge mb-3 mb-md-0">
                                <div class="company-icon-circle">
                                    <i class="fas fa-building fs-5 text-white"></i>
                                </div>
                                <div>
                                    <h4 class="mb-0 fw-bold text-warning" style="letter-spacing: 0.5px;"><?= htmlspecialchars($vendor['company_name']) ?></h4>
                                    <?php if ($direct_registered_by_name !== null): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle mt-1" style="font-size: 0.7rem;" title="Sourcing mengisi &amp; mengajukan data ini atas nama vendor, tanpa vendor login sendiri.">
                                            <i class="fas fa-user-edit me-1"></i>Pendaftaran Langsung oleh <?= htmlspecialchars($direct_registered_by_name) ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- VENDOR CLASSIFICATION & PROFILE BUTTON -->
                            <div class="d-flex flex-column align-items-md-end gap-2 text-start text-md-end">
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-secondary rounded-pill px-3 py-2 fw-normal shadow-sm">
                                        <i class="fas fa-briefcase me-1 opacity-75"></i> <?= htmlspecialchars($vendor['company_type']) ?>
                                    </span>
                                    
                                    <span class="badge bg-gradient-primary rounded-pill px-3 py-2 shadow-sm border border-primary transition-all hover-scale" style="font-size: 0.8rem; cursor: help;" title="Vendor Sub-Category assigned by Sourcing Team">
                                        <i class="fas fa-sitemap me-1 text-warning"></i> Sourcing Classification: <strong class="ms-1"><?= htmlspecialchars($vendor['sub_company_type'] ?? 'Unclassified') ?></strong>
                                    </span>
                                </div>
                                
                                <span class="text-muted small mt-1">
                                    <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($vendor['contact_person']) ?> &nbsp;&bull;&nbsp; 
                                    <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($vendor['email']) ?>
                                </span>

                                <!--<div class="mt-1">-->
                                <!--    <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm transition-all hover-scale" data-bs-toggle="modal" data-bs-target="#vendorProfileModal">-->
                                <!--        <i class="fas fa-user-circle me-1"></i> View Quick Profile-->
                                <!--    </button>-->
                                <!--</div>-->
                            </div>

                        </div>

                        <?php
                        // The vendor's own Model Bisnis answers from registration. VM and VM
                        // Head see the same raw declaration Sourcing read before classifying,
                        // so they can sanity-check the Sourcing Classification badge above
                        // against what the vendor actually said it does.
                        $bm_labels = business_model_labels($vendor['business_model'] ?? null);
                        ?>
                        <div class="mt-3 p-3 bg-white rounded border border-secondary border-opacity-25">
                            <label class="form-label fw-bold text-secondary small mb-1"><i class="fas fa-clipboard-question me-1"></i> Model Bisnis (dijawab vendor saat registrasi)</label>
                            <?php if (!empty($bm_labels)): ?>
                                <ul class="list-unstyled mb-0 mt-2">
                                    <?php foreach ($bm_labels as $bm_label): ?>
                                        <li class="mb-1 text-dark small">
                                            <i class="fas fa-check-square text-success me-2"></i><?= htmlspecialchars($bm_label) ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="mt-2 pt-2 border-top small text-muted">
                                    <i class="fas fa-arrow-turn-up fa-rotate-90 me-1"></i>
                                    Kategori turunan: <strong class="text-dark"><?= htmlspecialchars($vendor['company_type']) ?></strong>
                                    <?php if (!empty($vendor['is_umkm'])): ?>
                                        &nbsp;<span class="badge bg-warning text-dark"><i class="fas fa-store me-1"></i>UMKM</span>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="small text-muted mt-1">
                                    <i class="fas fa-minus-circle me-1"></i> Vendor ini mendaftar sebelum pertanyaan Model Bisnis ada (atau didaftarkan langsung oleh Sourcing).
                                    <?php if (!empty($vendor['is_umkm'])): ?>
                                        &nbsp;<span class="badge bg-warning text-dark"><i class="fas fa-store me-1"></i>UMKM</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card-body p-0">
                        <?php if (empty($review_data)): ?>
                            <div class="p-5 text-center text-muted">No answers found for this submission.</div>
                        <?php else: ?>
                            <?php foreach ($review_data as $section => $answers): ?>
                                <!-- Section Header -->
                                <div class="bg-light px-3 py-2 border-bottom fw-bold small text-uppercase text-secondary section-header"><?= $section ?></div>
                                
                                <!-- Section Table -->
                                <div class="table-responsive section-table-wrapper">
                                    <table class="table table-bordered table-hover align-middle mb-0">
                                        <thead class="table-light small text-muted text-uppercase">
                                            <tr>
                                                <th class="ps-3" style="width: 30%">Question / Criteria</th>
                                                <th style="width: 20%">Response & Sourcing Info</th>
                                                <th style="width: 22%">Sourcing Notes</th>
                                                <th style="width: 23%">VM Notes <i class="fas fa-edit ms-1 text-warning"></i></th>
                                                <th class="text-center" style="width: 5%">Flag</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($answers as $ans): 
                                                
                                                $q_text_lower = strtolower($ans['question_text']);
                                                $is_financial = (strpos($q_text_lower, 'income') !== false || strpos($q_text_lower, 'pendapatan') !== false || strpos($q_text_lower, 'capital') !== false || strpos($q_text_lower, 'modal') !== false);
                                                $is_legal = (stripos($ans['question_text'], 'legalitas perusahaan') !== false || stripos($ans['question_text'], 'legalitas') !== false);

                                                // Text Extraction for the Dropdown Label
                                                $display_label = "Score: " . ($ans['selected_score'] ?? 0);
                                                if (!$is_financial && $ans['selected_score'] !== null && !empty($ans['help_text'])) {
                                                    preg_match('/\(' . $ans['selected_score'] . '\)\s*(.*?)(?=\(\d+\)|$)/s', $ans['help_text'], $matches);
                                                    if (!empty($matches[1])) {
                                                        $display_label = trim($matches[1]) . " (" . $ans['selected_score'] . ")";
                                                    }
                                                }
                                            ?>
                                                <tr>
                                                    <td class="ps-3">
                                                        <div class="fw-bold small mb-1 text-dark"><?= htmlspecialchars($ans['question_text']) ?></div>
                                                        <div class="small text-muted mt-2" style="font-size:0.75rem;">
                                                            <span class="badge bg-light text-dark border weight-label"><i class="fas fa-balance-scale"></i> Weight: <?= (float)$ans['weight'] ?></span>
                                                        </div>
                                                        <?php if ($is_legal): ?>
                                                            <div class="mt-2">
                                                                <button type="button" class="btn btn-sm btn-light border-primary text-primary fw-bold rounded-pill px-3 shadow-sm hover-scale py-1" style="font-size: 0.65rem; transition: 0.2s;" data-bs-toggle="modal" data-bs-target="#vendorProfileModal" onclick="initLegalNotes(<?= $ans['question_id'] ?>)">
                                                                    <i class="fas fa-external-link-alt me-1"></i> PREVIEW LEGAL DOCS
                                                                </button>
                                                            </div>
                                                            <textarea id="legal_notes_data_<?= $ans['question_id'] ?>" class="d-none"><?= htmlspecialchars($ans['reviewer_notes'] ?? '') ?></textarea>
                                                            <div id="legal_notes_summary_<?= $ans['question_id'] ?>" class="small text-danger fw-bold mt-1 bg-danger bg-opacity-10 p-2 rounded d-none border border-danger border-opacity-25" style="line-height:1.4;"></div>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td>
                                                        <?php
                                                        // The figures behind a calculated answer (Quick Ratio, Debt to
                                                        // Equity), as the vendor typed them into the calculator. Same block
                                                        // Sourcing sees, so every reviewer checks the same numbers.
                                                        echo pqCalcSummaryHtml($ans['calc_details'] ?? null);
                                                        ?>

                                                        <?php if (!empty($ans['remarks'])): ?>
                                                            <div class="mb-2 p-2 bg-light rounded border small">
                                                                <div class="fw-bold text-muted mb-1 text-uppercase" style="font-size: 0.6rem;">Vendor Response:</div>
                                                                <div class="text-dark">
                                                                    <?php 
                                                                    $lines = explode("\n", $ans['remarks']);
                                                                    if (count($lines) > 1): ?>
                                                                        <ul class="ps-3 mb-0">
                                                                            <?php foreach($lines as $line): if(trim($line)) echo "<li>".htmlspecialchars(trim($line))."</li>"; endforeach; ?>
                                                                        </ul>
                                                                    <?php else: ?>
                                                                        <?php 
                                                                            if (is_numeric(str_replace(['.', ','], '', trim($ans['remarks']))) && stripos($ans['question_text'], 'clients') === false && stripos($ans['question_text'], 'branches') === false) {
                                                                                echo '<span class="fw-bold">Rp ' . number_format((float)str_replace(['.', ','], '', $ans['remarks']), 0, ',', '.') . '</span>';
                                                                            } else {
                                                                                echo nl2br(htmlspecialchars($ans['remarks']));
                                                                            }
                                                                        ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>

                                                        <div class="fw-bold text-dark small border p-1 rounded bg-light mb-1 d-inline-block"><?= htmlspecialchars($display_label) ?></div>
                                                        
                                                        <?php if ($ans['needs_revision']): ?>
                                                            <span class="badge bg-danger d-block mt-1" style="font-size: 0.6rem;"><i class="fas fa-flag"></i> Flagged by Sourcing</span>
                                                        <?php endif; ?>

                                                        <?php if (!empty($ans['attachment_path'])): ?>
                                                            <div class="mt-2 d-flex flex-wrap gap-1">
                                                                <?php 
                                                                    $files = explode(',', $ans['attachment_path']);
                                                                    foreach($files as $idx => $f): if(!trim($f)) continue;
                                                                        echo "<a href='{$path_to_root}../uploads/".htmlspecialchars(trim($f))."' target='_blank' class='badge bg-primary text-decoration-none'><i class='fas fa-paperclip'></i> File ".($idx+1)."</a>";
                                                                    endforeach; 
                                                                ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>
                                                    
                                                    <td class="bg-light-subtle">
                                                        <?php if (!empty($ans['reviewer_notes'])): ?>
                                                            <div class="small text-dark fst-italic border-start border-3 border-secondary ps-2 mb-1">
                                                                <?= nl2br(htmlspecialchars($ans['reviewer_notes'])) ?>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-muted small opacity-50 d-block mb-1">-</span>
                                                        <?php endif; ?>
                                                        
                                                        <!-- SOURCING REVIEWER NAME BADGE -->
                                                        <?php 
                                                            // Determine if we should show the sourcing badge
                                                            // True if there is a Sourcing reviewer attached AND they left a note, flag, or score
                                                            $has_sourcing_edit = (!empty($ans['reviewer_notes']) || !empty($ans['needs_revision']) || $ans['selected_score'] !== null);
                                                            $show_badge = (!empty($ans['reviewer_name']) && $has_sourcing_edit);
                                                        ?>
                                                        <?php if($show_badge): ?>
                                                            <div class="text-start mt-2">
                                                                <span class="badge bg-light text-secondary border border-secondary border-opacity-25 shadow-sm" style="font-size: 0.65rem;">
                                                                    <i class="fas fa-user-edit me-1"></i> <span class="reviewer-name-text"><?= htmlspecialchars($ans['reviewer_name'] ?? '') ?></span>
                                                                </span>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>

                                                    <!-- VM INTERACTIVE COLUMN -->
                                                    <td>
                                                        <?php if ($is_editable): ?>
                                                            <textarea name="vm_row_notes[<?= $ans['question_id'] ?>]" class="form-control form-control-sm border-warning" rows="3" placeholder="Add note for Sourcing..." style="font-size: 0.8rem;"><?= htmlspecialchars($ans['vm_row_notes'] ?? '') ?></textarea>
                                                        <?php else: ?>
                                                            <div class="small text-dark fst-italic border-start border-3 border-warning ps-2">
                                                                <?= !empty($ans['vm_row_notes']) ? nl2br(htmlspecialchars($ans['vm_row_notes'])) : '<span class="text-muted opacity-50">-</span>' ?>
                                                            </div>
                                                        <?php endif; ?>
                                                    </td>

                                                    <td class="text-center bg-light-subtle">
                                                        <?php if ($is_editable): ?>
                                                            <input type="checkbox" id="vm_flag_<?= $ans['question_id'] ?>" name="vm_revision_flags[<?= $ans['question_id'] ?>]" value="1" class="vm-flag-checkbox" <?= $ans['vm_needs_revision'] ? 'checked' : '' ?>>
                                                            <label for="vm_flag_<?= $ans['question_id'] ?>" class="vm-flag-label" title="Flag for Sourcing to Review"><i class="fas fa-flag"></i></label>
                                                        <?php else: ?>
                                                            <i class="fas fa-flag <?= $ans['vm_needs_revision'] ? 'text-warning' : 'text-muted opacity-25' ?>"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <?php if ($is_editable): ?>
                        <div class="card-footer bg-white py-3 sticky-bottom border-top">
                            <div class="d-flex justify-content-between align-items-center">
                                <small class="text-muted"><i class="fas fa-info-circle me-1"></i> Save your row notes before confirming a decision.</small>
                                <button type="submit" class="btn btn-warning text-dark px-4 fw-bold shadow-sm" id="save_vm_rows_btn">
                                    <i class="fas fa-save me-2"></i> Save Rows
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="col-lg-3">
            <div class="card shadow border-0 sticky-top" style="top: 80px; z-index: 100;">
                <div class="card-header bg-dark text-white py-3">
                    <h6 class="mb-0 fw-bold small text-uppercase text-white"><i class="fas fa-gavel me-2"></i>Final Decision</h6>
                </div>
                <div class="card-body p-3">
                    <?php if ($is_editable): ?>
                        <form method="POST" action="approve_action.php" id="decisionForm">
                            <input type="hidden" name="submission_id" value="<?= $sub_id ?>">
                            <input type="hidden" name="action" id="action_hidden_input" value="">
                            <!-- Used for the single Approve & PDF logic -->
                            <input type="hidden" name="approve_btn" id="approve_btn_input" value="" disabled>
                            
                            <?php if (!empty($submission['vm_notes'])): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted">
                                        <i class="fas fa-history me-1"></i> PREVIOUS NOTES
                                    </label>
                                    <div class="p-2 bg-light border rounded small" style="max-height: 150px; overflow-y: auto; font-size: 0.8rem; line-height: 1.6;">
                                        <?= nl2br(htmlspecialchars($submission['vm_notes'])) ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($is_vm_head): ?>
                                <div class="mb-3">
                                    <label class="form-label fw-bold small text-muted">
                                        <i class="fas fa-pen me-1"></i> VM HEAD NOTES
                                    </label>
                                    <textarea name="vm_notes" id="vm_notes_input" class="form-control" rows="4" placeholder="Enter final approval notes or rejection reasons..." required></textarea>
                                </div>
                            <?php else: ?>
                                <!-- VM Team notes are captured per-action via popups -->
                                <input type="hidden" name="vm_notes" id="vm_notes_hidden" value="">
                                <input type="hidden" name="notes_to_vendor" id="notes_to_vendor_hidden" value="">
                            <?php endif; ?>

                            <div class="d-grid gap-2">
                                <?php if ($is_vm_head): ?>
                                    <!-- VM HEAD: Approve + Return to VM + Reject -->
                                    <button type="button" class="btn btn-success py-2 fw-bold shadow-sm mb-2" onclick="triggerDecisionModal('approve')">
                                        <i class="fas fa-check-circle me-2"></i> Approve & Create PDF
                                    </button>
                                    <div class="row g-2">
                                        <div class="col-6">
                                            <button type="button" class="btn btn-warning text-dark w-100 py-2 fw-bold" title="Return to VM Team" onclick="triggerDecisionModal('return_to_vm')">
                                                <i class="fas fa-undo"></i> Return
                                            </button>
                                        </div>
                                        <div class="col-6">
                                            <button type="button" class="btn btn-outline-danger w-100 py-2 fw-bold" onclick="triggerDecisionModal('reject')">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <!-- VENDOR MGT: Forward to Head + Return (Sourcing / Vendor) -->
                                    <button type="button" class="btn btn-success py-2 fw-bold shadow-sm mb-2" onclick="openNoteModal('forward_head')">
                                        <i class="fas fa-arrow-up me-2"></i> Forward to VM Head
                                    </button>
                                    <button type="button" class="btn btn-warning text-dark w-100 py-2 fw-bold" title="Return this application" onclick="openReturnChoice()">
                                        <i class="fas fa-undo me-1"></i> Return
                                    </button>
                                <?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">VM FINAL NOTES</label>
                            <div class="p-2 bg-light border rounded small">
                                <?= !empty($submission['vm_notes']) ? nl2br(htmlspecialchars($submission['vm_notes'])) : '<span class="text-muted fst-italic">No VM notes provided.</span>' ?>
                            </div>
                        </div>
                        <div class="alert alert-secondary text-center small mb-0">
                            Assessment is locked<br><strong>Status: <?= strtoupper(str_replace('_', ' ', $submission['status'])) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- VENDOR QUICK PROFILE MODAL -->
<div class="modal fade" id="vendorProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-gradient-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-building me-2"></i> Quick Profile: <?= htmlspecialchars($vendor['company_name']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-4">
                    <!-- Corporate Info & Banking -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body p-4">
                                <h6 class="text-primary fw-bold text-uppercase mb-3 small border-bottom pb-2"><i class="fas fa-info-circle me-2"></i> Corporate Information</h6>
                                
                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Company Name & Type</label>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($vendor['company_name']) ?></div>
                                    <div class="text-muted small"><?= htmlspecialchars($vendor['company_type']) ?> - <span class="text-primary"><?= htmlspecialchars($vendor['sub_company_type'] ?? 'Unclassified') ?></span></div>
                                </div>

                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Director Name</label>
                                    <div class="fw-bold text-dark fs-6"><?= htmlspecialchars($vendor['director_name'] ?? '-') ?></div>
                                </div>

                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Establishment Date</label>
                                    <div class="fw-bold text-dark fs-6"><?= !empty($vendor['establishment_date']) ? date('d F Y', strtotime($vendor['establishment_date'])) : '-' ?></div>
                                </div>

                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Jumlah Karyawan</label>
                                    <div class="fw-bold text-dark fs-6"><i class="fas fa-users text-secondary me-1"></i> <?= ($vendor['number_of_employees'] ?? '') !== '' ? number_format((int) $vendor['number_of_employees'], 0, ',', '.') : '-' ?></div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-sm-6 mb-3 mb-sm-0">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Contact Person</label>
                                        <div class="fw-bold text-dark fs-6"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['contact_person']) ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Phone Number</label>
                                        <div class="fw-bold text-dark fs-6"><i class="fas fa-phone text-secondary me-1"></i> <?= htmlspecialchars($vendor['phone']) ?></div>
                                    </div>
                                </div>

                                <div class="row mb-3">
                                    <div class="col-sm-6 mb-3 mb-sm-0">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">PIC Finance</label>
                                        <div class="fw-bold text-dark"><i class="fas fa-user-tie text-secondary me-1"></i> <?= htmlspecialchars($vendor['pic_finance'] ?? '-') ?></div>
                                    </div>
                                    <div class="col-sm-6">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Email PIC Finance</label>
                                        <div class="fw-bold text-primary"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email_pic_finance'] ?? '-') ?></div>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Login Email</label>
                                    <div class="fw-bold text-primary"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email']) ?></div>
                                </div>

                                <div class="mb-4">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Company Address <span class="text-muted fw-normal text-lowercase">(dari NPWP)</span></label>
                                    <div class="fw-bold text-dark" style="line-height:1.5;"><i class="fas fa-map-marker-alt text-secondary me-1"></i> <?= !empty($vendor['company_address']) ? nl2br(htmlspecialchars($vendor['company_address'])) : '-' ?></div>
                                </div>

                                <h6 class="text-danger fw-bold text-uppercase mt-4 mb-3 small border-bottom pb-2"><i class="fas fa-university me-2"></i> Banking Details</h6>
                                <div class="row">
                                    <div class="col-sm-6 mb-3">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Nama Bank</label>
                                        <div class="fw-bold text-dark"><i class="fas fa-university text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_name'] ?? '-') ?></div>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Cabang Bank</label>
                                        <div class="fw-bold text-dark"><i class="fas fa-map-marker-alt text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_branch'] ?? '-') ?></div>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Account Number</label>
                                        <div class="fw-bold text-dark font-monospace"><?= htmlspecialchars($vendor['bank_account_number'] ?? '-') ?></div>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Account Holder</label>
                                        <div class="fw-bold text-dark"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['bank_holder_name'] ?? '-') ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Legal Documents List -->
                    <div class="col-lg-6">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body p-4">
                                <h6 class="text-success fw-bold text-uppercase mb-3 small border-bottom pb-2"><i class="fas fa-folder-open me-2"></i> Review Dokumen Legalitas</h6>
                                
                                <div class="mb-3 p-3 bg-light rounded border border-warning shadow-sm">
                                    <label class="form-label small fw-bold text-dark"><i class="fas fa-pen me-2 text-warning"></i>Catatan Umum Legalitas (dari Sourcing)</label>
                                    <div id="general_legal_note" class="small text-dark fst-italic p-2 bg-white rounded border border-secondary border-opacity-25"></div>
                                </div>

                                <div class="d-flex flex-column gap-1 bg-white rounded border p-2">
                                    <?php 
                                    $entity_type = $vendor['legal_entity_type'] ?? '';
                                    $comp_type   = strtolower($vendor['company_type'] ?? '');

                                    $legal_docs = [
                                        'KTP Direktur' => [$vendor['director_id_card_file'] ?? '', null, ''],
                                        'NPWP' => [$vendor['npwp_path'] ?? '', $vendor['npwp_number'] ?? '', $vendor['npwp_status'] ?? ''],
                                        'Buku Rekening' => [$vendor['bank_cover_path'] ?? '', null, ''],
                                        'Surat Pernyataan Perbedaan Nama Rekening' => [$vendor['bank_holder_statement_letter_file'] ?? '', null, ''],
                                        'Pakta Integritas Kawan Lama Group' => [$vendor['business_ethics_commitment_file'] ?? '', null, ''],
                                        'Tax Statement Letter' => [$vendor['tax_statement_letter_file'] ?? '', null, ''],
                                        'Profil Perusahaan' => [$vendor['company_profile_file'] ?? '', null, '']
                                    ];

                                    if ($entity_type === 'Badan Usaha') {
                                        $legal_docs['NIB'] = [$vendor['nib_path'] ?? '', $vendor['nib_number'] ?? '', $vendor['nib_status'] ?? ''];
                                        $legal_docs['Akta Pendirian'] = [$vendor['deed_of_establishment_file'] ?? '', null, ''];
                                        $legal_docs['SK Pengesahan'] = [$vendor['decree_of_legal_entity_file'] ?? '', null, ''];
                                        $legal_docs['Akta Perubahan'] = [$vendor['deed_of_amendment_file'] ?? '', null, ''];
                                        $legal_docs['SPPKP'] = [$vendor['sppkp_path'] ?? '', null, ''];
                                    }

                                    if (strpos($comp_type, 'contractor') !== false || strpos($comp_type, 'kontraktor') !== false) {
                                        $legal_docs['Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)'] = [$vendor['business_entity_cert_file'] ?? '', null, ''];
                                    }
                                    
                                    foreach($legal_docs as $label => $data) {
                                        renderModalDocRow($label, $data[0], $path_to_root, $data[1], $data[2]);
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top-0 py-3">
                <a href="../vendormgt/view_profile.php?id=<?= $vendor['id'] ?>"class="btn btn-outline-primary rounded-pill px-4 me-auto fw-bold">
                    <i class="fas fa-external-link-alt me-1"></i> Open Full Profile Page
                </a>
                <button type="button" class="btn btn-dark rounded-pill px-4 fw-bold" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- VM DECISION MODAL -->
<div class="modal fade" id="decisionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 py-3" id="decisionModalHeader">
                <h5 class="modal-title fw-bold text-white" id="decisionModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-4">
                    <i class="fas fa-question-circle fa-4x opacity-50" id="decisionModalIcon"></i>
                </div>
                <h4 class="fw-bold text-dark mb-3" id="decisionModalHeading">Are you sure?</h4>
                <p class="text-muted" id="decisionModalText">
                    Please confirm your action before proceeding.
                </p>
                <div class="alert alert-info small text-start d-none" id="decisionModalPDFWarning">
                    <i class="fas fa-file-pdf me-1"></i> <strong>Note:</strong> Approving will immediately generate the official Pre-Qualification Certificate PDF and change the vendor's status to Approved.
                </div>
                <div class="alert alert-warning small text-start d-none" id="decisionModalReviseWarning">
                    <i class="fas fa-exclamation-triangle me-1"></i> <strong>Note:</strong> Any unsaved changes in the row notes or flags will be saved automatically before processing.
                </div>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn fw-bold px-4 rounded-pill" id="decisionModalBtn" onclick="executeDecision()">
                    Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- VM NOTES REQUIRED WARNING MODAL -->
<div class="modal fade" id="notesRequiredModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg overflow-hidden">
            <button type="button" class="btn-close position-absolute end-0 top-0 m-3" style="z-index:5;" data-bs-dismiss="modal" aria-label="Close"></button>
            <div class="modal-body px-4 pt-4 pb-2 text-center">
                <div class="notes-required-icon mb-4">
                    <i class="fas fa-pen-nib"></i>
                </div>
                <h5 class="fw-bold text-dark mb-2">VM Notes Required</h5>
                <p class="text-muted mb-0" style="line-height:1.6;">
                    Please provide <strong class="text-dark">VM Notes</strong> before proceeding.<br>
                    <span class="small">These will appear on the final document.</span>
                </p>
            </div>
            <div class="modal-footer border-0 d-flex justify-content-center pb-4 pt-2">
                <button type="button" class="btn btn-notes-ok fw-bold px-5 py-2 rounded-pill" data-bs-dismiss="modal">
                    <i class="fas fa-check me-2"></i>OK, Got It
                </button>
            </div>
        </div>
    </div>
</div>

<!-- RETURN DESTINATION CHOICE MODAL (VM Team) -->
<div class="modal fade" id="returnChoiceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning border-0 py-3">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-undo me-2"></i>Return Application</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <p class="text-muted mb-4">Where would you like to return this application?</p>
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-secondary py-2 fw-bold" onclick="openNoteModal('revision')">
                        <i class="fas fa-user-tie me-2"></i> To Sourcing
                    </button>
                    <?php if (empty($vendor['registered_by_sourcing_id'])): ?>
                    <button type="button" class="btn btn-return-vendor py-2 fw-bold" onclick="openNoteModal('return_to_vendor')">
                        <i class="fas fa-building me-2"></i> To Vendor
                    </button>
                    <?php else: ?>
                    <div class="alert alert-light border small text-muted mb-0 text-start">
                        <i class="fas fa-info-circle me-1"></i> "To Vendor" isn't available — this submission was filled by Sourcing via Direct Registration, so it always returns to them.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- VM ACTION NOTE MODAL (Forward / Return to Sourcing / Return to Vendor) -->
<div class="modal fade" id="vmNoteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 py-3" id="vmNoteModalHeader">
                <h5 class="modal-title fw-bold text-white" id="vmNoteModalTitle">Add Note</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <label class="form-label fw-bold small text-muted" id="vmNoteModalLabel">
                    <i class="fas fa-pen me-1"></i> Note
                </label>
                <textarea id="vmNoteModalText" class="form-control" rows="4" placeholder="Type your note here..."></textarea>
                <div class="form-text mt-2" id="vmNoteModalHelp"></div>
                <div class="alert alert-danger small mt-3 d-none" id="vmNoteModalError">
                    <i class="fas fa-exclamation-circle me-1"></i> Please enter a note before proceeding.
                </div>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn fw-bold px-4 rounded-pill" id="vmNoteModalBtn" onclick="confirmNote()">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
    // --- LEGAL NOTES (Read-Only Mirror from Sourcing) ---
    let currentLegalQid = null;

    function initLegalNotes(qid) {
        if (!qid) return;
        currentLegalQid = qid;

        let valBox = document.getElementById('legal_notes_data_' + qid);
        if (!valBox) return;
        let val = valBox.value;

        let data = { docs: {}, general: "" };
        if (val) {
            try {
                data = JSON.parse(val);
                if (!data.docs) data = { docs: data, general: "" };
            } catch(e) {
                data.general = val;
            }
        }

        // General note (read-only)
        let genNote = document.getElementById('general_legal_note');
        if (genNote) {
            genNote.innerText = data.general || "- Tidak ada catatan umum -";
        }

        // Per-document notes (read-only)
        document.querySelectorAll('[id^="readonly_note_"]').forEach(readonlyDiv => {
            let docKey = readonlyDiv.id.replace('readonly_note_', '');
            let docName = readonlyDiv.getAttribute('data-doc');
            let textDisplay = readonlyDiv.querySelector('.note-text-display');

            if (data.docs[docName]) {
                readonlyDiv.classList.remove('d-none');
                textDisplay.innerText = data.docs[docName];
            } else {
                readonlyDiv.classList.add('d-none');
                textDisplay.innerText = '';
            }
        });

        updateLegalSummary();
    }

    function updateLegalSummary() {
        if (!currentLegalQid) return;
        let valBox = document.getElementById('legal_notes_data_' + currentLegalQid);
        let summaryDiv = document.getElementById('legal_notes_summary_' + currentLegalQid);
        if (!summaryDiv || !valBox) return;

        let val = valBox.value;
        if (!val) {
            summaryDiv.innerHTML = '';
            summaryDiv.classList.add('d-none');
            return;
        }

        try {
            let data = JSON.parse(val);
            let html = [];
            let count = Object.keys(data.docs || {}).length;
            if (count > 0) html.push(`<i class="fas fa-exclamation-triangle"></i> Terdapat <strong>${count} dokumen</strong> yang ditandai butuh revisi oleh Sourcing.`);
            if (data.general) html.push(`<i class="fas fa-comment-dots"></i> Ditambah instruksi umum untuk legalitas.`);

            if (html.length > 0) {
                summaryDiv.innerHTML = html.join('<br>');
                summaryDiv.classList.remove('d-none');
            } else {
                summaryDiv.innerHTML = '';
                summaryDiv.classList.add('d-none');
            }
        } catch(e) {
            summaryDiv.innerHTML = '';
            summaryDiv.classList.add('d-none');
        }
    }

    // Auto-load legal summaries on page load
    document.addEventListener('DOMContentLoaded', function() {
        let legalTAs = document.querySelectorAll('textarea[id^="legal_notes_data_"]');
        if (legalTAs.length > 0) {
            let qid = legalTAs[0].id.replace('legal_notes_data_', '');
            currentLegalQid = qid;
            updateLegalSummary();
        }
    });

    // --- AJAX SAVE ROWS FUNCTION ---
    async function submitVMRows(event) {
        if(event) event.preventDefault();
        
        const form = document.getElementById('vmReviewForm');
        const btn = document.getElementById('save_vm_rows_btn');
        const originalText = btn ? btn.innerHTML : '';
        
        if(btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
            btn.disabled = true;
        }

        try {
            const response = await fetch('approve_action.php', {
                method: 'POST',
                body: new FormData(form)
            });
            const data = await response.json();
            
            if(btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }

            if (data.status === 'success') {
                const msgBox = document.getElementById('ajax_success_msg');
                msgBox.classList.remove('d-none');
                setTimeout(() => msgBox.classList.add('d-none'), 3000);
                return true;
            } else {
                alert('Error saving row notes: ' + data.message);
                return false;
            }
        } catch (error) {
            if(btn) {
                btn.innerHTML = originalText;
                btn.disabled = false;
            }
            console.error('Error:', error);
            alert('Failed to connect to server. Row notes not saved.');
            return false;
        }
    }

    // --- MODAL LOGIC FOR VM ACTIONS ---
    function triggerDecisionModal(actionType) {
        const noteField = document.getElementById('vm_notes_input');
        const noteInput = noteField.value.trim();
        if (noteInput === '') {
            const notesModal = new bootstrap.Modal(document.getElementById('notesRequiredModal'));
            notesModal.show();
            document.getElementById('notesRequiredModal').addEventListener('hidden.bs.modal', function handler() {
                noteField.focus();
                this.removeEventListener('hidden.bs.modal', handler);
            });
            return;
        }

        const actionInput = document.getElementById('action_hidden_input');
        const approveBtnInput = document.getElementById('approve_btn_input');
        
        actionInput.value = '';
        approveBtnInput.value = '';
        approveBtnInput.disabled = true;

        const header = document.getElementById('decisionModalHeader');
        const icon = document.getElementById('decisionModalIcon');
        const title = document.getElementById('decisionModalTitle');
        const heading = document.getElementById('decisionModalHeading');
        const text = document.getElementById('decisionModalText');
        const btn = document.getElementById('decisionModalBtn');
        const pdfWarning = document.getElementById('decisionModalPDFWarning');
        const reviseWarning = document.getElementById('decisionModalReviseWarning');
        
        header.className = 'modal-header border-0 py-3';
        icon.className = 'fas fa-4x opacity-50';
        btn.className = 'btn fw-bold px-4 rounded-pill';
        title.classList.remove('text-dark');
        title.classList.add('text-white');
        pdfWarning.classList.add('d-none'); 
        reviseWarning.classList.add('d-none'); 

        if (actionType === 'approve') {
            approveBtnInput.disabled = false; 
            approveBtnInput.value = 'approve';
            actionInput.value = 'approve';
            
            header.classList.add('bg-success');
            icon.classList.add('fa-check-double', 'text-success');
            title.innerText = 'Final Approval';
            heading.innerText = 'Approve Vendor?';
            text.innerHTML = 'You are about to give final approval for this vendor.';
            btn.classList.add('btn-success');
            btn.innerText = 'Yes, Approve & Create PDF';
            pdfWarning.classList.remove('d-none');
            
        } else if (actionType === 'forward_head') {
            actionInput.value = 'forward_head';
            header.classList.add('bg-success');
            icon.classList.add('fa-arrow-up', 'text-success');
            title.innerText = 'Forward to VM Head';
            heading.innerText = 'Forward to VM Head?';
            text.innerHTML = 'You are forwarding this vendor application to the VM Head for final approval.';
            btn.classList.add('btn-success');
            btn.innerText = 'Yes, Forward to VM Head';

        } else if (actionType === 'revision') {
            actionInput.value = 'revision';
            header.classList.add('bg-warning');
            title.classList.replace('text-white', 'text-dark');
            icon.classList.add('fa-undo', 'text-warning');
            heading.innerText = 'Return to Sourcing?';
            text.innerHTML = 'You are returning this application to the Sourcing team for corrections.';
            btn.classList.add('btn-warning', 'text-dark');
            btn.innerText = 'Yes, Return it';
            reviseWarning.classList.remove('d-none');

        } else if (actionType === 'return_to_vm') {
            actionInput.value = 'return_to_vm';
            header.classList.add('bg-warning');
            title.classList.replace('text-white', 'text-dark');
            icon.classList.add('fa-undo', 'text-warning');
            heading.innerText = 'Return to VM Team?';
            text.innerHTML = 'You are returning this application to the Vendor Management team for further review.';
            btn.classList.add('btn-warning', 'text-dark');
            btn.innerText = 'Yes, Return to VM';

        } else if (actionType === 'reject') {
            actionInput.value = 'reject';
            header.classList.add('bg-danger');
            icon.classList.add('fa-times-circle', 'text-danger');
            heading.innerText = 'Reject Vendor?';
            text.innerHTML = 'This will mark the vendor application as rejected. This action is final.';
            btn.classList.add('btn-danger');
            btn.innerText = 'Yes, Reject Vendor';
        }

        var modal = new bootstrap.Modal(document.getElementById('decisionModal'));
        modal.show();
    }

    // Auto-save rows via AJAX before finalizing the status change
    async function executeDecision() {
        const btn = document.getElementById('decisionModalBtn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Finalizing...';
        btn.disabled = true;

        // Auto-save the row-level notes first
        const success = await submitVMRows();
        if (success) {
            document.getElementById('decisionForm').submit();
        } else {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }

    // --- VM TEAM: PER-ACTION NOTE POPUPS (Forward / Return to Sourcing / Return to Vendor) ---
    let pendingAction = null;

    const VM_NOTE_CONFIG = {
        forward_head: {
            headerClass: 'bg-success',
            title: 'Forward to VM Head',
            label: '<i class="fas fa-pen me-1"></i> VM Notes',
            help: 'These notes will be visible to the VM Head.',
            btnClass: 'btn-success',
            btnText: 'Forward to VM Head'
        },
        revision: {
            headerClass: 'bg-secondary',
            title: 'Return to Sourcing',
            label: '<i class="fas fa-pen me-1"></i> Note for Sourcing',
            help: 'This note will be sent back to the Sourcing team.',
            btnClass: 'btn-secondary',
            btnText: 'Return to Sourcing'
        },
        return_to_vendor: {
            headerClass: 'bg-gradient-orange',
            title: 'Return to Vendor',
            label: '<i class="fas fa-pen me-1"></i> Note for Vendor',
            help: 'The vendor will see this as "DD Month YYYY HH.mm - Message" (your name is recorded internally but hidden from the vendor).',
            btnClass: 'btn-return-vendor',
            btnText: 'Return to Vendor'
        }
    };

    // Direct Registration: Sourcing filled/submitted the PQ themselves, so
    // "Return to Vendor" makes no sense — the vendor never touched this
    // submission. Always route back to Sourcing instead; skip the
    // destination-choice modal entirely rather than offering a dead-end option.
    const isDirectRegistered = <?= !empty($vendor['registered_by_sourcing_id']) ? 'true' : 'false' ?>;

    function openReturnChoice() {
        if (isDirectRegistered) {
            openNoteModal('revision');
            return;
        }
        new bootstrap.Modal(document.getElementById('returnChoiceModal')).show();
    }

    function openNoteModal(action) {
        // If the choice modal is open (Return -> To Sourcing/Vendor), wait for it to
        // fully hide before showing the note modal so Bootstrap cleans up its backdrop.
        const choiceEl = document.getElementById('returnChoiceModal');
        const choiceModal = bootstrap.Modal.getInstance(choiceEl);
        if (choiceModal) {
            choiceEl.addEventListener('hidden.bs.modal', () => showNoteModal(action), { once: true });
            choiceModal.hide();
        } else {
            showNoteModal(action);
        }
    }

    function showNoteModal(action) {
        pendingAction = action;
        const cfg = VM_NOTE_CONFIG[action];
        if (!cfg) return;

        document.getElementById('vmNoteModalHeader').className = 'modal-header border-0 py-3 ' + cfg.headerClass;
        document.getElementById('vmNoteModalTitle').innerText = cfg.title;
        document.getElementById('vmNoteModalLabel').innerHTML = cfg.label;
        document.getElementById('vmNoteModalHelp').innerText = cfg.help;
        document.getElementById('vmNoteModalError').classList.add('d-none');

        const btn = document.getElementById('vmNoteModalBtn');
        btn.className = 'btn fw-bold px-4 rounded-pill ' + cfg.btnClass;
        btn.innerText = cfg.btnText;

        const ta = document.getElementById('vmNoteModalText');
        ta.value = '';

        const modalEl = document.getElementById('vmNoteModal');
        const modal = new bootstrap.Modal(modalEl);
        modalEl.addEventListener('shown.bs.modal', () => ta.focus(), { once: true });
        modal.show();
    }

    async function confirmNote() {
        const ta = document.getElementById('vmNoteModalText');
        const note = ta.value.trim();
        if (note === '') {
            document.getElementById('vmNoteModalError').classList.remove('d-none');
            ta.focus();
            return;
        }

        // Route the note into the correct hidden field
        if (pendingAction === 'return_to_vendor') {
            document.getElementById('notes_to_vendor_hidden').value = note;
        } else {
            document.getElementById('vm_notes_hidden').value = note;
        }
        document.getElementById('action_hidden_input').value = pendingAction;

        const btn = document.getElementById('vmNoteModalBtn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Processing...';
        btn.disabled = true;

        // Save row-level notes/flags first (same sequence as executeDecision)
        const success = await submitVMRows();
        if (success) {
            document.getElementById('decisionForm').submit();
        } else {
            btn.innerHTML = originalText;
            btn.disabled = false;
        }
    }
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>