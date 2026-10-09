<?php
// workspace/pages/public_eval_v2_project_worklist.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail — New Store) shared worklist.
//
// Access model: ONE global, unscoped, no-login token (NOT tied to a site,
// PO, or individual person) shared by both the Operations team (~4 people)
// and Project team (~10 people) — see vendor_eval_v2_form2_worklist_token.php.
// There is no per-requestor identity check on this page or on the team
// engine behind it (vendor_eval_v2_form2_team_engine.php); which team a
// submission belongs to is purely which button the filler clicked.
//
// The list is NOT scoped to a site or PO and is NOT cached: every load
// re-runs the full Form 2 eligibility gate (PGroup pattern + is_new_store +
// arrived Store Opening Date, MAX-date rule for multi-site POs) across
// every candidate PO, live. This means the list naturally grows and shrinks
// as more sites open — no link needs to be reissued when a new site becomes
// eligible.
//
// Read tables : vendor_eval_v2_form2_worklist_token, prs (candidate source +
//               GR Date + P Group + eligibility gate), vendor_eval_submissions,
//               vendor_eval_store_opening_dates (via eligibility gate),
//               pgroup_business_unit_mappings (Business Unit filter, joined
//               via p_group — same join used by vendor_eval_v2_results.php).
// Write tables: NONE.
//
// Safety invariants:
//   • No auth.php / session / login required.
//   • Never writes to DB.
//   • No WA / email / Gemini / external API calls.
//   • Never touches vendor_evaluations (v1) or v1 pages.
//   • All DB access uses prepared statements (PDO).
//   • All output goes through h() (htmlspecialchars).
require_once __DIR__ . '/../../includes/branding.php';

date_default_timezone_set('Asia/Jakarta');

// Never let the browser (or its back/forward cache) serve a stale snapshot of
// this worklist — submission/lock state must always be re-fetched live, even
// on back-button navigation.
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

require_once '../config/database.php'; // provides $pdo (PDO)
require_once '../includes/vendor_eval_v2_form2_worklist_token.php';
require_once '../includes/vendor_eval_v2_form2_team_engine.php'; // ve2_form2_final_grade(), ve2_form2_team_label()
require_once '../includes/vendor_eval_v2_item_category.php'; // ve2_classify_item_category(), ve2_item_category_overrides()

const WL2_TOKEN_MAX = 64;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function blockPage(string $title, string $message, string $bar_class = 'bg-indigo-600'): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <?= vss_favicon_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-10 px-4">
<div class="max-w-lg w-full bg-white rounded-lg shadow-xl overflow-hidden">
    <div class="<?= h($bar_class) ?> px-6 py-5">
        <h2 class="text-xl font-bold text-white"><?= h($title) ?></h2>
    </div>
    <div class="p-6 text-gray-700 text-sm leading-relaxed"><?= h($message) ?></div>
</div>
</body>
</html><?php
    exit;
}

// ── Access gate: single global token ─────────────────────────────────────
$raw_token = trim((string)($_GET['token'] ?? ''));
if ($raw_token === '' || strlen($raw_token) > WL2_TOKEN_MAX) {
    blockPage(
        'Invalid Worklist Link',
        'This worklist link is invalid. Please use the link provided to you.',
        'bg-red-600'
    );
}
if (!ve2_form2_worklist_token_validate($pdo, $raw_token)) {
    blockPage(
        'Invalid Worklist Link',
        'This worklist link is not recognised or has been rotated. Please contact the procurement team for the current link.',
        'bg-red-600'
    );
}

// ── Candidate POs: sourced directly from prs — PGroup pattern + is_new_store
//    = 1, the SAME source ve2_form2_po_eligibility() itself uses — then
//    filtered live through that full eligibility gate. This is intentionally
//    NOT gated on vendor_eval_po_taxonomy: that table requires gr_date to
//    ever get a row (see run_po_taxonomy_mapping.php / the master_data_id
//    refresh tool), so a fresh pre-GR construction PO can never earn one —
//    gating Form 2's candidate list on it would permanently hide exactly
//    the New Store POs Form 2 exists to evaluate. The candidate query below
//    is deliberately loose (any line matching scope); the full per-line
//    checks (mixed p_group, mixed is_new_store, per-site opening dates) are
//    re-verified by ve2_form2_po_eligibility() for every candidate before
//    it's listed. Mirrors Form 1's worklist pattern of fetching candidates
//    and filtering in PHP, just from a different candidate source. ────────
$po_stmt = $pdo->query("
    SELECT p.po_number, MAX(p.vendor_name) AS vendor_name, MAX(p.gr_date) AS gr_date,
           MAX(p.p_group) AS p_group
      FROM prs p
     WHERE p.po_number IS NOT NULL AND p.po_number != ''
       AND p.is_new_store = 1
       AND (p.p_group = 'A77' OR p.p_group REGEXP '^[A-Z]97$')
     GROUP BY p.po_number
     ORDER BY p.po_number
");
$candidates = $po_stmt->fetchAll(PDO::FETCH_ASSOC);

$eligible_rows = [];
foreach ($candidates as $c) {
    $elig = ve2_form2_po_eligibility($pdo, (string)$c['po_number']);
    if ($elig['eligible']) {
        $c['sites']              = array_keys($elig['sites']); // distinct site_allocation values on this PO
        $c['store_opening_date'] = $elig['max_open_date'];       // MAX across sites — the date that completed eligibility
        $eligible_rows[(string)$c['po_number']] = $c;
    }
}

// Business Unit, via the same p_group -> pgroup_business_unit_mappings join
// used by vendor_eval_v2_results.php — bulk-fetched for just the p_groups
// present among eligible POs (not the whole ~500-row mapping table).
$bu_by_pgroup = [];
if (!empty($eligible_rows)) {
    $pgroups = array_values(array_unique(array_filter(array_column($eligible_rows, 'p_group'))));
    if (!empty($pgroups)) {
        $ph = implode(',', array_fill(0, count($pgroups), '?'));
        $bu_stmt = $pdo->prepare("
            SELECT p_group, business_unit_name
              FROM pgroup_business_unit_mappings
             WHERE p_group IN ($ph)
        ");
        $bu_stmt->execute($pgroups);
        $bu_by_pgroup = $bu_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
}

// ── Submission state for every eligible PO, one query (no N+1) ──────────
$subs_by_po = [];
if (!empty($eligible_rows)) {
    $po_list = array_keys($eligible_rows);
    $ph = implode(',', array_fill(0, count($po_list), '?'));
    $sub_stmt = $pdo->prepare("
        SELECT po_number, team, total_score, score_band, submitted_at
          FROM vendor_eval_submissions
         WHERE po_number IN ($ph) AND team IN ('ops','project') AND status = 'submitted'
    ");
    $sub_stmt->execute($po_list);
    foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $subs_by_po[$row['po_number']][$row['team']] = $row;
    }
}

// ── Line-item descriptions for every eligible PO, one query (no N+1) ────
// Lets a user see what was actually purchased before deciding whether to
// fill the evaluation (service work vs goods). Source is prs directly, NOT
// vendor_eval_po_taxonomy.item_descriptions_json: that column only exists
// for POs that earned a taxonomy row, which needs an arrived gr_date, which
// pre-GR construction POs don't have — the same reason the candidate query
// above doesn't gate on that table either.
// One row per prs line (NOT deduplicated by description): a PO can cover many
// sites with one line each, so the same description legitimately recurs with
// its own qty and amount. po_amount is a per-line figure, so the totals below
// are meaningful. All rows in scope are IDR.
$items_by_po  = [];
$item_totals  = [];
if (!empty($eligible_rows)) {
    $po_list = array_keys($eligible_rows);
    $ph = implode(',', array_fill(0, count($po_list), '?'));
    $item_stmt = $pdo->prepare("
        SELECT po_number, item_description, qty, uom, po_amount
          FROM prs
         WHERE po_number IN ($ph)
           AND item_description IS NOT NULL AND item_description != ''
         ORDER BY po_number, ticket_no, ticket_line_item
    ");
    $item_stmt->execute($po_list);
    foreach ($item_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $desc = trim((string)$row['item_description']);
        if ($desc === '') {
            continue;
        }
        $po_key = (string)$row['po_number'];
        $amount = (float)$row['po_amount'];
        // Short keys keep the inlined payload small; expanded in the modal JS.
        $items_by_po[$po_key][] = [
            'd' => $desc,
            // 9.00 -> 9, 585597.10 -> 585,597.1
            'q' => rtrim(rtrim(number_format((float)$row['qty'], 2), '0'), '.'),
            'u' => trim((string)$row['uom']),
            'a' => number_format($amount, 0),
        ];
        $item_totals[$po_key] = ($item_totals[$po_key] ?? 0.0) + $amount;
    }
    foreach ($item_totals as $po_key => $sum) {
        $item_totals[$po_key] = number_format($sum, 0);
    }
}

// ── Barang/Jasa category: live keyword guess from the item descriptions
// already fetched above, overridden by a human's manual pick when one
// exists (vendor_eval_po_item_category — see includes/vendor_eval_v2_item_category.php).
// A manual override always wins over the live guess for that po_number.
$category_overrides = ve2_item_category_overrides($pdo, array_keys($eligible_rows));

$display_rows = [];
foreach ($eligible_rows as $po_num => $row) {
    $ops     = $subs_by_po[$po_num]['ops']     ?? null;
    $project = $subs_by_po[$po_num]['project'] ?? null;

    $live = ve2_classify_item_category(array_column($items_by_po[$po_num] ?? [], 'd'));
    $override = $category_overrides[$po_num] ?? null;

    $display_rows[] = [
        'po_number' => $po_num,
        'vendor_name' => $row['vendor_name'],
        'sites' => $row['sites'],
        'store_opening_date' => $row['store_opening_date'],
        'gr_date' => $row['gr_date'],
        'business_unit' => $bu_by_pgroup[$row['p_group']] ?? null,
        'items' => $items_by_po[$po_num] ?? [],
        'ops' => $ops,
        'project' => $project,
        'final' => ve2_form2_final_grade($ops, $project),
        'category'        => $override['category'] ?? $live['category'],
        'category_source' => $override !== null ? 'manual' : 'auto',
        'category_mixed'  => $live['mixed'],
    ];
}

$count_total    = count($display_rows);
$count_complete = count(array_filter($display_rows, fn($r) => $r['final'] !== null));
$count_pending  = $count_total - $count_complete;

// Options for the Business Unit filter — only BUs actually present among
// this page's eligible POs, not the full mapping table.
$bu_options = array_values(array_unique(array_filter(array_column($display_rows, 'business_unit'))));
sort($bu_options);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Retail — New Store Evaluation Worklist</title>
    <?= vss_favicon_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen py-10 px-4">
<div class="max-w-6xl mx-auto space-y-6">

    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="bg-indigo-700 px-6 py-5">
            <h1 class="text-2xl font-bold text-white">Project Retail — New Store Evaluation Worklist</h1>
            <p class="text-indigo-200 mt-1">
                Each PO needs one evaluation from each team.
            </p>
        </div>
        <div class="p-6 border-b border-gray-100">
            <div class="grid grid-cols-3 gap-4">
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-orange-600"><?= $count_pending ?></p>
                    <p class="text-xs font-bold text-orange-500 uppercase tracking-wider mt-1">Awaiting Both Teams</p>
                </div>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-green-600"><?= $count_complete ?></p>
                    <p class="text-xs font-bold text-green-500 uppercase tracking-wider mt-1">Completed</p>
                </div>
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-indigo-600"><?= $count_total ?></p>
                    <p class="text-xs font-bold text-indigo-400 uppercase tracking-wider mt-1">Total Eligible POs</p>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($display_rows)): ?>
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-400 text-sm">No purchase orders are currently eligible for the Project Retail — New Store evaluation. Check back once a New Store site has an arrived Store Opening Date.</p>
    </div>
    <?php else: ?>
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="p-4 border-b border-gray-100 flex flex-wrap gap-3 items-center">
            <input type="text" id="wl2Search" placeholder="Search PO Number, Vendor, or Site Allocation..."
                   class="flex-1 min-w-[240px] font-sans text-sm text-gray-700 border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400">
            <select id="wl2StatusFilter" class="font-sans text-sm text-gray-700 border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <option value="">All</option>
                <option value="pending">Awaiting Both Teams</option>
                <option value="complete">Complete</option>
            </select>
            <select id="wl2BuFilter" class="font-sans text-sm text-gray-700 border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <option value="">All Business Units</option>
                <?php foreach ($bu_options as $bu): ?>
                <option value="<?= h($bu) ?>"><?= h($bu) ?></option>
                <?php endforeach; ?>
            </select>
            <select id="wl2CategoryFilter" class="font-sans text-sm text-gray-700 border border-gray-300 rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <option value="">All Categories</option>
                <option value="barang">Barang</option>
                <option value="jasa">Jasa</option>
            </select>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm" id="wl2Table">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-center text-xs text-gray-500 uppercase tracking-wider">
                        <th class="px-4 py-3 font-bold text-center cursor-pointer select-none hover:text-gray-700" data-sort-key="po" data-sort-type="text">PO Number <span class="wl2-sort-arrow"></span></th>
                        <th class="px-4 py-3 font-bold text-center cursor-pointer select-none hover:text-gray-700" data-sort-key="vendor" data-sort-type="text">Vendor <span class="wl2-sort-arrow"></span></th>
                        <th class="px-4 py-3 font-bold text-center cursor-pointer select-none hover:text-gray-700" data-sort-key="site" data-sort-type="text">Site Allocation <span class="wl2-sort-arrow"></span></th>
                        <th class="px-4 py-3 font-bold text-center cursor-pointer select-none hover:text-gray-700" data-sort-key="opened" data-sort-type="text">Store Opening Date <span class="wl2-sort-arrow"></span></th>
                        <th class="px-4 py-3 font-bold text-center cursor-pointer select-none hover:text-gray-700" data-sort-key="gr" data-sort-type="text">GR Date <span class="wl2-sort-arrow"></span></th>
                        <th class="px-4 py-3 font-bold text-center">Operations</th>
                        <th class="px-4 py-3 font-bold text-center">Project</th>
                        <th class="px-4 py-3 font-bold text-center cursor-pointer select-none hover:text-gray-700" data-sort-key="final" data-sort-type="number">Final Grade <span class="wl2-sort-arrow"></span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100" id="wl2Body">
                    <?php foreach ($display_rows as $row):
                        $site_text     = implode(', ', $row['sites']);
                        $overall_status = $row['final'] !== null ? 'complete' : 'pending';
                    ?>
                    <tr class="hover:bg-gray-50 transition"
                        data-po="<?= h(mb_strtolower((string)$row['po_number'])) ?>"
                        data-vendor="<?= h(mb_strtolower((string)($row['vendor_name'] ?? ''))) ?>"
                        data-site="<?= h(mb_strtolower($site_text)) ?>"
                        data-status="<?= h($overall_status) ?>"
                        data-items="<?= h(mb_strtolower(implode(' | ', array_column($row['items'], 'd')))) ?>"
                        data-bu="<?= h((string)($row['business_unit'] ?? '')) ?>"
                        data-category="<?= h($row['category']) ?>">
                        <td class="px-4 py-3 font-mono font-bold text-indigo-700 align-top" data-sort-cell="po" data-sort-value="<?= h(mb_strtolower((string)$row['po_number'])) ?>">
                            <?= h((string)$row['po_number']) ?>
                            <div class="mt-1">
                                <?php
                                    $cat_cls = $row['category'] === 'jasa'
                                        ? 'bg-purple-100 text-purple-700 border-purple-200'
                                        : 'bg-teal-100 text-teal-700 border-teal-200';
                                ?>
                                <button type="button"
                                        class="wl2-cat-badge inline-flex items-center gap-1 font-sans text-[10px] font-bold uppercase tracking-wide border rounded px-1.5 py-0.5 <?= $cat_cls ?>"
                                        data-po="<?= h((string)$row['po_number']) ?>"
                                        data-category="<?= h($row['category']) ?>"
                                        title="<?= $row['category_source'] === 'manual' ? 'Manually set' : 'Auto-detected from item description' ?><?= $row['category_mixed'] ? ' — this PO has a mix of goods/services line items' : '' ?>. Click to correct.">
                                    <?= h(ucfirst($row['category'])) ?><?= $row['category_source'] === 'auto' ? ' &#9998;' : '' ?>
                                </button>
                            </div>
                            <?php if (!empty($row['items'])): $item_count = count($row['items']); ?>
                            <button type="button"
                                    class="wl2-items-btn mt-1 block font-sans font-semibold text-[11px] text-gray-500 hover:text-indigo-700 hover:underline decoration-dotted underline-offset-2 transition"
                                    data-po="<?= h((string)$row['po_number']) ?>">
                                &#128196; <?= $item_count ?> item<?= $item_count === 1 ? '' : 's' ?>
                            </button>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-gray-700 max-w-[220px]" data-sort-cell="vendor" data-sort-value="<?= h(mb_strtolower((string)($row['vendor_name'] ?? ''))) ?>">
                            <span class="wl2-expand-toggle block truncate cursor-pointer hover:text-indigo-700 hover:underline decoration-dotted underline-offset-2" title="<?= h((string)($row['vendor_name'] ?? '')) ?>"><?= h((string)($row['vendor_name'] ?? '—')) ?></span>
                        </td>
                        <td class="px-4 py-3 text-gray-600 max-w-[200px]" data-sort-cell="site" data-sort-value="<?= h(mb_strtolower($site_text)) ?>">
                            <span class="wl2-expand-toggle block truncate cursor-pointer hover:text-indigo-700 hover:underline decoration-dotted underline-offset-2" title="<?= h($site_text) ?>"><?= h($site_text) ?></span>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-600 whitespace-nowrap" data-sort-cell="opened" data-sort-value="<?= h((string)($row['store_opening_date'] ?? '')) ?>">
                            <?= h($row['store_opening_date'] !== null ? date('d M Y', strtotime($row['store_opening_date'])) : '—') ?>
                        </td>
                        <td class="px-4 py-3 text-center text-gray-600 whitespace-nowrap" data-sort-cell="gr" data-sort-value="<?= h((string)($row['gr_date'] ?? '')) ?>">
                            <?php if (!empty($row['gr_date'])): ?>
                                <?= h(date('d M Y', strtotime($row['gr_date']))) ?>
                            <?php else: ?>
                                <span class="text-gray-400 italic">Pending</span>
                            <?php endif; ?>
                        </td>
                        <?php foreach (['ops' => $row['ops'], 'project' => $row['project']] as $team_key => $sub): ?>
                        <td class="px-4 py-3 text-center">
                            <?php if ($sub !== null):
                                $band_chip = [
                                    'A' => 'text-green-700 bg-green-100', 'B' => 'text-blue-700 bg-blue-100',
                                    'C' => 'text-yellow-700 bg-yellow-100', 'D' => 'text-red-700 bg-red-100',
                                ][$sub['score_band']] ?? 'text-gray-700 bg-gray-100';
                            ?>
                            <span class="inline-flex flex-col items-center gap-0.5 text-xs font-bold text-gray-400 bg-gray-100 border border-gray-200 px-3 py-1.5 rounded">
                                Submitted
                                <span class="text-sm px-1.5 py-0.5 rounded <?= h($band_chip) ?>">
                                    <?= h(number_format((float)$sub['total_score'], 2)) ?> (<?= h((string)$sub['score_band']) ?>)
                                </span>
                            </span>
                            <?php else: ?>
                            <a href="public_eval_v2_project.php?po=<?= urlencode((string)$row['po_number']) ?>&team=<?= h($team_key) ?>&token=<?= urlencode($raw_token) ?>"
                               class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded shadow transition">
                                Fill as <?= h(ve2_form2_team_label($team_key)) ?>
                            </a>
                            <?php endif; ?>
                        </td>
                        <?php endforeach; ?>
                        <td class="px-4 py-3 text-right" data-sort-cell="final" data-sort-value="<?= h($row['final'] !== null ? (string)$row['final']['avg_score'] : '') ?>">
                            <?php if ($row['final'] !== null):
                                $fband = [
                                    'A' => 'text-green-700 bg-green-100', 'B' => 'text-blue-700 bg-blue-100',
                                    'C' => 'text-yellow-700 bg-yellow-100', 'D' => 'text-red-700 bg-red-100',
                                ][$row['final']['band']] ?? 'text-gray-700 bg-gray-100';
                            ?>
                                <span class="font-black text-gray-800"><?= h(number_format($row['final']['avg_score'], 2)) ?></span>
                                <span class="text-xs font-bold ml-1 px-1.5 py-0.5 rounded <?= h($fband) ?>"><?= h($row['final']['band']) ?></span>
                            <?php else: ?>
                                <span class="text-gray-300 text-xs">Awaiting both teams</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p id="wl2NoMatches" class="hidden text-center text-sm text-gray-400 py-8">No rows match the current search/filter.</p>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-center text-xs text-gray-400">&copy; 2026 Corporate Procurement App. All rights reserved.</p>
</div>

<!-- Item Descriptions modal ─────────────────────────────────────────────────
     Opened from the "N items" button in each PO Number cell. A 9th column
     would not fit in the table, so the line items live here instead. Same
     vanilla-JS modal pattern as vendor_eval_v2_results.php (no jQuery). -->
<div id="wl2-items-modal" style="display:none" class="fixed inset-0 z-50 items-center justify-center">
    <div class="absolute inset-0 bg-black/40" data-wl2-items-close></div>
    <div class="relative bg-white rounded-xl shadow-xl w-full max-w-3xl mx-4 max-h-[80vh] flex flex-col z-10">
        <div class="px-5 py-4 border-b border-indigo-100 bg-indigo-50 rounded-t-xl flex items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-bold text-indigo-800">Item Descriptions</h2>
                <p class="text-xs text-indigo-600 mt-0.5">PO <span id="wl2-items-po" class="font-mono font-bold"></span> &middot; <span id="wl2-items-count"></span></p>
            </div>
            <button type="button" data-wl2-items-close class="text-indigo-400 hover:text-red-600 font-bold text-xl leading-none mt-0.5 transition">&#x2715;</button>
        </div>
        <div class="overflow-auto flex-1">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200 sticky top-0">
                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                        <th class="px-4 py-2.5 font-bold text-right w-12">#</th>
                        <th class="px-4 py-2.5 font-bold">Item Description</th>
                        <th class="px-4 py-2.5 font-bold text-right whitespace-nowrap">Qty</th>
                        <th class="px-4 py-2.5 font-bold text-right whitespace-nowrap">Amount (IDR)</th>
                    </tr>
                </thead>
                <tbody id="wl2-items-body" class="divide-y divide-gray-100"></tbody>
                <tfoot class="bg-gray-50 border-t-2 border-gray-200">
                    <tr>
                        <td colspan="3" class="px-4 py-2.5 text-right text-xs font-bold text-gray-500 uppercase tracking-wider">Total</td>
                        <td id="wl2-items-total" class="px-4 py-2.5 text-right font-bold text-gray-800 whitespace-nowrap"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
<script>
// Lightweight vanilla-JS search/filter/sort for this table — no jQuery/
// DataTables. This is a public no-login page; keep the dependency footprint
// the same as the rest of it (Tailwind CDN only).
(function () {
    var table = document.getElementById('wl2Table');
    if (!table) return;

    var searchInput   = document.getElementById('wl2Search');
    var statusFilter  = document.getElementById('wl2StatusFilter');
    var buFilter      = document.getElementById('wl2BuFilter');
    var categoryFilter = document.getElementById('wl2CategoryFilter');
    var tbody        = document.getElementById('wl2Body');
    var noMatches    = document.getElementById('wl2NoMatches');
    var rows         = Array.prototype.slice.call(tbody.querySelectorAll('tr[data-po]'));

    function applyFilters() {
        var q         = (searchInput.value || '').trim().toLowerCase();
        var statusVal = statusFilter.value;
        var buVal     = buFilter.value;
        var catVal    = categoryFilter.value;
        var visible   = 0;

        rows.forEach(function (tr) {
            var matchesSearch = !q
                || tr.dataset.po.indexOf(q) !== -1
                || tr.dataset.vendor.indexOf(q) !== -1
                || tr.dataset.site.indexOf(q) !== -1
                || (tr.dataset.items || '').indexOf(q) !== -1;
            var matchesStatus = !statusVal || tr.dataset.status === statusVal;
            var matchesBu     = !buVal || tr.dataset.bu === buVal;
            var matchesCat    = !catVal || tr.dataset.category === catVal;
            var show = matchesSearch && matchesStatus && matchesBu && matchesCat;
            tr.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        noMatches.classList.toggle('hidden', visible !== 0);
    }

    searchInput.addEventListener('input', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
    buFilter.addEventListener('change', applyFilters);
    categoryFilter.addEventListener('change', applyFilters);

    // ── "Your name" — remembered locally so a repeat filler doesn't retype it
    // ── Barang/Jasa category override popover ──────────────────────────
    var TOKEN = <?= json_encode($raw_token) ?>;
    var catPopover = null;

    function closeCatPopover() {
        if (catPopover) { catPopover.remove(); catPopover = null; }
    }

    function flashBadgeError(badge, message) {
        var prevText = badge.textContent;
        var prevCls  = badge.className;
        badge.textContent = 'Error — retry';
        badge.className = 'wl2-cat-badge inline-flex items-center gap-1 font-sans text-[10px] font-bold uppercase tracking-wide border rounded px-1.5 py-0.5 bg-red-100 text-red-700 border-red-200';
        badge.title = message;
        setTimeout(function () {
            badge.textContent = prevText;
            badge.className = prevCls;
        }, 2500);
    }

    function saveCategory(po, category, badge, tr) {
        var body = new URLSearchParams({ token: TOKEN, po_number: po, category: category });
        badge.disabled = true;
        fetch('ajax_po_item_category_override.php', { method: 'POST', body: body })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                badge.disabled = false;
                if (data.error) { flashBadgeError(badge, data.error); return; }
                tr.dataset.category = category;
                badge.dataset.category = category;
                badge.textContent = category.charAt(0).toUpperCase() + category.slice(1);
                badge.className = 'wl2-cat-badge inline-flex items-center gap-1 font-sans text-[10px] font-bold uppercase tracking-wide border rounded px-1.5 py-0.5 '
                    + (category === 'jasa' ? 'bg-purple-100 text-purple-700 border-purple-200' : 'bg-teal-100 text-teal-700 border-teal-200');
                badge.title = 'Manually set. Click to correct.';
                applyFilters();
            })
            .catch(function () { badge.disabled = false; flashBadgeError(badge, 'Could not save — check your connection and try again.'); });
    }

    tbody.addEventListener('click', function (e) {
        var badge = e.target.closest ? e.target.closest('.wl2-cat-badge') : null;
        if (!badge) { closeCatPopover(); return; }
        e.stopPropagation();
        closeCatPopover();

        var po = badge.getAttribute('data-po');
        var tr = badge.closest('tr');
        var rect = badge.getBoundingClientRect();

        catPopover = document.createElement('div');
        catPopover.className = 'fixed z-50 bg-white border border-gray-200 rounded-lg shadow-xl p-1.5 flex gap-1';
        // position:fixed is already viewport-relative — do NOT add
        // window.scrollY/scrollX here, that double-counts scroll and pushes
        // the popover further down/right the more the page is scrolled.
        catPopover.style.top  = (rect.bottom + 4) + 'px';
        catPopover.style.left = rect.left + 'px';

        ['barang', 'jasa'].forEach(function (cat) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = 'Mark ' + cat.charAt(0).toUpperCase() + cat.slice(1);
            btn.className = 'text-xs font-bold px-2.5 py-1.5 rounded hover:bg-gray-100 whitespace-nowrap '
                + (cat === 'jasa' ? 'text-purple-700' : 'text-teal-700');
            btn.addEventListener('click', function (ev) {
                ev.stopPropagation();
                closeCatPopover();
                saveCategory(po, cat, badge, tr);
            });
            catPopover.appendChild(btn);
        });

        document.body.appendChild(catPopover);
    });
    document.addEventListener('click', closeCatPopover);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeCatPopover(); });

    // Click a truncated Vendor / Site Allocation cell to expand it to its
    // full text (wraps within the column instead of overflowing the
    // table), click again to re-truncate. Delegated on tbody so it keeps
    // working after rows get reordered by the sort handler below.
    tbody.addEventListener('click', function (e) {
        var span = e.target.closest ? e.target.closest('.wl2-expand-toggle') : null;
        if (!span) return;
        span.classList.toggle('truncate');
        span.classList.toggle('whitespace-normal');
        span.classList.toggle('break-words');
    });

    // ── Item Descriptions modal ────────────────────────────────────────
    // Descriptions are inlined server-side rather than fetched on demand:
    // the eligible-PO list is small, and the two existing ajax_*.php
    // endpoints both require ../includes/auth.php, which this no-login
    // page cannot use.
    var WL2_ITEMS    = <?= json_encode($items_by_po, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var WL2_TOTALS   = <?= json_encode($item_totals, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var itemsModal   = document.getElementById('wl2-items-modal');
    var itemsPoEl    = document.getElementById('wl2-items-po');
    var itemsCountEl = document.getElementById('wl2-items-count');
    var itemsBodyEl  = document.getElementById('wl2-items-body');
    var itemsTotalEl = document.getElementById('wl2-items-total');

    function closeItemsModal() {
        itemsModal.style.display = 'none';
    }

    // textContent, never innerHTML — every value here is raw ERP text.
    function itemCell(text, cls) {
        var td = document.createElement('td');
        td.className = cls;
        td.textContent = text;
        return td;
    }

    function openItemsModal(po) {
        var items = WL2_ITEMS[po] || [];
        itemsPoEl.textContent    = po;
        itemsCountEl.textContent = items.length + (items.length === 1 ? ' line item' : ' line items');
        itemsBodyEl.innerHTML    = '';

        if (!items.length) {
            var tr = document.createElement('tr');
            var td = itemCell('No item descriptions recorded for this PO.', 'px-4 py-6 text-center text-gray-400 italic');
            td.colSpan = 4;
            tr.appendChild(td);
            itemsBodyEl.appendChild(tr);
            itemsTotalEl.textContent = '—';
        } else {
            items.forEach(function (it, i) {
                var tr = document.createElement('tr');
                tr.appendChild(itemCell(String(i + 1), 'px-4 py-2.5 text-right text-gray-400 align-top tabular-nums'));
                tr.appendChild(itemCell(it.d, 'px-4 py-2.5 text-gray-700 break-words'));
                tr.appendChild(itemCell(it.q + (it.u ? ' ' + it.u : ''), 'px-4 py-2.5 text-right text-gray-600 align-top whitespace-nowrap tabular-nums'));
                tr.appendChild(itemCell(it.a, 'px-4 py-2.5 text-right text-gray-800 align-top whitespace-nowrap tabular-nums'));
                itemsBodyEl.appendChild(tr);
            });
            itemsTotalEl.textContent = WL2_TOTALS[po] || '—';
        }
        itemsModal.style.display = 'flex';
    }

    // Delegated on tbody so it survives the sort handler re-ordering rows.
    // The expand handler above matches .wl2-expand-toggle, which this button
    // does not carry, so the two never fire for the same click.
    tbody.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('.wl2-items-btn') : null;
        if (!btn) return;
        e.stopPropagation();
        openItemsModal(btn.getAttribute('data-po') || '');
    });

    Array.prototype.forEach.call(itemsModal.querySelectorAll('[data-wl2-items-close]'), function (el) {
        el.addEventListener('click', closeItemsModal);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeItemsModal();
    });

    var sortState = { key: null, dir: 1 };
    Array.prototype.forEach.call(table.querySelectorAll('th[data-sort-key]'), function (th) {
        th.addEventListener('click', function () {
            var key  = th.getAttribute('data-sort-key');
            var type = th.getAttribute('data-sort-type') || 'text';
            var dir  = (sortState.key === key) ? -sortState.dir : 1;
            sortState = { key: key, dir: dir };

            Array.prototype.forEach.call(table.querySelectorAll('.wl2-sort-arrow'), function (a) {
                a.textContent = '';
            });
            var arrow = th.querySelector('.wl2-sort-arrow');
            if (arrow) arrow.textContent = dir === 1 ? '▲' : '▼';

            rows.sort(function (a, b) {
                var av = a.querySelector('[data-sort-cell="' + key + '"]').getAttribute('data-sort-value');
                var bv = b.querySelector('[data-sort-cell="' + key + '"]').getAttribute('data-sort-value');
                if (type === 'number') {
                    av = av === '' ? -Infinity : parseFloat(av);
                    bv = bv === '' ? -Infinity : parseFloat(bv);
                    return (av - bv) * dir;
                }
                if (av < bv) return -1 * dir;
                if (av > bv) return 1 * dir;
                return 0;
            });
            rows.forEach(function (tr) { tbody.appendChild(tr); });
        });
    });

    applyFilters();
})();
</script>
</body>
</html>
