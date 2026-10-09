<?php
// modules/sourcing/exception_request_send_otp.php
//
// Step "Kirim OTP" of the Exception Request flow. The Sourcing user presses this
// BEFORE submitting the form. It:
//   1. Re-checks the vendor is eligible for an Exception Request.
//   2. Resolves the Sourcing user's supervisor + WhatsApp number (workspace DB).
//   3. Generates a fresh 6-digit OTP, stores it (hashed) on a 'requested'
//      exception_requests row (the "requested" Exception Request record).
//   4. Delivers the OTP to the supervisor by WhatsApp (Fonnte).
//
// The final OTP verification + submit lives in exception_request_action.php.
// This endpoint always responds with JSON — it is only called by fetch().
session_start();
require '../../config.php';
require_once '../../includes/profile_helper.php';
require_once '../../includes/exception_request_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Emit a JSON envelope matching what the dashboard modal expects, then stop. */
function otp_json(bool $ok, string $message, array $extra = [], int $http = 200): void
{
    http_response_code($ok ? $http : ($http === 200 ? 422 : $http));
    echo json_encode(array_merge([
        'ok'      => $ok,
        'type'    => $ok ? 'success' : 'error',
        'title'   => $ok ? 'OTP Terkirim' : 'Perhatian',
        'message' => $message,
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Authorisation ────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'sourcing') {
    otp_json(false, 'Akses ditolak.', [], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    otp_json(false, 'Metode tidak valid.', [], 405);
}

$vendor_id   = intval($_POST['vendor_id'] ?? 0);
$sourcing_id = (int)$_SESSION['user_id'];

if (!$vendor_id) {
    otp_json(false, 'Vendor tidak valid.');
}

$reason_label = trim((string)($_POST['exception_request_reason_label'] ?? ''));
$notes        = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['exception_request_notes'] ?? '')));

// ── Vendor eligibility (mirrors exception_request_action.php) ─────────────────
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    otp_json(false, 'Vendor tidak ditemukan.');
}
if (!isVendorProfileComplete($vendor)['complete']) {
    otp_json(false, 'Profil vendor belum lengkap. Exception Request hanya untuk vendor berprofil penuh.');
}
if (($vendor['k2_status'] ?? '') === 'registered') {
    otp_json(false, 'Vendor ini sudah teregistrasi di K2.');
}
$regStatus = $vendor['registration_status'] ?? '';
if (in_array($regStatus, ['registration_submitted', 'approved', 'rejected'], true)) {
    otp_json(false, 'Vendor ini tidak dapat diajukan Exception Request karena status registrasinya.');
}

// ── Idempotency: block when a cycle is already active ─────────────────────────
$erCheck = $conn->prepare("SELECT id, status, submitted_by FROM exception_requests WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
$erCheck->bind_param("i", $vendor_id);
$erCheck->execute();
$latestEr = $erCheck->get_result()->fetch_assoc();
if ($latestEr && in_array($latestEr['status'], ['under_review_vm_head', 'approved'], true)) {
    otp_json(false, 'Vendor ini sudah berada dalam proses Exception Request.');
}

// ── Kuota: blokir bila ER aktif dengan PQ belum selesai sudah mencapai batas ──
// Dicek sebelum OTP dibuat/dikirim, supaya supervisor tidak pernah menerima OTP
// untuk pengajuan yang pasti ditolak dan tidak ada baris 'requested' ter-insert.
enforceExceptionRequestQuota($conn, $sourcing_id, function (string $msg, array $extra) {
    otp_json(false, $msg, $extra);
});

// ── Resolve supervisor + WhatsApp number ──────────────────────────────────────
$sup = findSourcingSupervisorWa($conn, $sourcing_id);
if (!$sup['ok']) {
    otp_json(false, $sup['error']);
}

// ── Generate + persist the OTP as a 'requested' record ────────────────────────
$otp      = generateExceptionRequestOtp();
$otp_hash = password_hash($otp, PASSWORD_DEFAULT);

// Reuse this user's outstanding 'requested' row (avoids row bloat on repeated
// "Kirim OTP"); otherwise open a fresh Exception Request cycle.
$reuse = $latestEr
    && $latestEr['status'] === 'requested'
    && (int)$latestEr['submitted_by'] === $sourcing_id;

// Persist the Exception Request detail (Kondisi Vendor + Catatan) already at the
// "Kirim OTP" step, so the data is recorded even if the user never enters the OTP.
// The final submit (exception_request_action.php) will re-save the same values.
if ($reuse) {
    $save = $conn->prepare("UPDATE exception_requests SET otp_hash = ?, otp_requested_at = NOW(), otp_sent_to = ?, otp_attempts = 0, reason = ?, submitted_notes = ? WHERE id = ?");
    $save->bind_param("ssssi", $otp_hash, $sup['phone'], $reason_label, $notes, $latestEr['id']);
} else {
    $save = $conn->prepare("INSERT INTO exception_requests (vendor_id, status, submitted_by, otp_hash, otp_requested_at, otp_sent_to, otp_attempts, reason, submitted_notes) VALUES (?, 'requested', ?, ?, NOW(), ?, 0, ?, ?)");
    $save->bind_param("iissss", $vendor_id, $sourcing_id, $otp_hash, $sup['phone'], $reason_label, $notes);
}
if (!$save->execute()) {
    otp_json(false, 'Gagal menyimpan OTP: ' . $conn->error);
}

// ── Deliver the OTP by WhatsApp to the supervisor ─────────────────────────────
require_once '../../workspace/includes/fonnte.php';

$requesterName = 'Tim Sourcing';
$rn = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
$rn->bind_param("i", $sourcing_id);
$rn->execute();
if ($rnRow = $rn->get_result()->fetch_assoc()) {
    $requesterName = $rnRow['full_name'] ?: $requesterName;
}
$companyName = $vendor['company_name'] ?? '-';

$wa_msg =
    "*VSS - Kode OTP Exception Request*\n\n" .
    "Halo {$sup['name']},\n\n" .
    "Anda menerima kode OTP untuk proses persetujuan *Exception Request* yang diajukan oleh *{$requesterName}* untuk vendor *{$companyName}*.\n\n" .
    "*Kondisi Vendor:* {$reason_label}\n" .
    "*Alasan / Catatan:* {$notes}\n\n" .
    "*Kode OTP:* *{$otp}*\n\n" .
    "Mohon berikan kode OTP tersebut kepada *{$requesterName}* hanya jika Anda telah menyetujui pengajuan Exception Request tersebut.\n\n" .
    "Demi keamanan, jangan bagikan kode OTP ini kepada pihak lain.";

$wa = sendFonnteWA($sup['phone'], $wa_msg);
if (empty($wa['ok'])) {
    otp_json(false, 'OTP gagal dikirim via WhatsApp. Silakan coba lagi. (' . ($wa['reason'] ?? 'unknown') . ')');
}

// CC a copy of the OTP to Sigit Rizqi Ramadan. Best-effort: the result is
// intentionally ignored so a CC failure never blocks the main flow.
$cc_number = '6285601920720'; // Sigit — monitoring CC 
if ($cc_number !== $sup['phone']) {
    sendFonnteWA($cc_number, $wa_msg);
}

otp_json(true,
    'Kode OTP telah dikirim via WhatsApp ke supervisor Anda (' . $sup['name'] . ', ' . maskExceptionRequestWa($sup['phone']) . '). Minta kode tersebut, lalu masukkan pada kolom OTP.',
    ['supervisor' => $sup['name'], 'sent_to' => maskExceptionRequestWa($sup['phone'])]
);
