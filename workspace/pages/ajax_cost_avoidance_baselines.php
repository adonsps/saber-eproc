<?php
// pages/ajax_cost_avoidance_baselines.php
//
// Server-side data source for the Input Baselines tab on cost_avoidance.php —
// the PO lines that still have no quotation baseline entered.
//
// This tab was by far the heaviest thing on the page. It rendered every group AND
// every PO line inside every group as editable forms, on page load, inside a tab
// that starts hidden. Measured on the local Docker copy: 5,569 groups / 9,900 PO
// lines / ~31,000 <form> / ~134,000 <input> — 78.3 MB of HTML, 99.9% of the page.
//
// Two modes, kept in one file so there is a single auth surface and a single
// definition of what a "group" is:
//
//   mode=groups (default) — DataTables server-side contract, one row per group
//                           (item + vendor + uom + team), one page at a time.
//   mode=detail           — the PO lines of ONE group, fetched only when the user
//                           actually expands it. Previously all 9,900 lines were
//                           sent up front even though a user opens a handful.
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/cost_avoidance_helper.php';
// Team-scoped access gate (replaces the old require_manager()-only gate) —
// DB-backed, so it must come after database.php + cost_avoidance_helper.php.
// $ca_team_scope: null = unrestricted, [] = deny (already redirected by the
// gate itself), non-empty = the session's allowed team codes.
$ca_team_scope = require_cost_avoidance_access($pdo);
// The scope gate resolves Viewer to "unrestricted read"; this endpoint also
// saves baselines, so the write half is refused separately.
ws_require_write();

header('Content-Type: application/json');

/**
 * Column index -> ORDER BY expression for the group list. A whitelist by design:
 * the index arrives from the client and must never reach the SQL text. Column 2
 * is the price-entry form, which is not data and so is not orderable.
 */
function ca_baselines_order_expr(int $col): string {
    switch ($col) {
        case 0:  return 'p.item_description';
        case 1:  return 'p.vendor_name';
        default: return 'p.item_description';
    }
}

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
$mode = isset($_POST['mode']) ? (string)$_POST['mode'] : 'groups';

try {
    $filters  = ca_normalize_filters($_POST['gf'] ?? ''); // Global Date Filter
    $bfilters = ca_normalize_filters($_POST['bf'] ?? ''); // this tab's own canvassing-date filter
    $ctx      = ca_context($pdo);

    if ($mode === 'detail') {
        // --- The PO lines of one group --------------------------------------
        $group = [
            'item'   => (string)($_POST['item']   ?? ''),
            'vendor' => (string)($_POST['vendor'] ?? ''),
            'uom'    => (string)($_POST['uom']    ?? ''),
            'team'   => (string)($_POST['team']   ?? ''),
        ];

        // Explicit team-set membership check per Addendum #1 §A1 point 2 — do
        // NOT rely on ca_from_where()/ca_group_where()'s forced-scope IN(...)
        // below as the actual control (two independently forced values
        // happening to both apply is a coincidental AND, fragile against a
        // future refactor of either builder). A crafted mode=detail request
        // naming a team outside the session's scope is rejected outright,
        // before any query runs.
        if ($ca_team_scope !== null && !in_array($group['team'], $ca_team_scope, true)) {
            echo json_encode(['rows' => []]);
            exit;
        }

        list($fw, $params)       = ca_from_where($ctx, $filters, 'baselines', $bfilters, $ca_team_scope);
        list($grp_sql, $grp_prm) = ca_group_where($ctx, $group, $ca_team_scope);
        $params = array_merge($params, $grp_prm);

        $sql = "SELECT
                    p.ticket_no,
                    p.ticket_line_item,
                    p.po_number,
                    p.canvassing_date,
                    p.qty       + 0e0 AS qty,
                    p.po_amount + 0e0 AS po_amount,
                    COALESCE(p.po_currency, 'IDR') AS po_currency,
                    " . ca_is_inclusive_expr()  . " AS is_inclusive,
                    " . ca_nett_unit_expr($ctx) . " AS nett_unit_price
                {$fw} {$grp_sql}
                ORDER BY p.created_at DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = [];
        foreach ($stmt as $r) {
            $rows[] = [
                'ticket'     => $r['ticket_no'],
                'line'       => $r['ticket_line_item'],
                'po'         => $r['po_number'],
                'canvassing' => $r['canvassing_date'],
                'qty'        => (float)$r['qty'],
                'poAmount'   => (float)$r['po_amount'],
                'poCurrency' => $r['po_currency'],
                'nettUnit'   => (float)$r['nett_unit_price'],
                'inclusive'  => (bool)(int)$r['is_inclusive'],
            ];
        }

        echo json_encode(['rows' => $rows]);
        exit;
    }

    // --- The group list -----------------------------------------------------
    $start  = max(0, isset($_POST['start']) ? (int)$_POST['start'] : 0);
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 25;
    if ($length <= 0 || $length > 200) $length = 25; // guard against an unbounded page
    $search = isset($_POST['search']['value']) ? (string)$_POST['search']['value'] : '';

    $order_parts = [];
    if (isset($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            if (!isset($o['column'])) continue;
            $dir = (isset($o['dir']) && strtolower($o['dir']) === 'asc') ? 'ASC' : 'DESC';
            $order_parts[] = ca_baselines_order_expr((int)$o['column']) . ' ' . $dir;
        }
    }
    if (empty($order_parts)) $order_parts[] = 'p.item_description ASC';
    $order_by = implode(', ', $order_parts);

    $team_expr = ca_team_expr($ctx);

    // These four columns are exactly the md5() ingredients the page bucketed the
    // tab with, so the groups produced here match the old ones one for one.
    $group_by = "p.item_description, p.vendor_name, p.uom, {$team_expr}";

    // $unfiltered resets the client's own team choice ($filters['team']) so
    // recordsTotal always reflects every team the session CAN see, ignoring
    // the Quick-Filter buttons — but $ca_team_scope is passed to BOTH calls
    // below, unaffected by that reset, so a team-scoped session's total never
    // silently becomes the grand total across every team (Addendum #3 §C1).
    $unfiltered = $filters;
    $unfiltered['team'] = '';
    list($base_fw, $base_params) = ca_from_where($ctx, $unfiltered, 'baselines', $bfilters, $ca_team_scope);
    list($fw, $params)           = ca_from_where($ctx, $filters, 'baselines', $bfilters, $ca_team_scope);

    list($search_sql, $search_params) = ca_baselines_search_where($ctx, $search);
    $params = array_merge($params, $search_params);

    // Counting a grouped set needs the grouping to happen first, hence the derived
    // table. recordsTotal drives the tab's red counter badge and ignores the team
    // buttons and the search box — matching what count($missing_grouped) reported.
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 {$base_fw} GROUP BY {$group_by}) g");
    $stmt->execute($base_params);
    $records_total = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM (SELECT 1 {$fw} {$search_sql} GROUP BY {$group_by}) g");
    $stmt->execute($params);
    $records_filtered = (int)$stmt->fetchColumn();

    // MAX(p.sku) rather than "the SKU of the first row", which is what the PHP loop
    // happened to take. Within a group every row shares item + vendor + uom, so the
    // SKU is normally identical (8 of 7,600 groups differ on the local copy); MAX
    // just makes the choice deterministic instead of dependent on row order, and it
    // only affects the cosmetic "[SKU]" prefix.
    // MAX(p.ca_taxonomy_level_2) for the same reason as MAX(p.sku): a group's
    // lines should already agree once a Project taxonomy has been saved
    // (save_ca_taxonomy writes it across the whole group), MAX just picks a
    // single deterministic value to pre-select the dropdown with.
    $sql = "SELECT
                p.item_description  AS item,
                p.vendor_name       AS vendor,
                p.uom               AS uom,
                {$team_expr}        AS team,
                MAX(p.sku)          AS sku,
                MAX(p.ca_taxonomy_level_2) AS ca_taxonomy_level_2,
                COUNT(*)            AS po_count
            {$fw} {$search_sql}
            GROUP BY {$group_by}
            ORDER BY {$order_by}
            LIMIT :ca_limit OFFSET :ca_offset";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    // LIMIT / OFFSET must bind as integers; PDO would otherwise quote them and
    // MySQL rejects a quoted LIMIT.
    $stmt->bindValue(':ca_limit',  $length, PDO::PARAM_INT);
    $stmt->bindValue(':ca_offset', $start,  PDO::PARAM_INT);
    $stmt->execute();

    $data = [];
    foreach ($stmt as $r) {
        $data[] = [
            'item'             => $r['item'],
            'vendor'           => $r['vendor'],
            'uom'              => $r['uom'],
            'team'             => $r['team'],
            'sku'              => $r['sku'],
            'caTaxonomyLevel2' => $r['ca_taxonomy_level_2'],
            'poCount'          => (int)$r['po_count'],
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $records_total,
        'recordsFiltered' => $records_filtered,
        'data'            => $data,
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — the "error" field reaches the user,
    // so it must never carry raw PDO/SQL details.
    error_log('ajax_cost_avoidance_baselines.php: ' . $e->getMessage());
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'rows'            => [],
        'error'           => 'Gagal memuat antrian Input Baselines. Coba muat ulang halaman.',
    ]);
}
