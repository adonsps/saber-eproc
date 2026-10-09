<?php
// pages/ajax_project_quotation_po_currency.php
// "PO currency" switch inside the Initial Quotation Price modal
// (includes/quotation_modal_ui.php). A PO the K2 import left as IDR but that was
// really made in USD can be switched here; the amount keeps its number, only
// the currency changes. All checks and the write live in
// quotation_change_po_currency() (includes/vendor_quotation_helper.php).
header('Content-Type: application/json');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect, return JSON instead.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

// View-only accounts can't change prices, so they can't change a PO currency either.
if (ws_is_viewer()) {
    echo json_encode(['status' => 'error', 'message' => 'View-only accounts cannot change prices.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/vendor_quotation_helper.php';

try {
    $canvassing_no = trim((string)($_POST['canvassing_no'] ?? ''));
    $ticket_no     = trim((string)($_POST['ticket_no'] ?? ''));
    if ($canvassing_no === '' && $ticket_no === '') {
        echo json_encode(['status' => 'error', 'message' => 'Canvassing or ticket number is required.']);
        exit;
    }

    $r = quotation_change_po_currency(
        $pdo, $_SESSION,
        $canvassing_no !== '' ? 'project' : 'ticket',
        $canvassing_no !== '' ? $canvassing_no : $ticket_no,
        (string)($_POST['po_number'] ?? ''),
        (string)($_POST['po_currency'] ?? '')
    );

    if (!$r['ok']) {
        echo json_encode(['status' => 'error', 'message' => $r['message']]);
    } else {
        echo json_encode(['status' => 'success', 'changed' => $r['changed'], 'po_number' => $r['po_number'], 'po_currency' => $r['to']]);
    }
} catch (Exception $e) {
    error_log('[cost-avoidance] ' . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again; if it keeps happening, tell the admin.']);
}
