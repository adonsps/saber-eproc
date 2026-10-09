<?php
// pages/logout.php

// Baca ?redirect= (dari timer inaktivitas di includes/footer.php) SEBELUM sesi
// dihancurkan, lalu teruskan lewat query — session_destroy() menghapus sesi.
require_once __DIR__ . '/../../includes/auth_intended.php';
$back = auth_sanitize_intended($_GET['redirect'] ?? null);

session_start();

// Unset all of the session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Menghapus cookie untuk cakupan host-only DAN domain induk sekaligus; lihat
// catatan di includes/auth_intended.php.
auth_clear_session_cookie();

session_destroy();

$reason = $_GET['reason'] ?? '';
if ($reason === 'timeout') {
    // Tanpa angka: batasnya bergantung peran (WS_IDLE_LIMIT_DEFAULT vs
    // _VIEWER) dan sesi sudah dihancurkan di atas, jadi perannya tak lagi
    // terbaca. Kalimatnya disamakan dengan ws_enforce_idle_timeout().
    $msg = urlencode("Sesi Anda berakhir karena tidak ada aktivitas. Silakan masuk kembali.");
    header("Location: login.php?error=" . $msg . ($back !== null ? "&redirect=" . rawurlencode($back) : ""));
} else {
    header("Location: login.php?msg=" . urlencode("You have been successfully logged out."));
}
exit;
?>