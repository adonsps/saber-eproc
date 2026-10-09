<?php
// modules/vendormgt/invite_vendor.php
// Thin wrapper — the full "Invite Vendor" page lives in /modules/shared/invite_vendor_page.php
// and is reused by the sourcing, admin and vendormgt modules. Here we only set the
// role-specific config, then hand off to the shared implementation.
session_start();
$path_to_root         = "../../";
$invite_required_role = ['vendor_mgt', 'vendormgt_head']; // both VM and VM Head may invite
$invite_dashboard_url = 'modules/vendormgt/dashboard.php';
require $path_to_root . 'modules/shared/invite_vendor_page.php';
