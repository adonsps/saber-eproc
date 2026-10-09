<?php
// pages/ajax_sla_request_history.php
// Full audit trail for one PR line, newest first — feeds the View History modal
// on SLA Tracking. Read-only.
header('Content-Type: application/json');

require_once '../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Sesi Anda telah berakhir. Silakan login kembali.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/sla_revision_helper.php';
date_default_timezone_set('Asia/Jakarta');

try {
    $role = $_SESSION['role'] ?? '';
    // Mirrors require_sla_access(): Technical Department no longer has SLA
    // Tracking at all, so it can't read this line's history either.
    if (!in_array($role, ['PIC', 'Manager', 'Category Manager'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Anda tidak berhak melihat riwayat ini.']);
        exit;
    }

    $ticket_no        = trim((string)($_POST['ticket_no'] ?? $_GET['ticket_no'] ?? ''));
    $ticket_line_item = trim((string)($_POST['ticket_line_item'] ?? $_GET['ticket_line_item'] ?? ''));
    if ($ticket_no === '' || $ticket_line_item === '') {
        echo json_encode(['status' => 'error', 'message' => 'Ticket No dan Line Item wajib diisi.']);
        exit;
    }

    // A PIC may only read the history of a line assigned to them. Checked
    // against prs directly (cheap) rather than rebuilding the whole report.
    if ($role === 'PIC') {
        $stmt = $pdo->prepare("SELECT assigned_pic FROM prs WHERE ticket_no = ? AND ticket_line_item = ? LIMIT 1");
        $stmt->execute([$ticket_no, $ticket_line_item]);
        $assigned = trim((string)($stmt->fetchColumn() ?: ''));
        if ($assigned !== trim((string)($_SESSION['name'] ?? ''))) {
            echo json_encode(['status' => 'error', 'message' => 'PR line ini bukan tanggung jawab Anda.']);
            exit;
        }
    }

    // SELECT * so the legacy columns come along for sla_rev_remarks_text() and
    // sla_rev_change_pairs(), which read whichever shape the row was written in.
    $stmt = $pdo->prepare("SELECT * FROM sla_revision_requests
                            WHERE ticket_no = ? AND ticket_line_item = ?
                            ORDER BY id DESC");
    $stmt->execute([$ticket_no, $ticket_line_item]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $items = array_map(function ($r) {
        return [
            'id'             => (int)$r['id'],
            'type'           => $r['request_type'],
            'type_label'     => sla_rev_type_label($r['request_type']),
            'changes'        => sla_rev_change_pairs($r),
            'remarks'        => sla_rev_remarks_text($r),
            'evidence_url'   => (string)($r['evidence_url'] ?? ''),
            'requested_by'   => (string)$r['requested_by_name'],
            'requested_at'   => (string)$r['created_at'],
            'approver_name'  => (string)($r['approver_name'] ?? ''),
            'status'         => (string)$r['status'],
            'status_label'   => sla_rev_status_label($r['status']),
            'decided_by'     => (string)($r['decided_by_name'] ?? ''),
            'decided_at'     => (string)($r['decided_at'] ?? ''),
            'decision_notes' => (string)($r['decision_notes'] ?? ''),
        ];
    }, $rows);

    // The override row tells the user which of these approvals is still in force.
    $ov = null;
    try {
        $stmt = $pdo->prepare("SELECT override_pr_date, override_canvassing_date, override_sla_status,
                                      approved_by_name, approved_at
                                 FROM sla_revision_overrides
                                WHERE ticket_no = ? AND ticket_line_item = ? LIMIT 1");
        $stmt->execute([$ticket_no, $ticket_line_item]);
        $ov = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        $ov = null;
    }

    echo json_encode([
        'status'   => 'success',
        'ticket'   => $ticket_no,
        'line'     => $ticket_line_item,
        'items'    => $items,
        'override' => $ov,
    ]);
} catch (PDOException $e) {
    error_log('ajax_sla_request_history.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Gagal memuat riwayat. Coba lagi beberapa saat.']);
} catch (Throwable $e) {
    error_log('ajax_sla_request_history.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Terjadi kesalahan sistem.']);
}
