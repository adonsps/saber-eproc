<?php
// modules/sourcing/reports.php
//
// Single Report entry point. The navbar switches between two views via
// the ?view= query param, both rendered inside the same page shell:
//   - view=vendor-distribution   (default) : analytics dashboard
//   - view=spending-per-category         : Total Spending per Taxonomy Level 1 bar chart
// Each view's content lives in modules/shared/dashboard/.
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security: Allow Sourcing OR Vendor Management
$allowed_roles = ['sourcing', 'vendor_mgt'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

// 2. Which view is active? (navbar drives this via ?view=)
$view = (($_GET['view'] ?? '') === 'spending-per-category') ? 'spending-per-category' : 'vendor-distribution';

$page_title = $view === 'spending-per-category' ? "Spending per Category" : "Vendor Distribution";
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
<?php $report_navbar_active = $view; include $path_to_root . 'modules/shared/report_navbar.php'; ?>
<div class="report-content">

<?php if ($view === 'spending-per-category'): ?>

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

<?php endif; ?>

</div><!-- /.report-content -->
</div><!-- /.report-layout -->

<?php include $path_to_root . 'includes/footer.php'; ?>
