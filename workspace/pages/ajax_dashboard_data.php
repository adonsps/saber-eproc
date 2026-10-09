<?php
// pages/ajax_dashboard_data.php
// Dashboard "meta" endpoint: everything #prTable's own server-side endpoint
// (ajax_dashboard_table.php) does NOT need to repeat per page/filter change --
// workload charts, filter-dropdown option lists, team buttons, persona flags,
// pending vendor-eval reminders, last-updated label. Fetched once on page load,
// independent of the table's own paging/sorting/filtering requests.
require_once '../includes/auth.php';
// Read gate, not require_manager(): the read-only 'Viewer' role is admitted to
// the Command Center. Every write path off this dashboard keeps its own gate.
require_dashboard_view();
require_once '../config/database.php';

// One-shot reassignment feedback flash from the POST handler in dashboard_mgr.php.
// dashboard_mgr.php's own page load already reads+clears this; this unset is just
// a harmless mirror so a stray flash can never linger if this endpoint's response
// ever lands before that read does.
unset($_SESSION['reassign_flash']);

// All persona-scoped row fetching, Smart Status, AON classification and SLA
// verdicts live in get_dashboard_rows() (includes/dashboard_data_helper.php) --
// shared with ajax_dashboard_table.php so the two endpoints can never disagree
// about which rows a manager can see. Do not duplicate that fetch/compute pass
// here: over the full persona-scoped dataset it is expensive enough that a
// second copy of it was blowing PHP's memory_limit before returning any JSON.
require_once '../includes/dashboard_data_helper.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $result = get_dashboard_rows($pdo, $_SESSION);

    echo json_encode([
        'chart_labels'       => $result['chart_labels'],
        'chart_counts'       => $result['chart_counts'],
        'chart_raw_data'     => $result['chart_raw_data'],
        'pending_evals'      => $result['pending_evals'],
        'last_updated'       => $result['last_updated'],
        'unique_tickets'     => $result['unique_tickets'],
        'unique_porgs'       => $result['unique_porgs'],
        'unique_requestors'  => $result['unique_requestors'],
        'unique_statuses'    => $result['unique_statuses'],
        'unique_sites'       => $result['unique_sites'],
        'all_pics'           => $result['all_pics'],
        'np_pics_by_team'    => $result['np_pics_by_team'],
        'persona'            => $result['persona'],
        'show_team_filter'   => $result['show_team_filter'],
        'officers_by_pgroup' => $result['officers_by_pgroup'],
        'np_team_codes'      => $result['np_team_codes'],
        'project_team_codes' => $result['project_team_codes'],
    ]);
} catch (PDOException $e) {
    error_log('ajax_dashboard_data.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Gagal memuat data dashboard. Coba muat ulang halaman.']);
}
