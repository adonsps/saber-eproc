<?php
// workspace/normalize_master_data_l5.php
//
// Backfill: rewrite existing master_data.level_5 values into the house Scenario A
// SKU format -- the Standardized Core (= level_4) merged with its modifiers into
// one opening segment, then each critical technical attribute behind a semicolon.
// GOLDEN RULE 3, plus RULE 2 (no commas) and RULE III (the JASA service prefix).
//
// From now on every write path already enforces this: the review/edit/add forms
// in pages/master_data.php, the AI autofill (ajax_ai_autofill_taxonomy.php), the
// SKU auto-generator (ajax_auto_generate_sku.php) and Master Data enrichment all
// run their level_5 through md_sanitize_hybrid_name(). This script is only for
// the rows that were written before that, e.g.
//   level_4 "MINI PC"  level_5 "MINI PC - CORE I7 32GB RAM 1TB SSD"  (dash)
//   level_4 "Managed Print Service"  level_5 "JASA MANAGED PRINT SERVICE"
//
// SCOPE
//   * Only rows whose level_5 actually changes are touched.
//   * status = 'registered' is EXCLUDED by default: that SKU name is already
//     registered in K2-PSS, and renaming it here would desync the two systems.
//     Pass --include-registered to take them as well, deliberately.
//   * A rewrite that would collide with an existing (level_4, level_5) pair is
//     skipped and reported -- that unique key is what keeps the catalogue free
//     of duplicates, and resolving a collision is a merge decision, not a
//     rename.
//
// Every run writes a rollback script to db_backups/local_runtime_exports/
// before touching anything, so an --apply can always be undone.
//
// Usage (command line only -- see the guard below):
//   php normalize_master_data_l5.php                       # dry run, prints every rename
//   php normalize_master_data_l5.php --apply               # writes the changes
//   php normalize_master_data_l5.php --include-registered  # also rename ERP-registered SKUs

// COMMAND LINE ONLY. This file lives under the web root; without this guard any
// visitor could rewrite SKU names across Master Data just by requesting it.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/master_data_enrichment.php';
require_once __DIR__ . '/includes/master_data_audit.php';

$argv_list = $argv ?? [];
$apply    = in_array('--apply', $argv_list, true);
$with_reg = in_array('--include-registered', $argv_list, true);
$nl = "\n";

try {
    $rows = $pdo->query("
        SELECT id, status, level_4, level_5
        FROM master_data
        WHERE level_5 IS NOT NULL AND TRIM(level_5) <> ''
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Existing (level_4, level_5) pairs, so a rename that would collide with the
    // unique key is caught here rather than as a failed UPDATE mid-run.
    $taken = [];
    foreach ($rows as $r) {
        $taken[mb_strtolower(trim((string)$r['level_4']) . '|' . trim((string)$r['level_5']), 'UTF-8')] = (int)$r['id'];
    }

    $to_rename = [];
    $skipped_registered = 0;
    $skipped_collision  = 0;

    foreach ($rows as $r) {
        $l4 = trim((string)$r['level_4']);
        // Placeholder category on an unreviewed SKU request -- not a core.
        if (stripos($l4, 'pending_') === 0) { $l4 = ''; }

        $std = md_sanitize_hybrid_name($r['level_5'], $l4);
        if ($std === '' || $std === $r['level_5']) { continue; }

        if (($r['status'] ?? '') === 'registered' && !$with_reg) {
            $skipped_registered++;
            continue;
        }

        $key = mb_strtolower(trim((string)$r['level_4']) . '|' . $std, 'UTF-8');
        if (isset($taken[$key]) && $taken[$key] !== (int)$r['id']) {
            $skipped_collision++;
            echo "  COLLISION (skipped) id={$r['id']}: '{$r['level_5']}' -> '{$std}' already on id={$taken[$key]}" . $nl;
            continue;
        }
        $taken[$key] = (int)$r['id'];

        $to_rename[] = [
            'id'     => (int)$r['id'],
            'status' => $r['status'],
            'l4'     => $r['level_4'],
            'old'    => $r['level_5'],
            'new'    => $std,
        ];
    }

    echo "Rows with a level_5            : " . count($rows) . $nl;
    echo "  registered (kept as-is)      : " . $skipped_registered . $nl;
    echo "  unique-key collisions        : " . $skipped_collision . $nl;
    echo "  TO RENAME                    : " . count($to_rename) . $nl . $nl;

    foreach ($to_rename as $r) {
        echo "  [{$r['status']}] L4: {$r['l4']}" . $nl;
        echo "     from: {$r['old']}" . $nl;
        echo "     to  : {$r['new']}" . $nl;
    }
    echo $nl;

    if (empty($to_rename)) {
        echo "Nothing to do." . $nl;
        return;
    }

    // --- Rollback script (written on dry runs too, so it can be reviewed) ---
    $export_dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($export_dir)) { @mkdir($export_dir, 0777, true); }
    $rollback_path = $export_dir . '/normalize_master_data_l5_rollback_' . date('Ymd_His') . '.sql';
    $sql = "-- Rollback for normalize_master_data_l5.php, generated " . date('c') . "\n"
         . "-- Restores level_5 on the " . count($to_rename) . " row(s) it renames.\n"
         . "START TRANSACTION;\n";
    foreach ($to_rename as $r) {
        $sql .= sprintf("UPDATE master_data SET level_5 = %s WHERE id = %d;\n", $pdo->quote($r['old']), $r['id']);
    }
    $sql .= "COMMIT;\n";
    file_put_contents($rollback_path, $sql);
    echo "Rollback script written to: " . $rollback_path . $nl;

    if (!$apply) {
        echo $nl . "DRY RUN -- nothing was written. Re-run with --apply to commit." . $nl;
        return;
    }

    // Mechanical reformat run from the CLI: no logged-in user and no AI call,
    // so the audit columns say SYSTEM.
    $upd = $pdo->prepare("UPDATE master_data SET level_5 = ?, last_modified_by = ? WHERE id = ?");
    $pdo->beginTransaction();
    try {
        $written = 0;
        foreach ($to_rename as $r) {
            $before = md_audit_snapshot($pdo, [$r['id']]);
            $upd->execute([$r['new'], MD_AUDIT_ACTOR_SYSTEM, $r['id']]);
            $written += $upd->rowCount();
            md_audit_log_update($pdo, $before, MD_AUDIT_ACTOR_SYSTEM);
        }
        $pdo->commit();
        echo $nl . "Applied. {$written} level_5 value(s) rewritten to the Scenario A SKU format." . $nl;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
} catch (PDOException $e) {
    echo "Error normalizing level_5: " . $e->getMessage() . $nl;
}
