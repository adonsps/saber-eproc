<?php
// includes/cost_savings_access.php
//
// One definition of "who may see Cost Savings, and how much of it".
//
// pages/cost_savings.php used to carry this inline, which was safe while the
// page WAS the data: nothing could reach a row without passing the check at the
// top of the file. Now that the rows arrive from AJAX endpoints, that check
// protects nothing on its own — each endpoint is independently reachable, so
// each one has to re-apply the same rule. Keeping it here means the page and the
// endpoints cannot drift apart.
//
// Requires includes/auth.php to have been loaded (for session + require_login).

/**
 * Roles allowed onto Cost Savings.
 *
 * Deliberately NOT require_manager(): that helper admits Manager and Category
 * Manager only, and would lock STC out of the page it exists for. Widening
 * require_manager() itself is not an option — many manager-only pages depend on
 * it staying strict.
 */
function cs_allowed_roles(): array {
    // 'Viewer' is read-only by construction: cs_can_correct() below stays
    // Category-Manager-only, and ajax_cost_savings_correct.php carries
    // ws_require_write(), so admitting it here grants sight and nothing else.
    return ['Manager', 'Category Manager', 'STC', 'Viewer'];
}

/**
 * AON mode restricts the source dataset to Master AON SKUs.
 *
 * Modelled as a capability of the role rather than a hard-coded test inside the
 * SQL, so the planned "AON Only" toggle for Managers / Category Managers can
 * later switch this on with no query changes. Today only STC is forced into it.
 *
 * MUST always be derived from the session, never from a request parameter: it is
 * the only thing keeping an STC session out of the unrestricted dataset.
 */
function cs_is_aon_only(string $role): bool {
    return $role === 'STC';
}

/** Only Category Managers may reassign teams or override benchmark prices. */
function cs_can_correct(string $role): bool {
    return $role === 'Category Manager';
}

/**
 * Gate an AJAX endpoint. Answers with JSON on refusal (a redirect would be
 * parsed as a broken payload by DataTables) and returns the caller's role.
 */
function cs_require_access(): string {
    require_login();

    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, cs_allowed_roles(), true)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Anda tidak memiliki akses ke Cost Savings.']);
        exit;
    }

    return $role;
}

/**
 * Gate the page itself. Sends a browser somewhere useful instead of a JSON body.
 */
function cs_require_page_access(): string {
    require_login();

    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, cs_allowed_roles(), true)) {
        header('Location: dashboard_pic.php');
        exit;
    }

    return $role;
}
