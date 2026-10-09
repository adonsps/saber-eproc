<?php
// pages/dashboard_stc.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/aon_stock_alert.php';
require_once '../includes/sla_rules.php';
require_once '../includes/header.php';

if (!in_array($_SESSION['role'], ['STC', 'Category Manager', 'Manager'], true)) {
    header("Location: dashboard_pic.php");
    exit;
}

$stc_name = $_SESSION['name'] ?? '';
// Category Manager / Manager claim no AON SKUs of their own (see master_aon.php),
// so scoping the list to aon_skus.handler = their name would always be empty for
// them. They get every AON ticket instead, for oversight; an STC gets only the
// tickets whose SKU sits on a contract they personally hold.
$is_aon_oversight = in_array($_SESSION['role'], ['Category Manager', 'Manager'], true);

// PR lines whose SKU is on the Master AON list (and, for an STC, on a contract
// they own). sku/qty and the SLA-only columns are selected exactly as the PIC /
// Admin worklists do so sla_attach() and the smart-status block behave the same.
//
// Window: every line still in flight (no PO yet, whatever its age -- those are
// the ones an STC has to chase), plus lines completed in the last 60 days kept
// as confirmation the SKU made it through. A 2026 floor keeps stale pre-program
// PRs out. po_create_date is not usable here (always NULL on these rows).
$aon_where = $is_aon_oversight ? aon_sku_filter_sql('p') : aon_sku_handler_filter_sql('p');
$sql = "
    SELECT p.ticket_no, p.ticket_line_item, p.pr_date, p.item_description, p.status_ticket,
           p.requestor_name, p.p_org, p.canvassing_no, p.canvassing_status, p.canvassing_date,
           p.po_number, p.po_create_date, p.assigned_pic,
           p.ticket_type, p.p_group, p.tr_date_gp, p.canvassing_date_approver_1, p.canvassing_description,
           p.sku, p.qty, p.ticket_helpdesk_no
    FROM prs p
    WHERE LOWER(p.status_ticket) NOT LIKE '%cancel%'
      AND (p.pr_date IS NULL OR p.pr_date >= '2026-01-01')
      AND ((p.po_number IS NULL OR p.po_number = '')
           OR p.pr_date >= (CURDATE() - INTERVAL 60 DAY))
      AND {$aon_where}
    ORDER BY p.pr_date DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($is_aon_oversight ? [] : [$stc_name]);
$aon_prs = $stmt->fetchAll(PDO::FETCH_ASSOC);

sla_attach($pdo, $aon_prs);

// Same "smart status" the PIC / Admin worklists compute, so the label an STC
// sees matches what the assigned PIC sees on their own board.
foreach ($aon_prs as &$pr) {
    $has_canv = !empty(trim((string)$pr['canvassing_no']));
    $has_po   = !empty(trim((string)$pr['po_number']));
    $raw_status       = strtolower(trim((string)$pr['status_ticket']));
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

// Headline counts for the summary strip.
$aon_total     = count($aon_prs);
$aon_outstanding = 0;
$aon_progress    = 0;
$aon_po          = 0;
$aon_overdue     = 0;
foreach ($aon_prs as $pr) {
    if ($pr['display_status'] === 'Outstanding') $aon_outstanding++;
    elseif ($pr['display_status'] === 'On Progress') $aon_progress++;
    elseif ($pr['display_status'] === 'PO Created') $aon_po++;
    // "Overdue" = SLA countdown blown on a line that has no PO yet. 'na' (no PR
    // date, countdown never started) is folded in, matching the PIC board.
    if ($pr['display_status'] !== 'PO Created'
        && in_array($pr['sla_status'], ['overdue', 'na'], true)) {
        $aon_overdue++;
    }
}

// --- AON RUNNING-OUT WARNING ---------------------------------------------
// Same warning Master AON shows, repeated here because this is the page an STC
// lands on after logging in -- otherwise a SKU could expire over a day the STC
// never happened to open Master AON. Dismissing it in either place silences
// both for the session. "Show these SKUs" hands over to Master AON with the
// filter pre-applied, since there is no table to narrow here.
$stock_alerts = aon_stock_alert_rows($pdo, $_SESSION['name'] ?? '');
aon_stock_alert_render(
    $stock_alerts,
    !empty($stock_alerts) && aon_stock_alert_should_autoshow(),
    'master_aon.php?aon_alert=1'
);
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-end sm:justify-between gap-3">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Welcome, <?= htmlspecialchars($_SESSION['name']) ?></h2>
        <p class="text-sm text-gray-500 mt-1">You are logged in as <strong>STC</strong>. Use the navigation or the shortcut below to get started.</p>
    </div>
    <!-- Reopens the running-out warning after it has been dismissed. -->
    <div class="flex-shrink-0"><?= aon_stock_alert_button_html($stock_alerts) ?></div>
</div>

<!-- ============ AON TICKET TRACKING ============================================
     An STC has to be sure every AON SKU they own is actually being turned into a
     PR, then a canvassing, then a PO -- and to catch the ones that stall. This
     list is those tickets: every open/recent PR line whose SKU sits on a
     contract they hold, with the same Status and SLA badges the PIC sees, so a
     stuck line is obvious without asking the PIC. Read-only. ==================-->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<div class="mt-2 mb-8">
    <div class="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-2 mb-4">
        <div>
            <h3 class="text-lg font-bold text-slate-800">
                <?= $is_aon_oversight ? 'AON Tickets — All' : 'My AON Tickets' ?>
            </h3>
            <p class="text-sm text-slate-500 mt-0.5">
                <?= $is_aon_oversight
                    ? 'Every open or recent PR line whose SKU is on the Master AON list.'
                    : 'Open or recent PR lines whose SKU is on a contract you hold. Track each one from Outstanding &rarr; On Progress &rarr; PO Created.' ?>
            </p>
        </div>
    </div>

    <?php if (empty($aon_prs)): ?>
        <div class="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-400 text-sm">
            <?= $is_aon_oversight
                ? 'No AON tickets in the current window.'
                : 'No tickets found for the AON SKUs you own. Claim SKUs on Master AON, or check back once PRs are raised against them.' ?>
        </div>
    <?php else: ?>

    <!-- Summary strip. Each chip is also a quick filter for the table below. -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
        <button type="button" class="stc-stat text-left bg-white border border-slate-200 rounded-xl p-3 hover:border-indigo-300 hover:shadow-sm transition" data-status="" data-sla="">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total</div>
            <div class="text-xl font-extrabold text-slate-800 mt-0.5"><?= $aon_total ?></div>
        </button>
        <button type="button" class="stc-stat text-left bg-white border border-slate-200 rounded-xl p-3 hover:border-red-300 hover:shadow-sm transition" data-status="Outstanding" data-sla="">
            <div class="text-[11px] font-bold text-red-500 uppercase tracking-wider">Outstanding</div>
            <div class="text-xl font-extrabold text-slate-800 mt-0.5"><?= $aon_outstanding ?></div>
        </button>
        <button type="button" class="stc-stat text-left bg-white border border-slate-200 rounded-xl p-3 hover:border-yellow-300 hover:shadow-sm transition" data-status="On Progress" data-sla="">
            <div class="text-[11px] font-bold text-yellow-600 uppercase tracking-wider">On Progress</div>
            <div class="text-xl font-extrabold text-slate-800 mt-0.5"><?= $aon_progress ?></div>
        </button>
        <button type="button" class="stc-stat text-left bg-white border border-slate-200 rounded-xl p-3 hover:border-green-300 hover:shadow-sm transition" data-status="PO Created" data-sla="">
            <div class="text-[11px] font-bold text-green-600 uppercase tracking-wider">PO Created</div>
            <div class="text-xl font-extrabold text-slate-800 mt-0.5"><?= $aon_po ?></div>
        </button>
        <button type="button" class="stc-stat text-left bg-white border border-slate-200 rounded-xl p-3 hover:border-red-400 hover:shadow-sm transition" data-status="" data-sla="overdue" title="SLA target passed and no PO yet (includes lines with no PR date)">
            <div class="text-[11px] font-bold text-red-600 uppercase tracking-wider">Overdue</div>
            <div class="text-xl font-extrabold text-red-600 mt-0.5"><?= $aon_overdue ?></div>
        </button>
    </div>

    <div class="bg-white shadow-md rounded-lg border border-gray-200">
        <div class="flex flex-wrap items-center gap-2 px-4 pt-4 pb-2">
            <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mr-1">Status:</span>
            <?php foreach (['' => 'All', 'Outstanding' => 'Outstanding', 'On Progress' => 'On Progress', 'PO Created' => 'PO Created'] as $val => $lbl): ?>
            <button type="button" class="stc-status-btn px-3 py-1.5 text-xs font-bold rounded-lg border transition <?= $val === '' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50' ?>" data-status="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></button>
            <?php endforeach; ?>
            <span class="text-[11px] font-bold text-gray-500 uppercase tracking-wider mx-1 ml-3">SLA:</span>
            <?php foreach (['' => 'All', 'overdue' => 'Overdue', 'due_soon' => 'Due Soon', 'on_track' => 'On Track', 'done' => 'Done'] as $val => $lbl): ?>
            <button type="button" class="stc-sla-btn px-3 py-1.5 text-xs font-bold rounded-lg border transition <?= $val === '' ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50' ?>" data-sla="<?= htmlspecialchars($val) ?>"><?= htmlspecialchars($lbl) ?></button>
            <?php endforeach; ?>
            <input type="text" id="stcGlobalSearch" class="ml-auto border border-gray-300 rounded-md px-2 py-1.5 text-xs" placeholder="Search all columns..." style="width: 200px;">
        </div>
        <div class="overflow-x-auto px-4 pb-4 pt-1">
            <table id="stcAonTable" class="min-w-full text-left text-sm">
                <thead>
                    <tr class="text-[11px] font-bold text-gray-600 uppercase tracking-wider bg-gray-50">
                        <th class="px-3 py-2 border-b-2 border-gray-200">Ticket No / PR Date</th>
                        <th class="px-2 py-2 border-b-2 border-gray-200 text-center">SLA</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">AON SKU</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">P.ORG</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">Requestor</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">Item Description</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">Status</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">Canvassing No / Date</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">PO Number</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">Assigned PIC</th>
                        <th class="px-3 py-2 border-b-2 border-gray-200">Helpdesk No.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Row order: oldest still-Outstanding first, then On Progress,
                    // then everything already closed (PO Created / Cancelled / …).
                    // The Status cell's data-order carries this rank; the second
                    // sort key is the PR date (column 0), ascending = oldest.
                    $status_rank = [
                        'Outstanding' => 0, 'On Progress' => 1, 'Escalated' => 2,
                        'Sendback' => 3, 'PO Created' => 4, 'Canvassing Cancelled' => 5,
                    ];
                    foreach ($aon_prs as $pr):
                        $rank = $status_rank[$pr['display_status']] ?? 6;
                        $sc = 'bg-gray-100 text-gray-800 border-gray-200';
                        if ($pr['display_status'] === 'Outstanding') $sc = 'bg-red-100 text-red-800 border-red-200';
                        elseif ($pr['display_status'] === 'On Progress') $sc = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                        elseif ($pr['display_status'] === 'PO Created') $sc = 'bg-green-100 text-green-800 border-green-200';
                        elseif ($pr['display_status'] === 'Escalated') $sc = 'bg-orange-100 text-orange-800 border-orange-200';
                        elseif ($pr['display_status'] === 'Sendback') $sc = 'bg-purple-100 text-purple-800 border-purple-200';
                        elseif ($pr['display_status'] === 'Canvassing Cancelled') $sc = 'bg-red-500 text-white border-red-600';
                    ?>
                    <tr class="border-b border-gray-100 hover:bg-indigo-50/50 transition">
                        <td class="px-3 py-2 font-medium text-gray-900 whitespace-nowrap" data-order="<?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?>">
                            <?= htmlspecialchars($pr['ticket_no']) ?>
                            <?php if ($pr['pr_date']): ?><div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['pr_date'])) ?></div><?php endif; ?>
                        </td>
                        <td class="px-2 py-2 text-center" data-order="<?= (int)$pr['sla_sort'] ?>" data-search="<?= htmlspecialchars($pr['sla_status']) ?>">
                            <span class="<?= $pr['sla_badge'] ?> py-1 px-1 rounded text-[10px] font-bold border block w-fit min-w-[54px] mx-auto text-center whitespace-nowrap" title="<?= htmlspecialchars($pr['sla_title'], ENT_QUOTES) ?>"><?= htmlspecialchars($pr['sla_label']) ?></span>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs text-gray-700 whitespace-nowrap"><?= htmlspecialchars(trim((string)$pr['sku'])) ?></td>
                        <td class="px-3 py-2 text-gray-700"><?= htmlspecialchars($pr['p_org']) ?></td>
                        <td class="px-3 py-2 text-gray-700"><div class="truncate max-w-[140px]" title="<?= htmlspecialchars($pr['requestor_name']) ?>"><?= htmlspecialchars($pr['requestor_name']) ?></div></td>
                        <td class="px-3 py-2 text-gray-700"><div class="truncate max-w-[260px]" title="<?= htmlspecialchars($pr['item_description']) ?>"><?= htmlspecialchars($pr['item_description']) ?></div></td>
                        <td class="px-3 py-2" data-order="<?= $rank ?>" data-search="<?= htmlspecialchars($pr['display_status']) ?>">
                            <span class="<?= $sc ?> py-1 px-2 rounded-md text-[10px] font-bold border inline-block text-center whitespace-nowrap"><?= htmlspecialchars($pr['display_status']) ?></span>
                        </td>
                        <td class="px-3 py-2 text-gray-700 whitespace-nowrap" data-order="<?= $pr['canvassing_date'] ? date('Y-m-d', strtotime($pr['canvassing_date'])) : '' ?>">
                            <?= htmlspecialchars($pr['canvassing_no'] ?: '--') ?>
                            <?php if ($pr['canvassing_date']): ?><div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['canvassing_date'])) ?></div><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-700 font-medium whitespace-nowrap">
                            <?= htmlspecialchars($pr['po_number'] ?: '--') ?>
                            <?php if (!empty($pr['po_number']) && !empty($pr['po_create_date'])): ?><div class="text-[10px] text-gray-400 mt-0.5"><?= date('d M Y', strtotime($pr['po_create_date'])) ?></div><?php endif; ?>
                        </td>
                        <td class="px-3 py-2 text-gray-700"><div class="truncate max-w-[140px]" title="<?= htmlspecialchars($pr['assigned_pic'] ?? '') ?>"><?= htmlspecialchars($pr['assigned_pic'] ?: '--') ?></div></td>
                        <td class="px-3 py-2 text-gray-700 whitespace-nowrap"><?= htmlspecialchars(trim((string)($pr['ticket_helpdesk_no'] ?? '')) !== '' ? $pr['ticket_helpdesk_no'] : '--') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($aon_prs)): ?>
<script>
$(function () {
    if (!$.fn.dataTable) return;   // CDN blocked -- leave the static table as-is
    var stcStatusFilter = '';
    var stcSlaFilter    = '';

    // Row-level filter. The Status <td> (index 6) carries data-search with the
    // display status; the SLA <td> (index 1) carries data-search with the
    // sla_status key. 'na' (no PR date, countdown never started) folds into
    // Overdue, same as the PIC board's SLA quick filter.
    $.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
        if (settings.nTable.id !== 'stcAonTable') return true;
        var cells = settings.aoData[dataIndex].anCells;
        if (stcStatusFilter) {
            if ((cells[6].getAttribute('data-search') || '') !== stcStatusFilter) return false;
        }
        if (stcSlaFilter) {
            var sla = cells[1].getAttribute('data-search') || '';
            if (stcSlaFilter === 'overdue') {
                if (sla !== 'overdue' && sla !== 'na') return false;
            } else if (sla !== stcSlaFilter) {
                return false;
            }
        }
        return true;
    });

    var stcTable = $('#stcAonTable').DataTable({
        // Oldest Outstanding first, then On Progress, then closed lines. Status
        // rank is column 6's data-order; PR date (column 0) breaks the tie,
        // ascending so the longest-waiting line is on top.
        order: [[6, 'asc'], [0, 'asc']],
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, 'All']],
        dom: "<'flex flex-wrap items-center justify-between gap-2 mb-2'lB>rt<'flex flex-wrap items-center justify-between gap-2 mt-2'ip>",
        buttons: [
            { extend: 'excelHtml5', className: 'text-xs', text: 'Excel', exportOptions: { columns: ':visible' } },
            { extend: 'csvHtml5',   className: 'text-xs', text: 'CSV',   exportOptions: { columns: ':visible' } }
        ],
        columnDefs: [{ targets: [1, 6], type: 'num' }]
    });

    $('#stcGlobalSearch').on('keyup', function () { stcTable.search(this.value).draw(); });

    function styleGroup(sel, attr, activeVal) {
        $(sel).each(function () {
            var on = (this.getAttribute('data-' + attr) || '') === activeVal;
            $(this).toggleClass('bg-indigo-600 text-white border-indigo-600', on);
            $(this).toggleClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50', !on);
        });
    }

    $('.stc-status-btn').on('click', function () {
        stcStatusFilter = this.getAttribute('data-status') || '';
        styleGroup('.stc-status-btn', 'status', stcStatusFilter);
        stcTable.draw();
    });
    $('.stc-sla-btn').on('click', function () {
        stcSlaFilter = this.getAttribute('data-sla') || '';
        styleGroup('.stc-sla-btn', 'sla', stcSlaFilter);
        stcTable.draw();
    });
    // Summary chips drive both filter groups at once.
    $('.stc-stat').on('click', function () {
        stcStatusFilter = this.getAttribute('data-status') || '';
        stcSlaFilter    = this.getAttribute('data-sla') || '';
        styleGroup('.stc-status-btn', 'status', stcStatusFilter);
        styleGroup('.stc-sla-btn', 'sla', stcSlaFilter);
        stcTable.draw();
    });
});
</script>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mt-6 max-w-2xl">
    <a href="master_aon.php" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all group">
        <div class="flex items-center mb-3">
            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center mr-3 group-hover:bg-indigo-200 transition">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path></svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 group-hover:text-indigo-700 transition">Master AON</h3>
        </div>
        <p class="text-sm text-slate-500">Manage Always On SKU master data — add, edit, and maintain the active AON SKU list.</p>
    </a>

    <?php /* Import is Category Manager only; this dashboard is shared with STC. */ ?>
    <?php if (aon_can_upload($_SESSION['role'] ?? '')): ?>
    <a href="upload_aon.php" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all group">
        <div class="flex items-center mb-3">
            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center mr-3 group-hover:bg-indigo-200 transition">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 group-hover:text-indigo-700 transition">Upload AON</h3>
        </div>
        <p class="text-sm text-slate-500">Import the Price Per Vendor &amp; Tiering file to populate the Master AON list.</p>
    </a>
    <?php endif; ?>

    <a href="aon_repeat_alert.php" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all group">
        <div class="flex items-center mb-3">
            <div class="w-10 h-10 rounded-lg bg-amber-100 flex items-center justify-center mr-3 group-hover:bg-amber-200 transition">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 group-hover:text-indigo-700 transition">Repeat Purchase Alert</h3>
        </div>
        <p class="text-sm text-slate-500">SKUs bought repeatedly outside AON — review for Price Lock and track contract/MOU follow-up.</p>
    </a>

    <a href="announcement_list.php" class="bg-white border border-slate-200 rounded-xl p-6 shadow-sm hover:shadow-md hover:border-indigo-300 transition-all group">
        <div class="flex items-center mb-3">
            <div class="w-10 h-10 rounded-lg bg-indigo-100 flex items-center justify-center mr-3 group-hover:bg-indigo-200 transition">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10l6 6v8a2 2 0 01-2 2z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 4v6h6M8 13h8M8 17h5"></path></svg>
            </div>
            <h3 class="text-base font-bold text-slate-800 group-hover:text-indigo-700 transition">Announcements</h3>
        </div>
        <p class="text-sm text-slate-500">View the latest company and team announcements.</p>
    </a>
</div>

<?php require_once '../includes/footer.php'; ?>
