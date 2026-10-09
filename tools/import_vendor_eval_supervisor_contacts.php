<?php
// tools/import_vendor_eval_supervisor_contacts.php
//
// Matches CSV "Nama Karyawan" rows to requestors.requestor_name and previews
// (or applies) an update of requestors.manager_email from "Alamat Email Superior".
//
// Usage:
//   php tools/import_vendor_eval_supervisor_contacts.php --csv=<path> --preview
//   php tools/import_vendor_eval_supervisor_contacts.php --csv=<path> --apply --confirm-db=<db> --confirm-host=<host>
//
// Default mode is preview (dry-run) when --apply is not passed.

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

$expectedDb   = 'u894129685_workspace';
$expectedHost = 'KLG-PROC-I01271';

$opts = getopt('', ['csv:', 'preview', 'apply', 'confirm-db:', 'confirm-host:']);

$csvPath = $opts['csv'] ?? null;
$apply   = array_key_exists('apply', $opts);
$dry     = !$apply;

if (!$csvPath) {
    fwrite(STDERR, "Missing required --csv=<path> argument.\n");
    exit(1);
}
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: $csvPath\n");
    exit(1);
}

if ($apply) {
    $confirmDb   = $opts['confirm-db'] ?? null;
    $confirmHost = $opts['confirm-host'] ?? null;
    if ($confirmDb !== $expectedDb || $confirmHost !== $expectedHost) {
        fwrite(STDERR, "Refusing --apply: --confirm-db and --confirm-host must match expected identity ($expectedDb / $expectedHost).\n");
        exit(1);
    }
}

echo $dry
    ? "[DRY RUN] Preview mode. No changes will be written.\n"
    : "[APPLY MODE] Changes will be written to requestors.manager_email.\n";

require __DIR__ . '/../workspace/config/database.php';
/** @var PDO $pdo */

// ---- DB identity gate ----
$identity = $pdo->query("SELECT DATABASE() AS db_name, USER() AS db_user, @@hostname AS db_host, VERSION() AS db_version")->fetch();
echo "DB Identity: db={$identity['db_name']} user={$identity['db_user']} host={$identity['db_host']} version={$identity['db_version']}\n";

if ($apply && ($identity['db_name'] !== $expectedDb || $identity['db_host'] !== $expectedHost)) {
    fwrite(STDERR, "Refusing --apply: live DB identity does not match expected values.\n");
    exit(1);
}

// ---- Inspect requestors schema (adapt, don't assume) ----
$requestorColumns = [];
foreach ($pdo->query("DESCRIBE requestors") as $col) {
    $requestorColumns[] = $col['Field'];
}

$nameCol = in_array('requestor_name', $requestorColumns, true)
    ? 'requestor_name'
    : (in_array('name', $requestorColumns, true) ? 'name' : null);
$emailCol            = in_array('email', $requestorColumns, true) ? 'email' : null;
$waCol               = in_array('whatsapp_number', $requestorColumns, true) ? 'whatsapp_number' : null;
$managerUsernameCol  = in_array('manager_username', $requestorColumns, true) ? 'manager_username' : null;
$managerEmailCol     = in_array('manager_email', $requestorColumns, true) ? 'manager_email' : null;
$idCol               = in_array('id', $requestorColumns, true) ? 'id' : null;

if (!$idCol || !$nameCol || !$managerEmailCol) {
    fwrite(STDERR, "requestors table is missing an expected id/name/manager_email column. Aborting.\n");
    exit(1);
}

// ---- Load users table for manager_username conflict audit ----
$usersByUsername = [];
if ($managerUsernameCol) {
    foreach ($pdo->query("SELECT username, email FROM users") as $u) {
        $usersByUsername[$u['username']] = $u['email'];
    }
}

// ---- Name normalization for matching ----
function normalizeName(string $name): string {
    $name = str_replace("\xC2\xA0", ' ', $name); // NBSP -> space
    $name = mb_strtoupper(trim($name), 'UTF-8');
    $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name); // strip punctuation
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function isInvalidSupervisorEmail(string $email): bool {
    $email = trim($email);
    if ($email === '') {
        return true;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) === false;
}

// ---- Read CSV ----
$requiredCsvCols = [
    'Nama Karyawan', 'NIP', 'Posisi', 'NIP Superior',
    'Nama Superior', 'Jabatan Superior', 'Alamat Email Superior', 'Notes',
];

$fh = fopen($csvPath, 'r');
if ($fh === false) {
    fwrite(STDERR, "Unable to open CSV: $csvPath\n");
    exit(1);
}

$header = fgetcsv($fh);
if ($header === false) {
    fwrite(STDERR, "CSV appears empty.\n");
    exit(1);
}
// Strip UTF-8 BOM from first header cell if present
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
$header = array_map('trim', $header);

$colIndex = [];
foreach ($header as $i => $name) {
    $colIndex[$name] = $i;
}
foreach ($requiredCsvCols as $required) {
    if (!array_key_exists($required, $colIndex)) {
        fwrite(STDERR, "CSV missing required column: $required\n");
        exit(1);
    }
}

$csvRows = [];
$totalCsvRows = 0;
while (($row = fgetcsv($fh)) !== false) {
    if (count($row) === 1 && trim((string)$row[0]) === '') {
        continue; // skip blank trailing lines
    }
    $totalCsvRows++;
    $get = function (string $col) use ($row, $colIndex) {
        $idx = $colIndex[$col];
        return isset($row[$idx]) ? trim((string)$row[$idx]) : '';
    };
    $csvRows[] = [
        'nama_karyawan'          => $get('Nama Karyawan'),
        'nip'                    => $get('NIP'),
        'posisi'                 => $get('Posisi'),
        'nip_superior'           => $get('NIP Superior'),
        'nama_superior'          => $get('Nama Superior'),
        'jabatan_superior'       => $get('Jabatan Superior'),
        'alamat_email_superior'  => $get('Alamat Email Superior'),
        'notes'                  => $get('Notes'),
    ];
}
fclose($fh);

// Index CSV rows by normalized employee name (to detect AMBIGUOUS matches)
$csvByName = [];
foreach ($csvRows as $row) {
    $key = normalizeName($row['nama_karyawan']);
    $csvByName[$key][] = $row;
}

// ---- Load requestors ----
$requestors = $pdo->query("SELECT * FROM requestors")->fetchAll();

// ---- Build preview rows ----
$preview = [];
$counts = [
    'matched'                  => 0,
    'unmatched'                => 0,
    'ambiguous'                => 0,
    'invalid_email'            => 0,
    'resign'                   => 0,
    'same_manager_email'       => 0,
    'would_update'             => 0,
    'manager_username_conflict'=> 0,
];

foreach ($requestors as $r) {
    $currentName          = $r[$nameCol] ?? '';
    $currentEmail         = $emailCol ? ($r[$emailCol] ?? '') : '';
    $currentWhatsapp      = $waCol ? ($r[$waCol] ?? '') : '';
    $currentManagerUser   = $managerUsernameCol ? ($r[$managerUsernameCol] ?? '') : '';
    $currentManagerEmail  = $r[$managerEmailCol] ?? '';

    $normKey = normalizeName($currentName);
    $matches = $csvByName[$normKey] ?? [];

    $row = [
        'requestor_id'            => $r[$idCol],
        'current_requestor_name'  => $currentName,
        'current_requestor_email' => $currentEmail,
        'current_whatsapp'        => $currentWhatsapp,
        'current_manager_username'=> $currentManagerUser,
        'current_manager_email'   => $currentManagerEmail,
        'matched_csv_employee_name' => '',
        'nip'                     => '',
        'posisi'                  => '',
        'nama_superior'           => '',
        'jabatan_superior'        => '',
        'alamat_email_superior'   => '',
        'notes'                   => '',
        'proposed_manager_email'  => '',
        'action'                  => '',
    ];

    if (count($matches) === 0) {
        $row['action'] = 'UNMATCHED';
        $counts['unmatched']++;
        $preview[] = $row;
        continue;
    }

    if (count($matches) > 1) {
        $row['action'] = 'AMBIGUOUS';
        $counts['ambiguous']++;
        $preview[] = $row;
        continue;
    }

    $counts['matched']++;
    $csv = $matches[0];
    $row['matched_csv_employee_name'] = $csv['nama_karyawan'];
    $row['nip']                       = $csv['nip'];
    $row['posisi']                    = $csv['posisi'];
    $row['nama_superior']             = $csv['nama_superior'];
    $row['jabatan_superior']          = $csv['jabatan_superior'];
    $row['alamat_email_superior']     = $csv['alamat_email_superior'];
    $row['notes']                     = $csv['notes'];

    if (stripos($csv['notes'], 'resign') !== false) {
        $row['action'] = 'SKIP_RESIGN';
        $counts['resign']++;
        $preview[] = $row;
        continue;
    }

    if (isInvalidSupervisorEmail($csv['alamat_email_superior'])) {
        $row['action'] = 'SKIP_INVALID_SUPERVISOR_EMAIL';
        $counts['invalid_email']++;
        $preview[] = $row;
        continue;
    }

    $proposedEmail = trim($csv['alamat_email_superior']);
    $row['proposed_manager_email'] = $proposedEmail;

    if ($managerUsernameCol && $currentManagerUser !== '' && isset($usersByUsername[$currentManagerUser])) {
        $resolvedEmail = $usersByUsername[$currentManagerUser];
        if (strcasecmp(trim($resolvedEmail), $proposedEmail) !== 0) {
            $row['action'] = 'MANAGER_USERNAME_CONFLICT_REVIEW';
            $counts['manager_username_conflict']++;
            $preview[] = $row;
            continue;
        }
    }

    if (strcasecmp(trim((string)$currentManagerEmail), $proposedEmail) === 0) {
        $row['action'] = 'SAME_MANAGER_EMAIL';
        $counts['same_manager_email']++;
    } else {
        $row['action'] = 'UPDATE_MANAGER_EMAIL';
        $counts['would_update']++;
    }

    $preview[] = $row;
}

// ---- Write preview CSV report ----
$exportDir = __DIR__ . '/../db_backups/local_runtime_exports';
if (!is_dir($exportDir)) {
    mkdir($exportDir, 0777, true);
}
$timestamp = date('Ymd_His');
$reportPath = $exportDir . "/vendor_eval_supervisor_contact_preview_{$timestamp}.csv";

$out = fopen($reportPath, 'w');
fputcsv($out, [
    'requestor_id', 'current_requestor_name', 'current_requestor_email', 'current_whatsapp',
    'current_manager_username', 'current_manager_email', 'matched_csv_employee_name',
    'nip', 'posisi', 'nama_superior', 'jabatan_superior', 'alamat_email_superior', 'notes',
    'proposed_manager_email', 'action',
]);
foreach ($preview as $row) {
    fputcsv($out, $row);
}
fclose($out);

// ---- Print summary ----
echo "\n--- Summary ---\n";
echo "Total CSV rows: {$totalCsvRows}\n";
echo "Total requestors: " . count($requestors) . "\n";
echo "Matched requestors: {$counts['matched']}\n";
echo "Unmatched requestors: {$counts['unmatched']}\n";
echo "Ambiguous matches: {$counts['ambiguous']}\n";
echo "Invalid supervisor emails: {$counts['invalid_email']}\n";
echo "Resign skipped: {$counts['resign']}\n";
echo "Same manager email: {$counts['same_manager_email']}\n";
echo "Would update manager_email: {$counts['would_update']}\n";
echo "Manager_username conflict review: {$counts['manager_username_conflict']}\n";
echo "\nPreview report written to: {$reportPath}\n";

// ---- Apply (guarded; not invoked unless --apply + identity confirmed) ----
function applyChanges(PDO $pdo, array $preview, string $managerEmailCol, string $idCol): void {
    $toUpdate = array_filter($preview, fn($row) => $row['action'] === 'UPDATE_MANAGER_EMAIL');
    if (empty($toUpdate)) {
        echo "No rows to update.\n";
        return;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE requestors SET {$managerEmailCol} = :email WHERE {$idCol} = :id");
        foreach ($toUpdate as $row) {
            $stmt->execute([
                ':email' => $row['proposed_manager_email'],
                ':id'    => $row['requestor_id'],
            ]);
        }
        $pdo->commit();
        echo "Applied " . count($toUpdate) . " manager_email updates.\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "Apply failed, rolled back: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if ($apply) {
    applyChanges($pdo, $preview, $managerEmailCol, $idCol);
}
