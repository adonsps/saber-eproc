<?php
// tools/run_po_taxonomy_mapping.php
//
// Vendor Evaluation v2 — deterministic PO-to-taxonomy mapping engine.
// DRY-RUN by default; a guarded --apply mode can upsert results into
// `vendor_eval_po_taxonomy`.
//
// Reads in-scope POs from `prs` and the Item Category taxonomy from
// `v_item_taxonomy`, then proposes a taxonomy classification per PO using a
// deterministic, layered matcher (exact -> whole-word substring -> similar_text).
// In DRY-RUN nothing is written back to the DB (CSV report only). In --apply
// mode the same results are also upserted into vendor_eval_po_taxonomy.
//
// Usage:
//   C:\xampp\php\php.exe tools/run_po_taxonomy_mapping.php [--recent|--all] \
//       [--use-ai --limit=N [--confirm-all-ai]] \
//       [--apply --confirm-db=DB --confirm-host=HOST [--confirm-all-apply]]
//
//   --recent          Restrict to POs with pr_date >= '2026-05-01' (DEFAULT).
//   --all             No pr_date cutoff (full in-scope eval pool).
//   --use-ai          Enable the Gemini fallback for POs left UNRESOLVED by the
//                     deterministic stage. DISABLED by default. Requires --limit.
//   --limit=N         Hard cap on the number of unresolved POs sent to the AI
//                     (mandatory with --use-ai; bounds cost/calls).
//   --confirm-all-ai  Required ONLY to combine --all with --use-ai. Without it,
//                     '--all --use-ai' is refused.
//   --apply           Enable DB WRITE mode (upsert into vendor_eval_po_taxonomy).
//                     DISABLED by default. Requires --confirm-db and --confirm-host.
//   --confirm-db=DB   Required with --apply. Must equal DATABASE() or apply aborts.
//   --confirm-host=H  Required with --apply. Must equal @@hostname or apply aborts.
//                     (Local and production share the same DB name string, so the
//                     hostname + localhost user check are what actually pin LOCAL.)
//   --confirm-all-apply  Required ONLY to combine --apply with --all. Without it,
//                     '--apply --all' is refused.
//   --p-group=A,B,C   Restrict the run to POs that have at least one line in one of
//                     these p_groups (whole PO is still classified from ALL its
//                     lines). Applies to the dry-run report, the AI gate and --apply.
//
// Output:
//   db_backups/local_runtime_exports/po_taxonomy_mapping_dryrun_YYYYMMDD_HHMMSS.csv
//
// Matching refinements (post first dry-run, which over-matched generic words):
//   A. Description noise stripping (bracketed [PR#]/[AON] tags, long pure-numeric
//      SKU/dimension tokens) BEFORE normalization. Alphanumeric model names are
//      preserved (CT58S, RG-RAP2260) as useful context for a later AI stage.
//   B. Stop-list of generic terms (MATERIAL, SECURITY, ENTERPRISE, ...) excluded
//      from the *substring* stages only. Exact and similar_text still use all terms.
//   C. Stricter substring rules: multi-word terms preferred; single-word terms
//      allowed only if not stop-listed AND length >= 7; ambiguous matches (two
//      equally specific terms pointing at different sub-categories) -> unresolved.
//   D. Diagnostic CSV columns: cleaned_description_sample, match_block_reason,
//      matched_term.
//
// SAFETY:
//   - CLI only (refuses browser/web access).
//   - SELECT queries only UNLESS --apply is passed. Without --apply: no
//     INSERT/UPDATE/DELETE, CSV report only.
//   - --apply writes are triple-gated: --confirm-db must equal DATABASE(),
//     --confirm-host must equal @@hostname, and USER() must contain 'localhost'.
//     Any mismatch aborts before a single row is written.
//   - --apply runs inside a single transaction; any write error rolls back the
//     whole batch and exits non-zero.
//   - --apply never touches review fields (reviewed_by/reviewed_at/review_notes)
//     and SKIPS rows a human has already marked status='resolved'.
//   - --apply with --all requires --confirm-all-apply; --apply with --use-ai
//     requires --limit.
//   - AI/Gemini calls happen ONLY with --use-ai and ONLY for unresolved POs, hard
//     capped by --limit. No WA/email. No cron.
//   - Does not touch any v1 Vendor Evaluation files.

// ── CLI guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/run_po_taxonomy_mapping.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

$tool_start = microtime(true);

// ── Argument parsing ────────────────────────────────────────────────────────
$argv_in   = $argv ?? [];
$opt_recent = in_array('--recent', $argv_in, true);
$opt_all    = in_array('--all', $argv_in, true);
$opt_use_ai = in_array('--use-ai', $argv_in, true);
$opt_confirm_all_ai = in_array('--confirm-all-ai', $argv_in, true);

// DB write mode (guarded). OFF unless --apply is passed.
$opt_apply             = in_array('--apply', $argv_in, true);
$opt_confirm_all_apply = in_array('--confirm-all-apply', $argv_in, true);
$confirm_db            = null;
$confirm_host          = null;
// Opt-in for a deliberate non-local write: the operator echoes the exact live
// USER() they were shown. Without it the localhost-only user check applies.
$confirm_user          = null;
$p_groups_filter       = [];   // --p-group=A,B: restrict to POs touching these p_groups

// --limit=N (mandatory companion to --use-ai) and --confirm-db / --confirm-host
// (mandatory companions to --apply). Parsed defensively.
$ai_limit = null;
foreach ($argv_in as $a) {
    if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
        $ai_limit = (int)$m[1];
    } elseif ($a === '--limit') {
        exit("ERROR: --limit requires a value, e.g. --limit=30\n");
    } elseif (preg_match('/^--confirm-db=(.+)$/', $a, $m)) {
        $confirm_db = $m[1];
    } elseif (preg_match('/^--confirm-host=(.+)$/', $a, $m)) {
        $confirm_host = $m[1];
    } elseif (preg_match('/^--confirm-user=(.+)$/', $a, $m)) {
        $confirm_user = $m[1];
    } elseif (preg_match('/^--p-group=(.+)$/', $a, $m)) {
        foreach (explode(',', $m[1]) as $g) {
            $g = trim($g);
            if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $g)) {
                exit("ERROR: --p-group takes a comma-separated list of p_group codes (letters/digits only), got '{$g}'\n");
            }
            $p_groups_filter[] = $g;
        }
    } elseif ($a === '--confirm-db' || $a === '--confirm-host' || $a === '--confirm-user' || $a === '--p-group') {
        exit("ERROR: {$a} requires a value, e.g. {$a}=NAME\n");
    }
}

if ($opt_recent && $opt_all) {
    exit("ERROR: --recent and --all are mutually exclusive. Pick one.\n");
}
// Default scope is --recent when neither flag is supplied.
$scope = $opt_all ? 'all' : 'recent';

// ── AI safety gates ─────────────────────────────────────────────────────────
// AI is OFF unless --use-ai is explicitly passed, and even then it is bounded.
if ($opt_use_ai) {
    if ($ai_limit === null) {
        exit("\nSAFETY ABORT: --use-ai requires --limit=N (e.g. --use-ai --limit=30).\n"
           . "Refusing to call the AI on an unbounded set of POs.\n\n");
    }
    if ($ai_limit < 1) {
        exit("SAFETY ABORT: --limit must be a positive integer (got {$ai_limit}).\n");
    }
    if ($opt_all && !$opt_confirm_all_ai) {
        exit("\nSAFETY ABORT: '--all --use-ai' would run the AI across the FULL backlog.\n"
           . "This is blocked. Add --confirm-all-ai to override deliberately.\n\n");
    }
}

// ── Apply (DB write) safety gates — argument-level ───────────────────────────
// These run before any DB connection. The DATABASE()/@@hostname/USER() identity
// checks happen post-connect (further down) and are the real guard against ever
// writing to production (local + prod share the same DB name string).
if ($opt_apply) {
    if ($confirm_db === null || $confirm_db === '') {
        exit("\nSAFETY ABORT: --apply requires --confirm-db=DATABASE_NAME.\n"
           . "Refusing to write without an explicit DB confirmation.\n\n");
    }
    if ($confirm_host === null || $confirm_host === '') {
        exit("\nSAFETY ABORT: --apply requires --confirm-host=HOSTNAME.\n"
           . "Refusing to write without an explicit host confirmation.\n\n");
    }
    // Apply is allowed with --recent by default; --all needs an extra override.
    if ($scope === 'all' && !$opt_confirm_all_apply) {
        exit("\nSAFETY ABORT: '--apply --all' would WRITE the FULL backlog to the DB.\n"
           . "This is blocked. Add --confirm-all-apply to override deliberately.\n\n");
    }
    // Apply + AI must stay bounded by --limit (also enforced by the --use-ai gate).
    if ($opt_use_ai && $ai_limit === null) {
        exit("\nSAFETY ABORT: '--apply --use-ai' requires --limit=N to bound AI calls.\n\n");
    }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────
// Mirror audit_po_taxonomy_candidates.php: set REQUEST_URI (not HTTP_HOST) so
// database.php picks local XAMPP credentials without tripping the browser guard.
$_SERVER['REQUEST_URI'] = '';
define('RUNNING_AS_CLI_TOOL', true);

$root = dirname(__DIR__);
require_once $root . '/workspace/config/database.php';   // provides $pdo (PDO)

// Gemini helper is loaded ONLY when AI is enabled. We reuse call_gemini_api()
// and GEMINI_API_KEY exactly as-is; this file is never modified by this tool.
if ($opt_use_ai) {
    require_once $root . '/workspace/config/gemini_api.php';
}

// ── Apply target verification — POST-CONNECT, FAIL-FAST ──────────────────────
// Local and production use the SAME database name string, so DATABASE() alone is
// not enough. We additionally pin @@hostname to --confirm-host and require a
// 'localhost' user. Any mismatch aborts here, before taxonomy load or any write.
if ($opt_apply) {
    try {
        $cur_db   = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $cur_host = (string)$pdo->query('SELECT @@hostname')->fetchColumn();
        $cur_user = (string)$pdo->query('SELECT USER()')->fetchColumn();
    } catch (Throwable $e) {
        exit("\nSAFETY ABORT: could not read DB identity for --apply check: "
           . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n\n");
    }
    $apply_errs = [];
    if ($cur_db !== $confirm_db) {
        $apply_errs[] = "DATABASE() '{$cur_db}' != --confirm-db '{$confirm_db}'";
    }
    if ($cur_host !== $confirm_host) {
        $apply_errs[] = "@@hostname '{$cur_host}' != --confirm-host '{$confirm_host}'";
    }
    if ($confirm_user !== null) {
        if ($cur_user !== $confirm_user) {
            $apply_errs[] = "USER() '{$cur_user}' != --confirm-user '{$confirm_user}'";
        }
    } elseif (stripos($cur_user, 'localhost') === false && stripos($cur_user, '127.0.0.1') === false) {
        $apply_errs[] = "USER() '{$cur_user}' does not contain 'localhost' or '127.0.0.1' (pass --confirm-user=<exact USER()> to write to a non-local DB deliberately)";
    }
    if (!empty($apply_errs)) {
        exit("\nSAFETY ABORT: --apply target verification FAILED. No rows written.\n  - "
           . implode("\n  - ", $apply_errs) . "\n\n");
    }
    echo sprintf("[--apply] DB WRITE mode ENABLED. Target verified: db=%s host=%s user=%s\n",
        $cur_db, $cur_host, $cur_user);
}

// ── Output directory ───────────────────────────────────────────────────────
$export_dir = $root . '/db_backups/local_runtime_exports';
if (!is_dir($export_dir)) {
    mkdir($export_dir, 0755, true);
}
$timestamp = date('Ymd_His');
$csv_path  = $export_dir . '/po_taxonomy_mapping_dryrun_' . $timestamp . '.csv';

$mode_label = $opt_apply ? 'APPLY / DB WRITE' : 'DETERMINISTIC DRY-RUN';
echo "\n";
echo "=======================================================================\n";
echo " PO -> Taxonomy Mapping ({$mode_label})\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n\n";
echo "Scope: " . ($scope === 'all' ? "ALL in-scope POs (no pr_date cutoff)"
                                   : "RECENT (pr_date >= '2026-05-01')") . "\n";
if (!empty($p_groups_filter)) {
    echo "Filter: only POs with a line in p_group IN (" . implode(', ', $p_groups_filter) . ")\n";
}

if ($opt_use_ai) {
    echo "\n[--use-ai] AI fallback ENABLED for UNRESOLVED POs only.\n";
    printf("           Hard limit: %d unresolved PO(s) will be sent to Gemini.\n", $ai_limit);
    if ($opt_all) {
        echo "           (--confirm-all-ai supplied: AI may run across the full backlog.)\n";
    }
} else {
    echo "[AI] Disabled (deterministic only). Pass --use-ai --limit=N to enable.\n";
}
echo "\n";

// ── Generic-term stop-list (substring stages only) ──────────────────────────
// These appear as taxonomy terms but are too generic to be safe single-word
// substring triggers (they mislabelled hardware/services as "Software" etc.).
$STOPLIST = array_flip([
    'MATERIAL', 'SECURITY', 'ENTERPRISE', 'EQUIPMENT', 'SERVICE', 'SERVICES',
    'SYSTEM', 'SYSTEMS', 'SOLUTION', 'SOLUTIONS', 'PRODUCT', 'PRODUCTS',
    'GENERAL', 'OTHER', 'OTHERS', 'OPERATIONAL', 'MANAGEMENT', 'TECHNOLOGY',
    'INDUSTRIAL',
]);
const SUBSTR_SINGLE_WORD_MIN_LEN = 7; // single-word substring terms must be >= this

// A multi-line PO's majority vote is only trustworthy if it covers enough of
// the PO's actual distinct line items — a 1-of-6-lines "majority" is not a
// majority of the PO. Loose on purpose: only defer POs where fewer than half
// the distinct descriptions were even matched, rather than second-guessing
// every multi-line PO.
const MULTI_LINE_COVERAGE_FLOOR = 0.50;

// ── Description noise stripping (Refinement A) ──────────────────────────────
// Removes procurement noise BEFORE normalization while preserving alphanumeric
// model names. Returns a cleaned, human-readable string.
function clean_description($raw): string {
    $s = (string)$raw;
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    // Drop bracketed segments: [26081], [AON], [16757], etc.
    $s = preg_replace('/\[[^\]]*\]/', ' ', $s);
    // Tokenize; drop standalone pure-numeric tokens of length >= 4
    // (internal SKU codes, dimensions). Keep short numbers and any token that
    // contains a letter (model names: CT58S, RG-RAP2260, 8GB, DS-2CD3121G0-I).
    $tokens = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    $kept = [];
    foreach ($tokens as $t) {
        // Numeric test on the token core (ignore surrounding punctuation).
        $core = trim($t, " \t.,;:/\\|()#*°x");
        if ($core !== '' && ctype_digit($core) && strlen($core) >= 4) {
            continue; // pure-numeric SKU/dimension noise
        }
        $kept[] = $t;
    }
    $s = implode(' ', $kept);
    // Collapse leftover punctuation runs and whitespace.
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

// ── Text normalization ──────────────────────────────────────────────────────
// uppercase -> trim -> drop everything except A-Z 0-9 and spaces (special
// chars become spaces so word boundaries survive) -> collapse whitespace.
function normalize_text($s): string {
    $s = strtoupper((string)$s);
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    $s = preg_replace('/[^A-Z0-9 ]+/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

function token_count(string $norm): int {
    if ($norm === '') return 0;
    return substr_count($norm, ' ') + 1;
}

// ── Load taxonomy from v_item_taxonomy ──────────────────────────────────────
// level_0=Tower, level_1=Category, sub_category_code, level_2=Sub Category,
// level_3=Commodity. Ordered for deterministic "first row wins" selection.
echo "Loading taxonomy from v_item_taxonomy...\n";
$tax_sql = "
    SELECT level_0, level_1, sub_category_code, level_2, level_3
    FROM v_item_taxonomy
    ORDER BY level_0, level_1, level_2, level_3
";
$tax_rows = $pdo->query($tax_sql)->fetchAll(PDO::FETCH_ASSOC);

$exact_l3 = [];   // norm(level_3) => taxonomy row  (first wins) — uses ALL terms
$exact_l2 = [];   // norm(level_2) => taxonomy row  (first wins) — uses ALL terms
$l3_terms = [];   // norm(level_3) => taxonomy row  — full set, for similar_text
$l2_terms = [];   // norm(level_2) => taxonomy row  — full set, for similar_text

foreach ($tax_rows as $r) {
    $n3 = normalize_text($r['level_3'] ?? '');
    $n2 = normalize_text($r['level_2'] ?? '');
    if ($n3 !== '') {
        if (!isset($exact_l3[$n3])) $exact_l3[$n3] = $r;
        if (!isset($l3_terms[$n3])) $l3_terms[$n3] = $r;
    }
    if ($n2 !== '') {
        if (!isset($exact_l2[$n2])) $exact_l2[$n2] = $r;
        if (!isset($l2_terms[$n2])) $l2_terms[$n2] = $r;
    }
}

// Whole-word substring candidate lists (Refinements B & C):
//   - multi-word terms: allowed (length >= 4 baseline).
//   - single-word terms: allowed only if NOT stop-listed AND length >= 7.
//   - sorted by token count desc, then string length desc (specific wins).
$build_sub = function (array $terms) use ($STOPLIST): array {
    $out = [];
    foreach ($terms as $norm => $row) {
        if ($norm === '') continue;
        $tokens = token_count($norm);
        if ($tokens === 1) {
            if (isset($STOPLIST[$norm]))                continue; // generic single word
            if (strlen($norm) < SUBSTR_SINGLE_WORD_MIN_LEN) continue;
        } else {
            if (strlen($norm) < 4) continue;
        }
        $out[] = ['norm' => $norm, 'row' => $row, 'tokens' => $tokens, 'len' => strlen($norm)];
    }
    usort($out, function ($a, $b) {
        if ($a['tokens'] !== $b['tokens']) return $b['tokens'] <=> $a['tokens'];
        return $b['len'] <=> $a['len'];
    });
    return $out;
};
$l3_sub = $build_sub($l3_terms);
$l2_sub = $build_sub($l2_terms);

printf("  Taxonomy rows: %d | L3 terms (all/substr-allowed): %d/%d | L2 terms (all/substr-allowed): %d/%d\n\n",
    count($tax_rows), count($l3_terms), count($l3_sub), count($l2_terms), count($l2_sub));

// ── AI taxonomy structures (built only when --use-ai) ───────────────────────
// $tax_prompt_json : deduped allow-list shown to the model (JSON).
// $valid_l2        : norm(level_2)             => canonical taxonomy row.
// $valid_l2_l3     : norm(level_2)=>norm(l3)   => canonical taxonomy row.
$valid_l2        = [];
$valid_l2_l3     = [];
$tax_prompt_json = '';
if ($opt_use_ai) {
    $tax_prompt = [];
    $seen_combo = [];
    foreach ($tax_rows as $r) {
        $l2 = trim((string)($r['level_2'] ?? ''));
        if ($l2 === '') continue;
        $l3 = trim((string)($r['level_3'] ?? ''));
        $n2 = normalize_text($l2);
        $n3 = normalize_text($l3);
        if (!isset($valid_l2[$n2])) $valid_l2[$n2] = $r;
        if ($n3 !== '' && !isset($valid_l2_l3[$n2][$n3])) $valid_l2_l3[$n2][$n3] = $r;
        $combo = $n2 . '|' . $n3;
        if (!isset($seen_combo[$combo])) {
            $seen_combo[$combo] = true;
            $tax_prompt[] = [
                'level_0'           => trim((string)($r['level_0'] ?? '')),
                'level_1'           => trim((string)($r['level_1'] ?? '')),
                'sub_category_code' => trim((string)($r['sub_category_code'] ?? '')),
                'level_2'           => $l2,
                'level_3'           => $l3 !== '' ? $l3 : null,
            ];
        }
    }
    $tax_prompt_json = json_encode($tax_prompt,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    printf("AI allow-list prepared: %d taxonomy options (distinct L2=%d).\n\n",
        count($tax_prompt), count($valid_l2));
}

// ── similar_text best-candidate helper ──────────────────────────────────────
// Returns [bestRow, bestPct, secondPct] across a normalized-term=>row map.
function best_similar(string $n, array $terms): array {
    $best_pct = -1.0; $second_pct = -1.0; $best_row = null;
    foreach ($terms as $norm => $row) {
        $pct = 0.0;
        similar_text($n, $norm, $pct);
        if ($pct > $best_pct) {
            $second_pct = $best_pct;
            $best_pct   = $pct;
            $best_row   = $row;
        } elseif ($pct > $second_pct) {
            $second_pct = $pct;
        }
    }
    return [$best_row, $best_pct, $second_pct];
}

// ── Whole-word substring picker with ambiguity guard (Refinement C) ─────────
// $cands is a pre-filtered, pre-sorted list (tokens desc, len desc).
// Returns ['status'=>'hit','cand'=>..] | ['status'=>'ambiguous','detail'=>..]
//        | ['status'=>'none'].
function substring_pick(string $padded_norm, array $cands): array {
    $matched = [];
    foreach ($cands as $c) {
        if (strpos($padded_norm, " {$c['norm']} ") !== false) {
            $matched[] = $c;
        }
    }
    if (empty($matched)) return ['status' => 'none'];

    $t1     = $matched[0]; // most specific (tokens desc, len desc)
    $t1_l2  = normalize_text($t1['row']['level_2'] ?? '');
    // Ambiguous if an equally specific term (same token count) points at a
    // DIFFERENT sub-category. Subsumed shorter terms (fewer tokens) don't count.
    foreach (array_slice($matched, 1) as $tj) {
        if ($tj['tokens'] === $t1['tokens']) {
            $tj_l2 = normalize_text($tj['row']['level_2'] ?? '');
            if ($tj_l2 !== $t1_l2) {
                return ['status' => 'ambiguous', 'detail' => $t1['norm'] . ' vs ' . $tj['norm']];
            }
        }
    }
    return ['status' => 'hit', 'cand' => $t1];
}

// ── Single item_description matcher ─────────────────────────────────────────
// Returns ['match'=>matchArr|null, 'matched_term'=>string, 'block_reason'=>string].
// L2-level methods deliberately leave level_3 blank (commodity not resolved).
function build_match(string $method, int $conf, array $row, bool $with_l3, string $reason): array {
    return [
        'method'            => $method,
        'confidence'        => $conf,
        'level_0'           => $row['level_0'] ?? '',
        'level_1'           => $row['level_1'] ?? '',
        'sub_category_code' => $row['sub_category_code'] ?? '',
        'level_2'           => $row['level_2'] ?? '',
        'level_3'           => $with_l3 ? ($row['level_3'] ?? '') : '',
        'l2_norm'           => normalize_text($row['level_2'] ?? ''),
        'reason'            => $reason,
    ];
}

function match_description(string $cleaned, array $ctx): array {
    $n = normalize_text($cleaned);
    if ($n === '') {
        return ['match' => null, 'matched_term' => '', 'block_reason' => 'empty after cleaning'];
    }
    $notes = [];

    // 1. exact -> level_3 (Commodity) — all terms
    if (isset($ctx['exact_l3'][$n])) {
        $row = $ctx['exact_l3'][$n];
        $m = build_match('exact_l3', 95, $row, true, "exact match on Commodity '{$row['level_3']}'");
        return ['match' => $m, 'matched_term' => $row['level_3'], 'block_reason' => 'exact_l3'];
    }
    // 2. exact -> level_2 (Sub Category) — all terms
    if (isset($ctx['exact_l2'][$n])) {
        $row = $ctx['exact_l2'][$n];
        $m = build_match('exact_l2', 85, $row, false, "exact match on Sub Category '{$row['level_2']}'");
        return ['match' => $m, 'matched_term' => $row['level_2'], 'block_reason' => 'exact_l2'];
    }
    $notes[] = 'exact:none';

    $padded = " {$n} ";

    // 3. whole-word substring -> level_3 (stop-listed/short single words excluded)
    $r3 = substring_pick($padded, $ctx['l3_sub']);
    if ($r3['status'] === 'hit') {
        $row = $r3['cand']['row'];
        $m = build_match('fuzzy_l3', 72, $row, true, "whole-word match on Commodity '{$row['level_3']}'");
        return ['match' => $m, 'matched_term' => $row['level_3'], 'block_reason' => 'substring_l3'];
    }
    $notes[] = $r3['status'] === 'ambiguous' ? "substr_l3:ambiguous({$r3['detail']})" : 'substr_l3:none';

    // 4. whole-word substring -> level_2
    $r2 = substring_pick($padded, $ctx['l2_sub']);
    if ($r2['status'] === 'hit') {
        $row = $r2['cand']['row'];
        $m = build_match('fuzzy_l2', 65, $row, false, "whole-word match on Sub Category '{$row['level_2']}'");
        return ['match' => $m, 'matched_term' => $row['level_2'], 'block_reason' => 'substring_l2'];
    }
    $notes[] = $r2['status'] === 'ambiguous' ? "substr_l2:ambiguous({$r2['detail']})" : 'substr_l2:none';

    // 5 & 6. similar_text fallback — all terms; needs description length >= 10.
    if (strlen($n) >= 10) {
        [$row3, $top3, $sec3] = best_similar($n, $ctx['l3_terms']);
        if ($row3 !== null && $top3 >= 75 && ($top3 - max($sec3, 0)) > 5) {
            $m = build_match('fuzzy_l3', 60, $row3, true,
                sprintf("similar_text %.1f%% to Commodity '%s'", $top3, $row3['level_3']));
            return ['match' => $m, 'matched_term' => $row3['level_3'],
                    'block_reason' => sprintf('similar_l3 %.1f%%', $top3)];
        }
        $notes[] = sprintf('similar_l3:%.0f%%(gap%.0f)', max($top3, 0), $top3 - max($sec3, 0));

        [$row2, $top2, $sec2] = best_similar($n, $ctx['l2_terms']);
        if ($row2 !== null && $top2 >= 75 && ($top2 - max($sec2, 0)) > 5) {
            $m = build_match('fuzzy_l2', 55, $row2, false,
                sprintf("similar_text %.1f%% to Sub Category '%s'", $top2, $row2['level_2']));
            return ['match' => $m, 'matched_term' => $row2['level_2'],
                    'block_reason' => sprintf('similar_l2 %.1f%%', $top2)];
        }
        $notes[] = sprintf('similar_l2:%.0f%%(gap%.0f)', max($top2, 0), $top2 - max($sec2, 0));
    } else {
        $notes[] = 'similar:skip(len<10)';
    }

    return ['match' => null, 'matched_term' => '', 'block_reason' => implode('; ', $notes)];
}

// ── AI prompt builder (used only with --use-ai) ─────────────────────────────
// Signals: item_descriptions (primary) + vendor_name/ticket_type (context).
// p_group is deliberately NOT passed to the model as a classification signal.
function build_ai_prompt(string $vendor, string $ticket_type, array $descs, string $tax_json): string {
    $vendor      = $vendor      !== '' ? $vendor      : '(unknown)';
    $ticket_type = $ticket_type !== '' ? $ticket_type : '(unknown)';
    $desc_lines = '';
    foreach ($descs as $i => $d) {
        $desc_lines .= '  ' . ($i + 1) . '. ' . $d . "\n";
    }
    return <<<PROMPT
You are a procurement category classifier for a retail company in Indonesia.
Classify the Purchase Order (PO) below into exactly ONE category taken ONLY from
the APPROVED TAXONOMY. Do not invent categories. Every field you return must come
from the SAME single taxonomy row you select.

APPROVED TAXONOMY (JSON array of allowed rows):
{$tax_json}

PO CONTEXT:
- vendor_name: {$vendor}
- ticket_type: {$ticket_type}
- item_descriptions:
{$desc_lines}
INSTRUCTIONS:
- Base your decision PRIMARILY on the item_descriptions.
- Use vendor_name and ticket_type only as supporting context.
- Choose the best Level 2 (Sub Category) and Level 3 (Commodity).
- Prefer Level 3 when you are confident about the specific commodity.
- If Level 3 is ambiguous, set "taxonomy_level_3" to null and classify at Level 2.
- taxonomy_level_0, taxonomy_level_1, sub_category_code and taxonomy_level_2 must
  all belong to the SAME taxonomy row as your chosen Level 2 / Level 3.
- "confidence" is your certainty from 0 to 100.
- Output JSON ONLY. No markdown, no code fences, no commentary.

Required JSON shape:
{
  "taxonomy_level_0": "...",
  "taxonomy_level_1": "...",
  "sub_category_code": "... or null",
  "taxonomy_level_2": "...",
  "taxonomy_level_3": "... or null",
  "confidence": 0-100,
  "reason": "short reason"
}
PROMPT;
}

// ── Strip markdown code fences from an AI response (Refinement: post-proc 1) ─
function strip_json_fences(string $s): string {
    $s = trim($s);
    if (strncmp($s, '```', 3) === 0) {
        $s = preg_replace('/^```[A-Za-z0-9]*\s*/', '', $s);
        $s = preg_replace('/\s*```$/', '', $s);
    }
    return trim($s);
}

// ── Validate an AI classification against the taxonomy allow-list ───────────
// Returns ['valid'=>bool,'error'=>str,'row'=>?array,'with_l3'=>bool,
//          'confidence'=>int,'reason'=>str]. Enforces post-proc rules 3–5.
function ai_validate(array $p, array $valid_l2, array $valid_l2_l3): array {
    $fail = fn(string $e) => ['valid' => false, 'error' => $e, 'row' => null,
                              'with_l3' => false, 'confidence' => 0, 'reason' => ''];

    // 3. Level 2 must exist in taxonomy.
    $l2_raw = isset($p['taxonomy_level_2']) ? trim((string)$p['taxonomy_level_2']) : '';
    if ($l2_raw === '') return $fail('taxonomy_level_2 missing or blank');
    $n2 = normalize_text($l2_raw);
    if (!isset($valid_l2[$n2])) return $fail("taxonomy_level_2 '{$l2_raw}' not in taxonomy");
    $sel_row = $valid_l2[$n2];
    $with_l3 = false;

    // 4. Level 3 (optional) must exist UNDER the selected Level 2.
    $l3_raw = array_key_exists('taxonomy_level_3', $p) ? $p['taxonomy_level_3'] : null;
    $l3_str = ($l3_raw === null) ? '' : trim((string)$l3_raw);
    if ($l3_str !== '' && strcasecmp($l3_str, 'null') !== 0) {
        $n3 = normalize_text($l3_str);
        if (!isset($valid_l2_l3[$n2][$n3])) {
            return $fail("taxonomy_level_3 '{$l3_str}' not valid under Sub Category '{$sel_row['level_2']}'");
        }
        $sel_row = $valid_l2_l3[$n2][$n3];
        $with_l3 = true;
    }

    // 5. sub_category_code: null/blank allowed; if present must match selected row.
    $sc_raw = array_key_exists('sub_category_code', $p) ? $p['sub_category_code'] : null;
    $sc_str = ($sc_raw === null) ? '' : trim((string)$sc_raw);
    if ($sc_str !== '' && strcasecmp($sc_str, 'null') !== 0) {
        $expected = trim((string)($sel_row['sub_category_code'] ?? ''));
        if (normalize_text($sc_str) !== normalize_text($expected)) {
            return $fail("sub_category_code '{$sc_str}' does not match selected row ('{$expected}')");
        }
    }

    $conf = isset($p['confidence']) ? (int)round((float)$p['confidence']) : 0;
    $conf = max(0, min(100, $conf));
    $reason = isset($p['reason']) ? trim((string)$p['reason']) : '';
    $reason = str_replace(["\r", "\n"], ' ', $reason);
    if (strlen($reason) > 300) $reason = substr($reason, 0, 297) . '...';

    return ['valid' => true, 'error' => '', 'row' => $sel_row, 'with_l3' => $with_l3,
            'confidence' => $conf, 'reason' => $reason];
}

// Matcher context.
$ctx = [
    'exact_l3' => $exact_l3,
    'exact_l2' => $exact_l2,
    'l3_sub'   => $l3_sub,
    'l2_sub'   => $l2_sub,
    'l3_terms' => $l3_terms,
    'l2_terms' => $l2_terms,
];
$match_cache = []; // norm(cleaned desc) => match_description() result

// ── Candidate PO scope (SELECT only) ────────────────────────────────────────
$base_where = "
    WHERE p.po_number IS NOT NULL
      AND p.po_number != ''
      AND p.gr_date IS NOT NULL
      AND p.gr_date <= CURDATE()
      AND p.vendor_name IS NOT NULL
      AND p.vendor_name != ''
      AND LOWER(IFNULL(p.ticket_type,'')) NOT IN ('intercompany','intercompany new store')
";
    if ($scope !== 'all') {
        $base_where .= "      AND (p.pr_date >= '2026-05-01' OR p.pr_date IS NULL OR (p.canvassing_no IS NOT NULL AND p.canvassing_no != '') OR (p.po_number IS NOT NULL AND p.po_number != ''))\n";
    }
    if (!empty($p_groups_filter)) {
        // PO-level filter (not line-level): a PO is classified from ALL its lines,
        // so a PO spanning several p_groups is never judged on a partial line set.
        $in = implode(',', array_map(fn($g) => $pdo->quote($g), $p_groups_filter));
        $base_where .= "      AND p.po_number IN (SELECT po_number FROM prs WHERE p_group IN ({$in}))\n";
    }

echo "Fetching in-scope prs line items...\n";
$lines_sql = "
    SELECT
        p.po_number,
        p.vendor_name,
        p.requestor_name,
        p.p_group,
        p.ticket_type,
        p.item_description
    FROM prs p
    {$base_where}
    ORDER BY p.po_number
";
$all_lines = $pdo->query($lines_sql)->fetchAll(PDO::FETCH_ASSOC);
$total_line_items = count($all_lines);
echo "  Fetched {$total_line_items} line items.\n";

// ── Group by PO ─────────────────────────────────────────────────────────────
$po_map = [];
foreach ($all_lines as $row) {
    $po = $row['po_number'];
    if (!isset($po_map[$po])) {
        $po_map[$po] = [
            'po_number'    => $po,
            'vendor_name'  => $row['vendor_name'],
            'requestors'   => [],
            'p_groups'     => [],
            'ticket_types' => [],
            'descriptions' => [],
        ];
    }
    if (!empty($row['requestor_name']) && !in_array($row['requestor_name'], $po_map[$po]['requestors'], true)) {
        $po_map[$po]['requestors'][] = $row['requestor_name'];
    }
    if (!empty($row['p_group']) && !in_array($row['p_group'], $po_map[$po]['p_groups'], true)) {
        $po_map[$po]['p_groups'][] = $row['p_group'];
    }
    if (!empty($row['ticket_type']) && !in_array($row['ticket_type'], $po_map[$po]['ticket_types'], true)) {
        $po_map[$po]['ticket_types'][] = $row['ticket_type'];
    }
    $po_map[$po]['descriptions'][] = $row['item_description'] ?? '';
}
$total_pos = count($po_map);
echo "  Grouped into {$total_pos} distinct POs.\n\n";

// ── Load existing classified PO statuses (pre-mapping) ──────────────────────
// Prevents the AI gate from re-calling Gemini on POs already classified in a
// prior --apply run (status mapped/needs_review/resolved). Also used by the
// --apply write loop to skip overwriting those rows.
$existing_classified = []; // po_number => status string
if ($opt_use_ai || $opt_apply) {
    try {
        $ec_rows = $pdo->query("
            SELECT po_number, status
            FROM vendor_eval_po_taxonomy
            WHERE status IN ('mapped', 'needs_review', 'resolved')
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ec_rows as $ecr) {
            $existing_classified[(string)$ecr['po_number']] = $ecr['status'];
        }
        if (!empty($existing_classified)) {
            printf("  Existing classified rows: %d (mapped/needs_review/resolved — AI + apply will skip these).\n\n",
                count($existing_classified));
        }
    } catch (Throwable $e) {
        echo "  Warning: could not load existing classified rows: "
            . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n\n";
    }
}

// ── Per-PO mapping ──────────────────────────────────────────────────────────
echo "Mapping...\n";

$csv_rows = [];
$apply_rows = []; // per-PO payloads captured for the (optional) DB write pass
$sum = [
    'mapped' => 0, 'needs_review' => 0, 'unresolved' => 0,
    'exact_l3' => 0, 'exact_l2' => 0, 'fuzzy_l3' => 0, 'fuzzy_l2' => 0, 'unresolved_method' => 0,
    'ai_method' => 0, 'ai_invalid_method' => 0,
    'ai_calls' => 0, 'ai_valid' => 0, 'ai_invalid' => 0,
    'ai_mapped' => 0, 'ai_needs_review' => 0, 'ai_review_triggered' => 0,
    'ai_skipped_existing_classified' => 0, // rows already mapped/needs_review/resolved in DB
    'confidence_total' => 0,
    'proposed_l2_freq' => [],
    'unresolved_vendor_freq' => [],
];

foreach ($po_map as $po => $data) {
    $all_descs      = $data['descriptions'];
    $distinct_descs = array_values(array_unique($all_descs));
    $line_count     = count($all_descs);
    $distinct_count = count($distinct_descs);

    // Map each distinct description independently (clean -> normalize -> match).
    $line_results = []; // ['raw','clean','match'|null,'matched_term','block_reason']
    $clean_distinct = [];
    foreach ($distinct_descs as $d) {
        $clean = clean_description($d);
        $clean_distinct[] = $clean;
        $nd = normalize_text($clean);
        if ($nd === '') {
            $res = ['match' => null, 'matched_term' => '', 'block_reason' => 'empty after cleaning'];
        } elseif (array_key_exists($nd, $match_cache)) {
            $res = $match_cache[$nd];
        } else {
            $res = match_description($clean, $ctx);
            $match_cache[$nd] = $res;
        }
        $line_results[] = [
            'raw'          => $d,
            'clean'        => $clean,
            'match'        => $res['match'],
            'matched_term' => $res['matched_term'],
            'block_reason' => $res['block_reason'],
        ];
    }
    $clean_distinct = array_values(array_unique($clean_distinct));

    $mapped_lines = array_values(array_filter($line_results, fn($lr) => $lr['match'] !== null));
    $total_mapped = count($mapped_lines);

    // Defaults (all-unresolved baseline).
    $proposed = [
        'level_0' => '', 'level_1' => '', 'sub_category_code' => '',
        'level_2' => '', 'level_3' => '',
    ];
    $mapping_method = 'unresolved';
    $confidence     = 0;
    $dominant_basis = '';
    $status         = 'unresolved';
    $reason         = 'No deterministic match';
    $matched_term   = '';
    $block_reason   = '';

    if ($total_mapped > 0) {
        // Vote at level_2 across mapped distinct descriptions.
        $votes = [];   // l2_norm => count
        $by_l2 = [];   // l2_norm => list of line_results
        foreach ($mapped_lines as $lr) {
            $k = $lr['match']['l2_norm'];
            $votes[$k] = ($votes[$k] ?? 0) + 1;
            $by_l2[$k][] = $lr;
        }
        arsort($votes);
        $winner_norm  = array_key_first($votes);
        $winner_count = $votes[$winner_norm];
        $winner_pct   = $winner_count / $total_mapped;
        $tie          = count(array_filter($votes, fn($c) => $c === $winner_count)) > 1;

        $single       = ($distinct_count === 1);
        $clear_winner = $single || (!$tie && $winner_pct >= 0.60);

        // Coverage: how much of the PO's actual distinct scope did the
        // matched lines represent? $winner_pct alone only measures agreement
        // among the lines that matched — a PO with 6 distinct lines where
        // only 1 matched still scores 100% winner_pct. $coverage catches that.
        $coverage            = $total_mapped / $distinct_count;
        $sufficient_coverage = $single || $coverage > MULTI_LINE_COVERAGE_FLOOR;

        // Representative line for the chosen level_2: prefer one carrying a
        // level_3 (Commodity), then highest confidence.
        $pick_representative = function (array $lrs): array {
            $with_l3 = array_filter($lrs, fn($lr) => $lr['match']['level_3'] !== '');
            $pool = !empty($with_l3) ? array_values($with_l3) : $lrs;
            usort($pool, fn($a, $b) => $b['match']['confidence'] <=> $a['match']['confidence']);
            return $pool[0];
        };

        $rep = $pick_representative($by_l2[$winner_norm]);
        $rm  = $rep['match'];
        $proposed = [
            'level_0'           => $rm['level_0'],
            'level_1'           => $rm['level_1'],
            'sub_category_code' => $rm['sub_category_code'],
            'level_2'           => $rm['level_2'],
            'level_3'           => $rm['level_3'],
        ];
        $mapping_method = $rm['method'];
        $confidence     = $rm['confidence'];
        $matched_term   = $rep['matched_term'];
        $block_reason   = $rep['block_reason'];

        if ($clear_winner && $sufficient_coverage) {
            $dominant_basis = $single ? 'single' : 'majority';
            if ($confidence >= 80)      $status = 'mapped';
            elseif ($confidence >= 60)  $status = 'needs_review';
            else                        $status = 'unresolved';

            $basis_txt = $single
                ? 'single line'
                : sprintf('majority %d/%d mapped lines (%.0f%%)', $winner_count, $total_mapped, $winner_pct * 100);
            $reason = $rm['reason'] . '; ' . $basis_txt;
            if ($total_mapped < $distinct_count) {
                $reason .= sprintf('; %d/%d distinct descriptions unresolved',
                    $distinct_count - $total_mapped, $distinct_count);
            }
        } elseif ($clear_winner && !$sufficient_coverage) {
            // Agreement among the matched lines is clear, but they cover too
            // little of the PO's actual scope to trust (e.g. 1/6 distinct
            // lines). Don't accept the thin majority as mapped/needs_review —
            // defer instead. $proposed/$mapping_method above are left as the
            // thin-majority guess purely for CSV/audit visibility; $status
            // below is what actually matters: 'unresolved' routes this PO
            // into the existing AI fallback (which sees ALL distinct lines
            // and classifies holistically) when --use-ai is on, or leaves it
            // visible in the Manual Assignments queue otherwise.
            $dominant_basis = 'low_coverage';
            $status         = 'unresolved';
            $reason = sprintf(
                'majority %d/%d mapped lines (%.0f%%) but only %d/%d distinct descriptions resolved (%.0f%% coverage) — deferred to AI/manual review',
                $winner_count, $total_mapped, $winner_pct * 100,
                $total_mapped, $distinct_count, $coverage * 100
            );
        } else {
            // Tie / no >=60% winner -> flag for review; surface the lead candidate.
            $dominant_basis = 'ambiguous';
            $status         = 'needs_review';
            $reason = sprintf(
                'ambiguous — leading Sub Category only %d/%d mapped lines (%.0f%%), no >=60%% winner%s',
                $winner_count, $total_mapped, $winner_pct * 100, $tie ? ' (tie)' : ''
            );
        }
    } else {
        // No line mapped — summarize the dominant block reasons for diagnostics.
        $br = [];
        foreach ($line_results as $lr) {
            if ($lr['block_reason'] !== '' && !in_array($lr['block_reason'], $br, true)) {
                $br[] = $lr['block_reason'];
            }
        }
        $block_reason = implode(' || ', array_slice($br, 0, 2));
        if (strlen($block_reason) > 220) $block_reason = substr($block_reason, 0, 217) . '...';
    }

    // ── AI fallback (controlled): UNRESOLVED POs only, hard-capped by --limit ──
    $ai_used              = 'no';
    $ai_raw_response      = '';
    $ai_validation_status = '';
    $ai_validation_error  = '';
    $ai_review_triggered  = 'no';
    $ai_review_reason     = '';
    $existing_po_status   = $existing_classified[$po] ?? null;
    $already_classified   = in_array($existing_po_status, ['mapped', 'needs_review', 'resolved'], true);
    if ($already_classified) {
        // Row was classified in a prior run — do not call AI or overwrite.
        $sum['ai_skipped_existing_classified']++;
    } elseif ($opt_use_ai && $status === 'unresolved' && $sum['ai_calls'] < $ai_limit) {
        // Distinct, non-empty descriptions (raw signal), bounded for prompt size.
        $desc_for_ai = [];
        foreach ($distinct_descs as $d) {
            $d = trim((string)$d);
            if ($d === '') continue;
            $desc_for_ai[] = strlen($d) > 200 ? substr($d, 0, 197) . '...' : $d;
            if (count($desc_for_ai) >= 50) break;
        }
        if (!empty($desc_for_ai)) {
            $ai_used = 'yes';
            $sum['ai_calls']++;
            $prompt = build_ai_prompt(
                (string)$data['vendor_name'],
                implode(' | ', $data['ticket_types']),
                $desc_for_ai,
                $tax_prompt_json
            );
            try {
                $raw = call_gemini_api($prompt, 3, gemini_key_po_taxonomy());
                $ai_raw_response = strlen($raw) > 4000 ? substr($raw, 0, 3997) . '...' : $raw;
                $parsed = json_decode(strip_json_fences($raw), true);
                if (!is_array($parsed)) {
                    $sum['ai_invalid']++;
                    $mapping_method       = 'ai_invalid';
                    $status               = 'unresolved';
                    $confidence           = 0;
                    $dominant_basis       = 'ai';
                    $ai_validation_status = 'invalid';
                    $ai_validation_error  = 'JSON decode failed';
                    $reason               = 'AI fallback: response was not valid JSON';
                } else {
                    $v = ai_validate($parsed, $valid_l2, $valid_l2_l3);
                    if (!$v['valid']) {
                        $sum['ai_invalid']++;
                        $mapping_method       = 'ai_invalid';
                        $status               = 'unresolved';
                        $confidence           = 0;
                        $dominant_basis       = 'ai';
                        $ai_validation_status = 'invalid';
                        $ai_validation_error  = $v['error'];
                        $reason               = 'AI fallback invalid: ' . $v['error'];
                    } else {
                        $sum['ai_valid']++;
                        $row = $v['row'];
                        $proposed = [
                            'level_0'           => $row['level_0'] ?? '',
                            'level_1'           => $row['level_1'] ?? '',
                            'sub_category_code' => $row['sub_category_code'] ?? '',
                            'level_2'           => $row['level_2'] ?? '',
                            'level_3'           => $v['with_l3'] ? ($row['level_3'] ?? '') : '',
                        ];
                        $mapping_method       = 'ai';
                        $confidence           = $v['confidence'];
                        $dominant_basis       = 'ai';
                        $matched_term         = $v['with_l3'] ? ($row['level_3'] ?? '') : ($row['level_2'] ?? '');
                        $ai_validation_status = 'valid';

                        // ── Server-side review routing ───────────────────────
                        // High AI self-confidence alone is NOT enough to auto-map.
                        // Collect every reason this result should be human-reviewed.
                        $review_reasons = [];

                        // (a) Level 3 not resolved (null/blank/"ANY") -> L2-only.
                        $raw_l3 = array_key_exists('taxonomy_level_3', $parsed)
                                ? strtoupper(trim((string)($parsed['taxonomy_level_3'] ?? ''))) : '';
                        $l3_placeholder = in_array($raw_l3, ['', 'ANY', 'ALL', 'N/A', 'NONE'], true);
                        if (!$v['with_l3'] || $l3_placeholder) {
                            $review_reasons[] = 'level_3 null/blank/ANY (Level-2-only classification)';
                        }
                        // (b) Multi-line PO resolved only at Level 2.
                        if ($distinct_count > 1 && !$v['with_l3']) {
                            $review_reasons[] = sprintf('%d distinct descriptions but only Level 2 selected', $distinct_count);
                        }
                        // (c) Confidence below the auto-map floor.
                        if ($confidence < 90) {
                            $review_reasons[] = sprintf('confidence %d < 90', $confidence);
                        }
                        // (d) Ambiguity language in the AI's own reason. NB: 'general'
                        // and 'broad' were dropped — they collide with ordinary prose
                        // and the valid category name "General Supplies" (false positives).
                        $amb_terms = ['mixed', 'multiple', 'ambiguous', 'fallback',
                                      'cannot determine', 'unclear'];
                        $lc_reason = strtolower($v['reason']);
                        $amb_hits = [];
                        foreach ($amb_terms as $t) {
                            if (strpos($lc_reason, $t) !== false) $amb_hits[] = $t;
                        }
                        if (!empty($amb_hits)) {
                            $review_reasons[] = 'ambiguity indicator(s) in AI reason: ' . implode(', ', $amb_hits);
                        }

                        // Auto-map ONLY when no review reason fired. By construction
                        // that guarantees confidence >= 90 AND a resolved Level 3.
                        if (empty($review_reasons)) {
                            $status              = 'mapped';
                            $ai_review_triggered = 'no';
                            $ai_review_reason    = '';
                            $reason              = sprintf('AI (%d%%): %s', $confidence, $v['reason']);
                            $sum['ai_mapped']++;
                        } else {
                            $status              = 'needs_review';
                            $ai_review_triggered = 'yes';
                            $ai_review_reason    = implode(' | ', $review_reasons);
                            $reason              = sprintf('AI (%d%%) needs review [%s]: %s',
                                                           $confidence, $ai_review_reason, $v['reason']);
                            $sum['ai_needs_review']++;
                            $sum['ai_review_triggered']++;
                        }
                    }
                }
            } catch (Throwable $e) {
                $sum['ai_invalid']++;
                $mapping_method       = 'ai_invalid';
                $status               = 'unresolved';
                $confidence           = 0;
                $dominant_basis       = 'ai';
                $ai_validation_status = 'api_error';
                $ai_validation_error  = str_replace(["\r", "\n"], ' ', $e->getMessage());
                $reason               = 'AI fallback: API call failed';
            }
        }
    }

    // Candidate audit JSON: vote tally + per-line resolution (cleaned text).
    $cand_lines = [];
    foreach ($line_results as $lr) {
        $d = preg_replace('/\s+/', ' ', str_replace('"', "'", (string)$lr['clean']));
        if (strlen($d) > 80) $d = substr($d, 0, 77) . '...';
        $m = $lr['match'];
        $cand_lines[] = [
            'desc'   => $d,
            'method' => $m['method']     ?? 'unresolved',
            'conf'   => $m['confidence'] ?? 0,
            'term'   => $lr['matched_term'] !== '' ? $lr['matched_term'] : null,
            'l2'     => $m['level_2']     ?? null,
            'l3'     => ($m && $m['level_3'] !== '') ? $m['level_3'] : null,
        ];
    }
    $vote_tally = [];
    foreach ($mapped_lines as $lr) {
        $lbl = $lr['match']['level_2'];
        $vote_tally[$lbl] = ($vote_tally[$lbl] ?? 0) + 1;
    }
    arsort($vote_tally);
    $candidate_json = json_encode(
        ['votes' => $vote_tally, 'lines' => $cand_lines],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    // Samples (first 3 distinct, truncated, " | " joined).
    $make_sample = function (array $vals): string {
        $parts = [];
        foreach (array_slice($vals, 0, 3) as $i => $d) {
            $c = str_replace(["\r", "\n", '"'], [' ', ' ', "'"], (string)$d);
            $c = strlen($c) > 120 ? substr($c, 0, 117) . '...' : $c;
            $parts[] = '[' . ($i + 1) . '] ' . $c;
        }
        if (count($vals) > 3) $parts[] = '... +' . (count($vals) - 3) . ' more';
        return implode(' | ', $parts);
    };
    $sample       = $make_sample($distinct_descs);
    $clean_sample = $make_sample($clean_distinct);

    // Accumulate summary stats.
    if ($status === 'mapped')            $sum['mapped']++;
    elseif ($status === 'needs_review')  $sum['needs_review']++;
    else                                 $sum['unresolved']++;

    switch ($mapping_method) {
        case 'exact_l3':   $sum['exact_l3']++; break;
        case 'exact_l2':   $sum['exact_l2']++; break;
        case 'fuzzy_l3':   $sum['fuzzy_l3']++; break;
        case 'fuzzy_l2':   $sum['fuzzy_l2']++; break;
        case 'ai':         $sum['ai_method']++; break;
        case 'ai_invalid': $sum['ai_invalid_method']++; break;
        default:           $sum['unresolved_method']++; break;
    }
    $sum['confidence_total'] += $confidence;

    if ($proposed['level_2'] !== '') {
        $key = $proposed['level_2'];
        $sum['proposed_l2_freq'][$key] = ($sum['proposed_l2_freq'][$key] ?? 0) + 1;
    }
    if ($status === 'unresolved') {
        $v = $data['vendor_name'];
        $sum['unresolved_vendor_freq'][$v] = ($sum['unresolved_vendor_freq'][$v] ?? 0) + 1;
    }

    $csv_rows[] = [
        'po_number'                       => $po,
        'vendor_name'                     => $data['vendor_name'],
        'requestor_names'                 => implode(' | ', $data['requestors']),
        'p_group_values'                  => implode(' | ', $data['p_groups']),
        'ticket_type_values'              => implode(' | ', $data['ticket_types']),
        'line_item_count'                 => $line_count,
        'distinct_item_description_count' => $distinct_count,
        'item_descriptions_sample'        => $sample,
        'proposed_taxonomy_level_0'       => $proposed['level_0'],
        'proposed_taxonomy_level_1'       => $proposed['level_1'],
        'proposed_sub_category_code'      => $proposed['sub_category_code'],
        'proposed_taxonomy_level_2'       => $proposed['level_2'],
        'proposed_taxonomy_level_3'       => $proposed['level_3'],
        'mapping_method'                  => $mapping_method,
        'mapping_confidence'              => $confidence,
        'dominant_basis'                  => $dominant_basis,
        'status'                          => $status,
        'mapping_reason'                  => $reason,
        'candidate_taxonomy_json'         => $candidate_json,
        // Diagnostics (Refinement D)
        'cleaned_description_sample'      => $clean_sample,
        'match_block_reason'              => $block_reason,
        'matched_term'                    => $matched_term,
        // AI fallback diagnostics
        'ai_used'                         => $ai_used,
        'ai_validation_status'            => $ai_validation_status,
        'ai_validation_error'             => $ai_validation_error,
        'ai_review_triggered'             => $ai_review_triggered,
        'ai_review_reason'                => $ai_review_reason,
        'ai_raw_response'                 => $ai_raw_response,
    ];

    // Capture the precise payload for the (optional) DB write pass. Unlike the
    // CSV row, JSON columns carry the FULL distinct lists (not truncated samples).
    if ($opt_apply) {
        $apply_rows[] = [
            'po_number'          => $po,
            'vendor_name'        => $data['vendor_name'],
            'requestors'         => $data['requestors'],   // array -> JSON
            'descriptions'       => $distinct_descs,        // array -> JSON
            'line_item_count'    => $line_count,
            'p_group'            => implode(' | ', $data['p_groups']),
            'ticket_type'        => implode(' | ', $data['ticket_types']),
            'level_0'            => $proposed['level_0'],
            'level_1'            => $proposed['level_1'],
            'sub_category_code'  => $proposed['sub_category_code'],
            'level_2'            => $proposed['level_2'],
            'level_3'            => $proposed['level_3'],
            'mapping_method'     => $mapping_method,
            'mapping_confidence' => $confidence,
            'mapping_reason'     => $reason,
            'candidate_json'     => $candidate_json,
            'dominant_basis'     => $dominant_basis,
            'status'             => $status,
        ];
    }
}

// ── Sort CSV: unresolved & needs_review first, then PO ──────────────────────
usort($csv_rows, function ($a, $b) {
    $order = ['unresolved' => 0, 'needs_review' => 1, 'mapped' => 2];
    $sa = $order[$a['status']] ?? 3;
    $sb = $order[$b['status']] ?? 3;
    if ($sa !== $sb) return $sa - $sb;
    return strcmp($a['po_number'], $b['po_number']);
});

// ── Write CSV ───────────────────────────────────────────────────────────────
$fp = fopen($csv_path, 'w');
if (!$fp) {
    exit("ERROR: Cannot open output file: {$csv_path}\n");
}
fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

$headers = [
    'po_number', 'vendor_name', 'requestor_names', 'p_group_values',
    'ticket_type_values', 'line_item_count', 'distinct_item_description_count',
    'item_descriptions_sample', 'proposed_taxonomy_level_0',
    'proposed_taxonomy_level_1', 'proposed_sub_category_code',
    'proposed_taxonomy_level_2', 'proposed_taxonomy_level_3', 'mapping_method',
    'mapping_confidence', 'dominant_basis', 'status', 'mapping_reason',
    'candidate_taxonomy_json',
    'cleaned_description_sample', 'match_block_reason', 'matched_term',
    'ai_used', 'ai_validation_status', 'ai_validation_error',
    'ai_review_triggered', 'ai_review_reason', 'ai_raw_response',
];
fputcsv($fp, $headers);
foreach ($csv_rows as $row) {
    fputcsv($fp, array_map(fn($k) => $row[$k], $headers));
}
fclose($fp);

// ── DB write pass (--apply only) ─────────────────────────────────────────────
// Upsert one row per PO into vendor_eval_po_taxonomy. Runs in a single
// transaction; any failure rolls back the whole batch and exits non-zero.
// Review fields (reviewed_by/reviewed_at/review_notes) are NEVER written, and
// rows a human has marked status='resolved' are SKIPPED (manual override wins).
$writes = [
    'attempted'                  => 0,
    'written'                    => 0,   // INSERTs + UPDATEs actually sent
    'mapped'                     => 0,
    'needs_review'               => 0,
    'unresolved'                 => 0,
    'skipped'                    => 0,   // pre-existing status='resolved' rows left untouched
    'skipped_existing_classified' => 0,  // pre-existing mapped/needs_review rows preserved
];
if ($opt_apply) {
    // mapping_method / dominant_basis are constrained ENUMs that do NOT cover all
    // engine outputs (e.g. 'unresolved', 'ai_invalid', 'ambiguous', 'ai'). Any
    // value outside the enum is written as NULL rather than risking a truncation.
    $valid_methods = ['exact_l3', 'exact_l2', 'fuzzy_l3', 'fuzzy_l2', 'ai', 'manual'];
    $valid_basis   = ['single', 'majority', 'manual'];
    $valid_status  = ['pending', 'mapped', 'needs_review', 'resolved', 'unresolved'];
    $nn = fn($v) => (trim((string)$v) === '') ? null : (string)$v;   // '' -> NULL
    $cap = fn($v, $len) => ($v === null) ? null : substr($v, 0, $len);

    echo "\n";
    echo "Applying mapped results to vendor_eval_po_taxonomy...\n";

    // Protect manual overrides: collect PO numbers already marked 'resolved'.
    $resolved_set = [];
    try {
        $rs = $pdo->query("SELECT po_number FROM vendor_eval_po_taxonomy WHERE status = 'resolved'");
        foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $rp) {
            $resolved_set[(string)$rp] = true;
        }
    } catch (Throwable $e) {
        exit("\nDB WRITE ABORTED: could not read existing 'resolved' rows: "
           . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n\n");
    }

    $sql = "INSERT INTO vendor_eval_po_taxonomy
        (po_number, vendor_name, requestor_names_json, item_descriptions_json,
         line_item_count, p_group, ticket_type,
         taxonomy_level_0, taxonomy_level_1, sub_category_code,
         taxonomy_level_2, taxonomy_level_3,
         mapping_method, mapping_confidence, mapping_reason,
         candidate_taxonomy_json, dominant_basis, status, mapped_at, updated_at)
        VALUES
        (:po_number, :vendor_name, :requestor_names_json, :item_descriptions_json,
         :line_item_count, :p_group, :ticket_type,
         :taxonomy_level_0, :taxonomy_level_1, :sub_category_code,
         :taxonomy_level_2, :taxonomy_level_3,
         :mapping_method, :mapping_confidence, :mapping_reason,
         :candidate_taxonomy_json, :dominant_basis, :status, :mapped_at, :updated_at)
        ON DUPLICATE KEY UPDATE
         vendor_name             = VALUES(vendor_name),
         requestor_names_json    = VALUES(requestor_names_json),
         item_descriptions_json  = VALUES(item_descriptions_json),
         line_item_count         = VALUES(line_item_count),
         p_group                 = VALUES(p_group),
         ticket_type             = VALUES(ticket_type),
         taxonomy_level_0        = VALUES(taxonomy_level_0),
         taxonomy_level_1        = VALUES(taxonomy_level_1),
         sub_category_code       = VALUES(sub_category_code),
         taxonomy_level_2        = VALUES(taxonomy_level_2),
         taxonomy_level_3        = VALUES(taxonomy_level_3),
         mapping_method          = VALUES(mapping_method),
         mapping_confidence      = VALUES(mapping_confidence),
         mapping_reason          = VALUES(mapping_reason),
         candidate_taxonomy_json = VALUES(candidate_taxonomy_json),
         dominant_basis          = VALUES(dominant_basis),
         status                  = VALUES(status),
         mapped_at               = VALUES(mapped_at),
         updated_at              = VALUES(updated_at)";

    $now = date('Y-m-d H:i:s');
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        foreach ($apply_rows as $ar) {
            // Never clobber a human-resolved row.
            if (isset($resolved_set[$ar['po_number']])) {
                $writes['skipped']++;
                continue;
            }
            // Preserve existing mapped/needs_review rows from prior apply runs.
            if (isset($existing_classified[$ar['po_number']])
                && in_array($existing_classified[$ar['po_number']], ['mapped', 'needs_review'], true)
            ) {
                $writes['skipped_existing_classified']++;
                continue;
            }
            $writes['attempted']++;

            $method = in_array($ar['mapping_method'], $valid_methods, true) ? $ar['mapping_method'] : null;
            $basis  = in_array($ar['dominant_basis'],  $valid_basis,   true) ? $ar['dominant_basis']  : null;
            $status = in_array($ar['status'],          $valid_status,  true) ? $ar['status']          : 'unresolved';

            $stmt->execute([
                ':po_number'               => (string)$ar['po_number'],
                ':vendor_name'             => $nn($ar['vendor_name']),
                ':requestor_names_json'    => json_encode(array_values($ar['requestors']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':item_descriptions_json'  => json_encode(array_values($ar['descriptions']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':line_item_count'         => (int)$ar['line_item_count'],
                ':p_group'                 => $cap($nn($ar['p_group']), 50),
                ':ticket_type'             => $cap($nn($ar['ticket_type']), 100),
                ':taxonomy_level_0'        => $nn($ar['level_0']),
                ':taxonomy_level_1'        => $nn($ar['level_1']),
                ':sub_category_code'       => $cap($nn($ar['sub_category_code']), 50),
                ':taxonomy_level_2'        => $nn($ar['level_2']),
                ':taxonomy_level_3'        => $nn($ar['level_3']),
                ':mapping_method'          => $method,
                ':mapping_confidence'      => max(0, min(255, (int)$ar['mapping_confidence'])),
                ':mapping_reason'          => $nn($ar['mapping_reason']),
                ':candidate_taxonomy_json' => $nn($ar['candidate_json']),
                ':dominant_basis'          => $basis,
                ':status'                  => $status,
                ':mapped_at'               => $now,
                ':updated_at'              => $now,
            ]);

            $writes['written']++;
            if      ($status === 'mapped')       $writes['mapped']++;
            elseif  ($status === 'needs_review') $writes['needs_review']++;
            elseif  ($status === 'unresolved')   $writes['unresolved']++;
        }
        $pdo->commit();
        echo "  Done. Transaction committed.\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "\nDB WRITE FAILED — transaction rolled back. No rows persisted.\n");
        fwrite(STDERR, "  Error: " . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n\n");
        exit(1);
    }
}

// ── Console summary ─────────────────────────────────────────────────────────
function top_n(array $freq, int $n = 20): array {
    arsort($freq);
    return array_slice($freq, 0, $n, true);
}

$avg_conf   = $total_pos > 0 ? round($sum['confidence_total'] / $total_pos, 1) : 0;
$pct_mapped = $total_pos > 0 ? round($sum['mapped'] / $total_pos * 100, 1) : 0;
$elapsed    = round(microtime(true) - $tool_start, 2);

echo "  Done.\n\n";
echo "=======================================================================\n";
echo " MAPPING SUMMARY (DRY-RUN)\n";
echo "=======================================================================\n\n";

printf("  Scope used:              %s\n", $scope === 'all' ? "ALL (no pr_date cutoff)" : "RECENT (pr_date >= 2026-05-01)");
printf("  Total POs processed:     %d\n",   $total_pos);
echo "\n";
printf("  mapped:                  %d\n",   $sum['mapped']);
printf("  needs_review:            %d\n",   $sum['needs_review']);
printf("  unresolved:              %d\n",   $sum['unresolved']);
echo "\n";
printf("  exact_l3:                %d\n",   $sum['exact_l3']);
printf("  exact_l2:                %d\n",   $sum['exact_l2']);
printf("  fuzzy_l3:                %d\n",   $sum['fuzzy_l3']);
printf("  fuzzy_l2:                %d\n",   $sum['fuzzy_l2']);
printf("  (unresolved method):     %d\n",   $sum['unresolved_method']);
echo "\n";
printf("  Average confidence:      %.1f\n", $avg_conf);
printf("  Percentage mapped:       %.1f%%\n", $pct_mapped);
echo "\n";

echo "── AI fallback ──────────────────────────────────────────────────────────\n";
printf("  AI enabled:              %s\n", $opt_use_ai ? 'yes' : 'no');
printf("  AI limit:                %s\n", $opt_use_ai ? (string)$ai_limit : 'n/a');
printf("  AI calls made:           %d\n", $sum['ai_calls']);
printf("  AI valid responses:      %d\n", $sum['ai_valid']);
printf("  AI invalid responses:    %d\n", $sum['ai_invalid']);
printf("  AI -> mapped:            %d\n", $sum['ai_mapped']);
printf("  AI -> needs_review:      %d\n", $sum['ai_needs_review']);
printf("  AI review-triggered:     %d\n", $sum['ai_review_triggered']);
printf("  AI skipped (already classified in DB): %d\n", $sum['ai_skipped_existing_classified']);
echo "\n";
if ($opt_use_ai) {
    printf("  NOTE: SAMPLE dry-run — AI was capped at --limit=%d unresolved PO(s).\n", $ai_limit);
    echo  "        The mapped / needs_review / unresolved totals above are NOT a\n";
    echo  "        full production result; only the sampled POs went through AI.\n";
    echo "\n";
}

echo "── DB write ─────────────────────────────────────────────────────────────\n";
printf("  DB writes enabled:       %s\n", $opt_apply ? 'yes' : 'no');
if ($opt_apply) {
    printf("  Target DB / host:        %s @ %s\n", $confirm_db, $confirm_host);
    printf("  Rows inserted/updated:   %d\n", $writes['written']);
    printf("    mapped written:        %d\n", $writes['mapped']);
    printf("    needs_review written:  %d\n", $writes['needs_review']);
    printf("    unresolved written:    %d\n", $writes['unresolved']);
    printf("  Skipped (manual resolved): %d\n", $writes['skipped']);
    printf("  Skipped (existing mapped/needs_review): %d\n", $writes['skipped_existing_classified']);
} else {
    echo  "  (dry-run — CSV only, nothing written to vendor_eval_po_taxonomy)\n";
}
echo "\n";

echo "── Top 20 proposed Sub Category (level_2) ───────────────────────────────\n";
$top_l2 = top_n($sum['proposed_l2_freq']);
if (empty($top_l2)) {
    echo "  (none)\n";
} else {
    foreach ($top_l2 as $k => $v) {
        printf("  %-50s %d POs\n", strlen($k) > 50 ? substr($k, 0, 47) . '...' : $k, $v);
    }
}
echo "\n";

echo "── Top 20 unresolved vendors ────────────────────────────────────────────\n";
$top_unv = top_n($sum['unresolved_vendor_freq']);
if (empty($top_unv)) {
    echo "  (none)\n";
} else {
    foreach ($top_unv as $k => $v) {
        printf("  %-50s %d POs\n", strlen($k) > 50 ? substr($k, 0, 47) . '...' : $k, $v);
    }
}
echo "\n";

echo "=======================================================================\n";
printf(" Output CSV:  %s\n", $csv_path);
printf(" Elapsed:     %.2f seconds\n", $elapsed);
printf(" Mode:        %s\n", $opt_apply ? 'APPLY (DB WRITE)' : 'DRY-RUN');
printf(" DB writes:   %s\n", $opt_apply
    ? sprintf("%d row(s) upserted, %d skipped", $writes['written'], $writes['skipped'])
    : "NONE");
printf(" AI calls:    %s\n", $opt_use_ai ? ($sum['ai_calls'] . " (sample, capped at {$ai_limit})") : "NONE");
echo " WA/email:    NONE\n";
echo "=======================================================================\n\n";
