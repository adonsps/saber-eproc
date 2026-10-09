<?php
// modules/sourcing/invite_action.php
session_start();
require '../../config.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

if (isset($_GET['vendor_id'])) {
    $vendor_id = intval($_GET['vendor_id']);
    
    // Update Status
    $stmt = $conn->prepare("UPDATE vendors SET registration_status = 'invited_pq' WHERE id = ?");
    $stmt->bind_param("i", $vendor_id);
    
    if ($stmt->execute()) {
        // Optional: You could add PHPMailer here to send an email notification "You are invited!"
        
        header("Location: dashboard.php?msg=Vendor Invited Successfully");
    } else {
        echo "Error: " . $conn->error;
    }
}
?>