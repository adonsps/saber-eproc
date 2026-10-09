<?php
// modules/sourcing/direct_registration_resume.php
// Re-enters a vendor account previously created via Direct Registration, so
// Sourcing can finish profiling/PQ they left mid-flow (that vendor has no
// usable password — direct_registration_action.php generates a random one
// that's never shared with anyone).
session_start();
require '../../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

$vendor_id = intval($_GET['vendor_id'] ?? 0);
$sourcing_user_id = $_SESSION['user_id'];

$stmt = $conn->prepare("SELECT v.id, v.user_id, v.company_name, v.registration_status, u.email, u.full_name
                         FROM vendors v JOIN users u ON u.id = v.user_id
                         WHERE v.id = ? AND v.registered_by_sourcing_id = ?");
$stmt->bind_param("ii", $vendor_id, $sourcing_user_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

// Only resumable if it's a direct-registration vendor created by THIS sourcing
// user, and it hasn't already been handed to VM (or beyond).
// Anything before the PQ is actually submitted to VM — profile_action.php
// promotes registration_status through several of these (e.g.
// registration_submitted -> invited_pq once docs are complete) while the
// vendor still hasn't reached submit_pq.php, so all of them must stay
// resumable, not just the very first one.
$resumable_statuses = ['registration_submitted', 'registered', 'min_info_submitted', 'invited_pq', 'draft', 'revision_requested', 'returned_to_sourcing'];
if (!$vendor || !in_array($vendor['registration_status'], $resumable_statuses, true)) {
    header("Location: direct_registration.php?error=" . urlencode("Pendaftaran ini sudah tidak dapat dilanjutkan."));
    exit();
}

$_SESSION['impersonator'] = [
    'user_id'   => $sourcing_user_id,
    'role'      => 'sourcing',
    'user_name' => $_SESSION['user_name'] ?? 'Sourcing Team',
    'email'     => $_SESSION['email'] ?? '',
];

$_SESSION['user_id']       = $vendor['user_id'];
$_SESSION['role']          = 'vendor';
$_SESSION['email']         = $vendor['email'];
$_SESSION['user_name']     = $vendor['full_name'];
$_SESSION['last_activity'] = time();
$_SESSION['vendor_id']     = $vendor['id'];
$_SESSION['company_name']  = $vendor['company_name'];
$_SESSION['status']        = $vendor['registration_status'];

header("Location: ../vendor/dashboard.php");
exit();
