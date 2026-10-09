<?php
// pages/cost_savings.php
//
// Cost Savings Matrix — matches current PO lines to 2025 purchases by exact
// SKU + UOM and reports the saving against a weighted-average 2025 benchmark.
//
// SERVER-SIDE. This page renders a shell: table headers, empty card slots and a
// canvas. Every figure and every row arrives from ajax_cost_savings_summary.php
// and ajax_cost_savings_table.php, which share their rules with
// includes/cost_savings_helper.php.
//
// It used to do all of that here: one PHP loop over every eligible PO line and
// every purchases_2025 row, with all ~15.8k resulting rows printed into the HTML
// and DataTables paging them in the browser. That produced a 42.89 MB response
// (~800k DOM nodes) on every load, and every saved correction re-ran it via
// location.reload(). Same treatment cost_avoidance.php had in PR #94.
require_once '../includes/auth.php';
require_once '../includes/cost_savings_access.php';
$cost_savings_role = cs_require_page_access();

require_once '../config/database.php';
require_once '../includes/header.php';

// AON mode restricts the dataset to Master AON SKUs; today only STC is forced
// into it. The endpoints re-derive this from the session themselves — the copy
// here only drives the badge and the subtitle.
$aon_only = cs_is_aon_only($cost_savings_role);

// Only Category Managers can reassign teams or override 2025 benchmark prices.
$can_correct = cs_can_correct($cost_savings_role);
$reassignable_teams = ['CSGSM', 'TIGS', 'FNBOM'];

// Years available for the quarter/year filter — distinct p.pr_date years among
// eligible PR lines (same core eligibility test as cs_eligibility_where(), minus
// the team join, which this dropdown doesn't need). Falls back to an empty list
// (filter still renders, just with only "All Years") if the query ever fails.
$cs_years = [];
try {
    $cs_years = $pdo->query("
        SELECT DISTINCT YEAR(p.pr_date) AS yr
        FROM prs p
        WHERE p.po_number IS NOT NULL AND p.po_number != ''
          AND p.po_amount > 0 AND p.qty > 0
          AND p.sku IS NOT NULL AND p.sku != ''
          AND p.pr_date IS NOT NULL
        ORDER BY yr DESC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $cs_years = [];
}

// Taxonomy Level 2 options for the same filter bar — same join Cost Savings'
// own team resolution and pr_taxonomy_lookup.php/sla_report.php elsewhere in
// the app already use (prs.master_data_id -> master_data.level_2). Only
// distinct values actually present in the eligible dataset are offered, same
// philosophy as sla_report.php's taxonomy_combos. NOTE: locally, master_data_id
// is populated on well under 5% of eligible lines (a one-off tagging batch,
// not an ongoing pipeline) — most rows will filter under "Unmapped" here until
// that coverage improves; production may differ.
$cs_level2_options = [];
try {
    $cs_level2_options = $pdo->query("
        SELECT DISTINCT md.level_2 AS lvl
        FROM prs p
        LEFT JOIN master_data md ON p.master_data_id = md.id
        WHERE p.po_number IS NOT NULL AND p.po_number != ''
          AND p.po_amount > 0 AND p.qty > 0
          AND p.sku IS NOT NULL AND p.sku != ''
          AND md.level_2 IS NOT NULL AND TRIM(md.level_2) != ''
        ORDER BY lvl ASC
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $cs_level2_options = [];
}

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
    .item-desc {
        max-width: 320px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        cursor: pointer;
    }
    .item-desc.expanded {
        max-width: none;
        white-space: normal;
        word-break: break-word;
    }
</style>

<div class="mb-6 flex flex-col lg:flex-row lg:justify-between lg:items-end gap-4 border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">
            Cost Savings Matrix
            <?php if ($aon_only): ?><span class="align-middle ml-2 text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded font-bold uppercase">AON SKUs Only</span><?php endif; ?>
        </h2>
        <p class="text-sm text-gray-600">Matches current PRs to 2025 purchases by exact SKU and UOM, flagging &gt;10x price differences for review.<?php if ($aon_only): ?> Showing Master AON SKUs only.<?php endif; ?></p>
    </div>
    <a href="<?= h($dashboard_link) ?>" class="inline-flex items-center self-start bg-gray-500 hover:bg-gray-600 text-white font-semibold py-1.5 px-3 rounded-lg text-sm shadow transition">&larr; Back to Dashboard</a>
</div>

<div id="csNo2025" class="hidden bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 shadow-sm font-bold">The purchases_2025 table was not found, so savings cannot be calculated yet.</div>
<div id="csError" class="hidden bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 shadow-sm font-semibold"></div>

<div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-6 flex flex-wrap items-end gap-4">
    <div>
        <label for="csFilterYear" class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Year</label>
        <select id="csFilterYear" class="border border-gray-300 rounded text-sm py-1.5 px-2">
            <option value="">All Years</option>
            <?php foreach ($cs_years as $yr): ?>
                <option value="<?= h($yr) ?>"><?= h($yr) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label for="csFilterQuarter" class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Quarter</label>
        <select id="csFilterQuarter" class="border border-gray-300 rounded text-sm py-1.5 px-2">
            <option value="">All Quarters</option>
            <option value="1">Q1 (Jan&ndash;Mar)</option>
            <option value="2">Q2 (Apr&ndash;Jun)</option>
            <option value="3">Q3 (Jul&ndash;Sep)</option>
            <option value="4">Q4 (Oct&ndash;Dec)</option>
        </select>
    </div>
    <div>
        <label for="csFilterMonth" class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Month</label>
        <select id="csFilterMonth" class="border border-gray-300 rounded text-sm py-1.5 px-2">
            <option value="">All Months</option>
            <option value="1">Jan</option>
            <option value="2">Feb</option>
            <option value="3">Mar</option>
            <option value="4">Apr</option>
            <option value="5">May</option>
            <option value="6">Jun</option>
            <option value="7">Jul</option>
            <option value="8">Aug</option>
            <option value="9">Sep</option>
            <option value="10">Oct</option>
            <option value="11">Nov</option>
            <option value="12">Dec</option>
        </select>
    </div>
    <div>
        <label for="csFilterTeam" class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Team</label>
        <select id="csFilterTeam" class="border border-gray-300 rounded text-sm py-1.5 px-2">
            <option value="">All Teams</option>
        </select>
    </div>
    <div>
        <label for="csFilterLevel2" class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Taxonomy Level 2</label>
        <select id="csFilterLevel2" class="border border-gray-300 rounded text-sm py-1.5 px-2">
            <option value="">All Categories</option>
            <?php foreach ($cs_level2_options as $lvl): ?>
                <option value="<?= h($lvl) ?>"><?= h($lvl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="flex-1 min-w-[200px]">
        <label for="csGlobalSearch" class="block text-[11px] font-bold text-gray-500 uppercase mb-1">Search</label>
        <input type="text" id="csGlobalSearch" placeholder="Ticket, PO, SKU, item, vendor..." class="w-full border border-gray-300 rounded text-sm py-1.5 px-2">
    </div>
</div>

<div class="relative mb-6">
    <div class="viz-cards-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-70 rounded-lg">
        <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-5 gap-4">
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <p class="text-xs font-bold text-gray-500 uppercase">Current Nett Spend</p>
            <p id="kpiSpend" class="text-xl font-bold text-gray-800 mt-2">&hellip;</p>
            <p class="text-[11px] text-gray-500 mt-1">Eligible PO lines with SKU</p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <p class="text-xs font-bold text-gray-500 uppercase">2025 Benchmark</p>
            <p id="kpiBenchmark" class="text-xl font-bold text-gray-800 mt-2">&hellip;</p>
            <p class="text-[11px] text-gray-500 mt-1">What the same items would have cost at 2025 prices</p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <p class="text-xs font-bold text-gray-500 uppercase">Total Cost Savings</p>
            <p id="kpiSavings" class="text-xl font-bold mt-2 text-green-600">&hellip;</p>
            <p class="text-[11px] text-gray-500 mt-1">Current spend is <span id="kpiSavingsNote"></span></p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <p class="text-xs font-bold text-gray-500 uppercase">Matched Coverage</p>
            <p id="kpiCoverage" class="text-xl font-bold text-indigo-700 mt-2">&hellip;</p>
            <p class="text-[11px] text-gray-500 mt-1"><span id="kpiCoverageNote"></span></p>
        </div>
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
            <p class="text-xs font-bold text-gray-500 uppercase">Line Movement</p>
            <p id="kpiMovement" class="text-xl font-bold text-gray-800 mt-2">&hellip;</p>
            <p class="text-[11px] text-gray-500 mt-1">PO lines vs. their 2025 benchmark price</p>
        </div>
    </div>
</div>

<div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-6">
    <div class="mb-4">
        <h3 class="text-sm font-bold text-gray-700">Savings Impact by Team</h3>
        <p class="text-xs text-gray-500">The benchmark is based on the weighted-average 2025 unit price for each exact SKU and UOM match. Money is shown on a logarithmic scale, since team spend varies by several orders of magnitude; Savings % has its own chart rather than sharing an axis with Rupiah amounts, so neither scale gets distorted.</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="relative h-72 lg:col-span-2">
            <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            </div>
            <canvas id="savingsChart"></canvas>
        </div>
        <div class="relative h-72">
            <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            </div>
            <p class="text-[11px] font-bold text-gray-500 uppercase text-center mb-1">Savings %</p>
            <canvas id="savingsPctChart"></canvas>
        </div>
    </div>
</div>

<div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden mb-6 relative">
    <div class="viz-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80">
        <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        <p class="mt-3 text-xs font-semibold text-gray-500">Loading report&hellip;</p>
    </div>
    <div class="bg-gray-100 border-b border-gray-200 px-4 pt-3 flex flex-wrap gap-2">
        <button onclick="switchTab('matched')" id="tab-matched" class="px-4 py-2 text-sm font-bold rounded-t-lg bg-white text-indigo-700 shadow-sm">Matched Savings Report (<span class="tab-count">&hellip;</span>)</button>
        <button onclick="switchTab('review')" id="tab-review" class="px-4 py-2 text-sm font-bold rounded-t-lg text-gray-600 hover:text-gray-800">Price Mismatch - Needs Review (<span class="tab-count">&hellip;</span>)</button>
        <button onclick="switchTab('unmatched')" id="tab-unmatched" class="px-4 py-2 text-sm font-bold rounded-t-lg text-gray-600 hover:text-gray-800">No 2025 Match (<span class="tab-count">&hellip;</span>)</button>
        <button onclick="switchTab('excluded')" id="tab-excluded" class="px-4 py-2 text-sm font-bold rounded-t-lg text-gray-600 hover:text-gray-800">Excluded from Calculation (<span class="tab-count">&hellip;</span>)</button>
    </div>

    <div id="content-matched" class="tab-content p-4">
        <div class="overflow-x-auto">
            <table id="matchedTable" class="min-w-full text-left text-sm display nowrap" style="width:100%">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Ticket / PO</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Team</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">SKU</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Item</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Vendor</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Qty</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Current Unit</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right" title="Weighted average across ALL matched 2025 POs for this SKU + UOM (sum of PO amounts ÷ sum of quantities) - not just the &quot;Sample PO&quot; shown in 2025 Data.">2025 Average Price</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Savings</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">2025 Data</th>
                        <?php if ($can_correct): ?><th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr class="bg-gray-50">
                        <td colspan="8" class="p-3 text-right font-bold uppercase">Total Matched Cost Savings:</td>
                        <td id="matchedFooterSavings" class="p-3 text-right font-mono font-bold text-green-600">&hellip;</td>
                        <td id="matchedFooterSpend" class="p-3 text-xs text-gray-500" <?= $can_correct ? 'colspan="2"' : '' ?>></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <div id="content-review" class="tab-content p-4 hidden">
        <div class="mb-4 bg-orange-50 border border-orange-200 text-orange-800 rounded-lg p-3 text-sm">
            These lines have an exact SKU + UOM match in 2025, but the 2025 unit price is more than 10x higher or lower than the current price. This usually means the 2025 record was priced under a different structure (e.g. an annual/blanket contract amount rather than a true per-unit price), so the comparison isn't reliable. They are excluded from the totals and chart above until manually reviewed.
        </div>
        <div class="overflow-x-auto">
            <table id="reviewTable" class="min-w-full text-left text-sm display nowrap" style="width:100%">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Ticket / PO</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Team</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">SKU</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Item</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Vendor</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Qty</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Current Unit</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right" title="Weighted average across ALL matched 2025 POs for this SKU + UOM (sum of PO amounts ÷ sum of quantities) - not just the &quot;Sample PO&quot; shown in 2025 Data.">2025 Average Price</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Difference</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">2025 Data</th>
                        <?php if ($can_correct): ?><th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div id="content-unmatched" class="tab-content p-4 hidden">
        <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-sm">
            These PO lines are excluded from savings calculation until an exact 2025 SKU + UOM benchmark is available. SKU is still the item identity; UOM is guarded so the unit-price comparison stays accurate.
        </div>
        <div class="overflow-x-auto">
            <table id="unmatchedTable" class="min-w-full text-left text-sm display nowrap" style="width:100%">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Ticket / PO</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Team</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">SKU</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Item</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Vendor</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Current Unit</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Reason</th>
                        <?php if ($can_correct): ?><th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>

    <div id="content-excluded" class="tab-content p-4 hidden">
        <div class="mb-4 bg-orange-50 border border-orange-200 text-orange-800 rounded-lg p-3 text-sm">
            These PO lines have been manually excluded from the cost savings calculation by a Category Manager (e.g. items whose price is too volatile, like printing fees). They are not counted in the totals, chart, or other tabs above.
        </div>
        <div class="overflow-x-auto">
            <table id="excludedTable" class="min-w-full text-left text-sm display nowrap" style="width:100%">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Ticket / PO</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Team</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">SKU</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Item</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Vendor</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-right">Current Unit</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase">Reason</th>
                        <?php if ($can_correct): ?><th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($can_correct): ?>
<div id="correctionModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md max-h-[90vh] flex flex-col">
        <div class="p-6 pb-0 flex-none">
            <h3 class="text-lg font-bold text-gray-800 mb-1">Correct Details</h3>
            <p class="text-xs text-gray-500"><span id="cmTicket"></span> &middot; SKU <span id="cmSku"></span> &middot; UOM <span id="cmUom"></span></p>
        </div>

        <div class="px-6 py-4 overflow-y-auto flex-1">
        <div id="cmConfirmMatchWrap" class="hidden bg-green-50 border border-green-200 rounded-lg p-3 mb-4">
            <p class="text-xs text-gray-700 mb-2">This line was flagged for review because the 2025 average price (<span id="cmConfirmPrice" class="font-bold"></span>) differs from the current price by more than 10x. If you've checked the calculation and it's correct, confirm it to move this line into the Matched report.</p>
            <button type="button" id="cmConfirmMatch" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-3 rounded text-sm">Confirm Price is Correct &rarr; Move to Matched</button>
        </div>

        <div class="space-y-4">
            <div>
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cmReassignTeam">
                    Reassign Team
                </label>
                <select id="cmNewTeam" class="mt-2 w-full p-2 border rounded text-sm" disabled>
                    <?php foreach ($reassignable_teams as $rt): ?>
                        <option value="<?= h($rt) ?>"><?= h($rt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cmOverrideBenchmark">
                    Override 2025 Benchmark Unit Price
                </label>
                <input type="text" inputmode="numeric" id="cmNewBenchmark" class="mt-2 w-full p-2 border rounded text-sm" placeholder="Rp per unit, e.g. 160.000" disabled>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cmOverrideCurrent">
                    Override Current PO Unit Price
                </label>
                <p class="text-[11px] text-gray-500 mt-1">Use this if the current PO's price/qty was entered wrongly and the unit price shown is incorrect.</p>
                <input type="text" inputmode="numeric" id="cmNewCurrent" class="mt-2 w-full p-2 border rounded text-sm" placeholder="Rp per unit, e.g. 160.000" disabled>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cmOverrideUom2025">
                    Override UOM Used for 2025 Match
                </label>
                <p class="text-[11px] text-gray-500 mt-1">Use this if the same item was recorded under a different UOM in 2025 purchases (e.g. current PO uses "AU" but 2025 purchases recorded it as "PCS").</p>
                <input type="text" id="cmNewUom2025" maxlength="50" class="mt-2 w-full p-2 border rounded text-sm uppercase" placeholder="e.g. PCS" disabled>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cmOverrideUomCurrent">
                    Override Current PO UOM
                </label>
                <p class="text-[11px] text-gray-500 mt-1">Use this if the current PO's UOM was entered wrongly.</p>
                <input type="text" id="cmNewUomCurrent" maxlength="50" class="mt-2 w-full p-2 border rounded text-sm uppercase" placeholder="e.g. PCS" disabled>
            </div>

            <div>
                <label class="flex items-center gap-2 text-sm font-bold text-gray-700">
                    <input type="checkbox" id="cmExcludeRow">
                    Exclude from Cost Savings Calculation
                </label>
                <p class="text-[11px] text-gray-500 mt-1">Use this for items whose price is always changing and isn't a reliable like-for-like comparison (e.g. printing fees). This line will be removed from the savings totals, chart, and other tabs.</p>
            </div>

            <div>
                <label class="text-sm font-bold text-gray-700">Reason / Notes</label>
                <textarea id="cmReason" rows="2" maxlength="500" class="mt-1 w-full p-2 border rounded text-sm" placeholder="Optional"></textarea>
            </div>
        </div>

        <div id="cmError" class="hidden mt-3 text-sm text-red-600"></div>
        </div>

        <div class="flex gap-2 p-6 pt-4 border-t flex-none">
            <button type="button" id="cmCancel" class="flex-1 bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded text-sm">Cancel</button>
            <button type="button" id="cmSave" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm">Save Correction</button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    var csCanCorrect = <?= $can_correct ? 'true' : 'false' ?>;
    var csFilters = { team: '', year: '', quarter: '', month: '', level2: '' };
    var csSearchValue = ''; // shared across all four tabs' tables — see csEnsureTable()'s ajax.data
    var csTables = {};        // bucket -> DataTable, populated lazily
    var csActiveTab = 'matched';
    var csChart = null;
    var csPctChart = null;

    // Fixed categorical color order (validated via the data-viz skill's
    // palette validator — CVD-safe adjacent pairs in both modes; this app has
    // no dark mode, so only the light steps are used). Assigned by what each
    // series IS (Spend vs Benchmark), never by rank, so a filter that changes
    // which teams appear never repaints who's "series 1".
    var CS_COLOR_SPEND      = '#2a78d6'; // categorical slot 1 — blue
    var CS_COLOR_BENCHMARK  = '#eb6834'; // categorical slot 2 — orange
    var CS_COLOR_GOOD       = '#0ca30c'; // status: saved money
    var CS_COLOR_CRITICAL   = '#d03b3b'; // status: cost increase

    // --- formatting -------------------------------------------------------
    // Mirrors the page's old PHP rupiah() / pct() / number_format() output, so
    // nothing on screen changes shape now that rendering happens client-side.
    function csEsc(value) {
        return $('<div>').text(value === null || value === undefined ? '' : value).html();
    }
    function csInt(value) {
        return Math.round(Number(value) || 0).toLocaleString('id-ID');
    }
    function csRupiah(value) {
        return 'Rp ' + csInt(value);
    }
    function csDec2(value) {
        return (Number(value) || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function csPct(value) {
        return csDec2(value) + '%';
    }
    function formatRupiah(value) {
        var num = Math.round(Number(value) || 0);
        return num ? num.toLocaleString('id-ID') : '';
    }
    function parseRupiah(value) {
        var digits = String(value || '').replace(/[^0-9]/g, '');
        return digits === '' ? 0 : parseInt(digits, 10);
    }

    function csShowError(message) {
        $('#csError').text(message).removeClass('hidden');
    }
    function csHideError() {
        $('#csError').addClass('hidden').text('');
    }

    // --- shared cell renderers -------------------------------------------
    function csTicketCell(d) {
        var badge = d.excluded
            ? ' <span class="text-[10px] bg-orange-100 text-orange-700 px-1.5 py-0.5 rounded font-bold align-middle">EXCLUDED</span>'
            : (d.corrected
                ? ' <span class="text-[10px] bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded font-bold align-middle">CORRECTED</span>'
                : '');
        return csEsc(d.ticket) + badge +
               '<br><span class="text-xs font-normal text-gray-500">PO ' + csEsc(d.po) + '</span>';
    }

    function csTeamCell(d) {
        return '<span class="text-xs font-bold text-indigo-700 bg-indigo-50 px-2 py-1 rounded">' + csEsc(d.team) + '</span>';
    }

    // "2025 matched as X" only appears when a correction points the benchmark
    // lookup at a different UOM than the one displayed.
    function csUom2025Note(d) {
        if (!d.uom2025 || d.uom2025 === d.uom) return '';
        return ' • 2025 matched as ' + csEsc(d.uom2025);
    }

    function csItemCell(d, withQty) {
        var sub = withQty
            ? 'Qty: ' + csDec2(d.qty) + ' ' + csEsc(d.uom) + csUom2025Note(d)
            : 'UOM: ' + csEsc(d.uom) + (d.inclusive ? ' • Nett excludes PPN' : '') + csUom2025Note(d);
        return '<div class="item-desc font-semibold text-gray-800" title="' + csEsc(d.item) + '">' + csEsc(d.item) + '</div>' +
               '<div class="text-[11px] text-gray-500">' + sub + '</div>';
    }

    function csBenchmarkCell(d) {
        return '<div class="font-bold text-blue-700">' + csRupiah(d.benchmarkUnit) + '</div>' +
               '<div class="text-gray-600 mt-1"><span class="font-semibold">2025 Total:</span><br>' + csRupiah(d.histTotal) + '</div>' +
               '<div class="text-gray-600"><span class="font-semibold">÷ Qty:</span> ' + csInt(d.histQty) + '</div>';
    }

    function csHistoryCell(d) {
        var when = d.histLatestDate ? (' / ' + csEsc(csFormatDate(d.histLatestDate))) : '';
        return csInt(d.histPoCount) + ' PO(s), ' + csInt(d.histVendors) + ' vendor(s)<br>' +
               '<span class="text-gray-500" title="Reference only - a sample matching PO from 2025 purchases. The 2025 Unit price above is a weighted average across all PO(s) listed, not just this one.">' +
               'Sample PO: ' + csEsc(d.histLatestPo || '-') + when + '</span>';
    }

    // purchases_2025.tr_date_technical is stored as a US-format string
    // ("1/10/2025 22:53:58"); the page used PHP date('d M Y', strtotime(...)).
    function csFormatDate(raw) {
        var parsed = new Date(String(raw).replace(/-/g, '/'));
        if (isNaN(parsed.getTime())) return raw;
        var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return ('0' + parsed.getDate()).slice(-2) + ' ' + months[parsed.getMonth()] + ' ' + parsed.getFullYear();
    }

    function csActionCell(d) {
        if (!csCanCorrect) return '';
        return '<button type="button" class="btn-correct-row text-indigo-600 hover:text-indigo-800 focus:outline-none" title="Correct Details">' +
               '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg></button>';
    }

    // --- column definitions per tab --------------------------------------
    function csColumns(bucket) {
        var cols;

        if (bucket === 'matched' || bucket === 'review') {
            cols = [
                { data: null, className: 'p-3 font-mono font-bold text-gray-800', render: csTicketCell },
                { data: null, className: 'p-3', render: csTeamCell },
                { data: 'sku', className: 'p-3 font-mono font-bold', render: csEsc },
                { data: null, className: 'p-3 min-w-[280px]', render: function (d) { return csItemCell(d, false); } },
                { data: 'vendor', className: 'p-3 text-gray-700', render: csEsc },
                { data: 'qty', className: 'p-3 text-right font-mono', render: csDec2 },
                { data: 'currentUnit', className: 'p-3 text-right font-mono font-bold text-gray-800', render: csRupiah },
                { data: null, className: 'p-3 text-right font-mono text-xs', render: csBenchmarkCell },
                (bucket === 'matched')
                    ? { data: 'savings', className: 'p-3 text-right font-mono font-bold',
                        render: function (d, type, row) {
                            if (type !== 'display') return d;
                            var cls = d >= 0 ? 'text-green-600' : 'text-red-600';
                            return '<span class="' + cls + '">' + csRupiah(d) +
                                   '<br><span class="text-[11px] font-semibold">' + csPct(row.savingsPct) + '</span></span>';
                        } }
                    : { data: 'priceRatio', className: 'p-3 text-right font-mono font-bold text-orange-700',
                        render: function (d, type) {
                            if (type !== 'display') return d;
                            var r = Number(d) || 0;
                            return (r >= 1)
                                ? csDec2(r) + 'x higher'
                                : csDec2(r > 0 ? 1 / r : 0) + 'x lower';
                        } },
                { data: null, className: 'p-3 text-xs text-gray-600', render: csHistoryCell }
            ];
        } else {
            cols = [
                { data: null, className: 'p-3 font-mono font-bold text-gray-800', render: csTicketCell },
                { data: null, className: 'p-3', render: csTeamCell },
                { data: 'sku', className: 'p-3 font-mono font-bold', render: csEsc },
                { data: null, className: 'p-3 min-w-[280px]', render: function (d) { return csItemCell(d, true); } },
                { data: 'vendor', className: 'p-3 text-gray-700', render: csEsc },
                { data: 'currentUnit', className: 'p-3 text-right font-mono font-bold text-gray-800', render: csRupiah },
                (bucket === 'excluded')
                    ? { data: 'reason', className: 'p-3 text-sm text-gray-600',
                        render: function (d) { return csEsc(d || '-'); } }
                    : { data: 'unmatchedReason', className: 'p-3 text-sm text-amber-700 font-semibold', render: csEsc }
            ];
        }

        // The Action column exists only for Category Managers, and the <thead>
        // above follows the same flag. The two MUST stay in step: DataTables
        // throws if the column count does not match the header.
        if (csCanCorrect) {
            cols.push({ data: null, className: 'p-3 text-center', orderable: false, render: csActionCell });
        }
        return cols;
    }

    // Default sort per tab, matching what the page shipped: Matched sorted by
    // savings descending, the other three by Ticket / PO ascending (DataTables'
    // own default, which is what those tables used).
    function csDefaultOrder(bucket) {
        return (bucket === 'matched') ? [[8, 'desc']] : [[0, 'asc']];
    }

    // --- lazy table init --------------------------------------------------
    // Only the visible tab is loaded up front. Initialising all four would fire
    // four full queries on every page load to render three tabs nobody looked at.
    function csEnsureTable(bucket) {
        if (csTables[bucket]) return csTables[bucket];

        var tableIds = {
            matched: '#matchedTable', review: '#reviewTable',
            unmatched: '#unmatchedTable', excluded: '#excludedTable'
        };

        var table = $(tableIds[bucket]).DataTable({
            serverSide: true,
            processing: true,
            // No built-in search/length boxes (see the `dom` option below) —
            // the one search box lives in the filter bar and applies to
            // whichever tab is active; its value is injected here rather than
            // through DataTables' own (now-hidden) search UI.
            dom: 'rtip',
            ajax: {
                url: 'ajax_cost_savings_table.php',
                type: 'POST',
                data: function (d) {
                    d.bucket = bucket;
                    d.gf = JSON.stringify(csFilters);
                    d.search.value = csSearchValue;
                },
                error: function () {
                    $('.viz-table-loader').fadeOut(200);
                    csShowError('Gagal memuat tabel Cost Savings. Coba muat ulang halaman.');
                }
            },
            pageLength: 25,
            lengthChange: false,
            // With serverSide, autoWidth pins measured widths as inline styles
            // and can write width:0px onto every header (as it did on Cost
            // Avoidance). Let the browser lay the table out instead.
            autoWidth: false,
            scrollX: true,
            order: csDefaultOrder(bucket),
            language: { emptyTable: 'No rows in this tab.' },
            createdRow: function (row) { $(row).addClass('border-b hover:bg-gray-50'); },
            initComplete: function () { $('.viz-table-loader').fadeOut(200); },
            drawCallback: function (settings) {
                var json = settings.json || {};
                if (json.error) { csShowError(json.error); } else { csHideError(); }
                if (json.recordsFiltered !== undefined) {
                    $('#tab-' + bucket + ' .tab-count').text(csInt(json.recordsFiltered));
                }
            },
            columns: csColumns(bucket)
        });

        csTables[bucket] = table;
        return table;
    }

    function csReloadTables() {
        Object.keys(csTables).forEach(function (bucket) {
            csTables[bucket].ajax.reload(null, false);
        });
    }

    // --- summary (KPI cards, chart, team/taxonomy options, tab counts) ----
    // The cards and chart loaders re-appear on every call, not just the first
    // page load — the tables already show DataTables' own "Processing..."
    // indicator (processing: true) on every filter change, but the cards and
    // chart had no equivalent feedback, which made them look frozen/slow
    // whenever a filter change made this request take a moment.
    function csLoadSummary() {
        $('.viz-cards-loader, .viz-chart-loader').stop(true, true).fadeIn(120);

        return $.post('ajax_cost_savings_summary.php', { gf: JSON.stringify(csFilters) })
            .done(function (resp) {
                if (!resp || resp.error) {
                    csShowError((resp && resp.error) || 'Gagal memuat ringkasan Cost Savings.');
                    $('.viz-cards-loader, .viz-chart-loader').fadeOut(200);
                    return;
                }
                csHideError();
                $('#csNo2025').toggleClass('hidden', resp.has2025 !== false);

                // Team -> Taxonomy drill-down: both option lists are scoped to
                // the current filters server-side (cs_breakdown_matrix() /
                // cs_matrix_level2_options()), so rebuilding them here keeps
                // Team and Taxonomy Level 2 always showing only values that
                // actually exist under the rest of the filter bar. If the
                // currently selected value no longer appears (e.g. switching
                // Team to one with no "Unmapped" lines), it's reset to
                // "All ..." and the summary/tables are re-fetched once with
                // the corrected filters.
                var teamOk = csRebuildOptions($('#csFilterTeam'), resp.teams || [], 'All Teams', csFilters.team);
                var level2Ok = csRebuildOptions($('#csFilterLevel2'), resp.level2Options || [], 'All Categories', csFilters.level2);

                if (!teamOk || !level2Ok) {
                    csFilters.team = $('#csFilterTeam').val();
                    csFilters.level2 = $('#csFilterLevel2').val();
                    csReloadTables();
                    return csLoadSummary();
                }

                csRenderCards(resp.cards);
                $('.viz-cards-loader').fadeOut(200);
                csRenderTabCounts(resp.counts);
                csRenderChart(resp.chart);
            })
            .fail(function () {
                csShowError('Gagal memuat ringkasan Cost Savings. Coba muat ulang halaman.');
                $('.viz-cards-loader, .viz-chart-loader').fadeOut(200);
            });
    }

    function csRenderCards(c) {
        $('#kpiSpend').text(csRupiah(c.totalCurrentSpend));
        $('#kpiBenchmark').text(csRupiah(c.total2025Benchmark));

        // Shows the true signed total — a team that genuinely spent MORE than
        // its 2025 benchmark (e.g. TIGS) reads as a real negative number here,
        // matching the chart (see csRenderChart()) and the Matched-tab footer
        // below, which was never floored. This card used to floor negative
        // totals to "Rp 0 / no net savings", hiding a real cost increase
        // behind a neutral message.
        $('#kpiSavings')
            .text(csRupiah(c.totalSavings))
            .toggleClass('text-green-600', c.totalSavings >= 0)
            .toggleClass('text-red-600', c.totalSavings < 0);
        $('#kpiSavingsNote').text(c.totalSavings >= 0
            ? csPct(c.overallSavingsPct) + ' lower than 2025 benchmark prices'
            : csPct(Math.abs(c.overallSavingsPct)) + ' higher than 2025 benchmark prices');

        $('#kpiCoverage').text(csInt(c.matchedCount) + ' / ' + csInt(c.eligibleCount));
        $('#kpiCoverageNote').text(csPct(c.coveragePct) +
            ' of eligible PO lines have a price-comparable 2025 match (' +
            csInt(c.reviewCount) + ' more flagged for price review)');

        $('#kpiMovement').text(csInt(c.savingLines) + ' cheaper / ' + csInt(c.increaseLines) + ' more expensive');

        $('#matchedFooterSavings')
            .text(csRupiah(c.totalSavings))
            .toggleClass('text-green-600', c.totalSavings >= 0)
            .toggleClass('text-red-600', c.totalSavings < 0);
        $('#matchedFooterSpend').text('Matched spend: ' + csRupiah(c.matchedCurrentSpend));
    }

    // Rebuilds a filter <select>'s options from a server-scoped value list,
    // keeping the currently selected value if it's still offered. Returns
    // false when the previous selection no longer appears in the new list
    // (the caller resets that filter to "All ..." and re-fetches).
    function csRebuildOptions($select, values, allLabel, currentValue) {
        var html = '<option value="">' + csEsc(allLabel) + '</option>';
        values.forEach(function (v) {
            html += '<option value="' + csEsc(v) + '">' + csEsc(v) + '</option>';
        });
        $select.html(html);

        var stillValid = currentValue === '' || values.indexOf(currentValue) !== -1;
        $select.val(stillValid ? currentValue : '');
        return stillValid;
    }

    function csRenderTabCounts(counts) {
        // Only for tabs that have not loaded yet — an initialised table reports
        // its own recordsFiltered in drawCallback, which also reflects its
        // search box and is therefore the more specific number.
        ['matched', 'review', 'unmatched', 'excluded'].forEach(function (bucket) {
            if (!csTables[bucket]) {
                $('#tab-' + bucket + ' .tab-count').text(csInt(counts[bucket]));
            }
        });
    }

    // Money (Spend/Benchmark/Savings) and Savings % are two DIFFERENT units on
    // two different scales — combining them into one chart with a second axis
    // (as this used to) is the classic dual-axis mistake: the eye can't tell
    // which scale a bar belongs to, and either axis can be silently stretched
    // to make a series look better/worse than it is. They're rendered here as
    // two separate single-axis charts instead.
    function csRenderChart(chart) {
        // Team spend spans several orders of magnitude (CSGSM vs. PKP can be
        // ~12,000x apart locally), which made every team but the biggest
        // invisible on a linear axis. A logarithmic axis fixes that, but
        // Chart.js's log scale can't plot zero or negative values (a team can
        // have benchmark=0 with no 2025 match at all, or negative savings when
        // it spent MORE than the benchmark). "Cost Savings" is plotted here by
        // magnitude — color still carries the true sign (green/red) — with the
        // real signed amount kept in rawValues for the tooltip, so the number
        // shown to the user is never altered, only the bar's height.
        var savingsAbs = chart.savings.map(function (v) { return Math.abs(v); });

        var config = {
            type: 'bar',
            data: {
                labels: chart.teams,
                datasets: [
                    {
                        label: 'Current Nett Spend',
                        data: chart.spend,
                        backgroundColor: CS_COLOR_SPEND,
                        borderRadius: 4,
                        borderSkipped: false,
                        categoryPercentage: 0.7,
                        barPercentage: 0.85
                    },
                    {
                        label: '2025 Benchmark',
                        data: chart.benchmark,
                        backgroundColor: CS_COLOR_BENCHMARK,
                        borderRadius: 4,
                        borderSkipped: false,
                        categoryPercentage: 0.7,
                        barPercentage: 0.85
                    },
                    {
                        label: 'Cost Savings',
                        data: savingsAbs,
                        rawValues: chart.savings,
                        backgroundColor: chart.savings.map(function (v) { return v >= 0 ? CS_COLOR_GOOD : CS_COLOR_CRITICAL; }),
                        borderRadius: 4,
                        borderSkipped: false,
                        categoryPercentage: 0.7,
                        barPercentage: 0.85
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        type: 'logarithmic',
                        position: 'left',
                        title: { display: true, text: 'Rupiah (Rp), log scale' },
                        grid: { color: '#e1e0d9' },
                        // Chart.js's default log-scale ticks include in-between
                        // "nice" values (1, 3, 7 x 10^n as well as 1, 10, 100...),
                        // which reads as uneven/random spacing. Forcing ticks to
                        // ONLY the power-of-ten positions gives fixed, evenly
                        // log-spaced labels (1M, 10M, 100M, 1B, ...) instead.
                        afterBuildTicks: function (scale) {
                            var startPow = Math.floor(Math.log10(scale.min || 1));
                            var endPow = Math.ceil(Math.log10(scale.max || 10));
                            var ticks = [];
                            for (var p = startPow; p <= endPow; p++) {
                                ticks.push({ value: Math.pow(10, p) });
                            }
                            scale.ticks = ticks;
                        },
                        ticks: {
                            color: '#52514e',
                            callback: function (value) {
                                if (Math.abs(value) >= 1000000000) return (value / 1000000000) + 'B';
                                if (Math.abs(value) >= 1000000) return (value / 1000000) + 'M';
                                if (Math.abs(value) >= 1000) return (value / 1000) + 'K';
                                return value;
                            }
                        }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#52514e', font: { weight: 'bold' } }
                    }
                },
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'start',
                        labels: { color: '#0b0b0b', boxWidth: 12, boxHeight: 12, usePointStyle: true, pointStyle: 'rect' }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                var label = context.dataset.label || '';
                                // "Cost Savings" plots |value| on the log axis;
                                // show the true signed amount here instead of
                                // the magnitude used for the bar's height.
                                var raw = context.dataset.rawValues ? context.dataset.rawValues[context.dataIndex] : context.raw;
                                return label + ': Rp ' + Number(raw).toLocaleString('id-ID');
                            }
                        }
                    }
                }
            }
        };

        if (csChart) { csChart.destroy(); }
        csChart = new Chart(document.getElementById('savingsChart'), config);
        $('#savingsChart').closest('.relative').find('.viz-chart-loader').fadeOut(200);

        csRenderPctChart(chart);
    }

    // Savings % gets its own single-axis chart: a 0-baseline diverging bar
    // (green = saved money, red = spent more than the 2025 benchmark), with
    // the value labeled directly on each bar since it's the headline number —
    // a single series needs no legend box, the chart's own heading names it.
    function csRenderPctChart(chart) {
        var pctLabelsPlugin = {
            id: 'csPctDirectLabels',
            afterDatasetsDraw: function (c) {
                var ctx = c.ctx;
                var meta = c.getDatasetMeta(0);
                ctx.save();
                ctx.font = 'bold 11px system-ui, -apple-system, "Segoe UI", sans-serif';
                ctx.textAlign = 'center';
                meta.data.forEach(function (bar, i) {
                    var value = c.data.datasets[0].data[i];
                    var label = (value >= 0 ? '+' : '') + csDec2(value) + '%';
                    ctx.fillStyle = value >= 0 ? CS_COLOR_GOOD : CS_COLOR_CRITICAL;
                    ctx.fillText(label, bar.x, value >= 0 ? bar.y - 6 : bar.y + 14);
                });
                ctx.restore();
            }
        };

        var config = {
            type: 'bar',
            data: {
                labels: chart.teams,
                datasets: [{
                    label: 'Savings %',
                    data: chart.pct,
                    backgroundColor: chart.pct.map(function (v) { return v >= 0 ? CS_COLOR_GOOD : CS_COLOR_CRITICAL; }),
                    borderRadius: 4,
                    borderSkipped: false,
                    categoryPercentage: 0.6,
                    barPercentage: 0.85
                }]
            },
            plugins: [pctLabelsPlugin],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 16, bottom: 16 } },
                scales: {
                    y: {
                        type: 'linear',
                        grid: { color: '#e1e0d9' },
                        ticks: { color: '#52514e', callback: function (value) { return value + '%'; } }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { color: '#52514e', font: { weight: 'bold' } }
                    }
                },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (context) { return 'Savings: ' + csDec2(context.raw) + '%'; }
                        }
                    }
                }
            }
        };

        if (csPctChart) { csPctChart.destroy(); }
        csPctChart = new Chart(document.getElementById('savingsPctChart'), config);
        $('#savingsPctChart').closest('.relative').find('.viz-chart-loader').fadeOut(200);
    }

    // --- bootstrap --------------------------------------------------------
    $(document).ready(function () {
        $.fn.dataTable.ext.errMode = 'none';

        csEnsureTable('matched');
        csLoadSummary();

        // Quarter and Month both filter pr_date at different granularities;
        // keep them mutually exclusive so a request never combines them.
        $('#csFilterQuarter').on('change', function () {
            if ($(this).val() !== '') $('#csFilterMonth').val('');
        });
        $('#csFilterMonth').on('change', function () {
            if ($(this).val() !== '') $('#csFilterQuarter').val('');
        });

        $('#csFilterYear, #csFilterQuarter, #csFilterMonth, #csFilterTeam, #csFilterLevel2').on('change', function () {
            csFilters.year = $('#csFilterYear').val();
            csFilters.quarter = $('#csFilterQuarter').val();
            csFilters.month = $('#csFilterMonth').val();
            csFilters.team = $('#csFilterTeam').val();
            csFilters.level2 = $('#csFilterLevel2').val();
            csReloadTables();
            csLoadSummary();
        });

        // Moved out of each DataTable's own default search box (now hidden via
        // `dom: 'rtip'`) into the shared filter bar, so one box searches
        // whichever tab is active instead of each of the four tabs having its
        // own. Debounced since it fires a server request per keystroke
        // otherwise; only the tables react to it (matches the pre-existing
        // per-table search behavior — it never affected the KPI cards/chart).
        var csSearchTimer = null;
        $('#csGlobalSearch').on('input', function () {
            var val = $(this).val();
            clearTimeout(csSearchTimer);
            csSearchTimer = setTimeout(function () {
                csSearchValue = val;
                csReloadTables();
            }, 300);
        });

        $('body').on('click', '.item-desc', function () {
            $(this).toggleClass('expanded');
        });

        if (!csCanCorrect) return;

        $('body').on('click', '.btn-correct-row', function () {
            var $tr = $(this).closest('tr');
            var table = csTables[csActiveTab];
            var d = table.row($tr).data();
            if (!d) return;

            $('#correctionModal').data('context', {
                ticket_no: d.ticket,
                ticket_line_item: d.line,
                po_number: String(d.po),
                sku: d.sku,
                uom: d.uom,
                old_team: String(d.team),
                old_benchmark_unit_price: d.benchmarkUnit,
                old_current_unit_price: d.currentUnit,
                old_uom: String(d.uom),
                old_uom_2025: String(d.uom2025)
            });

            $('#cmTicket').text(d.ticket + ' / PO ' + d.po);
            $('#cmSku').text(d.sku);
            $('#cmUom').text(d.uom);

            $('#cmReassignTeam, #cmOverrideBenchmark, #cmOverrideCurrent, #cmOverrideUom2025, #cmOverrideUomCurrent, #cmExcludeRow').prop('checked', false);
            $('#cmNewTeam').val(String(d.team)).prop('disabled', true);
            $('#cmNewBenchmark').val(formatRupiah(d.benchmarkUnit)).prop('disabled', true);
            $('#cmNewCurrent').val(formatRupiah(d.currentUnit)).prop('disabled', true);
            $('#cmNewUom2025').val(String(d.uom2025 || '')).prop('disabled', true);
            $('#cmNewUomCurrent').val(String(d.uom || '')).prop('disabled', true);
            $('#cmReason').val('');
            $('#cmError').addClass('hidden').text('');
            $('#cmSave').prop('disabled', false).text('Save Correction');

            if (d.needsReview && d.benchmarkUnit) {
                $('#cmConfirmPrice').text(csRupiah(d.benchmarkUnit));
                $('#cmConfirmMatchWrap').removeClass('hidden');
            } else {
                $('#cmConfirmMatchWrap').addClass('hidden');
            }

            $('#correctionModal').removeClass('hidden');
        });

        $('#cmConfirmMatch').on('click', function () {
            var ctx = $('#correctionModal').data('context');
            if (!ctx || !ctx.old_benchmark_unit_price) return;

            $('#cmOverrideBenchmark').prop('checked', true);
            $('#cmNewBenchmark').val(formatRupiah(ctx.old_benchmark_unit_price)).prop('disabled', false);
            if (!$('#cmReason').val().trim()) {
                $('#cmReason').val('Confirmed: 2025 average price calculation verified as correct.');
            }

            $('#cmSave').trigger('click');
        });

        $('#cmReassignTeam').on('change', function () { $('#cmNewTeam').prop('disabled', !this.checked); });
        $('#cmOverrideBenchmark').on('change', function () { $('#cmNewBenchmark').prop('disabled', !this.checked); });
        $('#cmOverrideCurrent').on('change', function () { $('#cmNewCurrent').prop('disabled', !this.checked); });
        $('#cmOverrideUom2025').on('change', function () { $('#cmNewUom2025').prop('disabled', !this.checked); });
        $('#cmOverrideUomCurrent').on('change', function () { $('#cmNewUomCurrent').prop('disabled', !this.checked); });

        $('#cmNewBenchmark, #cmNewCurrent').on('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        $('#cmNewBenchmark, #cmNewCurrent').on('blur', function () {
            this.value = formatRupiah(parseRupiah(this.value));
        });

        $('#cmCancel').on('click', function () { $('#correctionModal').addClass('hidden'); });

        $('#cmSave').on('click', function () {
            var ctx = $('#correctionModal').data('context');
            var wantTeam = $('#cmReassignTeam').is(':checked');
            var wantBenchmark = $('#cmOverrideBenchmark').is(':checked');
            var wantCurrent = $('#cmOverrideCurrent').is(':checked');
            var wantUom2025 = $('#cmOverrideUom2025').is(':checked');
            var wantUomCurrent = $('#cmOverrideUomCurrent').is(':checked');
            var wantExclude = $('#cmExcludeRow').is(':checked');

            if (!wantTeam && !wantBenchmark && !wantCurrent && !wantUom2025 && !wantUomCurrent && !wantExclude) {
                $('#cmError').removeClass('hidden').text('Select at least one correction to apply.');
                return;
            }
            if (wantBenchmark && parseRupiah($('#cmNewBenchmark').val()) <= 0) {
                $('#cmError').removeClass('hidden').text('Enter a valid positive benchmark unit price.');
                return;
            }
            if (wantCurrent && parseRupiah($('#cmNewCurrent').val()) <= 0) {
                $('#cmError').removeClass('hidden').text('Enter a valid positive current PO unit price.');
                return;
            }
            if (wantUom2025 && !$('#cmNewUom2025').val().trim()) {
                $('#cmError').removeClass('hidden').text('Enter a UOM to use for the 2025 match.');
                return;
            }
            if (wantUomCurrent && !$('#cmNewUomCurrent').val().trim()) {
                $('#cmError').removeClass('hidden').text('Enter a UOM for the current PO.');
                return;
            }

            var types = [];
            if (wantTeam) types.push('TEAM_REASSIGNMENT');
            if (wantBenchmark) types.push('BENCHMARK_OVERRIDE');
            if (wantCurrent) types.push('CURRENT_PRICE_OVERRIDE');
            if (wantUom2025) types.push('UOM_OVERRIDE_2025');
            if (wantUomCurrent) types.push('UOM_OVERRIDE_CURRENT');
            if (wantExclude) types.push('EXCLUDE_FROM_CALCULATION');

            var payload = {
                ticket_no: ctx.ticket_no,
                ticket_line_item: ctx.ticket_line_item,
                po_number: ctx.po_number,
                sku: ctx.sku,
                uom: ctx.uom,
                correction_type: types.join(','),
                old_team: ctx.old_team,
                new_team: wantTeam ? $('#cmNewTeam').val() : null,
                old_benchmark_unit_price: ctx.old_benchmark_unit_price,
                new_benchmark_unit_price: wantBenchmark ? parseRupiah($('#cmNewBenchmark').val()) : null,
                old_current_unit_price: ctx.old_current_unit_price,
                new_current_unit_price: wantCurrent ? parseRupiah($('#cmNewCurrent').val()) : null,
                old_uom: ctx.old_uom,
                new_uom_2025: wantUom2025 ? $('#cmNewUom2025').val().trim().toUpperCase() : null,
                new_uom_current: wantUomCurrent ? $('#cmNewUomCurrent').val().trim().toUpperCase() : null,
                reason: $('#cmReason').val()
            };

            $('#cmError').addClass('hidden').text('');
            $('#cmSave').prop('disabled', true).text('Saving...');

            $.ajax({
                url: 'ajax_cost_savings_correct.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json'
            }).done(function (resp) {
                if (resp.status === 'success') {
                    // A correction can move the line into a different tab and
                    // shifts every total, so refresh the loaded tables and the
                    // summary — the page used to location.reload() here, which
                    // re-ran the entire report.
                    $('#correctionModal').addClass('hidden');
                    csReloadTables();
                    csLoadSummary();
                } else {
                    $('#cmError').removeClass('hidden').text(resp.message || 'Failed to save correction.');
                    $('#cmSave').prop('disabled', false).text('Save Correction');
                }
            }).fail(function (xhr) {
                var resp = xhr.responseJSON || {};
                $('#cmError').removeClass('hidden').text(resp.message || 'Failed to save correction.');
                $('#cmSave').prop('disabled', false).text('Save Correction');
            });
        });
    });

    function switchTab(tabId) {
        csActiveTab = tabId;
        $('.tab-content').addClass('hidden');
        $('#content-' + tabId).removeClass('hidden');
        $('[id^="tab-"]').removeClass('bg-white text-indigo-700 shadow-sm').addClass('text-gray-600 hover:text-gray-800');
        $('#tab-' + tabId).addClass('bg-white text-indigo-700 shadow-sm').removeClass('text-gray-600 hover:text-gray-800');

        // First visit to a tab creates its table; later visits only need the
        // column widths recalculated, since it was laid out while hidden.
        var existed = !!csTables[tabId];
        var table = csEnsureTable(tabId);
        if (existed) {
            table.columns.adjust();
        }
    }
</script>

<?php require_once '../includes/footer.php'; ?>
