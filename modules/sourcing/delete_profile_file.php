<?php
// modules/sourcing/delete_profile_file.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/upload_paths.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
    exit;
}

if (!isset($_POST['vendor_id']) || !isset($_POST['doc_type'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit;
}

$vendor_id = intval($_POST['vendor_id']);
$doc_type = trim($_POST['doc_type']);

// Validate vendor exists & get current file path
$stmt = $conn->prepare("SELECT id, company_profile_file FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor_result = $stmt->get_result()->fetch_assoc();
if (!$vendor_result) {
    echo json_encode(['status' => 'error', 'message' => 'Vendor not found']);
    exit;
}

$current_file = $vendor_result['company_profile_file'];

// Delete file from filesystem if it exists.
// Resolved against both upload bases — the hardcoded '../uploads/' never matched
// under Docker, so this silently deleted nothing and left the file orphaned
// while the DB column was cleared. A file that genuinely no longer exists is not
// an error: the DB row should still be cleared below.
if (!empty($current_file)) {
    $filepath = vendor_upload_resolve($current_file);
    if ($filepath !== null && !unlink($filepath)) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to delete file from server']);
        exit;
    }
}

// Update database - set file path to NULL
$update_stmt = $conn->prepare("UPDATE vendors SET company_profile_file = NULL WHERE id = ?");
$update_stmt->bind_param("i", $vendor_id);

if ($update_stmt->execute()) {
    // Insert audit log
    $current_user_id = $_SESSION['user_id'];
    $deleted_filename = !empty($current_file) ? basename($current_file) : '-';
    $action_detail = "Profile Deleted: Profil Perusahaan (" . $deleted_filename . ") telah dihapus";
    $sub_stmt = $conn->prepare("SELECT id FROM pq_submissions WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
    $sub_stmt->bind_param("i", $vendor_id);
    $sub_stmt->execute();
    $sub_row = $sub_stmt->get_result()->fetch_assoc();
    $sub_stmt->close();
    if ($sub_row) {
        $submission_id = $sub_row['id'];
        $log_stmt = $conn->prepare("INSERT INTO pq_audit_logs (submission_id, question_id, user_id, action_detail) VALUES (?, NULL, ?, ?)");
        $log_stmt->bind_param("iis", $submission_id, $current_user_id, $action_detail);
        $log_stmt->execute();
        $log_stmt->close();
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'File deleted successfully'
    ]);
} else {
    echo json_encode(['status' => 'error', 'message' => 'Failed to update database']);
}

$update_stmt->close();
$stmt->close();
$conn->close();
?>
