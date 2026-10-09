<?php
// pages/ajax_cost_savings_table.php
//
// Server-side data source for all four Cost Savings tables on cost_savings.php,
// selected by the `bucket` parameter (matched / review / unmatched / excluded).
//
// One endpoint rather than four because the tabs are four views of a single
// classification: a line is matched, held for review, unmatched or manually
// excluded, and includes/cost_savings_helper.php decides which in one place. If
// each tab had its own endpoint, that decision would exist in four.
//
// Filtering, ordering and paging are WHERE / ORDER BY / LIMIT, so the work per
// request is bounded by the page size rather than by the size of `prs`. The old
// page rendered every one of ~15.8k eligible lines into the HTML on every load.
require_once '../includes/auth.php';
require_once '../includes/cost_savings_access.php';
$cs_role = cs_require_access();
require_once '../config/database.php';
require_once '../includes/cost_savings_helper.php';

header('Content-Type: application/json');

function cs_table_order_expr(string $bucket, int $col): string {
    $wide = [
        0 => 'z.ticket_no',
        1 => 'z.team',
        2 => 'z.sku',
        3 => 'z.item_description',
        4 => 'z.vendor_name',
        5 => 'z.qty',
        6 => 'z.current_unit_price',
        7 => 'z.benchmark_unit',
        8 => ($bucket === 'review')
                ? 'GREATEST(z.price_ratio, 1 / NULLIF(z.price_ratio, 0))'
                : 'z.cost_savings',
        9 => 'z.history_po_count',
       10 => 'z.created_at',
    ];

    $narrow = [
        0 => 'z.ticket_no',
        1 => 'z.team',
        2 => 'z.sku',
        3 => 'z.item_description',
        4 => 'z.vendor_name',
        5 => 'z.current_unit_price',
        // "Reason" is a rendered sentence, so it sorts by what decides it.
        6 => ($bucket === 'excluded') ? 'z.correction_reason' : 'z.sku_seen_2025',
        7 => 'z.created_at',
    ];

    $map = in_array($bucket, ['matched', 'review'], true) ? $wide : $narrow;

    return $map[$col] ?? 'z.created_at';
}

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;

try {
    $bucket = (string)($_POST['bucket'] ?? 'matched');
    if (!in_array($bucket, ['matched', 'review', 'unmatched', 'excluded'], true)) {
        $bucket = 'matched';
    }

    $start  = max(0, isset($_POST['start']) ? (int)$_POST['start'] : 0);
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 25;
    if ($length <= 0 || $length > 500) $length = 25; // guard against an unbounded page
    $search = isset($_POST['search']['value']) ? (string)$_POST['search']['value'] : '';

    // AON mode comes from the session role, never from the request — see
    // cs_is_aon_only() in includes/cost_savings_access.php.
    $ctx = cs_context($pdo, cs_is_aon_only($cs_role));
    cs_prepare_benchmark($pdo, $ctx);

    $filters = cs_normalize_filters($_POST['gf'] ?? '');

    $order_parts = [];
    if (isset($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            if (!isset($o['column'])) continue;
            $dir = (isset($o['dir']) && strtolower($o['dir']) === 'asc') ? 'ASC' : 'DESC';
            $order_parts[] = cs_table_order_expr($bucket, (int)$o['column']) . ' ' . $dir;
        }
    }
    if (empty($order_parts)) $order_parts[] = 'z.created_at DESC';
    $order_by = implode(', ', $order_parts) . ', z.ticket_no ASC, z.ticket_line_item ASC';

    $lines = cs_lines_sql($pdo, $ctx, $filters);

    list($where, $params)               = cs_outer_where($filters, $bucket);
    list($search_sql, $search_params)   = cs_search_where($search);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$lines}) z WHERE {$where}");
    $stmt->execute($params);
    $records_total = (int)$stmt->fetchColumn();

    if ($search_sql === '') {
        $records_filtered = $records_total;
    } else {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$lines}) z WHERE {$where} {$search_sql}");
        $stmt->execute(array_merge($params, $search_params));
        $records_filtered = (int)$stmt->fetchColumn();
    }

    // The page of rows. Amounts are returned raw and formatted client-side, so
    // the JS renderers own presentation and this endpoint owns arithmetic.
    $sql = "SELECT z.* FROM ({$lines}) z
            WHERE {$where} {$search_sql}
            ORDER BY {$order_by}
            LIMIT :cs_limit OFFSET :cs_offset";

    $stmt = $pdo->prepare($sql);
    foreach (array_merge($params, $search_params) as $k => $v) {
        $stmt->bindValue(':' . $k, $v);
    }
    // LIMIT / OFFSET must bind as integers; PDO would otherwise quote them and
    // MySQL rejects a quoted LIMIT.
    $stmt->bindValue(':cs_limit',  $length, PDO::PARAM_INT);
    $stmt->bindValue(':cs_offset', $start,  PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // The "2025 Data" column (vendor count + sample PO) is supplementary: it
    // decorates rows the main query has already produced. Its own try/catch
    // keeps a failure here — a purchases_2025 schema drift between environments,
    // say — from emptying a table whose rows were fetched successfully. Losing
    // one column beats losing every row, so degrade instead of failing the draw.
    try {
        $details = cs_history_details($pdo, $ctx, array_map(
            fn($r) => ['sku' => $r['sku'], 'uom' => $r['uom_2025']],
            $rows
        ));
    } catch (PDOException $e) {
        error_log('ajax_cost_savings_table.php: 2025 history unavailable — ' . $e->getMessage());
        $details = [];
    }

    $data = [];
    foreach ($rows as $r) {
        $detail = $details[strtoupper(trim((string)$r['sku']) . '|' . $r['uom_2025'])] ?? null;

        $benchmark_total = (float)$r['benchmark_total'];
        $savings         = (float)$r['cost_savings'];
        $ratio           = (float)$r['price_ratio'];

        $is_excluded = (bool)(int)$r['is_excluded'];

        $data[] = [
            'ticket'        => $r['ticket_no'],
            'line'          => $r['ticket_line_item'],
            'po'            => $r['po_number'],
            'sku'           => $r['sku'],
            'item'          => $r['item_description'],
            'vendor'        => $r['vendor_name'],
            'team'          => $r['team'],
            'uom'           => $r['uom'],
            'uom2025'       => $r['uom_2025'],
            'qty'           => (float)$r['qty'],
            'currentUnit'   => (float)$r['current_unit_price'],
            'currentNett'   => (float)$r['current_nett_total'],
            'benchmarkUnit' => ($is_excluded || $r['benchmark_unit'] === null) ? null : (float)$r['benchmark_unit'],
            'benchmarkTotal'=> $is_excluded ? null : $benchmark_total,
            'savings'       => $is_excluded ? null : $savings,
            'savingsPct'    => (!$is_excluded && $benchmark_total > 0) ? ($savings / $benchmark_total) * 100 : 0,
            'priceRatio'    => $is_excluded ? null : $ratio,
            'inclusive'     => (bool)(int)$r['is_inclusive'],
            'corrected'     => (bool)(int)$r['is_corrected'],
            'excluded'      => $is_excluded,
            'reason'        => $r['correction_reason'],
            // Same two sentences the page picked between, from the same test.
            'unmatchedReason' => ((int)$r['sku_seen_2025'] === 1)
                ? 'SKU exists in 2025, but UOM does not match'
                : 'No 2025 purchase found for this SKU',
            // Drives the "Confirm Price is Correct" shortcut in the modal.
            'needsReview'   => !$is_excluded
                               && ($r['benchmark_unit'] !== null)
                               && ($ratio > CS_RATIO_HIGH || $ratio < CS_RATIO_LOW),
            'histTotal'     => $r['history_total_amount'] === null ? null : (float)$r['history_total_amount'],
            'histQty'       => $r['history_total_qty']    === null ? null : (float)$r['history_total_qty'],
            'histPoCount'   => (int)$r['history_po_count'],
            'histVendors'   => $detail['vendor_count'] ?? 0,
            'histLatestPo'  => $detail['sample_po'] ?? null,
            'histLatestDate'=> $detail['sample_date_technical'] ?? null,
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $records_total,
        'recordsFiltered' => $records_filtered,
        'data'            => $data,
    ]);
} catch (PDOException $e) {
    // Log the real exception server-side only — DataTables surfaces the "error"
    // field to the user, so it must never carry raw PDO/SQL details.
    error_log('ajax_cost_savings_table.php: ' . $e->getMessage());
    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => 'Gagal memuat tabel Cost Savings. Coba muat ulang halaman.',
    ]);
}
