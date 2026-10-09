<?php
// modules/vendormgt/migrated_reminder.php
// Thin wrapper — the full "Reminder Vendor Migrasi" page lives in
// /modules/shared/migrated_reminder_page.php and is reused by the vendormgt and
// admin modules. Here we only set the role-specific config.
session_start();
$path_to_root           = "../../";
$reminder_required_role = ['vendor_mgt', 'vendormgt_head'];
$reminder_dashboard_url = 'modules/vendormgt/dashboard.php';
require $path_to_root . 'modules/shared/migrated_reminder_page.php';
