<?php
// modules/sourcing/request_sample_action.php
session_start();
require '../../config.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

if (isset($_POST['submit_request'])) {
    $vendor_id = intval($_POST['vendor_id']);
    $product_id = intval($_POST['product_id']);
    $user_id = $_SESSION['user_id'];
    $note = mysqli_real_escape_string($conn, $_POST['request_note']);

    // Insert Request
    $stmt = $conn->prepare("INSERT INTO product_sample_requests (vendor_id, product_id, requested_by, request_note, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
    $stmt->bind_param("iiis", $vendor_id, $product_id, $user_id, $note);

    if ($stmt->execute()) {
        header("Location: view_products.php?vendor_id=$vendor_id&msg=Sample Request Sent Successfully");
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    header("Location: dashboard.php");
}
?>