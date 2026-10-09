<?php
// modules/vendormgt/reports.php
//
// Single Report entry point. The navbar switches between the views via
// the ?view= query param, all rendered inside the same page shell:
//   - view=vendor-distribution   (default) : analytics dashboard + PQ Review SLA
//   - view=spending-per-category         : Total Spending per Taxonomy Level 1 bar chart
//   - view=pq-risk-analysis              : Pareto of PQ risk drivers (vendor_mgt only)
// Each view's content lives in modules/shared/dashboard/.
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security: Allow Sourcing OR Vendor Management
$allowed_roles = ['sourcing', 'vendor_mgt', 'vendormgt_head'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

// PQ Risk Analysis is being rolled out to the Vendor Management team first —
// the tab is hidden and the view refuses to render for every other role.
$can_view_pq_risk = ($_SESSION['role'] === 'vendor_mgt');

// 2. Which view is active? (navbar drives this via ?view=)
$view = $_GET['view'] ?? '';
if ($view === 'pq-risk-analysis' && $can_view_pq_risk) {
    $view = 'pq-risk-analysis';
} elseif ($view === 'spending-per-category') {
    $view = 'spending-per-category';
} else {
    $view = 'vendor-distribution';
}

$page_title = [
    'spending-per-category' => 'Spending per Category',
    'pq-risk-analysis'      => 'PQ Risk Analysis',
][$view] ?? 'Vendor Distribution';

// --- DATA GATHERING (only the Reports view needs the PQ SLA figures) ---
if ($view === 'vendor-distribution') {
    // Always exclude developer/QA test accounts from reports.
    $exclude_test = "WHERE user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)";

    // D. SLA Performance — Sourcing → VM forward_head, 16 working hours target
    require_once $path_to_root . 'includes/sla_helper.php';

    // D1. Aggregate stats from closed forward_head legs
    $sla_agg_sql = "SELECT
                      COUNT(*)                              AS total_closed,
                      SUM(sla_met = 1)                      AS on_time,
                      SUM(sla_met = 0)                      AS breached,
                      ROUND(AVG(working_hours_elapsed), 2)  AS avg_hours,
                      ROUND(MIN(working_hours_elapsed), 2)  AS min_hours,
                      ROUND(MAX(working_hours_elapsed), 2)  AS max_hours
                    FROM pq_sla_logs
                    WHERE vm_action_type = 'forward_head'";
    $sla_agg = $conn->query($sla_agg_sql)->fetch_assoc();

    $sla_total_closed = (int)($sla_agg['total_closed'] ?? 0);
    $sla_on_time      = (int)($sla_agg['on_time']      ?? 0);
    $sla_breached     = (int)($sla_agg['breached']     ?? 0);
    $sla_hit_rate     = $sla_total_closed > 0 ? round(($sla_on_time / $sla_total_closed) * 100, 1) : 0.0;
    $sla_avg_hours    = (float)($sla_agg['avg_hours'] ?? 0);

    // D2. Currently-open legs at risk
    $sla_open_sql = "SELECT
                      COUNT(*) AS open_count,
                      SUM(CASE WHEN sla_deadline < NOW() THEN 1 ELSE 0 END) AS open_overdue
                     FROM pq_sla_logs
                     WHERE vm_action_at IS NULL";
    $sla_open = $conn->query($sla_open_sql)->fetch_assoc();
    $sla_open_count   = (int)($sla_open['open_count']   ?? 0);
    $sla_open_overdue = (int)($sla_open['open_overdue'] ?? 0);

    // D3. Per-reviewer breakdown (VM staff who closed legs with forward_head)
    $sla_reviewer_sql = "SELECT
                           u.full_name,
                           COUNT(*)                              AS total,
                           SUM(l.sla_met = 1)                    AS on_time,
                           ROUND(AVG(l.working_hours_elapsed),2) AS avg_hours
                         FROM pq_sla_logs l
                         LEFT JOIN users u ON l.vm_reviewer_id = u.id
                         WHERE l.vm_action_type = 'forward_head'
                         GROUP BY l.vm_reviewer_id
                         ORDER BY total DESC
                         LIMIT 10";
    $sla_reviewers = $conn->query($sla_reviewer_sql);

    // D4. Latest 15 submissions with SLA outcome
    $sla_recent_sql = "SELECT v.company_name, v.company_type,
                              s.id AS submission_id, s.status,
                              s.pq_sla_deadline, s.pq_sla_first_forward_at,
                              s.pq_sla_final_hours, s.pq_sla_final_met,
                              (SELECT COUNT(*) FROM pq_sla_logs WHERE submission_id = s.id) AS legs
                       FROM pq_submissions s
                       JOIN vendors v ON s.vendor_id = v.id
                       WHERE s.pq_sla_deadline IS NOT NULL
                         AND v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)
                       ORDER BY s.id DESC
                       LIMIT 15";
    $sla_recent = $conn->query($sla_recent_sql);
}

include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Reports</span>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to Dashboard
            </a>
            <?php include $path_to_root . 'includes/navbar_profile.php'; ?>
        </div>
    </div>
</nav>

<div class="report-layout">
<?php
$report_navbar_active       = $view;
$report_navbar_show_pq_risk = $can_view_pq_risk;
include $path_to_root . 'modules/shared/report_navbar.php';
?>
<div class="report-content">

<?php if ($view === 'pq-risk-analysis'): ?>

<!-- ============================================================
     PQ RISK ANALYSIS — Pareto of the questions driving Critical/High risk
     ============================================================ -->
<?php include $path_to_root . 'modules/shared/dashboard/pq_risk_analysis.php'; ?>

<?php elseif ($view === 'spending-per-category'): ?>

<!-- ============================================================
     DASHBOARD VIEW — Total Spending per Taxonomy Level 1 (bar chart)
     ============================================================ -->
<div class="container pb-4">
    <h4 class="fw-bold text-dark mb-3 mt-2">
        <i class="fas fa-coins text-primary me-2"></i>
        Spending per Category
        <small class="text-muted fw-normal" style="font-size: 0.85rem;">
            · Total spending per Taxonomy category (Level 1)
        </small>
    </h4>

    <?php include $path_to_root . 'modules/shared/dashboard/spending.php'; ?>
</div>

<?php else: ?>

<?php include $path_to_root . 'modules/shared/dashboard/analytics_widgets.php'; ?>

<!-- ============================================================
     PQ SLA SECTION — Sourcing → VM forward_head (16-hour target)
     ============================================================ -->
<div class="container pb-5">
    <h4 class="fw-bold text-dark mb-3 mt-2">
        <i class="fas fa-stopwatch text-primary me-2"></i>
        PQ Review SLA
        <small class="text-muted fw-normal" style="font-size: 0.85rem;">
            · Target: 16 working hours (2 days) from last sourcing submission to VM forward to Head
        </small>
    </h4>

    <!-- KPI Cards -->
    <div class="row mb-4 g-3">
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-success h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">SLA Hit Rate</small>
                    <h2 class="display-6 fw-bold text-success mb-0"><?= $sla_hit_rate ?>%</h2>
                    <small class="text-muted"><?= $sla_on_time ?> of <?= $sla_total_closed ?> on time</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-primary h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Avg. Working Hours</small>
                    <h2 class="display-6 fw-bold text-primary mb-0"><?= number_format($sla_avg_hours, 1) ?>h</h2>
                    <small class="text-muted">across <?= $sla_total_closed ?> closed legs</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-warning h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Open Now</small>
                    <h2 class="display-6 fw-bold text-warning mb-0"><?= $sla_open_count ?></h2>
                    <small class="text-muted"><?= $sla_open_overdue ?> overdue</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-danger h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Breaches</small>
                    <h2 class="display-6 fw-bold text-danger mb-0"><?= $sla_breached ?></h2>
                    <small class="text-muted">legs exceeded 16h</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Two-column: reviewer table + recent submissions -->
    <div class="row g-3">
        <!-- Reviewer Performance -->
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="fas fa-user-check text-primary me-2"></i>
                        Reviewer Performance (Top 10)
                    </h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">VM Reviewer</th>
                                <th class="text-center">Closed</th>
                                <th class="text-center">On Time</th>
                                <th class="text-end pe-3">Avg.</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($sla_reviewers && $sla_reviewers->num_rows > 0): ?>
                            <?php while($rv = $sla_reviewers->fetch_assoc()):
                                $rate = $rv['total'] > 0 ? round(($rv['on_time'] / $rv['total']) * 100) : 0;
                                $rate_class = $rate >= 80 ? 'text-success' : ($rate >= 50 ? 'text-warning' : 'text-danger');
                            ?>
                                <tr>
                                    <td class="ps-3 fw-medium"><?= htmlspecialchars($rv['full_name'] ?? '—') ?></td>
                                    <td class="text-center"><?= $rv['total'] ?></td>
                                    <td class="text-center">
                                        <span class="fw-bold <?= $rate_class ?>"><?= $rate ?>%</span>
                                        <small class="text-muted">(<?= $rv['on_time'] ?>)</small>
                                    </td>
                                    <td class="text-end pe-3"><?= number_format((float)$rv['avg_hours'], 1) ?>h</td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4 fst-italic">No SLA data yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Submissions -->
        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="fas fa-clock-rotate-left text-primary me-2"></i>
                        Recent Submissions — SLA Outcome (Last 15)
                    </h6>
                </div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Company</th>
                                <th class="text-center">Legs</th>
                                <th class="text-center">Status</th>
                                <th class="text-end pe-3">SLA</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($sla_recent && $sla_recent->num_rows > 0): ?>
                            <?php while($rs = $sla_recent->fetch_assoc()):
                                $badge = getPqSlaBadge(
                                    $conn,
                                    $rs['pq_sla_deadline']         ?? null,
                                    $rs['pq_sla_first_forward_at'] ?? null,
                                    isset($rs['pq_sla_final_hours']) ? (float)$rs['pq_sla_final_hours'] : null,
                                    isset($rs['pq_sla_final_met'])   ? (int)$rs['pq_sla_final_met']     : null
                                );
                            ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="fw-medium text-dark"><?= htmlspecialchars($rs['company_name']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($rs['company_type']) ?></small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark border"><?= $rs['legs'] ?></span>
                                    </td>
                                    <td class="text-center">
                                        <small class="text-muted"><?= strtoupper(str_replace('_', ' ', $rs['status'])) ?></small>
                                    </td>
                                    <td class="text-end pe-3">
                                        <span class="badge <?= $badge['class'] ?> rounded-pill px-2 py-1"
                                              title="<?= htmlspecialchars($badge['title'], ENT_QUOTES) ?>">
                                            <?= htmlspecialchars($badge['label']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="4" class="text-center text-muted py-4 fst-italic">No submissions yet</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php endif; ?>

</div><!-- /.report-content -->
</div><!-- /.report-layout -->

<?php include $path_to_root . 'includes/footer.php'; ?>
