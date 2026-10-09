<?php
// pages/ajax_sla_items.php
// Server-side source for the "Distinct Item List" panel on sla_report.php. Ports
// renderItemList() from the old inline <script>: itemOverallCounts is always computed
// from the FULL persona-scoped dataset (ignoring gf filters) as the fixed "/ N" denominator,
// while the shown list/counter respects every active global filter (including status).
require_once '../includes/auth.php';
require_sla_access();
require_once '../config/database.php';
require_once '../includes/sla_helper.php';
require_once '../includes/project_team_helper.php';

header('Content-Type: application/json');

// sla_item_key() lives in includes/sla_helper.php — shared with ajax_sla_chat.php so both
// bucket blank descriptions into '(No Description)' the exact same way.
// The aggregation itself lives in sla_build_item_list() (also in sla_helper.php) because
// ajax_sla_charts.php returns the very same payload inline; see the note there.
//
// This endpoint now only serves item-list-ONLY changes (typing in the search box, clicking
// a column header). A global-filter change gets its item list from ajax_sla_charts.php
// instead, so it no longer costs a second full get_sla_computed_rows() pass.

try {
    $gf = sla_normalize_gf($_POST['gf'] ?? '');
    $search = isset($_POST['search']) ? (string)$_POST['search'] : '';
    $sortCol = isset($_POST['sortCol']) ? (string)$_POST['sortCol'] : 'count';
    $sortDir = isset($_POST['sortDir']) ? (string)$_POST['sortDir'] : 'desc';

    $ctx = resolve_sla_persona($pdo, $_SESSION);
    $computed = get_sla_computed_rows($pdo, $ctx);

    // Item list respects EVERY global filter, including status.
    $filteredRows = sla_gf_filter($computed['rows'], $gf, false);

    echo json_encode(sla_build_item_list($computed['rows'], $filteredRows, $search, $sortCol, $sortDir));
} catch (PDOException $e) {
    // Log the real exception server-side only — never send raw PDO/SQL details to the client.
    error_log('ajax_sla_items.php: ' . $e->getMessage());
    echo json_encode([
        'itemTotalCount' => 0,
        'itemShownCount' => 0,
        'capped' => false,
        'items' => [],
        'error' => 'Gagal memuat daftar item. Coba lagi.',
    ]);
}
