<?php
// includes/vendor_risk_helper.php
//
// Shared "Exception Request / K2 risk" check. Single source of truth for flagging a
// vendor that is already transacting (Exception Request, registered in K2, 2+ POs
// cut) while still not PQ-approved. Used by the Sourcing dashboard (table
// badge + login popup) and the Sourcing All Vendors list (table badge).

/**
 * @param array $vendor A row from the vendors table (assoc) — needs
 *        is_exception_request, k2_status, registration_status.
 * @param int $poCount Number of distinct POs already cut for this vendor
 *        (looked up by company_name against the workspace `prs` table).
 */
function isExceptionRequestK2Risk(array $vendor, int $poCount): bool
{
    return (int)($vendor['is_exception_request'] ?? 0) === 1
        && ($vendor['k2_status'] ?? null) === 'registered'
        && ($vendor['registration_status'] ?? null) !== 'approved'
        && $poCount >= 2;
}

/**
 * Renders the "Risk" badge HTML for a vendor flagged by isExceptionRequestK2Risk(),
 * or an empty string when not flagged. Caller is responsible for including
 * the .badge-er-risk CSS class and initializing Bootstrap tooltips.
 */
function renderExceptionRequestK2RiskBadge(array $vendor, int $poCount): string
{
    if (!isExceptionRequestK2Risk($vendor, $poCount)) {
        return '';
    }

    $tooltip = "Exception Request &amp; sudah teregistrasi K2 (sudah bertransaksi, {$poCount} PO) namun belum PQ-approved.";

    return '<span class="badge badge-er-risk ms-1" data-bs-toggle="tooltip" data-bs-placement="top" title="' . $tooltip . '">'
         . '<i class="fas fa-triangle-exclamation me-1"></i>Risk</span>';
}
