<?php
// modules/admin/dashboard.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/exception_request_helper.php';

// 1. Security Check (Role: Admin)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];

// FIX: hoisted here — original was require_once inside the vendor table while-loop (ran on every row)
require_once $path_to_root . 'includes/sla_helper.php';

// CSRF token for JS fetch calls (mark_notif_read, k2_register_action)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$page_title = "Admin Dashboard";
include $path_to_root . 'includes/header.php';

// --- CAPTURE INPUTS ---
$search = isset($_GET['search']) ? trim($conn->real_escape_string($_GET['search'])) : '';
$sort = $_GET['sort'] ?? 'default';
$core_biz_arr = isset($_GET['core_biz']) && is_array($_GET['core_biz']) ? $_GET['core_biz'] : [];

$core_biz_safe = array_map(function($val) use ($conn) {
    return $conn->real_escape_string($val);
}, $core_biz_arr);

$tax_stmt = $conn->query("SELECT DISTINCT sub_category FROM taxonomy WHERE sub_category IS NOT NULL AND sub_category != '' ORDER BY sub_category ASC");
$taxonomies = [];
if($tax_stmt) {
    while($row = $tax_stmt->fetch_assoc()) {
        $taxonomies[] = $row['sub_category'];
    }
}

if (!isset($_GET['filter']) && (!empty($search) || !empty($core_biz_arr))) {
    $filter = 'all';
} else {
    // Admin default view: approved vendors (ready for K2)
    $filter = $_GET['filter'] ?? 'approved';
}

// --- BUILD WHERE CLAUSE ---
$conditions = [];
$conditions[] = "v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)";

// A vendor is eligible for K2 registration if it passed PQ (registration_status
// = 'approved') OR was approved via Exception Request (er.status = 'approved',
// which runs parallel to PQ). This expression captures both.
$k2_eligible = "(v.registration_status = 'approved' OR er.status = 'approved')";

if ($filter === 'approved') {
    $conditions[] = $k2_eligible;
} elseif ($filter === 'k2_pending') {
    $conditions[] = "$k2_eligible AND v.k2_status = 'pending'";
} elseif ($filter === 'k2_in_progress') {
    $conditions[] = "v.k2_status = 'in_progress'";
} elseif ($filter === 'k2_registered') {
    $conditions[] = "v.k2_status = 'registered'";
} elseif ($filter === 'exception_request') {
    $conditions[] = "er.status = 'approved'";
} elseif ($filter === 'pending_vm') {
    $conditions[] = "v.registration_status = 'under_review_vm'";
} elseif ($filter === 'pending_head') {
    $conditions[] = "v.registration_status = 'under_review_vm_head'";
} elseif ($filter !== 'all') {
    $conditions[] = $k2_eligible;
}

if (!empty($search)) {
    $conditions[] = "(v.company_name LIKE '%$search%' OR v.contact_person LIKE '%$search%' OR v.email LIKE '%$search%' OR v.k2_request_number LIKE '%$search%')";
}

if (!empty($core_biz_safe)) {
    $cb_conditions = [];
    foreach($core_biz_safe as $cb) {
        $cb_conditions[] = "v.core_business LIKE '%$cb%'";
    }
    $conditions[] = "(" . implode(" OR ", $cb_conditions) . ")";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// --- BUILD ORDER BY CLAUSE ---
$order_clause = "ORDER BY v.updated_at DESC";

if ($sort === 'newest')      $order_clause = "ORDER BY v.created_at DESC";
elseif ($sort === 'oldest')  $order_clause = "ORDER BY v.created_at ASC";
elseif ($sort === 'name_asc') $order_clause = "ORDER BY v.company_name ASC";
elseif ($sort === 'name_desc') $order_clause = "ORDER BY v.company_name DESC";
elseif ($sort === 'type')    $order_clause = "ORDER BY v.company_type ASC, v.company_name ASC";

// 2. Fetch Vendors (with K2 fields)
$query = "SELECT v.*,
            s.sourcing_reviewer_id,
            s.risk_category,
            s.risk_percentage,
            u.full_name as reviewer_name,
            (CASE WHEN er.status IN ('under_review_vm_head','approved') THEN 1 ELSE 0 END) AS is_exception_request,
            er.status AS exception_request_status
          FROM vendors v
          LEFT JOIN (
              SELECT vendor_id, sourcing_reviewer_id, risk_category, risk_percentage
              FROM pq_submissions
              WHERE id IN (SELECT MAX(id) FROM pq_submissions GROUP BY vendor_id)
          ) s ON v.id = s.vendor_id
          LEFT JOIN users u ON s.sourcing_reviewer_id = u.id
          " . EXCEPTION_REQUEST_LATEST_JOIN . "
          $where_clause
          $order_clause";

$result = $conn->query($query);

// 3. Admin-specific Metrics (focused on K2)
$metric_conditions = [];
$metric_conditions[] = "v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)";
if (!empty($search)) {
    $metric_conditions[] = "(v.company_name LIKE '%$search%' OR v.contact_person LIKE '%$search%' OR v.email LIKE '%$search%')";
}
if (!empty($core_biz_safe)) {
    $cb_metric_conditions = [];
    foreach($core_biz_safe as $cb) {
        $cb_metric_conditions[] = "v.core_business LIKE '%$cb%'";
    }
    $metric_conditions[] = "(" . implode(" OR ", $cb_metric_conditions) . ")";
}
$metric_where = !empty($metric_conditions) ? "WHERE " . implode(" AND ", $metric_conditions) : "";

$metrics_sql = "SELECT
    SUM(CASE WHEN registration_status = 'approved' OR er.status = 'approved' THEN 1 ELSE 0 END) as approved_total,
    SUM(CASE WHEN (registration_status = 'approved' OR er.status = 'approved') AND k2_status = 'pending' THEN 1 ELSE 0 END) as k2_pending,
    SUM(CASE WHEN er.status = 'approved' THEN 1 ELSE 0 END) as exception_request_count,
    SUM(CASE WHEN k2_status = 'in_progress' THEN 1 ELSE 0 END) as k2_in_progress,
    SUM(CASE WHEN k2_status = 'registered' THEN 1 ELSE 0 END) as k2_registered,
    SUM(CASE WHEN sla_status = 'on_time' THEN 1 ELSE 0 END) as on_time_count,
    SUM(CASE WHEN sla_status = 'late' THEN 1 ELSE 0 END) as late_count,
    SUM(CASE WHEN k2_status != 'registered' AND sla_deadline IS NOT NULL AND sla_deadline < NOW() THEN 1 ELSE 0 END) as overdue_now
FROM vendors v " . EXCEPTION_REQUEST_LATEST_JOIN . " $metric_where";

$metrics = $conn->query($metrics_sql)->fetch_assoc();

$approved_total    = $metrics['approved_total'] ?? 0;
$k2_pending_count  = $metrics['k2_pending'] ?? 0;
$exception_request_count  = $metrics['exception_request_count'] ?? 0;
$k2_in_progress    = $metrics['k2_in_progress'] ?? 0;
$k2_registered     = $metrics['k2_registered'] ?? 0;
$on_time_count = $metrics['on_time_count'] ?? 0;
$late_count    = $metrics['late_count'] ?? 0;
$overdue_now   = $metrics['overdue_now'] ?? 0;
$completed_total = $on_time_count + $late_count;
$on_time_rate  = $completed_total > 0 ? round(($on_time_count / $completed_total) * 100, 1) : 0;

$url_params = "&search=" . urlencode($search) . "&sort=" . urlencode($sort);
if (!empty($core_biz_arr)) {
    foreach($core_biz_arr as $cb) {
        $url_params .= "&core_biz[]=" . urlencode($cb);
    }
}
?>

<style>
    .metric-card { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; border-radius: 12px; overflow: hidden; position: relative; cursor: pointer; }
    .metric-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
    .metric-icon { position: absolute; right: 15px; bottom: 10px; font-size: 4rem; opacity: 0.15; z-index: 0; }
    .metric-content { position: relative; z-index: 1; }
    .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #198754 0%, #0f5132 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
    .bg-gradient-info    { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); }
    .bg-gradient-purple  { background: linear-gradient(135deg, #6f42c1 0%, #4b2a8a 100%); }
    a.card-link { text-decoration: none; color: inherit; display: block; }

    /* Responsive tweaks for small screens */
    @media (max-width: 767.98px) {
        .metric-card .display-4 { font-size: 2.25rem; }
        .metric-icon { font-size: 3rem; }
        .navbar-collapse .btn { text-align: left; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Admin Dashboard <span class="badge bg-warning text-dark ms-2" style="font-size: 0.6rem;">AGORA REGISTRATION</span></span>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#adminNav" aria-controls="adminNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="adminNav">
        <div class="d-flex flex-column flex-lg-row align-items-lg-center ms-lg-auto mt-3 mt-lg-0 gap-2 gap-lg-0">
            <a href="<?= $path_to_root ?>workspace/pages/sso_vss.php" class="btn btn-sm btn-nav me-lg-2" title="Launch Workspace Pro">
                <i class="fas fa-rocket me-1"></i> Workspace
            </a>

            <?php
            // --- Notification bell (admin) ---
            require_once $path_to_root . 'includes/notif_helper_inapp.php';
            $notif_count = getUnreadNotifCount($conn, $current_user_id);
            $notif_items = getUnreadNotifs($conn, $current_user_id);
            ?>
            <div class="dropdown me-lg-2">
                <button class="btn btn-sm btn-nav position-relative" data-bs-toggle="dropdown">
                    <i class="fas fa-bell"></i>
                    <?php if ($notif_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;">
                            <?= $notif_count > 99 ? '99+' : $notif_count ?>
                        </span>
                    <?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow border-0 p-0" style="min-width:320px; max-height:400px; overflow-y:auto;">
                    <div class="px-3 py-2 border-bottom fw-bold small bg-light">Notifikasi</div>
                    <?php if (empty($notif_items)): ?>
                        <div class="text-muted small text-center py-4">
                            <i class="fas fa-check-circle fa-2x mb-2 d-block opacity-25"></i>
                            Tidak ada notifikasi baru
                        </div>
                    <?php else: ?>
                        <?php foreach($notif_items as $n): ?>
                            <div class="px-3 py-2 border-bottom small" style="cursor:pointer; background:#fffdf5;" onclick="markNotifRead(<?= $n['id'] ?>, this)">
                                <div class="text-dark"><?= htmlspecialchars($n['message']) ?></div>
                                <div class="text-muted" style="font-size:0.65rem;"><?= date('d M H:i', strtotime($n['created_at'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <a href="<?= $path_to_root ?>modules/admin/invite_vendor.php" class="btn btn-sm btn-nav-solid me-lg-2">
                <i class="fas fa-user-plus me-1"></i> Invite Vendor
            </a>

            <a href="<?= $path_to_root ?>modules/admin/report_k2.php" class="btn btn-sm btn-nav me-lg-3">
                <i class="fas fa-chart-line me-1"></i> Report
            </a>

            <?php $navbar_profile_fallback = 'Admin'; include $path_to_root . 'includes/navbar_profile.php'; ?>
        </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    
    <!-- ── Admin-focused metrics ── -->
    <div class="row mb-4 g-4">
        <div class="col-md-3">
            <a href="?filter=approved<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-success metric-card bg-gradient-success">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-white small fw-bold mb-2">Approved Vendors</h6>
                        <h1 class="display-4 fw-bold mb-0 text-white"><?= $approved_total ?></h1>
                        <div class="small text-white-50 mt-1">All approved</div>
                    </div>
                    <i class="fas fa-check-circle metric-icon text-white"></i>
                </div>
            </a>
        </div>

        <div class="col-md-3">
            <a href="?filter=k2_pending<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-primary metric-card bg-gradient-primary">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-white small fw-bold mb-2">Ready for Agora</h6>
                        <h1 class="display-4 fw-bold mb-0 text-white"><?= $k2_pending_count ?></h1>
                        <div class="small text-white-50 mt-1">Awaiting registration</div>
                    </div>
                    <i class="fas fa-inbox metric-icon text-white"></i>
                </div>
            </a>
        </div>

        <div class="col-md-3">
            <a href="?filter=k2_in_progress<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning metric-card bg-gradient-warning">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-dark small fw-bold mb-2">Agora In Progress</h6>
                        <h1 class="display-4 fw-bold mb-0 text-dark"><?= $k2_in_progress ?></h1>
                        <div class="small text-dark mt-1 opacity-75">Awaiting code</div>
                    </div>
                    <i class="fas fa-spinner metric-icon text-dark"></i>
                </div>
            </a>
        </div>

        <div class="col-md-3">
            <a href="?filter=k2_registered<?= $url_params ?>" class="card-link">
                <div class="card h-100 shadow-sm border-0 border-start border-4 border-purple metric-card bg-gradient-purple">
                    <div class="card-body metric-content">
                        <h6 class="text-uppercase text-white small fw-bold mb-2">Registered in Agora</h6>
                        <h1 class="display-4 fw-bold mb-0 text-white"><?= $k2_registered ?></h1>
                        <div class="small text-white-50 mt-1">Completed</div>
                    </div>
                    <i class="fas fa-registered metric-icon text-white"></i>
                </div>
            </a>
        </div>
    </div>
    <!-- ── SLA KPI Row ── -->
<div class="row mb-4 g-4">
    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-success">
            <div class="card-body">
                <h6 class="text-uppercase text-muted small fw-bold mb-2">SLA On-Time Rate</h6>
                <h1 class="display-5 fw-bold mb-0 text-success"><?= $on_time_rate ?>%</h1>
                <div class="small text-muted mt-1"><?= $on_time_count ?> on-time / <?= $completed_total ?> completed</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-danger">
            <div class="card-body">
                <h6 class="text-uppercase text-muted small fw-bold mb-2">Currently Overdue</h6>
                <h1 class="display-5 fw-bold mb-0 text-danger"><?= $overdue_now ?></h1>
                <div class="small text-muted mt-1">Past SLA, not registered</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning">
            <div class="card-body">
                <h6 class="text-uppercase text-muted small fw-bold mb-2">Late Completions</h6>
                <h1 class="display-5 fw-bold mb-0 text-warning"><?= $late_count ?></h1>
                <div class="small text-muted mt-1">Finished after SLA</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="holidays.php" class="card-link">
            <div class="card h-100 shadow-sm border-0 border-start border-4 border-secondary">
                <div class="card-body d-flex flex-column justify-content-center align-items-center text-center py-4">
                    <i class="fas fa-calendar-alt fa-2x text-secondary mb-2"></i>
                    <h6 class="fw-bold text-dark mb-0">Configure Holidays</h6>
                    <div class="small text-muted">National + joint leave</div>
                </div>
            </div>
        </a>
    </div>
</div>

    <!-- ── Search & Filter Form ── -->
    <div class="card shadow-sm mb-3 border-0 bg-light">
        <div class="card-body p-3">
            <form method="GET" action="dashboard.php" class="row g-2 align-items-center">
                <?php if(isset($_GET['filter'])): ?>
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($_GET['filter']) ?>">
                <?php endif; ?>
                
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control border-start-0" placeholder="Cari company, contact, atau email..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>

                <div class="col-md-4">
                    <select name="sort" class="form-select">
                        <option value="default" <?= $sort=='default'?'selected':'' ?>>Default (Latest)</option>
                        <option value="newest" <?= $sort=='newest'?'selected':'' ?>>Newest First</option>
                        <option value="oldest" <?= $sort=='oldest'?'selected':'' ?>>Oldest First</option>
                        <option value="name_asc" <?= $sort=='name_asc'?'selected':'' ?>>Name (A-Z)</option>
                        <option value="name_desc" <?= $sort=='name_desc'?'selected':'' ?>>Name (Z-A)</option>
                    </select>
                </div>

                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm">Apply Filters</button>
                    <?php if(!empty($search) || $sort != 'default' || (isset($_GET['filter']) && $_GET['filter'] != 'approved')): ?>
                        <a href="dashboard.php" class="btn btn-outline-secondary shadow-sm" title="Clear All"><i class="fas fa-redo"></i></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <h3 class="mb-3">Vendor List 
        <small class="text-muted" style="font-size:0.9rem;">— Admin view (Agora registration)</small>
    </h3>

    <div class="card shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="min-width: 260px;">Company Name</th>
                            <th>Type & Business</th>
                            <th>Contact Person</th>
                            <th>Sourcing Requestor</th>
                            <th>Registration Status</th>
                            <th>Agora Status</th>
                            <th>Request Number</th>
                            <th>SLA</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <?php
                                $status = $row['registration_status'];
                                $k2     = $row['k2_status'] ?? 'pending';

                                // Exception Request flag — vendor authorized for K2 WITHOUT completed PQ.
                                $is_er_approved = (int)($row['is_exception_request'] ?? 0) === 1
                                                  && ($row['exception_request_status'] ?? '') === 'approved';

                                // Pre-calculate modal deadline for pending vendors.
                                $modal_deadline = $row['sla_deadline'] ?? '';
                                if ($k2 === 'pending' && !empty($row['updated_at'])) {
                                    $modal_deadline = addWorkingHours($conn, $row['updated_at'], K2_SLA_HOURS);
                                }
                            ?>
                                <tr>
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-1">
                                            <strong><?= htmlspecialchars($row['company_name']) ?></strong>
                                            <?php if ($is_er_approved): ?>
                                                <span class="badge" style="font-size:0.8rem;padding:0.2em 0.45em;background:#fef3c7;color:#92400e;border:1px solid #fcd34d;" data-bs-toggle="tooltip" title="Exception Request">
                                                    <i class="fas fa-bolt"></i>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($row['email'] ?? 'No Email') ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary mb-1"><?= htmlspecialchars($row['company_type']) ?></span><br>
                                        <?php if(!empty($row['core_business'])): ?>
                                            <span class="small text-muted" style="font-size: 0.75rem;">
                                                <i class="fas fa-tags me-1 opacity-50"></i> <?= htmlspecialchars($row['core_business']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['contact_person']) ?><br>
                                        <small><?= htmlspecialchars($row['phone'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if(!empty($row['reviewer_name'])): ?>
                                            <span class="badge bg-light text-dark border">
                                                <i class="fas fa-user me-1"></i> <?= htmlspecialchars($row['reviewer_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-muted border">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            $status = $row['registration_status'];
                                            $badge_class = 'bg-secondary';
                                            $label = strtoupper(str_replace('_', ' ', $status));

                                            if ($status == 'approved') $badge_class = 'bg-success';
                                            elseif ($status == 'under_review_vm') { $label = "WITH VM TEAM"; $badge_class = "bg-secondary"; }
                                            elseif ($status == 'under_review_vm_head') { $label = "WITH VM HEAD"; $badge_class = "bg-secondary"; }
                                            elseif ($status == 'pq_submitted') $badge_class = 'bg-primary';
                                            elseif ($status == 'invited_pq') $badge_class = 'bg-warning text-dark';
                                            elseif ($status == 'registration_submitted') { $label = "REGISTRATION SUBMITTED"; $badge_class = 'bg-info text-dark'; }
                                            
                                            echo "<span class='badge $badge_class'>" . $label . "</span>";
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                            $k2 = $row['k2_status'] ?? 'pending';
                                            if ($status === 'approved' || $is_er_approved):
                                                if ($k2 === 'registered'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i> <?= htmlspecialchars($row['vendor_code'] ?? 'REGISTERED') ?>
                                                    </span>
                                                <?php elseif ($k2 === 'in_progress'): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-spinner me-1"></i> IN PROGRESS
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-dark border">PENDING</span>
                                                <?php endif;
                                            else: ?>
                                                <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['k2_request_number'])): ?>
                                            <span class="badge bg-light text-dark border font-monospace fw-bold"
                                                  <?php if (!empty($row['k2_remarks'])): ?>
                                                      data-bs-toggle="tooltip"
                                                      data-bs-placement="left"
                                                      title="Remarks: <?= htmlspecialchars($row['k2_remarks']) ?>"
                                                  <?php endif; ?>
                                            >
                                                <?= htmlspecialchars($row['k2_request_number']) ?>
                                                <?php if (!empty($row['k2_remarks'])): ?>
                                                    <i class="fas fa-comment-alt ms-1 opacity-50" style="font-size:0.65rem;"></i>
                                                <?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted"
                                                  <?php if (!empty($row['k2_remarks'])): ?>
                                                      data-bs-toggle="tooltip"
                                                      data-bs-placement="left"
                                                      title="Remarks: <?= htmlspecialchars($row['k2_remarks']) ?>"
                                                  <?php endif; ?>
                                            >—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        // Pass $conn → working-hours-aware countdown (Asia/Jakarta, 4 wd SLA)
                                        $sla = getSlaStatus($modal_deadline ?: ($row['sla_deadline'] ?? null), $row['k2_registered_at'] ?? null, $conn);
                                        ?>
                                        <span class="badge <?= $sla['class'] ?>" title="<?= htmlspecialchars($sla['title'] ?? '') ?>">
                                            <?= htmlspecialchars($sla['label']) ?>
                                        </span>
                                    </td>
                                    <td style="min-width: 140px;">
                                        <div class="d-flex flex-column flex-xl-row flex-wrap gap-2 align-items-stretch align-items-xl-center">
                                        <?php if ($status == 'approved' || $is_er_approved): ?>

                                            <?php if ($k2 === 'pending'): ?>
                                                <button type="button" class="btn btn-sm btn-primary fw-bold"
                                                        onclick="openK2Modal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['company_name'], ENT_QUOTES) ?>', 'pending', '<?= htmlspecialchars($row['k2_request_number'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($modal_deadline, ENT_QUOTES) ?>')">
                                                    <i class="fas fa-registered me-1"></i> Regist Agora
                                                </button>
                                            <?php elseif ($k2 === 'in_progress'): ?>
                                                <button type="button" class="btn btn-sm btn-warning fw-bold"
                                                        onclick="openK2Modal(<?= $row['id'] ?>, '<?= htmlspecialchars($row['company_name'], ENT_QUOTES) ?>', 'in_progress', '<?= htmlspecialchars($row['k2_request_number'] ?? '', ENT_QUOTES) ?>', '<?= htmlspecialchars($row['sla_deadline'] ?? '', ENT_QUOTES) ?>')">
                                                    <i class="fas fa-edit me-1"></i> Enter Code
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-success" disabled>
                                                    <i class="fas fa-check-double me-1"></i> Done
                                                </button>
                                            <?php endif; ?>
                                            
                                            <a href="<?= $path_to_root ?>modules/admin/review_pq.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-dark" title="View PQ Result (read-only)">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small fst-italic">Not approved yet</span>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" class="text-center py-5">
                                <i class="fas fa-search fa-3x text-muted opacity-25 mb-3"></i>
                                <h5 class="text-muted">No vendors found.</h5>
                                <p class="text-muted small">Try adjusting your search or filters.</p>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── K2 Registration Modal ── -->
<div class="modal fade" id="k2Modal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">

            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold text-white">
                    <i class="fas fa-registered me-2"></i> Registrasi Agora
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body p-4">
                <div class="mb-3 p-3 bg-light rounded border">
                    <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size:0.7rem;">Vendor</div>
                    <div class="fw-bold text-dark fs-6" id="k2VendorName">—</div>
                </div>

                <div class="mb-3 p-3 rounded border" id="k2DeadlineBlock" style="display:none;">
                    <div class="small text-muted fw-bold text-uppercase mb-1" style="font-size:0.7rem;">
                        <i class="fas fa-clock me-1 text-warning"></i> SLA Deadline
                    </div>
                    <div class="fw-bold font-monospace fs-6" id="k2DeadlineDisplay">-</div>
                    <div class="small text-muted mt-1" id="k2DeadlineCountdown"></div>
                </div>

                <div id="k2StepPending">
                    <div class="alert alert-info small">
                        <i class="fas fa-info-circle me-1"></i>
                        Masukkan <strong>No.Request</strong>, lalu klik <strong>"Start Regist to Agora"</strong>.
                        Status vendor akan diubah menjadi <strong>In Progress</strong>.
                    </div>
                    <div class="mb-3 p-3 rounded border border-warning bg-warning bg-opacity-10">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="k2AnomalyCheck" onchange="toggleAnomaly()">
                            <label class="form-check-label fw-bold text-warning-emphasis" for="k2AnomalyCheck">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                Vendor sudah terdaftar di Agora (Anomali)
                            </label>
                        </div>
                        <div class="small text-muted mt-1 ms-4">
                            Centang jika vendor ini ternyata sudah memiliki Vendor Code di sistem Agora.
                        </div>
                    </div>

                    <div id="k2AnomalyVendorCode" class="mb-3 d-none">
                        <label class="form-label fw-bold small">
                            Vendor Code Existing <span class="text-muted fw-normal">(opsional)</span>
                        </label>
                        <input type="text" id="k2AnomalyCodeInput"
                               class="form-control font-monospace text-uppercase"
                               placeholder="Contoh: 100200XXXX"
                               autocomplete="off">
                        <div class="form-text text-muted">Isi jika Vendor Code sudah diketahui.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">
                            No.Request <span class="text-danger">*</span>
                        </label>
                        <input type="text" id="k2RequestNumberInput"
                               class="form-control form-control-lg fw-bold font-monospace text-uppercase"
                               placeholder="20260205-00001"
                               pattern="\d{8}-\d{5}"
                               maxlength="14"
                               autocomplete="off">
                        <div class="form-text text-muted">Format: YYYYMMDD-XXXXX &nbsp;·&nbsp; contoh: 20260205-00001</div>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-primary fw-bold py-2" onclick="startK2()">
                            <i class="fas fa-play-circle me-2"></i> Start Regist to Agora
                        </button>
                    </div>
                </div>

                <div id="k2StepInProgress" class="d-none">
                    <div class="alert alert-warning small">
                        <i class="fas fa-hourglass-half me-1"></i>
                        Masukkan <strong>Vendor Code</strong> dari sistem Agora,
                        lalu klik <strong>"Mark as Finished"</strong>.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Vendor Code <span class="text-danger">*</span></label>
                        <input type="text" id="k2VendorCodeInput" class="form-control form-control-lg fw-bold font-monospace"
                               placeholder="Contoh: 100200XXXX" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">
                            Remarks <span class="text-muted fw-normal">(opsional)</span>
                        </label>
                        <textarea id="k2RemarksInput"
                                  class="form-control"
                                  rows="3"
                                  maxlength="500"
                                  placeholder="Catatan khusus, kendala, atau informasi tambahan..."></textarea>
                    </div>
                    <div class="d-grid">
                        <button class="btn btn-success fw-bold py-2" onclick="finishK2()">
                            <i class="fas fa-check-circle me-2"></i> Mark as Finished
                        </button>
                    </div>
                </div>

                <div id="k2StepDone" class="d-none text-center py-3">
                    <i class="fas fa-check-circle text-success fa-3x mb-3"></i>
                    <div class="fw-bold text-success fs-5">Berhasil Didaftarkan!</div>
                    <div class="text-muted small mt-1">Vendor Code: <strong id="k2DoneCode" class="font-monospace text-dark"></strong></div>
                    <div class="text-muted small mt-1">Sourcing Requestor telah diberitahu.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let k2VendorId = null;
// CSRF token injected server-side for all fetch() calls
const CSRF_TOKEN = <?= json_encode($_SESSION['csrf_token']) ?>;

function openK2Modal(vendorId, vendorName, currentStatus, vcrNumber = '', slaDeadline = '') {
    k2VendorId = vendorId;
    document.getElementById('k2VendorName').textContent = vendorName;

    const deadlineBlock = document.getElementById('k2DeadlineBlock');
    const deadlineDisplay = document.getElementById('k2DeadlineDisplay');
    const deadlineCountdown = document.getElementById('k2DeadlineCountdown');

    if (slaDeadline) {
        const d = new Date(slaDeadline.replace(' ', 'T') + '+07:00');
        const pad = n => String(n).padStart(2, '0');
        const formatted = `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${String(d.getFullYear()).slice(-2)} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        const now = new Date();
        const diffMs = d - now;
        const diffH = Math.floor(diffMs / 3600000);
        const diffM = Math.floor((diffMs % 3600000) / 60000);
        let countdownStr = '';
        let countdownColor = 'text-success';

        if (diffMs < 0) {
            countdownStr = 'Overdue';
            countdownColor = 'text-danger fw-bold';
        } else if (diffH < 8) {
            countdownStr = `${diffH}h ${diffM}m remaining`;
            countdownColor = 'text-danger';
        } else if (diffH < 16) {
            countdownStr = `${diffH}h ${diffM}m remaining`;
            countdownColor = 'text-warning fw-bold';
        } else {
            countdownStr = `${diffH}h ${diffM}m remaining`;
            countdownColor = 'text-success';
        }

        deadlineDisplay.textContent = formatted + ' WIB';
        deadlineCountdown.className = `small mt-1 ${countdownColor}`;
        deadlineCountdown.textContent = countdownStr;
        deadlineBlock.className = diffMs < 0
            ? 'mb-3 p-3 rounded border border-danger bg-danger bg-opacity-10'
            : diffH < 8
                ? 'mb-3 p-3 rounded border border-warning bg-warning bg-opacity-10'
                : 'mb-3 p-3 rounded border border-success bg-success bg-opacity-10';
        deadlineBlock.style.display = '';
    } else {
        deadlineBlock.style.display = 'none';
    }

    document.getElementById('k2StepPending').classList.add('d-none');
    document.getElementById('k2StepInProgress').classList.add('d-none');
    document.getElementById('k2StepDone').classList.add('d-none');
    document.getElementById('k2VendorCodeInput').value = '';
    document.getElementById('k2RemarksInput').value = '';
    document.getElementById('k2RequestNumberInput').value = vcrNumber;  // ← pre-fill jika sudah ada
    document.getElementById('k2RequestNumberInput').classList.remove('is-invalid');
    document.getElementById('k2AnomalyCheck').checked = false;
    document.getElementById('k2AnomalyCodeInput').value = '';
    toggleAnomaly();

    if (currentStatus === 'pending') {
        document.getElementById('k2StepPending').classList.remove('d-none');
    } else if (currentStatus === 'in_progress') {
        document.getElementById('k2StepInProgress').classList.remove('d-none');
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('k2Modal')).show();
}

function startK2() {
    const isAnomaly = document.getElementById('k2AnomalyCheck').checked;
    const vcrInput = document.getElementById('k2RequestNumberInput');
    const vcr = vcrInput.value.trim().toUpperCase();

    if (!isAnomaly && (!vcr || !/^\d{8}-\d{5}$/.test(vcr))) {
        vcrInput.focus();
        vcrInput.classList.add('is-invalid');
        return;
    }
    vcrInput.classList.remove('is-invalid');
    const existingCode = document.getElementById('k2AnomalyCodeInput').value.trim();

    fetch('k2_register_action.php', {
        method: 'POST',
        body: new URLSearchParams({
            action:            'start_k2',
            vendor_id:         k2VendorId,
            k2_request_number: isAnomaly ? '' : vcr,
            is_anomaly:        isAnomaly ? '1' : '0',
            anomaly_code:      existingCode,
            csrf_token:        CSRF_TOKEN
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            if (data.is_anomaly) {
                setTimeout(() => location.reload(), 1500);
                return;
            }
            document.getElementById('k2StepPending').classList.add('d-none');
            document.getElementById('k2StepInProgress').classList.remove('d-none');
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(() => alert('Network error — please try again.'));
}

function finishK2() {
    const codeInput = document.getElementById('k2VendorCodeInput');
    const code = codeInput.value.trim();
    if (!code) {
        codeInput.focus();
        codeInput.classList.add('is-invalid');
        return;
    }
    codeInput.classList.remove('is-invalid');

    // Disable button during request to prevent double-submit
    const btn = document.querySelector('#k2StepInProgress .btn-success');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Menyimpan...';

    fetch('k2_register_action.php', {
        method: 'POST',
        body: new URLSearchParams({
            action: 'finish_k2',
            vendor_id: k2VendorId,
            vendor_code: code,
            k2_remarks: document.getElementById('k2RemarksInput').value.trim(),
            csrf_token: CSRF_TOKEN
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            document.getElementById('k2StepInProgress').classList.add('d-none');
            document.getElementById('k2DoneCode').textContent = data.vendor_code;
            document.getElementById('k2StepDone').classList.remove('d-none');
            setTimeout(() => location.reload(), 2000);
        } else {
            alert('Error: ' + data.message);
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    })
    .catch(() => {
        alert('Network error — please try again.');
        btn.disabled = false;
        btn.innerHTML = originalText;
    });
}
function markNotifRead(id, el) {
    fetch('mark_notif_read.php', {
        method: 'POST',
        body: new URLSearchParams({ notif_id: id, csrf_token: CSRF_TOKEN })
    });
    el.style.opacity = '0.5';
    const badge = document.querySelector('.navbar .badge.bg-danger');
    if (badge) {
        const cur = parseInt(badge.textContent) || 0;
        if (cur - 1 <= 0) badge.remove();
        else badge.textContent = (cur - 1) > 99 ? '99+' : (cur - 1);
    }
}

document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
});

function toggleAnomaly() {
    const isAnomaly = document.getElementById('k2AnomalyCheck').checked;
    const vcrWrapper = document.getElementById('k2RequestNumberInput').closest('.mb-3');
    const vcCodeBox = document.getElementById('k2AnomalyVendorCode');

    if (isAnomaly) {
        vcrWrapper.style.opacity = '0.4';
        vcrWrapper.style.pointerEvents = 'none';
        vcCodeBox.classList.remove('d-none');
        document.getElementById('k2RequestNumberInput').classList.remove('is-invalid');
    } else {
        vcrWrapper.style.opacity = '';
        vcrWrapper.style.pointerEvents = '';
        vcCodeBox.classList.add('d-none');
        document.getElementById('k2AnomalyCodeInput').value = '';
    }
}
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
