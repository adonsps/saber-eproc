<?php
// modules/sourcing/exception_request_cancel_otp.php
//
// Invalidates the outstanding Exception Request OTP for a vendor. Called by the
// dashboard when the sourcing user CLOSES the Exception Request modal (X / Batal
// / backdrop / Esc) — see the 'hidden.bs.modal' handler in dashboard.php. Once
// closed, the previously delivered OTP must no longer verify: the user has to
// press "Kirim OTP" again to obtain a fresh code.
//
// It only nulls the OTP fields on the caller's own 'requested' record; the
// record itself is left in place so the next "Kirim OTP" reuses it (mirrors the
// reuse logic in exception_request_send_otp.php). Always responds with JSON.
session_start();
require '../../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Emit a minimal JSON envelope, then stop. */
function cancel_otp_json(bool $ok, string $message = '', int $http = 200): void
{
    http_response_code($ok ? $http : ($http === 200 ? 422 : $http));
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Authorisation ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'sourcing') {
    cancel_otp_json(false, 'Akses ditolak.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    cancel_otp_json(false, 'Metode tidak valid.', 405);
}

$vendor_id   = intval($_POST['vendor_id'] ?? 0);
$sourcing_id = (int)$_SESSION['user_id'];
if (!$vendor_id) {
    cancel_otp_json(false, 'Vendor tidak valid.');
}

$stmt = $conn->prepare(
    "UPDATE exception_requests
        SET otp_hash = NULL, otp_attempts = 0
      WHERE vendor_id = ? AND submitted_by = ? AND status = 'requested'"
);
$stmt->bind_param("ii", $vendor_id, $sourcing_id);
$stmt->execute();

cancel_otp_json(true, 'OTP dibatalkan.');
