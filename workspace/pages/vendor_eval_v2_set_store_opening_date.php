<?php
// workspace/pages/vendor_eval_v2_set_store_opening_date.php
//
// Store Opening Date (write endpoint) — Phase 2 of the K2 New Store flow.
//
// Store Opening Date is tracked per SITE (prs.site_allocation), not per PO.
// Scope: sites under a PGroup matching [A-Z]97 or A77, filtered to
// prs.is_new_store = 1 (see project_pgroups). Manual entry only — no bulk
// import, no automatic Form 2 trigger yet (that is a later phase).
//
// Access (two-tier, both enforced server-side):
//   - Manager / Category Manager: any in-scope site.
//   - project_pgroup_officers (role PIC) for group 1 (PKR) or 2 (PKP): ONLY
//     sites owned by their own group. A PKR officer touching a PKP-owned site
//     (or vice versa) is rejected — see get_store_opening_officer_groups().
//
// Write: upsert into vendor_eval_store_opening_dates (one row per site).
// No WA/email, no external API. PRG: always redirects back, never renders HTML.

require_once '../includes/auth.php';
require_login();
require_once '../config/database.php'; // provides $pdo (PDO)

/** Redirect back to the results page (Store Opening Date tab) with a one-shot status param, then stop. */
function sod_redirect(string $key, string $value): void
{
    header('Location: vendor_eval_v2_results.php?' . $key . '=' . urlencode($value) . '#store-opening-tab', true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sod_redirect('store_save_error', 'method');
}

// ── Access control: same base + additive grant as the page itself ───────────
$is_manager = in_array($_SESSION['role'] ?? '', ['Manager', 'Category Manager'], true);
$officer_groups = $is_manager ? [] : get_store_opening_officer_groups($pdo, $_SESSION);
if (!$is_manager && empty($officer_groups)) {
    sod_redirect('store_save_error', 'access');
}

$site_allocation    = trim((string)($_POST['site_allocation'] ?? ''));
$store_opening_date = trim((string)($_POST['store_opening_date'] ?? ''));

if ($site_allocation === '' || $store_opening_date === '') {
    sod_redirect('store_save_error', 'input');
}

// Strict Y-m-d validation (HTML date input format); reject anything that
// doesn't round-trip cleanly (e.g. 2026-02-31 silently rolling to March 3).
$d = DateTime::createFromFormat('Y-m-d', $store_opening_date);
if (!$d || $d->format('Y-m-d') !== $store_opening_date) {
    sod_redirect('store_save_error', 'input');
}

try {
    // 1. Site must actually be an in-scope new-store site (is_new_store=1,
    //    ticket_type contains "new store", p_group matches [A-Z]97 or A77,
    //    PGroup active). Kept in lockstep with the display filter in
    //    vendor_eval_v2_results.php so a listed site always saves and an
    //    out-of-scope site never does. Resolve its owning group from
    //    project_pgroups rather than trusting client input.
    $stmt = $pdo->prepare("
        SELECT DISTINCT pp.project_group_number
        FROM prs
        JOIN project_pgroups pp ON pp.p_group = prs.p_group AND pp.status = 'ACTIVE'
        WHERE prs.site_allocation = ?
          AND prs.is_new_store = 1
          AND LOWER(IFNULL(prs.ticket_type, '')) LIKE '%new store%'
          AND (prs.p_group REGEXP '^[A-Z]97$' OR prs.p_group = 'A77')
    ");
    $stmt->execute([$site_allocation]);
    $groups = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (empty($groups)) {
        sod_redirect('store_save_error', 'notfound');
    }
    if (count($groups) > 1) {
        // Data-integrity guard: a site is expected to belong to exactly one
        // owning group. Fail closed rather than guess.
        error_log('[vendor_eval_v2_set_store_opening_date] site "' . $site_allocation . '" resolved to multiple groups: ' . implode(',', $groups));
        sod_redirect('store_save_error', 'db');
    }
    $owning_group = $groups[0];

    // 2. Officers may only write within their own group.
    if (!$is_manager && !in_array($owning_group, $officer_groups, true)) {
        sod_redirect('store_save_error', 'scope');
    }

    // 3. Upsert.
    $assigned_by = (string)($_SESSION['name'] ?? $_SESSION['username'] ?? 'unknown');
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO vendor_eval_store_opening_dates
                (site_allocation, store_opening_date, owning_project_group_number, assigned_by, assigned_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                store_opening_date          = VALUES(store_opening_date),
                owning_project_group_number = VALUES(owning_project_group_number),
                assigned_by                 = VALUES(assigned_by),
                assigned_at                 = NOW()
        ");
        $ins->execute([$site_allocation, $store_opening_date, $owning_group, $assigned_by]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
} catch (PDOException $e) {
    error_log('[vendor_eval_v2_set_store_opening_date] ' . $e->getMessage());
    sod_redirect('store_save_error', 'db');
}

sod_redirect('store_saved', $site_allocation);
