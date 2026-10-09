<?php
/**
 * tools/seed_vendor_eval_v2_demo_data.php
 *
 * LOCAL-ONLY demo-data seeder for Vendor Evaluation v2 (Form 2, Project Retail).
 * Fabricates realistic *completed* (both OPS + PROJECT) evaluations against REAL
 * existing new-store POs/vendors — PGroups A97/T97 by default, or any set via
 * --pgroups=X97,Y99 — so a Technical Department account's results dashboard
 * (vendor_eval_results_tech.php) looks populated for visual testing. It NEVER
 * invents POs or vendors — it only adds vendor_eval_submissions +
 * vendor_eval_answers rows for POs that currently have none, plus (only when
 * A97 is in the target set) optionally completes the one half-done PO 4200278639.
 *
 * SAFETY (this is a WRITE tool — treat as such):
 *   - Dry-run is the DEFAULT. It only SELECTs and prints a plan; no writes.
 *   - Writing requires ALL of:  --apply  --confirm-db  --confirm-demo
 *   - Identity gate: refuses unless DATABASE()=u894129685_workspace on MySQL 8.
 *   - Before applying, it backs up vendor_eval_submissions + vendor_eval_answers
 *     into db_backups/local_runtime_backups/ (gitignored) and writes a manifest
 *     of inserted submission ids for one-command rollback.
 *   - --rollback=<manifest.json> deletes exactly what a prior --apply inserted
 *     (answers cascade via FK). No secrets are ever printed.
 *
 * Run inside the app container for PHP/extension parity:
 *   docker-compose exec app php tools/seed_vendor_eval_v2_demo_data.php            # dry-run
 *   docker-compose exec app php tools/seed_vendor_eval_v2_demo_data.php --count=15 # dry-run, sized
 *   docker-compose exec app php tools/seed_vendor_eval_v2_demo_data.php --apply --confirm-db --confirm-demo
 *   docker-compose exec app php tools/seed_vendor_eval_v2_demo_data.php --rollback=db_backups/local_runtime_backups/seed_demo_manifest_XXXX.json --confirm-db
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// ── args ──────────────────────────────────────────────────────────────────────
$opts = getopt('', ['apply', 'confirm-db', 'confirm-demo', 'count::', 'seed::', 'rollback::', 'pgroups::']);
$APPLY        = isset($opts['apply']);
$CONFIRM_DB   = isset($opts['confirm-db']);
$CONFIRM_DEMO = isset($opts['confirm-demo']);
$COUNT        = isset($opts['count']) ? max(1, (int)$opts['count']) : 15;
$SEED         = isset($opts['seed']) ? (int)$opts['seed'] : 20260814;
$ROLLBACK     = isset($opts['rollback']) ? (string)$opts['rollback'] : '';
$PGROUPS      = isset($opts['pgroups'])
    ? array_values(array_filter(array_map('trim', explode(',', (string)$opts['pgroups']))))
    : ['A97', 'T97'];

$REPO       = dirname(__DIR__);
$BACKUP_DIR = $REPO . '/db_backups/local_runtime_backups';

function out(string $s = ''): void { fwrite(STDOUT, $s . "\n"); }
function err(string $s): void { fwrite(STDERR, $s . "\n"); }

// ── connect (direct PDO; avoids database.php include-time auto-migrations) ─────
$pw = null;
foreach (file($REPO . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
    if (strpos($line, 'WORKSPACE_DB_PASSWORD=') === 0) {
        $pw = substr($line, strlen('WORKSPACE_DB_PASSWORD='));
        break;
    }
}
if ($pw === null) { err('Could not read WORKSPACE_DB_PASSWORD from .env'); exit(1); }
$host = getenv('DB_HOST') ?: 'db';
try {
    $pdo = new PDO(
        "mysql:host={$host};dbname=u894129685_workspace;charset=utf8mb4",
        'u894129685_workspace007',
        $pw,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (Throwable $e) {
    err('DB connect failed: ' . $e->getMessage());
    exit(1);
}

// ── identity gate ─────────────────────────────────────────────────────────────
$id = $pdo->query('SELECT DATABASE() db, CURRENT_USER() usr, VERSION() ver')->fetch();
out('── Identity ──────────────────────────────────────────');
out("  DATABASE = {$id['db']}");
out("  USER     = {$id['usr']}");
out("  VERSION  = {$id['ver']}");
out('');
if ($id['db'] !== 'u894129685_workspace') { err('ABORT: not the local workspace DB.'); exit(1); }
if (strpos((string)$id['ver'], '8.') !== 0) { err('ABORT: expected MySQL 8.x.'); exit(1); }

// A/B/C/D band from a total score (mirrors computeScoreBand / ve2_score_grade).
function band(float $t): string { return $t >= 3.5 ? 'A' : ($t >= 2.5 ? 'B' : ($t >= 1.5 ? 'C' : 'D')); }

// ══════════════════════════════════════════════════════════════════════════════
// ROLLBACK MODE
// ══════════════════════════════════════════════════════════════════════════════
if ($ROLLBACK !== '') {
    $path = $ROLLBACK;
    if (!is_file($path)) { $path = $REPO . '/' . ltrim($ROLLBACK, '/'); }
    if (!is_file($path)) { err("Manifest not found: {$ROLLBACK}"); exit(1); }
    $manifest = json_decode((string)file_get_contents($path), true);
    $ids = array_values(array_filter(array_map('intval', $manifest['inserted_submission_ids'] ?? [])));
    if (!$ids) { err('Manifest lists no submission ids.'); exit(1); }
    out('ROLLBACK: ' . count($ids) . ' fabricated submissions from ' . basename($path));
    if (!$CONFIRM_DB) { out('  (dry-run — pass --confirm-db to actually delete. Answers cascade.)'); exit(0); }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM vendor_eval_submissions WHERE id IN ($ph)");
        $del->execute($ids);
        $pdo->commit();
        out('  Deleted ' . $del->rowCount() . ' submissions (+ their answers via cascade).');
    } catch (Throwable $e) {
        $pdo->rollBack();
        err('Rollback failed: ' . $e->getMessage());
        exit(1);
    }
    exit(0);
}

// ══════════════════════════════════════════════════════════════════════════════
// SEED MODE
// ══════════════════════════════════════════════════════════════════════════════
mt_srand($SEED); // deterministic: dry-run preview == apply result

// Question set 19 (VE_PROJECT_RETAIL_V1) is what Form 2 always uses.
$SET_ID = 19;
$qrows = $pdo->query("
    SELECT id, question_no, question_type, question_section, criterion_name, question_text,
           weight, score_1_label, score_2_label, score_3_label, score_4_label
      FROM vendor_eval_questions
     WHERE question_set_id = {$SET_ID}
     ORDER BY question_no
")->fetchAll();
if (!$qrows) { err("Question set {$SET_ID} has no questions."); exit(1); }
$rating_qs = array_values(array_filter($qrows, static fn($q) => $q['question_type'] === 'rating'));

/** Assign integer scores (1..4) to the rating questions so the weighted total lands in $targetBand.
 *  Returns [scoresByQuestionId, total]. Falls back to all-base (guaranteed in-band). */
function scoreForBand(string $targetBand, array $rating_qs): array
{
    $base = ['A' => 4, 'B' => 3, 'C' => 2, 'D' => 1][$targetBand];
    for ($attempt = 0; $attempt < 16; $attempt++) {
        $scores = [];
        $total  = 0.0;
        foreach ($rating_qs as $q) {
            $r = mt_rand(0, 99);
            $d = $r < 20 ? -1 : ($r < 40 ? 1 : 0);
            $s = max(1, min(4, $base + $d));
            $scores[(int)$q['id']] = $s;
            $total += $s * (float)$q['weight'];
        }
        $total = round($total, 4);
        if (band($total) === $targetBand) {
            return [$scores, $total];
        }
    }
    $scores = [];
    foreach ($rating_qs as $q) { $scores[(int)$q['id']] = $base; }
    return [$scores, round((float)$base, 4)]; // weights sum to 1.0 → total == base
}

// Build the full 12-answer row set for a submission given rating scores + filler name.
function buildAnswers(array $qrows, array $scores, string $fillerName): array
{
    $comments = [
        'Pekerjaan sesuai jadwal dan mutu.', 'Koordinasi lapangan baik.', 'Ada beberapa catatan minor.',
        'Perlu peningkatan di administrasi.', 'Respon cepat terhadap instruksi.', '',
    ];
    $rows = [];
    foreach ($qrows as $q) {
        $qid = (int)$q['id'];
        if ($q['question_section'] === 'identification') {
            $rows[] = [
                'question_id' => $qid, 'question_no' => (int)$q['question_no'],
                'criterion_name' => (string)$q['criterion_name'], 'question_text' => (string)$q['question_text'],
                'score' => null, 'selected_score_label' => mb_substr($fillerName, 0, 255),
                'weight' => '0.0000', 'weighted_score' => '0.0000', 'comment' => $fillerName,
            ];
        } elseif ($q['question_type'] === 'text') {
            $rows[] = [
                'question_id' => $qid, 'question_no' => (int)$q['question_no'],
                'criterion_name' => (string)$q['criterion_name'], 'question_text' => (string)$q['question_text'],
                'score' => null, 'selected_score_label' => '',
                'weight' => '0.0000', 'weighted_score' => '0.0000',
                'comment' => 'Evaluasi kinerja vendor terdokumentasi (data simulasi).',
            ];
        } else {
            $s = $scores[$qid];
            $w = (float)$q['weight'];
            $rows[] = [
                'question_id' => $qid, 'question_no' => (int)$q['question_no'],
                'criterion_name' => (string)$q['criterion_name'], 'question_text' => (string)$q['question_text'],
                'score' => $s, 'selected_score_label' => (string)($q['score_' . $s . '_label'] ?? (string)$s),
                'weight' => number_format($w, 4, '.', ''),
                'weighted_score' => number_format(round($s * $w, 4), 4, '.', ''),
                'comment' => ($comments[mt_rand(0, count($comments) - 1)] ?: null),
            ];
        }
    }
    return $rows;
}

// Pick candidate POs: real new-store POs (in the target PGroups) with a vendor
// and NO submissions yet.
$pgPh = implode(',', array_fill(0, count($PGROUPS), '?'));
$candStmt = $pdo->prepare("
    SELECT p.po_number, MAX(p.vendor_name) AS vendor_name, MAX(p.p_group) AS p_group
      FROM prs p
     WHERE p.p_group IN ($pgPh) AND p.is_new_store = 1
       AND p.po_number IS NOT NULL AND p.po_number <> ''
       AND p.vendor_name IS NOT NULL AND p.vendor_name <> ''
       AND p.po_number NOT IN (SELECT DISTINCT po_number FROM vendor_eval_submissions WHERE po_number IS NOT NULL)
     GROUP BY p.po_number
     ORDER BY p.po_number
");
$candStmt->execute($PGROUPS);
$cands = $candStmt->fetchAll();

if (!$cands) { err('No candidate POs available (all ' . implode('/', $PGROUPS) . ' new-store POs already have submissions?).'); exit(1); }

// Round-robin across distinct vendors so the sample spans many vendors.
$byVendor = [];
foreach ($cands as $c) { $byVendor[(string)$c['vendor_name']][] = $c; }
$vendors = array_keys($byVendor);
sort($vendors);
$picked = [];
$vi = 0;
while (count($picked) < $COUNT) {
    $progress = false;
    foreach ($vendors as $v) {
        if (count($picked) >= $COUNT) break;
        if (!empty($byVendor[$v])) { $picked[] = array_shift($byVendor[$v]); $progress = true; }
    }
    if (!$progress) break; // exhausted all candidates
    $vi++;
}

// Grade plan: cycle a natural mix (more B/C than A/D).
$pattern = ['B', 'A', 'C', 'B', 'C', 'D', 'B', 'A', 'C', 'B', 'B', 'C', 'A', 'D', 'B'];

// Date spread: ~1/3 in 2025 (so all-time > YTD), rest across 2026 Jan–Aug.
function spreadDate(int $i, int $n): string
{
    if ($i % 3 === 0) {
        $start = strtotime('2025-11-05');
        $end   = strtotime('2025-12-20');
    } else {
        $start = strtotime('2026-01-10');
        $end   = strtotime('2026-08-05');
    }
    $frac = $n > 1 ? $i / ($n - 1) : 0;
    $ts   = (int)round($start + ($end - $start) * $frac);
    return date('Y-m-d', $ts);
}

// Assemble the plan (one entry per PO: two team submissions).
$TEAMS = ['ops', 'project'];
$plan  = [];
$n = count($picked);
foreach ($picked as $i => $c) {
    $po     = (string)$c['po_number'];
    $vendor = (string)$c['vendor_name'];
    $pg     = (string)$c['p_group'];
    $target = $pattern[$i % count($pattern)];
    $baseDate = spreadDate($i, $n);

    $teams = [];
    foreach ($TEAMS as $t) {
        [$scores, $total] = scoreForBand($target, $rating_qs);
        // Stagger submit datetimes; ops usually a few days before project.
        $offsetDays = $t === 'ops' ? -mt_rand(1, 6) : 0;
        $dt = date('Y-m-d H:i:s', strtotime($baseDate . ' 10:00:00') + $offsetDays * 86400 + mt_rand(0, 6) * 3600);
        $filler = ($t === 'ops' ? 'Demo OPS' : 'Demo PRJ');
        $teams[$t] = [
            'total'   => $total,
            'band'    => band($total),
            'answers' => buildAnswers($qrows, $scores, $filler),
            'filler'  => $filler,
            'dt'      => $dt,
        ];
    }
    $final = round(($teams['ops']['total'] + $teams['project']['total']) / 2, 4);
    $plan[] = [
        'po_number' => $po, 'vendor_name' => $vendor, 'p_group' => $pg,
        'target' => $target, 'teams' => $teams, 'final' => $final, 'final_grade' => band($final),
        'complete_partial' => false,
    ];
}

// Optional: complete the one existing half-done PO (4200278639, project only,
// PGroup A97) by adding OPS — only relevant when A97 is in the target set.
$PARTIAL_PO = '4200278639';
$partial_by_team = [];
if (in_array('A97', $PGROUPS, true)) {
    $partialRow = $pdo->prepare("SELECT vendor_name, taxonomy_level_2, taxonomy_level_3, team, total_score
                                   FROM vendor_eval_submissions WHERE po_number = ? AND status='submitted'");
    $partialRow->execute([$PARTIAL_PO]);
    foreach ($partialRow->fetchAll() as $r) { if ($r['team'] !== null) $partial_by_team[$r['team']] = $r; }
}
if (isset($partial_by_team['project']) && !isset($partial_by_team['ops'])) {
    $pv = $partial_by_team['project'];
    [$scores, $total] = scoreForBand('C', $rating_qs); // project is A(4.0) → final ≈ (4.0+2.0)/2 = 3.0 (B)
    $dt = date('Y-m-d H:i:s', strtotime('2026-07-20 09:30:00'));
    $projTotal = (float)$pv['total_score'];
    $final = round(($projTotal + $total) / 2, 4);
    $plan[] = [
        'po_number' => $PARTIAL_PO, 'vendor_name' => (string)$pv['vendor_name'], 'p_group' => 'A97',
        'target' => 'C', 'final' => $final, 'final_grade' => band($final), 'complete_partial' => true,
        'teams' => ['ops' => [
            'total' => $total, 'band' => band($total),
            'answers' => buildAnswers($qrows, $scores, 'Demo OPS'), 'filler' => 'Demo OPS', 'dt' => $dt,
        ]],
        'l2' => $pv['taxonomy_level_2'], 'l3' => $pv['taxonomy_level_3'],
    ];
}

// ── print the plan ────────────────────────────────────────────────────────────
out('── Seed plan ─────────────────────────────────────────');
out(sprintf('  %-13s %-30s %-5s %-5s %-5s %-5s', 'PO', 'Vendor', 'PGrp', 'OPS', 'PRJ', 'FINAL'));
$dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0];
foreach ($plan as $p) {
    $opsT = isset($p['teams']['ops']) ? number_format($p['teams']['ops']['total'], 2) : '—';
    $prjT = isset($p['teams']['project']) ? number_format($p['teams']['project']['total'], 2) : '(exist)';
    out(sprintf('  %-13s %-30s %-5s %-5s %-5s %s%s',
        $p['po_number'], mb_substr($p['vendor_name'], 0, 30), $p['p_group'],
        $opsT, $prjT, $p['final_grade'] . ' ' . number_format($p['final'], 2),
        $p['complete_partial'] ? '  [completes partial]' : ''));
    $dist[$p['final_grade']]++;
}
out('');
out("  Total POs to seed: " . count($plan) . "  |  final-grade mix: A={$dist['A']} B={$dist['B']} C={$dist['C']} D={$dist['D']}");
out('');

if (!$APPLY) {
    out('DRY-RUN — no rows written. To apply:');
    out('  ... --apply --confirm-db --confirm-demo');
    exit(0);
}
if (!$CONFIRM_DB || !$CONFIRM_DEMO) {
    err('ABORT: --apply requires BOTH --confirm-db AND --confirm-demo (guards against running on a real/shared DB).');
    exit(1);
}

// ── backup before write ───────────────────────────────────────────────────────
if (!is_dir($BACKUP_DIR)) { mkdir($BACKUP_DIR, 0777, true); }
$stamp = date('Ymd_His');
function dumpTable(PDO $pdo, string $table, string $path): int
{
    $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
    $fh = fopen($path, 'w');
    fwrite($fh, "-- backup of $table (" . count($rows) . " rows)\n");
    foreach ($rows as $r) {
        $cols = array_map(static fn($c) => "`$c`", array_keys($r));
        $vals = array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r));
        fwrite($fh, "INSERT INTO `$table` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ");\n");
    }
    fclose($fh);
    return count($rows);
}
$b1 = dumpTable($pdo, 'vendor_eval_submissions', "{$BACKUP_DIR}/seed_demo_pre_submissions_{$stamp}.sql");
$b2 = dumpTable($pdo, 'vendor_eval_answers', "{$BACKUP_DIR}/seed_demo_pre_answers_{$stamp}.sql");
out("Backed up vendor_eval_submissions ({$b1}) + vendor_eval_answers ({$b2}) to {$BACKUP_DIR}");

// ── write ─────────────────────────────────────────────────────────────────────
$ins_sub = $pdo->prepare("
    INSERT INTO vendor_eval_submissions
        (po_number, vendor_name, requestor_name, team, question_set_id,
         taxonomy_level_2, taxonomy_level_3, total_score, score_band, status, submitted_at, updated_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?)
");
$ins_ans = $pdo->prepare("
    INSERT INTO vendor_eval_answers
        (submission_id, question_id, question_no, criterion_name, question_text,
         score, selected_score_label, weight, weighted_score, comment)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$inserted_ids = [];
$po_done = 0;
$skipped = 0;
foreach ($plan as $p) {
    $pdo->beginTransaction();
    try {
        foreach ($p['teams'] as $team => $t) {
            $l2 = $p['complete_partial'] ? ($p['l2'] ?? null) : null;
            $l3 = $p['complete_partial'] ? ($p['l3'] ?? null) : null;
            $ins_sub->execute([
                $p['po_number'], $p['vendor_name'], $t['filler'], $team, 19,
                $l2, $l3, number_format($t['total'], 4, '.', ''), $t['band'], $t['dt'], $t['dt'],
            ]);
            $sid = (int)$pdo->lastInsertId();
            $inserted_ids[] = $sid;
            foreach ($t['answers'] as $a) {
                $ins_ans->execute([
                    $sid, $a['question_id'], $a['question_no'], $a['criterion_name'], $a['question_text'],
                    $a['score'], $a['selected_score_label'], $a['weight'], $a['weighted_score'], $a['comment'],
                ]);
            }
        }
        $pdo->commit();
        $po_done++;
    } catch (PDOException $e) {
        $pdo->rollBack();
        // Unique-key clash (uq_po_team) => already seeded; skip idempotently.
        if (strpos((string)$e->getCode(), '23') === 0) { $skipped++; continue; }
        err('Insert failed for PO ' . $p['po_number'] . ': ' . $e->getMessage());
        break;
    }
}

// ── manifest for rollback ─────────────────────────────────────────────────────
$manifestPath = "{$BACKUP_DIR}/seed_demo_manifest_{$stamp}.json";
file_put_contents($manifestPath, json_encode([
    'stamp' => $stamp, 'count_pos' => $po_done, 'inserted_submission_ids' => $inserted_ids,
], JSON_PRETTY_PRINT));

out('');
out("APPLIED: {$po_done} PO(s) seeded, {$skipped} skipped (already present), " . count($inserted_ids) . " submissions inserted.");
out("Rollback with:  php tools/seed_vendor_eval_v2_demo_data.php --rollback=" . str_replace($REPO . '/', '', $manifestPath) . " --confirm-db");
