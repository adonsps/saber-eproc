<?php
// tools/import_contract_maintenance_from_xlsx.php
//
// Bulk-load the Facility/Property team's "List Contract Maintenance.xlsx"
// (sheet "Rekap") into contract_maintenance. This is the spreadsheet the
// feature replaced -- see includes/contract_maintenance_helper.php.
//
// COLUMN MAPPING (by header name, sheet "Rekap", header row auto-detected):
//   ITEM/SERVICE -> item_category   SUPPLIER -> supplier   DOCS -> docs_reference
//   DESCRIPTION  -> description     TYPE -> contract_type  REMARKS -> remarks
//   BU -> business_unit (see BU_MAP below)   SITE / AREA -> site_area
//   CONTRACT PERIOD -> contract_end_date     OUTPUT -> output_type
//   END PERIOD -> output_end_date
// The sheet's extra "AREA" column is unused (empty in every known export).
//
// BU_MAP: the sheet's BU column mixes plain divisions (Property/F&B/KLG) with
// short brand/legal-entity codes (AZKO, KLO, PET, TGI, HCI, and "F&B" itself
// read as a code here). Confirmed with the business owner 2026-09-09 --
// see BU_MAP below. Anything not in the map is written through unchanged
// (covers Property/KLG, and any already-correct value).
//
// DATES: CONTRACT PERIOD / END PERIOD are Excel date serials (days since
// 1899-12-30) in every row except one known text date ("29-Feb-2027", an
// impossible calendar date -- 2027 isn't a leap year). Per the business
// owner 2026-09-09: leave it as-is, let normal calendar overflow resolve it
// (day 29 of Feb rolls into March) rather than hand-correct it -- so text
// dates go through PHP's DateTime the same as any other date string, no
// special-casing.
//
// ROW FILTER: NONE beyond "entirely blank row" (every cell empty). Per the
// business owner 2026-09-09, rows with a bad TYPE ('-' instead of PKS/KHS)
// or missing fields are imported exactly as they appear in the sheet, not
// skipped -- contract_maintenance's NOT NULL columns are plain VARCHAR, so
// an empty string satisfies them.
//
// IDEMPOTENT: all rows this tool writes get created_by/updated_by = --by
// (default 'xlsx_import'). --apply REPLACES only that subset (DELETE WHERE
// created_by = '<--by>' THEN re-insert in the same transaction) -- rows
// created any other way (real app usage) are never touched. Re-running
// with the same --file and --by is safe.
//
// SAFETY (hard rules, same as tools/import_gp_monitoring_cost_avoidance.php):
//   * CLI ONLY.
//   * DRY-RUN BY DEFAULT. No writes unless BOTH --apply AND --confirm-db=DB_NAME,
//     and --confirm-db must match the connected SELECT DATABASE().
//   * IDENTITY GATE before any write: prints DATABASE()/USER()/VERSION().
//   * BACKS UP contract_maintenance to db_backups/local_runtime_backups/ before
//     the first write.
//   * Touches ONLY contract_maintenance. Sends NO WhatsApp / email. Never
//     prints the DB password.
//
// USAGE:
//   Dry run (default, no writes):
//     docker-compose exec app php tools/import_contract_maintenance_from_xlsx.php
//
//   Apply (BOTH flags required):
//     docker-compose exec app php tools/import_contract_maintenance_from_xlsx.php \
//         --apply --confirm-db=u894129685_workspace
//
//   Optional:
//     --file=PATH   .xlsx path (relative to repo root, or absolute). Default:
//                   db_backups/local_runtime_imports/List Contract Maintenance (2).xlsx
//     --sheet=NAME  Sheet name. Default: Rekap
//     --by=NAME     Recorded in created_by/updated_by, and the --apply replace
//                   key. Default: xlsx_import
//     --limit=N     Only process the first N data rows (smoke testing).

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

const EXPECTED_DB = 'u894129685_workspace';

// Confirmed with the business owner 2026-09-09 -- see header comment.
const BU_MAP = [
    'AZKO' => 'PT Aspirasi Hidup Indones',
    'KLO'  => 'PT Kawan Lama Solusi',
    'PET'  => 'PT Graha Satwa Paramita',
    'TGI'  => 'PT Toys Games Indonesia',
    'HCI'  => 'PT Home Center Indonesia',
    'F&B'  => 'PT Foods Beverages Indone',
];

const H_ITEM = 'item service';
const H_DESC = 'description';
const H_SUP  = 'supplier';
const H_TYPE = 'type';
const H_BU   = 'bu';
const H_SITE = 'site area';
const H_CP   = 'contract period';
const H_OUT  = 'output';
const H_EP   = 'end period';
const H_DOCS = 'docs';
const H_REM  = 'remarks';

$ROOT = dirname(__DIR__);

// Sengaja TIDAK me-require config/database.php di sini: file itu menjalankan
// blok auto-migrate (DDL) saat di-include, dan sebuah dry-run tidak boleh
// punya efek samping apa pun terhadap skema. PDO dibuat manual di bawah.

$argvFlags = array_slice($argv, 1);
$apply     = in_array('--apply', $argvFlags, true);
$confirmDb = null;
$SRC       = $ROOT . '/db_backups/local_runtime_imports/List Contract Maintenance (2).xlsx';
$sheetName = 'Rekap';
$by        = 'xlsx_import';
$limit     = 0;
foreach ($argvFlags as $f) {
    if (strpos($f, '--confirm-db=') === 0) $confirmDb = substr($f, 13);
    if (strpos($f, '--file=')       === 0) $SRC       = substr($f, 7);
    if (strpos($f, '--sheet=')      === 0) $sheetName = substr($f, 8);
    if (strpos($f, '--by=')         === 0) $by        = substr($f, 5);
    if (strpos($f, '--limit=')      === 0) $limit     = max(0, (int)substr($f, 8));
}
if ($SRC[0] !== '/' && !preg_match('/^[A-Za-z]:/', $SRC)) $SRC = $ROOT . '/' . $SRC;
if ($apply && $confirmDb === null) {
    exit("--apply requires --confirm-db=<dbname> too. Stopping without doing anything.\n");
}
if ($by === '') {
    exit("--by cannot be empty (it's the --apply replace key). Stopping.\n");
}


// ============================================================ XLSX READER ==
// Same minimal reader as tools/import_prs_master_data_id_from_xlsx.php:
// shared strings, inline strings, numbers. Good enough for plain report
// spreadsheets; formulas are read from their cached value if present.

function xlsx_col_index($ref) {
    $n = 0;
    for ($i = 0; $i < strlen($ref); $i++) {
        $ch = $ref[$i];
        if ($ch < 'A' || $ch > 'Z') break;
        $n = $n * 26 + (ord($ch) - 64);
    }
    return $n - 1;
}

function xlsx_read($path, $wantSheet = null) {
    if (!is_file($path)) throw new RuntimeException("File not found: $path");
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException("Could not open: $path");

    $shared = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sst = simplexml_load_string($xml);
        foreach ($sst->si as $si) {
            $shared[] = (isset($si->t) && count($si->r) === 0)
                ? (string)$si->t
                : implode('', array_map(function ($r) { return (string)$r->t; }, iterator_to_array($si->r)));
        }
    }

    $wb   = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
    $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
    $relMap = [];
    foreach ($rels->Relationship as $rel) $relMap[(string)$rel['Id']] = ltrim((string)$rel['Target'], '/');

    $target = null; $picked = null; $names = [];
    foreach ($wb->sheets->sheet as $sh) {
        $nm = (string)$sh['name'];
        $names[] = $nm;
        $isWanted = $wantSheet === null ? ($target === null) : (strcasecmp($nm, $wantSheet) === 0);
        if ($isWanted && $target === null) {
            $t = $relMap[(string)$sh->attributes('r', true)->id] ?? 'worksheets/sheet1.xml';
            $target = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
            $picked = $nm;
        }
    }
    if ($target === null) {
        $zip->close();
        throw new RuntimeException("Sheet '$wantSheet' not found. Available: " . implode(', ', $names));
    }

    $sheet = simplexml_load_string($zip->getFromName($target));
    $zip->close();
    if (!$sheet) throw new RuntimeException("Could not read sheet in: $path");

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $idx  = xlsx_col_index((string)$c['r']);
            $type = (string)$c['t'];
            if ($type === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = isset($c->v) ? (string)$c->v : '';
            }
            $cells[$idx] = trim($v);
        }
        $rows[(int)$row['r']] = $cells;
    }
    return ['sheet' => $picked, 'rows' => $rows];
}

function norm_header($s) {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', strtolower((string)$s))));
}

/** Excel serial date (days since 1899-12-30) OR a "D-Mon-Y"-ish text date -> 'Y-m-d', or null. */
function parse_xlsx_date($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (preg_match('/^\d+(\.\d+)?$/', $raw)) {
        $days = (int)$raw;
        $d = new DateTime('1899-12-30');
        $d->modify("+{$days} days");
        return $d->format('Y-m-d');
    }
    // Text date (e.g. "29-Feb-2027"). Let PHP's calendar-overflow behavior
    // resolve an impossible day rather than hand-correcting it -- see header
    // comment. DateTime silently overflows (Feb 29 in a non-leap year rolls
    // into March), matching the "leave as-is" decision.
    $ts = strtotime($raw);
    return $ts !== false ? date('Y-m-d', $ts) : null;
}

function csv_write($path, array $head, array $rows) {
    $dir = dirname($path);
    @mkdir($dir, 0777, true);
    $fh = @fopen($path, 'w');
    if (!$fh) {
        $who = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : '?';
        throw new RuntimeException("Could not write $path (user: $who).");
    }
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $head);
    foreach ($rows as $r) fputcsv($fh, $r);
    fclose($fh);
}


// ==================================================== 1. READ & MAP EXCEL ==
echo "== 1. Read Excel ==\n";
echo "Source : $SRC\n";
$read = xlsx_read($SRC, $sheetName);
$rows = $read['rows'];
echo "Sheet  : {$read['sheet']}\n";

$headerRow = null;
foreach ($rows as $rn => $cells) {
    $norm = array_map('norm_header', $cells);
    if (in_array(H_ITEM, $norm, true) && in_array(H_BU, $norm, true)) { $headerRow = $rn; break; }
}
if ($headerRow === null) exit("Could not find a header row containing '" . H_ITEM . "' and '" . H_BU . "'. Stopping.\n");

$colOf = [];
foreach ($rows[$headerRow] as $i => $v) {
    $h = norm_header($v);
    if ($h !== '' && !isset($colOf[$h])) $colOf[$h] = $i;
}
foreach ([H_ITEM, H_DESC, H_SUP, H_TYPE, H_BU, H_SITE, H_CP, H_OUT, H_EP, H_DOCS, H_REM] as $need) {
    if (!isset($colOf[$need])) {
        exit("Column '$need' not found in header row $headerRow. Headers read: "
            . implode(' | ', $rows[$headerRow]) . "\n");
    }
}
printf("Header : row %d\n", $headerRow);

$plan = [];       // rows that will be written
$buSubst = [];     // count of BU substitutions applied
$dateIssues = [];  // rows whose date didn't parse cleanly, for the review report
$n = 0;

foreach ($rows as $rn => $cells) {
    if ($rn <= $headerRow) continue;
    $g = function ($key) use ($cells, $colOf) { return trim((string)($cells[$colOf[$key]] ?? '')); };
    if (implode('', $cells) === '') continue; // entirely blank row
    $n++;
    if ($limit > 0 && $n > $limit) break;

    $buRaw = $g(H_BU);
    $bu = $buRaw;
    if (isset(BU_MAP[$buRaw])) {
        $bu = BU_MAP[$buRaw];
        $buSubst[$buRaw] = ($buSubst[$buRaw] ?? 0) + 1;
    }

    $cpRaw = $g(H_CP);
    $epRaw = $g(H_EP);
    $contractEndDate = parse_xlsx_date($cpRaw);
    $outputEndDate   = parse_xlsx_date($epRaw);
    if (($cpRaw !== '' && $contractEndDate === null) || ($epRaw !== '' && $outputEndDate === null)) {
        $dateIssues[] = [$rn, $cpRaw, $contractEndDate ?? '(unparsed)', $epRaw, $outputEndDate ?? '(unparsed)'];
    }

    $plan[] = [
        'excel_row'         => $rn,
        'item_category'     => $g(H_ITEM),
        'description'       => $g(H_DESC) ?: null,
        'supplier'          => $g(H_SUP) ?: null,
        'contract_type'     => $g(H_TYPE),
        'business_unit'     => $bu,
        'business_unit_raw' => $buRaw,
        'site_area'         => $g(H_SITE) ?: null,
        'contract_end_date' => $contractEndDate,
        'output_type'       => $g(H_OUT) ?: null,
        'output_end_date'   => $outputEndDate,
        'docs_reference'    => $g(H_DOCS) ?: null,
        'remarks'           => $g(H_REM) ?: null,
    ];
}

printf("Data rows read   : %d\n", $n);
printf("Rows to write    : %d\n", count($plan));
if ($buSubst) {
    echo "BU substitutions applied:\n";
    foreach ($buSubst as $raw => $cnt) printf("    %-6s -> %-30s (%d rows)\n", $raw, BU_MAP[$raw], $cnt);
}
if ($dateIssues) {
    printf("Rows with an unparsed date (kept, written as NULL): %d\n", count($dateIssues));
    foreach ($dateIssues as $d) printf("    excel row %d: CP='%s'->%s  EP='%s'->%s\n", ...$d);
}


// ============================================================ 2. DATABASE ==
echo "\n== 2. Connect ==\n";
$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: 'db') . ';dbname=' . EXPECTED_DB . ';charset=utf8mb4',
    'u894129685_workspace007',
    getenv('WORKSPACE_DB_PASSWORD') ?: 'Xcel007!',
    [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]
);

$ident = $pdo->query('SELECT DATABASE() db, USER() u, VERSION() v')->fetch();
printf("DATABASE() : %s\nUSER()     : %s\nVERSION()  : %s\nMode       : %s\n",
    $ident['db'], $ident['u'], $ident['v'], $apply ? 'APPLY (will write)' : 'DRY RUN (no writes)');

if ($apply) {
    if ($ident['db'] !== $confirmDb || $ident['db'] !== EXPECTED_DB) {
        exit("Identity gate FAILED: DATABASE() ({$ident['db']}) does not match --confirm-db ($confirmDb) "
           . "and/or the expected DB (" . EXPECTED_DB . "). Stopping without writing anything.\n");
    }
}

$existing = (int)$pdo->query("SELECT COUNT(*) FROM contract_maintenance WHERE created_by = "
    . $pdo->quote($by))->fetchColumn();
printf("Existing rows tagged created_by='%s' (will be replaced on --apply): %d\n", $by, $existing);
$otherRows = (int)$pdo->query("SELECT COUNT(*) FROM contract_maintenance WHERE created_by IS NULL OR created_by <> "
    . $pdo->quote($by))->fetchColumn();
printf("Other rows in the table (never touched by this tool): %d\n", $otherRows);


// ========================================================= 3. REVIEW REPORT ==
$stamp = date('Ymd_His');
$outDir = $ROOT . '/db_backups/local_runtime_exports';
$fPlan = "$outDir/contract_maintenance_import_PLAN_$stamp.csv";
try {
    csv_write($fPlan,
        ['excel_row', 'item_category', 'description', 'supplier', 'contract_type',
         'business_unit', 'business_unit_raw', 'site_area', 'contract_end_date',
         'output_type', 'output_end_date', 'docs_reference', 'remarks'],
        array_map(function ($p) {
            return [$p['excel_row'], $p['item_category'], $p['description'], $p['supplier'],
                    $p['contract_type'], $p['business_unit'], $p['business_unit_raw'], $p['site_area'],
                    $p['contract_end_date'], $p['output_type'], $p['output_end_date'],
                    $p['docs_reference'], $p['remarks']];
        }, $plan)
    );
    echo "\nReview plan written: $fPlan\n";
} catch (RuntimeException $e) {
    echo "\nCould not write review plan: " . $e->getMessage() . " (not fatal, counts above are still valid)\n";
}

if (!$apply) {
    echo "\nDRY RUN done. No database changes made.\n";
    echo "To apply: add --apply --confirm-db=" . EXPECTED_DB . " (will back up first).\n";
    exit(0);
}


// ================================================== 4. APPLY (needs both flags) ==
echo "\n== 4. Apply ==\n";
if (!$plan) {
    echo "Nothing to write. Done.\n";
    exit(0);
}

// Self-contained PHP dump (not a shelled-out mysqldump -- the app container
// has no mysql client, same reasoning as tools/import_gp_monitoring_cost_avoidance.php).
$backupDir = $ROOT . '/db_backups/local_runtime_backups';
@mkdir($backupDir, 0777, true);
$fBak = "$backupDir/contract_maintenance_before_xlsx_import_$stamp.sql";
$bfh = @fopen($fBak, 'w');
if (!$bfh) exit("Backup FAILED (could not open $fBak) -- stopping without writing anything.\n");
fwrite($bfh, "-- Backup of contract_maintenance taken " . date('c') . " before xlsx import (--by=$by)\n");
fwrite($bfh, "-- DB={$ident['db']} USER={$ident['u']}\n");
$allCols = $pdo->query("SHOW COLUMNS FROM contract_maintenance")->fetchAll(PDO::FETCH_COLUMN);
$backupRows = 0;
foreach ($pdo->query("SELECT * FROM contract_maintenance") as $dr) {
    $vals = array_map(function ($c) use ($dr, $pdo) {
        return $dr[$c] === null ? 'NULL' : $pdo->quote((string)$dr[$c]);
    }, $allCols);
    fwrite($bfh, "INSERT INTO `contract_maintenance` (`" . implode('`,`', $allCols) . "`) VALUES (" . implode(',', $vals) . ");\n");
    $backupRows++;
}
fclose($bfh);
if (!is_file($fBak) || filesize($fBak) === 0) {
    exit("Backup FAILED (empty file) -- stopping without writing anything. See: $fBak\n");
}
echo "Backup: $fBak ($backupRows existing rows)\n";

$pdo->beginTransaction();
try {
    $del = $pdo->prepare("DELETE FROM contract_maintenance WHERE created_by = ?");
    $del->execute([$by]);
    $deleted = $del->rowCount();

    $ins = $pdo->prepare("
        INSERT INTO contract_maintenance
            (item_category, description, supplier, contract_type, business_unit,
             site_area, contract_end_date, output_type, output_end_date,
             docs_reference, remarks, created_by, updated_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $inserted = 0;
    foreach ($plan as $p) {
        $ins->execute([
            $p['item_category'], $p['description'], $p['supplier'], $p['contract_type'],
            $p['business_unit'], $p['site_area'], $p['contract_end_date'], $p['output_type'],
            $p['output_end_date'], $p['docs_reference'], $p['remarks'], $by, $by,
        ]);
        $inserted++;
    }

    $pdo->commit();
    printf("\nAPPLY done: deleted %d old '%s' rows, inserted %d new rows.\n", $deleted, $by, $inserted);
} catch (Throwable $e) {
    $pdo->rollBack();
    echo "\nFAILED, transaction rolled back: " . $e->getMessage() . "\n";
    exit(1);
}
