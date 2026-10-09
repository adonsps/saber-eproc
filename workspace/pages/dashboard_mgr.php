<?php
// pages/dashboard_mgr.php
require_once '../includes/auth.php';
// Read gate, not require_manager(): the read-only 'Viewer' role is admitted to
// the Command Center. Every write path off this dashboard keeps its own gate.
require_dashboard_view();
// Read-only role: drives the write-control hiding below. Cosmetic only — the
// gates are ws_require_write() on the POST handler and on every write endpoint.
$is_viewer = ws_is_viewer();
require_once '../config/database.php';
require_once '../includes/currency_helper.php';
require_once '../includes/quotation_bulk_ui.php';
require_once '../includes/quotation_modal_ui.php';
require_once '../includes/manual_ca_modal_ui.php';
// Vendor Evaluation v2 sender library — pulled in for ve2_csrf_token() only.
// It is a pure function library: no DB, no WA, no email on include.
require_once '../includes/vendor_eval_v2_sender.php';
require_once '../includes/sla_alert.php';
// Master Data audit trail — the batch reassign below can rewrite pic_names.
require_once '../includes/master_data_audit.php';
require_once '../includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date

// --- PHASE D-1: PROJECT PGROUP EXCLUSION ------------------------------------
// Mapped active Project PGroups belong to the Project Head dashboard
// (dashboard_project_head.php), not this Non-Project Manager dashboard. We
// exclude every PR whose p_group is an ACTIVE row in project_pgroups from the
// assignable/unassigned-PR queries, the workload chart, and the manual
// reassignment handler below. Compatibility: if the project_pgroups table is
// not deployed yet we fail OPEN (no exclusion, legacy behaviour, never fatal).
$project_pgroups_available = false;
try {
    $pdo->query("SELECT 1 FROM project_pgroups LIMIT 1");
    $project_pgroups_available = true;
} catch (PDOException $e) {
    $project_pgroups_available = false;
}
// Reusable SQL fragment appended to PR queries (PR table named/aliased `prs`).
// Empty string when project_pgroups is unavailable so queries stay valid.
$project_exclude_sql = $project_pgroups_available
    ? " AND NOT EXISTS (SELECT 1 FROM project_pgroups pp WHERE pp.p_group = prs.p_group AND pp.status = 'ACTIVE')"
    : "";

// --- CANVASSING_STATUS COMPATIBILITY GUARD ----------------------------------
// prs.canvassing_status may not exist on every environment (e.g. local dev DBs
// created before this column shipped). Detect it with a read-only SHOW COLUMNS
// check and swap in a harmless NULL alias when absent so every query below
// keeps returning a canvassing_status key without ever touching the schema.
$canvassing_status_available = false;
try {
    $canvassing_status_available = (bool)$pdo->query("SHOW COLUMNS FROM prs LIKE 'canvassing_status'")->fetch();
} catch (PDOException $e) {
    $canvassing_status_available = false;
}
$canvassing_status_select = $canvassing_status_available ? 'prs.canvassing_status' : 'NULL AS canvassing_status';

$manager_username = $_SESSION['username'] ?? '';
$role = $_SESSION['role'] ?? '';
$full_access_allowlist = ['adonis.sutanto', 'teofilus.franky', 'hadi.risali'];

// --- HANDLE MANUAL RE-ASSIGNMENT (LINE-LEVEL, MULTI-TICKET, AJAX-capable) ---
// Manual reassignment targets the SPECIFIC ticket line items selected in the
// UI, never a whole ticket. Only (ticket_no, ticket_line_item) pairs that were
// selected AND actually exist are updated, inside a single transaction. The
// selection may now span several tickets: the UI submits reassign_items[] as
// "<ticket_no>|<ticket_line_item>" values. AI assignment (assigned_by_ai = 1)
// still fills whole tickets by majority vote -- this manual path always stamps
// assigned_by_ai = 0 and touches only the chosen lines.
//
// The older single-ticket payload (reassign_ticket_no + reassign_lines[]) is
// still accepted so a non-JS form post keeps working.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_pic'])
    && (isset($_POST['reassign_items']) || isset($_POST['reassign_ticket_no']))) {
    // The page gate above is now a READ gate, so this write handler states its
    // own requirement: a Viewer session is refused here (403 JSON on the AJAX
    // path below, redirect otherwise) before any row is touched.
    ws_require_write();
    // AJAX path (the normal one now): the dashboard submits the reassignment via
    // fetch with X-Requested-With so we can answer with JSON and let the page
    // update in place -- no reload, no lost filters/scroll. A non-AJAX POST (no
    // JS) still falls back to a one-shot session flash + redirect.
    $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

    // Single exit point for the reassignment outcome. Messages are safe/user-
    // facing only -- never SQL, PDO internals, or secrets. Extra keys
    // (ticket_no/lines/assigned_pic) drive the client-side in-place row update.
    $send_reassign_response = function($ok, $message, array $extra = []) use ($is_ajax) {
        if ($is_ajax) {
            if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
            echo json_encode(array_merge([
                'ok'      => (bool)$ok,
                'type'    => $ok ? 'success' : 'error',
                'message' => $message,
            ], $extra));
            exit;
        }
        $_SESSION['reassign_flash'] = ['type' => $ok ? 'success' : 'error', 'message' => $message];
        header('Location: dashboard_mgr.php');
        exit;
    };

    $new_pic = trim($_POST['new_pic']);
    $pic_val = ($new_pic === '') ? null : $new_pic;

    // --- Normalise the selection into ticket_no => [ticket_line_item, ...] ---
    // Two accepted shapes, both collapsed to the same structure:
    //   reassign_items[]   = "<ticket_no>|<ticket_line_item>"   (multi-ticket UI)
    //   reassign_ticket_no + reassign_lines[]                   (legacy single ticket)
    // Empty => nothing to do; we intentionally never fall back to a whole-ticket
    // update.
    $selection = [];
    $raw_items = $_POST['reassign_items'] ?? [];
    if (!is_array($raw_items)) { $raw_items = [$raw_items]; }
    foreach ($raw_items as $item) {
        $item = (string)$item;
        // Split on the LAST separator: line items are simple values, ticket
        // numbers are the part that could conceivably contain one.
        $sep = strrpos($item, '|');
        if ($sep === false) { continue; }
        $t = trim(substr($item, 0, $sep));
        $l = trim(substr($item, $sep + 1));
        if ($t === '' || $l === '') { continue; }
        $selection[$t][$l] = true;
    }
    if (empty($selection) && isset($_POST['reassign_ticket_no'])) {
        $legacy_ticket = trim((string)$_POST['reassign_ticket_no']);
        $raw_lines = $_POST['reassign_lines'] ?? [];
        if (!is_array($raw_lines)) { $raw_lines = [$raw_lines]; }
        foreach ($raw_lines as $ln) {
            $ln = trim((string)$ln);
            if ($legacy_ticket !== '' && $ln !== '') { $selection[$legacy_ticket][$ln] = true; }
        }
    }
    // Cast back to strings: a line item like "10" became an int array key on the
    // way in (PHP's numeric-key rule), and the strict in_array() below compares
    // against string values read from PDO.
    foreach ($selection as $t => $set) { $selection[$t] = array_map('strval', array_keys($set)); }

    if (empty($selection)) {
        $send_reassign_response(false, 'Reassignment failed. No line items were selected.');
    }

    $sel_tickets = array_map('strval', array_keys($selection));

    // "Re-assign all line items of this ticket". Off by default. Widens each
    // already-selected ticket to all of its lines, read from the DB rather than
    // the client -- it can never reach a ticket the manager did not pick.
    $reassign_all_lines = isset($_POST['reassign_all_lines']) && $_POST['reassign_all_lines'] == '1';
    if ($reassign_all_lines) {
        $tk_ph = implode(',', array_fill(0, count($sel_tickets), '?'));
        $exp = $pdo->prepare("SELECT ticket_no, ticket_line_item FROM prs WHERE ticket_no IN ($tk_ph)");
        $exp->execute($sel_tickets);
        $expanded = [];
        foreach ($exp->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $expanded[(string)$r['ticket_no']][] = (string)$r['ticket_line_item'];
        }
        foreach ($expanded as $t => $lines) {
            $selection[$t] = array_values(array_unique($lines));
        }
    }

    // Never reassign a mapped active Project PGroup ticket through this
    // Non-Project dashboard -- this also blocks hand-crafted POST requests.
    // All-or-nothing: one Project ticket anywhere in the selection rejects the
    // whole batch rather than silently dropping it from a partial write.
    $project_tickets = [];
    if ($project_pgroups_available) {
        $tk_ph = implode(',', array_fill(0, count($sel_tickets), '?'));
        $chk = $pdo->prepare("SELECT DISTINCT prs.ticket_no FROM prs JOIN project_pgroups pp ON pp.p_group = prs.p_group AND pp.status = 'ACTIVE' WHERE prs.ticket_no IN ($tk_ph)");
        $chk->execute($sel_tickets);
        $project_tickets = $chk->fetchAll(PDO::FETCH_COLUMN);
    }
    $has_project_ticket = !empty($project_tickets);

    $reassign_allowed = true;

    // Outcome bookkeeping for the feedback response below. This does NOT alter
    // the assignment/validation logic -- it only records what happened.
    $reassign_result = null;   // 'success' | 'db_error' | 'no_valid_lines'
    $updated_count   = 0;
    $valid_by_ticket = [];     // the exact ticket => lines the DB write touched

    if (!$has_project_ticket && $reassign_allowed) {
        // Confirm the selected (ticket, line) pairs actually exist before
        // writing, so a hand-crafted POST can never move lines that were never
        // selected. Only the intersection is updated; unselected lines stay
        // untouched. One query for the whole batch, intersected in PHP.
        $all_lines = [];
        foreach ($selection as $lines) { foreach ($lines as $l) { $all_lines[$l] = true; } }
        $all_lines = array_map('strval', array_keys($all_lines));

        $tk_ph = implode(',', array_fill(0, count($sel_tickets), '?'));
        $ln_ph = implode(',', array_fill(0, count($all_lines), '?'));
        $vstmt = $pdo->prepare("SELECT ticket_no, ticket_line_item FROM prs WHERE ticket_no IN ($tk_ph) AND ticket_line_item IN ($ln_ph)");
        $vstmt->execute(array_merge($sel_tickets, $all_lines));
        foreach ($vstmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $t = (string)$r['ticket_no'];
            $l = (string)$r['ticket_line_item'];
            if (isset($selection[$t]) && in_array($l, $selection[$t], true)) {
                $valid_by_ticket[$t][] = $l;
            }
        }

        if (!empty($valid_by_ticket)) {
            try {
                $pdo->beginTransaction();
                $update_master_data = isset($_POST['update_master_data']) && $_POST['update_master_data'] == '1';
                $md_ids = [];

                // One stamp for the whole reassign, so every line of it carries
                // the same "who + when" in the database edit trail. The stamp is
                // gated on the PIC really changing (pr_stamp_pic_set_sql), so a
                // line already assigned to the selected PIC keeps its existing
                // trail instead of being restamped by a no-op reassignment.
                $pr_stamp_by = pr_stamp_by();
                $pr_stamp_at = pr_stamp_now();

                foreach ($valid_by_ticket as $t => $lines) {
                    $upd_ph = implode(',', array_fill(0, count($lines), '?'));
                    $updateStmt = $pdo->prepare("UPDATE prs SET " . pr_stamp_pic_set_sql()
                        . ", assigned_pic = ?, assigned_by_ai = 0 WHERE ticket_no = ? AND ticket_line_item IN ($upd_ph)");
                    $updateStmt->execute(array_merge(
                        pr_stamp_pic_params($pic_val, $pr_stamp_by, $pr_stamp_at),
                        [$pic_val, $t],
                        $lines
                    ));
                    $updated_count += count($lines);

                    if ($update_master_data && $role === 'Category Manager') {
                        $md_stmt = $pdo->prepare("SELECT DISTINCT master_data_id FROM prs WHERE ticket_no = ? AND ticket_line_item IN ($upd_ph) AND master_data_id IS NOT NULL");
                        $md_stmt->execute(array_merge([$t], $lines));
                        foreach ($md_stmt->fetchAll(PDO::FETCH_COLUMN) as $mid) { $md_ids[$mid] = true; }
                    }
                }

                // Master data is updated once for the whole batch, so a category
                // mapped by two selected tickets is written a single time.
                if ($update_master_data && $role === 'Category Manager' && !empty($md_ids)) {
                    $md_ids = array_keys($md_ids);
                    $md_in = implode(',', array_fill(0, count($md_ids), '?'));
                    $new_md_pic = $pic_val === null ? '' : $pic_val;
                    $md_actor  = md_audit_actor();
                    $md_before = md_audit_snapshot($pdo, $md_ids);
                    $md_upd = $pdo->prepare("UPDATE master_data SET pic_names = ?, suggested_pic_names = NULL, suggested_by = NULL, last_modified_by = ? WHERE id IN ($md_in)");
                    $md_upd->execute(array_merge([$new_md_pic, $md_actor], $md_ids));
                    md_audit_log_update($pdo, $md_before, $md_actor);
                }

                $pdo->commit();
                $reassign_result = 'success';
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $reassign_result = 'db_error';
                $updated_count   = 0;
            }
        } else {
            $reassign_result = 'no_valid_lines';
        }
    }

    // --- Feedback response (JSON for AJAX, else one-shot session flash) -----
    // Messages are safe/user-facing only -- no SQL, no PDO internals, no
    // secrets. $send_reassign_response() exits, so exactly one branch fires.
    if (!$reassign_allowed) {
        $send_reassign_response(false, "Reassignment failed. The selected PIC is not eligible for this ticket's team.");
    } elseif ($has_project_ticket) {
        $blocked = (count($project_tickets) === 1)
            ? ('Ticket ' . $project_tickets[0] . ' is')
            : ('Tickets ' . implode(', ', $project_tickets) . ' are');
        $send_reassign_response(false, 'Reassignment failed. ' . $blocked . ' managed on the Project dashboard. Nothing was changed.');
    } elseif ($reassign_result === 'success') {
        $ticket_count = count($valid_by_ticket);
        $scope = $ticket_count === 1
            ? ('ticket ' . array_key_first($valid_by_ticket))
            : ($ticket_count . ' tickets');
        $success_message = ($pic_val === null)
            ? ('Reassignment successful. ' . $updated_count . ' line item(s) from ' . $scope . ' were set to Unassigned.')
            : ('Reassignment successful. ' . $updated_count . ' line item(s) from ' . $scope . ' were reassigned to ' . $pic_val . '.');
        // Echo back the exact tickets + lines the DB write touched and the
        // resulting PIC (null => Unassigned) so the client can update just
        // those visible rows.
        $items = [];
        foreach ($valid_by_ticket as $t => $lines) {
            $items[] = ['ticket_no' => (string)$t, 'lines' => array_map('strval', $lines)];
        }
        // The edit-trail stamp this write applied, so the client can refresh the
        // "last updated" sub-line under the PIC without a full reload. Only rows
        // whose PIC actually changed were stamped (pr_stamp_pic_set_sql), which
        // is the same test the client applies before swapping the sub-line in.
        $send_reassign_response(true, $success_message, [
            'items'             => $items,
            'assigned_pic'      => $pic_val,
            'last_updated_by'   => $pr_stamp_by,
            'last_updated_date' => $pr_stamp_at,
        ]);
    } elseif ($reassign_result === 'db_error') {
        $send_reassign_response(false, 'Reassignment failed. A database error occurred while updating the selected lines. No changes were saved.');
    } else {
        // no_valid_lines: the submitted pairs did not match any PR line.
        $send_reassign_response(false, 'Reassignment failed. No valid line items were selected.');
    }
}
require_once '../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>.select2-container { z-index: 1060 !important; } .select2-selection { font-size: 0.75rem; padding: 0.25rem; border-color: #d1d5db !important; }</style>
<?php

// One-shot reassignment feedback flash from the POST handler above -- read
// once, then cleared so a browser refresh doesn't replay it. Rendered as a
// modal below (non-AJAX fallback path only).
$reassign_flash = $_SESSION['reassign_flash'] ?? null;
unset($_SESSION['reassign_flash']);

// --- FETCH DATA ---
// One Workload Dashboard, three personas:
//   COMBINED   = Full-access allowlist OR Category Manager: Non-Project rows + every active Project group.
//   PROJECT    = Manager who heads at least one active Project group (Rio/Fabian):
//                ONLY their Project group(s); no Non-Project rows.
//   NONPROJECT = Manager with no Project group (Susi/Jimmy/Michelle):
//                the original Non-Project behaviour, completely unchanged.
// get_project_head_groups() fails closed to [] (e.g. project tables not deployed),
// so a Non-Project manager always resolves to NONPROJECT and behaves as before.

$project_groups = function_exists('get_project_head_groups')
    ? get_project_head_groups($pdo, $_SESSION)
    : [];
// 'Viewer' is the read-only role, granted unrestricted SIGHT: COMBINED is the
// widest persona (Non-Project rows + every active Project group), matching the
// unrestricted scope it already gets on Cost Avoidance and SLA. Safe to widen
// here because the persona only decides which rows are SEEN — every write path
// off this dashboard is refused by ws_require_write()/require_manager().
// MUST stay in step with the same condition in includes/dashboard_data_helper.php:
// this one only decides whether the team chart is RENDERED, that one decides
// whether the data behind it is FETCHED.
if (in_array($manager_username, $full_access_allowlist) || $role === 'Category Manager' || $role === 'Viewer') {
    $persona = 'COMBINED';
} elseif ($role === 'Manager' && !empty($project_groups)) {
    $persona = 'PROJECT';
} else {
    $persona = 'NONPROJECT';
}

// Bulk Initial Quotation Price template/import (includes/quotation_bulk_ui.php):
// one template per row type this persona actually sees, and only for roles
// quotation_authorize_ticket() would let write it. Viewers and the named users
// in QB_HIDDEN_USERNAMES get neither.
$qb_hidden          = $is_viewer || qb_hidden_for($_SESSION);
$qb_show_project    = !$qb_hidden && !empty($project_groups) && in_array($persona, ['COMBINED', 'PROJECT'], true);
$qb_show_nonproject = !$qb_hidden && in_array($persona, ['COMBINED', 'NONPROJECT'], true)
    && in_array($role, ['Manager', 'Category Manager', 'Admin'], true);
// Manual Input (Cost Avoidance) is in the same dropdown for every team; the
// server (ajax_manual_ca_save.php) applies the real team scope.
$qb_show_manual     = !$qb_hidden;


$all_prs     = [];   // unified display rows (each tagged with _row_type)
$workload_np = [];   // Non-Project workload: assigned_pic => open-PR count
$all_pics    = [];   // Non-Project assignable PIC pool (reassign modal + filter)

// Non-Project team short codes (UI-only; mirrors the analytics acronym map in
// cost_savings.php). Used to derive per-row team labels for the COMBINED team
// filter from existing pgroup_team_mappings.team_name -- display only.
$np_acronym_map = [
    'Corporate Services, General Supplies & Marketing' => 'CSGSM',
    'Technology, Industrial, and General Services'      => 'TIGS',
    'FNB and Operational Merchandise'                   => 'FNBOM',
    'Admin Team'                                        => 'ADMIN',
    'Project Konstruksi Retail'                         => 'PKR',
    'Project Konstruksi Properti'                       => 'PKP',
    'Maintenance & Facility Management'                 => 'MFM',
];
$np_team_code = function($team_name) use ($np_acronym_map) {
    $t = trim((string)$team_name);
    if ($t === '') return '';
    return $np_acronym_map[$t] ?? $t;
};


// Data fetched via AJAX

// --- SLA WARNING ----------------------------------------------------------
// Same pop-up dashboard_pic.php and dashboard_admin.php show: open ticket lines
// (Outstanding or On Progress) whose SLA badge has gone yellow or red, split
// into those four groups with the red ones first. See includes/sla_alert.php.
//
// Shown to the Category Manager AND to a Manager. It was Category-Manager-only
// on the worry that a Manager would be handed other teams' lines -- but that is
// not what happens, because the rows come from get_dashboard_rows(), which
// resolves the persona itself:
//
//   COMBINED    Category Manager / full-access -- the whole backlog
//   PROJECT     Manager heading Project group(s) -- only those groups
//   NONPROJECT  Manager otherwise -- only their own subordinates' lines
//
// So a Manager's pop-up counts exactly the lines their own table already shows,
// which is the scoping this needed, and it comes from the persona logic rather
// than from a second rule here that could drift away from it.
//
// Counted off get_dashboard_rows() -- the SAME helper ajax_dashboard_table.php
// feeds #prTable from -- and not off a leaner query of its own. That costs this
// page render roughly a second, and it is not optional: the helper applies the
// persona scope and the Project ticket+p_group aggregation, so a query that
// merely looks equivalent lands on a different universe. A pop-up that promises
// 379 lines and then filters the table down to 338 is worse than a slow one.
$sla_alerts   = [];
$sla_autoshow = false;
if ($role === 'Category Manager' || $role === 'Manager') {
    require_once '../includes/dashboard_data_helper.php';
    // false: the chart/pending-eval extras are the dashboard's own AJAX payload,
    // nothing the count needs.
    $sla_alerts   = sla_alert_rows(get_dashboard_rows($pdo, $_SESSION, false)['rows']);
    $sla_autoshow = !empty($sla_alerts) && sla_alert_should_autoshow();
}
?>

<?php
// Emitted here, ahead of the dashboard markup and the table libraries, so the
// browser paints the warning immediately instead of after #prTable's first
// server-side page has come back. Clicking one of its four blocks lands in
// window.slaAlertApplyFilter(), defined with the quick-filter handlers below.
sla_alert_render($sla_alerts, $sla_autoshow);
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>

<style>
/* Item Description: wraps to two lines instead of being cut mid-word at 200px.
   Full text stays on the title tooltip. anywhere: item codes have no spaces. */
.pr-item-desc {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    overflow-wrap: anywhere;
}
/* Compact search/filter toolbar — mirrors the Project dashboard, keeps the
   table header a single clean row instead of a thick multi-row header. */
.filt-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #6b7280; margin-bottom: 2px; }
.filt-input { padding: 5px 8px; font-size: 12px; line-height: 1.2; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #374151; width: 100%; min-width: 0; box-sizing: border-box; }
.filt-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
/* Filter grid items must be free to shrink below their content's natural width,
   otherwise a single wide control (e.g. Global Search) forces the whole grid --
   and the card around it -- wider than the viewport instead of wrapping to a
   new row. No overflow:hidden here: the Requestor/Status/Assigned PIC
   dropdown panels are absolutely-positioned children that must still be able
   to render outside this card's box. */
#prTable_filters .filt-cell { min-width: 0; }
#prTable_wrapper .dataTables_length { margin-bottom: .5rem; font-size: 12px; }
/* Per-column multi-select dropdowns now live in the toolbar, not in <th>. */
.col-filter { position: relative; }
.col-filter .filter-dropdown { z-index: 60; }
.col-trigger { display: flex; width: 100%; align-items: center; justify-content: space-between; gap: 6px; cursor: pointer; text-align: left; }
.col-trigger .col-trigger-label { color: #6b7280; }
/* One option row inside a filter dropdown. Left-aligned and top-aligned so the
   checkbox stays flush against its label on values that wrap to two lines. */
.filter-option { display: flex; align-items: flex-start; justify-content: flex-start; gap: 8px; width: 100%; padding: 5px 10px; border-radius: 4px; cursor: pointer; text-align: left; }
.filter-option:hover { background: #f9fafb; }
.filter-option input[type="checkbox"] { flex-shrink: 0; }
/* PR Date range calendar (single popup, replaces the old pair of date inputs).
   Cells are laid out edge-to-edge with no column gap so the in-range band reads
   as one continuous stripe across a week; the rounding is applied per-cell at
   the range's ends and at each row's edges instead. */
#prDatePopup, #poDatePopup { width: 17rem; }
.pr-cal-nav, .po-cal-nav { display: flex; align-items: center; justify-content: center; width: 1.5rem; height: 1.5rem; border-radius: 9999px; color: #4b5563; }
.pr-cal-nav:hover, .po-cal-nav:hover { background: #eef2ff; color: #4f46e5; }
.pr-cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); row-gap: 2px; }
.pr-cal-dow { text-align: center; font-size: 10px; font-weight: 600; color: #6b7280; padding: 4px 0; background: #f9fafb; }
.pr-cal-dow:first-of-type { border-radius: 6px 0 0 6px; }
.pr-cal-dow:nth-of-type(7) { border-radius: 0 6px 6px 0; }
.pr-cal-cell { position: relative; display: flex; align-items: center; justify-content: center; height: 2rem; font-size: 12px; color: #374151; cursor: pointer; background: transparent; }
.pr-cal-cell.is-outside { color: #d1d5db; }
/* Neutral grey on hover so it stays distinguishable from the indigo in-range band. */
.pr-cal-cell:hover:not(.is-edge) { background: #f3f4f6; border-radius: 9999px; }
/* Days strictly between the two ends. */
/* #e0e7ff is the same tint tr.line-row-selected uses further down, so a picked
   range reads as the same "selected" state the table itself uses. */
.pr-cal-cell.is-inrange, .pr-cal-cell.is-range-start, .pr-cal-cell.is-range-end { background: #e0e7ff; }
.pr-cal-cell.is-inrange.row-start, .pr-cal-cell.is-range-start { border-radius: 9999px 0 0 9999px; }
.pr-cal-cell.is-inrange.row-end, .pr-cal-cell.is-range-end { border-radius: 0 9999px 9999px 0; }
/* A one-day range has no stripe to sit on -- just the circle. */
.pr-cal-cell.is-single { background: transparent; }
/* The two picked ends: filled dark circle drawn on top of the stripe. */
.pr-cal-cell.is-edge::before { content: ''; position: absolute; inset: 0; margin: auto; width: 2rem; height: 2rem; border-radius: 9999px; background: #4f46e5; }
.pr-cal-cell.is-edge > span { position: relative; color: #fff; font-weight: 600; }
.pr-cal-cell.is-edge.is-single { border-radius: 9999px; }
/* Today marker: small dot under the number, hidden when the day is an end. */
.pr-cal-cell.is-today::after { content: ''; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 9999px; background: #6366f1; }
.pr-cal-cell.is-today.is-edge::after { display: none; }
/* Lightweight highlight for line rows selected for manual reassignment. The
   !important + child selector beats DataTables' default row striping. */
tr.line-row-selected > td { background-color: #e0e7ff !important; }
tr.line-row-selected > td:first-child { box-shadow: inset 3px 0 0 0 #6366f1; }
/* Frozen table header: the <th> cells stick to the top of the table's own scroll
   container (#prTableScroll) so the column labels stay readable while the rows
   scroll under them. Sticky is applied per-<th> rather than to <thead>, which is
   what browsers actually honour on tables. The table stays border-collapse:
   collapse (tbody row separators are borders on <tr>, which the separated model
   would drop), so the header's bottom rule is drawn with an inset box-shadow --
   a collapsed <th> border does not travel with the cell while it is stuck. The
   second shadow layer reproduces the Tailwind shadow-sm already on these cells.
   A stuck header must be fully opaque or the rows travelling underneath show
   through it. The colour is written as a literal instead of leaning on the
   bg-gray-100 utility, whose alpha comes from the inheritable --tw-bg-opacity
   custom property -- anything setting that on an ancestor makes the header
   translucent. !important keeps the utility from winning it back. */
#prTable thead th {
    position: sticky;
    top: 0;
    z-index: 15;
    background-color: #f3f4f6 !important;
    box-shadow: inset 0 -2px 0 0 #d1d5db, 0 1px 2px 0 rgba(0, 0, 0, .05);
}
/* Table area uses the card's full width -- no side padding stealing room from the
   right-most columns. Cell padding alone provides the gutter. The top padding goes
   too: it left a gap above the stuck header where rows stayed visible.
   Rounding the scroller's own top corners keeps the now-flush grey header from
   squaring off the card's rounded top -- it is already a scroll container, so the
   radius clips it. Done here rather than with overflow-hidden on the card, which
   would capture #lineSelectBar's sticky positioning. */
#prTableScroll {
    padding-left: 0;
    padding-right: 0;
    padding-top: 0;
    border-top-left-radius: .5rem;
    border-top-right-radius: .5rem;
}
/* Line-selection area only: Shift/Ctrl/Cmd-click for per-row toggle selection is a
   click-drag-like gesture, which browsers also interpret as a text-selection
   extension (blue highlight). Scoped to the PR table body so unrelated pages and
   the rest of this page keep normal text selection. */
#prTable tbody {
    user-select: none;
    -webkit-user-select: none;
    -moz-user-select: none;
    -ms-user-select: none;
}
</style>

<div class="mb-6 flex flex-col lg:flex-row lg:justify-between lg:items-end gap-4 border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Manager Command Center</h2>
        <p class="text-sm text-gray-600">Analytics, advanced filtering, and manual overrides.</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
        <?= sla_alert_button_html($sla_alerts) ?>
        <div class="text-xs text-gray-500 lg:text-right">Last updated: <span class="font-semibold text-gray-700"><span id="lastUpdatedLabel">Loading...</span></span></div>
    </div>
</div>

<!-- Reusable reassignment feedback modal. Always present but hidden; the JS helper
     showReassignFeedbackModal(type,title,message) fills and shows it for AJAX
     success/error and the non-AJAX flash fallback ('warning' is also supported).
     Only the icon path, accent colours and text change between states. -->
<div id="reassignFeedbackModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="reassignFeedbackOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
      <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
        <div class="sm:flex sm:items-start">
          <div id="reassignFeedbackIconWrap" class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-green-100 sm:mx-0 sm:h-10 sm:w-10">
            <svg id="reassignFeedbackIcon" class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path id="reassignFeedbackIconPath" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
          </div>
          <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
            <h3 id="reassignFeedbackTitle" class="text-lg font-medium text-gray-900">Reassignment</h3>
            <div class="mt-2">
              <p id="reassignFeedbackMessage" class="text-sm text-gray-600"></p>
            </div>
          </div>
        </div>
      </div>
      <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
        <button type="button" id="reassignFeedbackClose" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-green-600 hover:bg-green-700 text-base font-bold text-white sm:ml-3 sm:w-auto sm:text-sm">Got it</button>
      </div>
    </div>
  </div>
</div>

<div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
    <div class="mb-4 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <h3 class="text-lg font-semibold text-gray-800">Current Team Workload</h3>
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <!-- Off by default -- Done (PO already created) lines are excluded from the charts below. -->
            <button type="button" id="includeDoneToggleBtn" class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium rounded shadow-sm border transition-all duration-150 focus:outline-none bg-white text-gray-700 border-gray-200 hover:bg-gray-50" title="By default, Done (PO already created) lines are left out of these charts. Click to include them as their own gray segment.">
                <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Include Done
            </button>
            <div class="flex items-center space-x-2">
                <label for="workloadMetricSelect" class="text-xs font-bold text-gray-600">Calculated By:</label>
                <select id="workloadMetricSelect" class="block w-40 pl-3 pr-10 py-1.5 text-xs border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 rounded-md">
                    <option value="ticket_lines">Ticket Lines</option>
                    <option value="unique_tickets">Unique Tickets</option>
                    <option value="canvassing">Canvassing</option>
                    <option value="po">PO</option>
                    <option value="po_amount">PO Amount</option>
                </select>
            </div>
            <!-- PR Date quick filter: same preset set as cost_avoidance.php's Global
                 Date Filter. Picking "Custom Range" opens a popup calendar (identical
                 UI to the table's own #prDateTrigger/#prDatePopup below, reusing its
                 .pr-cal-* CSS and PR_MONTHS/PR_DOW/prIso/prPretty helpers) instead of
                 plain date inputs; every other option applies immediately. -->
            <div class="flex items-center space-x-2">
                <label for="workloadDateFilterSelect" class="text-xs font-bold text-gray-600">PR Date:</label>
                <div class="relative">
                    <select id="workloadDateFilterSelect" onchange="toggleWorkloadCustomDates(this)" class="block w-36 pl-3 pr-8 py-1.5 text-xs border-gray-300 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 rounded-md">
                        <option value="all">All Time</option>
                        <option value="this_month">This Month</option>
                        <option value="last_month">Last Month</option>
                        <option value="last_2_weeks">Last 2 Weeks</option>
                        <option value="custom">Custom Range</option>
                    </select>
                    <div id="wlDatePopup" class="hidden absolute top-full right-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3" style="width:17rem;">
                        <div class="flex items-center justify-between mb-2">
                            <button type="button" class="wl-cal-nav pr-cal-nav" data-step="-1" aria-label="Previous month">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                            </button>
                            <span id="wlCalTitle" class="text-sm font-semibold text-gray-800"></span>
                            <button type="button" class="wl-cal-nav pr-cal-nav" data-step="1" aria-label="Next month">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </button>
                        </div>
                        <div id="wlCalGrid" class="pr-cal-grid"></div>
                        <div class="flex items-center gap-2 mt-3">
                            <button type="button" id="wlDateCancel" class="flex-1 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                            <button type="button" id="wlDateApply" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Terapkan</button>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" id="showAllPicsBtn" class="hidden px-3 py-1.5 text-xs font-medium rounded text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 transition-colors">
                Show All PICs
            </button>
        </div>
    </div>

    <div class="flex flex-col md:flex-row gap-6 w-full">
        <?php if ($persona === 'COMBINED'): ?>
        <div id="teamChartContainer" class="relative h-64 w-full md:w-1/2 overflow-x-auto hidden">
            <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            </div>
            <div id="workloadChartTeamWrap" class="h-full" style="min-width:100%;">
                <canvas id="workloadChartTeam"></canvas>
            </div>
        </div>
        <?php endif; ?>
        <div id="picChartContainer" class="relative h-64 w-full  overflow-x-auto">
            <div class="viz-chart-loader absolute inset-0 z-10 flex items-center justify-center bg-white bg-opacity-80">
                <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
            </div>
            <div id="workloadChartWrap" class="h-full" style="min-width:100%;">
                <canvas id="workloadChart"></canvas>
            </div>
        </div>
    </div>
</div>

<?php
// NEW FETCH: Grab the actual list of pending evaluations to populate the checklist
$evalQuery = "
    SELECT p.po_number, p.vendor_name, p.requestor_name, MAX(p.gr_date) as gr_date
    FROM prs p
    LEFT JOIN vendor_evaluations v ON p.po_number = v.po_number
    WHERE p.po_number IS NOT NULL AND p.po_number != ''
      AND p.vendor_name IS NOT NULL AND p.vendor_name != ''
      AND v.id IS NULL
      AND (p.pr_date >= '2026-05-01' OR p.pr_date IS NULL OR (p.canvassing_no IS NOT NULL AND p.canvassing_no != '') OR (p.po_number IS NOT NULL AND p.po_number != ''))
    GROUP BY p.po_number, p.vendor_name, p.requestor_name
    HAVING MAX(p.gr_date) IS NOT NULL AND MAX(p.gr_date) <= CURDATE()
    ORDER BY gr_date ASC
";
$pending_evals = $pdo->query($evalQuery)->fetchAll();
$pending_eval_count = count($pending_evals);
?>

<?php if ($pending_eval_count > 0 && !$is_viewer): ?>
    <div class="bg-blue-50 border-l-4 border-blue-500 p-4 mb-6 rounded-r-lg shadow-sm flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-center">
            <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.006c.106.005.249-.04.39.298.144.347.491 1.2.534 1.287.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.338.101.158.449.741.964 1.185.667.574 1.265.761 1.409.818.144.058.23.043.318-.043.087-.087.375-.433.476-.578.101-.144.202-.116.346-.058.144.057.915.433 1.073.5.158.065.26.101.298.159.043.058.043.346-.101.751z"/></svg>
            </div>
            <div class="ml-3">
                <p class="text-sm text-blue-800 font-bold">Action Required: Requestor Slacking</p>
                <p class="text-xs md:text-sm text-blue-700">There are <strong><?= $pending_eval_count ?></strong> POs that have passed their GR Date but lack evaluations.</p>
            </div>
        </div>
        <div class="w-full md:w-auto">
            <button type="button" id="open-wa-modal-btn"
                    title="Select POs, review the per-requestor summary, then send."
                    class="w-full md:w-auto justify-center bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded-lg text-xs md:text-sm shadow-sm transition flex items-center">
                Shoot Evaluation Reminders
            </button>
        </div>
    </div>
<?php endif; ?>

<div id="dashboardFiltersWrapper" class="relative">
<div class="mb-6 flex flex-col gap-4 bg-gray-50 p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-36 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by Status:</span>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="Outstanding">
            Outstanding
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-filter="unassigned-only">
            Unassigned
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="On Progress">
            On Progress
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="PO Created">
            PO Created
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-filter="manual-only">
            Manual
        </button>
        <button type="button" class="status-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-status="">
            All Statuses
        </button>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-36 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by Type:</span>
        <button type="button" class="type-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="">
            All Types
        </button>
        <button type="button" class="type-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="intercompany">
            Intercompany
        </button>
        <button type="button" class="type-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="non-intercompany">
            Non-Intercompany
        </button>
        <button type="button" class="type-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-type="aon" title="Tickets whose item is an AON (Always On) item — by SKU on the Master AON list, or by item name">
            AON
        </button>
        <!-- AON label sub-filter: color swatches shown inline beside the AON button,
             revealed only while the AON type filter is active. Clicking a swatch
             narrows to that label; clicking the active one again clears back to all. -->
        <div id="aonLabelFilter" class="hidden flex items-center gap-1.5 ml-1">
            <button type="button" class="aon-label-btn w-8 h-8 rounded-lg bg-green-100 border-2 border-green-400 hover:scale-110 transition-transform duration-150 focus:outline-none" data-aon-label="green" title="Green — on Master AON list, contract valid &amp; enough remaining qty"></button>
            <button type="button" class="aon-label-btn w-8 h-8 rounded-lg bg-yellow-100 border-2 border-yellow-400 hover:scale-110 transition-transform duration-150 focus:outline-none" data-aon-label="yellow" title="Yellow — AON in the item name but not on the Master AON list"></button>
            <button type="button" class="aon-label-btn w-8 h-8 rounded-lg bg-red-100 border-2 border-red-400 hover:scale-110 transition-transform duration-150 focus:outline-none" data-aon-label="red" title="Red — on Master AON list but expired or remaining qty short"></button>
            <button type="button" class="aon-label-btn w-8 h-8 rounded-lg bg-gray-100 border-2 border-gray-400 hover:scale-110 transition-transform duration-150 focus:outline-none" data-aon-label="gray" title="Gray — PO Created (AON Item)"></button>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <span class="w-36 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by SLA:</span>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="">
            All SLA
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="on_track">
            On Track
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="due_soon">
            Due Soon
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="overdue" title="Includes lines with no PR date, since their SLA countdown never started">
            Overdue
        </button>
        <button type="button" class="sla-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-sla="done" title="PO already created -- SLA countdown closed for this line">
            Done
        </button>
    </div>
    <!-- Hidden until ajax_dashboard_data.php confirms this persona actually has
         team buttons to show (show_team_filter). -->
    <div id="teamFilterContainer" class="hidden">
    <div id="teamFilterButtonsContainer" class="flex flex-wrap items-center gap-2">
        <span class="w-36 shrink-0 text-xs font-bold text-gray-500 uppercase tracking-wider">Filter by Team:</span>
        <button type="button" class="team-filter-btn px-4 py-2 bg-indigo-600 text-white text-xs md:text-sm font-bold rounded-lg shadow-sm border border-indigo-600 ring-2 ring-offset-2 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-teamcode="">
            All Teams
        </button>
        <!-- Team codes via JS -->
    </div>
    <div class="text-[11px] text-gray-600 bg-white px-3 py-2 rounded-lg border border-gray-200 shadow-inner mt-3">
        <span class="font-bold text-gray-800 mr-2">Team Abbreviations:</span>
        <span id="team-abbrev-legend"></span>
    </div>
    </div> <!-- /teamFilterContainer -->
</div>

<!-- AON label legend: sits between the filter box and the search toolbar,
     explaining the badge colors rendered on AON tickets in the table -->
<div class="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-gray-600">
    <span class="font-bold text-gray-500 uppercase tracking-wider">AON labels:</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-green-100 border border-green-300"></span> On list · valid &amp; enough qty</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-red-100 border border-red-300"></span> On list · expired or qty short</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-yellow-100 border border-yellow-300"></span> AON in name · not on Master AON list</span>
    <span class="inline-flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-gray-100 border border-gray-300"></span> PO Created (AON Item)</span>
</div>

<!-- COMPACT SEARCH / FILTER TOOLBAR (filters moved out of the table header) -->
<div id="prTable_filters" class="mb-6 bg-white p-4 rounded-xl border border-gray-200 shadow-sm">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-3 gap-y-3">
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Ticket No</label>
            <input type="text" data-col="1" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">PR Date</label>
            <!-- One control for the whole range instead of two separate date
                 inputs: the popup below is a self-contained range calendar, so
                 there is no native <input type="date"> here to read from. The
                 committed range lives in currentPrDateFrom/currentPrDateTo. -->
            <div class="col-filter min-w-0">
                <button type="button" id="prDateTrigger" class="filt-input col-trigger">
                    <span id="prDateLabel" class="col-trigger-label">All dates</span>
                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
                </button>
                <div id="prDatePopup" class="hidden absolute top-full left-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3">
                    <div class="flex items-center justify-between mb-2">
                        <button type="button" class="pr-cal-nav" data-step="-1" aria-label="Previous month">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span id="prCalTitle" class="text-sm font-semibold text-gray-800"></span>
                        <button type="button" class="pr-cal-nav" data-step="1" aria-label="Next month">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                    <div id="prCalGrid" class="pr-cal-grid"></div>
                    <div class="flex items-center gap-2 mt-3">
                        <button type="button" id="prDateCancel" class="flex-1 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                        <button type="button" id="prDateApply" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Terapkan</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" id="filterDropdown-requestor">
            <label class="filt-label">Requestor</label>
            <div class="col-filter min-w-0">
                <button type="button" class="filter-trigger filt-input col-trigger">
                    <span class="col-trigger-label">All</span>
                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
                </button>
                <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-full min-w-[12rem] rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                    <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search..."></div>
                    <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                    <div class="p-2 max-h-48 overflow-y-auto dropdown-items"></div>
                    <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="6" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Item Description</label>
            <input type="text" data-col="3" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Canvassing No</label>
            <input type="text" data-col="7" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0" id="filterDropdown-status">
            <label class="filt-label">Status</label>
            <div class="col-filter min-w-0">
                <button type="button" class="filter-trigger filt-input col-trigger">
                    <span class="col-trigger-label">All</span>
                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
                </button>
                <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-full min-w-[12rem] rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                    <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search..."></div>
                    <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                    <div class="p-2 max-h-48 overflow-y-auto dropdown-items"></div>
                    <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="4" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">PO Number</label>
            <input type="text" data-col="8" class="column-search-text filt-input" placeholder="Search...">
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">PO Date</label>
            <div class="col-filter min-w-0">
                <button type="button" id="poDateTrigger" class="filt-input col-trigger">
                    <span id="poDateLabel" class="col-trigger-label">All dates</span>
                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3M4 11h16M5 5h14a1 1 0 011 1v13a1 1 0 01-1 1H5a1 1 0 01-1-1V6a1 1 0 011-1z"/></svg>
                </button>
                <div id="poDatePopup" class="hidden absolute top-full left-0 mt-1 rounded-xl shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200 p-3">
                    <div class="flex items-center justify-between mb-2">
                        <button type="button" class="po-cal-nav" data-step="-1" aria-label="Previous month">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                        </button>
                        <span id="poCalTitle" class="text-sm font-semibold text-gray-800"></span>
                        <button type="button" class="po-cal-nav" data-step="1" aria-label="Next month">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                    <div id="poCalGrid" class="pr-cal-grid"></div>
                    <div class="flex items-center gap-2 mt-3">
                        <button type="button" id="poDateCancel" class="flex-1 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 text-gray-500 hover:bg-gray-50">Batal</button>
                        <button type="button" id="poDateApply" class="flex-1 py-1.5 text-xs font-semibold rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">Terapkan</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" id="filterDropdown-pic">
            <label class="filt-label">Assigned PIC</label>
            <div class="col-filter min-w-0">
                <button type="button" class="filter-trigger filt-input col-trigger">
                    <span class="col-trigger-label">All</span>
                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
                </button>
                <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-full min-w-[12rem] rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                    <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search..."></div>
                    <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                    <div class="p-2 max-h-48 overflow-y-auto dropdown-items"></div>
                    <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="5" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0" id="filterDropdown-site">
            <label class="filt-label">Site Allocation</label>
            <div class="col-filter min-w-0">
                <button type="button" class="filter-trigger filt-input col-trigger">
                    <span class="col-trigger-label">All</span>
                    <svg class="h-3.5 w-3.5 text-gray-400 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path d="M3 3a1 1 0 011-1h12a1 1 0 011 1v3a1 1 0 01-.293.707L12 11.414V15a1 1 0 01-.293.707l-2 2A1 1 0 018 17v-5.586L3.293 6.707A1 1 0 013 6V3z"/></svg>
                </button>
                <div class="filter-dropdown hidden absolute top-full left-0 mt-1 w-full min-w-[12rem] rounded-md shadow-xl bg-white ring-1 ring-black ring-opacity-5 z-50 border border-gray-200">
                    <div class="p-2 border-b bg-white"><input type="text" class="dropdown-search w-full px-2 py-1 text-xs border border-gray-300 rounded" placeholder="Search..."></div>
                    <div class="px-3 py-2 border-b bg-gray-50"><label class="text-[10px] flex items-center cursor-pointer text-indigo-600 font-bold"><input type="checkbox" class="select-all-checkbox mr-1"> Select All</label></div>
                    <div class="p-2 max-h-48 overflow-y-auto dropdown-items"></div>
                    <div class="p-2 border-t bg-gray-50 flex justify-end"><button type="button" class="clear-filter text-xs text-gray-500 mr-4">Clear</button><button type="button" data-col="16" class="apply-filter bg-indigo-600 text-white text-[10px] px-3 py-1 rounded">Apply</button></div>
                </div>
            </div>
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">Global Search</label>
            <input type="text" id="globalSearch" class="filt-input" placeholder="Search all columns...">
        </div>
        <div class="filt-cell flex flex-col min-w-0">
            <label class="filt-label">&nbsp;</label>
            <button type="button" id="clearAllFiltersBtn" class="filt-input bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold border-gray-300 transition">Clear All Filters</button>
        </div>
    </div>
</div>
</div> <!-- /dashboardFiltersWrapper -->

<div class="mb-3 flex justify-between items-center gap-3">
    <div class="flex items-center gap-2">
    <?php if ($qb_show_project || $qb_show_nonproject || $qb_show_manual) qb_render_toolbar('mgr', $qb_show_project, $qb_show_nonproject, $qb_show_manual); ?>
    <div class="relative" id="prExportDropdownContainer">
        <button type="button" id="prExportBtn" class="group flex items-center gap-1.5 px-3 py-1.5 text-xs font-bold text-white rounded-lg shadow-sm hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
            Export
            <svg class="w-3 h-3 opacity-70 transition-transform duration-150" id="prExportChevron" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
        </button>
        <div id="prExportMenu" class="hidden absolute left-0 mt-1.5 w-40 bg-white rounded-md shadow-lg border border-gray-200 z-50">
            <div class="px-4 py-2 border-b border-gray-100">
                <label class="text-xs font-semibold text-gray-600 flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" id="prExportAllPages" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
                    All Pages
                </label>
            </div>
            <div class="py-1">
                <button type="button" id="prExportExcel" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                <button type="button" id="prExportCSV" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                <button type="button" id="prExportPDF" class="w-full text-left px-4 py-1.5 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
            </div>
        </div>
    </div>
    </div>
    <div class="flex items-center text-xs text-indigo-700 bg-indigo-50 px-3 py-1.5 rounded-lg border border-indigo-100 shadow-sm">
        <svg class="w-4 h-4 mr-2 opacity-70" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"></path></svg>
        <span><b>Tip:</b> Use <b>Shift+Click</b> or <b>Ctrl/Cmd+Click</b> on rows to multi-select tickets.</span>
    </div>
</div>

<div class="bg-white shadow-md rounded-lg border border-gray-200 mb-8 relative">
    <div class="viz-table-loader absolute inset-0 z-20 flex flex-col items-center justify-center bg-white bg-opacity-80 rounded-lg">
        <div class="w-8 h-8 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
        <p class="mt-3 text-xs font-semibold text-gray-500">Loading records…</p>
    </div>
    <!-- Line-level manual reassignment action bar: appears when one or more line
         items are checked. The selection may span several tickets — each checked
         line is reassigned individually, unchecked lines are never touched. -->
    <div id="lineSelectBar" class="hidden sticky top-0 z-30 flex items-center justify-between gap-3 px-6 py-3 bg-indigo-50 border-b border-indigo-200 rounded-t-lg">
        <div class="flex flex-col">
            <span class="text-sm font-bold text-indigo-800"><span id="lineSelectCount">0</span> line item(s) selected<span id="lineSelectTickets"></span></span>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" id="lineSelectClear" class="text-xs text-gray-500 hover:text-gray-700">Clear</button>
            <button type="button" id="reassignSelectedBtn" class="inline-flex items-center px-4 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold rounded-lg shadow-sm">Reassign Selected Lines &rarr;</button>
        </div>
    </div>
    <div id="prTableScroll" class="overflow-y-auto px-6 pb-6 pt-2" style="max-height: 750px;">
        <table id="prTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="line-select-col hidden dt-head-center bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[90px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center"><input type="checkbox" id="selectAllCheckbox" class="h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" title="Select All Visible Lines"></th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[122px] text-xs font-bold text-gray-600 uppercase tracking-wider">Ticket No</th>
                    <th class="bg-gray-100 px-2 py-2.5 border-b-2 border-gray-300 shadow-sm w-[70px] min-w-[70px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center" title="Days remaining against this ticket's SLA target, counted from the PR date">SLA</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[300px] max-w-[420px] text-xs font-bold text-gray-600 uppercase tracking-wider">Item Description</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[80px] text-xs font-bold text-gray-600 uppercase tracking-wider">Status</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[170px] text-xs font-bold text-gray-600 uppercase tracking-wider">Assigned PIC</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[150px] text-xs font-bold text-gray-600 uppercase tracking-wider">Requestor</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[135px] text-xs font-bold text-gray-600 uppercase tracking-wider">Canvassing No</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[119px] text-xs font-bold text-gray-600 uppercase tracking-wider">PO Number</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[130px] text-xs font-bold text-gray-600 uppercase tracking-wider text-right">PO Line Amount</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[90px] text-xs font-bold text-gray-600 uppercase tracking-wider">PO Currency Code</th>
                    <th class="hidden">PR Date</th><!-- hidden; export-only column, split out from Ticket No -->
                    <!-- POrg, PGroup, L0-L5, Site Allocation, Canvassing Date and PO Date: header
                         cells are positional (DataTables matches <th> to columns[] by DOM
                         order). main's own pattern of leaving these un-declared works ONLY
                         because every one of its hidden columns trails every visible one; here
                         Initial Quotation Price is a VISIBLE column trailing them instead, which
                         breaks that assumption (confirmed live: relying on it threw "Cannot
                         read properties of undefined (reading 'style')" and the table never
                         left "Loading records...", 2026-09-17). So every column gets an
                         explicit placeholder here in the exact same order as its columns[]
                         entry below, all the way through. -->
                    <th class="hidden">POrg</th>
                    <th class="hidden">PGroup</th>
                    <th class="hidden">L0</th>
                    <th class="hidden">L1</th>
                    <th class="hidden">L2</th>
                    <th class="hidden">L3</th>
                    <th class="hidden">L4</th>
                    <th class="hidden">L5</th>
                    <th class="hidden">Site Allocation</th>
                    <th class="hidden">Canvassing Date</th>
                    <th class="hidden">PO Date</th>
                    <!-- Initial Quotation Price: the one column after all the hidden ones that
                         IS visible, so it gets a real (non-hidden), styled header cell. -->
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[170px] text-xs font-bold text-gray-600 uppercase tracking-wider text-center">Initial Quotation Price</th>
                    <th class="bg-gray-100 px-4 py-2.5 border-b-2 border-gray-300 shadow-sm min-w-[150px] text-xs font-bold text-gray-600 uppercase tracking-wider" title="Ticket Helpdesk No. from the K2 export">Helpdesk No.</th>

                </tr>
            </thead>
            <tbody>
                <!-- Populated by DataTables AJAX -->
            </tbody>
        </table>
    </div>
</div>

<div id="reassignModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="modalOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <!-- max-w-2xl + a min-w-0 chain: the line-item table scrolls inside its own
         box instead of pushing the modal body out. -->
    <div class="inline-block align-bottom bg-white rounded-xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
      <form method="POST" action="">
          <div class="flex items-center gap-3 border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-white px-5 py-4">
            <div class="flex-shrink-0 flex items-center justify-center h-10 w-10 rounded-full bg-indigo-100">
              <svg class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
            </div>
            <div class="min-w-0">
              <h3 class="text-base font-bold text-gray-900">Reassign PR Ticket</h3>
              <p class="text-xs text-gray-500 truncate" id="modal-target-line"></p>
            </div>
          </div>

          <div class="px-5 py-4 max-h-[70vh] overflow-y-auto">
              <!-- Hidden reassign_items[] inputs for the non-AJAX form post. -->
              <span id="modal-lines-container"></span>

              <!-- Every line of every selected ticket; filled by loadTicketLineSuggestion(). -->
              <div id="modal-detail-box" class="hidden mb-4 rounded-lg border border-gray-200 overflow-hidden">
                <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 bg-gray-50 border-b border-gray-200 px-3 py-2">
                  <p class="text-xs font-bold text-gray-700" id="modal-detail-title"></p>
                  <p class="text-xs text-gray-500" id="modal-detail-count"></p>
                </div>
                <div id="modal-detail-meta" class="hidden flex flex-wrap gap-x-4 gap-y-1 border-b border-gray-100 px-3 py-2 text-xs text-gray-600"></div>
                <div class="max-h-52 overflow-y-auto overflow-x-auto">
                  <table class="min-w-full text-xs text-left text-gray-700">
                    <thead class="sticky top-0 z-10">
                      <tr class="bg-gray-100 text-gray-600">
                        <th class="px-2 py-1.5 font-semibold whitespace-nowrap w-6" title="Select line items to re-assign">
                          <input type="checkbox" id="modal-detail-select-all" class="h-3.5 w-3.5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 align-middle">
                          <span class="sr-only">Select all listed line items</span>
                        </th>
                        <th class="detail-ticket-col hidden px-2 py-1.5 font-semibold whitespace-nowrap">Ticket</th>
                        <th class="px-2 py-1.5 font-semibold whitespace-nowrap">Line</th>
                        <th class="px-2 py-1.5 font-semibold min-w-[220px]">Item Description</th>
                        <th class="px-2 py-1.5 font-semibold whitespace-nowrap">Status</th>
                        <th class="px-2 py-1.5 font-semibold whitespace-nowrap">Current PIC</th>
                      </tr>
                    </thead>
                    <tbody id="modal-detail-list" class="divide-y divide-gray-100"></tbody>
                  </table>
                </div>
                <p id="modal-detail-more" class="hidden bg-gray-50 border-t border-gray-100 px-3 py-1.5 text-xs text-gray-500 italic"></p>
                <p id="modal-detail-project-warn" class="hidden bg-red-50 border-t border-red-100 px-3 py-1.5 text-xs font-semibold text-red-700"></p>
              </div>

              <label for="modal-reassign-all-lines" class="mb-4 flex items-start gap-3 rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2.5 cursor-pointer hover:bg-indigo-100 transition-colors">
                <input id="modal-reassign-all-lines" name="reassign_all_lines" type="checkbox" value="1" class="mt-0.5 focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                <span class="text-sm min-w-0">
                  <span class="font-semibold text-gray-800 block">Re-assign all line items of this ticket</span>
                  <span class="text-gray-600 text-xs block mt-0.5" id="modal-reassign-all-hint">Leave unticked to move only the selected line item(s).</span>
                </span>
              </label>

              <label for="modal-pic-select" class="block text-xs font-bold text-gray-600 uppercase tracking-wider mb-1">New PIC</label>
              <select name="new_pic" id="modal-pic-select" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                  <option value="">-- Set to Unassigned --</option>
                  <!-- Populated via JS -->
              </select>
              <?php if ($role === 'Category Manager'): ?>
              <label for="modal-update-master-data" class="mt-3 flex items-start gap-3 cursor-pointer">
                <input id="modal-update-master-data" name="update_master_data" type="checkbox" class="mt-0.5 focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                <span class="text-sm min-w-0">
                  <span class="font-medium text-gray-700 block">Update PIC in Master Data</span>
                  <span class="text-gray-500 text-xs block mt-0.5">Also update the mapped master data dictionary for these line items.</span>
                </span>
              </label>
              <?php endif; ?>
          </div>
          <div class="bg-gray-50 border-t border-gray-200 px-5 py-3 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button type="button" id="closeModalBtn" class="inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-bold text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="submit" class="inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-sm font-bold text-white hover:bg-indigo-700">Save Changes</button>
          </div>
      </form>
    </div>
  </div>
</div>

<!-- PROJECT ASSIGN MODAL (manual, eligible officers only, via ajax_project_assign.php) -->
<div id="projectAssignModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="projOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <div class="sm:flex sm:items-start">
              <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-indigo-100 sm:mx-0 sm:h-10 sm:w-10">
                <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" /></svg>
              </div>
              <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                <h3 class="text-lg font-medium text-gray-900">Assign Project Ticket</h3>
                <div class="mt-2">
                  <p class="text-sm text-gray-500 mb-1">Ticket <strong id="proj-modal-ticket" class="text-indigo-600"></strong></p>
                  <p class="text-xs text-gray-400 mb-4">P.Group <strong id="proj-modal-pgroup"></strong> &middot; eligible officers only.</p>
                  <select id="proj-officer-select" class="block w-full py-2 px-3 border border-gray-300 bg-white rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                      <option value="">-- Set to Unassigned --</option>
                  </select>
                  <p id="proj-modal-error" class="text-xs text-red-600 mt-2 hidden"></p>
                </div>
              </div>
            </div>
        </div>
        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button type="button" id="projSaveBtn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Save Changes</button>
            <button type="button" id="projCancelBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
        </div>
    </div>
  </div>
</div>

<div id="waModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:p-0">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity backdrop-blur-sm" id="waModalOverlay"></div>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-5xl sm:w-full">
        <div class="bg-indigo-700 px-6 py-4">
            <h3 class="text-xl font-bold text-white">Shoot Evaluation Reminders</h3>
            <p class="text-indigo-200 text-sm mt-1">
                Select POs, review the per-requestor summary, then send. Each requestor receives
                <strong>one email and one WhatsApp</strong> covering all of their selected POs.
            </p>
        </div>

        <div class="p-6">
            <table id="waTable" class="min-w-full leading-normal w-full text-left">
                <thead>
                    <tr>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 border-gray-300 w-12 text-center">
                            <input type="checkbox" id="wa-select-all" class="h-4 w-4 text-indigo-600 rounded">
                        </th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase">PO Number</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase">Vendor</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase">Requestor</th>
                        <th class="px-4 py-3 bg-gray-100 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase">GR Date</th>
                    </tr>
                    <tr class="bg-gray-50">
                        <th class="px-4 py-2"></th>
                        <th class="px-4 py-2"><input type="text" class="wa-col-search w-full px-2 py-1 text-xs border rounded" placeholder="Filter PO..."></th>
                        <th class="px-4 py-2"><input type="text" class="wa-col-search w-full px-2 py-1 text-xs border rounded" placeholder="Filter Vendor..."></th>
                        <th class="px-4 py-2"><input type="text" class="wa-col-search w-full px-2 py-1 text-xs border rounded" placeholder="Filter Requestor..."></th>
                        <th class="px-4 py-2 text-center">
                            <button type="button" id="wa-clear-filters" class="text-xs text-indigo-600 hover:text-indigo-800 font-bold underline" title="Clear all column filters">Clear Filters</button>
                        </th>
                    </tr>
                </thead>
                <tbody id="wa-table-body">
                </tbody>
            </table>

            <!-- Populated by the server-side preview (SELECT only — nothing is sent). -->
            <div id="wa-preview-panel" class="hidden mt-5 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-sm font-bold text-gray-700">Recipients for this send</h4>
                    <span id="wa-preview-headline" class="text-xs text-gray-500"></span>
                </div>
                <div id="wa-preview-body" class="max-h-56 overflow-y-auto text-xs text-gray-700"></div>
                <div id="wa-preview-skips" class="mt-2 text-xs text-amber-700"></div>
            </div>
        </div>

        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex flex-col gap-3">
            <label id="wa-confirm-wrap" class="hidden flex items-start gap-2 text-xs text-red-700 bg-red-50 border border-red-200 rounded-md p-3">
                <input type="checkbox" id="wa-confirm-real-send" class="mt-0.5 h-4 w-4 text-red-600 rounded">
                <span>I understand this will send an email and WhatsApp to the requestors listed above.</span>
            </label>
            <div class="flex flex-col sm:flex-row-reverse gap-3">
                <button type="button" id="submit-wa-blast"
                        class="w-full sm:w-auto inline-flex justify-center rounded-md border border-transparent shadow-sm px-6 py-2 bg-indigo-600 hover:bg-indigo-700 text-base font-bold text-white transition focus:outline-none">
                    Review Recipients
                </button>
                <button type="button" id="closeWaModalBtn" class="w-full sm:w-auto inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-6 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 transition focus:outline-none">
                    Cancel
                </button>
                <p id="wa-modal-hint" class="flex-1 self-center text-xs text-gray-600">
                    Step 1 of 2 — nothing is sent until you review the recipients and confirm.
                </p>
            </div>
        </div>
    </div>
  </div>
</div>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
var officersByPgroup = {};
// Read-only 'Viewer' session. Hides write affordances only; the server
// refuses the corresponding endpoints regardless of what the page renders.
var IS_VIEWER        = <?= json_encode($is_viewer) ?>;
var IS_CATEGORY_MANAGER = <?= json_encode($role === 'Category Manager') ?>;
var VE2_CSRF_TOKEN   = <?= json_encode(ve2_csrf_token()) ?>;
var VE2_SEND_CONFIRM = <?= json_encode(VE2_WEB_SEND_CONFIRM) ?>;
var LPQ_CURRENCY_SYMBOLS = <?= json_encode(ca_currency_symbols(ca_get_active_currencies($pdo))) ?>;
var npPicsByTeam     = {};
var dashboardPersona = '';
var reassignFlash = <?= $reassign_flash ? json_encode($reassign_flash, JSON_UNESCAPED_UNICODE) : 'null' ?>;

$(document).ready(function() {
    // Multi-select: statuses OR together; Manual / Unassigned (pick one) AND on top of them.
    var currentStatusFilters = [];
    var ASSIGNMENT_FILTERS = ['manual-only', 'unassigned-only'];
    function statusFilterRealStatuses() {
        return currentStatusFilters.filter(function(v) { return ASSIGNMENT_FILTERS.indexOf(v) === -1; });
    }
    function statusFilterManualOnly() { return currentStatusFilters.indexOf('manual-only') !== -1; }
    function statusFilterUnassignedOnly() { return currentStatusFilters.indexOf('unassigned-only') !== -1; }
    function rowMatchesStatusFilters(row) {
        if (statusFilterUnassignedOnly() && (row.assigned_pic || row.resolved_officer)) return false;
        if (statusFilterManualOnly() && (parseInt(row.assigned_by_ai || 0, 10) !== 0 || !row.assigned_pic)) return false;
        var realStatuses = statusFilterRealStatuses();
        return !realStatuses.length || realStatuses.indexOf(row.display_status || '') !== -1;
    }
    var currentTypeFilter = '';   // '', 'intercompany', 'non-intercompany', 'aon'
    var currentAonLabel   = '';   // '', 'green', 'yellow', 'red' — only used while type = 'aon'
    var currentTeamFilter = '';
    var currentSlaFilter  = '';   // '', 'on_track', 'due_soon', 'overdue', 'na'

    // Default per role, disetel sebelum #prTable (serverSide) diinisialisasi
    // supaya request pertama sudah membawa filter ini.
    if (IS_VIEWER) {
        currentStatusFilters = ['Outstanding'];
        currentSlaFilter     = 'overdue';
    } else if (IS_CATEGORY_MANAGER) {
        currentStatusFilters = ['unassigned-only'];
    } else {
        currentStatusFilters = ['Outstanding', 'unassigned-only'];
    }
    // The SLA quick filter normally folds 'na' (no PR date, countdown never
    // started) into Overdue. The SLA pop-up counts 'na' as neither colour, so a
    // click coming from it asks for the EXACT bucket instead -- otherwise the
    // table would answer "379 lines" with 415 rows. Any later click on a filter
    // button by hand clears the flag and restores the normal folding.
    var slaAlertExact     = false;
    // Set while the pop-up applies BOTH quick filters at once, so the two
    // handlers restyle their buttons without each firing its own AJAX redraw.
    var slaAlertApplying  = false;
    var currentPrDateFrom = '';  // 'YYYY-MM-DD' or ''
    var currentPrDateTo   = '';  // 'YYYY-MM-DD' or ''
    var currentPoDateFrom = '';  // 'YYYY-MM-DD' or ''
    var currentPoDateTo   = '';  // 'YYYY-MM-DD' or ''

    // Team legend. Only the codes this persona actually has buttons for are
    // rendered (see renderTeamAbbrevLegend below, called from loadDashboardMeta)
    // so a manager scoped to two teams isn't handed a glossary of all seven.
    var TEAM_ABBREV = {
        'CSGSM': 'Corporate Services, General Supplies & Marketing',
        'TIGS':  'Technology, Industrial, and General Services',
        'FNBOM': 'FNB and Operational Merchandise',
        'ADMIN': 'Admin Team',
        'PKR':   'Project Konstruksi Retail',
        'PKP':   'Project Konstruksi Properti',
        'MFM':   'Maintenance & Facility Management'
    };
    function renderTeamAbbrevLegend(codes) {
        var seen = {}, legend = [];
        (codes || []).forEach(function(code) {
            if (!code || seen[code] || !TEAM_ABBREV[code]) return;
            seen[code] = true;
            legend.push('<strong>' + code + '</strong> = ' + TEAM_ABBREV[code]);
        });
        $('#team-abbrev-legend').html(legend.join(' &nbsp;&bull;&nbsp; '));
    }

    var $feedbackModal = $('#reassignFeedbackModal');
    function showReassignFeedbackModal(type, title, message) {
        var $wrap = $('#reassignFeedbackIconWrap');
        var $icon = $('#reassignFeedbackIcon');
        var $btn  = $('#reassignFeedbackClose');
        $wrap.removeClass('bg-green-100 bg-red-100 bg-amber-100');
        $icon.removeClass('text-green-600 text-red-600 text-amber-600');
        $btn.removeClass('bg-green-600 hover:bg-green-700 bg-red-600 hover:bg-red-700 bg-amber-500 hover:bg-amber-600');
        if (type === 'success') {
            $wrap.addClass('bg-green-100'); $icon.addClass('text-green-600');
            $btn.addClass('bg-green-600 hover:bg-green-700');
            $('#reassignFeedbackIconPath').attr('d', 'M5 13l4 4L19 7');
        } else if (type === 'warning') {
            $wrap.addClass('bg-amber-100'); $icon.addClass('text-amber-600');
            $btn.addClass('bg-amber-500 hover:bg-amber-600');
            $('#reassignFeedbackIconPath').attr('d', 'M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z');
        } else {
            $wrap.addClass('bg-red-100'); $icon.addClass('text-red-600');
            $btn.addClass('bg-red-600 hover:bg-red-700');
            $('#reassignFeedbackIconPath').attr('d', 'M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z');
        }
        $('#reassignFeedbackTitle').text(title || '');
        $('#reassignFeedbackMessage').text(message || '');
        $feedbackModal.removeClass('hidden');
    }
    $('#reassignFeedbackClose, #reassignFeedbackOverlay').on('click', function() {
        $feedbackModal.addClass('hidden');
    });
    if (reassignFlash && reassignFlash.message) {
        var flashOk = (reassignFlash.type === 'success');
        showReassignFeedbackModal(flashOk ? 'success' : 'error',
            flashOk ? 'Reassignment Successful' : 'Reassignment Failed',
            reassignFlash.message);
    }

    // Meta data (charts, filter-dropdown option lists, team buttons, persona
    // flag, pending vendor-eval reminders) is independent of the table's own
    // paging/filtering, so it's fetched once here instead of living inside the
    // table's per-request ajax response.
    function loadDashboardMeta() {
        $.get('ajax_dashboard_data.php', function(json) {
            officersByPgroup = json.officers_by_pgroup || {};
            npPicsByTeam     = json.np_pics_by_team || {};
            dashboardPersona = json.persona || '';

            if (json.show_team_filter) {
                $('#teamFilterContainer').removeClass('hidden');
                renderTeamAbbrevLegend(
                    (json.np_team_codes || []).concat($.map(json.project_team_codes || {}, function(v) { return v; }))
                );

                if ($('#teamFilterButtonsContainer .dynamic-btn').length === 0) {
                    let btnHtml = '';
                    try {
                        var combined = [];
                        if (json.np_team_codes) {
                            $.each(json.np_team_codes, function(i, code) {
                                if (combined.indexOf(code) === -1) combined.push(code);
                            });
                        }
                        if (json.project_team_codes) {
                            $.each(json.project_team_codes, function(gnum, code) {
                                if (combined.indexOf(code) === -1) combined.push(code);
                            });
                        }
                        $.each(combined, function(i, code) {
                            btnHtml += '<button type="button" class="dynamic-btn team-filter-btn px-4 py-2 bg-white text-gray-700 text-xs md:text-sm font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-50 hover:shadow-md transform hover:-translate-y-0.5 transition-all duration-150 focus:outline-none" data-teamcode="'+code+'">'+code+'</button>';
                        });
                        $('#teamFilterButtonsContainer').append(btnHtml);
                    } catch (e) {
                        console.error("Error generating team filter buttons", e);
                    }
                }
            }
            if (json.last_updated) {
                $('#lastUpdatedLabel').text(json.last_updated);
            }

            // Initialize Workload Charts with AJAX Data
            if (typeof initWorkloadCharts === 'function') {
                initWorkloadCharts(json.chart_raw_data, json.persona);
            }

            RAW_DATA = json.chart_raw_data;
            $('#last_updated_label').text(json.last_updated);

            if (json.pending_evals && json.pending_evals.length > 0) {
                $('#pending-eval-alert').removeClass('hidden');
                $('#pending_eval_count_display').text(json.pending_evals.length);
                let tbody = '';
                json.pending_evals.forEach(e => {
                    tbody += `<tr>
                        <td class="px-3 py-2 whitespace-nowrap text-sm font-medium text-gray-900">${e.po_number}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-700">${e.vendor_name}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-700">${e.requestor_name}</td>
                        <td class="px-3 py-2 whitespace-nowrap text-sm text-gray-700 font-bold ${new Date(e.gr_date) < new Date() ? 'text-red-600' : ''}">${e.gr_date}</td>
                    </tr>`;
                });
                $('#wa-table-body').html(tbody);
            } else {
                $('#pending-eval-alert').addClass('hidden');
            }

            // Populate filters
            function populateFilter(selector, data) {
                let html = '';
                data.forEach(val => {
                    let safeVal = $('<div>').text(val).html();
                    // NOTE: no `col-trigger` class here -- that one styles the
                    // dropdown's trigger BUTTON (justify-between, to push its
                    // chevron to the far edge) and would splay each option's
                    // checkbox and text to opposite sides of the row.
                    html += `<label class="filter-option">
                        <input type="checkbox" class="col-filter-checkbox mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="${safeVal}">
                        <span class="text-xs text-gray-700 font-medium leading-tight select-none">${safeVal}</span>
                    </label>`;
                });
                $(selector).html(html);
            }
            populateFilter('#filterDropdown-requestor .dropdown-items', json.unique_requestors);
            populateFilter('#filterDropdown-status .dropdown-items', json.unique_statuses);
            populateFilter('#filterDropdown-site .dropdown-items', json.unique_sites || []);
            window.populateFilterGlobal = populateFilter;

            let picHtml = '';
            let modalPicHtml = '<option value="">-- Set to Unassigned --</option>';
            json.all_pics.forEach(pic => {
                picHtml += `<label class="filter-option">
                    <input type="checkbox" class="col-filter-checkbox mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="${pic}">
                    <span class="text-xs text-gray-700 font-medium leading-tight select-none">${pic}</span>
                </label>`;
                modalPicHtml += `<option value="${pic}">${pic}</option>`;
            });
            $('#filterDropdown-pic .dropdown-items').html(picHtml);
            $('#modal-pic-select').html(modalPicHtml);
            if (json.persona === 'COMBINED' && json.show_team_filter) {
                let teamHtml = '';
                const map = {
                    'Corporate Services, General Supplies & Marketing': 'CSGSM',
                    'Technology, Industrial, and General Services': 'TIGS',
                    'FNB and Operational Merchandise': 'FNBOM',
                    'Admin Team': 'ADMIN',
                    'Project Konstruksi Retail': 'PKR',
                    'Project Konstruksi Properti': 'PKP',
                    'Maintenance & Facility Management': 'MFM'
                };
                Object.entries(map).forEach(([tName, tCode]) => {
                    teamHtml += `<label class="filter-option">
                        <input type="checkbox" class="col-filter-checkbox mt-0.5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500" value="${tCode}">
                        <span class="text-xs text-gray-700 font-medium leading-tight select-none" title="${tName}">${tCode}</span>
                    </label>`;
                });
                $('#filterDropdown-team .dropdown-items').html(teamHtml);
            }
        }).fail(function() {
            console.error('Gagal memuat data ringkasan dashboard (chart/filter). Coba muat ulang halaman.');
        });
    }
    loadDashboardMeta();

    // "Last updated" sub-line under the Assigned PIC control.
    //
    // prs.last_updated_by / last_updated_date track assigned_pic and nothing
    // else (includes/pr_audit_stamp.php), so the pair reads exactly as "who set
    // this PIC, and when". Empty for a line whose PIC has never been written
    // through a stamped path -- older rows, mostly -- and the sub-line is then
    // left out entirely rather than shown as a dash.
    function picStampHtml(row) {
        var by = (row.last_updated_by || '').toString().trim();
        var at = (row.last_updated_date || '').toString().trim();
        if (!by && !at) { return ''; }

        var when = '';
        // The column already holds Asia/Jakarta wall-clock time (stamped in PHP,
        // not by SQL NOW()). Formatted by hand instead of through new Date(),
        // which reads a bare 'YYYY-MM-DD HH:MM:SS' as UTC in some browsers and
        // would shift every stamp by seven hours.
        var m = at.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (m) {
            var months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            when = m[3] + ' ' + months[parseInt(m[2], 10) - 1] + ' ' + m[1] + ' ' + m[4] + ':' + m[5];
        } else {
            when = at;
        }

        var label = by ? picStampEsc(by) : '';
        if (when) { label += (label ? ' &middot; ' : '') + picStampEsc(when); }
        return '<div class="pic-stamp text-[10px] text-gray-400 mt-1 leading-tight"'
             + ' title="Last PIC change">' + label + '</div>';
    }

    // last_updated_by is free text (a username, an importer name, 'AI Auto-Assign'),
    // so it is escaped before going into the cell.
    function picStampEsc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var table = $('#prTable').DataTable({
        serverSide: true,
        processing: true,
        ajax: {
            url: "ajax_dashboard_table.php",
            type: "POST",
            data: function(d) {
                // Toolbar quick-filters aren't backed by a single DataTables column,
                // so they ride alongside its own protocol fields (paging/sorting/
                // per-column search/global search) in this one JSON blob.
                d.gf = JSON.stringify({
                    status: statusFilterRealStatuses(),
                    manualOnly: statusFilterManualOnly(),
                    unassignedOnly: statusFilterUnassignedOnly(),
                    type: currentTypeFilter,
                    aonLabel: currentAonLabel,
                    team: currentTeamFilter,
                    sla: currentSlaFilter,
                    slaExact: slaAlertExact,
                    prDateFrom: currentPrDateFrom,
                    prDateTo: currentPrDateTo,
                    poDateFrom: currentPoDateFrom,
                    poDateTo: currentPoDateTo
                });
            },
            error: function() {
                $('.viz-table-loader').fadeOut(200);
            }
        },
        initComplete: function() {
            // First successful load only -- fades the "Loading records..."
            // skeleton for good. Later draws (paging/filtering) are covered by
            // DataTables' own "processing" indicator (processing: true above),
            // not this one-time skeleton.
            $('.viz-table-loader').fadeOut(200);
        },

        columns: [
            {
                data: null,
                className: "line-select-col hidden px-4 py-3 text-center",
                orderable: false,
                render: function(data, type, row) {
                    if ((row._row_type || 'nonproject') === 'project') return '';
                    return `<input type="checkbox" class="line-select-checkbox h-4 w-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500" data-ticket="${row.ticket_no || ''}" data-line="${row.ticket_line_item || ''}" value="${row.ticket_no || ''}|${row.ticket_line_item || ''}">`;
                }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm font-medium text-indigo-700 cursor-copy ticket-no-cell relative hover:text-indigo-900 group",
                render: function(data, type, row) {
                    // Sorted/typed by PR Date so the header still drives a date sort
                    // even though PR Date no longer has its own column.
                    if (type === 'sort' || type === 'type') {
                        return row.pr_date || '';
                    }
                    if (type === 'filter') {
                        return row.ticket_no || '';
                    }
                    // Export (Excel/CSV/PDF): PR Date has its own hidden column below,
                    // so this one exports as the bare ticket number only.
                    if (type === 'export') {
                        return row.ticket_no || '';
                    }
                    let html = `<span class="ticket-no-text">${row.ticket_no || ''}</span>
                                <span class="absolute left-1/2 -top-6 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-1 rounded opacity-0 transition-opacity duration-200 pointer-events-none copy-toast whitespace-nowrap">Copied!</span>`;
                    if ((row._row_type || 'nonproject') === 'project') {
                        html += `<div class="mt-0.5">
                                    <span class="inline-block bg-gray-50 text-gray-700 border border-gray-200 py-0.5 px-1.5 rounded text-[10px] font-bold">${row.p_group || ''}</span>
                                    <span class="text-[10px] font-bold text-indigo-500 ml-1 tracking-wide" title="${row.project_group_name || ''}">${row.team_code || ''}</span>
                                 </div>`;
                    }
                    if (row.pr_date) {
                        let d = new Date(row.pr_date);
                        if (!isNaN(d.getTime())) {
                            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            let day = d.getDate().toString().padStart(2, '0');
                            html += `<div class="text-[10px] text-gray-400 mt-0.5">${day} ${months[d.getMonth()]} ${d.getFullYear()}</div>`;
                        }
                    }
                    return html;
                }
            },
            {
                // SLA: calendar days remaining against the ticket's configured target
                // (settings_sla.php), counted from the PR date. Server-computed in
                // includes/dashboard_data_helper.php so the rule lives in exactly one place.
                data: null,
                // No text-center here: DataTables mirrors columns.className onto the
                // header cell, and the header stays left-aligned. The badge centers itself.
                className: "px-2 py-3 text-sm",
                render: function(data, type, row) {
                    if (type === 'sort' || type === 'type') return parseInt(row.sla_sort != null ? row.sla_sort : 9999);
                    if (type === 'filter') return row.sla_status || '';
                    let cls   = row.sla_badge || 'bg-gray-100 text-gray-500 border-gray-200';
                    let label = row.sla_label || 'N/A';
                    let title = (row.sla_title || '').replace(/"/g, '&quot;');
                    return `<span class="${cls} py-1 px-1 rounded text-[10px] font-bold border block w-fit min-w-[62px] mx-auto text-center whitespace-nowrap" title="${title}">${label}</span>`;
                }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm text-gray-700",
                render: function(data, type, row) {
                    let html = `<div class="pr-item-desc text-indigo-600 hover:text-indigo-900 cursor-pointer hover:underline pr-taxonomy-clickable" title="View Taxonomy: ${row.item_description || ''}" data-ticket-no="${row.ticket_no || ''}">${row.item_description || ''}</div>`;
                    let lineCount = parseInt(row.line_count || 1);
                    let metaHtml = `<div class="flex items-center flex-wrap gap-1.5 mt-0.5 min-h-[16px]">`;

                    if ((row._row_type || 'nonproject') === 'project') {
                        if (lineCount > 1) {
                            metaHtml += `<span class="text-[10px] text-gray-400">${lineCount} lines</span>`;
                        }
                    } else {
                        if (row.ticket_line_item) {
                            metaHtml += `<span class="text-[10px] text-gray-400">Line ${row.ticket_line_item}</span>`;
                        }
                    }

                    let aonStatus = row.aon_status || 'none';
                    if (aonStatus !== 'none' && aonStatus) {
                        let badgeCls = ''; let reasonCls = '';
                        if (aonStatus === 'green') { badgeCls = 'bg-green-100 text-green-700 border-green-300'; reasonCls = 'text-green-600'; }
                        else if (aonStatus === 'red') { badgeCls = 'bg-red-100 text-red-700 border-red-300'; reasonCls = 'text-red-600'; }
                        else if (aonStatus === 'yellow') { badgeCls = 'bg-yellow-100 text-yellow-800 border-yellow-300'; reasonCls = 'text-yellow-700'; }
                        // Grey = PO Created; no live contract warning left to show.
                        else if (aonStatus === 'gray') { badgeCls = 'bg-gray-100 text-gray-700 border-gray-300'; reasonCls = 'text-gray-600'; }

                        if (badgeCls) {
                            let safeReason = (row.aon_reason || '').replace(/"/g, '&quot;');
                            metaHtml += `<span class="inline-flex items-center text-[10px] font-bold px-1.5 rounded border leading-[15px] ${badgeCls}" title="${safeReason}">AON</span>`;
                            if (aonStatus === 'red' && row.aon_reason) {
                                metaHtml += `<span class="text-[10px] font-semibold leading-[15px] ${reasonCls}">${safeReason}</span>`;
                            }
                        }
                    }

                    metaHtml += `</div>`;
                    return html + metaHtml;
                }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm",
                render: function(data, type, row) {
                    // display_status already folds in the raw imported Ticket Status
                    // (OS/WL, OS, OP, PO, CCL, etc. -- see includes/pr_status_helper.php)
                    // alongside canvassing/PO presence, so this single badge reflects both.
                    let status = row.display_status || '';
                    if (type === 'filter') return status;
                    let abbr = row.status_abbr || status;
                    let tooltip = status;
                    let statusClass = 'bg-gray-100 text-gray-800 border-gray-200';
                    if (status === 'Outstanding') statusClass = 'bg-red-100 text-red-800 border-red-200';
                    if (status === 'Escalated')   statusClass = 'bg-orange-100 text-orange-800 border-orange-200';
                    if (status === 'Sendback')    statusClass = 'bg-purple-100 text-purple-800 border-purple-200';
                    if (status === 'Canvassing Cancelled') statusClass = 'bg-red-500 text-white border-red-600';
                    if (status === 'On Progress') statusClass = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                    if (status === 'PO Created')  statusClass = 'bg-green-100 text-green-800 border-green-200';
                    // "Outstanding/Worklist" is the longest label -- break it onto two lines
                    // instead of squeezing it onto one so the badge stays readable.
                    let abbrHtml = abbr === 'Outstanding/Worklist' ? 'Outstanding /<br>Worklist' : abbr;
                    return `<span class="${statusClass} py-1 px-2 rounded-md text-[10px] font-bold border inline-block w-full text-center leading-tight" title="${tooltip}">${abbrHtml}</span>`;
                }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm",
                render: function(data, type, row) {
                    let pic = row.assigned_pic || '';
                    let resolved = row.resolved_officer || '';
                    if (type === 'filter') return pic || resolved || 'Unassigned';
                    // Export (Excel/CSV/PDF): full name, not the first-name-only
                    // shorthand the on-screen button uses to stay compact.
                    if (type === 'export') return pic || resolved || 'Unassigned';
                    // Column shows the first name only to keep the row compact; the
                    // full name is still on the element via title/data-pic attributes.
                    let firstName = function(n) { return (n || '').trim().split(/\s+/)[0] || ''; };

                    let isProject = (row._row_type || 'nonproject') === 'project';
                    let isIntercompany = row.is_intercompany ? true : false;
                    let assignedByAi = parseInt(row.assigned_by_ai || 0);

                    // Read-only session: this cell is a label, not a control. Both
                    // branches below render a <button> that opens a reassign modal,
                    // and ajax_project_assign.php / the reassign POST would refuse a
                    // Viewer anyway — so the button would only ever produce a 403.
                    // Shaped like the intercompany case below, which has never been
                    // clickable, so the column still reads as part of the same table.

                    // Every branch below closes with the same "who last changed this
                    // PIC, and when" sub-line, so the provenance reads the same
                    // whether the cell is a button, a system label or a read-only span.
                    let stamp = picStampHtml(row);

                    if (IS_VIEWER) {
                        let shown = pic || resolved;
                        if (!shown) {
                            return `<span class="inline-flex items-center px-3 py-1.5 text-xs font-medium text-yellow-600 italic" title="Unassigned">Unassigned</span>` + stamp;
                        }
                        // Same provenance hints the buttons carry, so a Viewer can
                        // still tell a manual assignment from an automatic one.
                        let hint = '';
                        if (pic && !assignedByAi) {
                            hint = `<span class="ml-1 text-[10px] text-indigo-500 font-semibold">&middot; Manual</span>`;
                        } else if (!pic && resolved) {
                            hint = `<span class="ml-1 text-[9px] text-amber-500 font-bold uppercase tracking-wider">GP Officer</span>`;
                        } else if (isIntercompany) {
                            hint = `<span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span>`;
                        }
                        return `<span class="inline-flex items-center px-3 py-1.5 border border-gray-200 shadow-sm text-xs font-medium rounded text-gray-700 bg-gray-50" title="${shown}">
                                    <span class="font-semibold">${firstName(shown)}</span>${hint}
                                </span>` + stamp;
                    }

                    if (isProject) {
                        if (isIntercompany) {
                            return `<span class="inline-flex items-center px-3 py-1.5 border border-blue-200 shadow-sm text-xs font-medium rounded text-blue-800 bg-blue-50" title="${pic ? pic + ' — Routed via admin mappings / system rule' : 'Routed via admin mappings / system rule'}">
                                        <span class="font-semibold">${firstName(pic) || '--'}</span>
                                        <span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span>
                                    </span>` + stamp;
                        } else {
                            let btnHtml = `<button type="button" class="project-assign-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors" data-ticket="${row.ticket_no || ''}" data-pgroup="${row.p_group || ''}" data-officer="${pic}">`;
                            if (pic) {
                                btnHtml += `<span class="text-indigo-700 font-semibold" title="${pic}">${firstName(pic)} (Manual)</span>`;
                            } else if (resolved) {
                                btnHtml += `<span class="text-amber-700 font-semibold" title="${resolved}">${firstName(resolved)}</span>
                                            <span class="ml-1 text-[9px] text-amber-500 font-bold uppercase tracking-wider">GP Officer</span>`;
                            } else {
                                btnHtml += `<span class="text-yellow-600 italic">Unassigned</span>`;
                            }
                            btnHtml += `<svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg></button>`;
                            return btnHtml + stamp;
                        }
                    } else {
                        let btnHtml = `<button type="button" class="open-modal-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors" data-ticket="${row.ticket_no || ''}" data-line="${row.ticket_line_item || ''}" data-pic="${pic}">`;
                        if (pic) {
                            let textClass = !assignedByAi ? 'text-indigo-700 font-semibold' : '';
                            let textAppend = !assignedByAi ? ' (Manual)' : '';
                            btnHtml += `<span class="${textClass}" title="${pic}">${firstName(pic)}${textAppend}</span>`;
                        } else {
                            btnHtml += `<span class="text-yellow-600 italic">Unassigned</span>`;
                        }
                        btnHtml += `<svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg></button>`;
                        return btnHtml + stamp;
                    }
                }
            },
            {
                data: "requestor_name",
                className: "px-4 py-3 text-sm text-gray-700",
                render: function(data) { return data || ''; }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm text-gray-700",
                render: function(data, type, row) {
                    if (type === 'filter' || type === 'export') {
                        return row.canvassing_no || '';
                    }
                    let html = `<div>${row.canvassing_no || '--'}</div>`;
                    if (row.canvassing_date) {
                        let d = new Date(row.canvassing_date);
                        if (!isNaN(d.getTime())) {
                            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            let day = d.getDate().toString().padStart(2, '0');
                            html += `<div class="text-[10px] text-gray-400 mt-0.5">${day} ${months[d.getMonth()]} ${d.getFullYear()}</div>`;
                        }
                    }
                    return html;
                }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm text-gray-700",
                render: function(data, type, row) {
                    if (type === 'filter' || type === 'export') {
                        return row.po_number || '';
                    }
                    let html = `<div>${row.po_number || '-'}</div>`;
                    if (row.po_number && row.po_create_date) {
                        let d = new Date(row.po_create_date);
                        if (!isNaN(d.getTime())) {
                            const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                            let day = d.getDate().toString().padStart(2, '0');
                            html += `<div class="text-[10px] text-gray-400 mt-0.5">${day} ${months[d.getMonth()]} ${d.getFullYear()}</div>`;
                        }
                    }
                    return html;
                }
            },
            {
                data: null,
                className: "px-4 py-3 text-sm text-gray-700 text-right",
                render: function(data, type, row) {
                    // No PO yet means no line amount to show, regardless of any
                    // stray po_amount value on the row.
                    let amount = row.po_number ? (parseFloat(row.po_amount) || 0) : 0;
                    if (type === 'filter' || type === 'sort' || type === 'export') return amount;
                    return amount.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            },
            {
                data: "po_currency",
                className: "px-4 py-3 text-sm text-gray-700",
                render: function(data, type, row) {
                    return row.po_number ? (data || '') : '';
                }
            },
            {
                // Hidden helper column: gives export (Excel/CSV/PDF) a PR Date column of
                // its own, separate from Ticket No -- the visible Ticket No column keeps
                // showing both together, this one is never rendered on screen.
                data: null,
                visible: false,
                orderable: false,
                render: function(data, type, row) {
                    if (!row.pr_date) return '';
                    // ISO (YYYY-MM-DD): sorts/parses cleanly as a real date once opened
                    // in Excel/Sheets, unlike a "30 Jul 2026"-style text string.
                    if (type === 'export') return String(row.pr_date).slice(0, 10);
                    if (type !== 'display') return row.pr_date;
                    let d = new Date(row.pr_date);
                    if (isNaN(d.getTime())) return '';
                    const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                    let day = d.getDate().toString().padStart(2, '0');
                    return day + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
                }
            },
            {
                data: "p_org",
                title: "POrg",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "p_group",
                title: "PGroup",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "level_0",
                title: "L0",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "level_1",
                title: "L1",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "level_2",
                title: "L2",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "level_3",
                title: "L3",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "level_4",
                title: "L4",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            {
                data: "level_5",
                title: "L5",
                visible: false,
                orderable: false,
                render: function(data) { return data || ''; }
            },
            // Site Allocation: never rendered in the table, only searched. Keeping
            // it as a real column lets the toolbar dropdown reuse the same
            // column(n).search() path as Status/Requestor/Assigned PIC, which is
            // also what makes Clear All Filters reset it for free.
            { data: "site_allocation", title: "Site Allocation", defaultContent: "", visible: false },
            // Same trick as the PR Date helper above: export-only date columns so
            // Canvassing No / PO Number stay pure numbers in the file. Fixed at
            // indices 21/22 -- the export `cols` order array below references
            // them by number, so they must stay immediately after Site
            // Allocation (20) and before the Initial Quotation Price column.
            {
                data: null,
                title: "Canvassing Date",
                visible: false,
                orderable: false,
                render: function(data, type, row) {
                    if (!row.canvassing_date) return '';
                    return String(row.canvassing_date).slice(0, 10);
                }
            },
            {
                data: null,
                title: "PO Date",
                visible: false,
                orderable: false,
                render: function(data, type, row) {
                    if (!row.po_number || !row.po_create_date) return '';
                    return String(row.po_create_date).slice(0, 10);
                }
            },
            // Initial Quotation Price: appended last (index 23) so it never disturbs
            // main's fixed 21/22 indices above, or ajax_dashboard_table.php's
            // dt_search_field() switch, or the data-col="N" toolbar dropdowns
            // elsewhere on this page. Has its own <th> in the thead (last cell)
            // since it's visible.
            {
                data: null,
                title: "Initial Quotation Price",
                orderable: false,
                searchable: false,
                className: "px-4 py-3 text-sm text-center",
                render: function(data, type, row) {
                    if (type !== 'display') return '';
                    if (row.is_intercompany) {
                        return `<span class="inline-flex items-center px-3 py-1.5 border border-blue-200 shadow-sm text-xs font-medium rounded text-blue-800 bg-blue-50" title="Routed via admin mappings / system rule">
                                    <span class="text-blue-400 italic">N/A</span>
                                    <span class="ml-1 text-[10px] text-blue-500 font-semibold">&middot; System</span>
                                </span>`;
                    }
                    // GP Project prices a whole canvassing (nothing to open until the
                    // row has one); GP Non-Project prices per ticket -- see
                    // includes/quotation_modal_ui.php.
                    let lpqIsProject = (row._row_type || '') === 'project';
                    let lpqCanv = row.lpq_canvassing_no || '';
                    if (lpqIsProject && lpqCanv === '') {
                        return `<span class="inline-flex items-center px-3 py-1.5 border border-gray-200 text-xs font-medium rounded text-gray-400 bg-gray-50 cursor-not-allowed" title="GP Project quotation is entered per canvassing -- available once this ticket has a canvassing number">
                                    <span class="italic">Awaiting canvassing</span>
                                </span>`;
                    }
                    let lpqAttr = lpqIsProject ? `data-canvassing="${lpqCanv}"` : `data-ticket="${row.ticket_no || ''}"`;
                    let canvasPrice = row.lpq_canvas_price;
                    let itemCount = parseInt(row.lpq_item_count || 0);
                    let inner;
                    if (canvasPrice !== null && canvasPrice !== undefined) {
                        let curr = row.lpq_canvas_currency || 'IDR';
                        let symbol = LPQ_CURRENCY_SYMBOLS[curr] || curr;
                        let priceText = curr === 'IDR'
                            ? Math.round(canvasPrice).toLocaleString('id-ID')
                            : canvasPrice.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        inner = `<span class="text-indigo-700 font-semibold">${symbol} ${priceText}</span>
                                 <span class="ml-1 text-[9px] text-indigo-500 font-bold uppercase tracking-wider">Canvas</span>`;
                    } else if (itemCount > 0) {
                        let itemTotal = parseInt(row.lpq_item_total || 0);
                        inner = (!lpqIsProject && itemTotal > itemCount)
                            ? `<span class="text-indigo-700 font-semibold">${itemCount} of ${itemTotal} items priced</span>`
                            : `<span class="text-indigo-700 font-semibold">${itemCount} item${itemCount > 1 ? 's' : ''} priced</span>`;
                    } else {
                        inner = `<span class="text-gray-400 italic">Add quotation</span>`;
                    }
                    return `<button type="button" class="quotation-btn inline-flex items-center px-3 py-1.5 border border-gray-300 shadow-sm text-xs font-medium rounded text-gray-700 bg-white hover:bg-indigo-50 focus:outline-none transition-colors" ${lpqAttr}>${inner}<svg class="ml-2 h-3 w-3 text-gray-400" fill="currentColor" viewBox="0 0 20 20"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"></path></svg></button>`;
                }
            },
            // Helpdesk No.: appended last (index 24), after Initial Quotation Price, for
            // the same reason that column sits at 23 -- no fixed index above moves.
            // Plain text from K2's TICKET HELPDESK NO. column; the global search
            // haystack (ajax_dashboard_table.php + the client replica below) covers it.
            {
                data: null,
                title: "Helpdesk No.",
                orderable: false,
                searchable: false,
                className: "px-4 py-3 text-sm text-gray-700",
                render: function(data, type, row) {
                    var v = (row.ticket_helpdesk_no || '').toString().trim();
                    if (type === 'export' || type === 'filter') return v;
                    if (type !== 'display') return '';
                    return v === '' ? '<span class="text-gray-300">--</span>' : $('<div>').text(v).html();
                }
            }
        ],
        createdRow: function(row, data, dataIndex) {
            $(row).addClass('hover:bg-indigo-50 transition border-b border-gray-100');
            
            let isProject = (data._row_type || 'nonproject') === 'project';
            $(row).attr('data-row-type', isProject ? 'project' : 'nonproject');
            $(row).attr('data-group', data.project_group_number || '');
            $(row).attr('data-np-team', (data.np_team || data.team_code || '').toLowerCase());
            $(row).attr('data-assigned-by-ai', parseInt(data.assigned_by_ai || 0));
            $(row).attr('data-ticket-type', (data.ticket_type || '').toLowerCase());
            $(row).attr('data-aon', (data.aon_status || 'none').toLowerCase());
            $(row).attr('data-pr-date', (data.pr_date || '').slice(0, 10));
            
            if (isProject) {
                $(row).attr('data-pgroup', data.p_group || '');
            } else {
                $(row).attr('data-team', data.np_team || '');
                $(row).attr('data-assigned-pic', data.assigned_pic || '');
            }
        },
        "pageLength": 25,
        "order": [[ 1, "desc" ]],
        "dom": '<"hidden"B>rtip',
        "buttons": (function() {
            // Export order, not DOM order: the hidden date helpers (11 PR Date,
            // 21 Canvassing Date, 22 PO Date) sit right after the column they
            // belong to, so each number and its date land in separate cells.
            var cols = [1, 11, 2, 3, 4, 6, 5, 7, 21, 8, 22, 9, 10, 12, 13, 14, 15, 16, 17, 18, 19, 24]; // Ticket No, PR Date, SLA, Item Desc, Status, PIC, Requestor, Canvassing No, Canvassing Date, PO Number, PO Date, PO Line Amount, PO Currency Code, POrg, PGroup, L0, L1, L2, L3, L4, L5, Helpdesk No.
            return [
                { extend: 'excelHtml5', exportOptions: { columns: cols, orthogonal: 'export' } },
                { extend: 'csvHtml5', exportOptions: { columns: cols, orthogonal: 'export' } },
                { extend: 'pdfHtml5', orientation: 'landscape', exportOptions: { columns: cols, orthogonal: 'export' } }
            ];
        })(),
        "orderCellsTop": true,
        "deferRender": true,
        "columnDefs": [{ "orderable": false, "targets": 0 }]
    });

    // Rows are paginated by DataTables now — drop the table loading skeleton.
    $('.viz-table-loader').fadeOut(200);

    // --- Export (Excel / CSV / PDF) -- mirrors sla_report.php's export menu.
    // Whatever's currently active (toolbar quick-filters + column/global search)
    // rides along automatically since it's the same `table` instance/ajax call.
    $('#prExportBtn').on('click', function(e) {
        e.stopPropagation();
        $('#prExportMenu').toggleClass('hidden');
        $('#prExportChevron').toggleClass('rotate-180');
    });
    $(document).on('click', function(e) {
        if (!$(e.target).closest('#prExportDropdownContainer').length) {
            $('#prExportMenu').addClass('hidden');
            $('#prExportChevron').removeClass('rotate-180');
        }
    });
    function triggerPrExport(type) {
        if ($('#prExportAllPages').is(':checked')) {
            var oldLen = table.page.len();
            table.one('draw', function() {
                table.button('.buttons-' + type).trigger();
                table.page.len(oldLen).draw();
            });
            table.page.len(-1).draw();
        } else {
            table.button('.buttons-' + type).trigger();
        }
        $('#prExportMenu').addClass('hidden');
        $('#prExportChevron').removeClass('rotate-180');
    }
    $('#prExportExcel').on('click', function() { triggerPrExport('excel'); });
    $('#prExportCSV').on('click', function() { triggerPrExport('csv'); });
    $('#prExportPDF').on('click', function() { triggerPrExport('pdf'); });

    // Define custom search function
    $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
        var row = $(settings.aoData[dataIndex].nTr);

        // Status is column 7 (0 = line-select checkbox, 6 = Canvassing No).
        if (!rowMatchesStatusFilters({
            display_status: data[7] ? data[7].trim() : '',
            assigned_by_ai: parseInt(row.attr('data-assigned-by-ai'), 10),
            assigned_pic: row.attr('data-assigned-pic') || ''
        })) {
            return false;
        }

        // 2. Type Filter
        var typeVal = (row.attr('data-ticket-type') || '').toLowerCase().trim();
        var isIntercompany = (typeVal === 'intercompany' || typeVal === 'intercompany new store');
        if (currentTypeFilter === 'intercompany') {
            if (!isIntercompany) {
                return false;
            }
        } else if (currentTypeFilter === 'non-intercompany') {
            if (isIntercompany) {
                return false;
            }
        } else if (currentTypeFilter === 'aon') {
            var aonVal = (row.attr('data-aon') || '').toLowerCase().trim();
            if (aonVal === '' || aonVal === 'none') {
                return false;
            }
            // Optional narrowing to a single AON label color.
            if (currentAonLabel && aonVal !== currentAonLabel) {
                return false;
            }
        }

        // 2b. Team filter (Non-Project team acronyms + Project group numbers)
        if (currentTeamFilter.indexOf('np:') === 0) {
            var wantTeam = currentTeamFilter.slice(3);
            if ((row.attr('data-row-type') || '') !== 'nonproject') {
                return false;
            }
            var assignedPic = row.attr('data-assigned-pic') || '';
            var assignedPicKey = assignedPic.trim().toLowerCase();

            // Find which team the assigned PIC belongs to, if any. Compared
            // lowercase/trimmed -- assigned_pic can drift in case from users.name.
            var picActualTeam = null;
            for (var t in npPicsByTeam) {
                var pics = npPicsByTeam[t] || [];
                for (var pi = 0; pi < pics.length; pi++) {
                    if ((pics[pi] || '').trim().toLowerCase() === assignedPicKey) {
                        picActualTeam = t;
                        break;
                    }
                }
                if (picActualTeam) break;
            }

            if (picActualTeam) {
                // If the PIC is mapped to a team, the row belongs ONLY to that team
                if (picActualTeam !== wantTeam) {
                    return false;
                }
            } else {
                // PIC is not mapped to any specific team (e.g. 'Unassigned', '', or an acronym like 'CSGSM')
                // So it belongs to wantTeam if assignedPic == wantTeam OR data-team == wantTeam
                if (assignedPic !== wantTeam && (row.attr('data-team') || '') !== wantTeam) {
                    return false;
                }
            }
        } else if (currentTeamFilter) {
            if ((row.attr('data-group') || '') !== currentTeamFilter) {
                return false;
            }
        }

        return true;
    });

    // PO Created rows and Done-SLA rows are the same "done" bucket the workload
    // charts hide by default (see includeDoneInCharts below) -- so filtering the
    // table down to just those rows would otherwise leave the charts empty.
    // Force Include Done on whenever either filter lands on that bucket.
    function syncIncludeDoneWithFilters() {
        if ((statusFilterRealStatuses().indexOf('PO Created') !== -1 || currentSlaFilter === 'done') && !includeDoneInCharts) {
            includeDoneInCharts = true;
            $('#includeDoneToggleBtn')
                .addClass('bg-gray-700 hover:bg-gray-800 text-white border-gray-700')
                .removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        }
    }

    // Styles buttons from currentStatusFilters -- reused wherever state is set programmatically.
    var STATUS_FILTER_STYLE = {
        '':                'bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600',
        'Outstanding':     'bg-red-600 hover:bg-red-700 text-white border-red-600',
        'On Progress':     'bg-yellow-500 hover:bg-yellow-600 text-white border-yellow-500',
        'PO Created':      'bg-green-600 hover:bg-green-700 text-white border-green-600',
        'manual-only':     'bg-violet-600 hover:bg-violet-700 text-white border-violet-600',
        'unassigned-only': 'bg-pink-600 hover:bg-pink-700 text-white border-pink-600'
    };
    function renderStatusFilterButtons() {
        var isAll = !statusFilterRealStatuses().length;
        $('.status-filter-btn').each(function() {
            var st = $(this).data('status');
            var fl = $(this).data('filter');
            var isAllBtn = (fl === undefined && (st || '') === '');
            var key = fl || st || '';
            var active = isAllBtn ? isAll : (currentStatusFilters.indexOf(key) !== -1);
            $(this).removeClass('bg-indigo-600 bg-red-600 bg-yellow-500 bg-green-600 bg-violet-600 bg-pink-600 hover:bg-indigo-700 hover:bg-red-700 hover:bg-yellow-600 hover:bg-green-700 hover:bg-violet-700 hover:bg-pink-700 text-white border-indigo-600 border-red-600 border-yellow-500 border-green-600 border-violet-600 border-pink-600 shadow-md ring-2 ring-offset-2')
                .addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
            if (active) {
                $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
                    .addClass((STATUS_FILTER_STYLE[isAllBtn ? '' : key] || STATUS_FILTER_STYLE['']) + ' shadow-md ring-2 ring-offset-2');
            }
        });
    }

    $('.status-filter-btn').on('click', function() {
        if (!slaAlertApplying) slaAlertExact = false;   // a filter picked by hand folds 'na' as usual

        var status = $(this).data('status');
        var filter = $(this).data('filter');
        var isAllBtn = (filter === undefined && (status || '') === '');
        var key = filter || status || '';

        if (isAllBtn) {
            // All Statuses clears the status picks but keeps Manual / Unassigned (they AND on top).
            currentStatusFilters = currentStatusFilters.filter(function(v) { return ASSIGNMENT_FILTERS.indexOf(v) !== -1; });
        } else {
            var idx = currentStatusFilters.indexOf(key);
            if (idx !== -1) {
                currentStatusFilters.splice(idx, 1);
            } else {
                // Manual and Unassigned are one filter type: picking one drops the other.
                if (ASSIGNMENT_FILTERS.indexOf(key) !== -1) {
                    currentStatusFilters = statusFilterRealStatuses();
                }
                currentStatusFilters.push(key);
            }
        }
        renderStatusFilterButtons();
        syncIncludeDoneWithFilters();
        if (slaAlertApplying) return;   // the pop-up redraws once, after both filters are set
        table.draw();
        renderCharts();
    });

    $('.type-filter-btn').on('click', function() {
        $('.type-filter-btn').removeClass('bg-indigo-600 bg-teal-600 bg-blue-600 bg-amber-600 hover:bg-indigo-700 hover:bg-teal-700 hover:bg-blue-700 hover:bg-amber-700 text-white border-indigo-600 border-teal-600 border-blue-600 border-amber-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');

        var type = $(this).data('type');
        currentTypeFilter = type || '';

        // Leaving AON hides the label sub-filter and drops any label narrowing.
        if (type !== 'aon') {
            $('#aonLabelFilter').addClass('hidden');
            resetAonLabelButtons();
        }

        if (type === 'intercompany') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-teal-600 hover:bg-teal-700 text-white border-teal-600 shadow-md ring-2 ring-offset-2');
        } else if (type === 'non-intercompany') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-blue-600 hover:bg-blue-700 text-white border-blue-600 shadow-md ring-2 ring-offset-2');
        } else if (type === 'aon') {
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-amber-600 hover:bg-amber-700 text-white border-amber-600 shadow-md ring-2 ring-offset-2');
            $('#aonLabelFilter').removeClass('hidden');
        } else {
            currentTypeFilter = '';
            $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        }
        table.draw();
        renderCharts();
    });

    // ── AON label sub-filter (green / yellow / red color swatches) ───────────
    // Only reachable while the AON type filter is active. No selection = all AON
    // labels; selecting one dims the others so the active choice is obvious.
    var AON_LABEL_RING = {
        'green'  : 'ring-green-500',
        'yellow' : 'ring-yellow-500',
        'red'    : 'ring-red-500',
        'gray'   : 'ring-gray-500'
    };
    function paintAonLabelButtons(label) {
        var $all = $('.aon-label-btn');
        $all.removeClass('ring-2 ring-offset-1 ring-green-600 ring-yellow-600 ring-red-600 opacity-40');
        if (!label) return;                       // nothing selected → all shown at full strength
        $all.addClass('opacity-40');              // dim the ones not chosen
        $('.aon-label-btn[data-aon-label="' + label + '"]')
            .removeClass('opacity-40')
            .addClass('ring-2 ring-offset-1 ' + (AON_LABEL_RING[label] || ''));
    }
    function resetAonLabelButtons() {
        currentAonLabel = '';
        paintAonLabelButtons('');
    }
    $('.aon-label-btn').on('click', function() {
        var lbl = $(this).attr('data-aon-label') || '';
        // Clicking the already-active swatch clears the narrowing (back to all AON).
        currentAonLabel = (currentAonLabel === lbl) ? '' : lbl;
        paintAonLabelButtons(currentAonLabel);
        table.draw();
        renderCharts();
    });

    // -------------------------------------------------------------------------
    // Team Filter UI
    // -------------------------------------------------------------------------
    $('#teamFilterButtonsContainer').on('click', '.team-filter-btn', function() {
        $('.team-filter-btn').removeClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2').addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50').addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');
        currentTeamFilter = $(this).data('teamcode') || '';
        
        // Switching (or clearing back to "All Teams") must also drop any PIC
        // drilled into via the PIC chart and resync the charts' own drill-down
        // state -- otherwise the "Assigned PIC" column search from whichever
        // person was clicked before stays applied (table stays stuck on that
        // one person), and clicking a different team button can surface rows
        // belonging to that stale PIC's real team instead.
        clearPicTableFilter();
        chartTeamFilter = currentTeamFilter ? $(this).text().trim() : null;
        forceShowAllPics = false;
        showAllBtn.addClass('hidden');

        table.draw();
        renderCharts();
    });

    // SLA quick filter -- button colors mirror the row badge colors from
    // sla_rules.php::sla_evaluate() (green/yellow/red/gray). 'na' rows (no PR
    // date, countdown never started) are folded into Overdue -- same rule as
    // the workload chart's slaBucket() -- so there's no separate N/A button.
    // 'done' rows (PO already created) get their own gray button instead.
    var SLA_FILTER_ACTIVE_CLASSES = 'bg-indigo-600 bg-green-600 bg-yellow-500 bg-red-600 bg-gray-500 ' +
        'hover:bg-indigo-700 hover:bg-green-700 hover:bg-yellow-600 hover:bg-red-700 hover:bg-gray-600 ' +
        'text-white border-indigo-600 border-green-600 border-yellow-500 border-red-600 border-gray-500 ' +
        'shadow-md ring-2 ring-offset-2';
    var SLA_FILTER_STYLE = {
        '':         'bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600',
        'on_track': 'bg-green-600 hover:bg-green-700 text-white border-green-600',
        'due_soon': 'bg-yellow-500 hover:bg-yellow-600 text-white border-yellow-500',
        'overdue':  'bg-red-600 hover:bg-red-700 text-white border-red-600',
        'done':     'bg-gray-500 hover:bg-gray-600 text-white border-gray-500'
    };
    $('.sla-filter-btn').on('click', function() {
        if (!slaAlertApplying) slaAlertExact = false;
        var sla = $(this).data('sla') || '';
        $('.sla-filter-btn').removeClass(SLA_FILTER_ACTIVE_CLASSES).addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        currentSlaFilter = sla;
        $(this).removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
            .addClass((SLA_FILTER_STYLE[sla] || SLA_FILTER_STYLE['']) + ' shadow-md ring-2 ring-offset-2');
        syncIncludeDoneWithFilters();
        if (slaAlertApplying) return;   // the pop-up redraws once, after both filters are set
        table.draw();
        renderCharts();
    });

    // Sorot tombol sesuai default yang sudah disetel -- murni kosmetik.
    renderStatusFilterButtons();
    if (IS_VIEWER) {
        slaAlertApplying = true;
        $('.sla-filter-btn[data-sla="overdue"]').trigger('click');
        slaAlertApplying = false;
    }

    // --- SLA pop-up hand-off ---------------------------------------------
    // Called when one of the four blocks in the pop-up is clicked
    // (includes/sla_alert.php). Reuses the page's own quick-filter buttons
    // rather than a private filter of its own, so the toolbar visibly shows
    // what the table is narrowed to and the usual "All Statuses" / "All SLA"
    // buttons clear it. #prTable is serverSide, so both filters are set under
    // slaAlertApplying and the single draw() below is the only request made.
    // Replaces the whole multi-select rather than toggling.
    window.slaAlertApplyFilter = function(status, sla) {
        slaAlertApplying = true;
        currentStatusFilters = status ? [status] : [];
        renderStatusFilterButtons();
        syncIncludeDoneWithFilters();
        $('.sla-filter-btn[data-sla="' + sla + '"]').trigger('click');
        slaAlertApplying = false;
        slaAlertExact = true;                  // ask for the exact bucket -- see above
        table.draw();
        renderCharts();
        // The scroll container on this layout is <main>, not the window
        // (includes/header.php puts overflow-hidden on <body>), so scrolling
        // html/body here would do nothing.
        var $t = $('#prTable'), $scroller = $t.closest('main');
        if ($scroller.length) {
            $scroller.animate({ scrollTop: $scroller.scrollTop() + $t.offset().top - $scroller.offset().top - 16 }, 250);
        }
    };
    // The dashboard is built and interactive -- the true "page has finished
    // loading" moment, so let the pop-up hand its cue over now.
    if (window.slaAlert) window.slaAlert.ready();

    // Debounced so typing doesn't fire an ajax_dashboard_table.php request on
    // every keystroke now that each draw() is a real network round trip.
    function debounce(fn, wait) {
        var t = null;
        return function() {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function() { fn.apply(ctx, args); }, wait);
        };
    }

    $('.column-search-text').on('keyup change clear', debounce(function(e) {
        e.stopPropagation();
        table.column($(this).data('col')).search(this.value).draw();
        renderCharts();
    }, 350));

    // --- PR Date range picker -------------------------------------------------
    // Self-contained range calendar behind a single trigger. Picking days only
    // moves a DRAFT range; nothing reaches currentPrDateFrom/currentPrDateTo (and
    // therefore no redraw fires) until "Terapkan" -- so choosing a start date
    // never briefly filters the table down to one day on the way to the end date.
    var PR_MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var PR_MONTHS_SHORT = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    var PR_DOW = ['Sen','Sel','Rab','Kam','Jum','Sab','Min'];   // week starts Monday
    var prDraftFrom = '', prDraftTo = '';
    var prCalYear, prCalMonth;
    var prToday = (function() {
        var n = new Date();
        return prIso(n.getFullYear(), n.getMonth(), n.getDate());
    })();

    function prIso(y, m, d) {
        return y + '-' + String(m + 1).padStart(2, '0') + '-' + String(d).padStart(2, '0');
    }
    // 'YYYY-MM-DD' -> '16 Jul 2026'. Parsed by hand rather than via new Date(iso),
    // which would read the string as UTC and can shift the day in WIB.
    function prPretty(iso) {
        var p = iso.split('-');
        return parseInt(p[2], 10) + ' ' + PR_MONTHS_SHORT[parseInt(p[1], 10) - 1] + ' ' + p[0];
    }
    function prSyncLabel() {
        var $l = $('#prDateLabel');
        if (!currentPrDateFrom && !currentPrDateTo) { $l.text('All dates'); return; }
        if (currentPrDateFrom === currentPrDateTo) { $l.text(prPretty(currentPrDateFrom)); return; }
        $l.text(prPretty(currentPrDateFrom) + ' – ' + prPretty(currentPrDateTo));
    }

    function prRenderCalendar() {
        $('#prCalTitle').text(PR_MONTHS[prCalMonth] + ' ' + prCalYear);

        var html = '';
        PR_DOW.forEach(function(d) { html += '<div class="pr-cal-dow">' + d + '</div>'; });

        var firstDow  = (new Date(prCalYear, prCalMonth, 1).getDay() + 6) % 7;  // Mon = 0
        var daysInMon = new Date(prCalYear, prCalMonth + 1, 0).getDate();
        var daysInPrv = new Date(prCalYear, prCalMonth, 0).getDate();
        var lo = prDraftFrom, hi = prDraftTo || prDraftFrom;

        for (var i = 0; i < 42; i++) {
            var dayNum, outside = true, iso = '';
            if (i < firstDow) {
                dayNum = daysInPrv - firstDow + 1 + i;
            } else if (i - firstDow < daysInMon) {
                dayNum = i - firstDow + 1;
                outside = false;
                iso = prIso(prCalYear, prCalMonth, dayNum);
            } else {
                dayNum = i - firstDow - daysInMon + 1;
            }

            var cls = 'pr-cal-cell';
            if (outside) {
                cls += ' is-outside';
            } else {
                if (iso === prToday) cls += ' is-today';
                if (lo && iso === lo && iso === hi)      cls += ' is-edge is-single';
                else if (lo && iso === lo)               cls += ' is-edge is-range-start';
                else if (hi && iso === hi)               cls += ' is-edge is-range-end';
                else if (lo && hi && iso > lo && iso < hi) {
                    cls += ' is-inrange';
                    if (i % 7 === 0) cls += ' row-start';
                    if (i % 7 === 6) cls += ' row-end';
                }
            }
            html += '<div class="' + cls + '"' + (outside ? '' : ' data-iso="' + iso + '"') + '><span>' + dayNum + '</span></div>';
        }
        $('#prCalGrid').html(html);
    }

    function prOpenPicker() {
        prDraftFrom = currentPrDateFrom;
        prDraftTo   = currentPrDateTo;
        var anchor = currentPrDateFrom || prToday;
        prCalYear  = parseInt(anchor.split('-')[0], 10);
        prCalMonth = parseInt(anchor.split('-')[1], 10) - 1;
        prRenderCalendar();
        $('#prDatePopup').removeClass('hidden');
    }

    $('#prDateTrigger').on('click', function(e) {
        e.stopPropagation();
        $('.filter-dropdown').addClass('hidden');
        $('#poDatePopup').addClass('hidden');
        if ($('#prDatePopup').hasClass('hidden')) prOpenPicker();
        else $('#prDatePopup').addClass('hidden');
    });
    $('#prDatePopup').on('click', function(e) { e.stopPropagation(); });
    $(document).on('click', function() { $('#prDatePopup').addClass('hidden'); });
    // Opening a column dropdown closes the calendar, mirroring how those close
    // each other.
    $('.filter-trigger').on('click', function() { $('#prDatePopup').addClass('hidden'); });

    $('.pr-cal-nav').on('click', function() {
        prCalMonth += parseInt($(this).data('step'), 10);
        if (prCalMonth < 0)  { prCalMonth = 11; prCalYear--; }
        if (prCalMonth > 11) { prCalMonth = 0;  prCalYear++; }
        prRenderCalendar();
    });

    $('#prCalGrid').on('click', '.pr-cal-cell[data-iso]', function() {
        var iso = $(this).data('iso');
        if (!prDraftFrom || prDraftTo) {            // start a fresh range
            prDraftFrom = iso; prDraftTo = '';
        } else if (iso < prDraftFrom) {             // clicked before the start -- swap
            prDraftTo = prDraftFrom; prDraftFrom = iso;
        } else {
            prDraftTo = iso;
        }
        prRenderCalendar();
    });

    $('#prDateApply').on('click', function() {
        currentPrDateFrom = prDraftFrom;
        // One tap = that single day, so the range stays inclusive on both ends.
        currentPrDateTo   = prDraftTo || prDraftFrom;
        prSyncLabel();
        $('#prDatePopup').addClass('hidden');
        table.draw();
        renderCharts();
    });

    $('#prDateCancel').on('click', function() {
        $('#prDatePopup').addClass('hidden');
    });

    // --- PO Date range picker -- same widget as PR Date above, with its own
    // state and DOM ids. Filtered server-side via gf.poDateFrom/poDateTo.
    var poDraftFrom = '', poDraftTo = '';
    var poCalYear, poCalMonth;

    function poSyncLabel() {
        var $l = $('#poDateLabel');
        if (!currentPoDateFrom && !currentPoDateTo) { $l.text('All dates'); return; }
        if (currentPoDateFrom === currentPoDateTo) { $l.text(prPretty(currentPoDateFrom)); return; }
        $l.text(prPretty(currentPoDateFrom) + ' – ' + prPretty(currentPoDateTo));
    }

    function poRenderCalendar() {
        $('#poCalTitle').text(PR_MONTHS[poCalMonth] + ' ' + poCalYear);

        var html = '';
        PR_DOW.forEach(function(d) { html += '<div class="pr-cal-dow">' + d + '</div>'; });

        var firstDow  = (new Date(poCalYear, poCalMonth, 1).getDay() + 6) % 7;  // Mon = 0
        var daysInMon = new Date(poCalYear, poCalMonth + 1, 0).getDate();
        var daysInPrv = new Date(poCalYear, poCalMonth, 0).getDate();
        var lo = poDraftFrom, hi = poDraftTo || poDraftFrom;

        for (var i = 0; i < 42; i++) {
            var dayNum, outside = true, iso = '';
            if (i < firstDow) {
                dayNum = daysInPrv - firstDow + 1 + i;
            } else if (i - firstDow < daysInMon) {
                dayNum = i - firstDow + 1;
                outside = false;
                iso = prIso(poCalYear, poCalMonth, dayNum);
            } else {
                dayNum = i - firstDow - daysInMon + 1;
            }

            var cls = 'pr-cal-cell';
            if (outside) {
                cls += ' is-outside';
            } else {
                if (iso === prToday) cls += ' is-today';
                if (lo && iso === lo && iso === hi)      cls += ' is-edge is-single';
                else if (lo && iso === lo)               cls += ' is-edge is-range-start';
                else if (hi && iso === hi)               cls += ' is-edge is-range-end';
                else if (lo && hi && iso > lo && iso < hi) {
                    cls += ' is-inrange';
                    if (i % 7 === 0) cls += ' row-start';
                    if (i % 7 === 6) cls += ' row-end';
                }
            }
            html += '<div class="' + cls + '"' + (outside ? '' : ' data-iso="' + iso + '"') + '><span>' + dayNum + '</span></div>';
        }
        $('#poCalGrid').html(html);
    }

    function poOpenPicker() {
        poDraftFrom = currentPoDateFrom;
        poDraftTo   = currentPoDateTo;
        var anchor = currentPoDateFrom || prToday;
        poCalYear  = parseInt(anchor.split('-')[0], 10);
        poCalMonth = parseInt(anchor.split('-')[1], 10) - 1;
        poRenderCalendar();
        $('#poDatePopup').removeClass('hidden');
    }

    $('#poDateTrigger').on('click', function(e) {
        e.stopPropagation();
        $('.filter-dropdown').addClass('hidden');
        $('#prDatePopup').addClass('hidden');
        if ($('#poDatePopup').hasClass('hidden')) poOpenPicker();
        else $('#poDatePopup').addClass('hidden');
    });
    $('#poDatePopup').on('click', function(e) { e.stopPropagation(); });
    $(document).on('click', function() { $('#poDatePopup').addClass('hidden'); });
    $('.filter-trigger').on('click', function() { $('#poDatePopup').addClass('hidden'); });

    $('.po-cal-nav').on('click', function() {
        poCalMonth += parseInt($(this).data('step'), 10);
        if (poCalMonth < 0)  { poCalMonth = 11; poCalYear--; }
        if (poCalMonth > 11) { poCalMonth = 0;  poCalYear++; }
        poRenderCalendar();
    });

    $('#poCalGrid').on('click', '.pr-cal-cell[data-iso]', function() {
        var iso = $(this).data('iso');
        if (!poDraftFrom || poDraftTo) {            // start a fresh range
            poDraftFrom = iso; poDraftTo = '';
        } else if (iso < poDraftFrom) {             // clicked before the start -- swap
            poDraftTo = poDraftFrom; poDraftFrom = iso;
        } else {
            poDraftTo = iso;
        }
        poRenderCalendar();
    });

    $('#poDateApply').on('click', function() {
        currentPoDateFrom = poDraftFrom;
        // One tap = that single day, so the range stays inclusive on both ends.
        currentPoDateTo   = poDraftTo || poDraftFrom;
        poSyncLabel();
        $('#poDatePopup').addClass('hidden');
        table.draw();
    });

    $('#poDateCancel').on('click', function() {
        $('#poDatePopup').addClass('hidden');
    });

    // --- Clear ALL toolbar filters -------------------------------------------
    // Everything here is applied directly to state vars / the DataTables API
    // and drawn once at the end, instead of chaining synthetic clicks through
    // each individual control's own handler (fragile -- one broken handler in
    // the chain would silently stop the rest from resetting).
    $('#clearAllFiltersBtn').on('click', function() {
        // Status / Type / Team quick filters
        currentStatusFilters = [];
        currentTypeFilter = '';
        currentAonLabel = '';
        currentTeamFilter = '';
        currentSlaFilter = '';
        slaAlertExact = false;

        renderStatusFilterButtons();

        var ACTIVE_CLASSES = 'bg-indigo-600 bg-teal-600 bg-blue-600 bg-amber-600 hover:bg-indigo-700 ' +
            'hover:bg-teal-700 hover:bg-blue-700 hover:bg-amber-700 ' +
            'text-white border-indigo-600 border-teal-600 border-blue-600 border-amber-600 shadow-md ring-2 ring-offset-2';
        $('.type-filter-btn, .team-filter-btn')
            .removeClass(ACTIVE_CLASSES).addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $('.type-filter-btn[data-type=""], .team-filter-btn[data-teamcode=""]')
            .removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
            .addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');

        $('.sla-filter-btn').removeClass(SLA_FILTER_ACTIVE_CLASSES).addClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50');
        $('.sla-filter-btn[data-sla=""]')
            .removeClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50')
            .addClass('bg-indigo-600 hover:bg-indigo-700 text-white border-indigo-600 shadow-md ring-2 ring-offset-2');

        $('#aonLabelFilter').addClass('hidden');
        resetAonLabelButtons();

        // Dropdown column filters (Status / Requestor / Assigned PIC)
        $('.filter-dropdown').each(function() {
            var colIdx = $(this).find('.apply-filter').data('col');
            $(this).find('.col-checkbox, .col-filter-checkbox, .select-all-checkbox').prop('checked', false);
            $(this).find('.dropdown-search').val('');
            if (colIdx !== undefined) table.column(colIdx).search('', false, false);
            $(this).addClass('hidden');
        });
        $('.col-trigger-label').text('All');

        // Free-text column filters (Ticket No / Item Description / Canvassing No / PO Number)
        $('.column-search-text').val('');
        [1, 3, 7, 8].forEach(function(colIdx) { table.column(colIdx).search(''); });

        // PR Date range (table's own picker + the chart's PR Date filter, kept in sync)
        currentPrDateFrom = '';
        currentPrDateTo = '';
        prDraftFrom = '';
        prDraftTo = '';
        prSyncLabel();
        $('#prDatePopup').addClass('hidden');
        currentPoDateFrom = '';
        currentPoDateTo = '';
        poDraftFrom = '';
        poDraftTo = '';
        poSyncLabel();
        $('#poDatePopup').addClass('hidden');
        lastConfirmedDateFilter = 'all';
        currentWlDateFrom = '';
        currentWlDateTo = '';
        dateFilterSelect.val('all');
        $('#wlDatePopup').addClass('hidden');

        // Global search
        $('#globalSearch').val('');
        table.search('');

        table.draw();
        renderCharts();
    });

    // --- TOOLBAR: global search (drives DataTables' built-in search) ---
    $('#globalSearch').on('keyup change', debounce(function() {
        table.search(this.value).draw();
        renderCharts();
    }, 350));

    $('.filter-trigger').on('click', function(e) {
        e.stopPropagation();
        var $dropdown = $(this).next('.filter-dropdown');
        $('.filter-dropdown').not($dropdown).addClass('hidden');
        $dropdown.toggleClass('hidden');
    });

    $(document).on('click', function() { $('.filter-dropdown').addClass('hidden'); });
    $('.filter-dropdown').on('click', function(e) { e.stopPropagation(); });

    $('.dropdown-search').on('keyup', function() {
        var searchTerm = $(this).val().toLowerCase();
        var $list = $(this).closest('.filter-dropdown').find('.dropdown-items label');
        $list.each(function() {
            var text = $(this).text().toLowerCase();
            $(this).toggle(text.indexOf(searchTerm) > -1);
        });
    });

    $('.select-all-checkbox').on('change', function() {
        var isChecked = $(this).prop('checked');
        $(this).closest('.filter-dropdown').find('.dropdown-items label:visible .col-filter-checkbox').prop('checked', isChecked);
    });

    $('.apply-filter').on('click', function() {
        var colIdx = $(this).data('col');
        var $dropdown = $(this).closest('.filter-dropdown');
        var selected = [];
        $dropdown.find('.col-filter-checkbox:checked').each(function() { selected.push($.fn.dataTable.util.escapeRegex($(this).val())); });

        var $triggerLabel = $(this).closest('.col-filter').find('.col-trigger-label');
        if (selected.length === 0) {
            table.column(colIdx).search('', false, false).draw();
            if ($triggerLabel.length) $triggerLabel.text('All');
        } else {
            table.column(colIdx).search('^(' + selected.join('|') + ')$', true, false).draw();
            if ($triggerLabel.length) $triggerLabel.text(selected.length === 1 ? '1 Selected' : selected.length + ' Selected');
        }
        renderCharts();
        $dropdown.addClass('hidden');
    });

    $('.clear-filter').on('click', function() {
        var $dropdown = $(this).closest('.filter-dropdown');
        var colIdx = $dropdown.find('.apply-filter').data('col');
        $dropdown.find('.col-filter-checkbox, .select-all-checkbox').prop('checked', false);
        $dropdown.find('.dropdown-search').val('').trigger('keyup');
        table.column(colIdx).search('', false, false).draw();
        renderCharts();

        var $triggerLabel = $(this).closest('.col-filter').find('.col-trigger-label');
        if ($triggerLabel.length) $triggerLabel.text('All');

        $dropdown.addClass('hidden');
    });

    // Note: the old per-draw "dim out dropdown options with zero matching rows"
    // dependency narrowing was removed here -- under serverSide mode the client
    // only ever holds the current PAGE of rows, so it could no longer tell
    // which options exist across the whole filtered set without a dedicated
    // endpoint. The dropdown lists themselves (populated in loadDashboardMeta())
    // still show every distinct value across the full persona-scoped dataset.

    // Copy Ticket No to clipboard
    $('#prTable tbody').on('click', '.ticket-no-cell', function(e) {
        // Prevent click from triggering row selection
        if ($(e.target).closest('input[type="checkbox"]').length) return;
        
        var ticketNo = $(this).find('.ticket-no-text').text().trim();
        var $toast = $(this).find('.copy-toast');
        
        navigator.clipboard.writeText(ticketNo).then(function() {
            $toast.removeClass('opacity-0').addClass('opacity-100 -translate-y-2');
            setTimeout(function() {
                $toast.removeClass('opacity-100 -translate-y-2').addClass('opacity-0');
            }, 1500);
        });
    });

    const modal = $('#reassignModal');
    // Pending reassignment: [{ticket: '...', line: '...'}, ...]. A selection may
    // span several tickets; every entry is submitted as its own (ticket, line) pair.
    var reassignSelection = [];

    // Selected lines past ROW_CAP: no checkbox exists for them, so they are
    // carried separately and merged back into every rebuild.
    var detailUnlistedSelection = [];
    var detailRowsTotal = 0;
    // Checked state from just before the whole-ticket switch forced everything on.
    var detailPreAllState = null;

    // Always opens on "-- Set to Unassigned --", never the line's current PIC, so
    // the new PIC stays a deliberate pick. The dropdown is pre-populated in HTML.
    function populateReassignPicPool(teamCode, currentPic) {
        var $sel = $('#modal-pic-select');
        $sel.val('');
    }

    // Distinct ticket numbers within a selection, in first-seen order.
    function selectionTickets(pairs) {
        var seen = {}, out = [];
        (pairs || []).forEach(function(p) {
            if (!seen[p.ticket]) { seen[p.ticket] = true; out.push(p.ticket); }
        });
        return out;
    }

    // Header line, hidden inputs and Save state, redrawn from the live selection.
    function renderReassignSelectionState() {
        var tickets = selectionTickets(reassignSelection);
        var n = reassignSelection.length;

        // Built as nodes so a ticket number is never parsed as markup.
        var $target = $('#modal-target-line').empty();
        var $strong = $('<strong>').addClass('text-indigo-700 font-semibold');
        $strong.text(tickets.length === 0 ? 'No ticket'
                   : (tickets.length === 1 ? tickets[0] : (tickets.length + ' tickets')));
        $target.append($strong)
               .append(document.createTextNode(' · ' + n + ' line item' + (n === 1 ? '' : 's') + ' selected'));

        // Mirrored into hidden inputs for the no-JS form-post fallback.
        var $container = $('#modal-lines-container').empty();
        reassignSelection.forEach(function(p) {
            $('<input>').attr({type: 'hidden', name: 'reassign_items[]'})
                        .val(p.ticket + '|' + p.line)
                        .appendTo($container);
        });

        // The server rejects an empty selection anyway; block it here instead.
        modal.find('button[type="submit"]')
             .prop('disabled', n === 0)
             .toggleClass('opacity-50 cursor-not-allowed', n === 0);
    }

    function refreshDetailCounts() {
        var sel = reassignSelection.length;
        var others = Math.max(detailRowsTotal - sel, 0);
        if (detailRowsTotal > 0) {
            $('#modal-detail-count').text(detailRowsTotal + ' line item(s) · ' + sel + ' selected · ' + others + ' not selected');
        }

        var tickets = selectionTickets(reassignSelection);
        if ($('#modal-reassign-all-lines').is(':checked')) {
            $('#modal-reassign-all-hint').text('All ' + detailRowsTotal + ' line item(s) of '
                + (tickets.length > 1 ? 'these tickets' : 'this ticket') + ' will be re-assigned.');
        } else if (others === 0) {
            $('#modal-reassign-all-hint').text('The selection already covers every line item of '
                + (tickets.length > 1 ? 'these tickets.' : 'this ticket.'));
        } else {
            $('#modal-reassign-all-hint').text('Ticking this also moves ' + others
                + ' other line item(s), for ' + detailRowsTotal + ' line item(s) in total.');
        }
    }

    // The per-row checkboxes are the source of truth: rebuild the selection from
    // them plus the unlisted carry-over, then resync highlights, counters, header.
    function syncSelectionFromDetailTable() {
        var pairs = detailUnlistedSelection.slice();
        var $boxes = $('#modal-detail-list .detail-line-checkbox');
        $boxes.each(function() {
            var $cb = $(this);
            var on = $cb.prop('checked');
            if (on) {
                pairs.push({ticket: String($cb.attr('data-ticket')), line: String($cb.attr('data-line'))});
            }
            $cb.closest('tr').toggleClass('bg-indigo-50 text-indigo-900', on)
                             .toggleClass('text-gray-600', !on);
        });
        reassignSelection = pairs;

        var checkedCount = $boxes.filter(':checked').length;
        $('#modal-detail-select-all')
            .prop('checked', $boxes.length > 0 && checkedCount === $boxes.length)
            .prop('indeterminate', checkedCount > 0 && checkedCount < $boxes.length);

        refreshDetailCounts();
        renderReassignSelectionState();
    }

    $('#modal-detail-list').on('change', '.detail-line-checkbox', function() {
        syncSelectionFromDetailTable();
    });

    // Row click toggles the line; a click on the box itself is already handled.
    $('#modal-detail-list').on('click', 'tr', function(e) {
        if ($(e.target).is('input')) { return; }
        var $cb = $(this).find('.detail-line-checkbox');
        if ($cb.length === 0 || $cb.prop('disabled')) { return; }
        $cb.prop('checked', !$cb.prop('checked'));
        syncSelectionFromDetailTable();
    });

    $('#modal-detail-select-all').on('change', function() {
        $('#modal-detail-list .detail-line-checkbox').not(':disabled').prop('checked', $(this).prop('checked'));
        syncSelectionFromDetailTable();
    });

    // While the switch is on every line is checked and frozen; unticking restores
    // the manual picks.
    $('#modal-reassign-all-lines').on('change', function() {
        var on = $(this).prop('checked');
        var $boxes = $('#modal-detail-list .detail-line-checkbox');
        if (on) {
            detailPreAllState = $boxes.map(function() { return $(this).prop('checked'); }).get();
            $boxes.prop('checked', true).prop('disabled', true);
            $('#modal-detail-select-all').prop('checked', true).prop('indeterminate', false).prop('disabled', true);
        } else {
            $boxes.prop('disabled', false);
            $('#modal-detail-select-all').prop('disabled', false);
            if (detailPreAllState && detailPreAllState.length === $boxes.length) {
                $boxes.each(function(i) { $(this).prop('checked', !!detailPreAllState[i]); });
            }
            detailPreAllState = null;
        }
        syncSelectionFromDetailTable();
    });

    // Open the reassign modal for an explicit list of (ticket, line) pairs.
    function openReassignModal(pairs, currentPic, teamCode) {
        reassignSelection = (pairs || []).slice();
        detailUnlistedSelection = [];
        detailRowsTotal = reassignSelection.length;   // provisional until the fetch lands
        detailPreAllState = null;
        $('#modal-detail-list').empty();
        renderReassignSelectionState();

        populateReassignPicPool(teamCode, currentPic || '');

        // Always reopen with the whole-ticket switch off: never inherited.
        $('#modal-reassign-all-lines').prop('checked', false);
        $('#modal-detail-select-all').prop({checked: false, indeterminate: false, disabled: false});
        $('#modal-reassign-all-hint').text('Leave unticked to move only the selected line item(s).');
        $('#modal-detail-box').addClass('hidden');
        loadTicketLineSuggestion(reassignSelection);

        modal.removeClass('hidden');
    }

    // --- TICKET DETAIL -----------------------------------------------------
    // Full line list for the selected ticket(s), fetched because #prTable is
    // serverSide and the sibling lines are usually not rendered. Advisory only:
    // a failed or slow fetch never blocks the reassignment.
    function detailMetaChip(label, value) {
        if (!value) { return null; }
        var $chip = $('<span>').addClass('whitespace-nowrap');
        $('<span>').addClass('text-gray-400').text(label + ': ').appendTo($chip);
        $('<span>').addClass('font-semibold text-gray-700').text(value).appendTo($chip);
        return $chip;
    }

    function detailPicCell(line) {
        var $td = $('<td>').addClass('px-2 py-1.5 whitespace-nowrap');
        if (!line.assigned_pic) {
            return $td.append($('<span>').addClass('italic text-yellow-600').text('Unassigned'));
        }
        $td.append(document.createTextNode(line.assigned_pic));
        if (parseInt(line.assigned_by_ai || 0)) {
            $td.append($('<span>').addClass('ml-1 text-[10px] text-gray-400').text('(AI)'));
        } else {
            $td.append($('<span>').addClass('ml-1 text-[10px] text-indigo-500').text('(Manual)'));
        }
        return $td;
    }

    var siblingRequestId = 0;
    function loadTicketLineSuggestion(pairs) {
        var tickets = selectionTickets(pairs);
        if (tickets.length === 0) { return; }

        // Lines already selected, as a "ticket|line" lookup.
        var picked = {};
        pairs.forEach(function(p) { picked[p.ticket + '|' + p.line] = true; });

        var myId = ++siblingRequestId;
        var fd = new FormData();
        tickets.forEach(function(t) { fd.append('tickets[]', t); });

        fetch('ajax_ticket_lines.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            // A newer modal open (or a close) invalidates this response.
            if (myId !== siblingRequestId || !resp || !resp.ok) { return; }

            var rows = [], projectTickets = [];
            (resp.tickets || []).forEach(function(t) {
                if (t.is_project) { projectTickets.push(t.ticket_no); }
                (t.lines || []).forEach(function(ln) {
                    var isSelected = !!picked[t.ticket_no + '|' + ln.line];
                    rows.push($.extend({ticket: t.ticket_no, selected: isSelected}, ln));
                });
            });
            if (rows.length === 0) { return; }
            detailRowsTotal = rows.length;

            var multiTicket = tickets.length > 1;
            $('#modal-detail-title').text(multiTicket
                ? ('Ticket detail — ' + tickets.length + ' tickets')
                : ('Ticket detail — ' + tickets[0]));

            // Meta only makes sense for one ticket; otherwise the Ticket column carries it.
            var $meta = $('#modal-detail-meta').empty();
            if (!multiTicket) {
                var t0 = (resp.tickets || [])[0] || {};
                [detailMetaChip('Requestor', t0.requestor_name),
                 detailMetaChip('PR Date', t0.pr_date),
                 detailMetaChip('P.Org', t0.p_org),
                 detailMetaChip('P.Group', t0.p_group),
                 detailMetaChip('Site', t0.site_allocation)
                ].forEach(function($chip) { if ($chip) { $chip.appendTo($meta); } });
            }
            $meta.toggleClass('hidden', $meta.children().length === 0);

            // One row per line, built with .text(): ticket numbers and descriptions
            // are user data and must never be parsed as markup.
            $('#modal-detail-box').find('.detail-ticket-col').toggleClass('hidden', !multiTicket);

            var ROW_CAP = 40;
            var $list = $('#modal-detail-list').empty();
            rows.slice(0, ROW_CAP).forEach(function(o) {
                var $tr = $('<tr>').addClass('detail-line-row cursor-pointer hover:bg-indigo-50/60')
                                   .toggleClass('bg-indigo-50 text-indigo-900', o.selected)
                                   .toggleClass('text-gray-600', !o.selected);

                $('<td>').addClass('px-2 py-1.5 text-center').append(
                    $('<input>').attr({type: 'checkbox', title: 'Include this line item'})
                                .addClass('detail-line-checkbox h-3.5 w-3.5 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500 align-middle')
                                .prop('checked', o.selected)
                                .attr('data-ticket', o.ticket).attr('data-line', o.line)
                ).appendTo($tr);

                if (multiTicket) {
                    $('<td>').addClass('px-2 py-1.5 whitespace-nowrap font-mono').text(o.ticket).appendTo($tr);
                }
                $('<td>').addClass('px-2 py-1.5 whitespace-nowrap font-semibold').text(o.line).appendTo($tr);
                // Clamped on an inner div: max-width on a <td> needs table-layout:fixed.
                $('<td>').addClass('px-2 py-1.5')
                         .append($('<div>').addClass('max-w-[260px] truncate')
                                           .attr('title', o.description || '')
                                           .text(o.description || '—'))
                         .appendTo($tr);
                $('<td>').addClass('px-2 py-1.5 whitespace-nowrap').text(o.status || '—').appendTo($tr);
                detailPicCell(o).appendTo($tr);

                $tr.appendTo($list);
            });
            detailUnlistedSelection = rows.slice(ROW_CAP)
                .filter(function(o) { return o.selected; })
                .map(function(o) { return {ticket: o.ticket, line: o.line}; });

            $('#modal-detail-more').toggleClass('hidden', rows.length <= ROW_CAP)
                                   .text('+ ' + (rows.length - ROW_CAP) + ' more line item(s) not listed'
                                         + (detailUnlistedSelection.length ? ' (' + detailUnlistedSelection.length + ' of them selected)' : '')
                                         + ' — use the switch below to cover them.');

            $('#modal-detail-project-warn')
                .toggleClass('hidden', projectTickets.length === 0)
                .text(projectTickets.length === 0 ? '' :
                      (projectTickets.join(', ') + (projectTickets.length === 1 ? ' is' : ' are') +
                       ' managed on the Project dashboard and cannot be reassigned here.'));

            $('#modal-detail-box').removeClass('hidden');
            syncSelectionFromDetailTable();
        })
        .catch(function() { /* advisory only: stay silent and keep the modal usable */ });
    }

    // Single-line reassign via the per-row Assigned-PIC button.
    $('#prTable tbody').on('click', '.open-modal-btn', function() {
        var ticket = String($(this).data('ticket'));
        var line = String($(this).data('line'));
        var currentPic = $(this).data('pic') || '';
        var teamCode = $(this).closest('tr').attr('data-team') || '';
        openReassignModal([{ticket: ticket, line: line}], currentPic, teamCode);
    });
    $('#closeModalBtn, #modalOverlay').on('click', function() { modal.addClass('hidden'); });

    // Re-fetch the current page from the server so the reassigned row(s) reflect
    // the true DB state (assigned_pic, assigned_by_ai, and anything Smart Status
    // derives from them). Now that #prTable is serverSide, hand-patching cells
    // in place would only touch the DOM, not the row data the server owns --
    // reload(null, false) keeps the current page/sort/filters and is cheap since
    // it only fetches one page. Any stale checkbox selection is cleared
    // automatically by the table's own 'draw' handler above.
    function applyReassignSuccess(data) {
        var assignedPic = data.assigned_pic || '';   // '' / null => Unassigned
        var isUnassigned = !assignedPic;

        // data.items = [{ticket_no, lines: [...]}, ...] — one entry per ticket the
        // write actually touched, so a multi-ticket reassignment patches every
        // affected row.
        var pairs = [];
        (data.items || []).forEach(function(item) {
            (item.lines || []).forEach(function(line) {
                pairs.push({ticket: String(item.ticket_no), line: String(line)});
            });
        });

        pairs.forEach(function(pair) {
            $('#prTable tbody .line-select-checkbox').each(function() {
                var $cb = $(this);
                if (String($cb.data('ticket')) !== pair.ticket) { return; }
                if (String($cb.data('line')) !== pair.line) { return; }
                var $row = $cb.closest('tr');

                // Assigned PIC cell (col 8): swap only the label span; keep the pencil
                // svg intact. Manual reassignment always sets assigned_by_ai = 0.
                var $btn = $row.find('.open-modal-btn');
                var prevPic = ($btn.attr('data-pic') || '').trim();
                $btn.attr('data-pic', assignedPic).data('pic', assignedPic);
                var $label = $('<span>');
                if (isUnassigned) {
                    $label.addClass('text-yellow-600 italic').text('Unassigned');
                } else {
                    var picFirstName = assignedPic.trim().split(/\s+/)[0] || '';
                    $label.addClass('text-indigo-700 font-semibold').attr('title', assignedPic).text(picFirstName + ' (Manual)');
                }
                $btn.find('span').first().replaceWith($label);
                $row.attr('data-assigned-by-ai', '0');

                // "Last updated" sub-line. The server only stamps lines whose PIC
                // really changed, so a reassignment back to the same person must
                // leave the existing stamp alone rather than claim this edit.
                if (prevPic !== assignedPic.trim()) {
                    var stampHtml = picStampHtml({
                        last_updated_by: data.last_updated_by,
                        last_updated_date: data.last_updated_date
                    });
                    var $oldStamp = $btn.closest('td').find('.pic-stamp');
                    if ($oldStamp.length) {
                        if (stampHtml) { $oldStamp.replaceWith(stampHtml); } else { $oldStamp.remove(); }
                    } else if (stampHtml) {
                        $btn.closest('td').append(stampHtml);
                    }
                }

                // Keep the column filter/search cache in sync (drives the Assigned PIC
                // dropdown filter + global search) without forcing a redraw.
                var $td = $btn.closest('td').attr('data-search', isUnassigned ? 'Unassigned' : assignedPic);
                try { table.cell($td.get(0)).invalidate('dom'); } catch (err) {}

                // Clear just this reassigned line's checkbox.
                $cb.prop('checked', false);
            });
        });
        refreshLineSelectBar();
    }

    // Reassignment submit -> AJAX (the normal path). preventDefault so the page never
    // reloads; the server returns JSON and we update the affected rows in place.
    var reassignSubmitting = false;
    modal.find('form').on('submit', function(e) {
        e.preventDefault();
        if (reassignSubmitting) { return; }

        var newPic = $('#modal-pic-select').val();
        var pairs = reassignSelection.slice();
        // Mirror the server no-op guard: never submit an empty selection.
        if (pairs.length === 0) {
            modal.addClass('hidden');
            showReassignFeedbackModal('error', 'Reassignment Failed', 'No valid line items were selected.');
            return;
        }

        // Expansion is left to the server: the sibling lines are not rendered here.
        var allLines = $('#modal-reassign-all-lines').is(':checked');

        var fd = new FormData();
        fd.append('new_pic', newPic);
        fd.append('update_master_data', $('#modal-update-master-data').is(':checked') ? '1' : '0');
        fd.append('reassign_all_lines', allLines ? '1' : '0');
        pairs.forEach(function(p) { fd.append('reassign_items[]', p.ticket + '|' + p.line); });

        var $submitBtn = modal.find('button[type="submit"]');
        reassignSubmitting = true;
        $submitBtn.prop('disabled', true).text('Reassigning…');

        fetch('dashboard_mgr.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: fd
        })
        .then(function(r) { return r.json(); })
        .then(function(resp) {
            modal.addClass('hidden');
            if (resp && resp.ok) {
                applyReassignSuccess(resp);
                // A whole-ticket write also touches off-page lines.
                if (allLines) { try { table.ajax.reload(null, false); } catch (err) {} }
                showReassignFeedbackModal('success', 'Reassignment Successful',
                    resp.message || 'Reassignment successful.');
            } else {
                showReassignFeedbackModal('error', 'Reassignment Failed',
                    (resp && resp.message) ? resp.message : 'Reassignment failed. Please try again.');
            }
        })
        .catch(function() {
            modal.addClass('hidden');
            showReassignFeedbackModal('error', 'Reassignment Failed',
                'A network error occurred and no changes were saved. Please try again.');
        })
        .then(function() {
            reassignSubmitting = false;
            $submitBtn.prop('disabled', false).text('Save Changes');
        });
    });

    // ===== LINE SELECTION UX (keyboard-assisted; visible rows only) =========
    // Selection is scoped to the rows DataTables currently renders (current page +
    // active search/sort). Cross-page persistence is intentionally NOT supported,
    // so the action-bar count always matches exactly what a reassignment submits.
    // Shift extends a range: it selects every line from the anchor (the last line
    // toggled on its own) through the clicked one, inclusive. Ctrl/Cmd toggles only
    // the line it lands on, so picking rows 2 and 4 selects exactly those two.

    // Currently-rendered checked boxes — also the exact set the submit uses, so the
    // count can never be misleading.
    function selectedLineCheckboxes() { return $('#prTable tbody').find('.line-select-checkbox:checked'); }

    // Anchor for Shift-range selection: the last row toggled on its own (plain
    // checkbox click or Ctrl/Cmd-click). Shift-clicks extend from it and leave it
    // in place, so repeated Shift-clicks re-extend from the same origin.
    var $lineSelectAnchor = null;

    // Rows on the current page that carry a line checkbox, in visual order. Project
    // rows have no checkbox and are skipped, so a range never spans into them.
    function selectableLineRows() {
        return $('#prTable tbody tr').filter(function() {
            return $(this).find('.line-select-checkbox').length > 0;
        });
    }

    // Check every line between the anchor and $row inclusive. Returns false when the
    // anchor is unusable (never set, or dropped by a redraw/page change) so callers
    // can fall back to toggling the single clicked line.
    function applyLineSelectRange($row) {
        if (!$lineSelectAnchor || $lineSelectAnchor.length === 0) { return false; }
        var $rows = selectableLineRows();
        var from = $rows.index($lineSelectAnchor);
        var to   = $rows.index($row);
        if (from === -1 || to === -1) { return false; }
        if (from > to) { var swap = from; from = to; to = swap; }
        $rows.slice(from, to + 1).find('.line-select-checkbox').prop('checked', true);
        return true;
    }

    function refreshLineSelectBar() {
        // Re-sync every visible row's highlight from its checkbox, then the bar.
        $('#prTable tbody tr').each(function() {
            var $cb = $(this).find('.line-select-checkbox');
            $(this).toggleClass('line-row-selected', $cb.length > 0 && $cb.prop('checked'));
        });
        var $checked = selectedLineCheckboxes();
        var count = $checked.length;
        $('#lineSelectCount').text(count);
        // Spell out the ticket spread once a selection covers more than one, so
        // the scope of the pending reassignment is visible before opening the modal.
        var tickets = {};
        $checked.each(function() { tickets[String($(this).data('ticket'))] = true; });
        var ticketCount = Object.keys(tickets).length;
        $('#lineSelectTickets').text(ticketCount > 1 ? (' across ' + ticketCount + ' tickets') : '');
        $('#lineSelectBar').toggleClass('hidden', IS_VIEWER || count === 0);
        // IS_VIEWER keeps the selection column and its Reassign bar permanently
        // hidden: the reassign POST would be refused anyway, so the affordance
        // would only produce a 403.
        $('#prTable .line-select-col').toggleClass('hidden', IS_VIEWER || count === 0);
        
        var visibleCheckboxes = table.$('.line-select-checkbox', {filter: 'applied'});
        var checkedVisible = visibleCheckboxes.filter(':checked');
        if (visibleCheckboxes.length > 0 && visibleCheckboxes.length === checkedVisible.length) {
            $('#selectAllCheckbox').prop('checked', true);
        } else {
            $('#selectAllCheckbox').prop('checked', false);
        }
    }

    // Direct checkbox click (also covers the spacebar toggle, which fires 'click').
    // The browser has already flipped the checked state by the time this runs, which
    // is the whole intent for an unmodified or Ctrl/Cmd click. A Shift-click instead
    // fills the range from the anchor, which re-checks this box anyway.
    $('#prTable tbody').on('click', '.line-select-checkbox', function(e) {
        var $row = $(this).closest('tr');
        if (!e.shiftKey || !applyLineSelectRange($row)) {
            $lineSelectAnchor = $row;
        }
        refreshLineSelectBar();
    });

    $('#selectAllCheckbox').on('click', function(e) {
        var isChecked = $(this).prop('checked');
        // Only select the checkboxes currently filtered/displayed
        table.$('.line-select-checkbox', {filter: 'applied'}).prop('checked', isChecked);
        $lineSelectAnchor = null;
        refreshLineSelectBar();
    });

    // Belt-and-suspenders for the CSS user-select:none above: Shift/Ctrl/Cmd-click is a
    // click-drag-like gesture browsers also read as "extend the text selection", which
    // can still kick in on mousedown regardless of user-select in some browsers. Stop it
    // at the source, before the browser starts a selection, while leaving interactive
    // controls (button/link/select/input/label/svg) untouched so they keep working.
    $('#prTable tbody').on('mousedown', 'tr', function(e) {
        if ($(e.target).closest('button, a, select, input, label, svg').length) { return; }
        if (e.shiftKey || e.ctrlKey || e.metaKey) {
            e.preventDefault();
        }
    });

    // Row click is deliberately inert on its own — a plain click must never select a
    // line, it's too easy to trigger by accident. Shift-click selects the whole range
    // from the anchor to here; Ctrl/Cmd-click toggles just this line. Clicks on
    // interactive controls (button/link/select/input/label/svg), including the
    // checkbox itself (handled above), and project rows (no checkbox) are ignored.
    $('#prTable tbody').on('click', 'tr', function(e) {
        // Read-only session: line selection exists only to feed the reassign
        // modal. The checkboxes are still in the DOM (just hidden), so without
        // this a Ctrl/Shift-click would tick them and reveal the Reassign bar.
        if (IS_VIEWER) { return; }
        if ($(e.target).closest('button, a, select, input, label, svg').length) { return; }
        var $cb = $(this).find('.line-select-checkbox');
        if ($cb.length === 0) { return; }
        if (!e.shiftKey && !e.ctrlKey && !e.metaKey) { return; } // plain row click: no change

        // Ctrl/Cmd wins when both are held, so an accidental Shift can't turn a
        // deliberate single pick into a range.
        if (e.shiftKey && !e.ctrlKey && !e.metaKey && applyLineSelectRange($(this))) {
            refreshLineSelectBar();
            return;
        }

        $cb.prop('checked', !$cb.prop('checked'));
        $lineSelectAnchor = $(this);
        refreshLineSelectBar();
    });

    table.on('draw', function() {
        // Row nodes change under a redraw; drop the anchor so a stale one can never
        // seed a range across a sort/page/filter boundary.
        $lineSelectAnchor = null;
        refreshLineSelectBar();
    });

    $('#lineSelectClear').on('click', function() {
        // Clear across all rows (including any off-page nodes) to fully reset.
        table.$('.line-select-checkbox').prop('checked', false);
        $('#selectAllCheckbox').prop('checked', false);
        table.$('tr').removeClass('line-row-selected');
        $lineSelectAnchor = null;
        refreshLineSelectBar();
    });
    $('#reassignSelectedBtn').on('click', function() {
        var $checked = selectedLineCheckboxes();
        if ($checked.length === 0) { return; }
        // The selection may cover any number of tickets; each checked line is
        // submitted as its own (ticket, line) pair.
        var pairs = [], teamCode = '';
        $checked.each(function() {
            pairs.push({
                ticket: String($(this).data('ticket')),
                line:   String($(this).data('line'))
            });
            if (!teamCode) { teamCode = $(this).closest('tr').attr('data-team') || ''; }
        });
        openReassignModal(pairs, '', teamCode);
    });

    // --- PROJECT ASSIGN MODAL (manual, scoped ticket_no + p_group, no AI) ---
    // Routes to ajax_project_assign.php, NOT the Non-Project reassign POST. That
    // endpoint re-validates head access, p_group scope, officer eligibility and
    // keeps Intercompany rows read-only.
    var projModal = $('#projectAssignModal');
    var projTicket = '', projPgroup = '';
    $('#prTable tbody').on('click', '.project-assign-btn', function() {
        projTicket = $(this).data('ticket');
        projPgroup = String($(this).data('pgroup'));
        var currentOfficer = $(this).data('officer') || '';
        $('#proj-modal-ticket').text(projTicket);
        $('#proj-modal-pgroup').text(projPgroup);
        $('#proj-modal-error').addClass('hidden').text('');
        var $sel = $('#proj-officer-select');
        $sel.empty().append('<option value="">-- Set to Unassigned --</option>');
        (officersByPgroup[projPgroup] || []).forEach(function(name) {
            $sel.append($('<option>').val(name).text(name));
        });
        $sel.val(currentOfficer);
        projModal.removeClass('hidden');
    });
    $('#projCancelBtn, #projOverlay').on('click', function() { projModal.addClass('hidden'); });
    $('#projSaveBtn').on('click', function() {
        var btn = $(this);
        var newOfficer = $('#proj-officer-select').val();
        btn.prop('disabled', true).text('Saving...');
        $.ajax({
            url: 'ajax_project_assign.php',
            type: 'POST',
            dataType: 'json',
            data: { ticket_no: projTicket, p_group: projPgroup, new_officer: newOfficer },
            success: function(resp) {
                if (resp.status === 'success') {
                    location.reload();
                } else {
                    $('#proj-modal-error').removeClass('hidden').text(resp.message || 'Assignment failed.');
                    btn.prop('disabled', false).text('Save Changes');
                }
            },
            error: function() {
                $('#proj-modal-error').removeClass('hidden').text('Connection error. Please try again.');
                btn.prop('disabled', false).text('Save Changes');
            }
        });
    });

    $('#prTable tbody').on('click', '.pr-taxonomy-clickable', function() {
        var ticketNo = $(this).data('ticket-no');
        Swal.fire({
            title: 'Fetching Taxonomy...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        $.ajax({
            url: 'ajax_get_pr_taxonomy.php',
            type: 'POST',
            data: { ticket_no: ticketNo },
            dataType: 'json',
            success: function(resp) {
                if (resp.status === 'success') {
                    var items = resp.data.line_items;
                    var mdList = resp.data.master_data_list;
                    
                    var mdOptions = '<option value="">-- Select Existing Taxonomy --</option>';
                    mdList.forEach(function(md) {
                        mdOptions += '<option value="'+md.id+'">'+(md.level_4 || md.level_3 || md.level_2 || 'Unnamed')+' (ID: '+md.id+')</option>';
                    });

                    var html = '<div class="text-left w-full overflow-x-auto" style="max-height: 70vh;">';
                    html += '<h3 class="font-bold text-lg mb-4 text-center">Ticket ' + ticketNo + '</h3>';
                    
                    items.forEach(function(item) {
                        html += '<div class="bg-gray-50 p-4 rounded border border-gray-200 mb-4" id="row-'+item.ticket_line_item+'">';
                        html += '<div class="flex justify-between items-start mb-2">';
                        html += '<h4 class="font-semibold text-gray-800 text-sm">Line ' + item.ticket_line_item + ': ' + (item.item_description || '-') + '</h4>';
                        if (!IS_VIEWER) {
                            html += '<button type="button" class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded hover:bg-indigo-200" onclick="toggleEdit(\''+item.ticket_line_item+'\')">Edit</button>';
                        }
                        html += '</div>';
                        
                        html += '<div id="view-'+item.ticket_line_item+'" class="space-y-1 text-xs">';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">ID:</span> ' + (item.master_data_id || 'Unmapped') + '</p>';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">L0:</span> ' + (item.level_0 || '-') + '</p>';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">L1:</span> ' + (item.level_1 || '-') + '</p>';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">L2:</span> ' + (item.level_2 || '-') + '</p>';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">L3:</span> ' + (item.level_3 || '-') + '</p>';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">L4:</span> ' + (item.level_4 || '-') + '</p>';
                        html += '<p><span class="font-bold text-gray-500 w-16 inline-block">PIC:</span> ' + (item.assigned_pic || '-') + '</p>';
                        html += '</div>';

                        html += '<div id="edit-'+item.ticket_line_item+'" class="hidden mt-3 p-3 bg-white border border-gray-200 rounded text-sm">';
                        html += '<div class="mb-3">';
                        html += '<label class="block text-xs font-bold text-gray-700 mb-1">Select Existing Master Data</label>';
                        html += '<select id="existing-'+item.ticket_line_item+'" class="w-full border-gray-300 rounded shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-xs p-2 border">' + mdOptions + '</select>';
                        html += '</div>';
                        
                        html += '<div class="text-center text-xs text-gray-500 my-2">- OR CREATE NEW -</div>';
                        
                        html += '<div class="space-y-2">';
                        html += '<input type="text" id="new-l0-'+item.ticket_line_item+'" placeholder="Level 0" class="w-full border-gray-300 rounded shadow-sm text-xs p-2 border">';
                        html += '<input type="text" id="new-l1-'+item.ticket_line_item+'" placeholder="Level 1" class="w-full border-gray-300 rounded shadow-sm text-xs p-2 border">';
                        html += '<input type="text" id="new-l2-'+item.ticket_line_item+'" placeholder="Level 2" class="w-full border-gray-300 rounded shadow-sm text-xs p-2 border">';
                        html += '<input type="text" id="new-l3-'+item.ticket_line_item+'" placeholder="Level 3" class="w-full border-gray-300 rounded shadow-sm text-xs p-2 border">';
                        html += '<input type="text" id="new-l4-'+item.ticket_line_item+'" placeholder="Level 4 (Required for new)" class="w-full border-gray-300 rounded shadow-sm text-xs p-2 border">';
                        
                        var picOptions = '<option value="">-- Select PIC --</option>';
                        if (resp.data.pic_list) {
                            resp.data.pic_list.forEach(function(pic) {
                                picOptions += '<option value="'+pic+'">'+pic+'</option>';
                            });
                        }
                        
                        html += '<select id="new-pic-'+item.ticket_line_item+'" class="w-full border-gray-300 rounded shadow-sm text-xs p-2 border select2-pic">' + picOptions + '</select>';
                        html += '<div class="flex justify-end mt-2">';
                        html += '<button type="button" class="text-xs bg-indigo-600 text-white px-3 py-1.5 rounded hover:bg-indigo-700" onclick="saveTaxonomy(event, \''+ticketNo+'\', \''+item.ticket_line_item+'\')">Save</button>';
                        html += '</div>';
                        html += '</div>';
                        
                        html += '</div>'; // end edit div
                        html += '</div>'; // end row wrapper
                    });
                    
                    html += '</div>';
                    
                    Swal.fire({
                        title: '',
                        html: html,
                        width: '800px',
                        showConfirmButton: false,
                        showCloseButton: true
                    });
                    
                    window.toggleEdit = function(line) {
                        $('#view-'+line).toggleClass('hidden');
                        var editBox = $('#edit-'+line);
                        editBox.toggleClass('hidden');
                        
                        if (!editBox.hasClass('hidden')) {
                            $('#new-pic-'+line).select2({
                                dropdownParent: $('.swal2-container'),
                                placeholder: "-- Select PIC --",
                                width: '100%'
                            });
                            $('#existing-'+line).select2({
                                dropdownParent: $('.swal2-container'),
                                placeholder: "-- Select Existing Taxonomy --",
                                width: '100%'
                            });
                        }
                    };
                    
                    window.saveTaxonomy = function(event, tNo, line) {
                        var existingId = $('#existing-'+line).val();
                        var data = { ticket_no: tNo, ticket_line_item: line };
                        
                        if (existingId) {
                            data.mode = 'existing';
                            data.master_data_id = existingId;
                        } else {
                            data.mode = 'new';
                            data.level_0 = $('#new-l0-'+line).val();
                            data.level_1 = $('#new-l1-'+line).val();
                            data.level_2 = $('#new-l2-'+line).val();
                            data.level_3 = $('#new-l3-'+line).val();
                            data.level_4 = $('#new-l4-'+line).val();
                            data.pic_names = $('#new-pic-'+line).val();
                            
                            if (!data.level_4) {
                                Swal.showValidationMessage('Level 4 is required for new taxonomy');
                                return;
                            }
                        }
                        
                        var btn = $(event.target);
                        btn.prop('disabled', true).text('Saving...');
                        
                        $.ajax({
                            url: 'ajax_update_pr_taxonomy.php',
                            type: 'POST',
                            data: data,
                            dataType: 'json',
                            success: function(res) {
                                if (res.status === 'success') {
                                    btn.removeClass('bg-indigo-600 hover:bg-indigo-700').addClass('bg-green-600 hover:bg-green-700').text('Saved!');
                                    setTimeout(() => toggleEdit(line), 500);
                                    
                                    Swal.fire({
                                        toast: true,
                                        position: 'top-end',
                                        icon: 'success',
                                        title: 'Saved successfully',
                                        showConfirmButton: false,
                                        timer: 3000
                                    });
                                } else {
                                    btn.prop('disabled', false).text('Save');
                                    Swal.showValidationMessage(res.message);
                                }
                            },
                            error: function() {
                                btn.prop('disabled', false).text('Save');
                                Swal.showValidationMessage('Connection error');
                            }
                        });
                    };

                } else {
                    Swal.fire('Error', resp.message || 'Taxonomy not found', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Connection error. Please try again.', 'error');
            }
        });
    });

    // --- VENDOR EVAL v2 REMINDER MODAL ---
    // Two-step flow. Step 1 asks the server for a preview (SELECT only, no send,
    // no DB write) and shows exactly which requestors would be contacted. Step 2
    // sends, and only after the operator ticks the real-send confirmation.
    var waTable = $('#waTable').DataTable({
        "pageLength": 10,
        "dom": 'tp',
        "order": [[ 1, "asc" ]]
    });

    var waStage = 'review';        // 'review' -> 'send'
    var waPreviewSignature = '';   // PO selection the preview was built from

    function waSelectedPOs() {
        // waTable.$ reaches rows on every page, not just the one on screen.
        return waTable.$('.wa-checkbox:checked').map(function() { return $(this).val(); }).get();
    }

    function waSignature(pos) { return pos.slice().sort().join('|'); }

    function waResetToReview() {
        waStage = 'review';
        waPreviewSignature = '';
        $('#wa-preview-panel').addClass('hidden');
        $('#wa-confirm-wrap').addClass('hidden');
        $('#wa-confirm-real-send').prop('checked', false);
        $('#wa-modal-hint').text('Step 1 of 2 — nothing is sent until you review the recipients and confirm.');
        $('#submit-wa-blast')
            .html('Review Recipients')
            .prop('disabled', false)
            .removeClass('bg-red-600 hover:bg-red-700 bg-gray-400 cursor-not-allowed opacity-75')
            .addClass('bg-indigo-600 hover:bg-indigo-700');
        $('#closeWaModalBtn').prop('disabled', false);
    }

    function waRenderPreview(res) {
        var d = res.details || {};
        var rows = (d.requestors || []).map(function(r) {
            return '<div class="py-1.5 border-b border-gray-200 last:border-0">'
                 + '<span class="font-bold text-gray-800">' + $('<div>').text(r.requestor_name).html() + '</span>'
                 + ' <span class="text-gray-500">— ' + r.po_count + ' PO</span><br>'
                 + '<span class="text-gray-500">email: ' + $('<div>').text(r.email.recipient).html()
                 + ' &nbsp;|&nbsp; WA: ' + $('<div>').text(r.wa.recipient).html() + '</span>'
                 + (r.placeholder_wa ? ' <span class="text-amber-700 font-bold">[placeholder WA]</span>' : '')
                 + '</div>';
        }).join('');

        $('#wa-preview-headline').text(res.requestor_count + ' requestor(s) · ' + res.po_count + ' PO(s)');
        $('#wa-preview-body').html(rows || '<em class="text-gray-500">No sendable requestor.</em>');

        var skips = d.skipped_pos || [];
        $('#wa-preview-skips').html(
            skips.length
                ? '<strong>' + skips.length + ' selected PO(s) will be skipped:</strong> '
                  + skips.slice(0, 8).map(function(s) {
                        return $('<div>').text(s.po_number + (s.requestor_name ? ' (' + s.requestor_name + ')' : '') + ' — ' + s.reason).html();
                    }).join('; ')
                  + (skips.length > 8 ? ' … and ' + (skips.length - 8) + ' more.' : '')
                : ''
        );
        $('#wa-preview-panel').removeClass('hidden');
    }

    // Post-send result is deliberately terse — the per-requestor/channel detail
    // this used to render is already in vendor_eval_v2_invite_log for anyone who
    // needs it. No reload: sending a reminder does not change any row in the
    // table above (that only happens once the requestor actually submits), so
    // the dashboard/table/chart state is left exactly as the operator had it.
    function waRenderResult(res) {
        Swal.fire({
            icon: res.success ? 'success' : 'warning',
            title: res.success ? 'Done.' : 'Partially failed',
            text: res.success ? '' : (res.message || 'Some reminders failed — check the server log.'),
            confirmButtonText: 'Close'
        });
    }

    $('#open-wa-modal-btn').on('click', function() {
        waResetToReview();
        $('#waModal').removeClass('hidden');
        waTable.columns.adjust();
    });

    $('#closeWaModalBtn, #waModalOverlay').on('click', function() {
        $('#waModal').addClass('hidden');
    });

    $('.wa-col-search').on('keyup change', function() {
        waTable.column($(this).parent().index()).search(this.value).draw();
    });

    $('#wa-clear-filters').on('click', function() {
        $('.wa-col-search').val('');
        waTable.columns().search('').draw();
    });

    // Select-all covers the rows matching the current filter only — never rows
    // the operator has filtered out of view.
    $('#wa-select-all').on('change', function() {
        waTable.$('.wa-checkbox', { filter: 'applied' }).prop('checked', $(this).prop('checked'));
        waResetToReview();
    });

    // Any change to the selection invalidates a preview the operator already saw.
    $('#waTable').on('change', '.wa-checkbox', function() {
        if (waStage === 'send' && waSignature(waSelectedPOs()) !== waPreviewSignature) {
            waResetToReview();
        }
    });

    $('#submit-wa-blast').on('click', function() {
        var btn = $(this);
        var selectedPOs = waSelectedPOs();

        if (selectedPOs.length === 0) {
            Swal.fire({ icon: 'info', title: 'Nothing selected', text: 'Select at least one PO first.' });
            return;
        }

        var sending = (waStage === 'send');
        if (sending) {
            if (waSignature(selectedPOs) !== waPreviewSignature) { waResetToReview(); return; }
            if (!$('#wa-confirm-real-send').is(':checked')) {
                Swal.fire({ icon: 'warning', title: 'Confirmation required', text: 'Tick the confirmation box before sending.' });
                return;
            }
        }

        var payload = { po_numbers: selectedPOs, mode: sending ? 'send' : 'preview' };
        if (sending) {
            payload._csrf_ve2 = VE2_CSRF_TOKEN;
            payload.confirm   = VE2_SEND_CONFIRM;
        }

        btn.html(sending ? 'Sending…' : 'Checking…')
           .prop('disabled', true)
           .addClass('bg-gray-400 cursor-not-allowed opacity-75');
        $('#closeWaModalBtn').prop('disabled', sending);

        $.ajax({
            url: 'ajax_send_wa.php',
            type: 'POST',
            data: payload,
            dataType: 'json',
            timeout: 180000
        }).done(function(res) {
            if (!sending) {
                if (!res.success || res.requestor_count === 0) {
                    waResetToReview();
                    waRenderPreview(res);
                    Swal.fire({ icon: 'warning', title: 'Cannot send', text: res.message });
                    return;
                }
                waRenderPreview(res);
                waPreviewSignature = waSignature(selectedPOs);
                waStage = 'send';
                $('#wa-confirm-wrap').removeClass('hidden');
                $('#wa-modal-hint').text('Step 2 of 2 — this sends real email and WhatsApp.');
                btn.html('Send Reminders Now')
                   .prop('disabled', false)
                   .removeClass('bg-indigo-600 hover:bg-indigo-700 bg-gray-400 cursor-not-allowed opacity-75')
                   .addClass('bg-red-600 hover:bg-red-700');
                return;
            }
            $('#waModal').addClass('hidden');
            waRenderResult(res);
        }).fail(function(xhr) {
            var msg = (xhr.responseJSON && xhr.responseJSON.message)
                   || 'Reminder request failed (HTTP ' + xhr.status + '). Nothing further was sent.';
            waResetToReview();
            Swal.fire({ icon: 'error', title: 'Reminder request failed', text: msg });
        });
    });

    // --- UNIFIED WORKLOAD CHART ---
    window.chartRawData = [];
    window.isCombined = false;
    
    window.initWorkloadCharts = function(raw_data, persona) {
        window.chartRawData = raw_data || [];
        window.isCombined = (persona === 'COMBINED');
        if (window.isCombined) {
            forceShowAllPics = false;
        }
        renderCharts();
        $('.viz-chart-loader').fadeOut(200);
    };
    
    var metricSelect = $('#workloadMetricSelect');
    var dateFilterSelect = $('#workloadDateFilterSelect');
    var showAllBtn = $('#showAllPicsBtn');

    var includeDoneInCharts = false;
    $('#includeDoneToggleBtn').on('click', function() {
        includeDoneInCharts = !includeDoneInCharts;
        $(this).toggleClass('bg-gray-700 hover:bg-gray-800 text-white border-gray-700', includeDoneInCharts);
        $(this).toggleClass('bg-white text-gray-700 border-gray-200 hover:bg-gray-50', !includeDoneInCharts);
        renderCharts();
    });

    var chartTeamInstance = null;
    var chartPicInstance = null;

    // Shared by both charts' onHover: pointer cursor over a bar, default elsewhere.
    function chartBarCursor(evt, elements) {
        evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
    }

    var chartTeamFilter = null;
    var forceShowAllPics = false;
    var activePicTableFilter = null;

    // Selecting a team keeps every OTHER team bar drawn (see renderCharts: the team map
    // is built unfiltered), so the selection is shown by fading the rest back instead of
    // deleting them. Chart colors here come in both notations -- '#rrggbb' from the SLA
    // bucket palette, 'rgba(...)' from the plain workload bar -- and the rgba ones already
    // carry an alpha, so fading is applied as a FACTOR of whatever alpha is already there
    // rather than an absolute value that would make a 0.6 bar barely change.
    function fadeChartColor(color, factor) {
        var s = String(color).trim();
        var m = s.match(/^rgba?\(\s*([\d.]+)\s*,\s*([\d.]+)\s*,\s*([\d.]+)\s*(?:,\s*([\d.]+))?/i);
        if (m) {
            var a = (m[4] === undefined) ? 1 : parseFloat(m[4]);
            return 'rgba(' + m[1] + ',' + m[2] + ',' + m[3] + ',' + (a * factor) + ')';
        }
        var h = s.replace('#', '');
        if (h.length === 3) h = h[0] + h[0] + h[1] + h[1] + h[2] + h[2];
        var n = parseInt(h, 16);
        if (isNaN(n) || h.length !== 6) return s;
        return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + factor + ')';
    }

    // Turns each dataset's single color into a per-bar array: full strength for the
    // selected team, faded for the rest. borderColor goes along with it — a faded bar
    // that kept a solid 1px outline would stand out more than the selected one.
    function fadeUnselectedTeamBars(labels, datasets, selected) {
        datasets.forEach(function(ds) {
            // Stashed for the legend below, which must keep showing the real bucket color.
            if (typeof ds.backgroundColor === 'string') ds.solidBg = ds.backgroundColor;
            if (!selected) return;
            ['backgroundColor', 'borderColor'].forEach(function(key) {
                var base = ds[key];
                if (typeof base !== 'string') return;
                var faded = fadeChartColor(base, 0.4);
                ds[key] = labels.map(function(l) { return l === selected ? base : faded; });
            });
        });
    }

    // Clicking a bar drives the #prTable filters below the charts, reusing the
    // exact same toolbar controls a manual click would use (team button /
    // "Assigned PIC" dropdown) so the active-state styling stays in sync too.
    function syncTeamTableFilter(label) {
        var $btn = label
            ? $('.team-filter-btn').filter(function() { return $(this).text().trim() === label; })
            : $('.team-filter-btn[data-teamcode=""]');
        if ($btn.length) $btn.trigger('click');
    }

    // Clears the "Assigned PIC" column narrowing applied by clicking a bar in
    // the PIC chart. Split out from syncPicTableFilter so team-switch handlers
    // can drop a stale per-PIC filter left over from a previous team -- without
    // this, switching/resetting the team filter left the old PIC's column(6)
    // search in place and the table stayed stuck on that one person.
    // Callers own table.draw() so a team switch only fires a single request.
    function clearPicTableFilter() {
        activePicTableFilter = null;
        var $dropdown = $('#filterDropdown-pic');
        $dropdown.find('.col-filter-checkbox').prop('checked', false);
        table.column(6).search('', false, false);
        var $triggerLabel = $dropdown.find('.col-trigger-label');
        if ($triggerLabel.length) $triggerLabel.text('All');
    }

    function syncPicTableFilter(pic) {
        if (!pic) return; // blank/unassigned bucket has no matching dropdown option to filter by
        var same = activePicTableFilter === pic;
        if (same) {
            clearPicTableFilter();
        } else {
            activePicTableFilter = pic;
            var $dropdown = $('#filterDropdown-pic');
            $dropdown.find('.col-filter-checkbox').prop('checked', false)
                .filter(function() { return $(this).val() === activePicTableFilter; }).prop('checked', true);
            table.column(6).search('^(' + $.fn.dataTable.util.escapeRegex(activePicTableFilter) + ')$', true, false);
            var $triggerLabel = $dropdown.find('.col-trigger-label');
            if ($triggerLabel.length) $triggerLabel.text('1 Selected');
        }
        table.draw();
    }

    var METRIC_DEFS = [
        { key: 'ticket_lines',   label: 'Ticket Lines' },
        { key: 'unique_tickets', label: 'Unique Tickets' },
        { key: 'canvassing',     label: 'Canvassing' },
        { key: 'po',             label: 'PO' },
        { key: 'po_amount',      label: 'PO Amount' }
    ];

    // "All (Stacked by SLA)" splits every Team/PIC bar into these segments
    // instead of filtering rows down to a single sla_status.
    var SLA_STATUS_DEFS = [
        { key: 'on_track', label: 'On Track', bg: 'rgba(16, 185, 129, 0.7)', border: 'rgba(16, 185, 129, 1)' },
        { key: 'due_soon', label: 'Due Soon', bg: 'rgba(245, 158, 11, 0.7)', border: 'rgba(245, 158, 11, 1)' },
        { key: 'overdue',  label: 'Overdue',  bg: 'rgba(239, 68, 68, 0.7)',  border: 'rgba(239, 68, 68, 1)' },
        { key: 'done',     label: 'Done',     bg: 'rgba(156, 163, 175, 0.7)', border: 'rgba(156, 163, 175, 1)' }
    ];

    // No separate N/A bucket -- a row with no PR Date (sla_status 'na', see
    // sla_rules.php::sla_evaluate()) can't start its SLA countdown, so it reads
    // as already breached rather than getting its own category. 'done' rows
    // (PO already created) DO get their own bucket -- see SLA_STATUS_DEFS above.
    function slaBucket(row) {
        var s = row.sla_status || 'na';
        return (s === 'na') ? 'overdue' : s;
    }

    // One Chart.js dataset per SLA status, values computed with the active
    // "Calculate By" metric over just that status's rows for each label.
    // `defs` lets the caller drop 'done' from the legend/bars entirely when
    // #includeDoneToggleBtn is off, instead of rendering an always-empty segment.
    function buildSlaStackedDatasets(labels, groupMap, defs) {
        return (defs || SLA_STATUS_DEFS).map(function(s) {
            return {
                label: s.label,
                data: labels.map(function(l) {
                    var subset = groupMap[l].filter(function(r) { return slaBucket(r) === s.key; });
                    return calculateMetric(subset);
                }),
                backgroundColor: s.bg,
                borderColor: s.border,
                borderWidth: 1
            };
        });
    }

    function calculateMetricByKey(dataList, metric) {
        var result = 0;

        if (metric === 'ticket_lines') {
            result = dataList.length;
        } else if (metric === 'unique_tickets') {
            var unique = new Set(dataList.map(function(d) { return d.ticket_no; }));
            result = unique.size;
        } else if (metric === 'canvassing') {
            var unique = new Set(dataList.map(function(d) { return d.canvassing_no; }).filter(Boolean));
            result = unique.size;
        } else if (metric === 'po') {
            var unique = new Set(dataList.map(function(d) { return d.po_number; }).filter(Boolean));
            result = unique.size;
        } else if (metric === 'po_amount') {
            result = dataList.reduce(function(sum, d) { return sum + (parseFloat(d.po_amount) || 0); }, 0);
        }

        return result;
    }

    function calculateMetric(dataList) {
        return calculateMetricByKey(dataList, metricSelect.val());
    }

    function formatMetricValue(key, value) {
        if (key === 'po_amount') {
            return 'Rp ' + Math.round(value).toLocaleString('id-ID');
        }
        return value.toLocaleString('id-ID');
    }

    // Tooltip footer shared by both charts: every "Calculate By" metric for the
    // hovered bar's underlying rows, not just the one currently selected.
    function allMetricsTooltip(dataList) {
        return METRIC_DEFS.map(function(m) {
            return m.label + ': ' + formatMetricValue(m.key, calculateMetricByKey(dataList, m.key));
        });
    }

    function toYMD(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    // Client-side re-implementation of ca_date_range() (includes/cost_avoidance_helper.php)
    // so the two date filters resolve "this_month" / "last_month" / "last_2_weeks"
    // identically. Returns { from, to } (YYYY-MM-DD, both inclusive) or null for "all".
    function resolveDateFilter(f) {
        if (!f || f.filter === 'all') return null;
        if (f.filter === 'custom') {
            return (f.from || f.to) ? { from: f.from || null, to: f.to || null } : null;
        }

        var today = new Date();
        if (f.filter === 'this_month') {
            var start = new Date(today.getFullYear(), today.getMonth(), 1);
            var end = new Date(today.getFullYear(), today.getMonth() + 1, 0);
            return { from: toYMD(start), to: toYMD(end) };
        }
        if (f.filter === 'last_month') {
            var prevStart = new Date(today.getFullYear(), today.getMonth() - 1, 1);
            var prevEnd = new Date(today.getFullYear(), today.getMonth(), 0);
            return { from: toYMD(prevStart), to: toYMD(prevEnd) };
        }
        if (f.filter === 'last_2_weeks') {
            var from = new Date(today);
            from.setDate(today.getDate() - 14);
            return { from: toYMD(from), to: toYMD(today) };
        }
        return null;
    }

    // Last confirmed state of the PR Date filter. "custom" only becomes real once
    // Terapkan is clicked in the popup calendar below -- merely picking "Custom
    // Range" from the dropdown just opens the calendar and changes nothing yet.
    var lastConfirmedDateFilter = 'all';
    var currentWlDateFrom = '';
    var currentWlDateTo = '';
    var wlDraftFrom = '', wlDraftTo = '';
    var wlCalYear, wlCalMonth;

    // Also drives #prTable's own PR Date range (gf payload), so this filter narrows the table too.
    function syncTableDateFilter() {
        var range = resolveDateFilter({ filter: lastConfirmedDateFilter, from: currentWlDateFrom, to: currentWlDateTo });
        currentPrDateFrom = range ? (range.from || '') : '';
        currentPrDateTo   = range ? (range.to   || '') : '';
        prSyncLabel();
        table.draw();
    }

    window.toggleWorkloadCustomDates = function(selectElem) {
        if (selectElem.value === 'custom') {
            wlOpenPicker();
            return; // wait for Terapkan
        }
        $('#wlDatePopup').addClass('hidden');
        lastConfirmedDateFilter = selectElem.value;
        syncTableDateFilter();
        renderCharts();
    };

    // wlRenderCalendar()/prIso()/prPretty()/PR_MONTHS/PR_DOW/prToday are defined
    // further down alongside the table's own PR Date picker (#prDateTrigger); by
    // the time any of these handlers actually fire (a later click), that whole
    // ready() block has already run once top-to-bottom so they're all assigned.
    function wlRenderCalendar() {
        $('#wlCalTitle').text(PR_MONTHS[wlCalMonth] + ' ' + wlCalYear);

        var html = '';
        PR_DOW.forEach(function(d) { html += '<div class="pr-cal-dow">' + d + '</div>'; });

        var firstDow  = (new Date(wlCalYear, wlCalMonth, 1).getDay() + 6) % 7;
        var daysInMon = new Date(wlCalYear, wlCalMonth + 1, 0).getDate();
        var daysInPrv = new Date(wlCalYear, wlCalMonth, 0).getDate();
        var lo = wlDraftFrom, hi = wlDraftTo || wlDraftFrom;

        for (var i = 0; i < 42; i++) {
            var dayNum, outside = true, iso = '';
            if (i < firstDow) {
                dayNum = daysInPrv - firstDow + 1 + i;
            } else if (i - firstDow < daysInMon) {
                dayNum = i - firstDow + 1;
                outside = false;
                iso = prIso(wlCalYear, wlCalMonth, dayNum);
            } else {
                dayNum = i - firstDow - daysInMon + 1;
            }

            var cls = 'pr-cal-cell';
            if (outside) {
                cls += ' is-outside';
            } else {
                if (iso === prToday) cls += ' is-today';
                if (lo && iso === lo && iso === hi)      cls += ' is-edge is-single';
                else if (lo && iso === lo)               cls += ' is-edge is-range-start';
                else if (hi && iso === hi)               cls += ' is-edge is-range-end';
                else if (lo && hi && iso > lo && iso < hi) {
                    cls += ' is-inrange';
                    if (i % 7 === 0) cls += ' row-start';
                    if (i % 7 === 6) cls += ' row-end';
                }
            }
            html += '<div class="' + cls + '"' + (outside ? '' : ' data-iso="' + iso + '"') + '><span>' + dayNum + '</span></div>';
        }
        $('#wlCalGrid').html(html);
    }

    function wlOpenPicker() {
        wlDraftFrom = currentWlDateFrom;
        wlDraftTo   = currentWlDateTo;
        var anchor = currentWlDateFrom || prToday;
        wlCalYear  = parseInt(anchor.split('-')[0], 10);
        wlCalMonth = parseInt(anchor.split('-')[1], 10) - 1;
        wlRenderCalendar();
        $('#wlDatePopup').removeClass('hidden');
    }

    $('#wlDatePopup').on('click', function(e) { e.stopPropagation(); });
    $(document).on('click', function(e) {
        // Picking "custom" from a native <select> also dispatches a bubbling
        // click on the <select> itself right after the change event that opened
        // this popup -- ignore that one click so it doesn't close what it just opened.
        if ($(e.target).is('#workloadDateFilterSelect')) return;
        $('#wlDatePopup').addClass('hidden');
    });

    $('#wlDatePopup .wl-cal-nav').on('click', function() {
        wlCalMonth += parseInt($(this).data('step'), 10);
        if (wlCalMonth < 0)  { wlCalMonth = 11; wlCalYear--; }
        if (wlCalMonth > 11) { wlCalMonth = 0;  wlCalYear++; }
        wlRenderCalendar();
    });

    $('#wlCalGrid').on('click', '.pr-cal-cell[data-iso]', function() {
        var iso = $(this).data('iso');
        if (!wlDraftFrom || wlDraftTo) {
            wlDraftFrom = iso; wlDraftTo = '';
        } else if (iso < wlDraftFrom) {
            wlDraftTo = wlDraftFrom; wlDraftFrom = iso;
        } else {
            wlDraftTo = iso;
        }
        wlRenderCalendar();
    });

    $('#wlDateApply').on('click', function() {
        currentWlDateFrom = wlDraftFrom;
        currentWlDateTo   = wlDraftTo || wlDraftFrom;
        lastConfirmedDateFilter = 'custom';
        $('#wlDatePopup').addClass('hidden');
        syncTableDateFilter();
        renderCharts();
    });

    $('#wlDateCancel').on('click', function(e) {
        e.stopPropagation();
        dateFilterSelect.val(lastConfirmedDateFilter);
        $('#wlDatePopup').addClass('hidden');
    });

    // Mirrors ajax_dashboard_table.php's toolbar quick-filters (status/type/AON/
    // team/SLA) and per-column + global search, applied client-side against the
    // full chartRawData set so the workload/SLA charts stay in sync with whatever
    // #prTable's toolbar is currently showing. Shared lookups (compiled regex,
    // pic->team map) are built ONCE per renderCharts() call via this context
    // object, not recomputed inside the per-row predicate below.
    function buildChartFilterContext() {
        // Keyed lowercase/trimmed -- assigned_pic can drift in case from users.name.
        // picCanonicalName lets the workload chart merge those variants back onto the
        // one proper-case name from users.name instead of drawing a separate bar.
        var picToNpTeam = {};
        var picCanonicalName = {};
        for (var t in npPicsByTeam) {
            (npPicsByTeam[t] || []).forEach(function(pic) {
                var key = (pic || '').trim().toLowerCase();
                if (key) { picToNpTeam[key] = t; picCanonicalName[key] = pic; }
            });
        }

        function compileRegex(pattern) {
            if (!pattern) return null;
            try { return new RegExp(pattern, 'i'); } catch (e) { return null; }
        }

        return {
            picToNpTeam: picToNpTeam,
            picCanonicalName: picCanonicalName,
            ticketNoSearch: (table.column(1).search() || '').toLowerCase(),
            itemDescSearch: (table.column(3).search() || '').toLowerCase(),
            canvassingSearch: (table.column(7).search() || '').toLowerCase(),
            poNumberSearch: (table.column(8).search() || '').toLowerCase(),
            statusRegex: compileRegex(table.column(4).search()),
            picRegex: compileRegex(table.column(5).search()),
            requestorRegex: compileRegex(table.column(6).search()),
            siteRegex: compileRegex(table.column(20).search()),
            globalWords: (table.search() || '').trim().toLowerCase().split(/\s+/).filter(Boolean)
        };
    }

    // Quick-filter buttons (Status/Type/AON/Team/SLA) -- reads the same
    // currentStatusFilters/currentTypeFilter/... state the table's own ajax `gf`
    // payload is built from.
    function chartRowMatchesQuickFilters(row, ctx) {
        // 1. Status -- everything picked ORs together, mirroring ajax_dashboard_table.php.
        if (!rowMatchesStatusFilters(row)) return false;

        // 2. Type
        var typeVal = (row.ticket_type || '').toLowerCase().trim();
        var isIntercompany = (typeVal === 'intercompany' || typeVal === 'intercompany new store');
        if (currentTypeFilter === 'intercompany') {
            if (!isIntercompany) return false;
        } else if (currentTypeFilter === 'non-intercompany') {
            if (isIntercompany) return false;
        } else if (currentTypeFilter === 'aon') {
            var aonVal = (row.aon_status || 'none').toLowerCase().trim();
            if (aonVal === '' || aonVal === 'none') return false;
            if (currentAonLabel && aonVal !== currentAonLabel) return false;
        }

        // 2b. Team lives in chartRowMatchesTeamFilter() below, NOT here: the Team
        // Workload overview must keep drawing every team even while one is selected,
        // so it needs the team check separable from the rest of the quick filters.

        // 3. SLA quick filter -- 'na' folds into overdue, same rule slaBucket()
        // uses, unless the SLA pop-up asked for its exact bucket.
        if (currentSlaFilter) {
            var rowSla = row.sla_status || 'na';
            if (rowSla === 'na' && !slaAlertExact) rowSla = 'overdue';
            if (rowSla !== currentSlaFilter) return false;
        }

        return true;
    }

    // Team quick filter (unified team acronym / project code), split out of
    // chartRowMatchesQuickFilters() so callers can opt out of just this one check.
    // Non-project rows resolve their team through the PIC first, because the row's own
    // np_team can disagree with the team that PIC actually belongs to.
    function chartRowMatchesTeamFilter(row, ctx) {
        if (!currentTeamFilter) return true;
        if ((row._row_type || 'nonproject') === 'nonproject') {
            var assignedPic = row.assigned_pic || '';
            var picActualTeam = ctx.picToNpTeam[assignedPic.trim().toLowerCase()];
            if (picActualTeam) return picActualTeam === currentTeamFilter;
            return assignedPic === currentTeamFilter || (row.np_team || '') === currentTeamFilter;
        }
        return (row.team_code || '') === currentTeamFilter;
    }

    // Toolbar column filters (Ticket No/Item Description/Canvassing No/PO Number
    // text inputs, Status/Requestor/Assigned PIC checkbox dropdowns) + the Global
    // Search box -- read straight from the DataTables column/global search state
    // via ctx (built once by buildChartFilterContext()).
    function chartRowMatchesToolbar(row, ctx) {
        if (ctx.ticketNoSearch && String(row.ticket_no || '').toLowerCase().indexOf(ctx.ticketNoSearch) === -1) return false;
        if (ctx.itemDescSearch && String(row.item_description || '').toLowerCase().indexOf(ctx.itemDescSearch) === -1) return false;
        if (ctx.canvassingSearch && String(row.canvassing_no || '').toLowerCase().indexOf(ctx.canvassingSearch) === -1) return false;
        if (ctx.poNumberSearch && String(row.po_number || '').toLowerCase().indexOf(ctx.poNumberSearch) === -1) return false;

        if (ctx.statusRegex && !ctx.statusRegex.test(row.display_status || '')) return false;
        if (ctx.requestorRegex && !ctx.requestorRegex.test(row.requestor_name || '')) return false;
        if (ctx.picRegex && !ctx.picRegex.test(row.assigned_pic || row.resolved_officer || 'Unassigned')) return false;
        if (ctx.siteRegex && !ctx.siteRegex.test(row.site_allocation || '')) return false;

        if (ctx.globalWords.length) {
            var hay = [
                row.ticket_no, row.sla_status, row.item_description, row.display_status,
                row.requestor_name, (row.assigned_pic || row.resolved_officer || 'Unassigned'),
                row.canvassing_no, row.po_number, row.ticket_helpdesk_no, row.p_group, row.project_group_name, row.team_code
            ].join(' ').toLowerCase();
            for (var i = 0; i < ctx.globalWords.length; i++) {
                if (hay.indexOf(ctx.globalWords[i]) === -1) return false;
            }
        }

        return true;
    }

    function renderCharts() {
        var metric = metricSelect.val();
        var slaStacked = true;
        // Done dropped from the legend/bars entirely when the toggle is off, rather
        // than rendering an always-empty segment (rows are also excluded below).
        var activeSlaDefs = includeDoneInCharts ? SLA_STATUS_DEFS : SLA_STATUS_DEFS.filter(function(s) { return s.key !== 'done'; });
        var filterCtx = buildChartFilterContext();
        var dateRange = resolveDateFilter({
            filter: lastConfirmedDateFilter,
            from: currentWlDateFrom,
            to: currentWlDateTo
        });
        var dateFrom = dateRange ? dateRange.from : null;
        var dateTo = dateRange ? dateRange.to : null;

        // Group data by Team and PIC, after applying the PR Date filter. The View
        // select only changes how each bar is drawn (single total vs. split into
        // SLA segments) -- it never removes rows.
        var teamMap = {};
        var picMap = {};

        chartRawData.filter(function(row) {
            if (dateFrom || dateTo) {
                // pr_date may come back as "YYYY-MM-DD HH:MM:SS" -- compare on the
                // date part only so a row timestamped on the boundary day isn't
                // dropped by a trailing time-of-day suffix.
                var rowDate = row.pr_date ? String(row.pr_date).slice(0, 10) : '';
                if (!rowDate) return false;
                if (dateFrom && rowDate < dateFrom) return false;
                if (dateTo && rowDate > dateTo) return false;
            }
            if (!includeDoneInCharts && (row.sla_status || 'na') === 'done') return false;
            if (!chartRowMatchesQuickFilters(row, filterCtx)) return false;
            if (!chartRowMatchesToolbar(row, filterCtx)) return false;
            return true;
        }).forEach(function(row) {
            var t = row.team_name;
            // Merged onto the canonical users.name casing so e.g. "AGUSTRIANI UTAMI" and
            // "Agustriani Utami" draw as one bar.
            var rawPic = (row.assigned_pic || '').trim();
            var p = filterCtx.picCanonicalName[rawPic.toLowerCase()] || rawPic;

            // The Team Workload overview stays complete: selecting a team filters the
            // table and the PIC breakdown, not the overview's own axis. Collapsing it to
            // the single selected bar left nothing to compare that team against, and no
            // second bar to click. Which one is selected is shown by fading the others
            // (fadeUnselectedTeamBars), so it reads as a selection, not as all there is.
            if (!teamMap[t]) teamMap[t] = [];
            teamMap[t].push(row);

            // The PIC breakdown IS the drill-down, so it does narrow to the selected team
            // — via the toolbar's own team filter (which a bar click drives through
            // syncTeamTableFilter) plus the bar-click state itself.
            if (!chartRowMatchesTeamFilter(row, filterCtx)) return;
            if (chartTeamFilter && t !== chartTeamFilter && !forceShowAllPics) return;

            if (!picMap[p]) picMap[p] = [];
            picMap[p].push(row);
        });
        
        if (isCombined) {
            $('#teamChartContainer').removeClass('hidden');
            $('#picChartContainer').removeClass('w-full').addClass('md:w-1/2');
            if (!chartTeamFilter && !forceShowAllPics) {
                $('#picChartContainer').addClass('hidden');
                $('#teamChartContainer').removeClass('md:w-1/2').addClass('w-full');
            } else {
                $('#picChartContainer').removeClass('hidden');
                $('#teamChartContainer').removeClass('w-full').addClass('md:w-1/2');
            }
        } else {
            $('#teamChartContainer').addClass('hidden');
            $('#picChartContainer').removeClass('hidden md:w-1/2').addClass('w-full');
        }
        
        // Team Chart
        if (isCombined) {
            var teamLabels = Object.keys(teamMap).sort();
            var teamDatasets = slaStacked
                ? buildSlaStackedDatasets(teamLabels, teamMap, activeSlaDefs)
                : [{
                    label: 'Team Workload',
                    data: teamLabels.map(function(t) { return calculateMetric(teamMap[t]); }),
                    backgroundColor: 'rgba(52, 211, 153, 0.6)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }];

            fadeUnselectedTeamBars(teamLabels, teamDatasets, chartTeamFilter);

            if (chartTeamInstance) chartTeamInstance.destroy();
            var ctxTeam = document.getElementById('workloadChartTeam').getContext('2d');
            chartTeamInstance = new Chart(ctxTeam, {
                type: 'bar',
                data: {
                    labels: teamLabels,
                    datasets: teamDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onHover: chartBarCursor,
                    onClick: function(evt, elements) {
                        if (elements.length > 0) {
                            var idx = elements[0].index;
                            var clickedTeam = teamLabels[idx];
                            if (chartTeamFilter === clickedTeam) {
                                // Klik bar yang sama lagi: reset ke tampilan awal.
                                chartTeamFilter = null;
                                forceShowAllPics = false;
                                showAllBtn.addClass('hidden');
                                syncTeamTableFilter(null);
                            } else {
                                chartTeamFilter = clickedTeam;
                                forceShowAllPics = false;
                                showAllBtn.removeClass('hidden');
                                syncTeamTableFilter(clickedTeam);
                            }
                            renderCharts();
                        }
                    },
                    scales: {
                        x: { stacked: slaStacked, title: { display: true, text: 'Team' } },
                        y: {
                            stacked: slaStacked, beginAtZero: true,
                            ticks: { precision: (metric === 'po_amount' ? undefined : 0) },
                            title: { display: true, text: metricSelect.find('option:selected').text() }
                        }
                    },
                    plugins: {
                        legend: {
                            display: slaStacked, position: 'top', align: 'start',
                            labels: {
                                // With a team selected, backgroundColor is a per-bar array
                                // and Chart.js would swatch the legend with whatever the
                                // FIRST bar got — so the legend would fade itself even
                                // though it labels an SLA bucket, not the selection.
                                generateLabels: function(chart) {
                                    return chart.data.datasets.map(function(ds, i) {
                                        var c = ds.solidBg || ds.backgroundColor;
                                        return {
                                            text: ds.label,
                                            fillStyle: c,
                                            strokeStyle: c,
                                            lineWidth: 0,
                                            hidden: !chart.isDatasetVisible(i),
                                            datasetIndex: i
                                        };
                                    });
                                }
                            }
                        },
                        tooltip: { callbacks: { footer: function(items) { return items.length ? allMetricsTooltip(teamMap[teamLabels[items[0].dataIndex]]) : []; } } }
                    }
                }
            });
        }

        // PIC Chart
        if (!isCombined || chartTeamFilter || forceShowAllPics) {
            var picLabels = Object.keys(picMap).sort(function(a, b) {
                var valA = calculateMetric(picMap[a]);
                var valB = calculateMetric(picMap[b]);
                return valB - valA; // Descending
            });
            var picDatasets = slaStacked
                ? buildSlaStackedDatasets(picLabels, picMap, activeSlaDefs)
                : [{
                    label: 'PIC Workload',
                    data: picLabels.map(function(p) { return calculateMetric(picMap[p]); }),
                    backgroundColor: 'rgba(79, 70, 229, 0.6)',
                    borderColor: 'rgba(79, 70, 229, 1)',
                    borderWidth: 1,
                    borderRadius: 4
                }];

            function toInitials(name) {
                var parts = String(name).split(/\s+/).filter(Boolean).map(function(w) { return w.charAt(0).toUpperCase(); });
                return parts.join('').slice(0, 2) || String(name).slice(0, 2);
            }
            var useShort = picLabels.length > 12;
            var dispLabels = useShort ? picLabels.map(toInitials) : picLabels;

            var available = $('#workloadChartWrap').parent().width() || 0;
            var needed = picLabels.length * 46;
            $('#workloadChartWrap').css('min-width', Math.max(needed, available) + 'px');

            if (chartPicInstance) chartPicInstance.destroy();
            var ctxPic = document.getElementById('workloadChart').getContext('2d');
            chartPicInstance = new Chart(ctxPic, {
                type: 'bar',
                data: {
                    labels: dispLabels,
                    datasets: picDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onHover: chartBarCursor,
                    onClick: function(evt, elements) {
                        if (elements.length > 0) {
                            var idx = elements[0].index;
                            syncPicTableFilter(picLabels[idx]);
                        }
                    },
                    scales: {
                        x: { stacked: slaStacked, title: { display: true, text: 'PIC' } },
                        y: {
                            stacked: slaStacked, beginAtZero: true,
                            ticks: { precision: (metric === 'po_amount' ? undefined : 0) },
                            title: { display: true, text: metricSelect.find('option:selected').text() }
                        }
                    },
                    plugins: {
                        legend: { display: slaStacked, position: 'top', align: 'start' },
                        tooltip: { callbacks: {
                            title: function(items) { return items.length ? picLabels[items[0].dataIndex] : ''; },
                            footer: function(items) { return items.length ? allMetricsTooltip(picMap[picLabels[items[0].dataIndex]]) : []; }
                        } }
                    }
                }
            });
        }
    }

    metricSelect.on('change', renderCharts);
    showAllBtn.on('click', function() {
        chartTeamFilter = null;
        forceShowAllPics = true;
        $(this).addClass('hidden');
        renderCharts();
    });

    // Initial Quotation Price modal: includes/quotation_modal_ui.php (rendered
    // below). After a save only the table is refreshed, not the whole page.
    window.qmOnSaved = function() { table.ajax.reload(null, false); };

});
</script>

<?php qm_render_modal('#prTable'); ?>
<?php if ($qb_show_project || $qb_show_nonproject || $qb_show_manual) qb_render_modal(qb_guide_for($pdo, $_SESSION, $qb_show_project, $qb_show_nonproject)); ?>
<?php if ($qb_show_manual) mca_render_modal(); ?>

<?php require_once '../includes/footer.php'; ?>
