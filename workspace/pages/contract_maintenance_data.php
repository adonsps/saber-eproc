<?php
// pages/contract_maintenance_data.php
// Contract Maintenance data table: full list + add/edit. No delete -- these
// records are never removed, per the team's call (is_deleted stays on the
// table for a possible future admin tool, but nothing in this app sets it).
// See pages/contract_maintenance.php for the dashboard this feeds.
require_once '../includes/auth.php';
require_login();
require_once '../includes/contract_maintenance_helper.php';
require_once '../config/database.php';
require_once '../includes/header.php';

if (!contract_maintenance_can_access($_SESSION['role'] ?? '', $_SESSION['username'] ?? '')) {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Access Denied.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

$today = date('Y-m-d');

// Most recently added contract first (not expiry order) -- this list is a
// working add/edit table, not the expiry view (that's the dashboard's job).
$rows = $pdo->query(
    "SELECT * FROM contract_maintenance WHERE is_deleted = 0 ORDER BY created_at DESC, id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Searchable site/area dropdown options: the spreadsheet-sourced suggestion
// list, plus any values already in the table that aren't on it (e.g. from
// historical seed data or a prior ad-hoc Select2 addition).
// site_area has no "is this a site or an area" column (see helper.php
// comment), so an unrecognized existing DB value is classified by simple
// membership: matches a known area -> area list, otherwise -> site list.
// site_area may hold several comma-joined values now (multi-select) -- split
// each stored value back into its individual site/area names before merging.
$db_sites_raw = $pdo->query(
    "SELECT DISTINCT site_area FROM contract_maintenance WHERE is_deleted = 0 AND site_area IS NOT NULL AND site_area != '' ORDER BY site_area"
)->fetchAll(PDO::FETCH_COLUMN);
$db_sites = [];
foreach ($db_sites_raw as $v) {
    foreach (contract_maintenance_split_multi($v) as $s) {
        $db_sites[] = $s;
    }
}
$area_options = contract_maintenance_area_options();
$site_options = contract_maintenance_site_options();
foreach ($db_sites as $v) {
    if (in_array($v, $area_options, true) || in_array($v, $site_options, true)) {
        continue;
    }
    $site_options[] = $v;
}
$site_options = array_values(array_unique($site_options));
$area_options = array_values(array_unique($area_options));
sort($site_options);
sort($area_options);

// Category and Business Unit dropdowns: same "hardcoded suggestions + any
// value already used in the table" merge as Site/Area above, so a value
// someone types in (Select2 tags:true) becomes a selectable option for
// everyone from the next contract onward, once it's been saved once. Both
// are user-extensible now; see ajax_contract_maintenance_edit.php for why
// server-side validation no longer restricts them to the hardcoded list.
$db_categories = $pdo->query(
    "SELECT DISTINCT item_category FROM contract_maintenance WHERE is_deleted = 0 AND item_category IS NOT NULL AND item_category != ''"
)->fetchAll(PDO::FETCH_COLUMN);
$categories = array_values(array_unique(array_merge(contract_maintenance_categories(), $db_categories)));
sort($categories);

$db_business_units_raw = $pdo->query(
    "SELECT DISTINCT business_unit FROM contract_maintenance WHERE is_deleted = 0 AND business_unit IS NOT NULL AND business_unit != ''"
)->fetchAll(PDO::FETCH_COLUMN);
$db_business_units = [];
foreach ($db_business_units_raw as $v) {
    foreach (contract_maintenance_split_multi($v) as $bu) {
        $db_business_units[] = $bu;
    }
}
$business_units = array_values(array_unique(array_merge(contract_maintenance_business_units(), $db_business_units)));
sort($business_units);

$contract_types = contract_maintenance_types();
$output_types   = contract_maintenance_output_types();
?>

<div class="max-w-full">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4 mb-6 border-b pb-4">
        <div class="min-w-0">
            <h2 class="text-2xl font-bold text-gray-800">Contract Maintenance Data</h2>
            <p class="text-xs text-gray-500 mt-1">Add, edit and retire service &amp; maintenance contract records.</p>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <a href="contract_maintenance.php"
                class="h-10 box-border inline-flex items-center gap-2 px-4 rounded-lg border border-slate-200 bg-white text-slate-700 font-bold text-sm shadow-sm hover:bg-slate-50 transition whitespace-nowrap">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path></svg>
                Back to Dashboard
            </a>
            <button type="button" id="addContractBtn"
                class="h-10 box-border inline-flex items-center gap-2 px-4 rounded-lg border border-indigo-600 bg-indigo-600 text-white font-bold text-sm shadow-sm hover:bg-indigo-700 hover:border-indigo-700 transition whitespace-nowrap">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Add Contract
            </button>
        </div>
    </div>

    <!-- Flash -->
    <div id="cmFlash" class="hidden mb-4 px-4 py-3 rounded-lg font-semibold text-sm"></div>

    <!-- Filters + global search, replacing DataTables' default length/search controls -->
    <div class="flex flex-wrap items-center gap-2 mb-3">
        <select id="filterBu" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-400">
            <option value="">All Business Units</option>
            <?php foreach ($business_units as $bu): ?>
            <option value="<?= htmlspecialchars($bu) ?>"><?= htmlspecialchars($bu) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterCategory" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-400">
            <option value="">All Categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterType" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-400">
            <option value="">All Types</option>
            <?php foreach ($contract_types as $t): ?>
            <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filterStatus" class="border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-slate-700 focus:outline-none focus:ring-1 focus:ring-indigo-400">
            <option value="">All Statuses</option>
            <option value="Expired">Expired</option>
            <option value="90 Days">90 Days</option>
            <option value="180 Days">180 Days</option>
            <option value="Active">Active</option>
            <option value="No Date">No Date</option>
        </select>
        <input id="globalSearch" type="text" placeholder="Search..."
            class="ml-auto border border-slate-200 rounded-lg px-3 py-1.5 text-xs w-56 focus:outline-none focus:ring-1 focus:ring-indigo-400">
    </div>

    <!-- Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-x-auto">
        <table id="cmTable" class="w-full text-sm text-left">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Category</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Description</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Supplier</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Type</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">BU</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Site/Area</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Contract End</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Output</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Output End</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Status</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Edit</th>
                </tr>
            </thead>
            <tbody>
                <!-- No hand-written "no data" row here: DataTables generates its own
                     zero-records row (see language.emptyTable below) with a colspan it
                     computes itself. A row supplied in the initial HTML is instead parsed
                     as real data during DataTables' HTML-source init, and a single
                     colspan="11" cell counts as 1 cell against the 11-column header --
                     that mismatch is what throws "Incorrect column count" (found
                     2026-09-08, unrelated to the same-day PR #263 merge). -->
                <?php foreach ($rows as $r):
                    $status = contract_maintenance_status($r['output_end_date'], $today);
                    $status_cls = [
                        'expired'      => 'bg-red-100 text-red-800',
                        'expiring_90'  => 'bg-amber-100 text-amber-800',
                        'expiring_180' => 'bg-yellow-100 text-yellow-800',
                        'active'       => 'bg-emerald-100 text-emerald-800',
                        'unknown'      => 'bg-slate-100 text-slate-500',
                    ][$status['key']];
                    $row_json = htmlspecialchars(json_encode($r), ENT_QUOTES);
                ?>
                <tr class="border-b border-slate-100 hover:bg-indigo-50/30 transition">
                    <td class="px-3 py-2"><?= htmlspecialchars($r['item_category']) ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($r['description'] ?? '') ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($r['supplier'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= htmlspecialchars($r['contract_type']) ?></td>
                    <td class="px-3 py-2"><?= htmlspecialchars($r['business_unit']) ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($r['site_area'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($r['contract_end_date'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= htmlspecialchars($r['output_type'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($r['output_end_date'] ?? '') ?></td>
                    <td class="px-3 py-2 text-center">
                        <span class="px-2 inline-flex text-[11px] leading-5 font-semibold rounded-full <?= $status_cls ?>"><?= htmlspecialchars($status['label']) ?></span>
                    </td>
                    <td class="px-3 py-2 whitespace-nowrap">
                        <button type="button" class="js-open-edit p-1.5 rounded-md bg-amber-100 text-amber-700 border border-amber-200 hover:bg-amber-500 hover:text-white hover:border-amber-500 transition" title="Edit"
                            data-row='<?= $row_json ?>'>
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ═══════════════════════ EDIT/ADD MODAL ═══════════════════════ -->
<div id="editModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
            <div>
                <h3 id="editModalTitle" class="text-base font-bold text-slate-800">Add Contract</h3>
                <p id="editAddedByInfo" class="hidden text-xs text-slate-400 mt-0.5"></p>
            </div>
            <button type="button" id="closeEditBtn" class="text-slate-400 hover:text-slate-600 transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="px-6 py-5 overflow-y-auto flex-1">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <input type="hidden" id="edit_id" value="">

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Category <span class="text-red-500">*</span></label>
                    <select id="edit_item_category" class="cm-select2 w-full" data-placeholder="Choose or type to add..." style="width:100%">
                        <option value=""></option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Business Unit <span class="text-red-500">*</span></label>
                    <select id="edit_business_unit" multiple="multiple" class="cm-select2 w-full" data-placeholder="Choose or type to add..." style="width:100%">
                        <?php foreach ($business_units as $bu): ?>
                        <option value="<?= htmlspecialchars($bu) ?>"><?= htmlspecialchars($bu) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Description <span class="text-red-500">*</span></label>
                    <input id="edit_description" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Supplier <span class="text-red-500">*</span></label>
                    <input id="edit_supplier" type="text" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Contract Type <span class="text-red-500">*</span></label>
                    <select id="edit_contract_type" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="">Choose</option>
                        <?php foreach ($contract_types as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Full width: the Site/Area radio adds a row above the select, making
                     this cell taller than a single-line field, so it no longer pairs
                     left/right with a same-height neighbor (see contract_maintenance_data.php
                     2026-09-04 alignment fix). -->
                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Site / Area <span class="text-red-500">*</span></label>
                    <div class="flex gap-4 mb-2">
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="radio" name="edit_site_area_type" value="site" checked class="form-radio h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                            <span class="ml-2 text-sm text-slate-700">Site</span>
                        </label>
                        <label class="inline-flex items-center cursor-pointer">
                            <input type="radio" name="edit_site_area_type" value="area" class="form-radio h-4 w-4 text-indigo-600 focus:ring-indigo-500">
                            <span class="ml-2 text-sm text-slate-700">Area</span>
                        </label>
                    </div>
                    <select id="edit_site_area" multiple="multiple" class="cm-select2 w-full" data-placeholder="Search site..." style="width:100%">
                        <?php foreach ($site_options as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Contract End Date <span class="text-red-500">*</span></label>
                    <input id="edit_contract_end_date" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Output Type <span class="text-red-500">*</span></label>
                    <select id="edit_output_type" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                        <option value="">Choose</option>
                        <?php foreach ($output_types as $o): ?>
                        <option value="<?= htmlspecialchars($o) ?>"><?= htmlspecialchars($o) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Output End Date <span class="text-red-500">*</span></label>
                    <input id="edit_output_end_date" type="date" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Google Drive Link</label>
                    <input id="edit_gdrive_link" type="url" placeholder="https://drive.google.com/…" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>

                <div class="sm:col-span-2">
                    <label class="block text-xs font-bold text-slate-700 mb-1">Remarks</label>
                    <textarea id="edit_remarks" rows="3" class="w-full border border-slate-200 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400"></textarea>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3 flex-shrink-0 border-t border-slate-100">
            <button id="cancelEditBtn" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="confirmEditBtn" type="button" class="px-5 py-2 text-sm font-bold text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition">Save</button>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function () {
    // No length menu / built-in search box -- replaced by the filter bar +
    // global search input above the table, wired below.
    // order: [] keeps the server's row order (most recently added first --
    // see the PHP query above) as the initial display, instead of DataTables
    // auto-sorting by a column on load. Column headers are still clickable to
    // sort another way.
    var cmTable = $('#cmTable').DataTable({
        pageLength: 25,
        order: [],
        dom: 'tip',
        language: {
            emptyTable: 'No contracts yet. Use <strong>Add Contract</strong> above.'
        }
    });

    document.getElementById('globalSearch').addEventListener('input', function () {
        cmTable.search(this.value).draw();
    });
    // Column indexes match the <thead> above: 4 = BU, 0 = Category, 3 = Type, 9 = Status.
    // (Company is a modal-only field, not a table column, so it isn't indexed here.)
    document.getElementById('filterBu').addEventListener('change', function () {
        // BU column can hold several comma-separated values now (multi-select),
        // so match the chosen value as one whole token in that list rather
        // than the whole cell (e.g. "Property" must not match "Property Two").
        var v = $.fn.dataTable.util.escapeRegex(this.value);
        cmTable.column(4).search(this.value ? '(^|,\\s*)' + v + '(\\s*,|$)' : '', true, false).draw();
    });
    document.getElementById('filterCategory').addEventListener('change', function () {
        cmTable.column(0).search(this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '', true, false).draw();
    });
    document.getElementById('filterType').addEventListener('change', function () {
        cmTable.column(3).search(this.value ? '^' + $.fn.dataTable.util.escapeRegex(this.value) + '$' : '', true, false).draw();
    });
    document.getElementById('filterStatus').addEventListener('change', function () {
        cmTable.column(9).search(this.value ? $.fn.dataTable.util.escapeRegex(this.value) : '', true, false).draw();
    });

    var editModal      = document.getElementById('editModal');
    var editModalTitle  = document.getElementById('editModalTitle');
    var editAddedByInfo = document.getElementById('editAddedByInfo');
    var addBtn          = document.getElementById('addContractBtn');
    var closeEditBtn    = document.getElementById('closeEditBtn');
    var cancelEditBtn   = document.getElementById('cancelEditBtn');
    var confirmEditBtn  = document.getElementById('confirmEditBtn');
    var flash           = document.getElementById('cmFlash');

    function showFlash(message, ok) {
        flash.textContent = message;
        flash.className = 'mb-4 px-4 py-3 rounded-lg font-semibold text-sm ' + (ok ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200');
        flash.classList.remove('hidden');
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function initSelect2() {
        // Each select supplies its own placeholder via data-placeholder on the
        // element (set below), rather than one shared string here. edit_site_area
        // is excluded -- setSiteAreaType() below owns its init since its option
        // list and placeholder swap between Site and Area.
        $('.cm-select2').not('#edit_site_area').each(function () {
            $(this).select2({ dropdownParent: $('#editModal'), width: '100%', allowClear: true, tags: true });
        });
    }
    initSelect2();

    // Site/Area options split (see contract_maintenance_helper.php): the user
    // picks Site or Area via radio first, then edit_site_area's own option
    // list + placeholder swap to match. Both still save into the single
    // site_area column -- this split is UI-only, not a data model change.
    var siteAreaOptions = {
        site: <?= json_encode($site_options) ?>,
        area: <?= json_encode($area_options) ?>
    };

    // selectedValues: array of already-picked site/area names (multi-select --
    // see contract_maintenance_helper.php's contract_maintenance_split_multi()
    // for how a stored comma-joined site_area value becomes this array).
    function setSiteAreaType(type, selectedValues) {
        selectedValues = selectedValues || [];
        var $select = $('#edit_site_area');
        var options = siteAreaOptions[type] || [];
        var placeholder = type === 'area' ? 'Search area...' : 'Search site...';
        if ($select.data('select2')) {
            $select.select2('destroy');
        }
        $select.empty();
        options.forEach(function (opt) {
            $select.append(new Option(opt, opt, false, false));
        });
        selectedValues.forEach(function (v) {
            if (!v) return;
            if ($select.find('option[value="' + v.replace(/"/g, '\\"') + '"]').length === 0) {
                $select.append(new Option(v, v, false, false));
            }
        });
        $select.val(selectedValues);
        // Both attr() and data() are needed: Select2's data-placeholder reader
        // pulls from jQuery's cached .data(), which attr() alone doesn't update
        // once jQuery has read the attribute once (a jQuery caching quirk).
        $select.attr('data-placeholder', placeholder).data('placeholder', placeholder);
        $select.select2({ dropdownParent: $('#editModal'), width: '100%', allowClear: true, tags: true, placeholder: placeholder });
        $select.trigger('change');
    }
    setSiteAreaType('site');

    $('input[name="edit_site_area_type"]').on('change', function () {
        setSiteAreaType(this.value);
    });

    // Appends any value missing from a Select2 (tags:true) element's option
    // list, then selects exactly `values` -- used for item_category (single,
    // but still tags:true so a value not in the merged suggestion list, e.g.
    // stale data, still shows) and business_unit (multi-select).
    function ensureOptionsAndSet($select, values) {
        values.forEach(function (v) {
            if (!v) return;
            if ($select.find('option[value="' + v.replace(/"/g, '\\"') + '"]').length === 0) {
                $select.append(new Option(v, v, false, false));
            }
        });
        $select.val(values).trigger('change');
    }

    function resetForm() {
        document.getElementById('edit_id').value = '';
        ensureOptionsAndSet($('#edit_item_category'), []);
        ensureOptionsAndSet($('#edit_business_unit'), []);
        document.getElementById('edit_description').value = '';
        document.getElementById('edit_supplier').value = '';
        document.getElementById('edit_contract_type').value = '';
        $('input[name="edit_site_area_type"][value="site"]').prop('checked', true);
        setSiteAreaType('site');
        document.getElementById('edit_contract_end_date').value = '';
        document.getElementById('edit_output_type').value = '';
        document.getElementById('edit_output_end_date').value = '';
        document.getElementById('edit_gdrive_link').value = '';
        document.getElementById('edit_remarks').value = '';
    }

    addBtn.addEventListener('click', function () {
        resetForm();
        editModalTitle.textContent = 'Add Contract';
        editAddedByInfo.classList.add('hidden');
        editModal.classList.remove('hidden');
    });

    // Delegated on document (not bound per-button): DataTables regenerates each
    // row's cells from its own cached data on every search/sort/page redraw,
    // which replaces these buttons with fresh nodes and drops any listener
    // attached directly to them at page load.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.js-open-edit');
        if (!btn) return;
        var row = JSON.parse(btn.dataset.row);
        resetForm();
        editModalTitle.textContent = 'Edit Contract';
        if (row.created_by) {
            var addedDate = row.created_at ? new Date(row.created_at.replace(' ', 'T')).toLocaleDateString() : '';
            editAddedByInfo.textContent = 'Added by ' + row.created_by + (addedDate ? ' on ' + addedDate : '');
            editAddedByInfo.classList.remove('hidden');
        } else {
            editAddedByInfo.classList.add('hidden');
        }
        document.getElementById('edit_id').value = row.id;
        ensureOptionsAndSet($('#edit_item_category'), row.item_category ? [row.item_category] : []);
        // business_unit is comma-separated (multi-select) -- split back into
        // individual values. See contract_maintenance_helper.php's PHP-side
        // equivalent, contract_maintenance_split_multi().
        var buValues = row.business_unit ? row.business_unit.split(',').map(function (s) { return s.trim(); }).filter(Boolean) : [];
        ensureOptionsAndSet($('#edit_business_unit'), buValues);
        document.getElementById('edit_description').value = row.description || '';
        document.getElementById('edit_supplier').value = row.supplier || '';
        document.getElementById('edit_contract_type').value = row.contract_type || '';
        if (row.site_area) {
            var siteAreaValues = row.site_area.split(',').map(function (s) { return s.trim(); }).filter(Boolean);
            // Classify by the first value -- Site and Area are presented as a
            // single either/or choice (radio) in this UI, so a row's values are
            // expected to all come from the same list.
            var siteAreaType = siteAreaOptions.area.indexOf(siteAreaValues[0]) !== -1 ? 'area' : 'site';
            $('input[name="edit_site_area_type"][value="' + siteAreaType + '"]').prop('checked', true);
            setSiteAreaType(siteAreaType, siteAreaValues);
        }
        document.getElementById('edit_contract_end_date').value = row.contract_end_date || '';
        document.getElementById('edit_output_type').value = row.output_type || '';
        document.getElementById('edit_output_end_date').value = row.output_end_date || '';
        document.getElementById('edit_gdrive_link').value = row.gdrive_link || '';
        document.getElementById('edit_remarks').value = row.remarks || '';
        editModal.classList.remove('hidden');
    });

    function closeEdit() { editModal.classList.add('hidden'); }
    closeEditBtn.addEventListener('click', closeEdit);
    cancelEditBtn.addEventListener('click', closeEdit);
    editModal.addEventListener('click', function (e) { if (e.target === editModal) closeEdit(); });

    confirmEditBtn.addEventListener('click', function () {
        var category = document.getElementById('edit_item_category').value;
        // Business Unit and Site/Area are multi-select now -- $.val() on a
        // <select multiple> returns an array (native .value would only give
        // the first selected option).
        var bu = $('#edit_business_unit').val() || [];
        var description = document.getElementById('edit_description').value.trim();
        var supplier = document.getElementById('edit_supplier').value.trim();
        var type = document.getElementById('edit_contract_type').value;
        var siteArea = $('#edit_site_area').val() || [];
        var contractEndDate = document.getElementById('edit_contract_end_date').value;
        var outputType = document.getElementById('edit_output_type').value;
        var outputEndDate = document.getElementById('edit_output_end_date').value;
        var gdriveLink = document.getElementById('edit_gdrive_link').value.trim();

        // Everything is required except Google Drive Link and Remarks.
        if (!category || bu.length === 0 || !description || !supplier || !type || siteArea.length === 0 ||
            !contractEndDate || !outputType || !outputEndDate) {
            alert('All fields are required, except Google Drive Link and Remarks.');
            return;
        }
        confirmEditBtn.disabled = true;
        confirmEditBtn.textContent = 'Saving…';
        fetch('ajax_contract_maintenance_edit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'save',
                id: document.getElementById('edit_id').value,
                item_category: category,
                business_unit: bu,
                description: description,
                supplier: supplier,
                contract_type: type,
                site_area: siteArea,
                contract_end_date: contractEndDate,
                output_type: outputType,
                output_end_date: outputEndDate,
                gdrive_link: gdriveLink,
                remarks: document.getElementById('edit_remarks').value
            })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
        .then(function (res) {
            confirmEditBtn.disabled = false;
            confirmEditBtn.textContent = 'Save';
            if (res.ok && res.body.status === 'success') {
                closeEdit();
                showFlash('Saved.', true);
                setTimeout(function () { location.reload(); }, 600);
            } else {
                showFlash(res.body.message || 'Could not save.', false);
            }
        })
        .catch(function () {
            confirmEditBtn.disabled = false;
            confirmEditBtn.textContent = 'Save';
            showFlash('Network error while saving.', false);
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
