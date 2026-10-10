<?php
// includes/header.php

require_once __DIR__ . '/../../includes/branding.php';

// Ensure the session is started so we can read the user's name
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Fetch the user's details if they are logged in
$display_name = isset($_SESSION['name']) ? $_SESSION['name'] : 'Guest';
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : '';

// --- ROLE-BASED ACCESS CONTROL (RBAC) LOGIC ---
$dashboard_link = 'dashboard_pic.php'; // Default fallback
if (in_array($user_role, ['Category Manager', 'Manager'])) {
    $dashboard_link = 'dashboard_mgr.php';
} elseif (in_array($user_role, ['Project Director', 'QS'])) {
    $dashboard_link = 'dashboard_project.php';
} elseif ($user_role === 'STC') {
    $dashboard_link = 'dashboard_stc.php';
} elseif ($user_role === 'Technical Department') {
    $dashboard_link = 'dashboard_tech.php';
} elseif ($user_role === 'Viewer') {
    // Read-only role lands on the Command Center, gated by require_dashboard_view().
    $dashboard_link = 'dashboard_mgr.php';
}

$show_create_pr = in_array($user_role, ['Technical Department', 'Category Manager', 'Manager', 'Admin'], true);
$show_upload_pr = ($user_role === 'Category Manager');
$show_vendor_eval_v2_results = in_array($user_role, ['Category Manager', 'Manager']);
// Read-only Vendor Evaluation results link for external Technical Department
// accounts. Default false; only turned on below (guarded) when the account's
// team is mapped to an in-scope PGroup, so existing Technical Department
// accounts on operational PGroups never see the link. Points at the scoped
// page vendor_eval_results_tech.php, NOT the Manager page above.
$show_vendor_eval_tech = false;
// Eval-only Technical Department accounts get a MINIMAL sidebar (Vendor
// Evaluations only). Flipped on together with $show_vendor_eval_tech below.
$ve_eval_only = false;
$show_create_rfq = in_array($user_role, ['Category Manager', 'Manager', 'PIC']);
$show_rfq_dashboard = !in_array($user_role, ['STC', 'Technical Department']);
$show_rfq_monitoring = in_array($user_role, ['Category Manager', 'Manager', 'Project Director', 'QS']);
$show_analytics = in_array($user_role, ['Category Manager', 'Manager']);
// SLA Tracking has its own flag (wider than $show_analytics) so PIC gets it
// WITHOUT being exposed to the other Analytics pages. PIC's view there is
// read-only and scoped to their own PR lines — see require_sla_access().
// Technical Department is deliberately NOT here: SLA was dropped from that
// role entirely (dashboard column + this page), so they also lose the whole
// Analytics group above, which held nothing else for them.
$show_sla_tracking = in_array($user_role, ['Category Manager', 'Manager', 'PIC']);
$show_sla_approvals = in_array($user_role, ['Category Manager', 'Manager']);
// Cost Savings has its own flag (wider than $show_analytics) so STC gets it
// WITHOUT being exposed to the other Analytics pages (Cost Avoidance, SLA).
$show_cost_savings = in_array($user_role, ['Category Manager', 'Manager', 'STC']);
$show_cost_avoidance = in_array($user_role, ['Category Manager', 'Manager']);
$show_config = in_array($user_role, ['Category Manager', 'Manager']);
$show_maintenance = ($user_role === 'Category Manager');
$show_admin_routing = ($user_role === 'Category Manager');
$show_master_data = in_array($user_role, ['Category Manager', 'Manager', 'PIC']);
$show_sku_dictionary = true; // Accessible to all users
$show_master_vendor_eval = ($user_role === 'Category Manager');
$show_technical_teams = ($user_role === 'Category Manager');
$show_aon = in_array($user_role, ['STC', 'Category Manager', 'Manager']);
// Importing the AON CSVs is a Category Manager / Manager job -- an STC keeps
// the Master AON page but not the import that overwrites it. See
// includes/aon_helper.php; pages/upload_aon.php enforces the same rule.
require_once __DIR__ . '/aon_helper.php';
$show_upload_aon = aon_can_upload($user_role);
// Contract Maintenance: Category Manager, plus a named Facility/Property team
// allowlist (no team_name value distinguishes this team in the users table).
// See includes/contract_maintenance_helper.php for the shared gate function.
require_once __DIR__ . '/contract_maintenance_helper.php';
$show_contract_maintenance = contract_maintenance_can_access($user_role, $_SESSION['username'] ?? '');

// Viewer (read-only) gets a MINIMAL sidebar: Command Center + the three
// Analytics pages it is allowed on, nothing else. Written as an override after
// every flag above (same shape as the $ve_eval_only block below) rather than by
// editing 20 in_array() lists, so a flag added later defaults to hidden here.
//
// Two flags MUST be turned off explicitly because they are not role-allowlists:
// $show_sku_dictionary defaults to true for everyone, and $show_rfq_dashboard is
// an exclusion list that would otherwise let Viewer through.
//
// This only hides links. The gate is ws_enforce_viewer_scope() in
// includes/auth.php — a hidden link is not access control.
if ($user_role === 'Viewer') {
    $show_upload_pr = $show_vendor_eval_v2_results = false;
    $show_create_rfq = $show_rfq_dashboard = $show_rfq_monitoring = false;
    $show_sla_approvals = false;
    $show_config = $show_maintenance = $show_admin_routing = false;
    $show_master_data = $show_sku_dictionary = $show_master_vendor_eval = false;
    $show_technical_teams = false;
    $show_aon = $show_upload_aon = false;
    $show_contract_maintenance = false;

    // Kept on: the four surfaces of the role.
    $show_analytics = false; // group header is driven by the three flags below
    $show_cost_avoidance = true;
    $show_cost_savings = true;
    $show_sla_tracking = true;
}

// Vendor Eval v2: data-driven red dot when POs are waiting for manual question-set
// assignment. Category-Manager-only. Guarded so header.php stays safe on pages that
// include it without a $pdo handle or without auth (upload_pr.php, auto_assign.php);
// fails closed (no dot) on any error, including before the override table exists.
$ve2_show_queue_dot = false;
if ($user_role === 'Category Manager' && isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/vendor_eval_v2_routing.php';
        $ve2_show_queue_dot = ve2_manual_queue_exists($pdo);
    } catch (\Throwable $e) {
        $ve2_show_queue_dot = false;
    }
}

// Only the Form 2 teams (VE2_TECH_EVAL_ONLY_TEAMS) are Vendor-Evaluation-only.
// Every other Technical Department team — General Affairs, MBO, and anything
// added later — keeps its original sidebar: Dashboard (dashboard_tech.php
// worklist), Announcements and SKU Dictionary. Team-name based, no
// PGroup-mapping dependency; the PGroup-scoped DATA on
// vendor_eval_results_tech.php is still resolved separately per team via
// technical_team_pgroups (see vendor_eval_v2_tech_results.php), so a Form 2
// team with no mapping there simply sees an empty results dashboard.
if ($user_role === 'Technical Department') {
    // ve2_tech_is_eval_only_team() is the single shared source of truth for this
    // gate (also used by dashboard_tech.php's redirect) so the two can't drift
    // out of sync again like they did before this file required it here.
    require_once __DIR__ . '/vendor_eval_v2_tech_results.php';
    $show_vendor_eval_tech = ve2_tech_is_eval_only_team($_SESSION['team_name'] ?? '');
}

// Evaluation-only Technical Department accounts use the Vendor Evaluations page
// as their dashboard AND get a minimal sidebar showing ONLY Vendor Evaluations
// (per product decision). Point the Dashboard link straight at the results page
// and turn every other nav flag off; the remaining sections are gated on these
// flags or on $ve_eval_only in the markup below. Existing Technical Department
// accounts (operational PGroups) are unaffected.
if ($show_vendor_eval_tech) {
    $dashboard_link = 'vendor_eval_results_tech.php';
    $ve_eval_only   = true;
    $show_upload_pr = $show_vendor_eval_v2_results = false;
    $show_create_rfq = $show_rfq_dashboard = $show_rfq_monitoring = false;
    $show_analytics = $show_sla_tracking = $show_sla_approvals = false;
    $show_cost_savings = $show_cost_avoidance = false;
    $show_config = $show_maintenance = $show_admin_routing = false;
    $show_master_data = $show_sku_dictionary = $show_master_vendor_eval = false;
    $show_technical_teams = false;
    $show_aon = $show_upload_aon = false;
    $show_contract_maintenance = false;
}

// Pending count for the "Manage SLA Request" button on SLA Tracking
// (pages/sla_report.php). Guarded and fail-closed for the same reason as
// $ve2_show_queue_dot above.
$sla_pending_count = 0;
if ($show_sla_approvals && isset($pdo) && $pdo instanceof PDO) {
    try {
        if ($user_role === 'Category Manager') {
            $sla_pending_count = (int)$pdo->query("SELECT COUNT(*) FROM sla_revision_requests WHERE status = 'pending'")->fetchColumn();
        } else {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM sla_revision_requests WHERE status = 'pending' AND approver_username = ?");
            $stmt->execute([$_SESSION['username'] ?? '']);
            $sla_pending_count = (int)$stmt->fetchColumn();
        }
    } catch (\Throwable $e) {
        $sla_pending_count = 0;
    }
}

// Phase F-3A: GP Project and GP Non-Project are now one unified Workload
// Dashboard (dashboard_mgr.php), so the separate Project sidebar link is gone.
// No get_project_head_groups() call here keeps header.php safe on pages that
// include it without a $pdo handle (upload_pr.php) or without auth.php
// (auto_assign.php) — they never fatal.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saber eProc | Enterprise Procurement Workspace</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <?= vss_favicon_tags() ?>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/css/custom.css">
</head>
<body class="bg-slate-50 text-slate-800 antialiased overflow-hidden">

<div class="flex h-screen w-full">

    <aside id="desktopSidebar" class="w-64 flex-shrink-0 bg-white border-r border-slate-200 flex-col shadow-sm hidden md:flex transition-all duration-300 z-20 overflow-hidden">
        <div class="w-64 flex flex-col h-full">
            
            <div class="h-16 flex items-center px-5 border-b border-slate-100 flex-shrink-0">
                <?= vss_brand_logo(34, 'mr-2.5') ?>
                <div class="flex flex-col">
                    <span class="text-base font-extrabold text-slate-900 tracking-tight leading-none">Saber <span class="text-blue-700">eProc</span></span>
                    <span class="text-[9px] font-bold text-slate-400 tracking-wider uppercase mt-1">Enterprise Procurement</span>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto py-6 px-3 space-y-6">
                <?php if (!$ve_eval_only): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Dashboard</p>
                    <a href="<?= $dashboard_link ?>" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                        Dashboard
                    </a>
                    <?php if ($user_role !== 'Viewer'): ?>
                    <a href="announcement_list.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10l6 6v8a2 2 0 01-2 2z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 4v6h6M8 13h8M8 17h5"></path></svg>
                        Announcements
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($show_create_pr || $show_upload_pr || $show_vendor_eval_v2_results || $show_vendor_eval_tech): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Operations</p>
                    <?php if ($show_create_pr): ?>
                    <a href="pr_create.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span>Create PR Direct</span>
                    </a>
                    <?php endif; ?>

                    <?php if ($show_upload_pr): ?>
                    <a href="upload_pr.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        Upload PRs & AI
                    </a>
                    <?php endif; ?>

                    <?php if ($show_vendor_eval_v2_results): ?>
                    <a href="vendor_eval_v2_results.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4L19 7"></path></svg>
                        <span>Vendor Evaluations</span>
                        <?php if (!empty($ve2_show_queue_dot)): ?>
                        <span class="ml-auto h-2 w-2 rounded-full bg-red-500 flex-shrink-0" title="Purchase orders are waiting for manual question-set assignment"></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>

                    <?php if ($show_vendor_eval_tech): ?>
                    <a href="vendor_eval_results_tech.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4L19 7"></path></svg>
                        <span>Vendor Evaluations</span>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php // Group header renders only when it has at least one link, like every
                      // other group here. Previously it tested $ve_eval_only alone, which
                      // left an empty "Sourcing" heading for any role holding all three
                      // flags false. That never showed before because $show_rfq_dashboard
                      // is an exclusion list (true for nearly every role); the read-only
                      // Viewer is the first role to turn all three off at once. ?>
                <?php if (!$ve_eval_only && ($show_create_rfq || $show_rfq_dashboard || $show_rfq_monitoring)): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Sourcing</p>
                    <?php if ($show_create_rfq): ?>
                    <a href="rfq_create.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                        Create RFQ
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($show_rfq_dashboard): ?>
                    <a href="rfq_dashboard.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        RFQ Dashboard
                    </a>
                    <?php endif; ?>

                    <?php if ($show_rfq_monitoring): ?>
                    <a href="rfq_monitoring.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                        Team RFQ Monitoring
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($show_aon || $show_upload_aon): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">AON</p>
                    <?php if ($show_aon): ?>
                    <a href="master_aon.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                        Master AON
                    </a>
                    <?php endif; ?>
                    <?php if ($show_upload_aon): ?>
                    <a href="upload_aon.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                        Upload AON
                    </a>
                    <?php endif; ?>
                    <?php if ($show_aon): ?>
                    <a href="aon_repeat_alert.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                        Repeat Purchase Alert
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($show_contract_maintenance): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Contracts</p>
                    <a href="contract_maintenance.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        Contract Maintenance
                    </a>
                </div>
                <?php endif; ?>

                <?php if ($show_analytics || $show_cost_savings || $show_sla_tracking): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Analytics</p>
                    <?php if ($show_cost_avoidance): ?>
                    <a href="cost_avoidance.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        Cost Avoidance
                    </a>
                    <?php endif; ?>
                    <?php if ($show_cost_savings): ?>
                    <a href="cost_savings.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a5 5 0 00-10 0v2M5 9h14l-1 11H6L5 9zm5 4h4"></path></svg>
                        Cost Savings
                    </a>
                    <?php endif; ?>
                    <?php if ($show_sla_tracking): ?>
                    <a href="sla_report.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        SLA Tracking
                    </a>
                    <?php endif; ?>
                    <!-- SLA Approvals link removed from the sidebar on purpose: the
                         approval queue is reached from the "Manage SLA Request" button
                         inside the SLA Tracking table card (pages/sla_report.php), so
                         it stays in the context of the rows it acts on. $show_sla_approvals
                         and $sla_pending_count above are still computed — that button's
                         pending badge reads them. -->
                </div>
                <?php endif; ?>

                <?php if ($show_master_data || $show_config || $show_maintenance || $show_sku_dictionary || $show_master_vendor_eval || $show_admin_routing): ?>
                <div>
                    <p class="px-3 text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-2">Configuration</p>
                    <?php if ($show_master_data): ?>
                    <a href="master_data.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"></path></svg>
                        Master Data
                    <?php endif; ?>
                    
                    <?php if ($show_sku_dictionary): ?>
                    <a href="sku_dictionary.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                        SKU Dictionary
                    </a>
                    <?php endif; ?>

                    <?php if ($show_admin_routing): ?>
                    <a href="admin_routing_setup.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                        Admin Routing Setup
                    </a>
                    <?php endif; ?>
                    
                    <?php if ($show_config): ?>
                    <a href="pgroups.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                        P.Group Whitelist
                    </a>
                    <?php endif; ?>

                    <?php if ($show_technical_teams): ?>
                    <a href="technical_teams.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        Technical Teams
                    </a>
                    <?php endif; ?>

                    <?php if ($show_maintenance): ?>
                    <a href="maintenance_settings.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-red-50 hover:text-red-700 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        VSS Maintenance
                    </a>
                    <?php endif; ?>

                    <?php if ($show_master_vendor_eval): ?>
                    <a href="master_vendor_evaluation.php" class="flex items-center px-3 py-2 text-sm font-medium text-slate-600 rounded-lg hover:bg-blue-50 hover:text-blue-800 transition-colors mt-1">
                        <svg class="w-5 h-5 mr-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 8l2 2 4-4"></path></svg>
                        Master Vendor Evaluation
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </nav>
        </div>
    </aside>

    <div class="flex-1 flex flex-col h-screen overflow-hidden min-w-0">
        
        <header class="bg-white h-16 flex items-center justify-between px-4 sm:px-6 shadow-sm border-b border-slate-200 relative z-30 flex-shrink-0">
            <div class="flex items-center">
                <button id="mainMenuBtn" class="text-slate-500 hover:text-blue-700 focus:outline-none transition-colors p-1 -ml-1 rounded-md">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
            </div>

            <div class="relative ml-auto mr-3 flex items-center gap-2">
                <?php if (!in_array($user_role, ['Technical Department', 'Viewer'], true)): // Viewer
                      // tidak punya tujuan di VSS: role VSS-nya 'viewer', yang di
                      // login_action.php justru dirutekan balik ke Workspace ini. ?>
                <a href="return_to_vss.php" class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-slate-600 hover:text-blue-700 bg-white hover:bg-slate-50 rounded-lg border border-slate-200 shadow-sm transition-colors hidden sm:flex">
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
                    Return to VSS
                </a>
                <?php endif; ?>

                <?php if ($user_role !== 'Viewer'): ?>
                <div class="relative">
                    <button type="button" id="announcementBellBtn" class="relative p-2 rounded-full text-slate-500 hover:text-blue-700 hover:bg-slate-50 border border-transparent hover:border-slate-200 transition focus:outline-none">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0a3 3 0 11-6 0m6 0H9"></path></svg>
                        <span id="announcementUnreadBadge" class="hidden absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-red-600 text-white text-[10px] font-bold leading-[18px] text-center">0</span>
                    </button>

                    <div id="announcementDropdown" class="hidden origin-top-right absolute right-0 mt-2 w-80 max-w-[calc(100vw-2rem)] rounded-xl shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 flex flex-col" style="max-height:320px">
                        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
                            <span class="text-sm font-bold text-slate-800">Announcements</span>
                            <a href="announcement_list.php" class="text-xs font-semibold text-blue-700 hover:text-blue-900">View all</a>
                        </div>
                        <div id="announcementDropdownList" class="overflow-y-auto flex-1">
                            <div class="px-4 py-6 text-sm text-slate-500 text-center">Loading announcements...</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="relative inline-block text-left">
                <button type="button" onclick="document.getElementById('userDropdownMenu').classList.toggle('hidden')" class="flex items-center gap-3 p-1 pr-2 rounded-full hover:bg-slate-50 border border-transparent hover:border-slate-200 transition focus:outline-none">
                    <div class="w-9 h-9 rounded-full bg-blue-100 border border-blue-200 flex items-center justify-center text-blue-800 font-bold text-sm shadow-sm">
                        <?php 
                            $name = $_SESSION['name'] ?? 'User';
                            $words = explode(' ', $name);
                            $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                            echo $initials;
                        ?>
                    </div>
                    <div class="hidden md:flex flex-col text-left mr-1">
                        <span class="text-sm font-bold text-slate-700 leading-none"><?= htmlspecialchars($name) ?></span>
                        <span class="text-[11px] text-slate-500 font-medium mt-1 leading-none"><?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
                    </div>
                    <svg class="h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                </button>
            
                <div id="userDropdownMenu" class="hidden origin-top-right absolute right-0 mt-2 w-48 rounded-xl shadow-lg bg-white ring-1 ring-black ring-opacity-5 z-50 overflow-hidden transform transition-all">
                    <div class="py-1" role="menu">
                        <a href="profile.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-800 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                            My Profile
                        </a>
                        
                        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'Category Manager'): ?>
                            <a href="user_management.php" class="flex items-center gap-2 px-4 py-2 text-sm text-gray-700 hover:bg-blue-50 hover:text-blue-800 transition">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                Manage Users
                            </a>
                        <?php endif; ?>
                        
                        <div class="border-t border-gray-100 my-1"></div>
                        
                        <a href="logout.php" class="flex items-center gap-2 px-4 py-2 text-sm text-red-600 hover:bg-red-50 font-bold transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                            Logout
                        </a>
                    </div>
                </div>
            </div>
            
            <script>
                function escapeAnnouncementText(value) {
                    return $('<div>').text(value || '').html();
                }

                function confidentialityClass(raw) {
                    switch (raw) {
                        case 'GP_ONLY':      return 'bg-red-100 text-red-700';
                        case 'INTERNAL_KLG': return 'bg-amber-100 text-amber-700';
                        case 'EXTERNAL':     return 'bg-green-100 text-green-700';
                        default:             return 'bg-slate-100 text-slate-700';
                    }
                }

                function renderAnnouncementDropdown(data) {
                    const badge = $('#announcementUnreadBadge');
                    const list = $('#announcementDropdownList');
                    const count = Number(data.count || 0);

                    if (count > 0) {
                        badge.text(count > 99 ? '99+' : count).removeClass('hidden');
                    } else {
                        badge.addClass('hidden').text('0');
                    }

                    if (!data.items || data.items.length === 0) {
                        list.html('<div class="px-4 py-6 text-sm text-slate-500 text-center">No unread announcements.</div>');
                        return;
                    }

                    const html = data.items.map(function(item) {
                        const confClass = confidentialityClass(item.confidentiality_raw);
                        const urgBadge  = item.urgency === 'URGENT'
                            ? `<span class="text-[10px] font-bold px-2 py-0.5 rounded bg-red-100 text-red-700">Urgent</span>`
                            : '';
                        return `
                            <a href="announcement_detail.php?id=${encodeURIComponent(item.id)}" class="block px-4 py-3 border-b border-slate-100 hover:bg-blue-50 transition">
                                <div class="flex flex-wrap gap-1 mb-1">
                                    <span class="text-[10px] font-bold px-2 py-0.5 rounded ${confClass}">${escapeAnnouncementText(item.confidentiality)}</span>
                                    ${urgBadge}
                                </div>
                                <div class="text-sm font-bold text-slate-800 line-clamp-1">${escapeAnnouncementText(item.title)}</div>
                                <div class="text-xs text-slate-500 mt-1 line-clamp-2">${escapeAnnouncementText(item.summary)}</div>
                                <div class="text-[11px] text-slate-400 mt-2">${escapeAnnouncementText(item.time_ago)}</div>
                            </a>
                        `;
                    }).join('');

                    list.html(html);
                }

                function fetchAnnouncementNotifications() {
                    $.ajax({
                        url: 'announcement_notifications.php',
                        dataType: 'json',
                        cache: false
                    })
                    .done(renderAnnouncementDropdown)
                    .fail(function() {
                        $('#announcementDropdownList').html('<div class="px-4 py-6 text-sm text-red-500 text-center">Unable to load announcements.</div>');
                    });
                }

                $('#announcementBellBtn').on('click', function(e) {
                    e.stopPropagation();
                    $('#announcementDropdown').toggleClass('hidden');
                    $('#userDropdownMenu').addClass('hidden');
                });

                window.addEventListener('click', function(e) {
                    const menu = document.getElementById('userDropdownMenu');
                    if (menu && !menu.contains(e.target) && !e.target.closest('button')) {
                        menu.classList.add('hidden');
                    }

                    const announcementMenu = document.getElementById('announcementDropdown');
                    const announcementBtn = document.getElementById('announcementBellBtn');
                    if (announcementMenu && announcementBtn && !announcementMenu.contains(e.target) && !announcementBtn.contains(e.target)) {
                        announcementMenu.classList.add('hidden');
                    }
                });

                <?php if ($user_role !== 'Viewer'): // lonceng disembunyikan untuk Viewer,
                      // dan announcement_notifications.php memang di luar allowlist-nya,
                      // jadi polling ini hanya akan menghasilkan 403 tiap 45 detik. ?>
                fetchAnnouncementNotifications();
                setInterval(fetchAnnouncementNotifications, 45000);
                <?php endif; ?>
            </script>
        </header>

        <main class="flex-1 overflow-y-auto p-4 sm:p-6 md:p-8 bg-slate-50">
            <div class="bg-white shadow-sm rounded-xl border border-slate-200 p-4 sm:p-6 min-h-[calc(100vh-10rem)]">
