<?php
// workspace/pages/public_eval_v2_worklist.php
//
// Vendor Evaluation v2 — no-login requestor worklist.
//
// Access model: ?token=<requestor_public_token> (production).
//               ?req=REQUESTOR_NAME fallback for local/debug only.
//               Token wins when both are present.
// See public_eval_v2.php for the per-PO evaluation form.
//
// Read tables  : vendor_eval_po_taxonomy, vendor_eval_submissions
// Write tables : NONE
//
// Safety invariants:
//   • No auth.php / session / login required.
//   • Never writes to DB.
//   • No WA / email / Gemini / external API calls.
//   • Does NOT query the prs table or any v1 table.
//   • Never touches vendor_evaluations (v1).
//   • All DB access uses prepared statements (PDO).
//   • All output goes through h() (htmlspecialchars).
//   • requestor_names_json is decoded in PHP; never exposed raw.
require_once __DIR__ . '/../../includes/branding.php';

date_default_timezone_set('Asia/Jakarta');
require_once '../config/database.php';   // provides $pdo (PDO)

const WL_MAX_PARAM = 255;
const WL_TOKEN_MAX = 128;

// ── Helper: HTML-safe output ─────────────────────────────────────────────────
function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Helper: full-page block (never returns) ──────────────────────────────────
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

// ── Input: resolve requestor from token (preferred) or req fallback ──────────
$raw_token = trim((string)($_GET['token'] ?? ''));
$raw_req   = trim((string)($_GET['req']   ?? ''));

$requestor    = '';
$active_token = '';

if ($raw_token !== '') {
    // Token path: validate hex format, then lookup requestors table.
    if (strlen($raw_token) > WL_TOKEN_MAX || !preg_match('/^[0-9a-f]{32,128}$/i', $raw_token)) {
        blockPage(
            'Invalid Worklist Link',
            'This worklist link is invalid. Please use the link provided to you.',
            'bg-red-600'
        );
    }
    $tok_stmt = $pdo->prepare(
        "SELECT requestor_name FROM requestors WHERE public_token = ? LIMIT 1"
    );
    $tok_stmt->execute([$raw_token]);
    $tok_row = $tok_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tok_row) {
        blockPage(
            'Invalid Worklist Link',
            'This worklist link is not recognised. Please use the link provided to you.',
            'bg-red-600'
        );
    }
    $requestor    = (string)$tok_row['requestor_name'];
    $active_token = $raw_token;

} elseif ($raw_req !== '') {
    // req fallback (local/debug only).
    if (strlen($raw_req) > WL_MAX_PARAM) {
        blockPage(
            'Invalid Worklist Link',
            'This worklist link is missing a required requestor parameter or the value is too long. '
            . 'Please use the link provided to you.',
            'bg-red-600'
        );
    }
    $requestor = $raw_req;

} else {
    blockPage(
        'Invalid Worklist Link',
        'This worklist link is missing a required parameter. '
        . 'Please use the link provided to you.',
        'bg-red-600'
    );
}

// ── Load all relevant PO taxonomy rows (PHP-side JSON filtering) ─────────────
// MariaDB 10.4 JSON_CONTAINS support is unreliable for array searches;
// we fetch all candidate rows and filter in PHP.
$po_stmt = $pdo->prepare("
    SELECT po_number,
           vendor_name,
           taxonomy_level_2,
           taxonomy_level_3,
           status,
           requestor_names_json,
           mapped_at
      FROM vendor_eval_po_taxonomy
     WHERE status IN ('mapped', 'needs_review', 'unresolved')
     ORDER BY mapped_at DESC
");
$po_stmt->execute();
$all_po_rows = $po_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter to rows where this requestor appears in requestor_names_json
$my_pos = [];
foreach ($all_po_rows as $po_row) {
    $names = json_decode((string)($po_row['requestor_names_json'] ?? '[]'), true);
    if (!is_array($names)) continue;
    foreach ($names as $name) {
        if (trim((string)$name) === $requestor) {
            $my_pos[$po_row['po_number']] = $po_row;
            break;
        }
    }
}

// ── Load submissions for this requestor ─────────────────────────────────────
$sub_stmt = $pdo->prepare("
    SELECT po_number, status, submitted_at, total_score, score_band
      FROM vendor_eval_submissions
     WHERE requestor_name = ?
       AND status = 'submitted'
");
$sub_stmt->execute([$requestor]);
$submissions = [];
foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $sub) {
    $submissions[$sub['po_number']] = $sub;
}

// ── Load overrides for this requestor's POs ─────────────────────────────────
$overrides = [];
if (!empty($my_pos)) {
    $ph = implode(',', array_fill(0, count($my_pos), '?'));
    $ov_stmt = $pdo->prepare("SELECT po_number FROM vendor_eval_po_question_set_override WHERE po_number IN ($ph)");
    $ov_stmt->execute(array_keys($my_pos));
    foreach ($ov_stmt->fetchAll(PDO::FETCH_ASSOC) as $ov) {
        $overrides[$ov['po_number']] = true;
    }
}

// ── Build display rows with derived status ───────────────────────────────────
// display_status: 'pending' | 'submitted' | 'not_ready'
$pending    = [];
$submitted  = [];
$not_ready  = [];

foreach ($my_pos as $po_num => $po) {
    $po_status  = (string)$po['status'];
    $has_sub    = isset($submissions[$po_num]);
    $has_override = isset($overrides[$po_num]);

    if ($po_status === 'mapped' || $has_override) {
        if ($has_sub) {
            $po['_display_status'] = 'submitted';
            $po['_sub']            = $submissions[$po_num];
            $submitted[]           = $po;
        } else {
            $po['_display_status'] = 'pending';
            $pending[]             = $po;
        }
    } elseif ($po_status === 'needs_review') {
        $po['_display_status'] = 'not_ready';
        $po['_reason']         = 'PO taxonomy under review';
        $not_ready[]           = $po;
    } else {
        $po['_display_status'] = 'not_ready';
        $po['_reason']         = 'Evaluation form not available yet';
        $not_ready[]           = $po;
    }
}

$count_pending   = count($pending);
$count_submitted = count($submitted);
$count_not_ready = count($not_ready);
$count_total     = $count_pending + $count_submitted + $count_not_ready;

// Merge into one display list: pending first, then submitted, then not_ready
$display_rows = array_merge($pending, $submitted, $not_ready);

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Evaluation Worklist — <?= h($requestor) ?></title>
    <?= vss_favicon_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen py-10 px-4">
<div class="max-w-6xl mx-auto space-y-6">

    <!-- Header -->
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="bg-indigo-700 px-6 py-5">
            <h1 class="text-2xl font-bold text-white">Vendor Evaluation Worklist</h1>
            <p class="text-indigo-200 mt-1">
                Showing all assigned purchase orders for
                <span class="font-bold text-white"><?= h($requestor) ?></span>
            </p>
        </div>

        <!-- Summary cards -->
        <div class="p-6 border-b border-gray-100">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-orange-600"><?= $count_pending ?></p>
                    <p class="text-xs font-bold text-orange-500 uppercase tracking-wider mt-1">Pending</p>
                </div>
                <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-green-600"><?= $count_submitted ?></p>
                    <p class="text-xs font-bold text-green-500 uppercase tracking-wider mt-1">Submitted</p>
                </div>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-gray-500"><?= $count_not_ready ?></p>
                    <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mt-1">Not Ready</p>
                </div>
                <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-4 text-center">
                    <p class="text-3xl font-black text-indigo-600"><?= $count_total ?></p>
                    <p class="text-xs font-bold text-indigo-400 uppercase tracking-wider mt-1">Total</p>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($display_rows)): ?>
    <!-- No POs assigned -->
    <div class="bg-white rounded-lg shadow p-8 text-center">
        <p class="text-gray-400 text-sm">No purchase orders are currently assigned to this requestor.</p>
    </div>

    <?php else: ?>
    <!-- PO table -->
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200">
                    <tr class="text-left text-xs text-gray-500 uppercase tracking-wider">
                        <th class="px-4 py-3 font-bold">PO Number</th>
                        <th class="px-4 py-3 font-bold">Vendor</th>
                        <th class="px-4 py-3 font-bold">Sub Category (L2)</th>
                        <th class="px-4 py-3 font-bold">Commodity (L3)</th>
                        <th class="px-4 py-3 font-bold">Status</th>
                        <th class="px-4 py-3 font-bold">Score / Date</th>
                        <th class="px-4 py-3 font-bold text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($display_rows as $row):
                        $ds  = $row['_display_status'];
                        $l3  = trim((string)($row['taxonomy_level_3'] ?? ''));
                        $sub = $row['_sub'] ?? null;
                        $eval_url = 'public_eval_v2.php?po=' . urlencode((string)$row['po_number'])
                                  . ($active_token !== ''
                                      ? '&token=' . urlencode($active_token)
                                      : '&req='   . urlencode($requestor));
                    ?>
                    <tr class="hover:bg-gray-50 transition">
                        <!-- PO number -->
                        <td class="px-4 py-3 font-mono font-bold text-indigo-700">
                            <?= h((string)$row['po_number']) ?>
                        </td>

                        <!-- Vendor -->
                        <td class="px-4 py-3 text-gray-700 max-w-[180px]">
                            <span class="block truncate" title="<?= h((string)($row['vendor_name'] ?? '')) ?>">
                                <?= h((string)($row['vendor_name'] ?? '—')) ?>
                            </span>
                        </td>

                        <!-- L2 -->
                        <td class="px-4 py-3 text-gray-600 max-w-[160px]">
                            <span class="block truncate" title="<?= h((string)($row['taxonomy_level_2'] ?? '')) ?>">
                                <?= h((string)($row['taxonomy_level_2'] ?? '—')) ?>
                            </span>
                        </td>

                        <!-- L3 -->
                        <td class="px-4 py-3 text-gray-500 max-w-[140px]">
                            <?php if ($l3 !== ''): ?>
                                <span class="block truncate" title="<?= h($l3) ?>"><?= h($l3) ?></span>
                            <?php else: ?>
                                <span class="text-gray-300">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td class="px-4 py-3">
                            <?php if ($ds === 'pending'): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-orange-700 bg-orange-100 border border-orange-200 rounded-full px-2.5 py-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-orange-500 inline-block"></span>
                                    Pending
                                </span>
                            <?php elseif ($ds === 'submitted'): ?>
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-green-700 bg-green-100 border border-green-200 rounded-full px-2.5 py-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-green-500 inline-block"></span>
                                    Submitted
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1 text-xs font-bold text-gray-500 bg-gray-100 border border-gray-200 rounded-full px-2.5 py-1">
                                    <span class="w-1.5 h-1.5 rounded-full bg-gray-400 inline-block"></span>
                                    Not Ready
                                </span>
                            <?php endif; ?>
                        </td>

                        <!-- Score / Date -->
                        <td class="px-4 py-3 text-gray-600">
                            <?php if ($ds === 'submitted' && $sub !== null):
                                $sub_date = date('d M Y', strtotime((string)$sub['submitted_at']));
                                $band_chip = [
                                    'A' => 'text-green-700 bg-green-100',
                                    'B' => 'text-blue-700 bg-blue-100',
                                    'C' => 'text-yellow-700 bg-yellow-100',
                                    'D' => 'text-red-700 bg-red-100',
                                ][$sub['score_band']] ?? 'text-gray-700 bg-gray-100';
                            ?>
                                <span class="font-bold text-gray-800"><?= h(number_format((float)$sub['total_score'], 2)) ?></span>
                                <span class="text-xs font-bold ml-1 px-1.5 py-0.5 rounded <?= h($band_chip) ?>"><?= h((string)$sub['score_band']) ?></span>
                                <span class="block text-xs text-gray-400 mt-0.5"><?= h($sub_date) ?></span>
                            <?php elseif ($ds === 'not_ready'): ?>
                                <span class="text-xs text-gray-400"><?= h((string)($row['_reason'] ?? '')) ?></span>
                            <?php else: ?>
                                <span class="text-gray-300 text-xs">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Action -->
                        <td class="px-4 py-3 text-right">
                            <?php if ($ds === 'pending'): ?>
                                <a href="<?= h($eval_url) ?>"
                                   class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold px-3 py-1.5 rounded shadow transition">
                                    Open Evaluation
                                </a>
                            <?php elseif ($ds === 'submitted'): ?>
                                <span class="inline-block text-xs font-bold text-green-700 bg-green-100 border border-green-200 px-3 py-1.5 rounded">
                                    Already Submitted
                                </span>
                            <?php else: ?>
                                <span class="inline-block text-xs font-bold text-gray-400 bg-gray-100 border border-gray-200 px-3 py-1.5 rounded cursor-not-allowed">
                                    Not Ready
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-center text-xs text-gray-400">
        &copy; 2026 Corporate Procurement App. All rights reserved.
    </p>
</div>
</body>
</html>
