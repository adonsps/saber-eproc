<?php
// tools/import_2024_vendors_direct_registration.php
//
// Bulk "Direct Registration" of the 2024 vendor master into the VSS Platform
// (u894129685_vmsportal). Mirrors modules/sourcing/direct_registration_action.php
// per vendor (users login + vendors profile + multi-CP link), but sourced from a
// CSV instead of a web form, and additionally pre-fills vendors.core_business
// from the 2024 classification.
//
// Each NEW vendor gets:
//   - a users row: role='vendor', is_active=1, contact_role='primary',
//     contact_status='active', a UNIQUE placeholder email, password = the shared
//     default (Vendor123 by default; hashed with password_hash()).
//   - a vendors row: company_name, core_business (2024), vendor_code (if any),
//     registration_status='registration_submitted', registered_by_sourcing_id
//     (optional), admin_notes='2024 bulk direct-registration import' (provenance
//     + rollback marker), email/phone left NULL.
//   - users.vendor_id linked to the new vendor.
//
// Vendors whose normalized company_name already exists in VSS are SKIPPED
// (reported, not written). Normalization: UPPER -> non-[A-Z0-9 ] to space ->
// collapse spaces -> trim (matches the offline dedup analysis).
//
// Usage:
//   php tools/import_2024_vendors_direct_registration.php [--csv=PATH]
//   php tools/import_2024_vendors_direct_registration.php --apply \
//       --confirm-db=DB --confirm-host=HOST [--limit=N] [--sourcing-id=ID] \
//       [--password=STR] [--placeholder-domain=DOMAIN]
//
//   (default)               DRY-RUN: SELECT only, writes a preview CSV, NO writes.
//   --apply                 DB WRITE mode. Requires --confirm-db and --confirm-host.
//   --confirm-db=DB         Must equal DATABASE() or apply aborts.
//   --confirm-host=HOST     Must equal @@hostname or apply aborts. (Local and prod
//                           share the DB name u894129685_vmsportal, so @@hostname is
//                           the real environment discriminator — pin it deliberately.)
//   --limit=N               Only create the first N new vendors (bounded first run).
//   --sourcing-id=ID        Set vendors.registered_by_sourcing_id for the batch.
//   --password=STR          Shared default password (default: Vendor123).
//   --placeholder-domain=D  Placeholder email domain (default: placeholder.vss).
//   --csv=PATH              Source CSV (default: db_backups/vss_direct_registration_2024_source.csv)
//                           Columns: company_name, core_business, vendor_code
//
// SAFETY:
//   - CLI only. SELECT-only unless --apply.
//   - --apply verified post-connect against DATABASE() and @@hostname; any mismatch
//     aborts before a single row is written. USER() is printed for transparency
//     (this app connects as a non-localhost DB user by design, so it is not gated on).
//   - --apply runs in ONE transaction; any error rolls the whole batch back.
//   - Idempotent by construction: existing company_names are skipped, so re-running
//     never double-creates. Rollback: DELETE by the admin_notes marker + placeholder domain.

if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only.\n");
}

$argv_in = $argv ?? [];
$opt_apply   = in_array('--apply', $argv_in, true);
$confirm_db = $confirm_host = null;
$limit = null; $sourcing_id = null;
$password = 'Vendor123';
$domain   = 'placeholder.vss';
$root = dirname(__DIR__);
$csv_path = $root . '/db_backups/vss_direct_registration_2024_source.csv';
foreach ($argv_in as $a) {
    if (preg_match('/^--confirm-db=(.+)$/', $a, $m))            $confirm_db = $m[1];
    elseif (preg_match('/^--confirm-host=(.+)$/', $a, $m))      $confirm_host = $m[1];
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m))            $limit = (int)$m[1];
    elseif (preg_match('/^--sourcing-id=(\d+)$/', $a, $m))      $sourcing_id = (int)$m[1];
    elseif (preg_match('/^--password=(.+)$/', $a, $m))          $password = $m[1];
    elseif (preg_match('/^--placeholder-domain=(.+)$/', $a, $m))$domain = $m[1];
    elseif (preg_match('/^--csv=(.+)$/', $a, $m))               $csv_path = $m[1];
}
if ($opt_apply) {
    if (!$confirm_db)   exit("\nSAFETY ABORT: --apply requires --confirm-db=DATABASE_NAME.\n\n");
    if (!$confirm_host) exit("\nSAFETY ABORT: --apply requires --confirm-host=HOSTNAME.\n\n");
}

require $root . '/config.php'; // provides $conn (mysqli -> vmsportal)
if ($conn->connect_errno) exit("DB connect failed: " . $conn->connect_error . "\n");
$conn->set_charset('utf8mb4');

$cur_db   = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
$cur_host = (string)$conn->query('SELECT @@hostname')->fetch_row()[0];
$cur_user = (string)$conn->query('SELECT USER()')->fetch_row()[0];

$mode = $opt_apply ? 'APPLY / DB WRITE' : 'DRY-RUN (no writes)';
echo "\n=======================================================================\n";
echo " 2024 Vendors -> VSS Direct Registration ({$mode})\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n";
echo "Target: db={$cur_db} host={$cur_host} user={$cur_user}\n";

if ($opt_apply) {
    $errs = [];
    if ($cur_db !== $confirm_db)     $errs[] = "DATABASE() '{$cur_db}' != --confirm-db '{$confirm_db}'";
    if ($cur_host !== $confirm_host) $errs[] = "@@hostname '{$cur_host}' != --confirm-host '{$confirm_host}'";
    if ($errs) exit("\nSAFETY ABORT: --apply target verification FAILED. No rows written.\n  - "
                   . implode("\n  - ", $errs) . "\n\n");
    echo "[--apply] target verified.\n";
}

if (!is_file($csv_path)) exit("Source CSV not found: {$csv_path}\n");

// Column-existence guards for multi-CP columns (added by includes/vendor_contacts_schema.php).
$has_col = function (string $table, string $col) use ($conn): bool {
    $t = $conn->real_escape_string($table); $c = $conn->real_escape_string($col);
    return (bool)$conn->query("SHOW COLUMNS FROM `{$t}` LIKE '{$c}'")->num_rows;
};
$users_has_contact_role   = $has_col('users', 'contact_role');
$users_has_contact_status = $has_col('users', 'contact_status');
$users_has_vendor_id      = $has_col('users', 'vendor_id');

function norm_name(string $s): string {
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

// Existing vendors (dedup) + existing user emails (placeholder uniqueness).
$existing_names = [];
$r = $conn->query("SELECT company_name FROM vendors");
while ($row = $r->fetch_row()) $existing_names[norm_name((string)$row[0])] = true;
$existing_emails = [];
$r = $conn->query("SELECT email FROM users");
while ($row = $r->fetch_row()) $existing_emails[strtolower((string)$row[0])] = true;

echo "Existing VSS vendors: " . count($existing_names) . " | existing user emails: " . count($existing_emails) . "\n";

// Read source CSV.
$fh = fopen($csv_path, 'r');
$header = fgetcsv($fh);
$header = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)), $header);
$ix = array_flip($header);
foreach (['company_name', 'core_business', 'vendor_code'] as $need) {
    if (!isset($ix[$need])) exit("CSV missing column: {$need}\n");
}

$to_create = []; $skipped = 0; $seq = 0; $used_emails = [];
while (($row = fgetcsv($fh)) !== false) {
    $company = trim((string)($row[$ix['company_name']] ?? ''));
    if ($company === '') continue;
    $core    = trim((string)($row[$ix['core_business']] ?? ''));
    $code    = trim((string)($row[$ix['vendor_code']] ?? ''));
    $n = norm_name($company);
    if (isset($existing_names[$n])) { $skipped++; continue; }
    // Unique placeholder email: readable slug + sequence, guaranteed unique.
    $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $company));
    $slug = trim(substr($slug, 0, 40), '_');
    do {
        $seq++;
        $email = sprintf('%s_%d@%s', $slug !== '' ? $slug : 'vendor', $seq, $domain);
        $email = substr($email, 0, 150);
        $lk = strtolower($email);
    } while (isset($existing_emails[$lk]) || isset($used_emails[$lk]));
    $used_emails[$lk] = true;
    // Reserve the normalized name too, so intra-batch duplicate names collapse to one.
    $existing_names[$n] = true;
    $to_create[] = ['company' => $company, 'core' => $core, 'code' => $code, 'email' => $email];
}
fclose($fh);

if ($limit !== null) $to_create = array_slice($to_create, 0, $limit);

echo "Source rows -> to CREATE: " . count($to_create) . " | SKIPPED (already in VSS): {$skipped}\n";

// Preview CSV.
$exp_dir = $root . '/db_backups/local_runtime_exports';
if (!is_dir($exp_dir)) mkdir($exp_dir, 0755, true);
$preview = $exp_dir . '/vss_direct_registration_preview_' . date('Ymd_His') . '.csv';
$pf = fopen($preview, 'w');
fwrite($pf, "\xEF\xBB\xBF");
fputcsv($pf, ['company_name', 'core_business', 'vendor_code', 'placeholder_email', 'registration_status', 'action']);
foreach ($to_create as $v) {
    fputcsv($pf, [$v['company'], $v['core'], $v['code'], $v['email'], 'migrated', $opt_apply ? 'created' : 'would_create']);
}
fclose($pf);
echo "Preview CSV: {$preview}\n";

if (!$opt_apply) {
    echo "\nDRY-RUN complete. No rows written. Re-run with --apply --confirm-db=... --confirm-host=... to write.\n\n";
    exit(0);
}

// ── APPLY ────────────────────────────────────────────────────────────────
$hashed = password_hash($password, PASSWORD_DEFAULT);
$note   = '2024 bulk direct-registration import';

$u_cols = ['email', 'password_hash', 'full_name', 'role', 'is_active'];
$u_vals = ['?', '?', '?', "'vendor'", '1'];
if ($users_has_contact_role)   { $u_cols[] = 'contact_role';   $u_vals[] = "'primary'"; }
if ($users_has_contact_status) { $u_cols[] = 'contact_status'; $u_vals[] = "'active'"; }
$sql_u = "INSERT INTO users (" . implode(',', $u_cols) . ") VALUES (" . implode(',', $u_vals) . ")";
$stmt_u = $conn->prepare($sql_u);

// NOTE: status 'migrated' (not 'registration_submitted') — it is the enum value the
// existing bulk-loaded historical-vendor cohort already uses on VSS, and (unlike
// 'registration_submitted') it is present in this DB's registration_status enum.
$stmt_v = $conn->prepare("INSERT INTO vendors
    (user_id, company_name, core_business, vendor_code, registered_by_sourcing_id,
     registration_status, admin_notes, created_at)
    VALUES (?, ?, ?, ?, ?, 'migrated', ?, NOW())");
$stmt_link = $users_has_vendor_id ? $conn->prepare("UPDATE users SET vendor_id = ? WHERE id = ?") : null;

$created = 0; $conn->begin_transaction();
try {
    foreach ($to_create as $v) {
        $stmt_u->bind_param('sss', $v['email'], $hashed, $v['company']);
        $stmt_u->execute();
        $user_id = $conn->insert_id;

        $code = $v['code'] !== '' ? $v['code'] : null;
        $stmt_v->bind_param('isssss', $user_id, $v['company'], $v['core'], $code, $sourcing_id, $note);
        $stmt_v->execute();
        $vendor_id = $conn->insert_id;

        if ($stmt_link) { $stmt_link->bind_param('ii', $vendor_id, $user_id); $stmt_link->execute(); }
        $created++;
    }
    $conn->commit();
    echo "DB WRITE committed. Vendors created: {$created}\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "\nDB WRITE FAILED — rolled back. No rows persisted.\n  " . $e->getMessage() . "\n\n");
    exit(1);
}
echo "Done.\n\n";
