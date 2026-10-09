<?php
// pages/ajax_sla_request_decide.php
// The Manager approves or rejects an SLA adjustment request. This is the ONLY
// path in the app that writes sla_revision_overrides, which is what makes "every
// adjustment requires Manager approval" true by construction.
//
// Accepts one or many request ids in one call — a PIC submission covering several
// line items is several rows here, and the Manager decides it as one thing. Every
// id is still authorized and applied on its own, and one failing never cancels
// the others.
header('Content-Type: application/json');

require_once '../includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Your session has expired. Please log in again.']);
    exit;
}

// Write endpoint. sla_rev_can_decide() already fails closed for any non-manager
// role, but that check happens after the request row is loaded; this refuses a
// Viewer up front and keeps the rule in one place.
ws_require_write();

require_once '../config/database.php';
require_once '../includes/sla_revision_helper.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $role = $_SESSION['role'] ?? '';
    if ($role !== 'Manager' && $role !== 'Category Manager') {
        echo json_encode(['status' => 'error', 'message' => 'You are not allowed to approve or reject SLA requests.']);
        exit;
    }

    $action = trim((string)($_POST['action'] ?? ''));
    $notes  = trim((string)($_POST['decision_notes'] ?? ''));

    // Absent request_ids (legacy single-request caller) => just request_id.
    $request_ids = [];
    $ids_raw = trim((string)($_POST['request_ids'] ?? ''));
    if ($ids_raw !== '') {
        $decoded = json_decode($ids_raw, true);
        if (!is_array($decoded)) {
            echo json_encode(['status' => 'error', 'message' => 'The selected request list is unreadable.']);
            exit;
        }
        foreach ($decoded as $rid) {
            $rid = (int)$rid;
            if ($rid > 0 && !in_array($rid, $request_ids, true)) $request_ids[] = $rid;
        }
    }
    if (empty($request_ids)) {
        $rid = (int)($_POST['request_id'] ?? 0);
        if ($rid > 0) $request_ids[] = $rid;
    }

    if (empty($request_ids)) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid request ID.']);
        exit;
    }
    if (!in_array($action, ['approve', 'reject'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'Unrecognized action.']);
        exit;
    }
    // BOTH decisions need a written reason, at least 10 characters. An approval
    // rewrites the SLA verdict on a line and is exactly as consequential as a
    // rejection, so it has to be as accountable. Enforced here, not only by the
    // two client-side checks in sla_report.php -- those just spare a round trip.
    if (mb_strlen($notes) < 10) {
        echo json_encode([
            'status'  => 'error',
            'message' => ($action === 'approve'
                ? 'An approval note is required, at least 10 characters.'
                : 'A rejection reason is required, at least 10 characters.'),
        ]);
        exit;
    }

    $decided_by_name = trim((string)($_SESSION['name'] ?? ($_SESSION['username'] ?? '')));
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';

    $sel = $pdo->prepare("SELECT * FROM sla_revision_requests WHERE id = ? LIMIT 1");
    $upd = $pdo->prepare("UPDATE sla_revision_requests
                             SET status = ?, decided_by_user_id = ?, decided_by_name = ?,
                                 decided_at = NOW(), decision_notes = ?
                           WHERE id = ? AND status = 'pending'");

    $decided = [];
    $skipped = [];

    // One transaction PER request, not one around the batch: each line's override
    // is independent, so a line that fails must not undo the lines already applied.
    foreach ($request_ids as $request_id) {
        $sel->execute([$request_id]);
        $request = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            $skipped[] = ['id' => $request_id, 'line' => (string)$request_id, 'message' => 'Request not found.'];
            continue;
        }
        $line = (string)$request['ticket_line_item'];

        // Per-request authorization, separate from the role gate: a Manager must
        // never be able to decide another Manager's queue by POSTing an id.
        if (!sla_rev_can_decide($pdo, $_SESSION, $request)) {
            $skipped[] = ['id' => $request_id, 'line' => $line, 'message' => 'Outside your authority.'];
            continue;
        }

        // Guards against a double-decide from two open tabs.
        if ($request['status'] !== 'pending') {
            $skipped[] = ['id' => $request_id, 'line' => $line,
                          'message' => 'Already processed (' . sla_rev_status_label($request['status']) . ').'];
            continue;
        }

        $pdo->beginTransaction();
        try {
            // Re-check status inside the transaction so two simultaneous approvals
            // can't both apply — the second one updates 0 rows and aborts.
            $upd->execute([$new_status, (int)$_SESSION['user_id'], $decided_by_name, ($notes !== '' ? $notes : null), $request_id]);

            if ($upd->rowCount() === 0) {
                throw new RuntimeException('Already processed by someone else.');
            }

            // Only an approval touches the effective values. A rejection leaves
            // sla_revision_overrides completely untouched.
            if ($action === 'approve') {
                sla_rev_apply_approved($pdo, $request, $decided_by_name);
            }

            $pdo->commit();
            $decided[] = ['id' => $request_id, 'line' => $line];
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $skipped[] = ['id' => $request_id, 'line' => $line, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('ajax_sla_request_decide.php (id ' . $request_id . '): ' . $e->getMessage());
            $skipped[] = ['id' => $request_id, 'line' => $line, 'message' => 'Failed to process this line.'];
        }
    }

    if (empty($decided)) {
        // Single-request callers keep their old, specific wording.
        echo json_encode([
            'status'  => 'error',
            'message' => count($skipped) === 1 ? $skipped[0]['message'] : 'No request could be processed.',
            'skipped' => $skipped,
        ]);
        exit;
    }

    $n = count($decided);
    echo json_encode([
        'status'  => 'success',
        'message' => ($action === 'approve'
            ? ($n === 1 ? 'Request approved and the revision has been applied.'
                        : $n . ' line items approved and their revisions have been applied.')
            : ($n === 1 ? 'Request rejected. The SLA data is unchanged.'
                        : $n . ' line items rejected. The SLA data is unchanged.')),
        'decided' => $decided,
        'skipped' => $skipped,
    ]);
} catch (RuntimeException $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('ajax_sla_request_decide.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to process the request. Please try again shortly.']);
} catch (Throwable $e) {
    error_log('ajax_sla_request_decide.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again shortly.']);
}
