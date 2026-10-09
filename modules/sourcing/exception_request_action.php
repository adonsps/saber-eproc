<?php
// modules/sourcing/exception_request_action.php
//
// Sourcing initiates a Exception Request: vouches for a vendor whose profile is fully
session_start();
require '../../config.php';
require_once '../../includes/profile_helper.php';
require_once '../../includes/exception_request_helper.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$vendor_id = intval($_POST['vendor_id'] ?? 0);
$notes     = trim(str_replace(["\r\n", "\r"], "\n", $_POST['exception_request_notes'] ?? ''));
$reason       = $_POST['exception_request_reason'] ?? '';
$reason_other = trim($_POST['exception_request_reason_other'] ?? '');
$ack       = $_POST['exception_request_ack'] ?? '';
$otp       = preg_replace('/\D/', '', (string)($_POST['exception_request_otp'] ?? ''));
$sourcing_id = (int)$_SESSION['user_id'];

$er_reason_options = [
    'po_urgent'        => 'PO Urgent',
    'vendor_exclusive' => 'Vendor Exclusive',
];

$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_POST['ajax']) && $_POST['ajax'] === '1')
);

/**
 * Render a full-page, on-brand feedback screen (replaces the native alert()).
 *
 * @param string      $msg      Message shown to the user.
 * @param string      $type     'error' | 'success'
 * @param string|null $redirect URL to send the user to. null = "Kembali" (history.back).
 * @param int         $delayMs  Auto-redirect delay in ms (only used when $redirect set).
 * @param array       $extra    Extra fields merged into the AJAX JSON envelope.
 */
function er_render_feedback(string $msg, string $type = 'error', ?string $redirect = null, int $delayMs = 1800, array $extra = []): void {
    global $isAjax;
    $isSuccess = ($type === 'success');

    // AJAX path: respond with JSON so the dashboard can show an in-page popup.
    if (!empty($isAjax)) {
        http_response_code($isSuccess ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'ok'      => $isSuccess,
            'type'    => $type,
            'title'   => $isSuccess ? 'Berhasil' : 'Perhatian',
            'message' => $msg,
        ], $extra));
        exit;
    }

    $safeMsg   = htmlspecialchars($msg ?? '', ENT_QUOTES, 'UTF-8');
    $accent    = $isSuccess ? '#198754' : '#dc3545';
    $accent2   = $isSuccess ? '#146c43' : '#b02a37';
    $title     = $isSuccess ? 'Berhasil' : 'Perhatian';
    $iconPath  = $isSuccess
        ? '<polyline points="20 6 9 17 4 12"></polyline>'
        : '<line x1="12" y1="8" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>';

    // Primary action + optional auto-redirect
    if ($redirect !== null) {
        $safeRedirect = htmlspecialchars($redirect ?? '', ENT_QUOTES, 'UTF-8');
        $primaryBtn   = "<a href=\"{$safeRedirect}\" class=\"erb-btn erb-btn-primary\">Lanjutkan</a>";
        $redirectJs   = "setTimeout(function(){ window.location.replace('{$safeRedirect}'); }, {$delayMs});";
        $subNote      = '<div class="erb-redirect"><span class="erb-spinner"></span> Mengalihkan otomatis&hellip;</div>';
    } else {
        $primaryBtn = "<button type=\"button\" class=\"erb-btn erb-btn-primary\" onclick=\"window.history.back()\">Kembali</button>";
        $redirectJs = '';
        $subNote    = '';
    }

    http_response_code($isSuccess ? 200 : 422);
    echo <<<HTML
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} &middot; Exception Request</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh;
    font-family: 'Poppins', sans-serif;
    background: #f4f6f9;
    display: flex; align-items: center; justify-content: center;
    padding: 20px; color: #264653;
  }
  .erb-card {
    width: 100%; max-width: 420px; background: #fff;
    border-radius: 20px; overflow: hidden;
    box-shadow: 0 18px 45px rgba(0,0,0,0.12);
    animation: erb-pop 0.35s cubic-bezier(0.18, 0.89, 0.32, 1.28);
  }
  @keyframes erb-pop { from { opacity: 0; transform: translateY(14px) scale(0.97); } to { opacity: 1; transform: none; } }
  .erb-head {
    background: linear-gradient(135deg, {$accent}, {$accent2});
    padding: 34px 24px 46px; text-align: center; position: relative;
  }
  .erb-head::after {
    content: ''; position: absolute; bottom: -1px; left: 0; right: 0; height: 34px;
    background: #fff; border-radius: 50% 50% 0 0 / 100% 100% 0 0;
  }
  .erb-icon {
    width: 68px; height: 68px; margin: 0 auto; border-radius: 50%;
    background: rgba(255,255,255,0.2); backdrop-filter: blur(6px);
    display: flex; align-items: center; justify-content: center;
    border: 3px solid rgba(255,255,255,0.55);
  }
  .erb-icon svg { width: 34px; height: 34px; stroke: #fff; stroke-width: 3; fill: none; stroke-linecap: round; stroke-linejoin: round; }
  .erb-body { padding: 6px 30px 30px; text-align: center; }
  .erb-title { font-size: 1.3rem; font-weight: 700; margin: 4px 0 10px; color: {$accent2}; }
  .erb-msg { font-size: 0.95rem; line-height: 1.6; color: #495057; margin: 0 0 24px; }
  .erb-btn {
    display: inline-block; border: none; cursor: pointer;
    font-family: inherit; font-size: 0.95rem; font-weight: 600;
    padding: 12px 30px; border-radius: 50px; text-decoration: none;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
  }
  .erb-btn-primary { background: linear-gradient(135deg, {$accent}, {$accent2}); color: #fff; box-shadow: 0 6px 18px rgba(0,0,0,0.15); }
  .erb-btn-primary:hover { transform: translateY(-2px); box-shadow: 0 9px 24px rgba(0,0,0,0.22); }
  .erb-redirect { margin-top: 16px; font-size: 0.8rem; color: #adb5bd; display: flex; align-items: center; justify-content: center; gap: 7px; }
  .erb-spinner {
    width: 13px; height: 13px; border: 2px solid #dee2e6; border-top-color: {$accent};
    border-radius: 50%; display: inline-block; animation: erb-spin 0.7s linear infinite;
  }
  @keyframes erb-spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
  <div class="erb-card">
    <div class="erb-head">
      <div class="erb-icon">
        <svg viewBox="0 0 24 24">{$iconPath}</svg>
      </div>
    </div>
    <div class="erb-body">
      <div class="erb-title">{$title}</div>
      <p class="erb-msg">{$safeMsg}</p>
      {$primaryBtn}
      {$subNote}
    </div>
  </div>
  <script>{$redirectJs}</script>
</body>
</html>
HTML;
    exit;
}

function er_fail(string $msg, array $extra = []): void {
    er_render_feedback($msg, 'error', null, 1800, $extra);
}

if (!$vendor_id) {
    er_fail('Vendor tidak valid.');
}

// Fetch the full vendor row (needed for the profile-completeness check).
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    er_fail('Vendor tidak ditemukan.');
}

// 1. Profile must be fully complete (server-side re-check, not just the button gate).
$check = isVendorProfileComplete($vendor);
if (!$check['complete']) {
    er_fail("Profil vendor belum lengkap. Exception Request hanya untuk vendor berprofil penuh.");
}

// Vendor may have been registered to K2 between "Kirim OTP" and this final
// submit — re-check here too (mirrors exception_request_send_otp.php).
if (($vendor['k2_status'] ?? '') === 'registered') {
    er_fail('Vendor ini sudah teregistrasi di K2.');
}

$regStatus = $vendor['registration_status'] ?? '';
if (in_array($regStatus, ['registration_submitted', 'approved', 'rejected'], true)) {
    er_fail('Vendor ini tidak dapat diajukan Exception Request karena status registrasinya.');
}

// Re-check kuota ER (defense in depth: jumlah bisa bertambah sejak "Kirim OTP").
// Dicek sebelum verifikasi OTP supaya attempt OTP tidak terpakai sia-sia.
enforceExceptionRequestQuota($conn, $sourcing_id, 'er_fail');

if ($reason === '' || ($reason !== 'other' && !isset($er_reason_options[$reason]))) {
    er_fail('Pilih kondisi vendor terlebih dahulu.');
}
if ($reason === 'other' && $reason_other === '') {
    er_fail('Sebutkan kondisi vendor (Lainnya) terlebih dahulu.');
}
$reason_label = $reason === 'other' ? $reason_other : $er_reason_options[$reason];

// 2. Notes are required.
if ($notes === '') {
    er_fail('Catatan (Notes) wajib diisi sebelum mengajukan Exception Request.');
}

// 3. Responsibility statement must be acknowledged (not stored, but required).
if ($ack !== '1') {
    er_fail('Anda harus menyetujui pernyataan tanggung jawab terlebih dahulu.');
}

// 4. Final confirmation: verify the 6-digit OTP that was delivered to the
//    sourcing user's supervisor by WhatsApp (see exception_request_send_otp.php).
if ($otp === '') {
    er_fail('Masukkan kode OTP yang dikirim ke supervisor Anda untuk konfirmasi.');
}

// The OTP lives on the outstanding 'requested' record created when "Kirim OTP"
// was pressed. Load the vendor's latest Exception Request row to verify it.
$erCheck = $conn->prepare("SELECT id, status, otp_hash, otp_attempts FROM exception_requests WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
$erCheck->bind_param("i", $vendor_id);
$erCheck->execute();
$erRow = $erCheck->get_result()->fetch_assoc();

// Idempotency guard: do not re-submit if already in review or approved.
if ($erRow && in_array($erRow['status'], ['under_review_vm_head', 'approved'], true)) {
    er_fail('Vendor ini sudah berada dalam proses Exception Request.');
}
if (!$erRow || $erRow['status'] !== 'requested' || empty($erRow['otp_hash'])) {
    er_fail('OTP belum diminta atau sudah tidak berlaku. Tekan "Kirim OTP" terlebih dahulu.');
}
// Throttle brute force: lock after 5 wrong tries until a new OTP is issued.
if ((int)$erRow['otp_attempts'] >= 5) {
    er_fail('Terlalu banyak percobaan OTP yang salah. Tekan "Kirim OTP" untuk meminta kode baru.');
}
if (!password_verify($otp, $erRow['otp_hash'])) {
    $bump = $conn->prepare("UPDATE exception_requests SET otp_attempts = otp_attempts + 1 WHERE id = ?");
    $bump->bind_param("i", $erRow['id']);
    $bump->execute();
    er_fail('Kode OTP salah. Silakan coba lagi.');
}

// 5. OTP verified — promote the 'requested' record into a full submission and
//    consume the OTP (set NULL) so it cannot be replayed. The chosen "Kondisi
//    Vendor" and notes are stored on the same row.
$ins = $conn->prepare("
    UPDATE exception_requests
    SET status = 'under_review_vm_head', submitted_at = NOW(), reason = ?, submitted_notes = ?,
        otp_hash = NULL, otp_attempts = 0
    WHERE id = ?
");
$ins->bind_param("ssi", $reason_label, $notes, $erRow['id']);

if (!$ins->execute()) {
    er_fail('Gagal menyimpan detail pengajuan: ' . $conn->error);
}

// 6b. The Sourcing who submits the Exception Request TAKES OVER responsibility for this
// vendor. Only the LATEST submission is reassigned — that is the row every
// responsibility lookup reads (MAX(id)); older rows are review history owned
// by whoever handled those cycles and must stay untouched.
$assign = $conn->prepare("
    UPDATE pq_submissions
    SET sourcing_reviewer_id = ?
    WHERE vendor_id = ?
    ORDER BY id DESC
    LIMIT 1
");
$assign->bind_param("ii", $sourcing_id, $vendor_id);
$assign->execute();

// 7. Notify VM Head.
require_once '../../includes/notification_helper.php';
notifyVmHeadOnExceptionRequest($sourcing_id, $vendor_id, $vendor['company_name'] ?? '', $reason_label, $notes);

er_render_feedback('Exception Request diajukan! Menunggu persetujuan VM Head.', 'success', 'dashboard.php');
?>
