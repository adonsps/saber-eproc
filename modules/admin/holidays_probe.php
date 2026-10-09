<?php
// modules/admin/holidays_probe.php
// FIX: Original file leaked server path, PHP timestamp, and confirmed file execution to any visitor.
// Replaced with a silent redirect. Delete this file entirely from production when convenient.
session_start();
$path_to_root = "../../";
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}
header("Location: dashboard.php");
exit();
?>