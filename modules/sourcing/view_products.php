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

<!-- Dynamic Navbar Color based on Role -->
<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Vendor Product Catalog
        </span>
        <div class="d-flex align-items-center">
            <span class="me-3 small nav-muted d-none d-md-block">
                <?= htmlspecialchars($vendor['company_name'] ?? '') ?>
            </span>
            <!-- Back button returns to the list in the same folder -->
            <a href="javascript:window.history.back()" class="btn btn-sm btn-nav">
                <i class="fas fa-arrow-left me-1"></i> Back to List
            </a>
        </div>
    </div>
</nav>

<div class="container pb-5">
    
    <!-- Success Message -->
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg'] ?? '') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm mb-4">
        <div class="card-body d-flex justify-content-between align-items-center">
            <div>
                <h4 class="fw-bold text-primary mb-0"><?= htmlspecialchars($vendor['company_name'] ?? '') ?></h4>
                <span class="badge bg-secondary"><?= htmlspecialchars($vendor['company_type'] ?? '') ?></span>
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
                            <!-- Image Column (Clickable to trigger modal) -->
                            <div class="col-4 bg-light d-flex align-items-center justify-content-center" style="min-height: 150px; cursor: pointer;" data-bs-toggle="modal" data-bs-target="#detailModal<?= $prod['id'] ?>">
                                <?php 
                                    $img_src = "";
                                    if(!empty($prod['product_image'])) {
                                        if (strpos($prod['product_image'], 'uploads/') !== false) {
                                            if (strpos($prod['product_image'], '../../') !== false) {
                                                $img_src = $prod['product_image'];
                                            } else {
                                                $img_src = "../../" . $prod['product_image'];
                                            }
                                        } else {
                                            $img_src = $path_to_root . "../uploads/" . $prod['product_image'];
                                        }
                                    }
                                ?>
                                
                                <?php if(!empty($img_src)): ?>
                                    <img src="<?= htmlspecialchars($img_src ?? '') ?>" 
                                         class="img-fluid rounded-start h-100 w-100" 
                                         style="object-fit: cover;" 
                                         alt="Product Image"
                                         onerror="this.onerror=null; this.src='https://via.placeholder.com/150?text=No+Image';">
                                <?php else: ?>
                                    <i class="fas fa-box fa-3x text-muted opacity-25"></i>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Details Column -->
                            <div class="col-8">
                                <div class="card-body d-flex flex-column h-100">
                                    <h6 class="card-title fw-bold mb-1 text-truncate" title="<?= htmlspecialchars($prod['item_name'] ?? '') ?>">
                                        <a href="#" class="text-decoration-none text-dark" data-bs-toggle="modal" data-bs-target="#detailModal<?= $prod['id'] ?>">
                                            <?= htmlspecialchars($prod['item_name'] ?? '') ?>
                                        </a>
                                    </h6>
                                    <div class="mb-2">
                                        <small class="text-muted"><i class="fas fa-tag me-1"></i> <?= htmlspecialchars($prod['brand'] ?? '') ?></small>
                                    </div>
                                    
                                    <div class="mb-2 small">
                                        <span class="badge bg-light text-dark border"><?= htmlspecialchars($prod['category'] ?? '') ?></span>
                                    </div>

                                    <p class="card-text small text-muted flex-grow-1 text-truncate" style="max-height: 40px;">
                                        <?= htmlspecialchars($prod['description'] ?? '') ?>
                                    </p>
                                    
                                    <div class="mt-auto border-top pt-2">
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="fw-bold text-success">
                                                Rp <?= number_format($prod['price_idr'], 0, ',', '.') ?>
                                            </span>
                                            <button class="btn btn-sm btn-outline-info rounded-pill" data-bs-toggle="modal" data-bs-target="#detailModal<?= $prod['id'] ?>" title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>

                                        <!-- REQUEST SAMPLE BUTTON (Sourcing Only) -->
                                        <?php if($_SESSION['role'] == 'sourcing'): ?>
                                            <button type="button" 
                                                    class="btn btn-sm btn-primary w-100 fw-bold" 
                                                    data-bs-toggle="modal" 
                                                    data-bs-target="#requestModal<?= $prod['id'] ?>">
                                                Request Sample
                                            </button>

                                            <!-- REQUEST MODAL -->
                                            <div class="modal fade" id="requestModal<?= $prod['id'] ?>" tabindex="-1">
                                                <div class="modal-dialog">
                                                    <div class="modal-content">
                                                        <div class="modal-header bg-primary text-white">
                                                            <h5 class="modal-title">Request Sample</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <form action="request_sample_action.php" method="POST">
                                                            <div class="modal-body">
                                                                <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
                                                                <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                                                                <p>Requesting sample for: <strong><?= htmlspecialchars($prod['item_name'] ?? '') ?></strong></p>
                                                                <div class="mb-3">
                                                                    <label class="form-label small fw-bold">Instructions / Quantity</label>
                                                                    <textarea name="request_note" class="form-control" rows="3" required></textarea>
                                                                </div>
                                                            </div>
                                                            <div class="modal-footer">
                                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                                                <button type="submit" name="submit_request" class="btn btn-primary">Send Request</button>
                                                            </div>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PRODUCT DETAIL MODAL (Overlay) -->
                <div class="modal fade" id="detailModal<?= $prod['id'] ?>" tabindex="-1" aria-labelledby="detailModalLabel<?= $prod['id'] ?>" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-light">
                                <h5 class="modal-title fw-bold" id="detailModalLabel<?= $prod['id'] ?>">Product Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="row">
                                    <!-- Full Image -->
                                    <div class="col-md-5 mb-3 mb-md-0 text-center bg-light rounded d-flex align-items-center justify-content-center" style="min-height: 250px;">
                                        <?php if(!empty($img_src)): ?>
                                            <img src="<?= htmlspecialchars($img_src ?? '') ?>" class="img-fluid rounded shadow-sm" style="max-height: 300px;" alt="Product Image">
                                        <?php else: ?>
                                            <div class="text-muted"><i class="fas fa-box fa-5x opacity-25"></i><br>No Image Available</div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Full Info -->
                                    <div class="col-md-7">
                                        <h4 class="fw-bold text-dark mb-1"><?= htmlspecialchars($prod['item_name'] ?? '') ?></h4>
                                        <div class="mb-3">
                                            <span class="badge bg-secondary"><?= htmlspecialchars($prod['brand'] ?? '') ?></span>
                                            <span class="badge bg-info text-dark"><?= htmlspecialchars($prod['category'] ?? '') ?></span>
                                            <?php if($prod['sub_category']): ?>
                                                <span class="badge bg-light text-dark border"><?= htmlspecialchars($prod['sub_category'] ?? '') ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <h5 class="text-success fw-bold mb-3">
                                            Rp <?= number_format($prod['price_idr'], 0, ',', '.') ?> 
                                            <span class="text-muted fs-6 fw-normal">/ <?= htmlspecialchars($prod['uom'] ?? '') ?></span>
                                        </h5>

                                        <div class="mb-3">
                                            <strong class="text-muted small text-uppercase">Description / Specs</strong>
                                            <p class="mt-1 bg-light p-3 rounded border">
                                                <?= nl2br(htmlspecialchars($prod['description'] ?? '')) ?>
                                            </p>
                                        </div>

                                        <div class="row g-2 mb-3">
                                            <div class="col-6">
                                                <div class="border rounded p-2 text-center">
                                                    <small class="text-muted d-block">Minimum Order (MOQ)</small>
                                                    <strong class="text-dark"><?= $prod['moq'] ?> <?= htmlspecialchars($prod['uom'] ?? '') ?></strong>
                                                </div>
                                            </div>
                                            <div class="col-6">
                                                <div class="border rounded p-2 text-center">
                                                    <small class="text-muted d-block">Additional Info</small>
                                                    <strong class="text-dark"><?= !empty($prod['add_info']) ? ucfirst($prod['add_info']) : '-' ?></strong>
                                                </div>
                                            </div>
                                            <?php if(!empty($prod['commodity'])): ?>
                                            <div class="col-12">
                                                <div class="border rounded p-2 text-center">
                                                    <small class="text-muted d-block">Commodity Type</small>
                                                    <strong class="text-dark"><?= htmlspecialchars($prod['commodity'] ?? '') ?></strong>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>

                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer bg-light">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                <?php if($_SESSION['role'] == 'sourcing'): ?>
                                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal<?= $prod['id'] ?>" data-bs-dismiss="modal">
                                        Request Sample
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- End Detail Modal -->

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