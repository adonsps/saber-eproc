<?php
// tools/import_vendor_eval_requestors.php
//
// Vendor Evaluation v2 — CLI utility to import / validate the filled requestor
// contact CSV and (only in apply mode) update the `requestors` master table.
//
// v2 CHANGE: manager_email replaces manager_username.
//   - Requestor managers are NOT Workspace users and will NOT have accounts.
//   - Managers receive email CC only — no WA, no login required.
//   - manager_email is validated as an email address only.
//   - Do NOT validate manager against users.username — users table not queried.
//   - --apply requires manager_email column to exist (run migration SQL first):
//       db_backups/vendor_eval_requestor_manager_email.sql
//
// SAFETY (hard rules baked into this script):
//   * CLI ONLY — refuses to run from a web browser.
//   * DRY-RUN BY DEFAULT — no DB writes unless BOTH --apply and a matching
//     --confirm-db=DATABASE_NAME are supplied.
//   * Touches ONLY the `requestors` table, and only in apply mode.
//   * Never overwrites an existing public_token.
//   * Never invents email / phone / manager data — empty CSV fill columns
//     leave existing values untouched.
//   * Sends NO WhatsApp and NO email. Creates NO cron. Imports NO PR/PO data.
//   * Reuses workspace/config/database.php for the connection (no credentials
//     are duplicated or modified here). Never prints the DB password.
//
// USAGE:
//   Dry run (default, no writes):
//       php tools/import_vendor_eval_requestors.php --file=PATH_TO_CSV
//
//   Apply (writes to `requestors`) — BOTH flags required:
//       php tools/import_vendor_eval_requestors.php --file=PATH_TO_CSV \
//           --apply --confirm-db=u894129685_workspace
//
//   Optional (apply mode only):
//       --allow-partial   Import valid rows even if some rows have validation
//                         errors. DEFAULT is all-or-nothing (abort on any error).
//
// CSV column names expected (from the v2 contact cleanup export):
//   requestor_name, exists_in_requestors, required_action, current_public_token,
//   fill_email, fill_whatsapp_number, fill_manager_email
//
// NOTE: fill_manager_username is no longer used by this importer. If present
// in the CSV it is silently ignored. The DB column manager_username is left
// untouched.
//
// The --confirm-db value MUST exactly match the database the script actually
// connected to (SELECT DATABASE()). This guards against writing to the wrong
// database when CLI credential/host selection differs from the web app.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script can only be run from the command line.');
}

// Known placeholder values seeded into the requestors master.
// A fill column still holding a placeholder is treated as blank/not provided.
const PLACEHOLDER_EMAIL = 'sigit.ramadan@kawanlamacorp.com';
const PLACEHOLDER_PHONE = '6281232934734';

// Parse CLI arguments.
$apply        = in_array('--apply', $argv, true);
$allowPartial = in_array('--allow-partial', $argv, true);

$csvPath   = null;
$confirmDb = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--file=') === 0) {
        $csvPath = substr($arg, strlen('--file='));
    } elseif (strpos($arg, '--confirm-db=') === 0) {
        $confirmDb = substr($arg, strlen('--confirm-db='));
    }
}

if ($csvPath === null || $csvPath === '') {
    fwrite(STDERR, "ERROR: --file=PATH_TO_CSV is required.\n");
    fwrite(STDERR, "Usage: php tools/import_vendor_eval_requestors.php --file=PATH_TO_CSV [--apply --confirm-db=DB_NAME] [--allow-partial]\n");
    exit(1);
}

if (!is_file($csvPath) || !is_readable($csvPath)) {
    fwrite(STDERR, "ERROR: CSV file not found or not readable: {$csvPath}\n");
    exit(1);
}

// Connect (reuses the app's config). Never prints the password.
require_once __DIR__ . '/../workspace/config/database.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "ERROR: database connection (\$pdo) not available.\n");
    exit(1);
}

try {
    $currentDb = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
} catch (PDOException $e) {
    fwrite(STDERR, "ERROR: failed to read current database: " . $e->getMessage() . "\n");
    exit(1);
}

$dbHost = isset($host) && $host !== '' ? (string)$host : '(unknown)';

// Helpers.

/**
 * Normalize an Indonesian WhatsApp/phone number to 62XXXXXXXXXX form.
 * Returns [normalizedString|null, errorMessage|null].
 */
function normalize_whatsapp(string $raw): array
{
    $v = trim($raw);
    if ($v === '') {
        return [null, null];
    }
    $v = preg_replace('/[\s\-\(\)\.]/', '', $v);

    if (strpos($v, '+62') === 0) {
        $v = substr($v, 1);
    } elseif (strpos($v, '+') === 0) {
        $v = substr($v, 1);
    }

    if (strpos($v, '0') === 0) {
        $v = '62' . substr($v, 1);
    }

    if (!ctype_digit($v)) {
        return [null, "non-numeric after normalization ('{$v}')"];
    }
    $len = strlen($v);
    if ($len < 9 || $len > 15) {
        return [null, "unreasonable length ({$len} digits: '{$v}')"];
    }
    return [$v, null];
}

/** Strip a UTF-8 BOM from the start of a string if present. */
function strip_bom(string $s): string
{
    if (substr($s, 0, 3) === "\xEF\xBB\xBF") {
        return substr($s, 3);
    }
    return $s;
}

/**
 * Determine completeness of a (would-be) requestor record.
 * $manager here is manager_email. Returns "COMPLETE" or "INCOMPLETE: reasons".
 */
function completeness(?string $email, ?string $whatsapp, ?string $managerEmail, ?string $token): string
{
    $missing = [];

    $e = trim((string)$email);
    if ($e === '' || strcasecmp($e, PLACEHOLDER_EMAIL) === 0) {
        $missing[] = ($e === '' ? 'email missing' : 'email placeholder');
    }

    $w = trim((string)$whatsapp);
    if ($w === '' || $w === PLACEHOLDER_PHONE) {
        $missing[] = ($w === '' ? 'whatsapp missing' : 'whatsapp placeholder');
    }

    $m = trim((string)$managerEmail);
    if ($m === '') {
        $missing[] = 'manager_email missing';
    }

    $t = trim((string)$token);
    if ($t === '') {
        $missing[] = 'token missing';
    }

    return $missing ? ('INCOMPLETE: ' . implode(', ', $missing)) : 'COMPLETE';
}

// Load existing master data (read-only): requestors only.
// Attempt to SELECT manager_email; if the column does not yet exist
// (migration not applied), fall back to querying without it and emit a notice.
$existing                 = [];
$managerEmailColumnExists = false;

try {
    $stmt = $pdo->query(
        "SELECT id, requestor_name, email, whatsapp_number, manager_email, public_token FROM requestors"
    );
    $managerEmailColumnExists = true;
    foreach ($stmt as $r) {
        $key            = mb_strtolower(trim((string)$r['requestor_name']));
        $existing[$key] = $r;
    }
} catch (PDOException $e) {
    echo "[NOTICE] manager_email column not found in requestors — migration not yet applied.\n";
    echo "         Dry-run will proceed using current contact data only (manager_email treated as blank).\n";
    echo "         Run db_backups/vendor_eval_requestor_manager_email.sql before --apply.\n\n";
    try {
        $stmt = $pdo->query(
            "SELECT id, requestor_name, email, whatsapp_number, public_token FROM requestors"
        );
        foreach ($stmt as $r) {
            $key                      = mb_strtolower(trim((string)$r['requestor_name']));
            $r['manager_email']       = null;
            $existing[$key]           = $r;
        }
    } catch (PDOException $e2) {
        fwrite(STDERR, "ERROR: failed to read requestors: " . $e2->getMessage() . "\n");
        exit(1);
    }
}

// Read + parse CSV (header-mapped, so column order is not assumed).
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
$colIndex  = [];
foreach ($header as $i => $name) {
    $colIndex[trim((string)$name)] = $i;
}

$required = ['requestor_name'];
foreach ($required as $needed) {
    if (!array_key_exists($needed, $colIndex)) {
        fwrite(STDERR, "ERROR: CSV is missing required column '{$needed}'.\n");
        fclose($fh);
        exit(1);
    }
}

/** Fetch a column value from a CSV row by header name (trimmed; '' if absent). */
$cell = function (array $row, string $name) use ($colIndex): string {
    if (!array_key_exists($name, $colIndex)) {
        return '';
    }
    $idx = $colIndex[$name];
    return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
};

// Plan phase — compute a planned action per row. NO writes here.
$plans     = [];
$totalRows = 0;
$blankRows = 0;
$counts    = ['INSERT' => 0, 'UPDATE' => 0, 'SKIP' => 0, 'ERROR' => 0];
$seenNames = [];

while (($row = fgetcsv($fh)) !== false) {
    if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
        continue;
    }
    $totalRows++;

    $name = $cell($row, 'requestor_name');
    if ($name === '') {
        $blankRows++;
        $plans[] = [
            'name'            => '(blank)',
            'action'          => 'SKIP',
            'email_action'    => 'n/a',
            'whatsapp_action' => 'n/a',
            'manager_action'  => 'n/a',
            'token_action'    => 'n/a',
            'error'           => 'blank requestor_name',
            'completeness'    => 'n/a',
            '_has_email'      => false,
            '_has_wa'         => false,
            '_has_manager'    => false,
        ];
        $counts['SKIP']++;
        continue;
    }

    $nameKey = mb_strtolower($name);
    if (isset($seenNames[$nameKey])) {
        $plans[] = [
            'name'            => $name,
            'action'          => 'ERROR',
            'email_action'    => 'n/a',
            'whatsapp_action' => 'n/a',
            'manager_action'  => 'n/a',
            'token_action'    => 'n/a',
            'error'           => 'duplicate requestor_name in CSV (first seen on row ' . $seenNames[$nameKey] . ')',
            'completeness'    => 'NOT_IMPORTED (duplicate)',
            '_has_email'      => false,
            '_has_wa'         => false,
            '_has_manager'    => false,
        ];
        $counts['ERROR']++;
        continue;
    }
    $seenNames[$nameKey] = $totalRows;

    $exists  = array_key_exists($nameKey, $existing);
    $current = $exists ? $existing[$nameKey] : null;

    // CSV v2 fill columns.
    $newEmail   = $cell($row, 'fill_email');
    $newWa      = $cell($row, 'fill_whatsapp_number');
    $newManager = $cell($row, 'fill_manager_email');   // v2: direct email, not username

    $errors = [];

    // --- Email ---
    $emailToWrite = null;
    if ($newEmail !== '') {
        if (strcasecmp($newEmail, PLACEHOLDER_EMAIL) === 0) {
            $emailAction = 'PLACEHOLDER (treated as blank)';
        } elseif (filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $emailToWrite = $newEmail;
            $emailAction  = 'SET:' . $newEmail;
        } else {
            $errors[]    = "invalid email format ('{$newEmail}')";
            $emailAction = 'INVALID:' . $newEmail;
        }
    } else {
        $emailAction = $exists ? 'KEEP' : 'NONE';
    }

    // --- WhatsApp ---
    $waToWrite = null;
    if ($newWa !== '') {
        [$normWa, $waErr] = normalize_whatsapp($newWa);
        if ($normWa !== null && $normWa === PLACEHOLDER_PHONE) {
            $waAction = 'PLACEHOLDER (treated as blank)';
            $normWa   = null;
        } elseif ($normWa !== null) {
            $waToWrite = $normWa;
            $waAction  = 'SET:' . $normWa;
        } else {
            $errors[]  = "invalid whatsapp: {$waErr}";
            $waAction  = 'INVALID:' . $newWa;
        }
    } else {
        $waAction = $exists ? 'KEEP' : 'NONE';
    }

    // --- Manager email (v2: email format validation only; no users table lookup) ---
    $managerToWrite = null;
    if ($newManager !== '') {
        if (strcasecmp($newManager, PLACEHOLDER_EMAIL) === 0) {
            $managerAction = 'PLACEHOLDER (treated as blank)';
        } elseif (filter_var($newManager, FILTER_VALIDATE_EMAIL)) {
            $managerToWrite = $newManager;
            $managerAction  = 'SET:' . $newManager;
        } else {
            $errors[]      = "invalid manager_email format ('{$newManager}')";
            $managerAction = 'INVALID:' . $newManager;
        }
    } else {
        $curMgr        = trim((string)($current['manager_email'] ?? ''));
        $managerAction = ($exists && $curMgr !== '') ? 'KEEP' : 'NONE';
    }

    // --- Public token (never overwrite an existing token) ---
    $needToken = true;
    if ($exists) {
        $curToken    = trim((string)($current['public_token'] ?? ''));
        $needToken   = ($curToken === '');
        $tokenAction = $needToken ? 'GENERATE' : 'KEEP';
    } else {
        $tokenAction = 'GENERATE';
    }

    // --- Resulting (would-be) values for completeness + invite-readiness ---
    if ($exists) {
        $resEmail   = $emailToWrite   ?? (string)($current['email']          ?? '');
        $resWa      = $waToWrite      ?? (string)($current['whatsapp_number'] ?? '');
        $resManager = $managerToWrite ?? (string)($current['manager_email']   ?? '');
        $resToken   = $needToken ? 'WILL_GENERATE' : (string)($current['public_token'] ?? '');
    } else {
        $resEmail   = $emailToWrite   ?? '';
        $resWa      = $waToWrite      ?? '';
        $resManager = $managerToWrite ?? '';
        $resToken   = 'WILL_GENERATE';
    }

    $hasEmail   = ($resEmail   !== '' && strcasecmp($resEmail, PLACEHOLDER_EMAIL) !== 0);
    $hasWa      = ($resWa      !== '' && $resWa !== PLACEHOLDER_PHONE);
    $hasManager = ($resManager !== '');

    // Any valid fill column provided?
    $hasFill = ($emailToWrite !== null || $waToWrite !== null || $managerToWrite !== null);

    // --- Decide the action ---
    if ($errors) {
        $action       = 'ERROR';
        $completeness = 'NOT_IMPORTED (validation error)';
    } elseif (!$exists) {
        if (!$hasFill) {
            $action        = 'SKIP';
            $emailAction   = 'NONE';
            $waAction      = 'NONE';
            $managerAction = 'NONE';
            $tokenAction   = 'NONE';
            $errors[]      = 'Missing from requestors master but no filled contact data provided';
            $completeness  = 'INCOMPLETE: not in master, no data provided';
        } else {
            $action       = 'INSERT';
            $completeness = completeness($resEmail, $resWa, $resManager, $resToken);
        }
    } else {
        $hasChange    = ($emailToWrite !== null) || ($waToWrite !== null)
                     || ($managerToWrite !== null) || $needToken;
        $action       = $hasChange ? 'UPDATE' : 'SKIP';
        $completeness = completeness($resEmail, $resWa, $resManager, $resToken);
        if ($action === 'SKIP') {
            $emailAction   = 'KEEP';
            $waAction      = 'KEEP';
            $managerAction = $hasManager ? 'KEEP' : 'NONE';
            $tokenAction   = 'KEEP';
        }
    }

    $counts[$action]++;

    $plans[] = [
        'name'            => $name,
        'action'          => $action,
        'email_action'    => $emailAction,
        'whatsapp_action' => $waAction,
        'manager_action'  => $managerAction,
        'token_action'    => $tokenAction,
        'error'           => $errors ? implode('; ', $errors) : '',
        'completeness'    => $completeness,
        '_has_email'      => $hasEmail,
        '_has_wa'         => $hasWa,
        '_has_manager'    => $hasManager,
        // write payload (apply mode only)
        '_exists'         => $exists,
        '_id'             => $exists ? (int)$current['id'] : null,
        '_email'          => $emailToWrite,
        '_whatsapp'       => $waToWrite,
        '_manager'        => $managerToWrite,
        '_needToken'      => $needToken,
    ];
}
fclose($fh);

// Compute invite-readiness counts across all named rows (excludes blank / ERROR).
$cMissingEmail   = 0;
$cMissingWa      = 0;
$cMissingManager = 0;
$cReadyFull      = 0;   // email + WA + manager_email
$cReadyWaOnly    = 0;   // email + WA, manager_email still missing
$cNotReady       = 0;   // missing email or WA

foreach ($plans as $p) {
    if ($p['name'] === '(blank)' || $p['action'] === 'ERROR') {
        continue;
    }
    if (!$p['_has_email'])   $cMissingEmail++;
    if (!$p['_has_wa'])      $cMissingWa++;
    if (!$p['_has_manager']) $cMissingManager++;

    if (!$p['_has_email'] || !$p['_has_wa']) {
        $cNotReady++;
    } elseif ($p['_has_manager']) {
        $cReadyFull++;
    } else {
        $cReadyWaOnly++;
    }
}

// Diagnostics banner — printed on EVERY run.
echo "============================================================\n";
echo " Import Vendor Eval Requestors  (" . ($apply ? "APPLY" : "DRY-RUN") . ")\n";
echo "============================================================\n";
echo "Connected database : {$currentDb}\n";
echo "DB host            : {$dbHost}\n";
echo "CSV file           : {$csvPath}\n";
echo "Total CSV rows     : {$totalRows}\n";
echo "  would INSERT     : {$counts['INSERT']}\n";
echo "  would UPDATE     : {$counts['UPDATE']}\n";
echo "  would SKIP       : {$counts['SKIP']}" . ($blankRows ? " (incl. {$blankRows} blank)" : "") . "\n";
echo "  validation ERROR : {$counts['ERROR']}\n";
echo "------------------------------------------------------------\n";
echo "Contact completeness (result state after import):\n";
echo "  missing email         : {$cMissingEmail}\n";
echo "  missing WA            : {$cMissingWa}\n";
echo "  missing manager_email : {$cMissingManager}\n";
echo "------------------------------------------------------------\n";
echo "Invite readiness:\n";
echo "  email + WA + manager_email (FULL CC)  : {$cReadyFull}\n";
echo "  email + WA only  (no manager_email)   : {$cReadyWaOnly}\n";
echo "  not invite-ready (missing email or WA) : {$cNotReady}\n";
if (!$managerEmailColumnExists) {
    echo "\n[MIGRATION PENDING] manager_email column not yet in requestors.\n";
    echo "  Apply first: db_backups/vendor_eval_requestor_manager_email.sql\n";
    echo "  Then re-run with: --apply --confirm-db={$currentDb}\n";
}
echo "------------------------------------------------------------\n";

// Per-row report.
foreach ($plans as $p) {
    $line = sprintf(
        "[%-6s] %-34s email=%-20s wa=%-22s mgr_email=%-18s token=%-9s %s",
        $p['action'],
        mb_substr($p['name'], 0, 34),
        mb_substr($p['email_action'], 0, 20),
        mb_substr($p['whatsapp_action'], 0, 22),
        mb_substr($p['manager_action'], 0, 18),
        $p['token_action'],
        $p['completeness']
    );
    echo $line . "\n";
    if ($p['error'] !== '') {
        echo "         ! " . $p['error'] . "\n";
    }
}
echo "------------------------------------------------------------\n";

// Still-incomplete list.
$incomplete = array_filter($plans, function ($p) {
    return in_array($p['action'], ['INSERT', 'UPDATE', 'SKIP'], true)
        && strpos((string)$p['completeness'], 'INCOMPLETE') === 0;
});
echo "Still incomplete after import: " . count($incomplete) . "\n";
foreach ($incomplete as $p) {
    echo "  - {$p['name']}: {$p['completeness']}\n";
}
echo "------------------------------------------------------------\n";

// Write the runtime report CSV (every run, into git-ignored folder).
$reportDir = __DIR__ . '/../db_backups/local_runtime_exports';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0777, true);
}
$stamp      = date('Ymd_His');
$reportPath = $reportDir . "/vendor_eval_requestor_import_report_{$stamp}.csv";
$rfh        = fopen($reportPath, 'w');
if ($rfh !== false) {
    fputcsv($rfh, [
        'requestor_name', 'action', 'email_action', 'whatsapp_action',
        'manager_email_action', 'token_action', 'error_message', 'completeness_after_import',
        'has_email', 'has_wa', 'has_manager_email',
    ]);
    foreach ($plans as $p) {
        fputcsv($rfh, [
            $p['name'], $p['action'], $p['email_action'], $p['whatsapp_action'],
            $p['manager_action'], $p['token_action'], $p['error'], $p['completeness'],
            $p['_has_email']   ? 'YES' : 'NO',
            $p['_has_wa']      ? 'YES' : 'NO',
            $p['_has_manager'] ? 'YES' : 'NO',
        ]);
    }
    fclose($rfh);
    echo "Report CSV written : {$reportPath}\n";
} else {
    fwrite(STDERR, "WARNING: could not write report CSV to {$reportPath}\n");
}

// DRY-RUN: stop here. No DB writes.
if (!$apply) {
    echo "\n[DRY-RUN] No database changes were made.\n";
    if ($counts['ERROR'] > 0) {
        echo "Note: {$counts['ERROR']} row(s) have validation errors. Fix them in the CSV before --apply\n";
        echo "      (or use --allow-partial to import only the valid rows).\n";
    }
    if (!$managerEmailColumnExists) {
        echo "\nPREREQUISITE before --apply:\n";
        echo "  mysql -h HOST -u USER -p DATABASE < db_backups/vendor_eval_requestor_manager_email.sql\n";
    }
    echo "\nTo apply, re-run with BOTH flags:\n";
    echo "    php tools/import_vendor_eval_requestors.php --file={$csvPath} --apply --confirm-db={$currentDb}\n";
    exit(0);
}

// APPLY guard: manager_email column must exist before writing.
if (!$managerEmailColumnExists) {
    fwrite(STDERR, "ABORT: manager_email column not found in requestors table.\n");
    fwrite(STDERR, "       Apply the migration first:\n");
    fwrite(STDERR, "       mysql -h HOST -u USER -p {$currentDb} < db_backups/vendor_eval_requestor_manager_email.sql\n");
    fwrite(STDERR, "       No changes made.\n");
    exit(1);
}

// APPLY guard: require an explicit --confirm-db that matches the live DB.
if ($confirmDb === null) {
    fwrite(STDERR, "ABORT: --apply requires --confirm-db=DATABASE_NAME.\n");
    fwrite(STDERR, "       Connected database is '{$currentDb}'. To proceed, run:\n");
    fwrite(STDERR, "       php tools/import_vendor_eval_requestors.php --file={$csvPath} --apply --confirm-db={$currentDb}\n");
    exit(1);
}
if ($confirmDb !== $currentDb) {
    fwrite(STDERR, "ABORT: --confirm-db='{$confirmDb}' does not match connected database '{$currentDb}'.\n");
    fwrite(STDERR, "       No changes made.\n");
    exit(1);
}

// All-or-nothing default: abort if any validation errors, unless --allow-partial.
if ($counts['ERROR'] > 0 && !$allowPartial) {
    fwrite(STDERR, "ABORT: {$counts['ERROR']} row(s) have validation errors and --allow-partial was not given.\n");
    fwrite(STDERR, "       No changes made. Fix the CSV or re-run with --allow-partial.\n");
    exit(1);
}

// APPLY: write inside a transaction. Rollback on any exception.
/** Generate a unique 64-hex public_token, retrying on collision. */
$genToken = function (PDO $pdo): string {
    $check = $pdo->prepare("SELECT 1 FROM requestors WHERE public_token = ? LIMIT 1");
    for ($i = 0; $i < 6; $i++) {
        $t = bin2hex(random_bytes(32));   // 64 hex chars
        $check->execute([$t]);
        if ($check->fetchColumn() === false) {
            return $t;
        }
    }
    throw new RuntimeException("Unable to generate a unique public_token after retries.");
};

$inserted = 0;
$updated  = 0;
$skipped  = 0;

try {
    $pdo->beginTransaction();

    foreach ($plans as $p) {
        if ($p['action'] === 'ERROR' || $p['action'] === 'SKIP') {
            $skipped++;
            continue;
        }

        if ($p['action'] === 'INSERT') {
            $cols = ['requestor_name', 'public_token', 'email', 'whatsapp_number', 'manager_email'];
            $vals = [$p['name'], $genToken($pdo), $p['_email'], $p['_whatsapp'], $p['_manager']];

            $placeholders = implode(', ', array_fill(0, count($cols), '?'));
            $sql          = "INSERT INTO requestors (" . implode(', ', $cols) . ") VALUES ({$placeholders})";
            $pdo->prepare($sql)->execute($vals);
            $inserted++;
        } elseif ($p['action'] === 'UPDATE') {
            $set  = [];
            $vals = [];
            if ($p['_email']    !== null) { $set[] = 'email = ?';           $vals[] = $p['_email']; }
            if ($p['_whatsapp'] !== null) { $set[] = 'whatsapp_number = ?'; $vals[] = $p['_whatsapp']; }
            if ($p['_manager']  !== null) { $set[] = 'manager_email = ?';   $vals[] = $p['_manager']; }
            if ($p['_needToken'])         { $set[] = 'public_token = ?';    $vals[] = $genToken($pdo); }

            if (!$set) {
                $skipped++;
                continue;
            }
            $vals[] = $p['_id'];
            $sql    = "UPDATE requestors SET " . implode(', ', $set) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($vals);
            $updated++;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\nERROR during apply — transaction rolled back. No changes were committed.\n");
    fwrite(STDERR, "  " . $e->getMessage() . "\n");
    exit(1);
}

echo "\n[APPLY] Done. Inserted: {$inserted}. Updated: {$updated}. Skipped: {$skipped}.\n";
if ($counts['ERROR'] > 0) {
    echo "Note: {$counts['ERROR']} row(s) skipped due to validation errors (--allow-partial).\n";
}
exit(0);
