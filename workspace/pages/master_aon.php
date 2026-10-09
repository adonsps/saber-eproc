<?php
// pages/master_aon.php
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';
require_once '../includes/aon_stock_alert.php';
require_once '../config/database.php';
require_once '../includes/header.php';

if (!aon_can_access($_SESSION['role'] ?? '')) {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Access Denied.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

// Cleanup: soft-delete any SKU that has no data from the Price Per Vendor file
// (price NULL, quantity NULL, vendor NULL) — these are catalog-only bad imports
// that slipped in due to a previous parser bug. Safe to run on every load since
// rows already soft-deleted are excluded.
$pdo->exec(
    "UPDATE aon_skus SET is_deleted = 1
     WHERE is_deleted = 0
       AND price    IS NULL
       AND quantity IS NULL
       AND vendor   IS NULL"
);

// All AON SKUs with a non-blank allocation — status (Active/Inactive) is
// computed per-row from the validity window instead of filtering rows out.
$skus = $pdo->query(
    "SELECT * FROM aon_skus WHERE is_deleted = 0
     AND alokasi IS NOT NULL AND alokasi != ''
     ORDER BY sku_code ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Realized 2026 unit price per SKU, averaged over this year's actual PO'd PRs.
// aon_skus.price only carries the contracted/last-edited 2026 figure, so the
// detail modal shows this alongside it with the months it covers. Fetched once
// for every SKU rather than per row — see the helper's docblock.
$realized_2026 = aon_get_realized_year_prices($pdo, 2026);

// --- AON RUNNING-OUT WARNING ---------------------------------------------
// The SKUs this user owns that are down to their last 30% of contract quantity,
// or approaching the end of their contract period. Pops up on load until
// dismissed, then stays reachable from the amber counter button in the header.
// Scoped to aon_skus.handler, so only the claiming STC is interrupted --
// Category Manager / Manager claim nothing and therefore see nothing here.
// See includes/aon_stock_alert.php.
// SKUs an STC has taken out of the reminders ("not extending this contract",
// with the reason). Loaded for the whole table, not just the alerted rows, so
// every row can show whether a reminder is still coming for it.
$reminder_optouts = aon_reminder_optouts($pdo);
$stock_alerts    = aon_stock_alert_rows($pdo, $_SESSION['name'] ?? '');
// Keyed by id so each table row can pick up its own reason without re-deriving it.
$stock_alert_map = array_column($stock_alerts, null, 'id');
$stock_autoshow  = !empty($stock_alerts) && aon_stock_alert_should_autoshow();
// Arriving from the pop-up's "Show these SKUs" button on the STC dashboard,
// which cannot filter a table it does not have.
$stock_filter_on_load = !empty($stock_alerts) && !empty($_GET['aon_alert']);

// STC users for dropdowns
$stc_users = $pdo->query(
    "SELECT name FROM users WHERE role = 'STC' AND name IS NOT NULL AND name != '' ORDER BY name"
)->fetchAll(PDO::FETCH_COLUMN);

// Category choices come from the Area column of the Master Item Catalog
// (synced into sku_dictionary.area on every AON upload — see aon_parser.php)
$aon_categories = $pdo->query(
    "SELECT DISTINCT area FROM sku_dictionary WHERE area IS NOT NULL AND area != '' ORDER BY area"
)->fetchAll(PDO::FETCH_COLUMN);

function aon_money($v) {
    if ($v === null || $v === '') return '—';
    $s = rtrim(rtrim(number_format((float)$v, 2, ',', '.'), '0'), ',');
    return 'Rp ' . $s;
}
function aon_num($v) {
    if ($v === null || $v === '') return '—';
    if (is_numeric($v)) return rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');
    return htmlspecialchars($v);
}
// Quantity columns are varchars in aon_skus and can carry free text, so the
// export keeps a real number where there is one and the raw string otherwise —
// Excel then gets values it can sum rather than the display's "—"/formatting.
function aon_export_num($v) {
    if ($v === null || $v === '') return '';
    return is_numeric($v) ? (float)$v : (string)$v;
}
function aon_flag_badges($flag) {
    if (!$flag) return '';
    $map = [
        'dup_sku'           => ['bg-red-100 text-red-700 border-red-200',       'Dup SKU',    'Same SKU has multiple item names'],
        'dup_name'          => ['bg-orange-100 text-orange-700 border-orange-200','Dup Name',  'Same item name mapped to multiple SKUs'],
        'unmatched_catalog' => ['bg-amber-100 text-amber-700 border-amber-200',  'No Catalog', 'SKU not found in Item Catalog'],
        'unmatched_price'   => ['bg-amber-100 text-amber-700 border-amber-200',  'No Price',   'SKU not found in Price file'],
    ];
    $html = '';
    foreach (explode(',', $flag) as $f) {
        $f = trim($f);
        if (isset($map[$f])) {
            [$cls, $label, $title] = $map[$f];
            $html .= "<span class='inline-block text-[10px] font-bold px-1.5 py-0.5 rounded border {$cls} ml-1' title='" . htmlspecialchars($title) . "'>{$label}</span>";
        }
    }
    return $html;
}

// Flat, decoration-free copy of every row rendered below, handed to the hidden
// export table at the bottom of this page so "Export" always writes the whole
// list — not the 25/50/100 rows the current page happens to show. Filled inside
// the render loop so it reuses the values the table itself derives (remaining
// qty, the 2025 name/price pairing, Active/Inactive) instead of re-deriving them
// and risking a file that disagrees with the screen.
$export_rows = [];

$today = date('Y-m-d');

// The manual "Shoot Reminder" button is a Category Manager control only —
// aon_can_access() above also admits STC and Manager, and messaging the whole
// STC group is a lead's call, not something every STC gets a button for.
// See pages/ajax_aon_shoot_reminder.php, which enforces the same rule.
$is_category_manager = (($_SESSION['role'] ?? '') === 'Category Manager');

// Uploading the AON CSVs is a Category Manager-only control (the files come
// from K2 on a schedule; Manager/STC have no task to run it and no folder
// access). Editing the contract fields those CSVs own is wider -- Category
// Manager / Manager. An STC still edits everything it is responsible for
// (item name, 2025 names/prices, contract no, PIC) — the commercial terms are
// read-only for them. pages/ajax_aon_sku_edit.php and pages/upload_aon.php
// enforce the same rules; a disabled input/hidden button is not access control.
$aon_can_upload        = aon_can_upload($_SESSION['role'] ?? '');
$aon_can_edit_contract = aon_can_edit_contract_fields($_SESSION['role'] ?? '');
// Reused on every contract-sourced input below.
$aon_ro_attr  = $aon_can_edit_contract ? '' : ' disabled';
$aon_ro_class = $aon_can_edit_contract ? '' : ' bg-slate-100 text-slate-500 cursor-not-allowed';
?>

<?php
// Emitted here, ahead of the table markup and its script, so the browser paints
// the warning immediately instead of after the SKU list has been built and
// paginated. See includes/aon_stock_alert.php.
aon_stock_alert_render($stock_alerts, $stock_autoshow);
?>

<div class="max-w-full">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4 mb-6 border-b pb-4">
        <div class="min-w-0">
            <h2 class="text-2xl font-bold text-gray-800">Master AON</h2>
            <p class="text-xs text-gray-500 mt-1 whitespace-nowrap overflow-hidden text-ellipsis">Always On SKU list. Upload both CSV files to populate. Claim SKUs by assigning an STC.</p>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <?= aon_stock_alert_button_html($stock_alerts) ?>
            <?php if ($is_category_manager): ?>
            <!-- Manual send. The reminder normally goes out by itself after every
                 AON import; this is for the stretch when nothing has been
                 imported and a contract is still ticking down. It obeys the same
                 once-per-SKU-per-day flag as the automatic send, so it cannot be
                 used to message the group twice about the same SKU. -->
            <button type="button" id="aonShootReminderBtn"
                class="h-9 box-border inline-flex items-center gap-1.5 px-3 rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-800 font-bold text-xs shadow-sm hover:bg-emerald-100 transition whitespace-nowrap focus:outline-none"
                title="Send the AON reminder to the STC WhatsApp group now">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                <span class="uppercase tracking-wider">Shoot Reminder</span>
            </button>
            <?php endif; ?>
            <a href="aon_repeat_alert.php"
                class="h-10 box-border inline-flex items-center gap-2 px-4 rounded-lg border border-slate-200 bg-white text-amber-700 font-bold text-sm shadow-sm hover:bg-amber-50 hover:border-amber-300 transition whitespace-nowrap">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                Repeat Purchase Alert
            </a>
            <?php if ($aon_can_upload): ?>
            <a href="upload_aon.php"
                class="h-9 box-border inline-flex items-center gap-1.5 px-3 rounded-lg border border-indigo-600 bg-indigo-600 text-white font-bold text-xs shadow-sm hover:bg-indigo-700 hover:border-indigo-700 transition whitespace-nowrap">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Upload AON
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filter -->
    <div class="flex flex-wrap items-center gap-2 mb-3">
        <button type="button" id="unclaimedFilterBtn" data-active="0"
            class="px-4 py-1.5 rounded-lg text-sm font-bold border transition bg-white text-indigo-700 border-indigo-300 hover:bg-indigo-50">
            Show Unclaimed SKUs (No STC)
        </button>

        <div class="flex items-center gap-2 text-xs text-slate-500">
            <span>Show</span>
            <select id="pageSizeSelect" class="border border-slate-200 rounded-lg px-2 py-1 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-400">
                <option value="25" selected>25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            <span>SKUs</span>
        </div>

        <!-- Export. Deliberately not wired to the table below: it always writes
             every AON SKU, not the current page and not what the column searches
             have narrowed the view to. See the hidden export table at the foot of
             this file. -->
        <div class="relative" id="aonExportContainer">
            <button type="button" id="aonExportBtn"
                class="flex items-center gap-2 bg-indigo-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-indigo-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Export
            </button>
            <div id="aonExportMenu" class="hidden absolute left-0 mt-2 w-48 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                <div class="px-4 py-2 border-b border-gray-100">
                    <div class="text-xs font-semibold text-gray-600">All <?= number_format(count($skus)) ?> SKUs</div>
                    <div class="text-[10px] text-gray-400 leading-tight">Every page — filters ignored</div>
                </div>
                <div class="py-1">
                    <button type="button" id="aonExportExcel" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                    <button type="button" id="aonExportCSV" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                    <button type="button" id="aonExportPDF" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                </div>
            </div>
        </div>

        <div id="bulkClaimBar" class="hidden ml-auto flex items-center gap-3">
            <span id="bulkSelectedCount" class="text-xs font-bold text-indigo-700"></span>
            <button type="button" id="bulkClaimBtn"
                class="px-4 py-1.5 rounded-lg text-sm font-bold bg-indigo-600 text-white hover:bg-indigo-700 transition inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                Assign STC
            </button>
            <button type="button" id="bulkClearBtn" class="text-xs font-bold text-slate-500 hover:text-slate-700 transition">Clear selection</button>
        </div>
    </div>

    <?= aon_stock_alert_banner_html($stock_alerts) ?>

    <!-- Flash -->
    <div id="aonFlash" class="hidden mb-4 px-4 py-3 rounded-lg font-semibold text-sm"></div>

    <!-- Table: fixed layout, no horizontal scroll -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        <table id="aonTable" class="w-full text-sm text-left table-fixed">
            <colgroup>
                <col style="width:148px">   <!-- actions (Details / Edit / Claim) -->
                <col style="width:14%">     <!-- PIC/Owner -->
                <col style="width:10%">     <!-- SKU Code -->
                <col>                       <!-- Item Name (flex) -->
                <col style="width:12%">     <!-- Vendor -->
                <col style="width:100px">   <!-- Status -->
                <col style="width:8%">      <!-- Rem. Qty -->
                <col style="width:13%">     <!-- Price 2026 -->
            </colgroup>
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-2 py-2">
                        <div class="flex items-center gap-1.5">
                            <input type="checkbox" id="selectAllCheckbox" class="rounded border-slate-300 text-indigo-600" title="Select all unclaimed SKUs">
                            <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Actions</div>
                        </div>
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">PIC / Owner</div>
                        <input type="text" data-col="1" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">SKU Code</div>
                        <input type="text" data-col="2" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Item Name</div>
                        <input type="text" data-col="3" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Vendor</div>
                        <input type="text" data-col="4" class="col-search w-full px-2 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400" placeholder="Search…">
                    </th>
                    <th class="px-3 py-2 align-top">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 text-center">Status</div>
                        <select id="statusFilterSelect" class="w-full px-1 py-1 text-xs border border-slate-200 rounded focus:outline-none focus:ring-1 focus:ring-indigo-400">
                            <option value="">All</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </th>
                    <th class="px-3 py-2 align-top">
                        <button type="button" id="remQtySortBtn" title="Sort by remaining qty"
                            class="w-full flex items-center justify-center gap-1 text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1 hover:text-indigo-600 transition">
                            Rem. Qty
                            <span id="remQtySortIcon" class="text-slate-400 text-[11px] leading-none">⇅</span>
                        </button>
                        <input type="text" class="invisible w-full px-2 py-1 text-xs border border-slate-200 rounded" tabindex="-1" aria-hidden="true">
                    </th>
                    <th class="px-3 py-2 align-top whitespace-nowrap">
                        <div class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Price 2026</div>
                        <input type="text" class="invisible w-full px-2 py-1 text-xs border border-slate-200 rounded" tabindex="-1" aria-hidden="true">
                    </th>
                </tr>
            </thead>
            <tbody id="aonTableBody">
                <?php if (empty($skus)): ?>
                <tr>
                    <td colspan="8" class="px-4 py-10 text-center text-slate-400 text-sm">
                        No AON SKUs yet. Use <strong>Upload AON</strong> to import data.
                    </td>
                </tr>
                <?php endif; ?>
                <?php foreach ($skus as $sku):
                    $p2025 = $sku['price_2025'] ?? null;
                    $p2026 = $sku['price']       ?? null;

                    $vfrom  = $sku['valid_from']  ?? null;
                    $vuntil = $sku['valid_until'] ?? null;
                    $expired = $vuntil && $vuntil < $today;
                    $not_started = $vfrom && $vfrom > $today;
                    $sku_active = !$expired && !$not_started;

                    $has_flag   = !empty($sku['flag']);
                    $is_claimed = !empty($sku['handler']);

                    $qty = $sku['quantity']   ?? null;
                    // Remaining qty: when Used Qty is blank nothing has been drawn
                    // down yet, so the full contract qty is still available (rather
                    // than showing a blank). See aon_effective_remain_qty().
                    $rem = aon_effective_remain_qty($qty, $sku['used_qty'] ?? null, $sku['remain_qty'] ?? null);
                    $low = aon_remain_is_low($qty, $rem);

                    // Set when this SKU is on the running-out warning, i.e. it is
                    // owned by this user and either low on qty or close to expiring.
                    // The row is tinted and tagged so the pop-up's "Show these SKUs"
                    // button can isolate exactly these. See includes/aon_stock_alert.php.
                    $alert = $stock_alert_map[(int)$sku['id']] ?? null;

                    // Reminders switched off for this contract. Shown on every row,
                    // alerted or not — an opted-out SKU is precisely the one that
                    // will never appear in the pop-up again, so the row is the only
                    // place that decision is still visible.
                    $optout = $reminder_optouts[strtoupper(trim((string)$sku['sku_code']))] ?? null;

                    // Missing price+qty = no data from price CSV for this row
                    $no_csv_data = ($p2026 === null || $p2026 === '') && ($qty === null || $qty === '');

                    // previous_item / price_2025 are pipe-delimited and aligned index-for-index
                    // when a SKU was purchased under 2+ distinct item names in purchases_2025
                    // (see aon_parser.php) — zip them so each 2025 name carries its own price
                    // to compare against the single 2026 price.
                    $prev_names_raw = !empty($sku['previous_item']) ? array_map('trim', explode('|', $sku['previous_item'])) : [];
                    $price_2025_raw = ($p2025 !== null && $p2025 !== '') ? array_map('trim', explode('|', (string)$p2025)) : [];

                    $price_pairs = [];
                    $pair_count  = max(count($prev_names_raw), count($price_2025_raw));
                    for ($i = 0; $i < $pair_count; $i++) {
                        $nm = $prev_names_raw[$i] ?? '';
                        $pr = (isset($price_2025_raw[$i]) && $price_2025_raw[$i] !== '' && is_numeric($price_2025_raw[$i]))
                                ? (float)$price_2025_raw[$i] : null;
                        if ($nm === '' && $pr === null) continue;
                        $price_pairs[] = [
                            'name'  => $nm !== '' ? $nm : null,
                            'price' => $pr,
                            'diff'  => ($pr !== null && $p2026 !== null && $p2026 !== '') ? ((float)$p2026 - $pr) : null,
                        ];
                    }
                    $prev_names = array_values(array_filter(array_column($price_pairs, 'name')));

                    // Backward-compatible single values (first pair) for the common single-name case
                    $p2025_single = $price_pairs[0]['price'] ?? null;
                    $diff_single  = $price_pairs[0]['diff']  ?? null;

                    $row_id = (int)$sku['id'];

                    // Realized 2026 average for this SKU (null when it has not been
                    // purchased this year yet) plus the month span it was averaged over.
                    $r2026 = $realized_2026[strtoupper(trim((string)$sku['sku_code']))] ?? null;
                    $avg2026_diff = ($r2026 && $p2026 !== null && $p2026 !== '')
                        ? ($r2026['avg_price'] - (float)$p2026) : null;

                    // JSON for detail modal (escaped for data attribute)
                    $detail_json = htmlspecialchars(json_encode([
                        'id'            => $row_id,
                        'sku_code'      => $sku['sku_code'],
                        'item_name'     => $sku['item_name'],
                        'prev_names'    => array_values($prev_names),
                        'vendor'        => $sku['vendor'] ?? '',
                        'contract_no'  => $sku['contract_no'] ?? '',
                        'category'      => $sku['category'] ?? '',
                        'handler'       => $sku['handler'] ?? '',
                        'p2025'         => $p2025_single ?? '',
                        'p2026'         => $p2026 ?? '',
                        'diff'          => $diff_single,
                        'price_pairs'   => $price_pairs,
                        'avg2026'       => $r2026 ? $r2026['avg_price'] : '',
                        'avg2026_range' => $r2026 ? aon_format_month_range($r2026['first_date'], $r2026['last_date']) : '',
                        'avg2026_pos'   => $r2026 ? $r2026['po_count'] : 0,
                        'avg2026_qty'   => $r2026 ? $r2026['total_qty'] : '',
                        'avg2026_diff'  => $avg2026_diff,
                        'quantity'      => $qty ?? '',
                        'used_qty'      => $sku['used_qty'] ?? '',
                        'remain_qty'    => $rem ?? '',
                        'remain_low'    => $low,
                        'valid_from'    => $vfrom ?? '',
                        'valid_until'   => $vuntil ?? '',
                        'flag'          => $sku['flag'] ?? '',
                        'flag_detail'   => $sku['flag_detail'] ?? '',
                        'active'        => $sku_active,
                        'updated_at'    => !empty($sku['updated_at']) ? aon_format_datetime($sku['updated_at']) : '',
                        'updated_by'    => $sku['updated_by'] ?? '',
                        // Reminder opt-out, so the detail modal can show the decision
                        // and offer to reverse it — the pop-up's Ignore button is
                        // one-way, and a mistake there needs a way back.
                        'reminder_off'     => (bool)$optout,
                        // Which of the two answers was given, so Details says
                        // "On Progress" or "Not Renewal" rather than only that
                        // the reminders are off. Blank for rows opted out before
                        // the pop-up offered a choice — see aon_reminder.php.
                        'reminder_status'  => $optout ? aon_reminder_optout_label($optout['status'] ?? null) : '',
                        'reminder_remarks' => $optout['remarks']    ?? '',
                        'reminder_by'      => $optout['ignored_by'] ?? '',
                        'reminder_at'      => !empty($optout['ignored_at']) ? date('d M Y', strtotime($optout['ignored_at'])) : '',
                    ]), ENT_QUOTES);
                    // One export row per table row (see $export_rows above), in the
                    // column order the export file is expected to have. Numbers stay
                    // numeric and dates stay ISO so the spreadsheet holds real values,
                    // not the "Rp 1.234" / "—" the cells show.
                    $export_rows[] = [
                        $sku['sku_code'],
                        $sku['item_name'],
                        $sku['category'] ?? '',
                        $vfrom  ?? '',
                        $vuntil ?? '',
                        $sku_active ? 'Active' : 'Inactive',
                        aon_export_num($qty),
                        aon_export_num($sku['used_qty'] ?? null),
                        aon_export_num($rem),
                        $sku['vendor']      ?? '',
                        $sku['contract_no'] ?? '',
                        $is_claimed ? $sku['handler'] : 'Unclaimed',
                        ($p2026 === null || $p2026 === '') ? '' : round((float)$p2026, 2),
                        // The 2025 figure is an average carried at full precision
                        // (150347.88732394); rounded to the 2 decimals money is
                        // actually shown with on screen.
                        $p2025_single !== null ? round($p2025_single, 2) : '',
                    ];

                ?>
                <tr class="main-row border-b border-slate-100 hover:bg-indigo-50/30 transition <?= $alert ? 'bg-amber-50/70' : ($has_flag ? 'bg-amber-50/40' : '') ?>"
                    data-claimed="<?= $is_claimed ? '1' : '0' ?>"
                    data-status="<?= $sku_active ? 'active' : 'inactive' ?>"
                    data-remain="<?= ($rem !== null && $rem !== '' && is_numeric($rem)) ? (float)$rem : '' ?>"
                    <?= $alert ? 'data-aon-alert="1"' : '' ?>>

                    <!-- 0: Actions (Details / Edit / Claim — bold colors so STC notices them) -->
                    <td class="px-2 py-2.5">
                        <div class="flex items-center gap-1">
                            <!-- Bulk-select slot: width is reserved even on claimed rows
                                 (which have no checkbox) so the Details/Edit buttons stay
                                 aligned in the same column across every row. -->
                            <span class="w-4 flex-shrink-0 flex justify-center">
                                <?php if (!$is_claimed): ?>
                                <input type="checkbox" class="js-row-checkbox rounded border-slate-300 text-indigo-600" data-id="<?= $row_id ?>">
                                <?php endif; ?>
                            </span>
                            <!-- Details -->
                            <button type="button" class="js-open-detail p-1.5 rounded-md bg-indigo-100 text-indigo-700 border border-indigo-200 hover:bg-indigo-600 hover:text-white hover:border-indigo-600 transition" title="View details"
                                data-detail='<?= $detail_json ?>'>
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </button>
                            <!-- Edit -->
                            <button type="button" class="js-open-edit p-1.5 rounded-md bg-amber-100 text-amber-700 border border-amber-200 hover:bg-amber-500 hover:text-white hover:border-amber-500 transition" title="Edit"
                                data-id="<?= $row_id ?>"
                                data-sku="<?= htmlspecialchars($sku['sku_code'], ENT_QUOTES) ?>"
                                data-item-name="<?= htmlspecialchars($sku['item_name'], ENT_QUOTES) ?>"
                                data-vendor="<?= htmlspecialchars($sku['vendor'] ?? '', ENT_QUOTES) ?>"

                                data-contract-no="<?= htmlspecialchars($sku['contract_no'] ?? '', ENT_QUOTES) ?>"
                                data-category="<?= htmlspecialchars($sku['category'] ?? '', ENT_QUOTES) ?>"
                                data-quantity="<?= htmlspecialchars($sku['quantity'] ?? '', ENT_QUOTES) ?>"
                                data-used-qty="<?= htmlspecialchars($sku['used_qty'] ?? '', ENT_QUOTES) ?>"
                                data-remain-qty="<?= htmlspecialchars($sku['remain_qty'] ?? '', ENT_QUOTES) ?>"
                                data-price2025="<?= $sku['price_2025'] ?? '' ?>"
                                data-price2026="<?= $sku['price'] ?? '' ?>"
                                data-valid-from="<?= $sku['valid_from'] ?? '' ?>"
                                data-valid-until="<?= $sku['valid_until'] ?? '' ?>"
                                data-handler="<?= htmlspecialchars($sku['handler'] ?? '', ENT_QUOTES) ?>"
                                data-previous-item="<?= htmlspecialchars($sku['previous_item'] ?? '', ENT_QUOTES) ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </button>
                            <!-- Claim (only if unclaimed) -->
                            <?php if (!$is_claimed): ?>
                            <button type="button" class="js-open-claim p-1.5 rounded-md bg-emerald-100 text-emerald-700 border border-emerald-200 hover:bg-emerald-600 hover:text-white hover:border-emerald-600 transition" title="Assign STC"
                                data-id="<?= $row_id ?>"
                                data-code="<?= htmlspecialchars($sku['sku_code'], ENT_QUOTES) ?>">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- 1: PIC/Owner -->
                    <td class="px-3 py-2.5">
                        <?php if ($is_claimed): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 max-w-full overflow-hidden">
                                <svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span class="truncate"><?= htmlspecialchars($sku['handler']) ?></span>
                            </span>
                        <?php else: ?>
                            <span class="text-[11px] text-slate-400 italic">Unclaimed</span>
                        <?php endif; ?>
                    </td>

                    <!-- 2: SKU Code + badges -->
                    <td class="px-3 py-2.5 font-mono text-xs">
                        <div class="font-bold text-slate-800 truncate"><?= htmlspecialchars($sku['sku_code']) ?></div>
                        <?php if ($has_flag): ?>
                        <div class="flex flex-wrap gap-0.5 mt-0.5"><?= aon_flag_badges($sku['flag']) ?></div>
                        <?php endif; ?>
                        <?php if ($no_csv_data): ?>
                        <span class="inline-block mt-0.5 text-[10px] font-bold px-1.5 py-0.5 rounded border bg-slate-100 text-slate-500 border-slate-300" title="Price &amp; quantity data not found in uploaded CSV">No CSV data</span>
                        <?php endif; ?>
                    </td>

                    <!-- 3: Item Name -->
                    <td class="px-3 py-2.5 text-slate-700">
                        <div class="truncate text-sm" title="<?= htmlspecialchars($sku['item_name']) ?>"><?= htmlspecialchars($sku['item_name']) ?></div>
                    </td>

                    <!-- 4: Vendor -->
                    <td class="px-3 py-2.5">
                        <div class="truncate text-xs text-slate-600" title="<?= htmlspecialchars($sku['vendor'] ?? '') ?>"><?= htmlspecialchars($sku['vendor'] ?? '—') ?></div>
                    </td>

                    <!-- 5: Status (Active/Inactive) -->
                    <td class="px-3 py-2.5 text-center">
                        <?php if ($sku_active): ?>
                        <span class="px-2 inline-flex text-[11px] leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>
                        <?php else: ?>
                        <span class="px-2 inline-flex text-[11px] leading-5 font-semibold rounded-full bg-red-100 text-red-800">Inactive</span>
                        <?php endif; ?>
                    </td>

                    <!-- 6: Remain Qty -->
                    <td class="px-3 py-2.5 text-center">
                        <span class="text-xs <?= $low ? 'text-red-600 font-bold' : 'text-slate-600' ?>"
                            <?= $alert ? 'title="' . htmlspecialchars(aon_stock_alert_reason($alert), ENT_QUOTES) . '"' : '' ?>><?= aon_num($rem) ?></span>
                    </td>

                    <!-- 7: Price 2026 -->
                    <td class="px-3 py-2.5 text-left whitespace-nowrap">
                        <span class="text-xs font-semibold text-slate-800"><?= aon_money($p2026) ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 px-4 py-3 border-t border-slate-200 text-xs text-slate-500">
            <div id="aonPageInfo">Showing 0 of 0 entries</div>
            <div id="aonPageButtons" class="flex items-center gap-1"></div>
        </div>
    </div>
</div>

<!-- ═══════════════════════ CLAIM MODAL ═══════════════════════ -->
<div id="claimModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm">
        <div class="px-6 py-5 border-b border-slate-100">
            <h3 class="text-base font-bold text-slate-800">Assign SKU to STC</h3>
            <p id="claimSkuLabel" class="text-xs text-slate-500 mt-1 font-mono"></p>
        </div>
        <div class="px-6 py-5">
            <label class="block text-xs font-bold text-slate-700 mb-1">Select STC <span class="text-red-500">*</span></label>
            <select id="claimHandlerSelect" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <option value="">— Choose STC —</option>
                <?php foreach ($stc_users as $stc): ?>
                <option value="<?= htmlspecialchars($stc) ?>"><?= htmlspecialchars($stc) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($stc_users)): ?>
            <p class="text-xs text-amber-600 mt-1">No STC users found. Add users with the STC role first.</p>
            <?php endif; ?>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3">
            <button id="cancelClaimBtn" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="confirmClaimBtn" type="button" class="px-4 py-2 text-sm font-bold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">Confirm Assign</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════ DETAIL MODAL ═══════════════════════ -->
<div id="detailModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[92vh] flex flex-col">

        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-100 flex items-start justify-between flex-shrink-0">
            <div>
                <div id="dm_sku_code" class="font-mono font-bold text-indigo-700 text-sm tracking-wider"></div>
                <div id="dm_item_name" class="text-base font-bold text-slate-800 mt-0.5 leading-snug"></div>
                <div id="dm_prev_names" class="text-xs text-slate-400 mt-0.5 space-y-0.5"></div>
            </div>
            <button type="button" id="closeDetailBtn" class="text-slate-400 hover:text-slate-600 transition ml-4 mt-0.5 flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Scrollable body -->
        <div class="px-6 py-5 overflow-y-auto flex-1 space-y-5">

            <!-- Vendor / Category / PIC row -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Vendor</div>
                    <div id="dm_vendor" class="text-sm text-slate-700 font-medium"></div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Category</div>
                    <div id="dm_category" class="text-sm text-slate-700 uppercase"></div>
                </div>
                <div>
                    <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">PIC / Handler</div>
                    <div id="dm_handler" class="text-sm text-slate-700"></div>
                </div>
            </div>

            <hr class="border-slate-100">

            <!-- Pricing — 2025 | 2026 | Diff side by side (single name), or a
                 per-name comparison table (2+ distinct 2025 item names) -->
            <div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Pricing</div>
                <div id="dm_price_single" class="grid grid-cols-3 gap-3">
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1" title="Averaged from 2025 purchases, net of PPN — the FF**/FY**/DF** POrgs book their PO amounts gross, so the tax is removed before averaging.">Avg Price 2025 <span class="normal-case font-normal">(excl. PPN)</span></div>
                        <div id="dm_p2025" class="text-sm font-bold text-slate-600"></div>
                    </div>
                    <div class="bg-indigo-50 rounded-lg p-3 text-center border border-indigo-100">
                        <div class="text-[10px] text-indigo-500 font-bold uppercase mb-1">Price 2026</div>
                        <div id="dm_p2026" class="text-sm font-bold text-indigo-800"></div>
                    </div>
                    <div id="dm_diff_card" class="rounded-lg p-3 text-center border">
                        <div class="text-[10px] font-bold uppercase mb-1 dm-diff-label">Difference</div>
                        <div id="dm_diff" class="text-sm font-bold"></div>
                    </div>
                </div>
                <div id="dm_price_multi" class="hidden">
                    <p class="text-[11px] text-slate-400 mb-2">Multiple 2025 item names found for this SKU in purchase history — each is compared against the single 2026 price.</p>
                    <table class="w-full text-xs border border-slate-100 rounded-lg overflow-hidden table-fixed">
                        <colgroup>
                            <col style="width:37%">
                            <col style="width:19%">
                            <col style="width:19%">
                            <col style="width:25%">
                        </colgroup>
                        <thead class="bg-slate-50 text-slate-500">
                            <tr>
                                <th class="px-2 py-1.5 text-left font-bold">2025 Item Name</th>
                                <th class="px-2 py-1.5 text-center font-bold" title="Net of PPN — see the Avg Price 2025 card.">Avg Price 2025<br><span class="normal-case font-normal text-slate-400">(excl. PPN)</span></th>
                                <th class="px-2 py-1.5 text-center font-bold">Price 2026</th>
                                <th class="px-2 py-1.5 text-center font-bold whitespace-nowrap">Diff</th>
                            </tr>
                        </thead>
                        <tbody id="dm_price_multi_body"></tbody>
                    </table>
                </div>
                <!-- Realized 2026 average. Price 2026 above is the contracted figure
                     as last uploaded/edited; this is what the SKU was actually bought
                     for this year, with the months the average was taken over. Sits
                     outside the single/multi split because it is one value per SKU,
                     independent of how many 2025 names it maps to. -->
                <div id="dm_avg2026_card" class="mt-3 bg-white rounded-lg p-3 border border-indigo-100 flex items-center justify-between gap-3">
                    <div>
                        <div class="text-[10px] text-indigo-500 font-bold uppercase mb-1" title="Averaged from this year's PO'd PRs, net of PPN — the FF**/FY**/DF** POrgs book their PO amounts gross, so the tax is removed before averaging and cannot read as a price overrun.">Avg Price 2026 (Realized, excl. PPN)</div>
                        <div id="dm_avg2026_meta" class="text-[11px] text-slate-400"></div>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <div id="dm_avg2026" class="text-sm font-bold text-indigo-800"></div>
                        <div id="dm_avg2026_diff" class="text-[11px] font-semibold"></div>
                    </div>
                </div>
            </div>

            <!-- Contract No. Sits under the pricing block on a full-width row of
                 its own rather than in the header: the number runs to ~34
                 characters ("PPH/985/PROJECT PROP/KLG/V/2026") and wrapped over
                 three lines in the old four-column header. Set in the same type as
                 every other field -- it is a reference a reader matches against the
                 WhatsApp reminder by eye, not code. -->
            <div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1">Contract No</div>
                <div id="dm_contract_no" class="text-sm text-slate-700 font-medium"></div>
            </div>

            <hr class="border-slate-100">

            <!-- Quantities -->
            <div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Quantities <span class="normal-case font-normal text-slate-400">(from Price CSV)</span></div>
                <div class="grid grid-cols-3 gap-3">
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1">Contract Qty</div>
                        <div id="dm_qty" class="text-sm font-bold text-slate-700"></div>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1">Used Qty</div>
                        <div id="dm_used" class="text-sm font-bold text-slate-700"></div>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1">Remaining Qty</div>
                        <div id="dm_remain" class="text-sm font-bold text-slate-700"></div>
                    </div>
                </div>
            </div>

            <hr class="border-slate-100">

            <!-- Validity — two boxes (start / end date) -->
            <div>
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-3">Contract Validity</div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1 whitespace-nowrap">Validity From</div>
                        <div id="dm_valid_from" class="text-sm font-bold text-slate-700"></div>
                    </div>
                    <div class="bg-slate-50 rounded-lg p-3 text-center border border-slate-100">
                        <div class="text-[10px] text-slate-400 font-bold uppercase mb-1 whitespace-nowrap">Validity To</div>
                        <div id="dm_valid_until" class="text-sm font-bold text-slate-700"></div>
                    </div>
                </div>
            </div>

            <!-- Reminder state. The pop-up's On Progress / Not Renewal buttons
                 only ever switch reminders OFF; this is the way back on, and the
                 place the answer and its reason stay readable. -->
            <div id="dm_reminder_section" class="hidden">
                <hr class="border-slate-100 mb-4">
                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">AON Reminder</div>
                <div class="flex items-start justify-between gap-3 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2.5">
                    <div class="min-w-0">
                        <div id="dm_reminder_state" class="text-xs font-bold text-slate-700">Reminders are off for this SKU</div>
                        <div id="dm_reminder_remarks" class="text-xs text-slate-600 mt-0.5 break-words"></div>
                        <div id="dm_reminder_who" class="text-[11px] text-slate-400 mt-0.5"></div>
                    </div>
                    <button type="button" id="dm_reminder_restore"
                        class="flex-shrink-0 px-3 py-1.5 rounded-lg text-xs font-bold border border-indigo-300 bg-white text-indigo-700 hover:bg-indigo-50 transition">
                        Turn back on
                    </button>
                </div>
            </div>

            <!-- Flags (shown only if any) -->
            <div id="dm_flag_section" class="hidden">
                <hr class="border-slate-100 mb-4">
                <div class="text-[10px] font-bold text-amber-500 uppercase tracking-wider mb-2">Flags</div>
                <div id="dm_flags" class="flex flex-wrap gap-1 mb-2"></div>
                <div id="dm_flag_detail" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 whitespace-normal leading-relaxed"></div>
            </div>

            <!-- Last updated -->
            <div class="text-[10px] text-slate-400 mt-2" id="dm_updated"></div>
        </div>

        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex-shrink-0 border-t border-slate-100 flex justify-end">
            <button type="button" id="closeDetailBtnBottom" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Close</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════ EDIT MODAL ═══════════════════════ -->
<div id="editModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
            <div>
                <h3 class="text-base font-bold text-slate-800">Edit AON SKU</h3>
                <p id="editSkuLabel" class="text-xs text-slate-500 mt-0.5 font-mono"></p>
            </div>
            <button type="button" id="closeEditBtn" class="text-slate-400 hover:text-slate-600 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="px-6 py-5 overflow-y-auto flex-1">
            <?php if (!$aon_can_edit_contract): ?>
            <div class="mb-4 px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-600">
                Greyed-out fields come from the AON upload and can only be changed by a Category Manager or Manager.
            </div>
            <?php endif; ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Item Name</label>
                    <input id="edit_item_name" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">2025 SKU Name(s) &amp; Avg Price 2025</label>
                    <div class="flex items-center gap-2 mb-1 px-0.5">
                        <span class="flex-1 text-[10px] font-bold text-slate-400 uppercase tracking-wider">2025 SKU Name</span>
                        <span class="w-[150px] text-[10px] font-bold text-slate-400 uppercase tracking-wider">Avg Price 2025 (Rp)</span>
                        <span class="w-4 flex-shrink-0"></span>
                    </div>
                    <div id="prevItemsContainer" class="space-y-2"></div>
                    <button type="button" id="addPrevItemBtn" class="mt-2 text-xs font-bold text-indigo-600 hover:text-indigo-800 flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Add another 2025 name
                    </button>
                    <p class="text-[10px] text-slate-400 mt-1">Names come from <code>purchases_2025</code> for this SKU — you can't add names outside the table. Picking a name auto-fills its average price, which you can still edit manually.</p>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Vendor</label>
                    <input id="edit_vendor" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>

                <!-- Not filled by any import: the masterplan and K2 feeds carry no
                     contract number, so this is the only place it is ever set.
                     Blank is fine — the expiry reminder just shows a dash. -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">
                        Contract No
                        <span class="font-normal text-slate-400">— quoted in the STC WhatsApp reminder</span>
                    </label>
                    <input id="edit_contract_no" type="text" maxlength="150" placeholder="e.g. PPH/1306/GP/KLG/VII/2026"
                           class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Category</label>
                    <select id="edit_category" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                        <option value="">— None —</option>
                        <?php foreach ($aon_categories as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">PIC / Handler</label>
                    <select id="edit_handler" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="">— None —</option>
                        <?php foreach ($stc_users as $stc): ?>
                        <option value="<?= htmlspecialchars($stc) ?>"><?= htmlspecialchars($stc) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Valid From</label>
                    <input id="edit_valid_from" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Valid Until</label>
                    <input id="edit_valid_until" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Price 2026 (Rp)</label>
                    <input id="edit_price_2026" type="number" step="1" min="0" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Contract Qty</label>
                    <input id="edit_quantity" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Used Qty</label>
                    <input id="edit_used_qty" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Remain Qty</label>
                    <input id="edit_remain_qty" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400<?= $aon_ro_class ?>"<?= $aon_ro_attr ?>>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3 flex-shrink-0 border-t border-slate-100">
            <button id="cancelEditBtn" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="confirmEditBtn" type="button" class="px-5 py-2 text-sm font-bold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">Save Changes</button>
        </div>
    </div>
</div>

<?php if ($is_category_manager): ?>
<!-- ═══════════════════════ SHOOT REMINDER CONFIRM ═══════════════════════ -->
<!-- Replaces the browser's native confirm(): that dialog is chrome-styled,
     announces "localhost:8080 says", and cannot say WHERE the message is going.
     Same shell as the Detail and Edit modals above so it reads as part of the
     page. Still only a confirmation — the count is deliberately not fetched
     first, since a preview round-trip would make the button slower for the one
     job it has. -->
<div id="shootModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            </div>
            <div class="min-w-0">
                <h3 class="text-base font-bold text-slate-800">Send AON reminder now?</h3>
                <p class="text-xs text-slate-500 mt-0.5">This posts a message to the STC WhatsApp group.</p>
            </div>
        </div>
        <div class="px-6 py-4 space-y-2.5">
            <p class="text-sm text-slate-600">
                Every AON SKU that is down to its last 30% of contract quantity, or approaching
                the end of its contract period, is collected into a single digest grouped by STC —
                quantity and expiry in their own sections.
            </p>
            <p class="text-xs text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                SKUs already reminded today are skipped, so this cannot send the group the same
                thing twice. If everything has already gone out, nothing is sent.
            </p>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3 border-t border-slate-100">
            <button id="shootCancelBtn" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="shootConfirmBtn" type="button" class="px-5 py-2 text-sm font-bold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition">Send Reminder</button>
        </div>
    </div>
</div>
<?php endif; ?>

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

    var unclaimedOnly = false;
    var unclaimedBtn   = document.getElementById('unclaimedFilterBtn');

    unclaimedBtn.addEventListener('click', function () {
        unclaimedOnly = !unclaimedOnly;
        this.dataset.active = unclaimedOnly ? '1' : '0';
        // Active: solid blue button; Inactive: outline style
        if (unclaimedOnly) {
            this.className = 'px-4 py-1.5 rounded-lg text-sm font-bold border transition bg-indigo-600 text-white border-indigo-600';
        } else {
            this.className = 'px-4 py-1.5 rounded-lg text-sm font-bold border transition bg-white text-indigo-700 border-indigo-300 hover:bg-indigo-50';
        }
        currentPage = 1;
        applyFilters();
    });

    // ── Status filter (Active / Inactive) ───────────────────────────────────
    var statusFilterSelect = document.getElementById('statusFilterSelect');
    statusFilterSelect.addEventListener('change', function () {
        currentPage = 1;
        applyFilters();
    });

    // ── Rem. Qty sort (toggle ascending / descending) ────────────────────────
    // Reorders the rows in the DOM so pagination slices the sorted list. Rows
    // with no numeric remaining qty always sink to the bottom.
    var aonTableBody   = document.getElementById('aonTableBody');
    var remQtySortBtn  = document.getElementById('remQtySortBtn');
    var remQtySortIcon = document.getElementById('remQtySortIcon');
    var remSortDir = 0; // 0 = unsorted, 1 = asc, -1 = desc

    remQtySortBtn.addEventListener('click', function () {
        remSortDir = remSortDir === 1 ? -1 : 1;
        remQtySortIcon.textContent = remSortDir === 1 ? '▲' : '▼';
        remQtySortIcon.className = 'text-indigo-600 text-[11px] leading-none';

        var rows = Array.from(aonTableBody.querySelectorAll('tr.main-row'));
        rows.sort(function (a, b) {
            var av = a.dataset.remain === '' ? null : parseFloat(a.dataset.remain);
            var bv = b.dataset.remain === '' ? null : parseFloat(b.dataset.remain);
            if (av === null && bv === null) return 0;
            if (av === null) return 1;   // blanks to bottom regardless of direction
            if (bv === null) return -1;
            return remSortDir === 1 ? av - bv : bv - av;
        });
        rows.forEach(function (r) { aonTableBody.appendChild(r); });

        currentPage = 1;
        applyFilters();
    });

    // ── Pagination (25 / 50 / 100 per page) ─────────────────────────────────
    var pageSizeSelect = document.getElementById('pageSizeSelect');
    var pageInfoEl      = document.getElementById('aonPageInfo');
    var pageButtonsEl   = document.getElementById('aonPageButtons');
    var currentPage = 1;
    var pageSize = parseInt(pageSizeSelect.value, 10);

    pageSizeSelect.addEventListener('change', function () {
        pageSize = parseInt(this.value, 10);
        currentPage = 1;
        applyFilters();
    });

    // ── Running-out warning filter ───────────────────────────────────────────
    // Driven by the pop-up's "Show these SKUs" button, not by a control of its
    // own: the rows it isolates are tagged data-aon-alert server-side, so the
    // narrowing survives sorting, paging and every column search.
    var alertOnly = false;

    function getMatchingRows() {
        return Array.from(document.querySelectorAll('#aonTableBody tr.main-row')).filter(function (row) {
            if (alertOnly && row.dataset.aonAlert !== '1') return false;
            if (unclaimedOnly && row.dataset.claimed !== '0') return false;
            if (statusFilterSelect.value && row.dataset.status !== statusFilterSelect.value) return false;
            var cells = row.querySelectorAll('td');
            for (var col in filters) {
                if (!filters[col]) continue;
                var cell = cells[parseInt(col)];
                if (cell && cell.textContent.toLowerCase().indexOf(filters[col]) === -1) return false;
            }
            return true;
        });
    }

    function matchedRowCheckboxes() {
        return getMatchingRows().map(function (row) { return row.querySelector('.js-row-checkbox'); }).filter(Boolean);
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

        document.querySelectorAll('#aonTableBody tr.main-row').forEach(function (row) {
            row.style.display = pageRowSet.has(row) ? '' : 'none';
        });

        var total = matched.length;
        pageInfoEl.textContent = total === 0
            ? 'Showing 0 of 0 entries'
            : 'Showing ' + (start + 1) + ' to ' + Math.min(start + pageSize, total) + ' of ' + total + ' entries';
        renderPageButtons(totalPages);

        updateBulkBar();
    }

    // ── Flash helper ─────────────────────────────────────────────────────────
    var flash = document.getElementById('aonFlash');
    function showFlash(msg, ok) {
        flash.textContent = msg;
        flash.className = 'mb-4 px-4 py-3 rounded-lg font-semibold text-sm ' +
            (ok ? 'bg-green-50 border border-green-200 text-green-700'
                : 'bg-red-50 border border-red-200 text-red-700');
        flash.classList.remove('hidden');
        setTimeout(function () { flash.classList.add('hidden'); }, 4000);
    }

    // ── Claim modal (single SKU, from the per-row button) ─────────────────────
    var claimModal    = document.getElementById('claimModal');
    var claimSkuLabel = document.getElementById('claimSkuLabel');
    var claimSelect   = document.getElementById('claimHandlerSelect');
    var confirmClaim  = document.getElementById('confirmClaimBtn');
    var cancelClaim   = document.getElementById('cancelClaimBtn');
    var pendingClaimIds = [];

    document.querySelectorAll('.js-open-claim').forEach(function (btn) {
        btn.addEventListener('click', function () {
            pendingClaimIds = [this.dataset.id];
            claimSkuLabel.textContent = 'SKU: ' + this.dataset.code;
            claimSelect.value = '';
            claimModal.classList.remove('hidden');
        });
    });
    cancelClaim.addEventListener('click', function () { claimModal.classList.add('hidden'); });
    claimModal.addEventListener('click', function (e) { if (e.target === claimModal) claimModal.classList.add('hidden'); });

    confirmClaim.addEventListener('click', function () {
        var handler = claimSelect.value;
        if (!handler) { alert('Please choose an STC before confirming.'); return; }
        confirmClaim.disabled = true; confirmClaim.textContent = 'Saving…';
        fetch('ajax_aon_sku_claim.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: pendingClaimIds, handler: handler })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            claimModal.classList.add('hidden');
            confirmClaim.disabled = false; confirmClaim.textContent = 'Confirm Assign';
            if (res.ok && res.body.status === 'success') {
                var n = res.body.assigned || 1;
                showFlash(n + ' SKU(s) assigned to ' + res.body.owner + '.', true);
                clearBulkSelection();
                setTimeout(function () { location.reload(); }, 800);
            } else {
                showFlash(res.body.message || 'Failed to assign.', false);
            }
        })
        .catch(function () {
            claimModal.classList.add('hidden');
            confirmClaim.disabled = false; confirmClaim.textContent = 'Confirm Assign';
            showFlash('Network error — please try again.', false);
        });
    });

    // ── Bulk selection (claim multiple unclaimed SKUs at once) ────────────────
    var selectAllCheckbox  = document.getElementById('selectAllCheckbox');
    var bulkClaimBar       = document.getElementById('bulkClaimBar');
    var bulkSelectedCount  = document.getElementById('bulkSelectedCount');
    var bulkClaimBtn       = document.getElementById('bulkClaimBtn');
    var bulkClearBtn       = document.getElementById('bulkClearBtn');
    var selectedIds        = new Set();

    function rowCheckboxes() {
        return Array.from(document.querySelectorAll('.js-row-checkbox'));
    }

    function updateBulkBar() {
        var n = selectedIds.size;
        bulkClaimBar.classList.toggle('hidden', n === 0);
        bulkSelectedCount.textContent = n + ' SKU' + (n === 1 ? '' : 's') + ' selected';
        var matched = matchedRowCheckboxes();
        selectAllCheckbox.checked = matched.length > 0 && matched.every(function (cb) { return selectedIds.has(cb.dataset.id); });
    }

    function clearBulkSelection() {
        selectedIds.clear();
        rowCheckboxes().forEach(function (cb) { cb.checked = false; });
        updateBulkBar();
    }

    document.querySelectorAll('.js-row-checkbox').forEach(function (cb) {
        cb.addEventListener('change', function () {
            if (this.checked) selectedIds.add(this.dataset.id);
            else selectedIds.delete(this.dataset.id);
            updateBulkBar();
        });
    });

    selectAllCheckbox.addEventListener('change', function () {
        var checked = this.checked;
        matchedRowCheckboxes().forEach(function (cb) {
            cb.checked = checked;
            if (checked) selectedIds.add(cb.dataset.id);
            else selectedIds.delete(cb.dataset.id);
        });
        updateBulkBar();
    });

    bulkClearBtn.addEventListener('click', clearBulkSelection);

    bulkClaimBtn.addEventListener('click', function () {
        if (selectedIds.size === 0) return;
        pendingClaimIds = Array.from(selectedIds);
        claimSkuLabel.textContent = pendingClaimIds.length + ' SKU(s) selected';
        claimSelect.value = '';
        claimModal.classList.remove('hidden');
    });

    // ── Detail modal ──────────────────────────────────────────────────────────
    var detailModal = document.getElementById('detailModal');
    function closeDetail() { detailModal.classList.add('hidden'); }
    document.getElementById('closeDetailBtn').addEventListener('click', closeDetail);
    document.getElementById('closeDetailBtnBottom').addEventListener('click', closeDetail);
    detailModal.addEventListener('click', function (e) { if (e.target === detailModal) closeDetail(); });

    function fmtDate(iso) {
        if (!iso) return null;
        var parts = iso.split('-');
        if (parts.length !== 3) return iso;
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return parts[2] + ' ' + (months[parseInt(parts[1], 10) - 1] || parts[1]) + ' ' + parts[0];
    }

    function fmt(v, isMoney) {
        if (v === null || v === '' || v === undefined) return '<span class="text-slate-400">—</span>';
        if (isMoney) {
            var n = parseFloat(v);
            return isNaN(n) ? v : 'Rp ' + n.toLocaleString('id-ID');
        }
        return String(v);
    }

    // SKU currently shown in the detail modal, so the reminder controls in its
    // footer know what they are acting on.
    var detailSku = null;

    document.querySelectorAll('.js-open-detail').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = JSON.parse(this.dataset.detail);

            document.getElementById('dm_sku_code').textContent  = d.sku_code;
            document.getElementById('dm_item_name').textContent = d.item_name;

            // 2025 names
            var prevEl = document.getElementById('dm_prev_names');
            prevEl.innerHTML = '';
            if (d.prev_names && d.prev_names.length) {
                d.prev_names.forEach(function (n) {
                    var div = document.createElement('div');
                    div.textContent = '2025: ' + n;
                    prevEl.appendChild(div);
                });
            }

            document.getElementById('dm_contract_no').innerHTML = fmt(d.contract_no) || '<span class="text-slate-400">—</span>';


            document.getElementById('dm_vendor').innerHTML   = fmt(d.vendor) || '<span class="text-slate-400">—</span>';
            document.getElementById('dm_category').innerHTML = fmt(d.category) || '<span class="text-slate-400">—</span>';
            document.getElementById('dm_handler').innerHTML  = d.handler
                ? '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">' + d.handler + '</span>'
                : '<span class="text-slate-400 italic text-xs">Unclaimed</span>';

            // Pricing
            document.getElementById('dm_p2025').innerHTML = fmt(d.p2025, true);
            document.getElementById('dm_p2026').innerHTML = fmt(d.p2026, true);

            var diffCard = document.getElementById('dm_diff_card');
            var diffEl   = document.getElementById('dm_diff');
            if (d.diff === null || d.diff === undefined || d.diff === '') {
                diffEl.innerHTML = '<span class="text-slate-400">—</span>';
                diffCard.className = 'rounded-lg p-3 text-center border border-slate-100 bg-slate-50';
                diffCard.querySelector('.dm-diff-label').className = 'text-[10px] font-bold uppercase mb-1 dm-diff-label text-slate-400';
            } else {
                var dv = parseFloat(d.diff);
                if (dv > 0) {
                    diffEl.innerHTML = '<span class="text-red-600">▲ Rp ' + Math.abs(dv).toLocaleString('id-ID') + '</span>';
                    diffCard.className = 'rounded-lg p-3 text-center border border-red-100 bg-red-50';
                    diffCard.querySelector('.dm-diff-label').className = 'text-[10px] font-bold uppercase mb-1 dm-diff-label text-red-400';
                } else if (dv < 0) {
                    diffEl.innerHTML = '<span class="text-green-600">▼ Rp ' + Math.abs(dv).toLocaleString('id-ID') + '</span>';
                    diffCard.className = 'rounded-lg p-3 text-center border border-green-100 bg-green-50';
                    diffCard.querySelector('.dm-diff-label').className = 'text-[10px] font-bold uppercase mb-1 dm-diff-label text-green-500';
                } else {
                    diffEl.innerHTML = '<span class="text-slate-400">Rp 0</span>';
                    diffCard.className = 'rounded-lg p-3 text-center border border-slate-100 bg-slate-50';
                    diffCard.querySelector('.dm-diff-label').className = 'text-[10px] font-bold uppercase mb-1 dm-diff-label text-slate-400';
                }
            }

            // Multiple 2025 item names (from purchase history) — show a per-name
            // comparison table instead of the single 2025/2026/diff card layout.
            var priceSingle = document.getElementById('dm_price_single');
            var priceMulti  = document.getElementById('dm_price_multi');
            var pairs = d.price_pairs || [];
            if (pairs.length > 1) {
                priceSingle.classList.add('hidden');
                priceMulti.classList.remove('hidden');
                var body = document.getElementById('dm_price_multi_body');
                body.innerHTML = '';
                pairs.forEach(function (p) {
                    var tr = document.createElement('tr');
                    tr.className = 'border-t border-slate-100';

                    var nameTd = document.createElement('td');
                    nameTd.className = 'px-2 py-1.5 text-slate-700 break-words';
                    if (p.name) { nameTd.textContent = p.name; }
                    else { nameTd.innerHTML = '<span class="text-slate-400">—</span>'; }
                    tr.appendChild(nameTd);

                    var priceTd = document.createElement('td');
                    priceTd.className = 'px-2 py-1.5 text-center font-semibold text-slate-600 whitespace-nowrap';
                    priceTd.innerHTML = fmt(p.price, true);
                    tr.appendChild(priceTd);

                    var p2026Td = document.createElement('td');
                    p2026Td.className = 'px-2 py-1.5 text-center font-semibold text-indigo-800 whitespace-nowrap';
                    p2026Td.innerHTML = fmt(d.p2026, true);
                    tr.appendChild(p2026Td);

                    var diffTd = document.createElement('td');
                    diffTd.className = 'px-2 py-1.5 text-center whitespace-nowrap';
                    var diffHtml = '<span class="text-slate-400">—</span>';
                    if (p.diff !== null && p.diff !== undefined) {
                        var pv = parseFloat(p.diff);
                        if (pv > 0)      diffHtml = '<span class="text-red-600 font-semibold">▲ Rp ' + Math.abs(pv).toLocaleString('id-ID') + '</span>';
                        else if (pv < 0) diffHtml = '<span class="text-green-600 font-semibold">▼ Rp ' + Math.abs(pv).toLocaleString('id-ID') + '</span>';
                        else             diffHtml = '<span class="text-slate-400">Rp 0</span>';
                    }
                    diffTd.innerHTML = diffHtml;
                    tr.appendChild(diffTd);

                    body.appendChild(tr);
                });
            } else {
                priceSingle.classList.remove('hidden');
                priceMulti.classList.add('hidden');
            }

            // Realized 2026 average — moves with what was actually purchased this
            // year, unlike Price 2026 which stays at the last uploaded/edited
            // contract price. The meta line names the months it was averaged over.
            var avgEl     = document.getElementById('dm_avg2026');
            var avgMetaEl = document.getElementById('dm_avg2026_meta');
            var avgDiffEl = document.getElementById('dm_avg2026_diff');
            avgEl.innerHTML = fmt(d.avg2026, true);
            if (d.avg2026 === '' || d.avg2026 === null || d.avg2026 === undefined) {
                avgMetaEl.textContent = 'No purchases with a PO recorded in 2026';
                avgDiffEl.innerHTML   = '';
            } else {
                var meta = [];
                if (d.avg2026_range) meta.push(d.avg2026_range);
                if (d.avg2026_pos)   meta.push(d.avg2026_pos + (d.avg2026_pos > 1 ? ' POs' : ' PO'));
                if (d.avg2026_qty !== '' && d.avg2026_qty !== null && d.avg2026_qty !== undefined) {
                    meta.push(parseFloat(d.avg2026_qty).toLocaleString('id-ID') + ' qty');
                }
                avgMetaEl.textContent = meta.join(' · ');

                if (d.avg2026_diff === null || d.avg2026_diff === undefined || d.avg2026_diff === '') {
                    avgDiffEl.innerHTML = '';
                } else {
                    var av = parseFloat(d.avg2026_diff);
                    var absAv = 'Rp ' + Math.round(Math.abs(av)).toLocaleString('id-ID');
                    if (av > 0)      avgDiffEl.innerHTML = '<span class="text-red-600">▲ ' + absAv + ' vs contract</span>';
                    else if (av < 0) avgDiffEl.innerHTML = '<span class="text-green-600">▼ ' + absAv + ' vs contract</span>';
                    else             avgDiffEl.innerHTML = '<span class="text-slate-400">On contract price</span>';
                }
            }

            // Quantities
            document.getElementById('dm_qty').innerHTML    = fmt(d.quantity);
            document.getElementById('dm_used').innerHTML   = fmt(d.used_qty);
            var remainEl = document.getElementById('dm_remain');
            remainEl.innerHTML = fmt(d.remain_qty);
            // Match the table: highlight a low remaining qty in red so STC notices.
            remainEl.className = 'text-sm font-bold ' + (d.remain_low ? 'text-red-600' : 'text-slate-700');

            // Validity — two separate boxes (start / end date)
            var vFrom  = fmtDate(d.valid_from);
            var vUntil = fmtDate(d.valid_until);
            document.getElementById('dm_valid_from').innerHTML  = vFrom  ? vFrom  : '<span class="text-slate-400">—</span>';
            document.getElementById('dm_valid_until').innerHTML = vUntil ? vUntil : '<span class="text-slate-400">—</span>';

            // Flags
            var flagSec = document.getElementById('dm_flag_section');
            if (d.flag) {
                flagSec.classList.remove('hidden');
                var flagMap = {
                    dup_sku:           ['bg-red-100 text-red-700 border-red-200',       'Dup SKU'],
                    dup_name:          ['bg-orange-100 text-orange-700 border-orange-200','Dup Name'],
                    unmatched_catalog: ['bg-amber-100 text-amber-700 border-amber-200',  'No Catalog'],
                    unmatched_price:   ['bg-amber-100 text-amber-700 border-amber-200',  'No Price'],
                };
                var flagsEl = document.getElementById('dm_flags');
                flagsEl.innerHTML = '';
                d.flag.split(',').forEach(function (f) {
                    f = f.trim();
                    if (flagMap[f]) {
                        var sp = document.createElement('span');
                        sp.className = 'inline-block text-[10px] font-bold px-2 py-0.5 rounded border ' + flagMap[f][0];
                        sp.textContent = flagMap[f][1];
                        flagsEl.appendChild(sp);
                    }
                });
                var fd = document.getElementById('dm_flag_detail');
                fd.textContent = d.flag_detail || '';
                fd.classList.toggle('hidden', !d.flag_detail);
            } else {
                flagSec.classList.add('hidden');
            }

            // Reminder opt-out — only shown when there is one to reverse.
            detailSku = d.sku_code;
            var remSec = document.getElementById('dm_reminder_section');
            remSec.classList.toggle('hidden', !d.reminder_off);
            if (d.reminder_off) {
                document.getElementById('dm_reminder_state').textContent =
                    d.reminder_status
                        ? 'Reminders off — marked "' + d.reminder_status + '"'
                        : 'Reminders are off for this SKU';
                // On Progress carries no mandatory reason, so an empty remark is
                // expected there and must not read as something missing.
                document.getElementById('dm_reminder_remarks').textContent =
                    d.reminder_remarks || (d.reminder_status === 'On Progress' ? '' : '(no reason recorded)');
                document.getElementById('dm_reminder_who').textContent =
                    (d.reminder_by ? 'Set by ' + d.reminder_by : 'Set')
                    + (d.reminder_at ? ' on ' + d.reminder_at : '');
                var rb = document.getElementById('dm_reminder_restore');
                rb.disabled = false;
                rb.textContent = 'Turn back on';
            }

            // Last updated
            var updatedEl = document.getElementById('dm_updated');
            if (d.updated_at) {
                updatedEl.textContent = 'Last updated: ' + d.updated_at + (d.updated_by ? ' by ' + d.updated_by : '');
            } else {
                updatedEl.textContent = '';
            }

            detailModal.classList.remove('hidden');
        });
    });

    // ── Edit modal ────────────────────────────────────────────────────────────
    var editModal      = document.getElementById('editModal');
    var confirmEditBtn = document.getElementById('confirmEditBtn');
    var cancelEditBtn  = document.getElementById('cancelEditBtn');
    var closeEditBtn   = document.getElementById('closeEditBtn');
    var prevContainer  = document.getElementById('prevItemsContainer');
    var editId         = null;
    var avail2025Names = [];   // [{name, avg_price}] — ALL purchases_2025 names
    var avgByName      = {};   // name -> avg_price, for O(1) auto-fill lookup
    var names2025Loaded  = false;
    var names2025Loading = null;

    // The full 2025-name list (~thousands) is the same regardless of SKU, so
    // fetch it once and reuse it across every row and every edited SKU.
    function load2025Names() {
        if (names2025Loaded)  return Promise.resolve(avail2025Names);
        if (names2025Loading) return names2025Loading;
        names2025Loading = fetch('ajax_aon_2025_names.php')
            .then(function (r) { return r.json(); })
            .then(function (res) {
                avail2025Names = (res && res.names) ? res.names : [];
                avgByName = {};
                avail2025Names.forEach(function (n) { avgByName[n.name] = n.avg_price; });
                names2025Loaded = true;
                return avail2025Names;
            })
            .catch(function () { avail2025Names = []; return avail2025Names; });
        return names2025Loading;
    }

    // ── Thousand-separator helpers for the price inputs (e.g. 1,000,000) ──────
    function stripThousands(v) { return String(v == null ? '' : v).replace(/[,\s]/g, ''); }
    function formatThousands(v) {
        var s = stripThousands(v);
        if (s === '') return '';
        var neg = s.charAt(0) === '-'; if (neg) s = s.slice(1);
        var parts = s.split('.');
        var intPart = parts[0].replace(/\D/g, '');
        var dec = parts.length > 1 ? '.' + parts[1].replace(/\D/g, '') : '';
        if (intPart === '') intPart = (dec ? '0' : '');
        if (intPart !== '') intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (neg ? '-' : '') + intPart + dec;
    }
    function attachThousands(inp) {
        inp.addEventListener('input', function () { this.value = formatThousands(this.value); });
    }

    function escPrevHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function closeAllPrevPanels() {
        prevContainer.querySelectorAll('.prev-item-panel').forEach(function (p) { p.classList.add('hidden'); });
    }
    document.addEventListener('click', closeAllPrevPanels);

    // One row = a searchable, strict dropdown of ALL real purchases_2025 names
    // (search bar on top, pick by keyword) + an editable, comma-formatted
    // average-price input beside it.
    function makePrevItemRow(name, price) {
        name  = name  || '';
        price = price || '';
        var wrap = document.createElement('div');
        wrap.className = 'flex items-start gap-2';

        var priceInp = document.createElement('input');
        priceInp.type = 'text';
        priceInp.inputMode = 'decimal';
        priceInp.className = 'prev-item-price w-[150px] flex-shrink-0 border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400';
        priceInp.placeholder = 'Rp';
        priceInp.value = price ? formatThousands(price) : '';
        attachThousands(priceInp);

        // Searchable dropdown widget
        var dd = document.createElement('div');
        dd.className = 'prev-item-dd relative flex-1 min-w-0';

        var display = document.createElement('button');
        display.type = 'button';
        display.className = 'w-full text-left border border-slate-200 rounded-lg px-3 py-2 text-sm bg-white flex items-center justify-between gap-2 focus:outline-none focus:ring-2 focus:ring-indigo-400';
        var displayText = document.createElement('span');
        displayText.className = 'truncate ' + (name ? 'text-slate-800' : 'text-slate-400');
        displayText.textContent = name || '— Choose 2025 name —';
        display.appendChild(displayText);
        display.insertAdjacentHTML('beforeend', '<svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>');

        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.className = 'prev-item-value';
        hidden.value = name;

        var panel = document.createElement('div');
        panel.className = 'prev-item-panel hidden absolute z-20 mt-1 w-full bg-white border border-slate-200 rounded-lg shadow-lg';
        var searchWrap = document.createElement('div');
        searchWrap.className = 'p-2 border-b border-slate-100';
        var search = document.createElement('input');
        search.type = 'text';
        search.placeholder = 'Search 2025 name…';
        search.className = 'w-full border border-slate-200 rounded px-2 py-1 text-xs focus:outline-none focus:ring-1 focus:ring-indigo-400';
        searchWrap.appendChild(search);
        var list = document.createElement('div');
        list.className = 'max-h-56 overflow-y-auto py-1';
        panel.appendChild(searchWrap);
        panel.appendChild(list);

        function selectName(nm) {
            hidden.value = nm;
            displayText.textContent = nm;
            displayText.className = 'truncate text-slate-800';
            var ap = avgByName[nm];
            if (ap !== undefined && ap !== '') priceInp.value = formatThousands(ap);
            panel.classList.add('hidden');
        }

        var CAP = 100;
        function renderList(q) {
            q = (q || '').trim().toLowerCase();
            list.innerHTML = '';
            var count = 0;
            for (var i = 0; i < avail2025Names.length && count < CAP; i++) {
                var nm = avail2025Names[i].name;
                if (q && nm.toLowerCase().indexOf(q) === -1) continue;
                var opt = document.createElement('button');
                opt.type = 'button';
                opt.className = 'w-full text-left px-3 py-1.5 text-xs hover:bg-indigo-50 ' + (nm === hidden.value ? 'bg-indigo-50 font-semibold text-indigo-700' : 'text-slate-700');
                var ap = avgByName[nm];
                opt.innerHTML = '<span>' + escPrevHtml(nm) + '</span>' + (ap !== undefined && ap !== '' ? ' <span class="text-slate-400">(Rp ' + Number(ap).toLocaleString('id-ID') + ')</span>' : '');
                (function (nm2) { opt.addEventListener('click', function () { selectName(nm2); }); })(nm);
                list.appendChild(opt);
                count++;
            }
            if (count === 0) {
                var none = document.createElement('div');
                none.className = 'px-3 py-2 text-xs text-slate-400';
                none.textContent = avail2025Names.length ? 'No match' : 'Loading…';
                list.appendChild(none);
            } else if (count === CAP) {
                var more = document.createElement('div');
                more.className = 'px-3 py-1.5 text-[10px] text-slate-400';
                more.textContent = 'Showing first ' + CAP + ' — refine your search';
                list.appendChild(more);
            }
        }

        display.addEventListener('click', function (e) {
            e.stopPropagation();
            var isHidden = panel.classList.contains('hidden');
            closeAllPrevPanels();
            if (isHidden) { panel.classList.remove('hidden'); renderList(search.value); search.focus(); }
        });
        search.addEventListener('input', function () { renderList(this.value); });
        search.addEventListener('click', function (e) { e.stopPropagation(); });
        panel.addEventListener('click', function (e) { e.stopPropagation(); });

        dd.appendChild(display); dd.appendChild(hidden); dd.appendChild(panel);

        var rmBtn = document.createElement('button');
        rmBtn.type = 'button';
        rmBtn.title = 'Remove';
        rmBtn.className = 'w-4 mt-2.5 flex-shrink-0 text-slate-400 hover:text-red-500 transition';
        rmBtn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
        rmBtn.addEventListener('click', function () { wrap.remove(); });

        wrap.appendChild(dd); wrap.appendChild(priceInp); wrap.appendChild(rmBtn);
        return wrap;
    }

    function renderPrevRows(names, prices) {
        prevContainer.innerHTML = '';
        var any = false;
        for (var i = 0; i < names.length; i++) {
            if (!names[i]) continue;
            prevContainer.appendChild(makePrevItemRow(names[i], prices[i] || ''));
            any = true;
        }
        if (!any) prevContainer.appendChild(makePrevItemRow('', ''));
    }

    document.getElementById('addPrevItemBtn').addEventListener('click', function () {
        prevContainer.appendChild(makePrevItemRow('', ''));
    });

    function setCategorySelect(value) {
        var sel = document.getElementById('edit_category');
        var custom = sel.querySelector('option[data-custom="1"]');
        if (custom) custom.remove();
        if (value && !Array.from(sel.options).some(function (o) { return o.value === value; })) {
            var opt = document.createElement('option');
            opt.value = value;
            opt.textContent = value + ' (current — not in Area list)';
            opt.dataset.custom = '1';
            sel.insertBefore(opt, sel.options[1] || null);
        }
        sel.value = value || '';
    }

    function openEditModal(btn) {
        editId = btn.dataset.id;
        document.getElementById('editSkuLabel').textContent = 'SKU: ' + btn.dataset.sku;
        document.getElementById('edit_item_name').value   = btn.dataset.itemName  || '';
        document.getElementById('edit_vendor').value      = btn.dataset.vendor     || '';

        document.getElementById('edit_contract_no').value = btn.dataset.contractNo || '';
        setCategorySelect(btn.dataset.category || '');
        document.getElementById('edit_handler').value     = btn.dataset.handler    || '';
        document.getElementById('edit_valid_from').value  = btn.dataset.validFrom  || '';
        document.getElementById('edit_valid_until').value = btn.dataset.validUntil || '';
        document.getElementById('edit_price_2026').value  = btn.dataset.price2026  || '';
        document.getElementById('edit_quantity').value    = btn.dataset.quantity   || '';
        document.getElementById('edit_used_qty').value    = btn.dataset.usedQty    || '';
        document.getElementById('edit_remain_qty').value  = btn.dataset.remainQty  || '';

        // 2025 names/prices are pipe-aligned index-for-index. The full list of
        // real 2025 names (loaded once, shared across SKUs) backs the searchable
        // dropdown; rebuild the rows from saved values once it's ready.
        var savedNames  = (btn.dataset.previousItem || '').split('|').map(function (s) { return s.trim(); });
        var savedPrices = (btn.dataset.price2025    || '').split('|').map(function (s) { return s.trim(); });
        prevContainer.innerHTML = '<div class="text-xs text-slate-400 py-2">Loading 2025 names…</div>';
        load2025Names().then(function () { renderPrevRows(savedNames, savedPrices); });

        editModal.classList.remove('hidden');
    }

    function closeEditModal() { editModal.classList.add('hidden'); editId = null; }

    document.querySelectorAll('.js-open-edit').forEach(function (btn) {
        btn.addEventListener('click', function () { openEditModal(this); });
    });
    cancelEditBtn.addEventListener('click', closeEditModal);
    closeEditBtn.addEventListener('click',  closeEditModal);
    editModal.addEventListener('click', function (e) { if (e.target === editModal) closeEditModal(); });

    confirmEditBtn.addEventListener('click', function () {
        confirmEditBtn.disabled = true; confirmEditBtn.textContent = 'Saving…';
        // Gather name + price per row, keeping the two pipe-lists aligned
        // index-for-index (only rows with a chosen name are sent).
        var prevNames = [], prevPrices = [];
        prevContainer.querySelectorAll('.prev-item-dd').forEach(function (dd) {
            var name = (dd.querySelector('.prev-item-value').value || '').trim();
            if (!name) return;
            var priceInp = dd.parentNode.querySelector('.prev-item-price');
            prevNames.push(name);
            prevPrices.push(priceInp ? stripThousands(priceInp.value) : '');
        });

        fetch('ajax_aon_sku_edit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id:            editId,
                item_name:     document.getElementById('edit_item_name').value.trim(),
                vendor:        document.getElementById('edit_vendor').value.trim(),

                contract_no:   document.getElementById('edit_contract_no').value.trim(),
                category:      document.getElementById('edit_category').value.trim(),
                handler:       document.getElementById('edit_handler').value,
                valid_from:    document.getElementById('edit_valid_from').value,
                valid_until:   document.getElementById('edit_valid_until').value,
                price_2025:    prevPrices.join('|'),
                price_2026:    document.getElementById('edit_price_2026').value,
                quantity:      document.getElementById('edit_quantity').value,
                used_qty:      document.getElementById('edit_used_qty').value,
                remain_qty:    document.getElementById('edit_remain_qty').value,
                previous_item: prevNames.join('|'),
            })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            closeEditModal();
            confirmEditBtn.disabled = false; confirmEditBtn.textContent = 'Save Changes';
            if (res.ok && res.body.status === 'success') {
                showFlash('SKU updated successfully.', true);
                setTimeout(function () { location.reload(); }, 700);
            } else {
                showFlash(res.body.message || 'Failed to save changes.', false);
            }
        })
        .catch(function () {
            closeEditModal();
            confirmEditBtn.disabled = false; confirmEditBtn.textContent = 'Save Changes';
            showFlash('Network error — please try again.', false);
        });
    });

    // ── Expiry reminder opt-out ──────────────────────────────────────────────
    // The pop-up (includes/aon_stock_alert.php) owns switching reminders OFF;
    // this page owns switching it back ON, from the SKU's Details.
    //
    // The table itself shows nothing about it: the row is already carrying its
    // AON status and quantity, and an opted-out SKU is one nobody is being
    // chased about, so it does not need a column-level flag. Details is where
    // the decision and its reason live.

    // Called by the pop-up the moment an Ignore succeeds, so the Details for that
    // SKU offer to reverse it straight away rather than after a reload.
    window.aonMasterMarkIgnored = function (sku, remarks, kind) {
        document.querySelectorAll('#aonTableBody .js-open-detail').forEach(function (b) {
            try {
                var d = JSON.parse(b.dataset.detail);
                if (d.sku_code !== sku) return;
                d.reminder_off = true;
                d.reminder_remarks = remarks || '';
                d.reminder_status = kind === 'ON_PROGRESS' ? 'On Progress'
                                  : (kind === 'NOT_RENEWAL' ? 'Not Renewal' : '');
                b.dataset.detail = JSON.stringify(d);
            } catch (e) { /* a malformed payload must not break the pop-up's flow */ }
        });
    };

    // ── Shoot Reminder (Category Manager only) ───────────────────────────────
    // Absent from the DOM for every other role, so this whole block is inert
    // there rather than guarded by a role check in the browser.
    var shootBtn     = document.getElementById('aonShootReminderBtn');
    var shootModal   = document.getElementById('shootModal');
    var shootConfirm = document.getElementById('shootConfirmBtn');

    if (shootBtn && shootModal) {
        var shootLabel = shootBtn.querySelector('span');
        var shootText  = shootLabel ? shootLabel.textContent : '';

        function openShoot() {
            shootConfirm.disabled = false;
            shootConfirm.textContent = 'Send Reminder';
            shootModal.classList.remove('hidden');
        }
        function closeShoot() { shootModal.classList.add('hidden'); }
        function shootOpen()  { return !shootModal.classList.contains('hidden'); }

        shootBtn.addEventListener('click', openShoot);
        document.getElementById('shootCancelBtn').addEventListener('click', closeShoot);
        // Backdrop only — a click inside the panel must not dismiss it.
        shootModal.addEventListener('click', function (ev) { if (ev.target === shootModal) closeShoot(); });
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && shootOpen()) closeShoot();
        });

        shootConfirm.addEventListener('click', function () {
            // Closed before the request, not after: the outcome is reported by the
            // page-level flash, and leaving a dead modal on screen while the send
            // runs invites a second click on a disabled button.
            closeShoot();
            shootBtn.disabled = true;
            if (shootLabel) shootLabel.textContent = 'Sending…';
            fetch('ajax_aon_shoot_reminder.php', { method: 'POST' })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
                .then(function (res) {
                    shootBtn.disabled = false;
                    if (shootLabel) shootLabel.textContent = shootText;
                    // A "nothing new to send" reply is a success, not a failure —
                    // it means the group has already been told today.
                    showFlash(res.body.message || 'Could not send the reminder.',
                              res.ok && res.body.status === 'success');
                })
                .catch(function () {
                    shootBtn.disabled = false;
                    if (shootLabel) shootLabel.textContent = shootText;
                    showFlash('Network error — the reminder was not sent.', false);
                });
        });
    }

    var restoreBtn = document.getElementById('dm_reminder_restore');
    if (restoreBtn) {
        restoreBtn.addEventListener('click', function () {
            if (!detailSku) return;
            var sku = detailSku;
            restoreBtn.disabled = true;
            restoreBtn.textContent = 'Saving…';
            fetch('ajax_aon_reminder_ignore.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // No remark required to switch reminders back on: the risk being
                // guarded against is silently dropping a contract, not resuming one.
                body: JSON.stringify({ sku_code: sku, active: false })
            })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
            .then(function (res) {
                restoreBtn.disabled = false;
                restoreBtn.textContent = 'Turn back on';
                if (!res.ok || res.body.status !== 'success') {
                    showFlash(res.body.message || 'Could not turn reminders back on.', false);
                    return;
                }
                document.querySelectorAll('#aonTableBody .js-open-detail').forEach(function (b) {
                    try {
                        var d = JSON.parse(b.dataset.detail);
                        if (d.sku_code !== sku) return;
                        d.reminder_off = false;
                        b.dataset.detail = JSON.stringify(d);
                    } catch (e) { /* leave a malformed payload alone */ }
                });
                document.getElementById('dm_reminder_section').classList.add('hidden');
                showFlash('Expiry reminders switched back on for ' + sku + '.', true);
            })
            .catch(function () {
                restoreBtn.disabled = false;
                restoreBtn.textContent = 'Turn back on';
                showFlash('Network error — please try again.', false);
            });
        });
    }

    // ── Running-out warning: wiring for the pop-up ───────────────────────────
    // Only the behaviour lives here; the button itself was bound up in
    // aon_stock_alert_render(), long before this script exists, so it can never
    // be dead. See includes/aon_stock_alert.php.
    var stockBanner = document.getElementById('aonStockAlertBanner');

    window.aonStockAlertFilter = function () {
        alertOnly = true;
        // Clear whatever the page's own filters left behind, otherwise a stale
        // column search or the Unclaimed toggle can hide the very rows we just
        // isolated.
        filters = {};
        document.querySelectorAll('.col-search').forEach(function (inp) { inp.value = ''; });
        statusFilterSelect.value = '';
        if (unclaimedOnly) unclaimedBtn.click();   // resets the button styling too
        currentPage = 1;
        applyFilters();
        if (stockBanner) { stockBanner.classList.remove('hidden'); stockBanner.classList.add('flex'); }
    };

    var stockClearBtn = document.getElementById('aonStockAlertClearFilter');
    if (stockClearBtn) {
        stockClearBtn.addEventListener('click', function () {
            alertOnly = false;
            currentPage = 1;
            applyFilters();
            stockBanner.classList.remove('flex');
            stockBanner.classList.add('hidden');
        });
    }

    // ── Initial render (applies pagination on page load) ───────────────────────
    applyFilters();

    <?php if ($stock_filter_on_load): ?>
    // Arrived from the pop-up on the STC dashboard (?aon_alert=1) -- that page has
    // no table to narrow, so the filter is applied here instead. Closing the
    // pop-up up front (rather than after ready() has opened it) both avoids a
    // flash of a modal the user has already acted on and records the dismissal,
    // which is what acting on it means.
    window.aonStockAlertFilter();
    if (window.aonStockAlert) window.aonStockAlert.hide();
    <?php endif; ?>

    if (window.aonStockAlert) {
        // The SKU list is built and paginated -- the true "page is ready" moment,
        // so hand the lead-in cue over to the pop-up now.
        window.aonStockAlert.ready();
        // The button was pressed while the table was still building; the click was
        // remembered rather than dropped, so honour it now.
        if (window.aonStockAlert.wantsFilter()) window.aonStockAlertFilter();
    }

})();
</script>

<!-- ── Export ──────────────────────────────────────────────────────────────
     The visible table is hand-built markup with buttons, badges and inputs in
     its cells and is paginated by hiding rows, so it is not what gets exported.
     This hidden DataTable holds the full $export_rows set and owns the
     Excel/CSV/PDF buttons; the toolbar dropdown only triggers them. Paging stays
     on (and tiny) so only a handful of rows are ever put in the DOM — the
     buttons read from DataTables' own data store via modifier.page = 'all', so
     the file still contains every SKU. -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<div class="hidden" aria-hidden="true">
    <table id="aonExportTable" class="display"></table>
</div>

<script>
(function () {
    var exportData = <?= json_encode($export_rows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    // Order matches the $export_rows tuple built in the render loop above.
    var headers = [
        'SKU Code', 'Item Name', 'Category', 'Valid From', 'Valid Until', 'Status',
        'Contract Qty', 'Used Qty', 'Remaining Qty',
        'Vendor', 'Contract No', 'PIC / Owner', 'Price 2026', 'Price 2025'
    ];

    var d = new Date();
    var stamp = d.getFullYear() +
        ('0' + (d.getMonth() + 1)).slice(-2) +
        ('0' + d.getDate()).slice(-2);
    var fileName = 'Master_AON_' + stamp;
    // Every row, in the order the server sent them, regardless of what the
    // hidden table is currently paging or sorting.
    var exportOpts = { modifier: { page: 'all', search: 'none', order: 'index' } };

    var exportTable = $('#aonExportTable').DataTable({
        data: exportData,
        columns: headers.map(function (h) { return { title: h }; }),
        dom: '<"hidden"B>rt',
        pageLength: 10,
        searching: false,
        ordering: false,
        info: false,
        buttons: [
            { extend: 'excelHtml5', title: 'Master AON', filename: fileName, exportOptions: exportOpts },
            { extend: 'csvHtml5',   title: 'Master AON', filename: fileName, exportOptions: exportOpts },
            {
                extend: 'pdfHtml5', title: 'Master AON', filename: fileName, exportOptions: exportOpts,
                // 14 columns still need the width; the font shrink keeps long item
                // names and vendor names from wrapping rows into unreadable strips.
                orientation: 'landscape', pageSize: 'A3',
                customize: function (doc) {
                    doc.defaultStyle.fontSize = 7;
                    doc.styles.tableHeader.fontSize = 7;
                    doc.styles.title.fontSize = 11;
                }
            }
        ]
    });

    var exportBtn  = document.getElementById('aonExportBtn');
    var exportMenu = document.getElementById('aonExportMenu');

    exportBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        exportMenu.classList.toggle('hidden');
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#aonExportContainer')) exportMenu.classList.add('hidden');
    });

    function triggerExport(type) {
        exportTable.button('.buttons-' + type).trigger();
        exportMenu.classList.add('hidden');
    }
    document.getElementById('aonExportExcel').addEventListener('click', function () { triggerExport('excel'); });
    document.getElementById('aonExportCSV').addEventListener('click', function () { triggerExport('csv'); });
    document.getElementById('aonExportPDF').addEventListener('click', function () { triggerExport('pdf'); });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
