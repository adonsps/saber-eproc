<?php
// modules/admin/migrated_reminder.php
// Thin wrapper — see /modules/shared/migrated_reminder_page.php.
session_start();
$path_to_root           = "../../";
$reminder_required_role = 'admin';
$reminder_dashboard_url = 'modules/admin/dashboard.php';
require $path_to_root . 'modules/shared/migrated_reminder_page.php';
