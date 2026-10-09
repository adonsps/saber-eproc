<?php
// pages/ajax_sla_siblings.php
// "Other line items of the same ticket you could change too" — the recommendation
// list behind Apply Changes on SLA Tracking.
//
// READ-ONLY. It writes nothing and decides nothing: it only answers what to OFFER.
// ajax_sla_request_submit.php re-derives eligibility for every line it is handed,
// so a line that slips through here is still rejected on write.
//
// It exists as an endpoint (rather than a client-side filter, as it was on the old
// sla_my_requests.php) because #slaTable is serverSide: a sibling of the edited row
// is very often on a page the browser has never loaded.
header('Content-Type: application/json');

require_once '../includes/auth.php';

// AJAX-safe auth: never redirect, return JSON (house convention) — a redirect
// breaks $.ajax with dataType:'json'.
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Your session has expired. Please log in again.']);
    exit;
}
require_sla_access();

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
    // [{ticket, line, type}] — type is 'date' or 'sla_status', because a line can
    // be free to file one and blocked on the other.
    $raw = trim((string)($_POST['sources'] ?? ''));
    $sources = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($sources)) {
        echo json_encode(['status' => 'error', 'message' => 'The edited line list is unreadable.']);
        exit;
    }
    if (empty($sources)) {
        echo json_encode(['status' => 'success', 'siblings' => new stdClass()]);
        exit;
    }

    // Persona-scoped, so this can never be used to enumerate someone else's lines:
    // it returns exactly what the caller could already see on SLA Tracking.
    $lines = sla_rev_requestable_lines($pdo, $_SESSION);

    // Group once, then serve every source from the same index — a ticket edited on
    // several lines must not re-scan the whole list per line.
    $by_ticket = [];
    foreach ($lines as $it) {
        $by_ticket[$it['ticket']][] = $it;
    }

    $out = [];
    foreach ($sources as $src) {
        $ticket = trim((string)($src['ticket'] ?? ''));
        $line   = trim((string)($src['line'] ?? ''));
        $type   = trim((string)($src['type'] ?? 'date'));
        if ($ticket === '' || $line === '') continue;
        if (!in_array($type, sla_rev_requestable_types(), true)) $type = 'date';

        $key = sla_rev_line_key($ticket, $line);
        $out[$key] = [];

        foreach ($by_ticket[$ticket] ?? [] as $it) {
            if ($it['line_item'] === $line) continue; // the source line itself
            // Same ticket is not enough: the sibling must be free to file THIS
            // type of request, or ticking it would only produce a skip.
            $free = ($type === 'date') ? $it['can_edit_date'] : $it['can_edit_status'];
            if (!$free) continue;

            // Field names match what the recommendation table renders, so the
            // client can drop these rows straight into its markup.
            $out[$key][] = [
                'line_item'  => $it['line_item'],
                'item'       => $it['item'],
                'cno'        => $it['cno'],
                'd'          => $it['d'],
                'cv'         => $it['cv'],
                'status'     => $it['status'],
                'req_status' => $it['req_status'],
            ];
        }
    }

    // (object) so an all-empty result still encodes as {} rather than [].
    echo json_encode(['status' => 'success', 'siblings' => (object)$out]);
} catch (PDOException $e) {
    error_log('ajax_sla_siblings.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Failed to load the line item recommendations.']);
} catch (Throwable $e) {
    error_log('ajax_sla_siblings.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'A system error occurred. Please try again shortly.']);
}
