<?php
// tools/refresh_vendor_eval_po_taxonomy_from_master_data.php
//
// Vendor Evaluation v2 — refreshes `vendor_eval_po_taxonomy` from the
// authoritative source: prs.master_data_id -> master_data.id.
//
// This is the curated-ID successor to the fuzzy item-description matcher in
// tools/run_po_taxonomy_mapping.php. Per supervisor direction, main/production
// already assigns master_data_id per PR line item via a human PIC workflow
// (ajax_update_pr_taxonomy.php on main); once that data exists, joining
// prs.master_data_id -> master_data gives a directly authoritative taxonomy
// classification per line item instead of guessing from free-text
// descriptions. master_data.level_2/level_3 use the SAME taxonomy vocabulary
// as v_item_taxonomy's Sub Category / Commodity (confirmed by data
// comparison — see db_backups/vendor_eval_v2_deployment_handover.txt).
//
// vendor_eval_po_taxonomy remains the PO-level routing bridge/cache; this
// tool only changes WHERE its values come from. The old fuzzy mapper
// (run_po_taxonomy_mapping.php) is kept as a fallback for POs whose lines
// have no master_data_id yet — it is NOT deleted or disabled by this tool.
//
// Usage:
//   C:\xampp\php\php.exe tools/refresh_vendor_eval_po_taxonomy_from_master_data.php --preview
//   C:\xampp\php\php.exe tools/refresh_vendor_eval_po_taxonomy_from_master_data.php --apply \
//       --confirm-db=DB --confirm-host=HOST [--fallback-existing] [--include-needs-review]
//
//   --preview               Default mode. SELECT only, no writes. Prints coverage
//                           + comparison report and a CSV export.
//   --apply                 Enable DB WRITE mode (upsert into vendor_eval_po_taxonomy).
//                           Requires --confirm-db and --confirm-host.
//   --confirm-db=DB         Required with --apply. Must equal DATABASE() or apply aborts.
//   --confirm-host=HOST     Required with --apply. Must equal @@hostname or apply aborts.
//   --fallback-existing     POs with zero master_data_id coverage are re-upserted
//                           using their CURRENT vendor_eval_po_taxonomy values
//                           unchanged (keeps them touched/refreshed in the run
//                           without inventing new data). Without this flag such
//                           POs are left completely untouched.
//   --include-needs-review  Also upsert POs where master_data-derived lines
//                           disagree (status=needs_review). Without this flag,
//                           --apply only writes POs with full agreement
//                           (status=mapped); disagreeing POs are reported but
//                           not written, leaving prior data intact.
//
// Output:
//   db_backups/local_runtime_exports/po_taxonomy_master_data_refresh_YYYYMMDD_HHMMSS.csv
//
// SAFETY:
//   - CLI only (refuses browser/web access).
//   - SELECT queries only UNLESS --apply is passed.
//   - --apply writes are triple-gated: --confirm-db must equal DATABASE(),
//     --confirm-host must equal @@hostname, and USER() must contain 'localhost'.
//   - --apply runs inside a single transaction; any write error rolls back the
//     whole batch and exits non-zero.
//   - --apply never touches review fields (reviewed_by/reviewed_at/review_notes)
//     and SKIPS rows a human has already marked status='resolved'.
//   - No AI/Gemini calls. No WA/email. No cron.
//   - mapping_method is written as 'manual': the existing ENUM
//     ('exact_l3','exact_l2','fuzzy_l3','fuzzy_l2','ai','manual') has no
//     master_data_id-specific value, and 'manual' is the closest existing
//     label for a human-curated (non-fuzzy, non-AI) source. mapping_reason
//     always states "master_data_id" explicitly so the real source is never
//     ambiguous. Do not widen the ENUM for this — report instead if it
//     becomes a real limitation.

// ── CLI guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/refresh_vendor_eval_po_taxonomy_from_master_data.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

$tool_start = microtime(true);

// ── Argument parsing ─────────────────────────────────────────────────────────
$argv_in = $argv ?? [];
$opt_apply             = in_array('--apply', $argv_in, true);
$opt_preview           = in_array('--preview', $argv_in, true) || !$opt_apply;
$opt_fallback_existing = in_array('--fallback-existing', $argv_in, true);
$opt_include_needs_review = in_array('--include-needs-review', $argv_in, true);
$confirm_db   = null;
$confirm_host = null;
foreach ($argv_in as $a) {
    if (preg_match('/^--confirm-db=(.+)$/', $a, $m)) {
        $confirm_db = $m[1];
    } elseif (preg_match('/^--confirm-host=(.+)$/', $a, $m)) {
        $confirm_host = $m[1];
    } elseif ($a === '--confirm-db' || $a === '--confirm-host') {
        exit("ERROR: {$a} requires a value, e.g. {$a}=NAME\n");
    }
}
if ($opt_apply) {
    if ($confirm_db === null || $confirm_db === '') {
        exit("\nSAFETY ABORT: --apply requires --confirm-db=DATABASE_NAME.\n\n");
    }
    if ($confirm_host === null || $confirm_host === '') {
        exit("\nSAFETY ABORT: --apply requires --confirm-host=HOSTNAME.\n\n");
    }
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
$_SERVER['REQUEST_URI'] = '';
define('RUNNING_AS_CLI_TOOL', true);
$root = dirname(__DIR__);
require_once $root . '/workspace/config/database.php'; // provides $pdo (PDO)

// ── Apply target verification — POST-CONNECT, FAIL-FAST ─────────────────────
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
    if ($cur_db !== $confirm_db)     $apply_errs[] = "DATABASE() '{$cur_db}' != --confirm-db '{$confirm_db}'";
    if ($cur_host !== $confirm_host) $apply_errs[] = "@@hostname '{$cur_host}' != --confirm-host '{$confirm_host}'";
    if (stripos($cur_user, 'localhost') === false) $apply_errs[] = "USER() '{$cur_user}' does not contain 'localhost'";
    if (!empty($apply_errs)) {
        exit("\nSAFETY ABORT: --apply target verification FAILED. No rows written.\n  - "
           . implode("\n  - ", $apply_errs) . "\n\n");
    }
    echo sprintf("[--apply] DB WRITE mode ENABLED. Target verified: db=%s host=%s user=%s\n",
        $cur_db, $cur_host, $cur_user);
}

// ── master_data_id column presence check ─────────────────────────────────────
$has_col = (bool)$pdo->query("SHOW COLUMNS FROM prs LIKE 'master_data_id'")->fetch();
if (!$has_col) {
    exit("\nABORT: prs.master_data_id does not exist on this database. Run\n"
       . "db_backups/vendor_eval_v2_prs_master_data_id.sql first.\n\n");
}

$mode_label = $opt_apply ? 'APPLY / DB WRITE' : 'PREVIEW (DRY-RUN)';
echo "\n=======================================================================\n";
echo " PO Taxonomy Refresh from master_data_id ({$mode_label})\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n\n";

// ── In-scope PO population — mirrors run_po_taxonomy_mapping.php's base_where
// (minus the --recent pr_date cutoff, so coverage reporting sees the full
// backlog, not just the last month) ─────────────────────────────────────────
$base_where = "
    WHERE p.po_number IS NOT NULL
      AND p.po_number != ''
      AND p.gr_date IS NOT NULL
      AND p.gr_date <= CURDATE()
      AND p.vendor_name IS NOT NULL
      AND p.vendor_name != ''
      AND LOWER(IFNULL(p.ticket_type,'')) NOT IN ('intercompany','intercompany new store')
";

echo "Fetching in-scope prs line items (with master_data join)...\n";
$lines_sql = "
    SELECT
        p.po_number,
        p.vendor_name,
        p.master_data_id,
        md.level_0, md.level_1, md.level_2, md.level_3,
        vt.sub_category_code
    FROM prs p
    LEFT JOIN master_data md ON p.master_data_id = md.id
    LEFT JOIN v_item_taxonomy vt ON vt.level_2 = md.level_2 AND vt.level_3 = md.level_3
    {$base_where}
    ORDER BY p.po_number
";
$all_lines = $pdo->query($lines_sql)->fetchAll(PDO::FETCH_ASSOC);
echo "  Fetched " . count($all_lines) . " line items.\n\n";

// ── Group by PO ───────────────────────────────────────────────────────────────
$po_map = [];
foreach ($all_lines as $row) {
    $po = $row['po_number'];
    if (!isset($po_map[$po])) {
        $po_map[$po] = ['po_number' => $po, 'vendor_name' => $row['vendor_name'], 'lines' => []];
    }
    $po_map[$po]['lines'][] = $row;
}
$total_pos = count($po_map);

// ── Load existing vendor_eval_po_taxonomy for comparison / fallback ──────────
$existing = [];
$er = $pdo->query("SELECT po_number, taxonomy_level_0, taxonomy_level_1, sub_category_code,
                           taxonomy_level_2, taxonomy_level_3, mapping_method, mapping_confidence,
                           status, vendor_name, requestor_names_json, item_descriptions_json,
                           line_item_count, p_group, ticket_type
                    FROM vendor_eval_po_taxonomy");
foreach ($er->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $existing[(string)$row['po_number']] = $row;
}

// ── Per-PO aggregation from master_data ──────────────────────────────────────
$stats = [
    'total_pos' => $total_pos,
    'with_coverage' => 0,
    'without_coverage' => 0,
    'agrees_with_existing' => 0,
    'differs_from_existing' => 0,
    'no_existing_to_compare' => 0,
    'resolved_from_needs_review' => 0,
    'still_needs_review' => 0,
    'now_mapped' => 0,
];
$results = []; // po_number => computed result
$sample_diffs = [];

foreach ($po_map as $po => $data) {
    $with_md = array_values(array_filter($data['lines'], fn($l) => $l['master_data_id'] !== null && $l['level_2'] !== null));
    $line_count = count($data['lines']);
    $md_count   = count($with_md);

    if ($md_count === 0) {
        $stats['without_coverage']++;
        $results[$po] = ['coverage' => false];
        continue;
    }
    $stats['with_coverage']++;

    // Vote on level_2|level_3 combo across lines that have master_data_id.
    $votes = [];
    foreach ($with_md as $l) {
        $key = ($l['level_2'] ?? '') . '||' . ($l['level_3'] ?? '');
        $votes[$key] = ($votes[$key] ?? 0) + 1;
    }
    arsort($votes);
    $winner_key   = array_key_first($votes);
    $winner_count = $votes[$winner_key];
    $winner_pct   = $winner_count / $md_count;
    $tie          = count(array_filter($votes, fn($c) => $c === $winner_count)) > 1;
    $distinct     = count($votes);

    $rep = null;
    foreach ($with_md as $l) {
        if (($l['level_2'] ?? '') . '||' . ($l['level_3'] ?? '') === $winner_key) { $rep = $l; break; }
    }

    if ($distinct === 1) {
        $status = 'mapped';
        $confidence = 100;
        $basis = 'single';
        $reason = sprintf('master_data_id: %d/%d line item(s) agree on Sub Category "%s" / Commodity "%s"',
            $winner_count, $md_count, $rep['level_2'], $rep['level_3'] ?: '(none)');
    } elseif (!$tie && $winner_pct >= 0.60) {
        $status = 'needs_review';
        $confidence = (int)round($winner_pct * 100);
        $basis = 'majority';
        $reason = sprintf('master_data_id: majority %d/%d line items (%.0f%%) point to Sub Category "%s" / Commodity "%s"; %d distinct combo(s) present',
            $winner_count, $md_count, $winner_pct * 100, $rep['level_2'], $rep['level_3'] ?: '(none)', $distinct);
    } else {
        $status = 'needs_review';
        $confidence = (int)round($winner_pct * 100);
        $basis = null; // 'ambiguous' is not in the dominant_basis ENUM
        $reason = sprintf('master_data_id: ambiguous — leading combo only %d/%d (%.0f%%)%s, %d distinct combos',
            $winner_count, $md_count, $winner_pct * 100, $tie ? ' (tie)' : '', $distinct);
    }
    if ($md_count < $line_count) {
        $reason .= sprintf('; %d/%d line item(s) have no master_data_id', $line_count - $md_count, $line_count);
    }

    $results[$po] = [
        'coverage' => true,
        'level_0' => $rep['level_0'], 'level_1' => $rep['level_1'],
        'sub_category_code' => $rep['sub_category_code'],
        'level_2' => $rep['level_2'], 'level_3' => $rep['level_3'],
        'status' => $status, 'confidence' => $confidence, 'basis' => $basis,
        'reason' => $reason, 'line_item_count' => $line_count,
    ];

    if ($status === 'mapped') $stats['now_mapped']++;
    else $stats['still_needs_review']++;

    // Compare vs existing.
    $ex = $existing[$po] ?? null;
    if ($ex === null) {
        $stats['no_existing_to_compare']++;
    } else {
        $same = ($ex['taxonomy_level_2'] === $rep['level_2']) && ($ex['taxonomy_level_3'] === $rep['level_3']);
        if ($same) {
            $stats['agrees_with_existing']++;
        } else {
            $stats['differs_from_existing']++;
            if (count($sample_diffs) < 15) {
                $sample_diffs[] = [
                    'po_number' => $po,
                    'existing_l2' => $ex['taxonomy_level_2'], 'existing_l3' => $ex['taxonomy_level_3'],
                    'existing_method' => $ex['mapping_method'], 'existing_status' => $ex['status'],
                    'master_data_l2' => $rep['level_2'], 'master_data_l3' => $rep['level_3'],
                    'master_data_status' => $status,
                ];
            }
            if (in_array($ex['status'], ['needs_review', 'unresolved', 'pending'], true) && $status === 'mapped') {
                $stats['resolved_from_needs_review']++;
            }
        }
    }
}

// ── Console coverage / comparison report ─────────────────────────────────────
echo "── Coverage ──────────────────────────────────────────────────────────────\n";
printf("  Total in-scope POs:              %d\n", $stats['total_pos']);
printf("  POs WITH master_data_id coverage:%d\n", $stats['with_coverage']);
printf("  POs MISSING master_data_id:      %d\n", $stats['without_coverage']);
echo "\n";
echo "── Comparison vs current vendor_eval_po_taxonomy ────────────────────────\n";
printf("  Agrees with existing:            %d\n", $stats['agrees_with_existing']);
printf("  Differs from existing:           %d\n", $stats['differs_from_existing']);
printf("  No existing row to compare:      %d\n", $stats['no_existing_to_compare']);
printf("  Resolved from needs_review/unresolved -> mapped: %d\n", $stats['resolved_from_needs_review']);
echo "\n";
echo "── master_data-derived outcome ──────────────────────────────────────────\n";
printf("  Now mapped (full agreement):     %d\n", $stats['now_mapped']);
printf("  Still needs_review (disagreement): %d\n", $stats['still_needs_review']);
echo "\n";

if (!empty($sample_diffs)) {
    echo "── Sample differences (up to 15) ────────────────────────────────────────\n";
    foreach ($sample_diffs as $d) {
        printf("  PO %s: existing[%s/%s method=%s status=%s] vs master_data[%s/%s status=%s]\n",
            $d['po_number'], $d['existing_l2'], $d['existing_l3'], $d['existing_method'], $d['existing_status'],
            $d['master_data_l2'], $d['master_data_l3'], $d['master_data_status']);
    }
    echo "\n";
}

if ($stats['with_coverage'] === 0) {
    echo "HONEST RESULT: zero POs have master_data_id coverage on this database.\n";
    echo "This is expected locally — prs.master_data_id was just added by an additive\n";
    echo "migration and nothing has populated it yet (that happens via the PIC/dashboard\n";
    echo "assignment workflow that exists on main, not on this branch). The refresh\n";
    echo "CANNOT improve local PO taxonomy mapping until master_data_id is populated,\n";
    echo "either by merging that workflow or by syncing production data.\n";
    echo "vendor_eval_po_taxonomy is left untouched; the existing fuzzy-text mapper\n";
    echo "(tools/run_po_taxonomy_mapping.php) remains the active source locally.\n\n";
}

// ── CSV export ────────────────────────────────────────────────────────────────
$export_dir = $root . '/db_backups/local_runtime_exports';
if (!is_dir($export_dir)) mkdir($export_dir, 0755, true);
$csv_path = $export_dir . '/po_taxonomy_master_data_refresh_' . date('Ymd_His') . '.csv';
$fp = fopen($csv_path, 'w');
fwrite($fp, "\xEF\xBB\xBF");
fputcsv($fp, ['po_number', 'coverage', 'level_2', 'level_3', 'status', 'confidence', 'basis', 'reason',
              'existing_l2', 'existing_l3', 'existing_method', 'existing_status']);
foreach ($results as $po => $r) {
    $ex = $existing[$po] ?? null;
    fputcsv($fp, [
        $po,
        $r['coverage'] ? 'yes' : 'no',
        $r['level_2'] ?? '', $r['level_3'] ?? '',
        $r['status'] ?? '', $r['confidence'] ?? '', $r['basis'] ?? '', $r['reason'] ?? '',
        $ex['taxonomy_level_2'] ?? '', $ex['taxonomy_level_3'] ?? '',
        $ex['mapping_method'] ?? '', $ex['status'] ?? '',
    ]);
}
fclose($fp);

// ── DB write pass (--apply only) ─────────────────────────────────────────────
$writes = ['attempted' => 0, 'written' => 0, 'skipped_resolved' => 0, 'skipped_disagreement' => 0, 'fallback_reupserted' => 0];
if ($opt_apply) {
    $resolved_set = [];
    foreach ($existing as $po => $ex) {
        if ($ex['status'] === 'resolved') $resolved_set[$po] = true;
    }

    $sql = "INSERT INTO vendor_eval_po_taxonomy
        (po_number, vendor_name, requestor_names_json, item_descriptions_json,
         line_item_count, p_group, ticket_type,
         taxonomy_level_0, taxonomy_level_1, sub_category_code,
         taxonomy_level_2, taxonomy_level_3,
         mapping_method, mapping_confidence, mapping_reason,
         dominant_basis, status, mapped_at, updated_at)
        VALUES
        (:po_number, :vendor_name, :requestor_names_json, :item_descriptions_json,
         :line_item_count, :p_group, :ticket_type,
         :taxonomy_level_0, :taxonomy_level_1, :sub_category_code,
         :taxonomy_level_2, :taxonomy_level_3,
         :mapping_method, :mapping_confidence, :mapping_reason,
         :dominant_basis, :status, :mapped_at, :updated_at)
        ON DUPLICATE KEY UPDATE
         vendor_name        = VALUES(vendor_name),
         line_item_count    = VALUES(line_item_count),
         taxonomy_level_0   = VALUES(taxonomy_level_0),
         taxonomy_level_1   = VALUES(taxonomy_level_1),
         sub_category_code  = VALUES(sub_category_code),
         taxonomy_level_2   = VALUES(taxonomy_level_2),
         taxonomy_level_3   = VALUES(taxonomy_level_3),
         mapping_method      = VALUES(mapping_method),
         mapping_confidence  = VALUES(mapping_confidence),
         mapping_reason      = VALUES(mapping_reason),
         dominant_basis       = VALUES(dominant_basis),
         status                = VALUES(status),
         mapped_at             = VALUES(mapped_at),
         updated_at            = VALUES(updated_at)";
    $valid_basis = ['single', 'majority', 'manual'];
    $nn = fn($v) => (trim((string)$v) === '') ? null : (string)$v;
    $now = date('Y-m-d H:i:s');

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare($sql);
        foreach ($results as $po => $r) {
            if (isset($resolved_set[$po])) { $writes['skipped_resolved']++; continue; }

            if (!$r['coverage']) {
                if ($opt_fallback_existing && isset($existing[$po])) {
                    $ex = $existing[$po];
                    $writes['attempted']++;
                    $stmt->execute([
                        ':po_number' => $po, ':vendor_name' => $ex['vendor_name'],
                        ':requestor_names_json' => $ex['requestor_names_json'],
                        ':item_descriptions_json' => $ex['item_descriptions_json'],
                        ':line_item_count' => $ex['line_item_count'], ':p_group' => $ex['p_group'],
                        ':ticket_type' => $ex['ticket_type'],
                        ':taxonomy_level_0' => $ex['taxonomy_level_0'], ':taxonomy_level_1' => $ex['taxonomy_level_1'],
                        ':sub_category_code' => $ex['sub_category_code'],
                        ':taxonomy_level_2' => $ex['taxonomy_level_2'], ':taxonomy_level_3' => $ex['taxonomy_level_3'],
                        ':mapping_method' => $ex['mapping_method'], ':mapping_confidence' => $ex['mapping_confidence'],
                        ':mapping_reason' => 'fallback: no master_data_id coverage, existing fuzzy mapping preserved',
                        ':dominant_basis' => null, ':status' => $ex['status'],
                        ':mapped_at' => $now, ':updated_at' => $now,
                    ]);
                    $writes['written']++;
                    $writes['fallback_reupserted']++;
                }
                continue;
            }

            if ($r['status'] === 'needs_review' && !$opt_include_needs_review) {
                $writes['skipped_disagreement']++;
                continue;
            }

            $writes['attempted']++;
            $basis = in_array($r['basis'], $valid_basis, true) ? $r['basis'] : null;
            $stmt->execute([
                ':po_number' => $po, ':vendor_name' => $po_map[$po]['vendor_name'],
                ':requestor_names_json' => $existing[$po]['requestor_names_json'] ?? json_encode([]),
                ':item_descriptions_json' => $existing[$po]['item_descriptions_json'] ?? json_encode([]),
                ':line_item_count' => $r['line_item_count'],
                ':p_group' => $existing[$po]['p_group'] ?? null,
                ':ticket_type' => $existing[$po]['ticket_type'] ?? null,
                ':taxonomy_level_0' => $nn($r['level_0']), ':taxonomy_level_1' => $nn($r['level_1']),
                ':sub_category_code' => $nn($r['sub_category_code']),
                ':taxonomy_level_2' => $nn($r['level_2']), ':taxonomy_level_3' => $nn($r['level_3']),
                ':mapping_method' => 'manual', ':mapping_confidence' => max(0, min(255, (int)$r['confidence'])),
                ':mapping_reason' => $r['reason'],
                ':dominant_basis' => $basis, ':status' => $r['status'],
                ':mapped_at' => $now, ':updated_at' => $now,
            ]);
            $writes['written']++;
        }
        $pdo->commit();
        echo "DB WRITE committed.\n";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        fwrite(STDERR, "\nDB WRITE FAILED — transaction rolled back. No rows persisted.\n");
        fwrite(STDERR, "  Error: " . str_replace(["\r", "\n"], ' ', $e->getMessage()) . "\n\n");
        exit(1);
    }
}

echo "=======================================================================\n";
printf(" Output CSV:  %s\n", $csv_path);
printf(" Mode:        %s\n", $opt_apply ? 'APPLY (DB WRITE)' : 'PREVIEW (no writes)');
if ($opt_apply) {
    printf(" Rows written: %d (fallback-reupserted: %d)\n", $writes['written'], $writes['fallback_reupserted']);
    printf(" Skipped (manual resolved): %d\n", $writes['skipped_resolved']);
    printf(" Skipped (disagreement, needs --include-needs-review): %d\n", $writes['skipped_disagreement']);
}
echo " API/Gemini calls: NONE\n";
echo " WA/email:         NONE\n";
printf(" Elapsed: %.2f seconds\n", microtime(true) - $tool_start);
echo "=======================================================================\n\n";
