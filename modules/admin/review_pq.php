<?php
// modules/admin/review_pq.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/pq_risk_helper.php';
require_once $path_to_root . 'includes/sourcing_note_helper.php';
require_once $path_to_root . 'includes/vendor_business_model.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

if (!isset($_GET['id'])) die("No Vendor ID provided");
$vendor_id = intval($_GET['id']);

// 2. Fetch Submission Data (Added contact_person & email)
$sql_sub = "SELECT s.*, v.company_name, v.company_type, v.sub_company_type, v.contact_person, v.email 
            FROM pq_submissions s 
            JOIN vendors v ON s.vendor_id = v.id 
            WHERE s.vendor_id = ? ORDER BY s.id DESC LIMIT 1";
$stmt = $conn->prepare($sql_sub);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();

if (!$submission) {
    $stmt_v = $conn->prepare("SELECT company_name FROM vendors WHERE id = ?");
    $stmt_v->bind_param("i", $vendor_id);
    $stmt_v->execute();
    $vendor_name = $stmt_v->get_result()->fetch_assoc()['company_name'] ?? 'This vendor';

    include $path_to_root . 'includes/header.php';
    ?>
    <nav class="navbar navbar-vss mb-4 sticky-top">
        <div class="container-fluid">
            <span class="navbar-brand d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Review PQ Submission</span>
        </div>
    </nav>
    <div class="container py-5 text-center">
        <i class="fas fa-file-circle-question fa-3x text-muted mb-3"></i>
        <h4><?= htmlspecialchars($vendor_name) ?> has no PQ submission</h4>
        <p class="text-muted">This vendor was approved via Exception Request, so no PQ questionnaire was ever submitted.</p>
        <a href="download_vendor_docs.php?id=<?= $vendor_id ?>" class="btn btn-outline-dark me-2">
            <i class="fas fa-download me-1"></i> Download Vendor Profile
        </a>
        <a href="dashboard.php" class="btn btn-link">Back to Dashboard</a>
    </div>
    <?php
    include $path_to_root . 'includes/footer.php';
    exit;
}

// Fetch full vendor data for the modal
$stmt_v = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt_v->bind_param("i", $vendor_id);
$stmt_v->execute();
$vendor = $stmt_v->get_result()->fetch_assoc();

// -----------------------------------------------------------------
// Auto-classify sole-proprietor vendors, since they have no other valid
// sub-category. Perseorangan company_type gets its own 'Perseorangan'
// sub-category; Contractor + Perseorangan legal entity keeps the older
// 'Individual / Foreman' label (both still share the same pq_weight
// question set — see $pq_weight_target below). Contractor + Badan Usaha
// keeps the full set of Contractor sub-categories so sourcing can choose.
//
// A vendor that declared itself UMKM on its profile joins the first branch
// whatever its company_type is: it answers the standalone Perseorangan
// question set, and that set is keyed by sub_variant_target = 'Perseorangan',
// so its sub_company_type must be pinned there too.
// -----------------------------------------------------------------
$legal_entity = $vendor['legal_entity_type'] ?? '';
$uses_perseorangan_pq = vendor_uses_perseorangan_pq($submission['company_type'], $vendor['is_umkm'] ?? 0);
if ($uses_perseorangan_pq) {
    if ($submission['sub_company_type'] !== 'Perseorangan') {
        $auto_sub  = 'Perseorangan';
        $stmt_auto = $conn->prepare("UPDATE vendors SET sub_company_type = ? WHERE id = ?");
        $stmt_auto->bind_param("si", $auto_sub, $vendor_id);
        $stmt_auto->execute();
        $submission['sub_company_type'] = $auto_sub;
        $vendor['sub_company_type']     = $auto_sub;
    }
} elseif ($submission['company_type'] === 'Contractor' && $legal_entity === 'Perseorangan') {
    if ($submission['sub_company_type'] !== 'Individual / Foreman') {
        $auto_sub  = 'Individual / Foreman';
        $stmt_auto = $conn->prepare("UPDATE vendors SET sub_company_type = ? WHERE id = ?");
        $stmt_auto->bind_param("si", $auto_sub, $vendor_id);
        $stmt_auto->execute();
        $submission['sub_company_type'] = $auto_sub;
        $vendor['sub_company_type']     = $auto_sub;
    }
}

// --- APPLY NEW RISK LOGIC FOR INITIAL LOAD (satu sumber di includes/pq_risk_helper.php) ---
$max_score = (float)$submission['max_possible_weighted_score'];
$total_score = (float)$submission['total_weighted_score'];
$risk_percentage = pqRiskPercentage($total_score, $max_score);
$risk_category = pqRiskCategory($risk_percentage);

// Override submission array with newly calculated values for display
$submission['risk_percentage'] = $risk_percentage;
$submission['risk_category'] = $risk_category;

// 3. NEW LOGIC: Join with pq_weight dynamically based on selected Sub-Category
$current_sub = $submission['sub_company_type'] ?? '';
$main_type = $submission['company_type'];
// Perseorangan has its own standalone question set: both variant_target and
// pq_weight.sub_variant_target are 'Perseorangan'. UMKM vendors use that same
// set, so they match on 'Perseorangan' rather than on their own company_type.
$like_type = '%' . ($uses_perseorangan_pq ? 'Perseorangan' : $main_type) . '%';
$pq_weight_target = $uses_perseorangan_pq ? 'Perseorangan' : $current_sub;

// FETCH ALL NEEDED COLUMNS (Including VM Notes and Reviewer Name)
// Fetch only questions whose pq_weight row matches the vendor's
// sub_company_type. If sub_company_type is not set yet, no questions
// are returned — sourcing must classify the vendor first.
$sql_ans = "SELECT 
                q.id as question_id,
                a.selected_score, 
                a.remarks, 
                a.attachment_path, 
                a.needs_revision,
                a.reviewer_notes,
                a.vm_needs_revision,
                a.vm_row_notes,
                a.reviewer_id,
                u.full_name as reviewer_name,
                q.question_text, 
                q.variant_target,
                pw.weight,
                COALESCE(pw.help_text, q.help_text) as help_text,
                pw.sub_variant_target as all_sub_targets,
                sc.section_name 
            FROM pq_questions q 
            INNER JOIN pq_weight pw 
                ON pw.question_id = q.id 
               AND pw.sub_variant_target = ?
            LEFT JOIN pq_answers a ON q.id = a.question_id AND a.submission_id = ?
            LEFT JOIN pq_sections sc ON q.section_id = sc.id
            LEFT JOIN users u ON a.reviewer_id = u.id
            WHERE (q.variant_target IS NULL OR q.variant_target = '' OR q.variant_target LIKE ?)
            ORDER BY sc.sort_order, q.id";

$stmt_a = $conn->prepare($sql_ans);
$stmt_a->bind_param("sis", $pq_weight_target, $submission['id'], $like_type);
$stmt_a->execute();
$res_ans = $stmt_a->get_result();

// Group answers
$review_data = [];
while ($row = $res_ans->fetch_assoc()) {
    $sec = $row['section_name'] ?? 'General Questions';
    $review_data[$sec][] = $row;
}

$is_editable = in_array($submission['status'], ['pq_submitted', 'under_review_sourcing', 'returned_to_sourcing']);
$page_title = "Review: " . $submission['company_name'];

// --- FETCH AUDIT LOGS (HISTORY) ---
$audit_logs = [];
$check_table = $conn->query("SHOW TABLES LIKE 'pq_audit_logs'");
if ($check_table && $check_table->num_rows > 0) {
    $log_sql = "SELECT l.*, u.full_name, q.question_text 
                FROM pq_audit_logs l 
                JOIN users u ON l.user_id = u.id 
                LEFT JOIN pq_questions q ON l.question_id = q.id 
                WHERE l.submission_id = ? 
                ORDER BY l.created_at DESC";
    $stmt_log = $conn->prepare($log_sql);
    $stmt_log->bind_param("i", $submission['id']);
    $stmt_log->execute();
    $log_res = $stmt_log->get_result();
    while($r = $log_res->fetch_assoc()) {
        $audit_logs[] = $r;
    }
}

// --- HELPER FUNCTION: RENDER MODAL DOC ROW DENGAN FITUR REVISI ---
function renderModalDocRow($label, $filePath, $rootPath, $is_editable, $ocrNumber = null, $ocrStatus = 'empty') {
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

    // INPUT CATATAN REVISI KHUSUS SOURCING
    echo '  <div class="mt-2 doc-note-wrapper" id="wrapper_doc_'.$docKey.'">';
    if ($is_editable) {
        echo '      <div class="form-check form-switch mt-2 mb-1">';
        echo '          <input class="form-check-input doc-flag-switch border-danger" type="checkbox" id="flag_doc_'.$docKey.'" data-doc="'.htmlspecialchars($label).'" onchange="toggleDocNote(\''.$docKey.'\')">';
        echo '          <label class="form-check-label small fw-bold text-danger" for="flag_doc_'.$docKey.'">Tandai Dokumen Ini Untuk Direvisi</label>';
        echo '      </div>';
        echo '      <textarea id="note_doc_'.$docKey.'" class="form-control form-control-sm doc-note-input d-none border-danger bg-danger bg-opacity-10 text-danger shadow-sm" rows="2" placeholder="Tuliskan apa yang salah dengan dokumen ini..." data-doc="'.htmlspecialchars($label).'" oninput="syncLegalNotes()"></textarea>';
    } else {
        echo '      <div id="readonly_note_'.$docKey.'" class="d-none mt-2 p-2 bg-danger bg-opacity-10 rounded border border-danger border-opacity-25 small">';
        echo '          <strong class="text-danger d-block mb-1" style="font-size: 0.65rem;"><i class="fas fa-exclamation-triangle me-1"></i> Catatan Revisi:</strong>';
        echo '          <span class="text-dark note-text-display"></span>';
        echo '      </div>';
    }
    echo '  </div>';
    echo '</div>';
}

include $path_to_root . 'includes/header.php';
?>

<style>
    .flag-checkbox { display: none; }
    .flag-label { cursor: pointer; color: #ccc; font-size: 1.2rem; transition: color 0.2s; }
    .flag-label:hover { color: #dc3545; }
    .flag-checkbox:checked + .flag-label { color: #dc3545; }
    
    /* Auto Highlight flagged rows */
    tr.row-flagged { background-color: #fffdf7; box-shadow: inset 4px 0 0 0 #dc3545; }

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
    .note-card { background: linear-gradient(to right, #ffffff, #f8f9fc); border-radius: 12px; position: relative; }
    .note-card::before { content: "\f10d"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; top: 15px; right: 20px; font-size: 3rem; color: rgba(13, 202, 240, 0.1); }
    .company-title-badge { display: inline-flex; align-items: center; background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%); color: white; padding: 0.5rem 1.5rem; border-radius: 50rem; box-shadow: 0 4px 10px rgba(13, 110, 253, 0.3); transition: transform 0.3s ease, box-shadow 0.3s ease; }
    .company-title-badge:hover { transform: translateY(-2px); box-shadow: 0 6px 15px rgba(13, 110, 253, 0.4); }
    .company-icon-circle { background: rgba(255,255,255,0.2); border-radius: 50%; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; margin-right: 12px; }

    /* Modern Timeline Styles for Audit Log */
    .modern-timeline {
        position: relative;
        padding-left: 2.5rem;
        margin-left: 1rem;
    }
    .modern-timeline::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 3px;
        background: linear-gradient(to bottom, #0dcaf0 0%, #0d6efd 100%);
        border-radius: 3px;
        opacity: 0.3;
    }
    .modern-timeline-item {
        position: relative;
        margin-bottom: 2rem;
        transition: all 0.3s ease;
    }
    .modern-timeline-marker {
        position: absolute;
        left: -2.5rem;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: #fff;
        border: 2px solid #0dcaf0;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #0dcaf0;
        font-size: 0.9rem;
        transform: translateX(-50%);
        z-index: 1;
        transition: all 0.3s ease;
        box-shadow: 0 0 0 5px #f8f9fa;
    }
    .modern-timeline-item:hover .modern-timeline-marker {
        background: #0dcaf0;
        color: #fff;
        box-shadow: 0 0 0 5px rgba(13, 202, 240, 0.2);
        transform: translateX(-50%) scale(1.15);
    }
    .modern-timeline-content {
        background: #fff;
        border: 1px solid rgba(0,0,0,0.06);
        border-radius: 16px;
        padding: 1.25rem 1.5rem;
        box-shadow: 0 5px 20px rgba(0,0,0,0.02);
        transition: all 0.3s ease;
    }
    .modern-timeline-item:hover .modern-timeline-content {
        box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        border-color: rgba(13, 202, 240, 0.4);
        transform: translateY(-3px);
    }
    .avatar-circle {
        background: linear-gradient(135deg, #e7f1ff 0%, #cbe0ff 100%);
        color: #0d6efd;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 800;
        font-size: 0.85rem;
        border-radius: 50%;
    }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Review PQ Submission</span>
        <div class="d-flex align-items-center gap-2">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <div class="dropdown">
                    <button class="btn btn-sm btn-nav position-relative" data-bs-toggle="dropdown">
                        <i class="fas fa-bell"></i>
                        <?php if ($notif_count > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge bg-danger rounded-pill" style="font-size:0.6rem;">
                                <?= $notif_count ?>
                            </span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end p-0" style="min-width:300px;">
                        <div class="px-3 py-2 border-bottom fw-bold small">Notifikasi</div>
                        <?php if (empty($notif_items)): ?>
                            <div class="text-muted small text-center py-3">Tidak ada notifikasi baru</div>
                        <?php else: ?>
                            <?php foreach($notif_items as $n): ?>
                                <div class="px-3 py-2 border-bottom small" style="cursor:pointer;" onclick="markRead(<?= $n['id'] ?>, this)">
                                    <?= htmlspecialchars($n['message']) ?>
                                    <div class="text-muted" style="font-size:0.65rem;"><?= date('d M H:i', strtotime($n['created_at'])) ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            <a href="download_vendor_docs.php?id=<?= $vendor_id ?>&sub_id=<?= $submission['id'] ?>"
               class="btn btn-sm btn-nav">
                <i class="fas fa-file-archive me-1"></i> Download Docs
            </a>
            <a href="javascript:window.history.back()" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container mt-4 mb-5">
    <div class="row mb-4 g-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-gradient-warning metric-card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Total Score</h6>
                    <h1 class="display-4 fw-bold mb-0 text-white" id="display_total_score"><?= number_format((float)$submission['total_weighted_score'], 2) ?></h1>
                    <div class="small text-white-50 mt-1">out of <span id="display_max_score"><?= (float)$submission['max_possible_weighted_score'] ?></span></div>
                </div>
                <i class="fas fa-star metric-icon text-white"></i>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 bg-dark metric-card" style="background: linear-gradient(135deg, #6c757d 0%, #495057 100%);">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase text-white-50 small fw-bold mb-2">Risk Percentage</h6>
                    <h1 class="display-4 fw-bold mb-0 text-white" id="display_risk_percentage"><?= number_format((float)$submission['risk_percentage'], 1) ?>%</h1>
                    <div class="small text-white-50 mt-1">Risk Level</div>
                </div>
                <i class="fas fa-percentage metric-icon text-white"></i>
            </div>
        </div>

        <div class="col-md-4">
            <?php 
                $risk_bg = 'bg-gradient-danger'; $text_color = 'text-white'; $icon_color = 'text-white'; $icon = 'fa-times-circle';
                if ($risk_category == 'Very Low') { $risk_bg = 'bg-gradient-success'; $icon = 'fa-shield-alt'; } 
                elseif ($risk_category == 'Low') { $risk_bg = 'bg-gradient-info'; $icon = 'fa-thumbs-up'; } 
                elseif ($risk_category == 'Moderate') { $risk_bg = 'bg-gradient-warning'; $text_color = 'text-dark'; $icon_color = 'text-dark'; $icon = 'fa-balance-scale'; } 
                elseif ($risk_category == 'High') { $risk_bg = 'bg-gradient-orange'; $icon = 'fa-exclamation-triangle'; }
            ?>
            <div class="card h-100 shadow-sm border-0 <?= $risk_bg ?> metric-card" id="display_risk_card">
                <div class="card-body metric-content text-white">
                    <h6 class="text-uppercase small fw-bold mb-2 opacity-75 <?= $text_color ?>" id="display_risk_label">Risk Category</h6>
                    <h2 class="display-5 fw-bold mb-0 <?= $text_color ?>" id="display_risk_category"><?= $submission['risk_category'] ?></h2>
                    <div class="small opacity-75 mt-1 <?= $text_color ?>" id="display_risk_sublabel">Classification</div>
                </div>
                <i class="fas <?= $icon ?> metric-icon <?= $icon_color ?>" id="display_risk_icon"></i>
            </div>
        </div>
    </div>

    <?php if(!empty($submission['vm_notes'])): ?>
        <div class="card border-0 shadow-sm mb-4 note-card" style="border-left: 5px solid #dc3545 !important;">
            <div class="card-body p-4">
                <div class="d-flex align-items-center mb-3">
                    <div class="bg-danger bg-opacity-10 rounded-circle p-2 me-3"><i class="fas fa-clipboard-check text-danger fs-5"></i></div>
                    <div><h6 class="mb-0 fw-bold text-dark">Feedback from VM Team</h6><small class="text-muted">Review notes regarding this submission</small></div>
                </div>
                <div class="p-3 bg-white rounded-3 border border-light shadow-sm text-secondary fst-italic" style="line-height: 1.6;">
                    "<?= nl2br(htmlspecialchars($submission['vm_notes'])) ?>"
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div id="ajax_success_msg" class="alert alert-success alert-dismissible fade show shadow-sm mb-4 d-none" role="alert">
        <i class="fas fa-check-circle me-2"></i> <span id="ajax_success_text">Assessment updated successfully.</span>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>

    <div class="row">
        <div class="col-md-9">
            <form action="update_assessment.php" method="POST" id="updateForm" onsubmit="submitFormAjax(event)">
                <input type="hidden" name="submission_id" value="<?= $submission['id'] ?>">
                <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
                <input type="hidden" name="ajax_request" value="1">

                <div class="card shadow-sm border-0 mb-4 rounded-4">
                    <div class="card-header bg-white py-4 border-bottom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3">
                            <div class="company-title-badge mb-3 mb-md-0">
                                <div class="company-icon-circle"><i class="fas fa-building fs-5 text-white"></i></div>
                                <div><h4 class="mb-0 fw-bold text-white" style="letter-spacing: 0.5px;"><?= htmlspecialchars($submission['company_name']) ?></h4></div>
                            </div>
                            <div class="d-flex flex-column align-items-md-end gap-2 text-start text-md-end">
                                <div class="d-flex flex-wrap gap-2">
                                    <span class="badge bg-secondary rounded-pill px-3 py-2 fw-normal shadow-sm"><i class="fas fa-briefcase me-1 opacity-75"></i> <?= htmlspecialchars($submission['company_type']) ?></span>
                                    <span class="badge bg-light text-dark border rounded-pill px-3 py-2 shadow-sm transition-all hover-scale" id="display_badge_risk">
                                        <i class="fas <?= $icon ?> text-secondary me-1" id="display_badge_icon"></i> <span id="display_badge_text"><?= $submission['risk_category'] ?> (<?= number_format((float)$submission['risk_percentage'], 1) ?>%)</span>
                                    </span>
                                </div>
                                <span class="text-muted small mt-1">
                                    <i class="fas fa-user-circle me-1"></i> <?= htmlspecialchars($submission['contact_person']) ?> &nbsp;&bull;&nbsp; <i class="fas fa-envelope me-1"></i> <?= htmlspecialchars($submission['email']) ?>
                                </span>
                                <!--<div class="mt-1">-->
                                <!--    <button type="button" class="btn btn-sm btn-outline-dark rounded-pill px-3 shadow-sm transition-all hover-scale" data-bs-toggle="modal" data-bs-target="#vendorProfileModal">-->
                                <!--        <i class="fas fa-user-circle me-1"></i> View Quick Profile-->
                                <!--    </button>-->
                                <!--</div>-->
                            </div>
                        </div>

                        <?php
                        // The vendor's own Model Bisnis answers, shown BEFORE the
                        // Sub-Category dropdown — company_type below is derived from
                        // these, not chosen by the vendor. Mirrors review_pq.php in
                        // modules/sourcing.
                        $bm_labels = business_model_labels($vendor['business_model'] ?? null);
                        ?>
                        <div class="mt-2 p-3 bg-white rounded border border-secondary border-opacity-25">
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
                                    Kategori turunan: <strong class="text-dark"><?= htmlspecialchars($submission['company_type']) ?></strong>
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

                        <div class="mt-2 p-3 bg-light rounded border border-primary border-opacity-25">
                            <label class="form-label fw-bold text-primary small mb-2"><i class="fas fa-sitemap me-1"></i> Vendor Sub-Category <span class="text-danger">*</span></label>
                            <?php if ($is_editable): ?>
                                <select name="sub_company_type" id="sub_company_type_select" class="form-select border-primary shadow-sm fw-bold" required onchange="onSubCategoryChange();">
                                    <option value="">-- Select Specific Classification --</option>
                                    <?php
                                    if ($uses_perseorangan_pq) {
                                        // Standalone sole-proprietor company_type, or a self-declared
                                        // UMKM vendor -> always the Perseorangan question set.
                                        $opts = ['Perseorangan'];
                                    } elseif ($submission['company_type'] == 'Contractor') {
                                        // Perseorangan legal entity -> only Individual / Foreman.
                                        // Badan Usaha (or any other entity type) -> full Contractor option set.
                                        $opts = ($legal_entity === 'Perseorangan')
                                            ? ['Individual / Foreman']
                                            : ['Project', 'Retail & Maintenance', 'Individual / Foreman'];
                                    } else {
                                        $opts = ['Principal', 'Distributor', 'Retail', 'Service'];
                                    }
                                    $selected_sub = trim($submission['sub_company_type'] ?? '');
                                    foreach($opts as $opt) {
                                        $is_selected = false;
                                        if (strcasecmp($selected_sub, trim($opt)) == 0) $is_selected = true;
                                        elseif (stripos($opt, 'Foreman') !== false && stripos($selected_sub, 'Foreman') !== false) $is_selected = true; 
                                        $sel = $is_selected ? 'selected' : '';
                                        echo "<option value='$opt' $sel>$opt</option>";
                                    }
                                    ?>
                                </select>
                                <div class="form-text small mt-2 text-primary"><i class="fas fa-info-circle me-1"></i> Selecting an option dynamically reveals the correct questions below. Remember to click Save!</div>
                            <?php else: ?>
                                <div class="form-control bg-white fw-bold text-dark border-primary shadow-sm"><?= htmlspecialchars($submission['sub_company_type'] ?: 'Unclassified') ?></div>
                                <!-- Hidden input crucial for JS updateNaRows() to still work correctly in Read-Only mode -->
                                <input type="hidden" id="sub_company_type_select" value="<?= htmlspecialchars($submission['sub_company_type'] ?: '') ?>">
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="card-body p-0">
                        <?php foreach ($review_data as $section => $answers): ?>
                            <div class="bg-light px-3 py-2 border-bottom fw-bold small text-uppercase text-secondary section-header"><?= $section ?></div>
                            <div class="table-responsive section-table-wrapper">
                                <table class="table table-bordered table-hover align-middle mb-0">
                                    <thead class="table-light small text-muted text-uppercase">
                                        <tr>
                                            <th class="ps-3" style="width: 30%">Question / Criteria</th>
                                            <th style="width: 25%">Vendor Response / Evaluation</th>
                                            <th style="width: 22%">Sourcing Notes</th>
                                            <th style="width: 23%">VM Feedback <i class="fas fa-comment-dots text-info ms-1"></i></th>
                                            <th class="text-center" style="width: 5%">Flag</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($answers as $ans): 
                                            $is_applicable = false;
                                            $current_sub_val = $pq_weight_target;
                                            $sv_targets = $ans['all_sub_targets'];
                                            // The row's data-sv-target attribute is re-checked client-side
                                            // (updateNaRows()) against the dropdown's own value, which is
                                            // 'Perseorangan' for this company_type — translate the raw
                                            // 'Individual / Foreman' pq_weight label so that JS match still
                                            // works after selecting/reloading the sub-category.
                                            $sv_targets_display = $uses_perseorangan_pq
                                                ? str_ireplace('Individual / Foreman', 'Perseorangan', $sv_targets)
                                                : $sv_targets;

                                            if (empty($sv_targets) || (!empty($current_sub_val) && stripos($sv_targets, $current_sub_val) !== false)) $is_applicable = true;

                                            $q_text_lower = strtolower($ans['question_text']);
                                            $is_financial = (strpos($q_text_lower, 'income') !== false || strpos($q_text_lower, 'pendapatan') !== false || strpos($q_text_lower, 'capital') !== false || strpos($q_text_lower, 'modal') !== false);
                                            $is_legal = (stripos($ans['question_text'], 'legalitas perusahaan') !== false || stripos($ans['question_text'], 'legalitas') !== false);

                                            $display_label = "Score: " . ($ans['selected_score'] ?? 0);
                                            if (!$is_financial && $ans['selected_score'] !== null && !empty($ans['help_text'])) {
                                                preg_match('/\(' . $ans['selected_score'] . '\)\s*(.*?)(?=\(\d+\)|$)/s', $ans['help_text'], $matches);
                                                if (!empty($matches[1])) $display_label = trim($matches[1]) . " (" . $ans['selected_score'] . ")";
                                            }
                                            
                                            // Visual row flag initially
                                            $row_class = !$is_applicable ? "d-none" : "";
                                            if ($ans['needs_revision']) $row_class .= " row-flagged";
                                        ?>
                                            <tr class="<?= trim($row_class) ?>" id="row_<?= $ans['question_id'] ?>" data-sv-target="<?= htmlspecialchars($sv_targets_display ?? '') ?>">
                                                
                                                <td class="ps-3">
                                                    <div class="fw-bold small mb-1 text-dark"><?= htmlspecialchars($ans['question_text']) ?></div>
                                                    <div class="small text-muted mt-2" style="font-size:0.75rem;">
                                                        <span class="badge bg-light text-dark border weight-label"><i class="fas fa-balance-scale"></i> Weight: <?= (float)$ans['weight'] ?></span>
                                                    </div>

                                                    <?php if ($is_legal): ?>
                                                        <?php if ($is_editable): ?>
                                                            <div class="mb-2 mt-2">
                                                                <button type="button" class="btn btn-sm btn-outline-danger w-100 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#vendorProfileModal" onclick="initLegalNotes(<?= $ans['question_id'] ?>)">
                                                                    <i class="fas fa-search-plus me-1"></i> Periksa & Beri Catatan
                                                                </button>
                                                            </div>
                                                            <textarea name="reviewer_notes[<?= $ans['question_id'] ?>]" id="legal_notes_data_<?= $ans['question_id'] ?>" class="d-none"><?= htmlspecialchars($ans['reviewer_notes'] ?? '') ?></textarea>
                                                            <div id="legal_notes_summary_<?= $ans['question_id'] ?>" class="small text-danger fw-bold mt-1 bg-danger bg-opacity-10 p-2 rounded d-none border border-danger border-opacity-25" style="line-height:1.4;"></div>
                                                        <?php else: ?>
                                                            <div class="mt-2">
                                                                <button type="button" class="btn btn-sm btn-light border-primary text-primary fw-bold rounded-pill px-3 shadow-sm hover-scale py-1 mb-2" style="font-size: 0.65rem;" data-bs-toggle="modal" data-bs-target="#vendorProfileModal" onclick="initLegalNotes(<?= $ans['question_id'] ?>)">
                                                                    <i class="fas fa-external-link-alt me-1"></i> Lihat Catatan Dokumen
                                                                </button>
                                                            </div>
                                                            <textarea id="legal_notes_data_<?= $ans['question_id'] ?>" class="d-none"><?= htmlspecialchars($ans['reviewer_notes'] ?? '') ?></textarea>
                                                            <div id="legal_notes_summary_<?= $ans['question_id'] ?>" class="small text-danger fw-bold mt-1 bg-danger bg-opacity-10 p-2 rounded d-none border border-danger border-opacity-25" style="line-height:1.4;"></div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>

                                                <td>
                                                    <?php if ($is_editable): ?>
                                                        <!-- SHOW VENDOR REMARKS -->
                                                        <?php if (!empty($ans['remarks']) && !$is_financial): ?>
                                                            <div class="mb-3 p-2 bg-light rounded border small">
                                                                <div class="fw-bold text-muted mb-1 text-uppercase" style="font-size: 0.6rem;">Vendor Response:</div>
                                                                <div class="text-dark">
                                                                    <?php 
                                                                    $lines = explode("\n", $ans['remarks']);
                                                                    if (count($lines) > 1): ?>
                                                                        <ul class="ps-3 mb-0"><?php foreach($lines as $line): if(trim($line)) echo "<li>".htmlspecialchars(trim($line))."</li>"; endforeach; ?></ul>
                                                                    <?php else: ?>
                                                                        <?php 
                                                                            if (is_numeric(str_replace(['.', ','], '', trim($ans['remarks']))) && stripos($ans['question_text'], 'clients') === false && stripos($ans['question_text'], 'branches') === false) echo '<span class="fw-bold">Rp ' . number_format((float)str_replace(['.', ','], '', $ans['remarks']), 0, ',', '.') . '</span>';
                                                                            else echo nl2br(htmlspecialchars($ans['remarks']));
                                                                        ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!$is_financial && !empty($ans['help_text'])): ?>
                                                            <div class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 mb-2 text-wrap text-start current-score-label" style="line-height:1.4; font-size:0.75rem;">
                                                                <i class="fas fa-info-circle me-1"></i> Current: <?= htmlspecialchars($display_label) ?>
                                                            </div>
                                                            <select name="scores[<?= $ans['question_id'] ?>]" class="form-select form-select-sm score-dropdown">
                                                                <option value="" disabled <?= $ans['selected_score'] === null ? 'selected' : '' ?>>Select an option...</option>
                                                                <?php 
                                                                preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $ans['help_text'], $matches, PREG_SET_ORDER);
                                                                foreach ($matches as $m) {
                                                                    $sel = ($m[1] == $ans['selected_score']) ? 'selected' : '';
                                                                    echo "<option value='{$m[1]}' $sel>({$m[1]}) " . trim($m[2]) . "</option>";
                                                                }
                                                                ?>
                                                            </select>
                                                        <?php elseif ($is_financial): ?>
                                                            <div class="mb-2 p-2 bg-light rounded border small">
                                                                <label class="fw-bold text-muted mb-1 text-uppercase d-block" style="font-size: 0.6rem;">Vendor Amount (Editable):</label>
                                                                <div class="input-group input-group-sm">
                                                                    <span class="input-group-text bg-white border-end-0 fw-bold">Rp</span>
                                                                    <input type="text" name="remarks[<?= $ans['question_id'] ?>]" class="form-control border-start-0 currency-input fw-bold" value="<?= htmlspecialchars($ans['remarks'] ?? '') ?>">
                                                                </div>
                                                                <div class="form-text" style="font-size: 0.65rem;">Changing this value will recalculate the score automatically upon save.</div>
                                                            </div>
                                                            <?php if ($ans['selected_score'] !== null): ?>
                                                                <div class="badge bg-info financial-score-badge mt-1 text-white border border-info shadow-sm w-100 text-start py-2"><i class="fas fa-calculator me-1"></i> Calculated Score: <?= $ans['selected_score'] ?></div>
                                                            <?php else: ?>
                                                                <!-- Placeholder for JS dynamically adding the badge -->
                                                                <div class="badge bg-info financial-score-badge mt-1 text-white border border-info shadow-sm w-100 text-start py-2 d-none"></div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php if (empty($ans['remarks'])): ?><div class="small text-muted fst-italic mb-2">- No Response Provided -</div><?php endif; ?>
                                                        <?php endif; ?>
                                                    
                                                    <?php else: ?>
                                                        <!-- READ ONLY DISPLAY -->
                                                        <?php if (!empty($ans['remarks']) && !$is_financial): ?>
                                                            <div class="mb-3 p-2 bg-light rounded border small">
                                                                <div class="fw-bold text-muted mb-1 text-uppercase" style="font-size: 0.6rem;">Vendor Response:</div>
                                                                <div class="text-dark">
                                                                    <?php 
                                                                    $lines = explode("\n", $ans['remarks']);
                                                                    if (count($lines) > 1): ?>
                                                                        <ul class="ps-3 mb-0"><?php foreach($lines as $line): if(trim($line)) echo "<li>".htmlspecialchars(trim($line))."</li>"; endforeach; ?></ul>
                                                                    <?php else: echo nl2br(htmlspecialchars($ans['remarks'])); endif; ?>
                                                                </div>
                                                            </div>
                                                        <?php endif; ?>

                                                        <?php if (!$is_financial && !empty($ans['help_text'])): ?>
                                                            <div class="badge bg-light text-dark border border-secondary border-opacity-25 px-3 py-2 mb-2 text-wrap text-start" style="line-height:1.4; font-size:0.8rem;">
                                                                <i class="fas fa-check-circle text-success me-1"></i> <?= htmlspecialchars($display_label) ?>
                                                            </div>
                                                        <?php elseif ($is_financial): ?>
                                                            <div class="mb-2 p-2 bg-light rounded border small">
                                                                <div class="fw-bold text-muted mb-1 text-uppercase" style="font-size: 0.6rem;">Vendor Amount:</div>
                                                                <div class="text-dark">
                                                                    <span class="fw-bold fs-6 text-primary">Rp <?= number_format((float)str_replace(['.', ','], '', $ans['remarks'] ?? '0'), 0, ',', '.') ?></span>
                                                                </div>
                                                            </div>
                                                            <?php if ($ans['selected_score'] !== null): ?>
                                                                <div class="badge bg-info financial-score-badge mt-1 text-white border border-info shadow-sm w-100 text-start py-2">
                                                                    <i class="fas fa-calculator me-1"></i> Calculated Score: <?= $ans['selected_score'] ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php if (empty($ans['remarks'])): ?>
                                                                <div class="small text-muted fst-italic mb-2">- No Response Provided -</div>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
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

                                                <td>
                                                    <?php if (!$is_legal): ?>
                                                        <?php if ($is_editable): ?>
                                                            <textarea name="reviewer_notes[<?= $ans['question_id'] ?>]" class="form-control form-control-sm" rows="2" placeholder="Instruction for vendor or VM..."><?= htmlspecialchars($ans['reviewer_notes'] ?? '') ?></textarea>
                                                        <?php else: ?>
                                                            <?php if (!empty($ans['reviewer_notes'])): ?>
                                                                <div class="small text-dark fst-italic border-start border-3 border-secondary ps-2 mb-1"><?= nl2br(htmlspecialchars($ans['reviewer_notes'])) ?></div>
                                                            <?php else: ?>
                                                                <span class="text-muted small opacity-50 d-block mb-1">-</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                    
                                                    <?php 
                                                        $show_badge = (!empty($ans['reviewer_name']) && $ans['reviewer_id'] !== null);
                                                    ?>
                                                    <div class="text-start mt-2 reviewer-badge-container <?= $show_badge ? '' : 'd-none' ?>">
                                                        <span class="badge bg-light text-secondary border border-secondary border-opacity-25 shadow-sm" style="font-size: 0.65rem;">
                                                            <i class="fas fa-user-edit me-1"></i> <span class="reviewer-name-text"><?= htmlspecialchars($ans['reviewer_name'] ?? '') ?></span>
                                                        </span>
                                                    </div>
                                                </td>
                                                
                                                <td class="bg-light-subtle">
                                                    <?php if (!empty($ans['vm_row_notes'])): ?>
                                                        <div class="small text-dark fst-italic border-start border-3 border-info ps-2 mb-2"><?= nl2br(htmlspecialchars($ans['vm_row_notes'])) ?></div>
                                                    <?php else: ?>
                                                        <span class="text-muted small opacity-50 d-block mb-2">-</span>
                                                    <?php endif; ?>
                                                    <?php if ($ans['vm_needs_revision']): ?><span class="badge bg-warning text-dark mt-1"><i class="fas fa-exclamation-triangle me-1"></i> Flagged by VM</span><?php endif; ?>
                                                </td>

                                                <td class="text-center">
                                                    <?php if ($is_editable): ?>
                                                        <input type="checkbox" id="flag_<?= $ans['question_id'] ?>" name="revision_flags[<?= $ans['question_id'] ?>]" value="1" class="flag-checkbox" <?= $ans['needs_revision'] ? 'checked' : '' ?> onchange="this.closest('tr').classList.toggle('row-flagged', this.checked)">
                                                        <label for="flag_<?= $ans['question_id'] ?>" class="flag-label" title="Click to Flag"><i class="fas fa-flag"></i></label>
                                                    <?php else: ?>
                                                        <i class="fas fa-flag <?= $ans['needs_revision'] ? 'text-danger' : 'text-muted opacity-25' ?>"></i>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($is_editable): ?>
                    <div class="card-footer bg-white py-3 sticky-bottom border-top">
                        <div class="d-flex justify-content-between align-items-center">
                            <small class="text-muted"><i class="fas fa-info-circle"></i> Save changes before deciding.</small>
                            <button type="submit" class="btn btn-primary px-4 fw-bold shadow-sm" id="save_btn">
                                <i class="fas fa-save me-2"></i> Save Rows & Recalculate
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="col-lg-3">
            <div class="card shadow border-0 sticky-top" style="top: 80px; z-index: 100;">
                <div class="card-header bg-gradient-primary text-white py-3">
                    <h6 class="mb-0 fw-bold small text-uppercase text-white">Final Decision</h6>
                </div>
                <div class="card-body p-3">
                    
                    <!-- NEW ACTIVITY LOG BUTTON -->
                    <button type="button" class="btn btn-outline-info text-dark w-100 fw-bold mb-3 shadow-sm py-2" data-bs-toggle="modal" data-bs-target="#auditLogModal">
                        <i class="fas fa-history me-2"></i> View Edit History
                    </button>
                    <hr class="my-3 opacity-10">

                    <?php if ($is_editable): ?>
                        <form action="review_action.php" method="POST" id="decisionForm">
                            <input type="hidden" name="submission_id" value="<?= $submission['id'] ?>">
                            <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
                            <input type="hidden" name="action" id="action_hidden_input" value="">
                            
                            <?php
                                // High/Critical vendors must document the REASON for registration.
                                // The vendor TYPE is canonical from the registration enums (never
                                // re-typed) and composed into sourcing_notes (see
                                // includes/sourcing_note_helper.php).
                                $note_requires_structure = sourcingNoteRequiresStructure($risk_category);
                                $registered_vendor_type  = formatRegisteredVendorType($submission['company_type'] ?? '', $submission['sub_company_type'] ?? '');
                                $note_parsed = parseSourcingNote($submission['sourcing_notes'] ?? '');
                                $prefill_reason = $note_parsed['is_structured']
                                    ? $note_parsed['reason']
                                    : ($submission['sourcing_notes'] ?? '');
                            ?>
                            <div class="mb-3">
                                <label class="form-label fw-bold small text-muted">SUMMARY NOTE</label>
                                <!-- Both variants are always in the DOM and toggled by class, so a live
                                     risk-category change from "Save Rows & Recalculate" (submitFormAjax's
                                     AJAX response, no page reload) can flip this without a stale render. -->
                                <div id="note_structured_extra" class="<?= $note_requires_structure ? '' : 'd-none' ?>">
                                    <div class="alert alert-warning py-2 px-2 small mb-2" style="font-size:0.72rem;">
                                        <i class="fas fa-exclamation-triangle me-1"></i>
                                        Risiko vendor <strong id="note_structured_risk_label"><?= htmlspecialchars($risk_category) ?></strong> — wajib mendokumentasikan <strong>alasan pendaftaran</strong> vendor ini.
                                    </div>
                                    <div class="bg-light border rounded p-2 mb-2 small text-muted" style="font-size:0.72rem;">
                                        <i class="fas fa-tag me-1"></i>Vendor type:
                                        <strong class="text-dark"><?= htmlspecialchars($registered_vendor_type !== '' ? $registered_vendor_type : '-') ?></strong>
                                    </div>
                                    <label class="form-label fw-bold small text-muted mb-1">REASON FOR REGISTRATION <span class="text-danger">*</span></label>
                                    <textarea id="registration_reason_input" class="form-control" rows="4" placeholder="Jelaskan alasan vendor ini didaftarkan"><?= htmlspecialchars($prefill_reason) ?></textarea>
                                </div>
                                <!-- Actual posted field: the plain summary box when unstructured, or (hidden)
                                     composed from the reason above on submit when structured. -->
                                <textarea name="sourcing_notes" id="sourcing_notes_input" class="form-control <?= $note_requires_structure ? 'd-none' : '' ?>" rows="4" placeholder="General summary..." <?= $note_requires_structure ? '' : 'required' ?>><?= htmlspecialchars($submission['sourcing_notes'] ?? '') ?></textarea>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-success py-2 fw-bold small" onclick="triggerDecisionModal('approve_sourcing')">
                                    <i class="fas fa-check-circle me-2"></i> Submit to VM
                                </button>
                                <button type="button" class="btn btn-outline-warning text-dark py-2 fw-bold small" onclick="triggerDecisionModal('request_revision')">
                                    <i class="fas fa-undo me-2"></i> Request Revision
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <!-- Show Sourcing Notes in Read-Only Mode -->
                        <div class="mb-3">
                            <label class="form-label fw-bold small text-muted">SUMMARY NOTE</label>
                            <div class="p-2 bg-light border rounded small text-dark" style="line-height: 1.5;">
                                <?= !empty($submission['sourcing_notes']) ? nl2br(htmlspecialchars($submission['sourcing_notes'])) : '<span class="text-muted fst-italic">No summary note provided.</span>' ?>
                            </div>
                        </div>
                        <div class="alert alert-secondary text-center small py-3 mb-0">
                            <i class="fas fa-lock mb-2 d-block fa-2x opacity-50"></i>
                            <span class="d-block mb-1">Assessment Locked</span>
                            <strong class="text-dark"><?= strtoupper(str_replace('_', ' ', $submission['status'])) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="vendorProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-gradient-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-building me-2"></i> Quick Profile & Dokumen: <?= htmlspecialchars($vendor['company_name']) ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <div class="row g-4">
                    <div class="col-lg-5">
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
                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Contact Person</label>
                                    <div class="fw-bold text-dark fs-6"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['contact_person']) ?></div>
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
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Phone / Email</label>
                                    <div class="fw-bold text-dark"><i class="fas fa-phone text-secondary me-1"></i> <?= htmlspecialchars($vendor['phone']) ?></div>
                                    <div class="fw-bold text-primary mt-1"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email']) ?></div>
                                </div>
                                <div class="mb-4">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Company Address <span class="text-muted fw-normal text-lowercase">(dari NPWP)</span></label>
                                    <div class="fw-bold text-dark" style="line-height:1.5;"><i class="fas fa-map-marker-alt text-secondary me-1"></i> <?= !empty($vendor['company_address']) ? nl2br(htmlspecialchars($vendor['company_address'])) : '-' ?></div>
                                </div>
                                <h6 class="text-primary fw-bold text-uppercase mt-4 mb-3 small border-bottom pb-2"><i class="fas fa-file-invoice-dollar me-2"></i> Informasi Pajak</h6>
                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">Status PKP</label>
                                    <div class="fw-bold">
                                        <?php if (!empty($vendor['is_sppkp'])): ?>
                                            <span class="badge bg-success">PKP</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Non-PKP</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">WHT Tax Type</label>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($vendor['wht_tax_type'] ?: '-') ?></div>
                                </div>
                                <div class="mb-3">
                                    <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.65rem;">WHT Tax Code</label>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($vendor['wht_tax_code'] ?: '-') ?></div>
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
                    
                    <div class="col-lg-7">
                        <div class="card shadow-sm border-0 h-100">
                            <div class="card-body p-4">
                                <h6 class="text-success fw-bold text-uppercase mb-3 small border-bottom pb-2"><i class="fas fa-folder-open me-2"></i> Review Dokumen Legalitas</h6>
                                
                                <div class="mb-3 p-3 bg-light rounded border border-warning shadow-sm">
                                    <label class="form-label small fw-bold text-dark"><i class="fas fa-pen me-2 text-warning"></i>Catatan Umum Legalitas (Untuk Vendor)</label>
                                    <?php if ($is_editable): ?>
                                        <textarea id="general_legal_note" class="form-control form-control-sm border-warning" rows="2" placeholder="Tuliskan jika ada instruksi umum terkait legalitas (opsional)..." oninput="syncLegalNotes()"></textarea>
                                    <?php else: ?>
                                        <div id="general_legal_note" class="small text-dark fst-italic p-2 bg-white rounded border border-secondary border-opacity-25"></div>
                                    <?php endif; ?>
                                </div>

                                <div class="d-flex flex-column gap-1 bg-white rounded border p-2">
                                    <?php 
                                    $entity_type = $vendor['legal_entity_type'] ?? '';
                                    $comp_type   = strtolower($vendor['company_type'] ?? '');

                                    // 1. Dokumen Dasar
                                    $legal_docs = [
                                        'KTP Direktur' => [$vendor['director_id_card_file'] ?? '', null, ''],
                                        'NPWP' => [$vendor['npwp_path'] ?? '', $vendor['npwp_number'] ?? '', $vendor['npwp_status'] ?? ''],
                                        'Buku Rekening' => [$vendor['bank_cover_path'] ?? '', null, ''],
                                        'Surat Pernyataan Perbedaan Nama Rekening' => [$vendor['bank_holder_statement_letter_file'] ?? '', null, ''],
                                        'Pakta Integritas Kawan Lama Group' => [$vendor['business_ethics_commitment_file'] ?? '', null, ''],
                                        'Tax Statement Letter' => [$vendor['tax_statement_letter_file'] ?? '', null, ''],
                                        'Profil Perusahaan' => [$vendor['company_profile_file'] ?? '', null, '']
                                    ];

                                    // 2. Dokumen Khusus BADAN USAHA
                                    if ($entity_type === 'Badan Usaha') {
                                        $legal_docs['NIB'] = [$vendor['nib_path'] ?? '', $vendor['nib_number'] ?? '', $vendor['nib_status'] ?? ''];
                                        $legal_docs['Akta Pendirian'] = [$vendor['deed_of_establishment_file'] ?? '', null, ''];
                                        $legal_docs['SK Pengesahan'] = [$vendor['decree_of_legal_entity_file'] ?? '', null, ''];
                                        $legal_docs['Akta Perubahan'] = [$vendor['deed_of_amendment_file'] ?? '', null, ''];
                                        $legal_docs['SPPKP'] = [$vendor['sppkp_path'] ?? '', null, ''];
                                    }

                                    // 3. Dokumen Khusus KONTRAKTOR 
                                    if (strpos($comp_type, 'contractor') !== false || strpos($comp_type, 'kontraktor') !== false) {
                                        $legal_docs['Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)'] = [$vendor['business_entity_cert_file'] ?? '', null, ''];
                                    }
                                    
                                    foreach($legal_docs as $label => $data) {
                                        renderModalDocRow($label, $data[0], $path_to_root, $is_editable, $data[1], $data[2]);
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer bg-white border-top-0 py-3">
                <a href="view_profile.php?id=<?= $vendor['id'] ?>" target="_blank" class="btn btn-outline-primary rounded-pill px-4 me-auto fw-bold">
                    <i class="fas fa-external-link-alt me-1"></i> Open Full Profile
                </a>
                <button type="button" class="btn btn-dark rounded-pill px-5 fw-bold" data-bs-dismiss="modal">Tutup / Selesai Review</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="decisionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 py-3" id="decisionModalHeader">
                <h5 class="modal-title fw-bold text-white" id="decisionModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-4"><i class="fas fa-question-circle fa-4x opacity-50" id="decisionModalIcon"></i></div>
                <h4 class="fw-bold text-dark mb-3" id="decisionModalHeading">Are you sure?</h4>
                <p class="text-muted" id="decisionModalText">Please <b>ensure you have reviewed all changes down to the bottom of the page</b> before proceeding.</p>
                <p class="text-muted" id="decisionModalText"><b>Ready to Submit?</b></p>
                <div class="alert alert-info small text-start d-none" id="decisionModalWarning"><i class="fas fa-info-circle me-1"></i> <strong>Note:</strong> Any unsaved changes will be automatically saved before submission.</div>
                <div class="alert alert-warning small text-start d-none" id="decisionModalK2Warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Perhatian — Persetujuan Formal:</strong><br>
                    Dengan menyetujui ini, masukan Anda akan dicatat sebagai 
                    <strong>permintaan resmi kepada Admin</strong> untuk mendaftarkan vendor ini 
                    ke dalam <strong>sistem Agora</strong>. Pastikan data sudah benar sebelum melanjutkan.
                </div>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn fw-bold px-4 rounded-pill" id="decisionModalBtn" onclick="executeDecision()">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- VALIDATION MODAL (app-consistent replacement for native alert) -->
<div class="modal fade" id="validationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning border-0 py-3">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-exclamation-triangle me-2"></i>Perhatian</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-3"><i class="fas fa-exclamation-circle fa-3x text-warning opacity-75"></i></div>
                <p class="text-muted mb-0" id="validationModalText"></p>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-warning fw-bold px-4 rounded-pill text-dark" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- AUDIT LOG MODAL -->
<div class="modal fade" id="auditLogModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-gradient-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-history me-2"></i> Assessment Edit History</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <?php if (empty($audit_logs)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-clipboard-list fa-3x mb-3 opacity-25"></i>
                        <p class="mb-0">No edit history found for this submission yet.</p>
                    </div>
                <?php else: ?>
                    <div class="modern-timeline mt-3">
                        <?php foreach($audit_logs as $idx => $log): 
                            // Parse actions string into array
                            $actions = explode(' | ', $log['action_detail']);
                            
                            // Generate initials for avatar
                            $initials = strtoupper(substr(trim($log['full_name']), 0, 2));
                        ?>
                            <div class="modern-timeline-item">
                                <div class="modern-timeline-marker shadow-sm">
                                    <i class="fas fa-check"></i> 
                                </div>
                                <div class="modern-timeline-content">
                                    <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                                        <div class="fw-bold text-dark d-flex align-items-center">
                                            <div class="avatar-circle me-3 shadow-sm">
                                                <?= $initials ?>
                                            </div>
                                            <?= htmlspecialchars($log['full_name']) ?>
                                        </div>
                                        <div class="small text-muted fw-medium border rounded-pill px-3 py-1 bg-light">
                                            <i class="far fa-clock me-1"></i> <?= date('d M Y, H:i', strtotime($log['created_at'])) ?>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3 px-2">
                                        <?php if($log['question_text']): ?>
                                            <div class="text-secondary small text-uppercase fw-bold mb-1" style="font-size:0.65rem;">Row / Question Edited:</div>
                                            <div class="text-dark fw-medium" style="font-size:0.9rem; line-height:1.5;">"<?= htmlspecialchars($log['question_text']) ?>"</div>
                                        <?php else: ?>
                                            <div class="text-secondary small text-uppercase fw-bold mb-2" style="font-size:0.65rem;">General Assessment Update</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="d-flex flex-wrap gap-2 px-2">
                                        <?php foreach($actions as $act): ?>
                                            <?php 
                                                // Dynamic styling based on action keyword
                                                $act_lower = strtolower($act);
                                                $act_bg = 'bg-light text-dark border';
                                                $act_icon = 'fa-pen';
                                                
                                                if (strpos($act_lower, 'score') !== false) { 
                                                    $act_bg = 'bg-primary-subtle text-primary border-primary-subtle'; 
                                                    $act_icon = 'fa-star'; 
                                                }
                                                if (strpos($act_lower, 'note') !== false) { 
                                                    $act_bg = 'bg-info-subtle text-info-emphasis border-info-subtle'; 
                                                    $act_icon = 'fa-comment-dots'; 
                                                }
                                                if (strpos($act_lower, 'flagged') !== false) { 
                                                    $act_bg = 'bg-danger-subtle text-danger border-danger-subtle'; 
                                                    $act_icon = 'fa-flag'; 
                                                }
                                                if (strpos($act_lower, 'removed') !== false) { 
                                                    $act_bg = 'bg-success-subtle text-success border-success-subtle'; 
                                                    $act_icon = 'fa-flag-checkered'; 
                                                }
                                                if (strpos($act_lower, 'amount') !== false || strpos($act_lower, 'response') !== false) {
                                                    $act_bg = 'bg-warning-subtle text-warning-emphasis border-warning-subtle';
                                                    $act_icon = 'fa-file-invoice-dollar';
                                                }
                                                if (strpos($act_lower, 'category') !== false || strpos($act_lower, 'classification') !== false) {
                                                    $act_bg = 'bg-dark bg-opacity-10 text-dark border-dark border-opacity-25';
                                                    $act_icon = 'fa-sitemap';
                                                }
                                            ?>
                                            <span class="badge <?= $act_bg ?> fw-medium py-2 px-3 shadow-sm rounded-4 text-start text-wrap" style="font-size: 0.75rem; line-height: 1.4; max-width: 100%;">
                                                <i class="fas <?= $act_icon ?> me-1 opacity-75"></i> <?= htmlspecialchars(trim($act)) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer border-0 bg-white py-3">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-dismiss="modal">Close History</button>
            </div>
        </div>
    </div>
</div>

<script>
    let currentLegalQid = null;

    function initLegalNotes(qid) {
        if(!qid) return;
        currentLegalQid = qid;
        
        let valBox = document.getElementById('legal_notes_data_' + qid);
        if(!valBox) return;
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
        
        let genNote = document.getElementById('general_legal_note');
        if (genNote) {
            if (genNote.tagName === 'TEXTAREA') genNote.value = data.general || "";
            else genNote.innerText = data.general || "- Tidak ada catatan umum -";
        }

        let isEditable = <?= $is_editable ? 'true' : 'false' ?>;

        document.querySelectorAll('[id^="wrapper_doc_"]').forEach(wrapper => {
            let docKey = wrapper.id.replace('wrapper_doc_', '');
            if (isEditable) {
                let cb = document.getElementById('flag_doc_' + docKey);
                let noteInput = document.getElementById('note_doc_' + docKey);
                let docName = noteInput.getAttribute('data-doc');
                
                if (data.docs[docName]) {
                    cb.checked = true;
                    noteInput.value = data.docs[docName];
                    noteInput.classList.remove('d-none');
                } else {
                    cb.checked = false;
                    noteInput.value = '';
                    noteInput.classList.add('d-none');
                }
            } else {
                let readonlyDiv = document.getElementById('readonly_note_' + docKey);
                let docName = readonlyDiv.getAttribute('data-doc');
                let textDisplay = readonlyDiv.querySelector('.note-text-display');
                
                if (data.docs[docName]) {
                    readonlyDiv.classList.remove('d-none');
                    textDisplay.innerText = data.docs[docName];
                } else {
                    readonlyDiv.classList.add('d-none');
                    textDisplay.innerText = '';
                }
            }
        });
        
        updateLegalSummary();
    }

    function toggleDocNote(docKey) {
        let cb = document.getElementById('flag_doc_' + docKey);
        let noteInput = document.getElementById('note_doc_' + docKey);
        if (cb.checked) {
            noteInput.classList.remove('d-none');
            noteInput.focus();
        } else {
            noteInput.classList.add('d-none');
            noteInput.value = '';
        }
        syncLegalNotes();
    }

    function syncLegalNotes() {
        if (!currentLegalQid) return;
        
        let data = { docs: {}, general: "" };
        let genNote = document.getElementById('general_legal_note');
        if (genNote) data.general = genNote.value.trim();

        document.querySelectorAll('.doc-flag-switch').forEach(cb => {
            if (cb.checked) {
                let docName = cb.getAttribute('data-doc');
                let docKey = cb.id.replace('flag_doc_', '');
                let note = document.getElementById('note_doc_' + docKey).value.trim();
                data.docs[docName] = note;
            }
        });

        let jsonStr = JSON.stringify(data);
        if (Object.keys(data.docs).length === 0 && data.general === "") jsonStr = "";

        let targetTextarea = document.getElementById('legal_notes_data_' + currentLegalQid);
        if (targetTextarea) targetTextarea.value = jsonStr;
        
        let mainFlag = document.getElementById('flag_' + currentLegalQid);
        if (mainFlag) {
            const shouldBeChecked = (Object.keys(data.docs).length > 0 || data.general !== "");
            mainFlag.checked = shouldBeChecked;
            mainFlag.closest('tr').classList.toggle('row-flagged', shouldBeChecked);
        }

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
            if (count > 0) html.push(`<i class="fas fa-exclamation-triangle"></i> Terdapat <strong>${count} dokumen</strong> yang ditandai butuh revisi.`);
            if (data.general) html.push(`<i class="fas fa-comment-dots"></i> Ditambah instruksi umum untuk legalitas.`);
            
            if(html.length > 0) {
                summaryDiv.innerHTML = html.join('<br>');
                summaryDiv.classList.remove('d-none');
            } else {
                summaryDiv.innerHTML = '';
                summaryDiv.classList.add('d-none');
            }
        } catch(e) {
            summaryDiv.innerHTML = '<i class="fas fa-comment-dots"></i> ' + val;
            summaryDiv.classList.remove('d-none');
        }
    }

    function formatNumber(n) { return n.replace(/\D/g, "").replace(/\B(?=(\d{3})+(?!\d))/g, "."); }

    function initCurrencyInputs() {
        document.querySelectorAll('.currency-input').forEach(input => {
            if(input.value) input.value = formatNumber(input.value.replace(/\D/g, ''));
            
            // Prevent multiple listener bindings
            if (!input.dataset.listenerAttached) {
                input.addEventListener('input', function(e) {
                    let selectionStart = this.selectionStart;
                    let oldLength = this.value.length;
                    let cleanValue = this.value.replace(/\D/g, '');
                    if (cleanValue === '') this.value = '';
                    else this.value = formatNumber(cleanValue);
                    let newLength = this.value.length;
                    this.setSelectionRange(selectionStart + (newLength - oldLength), selectionStart + (newLength - oldLength));
                });
                input.dataset.listenerAttached = 'true';
            }
        });
    }

    function updateNaRows() {
        const selectEl = document.getElementById('sub_company_type_select');
        if(!selectEl) return;
        const subCat = selectEl.value.toLowerCase();
        
        document.querySelectorAll('tr[id^="row_"]').forEach(row => {
            const svTarget = row.getAttribute('data-sv-target').toLowerCase();

            if (!subCat) {
                // No sub-category chosen yet → only generic rows (empty sv_target) visible
                if (svTarget) row.classList.add('d-none');
                else          row.classList.remove('d-none');
            } else {
                // Sub-category chosen → show generic + matching, hide the rest
                if (svTarget && !svTarget.includes(subCat)) row.classList.add('d-none');
                else                                       row.classList.remove('d-none');
            }
        });
        
        document.querySelectorAll('.section-table-wrapper').forEach(wrapper => {
            const tbody = wrapper.querySelector('tbody');
            const sectionHeader = wrapper.previousElementSibling; 
            const visibleRows = Array.from(tbody.querySelectorAll('tr')).filter(tr => !tr.classList.contains('d-none'));
            if (visibleRows.length === 0) {
                wrapper.style.display = 'none';
                if (sectionHeader && sectionHeader.classList.contains('section-header')) sectionHeader.style.display = 'none';
            } else {
                wrapper.style.display = 'block';
                if (sectionHeader && sectionHeader.classList.contains('section-header')) sectionHeader.style.display = 'block';
            }
        });
    }

    function onSubCategoryChange() {
        // Persist any in-progress edits, then reload so the SQL re-runs
        // with the new sub_company_type and the question list reflects the
        // chosen sub-category.
        document.querySelectorAll('.currency-input').forEach(input => {
            input.value = input.value.replace(/\./g, '');
        });
        const form = document.getElementById('updateForm');
        const btn  = document.getElementById('save_btn');
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Loading questions...';
            btn.disabled  = true;
        }
        fetch('update_assessment.php', { method: 'POST', body: new FormData(form) })
            .then(() => window.location.reload())
            .catch(() => window.location.reload());
    }

    function submitFormAjax(event) {
        if(event) event.preventDefault(); 
        
        // Make sure any legal notes changes are synced before serializing form
        if (currentLegalQid) syncLegalNotes();

        document.querySelectorAll('.currency-input').forEach(input => { input.value = input.value.replace(/\./g, ''); });
        
        const form = document.getElementById('updateForm');
        const btn = document.getElementById('save_btn');
        const originalBtnText = btn ? btn.innerHTML : '';
        
        if (btn) {
            btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving...';
            btn.disabled = true;
        }

        fetch('update_assessment.php', {
            method: 'POST',
            body: new FormData(form)
        })
        .then(response => response.json())
        .then(data => {
            if (btn) {
                btn.innerHTML = originalBtnText;
                btn.disabled = false;
            }
            initCurrencyInputs();
            if (data.status === 'success') {
                const msgBox = document.getElementById('ajax_success_msg');
                if (msgBox) {
                    msgBox.classList.remove('d-none');
                    setTimeout(() => msgBox.classList.add('d-none'), 3000);
                }
                
                const totalScoreEl = document.getElementById('display_total_score');
                if (totalScoreEl) totalScoreEl.innerText = data.total_score;
                
                if (data.max_possible_score !== undefined) {
                    const maxScoreEl = document.getElementById('display_max_score');
                    if(maxScoreEl) maxScoreEl.innerText = data.max_possible_score;
                }
                
                const riskPctEl = document.getElementById('display_risk_percentage');
                if (riskPctEl) riskPctEl.innerText = data.risk_percentage + '%';
                
                // Update Badge inside card
                const riskCard = document.getElementById('display_risk_card');
                const riskLabel = document.getElementById('display_risk_label');
                const riskCategory = document.getElementById('display_risk_category');
                const riskSubLabel = document.getElementById('display_risk_sublabel');
                const riskIcon = document.getElementById('display_risk_icon');
                
                if (riskCard && riskLabel && riskCategory && riskSubLabel && riskIcon) {
                    riskCard.className = 'card h-100 shadow-sm border-0 metric-card';
                    riskLabel.className = 'text-uppercase small fw-bold mb-2 opacity-75';
                    riskCategory.className = 'display-5 fw-bold mb-0';
                    riskSubLabel.className = 'small opacity-75 mt-1';
                    riskIcon.className = 'metric-icon fas';
                    
                    riskCategory.innerText = data.risk_category;

                    if (data.risk_category === 'Very Low') {
                        riskCard.classList.add('bg-gradient-success');
                        riskLabel.classList.add('text-white');
                        riskCategory.classList.add('text-white');
                        riskSubLabel.classList.add('text-white');
                        riskIcon.classList.add('text-white', 'fa-shield-alt');
                    } else if (data.risk_category === 'Low') {
                        riskCard.classList.add('bg-gradient-info');
                        riskLabel.classList.add('text-white');
                        riskCategory.classList.add('text-white');
                        riskSubLabel.classList.add('text-white');
                        riskIcon.classList.add('text-white', 'fa-thumbs-up');
                    } else if (data.risk_category === 'Moderate') {
                        riskCard.classList.add('bg-gradient-warning');
                        riskLabel.classList.add('text-dark');
                        riskCategory.classList.add('text-dark');
                        riskSubLabel.classList.add('text-dark');
                        riskIcon.classList.add('text-dark', 'fa-balance-scale');
                    } else {
                        riskCard.classList.add('bg-gradient-danger');
                        riskLabel.classList.add('text-white');
                        riskCategory.classList.add('text-white');
                        riskSubLabel.classList.add('text-white');
                        riskIcon.classList.add('text-white', 'fa-exclamation-triangle');
                    }
                }

                // Keep the Reason-for-Registration box in sync with the freshly
                // recalculated risk category (see setNoteStructured below) — without
                // this, a recalculation that crosses into High/Critical would flip the
                // badge but leave the mandatory reason box unrendered until reload.
                setNoteStructured(data.risk_category === 'High' || data.risk_category === 'Critical', data.risk_category);

                // Update Header Badge
                const badgeText = document.getElementById('display_badge_text');
                const badgeIcon = document.getElementById('display_badge_icon');
                if (badgeText && badgeIcon) {
                    badgeText.innerText = `${data.risk_category} (${data.risk_percentage}%)`;
                    if (data.risk_category === 'Very Low') {
                        badgeIcon.className = 'fas fa-shield-alt text-success me-1';
                    } else if (data.risk_category === 'Low') {
                        badgeIcon.className = 'fas fa-thumbs-up text-info me-1';
                    } else if (data.risk_category === 'Moderate') {
                        badgeIcon.className = 'fas fa-balance-scale text-warning me-1';
                    } else {
                        badgeIcon.className = 'fas fa-exclamation-triangle text-danger me-1';
                    }
                }

                // Update Individual Row Badges & Financial Scores dynamically
                if (data.questions_data) {
                    for (const [qid, qData] of Object.entries(data.questions_data)) {
                        const row = document.getElementById('row_' + qid);
                        if (!row) continue;
                        
                        // Update Weight Label dynamically
                        const weightLabel = row.querySelector('.weight-label');
                        if (weightLabel && qData.weight !== undefined) {
                            weightLabel.innerHTML = '<i class="fas fa-balance-scale"></i> Weight: ' + qData.weight;
                        }

                        // 1. Reviewer Badge
                        const badgeContainer = row.querySelector('.reviewer-badge-container');
                        const nameText = row.querySelector('.reviewer-name-text');
                        if (badgeContainer && nameText) {
                            if (qData.reviewer_name && qData.has_note_or_flag) {
                                nameText.innerText = qData.reviewer_name;
                                badgeContainer.classList.remove('d-none');
                            } else {
                                badgeContainer.classList.add('d-none');
                            }
                        }

                        // 2. Financial Score Badge
                        if (qData.selected_score !== null) {
                            let finBadge = row.querySelector('.financial-score-badge');
                            if (finBadge) {
                                finBadge.innerHTML = '<i class="fas fa-calculator me-1"></i> Calculated Score: ' + qData.selected_score;
                                finBadge.classList.remove('d-none');
                            } else {
                                const inputGrp = row.querySelector('.input-group');
                                if (inputGrp) {
                                    const newBadge = document.createElement('div');
                                    newBadge.className = 'badge bg-info financial-score-badge mt-1 text-white border border-info shadow-sm w-100 text-start py-2';
                                    newBadge.innerHTML = '<i class="fas fa-calculator me-1"></i> Calculated Score: ' + qData.selected_score;
                                    inputGrp.parentNode.appendChild(newBadge);
                                }
                            }
                        }

                        // 3. Dropdown UI Update
                        const selectEl = row.querySelector('.score-dropdown');
                        const currentLabelEl = row.querySelector('.current-score-label');
                        if (selectEl && qData.help_text) {
                            selectEl.innerHTML = '<option value="" disabled ' + (qData.selected_score === null ? 'selected' : '') + '>Select an option...</option>';
                            const regex = /\((\d+)\)\s*([\s\S]*?)(?=\(\d+\)|$)/g;
                            let match;
                            let displayLabelText = "Score: " + (qData.selected_score || 0);
                            let hasMatches = false;

                            while ((match = regex.exec(qData.help_text)) !== null) {
                                hasMatches = true;
                                const val = match[1];
                                const labelText = match[2].trim();
                                const isSelected = (parseInt(val) === parseInt(qData.selected_score));
                                
                                const opt = document.createElement('option');
                                opt.value = val;
                                opt.selected = isSelected;
                                opt.textContent = '(' + val + ') ' + labelText;
                                selectEl.appendChild(opt);

                                if (isSelected) displayLabelText = labelText + " (" + val + ")";
                            }

                            if (!hasMatches) {
                                for(let i=0; i<=5; i++) {
                                    const isSelected = (i === parseInt(qData.selected_score));
                                    const opt = document.createElement('option');
                                    opt.value = i;
                                    opt.selected = isSelected;
                                    opt.textContent = i;
                                    selectEl.appendChild(opt);
                                }
                            }
                            if (currentLabelEl) currentLabelEl.innerHTML = '<i class="fas fa-info-circle me-1"></i> Current: ' + displayLabelText;
                        }
                    }
                }
                
                // Only reload the page to refresh history logs IF it was explicitly a button click, not an auto-change trigger.
                if (event && event.type === 'submit') {
                    setTimeout(() => { location.reload(); }, 1000);
                } else {
                    updateNaRows();
                }
                
            } else {
                alert('Error saving data: ' + data.message);
            }
        })
        .catch(error => {
            if (btn) {
                btn.innerHTML = originalBtnText;
                btn.disabled = false;
            }
            initCurrencyInputs();
            console.error(error);
        });
    }

    // High/Critical vendors: compose the note from the required Reason plus the
    // canonical (registration-derived) vendor type (mirrors composeSourcingNote()
    // in includes/sourcing_note_helper.php).
    // `let` (not `const`): a live "Save Rows & Recalculate" can flip this mid-session
    // via setNoteStructured() below, without a page reload.
    let SOURCING_NOTE_STRUCTURED = <?= sourcingNoteRequiresStructure($risk_category) ? 'true' : 'false' ?>;
    const SOURCING_REGISTERED_TYPE = <?= json_encode($registered_vendor_type ?? '') ?>;

    // Toggles the note panel between the plain "General summary" box and the
    // required "Reason for Registration" box, carrying over whatever the reviewer
    // already typed so a recalculation crossing the High/Critical threshold never
    // loses their in-progress note. Called from submitFormAjax's success handler.
    function setNoteStructured(isStructured, riskCategory) {
        const extra = document.getElementById('note_structured_extra');
        const notesField = document.getElementById('sourcing_notes_input');
        const reasonField = document.getElementById('registration_reason_input');
        const riskLabel = document.getElementById('note_structured_risk_label');
        if (!extra || !notesField) return;

        const wasStructured = !extra.classList.contains('d-none');
        if (isStructured && !wasStructured) {
            if (reasonField && (reasonField.value || '').trim() === '') {
                reasonField.value = notesField.value || '';
            }
            extra.classList.remove('d-none');
            notesField.classList.add('d-none');
            notesField.removeAttribute('required');
        } else if (!isStructured && wasStructured) {
            if (reasonField && (reasonField.value || '').trim() !== '') {
                notesField.value = reasonField.value;
            }
            extra.classList.add('d-none');
            notesField.classList.remove('d-none');
            notesField.setAttribute('required', 'required');
        }
        if (riskLabel && riskCategory) riskLabel.innerText = riskCategory;
        SOURCING_NOTE_STRUCTURED = isStructured;
    }

    // App-consistent Bootstrap warning modal (replaces native alert()).
    function showValidationModal(message, focusElId) {
        const textEl = document.getElementById('validationModalText');
        if (textEl) textEl.innerText = message;
        const modalEl = document.getElementById('validationModal');
        if (focusElId) {
            modalEl.addEventListener('hidden.bs.modal', function handler() {
                modalEl.removeEventListener('hidden.bs.modal', handler);
                const t = document.getElementById(focusElId);
                if (t) t.focus();
            });
        }
        new bootstrap.Modal(modalEl).show();
    }

    function triggerDecisionModal(actionType) {
        const noteField = document.getElementById('sourcing_notes_input');
        if (SOURCING_NOTE_STRUCTURED) {
            const reasonEl = document.getElementById('registration_reason_input');
            const reason = (reasonEl.value || '').trim();
            if (reason === '') {
                showValidationModal('Please provide the Reason for Registration before proceeding.', 'registration_reason_input');
                return;
            }
            noteField.value = 'Vendor Type: ' + SOURCING_REGISTERED_TYPE + '\n\nReason for Registration: ' + reason;
        } else if (noteField.value.trim() === '') {
            showValidationModal('Please provide a Summary Note before proceeding.', 'sourcing_notes_input');
            return;
        }
        document.getElementById('action_hidden_input').value = actionType;
        
        const header = document.getElementById('decisionModalHeader');
        const title = document.getElementById('decisionModalTitle');
        const btn = document.getElementById('decisionModalBtn');
        const warning = document.getElementById('decisionModalWarning');
        
        header.className = 'modal-header border-0 py-3';
        warning.classList.remove('d-none');
        
        if (actionType === 'approve_sourcing') {
            header.classList.add('bg-success');
            title.innerText = 'Forward to VM';
            btn.className = 'btn fw-bold px-4 rounded-pill btn-success';
            btn.innerText = 'Yes, Forward to VM';
            document.getElementById('decisionModalK2Warning').classList.remove('d-none');
            document.getElementById('decisionModalHeading').innerText = 'Konfirmasi Persetujuan Sourcing';
        } else {
            header.classList.add('bg-warning');
            title.innerText = 'Request Revision';
            btn.className = 'btn fw-bold px-4 rounded-pill btn-warning text-dark';
            btn.innerText = 'Yes, Request Revision';
            document.getElementById('decisionModalK2Warning').classList.add('d-none');
        }
        new bootstrap.Modal(document.getElementById('decisionModal')).show();
    }

    async function executeDecision() {
        const btn = document.getElementById('decisionModalBtn');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i> Saving & Submitting...';
        btn.disabled = true;

        if (currentLegalQid) syncLegalNotes();

        document.querySelectorAll('.currency-input').forEach(input => { input.value = input.value.replace(/\./g, ''); });
        const updateForm = document.getElementById('updateForm');
        
        try {
            const response = await fetch('update_assessment.php', { method: 'POST', body: new FormData(updateForm) });
            const data = await response.json();
            
            if (data.status === 'success') {
                document.getElementById('decisionForm').submit();
            } else {
                alert('Auto-save failed: ' + data.message);
                btn.innerHTML = originalText;
                btn.disabled = false;
                initCurrencyInputs();
            }
        } catch (error) {
            console.error('Error:', error);
            alert('Failed to connect to server during auto-save.');
            btn.innerHTML = originalText;
            btn.disabled = false;
            initCurrencyInputs();
        }
    }
    
    document.addEventListener('DOMContentLoaded', () => {
        updateNaRows();
        initCurrencyInputs();
        
        let legalTAs = document.querySelectorAll('textarea[id^="legal_notes_data_"]');
        if(legalTAs.length > 0) {
            let qid = legalTAs[0].id.replace('legal_notes_data_', '');
            initLegalNotes(qid);
        }
    });
</script>

<script>
function markRead(id, el) {
    fetch('<?= $path_to_root ?>modules/admin/mark_notif_read.php', {
        method: 'POST',
        body: new URLSearchParams({ notif_id: id })
    });
    el.style.opacity = '0.5';
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>