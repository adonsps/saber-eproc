<?php
// modules/admin/report_k2.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/sla_helper.php';

// ── Auth ─────────────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ' . $path_to_root . 'login.php');
    exit;
}

$page_title        = "Agora Registration SLA Report";
$current_user_id   = $_SESSION['user_id'];

// ── Date Filter ──────────────────────────────────────────────────────────────
$date_from = $_GET['date_from'] ?? date('Y-m-01');          // default: first of month
$date_to   = $_GET['date_to']   ?? date('Y-m-d');           // default: today
$export    = isset($_GET['export']) && $_GET['export'] === 'xlsx';

// Sanitize
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from) ? $date_from : date('Y-m-01');
$date_to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)   ? $date_to   : date('Y-m-d');

// ── Query ────────────────────────────────────────────────────────────────────
$sql = "
    SELECT
        v.id,
        v.company_name,
        v.company_type,
        v.vendor_code,
        v.k2_request_number,
        v.k2_status,
        v.k2_anomaly,
        v.sla_deadline,
        v.sla_status,
        v.k2_started_at,
        v.k2_registered_at,
        v.k2_remarks,
        v.updated_at,
        u.full_name AS registered_by_name
    FROM vendors v
    LEFT JOIN users u ON v.k2_registered_by = u.id
    WHERE (v.registration_status = 'approved'
      AND v.k2_started_at IS NOT NULL
      AND DATE(v.k2_started_at) BETWEEN ? AND ?
      ) or (v.registration_status = 'approved' AND v.k2_anomaly = 1)
    ORDER BY v.k2_started_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $date_from, $date_to);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Metrics ──────────────────────────────────────────────────────────────────
$total      = count($rows);
$on_time    = 0; $late = 0; $pending_count = 0; $anomaly_count = 0;
foreach ($rows as $r) {
    if ($r['k2_anomaly'])                  $anomaly_count++;
    if ($r['sla_status'] === 'on_time')    $on_time++;
    elseif ($r['sla_status'] === 'late')   $late++;
    else                                   $pending_count++;
}
$completed   = $on_time + $late;
$on_time_rate = $completed > 0 ? round(($on_time / $completed) * 100, 1) : 0;

// ── Excel Export ─────────────────────────────────────────────────────────────
if ($export) {
    $filename = 'Agora_SLA_Report_' . $date_from . '_to_' . $date_to . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // BOM for Excel UTF-8
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'No.', 'Company Name', 'Company Type', 'Vendor Code',
        'No.Request', 'Agora Status', 'Anomaly',
        'SLA Deadline', 'SLA Status', 'Agora Started At',
        'Agora Registered At', 'Registered By', 'Remarks'
    ]);

    foreach ($rows as $i => $r) {
        fputcsv($out, [
            $i + 1,
            $r['company_name'],
            $r['company_type'],
            $r['vendor_code']        ?? '—',
            $r['k2_request_number']  ?? '—',
            strtoupper($r['k2_status']),
            $r['k2_anomaly'] ? 'Yes' : 'No',
            $r['sla_deadline']       ?? '—',
            strtoupper($r['sla_status'] ?? 'pending'),
            $r['k2_started_at']      ?? '—',
            $r['k2_registered_at']   ?? '—',
            $r['registered_by_name'] ?? '—',
            $r['k2_remarks']         ?? '—',
        ]);
    }
    fclose($out);
    exit;
}

// ── Helper: SLA badge ─────────────────────────────────────────────────────────
function slaBadge(string $status): string {
    return match($status) {
        'on_time' => '<span class="badge bg-success">On Time</span>',
        'late'    => '<span class="badge bg-danger">Late</span>',
        default   => '<span class="badge bg-secondary">Pending</span>',
    };
}
?>
<?php include $path_to_root . 'includes/header.php'; ?>
<style>
    .metric-card { border-radius: 12px; transition: transform .15s; }
    .metric-card:hover { transform: translateY(-2px); }
    .table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; }
    .table td { font-size: .875rem; vertical-align: middle; }
    .remarks-cell { max-width: 220px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    @media print {
        .no-print { display: none !important; }
        body { background: #fff; }
    }
</style>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>
            Admin Dashboard
            <span class="badge bg-warning text-dark ms-2" style="font-size:0.6rem;">AGORA REGISTRATION</span>
            <span class="nav-muted mx-2" style="font-size:0.75rem;">›</span>
            <span class="nav-muted fw-normal" style="font-size:0.85rem;">SLA Report</span>
        </span>
        <div class="d-flex align-items-center gap-2">
            <?php
            require_once $path_to_root . 'includes/notif_helper_inapp.php';
            $notif_count = getUnreadNotifCount($conn, $current_user_id);
            $notif_items = getUnreadNotifs($conn, $current_user_id);
            ?>
            <div class="dropdown">
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
                            <div class="px-3 py-2 border-bottom small" style="cursor:pointer; background:#fffdf5;">
                                <div class="text-dark"><?= htmlspecialchars($n['message']) ?></div>
                                <div class="text-muted" style="font-size:0.65rem;"><?= date('d M H:i', strtotime($n['created_at'])) ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <a href="dashboard.php" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
            <?php $navbar_profile_fallback = 'Admin'; include $path_to_root . 'includes/navbar_profile.php'; ?>
        </div>
    </div>
</nav>

<div class="container-fluid px-4 py-4">

    <!-- ── Header ─────────────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="fw-bold mb-0">
                <i class="fas fa-chart-bar me-2 text-primary"></i>
                Agora Registration SLA Report
            </h4>
            <div class="text-muted small mt-1">
                Period: <strong><?= date('d M Y', strtotime($date_from)) ?></strong>
                &nbsp;—&nbsp;
                <strong><?= date('d M Y', strtotime($date_to)) ?></strong>
            </div>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="?date_from=<?= $date_from ?>&date_to=<?= $date_to ?>&export=xlsx"
               class="btn btn-success fw-bold shadow-sm">
                <i class="fas fa-file-csv me-2"></i> Download Excel
            </a>
            <button onclick="window.print()" class="btn btn-outline-secondary fw-bold">
                <i class="fas fa-print me-2"></i> Print
            </button>
            <a href="dashboard.php" class="btn btn-outline-dark fw-bold">
                <i class="fas fa-arrow-left me-1"></i> Back
            </a>
        </div>
    </div>

    <!-- ── Date Filter ────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4 no-print">
        <div class="card-body p-3">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">From</label>
                    <input type="date" name="date_from" class="form-control"
                           value="<?= htmlspecialchars($date_from) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-bold mb-1">To</label>
                    <input type="date" name="date_to" class="form-control"
                           value="<?= htmlspecialchars($date_to) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="fas fa-filter me-1"></i> Apply
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="?date_from=<?= date('Y-m-01') ?>&date_to=<?= date('Y-m-d') ?>"
                       class="btn btn-outline-secondary w-100">
                        This Month
                    </a>
                </div>
                <div class="col-md-2">
                    <a href="?date_from=<?= date('Y-01-01') ?>&date_to=<?= date('Y-12-31') ?>"
                       class="btn btn-outline-secondary w-100">
                        This Year
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- ── KPI Cards ──────────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-md col-6">
            <div class="card metric-card border-0 shadow-sm h-100 border-start border-4 border-primary">
                <div class="card-body py-3">
                    <div class="small text-muted fw-bold text-uppercase" style="font-size:.7rem;">Total Processed</div>
                    <div class="display-6 fw-bold text-primary"><?= $total ?></div>
                    <div class="small text-muted">vendors</div>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="card metric-card border-0 shadow-sm h-100 border-start border-4 border-success">
                <div class="card-body py-3">
                    <div class="small text-muted fw-bold text-uppercase" style="font-size:.7rem;">On Time</div>
                    <div class="display-6 fw-bold text-success"><?= $on_time ?></div>
                    <div class="small text-muted">registered on time</div>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="card metric-card border-0 shadow-sm h-100 border-start border-4 border-danger">
                <div class="card-body py-3">
                    <div class="small text-muted fw-bold text-uppercase" style="font-size:.7rem;">Late</div>
                    <div class="display-6 fw-bold text-danger"><?= $late ?></div>
                    <div class="small text-muted">exceeded SLA</div>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="card metric-card border-0 shadow-sm h-100 border-start border-4 border-warning">
                <div class="card-body py-3">
                    <div class="small text-muted fw-bold text-uppercase" style="font-size:.7rem;">Anomaly</div>
                    <div class="display-6 fw-bold text-warning"><?= $anomaly_count ?></div>
                    <div class="small text-muted">flagged vendors</div>
                </div>
            </div>
        </div>
        <div class="col-md col-6">
            <div class="card metric-card border-0 shadow-sm h-100 border-start border-4 <?= $on_time_rate >= 80 ? 'border-success' : ($on_time_rate >= 60 ? 'border-warning' : 'border-danger') ?>">
                <div class="card-body py-3">
                    <div class="small text-muted fw-bold text-uppercase" style="font-size:.7rem;">On-Time Rate</div>
                    <div class="display-6 fw-bold <?= $on_time_rate >= 80 ? 'text-success' : ($on_time_rate >= 60 ? 'text-warning' : 'text-danger') ?>">
                        <?= $on_time_rate ?>%
                    </div>
                    <div class="small text-muted"><?= $completed ?> completed</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Table ──────────────────────────────────────────────────────────── -->
    <div class="card border-0 shadow">
        <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
            <span class="fw-bold">
                <i class="fas fa-table me-2 text-muted"></i>
                Detail Agora Registration
            </span>
            <span class="badge bg-secondary"><?= $total ?> records</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">#</th>
                            <th>Company</th>
                            <th>Vendor Code</th>
                            <th>No.Request</th>
                            <th>Agora Status</th>
                            <th>SLA Deadline</th>
                            <th>SLA Status</th>
                            <th>Started</th>
                            <th>Registered</th>
                            <th>By</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="11" class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-3x opacity-25 mb-3 d-block"></i>
                                    No data found for selected period.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $i => $r): ?>
                            <tr>
                                <td class="text-muted"><?= $i + 1 ?></td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($r['company_name']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($r['company_type']) ?></div>
                                </td>
                                <td class="font-monospace fw-bold">
                                    <?= !empty($r['vendor_code']) ? htmlspecialchars($r['vendor_code']) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['k2_request_number'])): ?>
                                        <span class="badge bg-light text-dark border font-monospace">
                                            <?= htmlspecialchars($r['k2_request_number']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                    <?php if ($r['k2_anomaly']): ?>
                                        <span class="badge bg-warning text-dark ms-1" title="Anomaly vendor">
                                            <i class="fas fa-exclamation-triangle"></i>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $k2 = $r['k2_status'];
                                    $k2_badge = match($k2) {
                                        'registered'  => 'bg-success',
                                        'in_progress' => 'bg-warning text-dark',
                                        default       => 'bg-light text-dark border',
                                    };
                                    ?>
                                    <span class="badge <?= $k2_badge ?>">
                                        <?= strtoupper(str_replace('_', ' ', $k2)) ?>
                                    </span>
                                </td>
                                <td class="font-monospace small">
                                    <?php if (!empty($r['sla_deadline'])): ?>
                                        <?= date('d/m/y H:i', strtotime($r['sla_deadline'])) ?> WIB
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= slaBadge($r['sla_status'] ?? 'pending') ?></td>
                                <td class="small text-muted">
                                    <?= !empty($r['k2_started_at'])
                                        ? date('d/m/y H:i', strtotime($r['k2_started_at']))
                                        : '—' ?>
                                </td>
                                <td class="small text-muted">
                                    <?= !empty($r['k2_registered_at'])
                                        ? date('d/m/y H:i', strtotime($r['k2_registered_at']))
                                        : '—' ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['registered_by_name'])): ?>
                                        <span class="badge bg-light text-dark border small">
                                            <?= htmlspecialchars($r['registered_by_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($r['k2_remarks'])): ?>
                                        <span class="remarks-cell d-inline-block"
                                              data-bs-toggle="tooltip"
                                              data-bs-placement="left"
                                              title="<?= htmlspecialchars($r['k2_remarks']) ?>">
                                            <i class="fas fa-comment-alt text-muted me-1" style="font-size:.75rem;"></i>
                                            <?= htmlspecialchars($r['k2_remarks']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include $path_to_root . 'includes/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
        new bootstrap.Tooltip(el);
    });
});
</script>