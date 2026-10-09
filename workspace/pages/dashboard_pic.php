<?php
// pages/dashboard_pic.php
require_once '../includes/auth.php';
require_login();

// STC has its own AON-focused dashboard. Bounce them there even if a stale
// session, a bookmark, or the VSS handoff lands them on the PIC worklist (which
// is keyed on assigned_pic and would just be empty for an STC anyway). Mirrors
// the role guard dashboard_stc.php uses in the other direction. Must run before
// header.php emits any output.
if (($_SESSION['role'] ?? '') === 'STC') {
    header('Location: dashboard_stc.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/sla_rules.php';
require_once '../includes/sla_alert.php';
require_once '../includes/currency_helper.php';
require_once '../includes/vendor_quotation_helper.php';
require_once '../includes/quotation_modal_ui.php';
require_once '../includes/quotation_bulk_ui.php';
require_once '../includes/manual_ca_modal_ui.php';
require_once '../includes/header.php';

$pic_name = $_SESSION['name'];

// Fetch PRs assigned ONLY to the logged-in user
// ticket_type / p_group / tr_date_gp / canvassing_date_approver_1 are selected for
// the SLA column only (targets configured on settings_sla.php).
// sku / qty feed the AON classifier behind the SLA warning pop-up.
$stmt = $pdo->prepare("
    SELECT ticket_no, ticket_line_item, pr_date, item_description, status_ticket, requestor_name, p_org, canvassing_no, canvassing_status, canvassing_date, po_number, po_create_date, assigned_by_ai,
           ticket_type, p_group, tr_date_gp, canvassing_date_approver_1, canvassing_description, sku, qty, ticket_helpdesk_no
    FROM prs
    WHERE assigned_pic = ? AND LOWER(status_ticket) NOT LIKE '%cancel%' AND (pr_date >= '2026-05-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))
    ORDER BY pr_date DESC
");
$stmt->execute([$pic_name]);
$assigned_prs = $stmt->fetchAll();

sla_attach($pdo, $assigned_prs);

// --- CALCULATE SMART STATUS ---
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
// red, counted into those four groups in the pop-up. Every ticket type counts,
// not just AON. The pop-up opens itself on load until the PIC dismisses it; the
// counter button in the header reopens it afterwards. See includes/sla_alert.php.
$sla_alerts   = sla_alert_rows($assigned_prs);
$sla_autoshow = !empty($sla_alerts) && sla_alert_should_autoshow();

// --- LAST PRICE QUOTATION (batched, no N+1) ----------------------------------
// Sourcing officers price the work on their own worklist: the modal
// (includes/quotation_modal_ui.php) and the bulk template/import
// (includes/quotation_bulk_ui.php) go through the same endpoints as the
// Manager / Project Head dashboards; quotation_authorize_canvassing() lets a
// PIC in when a line of the canvassing / ticket is assigned to them.
// GP Project lines are priced per canvassing, GP Non-Project per ticket.
$quotation_canvas_map = [];
$quotation_item_count_map = [];      // canvassing_no => priced lines (Project, legacy)
$quotation_ticket_count_map = [];    // ticket_no => priced lines (Non-Project)
$quotation_ticket_total_map = [];    // ticket_no => active lines (Non-Project)
$quotation_currency_symbols = ca_currency_symbols(ca_get_active_currencies($pdo));
$project_pgroup_set = array_flip($pdo->query("SELECT p_group FROM project_pgroups WHERE status = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN));
$pic_canvassing_nos = [];
$pic_ticket_nos = [];
foreach ($assigned_prs as $pr) {
    if (isset($project_pgroup_set[$pr['p_group']])) {
        $c = trim((string)($pr['canvassing_no'] ?? ''));
        if ($c !== '') $pic_canvassing_nos[$c] = true;
    } else {
        $pic_ticket_nos[trim((string)$pr['ticket_no'])] = true;
    }
}
try {
    if (!empty($pic_canvassing_nos)) {
        $ph = implode(',', array_fill(0, count($pic_canvassing_nos), '?'));
        $stmt = $pdo->prepare("SELECT canvassing_no, last_price, currency FROM vendor_quotation_last_price WHERE scope = 'canvas' AND canvassing_no IN ($ph)");
        $stmt->execute(array_keys($pic_canvassing_nos));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $quotation_canvas_map[$r['canvassing_no']] = $r; }
        $quotation_item_count_map = quotation_item_counts_by_canvassing($pdo, array_keys($pic_canvassing_nos));
    }
    $quotation_ticket_count_map = quotation_item_counts_by_ticket($pdo, array_keys($pic_ticket_nos));
    $quotation_ticket_total_map = quotation_line_totals_by_ticket($pdo, array_keys($pic_ticket_nos));
} catch (PDOException $e) {
    // Fail open: table not deployed yet just means every row shows "Add quotation".
}

// Bulk template per row type actually on this worklist (Project p_groups vs
// the rest; the templates list canvassed work only). Viewers and
// QB_HIDDEN_USERNAMES don't get the dropdown.
$qb_pic_has_project = false;
$qb_pic_has_nonproject = false;
foreach ($assigned_prs as $pr) {
    if (trim((string)($pr['canvassing_no'] ?? '')) === '') continue;
    if (isset($project_pgroup_set[$pr['p_group']])) $qb_pic_has_project = true; else $qb_pic_has_nonproject = true;
}
$qb_pic_show = !ws_is_viewer() && !qb_hidden_for($_SESSION) && ($qb_pic_has_project || $qb_pic_has_nonproject);
// Manual Input (Cost Avoidance) is offered to every PIC, with or without canvassed work.
$qb_pic_manual = !ws_is_viewer() && !qb_hidden_for($_SESSION);
$intercompany_types = ['intercompany', 'intercompany new store'];

// Get unique values for the filters
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
// the table. See includes/sla_alert.php.
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
#picTable thead th {
    position: sticky;
    top: 0;
    z-index: 15;
    background-color: #f3f4f6 !important;
    box-shadow: inset 0 -2px 0 0 #d1d5db, 0 1px 2px 0 rgba(0, 0, 0, .05);
}
#picTableScroll {
    padding-left: 0;
    padding-right: 0;
    padding-top: 0;
    /* Bottom spacing now comes from #picTableFooter's own padding -- keeping
       the old pb-6 here would leave a dead gap above the footer's divider. */
    padding-bottom: 0;
    border-top-left-radius: .5rem;
    border-top-right-radius: .5rem;
}
/* "Show entries" + Export + Global Search share one row ABOVE #picTableScroll
   (#picTableToolbar in the markup) instead of DataTables' default placement
   inside it -- previously "Show entries" scrolled out of view along with the
   rows and looked unstyled/cramped against the scrollbar. Only the length
   control is injected here on init; the rest of the row is static markup. */
#picTableToolbar .dataTables_length {
    margin: 0;
    font-size: 12px;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
}
#picTableToolbar .dataTables_length select {
    padding: 4px 22px 4px 8px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    color: #374151;
}
#picTableToolbar .dataTables_length select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 1px #6366f1;
}
/* "Showing x to y" + pagination, pulled out of #picTableScroll into their own
   static footer row (#picTableFooter) below it -- left inside the scroll area
   they sat flush against the horizontal scrollbar and drifted with the rows.
   DataTables' stock rules are all written as `.dataTables_wrapper .dataTables_*`,
   so moving these two blocks outside #picTable_wrapper drops every one of
   them (buttons collapse into unpadded, unclickable-looking text). The rules
   below are that same default styling re-scoped to the new parent -- the look
   is unchanged, only `float` gives way to the flex row. */
#picTableFooter .dataTables_info {
    clear: both;
    float: none;
    /* Stock DataTables adds padding-top here purely to line the text up against
       the floated pager; the flex row's own centring does that job now. */
    padding-top: 0;
}
#picTableFooter .dataTables_paginate {
    float: none;
    text-align: right;
    padding-top: 0;
}
#picTableFooter .dataTables_paginate .paginate_button {
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
#picTableFooter .dataTables_paginate .paginate_button.current,
#picTableFooter .dataTables_paginate .paginate_button.current:hover {
    color: #333 !important;
    border: 1px solid #979797;
    background-color: #fff;
    background: linear-gradient(to bottom, #fff 0%, #dcdcdc 100%);
}
#picTableFooter .dataTables_paginate .paginate_button.disabled,
#picTableFooter .dataTables_paginate .paginate_button.disabled:hover,
#picTableFooter .dataTables_paginate .paginate_button.disabled:active {
    cursor: default;
    color: #666 !important;
    border: 1px solid transparent;
    background: transparent;
    box-shadow: none;
}
#picTableFooter .dataTables_paginate .paginate_button:hover {
    color: #fff !important;
    border: 1px solid #111;
    background-color: #585858;
    background: linear-gradient(to bottom, #585858 0%, #111 100%);
}
#picTableFooter .dataTables_paginate .paginate_button:active {
    outline: none;
    background-color: #2b2b2b;
    background: linear-gradient(to bottom, #2b2b2b 0%, #0c0c0c 100%);
    box-shadow: inset 0 0 3px #111;
}
#picTableFooter .dataTables_paginate .ellipsis {
    padding: 0 1em;
}
</style>

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
        <h2 class="text-2xl font-bold text-gray-800">My Worklist</h2>
        <p class="text-sm text-gray-600">Welcome, <?= htmlspecialchars($pic_name) ?>. High-performance filtering enabled.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($last_import): ?>
        <div class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
            <span class="font-bold text-gray-600 uppercase tracking-wider">Last updated:</span>
            <span class="font-medium text-gray-700"><?= htmlspecialchars($last_import) ?></span>
        </div>
        <?php endif; ?>
        <?= sla_alert_button_html($sla_alerts) ?>
        <a href="master_data.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg font-bold shadow-sm transition">Taxonomy & SKU Request</a>
        <span class="bg-indigo-100 text-indigo-800 px-4 py-2 rounded-lg font-bold shadow-sm border border-indigo-200">Total: <?= count($assigned_prs) ?></span>
    </div>
</div>

<?php
// Auto-Check for pending Vendor Evaluations
$evalQuery = "
    SELECT COUNT(*) FROM (
        SELECT p.po_number 
        FROM prs p
        LEFT JOIN vendor_evaluations v ON p.po_number = v.po_number
        WHERE p.po_number IS NOT NULL AND p.po_number != ''
          AND p.vendor_name IS NOT NULL AND p.vendor_name != ''
          AND v.id IS NULL
          " . ($_SESSION['role'] === 'PIC' ? "AND p.assigned_pic = ?" : "") . "
        GROUP BY p.po_number
        HAVING MAX(p.gr_date) IS NOT NULL AND MAX(p.gr_date) <= CURDATE()
    ) as pending
";
$evalStmt = $pdo->prepare($evalQuery);
if ($_SESSION['role'] === 'PIC') {
    $evalStmt->execute([$_SESSION['name']]);
} else {
    $evalStmt->execute();
}
$pending_eval_count = $evalStmt->fetchColumn();

if ($pending_eval_count > 0):
?>
    <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-r-lg shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-center">
            <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-red-800 font-bold">Action Required: Vendor Evaluation</p>
                <p class="text-xs md:text-sm text-red-700">You have <?= $pending_eval_count ?> completed PO(s) waiting for vendor performance evaluation based on their GR date.</p>
            </div>
        </div>
        <div class="w-full md:w-auto">
            <a href="evaluations.php" class="block text-center w-full md:w-auto bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded-lg text-xs md:text-sm shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150">Evaluate Now &rarr;</a>
        </div>
    </div>
<?php endif; ?>

<div class="mb-6 flex flex-col gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-32 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by Status:</span>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="Outstanding">
            Outstanding
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="On Progress">
            On Progress
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="PO Created">
            PO Created
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="">
            All Statuses
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
     Assigned PIC / Team / AON -- this worklist is already scoped to one PIC). -->
<div class="mb-6 bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-3 gap-y-3">
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Ticket No</label>
            <input type="text" data-col="1" class="column-search-text filt-input" placeholder="Search...">
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
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="4" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Item Description</label>
            <input type="text" data-col="5" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Canvassing No</label>
            <input type="text" data-col="7" class="column-search-text filt-input" placeholder="Search...">
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
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="6" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">PO Number</label>
            <input type="text" data-col="8" class="column-search-text filt-input" placeholder="Search...">
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
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="3" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
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


<div class="bg-white shadow-md rounded-lg border border-gray-200 mb-8 relative">
    <form action="rfq_create.php" method="GET" id="createRfqForm">
    <!-- Plain card header, NOT sticky: the rows scroll inside #picTableScroll's own
         750px box, so a `sticky top-0` bar here pins itself to the viewport instead
         and paints over whichever rows happen to be passing underneath. All the
         other card chrome (toolbar, footer) is static for the same reason. -->
    <div class="p-4 border-b bg-gray-50 flex justify-between items-center">
        <span class="text-sm text-gray-700 font-bold" id="selectionCount">0 Items Selected</span>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg shadow disabled:opacity-50" id="btnCreateRfq" disabled>
            Proceed to Create RFQ &rarr;
        </button>
    </div>
    <!-- Toolbar is authored here, in its final position, so the row looks the same
         before and after DataTables initialises. #picToolbarLength is the empty
         slot DataTables' own "Show entries" control gets moved into on init. -->
    <div id="picTableToolbar" class="flex flex-wrap items-center justify-between gap-3 px-6 pt-4 pb-3">
        <div id="picToolbarLength"></div>
        <div class="flex items-center gap-3 pl-3" style="border-left: 1px solid #e5e7eb;">
            <?php if ($qb_pic_show || $qb_pic_manual) qb_render_toolbar('pic', $qb_pic_show && $qb_pic_has_project, $qb_pic_show && $qb_pic_has_nonproject, $qb_pic_manual); ?>
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
            <input type="text" id="picGlobalSearch" class="filt-input" placeholder="Search all columns..." style="width: 220px;">
        </div>
    </div>
    <div id="picTableScroll" class="overflow-y-auto px-6 pb-6 pt-2" style="max-height: 750px;">
        <table id="picTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm w-12 text-center">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider mb-2">Select</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[128px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Sorted by PR Date -- shown under the ticket number">Ticket No / PR Date</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-2 py-3 border-b-2 border-gray-300 align-top shadow-sm w-[80px] min-w-[80px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Days remaining against this ticket's SLA target, counted from the PR date">SLA</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[60px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">P.ORG</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[136px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Requestor</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[200px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Item Description</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[130px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Status</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Sorted by Canvassing Date -- shown under the canvassing number">Canvassing No / Date</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[140px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">PO Number</div>
                    </th>
                    <th class="hidden">PR Date</th><!-- hidden; export-only column, split out from Ticket No -->
                    <th class="hidden">Canvassing Date</th><!-- hidden; export-only column, split out from Canvassing No -->
                    <th class="hidden">PO Date</th><!-- hidden; export-only column, split out from PO Number -->
                    <!-- Initial Quotation Price: appended last (index 12) so the export column
                         lists and data-col filters above keep their indices. -->
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[170px] text-center">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Price settled during canvassing -- GP Project: one price per canvassing; GP Non-Project: per ticket, for every line">Initial Quotation Price</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Ticket Helpdesk No. from the K2 export">Helpdesk No.</div>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assigned_prs as $pr): ?>
                    <tr class="hover:bg-indigo-50 transition border-b border-gray-100" data-assigned-by-ai="<?= (int)$pr['assigned_by_ai'] ?>">
                        <td class="px-4 py-3 text-center">
                            <?php if ($pr['display_status'] !== 'PO Created' && $pr['display_status'] !== 'Closed'): ?>
                            <input type="checkbox" name="selected_items[]" value="<?= htmlspecialchars($pr['ticket_no'] . '|' . $pr['ticket_line_item']) ?>" class="item-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" data-ticket="<?= htmlspecialchars($pr['ticket_no']) ?>">
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900" data-order="<?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?>" data-ticket="<?= htmlspecialchars($pr['ticket_no']) ?>">
                            <?= htmlspecialchars($pr['ticket_no']) ?>
                            <?php if ($pr['pr_date']): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['pr_date'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-3 text-sm text-center" data-order="<?= (int)$pr['sla_sort'] ?>" data-search="<?= htmlspecialchars($pr['sla_status']) ?>">
                            <span class="<?= $pr['sla_badge'] ?> py-1 px-1 rounded text-[10px] font-bold border block w-fit min-w-[62px] mx-auto text-center whitespace-nowrap" title="<?= htmlspecialchars($pr['sla_title']) ?>"><?= htmlspecialchars($pr['sla_label']) ?></span>
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
                        <td class="hidden"><?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?></td>
                        <td class="hidden"><?= $pr['canvassing_date'] ? date('Y-m-d', strtotime($pr['canvassing_date'])) : '' ?></td>
                        <td class="hidden"><?= (!empty($pr['po_number']) && !empty($pr['po_create_date'])) ? date('Y-m-d', strtotime($pr['po_create_date'])) : '' ?></td>
                        <?php
                            $lpqProject = isset($project_pgroup_set[$pr['p_group']]);
                            $canv = $lpqProject ? trim((string)($pr['canvassing_no'] ?? '')) : '';
                            $canvasQ = $canv !== '' ? ($quotation_canvas_map[$canv] ?? null) : null;
                            $itemQCount = $lpqProject ? ($canv !== '' ? ($quotation_item_count_map[$canv] ?? 0) : 0) : ($quotation_ticket_count_map[trim((string)$pr['ticket_no'])] ?? 0);
                        ?>
                        <td class="px-4 py-3 text-sm text-center">
                            <?php if (in_array(strtolower(trim((string)$pr['ticket_type'])), $intercompany_types, true)): ?>
                                <span class="inline-flex items-center px-3 py-1.5 border border-blue-200 shadow-sm text-xs font-medium rounded text-blue-800 bg-blue-50" title="Routed via admin mappings / system rule">
                                    <span class="text-blue-400 italic">N/A</span>
                                    <span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span>
                                </span>
                            <?php elseif ($lpqProject && $canv === ''): ?>
                                <span class="inline-flex items-center px-3 py-1.5 border border-gray-200 text-xs font-medium rounded text-gray-400 bg-gray-50 cursor-not-allowed" title="GP Project quotation is entered per canvassing -- available once this ticket has a canvassing number">
                                    <span class="italic">Awaiting canvassing</span>
                                </span>
                            <?php else: ?>
                                <button type="button"
                                        class="quotation-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors"
                                        <?= $lpqProject ? 'data-canvassing="' . htmlspecialchars($canv) . '"' : 'data-ticket="' . htmlspecialchars(trim((string)$pr['ticket_no'])) . '"' ?>>
                                    <?php if ($canvasQ): ?>
                                        <?php $cur = (string)($canvasQ['currency'] ?? 'IDR'); $sym = $quotation_currency_symbols[$cur] ?? $cur; ?>
                                        <span class="text-indigo-700 font-semibold"><?= htmlspecialchars($sym . ' ' . ($cur === 'IDR' ? number_format((float)$canvasQ['last_price'], 0, ',', '.') : number_format((float)$canvasQ['last_price'], 2, '.', ','))) ?></span>
                                        <span class="ml-1 text-[9px] text-indigo-500 font-bold uppercase tracking-wider">Canvas</span>
                                    <?php elseif ($itemQCount > 0): ?>
                                        <?php $itemQTotal = $lpqProject ? 0 : ($quotation_ticket_total_map[trim((string)$pr['ticket_no'])] ?? 0); ?>
                                        <span class="text-indigo-700 font-semibold"><?= $itemQTotal > $itemQCount ? (int)$itemQCount . ' of ' . (int)$itemQTotal . ' items priced' : (int)$itemQCount . ' item' . ($itemQCount > 1 ? 's' : '') . ' priced' ?></span>
                                    <?php else: ?>
                                        <span class="text-gray-400 italic">Add quotation</span>
                                    <?php endif; ?>
                                    <svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars(trim((string)($pr['ticket_helpdesk_no'] ?? '')) !== '' ? $pr['ticket_helpdesk_no'] : '--') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </form>
    <!-- Footer slots: DataTables' info + pagination get moved in here on init so
         they sit below the scroll area instead of inside it. Kept outside the
         RFQ form so the pager never interacts with it. -->
    <div id="picTableFooter" class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 border-t border-gray-200">
        <div id="picFooterInfo"></div>
        <div id="picFooterPaginate"></div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Multi-select: empty = All Statuses; picked entries OR together.
    var currentStatusFilters = [];
    var currentSlaFilter = '';
    // 'na' rows (no PR date, countdown never started) are folded into Overdue --
    // same rule dashboard_mgr.php uses -- so there's no separate N/A button.
    //
    // ...except when the SLA pop-up drove the filter. It counts 'na' as neither
    // colour (a grey badge is not a red one), so folding here would answer a
    // click on "379 lines" with more rows than that. Any later click on a filter
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
            var rowSla = slaRow.find('td').eq(2).attr('data-search') || '';
            if (rowSla === 'na' && !slaAlertExact) rowSla = 'overdue';
            if (rowSla !== currentSlaFilter) return false;
        }

        if (currentPrDateFrom || currentPrDateTo) {
            // PR date lives on the merged Ticket No cell's data-order attribute
            // (col 1) since PR Date no longer has its own visible column.
            var dateRow = $(settings.aoData[dataIndex].nTr);
            var rowDate = dateRow.find('td').eq(1).attr('data-order') || '';
            if (!rowDate) return false;
            if (currentPrDateFrom && rowDate < currentPrDateFrom) return false;
            if (currentPrDateTo && rowDate > currentPrDateTo) return false;
        }

        if (currentCanvDateFrom || currentCanvDateTo) {
            // Canvassing date lives on the merged Canvassing No cell's data-order
            // attribute (col 7) since Canvassing Date has no visible column of
            // its own. Rows with no canvassing date can't match a date range.
            var canvRow = $(settings.aoData[dataIndex].nTr);
            var rowCanvDate = canvRow.find('td').eq(7).attr('data-order') || '';
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
    var EXPORT_HEADER = { 1: 'Ticket No', 7: 'Canvassing No' };
    function exportHeader(data, columnIdx) {
        if (EXPORT_HEADER[columnIdx]) return EXPORT_HEADER[columnIdx];
        return $('<div>').html(data).text().trim();
    }

    var table = $('#picTable').DataTable({
        "pageLength": 25,
        "order": [[ 1, "desc" ]],
        "columnDefs": [
            { "orderable": false, "targets": [0, 9, 10, 11, 12] },
            { "targets": 1, "render": ticketCellRender }, // Ticket No (+ PR Date beneath)
            { "targets": 7, "render": canvCellRender },   // Canvassing No (+ Canvassing Date beneath)
            { "targets": 8, "render": poCellRender }      // PO Number (+ PO Create Date beneath)
        ],
        "orderCellsTop": true,
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
            { extend: 'excelHtml5', exportOptions: { columns: [1, 9, 2, 3, 4, 5, 6, 7, 10, 8, 11, 13], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } },
            { extend: 'csvHtml5', exportOptions: { columns: [1, 9, 2, 3, 4, 5, 6, 7, 10, 8, 11, 13], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } },
            { extend: 'pdfHtml5', orientation: 'landscape', exportOptions: { columns: [1, 9, 2, 3, 4, 5, 6, 7, 10, 8, 11, 13], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } }
        ]
    });

    // #picTableToolbar (with Export + Global Search inside it) is authored
    // straight into the HTML above #picTableScroll, so the row renders in its
    // final position immediately -- nothing shifts once DataTables finishes.
    // "Show entries" is the one control that can't be pre-authored (DataTables
    // creates it during init), so it's moved -- not cloned -- into the empty
    // #picToolbarLength slot, keeping its own length listener intact. Without
    // this it would render inside the scrolling area and scroll out of view.
    $('#picTable_wrapper .dataTables_length').appendTo('#picToolbarLength');
    // Same treatment for the footer: "Showing x to y of z" and the pager are
    // moved below the scroll area so they no longer sit against the horizontal
    // scrollbar. Moved (not cloned) so DataTables keeps redrawing them in place.
    $('#picTable_wrapper .dataTables_info').appendTo('#picFooterInfo');
    $('#picTable_wrapper .dataTables_paginate').appendTo('#picFooterPaginate');

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

    // AUTO-APPLY STATUS FILTERS (multi-select, OR'd together)
    var STATUS_FILTER_STYLE = {
        '':            'bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600',
        'Outstanding': 'bg-red-600 hover:bg-red-700 text-white border-red-600',
        'On Progress': 'bg-yellow-500 hover:bg-yellow-600 text-white border-yellow-500',
        'PO Created':  'bg-green-600 hover:bg-green-700 text-white border-green-600'
    };
    function applyStatusFilters() {
        var isAll = currentStatusFilters.length === 0;
        $('.status-filter-btn').each(function() {
            var st = $(this).data('status') || '';
            var active = isAll ? (st === '') : (currentStatusFilters.indexOf(st) !== -1);
            $(this).removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 hover:bg-indigo-700 hover:bg-red-700 hover:bg-yellow-600 hover:bg-green-700 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 shadow-md ring-2 ring-offset-2')
                .addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
            if (active) {
                $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
                    .addClass((STATUS_FILTER_STYLE[st] || STATUS_FILTER_STYLE['']) + ' shadow-md ring-2 ring-offset-2');
            }
        });
        if (isAll) {
            table.column(6).search('', false, false);
        } else {
            var pattern = '^(' + currentStatusFilters.map(function(s) { return $.fn.dataTable.util.escapeRegex(s); }).join('|') + ')$';
            table.column(6).search(pattern, true, false);
        }
    }

    $('.status-filter-btn').on('click', function() {
        if (!slaAlertApplying) slaAlertExact = false;   // a filter picked by hand folds 'na' as usual

        var status = $(this).data('status') || '';
        if (status === '') {
            currentStatusFilters = [];
        } else {
            var idx = currentStatusFilters.indexOf(status);
            if (idx !== -1) {
                currentStatusFilters.splice(idx, 1);
            } else {
                currentStatusFilters.push(status);
            }
        }
        applyStatusFilters();
        if (!slaAlertApplying) table.draw();
    });

    // AUTO-APPLY SLA FILTERS
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
        currentStatusFilters = status ? [status] : [];
        applyStatusFilters();
        $('.sla-filter-btn[data-sla="' + sla + '"]').trigger('click');
        slaAlertApplying = false;
        slaAlertExact = true;                  // count 'na' as neither colour -- see above
        table.draw();
        // The scroll container on this layout is <main>, not the window
        // (includes/header.php puts overflow-hidden on <body>), so scrolling
        // html/body here would do nothing.
        var $t = $('#picTable'), $scroller = $t.closest('main');
        if ($scroller.length) {
            $scroller.animate({ scrollTop: $scroller.scrollTop() + $t.offset().top - $scroller.offset().top - 16 }, 250);
        }
    };
    // The worklist is built and interactive -- the true "page has finished
    // loading" moment, so let the pop-up hand its cue over now.
    if (window.slaAlert) window.slaAlert.ready();

    $('.column-search-text').on('keyup change clear', function(e) {
        e.stopPropagation();
        table.column($(this).data('col')).search(this.value).draw();
    });

    // GLOBAL SEARCH -- replaces DataTables' default "Search:" box, same as dashboard_mgr.php
    $('#picGlobalSearch').on('keyup change clear', function() {
        table.search(this.value).draw();
    });
    // The toolbar sits inside #createRfqForm (it has to, so the row can live
    // between the RFQ bar and the table), which makes this the form's only text
    // input -- pressing Enter in it would otherwise submit the RFQ form.
    $('#picGlobalSearch').on('keydown', function(e) {
        if (e.key === 'Enter') e.preventDefault();
    });

    // PR DATE RANGE PICKER -- same trigger/popup wiring as dashboard_mgr.php
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

    // CANVASSING DATE RANGE PICKER -- identical wiring to PR Date above, pointed
    // at the canv* state and DOM ids. Opening either picker closes the other one
    // (and any open dropdown) so only one popup is ever on screen.
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

    // PO Date range picker -- identical wiring, pointed at the po* state.
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

    $('.filter-trigger').on('click', function(e) {
        e.stopPropagation();
        $('#prDatePopup').addClass('hidden');
        $('#canvDatePopup').addClass('hidden');
        $('#poDatePopup').addClass('hidden');
        var $th = $(this).closest('th.th-filter');
        var $dropdown = $(this).next('.filter-dropdown');
        $('th.th-filter').css('z-index', '10');
        $('.filter-dropdown').not($dropdown).addClass('hidden');

        if ($dropdown.hasClass('hidden')) {
            $dropdown.removeClass('hidden');
            $th.css('z-index', '50'); 
        } else {
            $dropdown.addClass('hidden');
        }
    });

    $(document).on('click', function() { $('.filter-dropdown').addClass('hidden'); $('th.th-filter').css('z-index', '10'); });
    $('.filter-dropdown').on('click', function(e) { e.stopPropagation(); });
    $('thead input').on('click', function(e) { e.stopPropagation(); });

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
        $('th.th-filter').css('z-index', '10');
    });

    $('.clear-filter').on('click', function() {
        var $dropdown = $(this).closest('.filter-dropdown');
        var colIdx = $dropdown.find('.apply-filter').data('col');
        $dropdown.find('.col-checkbox, .select-all-checkbox').prop('checked', false);
        $dropdown.find('.dropdown-search').val('').trigger('keyup');
        table.column(colIdx).search('', false, false).draw();
        $dropdown.closest('.filt-cell').find('.col-trigger-label').text('All');
        $dropdown.addClass('hidden');
        $('th.th-filter').css('z-index', '10');
    });

    // CLEAR ALL FILTERS -- resets every control in the toolbar plus the
    // Status/SLA quick-filter buttons back to "All", in one click.
    $('#clearAllFiltersBtn').on('click', function() {
        currentSlaFilter = '';
        slaAlertExact = false;
        currentPrDateFrom = '';
        currentPrDateTo = '';

        currentStatusFilters = [];
        applyStatusFilters();

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

        $('#picGlobalSearch').val('');
        table.search('');

        table.draw();
    });

    // Checkbox and Form Logic
    $('#picTable').on('change', '.item-checkbox', function() {
        var selectedCheckboxes = $('.item-checkbox:checked');
        var count = selectedCheckboxes.length;
        $('#selectionCount').text(count + ' Items Selected');
        
        if (count > 0) {
            $('#btnCreateRfq').prop('disabled', false);
        } else {
            $('#btnCreateRfq').prop('disabled', true);
        }
    });

    $('#createRfqForm').on('submit', function(e) {
        var selectedCheckboxes = $('.item-checkbox:checked');
        if (selectedCheckboxes.length === 0) {
            e.preventDefault();
            alert("Pilih minimal satu item untuk dibuatkan RFQ.");
            return false;
        }

        var firstTicketNo = selectedCheckboxes.first().data('ticket');
        var allSame = true;

        selectedCheckboxes.each(function() {
            if ($(this).data('ticket') !== firstTicketNo) {
                allSame = false;
            }
        });

        if (!allSame) {
            e.preventDefault();
            alert("Harap pilih item dari Nomor Tiket (PR) yang sama.");
            return false;
        }

        // We append the selected checkboxes as hidden inputs so they submit properly even if hidden in pagination
        selectedCheckboxes.each(function() {
            if(!$.contains(document, this)){
                $('#createRfqForm').append($('<input>').attr('type', 'hidden').attr('name', this.name).val(this.value));
            }
        });
    });
});
</script>

<?php
// Initial Quotation Price modals -- outside the RFQ <form> above on purpose.
if (!empty($assigned_prs)) qm_render_modal('#picTable');
if ($qb_pic_show || $qb_pic_manual) qb_render_modal(qb_guide_for($pdo, $_SESSION, $qb_pic_show && $qb_pic_has_project, $qb_pic_show && $qb_pic_has_nonproject));
if ($qb_pic_manual) mca_render_modal();
?>

<?php require_once '../includes/footer.php'; ?>