<?php
// modules/sourcing/review_action.php
session_start();
require '../../config.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $sub_id = intval($_POST['submission_id']);
    $vendor_id = intval($_POST['vendor_id']);
    $notes = mysqli_real_escape_string($conn, str_replace(["\r\n", "\r"], "\n", $_POST['sourcing_notes'] ?? ''));
    $action = $_POST['action'];
    $current_user_id = $_SESSION['user_id']; 

    if ($action == 'approve_sourcing') {
        
        // CRITICAL VALIDATION: Ensure Sourcing has classified the vendor first!
        $v_check = $conn->query("SELECT sub_company_type FROM vendors WHERE id = $vendor_id")->fetch_assoc();
        if (empty($v_check['sub_company_type'])) {
            die("<script>alert('ERROR: You must assign a Sub-Category Classification before submitting to VM.'); window.history.back();</script>");
        }

        // 1. Move forward to VM Team
        // We set the status to 'under_review_vm' which hands it over to the first VM staff layer
        $sql = "UPDATE pq_submissions SET status = 'under_review_vm', sourcing_notes = ?, sourcing_reviewer_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $notes, $current_user_id, $sub_id);
        
        // Update Vendor Status to match
        $conn->query("UPDATE vendors SET registration_status = 'under_review_vm' WHERE id = $vendor_id");

        // PQ SLA: open a new 3-working-day leg
        require_once '../../includes/sla_helper.php';
        openPqSlaLeg($conn, $sub_id, $current_user_id);

        $msg = "Verified! Application sent to Vendor Management Team.";
        
    } elseif ($action == 'request_revision') {
        // 2. Send back to Vendor for Revision
        $sql = "UPDATE pq_submissions SET status = 'revision_needed', sourcing_notes = ?, sourcing_reviewer_id = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sii", $notes, $current_user_id, $sub_id);

        // Update Vendor Status to match
        $conn->query("UPDATE vendors SET registration_status = 'revision_requested' WHERE id = $vendor_id");
        
        $msg = "Returned to Vendor for Revision. You are now the assigned reviewer.";
    } else {
        die("Invalid action.");
    }

    if ($stmt->execute()) {
        echo "<script>alert('$msg'); window.location.href = 'dashboard.php';</script>";
    } else {
        echo "Error: " . $conn->error;
    }
} else {
    header("Location: dashboard.php");
}
?>