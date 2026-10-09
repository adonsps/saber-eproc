<?php
// workspace/index.php
session_start();

// 1. If the user is NOT logged in, send them to the login page
if (!isset($_SESSION['username'])) {
    header("Location: pages/login.php");
    exit;
}

// 2. If the user IS logged in, check their role
$role = $_SESSION['role'] ?? '';

// 3. Route to the correct dashboard based on role
if ($role === 'Category Manager' || $role === 'Manager') {
    header("Location: pages/dashboard_mgr.php");
    exit;
} elseif ($role === 'Project Director' || $role === 'QS') {
    header("Location: pages/dashboard_project.php");
    exit;
} else {
    // Default fallback for 'PIC', 'Procurement PIC', or any other standard role
    header("Location: pages/dashboard_pic.php");
    exit;
}
?>