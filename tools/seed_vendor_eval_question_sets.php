<?php
// tools/seed_vendor_eval_question_sets.php
//
// Vendor Evaluation v2 — dynamic question-set SEEDER / IMPORTER (CLI only).
//
// Validates a business-provided CSV of v2 question sets, their weighted
// questions, and their taxonomy routing BEFORE anything is written to the DB.
//
// Two modes:
//   DRY-RUN (default)  Parse + validate the CSV end-to-end and print a report.
//                      Reads `v_item_taxonomy` (SELECT only) to validate
//                      taxonomy values. Writes NOTHING to the DB.
//   APPLY (--apply)    Guarded insert into the v2 schema inside a transaction.
//                      Heavily gated (see SAFETY) and NOT exercised in this step.
//
// Usage:
//   C:\xampp\php\php.exe tools/seed_vendor_eval_question_sets.php \
//       --file=path/to/question_sets.csv [--dry-run]
//
//   C:\xampp\php\php.exe tools/seed_vendor_eval_question_sets.php \
//       --file=... --apply --confirm-db=DATABASE_NAME [--deactivate-old]
//
//   --file=PATH        REQUIRED. Path to the question-sets CSV.
//   --dry-run          Explicit dry-run (this is also the DEFAULT mode).
//   --apply            Perform the import. Requires --confirm-db. Aborts unless
//                      validation passes AND the v2 schema tables exist.
//   --confirm-db=NAME  Must equal the live `SELECT DATABASE()` name. Required
//                      with --apply. A typo-guard against the wrong database.
//   --deactivate-old   (parsed, reserved) Allow superseding an existing active
//                      set_code by deactivating its prior versions on apply.
//
// SAFETY:
//   - CLI only (refuses browser/web access).
//   - DRY-RUN issues SELECT-only queries. No INSERT/UPDATE/DELETE.
//   - APPLY is the ONLY path that writes, and only when --apply AND a matching
//     --confirm-db are BOTH present, validation passed, and the v2 tables exist.
//   - Never prints DB password or secrets (only the database NAME, for the gate).
//   - Does not touch any v1 Vendor Evaluation files or the `vendor_evaluations`
//     table. Does not run the v2 schema SQL (assumes it was applied separately).

// ── CLI guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/seed_vendor_eval_question_sets.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

const SENTINEL_ANY = '**ANY**';

// The exact, ordered set of headers the importer understands.
$REQUIRED_HEADERS = [
    'question_set_code', 'question_set_name', 'version', 'map_level',
    'taxonomy_level_2', 'taxonomy_level_3', 'question_no', 'criterion_name',
    'question_text', 'weight', 'score_1_label', 'score_2_label',
    'score_3_label', 'score_4_label', 'comment_required', 'is_active',
    'description',
];

// The four v2 tables APPLY writes into; all must exist before apply.
$V2_WRITE_TABLES = [
    'vendor_eval_question_sets',
    'vendor_eval_questions',
    'vendor_eval_question_set_taxonomy_map',
    'vendor_eval_question_import_batches',
];

// ── Argument parsing ────────────────────────────────────────────────────────
$argv_in            = $argv ?? [];
$opt_apply          = in_array('--apply', $argv_in, true);
$opt_dry_run        = in_array('--dry-run', $argv_in, true);
$opt_deactivate_old = in_array('--deactivate-old', $argv_in, true);

$opt_file       = null;
$opt_confirm_db = null;
foreach ($argv_in as $a) {
    if (preg_match('/^--file=(.+)$/', $a, $m))         { $opt_file = $m[1]; }
    elseif ($a === '--file')                           { exit("ERROR: --file requires a value, e.g. --file=path/to/question_sets.csv\n"); }
    elseif (preg_match('/^--confirm-db=(.+)$/', $a, $m)) { $opt_confirm_db = $m[1]; }
    elseif ($a === '--confirm-db')                     { exit("ERROR: --confirm-db requires a value, e.g. --confirm-db=u894129685_workspace\n"); }
}

if ($opt_file === null || trim($opt_file) === '') {
    exit("\nERROR: --file=PATH is required.\n"
       . "  e.g. --file=db_backups/local_runtime_imports/vendor_eval_question_sets_sample.csv\n\n");
}
$mode = $opt_apply ? 'apply' : 'dry-run';

// Resolve the CSV path (allow relative-to-project paths).
$root = dirname(__DIR__);
$csv_path = $opt_file;
if (!is_file($csv_path)) {
    $alt = $root . '/' . ltrim($opt_file, '/\\');
    if (is_file($alt)) $csv_path = $alt;
}
if (!is_file($csv_path)) {
    exit("\nERROR: CSV file not found: {$opt_file}\n\n");
}

// ── Bootstrap DB (SELECT-only use in dry-run) ───────────────────────────────
// Mirror the mapper: set REQUEST_URI (not HTTP_HOST) so database.php selects
// local credentials without tripping its browser guard. This file is read-only;
// database.php's own idempotent auto-migrations are a pre-existing side effect
// of the shared bootstrap and are unrelated to the v2 schema.
$_SERVER['REQUEST_URI'] = '';
define('RUNNING_AS_CLI_TOOL', true);
require_once $root . '/workspace/config/database.php';   // provides $pdo (PDO)

$current_db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

echo "\n";
echo "=======================================================================\n";
echo " Vendor Evaluation v2 — Question-Set Seeder / Importer\n";
echo " " . date('Y-m-d H:i:s') . "   mode: " . strtoupper($mode) . "\n";
echo "=======================================================================\n\n";
echo "Database (name only): {$current_db}\n";
echo "CSV file           : {$csv_path}\n\n";

// ── Helpers ─────────────────────────────────────────────────────────────────
function normalize_text($s): string {
    // Mirrors tools/run_po_taxonomy_mapping.php so taxonomy matching is identical.
    $s = strtoupper((string)$s);
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}
function is_pos_int($v): bool {
    $v = trim((string)$v);
    return $v !== '' && preg_match('/^\d+$/', $v) && (int)$v >= 1;
}
function is_zero_one($v): bool {
    $v = trim((string)$v);
    return $v === '0' || $v === '1';
}
function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ?"
    );
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

// ── Load taxonomy allow-list from v_item_taxonomy (SELECT only) ─────────────
echo "Loading taxonomy allow-list from v_item_taxonomy...\n";
$valid_l2    = [];   // norm(L2)            => canonical L2 string
$valid_l2_l3 = [];   // norm(L2) => norm(L3) => canonical L3 string
$tax_rows = $pdo->query("SELECT level_2, level_3 FROM v_item_taxonomy")->fetchAll(PDO::FETCH_ASSOC);
foreach ($tax_rows as $r) {
    $l2 = trim((string)($r['level_2'] ?? ''));
    $l3 = trim((string)($r['level_3'] ?? ''));
    if ($l2 === '') continue;
    $n2 = normalize_text($l2);
    if (!isset($valid_l2[$n2])) $valid_l2[$n2] = $l2;
    if ($l3 !== '') {
        $n3 = normalize_text($l3);
        if (!isset($valid_l2_l3[$n2][$n3])) $valid_l2_l3[$n2][$n3] = $l3;
    }
}
printf("  loaded %d taxonomy rows (%d distinct Sub Categories).\n\n",
    count($tax_rows), count($valid_l2));

// ── Read CSV ────────────────────────────────────────────────────────────────
$fh = fopen($csv_path, 'r');
if ($fh === false) exit("ERROR: cannot open CSV for reading: {$csv_path}\n");
$bom = fread($fh, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($fh);     // skip UTF-8 BOM if present

$header = fgetcsv($fh);
if ($header === false) { fclose($fh); exit("ERROR: CSV is empty.\n"); }
$header = array_map(fn($h) => trim((string)$h), $header);

$errors   = [];   // hard failures (block apply, fail dry-run)
$warnings = [];   // advisory only

// Rule 1: required headers must exist exactly.
$missing = array_values(array_diff($REQUIRED_HEADERS, $header));
$extra   = array_values(array_diff($header, $REQUIRED_HEADERS));
if (!empty($missing)) {
    $errors[] = 'Missing required header(s): ' . implode(', ', $missing);
}
if (!empty($extra)) {
    $warnings[] = 'Unexpected extra column(s) ignored: ' . implode(', ', $extra);
}
$Hx = array_flip($header);
function cell(array $row, array $Hx, string $key): string {
    return isset($Hx[$key]) && array_key_exists($Hx[$key], $row)
        ? trim((string)$row[$Hx[$key]]) : '';
}

// If headers are unusable, stop here — row-level checks would be meaningless.
if (!empty($missing)) {
    fclose($fh);
    print_report(0, [], [], $errors, $warnings, [], []);
    finish(false);
}

// ── Parse rows ──────────────────────────────────────────────────────────────
$rows = [];           // raw parsed data rows (assoc by header)
$line_no = 1;         // header was line 1
while (($r = fgetcsv($fh)) !== false) {
    $line_no++;
    if (count($r) === 1 && trim((string)$r[0]) === '') continue;   // skip blank line
    $rows[] = ['__line' => $line_no, 'data' => $r];
}
fclose($fh);

$total_rows = count($rows);

// ── Row-level validation + sentinel normalization ───────────────────────────
// Grouped structures:
//   $sets[key]            => set-level fields (validated for consistency)
//   $questions_by_set[key]=> list of question rows
//   $mapping_by_set[key]  => normalized taxonomy mapping for the set
$sets            = [];
$questions_by_set = [];
$seen_qno        = [];   // key => [question_no => first line]
$set_key = fn($code, $ver) => $code . '||' . $ver;

foreach ($rows as $row) {
    $L = $row['__line'];
    $d = $row['data'];

    $code = cell($d, $Hx, 'question_set_code');
    $name = cell($d, $Hx, 'question_set_name');
    $ver  = cell($d, $Hx, 'version');
    $map  = strtolower(cell($d, $Hx, 'map_level'));
    $l2   = cell($d, $Hx, 'taxonomy_level_2');
    $l3   = cell($d, $Hx, 'taxonomy_level_3');
    $qno  = cell($d, $Hx, 'question_no');
    $crit = cell($d, $Hx, 'criterion_name');
    $qtxt = cell($d, $Hx, 'question_text');
    $wt   = cell($d, $Hx, 'weight');
    $s1   = cell($d, $Hx, 'score_1_label');
    $s2   = cell($d, $Hx, 'score_2_label');
    $s3   = cell($d, $Hx, 'score_3_label');
    $s4   = cell($d, $Hx, 'score_4_label');
    $creq = cell($d, $Hx, 'comment_required');
    $act  = cell($d, $Hx, 'is_active');
    $desc = cell($d, $Hx, 'description');

    // Rule 2/3
    if ($code === '') { $errors[] = "L{$L}: question_set_code is blank"; continue; }
    if ($name === '') { $errors[] = "L{$L}: question_set_name is blank"; }
    // Rule 4
    if (!is_pos_int($ver)) { $errors[] = "L{$L}: version must be a positive integer (got '{$ver}')"; }
    // Rule 5
    if (!in_array($map, ['l3', 'l2', 'default'], true)) {
        $errors[] = "L{$L}: map_level must be one of l3|l2|default (got '{$map}')";
    }
    // Rule 8
    if (!is_pos_int($qno)) { $errors[] = "L{$L}: question_no must be a positive integer (got '{$qno}')"; }
    // Rule 10/11
    if ($crit === '') { $errors[] = "L{$L}: criterion_name is blank"; }
    if ($qtxt === '') { $errors[] = "L{$L}: question_text is blank"; }
    // Rule 12 — weight decimal > 0
    if (!is_numeric($wt) || (float)$wt <= 0) {
        $errors[] = "L{$L}: weight must be a decimal > 0 (got '{$wt}')";
    }
    // Rule 14 — score labels
    foreach (['score_1_label'=>$s1,'score_2_label'=>$s2,'score_3_label'=>$s3,'score_4_label'=>$s4] as $lbl=>$v) {
        if ($v === '') $errors[] = "L{$L}: {$lbl} is blank";
    }
    // Rule 15/16
    if (!is_zero_one($creq)) { $errors[] = "L{$L}: comment_required must be 0 or 1 (got '{$creq}')"; }
    if (!is_zero_one($act))  { $errors[] = "L{$L}: is_active must be 0 or 1 (got '{$act}')"; }

    // ── Rule 6 — sentinel normalization by map_level ──
    $norm_l2 = $l2;
    $norm_l3 = $l3;
    $map_note = '';
    if ($map === 'default') {
        if ($l2 !== '' && $l2 !== SENTINEL_ANY) $warnings[] = "L{$L}: map_level=default — taxonomy_level_2 '{$l2}' ignored, forced to ".SENTINEL_ANY;
        if ($l3 !== '' && $l3 !== SENTINEL_ANY) $warnings[] = "L{$L}: map_level=default — taxonomy_level_3 '{$l3}' ignored, forced to ".SENTINEL_ANY;
        $norm_l2 = SENTINEL_ANY;
        $norm_l3 = SENTINEL_ANY;
    } elseif ($map === 'l2') {
        if ($l3 !== '' && $l3 !== SENTINEL_ANY) $warnings[] = "L{$L}: map_level=l2 — taxonomy_level_3 '{$l3}' ignored, forced to ".SENTINEL_ANY;
        $norm_l3 = SENTINEL_ANY;
        // Rule 7 — L2 must be a real Sub Category
        $n2 = normalize_text($l2);
        if ($l2 === '' || !isset($valid_l2[$n2])) {
            $errors[] = "L{$L}: map_level=l2 requires a real taxonomy_level_2 (got '{$l2}')";
        } else {
            $norm_l2 = $valid_l2[$n2];   // canonicalize spelling
        }
    } elseif ($map === 'l3') {
        // Rule 7 — L2 real AND L3 real under that L2
        $n2 = normalize_text($l2);
        if ($l2 === '' || !isset($valid_l2[$n2])) {
            $errors[] = "L{$L}: map_level=l3 requires a real taxonomy_level_2 (got '{$l2}')";
        } else {
            $norm_l2 = $valid_l2[$n2];
            $n3 = normalize_text($l3);
            if ($l3 === '' || !isset($valid_l2_l3[$n2][$n3])) {
                $errors[] = "L{$L}: map_level=l3 requires a real taxonomy_level_3 under '{$norm_l2}' (got '{$l3}')";
            } else {
                $norm_l3 = $valid_l2_l3[$n2][$n3];
            }
        }
    }

    if (!is_pos_int($ver) || !is_pos_int($qno)) continue;   // can't group safely
    $key = $set_key($code, (int)$ver);

    // Rule 17 — duplicate set_code+version+question_no
    if (isset($seen_qno[$key][(int)$qno])) {
        $errors[] = "L{$L}: duplicate question_no {$qno} for set '{$code}' v{$ver} "
                  . "(first seen on L{$seen_qno[$key][(int)$qno]})";
    } else {
        $seen_qno[$key][(int)$qno] = $L;
    }

    // Set-level capture + consistency (hardening beyond the listed rules:
    // set-level columns must agree across all rows of the same set+version).
    $set_fields = [
        'set_code'    => $code,
        'version'     => (int)$ver,
        'set_name'    => $name,
        'is_active'   => is_zero_one($act) ? (int)$act : 1,
        'description' => $desc,
        'map_level'   => $map,
        'l2'          => $norm_l2,
        'l3'          => $norm_l3,
    ];
    if (!isset($sets[$key])) {
        $sets[$key] = $set_fields + ['__line' => $L];
    } else {
        foreach (['set_name','is_active','description','map_level','l2','l3'] as $f) {
            if ((string)$sets[$key][$f] !== (string)$set_fields[$f]) {
                $errors[] = "L{$L}: set '{$code}' v{$ver} has inconsistent {$f} "
                          . "('{$set_fields[$f]}' vs earlier '{$sets[$key][$f]}')";
            }
        }
    }

    $questions_by_set[$key][] = [
        'question_no'      => (int)$qno,
        'criterion_name'   => $crit,
        'question_text'    => $qtxt,
        'weight'           => (float)$wt,
        'weight_raw'       => $wt,
        'score_1_label'    => $s1,
        'score_2_label'    => $s2,
        'score_3_label'    => $s3,
        'score_4_label'    => $s4,
        'comment_required' => is_zero_one($creq) ? (int)$creq : 0,
        'is_active'        => is_zero_one($act) ? (int)$act : 1,
        'line'             => $L,
    ];
}

// ── Per-set validation: weight sum (13) + sequential question_no (9) ─────────
$weight_sums = [];   // key => float sum (for report)
foreach ($questions_by_set as $key => $qs) {
    [$code, $ver] = explode('||', $key);

    // Rule 13 — SUM(weight) == 1.0000 (±0.0001). Sum in ten-thousandths to
    // avoid binary float drift.
    $units = 0;
    foreach ($qs as $q) $units += (int)round($q['weight'] * 10000);
    $weight_sums[$key] = $units / 10000;
    if (abs($units - 10000) > 1) {   // tolerance ±0.0001
        $errors[] = sprintf("Set '%s' v%s: SUM(weight)=%.4f, must be 1.0000 (±0.0001)",
            $code, $ver, $units / 10000);
    }

    // Rule 9 — question_no sequential 1..N with no gaps.
    $nos = array_map(fn($q) => $q['question_no'], $qs);
    sort($nos);
    $expected = range(1, count($nos));
    if ($nos !== $expected) {
        $errors[] = sprintf("Set '%s' v%s: question_no must be sequential 1..%d with no gaps (got [%s])",
            $code, $ver, count($nos), implode(',', $nos));
    }
}

// ── Rule 18 — no duplicate ACTIVE mapping (map_level+L2+L3) in the CSV ───────
$mapping_by_set = [];   // key => [map_level, l2, l3, is_active]
$active_map_seen = [];  // "map|l2|l3" => set key
foreach ($sets as $key => $s) {
    $mapping_by_set[$key] = [
        'map_level' => $s['map_level'],
        'l2'        => $s['l2'],
        'l3'        => $s['l3'],
        'is_active' => $s['is_active'],
    ];
    if ((int)$s['is_active'] !== 1) continue;   // only active mappings conflict
    $mk = $s['map_level'] . '|' . normalize_text($s['l2']) . '|' . normalize_text($s['l3']);
    if (isset($active_map_seen[$mk])) {
        $errors[] = sprintf("Duplicate active mapping (%s / %s / %s) used by both '%s' and '%s'",
            $s['map_level'], $s['l2'], $s['l3'], $active_map_seen[$mk], $key);
    } else {
        $active_map_seen[$mk] = $key;
    }
}

// ── Rule 19/20 — DB advisory warnings (table-existence gated) ───────────────
// source_hash duplicate (19) and existing active set_code (20). Dry-run: warn only.
$source_hash = hash_file('sha256', $csv_path);
if (table_exists($pdo, 'vendor_eval_question_import_batches')) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM vendor_eval_question_import_batches WHERE source_hash = ?");
    $st->execute([$source_hash]);
    if ((int)$st->fetchColumn() > 0) {
        $warnings[] = "source_hash already present in vendor_eval_question_import_batches "
                    . "(this exact file appears already imported): {$source_hash}";
    }
} else {
    $warnings[] = "vendor_eval_question_import_batches does not exist yet — skipped re-import (source_hash) check.";
}
if (table_exists($pdo, 'vendor_eval_question_sets')) {
    $codes = array_values(array_unique(array_map(fn($s) => $s['set_code'], $sets)));
    if (!empty($codes)) {
        $in = implode(',', array_fill(0, count($codes), '?'));
        $st = $pdo->prepare("SELECT set_code, COUNT(*) c FROM vendor_eval_question_sets
                             WHERE is_active = 1 AND set_code IN ($in) GROUP BY set_code");
        $st->execute($codes);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $hit) {
            $warnings[] = "DB already has an ACTIVE set_code '{$hit['set_code']}' — "
                        . "apply would require --deactivate-old to supersede it.";
        }
    }
} else {
    $warnings[] = "vendor_eval_question_sets does not exist yet — skipped active set_code conflict check.";
}

// ── Report ──────────────────────────────────────────────────────────────────
print_report($total_rows, $sets, $questions_by_set, $errors, $warnings, $weight_sums, $mapping_by_set);

$validation_passed = empty($errors);

// ── Mode dispatch ───────────────────────────────────────────────────────────
if (!$opt_apply) {
    // DRY-RUN: never writes. (--dry-run flag is the explicit form of the default.)
    finish($validation_passed);
}

// ===========================================================================
// APPLY PATH — guarded. NOT exercised in this step (no --apply is passed).
// Each gate aborts BEFORE opening a transaction. Structure is implemented so a
// later, deliberate run can import safely.
// ===========================================================================
echo "\n--- APPLY MODE REQUESTED ---\n";

// Gate A: --confirm-db is mandatory and must match the live database name.
if ($opt_confirm_db === null) {
    exit("APPLY ABORT: --apply requires --confirm-db=DATABASE_NAME (got none).\n");
}
if ($opt_confirm_db !== $current_db) {
    exit("APPLY ABORT: --confirm-db '{$opt_confirm_db}' does not match the connected database.\n"
       . "             Refusing to write to the wrong database.\n");
}
// Gate B: validation must have passed.
if (!$validation_passed) {
    exit("APPLY ABORT: validation FAILED (" . count($errors) . " error[s]). Fix the CSV first.\n");
}
// Gate C: all v2 write tables must exist (schema applied separately).
$missing_tables = [];
foreach ($V2_WRITE_TABLES as $t) {
    if (!table_exists($pdo, $t)) $missing_tables[] = $t;
}
if (!empty($missing_tables)) {
    exit("APPLY ABORT: required v2 table(s) missing: " . implode(', ', $missing_tables) . "\n"
       . "             Apply db_backups/vendor_eval_v2_question_schema.sql first.\n");
}
// Gate D: existing active set_code blocks apply unless --deactivate-old.
$blocking = [];
$codes = array_values(array_unique(array_map(fn($s) => $s['set_code'], $sets)));
if (!empty($codes)) {
    $in = implode(',', array_fill(0, count($codes), '?'));
    $st = $pdo->prepare("SELECT DISTINCT set_code FROM vendor_eval_question_sets
                         WHERE is_active = 1 AND set_code IN ($in)");
    $st->execute($codes);
    $blocking = array_map(fn($r) => $r['set_code'], $st->fetchAll(PDO::FETCH_ASSOC));
}
if (!empty($blocking) && !$opt_deactivate_old) {
    exit("APPLY ABORT: active set_code already exists: " . implode(', ', $blocking) . "\n"
       . "             Re-run with --deactivate-old to supersede prior versions.\n");
}

// All gates passed — perform the import inside a single transaction.
apply_import($pdo, $sets, $questions_by_set, $mapping_by_set, $opt_deactivate_old,
    $csv_path, $source_hash);

exit(0);

// ===========================================================================
// Functions
// ===========================================================================

/**
 * Print the dry-run / validation report. Pure output; no DB access.
 */
function print_report(int $total_rows, array $sets, array $questions_by_set,
                      array $errors, array $warnings, array $weight_sums, array $mapping_by_set): void {
    $distinct_sets = count($sets);
    $total_questions = 0;
    foreach ($questions_by_set as $qs) $total_questions += count($qs);
    $total_mappings = count($mapping_by_set);

    echo "── Summary ──────────────────────────────────────────────────────────────\n";
    printf("  Total CSV data rows  : %d\n", $total_rows);
    printf("  Distinct question sets: %d\n", $distinct_sets);
    printf("  Total questions      : %d\n", $total_questions);
    printf("  Taxonomy mappings    : %d\n", $total_mappings);
    echo "\n";

    if (!empty($weight_sums)) {
        echo "── Weight sum per question set ───────────────────────────────────────────\n";
        foreach ($weight_sums as $key => $sum) {
            [$code, $ver] = explode('||', $key);
            $flag = (abs((int)round($sum * 10000) - 10000) <= 1) ? 'OK ' : 'BAD';
            printf("  [%s] %-28s v%-3s sum=%.4f\n", $flag, $code, $ver, $sum);
        }
        echo "\n";
    }

    if (!empty($mapping_by_set)) {
        echo "── Normalized taxonomy mappings (preview) ────────────────────────────────\n";
        foreach ($mapping_by_set as $key => $m) {
            [$code, $ver] = explode('||', $key);
            printf("  %-28s v%-3s  %-7s  L2=%-22s L3=%-22s active=%d\n",
                $code, $ver, $m['map_level'], $m['l2'], $m['l3'], $m['is_active']);
        }
        echo "\n";
    }

    // Preview first 10 questions across all sets (in encountered order).
    echo "── First 10 questions (preview) ──────────────────────────────────────────\n";
    $shown = 0;
    foreach ($questions_by_set as $key => $qs) {
        [$code, $ver] = explode('||', $key);
        foreach ($qs as $q) {
            if ($shown >= 10) break 2;
            printf("  %-22s q%-2d w=%.4f  %-26s | %s\n",
                $code, $q['question_no'], $q['weight'],
                mb_strimwidth($q['criterion_name'], 0, 26, ''),
                mb_strimwidth($q['question_text'], 0, 48, '...'));
            $shown++;
        }
    }
    if ($shown === 0) echo "  (none)\n";
    echo "\n";

    if (!empty($warnings)) {
        echo "── Warnings (" . count($warnings) . ") ──────────────────────────────────────────────────\n";
        foreach ($warnings as $w) echo "  ! {$w}\n";
        echo "\n";
    }
    if (!empty($errors)) {
        echo "── Validation errors (" . count($errors) . ") ────────────────────────────────────────────\n";
        foreach ($errors as $e) echo "  X {$e}\n";
        echo "\n";
    }
}

/**
 * Print the final status line and exit with an appropriate code.
 */
function finish(bool $passed): void {
    echo "=======================================================================\n";
    if ($passed) {
        echo " DRY RUN PASSED — no validation errors. (No DB writes were performed.)\n";
    } else {
        echo " DRY RUN FAILED — fix the validation errors above. (No DB writes.)\n";
    }
    echo "=======================================================================\n\n";
    exit($passed ? 0 : 1);
}

/**
 * APPLY: insert sets, questions, taxonomy mappings, and an import batch row
 * inside one transaction. Only reached after every gate passes. Not run in
 * this step.
 */
function apply_import(PDO $pdo, array $sets, array $questions_by_set, array $mapping_by_set,
                      bool $deactivate_old, string $csv_path, string $source_hash): void {
    $imported_by = get_current_user() ?: 'cli';
    $now = date('Y-m-d H:i:s');
    $total_questions = 0;
    foreach ($questions_by_set as $qs) $total_questions += count($qs);
    $set_codes = array_values(array_unique(array_map(fn($s) => $s['set_code'], $sets)));

    $pdo->beginTransaction();
    try {
        $ins_set = $pdo->prepare(
            "INSERT INTO vendor_eval_question_sets
                (set_code, set_name, version, is_active, description, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins_q = $pdo->prepare(
            "INSERT INTO vendor_eval_questions
                (question_set_id, question_no, criterion_name, question_text, weight,
                 score_1_label, score_2_label, score_3_label, score_4_label,
                 comment_required, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins_map = $pdo->prepare(
            "INSERT INTO vendor_eval_question_set_taxonomy_map
                (question_set_id, map_level, taxonomy_level_2, taxonomy_level_3, is_active, created_at)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $deact = $pdo->prepare(
            "UPDATE vendor_eval_question_sets SET is_active = 0, updated_at = ?
             WHERE set_code = ? AND is_active = 1"
        );

        foreach ($sets as $key => $s) {
            if ($deactivate_old && (int)$s['is_active'] === 1) {
                $deact->execute([$now, $s['set_code']]);
            }
            $ins_set->execute([
                $s['set_code'], $s['set_name'], $s['version'],
                $s['is_active'], ($s['description'] === '' ? null : $s['description']),
                $now, $now,
            ]);
            $set_id = (int)$pdo->lastInsertId();

            foreach ($questions_by_set[$key] as $q) {
                $weighted_unused = null; // weighted_score lives on answers, not questions
                $ins_q->execute([
                    $set_id, $q['question_no'], $q['criterion_name'], $q['question_text'],
                    number_format($q['weight'], 4, '.', ''),
                    $q['score_1_label'], $q['score_2_label'], $q['score_3_label'], $q['score_4_label'],
                    $q['comment_required'], $q['is_active'], $now,
                ]);
            }

            $m = $mapping_by_set[$key];
            $ins_map->execute([
                $set_id, $m['map_level'], $m['l2'], $m['l3'], $m['is_active'], $now,
            ]);
        }

        $ins_batch = $pdo->prepare(
            "INSERT INTO vendor_eval_question_import_batches
                (source_file, source_hash, set_codes_json, question_count, imported_by, imported_at, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ins_batch->execute([
            basename($csv_path), $source_hash, json_encode($set_codes),
            $total_questions, $imported_by, $now,
            'Imported via seed_vendor_eval_question_sets.php' . ($deactivate_old ? ' (--deactivate-old)' : ''),
        ]);

        $pdo->commit();
        echo "APPLY OK: imported " . count($sets) . " set(s), {$total_questions} question(s), "
           . count($mapping_by_set) . " mapping(s).\n";
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        exit("APPLY FAILED (rolled back): " . $e->getMessage() . "\n");
    }
}
