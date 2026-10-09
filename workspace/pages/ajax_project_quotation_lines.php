<?php
// pages/ajax_project_quotation_lines.php
// Read-only lookup for the Initial Quotation Price modal
// (includes/quotation_modal_ui.php) on the Project Head / Manager / PIC
// dashboards. Two modes (confirmed by user, 2026-09-29):
//   - canvassing_no -> GP Project: one price for the whole canvassing, with a
//     required taxonomy; returns the saved canvas row, if any.
//   - ticket_no     -> GP Non-Project: every active line of that ticket, each
//     with its saved item price, if any.
// A canvassing_no that isn't Project (or a ticket_no that is) is refused, so
// each quotation can only be entered at its own granularity.
header('Content-Type: application/json; charset=utf-8');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect, return JSON instead.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Not authenticated.']);
    exit;
}

require_once '../config/database.php';
require_once '../includes/vendor_quotation_helper.php';
require_once '../includes/currency_helper.php';
require_once '../includes/cost_avoidance_helper.php';

function qlines_fail($msg) {
    echo json_encode(['status' => 'error', 'message' => $msg]);
    exit;
}

try {
    $canvassing_no = trim((string)($_POST['canvassing_no'] ?? $_GET['canvassing_no'] ?? ''));
    $ticket_no     = trim((string)($_POST['ticket_no'] ?? $_GET['ticket_no'] ?? ''));
    if ($canvassing_no === '' && $ticket_no === '') qlines_fail('Canvassing or ticket number is required.');

    // Active currencies for the modal's dropdowns (IDR/USD today; extensible
    // via Cost Avoidance's own Currency Settings tab -- see currency_helper.php).
    $currencies = [];
    // Only currencies that can actually be converted (IDR, or a real rate > 0):
    // an active currency with rate 0 would let a price through with no rate.
    foreach (ca_get_active_currencies($pdo) as $code => $c) {
        if ($code !== 'IDR' && (float)$c['rate_to_idr'] <= 0) continue;
        $currencies[] = ['code' => $code, 'symbol' => $c['symbol'], 'rate_to_idr' => $c['rate_to_idr']];
    }

    if ($canvassing_no !== '') {
        $canv = quotation_canvassing_lines($pdo, $canvassing_no);
        if (!$canv['ok']) qlines_fail($canv['message']);
        $auth = quotation_authorize_canvassing($pdo, $_SESSION, $canv['p_group'], $canv['lines']);
        if (!$auth['ok']) qlines_fail($auth['message']);
        $team = quotation_project_team($pdo, $canv['p_group']);
        if ($team === null) qlines_fail('This is a GP Non-Project canvassing - open the quotation from the ticket instead.');

        $stmt = $pdo->prepare("SELECT last_price, currency, fx_rate, gdrive_link, notes FROM vendor_quotation_last_price WHERE scope = 'canvas' AND canvassing_no = ? LIMIT 1");
        $stmt->execute([$canvassing_no]);
        $canvasRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // Pre-select the taxonomy when every line already shares one label.
        $labels = array_unique(array_filter(array_map(function($l) { return trim((string)($l['ca_taxonomy_level_2'] ?? '')); }, $canv['lines']), 'strlen'));
        $tickets = array_values(array_unique(array_map('strval', array_column($canv['lines'], 'ticket_no'))));

        echo json_encode([
            'status'           => 'success',
            'mode'             => 'project',
            'canvassing_no'    => $canvassing_no,
            'p_group'          => $canv['p_group'],
            'tickets'          => $tickets,
            'line_count'       => count($canv['lines']),
            'po_lines'         => quotation_canvas_po_info($pdo, $canvassing_no),
            'currencies'       => $currencies,
            'taxonomy_team'    => $team,
            'taxonomy_options' => quotation_taxonomy_options($pdo, $team),
            'taxonomy'         => count($labels) === 1 ? reset($labels) : '',
            'finalized'        => quotation_project_canvas_finalized($pdo, $canvassing_no, $canv['lines']),
            'canvas'           => $canvasRow ? [
                'last_price'  => $canvasRow['last_price'],
                'currency'    => (string)$canvasRow['currency'],
                'fx_rate'     => $canvasRow['fx_rate'],
                'gdrive_link' => (string)$canvasRow['gdrive_link'],
                'notes'       => (string)$canvasRow['notes'],
            ] : null,
        ]);
        exit;
    }

    $tk = quotation_ticket_lines($pdo, $ticket_no);
    if (!$tk['ok']) qlines_fail($tk['message']);
    $auth = quotation_authorize_canvassing($pdo, $_SESSION, $tk['p_group'], $tk['lines']);
    if (!$auth['ok']) qlines_fail($auth['message']);
    if (quotation_project_team($pdo, $tk['p_group']) !== null) qlines_fail('This is a GP Project ticket - open the quotation from its canvassing instead.');

    $stmt = $pdo->prepare("SELECT ticket_line_item, last_price, currency, fx_rate, gdrive_link, notes FROM vendor_quotation_last_price WHERE scope = 'item' AND ticket_no = ?");
    $stmt->execute([$ticket_no]);
    $saved = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $saved[(string)$r['ticket_line_item']] = $r; }

    // 'finalized' = every line already has a price. It no longer locks the
    // modal: a saved price can be edited (with a confirmation + history).
    $finalized = true;
    $lines = [];
    $poInfo = quotation_item_po_info($pdo, $ticket_no);
    foreach ($tk['lines'] as $l) {
        $s = $saved[(string)$l['ticket_line_item']] ?? null;
        if (!$s || $s['last_price'] === null) $finalized = false;
        $lines[] = [
            'ticket_line_item' => (string)$l['ticket_line_item'],
            'description'      => (string)($l['item_description'] ?? ''),
            'qty'              => isset($l['qty']) ? (float)$l['qty'] : null,
            'uom'              => (string)($l['uom'] ?? ''),
            'po'               => $poInfo[(string)$l['ticket_line_item']] ?? null,
            'last_price'       => $s ? $s['last_price'] : null,
            'currency'         => $s ? (string)$s['currency'] : 'IDR',
            'fx_rate'          => $s ? $s['fx_rate'] : null,
            'gdrive_link'      => $s ? (string)$s['gdrive_link'] : '',
            'notes'            => $s ? (string)$s['notes'] : '',
        ];
    }

    echo json_encode([
        'status'     => 'success',
        'mode'       => 'nonproject',
        'ticket_no'  => $ticket_no,
        'p_group'    => $tk['p_group'],
        'currencies' => $currencies,
        'finalized'  => $finalized,
        'lines'      => $lines,
    ]);

} catch (Exception $e) {
    error_log('[cost-avoidance] ' . $e->getMessage()); echo json_encode(['status' => 'error', 'message' => 'Something went wrong. Please try again; if it keeps happening, tell the admin.']);
}
