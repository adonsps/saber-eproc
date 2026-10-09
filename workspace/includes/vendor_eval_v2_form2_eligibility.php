<?php
// workspace/includes/vendor_eval_v2_form2_eligibility.php
//
// Form 2 (Project Retail — New Store) eligibility gate.
//
// PURE LIBRARY: defines functions/constants only. No DB connection, no auth,
// no session, no output, no side effects — safe to require from the public
// no-login pages exactly like vendor_eval_v2_routing.php.
//
// Store Opening Date is tracked per SITE (prs.site_allocation), not per PO —
// one PO can have many sites and one site can have many POs. A PO becomes
// Form 2 eligible when ALL of the following hold:
//   1. p_group matches [A-Z]97 or equals 'A77' (see project_pgroups; today
//      this covers PKR/PKP project_group_number 1/2 only — see
//      ve2_form2_is_in_scope_pgroup()).
//   2. is_new_store = 1 on EVERY prs line item for the PO. A PO with mixed
//      New Store / Operational line items is a data anomaly, not something
//      to silently resolve one way or the other — it is flagged (reason
//      'mixed_new_store_line_items') and treated as ineligible.
//   3. EVERY distinct site_allocation on the PO has a Store Opening Date in
//      vendor_eval_store_opening_dates that has arrived (date <= today,
//      Asia/Jakarta). For multi-site POs this is equivalent to requiring
//      MAX(site dates) <= today: if any one site hasn't opened yet, the PO
//      isn't complete.

/** Pattern check — NOT a hardcoded PGroup list, so new *97 codes stay covered. */
function ve2_form2_is_in_scope_pgroup(string $p_group): bool
{
    $p_group = trim($p_group);
    return $p_group === 'A77' || (bool)preg_match('/^[A-Z]97$/', $p_group);
}

/**
 * Full eligibility detail for one PO. Always returns the same shape so
 * callers can inspect *why* a PO isn't eligible (for messaging / diagnostics)
 * rather than just a bool.
 *
 * reason codes (when eligible=false):
 *   no_prs_rows                  — PO has no prs rows at all.
 *   pgroup_not_in_scope          — PO's p_group(s) don't match Form 2 scope.
 *   mixed_pgroup_scope           — PO spans more than one p_group and they
 *                                   disagree on scope (flagged, not assumed).
 *   mixed_new_store_line_items   — PO's line items disagree on is_new_store
 *                                   (flagged, not assumed).
 *   not_new_store                — is_new_store is 0/NULL for the PO.
 *   no_site_allocation           — PO has no site_allocation on any line.
 *   site_missing_opening_date    — at least one site has no row in
 *                                   vendor_eval_store_opening_dates yet.
 *   site_opening_date_not_arrived— every site has a date, but at least one
 *                                   is still in the future.
 */
function ve2_form2_po_eligibility(PDO $pdo, string $po_number): array
{
    $result = [
        'po_number'         => $po_number,
        'in_scope_pgroup'   => false,
        'p_groups'          => [],
        'is_new_store'      => null,
        'mixed_new_store'   => false,
        'sites'             => [],
        'max_open_date'     => null,
        'all_sites_dated'   => false,
        'all_sites_arrived' => false,
        'eligible'          => false,
        'reason'            => 'unknown',
    ];

    // 1. p_group scope (defensive: a PO is expected to have exactly one
    //    p_group, but every distinct value present is checked).
    $stmt = $pdo->prepare("
        SELECT DISTINCT p_group FROM prs
         WHERE po_number = ? AND p_group IS NOT NULL AND p_group != ''
    ");
    $stmt->execute([$po_number]);
    $p_groups = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $result['p_groups'] = $p_groups;

    if (empty($p_groups)) {
        $result['reason'] = 'no_prs_rows';
        return $result;
    }

    $scope_flags = array_map('ve2_form2_is_in_scope_pgroup', $p_groups);
    if (!in_array(true, $scope_flags, true)) {
        $result['reason'] = 'pgroup_not_in_scope';
        return $result;
    }
    if (in_array(false, $scope_flags, true)) {
        // Some p_groups in scope, some not — a PO isn't expected to span
        // p_groups with different scope status. Flag rather than guess.
        $result['reason'] = 'mixed_pgroup_scope';
        return $result;
    }
    $result['in_scope_pgroup'] = true;

    // 2. is_new_store must be 1 on every line item.
    $stmt = $pdo->prepare("SELECT DISTINCT is_new_store FROM prs WHERE po_number = ?");
    $stmt->execute([$po_number]);
    $raw_flags = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $norm_flags = array_map(fn($v) => $v === null ? null : (int)$v, $raw_flags);
    $distinct_flags = array_values(array_unique($norm_flags, SORT_REGULAR));

    if (count($distinct_flags) > 1) {
        $result['mixed_new_store'] = true;
        $result['reason'] = 'mixed_new_store_line_items';
        return $result;
    }

    $only_flag = $distinct_flags[0] ?? null;
    $result['is_new_store'] = ($only_flag === null) ? null : (bool)$only_flag;

    if ($result['is_new_store'] !== true) {
        $result['reason'] = 'not_new_store';
        return $result;
    }

    // 3. Every distinct site on the PO must have an arrived Store Opening Date.
    $stmt = $pdo->prepare("
        SELECT DISTINCT site_allocation FROM prs
         WHERE po_number = ? AND site_allocation IS NOT NULL AND site_allocation != ''
    ");
    $stmt->execute([$po_number]);
    $sites = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($sites)) {
        $result['reason'] = 'no_site_allocation';
        return $result;
    }

    $ph = implode(',', array_fill(0, count($sites), '?'));
    $dstmt = $pdo->prepare("
        SELECT site_allocation, store_opening_date
          FROM vendor_eval_store_opening_dates
         WHERE site_allocation IN ($ph)
    ");
    $dstmt->execute($sites);
    $dated_by_site = [];
    foreach ($dstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dated_by_site[$r['site_allocation']] = $r['store_opening_date'];
    }

    $today = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    $all_dated   = true;
    $all_arrived = true;
    $max_date    = null;

    foreach ($sites as $site) {
        $date       = $dated_by_site[$site] ?? null;
        $has_date   = $date !== null;
        $arrived    = $has_date && $date <= $today;
        $result['sites'][$site] = ['date' => $date, 'has_date' => $has_date, 'arrived' => $arrived];
        if (!$has_date) { $all_dated = false; }
        if (!$arrived)  { $all_arrived = false; }
        if ($has_date && ($max_date === null || $date > $max_date)) { $max_date = $date; }
    }

    $result['all_sites_dated']   = $all_dated;
    $result['all_sites_arrived'] = $all_arrived;
    $result['max_open_date']     = $max_date; // MAX (latest) of the site dates

    if (!$all_dated) {
        $result['reason'] = 'site_missing_opening_date';
        return $result;
    }
    if (!$all_arrived) {
        // Equivalent to requiring MAX(site dates) <= today: if any single site's
        // date is still in the future, that is necessarily also the max.
        $result['reason'] = 'site_opening_date_not_arrived';
        return $result;
    }

    $result['eligible'] = true;
    $result['reason']   = 'eligible';
    return $result;
}

/**
 * Bulk equivalent of ve2_form2_po_eligibility() — same rules, same return
 * shape per PO, but 3 queries total instead of ~4 PER PO. Purely additive:
 * does not change ve2_form2_po_eligibility() or any existing caller. Written
 * for callers that need eligibility for many POs at once (e.g. a dashboard's
 * "never started" list) where the N+1 pattern of calling the single-PO
 * function in a loop was measured at ~1s for a few hundred candidates.
 * Returns [po_number => eligibility_array] (same shape as the single-PO
 * function, including 'reason'). Read-only, fails closed to [] on error.
 */
function ve2_form2_po_eligibility_bulk(PDO $pdo, array $po_numbers): array
{
    $po_numbers = array_values(array_unique(array_filter(
        array_map(static fn($p) => trim((string)$p), $po_numbers),
        static fn($p) => $p !== ''
    )));
    if (empty($po_numbers)) {
        return [];
    }

    $shell = static function (string $po): array {
        return [
            'po_number' => $po, 'in_scope_pgroup' => false, 'p_groups' => [],
            'is_new_store' => null, 'mixed_new_store' => false, 'sites' => [],
            'max_open_date' => null, 'all_sites_dated' => false, 'all_sites_arrived' => false,
            'eligible' => false, 'reason' => 'unknown',
        ];
    };

    try {
        $ph = implode(',', array_fill(0, count($po_numbers), '?'));
        $stmt = $pdo->prepare("
            SELECT po_number, p_group, is_new_store, site_allocation
              FROM prs
             WHERE po_number IN ($ph)
        ");
        $stmt->execute($po_numbers);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }

    $by_po = [];
    $all_sites = [];
    foreach ($rows as $r) {
        $po = (string)$r['po_number'];
        $by_po[$po]['p_groups'][] = $r['p_group'];
        $by_po[$po]['new_store'][] = $r['is_new_store'];
        if ($r['site_allocation'] !== null && $r['site_allocation'] !== '') {
            $by_po[$po]['sites'][$r['site_allocation']] = true;
            $all_sites[$r['site_allocation']] = true;
        }
    }

    $dated_by_site = [];
    if (!empty($all_sites)) {
        $sites = array_keys($all_sites);
        $ph2 = implode(',', array_fill(0, count($sites), '?'));
        $dstmt = $pdo->prepare("
            SELECT site_allocation, store_opening_date
              FROM vendor_eval_store_opening_dates
             WHERE site_allocation IN ($ph2)
        ");
        $dstmt->execute($sites);
        foreach ($dstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $dated_by_site[$r['site_allocation']] = $r['store_opening_date'];
        }
    }

    $today = (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');

    $out = [];
    foreach ($po_numbers as $po) {
        $result = $shell($po);
        $data = $by_po[$po] ?? null;
        if ($data === null) {
            $result['reason'] = 'no_prs_rows';
            $out[$po] = $result;
            continue;
        }

        // 1. p_group scope.
        $p_groups = array_values(array_unique(array_filter(
            array_map(static fn($p) => trim((string)$p), $data['p_groups']),
            static fn($p) => $p !== ''
        )));
        $result['p_groups'] = $p_groups;
        if (empty($p_groups)) {
            $result['reason'] = 'no_prs_rows';
            $out[$po] = $result;
            continue;
        }
        $scope_flags = array_map('ve2_form2_is_in_scope_pgroup', $p_groups);
        if (!in_array(true, $scope_flags, true)) {
            $result['reason'] = 'pgroup_not_in_scope';
            $out[$po] = $result;
            continue;
        }
        if (in_array(false, $scope_flags, true)) {
            $result['reason'] = 'mixed_pgroup_scope';
            $out[$po] = $result;
            continue;
        }
        $result['in_scope_pgroup'] = true;

        // 2. is_new_store on every line item.
        $norm_flags = array_map(static fn($v) => $v === null ? null : (int)$v, $data['new_store']);
        $distinct_flags = array_values(array_unique($norm_flags, SORT_REGULAR));
        if (count($distinct_flags) > 1) {
            $result['mixed_new_store'] = true;
            $result['reason'] = 'mixed_new_store_line_items';
            $out[$po] = $result;
            continue;
        }
        $only_flag = $distinct_flags[0] ?? null;
        $result['is_new_store'] = ($only_flag === null) ? null : (bool)$only_flag;
        if ($result['is_new_store'] !== true) {
            $result['reason'] = 'not_new_store';
            $out[$po] = $result;
            continue;
        }

        // 3. every distinct site must have an arrived Store Opening Date.
        $sites = array_keys($data['sites'] ?? []);
        if (empty($sites)) {
            $result['reason'] = 'no_site_allocation';
            $out[$po] = $result;
            continue;
        }

        $all_dated = true;
        $all_arrived = true;
        $max_date = null;
        foreach ($sites as $site) {
            $date     = $dated_by_site[$site] ?? null;
            $has_date = $date !== null;
            $arrived  = $has_date && $date <= $today;
            $result['sites'][$site] = ['date' => $date, 'has_date' => $has_date, 'arrived' => $arrived];
            if (!$has_date) { $all_dated = false; }
            if (!$arrived)  { $all_arrived = false; }
            if ($has_date && ($max_date === null || $date > $max_date)) { $max_date = $date; }
        }
        $result['all_sites_dated']   = $all_dated;
        $result['all_sites_arrived'] = $all_arrived;
        $result['max_open_date']     = $max_date;

        if (!$all_dated) {
            $result['reason'] = 'site_missing_opening_date';
            $out[$po] = $result;
            continue;
        }
        if (!$all_arrived) {
            $result['reason'] = 'site_opening_date_not_arrived';
            $out[$po] = $result;
            continue;
        }

        $result['eligible'] = true;
        $result['reason']   = 'eligible';
        $out[$po] = $result;
    }

    return $out;
}

/** Human-facing [title, message, bar_class] for a non-eligible reason code. */
function ve2_form2_eligibility_message(string $reason): array
{
    return match ($reason) {
        'mixed_new_store_line_items' => [
            'Evaluation Not Available',
            'This purchase order has line items that disagree on New Store / Operational status. '
            . 'Please contact the procurement team.',
            'bg-red-600',
        ],
        'mixed_pgroup_scope' => [
            'Evaluation Not Available',
            'This purchase order spans project groups with different evaluation routing. '
            . 'Please contact the procurement team.',
            'bg-red-600',
        ],
        'no_site_allocation' => [
            'Evaluation Not Available',
            'This purchase order has no site information on record. '
            . 'Please contact the procurement team.',
            'bg-gray-600',
        ],
        'site_missing_opening_date' => [
            'Evaluation Not Available Yet',
            'The Store Opening Date for this site has not been recorded yet. '
            . 'Please check back later.',
            'bg-orange-500',
        ],
        'site_opening_date_not_arrived' => [
            'Evaluation Not Available Yet',
            'This purchase order\'s site has not opened yet. '
            . 'Please check back after the store opens.',
            'bg-orange-500',
        ],
        default => [ // no_prs_rows, pgroup_not_in_scope, not_new_store, unknown
            'Evaluation Not Available',
            'This purchase order is not eligible for the Project Retail evaluation form. '
            . 'Please contact the procurement team.',
            'bg-gray-600',
        ],
    };
}
