<?php
// pages/ajax_project_quotation_save.php
// Save "Initial Quotation Price" from the modal (includes/quotation_modal_ui.php).
//   - canvassing_no -> GP Project: one canvas-scope row keyed by canvassing_no
//     (price, currency, link, notes) + the required taxonomy.
//   - ticket_no     -> GP Non-Project: one item-scope row per line of that
//     ticket, keyed by (ticket_no, ticket_line_item).
//
// All validation and writes live in includes/vendor_quotation_helper.php
// (quotation_save_project_canvas / quotation_save_ticket_items), shared with
// the bulk import (ajax_quotation_bulk.php) so both enforce the same rules.
header('Content-Type: application/json');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect, return JSON instead.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

// View-only accounts can't change prices. (A Viewer resolves to every Project
// group in get_project_head_groups(), so the group gate alone would let one
// through on Project canvassings.) Answers in this endpoint's own JSON shape so
// the modal can show the message.
if (ws_is_viewer()) {
    echo json_encode(['status' => 'error', 'message' => 'View-only accounts cannot change prices.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/vendor_quotation_helper.php';

try {
    $canvassing_no = trim((string)($_POST['canvassing_no'] ?? ''));
    $ticket_no     = trim((string)($_POST['ticket_no'] ?? ''));

    if ($canvassing_no !== '') {
        $r = quotation_save_project_canvas($pdo, $_SESSION, $canvassing_no, [
            'currency'    => $_POST['currency'] ?? 'IDR',
            'last_price'  => $_POST['last_price'] ?? '',
            'fx_rate'     => $_POST['fx_rate'] ?? '',
            'gdrive_link' => $_POST['gdrive_link'] ?? '',
            'notes'       => $_POST['notes'] ?? '',
            'taxonomy'    => $_POST['taxonomy'] ?? '',
        ], ['confirm_edit' => !empty($_POST['confirm_edit']), 'source' => 'MODAL']);
    } elseif ($ticket_no !== '') {
        $r = quotation_save_ticket_items($pdo, $_SESSION, $ticket_no, ['lines' => $_POST['lines'] ?? []],
            ['confirm_edit' => !empty($_POST['confirm_edit']), 'source' => 'MODAL']);
    } else {
        $r = ['ok' => false, 'message' => 'Canvassing or ticket number is required.'];
    }

    if (!$r['ok'] && !empty($r['needs_confirm'])) {
        // Editing an existing price: hand back old -> new so the modal can ask.
        echo json_encode(['status' => 'confirm', 'message' => $r['message'], 'changes' => $r['changes']]);
    } elseif (!$r['ok']) {
        echo json_encode(['status' => 'error', 'message' => $r['message']]);
    } else {
        echo json_encode(['status' => 'success', 'lines_saved' => $r['lines_saved'],
                          'taxonomy_warnings' => $r['taxonomy_warnings'], 'baseline_warnings' => $r['baseline_warnings']]);
    }

} catch (Exception $e) {
    error_log('[cost-avoidance] ' . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again; if it keeps happening, tell the admin.']);
}
