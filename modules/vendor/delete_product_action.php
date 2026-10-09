<?php
// modules/vendor/delete_product_action.php
session_start();
$path_to_root = "../../";
require '../../config.php';
require_once '../../includes/vendor_context.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    die("Access Denied");
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php — deleting catalog data is out of scope for it.
vendor_staff_edit_restrict(fn() => die("Access Denied"));

$vendor_id = current_vendor_id($conn);

// 1b. Least-privilege: deleting catalog data is a destructive action, so it is
//     restricted to the PRIMARY contact. Members may add/edit but not delete.
if (!vendor_is_primary()) {
    header("Location: dashboard.php?msg=" . urlencode("Hanya narahubung utama (primary) yang dapat menghapus produk."));
    exit();
}

if (isset($_GET['id']) && $vendor_id) {
    $prod_id = intval($_GET['id']);

    // 2. Verify the product belongs to THIS vendor company (multi-CP safe).
    $stmt = $conn->prepare("SELECT id, item_name FROM vendor_products WHERE id = ? AND vendor_id = ?");
    $stmt->bind_param("ii", $prod_id, $vendor_id);
    $stmt->execute();
    $prod = $stmt->get_result()->fetch_assoc();

    if ($prod) {
        // 3. Soft Delete (mark inactive; file is kept intentionally).
        $upd_stmt = $conn->prepare("UPDATE vendor_products SET is_active = 0 WHERE id = ?");
        $upd_stmt->bind_param("i", $prod_id);

        if ($upd_stmt->execute()) {
            vca_log($conn, $vendor_id, 'product_deleted', [
                'details' => 'Produk #' . $prod_id . ' (' . ($prod['item_name'] ?? '') . ') dinonaktifkan dari katalog.',
            ]);
            header("Location: dashboard.php?msg=Product has been removed from your active catalog.");
        } else {
            header("Location: dashboard.php?msg=Error: System encountered a database issue.");
        }
    } else {
        header("Location: dashboard.php?msg=Error: Product not found or access denied.");
    }
} else {
    header("Location: dashboard.php");
}
?>
