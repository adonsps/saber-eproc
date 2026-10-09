<?php
// modules/admin/k2_register_action.php
session_start();
require '../../config.php';
require_once dirname(__DIR__, 2) . '/includes/notification_helper.php';
require_once dirname(__DIR__, 2) . '/includes/exception_request_helper.php';

header('Content-Type: application/json');

// ── Auth guard ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die(json_encode(['status' => 'error', 'message' => 'Access Denied']));
}

// ── Input extraction ─────────────────────────────────────────────────────────
$action      = $_POST['action']    ?? '';
$vendor_id   = intval($_POST['vendor_id'] ?? 0);
$admin_id    = (int)$_SESSION['user_id'];

// FIX: $vendor_code was never read from $_POST in the original — it was always NULL
$vendor_code        = trim($_POST['vendor_code']        ?? '');
$k2_request_number  = strtoupper(trim($_POST['k2_request_number'] ?? ''));
$is_anomaly         = ($_POST['is_anomaly'] ?? '0') === '1';
$anomaly_code       = trim($_POST['anomaly_code'] ?? '');
$k2_remarks         = trim($_POST['k2_remarks'] ?? '');

// ── Basic validation ─────────────────────────────────────────────────────────
if (!$vendor_id) {
    die(json_encode(['status' => 'error', 'message' => 'Invalid vendor ID']));
}

// Use prepared statement — original used raw interpolation
$vs = $conn->prepare("
    SELECT v.id, v.company_name, v.registration_status, v.k2_status, v.sla_deadline, v.vendor_code,
           er.submitted_by AS exception_request_by
    FROM vendors v
    " . EXCEPTION_REQUEST_LATEST_JOIN . "
    WHERE v.id = ?
");
$vs->bind_param("i", $vendor_id);
$vs->execute();
$vendor = $vs->get_result()->fetch_assoc();

if (!$vendor) {
    die(json_encode(['status' => 'error', 'message' => 'Vendor not found']));
}

// ── Step 1: Start K2 registration ────────────────────────────────────────────
if ($action === 'start_k2') {

    // Guard: only move forward if currently pending
    if ($vendor['k2_status'] !== 'pending') {
        die(json_encode(['status' => 'error', 'message' => 'Vendor is not in pending state']));
    }

    if ($is_anomaly) {
        $nowJkt      = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
        $sla_outcome = 'on_time';
        if (!empty($vendor['sla_deadline']) && $nowJkt > $vendor['sla_deadline']) {
            $sla_outcome = 'late';
        }

        $vendor_code_to_save = $anomaly_code !== '' ? $anomaly_code : ($vendor['vendor_code'] ?? null);
        $remarks_to_save = 'Vendor already registered in Agora';
        if ($vendor_code_to_save) {
            $remarks_to_save .= ' with existing Vendor Code: ' . $vendor_code_to_save;
        }

        $stmt = $conn->prepare("
            UPDATE vendors
            SET k2_status        = 'registered',
                vendor_code      = ?,
                k2_anomaly        = 1,
                k2_registered_at = NOW(),
                k2_registered_by = ?,
                sla_status       = ?,
                k2_remarks       = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sissi", $vendor_code_to_save, $admin_id, $sla_outcome, $remarks_to_save, $vendor_id);

        if (!$stmt->execute()) {
            die(json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]));
        }

        echo json_encode([
            'status'      => 'success',
            'message'     => 'Vendor marked as already registered in Agora',
            'vendor_code' => $vendor_code_to_save,
            'sla_outcome' => $sla_outcome,
            'is_anomaly'  => true,
        ]);
        exit;
    }

    // Validate request number format
    if (!preg_match('/^\d{8}-\d{5}$/', $k2_request_number)) {
        die(json_encode(['status' => 'error', 'message' => 'Format No.Request tidak valid. Gunakan format: YYYYMMDD-XXXXX']));
    }

    $stmt = $conn->prepare("UPDATE vendors SET k2_status = 'in_progress', k2_started_at = NOW(), k2_request_number = ? WHERE id = ?");
    $stmt->bind_param("si", $k2_request_number, $vendor_id);

    if (!$stmt->execute()) {
        die(json_encode(['status' => 'error', 'message' => 'DB error: ' . $conn->error]));
    }

    echo json_encode(['status' => 'success', 'message' => 'Agora registration started']);

// ── Step 2: Finish K2 — atomic transaction ───────────────────────────────────
} elseif ($action === 'finish_k2') {

    // FIX: vendor_code must not be empty before we proceed
    if ($vendor_code === '') {
        die(json_encode(['status' => 'error', 'message' => 'Vendor code wajib diisi']));
    }

    // Guard: only finalize if currently in_progress
    if ($vendor['k2_status'] !== 'in_progress') {
        die(json_encode(['status' => 'error', 'message' => 'Vendor is not in in_progress state']));
    }

    // Determine SLA outcome — compare NOW (Asia/Jakarta) against stored deadline
    $nowJkt      = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    $sla_outcome = 'on_time';
    if (!empty($vendor['sla_deadline']) && $nowJkt > $vendor['sla_deadline']) {
        $sla_outcome = 'late';
    }

    // FIX: Everything inside ONE transaction.
    //      Original had a pre-transaction UPDATE that could not be rolled back,
    //      plus a second UPDATE inside the transaction that was missing sla_status.
    $conn->begin_transaction();
    $recipient_id = 0;

    try {
        // 2a. Mark vendor as registered — all fields in one shot
        $upd = $conn->prepare("
            UPDATE vendors
            SET k2_status        = 'registered',
                vendor_code      = ?,
                k2_registered_at = NOW(),
                k2_registered_by = ?,
                sla_status       = ?,
                k2_remarks       = ?
            WHERE id = ?
        ");
        $upd->bind_param("sissi", $vendor_code, $admin_id, $sla_outcome, $k2_remarks, $vendor_id);
        if (!$upd->execute()) throw new Exception("Vendor update failed: " . $conn->error);

        // 2b. Resolve Sourcing Requestor from the latest PQ submission
        $sub_stmt = $conn->prepare("
            SELECT sourcing_reviewer_id
            FROM pq_submissions
            WHERE vendor_id = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $sub_stmt->bind_param("i", $vendor_id);
        $sub_stmt->execute();
        $sub = $sub_stmt->get_result()->fetch_assoc();

        // Fallback: Exception Request vendors may have no PQ submission yet — notify the
        // Sourcing user who initiated the Exception Request instead.
        $requestor_id = ($sub && !empty($sub['sourcing_reviewer_id']))
            ? (int)$sub['sourcing_reviewer_id']
            : (int)($vendor['exception_request_by'] ?? 0);

        // 2c. Insert in-app notification for the Sourcing Requestor
        if ($requestor_id > 0) {
            $recipient_id = $requestor_id;
            $company_name = $vendor['company_name'];
            $message = "Vendor yang Anda ajukan, \"{$company_name}\", telah berhasil "
                     . "didaftarkan ke Agora dengan Vendor Code: {$vendor_code}.";

            $notif = $conn->prepare("
                INSERT INTO notifications
                    (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message)
                VALUES
                    ('k2_registered', ?, ?, ?, 'inapp', 'sent', 0, ?)
            ");
            $notif->bind_param("iiis", $admin_id, $vendor_id, $recipient_id, $message);
            if (!$notif->execute()) throw new Exception("Notification insert failed: " . $conn->error);
        }

        $conn->commit();

        if ($recipient_id > 0 && function_exists('notifyOnK2Registered')) {
            notifyOnK2Registered($admin_id, $vendor_id, $vendor['company_name'], $vendor_code, $recipient_id);
        }

        echo json_encode([
            'status'      => 'success',
            'message'     => 'Vendor berhasil didaftarkan ke Agora',
            'vendor_code' => $vendor_code,
            'sla_outcome' => $sla_outcome,
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}
?>
