<?php
// workspace/deactivate_stale_skus.php
//
// One-off cleanup of the Master Item Catalogue (sku_dictionary), for the two
// classes of row that make the SKU Dictionary menu confusing to read:
//
//   RULE A -- DUPLICATE NAMES
//     The same item name registered under several SKU codes. Only one of them
//     should be selectable, and it is the SMALLEST SKU code in the group (the
//     original registration); every other row in the group is flagged inactive.
//
//   RULE B -- IDLE FAR-FUTURE VALIDITY
//     Rows whose Validity To is 2999 / 9999 / anything past 2027 -- i.e. rows
//     that can never expire on their own -- that saw no purchase at all in 2026.
//     Nothing has been bought against them and nothing ever will time them out,
//     so they sit in the catalogue forever. Flagged inactive.
//
// Both rules only ever set is_active from 'Yes' to 'No'. Nothing is reactivated
// and nothing is deleted: if the smallest-code row of a duplicate group is
// already inactive, that is left as a decision somebody made, not something this
// script second-guesses.
//
// NAME MATCHING (Rule A) is on the item name with its bookkeeping prefix
// removed: "[10037] AIR SUSPENSION F/10104469" and "AIR SUSPENSION F/10104469"
// are the same item under two codes, and the bracketed code is the SKU itself --
// leaving it in would make every row unique and the rule a no-op. Beyond that
// the comparison is exact (case- and whitespace-normalized only), so items that
// differ by a single attribute -- 750ML vs 550ML COCKTAIL SHAKER -- are never
// collapsed into each other.
//
// PURCHASE CHECK (Rule B) joins prs.sku to sku_dictionary.sku on PR date year
// 2026. A row with any 2026 PR line is in use and is kept, whatever its validity
// says.
//
// Every run writes a rollback script to db_backups/local_runtime_exports/
// before touching anything, so an --apply can always be undone.
//
// Usage (command line only -- see the guard below):
//   php deactivate_stale_skus.php               # dry run, prints what would change
//   php deactivate_stale_skus.php --apply       # writes the changes
//   php deactivate_stale_skus.php --rule=a      # one rule only (a | b | both)
//   php deactivate_stale_skus.php --list        # also print every affected SKU

// COMMAND LINE ONLY. This file lives under the web root, so without this guard
// any visitor -- signed in or not -- could deactivate a large slice of the
// catalogue just by requesting it. A web request gets a bare 404 and nothing
// else, not even the dry-run listing. Checked before the DB is touched.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config/database.php';

$argv_list = $argv ?? [];
$apply = in_array('--apply', $argv_list, true);
$list  = in_array('--list', $argv_list, true);
$rule  = 'both';
foreach ($argv_list as $arg) {
    if (strpos($arg, '--rule=') === 0) {
        $rule = strtolower(substr($arg, 7));
    }
}
if (!in_array($rule, ['a', 'b', 'both'], true)) {
    echo "Unknown --rule value '{$rule}'. Use a, b or both.\n";
    exit(1);
}
$nl = "\n";

// Validity To beyond this date counts as "never expires" for Rule B: the ticket
// asks for 2999 / 9999 "or later than 2027".
const FAR_FUTURE_AFTER = '2027-12-31';
// The year a purchase has to fall in for a far-future SKU to count as in use.
const PURCHASE_YEAR = 2026;

/**
 * The comparable item name: bookkeeping prefix stripped, collapsed whitespace,
 * uppercased. Two rows are duplicates when these are identical.
 */
function sku_compare_name(string $detail): string {
    $name = preg_replace('/^\s*\[[^\]]*\]\s*/u', '', $detail); // "[10037] ..."
    $name = preg_replace('/\s+/u', ' ', (string)$name);
    return mb_strtoupper(trim($name), 'UTF-8');
}

/**
 * Sort key for "smallest SKU id". Codes are numeric in this catalogue, so they
 * are compared as numbers -- string order would put '10037' before '1004'. A
 * non-numeric code falls back to a string compare, and the row id breaks ties so
 * the choice is stable between runs.
 */
function sku_sort_key(array $row): array {
    $sku = trim((string)$row['sku']);
    $is_numeric = ($sku !== '' && ctype_digit($sku));
    return [$is_numeric ? 0 : 1, $is_numeric ? (int)$sku : 0, $is_numeric ? '' : $sku, (int)$row['id']];
}

try {
    // Only rows still flagged active can change; everything else is already in
    // the state both rules aim for.
    $rows = $pdo->query("
        SELECT id, sku, detail, validity_to, is_active
        FROM sku_dictionary
        WHERE UPPER(IFNULL(is_active, '')) = 'YES'
    ")->fetchAll(PDO::FETCH_ASSOC);

    // sku => 1 for every SKU with at least one PR line dated in PURCHASE_YEAR.
    $purchased = [];
    $stmt = $pdo->prepare("
        SELECT DISTINCT TRIM(sku) AS sku
        FROM prs
        WHERE sku IS NOT NULL AND TRIM(sku) <> '' AND YEAR(pr_date) = ?
    ");
    $stmt->execute([PURCHASE_YEAR]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sku) {
        $purchased[strtoupper((string)$sku)] = 1;
    }

    // reason keyed by row id, so a row caught by both rules is only written once.
    $to_deactivate = [];
    $add = function (array $row, string $reason) use (&$to_deactivate) {
        if (!isset($to_deactivate[$row['id']])) {
            $to_deactivate[$row['id']] = [
                'id'     => (int)$row['id'],
                'sku'    => $row['sku'],
                'detail' => $row['detail'],
                'reason' => $reason,
            ];
        }
    };

    // --- RULE A: duplicate item names -------------------------------------
    $dup_groups = 0;
    if ($rule === 'a' || $rule === 'both') {
        $by_name = [];
        foreach ($rows as $row) {
            $key = sku_compare_name((string)$row['detail']);
            if ($key === '') { continue; }  // no name to compare on
            $by_name[$key][] = $row;
        }
        foreach ($by_name as $name => $group) {
            if (count($group) < 2) { continue; }
            $dup_groups++;
            usort($group, function ($x, $y) {
                return sku_sort_key($x) <=> sku_sort_key($y);
            });
            $keep = array_shift($group);   // smallest SKU code stays active
            foreach ($group as $row) {
                $add($row, 'duplicate of SKU ' . $keep['sku']);
            }
        }
    }

    // --- RULE B: far-future validity, no purchase in PURCHASE_YEAR ---------
    $far_future = 0;
    if ($rule === 'b' || $rule === 'both') {
        foreach ($rows as $row) {
            $validity_to = trim((string)($row['validity_to'] ?? ''));
            if ($validity_to === '' || $validity_to <= FAR_FUTURE_AFTER) { continue; }
            $far_future++;
            if (isset($purchased[strtoupper(trim((string)$row['sku']))])) { continue; }
            $add($row, 'validity to ' . $validity_to . ', no ' . PURCHASE_YEAR . ' purchase');
        }
    }

    $to_deactivate = array_values($to_deactivate);

    echo "Active SKUs scanned            : " . count($rows) . $nl;
    if ($rule === 'a' || $rule === 'both') {
        echo "  duplicate-name groups found  : " . $dup_groups . $nl;
    }
    if ($rule === 'b' || $rule === 'both') {
        echo "  validity to after " . FAR_FUTURE_AFTER . "   : " . $far_future . $nl;
        echo "  ...of those, bought in " . PURCHASE_YEAR . "  : " . ($far_future - count(array_filter(
            $to_deactivate,
            function ($r) { return strpos($r['reason'], 'validity to') === 0; }
        ))) . $nl;
    }
    echo "  TO DEACTIVATE                : " . count($to_deactivate) . $nl . $nl;

    if (empty($to_deactivate)) {
        echo "Nothing to do." . $nl;
        return;
    }

    if ($list) {
        foreach ($to_deactivate as $r) {
            echo sprintf("  %-10s %-60s (%s)%s", $r['sku'], mb_substr((string)$r['detail'], 0, 60), $r['reason'], $nl);
        }
        echo $nl;
    }

    // --- Rollback script (written on dry runs too, so it can be reviewed) ---
    $export_dir = __DIR__ . '/../db_backups/local_runtime_exports';
    if (!is_dir($export_dir)) { @mkdir($export_dir, 0777, true); }
    $rollback_path = $export_dir . '/deactivate_stale_skus_rollback_' . date('Ymd_His') . '.sql';
    $sql = "-- Rollback for deactivate_stale_skus.php, generated " . date('c') . "\n"
         . "-- Restores is_active = 'Yes' on the " . count($to_deactivate) . " row(s) it deactivates.\n"
         . "START TRANSACTION;\n";
    foreach ($to_deactivate as $r) {
        $sql .= sprintf("UPDATE sku_dictionary SET is_active = 'Yes' WHERE id = %d;\n", $r['id']);
    }
    $sql .= "COMMIT;\n";
    file_put_contents($rollback_path, $sql);
    echo "Rollback script written to: " . $rollback_path . $nl;

    if (!$apply) {
        echo $nl . "DRY RUN -- nothing was written. Re-run with --apply to commit." . $nl;
        return;
    }

    // modified_by carries the script name so the audit trail on the row says why
    // it changed, the same way a Category Manager's own toggle stamps their name.
    $upd = $pdo->prepare("UPDATE sku_dictionary
        SET is_active = 'No', modified_by = 'deactivate_stale_skus', modified_date = NOW()
        WHERE id = ?");
    $pdo->beginTransaction();
    try {
        $written = 0;
        foreach ($to_deactivate as $r) {
            $upd->execute([$r['id']]);
            $written += $upd->rowCount();
        }
        $pdo->commit();
        echo $nl . "Applied. {$written} SKU(s) flagged Is Active = No." . $nl;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
} catch (PDOException $e) {
    echo "Error deactivating stale SKUs: " . $e->getMessage() . $nl;
}
