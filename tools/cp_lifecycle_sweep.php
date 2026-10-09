<?php
// tools/cp_lifecycle_sweep.php
//
// Phase 4 lifecycle sweep for vendor Contact Persons (CPs).
// Auto-suspends MEMBER CPs that have been inactive for longer than
// VENDOR_CP_INACTIVITY_DAYS so that stale logins (e.g. someone who quietly left
// the vendor) cannot linger with access. The PRIMARY contact is NEVER touched.
//
// SAFETY (house rules, mirrors other tools/ scripts):
//   * CLI ONLY by default — refuses to run from a browser unless a matching
//     CP_SWEEP_KEY env var is configured and passed as ?key= (for HTTP cron).
//   * DRY-RUN BY DEFAULT — lists what WOULD change; writes nothing.
//   * Writes only when BOTH --apply and --confirm-db=<database> are supplied.
//   * Touches only users.contact_status / is_active for inactive MEMBERS, and
//     appends to vendor_contact_audit. Never deletes. Never emails.
//
// Usage:
//   php tools/cp_lifecycle_sweep.php                         # dry-run
//   php tools/cp_lifecycle_sweep.php --apply --confirm-db=u894129685_vmsportal

require __DIR__ . '/../config.php';                 // provides $conn and $db
require_once __DIR__ . '/../includes/vendor_context.php';

$IS_CLI = (php_sapi_name() === 'cli');

// --- Web guard ------------------------------------------------------------
if (!$IS_CLI) {
    $key = getenv('CP_SWEEP_KEY');
    if (!$key || (($_GET['key'] ?? '') !== $key)) {
        http_response_code(403);
        exit("Forbidden");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// --- Parse flags ----------------------------------------------------------
$apply = false;
$confirm_db = '';
if ($IS_CLI) {
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') $apply = true;
        elseif (strpos($arg, '--confirm-db=') === 0) $confirm_db = substr($arg, 13);
    }
} else {
    $apply = isset($_GET['apply']);
    $confirm_db = $_GET['confirm_db'] ?? '';
}
$will_write = $apply && ($confirm_db === $db);

$cutoff_days = (int) VENDOR_CP_INACTIVITY_DAYS;
echo "== CP lifecycle sweep ==\n";
echo "DB: {$db} | inactivity threshold: {$cutoff_days} days | mode: " . ($will_write ? "APPLY (writing)" : "DRY-RUN") . "\n\n";

// --- Find inactive active MEMBERS -----------------------------------------
// Inactive = last login older than cutoff, OR never logged in but the account
// was created before the cutoff. Primaries are excluded entirely.
// 'invited' is included alongside 'active' so a CP who never opened their
// activation mail is eventually cleaned up rather than lingering forever.
$sql = "SELECT u.id, u.email, u.full_name, u.vendor_id, u.last_login_at, u.created_at, v.company_name
          FROM users u
          JOIN vendors v ON v.id = u.vendor_id
         WHERE u.role = 'vendor'
           AND u.contact_role = 'member'
           AND u.contact_status IN ('active', 'invited')
           AND (
                 (u.last_login_at IS NOT NULL AND u.last_login_at < (NOW() - INTERVAL {$cutoff_days} DAY))
              OR (u.last_login_at IS NULL AND u.created_at < (NOW() - INTERVAL {$cutoff_days} DAY))
               )
         ORDER BY u.vendor_id, u.id";
$res = $conn->query($sql);
$targets = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

if (!$targets) {
    echo "No inactive member CPs found. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($targets) . " inactive member CP(s):\n";
foreach ($targets as $t) {
    $last = $t['last_login_at'] ?: ('(never; created ' . $t['created_at'] . ')');
    echo "  - #{$t['id']} {$t['email']} [{$t['company_name']}] last active: {$last}\n";
}
echo "\n";

if (!$will_write) {
    echo "DRY-RUN: no changes made.\n";
    if ($apply && $confirm_db !== $db) {
        echo "NOTE: --apply given but --confirm-db did not match '{$db}'. Refusing to write.\n";
    }
    echo "To apply: add --apply --confirm-db={$db}\n";
    exit(0);
}

// --- Apply ----------------------------------------------------------------
$upd = $conn->prepare("UPDATE users SET contact_status = 'revoked', is_active = 0 WHERE id = ? AND contact_role = 'member'");
$aud = $conn->prepare("INSERT INTO vendor_contact_audit (vendor_id, actor_name, actor_role, target_user_id, target_email, action, details)
                       VALUES (?, 'SYSTEM (cp_lifecycle_sweep)', 'system', ?, ?, 'cp_auto_suspended_inactivity', ?)");
$n = 0;
foreach ($targets as $t) {
    $upd->bind_param("i", $t['id']);
    $upd->execute();
    $details = "Inactive > {$cutoff_days} days (last: " . ($t['last_login_at'] ?: 'never') . ")";
    $aud->bind_param("iiss", $t['vendor_id'], $t['id'], $t['email'], $details);
    $aud->execute();
    $n++;
}
echo "APPLIED: suspended {$n} inactive member CP(s). They can be reactivated by the primary or Procurement.\n";
exit(0);
