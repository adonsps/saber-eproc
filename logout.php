<?php
// logout.php

// Baca ?redirect= (dari timer inaktivitas di includes/footer.php) SEBELUM sesi
// dihancurkan, lalu teruskan ke login.php lewat query — session_destroy() di
// bawah menghapus apa pun yang tersimpan di sesi.
require_once __DIR__ . '/includes/auth_intended.php';
$back = auth_sanitize_intended($_GET['redirect'] ?? null);

// Initialize the session
session_start();

// Unset all of the session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
// Menghapus cookie untuk cakupan host-only DAN domain induk sekaligus; lihat
// catatan di includes/auth_intended.php.
auth_clear_session_cookie();

// Finally, destroy the session.
session_destroy();

// Redirect to the login page
$reason = $_GET['reason'] ?? '';
if ($reason === 'timeout') {
    $mins = (int) round(VSS_IDLE_LIMIT_SECONDS / 60);
    $msg = urlencode("Sesi Anda telah berakhir karena tidak ada aktivitas selama {$mins} menit. Silakan login kembali.");
    header("Location: login.php?error=" . $msg . ($back !== null ? "&redirect=" . rawurlencode($back) : ""));
} else {
    header("Location: login.php?msg=" . urlencode("You have been successfully logged out."));
}
exit();
?>