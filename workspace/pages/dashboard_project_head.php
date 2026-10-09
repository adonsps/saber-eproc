<?php
// pages/dashboard_project_head.php
// Project Head dashboard: manual assignment of GP Project tickets to project officers.
// No AI. Mirrors the Manager Command Center styling. Access via project_head_groups.
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/sla_rules.php';
require_once '../includes/currency_helper.php';
require_once '../includes/vendor_quotation_helper.php';
require_once '../includes/quotation_bulk_ui.php';
require_once '../includes/quotation_modal_ui.php';
require_once '../includes/manual_ca_modal_ui.php';
require_once '../includes/header.php';

date_default_timezone_set('Asia/Jakarta');

// --- ACCESS CONTROL ---------------------------------------------------------
$allowed_groups = get_project_head_groups($pdo, $_SESSION);
if (empty($allowed_groups)) {
    echo "<div class='p-8 text-center mt-10'>
            <h2 class='text-2xl font-bold text-red-600'>Access Denied</h2>
            <p class='text-sm text-gray-600 mt-2'>This dashboard is only available to Category Managers and assigned Project Heads.</p>
          </div>";
    require_once '../includes/footer.php';
    exit;
}

$intercompany_types = ['intercompany', 'intercompany new store'];
$group_placeholders = implode(',', array_fill(0, count($allowed_groups), '?'));

// --- PROJECT TEAM NAMING ----------------------------------------------------
// Canonical full team names live in the DB (project_head_groups / project_pgroups)
// so Cost Savings / Cost Avoidance / SLA can reuse the same source of truth.
// Short codes (PKR/PKP/MFM) are a UI-only abbreviation keyed by group number.
$team_short_codes = [1 => 'PKR', 2 => 'PKP', 3 => 'MFM'];
$team_code = function($n) use ($team_short_codes) {
    return $team_short_codes[(int)$n] ?? ('G' . (int)$n);
};
// Full team names per allowed group number, read from the DB.
$group_name_map = [];
$gnstmt = $pdo->prepare("SELECT project_group_number, project_group_name
                         FROM project_head_groups
                         WHERE active = 1 AND project_group_number IN ($group_placeholders)");
$gnstmt->execute($allowed_groups);
foreach ($gnstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $group_name_map[(int)$r['project_group_number']] = $r['project_group_name'];
}

// --- ELIGIBLE OFFICER POOLS (per p_group) ----------------------------------
// $officers_by_pgroup feeds the manual-assign dropdown and the P.Group hover
// tooltip. $officers_by_group_number is the per-team fallback pool used only
// when a p_group has no exact officer list of its own.
// $officer_resolver is a read-only lookup that maps a source GP OFFICER value
// (either a full name, or a "<NIP> <firstname>" string) back to the canonical
// eligible officer_name for that exact p_group. Used for display hints only.
$officers_by_pgroup = [];
$officers_by_group_number = [];
$officer_resolver = [];
$ostmt = $pdo->prepare("SELECT p_group, project_group_number, officer_name, officer_nip
                        FROM project_pgroup_officers
                        WHERE active = 1 AND project_group_number IN ($group_placeholders)
                        ORDER BY officer_name");
$ostmt->execute($allowed_groups);
foreach ($ostmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
    $pg = $o['p_group'];
    $gnum = (int)$o['project_group_number'];
    $officers_by_pgroup[$pg][] = $o['officer_name'];
    if (!isset($officers_by_group_number[$gnum]) || !in_array($o['officer_name'], $officers_by_group_number[$gnum], true)) {
        $officers_by_group_number[$gnum][] = $o['officer_name'];
    }
    $officer_resolver[$pg]['names'][strtolower(trim((string)$o['officer_name']))] = $o['officer_name'];
    $nip = trim((string)$o['officer_nip']);
    if ($nip !== '') {
        $officer_resolver[$pg]['nips'][$nip] = $o['officer_name'];
    }
}

// Resolve a raw GP OFFICER string to an eligible officer_name for $p_group.
// Returns null when it cannot be resolved (e.g. Ridwan Arifin, who is not in
// the officer pool). Display hint only — this never writes assigned_pic.
$resolve_gp_officer = function($p_group, $gp_officer) use ($officer_resolver) {
    $gp = trim((string)$gp_officer);
    if ($gp === '' || !isset($officer_resolver[$p_group])) return null;
    $map = $officer_resolver[$p_group];
    $lc = strtolower($gp);
    if (isset($map['names'][$lc])) return $map['names'][$lc];
    if (preg_match('/^\d+/', $gp, $m) && isset($map['nips'][$m[0]])) {
        return $map['nips'][$m[0]];
    }
    return null;
};

// Source-backed Ridwan Arifin exception (mirrors the B-5B attribution rule):
// he is an Admin user deliberately kept OUT of project_pgroup_officers, so he
// never resolves via $resolve_gp_officer and is never selectable in the assign
// dropdown. He counts toward effective workload ONLY when the parsed GP OFFICER
// literally names him and his canonical user record exists. Display-only.
$ridwan_user_exists = (bool)$pdo
    ->query("SELECT 1 FROM users WHERE username = 'ridwan.arifin' AND name = 'Ridwan Arifin' LIMIT 1")
    ->fetchColumn();

// Effective officer for the workload chart (display-only, never writes):
//   1) assigned_pic when present;
//   2) else the GP OFFICER resolved to an eligible officer for that p_group;
//   3) else the source-backed Ridwan Arifin exception;
//   4) else null  => counted as Unassigned (kept off the officer bars).
$resolve_effective_officer = function($row) use ($resolve_gp_officer, $ridwan_user_exists) {
    $pic = trim((string)($row['assigned_pic'] ?? ''));
    if ($pic !== '') return $pic;
    $resolved = $resolve_gp_officer($row['p_group'] ?? '', $row['gp_officer'] ?? '');
    if ($resolved !== null) return $resolved;
    if ($ridwan_user_exists && strtolower(trim((string)($row['gp_officer'] ?? ''))) === 'ridwan arifin') {
        return 'Ridwan Arifin';
    }
    return null;
};

// --- MAIN FETCH -------------------------------------------------------------
$prStmt = $pdo->prepare("
    SELECT prs.ticket_no, prs.ticket_line_item, prs.pr_date, prs.p_org, prs.p_group,
           prs.requestor_name, prs.item_description, prs.status_ticket,
           prs.canvassing_no, prs.canvassing_status, prs.canvassing_date, prs.po_number,
           prs.ticket_type, prs.vendor_name, prs.assigned_pic, prs.assigned_by_ai, prs.gp_officer,
           prs.tr_date_gp, prs.canvassing_date_approver_1, prs.canvassing_description, prs.ticket_helpdesk_no,
           pp.project_group_number, pp.project_group_name
    FROM prs
    JOIN project_pgroups pp ON prs.p_group = pp.p_group AND pp.status = 'ACTIVE'
    WHERE pp.project_group_number IN ($group_placeholders)
      AND LOWER(prs.status_ticket) NOT LIKE '%cancel%'
      AND (prs.pr_date >= '2026-01-01' OR prs.pr_date IS NULL OR (prs.canvassing_no IS NOT NULL AND prs.canvassing_no != '') OR (prs.po_number IS NOT NULL AND prs.po_number != ''))
    ORDER BY prs.created_at DESC
");
$prStmt->execute($allowed_groups);
$all_prs = $prStmt->fetchAll(PDO::FETCH_ASSOC);

// --- DERIVE STATUS / STATE (per line) --------------------------------------
// Line-level pass: sets the display status, intercompany flag and assign state
// the table needs. Officer workload is NOT accumulated here anymore -- it is
// computed after grouping from the effective officer (see below).
foreach ($all_prs as &$pr) {
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

    $pr['is_intercompany'] = in_array(strtolower(trim((string)$pr['ticket_type'])), $intercompany_types, true);
    $pr['assign_state'] = (trim((string)$pr['assigned_pic']) === '') ? 'pending' : 'assigned';
}
unset($pr);

// --- AGGREGATE TO ONE ROW PER TICKET + P.GROUP -----------------------------
// The table shows a single row per unique ticket_no + p_group. When a ticket
// carries several line items inside the same p_group they collapse into one
// ticket row (assignment scope is ticket_no + p_group, so all those lines
// always share one officer). Representative item / vendor / requestor / status
// come from the first (most recent, created_at DESC) line of the group; the
// line count is surfaced subtly so a multi-line ticket is still obvious.
// These grouped rows are also the unit the effective-officer workload counts.
$grouped_rows = [];
foreach ($all_prs as $line) {
    $key = $line['ticket_no'] . '|' . $line['p_group'];
    if (!isset($grouped_rows[$key])) {
        $line['line_count'] = 1;
        $grouped_rows[$key] = $line;
        continue;
    }
    $g = &$grouped_rows[$key];
    $g['line_count']++;
    // Prefer a real assigned officer if any line of the group carries one.
    if (trim((string)$g['assigned_pic']) === '' && trim((string)$line['assigned_pic']) !== '') {
        $g['assigned_pic'] = $line['assigned_pic'];
        $g['assign_state'] = 'assigned';
    }
    if (trim((string)($g['ticket_helpdesk_no'] ?? '')) === '' && trim((string)($line['ticket_helpdesk_no'] ?? '')) !== '') {
        $g['ticket_helpdesk_no'] = $line['ticket_helpdesk_no'];
    }
    // Prefer a non-empty source GP OFFICER hint if the base line had none.
    if (trim((string)($g['gp_officer'] ?? '')) === '' && trim((string)($line['gp_officer'] ?? '')) !== '') {
        $g['gp_officer'] = $line['gp_officer'];
    }
    // Prefer a non-empty canvassing_no if the base line had none (Last Price
    // Quotation canvas-scope lookup needs a representative canvassing_no).
    if (trim((string)($g['canvassing_no'] ?? '')) === '' && trim((string)($line['canvassing_no'] ?? '')) !== '') {
        $g['canvassing_no'] = $line['canvassing_no'];
    }
    // Any intercompany line keeps the whole ticket-group read-only.
    if (!empty($line['is_intercompany'])) {
        $g['is_intercompany'] = true;
    }
    unset($g);
}
$grouped_rows = array_values($grouped_rows);

// SLA verdict per visible row, measured off the group's representative line (the
// same line the item / vendor / status columns already come from).
sla_attach($pdo, $grouped_rows);

// --- LAST PRICE QUOTATION SUMMARY (batched, no N+1) --------------------------
// Canvas-scope rows keyed by canvassing_no; item-scope priced-line counts
// rolled up per canvassing_no (the quotation's unit -- one canvassing can
// span several tickets). Display only here; writes happen via
// ajax_project_quotation_save.php.
$quotation_canvas_map = [];
$quotation_item_count_map = [];
$quotation_currency_symbols = ca_currency_symbols(ca_get_active_currencies($pdo));
try {
    $canvassing_nos = [];
    foreach ($grouped_rows as $g) {
        $c = trim((string)($g['canvassing_no'] ?? ''));
        if ($c !== '') { $canvassing_nos[$c] = true; }
    }
    if (!empty($canvassing_nos)) {
        $ph = implode(',', array_fill(0, count($canvassing_nos), '?'));
        $stmt = $pdo->prepare("SELECT canvassing_no, last_price, currency, gdrive_link, notes FROM vendor_quotation_last_price WHERE scope = 'canvas' AND canvassing_no IN ($ph)");
        $stmt->execute(array_keys($canvassing_nos));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $quotation_canvas_map[$r['canvassing_no']] = $r;
        }
    }
    $quotation_item_count_map = quotation_item_counts_by_canvassing($pdo, array_keys($canvassing_nos));
} catch (PDOException $e) {
    // Fail open: table not deployed yet just means every row shows "Add quotation".
}

// {symbol} {number}, 0-decimal comma/dot IDR style kept for IDR (matches the
// figures users are used to); anything else falls back to standard 2-decimal
// formatting since that's how those currencies are normally quoted.
function quotation_format_price(float $price, string $currency, array $symbols): string {
    $symbol = $symbols[$currency] ?? $currency;
    if ($currency === 'IDR') {
        return $symbol . ' ' . number_format($price, 0, ',', '.');
    }
    return $symbol . ' ' . number_format($price, 2, '.', ',');
}

// --- EFFECTIVE OFFICER WORKLOAD (display-only, Project-effective) -----------
// One unit per ticket_no + p_group (a grouped row), bucketed by effective
// officer: assigned_pic -> resolved GP OFFICER -> source-backed Ridwan. Both
// PO-created and open rows count, so the chart reflects parsed K2 ownership
// rather than only manually-assigned open lines. Unassigned is kept off the
// officer bars and surfaced separately near the chart. No DB writes.
$workload = [];
$unassigned_count = 0;
foreach ($grouped_rows as $g) {
    $eff = $resolve_effective_officer($g);
    if ($eff === null) { $unassigned_count++; continue; }
    $workload[$eff] = ($workload[$eff] ?? 0) + 1;
}
// Sort heaviest-first for a readable chart, then split into Chart.js arrays.
arsort($workload);
$chart_labels = array_keys($workload);
$chart_counts = array_values($workload);

// --- LAST UPDATED (PR Upload) -------------------------------------------
$last_import = null;
try {
    date_default_timezone_set('Asia/Jakarta');
    $li = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_pr_upload_at'")->fetchColumn();
    if (!empty($li)) { $last_import = date('d M Y, H:i', strtotime($li)) . ' WIB'; }
} catch (PDOException $e) {
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<style>
/* Search/filter toolbar — consistent field styling */
.filt-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; margin-bottom: 2px; }
.filt-input { padding: 5px 8px; font-size: 12px; line-height: 1.2; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #374151; width: 100%; min-width: 0; box-sizing: border-box; }
.filt-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
.filt-date { font-size: 11px; padding: 4px 5px; }
/* Each field gets a shrinkable flex-basis instead of its input's natural/fixed
   width, so the row compresses to fit instead of wrapping Global Search onto
   its own line. min-width:0 is required on flex children for shrinking to
   work at all — without it a flex item never shrinks past its content size. */
.toolbar-scroll { overflow-x: auto; }
.filt-cell { flex: 1 1 130px; min-width: 90px; }
#globalSearch { min-width: 0; }
.filt-cell-search { flex: 1 1 160px; min-width: 120px; }
/* keep the DataTables length menu tidy under the toolbar */
#projTable_wrapper .dataTables_length { margin-bottom: .5rem; font-size: 12px; }
/* P.Group hover tooltip (lightweight, body-anchored, no library) */
#pgroupTooltip { position: fixed; z-index: 60; display: none; max-width: 240px;
    background: #1f2937; color: #f9fafb; border-radius: 8px; padding: 8px 10px;
    box-shadow: 0 10px 25px rgba(0,0,0,.25); font-size: 11px; pointer-events: none; }
#pgroupTooltip .pgtip-head { font-weight: 700; font-size: 11px; margin-bottom: 4px; color: #c7d2fe; }
#pgroupTooltip .pgtip-sub { font-size: 9px; text-transform: uppercase; letter-spacing: .05em; color: #9ca3af; margin-bottom: 3px; }
#pgroupTooltip .pgtip-list { list-style: none; margin: 0; padding: 0; }
#pgroupTooltip .pgtip-list li { padding: 1px 0; }
#pgroupTooltip .pgtip-empty { font-style: italic; color: #9ca3af; }
.pgroup-cell { cursor: help; }
</style>

<div class="mb-6 flex flex-col lg:flex-row lg:justify-between lg:items-end gap-4 border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Project Head Command Center</h2>
        <p class="text-sm text-gray-600">Manual assignment of GP Project tickets to project officers. No AI routing.</p>
    </div>
    <?php if ($last_import): ?>
    <div class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
        <span class="font-bold text-gray-600 uppercase tracking-wider">Last updated:</span>
        <span class="font-medium text-gray-700"><?= htmlspecialchars($last_import) ?></span>
    </div>
    <?php endif; ?>
</div>

<!-- OFFICER WORKLOAD (Project-effective: assigned_pic → resolved GP Officer → source-backed) -->
<div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
    <div class="flex flex-wrap items-start justify-between gap-2 mb-4">
        <div>
            <h3 class="text-lg font-semibold text-gray-800">Current Officer Workload</h3>
            <p class="text-xs text-gray-400 mt-0.5">Ticket+P.Group count per officer &mdash; assigned_pic, then resolved GP Officer (parsed K2 data). Display only.</p>
        </div>
        <?php if ($unassigned_count > 0): ?>
        <div class="flex items-center gap-1.5 bg-gray-50 border border-gray-200 rounded-lg px-3 py-1.5 text-xs text-gray-500">
            <span class="inline-block w-2 h-2 rounded-full bg-gray-300"></span>
            <span><strong class="text-gray-700"><?= $unassigned_count ?></strong> Unassigned (no officer recognized)</span>
        </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($chart_labels)): ?>
    <div class="relative h-64 w-full">
        <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
            <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        </div>
        <canvas id="workloadChart"></canvas>
    </div>
    <?php else: ?>
    <p class="text-sm text-gray-500 italic py-8 text-center">No recognized officer workload in the current window.</p>
    <?php endif; ?>
</div>

<!-- FILTER PILLS -->
<div class="mb-4 flex flex-col gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider mr-2">Filter by Status:</span>
        <button type="button" class="status-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="">All Statuses</button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="Outstanding">Outstanding</button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="On Progress">On Progress</button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="PO Created">PO Created</button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="Escalated">Escalated</button>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider mr-2">Filter by Assignment:</span>
        <button type="button" class="pic-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-pic-filter="">All</button>
        <button type="button" class="pic-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-pic-filter="manual">Manual</button>
        <button type="button" class="pic-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-pic-filter="unassigned">Unassigned</button>
    </div>
    <?php if (count($allowed_groups) > 1): ?>
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider mr-2">Filter by Team:</span>
        <button type="button" class="group-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 transition-all duration-150 focus:outline-none" data-group="">All Teams</button>
        <?php foreach ($allowed_groups as $gnum): ?>
        <button type="button" class="group-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 transition-all duration-150 focus:outline-none" data-group="<?= $gnum ?>" title="<?= htmlspecialchars($group_name_map[$gnum] ?? ('Group ' . $gnum)) ?>"><?= htmlspecialchars($team_code($gnum)) ?></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- SEARCH / FILTER TOOLBAR (field filters left, global search right) -->
<div class="mb-6 bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="toolbar-scroll flex flex-nowrap items-end gap-x-3 gap-y-2">
        <div class="flex flex-col filt-cell">
            <label class="filt-label">Ticket No</label>
            <input type="text" data-col="0" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="flex flex-col filt-cell">
            <label class="filt-label">Item</label>
            <input type="text" data-col="5" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="flex flex-col filt-cell">
            <label class="filt-label">Vendor</label>
            <input type="text" data-col="6" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="flex flex-col filt-cell">
            <label class="filt-label">Assigned Officer</label>
            <input type="text" data-col="9" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="flex flex-col filt-cell-search ml-auto">
            <label class="filt-label text-right">Global Search</label>
            <input type="text" id="globalSearch" class="filt-input" placeholder="Search all columns...">
        </div>
    </div>
</div>

<?php $qb_show = !ws_is_viewer() && !qb_hidden_for($_SESSION); ?>
<?php if ($qb_show): ?>
<div class="mb-3 flex items-center gap-2">
    <?php qb_render_toolbar('project_head', true, false, true); ?>
</div>
<?php endif; ?>

<!-- TABLE -->
<div class="bg-white shadow-md rounded-lg border border-gray-200 mb-8 relative">
    <div class="viz-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80 rounded-lg">
        <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        <p class="mt-3 text-xs font-semibold text-gray-500">Loading records…</p>
    </div>
    <div class="overflow-y-auto px-6 pb-6 pt-2" style="max-height: 750px;">
        <table id="projTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[150px] text-xs font-bold text-gray-600 uppercase tracking-wider">Ticket No</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[130px] text-xs font-bold text-gray-600 uppercase tracking-wider">PR Date</th>
                    <th class="bg-gray-100 px-2 py-2.5 border-b-2 border-gray-300 shadow-sm w-[70px] min-w-[70px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center" title="Days remaining against this ticket's SLA target, counted from the PR date">SLA</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[110px] text-xs font-bold text-gray-600 uppercase tracking-wider">P.ORG</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[150px] text-xs font-bold text-gray-600 uppercase tracking-wider">Requestor</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[200px] text-xs font-bold text-gray-600 uppercase tracking-wider">Item Description</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[150px] text-xs font-bold text-gray-600 uppercase tracking-wider">Vendor</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[120px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center">P.Group / Team</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[120px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center">Status</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[180px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center">Assigned Officer</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[180px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center" title="Price settled during canvassing, before a PO exists">Initial Quotation Price</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[150px] text-xs font-bold text-gray-600 uppercase tracking-wider" title="Ticket Helpdesk No. from the K2 export">Helpdesk No.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grouped_rows as $pr): ?>
                    <tr class="hover:bg-indigo-50 transition border-b border-gray-100"
                        data-group="<?= (int)$pr['project_group_number'] ?>"
                        data-assign-state="<?= $pr['assign_state'] ?>"
                        data-intercompany="<?= $pr['is_intercompany'] ? 1 : 0 ?>"
                        data-pgroup="<?= htmlspecialchars($pr['p_group']) ?>">
                        <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($pr['ticket_no']) ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700" data-order="<?= $pr['pr_date'] ? date('Y-m-d', strtotime($pr['pr_date'])) : '' ?>"><?= $pr['pr_date'] ? date('d M Y', strtotime($pr['pr_date'])) : '-' ?></td>
                        <td class="px-2 py-3 text-sm text-center" data-order="<?= (int)$pr['sla_sort'] ?>" data-search="<?= htmlspecialchars($pr['sla_status']) ?>">
                            <span class="<?= $pr['sla_badge'] ?> py-1 px-1 rounded text-[10px] font-bold border block w-fit min-w-[62px] mx-auto text-center whitespace-nowrap" title="<?= htmlspecialchars($pr['sla_title']) ?>"><?= htmlspecialchars($pr['sla_label']) ?></span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($pr['p_org']) ?></td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div class="truncate max-w-[140px]" title="<?= htmlspecialchars($pr['requestor_name']) ?>"><?= htmlspecialchars($pr['requestor_name']) ?></div>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            <div class="truncate max-w-[200px]" title="<?= htmlspecialchars($pr['item_description']) ?>"><?= htmlspecialchars($pr['item_description']) ?></div>
                            <?php if (($pr['line_count'] ?? 1) > 1): ?>
                            <div class="text-[10px] text-gray-400 mt-0.5"><?= (int)$pr['line_count'] ?> lines</div>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($pr['vendor_name'] ?: '--') ?></td>
                        <td class="px-4 py-3 text-sm text-center">
                            <div class="pgroup-cell inline-block"
                                 data-pgroup="<?= htmlspecialchars($pr['p_group']) ?>"
                                 data-group="<?= (int)$pr['project_group_number'] ?>"
                                 data-team="<?= htmlspecialchars($pr['project_group_name']) ?>">
                                <span class="inline-block bg-gray-100 text-gray-700 border border-gray-200 py-1 px-2 rounded-md text-[10px] font-bold"><?= htmlspecialchars($pr['p_group']) ?></span>
                                <div class="text-[10px] font-bold text-indigo-500 mt-1 tracking-wide"><?= htmlspecialchars($team_code($pr['project_group_number'])) ?></div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-sm text-center" data-search="<?= htmlspecialchars($pr['display_status']) ?>">
                            <?php
                                $statusClass = 'bg-gray-100 text-gray-800 border-gray-200';
                                if ($pr['display_status'] === 'Outstanding') $statusClass = 'bg-red-100 text-red-800 border-red-200';
                                if ($pr['display_status'] === 'Escalated') $statusClass = 'bg-orange-100 text-orange-800 border-orange-200';
                                if ($pr['display_status'] === 'Sendback') $statusClass = 'bg-purple-100 text-purple-800 border-purple-200';
                                if ($pr['display_status'] === 'Canvassing Cancelled') $statusClass = 'bg-red-500 text-white border-red-600';
                                if ($pr['display_status'] === 'On Progress') $statusClass = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                                if ($pr['display_status'] === 'PO Created') $statusClass = 'bg-green-100 text-green-800 border-green-200';
                            ?>
                            <span class="<?= $statusClass ?> py-1 px-2 rounded-md text-[10px] font-bold border inline-block text-center"><?= htmlspecialchars($pr['display_status']) ?></span>
                        </td>
                        <?php
                            // Read-only hint: for still-pending, non-Intercompany rows, resolve the
                            // source GP OFFICER value to an eligible officer. Display only - never writes.
                            $resolved_officer = (!$pr['is_intercompany'] && $pr['assign_state'] === 'pending')
                                ? $resolve_gp_officer($pr['p_group'], $pr['gp_officer'] ?? '')
                                : null;
                        ?>
                        <td class="px-4 py-3 text-sm text-center" data-search="<?= htmlspecialchars($pr['assigned_pic'] ?: ($resolved_officer ?: 'Unassigned')) ?>">
                            <?php if ($pr['is_intercompany']): ?>
                                <span class="inline-flex items-center px-3 py-1.5 border border-blue-200 shadow-sm text-xs font-medium rounded text-blue-800 bg-blue-50" title="Routed via admin mappings / system rule">
                                    <span class="font-semibold"><?= htmlspecialchars($pr['assigned_pic'] ?: '--') ?></span>
                                    <span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span>
                                </span>
                            <?php else: ?>
                                <button type="button"
                                        class="assign-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors"
                                        data-ticket="<?= htmlspecialchars($pr['ticket_no']) ?>"
                                        data-pgroup="<?= htmlspecialchars($pr['p_group']) ?>"
                                        data-officer="<?= htmlspecialchars($pr['assigned_pic'] ?? '') ?>">
                                    <?php if ($pr['assigned_pic']): ?>
                                        <span class="text-indigo-700 font-semibold"><?= htmlspecialchars($pr['assigned_pic']) ?> (Manual)</span>
                                    <?php elseif ($resolved_officer !== null): ?>
                                        <span class="text-amber-700 font-semibold"><?= htmlspecialchars($resolved_officer) ?></span>
                                        <span class="ml-1 text-[9px] text-amber-500 font-bold uppercase tracking-wider">GP Officer</span>
                                    <?php else: ?>
                                        <span class="text-yellow-600 italic">Unassigned</span>
                                    <?php endif; ?>
                                    <svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg>
                                </button>
                            <?php endif; ?>
                        </td>
                        <?php
                            $canv = trim((string)($pr['canvassing_no'] ?? ''));
                            $canvasQ = $canv !== '' ? ($quotation_canvas_map[$canv] ?? null) : null;
                            $itemQCount = $canv !== '' ? ($quotation_item_count_map[$canv] ?? 0) : 0;
                        ?>
                        <td class="px-4 py-3 text-sm text-center">
                            <?php if ($pr['is_intercompany']): ?>
                                <span class="inline-flex items-center px-3 py-1.5 border border-blue-200 shadow-sm text-xs font-medium rounded text-blue-800 bg-blue-50" title="Routed via admin mappings / system rule">
                                    <span class="text-blue-400 italic">N/A</span>
                                    <span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span>
                                </span>
                            <?php elseif ($canv === ''): ?>
                                <span class="inline-flex items-center px-3 py-1.5 border border-gray-200 text-xs font-medium rounded text-gray-400 bg-gray-50 cursor-not-allowed" title="Quotation is entered per canvassing -- available once this ticket has a canvassing number">
                                    <span class="italic">Awaiting canvassing</span>
                                </span>
                            <?php else: ?>
                                <button type="button"
                                        class="quotation-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors"
                                        data-canvassing="<?= htmlspecialchars($canv) ?>">
                                    <?php if ($canvasQ): ?>
                                        <span class="text-indigo-700 font-semibold"><?= htmlspecialchars(quotation_format_price((float)$canvasQ['last_price'], (string)($canvasQ['currency'] ?? 'IDR'), $quotation_currency_symbols)) ?></span>
                                        <span class="ml-1 text-[9px] text-indigo-500 font-bold uppercase tracking-wider">Canvas</span>
                                    <?php elseif ($itemQCount > 0): ?>
                                        <span class="text-indigo-700 font-semibold"><?= (int)$itemQCount ?> item<?= $itemQCount > 1 ? 's' : '' ?> priced</span>
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
</div>

<!-- ASSIGN MODAL -->
<div id="assignModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="assignOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
              <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
              </div>
              <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                <h3 class="text-lg font-medium text-gray-900">Assign Project Ticket</h3>
                <div class="mt-2">
                  <p class="text-sm text-gray-500 mb-1">Ticket <strong id="modal-ticket-display" class="text-indigo-600"></strong></p>
                  <p class="text-xs text-gray-400 mb-4">P.Group <strong id="modal-pgroup-display"></strong> &middot; eligible officers only.</p>
                  <select id="modal-officer-select" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                      <option value="">-- Set to Unassigned --</option>
                  </select>
                  <p id="modal-error" class="text-xs text-red-600 mt-2 hidden"></p>
                </div>
              </div>
            </div>
        </div>
        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="button" id="assignSaveBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Save Changes</button>
            <button type="button" id="assignCancelBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
        </div>
    </div>
  </div>
</div>

<script>
var officersByPgroup = <?= json_encode($officers_by_pgroup, JSON_UNESCAPED_UNICODE) ?>;
var officersByGroupNumber = <?= json_encode($officers_by_group_number, JSON_UNESCAPED_UNICODE) ?>;

$(document).ready(function() {
    var currentStatusFilter = '';  // '', 'Outstanding', 'On Progress', 'PO Created', 'Escalated' (ticket status)
    var currentStateFilter = '';   // assignment state ('pending'/'assigned') — kept for internal logic, no UI surfaced
    var currentGroupFilter = '';
    var currentPicFilter = '';     // '', 'manual', 'unassigned' — Assigned Officer column state

    // dom 'rtip' drops the built-in search box AND the length selector; our toolbar
    // #globalSearch drives search and pageLength stays fixed at 25 (pagination kept).
    var table = $('#projTable').DataTable({
        "pageLength": 25,
        "order": [[ 1, "desc" ]],
        "dom": 'rtip'
    });

    // Rows are paginated by DataTables now — drop the table loading skeleton.
    $('.viz-table-loader').fadeOut(200);

    // --- OFFICER WORKLOAD CHART (Project-effective: ticket+p_group units) ---
    var workloadCanvas = document.getElementById('workloadChart');
    if (workloadCanvas) {
        new Chart(workloadCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?= json_encode($chart_labels) ?>,
                datasets: [{
                    label: 'Ticket+P.Group count',
                    data: <?= json_encode($chart_counts) ?>,
                    backgroundColor: 'rgba(79, 70, 229, 0.6)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }, plugins: { legend: { display: false } } }
        });
    }
    // Chart is drawn (or absent) — remove its loading skeleton.
    $('.viz-chart-loader').fadeOut(200);

    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        var row = $(settings.aoData[dataIndex].nTr);

        if (currentStatusFilter) {
            var statusVal = data[7] ? data[7].trim() : '';
            if (statusVal !== currentStatusFilter) return false;
        }
        if (currentStateFilter) {
            if ((row.attr('data-assign-state') || '') !== currentStateFilter) return false;
        }
        if (currentGroupFilter) {
            if ((row.attr('data-group') || '') !== currentGroupFilter) return false;
        }
        if (currentPicFilter === 'manual') {
            if ((row.attr('data-assign-state') || '') !== 'assigned') return false;
        }
        if (currentPicFilter === 'unassigned') {
            // Assigned Officer is the 10th column (index 9); its data-search attribute
            // is the literal string "Unassigned" only when neither assigned_pic nor a
            // resolved GP Officer hint exists (see the PHP td markup).
            var picSearch = row.find('td').eq(9).attr('data-search') || '';
            if (picSearch !== 'Unassigned') return false;
        }

        return true;
    });

    // --- TICKET STATUS FILTER (mirrors the GP Non-Project dashboard style) ---
    $('.status-filter-btn').on('click', function() {
        $('.status-filter-btn').removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 bg-orange-500 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 border-orange-500 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200');
        var status = $(this).data('status');
        currentStatusFilter = status || '';
        if (status === 'Outstanding') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-red-600 text-white border-red-600 shadow-md ring-2 ring-offset-2');
        } else if (status === 'On Progress') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-yellow-500 text-white border-yellow-500 shadow-md ring-2 ring-offset-2');
        } else if (status === 'PO Created') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-green-600 text-white border-green-600 shadow-md ring-2 ring-offset-2');
        } else if (status === 'Escalated') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-orange-500 text-white border-orange-500 shadow-md ring-2 ring-offset-2');
        } else {
            currentStatusFilter = '';
            $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-indigo-600 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        }
        table.draw();
    });

    $('.group-filter-btn').on('click', function() {
        $('.group-filter-btn').removeClass('bg-indigo-600 text-white border-indigo-600 ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200');
        $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-indigo-600 text-white border-indigo-600 ring-2 ring-offset-2');
        currentGroupFilter = ($(this).data('group') === '' || $(this).data('group') === undefined) ? '' : String($(this).data('group'));
        table.draw();
    });

    // --- ASSIGNMENT FILTER (Manual / Unassigned, on the Assigned Officer column) ---
    $('.pic-filter-btn').on('click', function() {
        $('.pic-filter-btn').removeClass('bg-indigo-600 text-white border-indigo-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200');
        $(this).removeClass('bg-white text-gray-700 border-gray-200').addClass('bg-indigo-600 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        currentPicFilter = $(this).data('pic-filter') || '';
        table.draw();
    });

    // --- TOOLBAR: per-column field filters ---
    $('.column-search-text').on('keyup change', function() {
        table.column($(this).data('col')).search(this.value).draw();
    });
    $('.column-search-select').on('change', function() {
        table.column($(this).data('col')).search(this.value).draw();
    });

    // --- TOOLBAR: global search (drives DataTables' built-in search) ---
    $('#globalSearch').on('keyup change', function() {
        table.search(this.value).draw();
    });

    // --- P.GROUP / TEAM HOVER TOOLTIP (eligible officers for that p_group) ---
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function(c) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    var $pgTip = $('<div id="pgroupTooltip"></div>').appendTo('body');
    function positionTip(el) {
        var r = el.getBoundingClientRect();
        var tw = $pgTip.outerWidth(), th = $pgTip.outerHeight();
        var left = r.left + (r.width / 2) - (tw / 2);
        var top = r.top - th - 8;
        if (top < 8) top = r.bottom + 8; // flip below when no room above
        left = Math.max(8, Math.min(left, window.innerWidth - tw - 8));
        $pgTip.css({ left: left + 'px', top: top + 'px' });
    }
    $('#projTable tbody').on('mouseenter', '.pgroup-cell', function() {
        var pg = String($(this).data('pgroup') || '');
        var gnum = String($(this).data('group') || '');
        var team = $(this).data('team') || '';
        var officers = officersByPgroup[pg];
        if (!officers || !officers.length) officers = officersByGroupNumber[gnum] || [];
        var html = '<div class="pgtip-head">' + escapeHtml(team) + ' &middot; ' + escapeHtml(pg) + '</div>';
        if (officers && officers.length) {
            html += '<div class="pgtip-sub">Eligible officers</div><ul class="pgtip-list">';
            officers.forEach(function(n) { html += '<li>' + escapeHtml(n) + '</li>'; });
            html += '</ul>';
        } else {
            html += '<div class="pgtip-empty">No eligible officers</div>';
        }
        $pgTip.html(html).show();
        positionTip(this);
    }).on('mousemove', '.pgroup-cell', function() {
        positionTip(this);
    }).on('mouseleave', '.pgroup-cell', function() {
        $pgTip.hide();
    });

    // --- ASSIGN MODAL ---
    var modal = $('#assignModal');
    var currentTicket = '', currentPgroup = '';

    $('#projTable tbody').on('click', '.assign-btn', function() {
        currentTicket = $(this).data('ticket');
        currentPgroup = String($(this).data('pgroup'));
        var currentOfficer = $(this).data('officer') || '';

        $('#modal-ticket-display').text(currentTicket);
        $('#modal-pgroup-display').text(currentPgroup);
        $('#modal-error').addClass('hidden').text('');

        var $sel = $('#modal-officer-select');
        $sel.empty().append('<option value="">-- Set to Unassigned --</option>');
        var pool = officersByPgroup[currentPgroup] || [];
        pool.forEach(function(name) {
            $sel.append($('<option>').val(name).text(name));
        });
        $sel.val(currentOfficer);
        modal.removeClass('hidden');
    });

    $('#assignCancelBtn, #assignOverlay').on('click', function() { modal.addClass('hidden'); });

    $('#assignSaveBtn').on('click', function() {
        var btn = $(this);
        var newOfficer = $('#modal-officer-select').val();
        btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: 'ajax_project_assign.php',
            type: 'POST',
            dataType: 'json',
            data: { ticket_no: currentTicket, p_group: currentPgroup, new_officer: newOfficer },
            success: function(resp) {
                if (resp.status === 'success') {
                    location.reload();
                } else {
                    $('#modal-error').removeClass('hidden').text(resp.message || 'Assignment failed.');
                    btn.prop('disabled', false).text('Save Changes');
                }
            },
            error: function() {
                $('#modal-error').removeClass('hidden').text('Connection error. Please try again.');
                btn.prop('disabled', false).text('Save Changes');
            }
        });
    });
});
</script>

<?php qm_render_modal('#projTable'); ?>
<?php if ($qb_show) qb_render_modal(qb_guide_for($pdo, $_SESSION, true, false)); ?>
<?php if ($qb_show) mca_render_modal(); ?>

<?php require_once '../includes/footer.php'; ?>
