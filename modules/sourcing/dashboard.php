<?php
// modules/sourcing/dashboard.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/profile_helper.php';
require_once $path_to_root . 'includes/vendor_risk_helper.php';
require_once $path_to_root . 'includes/exception_request_helper.php';

// 1. Security Check (Role: Sourcing)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];
$page_title = "Sourcing Dashboard";
include $path_to_root . 'includes/header.php';

// --- CAPTURE INPUTS ---
$search = isset($_GET['search']) ? trim($conn->real_escape_string($_GET['search'])) : '';
$sort = $_GET['sort'] ?? 'default';
$core_biz_arr = isset($_GET['core_biz']) && is_array($_GET['core_biz']) ? $_GET['core_biz'] : [];
// Drop the empty "Semua" option so it doesn't count as an active filter.
$core_biz_arr = array_values(array_filter($core_biz_arr, function($v) { return $v !== ''; }));

// Per-column filters from the table header row. $status_options doubles as the
// whitelist for the status value and the option list for its dropdown.
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
// Responsibility filter: 'unassigned' or a sourcing user id.
$resp_raw    = $_GET['resp'] ?? '';
$resp_filter = ($resp_raw === 'unassigned' || ctype_digit($resp_raw)) ? $resp_raw : '';

// Sourcing users for the Responsibility dropdown (option value = user id).
$sourcing_users = [];
$su_res = $conn->query("SELECT id, full_name FROM users WHERE role = 'sourcing' AND COALESCE(is_test_account, 0) = 0 ORDER BY full_name ASC");
if ($su_res) {
    while ($su = $su_res->fetch_assoc()) {
        $sourcing_users[] = $su;
    }
}
$biz_search     = isset($_GET['biz_search']) ? trim($conn->real_escape_string($_GET['biz_search'])) : '';
$contact_search = isset($_GET['contact_search']) ? trim($conn->real_escape_string($_GET['contact_search'])) : '';

// Sanitize Core Business Array
$core_biz_safe = array_map(function($val) use ($conn) {
    return $conn->real_escape_string($val);
}, $core_biz_arr);

// The per-column filters always apply WITHIN the active tab / metric-card
// scope (Worklist, Total Registered, dst.) — they never widen the view.
$filter = $_GET['filter'] ?? 'worklist'; // Worklist tab is the default view

// --- BUILD WHERE CLAUSE ---
$conditions = [];

// Always exclude developer/QA test accounts from sourcing dashboards.
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
    $conditions[] = "er.status IN ('under_review_vm_head', 'approved')";
} elseif ($filter === 'exception_request_risk') {
    // Exception Request vendors already registered in K2 (transacting) but still not
    // PQ-approved. The 2+ PO count is confirmed per-row in PHP (the row loop
    // skips any that don't meet it) since PO data lives in a separate DB.
    // Scoped to the logged-in Sourcing user's own submissions, matching the
    // per-user login risk popup that links here.
    $conditions[] = "er.status IN ('under_review_vm_head', 'approved')";
    $conditions[] = "er.submitted_by = " . (int)$current_user_id;
    $conditions[] = "v.k2_status = 'registered'";
    $conditions[] = "v.registration_status != 'approved'";
} elseif ($filter === 'exception_request_waiting') {
    $conditions[] = "er.status = 'under_review_vm_head'";
} elseif ($filter === 'exception_request_approved') {
    $conditions[] = "er.status = 'approved'";
} elseif ($filter === 'exception_request_pq_pending') {
    // Vendor ber-ER aktif milik user ini yang PQ-nya belum selesai — persis
    // isi kuota Exception Request (getExceptionRequestPqDebt). Target tautan
    // "Lihat Vendor" pada popup kuota penuh.
    $conditions[] = "er.status IN ('under_review_vm_head', 'approved')";
    $conditions[] = "er.submitted_by = " . (int)$current_user_id;
    $conditions[] = "v.registration_status != 'approved'";
} elseif ($filter === 'worklist') {
    // Worklist tab: PQs that need sourcing action — submitted (to review) or
    // returned/revision (to fix). Shared across all sourcing accounts; the
    // default sort still floats the logged-in user's own reviews to the top.
    $conditions[] = "v.registration_status IN ('pq_submitted', 'returned_to_sourcing', 'revision_requested')";
} elseif ($filter === 'waiting_pq') {
    // Waiting for PQ tab: all vendors registered via Exception Request whose PQ
    // process hasn't finished yet — invited, submitted, sent back for revision,
    // or under VM/VM Head review. Shows every sourcing PIC's ER submissions,
    // not just the logged-in user's own.
    $conditions[] = "v.registration_status IN ('invited_pq', 'pq_submitted', 'revision_requested', 'returned_to_sourcing', 'under_review_vm', 'under_review_vm_head')";
    $conditions[] = "er.status IN ('under_review_vm_head', 'approved')";
} elseif ($filter !== 'all') { // default
    $conditions[] = "v.registration_status IN ('registered', 'invited_pq', 'pq_submitted')";
}

// Add General Search Condition (Removed Core Business from here)
if (!empty($search)) {
    $conditions[] = "(v.company_name LIKE '%$search%' OR v.contact_person LIKE '%$search%' OR v.email LIKE '%$search%')";
}

// Per-column Contact Person filter (PIC name or phone number)
if ($contact_search !== '') {
    $conditions[] = "(v.contact_person LIKE '%$contact_search%' OR v.phone LIKE '%$contact_search%')";
}

// Per-column Type & Business filter (company type or core business)
if ($biz_search !== '') {
    $conditions[] = "(v.company_type LIKE '%$biz_search%' OR v.core_business LIKE '%$biz_search%')";
}

// Per-column Status filter (value already whitelisted against $status_options)
if ($status_filter !== '') {
    $conditions[] = "v.registration_status = '" . $conn->real_escape_string($status_filter) . "'";
}

// Add Core Business Dropdown Condition (MULTI-SELECT)
if (!empty($core_biz_safe)) {
    $cb_conditions = [];
    foreach($core_biz_safe as $cb) {
        $cb_conditions[] = "v.core_business LIKE '%$cb%'";
    }
    $conditions[] = "(" . implode(" OR ", $cb_conditions) . ")";
}

$where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

// --- BUILD ORDER BY CLAUSE ---
$order_clause = "ORDER BY (s.sourcing_reviewer_id = $current_user_id) DESC, v.updated_at DESC"; // Default

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

// 2. Fetch All Vendors (Filtered & Sorted)
$query = "SELECT v.*,
            s.sourcing_reviewer_id,
            s.risk_category,
            s.risk_percentage,
            u.full_name as reviewer_name,
            (CASE WHEN er.status IN ('under_review_vm_head','approved') THEN 1 ELSE 0 END) AS is_exception_request,
            er.status AS exception_request_status,
            er.submitted_by AS exception_request_by,
            er.submitted_at AS exception_request_at,
            er.reason AS exception_request_reason,
            er.submitted_notes AS exception_request_notes,
            er.review_notes AS exception_request_vm_notes,
            er.reviewed_at AS exception_request_reviewed_at,
            eru.full_name as exception_request_by_name,
            erv.full_name as exception_request_reviewer_name
          FROM vendors v
          LEFT JOIN (
              SELECT vendor_id, sourcing_reviewer_id, risk_category, risk_percentage
              FROM pq_submissions
              WHERE id IN (SELECT MAX(id) FROM pq_submissions GROUP BY vendor_id)
          ) s ON v.id = s.vendor_id
          LEFT JOIN users u ON s.sourcing_reviewer_id = u.id
          " . EXCEPTION_REQUEST_LATEST_JOIN . "
          LEFT JOIN users eru ON er.submitted_by = eru.id
          LEFT JOIN users erv ON er.reviewed_by = erv.id
          $where_clause
          $order_clause";

$result = $conn->query($query);

// Pre-fetch PO counts per vendor (by company_name) from the workspace DB, used
// to flag Exception Request vendors already registered in K2 and transacting (2+ POs)
// while still not PQ-approved — a risk-exposure warning shown in the table.
$vendor_po_count_map = [];
$conn_ws = getWorkspaceConnection();
if ($conn_ws) {
    $po_sql = "SELECT vendor_name, COUNT(DISTINCT po_number) as po_count
               FROM prs
               WHERE po_number IS NOT NULL AND po_number != ''
               GROUP BY vendor_name";
    $po_res = $conn_ws->query($po_sql);
    if ($po_res) {
        while ($po_row = $po_res->fetch_assoc()) {
            $vendor_po_count_map[$po_row['vendor_name']] = (int)$po_row['po_count'];
        }
    }
    $conn_ws->close();
}

// --- PAGINATION ---
// Some filters (the exception_request_risk PO-count check, and the resp_filter
// ownership fallback for Exception Request vendors) can only be evaluated in
// PHP after each row is fetched, so the full result set is filtered here
// first and then sliced into pages of $per_page rows.
$per_page = 20;
$all_rows = [];
while ($r = $result->fetch_assoc()) {
    $all_rows[] = $r;
}

$filtered_rows = [];
foreach ($all_rows as $r) {
    $r_po_count = $vendor_po_count_map[$r['company_name']] ?? 0;
    if ($filter === 'exception_request_risk' && !isExceptionRequestK2Risk($r, $r_po_count)) {
        continue;
    }

    $r_reviewer_id = $r['sourcing_reviewer_id'];
    if (is_null($r_reviewer_id) && !empty($r['exception_request_by'])) {
        $r_reviewer_id = $r['exception_request_by'];
    }
    if ($resp_filter === 'unassigned' && !is_null($r_reviewer_id)) continue;
    if (ctype_digit($resp_filter) && (int)$r_reviewer_id !== (int)$resp_filter) continue;

    $filtered_rows[] = $r;
}

$total_rows  = count($filtered_rows);
$total_pages = max(1, (int)ceil($total_rows / $per_page));
$page        = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $total_pages) $page = $total_pages;
$page_offset = ($page - 1) * $per_page;
$paged_rows  = array_slice($filtered_rows, $page_offset, $per_page);

// List of Exception Request / K2-registered / not-yet-PQ-approved vendors with
// 2+ POs — used for the login popup below. Independent of the
// search/filter/core_biz GET params so the popup always reflects the true,
// current risk list. Scoped PER SOURCING USER: only vendors whose Exception
// Request was submitted by the logged-in account are counted, so each Sourcing
// user is only warned about their own submissions.
$er_risk_vendors = [];
$risk_check_sql = "SELECT v.id, v.company_name, v.k2_status, v.registration_status,
                        (CASE WHEN er.status IN ('under_review_vm_head','approved') THEN 1 ELSE 0 END) AS is_exception_request
                    FROM vendors v
                    " . EXCEPTION_REQUEST_LATEST_JOIN . "
                    WHERE v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)
                      AND er.status IN ('under_review_vm_head', 'approved')
                      AND er.submitted_by = " . (int)$current_user_id . "
                      AND v.k2_status = 'registered'
                      AND v.registration_status != 'approved'";
$risk_check_res = $conn->query($risk_check_sql);
if ($risk_check_res) {
    while ($rv = $risk_check_res->fetch_assoc()) {
        $rv_po_count = $vendor_po_count_map[$rv['company_name']] ?? 0;
        if (isExceptionRequestK2Risk($rv, $rv_po_count)) {
            $er_risk_vendors[] = [
                'id' => $rv['id'],
                'company_name' => $rv['company_name'],
                'po_count' => $rv_po_count,
            ];
        }
    }
}

// Show the risk popup once per login (flag is set at login time in login_action.php
// and cleared here immediately after use, so refreshing/navigating the dashboard
// within the same session won't keep re-showing it).
$show_er_risk_popup = !empty($_SESSION['show_er_risk_popup']) && !empty($er_risk_vendors);
unset($_SESSION['show_er_risk_popup']);

// One-shot success flash set by review_action.php (Submit to VM / Request Revision),
// shown via the on-brand ftPopup instead of a native browser alert().
$flash_success = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

// 3. Calculate Dashboard Metrics (Filtered by Search and Core Business)
$metric_conditions = [];

// Always exclude developer/QA test accounts from metric counters.
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
    SUM(CASE WHEN er.status IN ('under_review_vm_head', 'approved') THEN 1 ELSE 0 END) as exception_request,
    SUM(CASE WHEN er.status = 'under_review_vm_head' THEN 1 ELSE 0 END) as exception_request_waiting,
    SUM(CASE WHEN er.status = 'approved' THEN 1 ELSE 0 END) as exception_request_approved
FROM vendors v " . EXCEPTION_REQUEST_LATEST_JOIN . " $metric_where";

$metrics = $conn->query($metrics_sql)->fetch_assoc();

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
$exception_request_waiting_count = $metrics['exception_request_waiting'] ?? 0;
$exception_request_approved_count = $metrics['exception_request_approved'] ?? 0;

// "Kondisi Vendor" dropdown on the Exception Request submission modal. Add a new
// condition by adding one line here (kept in sync with the same array in
// exception_request_action.php, which uses it to label the saved notes).
$er_reason_options = [
    'po_urgent'        => 'PO Urgent',
    'vendor_exclusive' => 'Vendor Exclusive',
];

// Labels for the "Filter: ..." chip shown next to the table tabs whenever a
// metric-card filter is active (keys = ?filter= values, wording = card labels).
$metric_filter_labels = [
    'all'                          => 'Total Registered',
    'approved'                     => 'Approved Vendors',
    'pq_submitted'                 => 'New PQ',
    'pending_vm'                   => 'VM Review',
    'pending_head'                 => 'VM Head',
    'registration_submitted'       => 'Self Registered',
    'migrated'                     => 'Migrated',
    'invited'                      => 'Full Profile',
    'revision'                     => 'Revision Needed',
    'draft'                        => 'Draft',
    'exception_request'            => 'Exception Request',
    'exception_request_waiting'    => 'ER Waiting Approval',
    'exception_request_approved'   => 'ER Approved',
    'exception_request_risk'       => 'ER Risk (K2)',
    'exception_request_pq_pending' => 'ER PQ Pending',
];

// URL parameters to persist search, sort & checkboxes when clicking metric cards
$url_params = "&search=" . urlencode($search) . "&sort=" . urlencode($sort);
if (!empty($core_biz_arr)) {
    foreach($core_biz_arr as $cb) {
        $url_params .= "&core_biz[]=" . urlencode($cb);
    }
}
if ($resp_filter !== '')   $url_params .= "&resp=" . urlencode($resp_filter);
if ($status_filter !== '') $url_params .= "&status_f=" . urlencode($status_filter);
if ($biz_search !== '')     $url_params .= "&biz_search=" . urlencode($biz_search);
if ($contact_search !== '') $url_params .= "&contact_search=" . urlencode($contact_search);

// Base URL for page-number links (all active filters + current tab, page appended per-link).
$page_base_url = "?filter=" . urlencode($filter) . $url_params;
?>

<style>
    /* "Filter: ..." chip next to the table tabs — tells which metric-card
       filter is currently applied; the × removes it (back to Worklist). */
    /* Sized to match the Clear Filters button next to it (theme .btn:
       padding 10px 25px, font-size 0.8rem, border-radius 50px). */
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

    /* Worklist / Waiting for PQ tabs: underline style like the vendor profile
       tabs, but colored with the site palette (--primary-color navy). */
    .nav-tabs .nav-link { color: var(--text-color); border: none; border-bottom: 3px solid transparent; padding: 1rem 1.5rem; }
    .nav-tabs .nav-link:hover { color: var(--primary-color); border-color: #e9ecef; }
    .nav-tabs .nav-link.active { color: var(--primary-color); background-color: transparent; border-bottom: 3px solid var(--primary-color); }

    /* Compact, uniform controls for the per-column filter row in the table
       header — overrides the theme's default (larger) form-control sizing. */
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
        border-radius: 50rem; /* pill, matching the row action buttons */
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .metric-card { transition: transform 0.2s ease-in-out, box-shadow 0.2s ease-in-out; border-radius: 12px; overflow: hidden; position: relative; cursor: pointer; }
    .metric-card:hover { transform: translateY(-5px); box-shadow: 0 10px 20px rgba(0,0,0,0.1) !important; }
    .metric-icon { position: absolute; right: 15px; bottom: 10px; font-size: 4rem; opacity: 0.15; z-index: 0; }
    .metric-content { position: relative; z-index: 1; }
    .bg-gradient-primary { background: linear-gradient(135deg, #0d6efd 0%, #0043a8 100%); }
    .bg-gradient-success { background: linear-gradient(135deg, #198754 0%, #0f5132 100%); }
    .bg-gradient-warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); }
    .bg-gradient-info    { background: linear-gradient(135deg, #0dcaf0 0%, #087990 100%); }
    .bg-gradient-exception-request { background: linear-gradient(135deg, #0d9488 0%, #115e59 100%); }
    /* Exception Request theme (teal) — buttons & badges */
    .btn-exception-request { background:#0d9488; border:1px solid #0d9488; color:#fff; transition:all .2s ease; }
    .btn-exception-request:hover { background:#0b7d72; border-color:#0b7d72; color:#fff; box-shadow:0 4px 12px rgba(13,148,136,.35); }
    /* Resubmit (Ajukan Ulang) — softer, minimalist teal outline */
    .btn-exception-request-redo { background:#f0fdfa; border:1px solid #5eead4; color:#0f766e; transition:all .2s ease; }
    .btn-exception-request-redo:hover { background:#0d9488; border-color:#0d9488; color:#fff; box-shadow:0 4px 12px rgba(13,148,136,.30); }
    /* Action column: icon-reveal buttons. Collapsed = round icon button; on hover
       the label expands as an absolute overlay that joins the icon into a single
       rounded pill, floating on top of the neighbouring buttons. The reveal
       direction follows the button's position in the row so it never runs off
       the edge:
         - first / lone button -> expands right
         - middle button       -> expands both ways
         - last button         -> expands left                                    */
    .btn-reveal { position:relative; display:inline-flex; align-items:center; justify-content:center; border-radius:50rem; }
    .btn-reveal:hover, .btn-reveal:focus-visible { z-index:5; }
    .btn-reveal .btn-ico-label {
        position:absolute; top:0; bottom:0;
        display:flex; align-items:center;
        max-width:0; opacity:0; overflow:hidden; white-space:nowrap;
        background:inherit; color:inherit;
        transition:max-width .25s ease, opacity .2s ease, padding .25s ease;
    }
    .btn-reveal:hover .btn-ico-label, .btn-reveal:focus-visible .btn-ico-label { max-width:160px; opacity:1; }

    /* The label always opens to the RIGHT of the icon so the order stays natural
       ([icon] then [text]). To reveal toward the LEFT neighbours we simply slide
       the whole pill left on hover (the icon is free to move, not pinned). */
    .btn-reveal .btn-ico-label { left:100%; justify-content:flex-start; border-radius:0 50rem 50rem 0; }
    .btn-reveal:hover, .btn-reveal:focus-visible { border-top-right-radius:0; border-bottom-right-radius:0; }
    .btn-reveal:hover .btn-ico-label, .btn-reveal:focus-visible .btn-ico-label { padding-left:.5rem; padding-right:1rem; }

    /* Middle button: nudge left a little -> opens toward BOTH neighbours */
    .btn-reveal:not(:first-child):not(:last-child):hover,
    .btn-reveal:not(:first-child):not(:last-child):focus-visible { transform:translateX(-50%); }

    /* Last button: slide further left -> opens over the buttons on its LEFT */
    .btn-reveal:last-child:not(:first-child):hover,
    .btn-reveal:last-child:not(:first-child):focus-visible { transform:translateX(-4.5rem); }

    /* Exception Request "View Request" reveal button. Themed per-row via inline
       --erv-* vars. The icon (rest) and the revealed label share one continuous
       pill: the label carries the top/right/bottom border while the icon's right
       border drops on hover, so there is no seam between them. */
    .er-view-reveal { background:var(--erv-bg); color:var(--erv-fg); border:1px solid var(--erv-bd); }
    .er-view-reveal:hover, .er-view-reveal:focus-visible { background:var(--erv-bg); color:var(--erv-fg); border-color:var(--erv-bd); border-right-color:transparent; }
    .er-view-reveal .btn-ico-label { border:1px solid var(--erv-bd); border-left:0; }
    /* Warning: Exception Request vendor already registered in K2 (transacting) but still
       not PQ-approved, with 2+ POs already cut against them. */
    .badge-er-risk { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; animation: er-risk-pulse 1.8s ease-in-out infinite; }
    @keyframes er-risk-pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,.35); } 50% { box-shadow: 0 0 0 4px rgba(220,53,69,0); } }
    .badge-fasttrack { background:#ccfbf1; color:#115e59; border:1px solid #5eead4; }
    .badge-fasttrack-done { background:#0d9488; color:#fff; }
    .badge-fasttrack-rejected { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
    .ft-header { background:linear-gradient(135deg, #0d9488 0%, #115e59 100%); color:#fff; }
    @media (min-width: 576px) { .ft-modal-wide { max-width: 580px; } }
    .ft-header-icon { width:42px; height:42px; border-radius:50%; background:rgba(255,255,255,.16); display:flex; align-items:center; justify-content:center; font-size:1.05rem; flex-shrink:0; }
    .exception-request-form .modal-body { padding:1.5rem 1.5rem 1.25rem; background:#fafbfb; }
    .exception-request-form .modal-footer { background:#fff; padding:1rem 1.5rem; }
    /* Exception Request: numbered field steps */
    .ft-field { margin-bottom:1.15rem; }
    .ft-field:last-child { margin-bottom:0; }
    .ft-field-ack { margin-top:-.85rem; }
    .er-field-label { display:flex; align-items:center; gap:.55rem; font-weight:600; margin-bottom:.45rem; font-size:.875rem; color:#212529; }
    .ft-step-num { width:20px; height:20px; border-radius:50%; background:#0d9488; color:#fff; font-size:.7rem; font-weight:700; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
    .ft-field-hint { font-weight:400; color:#8a94a6; font-size:.78rem; }
    .exception-request-form .form-select, .exception-request-form .form-control { border-radius:.6rem; border-color:#dde3e8; }
    .exception-request-form .form-select:focus, .exception-request-form .form-control:focus { border-color:#0d9488; box-shadow:0 0 0 .2rem rgba(13,148,136,.15); }
    /* Info / rejected notice boxes */
    .ft-info-box { background:#f0fdfa; border:1px solid #99f6e4; border-radius:.7rem; padding:.85rem 1rem; display:flex; gap:.65rem; align-items:flex-start; }
    .ft-info-box i { color:#0d9488; margin-top:.15rem; }
    .ft-info-box .small { color:#155e56; }
    .ft-rejected-box { background:#fff5f5; border:1px solid #fecaca; border-radius:.7rem; padding:.85rem 1rem; }
    /* Acknowledgement card */
    .er-ack-box { border-radius:.7rem !important; background:#f8f9fa; border-color:#e2e6ea !important; display:flex; gap:.65rem; align-items:flex-start; padding:.65rem .75rem !important; }
    .er-ack-box .form-check-input { width:1.15em; height:1.15em; margin-top:.15rem; margin-left:0; flex-shrink:0; cursor:pointer; }
    .er-ack-box .form-check-label { line-height:1.5; cursor:pointer; }
    .er-ack-box .invalid-feedback { margin-top:.5rem; }
    .exception-request-form.was-validated .er-ack-box:has(.form-check-input:invalid) { border-color:#dc3545 !important; background:#fff5f5 !important; }
    .exception-request-form.was-validated .form-check-input:invalid { border-color:#dc3545; }
    /* Exception Request: segmented OTP input */
    .ft-otp-boxes { display:flex; gap:.4rem; }
    .ft-otp-digit {
        flex:0 0 auto; width:2.5rem; height:2.5rem; padding:0; text-align:center;
        font-size:1.05rem; font-weight:700; color:#0f766e; font-family:'Poppins', system-ui, sans-serif;
        border:1.5px solid #cbd5e1; border-radius:.5rem; background:#fff; caret-color:#0d9488;
        transition:border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .ft-otp-digit:focus { outline:none; border-color:#0d9488; box-shadow:0 0 0 .15rem rgba(13,148,136,.18); }
    .ft-otp-digit:disabled { background:#f1f3f5; border-color:#e5e9ee; color:#adb5bd; cursor:not-allowed; }
    .ft-otp-digit.is-filled { border-color:#0d9488; background:#f0fdfa; }
    .ft-otp-boxes.is-error .ft-otp-digit { border-color:#dc3545; }
    .ft-otp-actions { display:flex; align-items:center; justify-content:space-between; gap:.6rem; margin-top:.7rem; }
    .ft-otp-actions .ft-otp-status { margin:0; flex:1 1 auto; }
    .ft-otp-send { flex:0 0 auto; white-space:nowrap; background:#0d9488; border:1px solid #0d9488; color:#fff; font-weight:600; border-radius:.5rem; padding:.375rem .9rem; }
    .ft-otp-send:hover:not(:disabled) { background:#0f766e; border-color:#0f766e; color:#fff; }
    .ft-otp-send:disabled { opacity:.65; cursor:not-allowed; }
    .ft-otp-status { border-radius:.5rem; padding:.45rem .65rem; line-height:1.35; }
    .ft-otp-status.is-ok { background:#e8f5ee; color:#0f5132; }
    .ft-otp-status.is-err { background:#fdecec; color:#842029; }
    /* Kondisi Vendor + Catatan locked after the OTP is dispatched */
    .exception-request-form .er-locked { background:#f1f3f5; color:#6c757d; cursor:not-allowed; }
    /* Exception Request in-page result popup — minimalist */
    .ft-pop-card { border-radius:16px; overflow:hidden; }
    .ft-pop-head { padding:28px 24px 0; text-align:center; }
    .ft-pop-icon { width:56px; height:56px; margin:0 auto; border-radius:50%; display:flex; align-items:center; justify-content:center; }
    .ft-pop-head.ft-pop-success .ft-pop-icon { background:#e8f5ee; }
    .ft-pop-head.ft-pop-error   .ft-pop-icon { background:#fdecec; }
    .ft-pop-head.ft-pop-warning .ft-pop-icon { background:#fdecec; }
    .ft-pop-icon svg { width:26px; height:26px; stroke-width:2.5; fill:none; stroke-linecap:round; stroke-linejoin:round; }
    .ft-pop-head.ft-pop-success .ft-pop-icon svg { stroke:#198754; }
    .ft-pop-head.ft-pop-error   .ft-pop-icon svg { stroke:#dc3545; }
    .ft-pop-head.ft-pop-warning .ft-pop-icon i { font-size:22px; color:#dc3545; }
    .ft-pop-body { padding:16px 28px 26px; }
    .ft-pop-title { font-size:1.1rem; font-weight:600; margin:0 0 8px; color:#212529; }
    .ft-pop-msg { font-size:.9rem; line-height:1.55; color:#6c757d; margin:0 0 20px; }
    .ft-pop-redirect { margin-top:14px; font-size:.78rem; color:#adb5bd; display:flex; align-items:center; justify-content:center; gap:7px; }

    /* Exception Request quota popup — minimalist card with a firm accent */
    .erq-card { border-radius:18px; overflow:hidden; }
    .erq-accent { height:4px; background:linear-gradient(90deg,#f59f00,#dc3545); }
    .erq-head { padding:30px 28px 0; text-align:center; }
    .erq-icon { width:58px; height:58px; margin:0 auto; border-radius:50%; background:#fff4e0; display:flex; align-items:center; justify-content:center; }
    .erq-icon i { font-size:22px; color:#e8590c; }
    .erq-body { padding:18px 28px 26px; }
    .erq-title { font-weight:700; font-size:1.05rem; color:#212529; text-align:center; margin-bottom:6px; letter-spacing:.2px; }
    .erq-sub { font-size:.85rem; color:#6c757d; text-align:center; line-height:1.6; margin-bottom:16px; }
    .erq-sub strong { color:#212529; }
    .erq-list { background:#f8f9fa; border:1px solid #eef0f2; border-radius:12px; padding:4px 0; margin-bottom:18px; max-height:190px; overflow-y:auto; }
    .erq-item { display:flex; align-items:center; gap:10px; padding:10px 16px; font-size:.85rem; font-weight:600; color:#343a40; }
    .erq-item + .erq-item { border-top:1px dashed #e9ecef; }
    .erq-item > i { color:#e8590c; font-size:.8rem; flex-shrink:0; }
    .erq-name { flex-grow:1; min-width:0; overflow-wrap:anywhere; }
    .erq-pill { margin-left:auto; flex-shrink:0; font-size:.6rem; font-weight:700; letter-spacing:.4px; text-transform:uppercase; background:#fff4e0; color:#e8590c; border:1px solid #ffe0b3; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .bg-gradient-danger  { background: linear-gradient(135deg, #dc3545 0%, #842029 100%); }
    .bg-gradient-secondary { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); }
    .vendor-modal-card { border-radius: 12px; }
    .vendor-modal-header { background: linear-gradient(135deg, #1d4ed8 0%, #0f172a 100%); }
    .vendor-modal-header .modal-title { color:#ffffff; text-shadow: 0 1px 2px rgba(0,0,0,.25); }
    .vendor-modal-header .vendor-header-subtitle { color:#dbeafe; }
    .vendor-header-icon { width:46px; height:46px; border-radius:50%; background:rgba(255,255,255,.22); display:flex; align-items:center; justify-content:center; font-size:1.15rem; color:#ffffff; flex-shrink:0; }
    .border-dashed { border-style: dashed !important; }
    .hover-shadow:hover { box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important; }
    .subcard { background: rgba(255, 255, 255, 0.2); border-radius: 8px; padding: 8px 10px; backdrop-filter: blur(5px); transition: background 0.2s; text-decoration: none; color: inherit; display: block; cursor: pointer; }
    .subcard:hover { background: rgba(255, 255, 255, 0.35); }
    /* Sub-metric caption. Forced onto one line so the six metric cards keep
       equal heights — a wrapped label ("SELF REGISTERED", "APPROVE EXCEPTION
       REQUEST") used to push its card taller than its neighbours. Scales down
       with the viewport instead of wrapping. */
    .subcard-label {
        display: block;
        font-weight: 700;
        text-transform: uppercase;
        white-space: nowrap;
        letter-spacing: 0.2px;
        opacity: 0.75;
        font-size: clamp(0.48rem, 0.42vw, 0.6rem);
    }
    a.card-link { text-decoration: none; color: inherit; display: block; }

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

    /* Reveal the Vendor / Vendor Onboarding dropdowns on hover (desktop only —
       mobile/collapsed navbar keeps the default click-to-open behavior). */
    @media (min-width: 992px) {
        .onboarding-dropdown:hover .dropdown-menu,
        .vendor-dropdown:hover .dropdown-menu { display: block; margin-top: 0; }
    }
</style>

<nav class="navbar navbar-expand-lg navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Sourcing Dashboard</span>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#sourcingNav" aria-controls="sourcingNav" aria-expanded="false" aria-label="Toggle navigation">
            <i class="fas fa-bars"></i>
        </button>

        <div class="collapse navbar-collapse" id="sourcingNav">
            <div class="d-flex flex-column flex-lg-row align-items-lg-center ms-lg-auto mt-3 mt-lg-0 gap-2 gap-lg-0">
                <a href="<?= $path_to_root ?>workspace/pages/sso_vss.php" class="btn btn-sm btn-nav me-lg-2" title="Launch Workspace Pro">
                    <i class="fas fa-rocket me-1"></i> Workspace
                </a>

                <div class="dropdown me-lg-2 vendor-dropdown">
                    <a class="btn btn-sm btn-nav dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-list me-1 nav-ic-vendor"></i> Vendor
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="all_products.php"><i class="fas fa-box-open me-2 text-muted"></i>Master Catalog</a></li>
                        <li><a class="dropdown-item" href="all_vendors.php"><i class="fas fa-list me-2 text-muted"></i>Vendors</a></li>
                    </ul>
                </div>
                <a href="reports.php" class="btn btn-sm btn-nav me-lg-2">
                    <i class="fas fa-chart-pie me-1 text-warning"></i> Reports
                </a>
                <div class="dropdown me-lg-3 onboarding-dropdown">
                    <a class="btn btn-sm btn-nav dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="fas fa-user-plus me-1 text-success"></i> Vendor Onboarding
                    </a>
                    <ul class="dropdown-menu">
                        <li><a class="dropdown-item" href="invite_vendor.php"><i class="fas fa-envelope me-2 text-muted"></i>Invite Vendor</a></li>
                        <li><a class="dropdown-item" href="direct_registration.php"><i class="fas fa-user-edit me-2 text-muted"></i>Pendaftaran Langsung</a></li>
                    </ul>
                </div>

                <?php $navbar_profile_fallback = 'Sourcing Team'; include $path_to_root . 'includes/navbar_profile.php'; ?>
            </div>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    
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

        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 border-start border-4 border-warning bg-gradient-warning metric-card" style="cursor: default;">
                <div class="card-body pb-2 metric-content">
                    <h6 class="text-uppercase text-dark small fw-bold mb-3">Pending Process</h6>
                    <div class="row g-2">
                        <div class="col-4">
                            <a href="?filter=pq_submitted<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-dark text-decoration-none">
                                <small class="subcard-label">New PQ</small>
                                <div class="d-flex justify-content-between align-items-center"><span class="fs-4 fw-bold"><?= $pq_submitted_count ?></span><i class="fas fa-inbox opacity-50"></i></div>
                            </a>
                        </div>
                        <div class="col-4">
                            <a href="?filter=pending_vm<?= $url_params ?>" class="subcard bg-black bg-opacity-10 border border-dark text-dark text-decoration-none">
                                <small class="subcard-label">VM Review</small>
                                <div class="d-flex justify-content-between align-items-center"><span class="fs-4 fw-bold"><?= $pending_vm_count ?></span><i class="fas fa-user-clock opacity-50"></i></div>
                            </a>
                        </div>
                        <div class="col-4">
                            <a href="?filter=pending_head<?= $url_params ?>" class="subcard bg-black bg-opacity-10 border border-dark text-dark text-decoration-none">
                                <small class="subcard-label">VM Head</small>
                                <div class="d-flex justify-content-between align-items-center"><span class="fs-4 fw-bold"><?= $pending_head_count ?></span><i class="fas fa-user-shield opacity-50"></i></div>
                            </a>
                        </div>
                    </div>
                </div>
                <i class="fas fa-clock metric-icon text-dark"></i>
            </div>
        </div>
    </div>

    <div class="row mb-4 g-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0 border-start border-4 border-info metric-card bg-gradient-info" style="cursor: default;">
                <div class="card-body pb-2 metric-content">
                    <h6 class="text-uppercase text-white small fw-bold mb-3">Invited to Qualification</h6>
                    <div class="row g-2">
                        <div class="col-4">
                            <a href="?filter=registration_submitted<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="subcard-label">Self Registered</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $registration_submitted_count ?></span>
                                    <i class="fas fa-user-edit opacity-50"></i>
                                </div>
                            </a>
                        </div>
                        <div class="col-4">
                            <a href="?filter=migrated<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="subcard-label">Migrated</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $migrated_count ?></span>
                                    <i class="fas fa-file-import opacity-50"></i>
                                </div>
                            </a>
                        </div>
                        <div class="col-4">
                            <a href="?filter=invited<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="subcard-label">Full Profile</small>
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
                                <small class="subcard-label">Waiting Approval</small>
                                <div class="d-flex justify-content-between align-items-center">
                                    <span class="fs-4 fw-bold"><?= $exception_request_waiting_count ?></span>
                                    <i class="fas fa-hourglass-half opacity-50"></i>
                                </div>
                            </a>
                        </div>

                        <!-- Subcard 2: Approved Exception Request -->
                        <div class="col-6">
                            <a href="?filter=exception_request_approved<?= $url_params ?>" class="subcard bg-white bg-opacity-25 border border-white text-white text-decoration-none">
                                <small class="subcard-label">Approve Exception Request</small>
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
            <a href="?filter=worklist<?= $url_params ?>" class="nav-link fw-bold <?= $filter === 'worklist' ? 'active' : '' ?>">
                <i class="fas fa-tasks me-2"></i> Worklist
            </a>
        </li>
        <li class="nav-item">
            <a href="?filter=waiting_pq<?= $url_params ?>" class="nav-link fw-bold <?= $filter === 'waiting_pq' ? 'active' : '' ?>">
                <i class="fas fa-hourglass-half me-2"></i> Waiting for PQ
            </a>
        </li>
        <?php
            $chip_label        = $metric_filter_labels[$filter] ?? null;
            $has_other_filters = !empty($search) || !empty($core_biz_arr) || $resp_filter !== '' || $status_filter !== '' || $biz_search !== '' || $contact_search !== '' || (isset($_GET['filter']) && !in_array($_GET['filter'], ['default', 'worklist'], true));
        ?>
        <?php if($chip_label || $has_other_filters): ?>
            <li class="nav-item ms-auto d-flex align-items-center gap-2">
                <?php if($chip_label): ?>
                    <span class="filter-chip">
                        <i class="fas fa-filter"></i> Filter: <?= $chip_label ?>
                        <a href="?filter=worklist<?= $url_params ?>" title="Hapus filter kartu">&times;</a>
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
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter ?? '') ?>">
        <?php if($sort != 'default'): ?>
            <input type="hidden" name="sort" value="<?= htmlspecialchars($sort ?? '') ?>">
        <?php endif; ?>
    </form>

    <div class="card shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Company Name</th>
                            <th style="width: 150px;">Type &amp; Business</th>
                            <th>Contact Person</th>
                            <th>Responsibility</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        <tr class="bg-white table-filter-row">
                            <th class="fw-normal py-2">
                                <input type="text" name="search" form="tableFilterForm" class="form-control form-control-sm" placeholder="Cari nama / email..." value="<?= htmlspecialchars($search ?? '') ?>">
                            </th>
                            <th class="fw-normal py-2">
                                <input type="text" name="biz_search" form="tableFilterForm" class="form-control form-control-sm" placeholder="Cari type / bisnis..." value="<?= htmlspecialchars($biz_search ?? '') ?>">
                            </th>
                            <th class="fw-normal py-2">
                                <input type="text" name="contact_search" form="tableFilterForm" class="form-control form-control-sm" placeholder="Cari PIC / telepon..." value="<?= htmlspecialchars($contact_search ?? '') ?>">
                            </th>
                            <th class="fw-normal py-2">
                                <select name="resp" form="tableFilterForm" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Semua</option>
                                    <option value="unassigned" <?= $resp_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                                    <?php foreach($sourcing_users as $su): ?>
                                        <option value="<?= $su['id'] ?>" <?= $resp_filter == $su['id'] ? 'selected' : '' ?>><?= htmlspecialchars($su['full_name'] ?? '') ?><?= $su['id'] == $current_user_id ? ' (Saya)' : '' ?></option>
                                    <?php endforeach; ?>
                                </select>
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
                                <button type="submit" form="tableFilterForm" class="btn btn-sm btn-primary" title="Apply Filters"><i class="fas fa-filter"></i></button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($paged_rows) > 0): ?>
                            <?php foreach ($paged_rows as $row): ?>
                                <?php
                                    // Risk flag: Exception Request vendor already registered in K2 (i.e. already
                                    // transacting) but still not PQ-approved, with 2+ POs already cut.
                                    // (Rows failing this check, and rows failing the Responsibility filter,
                                    // were already dropped in the pagination pre-pass above.)
                                    $row_po_count = $vendor_po_count_map[$row['company_name']] ?? 0;

                                    // Ownership Logic. A vendor's responsible Sourcing is normally the
                                    // PQ reviewer (sourcing_reviewer_id). For a Exception Request vendor with no PQ
                                    $reviewer_id   = $row['sourcing_reviewer_id'];
                                    $reviewer_name = $row['reviewer_name'];
                                    if (is_null($reviewer_id) && !empty($row['exception_request_by'])) {
                                        $reviewer_id   = $row['exception_request_by'];
                                        $reviewer_name = $row['exception_request_by_name'];
                                    }
                                    $is_mine = ($reviewer_id == $current_user_id);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['company_name'] ?? '') ?></strong>
                                        <?= renderExceptionRequestK2RiskBadge($row, $row_po_count) ?>
                                        <br>
                                        <small class="text-muted"><?= htmlspecialchars($row['email'] ?? 'No Email') ?></small>
                                    </td>
                                    <td style="width: 150px; white-space: normal; word-break: break-word; vertical-align: middle;">
                                        <span class="badge bg-secondary mb-1"><?= $row['company_type'] ?></span><br>
                                        <?php if(!empty($row['core_business'])): ?>
                                            <?php $core_biz_spaced = implode(', ', array_filter(array_map('trim', explode(',', $row['core_business'])))); ?>
                                            <span class="small text-muted d-flex align-items-start" title="<?= htmlspecialchars($core_biz_spaced ?? '') ?>" style="font-size: 0.75rem; line-height: 1.2; height: 2.3em; cursor: help;">
                                                <i class="fas fa-tags me-1 mt-1 opacity-50 flex-shrink-0"></i>
                                                <span style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;"><?= htmlspecialchars($core_biz_spaced ?? '') ?></span>
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars($row['contact_person'] ?? '') ?><br>
                                        <small><?= htmlspecialchars($row['phone'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if(is_null($reviewer_id)): ?>
                                                <span class="badge bg-light text-muted border">Unassigned</span>
                                        <?php elseif($is_mine): ?>
                                                <span class="badge bg-primary"><i class="fas fa-user-check me-1"></i> ME</span>
                                        <?php else: ?>
                                                <span class="badge bg-light text-dark border">
                                                    <i class="fas fa-user me-1"></i> <?= htmlspecialchars($reviewer_name ?? '') ?>
                                                </span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php 
                                            // Status Badge Logic
                                            $status = $row['registration_status'];
                                            
                                            $badge_class = 'bg-secondary';
                                            $label = strtoupper(str_replace('_', ' ', $status));
                                            $custom_style = "";

                                            if ($status == 'registration_submitted') {
                                                $label = "REGISTRATION SUBMITTED";
                                                $badge_class = 'bg-info text-dark';
                                            }
                                            if ($status == 'registered') $badge_class = 'bg-info text-dark';
                                            if ($status == 'invited_pq') $badge_class = 'bg-warning text-dark';

                                            if ($status == 'pq_submitted') {
                                                $badge_class = 'bg-primary';
                                            }
                                            if ($status == 'returned_to_sourcing') {
                                                $label = "RETURNED BY VM";
                                                $badge_class = "bg-danger text-white";
                                            }
                                            if ($status == 'under_review_vm') {
                                                $label = "WITH VM TEAM";
                                                $badge_class = "bg-secondary text-white";
                                            }
                                            if ($status == 'under_review_vm_head') {
                                                $label = "WITH VM HEAD";
                                                $badge_class = "bg-secondary text-white";
                                            }
                                            if ($status == 'approved') $badge_class = 'bg-success';
                                            if ($status == 'revision_requested') $badge_class = 'bg-danger';
                                            
                                            echo "<span class='badge $badge_class' style='$custom_style'>" . $label . "</span>";
                                        ?>
                                    </td>
                                    <td style="min-width: 150px;">
                                        <div class="d-flex flex-row flex-nowrap gap-2 align-items-center">
                                        <?php if (in_array($filter, ['exception_request_waiting', 'exception_request_approved'], true)): ?>
                                            <button type="button" class="btn btn-sm btn-info text-white btn-reveal"
                                                    data-bs-toggle="modal" title="Details"
                                                    data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i><span class="btn-ico-label">Details</span>
                                            </button>
                                        <?php elseif ($status == 'registered'): ?>
                                            <a href="invite_action.php?vendor_id=<?= $row['id'] ?>"
                                               class="btn btn-sm btn-outline-primary btn-reveal" title="Invite to PQ"
                                               onclick="return confirm('Invite this vendor to Pre-Qualification?')">
                                               <i class="fas fa-paper-plane"></i><span class="btn-ico-label">Invite to PQ</span>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-info text-white btn-reveal"
                                                    data-bs-toggle="modal" title="Details"
                                                    data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i><span class="btn-ico-label">Details</span>
                                            </button>
                                        
                                        <?php elseif ($status == 'pq_submitted' || $status == 'returned_to_sourcing'): ?>
                                            <a href="review_pq.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-primary btn-reveal" title="<?= ($status == 'returned_to_sourcing') ? 'Fix / Review' : 'Review PQ' ?>">
                                                <i class="fas <?= ($status == 'returned_to_sourcing') ? 'fa-pen-to-square' : 'fa-clipboard-check' ?>"></i><span class="btn-ico-label"><?= ($status == 'returned_to_sourcing') ? 'Fix / Review' : 'Review PQ' ?></span>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-info text-white btn-reveal"
                                                    data-bs-toggle="modal" title="Details"
                                                    data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i><span class="btn-ico-label">Details</span>
                                            </button>
                                            
                                        <?php elseif ($status == 'under_review_vm' || $status == 'under_review_vm_head' || $status == 'approved' || $status == 'revision_requested'): ?>
                                            <a href="review_pq.php?id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-dark btn-reveal" title="Result PQ">
                                                <i class="fas fa-file-lines"></i><span class="btn-ico-label">Result PQ</span>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-info text-white btn-reveal"
                                                    data-bs-toggle="modal" title="Details"
                                                    data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i><span class="btn-ico-label">Details</span>
                                            </button>

                                        <?php elseif ($status == 'min_info_submitted'): ?>
                                            <span class="text-muted small">Incomplete Profile</span>
                                            
                                        <?php else: ?>
                                            <button type="button" class="btn btn-sm btn-info text-white btn-reveal"
                                                    data-bs-toggle="modal" title="Details"
                                                    data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                <i class="fas fa-eye"></i><span class="btn-ico-label">Details</span>
                                            </button>
                                        <?php endif; ?>

                                        <?php
                                            // --- EXCEPTION REQUEST (runs parallel to PQ) ---
                                            $er_status  = $row['exception_request_status'] ?? 'none';
                                            $profile_ok = isVendorProfileComplete($row)['complete'];
                                            $already_k2 = ($row['k2_status'] ?? '') === 'registered';
                                            // Eligible when the profile is complete, not already in K2, and the
                                            // 'requested' = an OTP has already been requested for this Exception
                                            // Request but it hasn't been submitted yet; keep the modal available
                                            // so the user can finish by entering the OTP.
                                            $er_can     = $profile_ok && in_array($er_status, ['none', 'rejected', 'requested'], true) && !$already_k2
                                                          && !in_array($status, ['registration_submitted', 'under_review_vm_head', 'approved', 'rejected'], true);
                                            // Hide the Exception Request entry point on the default worklist view.
                                            $er_show    = $er_can && !in_array($filter, ['default', 'worklist'], true);
                                        ?>
                                        <?php
                                            // Read-only "Detail Exception Request" modal state. Rendered whenever the
                                            // request is waiting on / approved by VM Head, in any filter view — it is
                                            // opened from the Details modal's status button (Pending Approval / Approved).
                                            $er_view_waiting  = ($er_status === 'under_review_vm_head');
                                            $er_view_approved = ($er_status === 'approved');
                                        ?>
                                        </div>

                                        <?php if ($er_view_waiting || $er_view_approved): ?>
                                        <div class="modal fade" id="erViewModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered ft-modal-wide">
                                                <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
                                                    <div class="modal-header ft-header border-0">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <span class="ft-header-icon text-white"><i class="fas fa-file-lines"></i></span>
                                                            <div>
                                                                <h5 class="modal-title fw-bold text-white mb-0">Detail Exception Request</h5>
                                                                <div class="text-white-50 small"><?= htmlspecialchars($row['company_name'] ?? '') ?></div>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <?php
                                                            $er_reviewer_name = trim((string)($row['exception_request_reviewer_name'] ?? '')) ?: 'VM Head';
                                                            $er_view_status_badge = $er_view_approved
                                                                ? '<span class="badge rounded-pill" style="background-color:#d1fae5;color:#0f766e;">Disetujui ' . htmlspecialchars($er_reviewer_name ?? '') . '</span>'
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
                                                                    <td><?= htmlspecialchars($row['exception_request_reason'] ?? '-') ?: '-' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold align-top">Catatan Sourcing</th>
                                                                    <td style="white-space:pre-line;"><?= htmlspecialchars($row['exception_request_notes'] ?? '') ?: '-' ?></td>
                                                                </tr>
                                                                <tr>
                                                                    <th class="text-muted fw-semibold">Diajukan Oleh</th>
                                                                    <td><?= htmlspecialchars($row['exception_request_by_name'] ?? '-') ?: '-' ?></td>
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
                                                                    <td style="white-space:pre-line;"><?= htmlspecialchars($row['exception_request_vm_notes'] ?? '') ?></td>
                                                                </tr>
                                                                <?php endif; ?>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary rounded-pill px-3"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#dashVendorModal<?= $row['id'] ?>">
                                                            <i class="fas fa-arrow-left me-1"></i> Back
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <?php if ($er_show): ?>
                                        <div class="modal fade" id="fastTrackModal<?= $row['id'] ?>" tabindex="-1" aria-hidden="true">
                                            <div class="modal-dialog modal-dialog-centered ft-modal-wide">
                                                <form method="POST" action="exception_request_action.php" class="modal-content border-0 shadow-lg rounded-4 overflow-hidden exception-request-form" novalidate>
                                                    <input type="hidden" name="vendor_id" value="<?= $row['id'] ?>">
                                                    <div class="modal-header ft-header border-0">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <span class="ft-header-icon text-white"><i class="fas fa-bolt"></i></span>
                                                            <div>
                                                                <h5 class="modal-title fw-bold text-white mb-0">Exception Request</h5>
                                                                <div class="text-white-50 small"><?= htmlspecialchars($row['company_name'] ?? '') ?></div>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <?php if ($er_status === 'rejected' && !empty($row['exception_request_vm_notes'])): ?>
                                                        <div class="ft-rejected-box mb-3">
                                                            <div class="fw-semibold text-danger small mb-1">
                                                                <i class="fas fa-times-circle me-1"></i> Ditolak VM Head sebelumnya
                                                            </div>
                                                            <div class="small text-muted" style="white-space: pre-line;"><?= htmlspecialchars($row['exception_request_vm_notes'] ?? '') ?></div>
                                                        </div>
                                                        <?php else: ?>
                                                        <div class="ft-info-box mb-3">
                                                            <i class="fas fa-info-circle"></i>
                                                            <div class="small">
                                                                Pengajuan vendor kepada Head VM untuk diproses registrasinya ke K2 setelah profil dilengkapi. Proses <Strong>PQ (Pre-Qualification)</Strong> tetap berjalan paralel dan sifatnya <Strong>wajib</Strong> untuk diselesaikan.
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                        <div class="ft-field">
                                                            <label class="er-field-label" for="ftReason<?= $row['id'] ?>">
                                                                <span class="ft-step-num">1</span>
                                                                Kondisi Vendor <span class="text-danger">*</span>
                                                                <span class="ft-field-hint">pilih salah satu</span>
                                                            </label>
                                                            <select class="form-select er-reason-select" name="exception_request_reason"
                                                                    id="ftReason<?= $row['id'] ?>" required>
                                                                <option value="" selected disabled>Pilih kondisi vendor...</option>
                                                                <?php foreach ($er_reason_options as $reasonValue => $reasonLabel): ?>
                                                                <option value="<?= htmlspecialchars($reasonValue ?? '') ?>"><?= htmlspecialchars($reasonLabel ?? '') ?></option>
                                                                <?php endforeach; ?>
                                                                <option value="other">Lainnya</option>
                                                            </select>
                                                            <div class="invalid-feedback">
                                                                <i class="fas fa-exclamation-circle me-1"></i> Pilih kondisi vendor terlebih dahulu.
                                                            </div>
                                                            <!-- Wrapped so the select's Bootstrap "~ .invalid-feedback" rule cannot
                                                                 also light up this "Lainnya" message (it only applies to its own input). -->
                                                            <div class="ft-reason-other-wrap">
                                                                <input type="text" name="exception_request_reason_other" maxlength="150" disabled
                                                                       class="form-control mt-2 d-none ft-reason-other-input"
                                                                       placeholder="Sebutkan kondisi lain...">
                                                                <div class="invalid-feedback ft-reason-other-feedback">
                                                                    <i class="fas fa-exclamation-circle me-1"></i> Sebutkan kondisi lain terlebih dahulu.
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="ft-field">
                                                            <label class="er-field-label" for="erNotes<?= $row['id'] ?>">
                                                                <span class="ft-step-num">2</span>
                                                                Catatan <span class="text-danger">*</span>
                                                                <span class="ft-field-hint">wajib diisi</span>
                                                            </label>
                                                            <textarea name="exception_request_notes" id="erNotes<?= $row['id'] ?>" class="form-control" rows="3" required
                                                                      placeholder="Alasan / justifikasi pengajuan Exception Request untuk vendor ini..."></textarea>
                                                            <div class="invalid-feedback">
                                                                <i class="fas fa-exclamation-circle me-1"></i> Mohon isi kolom catatan / justifikasi terlebih dahulu sebelum melanjutkan.
                                                            </div>
                                                        </div>
                                                        <div class="ft-field ft-field-ack">
                                                            <div class="form-check er-ack-box">
                                                                <input class="form-check-input mt-0" type="checkbox" value="1"
                                                                       name="exception_request_ack" id="erAck<?= $row['id'] ?>" required>
                                                                <div>
                                                                    <label class="form-check-label small mb-0" for="erAck<?= $row['id'] ?>">
                                                                        Saya telah memverifikasi bahwa vendor ini telah mengisi profil dengan lengkap dan benar sesuai persyaratan registrasi vendor yang berlaku.
                                                                    </label>
                                                                    <div class="invalid-feedback">
                                                                        <i class="fas fa-exclamation-circle me-1"></i> Centang pernyataan tanggung jawab ini untuk dapat mengajukan Exception Request.
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="ft-field">
                                                            <label class="er-field-label">
                                                                <span class="ft-step-num">3</span>
                                                                Kode OTP Supervisor <span class="text-danger">*</span>
                                                                <span class="ft-field-hint">langkah terakhir</span>
                                                            </label>
                                                            <div class="ft-otp-boxes" data-otp-group="<?= $row['id'] ?>">
                                                                <?php for ($d = 0; $d < 6; $d++): ?>
                                                                <input type="text" class="ft-otp-digit" inputmode="numeric" maxlength="1"
                                                                       autocomplete="off" disabled aria-label="Digit OTP ke-<?= $d + 1 ?>">
                                                                <?php endfor; ?>
                                                            </div>
                                                            <input type="hidden" name="exception_request_otp" class="ft-otp-value">
                                                            <div class="ft-otp-actions">
                                                                <div class="ft-otp-status small d-none"></div>
                                                                <button type="button" class="ft-otp-send" data-vendor="<?= $row['id'] ?>">
                                                                    <i class="fas fa-paper-plane me-1"></i> Kirim OTP
                                                                </button>
                                                            </div>
                                                            <div class="ft-otp-error small text-danger mt-2 d-none">
                                                                <i class="fas fa-exclamation-circle me-1"></i> Masukkan 6 digit kode OTP dari supervisor Anda.
                                                            </div>
                                                            <div class="ft-otp-hint small text-muted mt-2">
                                                                <i class="fas fa-info-circle me-1"></i> Tekan <strong>Kirim OTP</strong> untuk mengirim kode ke WhatsApp supervisor Anda. Menutup jendela ini akan membatalkan kode &mdash; Anda perlu meminta kode baru.
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary rounded-pill px-3" data-bs-dismiss="modal">Batal</button>
                                                        <button type="submit" class="btn btn-exception-request fw-bold rounded-pill px-3 ft-submit-btn">
                                                            <i class="fas fa-bolt me-1"></i> Ajukan Exception Request
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <div class="modal fade" id="dashVendorModal<?= $row['id'] ?>" tabindex="-1" aria-labelledby="vendorModalLabel<?= $row['id'] ?>" aria-hidden="true">
                                            <div class="modal-dialog modal-lg modal-dialog-centered">
                                                <div class="modal-content border-0 shadow-lg" style="border-radius: 12px; overflow: hidden;">
                                                    
                                                    <div class="modal-header vendor-modal-header text-white border-0 py-4 px-4">
                                                        <div class="d-flex align-items-center gap-3">
                                                            <div class="vendor-header-icon">
                                                                <i class="fas fa-building"></i>
                                                            </div>
                                                            <div>
                                                                <h5 class="modal-title fw-bold mb-1" id="vendorModalLabel<?= $row['id'] ?>">
                                                                    <?= htmlspecialchars($row['company_name'] ?? '') ?>
                                                                </h5>
                                                                <div class="vendor-header-subtitle small fw-medium">
                                                                    <?= htmlspecialchars($row['company_type'] ?? 'Company Details') ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                                                    </div>

                                                    <div class="modal-body p-4" style="background-color: #f4f6f9;">
                                                        <div class="row g-4 mb-4">
                                                            
                                                            <div class="col-md-6">
                                                                <div class="card h-100 border-0 shadow-sm vendor-modal-card">
                                                                    <div class="card-body p-4">
                                                                        <h6 class="text-primary fw-bold text-uppercase mb-4" style="font-size: 0.85rem; letter-spacing: 0.5px;">
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
                                                                        
                                                                        <div class="d-flex justify-content-between align-items-start mb-3 pb-2 border-bottom">
                                                                            <span class="text-muted small mt-1">Core Business :</span>
                                                                            <span class="fw-bold text-dark small text-end" style="word-break: break-word; max-width: 60%;">
                                                                                <?= !empty($row['core_business']) ? htmlspecialchars($row['core_business'] ?? '') : '-' ?>
                                                                            </span>
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

                                                            <div class="col-md-6">
                                                                <div class="card h-100 border-0 shadow-sm vendor-modal-card">
                                                                    <div class="card-body p-4 d-flex flex-column">
                                                                        <h6 class="text-success fw-bold text-uppercase mb-4" style="font-size: 0.85rem; letter-spacing: 0.5px;">
                                                                            <i class="fas fa-boxes me-2"></i> Risk Category & Docs
                                                                        </h6>

                                                                        <?php 
                                                                            // Compute risk_category from risk_percentage if NULL
                                                                            $risk_cat = $row['risk_category'] ?? null;
                                                                            $risk_pct = (float)($row['risk_percentage'] ?? 0);
                                                                            
                                                                            if (empty($risk_cat) && $risk_pct > 0) {
                                                                                if ($risk_pct > 60) $risk_cat = 'Critical';
                                                                                elseif ($risk_pct >= 45) $risk_cat = 'High';
                                                                                elseif ($risk_pct >= 30) $risk_cat = 'Moderate';
                                                                                elseif ($risk_pct >= 15) $risk_cat = 'Low';
                                                                                else $risk_cat = 'Very Low';
                                                                            }
                                                                            if (empty($risk_cat)) $risk_cat = 'No PQ yet';

                                                                            $risk_bg = 'bg-secondary';
                                                                            $text_color = 'text-white';
                                                                            $icon = 'fa-file-circle-question';
                                                                            
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
                                                                                $risk_bg = 'bg-orange';
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
                                                                                <h3 class="mb-0 fw-bold <?= $text_color ?>"><?= htmlspecialchars($risk_cat ?? '') ?></h3>
                                                                            </div>
                                                                            <i class="fas <?= $icon ?> fa-3x text-dark opacity-25 position-relative z-1"></i>
                                                                        </div>

                                                                        <div class="mt-auto">
                                                                            <h6 class="text-muted fw-bold text-uppercase mb-3" style="font-size: 0.75rem;">
                                                                                <i class="fas fa-folder-open me-1"></i> Key Documents
                                                                            </h6>
                                                                            
                                                                            <div class="d-flex flex-column gap-2">
                                                                                <?php if (!empty($row['company_profile_file'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['company_profile_file'] ?? '') ?>" 
                                                                                       target="_blank" 
                                                                                       class="text-decoration-none d-flex justify-content-between align-items-center p-2 border rounded-3 bg-white hover-shadow transition-all shadow-sm">
                                                                                        <span class="fw-bold text-dark small"><i class="fas fa-file-pdf text-info me-2"></i> Company Profile</span>
                                                                                        <i class="fas fa-external-link-alt text-muted" style="font-size: 0.7rem;"></i>
                                                                                    </a>
                                                                                <?php else: ?>
                                                                                    <div class="p-2 bg-light rounded-3 border border-dashed d-flex justify-content-between align-items-center shadow-sm">
                                                                                        <span class="text-muted small fst-italic"><i class="fas fa-file-pdf me-2 opacity-50"></i> Company Profile</span>
                                                                                        <span class="badge bg-secondary" style="font-size: 0.6rem;">Missing</span>
                                                                                    </div>
                                                                                <?php endif; ?>

                                                                                <?php if (!empty($row['bank_cover_path'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['bank_cover_path'] ?? '') ?>" 
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

                                                                                <?php if (!empty($row['business_ethics_commitment_file'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['business_ethics_commitment_file'] ?? '') ?>" 
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

                                                                                <?php if (!empty($row['tax_statement_letter_file'])): ?>
                                                                                    <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($row['tax_statement_letter_file'] ?? '') ?>"
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
                                                    
                                                    <div class="modal-footer bg-white border-top-0 py-3 px-4">
                                                        <a href="view_profile.php?id=<?= $row['id'] ?>" class="btn btn-primary fw-bold rounded-pill px-4">
                                                            <i class="fas fa-user-circle me-1"></i> View Profile
                                                        </a>
                                                        <?php if ($er_show): ?>
                                                        <button type="button" class="btn btn-exception-request fw-bold rounded-pill px-4 me-auto"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#fastTrackModal<?= $row['id'] ?>">
                                                            <i class="fas fa-bolt me-1"></i> Exception Request
                                                        </button>
                                                        <?php elseif ($er_status === 'under_review_vm_head'): ?>
                                                        <button type="button" class="btn btn-exception-request fw-bold rounded-pill px-4 me-auto"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#erViewModal<?= $row['id'] ?>"
                                                                title="Lihat detail Exception Request — menunggu persetujuan VM Head">
                                                            <i class="fas fa-hourglass-half me-1"></i> Pending Approval
                                                        </button>
                                                        <?php elseif ($er_status === 'approved'): ?>
                                                        <button type="button" class="btn btn-success fw-bold rounded-pill px-4 me-auto"
                                                                data-bs-dismiss="modal"
                                                                data-bs-toggle="modal" data-bs-target="#erViewModal<?= $row['id'] ?>"
                                                                title="Lihat detail Exception Request — disetujui VM Head">
                                                            <i class="fas fa-check-circle me-1"></i> Approved Exception
                                                        </button>
                                                        <?php else: ?>
                                                        <span class="me-auto"></span>
                                                        <?php endif; ?>
                                                        <button type="button" class="btn btn-outline-dark bg-white fw-bold rounded-pill px-4" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center py-5">
                                <i class="fas fa-search fa-3x text-muted opacity-25 mb-3"></i>
                                <h5 class="text-muted">No vendors found.</h5>
                                <p class="text-muted small">Try adjusting your search or filters.</p>
                            </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_rows > 0): ?>
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
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

<script>
document.addEventListener('change', function(e) {
    if (!e.target.classList.contains('er-reason-select')) return;
    var wrap = e.target.closest('.ft-field');
    var input = wrap ? wrap.querySelector('.ft-reason-other-input') : null;
    if (!input) return;
    var isOther = e.target.value === 'other';
    input.disabled = !isOther;
    input.required = isOther;
    input.classList.toggle('d-none', !isOther);
    if (!isOther) input.value = '';
});

// Exception Request modal: segmented OTP handling. The OTP is generated +
// WhatsApped to the sourcing user's supervisor by exception_request_send_otp.php.
// The "OTP already sent" state is tracked per-tab in sessionStorage. Closing the
// modal invalidates the code (both here and server-side via
// exception_request_cancel_otp.php), so the user must re-request it on reopen.
function erOtpKey(vendorId) { return 'er_otp_sent_' + vendorId; }
function erBoxes(form) { return Array.prototype.slice.call(form.querySelectorAll('.ft-otp-digit')); }
function erVendorId(form) { var i = form.querySelector('input[name="vendor_id"]'); return i ? i.value : ''; }
// Close the Exception Request modal that hosts this form (if it is open).
function erCloseModal(form) {
    var modalEl = form.closest('.modal');
    if (!modalEl) return;
    var inst = bootstrap.Modal.getInstance(modalEl);
    if (inst) inst.hide();
}

// Concatenate the 6 boxes into the hidden field the form actually submits.
function erSyncOtp(form) {
    var val = erBoxes(form).map(function(b){ return b.value; }).join('');
    var hidden = form.querySelector('.ft-otp-value');
    if (hidden) hidden.value = val;
    return val;
}
function erSetBoxesEnabled(form, enabled) {
    erBoxes(form).forEach(function(b){ b.disabled = !enabled; });
}
function erClearBoxes(form) {
    erBoxes(form).forEach(function(b){ b.value = ''; b.classList.remove('is-filled'); });
    erSyncOtp(form);
    var group = form.querySelector('.ft-otp-boxes');
    if (group) group.classList.remove('is-error');
    var err = form.querySelector('.ft-otp-error');
    if (err) err.classList.add('d-none');
}
// Turn "Kirim OTP" into "Kirim Ulang" once a code has been dispatched.
function erResendLabel() { return '<i class="fas fa-rotate-right me-1"></i> Kirim Ulang'; }
function erMarkResend(form) {
    var btn = form.querySelector('.ft-otp-send');
    if (btn) btn.innerHTML = erResendLabel();
}
// Enable the OTP boxes + submit button once an OTP has been dispatched.
function erEnableOtp(form) {
    erSetBoxesEnabled(form, true);
    var submitBtn = form.querySelector('.ft-submit-btn');
    if (submitBtn) submitBtn.disabled = false;
}
// Small status line under the OTP row.
function erOtpStatus(form, message, ok) {
    var box = form.querySelector('.ft-otp-status');
    if (!box) return;
    box.textContent = message;
    box.classList.remove('d-none', 'is-ok', 'is-err');
    box.classList.add(ok ? 'is-ok' : 'is-err');
}

// Full reset of the OTP widget back to its untouched state: boxes cleared +
// disabled, submit locked, "Kirim Ulang" reverted to "Kirim OTP", status hidden.
function erSendLabel() { return '<i class="fas fa-paper-plane me-1"></i> Kirim OTP'; }
function erResetOtp(form) {
    erClearBoxes(form);
    erSetBoxesEnabled(form, false);
    erUnlockContext(form);
    var submitBtn = form.querySelector('.ft-submit-btn');
    if (submitBtn) submitBtn.disabled = true;
    var sendBtn = form.querySelector('.ft-otp-send');
    if (sendBtn) { sendBtn.disabled = false; sendBtn.innerHTML = erSendLabel(); }
    var status = form.querySelector('.ft-otp-status');
    if (status) { status.classList.add('d-none'); status.classList.remove('is-ok', 'is-err'); }
}

// Freeze Kondisi Vendor + Catatan once an OTP is dispatched, so what the
// supervisor approved by WhatsApp cannot diverge from what is later submitted.
// A disabled <select>/<textarea> is dropped from the form POST, so instead we
// pin the current choice (disable the other options) and mark inputs read-only —
// both remain submittable. Undone by erUnlockContext() on reset/close.
function erLockContext(form) {
    var reasonSel = form.querySelector('.er-reason-select');
    var otherEl   = form.querySelector('.ft-reason-other-input');
    var notesEl   = form.querySelector('textarea[name="exception_request_notes"]');
    if (reasonSel) {
        var current = reasonSel.value;
        Array.prototype.forEach.call(reasonSel.options, function(o) { o.disabled = (o.value !== current); });
        reasonSel.style.pointerEvents = 'none';
        reasonSel.classList.add('er-locked');
    }
    if (otherEl) { otherEl.readOnly = true; otherEl.classList.add('er-locked'); }
    if (notesEl) { notesEl.readOnly = true; notesEl.classList.add('er-locked'); }
}
function erUnlockContext(form) {
    var reasonSel = form.querySelector('.er-reason-select');
    var otherEl   = form.querySelector('.ft-reason-other-input');
    var notesEl   = form.querySelector('textarea[name="exception_request_notes"]');
    if (reasonSel) {
        // Restore the original option state: only the placeholder stays disabled.
        Array.prototype.forEach.call(reasonSel.options, function(o) { o.disabled = (o.value === ''); });
        reasonSel.style.pointerEvents = '';
        reasonSel.classList.remove('er-locked');
    }
    if (otherEl) { otherEl.readOnly = false; otherEl.classList.remove('er-locked'); }
    if (notesEl) { notesEl.readOnly = false; notesEl.classList.remove('er-locked'); }
}

// On (re)load: a page reload cannot preserve the locked Kondisi Vendor + Catatan
// context (the form is reset), so a previously-sent OTP must NOT silently carry
// over — otherwise it could be reused against freshly-typed, different context.
// Clear the stale per-tab flag, reset the widget, and invalidate the server-side
// code so the user starts a clean request.
document.querySelectorAll('.exception-request-form').forEach(function(form) {
    var vendorId = erVendorId(form);
    if (vendorId && sessionStorage.getItem(erOtpKey(vendorId))) {
        sessionStorage.removeItem(erOtpKey(vendorId));
        erResetOtp(form);
        var data = new FormData();
        data.append('vendor_id', vendorId);
        data.append('ajax', '1');
        fetch('exception_request_cancel_otp.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: data
        }).catch(function() { /* best-effort; the widget is already reset */ });
    }
});

// Per-box typing: numeric only, auto-advance to the next box.
document.addEventListener('input', function(e) {
    var box = e.target.closest('.ft-otp-digit');
    if (!box) return;
    var form = box.closest('form');
    box.value = box.value.replace(/\D/g, '').slice(0, 1);
    box.classList.toggle('is-filled', box.value !== '');
    var boxes = erBoxes(form);
    var idx = boxes.indexOf(box);
    if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
    erSyncOtp(form);
    var group = form.querySelector('.ft-otp-boxes');
    if (group) group.classList.remove('is-error');
    var err = form.querySelector('.ft-otp-error');
    if (err) err.classList.add('d-none');
});
// Backspace on an empty box jumps back; arrows move between boxes.
document.addEventListener('keydown', function(e) {
    var box = e.target.closest('.ft-otp-digit');
    if (!box) return;
    var form = box.closest('form');
    var boxes = erBoxes(form);
    var idx = boxes.indexOf(box);
    if (e.key === 'Backspace' && box.value === '' && idx > 0) {
        e.preventDefault();
        boxes[idx - 1].focus();
        boxes[idx - 1].value = '';
        boxes[idx - 1].classList.remove('is-filled');
        erSyncOtp(form);
    } else if (e.key === 'ArrowLeft' && idx > 0) {
        e.preventDefault(); boxes[idx - 1].focus();
    } else if (e.key === 'ArrowRight' && idx < boxes.length - 1) {
        e.preventDefault(); boxes[idx + 1].focus();
    }
});
// Paste a full code into any box → spread it across all boxes.
document.addEventListener('paste', function(e) {
    var box = e.target.closest('.ft-otp-digit');
    if (!box) return;
    e.preventDefault();
    var form = box.closest('form');
    var clip = (e.clipboardData || window.clipboardData).getData('text') || '';
    var digits = clip.replace(/\D/g, '').slice(0, 6).split('');
    var boxes = erBoxes(form);
    boxes.forEach(function(b, i) {
        b.value = digits[i] || '';
        b.classList.toggle('is-filled', !!digits[i]);
    });
    erSyncOtp(form);
    var next = Math.min(digits.length, boxes.length - 1);
    if (boxes[next]) boxes[next].focus();
});

// "Kirim OTP" click → request an OTP for the supervisor.
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.ft-otp-send');
    if (!btn) return;
    var form = btn.closest('form');
    var vendorId = btn.getAttribute('data-vendor');
    if (!form || !vendorId) return;

    // Kondisi Vendor (step 1) + Catatan (step 2) + pernyataan tanggung jawab must
    // all be completed first — reason/notes go into the supervisor's OTP message,
    // and the acknowledgement must be affirmed before any code is sent.
    var reasonSel = form.querySelector('.er-reason-select');
    var otherEl   = form.querySelector('.ft-reason-other-input');
    var notesEl   = form.querySelector('textarea[name="exception_request_notes"]');
    var ackEl     = form.querySelector('input[name="exception_request_ack"]');
    var isOther   = reasonSel && reasonSel.value === 'other';
    var reasonMissing = !reasonSel || reasonSel.value === '' || (isOther && (!otherEl || otherEl.value.trim() === ''));
    var notesMissing  = !notesEl || notesEl.value.trim() === '';
    var ackMissing    = !ackEl || !ackEl.checked;
    if (reasonMissing || notesMissing || ackMissing) {
        var focusEl = reasonMissing ? (isOther ? otherEl : reasonSel) : (notesMissing ? notesEl : ackEl);
        if (focusEl) focusEl.focus();
        erOtpStatus(form, 'Lengkapi Kondisi Vendor, Catatan, dan centang pernyataan tanggung jawab terlebih dahulu sebelum mengirim OTP.', false);
        return;
    }
    // Human-readable Kondisi Vendor label (free text for "Lainnya").
    var reasonLabel = isOther ? otherEl.value.trim() : reasonSel.options[reasonSel.selectedIndex].text;

    var originalHtml = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Mengirim...';

    var data = new FormData();
    data.append('vendor_id', vendorId);
    data.append('exception_request_reason_label', reasonLabel);
    data.append('exception_request_notes', notesEl.value);
    data.append('ajax', '1');

    fetch('exception_request_send_otp.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: data
    })
    .then(function(res) { return res.json().then(function(j){ return { ok: res.ok, body: j }; }); })
    .then(function(r) {
        var ok = !!(r.body && (r.body.ok || r.body.type === 'success')) && r.ok !== false;
        // Kuota ER penuh: tampilkan popup berisi daftar vendor yang PQ-nya harus
        // diselesaikan, lalu tutup modal Exception Request secara otomatis.
        if (!ok && r.body && r.body.quota_full) {
            erCloseModal(form);
            showErQuotaPopup(r.body);
            return;
        }
        if (ok) {
            sessionStorage.setItem(erOtpKey(vendorId), '1');
            erEnableOtp(form);
            erLockContext(form);
            erClearBoxes(form);
            var first = erBoxes(form)[0];
            if (first) first.focus();
            originalHtml = erResendLabel(); // finally() restores the button as "Kirim Ulang"
        }
        erOtpStatus(form, (r.body && r.body.message) || (ok ? 'OTP terkirim.' : 'Gagal mengirim OTP.'), ok);
    })
    .catch(function() {
        erOtpStatus(form, 'Terjadi kesalahan jaringan saat mengirim OTP. Silakan coba lagi.', false);
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = originalHtml;
    });
});

// Exception Request modal: inline validation + AJAX submit that shows an in-page popup
document.querySelectorAll('.exception-request-form').forEach(function(form) {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        e.stopPropagation();

        if (!form.checkValidity()) {
            form.classList.add('was-validated');
            var firstInvalid = form.querySelector(':invalid');
            if (firstInvalid) firstInvalid.focus();
            return;
        }
        form.classList.add('was-validated');

        // OTP must be fully entered (relevant only once the boxes are enabled).
        if (form.querySelector('.ft-otp-digit:not([disabled])')) {
            if (erSyncOtp(form).length !== 6) {
                var group = form.querySelector('.ft-otp-boxes');
                var errEl = form.querySelector('.ft-otp-error');
                if (group) group.classList.add('is-error');
                if (errEl) errEl.classList.remove('d-none');
                var firstEmpty = erBoxes(form).filter(function(b){ return !b.value; })[0];
                if (firstEmpty) firstEmpty.focus();
                return;
            }
        }

        var submitBtn = form.querySelector('button[type="submit"]');
        var originalHtml = submitBtn ? submitBtn.innerHTML : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Memproses...';
        }

        var data = new FormData(form);
        data.append('ajax', '1');

        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: data
        })
        .then(function(res) { return res.json().then(function(j){ return { ok: res.ok, body: j }; }); })
        .then(function(r) {
            var isSuccess = !!(r.body && (r.body.ok || r.body.type === 'success')) && r.ok !== false;
            // Only close the Exception Request form on success. On failure, leave it open
            if (isSuccess) {
                // Cycle complete — drop the per-tab OTP flag.
                var vInput = form.querySelector('input[name="vendor_id"]');
                if (vInput) sessionStorage.removeItem(erOtpKey(vInput.value));
                erCloseModal(form);
            } else if (r.body && r.body.quota_full) {
                // Kuota ER penuh: form ini tidak bisa dilanjutkan — tutup modalnya
                // dan tampilkan popup kuota (bukan popup hasil generik).
                erCloseModal(form);
                showErQuotaPopup(r.body);
                return;
            } else {
                // Wrong / stale OTP: clear the boxes so the user can re-enter the
                // code (the rest of the form stays filled in).
                if (form.querySelector('.ft-otp-digit:not([disabled])')) {
                    erClearBoxes(form);
                    var firstBox = erBoxes(form)[0];
                    if (firstBox) firstBox.focus();
                }
            }
            showFtPopup(r.body, r.ok);
        })
        .catch(function() {
            showFtPopup({ type: 'error', title: 'Gagal', message: 'Terjadi kesalahan jaringan. Silakan coba lagi.' }, false);
        })
        .finally(function() {
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalHtml;
            }
        });
    });
    // Reset validation styling every time the modal is opened fresh
    var modalEl = form.closest('.modal');
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function() {
            form.classList.remove('was-validated');
            // Closing the modal (X / Batal / backdrop / Esc) invalidates the OTP:
            // reset the widget and drop the per-tab flag so reopening starts fresh,
            // then tell the server to null the code so it can no longer verify. The
            // user must press "Kirim OTP" again for a new one.
            var vInput = form.querySelector('input[name="vendor_id"]');
            var vendorId = vInput ? vInput.value : '';
            var hadOtp = vendorId && sessionStorage.getItem(erOtpKey(vendorId));
            erResetOtp(form);
            if (hadOtp) {
                sessionStorage.removeItem(erOtpKey(vendorId));
                var data = new FormData();
                data.append('vendor_id', vendorId);
                data.append('ajax', '1');
                fetch('exception_request_cancel_otp.php', {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    body: data
                }).catch(function() { /* best-effort; UI is already reset */ });
            }
        });
    }
});

// Quota popup: renders the vendors whose PQ must be finished (one row each,
// escaped via textContent) and shows the dedicated #erQuotaPopup card.
function showErQuotaPopup(data) {
    var list  = document.getElementById('erQuotaVendors');
    var names = (data && data.vendors) || [];
    list.innerHTML = '';
    if (names.length) {
        names.forEach(function(n) {
            var row = document.createElement('div');
            row.className = 'erq-item';
            row.innerHTML = '<i class="fas fa-building"></i><span class="erq-name"></span><span class="erq-pill">PQ belum selesai</span>';
            row.querySelector('.erq-name').textContent = n;
            list.appendChild(row);
        });
    } else {
        // Fallback (no structured list in the response): show the plain message.
        var row = document.createElement('div');
        row.className = 'erq-item';
        row.textContent = (data && data.message) || 'Selesaikan PQ vendor Exception Request Anda terlebih dahulu.';
        list.appendChild(row);
    }
    bootstrap.Modal.getOrCreateInstance(document.getElementById('erQuotaPopup')).show();
}

// Renders the on-brand success/error popup and reloads the dashboard on success
// so the vendor's status badge reflects the new Exception Request state.
function showFtPopup(data, ok) {
    var isSuccess = !!(data && (data.ok || data.type === 'success')) && ok !== false;
    var title   = (data && data.title)   || (isSuccess ? 'Berhasil' : 'Perhatian');
    var message = (data && data.message) || (isSuccess ? 'Berhasil.' : 'Terjadi kesalahan.');

    var pop      = document.getElementById('ftPopup');
    var head     = document.getElementById('ftPopupHead');
    var iconWrap = document.getElementById('ftPopupIcon');
    var titleEl  = document.getElementById('ftPopupTitle');
    var msgEl    = document.getElementById('ftPopupMsg');
    var redirect = document.getElementById('ftPopupRedirect');

    head.classList.toggle('ft-pop-success', isSuccess);
    head.classList.toggle('ft-pop-error', !isSuccess);
    iconWrap.innerHTML = isSuccess
        ? '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>'
        : '<svg viewBox="0 0 24 24"><line x1="12" y1="8" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>';
    titleEl.textContent = title;
    msgEl.textContent   = message;
    redirect.classList.toggle('d-none', !isSuccess);

    var popModal = bootstrap.Modal.getOrCreateInstance(pop);
    popModal.show();

    if (isSuccess) {
        // Give the user a moment to read, then refresh so the badge updates.
        setTimeout(function() { window.location.reload(); }, 1600);
    }
}

// Initialize Bootstrap tooltips (e.g. the Exception Request risk warning badge)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
        new bootstrap.Tooltip(el);
    });

    <?php if ($show_er_risk_popup): ?>
    var ftRiskModal = new bootstrap.Modal(document.getElementById('ftRiskPopup'));
    ftRiskModal.show();
    <?php endif; ?>

    <?php if ($flash_success !== ''): ?>
    // Success flash from review_action.php — reuse the on-brand ftPopup (no reload).
    (function () {
        var head = document.getElementById('ftPopupHead');
        head.classList.add('ft-pop-success');
        head.classList.remove('ft-pop-error');
        document.getElementById('ftPopupIcon').innerHTML =
            '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>';
        document.getElementById('ftPopupTitle').textContent = 'Berhasil';
        document.getElementById('ftPopupMsg').textContent = <?= json_encode($flash_success) ?>;
        document.getElementById('ftPopupRedirect').classList.add('d-none');
        bootstrap.Modal.getOrCreateInstance(document.getElementById('ftPopup')).show();
    })();
    <?php endif; ?>
});
</script>

<?php if ($show_er_risk_popup): ?>
<!-- Login warning: Exception Request vendors registered in K2 (already transacting) but still not PQ-approved -->
<div class="modal fade" id="ftRiskPopup" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg ft-pop-card">
            <div class="ft-pop-head ft-pop-warning">
                <div class="ft-pop-icon">
                    <i class="fas fa-triangle-exclamation"></i>
                </div>
            </div>
            <div class="ft-pop-body text-center">
                <div class="ft-pop-title">Vendor Exception Request Berisiko</div>
                <p class="ft-pop-msg">
                    <strong><?= count($er_risk_vendors) ?> vendor</strong> Exception Request <strong>yang Anda ajukan</strong> sudah
                    teregistrasi K2 (sudah dapat bertransaksi dan memiliki 2 PO atau lebih) namun
                    <strong>belum mendapat persetujuan PQ</strong>. Mohon segera ditindaklanjuti.
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
                    <a href="?filter=exception_request_risk" class="btn btn-danger fw-bold rounded-pill px-4">
                        <i class="fas fa-eye me-1"></i> Lihat Vendor
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Exception Request result popup (shown in-page instead of redirecting) -->
<div class="modal fade" id="ftPopup" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 400px;">
        <div class="modal-content border-0 shadow-lg ft-pop-card">
            <div class="ft-pop-head" id="ftPopupHead">
                <div class="ft-pop-icon" id="ftPopupIcon"></div>
            </div>
            <div class="ft-pop-body text-center">
                <div class="ft-pop-title" id="ftPopupTitle">Berhasil</div>
                <p class="ft-pop-msg" id="ftPopupMsg"></p>
                <button type="button" class="btn btn-exception-request fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Lanjutkan</button>
                <div class="ft-pop-redirect d-none" id="ftPopupRedirect">
                    <span class="spinner-border spinner-border-sm"></span> Memuat ulang&hellip;
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Exception Request quota popup: the sourcing user must finish these vendors' PQ
     before submitting a new Exception Request (list filled by showErQuotaPopup) -->
<div class="modal fade" id="erQuotaPopup" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg erq-card">
            <div class="erq-accent"></div>
            <div class="erq-head">
                <div class="erq-icon"><i class="fas fa-hourglass-half"></i></div>
            </div>
            <div class="erq-body">
                <div class="erq-title">Exception Request Ditahan</div>
                <p class="erq-sub">
                    Selesaikan proses <strong>PQ</strong> vendor berikut terlebih dahulu
                    sebelum mengajukan <strong>Exception Request</strong> baru.
                </p>
                <div class="erq-list" id="erQuotaVendors"></div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-secondary fw-bold rounded-pill px-4" data-bs-dismiss="modal">Tutup</button>
                    <a href="?filter=exception_request_pq_pending" class="btn btn-dark w-100 fw-bold rounded-pill">
                        <i class="fas fa-eye me-1"></i> Lihat Vendor
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>