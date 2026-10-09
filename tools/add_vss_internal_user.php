<?php
// tools/add_vss_internal_user.php
//
// Create ONE internal (non-vendor) VSS login in u894129685_vmsportal.users.
//
// Internal accounts — vendor_mgt, sourcing, requestor, project, admin, ... — have
// no self-service path: register_action.php hardcodes role='vendor', and
// direct_registration_action.php creates vendors. Every existing internal account
// was inserted by hand or by a one-off setup_*_users.php script. This tool is that
// insert, with the identity gate and the dry-run the ad-hoc ones lacked.
//
// What it does NOT do, on purpose:
//   - It never touches u894129685_workspace.users. That table is the ONLY thing
//     granting Workspace access (pages/sso_vss.php matches VSS email -> Workspace
//     row), so leaving it alone is what keeps the account VSS-only. Someone who
//     also needs Workspace goes through workspace/pages/user_management.php, which
//     additionally syncs the VSS role.
//   - It never creates a vendors row and never sets users.vendor_id. An internal
//     user with a vendor_id would be dragged through the vendor gates in
//     login_action.php (vendor_is_active, contact_status) and the vendor onboarding
//     redirect.
//   - It sends nothing. No mail, no WA. See the password note below.
//
// Password: the account is seeded with the shared default (Vendor123). That is not
// laziness — login_action.php checks the default explicitly, and on first login
// mints a reset token and redirects into reset_password.php, forcing a change. So
// the real password is set by the user, and no secret has to be transmitted.
//
// Usage:
//   php tools/add_vss_internal_user.php --email=A --name="B" --role=vendor_mgt
//   php tools/add_vss_internal_user.php --email=A --name="B" --role=vendor_mgt \
//       --apply --confirm-db=DB --confirm-host=HOST [--password=STR]
//
//   (default)            DRY-RUN: SELECT only, reports what it WOULD write.
//   --apply              DB WRITE mode. Requires --confirm-db and --confirm-host.
//   --confirm-db=DB      Must equal DATABASE() or apply aborts.
//   --confirm-host=HOST  Must equal @@hostname or apply aborts. Local and prod
//                        share the DB name u894129685_vmsportal, so @@hostname is
//                        the real environment discriminator — pin it deliberately.
//                        Run the dry-run first; it prints the value to pass.
//   --password=STR       Override the seeded password (default: Vendor123).
//                        Overriding it DISABLES the forced-change flow above.
//
// Notes:
//   - CLI only. SELECT-only unless --apply.
//   - users.email is UNIQUE; an existing address is reported and skipped, never
//     updated. Re-pointing a live login is not this tool's job.
//   - Back up vmsportal.users before --apply.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("CLI only.\n");
}

$argv_in = $argv ?? [];
$opt_apply = in_array('--apply', $argv_in, true);
$confirm_db = $confirm_host = null;
$email = $name = null;
$role = null;
$password = 'Vendor123';

foreach ($argv_in as $a) {
    if (preg_match('/^--confirm-db=(.+)$/', $a, $m))       $confirm_db   = $m[1];
    elseif (preg_match('/^--confirm-host=(.+)$/', $a, $m)) $confirm_host = $m[1];
    elseif (preg_match('/^--email=(.+)$/', $a, $m))        $email        = trim($m[1]);
    elseif (preg_match('/^--name=(.+)$/', $a, $m))         $name         = trim($m[1]);
    elseif (preg_match('/^--role=(.+)$/', $a, $m))         $role         = trim($m[1]);
    elseif (preg_match('/^--password=(.+)$/', $a, $m))     $password     = $m[1];
}

// The internal roles login_action.php actually routes after a successful login,
// plus the ones present in the users table. 'vendor' is deliberately absent: a
// vendor needs a vendors row and a vendor_id, which is a different job entirely.
$allowed_roles = ['vendor_mgt', 'vendormgt_head', 'sourcing', 'requestor', 'project', 'admin', 'viewer'];

if (!$email || !$name || !$role) {
    exit("\nUsage: php tools/add_vss_internal_user.php --email=A --name=\"B\" --role=R [--apply --confirm-db=DB --confirm-host=HOST]\n"
       . "Roles: " . implode(', ', $allowed_roles) . "\n\n");
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("\nABORT: '{$email}' is not a valid email address.\n\n");
}
if (!in_array($role, $allowed_roles, true)) {
    exit("\nABORT: role '{$role}' is not an internal VSS role.\n"
       . "Allowed: " . implode(', ', $allowed_roles) . "\n"
       . "(To create a VENDOR, use the Direct Registration flow — a vendor needs a vendors row.)\n\n");
}
if ($opt_apply) {
    if (!$confirm_db)   exit("\nSAFETY ABORT: --apply requires --confirm-db=DATABASE_NAME.\n\n");
    if (!$confirm_host) exit("\nSAFETY ABORT: --apply requires --confirm-host=HOSTNAME.\n\n");
}

$root = dirname(__DIR__);
require $root . '/config.php'; // provides $conn (mysqli -> vmsportal)
if ($conn->connect_errno) exit("DB connect failed: " . $conn->connect_error . "\n");
$conn->set_charset('utf8mb4');

$cur_db   = (string)$conn->query('SELECT DATABASE()')->fetch_row()[0];
$cur_host = (string)$conn->query('SELECT @@hostname')->fetch_row()[0];
$cur_user = (string)$conn->query('SELECT USER()')->fetch_row()[0];
$cur_ver  = (string)$conn->query('SELECT VERSION()')->fetch_row()[0];

$mode = $opt_apply ? 'APPLY / DB WRITE' : 'DRY-RUN (no writes)';
echo "\n=======================================================================\n";
echo " Add internal VSS user ({$mode})\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n";
echo " DATABASE() : {$cur_db}\n";
echo " USER()     : {$cur_user}\n";
echo " VERSION()  : {$cur_ver}\n";
echo " @@hostname : {$cur_host}\n";
echo "-----------------------------------------------------------------------\n";

// The whole point of this tool is that it is pointed at vmsportal, not workspace.
if (stripos($cur_db, 'vmsportal') === false) {
    exit("\nSAFETY ABORT: DATABASE() '{$cur_db}' is not the VSS (vmsportal) database.\n"
       . "Internal VSS logins live in vmsportal.users. Nothing written.\n\n");
}

if ($opt_apply) {
    $errs = [];
    if ($cur_db !== $confirm_db)     $errs[] = "DATABASE() '{$cur_db}' != --confirm-db '{$confirm_db}'";
    if ($cur_host !== $confirm_host) $errs[] = "@@hostname '{$cur_host}' != --confirm-host '{$confirm_host}'";
    if ($errs) exit("\nSAFETY ABORT: --apply target verification FAILED. No rows written.\n  - "
                  . implode("\n  - ", $errs) . "\n\n");
    echo "[--apply] target verified.\n";
}

// users.email is UNIQUE. Report and stop rather than letting the insert throw, and
// never UPDATE — silently re-pointing an existing login is how the wrong person
// ends up with someone else's access.
$stmt = $conn->prepare("SELECT id, email, full_name, role, is_active, vendor_id FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param('s', $email);
$stmt->execute();
$existing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if ($existing) {
    echo "\nSKIP: {$email} already exists in {$cur_db}.\n";
    echo "  id={$existing['id']}  role={$existing['role']}  is_active={$existing['is_active']}"
       . "  vendor_id=" . ($existing['vendor_id'] ?? 'NULL') . "  name={$existing['full_name']}\n";
    echo "\nNothing written. Change a role or reactivate by hand, deliberately.\n\n";
    exit(0);
}

echo "\nWould create:\n";
echo "  email           : {$email}\n";
echo "  full_name       : {$name}\n";
echo "  role            : {$role}\n";
echo "  is_active       : 1\n";
echo "  vendor_id       : NULL      (internal user, not a vendor)\n";
echo "  contact_role    : NULL\n";
echo "  contact_status  : active\n";
echo "  password        : " . ($password === 'Vendor123'
        ? "shared default -> forced reset on first login"
        : "CUSTOM -> forced-reset flow DISABLED") . "\n";
echo "  workspace access: NONE (u894129685_workspace.users untouched)\n";

if (!$opt_apply) {
    echo "\nDRY-RUN complete. No rows written.\n";
    echo "To write, back up vmsportal.users first, then re-run with:\n";
    echo "  --apply --confirm-db={$cur_db} --confirm-host={$cur_host}\n\n";
    exit(0);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
$ins = $conn->prepare(
    "INSERT INTO users (email, full_name, password_hash, role, is_active,
                        is_test_account, is_migrated, vendor_id, contact_role,
                        contact_status, verified_at, created_at)
     VALUES (?, ?, ?, ?, 1, 0, 0, NULL, NULL, 'active', NOW(), NOW())"
);
$ins->bind_param('ssss', $email, $name, $hash, $role);

if (!$ins->execute()) {
    exit("\nINSERT FAILED: " . $ins->error . "\nNothing written.\n\n");
}
$new_id = $conn->insert_id;
$ins->close();

// Read the row back rather than trusting the insert — the hash is deliberately not
// selected, so nothing credential-shaped reaches stdout or a CI log.
$stmt = $conn->prepare(
    "SELECT id, email, full_name, role, is_active, vendor_id, contact_status, created_at
     FROM users WHERE id = ?"
);
$stmt->bind_param('i', $new_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo "\nCREATED id={$row['id']}\n";
foreach ($row as $k => $v) {
    printf("  %-15s : %s\n", $k, $v === null ? 'NULL' : $v);
}
echo "\nNext: have them log in at the VSS login page with the shared default.\n";
echo "login_action.php will detect it and force a password reset. Do not send the\n";
echo "password through this tool — it sends nothing.\n\n";
