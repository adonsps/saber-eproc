<?php
// pages/dashboard_tech.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';

// The Form 2 teams (VE2_TECH_EVAL_ONLY_TEAMS) don't use this PR worklist —
// route them to their Vendor Evaluation results page instead. Every other
// Technical Department team — General Affairs, MBO, anything added later —
// falls through to the worklist below, unchanged. Must run BEFORE header.php
// (which emits HTML), and shares its gate with header.php's sidebar so the two
// can't drift. Fail-open to the worklist on any error, and also when the Form 2
// team has no in-scope PGroup mapping yet (nothing to show them there).
require_once '../includes/vendor_eval_v2_tech_results.php';
if (($_SESSION['role'] ?? '') === 'Technical Department' && ve2_tech_is_eval_only_team($_SESSION['team_name'] ?? '')) {
    try {
        if (!empty(ve2_tech_resolve_pgroups($pdo, $_SESSION['team_name'] ?? ''))) {
            header('Location: vendor_eval_results_tech.php');
            exit;
        }
    } catch (\Throwable $e) {
        // fall through to the normal worklist
    }
}

// sla_elapsed_days() -- the working-day arithmetic (weekends + company_holidays
// skipped) behind the Aging column below. The SLA verdict helpers in the same
// file are unused here: this role has an Aging column instead of an SLA one.
require_once '../includes/sla_rules.php';
require_once '../includes/header.php';

// Aging counts up to "today" for lines with no PO yet, so the day boundary has
// to be the business's own, not the server's default.
date_default_timezone_set('Asia/Jakarta');

$team_name = $_SESSION['team_name'] ?? '';

// Fetch PRs matching the Technical Department's mapped PGroups.
// tr_date_gp / canvassing_date_approver_1 are no longer selected: they only ever
// fed the SLA column, which this role no longer has. pr_date + po_create_date
// are the two ends of the Aging column.
$stmt = $pdo->prepare("
    SELECT ticket_no, ticket_line_item, ticket_type, pr_date, item_description, status_ticket, requestor_name, p_org, p_group, site_allocation, canvassing_no, canvassing_date, po_number, po_create_date, assigned_pic, assigned_by_ai, ticket_helpdesk_no
    FROM prs
    WHERE p_group COLLATE utf8mb4_unicode_ci IN (SELECT pgroup COLLATE utf8mb4_unicode_ci FROM technical_team_pgroups WHERE team_name = ?)
    AND LOWER(status_ticket) NOT LIKE '%cancel%' AND (pr_date >= '2026-05-01' OR pr_date IS NULL OR (canvassing_no IS NOT NULL AND canvassing_no != '') OR (po_number IS NOT NULL AND po_number != ''))
    ORDER BY pr_date DESC
");
$stmt->execute([$team_name]);
$assigned_prs = $stmt->fetchAll();

// --- CALCULATE SMART STATUS + AGING ---
foreach ($assigned_prs as &$pr) {
    $has_canv = !empty(trim((string)$pr['canvassing_no']));
    $has_po   = !empty(trim((string)$pr['po_number']));

    // AGING -- working days from TR Date (prs.pr_date) to PO Create Date.
    // Counted with sla_elapsed_days(), the same function the SLA countdown uses,
    // so weekends and every date on the company holiday calendar are skipped and
    // the window is (start, end]: the PR date itself is day 0, each following
    // working day adds one. Unlike SLA this is a plain DURATION, not headroom
    // against a target -- there is nothing to be "within" or "breach".
    //
    // A line still waiting for its PO has not finished ageing, so it counts up to
    // today and is badged amber to say the number is still moving; a line with its
    // PO create date stamped freezes at its final count in neutral grey.
    //
    // The third case is the awkward one: prs.po_create_date is only stamped by the
    // PR import at the moment po_number goes from empty to filled
    // (excel_parser.php ~253), so every line whose PO predates that feature has a
    // PO but no create date, permanently. Those are FINISHED with an unknown end
    // date -- counting them up to today would invent an ever-growing number for
    // work that closed months ago.
    //
    // They read "Done", not N/A. The PO exists, so the work on this line IS
    // finished, and that is the fact the reader needs; N/A said "nothing to
    // report here", which put a closed line in the same visual bucket as a line
    // with no PR date at all and made it look outstanding. Only the exact day
    // count is unknown, and the tooltip is where that belongs.
    $aging_start = substr(trim((string)($pr['pr_date'] ?? '')), 0, 10);
    $aging_end   = substr(trim((string)($pr['po_create_date'] ?? '')), 0, 10);
    $aging_done  = ($aging_end !== '' && strpos($aging_end, '0000-00-00') !== 0);

    if ($aging_start === '' || strpos($aging_start, '0000-00-00') === 0) {
        // No PR date -- there is no clock to start. Sorted to the very bottom.
        $pr['aging_days']  = null;
        $pr['aging_label'] = 'N/A';
        $pr['aging_badge'] = 'bg-gray-100 text-gray-500 border-gray-200';
        $pr['aging_sort']  = 99999;
        $pr['aging_title'] = 'No TR/PR date on this line -- aging cannot be counted.';
    } elseif (!$aging_done && $has_po) {
        $pr['aging_days']  = null;
        $pr['aging_label'] = 'Done';
        // Same neutral grey as a finished line with a known count, so the two read
        // as the one state they actually are: PO raised, clock stopped.
        $pr['aging_badge'] = 'bg-gray-200 text-gray-700 border-gray-300';
        $pr['aging_sort']  = 99998;
        $pr['aging_title'] = 'PO ' . trim((string)$pr['po_number']) . ' has been created, so this line is done and '
            . 'no longer ageing. Its PO create date was never recorded, so the exact number of days cannot be shown: '
            . 'only POs first seen by a PR import after the PO Create Date field shipped carry one.';
    } else {
        $days = sla_elapsed_days($pdo, $aging_start, $aging_done ? $aging_end : date('Y-m-d'));
        $pr['aging_days']  = $days;
        $pr['aging_label'] = $days . ' ' . ($days === 1 ? 'day' : 'days');
        $pr['aging_sort']  = $days;
        if ($aging_done) {
            $pr['aging_badge'] = 'bg-gray-200 text-gray-700 border-gray-300';
            $pr['aging_title'] = 'Final aging: ' . $days . ' working day(s) from TR/PR date ('
                . date('d M Y', strtotime($aging_start)) . ') to PO create date ('
                . date('d M Y', strtotime($aging_end)) . '). Weekends and company holidays excluded.';
        } else {
            $pr['aging_badge'] = 'bg-amber-100 text-amber-800 border-amber-200';
            $pr['aging_title'] = 'Still running: ' . $days . ' working day(s) since the TR/PR date ('
                . date('d M Y', strtotime($aging_start)) . '). No PO yet, so this counts up to today. '
                . 'Weekends and company holidays excluded.';
        }
    }

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

// Get unique values for the filters
$unique_tickets = array_unique(array_column($assigned_prs, 'ticket_no'));
$unique_porgs = array_unique(array_column($assigned_prs, 'p_org'));
$unique_pgroups = array_unique(array_column($assigned_prs, 'p_group'));
// array_filter drops the blanks -- those rows render as '--' in the cell and have
// nothing meaningful to offer as a checkbox.
$unique_sites = array_unique(array_filter(array_column($assigned_prs, 'site_allocation')));
$unique_requestors = array_unique(array_column($assigned_prs, 'requestor_name'));
$unique_pics = array_unique(array_filter(array_column($assigned_prs, 'assigned_pic')));
$unique_statuses = array_unique(array_column($assigned_prs, 'display_status'));

sort($unique_tickets);
sort($unique_porgs);
sort($unique_pgroups);
sort($unique_sites);
sort($unique_requestors);
sort($unique_pics);
sort($unique_statuses);
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
#techTable thead th {
    position: sticky;
    top: 0;
    z-index: 15;
    background-color: #f3f4f6 !important;
    box-shadow: inset 0 -2px 0 0 #d1d5db, 0 1px 2px 0 rgba(0, 0, 0, .05);
}
#techTableScroll {
    padding-left: 0;
    padding-right: 0;
    padding-top: 0;
    /* Bottom spacing now comes from #techTableFooter's own padding -- keeping
       the old pb-6 here would leave a dead gap above the footer's divider. */
    padding-bottom: 0;
    border-top-left-radius: .5rem;
    border-top-right-radius: .5rem;
}
/* "Show entries" + Export + Global Search share one row ABOVE #techTableScroll
   (#techTableToolbar in the markup) instead of DataTables' default placement
   inside it -- previously "Show entries" scrolled out of view along with the
   rows and looked unstyled/cramped against the scrollbar. Only the length
   control is injected here on init; the rest of the row is static markup. */
#techTableToolbar .dataTables_length {
    margin: 0;
    font-size: 12px;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
}
#techTableToolbar .dataTables_length select {
    padding: 4px 22px 4px 8px;
    font-size: 12px;
    border: 1px solid #d1d5db;
    border-radius: 6px;
    background-color: #fff;
    color: #374151;
}
#techTableToolbar .dataTables_length select:focus {
    outline: none;
    border-color: #6366f1;
    box-shadow: 0 0 0 1px #6366f1;
}
/* "Showing x to y" + pagination, pulled out of #techTableScroll into their own
   static footer row (#techTableFooter) below it -- left inside the scroll area
   they sat flush against the horizontal scrollbar and drifted with the rows.
   DataTables' stock rules are all written as `.dataTables_wrapper .dataTables_*`,
   so moving these two blocks outside #techTable_wrapper drops every one of
   them (buttons collapse into unpadded, unclickable-looking text). The rules
   below are that same default styling re-scoped to the new parent -- the look
   is unchanged, only `float` gives way to the flex row. */
#techTableFooter .dataTables_info {
    clear: both;
    float: none;
    /* Stock DataTables adds padding-top here purely to line the text up against
       the floated pager; the flex row's own centring does that job now. */
    padding-top: 0;
}
#techTableFooter .dataTables_paginate {
    float: none;
    text-align: right;
    padding-top: 0;
}
#techTableFooter .dataTables_paginate .paginate_button {
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
#techTableFooter .dataTables_paginate .paginate_button.current,
#techTableFooter .dataTables_paginate .paginate_button.current:hover {
    color: #333 !important;
    border: 1px solid #979797;
    background-color: #fff;
    background: linear-gradient(to bottom, #fff 0%, #dcdcdc 100%);
}
#techTableFooter .dataTables_paginate .paginate_button.disabled,
#techTableFooter .dataTables_paginate .paginate_button.disabled:hover,
#techTableFooter .dataTables_paginate .paginate_button.disabled:active {
    cursor: default;
    color: #666 !important;
    border: 1px solid transparent;
    background: transparent;
    box-shadow: none;
}
#techTableFooter .dataTables_paginate .paginate_button:hover {
    color: #fff !important;
    border: 1px solid #111;
    background-color: #585858;
    background: linear-gradient(to bottom, #585858 0%, #111 100%);
}
#techTableFooter .dataTables_paginate .paginate_button:active {
    outline: none;
    background-color: #2b2b2b;
    background: linear-gradient(to bottom, #2b2b2b 0%, #0c0c0c 100%);
    box-shadow: inset 0 0 3px #111;
}
#techTableFooter .dataTables_paginate .ellipsis {
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

<?php if (!empty($_SESSION['flash_pr_success'])): 
    $fps = $_SESSION['flash_pr_success'];
    unset($_SESSION['flash_pr_success']);
?>
<div class="mb-6 p-4 rounded-2xl bg-emerald-50 border border-emerald-200 text-emerald-900 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4">
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-emerald-600 text-white flex items-center justify-center flex-shrink-0 shadow-sm">
            <i class="fas fa-check text-lg"></i>
        </div>
        <div>
            <h4 class="font-extrabold text-sm text-emerald-950">Purchase Requisition Berhasil Dibuat!</h4>
            <p class="text-xs text-emerald-800 mt-0.5">
                Ticket <strong class="font-mono bg-white/70 px-1.5 py-0.5 rounded border border-emerald-200"><?= htmlspecialchars($fps['ticket_no']) ?></strong> dengan <strong><?= (int)$fps['lines_count'] ?></strong> baris barang/jasa berhasil disimpan ke dalam sistem eProc.
                <?php if (!empty($fps['assigned_pic']) && $fps['assigned_pic'] !== 'Unassigned'): ?>
                Diarahkan ke PIC Sourcing: <strong class="text-blue-900"><?= htmlspecialchars($fps['assigned_pic']) ?></strong>.
                <?php endif; ?>
            </p>
        </div>
    </div>
    <div class="flex items-center gap-2 self-end md:self-auto">
        <button type="button" onclick="openTicketDetailModal('<?= htmlspecialchars($fps['ticket_no']) ?>')" class="px-3.5 py-2 bg-emerald-700 hover:bg-emerald-800 text-white rounded-lg text-xs font-bold transition flex items-center gap-1.5 flex-shrink-0 shadow-sm">
            <i class="fas fa-eye text-[11px]"></i> Lihat Detail PR
        </button>
    </div>
</div>
<?php endif; ?>

<div class="mb-6 flex flex-col md:flex-row md:justify-between md:items-end gap-4 border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">My Worklist</h2>
        <p class="text-sm text-gray-600">Welcome, <?= htmlspecialchars($display_name ?? '') ?>. High-performance filtering enabled.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <?php if ($last_import): ?>
        <div class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
            <span class="font-bold text-gray-600 uppercase tracking-wider">Last updated:</span>
            <span class="font-medium text-gray-700"><?= htmlspecialchars($last_import) ?></span>
        </div>
        <?php endif; ?>
        <span class="bg-indigo-100 text-indigo-800 px-4 py-2 rounded-lg font-bold shadow-sm border border-indigo-200">Total: <?= count($assigned_prs) ?></span>
        
        <!-- Create PR Button for Technical Department -->
        <a href="pr_create.php" class="px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
            + Create PR Direct
        </a>
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
        <!-- Sendback = prs.status_ticket is 'Revise' or 'Rejected' (see the display_status
             mapping above), i.e. the ticket was pushed back to the requestor. -->
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="Sendback">
            Sendback
        </button>
    </div>
    <!-- The "Filter by SLA" button row that used to sit here was removed together
         with the SLA column -- it filtered on that column and has nothing left to
         read once the column is gone. -->
    <!-- Type buttons are specific to this dashboard: the technical worklist mixes
         intercompany and non-intercompany tickets, which the other worklists don't. -->
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-32 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by Type:</span>
        <button type="button" class="type-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="">
            All Types
        </button>
        <button type="button" class="type-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="intercompany">
            Intercompany
        </button>
        <button type="button" class="type-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="non-intercompany">
            Non-Intercompany
        </button>
    </div>
</div>

<!-- COMPACT SEARCH / FILTER TOOLBAR -- same layout as dashboard_pic.php /
     dashboard_admin.php, plus the two columns only this worklist has
     (Assigned PIC and P.Group).
     Field order is grouped so each row of five reads as one theme rather than
     following the table's column order: row 1 identifies the line (ticket, date,
     org), row 2 covers who's working it and how far it's got, row 3 the outcome.
     Thirteen cells over five columns leave two empty at the end, so the Clear
     button is pinned to the last column (lg:col-start-5) -- left to flow it
     landed mid-row with a hole beside it. -->
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
            <label class="filt-label">P.Group</label>
            <button type="button" class="filter-trigger filt-input col-trigger">
                <span class="col-trigger-label">All</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
            </button>
            <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-64 rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search P.Group..."></div>
                <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                <div class="p-2 max-h-48 overflow-y-auto checkbox-list">
                    <?php foreach ($unique_pgroups as $val): ?>
                    <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer"><input type="checkbox" class="col-checkbox h-3 w-3 text-indigo-600" value="<?= htmlspecialchars($val) ?>"><span class="ml-2 text-xs text-gray-700"><?= htmlspecialchars($val) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <!-- Searches the hidden export-only P.Group column (12), not the merged
                     P.ORG / P.Group cell -- that one filters on P.ORG alone. -->
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="12" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">Site Allocation</label>
            <button type="button" class="filter-trigger filt-input col-trigger">
                <span class="col-trigger-label">All</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
            </button>
            <div class="filter-dropdown hidden absolute top-full right-0 mt-1 w-72 rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search Site Allocation..."></div>
                <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                <div class="p-2 max-h-48 overflow-y-auto checkbox-list">
                    <?php foreach ($unique_sites as $val): ?>
                    <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer"><input type="checkbox" class="col-checkbox h-3 w-3 text-indigo-600" value="<?= htmlspecialchars($val) ?>"><span class="ml-2 text-xs text-gray-700"><?= htmlspecialchars($val) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="3" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
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
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">Assigned PIC</label>
            <button type="button" class="filter-trigger filt-input col-trigger">
                <span class="col-trigger-label">All</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
            </button>
            <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-72 rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search PIC..."></div>
                <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                <div class="p-2 max-h-48 overflow-y-auto checkbox-list">
                    <?php foreach ($unique_pics as $val): ?>
                    <label class="flex items-center p-1 hover:bg-indigo-50 rounded cursor-pointer"><input type="checkbox" class="col-checkbox h-3 w-3 text-indigo-600" value="<?= htmlspecialchars($val) ?>"><span class="ml-2 text-xs text-gray-700"><?= htmlspecialchars($val) ?></span></label>
                    <?php endforeach; ?>
                </div>
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="5" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Item Description</label>
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
                <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="7" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Canvassing No</label>
            <input type="text" data-col="8" class="column-search-text filt-input" placeholder="Search...">
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
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">PO Number</label>
            <input type="text" data-col="9" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0" style="position:relative;">
            <label class="filt-label">PO Date</label>
            <button type="button" id="poDateTrigger" class="filt-input col-trigger">
                <span id="poDateLabel" class="col-trigger-label">All dates</span>
                <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
            </button>
            <div id="poDatePopup" class="hidden absolute top-full left-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3">
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
        <div class="filt-cell flex flex-col min-w-0 lg:col-start-5">
            <label class="filt-label">&nbsp;</label>
            <button type="button" id="clearAllFiltersBtn" class="filt-input bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold border-gray-300 transition">Clear All Filters</button>
        </div>
    </div>
</div>

<div class="bg-white shadow-md rounded-lg border border-gray-200 mb-8">
    <!-- Toolbar is authored here, in its final position, so the row looks the same
         before and after DataTables initialises. #techToolbarLength is the empty
         slot DataTables' own "Show entries" control gets moved into on init. -->
    <div id="techTableToolbar" class="flex flex-wrap items-center justify-between gap-3 px-6 pt-4 pb-3">
        <div id="techToolbarLength"></div>
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
            <input type="text" id="techGlobalSearch" class="filt-input" placeholder="Search all columns..." style="width: 220px;">
        </div>
    </div>
    <div id="techTableScroll" class="overflow-y-auto px-6 pb-6 pt-2" style="max-height: 750px;">
        <table id="techTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[128px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Sorted by PR Date -- shown under the ticket number">Ticket No / PR Date</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-2 py-3 border-b-2 border-gray-300 align-top shadow-sm w-[70px] min-w-[70px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Working days from the TR (PR) date to the PO create date -- weekends and company holidays excluded. Lines with no PO yet keep counting up to today.">Aging</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[90px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="P.Group is shown underneath the P.ORG code">P.ORG / P.Group</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[140px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Site Allocation</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[136px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Requestor</div>
                    </th>
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider">Assigned PIC</div>
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
                    <th class="hidden">P.Group</th><!-- hidden; export-only column, split out from P.ORG (also what the P.Group filter searches) -->
                    <th class="hidden">PO Date</th><!-- hidden; export-only column, split out from PO Number -->
                    <th class="th-filter bg-gray-100 px-4 py-3 border-b-2 border-gray-300 align-top shadow-sm min-w-[150px]">
                        <div class="text-xs font-bold text-gray-600 uppercase tracking-wider" title="Ticket Helpdesk No. from the K2 export">Helpdesk No.</div>
                    </th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assigned_prs as $pr): ?>
                    <tr class="hover:bg-indigo-50 transition border-b border-gray-100" data-assigned-by-ai="<?= (int)$pr['assigned_by_ai'] ?>" data-ticket-type="<?= htmlspecialchars($pr['ticket_type'] ?? '') ?>">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900" data-order="<?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?>" data-ticket="<?= htmlspecialchars($pr['ticket_no']) ?>">
                            <a href="javascript:void(0)" onclick="openTicketDetailModal('<?= htmlspecialchars($pr['ticket_no']) ?>')" 
                               class="text-blue-700 hover:text-blue-900 font-bold hover:underline transition inline-flex items-center gap-1 group">
                                <span><?= htmlspecialchars($pr['ticket_no']) ?></span>
                                <i class="fas fa-external-link-alt text-[9px] text-slate-400 group-hover:text-blue-700 opacity-60"></i>
                            </a>
                            <?php if ($pr['pr_date']): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['pr_date'])) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-2 py-3 text-sm text-center" data-order="<?= (int)$pr['aging_sort'] ?>">
                            <span class="<?= $pr['aging_badge'] ?> py-1 px-1 rounded text-[10px] font-bold border block w-fit min-w-[62px] mx-auto text-center whitespace-nowrap" title="<?= htmlspecialchars($pr['aging_title']) ?>"><?= htmlspecialchars($pr['aging_label']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700" data-porg="<?= htmlspecialchars($pr['p_org']) ?>">
                            <?= htmlspecialchars($pr['p_org']) ?>
                            <?php if ($pr['p_group']): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= htmlspecialchars($pr['p_group']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div class="truncate max-w-[160px]" title="<?= htmlspecialchars($pr['site_allocation'] ?? '') ?>"><?= htmlspecialchars($pr['site_allocation'] ?: '--') ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div class="truncate max-w-[160px]" title="<?= htmlspecialchars($pr['requestor_name']) ?>"><?= htmlspecialchars($pr['requestor_name']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($pr['assigned_pic'] ?? '') ?></td>
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
                        <td class="hidden"><?= htmlspecialchars($pr['p_group']) ?></td>
                        <td class="hidden"><?= (!empty($pr['po_number']) && !empty($pr['po_create_date'])) ? date('Y-m-d', strtotime($pr['po_create_date'])) : '' ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars(trim((string)($pr['ticket_helpdesk_no'] ?? '')) !== '' ? $pr['ticket_helpdesk_no'] : '--') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- Footer slots: DataTables' info + pagination get moved in here on init so
         they sit below the scroll area instead of inside it. -->
    <div id="techTableFooter" class="flex flex-wrap items-center justify-between gap-3 px-6 py-3 border-t border-gray-200">
        <div id="techFooterInfo"></div>
        <div id="techFooterPaginate"></div>
    </div>
</div>

<script>
$(document).ready(function() {
    var currentTypeFilter = '';

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        var $row = $(settings.aoData[dataIndex].nTr);

        // Ticket type (intercompany / non-intercompany) -- read off the row itself,
        // it has no column of its own.
        if (currentTypeFilter) {
            var typeVal = ($row.attr('data-ticket-type') || '').toLowerCase().trim();
            var isIntercompany = (typeVal === 'intercompany' || typeVal === 'intercompany new store');

            if (currentTypeFilter === 'intercompany' && !isIntercompany) {
                return false;
            } else if (currentTypeFilter === 'non-intercompany' && isIntercompany) {
                return false;
            }
        }

        if (currentPrDateFrom || currentPrDateTo) {
            // PR date lives on the merged Ticket No cell's data-order attribute
            // (col 0) since PR Date no longer has its own visible column.
            var rowDate = $row.find('td').eq(0).attr('data-order') || '';
            if (!rowDate) return false;
            if (currentPrDateFrom && rowDate < currentPrDateFrom) return false;
            if (currentPrDateTo && rowDate > currentPrDateTo) return false;
        }

        if (currentCanvDateFrom || currentCanvDateTo) {
            // Canvassing date lives on the merged Canvassing No cell's data-order
            // attribute (col 8) since Canvassing Date has no visible column of
            // its own. Rows with no canvassing date can't match a date range.
            var rowCanvDate = $row.find('td').eq(8).attr('data-order') || '';
            if (!rowCanvDate) return false;
            if (currentCanvDateFrom && rowCanvDate < currentCanvDateFrom) return false;
            if (currentCanvDateTo && rowCanvDate > currentCanvDateTo) return false;
        }

        if (currentPoDateFrom || currentPoDateTo) {
            // Hidden PO Date column (col 13) -- empty when the row has no PO number.
            var rowPoDate = $row.find('td').eq(13).text().trim();
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

    // P.ORG + P.Group are merged the same way: P.Group is shown beneath the P.ORG
    // code, while search/export read only the plain P.ORG (data-porg). P.Group
    // keeps its own hidden column (12), which is what its filter searches and what
    // the export writes out as a separate "P.Group" column.
    function porgCellRender(data, type, row, meta) {
        if (type === 'filter' || type === 'export') {
            var $td = $(meta.settings.aoData[meta.row].nTr).find('td').eq(meta.col);
            return $td.attr('data-porg') || '';
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

    // PO Number + PO Create Date, same merged-cell treatment as the ones above.
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
    var EXPORT_HEADER = { 0: 'Ticket No', 2: 'P.ORG', 8: 'Canvassing No' };
    function exportHeader(data, columnIdx) {
        if (EXPORT_HEADER[columnIdx]) return EXPORT_HEADER[columnIdx];
        return $('<div>').html(data).text().trim();
    }

    var table = $('#techTable').DataTable({
        "pageLength": 25,
        "order": [[ 0, "desc" ]],
        "orderCellsTop": true,
        "columnDefs": [
            { "orderable": false, "targets": [10, 11, 12, 13] },
            { "targets": 0, "render": ticketCellRender }, // Ticket No (+ PR Date beneath)
            { "targets": 2, "render": porgCellRender },   // P.ORG (+ P.Group beneath)
            { "targets": 8, "render": canvCellRender },   // Canvassing No (+ Canvassing Date beneath)
            { "targets": 9, "render": poCellRender }      // PO Number (+ PO Create Date beneath)
        ],
        "dom": '<"hidden"B>ltip',
        "buttons": [
            // modifier.page pinned to the literal string 'current' -- Buttons' own
            // default omits `page`, which DataTables' core row-selector then defaults
            // to 'all' (every row matching search, ignoring pagination). This table is
            // client-side (every row already sits in the DOM), so that default would
            // silently export everything regardless of what's on screen.
            // Column order here is the EXPORT order, not the DOM order -- PR Date (10),
            // Canvassing Date (11), P.Group (12) and PO Date (13), all four hidden, sit
            // right after the merged visible column they were split out of so the file
            // reads naturally: Ticket No | PR Date | Aging | P.ORG | P.Group | Site
            // Allocation | Requestor | Assigned PIC | Item Description | Status |
            // Canvassing No | Canvassing Date | PO Number | PO Date.
            { extend: 'excelHtml5', exportOptions: { columns: [0, 10, 1, 2, 12, 3, 4, 5, 6, 7, 8, 11, 9, 13, 14], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } },
            { extend: 'csvHtml5', exportOptions: { columns: [0, 10, 1, 2, 12, 3, 4, 5, 6, 7, 8, 11, 9, 13, 14], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } },
            { extend: 'pdfHtml5', orientation: 'landscape', pageSize: 'LEGAL', exportOptions: { columns: [0, 10, 1, 2, 12, 3, 4, 5, 6, 7, 8, 11, 9, 13, 14], modifier: { page: 'current' }, orthogonal: 'export', format: { header: exportHeader } } }
        ]
    });

    // #techTableToolbar (with Export + Global Search inside it) is authored
    // straight into the HTML above #techTableScroll, so the row renders in its
    // final position immediately -- nothing shifts once DataTables finishes.
    // "Show entries" is the one control that can't be pre-authored (DataTables
    // creates it during init), so it's moved -- not cloned -- into the empty
    // #techToolbarLength slot, keeping its own length listener intact. Without
    // this it would render inside the scrolling area and scroll out of view.
    $('#techTable_wrapper .dataTables_length').appendTo('#techToolbarLength');
    // Same treatment for the footer: "Showing x to y of z" and the pager are
    // moved below the scroll area so they no longer sit against the horizontal
    // scrollbar. Moved (not cloned) so DataTables keeps redrawing them in place.
    $('#techTable_wrapper .dataTables_info').appendTo('#techFooterInfo');
    $('#techTable_wrapper .dataTables_paginate').appendTo('#techFooterPaginate');

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

    // AUTO-APPLY STATUS FILTERS
    $('.status-filter-btn').on('click', function() {
        $('.status-filter-btn').removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 bg-purple-600 hover:bg-indigo-700 hover:bg-red-700 hover:bg-yellow-600 hover:bg-green-700 hover:bg-purple-700 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 border-purple-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');

        var status = $(this).data('status');

        if (status === 'Outstanding') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-red-600 hover:bg-red-700 text-white border-red-600 shadow-md ring-2 ring-offset-2');
        } else if (status === 'On Progress') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-yellow-500 hover:bg-yellow-600 text-white border-yellow-500 shadow-md ring-2 ring-offset-2');
        } else if (status === 'PO Created') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-green-600 hover:bg-green-700 text-white border-green-600 shadow-md ring-2 ring-offset-2');
        } else if (status === 'Sendback') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-purple-600 hover:bg-purple-700 text-white border-purple-600 shadow-md ring-2 ring-offset-2');
        } else {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        }

        if (status) {
            table.column(7).search('^' + $.fn.dataTable.util.escapeRegex(status) + '$', true, false).draw();
        } else {
            table.column(7).search('', false, false).draw(); // All Statuses
        }
    });

    // AUTO-APPLY TYPE FILTERS
    $('.type-filter-btn').on('click', function() {
        var type = $(this).data('type');
        currentTypeFilter = type || '';

        $('.type-filter-btn').removeClass('bg-indigo-600 bg-teal-600 bg-blue-600 hover:bg-indigo-700 hover:bg-teal-700 hover:bg-blue-700 text-white border-indigo-600 border-teal-600 border-blue-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');

        if (type === 'intercompany') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-teal-600 hover:bg-teal-700 text-white border-teal-600 shadow-md ring-2 ring-offset-2');
        } else if (type === 'non-intercompany') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-blue-600 hover:bg-blue-700 text-white border-blue-600 shadow-md ring-2 ring-offset-2');
        } else {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        }

        table.draw();
    });

    $('.column-search-text').on('keyup change clear', function(e) {
        e.stopPropagation();
        table.column($(this).data('col')).search(this.value).draw();
    });

    // GLOBAL SEARCH -- replaces DataTables' default "Search:" box, same as dashboard_mgr.php
    $('#techGlobalSearch').on('keyup change clear', function() {
        table.search(this.value).draw();
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
    // Status/Type quick-filter buttons back to "All", in one click.
    $('#clearAllFiltersBtn').on('click', function() {
        currentTypeFilter = '';
        currentPrDateFrom = '';
        currentPrDateTo = '';

        $('.status-filter-btn').removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 bg-purple-600 hover:bg-indigo-700 hover:bg-red-700 hover:bg-yellow-600 hover:bg-green-700 hover:bg-purple-700 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 border-purple-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $('.status-filter-btn[data-status=""]').removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');

        $('.type-filter-btn').removeClass('bg-indigo-600 bg-teal-600 bg-blue-600 hover:bg-indigo-700 hover:bg-teal-700 hover:bg-blue-700 text-white border-indigo-600 border-teal-600 border-blue-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $('.type-filter-btn[data-type=""]').removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');

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

        $('#techGlobalSearch').val('');
        table.search('');

        table.draw();
    });

});
<!-- Ticket Detail Modal -->
<div id="ticketDetailModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <!-- Background overlay with blur -->
        <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="closeTicketDetailModal()"></div>

        <!-- Center trick -->
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border border-slate-200">
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="w-8 h-8 rounded-lg bg-blue-100 text-blue-700 flex items-center justify-center text-sm font-bold">
                        <i class="fas fa-file-invoice"></i>
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 leading-none" id="ticketModalTitle">Detail Requisition (PR)</h3>
                        <span class="text-xs text-slate-400 mt-1 block">Rincian pengadaan dan seluruh baris kebutuhan</span>
                    </div>
                </div>
                <button type="button" onclick="closeTicketDetailModal()" class="w-8 h-8 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-200/60 flex items-center justify-center transition">
                    <i class="fas fa-times text-sm"></i>
                </button>
            </div>

            <!-- Modal Content Body -->
            <div id="ticketModalBody" class="p-6 max-h-[75vh] overflow-y-auto">
                <!-- Content injected via AJAX -->
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-3 bg-slate-50 border-t border-slate-200 flex justify-end">
                <button type="button" onclick="closeTicketDetailModal()" class="px-4 py-2 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded-xl text-xs transition">
                    Tutup
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openTicketDetailModal(ticketNo) {
    if (!ticketNo) return;
    $('#ticketModalTitle').text('Detail Requisition: ' + ticketNo);
    $('#ticketModalBody').html(`
        <div class="py-12 text-center text-slate-400">
            <i class="fas fa-spinner fa-spin text-3xl text-blue-600 mb-3 block"></i>
            <span class="text-sm font-medium">Memuat rincian PR...</span>
        </div>
    `);
    $('#ticketDetailModal').removeClass('hidden');
    $('body').addClass('overflow-hidden');

    $.get('ajax_pr_ticket_detail.php', { ticket_no: ticketNo }, function(html) {
        $('#ticketModalBody').html(html);
    }).fail(function() {
        $('#ticketModalBody').html('<div class="p-6 text-center text-rose-600 font-bold">Gagal memuat detail tiket.</div>');
    });
}

function closeTicketDetailModal() {
    $('#ticketDetailModal').addClass('hidden');
    $('body').removeClass('overflow-hidden');
}

$(document).keydown(function(e) {
    if (e.key === "Escape" && !$('#ticketDetailModal').hasClass('hidden')) {
        closeTicketDetailModal();
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
