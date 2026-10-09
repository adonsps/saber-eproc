<?php
// modules/admin/sla_backfill.php
//
// ONE-SHOT BACKFILL for vendors approved BEFORE the SLA system was added.
// Run once, then delete this file (or leave it — it's idempotent and safe to rerun).
//
// Logic:
//   - Find all approved vendors where sla_deadline IS NULL
//   - Compute the deadline = approved_at + 3 working days (skipping weekends/holidays)
//   - If k2_status='registered' AND k2_registered_at IS NOT NULL → set on_time / late
//   - Else → set pending
//
// Access: Admin only.

session_start();
require '../../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied — admin only.");
}

require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';

// Find historical vendors that need backfill
$sql = "
    SELECT v.id AS vendor_id,
           v.company_name,
           v.k2_status,
           v.k2_registered_at,
           s.approved_at
    FROM vendors v
    LEFT JOIN pq_submissions s
           ON s.vendor_id = v.id
          AND s.status = 'approved'
    WHERE v.registration_status = 'approved'
      AND v.sla_deadline IS NULL
      AND s.approved_at IS NOT NULL
    ORDER BY s.approved_at ASC
";
$res = $conn->query($sql);

$processed = 0;
$onTime    = 0;
$late      = 0;
$pending   = 0;
$rows_html = '';

if ($res && $res->num_rows > 0) {
    while ($row = $res->fetch_assoc()) {
        $vendor_id    = (int)$row['vendor_id'];
        $approved_at  = $row['approved_at'];
        $k2_status    = $row['k2_status'];
        $k2_done_at   = $row['k2_registered_at'];
        $company      = $row['company_name'];

        // Compute deadline from approved_at + 3 working days
        $deadline = calculateSlaDeadline($conn, $approved_at, 3);

        // Decide sla_status
        if ($k2_status === 'registered' && !empty($k2_done_at)) {
            $sla_status = (strtotime($k2_done_at) <= strtotime($deadline)) ? 'on_time' : 'late';
            if ($sla_status === 'on_time') $onTime++; else $late++;
        } else {
            $sla_status = 'pending';
            $pending++;
        }

        // Apply
        $upd = $conn->prepare("UPDATE vendors SET sla_deadline = ?, sla_status = ? WHERE id = ?");
        $upd->bind_param("ssi", $deadline, $sla_status, $vendor_id);
        $upd->execute();
        $processed++;

        $rows_html .= '<tr>'
            . '<td>' . $vendor_id . '</td>'
            . '<td>' . htmlspecialchars($company) . '</td>'
            . '<td>' . htmlspecialchars($approved_at) . '</td>'
            . '<td>' . htmlspecialchars($deadline) . '</td>'
            . '<td>' . htmlspecialchars($k2_done_at ?? '—') . '</td>'
            . '<td><span class="badge bg-' . ($sla_status === 'late' ? 'danger' : ($sla_status === 'on_time' ? 'success' : 'secondary')) . '">' . $sla_status . '</span></td>'
            . '</tr>';
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>SLA Backfill</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <h2>SLA Backfill — Historical Vendors</h2>
    <p class="text-muted">Filled missing <code>sla_deadline</code> + <code>sla_status</code> for approved vendors that pre-date the SLA system.</p>

    <div class="row g-3 my-3">
        <div class="col"><div class="card border-primary"><div class="card-body"><h6 class="text-muted small">Processed</h6><h3><?= $processed ?></h3></div></div></div>
        <div class="col"><div class="card border-success"><div class="card-body"><h6 class="text-muted small">On Time</h6><h3 class="text-success"><?= $onTime ?></h3></div></div></div>
        <div class="col"><div class="card border-danger"><div class="card-body"><h6 class="text-muted small">Late</h6><h3 class="text-danger"><?= $late ?></h3></div></div></div>
        <div class="col"><div class="card border-secondary"><div class="card-body"><h6 class="text-muted small">Pending</h6><h3 class="text-secondary"><?= $pending ?></h3></div></div></div>
    </div>

    <?php if ($processed > 0): ?>
        <div class="card shadow-sm">
            <div class="card-body">
                <table class="table table-sm table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Vendor ID</th>
                            <th>Company</th>
                            <th>Approved At</th>
                            <th>SLA Deadline (computed)</th>
                            <th>Agora Registered At</th>
                            <th>SLA Status</th>
                        </tr>
                    </thead>
                    <tbody><?= $rows_html ?></tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-info">No vendors needed backfilling. All approved vendors already have SLA data.</div>
    <?php endif; ?>

    <a href="dashboard.php" class="btn btn-primary mt-3">← Back to Dashboard</a>
    <p class="text-muted small mt-4">Safe to rerun — only touches rows where <code>sla_deadline IS NULL</code>.</p>
</div>
</body>
</html>