<?php
// pages/sla_report.php
require_once '../includes/auth.php';
require_sla_access();
require_once '../config/database.php';
require_once '../includes/header.php';
require_once '../includes/sla_helper.php';
require_once '../includes/project_team_helper.php';
require_once '../includes/sla_bulk_request.php'; // Bulk SLA Request modal (row cap shown in its rules)
date_default_timezone_set('Asia/Jakarta');

try {
    $ctx = resolve_sla_persona($pdo, $_SESSION);
    $computed = get_sla_computed_rows($pdo, $ctx);
} catch (PDOException $e) {
    error_log('sla_report.php: ' . $e->getMessage());
    ?>
    <div class="mb-6 bg-red-50 border border-red-200 text-red-700 rounded-lg p-6 text-center">
        <h2 class="text-lg font-bold mb-2">Gagal memuat data SLA</h2>
        <p class="text-sm">Terjadi kendala saat mengambil data dari database. Coba muat ulang halaman beberapa saat lagi, atau hubungi admin jika masalah berlanjut.</p>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}
$mode = $computed['mode'];
$unique_teams = $computed['unique_teams'];
$data = $computed['rows'];

// Trend bucket keys/labels are pure date-math with no row dependency, so they stay
// computed here and embedded as before (the trend chart's X axis is built from them).
// The team-color palette is NOT needed here anymore — ajax_sla_charts.php now applies it
// server-side when it builds the trend datasets.
$trend_buckets = sla_trend_buckets();

// Distinct L0..L4 tuples present in the data — feeds the cascading taxonomy filter in JS.
// Kept client-embedded (distinct combos are a much smaller set than the row count).
$taxonomy_combos = [];
$seen_combo = [];
foreach ($data as $row) {
    $tuple = [
        trim((string)($row['level_0'] ?? '')),
        trim((string)($row['level_1'] ?? '')),
        trim((string)($row['level_2'] ?? '')),
        trim((string)($row['level_3'] ?? '')),
        trim((string)($row['level_4'] ?? '')),
    ];
    if (implode('', $tuple) === '') continue; // fully unmapped row, nothing to offer
    $key = implode('||', $tuple);
    if (isset($seen_combo[$key])) continue;
    $seen_combo[$key] = true;
    $taxonomy_combos[] = $tuple;
}

// ===== SLA Approvals inbox (was pages/sla_requests.php) ======================
// Manager/Category Manager only. Folded into this page as a modal — reached
// via the "Manage SLA Request" button below — instead of a standalone page, so the
// workflow adds no new page file. Deciding still happens through
// ajax_sla_request_decide.php, which re-checks authorization per request;
// this block only decides what the modal SHOWS.
$slarep_is_approver = in_array($_SESSION['role'] ?? '', ['Manager', 'Category Manager'], true);
// Bulk SLA Request (CSV) — same roles as a single request (ajax_sla_request_submit.php
// step 1); ajax_sla_bulk_request.php enforces it again. Viewer is not in the list.
$slarep_can_bulk = in_array($_SESSION['role'] ?? '', ['PIC', 'Manager', 'Category Manager'], true) && !ws_is_viewer();
$appr_details = [];
$appr_load_error = null;

function slareq_fmt_datetime($v) {
    if (empty($v)) return '-';
    $ts = strtotime($v);
    return $ts ? date('d M Y H:i', $ts) : '-';
}
function slareq_fmt_date($v) {
    if (empty($v)) return '-';
    $ts = strtotime($v);
    return $ts ? date('d M Y', $ts) : '-';
}
// One request can carry two changes (a Date Adjustment may move both dates), so
// each value cell renders a labelled line per change. The label is dropped when
// there is only one — it would just repeat the Request Type column.
function slareq_value_cell($pairs, $side) {
    if (empty($pairs)) return '<span class="text-gray-400">-</span>';
    $out = [];
    foreach ($pairs as $p) {
        $v = htmlspecialchars($p[$side]);
        $out[] = (count($pairs) > 1)
            ? '<span class="block"><span class="text-gray-400 text-[10px] uppercase">' . htmlspecialchars($p['label']) . '</span><br>' . $v . '</span>'
            : $v;
    }
    return implode('<span class="block h-1"></span>', $out);
}

if ($slarep_is_approver) {
    try {
        $appr_role     = $_SESSION['role'] ?? '';
        $appr_username = trim((string)($_SESSION['username'] ?? ''));

        // Scope mirrors sla_rev_can_decide(): a Category Manager sees everything;
        // a Manager sees the requests routed to them, plus any orphaned request
        // (no manager on file for the requester) from someone in their own tree.
        if ($appr_role === 'Category Manager') {
            $appr_where  = '1=1';
            $appr_params = [];
        } else {
            $appr_sub_usernames = [];
            try {
                foreach (get_all_subordinates($pdo, $appr_username) as $sub) {
                    if (!empty($sub['username'])) $appr_sub_usernames[] = $sub['username'];
                }
            } catch (PDOException $e) {
                $appr_sub_usernames = [];
            }

            $appr_where  = 'r.approver_username = ?';
            $appr_params = [$appr_username];
            if (!empty($appr_sub_usernames)) {
                $ph = implode(',', array_fill(0, count($appr_sub_usernames), '?'));
                $appr_where = "(r.approver_username = ? OR (r.approver_username IS NULL AND r.requested_by_username IN ($ph)))";
                $appr_params = array_merge([$appr_username], $appr_sub_usernames);
            }
        }

        $stmt = $pdo->prepare("SELECT r.*, p.item_description, p.canvassing_no, p.assigned_pic,
                                      p.ticket_type, p.po_number, p.p_group, p.requestor_name,
                                      p.pr_date, p.canvassing_date_approver_1
                               FROM sla_revision_requests r
                               LEFT JOIN prs p ON p.ticket_no = r.ticket_no AND p.ticket_line_item = r.ticket_line_item
                               WHERE $appr_where ORDER BY r.created_at ASC, r.id ASC LIMIT 2000");
        $stmt->execute($appr_params);
        $appr_all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // One PIC submission covering 8 line items is 8 rows here — fold them
        // back into the submission they came from, the same rule the History
        // modal on this page already uses (sla_rev_group_key()).
        $appr_groups = [];
        foreach ($appr_all as $r) {
            $k = sla_rev_group_key($r);
            if (!isset($appr_groups[$k])) $appr_groups[$k] = ['head' => $r, 'lines' => []];
            $appr_groups[$k]['lines'][] = $r;
        }

        // Oldest-first: the tab strip is client-side now, so every group is
        // emitted once and JS decides what to show. Order here only matters
        // for the default (pending) view, so oldest-first still surfaces the
        // longest-waiting request first.
        usort($appr_groups, function ($a, $b) {
            return strcmp((string)$a['head']['created_at'], (string)$b['head']['created_at']);
        });

        foreach ($appr_groups as $g) {
            $r     = $g['head'];
            $pairs = sla_rev_change_pairs($r);

            $lines  = [];
            $search = strtolower($r['ticket_no'] . ' ' . $r['requested_by_name'] . ' ' . sla_rev_remarks_text($r));
            foreach ($g['lines'] as $ln) {
                $ln_pairs = sla_rev_change_pairs($ln);
                $lines[] = [
                    'id'    => (int)$ln['id'],
                    'line'  => (string)$ln['ticket_line_item'],
                    'item'  => (string)($ln['item_description'] ?? ''),
                    'cno'   => (string)($ln['canvassing_no'] ?? ''),
                    'pic'   => (string)($ln['assigned_pic'] ?? ''),
                    'po'    => (string)($ln['po_number'] ?? ''),
                    // Current PR Date / Canvassing Date Approver 1 on this line, for
                    // the approver's context — independent of what the request itself
                    // is changing (a status override changes neither).
                    'pr'    => slareq_fmt_date($ln['pr_date'] ?? ''),
                    'cv'    => slareq_fmt_date($ln['canvassing_date_approver_1'] ?? ''),
                    // What field(s) this request actually changes, e.g. "SLA Status"
                    // or "PR Date, Canvassing Date" for a combined date adjustment.
                    'changed' => implode(', ', array_column($ln_pairs, 'label')),
                    'pairs' => $ln_pairs,
                ];
                $search .= ' ' . strtolower($ln['ticket_line_item'] . ' ' . ($ln['item_description'] ?? ''));
            }

            $appr_details[] = [
                'id'        => (int)$r['id'],
                'ticket'    => (string)$r['ticket_no'],
                'lines'     => $lines,
                'type'      => sla_rev_type_label($r['request_type']),
                'status'    => (string)$r['status'],
                'status_lb' => sla_rev_status_label($r['status']),
                'tt'        => (string)($r['ticket_type'] ?? ''),
                'pg'        => (string)($r['p_group'] ?? ''),
                'reqtor'    => (string)($r['requestor_name'] ?? ''),
                'by'        => (string)$r['requested_by_name'],
                'at'        => slareq_fmt_datetime($r['created_at']),
                'at_iso'    => (string)$r['created_at'],
                'dec_at_iso' => (string)($r['decided_at'] ?? ''),
                'approver'  => (string)($r['approver_name'] ?? ''),
                'pairs'     => $pairs,
                'value_to'  => slareq_value_cell($pairs, 'to'),
                'remarks'   => sla_rev_remarks_text($r),
                'evidence'  => (string)($r['evidence_url'] ?? ''),
                'dec_by'    => (string)($r['decided_by_name'] ?? ''),
                'dec_at'    => !empty($r['decided_at']) ? slareq_fmt_datetime($r['decided_at']) : '',
                'dec_notes' => (string)($r['decision_notes'] ?? ''),
                'search'    => $search,
            ];
        }
    } catch (PDOException $e) {
        error_log('sla_report.php (approvals): ' . $e->getMessage());
        $appr_details = [];
        $appr_load_error = 'Failed to load the request list. Please reload the page in a moment.';
    }
}
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<div class="mb-6 flex justify-between items-end border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">SLA Lead Time Tracking</h2>
        <p class="text-sm text-gray-600 flex items-center gap-2">
            Performance tracking matrix. Current routing architecture: 
            <span class="font-bold uppercase bg-indigo-100 text-indigo-800 px-2 py-0.5 rounded text-xs"><?= htmlspecialchars($mode) ?> MAPPING</span>
        </p>
    </div>
    <div class="flex gap-2">
        <?php if (isset($_SESSION['role']) && ($_SESSION['role'] === 'Category Manager' || in_array($_SESSION['username'] ?? '', ['adonis.sutanto', 'teofilus.franky', 'hadi.risali']))): ?>
            <a href="settings_sla.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg text-sm shadow transition">Configure Settings</a>
        <?php endif; ?>
        <?php
        // PIC now reaches this page too, so "Back" can no longer be hardcoded to
        // the Manager command center — that would just bounce them.
        $sla_back_link = 'dashboard_pic.php';
        $sla_role = $_SESSION['role'] ?? '';
        if (in_array($sla_role, ['Category Manager', 'Manager'], true)) {
            $sla_back_link = 'dashboard_mgr.php';
        } elseif ($sla_role === 'Technical Department') {
            $sla_back_link = 'dashboard_tech.php';
        }
        ?>
        <a href="<?= $sla_back_link ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow transition">Back</a>
    </div>
</div>

<!-- Shared, hidden-by-default error banner for the ajax_sla_*.php endpoints (chart /
     item list) — surfaces a short message instead of failing silently. -->
<!-- sticky: the table it reports on sits far below this point in the page, so a
     statically-placed banner is off-screen exactly when it matters. -->
<div id="slaAjaxError" class="hidden sticky top-2 z-40 mb-4 bg-red-50 border border-red-200 text-red-700 text-xs font-semibold px-4 py-2 rounded-lg shadow-sm break-words"></div>

<?php
// Full names keyed by acronym, purely for the legend-as-filter labels below.
// $unique_teams (from get_sla_computed_rows()) already holds the DB-derived acronyms
// via $acronym_map in sla_helper.php, so any team not in this list still renders
// (with its raw name as its own description) instead of silently disappearing.
$acronym_legend = [
    'CSGSM' => 'Corporate Services, General Supplies & Marketing',
    'TIGS'  => 'Technology, Industrial, and General Services',
    'FNBOM' => 'FNB and Operational Merchandise',
    'ADMIN' => 'Admin Team',
    'PKR'   => 'Project Konstruksi Retail',
    'PKP'   => 'Project Konstruksi Properti',
    'MFM'   => 'Maintenance & Facility Management',
];
?>
<div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
    <h3 class="text-xs font-bold text-gray-600 uppercase mb-2">Team Acronym Legend <span class="font-normal normal-case text-gray-400">(click to filter)</span></h3>
    <div class="flex flex-wrap gap-4 text-xs text-gray-700">
        <?php foreach ($unique_teams as $team): ?>
            <button type="button" class="team-filter-btn legend-team-btn text-left bg-gray-50 px-3 py-1.5 rounded border border-gray-100 hover:bg-indigo-50 hover:border-indigo-200 transition focus:outline-none opacity-100" data-team="<?= htmlspecialchars($team) ?>">
                <strong class="text-indigo-700"><?= htmlspecialchars($team) ?>:</strong> <?= htmlspecialchars($acronym_legend[$team] ?? $team) ?>
            </button>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
        <div class="flex justify-between items-start mb-2">
            <h3 class="text-sm font-bold text-gray-700">Current SLA Status by <span id="barDimLabel">Team</span> <span id="barScaleLabel">(100% Scale)</span></h3>
            <div class="flex items-center gap-2">
                <select id="barDimSel" class="text-xs font-bold border border-gray-300 rounded-lg px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300" aria-label="Bar chart grouping dimension">
                    <option value="taxonomy">Taxonomy</option>
                    <option value="team" selected>Team</option>
                    <!-- PIC is no longer a grouping choice here: it now appears on its
                         own card BELOW this one (#picBarCard), automatically, as soon as
                         a team is selected — a drill-down instead of a mode switch.
                    <option value="pic">PIC</option>
                    -->
                </select>
                <select id="barTaxLevel" class="text-xs border border-gray-300 rounded px-2 py-1 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300 hidden">
                    <option value="l0">Level 0</option>
                    <option value="l1">Level 1</option>
                    <option value="l2">Level 2</option>
                    <option value="l3" selected>Level 3</option>
                </select>
                <!-- Bar order, split in two: WHAT to rank by (#barSortBy) and WHICH WAY
                     (#barSortDir). Ties always fall back to A–Z so equal values keep a
                     stable, predictable order instead of shuffling between requests.
                     #% has no meaning without a reference status — every bar is on a 100%
                     scale, so its own total is always 100. It therefore ranks by the share
                     of the status currently filtered, defaulting to SLA Breached. -->
                <select id="barSortBy" class="text-xs border border-gray-300 rounded px-2 py-1 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300" aria-label="Sort bars by ticket count or percentage">
                    <option value="n" selected title="Rank by total tickets">#n</option>
                    <option value="pct" title="Rank by share of the filtered status (default: SLA Breached)">#%</option>
                </select>
                <!-- Direction sits last, after the metric it modifies, so the row reads
                     left-to-right as one sentence: group by Team, rank by #n, descending.
                     It is a 2-state toggle, so one icon button beats a 2-option dropdown.
                     The arrow is swapped in JS; title/aria-label are swapped with it,
                     otherwise the icon alone tells a screen reader nothing. -->
                <button type="button" id="barSortDir" data-dir="desc"
                        class="text-xs border border-gray-300 rounded px-2 py-1 bg-white text-gray-600 hover:bg-gray-50 hover:text-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-300 transition"
                        title="Sorted highest first — click for lowest first" aria-label="Sort direction: descending">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                        <path id="barSortDirIcon" stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h5.25M17.25 9v12M13.5 17.25L17.25 21l3.75-3.75"/>
                    </svg>
                </button>
            </div>
        </div>
        <p class="text-xs text-gray-500 mb-4">Click any bar segment to filter the table below. <span id="barModeCaption" class="font-semibold text-indigo-600"></span></p>
        <div class="relative h-64">
            <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            </div>
            <canvas id="barChart"></canvas>
        </div>
    </div>
    <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200">
        <div class="flex justify-between items-start mb-2">
            <h3 class="text-sm font-bold text-gray-700">SLA Achievement Trend (%)</h3>
            <div class="flex bg-gray-100 border border-gray-300 rounded-lg p-0.5" role="group" aria-label="Trend period granularity">
                <button type="button" class="trend-gran-btn text-xs font-bold px-3 py-1 rounded-md transition focus:outline-none bg-indigo-600 text-white" data-gran="week">Week</button>
                <button type="button" class="trend-gran-btn text-xs font-bold px-3 py-1 rounded-md transition focus:outline-none text-gray-600 hover:text-gray-900" data-gran="month">Month</button>
                <button type="button" class="trend-gran-btn text-xs font-bold px-3 py-1 rounded-md transition focus:outline-none text-gray-600 hover:text-gray-900" data-gran="year">Year</button>
            </div>
        </div>
        <p class="text-xs text-gray-500 mb-4">Percentage of evaluated POs created within target SLA, <span id="trendRangeLabel">last 8 weeks (including this week)</span>.</p>
        <div class="relative h-64">
            <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            </div>
            <canvas id="lineChart"></canvas>
        </div>
    </div>
</div>

<!-- PIC DRILL-DOWN: hidden until at least one team is selected (legend button or a click
     on a team bar above). The data behind it is the SAME globally-filtered set as the bar
     chart, re-grouped by assigned_pic, so it always shows exactly the PICs of the selected
     team(s). Clicking a segment toggles that PIC into the global filter, like the old
     "PIC" grouping mode did. -->
<div id="picBarCard" class="hidden mb-6 bg-white px-4 py-3 rounded-lg shadow-sm border border-gray-200">
    <div class="flex justify-between items-center gap-2">
        <div class="min-w-0">
            <h3 class="text-sm font-bold text-gray-700 truncate">SLA Status by PIC — <span id="picBarTeams" class="text-indigo-700"></span> <span id="picBarScaleLabel">(100% Scale)</span></h3>
            <p class="text-xs text-gray-500">Click a bar segment to filter the table by that PIC; click it again to clear. <span id="picBarCaption" class="font-semibold text-indigo-600"></span></p>
        </div>
        <!-- Dismisses the card without touching the team selection above. It comes
             back on the next team change (see picCardDismissed in the script). -->
        <button type="button" id="picBarClose" title="Close this breakdown" aria-label="Close PIC breakdown"
                class="shrink-0 text-gray-400 hover:text-red-600 hover:bg-red-50 border border-transparent hover:border-red-200 rounded-lg p-1.5 focus:outline-none transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <!-- Deliberately shorter than the two charts above (h-64): this card is an extra row
         on an already long page, so it is sized to read at a glance, not to match them. -->
    <div class="relative h-[150px] mt-2">
        <canvas id="picBarChart"></canvas>
    </div>
</div>

<!-- DISTINCT ITEM LIST: every distinct item_description in the (globally filtered) data,
     with its PR-line count. Searchable; follows all global filters including status.
     Collapsible: header always shows the "shown / overall total" counter; the search box
     and the list itself live in #itemListPanel, hidden by default to save space. -->
<div class="mb-6 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
    <button type="button" id="itemListToggle" class="w-full flex flex-wrap justify-between items-center gap-2 text-left focus:outline-none">
        <div>
            <h3 class="text-sm font-bold text-gray-700 flex items-center gap-2">
                <svg id="itemListChevron" class="w-4 h-4 text-gray-500 transition-transform -rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                Distinct Item List
            </h3>
            <p class="text-xs text-gray-500">
                <span id="itemShownCount">0</span> / <span id="itemTotalCount">0</span> distinct items (total keseluruhan) — count = PR lines. Follows the global filters.
            </p>
        </div>
        <span class="text-xs text-indigo-600 font-semibold" id="itemListToggleLabel">Show list</span>
    </button>
    <div id="itemListPanel" class="hidden mt-3">
        <div class="mb-3">
            <input type="text" id="itemSearch" placeholder="Search item..."
                   class="text-xs border border-gray-300 rounded px-3 py-1.5 w-64 max-w-full focus:outline-none focus:ring-2 focus:ring-indigo-300">
        </div>
        <div class="border border-gray-100 rounded overflow-hidden">
            <!-- Sortable header: click a column to sort by it, click again to flip direction. -->
            <div class="flex items-center gap-3 px-3 py-1.5 bg-gray-50 border-b border-gray-200 text-[10px] font-bold text-gray-500 uppercase" style="padding-right: calc(0.75rem + 16px);">
                <button type="button" class="item-sort-btn flex-1 min-w-0 text-left flex items-center gap-1 hover:text-indigo-600 focus:outline-none" data-col="name">Item <span class="item-sort-arrow"></span></button>
                <button type="button" class="item-sort-btn shrink-0 w-24 text-center flex items-center justify-center gap-1 hover:text-indigo-600 focus:outline-none" data-col="count">N/Total <span class="item-sort-arrow inline-block w-3 shrink-0 text-center"></span></button>
                <button type="button" class="item-sort-btn shrink-0 w-14 text-center flex items-center justify-center gap-1 hover:text-indigo-600 focus:outline-none" data-col="pct">% <span class="item-sort-arrow inline-block w-3 shrink-0 text-center"></span></button>
            </div>
            <div id="itemListBody" class="max-h-64 overflow-y-auto" style="scrollbar-gutter: stable;"></div>
        </div>
    </div>
</div>

<div class="mb-4 bg-white p-4 rounded-lg shadow-sm border border-gray-200">
    <div class="text-xs font-bold text-gray-600 uppercase mb-3">Filter by Date Range (TR Date Technical)</div>
    <div class="flex flex-wrap gap-2 items-end mb-4">
        <div class="flex flex-col">
            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">Quick Filter</label>
            <select id="dateQuickFilter" class="text-xs border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <option value="all" selected>All Time</option>
                <option value="this_month">This Month</option>
                <option value="last_month">Last Month</option>
                <option value="last_2_weeks">Last 2 Weeks</option>
                <option value="custom">Custom Range</option>
            </select>
        </div>
        <div id="customDateInputs" class="hidden flex-wrap gap-2 items-end">
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">From</label>
                <input type="date" id="dateFrom" class="text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-300">
            </div>
            <div class="flex flex-col">
                <label class="text-[10px] font-bold text-gray-500 uppercase mb-1">To</label>
                <input type="date" id="dateTo" class="text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-300">
            </div>
        </div>
    </div>
    <div class="text-xs font-bold text-gray-600 uppercase mb-3 border-t pt-3">Quick Filter by Team</div>
    <div class="flex flex-wrap gap-2 mb-4">
        <button type="button" class="team-filter-btn text-xs font-bold bg-gray-100 text-gray-800 border border-gray-300 px-3 py-1.5 rounded hover:bg-gray-200 transition focus:outline-none" data-team="">All Teams</button>
        <?php foreach ($unique_teams as $team): ?>
            <button type="button" class="team-filter-btn text-xs font-bold bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded hover:bg-indigo-100 transition focus:outline-none opacity-100" data-team="<?= htmlspecialchars($team) ?>">
                <?= htmlspecialchars($team) ?>
            </button>
        <?php endforeach; ?>
    </div>
    <div class="text-xs font-bold text-gray-600 uppercase mb-3 border-t pt-3">Filter by Status</div>
    <div class="flex flex-wrap gap-4 items-center">
        <button type="button" class="legend-filter-btn flex items-center space-x-2 hover:opacity-70 transition focus:outline-none opacity-100" data-status="In Progress"><span class="h-4 w-4 rounded-full bg-yellow-400 shadow-sm"></span><span class="text-sm font-bold text-gray-700">In Progress</span></button>
        <button type="button" class="legend-filter-btn flex items-center space-x-2 hover:opacity-70 transition focus:outline-none opacity-100" data-status="Within SLA"><span class="h-4 w-4 rounded-full bg-green-500 shadow-sm"></span><span class="text-sm font-bold text-gray-700">Within SLA</span></button>
        <button type="button" class="legend-filter-btn flex items-center space-x-2 hover:opacity-70 transition focus:outline-none opacity-100" data-status="SLA Breached"><span class="h-4 w-4 rounded-full bg-red-500 shadow-sm"></span><span class="text-sm font-bold text-gray-700">SLA Breached</span></button>
        <button type="button" class="legend-filter-btn flex items-center space-x-2 hover:opacity-70 transition focus:outline-none opacity-100" data-status="AON"><span class="h-4 w-4 rounded-full bg-blue-500 shadow-sm"></span><span class="text-sm font-bold text-gray-700">AON</span></button>
        <!-- Not a real statusName value: matches every line the SLA Revision workflow
             touched — an approved status override (flagged by the "Escalated" table
             column), an approved date override, or a request that was REJECTED.
             Rejected lines look untouched otherwise, so this legend is the only way
             to reach them. One definition, server-side: sla_rev_is_adjusted_line(). -->
        <button type="button" class="legend-filter-btn flex items-center space-x-2 hover:opacity-70 transition focus:outline-none opacity-100" data-status="Adjusted"><span class="h-4 w-4 rounded-full bg-purple-500 shadow-sm"></span><span class="text-sm font-bold text-gray-700">Escalated</span></button>
        <button type="button" id="clearAllBtn" class="text-xs text-indigo-600 underline font-semibold ml-4 focus:outline-none">Clear All Filters</button>
    </div>
    <div class="flex items-center justify-between border-t pt-3 mt-4 mb-3">
        <div class="text-xs font-bold text-gray-600 uppercase">Filter by Taxonomy</div>
        <div class="flex items-center gap-3">
            <input type="text" id="slaGlobalSearch" placeholder="Search data..." class="text-xs border border-gray-300 rounded px-3 py-1.5 w-64 focus:outline-none focus:ring-2 focus:ring-indigo-300 bg-white shadow-sm">
            <?php if (($_SESSION['role'] ?? '') === 'Category Manager'): ?>
            <button type="button" id="mapTaxonomyAIBtn" class="hidden flex items-center gap-2 bg-purple-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-purple-700 transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                Map Taxonomy (AI)
            </button>
            <?php endif; ?>
            <div class="relative" id="exportDropdownContainer">
                <button type="button" id="exportBtn" class="flex items-center gap-2 bg-indigo-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg hover:bg-indigo-700 transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Export
                </button>
                <div id="exportMenu" class="hidden absolute right-0 mt-2 w-40 bg-white rounded-md shadow-lg border border-gray-200 z-50">
                    <div class="px-4 py-2 border-b border-gray-100">
                        <label class="text-xs font-semibold text-gray-600 flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" id="exportAllPages" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                            All Pages
                        </label>
                    </div>
                    <div class="py-1">
                        <button type="button" id="slaExportExcel" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                        <button type="button" id="slaExportCSV" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                        <button type="button" id="slaExportPDF" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Fixed 5-col grid + w-full/truncate so a long option label (e.g. an L4 SKU name)
         can't stretch its select and break the row layout. -->
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <?php foreach (['0' => 'Level 0', '1' => 'Level 1', '2' => 'Level 2', '3' => 'Level 3', '4' => 'Level 4'] as $lvl => $lbl): ?>
        <div class="flex flex-col min-w-0">
            <label class="text-[10px] font-bold text-gray-500 uppercase mb-1"><?= $lbl ?></label>
            <select data-level="<?= $lvl ?>" class="tax-filter w-full min-w-0 truncate text-xs border border-gray-300 rounded px-2 py-1.5 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-300">
                <option value="">All</option>
            </select>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- pt-3 overrides p-6 on the top edge only: the "SLA Records"/Manage SLA Request
     row sits directly against it, and 24px above a 28px-tall button reads as a gap.
     The other three sides stay at 24px so this card still lines up with the ones above. -->
<div class="bg-white shadow-md rounded-lg p-6 pt-3 border border-gray-200 relative">
    <div class="viz-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80 rounded-lg">
        <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        <p class="mt-3 text-xs font-semibold text-gray-500">Loading records…</p>
    </div>
    <div class="flex items-center justify-between gap-3 mb-3">
        <h3 class="text-xs font-bold text-gray-600 uppercase">SLA Records</h3>
        <div class="flex items-center gap-2">
        <?php if ($slarep_can_bulk): ?>
        <!-- Bulk SLA Request (CSV): opens #slaBulkModalBg below. Outlined so it
             reads as secondary next to the solid Manage SLA Request button. -->
        <button type="button" id="slaBulkBtn"
           class="inline-flex items-center gap-2 bg-white hover:bg-indigo-50 text-indigo-700 border border-indigo-300 text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 12v9m0-9l3 3m-3-3l-3 3"></path></svg>
            Bulk SLA Request
        </button>
        <?php endif; ?>
        <?php if ($slarep_is_approver): ?>
        <!-- Opens the SLA Approvals modal below instead of navigating to a
             separate page. Sized like the Export button in the card above so
             the two read as the same kind of control. The count is already
             resolved by header.php. Badge sits on the button's own top-left
             corner (phone-app notification style) instead of inline text, so
             it reads as "N pending" at a glance without competing with the label. -->
        <button type="button" id="slaApprInboxBtn"
           class="relative inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
            <?php if (!empty($sla_pending_count)): ?>
                <span class="absolute -top-1.5 -left-1.5 min-w-[18px] h-[18px] px-1 flex items-center justify-center bg-red-500 text-white text-[10px] font-bold rounded-full leading-none"><?= (int)$sla_pending_count ?></span>
            <?php endif; ?>
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.4-3.4a4 4 0 01-5.4 5.4L6.5 20.5a1.5 1.5 0 01-2.1-2.1L13.6 9.2a4 4 0 015.4-5.4l-3.4 3.4z"></path></svg>
            Manage SLA Request
        </button>
        <?php endif; ?>
        </div>
    </div>

    <!-- STAGED CHANGES BAR. Hidden until the first cell is edited, then sticks to
         the top of the viewport while the user works down the table — the whole
         point is that it stays reachable after a dozen edits and several pages.
         Renders for every role: whether a cell can be edited at all is decided
         per row by the server (can_ed / can_es), not here. -->
    <div id="slaEditBar" class="hidden sticky top-0 z-30 mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 px-4 py-2.5 shadow-sm">
        <div class="flex items-center gap-2 text-xs text-amber-900">
            <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
            <span id="slaEditBarText" class="font-bold"></span>
            <span class="hidden sm:inline text-amber-700">— not submitted yet.</span>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="slaDiscardBtn"
                    class="text-xs font-bold px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 transition">
                Discard Changes
            </button>
            <button type="button" id="slaApplyBtn"
                    class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow-sm transition">
                Apply Changes
            </button>
        </div>
    </div>
    <!-- The adjustment-workflow columns pushed this table past the container
         width. It scrolls horizontally instead of squeezing every column until
         dates and durations wrap onto three lines. -->
    <style>
        /* Line only ever holds a short number, so it does not need to be as wide
           as the label "Line Item" once was (~95px). !important because
           DataTables' autoWidth writes an inline width, and box-sizing is spelled
           out because these cells default to content-box — without it the 26px of
           padding is added ON TOP of the width and the column stays wide. */
        #slaTable th.sla-line-col,
        #slaTable td.sla-line-col {
            box-sizing: border-box;
            width: 56px !important;
            min-width: 56px;
            max-width: 56px;
            padding-left: 8px !important;
            padding-right: 18px !important;
        }

        /* Item Description and Canvassing Description are free text and can run
           for a paragraph. Clamped to two lines so one wordy row can't stretch
           the whole table; the full text is still one hover away (the span
           carries it as its title). max-width is what actually forces the wrap
           — without it the cell just grows. */
        #slaTable td .sla-clamp-2 {
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            overflow: hidden;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        /* Hover panel for the clamped cells above. The native title= tooltip was
           unusable for a paragraph of text: ~1s delay, OS-styled, no wrapping
           control, and it vanishes after a few seconds. This one is instant,
           readable, and matches the rest of the page. Positioned with
           position:fixed so it needs no scroll-offset maths, and pinned to the
           body so an overflow:hidden ancestor can never clip it. */
        #slaCellTip {
            position: fixed;
            z-index: 9999;
            max-width: 26rem;
            padding: 0.5rem 0.625rem;
            border-radius: 0.375rem;
            background: #1f2937;           /* gray-800, same as the table's other dark chips */
            color: #f9fafb;
            font-size: 11px;
            line-height: 1.5;
            white-space: pre-wrap;         /* keeps any newline the source text had */
            overflow-wrap: anywhere;
            box-shadow: 0 8px 20px -4px rgba(0, 0, 0, .35), 0 0 0 1px rgba(255, 255, 255, .06);
            opacity: 0;
            transform: translateY(-2px);
            transition: opacity .12s ease, transform .12s ease;
            pointer-events: none;          /* never steals the hover from the cell */
            display: none;
        }
        #slaCellTip.is-visible { opacity: 1; transform: translateY(0); }

        /* The leading STT work-type code, called out so the reader can see at a
           glance which SLA rule this line is on. */
        #slaCellTip .tip-code {
            display: inline-block;
            margin-bottom: 0.25rem;
            padding: 0 0.3rem;
            border-radius: 0.1875rem;
            background: #4f46e5;           /* indigo-600 */
            color: #fff;
            font-weight: 700;
            font-size: 10px;
            letter-spacing: .03em;
        }

        /* Only the clamped cells are hoverable targets; the cursor says so. */
        #slaTable td .sla-clamp-2.is-clamped { cursor: help; }

        /* INLINE EDIT CELLS.
           A plain editable-but-untouched cell carries no permanent marker at all
           — cursor: cell and the hover highlight are enough to say "you can
           click this". The corner triangle (an Excel-comment-style border trick,
           no background image needed) is reserved for the two states that carry
           actual information worth flagging even when the cell isn't hovered:
             red  = edited, staged, NOT submitted
             blue = already waiting for approval (locked) */
        #slaTable td.sla-cell { position: relative; }
        #slaTable td.sla-cell::after {
            content: '';
            position: absolute;
            top: 0; right: 0;
            width: 0; height: 0;
            border-top: 7px solid transparent;
            border-right: 7px solid transparent;
        }
        #slaTable td.sla-editable { cursor: cell; }
        /* Hover has to beat TWO things to be visible at all: every row already carries
           the hover:bg-indigo-50 utility (so an indigo-50 cell highlight was invisible
           — the row had already turned that exact color), and that utility wins on
           specificity anyway, hence !important.
           A deeper shade of the row's own blue, not a different hue: it has to be dark
           enough to read as a state next to an indigo-50 row, which is why it goes to
           indigo-200 with a full indigo-600 ring rather than one step down. The text
           darkens too, so the cue survives if a background is ever overridden.
           :not(.sla-staged) because a staged cell keeps .sla-editable so it can be
           reopened — its amber "you changed this" background must not be overwritten
           by a plain hover. */
        #slaTable td.sla-editable:not(.sla-staged):hover {
            background-color: #c7d2fe !important;                  /* indigo-200 */
            box-shadow: inset 0 0 0 2px #4f46e5;                   /* indigo-600 */
            color: #3730a3;                                        /* indigo-800 */
        }

        #slaTable td.sla-staged {
            background-color: #fef3c7 !important;                        /* amber-100 */
            box-shadow: inset 0 0 0 1px #f59e0b;                         /* amber-500 */
        }
        #slaTable td.sla-staged::after    { border-top-color: #dc2626; } /* red-600 */
        #slaTable td.sla-staged .sla-was {
            display: block;
            font-size: 10px;
            font-weight: 400;
            color: #92400e;                                              /* amber-800 */
            text-decoration: line-through;
        }

        #slaTable td.sla-pending {
            background-color: #eff6ff !important;                        /* blue-50 */
            cursor: help;
        }
        #slaTable td.sla-pending::after   { border-top-color: #2563eb; } /* blue-600 */

        /* Whole row has at least one staged (unsubmitted) edit, not just the cell
           being edited — lighter than the cell's own amber-100 so the actually
           edited cell(s) still stand out against the rest of the row. !important
           to beat the tr:hover:bg-indigo-50 utility already on every row. */
        #slaTable tbody tr.sla-row-staged { background-color: #fffbeb !important; } /* amber-50 */

        /* The comment popover. Fixed-position so it is never clipped by the
           table's own overflow-x container. */
        #slaEditPop { width: 320px; }
    </style>
    <table id="slaTable" class="min-w-full leading-normal text-left">
        <thead>
            <tr class="bg-gray-100 border-b">
                <?php if (($_SESSION['role'] ?? '') === 'Category Manager'): ?>
                <th class="p-3 text-center w-10">
                    <input type="checkbox" id="selectAllCheckbox" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer">
                </th>
                <?php endif; ?>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Ticket No</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Escalated</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">SLA Status</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Duration / Target</th>
                <th class="px-2 py-3 text-xs font-bold text-gray-600 uppercase sla-line-col">Line</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Item Description</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Ticket Type</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Assigned PIC</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Assigned Team</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">TR Date Technical</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Canvassing No</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Canvassing Date</th><!-- hidden on screen (stacked under Canvassing No); its own column in exports -->
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Canvassing Date Approver 1</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Canvassing Description</th><!-- its leading [PR]/[PP]/[PM]/[OS] code sets the SLA target -->
                <!-- Taxonomy columns appended after Canvassing Description, before History, so their DataTables indices stay grouped at the tail. L0/L1/L2/L4 are hidden; only L3 is shown. -->
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Level 0</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Level 1</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Level 2</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Taxonomy (L3)</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">Level 4</th>
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">TR Date Technical ISO</th><!-- hidden; YYYY-MM-DD for the date-range filter -->
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">POrg</th><!-- hidden -->
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">PGroup</th><!-- hidden -->
                <!-- Adjustment workflow, appended last so every index above stays
                     valid. The request status / requester / approver / timestamp
                     all live in the View History modal instead of their own
                     columns, which kept the table narrow enough to read. -->
                <th class="p-3 text-xs font-bold text-gray-600 uppercase whitespace-nowrap">History</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>

<!-- SLA CHATBOT: floating widget. Questions are answered by Gemini from a compact JSON
     summary of the CURRENTLY FILTERED data (built server-side from the active filters),
     so answers follow the global filters. Backend: ajax_sla_chat.php. -->
<button type="button" id="slaChatToggle"
        class="fixed bottom-6 right-6 z-40 bg-indigo-600 hover:bg-indigo-700 text-white rounded-full w-14 h-14 shadow-lg flex items-center justify-center text-2xl transition focus:outline-none"
        title="Tanya data SLA">💬</button>

<div id="slaChatPanel" class="hidden fixed bottom-24 right-6 z-40 w-96 max-w-[calc(100vw-3rem)] bg-white rounded-lg shadow-2xl border border-gray-200 flex flex-col" style="height: 28rem;">
    <div class="flex justify-between items-center px-4 py-3 border-b bg-indigo-600 rounded-t-lg">
        <div>
            <h3 class="text-sm font-bold text-white">SLA Assistant</h3>
            <p class="text-[10px] text-indigo-200">Jawaban singkat dari data yang sedang tampil (mengikuti filter aktif)</p>
        </div>
        <button type="button" id="slaChatClose" class="text-indigo-200 hover:text-white text-lg leading-none focus:outline-none">&times;</button>
    </div>
    <div id="slaChatMessages" class="flex-1 overflow-y-auto p-3 space-y-2 text-sm">
        <div class="bg-gray-100 text-gray-700 rounded-lg px-3 py-2 text-xs max-w-[85%]">
            Halo! Tanyakan apa saja soal data SLA di halaman ini, misalnya: <em>"Team mana yang paling banyak breach?"</em> atau <em>"Berapa achievement bulan ini?"</em>
        </div>
    </div>
    <form id="slaChatForm" class="flex gap-2 p-3 border-t">
        <input type="text" id="slaChatInput" maxlength="500" placeholder="Ketik pertanyaan..."
               class="flex-1 text-xs border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-300" autocomplete="off">
        <button type="submit" id="slaChatSend"
                class="bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white text-xs font-bold px-4 py-2 rounded transition focus:outline-none">Kirim</button>
    </form>
</div>

<!-- Taxonomy Mapping Modal (Single) -->
<div id="taxonomyModalBg" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[100] hidden flex items-center justify-center backdrop-blur-sm">
    <div id="taxonomyModal" class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden hidden transform transition-all">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <h3 class="text-lg font-bold text-gray-800">Map Taxonomy to Master Data</h3>
            <button id="closeTaxonomyBtn" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-6">
            <input type="hidden" id="taxTicketNoInput">
            <input type="hidden" id="taxTicketLineItemInput">
            
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Ticket / Line Item</label>
                <div class="text-gray-900 font-medium bg-gray-50 p-2 rounded border border-gray-200">
                    <span id="taxTicketNo"></span> / <span id="taxTicketLineItemInputDisplay"></span>
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Item Description</label>
                <div id="taxItemDesc" class="text-gray-800 text-sm bg-blue-50 p-3 rounded border border-blue-100 whitespace-pre-wrap"></div>
            </div>

            <div class="mb-6">
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Select Master Data (SKU)</label>
                <select id="taxMasterDataId" class="w-full text-sm border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <!-- Populated via AJAX -->
                </select>
                <p id="taxPathDisplay" class="mt-2 text-xs text-gray-500 italic"></p>
            </div>
            
            <div id="tax-modal-error" class="hidden mb-4 p-3 bg-red-50 text-red-700 text-sm rounded border border-red-200"></div>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="cancelTaxonomyBtn" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                    Cancel
                </button>
                <button type="button" id="saveTaxonomyBtn" class="inline-flex items-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                    Save Mapping
                </button>
            </div>
        </div>
    </div>
</div>

<!-- AI Batch Mapping Review Modal -->
<div id="aiReviewModalBg" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[100] hidden flex items-center justify-center backdrop-blur-sm">
    <div id="aiReviewModal" class="bg-white rounded-xl shadow-2xl w-full max-w-5xl overflow-hidden hidden transform transition-all flex flex-col max-h-[90vh]">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-indigo-50">
            <div>
                <h3 class="text-lg font-bold text-indigo-900">Review AI Taxonomy Mapping</h3>
                <p class="text-sm text-indigo-700">Please review the suggested mappings. You can change them if needed before saving.</p>
            </div>
            <button id="closeAiReviewBtn" class="text-gray-400 hover:text-gray-600 transition-colors">
                <i class="fas fa-times text-xl"></i>
            </button>
        </div>
        <div class="p-0 overflow-y-auto flex-1">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50 sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-24">Ticket</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-16">Line</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-1/3">Item Description</th>
                        <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">Suggested Master Data (SKU)</th>
                    </tr>
                </thead>
                <tbody id="aiReviewTableBody" class="bg-white divide-y divide-gray-200">
                    <!-- Populated via JS -->
                </tbody>
            </table>
        </div>
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-between items-center">
            <div id="ai-review-modal-error" class="hidden p-2 bg-red-50 text-red-700 text-sm rounded border border-red-200 flex-1 mr-4"></div>
            <div class="flex justify-end gap-3 ml-auto">
                <button type="button" id="cancelAiReviewBtn" class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    Cancel
                </button>
                <button type="button" id="saveAiReviewBtn" class="inline-flex items-center px-6 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 transition-colors">
                    Confirm & Save All
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SLA APPROVALS INBOX (was pages/sla_requests.php): Manager approval inbox for
     SLA adjustment requests submitted by PICs, opened via the "Manage SLA Request"
     button above. Tab/search filtering is client-side — every request the
     approver can see is embedded once as apprDetails, not re-fetched per tab. -->
<div id="slaApprModalBg" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[120] hidden items-center justify-center backdrop-blur-sm overflow-y-auto">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl my-8 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Manage SLA Request</h3>
                <p class="text-xs text-gray-500">Review SLA adjustment requests from PICs. Changes take effect only after you approve them.</p>
            </div>
            <button type="button" id="closeSlaApprBtn" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="p-6 max-h-[75vh] overflow-y-auto">
            <div id="apprLoadError" class="hidden mb-4 bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 text-sm"></div>

            <div class="mb-4 flex flex-wrap gap-2" id="apprTabs">
                <?php
                // Same palette as the staged/pending cell markers on the SLA table, so a
                // PIC and their Manager read one state the same way everywhere.
                $appr_tabs = [
                    'pending'  => ['Pending Approval', 'bg-amber-100 text-amber-800 border-amber-300 ring-2 ring-amber-200',
                                   'No request is waiting for your approval.'],
                    'approved' => ['Approved',         'bg-green-100 text-green-800 border-green-300 ring-2 ring-green-200',
                                   'No request has been approved yet.'],
                    'rejected' => ['Rejected',         'bg-red-100   text-red-800   border-red-300   ring-2 ring-red-200',
                                   'No request has been rejected yet.'],
                    'all'      => ['All',              'bg-slate-100 text-slate-800 border-slate-300 ring-2 ring-slate-200',
                                   'No request has been filed yet.'],
                ];
                foreach ($appr_tabs as $key => $meta):
                ?>
                <button type="button" class="appr-tab-btn text-xs font-bold px-4 py-2 rounded-lg border transition bg-white text-gray-600 border-gray-300 hover:bg-gray-50"
                        data-tab="<?= $key ?>" data-active-class="<?= htmlspecialchars($meta[1]) ?>" data-empty="<?= htmlspecialchars($meta[2]) ?>">
                    <?= $meta[0] ?> <span class="ml-1 appr-tab-count text-gray-400">(0)</span>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="mb-4">
                <input type="text" id="apprSearch" placeholder="Search ticket / PIC / remarks..."
                       class="w-full max-w-md text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-300">
            </div>

            <!-- Bulk bar: hidden until at least one pending request is ticked. Every
                 line item of every ticked request is decided together, in one call to
                 ajax_sla_request_decide.php — same endpoint, just a longer id list. -->
            <div id="apprBulkBar" class="hidden mb-3 flex items-center justify-between gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-4 py-2.5">
                <span id="apprBulkCount" class="text-xs font-bold text-indigo-900"></span>
                <div class="flex items-center gap-2">
                    <button type="button" id="apprBulkApproveBtn" class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5 rounded transition">Approve Selected</button>
                    <button type="button" id="apprBulkRejectBtn" class="bg-gray-500 hover:bg-gray-600 text-white text-xs font-bold px-3 py-1.5 rounded transition">Reject Selected</button>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-lg overflow-hidden">
                <div id="apprEmpty" class="hidden p-10 text-center text-sm text-gray-500"></div>
                <div id="apprTableWrap" class="overflow-x-auto">
                    <table class="min-w-full text-left">
                        <thead>
                            <tr class="bg-gray-100 border-b">
                                <th class="p-3 w-8"><input type="checkbox" id="apprSelectAll" title="Select all pending on this list"></th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">Request Type</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">PR Number</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">Requested By</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">Requested Value</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">Remarks</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">Request Date</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase">Status</th>
                                <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center whitespace-nowrap">Action</th>
                            </tr>
                        </thead>
                        <tbody id="apprTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="bg-gray-50 px-6 py-3 flex justify-end">
            <button type="button" id="apprCloseFooterBtn"
                    class="px-4 py-2 rounded-md border border-gray-300 shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Close</button>
        </div>
    </div>
</div>

<!-- Detail modal: everything about one request on one screen, and the only place
     Approve / Reject can be reached — a decision should never be made from a
     truncated table cell. -->
<!-- Single element for backdrop + centering (not a separate overlay sibling next to
     a plain wrapper): a positioned backdrop always paints ABOVE a non-positioned
     sibling regardless of DOM order, which silently ate every click through to the
     card underneath when this was split into two divs. Matches the pattern every
     other modal on this page already uses (taxonomyModalBg, slaApplyModalBg, etc). -->
<div id="slaDetailModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[130] hidden items-center justify-center overflow-y-auto p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl overflow-hidden relative">
            <div class="px-6 pt-5 pb-4 max-h-[75vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4 border-b pb-3 mb-4">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900">PR <span id="dtTicket"></span> <span id="dtLineCount" class="text-gray-400 font-normal"></span></h3>
                        <p id="dtType" class="text-xs font-semibold text-indigo-700 mt-0.5"></p>
                    </div>
                    <span id="dtStatus" class="shrink-0 text-[10px] font-bold px-2 py-1 rounded border"></span>
                </div>

                <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider mb-2">Ticket</p>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-x-4 gap-y-3 mb-5">
                    <div><span class="block text-[10px] text-gray-500 uppercase">Ticket Type</span><span id="dtTt" class="text-sm text-gray-800"></span></div>
                    <div><span class="block text-[10px] text-gray-500 uppercase">Requestor</span><span id="dtReqtor" class="text-sm text-gray-800"></span></div>
                    <div><span class="block text-[10px] text-gray-500 uppercase">P Group</span><span id="dtPg" class="text-sm text-gray-800"></span></div>
                    <div><span class="block text-[10px] text-gray-500 uppercase">Requested By</span><span id="dtBy" class="text-sm font-semibold text-gray-800"></span></div>
                    <div><span class="block text-[10px] text-gray-500 uppercase">Requested At</span><span id="dtAt" class="text-sm text-gray-800"></span></div>
                    <div><span class="block text-[10px] text-gray-500 uppercase">Routed To</span><span id="dtApprover" class="text-sm text-gray-800"></span></div>
                </div>

                <div class="flex items-end justify-between gap-3 mb-2">
                    <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Line Items</p>
                    <!-- Only on a pending submission: the tick decides which lines the
                         Approve / Reject below applies to. On a decided one the list is
                         a record, so the column is not rendered at all. -->
                    <p id="dtPickHint" class="hidden text-[11px] text-gray-500"></p>
                </div>
                <div class="rounded border border-gray-200 mb-4 max-h-64 overflow-auto">
                    <table class="min-w-full text-left">
                        <thead><tr class="bg-gray-100 border-b border-gray-200">
                            <th class="p-2 w-8 dt-pick-col"><input type="checkbox" id="dtToggleAll" title="Select all"></th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Line</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Item</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Canvassing No</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">PR Date</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Canvassing Date Approver 1</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Current Value</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Changed Field</th>
                            <th class="p-2 text-[10px] font-bold text-gray-600 uppercase">Requested Value</th>
                        </tr></thead>
                        <tbody id="dtLines"></tbody>
                    </table>
                </div>

                <div class="mb-4">
                    <span class="block text-[10px] text-gray-500 uppercase mb-1">Remarks</span>
                    <p id="dtRemarks" class="text-sm text-gray-800 whitespace-pre-wrap"></p>
                    <p id="dtEvidenceWrap" class="mt-1 hidden">
                        <a id="dtEvidence" href="#" target="_blank" rel="noopener noreferrer" class="text-sm text-indigo-600 hover:underline break-all">Open evidence link</a>
                    </p>
                </div>

                <!-- Only on a pending submission: approve/reject right here, no second
                     modal. One label for both buttons: approving and rejecting BOTH
                     require a written note of at least 10 characters, so the wording
                     no longer has to switch per action. -->
                <div id="dtNotesWrap" class="mb-4">
                    <label class="block text-[10px] font-bold text-gray-500 uppercase mb-1">
                        <span id="dtNotesLabel">Decision Notes (required, min. 10 characters)</span>
                    </label>
                    <textarea id="dtNotes" rows="3" maxlength="1000"
                              class="w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-300"
                              placeholder="Write your notes here..."></textarea>
                    <p id="dtNotesError" class="hidden text-[11px] text-red-600 mt-1"></p>
                </div>

                <div id="dtDecisionWrap" class="hidden rounded-lg border p-3">
                    <p class="text-[10px] font-semibold uppercase tracking-wider mb-1">Decision</p>
                    <p class="text-sm"><span id="dtDecBy" class="font-semibold"></span> <span class="text-gray-500">on</span> <span id="dtDecAt"></span></p>
                    <p id="dtDecNotes" class="text-sm text-gray-700 whitespace-pre-wrap mt-1"></p>
                </div>
            </div>
            <div class="bg-gray-50 px-6 py-3 sm:flex sm:flex-row-reverse gap-2">
                <button type="button" id="dtApproveBtn"
                        class="hidden w-full sm:w-auto justify-center px-4 py-2 rounded-md shadow-sm text-sm font-bold text-white bg-green-600 hover:bg-green-700 transition">Approve</button>
                <button type="button" id="dtRejectBtn"
                        class="hidden mt-2 sm:mt-0 w-full sm:w-auto justify-center px-4 py-2 rounded-md shadow-sm text-sm font-bold text-white bg-gray-600 hover:bg-gray-700 transition">Reject</button>
                <button type="button" id="dtCloseBtn"
                        class="mt-2 sm:mt-0 w-full sm:w-auto inline-flex justify-center px-4 py-2 rounded-md border border-gray-300 shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Close</button>
            </div>
        </div>
</div>

<!-- Decision modal. z-[140]: opens ON TOP of the detail modal, which stays
     visible behind it so the Manager can still read what they are deciding on.
     Same single-element backdrop+centering pattern as the Detail modal above. -->
<div id="slaDecisionModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[140] hidden items-center justify-center overflow-y-auto p-4">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg overflow-hidden">
            <div class="px-6 pt-5 pb-4">
                <h3 id="slaDecisionTitle" class="text-lg font-bold text-gray-900 mb-1">Approve Request</h3>
                <p id="slaDecisionSubtitle" class="text-xs text-gray-500 mb-4"></p>
                <label class="block text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">
                    <span id="slaDecisionNotesLabel">Approval Note (required, min. 10 characters)</span>
                </label>
                <textarea id="slaDecisionNotes" rows="4" maxlength="1000"
                          class="w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-300"
                          placeholder="Write your notes here..."></textarea>
                <p id="sla-decision-error" class="text-xs text-red-600 mt-2 hidden"></p>
            </div>
            <div class="bg-gray-50 px-6 py-3 sm:flex sm:flex-row-reverse gap-2">
                <button type="button" id="slaDecisionSaveBtn"
                        class="w-full sm:w-auto inline-flex justify-center px-4 py-2 rounded-md shadow-sm text-sm font-bold text-white bg-green-600 hover:bg-green-700 transition">Confirm</button>
                <button type="button" id="slaDecisionCancelBtn"
                        class="mt-2 sm:mt-0 w-full sm:w-auto inline-flex justify-center px-4 py-2 rounded-md border border-gray-300 shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Cancel</button>
            </div>
        </div>
</div>

<!-- VIEW HISTORY MODAL: the full, append-only audit trail for one PR line. -->
<div id="slaHistoryModalBg" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[100] hidden items-center justify-center backdrop-blur-sm overflow-y-auto">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl my-8 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Revision History</h3>
                <p id="histSubtitle" class="text-xs text-gray-500"></p>
            </div>
            <button type="button" id="closeSlaHistoryBtn" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div id="histBody" class="p-6 max-h-[70vh] overflow-y-auto text-sm"></div>
    </div>
</div>

<!-- INLINE EDIT POPOVER — the Excel-style comment on a cell.
     Deliberately NOT a full-screen modal: the user is editing one cell of a row
     they are looking at, and covering the table would take away the context they
     are editing against. position:fixed so the table's overflow-x container
     cannot clip it. -->
<div id="slaEditPop" class="hidden fixed z-[110] bg-white rounded-lg shadow-2xl border border-gray-300 text-sm">
    <div class="px-3 py-2 border-b border-gray-100 bg-gray-50 rounded-t-lg">
        <p id="slaEditPopField" class="text-xs font-bold text-gray-800"></p>
        <p id="slaEditPopLine" class="text-[10px] text-gray-500"></p>
    </div>
    <div class="p-3 space-y-2">
        <div>
            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-0.5">Current</label>
            <p id="slaEditPopCurrent" class="text-xs font-semibold text-gray-800 bg-gray-50 border border-gray-200 rounded px-2 py-1"></p>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-0.5">New value</label>
            <input type="date" id="slaEditPopDate"
                   class="w-full text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <select id="slaEditPopStatus"
                    class="hidden w-full text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-300"></select>
        </div>
        <div>
            <!-- Per LINE, not per cell: both dates of one row travel as a single
                 'date' request with a single justification, so editing the second
                 date reopens the remarks already written for the first. -->
            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-0.5">Remarks <span class="text-red-500">*</span></label>
            <textarea id="slaEditPopRemarks" rows="3" maxlength="1000"
                      placeholder="Why does this line need adjusting? (min. 10 characters)"
                      class="w-full text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-300"></textarea>
            <p id="slaEditPopShared" class="hidden text-[10px] text-gray-500 mt-0.5">Shared with the other date on this line.</p>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-gray-500 uppercase mb-0.5">Evidence link (optional)</label>
            <input type="url" id="slaEditPopEvidence" maxlength="500" placeholder="https://..."
                   class="w-full text-xs border border-gray-300 rounded px-2 py-1 focus:outline-none focus:ring-2 focus:ring-indigo-300">
        </div>
        <p id="slaEditPopError" class="hidden text-[11px] text-red-600"></p>
        <div class="flex items-center justify-between gap-2 pt-1">
            <button type="button" id="slaEditPopRemove"
                    class="hidden text-[11px] font-semibold text-red-600 hover:underline">Remove change</button>
            <div class="flex gap-2 ml-auto">
                <button type="button" id="slaEditPopCancel"
                        class="text-xs font-semibold px-3 py-1.5 rounded border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 transition">Cancel</button>
                <button type="button" id="slaEditPopOk"
                        class="text-xs font-bold px-3 py-1.5 rounded bg-indigo-600 hover:bg-indigo-700 text-white transition">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- APPLY CHANGES REVIEW MODAL. One block per edited line: what changes, the
     remarks, and the other line items of the SAME ticket that could take the
     same change. Nothing is submitted until Confirm here. -->
<div id="slaApplyModalBg" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[100] hidden items-center justify-center backdrop-blur-sm overflow-y-auto">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-4xl my-8 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Review Changes</h3>
                <p class="text-xs text-gray-500"><?= $slarep_is_approver
                    ? 'Your role can apply changes directly — they take effect as soon as you confirm.'
                    : 'Every change is submitted for approval — nothing takes effect until your superior approves it.' ?></p>
            </div>
            <button type="button" id="closeSlaApplyBtn" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div id="slaApplyBody" class="p-6 max-h-[65vh] overflow-y-auto text-sm"></div>
        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-between gap-3">
            <p id="slaApplyError" class="hidden text-xs text-red-600"></p>
            <div class="flex gap-3 ml-auto">
                <button type="button" id="cancelSlaApplyBtn"
                        class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Back</button>
                <button type="button" id="confirmSlaApplyBtn"
                        class="px-5 py-2 rounded-md text-sm font-bold text-white bg-indigo-600 hover:bg-indigo-700 transition"><?= $slarep_is_approver ? 'Apply Changes' : 'Submit for Approval' ?></button>
            </div>
        </div>
    </div>
</div>

<?php if ($slarep_can_bulk): ?>
<!-- BULK SLA REQUEST (CSV) MODAL. Upload -> validation preview -> submit, same
     two-step shape as Master Data's CSV import. Each line becomes an ordinary
     pending SLA Status Override (SLA Breached -> Within SLA) that lands in the
     Manage SLA Request inbox; nothing takes effect until it is approved. Server side:
     pages/ajax_sla_bulk_request.php + includes/sla_bulk_request.php. -->
<div id="slaBulkModalBg" class="fixed inset-0 bg-gray-900 bg-opacity-50 z-[100] hidden items-center justify-center backdrop-blur-sm overflow-y-auto">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl my-8 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
            <div>
                <h3 class="text-lg font-bold text-gray-800">Bulk SLA Request (.CSV)</h3>
                <p class="text-xs text-gray-500">Request an SLA Status Override (SLA Breached &rarr; Within SLA) for many lines at once. Every request needs approval before it takes effect.</p>
            </div>
            <button type="button" id="closeSlaBulkBtn" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>

        <!-- Step 1: upload -->
        <div id="slaBulkStepUpload" class="p-6 max-h-[70vh] overflow-y-auto text-sm">
            <div class="grid md:grid-cols-2 gap-4 mb-5">
                <div class="rounded-lg border border-indigo-100 bg-indigo-50 p-4">
                    <p class="text-xs font-bold text-indigo-900 uppercase mb-2">How it works</p>
                    <ol class="list-decimal pl-5 space-y-1 text-xs text-indigo-900">
                        <li>Download the template.</li>
                        <li>Fill one row per line item:
                            <code class="bg-white px-1 rounded">ticket_no</code>,
                            <code class="bg-white px-1 rounded">line_item</code>,
                            <code class="bg-white px-1 rounded">remarks</code> (min. 10 characters),
                            <code class="bg-white px-1 rounded">link</code> (optional, http/https).</li>
                        <li>Upload &amp; Validate &mdash; review the preview. Nothing is submitted until you confirm.</li>
                        <li>Submitted lines appear in <strong>Manage SLA Request</strong> as <em>Pending Approval</em>.</li>
                    </ol>
                </div>
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <p class="text-xs font-bold text-amber-900 uppercase mb-2">Validation rules</p>
                    <ul class="list-disc pl-5 space-y-1 text-xs text-amber-900">
                        <li>The ticket and line item must exist.</li>
                        <li>No multiple requests: each line only once per file, and not if it already has a request waiting for approval.</li>
                        <li>Only <strong>SLA Breached</strong> lines on your own SLA Tracking list<?= ($_SESSION['role'] ?? '') === 'PIC' ? ' (lines assigned to you)' : '' ?>.</li>
                        <li>Maximum <?= (int)SLA_BULK_MAX_ROWS ?> rows per file.</li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-4">
                <div class="flex-grow min-w-[16rem]">
                    <label class="block text-gray-700 text-xs font-bold mb-1" for="slaBulkFile">Choose CSV File</label>
                    <input type="file" id="slaBulkFile" accept=".csv"
                           class="w-full text-sm border border-gray-300 rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-300">
                </div>
                <div>
                    <label class="block text-gray-700 text-xs font-bold mb-1" for="slaBulkDelimiter">Delimiter</label>
                    <select id="slaBulkDelimiter" class="text-sm border border-gray-300 rounded-md px-3 py-2 h-[42px] focus:outline-none focus:ring-2 focus:ring-indigo-300">
                        <option value="" selected>Auto-detect</option>
                        <option value=",">Comma (,)</option>
                        <option value=";">Semicolon (;)</option>
                        <option value="\t">Tab</option>
                        <option value="|">Pipe (|)</option>
                    </select>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 mt-1">If Excel opens the template with everything in one column, choose Semicolon (;) and download the template again.</p>

            <div class="flex flex-wrap items-center gap-3 mt-4">
                <a id="slaBulkTemplateBtn" href="ajax_sla_bulk_request.php?action=template"
                   class="bg-white hover:bg-indigo-50 text-indigo-700 font-bold py-2 px-4 rounded-lg shadow-sm border border-indigo-200 transition h-[42px] inline-flex items-center text-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Download Template
                </a>
                <button type="button" id="slaBulkValidateBtn"
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg shadow-sm transition h-[42px] inline-flex items-center text-sm disabled:opacity-50">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M12 12v9m0-9l3 3m-3-3l-3 3"></path></svg>
                    <span>Upload &amp; Validate</span>
                </button>
            </div>
            <div id="slaBulkUploadError" class="hidden mt-4 bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded text-xs"></div>
        </div>

        <!-- Step 2: validation preview -->
        <div id="slaBulkStepPreview" class="hidden p-6 max-h-[70vh] overflow-y-auto text-sm">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-4 text-center">
                <div class="bg-gray-50 rounded p-3"><div class="text-xl font-bold text-gray-800" id="slaBulkSumTotal">0</div><div class="text-[10px] text-gray-500 uppercase">Total Rows</div></div>
                <div class="bg-green-50 rounded p-3"><div class="text-xl font-bold text-green-700" id="slaBulkSumReady">0</div><div class="text-[10px] text-gray-500 uppercase">Ready to Submit</div></div>
                <div class="bg-red-50 rounded p-3"><div class="text-xl font-bold text-red-700" id="slaBulkSumNotFound">0</div><div class="text-[10px] text-gray-500 uppercase">Ticket / Line Not Found</div></div>
                <div class="bg-amber-50 rounded p-3" title="The same line twice in this file, or a line that already has a request waiting for approval."><div class="text-xl font-bold text-amber-700" id="slaBulkSumDup">0</div><div class="text-[10px] text-gray-500 uppercase">Duplicated</div></div>
                <div class="bg-slate-50 rounded p-3" title="Lines whose current status is not SLA Breached, plus any row outside your scope or with a malformed remark/link."><div class="text-xl font-bold text-slate-700" id="slaBulkSumOther">0</div><div class="text-[10px] text-gray-500 uppercase">Within SLA</div></div>
            </div>

            <div class="flex flex-wrap items-center gap-2 mb-3">
                <button type="button" data-bulk-filter="all" class="sla-bulk-filter px-3 py-1 rounded-full text-xs font-bold border">All</button>
                <button type="button" data-bulk-filter="READY" class="sla-bulk-filter px-3 py-1 rounded-full text-xs font-bold border">Ready</button>
                <button type="button" data-bulk-filter="INVALID" class="sla-bulk-filter px-3 py-1 rounded-full text-xs font-bold border">Invalid</button>
                <span class="flex-grow"></span>
                <button type="button" id="slaBulkSelectAll" class="px-3 py-1 rounded-full text-xs font-bold border border-indigo-300 text-indigo-600 bg-white hover:bg-indigo-50">Select all ready</button>
                <button type="button" id="slaBulkSelectNone" class="px-3 py-1 rounded-full text-xs font-bold border border-gray-300 text-gray-600 bg-white hover:bg-gray-50">Deselect all</button>
            </div>

            <div id="slaBulkRows" class="border border-gray-200 rounded max-h-[26rem] overflow-auto"></div>
            <p id="slaBulkRowsMore" class="hidden text-xs text-gray-500 mt-1"></p>
            <p class="text-[11px] text-gray-500 mt-2">Invalid rows are never submitted. To fix one, correct the CSV and upload it again.</p>
            <div id="slaBulkConfirmError" class="hidden mt-3 bg-red-50 border-l-4 border-red-500 text-red-700 p-3 rounded text-xs"></div>
        </div>

        <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex items-center justify-end gap-3">
            <button type="button" id="slaBulkBackBtn"
                    class="hidden px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Upload Another File</button>
            <button type="button" id="slaBulkCancelBtn"
                    class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition">Cancel</button>
            <button type="button" id="slaBulkSubmitBtn"
                    class="hidden px-5 py-2 rounded-md text-sm font-bold text-white bg-green-600 hover:bg-green-700 transition disabled:opacity-50 disabled:cursor-not-allowed">Submit for Approval</button>
        </div>
    </div>
</div>

<script>
$(function() {
    // ===== BULK SLA REQUEST (CSV) ============================================
    // Self-contained: only talks to ajax_sla_bulk_request.php and reloads the
    // page afterwards, so the Manage SLA Request inbox (embedded server-side) and the
    // pending markers on the table both show the new requests.
    var bulkRows = [];      // last validate response, one entry per CSV row
    var bulkSel = {};       // row_number -> true when ticked (READY rows only)
    var bulkFilter = 'all';
    var BULK_RENDER_CAP = 300; // DOM rows drawn per view; selection still covers every row

    function besc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    function openBulk() {
        showStep('upload');
        $('#slaBulkFile').val('');
        $('#slaBulkUploadError, #slaBulkConfirmError').addClass('hidden').text('');
        $('#slaBulkModalBg').removeClass('hidden').addClass('flex');
    }
    function closeBulk() { $('#slaBulkModalBg').addClass('hidden').removeClass('flex'); }

    function showStep(step) {
        var preview = (step === 'preview');
        $('#slaBulkStepUpload').toggleClass('hidden', preview);
        $('#slaBulkStepPreview').toggleClass('hidden', !preview);
        $('#slaBulkBackBtn, #slaBulkSubmitBtn').toggleClass('hidden', !preview);
    }

    $('#slaBulkBtn').on('click', openBulk);
    $('#closeSlaBulkBtn, #slaBulkCancelBtn').on('click', closeBulk);
    $('#slaBulkModalBg').on('click', function(e) { if (e.target === this) closeBulk(); });
    $('#slaBulkBackBtn').on('click', function() {
        $('#slaBulkFile').val('');
        $('#slaBulkConfirmError').addClass('hidden');
        showStep('upload');
    });

    // The template follows the chosen delimiter; Auto-detect downloads comma.
    $('#slaBulkDelimiter').on('change', function() {
        var d = $(this).val() || ',';
        $('#slaBulkTemplateBtn').attr('href', 'ajax_sla_bulk_request.php?action=template&delimiter=' + encodeURIComponent(d));
    });

    function filtered() {
        return bulkFilter === 'all' ? bulkRows : bulkRows.filter(function(r) { return r.status === bulkFilter; });
    }
    function selectedCount() {
        var n = 0;
        bulkRows.forEach(function(r) { if (r.status === 'READY' && bulkSel[r.row_number]) n++; });
        return n;
    }
    function refreshSubmit() {
        var n = selectedCount();
        $('#slaBulkSubmitBtn').prop('disabled', n === 0)
            .text(n ? 'Submit ' + n + ' for Approval' : 'Submit for Approval');
    }
    function refreshFilterPills() {
        $('.sla-bulk-filter').each(function() {
            var active = String($(this).data('bulk-filter')) === bulkFilter;
            $(this).toggleClass('bg-indigo-600 text-white border-indigo-600', active)
                   .toggleClass('bg-white text-gray-600 border-gray-300 hover:bg-gray-50', !active);
        });
    }

    var STATUS_CLS = {
        'SLA Breached': 'text-red-700', 'Within SLA': 'text-green-700',
        'In Progress': 'text-yellow-700', 'AON': 'text-blue-700', 'Out of Scope': 'text-gray-500'
    };

    function rowHtml(r) {
        var ready = r.status === 'READY';
        var cb = ready
            ? '<input type="checkbox" class="sla-bulk-cb h-4 w-4 text-indigo-600 rounded" data-row="' + r.row_number + '"' + (bulkSel[r.row_number] ? ' checked' : '') + '>'
            : '';
        var result = ready
            ? '<span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-green-100 text-green-800">Ready</span>'
            : '<span class="inline-block px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-red-100 text-red-800 mb-1">Invalid</span>' +
              // One numbered line per problem, most fundamental first (the server
              // orders them): bold title says WHAT is wrong, the rest says the detail.
              '<ol class="list-decimal pl-4 space-y-1 text-red-700">' +
              (r.errors || []).map(function(e) {
                  return '<li><span class="font-bold">' + besc(e.title || '') + '</span>' +
                         (e.message ? '<span class="block text-red-600">' + besc(e.message) + '</span>' : '') + '</li>';
              }).join('') + '</ol>';
        // The line's current SLA status as SLA Tracking shows it. When there is none
        // (ticket/line not found, not on the user's list) the reason is shown instead.
        var status = r.current_status
            ? '<span class="font-semibold ' + (STATUS_CLS[r.current_status] || 'text-gray-700') + '">' + besc(r.current_status) + '</span>' +
              (r.duration ? '<div class="text-[10px] text-gray-400">' + besc(r.duration) + '</div>' : '')
            : (r.status_note
                ? '<span class="font-semibold text-red-700">' + besc(r.status_note) + '</span>'
                : '<span class="text-gray-300">-</span>');
        var link = r.link
            ? (/^https?:\/\//i.test(r.link)
                ? '<a href="' + besc(r.link).replace(/"/g, '&quot;') + '" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline break-all">Open</a>'
                : '<span class="text-gray-500 break-all">' + besc(r.link) + '</span>')
            : '<span class="text-gray-300">-</span>';

        return '<tr class="' + (ready ? '' : 'bg-red-50/40') + '">' +
            '<td class="px-2 py-2 align-top">' + cb + '</td>' +
            '<td class="px-2 py-2 align-top text-[10px] text-gray-400">' + r.row_number + '</td>' +
            '<td class="px-2 py-2 align-top font-semibold text-gray-800 whitespace-nowrap">' + besc(r.ticket_no || '-') + '</td>' +
            '<td class="px-2 py-2 align-top whitespace-nowrap">' + besc(r.line_item || '-') + '</td>' +
            '<td class="px-2 py-2 align-top max-w-[14rem]"><div class="line-clamp-2" title="' + besc(r.item).replace(/"/g, '&quot;') + '">' + besc(r.item || '-') + '</div></td>' +
            '<td class="px-2 py-2 align-top whitespace-nowrap">' + besc(r.pic || '-') + '</td>' +
            '<td class="px-2 py-2 align-top whitespace-nowrap">' + status + '</td>' +
            '<td class="px-2 py-2 align-top max-w-[16rem]"><div class="whitespace-pre-line break-words">' + besc(r.remarks || '-') + '</div></td>' +
            '<td class="px-2 py-2 align-top">' + link + '</td>' +
            '<td class="px-2 py-2 align-top max-w-[18rem]">' + result + '</td>' +
            '</tr>';
    }

    function renderRows() {
        var rows = filtered();
        var $wrap = $('#slaBulkRows');
        if (!rows.length) {
            $wrap.html('<div class="p-6 text-center text-xs text-gray-400">No rows in this view.</div>');
            $('#slaBulkRowsMore').addClass('hidden');
            return;
        }
        var th = function(t) { return '<th class="px-2 py-2 text-left text-[10px] font-bold text-gray-500 uppercase whitespace-nowrap">' + t + '</th>'; };
        var html = '<table class="min-w-full text-xs"><thead class="bg-gray-50 sticky top-0 z-10"><tr>' +
            '<th class="px-2 py-2"></th>' + th('Row') + th('Ticket No') + th('Line') + th('Item Description') +
            th('PIC') + th('Current Status') + th('Remarks') + th('Link') + th('Validation') +
            '</tr></thead><tbody class="divide-y divide-gray-100">';
        rows.slice(0, BULK_RENDER_CAP).forEach(function(r) { html += rowHtml(r); });
        html += '</tbody></table>';
        $wrap.html(html);
        $('#slaBulkRowsMore').toggleClass('hidden', rows.length <= BULK_RENDER_CAP)
            .text('Showing the first ' + BULK_RENDER_CAP + ' of ' + rows.length + ' rows in this view. "Select all ready" still covers every row.');
    }

    $('#slaBulkValidateBtn').on('click', function() {
        var input = document.getElementById('slaBulkFile');
        if (!input.files || !input.files.length) {
            $('#slaBulkUploadError').removeClass('hidden').text('Please choose a CSV file first.');
            return;
        }
        var fd = new FormData();
        fd.append('action', 'validate');
        fd.append('csv_file', input.files[0]);
        fd.append('delimiter', $('#slaBulkDelimiter').val());

        var $btn = $(this).prop('disabled', true);
        $btn.find('span').text('Validating…');
        $('#slaBulkUploadError').addClass('hidden');

        $.ajax({
            url: 'ajax_sla_bulk_request.php', type: 'POST', data: fd,
            processData: false, contentType: false, dataType: 'json'
        }).done(function(res) {
            if (!res || res.status !== 'ok') {
                $('#slaBulkUploadError').removeClass('hidden').text((res && (res.message || res.error)) || 'Validation failed.');
                return;
            }
            bulkRows = res.rows || [];
            bulkSel = {};
            bulkRows.forEach(function(r) { if (r.status === 'READY') bulkSel[r.row_number] = true; });
            // Land on the rows that need attention when there are any.
            bulkFilter = res.summary.invalid > 0 ? 'INVALID' : 'all';

            $('#slaBulkSumTotal').text(res.summary.total_rows);
            $('#slaBulkSumReady').text(res.summary.ready);
            $('#slaBulkSumNotFound').text(res.summary.not_found);
            $('#slaBulkSumDup').text(res.summary.duplicate);
            $('#slaBulkSumOther').text(res.summary.not_eligible);
            $('#slaBulkConfirmError').addClass('hidden');

            refreshFilterPills();
            renderRows();
            refreshSubmit();
            showStep('preview');
        }).fail(function(xhr) {
            var msg = (xhr.responseJSON && (xhr.responseJSON.message || xhr.responseJSON.error)) || 'Server error while validating the file.';
            $('#slaBulkUploadError').removeClass('hidden').text(msg);
        }).always(function() {
            $btn.prop('disabled', false);
            $btn.find('span').text('Upload & Validate');
        });
    });

    $(document).on('click', '.sla-bulk-filter', function() {
        bulkFilter = String($(this).data('bulk-filter'));
        refreshFilterPills();
        renderRows();
    });
    $('#slaBulkRows').on('change', '.sla-bulk-cb', function() {
        bulkSel[$(this).data('row')] = this.checked;
        refreshSubmit();
    });
    $('#slaBulkSelectAll, #slaBulkSelectNone').on('click', function() {
        var on = this.id === 'slaBulkSelectAll';
        bulkRows.forEach(function(r) { if (r.status === 'READY') bulkSel[r.row_number] = on; });
        renderRows();
        refreshSubmit();
    });

    $('#slaBulkSubmitBtn').on('click', function() {
        var n = selectedCount();
        if (!n) return;
        var tickets = {};
        bulkRows.forEach(function(r) { if (r.status === 'READY' && bulkSel[r.row_number]) tickets[r.ticket_no] = true; });
        var $btn = $(this);

        Swal.fire({
            title: 'Submit bulk SLA request?',
            html: '<strong>' + n + '</strong> line' + (n === 1 ? '' : 's') + ' across <strong>' + Object.keys(tickets).length +
                  '</strong> ticket' + (Object.keys(tickets).length === 1 ? '' : 's') +
                  ' will be submitted as <em>SLA Breached &rarr; Within SLA</em>.<br>' +
                  '<span class="text-sm text-gray-500">They take effect only after approval in Manage SLA Request.</span>',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Submit for Approval',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#16a34a',
            cancelButtonColor: '#6b7280'
        }).then(function(result) {
            if (!result.isConfirmed) return;
            $btn.prop('disabled', true).text('Submitting…');
            $('#slaBulkConfirmError').addClass('hidden');

            // Every row goes back, ticked or not: the server re-validates the whole
            // file (the duplicate rule needs all of it) and files only ticked READY rows.
            var payload = bulkRows.map(function(r) {
                return { row_number: r.row_number, ticket_no: r.ticket_no, line_item: r.line_item,
                         remarks: r.remarks, link: r.link, selected: !!(r.status === 'READY' && bulkSel[r.row_number]) };
            });

            $.ajax({
                url: 'ajax_sla_bulk_request.php', type: 'POST', dataType: 'json',
                data: { action: 'confirm', rows: JSON.stringify(payload) }
            }).done(function(res) {
                var failed = (res && res.failed) || [];
                var failHtml = '';
                if (failed.length) {
                    failHtml = '<p class="text-xs text-left mt-3 mb-1 font-bold text-amber-700">Not submitted (' + failed.length + '):</p>' +
                        '<ul class="text-[11px] text-left text-gray-600 list-disc pl-5 space-y-0.5 max-h-48 overflow-y-auto">' +
                        failed.map(function(f) {
                            return '<li>Row ' + f.row_number + ' — ' + besc(f.ticket_no) + ' / ' + besc(f.line_item) + ': ' + besc(f.message) + '</li>';
                        }).join('') + '</ul>';
                }
                if (!res || res.status !== 'ok') {
                    $('#slaBulkConfirmError').removeClass('hidden').html(besc((res && (res.message || res.error)) || 'Submission failed.') + failHtml);
                    refreshSubmit();
                    return;
                }
                closeBulk();
                Swal.fire({
                    icon: failed.length ? 'warning' : 'success',
                    title: 'Submitted',
                    html: '<p class="text-sm">' + besc(res.message) + '</p>' + failHtml
                }).then(function() { location.reload(); });
            }).fail(function() {
                $('#slaBulkConfirmError').removeClass('hidden').text('Connection problem — nothing was confirmed. Please try again.');
                refreshSubmit();
            });
        });
    });
});
</script>
<?php endif; ?>

<script>
$(document).ready(function() {
    // ===== Server-provided data ==============================================
    var isCategoryManager = <?php echo (($_SESSION['role'] ?? '') === 'Category Manager') ? 'true' : 'false'; ?>;
    // Manager/Category Manager already have approval authority, so their own
    // inline edits are applied immediately (ajax_sla_request_submit.php's
    // $auto_approve) instead of sitting pending — mirrors $slarep_is_approver.
    var slaAutoApprove = <?= $slarep_is_approver ? 'true' : 'false' ?>;
    // Statuses eligible for an override request, straight from the PHP helper so
    // the modal can never offer something the server would reject.
    var slaStatusOptions = <?= json_encode(sla_rev_status_options()) ?>;
    var trendBuckets = <?= json_encode($trend_buckets) ?>; // fixed period keys + labels
    var taxCombos = <?= json_encode($taxonomy_combos) ?>;  // distinct [l0..l4] tuples

    // ===== Global filter state ===============================================
    // Single source of truth applied to BOTH the table and the charts.
    var gf = { team: [], pic: [], status: '', tax: ['', '', '', '', ''], from: '', to: '' };
    var currentBarDim = 'team';  // 'team' | 'pic' | 'l0'..'l4'
    var currentGran = 'week';

    var barDimLabels = { team: 'Team', pic: 'PIC', l0: 'Taxonomy L0', l1: 'Taxonomy L1', l2: 'Taxonomy L2', l3: 'Taxonomy L3', l4: 'Taxonomy L4' };
    var trendRangeLabels = {
        week: 'last 8 weeks (including this week)',
        month: 'last 12 months (including this month)',
        year: 'last 5 years (including this year)'
    };
    // Statuses the bar chart charts (AON / Out of Scope are excluded everywhere below).
    // Single source of truth shared by the server-side bar aggregation and updateCharts()
    // so the two can't drift out of sync if a status is ever added/renamed.
    var CHARTED_STATUSES = ['In Progress', 'Within SLA', 'SLA Breached'];

    // ===== Table =============================================================
    // HTML-escape helper (replaces the PHP htmlspecialchars the old server-rendered
    // tbody used) — cell data is inserted via innerHTML by DataTables.
    function esc(s) { return $('<div>').text(s == null ? '' : String(s)).html(); }

    // esc() is built for text between tags: jQuery's .text().html() leaves double
    // quotes untouched, which breaks straight out of a quoted attribute. Hundreds
    // of real item descriptions carry one (e.g. '10" BANNER ADS'), so anything
    // going INTO an attribute -- title=, data-* -- has to use this instead.
    function escAttr(s) { return esc(s).replace(/"/g, '&quot;'); }

    // ----- Hover panel for the two-line-clamped description cells --------------
    // Replaces the native title= tooltip, which can't be styled and takes about a
    // second to appear. The full text rides on data-full (NOT title, or the browser
    // would draw its own tooltip on top of this one).
    var slaTip = (function() {
        var el = null;
        var CODE_RE = /^\s*[\[\(]\s*(PR|PP|PM|OS)\s*[\]\)]\s*/i;

        function node() {
            if (!el) {
                el = document.createElement('div');
                el.id = 'slaCellTip';
                document.body.appendChild(el);
            }
            return el;
        }

        function place(anchor) {
            var t = node(), pad = 8, gap = 6;
            var r = anchor.getBoundingClientRect();
            var tw = t.offsetWidth, th = t.offsetHeight;

            // Below the cell by default; flip above when the viewport bottom is close.
            var top = r.bottom + gap;
            if (top + th > window.innerHeight - pad) {
                var above = r.top - th - gap;
                top = (above >= pad) ? above : Math.max(pad, window.innerHeight - th - pad);
            }
            // Left-aligned with the cell, pulled back in if it would run off-screen.
            var left = Math.min(r.left, window.innerWidth - tw - pad);
            t.style.top  = Math.round(top) + 'px';
            t.style.left = Math.round(Math.max(pad, left)) + 'px';
        }

        return {
            show: function(anchor, text) {
                var t = node(), m = String(text).match(CODE_RE);
                // Lead with the STT work-type code as a chip when there is one.
                t.innerHTML = m
                    ? '<span class="tip-code">' + esc(m[1].toUpperCase()) + '</span><br>' + esc(String(text).slice(m[0].length))
                    : esc(text);
                t.style.display = 'block';
                place(anchor);                 // measure only after it has a size
                t.classList.add('is-visible');
            },
            hide: function() {
                if (!el) return;
                el.classList.remove('is-visible');
                el.style.display = 'none';
            }
        };
    })();

    $(document)
        .on('mouseenter', '#slaTable tbody .sla-clamp-2', function() {
            var full = this.getAttribute('data-full') || '';
            // Nothing to reveal when the text already fits in the two lines.
            var clamped = this.scrollHeight > this.clientHeight + 1;
            this.classList.toggle('is-clamped', clamped);
            if (clamped && full !== '' && full !== '-') slaTip.show(this, full);
        })
        .on('mouseleave', '#slaTable tbody .sla-clamp-2', function() { slaTip.hide(); })
        .on('keydown', function(e) { if (e.key === 'Escape') slaTip.hide(); });

    // Any movement under the panel invalidates the position it was measured at.
    // This has to be a native capture-phase listener: scroll does not bubble, so
    // jQuery delegation would never see the table's own scroll container (and
    // jQuery has no capture option at all).
    document.addEventListener('scroll', function() { slaTip.hide(); }, true);
    $(window).on('resize', function() { slaTip.hide(); });

    // A redraw (paging, sort, search) destroys the cell the panel was anchored to,
    // and mouseleave never fires for a node that was removed mid-hover.
    $('#slaTable').on('draw.dt', function() { slaTip.hide(); });

    var MONTH_ABBR = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    function fmtDate(iso) { // 'YYYY-MM-DD' -> 'DD Mon YYYY' (matches the old PHP d M Y)
        if (!iso) return '-';
        return iso.slice(8, 10) + ' ' + MONTH_ABBR[parseInt(iso.slice(5, 7), 10) - 1] + ' ' + iso.slice(0, 4);
    }

    // Status -> sphere/text colors, formerly sent per-row from PHP.
    var statusMeta = {
        'Within SLA':   { sphere: 'bg-green-500',  text: 'text-green-700' },
        'SLA Breached': { sphere: 'bg-red-500',    text: 'text-red-700' },
        'In Progress':  { sphere: 'bg-yellow-400', text: 'text-yellow-700' },
        'AON':          { sphere: 'bg-blue-500',   text: 'text-blue-700' },
        'Out of Scope': { sphere: 'bg-gray-400',   text: 'text-gray-600' }
    };

    // Orthogonal date column: formatted for display AND the global search box (same as
    // the old server-rendered cells), raw ISO string for sorting so order stays correct.
    //
    // A revised date renders exactly like an unrevised one — no color, no tooltip.
    // The raw prs value still reaches the client (orig_d / orig_cv) for anything that
    // wants to compare, it is simply not marked in the table.
    // 'export' is the orthogonal type the export buttons ask for (see exportOptions
    // below): same human-readable text as the screen, but never any HTML around it.
    function dateCol(field) {
        return function(data, type, row) {
            if (type === 'filter') return fmtDate(row[field]);
            if (type === 'display') return fmtDate(row[field]);
            if (type === 'export') return fmtDate(row[field]);
            return row[field] || '';
        };
    }

    // ===== Inline SLA revision: cell state ===================================
    // A PIC changes PR Date / Canvassing Date Approver 1 / SLA Status directly in the table.
    // Edits are STAGED in the browser first — nothing reaches the server until
    // Apply Changes — so several lines can be corrected in one pass and reviewed
    // together, the way a spreadsheet works.
    //
    // Keyed by "ticket|line" because `prs` has no surrogate id; that composite is
    // the row identity everywhere in this feature (see sla_rev_line_key() in PHP).
    //   { pr, cv, st, remarks, evidence, orig: {d, cv, st}, item, cno, dur }
    // pr/cv/st are only present for the fields actually changed.
    var pendingEdits = {};
    function editKey(ticket, line) { return ticket + '|' + line; }

    // The three editable fields, in one table so the popover, the staged-cell
    // painter and the submit builder all read the same definitions.
    // reqType maps a field onto the request_type the API expects: both dates are
    // ONE 'date' request (one approval settles both), the status is its own.
    var EDIT_FIELDS = {
        d:  { label: 'TR Date Technical (PR Date)', kind: 'date',   reqType: 'date',       rowKey: 'd'  },
        cv: { label: 'Canvassing Date Approver 1',  kind: 'date',   reqType: 'date',       rowKey: 'cv' },
        st: { label: 'SLA Status',                  kind: 'status', reqType: 'sla_status', rowKey: 'status' }
    };

    // Row payloads for the rows currently drawn, so the popover and the review
    // modal can read a line's other values without another round trip. Rebuilt
    // every draw — it is a cache of what is on screen, not a store.
    var drawnRows = {};

    function fieldDisplay(field, value) {
        if (!value) return '-';
        return EDIT_FIELDS[field].kind === 'date' ? fmtDate(value) : value;
    }

    // DataTables createdCell hook for one editable column. Runs on every draw
    // (the table is serverSide, so every page/sort/search re-creates the cells),
    // which is exactly what makes the staged and pending markers survive paging.
    function editCell(field) {
        return function(td, cellData, rowData) {
            var f   = EDIT_FIELDS[field];
            var key = editKey(rowData.ticket, rowData.line_item);
            drawnRows[key] = rowData;

            var $td = $(td).addClass('sla-cell')
                           .attr('data-f', field)
                           .attr('data-ticket', rowData.ticket)
                           .attr('data-line', rowData.line_item);
            // Kept so removing a staged edit can restore the cell exactly as
            // DataTables rendered it, without forcing a redraw of the page.
            $td.data('origHtml', $td.html());

            var pd = (rowData.pd || {})[field] || '';
            if (pd) {
                // Already waiting for approval: locked, and it says by whom, so
                // the user is not left wondering why the cell will not open.
                $td.addClass('sla-pending').attr('title',
                    'Waiting for approval: ' + pd +
                    (rowData.pd_by ? '\nRequested by ' + rowData.pd_by : '') +
                    (rowData.pd_at ? ' on ' + rowData.pd_at : '') +
                    (rowData.pd_rm ? '\nRemarks: ' + rowData.pd_rm : ''));
                return;
            }

            var editable = (f.reqType === 'sla_status') ? rowData.can_es : rowData.can_ed;
            if (editable) {
                $td.addClass('sla-editable').attr('title', 'Double-click to change ' + f.label);
            } else if (rowData.ed_why) {
                if (rowData.has_pending) {
                    // Locked because ANOTHER field on this line has a pending
                    // request — this field isn't the pending one itself (no entry
                    // in rowData.pd), but it should still react like a locked cell
                    // instead of silently doing nothing on double-click.
                    $td.addClass('sla-pending').attr('title', rowData.ed_why);
                } else {
                    // Not eligible for a different reason (not breached / already
                    // adjusted) — a plain hover tooltip is enough, no pending state.
                    $td.attr('title', rowData.ed_why);
                }
            }

            if (pendingEdits[key] && pendingEdits[key][field] !== undefined) paintStaged($td);
        };
    }

    // Repaints one staged cell: the new value on top, the value it replaces
    // struck through underneath. Showing both is what makes a mis-click visible
    // before Apply, when it is still free to undo.
    function paintStaged($td) {
        var key   = editKey($td.attr('data-ticket'), $td.attr('data-line'));
        var field = $td.attr('data-f');
        var edit  = pendingEdits[key];
        if (!edit || edit[field] === undefined) return;

        $td.addClass('sla-staged').attr('title',
            EDIT_FIELDS[field].label + ': ' + fieldDisplay(field, edit.orig[field]) +
            ' → ' + fieldDisplay(field, edit[field]) + '\nRemarks: ' + edit.remarks);
        $td.html('<span class="font-bold text-amber-900">' + esc(fieldDisplay(field, edit[field])) + '</span>' +
                 '<span class="sla-was">' + esc(fieldDisplay(field, edit.orig[field])) + '</span>');
    }

    // Undoes the paint above without a redraw — the row is still on screen and
    // its server data has not changed.
    function unpaintStaged($td) {
        $td.removeClass('sla-staged').html($td.data('origHtml') || '');
        var rowData = drawnRows[editKey($td.attr('data-ticket'), $td.attr('data-line'))];
        var f = EDIT_FIELDS[$td.attr('data-f')];
        $td.attr('title', rowData && f ? 'Double-click to change ' + f.label : '');
    }

    $.fn.dataTable.ext.errMode = 'none';

    var table = $('#slaTable').DataTable({
        "serverSide": true,
        "processing": true,
        "dom": '<"hidden"B>rtip',
        "buttons": (function() {
            // Trailing entries are the adjustment-workflow columns; the History
            // column (buttons only) is deliberately left out of every export.
            var exportCols = isCategoryManager
                ? [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 21, 22]
                : [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 20, 21];
            // orthogonal:'export' (not the default 'display') is what keeps decoration
            // out of the file: the display HTML stacks two values inside the
            // Canvassing No cell, and stripping its tags would glue that text onto
            // the real value.
            var exportOpts = { columns: exportCols, orthogonal: 'export' };
            return [
                { extend: 'excelHtml5', exportOptions: exportOpts },
                { extend: 'csvHtml5', exportOptions: exportOpts },
                { extend: 'pdfHtml5', exportOptions: exportOpts }
            ];
        })(),
        "ajax": {
            "url": "ajax_sla_table.php",
            "type": "POST",
            "data": function(d) { d.gf = JSON.stringify(gf); },
            // Overriding ajax.error replaces DataTables' own handler, which is where
            // it would otherwise switch the processing indicator back off — without
            // the explicit call below a failed load spins forever.
            "error": function(xhr, textStatus) {
                $('.viz-table-loader').fadeOut(200);
                $('#slaTable').closest('.dataTables_wrapper')
                    .find('.dataTables_processing').css('display', 'none');
                console.error('ajax_sla_table.php failed', xhr.status, textStatus, xhr.responseText);
                showSlaAjaxError('Gagal memuat tabel SLA (HTTP ' + xhr.status + ' / ' + textStatus + '): ' +
                    String(xhr.responseText || '').replace(/<[^>]*>/g, ' ').trim().slice(0, 400), true);
            }
        },
        "pageLength": 50,
        "lengthChange": false,
        "order": [[ 9, "desc" ]],
        "createdRow": function(row, data) {
            $(row).addClass('border-b hover:bg-indigo-50 transition')
                  .toggleClass('sla-row-staged', !!pendingEdits[editKey(data.ticket, data.line_item)]);
        },
        "initComplete": function() { $('.viz-table-loader').fadeOut(200); },
        "drawCallback": function() { hideSlaAjaxError(); }, // a successful draw means the table recovered
        "columns": (function() {
            var cols = [];
            if (isCategoryManager) {
                cols.push({
                    "data": null,
                    "orderable": false,
                    "searchable": false,
                    "className": "p-3 text-center",
                    "render": function(d, type, row) {
                        return '<input type="checkbox" class="row-checkbox rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer" data-ticket="' + esc(row.ticket) + '" data-line="' + esc(row.line_item) + '">';
                    }
                });
            }
            cols = cols.concat([
                { "data": "ticket", "title": "Ticket No", "className": "p-3 text-sm font-medium text-gray-900 whitespace-nowrap",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "escalated", "title": "Escalated", "className": "p-3 text-xs whitespace-nowrap",
                  "render": function(d, type) {
                      if (type !== 'display') return d ? 1 : 0;
                      return d
                          ? '<span class="text-purple-700 font-semibold">Escalated</span>'
                          : '<span class="text-gray-500">not escalated</span>';
                  } },
                { "data": "status", "title": "SLA Status", "className": "p-3 text-xs whitespace-nowrap",
                  "render": function(d, type, row) {
                      if (type !== 'display') return d;
                      var m = statusMeta[d] || { sphere: 'bg-gray-400', text: 'text-gray-600' };
                      var html = '<div class="flex items-center space-x-2">' +
                                 '<span class="h-3 w-3 rounded-full ' + m.sphere + ' inline-block shadow-sm" title="' + esc(d) + '"></span>' +
                                 '<span class="font-bold ' + m.text + '">' + esc(d) + '</span>';
                      return html + '</div>';
                  }, "createdCell": editCell('st') },
                { "data": "dur", "title": "Duration", "className": "p-3 text-sm font-bold text-gray-800 whitespace-nowrap",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "line_item", "title": "Line", "className": "px-2 py-3 text-xs text-gray-700 whitespace-nowrap sla-line-col",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "item", "title": "Item Description", "className": "p-3 text-xs text-gray-700 min-w-[14rem] max-w-xs",
                  "render": function(d, type, row) {
                      if (type !== 'display') return d;
                      // Two lines on screen, the rest in the hover panel (slaTip).
                      // data-full, not title -- title would make the browser draw
                      // its own slow grey tooltip on top of ours.
                      if (isCategoryManager) {
                          return '<span class="pr-taxonomy-clickable sla-clamp-2 cursor-pointer text-indigo-600 hover:underline" data-full="' + escAttr(d) + '" data-ticket-no="' + escAttr(row.ticket) + '" data-line-item="' + escAttr(row.line_item) + '">' + esc(d) + '</span>';
                      }
                      return '<span class="sla-clamp-2" data-full="' + escAttr(d) + '">' + esc(d) + '</span>';
                  } },
                { "data": "type", "title": "Type", "className": "p-3 text-xs font-semibold text-indigo-700 whitespace-nowrap",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "pic", "title": "Assigned PIC", "className": "p-3 text-xs font-bold text-gray-800 whitespace-nowrap",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "team", "title": "Team", "className": "p-3 text-xs text-gray-600 font-semibold whitespace-nowrap",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "d", "title": "PR Date", "className": "p-3 text-sm text-gray-700 whitespace-nowrap",
                  "render": dateCol('d'), "createdCell": editCell('d') },
                { "data": "cno", "title": "Canvassing No", "className": "p-3 text-sm text-gray-700 whitespace-nowrap",
                  "render": function(d, type, row) {
                      if (type !== 'display') return d || '';
                      var no = row.cno ? esc(row.cno) : '-';
                      var dt = row.cnd ? fmtDate(row.cnd) : '-';
                      return '<span class="block">' + no + '</span><span class="block text-gray-500">' + dt + '</span>';
                  } },
                // Hidden on screen — the date is already stacked under Canvassing No
                // above. It exists so exports get it as its own cell instead of the
                // two values running together in one.
                { "data": "cnd", "title": "Canvassing Date", "className": "p-3 text-sm text-gray-700 whitespace-nowrap",
                  "render": dateCol('cnd') },
                { "data": "cv", "title": "Canvassing Date Approver 1", "className": "p-3 text-sm text-gray-700 whitespace-nowrap",
                  "render": dateCol('cv'), "createdCell": editCell('cv') },
                // Written by STT in K2. The leading [PR]/[PP]/[PM]/[OS] code is what
                // decides the target shown in Duration / Target next to it.
                { "data": "cdesc", "title": "Canvassing Description", "className": "p-3 text-xs text-gray-700 min-w-[12rem] max-w-xs",
                  "render": function(d, type) {
                      if (type === 'filter') return d !== '' ? d : '-';
                      if (type !== 'display') return d;
                      // Same two-line clamp + hover-for-full as Item Description.
                      var txt = d !== '' ? d : '-';
                      return '<span class="sla-clamp-2" data-full="' + escAttr(txt) + '">' + esc(txt) + '</span>';
                  } },
                { "data": "lv.0", "title": "Level 0", "className": "p-3 text-xs text-gray-600",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "lv.1", "title": "Level 1", "className": "p-3 text-xs text-gray-600",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "lv.2", "title": "Level 2", "className": "p-3 text-xs text-gray-600",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "lv.3", "title": "Level 3", "className": "p-3 text-xs text-gray-700 font-semibold",
                  "render": function(d, type, row) {
                      if (type === 'filter') return d !== '' ? d : '-';
                      if (type !== 'display') return d;
                      var path = row.lv.filter(function(v) { return v !== ''; }).join(' > ') || '-';
                      return '<span title="' + escAttr(path) + '">' + esc(d !== '' ? d : '-') + '</span>';
                  } },
                { "data": "lv.4", "title": "Level 4", "className": "p-3 text-xs text-gray-600",
                  "render": function(d, type) { return type === 'display' ? esc(d) : d; } },
                { "data": "d", "title": "Raw Date", "className": "p-3 text-xs text-gray-600" },
                { "data": "porg", "title": "POrg", "className": "hidden", "render": function(d) { return d || ''; } },
                { "data": "pgroup", "title": "PGroup", "className": "hidden", "render": function(d) { return d || ''; } },
                // History for everyone. The PIC's Request button used to live here
                // instead; it moved to the "SLA Revision" picker above the
                // table, which lists only the lines that actually qualify. The PIC
                // keeps History as a result — they can now follow what they filed.
                { "data": null, "title": "History", "orderable": false, "searchable": false,
                  "className": "p-3 text-xs whitespace-nowrap",
                  "render": function(d, type, row) {
                      if (type !== 'display') return '';
                      // Red when the line's LAST request was rejected (row.rj is only
                      // sent for those). Nothing about a rejected line changes in the
                      // table itself, so this link is the only thing that can point at
                      // the decision — the tooltip carries who/when/why so the reason
                      // is readable without opening the modal.
                      var rejected = !!row.rj;
                      var tip = rejected
                          ? (row.rj + ' rejected' + (row.rj_by ? ' by ' + row.rj_by : '') +
                             (row.rj_at ? ' on ' + row.rj_at : '') +
                             (row.rj_why ? '\nReason: ' + row.rj_why : ''))
                          : '';
                      return '<button type="button" class="sla-history-btn hover:underline font-semibold ' +
                             (rejected ? 'text-red-600' : 'text-indigo-600') + '" ' +
                             (rejected ? 'title="' + esc(tip) + '" ' : '') +
                             'data-ticket="' + esc(row.ticket) + '" data-line="' + esc(row.line_item) + '">' +
                             'View History' + (rejected ? ' <span aria-hidden="true">&#9888;</span>' : '') +
                             '</button>';
                  } }
            ]);
            return cols;
        })(),
        "columnDefs": [
            { "targets": isCategoryManager ? [12, 15, 16, 17, 19, 20, 21, 22] : [11, 14, 15, 16, 18, 19, 20, 21], "visible": false }
        ]
    });

    // Scroll the TABLE horizontally, not the whole DataTables wrapper — done here
    // rather than in the markup because DataTables inserts its wrapper around the
    // table at init, which would otherwise drag "Showing x of y" and the pager
    // off-screen along with the columns.
    $('#slaTable').wrap('<div class="overflow-x-auto"></div>');

    // Covers the "valid JSON response, but it carries an error field" case (our sanitized
    // error message is in the 'message' field). The table's drawCallback() will hide
    table.on('error.dt', function(e, settings, techNote, message) {
        $('.viz-table-loader').fadeOut(200);
        showSlaAjaxError(message || 'Gagal memuat data SLA. Coba muat ulang halaman.', true);
    });
    // Search and Export Bindings
    $('#slaGlobalSearch').on('keyup', function() {
        table.search(this.value).draw();
    });
    $('#exportBtn').on('click', function(e) {
        e.stopPropagation();
        $('#exportMenu').toggleClass('hidden');
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#exportDropdownContainer').length) {
            $('#exportMenu').addClass('hidden');
        }
    });
    function triggerExport(type) {
        if ($('#exportAllPages').is(':checked')) {
            var oldLen = table.page.len();
            table.one('draw', function() {
                table.button('.buttons-' + type).trigger();
                table.page.len(oldLen).draw();
            });
            table.page.len(-1).draw();
        } else {
            table.button('.buttons-' + type).trigger();
        }
        $('#exportMenu').addClass('hidden');
    }
    $('#slaExportExcel').on('click', function() { triggerExport('excel'); });
    $('#slaExportCSV').on('click', function() { triggerExport('csv'); });
    $('#slaExportPDF').on('click', function() { triggerExport('pdf'); });

    // Date-range filtering now happens server-side inside ajax_sla_table.php (applied to
    // the full filtered set before pagination), so the old client-side ext.search hook is
    // removed rather than left in place — with serverSide:true it would only ever see the
    // current page's rows and silently corrupt recordsFiltered/paging if left active.

    // Bar order, driven by the two #barSortBy / #barSortDir controls. Defaults reproduce
    // the old "Sort: Total tickets" option (biggest group first).
    var currentBarSort = 'n';
    var currentBarDir  = 'desc';

    // ===== Charts ============================================================

    // Selecting a group no longer removes the other bars (see ajax_sla_charts.php: the
    // bar payload is built with this dimension's own filter lifted), so the selection has
    // to be shown some other way — the picked bars keep their color, the rest fade back.
    // Nothing selected = every bar solid, i.e. exactly the pre-selection look.
    function fadeUnselectedBars(bar, selected) {
        bar.datasets.forEach(function(ds) {
            // The server always sends a solid hex here, so this captures the true color
            // even when a previous render left an array behind on the old dataset.
            ds.solidColor = ds.backgroundColor;
        });
        if (!selected.length) return;
        bar.datasets.forEach(function(ds) {
            var h = String(ds.solidColor).replace('#', '');
            if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
            var n = parseInt(h, 16);
            // Alpha is a balance: high enough that an unselected bar's segments stay
            // readable and comparable, low enough that the selected bar still reads as
            // the foreground at a glance. Keep it well under 1 — that gap IS the signal.
            var faded = 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',0.6)';
            ds.backgroundColor = bar.labels.map(function(l) {
                return selected.indexOf(l) > -1 ? ds.solidColor : faded;
            });
        });
    }

    // #n mode charts raw ticket counts instead of each bar's 100%-scale percentage —
    // the server already sends both (percentage in .data, counts in .rawCounts), so this
    // just swaps which one the chart actually plots. Runs AFTER fadeUnselectedBars() so
    // the color array it wrote is carried over untouched; only .data changes.
    function applyBarCountMode(bar) {
        if (currentBarSort !== 'n') return bar;
        return {
            labels: bar.labels,
            datasets: bar.datasets.map(function(ds) {
                return Object.assign({}, ds, {
                    data: ds.rawCounts.map(function(rc) { return rc.tickets; })
                });
            })
        };
    }

    // Which bars of the main chart count as "selected", per dimension: the team filter in
    // Team mode, the charted level's own taxonomy value in Taxonomy mode.
    function selectedBarLabels() {
        if (currentBarDim === 'team') return gf.team;
        var v = gf.tax[parseInt(currentBarDim.slice(1), 10)] || '';
        return v ? [v] : [];
    }

    // Footer line shared by both stacked bar tooltips: the column's totals across the
    // statuses currently shown. Only the items Chart.js hands us are summed, so hiding a
    // status via the legend takes it out of the total too. POs are summed per status, so
    // a PO with tickets in more than one status is counted once per status it appears in.
    function tooltipFooterTotals(tooltipItems) {
        var tickets = 0, pos = 0;
        tooltipItems.forEach(function(item) {
            var raw = (item.dataset.rawCounts || [])[item.dataIndex];
            if (!raw) return;
            tickets += raw.tickets || 0;
            pos += raw.pos || 0;
        });
        return `Total: ${tickets} Tickets (${pos} POs)`;
    }

    var ctxBar = document.getElementById('barChart').getContext('2d');
    var barChart = new Chart(ctxBar, {
        type: 'bar',
        data: { labels: [], datasets: [] }, // filled by updateCharts()
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    stacked: true,
                    ticks: {
                        // PIC full names run long, so the axis only shows the first
                        // name + "…" — the tooltip title below still uses the raw
                        // label (chart.data.labels), so hovering reveals the full name.
                        callback: function(value) {
                            var label = this.getLabelForValue(value);
                            if (currentBarDim === 'pic' && label && label.indexOf(' ') !== -1) {
                                return label.split(' ')[0] + '…';
                            }
                            return label;
                        }
                    }
                },
                y: { stacked: true, max: 100, title: { display: true, text: 'Percentage (%)' } }
            },
            plugins: {
                legend: {
                    labels: {
                        // Once a group is selected, backgroundColor becomes a per-bar
                        // array (solid for the selection, faded for the rest) and Chart.js
                        // would swatch the legend with whatever the FIRST bar got — so the
                        // legend would dim itself even though it labels a status, not a
                        // selection. solidColor, stashed in fadeUnselectedBars(), is the
                        // status' real color and is what the swatch must always show.
                        generateLabels: function(chart) {
                            return chart.data.datasets.map(function(ds, i) {
                                var c = ds.solidColor || ds.backgroundColor;
                                return {
                                    text: ds.label,
                                    fillStyle: c,
                                    strokeStyle: c,
                                    lineWidth: 0,
                                    hidden: !chart.isDatasetVisible(i),
                                    datasetIndex: i
                                };
                            });
                        }
                    }
                },
                tooltip: {
                    // mode: 'index' + intersect: false apply ONLY to the tooltip (click
                    // detection below keeps its own default nearest/intersect-true
                    // behavior, so segment clicks stay precise). This lets hovering
                    // anywhere in a category's column show its tooltip even when a bar
                    // is a near-zero sliver too thin to land a cursor on directly.
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let rawStats = context.dataset.rawCounts[context.dataIndex];
                            if (currentBarSort === 'n') {
                                return `${label}: ${rawStats.tickets} Tickets (${rawStats.pos} POs)`;
                            }
                            let pct = context.raw;
                            return `${label}: ${pct}% (${rawStats.tickets} Tickets, ${rawStats.pos} POs)`;
                        },
                        footer: tooltipFooterTotals
                    }
                }
            },
            onClick: (e, activeEls) => {
                // Segment click filters ONLY the group of the current dimension (team in
                // Team mode, the shown taxonomy level in Taxonomy mode) — the status of
                // the clicked segment is NOT applied as a filter, whichever color you
                // click. Clicking the SAME group again toggles it back off. Selecting a
                // team here also reveals the per-PIC card below (#picBarCard).
                if (activeEls.length === 0) return;
                const group = barChart.data.labels[activeEls[0].index];

                if (currentBarDim === 'team') {
                    var idx = gf.team.indexOf(group);
                    if (idx > -1) gf.team.splice(idx, 1);
                    else gf.team.push(group);
                    gf.pic = [];            // see the legend handler: PIC filter is bound to the team drill-down
                    picCardDismissed = false; // a new team selection re-opens the card

                    $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100').addClass('opacity-50');
                    if (gf.team.length > 0) {
                        gf.team.forEach(function(t) {
                            $('.team-filter-btn[data-team="' + t + '"]').removeClass('opacity-50').addClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100');
                        });
                    } else {
                        $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
                    }
                } else {
                    // ('pic' is no longer a dimension of THIS chart — PIC clicks are
                    //  handled by picBarChart's own onClick, on the drill-down card.)
                    var lvl = parseInt(currentBarDim.slice(1), 10);
                    if (group === 'Unmapped') {
                        // No dropdown option mirrors "unmapped"; toggle the state directly.
                        gf.tax[lvl] = (gf.tax[lvl] === 'Unmapped') ? '' : 'Unmapped';
                    } else {
                        // Mirror the click into the cascading dropdowns (toggling off if the
                        // same value is already selected), then re-read state.
                        var $sel = $taxSelects.filter('[data-level="' + lvl + '"]');
                        $sel.val($sel.val() === group ? '' : group);
                        rebuildTaxOptions();
                        gf.tax = currentTaxSelections();
                    }
                }
                refreshAll();
            }
        }
    });

    // --- PIC drill-down chart (#picBarCard) ----------------------------------
    // Same stacked-100% shape as barChart, permanently grouped by PIC. It is only ever
    // drawn while gf.team is non-empty, so its bars are the PICs of the selected team(s).
    // PIC full names run long, so the axis shows the first name + "…" (unconditionally
    // here — this chart has no other dimension); the tooltip title still uses the raw label.
    var ctxPicBar = document.getElementById('picBarChart').getContext('2d');
    var picBarChart = new Chart(ctxPicBar, {
        type: 'bar',
        data: { labels: [], datasets: [] }, // filled by updateCharts()
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    stacked: true,
                    ticks: {
                        callback: function(value) {
                            var label = this.getLabelForValue(value);
                            if (label && label.indexOf(' ') !== -1) return label.split(' ')[0] + '…';
                            return label;
                        }
                    }
                },
                y: { stacked: true, max: 100, title: { display: true, text: 'Percentage (%)' } }
            },
            plugins: {
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let rawStats = context.dataset.rawCounts[context.dataIndex];
                            if (currentBarSort === 'n') {
                                return `${label}: ${rawStats.tickets} Tickets (${rawStats.pos} POs)`;
                            }
                            let pct = context.raw;
                            return `${label}: ${pct}% (${rawStats.tickets} Tickets, ${rawStats.pos} POs)`;
                        },
                        footer: tooltipFooterTotals
                    }
                }
            },
            onClick: (e, activeEls) => {
                // Toggles the clicked PIC in/out of the global filter — the status of the
                // clicked segment is NOT applied, exactly like the team chart above.
                if (activeEls.length === 0) return;
                var pic = picBarChart.data.labels[activeEls[0].index];
                var i = gf.pic.indexOf(pic);
                if (i > -1) gf.pic.splice(i, 1);
                else gf.pic.push(pic);
                refreshAll();
            }
        }
    });

    // No separate "clear" control: clicking an already-selected PIC bar toggles it back
    // off (the onClick above), and closing the card clears the filter too.

    // Manual dismiss (the X). Kept as a separate flag from gf so it never survives a
    // change of team: picking another team is a new drill-down and shows the card again.
    // Any PIC filter is dropped along with the card — leaving one active behind a hidden
    // chart would filter the table with nothing on screen to explain why.
    var picCardDismissed = false;
    $('#picBarClose').on('click', function(e) {
        e.preventDefault();
        picCardDismissed = true;
        $('#picBarCard').addClass('hidden');
        if (gf.pic.length > 0) {
            gf.pic = [];
            refreshAll();
        }
    });

    var ctxLine = document.getElementById('lineChart').getContext('2d');
    var lineChart = new Chart(ctxLine, {
        type: 'line',
        data: { labels: trendBuckets.week.labels, datasets: [] }, // filled by updateCharts()
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, max: 100, title: { display: true, text: 'Achievement %' } } },
            plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + context.raw + '%'; } } } }
        }
    });

    // Debounce helper — item-list search now costs a network round trip (server-side
    // aggregation), so keystrokes are coalesced instead of firing a request each time.
    // This only delays when the result renders; the final rendered result is unchanged.
    function debounce(fn, wait) {
        var t = null;
        return function() {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(ctx, args); }, wait);
        };
    }

    // Shared error banner for the ajax_sla_*.php calls (chart / item list) — without this,
    // a failed request just fades its loader and leaves stale/empty data on screen with no
    // indication anything went wrong.
    var $slaAjaxError = $('#slaAjaxError');
    var slaAjaxErrorTimer = null;
    // persist=true keeps the banner up until the next successful draw. A chart or
    // item-list hiccup is transient and can auto-dismiss, but a table load failure
    // leaves the page with nothing to show — its message has to stay readable.
    function showSlaAjaxError(msg, persist) {
        clearTimeout(slaAjaxErrorTimer);
        $slaAjaxError.text(msg).removeClass('hidden');
        if (!persist) {
            slaAjaxErrorTimer = setTimeout(function() { $slaAjaxError.addClass('hidden'); }, 6000);
        }
    }
    function hideSlaAjaxError() {
        clearTimeout(slaAjaxErrorTimer);
        $slaAjaxError.addClass('hidden');
    }

    function updateCharts() {
        // The PIC breakdown is a drill-down of the team selection: it is requested (and
        // the card shown) only while at least one team is selected and the user has not
        // dismissed it with the X.
        var wantPic = gf.team.length > 0 && !picCardDismissed;
        $.post('ajax_sla_charts.php', {
            gf: JSON.stringify(gf),
            dim: currentBarDim,
            withPic: wantPic ? '1' : '0',
            gran: currentGran,
            sort: currentBarSort,
            barDir: currentBarDir, // NOT 'sortDir' — that key belongs to the item list below
            search: $itemSearch.val() || '',
            sortCol: itemSort.col,
            sortDir: itemSort.dir
        }, null, 'json').done(function(resp) {
            // Fade the loader out on every response (success or error) — otherwise a
            // failed/errored request leaves the spinner stuck over the charts forever.
            $('.viz-chart-loader').fadeOut(200);
            if (!resp || resp.error) {
                showSlaAjaxError((resp && resp.error) || 'Gagal memuat data chart. Coba lagi.');
                return;
            }
            hideSlaAjaxError();

            // With a charted status selected, show ONLY that segment — each bar keeps
            // its own original percentage (share of that group's own total), the same
            // number it showed in the full stacked bar before the filter was applied.
            var isSingleStatusMode = !!gf.status && CHARTED_STATUSES.indexOf(gf.status) !== -1;
            var bar = resp.bar;
            if (isSingleStatusMode) {
                var only = bar.datasets.filter(function(ds) { return ds.label === gf.status; });
                if (only.length) bar = { labels: bar.labels, datasets: only };
            }
            fadeUnselectedBars(bar, selectedBarLabels());
            bar = applyBarCountMode(bar);
            barChart.data.labels = bar.labels;
            barChart.data.datasets = bar.datasets;
            // #n plots raw ticket counts, so the 100%-scale cap and "Percentage" axis no
            // longer apply — let Chart.js auto-scale to the tallest stacked bar instead.
            barChart.options.scales.y.max = currentBarSort === 'n' ? undefined : 100;
            barChart.options.scales.y.title.text = currentBarSort === 'n' ? 'Number of Tickets' : 'Percentage (%)';
            barChart.update();
            $('#barDimLabel').text(barDimLabels[currentBarDim] || 'Team');
            $('#barScaleLabel').text(currentBarSort === 'n' ? '' : '(100% Scale)');
            $('#barModeCaption').text(
                isSingleStatusMode ? `— showing ${gf.status} only.` : ''
            );

            // --- PIC drill-down card ---------------------------------------
            $('#picBarCard').toggleClass('hidden', !wantPic);
            if (wantPic && resp.picBar) {
                var picBar = resp.picBar;
                if (isSingleStatusMode) {
                    var picOnly = picBar.datasets.filter(function(ds) { return ds.label === gf.status; });
                    if (picOnly.length) picBar = { labels: picBar.labels, datasets: picOnly };
                }
                picBar = applyBarCountMode(picBar);
                picBarChart.data.labels = picBar.labels;
                picBarChart.data.datasets = picBar.datasets;
                picBarChart.options.scales.y.max = currentBarSort === 'n' ? undefined : 100;
                picBarChart.options.scales.y.title.text = currentBarSort === 'n' ? 'Number of Tickets' : 'Percentage (%)';
                $('#picBarScaleLabel').text(currentBarSort === 'n' ? '' : '(100% Scale)');
                // The canvas was 0x0 while the card was hidden, so the first reveal needs
                // an explicit resize before update() or the chart draws at zero height.
                picBarChart.resize();
                picBarChart.update();
                $('#picBarTeams').text(gf.team.join(', '));
                $('#picBarCaption').text(
                    gf.pic.length ? `— filtered to ${gf.pic.join(', ')}.`
                                  : (isSingleStatusMode ? `— showing ${gf.status} only.` : '')
                );
            }

            lineChart.data.labels = trendBuckets[currentGran].labels;
            lineChart.data.datasets = resp.trend.datasets;
            lineChart.update();
            $('#trendRangeLabel').text(trendRangeLabels[currentGran]);

            // Item list arrives in this same response (see the request params above).
            if (resp.items) renderItemList(resp.items);
        }).fail(function() {
            // Network-level failure (no response at all) — same stuck-loader risk as above.
            $('.viz-chart-loader').fadeOut(200);
            showSlaAjaxError('Gagal terhubung ke server saat memuat chart. Coba lagi.');
        });
        // No separate item-list request here anymore — it is served by the call above.
        // fetchItemList() is still used on its own for item-list-only changes (search
        // box, column sort), which don't need the charts rebuilt.
    }

    // ===== Distinct item list ================================================
    var $itemSearch = $('#itemSearch');

    // Sortable header state: 'name' | 'count' | 'pct', default matches the old fixed
    // behavior (busiest item first). Clicking the active column again flips direction.
    var itemSort = { col: 'count', dir: 'desc' };

    function updateSortArrows() {
        $('.item-sort-btn').each(function() {
            var $btn = $(this);
            var active = $btn.attr('data-col') === itemSort.col;
            $btn.toggleClass('text-indigo-600', active).toggleClass('text-gray-500', !active);
            $btn.find('.item-sort-arrow').text(active ? (itemSort.dir === 'asc' ? '▲' : '▼') : '');
        });
    }

    function renderItemList(resp) {
        $('#itemTotalCount').text(resp.itemTotalCount);
        $('#itemShownCount').text(resp.itemShownCount);
        updateSortArrows();

        var RENDER_CAP = 500;
        var entries = resp.items || [];
        var html = entries.map(function(e) {
            var esc = $('<div>').text(e.name).html();
            var pctText = (e.pct % 1 === 0 ? e.pct.toFixed(0) : e.pct.toFixed(1)) + '%';
            return '<div class="flex items-center gap-3 px-3 py-1.5 border-b border-gray-100 hover:bg-indigo-50 transition">' +
                   '<span class="flex-1 min-w-0 text-xs text-gray-700 truncate" title="' + esc + '">' + esc + '</span>' +
                   '<span class="shrink-0 w-24 text-center text-xs font-bold text-indigo-700">' + e.count + ' / ' + e.overall + '</span>' +
                   '<span class="shrink-0 w-14 text-center text-xs font-bold text-indigo-700">' + pctText + '</span>' +
                   '</div>';
        }).join('');
        if (resp.capped) {
            html += '<div class="p-3 text-xs text-gray-400 italic">Showing top ' + RENDER_CAP + ' items — refine the search to see more.</div>';
        }
        $('#itemListBody').html(html || '<div class="p-3 text-xs text-gray-400">No items match.</div>');
    }

    function fetchItemList() {
        $.post('ajax_sla_items.php', {
            gf: JSON.stringify(gf),
            search: $itemSearch.val() || '',
            sortCol: itemSort.col,
            sortDir: itemSort.dir
        }, null, 'json').done(function(resp) {
            if (!resp || resp.error) {
                showSlaAjaxError((resp && resp.error) || 'Gagal memuat daftar item. Coba lagi.');
                return;
            }
            hideSlaAjaxError();
            renderItemList(resp);
        }).fail(function() {
            showSlaAjaxError('Gagal terhubung ke server saat memuat daftar item. Coba lagi.');
        });
    }

    $itemSearch.on('input', debounce(fetchItemList, 300));

    $('.item-sort-btn').on('click', function() {
        var col = $(this).attr('data-col');
        if (itemSort.col === col) {
            itemSort.dir = itemSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            itemSort.col = col;
            itemSort.dir = col === 'name' ? 'asc' : 'desc'; // name defaults A-Z, numeric columns default highest-first
        }
        fetchItemList();
    });

    // Collapsible toggle: list starts hidden; the header counter stays visible either way.
    $('#itemListToggle').on('click', function() {
        var opened = $('#itemListPanel').toggleClass('hidden').hasClass('hidden') === false;
        $('#itemListChevron').toggleClass('-rotate-90', !opened);
        $('#itemListToggleLabel').text(opened ? 'Hide list' : 'Show list');
    });

    // ===== Applying the global filter ========================================
    function refreshAll() {
        // Reset to the first page, like the old column().search() + draw() did whenever
        // a filter changed (server-side reload with the current gf baked into the request).
        table.ajax.reload(null, true);
        updateCharts();
    }

    // ===== Filter handlers ===================================================
    $('.team-filter-btn').on('click', function(e) {
        e.preventDefault();
        var team = $(this).attr('data-team') || '';
        if (team) {
            var idx = gf.team.indexOf(team);
            if (idx > -1) gf.team.splice(idx, 1);
            else gf.team.push(team);
        } else {
            gf.team = [];
        }
        // The PIC filter only exists inside a team drill-down, so changing the team
        // selection drops it — otherwise a PIC from the previous team keeps filtering
        // the table from a card that no longer lists them.
        gf.pic = [];
        picCardDismissed = false; // a new team selection re-opens the card

        $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100').addClass('opacity-50');
        if (gf.team.length > 0) {
            gf.team.forEach(function(t) {
                $('.team-filter-btn[data-team="' + t + '"]').removeClass('opacity-50').addClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100');
            });
        } else {
            $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
        }
        refreshAll();
    });

    $('.legend-filter-btn').on('click', function(e) {
        e.preventDefault();
        var status = $(this).attr('data-status') || '';
        if (status && gf.status === status) status = ''; // clicking the active status again clears it
        gf.status = status;
        if (status) {
            $('.legend-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100').addClass('opacity-50');
            $(this).removeClass('opacity-50').addClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100');
        } else {
            $('.legend-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
        }
        refreshAll();
    });

    // --- Cascading taxonomy filter (L0 -> L4) --------------------------------
    var $taxSelects = $('.tax-filter');

    function currentTaxSelections() {
        var sel = ['', '', '', '', ''];
        $taxSelects.each(function() { sel[parseInt($(this).attr('data-level'), 10)] = $(this).val() || ''; });
        return sel;
    }

    function rebuildTaxOptions() {
        var sel = currentTaxSelections();
        $taxSelects.each(function() {
            var lvl = parseInt($(this).attr('data-level'), 10);
            var $s = $(this);
            var prev = $s.val() || '';
            // Values available at this level, given the picks made at every OTHER level.
            var opts = {};
            taxCombos.forEach(function(combo) {
                var ok = true;
                for (var i = 0; i < 5; i++) {
                    if (i !== lvl && sel[i] && combo[i] !== sel[i]) { ok = false; break; }
                }
                if (ok && combo[lvl] !== '') opts[combo[lvl]] = true;
            });
            var keys = Object.keys(opts).sort();
            var html = '<option value="">All</option>';
            keys.forEach(function(k) {
                var escVal = $('<div>').text(k).html();
                // Truncate the visible label so the native dropdown popup stays narrow;
                // the full value is kept for filtering and shown via the title tooltip.
                var label = k.length > 60 ? k.slice(0, 57) + '…' : k;
                var escLabel = $('<div>').text(label).html();
                html += '<option value="' + escVal + '" title="' + escVal + '"' + (k === prev ? ' selected' : '') + '>' + escLabel + '</option>';
            });
            $s.html(html);
            if (keys.indexOf(prev) === -1) $s.val(''); // previously-picked value no longer valid
        });
    }

    $taxSelects.on('change', function() {
        rebuildTaxOptions();
        gf.tax = currentTaxSelections();
        refreshAll();
    });

    rebuildTaxOptions(); // populate Level 0 options (and cascade) on initial load

    // --- Date range filter (mirrors cost_avoidance quick ranges, client-side) -
    function localISO(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    function applyQuickDate(v) {
        var now = new Date();
        if (v === 'this_month') {
            gf.from = localISO(new Date(now.getFullYear(), now.getMonth(), 1));
            gf.to = localISO(new Date(now.getFullYear(), now.getMonth() + 1, 0));
        } else if (v === 'last_month') {
            gf.from = localISO(new Date(now.getFullYear(), now.getMonth() - 1, 1));
            gf.to = localISO(new Date(now.getFullYear(), now.getMonth(), 0));
        } else if (v === 'last_2_weeks') {
            var past = new Date(now);
            past.setDate(past.getDate() - 14);
            gf.from = localISO(past);
            gf.to = localISO(now);
        } else if (v === 'custom') {
            gf.from = $('#dateFrom').val() || '';
            gf.to = $('#dateTo').val() || '';
        } else { // 'all'
            gf.from = '';
            gf.to = '';
        }
    }

    $('#dateQuickFilter').on('change', function() {
        var v = this.value;
        $('#customDateInputs').toggleClass('hidden', v !== 'custom').toggleClass('flex', v === 'custom');
        applyQuickDate(v);
        refreshAll();
    });

    $('#dateFrom, #dateTo').on('change', function() {
        if ($('#dateQuickFilter').val() === 'custom') {
            gf.from = $('#dateFrom').val() || '';
            gf.to = $('#dateTo').val() || '';
            refreshAll();
        }
    });

    // --- Clear ALL global filters --------------------------------------------
    $('#clearAllBtn').on('click', function(e) {
        e.preventDefault();
        gf = { team: [], pic: [], status: '', tax: ['', '', '', '', ''], from: '', to: '' };
        $('.team-filter-btn, .legend-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
        $('.tax-filter').val('');
        rebuildTaxOptions();
        $('#dateQuickFilter').val('all');
        $('#dateFrom, #dateTo').val('');
        $('#customDateInputs').addClass('hidden').removeClass('flex');
        refreshAll();
    });

    // ===== Chart display controls (grouping dimension / granularity) =========
    var barTaxLevelSel = document.getElementById('barTaxLevel');
    $('#barDimSel').on('change', function() {
        var dim = this.value;
        if (dim === 'team') {
            currentBarDim = 'team';
            $(barTaxLevelSel).addClass('hidden');
        } else {
            $(barTaxLevelSel).removeClass('hidden');
            currentBarDim = barTaxLevelSel.value; // 'l0'..'l4'
        }
        updateCharts();
    });
    $(barTaxLevelSel).on('change', function() {
        currentBarDim = this.value;
        updateCharts();
    });
    $('#barSortBy').on('change', function() {
        currentBarSort = this.value;
        updateCharts();
    });
    $('#barSortDir').on('click', function() {
        currentBarDir = (currentBarDir === 'desc') ? 'asc' : 'desc';
        var asc = currentBarDir === 'asc';
        // Same three bars in both states — only the arrow flips, so the swap reads as one
        // icon turning over rather than two unrelated icons trading places.
        $('#barSortDirIcon').attr('d', asc
            ? 'M3 4.5h14.25M3 9h9.75M3 13.5h5.25M17.25 21V9M13.5 12.75L17.25 9l3.75 3.75'
            : 'M3 4.5h14.25M3 9h9.75M3 13.5h5.25M17.25 9v12M13.5 17.25L17.25 21l3.75-3.75');
        $(this).attr('data-dir', currentBarDir)
               .attr('title', asc ? 'Sorted lowest first — click for highest first'
                                  : 'Sorted highest first — click for lowest first')
               .attr('aria-label', 'Sort direction: ' + (asc ? 'ascending' : 'descending'));
        updateCharts();
    });

    $('.trend-gran-btn').on('click', function(e) {
        e.preventDefault();
        currentGran = $(this).attr('data-gran');
        $('.trend-gran-btn').removeClass('bg-indigo-600 text-white').addClass('text-gray-600');
        $(this).addClass('bg-indigo-600 text-white').removeClass('text-gray-600');
        updateCharts();
    });

    // ===== INLINE SLA REVISION ===============================================
    // The PIC's half of the adjustment workflow, in the cells of the table itself.
    // Flow: double-click a cell -> comment popover (new value + mandatory remarks)
    // -> staged locally -> Apply Changes -> recommend the other line items of the
    // same ticket -> POST to ajax_sla_request_submit.php as pending requests.
    //
    // NOTHING here changes what SLA Tracking shows. Only an approval does
    // (ajax_sla_request_decide.php). Every rule enforced below is re-derived from
    // the database on write, so this layer is convenience, never authority.

    // ----- Popover ------------------------------------------------------------
    var popCell = null; // the jQuery <td> the popover is currently editing

    function popErr(msg) {
        $('#slaEditPopError').toggleClass('hidden', !msg).text(msg || '');
    }

    function closeEditPop() {
        $('#slaEditPop').addClass('hidden');
        popCell = null;
    }

    function openEditPop($td) {
        var field   = $td.attr('data-f');
        var f       = EDIT_FIELDS[field];
        var key     = editKey($td.attr('data-ticket'), $td.attr('data-line'));
        var rowData = drawnRows[key];
        if (!f || !rowData) return;

        // Nothing to adjust when canvassing approval has not happened yet — the
        // submit endpoint refuses it too, so say so here instead of letting the
        // user write a justification for a request that cannot be filed.
        if (field === 'cv' && !rowData.cv) {
            Swal.fire({ icon: 'info', title: 'Cannot be adjusted',
                        text: 'Canvassing Date Approver 1 is not filled in yet, so there is no date to adjust.' });
            return;
        }

        popCell = $td;
        var staged = pendingEdits[key];
        var orig   = staged ? staged.orig[field] : (rowData[f.rowKey] || '');

        $('#slaEditPopField').text(f.label);
        $('#slaEditPopLine').text(rowData.ticket + ' · Line ' + rowData.line_item);
        $('#slaEditPopCurrent').text(fieldDisplay(field, orig));

        if (f.kind === 'status') {
            var opts = '', remaining = [];
            for (var i = 0; i < slaStatusOptions.length; i++) {
                if (slaStatusOptions[i] === orig) continue; // a no-op request
                remaining.push(slaStatusOptions[i]);
                opts += '<option value="' + esc(slaStatusOptions[i]) + '">' + esc(slaStatusOptions[i]) + '</option>';
            }
            // Only two statuses exist in this workflow (sla_rev_status_options()),
            // so excluding the current one almost always leaves exactly one choice
            // — pre-select it instead of making the user open a dropdown just to
            // pick the only option. Still left blank if that ever isn't true.
            var statusDefault = (staged && staged.st) ? staged.st : (remaining.length === 1 ? remaining[0] : null);
            $('#slaEditPopStatus').html(opts).removeClass('hidden').val(statusDefault);
            $('#slaEditPopDate').addClass('hidden');
        } else {
            $('#slaEditPopDate').removeClass('hidden')
                .val(staged && staged[field] !== undefined ? staged[field] : orig);
            $('#slaEditPopStatus').addClass('hidden');
        }

        // Remarks and evidence belong to the LINE: the two dates of one row are
        // submitted as a single request with a single justification.
        $('#slaEditPopRemarks').val(staged ? staged.remarks : '');
        $('#slaEditPopEvidence').val(staged ? staged.evidence : '');
        $('#slaEditPopShared').toggleClass('hidden', f.reqType !== 'date');
        $('#slaEditPopRemove').toggleClass('hidden', !(staged && staged[field] !== undefined));
        popErr('');

        // Anchored to the cell, then nudged back inside the viewport — a cell in
        // the last column or the last visible row would otherwise open offscreen.
        var $pop = $('#slaEditPop').removeClass('hidden');
        var r = $td[0].getBoundingClientRect();
        var w = $pop.outerWidth(), h = $pop.outerHeight();
        var left = Math.min(r.left, $(window).width() - w - 12);
        var top  = (r.bottom + h + 12 < $(window).height()) ? r.bottom + 4 : Math.max(8, r.top - h - 4);
        $pop.css({ left: Math.max(8, left) + 'px', top: top + 'px' });
        $(f.kind === 'status' ? '#slaEditPopStatus' : '#slaEditPopDate').focus();
    }

    // A staged cell keeps .sla-editable, so this one handler also reopens it —
    // on its staged value, so a change can be corrected or removed without
    // discarding everything.
    $('#slaTable tbody').on('dblclick', 'td.sla-editable', function() {
        openEditPop($(this));
    });

    // Locked cells are not silently inert: double-clicking one explains why.
    $('#slaTable tbody').on('dblclick', 'td.sla-pending', function() {
        Swal.fire({ icon: 'info', title: 'Waiting for approval',
                    text: $(this).attr('title') || 'This line already has a request waiting for approval.' });
    });

    $('#slaEditPopCancel').on('click', closeEditPop);

    $('#slaEditPopRemove').on('click', function() {
        if (!popCell) return;
        var key = editKey(popCell.attr('data-ticket'), popCell.attr('data-line'));
        var field = popCell.attr('data-f');
        if (pendingEdits[key]) {
            delete pendingEdits[key][field];
            // An entry with no field left is not a change any more.
            if (pendingEdits[key].d === undefined && pendingEdits[key].cv === undefined &&
                pendingEdits[key].st === undefined) {
                delete pendingEdits[key];
            }
        }
        unpaintStaged(popCell);
        popCell.closest('tr').toggleClass('sla-row-staged', !!pendingEdits[key]);
        closeEditPop();
        updateEditBar();
    });

    $('#slaEditPopOk').on('click', function() {
        if (!popCell) return;
        var field   = popCell.attr('data-f');
        var f       = EDIT_FIELDS[field];
        var key     = editKey(popCell.attr('data-ticket'), popCell.attr('data-line'));
        var rowData = drawnRows[key];
        var staged  = pendingEdits[key];
        var orig    = staged ? staged.orig[field] : (rowData[f.rowKey] || '');

        var value = (f.kind === 'status') ? $('#slaEditPopStatus').val() : $('#slaEditPopDate').val();
        if (!value) { popErr('Please fill in the new value.'); return; }
        if (value === orig) { popErr('This is the same as the current value.'); return; }
        if (f.kind === 'date') {
            // Mirrors sla_rev_validate_date() so an obvious typo is caught before
            // it costs an approval round trip.
            var maxDate = new Date(); maxDate.setFullYear(maxDate.getFullYear() + 1);
            if (value > maxDate.toISOString().slice(0, 10)) { popErr('That date is too far in the future.'); return; }
            if (value < '2000-01-01') { popErr('That date is too far in the past.'); return; }
        }

        var remarks = $('#slaEditPopRemarks').val().trim();
        if (remarks.length < 10) { popErr('Remarks are required, at least 10 characters.'); return; }

        var evidence = $('#slaEditPopEvidence').val().trim();
        if (evidence !== '' && !/^https?:\/\//i.test(evidence)) {
            popErr('The evidence link must start with http:// or https://.');
            return;
        }

        if (!staged) {
            // orig snapshots the values as the server sent them, so the "was"
            // line stays truthful no matter how often the cell is re-edited.
            staged = pendingEdits[key] = {
                ticket: rowData.ticket, line: rowData.line_item,
                item: rowData.item, cno: rowData.cno || '', dur: rowData.dur,
                orig: { d: rowData.d || '', cv: rowData.cv || '', st: rowData.status || '' },
                remarks: '', evidence: ''
            };
        }
        staged[field]   = value;
        staged.remarks  = remarks;   // shared across both dates of this line
        staged.evidence = evidence;

        paintStaged(popCell);
        // The other date of the same line now carries the updated remarks in its
        // tooltip too, so repaint it as well.
        popCell.closest('tr').addClass('sla-row-staged').find('td.sla-staged').each(function() { paintStaged($(this)); });
        closeEditPop();
        updateEditBar();
    });

    // Click-away / Escape close, the way a comment box does.
    $(document).on('mousedown', function(e) {
        if ($('#slaEditPop').hasClass('hidden')) return;
        if ($(e.target).closest('#slaEditPop').length) return;
        if ($(e.target).closest('#slaTable td.sla-cell').length) return; // let dblclick move the popover
        closeEditPop();
    });
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$('#slaEditPop').hasClass('hidden')) closeEditPop();
    });

    // ----- Staged-changes bar -------------------------------------------------
    function editCounts() {
        var lines = 0, changes = 0;
        for (var k in pendingEdits) {
            if (!pendingEdits.hasOwnProperty(k)) continue;
            lines++;
            ['d', 'cv', 'st'].forEach(function(f) {
                if (pendingEdits[k][f] !== undefined) changes++;
            });
        }
        return { lines: lines, changes: changes };
    }

    function updateEditBar() {
        var c = editCounts();
        $('#slaEditBar').toggleClass('hidden', c.changes === 0);
        $('#slaEditBarText').text(
            c.changes + ' change' + (c.changes === 1 ? '' : 's') +
            ' on ' + c.lines + ' line' + (c.lines === 1 ? '' : 's'));
    }

    // The table is serverSide: paging, sorting and searching all replace the
    // tbody. createdCell repaints each cell as it is rebuilt, so staged edits
    // survive navigation — this only keeps the counter honest.
    table.on('draw.dt', updateEditBar);

    // Staged edits live only in this tab. Leaving without applying loses them.
    $(window).on('beforeunload', function() {
        if (editCounts().changes > 0) return 'You have changes that have not been submitted yet.';
    });

    $('#slaDiscardBtn').on('click', function() {
        Swal.fire({
            icon: 'warning', title: 'Discard all changes?',
            text: 'Every change you staged will be removed. Nothing was submitted.',
            showCancelButton: true, confirmButtonText: 'Discard', cancelButtonText: 'Keep editing',
            confirmButtonColor: '#dc2626'
        }).then(function(res) {
            if (!res.isConfirmed) return;
            pendingEdits = {};
            $('#slaTable tbody td.sla-staged').each(function() { unpaintStaged($(this)); });
            $('#slaTable tbody tr.sla-row-staged').removeClass('sla-row-staged');
            closeEditPop();
            updateEditBar();
        });
    });

    // ----- Apply: review + sibling recommendation -----------------------------
    // One submission per (line, request_type): the two dates of a line travel
    // together as 'date', the status override is its own request. This is the
    // shape ajax_sla_request_submit.php accepts, unchanged.
    var applyGroups = [];   // [{ ticket, line, type, changes:[{field,from,to}], remarks, evidence, siblings:[], sort:{} }]

    function buildApplyGroups() {
        var groups = [];
        for (var k in pendingEdits) {
            if (!pendingEdits.hasOwnProperty(k)) continue;
            var e = pendingEdits[k];
            var dateChanges = [];
            ['d', 'cv'].forEach(function(f) {
                if (e[f] !== undefined) dateChanges.push({ field: f, from: e.orig[f], to: e[f] });
            });
            if (dateChanges.length) {
                groups.push({ ticket: e.ticket, line: e.line, type: 'date', changes: dateChanges,
                              remarks: e.remarks, evidence: e.evidence, src: e,
                              siblings: [], ticked: {}, sort: { key: 'cno', dir: 1 } });
            }
            if (e.st !== undefined) {
                groups.push({ ticket: e.ticket, line: e.line, type: 'sla_status',
                              changes: [{ field: 'st', from: e.orig.st, to: e.st }],
                              remarks: e.remarks, evidence: e.evidence, src: e,
                              siblings: [], ticked: {}, sort: { key: 'cno', dir: 1 } });
            }
        }
        return groups;
    }

    // A sibling starts TICKED only when taking the same change is obviously
    // right: it currently holds the same dates as the edited line, and it has
    // not been rejected before. Anything else is offered but left to the user —
    // silently filing a request they did not look at is the failure mode here.
    function defaultTicked(g, s) {
        if (s.req_status === 'rejected') return false;
        if (g.type === 'date') return s.d === g.src.orig.d && s.cv === g.src.orig.cv;
        return s.status === g.src.orig.st;
    }

    function siblingHint(g, s) {
        if (s.req_status === 'rejected') return 'A previous request for this line was rejected.';
        if (g.type === 'date' && (s.d !== g.src.orig.d || s.cv !== g.src.orig.cv)) return 'Its dates differ from the edited line.';
        if (g.type === 'sla_status' && s.status !== g.src.orig.st) return 'Its status differs from the edited line.';
        return '';
    }

    function sortSiblings(g) {
        var k = g.sort.key, dir = g.sort.dir;
        g.siblings.sort(function(a, b) {
            var av = (a[k] || ''), bv = (b[k] || '');
            if (av === bv) return String(a.line_item).localeCompare(String(b.line_item));
            return av > bv ? dir : -dir;
        });
    }

    var SIB_COLS = [
        { key: 'line_item', label: 'Line' },
        { key: 'item',      label: 'Item Description' },
        { key: 'cno',       label: 'Canvassing No' },
        { key: 'd',         label: 'PR Date' },
        { key: 'cv',        label: 'Canvassing Date Approver 1' }
    ];

    function renderApplyBody() {
        if (!applyGroups.length) {
            $('#slaApplyBody').html('<p class="text-gray-500 text-xs">No changes to submit.</p>');
            return;
        }
        var html = '';
        applyGroups.forEach(function(g, gi) {
            html += '<div class="border border-gray-200 rounded-lg mb-4">' +
                '<div class="px-4 py-3 bg-gray-50 border-b border-gray-200 flex flex-wrap items-center gap-2">' +
                    '<span class="text-xs font-bold text-gray-900">' + esc(g.ticket) + '</span>' +
                    '<span class="text-[10px] text-gray-500">Line ' + esc(g.line) + '</span>' +
                    '<span class="ml-auto text-[10px] font-bold uppercase px-2 py-0.5 rounded-full ' +
                        (g.type === 'date' ? 'bg-indigo-100 text-indigo-800' : 'bg-purple-100 text-purple-800') + '">' +
                        (g.type === 'date' ? 'Date Revision' : 'SLA Status Override') + '</span>' +
                '</div>' +
                '<div class="p-4 space-y-3">' +
                    '<div class="space-y-1">';
            g.changes.forEach(function(c) {
                html += '<div class="grid grid-cols-3 gap-2 text-xs">' +
                            '<span class="text-gray-500">' + esc(EDIT_FIELDS[c.field].label) + '</span>' +
                            '<span class="font-semibold text-gray-700 line-through">' + esc(fieldDisplay(c.field, c.from)) + '</span>' +
                            '<span class="font-bold text-indigo-700">&rarr; ' + esc(fieldDisplay(c.field, c.to)) + '</span>' +
                        '</div>';
            });
            html += '</div>' +
                '<p class="text-xs whitespace-pre-wrap"><span class="text-gray-500">Remarks:</span> ' + esc(g.remarks) + '</p>' +
                (g.evidence ? '<p class="text-xs break-all"><span class="text-gray-500">Evidence:</span> ' + esc(g.evidence) + '</p>' : '');

            // Recommendation. Same ticket, different line item, still free to file
            // this type of request — resolved server-side by ajax_sla_siblings.php
            // because a sibling is very often on a page that was never loaded.
            if (g.siblings.length) {
                sortSiblings(g);
                html += '<div class="border border-indigo-100 bg-indigo-50 rounded-lg p-3">' +
                    '<div class="flex items-center justify-between gap-2 mb-2">' +
                        '<p class="text-[11px] font-bold text-indigo-900">Apply the same change to other line items of this ticket?</p>' +
                        '<label class="text-[10px] font-semibold text-indigo-800 flex items-center gap-1 cursor-pointer">' +
                            '<input type="checkbox" class="sib-all rounded border-gray-300" data-g="' + gi + '"> Select all</label>' +
                    '</div>' +
                    '<div class="overflow-x-auto"><table class="min-w-full text-left"><thead><tr class="border-b border-indigo-200">' +
                        '<th class="p-1 w-8"></th>';
                SIB_COLS.forEach(function(c) {
                    var arrow = (g.sort.key === c.key) ? (g.sort.dir === 1 ? ' ▲' : ' ▼') : '';
                    html += '<th class="p-1 text-[10px] font-bold text-indigo-900 uppercase whitespace-nowrap cursor-pointer sib-sort" ' +
                            'data-g="' + gi + '" data-key="' + c.key + '">' + esc(c.label) + arrow + '</th>';
                });
                html += '</tr></thead><tbody>';
                g.siblings.forEach(function(s) {
                    var hint = siblingHint(g, s);
                    html += '<tr class="border-b border-indigo-100 align-top">' +
                        '<td class="p-1"><input type="checkbox" class="sib-pick rounded border-gray-300" ' +
                            'data-g="' + gi + '" data-line="' + esc(s.line_item) + '"' + (g.ticked[s.line_item] ? ' checked' : '') + '></td>' +
                        '<td class="p-1 text-[11px] font-semibold text-gray-800 whitespace-nowrap">' + esc(s.line_item) + '</td>' +
                        '<td class="p-1 text-[11px] text-gray-700 max-w-xs">' + esc(s.item) +
                            (hint ? '<span class="block text-[10px] text-amber-700">' + esc(hint) + '</span>' : '') + '</td>' +
                        '<td class="p-1 text-[11px] text-gray-600 whitespace-nowrap">' + esc(s.cno || '-') + '</td>' +
                        '<td class="p-1 text-[11px] text-gray-600 whitespace-nowrap">' + esc(s.d ? fmtDate(s.d) : '-') + '</td>' +
                        '<td class="p-1 text-[11px] text-gray-600 whitespace-nowrap">' + esc(s.cv ? fmtDate(s.cv) : '-') + '</td>' +
                    '</tr>';
                });
                html += '</tbody></table></div></div>';
            }
            html += '</div></div>';
        });
        $('#slaApplyBody').html(html);
    }

    $('#slaApplyBody').on('change', '.sib-pick', function() {
        var g = applyGroups[$(this).data('g')];
        if (g) g.ticked[$(this).data('line')] = this.checked;
    });
    $('#slaApplyBody').on('change', '.sib-all', function() {
        var gi = $(this).data('g'), g = applyGroups[gi], on = this.checked;
        if (!g) return;
        g.siblings.forEach(function(s) { g.ticked[s.line_item] = on; });
        $('#slaApplyBody').find('.sib-pick[data-g="' + gi + '"]').prop('checked', on);
    });
    $('#slaApplyBody').on('click', '.sib-sort', function() {
        var g = applyGroups[$(this).data('g')], key = $(this).data('key');
        if (!g) return;
        g.sort = { key: key, dir: (g.sort.key === key ? -g.sort.dir : 1) };
        renderApplyBody();
    });

    function closeApplyModal() {
        $('#slaApplyModalBg').addClass('hidden').removeClass('flex');
    }
    $('#closeSlaApplyBtn, #cancelSlaApplyBtn').on('click', closeApplyModal);
    $('#slaApplyModalBg').on('click', function(e) {
        if (e.target === this) closeApplyModal(); // backdrop only, not the card
    });

    $('#slaApplyBtn').on('click', function() {
        applyGroups = buildApplyGroups();
        if (!applyGroups.length) return;

        closeEditPop();
        $('#slaApplyError').addClass('hidden').text('');
        $('#confirmSlaApplyBtn').prop('disabled', false).text(slaAutoApprove ? 'Apply Changes' : 'Submit for Approval');
        $('#slaApplyBody').html('<p class="text-gray-500 text-xs">Loading line item recommendations…</p>');
        $('#slaApplyModalBg').removeClass('hidden').addClass('flex');

        // Loaded fresh on every open, never cached: an approval elsewhere can
        // change which lines still qualify, and a stale list would recommend a
        // line the server then skips.
        $.ajax({
            url: 'ajax_sla_siblings.php',
            type: 'POST',
            dataType: 'json',
            data: {
                sources: JSON.stringify(applyGroups.map(function(g) {
                    return { ticket: g.ticket, line: g.line, type: g.type };
                }))
            },
            success: function(resp) {
                // A failure here costs the recommendation, not the submission —
                // the edits the user actually made are still reviewable below.
                var map = (resp && resp.status === 'success') ? (resp.siblings || {}) : {};
                applyGroups.forEach(function(g) {
                    g.siblings = map[g.ticket + '|' + g.line] || [];
                    g.siblings.forEach(function(s) { g.ticked[s.line_item] = defaultTicked(g, s); });
                });
                renderApplyBody();
                if (resp && resp.status !== 'success') {
                    $('#slaApplyError').removeClass('hidden')
                        .text(resp.message || 'Could not load the line item recommendations.');
                }
            },
            error: function() {
                renderApplyBody();
                $('#slaApplyError').removeClass('hidden')
                    .text('Could not load the line item recommendations. Your own changes can still be submitted.');
            }
        });
    });

    $('#confirmSlaApplyBtn').on('click', function() {
        var btn = $(this).prop('disabled', true).text('Submitting…');
        $('#slaApplyError').addClass('hidden').text('');

        // Sequential, one request_type at a time. Partial success is normal and
        // expected — a line can be skipped for a reason only the server knows —
        // so results are collected and reported rather than aborted on.
        var created = [], skipped = [], failed = [];
        var queue = applyGroups.slice();

        function sendNext() {
            if (!queue.length) return finish();
            var g = queue.shift();

            var extra = [];
            g.siblings.forEach(function(s) { if (g.ticked[s.line_item]) extra.push(s.line_item); });

            var payload = {
                ticket_no: g.ticket,
                ticket_line_item: g.line,
                line_items: JSON.stringify(extra),
                request_type: g.type,
                remarks: g.remarks,
                evidence_url: g.evidence || ''
            };
            if (g.type === 'sla_status') {
                payload.requested_value = g.changes[0].to;
            } else {
                // Only the dates actually changed are sent. A field left alone is
                // not part of this request.
                g.changes.forEach(function(c) {
                    if (c.field === 'd')  payload.requested_pr_date = c.to;
                    if (c.field === 'cv') payload.requested_canvassing_date = c.to;
                });
            }

            $.ajax({
                url: 'ajax_sla_request_submit.php', type: 'POST', dataType: 'json', data: payload,
                success: function(resp) {
                    (resp.created || []).forEach(function(c) { created.push(g.ticket + ' / ' + c.line); });
                    (resp.skipped || []).forEach(function(s) { skipped.push(g.ticket + ' / ' + s.line + ': ' + s.message); });
                    if (resp.status !== 'success' && !(resp.skipped || []).length) {
                        failed.push(g.ticket + ' / ' + g.line + ': ' + (resp.message || 'Failed.'));
                    }
                    sendNext();
                },
                error: function() {
                    failed.push(g.ticket + ' / ' + g.line + ': connection problem.');
                    sendNext();
                }
            });
        }

        function finish() {
            btn.prop('disabled', false).text(slaAutoApprove ? 'Apply Changes' : 'Submit for Approval');

            if (!created.length) {
                $('#slaApplyError').removeClass('hidden')
                    .text(skipped.concat(failed).join(' | ') || 'No request could be submitted.');
                return;
            }

            // Only what actually reached the database is cleared. Anything the
            // server refused stays staged, so it can be corrected and retried.
            applyGroups.forEach(function(g) {
                var e = pendingEdits[editKey(g.ticket, g.line)];
                if (!e) return;
                if (g.type === 'date') { delete e.d; delete e.cv; } else { delete e.st; }
                if (e.d === undefined && e.cv === undefined && e.st === undefined) {
                    delete pendingEdits[editKey(g.ticket, g.line)];
                }
            });

            closeApplyModal();
            var html = '<p class="text-sm">' + created.length + ' line' + (created.length === 1 ? '' : 's') +
                       (slaAutoApprove ? ' adjusted.</p>' : ' submitted for approval.</p>');
            var problems = skipped.concat(failed);
            if (problems.length) {
                html += '<p class="text-xs text-left mt-3 mb-1 font-bold text-amber-700">' + (slaAutoApprove ? 'Not applied' : 'Not submitted') + ' (' + problems.length + '):</p>' +
                        '<ul class="text-[11px] text-left text-gray-600 list-disc pl-5 space-y-0.5">';
                problems.forEach(function(p) { html += '<li>' + esc(p) + '</li>'; });
                html += '</ul>';
            }
            Swal.fire({ icon: problems.length ? 'warning' : 'success', title: 'Submitted', html: html });

            updateEditBar();
            table.ajax.reload(null, false); // pending markers come back from the server
        }

        sendNext();
    });

    $('#slaTable tbody').on('click', '.sla-history-btn', function() {
        var ticket = $(this).data('ticket');
        var line = $(this).data('line');
        $('#histSubtitle').text('PR ' + ticket + ' / Line ' + line);
        $('#histBody').html('<p class="text-gray-500 text-xs">Memuat riwayat...</p>');
        $('#slaHistoryModalBg').removeClass('hidden').addClass('flex');

        $.ajax({
            url: 'ajax_sla_request_history.php',
            type: 'POST',
            dataType: 'json',
            data: { ticket_no: ticket, ticket_line_item: line },
            success: function(resp) {
                if (resp.status !== 'success') {
                    $('#histBody').html('<p class="text-red-600 text-xs">' + esc(resp.message || 'Gagal memuat riwayat.') + '</p>');
                    return;
                }
                if (!resp.items.length) {
                    $('#histBody').html('<p class="text-gray-500 text-xs">Belum ada pengajuan penyesuaian untuk PR line ini.</p>');
                    return;
                }
                var badge = { pending: 'bg-amber-100 text-amber-800', approved: 'bg-green-100 text-green-800', rejected: 'bg-gray-200 text-gray-700' };
                var html = '<div class="space-y-4">';
                resp.items.forEach(function(it) {
                    html += '<div class="border border-gray-200 rounded-lg p-4">' +
                        '<div class="flex justify-between items-start mb-2 gap-2">' +
                            '<span class="font-bold text-indigo-700 text-xs">' + esc(it.type_label) + '</span>' +
                            '<span class="shrink-0 font-bold px-2 py-0.5 rounded-full text-[10px] ' + (badge[it.status] || 'bg-gray-100') + '">' + esc(it.status_label) + '</span>' +
                        '</div>' +
                        '<div class="text-xs mb-2 space-y-1">';
                    // A Date Adjustment can carry two changes; each is listed
                    // with its own original -> requested pair.
                    (it.changes || []).forEach(function(c) {
                        html += '<div class="grid grid-cols-3 gap-2">' +
                                    '<span class="text-gray-500">' + esc(c.label) + '</span>' +
                                    '<span class="font-semibold">' + esc(c.from) + '</span>' +
                                    '<span class="font-semibold text-indigo-700">&rarr; ' + esc(c.to) + '</span>' +
                                '</div>';
                    });
                    html += '</div>' +
                        '<p class="text-xs mb-1 whitespace-pre-wrap"><span class="text-gray-500">Remarks:</span> ' + esc(it.remarks || '-') + '</p>';
                    if (it.evidence_url) {
                        html += '<p class="text-xs mb-1"><a href="' + esc(it.evidence_url) + '" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline break-all">Buka link evidence</a></p>';
                    }
                    html += '<p class="text-[11px] text-gray-500 mt-2 pt-2 border-t border-gray-100">' +
                            'Diajukan oleh <strong>' + esc(it.requested_by) + '</strong> pada ' + esc(it.requested_at);
                    if (it.status !== 'pending') {
                        html += '<br>' + (it.status === 'approved' ? 'Disetujui' : 'Ditolak') +
                                ' oleh <strong>' + esc(it.decided_by || '-') + '</strong> pada ' + esc(it.decided_at || '-');
                        if (it.decision_notes) html += '<br>Catatan manager: ' + esc(it.decision_notes);
                    } else if (it.approver_name) {
                        html += '<br>Menunggu approval <strong>' + esc(it.approver_name) + '</strong>';
                    }
                    html += '</p></div>';
                });
                html += '</div>';
                $('#histBody').html(html);
            },
            error: function() {
                $('#histBody').html('<p class="text-red-600 text-xs">Koneksi bermasalah. Coba lagi.</p>');
            }
        });
    });

    $('#closeSlaHistoryBtn').on('click', function() {
        $('#slaHistoryModalBg').addClass('hidden').removeClass('flex');
    });

    // ===== SLA APPROVALS INBOX (was pages/sla_requests.php) ==================
    // Opened from the "Manage SLA Request" button. Every request this approver can
    // see is embedded once as apprDetails; tabs and search filter it entirely
    // client-side, so switching tabs never round-trips to the server.
    var apprDetails   = <?= json_encode($appr_details, JSON_UNESCAPED_UNICODE) ?>;
    var apprLoadError = <?= json_encode($appr_load_error) ?>;
    var apprTab = 'pending';
    var apprCurrent = null; // the detail-modal's current apprDetails entry

    var apprBadge = {
        pending:  'bg-amber-100 text-amber-800 border-amber-300',
        approved: 'bg-green-100 text-green-800 border-green-300',
        rejected: 'bg-red-100 text-red-800 border-red-300'
    };

    function apprCounts() {
        var c = { pending: 0, approved: 0, rejected: 0, all: 0 };
        apprDetails.forEach(function(d) { if (c[d.status] !== undefined) c[d.status]++; });
        c.all = c.pending + c.approved + c.rejected;
        return c;
    }

    function apprFilteredList(tab, query) {
        var q = (query || '').trim().toLowerCase();
        var list = apprDetails.filter(function(d) {
            if (tab !== 'all' && d.status !== tab) return false;
            return q === '' || d.search.indexOf(q) !== -1;
        });
        // Pending oldest-first (act on the longest wait); decided newest-first.
        // 'all' floats still-pending groups to the top for the same reason,
        // then shows the rest newest-first — mirrors the old page's ORDER BY.
        list.sort(function(a, b) {
            if (tab === 'all' && (a.status === 'pending') !== (b.status === 'pending')) {
                return a.status === 'pending' ? -1 : 1;
            }
            if (tab === 'pending') return a.at_iso < b.at_iso ? -1 : (a.at_iso > b.at_iso ? 1 : 0);
            var ax = a.dec_at_iso || a.at_iso, ay = b.dec_at_iso || b.at_iso;
            return ay < ax ? -1 : (ay > ax ? 1 : 0);
        });
        return list;
    }

    function renderApprTable() {
        var counts = apprCounts();
        var APPR_TAB_BASE = 'appr-tab-btn text-xs font-bold px-4 py-2 rounded-lg border transition ';
        var APPR_TAB_INACTIVE = 'bg-white text-gray-600 border-gray-300 hover:bg-gray-50';
        $('.appr-tab-btn').each(function() {
            var $btn = $(this), tab = $btn.attr('data-tab');
            $btn.find('.appr-tab-count').text('(' + counts[tab] + ')');
            var active = tab === apprTab;
            $btn.attr('class', APPR_TAB_BASE + (active
                ? $btn.attr('data-active-class') + ' ring-offset-1 shadow-sm'
                : APPR_TAB_INACTIVE));
        });

        var list = apprFilteredList(apprTab, $('#apprSearch').val());
        var $activeTabBtn = $('.appr-tab-btn[data-tab="' + apprTab + '"]');

        if (!list.length) {
            $('#apprTableWrap').addClass('hidden');
            $('#apprEmpty').removeClass('hidden').text(
                ($('#apprSearch').val() || '').trim() !== ''
                    ? 'No results for this search.'
                    : $activeTabBtn.attr('data-empty'));
            updateApprBulkBar();
            return;
        }
        $('#apprEmpty').addClass('hidden');
        $('#apprTableWrap').removeClass('hidden');

        var html = list.map(function(d) {
            var idx = apprDetails.indexOf(d);
            var lineSub = d.lines.length > 1 ? (d.lines.length + ' line items') : ('Line ' + esc(d.lines[0].line));
            var badge = apprBadge[d.status] || 'bg-gray-100 text-gray-700 border-gray-300';
            // Only a pending request can be bulk-decided — a decided one gets an
            // empty cell instead of a disabled checkbox, one less thing to explain.
            var checkCell = d.status === 'pending'
                ? '<td class="p-3"><input type="checkbox" class="appr-row-check" data-idx="' + idx + '"' + (apprSelected.has(idx) ? ' checked' : '') + '></td>'
                : '<td class="p-3"></td>';
            return '<tr class="border-b hover:bg-indigo-50 transition align-top">' +
                checkCell +
                '<td class="p-3 text-xs font-semibold text-indigo-700">' + esc(d.type) + '</td>' +
                '<td class="p-3 text-sm font-medium text-gray-900">' + esc(d.ticket) +
                    '<span class="block text-xs font-normal text-gray-500">' + lineSub + '</span></td>' +
                '<td class="p-3 text-xs text-gray-800 font-bold">' + esc(d.by) + '</td>' +
                '<td class="p-3 text-sm font-bold text-gray-900 whitespace-nowrap">' + d.value_to + '</td>' +
                '<td class="p-3 text-xs text-gray-700 max-w-md"><p class="whitespace-pre-wrap">' + esc(d.remarks) + '</p>' +
                    (d.evidence ? '<p class="mt-1"><a href="' + esc(d.evidence) + '" target="_blank" rel="noopener noreferrer" class="text-indigo-600 hover:underline break-all">Open evidence link</a></p>' : '') +
                '</td>' +
                '<td class="p-3 text-xs text-gray-600 whitespace-nowrap">' + esc(d.at) + '</td>' +
                '<td class="p-3 whitespace-nowrap"><span class="inline-block text-[10px] font-bold px-2 py-1 rounded border ' + badge + '">' + esc(d.status_lb) + '</span></td>' +
                '<td class="p-3 whitespace-nowrap text-center">' +
                    '<div class="inline-flex flex-col items-stretch gap-1">' +
                    // Quick actions decide every line of the submission at once (same
                    // as opening Detail and leaving every checkbox ticked). Approve/
                    // Reject sit on their own row, compact; View Detail stretches to
                    // that row's full width (items-stretch + w-full) so the two rows
                    // line up edge-to-edge instead of the button sitting narrower.
                    (d.status === 'pending'
                        ? '<div class="flex gap-1">' +
                              '<button type="button" class="appr-quick-btn flex-1 bg-green-600 hover:bg-green-700 text-white text-[11px] font-bold px-2.5 py-1 rounded transition" data-idx="' + idx + '" data-action="approve">Approve</button>' +
                              '<button type="button" class="appr-quick-btn flex-1 bg-gray-500 hover:bg-gray-600 text-white text-[11px] font-bold px-2.5 py-1 rounded transition" data-idx="' + idx + '" data-action="reject">Reject</button>' +
                          '</div>'
                        : '') +
                    '<button type="button" class="appr-detail-btn w-full bg-indigo-600 hover:bg-indigo-700 text-white text-[11px] font-bold px-2.5 py-1 rounded transition" data-idx="' + idx + '">View Detail</button>' +
                    '</div>' +
                '</td>' +
            '</tr>';
        }).join('');
        $('#apprTableBody').html(html);
        updateApprSelectAllState();
        updateApprBulkBar();
    }

    // ----- Bulk selection ------------------------------------------------
    // Indices into apprDetails, not DOM elements — survives tab/search
    // re-renders, so ticking a request in Pending then switching to All still
    // shows it ticked instead of silently losing the selection.
    var apprSelected = new Set();

    function updateApprSelectAllState() {
        var $boxes = $('.appr-row-check');
        var total = $boxes.length, checked = $boxes.filter(':checked').length;
        $('#apprSelectAll').prop('checked', total > 0 && checked === total)
                            .prop('indeterminate', checked > 0 && checked < total);
    }

    function updateApprBulkBar() {
        var n = apprSelected.size;
        $('#apprBulkBar').toggleClass('hidden', n === 0);
        $('#apprBulkCount').text(n + ' request' + (n === 1 ? '' : 's') + ' selected');
    }

    $('#apprTableBody').on('change', '.appr-row-check', function() {
        var idx = $(this).data('idx');
        if (this.checked) apprSelected.add(idx); else apprSelected.delete(idx);
        updateApprSelectAllState();
        updateApprBulkBar();
    });

    $('#apprSelectAll').on('change', function() {
        var checked = this.checked;
        $('.appr-row-check').each(function() {
            $(this).prop('checked', checked);
            var idx = $(this).data('idx');
            if (checked) apprSelected.add(idx); else apprSelected.delete(idx);
        });
        updateApprBulkBar();
    });

    $('#apprBulkApproveBtn').on('click', function() { apprBulkDecision('approve'); });
    $('#apprBulkRejectBtn').on('click',  function() { apprBulkDecision('reject');  });

    function apprBulkDecision(action) {
        var entries = Array.from(apprSelected).map(function(idx) { return apprDetails[idx]; }).filter(Boolean);
        if (!entries.length) return;
        var ids = [];
        entries.forEach(function(e) { e.lines.forEach(function(l) { ids.push(l.id); }); });
        var reqN = entries.length, lineN = ids.length;
        openApprDecisionForIds(ids, action,
            reqN + ' request' + (reqN > 1 ? 's' : '') + ' — ' + lineN + ' line item' + (lineN > 1 ? 's' : '') + ' total');
    }

    $('#slaApprInboxBtn').on('click', function() {
        if (apprLoadError) {
            $('#apprLoadError').removeClass('hidden').text(apprLoadError);
        } else {
            $('#apprLoadError').addClass('hidden');
        }
        apprTab = 'pending';
        $('#apprSearch').val('');
        apprSelected.clear();
        renderApprTable();
        $('#slaApprModalBg').removeClass('hidden').addClass('flex');
    });

    function closeApprModal() {
        $('#slaApprModalBg').addClass('hidden').removeClass('flex');
    }
    $('#closeSlaApprBtn, #apprCloseFooterBtn').on('click', closeApprModal);
    $('#slaApprModalBg').on('click', function(e) {
        if (e.target === this) closeApprModal(); // backdrop only, not the card
    });

    $('#apprTabs').on('click', '.appr-tab-btn', function() {
        apprTab = $(this).attr('data-tab');
        renderApprTable();
    });
    $('#apprSearch').on('keyup', renderApprTable);

    // ----- Detail modal --------------------------------------------------
    $('#apprTableBody').on('click', '.appr-detail-btn', function() {
        var d = apprDetails[$(this).data('idx')];
        if (!d) return;
        apprCurrent = d;

        var decided = d.status !== 'pending';

        $('#dtTicket').text(d.ticket);
        $('#dtLineCount').text(d.lines.length > 1 ? '/ ' + d.lines.length + ' line items' : '/ Line ' + d.lines[0].line);
        $('#dtType').text(d.type);
        $('#dtStatus').text(d.status_lb)
            .attr('class', 'shrink-0 text-[10px] font-bold px-2 py-1 rounded border ' + (apprBadge[d.status] || 'bg-gray-100 text-gray-700 border-gray-300'));

        $('#dtTt').text(d.tt || '-');
        $('#dtReqtor').text(d.reqtor || '-');
        $('#dtPg').text(d.pg || '-');

        $('#dtBy').text(d.by);
        $('#dtAt').text(d.at);
        // No approver on file means the request fell through to the Category
        // Manager queue — say that instead of showing an empty dash.
        $('#dtApprover').text(d.approver || 'Category Manager queue');

        // One row per line item of this submission. Every line carries its own
        // request id, because that is still what the decision endpoint acts on.
        var html = '';
        d.lines.forEach(function(l) {
            var from = '', to = '';
            (l.pairs || []).forEach(function(p) {
                var lbl = (l.pairs.length > 1) ? '<span class="block text-[10px] text-gray-400 uppercase">' + esc(p.label) + '</span>' : '';
                from += '<span class="block">' + lbl + esc(p.from) + '</span>';
                to   += '<span class="block">' + lbl + esc(p.to)   + '</span>';
            });
            html += '<tr class="border-b border-gray-100 last:border-0 align-top">' +
                        '<td class="p-2 dt-pick-col"><input type="checkbox" class="dt-line" value="' + l.id + '" checked></td>' +
                        '<td class="p-2 text-xs font-semibold text-gray-700 whitespace-nowrap">' + esc(l.line) + '</td>' +
                        '<td class="p-2 text-xs text-gray-700">' + esc(l.item || '-') + '</td>' +
                        '<td class="p-2 text-xs text-gray-700 whitespace-nowrap">' + esc(l.cno || '-') + '</td>' +
                        '<td class="p-2 text-xs text-gray-700 whitespace-nowrap">' + esc(l.pr || '-') + '</td>' +
                        '<td class="p-2 text-xs text-gray-700 whitespace-nowrap">' + esc(l.cv || '-') + '</td>' +
                        '<td class="p-2 text-xs text-gray-700 whitespace-nowrap">' + (from || '-') + '</td>' +
                        '<td class="p-2 text-xs text-gray-700 whitespace-nowrap">' + esc(l.changed || '-') + '</td>' +
                        '<td class="p-2 text-xs font-bold text-gray-900 whitespace-nowrap">' + (to || '-') + '</td>' +
                    '</tr>';
        });
        $('#dtLines').html(html);

        $('.dt-pick-col').toggle(!decided);
        $('#dtToggleAll').prop('checked', true).prop('indeterminate', false);
        $('#dtPickHint').toggleClass('hidden', decided || d.lines.length < 2)
            .text('Untick a line to leave it out of this decision.');

        $('#dtRemarks').text(d.remarks || '-');
        $('#dtEvidenceWrap').toggleClass('hidden', !d.evidence);
        if (d.evidence) $('#dtEvidence').attr('href', d.evidence);

        $('#dtDecisionWrap').toggleClass('hidden', !decided);
        if (decided) {
            $('#dtDecisionWrap').attr('class', 'rounded-lg border p-3 ' +
                (d.status === 'approved' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'));
            $('#dtDecisionWrap').find('p').first().text(d.status_lb);
            $('#dtDecBy').text(d.dec_by || '-');
            $('#dtDecAt').text(d.dec_at || '-');
            $('#dtDecNotes').text(d.dec_notes || '');
        }

        // The notes box only makes sense while there's still a decision to make —
        // a decided request already shows its real notes in dtDecisionWrap above.
        $('#dtNotesWrap').toggleClass('hidden', decided);
        $('#dtNotes').val('');
        $('#dtNotesError').addClass('hidden').text('');

        // Only a pending request can be decided; a decided one opens read-only.
        $('#dtApproveBtn, #dtRejectBtn').css('display', decided ? 'none' : 'inline-flex');
        $('#slaDetailModal').removeClass('hidden').addClass('flex');
    });

    function closeApprDetail() {
        $('#slaDetailModal').addClass('hidden').removeClass('flex');
    }
    $('#dtCloseBtn').on('click', closeApprDetail);
    $('#slaDetailModal').on('click', function(e) {
        if (e.target === this) closeApprDetail(); // backdrop only, not the card
    });

    $('#dtToggleAll').on('change', function() {
        $('.dt-line').prop('checked', $(this).is(':checked'));
    });
    $('#dtLines').on('change', '.dt-line', function() {
        var total = $('.dt-line').length, on = $('.dt-line:checked').length;
        $('#dtToggleAll').prop('checked', total > 0 && on === total)
                         .prop('indeterminate', on > 0 && on < total);
    });

    // ----- Submitting a decision ---------------------------------------------
    // Shared by both paths below: POSTs to ajax_sla_request_decide.php, shows
    // the same success/warning Swal either way, then reloads (pending markers,
    // the SLA table, and the inbox badge all come from the server on reload).
    // $disable/$busyBtn/$errorEl differ per path so each can manage its own
    // buttons/error text without knowing about the other.
    function apprPostDecision(ids, action, notes, opts) {
        opts.$disable.prop('disabled', true);
        var origLabel = opts.$busyBtn.text();
        opts.$busyBtn.text('Saving...');
        $.ajax({
            url: 'ajax_sla_request_decide.php',
            type: 'POST',
            dataType: 'json',
            data: {
                request_id:  ids[0],
                request_ids: JSON.stringify(ids),
                action: action,
                decision_notes: notes
            },
            success: function(resp) {
                if (resp.status === 'success') {
                    var partial = resp.skipped && resp.skipped.length;
                    Swal.fire({
                        icon:  partial ? 'warning' : 'success',
                        title: partial ? 'Partly processed' : 'Success',
                        text:  resp.message + (partial
                                ? ' Not processed: ' + resp.skipped.map(function(s) { return 'line ' + s.line + ' (' + s.message + ')'; }).join('; ')
                                : ''),
                        timer: partial ? undefined : 1800,
                        showConfirmButton: !!partial
                    }).then(function() { location.reload(); });
                } else {
                    opts.$errorEl.removeClass('hidden').text(resp.message || 'Failed to process the request.');
                    opts.$disable.prop('disabled', false);
                    opts.$busyBtn.text(origLabel);
                }
            },
            error: function() {
                opts.$errorEl.removeClass('hidden').text('Connection problem. Please try again.');
                opts.$disable.prop('disabled', false);
                opts.$busyBtn.text(origLabel);
            }
        });
    }

    // ----- Detail modal: approve/reject right there, no second modal --------
    $('#dtApproveBtn').on('click', function() { dtSubmitDecision('approve'); });
    $('#dtRejectBtn').on('click',  function() { dtSubmitDecision('reject');  });

    function dtSubmitDecision(action) {
        if (!apprCurrent) return;
        var ids = $('.dt-line:checked').map(function() { return parseInt(this.value, 10); }).get();
        if (ids.length === 0) {
            Swal.fire({ icon: 'info', title: 'Nothing selected', text: 'Tick at least one line item to decide on.' });
            return;
        }
        // Approve and reject both need a note — same rule the server enforces in
        // ajax_sla_request_decide.php; this only saves the round trip.
        var notes = $('#dtNotes').val().trim();
        if (notes.length < 10) {
            $('#dtNotesError').removeClass('hidden').text(action === 'approve'
                ? 'An approval note is required, at least 10 characters.'
                : 'A rejection reason is required, at least 10 characters.');
            return;
        }
        $('#dtNotesError').addClass('hidden').text('');
        apprPostDecision(ids, action, notes, {
            $disable: $('#dtApproveBtn, #dtRejectBtn'),
            $busyBtn: action === 'approve' ? $('#dtApproveBtn') : $('#dtRejectBtn'),
            $errorEl: $('#dtNotesError')
        });
    }

    // ----- Quick action from the inbox row: small confirm modal, own notes --
    // No inline notes field exists on a table row, so this path keeps the
    // separate Decision modal — every line of the submission, same as opening
    // Detail and leaving every checkbox ticked.
    var apprDecideIds = [], apprDecideAction = '';

    function closeApprDecision() {
        $('#slaDecisionModal').addClass('hidden').removeClass('flex');
        $('#sla-decision-error').addClass('hidden').text('');
        $('#slaDecisionNotes').val('');
        $('#slaDecisionSaveBtn').prop('disabled', false).text('Confirm');
    }

    $('#apprTableBody').on('click', '.appr-quick-btn', function() {
        var d = apprDetails[$(this).data('idx')];
        if (!d) return;
        var ids = d.lines.map(function(l) { return l.id; }), n = ids.length;
        openApprDecisionForIds(ids, $(this).data('action'),
            'PR ' + d.ticket + ' — ' + n + ' line item' + (n > 1 ? 's' : ''));
    });

    // ids: request row ids to decide. subtitle: everything before the trailing
    // ". The adjustment is applied..."/". The SLA data will stay..." sentence —
    // built by the caller since a single request names its PR ticket, a bulk
    // selection names how many requests/line items instead.
    function openApprDecisionForIds(ids, action, subtitle) {
        apprDecideIds = ids;
        apprDecideAction = action;
        var isApprove = action === 'approve';

        $('#slaDecisionTitle').text(isApprove ? 'Approve Request' : 'Reject Request');
        $('#slaDecisionSubtitle').text(subtitle +
            (isApprove ? '. The revision is applied immediately once approved.'
                       : '. The SLA data will stay unchanged.'));
        $('#slaDecisionNotesLabel').text(isApprove ? 'Approval Note (required, min. 10 characters)' : 'Rejection Reason (required, min. 10 characters)');
        $('#slaDecisionSaveBtn')
            .removeClass('bg-green-600 hover:bg-green-700 bg-gray-600 hover:bg-gray-700')
            .addClass(isApprove ? 'bg-green-600 hover:bg-green-700' : 'bg-gray-600 hover:bg-gray-700');

        $('#slaDecisionModal').removeClass('hidden').addClass('flex');
    }

    $('#slaDecisionCancelBtn').on('click', closeApprDecision);
    $('#slaDecisionModal').on('click', function(e) {
        if (e.target === this) closeApprDecision(); // backdrop only, not the card
    });

    $('#slaDecisionSaveBtn').on('click', function() {
        var notes = $('#slaDecisionNotes').val().trim();
        if (notes.length < 10) {
            $('#sla-decision-error').removeClass('hidden').text(apprDecideAction === 'approve'
                ? 'An approval note is required, at least 10 characters.'
                : 'A rejection reason is required, at least 10 characters.');
            return;
        }
        $('#sla-decision-error').addClass('hidden').text('');
        apprPostDecision(apprDecideIds, apprDecideAction, notes, {
            $disable: $('#slaDecisionSaveBtn'),
            $busyBtn: $('#slaDecisionSaveBtn'),
            $errorEl: $('#sla-decision-error')
        });
    });

    // ===== SLA Taxonomy Mapping (Category Manager Only) ======================
    if (isCategoryManager) {
        // Toggle Map Taxonomy (AI) button visibility based on selection
        function updateMapBtnVisibility() {
            if ($('.row-checkbox:checked').length > 0) {
                $('#mapTaxonomyAIBtn').removeClass('hidden');
            } else {
                $('#mapTaxonomyAIBtn').addClass('hidden');
            }
        }

        // Select All Checkbox
        $('#selectAllCheckbox').on('click', function() {
            var isChecked = $(this).prop('checked');
            $('.row-checkbox').prop('checked', isChecked);
            updateMapBtnVisibility();
        });

        // Row Checkbox
        $('#slaTable tbody').on('change', '.row-checkbox', function() {
            if (!$(this).prop('checked')) {
                $('#selectAllCheckbox').prop('checked', false);
            }
            updateMapBtnVisibility();
        });

        // Open Taxonomy Modal
        $('#slaTable tbody').on('click', '.pr-taxonomy-clickable', function() {
            var ticketNo = $(this).data('ticket-no');
            var lineItem = $(this).data('line-item');
            
            Swal.fire({
                title: 'Fetching Line Item...',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });

            $.ajax({
                url: 'ajax_sla_get_pr_taxonomy.php',
                type: 'POST',
                data: { ticket_no: ticketNo },
                dataType: 'json',
                success: function(resp) {
                    if (resp.status === 'success') {
                        var items = resp.data.line_items;
                        var targetItem = null;
                        for (var i = 0; i < items.length; i++) {
                            if (items[i].ticket_line_item == lineItem) {
                                targetItem = items[i];
                                break;
                            }
                        }
                        
                        if (!targetItem) {
                            Swal.fire('Error', 'Line item not found.', 'error');
                            return;
                        }

                        $('#taxTicketNo').text(ticketNo);
                        $('#taxTicketNoInput').val(ticketNo);
                        $('#taxTicketLineItemInput').val(lineItem);
                        $('#taxItemDesc').text(targetItem.item_description);
                        $('#tax-modal-error').addClass('hidden').text('');

                        var sel = $('#taxMasterDataId').empty();
                        sel.append('<option value="">-- Select Master Data --</option>');
                        var md = resp.data.master_data_list;
                        for (var i = 0; i < md.length; i++) {
                            var row = md[i];
                            var path = [row.level_0, row.level_1, row.level_2, row.level_3, row.level_4]
                                .filter(Boolean).join(' > ');
                            var opt = $('<option></option>').val(row.id).text(row.level_4).data('path', path);
                            if (targetItem.master_data_id == row.id) {
                                opt.prop('selected', true);
                            }
                            sel.append(opt);
                        }

                        sel.trigger('change');
                        Swal.close();
                        $('#taxonomyModalBg, #taxonomyModal').removeClass('hidden');
                        setTimeout(() => $('#projectAssignOverlay').removeClass('hidden'), 10);
                    } else {
                        Swal.fire('Error', resp.message || 'Failed to fetch taxonomy.', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Connection failed.', 'error');
                }
            });
        });

        // Close Modal
        $('#closeTaxonomyBtn, #cancelTaxonomyBtn, #taxonomyModalBg').on('click', function() {
            $('#projectAssignOverlay').addClass('hidden');
            setTimeout(() => $('#taxonomyModalBg, #taxonomyModal').addClass('hidden'), 200);
        });

        // Master Data dropdown change
        $('#taxMasterDataId').on('change', function() {
            var selectedOpt = $(this).find('option:selected');
            if (selectedOpt.val()) {
                $('#taxPathDisplay').text('Path: ' + selectedOpt.data('path'));
            } else {
                $('#taxPathDisplay').text('Path: -');
            }
        });

        // Save Taxonomy Mapping
        $('#saveTaxonomyBtn').on('click', function() {
            var btn = $(this);
            var mdId = $('#taxMasterDataId').val();
            
            if (!mdId) {
                $('#tax-modal-error').removeClass('hidden').text('Please select a Master Data category.');
                return;
            }

            btn.prop('disabled', true).html('<div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div> Saving...');
            $('#tax-modal-error').addClass('hidden');

            $.ajax({
                url: 'ajax_sla_update_taxonomy.php',
                type: 'POST',
                data: {
                    ticket_no: $('#taxTicketNoInput').val(),
                    ticket_line_item: $('#taxTicketLineItemInput').val(),
                    master_data_id: mdId
                },
                dataType: 'json',
                success: function(resp) {
                    if (resp.status === 'success') {
                        $('#closeTaxonomyBtn').click();
                        Swal.fire({
                            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000,
                            icon: 'success', title: 'Taxonomy mapped successfully'
                        });
                        table.ajax.reload(null, false);
                    } else {
                        $('#tax-modal-error').removeClass('hidden').text(resp.message || 'Mapping failed.');
                        btn.prop('disabled', false).text('Save Mapping');
                    }
                },
                error: function() {
                    $('#tax-modal-error').removeClass('hidden').text('Connection error. Please try again.');
                    btn.prop('disabled', false).text('Save Mapping');
                }
            });
        });

        // Batch AI Mapping
        $('#mapTaxonomyAIBtn').on('click', function() {
            var selected = [];
            $('.row-checkbox:checked').each(function() {
                selected.push({
                    ticket_no: $(this).data('ticket'),
                    ticket_line_item: String($(this).data('line'))
                });
            });

            if (selected.length === 0) return;

            Swal.fire({
                title: 'Map ' + selected.length + ' Line Items?',
                text: "This will use Gemini AI to find the best Master Data match for the selected items.",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#4f46e5',
                cancelButtonColor: '#d1d5db',
                confirmButtonText: 'Yes, Map with AI'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Processing AI Mapping...',
                        html: 'Please wait, Gemini is analyzing the items.',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    $.ajax({
                        url: 'ajax_sla_auto_map_taxonomy_batch.php',
                        type: 'POST',
                        data: { items: JSON.stringify(selected) },
                        dataType: 'json',
                        success: function(resp) {
                            if (resp.status === 'success') {
                                Swal.close();
                                
                                var tbody = $('#aiReviewTableBody').empty();
                                var mdList = resp.data.master_data_list;
                                var picList = resp.data.pic_list || [];
                                var preview = resp.data.preview;
                                
                                if (!preview || preview.length === 0) {
                                    Swal.fire('Info', 'No items could be mapped.', 'info');
                                    return;
                                }

                                var picOptions = '<option value="">-- Select PIC --</option>';
                                picList.forEach(function(pic) {
                                    picOptions += '<option value="'+pic+'">'+pic+'</option>';
                                });

                                preview.forEach(function(item) {
                                    var tr = $('<tr>').addClass('hover:bg-gray-50');
                                    tr.append($('<td>').addClass('px-4 py-3 whitespace-nowrap text-xs font-medium text-gray-900').text(item.ticket_no));
                                    tr.append($('<td>').addClass('px-4 py-3 whitespace-nowrap text-xs text-gray-500').text(item.ticket_line_item));
                                    tr.append($('<td>').addClass('px-4 py-3 text-xs text-gray-700 whitespace-pre-wrap').text(item.item_description));
                                    
                                    var tdSelect = $('<td>').addClass('px-4 py-3 text-sm text-gray-500');
                                    
                                    var selectId = 'ai-select-' + item.ticket_line_item;
                                    var select = $('<select>').attr('id', selectId).addClass('ai-review-select w-full text-xs border-gray-300 rounded-md shadow-sm focus:border-indigo-500 focus:ring-indigo-500')
                                        .data('ticket', item.ticket_no)
                                        .data('line', item.ticket_line_item);
                                    
                                    select.append($('<option>').val('').text('-- Select Master Data --'));
                                    select.append($('<option>').val('NEW').text('-- ➕ Create New Master Data --').addClass('font-bold text-indigo-600'));
                                    
                                    mdList.forEach(function(md) {
                                        var opt = $('<option>').val(md.id).text(md.level_4);
                                        if (item.master_data_id == md.id && item.mode === 'existing') {
                                            opt.prop('selected', true);
                                        }
                                        select.append(opt);
                                    });
                                    
                                    var safeTicket = item.ticket_no.replace(/[^a-zA-Z0-9]/g, '-');
                                    var newFormId = 'ai-new-form-' + safeTicket + '-' + item.ticket_line_item;
                                    var newForm = $('<div>').attr('id', newFormId).addClass('mt-3 p-3 bg-indigo-50 border border-indigo-100 rounded space-y-2');
                                    if (item.mode !== 'new') {
                                        newForm.addClass('hidden');
                                    } else {
                                        select.val('NEW');
                                    }

                                    newForm.append('<div class="text-xs font-bold text-indigo-800 mb-2">Create New Master Data SKU</div>');
                                    newForm.append('<input type="text" class="new-l0 w-full border-gray-300 rounded shadow-sm text-xs p-1.5 border" placeholder="Level 0 (Tower)" value="' + (item.level_0 || '') + '">');
                                    newForm.append('<input type="text" class="new-l1 w-full border-gray-300 rounded shadow-sm text-xs p-1.5 border" placeholder="Level 1 (Category)" value="' + (item.level_1 || '') + '">');
                                    newForm.append('<input type="text" class="new-l2 w-full border-gray-300 rounded shadow-sm text-xs p-1.5 border" placeholder="Level 2 (Sub Category)" value="' + (item.level_2 || '') + '">');
                                    newForm.append('<input type="text" class="new-l3 w-full border-gray-300 rounded shadow-sm text-xs p-1.5 border" placeholder="Level 3 (Commodity)" value="' + (item.level_3 || '') + '">');
                                    newForm.append('<input type="text" class="new-l4 w-full border-indigo-300 rounded shadow-sm text-xs p-1.5 border focus:border-indigo-500" placeholder="Level 4 (Category) (Required)" value="' + (item.level_4 || '') + '">');
                                    newForm.append('<input type="text" class="new-l5 w-full border-gray-300 rounded shadow-sm text-xs p-1.5 border" placeholder="Level 5 (SKU Name)" value="' + (item.level_5 || '') + '">');
                                    newForm.append('<select class="new-pic w-full border-gray-300 rounded shadow-sm text-xs p-1.5 border">' + picOptions + '</select>');

                                    select.on('change', function() {
                                        if ($(this).val() === 'NEW') {
                                            $('#' + newFormId).removeClass('hidden');
                                        } else {
                                            $('#' + newFormId).addClass('hidden');
                                        }
                                    });

                                    tdSelect.append(select);
                                    tdSelect.append(newForm);
                                    tr.append(tdSelect);
                                    tbody.append(tr);
                                });
                                
                                $('#ai-review-modal-error').addClass('hidden').text('');
                                $('#aiReviewModalBg, #aiReviewModal').removeClass('hidden');
                                setTimeout(() => $('#projectAssignOverlay').removeClass('hidden'), 10);
                            } else {
                                Swal.fire('Error', resp.message || 'AI Mapping failed.', 'error');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error(xhr.responseText);
                            Swal.fire('Error', 'Connection failed or timed out.', 'error');
                        }
                    });
                }
            });
        });

        // Close AI Review Modal
        $('#closeAiReviewBtn, #cancelAiReviewBtn').on('click', function() {
            $('#projectAssignOverlay').addClass('hidden');
            setTimeout(() => $('#aiReviewModalBg, #aiReviewModal').addClass('hidden'), 200);
        });

        // Save AI Batch Mapping
        $('#saveAiReviewBtn').on('click', function() {
            var btn = $(this);
            var mappings = [];
            var validationError = '';
            
            // Clear previous error highlights
            $('.new-l4').removeClass('border-red-500 ring-2 ring-red-200 focus:border-red-500').addClass('border-indigo-300 focus:border-indigo-500');

            $('.ai-review-select').each(function() {
                var mdId = $(this).val();
                var line = $(this).data('line');
                
                if (mdId === 'NEW') {
                    var container = $(this).closest('td').find('div[id^="ai-new-form-"]');
                    var l4Input = container.find('.new-l4');
                    var l4 = l4Input.val().trim();
                    var l5 = container.find('.new-l5').val().trim();
                    if (!l4) {
                        validationError = 'Level 4 (Category) is required for all new Master Data entries.';
                        l4Input.removeClass('border-indigo-300 focus:border-indigo-500').addClass('border-red-500 ring-2 ring-red-200 focus:border-red-500');
                        l4Input[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                        return false; // break $.each loop
                    }
                    mappings.push({
                        ticket_no: $(this).data('ticket'),
                        ticket_line_item: String(line),
                        mode: 'new',
                        level_0: container.find('.new-l0').val().trim(),
                        level_1: container.find('.new-l1').val().trim(),
                        level_2: container.find('.new-l2').val().trim(),
                        level_3: container.find('.new-l3').val().trim(),
                        level_4: l4,
                        level_5: l5,
                        pic_names: container.find('.new-pic').val()
                    });
                } else if (mdId) {
                    mappings.push({
                        ticket_no: $(this).data('ticket'),
                        ticket_line_item: String(line),
                        mode: 'existing',
                        master_data_id: mdId
                    });
                }
            });

            if (validationError) {
                $('#ai-review-modal-error').removeClass('hidden').text(validationError);
                return;
            }

            if (mappings.length === 0) {
                $('#ai-review-modal-error').removeClass('hidden').text('No mappings selected to save.');
                return;
            }

            btn.prop('disabled', true).html('<div class="w-4 h-4 border-2 border-white border-t-transparent rounded-full animate-spin"></div> Saving...');
            $('#ai-review-modal-error').addClass('hidden');

            $.ajax({
                url: 'ajax_sla_save_taxonomy_batch.php',
                type: 'POST',
                data: { mappings: JSON.stringify(mappings) },
                dataType: 'json',
                success: function(resp) {
                    if (resp.status === 'success') {
                        $('#closeAiReviewBtn').click();
                        Swal.fire({
                            toast: true, position: 'top-end', showConfirmButton: false, timer: 3000,
                            icon: 'success', title: 'Mapped ' + resp.mapped + ' items successfully'
                        });
                        $('#selectAllCheckbox').prop('checked', false);
                        $('#mapTaxonomyAIBtn').addClass('hidden');
                        table.ajax.reload(null, false);
                    } else {
                        $('#ai-review-modal-error').removeClass('hidden').text(resp.message || 'Mapping failed.');
                    }
                },
                error: function() {
                    $('#ai-review-modal-error').removeClass('hidden').text('Connection error. Please try again.');
                },
                complete: function() {
                    btn.prop('disabled', false).text('Confirm & Save All');
                }
            });
        });
    }

    // ===== SLA Chatbot =======================================================
    // The compact aggregate summary is now built server-side in ajax_sla_chat.php from
    // the current gf filter state, instead of client-side from a full local row array.
    function appendChatMsg(text, who, isHtml) {
        var content = isHtml ? text : $('<div>').text(text).html();
        var cls = who === 'user'
            ? 'bg-indigo-600 text-white rounded-lg px-3 py-2 text-xs max-w-[85%] ml-auto'
            : 'bg-gray-100 text-gray-700 rounded-lg px-3 py-2 text-xs max-w-[85%]';
        var $msg = $('<div class="' + cls + '"></div>').html(content);
        $('#slaChatMessages').append($msg).scrollTop($('#slaChatMessages')[0].scrollHeight);
        return $msg;
    }

    $('#slaChatToggle').on('click', function() {
        $('#slaChatPanel').toggleClass('hidden');
        if (!$('#slaChatPanel').hasClass('hidden')) $('#slaChatInput').focus();
    });
    $('#slaChatClose').on('click', function() { $('#slaChatPanel').addClass('hidden'); });

    var chatBusy = false;
    $('#slaChatForm').on('submit', function(e) {
        e.preventDefault();
        var q = ($('#slaChatInput').val() || '').trim();
        if (!q || chatBusy) return;
        chatBusy = true;
        $('#slaChatInput').val('');
        $('#slaChatSend').prop('disabled', true);
        appendChatMsg(q, 'user');
        var $typing = appendChatMsg('Mengetik…', 'bot').addClass('italic text-gray-400');

        $.post('ajax_sla_chat.php', {
            question: q,
            gf: JSON.stringify(gf)
        }, null, 'json').done(function(res) {
            $typing.remove();
            
            if (res.status === 'success') {
                var ansHtml = (res.answer || '')
                    .replace(/</g, '&lt;').replace(/>/g, '&gt;') // escape HTML
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>') // bold
                    .replace(/\*(.*?)\*/g, '<em>$1</em>') // italic
                    .replace(/\n\s*[-*]\s+(.*)/g, '<br>&bull; $1') // bullets
                    .replace(/\n/g, '<br>'); // newlines
                
                var $msg = appendChatMsg(ansHtml, 'bot', true);
                
                if (res.suggested_questions && res.suggested_questions.length > 0) {
                    var $sugContainer = $('<div class="flex flex-wrap gap-1 mt-3 pt-2 border-t border-gray-200"></div>');
                    res.suggested_questions.forEach(function(sq) {
                        var escSq = $('<div>').text(sq).html();
                        var $pill = $('<button type="button" class="text-left text-[10px] bg-white hover:bg-indigo-50 text-indigo-700 border border-indigo-200 rounded-md px-2 py-1 transition-colors cursor-pointer leading-tight">' + escSq + '</button>');
                        $pill.on('click', function() {
                            $('#slaChatInput').val(sq);
                            $('#slaChatForm').submit();
                        });
                        $sugContainer.append($pill);
                    });
                    $msg.append($sugContainer);
                    $('#slaChatMessages').scrollTop($('#slaChatMessages')[0].scrollHeight);
                }
            } else {
                appendChatMsg(res.message || 'Terjadi kesalahan, coba lagi.', 'bot');
            }
            
            if (res.status === 'success' && res.set_filters) {
                let f = res.set_filters;
                let changed = false;
                
                if (f.team !== undefined) {
                    if (Array.isArray(f.team)) {
                        gf.team = f.team;
                    } else {
                        gf.team = (f.team === 'ALL' || f.team === 'all' || !f.team) ? [] : [f.team];
                    }
                    changed = true;
                    $('.team-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
                    if (gf.team.length > 0) {
                        $('.team-filter-btn').addClass('opacity-50').removeClass('opacity-100');
                        gf.team.forEach(function(t) {
                            $('.team-filter-btn[data-team="' + t + '"]').addClass('ring-2 ring-offset-2 ring-indigo-400 opacity-100').removeClass('opacity-50');
                        });
                    }
                }
                
                if (f.status !== undefined) {
                    gf.status = (f.status === 'ALL' || f.status === 'all') ? '' : f.status;
                    changed = true;
                    $('.legend-filter-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400 opacity-50').addClass('opacity-100');
                    if (gf.status) {
                        $('.legend-filter-btn[data-status="' + gf.status + '"]').addClass('ring-2 ring-offset-2 ring-indigo-400');
                        $('.legend-filter-btn').not('[data-status="' + gf.status + '"]').addClass('opacity-50').removeClass('opacity-100');
                    }
                }
                
                if (f.dateQuick !== undefined && f.dateQuick !== '') {
                    $('#dateQuickFilter').val(f.dateQuick);
                    applyQuickDate(f.dateQuick);
                    $('#customDateInputs').toggleClass('hidden', f.dateQuick !== 'custom').toggleClass('flex', f.dateQuick === 'custom');
                    changed = true;
                } else if (f.dateFrom !== undefined && f.dateTo !== undefined) {
                    $('#dateQuickFilter').val('custom');
                    $('#customDateInputs').removeClass('hidden').addClass('flex');
                    $('#dateFrom').val(f.dateFrom);
                    $('#dateTo').val(f.dateTo);
                    gf.from = f.dateFrom;
                    gf.to = f.dateTo;
                    changed = true;
                }
                
                if (f.taxonomy !== undefined && Array.isArray(f.taxonomy)) {
                    gf.tax = ['', '', '', '', ''];
                    for (let i = 0; i < f.taxonomy.length && i < 5; i++) {
                        gf.tax[i] = (f.taxonomy[i] === 'ALL' || f.taxonomy[i] === 'all') ? '' : f.taxonomy[i];
                    }
                    // Update visual selects
                    for (let i = 0; i < 5; i++) {
                        $('.tax-filter[data-level="' + i + '"]').val(gf.tax[i] || '');
                    }
                    changed = true;
                }
                
                if (changed) {
                    refreshAll();
                }
            }
        }).fail(function() {
            $typing.remove();
            appendChatMsg('Gagal terhubung ke server, coba lagi.', 'bot');
        }).always(function() {
            chatBusy = false;
            $('#slaChatSend').prop('disabled', false);
            $('#slaChatInput').focus();
        });
    });

    // First render (no filters active = same numbers as the old server-side build).
    // The chart loader is faded out inside updateCharts()'s AJAX callback (once the
    // server-built chart data has actually arrived), not synchronously here.
    updateCharts();
});
</script>

<?php require_once '../includes/footer.php'; ?>
