<?php
// modules/sourcing/direct_registration_action.php
session_start();
require '../../config.php';
require_once '../../includes/form_draft.php';

// Security Check — only Sourcing can initiate a direct registration / impersonation.
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: direct_registration.php");
    exit();
}

// Every rejection below bounces back to the empty form, so stash what Sourcing
// typed first — one duplicate email must not cost them the whole entry.
$reject_and_keep_draft = function (string $message) {
    form_draft_save(
        'direct_registration',
        form_draft_collect($_POST, ['company_name', 'company_type', 'contact_person', 'email', 'phone', 'legal_entity_type']),
        (int) ($_SESSION['user_id'] ?? 0)
    );
    header("Location: direct_registration.php?error=" . urlencode($message));
    exit();
};

$company_name      = mysqli_real_escape_string($conn, trim($_POST['company_name'] ?? ''));
$company_type      = mysqli_real_escape_string($conn, trim($_POST['company_type'] ?? ''));
$contact_person    = mysqli_real_escape_string($conn, trim($_POST['contact_person'] ?? ''));
$email             = mysqli_real_escape_string($conn, trim($_POST['email'] ?? ''));
$phone_raw         = trim($_POST['phone'] ?? '');
$legal_entity_type = trim($_POST['legal_entity_type'] ?? '');

$clean_phone = preg_replace('/[^0-9]/', '', $phone_raw);

if ($company_name === '' || $contact_person === '' || $email === '') {
    $reject_and_keep_draft("Semua kolom wajib harus diisi.");
}
if (!in_array($company_type, ['Supplier', 'Contractor', 'Perseorangan'], true)) {
    $reject_and_keep_draft("Model bisnis tidak valid.");
}
if (!in_array($legal_entity_type, ['Badan Usaha', 'Perseorangan'], true)) {
    $reject_and_keep_draft("Jenis badan hukum tidak valid.");
}
if (strlen($clean_phone) < 7 || strlen($clean_phone) > 15) {
    $reject_and_keep_draft("Nomor telepon tidak valid.");
}
$phone = mysqli_real_escape_string($conn, $clean_phone);

// Duplicate email check
$check = $conn->query("SELECT id FROM users WHERE email = '$email'");
if ($check->num_rows > 0) {
    $reject_and_keep_draft("Email sudah terdaftar.");
}

$sourcing_user_id = $_SESSION['user_id'];

$conn->begin_transaction();
try {
    // A. Create the vendor's login account. Active immediately — Sourcing has
    // verified the vendor's identity directly, so no email verification loop.
    $random_password = bin2hex(random_bytes(8));
    $hashed = password_hash($random_password, PASSWORD_DEFAULT);

    // contact_role/contact_status mirror register_action.php's multi-CP
    // pattern: this new login is the PRIMARY contact for the vendor being
    // created (see includes/vendor_contacts_schema.php).
    $stmt = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, is_active, contact_role, contact_status) VALUES (?, ?, ?, 'vendor', 1, 'primary', 'active')");
    $stmt->bind_param("sss", $email, $hashed, $contact_person);
    $stmt->execute();
    $user_id = $conn->insert_id;

    // B. Create the vendor profile. sub_company_type is intentionally NOT set
    // here — like the normal flow's review_pq.php, that classification is a
    // judgment call made from the PQ answers, declared later in pq_form.php
    // right before final submission (see modules/vendor/submit_pq.php).
    $stmt_v = $conn->prepare("INSERT INTO vendors
        (user_id, company_name, company_type, contact_person, email, phone, legal_entity_type, registered_by_sourcing_id, registration_status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'registration_submitted', NOW())");
    $stmt_v->bind_param("issssssi", $user_id, $company_name, $company_type, $contact_person, $email, $phone, $legal_entity_type, $sourcing_user_id);
    $stmt_v->execute();
    $vendor_id = $conn->insert_id;

    // C. Link the new login to the vendor company via users.vendor_id — the
    // multi-CP model's primary lookup path (see current_vendor_id() in
    // includes/vendor_context.php). vendors.user_id (set above) still points
    // at this same account too, matching register_action.php's pattern so
    // both the new and legacy resolution paths agree.
    $link = $conn->prepare("UPDATE users SET vendor_id = ? WHERE id = ?");
    $link->bind_param("ii", $vendor_id, $user_id);
    $link->execute();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    $reject_and_keep_draft($e->getMessage());
}

// The account exists now — the recovery draft has served its purpose, and must
// not linger to pre-fill the NEXT vendor Sourcing registers.
form_draft_clear('direct_registration');

// C. Stash the Sourcing session, then swap the active session to the new
// vendor account — the vendor-facing pages below are used completely
// unmodified, just as they would be by the vendor logging in themselves.
$_SESSION['impersonator'] = [
    'user_id'   => $sourcing_user_id,
    'role'      => 'sourcing',
    'user_name' => $_SESSION['user_name'] ?? 'Sourcing Team',
    'email'     => $_SESSION['email'] ?? '',
];

$_SESSION['user_id']       = $user_id;
$_SESSION['role']          = 'vendor';
$_SESSION['email']         = $email;
$_SESSION['user_name']     = $contact_person;
$_SESSION['last_activity'] = time();
$_SESSION['vendor_id']     = $vendor_id;
$_SESSION['company_name']  = $company_name;
$_SESSION['status']        = 'registration_submitted';

// Land on "Informasi Umum" first (the default tab) — Sourcing should fill in
// the vendor's general details before jumping to document uploads, matching
// the natural order a vendor would follow themselves.
header("Location: ../vendor/profile.php");
exit();
