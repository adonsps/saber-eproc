<?php
// modules/shared/edit_vendor_profile.php
//
// Lets Sourcing / Vendor Management edit a vendor profile that the vendor
// themselves can no longer touch, i.e. while the PQ is under review.
//
// Mechanism: the same impersonation the Direct Registration flow already uses
// (modules/sourcing/direct_registration_resume.php) — swap the session to the
// vendor's own account and reuse modules/vendor/profile.php, so every existing
// validation, OCR and draft-recovery rule still applies. What the staff user
// may change is decided by vendor_staff_can_edit_profile() /
// vendor_staff_can_edit_documents() in includes/vendor_context.php:
//
//   sourcing / vendor_mgt / vendormgt_head -> profile fields AND legal documents
//
// Access exists ONLY while the profile is locked (pq_submitted, under_review_vm,
// under_review_vm_head, returned_to_sourcing, rejected). Outside those statuses
// the vendor maintains their own data and there is nothing to override.
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';

$staff_role = $_SESSION['role'] ?? '';
$staff_id   = (int) ($_SESSION['user_id'] ?? 0);

if (!$staff_id || !in_array($staff_role, vendor_staff_editor_roles(), true)) {
    die("Access Denied");
}

// Never stack an impersonation on top of another one — a Direct Registration
// session is already "someone else", and nesting would lose the way back.
if (!empty($_SESSION['impersonator'])) {
    die("Access Denied: sesi impersonasi lain sedang berjalan.");
}

$vendor_id = (int) ($_GET['id'] ?? 0);

// Where to send the staff user when they press "Keluar" in the banner. Only an
// in-app path is accepted, so this can't be turned into an open redirect.
$fallback_return = $staff_role === 'sourcing'
    ? "modules/sourcing/view_profile.php?id={$vendor_id}"
    : "modules/vendormgt/view_profile.php?id={$vendor_id}";
$return_to = (string) ($_GET['return_to'] ?? '');
if ($return_to === '' || preg_match('#^(https?:)?//#i', $return_to) || strpos($return_to, "\n") !== false) {
    $return_to = $fallback_return;
}

$stmt = $conn->prepare(
    "SELECT v.id, v.user_id, v.company_name, v.registration_status, u.email, u.full_name
       FROM vendors v
       JOIN users u ON u.id = v.user_id
      WHERE v.id = ?"
);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    die("Vendor not found (atau vendor ini belum punya akun login utama).");
}

// The whole point of this page is to edit THROUGH the lock. If the profile is
// not locked, the vendor can edit it themselves — send the staff user back
// rather than silently handing them an impersonated session they don't need.
if (!vendor_profile_is_locked($vendor['registration_status'] ?? '')) {
    header("Location: " . $path_to_root . $fallback_return
        . (strpos($fallback_return, '?') === false ? '?' : '&')
        . "error=" . urlencode("Profil vendor ini sedang tidak terkunci — vendor masih dapat memperbaruinya sendiri."));
    exit();
}

$_SESSION['impersonator'] = [
    'user_id'   => $staff_id,
    'role'      => $staff_role,
    'user_name' => $_SESSION['user_name'] ?? 'Tim Procurement',
    'email'     => $_SESSION['email'] ?? '',
    'purpose'   => 'staff_profile_edit',
    'return_to' => $return_to,
];

$_SESSION['user_id']       = $vendor['user_id'];
$_SESSION['role']          = 'vendor';
$_SESSION['email']         = $vendor['email'];
$_SESSION['user_name']     = $vendor['full_name'];
// vendors.user_id is the primary CP by definition; say so explicitly so
// vendor_is_primary() (which gates document deletion) doesn't fall through to
// whatever contact_role the staff session happened to carry.
$_SESSION['contact_role']  = 'primary';
$_SESSION['last_activity'] = time();
$_SESSION['vendor_id']     = $vendor['id'];
$_SESSION['company_name']  = $vendor['company_name'];
$_SESSION['status']        = $vendor['registration_status'];

header("Location: " . $path_to_root . "modules/vendor/profile.php");
exit();
