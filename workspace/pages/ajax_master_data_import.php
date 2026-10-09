<?php
// pages/ajax_master_data_import.php
//
// CSV Import for Master Data. Category Manager only, same as every other
// write on the Taxonomy tab of master_data.php.
//
// Two-step flow, neither of which trusts the other:
//   action=validate  Parses an uploaded CSV and classifies every row against
//                     the LIVE table (md_import_classify_rows()) into UNCHANGED /
//                     UPDATED / NEW / INVALID. Writes nothing. Returns that
//                     classification for the browser's change-preview screen.
//   action=confirm    Takes back the SAME row list plus one 'action' per row
//                     (the user's checkbox choice: 'update' / 'add' / 'skip')
//                     and re-classifies it from scratch against the live table
//                     before writing anything -- the table can have changed
//                     since validate ran, and a client payload is never
//                     authoritative. A row is only written when its FRESH
//                     classification still supports the requested action
//                     (status UPDATED for 'update', status NEW for 'add');
//                     anything else is reported back as skipped or failed,
//                     never silently written.
//
// A row whose id does not exist is NEW, never auto-inserted -- Add requires
// the user's explicit per-row opt-in. There is no delete anywhere in this
// flow: a master_data row absent from the CSV is simply left untouched.
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/master_data_audit.php';
require_once '../includes/master_data_import.php';

require_login();
ws_require_write();

$role = $_SESSION['role'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// action=template is a plain file download (no JSON), so it's handled before
// the JSON content-type / role gate below -- a Category Manager reaching this
// through a normal <a href> download, not fetch(), still needs the CSV to
// come back as a CSV.
if ($action === 'template') {
    if ($role !== 'Category Manager') {
        http_response_code(403);
        exit('Forbidden');
    }
    $csv = md_import_template_csv();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="master_data_import_template.csv"');
    header('Content-Length: ' . strlen($csv));
    echo $csv;
    exit;
}

header('Content-Type: application/json');

if ($role !== 'Category Manager') {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Only Category Manager can perform an import.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

if ($action === 'validate') {
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'message' => 'Please choose a CSV file to upload.']);
        exit;
    }
    $file = $_FILES['csv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        echo json_encode(['status' => 'error', 'message' => 'Please upload a valid .csv file.']);
        exit;
    }

    $delimiter = (string)($_POST['delimiter'] ?? ',');
    $delimiter = $delimiter === '\t' ? "\t" : $delimiter;
    if (!in_array($delimiter, [',', ';', "\t", '|'], true)) {
        $delimiter = ',';
    }

    $parsed = md_import_parse_csv($file['tmp_name'], $delimiter);
    if (!$parsed['ok']) {
        echo json_encode(['status' => 'error', 'message' => $parsed['error']]);
        exit;
    }

    $result = md_import_classify_rows($pdo, $parsed['rows']);
    echo json_encode(['status' => 'ok'] + $result);
    exit;
}

if ($action === 'confirm') {
    $raw = $_POST['rows'] ?? '';
    $submitted = json_decode((string)$raw, true);
    if (!is_array($submitted) || empty($submitted)) {
        echo json_encode(['status' => 'error', 'message' => 'No rows were submitted.']);
        exit;
    }

    // The browser sends back EVERY row from its last classify response (not
    // just the ones it wants applied), each carrying the action the user's
    // checkbox landed on ('update' / 'add' / 'skip'). Re-shape into the plain
    // row format md_import_classify_rows() expects from a fresh parse, and keep
    // the requested action indexed by row_number -- the correlation key,
    // since a NEW row has no database id yet to key off.
    $expected = md_import_expected_columns();
    $rows = [];
    $requested_action = [];
    foreach ($submitted as $i => $r) {
        if (!is_array($r)) { continue; }
        $row_number = isset($r['row_number']) ? (int)$r['row_number'] : ($i + 1);
        $row = ['row_number' => $row_number];
        foreach ($expected as $col) {
            $row[$col] = isset($r[$col]) ? trim((string)$r[$col]) : '';
        }
        $rows[] = $row;
        $requested_action[$row_number] = in_array($r['action'] ?? 'skip', ['update', 'add'], true) ? $r['action'] : 'skip';
    }

    // Fresh classification against the LIVE table -- the browser's copy of
    // status/changed_fields/new_values is never trusted, only re-derived here.
    $classified = md_import_classify_rows($pdo, $rows);

    $to_update = [];
    $to_add    = [];
    $results   = [];
    foreach ($classified['rows'] as $entry) {
        $wanted = $requested_action[$entry['row_number']] ?? 'skip';
        if ($wanted === 'update' && $entry['status'] === 'UPDATED') {
            $to_update[] = $entry;
        } elseif ($wanted === 'add' && $entry['status'] === 'NEW') {
            $to_add[] = $entry;
        } elseif ($wanted !== 'skip') {
            // The user asked for update/add, but a fresh check says this row no
            // longer qualifies (someone else changed it, added it, or it's now
            // invalid) -- report as failed rather than silently dropping it.
            $entry['outcome'] = 'failed';
            $entry['outcome_message'] = 'Row state changed since it was validated (now ' . $entry['status'] . '); not applied.';
            $results[] = $entry;
        } else {
            $entry['outcome'] = 'skipped';
            $results[] = $entry;
        }
    }

    if (empty($to_update) && empty($to_add)) {
        $failed_early = count(array_filter($results, static fn($e) => ($e['outcome'] ?? '') === 'failed'));
        echo json_encode([
            'status'  => 'ok',
            'summary' => ['updated' => 0, 'added' => 0, 'skipped' => count($results) - $failed_early, 'failed' => $failed_early],
            'results' => $results,
            'message' => $failed_early > 0
                ? 'Nothing was changed -- the requested row(s) are no longer valid to update or add.'
                : 'No rows were selected to update or add -- nothing was changed.',
        ]);
        exit;
    }

    $audit_actor = md_audit_actor();
    $upd = $pdo->prepare(
        "UPDATE master_data
            SET level_0 = ?, level_1 = ?, level_2 = ?, level_3 = ?, level_4 = ?, level_5 = ?, pic_names = ?,
                suggested_pic_names = NULL, suggested_by = NULL, status = 'active', last_modified_by = ?
          WHERE id = ?"
    );
    $ins = $pdo->prepare(
        "INSERT INTO master_data (level_0, level_1, level_2, level_3, level_4, level_5, pic_names, status, created_by, last_modified_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)"
    );

    // One transaction for the whole batch: a thrown PDOException rolls
    // everything back, so $to_update/$to_add either all land together or none
    // do -- there is no partially-applied CSV.
    try {
        $pdo->beginTransaction();
        foreach ($to_update as $entry) {
            $v = $entry['new_values'];
            $before = md_audit_snapshot($pdo, [$entry['id']]);
            $upd->execute([
                $v['level_0'], $v['level_1'], $v['level_2'], $v['level_3'],
                $v['level_4'], $v['level_5'], $v['pic_names'],
                $audit_actor, $entry['id'],
            ]);
            md_audit_log_update($pdo, $before, $audit_actor);
            $entry['outcome'] = 'updated';
            $results[] = $entry;
        }
        foreach ($to_add as $entry) {
            $v = $entry['new_values'];
            $ins->execute([
                $v['level_0'], $v['level_1'], $v['level_2'], $v['level_3'],
                $v['level_4'], $v['level_5'], $v['pic_names'],
                $audit_actor, $audit_actor,
            ]);
            $new_id = (int)$pdo->lastInsertId();
            md_audit_log_insert($pdo, $new_id, $audit_actor);
            $entry['id'] = $new_id;
            $entry['outcome'] = 'added';
            $results[] = $entry;
        }
        $pdo->commit();
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('Master Data import failed, rolled back: ' . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => 'A database error occurred; no rows were changed. Please try again.']);
        exit;
    }

    $summary = [
        'updated' => count($to_update),
        'added'   => count($to_add),
        'skipped' => count(array_filter($results, static fn($e) => ($e['outcome'] ?? '') === 'skipped')),
        'failed'  => count(array_filter($results, static fn($e) => ($e['outcome'] ?? '') === 'failed')),
    ];
    error_log(sprintf(
        'Master Data import: actor=%s submitted=%d updated=%d added=%d skipped=%d failed=%d',
        $audit_actor, count($rows), $summary['updated'], $summary['added'], $summary['skipped'], $summary['failed']
    ));

    echo json_encode(['status' => 'ok', 'summary' => $summary, 'results' => $results]);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
