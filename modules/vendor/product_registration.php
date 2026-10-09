<?php
// modules/vendor/product_registration.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever be on profile.php.
vendor_staff_edit_restrict();

// --- FETCH TAXONOMY HIERARCHY ---
$taxonomy_tree = [];
$sql = "SELECT DISTINCT category, sub_category, commodity FROM taxonomy 
        WHERE category IS NOT NULL AND category != '' 
        ORDER BY category, sub_category, commodity";
$result = $conn->query($sql);
if ($result) {
    while($row = $result->fetch_assoc()) {
        $cat = $row['category'];
        $sub = $row['sub_category'];
        $com = $row['commodity'];
        if (!isset($taxonomy_tree[$cat])) $taxonomy_tree[$cat] = [];
        if (!empty($sub)) {
            if (!isset($taxonomy_tree[$cat][$sub])) $taxonomy_tree[$cat][$sub] = [];
            if (!empty($com)) $taxonomy_tree[$cat][$sub][] = $com;
        }
    }
}
$taxonomy_json = json_encode($taxonomy_tree);
$add_info_options = ['size', 'color', 'type', 'weight', 'flavour', 'other'];

// --- EDIT MODE LOGIC ---
$is_edit = false;
$prod = [];
$prod_id = "";

if (isset($_GET['edit_id'])) {
    $prod_id = intval($_GET['edit_id']);
    $user_id = $_SESSION['user_id'];
    
    // Verify ownership
    $check_sql = "SELECT p.* FROM vendor_products p 
                  JOIN vendors v ON p.vendor_id = v.id 
                  WHERE p.id = ? AND v.user_id = ?";
    $stmt = $conn->prepare($check_sql);
    $stmt->bind_param("ii", $prod_id, $user_id);
    $stmt->execute();
    $res = $stmt->get_result();
    
    if ($res->num_rows > 0) {
        $prod = $res->fetch_assoc();
        $is_edit = true;
    } else {
        die("Product not found or access denied.");
    }
}

$page_title = $is_edit ? "Ubah Produk" : "Pendaftaran Produk";
include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?><?= $is_edit ? "Ubah Produk" : "Pendaftaran Produk" ?>
        </span>
        <a href="dashboard.php" class="btn btn-sm btn-nav"><i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
    </div>
</nav>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-lg-8">
            
            <div class="card shadow">
                <div class="card-header bg-white py-3">
                    <h5 class="mb-0 fw-bold text-primary">Detail Barang</h5>
                </div>
                <div class="card-body p-4">
                    
                    <form action="product_action.php" method="POST" enctype="multipart/form-data">
                        
                        <?php if ($is_edit): ?>
                            <input type="hidden" name="update_product" value="1">
                            <input type="hidden" name="product_id" value="<?= $prod['id'] ?>">
                        <?php endif; ?>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Nama Barang <span class="text-danger">*</span></label>
                                <input type="text" name="item_name" class="form-control" placeholder="mis. Safety Helmet Tipe X" 
                                       value="<?= $is_edit ? htmlspecialchars($prod['item_name']) : '' ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small text-uppercase text-muted">Merek <span class="text-danger">*</span></label>
                                <input type="text" name="brand" class="form-control" placeholder="mis. KRISBOW" 
                                       value="<?= $is_edit ? htmlspecialchars($prod['brand']) : '' ?>" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Kategori <span class="text-danger">*</span></label>
                                <select name="category" id="category" class="form-select" <?= $is_edit ? 'disabled style="background-color: #e9ecef;"' : 'required' ?>>
                                    <option value="">Pilih Kategori...</option>
                                    <?php foreach(array_keys($taxonomy_tree) as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>" <?= ($is_edit && $prod['category'] == $cat) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($is_edit): ?>
                                    <input type="hidden" name="category" value="<?= htmlspecialchars($prod['category']) ?>">
                                <?php endif; ?>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Sub Kategori</label>
                                <select name="sub_category" id="sub_category" class="form-select" <?= $is_edit ? 'disabled style="background-color: #e9ecef;"' : 'disabled' ?>>
                                    <?php if ($is_edit && !empty($prod['sub_category'])): ?>
                                        <option value="<?= htmlspecialchars($prod['sub_category']) ?>" selected><?= htmlspecialchars($prod['sub_category']) ?></option>
                                    <?php else: ?>
                                        <option value="">Pilih Sebelumnya...</option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Commodity</label>
                                <select name="commodity" id="commodity" class="form-select" <?= $is_edit ? 'disabled style="background-color: #e9ecef;"' : 'disabled' ?>>
                                    <?php if ($is_edit && !empty($prod['commodity'])): ?>
                                        <option value="<?= htmlspecialchars($prod['commodity']) ?>" selected><?= htmlspecialchars($prod['commodity']) ?></option>
                                    <?php else: ?>
                                        <option value="">Pilih Sebelumnya...</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>
                        <?php if($is_edit): ?>
                            <div class="mb-3 text-muted small fst-italic">
                                <i class="fas fa-info-circle me-1"></i> Kategorisasi produk tidak dapat diubah setelah pendaftaran.
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-bold small text-uppercase text-muted">Spesifikasi / Deskripsi <span class="text-danger">*</span></label>
                            <textarea name="description" class="form-control" rows="3" required><?= $is_edit ? htmlspecialchars($prod['description']) : '' ?></textarea>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Harga Satuan (Rp) <span class="text-danger">*</span></label>
                                <div class="input-group">
                                    <span class="input-group-text">Rp</span>
                                    <input type="number" name="price_idr" class="form-control" placeholder="0" min="0" 
                                           value="<?= $is_edit ? $prod['price_idr'] : '' ?>" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Satuan Ukur (UoM) <span class="text-danger">*</span></label>
                                <select name="uom" class="form-select" required>
                                    <?php 
                                    $uoms = ['PCS', 'UNIT', 'SET', 'KG', 'METER', 'BOX', 'LITER', 'PACK', 'ROLL'];
                                    foreach($uoms as $u) {
                                        $sel = ($is_edit && $prod['uom'] == $u) ? 'selected' : '';
                                        echo "<option value='$u' $sel>$u</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                             <div class="col-md-4">
                                <label class="form-label fw-bold small text-uppercase text-muted">Minimum Order (MOQ) <span class="text-danger">*</span></label>
                                <input type="number" name="moq" class="form-control" min="1" 
                                       value="<?= $is_edit ? $prod['moq'] : '1' ?>" required>
                            </div>
                        </div>

                        <div class="col-md-12">
                                <label class="form-label fw-bold small text-uppercase text-muted">Foto Produk <?= $is_edit ? '' : '<span class="text-danger">*</span>' ?></label>
                                <div class="input-group">
                                    <input type="file" name="product_image" class="form-control" accept="image/*" <?= $is_edit ? '' : 'required' ?>>
                                    <label class="input-group-text"><i class="fas fa-camera"></i></label>
                                </div>
                                <?php if ($is_edit && !empty($prod['product_image'])): ?>
                                    <div class="mt-2 d-flex align-items-center">
                                        <img src="<?= $path_to_root ?>../uploads/products/<?= htmlspecialchars($prod['product_image']) ?>" 
                                             class="rounded border me-2" style="width: 40px; height: 40px; object-fit: cover;"
                                             onerror="this.src='https://via.placeholder.com/40'">
                                        <small class="text-muted">Gambar saat ini (Unggah baru untuk mengganti)</small>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="dashboard.php" class="btn btn-light border">Batal</a>
                            <button type="submit" name="<?= $is_edit ? 'update_product' : 'save_product' ?>" class="btn btn-primary px-4 shadow-sm">
                                <i class="fas fa-save me-2"></i> <?= $is_edit ? "Perbarui Produk" : "Simpan Produk" ?>
                            </button>
                        </div>

                    </form>

                </div>
            </div>

        </div>
    </div>
</div>

<?php if (!$is_edit): ?>
<script>
    const taxonomy = <?= $taxonomy_json ?: '{}' ?>;
    const catSelect = document.getElementById('category');
    const subSelect = document.getElementById('sub_category');
    const comSelect = document.getElementById('commodity');

    function populateSub(cat) {
        subSelect.innerHTML = '<option value="">Pilih Sub Kategori...</option>';
        comSelect.innerHTML = '<option value="">Pilih Commodity...</option>';
        subSelect.disabled = true;
        comSelect.disabled = true;

        if (cat && taxonomy[cat]) {
            const subs = Object.keys(taxonomy[cat]);
            if (subs.length > 0) {
                subSelect.disabled = false;
                subs.forEach(sub => {
                    const opt = document.createElement('option');
                    opt.value = sub;
                    opt.textContent = sub;
                    subSelect.appendChild(opt);
                });
            }
        }
    }

    function populateCom(cat, sub) {
        comSelect.innerHTML = '<option value="">Pilih Commodity...</option>';
        comSelect.disabled = true;

        if (cat && sub && taxonomy[cat][sub]) {
            const coms = taxonomy[cat][sub];
            if (coms.length > 0) {
                comSelect.disabled = false;
                coms.forEach(com => {
                    const opt = document.createElement('option');
                    opt.value = com;
                    opt.textContent = com;
                    comSelect.appendChild(opt);
                });
            }
        }
    }

    catSelect.addEventListener('change', function() {
        populateSub(this.value);
    });

    subSelect.addEventListener('change', function() {
        populateCom(catSelect.value, this.value);
    });
</script>
<?php endif; ?>

<?php include $path_to_root . 'includes/footer.php'; ?>