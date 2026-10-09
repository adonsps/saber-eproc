<?php
// modules/sourcing/view_products.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Allow BOTH Sourcing and VM)
$allowed_roles = ['sourcing', 'vendor_mgt'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

if (!isset($_GET['vendor_id'])) {
    die("Error: No Vendor ID provided.");
}

$vendor_id = intval($_GET['vendor_id']);

// 2. Fetch Vendor Info
$stmt_v = $conn->prepare("SELECT company_name, company_type FROM vendors WHERE id = ?");
$stmt_v->bind_param("i", $vendor_id);
$stmt_v->execute();
$vendor = $stmt_v->get_result()->fetch_assoc();

if (!$vendor) die("Vendor not found.");

// 3. Fetch Products
$sql_p = "SELECT * FROM vendor_products WHERE vendor_id = ? ORDER BY id DESC";
$stmt_p = $conn->prepare($sql_p);
$stmt_p->bind_param("i", $vendor_id);
$stmt_p->execute();
$result = $stmt_p->get_result();

$page_title = "Product Catalog: " . $vendor['company_name'];
include $path_to_root . 'includes/header.php';
?>

<?php $nav_bg = ($_SESSION['role'] == 'vendor_mgt') ? 'bg-danger' : 'bg-dark'; ?>
<nav class="navbar navbar-dark <?= $nav_bg ?> mb-4 shadow-sm">
    <div class="container-fluid">
        <span class="navbar-brand">
            <i class="fas fa-boxes me-2"></i> Vendor Product Catalog
        </span>
        <div class="d-flex align-items-center text-white">
            <span class="me-3 small opacity-75 d-none d-md-block">
                <?= htmlspecialchars($vendor['company_name']) ?>
            </span>
            <a href="all_vendors.php" class="btn btn-sm btn-outline-light me-2">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    
    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h4 class="fw-bold text-primary mb-0"><?= htmlspecialchars($vendor['company_name']) ?></h4>
                <span class="badge bg-secondary"><?= htmlspecialchars($vendor['company_type']) ?></span>
            </div>
            <div>
                <span class="badge bg-info text-dark fs-6"><?= $result->num_rows ?> Items Listed</span>
            </div>
        </div>
    </div>

    <?php if ($result->num_rows > 0): ?>
        <div class="row g-4">
            <?php while($prod = $result->fetch_assoc()): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="row g-0 h-100">
                            <!-- Image Column -->
                            <div class="col-4 bg-light d-flex align-items-center justify-content-center" style="min-height: 150px;">
                                <?php if(!empty($prod['product_image'])): ?>
                                    <img src="<?= $path_to_root ?>../uploads/products/<?= htmlspecialchars($prod['product_image']) ?>" 
                                         class="img-fluid rounded-start h-100 w-100" 
                                         style="object-fit: cover;" 
                                         alt="Product Image">
                                <?php else: ?>
                                    <i class="fas fa-box fa-3x text-muted opacity-25"></i>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Details Column -->
                            <div class="col-8">
                                <div class="card-body d-flex flex-column h-100">
                                    <h6 class="card-title fw-bold mb-1 text-truncate" title="<?= htmlspecialchars($prod['item_name']) ?>">
                                        <?= htmlspecialchars($prod['item_name']) ?>
                                    </h6>
                                    <div class="mb-2">
                                        <small class="text-muted"><i class="fas fa-tag me-1"></i> <?= htmlspecialchars($prod['brand']) ?></small>
                                    </div>
                                    
                                    <div class="mb-2 small">
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($prod['category']) ?></span>
                                        <?php if($prod['sub_category']): ?>
                                            <span class="text-muted">&rsaquo; <?= htmlspecialchars($prod['sub_category']) ?></span>
                                        <?php endif; ?>
                                    </div>

                                    <p class="card-text small text-muted flex-grow-1 text-truncate" style="max-height: 40px;">
                                        <?= htmlspecialchars($prod['description']) ?>
                                    </p>
                                    
                                    <div class="mt-auto border-top pt-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span class="fw-bold text-success">
                                                Rp <?= number_format($prod['price_idr'], 0, ',', '.') ?>
                                                <span class="text-muted fw-normal small">/<?= $prod['uom'] ?></span>
                                            </span>
                                            <span class="badge bg-warning text-dark" title="Minimum Order Qty">MOQ: <?= $prod['moq'] ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="alert alert-secondary text-center py-5">
            <i class="fas fa-box-open fa-3x mb-3 text-muted"></i>
            <h5>No Products Registered</h5>
            <p class="text-muted">This vendor has not uploaded any products to their catalog yet.</p>
        </div>
    <?php endif; ?>

</div>

<?php include $path_to_root . 'includes/footer.php'; ?>