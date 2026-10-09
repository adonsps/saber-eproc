<?php
// modules/sourcing/upload_profile_file.php
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

if (!isset($_POST['vendor_id']) || !isset($_POST['doc_type']) || !isset($_FILES['file'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing required parameters']);
    exit;
}

$vendor_id = intval($_POST['vendor_id']);
$doc_type = trim($_POST['doc_type']);
$file = $_FILES['file'];

// Validate vendor exists & get company name
$stmt = $conn->prepare("SELECT id, company_name FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor_result = $stmt->get_result()->fetch_assoc();
if (!$vendor_result) {
    echo json_encode(['status' => 'error', 'message' => 'Vendor not found']);
    exit;
}

$company_name = $vendor_result['company_name'];

// File validation
$allowed_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
$allowed_mime_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'image/jpeg',
    'image/png',
    'application/octet-stream', // fallback for some browsers
];
$max_size = 30 * 1024 * 1024; // 30MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'Upload error code: ' . $file['error']]);
    exit;
}

if ($file['size'] > $max_size) {
    echo json_encode(['status' => 'error', 'message' => 'File size exceeds 30MB limit']);
    exit;
}

// Validate by extension (more reliable than MIME from browser)
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if (!in_array($ext, $allowed_extensions)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid file type. Allowed: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG']);
    exit;
}

// Double-check MIME via finfo if available
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $real_mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($real_mime, $allowed_mime_types)) {
        echo json_encode(['status' => 'error', 'message' => 'File content does not match allowed types']);
        exit;
    }
}

// Resolve the vendor's uploads directory (vendor_{id}_{company_name}) through
// the shared helper: reuse an existing folder wherever it lives, otherwise
// create one under a base that is actually writable. The old
// "$path_to_root . '../uploads/'" resolved to /var/www in the container, which
// www-data cannot write — this endpoint at least failed loudly, unlike the
// vendor-facing ones. See includes/upload_paths.php.
$vendor_dir = vendor_upload_dir_for($vendor_id, $company_name);
if ($vendor_dir === null) {
    error_log("upload_profile_file.php: no writable uploads base; vendor_id={$vendor_id}");
    echo json_encode(['status' => 'error', 'message' => 'Uploads folder is not writable on the server']);
    exit;
}
$upload_dir = $vendor_dir . '/';

// Generate unique filename ($ext already set above)
$timestamp = date('YmdHis');
$random = rand(1000, 9999);
$filename = "profile_{$timestamp}_{$random}." . strtolower($ext);
$filepath = $upload_dir . $filename;

// Move uploaded file
if (!@move_uploaded_file($file['tmp_name'], $filepath)) {
    error_log("upload_profile_file.php: move_uploaded_file failed — vendor_id={$vendor_id}, target={$filepath}");
    echo json_encode(['status' => 'error', 'message' => 'Failed to save file']);
    exit;
}

// Update database - store relative path from uploads directory
$db_filename = vendor_upload_relative_path($vendor_dir, $filename);
$update_stmt = $conn->prepare("UPDATE vendors SET company_profile_file = ? WHERE id = ?");
$update_stmt->bind_param("si", $db_filename, $vendor_id);

if ($update_stmt->execute()) {
    // Insert audit log
    $current_user_id = $_SESSION['user_id'];
    $action_detail = "Profile Uploaded: Profil Perusahaan (" . $file['name'] . ") berhasil diunggah";
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
        'message' => 'File uploaded successfully',
        'file_path' => $db_filename
    ]);
} else {
    // Delete the uploaded file if DB update fails
    unlink($filepath);
    echo json_encode(['status' => 'error', 'message' => 'Failed to update database']);
}

$update_stmt->close();
$stmt->close();
$conn->close();
?>
