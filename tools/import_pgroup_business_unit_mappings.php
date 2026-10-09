<?php
// tools/import_pgroup_business_unit_mappings.php
//
// Local CLI utility to import / validate the PGroup -> Business Unit mapping
// CSV (sourced from an external BU/PGroup mapping workbook) and (only in
// apply mode) upsert it into `pgroup_business_unit_mappings`.
//
// SAFETY (hard rules baked into this script):
//   * CLI ONLY -- refuses to run from a web browser.
//   * DRY-RUN BY DEFAULT, and FULLY OFFLINE -- dry-run validates the CSV
//     without loading database.php or opening any connection, so it can make
//     no DB writes at all. A connection is opened ONLY in --apply mode, which
//     also requires a matching --confirm-db=DATABASE_NAME.
//   * APPLY FAILS CLOSED -- blocked if any row is missing p_group /
//     business_unit_code / business_unit_name, or if p_group is duplicated
//     within the CSV. Dry-run only warns; apply refuses on any mismatch.
//   * mapping_confidence and notes are preserved AS-IS from the source CSV
//     (categorical provenance labels, e.g. "family-based",
//     "inferred, not in source file", "user-provided" -- never collapsed,
//     summarized, or reduced to a single value).
//   * The source CSV also carries pgroup_description, pgroup_family, and
//     mapping_method columns that are NOT part of this table's schema.
//     These are read for validation/reporting only and are NOT written to
//     the DB -- flagged explicitly in the banner below so this is never a
//     silent drop.
//   * Touches ONLY `pgroup_business_unit_mappings` (upserted) -- plus its
//     own disposable staging table. Never touches any other table.
//   * Loads through a staging table first; only after staging validation
//     passes does it upsert (INSERT ... ON DUPLICATE KEY UPDATE) into the
//     live table inside one transaction. Existing rows not present in the
//     CSV are left untouched (this is an upsert, not a replace).
//   * Sends NO WhatsApp and NO email. Creates NO cron.
//   * Reuses workspace/config/database.php for the connection (no credentials
//     are duplicated or modified here). Never prints the DB password.
//
// USAGE:
//   Dry run (default, no writes):
//       php tools/import_pgroup_business_unit_mappings.php \
//           --file=db_backups/local_runtime_imports/pgroup_business_unit_mappings_seed.csv
//
//   Apply (upserts pgroup_business_unit_mappings) -- BOTH flags required:
//       php tools/import_pgroup_business_unit_mappings.php \
//           --file=db_backups/local_runtime_imports/pgroup_business_unit_mappings_seed.csv \
//           --apply --confirm-db=u894129685_workspace
//
//   Optional:
//       --by=NAME      Who ran the import (metadata/report only). Default: OS user.
//
// The --confirm-db value MUST exactly match the database the script actually
// connected to (SELECT DATABASE()). This guards against writing to the wrong
// database when CLI credential/host selection differs from the web app.
//
// If your local DB requires it (e.g. Docker), set DB_HOST before running, e.g.:
//       DB_HOST=db php tools/import_pgroup_business_unit_mappings.php --file=...

// ---------------------------------------------------------------------
// Refuse to run anywhere except the CLI.
// ---------------------------------------------------------------------
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

// ---------------------------------------------------------------------
// Expected shape of the source CSV. Order matters: header must match
// exactly, in this order.
// ---------------------------------------------------------------------
const EXPECTED_COLUMNS = [
    'p_group', 'business_unit_code', 'business_unit_name',
    'pgroup_description', 'pgroup_family', 'mapping_method',
    'mapping_confidence', 'notes',
];

// Columns that ARE imported into pgroup_business_unit_mappings.
const IMPORTED_COLUMNS = ['p_group', 'business_unit_code', 'business_unit_name', 'mapping_confidence', 'notes'];

// Columns present in the CSV but NOT written to the DB (read for
// reporting/validation only). Kept explicit so dropping them is never silent.
const UNIMPORTED_COLUMNS = ['pgroup_description', 'pgroup_family', 'mapping_method'];

// Required NON-BLANK columns -- the source data is expected to have a real
// business_unit_code/name on every row (no intentional-null rows in this
// version of the CSV); apply fails closed if any are blank.
const REQUIRED_NONBLANK_COLUMNS = ['p_group', 'business_unit_code', 'business_unit_name'];

const TARGET_TABLE  = 'pgroup_business_unit_mappings';
const STAGING_TABLE = 'pgroup_business_unit_mappings_staging';

// ---------------------------------------------------------------------
// Parse CLI arguments.
// ---------------------------------------------------------------------
$apply = in_array('--apply', $argv, true);

$csvPath    = null;
$confirmDb  = null;
$importedBy = '';
foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $csvPath = substr($arg, strlen('--file='));
    } elseif (strpos($arg, '--confirm-db=') === 0) {
        $confirmDb = substr($arg, strlen('--confirm-db='));
    } elseif (strpos($arg, '--by=') === 0) {
        $importedBy = substr($arg, strlen('--by='));
    }
}

if ($importedBy === '') {
    $importedBy = (string)(getenv('USERNAME') ?: getenv('USER') ?: get_current_user() ?: 'cli');
}

if ($csvPath === null || $csvPath === '') {
    fwrite(STDERR, "ERROR: --file=PATH_TO_CSV is required.\n");
    fwrite(STDERR, "Usage: php tools/import_pgroup_business_unit_mappings.php --file=PATH_TO_CSV [--apply --confirm-db=DB_NAME] [--by=NAME]\n");
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
$pdo         = null;
$currentDb   = null;    // null until connected (apply mode only)
$dbHost      = null;
$targetExists = null;   // null = not checked (offline dry-run)

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
// Read + parse CSV. Header must match EXPECTED_COLUMNS exactly (no rename).
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
    fwrite(STDERR, "ERROR: CSV header does not match the expected columns.\n");
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
$rows          = [];   // each: ['p_group'=>..., 'business_unit_code'=>..., ...]
$rowErrors     = [];   // human-readable validation errors
$lineNo        = 1;    // header was line 1
$totalRows     = 0;
$pGroupSeen    = [];   // for duplicate detection within the CSV
$duplicatePGroups = [];
$confidenceCounts = [];

while (($row = fgetcsv($fh)) !== false) {
    $lineNo++;

    // A completely empty trailing line -- ignore silently.
    if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
        continue;
    }

    $totalRows++;

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

    $pGroupKey = strtoupper($record['p_group']);
    if (isset($pGroupSeen[$pGroupKey])) {
        $duplicatePGroups[$pGroupKey] = true;
        $rowErrors[] = sprintf("line %d: duplicate p_group '%s' (first seen at line %d)", $lineNo, $record['p_group'], $pGroupSeen[$pGroupKey]);
        continue;
    }
    $pGroupSeen[$pGroupKey] = $lineNo;

    $rows[] = $record;
    $confKey = $record['mapping_confidence'] !== '' ? $record['mapping_confidence'] : '(blank)';
    $confidenceCounts[$confKey] = ($confidenceCounts[$confKey] ?? 0) + 1;
}
fclose($fh);

$validRows = count($rows);

// ---------------------------------------------------------------------
// APPLY mode connects HERE (before the banner) so the banner can report the
// live target and table existence. DRY-RUN stays fully offline.
// ---------------------------------------------------------------------
if ($apply) {
    [$pdo, $currentDb, $dbHost] = connect_db();
    $targetExists = table_exists($pdo, TARGET_TABLE);
}

// ---------------------------------------------------------------------
// Diagnostics banner -- printed on EVERY run.
// ---------------------------------------------------------------------
echo "============================================================\n";
echo " Import PGroup -> Business Unit Mappings  (" . ($apply ? "APPLY" : "DRY-RUN") . ")\n";
echo "============================================================\n";
if ($apply) {
    echo "Connected database : {$currentDb}\n";
    echo "DB host            : {$dbHost}\n";
} else {
    echo "Database           : (offline dry-run -- no connection, no writes)\n";
}
echo "CSV file           : {$csvPath}\n";
echo "Imported by        : {$importedBy}\n";
echo "------------------------------------------------------------\n";
echo "Header             : OK (" . implode(' | ', EXPECTED_COLUMNS) . ")\n";
echo "Imported columns   : " . implode(', ', IMPORTED_COLUMNS) . "\n";
echo "NOT imported (read for reporting only, not written to DB): " . implode(', ', UNIMPORTED_COLUMNS) . "\n";
echo "Data rows seen     : {$totalRows}\n";
echo "Valid rows         : {$validRows}\n";
echo "Validation errors  : " . count($rowErrors) . "\n";
echo "Duplicate p_group values: " . count($duplicatePGroups) . "\n";
echo "mapping_confidence breakdown (preserved as-is, not collapsed):\n";
foreach ($confidenceCounts as $label => $count) {
    echo "  - {$label}: {$count}\n";
}
if ($apply) {
    echo "Target table       : " . TARGET_TABLE . " (" . ($targetExists ? "exists" : "MISSING -- run db_backups/pgroup_business_unit_mappings_01_schema.sql") . ")\n";
}
echo "------------------------------------------------------------\n";

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
$reportPath = $reportDir . "/pgroup_business_unit_mappings_import_report_{$stamp}.csv";
$rfh        = fopen($reportPath, 'w');
if ($rfh !== false) {
    fputcsv($rfh, EXPECTED_COLUMNS);
    foreach ($rows as $r) {
        fputcsv($rfh, array_map(fn($c) => $r[$c], EXPECTED_COLUMNS));
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
    echo "    php tools/import_pgroup_business_unit_mappings.php --file={$csvPath} --apply --confirm-db=DATABASE_NAME\n";
    exit(0);
}

// ---------------------------------------------------------------------
// APPLY guard: require an explicit --confirm-db that matches the live DB.
// ---------------------------------------------------------------------
if ($confirmDb === null) {
    fwrite(STDERR, "ABORT: --apply requires --confirm-db=DATABASE_NAME.\n");
    fwrite(STDERR, "       Connected database is '{$currentDb}'. To proceed, run:\n");
    fwrite(STDERR, "       php tools/import_pgroup_business_unit_mappings.php --file={$csvPath} --apply --confirm-db={$currentDb}\n");
    exit(1);
}
if ($confirmDb !== $currentDb) {
    fwrite(STDERR, "ABORT: --confirm-db='{$confirmDb}' does not match connected database '{$currentDb}'.\n");
    fwrite(STDERR, "       No changes made.\n");
    exit(1);
}

// Hard validation gates for apply.
if ($rowErrors) {
    fwrite(STDERR, "ABORT: " . count($rowErrors) . " row(s) have validation errors (including any duplicate p_group). No changes made.\n");
    fwrite(STDERR, "       Fix the CSV and re-run.\n");
    exit(1);
}
if ($validRows === 0) {
    fwrite(STDERR, "ABORT: no valid data rows to import. No changes made.\n");
    exit(1);
}
if (!$targetExists) {
    fwrite(STDERR, "ABORT: target table missing. Run db_backups/pgroup_business_unit_mappings_01_schema.sql first.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// APPLY: load into staging, validate staging, then upsert into the live
// table inside one transaction. Touches ONLY the two tables named above.
// This is an UPSERT (INSERT ... ON DUPLICATE KEY UPDATE), not a replace --
// existing rows whose p_group is not in this CSV are left untouched.
// ---------------------------------------------------------------------
try {
    // (1) (Re)build a clean staging table that mirrors the target schema.
    //     CREATE/DROP are DDL (implicit commit), so we do them BEFORE the
    //     upsert transaction.
    $pdo->exec("DROP TABLE IF EXISTS `" . STAGING_TABLE . "`");
    $pdo->exec("CREATE TABLE `" . STAGING_TABLE . "` LIKE `" . TARGET_TABLE . "`");

    // (2) Load every validated row into staging (only the imported columns).
    $ins = $pdo->prepare(
        "INSERT INTO `" . STAGING_TABLE . "`
            (`p_group`, `business_unit_code`, `business_unit_name`, `mapping_confidence`, `notes`)
         VALUES (?, ?, ?, ?, ?)"
    );
    foreach ($rows as $r) {
        $ins->execute([
            $r['p_group'],
            $r['business_unit_code'],
            $r['business_unit_name'],
            $r['mapping_confidence'] !== '' ? $r['mapping_confidence'] : null,
            $r['notes'] !== '' ? $r['notes'] : null,
        ]);
    }

    // (3) Validate staging before we touch the live table.
    $stagedCount = (int)$pdo->query("SELECT COUNT(*) FROM `" . STAGING_TABLE . "`")->fetchColumn();
    if ($stagedCount !== $validRows) {
        throw new RuntimeException(
            "staging row count {$stagedCount} != expected {$validRows}; aborting before touching live table"
        );
    }
    $stagedBlanks = (int)$pdo->query(
        "SELECT COUNT(*) FROM `" . STAGING_TABLE . "`
         WHERE `p_group` IS NULL OR `p_group` = ''
            OR `business_unit_code` IS NULL OR `business_unit_code` = ''
            OR `business_unit_name` IS NULL OR `business_unit_name` = ''"
    )->fetchColumn();
    if ($stagedBlanks > 0) {
        throw new RuntimeException("staging contains {$stagedBlanks} row(s) with blank required values; aborting");
    }

    // (4) Upsert from staging into the live table.
    $pdo->beginTransaction();

    $beforeCount = (int)$pdo->query("SELECT COUNT(*) FROM `" . TARGET_TABLE . "`")->fetchColumn();

    $pdo->exec(
        "INSERT INTO `" . TARGET_TABLE . "`
            (`p_group`, `business_unit_code`, `business_unit_name`, `mapping_confidence`, `notes`)
         SELECT `p_group`, `business_unit_code`, `business_unit_name`, `mapping_confidence`, `notes`
         FROM `" . STAGING_TABLE . "`
         ON DUPLICATE KEY UPDATE
            business_unit_code = VALUES(business_unit_code),
            business_unit_name = VALUES(business_unit_name),
            mapping_confidence = VALUES(mapping_confidence),
            notes = VALUES(notes),
            updated_at = CURRENT_TIMESTAMP"
    );

    $afterCount = (int)$pdo->query("SELECT COUNT(*) FROM `" . TARGET_TABLE . "`")->fetchColumn();

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

$inserted = $afterCount - $beforeCount;
$updated  = $validRows - $inserted;
echo "\n[APPLY] Done. {$validRows} row(s) upserted into `" . TARGET_TABLE . "` ({$inserted} inserted, {$updated} updated). Rows for p_group values not present in this CSV were left untouched.\n";
exit(0);
