<?php
// includes/pr_status_helper.php
// Shared "Smart Status" classifier for prs rows, used by dashboard_pic.php,
// dashboard_admin.php and ajax_dashboard_data.php (Manager dashboard) so the
// Status column logic lives in exactly one place.

if (!function_exists('compute_pr_display_status')) {
    /**
     * @param string|null $status_ticket     Raw imported ticket status (e.g. "OS/WL", "OP").
     * @param string|null $canvassing_status Raw canvassing status.
     * @param string|null $po_number
     * @param string|null $canvassing_no
     * @return string One of: Canvassing Cancelled, Escalated, Sendback, Cancelled,
     *                 PO Created, On Progress, Outstanding.
     */
    function compute_pr_display_status($status_ticket, $canvassing_status, $po_number, $canvassing_no) {
        $raw_status = strtolower(trim((string)$status_ticket));
        $canvassing_status = strtolower(trim((string)$canvassing_status));
        $has_po   = !empty(trim((string)$po_number));
        $has_canv = !empty(trim((string)$canvassing_no));

        // Ticket Status codes from the source import are read as an authoritative
        // signal alongside the local canvassing_no / po_number presence, so a row
        // already flagged e.g. "OP" upstream shows On Progress even before its
        // canvassing record lands here.
        static $ticket_status_code_map = [
            'os/wl' => 'Outstanding',
            'os'    => 'Outstanding',
            'op'    => 'On Progress',
            'po'    => 'PO Created',
            'ccl'   => 'Cancelled',
        ];

        if (strpos($canvassing_status, 'cancel') !== false) {
            return 'Canvassing Cancelled';
        } elseif (strpos($raw_status, 'escalated') !== false) {
            return 'Escalated';
        } elseif (strpos($raw_status, 'reject') !== false || strpos($raw_status, 'revise') !== false) {
            return 'Sendback';
        } elseif (isset($ticket_status_code_map[$raw_status])) {
            return $ticket_status_code_map[$raw_status];
        } elseif ($has_po) {
            return 'PO Created';
        } elseif ($has_canv) {
            return 'On Progress';
        } else {
            return 'Outstanding';
        }
    }
}

if (!function_exists('pr_status_abbr')) {
    // Status column now shows the full display_status text (no more SAP-style
    // codes like OS/OP/PO/CCL) -- callers still get the label through this
    // function since it also carries the one exception below.
    //
    // $status_ticket (optional) refines the Outstanding bucket: a raw ticket
    // status of "Approve" or "OS/WL" still buckets as Outstanding (same color,
    // same filter button, same display_status) so nothing upstream breaks -- but
    // its badge reads "Outstanding/Worklist" instead of the plain "Outstanding",
    // so PICs can tell it apart from a plain outstanding ticket with no upstream
    // signal at all.
    function pr_status_abbr($display_status, $status_ticket = null) {
        $raw_status = strtolower(trim((string)$status_ticket));
        if ($display_status === 'Outstanding' && ($raw_status === 'approve' || $raw_status === 'approved' || $raw_status === 'os/wl')) {
            return 'Outstanding/Worklist';
        }
        return $display_status;
    }
}
