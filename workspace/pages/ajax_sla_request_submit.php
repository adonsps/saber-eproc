<?php
// pages/ajax_sla_request_submit.php
// A PIC (or Manager/Technical Department) submits an SLA adjustment request.
// Normally writes ONLY to sla_revision_requests with status='pending' —
// nothing here changes what SLA Tracking shows; only an approval can
// (ajax_sla_request_decide.php). The one exception: a Manager or Category
// Manager already HAS approval authority, so their own submission is written
// straight to 'approved' and applied in the same request — see $auto_approve
// below — instead of making them wait on themselves or a peer.
//
// Accepts one or many lines of the SAME ticket in one call. Each line still gets
// its own row, and one line failing its checks never cancels the others.
header('Content-Type: application/json');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect, return JSON (house convention, see
// ajax_project_assign.php) — a redirect breaks $.ajax with dataType:'json'.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Your session has expired. Please log in again.']);
    exit;
}

// require_sla_access() (further down / in sla_helper's callers) now admits the
// read-only Viewer role for the report page, so the write path states its own
// requirement here.
ws_require_write();

require_once '../config/database.php';
require_once '../includes/sla_helper.php';
require_once '../includes/project_team_helper.php';
require_once '../includes/sla_revision_helper.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

try {
    $role     = $_SESSION['role'] ?? '';
    $username = trim((string)($_SESSION['username'] ?? ''));
    $name     = trim((string)($_SESSION['name'] ?? ''));
    $user_id  = (int)($_SESSION['user_id'] ?? 0);

    // 1. Only roles that may submit an SLA adjustment. Technical Department can
    //    reach SLA Tracking (require_sla_access()) but not this feature — kept
    //    out on purpose, mirrors $may_request in get_sla_computed_rows().
    if (!in_array($role, ['PIC', 'Manager', 'Category Manager'], true)) {
        echo json_encode(['status' => 'error', 'message' => 'You are not allowed to request an SLA revision.']);
        exit;
    }
    if ($name === '') {
        echo json_encode(['status' => 'error', 'message' => 'Your profile is incomplete (name is empty). Please contact the admin.']);
        exit;
    }

    // Manager and Category Manager already have the authority to approve
    // someone else's request (sla_rev_can_decide()), so making them file one
    // and then wait — on themselves, or on a peer — adds a step that decides
    // nothing. Their own submission is approved in the same call instead.
    // PIC and Technical Department are unaffected: still submit-then-wait.
    $auto_approve = in_array($role, ['Manager', 'Category Manager'], true);

    $ticket_no        = trim((string)($_POST['ticket_no'] ?? ''));
    $ticket_line_item = trim((string)($_POST['ticket_line_item'] ?? ''));
    if ($ticket_no === '' || $ticket_line_item === '') {
        echo json_encode(['status' => 'error', 'message' => 'Ticket No and Line Item are required.']);
        exit;
    }

    // Extra line items of the same ticket, picked from the recommendation list.
    // Absent (legacy single-line payload) => just the line above.
    $line_items = [$ticket_line_item];
    $lines_raw  = trim((string)($_POST['line_items'] ?? ''));
    if ($lines_raw !== '') {
        $decoded = json_decode($lines_raw, true);
        if (!is_array($decoded)) {
            echo json_encode(['status' => 'error', 'message' => 'The selected line item list is unreadable.']);
            exit;
        }
        foreach ($decoded as $li) {
            $li = trim((string)$li);
            if ($li !== '' && !in_array($li, $line_items, true)) $line_items[] = $li;
        }
    }

    // 2. Shape/format validation (types, dates, mandatory reason + evidence).
    list($ok, $clean) = sla_rev_validate($_POST);
    if (!$ok) {
        echo json_encode(['status' => 'error', 'message' => $clean]);
        exit;
    }

    // 3. Every selected line must exist, and a PIC may only submit for their OWN line.
    //    Resolved through the same persona-scoped row set the page renders, so
    //    a PIC can never request against a line they cannot even see.
    $ctx = resolve_sla_persona($pdo, $_SESSION);
    $computed = get_sla_computed_rows($pdo, $ctx);

    // Built once for every selected line — the row set is the expensive part.
    $rows_by_line = [];
    foreach ($computed['rows'] as $r) {
        if (trim((string)$r['ticket_no']) !== $ticket_no) continue;
        $rows_by_line[trim((string)$r['ticket_line_item'])] = $r;
    }

    // 4. At most ONE pending request per line, of ANY type — not just the same
    //    type as this submission. A line with a pending Date Adjustment must not
    //    also accept an SLA Status Override (or another Date Adjustment) until
    //    that first one is approved or rejected; otherwise two approvals could
    //    race, or a Manager could be asked to decide a date request that a status
    //    override (or vice versa) would make moot.
    $pendingStmt = $pdo->prepare("SELECT id FROM sla_revision_requests
                            WHERE ticket_no = ? AND ticket_line_item = ? AND status = 'pending'
                            LIMIT 1");

    // 6. Direct superior. NULL is allowed: the request falls through to the
    //    Category Manager queue instead of blocking the PIC over a data gap.
    //    Depends on the submitter only, so it is resolved once for the batch.
    $approver = sla_rev_resolve_approver($pdo, $username);

    // Auto-approved rows record the REQUESTER as the approver, not their
    // superior — they decided it, not the person $approver resolved to. This
    // is what makes the row show up under "Approved" in the requester's own
    // SLA Revision inbox (pages/sla_report.php scopes that list by
    // r.approver_username = the viewer), instead of silently landing in a
    // superior's queue for a decision that superior never actually made.
    $approver_username_col = $auto_approve ? $username : ($approver['username'] ?? null);
    $approver_name_col     = $auto_approve ? $name     : ($approver['name'] ?? null);

    $insStmt = $pdo->prepare("INSERT INTO sla_revision_requests
        (ticket_no, ticket_line_item, request_type, old_value, requested_value,
         old_pr_date, old_canvassing_date, requested_pr_date, requested_canvassing_date,
         remarks, evidence_url,
         requested_by_user_id, requested_by_name, requested_by_username,
         approver_username, approver_name, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");

    $created = [];
    $skipped = [];

    foreach ($line_items as $line) {
        $target = $rows_by_line[$line] ?? null;
        if ($target === null) {
            $skipped[] = ['line' => $line, 'message' => 'PR line not found, or it is not your responsibility.'];
            continue;
        }
        if ($role === 'PIC' && trim((string)($target['assigned_pic'] ?? '')) !== $name) {
            $skipped[] = ['line' => $line, 'message' => 'This line is assigned to another PIC.'];
            continue;
        }

        // 3b. ELIGIBILITY: SLA Breached only, for every request type. An adjustment
        //     exists to contest a breach — a line that met its SLA has nothing to
        //     contest, and a line still In Progress has no verdict yet.
        //     Enforced HERE, not only by the editable cells on SLA Tracking
        //     (sla_rev_line_editability()): the cells decide what to offer, this
        //     decides what is allowed.
        //     Consequence, by design: an SLA Status Override is now one-way,
        //     SLA Breached -> Within SLA.
        if ((string)$target['statusName'] !== 'SLA Breached') {
            $skipped[] = ['line' => $line, 'message' => 'Not SLA Breached (current status: ' . $target['statusName'] . ').'];
            continue;
        }

        $pendingStmt->execute([$ticket_no, $line]);
        if ($pendingStmt->fetch()) {
            $skipped[] = ['line' => $line, 'message' => 'This line already has a request waiting for approval.'];
            continue;
        }

        // 5. Per-type eligibility + snapshot of the EFFECTIVE current value.
        //    $target already carries any previously approved override, so the old_*
        //    columns record what the requester actually saw on screen. Working on a
        //    per-line copy: the same requested date can be a real change on one line
        //    and a no-op on the next.
        $c = $clean;
        $old_value           = null;
        $old_pr_date         = null;
        $old_canvassing_date = null;
        if ($c['request_type'] === 'date') {
            $old_pr_date = !empty($target['pr_date']) ? date('Y-m-d', strtotime($target['pr_date'])) : null;
            $old_canvassing_date = !empty($target['canvassing_date_approver_1'])
                ? date('Y-m-d', strtotime($target['canvassing_date_approver_1'])) : null;

            if ($c['requested_canvassing_date'] !== null && $old_canvassing_date === null) {
                $skipped[] = ['line' => $line, 'message' => 'Canvassing Approval Date is not filled in yet, so there is no date to adjust.'];
                continue;
            }

            // The form arrives pre-filled with both current dates, so a field left
            // untouched comes back equal to what it already is. Drop those instead
            // of recording a change that changes nothing.
            if ($c['requested_pr_date'] === $old_pr_date)                 $c['requested_pr_date'] = null;
            if ($c['requested_canvassing_date'] === $old_canvassing_date) $c['requested_canvassing_date'] = null;

            if ($c['requested_pr_date'] === null && $c['requested_canvassing_date'] === null) {
                $skipped[] = ['line' => $line, 'message' => 'No date differs from its current value.'];
                continue;
            }
        } else {
            // SLA Status Override. Only a line whose verdict already exists can be
            // overridden — 'In Progress' means canvassing approval has not happened,
            // so no SLA verdict has been reached yet.
            $current_status = (string)$target['statusName'];
            if (!in_array($current_status, sla_rev_status_options(), true)) {
                $skipped[] = ['line' => $line, 'message' => 'This line has no SLA result yet (status: ' . $current_status . ').'];
                continue;
            }
            if ($current_status === $c['requested_value']) {
                $skipped[] = ['line' => $line, 'message' => 'The requested status is the same as the current status.'];
                continue;
            }
            $old_value = $current_status;
        }

        $insStmt->execute([
            $ticket_no, $line, $c['request_type'], $old_value, $c['requested_value'],
            $old_pr_date, $old_canvassing_date, $c['requested_pr_date'], $c['requested_canvassing_date'],
            $c['remarks'], $c['evidence_url'],
            $user_id, $name, $username,
            $approver_username_col, $approver_name_col,
        ]);
        $new_id = (int)$pdo->lastInsertId();

        if ($auto_approve) {
            // Same effect as ajax_sla_request_decide.php's approve path — mark
            // decided, then apply — just folded into this call instead of a
            // second round trip through the inbox. One transaction per line,
            // same reasoning as the decide endpoint: one line's failure here
            // must not undo a sibling line already inserted above.
            $pdo->beginTransaction();
            try {
                $pdo->prepare("UPDATE sla_revision_requests
                                  SET status = 'approved', decided_by_user_id = ?, decided_by_name = ?,
                                      decided_at = NOW(), decision_notes = ?
                                WHERE id = ?")
                    ->execute([$user_id, $name, 'Auto-approved: submitted directly by ' . $role . '.', $new_id]);

                sla_rev_apply_approved($pdo, [
                    'id'                        => $new_id,
                    'ticket_no'                 => $ticket_no,
                    'ticket_line_item'          => $line,
                    'request_type'              => $c['request_type'],
                    'requested_pr_date'         => $c['requested_pr_date'],
                    'requested_canvassing_date' => $c['requested_canvassing_date'],
                    'requested_value'           => $c['requested_value'],
                ], $name);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('ajax_sla_request_submit.php (auto-approve id ' . $new_id . '): ' . $e->getMessage());
                // The row itself is still there as 'pending' (the UPDATE rolled
                // back with everything else) — not lost, just not auto-applied.
                // It will need a normal approval like anyone else's request.
            }
        }

        $created[] = ['line' => $line, 'request_id' => $new_id];
    }

    if (empty($created)) {
        // Single-line submit keeps its old, specific wording; nothing was written.
        echo json_encode([
            'status'  => 'error',
            'message' => count($skipped) === 1 ? $skipped[0]['message'] : 'No request could be submitted.',
            'skipped' => $skipped,
        ]);
        exit;
    }

    $where = $auto_approve
        ? '. Applied immediately — your role does not require approval.'
        : ($approver
            ? ', waiting for approval from ' . $approver['name'] . '.'
            : '. Your direct superior is not registered yet, so it goes to the Category Manager queue.');
    echo json_encode([
        'status'  => 'success',
        'message' => (count($created) === 1
                        ? ($auto_approve ? 'Line ' . $created[0]['line'] . ' was adjusted' : 'Request submitted for line ' . $created[0]['line'])
                        : count($created) . ' lines' . ($auto_approve ? ' adjusted' : ' submitted') . ' for ticket ' . $ticket_no)
                   . $where,
        'created' => $created,
        'skipped' => $skipped,
        'request_id' => $created[0]['request_id'], // legacy single-line callers
    ]);
} catch (PDOException $e) {
    error_log('ajax_sla_request_submit.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to save the request. Please try again shortly.']);
} catch (Throwable $e) {
    error_log('ajax_sla_request_submit.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again shortly.']);
}
