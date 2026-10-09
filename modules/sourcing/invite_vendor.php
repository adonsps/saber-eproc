<?php
// modules/sourcing/invite_vendor.php
// Thin wrapper — the full "Invite Vendor" page lives in /modules/shared/invite_vendor_page.php
// and is reused by both the sourcing and admin modules. Here we only set the
// role-specific config, then hand off to the shared implementation.
session_start();
$path_to_root         = "../../";
$invite_required_role = 'sourcing';
$invite_dashboard_url = 'modules/sourcing/dashboard.php';
require $path_to_root . 'modules/shared/invite_vendor_page.php';
