<?php
// verify_email_change.php
// Confirms a Contact Person's self-service email change. The link is emailed to
// the NEW address; clicking it proves the CP controls that inbox, then the login
// email is switched over. See modules/vendor/contacts_action.php (change_email).

require 'config.php';
require_once __DIR__ . '/includes/vendor_context.php';

$token = isset($_GET['token']) ? preg_replace('/[^a-f0-9]/i', '', (string) $_GET['token']) : '';
if ($token === '') {
    header("Location: login.php?error=" . urlencode("Tautan konfirmasi tidak valid."));
    exit();
}

$stmt = $conn->prepare(
    "SELECT id, email, pending_email, email_change_expires, vendor_id
       FROM users WHERE email_change_token = ? LIMIT 1"
);
$stmt->bind_param("s", $token);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || empty($row['pending_email'])) {
    header("Location: login.php?error=" . urlencode("Tautan konfirmasi tidak valid atau sudah digunakan."));
    exit();
}
if (strtotime($row['email_change_expires']) < time()) {
    header("Location: login.php?error=" . urlencode("Tautan konfirmasi sudah kedaluwarsa. Silakan ajukan perubahan email kembali."));
    exit();
}

// Guard against the address being claimed by someone else after the request.
$dup = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1");
$dup->bind_param("si", $row['pending_email'], $row['id']);
$dup->execute();
if ($dup->get_result()->fetch_assoc()) {
    header("Location: login.php?error=" . urlencode("Email tersebut kini sudah digunakan akun lain. Perubahan dibatalkan."));
    exit();
}

$old_email = $row['email'];
$new_email = $row['pending_email'];

$conn->begin_transaction();
try {
    $upd = $conn->prepare(
        "UPDATE users
            SET email = ?, pending_email = NULL, email_change_token = NULL,
                email_change_expires = NULL, verified_at = NOW()
          WHERE id = ?"
    );
    $upd->bind_param("si", $new_email, $row['id']);
    $upd->execute();

    // If this CP is the primary, keep the vendor's contact email in sync.
    // (Matches only when this user owns vendors.user_id, i.e. is primary.)
    $vu = $conn->prepare("UPDATE vendors SET email = ? WHERE user_id = ?");
    $vu->bind_param("si", $new_email, $row['id']);
    $vu->execute();

    $conn->commit();
} catch (mysqli_sql_exception $e) {
    $conn->rollback();
    header("Location: login.php?error=" . urlencode("Terjadi kesalahan saat memperbarui email. Silakan coba lagi."));
    exit();
}

if (!empty($row['vendor_id'])) {
    // Reconstruct minimal session context for the audit actor label.
    vca_log($conn, (int) $row['vendor_id'], 'email_changed', [
        'target_user_id' => (int) $row['id'],
        'target_email'   => $new_email,
        'details'        => 'Dari ' . $old_email,
    ]);
}

header("Location: login.php?msg=" . urlencode("Email berhasil diperbarui. Silakan masuk menggunakan email baru Anda: {$new_email}."));
exit();
