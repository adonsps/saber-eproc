<?php
// modules/sourcing/reset_pq.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security Check (Sourcing Only)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'sourcing') {
    die("Access Denied");
}

if (isset($_GET['id'])) {
    $vendor_id = intval($_GET['id']);
    
    $conn->begin_transaction();
    try {
        // 2. Delete Previous Submissions
        // (This will automatically delete answers if you set up Foreign Keys with CASCADE, 
        // but let's be safe and delete answers first just in case)
        
        // Find submission IDs first
        $stmt_find = $conn->prepare("SELECT id FROM pq_submissions WHERE vendor_id = ?");
        $stmt_find->bind_param("i", $vendor_id);
        $stmt_find->execute();
        $res = $stmt_find->get_result();
        
        while($row = $res->fetch_assoc()) {
            $sub_id = $row['id'];
            $conn->query("DELETE FROM pq_answers WHERE submission_id = $sub_id");
            $conn->query("DELETE FROM pq_submissions WHERE id = $sub_id");
        }

        // 3. Reset Vendor Status to 'invited_pq'
        // This makes the "Start Assessment" button appear again on their dashboard
        $conn->query("UPDATE vendors SET registration_status = 'invited_pq', admin_notes = NULL WHERE id = $vendor_id");

        $conn->commit();
        
        // 4. Redirect
        header("Location: all_vendors.php?msg=Vendor+Reset+Successfully");
        
    } catch (Exception $e) {
        $conn->rollback();
        echo "Error: " . $e->getMessage();
    }
}
?>