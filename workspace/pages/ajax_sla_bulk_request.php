<?php
// pages/ajax_sla_bulk_request.php
//
// BULK SLA REQUEST (CSV) for SLA Tracking (pages/sla_report.php, "Bulk SLA
// Request" button). Rules and rationale: includes/sla_bulk_request.php.
//
// Three actions, same shape as ajax_master_data_import.php:
//   action=template  GET — the CSV template as a file download.
//   action=validate  POST — parses the upload and validates every row against
//                    the LIVE database (sla_bulk_classify_rows()). Writes nothing.
//   action=confirm   POST — takes back the SAME rows plus the user's per-row
//                    tick, re-validates all of them from scratch, then files a
//                    pending 'sla_status' request (SLA Breached -> Within SLA)
//                    for every row that is ticked AND still READY. The browser's
//                    copy of the validation is never trusted.
//
// Every request filed here is 'pending', for every role — no auto-approve, unlike
// ajax_sla_request_submit.php. Only an approval in the Manage SLA Request inbox
// (ajax_sla_request_decide.php) changes what SLA Tracking shows.
require_once '../includes/auth.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$is_template = ($action === 'template');

// AJAX-safe auth (house convention, see ajax_sla_request_submit.php): never
// redirect a JSON caller. The template is a plain <a href> download, so it
// answers with a status code instead.
if (!isset($_SESSION['user_id'])) {
    if ($is_template) { http_response_code(401); exit('Your session has expired. Please log in again.'); }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Your session has expired. Please log in again.']);
    exit;
}

ws_require_write();

require_once '../config/database.php';
require_once '../includes/sla_helper.php';
require_once '../includes/project_team_helper.php';
require_once '../includes/sla_revision_helper.php';
require_once '../includes/sla_bulk_request.php';
date_default_timezone_set('Asia/Jakarta');

$role     = $_SESSION['role'] ?? '';
$username = trim((string)($_SESSION['username'] ?? ''));
$name     = trim((string)($_SESSION['name'] ?? ''));
$user_id  = (int)($_SESSION['user_id'] ?? 0);

// Same roles that may file a single request (ajax_sla_request_submit.php step 1).
// Technical Department can view SLA Tracking but not the revision workflow.
$may_request = in_array($role, ['PIC', 'Manager', 'Category Manager'], true);

if ($is_template) {
    if (!$may_request) { http_response_code(403); exit('Forbidden'); }
    $d = (string)($_GET['delimiter'] ?? ',');
    $d = $d === '\t' ? "\t" : $d;
    if (!in_array($d, [',', ';', "\t", '|'], true)) $d = ',';
    $csv = sla_bulk_template_csv($d);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sla_bulk_request_template.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

header('Content-Type: application/json');

function sla_bulk_json($payload) {
    // Substitute rather than fail: one odd byte in a remark must not blank the
    // whole preview (sla_bulk_to_utf8() already converts, this is the backstop).
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!$may_request) {
    sla_bulk_json(['status' => 'error', 'message' => 'You are not allowed to request an SLA revision.']);
}
if ($name === '') {
    sla_bulk_json(['status' => 'error', 'message' => 'Your profile is incomplete (name is empty). Please contact the admin.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sla_bulk_json(['status' => 'error', 'message' => 'Invalid request method.']);
}

try {
    if ($action === 'validate') {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            sla_bulk_json(['status' => 'error', 'message' => 'Please choose a CSV file to upload.']);
        }
        $file = $_FILES['csv_file'];
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            sla_bulk_json(['status' => 'error', 'message' => 'Please upload a .csv file (in Excel: File > Save As > CSV).']);
        }
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            sla_bulk_json(['status' => 'error', 'message' => 'The file is too large (maximum 2 MB).']);
        }

        // '' = auto-detect (the default in the UI).
        $delimiter = (string)($_POST['delimiter'] ?? '');
        $delimiter = $delimiter === '\t' ? "\t" : $delimiter;
        if (!in_array($delimiter, ['', ',', ';', "\t", '|'], true)) $delimiter = '';

        $parsed = sla_bulk_parse_csv($file['tmp_name'], $delimiter);
        if (!$parsed['ok']) {
            sla_bulk_json(['status' => 'error', 'message' => $parsed['error']]);
        }

        $result = sla_bulk_classify_rows($pdo, $_SESSION, $parsed['rows']);
        // 'clean' is confirm's business, not the preview's.
        foreach ($result['rows'] as &$e) unset($e['clean']);
        unset($e);
        sla_bulk_json(['status' => 'ok'] + $result);
    }

    if ($action === 'confirm') {
        $submitted = json_decode((string)($_POST['rows'] ?? ''), true);
        if (!is_array($submitted) || empty($submitted)) {
            sla_bulk_json(['status' => 'error', 'message' => 'No rows were submitted.']);
        }
        if (count($submitted) > SLA_BULK_MAX_ROWS) {
            sla_bulk_json(['status' => 'error', 'message' => 'Too many rows (maximum ' . SLA_BULK_MAX_ROWS . ').']);
        }

        // The browser sends back EVERY row of its last preview, not just the ticked
        // ones — the duplicate-in-file rule needs the whole file to be judged the
        // same way twice. row_number is the correlation key.
        $rows = [];
        $wanted = [];
        foreach ($submitted as $i => $r) {
            if (!is_array($r)) continue;
            $rn = isset($r['row_number']) ? (int)$r['row_number'] : ($i + 2);
            $rows[] = [
                'row_number' => $rn,
                'ticket_no'  => trim((string)($r['ticket_no'] ?? '')),
                'line_item'  => trim((string)($r['line_item'] ?? '')),
                'remarks'    => trim((string)($r['remarks'] ?? '')),
                'link'       => trim((string)($r['link'] ?? '')),
            ];
            $wanted[$rn] = !empty($r['selected']);
        }

        $classified = sla_bulk_classify_rows($pdo, $_SESSION, $rows);

        $to_submit = [];
        $failed    = [];
        $skipped   = 0;
        foreach ($classified['rows'] as $e) {
            if (empty($wanted[$e['row_number']])) { $skipped++; continue; }
            if ($e['status'] === 'READY') {
                $to_submit[] = $e;
            } else {
                // Ticked, but no longer valid — someone filed a request on the line,
                // an approval moved it off Breached, etc. Reported, never written.
                $failed[] = [
                    'row_number' => $e['row_number'],
                    'ticket_no'  => $e['ticket_no'],
                    'line_item'  => $e['line_item'],
                    'message'    => implode(' ', array_map(fn($x) => $x['title'] . ': ' . $x['message'], $e['errors'])),
                ];
            }
        }

        if (empty($to_submit)) {
            sla_bulk_json([
                'status'  => 'error',
                'message' => $failed
                    ? 'Nothing was submitted — the selected rows are no longer valid.'
                    : 'No rows were selected — nothing was submitted.',
                'failed'  => $failed,
            ]);
        }

        // Resolved once: it depends on the submitter only. NULL is allowed — the
        // requests then fall to the Category Manager queue, same as a single request.
        $approver = sla_rev_resolve_approver($pdo, $username);

        $pendingStmt = $pdo->prepare("SELECT id FROM sla_revision_requests
                                       WHERE ticket_no = ? AND ticket_line_item = ? AND status = 'pending'
                                       LIMIT 1 FOR UPDATE");
        $insStmt = $pdo->prepare("INSERT INTO sla_revision_requests
            (ticket_no, ticket_line_item, request_type, old_value, requested_value,
             remarks, evidence_url,
             requested_by_user_id, requested_by_name, requested_by_username,
             approver_username, approver_name, status)
            VALUES (?, ?, 'sla_status', ?, 'Within SLA', ?, ?, ?, ?, ?, ?, ?, 'pending')");

        // One transaction for the whole file: either every READY row is filed or
        // none is — no half-uploaded CSV to reconcile afterwards.
        $created = [];
        $pdo->beginTransaction();
        try {
            foreach ($to_submit as $e) {
                // Last-moment re-check of the one-pending-per-line rule, inside
                // the transaction, so a request filed in the seconds since
                // classify cannot end up with a twin.
                $pendingStmt->execute([$e['ticket_no'], $e['line_item']]);
                if ($pendingStmt->fetch()) {
                    $failed[] = [
                        'row_number' => $e['row_number'], 'ticket_no' => $e['ticket_no'], 'line_item' => $e['line_item'],
                        'message'    => 'This line already has a request waiting for approval.',
                    ];
                    continue;
                }
                $insStmt->execute([
                    $e['ticket_no'], $e['line_item'], $e['current_status'],
                    $e['clean']['remarks'], $e['clean']['evidence_url'],
                    $user_id, $name, $username,
                    $approver['username'] ?? null, $approver['name'] ?? null,
                ]);
                $created[] = ['row_number' => $e['row_number'], 'ticket_no' => $e['ticket_no'],
                              'line_item' => $e['line_item'], 'request_id' => (int)$pdo->lastInsertId()];
            }
            $pdo->commit();
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('ajax_sla_bulk_request.php (confirm, rolled back): ' . $ex->getMessage());
            sla_bulk_json(['status' => 'error', 'message' => 'A database error occurred; nothing was submitted. Please try again.']);
        }

        error_log(sprintf('SLA bulk request: user=%s submitted=%d failed=%d skipped=%d',
            $username, count($created), count($failed), $skipped));

        if (empty($created)) {
            sla_bulk_json(['status' => 'error', 'message' => 'Nothing was submitted — the selected rows are no longer valid.', 'failed' => $failed]);
        }

        $tickets = count(array_unique(array_column($created, 'ticket_no')));
        sla_bulk_json([
            'status'  => 'ok',
            'message' => count($created) . ' line' . (count($created) === 1 ? '' : 's') . ' across ' . $tickets
                       . ' ticket' . ($tickets === 1 ? '' : 's') . ' submitted'
                       . ($approver
                            ? ', waiting for approval from ' . $approver['name'] . '.'
                            : '. Your direct superior is not registered yet, so it goes to the Category Manager queue.'),
            'summary' => ['submitted' => count($created), 'failed' => count($failed), 'skipped' => $skipped],
            'created' => $created,
            'failed'  => $failed,
        ]);
    }

    sla_bulk_json(['status' => 'error', 'message' => 'Unknown action.']);
} catch (PDOException $e) {
    error_log('ajax_sla_bulk_request.php: ' . $e->getMessage());
    sla_bulk_json(['status' => 'error', 'message' => 'Failed to read the SLA data. Please try again shortly.']);
} catch (Throwable $e) {
    error_log('ajax_sla_bulk_request.php: ' . $e->getMessage());
    sla_bulk_json(['status' => 'error', 'message' => 'A system error occurred. Please try again shortly.']);
}
