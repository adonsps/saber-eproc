<?php
// modules/sourcing/all_vendors.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Allow BOTH Sourcing and VM)
$allowed_roles = ['sourcing', 'vendor_mgt'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

$page_title = "Master Vendor Database";
include $path_to_root . 'includes/header.php';

// 2. Fetch ALL Vendors + Their Latest PQ Score (if exists)
// We use a specific subquery to ensure we only get the LATEST submission for each vendor
$sql = "SELECT 
            v.*, 
            s.risk_percentage, 
            s.risk_category,
            s.approved_at,
            (SELECT COUNT(*) FROM vendor_products vp WHERE vp.vendor_id = v.id) as product_count
        FROM vendors v
        LEFT JOIN (
            SELECT vendor_id, risk_percentage, risk_category, approved_at 
            FROM pq_submissions 
            WHERE id IN (SELECT MAX(id) FROM pq_submissions GROUP BY vendor_id)
        ) s ON v.id = s.vendor_id
        ORDER BY v.id DESC";

$result = $conn->query($sql);
?>

<?php $nav_bg = ($_SESSION['role'] == 'vendor_mgt') ? 'bg-danger' : 'bg-dark'; ?>
<nav class="navbar navbar-dark <?= $nav_bg ?> mb-4">
    <div class="container-fluid">
        <span class="navbar-brand">
            <i class="fas fa-database"></i> Master Vendor Data
        </span>
        <div class="d-flex text-white align-items-center">
            <a href="dashboard.php" class="btn btn-sm btn-outline-light me-2">Back to Dashboard</a>
            <a href="../../logout.php" class="btn btn-sm btn-light text-dark">Logout</a>
        </div>
    </div>
</nav>

<div class="container-fluid px-4">
    
    <div class="card shadow">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Registered Vendors & PQ Results</h5>
            <span class="badge bg-secondary"><?= $result->num_rows ?> Records Found</span>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Company Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>PQ Score / Risk</th>
                            <th class="text-center">Products</th>
                            <th class="text-center">Actions</th> 
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['company_name']) ?></strong><br>
                                        <small class="text-muted">
                                            <i class="fas fa-user"></i> <?= htmlspecialchars($row['contact_person']) ?><br>
                                            <i class="fas fa-envelope"></i> <?= htmlspecialchars($row['email'] ?? 'No Email') ?>
                                        </small>
                                    </td>
                                    
                                    <td><span class="badge bg-light text-dark border"><?= $row['company_type'] ?></span></td>
                                    
                                    <td>
                                        <?php 
                                            $st = $row['registration_status'];
                                            $cls = 'bg-secondary';
                                            if($st=='approved') $cls='bg-success';
                                            if($st=='pq_submitted') $cls='bg-primary';
                                            if($st=='revision_required') $cls='bg-warning text-dark';
                                            if($st=='rejected') $cls='bg-danger';
                                            echo "<span class='badge $cls'>" . strtoupper(str_replace('_',' ',$st)) . "</span>";
                                        ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($row['risk_percentage'])): ?>
                                            <div class="fw-bold"><?= $row['risk_percentage'] ?>%</div>
                                            <small class="text-muted"><?= $row['risk_category'] ?></small>
                                            <?php if($row['approved_at']): ?>
                                                <br><small style="font-size:0.7em; color:green;">Approved: <?= date('d M Y', strtotime($row['approved_at'])) ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted small">- Not assessed -</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center">
                                        <?php if($row['product_count'] > 0): ?>
                                            <a href="view_products.php?vendor_id=<?= $row['id'] ?>" class="btn btn-sm btn-outline-primary position-relative">
                                                <i class="fas fa-box-open"></i> Catalog
                                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                                    <?= $row['product_count'] ?>
                                                </span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted small">Empty</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="text-center" style="min-width: 120px;">
                                        <button type="button" class="btn btn-sm btn-info text-white me-1" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#vendorModal<?= $row['id'] ?>"
                                                title="View Banking & Goods Info">
                                            <i class="fas fa-eye"></i>
                                        </button>

                                        <?php if($_SESSION['role'] == 'sourcing'): ?>
                                            <a href="reset_pq.php?id=<?= $row['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('TESTING MODE: This will DELETE all PQ data for this vendor and reset their status to Invited. Proceed?');"
                                               title="Reset Vendor for Testing">
                                                <i class="fas fa-undo"></i>
                                            </a>
                                        <?php endif; ?>
                                        
                                        <div class="modal fade" id="vendorModal<?= $row['id'] ?>" tabindex="-1">
                                            <div class="modal-dialog modal-lg">
                                                <div class="modal-content text-start">
                                                    <div class="modal-header bg-info text-white">
                                                        <h5 class="modal-title">Vendor Details: <?= htmlspecialchars($row['company_name']) ?></h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <h6 class="text-primary border-bottom pb-2">Banking Information</h6>
                                                                <table class="table table-sm table-borderless">
                                                                    <tr><td class="text-muted">Bank Name:</td><td><strong><?= htmlspecialchars($row['bank_name'] ?? '-') ?></strong></td></tr>
                                                                    <tr><td class="text-muted">Account No:</td><td><strong><?= htmlspecialchars($row['bank_account_number'] ?? '-') ?></strong></td></tr>
                                                                    <tr><td class="text-muted">Holder:</td><td><strong><?= htmlspecialchars($row['bank_holder_name'] ?? '-') ?></strong></td></tr>
                                                                    <tr><td class="text-muted">NIB:</td><td><?= htmlspecialchars($row['nib_number'] ?? '-') ?></td></tr>
                                                                </table>
                                                            </div>

                                                            <div class="col-md-6">
                                                                <h6 class="text-primary border-bottom pb-2">Goods & Services</h6>
                                                                <div class="p-3 bg-light rounded" style="max-height:150px; overflow-y:auto;">
                                                                    <?= nl2br(htmlspecialchars($row['goods_services_list'] ?? '-')) ?>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <hr>
                                                        
                                                        <h6 class="text-primary">Contact Information</h6>
                                                        <p class="mb-0">
                                                            <strong>Phone:</strong> <?= htmlspecialchars($row['phone'] ?? '-') ?><br>
                                                            <strong>Registered:</strong> <?= date('d M Y', strtotime($row['created_at'])) ?>
                                                        </p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center">No vendors found in database.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>