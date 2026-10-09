<?php
// modules/sourcing/all_vendors.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_risk_helper.php';
require_once $path_to_root . 'includes/exception_request_helper.php';

// 1. Security Check (Allow VM + VM Head)
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['sourcing'])) {
    die("Access Denied");
}

$current_user_id = $_SESSION['user_id'];
$page_title = "Master Vendor Database (VM)";
include $path_to_root . 'includes/header.php';

// --- CAPTURE SEARCH & SORT ---
$search = isset($_GET['search']) ? trim($conn->real_escape_string($_GET['search'])) : '';
$sort = $_GET['sort'] ?? 'newest';

// --- BUILD WHERE CLAUSE ---
// Always exclude developer/QA test accounts, and any vendor Vendor Management
// has deactivated. Deactivation is a soft hide, never a delete: the row and all
// its history stay in the database, and reactivating it (VM > Duplicate Vendors)
// brings it straight back into this list. COALESCE covers a database where the
// column has not been added yet.
$conditions = [
    "v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)",
    "COALESCE(v.is_active, 1) = 1",
];
if (!empty($search)) {
    $conditions[] = "(v.company_name LIKE '%$search%' OR v.contact_person LIKE '%$search%' OR v.email LIKE '%$search%' OR v.nib_number LIKE '%$search%' OR v.npwp_number LIKE '%$search%')";
}
$where = "WHERE " . implode(" AND ", $conditions);

// --- BUILD ORDER BY ---
$order = "ORDER BY v.id DESC";
if ($sort === 'newest') $order = "ORDER BY v.created_at DESC";
elseif ($sort === 'oldest') $order = "ORDER BY v.created_at ASC";
elseif ($sort === 'name_asc') $order = "ORDER BY v.company_name ASC";
elseif ($sort === 'name_desc') $order = "ORDER BY v.company_name DESC";
elseif ($sort === 'type') $order = "ORDER BY v.company_type ASC, v.company_name ASC";
elseif ($sort === 'status') $order = "ORDER BY v.registration_status ASC, v.company_name ASC";
elseif ($sort === 'last_approved') $order = "ORDER BY s.approved_at DESC, v.company_name ASC";

// 2. Fetch ALL Vendors + Their Latest PQ Score (if exists)
$sql = "SELECT 
            v.*, 
            s.risk_percentage, 
            s.risk_category,
            s.approved_at,
            s.sourcing_reviewer_id,
            u.full_name as reviewer_name,
            (CASE WHEN er.status IN ('under_review_vm_head','approved') THEN 1 ELSE 0 END) AS is_exception_request,
            er.status AS er_status,
            (SELECT COUNT(*) FROM vendor_products vp WHERE vp.vendor_id = v.id) as product_count
        FROM vendors v
        LEFT JOIN (
            SELECT vendor_id, risk_percentage, risk_category, approved_at, sourcing_reviewer_id
            FROM pq_submissions
            WHERE id IN (SELECT MAX(id) FROM pq_submissions GROUP BY vendor_id)
        ) s ON v.id = s.vendor_id
        LEFT JOIN users u ON s.sourcing_reviewer_id = u.id
        " . EXCEPTION_REQUEST_LATEST_JOIN . "
        $where
        $order";

$result = $conn->query($sql);

// Connect to workspace DB and pre-fetch PO data keyed by vendor_name
// (credentials + QA/prod DB name are centralised in config.php)
$vendor_po_map = [];
$conn_ws = getWorkspaceConnection();
if ($conn_ws) {
    $po_sql = "SELECT vendor_name, po_number, MIN(canvassing_date) as po_date
               FROM prs
               WHERE po_number IS NOT NULL AND po_number != ''
               GROUP BY vendor_name, po_number
               ORDER BY vendor_name, MIN(canvassing_date) DESC";
    $po_res = $conn_ws->query($po_sql);
    if ($po_res) {
        while ($po_row = $po_res->fetch_assoc()) {
            $vname = $po_row['vendor_name'];
            if (!isset($vendor_po_map[$vname])) $vendor_po_map[$vname] = [];
            $vendor_po_map[$vname][] = [
                'po_number' => $po_row['po_number'],
                'po_date'   => $po_row['po_date'],
            ];
        }
    }
    $conn_ws->close();
}
?>

<style>
    /* Consistent styling with dashboard */
    .metric-card {
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        border-radius: 12px;
        overflow: hidden;
    }
    .hover-shadow:hover {
        box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
    }
    
    /* MODERN INTERACTIVE ACTION BUTTONS */
    .btn-icon {
        width: 36px;
        height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        padding: 0;
        transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        border: 1px solid transparent;
    }
    .btn-icon:hover {
        transform: translateY(-3px) scale(1.05);
        box-shadow: 0 6px 12px rgba(0,0,0,0.12) !important;
        z-index: 10;
    }
    .btn-icon:active {
        transform: translateY(-1px);
    }
    
    /* Specific Icon Button Styles */
    .btn-icon-info { background: #e0f8f9; color: #0dcaf0; border-color: rgba(13, 202, 240, 0.2); }
    .btn-icon-info:hover { background: #0dcaf0; color: white; }
    
    .btn-icon-dark { background: #f8f9fa; color: #212529; border-color: rgba(33, 37, 41, 0.1); }
    .btn-icon-dark:hover { background: #212529; color: white; }
    
    .btn-icon-danger { background: #f8d7da; color: #dc3545; border-color: rgba(220, 53, 69, 0.2); }
    .btn-icon-danger:hover { background: #dc3545; color: white; }
    
    .btn-icon-secondary { background: #e9ecef; color: #495057; border-color: rgba(73, 80, 87, 0.2); }
    .btn-icon-secondary:hover { background: #495057; color: white; border-color: #495057; }

    /* Warning: Exception Request vendor already registered in K2 (transacting) but still
       not PQ-approved, with 2+ POs already cut against them. */
    .badge-er-risk { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; animation: er-risk-pulse 1.8s ease-in-out infinite; }
    @keyframes er-risk-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,.35); } 50% { box-shadow: 0 0 0 4px rgba(220,53,69,0); } }

    /* Freeze table header for the vendor list: only the body rows scroll */
    #vendorTableWrap {
        max-height: 70vh;
        overflow-y: auto;
    }
    #vendorTableWrap thead th {
        position: sticky;
        top: 0;
        z-index: 2;
        background-color: #f8f9fa; /* match bg-light so scrolled rows don't show through */
    }
</style>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Master Vendor Data
        </span>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
            <?php include $path_to_root . 'includes/navbar_profile.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">
    
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold text-dark mb-0">Registered Vendors</h4>
            <small class="text-muted">Total Records: <?= $result->num_rows ?><?= !empty($search) ? ' (filtered)' : '' ?></small>
        </div>
        <button class="btn btn-outline-secondary btn-sm" onclick="location.href='all_vendors.php'">
            <i class="fas fa-sync-alt me-1"></i> Reset
        </button>
    </div>

    <!-- SEARCH & SORT BAR -->
    <div class="card shadow-sm border-0 rounded-3 mb-4">
        <div class="card-body py-3">
            <form method="GET" action="all_vendors.php" class="row g-2 align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0 ps-0" placeholder="Search company, contact, email, NIB, NPWP..." value="<?= htmlspecialchars($search ?? '') ?>">
                    </div>
                </div>
                <div class="col-md-3">
                    <select name="sort" class="form-select">
                        <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>>Newest First</option>
                        <option value="oldest" <?= $sort === 'oldest' ? 'selected' : '' ?>>Oldest First</option>
                        <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>Name A-Z</option>
                        <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>Name Z-A</option>
                        <option value="type" <?= $sort === 'type' ? 'selected' : '' ?>>By Type</option>
                        <option value="status" <?= $sort === 'status' ? 'selected' : '' ?>>By Status</option>
                        <option value="last_approved" <?= $sort === 'last_approved' ? 'selected' : '' ?>>Last Approved</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary fw-bold flex-fill">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                    <?php if (!empty($search) || $sort !== 'newest'): ?>
                        <a href="all_vendors.php" class="btn btn-outline-secondary">
                            <i class="fas fa-times"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow border-0 rounded-3 overflow-hidden">
        <div class="card-body p-0">
            <div class="table-responsive" id="vendorTableWrap">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light text-secondary small text-uppercase">
                        <tr>
                            <th class="ps-4 py-3">Company Name</th>
                            <th class="text-center">Type</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Track</th>
                            <th class="text-center">Responsibility</th>
                            <th class="text-center">PQ Score / Risk</th>
                            <th class="text-center">Products</th>
                            <th class="text-center">Jumlah PO</th>
                            <th class="text-start" style="min-width: 200px;">Actions</th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                
                                <?php
                                    // Risk flag: Exception Request vendor already registered in K2 (i.e. already
                                    // transacting) but still not PQ-approved, with 2+ POs already cut.
                                    $row_po_count = count($vendor_po_map[$row['company_name']] ?? []);
                                    $row_is_risk = isExceptionRequestK2Risk($row, $row_po_count);

                                    // Highlight row if it needs VM action; risk flag takes priority.
                                    $row_class = "";
                                    if ($row_is_risk) {
                                        $row_class = "table-danger";
                                    } elseif ($row['registration_status'] == 'under_review_vm') {
                                        $row_class = "table-warning";
                                    }
                                ?>

                                <tr class="<?= $row_class ?>">
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark">
                                            <?= htmlspecialchars($row['company_name'] ?? '') ?>
                                            <?= renderExceptionRequestK2RiskBadge($row, $row_po_count) ?>
                                        </div>
                                        <div class="small text-muted">
                                            <i class="fas fa-user me-1"></i> <?= htmlspecialchars($row['contact_person'] ?? '') ?>
                                        </div>
                                    </td>
                                    
                                    <td class="text-center"><span class="badge bg-light text-dark border"><?= $row['company_type'] ?></span></td>

                                    <td class="text-center">
                                        <?php
                                            // Status Badge Logic
                                            $st = $row['registration_status'];
                                            $badge_class = 'bg-secondary';
                                            $label = strtoupper(str_replace('_', ' ', $st));
                                            
                                            if ($st == 'registration_submitted') {
                                                $label = "REGISTRATION SUBMITTED";
                                                $badge_class = 'bg-info text-dark';
                                            }
                                            if ($st == 'registered') $badge_class = 'bg-info text-dark';
                                            if ($st == 'invited_pq') $badge_class = 'bg-warning text-dark';
                                            if ($st == 'pq_submitted') $badge_class = 'bg-primary';
                                            if ($st == 'returned_to_sourcing') {
                                                $label = "RETURNED BY VM";
                                                $badge_class = "bg-danger text-white";
                                            }
                                            if ($st == 'under_review_vm') {
                                                $label = "WITH VM TEAM";
                                                $badge_class = "bg-warning text-dark border border-warning";
                                            }
                                            if ($st == 'approved') $badge_class = 'bg-success';
                                            if ($st == 'revision_requested') $badge_class = 'bg-danger';

                                            echo "<span class='badge $badge_class rounded-pill px-3 shadow-sm'>" . $label . "</span>";
                                        ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if (!empty($row['is_exception_request'])): ?>
                                            <span class="badge rounded-pill px-3 shadow-sm" style="background:#ccfbf1;color:#115e59;border:1px solid #5eead4;">
                                                <i class="fas fa-bolt me-1"></i> Exception Request
                                            </span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill px-3 shadow-sm bg-light text-secondary border">
                                                <i class="fas fa-stream me-1"></i> Reguler
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if(empty($row['reviewer_name'])): ?>
                                            <span class="badge bg-light text-muted border">Unassigned</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fas fa-user-tie me-1"></i> <?= htmlspecialchars($row['reviewer_name'] ?? '') ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if ($row['registration_status'] === 'approved'): ?>
                                            <?php if ($row['risk_percentage'] !== null): ?>
                                                <div class="fw-bold text-dark"><?= $row['risk_percentage'] ?>%</div>
                                                <small class="text-muted"><?= $row['risk_category'] ?></small>
                                            <?php endif; ?>

                                            <?php
                                            // --- PDF LINK LOGIC ---
                                            // Uploads live at <root>/uploads in prod (persistent volume) and,
                                            // in some dev setups, also at <root>/../uploads (docker-compose
                                            // double-mount). Look in both so the badge shows regardless of the
                                            // mount layout; the download href always resolves to the
                                            // browser-reachable <docroot>/uploads. See commits 699066a / d4ed303.
                                            $pdf_link = "";
                                            $root_path = dirname(dirname(__DIR__));
                                            $upload_bases = [];
                                            foreach ([$root_path . '/uploads', $root_path . '/../uploads'] as $cand) {
                                                $real = realpath($cand);
                                                if ($real && is_dir($real)) $upload_bases[$real] = true;
                                            }
                                            $upload_bases = array_keys($upload_bases);

                                            foreach ($upload_bases as $ub) {
                                                $vendor_folders = glob($ub . "/vendor_" . $row['id'] . "_*", GLOB_ONLYDIR);
                                                if ($vendor_folders && count($vendor_folders) > 0) {
                                                    $target_dir_abs = $vendor_folders[0];
                                                    $pdf_files = glob($target_dir_abs . "/PQ_Result_*.pdf");
                                                    if ($pdf_files && count($pdf_files) > 0) {
                                                        usort($pdf_files, function($a, $b) {
                                                            return filemtime($b) - filemtime($a);
                                                        });
                                                        $folder_name = basename($target_dir_abs);
                                                        $file_name = basename($pdf_files[0]);
                                                        $pdf_link = "../../../uploads/" . $folder_name . "/" . $file_name;
                                                        break;
                                                    }
                                                }
                                            }
                                            ?>

                                            <?php if (!empty($pdf_link)): ?>
                                                <div class="mt-1">
                                                    <a href="<?= $pdf_link ?>" target="_blank" class="badge bg-danger text-white text-decoration-none" title="Download PQ Result">
                                                        <i class="fas fa-file-pdf me-1"></i> PDF Result
                                                    </a>
                                                </div>
                                            <?php endif; ?>

                                        <?php elseif (($row['er_status'] ?? '') === 'approved'): ?>
                                            <?php
                                                // Exception Request approved but PQ hasn't been done yet (no risk score) —
                                                // show the temporary Exception Request Approval PDF instead of PQ Result.
                                                $er_pdf_link = "";
                                                $root_path = dirname(dirname(__DIR__));
                                                // Same multi-base lookup as the PQ_Result block above.
                                                $upload_bases = [];
                                                foreach ([$root_path . '/uploads', $root_path . '/../uploads'] as $cand) {
                                                    $real = realpath($cand);
                                                    if ($real && is_dir($real)) $upload_bases[$real] = true;
                                                }
                                                $upload_bases = array_keys($upload_bases);

                                                foreach ($upload_bases as $ub) {
                                                    $vendor_folders = glob($ub . "/vendor_" . $row['id'] . "_*", GLOB_ONLYDIR);
                                                    if (!$vendor_folders || count($vendor_folders) === 0) continue;
                                                    $target_dir_abs = $vendor_folders[0];
                                                    // Match both the new naming (ExceptionRequest_Approval_*) and the
                                                    // old pre-rename naming (FastTrack_Approval_*) so PDFs generated
                                                    // before this rename still show up.
                                                    $er_pdf_files = array_merge(
                                                        glob($target_dir_abs . "/ExceptionRequest_Approval_*.pdf"),
                                                        glob($target_dir_abs . "/FastTrack_Approval_*.pdf")
                                                    );
                                                    if ($er_pdf_files && count($er_pdf_files) > 0) {
                                                        usort($er_pdf_files, function($a, $b) {
                                                            return filemtime($b) - filemtime($a);
                                                        });
                                                        $folder_name = basename($target_dir_abs);
                                                        $file_name = basename($er_pdf_files[0]);
                                                        $er_pdf_link = "../../../uploads/" . $folder_name . "/" . $file_name;
                                                        break;
                                                    }
                                                }
                                            ?>
                                            <span class="text-muted small fst-italic d-block mb-1">- PQ belum dilakukan -</span>
                                            <?php if (!empty($er_pdf_link)): ?>
                                                <a href="<?= $er_pdf_link ?>" target="_blank" class="badge text-white text-decoration-none" style="background:#fd7e14;" title="Download Exception Request Approval (Sementara)">
                                                    <i class="fas fa-bolt me-1"></i> PDF Exception Request
                                                </a>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">- Not assessed -</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if($row['product_count'] > 0): ?>
                                            <a href="../vendormgt/view_products.php?vendor_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-danger position-relative px-3 rounded-pill">
                                                <i class="fas fa-box-open me-1"></i> Catalog
                                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-dark border border-light">
                                                    <?= $row['product_count'] ?>
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small opacity-50"><i class="fas fa-box"></i> Empty</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php
                                            $vendor_pos = $vendor_po_map[$row['company_name']] ?? [];
                                            $po_count = count($vendor_pos);
                                            if ($po_count > 0):
                                                $po_json = htmlspecialchars(json_encode($vendor_pos), ENT_QUOTES);
                                                $modal_title = htmlspecialchars($row['company_name'] ?? '', ENT_QUOTES);
                                        ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-success fw-bold"
                                                    onclick="showPoModal('<?= $modal_title ?>', <?= $po_json ?>)">
                                                <i class="fas fa-file-invoice me-1"></i><?= $po_count ?>
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <div class="d-flex justify-content-start gap-2">
                                            <!-- Quick Info Modal -->
                                            <span data-bs-toggle="tooltip" data-bs-placement="top" title="Quick Info">
                                                <button type="button" class="btn-icon btn-icon-info shadow-sm" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#vendorModal<?= $row['id'] ?>">
                                                    <i class="fas fa-id-badge"></i>
                                                </button>
                                            </span>
                                            
                                            <!-- View Complete Profile -->
                                            <a href="../sourcing/view_profile.php?id=<?= $row['id'] ?>" 
                                               class="btn-icon btn-icon-dark shadow-sm" 
                                               data-bs-toggle="tooltip" data-bs-placement="top" title="View Full Profile">
                                                <i class="fas fa-user-circle"></i>
                                            </a>

                                            
                                            <!-- SR Action Logic -->
                                            <?php if (in_array($row['registration_status'], ['approved', 'rejected', 'returned_to_sourcing', 'under_review_vm'])): ?>
                                                <a href="review_pq.php?id=<?= $row['id'] ?>" 
                                                   class="btn-icon btn-icon-secondary shadow-sm text-dark" 
                                                   data-bs-toggle="tooltip" data-bs-placement="top" title="View Assessment Result">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- MODAL: Vendor Details (Quick Info) -->
                                        <div class="modal fade" id="vendorModal<?= $row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg text-start" style="border-radius: 12px; overflow: hidden;">
                                                    
                                                    <div class="modal-header bg-danger text-white border-0 py-3">
                                                        <h5 class="modal-title fw-bold"><i class="fas fa-building me-2"></i> <?= htmlspecialchars($row['company_name'] ?? '') ?></h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>

                                                    <div class="modal-body p-4 bg-light">
                                                        <div class="row g-4">
                                                            <!-- Banking -->
                                                            <div class="col-md-6">
                                                                <div class="card h-100 border-0 shadow-sm">
                                                                    <div class="card-body p-3">
                                                                        <h6 class="text-danger fw-bold text-uppercase small mb-3"><i class="fas fa-university me-2"></i>Banking</h6>
                                                                        <div class="d-flex justify-content-between mb-2 border-bottom pb-1"><span class="text-muted small">Bank:</span><span class="fw-bold text-dark small"><?= htmlspecialchars($row['bank_name'] ?? '-') ?></span></div>
                                                                        <div class="d-flex justify-content-between mb-2 border-bottom pb-1"><span class="text-muted small">Account:</span><span class="fw-bold text-dark small"><?= htmlspecialchars($row['bank_account_number'] ?? '-') ?></span></div>
                                                                        <div class="d-flex justify-content-between"><span class="text-muted small">NIB:</span><span class="badge bg-light text-dark border"><?= htmlspecialchars($row['nib_number'] ?? '-') ?></span></div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- General -->
                                                            <div class="col-md-6">
                                                                <div class="card h-100 border-0 shadow-sm">
                                                                    <div class="card-body p-3">
                                                                        <h6 class="text-success fw-bold text-uppercase small mb-3"><i class="fas fa-info-circle me-2"></i>General Info</h6>
                                                                        <div class="d-flex justify-content-between mb-2 border-bottom pb-1"><span class="text-muted small">Director:</span><span class="fw-bold text-dark small text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['director_name'] ?? '-') ?></span></div>
                                                                        <div class="d-flex justify-content-between mb-2 border-bottom pb-1"><span class="text-muted small">Email:</span><span class="fw-bold text-dark small text-truncate" style="max-width: 150px;"><?= htmlspecialchars($row['email'] ?? '-') ?></span></div>
                                                                        <div class="mt-2 small">
                                                                            <span class="text-muted">Ethics Form:</span> 
                                                                            <?= !empty($row['business_ethics_commitment_file']) ? '<i class="fas fa-check text-success"></i> Uploaded' : '<span class="text-muted fst-italic">Missing</span>' ?>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Contact -->
                                                            <div class="col-12">
                                                                <div class="card border-0 shadow-sm">
                                                                    <div class="card-body p-3 d-flex justify-content-between align-items-center">
                                                                        <div>
                                                                            <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Contact</small>
                                                                            <div class="fw-bold text-dark"><i class="fas fa-phone me-1"></i> <?= htmlspecialchars($row['phone'] ?? '-') ?></div>
                                                                        </div>
                                                                        <div class="text-end">
                                                                            <small class="text-muted d-block text-uppercase fw-bold" style="font-size: 0.7rem;">Registered</small>
                                                                            <div class="text-dark"><?= date('d M Y', strtotime($row['created_at'])) ?></div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <div class="modal-footer bg-white border-top-0 py-3">
                                                        <a href="../vendormgt/view_profile.php?id=<?= $row['id'] ?>" class="btn btn-dark rounded-pill px-4 me-auto">
                                                            <i class="fas fa-user-circle me-1"></i> View Full Profile
                                                        </a>
                                                        <button type="button" class="btn btn-outline-dark rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="9" class="text-center py-5 text-muted">No vendors found in database.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Shared PO Modal -->
<style>
    .po-history-modal .modal-content { border: 0; border-radius: 20px; overflow: hidden; box-shadow: 0 24px 70px rgba(15,23,42,.22); }
    .po-history-modal .modal-header { padding: 1.25rem 1.5rem; background: linear-gradient(135deg,#198754,#117a4b); }
    .po-history-modal .po-title-icon { width: 42px; height: 42px; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; background: rgba(255,255,255,.16); }
    .po-history-modal .modal-title { font-size: 1rem; line-height: 1.3; }
    .po-history-modal .modal-title > #poModalVendorName { display: block; color: #fff; font-size: .98rem; font-weight: 700; letter-spacing: .01em; text-shadow: 0 1px 2px rgba(0,0,0,.15); }
    .po-history-modal .po-title-label { display: block; margin-bottom: .15rem; color: rgba(255,255,255,.72); font-size: .68rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
    .po-history-modal .btn-close { padding: .65rem; border-radius: 50%; background-color: rgba(255,255,255,.12); }
    .po-history-modal thead th { padding: .85rem 1rem; border: 0; background: #f4f7f6; color: #64748b; font-size: .7rem; letter-spacing: .045em; }
    .po-history-modal tbody td { padding: 1rem; border-color: #edf1ef; }
    .po-history-modal .po-index { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; border-radius: 9px; background: #eaf6ef; color: #198754; font-size: .75rem; font-weight: 700; }
    .po-history-modal .po-number { color: #1e293b; font-weight: 700; }
    .po-history-modal .po-date { display: inline-flex; align-items: center; gap: .45rem; color: #64748b; font-size: .82rem; }
    .po-history-modal .po-date i { color: #198754; }
    .po-history-modal .modal-footer { padding: 1rem 1.5rem 1.25rem; }
    .po-history-modal .po-close-btn { padding: .55rem 1.35rem; border-color: #d7dfdb; color: #475569; font-weight: 600; }
    .po-history-modal .po-close-btn:hover { border-color: #198754; background: #198754; color: #fff; }
    .po-history-modal .po-evaluation-btn { padding: .55rem 1.35rem; background: #198754; border-color: #198754; color: #fff; font-weight: 600; }
    .po-history-modal .po-evaluation-btn:hover { background: #146c43; border-color: #146c43; color: #fff; }
</style>
<div class="modal fade" id="poListModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-md po-history-modal">
        <div class="modal-content">
            <div class="modal-header text-white border-0">
                <div class="d-flex align-items-center gap-3">
                    <span class="po-title-icon"><i class="fas fa-file-invoice"></i></span>
                    <h5 class="modal-title mb-0"><span class="po-title-label">Purchase Order History</span><span id="poModalVendorName"></span></h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-0">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light small text-uppercase fw-bold">
                        <tr>
                            <th class="ps-4 py-2">#</th>
                            <th class="py-2">Nomor PO</th>
                            <th class="py-2">Tanggal</th>
                        </tr>
                    </thead>
                    <tbody id="poModalBody"></tbody>
                </table>
            </div>
            <div class="modal-footer border-top-0 bg-white">
                <!-- <a href="../../workspace/pages/sso_vss.php?target=eval_monitor" class="btn rounded-pill po-evaluation-btn me-auto">
                    <i class="fas fa-chart-line me-2"></i>Lihat Evaluation Monitor
                </a> -->
                <button type="button" class="btn btn-outline-secondary rounded-pill po-close-btn" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- Initialize Bootstrap Tooltips & PO Modal -->
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl)
        })
    });

    function showPoModal(vendorName, poList) {
        document.getElementById('poModalVendorName').textContent = vendorName;
        var tbody = document.getElementById('poModalBody');
        tbody.innerHTML = '';
        poList.forEach(function(po, idx) {
            var dateStr = po.po_date ? new Date(po.po_date).toLocaleDateString('id-ID', {day:'2-digit', month:'short', year:'numeric'}) : '-';
            var tr = '<tr>' +
                '<td class="ps-4"><span class="po-index">' + (idx + 1) + '</span></td>' +
                '<td><span class="po-number">' + po.po_number + '</span></td>' +
                '<td><span class="po-date"><i class="far fa-calendar-alt"></i>' + dateStr + '</span></td>' +
                '</tr>';
            tbody.insertAdjacentHTML('beforeend', tr);
        });
        var modal = new bootstrap.Modal(document.getElementById('poListModal'));
        modal.show();
    }
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
