<?php
// modules/vendormgt/dashboard.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/exception_request_helper.php';
require_once $path_to_root . 'includes/pq_risk_helper.php';

// Security Check
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['vendor_mgt', 'vendormgt_head'])) {
    header("Location: ../../login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$is_vm_head = ($_SESSION['role'] === 'vendormgt_head');
$page_title = "VM Dashboard";
include $path_to_root . 'includes/header.php';

// --- CAPTURE INPUTS ---
$search = isset($_GET['search']) ? trim($conn->real_escape_string($_GET['search'])) : '';
$sort = $_GET['sort'] ?? 'default';

// Default priority based on role, with a per-user override for VM Head
// accounts that have their own scoped default view (see VM_HEAD_USER_SETTINGS).
// 'worklist' is used for both roles (identical WHERE clause to the old
// 'pending_vm' default for regular VM staff) so the primary tab reads as
// active on first load, matching the sourcing dashboard. Computed unconditionally
// (not just in the "else" branch below) so the primary tab's own label/link can
// always reflect it — e.g. Hadi's account (see VM_HEAD_USER_SETTINGS) shows
// "ER Request" instead of "Worklist" as its first tab.
$default_filter = 'worklist';
if ($is_vm_head) {
    $default_filter = vmHeadSetting($current_user_id, 'default_dashboard_filter', $default_filter);
}

// SMART SEARCH LOGIC:
if (!isset($_GET['filter']) && !empty($search)) {
    $filter = 'all';
} else {
    $filter = $_GET['filter'] ?? $default_filter;
}

// Primary tab (first tab in the nav bar) always reads "Worklist",
$primary_tab = ['label' => 'Worklist', 'icon' => 'fa-tasks'];

// Exception Request filters use a different table layout: no Risk Score column
$is_exception_filter = strpos($filter, 'exception_request') === 0;

// Per-column filters from the table header row, mirroring the sourcing dashboard.
$biz_search      = isset($_GET['biz_search']) ? trim($conn->real_escape_string($_GET['biz_search'])) : '';
$reviewer_search = isset($_GET['reviewer_search']) ? trim($conn->real_escape_string($_GET['reviewer_search'])) : '';
$status_options = [
    'invited_pq'             => 'Invited PQ',
    'pq_submitted'           => 'PQ Submitted',
    'returned_to_sourcing'   => 'Returned by VM',
    'under_review_vm'        => 'With VM Team',
    'under_review_vm_head'   => 'With VM Head',
    'approved'               => 'Approved',
    'revision_requested'     => 'Revision Requested',
    'registration_submitted' => 'Registration Submitted',
];
$status_filter = isset($status_options[$_GET['status_f'] ?? '']) ? $_GET['status_f'] : '';

// --- BUILD WHERE CLAUSE ---
$conditions = [];

// Always exclude developer/QA test accounts from VM dashboards.
$conditions[] = "v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)";

if ($filter === 'approved') {
    $conditions[] = "v.registration_status = 'approved'";
} elseif ($filter === 'pending_vm') {
    $conditions[] = "v.registration_status = 'under_review_vm'";
} elseif ($filter === 'pending_head') {
    $conditions[] = "v.registration_status = 'under_review_vm_head'";
} elseif ($filter === 'pq_submitted') {
    $conditions[] = "v.registration_status = 'pq_submitted'";
} elseif ($filter === 'invited') {
    $conditions[] = "v.registration_status = 'invited_pq'";
} elseif ($filter === 'registration_submitted') {
    $conditions[] = "v.registration_status = 'registration_submitted'";
} elseif ($filter === 'migrated') {
    $conditions[] = "v.registration_status = 'migrated'";
} elseif ($filter === 'revision') {
    $conditions[] = "v.registration_status IN ('revision_requested', 'returned_to_sourcing')";
} elseif ($filter === 'draft') {
    $conditions[] = "v.registration_status IN ('registered', 'min_info_submitted', 'draft')";
} elseif ($filter === 'exception_request') {
    // Exception Request lane runs parallel to PQ (independent of registration_status).
    $conditions[] = "er.status = 'under_review_vm_head'";
} elseif ($filter === 'exception_request_waiting') {
    $conditions[] = "er.status = 'under_review_vm_head'";
} elseif ($filter === 'exception_request_approved') {
    $conditions[] = "er.status = 'approved'";
} elseif ($filter === 'worklist') {
    // Default worklist: items awaiting the current user's action.
    // VM Head also handles Exception Request approvals (parallel to registration approval).
    if ($is_vm_head) {
        $conditions[] = "(v.registration_status = 'under_review_vm_head' OR er.status = 'under_review_vm_head')";
    } else {
        $conditions[] = "v.registration_status = 'under_review_vm'";
    }
} elseif ($filter !== 'all') { // default fallback
    $conditions[] = $is_vm_head ? "v.registration_status = 'under_review_vm_head'" : "v.registration_status = 'under_review_vm'";
}

// Add Search Condition
if (!empty($search)) {
    $conditions[] = "(v.company_name LIKE '%$search%' OR v.contact_person LIKE '%$search%' OR v.email LIKE '%$search%')";
}

// Per-column Type & Business filter (company type or core business)
if ($biz_search !== '') {
    $conditions[] = "(v.company_type LIKE '%$biz_search%' OR v.core_business LIKE '%$biz_search%')";
}

// Per-column Status filter (value already whitelisted against $status_options)
if ($status_filter !== '') {
    $conditions[] = "v.registration_status = '" . $conn->real_escape_string($status_filter) . "'";
}

// Per-column Sourcing Reviewer / Requestor filter (u = PQ reviewer, ur = Exception Request requestor)
if ($reviewer_search !== '') {
    $conditions[] = "(u.full_name LIKE '%$reviewer_search%' OR ur.full_name LIKE '%$reviewer_search%')";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// Shared FROM/JOIN clause for both the row query and the pagination count query
// (the reviewer filter above needs the u/ur joins, not just vendors + er).
$from_clause = "FROM vendors v
          LEFT JOIN (
              SELECT id, vendor_id, sourcing_reviewer_id, risk_category, risk_percentage, submitted_at,
                     pq_sla_deadline, pq_sla_first_forward_at, pq_sla_final_hours, pq_sla_final_met
              FROM pq_submissions
              WHERE id IN (SELECT MAX(id) FROM pq_submissions GROUP BY vendor_id)
          ) s ON v.id = s.vendor_id
          LEFT JOIN users u ON s.sourcing_reviewer_id = u.id
          " . EXCEPTION_REQUEST_LATEST_JOIN . "
          LEFT JOIN users ur ON er.submitted_by = ur.id
          LEFT JOIN users rvu ON er.reviewed_by = rvu.id";

// --- PAGINATION ---
$per_page = 20;
$count_sql = "SELECT COUNT(*) as cnt $from_clause $where_clause";
$total_rows = (int)($conn->query($count_sql)->fetch_assoc()['cnt'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages) $page = $total_pages;
$page_offset = ($page - 1) * $per_page;

// --- BUILD ORDER BY CLAUSE ---
// Default VM logic: Show pending items first, sorted by oldest submission
$prioritized_status = $is_vm_head ? 'under_review_vm_head' : 'under_review_vm';
$order_clause = "ORDER BY (v.registration_status = '$prioritized_status') DESC, s.submitted_at ASC"; 

if ($sort === 'newest') {
    $order_clause = "ORDER BY v.created_at DESC";
} elseif ($sort === 'oldest') {
    $order_clause = "ORDER BY v.created_at ASC";
} elseif ($sort === 'name_asc') {
    $order_clause = "ORDER BY v.company_name ASC";
} elseif ($sort === 'name_desc') {
    $order_clause = "ORDER BY v.company_name DESC";
} elseif ($sort === 'type') {
    $order_clause = "ORDER BY v.company_type ASC, v.company_name ASC";
}

// 1. Fetch All Vendors (Filtered & Sorted)
$query = "SELECT v.*, 
            s.id as submission_id,
            s.submitted_at,
            s.risk_category,
            s.risk_percentage,
            s.sourcing_reviewer_id,
            s.pq_sla_deadline,
            s.pq_sla_first_forward_at,
            s.pq_sla_final_hours,
            s.pq_sla_final_met,
            u.full_name as reviewer_name,
            ur.full_name as er_requestor_name,
            rvu.full_name as er_reviewer_name,
            (CASE WHEN er.status IN ('under_review_vm_head','approved') THEN 1 ELSE 0 END) AS is_exception_request,
            er.status AS exception_request_status,
            er.reason AS exception_request_reason,
            er.submitted_notes AS exception_request_notes,
            er.submitted_at AS exception_request_at,
            er.review_notes AS exception_request_vm_notes,
            er.reviewed_at AS exception_request_reviewed_at
          $from_clause
          $where_clause
          $order_clause
          LIMIT $per_page OFFSET $page_offset";

$result = $conn->query($query);

// Load SLA helper for badge rendering
require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';

// 2. Calculate Dashboard Metrics (Filtered by Search, but NOT by Status Filter)
$metric_conditions = ["v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)"];
if (!empty($search)) {
    $metric_conditions[] = "(v.company_name LIKE '%$search%' OR v.contact_person LIKE '%$search%' OR v.email LIKE '%$search%')";
}
$metric_where = "WHERE " . implode(" AND ", $metric_conditions);

$metrics_sql = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN registration_status = 'under_review_vm' THEN 1 ELSE 0 END) as pending_vm,
    SUM(CASE WHEN registration_status = 'under_review_vm_head' THEN 1 ELSE 0 END) as pending_head,
    SUM(CASE WHEN registration_status = 'pq_submitted' THEN 1 ELSE 0 END) as pq_submitted, 
    SUM(CASE WHEN registration_status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN registration_status = 'invited_pq' THEN 1 ELSE 0 END) as invited,
    SUM(CASE WHEN registration_status = 'registration_submitted' THEN 1 ELSE 0 END) as registration_submitted,
    SUM(CASE WHEN registration_status = 'migrated' THEN 1 ELSE 0 END) as migrated,
    SUM(CASE WHEN registration_status IN ('registered', 'min_info_submitted', 'draft') THEN 1 ELSE 0 END) as draft,
    SUM(CASE WHEN registration_status IN ('revision_requested', 'returned_to_sourcing') THEN 1 ELSE 0 END) as revision,
    SUM(CASE WHEN er.status = 'under_review_vm_head' THEN 1 ELSE 0 END) as exception_request,
    SUM(CASE WHEN er.status = 'approved' THEN 1 ELSE 0 END) as exception_request_approved
FROM vendors v " . EXCEPTION_REQUEST_LATEST_JOIN . " $metric_where";

$metrics = $conn->query($metrics_sql)->fetch_assoc();

// Use null coalescing ?? 0 in case the SUM returns NULL for empty search results
$total_count = $metrics['total'] ?? 0;
$pending_vm_count = $metrics['pending_vm'] ?? 0;
$pending_head_count = $metrics['pending_head'] ?? 0;
$pq_submitted_count = $metrics['pq_submitted'] ?? 0;
$approved_count = $metrics['approved'] ?? 0;
$invited_count = $metrics['invited'] ?? 0;
$registration_submitted_count = $metrics['registration_submitted'] ?? 0;
$migrated_count = $metrics['migrated'] ?? 0;
$draft_count = $metrics['draft'] ?? 0;
$revision_count = $metrics['revision'] ?? 0;
$exception_request_count = $metrics['exception_request'] ?? 0;
$exception_request_waiting_count = $exception_request_count;
$exception_request_approved_count = $metrics['exception_request_approved'] ?? 0;

// Labels for the "Filter: ..." chip shown next to the Worklist tab whenever a
// metric-card filter is active (keys = ?filter= values, wording = card labels).
$metric_filter_labels = [
    'all'                        => 'Total Registered',
    'approved'                   => 'Approved Vendors',
    'pq_submitted'               => 'New PQ',
    'pending_vm'                 => 'VM Review',
    'pending_head'               => 'VM Head',
    'registration_submitted'     => 'Self Registered',
    'migrated'                   => 'Migrated',
    'invited'                    => 'Full Profile',
    'revision'                   => 'Revision Needed',
    'draft'                      => 'Draft',
    'exception_request'          => 'Exception Request',
    'exception_request_waiting'  => 'ER Waiting Approval',
    'exception_request_approved' => 'ER Approved',
];

// URL parameters to persist search, sort & per-column filters when clicking metric cards
$url_params = "&search=" . urlencode($search) . "&sort=" . urlencode($sort);
if ($biz_search !== '')      $url_params .= "&biz_search=" . urlencode($biz_search);
if ($status_filter !== '')   $url_params .= "&status_f=" . urlencode($status_filter);
if ($reviewer_search !== '') $url_params .= "&reviewer_search=" . urlencode($reviewer_search);

// Base URL for page-number links (active filter/search/sort + current tab, page appended per-link).
$page_base_url = "?filter=" . urlencode($filter) . $url_params;
?>

<style>
    .metric-card {
        transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out;
        border-radius: 12px;
        overflow: hidden;
        position: relative;
        cursor: pointer; 
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
    /* HIGH CONTRAST GRADIENTS matching VM Theme */
    .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #198754 0%, #0f5132 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
    .bg-gradient-info    { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); }
    .bg-gradient-danger  { background: linear-gradient(135deg, #dc3545 0%, #842029 100%); }
    .bg-gradient-secondary { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); }
    .bg-gradient-exception-request { background: linear-gradient(135deg, #0d9488 0%, #115e59 100%); }

    .vendor-modal-card { border-radius: 12px; }
    .border-dashed { border-style: dashed !important; }
    .hover-shadow:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }
    
    /* Subcard Styles */
    .subcard {
        background: rgba(255, 255, 255, 0.2);
        border-radius: 8px;
        padding: 8px 12px;
        backdrop-filter: blur(5px);
        transition: background 0.2s;
        text-decoration: none;
        color: inherit;
        display: block;
        cursor: pointer;
    }
    .subcard:hover { background: rgba(255, 255, 255, 0.35); }
    
    a.card-link { text-decoration: none; color: inherit; display: block; }

    /* Worklist tab: underline style like the sourcing dashboard, colored with
       the site palette (--primary-color navy) instead of Bootstrap's default. */
    .nav-tabs .nav-link { color: var(--text-color); border: none; border-bottom: 3px solid transparent; padding: 1rem 1.5rem; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); border-color: #e9ecef; }
    .nav-tabs .nav-link.active { color: var(--primary-color); background-color: transparent; border-bottom: 3px solid var(--primary-color); }

    /* "Filter: ..." chip next to the Worklist tab — tells which metric-card
       filter is currently applied; the × removes it (back to Worklist). */
    .filter-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background: #e8f3fa;
        color: var(--primary-color);
        border: 1px solid rgba(0, 96, 156, 0.25);
        border-radius: 50px;
        padding: 10px 20px;
        font-size: 0.8rem;
        font-weight: 600;
        line-height: 1.5;
        white-space: nowrap;
    }
    .filter-chip a { color: var(--primary-color); text-decoration: none; font-weight: 700; font-size: 1rem; line-height: 1; }
    .filter-chip a:hover { color: #dc3545; }

    /* Compact, uniform controls for the per-column filter row in the table
       header — mirrors the sourcing dashboard's table-filter-row. */
    .table-filter-row th { padding-top: 0.4rem; padding-bottom: 0.4rem; vertical-align: middle; }
    .table-filter-row .form-control,
    .table-filter-row .form-select {
        font-size: 0.82rem;
        padding: 0.25rem 0.55rem;
        height: 32px;
        min-height: 32px;
        border-radius: 6px;
        width: 100%;
    }
    .table-filter-row .form-select { padding-right: 1.6rem; background-size: 11px; }
    .table-filter-row .btn {
        font-size: 0.82rem;
        padding: 0.25rem 0.85rem;
        height: 32px;
        line-height: 1;
        border-radius: 50rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }

    /* Minimalist vendor-table pagination — borderless, flat, small footprint */
    .pagination-minimal { display: flex; align-items: center; gap: 0.15rem; list-style: none; margin: 0; padding: 0; }
    .pagination-minimal .page-link {
        border: none; background: transparent; color: var(--text-color);
        font-size: 0.82rem; font-weight: 600;
        width: 30px; height: 30px; padding: 0;
        display: flex; align-items: center; justify-content: center;
        border-radius: 8px; transition: background 0.15s ease, color 0.15s ease;
    }
    .pagination-minimal .page-link:hover { background: #f1f3f5; color: var(--primary-color); }
    .pagination-minimal .page-link:focus { box-shadow: none; }
    .pagination-minimal .page-item.active .page-link { background: var(--primary-color); color: #fff; }
    .pagination-minimal .page-item.disabled .page-link { color: #ced4da; background: transparent; pointer-events: none; }
    .pagination-minimal .page-item .page-link.ellipsis { cursor: default; font-weight: 700; }

    /* Responsive tweaks for small screens */
    @media (max-width: 767.98px) {
        .metric-card .display-4 { font-size: 2.25rem; }
        .metric-icon { font-size: 3rem; }
        .navbar-collapse .btn { text-align: left; }
    }

    /* Reveal the Vendor dropdown on hover (desktop only — mobile/collapsed
       navbar keeps the default click-to-open behavior). */
    @media (min-width: 992px) {
        .vendor-dropdown:hover .dropdown-menu { display: block; margin-top: 0; }
    }

    /* Compact nav buttons so the whole toolbar fits on one line (desktop only). */
    @media (min-width: 992px) {
        #vendormgtNav .btn-sm {
            font-size: 0.78rem;
            padding: 0.25rem 0.6rem;
            white-space: nowrap;
        }
    }

    .btn-reveal { position:relative; display:inline-flex; align-items:center; justify-content:center; border-radius:50rem; }
    .btn-reveal:hover, .btn-reveal:focus-visible { z-index:5; }
    .btn-reveal .btn-ico-label {
        position:absolute; top:0; bottom:0;
        display:flex; align-items:center;
        max-width:0; opacity:0; overflow:hidden; white-space:nowrap;
        background:inherit; color:inherit;
        transition:max-width .25s ease, opacity .2s ease, padding .25s ease;
    }
    .btn-reveal:hover .btn-ico-label, .btn-reveal:focus-visible .btn-ico-label { max-width:260px; opacity:1; }
    .btn-reveal .btn-ico-label { left:100%; justify-content:flex-start; border-radius:0 50rem 50rem 0; }
    .btn-reveal:hover, .btn-reveal:focus-visible { border-top-right-radius:0; border-bottom-right-radius:0; }
    .btn-reveal:hover .btn-ico-label, .btn-reveal:focus-visible .btn-ico-label { padding-left:.5rem; padding-right:1rem; }

    /* Middle button: nudge left a little -> opens toward BOTH neighbours */
    .btn-reveal:not(:first-child):not(:last-child):hover,
    .btn-reveal:not(:first-child):not(:last-child):focus-visible { transform:translateX(-50%); }

    /* Last button: slide further left -> opens over the buttons on its LEFT */
    .btn-reveal:last-child:not(:first-child):hover,
    .btn-reveal:last-child:not(:first-child):focus-visible { transform:translateX(-4.5rem); }

    /* Detail Exception Request modal — mirrors the sourcing dashboard design. */
    .ft-header { background:linear-gradient(135deg, #0d9488 0%, #115e59 100%); color:#fff; }
    @media (min-width: 576px) { .ft-modal-wide { max-width: 580px; } }
    .ft-header-icon { width:42px; height:42px; border-radius:50%; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:1.05rem; flex-shrink:0; }
</style>

<nav class="navbar navbar-expand-lg navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>
            Vendor Management Dashboard
        </span>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#vendormgtNav" aria-controls="vendormgtNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="vendormgtNav">
        <div class="d-flex flex-column flex-lg-row flex-lg-nowrap align-items-lg-center ms-lg-auto mt-3 mt-lg-0 gap-2 gap-lg-0">
            <a href="<?= $path_to_root ?>workspace/pages/sso_vss.php" class="btn btn-sm btn-nav me-lg-2" title="Launch Workspace Pro">
                <i class="fas fa-rocket me-1"></i> Workspace
            </a>

            <div class="dropdown me-lg-2 vendor-dropdown">
                <a class="btn btn-sm btn-nav dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="fas fa-list me-1 nav-ic-vendor"></i> Vendor
                </a>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="all_vendors.php"><i class="fas fa-list me-2 text-muted"></i>All Vendors</a></li>
                    <li><a class="dropdown-item" href="../sourcing/manage_questions_new.php"><i class="fas fa-tasks me-2 text-muted"></i>Manage PQ Questions</a></li>
                    <li><a class="dropdown-item" href="duplicate_vendors.php"><i class="fas fa-clone me-2 text-muted"></i>Duplicate Vendors</a></li>
                    <li><a class="dropdown-item" href="migrated_reminder.php"><i class="fas fa-bell me-2 text-muted"></i>Reminder Vendor Migrasi</a></li>
                </ul>
            </div>
            <a href="reports.php" class="btn btn-sm btn-nav me-lg-2">
                <i class="fas fa-chart-pie me-1 text-warning"></i> Reports
            </a>
            <a href="invite_vendor.php" class="btn btn-sm btn-nav-solid me-lg-3">
                <i class="fas fa-user-plus me-1"></i> Invite Vendor
            </a>
            <?php $navbar_profile_fallback = 'Vendor Management'; include $path_to_root . 'includes/navbar_profile.php'; ?>
        </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    
    <!-- ROW 1 METRICS -->
    <div class="row mb-4 g-4">
        <div class="col-md-4">
            <a href="?filter=all<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-primary metric-card bg-gradient-primary">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-white small fw-bold mb-2">Total Registered</h6>
                        <h1 class="display-4 fw-bold mb-0 text-white"><?= $total_count ?></h1>
                        <div class="small text-white-50 mt-1">Vendor Database</div>
                    </div>
                    <i class="fas fa-users metric-icon text-white"></i>
                </div>
            </a>
        </div>

        <div class="col-md-4">
            <a href="?filter=approved<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-success metric-card bg-gradient-success">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-white small fw-bold mb-2">Approved Vendors</h6>
                        <h1 class="display-4 fw-bold mb-0 text-white"><?= $approved_count ?></h1>
                        <div class="small text-white-50 mt-1">Ready for PO</div>
                    </div>
                    <i class="fas fa-check-circle metric-icon text-white"></i>
                </div>
            </a>
        </div>

        <!-- Metric: Pending Approval (VM) with Subcards -->
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning bg-gradient-warning metric-card" style="cursor: default;">
                <div class="card-body pb-2 metric-content">
                    <h6 class="text-uppercase text-dark small fw-bold mb-3">Pending Process</h6>
                    
                    <div class="row g-2">
                        <!-- Subcard 1: Under VM Review -->
                        <div class="col-4">
                            <a href="?filter=pending_vm<?= $url_params ?>" class="subcard bg-black bg-opacity-10 border border-dark text-dark text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">VM Review</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $pending_vm_count ?></span>
                                    <i class="fas fa-user-clock opacity-50"></i>
                                </div>
                            </a>
                        </div>

                        <!-- Subcard 2: Pending Head Approval -->
                        <div class="col-4">
                            <a href="?filter=pending_head<?= $url_params ?>" class="subcard bg-black bg-opacity-10 border border-dark text-dark text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">VM Head</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $pending_head_count ?></span>
                                    <i class="fas fa-user-shield opacity-50"></i>
                                </div>
                            </a>
                        </div>

                        <!-- Subcard 3: New PQ (Sourcing Task) -->
                        <div class="col-4">
                            <a href="?filter=pq_submitted<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-dark text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">New PQ</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $pq_submitted_count ?></span>
                                    <i class="fas fa-inbox opacity-50"></i>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                <!-- Icon placed behind content -->
                <i class="fas fa-tasks metric-icon text-dark"></i>
            </div>
        </div>
    </div>

    <!-- ROW 2 METRICS -->
    <div class="row mb-4 g-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 border-start border-4 border-info metric-card bg-gradient-info" style="cursor: default;">
                <div class="card-body pb-2 metric-content">
                    <h6 class="text-uppercase text-white small fw-bold mb-3">Invited to Qualification</h6>
                    <div class="row g-2">
                        <div class="col-4">
                            <a href="?filter=registration_submitted<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">Self Registered</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $registration_submitted_count ?></span>
                                    <i class="fas fa-user-edit opacity-50"></i>
                                </div>
                            </a>
                        </div>
                        <div class="col-4">
                            <a href="?filter=migrated<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">Migrated</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $migrated_count ?></span>
                                    <i class="fas fa-file-import opacity-50"></i>
                                </div>
                            </a>
                        </div>
                        <div class="col-4">
                            <a href="?filter=invited<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">Full Profile</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $invited_count ?></span>
                                    <i class="fas fa-paper-plane opacity-50"></i>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                <i class="fas fa-envelope-open-text metric-icon text-white"></i>
            </div>
        </div>
        <div class="col-md-4">
            <a href="?filter=revision<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-danger metric-card bg-gradient-danger">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-white small fw-bold mb-2">Revision Needed</h6>
                        <h1 class="display-4 fw-bold mb-0 text-white"><?= $revision_count ?></h1>
                        <div class="small text-white-50 mt-1">Vendor Action Required</div>
                    </div>
                    <i class="fas fa-exclamation-triangle metric-icon text-white"></i>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 border-start border-4 metric-card bg-gradient-exception-request" style="cursor: default; border-color:#5eead4 !important;">
                <div class="card-body pb-2 metric-content">
                    <h6 class="text-uppercase text-white small fw-bold mb-3"><i class="fas fa-bolt me-1"></i> Registered Exception Request</h6>

                    <div class="row g-2">
                        <!-- Subcard 1: Waiting Approval (VM Head) -->
                        <div class="col-6">
                            <a href="?filter=exception_request_waiting<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">Waiting Approval</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $exception_request_waiting_count ?></span>
                                    <i class="fas fa-hourglass-half opacity-50"></i>
                                </div>
                            </a>
                        </div>

                        <!-- Subcard 2: Approved Exception Request -->
                        <div class="col-6">
                            <a href="?filter=exception_request_approved<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="d-block fw-bold text-uppercase opacity-75" style="font-size: 0.6rem;">Approve Exception Request</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $exception_request_approved_count ?></span>
                                    <i class="fas fa-check-circle opacity-50"></i>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                <i class="fas fa-bolt metric-icon text-white"></i>
            </div>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a href="?filter=<?= urlencode($default_filter) ?><?= $url_params ?>" class="nav-link fw-bold <?= $filter === $default_filter ? 'active' : '' ?>">
                <i class="fas <?= $primary_tab['icon'] ?> me-2"></i> <?= $primary_tab['label'] ?>
            </a>
        </li>
        <?php
            $chip_label        = ($filter !== $default_filter) ? ($metric_filter_labels[$filter] ?? null) : null;
            $has_other_filters = !empty($search) || $biz_search !== '' || $status_filter !== '' || $reviewer_search !== '' || (isset($_GET['filter']) && !in_array($_GET['filter'], ['default', $default_filter], true));
        ?>
        <?php if($chip_label || $has_other_filters): ?>
            <li class="nav-item ms-auto d-flex align-items-center gap-2">
                <?php if($chip_label): ?>
                    <span class="filter-chip">
                        <i class="fas fa-filter"></i> Filter: <?= $chip_label ?>
                        <a href="?filter=<?= urlencode($default_filter) ?><?= $url_params ?>" title="Hapus filter kartu">&times;</a>
                    </span>
                <?php endif; ?>
                <?php if($has_other_filters): ?>
                    <a href="dashboard.php" class="btn btn-sm btn-outline-secondary" style="font-size: 0.8rem;"><i class="fas fa-times me-1"></i> Clear Filters</a>
                <?php endif; ?>
            </li>
        <?php endif; ?>
    </ul>

    <!-- The table rows contain their own modals & forms, so the filter form lives
         outside the table and controls are linked to it via the form="" attribute. -->
    <form id="tableFilterForm" method="GET" action="dashboard.php">
        <!-- Keep the active tab / metric-card scope when the filter row is submitted. -->
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <?php if($sort != 'default'): ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort) ?>">
        <?php endif; ?>
    </form>

    <div class="card shadow-sm border-0 rounded-3 overflow-hidden mb-5">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="background-color: white;">
                    <thead style="background-color: #f1f5f9; color: #64748b;" class="small text-uppercase fw-bold">
                        <tr class="text-center">
                            <th class="ps-4 py-3 border-0">Company Name</th>
                            <th class="border-0" style="width: 160px;">Type &amp; Business</th>
                            <th class="border-0">Status</th>
                            <th class="border-0"><?= $is_exception_filter ? 'Sourcing Requestor' : 'Sourcing Reviewer' ?></th>
                            <?php if (!$is_exception_filter): ?><th class="border-0">Risk Score</th><?php endif; ?>
                            <?php if (!$is_vm_head): ?><th class="border-0">SLA</th><?php endif; ?>
                            <th class="pe-4 border-0">Action</th>
                        </tr>
                        <tr class="bg-white table-filter-row text-start">
                            <th class="fw-normal py-2">
                                <input type="text" name="search" form="tableFilterForm" class="form-control form-control-sm" placeholder="Cari nama / email..." value="<?= htmlspecialchars($search) ?>">
                            </th>
                            <th class="fw-normal py-2">
                                <input type="text" name="biz_search" form="tableFilterForm" class="form-control form-control-sm" placeholder="Cari type / bisnis..." value="<?= htmlspecialchars($biz_search) ?>">
                            </th>
                            <th class="fw-normal py-2">
                                <select name="status_f" form="tableFilterForm" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Semua</option>
                                    <?php foreach($status_options as $status_val => $status_label): ?>
                                        <option value="<?= $status_val ?>" <?= $status_filter === $status_val ? 'selected' : '' ?>><?= $status_label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </th>
                            <th class="fw-normal py-2">
                                <input type="text" name="reviewer_search" form="tableFilterForm" class="form-control form-control-sm" placeholder="Cari nama..." value="<?= htmlspecialchars($reviewer_search) ?>">
                            </th>
                            <?php if (!$is_exception_filter): ?><th class="fw-normal py-2"></th><?php endif; ?>
                            <?php if (!$is_vm_head): ?><th class="fw-normal py-2"></th><?php endif; ?>
                            <th class="fw-normal py-2 text-center">
                                <button type="submit" form="tableFilterForm" class="btn btn-sm btn-danger" title="Apply Filters"><i class="fas fa-filter"></i></button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($total_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr style="border-bottom: 1px solid #f1f5f9;">
                                    <td class="ps-4 py-3">
                                        <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($row['company_name']) ?></div>
                                        <div class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($row['email'] ?? 'No Email') ?></div>
                                    </td>
                                    <td class="text-center" style="width: 160px; vertical-align: middle; word-break: break-word;">
                                        <span class="badge bg-secondary rounded-pill px-3 py-1 fw-normal shadow-sm"><?= $row['company_type'] ?></span>
                                        <?php if(!empty($row['core_business'])): ?>
                                            <?php $core_biz_spaced = implode(', ', array_filter(array_map('trim', explode(',', $row['core_business'])))); ?>
                                            <span class="small text-muted d-flex align-items-start justify-content-center mt-1" title="<?= htmlspecialchars($core_biz_spaced) ?>" style="font-size: 0.75rem; line-height: 1.2; height: 2.3em; cursor: help;">
                                                <i class="fas fa-tags me-1 mt-1 opacity-50 flex-shrink-0"></i>
                                                <span style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; text-align: left;"><?= htmlspecialchars($core_biz_spaced) ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php
                                            // Status Badge Logic matching screenshot aesthetic
                                            $status = $row['registration_status'];
                                            
                                            $badge_class = 'bg-secondary';
                                            $label = strtoupper(str_replace('_', ' ', $status));

                                            if ($status == 'registration_submitted') {
                                                $label = "REGISTRATION SUBMITTED";
                                                $badge_class = 'bg-info text-dark';
                                            }
                                            if ($status == 'registered') $badge_class = 'bg-info text-dark';
                                            if ($status == 'invited_pq') $badge_class = 'bg-warning text-dark';
                                            if ($status == 'pq_submitted') $badge_class = 'bg-primary';
                                            
                                            if ($status == 'returned_to_sourcing') {
                                                $label = "RETURNED BY VM";
                                                $badge_class = "bg-danger text-white";
                                            }
                                            if ($status == 'under_review_vm') {
                                                $label = "PENDING VM REVIEW";
                                                // Matched warning color from image
                                                $badge_class = "bg-warning text-white"; 
                                            }
                                            if ($status == 'under_review_vm_head') {
                                                $label = "PENDING HEAD APPROVAL";
                                                $badge_class = "bg-primary text-white";
                                            }
                                            if ($status == 'approved') $badge_class = 'bg-success';
                                            if ($status == 'revision_requested') $badge_class = 'bg-danger';
                                            
                                            echo "<span class='badge $badge_class rounded-pill px-3 py-2 shadow-sm border-0' style='letter-spacing: 0.5px; font-size: 0.7rem;'>" . $label . "</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php $person_name = $is_exception_filter ? ($row['er_requestor_name'] ?? '') : ($row['reviewer_name'] ?? ''); ?>
                                        <?php if (!empty($person_name)): ?>
                                            <span class="text-dark fw-medium" style="font-size: 0.9rem;">
                                                <i class="fas fa-user text-muted me-1"></i> <?= htmlspecialchars($person_name) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">-</span>
                                        <?php endif; ?>
                                        <?php if (!$is_exception_filter && !empty($row['registered_by_sourcing_id'])): ?>
                                            <div class="mt-1">
                                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 0.65rem;" title="Sourcing mengisi &amp; mengajukan data ini atas nama vendor, tanpa vendor login sendiri.">
                                                    <i class="fas fa-user-edit me-1"></i>Pendaftaran Langsung
                                                </span>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <?php if (!$is_exception_filter): ?>
                                    <td class="text-center">
                                        <?php if (!empty($row['risk_percentage'])):
                                            $tbl_risk_cat = $row['risk_category'] ?? null;
                                            $tbl_risk_pct = (float)$row['risk_percentage'];
                                            if (empty($tbl_risk_cat) && $tbl_risk_pct > 0) {
                                                $tbl_risk_cat = pqRiskCategory($tbl_risk_pct);
                                            }
                                        ?>
                                            <div class="fw-bold text-dark" style="font-size: 1.05rem;"><?= number_format($row['risk_percentage'], 1) ?>%</div>
                                            <div class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($tbl_risk_cat ?? '-') ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                    <?php if (!$is_vm_head): ?>
                                    <td class="text-center">
                                        <?php
                                            // SLA Badge: only shown for rows that have entered the VM review queue
                                            $sla_states = ['under_review_vm', 'under_review_vm_head', 'returned_to_sourcing', 'approved'];
                                            if (in_array($status, $sla_states) && !empty($row['submission_id'])) {
                                                $sla = getPqSlaBadge(
                                                    $conn,
                                                    $row['pq_sla_deadline']         ?? null,
                                                    $row['pq_sla_first_forward_at'] ?? null,
                                                    isset($row['pq_sla_final_hours']) ? (float)$row['pq_sla_final_hours'] : null,
                                                    isset($row['pq_sla_final_met'])   ? (int)$row['pq_sla_final_met']     : null
                                                );
                                                echo "<span class='badge {$sla['class']} rounded-pill px-3 py-2 shadow-sm border-0' "
                                                   . "style='letter-spacing:0.3px; font-size:0.72rem;' "
                                                   . "title='" . htmlspecialchars($sla['title'], ENT_QUOTES) . "' "
                                                   . "data-bs-toggle='tooltip'>"
                                                   . htmlspecialchars($sla['label']) . "</span>";
                                            } else {
                                                echo '<span class="text-muted small fst-italic">—</span>';
                                            }
                                        ?>
                                    </td>
                                    <?php endif; ?>
                                    <td style="min-width: 150px;">
                                        <div class="d-flex flex-row flex-wrap gap-2 align-items-center">
                                            <!-- Primary Action based on Role + Status -->
                                            <?php
                                            $can_review = false;
                                            if ($is_vm_head && $status == 'under_review_vm_head') $can_review = true;
                                            if (!$is_vm_head && $status == 'under_review_vm') $can_review = true;

                                            // Exception Request button state. A VM Head on a 'waiting' request gets the
                                            // actionable review page; everyone else on an ER row (approved, or non-head
                                            // who cannot approve) gets a read-only "Detail Exception Request" modal.
                                            $er_st            = $row['exception_request_status'] ?? 'none';
                                            $er_is_row        = in_array($er_st, ['under_review_vm_head', 'approved'], true);
                                            $er_can_review    = $is_vm_head && $er_st === 'under_review_vm_head';
                                            $er_show_view     = $er_is_row && !$er_can_review;
                                            $er_view_approved = ($er_st === 'approved');
                                            ?>

                                            <?php if ($er_can_review): ?>
                                                <a href="exception_request_review.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-success text-white btn-reveal" title="Review Exception Request">
                                                    <i class="fas fa-bolt"></i><span class="btn-ico-label">Review Exception Request</span>
                                                </a>
                                            <?php endif; ?>

                                            <?php if ($can_review): ?>
                                                <a href="approve_pq.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-danger btn-reveal" title="Review">
                                                    <i class="fas fa-gavel"></i><span class="btn-ico-label">Review</span>
                                                </a>
                                            <?php elseif (in_array($status, ['approved', 'rejected', 'returned_to_sourcing', 'under_review_vm', 'under_review_vm_head'])): ?>
                                                <a href="approve_pq.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-secondary btn-reveal" title="<?= ($status == 'under_review_vm_head' && !$is_vm_head) ? 'With VM Head' : 'Result' ?>">
                                                    <i class="fas fa-file-lines"></i><span class="btn-ico-label"><?= ($status == 'under_review_vm_head' && !$is_vm_head) ? 'With VM Head' : 'Result' ?></span>
                                                </a>
                                            <?php endif; ?>

                                            <!-- Always show Details Modal trigger -->
                                            <button type="button" class="btn btn-sm btn-info text-white btn-reveal" data-bs-toggle="modal" title="Details" data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i><span class="btn-ico-label">Details</span>
                                            </button>
                                        </div>

                                        <?php if ($er_show_view): ?>
                                        <!-- MODAL: Detail Exception Request (read-only; vendormgt cannot approve here) -->
                                        <div class="modal fade" id="erViewModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered ft-modal-wide">
                                                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                                    <div class="modal-header ft-header border-0">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <span class="ft-header-icon text-white"><i class="fas fa-file-lines"></i></span>
                                                            <div>
                                                                <h5 class="modal-title fw-bold text-white mb-0">Detail Exception Request</h5>
                                                                <div class="text-white-50 small"><?= htmlspecialchars($row['company_name']) ?></div>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <?php
                                                            $er_reviewer_name = trim((string)($row['er_reviewer_name'] ?? '')) ?: 'VM Head';
                                                            $er_view_status_badge = $er_view_approved
                                                                ? '<span class="badge rounded-pill" style="background-color:#d1fae5;color:#0f766e;">Disetujui ' . htmlspecialchars($er_reviewer_name) . '</span>'
                                                                : '<span class="badge rounded-pill" style="background-color:#ccfbf1;color:#115e59;">Menunggu Persetujuan Hadi Sanjaya Risali</span>';
                                                        ?>
                                                        <table class="table table-sm align-middle mb-0">
                                                            <tbody>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold" style="width:38%;">Status</th>
                                                                    <td><?= $er_view_status_badge ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold">Kondisi Vendor</th>
                                                                    <td><?= htmlspecialchars($row['exception_request_reason'] ?? '') ?: '-' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold align-top">Catatan Sourcing</th>
                                                                    <td style="white-space:pre-line;"><?= htmlspecialchars($row['exception_request_notes'] ?? '') ?: '-' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold">Diajukan Oleh</th>
                                                                    <td><?= htmlspecialchars($row['er_requestor_name'] ?? '') ?: '-' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold">Tanggal Pengajuan</th>
                                                                    <td><?= !empty($row['exception_request_at']) ? date('d M Y H:i', strtotime($row['exception_request_at'])) . ' WIB' : '-' ?></td>
                                                                </tr>
                                                                <?php if ($er_view_approved): ?>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold">Tanggal Disetujui</th>
                                                                    <td><?= !empty($row['exception_request_reviewed_at']) ? date('d M Y H:i', strtotime($row['exception_request_reviewed_at'])) . ' WIB' : '-' ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                                <?php if ($er_view_approved && !empty($row['exception_request_vm_notes'])): ?>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold">Catatan VM Head</th>
                                                                    <td style="white-space:pre-line;"><?= htmlspecialchars($row['exception_request_vm_notes']) ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary rounded-pill px-3"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                            <i class="fas fa-arrow-left me-1"></i> Kembali
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <!-- MODAL: Vendor Details (Replicated from Sourcing) -->
                                        <div class="modal fade text-start" id="dashVendorModal<?= $row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                                                    
                                                    <!-- Header -->
                                                    <div class="modal-header bg-danger text-white border-0 py-3">
                                                        <h5 class="modal-title fw-bold" id="vendorModalLabel<?= $row['id'] ?>">
                                                            <i class="fas fa-building me-2"></i> <?= htmlspecialchars($row['company_name']) ?>
                                                        </h5>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>

                                                    <!-- Body -->
                                                    <div class="modal-body p-4" style="background-color: #f4f6f9;">
                                                        <div class="row g-4 mb-4">
                                                            
                                                            <!-- Left Col: Banking & Legal -->
                                                            <div class="col-md-6">
                                                                <div class="card h-100 border-0 shadow-sm vendor-modal-card">
                                                                    <div class="card-body p-4">
                                                                        <h6 class="text-danger fw-bold text-uppercase mb-4" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                                                                            <i class="fas fa-university me-2"></i> Banking & Legal
                                                                        </h6>
                                                                        
                                                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                                                            <span class="text-muted small">Director :</span>
                                                                            <span class="fw-bold text-dark small text-end"><?= htmlspecialchars($row['director_name'] ?? '-') ?></span>
                                                                        </div>
                                                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                                                            <span class="text-muted small">Business :</span>
                                                                            <span class="fw-bold text-dark small text-end"><?= htmlspecialchars($row['company_type'] ?? '-') ?></span>
                                                                        </div>
                                                                        <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                                                            <span class="text-muted small">E-mail :</span>
                                                                            <span class="fw-bold text-dark small text-end"><?= htmlspecialchars($row['email'] ?? '-') ?></span>
                                                                        </div>
                                                                        <div class="d-flex justify-content-between align-items-center">
                                                                            <span class="text-muted small">NIB :</span>
                                                                            <span class="badge bg-light text-dark border px-3 py-1"><?= htmlspecialchars($row['nib_number'] ?? '-') ?></span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                            <!-- Right Col: Risk Category & Docs -->
                                                            <div class="col-md-6">
                                                                <div class="card h-100 border-0 shadow-sm vendor-modal-card">
                                                                    <div class="card-body p-4 d-flex flex-column">
                                                                        <h6 class="text-success fw-bold text-uppercase mb-4" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                                                                            <i class="fas fa-boxes me-2"></i> Risk Category & Docs
                                                                        </h6>

                                                                        <!-- UPDATED Risk Category Card (5-Tier) -->
                                                                        <?php 
                                                                            // Compute risk_category from risk_percentage if NULL
                                                                            $risk_cat = $row['risk_category'] ?? null;
                                                                            $risk_pct = (float)($row['risk_percentage'] ?? 0);

                                                                            if (empty($risk_cat) && $risk_pct > 0) {
                                                                                $risk_cat = pqRiskCategory($risk_pct);
                                                                            }
                                                                            if (empty($risk_cat)) $risk_cat = 'N/A';
                                                                            
                                                                            $risk_bg = 'bg-secondary'; 
                                                                            $text_color = 'text-white'; 
                                                                            $icon = 'fa-times-circle';
                                                                            
                                                                            if ($risk_cat == 'Very Low') { 
                                                                                $risk_bg = 'bg-success'; 
                                                                                $text_color = 'text-white';
                                                                                $icon = 'fa-shield-alt'; 
                                                                            } elseif ($risk_cat == 'Low') { 
                                                                                $risk_bg = 'bg-info'; 
                                                                                $text_color = 'text-white';
                                                                                $icon = 'fa-thumbs-up'; 
                                                                            } elseif ($risk_cat == 'Moderate') { 
                                                                                $risk_bg = 'bg-warning'; 
                                                                                $text_color = 'text-dark'; 
                                                                                $icon = 'fa-balance-scale'; 
                                                                            } elseif ($risk_cat == 'High') {
                                                                                $risk_bg = 'bg-orange'; // Requires inline style below
                                                                                $text_color = 'text-white';
                                                                                $icon = 'fa-exclamation-triangle';
                                                                            } elseif ($risk_cat == 'Critical') {
                                                                                $risk_bg = 'bg-danger';
                                                                                $text_color = 'text-white';
                                                                                $icon = 'fa-exclamation-triangle';
                                                                            }
                                                                        ?>
                                                                        <div class="<?= $risk_bg ?> rounded-3 p-3 d-flex justify-content-between align-items-center mb-4 shadow-sm position-relative overflow-hidden" 
                                                                             style="<?= ($risk_cat == 'High') ? 'background-color: #fd7e14;' : '' ?>">
                                                                            <div class="position-relative z-1">
                                                                                <div class="small fw-bold <?= $text_color ?> text-uppercase mb-1" style="font-size: 0.7rem;">Risk Category</div>
                                                                                <h3 class="mb-0 fw-bold <?= $text_color ?>"><?= htmlspecialchars($risk_cat) ?></h3>
                                                                            </div>
                                                                            <i class="fas <?= $icon ?> fa-3x text-dark opacity-25 position-relative z-1"></i>
                                                                        </div>

                                                                        <!-- Key Documents -->
                                                                        <div class="mt-auto">
                                                                            <h6 class="text-muted fw-bold text-uppercase mb-3" style="font-size: 0.75rem;">
                                                                                <i class="fas fa-folder-open me-1"></i> Key Documents
                                                                            </h6>
                                                                            
                                                                            <div class="d-flex flex-column gap-2">
                                                                                <!-- Bank Attachment -->
                                                                                <?php if (!empty($row['bank_cover_path'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['bank_cover_path']) ?>" 
                                                                                       target="_blank" 
                                                                                       class="text-decoration-none d-flex justify-content-between align-items-center p-2 border rounded-3 bg-white hover-shadow transition-all shadow-sm">
                                                                                        <span class="fw-bold text-dark small"><i class="fas fa-university text-primary me-2"></i> Bank Cover</span>
                                                                                        <i class="fas fa-external-link-alt text-muted" style="font-size: 0.7rem;"></i>
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <div class="p-2 bg-light rounded-3 border border-dashed d-flex justify-content-between align-items-center shadow-sm">
                                                                                        <span class="text-muted small fst-italic"><i class="fas fa-university me-2 opacity-50"></i> Bank Cover</span>
                                                                                        <span class="badge bg-secondary" style="font-size: 0.6rem;">Missing</span>
                                                                                    </div>
                                                                                <?php endif; ?>

                                                                                <!-- Business Ethics Commitment -->
                                                                                <?php if (!empty($row['business_ethics_commitment_file'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['business_ethics_commitment_file']) ?>" 
                                                                                       target="_blank" 
                                                                                       class="text-decoration-none d-flex justify-content-between align-items-center p-2 border rounded-3 bg-white hover-shadow transition-all shadow-sm">
                                                                                        <span class="fw-bold text-dark small"><i class="fas fa-handshake text-success me-2"></i> Business Ethics</span>
                                                                                        <i class="fas fa-external-link-alt text-muted" style="font-size: 0.7rem;"></i>
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <div class="p-2 bg-light rounded-3 border border-dashed d-flex justify-content-between align-items-center shadow-sm">
                                                                                        <span class="text-muted small fst-italic"><i class="fas fa-handshake me-2 opacity-50"></i> Business Ethics</span>
                                                                                        <span class="badge bg-secondary" style="font-size: 0.6rem;">Missing</span>
                                                                                    </div>
                                                                                <?php endif; ?>

                                                                                <!-- Tax Statement Letter -->
                                                                                <?php if (!empty($row['tax_statement_letter_file'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['tax_statement_letter_file']) ?>"
                                                                                       target="_blank"
                                                                                       class="text-decoration-none d-flex justify-content-between align-items-center p-2 border rounded-3 bg-white hover-shadow transition-all shadow-sm">
                                                                                        <span class="fw-bold text-dark small"><i class="fas fa-file-invoice-dollar text-success me-2"></i> Tax Statement Letter</span>
                                                                                        <i class="fas fa-external-link-alt text-muted" style="font-size: 0.7rem;"></i>
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <div class="p-2 bg-light rounded-3 border border-dashed d-flex justify-content-between align-items-center shadow-sm">
                                                                                        <span class="text-muted small fst-italic"><i class="fas fa-file-invoice-dollar me-2 opacity-50"></i> Tax Statement Letter</span>
                                                                                        <span class="badge bg-secondary" style="font-size: 0.6rem;">Missing</span>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>

                                                        </div>

                                                        <!-- Bottom: Contact Info -->
                                                        <div class="card border-0 shadow-sm vendor-modal-card">
                                                            <div class="card-body p-4 d-flex justify-content-between align-items-center flex-wrap gap-3">
                                                                <div>
                                                                    <h6 class="text-muted fw-bold text-uppercase mb-2" style="font-size: 0.7rem; letter-spacing: 0.5px;">Contact Person</h6>
                                                                    <div class="fw-bold text-dark d-flex align-items-center fs-6">
                                                                        <i class="fas fa-user-circle text-secondary me-2 fs-5"></i> <?= htmlspecialchars($row['contact_person'] ?? '') ?>
                                                                    </div>
                                                                </div>
                                                                <div class="text-md-end">
                                                                    <h6 class="text-muted fw-bold text-uppercase mb-2" style="font-size: 0.7rem; letter-spacing: 0.5px;">Phone / Registered</h6>
                                                                    <div class="text-dark fw-medium d-flex align-items-center justify-content-md-end gap-3 small">
                                                                        <span><i class="fas fa-phone text-secondary me-1"></i> <?= htmlspecialchars($row['phone'] ?? '-') ?></span>
                                                                        <span class="text-muted opacity-25">|</span>
                                                                        <span><i class="fas fa-calendar-alt text-secondary me-1"></i> <?= date('d M Y', strtotime($row['created_at'])) ?></span>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                    </div>
                                                    
                                                    <!-- Footer -->
                                                    <div class="modal-footer bg-white border-top-0 py-3 px-4">
                                                        <a href="../sourcing/view_profile.php?id=<?= $row['id'] ?>" class="btn btn-dark rounded-pill px-4">
                                                            <i class="fas fa-user-circle me-1"></i> View Full Profile
                                                        </a>
                                                        <?php if ($er_show_view && $er_view_approved): ?>
                                                        <button type="button" class="btn btn-success fw-bold rounded-pill px-4 me-auto"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#erViewModal<?= $row['id'] ?>"
                                                                title="Lihat detail Exception Request — disetujui VM Head">
                                                            <i class="fas fa-check-circle me-1"></i> Approved Exception
                                                        </button>
                                                        <?php elseif ($er_show_view): ?>
                                                        <button type="button" class="btn fw-bold rounded-pill px-4 me-auto"
                                                                style="background:#0d9488;border:1px solid #0d9488;color:#fff;"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#erViewModal<?= $row['id'] ?>"
                                                                title="Lihat detail Exception Request — menunggu persetujuan VM Head">
                                                            <i class="fas fa-hourglass-half me-1"></i> Pending Approval
                                                        </button>
                                                        <?php else: ?>
                                                        <span class="me-auto"></span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-dark bg-white fw-bold rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- End Modal -->

                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="<?= ($is_vm_head ? 6 : 7) - ($is_exception_filter ? 1 : 0) ?>" class="text-center py-5">
                                <i class="fas fa-search fa-3x text-muted opacity-25 mb-3"></i>
                                <h5 class="text-muted">No vendors found.</h5>
                                <p class="text-muted small">Try adjusting your search or filters.</p>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_rows > 0): ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-4 py-3">
                <div class="text-muted small">
                    Showing <?= $page_offset + 1 ?>&ndash;<?= min($page_offset + $per_page, $total_rows) ?> of <?= $total_rows ?> vendors
                </div>
                <?php if ($total_pages > 1): ?>
                <nav aria-label="Vendor list pagination">
                    <ul class="pagination-minimal">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $page_base_url ?>&page=<?= max(1, $page - 1) ?>">&laquo;</a>
                        </li>
                        <?php
                            $pg_window = 2;
                            $pg_start  = max(1, $page - $pg_window);
                            $pg_end    = min($total_pages, $page + $pg_window);
                        ?>
                        <?php if ($pg_start > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?= $page_base_url ?>&page=1">1</a></li>
                            <?php if ($pg_start > 2): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                        <?php endif; ?>
                        <?php for ($p = $pg_start; $p <= $pg_end; $p++): ?>
                            <li class="page-item <?= $p == $page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $page_base_url ?>&page=<?= $p ?>"><?= $p ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($pg_end < $total_pages): ?>
                            <?php if ($pg_end < $total_pages - 1): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?= $page_base_url ?>&page=<?= $total_pages ?>"><?= $total_pages ?></a></li>
                        <?php endif; ?>
                        <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $page_base_url ?>&page=<?= min($total_pages, $page + 1) ?>">&raquo;</a>
                        </li>
                    </ul>
                </nav>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['er_flash'])):
    $erFlash = $_SESSION['er_flash'];
    unset($_SESSION['er_flash']); // one-time
    $erFlashSuccess = ($erFlash['type'] ?? 'success') === 'success';
?>
<style>
    .er-flash-overlay {
        position: fixed; inset: 0; z-index: 1080; padding: 20px;
        background: rgba(0,0,0,.45);
        display: flex; align-items: center; justify-content: center;
    }
    .er-flash-card {
        background: #fff; border-radius: 16px; max-width: 400px; width: 100%;
        text-align: center; padding: 34px 28px 26px;
        box-shadow: 0 12px 40px rgba(0,0,0,.18);
        animation: erFlashPop .25s ease;
    }
    @keyframes erFlashPop { from { transform: scale(.92); opacity: 0 } to { transform: scale(1); opacity: 1 } }
    .er-flash-icon { width: 64px; height: 64px; border-radius: 50%; margin: 0 auto 18px; display: flex; align-items: center; justify-content: center; }
    .er-flash-icon svg { width: 32px; height: 32px; stroke-width: 2.5; fill: none; stroke-linecap: round; stroke-linejoin: round; }
    .er-flash-card.success .er-flash-icon { background: #e8f5ee; } .er-flash-card.success .er-flash-icon svg { stroke: #198754; }
    .er-flash-card.error   .er-flash-icon { background: #fdecec; } .er-flash-card.error   .er-flash-icon svg { stroke: #dc3545; }
    .er-flash-card h2 { font-size: 1.15rem; font-weight: 700; color: #1f2937; margin-bottom: 8px; }
    .er-flash-card p  { font-size: .9rem; color: #6b7280; line-height: 1.55; margin-bottom: 22px; }
    .er-flash-btn { border: none; cursor: pointer; font-size: .9rem; font-weight: 600; padding: 11px 30px; border-radius: 10px; color: #fff; transition: background .15s; }
    .er-flash-card.success .er-flash-btn { background: #198754; } .er-flash-card.success .er-flash-btn:hover { background: #157347; }
    .er-flash-card.error   .er-flash-btn { background: #dc3545; } .er-flash-card.error   .er-flash-btn:hover { background: #bb2d3b; }
</style>
<div class="er-flash-overlay" id="erFlashOverlay">
    <div class="er-flash-card <?= $erFlashSuccess ? 'success' : 'error' ?>">
        <div class="er-flash-icon">
            <svg viewBox="0 0 24 24">
                <?php if ($erFlashSuccess): ?>
                    <polyline points="20 6 9 17 4 12"></polyline>
                <?php else: ?>
                    <line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line>
                <?php endif; ?>
            </svg>
        </div>
        <h2><?= htmlspecialchars($erFlash['title'] ?? '') ?></h2>
        <p><?= htmlspecialchars($erFlash['msg'] ?? '') ?></p>
        <button type="button" class="er-flash-btn" onclick="document.getElementById('erFlashOverlay').remove()">OK</button>
    </div>
</div>
<script>
    // Dismiss when clicking the dark backdrop (outside the card).
    document.getElementById('erFlashOverlay').addEventListener('click', function (e) {
        if (e.target === this) this.remove();
    });
</script>
<?php endif; ?>

<?php include $path_to_root . 'includes/footer.php'; ?>