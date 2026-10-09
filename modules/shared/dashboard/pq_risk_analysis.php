<?php
/* ------------------------------------------------------------------ *
 * modules/shared/dashboard/pq_risk_analysis.php
 *
 * "PQ Risk Analysis" report view (Vendor Management only).
 *
 * Answers the question Vendor Management keeps asking about a Critical /
 * High vendor: *which* PQ questions actually pushed it there, and *what*
 * did those vendors answer. For every Category (vendors.company_type) x
 * Sub-Category (vendors.sub_company_type) it ranks the questions by the
 * weighted points they lost — a Pareto — and for each question reports the
 * modus (most frequent) answer plus the free-text reasons vendors typed.
 *
 * Risk contribution of one answer mirrors the scoring engine exactly
 * (see finalizeSubmissionScoring() in includes/pq_workflow_helper.php):
 *     earned = (score / 5) * weight        lost = weight - earned
 * and risk_percentage = SUM(lost) / SUM(weight) * 100, so the per-question
 * "lost" column adds back up to the vendor's own risk percentage.
 * ------------------------------------------------------------------ */

if (!isset($conn) || !($conn instanceof mysqli)) {
    return;
}
require_once __DIR__ . '/../../../includes/pq_risk_helper.php';

/* ---------------- Filters (GET) ---------------- */
$pa_risk_opts = ['Critical', 'High', 'Moderate', 'Low', 'Very Low'];
$pa_sel_risk  = array_values(array_intersect($pa_risk_opts, (array) ($_GET['risk'] ?? [])));
if (!$pa_sel_risk) {
    $pa_sel_risk = ['Critical', 'High'];   // the two tiers this report exists for
}

$pa_ct_opts  = ['Supplier', 'Contractor', 'Perseorangan'];
$pa_sct_opts = ['Project', 'Retail & Maintenance', 'Individual / Foreman', 'Distributor', 'Principal', 'Retail', 'Service', 'Perseorangan'];
$pa_sel_ct   = in_array($_GET['ct']  ?? '', $pa_ct_opts,  true) ? $_GET['ct']  : '';
$pa_sel_sct  = in_array($_GET['sct'] ?? '', $pa_sct_opts, true) ? $_GET['sct'] : '';

/* ---------------- Small formatting helpers ---------------- */

// "(5) Ya\n(1) Tidak" -> [5 => 'Ya', 1 => 'Tidak'].  pq_weight.help_text is the
// only place the answer choices of a question are described.
$pa_parse_options = function (?string $help): array {
    $map = [];
    foreach (preg_split('/\R|\\\\n/', (string) $help) as $line) {
        if (preg_match('/^\s*\((\d+)\)\s*(.+?)\s*$/', $line, $m)) {
            $map[(int) $m[1]] = $m[2];
        }
    }
    return $map;
};

// Financial questions store a raw Rupiah amount in pq_answers.remarks
// ("150000000"), everything else stores prose. Digits-only decides which.
$pa_amount_of = function ($remark) {
    $raw = trim((string) $remark);
    if ($raw === '' || !preg_match('/^[0-9][0-9.,\s]*$/', $raw)) {
        return null;
    }
    $digits = preg_replace('/\D/', '', $raw);
    return ($digits === '' || strlen($digits) < 4) ? null : (float) $digits;
};

$pa_rp = function ($v) {
    $v = (float) $v;
    if ($v >= 1e12) { return 'Rp ' . rtrim(rtrim(number_format($v / 1e12, 2, ',', '.'), '0'), ',') . ' T'; }
    if ($v >= 1e9)  { return 'Rp ' . rtrim(rtrim(number_format($v / 1e9,  2, ',', '.'), '0'), ',') . ' M'; }
    if ($v >= 1e6)  { return 'Rp ' . rtrim(rtrim(number_format($v / 1e6,  1, ',', '.'), '0'), ',') . ' jt'; }
    return 'Rp ' . number_format($v, 0, ',', '.');
};

// Short tier labels so a group can show its whole risk mix on one line.
$pa_risk_abbr = ['Critical' => 'C', 'High' => 'H', 'Moderate' => 'M', 'Low' => 'L', 'Very Low' => 'VL'];

$pa_risk_class = function (string $cat): string {
    return [
        'Critical' => 'bg-danger',
        'High'     => 'bg-warning text-dark',
        'Moderate' => 'bg-info text-dark',
        'Low'      => 'bg-success',
        'Very Low' => 'bg-secondary',
    ][$cat] ?? 'bg-light text-dark';
};

/* ---------------- 1. Question catalogue + weights ---------------- */
$pa_questions = [];   // qid => text, section, variant_target, subs[], all_sub
$res = $conn->query("SELECT q.id, q.question_text, q.variant_target, s.section_name
                     FROM pq_questions q
                     JOIN pq_sections s ON s.id = q.section_id");
while ($res && $row = $res->fetch_assoc()) {
    $pa_questions[(int) $row['id']] = [
        'text'    => $row['question_text'],
        'section' => $row['section_name'],
        'variant' => $row['variant_target'],
        'subs'    => [],   // sub_variant_target => ['weight'=>, 'options'=>[]]
        'all_sub' => '',   // GROUP_CONCAT equivalent, drives applicability
    ];
}

$res = $conn->query("SELECT question_id, sub_variant_target, weight, help_text FROM pq_weight");
while ($res && $row = $res->fetch_assoc()) {
    $qid = (int) $row['question_id'];
    if (!isset($pa_questions[$qid])) {
        continue;
    }
    $sub = (string) ($row['sub_variant_target'] ?? '');
    $pa_questions[$qid]['subs'][$sub] = [
        'weight'  => (float) $row['weight'],
        'options' => $pa_parse_options($row['help_text']),
    ];
    $pa_questions[$qid]['all_sub'] .= ($pa_questions[$qid]['all_sub'] === '' ? '' : ',') . $sub;
}

// Same precedence as the scoring engine's weight sub-query: an exact
// sub_variant_target row wins, otherwise the catch-all ('' / NULL) row.
$pa_weight_row = function (array $q, string $subType): ?array {
    if (isset($q['subs'][$subType])) {
        return $q['subs'][$subType];
    }
    return $q['subs'][''] ?? null;
};

/* ---------------- 2. Submissions in scope ---------------- */
$pa_where = ["s.risk_category IS NOT NULL",
             "v.user_id NOT IN (SELECT id FROM users WHERE is_test_account = 1)"];
$pa_types = '';
$pa_args  = [];
if ($pa_sel_ct !== '')  { $pa_where[] = "v.company_type = ?";     $pa_types .= 's'; $pa_args[] = $pa_sel_ct; }
if ($pa_sel_sct !== '') { $pa_where[] = "v.sub_company_type = ?"; $pa_types .= 's'; $pa_args[] = $pa_sel_sct; }

$stmt = $conn->prepare("SELECT s.id, s.vendor_id, s.status, s.risk_category, s.risk_percentage,
                               v.company_name, v.company_type, v.sub_company_type
                        FROM pq_submissions s
                        JOIN vendors v ON v.id = s.vendor_id
                        WHERE " . implode(' AND ', $pa_where) . "
                        ORDER BY v.company_type, v.sub_company_type, s.risk_percentage DESC");
if ($pa_types !== '') {
    $stmt->bind_param($pa_types, ...$pa_args);
}
$stmt->execute();
$pa_all_subs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// The Pareto itself only looks at the selected risk tiers; the per-group
// averages also report the full population so the two can be compared.
$pa_subs = array_values(array_filter(
    $pa_all_subs,
    fn($s) => in_array($s['risk_category'], $pa_sel_risk, true)
));

/* ---------------- 3. Answers, and a live re-score of every submission ----------------
 *
 * pq_submissions.risk_percentage is a workflow snapshot: it is written by the
 * Sourcing review pass (update_assessment.php) or finalizeSubmissionScoring(),
 * and by the deliberately weaker placeholder calculation in submit_pq.php. It
 * is NOT refreshed when a vendor revises an answer afterwards or when the
 * classification changes, so a handful of rows carry a stale figure.
 *
 * A Pareto has to add up, so this report re-scores every submission from its
 * current answers with the canonical engine and drives all of its numbers off
 * that. The stored value is still used to scope the report (risk_category is
 * the label the rest of the portal works with) and any divergence is reported
 * back to VM rather than hidden.
 * ------------------------------------------------------------------ */
$pa_answers = [];   // submission_id => question_id => ['score'=>, 'remarks'=>]
if ($pa_all_subs) {
    $ids = implode(',', array_map(fn($s) => (int) $s['id'], $pa_all_subs));
    $res = $conn->query("SELECT submission_id, question_id, selected_score, remarks
                         FROM pq_answers WHERE submission_id IN ($ids)");
    while ($res && $row = $res->fetch_assoc()) {
        $pa_answers[(int) $row['submission_id']][(int) $row['question_id']] = [
            'score'   => $row['selected_score'] === null ? null : (int) $row['selected_score'],
            'remarks' => $row['remarks'],
        ];
    }
}

// The applicable, weighted, answered questions of one submission — the exact
// set finalizeSubmissionScoring() sums over.
$pa_scored_rows = function (array $s) use ($pa_questions, $pa_answers, $pa_weight_row): array {
    [$main_type, $sub_type] = pqScoringVariants($s['company_type'], $s['sub_company_type']);
    $rows = [];
    foreach ($pa_questions as $qid => $q) {
        if (!pqQuestionApplies($q['variant'], $q['all_sub'], $main_type, $sub_type)) {
            continue;
        }
        $wrow = $pa_weight_row($q, $sub_type);
        if (!$wrow || $wrow['weight'] <= 0) {
            continue;
        }
        $ans = $pa_answers[$s['id']][$qid] ?? null;
        if (!$ans || $ans['score'] === null) {
            continue;
        }
        $rows[$qid] = [
            'q'       => $q,
            'weight'  => $wrow['weight'],
            'options' => $wrow['options'],
            'score'   => $ans['score'],
            'remarks' => $ans['remarks'],
            'lost'    => (1 - ($ans['score'] / 5)) * $wrow['weight'],
        ];
    }
    return $rows;
};

// Re-score everything first, so the "all tiers" averages are comparable.
$pa_stale = [];
foreach ($pa_all_subs as &$s) {
    $rows        = $pa_scored_rows($s);
    $lost        = array_sum(array_column($rows, 'lost'));
    $max         = array_sum(array_column($rows, 'weight'));
    $s['rows']      = $rows;
    $s['calc_risk'] = pqRiskPercentage($max - $lost, $max);
    $s['calc_cat']  = pqRiskCategory($s['calc_risk']);
    // >1pp apart is a real drift, not the int rounding of total_weighted_score.
    if (abs($s['calc_risk'] - (float) $s['risk_percentage']) > 1.0) {
        $pa_stale[] = $s;
    }
}
unset($s);

// Re-point the in-scope slice at the enriched rows.
$pa_subs = array_values(array_filter(
    $pa_all_subs,
    fn($s) => in_array($s['risk_category'], $pa_sel_risk, true)
));

/* ---------------- 4. Aggregate per Category x Sub-Category x Question ---------------- */
$pa_groups = [];   // "Category|Sub" => ['category','sub','subs'=>[], 'questions'=>[qid=>...]]

$pa_group_key = fn($s) => ($s['company_type'] ?: '—') . '|' . ($s['sub_company_type'] ?: '—');

foreach ($pa_subs as $s) {
    $key = $pa_group_key($s);
    if (!isset($pa_groups[$key])) {
        $pa_groups[$key] = [
            'category'  => $s['company_type'] ?: '—',
            'sub'       => $s['sub_company_type'] ?: '—',
            'subs'      => [],
            'questions' => [],
        ];
    }
    $pa_groups[$key]['subs'][] = $s;

    foreach ($s['rows'] as $qid => $row) {
        $score = $row['score'];

        if (!isset($pa_groups[$key]['questions'][$qid])) {
            $pa_groups[$key]['questions'][$qid] = [
                'qid'       => $qid,
                'text'      => $row['q']['text'],
                'section'   => $row['q']['section'],
                'weight'    => $row['weight'],
                'options'   => $row['options'],
                'n'         => 0,
                'sum_score' => 0.0,
                'lost'      => 0.0,
                'max'       => 0.0,
                'dist'      => [],   // score => ['n'=>, 'vendors'=>[]]
                'reasons'   => [],   // lowercased reason => ['text'=>, 'n'=>, 'vendors'=>[]]
                'amounts'   => [],   // raw Rupiah values (financial questions)
            ];
        }
        $b =& $pa_groups[$key]['questions'][$qid];
        $b['n']++;
        $b['sum_score'] += $score;
        $b['lost']      += $row['lost'];
        $b['max']       += $row['weight'];

        if (!isset($b['dist'][$score])) {
            $b['dist'][$score] = ['n' => 0, 'vendors' => []];
        }
        $b['dist'][$score]['n']++;
        $b['dist'][$score]['vendors'][] = $s['company_name'];

        $amount = $pa_amount_of($row['remarks']);
        if ($amount !== null) {
            $b['amounts'][] = $amount;
        } elseif (trim((string) $row['remarks']) !== '') {
            $rk = mb_strtolower(trim(preg_replace('/\s+/', ' ', $row['remarks'])));
            if (!isset($b['reasons'][$rk])) {
                $b['reasons'][$rk] = ['text' => trim($row['remarks']), 'n' => 0, 'vendors' => []];
            }
            $b['reasons'][$rk]['n']++;
            $b['reasons'][$rk]['vendors'][] = $s['company_name'];
        }
        unset($b);
    }
}

/* ---------------- 5. Pareto ranking + modus per group ---------------- */
$pa_flat = [];   // flattened rows, used by the CSV export
foreach ($pa_groups as $key => &$g) {
    $total_lost = array_sum(array_column($g['questions'], 'lost'));
    $g['total_lost'] = $total_lost;

    // Average PQ result of the group, both in scope and across every tier.
    // Averaged over calc_risk so the group figure and its Pareto rows are the
    // same arithmetic (see the re-score note in section 3).
    $g['avg_risk']      = $g['subs'] ? array_sum(array_column($g['subs'], 'calc_risk')) / count($g['subs']) : 0.0;
    $g['vendor_count']  = count($g['subs']);
    $g['risk_mix']      = array_count_values(array_column($g['subs'], 'risk_category'));

    $all_of_group = array_values(array_filter($pa_all_subs, fn($s) => $pa_group_key($s) === $key));
    $g['all_count']    = count($all_of_group);
    $g['all_avg_risk'] = $all_of_group ? array_sum(array_column($all_of_group, 'calc_risk')) / count($all_of_group) : 0.0;

    uasort($g['questions'], fn($a, $b) => $b['lost'] <=> $a['lost']);

    $cum  = 0.0;
    $rank = 0;
    foreach ($g['questions'] as $qid => &$q) {
        $rank++;
        $share = $total_lost > 0 ? ($q['lost'] / $total_lost) * 100 : 0.0;
        $cum  += $share;

        $q['rank']       = $rank;
        $q['share']      = $share;
        $q['cumulative'] = $cum;
        // Vital few = the questions that together carry the first 80% of the
        // lost points. The row that crosses 80% is still included, otherwise
        // a two-question group could end up with no drivers flagged at all.
        $q['vital']      = ($cum - $share) < 80.0;
        $q['avg_score']  = $q['n'] > 0 ? $q['sum_score'] / $q['n'] : 0.0;
        $q['achieved']   = $q['max'] > 0 ? (($q['max'] - $q['lost']) / $q['max']) * 100 : 0.0;

        // Modus answer: the score most vendors picked, resolved to its label.
        // A modus only exists if something actually repeats — when every vendor
        // picked a different score there is no most-frequent answer, just a tie,
        // and reporting the first one would be a lie about the data.
        krsort($q['dist']);
        $modus_score = null;
        $modus_n     = 0;
        $modus_ties  = 0;
        foreach ($q['dist'] as $score => $d) {
            if ($d['n'] > $modus_n) {
                $modus_n     = $d['n'];
                $modus_score = $score;
                $modus_ties  = 1;
            } elseif ($d['n'] === $modus_n) {
                $modus_ties++;
            }
        }
        // Scores set by the financial auto-scorer (and older reviews) can fall
        // outside the choices help_text lists, so say so instead of inventing one.
        $q['modus_score']  = $modus_score;
        $q['modus_n']      = $modus_n;
        $q['modus_exists'] = ($modus_n > 1 || count($q['dist']) === 1);
        $q['modus_ties']   = $modus_ties;
        $q['modus_label']  = $modus_score === null
            ? '—'
            : ($q['options'][$modus_score] ?? 'Skor ' . $modus_score . ' (di luar daftar pilihan)');
        $q['modus_share']  = $q['n'] > 0 ? ($modus_n / $q['n']) * 100 : 0.0;

        // Financial questions: the score label is a band, so also surface the
        // actual Rupiah figures behind it (this is the raw input VM wants for
        // the capital-gain / business-income read). Same modus rule as above —
        // three vendors with three different amounts have no modus, only a
        // median and a range.
        $q['amount_stats'] = null;
        if ($q['amounts']) {
            sort($q['amounts']);
            $cnt  = count($q['amounts']);
            $freq = array_count_values(array_map('strval', $q['amounts']));
            arsort($freq);
            $top_n  = reset($freq);
            $has_modus = ($top_n > 1 || count($freq) === 1);
            $q['amount_stats'] = [
                'n'          => $cnt,
                'modus'      => $has_modus ? (float) array_key_first($freq) : null,
                'modus_n'    => $top_n,
                'modus_ties' => count(array_filter($freq, fn($c) => $c === $top_n)),
                'median' => $cnt % 2 ? $q['amounts'][intdiv($cnt, 2)]
                                     : ($q['amounts'][$cnt / 2 - 1] + $q['amounts'][$cnt / 2]) / 2,
                'min'    => $q['amounts'][0],
                'max'    => $q['amounts'][$cnt - 1],
            ];
        }

        uasort($q['reasons'], fn($a, $b) => $b['n'] <=> $a['n']);

        $pa_flat[] = [
            'Category'        => $g['category'],
            'Sub Category'    => $g['sub'],
            'Rank'            => $rank,
            'Section'         => $q['section'],
            'Question'        => $q['text'],
            'Weight'          => $q['weight'],
            'Vendors'         => $q['n'],
            'Avg Score (0-5)' => round($q['avg_score'], 2),
            'Achieved %'      => round($q['achieved'], 1),
            'Risk Points Lost'=> round($q['lost'], 2),
            '% of Group Risk' => round($share, 1),
            'Cumulative %'    => round($cum, 1),
            'Pareto'          => $q['vital'] ? 'Vital Few' : 'Trivial Many',
            'Modus Answer'    => $q['modus_exists'] ? $q['modus_label'] : '(seri, tidak ada modus)',
            'Modus Count'     => $modus_n,
            'Modus Share %'   => round($q['modus_share'], 1),
            'Modus Amount'    => $q['amount_stats']['modus'] ?? '',
            'Median Amount'   => $q['amount_stats'] ? $q['amount_stats']['median'] : '',
            'Top Reason'      => $q['reasons'] ? reset($q['reasons'])['text'] : '',
        ];
    }
    unset($q);
}
unset($g);

// Heaviest-risk groups first.
uasort($pa_groups, fn($a, $b) => $b['total_lost'] <=> $a['total_lost']);

/* ---------------- 6. Header KPIs ---------------- */
$pa_scope_n   = count($pa_subs);
$pa_avg_risk  = $pa_scope_n ? array_sum(array_column($pa_subs, 'calc_risk')) / $pa_scope_n : 0.0;
$pa_pop_n     = count($pa_all_subs);
$pa_pop_risk  = $pa_pop_n ? array_sum(array_column($pa_all_subs, 'calc_risk')) / $pa_pop_n : 0.0;
$pa_driver_n  = 0;
foreach ($pa_groups as $g) {
    $pa_driver_n += count(array_filter($g['questions'], fn($q) => $q['vital']));
}
?>

<style>
    .pa-filter .form-label { font-size: .72rem; letter-spacing: .04em; }
    .pa-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        border: 1px solid #dee2e6; border-radius: 50px;
        padding: .3rem .8rem; font-size: .82rem; cursor: pointer;
        background: #fff; color: #6c757d; transition: all .15s ease;
    }
    .pa-chip:hover { border-color: #adb5bd; }
    .pa-chip input { accent-color: #2f6690; }
    .pa-chip.is-on { background: #eef5fb; border-color: #bcdcf0; color: #2f6690; font-weight: 600; }

    .pa-group-head {
        background: linear-gradient(135deg, #f6f9fc 0%, #eef4fa 100%);
        border-bottom: 1px solid #e3ecf4;
    }
    .pa-table { font-size: .84rem; }
    .pa-table thead th {
        font-size: .7rem; text-transform: uppercase; letter-spacing: .04em;
        color: #6c757d; font-weight: 700; white-space: nowrap; vertical-align: middle;
    }
    .pa-table td { vertical-align: middle; }
    .pa-table tbody tr.pa-vital { background: #fffaf3; }
    .pa-table tbody tr.pa-row:hover { background: #f1f6fb; cursor: pointer; }
    .pa-q { font-weight: 600; color: #212529; }
    .pa-bar { height: 6px; border-radius: 3px; background: #e9ecef; overflow: hidden; min-width: 60px; }
    .pa-bar > span { display: block; height: 100%; background: #dc3545; }

    /* Both the Pareto table and the two tables inside its expanded detail run
       table-layout:fixed. Under the default `auto` layout a long cell (a vendor
       list, a full answer label) widens its column until the table outgrows its
       container and spills over the neighbouring column — that was the overlap.
       Fixed layout makes the declared widths binding, so text wraps or truncates
       inside its own column instead. */
    .pa-pareto { table-layout: fixed; width: 100%; min-width: 980px; }
    .pa-cell   { overflow-wrap: anywhere; }

    .pa-detail { background: #fbfcfe; }
    .pa-detail .table { font-size: .8rem; margin-bottom: 0; table-layout: fixed; width: 100%; }
    .pa-detail .table td, .pa-detail .table th { overflow-wrap: anywhere; }
    .pa-caret { transition: transform .15s ease; color: #adb5bd; }
    .pa-row[aria-expanded="true"] .pa-caret { transform: rotate(90deg); color: #2f6690; }
    .pa-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>

<div class="container pb-5">

    <h4 class="fw-bold text-dark mb-3 mt-2">
        <i class="fas fa-triangle-exclamation text-primary me-2"></i>
        PQ Risk Analysis
    </h4>

    <!-- ===================== Filters ===================== -->
    <form method="get" class="card shadow-sm mb-3 pa-filter">
        <input type="hidden" name="view" value="pq-risk-analysis">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-lg-5">
                    <label class="form-label text-muted fw-bold text-uppercase d-block mb-2">Risk Category</label>
                    <?php foreach ($pa_risk_opts as $opt): $on = in_array($opt, $pa_sel_risk, true); ?>
                        <label class="pa-chip me-1 mb-1 <?= $on ? 'is-on' : '' ?>">
                            <input type="checkbox" name="risk[]" value="<?= htmlspecialchars($opt) ?>" <?= $on ? 'checked' : '' ?>>
                            <?= htmlspecialchars($opt) ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label text-muted fw-bold text-uppercase">Category</label>
                    <select name="ct" class="form-select form-select-sm">
                        <option value="">All Categories</option>
                        <?php foreach ($pa_ct_opts as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $pa_sel_ct === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label text-muted fw-bold text-uppercase">Sub-Category</label>
                    <select name="sct" class="form-select form-select-sm">
                        <option value="">All Sub-Categories</option>
                        <?php foreach ($pa_sct_opts as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= $pa_sel_sct === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-1 col-md-12 d-grid">
                    <button class="btn btn-sm btn-primary"><i class="fas fa-filter me-1"></i>Apply</button>
                </div>
            </div>
        </div>
    </form>

    <!-- ===================== KPI strip ===================== -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-danger h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Vendors in Scope</small>
                    <h2 class="display-6 fw-bold text-danger mb-0"><?= $pa_scope_n ?></h2>
                    <small class="text-muted">of <?= $pa_pop_n ?> scored PQ submissions</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-warning h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Avg. Risk in Scope</small>
                    <h2 class="display-6 fw-bold text-warning mb-0"><?= number_format($pa_avg_risk, 1) ?>%</h2>
                    <small class="text-muted">all tiers: <?= number_format($pa_pop_risk, 1) ?>%</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-primary h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Avg. PQ Score</small>
                    <h2 class="display-6 fw-bold text-primary mb-0"><?= number_format(100 - $pa_avg_risk, 1) ?>%</h2>
                    <small class="text-muted">weighted score achieved</small>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm border-start border-4 border-success h-100">
                <div class="card-body">
                    <small class="text-muted text-uppercase fw-bold">Vital-Few Drivers</small>
                    <h2 class="display-6 fw-bold text-success mb-0"><?= $pa_driver_n ?></h2>
                    <small class="text-muted">questions carrying the first 80%</small>
                </div>
            </div>
        </div>
    </div>

    <?php if ($pa_stale): ?>
    <!-- Stored vs recomputed drift — worth acting on, so never silently hidden. -->
    <div class="alert alert-warning border-0 shadow-sm mb-4">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <i class="fas fa-rotate me-1"></i>
                Nilai risiko <strong><?= count($pa_stale) ?> vendor</strong> di halaman ini berbeda dengan yang tersimpan di halaman Review PQ.
                <div class="small mt-1">
                    Penyebabnya: setelah skor terakhir dihitung, jawaban PQ atau klasifikasi kategori vendor tersebut sempat diubah,
                    tetapi nilai risikonya tidak ikut dihitung ulang oleh sistem.
                    Halaman ini selalu memakai hasil hitung terbaru dari jawaban yang ada sekarang, jadi angkanya lebih akurat.
                </div>
            </div>
            <button class="btn btn-sm btn-outline-dark" type="button" data-bs-toggle="collapse" data-bs-target="#paStale">
                Lihat daftar vendor
            </button>
        </div>
        <div class="collapse mt-3" id="paStale">
            <div class="table-responsive bg-white rounded">
                <table class="table table-sm pa-table mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Vendor</th>
                            <th>Kategori vendor</th>
                            <th class="text-end">Risiko tersimpan<br><span class="fw-normal text-body-tertiary">di Review PQ</span></th>
                            <th class="text-end">Risiko terbaru<br><span class="fw-normal text-body-tertiary">hitung ulang</span></th>
                            <th class="pe-3">Perubahan tingkat risiko</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pa_stale as $s): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= htmlspecialchars($s['company_name']) ?></td>
                            <td class="text-muted"><?= htmlspecialchars(($s['company_type'] ?: '—') . ' · ' . ($s['sub_company_type'] ?: '—')) ?></td>
                            <td class="text-end pa-num"><?= number_format((float) $s['risk_percentage'], 1) ?>%</td>
                            <td class="text-end pa-num fw-bold"><?= number_format($s['calc_risk'], 1) ?>%</td>
                            <td class="pe-3">
                                <span class="badge <?= $pa_risk_class($s['risk_category']) ?> rounded-pill"><?= htmlspecialchars($s['risk_category']) ?></span>
                                <?php if ($s['calc_cat'] !== $s['risk_category']): ?>
                                    <i class="fas fa-arrow-right mx-1 text-muted small"></i>
                                    <span class="badge <?= $pa_risk_class($s['calc_cat']) ?> rounded-pill"><?= htmlspecialchars($s['calc_cat']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$pa_groups): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center text-muted py-5 fst-italic">
                <i class="fas fa-circle-info me-1"></i>
                Belum ada submission PQ yang cocok dengan filter ini.
            </div>
        </div>
    <?php else: ?>

    <!-- ===================== Average PQ result per Category / Sub-Category ===================== -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold text-dark">
                <i class="fas fa-layer-group text-primary me-2"></i>
                Average PQ Result per Category &amp; Sub-Category
            </h6>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="paExportCsv">
                <i class="fas fa-file-csv me-1"></i>Download raw data (CSV)
            </button>
        </div>
        <div class="table-responsive">
            <table class="table pa-table mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Category</th>
                        <th>Sub-Category</th>
                        <th class="text-center">Vendors<br><span class="fw-normal text-body-tertiary">in scope</span></th>
                        <th class="text-center">Risk Mix</th>
                        <th class="text-end">Avg. Risk %<br><span class="fw-normal text-body-tertiary">in scope</span></th>
                        <th class="text-end">Avg. PQ Score %</th>
                        <th class="text-end">Avg. Risk %<br><span class="fw-normal text-body-tertiary">all tiers</span></th>
                        <th class="pe-3">Top Risk Driver</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pa_groups as $g): $top = $g['questions'] ? reset($g['questions']) : null; ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= htmlspecialchars($g['category']) ?></td>
                        <td><?= htmlspecialchars($g['sub']) ?></td>
                        <td class="text-center pa-num"><?= $g['vendor_count'] ?> <small class="text-muted">/ <?= $g['all_count'] ?></small></td>
                        <td class="text-center">
                            <?php foreach ($pa_risk_opts as $rc): if (empty($g['risk_mix'][$rc])) continue; ?>
                                <span class="badge <?= $pa_risk_class($rc) ?> rounded-pill" title="<?= htmlspecialchars($rc) ?>">
                                    <?= $pa_risk_abbr[$rc] ?> <?= $g['risk_mix'][$rc] ?>
                                </span>
                            <?php endforeach; ?>
                        </td>
                        <td class="text-end fw-bold text-danger pa-num"><?= number_format($g['avg_risk'], 1) ?>%</td>
                        <td class="text-end pa-num"><?= number_format(100 - $g['avg_risk'], 1) ?>%</td>
                        <td class="text-end text-muted pa-num"><?= number_format($g['all_avg_risk'], 1) ?>%</td>
                        <td class="pe-3">
                            <?php if ($top): ?>
                                <span class="pa-q"><?= htmlspecialchars($top['text']) ?></span>
                                <small class="text-muted">· <?= number_format($top['share'], 0) ?>% of group risk</small>
                            <?php else: ?>
                                <span class="text-muted fst-italic">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ===================== Pareto table per group ===================== -->
    <?php $pa_uid = 0; foreach ($pa_groups as $g): $pa_uid++; ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header pa-group-head py-3">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h6 class="mb-0 fw-bold text-dark">
                        <i class="fas fa-chart-simple text-primary me-2"></i>
                        <?= htmlspecialchars($g['category']) ?>
                        <i class="fas fa-angle-right mx-1 text-muted"></i>
                        <?= htmlspecialchars($g['sub']) ?>
                    </h6>
                    <div class="small text-muted">
                        <?= $g['vendor_count'] ?> vendor<?= $g['vendor_count'] === 1 ? '' : 's' ?>
                        &nbsp;·&nbsp; avg risk <strong class="text-danger"><?= number_format($g['avg_risk'], 1) ?>%</strong>
                        &nbsp;·&nbsp; <?= count($g['questions']) ?> scored questions
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table pa-table pa-pareto table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:3%;"></th>
                            <th style="width:3%;">#</th>
                            <th style="width:22%;">Question</th>
                            <th class="text-center" style="width:6%;">Vendors</th>
                            <th class="text-end" style="width:7%;">Avg Score</th>
                            <th class="text-end" style="width:11%;">Risk Points</th>
                            <th class="text-end" style="width:6%;">Cum. %</th>
                            <th style="width:21%;">Modus Answer</th>
                            <th class="pe-3" style="width:21%;">Why (vendor input)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($g['questions'] as $q):
                        $rid = 'paD' . $pa_uid . '_' . $q['qid'];
                    ?>
                        <tr class="pa-row <?= $q['vital'] ? 'pa-vital' : '' ?>" data-bs-toggle="collapse" data-bs-target="#<?= $rid ?>" aria-expanded="false">
                            <td class="ps-3"><i class="fas fa-caret-right pa-caret"></i></td>
                            <td class="text-muted pa-num"><?= $q['rank'] ?></td>
                            <td>
                                <div class="pa-cell">
                                    <div class="pa-q"><?= htmlspecialchars($q['text']) ?></div>
                                    <small class="text-muted">
                                        <?= htmlspecialchars($q['section']) ?>
                                        · bobot <?= rtrim(rtrim(number_format($q['weight'], 2, ',', '.'), '0'), ',') ?>
                                    </small>
                                    <?php if ($q['vital']): ?>
                                        <span class="badge bg-warning text-dark rounded-pill ms-1" title="Termasuk 80% penyumbang risiko terbesar">Vital Few</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center pa-num"><?= $q['n'] ?></td>
                            <td class="text-end pa-num">
                                <span class="fw-bold <?= $q['avg_score'] >= 4 ? 'text-success' : ($q['avg_score'] >= 2.5 ? 'text-warning' : 'text-danger') ?>">
                                    <?= number_format($q['avg_score'], 1) ?>
                                </span>
                                <small class="text-muted">/5</small>
                            </td>
                            <td class="text-end">
                                <div class="fw-bold pa-num"><?= number_format($q['lost'], 1) ?></div>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="pa-bar flex-grow-1"><span style="width: <?= min(100, $q['share']) ?>%"></span></div>
                                    <small class="text-muted pa-num"><?= number_format($q['share'], 1) ?>%</small>
                                </div>
                            </td>
                            <td class="text-end pa-num <?= $q['vital'] ? 'fw-bold' : 'text-muted' ?>"><?= number_format($q['cumulative'], 1) ?>%</td>
                            <td>
                                <div class="pa-cell">
                                    <div><?= htmlspecialchars($q['modus_label']) ?></div>
                                    <small class="text-muted">
                                        <?php if ($q['modus_score'] !== null && isset($q['options'][$q['modus_score']])): ?>skor <?= $q['modus_score'] ?> · <?php endif; ?>
                                        <?= $q['modus_n'] ?>/<?= $q['n'] ?> vendor (<?= number_format($q['modus_share'], 0) ?>%)
                                        <?php if (!$q['modus_exists']): ?>
                                            <span class="d-block fst-italic">jawaban seri, tidak ada modus</span>
                                        <?php elseif ($q['modus_ties'] > 1): ?>
                                            <span class="d-block fst-italic">seri dengan <?= $q['modus_ties'] - 1 ?> jawaban lain</span>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </td>
                            <td class="pe-3">
                                <div class="pa-cell">
                                    <?php if ($q['amount_stats']): $a = $q['amount_stats']; ?>
                                        <?php if ($a['modus'] !== null): ?>
                                            <div class="fw-semibold text-dark"><?= $pa_rp($a['modus']) ?>
                                                <small class="text-muted fw-normal">(modus, <?= $a['modus_n'] ?>x)</small></div>
                                            <small class="text-muted">median <?= $pa_rp($a['median']) ?> · rentang <?= $pa_rp($a['min']) ?>–<?= $pa_rp($a['max']) ?></small>
                                        <?php else: ?>
                                            <div class="fw-semibold text-dark"><?= $pa_rp($a['median']) ?>
                                                <small class="text-muted fw-normal">(median)</small></div>
                                            <small class="text-muted">tidak ada modus · rentang <?= $pa_rp($a['min']) ?>–<?= $pa_rp($a['max']) ?></small>
                                        <?php endif; ?>
                                    <?php elseif ($q['reasons']): $r = reset($q['reasons']); ?>
                                        <div class="text-truncate" title="<?= htmlspecialchars($r['text'], ENT_QUOTES) ?>">“<?= htmlspecialchars($r['text']) ?>”</div>
                                        <small class="text-muted"><?= $r['n'] ?>x · <?= count($q['reasons']) ?> alasan berbeda</small>
                                    <?php else: ?>
                                        <small class="text-muted fst-italic">tidak ada isian bebas</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Expanded detail: full answer distribution + every reason -->
                        <tr class="pa-detail">
                            <td colspan="9" class="p-0 border-0">
                                <div class="collapse" id="<?= $rid ?>">
                                    <div class="row g-0">
                                    <div class="col-lg-6 p-3 border-end">
                                        <div class="small fw-bold text-uppercase text-muted mb-2">Sebaran jawaban</div>
                                            <table class="table table-sm align-middle">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th style="width:3rem;">Skor</th>
                                                        <th>Pilihan jawaban</th>
                                                        <th class="text-center" style="width:5rem;">Vendor</th>
                                                        <th class="text-end" style="width:6rem;">Risk points</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($q['dist'] as $score => $d): ?>
                                                    <tr>
                                                        <td><span class="badge <?= $score >= 4 ? 'bg-success' : ($score >= 2 ? 'bg-warning text-dark' : 'bg-danger') ?> rounded-pill"><?= $score ?></span></td>
                                                        <td>
                                                            <?php if (isset($q['options'][$score])): ?>
                                                                <?= htmlspecialchars($q['options'][$score]) ?>
                                                            <?php else: ?>
                                                                <span class="text-muted fst-italic">di luar daftar pilihan</span>
                                                            <?php endif; ?>
                                                            <?php if ($score === $q['modus_score']): ?>
                                                                <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill ms-1">modus</span>
                                                            <?php endif; ?>
                                                            <div class="small text-muted text-truncate" title="<?= htmlspecialchars(implode(', ', $d['vendors']), ENT_QUOTES) ?>">
                                                                <?= htmlspecialchars(implode(', ', array_slice($d['vendors'], 0, 4))) ?><?= count($d['vendors']) > 4 ? ' +' . (count($d['vendors']) - 4) . ' lainnya' : '' ?>
                                                            </div>
                                                        </td>
                                                        <td class="text-center pa-num"><?= $d['n'] ?></td>
                                                        <td class="text-end pa-num"><?= number_format((1 - $score / 5) * $q['weight'] * $d['n'], 1) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div class="col-lg-6 p-3">
                                            <div class="small fw-bold text-uppercase text-muted mb-2">
                                                <?= $q['amount_stats'] ? 'Nilai yang diisi vendor' : 'Alasan yang diisi vendor' ?>
                                            </div>
                                            <?php if ($q['amount_stats']): $a = $q['amount_stats']; ?>
                                                <table class="table table-sm">
                                                    <tbody>
                                                        <tr>
                                                            <td class="text-muted">Modus</td>
                                                            <td>
                                                                <?php if ($a['modus'] !== null): ?>
                                                                    <span class="fw-bold"><?= $pa_rp($a['modus']) ?></span>
                                                                    <small class="text-muted">(<?= $a['modus_n'] ?> vendor<?= $a['modus_ties'] > 1 ? ', seri dengan ' . ($a['modus_ties'] - 1) . ' nilai lain' : '' ?>)</small>
                                                                <?php else: ?>
                                                                    <span class="text-muted">—</span>
                                                                    <small class="text-muted fst-italic d-block">setiap vendor mengisi nilai berbeda, tidak ada yang berulang</small>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                        <tr><td class="text-muted">Median</td><td class="fw-bold"><?= $pa_rp($a['median']) ?></td></tr>
                                                        <tr><td class="text-muted">Terendah</td><td><?= $pa_rp($a['min']) ?></td></tr>
                                                        <tr><td class="text-muted">Tertinggi</td><td><?= $pa_rp($a['max']) ?></td></tr>
                                                        <tr><td class="text-muted">Jumlah isian</td><td><?= $a['n'] ?> vendor</td></tr>
                                                    </tbody>
                                                </table>
                                            <?php elseif ($q['reasons']): ?>
                                                <table class="table table-sm align-middle">
                                                    <thead class="table-light">
                                                        <tr><th>Alasan</th><th class="text-center" style="width:4rem;">Vendor</th></tr>
                                                    </thead>
                                                    <tbody>
                                                    <?php foreach ($q['reasons'] as $r): ?>
                                    <tr>
                                                            <td>
                                                                “<?= htmlspecialchars($r['text']) ?>”
                                                                    <div class="small text-muted"><?= htmlspecialchars(implode(', ', $r['vendors'])) ?></div>
                                                                    </td>
                                                                <td class="text-center pa-num"><?= $r['n'] ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                    </tbody>
                                                    </table>
                                                <?php else: ?>
                                            <p class="text-muted fst-italic small mb-0">
                                                Pertanyaan ini hanya berupa pilihan skor — vendor tidak mengisi teks bebas apa pun.
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endforeach; ?>

    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Chip checkboxes: keep the pill styling in sync with the box.
    document.querySelectorAll('.pa-chip input').forEach(function (box) {
        box.addEventListener('change', function () {
            box.closest('.pa-chip').classList.toggle('is-on', box.checked);
        });
    });

    var btn = document.getElementById('paExportCsv');
    if (!btn) { return; }

    var ROWS = <?= json_encode($pa_flat, JSON_UNESCAPED_UNICODE) ?: '[]' ?>;

    btn.addEventListener('click', function () {
        if (!ROWS.length) { return; }
        var headers = Object.keys(ROWS[0]);
        var esc = function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; };
        var csv = [headers.map(esc).join(',')]
            .concat(ROWS.map(function (r) { return headers.map(function (h) { return esc(r[h]); }).join(','); }))
            .join('\r\n');

        // BOM so Excel opens the Indonesian text and quotes correctly.
        var url  = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
        var a    = document.createElement('a');
        a.href     = url;
        a.download = 'pq-risk-analysis-' + new Date().toISOString().slice(0, 10) + '.csv';
        a.click();
        URL.revokeObjectURL(url);
    });
});
</script>
