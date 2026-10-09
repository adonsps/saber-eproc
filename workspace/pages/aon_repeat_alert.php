<?php
// pages/aon_repeat_alert.php
// Repeat Purchase Alert — flags SKUs bought repeatedly via ad-hoc Purchase
// Requests (prs) that never became an AON contract, so STC can evaluate
// them for Price Lock / contract negotiation. STC tracks which alerts have
// been followed up on (contract/MOU renewal check) via a review status
// (On Review / Ignore) + remarks per SKU — remarks are mandatory on Ignore.
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';
require_once '../config/database.php';
require_once '../includes/header.php';

if (!aon_can_access($_SESSION['role'] ?? '')) {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Access Denied.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

$window_choices = aon_alert_window_choices();
$window    = (int)($_GET['window'] ?? 6);
if (!in_array($window, $window_choices, true)) $window = 6;
$min_count = (int)($_GET['min_count'] ?? 3);
if ($min_count < 2) $min_count = 2;
if ($min_count > 50) $min_count = 50;

$candidates = aon_get_repeat_purchase_candidates($pdo, $window, $min_count);

// Actual calendar range the selected window covers, shown beside Apply so STC
// sees exactly which dates "Last N months" resolves to (e.g. 09 Jun – 09 Jul 2026).
$window_to_iso   = date('Y-m-d');
$window_from_iso = date('Y-m-d', strtotime("-{$window} months"));

function repeat_alert_fmt_date($iso) {
    if (empty($iso)) return '—';
    $ts = strtotime($iso);
    return $ts ? date('d M Y', $ts) : '—';
}

?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<div class="max-w-full">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4 mb-6 border-b pb-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Repeat Purchase Alert</h2>
            <p class="text-sm text-gray-500 mt-1">SKUs bought repeatedly via Purchase Requests but not yet an AON contract — potential Price Lock candidates. Check each SKU for contract/MOU renewal follow-up.</p>
        </div>
        <a href="master_aon.php"
            class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold px-5 py-2 rounded-lg shadow-sm text-sm transition inline-flex items-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            Back to Master AON
        </a>
    </div>

    <!-- Filters -->
    <form method="GET" class="flex flex-wrap items-end gap-3 mb-4 bg-white border border-slate-200 rounded-xl p-4">
        <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Time Window</label>
            <select name="window" class="border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <?php foreach ($window_choices as $wc): ?>
                <option value="<?= $wc ?>" <?= $wc === $window ? 'selected' : '' ?>>Last <?= $wc ?> month<?= $wc > 1 ? 's' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Min. Purchases</label>
            <input type="number" name="min_count" min="2" max="50" value="<?= $min_count ?>"
                class="w-24 border border-slate-200 rounded-lg px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
        </div>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-1.5 rounded-lg text-sm transition">Apply</button>

        <div id="windowRange" class="self-center text-xs text-slate-500 flex items-center gap-1.5">
            <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <span class="wr-from font-semibold text-slate-700"><?= repeat_alert_fmt_date($window_from_iso) ?></span>
            <span class="text-slate-400">–</span>
            <span class="wr-to font-semibold text-slate-700"><?= repeat_alert_fmt_date($window_to_iso) ?></span>
        </div>

        <div class="ml-auto flex items-center gap-2">
            <button type="button" id="uncheckedFilterBtn" data-active="0"
                class="px-4 py-1.5 rounded-lg text-sm font-bold border transition bg-white text-indigo-700 border-indigo-300 hover:bg-indigo-50">
                Show Unchecked Only
            </button>
            <div class="relative" id="alertExportDropdownContainer">
                <button type="button" id="alertExportBtn"
                    class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-1.5 rounded-lg text-sm transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Export
                </button>
                <div id="alertExportMenu" class="hidden absolute right-0 mt-2 w-40 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                    <div class="py-1">
                        <button type="button" id="alertExportExcel" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                        <button type="button" id="alertExportCSV" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                        <button type="button" id="alertExportPDF" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Flash -->
    <div id="alertFlash" class="hidden mb-4 px-4 py-3 rounded-lg font-semibold text-sm"></div>

    <!-- Page size -->
    <div class="flex items-center gap-2 mb-3 text-xs text-slate-500">
        <span>Show</span>
        <select id="pageSizeSelect" class="border border-slate-200 rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-400">
            <option value="25" selected>25</option>
            <option value="50">50</option>
            <option value="100">100</option>
        </select>
        <span>rows</span>
    </div>

    <!-- Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        <table class="w-full text-sm text-left table-fixed">
            <colgroup>
                <col style="width:84px">   <!-- Actions -->
                <col style="width:10%">    <!-- SKU Code -->
                <col>                      <!-- Item Name (flex) -->
                <col style="width:13%">    <!-- Taxonomy -->
                <col style="width:8%">     <!-- PR Tickets -->
                <col style="width:8%">     <!-- Total PO -->
                <col style="width:16%">    <!-- Vendor(s) -->
                <col style="width:14%">    <!-- STC Review -->
            </colgroup>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 text-center">Actions</div>
                        <input type="text" class="invisible w-full px-2 py-1 text-xs border border-slate-200 rounded" tabindex="-1" aria-hidden="true">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 whitespace-nowrap">SKU Code</div>
                        <input type="text" data-col="1" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 whitespace-nowrap">Item Name</div>
                        <input type="text" data-col="2" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 whitespace-nowrap">Taxonomy</div>
                        <input type="text" data-col="3" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <button type="button" id="prTicketsSortBtn" title="Sort by PR tickets"
                            class="w-full flex items-center justify-center gap-1 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 hover:text-indigo-600 transition">
                            PR Tickets <span id="prTicketsSortIcon">⇅</span>
                        </button>
                        <input type="text" class="invisible w-full px-2 py-1 text-xs border border-slate-200 rounded" tabindex="-1" aria-hidden="true">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <button type="button" id="totalPoSortBtn" title="Sort by total PO"
                            class="w-full flex items-center justify-center gap-1 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 hover:text-indigo-600 transition">
                            Total PO <span id="totalPoSortIcon">⇅</span>
                        </button>
                        <input type="text" class="invisible w-full px-2 py-1 text-xs border border-slate-200 rounded" tabindex="-1" aria-hidden="true">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 whitespace-nowrap">Vendor(s)</div>
                        <input type="text" data-col="6" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 whitespace-nowrap">STC Review</div>
                        <select id="reviewFilterSelect" class="w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400 text-slate-600">
                            <option value="">All</option>
                            <option value="REVIEW">On Review</option>
                            <option value="IGNORE">Ignored</option>
                            <option value="NONE">Not Checked</option>
                        </select>
                    </th>
                </tr>
            </thead>
            <tbody id="alertTbody">
                <?php if (empty($candidates)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-slate-400 text-sm">
                        No repeat-purchase alerts for the selected window / threshold.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($candidates as $row):
                    $sku      = $row['sku'];
                    $checked  = !empty($row['is_checked']);
                    $review_status = $row['review_status'] ?? null;
                    $po_rows  = $row['po_rows'] ?? [];

                    $review_detail_json = htmlspecialchars(json_encode([
                        'sku'           => $sku,
                        'item_name'     => $row['item_name'],
                        'is_checked'    => $checked,
                        'review_status' => $review_status ?? '',
                        'remarks'       => $row['remarks'] ?? '',
                        'checked_by'    => $row['checked_by'] ?? '',
                        'checked_at'    => !empty($row['checked_at']) ? aon_format_datetime($row['checked_at']) : '',
                    ]), ENT_QUOTES);

                    $view_detail_json = htmlspecialchars(json_encode([
                        'sku'             => $sku,
                        'item_name'       => $row['item_name'],
                        'taxonomy'        => $row['taxonomy'],
                        'purchase_count'  => (int)$row['purchase_count'],
                        'po_count'        => (int)$row['po_count'],
                        'total_qty'       => $row['total_qty'],
                        'total_po_price'  => $row['total_po_price'],
                        'avg_unit_price'  => $row['avg_unit_price'],
                        'top_vendor'      => $row['top_vendor'],
                        'po_rows'         => array_map(fn($p) => [
                            'po_number'   => $p['po_number'],
                            'ticket_no'   => $p['ticket_no'],
                            'date'        => repeat_alert_fmt_date($p['pr_date']),
                            'qty'         => $p['qty'],
                            'vendor_name' => $p['vendor_name'],
                            'po_amount'   => $p['po_amount'],
                        ], $po_rows),
                    ]), ENT_QUOTES);
                ?>
                <?php $tax_levels = $row['taxonomy_levels'] ?? ['', '', '', '', '']; ?>
                <tr class="main-row border-b border-slate-100 hover:bg-indigo-50/30 transition" data-checked="<?= $checked ? '1' : '0' ?>" data-review="<?= htmlspecialchars($review_status ?? '') ?>"
                    data-tax0="<?= htmlspecialchars($tax_levels[0]) ?>" data-tax1="<?= htmlspecialchars($tax_levels[1]) ?>"
                    data-tax2="<?= htmlspecialchars($tax_levels[2]) ?>" data-tax3="<?= htmlspecialchars($tax_levels[3]) ?>"
                    data-tax4="<?= htmlspecialchars($tax_levels[4]) ?>">
                    <td class="px-2 py-2.5">
                        <div class="flex items-center justify-center gap-1">
                            <button type="button" class="js-open-detail inline-flex items-center justify-center w-7 h-7 rounded-lg bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white transition" title="View PO purchase history"
                                data-detail='<?= $view_detail_json ?>'>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </button>
                            <button type="button" class="js-open-review inline-flex items-center justify-center w-7 h-7 rounded-lg bg-amber-50 text-amber-600 hover:bg-amber-500 hover:text-white transition" title="STC review"
                                data-detail='<?= $review_detail_json ?>'>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                        </div>
                    </td>
                    <td class="px-3 py-2.5 font-mono text-xs font-bold text-slate-800 truncate"><?= htmlspecialchars($sku) ?></td>
                    <td class="px-3 py-2.5 text-slate-700">
                        <div class="truncate" title="<?= htmlspecialchars($row['item_name']) ?>"><?= htmlspecialchars($row['item_name']) ?></div>
                    </td>
                    <td class="px-3 py-2.5 text-xs text-slate-600">
                        <div class="truncate" title="<?= htmlspecialchars($row['taxonomy'] ?? '') ?>"><?= htmlspecialchars($row['taxonomy'] ?? '—') ?></div>
                    </td>
                    <td class="px-3 py-2.5 text-center font-bold text-slate-800"><?= (int)$row['purchase_count'] ?></td>
                    <td class="px-3 py-2.5 text-center font-bold text-slate-800"><?= (int)$row['po_count'] ?></td>
                    <td class="px-3 py-2.5 text-xs text-slate-600">
                        <div class="truncate" title="<?= htmlspecialchars($row['top_vendor'] ?? '') ?>"><?= htmlspecialchars($row['top_vendor'] ?? '—') ?></div>
                    </td>
                    <td class="px-3 py-2.5">
                        <div class="flex items-center gap-2">
                            <?php if ($review_status === 'IGNORE'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-rose-50 text-rose-600 border border-rose-200">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636L5.636 18.364M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                Ignored
                            </span>
                            <?php elseif ($review_status === 'REVIEW'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                On Review
                            </span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                <svg class="w-2 h-2" fill="currentColor" viewBox="0 0 8 8"><circle cx="4" cy="4" r="4"/></svg>
                                Not Checked
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-200 text-xs text-slate-500">
            <div id="alertPageInfo">Showing 0 of 0 entries</div>
            <div id="alertPageButtons" class="flex items-center gap-1"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════ REVIEW MODAL ═══════════════════════ -->
<div id="reviewModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 class="text-base font-bold text-slate-800">STC Review</h3>
            <p id="rm_sku_label" class="text-xs text-slate-500 mt-1 font-mono"></p>
        </div>
        <div class="px-6 py-5 space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Status</label>
                <select id="rm_review_status" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">Not Checked</option>
                    <option value="REVIEW">On Review</option>
                    <option value="IGNORE">Ignore</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Remarks <span id="rm_remarks_required" class="hidden text-red-500">*</span></label>
                <textarea id="rm_remarks" rows="4" placeholder="e.g. Contacted vendor re: MOU renewal, waiting on quote"
                    class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"></textarea>
                <p id="rm_remarks_error" class="hidden text-[11px] text-red-600 mt-1">Remarks are required when marking as Ignore.</p>
            </div>
            <p id="rm_meta" class="text-[11px] text-slate-400"></p>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3">
            <button id="rm_cancel" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="rm_save" type="button" class="px-4 py-2 text-sm font-bold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">Save Review</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════ DETAIL MODAL ═══════════════════════ -->
<div id="detailModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl max-h-[92vh] flex flex-col">

        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-start justify-between flex-shrink-0">
            <div class="min-w-0">
                <div id="dm_sku_code" class="font-mono font-bold text-indigo-700 text-sm tracking-wider"></div>
                <div id="dm_item_name" class="text-base font-bold text-slate-800 mt-0.5 leading-snug"></div>
                <div id="dm_taxonomy" class="text-xs text-slate-400 mt-0.5"></div>
            </div>
            <button type="button" id="closeDetailBtn" class="text-slate-400 hover:text-slate-600 transition ml-4 mt-0.5 flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Scrollable body -->
        <div class="px-6 py-5 overflow-y-auto flex-1 space-y-5">

            <!-- Summary -->
            <div class="space-y-3">
                <!-- Purchase counts -->
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1 whitespace-nowrap">PR Tickets</div>
                        <div id="dm_purchase_count" class="text-lg font-bold text-slate-700"></div>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1 whitespace-nowrap">Total PO</div>
                        <div id="dm_po_count" class="text-lg font-bold text-slate-700"></div>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1 whitespace-nowrap">Total Qty</div>
                        <div id="dm_total_qty" class="text-lg font-bold text-slate-700"></div>
                    </div>
                </div>

                <!-- Price metrics — the key figures for Price Lock evaluation -->
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-indigo-50 rounded-lg p-3 text-center border border-indigo-100">
                        <div class="text-[10px] text-indigo-400 font-bold uppercase mb-1 whitespace-nowrap">Total PO Price</div>
                        <div id="dm_total_po_price" class="text-lg font-bold text-indigo-700 whitespace-nowrap"></div>
                    </div>
                    <div class="bg-indigo-50 rounded-lg p-3 text-center border border-indigo-100">
                        <div class="text-[10px] text-indigo-400 font-bold uppercase mb-1 whitespace-nowrap">Avg Unit Price</div>
                        <div id="dm_avg_unit_price" class="text-lg font-bold text-indigo-700 whitespace-nowrap"></div>
                    </div>
                </div>

                <!-- Top vendor — full width so the name isn't cut off -->
                <div class="bg-slate-50 rounded-lg p-3 border border-slate-100 flex items-center justify-between gap-3">
                    <div class="text-[10px] text-slate-400 font-bold uppercase whitespace-nowrap">Top Vendor</div>
                    <div id="dm_vendors" class="text-sm font-bold text-slate-700 text-right"></div>
                </div>
            </div>

            <hr class="border-slate-100">

            <!-- PO purchase history -->
            <div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">PO Purchase History <span class="normal-case font-normal text-slate-400">— verify each PO actually purchased this SKU</span></div>
                <table class="w-full text-xs border border-slate-100 rounded-lg overflow-hidden table-fixed">
                    <colgroup>
                        <col style="width:16%">
                        <col style="width:17%">
                        <col style="width:14%">
                        <col style="width:8%">
                        <col>
                        <col style="width:18%">
                    </colgroup>
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="px-3 py-1.5 text-center font-bold whitespace-nowrap">PO Number</th>
                            <th class="px-3 py-1.5 text-center font-bold whitespace-nowrap">PR Ticket</th>
                            <th class="px-3 py-1.5 text-center font-bold whitespace-nowrap">PR Date</th>
                            <th class="px-3 py-1.5 text-center font-bold whitespace-nowrap">Qty</th>
                            <th class="px-3 py-1.5 text-center font-bold whitespace-nowrap">Vendor</th>
                            <th class="px-3 py-1.5 text-center font-bold whitespace-nowrap">PO Price</th>
                        </tr>
                    </thead>
                    <tbody id="dm_po_rows"></tbody>
                </table>
            </div>
        </div>

        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex-shrink-0 border-t border-slate-100 flex justify-end">
            <button type="button" id="closeDetailBtnBottom" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    // ── Column search ─────────────────────────────────────────────────────────
    var filters = {};
    document.querySelectorAll('.col-search').forEach(function (inp) {
        inp.addEventListener('input', function () {
            filters[this.dataset.col] = this.value.trim().toLowerCase();
            currentPage = 1;
            applyFilters();
        });
    });

    var unchecked = false;
    var toggleBtn = document.getElementById('uncheckedFilterBtn');
    toggleBtn.addEventListener('click', function () {
        unchecked = !unchecked;
        this.dataset.active = unchecked ? '1' : '0';
        this.className = unchecked
            ? 'px-4 py-1.5 rounded-lg text-sm font-bold border transition bg-indigo-600 text-white border-indigo-600'
            : 'px-4 py-1.5 rounded-lg text-sm font-bold border transition bg-white text-indigo-700 border-indigo-300 hover:bg-indigo-50';
        currentPage = 1;
        applyFilters();
    });

    // ── STC Review status filter (All / On Review / Ignored / Not Checked) ──────
    var reviewFilter = '';
    var reviewFilterSelect = document.getElementById('reviewFilterSelect');
    reviewFilterSelect.addEventListener('change', function () {
        reviewFilter = this.value;
        currentPage = 1;
        applyFilters();
    });

    // ── Live date-range preview beside Apply (updates as the window changes) ────
    var windowSelect = document.querySelector('select[name="window"]');
    var windowRange  = document.getElementById('windowRange');
    var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function fmtRangeDate(d) { return ('0' + d.getDate()).slice(-2) + ' ' + MON[d.getMonth()] + ' ' + d.getFullYear(); }
    windowSelect.addEventListener('change', function () {
        var months = parseInt(this.value, 10) || 0;
        var to = new Date(), from = new Date();
        from.setMonth(from.getMonth() - months);
        windowRange.querySelector('.wr-from').textContent = fmtRangeDate(from);
        windowRange.querySelector('.wr-to').textContent   = fmtRangeDate(to);
    });

    // ── Pagination (25 / 50 / 100 per page) ─────────────────────────────────
    var pageSizeSelect = document.getElementById('pageSizeSelect');
    var pageInfoEl     = document.getElementById('alertPageInfo');
    var pageButtonsEl  = document.getElementById('alertPageButtons');
    var currentPage = 1;
    var pageSize = parseInt(pageSizeSelect.value, 10);

    pageSizeSelect.addEventListener('change', function () {
        pageSize = parseInt(this.value, 10);
        currentPage = 1;
        applyFilters();
    });

    function getMatchingRows() {
        return Array.from(document.querySelectorAll('tr.main-row')).filter(function (row) {
            if (unchecked && row.dataset.checked !== '0') return false;
            if (reviewFilter) {
                var rv = row.dataset.review || '';
                if (reviewFilter === 'NONE') { if (rv !== '') return false; }
                else if (rv !== reviewFilter) return false;
            }
            var cells = row.querySelectorAll('td');
            for (var col in filters) {
                if (!filters[col]) continue;
                var cell = cells[parseInt(col)];
                if (cell && cell.textContent.toLowerCase().indexOf(filters[col]) === -1) return false;
            }
            return true;
        });
    }

    function renderPageButtons(totalPages) {
        pageButtonsEl.innerHTML = '';
        if (totalPages <= 1) return;

        function makeBtn(label, page, opts) {
            opts = opts || {};
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            var base = 'px-2.5 py-1 rounded-md text-xs font-bold border transition ';
            if (opts.active) {
                btn.className = base + 'bg-indigo-600 text-white border-indigo-600';
            } else if (opts.disabled) {
                btn.className = base + 'bg-slate-50 text-slate-300 border-slate-200 cursor-not-allowed';
                btn.disabled = true;
            } else {
                btn.className = base + 'bg-white text-slate-600 border-slate-200 hover:bg-indigo-50';
            }
            if (!opts.disabled) {
                btn.addEventListener('click', function () {
                    currentPage = page;
                    applyFilters();
                });
            }
            return btn;
        }

        pageButtonsEl.appendChild(makeBtn('Previous', currentPage - 1, { disabled: currentPage === 1 }));

        var maxButtons = 5;
        var start = Math.max(1, currentPage - Math.floor(maxButtons / 2));
        var end   = Math.min(totalPages, start + maxButtons - 1);
        start = Math.max(1, end - maxButtons + 1);

        if (start > 1) {
            pageButtonsEl.appendChild(makeBtn('1', 1));
            if (start > 2) pageButtonsEl.appendChild(makeBtn('…', 0, { disabled: true }));
        }
        for (var p = start; p <= end; p++) {
            pageButtonsEl.appendChild(makeBtn(String(p), p, { active: p === currentPage }));
        }
        if (end < totalPages) {
            if (end < totalPages - 1) pageButtonsEl.appendChild(makeBtn('…', 0, { disabled: true }));
            pageButtonsEl.appendChild(makeBtn(String(totalPages), totalPages));
        }

        pageButtonsEl.appendChild(makeBtn('Next', currentPage + 1, { disabled: currentPage === totalPages }));
    }

    function applyFilters() {
        var matched = getMatchingRows();
        var totalPages = Math.max(1, Math.ceil(matched.length / pageSize));
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        var start = (currentPage - 1) * pageSize;
        var pageRows = matched.slice(start, start + pageSize);
        var pageRowSet = new Set(pageRows);

        document.querySelectorAll('tr.main-row').forEach(function (row) {
            row.style.display = pageRowSet.has(row) ? '' : 'none';
        });

        var total = matched.length;
        pageInfoEl.textContent = total === 0
            ? 'Showing 0 of 0 entries'
            : 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, total) + ' of ' + total + ' entries';
        renderPageButtons(totalPages);
    }

    applyFilters();

    // ── Export (Excel / CSV / PDF) — respects active search/unchecked/review
    // filters, all pages (not just the current page shown on screen).
    var EXPORT_TITLE = 'Repeat Purchase Alert';
    var EXPORT_HEADER = ['SKU Code', 'Item Name', 'Level 0', 'Level 1', 'Level 2', 'Level 3', 'Level 4', 'PR Tickets', 'Total PO', 'Vendor(s)', 'STC Review'];
    var EXPORT_REVIEW_LABEL = { REVIEW: 'On Review', IGNORE: 'Ignored', '': 'Not Checked' };

    function getExportData() {
        var rows = getMatchingRows().map(function (row) {
            var cells = row.querySelectorAll('td');
            return [
                cells[1].textContent.trim(),
                cells[2].textContent.trim(),
                row.dataset.tax0 || '',
                row.dataset.tax1 || '',
                row.dataset.tax2 || '',
                row.dataset.tax3 || '',
                row.dataset.tax4 || '',
                cells[4].textContent.trim(),
                cells[5].textContent.trim(),
                cells[6].textContent.trim(),
                EXPORT_REVIEW_LABEL[row.dataset.review || ''] || 'Not Checked',
            ];
        });
        // .slice() so each call gets its own header array — pdfMake mutates the
        // row/cell objects it's handed in place while laying out the table, and
        // without this, a PDF export first would leave EXPORT_HEADER's shared
        // array poisoned with pdfMake's internal nodes for every export after it.
        return [EXPORT_HEADER.slice()].concat(rows);
    }

    function exportFileName(ext) {
        return 'repeat_purchase_alert_' + new Date().toISOString().slice(0, 10) + '.' + ext;
    }

    var exportBtn = document.getElementById('alertExportBtn');
    var exportMenu = document.getElementById('alertExportMenu');
    exportBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        exportMenu.classList.toggle('hidden');
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#alertExportDropdownContainer')) exportMenu.classList.add('hidden');
    });

    document.getElementById('alertExportCSV').addEventListener('click', function () {
        exportMenu.classList.add('hidden');
        function csvEscape(v) {
            v = v == null ? '' : String(v);
            return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
        }
        var lines = [csvEscape(EXPORT_TITLE), ''].concat(
            getExportData().map(function (row) { return row.map(csvEscape).join(','); })
        );
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        a.download = exportFileName('csv');
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    });

    document.getElementById('alertExportExcel').addEventListener('click', function () {
        exportMenu.classList.add('hidden');
        var blankRow = EXPORT_HEADER.map(function () { return ''; });
        var ws = XLSX.utils.aoa_to_sheet([[EXPORT_TITLE], blankRow].concat(getExportData()));
        var wb = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(wb, ws, 'Repeat Purchase Alert');
        XLSX.writeFile(wb, exportFileName('xlsx'));
    });

    document.getElementById('alertExportPDF').addEventListener('click', function () {
        exportMenu.classList.add('hidden');
        var data = getExportData();
        pdfMake.createPdf({
            pageOrientation: 'landscape',
            pageMargins: [20, 30, 20, 20],
            content: [
                { text: 'Repeat Purchase Alert', style: 'title' },
                {
                    table: {
                        headerRows: 1,
                        widths: ['7%', '19%', '8%', '8%', '8%', '8%', '8%', '7%', '7%', '12%', '8%'],
                        body: data,
                    },
                    layout: {
                        fillColor: function (rowIndex) { return rowIndex === 0 ? '#eef2ff' : null; },
                    },
                    fontSize: 8,
                },
            ],
            styles: {
                title: { fontSize: 14, bold: true, margin: [0, 0, 0, 10] },
            },
            defaultStyle: { fontSize: 8 },
        }).download(exportFileName('pdf'));
    });

    // ── Numeric column sort (PR Tickets / Total PO) ─────────────────────────────
    // Reorders the real rows in the tbody, then re-paginates so the sorted order
    // flows through the existing filter/pagination pipeline.
    var alertTbody = document.getElementById('alertTbody');
    var sortDir = {};
    function makeNumSort(btnId, iconId, cellIndex, key) {
        var btn  = document.getElementById(btnId);
        var icon = document.getElementById(iconId);
        if (!btn) return;
        btn.addEventListener('click', function () {
            var dir = sortDir[key] === 'asc' ? 'desc' : 'asc';
            sortDir = {};                 // one active sort at a time
            sortDir[key] = dir;
            var prIcon = document.getElementById('prTicketsSortIcon');
            var poIcon = document.getElementById('totalPoSortIcon');
            if (prIcon) prIcon.textContent = '⇅';
            if (poIcon) poIcon.textContent = '⇅';

            var rows = Array.from(alertTbody.querySelectorAll('tr.main-row'));
            rows.sort(function (a, b) {
                var av = parseFloat((a.querySelectorAll('td')[cellIndex] || {}).textContent) || 0;
                var bv = parseFloat((b.querySelectorAll('td')[cellIndex] || {}).textContent) || 0;
                return dir === 'asc' ? av - bv : bv - av;
            });
            rows.forEach(function (r) { alertTbody.appendChild(r); });
            icon.textContent = dir === 'asc' ? '▲' : '▼';
            currentPage = 1;
            applyFilters();
        });
    }
    makeNumSort('prTicketsSortBtn', 'prTicketsSortIcon', 4, 'pr');
    makeNumSort('totalPoSortBtn',   'totalPoSortIcon',   5, 'po');

    var flash = document.getElementById('alertFlash');
    function showFlash(msg, ok) {
        flash.textContent = msg;
        flash.className = 'mb-4 px-4 py-3 rounded-lg font-semibold text-sm ' +
            (ok ? 'bg-green-50 border border-green-200 text-green-700'
                : 'bg-red-50 border border-red-200 text-red-700');
        flash.classList.remove('hidden');
        setTimeout(function () { flash.classList.add('hidden'); }, 4000);
    }

    var modal        = document.getElementById('reviewModal');
    var skuLabel      = document.getElementById('rm_sku_label');
    var statusSelect  = document.getElementById('rm_review_status');
    var remarksBox    = document.getElementById('rm_remarks');
    var remarksReqTag = document.getElementById('rm_remarks_required');
    var remarksError  = document.getElementById('rm_remarks_error');
    var metaEl        = document.getElementById('rm_meta');
    var saveBtn       = document.getElementById('rm_save');
    var cancelBtn     = document.getElementById('rm_cancel');
    var currentSku    = null;

    function syncRemarksRequired() {
        var required = statusSelect.value === 'IGNORE';
        remarksReqTag.classList.toggle('hidden', !required);
        if (!required) remarksError.classList.add('hidden');
    }
    statusSelect.addEventListener('change', syncRemarksRequired);

    document.querySelectorAll('.js-open-review').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = JSON.parse(this.dataset.detail);
            currentSku = d.sku;
            skuLabel.textContent = 'SKU: ' + d.sku + ' — ' + d.item_name;
            statusSelect.value = d.review_status || '';
            remarksBox.value = d.remarks || '';
            remarksError.classList.add('hidden');
            syncRemarksRequired();
            metaEl.textContent = d.checked_by ? ('Last checked by ' + d.checked_by + (d.checked_at ? ' on ' + d.checked_at : '')) : '';
            modal.classList.remove('hidden');
        });
    });

    function closeModal() { modal.classList.add('hidden'); currentSku = null; }
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });

    // ── Detail modal (PO purchase history) ─────────────────────────────────────
    var detailModal = document.getElementById('detailModal');
    function closeDetail() { detailModal.classList.add('hidden'); }
    document.getElementById('closeDetailBtn').addEventListener('click', closeDetail);
    document.getElementById('closeDetailBtnBottom').addEventListener('click', closeDetail);
    detailModal.addEventListener('click', function (e) { if (e.target === detailModal) closeDetail(); });

    function fmtQty(v) {
        if (v === null || v === '' || v === undefined) return '—';
        var n = parseFloat(v);
        return isNaN(n) ? String(v) : n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
    }

    function fmtMoney(v) {
        if (v === null || v === '' || v === undefined) return '—';
        var n = parseFloat(v);
        return isNaN(n) ? String(v) : 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 });
    }

    document.querySelectorAll('.js-open-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = JSON.parse(this.dataset.detail);

            document.getElementById('dm_sku_code').textContent  = d.sku;
            document.getElementById('dm_item_name').textContent = d.item_name;
            document.getElementById('dm_taxonomy').textContent  = d.taxonomy || '—';
            document.getElementById('dm_purchase_count').textContent = d.purchase_count;
            document.getElementById('dm_po_count').textContent = d.po_count;
            document.getElementById('dm_total_qty').textContent = fmtQty(d.total_qty);
            document.getElementById('dm_total_po_price').textContent = fmtMoney(d.total_po_price);
            document.getElementById('dm_avg_unit_price').textContent = fmtMoney(d.avg_unit_price);
            document.getElementById('dm_vendors').textContent = d.top_vendor || '—';

            var body = document.getElementById('dm_po_rows');
            body.innerHTML = '';
            var rows = d.po_rows || [];
            if (!rows.length) {
                var emptyTr = document.createElement('tr');
                emptyTr.innerHTML = '<td colspan="6" class="px-2 py-4 text-center text-slate-400">No PO purchase records found.</td>';
                body.appendChild(emptyTr);
            } else {
                rows.forEach(function (p) {
                    var tr = document.createElement('tr');
                    tr.className = 'border-t border-slate-100';

                    function cell(text, cls) {
                        var td = document.createElement('td');
                        td.className = cls;
                        var val = (text === null || text === undefined || text === '') ? '—' : text;
                        td.textContent = val;
                        if (cls.indexOf('truncate') !== -1) td.title = val;
                        return td;
                    }

                    tr.appendChild(cell(p.po_number, 'px-3 py-1.5 text-center font-mono font-bold text-slate-800 truncate'));
                    tr.appendChild(cell(p.ticket_no, 'px-3 py-1.5 text-center text-slate-600 truncate'));
                    tr.appendChild(cell(p.date, 'px-3 py-1.5 text-center text-slate-600 truncate'));
                    tr.appendChild(cell(fmtQty(p.qty), 'px-3 py-1.5 text-center text-slate-600 whitespace-nowrap'));
                    tr.appendChild(cell(p.vendor_name, 'px-3 py-1.5 text-center text-slate-600 truncate'));
                    tr.appendChild(cell(fmtMoney(p.po_amount), 'px-3 py-1.5 text-center font-mono text-slate-600 truncate'));
                    body.appendChild(tr);
                });
            }

            detailModal.classList.remove('hidden');
        });
    });

    saveBtn.addEventListener('click', function () {
        if (!currentSku) return;

        if (statusSelect.value === 'IGNORE' && !remarksBox.value.trim()) {
            remarksError.classList.remove('hidden');
            remarksBox.focus();
            return;
        }
        remarksError.classList.add('hidden');

        saveBtn.disabled = true; saveBtn.textContent = 'Saving…';
        fetch('ajax_aon_review_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                sku_code: currentSku,
                review_status: statusSelect.value,
                remarks: remarksBox.value.trim(),
            })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            closeModal();
            saveBtn.disabled = false; saveBtn.textContent = 'Save Review';
            if (res.ok && res.body.status === 'success') {
                showFlash('Review saved.', true);
                setTimeout(function () { location.reload(); }, 700);
            } else {
                showFlash(res.body.message || 'Failed to save review.', false);
            }
        })
        .catch(function () {
            closeModal();
            saveBtn.disabled = false; saveBtn.textContent = 'Save Review';
            showFlash('Network error — please try again.', false);
        });
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
