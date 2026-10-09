<?php
// pages/ajax_cost_avoidance_table.php
//
// Server-side data source for the #reportTable DataTables on cost_avoidance.php.
//
// Unlike ajax_sla_table.php — which re-runs a shared PHP business-logic pass and
// then array_slice()s the result — every stage here happens in SQL: the tax /
// currency / cost-avoidance derivations are expressions (includes/
// cost_avoidance_helper.php), and filtering, ordering and paging are WHERE /
// ORDER BY / LIMIT. The work per request is therefore bounded by the page size,
// not by the size of `prs`.
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/cost_avoidance_helper.php';
// Team-scoped access gate (replaces the old require_manager()-only gate) —
// DB-backed, so it must come after database.php + cost_avoidance_helper.php.
// $ca_team_scope: null = unrestricted, [] = deny (already redirected by the
// gate itself), non-empty = the session's allowed team codes.
$ca_team_scope = require_cost_avoidance_access($pdo);

header('Content-Type: application/json');

/**
 * Column index -> ORDER BY expression. Mirrors the <thead> in cost_avoidance.php.
 * A whitelist by design: the index arrives from the client and must never be
 * able to reach the SQL text.
 *
 * Index 6 is the Action column, which holds buttons and is not meaningfully
 * sortable. It maps to created_at because that is what the table was already
 * ordered by in practice: the page shipped `order: [[6, "desc"]]`, DataTables
 * sorted the identical button markup (a no-op), and rows kept the query's
 * "ORDER BY p.created_at DESC". Mapping it here preserves that exact order.
 */
// The Savings Report is served as a UNION of a `prs` arm and a manual-GP arm
// (see the try block below), wrapped in a derived table `u`. Ordering therefore
// happens on `u`'s output columns, not the raw `prs`/`mca` expressions — the
// arms each expose the sort keys as srt_* columns so both sides order by the
// same rule. This is a whitelist by design: the index arrives from the client
// and must never reach the SQL text.
//
// Index 6 is the Action column (buttons, not sortable). It maps to srt_created
// because that is what the table was already ordered by — the page ships
// `order: [[6, "desc"]]`, so this preserves "most recently created first".
function ca_table_order_expr(int $col): string {
    switch ($col) {
        case 0: return 'u.po_number';
        case 1: return 'u.team';
        case 2: return 'u.item_description';
        case 3: return 'u.srt_po_idr';
        case 4: return 'u.srt_baseline_total_idr';
        case 5: return 'u.cost_avoidance';
        case 6: return 'u.srt_created';
        default: return 'u.srt_created';
    }
}

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;

try {
    $start  = max(0, isset($_POST['start']) ? (int)$_POST['start'] : 0);
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 25;
    if ($length <= 0 || $length > 500) $length = 25; // guard against an unbounded page
    $search = isset($_POST['search']['value']) ? (string)$_POST['search']['value'] : '';

    $filters = ca_normalize_filters($_POST['gf'] ?? '');
    $ctx     = ca_context($pdo);

    // ORDER BY, rebuilt from the client's order[] array. DataTables supports
    // shift-click multi-column sorting, so every entry is honoured in turn.
    $order_parts = [];
    if (isset($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            if (!isset($o['column'])) continue;
            $dir = (isset($o['dir']) && strtolower($o['dir']) === 'asc') ? 'ASC' : 'DESC';
            $order_parts[] = ca_table_order_expr((int)$o['column']) . ' ' . $dir;
        }
    }
    if (empty($order_parts)) $order_parts[] = 'u.srt_created DESC';
    $order_by = implode(', ', $order_parts);

    // The Savings Report only ever shows lines that already carry a baseline.
    // The team filter lives in $filters and is applied by ca_from_where().
    // $unfiltered resets the client's own team choice so recordsTotal/
    // totalAvoidance always reflect every team the session CAN see, ignoring
    // the team buttons — but $ca_team_scope is passed to BOTH calls below,
    // unaffected by that reset, so a team-scoped session's "Total Cost
    // Avoidance" figure never silently becomes the grand total across every
    // team (the exact bug Addendum #3 caught before it could ship).
    $unfiltered = $filters;
    $unfiltered['team'] = '';
    list($base_fw, $base_params) = ca_from_where($ctx, $unfiltered, 'report', null, $ca_team_scope);
    list($fw, $params)           = ca_from_where($ctx, $filters, 'report', null, $ca_team_scope);

    list($search_sql, $search_params) = ca_search_where($ctx, $search);
    $params = array_merge($params, $search_params);

    // Manual GP arm — the Savings Report also surfaces the "Monitoring GP Project"
    // cost avoidance backfilled into manual_cost_avoidance (see the section note in
    // cost_avoidance_helper.php). It mirrors the prs fragments above one-for-one:
    // an $unfiltered base (date + session scope, no team button) for the totals,
    // and a filtered variant (team button + search) for the shown rows. The prs
    // queries below are completely unchanged; the manual figures are simply ADDED
    // to them, so the prs side can never be perturbed by this.
    list($m_base_fw, $m_base_params) = ca_manual_report_from_where($ctx, $unfiltered, $ca_team_scope, 'mb');
    list($m_fw, $m_params)           = ca_manual_report_from_where($ctx, $filters, $ca_team_scope, 'mf');
    list($m_search_sql, $m_search_params) = ca_manual_report_search_where($ctx, $search, 'mf');
    $m_params = array_merge($m_params, $m_search_params);
    $m_ca_idr = !empty($ctx['has_manual_fx'])
        ? ca_currency_case_expr_rate('mca.total_cost_avoidance', 'mca.currency', 'mca.fx_rate', ca_effective_currencies($ctx), ca_is_strict_ctx($ctx))
        : ca_currency_case_expr('mca.total_cost_avoidance', 'mca.currency', ca_effective_currencies($ctx), ca_is_strict_ctx($ctx));

    // recordsTotal ignores the team filter and the search box (DataTables shows it
    // as the "filtered from N total entries" denominator), but still respects the
    // Global Date Filter, which is what defines the report's universe.
    $stmt = $pdo->prepare("SELECT COUNT(*) {$base_fw}");
    $stmt->execute($base_params);
    $records_total = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) {$m_base_fw}");
    $stmt->execute($m_base_params);
    $records_total += (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) {$fw} {$search_sql}");
    $stmt->execute($params);
    $records_filtered = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) {$m_fw} {$m_search_sql}");
    $stmt->execute($m_params);
    $records_filtered += (int)$stmt->fetchColumn();

    // Total Cost Avoidance for the <tfoot>. Scoped to the SAME filters as the rows
    // shown — team button + search + date — so the figure reflects exactly what the
    // user is currently looking at (previously it stayed at the grand total and
    // ignored the team button, which read as a bug). It still spans every page, not
    // just the visible one, since server-side paging makes it underivable from the
    // rendered rows. The session's own $ca_team_scope is baked into $fw/$m_fw
    // regardless, so a team-scoped session can never see beyond its scope. The
    // manual GP avoidance (converted to IDR the same way) is added to the total.
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(" . ca_avoidance_expr($ctx) . "), 0) {$fw} {$search_sql}");
    $stmt->execute($params);
    $total_avoidance = (float)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COALESCE(SUM({$m_ca_idr}), 0) {$m_fw} {$m_search_sql}");
    $stmt->execute($m_params);
    $total_avoidance += (float)$stmt->fetchColumn();

    // The page of rows. Amounts are returned raw (plus their IDR conversions) and
    // formatted client-side, so the JS renderers own presentation and this
    // endpoint owns arithmetic. The prs arm's SELECT is unchanged except for the
    // trailing srt_* sort keys + row_source discriminator that let it UNION with
    // the manual GP arm and be ordered/paged as one set (see ca_table_order_expr).
    $prs_arm = "SELECT
                p.ticket_no,
                p.ticket_line_item,
                p.po_number,
                p.p_org,
                p.sku,
                p.item_description,
                p.uom,
                p.vendor_name,
                p.qty          + 0e0 AS qty,
                p.po_amount    + 0e0 AS po_amount,
                COALESCE(p.po_currency, 'IDR')       AS po_currency,
                p.baseline_price + 0e0               AS baseline_price,
                COALESCE(p.baseline_currency, 'IDR') AS baseline_currency,
                p.quotation_link,
                " . ca_team_expr($ctx)        . " AS team,
                " . ca_is_inclusive_expr()   . " AS is_inclusive,
                " . ca_nett_unit_expr($ctx)  . " AS nett_unit_price,
                " . ca_baseline_idr_expr($ctx) . " AS baseline_idr,
                " . ca_avoidance_expr($ctx)  . " AS cost_avoidance,
                " . ca_po_idr_expr($ctx, true) . " AS srt_po_idr,
                (" . ca_baseline_idr_expr($ctx) . " * (p.qty + 0e0)) AS srt_baseline_total_idr,
                p.created_at AS srt_created,
                'PRS' AS row_source
            {$fw} {$search_sql}";

    $manual_arm = ca_manual_report_select($ctx) . " {$m_fw} {$m_search_sql}";

    $sql = "SELECT * FROM ( {$prs_arm} UNION ALL {$manual_arm} ) u
            ORDER BY {$order_by}
            LIMIT :ca_limit OFFSET :ca_offset";

    $stmt = $pdo->prepare($sql);
    foreach (array_merge($params, $m_params) as $k => $v) {
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
            'ticket'        => $r['ticket_no'],
            'line'          => $r['ticket_line_item'],
            'po'            => $r['po_number'],
            'porg'          => $r['p_org'],
            'sku'           => $r['sku'],
            'item'          => $r['item_description'],
            'uom'           => $r['uom'],
            'vendor'        => $r['vendor_name'],
            'team'          => $r['team'],
            'qty'           => (float)$r['qty'],
            'poAmount'      => (float)$r['po_amount'],
            'poCurrency'    => $r['po_currency'],
            'baseline'      => (float)$r['baseline_price'],
            'baseCurrency'  => $r['baseline_currency'],
            'baselineIdr'   => (float)$r['baseline_idr'],
            'nettUnit'      => (float)$r['nett_unit_price'],
            'avoidance'     => (float)$r['cost_avoidance'],
            'inclusive'     => (bool)(int)$r['is_inclusive'],
            'quotationLink' => $r['quotation_link'],
            // 'PRS' = a real PO line (editable), 'MANUAL' = a GP backfill row
            // (no prs ticket line to edit — the Action cell suppresses its
            // edit/remove buttons on the strength of this flag).
            'source'        => $r['row_source'],
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $records_total,
        'recordsFiltered' => $records_filtered,
        'data'            => $data,
        'totalAvoidance'  => $total_avoidance,
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — DataTables surfaces the "error"
    // field to the user, so it must never carry raw PDO/SQL details.
    error_log('ajax_cost_avoidance_table.php: ' . $e->getMessage());
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'totalAvoidance'  => 0,
        'error'           => 'Gagal memuat Savings Report. Coba muat ulang halaman.',
    ]);
}
