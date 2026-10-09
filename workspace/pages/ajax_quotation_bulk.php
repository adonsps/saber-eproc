<?php
// pages/ajax_quotation_bulk.php
//
// BULK Initial Quotation Price (CSV) for dashboard_mgr.php,
// dashboard_project_head.php and dashboard_pic.php. Rules and rationale:
// includes/quotation_bulk.php.
//
// Three actions, same shape as ajax_sla_bulk_request.php:
//   action=template  GET  -- the prefilled worklist CSV as a download
//                    (type=project|nonproject, source=mgr|project_head|pic).
//   action=validate  POST -- parses the uploaded .csv and classifies every
//                    unit (canvassing / ticket) against the LIVE database.
//                    Writes nothing.
//   action=confirm   POST -- takes back the SAME parsed rows plus the ticked
//                    keys, re-classifies all of them from scratch, then saves
//                    each ticked unit that is still READY via
//                    quotation_save_project_canvas() / quotation_save_ticket_items()
//                    (one transaction per unit).
//                    The browser's copy of the validation is never trusted.
require_once '../includes/auth.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$is_template = ($action === 'template');

// AJAX-safe auth: never redirect a JSON caller. The template is a plain
// <a href> download, so it answers with a status code instead.
if (!isset($_SESSION['user_id'])) {
    if ($is_template) { http_response_code(401); exit('Your session has expired. Please log in again.'); }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Your session has expired. Please log in again.']);
    exit;
}

// View-only accounts can't write quotations (the template is only useful for importing).
ws_require_write();

require_once '../config/database.php';
require_once '../includes/quotation_bulk.php';
require_once '../includes/quotation_bulk_ui.php';   // qb_hidden_for()
date_default_timezone_set('Asia/Jakarta');

// The bulk template/import is hidden for a few named users (see
// QB_HIDDEN_USERNAMES); refuse them here as well, not just in the UI.
if (qb_hidden_for($_SESSION)) {
    if ($is_template) { http_response_code(403); exit('Not available for your account.'); }
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Not available for your account.']);
    exit;
}

if ($is_template) {
    $type   = ($_GET['type'] ?? '') === 'nonproject' ? 'nonproject' : 'project';
    $source = ($_GET['source'] ?? '') === 'project_head' ? 'project_head' : 'mgr';
    if ($source === 'project_head') $type = 'project';
    try {
        $worklist = quotation_bulk_worklist($pdo, $_SESSION, $type, $source);
        $label = $type === 'project' ? 'project' : 'non_project';
        quotation_bulk_write_template($worklist, $type, 'initial_quotation_price_' . $label . '_' . date('Ymd_Hi') . '.csv');
    } catch (Throwable $e) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
        error_log('[cost-avoidance] ' . $e->getMessage()); echo 'Could not build the template. Please try again.';
    }
    exit;
}

header('Content-Type: application/json');

function qb_json($payload) {
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function qb_counts(array $classified): array {
    $c = ['READY' => 0, 'SKIPPED' => 0, 'ERROR' => 0];
    foreach ($classified as $r) { $c[$r['status']]++; }
    return $c;
}

// The preview never needs the full save payload in the browser.
function qb_public(array $classified): array {
    return array_map(function($r) { unset($r['input']); return $r; }, $classified);
}

try {
    if ($action === 'validate') {
        if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            qb_json(['status' => 'error', 'message' => 'Please choose the filled-in template (.csv) to upload.']);
        }
        $f = $_FILES['file'];
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if ($ext === 'xlsx' || $ext === 'xls') {
            qb_json(['status' => 'error', 'message' => 'Please upload the CSV template. If you saved it as an Excel workbook, use File > Save As > "CSV (Comma delimited)".']);
        }
        if (!in_array($ext, ['csv', 'txt'], true)) {
            qb_json(['status' => 'error', 'message' => 'Please upload the template as a .csv file.']);
        }
        if ((int)$f['size'] > 10 * 1024 * 1024) {
            qb_json(['status' => 'error', 'message' => 'The file is larger than 10 MB.']);
        }

        $parsed = quotation_bulk_parse_csv($f['tmp_name']);
        if (!$parsed['ok']) qb_json(['status' => 'error', 'message' => $parsed['error']]);

        $classified = quotation_bulk_classify($pdo, $_SESSION, $parsed['rows'], $parsed['type']);
        qb_json([
            'status'    => 'success',
            'type'      => $parsed['type'],
            'row_count' => count($parsed['rows']),
            'counts'    => qb_counts($classified),
            'units'     => qb_public($classified),
            // Sent back verbatim on confirm, where it is re-validated from scratch.
            'rows'      => $parsed['rows'],
        ]);
    }

    if ($action === 'confirm') {
        $rows = json_decode((string)($_POST['rows'] ?? ''), true);
        $type = ($_POST['type'] ?? '') === 'nonproject' ? 'nonproject' : 'project';
        $selected = json_decode((string)($_POST['selected'] ?? ''), true);
        if (!is_array($rows) || empty($rows) || count($rows) > QB_MAX_ROWS) {
            qb_json(['status' => 'error', 'message' => 'Upload data is missing or too large - please validate the file again.']);
        }
        if (!is_array($selected) || empty($selected)) {
            qb_json(['status' => 'error', 'message' => 'Tick at least one row to import.']);
        }
        $selected = array_flip(array_map('strval', $selected));

        $classified = quotation_bulk_classify($pdo, $_SESSION, quotation_bulk_clean_rows($rows, $type), $type);
        $results = [];
        $saved = 0;
        foreach ($classified as $c) {
            if (!isset($selected[$c['key']])) continue;
            if ($c['status'] !== 'READY') {
                $results[] = ['key' => $c['key'], 'ok' => false, 'message' => $c['message'] ?: 'No longer ready to import.'];
                continue;
            }
            try {
                $r = $type === 'project'
                    ? quotation_save_project_canvas($pdo, $_SESSION, $c['key'], $c['input'], ['confirm_edit' => true, 'source' => 'BULK'])
                    : quotation_save_ticket_items($pdo, $_SESSION, $c['key'], $c['input'], ['confirm_edit' => true, 'source' => 'BULK']);
            } catch (Throwable $e) {
                error_log('[cost-avoidance] ' . $e->getMessage()); $r = ['ok' => false, 'message' => 'Could not save this one - please try again.', 'lines_saved' => 0, 'taxonomy_warnings' => [], 'baseline_warnings' => []];
            }
            if ($r['ok']) $saved++;
            $warnings = [];
            if (!empty($c['problem_text'])) $warnings[] = 'Not imported: ' . $c['problem_text'];
            foreach (($r['taxonomy_warnings'] ?? []) as $k => $w) { $warnings[] = "$k: taxonomy - $w"; }
            foreach (($r['baseline_warnings'] ?? []) as $k => $w) { $warnings[] = "$k: $w"; }
            $results[] = ['key' => $c['key'], 'ok' => (bool)$r['ok'], 'message' => $r['ok'] ? '' : $r['message'],
                          'lines_saved' => (int)($r['lines_saved'] ?? 0), 'warnings' => $warnings];
        }

        qb_json(['status' => 'success', 'type' => $type, 'saved' => $saved, 'failed' => count($results) - $saved, 'results' => $results]);
    }

    qb_json(['status' => 'error', 'message' => 'Unknown action.']);

} catch (Throwable $e) {
    error_log('[cost-avoidance] ' . $e->getMessage()); qb_json(['status' => 'error', 'message' => 'Something went wrong. Please try again; if it keeps happening, tell the admin.']);
}
