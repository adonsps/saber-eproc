<?php
// modules/vendor/product_action.php
session_start();
require '../../config.php';
require_once '../../includes/upload_paths.php';
require_once '../../includes/vendor_context.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    die("Access Denied");
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php — product uploads are out of scope for it.
vendor_staff_edit_restrict(fn() => die("Access Denied"));

/**
 * Product images live in the vendor's own folder, under products/.
 * Path resolution is delegated to includes/upload_paths.php — the previous
 * "<root>/../uploads/" spelling resolved to /var/www in the container, which
 * www-data cannot write, so every product image was silently discarded.
 *
 * @return string|null Absolute path (with trailing slash), or null when no
 *                     writable uploads base exists.
 */
function getProductUploadDir(int $vendor_id, string $company_name): ?string {
    $vendor_dir = vendor_upload_dir_for($vendor_id, $company_name);
    if ($vendor_dir === null) {
        return null;
    }
    $target = $vendor_dir . "/products";
    if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
        return null;
    }
    return $target . "/";
}

// 1. HANDLE NEW PRODUCT REGISTRATION
if (isset($_POST['save_product'])) {
    
    $user_id = $_SESSION['user_id'];
    $session_vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company
    $v_q = $conn->query("SELECT id, company_name FROM vendors WHERE id = $session_vendor_id");
    $v_data = $v_q->fetch_assoc();
    $vendor_id = $v_data['id'];
    $company_name = $v_data['company_name'];

    $item_name = mysqli_real_escape_string($conn, $_POST['item_name']);
    $brand = mysqli_real_escape_string($conn, $_POST['brand']);
    $category = isset($_POST['category']) ? mysqli_real_escape_string($conn, $_POST['category']) : '';
    $sub_category = isset($_POST['sub_category']) ? mysqli_real_escape_string($conn, $_POST['sub_category']) : '';
    $commodity = isset($_POST['commodity']) ? mysqli_real_escape_string($conn, $_POST['commodity']) : '';
    $add_info = isset($_POST['add_info']) ? mysqli_real_escape_string($conn, $_POST['add_info']) : '';
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $moq = intval($_POST['moq']);
    $price_idr = floatval($_POST['price_idr']);
    $uom = mysqli_real_escape_string($conn, $_POST['uom']);

    // Image Upload Logic (Absolute Path Fix)
    $image_path_db = NULL;
    $image_failed  = false;

    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $filename = $_FILES['product_image']['name'];
        $tmp_name = $_FILES['product_image']['tmp_name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $target_dir_abs = getProductUploadDir((int)$vendor_id, $company_name);
            $new_filename   = "PROD_" . time() . "." . $ext;

            if ($target_dir_abs !== null && @move_uploaded_file($tmp_name, $target_dir_abs . $new_filename)) {
                // Store relative path: vendor_folder/products/filename
                $vendor_folder_name = basename(dirname(rtrim($target_dir_abs, '/')));
                $image_path_db = $vendor_folder_name . "/products/" . $new_filename;
            } else {
                $image_failed = true;
                error_log("product_action.php: product image not stored — vendor_id={$vendor_id}, file={$filename}");
            }
        } else {
            $image_failed = true;
        }
    }

    $sql = "INSERT INTO vendor_products (
                vendor_id, item_name, brand, 
                category, sub_category, commodity, add_info,
                description, moq, uom, price_idr, product_image, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("isssssssisds", 
        $vendor_id, $item_name, $brand, $category, $sub_category, $commodity, $add_info, 
        $description, $moq, $uom, $price_idr, $image_path_db
    );

    if ($stmt->execute()) {
        $msg = $image_failed
            ? "Produk tersimpan, namun GAMBAR GAGAL diunggah. Silakan ubah produk dan unggah ulang gambarnya."
            : "Product Registered Successfully";
        header("Location: dashboard.php?msg=" . urlencode($msg));
    } else {
        echo "Error: " . $conn->error;
    }
}

// 2. HANDLE PRODUCT UPDATE
if (isset($_POST['update_product'])) {
    $prod_id = intval($_POST['product_id']);
    $item_name = mysqli_real_escape_string($conn, $_POST['item_name']);
    $brand = mysqli_real_escape_string($conn, $_POST['brand']);
    $description = mysqli_real_escape_string($conn, $_POST['description']);
    $moq = intval($_POST['moq']);
    $price_idr = floatval($_POST['price_idr']);
    
    // Check ownership
    $user_id = $_SESSION['user_id'];
    $session_vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company
    $v_check = $conn->query("SELECT id, company_name FROM vendors WHERE id=$session_vendor_id")->fetch_assoc();
    $vendor_id = $v_check['id'];
    $company_name = $v_check['company_name'];

    // Validate product belongs to vendor
    $own_check = $conn->query("SELECT id, product_image FROM vendor_products WHERE id=$prod_id AND vendor_id=$vendor_id");
    if($own_check->num_rows == 0) die("Access Denied");
    $existing_prod = $own_check->fetch_assoc();

    // --- HANDLE IMAGE UPDATE ---
    $new_image_path = null;
    $image_failed   = false;

    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $filename = $_FILES['product_image']['name'];
        $tmp_name = $_FILES['product_image']['tmp_name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (in_array($ext, $allowed)) {
            $target_dir_abs = getProductUploadDir((int)$vendor_id, $company_name);
            $new_filename   = "PROD_" . time() . "." . $ext;

            if ($target_dir_abs !== null && @move_uploaded_file($tmp_name, $target_dir_abs . $new_filename)) {
                $vendor_folder_name = basename(dirname(rtrim($target_dir_abs, '/')));
                $new_image_path = $vendor_folder_name . "/products/" . $new_filename;

                // Delete old image if exists (and isn't the same as new). Resolved
                // through the shared helper so an image stored under the old base
                // is still found and cleaned up rather than orphaned.
                if ($existing_prod['product_image'] && $existing_prod['product_image'] != $new_image_path) {
                    $old_file = vendor_upload_resolve($existing_prod['product_image']);
                    if ($old_file) unlink($old_file);
                }
            } else {
                $image_failed = true;
                error_log("product_action.php: product image not replaced — vendor_id={$vendor_id}, prod_id={$prod_id}, file={$filename}");
            }
        } else {
            $image_failed = true;
        }
    }

    // Prepare Update Query
    // Note: We are updating the 'brand' column as requested previously
    $sql = "UPDATE vendor_products SET 
            item_name = ?, 
            brand = ?, 
            description = ?, 
            moq = ?, 
            price_idr = ?";
    
    // Append image update if a new one was uploaded
    if ($new_image_path) {
        $sql .= ", product_image = ?";
    }
    
    $sql .= " WHERE id = ?";
            
    $stmt = $conn->prepare($sql);
    
    if ($new_image_path) {
        // sssidsi
        $stmt->bind_param("sssidsi", $item_name, $brand, $description, $moq, $price_idr, $new_image_path, $prod_id);
    } else {
        // sssidi
        $stmt->bind_param("sssidi", $item_name, $brand, $description, $moq, $price_idr, $prod_id);
    }

    if ($stmt->execute()) {
        $msg = $image_failed
            ? "Produk diperbarui, namun GAMBAR GAGAL diunggah. Silakan coba unggah ulang gambarnya."
            : "Product Updated Successfully";
        header("Location: dashboard.php?msg=" . urlencode($msg));
    } else {
        echo "Error: " . $conn->error;
    }
}
?>