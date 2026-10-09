<?php
// modules/vendormgt/exception_request_action.php
// VM Head approves or rejects an Exception Request.
session_start();
require '../../config.php';
require_once '../../includes/exception_request_helper.php';
require_once '../../includes/upload_paths.php';

// Security: VM Head only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendormgt_head') {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$vendor_id = intval($_POST['vendor_id'] ?? 0);
$action    = $_POST['action'] ?? '';
$vm_notes  = trim(str_replace(["\r\n", "\r"], "\n", $_POST['vm_notes'] ?? ''));
$vm_head_id = (int)$_SESSION['user_id'];


function ervm_popup(string $type, string $title, string $msg, string $redirect): void {
    $_SESSION['er_flash'] = ['type' => $type, 'title' => $title, 'msg' => $msg];
    if ($redirect === 'back' || $redirect === '') {
        $redirect = 'dashboard.php';
    }
    header("Location: $redirect");
    exit;
}

function ervm_fail(string $msg): void {
    ervm_popup('error', 'Terjadi Kesalahan', $msg, 'back');
}

if (!$vendor_id) {
    ervm_fail('Vendor tidak valid.');
}

// Load vendor + its latest (current) Exception Request cycle + k2 state.
$stmt = $conn->prepare("
    SELECT v.company_name, v.k2_status, er.id AS er_id, er.status AS er_status, er.submitted_by
    FROM vendors v
    " . EXCEPTION_REQUEST_LATEST_JOIN . "
    WHERE v.id = ?
");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    ervm_fail('Vendor tidak ditemukan.');
}

// Guard: only pending requests can be acted on.
if (($vendor['er_status'] ?? 'none') !== 'under_review_vm_head') {
    ervm_fail('Pengajuan Exception Request ini sudah diproses atau tidak valid.');
}

require_once '../../includes/notification_helper.php';
$company     = $vendor['company_name'] ?? '';
$sourcing_id = (int)($vendor['submitted_by'] ?? 0);
$er_id       = (int)$vendor['er_id'];

// Per-user scoping (see VM_HEAD_USER_SETTINGS): some VM Head accounts are read-only
// on Exception Requests. Re-check here, not just in the UI, since this endpoint is
// reachable directly by POSTing the form regardless of which buttons were rendered.
if (in_array($action, ['approve', 'reject'], true) && !canDecideExceptionRequest($vm_head_id)) {
    ervm_fail('Akun Anda tidak memiliki akses untuk memutuskan Exception Request ini. Silakan hubungi VM Head lain.');
}

if ($action === 'approve') {

    // Only open the K2 gate if the vendor has not already progressed past it.
    // (PQ approval may have already set k2_status; never downgrade in_progress/registered.)
    $open_k2 = in_array((string)($vendor['k2_status'] ?? ''), ['', 'pending'], true);

    $vm_head_name = $_SESSION['user_name'] ?? '-';

    // Build the "Exception Request Approval" PDF (stand-in cover doc for the PQ Result,
    $temp_path = generateExceptionRequestApprovalPdf($conn, $vendor_id, $company, $er_id, $sourcing_id, $vm_head_name, $vm_notes);

    // --- Resolve/prepare the vendor's upload folder (same convention as the PQ Result PDF). ---
    // Delegated to includes/upload_paths.php: the old "<root>/../uploads/" base
    // resolves to /var/www in the container, which www-data cannot write, so the
    // approval PDF never reached the browser-reachable volume.
    $target_dir = vendor_upload_dir_for((int)$vendor_id, $company);
    if ($target_dir === null) {
        @unlink($temp_path);
        ervm_fail('Folder uploads tidak dapat ditulis; PDF persetujuan tidak dapat disimpan. Hubungi tim IT.');
    }

    $file_name = 'ExceptionRequest_Approval_' . date('Ymd_His') . '.pdf';
    $full_path = $target_dir . '/' . $file_name;

    // The PDF stays in temp until the DB transaction below commits successfully.
    $conn->begin_transaction();
    try {
        if ($open_k2) {
            require_once '../../includes/sla_helper.php';
            // Admin K2-registration SLA = 3 working days: the admin waits on
            // external Finance + MDM before the K2 vendor code exists.
            $sla_deadline = calculateSlaDeadline($conn, date('Y-m-d H:i:s'), 3);

            $upd = $conn->prepare("
                UPDATE vendors
                SET k2_status    = 'pending',
                    sla_deadline = ?,
                    sla_status   = 'pending'
                WHERE id = ?
            ");
            $upd->bind_param("si", $sla_deadline, $vendor_id);
            if (!$upd->execute()) {
                throw new Exception($conn->error);
            }
        }

        $updReq = $conn->prepare("
            UPDATE exception_requests
            SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
            WHERE id = ?
        ");
        $updReq->bind_param("isi", $vm_head_id, $vm_notes, $er_id);
        if (!$updReq->execute()) {
            throw new Exception($conn->error);
        }

        $conn->commit();

        // DB is committed — now it is safe to move the PDF to the vendor folder.
        // rename() across filesystems fails, hence the copy fallback; if BOTH
        // fail the certificate does not exist and that must be logged rather
        // than assumed (the DB row already points at a path).
        if (!@rename($temp_path, $full_path)) {
            if (!@copy($temp_path, $full_path)) {
                error_log("exception_request_action.php: approval PDF not stored — vendor_id={$vendor_id}, target={$full_path}");
            }
            @unlink($temp_path);
        }
    } catch (Exception $e) {
        $conn->rollback();
        @unlink($temp_path);
        ervm_fail('Gagal menyimpan: ' . $e->getMessage());
    }

    notifyAdminsOnExceptionRequestApproved($vm_head_id, $vendor_id, $company, $full_path);
    ervm_popup('success', 'Exception Request Disetujui', 'Admin dapat mendaftarkan vendor ke K2 tanpa menunggu PQ.', 'dashboard.php');

} elseif ($action === 'reject') {

    if ($vm_notes === '') {
        ervm_fail('Catatan wajib diisi saat menolak pengajuan.');
    }

    // Each rejection is stored as its own exception_requests row, so the full
    // history across re-submission cycles is just the vendor's past rows —
    $updReq = $conn->prepare("
        UPDATE exception_requests
        SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_notes = ?
        WHERE id = ?
    ");
    $updReq->bind_param("isi", $vm_head_id, $vm_notes, $er_id);
    if (!$updReq->execute()) {
        ervm_fail('Gagal menyimpan: ' . $conn->error);
    }

    if ($sourcing_id > 0) {
        notifyOnExceptionRequestRejected($vm_head_id, $vendor_id, $company, $vm_notes, $sourcing_id);
    }
    ervm_popup('success', 'Pengajuan Ditolak', 'Pengajuan Exception Request telah ditolak. Tim Sourcing telah diberi notifikasi.', 'dashboard.php');

} else {
    ervm_fail('Aksi tidak valid.');
}
?>
