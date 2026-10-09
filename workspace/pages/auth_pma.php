<?php
// workspace/pages/auth_pma.php
// This script is called by Nginx Proxy Manager's auth_request module.
// It verifies if the user is a logged-in Category Manager.
// If yes, it returns 200 OK (allowing access to phpMyAdmin).
// If no, it returns 401 Unauthorized (denying access).
//
// Return-to-intended tidak bisa ditangani di sini: ini SUBREQUEST, jadi
// REQUEST_URI berisi path skrip ini sendiri, bukan URL phpMyAdmin yang diminta.
// Nginx yang tahu tujuannya, dan ia yang meneruskannya lewat
// error_page 401 -> login.php?redirect=... (diterima auth_sanitize_cross_host()).
// Sesi menyeberang antar subdomain lewat session.cookie_domain di Dockerfile.

session_start();

if (!isset($_SESSION['user_id'])) {
    // Belum login. Hanya 401 yang boleh memicu redirect ke login di sisi Nginx.
    http_response_code(401);
    echo 'Unauthorized';
} elseif (($_SESSION['role'] ?? '') !== 'Category Manager') {
    // Sudah login tapi tak berhak. Wajib dibedakan dari 401: kalau ikut 401,
    // Nginx melemparnya ke login, pengguna login lagi, ditolak lagi — loop.
    http_response_code(403);
    echo 'Forbidden';
} else {
    http_response_code(200);
    echo 'OK';
}
