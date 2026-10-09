<?php
// tools/import_item_taxonomy.php
//
// Local CLI utility to import / validate the "Item Category - DB View"
// taxonomy CSV and (only in apply mode) replace the raw master table
// `item_taxonomy_db_view`.
//
// SAFETY (hard rules baked into this script):
//   * CLI ONLY -- refuses to run from a web browser.
//   * DRY-RUN BY DEFAULT, and FULLY OFFLINE -- dry-run validates the CSV
//     without loading database.php or opening any connection, so it can make
//     no DB writes at all. A connection is opened ONLY in --apply mode, which
//     also requires a matching --confirm-db=DATABASE_NAME.
//   * APPLY FAILS CLOSED -- in --apply mode the import is blocked unless the
//     parsed CSV is EXACTLY: 221 data rows, 5 distinct Towers, 27 distinct
//     NON-BLANK Sub Category Codes, every row exactly 5 columns, and Tower /
//     Category / Sub Category / Commodity non-blank. `Sub Category Code` MAY be
//     blank (real sheet has valid blank codes). Dry-run only warns; apply
//     refuses on any mismatch.
//   * Touches ONLY `item_taxonomy_db_view` (replaced) and
//     `item_taxonomy_import_meta` (one row appended) -- plus its own disposable
//     staging table. Never touches any other table.
//   * Loads through a staging table first; the live raw table is replaced
//     atomically (DELETE + INSERT...SELECT inside one transaction) ONLY after
//     staging validation passes.
//   * Sends NO WhatsApp and NO email. Creates NO cron.
//   * Reuses workspace/config/database.php for the connection (no credentials
//     are duplicated or modified here). Never prints the DB password.
//
// USAGE:
//   Dry run (default, no writes):
//       php tools/import_item_taxonomy.php --file=db_backups/local_runtime_imports/item_category_db_view.csv
//
//   Apply (replaces item_taxonomy_db_view) -- BOTH flags required:
//       php tools/import_item_taxonomy.php \
//           --file=db_backups/local_runtime_imports/item_category_db_view.csv \
//           --apply --confirm-db=u894129685_workspace
//
//   Optional:
//       --sheet=NAME   Source sheet name recorded in metadata.
//                      Default: "Item Category - DB View".
//       --by=NAME      Who ran the import (metadata). Default: OS user.
//       --notes=TEXT   Free-text note recorded in metadata.
//
// The --confirm-db value MUST exactly match the database the script actually
// connected to (SELECT DATABASE()). This guards against writing to the wrong
// database when CLI credential/host selection differs from the web app.
//
// If your local DB requires it (e.g. Docker), set DB_HOST before running, e.g.:
//       DB_HOST=db php tools/import_item_taxonomy.php --file=...

// ---------------------------------------------------------------------
// Refuse to run anywhere except the CLI.
// ---------------------------------------------------------------------
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

// ---------------------------------------------------------------------
// Expected schema of the source sheet. Order matters: each data row must
// have exactly this many columns, in this order.
// ---------------------------------------------------------------------
const EXPECTED_COLUMNS = ['Tower', 'Category', 'Sub Category Code', 'Sub Category', 'Commodity'];

// Required NON-BLANK taxonomy fields. `Sub Category Code` is intentionally
// NOT here: the real sheet has legitimately blank codes (notably Project /
// Construction and Maintenance & Facility Management rows), so blanks are
// allowed and the raw column is still preserved exactly.
const REQUIRED_NONBLANK_COLUMNS = ['Tower', 'Category', 'Sub Category', 'Commodity'];

// The source sheet carries a SECOND header row immediately under the real
// header -- a column-mapping/label row ("Level 0, Level 1, Sub Category Code,
// Level 2, Level 3"). It is NOT data and must be skipped. Matched
// case-insensitively; no real commodity row matches all five label cells.
const MAPPING_ROW = ['Level 0', 'Level 1', 'Sub Category Code', 'Level 2', 'Level 3'];
const EXPECTED_ROW_COUNT = 221;     // approximate -- a DRY-RUN sanity check, not a hard gate
const ROW_COUNT_TOLERANCE = 25;     // dry-run: warn (do not block) if |actual - expected| > this

// Apply-mode STRICT shape gates. Dry-run only warns; --apply fails CLOSED
// unless the parsed CSV matches every one of these exactly.
const APPLY_EXACT_ROW_COUNT          = 221;   // exact parsed data row count
const APPLY_EXACT_DISTINCT_TOWERS    = 5;     // exact distinct Tower count
const APPLY_EXACT_DISTINCT_SUB_CODES = 27;    // exact distinct NON-BLANK Sub Category Code count

const RAW_TABLE      = 'item_taxonomy_db_view';
const META_TABLE     = 'item_taxonomy_import_meta';
const STAGING_TABLE  = 'item_taxonomy_db_view_staging';

// ---------------------------------------------------------------------
// Parse CLI arguments.
// ---------------------------------------------------------------------
$apply = in_array('--apply', $argv, true);

$csvPath   = null;
$confirmDb = null;
$sheet     = 'Item Category - DB View';
$importedBy = '';
$notes     = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $csvPath = substr($arg, strlen('--file='));
    } elseif (strpos($arg, '--confirm-db=') === 0) {
        $confirmDb = substr($arg, strlen('--confirm-db='));
    } elseif (strpos($arg, '--sheet=') === 0) {
        $sheet = substr($arg, strlen('--sheet='));
    } elseif (strpos($arg, '--by=') === 0) {
        $importedBy = substr($arg, strlen('--by='));
    } elseif (strpos($arg, '--notes=') === 0) {
        $notes = substr($arg, strlen('--notes='));
    }
}

if ($importedBy === '') {
    $importedBy = (string)(getenv('USERNAME') ?: getenv('USER') ?: get_current_user() ?: 'cli');
}

if ($csvPath === null || $csvPath === '') {
    fwrite(STDERR, "ERROR: --file=PATH_TO_CSV is required.\n");
    fwrite(STDERR, "Usage: php tools/import_item_taxonomy.php --file=PATH_TO_CSV [--apply --confirm-db=DB_NAME] [--sheet=NAME] [--by=NAME] [--notes=TEXT]\n");
    exit(1);
}

if (!is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "ERROR: CSV file not found or not readable: {$csvPath}\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Connection is deferred. DRY-RUN IS FULLY OFFLINE: it never loads
// database.php, never opens a connection, and therefore can make no DB
// writes at all (not even the auto-migrations database.php runs on connect).
// The connection is established ONLY in --apply mode, just before the banner.
// ---------------------------------------------------------------------
$pdo        = null;
$currentDb  = null;     // null until connected (apply mode only)
$dbHost     = null;
$rawExists  = null;     // null = not checked (offline dry-run)
$metaExists = null;

// ---------------------------------------------------------------------
// Helpers.
// ---------------------------------------------------------------------

/** Strip a UTF-8 BOM from the start of a string if present. */
function strip_bom(string $s): string
{
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
        return substr($s, 3);
    }
    return $s;
}

/**
 * Establish the DB connection by reusing the app config (never duplicates or
 * prints credentials). Called ONLY in apply mode. database.php die()s on a
 * failed connection. Returns [PDO $pdo, string $currentDb, string $dbHost].
 */
function connect_db(): array
{
    require_once __DIR__ . '/../workspace/config/database.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        fwrite(STDERR, "ERROR: database connection (\$pdo) not available.\n");
        exit(1);
    }
    $currentDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    // $host comes from config/database.php scope; safe to show (no password).
    $dbHost = isset($host) && $host !== '' ? (string)$host : '(unknown)';
    return [$pdo, $currentDb, $dbHost];
}

/** Does a base table (not view) exist in the connected database? */
function table_exists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
    );
    $stmt->execute([$name]);
    return $stmt->fetchColumn() !== false;
}

// ---------------------------------------------------------------------
// Read + parse CSV. Unlike the requestor importer this sheet is positional:
// we assert the header matches EXPECTED_COLUMNS exactly (no rename) and that
// every data row carries exactly that many fields.
// ---------------------------------------------------------------------
$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "ERROR: could not open CSV: {$csvPath}\n");
    exit(1);
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "ERROR: CSV is empty: {$csvPath}\n");
    fclose($fh);
    exit(1);
}
$header[0] = strip_bom((string)$header[0]);
$header    = array_map(static fn($h) => trim((string)$h), $header);

// --- Validate the header matches the expected sheet columns exactly. ---
$headerErrors = [];
if (count($header) !== count(EXPECTED_COLUMNS)) {
    $headerErrors[] = sprintf(
        "header has %d column(s), expected %d",
        count($header),
        count(EXPECTED_COLUMNS)
    );
}
foreach (EXPECTED_COLUMNS as $i => $expected) {
    $got = $header[$i] ?? '(missing)';
    if ($got !== $expected) {
        $headerErrors[] = "column " . ($i + 1) . ": got '{$got}', expected '{$expected}'";
    }
}
if ($headerErrors) {
    fwrite(STDERR, "ERROR: CSV header does not match the expected sheet columns.\n");
    foreach ($headerErrors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    fwrite(STDERR, "  Expected (exact, in order): " . implode(' | ', EXPECTED_COLUMNS) . "\n");
    fclose($fh);
    exit(1);
}

// ---------------------------------------------------------------------
// Validate + collect data rows. NO writes here.
// ---------------------------------------------------------------------
$rows       = [];   // each: ['Tower'=>..., 'Category'=>..., ...]
$rowErrors  = [];   // human-readable validation errors
$lineNo     = 1;    // header was line 1
$totalRows  = 0;
$towers     = [];
$subCatCodes = [];  // distinct NON-BLANK Sub Category Code values only
$blankSubCodeRows = 0;   // data rows whose Sub Category Code is blank (allowed)
$mappingRowSkipped = false;

// Pre-computed lowercased mapping row for comparison.
$mappingLower = array_map(static fn($v) => mb_strtolower($v), MAPPING_ROW);

while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;

    // A completely empty trailing line -- ignore silently.
    if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
        continue;
    }

    // Skip the sheet's secondary mapping/label row (not data).
    if (count($row) === count(MAPPING_ROW)) {
        $rowLower = array_map(static fn($v) => mb_strtolower(trim((string)$v)), $row);
        if ($rowLower === $mappingLower) {
            $mappingRowSkipped = true;
            continue;
        }
    }

    $totalRows++;

    // Exactly 5 columns per data row.
    if (count($row) !== count(EXPECTED_COLUMNS)) {
        $rowErrors[] = sprintf(
            "line %d: has %d field(s), expected %d",
            $lineNo,
            count($row),
            count(EXPECTED_COLUMNS)
        );
        continue;
    }

    $record = [];
    foreach (EXPECTED_COLUMNS as $i => $col) {
        $record[$col] = trim((string)$row[$i]);
    }

    // No blank REQUIRED taxonomy values. `Sub Category Code` is allowed blank
    // and is deliberately excluded from this check.
    $blanks = [];
    foreach (REQUIRED_NONBLANK_COLUMNS as $col) {
        if ($record[$col] === '') {
            $blanks[] = $col;
        }
    }
    if ($blanks) {
        $rowErrors[] = sprintf("line %d: blank required value(s): %s", $lineNo, implode(', ', $blanks));
        continue;
    }

    $rows[] = $record;
    $towers[mb_strtolower($record['Tower'])] = $record['Tower'];

    // Sub Category Code may be blank; only count distinct NON-BLANK codes.
    if ($record['Sub Category Code'] === '') {
        $blankSubCodeRows++;
    } else {
        $subCatCodes[mb_strtolower($record['Sub Category Code'])] = $record['Sub Category Code'];
    }
}
fclose($fh);

$validRows       = count($rows);
$distinctTowers  = count($towers);
$distinctSubCodes = count($subCatCodes);   // distinct NON-BLANK codes

// Row-count sanity check (approximate -- a warning, never a hard gate).
$countWarning = null;
if (abs($totalRows - EXPECTED_ROW_COUNT) > ROW_COUNT_TOLERANCE) {
    $countWarning = sprintf(
        "data row count %d is far from the expected ~%d (tolerance +/-%d)",
        $totalRows,
        EXPECTED_ROW_COUNT,
        ROW_COUNT_TOLERANCE
    );
}

// ---------------------------------------------------------------------
// APPLY mode connects HERE (before the banner) so the banner can report the
// live target and table existence. DRY-RUN stays fully offline.
// ---------------------------------------------------------------------
if ($apply) {
    [$pdo, $currentDb, $dbHost] = connect_db();
    $rawExists  = table_exists($pdo, RAW_TABLE);
    $metaExists = table_exists($pdo, META_TABLE);
}

// ---------------------------------------------------------------------
// Diagnostics banner -- printed on EVERY run.
// ---------------------------------------------------------------------
echo "============================================================\n";
echo " Import Item Taxonomy (\"Item Category - DB View\")  (" . ($apply ? "APPLY" : "DRY-RUN") . ")\n";
echo "============================================================\n";
if ($apply) {
    echo "Connected database : {$currentDb}\n";
    echo "DB host            : {$dbHost}\n";
} else {
    echo "Database           : (offline dry-run -- no connection, no writes)\n";
}
echo "CSV file           : {$csvPath}\n";
echo "Source sheet       : {$sheet}\n";
echo "Imported by        : {$importedBy}\n";
echo "------------------------------------------------------------\n";
echo "Header             : OK (" . implode(' | ', EXPECTED_COLUMNS) . ")\n";
echo "Mapping row skipped: " . ($mappingRowSkipped ? "yes (secondary 'Level 0..' label row)" : "no") . "\n";
echo "Data rows seen     : {$totalRows} (expected ~" . EXPECTED_ROW_COUNT . ")\n";
echo "Valid rows         : {$validRows}\n";
echo "Validation errors  : " . count($rowErrors) . "\n";
echo "Distinct Tower      : {$distinctTowers}\n";
echo "Blank Sub Cat Code rows : {$blankSubCodeRows} (allowed)\n";
echo "Distinct nonblank Sub Cat Code: {$distinctSubCodes}\n";
if ($apply) {
    echo "Target raw table   : " . RAW_TABLE . " (" . ($rawExists ? "exists" : "MISSING -- run db_backups/item_taxonomy_db_view.sql") . ")\n";
    echo "Metadata table     : " . META_TABLE . " (" . ($metaExists ? "exists" : "MISSING -- run db_backups/item_taxonomy_db_view.sql") . ")\n";
}
echo "------------------------------------------------------------\n";

if ($countWarning !== null) {
    echo "WARNING: {$countWarning}\n";
}
if ($rowErrors) {
    echo "Row validation errors (first 50 shown):\n";
    foreach (array_slice($rowErrors, 0, 50) as $e) {
        echo "  ! {$e}\n";
    }
    if (count($rowErrors) > 50) {
        echo "  ... and " . (count($rowErrors) - 50) . " more.\n";
    }
    echo "------------------------------------------------------------\n";
}

// ---------------------------------------------------------------------
// Write a runtime report CSV (every run). git-ignored folder.
// ---------------------------------------------------------------------
$reportDir = __DIR__ . '/../db_backups/local_runtime_exports';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0777, true);
}
$stamp      = date('Ymd_His');
$reportPath = $reportDir . "/item_taxonomy_import_report_{$stamp}.csv";
$rfh        = fopen($reportPath, 'w');
if ($rfh !== false) {
    fputcsv($rfh, EXPECTED_COLUMNS);
    foreach ($rows as $r) {
        fputcsv($rfh, [
            $r['Tower'], $r['Category'], $r['Sub Category Code'], $r['Sub Category'], $r['Commodity'],
        ]);
    }
    fclose($rfh);
    echo "Report CSV written : {$reportPath}\n";
} else {
    fwrite(STDERR, "WARNING: could not write report CSV to {$reportPath}\n");
}

// ---------------------------------------------------------------------
// DRY-RUN: stop here. No DB writes.
// ---------------------------------------------------------------------
if (!$apply) {
    echo "\n[DRY-RUN] Offline -- no database connection was opened and no changes were made.\n";
    if ($rowErrors) {
        echo "Note: " . count($rowErrors) . " row(s) have validation errors. Fix them in the CSV before --apply.\n";
    }
    echo "To apply, re-run with BOTH flags (DATABASE_NAME = the DB you intend to write):\n";
    echo "    php tools/import_item_taxonomy.php --file={$csvPath} --apply --confirm-db=DATABASE_NAME\n";
    exit(0);
}

// ---------------------------------------------------------------------
// APPLY guard: require an explicit --confirm-db that matches the live DB.
// ---------------------------------------------------------------------
if ($confirmDb === null) {
    fwrite(STDERR, "ABORT: --apply requires --confirm-db=DATABASE_NAME.\n");
    fwrite(STDERR, "       Connected database is '{$currentDb}'. To proceed, run:\n");
    fwrite(STDERR, "       php tools/import_item_taxonomy.php --file={$csvPath} --apply --confirm-db={$currentDb}\n");
    exit(1);
}
if ($confirmDb !== $currentDb) {
    fwrite(STDERR, "ABORT: --confirm-db='{$confirmDb}' does not match connected database '{$currentDb}'.\n");
    fwrite(STDERR, "       No changes made.\n");
    exit(1);
}

// Hard validation gates for apply.
if ($rowErrors) {
    fwrite(STDERR, "ABORT: " . count($rowErrors) . " row(s) have validation errors. No changes made.\n");
    fwrite(STDERR, "       Fix the CSV and re-run.\n");
    exit(1);
}
if ($validRows === 0) {
    fwrite(STDERR, "ABORT: no valid data rows to import. No changes made.\n");
    exit(1);
}

// Strict apply-mode shape gates -- fail CLOSED on any mismatch. The "exactly
// 5 columns per row" and "no blank required fields" rules are already enforced
// by the $rowErrors abort above (those failures populate $rowErrors), so at
// this point $totalRows === $validRows. These gates add the exact cardinality
// requirements that dry-run only warns about.
$strictErrors = [];
if ($totalRows !== APPLY_EXACT_ROW_COUNT) {
    $strictErrors[] = "parsed data row count is {$totalRows}, must be exactly " . APPLY_EXACT_ROW_COUNT;
}
if ($distinctTowers !== APPLY_EXACT_DISTINCT_TOWERS) {
    $strictErrors[] = "distinct Tower count is {$distinctTowers}, must be exactly " . APPLY_EXACT_DISTINCT_TOWERS;
}
if ($distinctSubCodes !== APPLY_EXACT_DISTINCT_SUB_CODES) {
    $strictErrors[] = "distinct NON-BLANK Sub Category Code count is {$distinctSubCodes}, must be exactly " . APPLY_EXACT_DISTINCT_SUB_CODES;
}
if ($strictErrors) {
    fwrite(STDERR, "ABORT: --apply strict validation failed. No changes made.\n");
    foreach ($strictErrors as $e) {
        fwrite(STDERR, "  - {$e}\n");
    }
    fwrite(STDERR, "       (Dry-run accepts these as warnings; apply requires an exact match.)\n");
    exit(1);
}

if (!$rawExists || !$metaExists) {
    fwrite(STDERR, "ABORT: required table(s) missing. Run db_backups/item_taxonomy_db_view.sql first.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// APPLY: load into staging, validate staging, then atomically replace the
// raw table and append one metadata row. Touches ONLY the three tables
// named at the top of this script.
// ---------------------------------------------------------------------
try {
    // (1) (Re)build a clean staging table that mirrors the raw schema.
    //     CREATE/DROP are DDL (implicit commit), so we do them BEFORE the
    //     replace transaction.
    $pdo->exec("DROP TABLE IF EXISTS `" . STAGING_TABLE . "`");
    $pdo->exec("CREATE TABLE `" . STAGING_TABLE . "` LIKE `" . RAW_TABLE . "`");

    // (2) Load every validated row into staging.
    $ins = $pdo->prepare(
        "INSERT INTO `" . STAGING_TABLE . "`
            (`Tower`, `Category`, `Sub Category Code`, `Sub Category`, `Commodity`)
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($rows as $r) {
        $ins->execute([
            $r['Tower'], $r['Category'], $r['Sub Category Code'], $r['Sub Category'], $r['Commodity'],
        ]);
    }

    // (3) Validate staging before we touch the live table.
    $stagedCount = (int)$pdo->query("SELECT COUNT(*) FROM `" . STAGING_TABLE . "`")->fetchColumn();
    if ($stagedCount !== $validRows) {
        throw new RuntimeException(
            "staging row count {$stagedCount} != expected {$validRows}; aborting before touching live table"
        );
    }
    // `Sub Category Code` is intentionally NOT checked here -- it may be blank.
    $stagedBlanks = (int)$pdo->query(
        "SELECT COUNT(*) FROM `" . STAGING_TABLE . "`
         WHERE `Tower` IS NULL OR `Tower` = ''
            OR `Category` IS NULL OR `Category` = ''
            OR `Sub Category` IS NULL OR `Sub Category` = ''
            OR `Commodity` IS NULL OR `Commodity` = ''"
    )->fetchColumn();
    if ($stagedBlanks > 0) {
        throw new RuntimeException("staging contains {$stagedBlanks} row(s) with blank required values; aborting");
    }

    // (4) Atomic replace of the live raw table + metadata append.
    //     DELETE (not TRUNCATE) keeps this inside one InnoDB transaction.
    $pdo->beginTransaction();

    $pdo->exec("DELETE FROM `" . RAW_TABLE . "`");
    $pdo->exec(
        "INSERT INTO `" . RAW_TABLE . "`
            (`Tower`, `Category`, `Sub Category Code`, `Sub Category`, `Commodity`)
         SELECT `Tower`, `Category`, `Sub Category Code`, `Sub Category`, `Commodity`
         FROM `" . STAGING_TABLE . "`"
    );

    $metaNote = $notes !== ''
        ? $notes
        : sprintf("distinct_towers=%d; distinct_sub_category_codes=%d", $distinctTowers, $distinctSubCodes);
    $metaStmt = $pdo->prepare(
        "INSERT INTO `" . META_TABLE . "`
            (`source_file`, `source_sheet`, `row_count`, `imported_by`, `imported_at`, `notes`)
         VALUES (?, ?, ?, ?, NOW(), ?)"
    );
    $metaStmt->execute([basename($csvPath), $sheet, $validRows, $importedBy, $metaNote]);

    $pdo->commit();

    // (5) Clean up staging (DDL -- outside the committed transaction).
    $pdo->exec("DROP TABLE IF EXISTS `" . STAGING_TABLE . "`");
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Best-effort staging cleanup; ignore secondary errors.
    try { $pdo->exec("DROP TABLE IF EXISTS `" . STAGING_TABLE . "`"); } catch (Throwable $ignore) {}
    fwrite(STDERR, "\nERROR during apply -- transaction rolled back. No changes were committed.\n");
    fwrite(STDERR, "  " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n[APPLY] Done. Replaced `" . RAW_TABLE . "` with {$validRows} row(s). Metadata row appended to `" . META_TABLE . "`.\n";
exit(0);
