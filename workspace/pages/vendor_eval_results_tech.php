<?php
// workspace/pages/vendor_eval_results_tech.php
//
// Vendor Evaluation — Results (external Technical Department POV).
// Read-only. PGroup-scoped to the logged-in account's team
// (technical_team_pgroups), Form 2 (Project Retail — New Store) only.
//
// Access : Technical Department role only (Manager/Category Manager keep their
//          richer page, vendor_eval_v2_results.php). Everyone else redirected.
// Data   : vendor_eval_submissions + vendor_eval_answers (read-only), prs
//          (PGroup lookup / eligibility), pgroup_business_unit_mappings
//          (business unit labels). No DB writes. No WA/email. No API.
// Scope  : includes/vendor_eval_v2_tech_results.php (pure read-only helpers).

require_once '../includes/auth.php';
require_login();

$__role = $_SESSION['role'] ?? '';
if ($__role !== 'Technical Department') {
    header('Location: dashboard_pic.php');
    exit;
}

require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_tech_results.php';

date_default_timezone_set('Asia/Jakarta');

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function veTechGradeClass(?string $grade): string
{
    return match ($grade) {
        'A'     => 'bg-green-100 text-green-800 border-green-200',
        'B'     => 'bg-blue-100 text-blue-800 border-blue-200',
        'C'     => 'bg-yellow-100 text-yellow-800 border-yellow-200',
        'D'     => 'bg-red-100 text-red-800 border-red-200',
        default => 'bg-gray-100 text-gray-600 border-gray-200',
    };
}

// Grade fill colors — reuse the app's established grade convention
// (A green / B blue / C yellow / D red), which is an ordinal/status scale.
function veTechGradeFill(string $grade): string
{
    return match ($grade) {
        'A'     => '#16a34a',
        'B'     => '#2563eb',
        'C'     => '#eab308',
        default => '#dc2626',
    };
}

function veTechCategory(?string $l2, ?string $l3): string
{
    $l2 = trim((string)$l2);
    $l3 = trim((string)$l3);
    if ($l2 === '' && $l3 === '') return '';
    if ($l3 === '') return $l2;
    if ($l2 === '') return $l3;
    return $l2 . ' › ' . $l3;
}

function veTechDate(?string $dt): string
{
    if (empty($dt)) return '';
    return (new DateTime($dt, new DateTimeZone('Asia/Jakarta')))->format('d M Y');
}

// Centered em-dash placeholder for empty cells.
function veTechCell(string $value, string $align = 'left'): string
{
    if (trim($value) === '') {
        return '<span class="block text-center text-gray-300">—</span>';
    }
    $cls = $align === 'center' ? 'block text-center' : '';
    return '<span class="' . $cls . '">' . h($value) . '</span>';
}

// Site allocation cell for the Results/Outstanding tables: shows the PO's
// site(s) (one PO can span several), truncating to "First site  +N".
function veTechSiteCell(array $sites): string
{
    $sites = array_values(array_unique(array_filter(array_map('strval', $sites), static fn($s) => trim($s) !== '')));
    if (empty($sites)) {
        return '<span class="block text-center text-gray-300">—</span>';
    }
    $first = $sites[0];
    $extra = count($sites) - 1;
    $label = h($first) . ($extra > 0 ? ' <span class="text-gray-400">+' . $extra . '</span>' : '');
    return '<span title="' . h(implode(' · ', $sites)) . '">' . $label . '</span>';
}

// Clickable vendor name that opens the vendor-profile modal. Falls back to the
// plain em-dash cell for blank / placeholder ("(Unnamed vendor)") names, which
// have no matching submission row to profile.
function veTechVendorCell(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '' || $name === '(Unnamed vendor)') {
        return veTechCell($name);
    }
    return '<button type="button" class="ve-vendor-link" data-vendor="' . h($name) . '" title="View ' . h($name) . '\'s quality profile">' . h($name) . '</button>';
}

$team_name     = trim((string)($_SESSION['team_name'] ?? ''));
$scope_pgroups = ve2_tech_resolve_pgroups($pdo, $team_name);
$has_scope     = !empty($scope_pgroups);

// ── Global filters (PGroup + Year/Month) ─────────────────────────────────────
// Applied via query-string + full reload (not client-side) so every KPI, chart,
// and table on the page — not just the two DataTables — reflects the same
// scope consistently. $active_pgroups replaces $scope_pgroups in every
// downstream aggregation call below; $scope_pgroups itself is kept intact for
// rendering the "Your P.Groups" badge row (always shows the account's full scope).
$pg_filter = trim((string)($_GET['pg'] ?? ''));
if ($pg_filter !== '' && !in_array($pg_filter, $scope_pgroups, true)) {
    $pg_filter = ''; // ignore an out-of-scope value rather than leaking data
}
$active_pgroups = $pg_filter !== '' ? [$pg_filter] : $scope_pgroups;

$y_raw    = trim((string)($_GET['y'] ?? ''));
$y_filter = (ctype_digit($y_raw) && strlen($y_raw) === 4) ? (int)$y_raw : null;
$m_raw    = trim((string)($_GET['m'] ?? ''));
$m_filter = (ctype_digit($m_raw) && (int)$m_raw >= 1 && (int)$m_raw <= 12) ? (int)$m_raw : null;
$has_time_filter = ($y_filter !== null || $m_filter !== null);

$available_years = $has_scope ? ve2_tech_available_years($pdo, $scope_pgroups) : [];

// Builds a link back to this page with one or more filter params overridden,
// preserving the others currently in the querystring. Used by the clickable
// P.Group badges and the "Clear" links.
function veTechFilterUrl(array $overrides): string
{
    $params = array_filter(
        array_merge(['y' => $_GET['y'] ?? '', 'm' => $_GET['m'] ?? '', 'pg' => $_GET['pg'] ?? ''], $overrides),
        static fn($v) => $v !== '' && $v !== null
    );
    $path = strtok($_SERVER['REQUEST_URI'], '?');
    return $params ? ($path . '?' . http_build_query($params)) : $path;
}

$all_rows  = $has_scope ? ve2_tech_form2_results($pdo, $active_pgroups, $y_filter, $m_filter) : [];
$results   = array_values(array_filter($all_rows, static fn($r) => $r['both_submitted']));
$partial   = array_values(array_filter($all_rows, static fn($r) => !$r['both_submitted']));
$unstarted = $has_scope ? ve2_tech_unstarted($pdo, $active_pgroups, array_column($all_rows, 'po_number')) : [];

// Unified Outstanding list: partially evaluated (one team missing) + never started.
$outstanding = [];
foreach ($partial as $r) {
    $missing = [];
    if ($r['ops'] === null)     $missing[] = 'Operations';
    if ($r['project'] === null) $missing[] = 'Project';
    $outstanding[] = [
        'po_number'          => $r['po_number'],
        'vendor_name'        => $r['vendor_name'],
        'business_unit'      => $r['business_unit'] ?? null,
        'category'           => veTechCategory($r['taxonomy_level_2'], $r['taxonomy_level_3']),
        'store_opening_date' => null,
        'state'              => 'Partially Evaluated',
        'missing'            => implode(' + ', $missing),
    ];
}
foreach ($unstarted as $r) {
    $outstanding[] = [
        'po_number'          => $r['po_number'],
        'vendor_name'        => $r['vendor_name'],
        'business_unit'      => $r['business_unit'] ?? null,
        'category'           => veTechCategory($r['taxonomy_level_2'], $r['taxonomy_level_3']),
        'store_opening_date' => $r['store_opening_date'],
        'state'              => 'Not Started',
        'missing'            => 'Operations + Project',
    ];
}

// Grade distribution across completed results (for the visualization).
$grade_dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach ($results as $r) {
    if (isset($grade_dist[$r['final_grade']])) {
        $grade_dist[$r['final_grade']]++;
    }
}
$completed_n   = count($results);
$outstanding_n = count($outstanding);
$total_n       = $completed_n + $outstanding_n;
$pct_done      = $total_n > 0 ? round($completed_n / $total_n * 100) : 0;
$grade_max     = max(1, max($grade_dist));

// Distinct Business Units present (for the filter dropdown).
$bu_set = [];
foreach ($results as $r)     { if (!empty($r['business_unit'])) $bu_set[$r['business_unit']] = true; }
foreach ($outstanding as $r) { if (!empty($r['business_unit'])) $bu_set[$r['business_unit']] = true; }
ksort($bu_set);
$bu_options = array_keys($bu_set);

// ── Visualization aggregates ─────────────────────────────────────────────────
// Average final score across completed evaluations (gauge).
$final_scores = [];
foreach ($results as $r) { if ($r['final_score'] !== null) $final_scores[] = (float)$r['final_score']; }
$avg_final = $final_scores ? round(array_sum($final_scores) / count($final_scores), 2) : null;
$avg_grade = $avg_final !== null ? ve2_score_grade($avg_final) : null;

// Completed vs Outstanding tally per Business Unit (breakdown bars).
$bu_stats = [];
foreach ($results as $r) {
    $bu = trim((string)($r['business_unit'] ?? '')) ?: 'Unmapped';
    $bu_stats[$bu]['completed']   = ($bu_stats[$bu]['completed']   ?? 0) + 1;
    $bu_stats[$bu]['outstanding'] = ($bu_stats[$bu]['outstanding'] ?? 0);
}
foreach ($outstanding as $o) {
    $bu = trim((string)($o['business_unit'] ?? '')) ?: 'Unmapped';
    $bu_stats[$bu]['completed']   = ($bu_stats[$bu]['completed']   ?? 0);
    $bu_stats[$bu]['outstanding'] = ($bu_stats[$bu]['outstanding'] ?? 0) + 1;
}
uasort($bu_stats, static fn($a, $b) => ($b['completed'] + $b['outstanding']) <=> ($a['completed'] + $a['outstanding']));
$bu_max_total = 1;
foreach ($bu_stats as $s) { $bu_max_total = max($bu_max_total, $s['completed'] + $s['outstanding']); }

// Vendor evaluations submitted by each team (OPS vs PRJ), counted across all
// in-scope POs — one PO contributes to a team's tally once that team submitted.
$ops_filled = 0;
$prj_filled = 0;
foreach ($all_rows as $r) {
    if ($r['ops'] !== null)     $ops_filled++;
    if ($r['project'] !== null) $prj_filled++;
}
$team_max = max(1, $ops_filled, $prj_filled);

// ── Summary dashboard aggregates (completed evaluations only) ─────────────────
// Per-PO distinct sites (site = prs.site_allocation) for all completed POs.
$completed_pos = array_column($results, 'po_number');
$po_sites      = $has_scope ? ve2_tech_po_sites($pdo, $completed_pos) : [];

// KPI: total unique sites across completed POs (a site may span multiple POs).
$site_set = [];
foreach ($po_sites as $sites) {
    foreach ($sites as $s) { $site_set[$s] = true; }
}
$total_sites = count($site_set);

// KPI: highest / lowest single evaluation by final score.
$hi = null;
$lo = null;
foreach ($results as $r) {
    if ($r['final_score'] === null) continue;
    if ($hi === null || $r['final_score'] > $hi['final_score']) $hi = $r;
    if ($lo === null || $r['final_score'] < $lo['final_score']) $lo = $r;
}

// Bar chart: average score per team (OPS vs PRJ) across completed POs.
$ops_score_list = [];
$prj_score_list = [];
foreach ($results as $r) {
    if (($r['ops']['total_score'] ?? null) !== null)     $ops_score_list[] = (float)$r['ops']['total_score'];
    if (($r['project']['total_score'] ?? null) !== null) $prj_score_list[] = (float)$r['project']['total_score'];
}
$ops_avg = $ops_score_list ? round(array_sum($ops_score_list) / count($ops_score_list), 2) : null;
$prj_avg = $prj_score_list ? round(array_sum($prj_score_list) / count($prj_score_list), 2) : null;

// Top vendors by average final score (across their completed evaluations).
$vendor_agg = [];
foreach ($results as $r) {
    $v = trim((string)($r['vendor_name'] ?? '')) ?: '(Unnamed vendor)';
    if ($r['final_score'] === null) continue;
    $vendor_agg[$v]['scores'][] = (float)$r['final_score'];
    $vendor_agg[$v]['pos'][]    = $r['po_number'];
}
$top_vendors = [];
foreach ($vendor_agg as $v => $d) {
    $avg = round(array_sum($d['scores']) / count($d['scores']), 2);
    $vsites = [];
    foreach ($d['pos'] as $po) {
        foreach ($po_sites[(string)$po] ?? [] as $s) { $vsites[$s] = true; }
    }
    $top_vendors[] = [
        'vendor_name' => $v,
        'sites'       => count($vsites),
        'eval_count'  => count($d['scores']),
        'avg_score'   => $avg,
        'grade'       => ve2_score_grade($avg),
    ];
}
usort($top_vendors, static fn($a, $b) => $b['avg_score'] <=> $a['avg_score']);
$TOP_VENDORS_LIMIT = 5;
$top_vendors = array_slice($top_vendors, 0, $TOP_VENDORS_LIMIT);

// ── Site Allocations section ─────────────────────────────────────────────────
// Every distinct site in scope (a site = prs.site_allocation), with its PO
// rollup. Clicking a site opens a modal listing that site's POs + grades.
$scope_sites = $has_scope ? ve2_tech_scope_sites($pdo, $active_pgroups, $y_filter, $m_filter) : [];
$scope_sites_evaluated = 0;
foreach ($scope_sites as $s) { if ($s['evaluated_count'] > 0) $scope_sites_evaluated++; }

// Per-PO site label(s) for the Results/Outstanding tables (Site replaces the
// former Category column). Superset of $po_sites so Outstanding rows resolve too.
$display_pos = array_values(array_unique(array_merge(
    array_column($results, 'po_number'),
    array_column($outstanding, 'po_number')
)));
$display_po_sites = $has_scope ? ve2_tech_po_sites($pdo, $display_pos) : [];

// AJAX partial-render mode: a P.Group/Year/Month click fetches just the
// #veAppRoot fragment below (no header.php chrome, no CSS/CDN <script>
// tags — those are already loaded from the initial full-page render) so
// the filter feels instant instead of a full navigation. Detected via the
// same X-Requested-With header the AJAX endpoints in this app already use.
$__is_ajax = (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest');

if (!$__is_ajax) {
    require_once '../includes/header.php';
    ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<style>
#veResultsTable thead th, #veOutstandingTable thead th {
    background-color:#f9fafb; color:#6b7280; font-size:11px; letter-spacing:.04em;
    text-transform:uppercase; font-weight:700; border-bottom:1px solid #e5e7eb;
}
#veResultsTable td, #veOutstandingTable td { font-size:13px; }
.ve-tab-btn.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }
.ve-row-clickable { cursor:pointer; }
/* Clickable vendor name -> vendor-profile modal. Inherits the cell's truncation. */
.ve-vendor-link { color:#4338ca; font:inherit; text-align:left; cursor:pointer; background:none; border:0; padding:0; max-width:100%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.ve-vendor-link:hover { text-decoration:underline; }
.ve-toggle-btn.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }
.ve-site-filter.active { background:#4f46e5; color:#fff; border-color:#4f46e5; }
.ve-site-card { cursor:pointer; }
.ve-filter { padding:7px 10px; font-size:13px; border:1px solid #d1d5db; border-radius:8px; background:#fff; color:#374151; }
.ve-filter:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 1px #6366f1; }
/* Kill DataTables default length/search chrome — we supply our own filter row. */
#veResultsTable_wrapper .dataTables_length, #veOutstandingTable_wrapper .dataTables_length,
#veResultsTable_wrapper .dataTables_filter, #veOutstandingTable_wrapper .dataTables_filter { display:none; }
/* Interactive dashboard: clickable viz + loader + table fade */
#veResultsTable, #veOutstandingTable { transition: opacity .2s ease; }
.ve-grade-row { cursor:pointer; border-radius:6px; margin:0 -6px; padding:2px 6px; transition:background .12s ease; }
.ve-grade-row:hover { background:#f9fafb; }
.ve-grade-row.active { background:#eef2ff; box-shadow: inset 0 0 0 1px #c7d2fe; }
/* Base is display:none so Tailwind's `.hidden` can't be overridden by source
   order; `:not(.hidden)` (higher specificity) turns it on only when shown. */
#veAppRoot { position:relative; min-height:120px; }
.ve-app-loader { position:absolute; inset:0; background:rgba(255,255,255,.75); display:flex; align-items:center; justify-content:center; z-index:20; }
.ve-loader { position:absolute; inset:0; background:rgba(255,255,255,.65); align-items:center; justify-content:center; z-index:5; border-radius:.75rem; display:none; }
.ve-loader:not(.hidden) { display:flex; }
.ve-spin { width:28px; height:28px; border:3px solid #e5e7eb; border-top-color:#4f46e5; border-radius:9999px; animation:veSpin .7s linear infinite; }
@keyframes veSpin { to { transform: rotate(360deg); } }
</style>
<?php
}
if (!$__is_ajax) {
?>
<div id="veAppRoot">
<?php } ?>
<div class="mb-6 flex flex-col items-center text-center gap-2">
    <h2 class="text-2xl font-bold text-gray-800">Vendor Evaluation Results</h2>
    <p class="text-sm text-gray-500">Welcome, <?= h($_SESSION['name'] ?? '') ?>.</p>
    <?php if ($has_scope): ?>
    <div class="w-full max-w-5xl flex flex-wrap items-center justify-between gap-3 mt-2">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Your P.Groups</span>
            <?php foreach ($scope_pgroups as $pg):
                $pg_active = ($pg_filter === $pg);
                $pg_cls = $pg_active
                    ? 'bg-indigo-600 text-white border-indigo-600'
                    : 'bg-indigo-50 text-indigo-700 border-indigo-200 hover:bg-indigo-100';
            ?>
                <a href="<?= h(veTechFilterUrl(['pg' => $pg_active ? '' : $pg])) ?>"
                   class="ve-filter-link <?= $pg_cls ?> border px-2.5 py-1 rounded-lg text-xs font-mono font-bold transition"
                   title="<?= $pg_active ? 'Click to clear this P.Group filter' : ('Click to scope the whole dashboard to ' . h($pg)) ?>"><?= h($pg) ?></a>
            <?php endforeach; ?>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <form method="get" id="veTimeFilterFormTop" class="flex items-center gap-2">
                <?php if ($pg_filter !== ''): ?><input type="hidden" name="pg" value="<?= h($pg_filter) ?>"><?php endif; ?>
                <select name="y" class="ve-filter text-xs" onchange="veApplyTimeFilter()">
                    <option value="">All years</option>
                    <?php foreach ($available_years as $yr): ?>
                    <option value="<?= (int)$yr ?>" <?= $y_filter === $yr ? 'selected' : '' ?>><?= (int)$yr ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="m" class="ve-filter text-xs" onchange="veApplyTimeFilter()">
                    <option value="">All months</option>
                    <?php for ($mm = 1; $mm <= 12; $mm++): ?>
                    <option value="<?= sprintf('%02d', $mm) ?>" <?= $m_filter === $mm ? 'selected' : '' ?>><?= h(date('F', mktime(0, 0, 0, $mm, 1))) ?></option>
                    <?php endfor; ?>
                </select>
            </form>
            <?php if ($has_time_filter || $pg_filter !== ''): ?>
            <div class="flex flex-wrap items-center gap-2 bg-amber-50 border border-amber-200 text-amber-800 text-xs font-semibold rounded-lg px-3 py-1.5">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 01.8 1.6l-6.3 8.4v5.4a1 1 0 01-1.45.9l-4-2A1 1 0 018 16.4v-3.4L1.7 4.6A1 1 0 013.5 4z"></path></svg>
                <span>Filtered:</span>
                <?php if ($y_filter !== null): ?><span class="bg-amber-100 px-2 py-0.5 rounded">Year <?= (int)$y_filter ?></span><?php endif; ?>
                <?php if ($m_filter !== null): ?><span class="bg-amber-100 px-2 py-0.5 rounded"><?= h(date('F', mktime(0, 0, 0, $m_filter, 1))) ?></span><?php endif; ?>
                <?php if ($pg_filter !== ''): ?><span class="bg-amber-100 px-2 py-0.5 rounded font-mono">P.Group <?= h($pg_filter) ?></span><?php endif; ?>
                <a href="<?= h(veTechFilterUrl(['y' => '', 'm' => '', 'pg' => ''])) ?>" class="ve-filter-link underline hover:no-underline">Clear all filters</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <p class="text-[10px] text-gray-400">Tip: click a P.Group to scope the whole dashboard to it.</p>
    <?php endif; ?>
</div>

<?php if (!$has_scope): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-xl p-8 text-center max-w-2xl mx-auto mt-8">
        <div class="mx-auto mb-4 w-14 h-14 rounded-full bg-amber-100 flex items-center justify-center">
            <svg class="w-7 h-7 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
        </div>
        <h3 class="text-lg font-bold text-gray-800">No Purchasing Groups Mapped</h3>
        <p class="text-sm text-gray-600 mt-2">Your team is not yet mapped to any purchasing group for vendor evaluation. Please contact the Category Manager.</p>
    </div>
<?php
    if (!$__is_ajax) {
        echo '</div><!-- /#veAppRoot -->';
    } else {
        exit; // unreachable in practice: no P.Group/Year/Month controls render without scope
    }
?>
<?php else: ?>

    <!-- SUMMARY DASHBOARD -->
    <?php
        // Neutral card shell reused for all four KPIs.
        $kpi_shell = 'bg-white border border-gray-200 rounded-xl p-5 shadow-sm';
    ?>
    <!-- KPI ROW -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
        <div class="<?= $kpi_shell ?>">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Sites Evaluated</p>
            <div class="flex items-end gap-1">
                <span class="text-4xl font-black text-gray-800"><?= number_format($total_sites) ?></span>
                <span class="text-lg text-gray-300 font-bold mb-0.5">/ <?= number_format(count($scope_sites)) ?></span>
            </div>
            <p class="text-[11px] text-gray-400 mt-1">Sites that have been evaluated, out of <?= number_format(count($scope_sites)) ?> unique site<?= count($scope_sites) === 1 ? '' : 's' ?></p>
        </div>
        <div class="<?= $kpi_shell ?>">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Average Score</p>
            <?php if ($avg_final === null): ?>
                <p class="text-sm text-gray-400 py-3">No completed evaluations yet.</p>
            <?php else: ?>
                <div class="flex items-end gap-2">
                    <span class="text-4xl font-black text-gray-800"><?= number_format($avg_final, 2) ?></span>
                    <span class="text-sm text-gray-400 mb-1">/ 4.00</span>
                    <span class="ml-auto inline-block px-2.5 py-1 rounded-md text-xs font-black border <?= veTechGradeClass($avg_grade) ?>"><?= h((string)$avg_grade) ?></span>
                </div>
                <p class="text-[11px] text-gray-400 mt-1">Both teams, all completed</p>
            <?php endif; ?>
        </div>
        <div class="<?= $kpi_shell ?>">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Highest Score</p>
            <?php if ($hi === null): ?>
                <p class="text-sm text-gray-400 py-3">No completed evaluations yet.</p>
            <?php else: ?>
                <div class="flex items-center gap-2">
                    <span class="text-3xl font-black text-gray-800"><?= number_format((float)$hi['final_score'], 2) ?></span>
                    <span class="inline-block px-2 py-0.5 rounded-md text-xs font-black border <?= veTechGradeClass($hi['final_grade']) ?>"><?= h((string)$hi['final_grade']) ?></span>
                </div>
                <p class="text-xs text-gray-600 mt-1 truncate" title="<?= h((string)($hi['vendor_name'] ?? '')) ?>"><?= veTechCell((string)($hi['vendor_name'] ?? '')) ?></p>
                <p class="text-[11px] text-gray-400 font-mono">PO <?= h($hi['po_number']) ?></p>
            <?php endif; ?>
        </div>
        <div class="<?= $kpi_shell ?>">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Lowest Score</p>
            <?php if ($lo === null): ?>
                <p class="text-sm text-gray-400 py-3">No completed evaluations yet.</p>
            <?php else: ?>
                <div class="flex items-center gap-2">
                    <span class="text-3xl font-black text-gray-800"><?= number_format((float)$lo['final_score'], 2) ?></span>
                    <span class="inline-block px-2 py-0.5 rounded-md text-xs font-black border <?= veTechGradeClass($lo['final_grade']) ?>"><?= h((string)$lo['final_grade']) ?></span>
                </div>
                <p class="text-xs text-gray-600 mt-1 truncate" title="<?= h((string)($lo['vendor_name'] ?? '')) ?>"><?= veTechCell((string)($lo['vendor_name'] ?? '')) ?></p>
                <p class="text-[11px] text-gray-400 font-mono">PO <?= h($lo['po_number']) ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- CHARTS + TOP VENDORS ROW -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-4">
        <!-- Grade distribution pie -->
        <div class="<?= $kpi_shell ?> lg:col-span-1">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Grade Distribution</p>
            <?php if ($completed_n === 0): ?>
                <p class="text-sm text-gray-400 py-4">No completed evaluations yet.</p>
            <?php else: ?>
                <div class="relative mx-auto" style="height:150px; max-width:190px;"><canvas id="gradePie"></canvas></div>
                <div class="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 mt-3 text-[11px] text-gray-600">
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#16a34a"></span>A <?= (int)$grade_dist['A'] ?></span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#2563eb"></span>B <?= (int)$grade_dist['B'] ?></span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#eab308"></span>C <?= (int)$grade_dist['C'] ?></span>
                    <span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#dc2626"></span>D <?= (int)$grade_dist['D'] ?></span>
                </div>
                <p class="text-[10px] text-gray-400 mt-2 text-center">Tip: click a slice to filter the Results table.</p>
            <?php endif; ?>
        </div>

        <!-- Evaluations submitted per team -->
        <div class="<?= $kpi_shell ?> lg:col-span-1">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Evaluations by Team</p>
            <p class="text-[11px] text-gray-400 mb-3">OPS = Operations · PRJ = Project</p>
            <?php if ($ops_filled === 0 && $prj_filled === 0): ?>
                <p class="text-sm text-gray-400 py-4">No evaluations yet.</p>
            <?php else: ?>
                <div style="height:150px;"><canvas id="teamAvgChart"></canvas></div>
            <?php endif; ?>
        </div>

        <!-- Top vendors table -->
        <div class="<?= $kpi_shell ?> lg:col-span-2">
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Top Vendors <span class="text-gray-300 font-normal normal-case">(by average score)</span></p>
            <?php if (empty($top_vendors)): ?>
                <p class="text-sm text-gray-400 py-4">No completed evaluations yet.</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-[13px]">
                    <thead>
                        <tr class="text-[10px] text-gray-400 uppercase tracking-wider border-b border-gray-200">
                            <th class="py-2 pr-2 font-bold text-center w-8">No</th>
                            <th class="py-2 px-2 font-bold">Vendor</th>
                            <th class="py-2 px-2 font-bold text-center">Sites</th>
                            <th class="py-2 px-2 font-bold text-center">Avg Score</th>
                            <th class="py-2 pl-2 font-bold text-center">Grade</th>
                        </tr>
                    </thead>
                    <tbody id="topVendorsBody">
                        <?php foreach ($top_vendors as $i => $v): ?>
                        <tr class="tv-row border-b border-gray-100 last:border-0" data-grade="<?= h($v['grade']) ?>">
                            <td class="py-2 pr-2 text-center text-gray-400"><?= $i + 1 ?></td>
                            <td class="py-2 px-2 text-gray-700 truncate max-w-[220px]" title="<?= h($v['vendor_name']) ?>"><?= veTechVendorCell($v['vendor_name']) ?></td>
                            <td class="py-2 px-2 text-center text-gray-600"><?= (int)$v['sites'] ?></td>
                            <td class="py-2 px-2 text-center font-semibold text-gray-800"><?= number_format((float)$v['avg_score'], 2) ?></td>
                            <td class="py-2 pl-2 text-center">
                                <span class="inline-block px-2 py-0.5 rounded-md text-xs font-black border <?= veTechGradeClass($v['grade']) ?>"><?= h($v['grade']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="topVendorsEmpty" class="hidden"><td colspan="5" class="py-4 text-center text-sm text-gray-400">No top vendors with this grade.</td></tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- SITE ALLOCATIONS -->
    <div class="<?= $kpi_shell ?> mb-4">
        <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
            <div>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Site Allocations</p>
                <p class="text-[11px] text-gray-400 mt-0.5">
                    <b class="text-gray-700"><?= number_format(count($scope_sites)) ?></b> unique site<?= count($scope_sites) === 1 ? '' : 's' ?> available
                </p>
            </div>
            <div class="flex items-center gap-2">
                <input type="text" id="siteSearch" class="ve-filter" placeholder="Search site…">
                <div class="inline-flex rounded-lg border border-gray-200 overflow-hidden text-xs">
                    <button type="button" class="ve-site-filter active px-3 py-1.5 font-bold text-gray-600" data-filter="all">All</button>
                    <button type="button" class="ve-site-filter px-3 py-1.5 font-bold text-gray-600 border-l border-gray-200" data-filter="evaluated">Evaluated</button>
                </div>
            </div>
        </div>
        <?php if (empty($scope_sites)): ?>
            <p class="text-sm text-gray-400 py-4">No site allocations found for your P.Groups.</p>
        <?php else: ?>
        <div id="siteGrid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 max-h-80 overflow-y-auto pr-1">
            <?php foreach ($scope_sites as $s):
                $has_eval = $s['evaluated_count'] > 0;
            ?>
            <button type="button"
                    class="ve-site-card text-left border border-gray-200 rounded-lg p-3 hover:border-indigo-300 hover:bg-indigo-50 transition"
                    data-site="<?= h($s['site_allocation']) ?>"
                    data-evaluated="<?= $has_eval ? '1' : '0' ?>"
                    data-search="<?= h(mb_strtolower($s['site_allocation'])) ?>">
                <div class="flex items-start justify-between gap-2">
                    <span class="text-[13px] font-semibold text-gray-800 leading-tight break-words"><?= h($s['site_allocation']) ?></span>
                    <?php if ($has_eval && $s['best_grade'] !== null): ?>
                        <span class="shrink-0 inline-block px-1.5 py-0.5 rounded text-[10px] font-black border <?= veTechGradeClass($s['best_grade']) ?>"><?= h($s['best_grade']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2 mt-1.5 text-[11px] text-gray-400">
                    <span class="font-mono bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded"><?= h($s['p_group']) ?></span>
                    <span><?= (int)$s['po_count'] ?> PO<?= (int)$s['po_count'] === 1 ? '' : 's' ?></span>
                    <?php if ($has_eval): ?>
                        <span class="text-indigo-500 font-semibold"><?= (int)$s['evaluated_count'] ?> evaluated</span>
                    <?php endif; ?>
                </div>
            </button>
            <?php endforeach; ?>
        </div>
        <p id="siteNoMatch" class="hidden text-sm text-gray-400 py-4 text-center">No sites match your filter.</p>
        <p class="text-[10px] text-gray-400 mt-2">Tip: click a site to see its purchase orders and their grades.</p>
        <?php endif; ?>
    </div>

    <!-- GRADE LEGEND -->
    <div class="bg-white border border-gray-200 rounded-xl px-5 py-3 mb-6 shadow-sm">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
            <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Grade Scale</span>
            <span class="flex items-center gap-2 text-sm text-gray-600"><span class="inline-block w-3 h-3 rounded-sm" style="background:#16a34a"></span><b class="text-gray-800">A</b> &ge; 3.50</span>
            <span class="flex items-center gap-2 text-sm text-gray-600"><span class="inline-block w-3 h-3 rounded-sm" style="background:#2563eb"></span><b class="text-gray-800">B</b> 2.50 &ndash; 3.49</span>
            <span class="flex items-center gap-2 text-sm text-gray-600"><span class="inline-block w-3 h-3 rounded-sm" style="background:#eab308"></span><b class="text-gray-800">C</b> 1.50 &ndash; 2.49</span>
            <span class="flex items-center gap-2 text-sm text-gray-600"><span class="inline-block w-3 h-3 rounded-sm" style="background:#dc2626"></span><b class="text-gray-800">D</b> &lt; 1.50</span>
        </div>
    </div>

    <!-- Tabs -->
    <div class="flex items-center gap-2 mb-4">
        <button type="button" class="ve-tab-btn active px-4 py-2 text-sm font-bold rounded-lg border border-gray-200 bg-white text-gray-700 transition" data-tab="results">
            Results <span class="ml-1 text-xs opacity-80">(<?= $completed_n ?>)</span>
        </button>
        <button type="button" class="ve-tab-btn px-4 py-2 text-sm font-bold rounded-lg border border-gray-200 bg-white text-gray-700 transition" data-tab="outstanding">
            Outstanding <span class="ml-1 text-xs opacity-80">(<?= $outstanding_n ?>)</span>
        </button>
    </div>

    <!-- Filter bar (shared) — Business Unit/Grade/Search filter the visible
         table instantly. Year/Month/P.Group live up top (they rescope the
         whole dashboard via reload, not just this table). -->
    <div class="flex flex-wrap items-end gap-x-6 gap-y-3 mb-4 bg-white border border-gray-200 rounded-xl px-4 py-3 shadow-sm">
        <div class="flex flex-col">
            <label class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1">Business Unit</label>
            <select id="filterBU" class="ve-filter min-w-[220px]">
                <option value="">All Business Units</option>
                <?php foreach ($bu_options as $bu): ?>
                <option value="<?= h($bu) ?>"><?= h($bu) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex flex-col" id="gradeFilterWrap">
            <label class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1">Grade</label>
            <select id="filterGrade" class="ve-filter">
                <option value="">All Grades</option>
                <option value="A">A</option>
                <option value="B">B</option>
                <option value="C">C</option>
                <option value="D">D</option>
            </select>
        </div>
        <div class="flex flex-col ml-auto">
            <label class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-1">Search</label>
            <input type="text" id="tableSearch" class="ve-filter min-w-[220px]" placeholder="Search PO or vendor…">
        </div>
    </div>

    <!-- RESULTS TAB -->
    <div id="tab-results" class="ve-tab-panel">
        <div class="relative bg-white shadow-sm rounded-xl border border-gray-200 p-4 sm:p-5">
            <div class="ve-loader hidden"><div class="ve-spin"></div></div>
            <p class="text-xs text-gray-400 mb-3">Click a row to see the full per-criterion breakdown for both teams. Click the vendor name to review the historical vendor grade.</p>
            <div class="overflow-x-auto">
            <table id="veResultsTable" class="min-w-full text-left">
                <thead>
                    <tr>
                        <th class="px-4 py-3">PO Number</th>
                        <th class="px-4 py-3">Vendor</th>
                        <th class="px-4 py-3">Business Unit</th>
                        <th class="px-4 py-3">Site</th>
                        <th class="px-4 py-3 text-center">Operations</th>
                        <th class="px-4 py-3 text-center">Project</th>
                        <th class="px-4 py-3 text-center">Final Grade</th>
                        <th class="px-4 py-3 text-center">Submit Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r):
                        $ops_score  = $r['ops']['total_score'] ?? null;
                        $proj_score = $r['project']['total_score'] ?? null;
                    ?>
                    <tr class="ve-row-clickable border-b border-gray-100 hover:bg-indigo-50 transition" data-po="<?= h($r['po_number']) ?>">
                        <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?= h($r['po_number']) ?></td>
                        <td class="px-4 py-3 text-gray-700"><?= veTechVendorCell($r['vendor_name'] ?? '') ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= veTechCell((string)($r['business_unit'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= veTechSiteCell($display_po_sites[(string)$r['po_number']] ?? []) ?></td>
                        <td class="px-4 py-3 text-center font-semibold text-gray-800"><?= $ops_score !== null ? h(number_format((float)$ops_score, 2)) : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="px-4 py-3 text-center font-semibold text-gray-800"><?= $proj_score !== null ? h(number_format((float)$proj_score, 2)) : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-block px-2.5 py-1 rounded-md text-xs font-black border <?= veTechGradeClass($r['final_grade']) ?>">
                                <?= h((string)$r['final_grade']) ?><?= $r['final_score'] !== null ? ' · ' . h(number_format((float)$r['final_score'], 2)) : '' ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-500 text-xs" data-order="<?= h((string)($r['last_submitted_at'] ?? '')) ?>"><?= veTechCell(veTechDate($r['last_submitted_at']), 'center') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- OUTSTANDING TAB -->
    <div id="tab-outstanding" class="ve-tab-panel hidden">
        <div class="relative bg-white shadow-sm rounded-xl border border-gray-200 p-4 sm:p-5">
            <div class="ve-loader hidden"><div class="ve-spin"></div></div>
            <p class="text-xs text-gray-400 mb-3">Purchase orders eligible for Form 2 evaluation that are still missing one or both team submissions.</p>
            <div class="overflow-x-auto">
            <table id="veOutstandingTable" class="min-w-full text-left">
                <thead>
                    <tr>
                        <th class="px-4 py-3">PO Number</th>
                        <th class="px-4 py-3">Vendor</th>
                        <th class="px-4 py-3">Business Unit</th>
                        <th class="px-4 py-3">Site</th>
                        <th class="px-4 py-3 text-center">Store Opening</th>
                        <th class="px-4 py-3 text-center">Status</th>
                        <th class="px-4 py-3 text-center">Awaiting</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outstanding as $o): ?>
                    <tr class="border-b border-gray-100 hover:bg-gray-50 transition">
                        <td class="px-4 py-3 font-mono font-bold text-gray-700"><?= h($o['po_number']) ?></td>
                        <td class="px-4 py-3 text-gray-700"><?= veTechCell((string)($o['vendor_name'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= veTechCell((string)($o['business_unit'] ?? '')) ?></td>
                        <td class="px-4 py-3 text-gray-600"><?= veTechSiteCell($display_po_sites[(string)$o['po_number']] ?? []) ?></td>
                        <td class="px-4 py-3 text-center text-gray-500 text-xs"><?= veTechCell(veTechDate($o['store_opening_date']), 'center') ?></td>
                        <td class="px-4 py-3 text-center">
                            <span class="inline-block px-2.5 py-1 rounded-md text-[10px] font-bold border <?= $o['state'] === 'Partially Evaluated' ? 'bg-yellow-100 text-yellow-800 border-yellow-200' : 'bg-red-100 text-red-800 border-red-200' ?>">
                                <?= h($o['state']) ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-600 text-xs"><?= veTechCell((string)$o['missing'], 'center') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <!-- DRILL-DOWN MODAL -->
    <div id="veDetailModal" class="hidden fixed inset-0 z-[9998] flex items-start justify-center bg-slate-900 bg-opacity-50 p-4 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-3xl my-8">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <div>
                    <h3 class="text-lg font-bold text-gray-800">Evaluation Detail</h3>
                    <p class="text-xs text-gray-500 font-mono" id="veDetailPo">—</p>
                </div>
                <button type="button" id="veDetailClose" class="text-gray-400 hover:text-red-500 p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div id="veDetailBody" class="p-6">
                <div class="text-center text-gray-500 py-8">Loading…</div>
            </div>
        </div>
    </div>

    <!-- VENDOR PROFILE MODAL -->
    <div id="veVendorModal" class="hidden fixed inset-0 z-[9998] flex items-start justify-center bg-slate-900 bg-opacity-50 p-4 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl my-8">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 gap-3">
                <div>
                    <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Vendor Quality Profile</p>
                    <h3 class="text-lg font-bold text-gray-800" id="veVendorName">—</h3>
                </div>
                <button type="button" id="veVendorClose" class="text-gray-400 hover:text-red-500 p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="px-6 pt-4">
                <div id="veVendorRangeNote" class="hidden text-[11px] text-indigo-600 font-semibold mb-2">Showing a custom period — the All-time/Year-to-date toggle is paused while a Year/Month is selected.</div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div id="veVendorToggleWrap" class="inline-flex rounded-lg border border-gray-200 overflow-hidden text-sm">
                        <button type="button" class="ve-toggle-btn active px-4 py-1.5 font-bold text-gray-600" data-range="all_time">All-time</button>
                        <button type="button" class="ve-toggle-btn px-4 py-1.5 font-bold text-gray-600 border-l border-gray-200" data-range="ytd">Year-to-date</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <select id="veVendorYear" class="ve-filter text-xs">
                            <option value="">All years</option>
                            <?php foreach ($available_years as $yr): ?>
                            <option value="<?= (int)$yr ?>"><?= (int)$yr ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="veVendorMonth" class="ve-filter text-xs">
                            <option value="">All months</option>
                            <?php for ($mm = 1; $mm <= 12; $mm++): ?>
                            <option value="<?= sprintf('%02d', $mm) ?>"><?= h(date('F', mktime(0, 0, 0, $mm, 1))) ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div id="veVendorBody" class="p-6 pt-4">
                <div class="text-center text-gray-500 py-8">Loading…</div>
            </div>
        </div>
    </div>

    <!-- SITE DETAIL MODAL -->
    <div id="veSiteModal" class="hidden fixed inset-0 z-[9998] flex items-start justify-center bg-slate-900 bg-opacity-50 p-4 overflow-y-auto">
        <div class="bg-white rounded-xl shadow-2xl w-full max-w-2xl my-8">
            <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
                <div>
                    <p class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Site Allocation</p>
                    <h3 class="text-lg font-bold text-gray-800" id="veSiteName">—</h3>
                </div>
                <button type="button" id="veSiteClose" class="text-gray-400 hover:text-red-500 p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div id="veSiteBody" class="p-6">
                <div class="text-center text-gray-500 py-8">Loading…</div>
            </div>
        </div>
    </div>
<?php if (!$__is_ajax): ?>
</div><!-- /#veAppRoot -->
<?php endif; ?>
<?php
if ($__is_ajax) {
    // AJAX partial-render: everything the client needs to swap in is above
    // this line. No CDN <script> tags, no init script — those already ran
    // on the page's initial full load and stay resident; the caller
    // (loadVeDashboard() below) re-runs initVeDashboard() after the swap.
    exit;
}
?>

    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
    // Re-run on initial load AND after every AJAX filter swap (loadVeDashboard
    // below replaces #veAppRoot's HTML, which destroys/recreates every element
    // this function binds to — so a full re-run is both correct and cheap).
    function initVeDashboard() {
        var GRADE_CLASS = {
            'A': 'bg-green-100 text-green-800 border-green-200',
            'B': 'bg-blue-100 text-blue-800 border-blue-200',
            'C': 'bg-yellow-100 text-yellow-800 border-yellow-200',
            'D': 'bg-red-100 text-red-800 border-red-200'
        };
        function esc(v) { return $('<div>').text(v == null ? '' : v).html(); }
        function rx(v) { return String(v).replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

        // Custom dom: no length menu, no default search — filter row is our own.
        var COMMON = { lengthChange: false, pageLength: 25, dom: 'rt<"flex flex-wrap items-center justify-between gap-2 pt-3"ip>' };
        var resultsTable     = $('#veResultsTable').DataTable($.extend({}, COMMON, { order: [[7, 'desc']] }));
        var outstandingTable = $('#veOutstandingTable').DataTable($.extend({}, COMMON, { order: [[0, 'asc']] }));

        // ── Interactive dashboard controls ──────────────────────────────
        function visiblePanel() { return $('.ve-tab-panel:not(.hidden)').first(); }

        // Briefly show a loader over the visible panel, run fn (client-side
        // filter / tab switch), then fade the resulting table back in.
        function withLoader(fn) {
            var $panel = visiblePanel();
            var $ov = $panel.find('.ve-loader');
            $panel.find('table').css('opacity', '.35');
            $ov.removeClass('hidden');
            setTimeout(function() {
                fn();
                $panel.find('table').css('opacity', '1'); // reset the panel we dimmed
                $ov.addClass('hidden');
                var $now = visiblePanel();
                $now.find('.ve-loader').addClass('hidden');
                var $tbl = $now.find('table').css('opacity', '0');
                requestAnimationFrame(function() { $tbl.css('opacity', '1'); });
            }, 320);
        }

        function activateTab(tab) {
            $('.ve-tab-btn').removeClass('active').filter('[data-tab="' + tab + '"]').addClass('active');
            $('.ve-tab-panel').addClass('hidden');
            $('#tab-' + tab).removeClass('hidden');
            $('#gradeFilterWrap').toggle(tab === 'results');   // Grade only applies to Results
            (tab === 'results' ? resultsTable : outstandingTable).columns.adjust();
        }

        function applyGrade(grade) {
            $('#filterGrade').val(grade);
            // Grade cell renders e.g. "B · 3.00" wrapped in whitespace, so an
            // anchored ^ regex matches nothing — use a plain substring match on
            // the single grade letter (column 6 contains no other letters).
            resultsTable.column(6).search(grade || '', false, false).draw();
            // Filter the Top Vendors table too, by each vendor's average grade.
            var anyTV = false;
            $('#topVendorsBody tr.tv-row').each(function() {
                var show = !grade || String($(this).data('grade')) === grade;
                $(this).toggle(show);
                if (show) anyTV = true;
            });
            $('#topVendorsEmpty').toggle(!!grade && !anyTV);
            // Emphasize the selected slice on the grade pie.
            if (window.gradePie) {
                var idx = ['A', 'B', 'C', 'D'].indexOf(grade);
                var offs = [0, 0, 0, 0];
                if (idx >= 0) offs[idx] = 12;
                window.gradePie.data.datasets[0].offset = offs;
                window.gradePie.update();
            }
        }

        // Tab buttons
        $('.ve-tab-btn').on('click', function() {
            var tab = $(this).data('tab');
            withLoader(function() { activateTab(tab); });
        });

        // Dropdown filters (kept alongside the clickable charts).
        $('#filterBU').on('change', function() {
            var v = this.value ? ('^' + rx(this.value) + '$') : '';
            resultsTable.column(2).search(v, true, false).draw();
            outstandingTable.column(2).search(v, true, false).draw();
        });
        $('#filterGrade').on('change', function() { applyGrade(this.value); });
        $('#tableSearch').on('keyup', function() {
            resultsTable.search(this.value).draw();
            outstandingTable.search(this.value).draw();
        });

        // ── Charts (Chart.js): Grade pie + Team-average bar ──────────────
        var VE_GRADES  = [<?= (int)$grade_dist['A'] ?>, <?= (int)$grade_dist['B'] ?>, <?= (int)$grade_dist['C'] ?>, <?= (int)$grade_dist['D'] ?>];
        var VE_OPS_CNT = <?= (int)$ops_filled ?>;
        var VE_PRJ_CNT = <?= (int)$prj_filled ?>;

        // Grade distribution pie — clicking a slice filters the Results table.
        var pieEl = document.getElementById('gradePie');
        if (pieEl && typeof Chart !== 'undefined') {
            pieEl.style.cursor = 'pointer';
            window.gradePie = new Chart(pieEl, {
                type: 'pie',
                data: {
                    labels: ['A', 'B', 'C', 'D'],
                    datasets: [{ data: VE_GRADES, backgroundColor: ['#16a34a', '#2563eb', '#eab308', '#dc2626'], borderColor: '#fff', borderWidth: 2, offset: [0, 0, 0, 0], hoverOffset: 6 }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function(c) { return c.label + ': ' + c.parsed; } } }
                    },
                    onClick: function(evt, els) {
                        if (els.length) {
                            var g = ['A', 'B', 'C', 'D'][els[0].index];
                            var next = ($('#filterGrade').val() === g) ? '' : g;
                            withLoader(function() { activateTab('results'); applyGrade(next); });
                        }
                    }
                }
            });
        }

        // Evaluations submitted per team — static bar (count of POs each team
        // has evaluated; OPS vs PRJ).
        var teamEl = document.getElementById('teamAvgChart');
        if (teamEl && typeof Chart !== 'undefined') {
            window.teamAvgChart = new Chart(teamEl, {
                type: 'bar',
                data: {
                    labels: ['OPS', 'PRJ'],
                    datasets: [{ data: [VE_OPS_CNT, VE_PRJ_CNT], backgroundColor: '#4f46e5', borderRadius: 4, maxBarThickness: 56 }]
                },
                options: {
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: function(c) { var n = c.parsed.y || 0; return n + ' evaluation' + (n === 1 ? '' : 's'); } } }
                    },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1, precision: 0 }, grid: { color: '#f3f4f6' } }, x: { grid: { display: false } } }
                }
            });
        }

        // Drill-down
        function openDetail(po) {
            $('#veDetailPo').text(po);
            $('#veDetailBody').html('<div class="text-center text-gray-500 py-8">Loading…</div>');
            $('#veDetailModal').removeClass('hidden');
            $.ajax({ url: 'ajax_vendor_eval_tech_detail.php', data: { po: po }, dataType: 'json' })
                .done(function(data) {
                    if (data.error) { $('#veDetailBody').html('<div class="text-center text-red-500 py-8">' + esc(data.error) + '</div>'); return; }
                    $('#veDetailBody').html(renderDetail(data));
                })
                .fail(function() { $('#veDetailBody').html('<div class="text-center text-red-500 py-8">Unable to load detail. Please try again.</div>'); });
        }

        function renderTeam(label, slot) {
            if (!slot) {
                return '<div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center text-sm text-gray-400">' +
                    '<p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">' + esc(label) + '</p>Not submitted yet</div>';
            }
            var rows = '';
            (slot.answers || []).forEach(function(a) {
                if (a.is_rating) {
                    rows += '<tr' + (a.comment ? '' : ' class="border-b border-gray-100 last:border-0"') + '>' +
                        '<td class="py-2 pr-2 text-gray-700">' + esc(a.criterion_name) + '</td>' +
                        '<td class="py-2 px-2 text-right font-bold text-gray-800">' + esc(a.score) + '/4' +
                        '<span class="block text-[10px] font-normal text-gray-400">' + esc(a.selected_score_label) + '</span></td>' +
                        '<td class="py-2 px-2 text-right text-gray-500">' + Math.round(a.weight * 100) + '%</td>' +
                        '<td class="py-2 pl-2 text-right font-bold text-indigo-700">' + Number(a.weighted_score).toFixed(2) + '</td>' +
                        '</tr>';
                    if (a.comment) {
                        rows += '<tr class="border-b border-gray-100 last:border-0"><td colspan="4" class="pt-1 pb-2.5">' +
                            '<div class="flex items-start gap-1.5 text-[12px] text-gray-600 bg-gray-50 border-l-2 border-indigo-300 rounded-r px-3 py-1.5">' +
                            '<span class="text-indigo-400 leading-none">&#128172;</span><span>' + esc(a.comment) + '</span></div></td></tr>';
                    }
                } else {
                    // Text / identification answers (Kesimpulan, Nama) — render as a
                    // clearly labelled, readable block instead of faint italics.
                    rows += '<tr class="border-b border-gray-100 last:border-0 align-top">' +
                        '<td class="py-2.5 pr-3 text-[11px] font-bold text-gray-500 uppercase tracking-wide whitespace-nowrap">' + esc(a.criterion_name) + '</td>' +
                        '<td colspan="3" class="py-2.5 text-sm text-gray-800">' + (a.comment ? esc(a.comment) : '<span class="text-gray-400">(no response)</span>') + '</td>' +
                        '</tr>';
                }
            });
            var gradeCls = GRADE_CLASS[slot.score_band] || 'bg-gray-100 text-gray-600 border-gray-200';
            return '<div class="border border-gray-200 rounded-lg overflow-hidden">' +
                '<div class="flex items-center justify-between px-4 py-3 bg-gray-50 border-b border-gray-200">' +
                    '<div><p class="text-xs font-bold text-gray-500 uppercase tracking-wider">' + esc(label) + ' Team</p>' +
                    '<p class="text-xs text-gray-400">by ' + esc(slot.requestor_name) + '</p></div>' +
                    '<span class="inline-block px-2.5 py-1 rounded-md text-xs font-black border ' + gradeCls + '">' +
                    esc(slot.score_band) + ' · ' + (slot.total_score != null ? Number(slot.total_score).toFixed(2) : '—') + '</span>' +
                '</div>' +
                '<div class="p-4"><table class="w-full text-sm"><thead><tr class="text-left text-[10px] text-gray-400 uppercase border-b border-gray-200">' +
                    '<th class="pb-2 font-bold">Criterion</th><th class="pb-2 font-bold text-right">Score</th>' +
                    '<th class="pb-2 font-bold text-right">Weight</th><th class="pb-2 font-bold text-right">Weighted</th></tr></thead>' +
                    '<tbody>' + rows + '</tbody></table></div></div>';
        }

        function renderDetail(data) {
            var html = '';
            if (data.final) {
                var gradeCls = GRADE_CLASS[data.final.grade] || 'bg-gray-100 text-gray-600 border-gray-200';
                html += '<div class="text-center mb-6 pb-6 border-b border-gray-100">' +
                    '<p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Final Grade (Average of Both Teams)</p>' +
                    '<span class="inline-block mt-2 px-4 py-1.5 rounded-lg text-lg font-black border ' + gradeCls + '">' +
                    esc(data.final.grade) + ' · ' + Number(data.final.score).toFixed(2) + '</span></div>';
            } else {
                html += '<div class="text-center mb-6 pb-6 border-b border-gray-100 text-sm text-gray-500">Final grade is available only after both teams submit.</div>';
            }
            html += '<div class="grid grid-cols-1 gap-4">' + renderTeam('Operations', data.ops) + renderTeam('Project', data.project) + '</div>';
            return html;
        }

        $('#veResultsTable tbody').on('click', 'tr.ve-row-clickable', function(e) {
            // The vendor-name link handles its own click (opens the vendor
            // profile); don't also open the PO breakdown modal for it.
            if ($(e.target).closest('.ve-vendor-link').length) return;
            var po = $(this).data('po');
            if (po) openDetail(String(po));
        });
        $('#veDetailClose, #veDetailModal').on('click', function(e) {
            if (e.target === this || $(e.target).closest('#veDetailClose').length) { $('#veDetailModal').addClass('hidden'); }
        });

        // ── Vendor quality profile ───────────────────────────────────────
        var vendorData = null;      // last-loaded {vendor_name, profile}
        var vendorRange = 'all_time';

        function renderVendorBucket(b) {
            if (!b || b.po_count === 0) {
                var none = '<div class="text-center text-gray-400 py-8 text-sm">No completed evaluations for this vendor in this range.';
                if (b && b.partial_count > 0) {
                    none += '<div class="mt-1 text-xs text-gray-400">' + b.partial_count + ' PO' + (b.partial_count === 1 ? '' : 's') + ' still awaiting a second team.</div>';
                }
                return none + '</div>';
            }
            var gradeCls = GRADE_CLASS[b.avg_grade] || 'bg-gray-100 text-gray-600 border-gray-200';
            var html = '<div class="grid grid-cols-2 gap-4 mb-4">' +
                '<div class="bg-gray-50 border border-gray-200 rounded-lg p-4">' +
                    '<p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">POs Evaluated</p>' +
                    '<p class="text-3xl font-black text-gray-800">' + b.po_count + '</p>' +
                    (b.partial_count > 0 ? '<p class="text-[11px] text-gray-400 mt-1">+' + b.partial_count + ' awaiting a team</p>' : '') +
                '</div>' +
                '<div class="bg-gray-50 border border-gray-200 rounded-lg p-4">' +
                    '<p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Average Score</p>' +
                    '<div class="flex items-end gap-2"><span class="text-3xl font-black text-gray-800">' + Number(b.avg_score).toFixed(2) + '</span>' +
                    '<span class="text-xs text-gray-400 mb-1">/ 4.00</span>' +
                    '<span class="ml-auto inline-block px-2.5 py-1 rounded-md text-xs font-black border ' + gradeCls + '">' + esc(b.avg_grade) + '</span></div>' +
                '</div></div>';

            // Grade distribution strip.
            var GC = { A: '#16a34a', B: '#2563eb', C: '#eab308', D: '#dc2626' };
            html += '<div class="flex flex-wrap items-center gap-x-4 gap-y-1 mb-4 text-[12px] text-gray-600">' +
                '<span class="text-[11px] font-bold text-gray-400 uppercase tracking-wider">Grades</span>';
            ['A', 'B', 'C', 'D'].forEach(function(g) {
                html += '<span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:' + GC[g] + '"></span>' + g + ' ' + (b.grade_dist[g] || 0) + '</span>';
            });
            html += '</div>';

            // Per-PO table.
            var rows = '';
            (b.pos || []).forEach(function(p) {
                var gc = GRADE_CLASS[p.final_grade] || 'bg-gray-100 text-gray-600 border-gray-200';
                rows += '<tr class="border-b border-gray-100 last:border-0">' +
                    '<td class="py-2 pr-2 font-mono text-indigo-700">' + esc(p.po_number) + '</td>' +
                    '<td class="py-2 px-2 text-gray-500 font-mono text-xs">' + esc(p.p_group) + '</td>' +
                    '<td class="py-2 px-2 text-right font-semibold text-gray-800">' + Number(p.final_score).toFixed(2) + '</td>' +
                    '<td class="py-2 px-2 text-center"><span class="inline-block px-2 py-0.5 rounded-md text-xs font-black border ' + gc + '">' + esc(p.final_grade) + '</span></td>' +
                    '<td class="py-2 pl-2 text-right text-gray-500 text-xs">' + esc(veDate(p.last_submitted_at)) + '</td>' +
                    '</tr>';
            });
            html += '<div class="max-h-64 overflow-y-auto border-t border-gray-100 pt-2"><table class="w-full text-[13px]">' +
                '<thead><tr class="text-left text-[10px] text-gray-400 uppercase tracking-wider border-b border-gray-200">' +
                '<th class="py-2 pr-2 font-bold">PO</th><th class="py-2 px-2 font-bold">P.Group</th>' +
                '<th class="py-2 px-2 font-bold text-right">Score</th><th class="py-2 px-2 font-bold text-center">Grade</th>' +
                '<th class="py-2 pl-2 font-bold text-right">Date</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
            return html;
        }

        function veDate(dt) {
            if (!dt) return '';
            var d = new Date(String(dt).replace(' ', 'T'));
            if (isNaN(d)) return String(dt);
            return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
        }

        function paintVendor() {
            if (!vendorData) return;
            $('.ve-toggle-btn').removeClass('active').filter('[data-range="' + vendorRange + '"]').addClass('active');
            $('#veVendorBody').html(renderVendorBucket(vendorData.profile[vendorRange]));
        }

        // ── Per-vendor Year/Month filter (independent of the dashboard's
        // global one) — lets you see just this vendor's "this month" or "last
        // month" performance. Overrides the All-time/Year-to-date toggle while
        // a Year and/or Month is picked; clearing both back to "All" hands
        // control back to the toggle.
        var vendorRangeName = null; // vendor the in-flight/last range fetch was for
        function inVendorRangeMode() {
            return $('#veVendorYear').val() !== '' || $('#veVendorMonth').val() !== '';
        }
        function loadVendorRange(name) {
            vendorRangeName = name;
            $('#veVendorToggleWrap').css('opacity', '.4').css('pointer-events', 'none');
            $('#veVendorRangeNote').removeClass('hidden');
            $('#veVendorBody').html('<div class="text-center text-gray-500 py-8">Loading…</div>');
            $.ajax({
                url: 'ajax_vendor_eval_tech_profile.php',
                data: { vendor: name, year: $('#veVendorYear').val(), month: $('#veVendorMonth').val() },
                dataType: 'json'
            })
                .done(function(data) {
                    if (vendorRangeName !== name || !inVendorRangeMode()) return; // superseded by a newer pick / cleared
                    if (data.error) { $('#veVendorBody').html('<div class="text-center text-red-500 py-8">' + esc(data.error) + '</div>'); return; }
                    $('#veVendorBody').html(renderVendorBucket(data.range));
                })
                .fail(function() { $('#veVendorBody').html('<div class="text-center text-red-500 py-8">Unable to load this period. Please try again.</div>'); });
        }

        function openVendorProfile(name) {
            vendorData = null;
            vendorRange = 'all_time';
            $('#veVendorName').text(name);
            $('#veVendorYear').val('');
            $('#veVendorMonth').val('');
            $('#veVendorToggleWrap').css('opacity', '1').css('pointer-events', '');
            $('#veVendorRangeNote').addClass('hidden');
            $('.ve-toggle-btn').removeClass('active').filter('[data-range="all_time"]').addClass('active');
            $('#veVendorBody').html('<div class="text-center text-gray-500 py-8">Loading…</div>');
            $('#veVendorModal').removeClass('hidden');
            $.ajax({ url: 'ajax_vendor_eval_tech_profile.php', data: { vendor: name }, dataType: 'json' })
                .done(function(data) {
                    if (data.error) { $('#veVendorBody').html('<div class="text-center text-red-500 py-8">' + esc(data.error) + '</div>'); return; }
                    vendorData = data;
                    paintVendor();
                })
                .fail(function() { $('#veVendorBody').html('<div class="text-center text-red-500 py-8">Unable to load vendor profile. Please try again.</div>'); });
        }

        // Vendor name links live in both the Results table and the Top Vendors
        // table; stopPropagation so a Results-row click doesn't also open the PO modal.
        // True document-delegated (persists across AJAX swaps automatically) —
        // .off() first so re-running initVeDashboard() after a swap doesn't
        // stack a second identical handler on top of the still-live one.
        $(document).off('click.veVendorLink').on('click.veVendorLink', '.ve-vendor-link', function(e) {
            e.stopPropagation();
            var name = $(this).data('vendor');
            if (name) openVendorProfile(String(name));
        });
        $('.ve-toggle-btn').on('click', function() {
            vendorRange = $(this).data('range');
            paintVendor();
        });
        $('#veVendorYear, #veVendorMonth').on('change', function() {
            var name = $('#veVendorName').text();
            if (!name || name === '—') return;
            if (inVendorRangeMode()) {
                loadVendorRange(name);
            } else {
                // Cleared back to "All years" + "All months" — hand control
                // back to whichever All-time/Year-to-date toggle is active.
                vendorRangeName = null;
                $('#veVendorToggleWrap').css('opacity', '1').css('pointer-events', '');
                $('#veVendorRangeNote').addClass('hidden');
                paintVendor();
            }
        });
        $('#veVendorClose, #veVendorModal').on('click', function(e) {
            if (e.target === this || $(e.target).closest('#veVendorClose').length) { $('#veVendorModal').addClass('hidden'); }
        });

        // ── Site allocations: filter/search + drill-down ─────────────────
        var siteFilter = 'all';
        function applySiteFilter() {
            var q = ($('#siteSearch').val() || '').toLowerCase();
            var shown = 0;
            $('#siteGrid .ve-site-card').each(function() {
                var $c = $(this);
                var okF = siteFilter === 'all' || $c.data('evaluated') === 1 || $c.attr('data-evaluated') === '1';
                var okQ = q === '' || String($c.attr('data-search')).indexOf(q) !== -1;
                var ok = okF && okQ;
                $c.toggleClass('hidden', !ok);
                if (ok) shown++;
            });
            $('#siteNoMatch').toggleClass('hidden', shown !== 0);
        }
        $('.ve-site-filter').on('click', function() {
            siteFilter = $(this).data('filter');
            $('.ve-site-filter').removeClass('active');
            $(this).addClass('active');
            applySiteFilter();
        });
        $('#siteSearch').on('keyup', applySiteFilter);

        // A single team's cell: grade band + score badge, or a muted dash when
        // that team hasn't submitted yet.
        function teamCell(slot) {
            if (!slot || slot.band == null) {
                return '<span class="text-gray-300">—</span>';
            }
            var gc = GRADE_CLASS[slot.band] || 'bg-gray-100 text-gray-600 border-gray-200';
            return '<span class="inline-block px-2 py-0.5 rounded-md text-xs font-black border ' + gc + '">' +
                esc(slot.band) + (slot.score != null ? ' · ' + Number(slot.score).toFixed(2) : '') + '</span>';
        }
        function renderSite(data) {
            var pos = data.pos || [];
            if (!pos.length) { return '<div class="text-center text-gray-400 py-8 text-sm">No purchase orders for this site.</div>'; }
            var done = pos.filter(function(p) { return p.status === 'completed'; }).length;
            var html = '<p class="text-sm text-gray-500 mb-1"><b class="text-gray-800">' + pos.length + '</b> PO' + (pos.length === 1 ? '' : 's') +
                ' at this site · <b class="text-gray-800">' + done + '</b> with a final grade (both teams).</p>' +
                '<p class="text-[11px] text-gray-400 mb-4">Both OPS &amp; PRJ filled = final grade. Only one filled = still partial.</p>';
            var rows = '';
            pos.forEach(function(p) {
                rows += '<tr class="border-b border-gray-100 last:border-0">' +
                    '<td class="py-2 pr-2 font-mono text-indigo-700">' + esc(p.po_number) + '</td>' +
                    '<td class="py-2 px-2 text-gray-700">' + esc(p.vendor_name || '—') + '</td>' +
                    '<td class="py-2 px-2 text-center">' + teamCell(p.ops) + '</td>' +
                    '<td class="py-2 pl-2 text-center">' + teamCell(p.project) + '</td>' +
                    '</tr>';
            });
            html += '<div class="max-h-72 overflow-y-auto border-t border-gray-100 pt-2"><table class="w-full text-[13px]">' +
                '<thead><tr class="text-left text-[10px] text-gray-400 uppercase tracking-wider border-b border-gray-200">' +
                '<th class="py-2 pr-2 font-bold">PO</th><th class="py-2 px-2 font-bold">Vendor</th>' +
                '<th class="py-2 px-2 font-bold text-center">OPS</th><th class="py-2 pl-2 font-bold text-center">PRJ</th>' +
                '</tr></thead><tbody>' + rows + '</tbody></table></div>';
            return html;
        }
        function openSite(site) {
            $('#veSiteName').text(site);
            $('#veSiteBody').html('<div class="text-center text-gray-500 py-8">Loading…</div>');
            $('#veSiteModal').removeClass('hidden');
            $.ajax({ url: 'ajax_vendor_eval_tech_site.php', data: { site: site }, dataType: 'json' })
                .done(function(data) {
                    if (data.error) { $('#veSiteBody').html('<div class="text-center text-red-500 py-8">' + esc(data.error) + '</div>'); return; }
                    $('#veSiteBody').html(renderSite(data));
                })
                .fail(function() { $('#veSiteBody').html('<div class="text-center text-red-500 py-8">Unable to load site detail. Please try again.</div>'); });
        }
        // True document-delegated — same .off()-then-.on() reasoning as
        // .ve-vendor-link above.
        $(document).off('click.veSiteCard').on('click.veSiteCard', '.ve-site-card', function() {
            var site = $(this).data('site');
            if (site) openSite(String(site));
        });
        $('#veSiteClose, #veSiteModal').on('click', function(e) {
            if (e.target === this || $(e.target).closest('#veSiteClose').length) { $('#veSiteModal').addClass('hidden'); }
        });
    }

    $(document).ready(function() { initVeDashboard(); });

    // ── No-refresh P.Group / Year / Month filtering ──────────────────────
    // Fetches just the #veAppRoot fragment (server-side AJAX branch above)
    // and swaps it in, instead of a full page navigation. All the handlers
    // below are registered ONCE (they're document/window-level, not tied to
    // elements inside #veAppRoot, so they survive the innerHTML swap) —
    // do not move this block inside initVeDashboard(), it would re-register
    // on every swap and fire once per accumulated registration.
    var veLoadSeq = 0; // guards against an earlier, still-in-flight fetch clobbering a newer one
    function loadVeDashboard(url, pushState) {
        var $root = $('#veAppRoot');
        var seq = ++veLoadSeq;
        $root.css('opacity', '.5').css('pointer-events', 'none');
        // Appended (not baked into the PHP-rendered markup) so it naturally
        // disappears the moment $root.html(html) replaces the content below —
        // no separate "hide loader" step needed on success.
        var $loader = $('<div class="ve-app-loader"><div class="ve-spin"></div></div>');
        $root.append($loader);
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.text();
            })
            .then(function(html) {
                if (seq !== veLoadSeq) { return; } // a newer click already superseded this one
                $root.html(html); // jQuery .html() (not raw innerHTML) so nothing here relies on <script> execution
                $root.css('opacity', '1').css('pointer-events', '');
                if (pushState) { history.pushState({}, '', url); }
                initVeDashboard();
            })
            .catch(function() {
                // Fetch failed (network hiccup, etc.) — fall back to a real
                // navigation rather than leaving the user stuck.
                window.location.href = url;
            });
    }

    $(document).on('click', 'a.ve-filter-link', function(e) {
        e.preventDefault();
        loadVeDashboard(this.href, true);
    });

    window.veApplyTimeFilter = function() {
        var $f = $('#veTimeFilterFormTop');
        loadVeDashboard(window.location.pathname + '?' + $f.serialize(), true);
    };

    window.addEventListener('popstate', function() {
        loadVeDashboard(window.location.href, false);
    });
    </script>

<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
