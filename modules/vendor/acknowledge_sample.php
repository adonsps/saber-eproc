<?php
// modules/vendor/acknowledge_sample.php
session_start();
require '../../config.php';
require_once '../../includes/vendor_context.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    die("Access Denied");
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php.
vendor_staff_edit_restrict(fn() => die("Access Denied"));

if (isset($_GET['req_id'])) {
    $req_id = intval($_GET['req_id']);
    $user_id = $_SESSION['user_id'];

    // 2. Verify Vendor Ownership
    // We need to make sure the request belongs to the logged-in vendor
    $vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
    $stmt_v = $conn->prepare("SELECT id FROM vendors WHERE id = ?");
    $stmt_v->bind_param("i", $vendor_id);
    $stmt_v->execute();
    $vendor_res = $stmt_v->get_result();
    
    if ($vendor_res->num_rows == 0) {
        die("Vendor profile not found.");
    }
    $vendor_id = $vendor_res->fetch_assoc()['id'];

    // 3. Update Status to 'sent'
    // This removes it from the "Pending" list on the dashboard
    $stmt_upd = $conn->prepare("UPDATE product_sample_requests SET status = 'sent' WHERE id = ? AND vendor_id = ?");
    $stmt_upd->bind_param("ii", $req_id, $vendor_id);

    if ($stmt_upd->execute()) {
        header("Location: dashboard.php?msg=Sample request acknowledged and marked as Sent.");
    } else {
        echo "Error updating record: " . $conn->error;
    }

} else {
    header("Location: dashboard.php");
}
?>