<?php
// resend_verification.php
//
// Kirim ulang email verifikasi untuk akun yang baru saja didaftarkan.
// Alamat tujuan diambil dari session yang ditulis register_action.php, bukan
// dari input — endpoint ini tidak bisa dipakai untuk menguji alamat mana yang
// terdaftar di sistem.
session_start();
require 'config.php';
require_once 'includes/verification_email.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header("Location: register.php");
    exit();
}

$pending_email   = (string) ($_SESSION['pending_verification']['email'] ?? '');
$pending_name    = (string) ($_SESSION['pending_verification']['name'] ?? '');
$pending_company = (string) ($_SESSION['pending_verification']['company'] ?? '');

if ($pending_email === '') {
    header("Location: register.php?error=" . urlencode("Sesi pendaftaran sudah berakhir. Silakan masuk (login) untuk melanjutkan, atau daftar ulang bila akun Anda belum terbentuk."));
    exit();
}

$stmt = $conn->prepare("SELECT is_active, verification_token, verification_sent_at FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $pending_email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    unset($_SESSION['pending_verification']);
    header("Location: register.php?error=" . urlencode("Akun tidak ditemukan. Silakan daftar ulang."));
    exit();
}

// Sudah diverifikasi lewat email sebelumnya — tidak ada yang perlu dikirim.
if ((int) $user['is_active'] === 1 || empty($user['verification_token'])) {
    unset($_SESSION['pending_verification']);
    header("Location: login.php?msg=" . urlencode("Akun Anda sudah terverifikasi. Silakan masuk (login)."));
    exit();
}

// Throttle: satu email per VERIFICATION_RESEND_COOLDOWN_SEC detik, supaya
// klik berulang tidak membanjiri inbox vendor.
$wait = vendor_verification_resend_wait($user['verification_sent_at'] ?? null);
if ($wait > 0) {
    header("Location: register.php?success=1&resend_error=" . urlencode("Email verifikasi baru saja dikirim. Mohon tunggu {$wait} detik lagi sebelum mengirim ulang."));
    exit();
}

// Token lama sengaja dipakai ulang: membuat token baru akan mematikan tautan
// di email yang sudah terkirim, sehingga vendor yang mengklik email lama
// justru mendapat "Invalid or expired verification link".
$sent = send_vendor_verification_email($pending_email, $pending_name, (string) $user['verification_token'], $pending_company, 'Kirim ulang verifikasi');

if (!$sent) {
    header("Location: register.php?success=1&resend_error=" . urlencode("Email verifikasi gagal dikirim. Silakan coba lagi beberapa saat lagi."));
    exit();
}

stamp_verification_sent($conn, $pending_email);
header("Location: register.php?success=1&resent=1");
exit();
