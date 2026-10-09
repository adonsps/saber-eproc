<?php
// modules/sourcing/view_profile.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Allow BOTH Sourcing and VM)
$allowed_roles = ['sourcing', 'vendor_mgt'];
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

$page_title = "Vendor Profile: " . $vendor['company_name'];
include $path_to_root . 'includes/header.php';

// Helper function to render a document row
function renderDocRow($label, $filePath, $rootPath, $ocrNumber = null, $ocrStatus = 'empty') {
    echo '<div class="d-flex justify-content-between align-items-center py-3 border-bottom">';
    echo '  <div>';
    echo '      <div class="fw-bold text-dark small mb-1">' . $label . '</div>';
    
    // Show OCR Info if applicable
    if ($ocrNumber !== null) {
        if (!empty($ocrNumber)) {
            echo '<div class="small text-muted mb-1">Extracted ID: <strong class="text-dark">' . htmlspecialchars($ocrNumber) . '</strong></div>';
            
            // Badge based on OCR status
            if ($ocrStatus === 'verified') {
                echo '<span class="badge bg-success" style="font-size: 0.65rem;"><i class="fas fa-check-circle me-1"></i>Verified</span>';
            } elseif ($ocrStatus === 'invalid') {
                echo '<span class="badge bg-danger" style="font-size: 0.65rem;"><i class="fas fa-times-circle me-1"></i>Invalid/Mismatched</span>';
            } elseif ($ocrStatus === 'unverified') {
                echo '<span class="badge bg-warning text-dark" style="font-size: 0.65rem;"><i class="fas fa-exclamation-triangle me-1"></i>Manually Edited (Unverified)</span>';
            } else {
                echo '<span class="badge bg-secondary" style="font-size: 0.65rem;"><i class="fas fa-database me-1"></i>Saved</span>';
            }
        } else {
             echo '<div class="small text-muted fst-italic">No ID extracted</div>';
        }
    }
    
    echo '  </div>';
    
    if (!empty($filePath)) {
        echo '  <a href="' . $rootPath . 'uploads/' . htmlspecialchars($filePath) . '" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-sm">';
        echo '      <i class="fas fa-external-link-alt me-1"></i> View';
        echo '  </a>';
    } else {
        echo '  <span class="badge bg-light text-muted border fst-italic">Not Uploaded</span>';
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

<?php $nav_bg = ($_SESSION['role'] == 'vendor_mgt') ? 'bg-danger' : 'bg-dark'; ?>
<nav class="navbar navbar-dark <?= $nav_bg ?> mb-4 shadow-sm sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand">
            <i class="fas fa-id-card me-2"></i> Vendor Profile Details
        </span>
        <div class="d-flex align-items-center text-white">
            <a href="javascript:window.history.back()" class="btn btn-sm btn-outline-light me-2">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5" style="max-width: 1000px;">

    <!-- TOP HEADER CARD -->
    <div class="profile-header p-4 p-md-5 mb-4 shadow-sm border border-light">
        <div class="profile-header-content d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <?php 
                    $st = $vendor['registration_status'];
                    $badge_class = 'bg-secondary';
                    $label = strtoupper(str_replace('_', ' ', $st));
                    if ($st == 'registered') $badge_class = 'bg-info text-dark';
                    if ($st == 'invited_pq') $badge_class = 'bg-warning text-dark';
                    if ($st == 'pq_submitted') $badge_class = 'bg-primary';
                    if ($st == 'under_review_vm') $badge_class = 'bg-secondary text-white';
                    if ($st == 'approved') $badge_class = 'bg-success';
                    if (in_array($st, ['revision_requested', 'returned_to_sourcing'])) $badge_class = 'bg-danger';
                ?>
                <div class="mb-2">
                    <span class="badge <?= $badge_class ?> rounded-pill px-3 py-1 shadow-sm border border-light"><?= $label ?></span>
                </div>
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

                    <div class="row mb-4">
                        <div class="col-sm-6 mb-4 mb-sm-0">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Contact Person</label>
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-user-circle text-secondary me-1"></i> <?= htmlspecialchars($vendor['contact_person']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Phone Number</label>
                            <div class="fw-bold text-dark fs-6"><i class="fas fa-phone text-secondary me-1"></i> <?= htmlspecialchars($vendor['phone']) ?></div>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Login Email</label>
                        <div class="fw-bold text-primary"><i class="fas fa-envelope text-secondary me-1"></i> <?= htmlspecialchars($vendor['email']) ?></div>
                    </div>

                    <hr class="my-4 border-light">

                    <h5 class="section-title"><i class="fas fa-university"></i> Banking Details</h5>
                    <div class="row">
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Bank Name</label>
                            <div class="fw-bold text-dark"><?= htmlspecialchars($vendor['bank_name'] ?? '-') ?></div>
                        </div>
                        <div class="col-sm-6 mb-3">
                            <label class="small text-muted fw-bold d-block text-uppercase mb-1" style="font-size: 0.7rem;">Account Number</label>
                            <div class="fw-bold text-dark font-monospace"><?= htmlspecialchars($vendor['bank_account_number'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: DOCUMENTS -->
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body p-4 p-md-5">
                    <h5 class="section-title"><i class="fas fa-folder-open"></i> Uploaded Documents</h5>
                    
                    <div class="d-flex flex-column gap-1">
                        
                        <?php 
                        // General Docs
                        renderDocRow("Company Profile / Brochure", $vendor['company_profile_file'] ?? '', $path_to_root);
                        renderDocRow("Bank Account Cover", $vendor['bank_cover_path'] ?? '', $path_to_root);
                        
                        // ID Docs with OCR checking
                        renderDocRow("NIB Document", $vendor['nib_path'] ?? '', $path_to_root, $vendor['nib_number'] ?? '', $vendor['nib_status'] ?? '');
                        renderDocRow("NPWP (Tax ID)", $vendor['npwp_path'] ?? '', $path_to_root, $vendor['npwp_number'] ?? '', $vendor['npwp_status'] ?? '');
                        
                        // Other Standard Docs
                        renderDocRow("SPPKP (Taxable Entrepreneur)", $vendor['sppkp_path'] ?? '', $path_to_root);
                        
                        // New Legal Docs
                        renderDocRow("Deed of Establishment", $vendor['deed_of_establishment_file'] ?? '', $path_to_root);
                        renderDocRow("Latest Deed of Amendment", $vendor['deed_of_amendment_file'] ?? '', $path_to_root);
                        renderDocRow("Decree of Legal Entity", $vendor['decree_of_legal_entity_file'] ?? '', $path_to_root);
                        renderDocRow("Director ID Card", $vendor['director_id_card_file'] ?? '', $path_to_root);
                        
                        // Exclusive Feature Docs
                        renderDocRow("Business Ethics Commitment", $vendor['business_ethics_commitment_file'] ?? '', $path_to_root);

                        // Conditional: Contractor Only
                        if (stripos($vendor['company_type'], 'contractor') !== false || stripos($vendor['company_type'], 'kontraktor') !== false) {
                            renderDocRow("Business Entity Certificate", $vendor['business_entity_cert_file'] ?? '', $path_to_root);
                        }
                        ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>