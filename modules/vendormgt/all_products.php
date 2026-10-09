<?php
// modules/sourcing/all_products.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Allow BOTH Sourcing and VM)
$allowed_roles = ['sourcing', 'vendor_mgt'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

$page_title = "Master Product Catalog";
include $path_to_root . 'includes/header.php';

// 2. Handle Filters
$search = $_GET['search'] ?? '';
$cat_filter = $_GET['category'] ?? '';

// Build Query
// Always exclude products belonging to developer/QA test vendors.
$sql_p = "SELECT p.*, v.company_name, v.company_type, v.registration_status 
          FROM vendor_products p 
          JOIN vendors v ON p.vendor_id = v.id 
          WHERE p.is_active = 1
            AND v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)";

if (!empty($search)) {
    $safe_search = $conn->real_escape_string($search);
    $sql_p .= " AND (p.item_name LIKE '%$safe_search%' OR p.brand LIKE '%$safe_search%')";
}

if (!empty($cat_filter)) {
    $safe_cat = $conn->real_escape_string($cat_filter);
    $sql_p .= " AND p.category = '$safe_cat'";
}

$sql_p .= " ORDER BY p.id DESC";
$result = $conn->query($sql_p);

// Fetch categories for the filter dropdown
$cat_sql = "SELECT DISTINCT category FROM vendor_products WHERE is_active = 1 AND category IS NOT NULL";
$cat_res = $conn->query($cat_sql);
?>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Master Product Catalog
        </span>
        <div class="d-flex align-items-center">
            <a href="dashboard.php" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Dashboard
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    
    <!-- Success Message -->
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Filter & Search Bar -->
    <div class="card shadow-sm mb-4 border-0 bg-light">
        <div class="card-body p-3">
            <form method="GET" action="all_products.php" class="row g-2 align-items-center">
                <div class="col-md-5">
                    <div class="input-group">
                        <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                        <input type="text" name="search" class="form-control" placeholder="Search item or brand..." value="<?= htmlspecialchars($search) ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <select name="category" class="form-select">
                        <option value="">All Categories</option>
                        <?php while($c = $cat_res->fetch_assoc()): ?>
                            <option value="<?= htmlspecialchars($c['category']) ?>" <?= ($cat_filter == $c['category']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['category']) ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Filter</button>
                    <?php if(!empty($search) || !empty($cat_filter)): ?>
                        <a href="all_products.php" class="btn btn-outline-secondary">Reset</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Product Grid -->
    <?php if ($result->num_rows > 0): ?>
        <div class="row g-4">
            <?php while($prod = $result->fetch_assoc()): ?>
                <div class="col-md-6 col-lg-4 col-xl-3">
                    <div class="card h-100 shadow-sm border-0 position-relative">
                        
                        <!-- Vendor Name Badge -->
                        <div class="position-absolute top-0 start-0 m-2 z-1">
                            <span class="badge bg-dark shadow-sm px-2 py-1" style="font-size: 0.7rem;">
                                <i class="fas fa-building me-1"></i> <?= htmlspecialchars($prod['company_name']) ?>
                            </span>
                        </div>

                        <!-- Image -->
                        <div class="bg-light d-flex align-items-center justify-content-center border-bottom" style="height: 200px; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#detailModal<?= $prod['id'] ?>">
                            <?php 
                                $img_src = "";
                                if(!empty($prod['product_image'])) {
                                    if (strpos($prod['product_image'], 'uploads/') !== false) {
                                        $img_src = (strpos($prod['product_image'], '../../') !== false) ? $prod['product_image'] : "../../" . $prod['product_image'];
                                    } else {
                                        $img_src = $path_to_root . "../uploads/" . $prod['product_image'];
                                    }
                                }
                            ?>
                            <?php if(!empty($img_src)): ?>
                                <img src="<?= htmlspecialchars($img_src) ?>" class="img-fluid h-100 w-100" style="object-fit: cover;" alt="Image">
                            <?php else: ?>
                                <i class="fas fa-box fa-4x text-muted opacity-25"></i>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Details -->
                        <div class="card-body d-flex flex-column">
                            <h6 class="card-title fw-bold mb-1 text-truncate" title="<?= htmlspecialchars($prod['item_name']) ?>">
                                <?= htmlspecialchars($prod['item_name']) ?>
                            </h6>
                            <div class="small text-muted mb-2">
                                <i class="fas fa-tag me-1"></i> <?= htmlspecialchars($prod['brand'] ?: 'No Brand') ?>
                            </div>
                            
                            <div class="mb-2">
                                <span class="badge bg-light text-primary border border-primary-subtle small"><?= htmlspecialchars($prod['category'] ?: 'General') ?></span>
                            </div>
                            
                            <div class="mt-auto pt-2 border-top">
                                <div class="d-flex justify-content-between align-items-end mb-3">
                                    <div>
                                        <div class="text-success fw-bold fs-5">Rp <?= number_format($prod['price_idr'], 0, ',', '.') ?></div>
                                        <small class="text-muted" style="font-size: 0.7rem;">MOQ: <?= $prod['moq'] ?> <?= htmlspecialchars($prod['uom']) ?></small>
                                    </div>
                                    <button class="btn btn-sm btn-outline-secondary rounded-pill" data-bs-toggle="modal" data-bs-target="#detailModal<?= $prod['id'] ?>" title="View Details">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>

                                <!-- ACTION BUTTONS -->
                                <div class="d-flex gap-2">
                                    <!--<a href="view_products.php?vendor_id=<?= $prod['vendor_id'] ?>" class="btn btn-sm btn-light border w-100" title="View all products from this vendor">-->
                                    <!--    Vendor Catalog-->
                                    <!--</a>-->
                                    <?php if($_SESSION['role'] == 'sourcing'): ?>
                                        <button type="button" 
                                                    class="btn btn-sm btn-primary w-100 fw-bold" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#requestModal<?= $prod['id'] ?>">
                                                Request Sample
                                            </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PRODUCT DETAIL MODAL -->
                <div class="modal fade" id="detailModal<?= $prod['id'] ?>" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content border-0 shadow-lg">
                            <div class="modal-header bg-light border-0">
                                <h5 class="modal-title fw-bold">Product Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="row g-4">
                                    <div class="col-md-5 text-center bg-light rounded d-flex align-items-center justify-content-center p-2" style="min-height: 250px;">
                                        <?php if(!empty($img_src)): ?>
                                            <img src="<?= htmlspecialchars($img_src) ?>" class="img-fluid rounded shadow-sm" style="max-height: 300px;">
                                        <?php else: ?>
                                            <div class="text-muted"><i class="fas fa-box fa-5x opacity-25"></i><br>No Image</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="col-md-7">
                                        <div class="mb-2">
                                            <span class="badge bg-dark"><i class="fas fa-building me-1"></i> <?= htmlspecialchars($prod['company_name']) ?></span>
                                            <span class="badge <?= $prod['registration_status'] == 'approved' ? 'bg-success' : 'bg-warning text-dark' ?>">Vendor Status: <?= strtoupper($prod['registration_status']) ?></span>
                                        </div>
                                        
                                        <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($prod['item_name']) ?></h4>
                                        <div class="mb-3">
                                            <span class="badge bg-secondary"><?= htmlspecialchars($prod['brand']) ?></span>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($prod['category']) ?></span>
                                        </div>

                                        <h4 class="text-success fw-bold mb-3">
                                            Rp <?= number_format($prod['price_idr'], 0, ',', '.') ?> 
                                            <span class="text-muted fs-6 fw-normal">/ <?= htmlspecialchars($prod['uom']) ?></span>
                                        </h4>

                                        <div class="mb-3">
                                            <strong class="text-muted small text-uppercase">Description</strong>
                                            <div class="mt-1 bg-light p-3 rounded border small text-dark">
                                                <?= nl2br(htmlspecialchars($prod['description'] ?: 'No description provided.')) ?>
                                            </div>
                                        </div>

                                        <div class="row g-2">
                                            <div class="col-6">
                                                <div class="border rounded p-2 text-center bg-light">
                                                    <small class="text-muted d-block">Minimum Order</small>
                                                    <strong class="text-dark"><?= $prod['moq'] ?> <?= htmlspecialchars($prod['uom']) ?></strong>
                                                </div>
                                            </div>
                                            <?php if(!empty($prod['commodity'])): ?>
                                            <div class="col-6">
                                                <div class="border rounded p-2 text-center bg-light">
                                                    <small class="text-muted d-block">Commodity</small>
                                                    <strong class="text-dark text-truncate d-block"><?= htmlspecialchars($prod['commodity']) ?></strong>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light border-0">
                                <a href="view_products.php?vendor_id=<?= $prod['vendor_id'] ?>" class="btn btn-outline-dark me-auto">View Vendor Catalog</a>
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if($_SESSION['role'] == 'sourcing'): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal<?= $prod['id'] ?>" data-bs-dismiss="modal">
                                        <i class="fas fa-paper-plane me-1"></i> Request Sample
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- SAMPLE REQUEST MODAL (Sourcing Only) -->
                <?php if($_SESSION['role'] == 'sourcing'): ?>
                    <div class="modal fade" id="requestModal<?= $prod['id'] ?>" tabindex="-1">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content border-0 shadow">
                                <div class="modal-header bg-primary text-white border-0">
                                    <h5 class="modal-title"><i class="fas fa-paper-plane me-2"></i>Request Sample</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <form action="request_sample_action.php" method="POST">
                                    <div class="modal-body p-4">
                                        <input type="hidden" name="vendor_id" value="<?= $prod['vendor_id'] ?>">
                                        <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                        
                                        <div class="alert alert-light border mb-4">
                                            Requesting from: <strong><?= htmlspecialchars($prod['company_name']) ?></strong><br>
                                            Product: <strong><?= htmlspecialchars($prod['item_name']) ?></strong>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label small fw-bold">Instructions / Delivery Details *</label>
                                            <textarea name="request_note" class="form-control" rows="4" placeholder="E.g., Please send 2 samples to Head Office building A..." required></textarea>
                                        </div>
                                    </div>
                                    <div class="modal-footer bg-light border-0">
                                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" name="submit_request" class="btn btn-primary px-4 fw-bold shadow-sm">Send Request</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

            <?php endwhile; ?>
        </div>
    <?php else: ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center py-5">
                <i class="fas fa-search fa-4x text-muted opacity-25 mb-3"></i>
                <h4 class="text-secondary fw-bold">No products found</h4>
                <p class="text-muted">Try adjusting your search criteria or checking back later.</p>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php include $path_to_root . 'includes/footer.php'; ?>