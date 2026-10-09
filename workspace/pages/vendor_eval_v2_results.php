<?php
// workspace/pages/vendor_eval_v2_results.php
//
// Vendor Evaluations — Unified page
// Combines: v2 Evaluation Grade Results + v1 Legacy PO Worklist (read-only)
// Access: Manager and Category Manager only.
//
// v2 data : vendor_eval_submissions (read-only), plus the Category-Manager-only
//           manual-assignment queue (see vendor_eval_v2_routing.php).
// v1 data : prs + vendor_evaluations (read-only, legacy section only, clearly marked)
// No DB writes here — the manual assignment write lives in vendor_eval_v2_assign_set.php.
// No WA/email. No external API calls.

require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_results_helper.php';

// ── ACCESS CONTROL ───────────────────────────────────────────────────────────
// Base gate: Manager / Category Manager get full page access (all tabs).
// Additive grant: a PKR/PKP project_pgroup_officers row (role PIC, NOT a
// Manager/Category Manager) grants view+edit access to ONLY the Store Opening
// Date tab, scoped to that officer's own group — see get_store_opening_officer_groups().
// Neither grant -> access denied entirely.
$is_manager = in_array($_SESSION['role'] ?? '', ['Manager', 'Category Manager'], true);
$store_opening_officer_groups = $is_manager ? [] : get_store_opening_officer_groups($pdo, $_SESSION);
// Groups this user may view/edit Store Opening Dates for: Manager/CM = both, officer = their own only.
$store_opening_groups = $is_manager ? [1, 2] : $store_opening_officer_groups;

if (!$is_manager && empty($store_opening_officer_groups)) {
    require_once '../includes/header.php';
    echo "<div class='p-8 text-center mt-10'>
            <h2 class='text-2xl font-bold text-red-600'>Access Denied</h2>
            <p class='text-sm text-gray-600 mt-2'>This page is only available to Managers, Category Managers, and assigned Project Officers.</p>
          </div>";
    require_once '../includes/footer.php';
    exit;
}
require_once '../includes/header.php';

date_default_timezone_set('Asia/Jakarta');

// One-shot result banner from vendor_eval_v2_set_store_opening_date.php (PRG redirect).
$store_saved_ok    = trim((string)($_GET['store_saved'] ?? ''));
$store_save_error  = trim((string)($_GET['store_save_error'] ?? ''));

// Non-managers (PKR/PKP officers) never see Results/Worklist/Assignments —
// Store Opening Date is their only tab, so it (and any redirect back into it)
// becomes the default-active one.
$default_tab = $is_manager ? 'results-tab' : 'store-opening-tab';
if ($store_saved_ok !== '' || $store_save_error !== '') {
    $default_tab = 'store-opening-tab';
}

function storeSaveErrorMessage(string $code): string
{
    return [
        'method'  => 'Store Opening Date must be submitted from the form.',
        'access'  => 'You do not have access to set Store Opening Dates.',
        'input'   => 'Please choose a valid date before saving.',
        'notfound'=> 'That site was not found among in-scope new-store sites.',
        'scope'   => 'That site belongs to a different project group — you can only set dates for your own group\'s sites.',
        'db'      => 'A system error prevented the save. Please try again.',
    ][$code] ?? 'The Store Opening Date could not be saved. Please try again.';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function scoreGrade(float $avg): string
{
    if ($avg >= 3.5) return 'A';
    if ($avg >= 2.5) return 'B';
    if ($avg >= 1.5) return 'C';
    return 'D';
}

function gradeClass(string $grade): string
{
    return match ($grade) {
        'A'     => 'bg-green-100 text-green-800',
        'B'     => 'bg-blue-100 text-blue-800',
        'C'     => 'bg-yellow-100 text-yellow-800',
        default => 'bg-red-100 text-red-800',
    };
}

function fmtDate(?string $dt): string
{
    if (!$dt) return '—';
    return (new DateTime($dt, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Jakarta'))
        ->format('d M Y H:i');
}

// Human message for the ?assign_error code handed back by vendor_eval_v2_assign_set.php.
function assignErrorMessage(string $code): string
{
    return [
        'method'    => 'Assignment must be submitted from the form.',
        'input'     => 'Please choose a question set before saving.',
        'notfound'  => 'That purchase order was not found.',
        'set'       => 'The selected question set is not available. Please pick another.',
        'notqueued' => 'That PO is already mapped or was just assigned — it is no longer in the queue.',
        'dup'       => 'That PO was already assigned a question set. The queue has been refreshed.',
        'db'        => 'A system error prevented the assignment. Please try again.',
    ][$code] ?? 'The assignment could not be completed. Please try again.';
}

// ── Manager-only data prep (Results / Worklist / Manual Assignments tabs) ────
// PKR/PKP officers only ever see the Store Opening Date tab (below), so none
// of this needs to run for them — defaults keep the rest of the file safe to
// reference regardless of access level.
$f_vendor = $f_l2 = $f_l3 = $f_grade = $f_date_from = $f_date_to = '';
$l2_options = $l3_options = $rows = $detail_by_key = $bu_options = [];
$grade_dist   = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
$total_groups = 0;
$v1_list   = [];
$v1_counts = ['Need Evaluation' => 0, 'Partially Evaluated' => 0, 'Evaluated' => 0, 'Waiting for GR' => 0];
$quant_config = ['qualitative_weight_percent' => 50, 'otf_weight_percent' => 25, 'top_weight_percent' => 25];
$is_category_manager = false;
$manual_queue = $assignable_sets = [];
$assign_ok = $assign_error = '';

if ($is_manager):
// ── Filters (GET, safe prepared params) ──────────────────────────────────────
$f_vendor    = trim($_GET['vendor']    ?? '');
$f_l2        = trim($_GET['l2']        ?? '');
$f_l3        = trim($_GET['l3']        ?? '');
$f_grade     = in_array($_GET['grade'] ?? '', ['A','B','C','D']) ? $_GET['grade'] : '';
$f_date_from = trim($_GET['date_from'] ?? '');
$f_date_to   = trim($_GET['date_to']   ?? '');

// ── Dropdown options ──────────────────────────────────────────────────────────
try {
    $l2_options = $pdo->query(
        "SELECT DISTINCT taxonomy_level_2
           FROM vendor_eval_submissions
          WHERE status='submitted' AND taxonomy_level_2 IS NOT NULL
          ORDER BY taxonomy_level_2"
    )->fetchAll(PDO::FETCH_COLUMN);

    $l3_options = $pdo->query(
        "SELECT DISTINCT taxonomy_level_3
           FROM vendor_eval_submissions
          WHERE status='submitted' AND taxonomy_level_3 IS NOT NULL
          ORDER BY taxonomy_level_3"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $l2_options = [];
    $l3_options = [];
}

// ── v2 aggregation + submission detail ──────────────────────────────────────
// Both now live server-side in the DataTables endpoint (WHERE/GROUP BY built by
// ve2_grade_where_from_filters() + ve2_grade_status_sql(), includes/vendor_eval_v2_results_helper.php
// — including the gr_date / business_units columns added alongside the Store
// Opening Date feature) and the on-demand modal endpoint (ajax_vendor_eval_v2_detail.php)
// — see #evalGradeTable's serverSide DataTable init and openEvalModal() below.

// ── v2 Grade overview — unfiltered, for top cards and chart ──────────────────
$grade_dist   = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
$total_groups = 0;
try {
    $ov_avgs = $pdo->query("
        SELECT AVG(total_score) AS avg_score
        FROM vendor_eval_submissions
        WHERE status = 'submitted'
        GROUP BY vendor_name, taxonomy_level_2, taxonomy_level_3
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ov_avgs as $avg) {
        $g = scoreGrade((float)$avg);
        $grade_dist[$g]++;
        $total_groups++;
    }
} catch (PDOException $e) {}

// ── v1 Legacy worklist — read-only, clearly marked ───────────────────────────
// The full PO list now lives server-side in ajax_vendor_eval_v1_table.php
// (#v1MonitorTable's serverSide DataTable). Only the status-legend counts are
// still needed here, computed with the same CASE the endpoint uses so the two
// can never disagree about what "Need Evaluation" etc. means.
$v1_counts = ['Need Evaluation' => 0, 'Partially Evaluated' => 0, 'Evaluated' => 0, 'Waiting for GR' => 0];
$quant_config = $pdo->query("SELECT * FROM quant_eval_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$quant_config) {
    $quant_config = ['qualitative_weight_percent' => 50, 'otf_weight_percent' => 25, 'top_weight_percent' => 25];
}

try {
    $v1_status_sql = ve1_worklist_status_sql($quant_config, ve1_today_jakarta());
    $cnt_stmt = $pdo->query("SELECT w.v1_status, COUNT(*) AS n FROM ({$v1_status_sql}) w GROUP BY w.v1_status");
    foreach ($cnt_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($v1_counts[$row['v1_status']])) {
            $v1_counts[$row['v1_status']] = (int)$row['n'];
        }
    }
} catch (PDOException $e) {}

// Options for the PO Worklist tab's "Business Unit" filter dropdown
// (data-v1col="10"). Empty if pgroup_business_unit_mappings isn't applied
// on this environment yet — see ve1_business_unit_options().
try {
    $bu_options = ve1_business_unit_options($pdo);
} catch (PDOException $e) {
    $bu_options = [];
}

// ── Manual Question Set Assignment queue (Category Manager only) ──────────────
// Visibility tool: lists POs that matched ONLY the Default routing rule so a
// Category Manager can assign a taxonomy-specific set. Reads only; the write is
// in vendor_eval_v2_assign_set.php. Fails closed (empty) on any error.
$is_category_manager = (($_SESSION['role'] ?? '') === 'Category Manager');
$manual_queue    = [];
$assignable_sets = [];
if ($is_category_manager) {
    require_once '../includes/vendor_eval_v2_routing.php';
    try { $manual_queue = ve2_manual_queue_pos($pdo); }
    catch (Throwable $e) { $manual_queue = []; }
    try {
        $assignable_sets = $pdo->query(
            "SELECT id, set_code, set_name FROM vendor_eval_question_sets WHERE is_active = 1 ORDER BY set_code"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $assignable_sets = []; }
}
// One-shot result banner from vendor_eval_v2_assign_set.php (PRG redirect).
$assign_ok    = trim((string)($_GET['assigned'] ?? ''));
$assign_error = trim((string)($_GET['assign_error'] ?? ''));
endif; // $is_manager

// ── Store Opening Date tab data (Manager/CM: both groups; PKR/PKP officer: own group only) ──
// Scope: distinct sites from prs where is_new_store=1 AND ticket_type contains
// "new store" (guards against PRs flagged new-store but not actually a new-store
// ticket type) and p_group belongs to a
// PGroup matching [A-Z]97 or A77 — confirmed via project_pgroups to fall only
// within project_group_number 1 (PKR) or 2 (PKP). owning_project_group_number
// on already-dated sites comes from vendor_eval_store_opening_dates itself
// (stored at assignment time); undated sites resolve it live from project_pgroups.
$store_opening_sites = [];
$site_po_lists       = [];
if (!empty($store_opening_groups)) {
    try {
        $sog_placeholders = implode(',', array_fill(0, count($store_opening_groups), '?'));
        $sog_stmt = $pdo->prepare("
            SELECT
                s.site_allocation,
                pp.project_group_number AS owning_project_group_number,
                MAX(s.p_group)          AS sample_p_group,
                COUNT(DISTINCT s.po_number) AS po_count,
                MAX(s.gr_date)          AS gr_date,
                sod.store_opening_date,
                sod.assigned_by,
                sod.assigned_at
            FROM (
                SELECT site_allocation, p_group, po_number, gr_date
                FROM prs
                WHERE is_new_store = 1
                  AND LOWER(IFNULL(ticket_type, '')) LIKE '%new store%'
                  AND site_allocation IS NOT NULL AND site_allocation != ''
                  AND (p_group REGEXP '^[A-Z]97$' OR p_group = 'A77')
            ) s
            JOIN project_pgroups pp ON pp.p_group = s.p_group AND pp.status = 'ACTIVE'
            LEFT JOIN vendor_eval_store_opening_dates sod ON sod.site_allocation = s.site_allocation
            WHERE pp.project_group_number IN ($sog_placeholders)
            GROUP BY s.site_allocation, pp.project_group_number, sod.store_opening_date, sod.assigned_by, sod.assigned_at
            ORDER BY (sod.store_opening_date IS NULL) DESC, s.site_allocation ASC
        ");
        $sog_stmt->execute($store_opening_groups);
        $store_opening_sites = $sog_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $store_opening_sites = [];
    }

    // Full PO list per site for the edit modal — fetched separately (rather than
    // GROUP_CONCAT above) so a site with a long PO list is never silently
    // truncated by group_concat_max_len.
    // po_number is filtered here because a New Store PR line that has not been
    // ordered yet carries a NULL/blank po_number; without this it reached the
    // modal as an empty chip. The po_count column above never showed it because
    // COUNT(DISTINCT) already ignores NULL — so this keeps the two consistent.
    try {
        $pol_stmt = $pdo->prepare("
            SELECT DISTINCT s.site_allocation, s.po_number
            FROM (
                SELECT site_allocation, p_group, po_number
                FROM prs
                WHERE is_new_store = 1
                  AND LOWER(IFNULL(ticket_type, '')) LIKE '%new store%'
                  AND site_allocation IS NOT NULL AND site_allocation != ''
                  AND (p_group REGEXP '^[A-Z]97$' OR p_group = 'A77')
            ) s
            JOIN project_pgroups pp ON pp.p_group = s.p_group AND pp.status = 'ACTIVE'
            WHERE pp.project_group_number IN ($sog_placeholders)
              AND s.po_number IS NOT NULL AND s.po_number != ''
            ORDER BY s.site_allocation ASC, s.po_number ASC
        ");
        $pol_stmt->execute($store_opening_groups);
        foreach ($pol_stmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $site_po_lists[$pr['site_allocation']][] = $pr['po_number'];
        }
    } catch (PDOException $e) {
        $site_po_lists = [];
    }
}
$store_opening_undated = array_values(array_filter($store_opening_sites, fn($r) => empty($r['store_opening_date'])));
$store_opening_dated   = array_values(array_filter($store_opening_sites, fn($r) => !empty($r['store_opening_date'])));

$team_short_codes = [1 => 'PKR', 2 => 'PKP'];
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php if ($is_category_manager): ?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<?php endif; ?>

<div class="space-y-6">

    <!-- Page Header -->
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Vendor Evaluations</h1>
        <div class="mt-2 p-3 bg-indigo-50 border border-indigo-200 rounded-lg text-sm text-indigo-800">
            <strong>Note:</strong> Scores are calculated per PO and category context. The same vendor may have
            different grades for different taxonomy categories.
        </div>
    </div>

    <?php
    $tab_active_cls = fn($id) => $id === $default_tab
        ? 'tab-btn active px-4 py-3 text-sm font-semibold text-indigo-600 border-b-2 border-indigo-600 transition-colors'
        : 'tab-btn px-4 py-3 text-sm font-semibold text-slate-600 border-b-2 border-transparent hover:text-slate-800 hover:border-slate-300 transition-colors';
    ?>
    <!-- ── Tab Navigation ──────────────────────────────────────────────────────── -->
    <div class="mt-6 border-b border-slate-200">
        <div class="flex gap-0">
            <?php if ($is_manager): ?>
            <button type="button" class="<?= $tab_active_cls('results-tab') ?>" data-tab="results-tab">
                Results
            </button>
            <button type="button" class="<?= $tab_active_cls('worklist-tab') ?>" data-tab="worklist-tab">
                PO Worklist
            </button>
            <?php if ($is_category_manager): ?>
            <button type="button" class="<?= $tab_active_cls('assignments-tab') ?>" data-tab="assignments-tab">
                Manual Assignments <?php if (!empty($manual_queue)): ?><span class="ml-1.5 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800"><?= count($manual_queue) ?></span><?php endif; ?>
            </button>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!empty($store_opening_groups)): ?>
            <button type="button" class="<?= $tab_active_cls('store-opening-tab') ?>" data-tab="store-opening-tab">
                Store Opening Date <?php if (!empty($store_opening_undated)): ?><span class="ml-1.5 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800"><?= count($store_opening_undated) ?></span><?php endif; ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($is_manager): ?>
    <!-- ── Tab 1: Results ──────────────────────────────────────────────────────── -->
    <div id="results-tab" class="tab-content <?= $default_tab === 'results-tab' ? 'active' : 'hidden' ?> mt-6 space-y-6">

        <!-- v2 Grade Overview -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">

        <!-- Grade summary cards — click sets the Grade filter (JS state, see the
             <script> below); no page reload, just an evalGradeTable.ajax.reload(). -->
        <div class="md:col-span-1 bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-col justify-between">
            <div>
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Grade Summary</h3>
                <div class="space-y-2.5">

                    <button type="button" data-grade="A" class="ve-grade-card w-full flex items-center justify-between p-2.5 rounded-lg border border-slate-200 hover:bg-green-50 hover:border-green-300 transition-all">
                        <div class="flex items-center space-x-2">
                            <span class="h-3 w-3 rounded-full bg-green-500 shadow-sm"></span>
                            <span class="text-sm font-semibold text-slate-700">Grade A (≥ 3.5)</span>
                        </div>
                        <span class="bg-green-100 text-green-800 text-[11px] font-bold px-2 py-0.5 rounded-full"><?= $grade_dist['A'] ?></span>
                    </button>

                    <button type="button" data-grade="B" class="ve-grade-card w-full flex items-center justify-between p-2.5 rounded-lg border border-slate-200 hover:bg-blue-50 hover:border-blue-300 transition-all">
                        <div class="flex items-center space-x-2">
                            <span class="h-3 w-3 rounded-full bg-blue-500 shadow-sm"></span>
                            <span class="text-sm font-semibold text-slate-700">Grade B (≥ 2.5)</span>
                        </div>
                        <span class="bg-blue-100 text-blue-800 text-[11px] font-bold px-2 py-0.5 rounded-full"><?= $grade_dist['B'] ?></span>
                    </button>

                    <button type="button" data-grade="C" class="ve-grade-card w-full flex items-center justify-between p-2.5 rounded-lg border border-slate-200 hover:bg-yellow-50 hover:border-yellow-300 transition-all">
                        <div class="flex items-center space-x-2">
                            <span class="h-3 w-3 rounded-full bg-yellow-400 shadow-sm"></span>
                            <span class="text-sm font-semibold text-slate-700">Grade C (≥ 1.5)</span>
                        </div>
                        <span class="bg-yellow-100 text-yellow-800 text-[11px] font-bold px-2 py-0.5 rounded-full"><?= $grade_dist['C'] ?></span>
                    </button>

                    <button type="button" data-grade="D" class="ve-grade-card w-full flex items-center justify-between p-2.5 rounded-lg border border-slate-200 hover:bg-red-50 hover:border-red-300 transition-all">
                        <div class="flex items-center space-x-2">
                            <span class="h-3 w-3 rounded-full bg-red-500 shadow-sm"></span>
                            <span class="text-sm font-semibold text-slate-700">Grade D (&lt; 1.5)</span>
                        </div>
                        <span class="bg-red-100 text-red-800 text-[11px] font-bold px-2 py-0.5 rounded-full"><?= $grade_dist['D'] ?></span>
                    </button>

                </div>
            </div>
        </div>

        <!-- Grade distribution pie chart -->
        <div class="md:col-span-2 bg-white p-6 rounded-xl shadow-sm border border-slate-200 flex flex-col justify-between">
            <div>
                <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-1">Evaluation Grade Distribution</h3>
                <p class="text-[11px] text-slate-500">
                    All vendor-category groups by grade. Click a segment to filter the results table below.
                </p>
            </div>
            <div class="relative w-full h-44 mt-3 flex items-center justify-center">
                <?php if ($total_groups > 0): ?>
                <canvas id="gradeDistChart"></canvas>
                <?php else: ?>
                <p class="text-sm text-slate-400">No v2 evaluation data yet.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── v2 Evaluation Grade Results ───────────────────────────────────── -->

    <!-- Filters — client-side state (veFilters, in the <script> below), not a page
         reload: "Apply"/"Clear" update veFilters and call evalGradeTable.ajax.reload(). -->
    <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-3">Filters</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-3">

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Vendor</label>
                <input type="text" id="ve_f_vendor" value="<?= h($f_vendor) ?>" placeholder="Keyword..."
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Taxonomy Level 2</label>
                <select id="ve_f_l2" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">All</option>
                    <?php foreach ($l2_options as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $f_l2 === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Taxonomy Level 3</label>
                <select id="ve_f_l3" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">All</option>
                    <?php foreach ($l3_options as $opt): ?>
                    <option value="<?= h($opt) ?>" <?= $f_l3 === $opt ? 'selected' : '' ?>><?= h($opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Grade</label>
                <select id="ve_f_grade" class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                    <option value="">All Grades</option>
                    <?php foreach (['A','B','C','D'] as $g): ?>
                    <option value="<?= $g ?>" <?= $f_grade === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Submitted From</label>
                <input type="date" id="ve_f_date_from" value="<?= h($f_date_from) ?>"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-600 mb-1">Submitted To</label>
                <input type="date" id="ve_f_date_to" value="<?= h($f_date_to) ?>"
                    class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
            </div>

        </div>
        <div class="mt-3 flex gap-2">
            <button type="button" id="ve-apply-filter"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition">
                Apply
            </button>
            <button type="button" id="ve-clear-filter"
                class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg transition">
                Clear
            </button>
        </div>
    </div>

    <!-- Evaluation Grade Table -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden relative">
        <div class="viz-v2-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80">
            <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            <p class="mt-3 text-xs font-semibold text-gray-500">Loading report…</p>
        </div>
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-base font-bold text-slate-800">Evaluation Grade</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    <span id="evalGradeResultCount">0</span> result<span id="evalGradeResultPlural">s</span>
                    <span id="evalGradeFilteredBadge" class="ml-1 text-indigo-600 font-medium <?= ($f_vendor || $f_l2 || $f_l3 || $f_grade || $f_date_from || $f_date_to) ? '' : 'hidden' ?>">(filtered)</span>
                </p>
            </div>
            <div class="flex flex-wrap gap-2 text-xs items-center">
                <span class="px-2 py-1 rounded bg-green-100 text-green-800 font-bold">A ≥ 3.5</span>
                <span class="px-2 py-1 rounded bg-blue-100  text-blue-800  font-bold">B ≥ 2.5</span>
                <span class="px-2 py-1 rounded bg-yellow-100 text-yellow-800 font-bold">C ≥ 1.5</span>
                <span class="px-2 py-1 rounded bg-red-100   text-red-800   font-bold">D &lt; 1.5</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 px-5 pt-4">
            <p class="text-xs text-slate-500 mr-auto">
                Fill the boxes in the table header, then click Apply Filter (or press Enter).
            </p>
            <button type="button" id="eg-export-btn"
                class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 text-xs font-semibold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H8a2 2 0 01-2-2V5a2 2 0 012-2h6l6 6v11a2 2 0 01-2 2z"></path></svg>
                Export to Excel
            </button>
            <button type="button" id="eg-apply-filter"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                Apply Filter
            </button>
            <button type="button" id="eg-clear-filter"
                class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                Clear
            </button>
        </div>
        <div class="overflow-x-auto">
            <table id="evalGradeTable" class="min-w-full text-sm display nowrap" style="width:100%">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 font-semibold uppercase tracking-wider">
                        <th class="px-4 py-3 text-left">Vendor</th>
                        <th class="px-4 py-3 text-left">Taxonomy (L2 &rarr; L3)</th>
                        <th class="px-4 py-3 text-center">Evaluated #PO</th>
                        <th class="px-4 py-3 text-center">Avg Score</th>
                        <th class="px-4 py-3 text-center">Grade</th>
                        <th class="px-4 py-3 text-center">Requestors</th>
                        <th class="px-4 py-3 text-left">Business Unit</th>
                        <th class="px-4 py-3 text-center">GR Date</th>
                        <th class="px-4 py-3 text-center">Detail</th>
                    </tr>
                    <!-- Filter row MUST have exactly as many <th> as the header row above.
                         With orderCellsTop:true a short row makes DataTables read an
                         undefined header cell and throw during init, which kills the
                         whole init callback — pagination and every column search box
                         then silently stop working. (Same constraint as #v1MonitorTable.) -->
                    <tr class="bg-slate-50">
                        <th class="px-4 py-2"><input type="text" data-egcol="0" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="Vendor..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="1" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="Taxonomy..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="2" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="#PO..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="3" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="Score..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="4" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="Grade..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="5" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="Requestors..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="6" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="Business Unit..."></th>
                        <th class="px-4 py-2"><input type="text" data-egcol="7" class="eg-col-search w-full text-xs p-1 border rounded" placeholder="GR Date..."></th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </div>

    </div>

    <!-- ── Tab 2: PO Worklist ──────────────────────────────────────────────────── -->
    <div id="worklist-tab" class="tab-content hidden mt-6">

    <!-- ── v1 Legacy PO Evaluation Worklist ──────────────────────────────── -->
    <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden relative">
        <div class="viz-v1-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80">
            <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            <p class="mt-3 text-xs font-semibold text-gray-500">Loading report…</p>
        </div>

        <div class="px-5 py-4 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-800">PO Evaluation Worklist</h2>
            <p class="text-xs text-slate-500 mt-0.5">
                <?= array_sum($v1_counts) ?> purchase order<?= array_sum($v1_counts) !== 1 ? 's' : '' ?> tracked.
            </p>
        </div>

        <!-- v1 Status filter cards -->
        <div class="px-5 pt-5 grid grid-cols-2 sm:grid-cols-4 gap-3">

            <button type="button" class="v1-legend-btn flex items-center justify-between p-2.5 rounded-lg border border-gray-200 hover:bg-red-50 hover:border-red-300 transition-all focus:outline-none" data-v1status="Need Evaluation">
                <div class="flex items-center space-x-2">
                    <span class="h-3 w-3 rounded-full bg-red-500 flex-shrink-0"></span>
                    <span class="text-xs font-semibold text-slate-700">Not Evaluated</span>
                </div>
                <span class="bg-red-100 text-red-800 text-[11px] font-bold px-2 py-0.5 rounded-full ml-2"><?= $v1_counts['Need Evaluation'] ?></span>
            </button>

            <button type="button" class="v1-legend-btn flex items-center justify-between p-2.5 rounded-lg border border-gray-200 hover:bg-orange-50 hover:border-orange-300 transition-all focus:outline-none" data-v1status="Partially Evaluated">
                <div class="flex items-center space-x-2">
                    <span class="h-3 w-3 rounded-full bg-orange-400 flex-shrink-0"></span>
                    <span class="text-xs font-semibold text-slate-700">Partial</span>
                </div>
                <span class="bg-orange-100 text-orange-800 text-[11px] font-bold px-2 py-0.5 rounded-full ml-2"><?= $v1_counts['Partially Evaluated'] ?></span>
            </button>

            <button type="button" class="v1-legend-btn flex items-center justify-between p-2.5 rounded-lg border border-gray-200 hover:bg-green-50 hover:border-green-300 transition-all focus:outline-none" data-v1status="Evaluated">
                <div class="flex items-center space-x-2">
                    <span class="h-3 w-3 rounded-full bg-green-500 flex-shrink-0"></span>
                    <span class="text-xs font-semibold text-slate-700">Evaluated</span>
                </div>
                <span class="bg-green-100 text-green-800 text-[11px] font-bold px-2 py-0.5 rounded-full ml-2"><?= $v1_counts['Evaluated'] ?></span>
            </button>

            <button type="button" class="v1-legend-btn flex items-center justify-between p-2.5 rounded-lg border border-gray-200 hover:bg-yellow-50 hover:border-yellow-300 transition-all focus:outline-none" data-v1status="Waiting for GR">
                <div class="flex items-center space-x-2">
                    <span class="h-3 w-3 rounded-full bg-yellow-400 flex-shrink-0"></span>
                    <span class="text-xs font-semibold text-slate-700">Waiting GR</span>
                </div>
                <span class="bg-yellow-100 text-yellow-800 text-[11px] font-bold px-2 py-0.5 rounded-full ml-2"><?= $v1_counts['Waiting for GR'] ?></span>
            </button>

        </div>

        <!-- Business Unit filter — sits up top in line with the status cards
             above, not as a table column. It still writes into v1ColFilters
             through the same data-v1col/v1-col-search convention the table's
             per-column boxes below use (key "10"), so the single Apply
             Filter/Clear pair further down governs it too — no separate
             Apply/Clear needed just for this one control. Multi-select: no
             option chosen means "all" (no filter); several chosen means
             "any of these" (OR — see V1_MULTI_FILTER_SEP / v1ApplyFilters()
             below and v1_worklist_filter_rows() server-side). -->
        <div class="px-5 pt-4 flex flex-wrap items-center gap-2">
            <label for="v1-bu-filter" class="text-xs font-semibold text-slate-600">Business Unit</label>
            <select id="v1-bu-filter" multiple size="5" data-v1col="10"
                class="v1-col-search text-xs py-1 px-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400 min-w-[220px]">
                <?php foreach ($bu_options as $bu): ?>
                <option value="<?= h($bu) ?>"><?= h($bu) ?></option>
                <?php endforeach; ?>
            </select>
            <span class="text-[11px] text-slate-400">Ctrl/Cmd-click (or Shift-click) to pick more than one. None selected = all.</span>
            <span id="v1-bu-count" class="hidden text-[11px] font-semibold text-indigo-600 bg-indigo-50 px-2 py-0.5 rounded-full"></span>
        </div>

        <!-- Filter controls: kept outside the table's own overflow-x-auto wrapper
             below so they stay put (don't scroll away) when the wide table is
             scrolled horizontally, and right-aligned with a tighter right inset
             (pr-3 vs. the table's pr-5) to sit further out toward the card edge.
             One Apply Filter/Clear pair for everything above (status cards,
             Business Unit) and below (per-column table boxes) — there is
             deliberately no separate "Reset" control anymore. -->
        <div class="pl-5 pr-3 pt-4 flex flex-wrap items-center justify-end gap-2">
            <p class="text-xs text-slate-500 mr-auto">
                Pick a status card or Business Unit above, or fill the boxes in the table header, then click Apply Filter (or press Enter).
            </p>
            <button type="button" id="v1-export-btn"
                class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 text-xs font-semibold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H8a2 2 0 01-2-2V5a2 2 0 012-2h6l6 6v11a2 2 0 01-2 2z"></path></svg>
                Export to Excel
            </button>
            <button type="button" id="v1-apply-filter"
                class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                Apply Filter
            </button>
            <button type="button" id="v1-clear-filter"
                class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                Clear
            </button>
        </div>

        <!-- v1 DataTables worklist -->
        <div class="p-5 pt-3 overflow-x-auto">
            <table id="v1MonitorTable" class="min-w-full leading-normal w-full text-left">
                <thead>
                    <tr>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase"></th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase">PO Number</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase" title="Qualitative Score from Survey (Max 4)">Qualitative Score</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase" title="On Time Fulfilment Score (Max 3)">OTF Score</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase" title="Term of Payment Score (Max 3)">TOP Score</th>
                        <th class="px-4 py-3 border-b-2 text-xs font-bold uppercase text-center bg-indigo-100 text-indigo-800" title="Overall Total Score (Max 100)">Final Score</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase">Requestors</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase">Vendor</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase">Delivery Date</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 text-xs font-bold text-gray-600 uppercase">Latest GR</th>
                    </tr>
                    <!-- Filter row MUST have exactly as many <th> as the header row above.
                         With orderCellsTop:true a short row makes DataTables read an
                         undefined header cell and throw during init, which kills the
                         whole init callback — pagination and every column search box
                         then silently stop working. -->
                    <tr class="bg-gray-50">
                        <th class="px-4 py-2"></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="1" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="PO..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="2" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="Score..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="3" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="OTF..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="4" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="TOP..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="5" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="Quant..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="6" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="Requestors..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="7" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="Vendor..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="8" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="Date..."></th>
                        <th class="px-4 py-2"><input type="text" data-v1col="9" class="v1-col-search w-full text-xs p-1 border rounded" placeholder="GR..."></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

    </div>

    </div>
    <?php endif; // $is_manager: Results + PO Worklist tabs ?>

    <!-- ── Tab 3: Manual Assignments ──────────────────────────────────────────── -->
    <?php if ($is_category_manager): ?>
    <div id="assignments-tab" class="tab-content hidden mt-6">

        <?php if ($assign_ok !== ''): ?>
        <div class="flex items-center gap-2 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 mb-6">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span>Question set assigned to PO <span class="font-mono font-semibold"><?= h($assign_ok) ?></span>. It has been removed from the queue below.</span>
        </div>
        <?php elseif ($assign_error !== ''): ?>
        <div class="flex items-center gap-2 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800 mb-6">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
            <span><?= h(assignErrorMessage($assign_error)) ?></span>
        </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-slate-800">⚠ Manual Question Set Assignment</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Purchase orders that matched only the default form and need a taxonomy-specific question set.</p>
                </div>
                <?php if (!empty($manual_queue)): ?>
                <span class="text-xs font-bold text-amber-800 bg-amber-100 border border-amber-200 rounded-full px-2.5 py-1"><?= count($manual_queue) ?> pending</span>
                <?php endif; ?>
            </div>

            <?php if (empty($manual_queue)): ?>
            <div class="px-5 py-6 flex items-center gap-2 text-sm">
                <svg class="w-5 h-5 text-green-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span class="font-semibold text-green-700">All Purchase Orders are successfully mapped.</span>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table id="manualQueueTable" class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 font-semibold uppercase tracking-wider">
                            <th class="px-4 py-3 text-left">PO Number</th>
                            <th class="px-4 py-3 text-left">Vendor</th>
                            <th class="px-4 py-3 text-left">Sub Category</th>
                            <th class="px-4 py-3 text-left">Commodity</th>
                            <th class="px-4 py-3 text-center">Status</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                        <tr class="bg-slate-50">
                            <th class="px-4 py-2"><input type="text" data-mqcol="0" class="mq-col-search w-full text-xs p-1 border rounded" placeholder="PO..."></th>
                            <th class="px-4 py-2"><input type="text" data-mqcol="1" class="mq-col-search w-full text-xs p-1 border rounded" placeholder="Vendor..."></th>
                            <th class="px-4 py-2"><input type="text" data-mqcol="2" class="mq-col-search w-full text-xs p-1 border rounded" placeholder="Sub Category..."></th>
                            <th class="px-4 py-2"><input type="text" data-mqcol="3" class="mq-col-search w-full text-xs p-1 border rounded" placeholder="Commodity..."></th>
                            <th class="px-4 py-2"></th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($manual_queue as $q):
                            $q_po = (string)$q['po_number'];
                            $q_ve = (string)($q['vendor_name'] ?? '');
                            $q_l2 = (string)($q['taxonomy_level_2'] ?? '');
                            $q_l3 = (string)($q['taxonomy_level_3'] ?? '');
                        ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?= h($q_po) ?></td>
                            <td class="px-4 py-3 text-slate-700"><?= $q_ve !== '' ? h($q_ve) : '—' ?></td>
                            <td class="px-4 py-3 text-slate-600"><?= $q_l2 !== '' ? h($q_l2) : '—' ?></td>
                            <td class="px-4 py-3 text-slate-500"><?= $q_l3 !== '' ? h($q_l3) : '—' ?></td>
                            <td class="px-4 py-3 text-center">
                                <span class="inline-block px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">Unmapped</span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <button type="button" title="Assign Question Set"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100"
                                    data-po="<?= h($q_po) ?>"
                                    data-vendor="<?= h($q_ve) ?>"
                                    data-l2="<?= h($q_l2) ?>"
                                    data-l3="<?= h($q_l3) ?>"
                                    onclick="openAssignModal(this)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php endif; ?>

    <!-- ── Tab 4: Store Opening Date ────────────────────────────────────────────── -->
    <?php if (!empty($store_opening_groups)): ?>
    <div id="store-opening-tab" class="tab-content <?= $default_tab === 'store-opening-tab' ? 'active' : 'hidden' ?> mt-6">

        <?php if ($store_saved_ok !== ''): ?>
        <div class="flex items-center gap-2 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 mb-6">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
            <span>Store Opening Date saved for <span class="font-mono font-semibold"><?= h($store_saved_ok) ?></span>.</span>
        </div>
        <?php elseif ($store_save_error !== ''): ?>
        <div class="flex items-center gap-2 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800 mb-6">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
            <span><?= h(storeSaveErrorMessage($store_save_error)) ?></span>
        </div>
        <?php endif; ?>

        <div class="bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
            <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-base font-bold text-slate-800">Store Opening Date</h2>
                </div>
                <?php if (!empty($store_opening_undated)): ?>
                <span class="text-xs font-bold text-amber-800 bg-amber-100 border border-amber-200 rounded-full px-2.5 py-1"><?= count($store_opening_undated) ?> pending</span>
                <?php endif; ?>
            </div>

            <?php if (empty($store_opening_sites)): ?>
            <div class="px-5 py-16 text-center text-slate-400 text-sm">
                No in-scope new-store sites found<?= $is_manager ? '.' : ' for your assigned group.' ?>
            </div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table id="storeOpeningTable" class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-slate-50 border-b border-slate-200 text-xs text-slate-500 font-semibold uppercase tracking-wider">
                            <th class="px-4 py-3 text-left">Site</th>
                            <th class="px-4 py-3 text-center">#PO</th>
                            <th class="px-4 py-3 text-center">GR Date</th>
                            <th class="px-4 py-3 text-center">Store Opening Date</th>
                            <th class="px-4 py-3 text-center">Assigned By</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                        <!-- Filter row MUST have exactly as many <th> as the header row above (see
                             the other tables' same note): a short row breaks orderCellsTop:true init. -->
                        <tr class="bg-slate-50">
                            <th class="px-4 py-2"><input type="text" data-socol="0" class="so-col-search w-full text-xs p-1 border rounded" placeholder="Site..."></th>
                            <th class="px-4 py-2"></th>
                            <th class="px-4 py-2"><input type="text" data-socol="2" class="so-col-search w-full text-xs p-1 border rounded" placeholder="GR Date..."></th>
                            <th class="px-4 py-2"><input type="text" data-socol="3" class="so-col-search w-full text-xs p-1 border rounded" placeholder="Date..."></th>
                            <th class="px-4 py-2"><input type="text" data-socol="4" class="so-col-search w-full text-xs p-1 border rounded" placeholder="Assigned By..."></th>
                            <th class="px-4 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                    <?php foreach ($store_opening_sites as $site):
                        $g_num    = (int)$site['owning_project_group_number'];
                        $g_code   = $team_short_codes[$g_num] ?? ('G' . $g_num);
                        $has_date = !empty($site['store_opening_date']);
                    ?>
                        <tr class="hover:bg-slate-50 transition-colors">
                            <td class="px-4 py-3 font-mono font-bold text-indigo-700"><?= h($site['site_allocation']) ?></td>
                            <td class="px-4 py-3 text-center text-slate-600"><?= (int)$site['po_count'] ?></td>
                            <td class="px-4 py-3 text-center text-slate-600">
                                <?php if (!empty($site['gr_date'])): ?>
                                    <?= h(date('d M Y', strtotime($site['gr_date']))) ?>
                                <?php else: ?>
                                    <span class="text-slate-400 italic">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($has_date): ?>
                                    <?= h(date('d M Y', strtotime($site['store_opening_date']))) ?>
                                <?php else: ?>
                                    <span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">Not set</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center text-slate-600"><?= !empty($site['assigned_by']) ? h($site['assigned_by']) : '—' ?></td>
                            <td class="px-4 py-3 text-center">
                                <button type="button" title="<?= $has_date ? 'Edit' : 'Set' ?> Store Opening Date"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100"
                                    data-site="<?= h($site['site_allocation']) ?>"
                                    data-group-code="<?= h($g_code) ?>"
                                    data-date="<?= h($site['store_opening_date'] ?? '') ?>"
                                    onclick="openStoreDateModal(this)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
    <?php endif; ?>

</div>

<!-- ── Submission Detail Modal ────────────────────────────────────────────── -->
<div id="eval-modal" style="display:none"
     class="fixed inset-0 z-50 items-center justify-center">
    <div id="eval-modal-backdrop"
         class="absolute inset-0 bg-black/40"
         onclick="closeEvalModal()"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4 max-h-[90vh] flex flex-col z-10">
        <div class="px-5 py-4 border-b border-indigo-100 bg-indigo-50 rounded-t-xl flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-bold text-indigo-800" id="modal-title">Submission Detail</h2>
                <p class="text-xs text-indigo-600 mt-0.5" id="modal-subtitle"></p>
            </div>
            <button onclick="closeEvalModal()"
                class="text-indigo-400 hover:text-red-600 font-bold text-xl leading-none mt-0.5 transition">&#x2715;</button>
        </div>
        <div class="overflow-y-auto flex-1 p-0" id="modal-body"></div>
    </div>
</div>

<?php if (!empty($store_opening_groups)): ?>
<!-- Store Opening Date modal ─────────────────────────────────────────────── -->
<div id="store-date-modal" style="display:none" class="fixed inset-0 z-50 items-center justify-center">
    <div class="absolute inset-0 bg-black/40" onclick="closeStoreDateModal()"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-md mx-4 z-10">
        <div class="px-5 py-4 border-b border-indigo-100 bg-indigo-50 rounded-t-xl flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-bold text-indigo-800">Store Opening Date</h2>
                <p class="text-xs text-indigo-600 mt-0.5">Manual entry only — set the date this site opened.</p>
            </div>
            <button type="button" onclick="closeStoreDateModal()" class="text-indigo-400 hover:text-red-600 font-bold text-xl leading-none mt-0.5 transition">&#x2715;</button>
        </div>
        <form method="POST" action="vendor_eval_v2_set_store_opening_date.php">
            <input type="hidden" name="site_allocation" id="sd-site-input">
            <div class="p-5 space-y-4">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Site</dt>
                        <dd id="sd-site" class="font-mono font-bold text-indigo-700 text-sm"></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Group</dt>
                        <dd id="sd-group" class="text-slate-800 text-sm"></dd>
                    </div>
                </dl>
                <dl>
                    <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1.5">Purchase Orders</dt>
                    <dd id="sd-po-list" class="flex flex-wrap gap-1.5 max-h-32 overflow-y-auto border border-slate-100 rounded-lg bg-slate-50 p-2"></dd>
                </dl>
                <div>
                    <label for="sd-date-input" class="block text-xs font-bold text-slate-600 mb-1">Store Opening Date <span class="text-red-500">*</span></label>
                    <input type="date" id="sd-date-input" name="store_opening_date" required
                        class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-400">
                </div>
            </div>
            <div class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2">
                <button type="button" onclick="closeStoreDateModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg transition">Cancel</button>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition">Save</button>
            </div>
        </form>
    </div>
</div>
<script>
const SITE_PO_LISTS = <?= json_encode($site_po_lists, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

function sdEsc(str) {
    var d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}

function openStoreDateModal(btn) {
    var site = btn.dataset.site || '';
    document.getElementById('sd-site').textContent      = site;
    document.getElementById('sd-site-input').value       = site;
    document.getElementById('sd-group').textContent      = btn.dataset.groupCode || '—';
    document.getElementById('sd-date-input').value        = btn.dataset.date || '';

    var pos     = SITE_PO_LISTS[site] || [];
    var listEl  = document.getElementById('sd-po-list');
    listEl.innerHTML = pos.length
        ? pos.map(function (po) {
            return '<span class="inline-block px-2 py-0.5 rounded bg-white border border-slate-200 text-slate-700 text-xs font-mono break-all">' + sdEsc(po) + '</span>';
        }).join('')
        : '<span class="text-slate-400 italic text-xs">No POs found</span>';

    document.getElementById('store-date-modal').style.display = 'flex';
}
function closeStoreDateModal() {
    document.getElementById('store-date-modal').style.display = 'none';
}
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeStoreDateModal();
});

// ── Store Opening Date table: pagination + per-column filter (same on-demand-
// apply pattern as the other tables on this page) ───────────────────────────
<?php if (!empty($store_opening_sites)): ?>
$(document).ready(function () {
    var soTable = $('#storeOpeningTable').DataTable({
        pageLength: 25,
        lengthChange: false,
        orderCellsTop: true,
        order: [],
        dom: 'tip'
    });

    function soApplyFilters() {
        $('.so-col-search').each(function() {
            soTable.column($(this).attr('data-socol')).search(this.value);
        });
        soTable.draw();
    }

    $('.so-col-search').on('keydown', function(e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        soApplyFilters();
    });

    $('#storeOpeningTable thead input').on('click', function(e) { e.stopPropagation(); });
});
<?php endif; ?>
</script>
<?php endif; // $store_opening_groups ?>

<?php if ($is_category_manager): ?>
<!-- ⚠ Manual Question Set Assignment modal ─────────────────────────────────── -->
<div id="assign-modal" style="display:none" class="fixed inset-0 z-50 items-center justify-center">
    <div class="absolute inset-0 bg-black/40" onclick="closeAssignModal()"></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-lg mx-4 z-10">
        <div class="px-5 py-4 border-b border-amber-100 bg-amber-50 rounded-t-xl flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-bold text-amber-800">Assign Question Set</h2>
                <p class="text-xs text-amber-600 mt-0.5">Choose a taxonomy-specific evaluation form for this purchase order.</p>
            </div>
            <button type="button" onclick="closeAssignModal()" class="text-amber-400 hover:text-red-600 font-bold text-xl leading-none mt-0.5 transition">&#x2715;</button>
        </div>
        <form method="POST" action="vendor_eval_v2_assign_set.php">
            <input type="hidden" name="po_number" id="assign-po-input">
            <div class="p-5 space-y-4">
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-3">
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">PO Number</dt>
                        <dd id="assign-po" class="font-mono font-bold text-indigo-700 text-sm"></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Vendor</dt>
                        <dd id="assign-vendor" class="text-slate-800 text-sm"></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Sub Category</dt>
                        <dd id="assign-l2" class="text-slate-700 text-sm"></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Commodity</dt>
                        <dd id="assign-l3" class="text-slate-700 text-sm"></dd>
                    </div>
                    <div>
                        <dt class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Resolved Status</dt>
                        <dd><span class="inline-block px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800">Unmapped</span></dd>
                    </div>
                </dl>
                <div>
                    <label for="assign-set-select" class="block text-xs font-bold text-slate-600 mb-1">Question Set <span class="text-red-500">*</span></label>
                    <select id="assign-set-select" name="question_set_id" required style="width:100%">
                        <option value="">Select a question set…</option>
                        <?php foreach ($assignable_sets as $s): ?>
                        <option value="<?= (int)$s['id'] ?>"><?= h((string)$s['set_code'] . ' — ' . (string)$s['set_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="px-5 py-4 border-t border-slate-100 flex justify-end gap-2">
                <button type="button" onclick="closeAssignModal()" class="bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold px-4 py-2 rounded-lg transition">Cancel</button>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg transition">Save</button>
            </div>
        </form>
    </div>
</div>
<script>
function openAssignModal(btn) {
    document.getElementById('assign-po').textContent     = btn.dataset.po || '';
    document.getElementById('assign-po-input').value     = btn.dataset.po || '';
    document.getElementById('assign-vendor').textContent = btn.dataset.vendor || '—';
    document.getElementById('assign-l2').textContent     = btn.dataset.l2 || '—';
    document.getElementById('assign-l3').textContent     = btn.dataset.l3 || '—';
    $('#assign-set-select').val('').trigger('change');
    document.getElementById('assign-modal').style.display = 'flex';
}
function closeAssignModal() {
    document.getElementById('assign-modal').style.display = 'none';
}
$(function () {
    $('#assign-set-select').select2({
        dropdownParent: $('#assign-modal'),
        placeholder: 'Select a question set…',
        width: '100%'
    });
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAssignModal();
});

// ── Manual queue: pagination + per-column filter (same DataTables pattern as
// the PO Evaluation Worklist table below) ─────────────────────────────────────
<?php if (!empty($manual_queue)): ?>
$(document).ready(function () {
    var mqTable = $('#manualQueueTable').DataTable({
        pageLength: 25,
        lengthChange: false,
        orderCellsTop: true,
        order: [[0, 'asc']],
        dom: 'tip'
    });
    $('.mq-col-search').on('keyup change clear', function (e) {
        e.stopPropagation();
        var colIdx = $(this).attr('data-mqcol');
        mqTable.column(colIdx).search(this.value).draw();
    });
    $('#manualQueueTable thead input').on('click', function (e) { e.stopPropagation(); });
});
<?php endif; ?>
</script>
<?php endif; ?>

<script>
// ── v2 filter state — shared by the filter form, the grade-summary cards and the
// donut chart's onClick. All three just mutate veFilters and call
// evalGradeTable.ajax.reload(); nothing here ever navigates the page. Seeded from
// the GET params so a direct/bookmarked link like "?grade=A" still pre-filters
// on first load — after that, filtering never touches the URL again.
var veFilters = <?= json_encode([
    'vendor'    => $f_vendor,
    'l2'        => $f_l2,
    'l3'        => $f_l3,
    'grade'     => $f_grade,
    'date_from' => $f_date_from,
    'date_to'   => $f_date_to,
]) ?>;
var evalGradeTable; // assigned inside its own $(document).ready() below.

function readVeFilterInputsIntoState() {
    veFilters = {
        vendor:    $('#ve_f_vendor').val() || '',
        l2:        $('#ve_f_l2').val() || '',
        l3:        $('#ve_f_l3').val() || '',
        grade:     $('#ve_f_grade').val() || '',
        date_from: $('#ve_f_date_from').val() || '',
        date_to:   $('#ve_f_date_to').val() || ''
    };
}
function syncVeFilterInputsFromState() {
    $('#ve_f_vendor').val(veFilters.vendor || '');
    $('#ve_f_l2').val(veFilters.l2 || '');
    $('#ve_f_l3').val(veFilters.l3 || '');
    $('#ve_f_grade').val(veFilters.grade || '');
    $('#ve_f_date_from').val(veFilters.date_from || '');
    $('#ve_f_date_to').val(veFilters.date_to || '');
}

var VE_GRADE_ACTIVE_CLASS = {
    A: 'border-green-400 bg-green-50 ring-2 ring-green-300',
    B: 'border-blue-400 bg-blue-50 ring-2 ring-blue-300',
    C: 'border-yellow-400 bg-yellow-50 ring-2 ring-yellow-300',
    D: 'border-red-400 bg-red-50 ring-2 ring-red-300'
};
var VE_GRADE_ALL_ACTIVE_CLASSES = Object.keys(VE_GRADE_ACTIVE_CLASS)
    .map(function (k) { return VE_GRADE_ACTIVE_CLASS[k]; })
    .join(' ');

function updateVeGradeCardHighlight() {
    $('.ve-grade-card').removeClass(VE_GRADE_ALL_ACTIVE_CLASSES).addClass('border-slate-200');
    if (veFilters.grade && VE_GRADE_ACTIVE_CLASS[veFilters.grade]) {
        $('.ve-grade-card[data-grade="' + veFilters.grade + '"]')
            .removeClass('border-slate-200')
            .addClass(VE_GRADE_ACTIVE_CLASS[veFilters.grade]);
    }
}
function updateVeFilteredBadge() {
    var active = !!(veFilters.vendor || veFilters.l2 || veFilters.l3 || veFilters.grade || veFilters.date_from || veFilters.date_to);
    $('#evalGradeFilteredBadge').toggleClass('hidden', !active);
}
function applyVeFilters() {
    readVeFilterInputsIntoState();
    updateVeGradeCardHighlight();
    updateVeFilteredBadge();
    if (evalGradeTable) evalGradeTable.ajax.reload();
}
function clearVeFilters() {
    veFilters = { vendor: '', l2: '', l3: '', grade: '', date_from: '', date_to: '' };
    syncVeFilterInputsFromState();
    updateVeGradeCardHighlight();
    updateVeFilteredBadge();
    if (evalGradeTable) evalGradeTable.ajax.reload();
}

$(document).ready(function () {
    updateVeGradeCardHighlight();
    updateVeFilteredBadge();
    $('#ve-apply-filter').on('click', applyVeFilters);
    $('#ve-clear-filter').on('click', clearVeFilters);
    $(document).on('click', '.ve-grade-card', function () {
        veFilters.grade = $(this).attr('data-grade') || '';
        syncVeFilterInputsFromState();
        updateVeGradeCardHighlight();
        updateVeFilteredBadge();
        if (evalGradeTable) evalGradeTable.ajax.reload();
    });
});

// ── v2 grade pie chart ────────────────────────────────────────────────────────
<?php if ($total_groups > 0): ?>
$(document).ready(function() {
    var gradeCtx = document.getElementById('gradeDistChart').getContext('2d');
    var gradeChart = new Chart(gradeCtx, {
        type: 'pie',
        data: {
            labels: ['Grade A', 'Grade B', 'Grade C', 'Grade D'],
            datasets: [{
                data: [<?= (int)$grade_dist['A'] ?>, <?= (int)$grade_dist['B'] ?>, <?= (int)$grade_dist['C'] ?>, <?= (int)$grade_dist['D'] ?>],
                backgroundColor: ['#22c55e', '#3b82f6', '#eab308', '#ef4444'],
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        boxWidth: 12,
                        font: { family: "'Inter', sans-serif", size: 11, weight: '500' },
                        color: '#374151'
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            var total = context.dataset.data.reduce(function(a, b) { return a + b; }, 0);
                            var val = context.raw;
                            var pct = total > 0 ? ((val / total) * 100).toFixed(1) : 0;
                            return ' ' + context.label + ': ' + val + ' groups (' + pct + '%)';
                        }
                    }
                }
            },
            onClick: function(evt, activeElements) {
                if (activeElements.length > 0) {
                    var grades = ['A', 'B', 'C', 'D'];
                    veFilters.grade = grades[activeElements[0].index];
                    syncVeFilterInputsFromState();
                    updateVeGradeCardHighlight();
                    updateVeFilteredBadge();
                    if (evalGradeTable) evalGradeTable.ajax.reload();
                }
            }
        }
    });
});
<?php endif; ?>

// ── v2 Evaluation Grade Results table: server-side pagination + per-column filter ──
// Escaping helpers used by every render() below. esc() is for element text/HTML
// content; escAttr() additionally escapes quotes, since esc()'s textContent→
// innerHTML round trip does NOT escape " or ' (only &, < and >) and several
// render()s below interpolate values into double-quoted HTML attributes.
function esc(str) {
    var d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
}
function escAttr(str) {
    return esc(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}
// "YYYY-MM-DD" -> "d M Y", from the string parts directly (no Date object, so
// no local-timezone rollback risk). Shared by evalGradeTable's GR Date column
// and #v1MonitorTable's Delivery/GR Date columns below.
function fmtDMY(s) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
    if (!m) return '-';
    var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    return m[3] + ' ' + months[parseInt(m[2], 10) - 1] + ' ' + m[1];
}

$(document).ready(function () {
    var egColFilters = {};

    evalGradeTable = $('#evalGradeTable').DataTable({
        serverSide: true,
        processing: true,
        pageLength: 25,
        lengthChange: false,
        orderCellsTop: true,
        order: [[0, 'asc']],
        dom: 'tip',
        language: { emptyTable: 'No Vendor Eval v2 submissions found.' },
        ajax: {
            url: 'ajax_vendor_eval_v2_table.php',
            type: 'POST',
            data: function (d) {
                d.gf = JSON.stringify(veFilters);
                d.colFilters = JSON.stringify(egColFilters);
            },
            error: function () { $('.viz-v2-table-loader').fadeOut(200); }
        },
        initComplete: function () { $('.viz-v2-table-loader').fadeOut(200); },
        columns: [
            { data: 'vendor', className: 'px-4 py-3 font-medium text-slate-800',
              render: function (d, type) { return type === 'display' ? esc(d || '—') : d; } },
            {
                data: null, className: 'px-4 py-3 text-slate-600',
                render: function (d, type, row) {
                    var l2 = row.l2 || '', l3 = row.l3 || '';
                    if (type !== 'display') return (l2 + ' ' + l3).trim();
                    var tax = (l2 && l3) ? (l2 + ' → ' + l3) : (l2 || l3 || '—');
                    return esc(tax);
                }
            },
            { data: 'poCount', className: 'px-4 py-3 text-center font-semibold text-slate-700' },
            { data: 'avgScore', className: 'px-4 py-3 text-center font-semibold text-slate-700',
              render: function (d, type) { return type === 'display' ? Number(d).toFixed(1) : d; } },
            { data: 'grade', className: 'px-4 py-3 text-center',
              render: function (d, type) {
                  if (type !== 'display') return d;
                  var gc = GRADE_CLASSES[d] || GRADE_CLASSES['D'];
                  return '<span class="inline-block px-3 py-1 rounded-full text-xs font-bold ' + gc + '">' + esc(d) + '</span>';
              } },
            { data: 'requestorCount', className: 'px-4 py-3 text-center text-slate-600' },
            { data: 'businessUnits', className: 'px-4 py-3 text-slate-600',
              render: function (d, type) { return type === 'display' ? esc(d || '—') : (d || ''); } },
            { data: 'grDate', className: 'px-4 py-3 text-center text-slate-600',
              render: function (d, type) {
                  if (type !== 'display') return d || '';
                  return d ? fmtDMY(d) : '<span class="text-slate-400 italic">Pending</span>';
              } },
            {
                data: null, orderable: false, searchable: false, className: 'px-4 py-3 text-center',
                render: function (d, type, row) {
                    if (type !== 'display') return '';
                    return '<button class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold transition" ' +
                        'data-vendor="' + escAttr(row.vendor || '') + '" ' +
                        'data-l2="' + escAttr(row.l2 || '') + '" ' +
                        'data-l3="' + escAttr(row.l3 || '') + '" ' +
                        'data-last-submitted="' + escAttr(row.lastSubmittedAt || '') + '" ' +
                        'onclick="openEvalModal(this)">View</button>';
                }
            }
        ]
    });

    evalGradeTable.on('xhr.dt', function (e, settings, json) {
        var count = (json && typeof json.recordsFiltered === 'number') ? json.recordsFiltered : 0;
        document.getElementById('evalGradeResultCount').textContent = count;
        document.getElementById('evalGradeResultPlural').textContent = count === 1 ? '' : 's';
    });
    evalGradeTable.on('error.dt', function () { $('.viz-v2-table-loader').fadeOut(200); });

    // Same on-demand-apply pattern as #v1MonitorTable's .v1-col-search: one
    // ajax.reload() per Apply click / Enter, not per keystroke.
    function egApplyFilters() {
        egColFilters = {};
        $('.eg-col-search').each(function () {
            var col = $(this).attr('data-egcol');
            var val = this.value;
            if (val !== '') egColFilters[col] = val;
        });
        evalGradeTable.ajax.reload();
    }

    $('#eg-apply-filter').on('click', egApplyFilters);

    $('#eg-clear-filter').on('click', function () {
        $('.eg-col-search').val('');
        egApplyFilters();
    });

    // Downloads the currently filtered Evaluation Grade table as XLSX — same
    // gf/colFilters the table itself is showing, so the export matches the
    // screen exactly (Content-Disposition: attachment means this doesn't
    // navigate away from the SPA-style tab view).
    $('#eg-export-btn').on('click', function () {
        window.location.href = 'export_vendor_eval_v2_results.php?' + $.param({
            gf:         JSON.stringify(veFilters),
            colFilters: JSON.stringify(egColFilters)
        });
    });

    $('.eg-col-search').on('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        egApplyFilters();
    });

    $('#evalGradeTable thead input').on('click', function (e) { e.stopPropagation(); });
});

// ── v1 PO Worklist table: server-side pagination + status/column filters ────────
$(document).ready(function () {
    var v1ActiveStatus = '';
    var v1ColFilters    = {};
    // Matches PHP's V1_MULTI_FILTER_SEP (includes/vendor_eval_v2_results_helper.php)
    // — joins a multi-select's several chosen values into the one string
    // v1ColFilters[col] holds, for the server to split back apart and OR-match.
    var V1_MULTI_FILTER_SEP = '\u001F';

    var v1StatusDot = {
        'Need Evaluation':     'bg-red-500',
        'Partially Evaluated': 'bg-orange-400',
        'Evaluated':           'bg-green-500',
        'Waiting for GR':      'bg-yellow-400'
    };

    // deliveryDate/grDate arrive as plain "YYYY-MM-DD" strings; formatted from the
    // string parts directly (no Date object) so no local-timezone rollback risk.
    function fmtDMY(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
        if (!m) return '-';
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        return m[3] + ' ' + months[parseInt(m[2], 10) - 1] + ' ' + m[1];
    }

    var v1Table = $('#v1MonitorTable').DataTable({
        serverSide: true,
        processing: true,
        pageLength: 25,
        lengthChange: false,
        orderCellsTop: true,
        order: [[0, 'asc']],
        dom: 'tip',
        language: { emptyTable: 'No PO records found.' },
        ajax: {
            url: 'ajax_vendor_eval_v1_table.php',
            type: 'POST',
            data: function (d) {
                d.status     = v1ActiveStatus;
                d.colFilters = JSON.stringify(v1ColFilters);
            },
            error: function () { $('.viz-v1-table-loader').fadeOut(200); }
        },
        initComplete: function () { $('.viz-v1-table-loader').fadeOut(200); },
        columns: [
            {
                data: null, orderable: true, searchable: false, className: 'px-4 py-3 text-center',
                render: function (d, type, row) {
                    if (type !== 'display') return row.status || '';
                    var dot = '<span class="h-3 w-3 rounded-full ' + (v1StatusDot[row.status] || 'bg-gray-400') +
                              ' inline-block shadow-sm" title="' + escAttr(row.status || '') + '"></span>';
                    if (row.status === 'Evaluated' || row.status === 'Partially Evaluated') {
                        return '<a href="eval_detail.php?po=' + encodeURIComponent(row.poNumber || '') + '" class="group flex items-center space-x-2">' +
                               dot + '<span class="text-xs font-bold text-indigo-600 underline group-hover:text-indigo-900">View</span></a>';
                    }
                    return dot;
                }
            },
            { data: 'poNumber', className: 'px-4 py-3 text-sm font-mono font-bold text-indigo-700',
              render: function (d, type) { return type === 'display' ? esc(d || '') : d; } },
            { data: 'avgScore', className: 'px-4 py-3 text-sm font-bold',
              render: function (d, type) {
                  if (type !== 'display') return d === null ? '' : d;
                  var cls = d ? 'text-green-700' : 'text-gray-400';
                  var txt = d ? Number(d).toFixed(2) : '--';
                  return '<span class="' + cls + '">' + txt + '</span>';
              } },
            { data: 'otfScore', className: 'px-4 py-3 text-xs text-center',
              render: function (d, type, row) {
                  if (type !== 'display') return d === null ? '' : d;
                  var title = escAttr(row.otfStatus || (d ? '' : 'Pending'));
                  if (d === 3) return '<span class="bg-green-100 text-green-800 font-bold px-2 py-1 rounded" title="' + title + '">3</span>';
                  if (d === 2) return '<span class="bg-yellow-100 text-yellow-800 font-bold px-2 py-1 rounded" title="' + title + '">2</span>';
                  if (d === 1) return '<span class="bg-red-100 text-red-800 font-bold px-2 py-1 rounded" title="' + title + '">1</span>';
                  return '<span class="text-gray-400" title="' + title + '">--</span>';
              } },
            { data: 'topScore', className: 'px-4 py-3 text-xs text-center',
              render: function (d, type, row) {
                  if (type !== 'display') return d === null ? '' : d;
                  var title = 'TOP: ' + escAttr(row.vendorTop || (d ? '' : 'Unassigned'));
                  if (d === 3) return '<span class="bg-green-100 text-green-800 font-bold px-2 py-1 rounded" title="' + title + '">3</span>';
                  if (d === 2) return '<span class="bg-yellow-100 text-yellow-800 font-bold px-2 py-1 rounded" title="' + title + '">2</span>';
                  if (d === 1) return '<span class="bg-red-100 text-red-800 font-bold px-2 py-1 rounded" title="' + title + '">1</span>';
                  return '<span class="text-gray-400" title="' + title + '">--</span>';
              } },
            { data: 'finalScore', className: 'px-4 py-3 text-sm text-center font-bold bg-indigo-50 text-indigo-700',
              render: function (d, type) {
                  if (type !== 'display') return d === null ? '' : d;
                  return d !== null ? Number(d).toFixed(2) : '<span class="text-gray-400 text-xs">Inc. Data</span>';
              } },
            { data: 'allRequestors', className: 'px-4 py-3 text-xs text-gray-700',
              render: function (d, type, row) {
                  if (type !== 'display') return d || '';
                  return esc(d || '') + '<br><span class="text-indigo-500 font-bold">(' + row.evalCount + '/' + row.totalRequestors + ' Done)</span>';
              } },
            { data: 'vendorName', className: 'px-4 py-3 text-sm text-gray-800 font-semibold',
              render: function (d, type, row) {
                  if (type !== 'display') return d || '';
                  var html = esc(d || '');
                  if (!row.mvId) {
                      html += '<div class="mt-1 text-[10px] text-red-600 bg-red-100 inline-block px-1 rounded font-bold uppercase tracking-wider" title="Vendor not found in Master Vendor Data">⚠ Unmatched</div>';
                  }
                  return html;
              } },
            { data: 'deliveryDate', className: 'px-4 py-3 text-sm text-gray-600',
              render: function (d, type) { return type === 'display' ? fmtDMY(d) : (d || ''); } },
            { data: 'grDate', className: 'px-4 py-3 text-sm text-gray-600',
              render: function (d, type) { return type === 'display' ? fmtDMY(d) : (d || ''); } }
        ]
    });

    $('.v1-legend-btn').on('click', function (e) {
        e.preventDefault();
        v1ActiveStatus = $(this).attr('data-v1status') || '';
        $('.v1-legend-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400').css('opacity', '');
        if (v1ActiveStatus) {
            $('.v1-legend-btn').css('opacity', '0.5');
            $(this).addClass('ring-2 ring-offset-2 ring-indigo-400').css('opacity', '1');
        }
        v1Table.ajax.reload();
    });

    // Column filters are applied on demand, not per keystroke: one reload() re-fetches
    // and re-renders the page, and a user typically fills more than one box before
    // they want a result. Every box is read on each apply, so filling several and
    // hitting Apply once gives the same result as applying them one at a time.
    function v1ApplyFilters() {
        v1ColFilters = {};
        $('.v1-col-search').each(function () {
            var col = $(this).attr('data-v1col');
            var val;
            if (this.multiple) {
                // A multi-select's several chosen values are joined into one
                // string with V1_MULTI_FILTER_SEP — v1_worklist_filter_rows()
                // (includes/vendor_eval_v2_results_helper.php) splits it back
                // apart server-side and OR-matches any one of them.
                val = $.map(this.selectedOptions, function (o) { return o.value; }).join(V1_MULTI_FILTER_SEP);
            } else {
                val = this.value;
            }
            if (val !== '') v1ColFilters[col] = val;
        });
        v1Table.ajax.reload();
    }

    $('#v1-apply-filter').on('click', v1ApplyFilters);

    // Business Unit selection count badge — purely cosmetic feedback for the
    // multi-select (native <select multiple> gives no visible cue otherwise).
    function v1UpdateBuCount() {
        var n = document.getElementById('v1-bu-filter').selectedOptions.length;
        var $badge = $('#v1-bu-count');
        if (n > 0) {
            $badge.text(n + ' selected').removeClass('hidden');
        } else {
            $badge.addClass('hidden');
        }
    }
    $('#v1-bu-filter').on('change', v1UpdateBuCount);

    // The one Clear for everything: status cards, Business Unit dropdown and
    // the per-column table boxes (no separate "Reset (Show All)" anymore —
    // it did the same thing as this, just for status alone).
    $('#v1-clear-filter').on('click', function () {
        $('.v1-col-search').each(function () {
            if (this.multiple) {
                $(this).find('option').prop('selected', false);
            } else {
                this.value = '';
            }
        });
        v1UpdateBuCount();
        v1ActiveStatus = '';
        $('.v1-legend-btn').removeClass('ring-2 ring-offset-2 ring-indigo-400').css('opacity', '');
        v1ApplyFilters();
    });

    // Downloads the currently filtered PO Evaluation Worklist as XLSX — same
    // status/colFilters the table itself is showing, so the export matches
    // the screen exactly (Content-Disposition: attachment means this doesn't
    // navigate away from the SPA-style tab view).
    $('#v1-export-btn').on('click', function () {
        window.location.href = 'export_vendor_eval_v1_worklist.php?' + $.param({
            status:     v1ActiveStatus,
            colFilters: JSON.stringify(v1ColFilters)
        });
    });

    // Enter in any filter box applies them all (and never submits a form).
    $('.v1-col-search').on('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        v1ApplyFilters();
    });

    $('#v1MonitorTable thead input').on('click', function (e) { e.stopPropagation(); });

    v1Table.on('error.dt', function () { $('.viz-v1-table-loader').fadeOut(200); });
});

// ── v2 Submission detail modal ────────────────────────────────────────────────
const GRADE_CLASSES = {
    A: 'bg-green-100 text-green-800',
    B: 'bg-blue-100 text-blue-800',
    C: 'bg-yellow-100 text-yellow-800',
    D: 'bg-red-100 text-red-800'
};

// Render one submission as a card: summary header + answers grouped into
// Identification, Evaluation (scored) and Kesimpulan / Notes sections.
// Identification and text answers are display-only and never affect the score.
function renderSubmissionCard(r) {
    var gc      = GRADE_CLASSES[r.grade] || GRADE_CLASSES['D'];
    var answers = r.answers || [];
    var idents  = answers.filter(function(a){ return a.section === 'identification'; });
    var evals   = answers.filter(function(a){ return a.section === 'evaluation'; });
    var texts   = answers.filter(function(a){ return a.section === 'text'; });

    var h = '<div class="border border-slate-200 rounded-lg overflow-hidden">';

    // Header
    h += '<div class="flex flex-wrap items-center justify-between gap-2 bg-slate-50 px-4 py-3 border-b border-slate-200">';
    h += '<div class="min-w-0">';
    h += '<div class="font-mono text-xs font-bold text-indigo-700">' + esc(r.po) + '</div>';
    h += '<div class="text-xs text-slate-500">' + esc(r.requestor) + ' · ' + esc(r.submitted_at) + '</div>';
    h += '</div>';
    h += '<div class="flex items-center gap-2">';
    h += '<span class="text-sm font-bold text-slate-700">' + esc(r.score) + '<span class="text-xs font-normal text-slate-400">/4.0</span></span>';
    h += '<span class="inline-block px-3 py-1 rounded-full text-xs font-bold ' + gc + '">' + esc(r.grade) + '</span>';
    h += '</div></div>';

    h += '<div class="p-4 space-y-4">';

    // Identification (no score, no rubric)
    if (idents.length) {
        h += '<div>';
        h += '<p class="text-[11px] font-bold text-amber-600 uppercase tracking-wider mb-2">Identification</p>';
        h += '<dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-2">';
        idents.forEach(function(a) {
            h += '<div class="text-sm">';
            h += '<dt class="text-xs font-semibold text-slate-500">' + esc(a.criterion) + '</dt>';
            h += '<dd class="text-slate-800 break-words">' +
                 (a.value ? esc(a.value) : '<span class="text-slate-300 italic">—</span>') + '</dd>';
            h += '</div>';
        });
        h += '</dl></div>';
    }

    // Evaluation (scored)
    if (evals.length) {
        h += '<div>';
        h += '<p class="text-[11px] font-bold text-indigo-600 uppercase tracking-wider mb-2">Evaluation</p>';
        h += '<div class="overflow-x-auto"><table class="min-w-full text-xs">';
        h += '<thead><tr class="text-left text-slate-400 border-b border-slate-200">';
        h += '<th class="py-1.5 pr-2 font-semibold">Criterion</th>';
        h += '<th class="py-1.5 px-2 font-semibold text-center">Score</th>';
        h += '<th class="py-1.5 px-2 font-semibold text-right">Weight</th>';
        h += '<th class="py-1.5 pl-2 font-semibold">Comment</th>';
        h += '</tr></thead><tbody class="divide-y divide-slate-100">';
        evals.forEach(function(a) {
            h += '<tr>';
            h += '<td class="py-1.5 pr-2 text-slate-700">' + esc(a.criterion) + '</td>';
            h += '<td class="py-1.5 px-2 text-center font-bold text-slate-800">' +
                 (a.score !== null ? esc(a.score) + '/4' : '—') +
                 (a.label ? '<span class="block text-[10px] font-normal text-slate-400">' + esc(a.label) + '</span>' : '') + '</td>';
            h += '<td class="py-1.5 px-2 text-right text-slate-500">' + esc(a.weight_pct) + '%</td>';
            h += '<td class="py-1.5 pl-2 text-slate-500">' +
                 (a.comment ? esc(a.comment) : '<span class="text-slate-300">—</span>') + '</td>';
            h += '</tr>';
        });
        h += '</tbody></table></div></div>';
    }

    // Text / Kesimpulan
    if (texts.length) {
        h += '<div>';
        h += '<p class="text-[11px] font-bold text-purple-600 uppercase tracking-wider mb-2">Kesimpulan / Notes</p>';
        h += '<div class="space-y-2">';
        texts.forEach(function(a) {
            h += '<div class="text-sm">';
            h += '<div class="text-xs font-semibold text-slate-500">' + esc(a.criterion) + '</div>';
            h += '<div class="text-slate-700 break-words">' +
                 (a.comment ? esc(a.comment) : '<span class="text-slate-300 italic">(no response)</span>') + '</div>';
            h += '</div>';
        });
        h += '</div></div>';
    }

    if (!answers.length) {
        h += '<p class="text-xs text-slate-400 italic">Answer breakdown not available for this submission.</p>';
    }

    h += '</div></div>';
    return h;
}

function openEvalModal(btn) {
    var vendor = btn.dataset.vendor;
    var l2     = btn.dataset.l2;
    var l3     = btn.dataset.l3;
    var lastSubmitted = btn.dataset.lastSubmitted;
    var parts  = [vendor, l2, l3].filter(Boolean);

    // Show the modal immediately with a loading state — the submission list for
    // this one group is now fetched on demand (ajax_vendor_eval_v2_detail.php)
    // rather than pre-dumped for every group on page load.
    document.getElementById('modal-title').textContent = 'Submission Detail';
    document.getElementById('modal-subtitle').textContent = parts.join(' — ') + ' · Loading…';
    document.getElementById('modal-body').innerHTML =
        '<div class="flex items-center justify-center py-16">' +
        '<div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div></div>';
    document.getElementById('eval-modal').style.display = 'flex';

    // Same filter state (veFilters — date range, etc.) the table itself was
    // loaded with, so the modal never shows a submission the current filter
    // would have excluded.
    $.post('ajax_vendor_eval_v2_detail.php', { vendor: vendor, l2: l2, l3: l3, gf: JSON.stringify(veFilters) })
        .done(function (res) {
            if (!res || !res.success) {
                document.getElementById('modal-body').innerHTML =
                    '<p class="text-center text-red-500 py-10 text-sm">' +
                    esc((res && res.error) || 'Gagal memuat detail submission.') + '</p>';
                return;
            }
            var rows  = res.data || [];
            var count = rows.length;
            document.getElementById('modal-subtitle').textContent =
                parts.join(' — ') + ' · ' + count + ' submission' + (count !== 1 ? 's' : '') +
                (lastSubmitted && lastSubmitted !== '—' ? ' · Last: ' + lastSubmitted : '');

            var html;
            if (rows.length === 0) {
                html = '<p class="text-center text-slate-400 py-10 text-sm">No submissions found for this selection.</p>';
            } else {
                html = '<div class="p-4 space-y-4">';
                rows.forEach(function(r) {
                    html += renderSubmissionCard(r);
                });
                html += '</div>';
            }
            document.getElementById('modal-body').innerHTML = html;
        })
        .fail(function () {
            document.getElementById('modal-body').innerHTML =
                '<p class="text-center text-red-500 py-10 text-sm">Gagal terhubung ke server. Coba lagi.</p>';
        });
}

function closeEvalModal() {
    document.getElementById('eval-modal').style.display = 'none';
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEvalModal();
});

// ── Tab Navigation ──────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    const tabBtns = document.querySelectorAll('.tab-btn');
    const tabContents = document.querySelectorAll('.tab-content');

    tabBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const targetTab = this.getAttribute('data-tab');

            // Hide all tabs
            tabContents.forEach(content => {
                content.classList.remove('active');
                content.classList.add('hidden');
            });

            // Remove active styling from all buttons
            tabBtns.forEach(b => {
                b.classList.remove('border-indigo-600', 'text-indigo-600');
                b.classList.add('border-transparent', 'text-slate-600');
            });

            // Show active tab
            document.getElementById(targetTab).classList.remove('hidden');
            document.getElementById(targetTab).classList.add('active');

            // Add active styling to clicked button
            this.classList.remove('border-transparent', 'text-slate-600');
            this.classList.add('border-indigo-600', 'text-indigo-600');
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
