<?php
// modules/sourcing/exit_direct_registration.php
// Restores the staff user's own session after an impersonation session. Two
// flows land here:
//   - Direct Registration (direct_registration_action.php / _resume.php)
//   - Staff profile edit   (modules/shared/edit_vendor_profile.php)
// Only works if an impersonation is actually in progress, so it can't be used
// to forge a session switch on its own.
session_start();

if (empty($_SESSION['impersonator'])) {
    header("Location: ../../login.php");
    exit();
}

$impersonator = $_SESSION['impersonator'];

$_SESSION['user_id']       = $impersonator['user_id'];
$_SESSION['role']          = $impersonator['role'];
$_SESSION['user_name']     = $impersonator['user_name'];
$_SESSION['email']         = $impersonator['email'];
$_SESSION['last_activity'] = time();

// Where to go back to. The staff-edit flow records the page it came from;
// Direct Registration has always just returned to the Sourcing dashboard.
// Anything role-specific must be resolved BEFORE the impersonator is unset.
$role      = $impersonator['role'] ?? '';
$return_to = (string) ($impersonator['return_to'] ?? '');
if ($return_to !== '' && !preg_match('#^(https?:)?//#i', $return_to)) {
    $target = '../../' . ltrim($return_to, '/');
} elseif (in_array($role, ['vendor_mgt', 'vendormgt_head'], true)) {
    $target = '../vendormgt/dashboard.php';
} else {
    $target = 'dashboard.php';
}

// contact_role only ever means something for a vendor session; leaving
// 'primary' behind on a Sourcing/VM session would be misleading state.
unset(
    $_SESSION['impersonator'],
    $_SESSION['vendor_id'],
    $_SESSION['company_name'],
    $_SESSION['status'],
    $_SESSION['contact_role']
);

$msg = $_GET['msg'] ?? '';
if ($msg !== '') {
    $target .= (strpos($target, '?') === false ? '?' : '&') . "msg=" . urlencode($msg);
}

header("Location: " . $target);
exit();
