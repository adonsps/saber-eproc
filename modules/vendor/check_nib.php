<?php
// modules/vendor/check_nib.php
session_start();
require '../../config.php';
require_once '../../includes/vendor_context.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    echo json_encode(['exists' => false]);
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php — the NIB dedup check belongs to product_registration.
if (vendor_staff_edit_session()) {
    echo json_encode(['exists' => false]);
    exit();
}

$nib = $_POST['nib'] ?? '';

if (empty(trim($nib))) {
    echo json_encode(['exists' => false]);
    exit();
}

// 1. vendor_id saat ini (multi-CP: dari sesi, berlaku untuk primary & member CP)
//    agar tidak salah mendeteksi NIB miliknya sendiri.
$vendor_id = (int)($_SESSION['vendor_id'] ?? 0);

// 2. Cek apakah NIB sudah dipakai oleh vendor LAIN
$stmt = $conn->prepare("SELECT id FROM vendors WHERE nib_number = ? AND id != ?");
$stmt->bind_param("si", $nib, $vendor_id);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows > 0) {
    echo json_encode(['exists' => true]);
} else {
    echo json_encode(['exists' => false]);
}
?>