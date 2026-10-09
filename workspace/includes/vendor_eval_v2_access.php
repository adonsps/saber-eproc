<?php
// includes/vendor_eval_v2_access.php
//
// AJAX-safe access gate for the Vendor Evaluation v2 server-side DataTables
// endpoints. require_manager() (includes/auth.php) redirects on failure via a
// Location header, which an XHR call would just see as a broken JSON payload —
// so each ajax_vendor_eval_v2_*.php endpoint calls this instead, mirroring
// includes/cost_savings_access.php's cs_require_access() pattern.
//
// Requires includes/auth.php to have been loaded (for require_login()).

function ve2_require_ajax_access(): string {
    require_login();

    $role = $_SESSION['role'] ?? '';
    if ($role !== 'Manager' && $role !== 'Category Manager') {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Anda tidak memiliki akses ke Evaluasi Vendor.']);
        exit;
    }

    return $role;
}
