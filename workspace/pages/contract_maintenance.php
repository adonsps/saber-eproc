<?php
// pages/contract_maintenance.php
// Contract Maintenance dashboard: at-a-glance visibility over the
// Facility/Property team's service & maintenance contracts (gas supply, HVAC,
// fire fighting, lift/escalator maintenance, cleaning, chemical treatment,
// etc.), replacing a manual spreadsheet the team used to eyeball for expiring
// contracts (some cells were even manually pink-highlighted for that).
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

$rows = $pdo->query(
    "SELECT * FROM contract_maintenance WHERE is_deleted = 0 ORDER BY output_end_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// --- Aggregate for the dashboard, server-side (small dataset, no need for a
// separate ajax chart-data endpoint) -----------------------------------------
$status_counts = ['expired' => 0, 'expiring_90' => 0, 'expiring_180' => 0, 'active' => 0, 'unknown' => 0];
$bu_counts = [];
$category_counts = [];
$type_counts = [];
$expiring_soon = [];

foreach ($rows as $r) {
    $status = contract_maintenance_status($r['output_end_date'], $today);
    $status_counts[$status['key']] = ($status_counts[$status['key']] ?? 0) + 1;

    // business_unit can hold several comma-separated values now (multi-select
    // in the Add/Edit modal) -- count the contract once per BU it's tagged
    // with, rather than lumping every combination into its own chart bucket.
    $bus = contract_maintenance_split_multi($r['business_unit']) ?: ['Unassigned'];
    foreach ($bus as $bu) {
        $bu_counts[$bu] = ($bu_counts[$bu] ?? 0) + 1;
    }

    $cat = $r['item_category'] ?: 'Unassigned';
    $category_counts[$cat] = ($category_counts[$cat] ?? 0) + 1;

    $type = $r['contract_type'] ?: 'Unassigned';
    $type_counts[$type] = ($type_counts[$type] ?? 0) + 1;

    if (in_array($status['key'], ['expired', 'expiring_90'], true) && $status['soonest']) {
        $expiring_soon[] = [
            'id'            => $r['id'],
            'item_category' => $r['item_category'],
            'description'   => $r['description'],
            'supplier'      => $r['supplier'],
            'business_unit' => $r['business_unit'],
            'site_area'     => $r['site_area'],
            'soonest'       => $status['soonest'],
            'status'        => $status,
        ];
    }
}
usort($expiring_soon, fn($a, $b) => strcmp($a['soonest'], $b['soonest']));

$can_shoot_reminder = (($_SESSION['role'] ?? '') === 'Category Manager');

arsort($category_counts);

$status_labels = ['Expired', '90 Days', '180 Days', 'Active', 'No Date'];
$status_data   = [
    $status_counts['expired'],
    $status_counts['expiring_90'],
    $status_counts['expiring_180'],
    $status_counts['active'],
    $status_counts['unknown'],
];
$status_colors = ['#dc2626', '#f59e0b', '#facc15', '#16a34a', '#94a3b8'];
?>

<div class="max-w-full">

    <!-- Header -->
    <div class="flex flex-col md:flex-row md:justify-between md:items-end gap-4 mb-6 border-b pb-4">
        <div class="min-w-0">
            <h2 class="text-2xl font-bold text-gray-800">Contract Maintenance</h2>
            <p class="text-xs text-gray-500 mt-1">Service & maintenance contracts across Property, Retail, F&amp;B and KLG.</p>
        </div>
        <div class="flex items-center gap-3 flex-shrink-0">
            <a href="contract_maintenance_data.php"
                class="h-10 box-border inline-flex items-center gap-2 px-4 rounded-lg border border-indigo-600 bg-indigo-600 text-white font-bold text-sm shadow-sm hover:bg-indigo-700 hover:border-indigo-700 transition whitespace-nowrap">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                Manage Data
            </a>
        </div>
    </div>

    <?php if (empty($rows)): ?>
    <p class="text-sm text-gray-500 italic py-8 text-center">No contracts yet. Use <strong>Manage Data</strong> to add one.</p>
    <?php else: ?>

    <!-- Summary tiles -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-slate-800"><?= count($rows) ?></div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-1">Total Contracts</div>
        </div>
        <div class="bg-white border border-red-200 rounded-xl p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-red-600"><?= $status_counts['expired'] ?></div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-1">Expired</div>
        </div>
        <div class="bg-white border border-amber-200 rounded-xl p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-amber-600"><?= $status_counts['expiring_90'] ?></div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-1">Expiring ≤ 90d</div>
        </div>
        <div class="bg-white border border-yellow-200 rounded-xl p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-yellow-600"><?= $status_counts['expiring_180'] ?></div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-1">Expiring ≤ 180d</div>
        </div>
        <div class="bg-white border border-emerald-200 rounded-xl p-4 text-center shadow-sm">
            <div class="text-2xl font-bold text-emerald-600"><?= $status_counts['active'] ?></div>
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mt-1">Active</div>
        </div>
    </div>

    <!-- Charts -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
            <h3 class="text-sm font-bold text-slate-700 mb-3">Expiry Status</h3>
            <div class="relative h-64 w-full">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
            <h3 class="text-sm font-bold text-slate-700 mb-3">By Business Unit</h3>
            <div class="relative h-64 w-full">
                <canvas id="buChart"></canvas>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
            <h3 class="text-sm font-bold text-slate-700 mb-3">By Category</h3>
            <div class="relative h-64 w-full">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl shadow-sm p-4">
            <h3 class="text-sm font-bold text-slate-700 mb-3">By Contract Type</h3>
            <div class="relative h-64 w-full">
                <canvas id="typeChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Expiring soon list -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm">
        <div class="px-4 py-3 border-b border-slate-200">
            <!-- Title and button are the only things on this row, so the
                 button always stays top-right instead of wrapping under a
                 long hint sentence -- that text has its own row below. -->
            <div class="flex items-center justify-between gap-3">
                <h3 class="text-sm font-bold text-slate-700 shrink-0">Expired or Expiring Within 90 Days</h3>
                <?php if ($can_shoot_reminder): ?>
                <!-- Kept right next to this table (not the page header) since it
                     acts on rows selected here -- disabled until at least one is
                     ctrl/shift-selected. See includes/contract_maintenance_reminder.php
                     for the send-side logic. -->
                <button type="button" id="shootReminderBtn" disabled
                    class="h-9 box-border inline-flex items-center gap-1.5 px-3 rounded-lg border border-emerald-300 bg-emerald-50 text-emerald-800 font-bold text-xs shadow-sm hover:bg-emerald-100 transition whitespace-nowrap shrink-0 disabled:opacity-40 disabled:cursor-not-allowed disabled:hover:bg-emerald-50"
                    title="Select rows below (ctrl/cmd-click, shift-click for a range) to enable">
                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    <span id="shootReminderLabel">Shoot Reminder</span>
                </button>
                <?php endif; ?>
            </div>
            <?php if ($can_shoot_reminder && !empty($expiring_soon)): ?>
            <p class="text-xs text-slate-400 mt-1">Click a row to select it, ctrl/cmd-click to add more, shift-click to select a range.</p>
            <?php endif; ?>
        </div>
        <?php if (empty($expiring_soon)): ?>
        <p class="text-sm text-gray-500 italic py-8 text-center">Nothing expired or expiring soon. 🎉</p>
        <?php else: ?>
        <table id="expiringSoonTable" class="w-full text-sm text-left">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Category</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Description</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Supplier</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">BU</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Site</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Expiry</th>
                    <th class="px-3 py-2 text-[10px] font-bold text-slate-500 uppercase tracking-wider text-center">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($expiring_soon as $row): ?>
                <tr data-id="<?= (int)$row['id'] ?>"
                    class="js-selectable-row border-b border-slate-100 cursor-pointer select-none <?= $row['status']['key'] === 'expired' ? 'bg-red-50' : 'bg-amber-50' ?>">
                    <td class="px-3 py-2"><?= htmlspecialchars($row['item_category'] ?? '') ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($row['description'] ?? '') ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($row['supplier'] ?? '') ?></td>
                    <td class="px-3 py-2"><?= htmlspecialchars($row['business_unit'] ?? '') ?></td>
                    <td class="px-3 py-2 text-slate-600"><?= htmlspecialchars($row['site_area'] ?? '') ?></td>
                    <td class="px-3 py-2 font-mono text-xs"><?= htmlspecialchars($row['soonest'] ?? '') ?></td>
                    <td class="px-3 py-2 text-center">
                        <span class="px-2 inline-flex text-[11px] leading-5 font-semibold rounded-full <?= $row['status']['key'] === 'expired' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-800' ?>">
                            <?= htmlspecialchars($row['status']['label']) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <?php endif; ?>
</div>

<?php if ($can_shoot_reminder): ?>
<!-- ═══════════════════════ SHOOT REMINDER CONFIRM ═══════════════════════ -->
<div id="shootModal" class="hidden fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-md flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-start gap-3">
            <div class="flex-shrink-0 w-10 h-10 rounded-full bg-emerald-50 border border-emerald-200 flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
            </div>
            <div class="min-w-0">
                <h3 class="text-base font-bold text-slate-800">Send reminder for <span id="shootCount">0</span> contract(s)?</h3>
                <p class="text-xs text-slate-500 mt-0.5">This posts a message to the Facility/Property WhatsApp group.</p>
            </div>
        </div>
        <div class="px-6 py-4">
            <p class="text-sm text-slate-600">
                One WhatsApp message will be sent listing the selected contracts, reminding
                the officers responsible to extend or renegotiate them.
            </p>
        </div>
        <div class="px-6 py-4 bg-slate-50 rounded-b-xl flex justify-end gap-3 border-t border-slate-100">
            <button id="shootCancelBtn" type="button" class="px-4 py-2 text-sm font-bold text-slate-600 border border-slate-200 rounded-lg hover:bg-slate-100 transition">Cancel</button>
            <button id="shootConfirmBtn" type="button" class="px-5 py-2 text-sm font-bold text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 transition">Send Reminder</button>
        </div>
    </div>
</div>
<div id="cmReminderFlash" class="hidden fixed top-4 right-4 z-50 max-w-sm px-4 py-3 rounded-lg font-semibold text-sm shadow-lg"></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
(function () {
    var statusCanvas = document.getElementById('statusChart');
    if (statusCanvas) {
        new Chart(statusCanvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: <?= json_encode($status_labels) ?>,
                datasets: [{
                    data: <?= json_encode($status_data) ?>,
                    backgroundColor: <?= json_encode($status_colors) ?>
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });
    }

    var buCanvas = document.getElementById('buChart');
    if (buCanvas) {
        new Chart(buCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($bu_counts)) ?>,
                datasets: [{
                    label: 'Contracts',
                    data: <?= json_encode(array_values($bu_counts)) ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.6)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
        });
    }

    var categoryCanvas = document.getElementById('categoryChart');
    if (categoryCanvas) {
        new Chart(categoryCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_keys($category_counts)) ?>,
                datasets: [{
                    label: 'Contracts',
                    data: <?= json_encode(array_values($category_counts)) ?>,
                    backgroundColor: 'rgba(16, 185, 129, 0.6)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                scales: { x: { beginAtZero: true, ticks: { precision: 0 } } },
                plugins: { legend: { display: false } }
            }
        });
    }

    var typeCanvas = document.getElementById('typeChart');
    if (typeCanvas) {
        new Chart(typeCanvas.getContext('2d'), {
            type: 'pie',
            data: {
                labels: <?= json_encode(array_keys($type_counts)) ?>,
                datasets: [{
                    data: <?= json_encode(array_values($type_counts)) ?>,
                    backgroundColor: ['#6366f1', '#f59e0b', '#10b981', '#ec4899']
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } } }
        });
    }

    // ── Expiring-soon table: DataTables, same as Manage Data's #cmTable ──────
    // (search box, per-page dropdown, sortable columns, pagination) so the two
    // tables in this feature look and behave the same way.
    var expiringSoonTableEl = document.getElementById('expiringSoonTable');
    var cmExpiringTable = expiringSoonTableEl ? $('#expiringSoonTable').DataTable({
        pageLength: 25,
        order: [[5, 'asc']],
        // No length menu / search box -- this list is already filtered down to
        // "expired or expiring soon", so there's nothing to search or page size
        // to pick. Info + pagination left as DataTables' own default markup/
        // positioning (no custom wrapper), same as before any positioning was
        // touched.
        dom: "tip"
    }) : null;

    // ── Row multi-select (ctrl/cmd-click, shift-click range) ─────────────────
    // Same interaction as a file manager's list: click selects just that row,
    // ctrl/cmd-click toggles one row without touching the rest, shift-click
    // selects every row between the last-clicked row and this one.
    //
    // Delegated on document (not bound per-row): DataTables regenerates each
    // row from its own cached data on every search/sort/page redraw, which
    // would otherwise replace these rows with fresh nodes and drop a listener
    // attached directly to them at page load (see the same fix on
    // contract_maintenance_data.php's Edit/Delete buttons).
    if (expiringSoonTableEl) {
        var selected = new Set();
        var lastClickedId = null;
        var shootBtn = document.getElementById('shootReminderBtn');
        var shootLabel = document.getElementById('shootReminderLabel');

        function currentRows() {
            return Array.from(expiringSoonTableEl.querySelectorAll('tbody tr.js-selectable-row'));
        }

        function applySelectionStyles() {
            currentRows().forEach(function (row) {
                var isSelected = selected.has(row.dataset.id);
                row.classList.toggle('ring-2', isSelected);
                row.classList.toggle('ring-inset', isSelected);
                row.classList.toggle('ring-indigo-500', isSelected);
            });
            if (shootBtn) {
                shootBtn.disabled = selected.size === 0;
                if (shootLabel) {
                    shootLabel.textContent = selected.size > 0
                        ? 'Shoot Reminder (' + selected.size + ')'
                        : 'Shoot Reminder';
                }
            }
        }

        // Re-apply highlight whenever DataTables redraws: it rebuilds the
        // visible rows from its own data, so a previously-selected row would
        // otherwise show as unselected the moment it's paged/sorted/filtered
        // back into view, even though `selected` still has its id.
        if (cmExpiringTable) cmExpiringTable.on('draw', applySelectionStyles);

        document.addEventListener('click', function (e) {
            var row = e.target.closest('#expiringSoonTable .js-selectable-row');
            if (!row) return;
            var rows = currentRows();
            var index = rows.indexOf(row);
            var id = row.dataset.id;

            if (e.shiftKey && lastClickedId !== null) {
                var lastIndex = rows.findIndex(function (r) { return r.dataset.id === lastClickedId; });
                if (lastIndex === -1) lastIndex = index;
                var start = Math.min(lastIndex, index);
                var end = Math.max(lastIndex, index);
                for (var i = start; i <= end; i++) selected.add(rows[i].dataset.id);
            } else if (e.ctrlKey || e.metaKey) {
                if (selected.has(id)) selected.delete(id); else selected.add(id);
                lastClickedId = id;
            } else {
                selected.clear();
                selected.add(id);
                lastClickedId = id;
            }
            applySelectionStyles();
        });

        if (shootBtn) {
            var shootModal   = document.getElementById('shootModal');
            var shootCount   = document.getElementById('shootCount');
            var shootCancel  = document.getElementById('shootCancelBtn');
            var shootConfirm = document.getElementById('shootConfirmBtn');
            var flash        = document.getElementById('cmReminderFlash');

            function showFlash(message, ok) {
                flash.textContent = message;
                flash.className = 'fixed top-4 right-4 z-50 max-w-sm px-4 py-3 rounded-lg font-semibold text-sm shadow-lg ' +
                    (ok ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200');
                flash.classList.remove('hidden');
                setTimeout(function () { flash.classList.add('hidden'); }, 6000);
            }

            shootBtn.addEventListener('click', function () {
                if (selected.size === 0) return;
                shootCount.textContent = selected.size;
                shootModal.classList.remove('hidden');
            });
            shootCancel.addEventListener('click', function () { shootModal.classList.add('hidden'); });
            shootModal.addEventListener('click', function (e) { if (e.target === shootModal) shootModal.classList.add('hidden'); });

            shootConfirm.addEventListener('click', function () {
                shootModal.classList.add('hidden');
                shootBtn.disabled = true;
                var prevLabel = shootLabel.textContent;
                shootLabel.textContent = 'Sending…';
                fetch('ajax_contract_maintenance_shoot_reminder.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ids: Array.from(selected) })
                })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
                .then(function (res) {
                    showFlash(res.body.message || 'Could not send the reminder.', res.ok && res.body.status === 'success');
                    if (res.ok && res.body.status === 'success') {
                        selected.clear();
                        applySelectionStyles();
                    } else {
                        shootBtn.disabled = false;
                        shootLabel.textContent = prevLabel;
                    }
                })
                .catch(function () {
                    showFlash('Network error while sending.', false);
                    shootBtn.disabled = false;
                    shootLabel.textContent = prevLabel;
                });
            });
        }
    }
})();
</script>

<?php require_once '../includes/footer.php'; ?>
