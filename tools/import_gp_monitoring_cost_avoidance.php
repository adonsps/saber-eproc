<?php
// tools/import_gp_monitoring_cost_avoidance.php
//
// Backfill the "Monitoring GP Project" workbook's 2026 line items into
// manual_cost_avoidance, for the three project teams:
//     Retail      -> PKR (Project Konstruksi Retail)
//     Maintenance -> MFM (Maintenance & Facility Management)
//     Property    -> PKP (Project Konstruksi Properti)
//
// Input is the normalized CSV produced by tools/ods_gp_monitoring_to_csv.py.
//
// NO-DOUBLE-ENTRY GUARANTEE: a CSV row is skipped ONLY when its PO or canvassing
// number matches a `prs` line that is ACTUALLY COUNTED in the Savings Report —
// i.e. a line passing ca_from_where()'s 'report' filter (carries a quotation
// baseline, non-AON, not intercompany, not in purchases_2025). Those already
// show on the page via the PO-based path, so importing them would double-count.
// A PO that merely EXISTS in `prs` but has no baseline is NOT counted on the
// report (it sits in the Input Baselines queue at Rp 0), so its sheet saving
// would otherwise appear nowhere — those rows ARE imported. Reusing the report's
// own filter keeps the dedup in lock-step with the page in every environment.
//
// IDEMPOTENT: re-running upserts on canvassing_no (existing row -> UPDATE, new
// -> INSERT), so a second run inserts nothing.
//
// SAFETY (hard rules):
//   * CLI ONLY -- refuses to run from a web browser.
//   * DRY-RUN BY DEFAULT. It connects read-only to compute the dedup preview but
//     performs NO writes unless BOTH --apply AND --confirm-db=DB_NAME are given
//     and --confirm-db matches SELECT DATABASE().
//   * IDENTITY GATE before any write: prints DATABASE()/USER()/VERSION() and
//     requires --confirm-db to match the connected database.
//   * BACKS UP manual_cost_avoidance to db_backups/local_runtime_backups/ before
//     the first write.
//   * Touches ONLY manual_cost_avoidance (INSERT/UPDATE). Reads prs (dedup).
//   * Sends NO WhatsApp / email. Creates NO cron. Never prints the DB password.
//   * Reuses workspace/config/database.php for the connection.
//
// USAGE:
//   Dry run (default, no writes):
//     DB_HOST=db php tools/import_gp_monitoring_cost_avoidance.php
//
//   Apply (BOTH flags required):
//     DB_HOST=db php tools/import_gp_monitoring_cost_avoidance.php \
//         --apply --confirm-db=u894129685_workspace
//
//   Optional:
//     --file=PATH   CSV path. Default:
//                   db_backups/local_runtime_imports/gp_monitoring_2026.csv
//     --by=NAME     Recorded in created_by/updated_by. Default: OS user.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

const TARGET_TABLE = 'manual_cost_avoidance';
// Identifies rows this tool created (the UI form sets none of these). Used to
// REPLACE the GP-import subset on --apply so re-runs stay idempotent.
const GP_SUBSET_WHERE = " WHERE (po_number IS NOT NULL OR canvassing_no IS NOT NULL OR remarks LIKE 'GP: BU=%')";
const CSV_COLS = [
    'sheet', 'team_full', 'canvassing_no', 'po_number', 'entry_date',
    'vendor_name', 'item_description', 'taxonomy_category',
    'unit_price_before', 'avoided_price', 'total_cost_avoidance',
    'year', 'month', 'remarks', 'bu', 'pic',
];

// ---------------------------------------------------------------------
// Args
// ---------------------------------------------------------------------
$csvPath   = __DIR__ . '/../db_backups/local_runtime_imports/gp_monitoring_2026.csv';
$apply     = false;
$confirmDb = null;
$runBy     = get_current_user() ?: 'cli';

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (strpos($arg, '--file=') === 0) {
        $csvPath = substr($arg, strlen('--file='));
    } elseif (strpos($arg, '--confirm-db=') === 0) {
        $confirmDb = substr($arg, strlen('--confirm-db='));
    } elseif (strpos($arg, '--by=') === 0) {
        $runBy = substr($arg, strlen('--by=')) ?: $runBy;
    } else {
        fwrite(STDERR, "Unknown argument: {$arg}\n");
        fwrite(STDERR, "Usage: php tools/import_gp_monitoring_cost_avoidance.php [--file=CSV] [--apply --confirm-db=DB] [--by=NAME]\n");
        exit(2);
    }
}

if (!is_file($csvPath)) {
    fwrite(STDERR, "ABORT: CSV not found: {$csvPath}\n");
    fwrite(STDERR, "       Generate it first:\n");
    fwrite(STDERR, "         python tools/ods_gp_monitoring_to_csv.py \"Monitoring GP Project.ods\" {$csvPath}\n");
    exit(1);
}

// ---------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------
function strip_bom(string $s): string {
    return substr($s, 0, 3) === "\xEF\xBB\xBF" ? substr($s, 3) : $s;
}

/** Parse a date cell to Y-m-d, else '' if not parseable. */
function to_ymd(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') return '';
    // Already ISO (from ODS date-value)
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) return $raw;
    $ts = strtotime($raw);              // handles "Jan-05-2026", "8 January 2026"
    return $ts !== false ? date('Y-m-d', $ts) : '';
}

/** Blank -> null; else numeric string as-is. */
function num_or_null(string $s) {
    $s = trim($s);
    return $s === '' ? null : $s;
}

// ---------------------------------------------------------------------
// Read CSV
// ---------------------------------------------------------------------
$fh = fopen($csvPath, 'r');
if (!$fh) { fwrite(STDERR, "ABORT: cannot open CSV.\n"); exit(1); }

$header = fgetcsv($fh);
if ($header === false) { fwrite(STDERR, "ABORT: empty CSV.\n"); exit(1); }
$header[0] = strip_bom((string)$header[0]);
$idx = array_flip($header);
foreach (CSV_COLS as $need) {
    if (!array_key_exists($need, $idx)) {
        fwrite(STDERR, "ABORT: CSV missing column '{$need}'.\n");
        exit(1);
    }
}

$rows = [];
$lineNo = 1;
while (($r = fgetcsv($fh)) !== false) {
    $lineNo++;
    if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) === 0) continue;
    $get = fn($k) => trim((string)($r[$idx[$k]] ?? ''));
    $rows[] = [
        'sheet'         => $get('sheet'),
        'team'          => $get('team_full'),
        'canvassing_no' => $get('canvassing_no'),
        'po_number'     => $get('po_number'),
        'entry_date'    => to_ymd($get('entry_date')),
        'entry_raw'     => $get('entry_date'),
        'vendor_name'   => $get('vendor_name'),
        'item_desc'     => $get('item_description'),
        'taxonomy'      => $get('taxonomy_category'),
        'before'        => num_or_null($get('unit_price_before')),
        'avoided'       => num_or_null($get('avoided_price')),
        'total'         => $get('total_cost_avoidance'),
        'month'         => $get('month'),
        'remarks'       => $get('remarks'),
        'bu'            => $get('bu'),
        'pic'           => $get('pic'),
        '_line'         => $lineNo,
    ];
}
fclose($fh);

echo "GP Monitoring -> Cost Avoidance backfill\n";
echo str_repeat('=', 60) . "\n";
echo "CSV:        {$csvPath}\n";
echo "CSV rows:   " . count($rows) . "\n";
echo "Mode:       " . ($apply ? "APPLY" : "DRY-RUN (no writes)") . "\n\n";

// ---------------------------------------------------------------------
// Connect (read-only queries first; writes gated behind --apply)
// ---------------------------------------------------------------------
require_once __DIR__ . '/../workspace/config/database.php';
if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "ERROR: database connection (\$pdo) not available.\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$currentDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
$currentUser = (string)$pdo->query("SELECT CURRENT_USER()")->fetchColumn();
$currentVer  = (string)$pdo->query("SELECT VERSION()")->fetchColumn();
echo "DB identity:\n";
echo "  DATABASE(): {$currentDb}\n";
echo "  USER():     {$currentUser}\n";
echo "  VERSION():  {$currentVer}\n\n";

// Verify target columns exist (migration must have run).
$haveCols = $pdo->query("SHOW COLUMNS FROM " . TARGET_TABLE)->fetchAll(PDO::FETCH_COLUMN);
foreach (['po_number', 'canvassing_no', 'taxonomy_category'] as $c) {
    if (!in_array($c, $haveCols, true)) {
        fwrite(STDERR, "ABORT: " . TARGET_TABLE . ".{$c} missing. Load the page once or run db_update.php so the guarded migration in database.php adds it.\n");
        exit(1);
    }
}

// ---------------------------------------------------------------------
// Load prs dedup sets + existing manual rows (idempotency)
//
// DEDUP = rows the Savings Report ACTUALLY COUNTS, not every row in prs. We
// reuse ca_from_where()'s 'report' scope so the dedup mirrors the page exactly:
// a PO/canvassing is a double-entry risk only if a report-counted prs line
// carries it. A PO that exists in prs without a baseline is not counted there,
// so it stays importable (its saving would otherwise show nowhere on the page).
// ---------------------------------------------------------------------
require_once __DIR__ . '/../workspace/includes/cost_avoidance_helper.php';
$caCtx = ca_context($pdo);
$reportFilters = ['team' => '', 'g_filter' => 'all', 'g_from' => '', 'g_to' => ''];
list($reportFw, $reportParams) = ca_from_where($caCtx, $reportFilters, 'report', null, null);

// po_number is guaranteed non-empty by the 'report' WHERE; canvassing_no is not,
// so it is filtered explicitly here.
$poStmt = $pdo->prepare("SELECT DISTINCT p.po_number {$reportFw}");
$poStmt->execute($reportParams);
$prsPo = array_flip($poStmt->fetchAll(PDO::FETCH_COLUMN));

$canvStmt = $pdo->prepare("SELECT DISTINCT p.canvassing_no {$reportFw} AND p.canvassing_no IS NOT NULL AND p.canvassing_no <> ''");
$canvStmt->execute($reportParams);
$prsCanv = array_flip($canvStmt->fetchAll(PDO::FETCH_COLUMN));

echo "Savings-Report-counted dedup sets: " . count($prsPo) . " PO, " . count($prsCanv) . " canvassing.\n";

// GP-import rows are identifiable exclusively by these columns/marker (the UI
// manual-entry form never sets po_number/canvassing_no and never writes this
// remarks prefix). Apply mode REPLACES this subset so re-runs are idempotent.
$gpExisting = (int)$pdo->query(
    "SELECT COUNT(*) FROM " . TARGET_TABLE . GP_SUBSET_WHERE
)->fetchColumn();
echo "Existing GP-import rows (will be replaced on apply): {$gpExisting}\n\n";

// ---------------------------------------------------------------------
// Classify every CSV row
// ---------------------------------------------------------------------
$toInsert = [];
$skipInPrs = 0;
$skipBadDate = 0;
$skipNoKey = 0;
$perTeamInsert = [];   // team => ['n'=>, 'ca'=>]
$sampleSkip = [];
$sampleInsert = [];

foreach ($rows as $row) {
    $canv = $row['canvassing_no'];
    $po   = $row['po_number'];

    // Skip untraceable rows (no canvassing AND no PO): they cannot be deduped
    // against prs and would not survive the idempotent replace cleanly.
    if ($canv === '' && $po === '') {
        $skipNoKey++;
        continue;
    }

    // Dedup against Savings-Report-counted prs lines only (no double entry).
    if (($canv !== '' && isset($prsCanv[$canv])) || ($po !== '' && isset($prsPo[$po]))) {
        $skipInPrs++;
        if (count($sampleSkip) < 5 && $po !== '') $sampleSkip[] = $po;
        continue;
    }

    // Resolve entry_date (NOT NULL column). Fall back to 2026-MM-01, else skip.
    $entry = $row['entry_date'];
    if ($entry === '') {
        $mo = (int)$row['month'];
        if ($mo >= 1 && $mo <= 12) {
            $entry = sprintf('2026-%02d-01', $mo);
        } else {
            $skipBadDate++;
            continue;
        }
    }

    $vendor = $row['vendor_name'] !== '' ? $row['vendor_name'] : 'N/A';
    $desc   = $row['item_desc']  !== '' ? $row['item_desc']  : '(no description)';
    $total  = $row['total'] !== '' ? $row['total'] : '0';
    $remarksParts = array_filter([
        $row['remarks'],
        ($row['bu'] !== '' || $row['pic'] !== '') ? "GP: BU={$row['bu']} PIC={$row['pic']}" : '',
    ]);
    $remarks = $remarksParts ? implode(' | ', $remarksParts) : null;

    $rec = [
        'entry_date'          => $entry,
        'vendor_name'         => mb_substr($vendor, 0, 255),
        'item_description'    => mb_substr($desc, 0, 500),
        'quantity'            => 1,
        'uom'                 => null,
        'unit_price_before'   => $row['before'],
        'avoided_price'       => $row['avoided'],
        'total_cost_avoidance'=> $total,
        'currency'            => 'IDR',
        'category'            => null,                       // FK to master_data.level_4 — left NULL
        'taxonomy_category'   => $row['taxonomy'] !== '' ? mb_substr($row['taxonomy'], 0, 255) : null,
        'team'                => $row['team'],
        'remarks'             => $remarks,
        'quotation_link'      => null,
        'po_number'           => $po !== '' ? $po : null,
        'canvassing_no'       => $canv !== '' ? $canv : null,
    ];

    $team = $row['team'];
    $perTeamInsert[$team] ??= ['n' => 0, 'ca' => 0.0];

    $toInsert[] = $rec;
    $perTeamInsert[$team]['n']++;
    $perTeamInsert[$team]['ca'] += (float)$total;
    if (count($sampleInsert) < 5 && $po !== '') $sampleInsert[] = $po;
}

// ---------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------
echo "Classification\n" . str_repeat('-', 60) . "\n";
echo "  Skipped (no canvassing & no PO, untraceable): {$skipNoKey}\n";
echo "  Skipped (counted in Savings Report, no dbl):  {$skipInPrs}\n";
echo "  Skipped (unparseable date, no month):         {$skipBadDate}\n";
echo "  To IMPORT (replace GP subset, then insert):   " . count($toInsert) . "\n\n";

echo "New rows by team\n" . str_repeat('-', 60) . "\n";
foreach ($perTeamInsert as $team => $agg) {
    printf("  %-38s %5d rows   CA=Rp %s\n", $team, $agg['n'], number_format($agg['ca'], 0));
}
echo "\n";
echo "Spot-check PO already-in-prs (skipped): " . implode(', ', $sampleSkip) . "\n";
echo "Spot-check PO new (insert):             " . implode(', ', $sampleInsert) . "\n\n";

if (!$apply) {
    echo "DRY-RUN complete. No rows written.\n";
    echo "To apply:\n";
    echo "  DB_HOST=" . (getenv('DB_HOST') ?: 'db') . " php tools/import_gp_monitoring_cost_avoidance.php --apply --confirm-db={$currentDb}\n";
    exit(0);
}

// ---------------------------------------------------------------------
// APPLY: identity gate
// ---------------------------------------------------------------------
if ($confirmDb === null) {
    fwrite(STDERR, "ABORT: --apply requires --confirm-db=DB_NAME (matching '{$currentDb}').\n");
    exit(1);
}
if ($confirmDb !== $currentDb) {
    fwrite(STDERR, "ABORT: --confirm-db='{$confirmDb}' != connected DATABASE() '{$currentDb}'.\n");
    exit(1);
}

// ---------------------------------------------------------------------
// APPLY: backup manual_cost_avoidance (self-contained PHP dump)
// ---------------------------------------------------------------------
$backupDir = __DIR__ . '/../db_backups/local_runtime_backups';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0775, true); }
$backupFile = $backupDir . '/manual_cost_avoidance_' . date('Ymd_His') . '.sql';
$bfh = fopen($backupFile, 'w');
if (!$bfh) { fwrite(STDERR, "ABORT: cannot open backup file {$backupFile}\n"); exit(1); }
fwrite($bfh, "-- Backup of " . TARGET_TABLE . " taken " . date('c') . " before GP monitoring backfill\n");
fwrite($bfh, "-- DB={$currentDb} USER={$currentUser}\n");
$allCols = $pdo->query("SHOW COLUMNS FROM " . TARGET_TABLE)->fetchAll(PDO::FETCH_COLUMN);
$dump = $pdo->query("SELECT * FROM " . TARGET_TABLE);
$backupRows = 0;
foreach ($dump as $dr) {
    $vals = array_map(function ($c) use ($dr, $pdo) {
        return $dr[$c] === null ? 'NULL' : $pdo->quote((string)$dr[$c]);
    }, $allCols);
    fwrite($bfh, "INSERT INTO `" . TARGET_TABLE . "` (`" . implode('`,`', $allCols) . "`) VALUES (" . implode(',', $vals) . ");\n");
    $backupRows++;
}
fclose($bfh);
echo "Backup written: {$backupFile} ({$backupRows} existing rows)\n\n";

// ---------------------------------------------------------------------
// APPLY: replace the GP-import subset (delete + insert) in one transaction.
// Every inserted row carries a po_number OR canvassing_no (untraceable rows are
// skipped above), so the DELETE below removes exactly the prior GP import and
// re-runs are idempotent.
// ---------------------------------------------------------------------
$insSql = "INSERT INTO " . TARGET_TABLE . "
    (entry_date, vendor_name, item_description, quantity, uom, unit_price_before,
     avoided_price, total_cost_avoidance, currency, category, taxonomy_category,
     team, remarks, quotation_link, po_number, canvassing_no, source_type,
     is_active, created_by, updated_by)
    VALUES
    (:entry_date, :vendor_name, :item_description, :quantity, :uom, :unit_price_before,
     :avoided_price, :total_cost_avoidance, :currency, :category, :taxonomy_category,
     :team, :remarks, :quotation_link, :po_number, :canvassing_no, 'MANUAL',
     1, :created_by, :updated_by)";

$insStmt = $pdo->prepare($insSql);

$pdo->beginTransaction();
try {
    $nDel = $pdo->exec("DELETE FROM " . TARGET_TABLE . GP_SUBSET_WHERE);
    $nIns = 0;
    foreach ($toInsert as $rec) {
        $insStmt->execute([
            ':entry_date' => $rec['entry_date'], ':vendor_name' => $rec['vendor_name'],
            ':item_description' => $rec['item_description'], ':quantity' => $rec['quantity'],
            ':uom' => $rec['uom'], ':unit_price_before' => $rec['unit_price_before'],
            ':avoided_price' => $rec['avoided_price'], ':total_cost_avoidance' => $rec['total_cost_avoidance'],
            ':currency' => $rec['currency'], ':category' => $rec['category'],
            ':taxonomy_category' => $rec['taxonomy_category'], ':team' => $rec['team'],
            ':remarks' => $rec['remarks'], ':quotation_link' => $rec['quotation_link'],
            ':po_number' => $rec['po_number'], ':canvassing_no' => $rec['canvassing_no'],
            ':created_by' => $runBy, ':updated_by' => $runBy,
        ]);
        $nIns++;
    }
    $pdo->commit();
    echo "APPLIED: deleted {$nDel} prior GP rows, inserted {$nIns} into " . TARGET_TABLE . ".\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ABORT (rolled back): " . $e->getMessage() . "\n");
    exit(1);
}
