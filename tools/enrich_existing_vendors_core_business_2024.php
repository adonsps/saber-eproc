<?php
// tools/enrich_existing_vendors_core_business_2024.php
//
// FILL-ONLY enrichment: for VSS vendors (u894129685_vmsportal) that already exist
// but have an EMPTY core_business, fill it in from the 2024 classification, matched
// by normalized company_name. This is the companion to
// tools/import_2024_vendors_direct_registration.php (which created the NEW vendors);
// this one only *updates an empty column* on pre-existing rows.
//
// Guarantees:
//   - Fill-only: UPDATE is guarded WHERE core_business IS NULL OR TRIM(core_business)=''
//     so it can NEVER overwrite an existing value.
//   - Only single, unambiguous name matches are applied. If a normalized name maps to
//     two different core_business strings in the source, it is reported and SKIPPED.
//   - Writes a rollback CSV of every (id, prior core_business) before applying, so the
//     change can be reverted precisely.
//   - No new rows, no other columns touched.
//
// Normalization matches the import tool: UPPER -> non-[A-Z0-9 ] to space ->
// collapse spaces -> trim.
//
// Usage:
//   php tools/enrich_existing_vendors_core_business_2024.php [--csv=PATH]
//   php tools/enrich_existing_vendors_core_business_2024.php --apply \
//       --confirm-db=DB --confirm-host=HOST [--limit=N]
//
//   (default)             DRY-RUN: SELECT only, writes a preview CSV, NO writes.
//   --apply               DB WRITE mode. Requires --confirm-db and --confirm-host.
//   --confirm-db=DB       Must equal DATABASE() or apply aborts.
//   --confirm-host=HOST   Must equal @@hostname or apply aborts.
//   --limit=N             Only update the first N matched vendors (bounded first run).
//   --csv=PATH            Source CSV (default: db_backups/vss_core_business_enrichment_2024_source.csv)
//                         Columns: company_name, core_business
//
// SAFETY:
//   - CLI only. SELECT-only unless --apply.
//   - --apply verified post-connect against DATABASE() and @@hostname; mismatch aborts.
//   - --apply runs in ONE transaction; any error rolls the whole batch back.
//   - Idempotent: only empty core_business rows are targeted, so re-running is a no-op.
//   - Rollback: apply the generated rollback CSV, or:
//       UPDATE vendors SET core_business = NULL WHERE id IN (<ids in rollback file>);

if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only.\n");
}

$argv_in = $argv ?? [];
$opt_apply = in_array('--apply', $argv_in, true);
$confirm_db = $confirm_host = null;
$limit = null;
$root = dirname(__DIR__);
$csv_path = $root . '/db_backups/vss_core_business_enrichment_2024_source.csv';
foreach ($argv_in as $a) {
    if (preg_match('/^--confirm-db=(.+)$/', $a, $m))       $confirm_db = $m[1];
    elseif (preg_match('/^--confirm-host=(.+)$/', $a, $m)) $confirm_host = $m[1];
    elseif (preg_match('/^--limit=(\d+)$/', $a, $m))       $limit = (int)$m[1];
    elseif (preg_match('/^--csv=(.+)$/', $a, $m))          $csv_path = $m[1];
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
echo " 2024 Core Business -> enrich EXISTING VSS vendors ({$mode})\n";
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

function norm_name(string $s): string {
    $s = strtoupper($s);
    $s = preg_replace('/[^A-Z0-9 ]/', ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
}

// Read source CSV -> normalized name => core_business (detect ambiguity).
$fh = fopen($csv_path, 'r');
$header = fgetcsv($fh);
$header = array_map(fn($h) => trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h)), $header);
$ix = array_flip($header);
foreach (['company_name', 'core_business'] as $need) {
    if (!isset($ix[$need])) exit("CSV missing column: {$need}\n");
}
$src = [];            // norm => core_business (single)
$src_conflict = [];   // norm => true if conflicting core_business seen
while (($row = fgetcsv($fh)) !== false) {
    $company = trim((string)($row[$ix['company_name']] ?? ''));
    $core    = trim((string)($row[$ix['core_business']] ?? ''));
    if ($company === '' || $core === '') continue;
    $n = norm_name($company);
    if (isset($src[$n]) && $src[$n] !== $core) { $src_conflict[$n] = true; }
    else $src[$n] = $core;
}
fclose($fh);
echo "Source names with core_business: " . count($src) . " | ambiguous names: " . count($src_conflict) . "\n";

// Existing vendors with EMPTY core_business.
$res = $conn->query("SELECT id, company_name FROM vendors
                     WHERE core_business IS NULL OR TRIM(core_business) = ''");
$to_update = []; $skipped_ambig = 0; $no_match = 0;
while ($v = $res->fetch_assoc()) {
    $n = norm_name((string)$v['company_name']);
    if (isset($src_conflict[$n])) { $skipped_ambig++; continue; }
    if (!isset($src[$n]))         { $no_match++; continue; }
    $to_update[] = ['id' => (int)$v['id'], 'company' => $v['company_name'], 'core' => $src[$n]];
}
if ($limit !== null) $to_update = array_slice($to_update, 0, $limit);

echo "Existing vendors missing core_business -> to FILL: " . count($to_update)
   . " | skipped ambiguous: {$skipped_ambig} | no 2024 match: {$no_match}\n";

// Preview CSV.
$exp_dir = $root . '/db_backups/local_runtime_exports';
if (!is_dir($exp_dir)) mkdir($exp_dir, 0755, true);
$preview = $exp_dir . '/vss_core_business_enrich_preview_' . date('Ymd_His') . '.csv';
$pf = fopen($preview, 'w');
fwrite($pf, "\xEF\xBB\xBF");
fputcsv($pf, ['vendor_id', 'company_name', 'new_core_business', 'action']);
foreach ($to_update as $v) {
    fputcsv($pf, [$v['id'], $v['company'], $v['core'], $opt_apply ? 'filled' : 'would_fill']);
}
fclose($pf);
echo "Preview CSV: {$preview}\n";

if (!$opt_apply) {
    echo "\nDRY-RUN complete. No rows written. Re-run with --apply --confirm-db=... --confirm-host=... to write.\n\n";
    exit(0);
}

// ── APPLY ────────────────────────────────────────────────────────────────
// Rollback snapshot FIRST: record each id's prior core_business (NULL/empty here).
$rollback = $exp_dir . '/vss_core_business_enrich_rollback_' . date('Ymd_His') . '.csv';
$rf = fopen($rollback, 'w');
fwrite($rf, "\xEF\xBB\xBF");
fputcsv($rf, ['vendor_id', 'prior_core_business']);
$ids = array_map(fn($v) => $v['id'], $to_update);
if ($ids) {
    $in = implode(',', array_map('intval', $ids));
    $rs = $conn->query("SELECT id, core_business FROM vendors WHERE id IN ({$in})");
    while ($r = $rs->fetch_assoc()) fputcsv($rf, [$r['id'], $r['core_business']]);
}
fclose($rf);
echo "Rollback CSV: {$rollback}\n";

// Fill-only UPDATE (guarded so a concurrent non-empty value is never clobbered).
$stmt = $conn->prepare("UPDATE vendors SET core_business = ?
                        WHERE id = ? AND (core_business IS NULL OR TRIM(core_business) = '')");
$filled = 0; $conn->begin_transaction();
try {
    foreach ($to_update as $v) {
        $stmt->bind_param('si', $v['core'], $v['id']);
        $stmt->execute();
        $filled += $stmt->affected_rows;
    }
    $conn->commit();
    echo "DB WRITE committed. Vendors enriched: {$filled}\n";
} catch (Throwable $e) {
    $conn->rollback();
    fwrite(STDERR, "\nDB WRITE FAILED — rolled back. No rows persisted.\n  " . $e->getMessage() . "\n\n");
    exit(1);
}
echo "Done.\n\n";
