<?php
// pages/dashboard_admin.php
require_once '../includes/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'Admin') {
    header("Location: login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/sla_rules.php';
require_once '../includes/sla_alert.php';
$admin_name = $_SESSION['name'];

require_once '../includes/header.php';

// Fetch PRs assigned ONLY to the logged-in Admin
// ticket_type / p_group / tr_date_gp / canvassing_date_approver_1 are selected for
// the SLA column only (targets configured on settings_sla.php).
// ticket_line_item / sku / qty feed the AON classifier behind the SLA warning pop-up.
$stmt = $pdo->prepare("
    SELECT ticket_no, ticket_line_item, pr_date, item_description, status_ticket, requestor_name, p_org, canvassing_no, canvassing_status, canvassing_date, po_number, po_create_date, assigned_by_ai,
           ticket_type, p_group, tr_date_gp, canvassing_date_approver_1, canvassing_description, sku, qty, ticket_helpdesk_no
    FROM prs
    WHERE assigned_pic = ? AND LOWER(status_ticket) NOT LIKE '%cancel%' AND (pr_date >= '2026-05-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))
    ORDER BY pr_date DESC
");
$stmt->execute([$admin_name]);
$assigned_prs = $stmt->fetchAll();

sla_attach($pdo, $assigned_prs);

foreach ($assigned_prs as &$pr) {
    $has_canv = !empty(trim((string)$pr['canvassing_no']));
    $has_po   = !empty(trim((string)$pr['po_number']));
    $raw_status = strtolower(trim((string)$pr['status_ticket']));

    $canvassing_status = strtolower(trim((string)($pr['canvassing_status'] ?? '')));

    if (strpos($canvassing_status, 'cancel') !== false) {
        $pr['display_status'] = 'Canvassing Cancelled';
    } elseif (strpos($raw_status, 'escalated') !== false) {
        $pr['display_status'] = 'Escalated';
    } elseif (strpos($raw_status, 'reject') !== false || strpos($raw_status, 'revise') !== false) {
        $pr['display_status'] = 'Sendback';
    } elseif ($has_po) {
        $pr['display_status'] = 'PO Created';
    } elseif ($has_canv) {
        $pr['display_status'] = 'On Progress';
    } else {
        $pr['display_status'] = 'Outstanding';
    }
}
unset($pr);

// --- SLA WARNING ----------------------------------------------------------
// Open lines (Outstanding or On Progress) whose SLA badge has gone yellow or
// red, split into those four groups in the pop-up. Every ticket type counts,
// not just AON. Same pop-up the PICs get; it opens itself on load until
// dismissed. See includes/sla_alert.php.
$sla_alerts     = sla_alert_rows($assigned_prs);
$sla_autoshow   = !empty($sla_alerts) && sla_alert_should_autoshow();

$unique_tickets = array_unique(array_column($assigned_prs, 'ticket_no'));
$unique_porgs = array_unique(array_column($assigned_prs, 'p_org'));
$unique_requestors = array_unique(array_column($assigned_prs, 'requestor_name'));
$unique_statuses = array_unique(array_column($assigned_prs, 'display_status'));

sort($unique_tickets);
sort($unique_porgs);
sort($unique_requestors);
sort($unique_statuses);
?>

<?php
// Emitted here, ahead of the worklist markup and the table libraries, so the
// browser paints the warning immediately instead of after DataTables has built
// the table -- on an Admin account that is several thousand rows of work.
// See includes/sla_alert.php.
sla_alert_render($sla_alerts, $sla_autoshow);
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<style>
/* Global Search field -- same look as dashboard_mgr.php's #globalSearch. */
.filt-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; margin-bottom: 2px; }
.filt-input { padding: 5px 8px; font-size: 12px; line-height: 1.2; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #374151; width: 100%; min-width: 0; box-sizing: border-box; }
.filt-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
.col-trigger { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 6px; cursor: pointer; text-align: left; }
.col-trigger .col-trigger-label { color: #6b7280; }
.filt-cell { min-width: 0; }
/* PR Date range calendar (single popup) -- identical to dashboard_mgr.php's
   #prDateTrigger/#prDatePopup so the two dashboards behave the same way. Cells
   are laid out edge-to-edge with no column gap so the in-range band reads as
   one continuous stripe across a week; rounding is applied per-cell at the
   range's ends and at each row's edges instead. */
#prDatePopup, #canvDatePopup, #poDatePopup { width: 17rem; }
.pr-cal-nav, .canv-cal-nav, .po-cal-nav { display: flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; color: #4b5563; }
.pr-cal-nav:hover, .canv-cal-nav:hover, .po-cal-nav:hover { background: #eef2ff; color: #4f46e5; }
.pr-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); row-gap: 2px; }
.pr-cal-dow { text-align: center; font-size: 10px; font-weight: 600; color: #6b7280; padding: 4px 0; background: #f9fafb; }
.pr-cal-dow:first-of-type { border-radius: 6px 0 0 6px; }
.pr-cal-dow:nth-of-type(7) { border-radius: 0 6px 6px 0; }
.pr-cal-cell { position: relative; display: flex; align-items: center; justify-content: center; height: 2rem; font-size: 12px; color: #374151; cursor: pointer; background: transparent; }
.pr-cal-cell.is-outside { color: #d1d5db; }
.pr-cal-cell:hover:not(.is-edge) { background: #f3f4f6; border-radius: 9999px; }
.pr-cal-cell.is-inrange, .pr-cal-cell.is-range-start, .pr-cal-cell.is-range-end { background: #e0e7ff; }
.pr-cal-cell.is-inrange.row-start, .pr-cal-cell.is-range-start { border-radius: 9999px 0 0 9999px; }
.pr-cal-cell.is-inrange.row-end, .pr-cal-cell.is-range-end { border-radius: 0 9999px 9999px 0; }
.pr-cal-cell.is-single { background: transparent; }
.pr-cal-cell.is-edge::before { content: ''; position: absolute; inset: 0; margin: auto; width: 2rem; height: 2rem; border-radius: 9999px; background: #4f46e5; }
.pr-cal-cell.is-edge > span { position: relative; color: #fff; font-weight: 600; }
.pr-cal-cell.is-edge.is-single { border-radius: 9999px; }
.pr-cal-cell.is-today::after { content: ''; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 9999px; background: #6366f1; }
.pr-cal-cell.is-today.is-edge::after { display: none; }
/* Frozen table header + rounded scroll container -- mirrors dashboard_mgr.php's
   #prTable styling so every worklist reads as one consistent design. */
#adminTable thead th {
    position: sticky;
    top: 0;
    z-index: 15;
    background-color: #f3f4f6 !important;
    box-shadow: inset 0 -2px 0 0 #d1d5db, 0 1px 2px 0 rgba(0, 0, 0, .05);
}
#adminTableScroll {
    padding-left: 0;
    padding-right: 0;
    padding-top: 0;
    /* Bottom spacing now comes from #adminTableFooter's own padding -- keeping
       the old pb-6 here would leave a dead gap above the footer's divider. */
    padding-bottom: 0;
    border-top-left-radius: .5rem;
    border-top-right-radius: .5rem;
}
/* "Show entries" + Export + Global Search share one row ABOVE #adminTableScroll
   (#adminTableToolbar in the markup) instead of DataTables' default placement
   inside it -- previously "Show entries" scrolled out of view along with the
   rows and looked unstyled/cramped against the scrollbar. Only the length
   control is injected here on init; the rest of the row is static markup. */
#adminTableToolbar .dataTables_length {
    margin: 0;
    font-size: 12px;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
}
#adminTableToolbar .dataTables_length select {
    padding: 4px 22px 4px 8px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    color: #374151;
}
#adminTableToolbar .dataTables_length select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 1px #6366f1;
}
/* "Showing x to y" + pagination, pulled out of #adminTableScroll into their own
   static footer row (#adminTableFooter) below it -- left inside the scroll area
   they sat flush against the horizontal scrollbar and drifted with the rows.
   DataTables' stock rules are all written as `.dataTables_wrapper .dataTables_*`,
   so moving these two blocks outside #adminTable_wrapper drops every one of
   them (buttons collapse into unpadded, unclickable-looking text). The rules
   below are that same default styling re-scoped to the new parent -- the look
   is unchanged, only `float` gives way to the flex row. */
#adminTableFooter .dataTables_info {
    clear: both;
    float: none;
    /* Stock DataTables adds padding-top here purely to line the text up against
       the floated pager; the flex row's own centring does that job now. */
    padding-top: 0;
}
#adminTableFooter .dataTables_paginate {
    float: none;
    text-align: right;
    padding-top: 0;
}
#adminTableFooter .dataTables_paginate .paginate_button {
    box-sizing: border-box;
    display: inline-block;
    min-width: 1.5em;
    padding: .5em 1em;
    margin-left: 2px;
    text-align: center;
    text-decoration: none !important;
    cursor: pointer;
    color: #333 !important;
    border: 1px solid transparent;
    border-radius: 2px;
}
#adminTableFooter .dataTables_paginate .paginate_button.current,
#adminTableFooter .dataTables_paginate .paginate_button.current:hover {
    color: #333 !important;
    border: 1px solid #979797;
    background-color: #fff;
    background: linear-gradient(to bottom, #fff 0%, #dcdcdc 100%);
}
#adminTableFooter .dataTables_paginate .paginate_button.disabled,
#adminTableFooter .dataTables_paginate .paginate_button.disabled:hover,
#adminTableFooter .dataTables_paginate .paginate_button.disabled:active {
    cursor: default;
    color: #666 !important;
    border: 1px solid transparent;
    background: transparent;
    box-shadow: none;
}
#adminTableFooter .dataTables_paginate .paginate_button:hover {
    color: #fff !important;
    border: 1px solid #111;
    background-color: #585858;
    background: linear-gradient(to bottom, #585858 0%, #111 100%);
}
#adminTableFooter .dataTables_paginate .paginate_button:active {
    outline: none;
    background-color: #2b2b2b;
    background: linear-gradient(to bottom, #2b2b2b 0%, #0c0c0c 100%);
    box-shadow: inset 0 0 3px #111;
}
#adminTableFooter .dataTables_paginate .ellipsis {
    padding: 0 1em;
}
</style>

<div id="toast-container" class="fixed bottom-5 right-5 z-[9999] flex flex-col gap-3"></div>

<?php
$last_import = null;
try {
    date_default_timezone_set('Asia/Jakarta');
    $li = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_pr_upload_at'")->fetchColumn();
    if (!empty($li)) { $last_import = date('d M Y, H:i', strtotime($li)) . ' WIB'; }
} catch (PDOException $e) {}
?>
<div class="mb-6 flex flex-col md:flex-row md:justify-between md:items-end gap-4 border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Admin Worklist</h2>
        <p class="text-sm text-gray-600">Welcome, <?= htmlspecialchars($admin_name) ?>. Background AI re-routing enabled.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($last_import): ?>
        <div class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
            <span class="font-bold text-gray-600 uppercase tracking-wider">Last updated:</span>
            <span class="font-medium text-gray-700"><?= htmlspecialchars($last_import) ?></span>
        </div>
        <?php endif; ?>
        <?= sla_alert_button_html($sla_alerts) ?>
        <span class="bg-indigo-100 text-indigo-800 px-4 py-2 rounded-lg font-bold shadow-sm border border-indigo-200">Total: <span id="total-counter"><?= count($assigned_prs) ?></span></span>
    </div>
</div>

<div class="mb-6 flex flex-col gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-32 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by Status:</span>
        <button type="button" class="status-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="">
            All Statuses
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="Outstanding">
            Outstanding
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="On Progress">
            On Progress
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="PO Created">
            PO Created
        </button>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-32 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by SLA:</span>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="">
            All SLA
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="on_track">
            On Track
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="due_soon">
            Due Soon
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="overdue" title="Includes lines with no PR date, since their SLA countdown never started">
            Overdue
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="done" title="PO already created -- SLA countdown closed for this line">
            Done
        </button>
    </div>
</div>

<!-- COMPACT SEARCH / FILTER TOOLBAR -- same layout as dashboard_mgr.php's
     #prTable_filters, adapted to the columns actually available here (no
     Assigned PIC / Team / AON -- this worklist is already scoped to one
     Admin). -->
<div class="mb-6 bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-3 gap-y-3">
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Ticket No</label>
            <input type="text" data-col="0" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">PR Date</label>
            <button type="button" id="prDateTrigger" class="filt-input col-trigger">
                <span id="prDateLabel" class="col-trigger-label">All dates</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
            </button>
            <div id="prDatePopup" class="hidden absolute top-full left-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3">
                <div class="flex items-center justify-between mb-2">
                    <button type="button" class="pr-cal-nav" data-step="-1" aria-label="Previous month">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span id="prCalTitle" class="text-sm font-semibold text-gray-800"></span>
                    <button type="button" class="pr-cal-nav" data-step="1" aria-label="Next month">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
                <div id="prCalGrid" class="pr-cal-grid"></div>
                <div class="flex items-center gap-2 mt-3">
                    <button type="button" id="prDateCancel" class="flex-1 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                    <button type="button" id="prDateApply" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Terapkan</button>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">Requestor</label>
            <button type="button" class="filter-trigger filt-input col-trigger">
                <span class="col-trigger-label">All</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
            </button>
            <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-72 rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search Requestor..."></div>
                <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                <div class="p-2 max-h-48 overflow-y-auto checkbox-list">
                    <?php foreach ($unique_requestors as $val): ?>
                    <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer"><input type="checkbox" class="col-checkbox h-3 w-3 text-indigo-600" value="<?= htmlspecialchars($val) ?>"><span class="ml-2 text-xs text-gray-700"><?= htmlspecialchars($val) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="3" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Item Description</label>
            <input type="text" data-col="4" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Canvassing No</label>
            <input type="text" data-col="6" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">Status</label>
            <button type="button" class="filter-trigger filt-input col-trigger">
                <span class="col-trigger-label">All</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
            </button>
            <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-64 rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search Status..."></div>
                <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                <div class="p-2 max-h-48 overflow-y-auto checkbox-list">
                    <?php foreach ($unique_statuses as $val): ?>
                    <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer"><input type="checkbox" class="col-checkbox h-3 w-3 text-indigo-600" value="<?= htmlspecialchars($val) ?>"><span class="ml-2 text-xs text-gray-700"><?= htmlspecialchars($val) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="5" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">PO Number</label>
            <input type="text" data-col="7" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">P.ORG</label>
            <button type="button" class="filter-trigger filt-input col-trigger">
                <span class="col-trigger-label">All</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
            </button>
            <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-64 rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search P.ORG..."></div>
                <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                <div class="p-2 max-h-48 overflow-y-auto checkbox-list">
                    <?php foreach ($unique_porgs as $val): ?>
                    <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer"><input type="checkbox" class="col-checkbox h-3 w-3 text-indigo-600" value="<?= htmlspecialchars($val) ?>"><span class="ml-2 text-xs text-gray-700"><?= htmlspecialchars($val) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="2" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">Canvassing Date</label>
            <button type="button" id="canvDateTrigger" class="filt-input col-trigger">
                <span id="canvDateLabel" class="col-trigger-label">All dates</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
            </button>
            <div id="canvDatePopup" class="hidden absolute top-full right-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3">
                <div class="flex items-center justify-between mb-2">
                    <button type="button" class="canv-cal-nav" data-step="-1" aria-label="Previous month">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span id="canvCalTitle" class="text-sm font-semibold text-gray-800"></span>
                    <button type="button" class="canv-cal-nav" data-step="1" aria-label="Next month">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
                <div id="canvCalGrid" class="pr-cal-grid"></div>
                <div class="flex items-center gap-2 mt-3">
                    <button type="button" id="canvDateCancel" class="flex-1 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                    <button type="button" id="canvDateApply" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Terapkan</button>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">PO Date</label>
            <button type="button" id="poDateTrigger" class="filt-input col-trigger">
                <span id="poDateLabel" class="col-trigger-label">All dates</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
            </button>
            <div id="poDatePopup" class="hidden absolute top-full right-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3">
                <div class="flex items-center justify-between mb-2">
                    <button type="button" class="po-cal-nav" data-step="-1" aria-label="Previous month">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span id="poCalTitle" class="text-sm font-semibold text-gray-800"></span>
                    <button type="button" class="po-cal-nav" data-step="1" aria-label="Next month">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>
                <div id="poCalGrid" class="pr-cal-grid"></div>
                <div class="flex items-center gap-2 mt-3">
                    <button type="button" id="poDateCancel" class="flex-1 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                    <button type="button" id="poDateApply" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Terapkan</button>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">&nbsp;</label>
            <button type="button" id="clearAllFiltersBtn" class="filt-input bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold border-gray-300 transition">Clear All Filters</button>
        </div>
    </div>
</div>


<div class="bg-white shadow-md rounded-lg border border-gray-200 mb-8">
    <!-- Toolbar is authored here, in its final position, so the row looks the same
         before and after DataTables initialises. #adminToolbarLength is the empty
         slot DataTables' own "Show entries" control gets moved into on init. -->
    <div id="adminTableToolbar" class="flex flex-wrap items-center justify-between gap-3 px-6 pt-4 pb-3">
        <div id="adminToolbarLength"></div>
        <div class="flex items-center gap-3 pl-3" style="border-left: 1px solid #e5e7eb;">
            <div class="relative" id="prExportDropdownContainer">
                <button type="button" id="prExportBtn" class="group flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-white rounded-lg shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Export
                    <svg class="w-3 h-3 opacity-70 transition-transform duration-150" id="prExportChevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
                <div id="prExportMenu" class="hidden absolute left-0 mt-1.5 w-40 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                    <div class="px-4 py-2 border-b border-gray-100">
                        <label class="text-xs font-semibold text-gray-600 flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="prExportAllPages" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            All Pages
                        </label>
                    </div>
                    <div class="py-1">
                        <button type="button" id="prExportExcel" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                        <button type="button" id="prExportCSV" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                        <button type="button" id="prExportPDF" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                    </div>
                </div>
            </div>
            <input type="text" id="adminGlobalSearch" class="filt-input" placeholder="Search all columns..." style="width: 220px;">
        </div>
    </div>
    <div id="adminTableScroll" class="overflow-y-auto px-6 pb-6 pt-2" style="max-height: 750px;">
        <table id="adminTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[98px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Sorted by PR Date -- shown under the ticket number">Ticket No / PR Date</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-2 py-3 border-b-2 border-gray-300 align-top shadow-sm w-[61px] min-w-[61px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Days remaining against this ticket's SLA target, counted from the PR date">SLA</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[54px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">P.ORG</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[153px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Requestor</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[138px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Item Description</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[94px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Status</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[128px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Sorted by Canvassing No -- Canvassing Date is shown underneath">Canvassing No / Date</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[119px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">PO Number</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[120px] text-right">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Actions</div>
                    </th>
                    <th class="hidden">PR Date</th><!-- hidden; export-only column, split out from Ticket No -->
                    <th class="hidden">Canvassing Date</th><!-- hidden; export-only column, split out from Canvassing No -->
                    <th class="hidden">PO Date</th><!-- hidden; export-only column, split out from PO Number -->
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Ticket Helpdesk No. from the K2 export">Helpdesk No.</div>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assigned_prs as $pr): ?>
                    <tr id="row-<?= htmlspecialchars($pr['ticket_no']) ?>-<?= htmlspecialchars($pr['ticket_line_item']) ?>" class="hover:bg-indigo-50 transition border-b border-gray-100" data-assigned-by-ai="<?= (int)$pr['assigned_by_ai'] ?>" data-line="<?= htmlspecialchars($pr['ticket_line_item']) ?>">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900" data-order="<?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?>" data-ticket="<?= htmlspecialchars($pr['ticket_no']) ?>">
                            <?= htmlspecialchars($pr['ticket_no']) ?>
                            <?php if ($pr['pr_date']): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['pr_date'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-3 text-sm text-center" data-order="<?= (int)$pr['sla_sort'] ?>" data-search="<?= htmlspecialchars($pr['sla_status']) ?>">
                            <span class="<?= $pr['sla_badge'] ?> py-1 px-1 rounded text-[10px] font-bold border block w-fit min-w-[53px] mx-auto text-center whitespace-nowrap" title="<?= htmlspecialchars($pr['sla_title']) ?>"><?= htmlspecialchars($pr['sla_label']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($pr['p_org']) ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div class="truncate max-w-[160px]" title="<?= htmlspecialchars($pr['requestor_name']) ?>"><?= htmlspecialchars($pr['requestor_name']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div class="truncate max-w-xs" title="<?= htmlspecialchars($pr['item_description']) ?>">
                                <?= htmlspecialchars($pr['item_description']) ?>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm" data-search="<?= htmlspecialchars($pr['display_status']) ?>">
                            <?php
                                $statusClass = 'bg-gray-100 text-gray-800 border-gray-200';
                                if ($pr['display_status'] === 'Outstanding') $statusClass = 'bg-red-100 text-red-800 border-red-200';
                                if ($pr['display_status'] === 'Escalated') $statusClass = 'bg-orange-100 text-orange-800 border-orange-200';
                                if ($pr['display_status'] === 'Sendback') $statusClass = 'bg-purple-100 text-purple-800 border-purple-200';
                                if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';
                                if ($pr['display_status'] === 'On Progress') $statusClass = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                                if ($pr['display_status'] === 'PO Created') $statusClass = 'bg-green-100 text-green-800 border-green-200';
                            ?>
                            <span class="<?= $statusClass ?> py-1 px-2 rounded-md text-[10px] font-bold border inline-block w-full text-center">
                                <?= htmlspecialchars($pr['display_status']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700" data-order="<?= $pr['canvassing_date'] ? date('Y-m-d', strtotime($pr['canvassing_date'])) : '' ?>" data-canv="<?= htmlspecialchars($pr['canvassing_no'] ?: '--') ?>">
                            <?= htmlspecialchars($pr['canvassing_no'] ?: '--') ?>
                            <?php if ($pr['canvassing_date']): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['canvassing_date'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700 font-medium" data-po="<?= htmlspecialchars($pr['po_number'] ?: '--') ?>">
                            <?= htmlspecialchars($pr['po_number'] ?: '--') ?>
                            <?php if (!empty($pr['po_number']) && !empty($pr['po_create_date'])): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['po_create_date'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-right">
                            <button type="button"
                                    class="reassign-btn inline-flex items-center text-[11px] font-bold text-indigo-700 bg-indigo-50 hover:bg-indigo-600 hover:text-white border border-indigo-200 px-2 py-1.5 rounded transition-all shadow-sm focus:outline-none"
                                    data-ticket="<?= htmlspecialchars($pr['ticket_no']) ?>">
                                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                                Re-route via AI
                            </button>
                        </td>
                        <td class="hidden"><?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?></td>
                        <td class="hidden"><?= $pr['canvassing_date'] ? date('Y-m-d', strtotime($pr['canvassing_date'])) : '' ?></td>
                        <td class="hidden"><?= (!empty($pr['po_number']) && !empty($pr['po_create_date'])) ? date('Y-m-d', strtotime($pr['po_create_date'])) : '' ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars(trim((string)($pr['ticket_helpdesk_no'] ?? '')) !== '' ? $pr['ticket_helpdesk_no'] : '--') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Footer slots: DataTables' info + pagination get moved in here on init so
         they sit below the scroll area instead of inside it. -->
    <div id="adminTableFooter" class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 border-t border-gray-200">
        <div id="adminFooterInfo"></div>
        <div id="adminFooterPaginate"></div>
    </div>
</div>

<div id="reassignModal" class="fixed z-50 inset-0 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-slate-900 bg-opacity-50 transition-opacity backdrop-blur-sm" id="modalOverlay"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full border border-slate-100">
            <input type="hidden" id="modal-ticket-input">
            
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 class="text-lg leading-6 font-bold text-slate-800" id="modal-title">Trigger AI Re-assignment?</h3>
                        <div class="mt-3">
                            <p class="text-sm text-slate-600 leading-relaxed">
                                AI will analyze Ticket <strong id="modal-ticket-display" class="text-indigo-600 font-mono px-1 bg-indigo-50 rounded"></strong> based on the latest Taxonomy and automatically assign it to the correct PIC.
                            </p>
                            <div class="mt-4 bg-amber-50 p-3 rounded-lg border border-amber-200">
                                <p class="text-xs text-amber-800 font-medium">
                                    ⚠️ This action will apply to <span class="underline">all line items</span> contained within this ticket number to maintain the single-PIC rule.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-slate-50 px-4 py-4 sm:px-6 flex flex-col sm:flex-row-reverse gap-2 sm:gap-3 border-t border-slate-100">
                <button type="button" id="confirmAiBtn" class="w-full inline-flex justify-center items-center rounded-lg border border-transparent shadow-sm px-4 py-2.5 bg-indigo-600 text-sm font-bold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto transition-colors">
                    Confirm & Start AI
                </button>
                <button type="button" id="closeModalBtn" class="w-full inline-flex justify-center rounded-lg border border-slate-300 shadow-sm px-4 py-2.5 bg-white text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Ticket No + PR Date are merged into one column (same as dashboard_mgr.php):
    // the cell displays the ticket number with the PR date beneath it, sorting
    // still runs off the PR date via the cell's data-order attribute, and search/
    // export both use only the plain ticket number (data-ticket) so the date text
    // never leaks into a "Search Ticket..." match or the exported Ticket No cell.
    function ticketCellRender(data, type, row, meta) {
        if (type === 'filter' || type === 'export') {
            var $td = $(meta.settings.aoData[meta.row].nTr).find('td').eq(meta.col);
            return $td.attr('data-ticket') || '';
        }
        return data;
    }

    // Canvassing No + Canvassing Date are merged into one column the same way as
    // Ticket No + PR Date above: date shown beneath the number, search/export use
    // only the plain canvassing no (data-canv) so the date text doesn't leak in.
    function canvCellRender(data, type, row, meta) {
        if (type === 'filter' || type === 'export') {
            var $td = $(meta.settings.aoData[meta.row].nTr).find('td').eq(meta.col);
            return $td.attr('data-canv') || '';
        }
        return data;
    }

    // PO Number + PO Create Date, same merged-cell treatment as the two above.
    function poCellRender(data, type, row, meta) {
        if (type === 'filter' || type === 'export') {
            var $td = $(meta.settings.aoData[meta.row].nTr).find('td').eq(meta.col);
            return $td.attr('data-po') || '';
        }
        return data;
    }

    // --- PR Date range picker -- identical behaviour to dashboard_mgr.php's own
    // #prDateTrigger/#prDatePopup, just filtering the already-rendered client-side
    // table instead of driving an AJAX request. Picking days only moves a DRAFT
    // range; nothing reaches currentPrDateFrom/currentPrDateTo (and therefore no
    // redraw fires) until "Terapkan".
    var PR_MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var PR_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    var PR_DOW = ['Sen','Sel','Rab','Kam','Jum','Sab','Min'];   // week starts Monday
    var currentPrDateFrom = '';  // 'YYYY-MM-DD' or ''
    var currentPrDateTo   = '';  // 'YYYY-MM-DD' or ''
    var prDraftFrom = '', prDraftTo = '';
    var prCalYear, prCalMonth;
    var prToday = (function() {
        var n = new Date();
        return prIso(n.getFullYear(), n.getMonth(), n.getDate());
    })();

    function prIso(y, m, d) {
        return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }
    // 'YYYY-MM-DD' -> '16 Jul 2026'. Parsed by hand rather than via new Date(iso),
    // which would read the string as UTC and can shift the day in WIB.
    function prPretty(iso) {
        var p = iso.split('-');
        return parseInt(p[2], 10) + ' ' + PR_MONTHS_SHORT[parseInt(p[1], 10) - 1] + ' ' + p[0];
    }
    function prSyncLabel() {
        var $l = $('#prDateLabel');
        if (!currentPrDateFrom && !currentPrDateTo) { $l.text('All dates'); return; }
        if (currentPrDateFrom === currentPrDateTo) { $l.text(prPretty(currentPrDateFrom)); return; }
        $l.text(prPretty(currentPrDateFrom) + ' – ' + prPretty(currentPrDateTo));
    }

    function prRenderCalendar() {
        $('#prCalTitle').text(PR_MONTHS[prCalMonth] + ' ' + prCalYear);

        var html = '';
        PR_DOW.forEach(function(d) { html += '<div class="pr-cal-dow">' + d + '</div>'; });

        var firstDow  = (new Date(prCalYear, prCalMonth, 1).getDay() + 6) % 7;  // Mon = 0
        var daysInMon = new Date(prCalYear, prCalMonth + 1, 0).getDate();
        var daysInPrv = new Date(prCalYear, prCalMonth, 0).getDate();
        var lo = prDraftFrom, hi = prDraftTo || prDraftFrom;

        for (var i = 0; i < 42; i++) {
            var dayNum, outside = true, iso = '';
            if (i < firstDow) {
                dayNum = daysInPrv - firstDow + 1 + i;
            } else if (i - firstDow < daysInMon) {
                dayNum = i - firstDow + 1;
                outside = false;
                iso = prIso(prCalYear, prCalMonth, dayNum);
            } else {
                dayNum = i - firstDow - daysInMon + 1;
            }

            var cls = 'pr-cal-cell';
            if (outside) {
                cls += ' is-outside';
            } else {
                if (iso === prToday) cls += ' is-today';
                if (lo && iso === lo && iso === hi)      cls += ' is-edge is-single';
                else if (lo && iso === lo)               cls += ' is-edge is-range-start';
                else if (hi && iso === hi)               cls += ' is-edge is-range-end';
                else if (lo && hi && iso > lo && iso < hi) {
                    cls += ' is-inrange';
                    if (i % 7 === 0) cls += ' row-start';
                    if (i % 7 === 6) cls += ' row-end';
                }
            }
            html += '<div class="' + cls + '"' + (outside ? '' : ' data-iso="' + iso + '"') + '><span>' + dayNum + '</span></div>';
        }
        $('#prCalGrid').html(html);
    }

    function prOpenPicker() {
        prDraftFrom = currentPrDateFrom;
        prDraftTo   = currentPrDateTo;
        var anchor = currentPrDateFrom || prToday;
        prCalYear  = parseInt(anchor.split('-')[0], 10);
        prCalMonth = parseInt(anchor.split('-')[1], 10) - 1;
        prRenderCalendar();
        $('#prDatePopup').removeClass('hidden');
    }

    // --- Canvassing Date range picker -- the same widget as PR Date above
    // (reusing PR_MONTHS/PR_DOW and the prIso/prPretty helpers), with its own
    // from/to/draft state and DOM ids so the two pickers never clash.
    var currentCanvDateFrom = '';  // 'YYYY-MM-DD' or ''
    var currentCanvDateTo   = '';  // 'YYYY-MM-DD' or ''
    var canvDraftFrom = '', canvDraftTo = '';
    var canvCalYear, canvCalMonth;

    function canvSyncLabel() {
        var $l = $('#canvDateLabel');
        if (!currentCanvDateFrom && !currentCanvDateTo) { $l.text('All dates'); return; }
        if (currentCanvDateFrom === currentCanvDateTo) { $l.text(prPretty(currentCanvDateFrom)); return; }
        $l.text(prPretty(currentCanvDateFrom) + ' – ' + prPretty(currentCanvDateTo));
    }

    function canvRenderCalendar() {
        $('#canvCalTitle').text(PR_MONTHS[canvCalMonth] + ' ' + canvCalYear);

        var html = '';
        PR_DOW.forEach(function(d) { html += '<div class="pr-cal-dow">' + d + '</div>'; });

        var firstDow  = (new Date(canvCalYear, canvCalMonth, 1).getDay() + 6) % 7;  // Mon = 0
        var daysInMon = new Date(canvCalYear, canvCalMonth + 1, 0).getDate();
        var daysInPrv = new Date(canvCalYear, canvCalMonth, 0).getDate();
        var lo = canvDraftFrom, hi = canvDraftTo || canvDraftFrom;

        for (var i = 0; i < 42; i++) {
            var dayNum, outside = true, iso = '';
            if (i < firstDow) {
                dayNum = daysInPrv - firstDow + 1 + i;
            } else if (i - firstDow < daysInMon) {
                dayNum = i - firstDow + 1;
                outside = false;
                iso = prIso(canvCalYear, canvCalMonth, dayNum);
            } else {
                dayNum = i - firstDow - daysInMon + 1;
            }

            var cls = 'pr-cal-cell';
            if (outside) {
                cls += ' is-outside';
            } else {
                if (iso === prToday) cls += ' is-today';
                if (lo && iso === lo && iso === hi)      cls += ' is-edge is-single';
                else if (lo && iso === lo)               cls += ' is-edge is-range-start';
                else if (hi && iso === hi)               cls += ' is-edge is-range-end';
                else if (lo && hi && iso > lo && iso < hi) {
                    cls += ' is-inrange';
                    if (i % 7 === 0) cls += ' row-start';
                    if (i % 7 === 6) cls += ' row-end';
                }
            }
            html += '<div class="' + cls + '"' + (outside ? '' : ' data-iso="' + iso + '"') + '><span>' + dayNum + '</span></div>';
        }
        $('#canvCalGrid').html(html);
    }

    function canvOpenPicker() {
        canvDraftFrom = currentCanvDateFrom;
        canvDraftTo   = currentCanvDateTo;
        var anchor = currentCanvDateFrom || prToday;
        canvCalYear  = parseInt(anchor.split('-')[0], 10);
        canvCalMonth = parseInt(anchor.split('-')[1], 10) - 1;
        canvRenderCalendar();
        $('#canvDatePopup').removeClass('hidden');
    }

    // --- PO Date range picker -- same widget as Canvassing Date above.
    var currentPoDateFrom = '';  // 'YYYY-MM-DD' or ''
    var currentPoDateTo   = '';  // 'YYYY-MM-DD' or ''
    var poDraftFrom = '', poDraftTo = '';
    var poCalYear, poCalMonth;

    function poSyncLabel() {
        var $l = $('#poDateLabel');
        if (!currentPoDateFrom && !currentPoDateTo) { $l.text('All dates'); return; }
        if (currentPoDateFrom === currentPoDateTo) { $l.text(prPretty(currentPoDateFrom)); return; }
        $l.text(prPretty(currentPoDateFrom) + ' – ' + prPretty(currentPoDateTo));
    }

    function poRenderCalendar() {
        $('#poCalTitle').text(PR_MONTHS[poCalMonth] + ' ' + poCalYear);

        var html = '';
        PR_DOW.forEach(function(d) { html += '<div class="pr-cal-dow">' + d + '</div>'; });

        var firstDow  = (new Date(poCalYear, poCalMonth, 1).getDay() + 6) % 7;  // Mon = 0
        var daysInMon = new Date(poCalYear, poCalMonth + 1, 0).getDate();
        var daysInPrv = new Date(poCalYear, poCalMonth, 0).getDate();
        var lo = poDraftFrom, hi = poDraftTo || poDraftFrom;

        for (var i = 0; i < 42; i++) {
            var dayNum, outside = true, iso = '';
            if (i < firstDow) {
                dayNum = daysInPrv - firstDow + 1 + i;
            } else if (i - firstDow < daysInMon) {
                dayNum = i - firstDow + 1;
                outside = false;
                iso = prIso(poCalYear, poCalMonth, dayNum);
            } else {
                dayNum = i - firstDow - daysInMon + 1;
            }

            var cls = 'pr-cal-cell';
            if (outside) {
                cls += ' is-outside';
            } else {
                if (iso === prToday) cls += ' is-today';
                if (lo && iso === lo && iso === hi)      cls += ' is-edge is-single';
                else if (lo && iso === lo)               cls += ' is-edge is-range-start';
                else if (hi && iso === hi)               cls += ' is-edge is-range-end';
                else if (lo && hi && iso > lo && iso < hi) {
                    cls += ' is-inrange';
                    if (i % 7 === 0) cls += ' row-start';
                    if (i % 7 === 6) cls += ' row-end';
                }
            }
            html += '<div class="' + cls + '"' + (outside ? '' : ' data-iso="' + iso + '"') + '><span>' + dayNum + '</span></div>';
        }
        $('#poCalGrid').html(html);
    }

    function poOpenPicker() {
        poDraftFrom = currentPoDateFrom;
        poDraftTo   = currentPoDateTo;
        var anchor = currentPoDateFrom || prToday;
        poCalYear  = parseInt(anchor.split('-')[0], 10);
        poCalMonth = parseInt(anchor.split('-')[1], 10) - 1;
        poRenderCalendar();
        $('#poDatePopup').removeClass('hidden');
    }

    // Export header labels. On screen these two columns merge two values, so the
    // header reads "Ticket No / PR Date" and "Canvassing No / Date". In the export
    // the date is a column of its own, which makes the merged label wrong -- both
    // get renamed back to the plain field name. Every other column keeps its own
    // header text with markup stripped, which is what Buttons' default does.
    var EXPORT_HEADER = { 0: 'Ticket No', 6: 'Canvassing No' };
    function exportHeader(data, columnIdx) {
        if (EXPORT_HEADER[columnIdx]) return EXPORT_HEADER[columnIdx];
        return $('<div>').html(data).text().trim();
    }

    // 1. Inisialisasi DataTables
    var table = $('#adminTable').DataTable({
        "pageLength": 25,
        "order": [[ 0, "desc" ]],
        "language": { "search": "Quick Search:" },
        "orderCellsTop": true,
        "columnDefs": [
            { "orderable": false, "targets": [8, 9, 10, 11] },
            { "targets": 0, "render": ticketCellRender }, // Ticket No (+ PR Date beneath)
            { "targets": 6, "render": canvCellRender },   // Canvassing No (+ Canvassing Date beneath)
            { "targets": 7, "render": poCellRender }      // PO Number (+ PO Create Date beneath)
        ],
        "dom": '<"hidden"B>ltip',
        "buttons": [
            // modifier.page pinned to the literal string 'current' -- Buttons' own
            // default omits `page`, which DataTables' core row-selector then defaults
            // to 'all' (every row matching search, ignoring pagination). This table is
            // client-side (every row already sits in the DOM), so that default would
            // silently export everything regardless of what's on screen.
            // Column order here is the EXPORT order, not the DOM order -- PR Date (9),
            // Canvassing Date (10) and PO Date (11), all hidden, sit right after their
            // merged visible column so the file reads naturally.
            { extend: 'excelHtml5', exportOptions: { columns: [0, 9, 1, 2, 3, 4, 5, 6, 10, 7, 11, 12], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } },
            { extend: 'csvHtml5', exportOptions: { columns: [0, 9, 1, 2, 3, 4, 5, 6, 10, 7, 11, 12], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } },
            { extend: 'pdfHtml5', orientation: 'landscape', exportOptions: { columns: [0, 9, 1, 2, 3, 4, 5, 6, 10, 7, 11, 12], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } }
        ]
    });

    // #adminTableToolbar (with Export + Global Search inside it) is authored
    // straight into the HTML above #adminTableScroll, so the row renders in its
    // final position immediately -- nothing shifts once DataTables finishes.
    // "Show entries" is the one control that can't be pre-authored (DataTables
    // creates it during init), so it's moved -- not cloned -- into the empty
    // #adminToolbarLength slot, keeping its own length listener intact. Without
    // this it would render inside the scrolling area and scroll out of view.
    $('#adminTable_wrapper .dataTables_length').appendTo('#adminToolbarLength');
    // Same treatment for the footer: "Showing x to y of z" and the pager are
    // moved below the scroll area so they no longer sit against the horizontal
    // scrollbar. Moved (not cloned) so DataTables keeps redrawing them in place.
    $('#adminTable_wrapper .dataTables_info').appendTo('#adminFooterInfo');
    $('#adminTable_wrapper .dataTables_paginate').appendTo('#adminFooterPaginate');

    // --- Export (Excel / CSV / PDF) -- whatever's currently filtered/searched
    // in the table rides along automatically since it's the same `table` instance.
    $('#prExportBtn').on('click', function(e) {
        e.stopPropagation();
        $('#prExportMenu').toggleClass('hidden');
        $('#prExportChevron').toggleClass('rotate-180');
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#prExportDropdownContainer').length) {
            $('#prExportMenu').addClass('hidden');
            $('#prExportChevron').removeClass('rotate-180');
        }
    });
    function triggerPrExport(type) {
        if ($('#prExportAllPages').is(':checked')) {
            // Buttons' modifier.page stays 'current' -- instead, temporarily make
            // "current page" mean every filtered row, so it naturally grabs it all.
            var oldLen = table.page.len();
            table.one('draw', function() {
                table.button('.buttons-' + type).trigger();
                table.page.len(oldLen).draw();
            });
            table.page.len(-1).draw();
        } else {
            table.button('.buttons-' + type).trigger();
        }
        $('#prExportMenu').addClass('hidden');
        $('#prExportChevron').removeClass('rotate-180');
    }
    $('#prExportExcel').on('click', function() { triggerPrExport('excel'); });
    $('#prExportCSV').on('click', function() { triggerPrExport('csv'); });
    $('#prExportPDF').on('click', function() { triggerPrExport('pdf'); });

    // 2. Custom Filter Logic untuk filter SLA
    var currentSlaFilter = '';
    // 'na' rows (no PR date, countdown never started) are folded into Overdue --
    // same rule dashboard_mgr.php uses -- so there's no separate N/A button.
    //
    // ...except when the SLA pop-up drove the filter. It counts 'na' as neither
    // colour (a grey badge is not a red one), so folding here would answer a
    // click on "116 lines" with more rows than that. Any later click on a filter
    // button by hand clears the flag and restores the normal folding.
    var slaAlertExact    = false;
    // Set while the pop-up applies BOTH quick filters at once, so the two
    // handlers restyle their buttons without each firing its own redraw.
    var slaAlertApplying = false;
    var SLA_FILTER_ACTIVE_CLASSES = 'bg-indigo-600 bg-green-600 bg-yellow-500 bg-red-600 bg-gray-500 ' +
        'hover:bg-indigo-700 hover:bg-green-700 hover:bg-yellow-600 hover:bg-red-700 hover:bg-gray-600 ' +
        'text-white border-indigo-600 border-green-600 border-yellow-500 border-red-600 border-gray-500 ' +
        'shadow-md ring-2 ring-offset-2';
    var SLA_FILTER_STYLE = {
        '':         'bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600',
        'on_track': 'bg-green-600 hover:bg-green-700 text-white border-green-600',
        'due_soon': 'bg-yellow-500 hover:bg-yellow-600 text-white border-yellow-500',
        'overdue':  'bg-red-600 hover:bg-red-700 text-white border-red-600',
        'done':     'bg-gray-500 hover:bg-gray-600 text-white border-gray-500'
    };

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        if (currentSlaFilter) {
            var slaRow = $(settings.aoData[dataIndex].nTr);
            var rowSla = slaRow.find('td').eq(1).attr('data-search') || '';
            if (rowSla === 'na' && !slaAlertExact) rowSla = 'overdue';
            if (rowSla !== currentSlaFilter) return false;
        }
        if (currentPrDateFrom || currentPrDateTo) {
            // PR date lives on the merged Ticket No cell's data-order attribute
            // (col 0) since PR Date no longer has its own visible column.
            var dateRow = $(settings.aoData[dataIndex].nTr);
            var rowDate = dateRow.find('td').eq(0).attr('data-order') || '';
            if (!rowDate) return false;
            if (currentPrDateFrom && rowDate < currentPrDateFrom) return false;
            if (currentPrDateTo && rowDate > currentPrDateTo) return false;
        }
        if (currentCanvDateFrom || currentCanvDateTo) {
            // Canvassing date lives on the merged Canvassing No cell's data-order
            // attribute (col 6) since Canvassing Date has no visible column of
            // its own. Rows with no canvassing date can't match a date range.
            var canvRow = $(settings.aoData[dataIndex].nTr);
            var rowCanvDate = canvRow.find('td').eq(6).attr('data-order') || '';
            if (!rowCanvDate) return false;
            if (currentCanvDateFrom && rowCanvDate < currentCanvDateFrom) return false;
            if (currentCanvDateTo && rowCanvDate > currentCanvDateTo) return false;
        }
        if (currentPoDateFrom || currentPoDateTo) {
            // Hidden PO Date column (col 11) -- empty when the row has no PO number.
            var rowPoDate = $(settings.aoData[dataIndex].nTr).find('td').eq(11).text().trim();
            if (!rowPoDate) return false;
            if (currentPoDateFrom && rowPoDate < currentPoDateFrom) return false;
            if (currentPoDateTo && rowPoDate > currentPoDateTo) return false;
        }
        return true;
    });

    // 3. AUTO-APPLY STATUS FILTERS
    $('.status-filter-btn').on('click', function() {
        if (!slaAlertApplying) slaAlertExact = false;   // a filter picked by hand folds 'na' as usual
        $('.status-filter-btn').removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 hover:bg-indigo-700 hover:bg-red-700 hover:bg-yellow-600 hover:bg-green-700 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');

        var status = $(this).data('status');

        if (status === 'Outstanding') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-red-600 hover:bg-red-700 text-white border-red-600 shadow-md ring-2 ring-offset-2');
        } else if (status === 'On Progress') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-yellow-500 hover:bg-yellow-600 text-white border-yellow-500 shadow-md ring-2 ring-offset-2');
        } else if (status === 'PO Created') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-green-600 hover:bg-green-700 text-white border-green-600 shadow-md ring-2 ring-offset-2');
        } else {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        }
        if (status) {
            // MENGGUNAKAN SMART SEARCH (false, true) AGAR BISA MEMBACA SPASI HTML
            table.column(5).search(status, false, true);
        } else {
            table.column(5).search('', false, false);
        }
        if (!slaAlertApplying) table.draw();
    });

    // 3b. AUTO-APPLY SLA FILTERS
    $('.sla-filter-btn').on('click', function() {
        if (!slaAlertApplying) slaAlertExact = false;
        var sla = $(this).data('sla') || '';
        $('.sla-filter-btn').removeClass(SLA_FILTER_ACTIVE_CLASSES).addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        currentSlaFilter = sla;
        $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
            .addClass((SLA_FILTER_STYLE[sla] || SLA_FILTER_STYLE['']) + ' shadow-md ring-2 ring-offset-2');
        if (!slaAlertApplying) table.draw();
    });

    // --- SLA pop-up hand-off ---------------------------------------------
    // Called when one of the four blocks in the pop-up is clicked
    // (includes/sla_alert.php). Reuses the page's own quick-filter buttons
    // rather than a private filter of its own, so the toolbar visibly shows
    // what the table is narrowed to and the usual "All Statuses" / "All SLA"
    // buttons clear it. Both filters are applied under slaAlertApplying so the
    // table redraws once at the end instead of after each.
    window.slaAlertApplyFilter = function(status, sla) {
        slaAlertApplying = true;
        $('.status-filter-btn[data-status="' + status + '"]').trigger('click');
        $('.sla-filter-btn[data-sla="' + sla + '"]').trigger('click');
        slaAlertApplying = false;
        slaAlertExact = true;                  // count 'na' as neither colour -- see above
        table.draw();
        // The scroll container on this layout is <main>, not the window
        // (includes/header.php puts overflow-hidden on <body>), so scrolling
        // html/body here would do nothing.
        var $t = $('#adminTable'), $scroller = $t.closest('main');
        if ($scroller.length) {
            $scroller.animate({ scrollTop: $scroller.scrollTop() + $t.offset().top - $scroller.offset().top - 16 }, 250);
        }
    };
    // The worklist is built and interactive -- the true "page has finished
    // loading" moment, so let the pop-up hand its cue over now.
    if (window.slaAlert) window.slaAlert.ready();

    // 4. HEADER TEXT INPUT FILTERS (BAGIAN YANG HILANG SEBELUMNYA)
    $('.column-search-text').on('keyup change clear', function(e) {
        e.stopPropagation();
        table.column($(this).data('col')).search(this.value).draw();
    });

    // 4b. GLOBAL SEARCH -- replaces DataTables' default "Search:" box, same as dashboard_mgr.php
    $('#adminGlobalSearch').on('keyup change clear', function() {
        table.search(this.value).draw();
    });

    // 4c. PR DATE RANGE PICKER -- same trigger/popup wiring as dashboard_mgr.php
    $('#prDateTrigger').on('click', function(e) {
        e.stopPropagation();
        $('.filter-dropdown').addClass('hidden');
        $('#canvDatePopup').addClass('hidden');
        $('#poDatePopup').addClass('hidden');
        if ($('#prDatePopup').hasClass('hidden')) prOpenPicker();
        else $('#prDatePopup').addClass('hidden');
    });
    $('#prDatePopup').on('click', function(e) { e.stopPropagation(); });
    $(document).on('click', function() { $('#prDatePopup').addClass('hidden'); });

    $('.pr-cal-nav').on('click', function() {
        prCalMonth += parseInt($(this).data('step'), 10);
        if (prCalMonth < 0)  { prCalMonth = 11; prCalYear--; }
        if (prCalMonth > 11) { prCalMonth = 0;  prCalYear++; }
        prRenderCalendar();
    });

    $('#prCalGrid').on('click', '.pr-cal-cell[data-iso]', function() {
        var iso = $(this).data('iso');
        if (!prDraftFrom || prDraftTo) {            // start a fresh range
            prDraftFrom = iso; prDraftTo = '';
        } else if (iso < prDraftFrom) {             // clicked before the start -- swap
            prDraftTo = prDraftFrom; prDraftFrom = iso;
        } else {
            prDraftTo = iso;
        }
        prRenderCalendar();
    });

    $('#prDateApply').on('click', function() {
        currentPrDateFrom = prDraftFrom;
        // One tap = that single day, so the range stays inclusive on both ends.
        currentPrDateTo   = prDraftTo || prDraftFrom;
        prSyncLabel();
        $('#prDatePopup').addClass('hidden');
        table.draw();
    });

    $('#prDateCancel').on('click', function() {
        $('#prDatePopup').addClass('hidden');
    });

    // 4c-2. CANVASSING DATE RANGE PICKER -- identical wiring to PR Date above,
    // pointed at the canv* state and DOM ids. Opening either picker closes the
    // other one (and any open dropdown) so only one popup is ever on screen.
    $('#canvDateTrigger').on('click', function(e) {
        e.stopPropagation();
        $('.filter-dropdown').addClass('hidden');
        $('#prDatePopup').addClass('hidden');
        $('#poDatePopup').addClass('hidden');
        if ($('#canvDatePopup').hasClass('hidden')) canvOpenPicker();
        else $('#canvDatePopup').addClass('hidden');
    });
    $('#canvDatePopup').on('click', function(e) { e.stopPropagation(); });
    $(document).on('click', function() { $('#canvDatePopup').addClass('hidden'); });

    $('.canv-cal-nav').on('click', function() {
        canvCalMonth += parseInt($(this).data('step'), 10);
        if (canvCalMonth < 0)  { canvCalMonth = 11; canvCalYear--; }
        if (canvCalMonth > 11) { canvCalMonth = 0;  canvCalYear++; }
        canvRenderCalendar();
    });

    $('#canvCalGrid').on('click', '.pr-cal-cell[data-iso]', function() {
        var iso = $(this).data('iso');
        if (!canvDraftFrom || canvDraftTo) {            // start a fresh range
            canvDraftFrom = iso; canvDraftTo = '';
        } else if (iso < canvDraftFrom) {               // clicked before the start -- swap
            canvDraftTo = canvDraftFrom; canvDraftFrom = iso;
        } else {
            canvDraftTo = iso;
        }
        canvRenderCalendar();
    });

    $('#canvDateApply').on('click', function() {
        currentCanvDateFrom = canvDraftFrom;
        // One tap = that single day, so the range stays inclusive on both ends.
        currentCanvDateTo   = canvDraftTo || canvDraftFrom;
        canvSyncLabel();
        $('#canvDatePopup').addClass('hidden');
        table.draw();
    });

    $('#canvDateCancel').on('click', function() {
        $('#canvDatePopup').addClass('hidden');
    });

    // 4c-3. PO DATE RANGE PICKER -- identical wiring, pointed at the po* state.
    $('#poDateTrigger').on('click', function(e) {
        e.stopPropagation();
        $('.filter-dropdown').addClass('hidden');
        $('#prDatePopup').addClass('hidden');
        $('#canvDatePopup').addClass('hidden');
        if ($('#poDatePopup').hasClass('hidden')) poOpenPicker();
        else $('#poDatePopup').addClass('hidden');
    });
    $('#poDatePopup').on('click', function(e) { e.stopPropagation(); });
    $(document).on('click', function() { $('#poDatePopup').addClass('hidden'); });

    $('.po-cal-nav').on('click', function() {
        poCalMonth += parseInt($(this).data('step'), 10);
        if (poCalMonth < 0)  { poCalMonth = 11; poCalYear--; }
        if (poCalMonth > 11) { poCalMonth = 0;  poCalYear++; }
        poRenderCalendar();
    });

    $('#poCalGrid').on('click', '.pr-cal-cell[data-iso]', function() {
        var iso = $(this).data('iso');
        if (!poDraftFrom || poDraftTo) {            // start a fresh range
            poDraftFrom = iso; poDraftTo = '';
        } else if (iso < poDraftFrom) {             // clicked before the start -- swap
            poDraftTo = poDraftFrom; poDraftFrom = iso;
        } else {
            poDraftTo = iso;
        }
        poRenderCalendar();
    });

    $('#poDateApply').on('click', function() {
        currentPoDateFrom = poDraftFrom;
        // One tap = that single day, so the range stays inclusive on both ends.
        currentPoDateTo   = poDraftTo || poDraftFrom;
        poSyncLabel();
        $('#poDatePopup').addClass('hidden');
        table.draw();
    });

    $('#poDateCancel').on('click', function() {
        $('#poDatePopup').addClass('hidden');
    });

    // 4d. DROPDOWN COLUMN FILTERS (Requestor / Status / P.ORG) -- moved from the
    // table header into the toolbar above; wiring is unchanged.
    $('.filter-trigger').on('click', function(e) {
        e.stopPropagation();
        $('#prDatePopup').addClass('hidden');
        $('#canvDatePopup').addClass('hidden');
        $('#poDatePopup').addClass('hidden');
        var $dropdown = $(this).next('.filter-dropdown');
        $('.filter-dropdown').not($dropdown).addClass('hidden');
        $dropdown.toggleClass('hidden');
    });

    $(document).on('click', function() { $('.filter-dropdown').addClass('hidden'); });
    $('.filter-dropdown').on('click', function(e) { e.stopPropagation(); });

    $('.dropdown-search').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        var $list = $(this).closest('.filter-dropdown').find('.checkbox-list label');
        $list.each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(searchTerm) > -1);
        });
    });

    $('.select-all-checkbox').on('change', function() {
        var isChecked = $(this).prop('checked');
        $(this).closest('.filter-dropdown').find('.checkbox-list label:visible .col-checkbox').prop('checked', isChecked);
    });

    $('.apply-filter').on('click', function() {
        var colIdx = $(this).data('col');
        var $dropdown = $(this).closest('.filter-dropdown');
        var selected = [];
        $dropdown.find('.col-checkbox:checked').each(function() { selected.push($.fn.dataTable.util.escapeRegex($(this).val())); });

        if (selected.length === 0) {
            table.column(colIdx).search('', false, false).draw();
        } else {
            table.column(colIdx).search('^(' + selected.join('|') + ')$', true, false).draw();
        }
        $dropdown.closest('.filt-cell').find('.col-trigger-label').text(selected.length === 0 ? 'All' : selected.length + ' selected');
        $dropdown.addClass('hidden');
    });

    $('.clear-filter').on('click', function() {
        var $dropdown = $(this).closest('.filter-dropdown');
        var colIdx = $dropdown.find('.apply-filter').data('col');
        $dropdown.find('.col-checkbox, .select-all-checkbox').prop('checked', false);
        $dropdown.find('.dropdown-search').val('').trigger('keyup');
        table.column(colIdx).search('', false, false).draw();
        $dropdown.closest('.filt-cell').find('.col-trigger-label').text('All');
        $dropdown.addClass('hidden');
    });

    // 4e. CLEAR ALL FILTERS -- resets every control in the toolbar plus the
    // Status/SLA quick-filter buttons back to "All", in one click.
    $('#clearAllFiltersBtn').on('click', function() {
        currentSlaFilter = '';
        slaAlertExact = false;
        currentPrDateFrom = '';
        currentPrDateTo = '';

        $('.status-filter-btn').removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 hover:bg-indigo-700 hover:bg-red-700 hover:bg-yellow-600 hover:bg-green-700 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $('.status-filter-btn[data-status=""]').removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');

        $('.sla-filter-btn').removeClass(SLA_FILTER_ACTIVE_CLASSES).addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $('.sla-filter-btn[data-sla=""]').removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');

        $('.filter-dropdown').each(function() {
            var colIdx = $(this).find('.apply-filter').data('col');
            $(this).find('.col-checkbox, .select-all-checkbox').prop('checked', false);
            $(this).find('.dropdown-search').val('');
            if (colIdx !== undefined) table.column(colIdx).search('', false, false);
            $(this).addClass('hidden');
        });
        $('.col-trigger-label').text('All');

        $('.column-search-text').val('').each(function() { table.column($(this).data('col')).search(''); });

        currentPrDateFrom = '';
        currentPrDateTo = '';
        prDraftFrom = '';
        prDraftTo = '';
        prSyncLabel();
        $('#prDatePopup').addClass('hidden');

        currentCanvDateFrom = '';
        currentCanvDateTo = '';
        canvDraftFrom = '';
        canvDraftTo = '';
        canvSyncLabel();
        $('#canvDatePopup').addClass('hidden');

        currentPoDateFrom = '';
        currentPoDateTo = '';
        poDraftFrom = '';
        poDraftTo = '';
        poSyncLabel();
        $('#poDatePopup').addClass('hidden');

        $('#adminGlobalSearch').val('');
        table.search('');

        table.draw();
    });

    // TOAST NOTIFICATION FUNCTION
    function showToast(title, message, type) {
        var bgColor = type === 'success' ? 'bg-green-500' : 'bg-red-500';
        var icon = type === 'success' 
            ? '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>' 
            : '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>';
        
        var toast = $(`
            <div class="transform transition-all duration-300 translate-y-10 opacity-0 ${bgColor} text-white px-5 py-3 rounded-lg shadow-lg flex items-start max-w-sm">
                <div class="mr-3 mt-0.5">${icon}</div>
                <div>
                    <h4 class="font-bold text-sm leading-tight">${title}</h4>
                    <p class="text-xs mt-1 opacity-90 leading-tight">${message}</p>
                </div>
            </div>
        `);
        
        $('#toast-container').append(toast);
        setTimeout(() => toast.removeClass('translate-y-10 opacity-0'), 50); // animate in
        setTimeout(() => { // remove after 5 seconds
            toast.addClass('translate-y-10 opacity-0');
            setTimeout(() => toast.remove(), 300);
        }, 5000);
    }

    // MODAL LOGIC
    const modal = $('#reassignModal');
    
    $('#adminTable tbody').on('click', '.reassign-btn', function() {
        const ticket = $(this).data('ticket');
        $('#modal-ticket-input').val(ticket);
        $('#modal-ticket-display').text(ticket);
        modal.removeClass('hidden');
    });

    $('#closeModalBtn, #modalOverlay').on('click', function() {
        modal.addClass('hidden');
    });

    // AJAX AI TRIGGER LOGIC
    $('#confirmAiBtn').on('click', function() {
        var btn = $(this);
        var ticketNo = $('#modal-ticket-input').val();

        // 1. Ubah tombol menjadi status Loading (Spinner)
        var originalText = btn.html();
        btn.prop('disabled', true).removeClass('bg-indigo-600 hover:bg-indigo-700').addClass('bg-indigo-400 cursor-not-allowed');
        btn.html('<svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing AI...');

        // 2. Tembak request ke Backend secara Asynchronous
        $.ajax({
            url: 'ajax_reassign_ai.php',
            type: 'POST',
            dataType: 'json',
            data: { ticket_no: ticketNo },
            success: function(response) {
                if (response.status === 'success' && Array.isArray(response.lines)) {
                    modal.addClass('hidden');

                    // Lines can now resolve to DIFFERENT PICs -- only remove the
                    // rows that actually moved (new_pic set); a line that failed
                    // classification stays assigned to this admin.
                    var movedLines = response.lines.filter(function(l) { return l.new_pic; });
                    var failedLines = response.lines.filter(function(l) { return !l.new_pic; });

                    var movedByPic = {};
                    movedLines.forEach(function(l) {
                        movedByPic[l.new_pic] = (movedByPic[l.new_pic] || 0) + 1;
                    });
                    var summary = Object.keys(movedByPic).map(function(pic) {
                        return movedByPic[pic] + ' line(s) &rarr; <b>' + pic + '</b>';
                    }).join(', ');
                    if (failedLines.length > 0) {
                        summary += (summary ? '; ' : '') + failedLines.length + ' line(s) could not be re-routed and stayed with you.';
                    }
                    showToast('AI Assignment Complete', 'Ticket <b>' + ticketNo + '</b>: ' + summary, 'success');

                    // Remove only the specific (ticket_no, line) rows that moved to a
                    // different PIC -- lines that failed stay visible. Matched via the
                    // Ticket No cell's data-ticket attribute (not the cell's rendered
                    // text), since that cell also renders the PR date beneath it.
                    var rowsToRemove = table.rows(function(idx, data, node) {
                        if ($(node).find('td').eq(0).attr('data-ticket') !== ticketNo) { return false; }
                        var line = $(node).attr('data-line');
                        return movedLines.some(function(l) { return String(l.ticket_line_item) === String(line); });
                    });
                    rowsToRemove.remove().draw(false);

                    // Update total counter
                    var newTotal = table.rows().count();
                    $('#total-counter').text(newTotal);
                } else {
                    showToast('AI Failed', response.message || 'AI failed to reassign this ticket.', 'error');
                }
            },
            error: function() {
                showToast('Connection Error', 'Failed to reach the AI server. Please try again.', 'error');
            },
            complete: function() {
                // Kembalikan kondisi tombol modal ke semula (jaga-jaga jika ingin klik tiket lain nanti)
                btn.prop('disabled', false).removeClass('bg-indigo-400 cursor-not-allowed').addClass('bg-indigo-600 hover:bg-indigo-700');
                btn.html(originalText);
            }
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>