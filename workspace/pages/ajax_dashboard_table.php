<?php
// pages/ajax_dashboard_table.php
// Server-side data source for the #prTable DataTable on dashboard_mgr.php.
// Row visibility/business rules (persona scope, Smart Status, AON classification,
// Project ticket+p_group aggregation) can't be plain SQL WHERE/ORDER BY -- they
// only exist after get_dashboard_rows()'s persona + business-logic pass -- so
// this endpoint re-runs that same shared logic every request and then filters/
// searches/sorts/paginates the resulting in-memory array, same as
// ajax_sla_table.php does for the SLA report. Only the current page's rows are
// ever sent back, so the browser never has to hold (or re-scan) the whole table.
require_once '../includes/auth.php';
// Read gate, not require_manager(): the read-only 'Viewer' role is admitted to
// the Command Center. Every write path off this dashboard keeps its own gate.
require_dashboard_view();
require_once '../config/database.php';
require_once '../includes/dashboard_data_helper.php';
require_once '../includes/vendor_quotation_helper.php';

header('Content-Type: application/json; charset=utf-8');

// Column index -> the value that column's per-column search (columns[n][search])
// matches against. Mirrors each column's `render(..., type === 'filter', ...)`
// branch in dashboard_mgr.php's <script>.
function dt_search_field($row, $col) {
    switch ($col) {
        case 1: return (string)($row['ticket_no'] ?? '');
        case 2: return (string)($row['sla_status'] ?? '');
        case 3: return (string)($row['item_description'] ?? '');
        case 4: return (string)($row['display_status'] ?? '');
        // Non-Project rows carry no resolved_officer key at all, so both sides
        // need ?? before ?: -- an undefined-key warning here would be echoed
        // ahead of the JSON body and break the whole response.
        case 5: return ($row['assigned_pic'] ?? '') ?: (($row['resolved_officer'] ?? '') ?: 'Unassigned');
        case 6: return (string)($row['requestor_name'] ?? '');
        case 7: return (string)($row['canvassing_no'] ?? '');
        case 8: return (string)($row['po_number'] ?? '');
        // Site Allocation has no visible column of its own — it is a hidden
        // DataTables column that exists purely so its toolbar dropdown can ride
        // the standard columns[n][search] protocol like the other dropdowns.
        // Index 20: shifted from 16 by the PO Currency Code/PO Line Amount (+2)
        // and L4/L5 (+2) columns inserted ahead of it.
        case 20: return (string)($row['site_allocation'] ?? '');
        default: return '';
    }
}

// Column index -> the value that column sorts by. Two columns differ from their
// search value: column 1 (Ticket No) sorts by pr_date so the header still drives
// a date sort even though PR Date has no column of its own, and column 2 (SLA)
// sorts numerically by sla_sort (handled separately in dt_compare -- it can be
// negative/overdue, so a string compare would order it wrong).
function dt_sort_field($row, $col) {
    if ($col === 1) return (string)($row['pr_date'] ?? '');
    return dt_search_field($row, $col);
}

function dt_compare($a, $b, $col) {
    if ($col === 2) {
        $av = (int)($a['sla_sort'] ?? 9999);
        $bv = (int)($b['sla_sort'] ?? 9999);
        return $av <=> $bv;
    }
    return strcmp(dt_sort_field($a, $col), dt_sort_field($b, $col));
}

function dt_compare_multi($a, $b, $orderList) {
    foreach ($orderList as $o) {
        $cmp = dt_compare($a, $b, $o['column']);
        if ($cmp !== 0) return $o['dir'] === 'asc' ? $cmp : -$cmp;
    }
    return 0;
}

function dt_search_haystack($row) {
    return implode(' ', [
        (string)($row['ticket_no'] ?? ''),
        (string)($row['sla_status'] ?? ''),
        (string)($row['item_description'] ?? ''),
        (string)($row['display_status'] ?? ''),
        (string)($row['requestor_name'] ?? ''),
        ($row['assigned_pic'] ?? '') ?: (($row['resolved_officer'] ?? '') ?: 'Unassigned'),
        (string)($row['canvassing_no'] ?? ''),
        (string)($row['po_number'] ?? ''),
        (string)($row['ticket_helpdesk_no'] ?? ''),
        (string)($row['p_group'] ?? ''),
        (string)($row['project_group_name'] ?? ''),
        (string)($row['team_code'] ?? ''),
        (string)($row['level_0'] ?? ''),
        (string)($row['level_1'] ?? ''),
        (string)($row['level_2'] ?? ''),
        (string)($row['level_3'] ?? ''),
        (string)($row['level_4'] ?? ''),
        (string)($row['level_5'] ?? ''),
    ]);
}

$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;

try {
    $start  = isset($_POST['start']) ? (int)$_POST['start'] : 0;
    $length = isset($_POST['length']) ? (int)$_POST['length'] : 25;
    $globalSearch = isset($_POST['search']['value']) ? trim($_POST['search']['value']) : '';

    $orderList = [];
    if (isset($_POST['order']) && is_array($_POST['order'])) {
        foreach ($_POST['order'] as $o) {
            if (!isset($o['column'])) continue;
            $orderList[] = [
                'column' => (int)$o['column'],
                'dir' => (isset($o['dir']) && strtolower($o['dir']) === 'asc') ? 'asc' : 'desc',
            ];
        }
    }
    if (empty($orderList)) $orderList[] = ['column' => 1, 'dir' => 'desc'];

    $gf = json_decode($_POST['gf'] ?? '', true);
    if (!is_array($gf)) $gf = [];
    $gfStatusList    = array_values(array_filter(array_map('strval', (array)($gf['status'] ?? []))));
    $gfManualOnly    = !empty($gf['manualOnly']);
    $gfUnassignedOnly = !empty($gf['unassignedOnly']);
    $gfType        = (string)($gf['type'] ?? '');
    $gfAonLabel    = (string)($gf['aonLabel'] ?? '');
    $gfTeam        = (string)($gf['team'] ?? '');
    $gfSla         = (string)($gf['sla'] ?? '');
    $gfSlaExact    = !empty($gf['slaExact']);
    $gfPrDateFrom  = (string)($gf['prDateFrom'] ?? '');
    $gfPrDateTo    = (string)($gf['prDateTo'] ?? '');
    $gfPoDateFrom  = (string)($gf['poDateFrom'] ?? '');
    $gfPoDateTo    = (string)($gf['poDateTo'] ?? '');

    $result = get_dashboard_rows($pdo, $_SESSION, false);
    $rows = $result['rows'];
    $npPicsByTeam = $result['np_pics_by_team'];

    $recordsTotal = count($rows);

    // --- LAST PRICE QUOTATION SUMMARY (batched, no N+1) ------------------------
    // Attached here so the "Initial Quotation Price" column's render() has data to
    // show without a per-row query. Display only; writes happen via
    // ajax_project_quotation_save.php. GP Project rows are priced per
    // canvassing (canvas price; item counts are legacy per-line prices), GP
    // Non-Project rows per ticket -- see includes/vendor_quotation_helper.php.
    try {
        // Grouped Project rows carry quotation_canvassing_no (first non-empty
        // canvassing across the ticket's lines).
        $canvassing_nos = [];
        $ticket_nos = [];
        foreach ($rows as &$r) {
            $isProject = ($r['_row_type'] ?? 'nonproject') === 'project';
            $r['lpq_canvassing_no'] = $isProject ? trim((string)($r['quotation_canvassing_no'] ?? $r['canvassing_no'] ?? '')) : '';
            if ($r['lpq_canvassing_no'] !== '') { $canvassing_nos[$r['lpq_canvassing_no']] = true; }
            if (!$isProject && trim((string)($r['ticket_no'] ?? '')) !== '') { $ticket_nos[trim((string)$r['ticket_no'])] = true; }
        }
        unset($r);
        $quotation_canvas_map = [];
        if (!empty($canvassing_nos)) {
            $ph = implode(',', array_fill(0, count($canvassing_nos), '?'));
            $stmt = $pdo->prepare("SELECT canvassing_no, last_price, currency FROM vendor_quotation_last_price WHERE scope = 'canvas' AND canvassing_no IN ($ph)");
            $stmt->execute(array_keys($canvassing_nos));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $quotation_canvas_map[$r['canvassing_no']] = $r; }
        }
        $quotation_canv_count_map   = quotation_item_counts_by_canvassing($pdo, array_keys($canvassing_nos));
        $quotation_ticket_count_map = quotation_item_counts_by_ticket($pdo, array_keys($ticket_nos));
        $quotation_ticket_total_map = quotation_line_totals_by_ticket($pdo, array_keys($ticket_nos));
        foreach ($rows as &$r) {
            $canv = $r['lpq_canvassing_no'];
            $canvasQ = $canv !== '' ? ($quotation_canvas_map[$canv] ?? null) : null;
            $r['lpq_canvas_price']    = $canvasQ ? (float)$canvasQ['last_price'] : null;
            $r['lpq_canvas_currency'] = $canvasQ ? (string)$canvasQ['currency'] : null;
            $r['lpq_item_count']      = ($r['_row_type'] ?? 'nonproject') === 'project'
                ? ($canv !== '' ? ($quotation_canv_count_map[$canv] ?? 0) : 0)
                : ($quotation_ticket_count_map[trim((string)($r['ticket_no'] ?? ''))] ?? 0);
            $r['lpq_item_total']      = ($r['_row_type'] ?? 'nonproject') === 'project'
                ? 0
                : ($quotation_ticket_total_map[trim((string)($r['ticket_no'] ?? ''))] ?? 0);
        }
        unset($r);
    } catch (PDOException $e) {
        // Fail open: table not deployed yet just means every row shows "Add quotation".
    }

    // --- Toolbar quick-filters (status / type / AON / team / PR Date / PO Date) ---
    $filtered = array_values(array_filter($rows, function($row) use (
        $gfStatusList, $gfManualOnly, $gfUnassignedOnly, $gfType, $gfAonLabel, $gfTeam, $gfSla, $gfSlaExact, $gfPrDateFrom, $gfPrDateTo, $gfPoDateFrom, $gfPoDateTo, $npPicsByTeam
    ) {
        // 1. Status quick filter -- statuses OR together; Manual / Unassigned AND on top.
        if (!empty($gfStatusList) && !in_array((string)($row['display_status'] ?? ''), $gfStatusList, true)) {
            return false;
        }
        if ($gfManualOnly && ((int)($row['assigned_by_ai'] ?? 0) !== 0 || empty($row['assigned_pic']))) return false;
        if ($gfUnassignedOnly) {
            // "Unassigned" mirrors the exact fallback used for display/search
            // (dt_search_field case 5): no assigned_pic AND no resolved GP-officer hint.
            if (!empty($row['assigned_pic']) || !empty($row['resolved_officer'] ?? '')) return false;
        }

        // 2. Type quick filter
        $typeVal = strtolower(trim((string)($row['ticket_type'] ?? '')));
        $isIntercompany = in_array($typeVal, ['intercompany', 'intercompany new store'], true);
        if ($gfType === 'intercompany') {
            if (!$isIntercompany) return false;
        } elseif ($gfType === 'non-intercompany') {
            if ($isIntercompany) return false;
        } elseif ($gfType === 'aon') {
            $aonVal = strtolower(trim((string)($row['aon_status'] ?? 'none')));
            if ($aonVal === '' || $aonVal === 'none') return false;
            if ($gfAonLabel !== '' && $aonVal !== $gfAonLabel) return false;
        }

        // 3. Team filter (Unified for Non-Project team acronyms and Project group codes)
        if ($gfTeam !== '') {
            $isMatch = false;
            
            // Check non-project logic
            if (($row['_row_type'] ?? 'nonproject') === 'nonproject') {
                $assignedPic = (string)($row['assigned_pic'] ?? '');
                $picActualTeam = null;
                foreach ($npPicsByTeam as $t => $pics) {
                    if (in_array($assignedPic, $pics, true)) { $picActualTeam = $t; break; }
                }
                if ($picActualTeam !== null) {
                    if ($picActualTeam === $gfTeam) $isMatch = true;
                } else {
                    if ($assignedPic === $gfTeam || (string)($row['np_team'] ?? '') === $gfTeam) $isMatch = true;
                }
            } else {
                // Check project logic
                if ((string)($row['team_code'] ?? '') === $gfTeam) {
                    $isMatch = true;
                }
            }
            
            if (!$isMatch) return false;
        }

        // 3b. SLA quick filter (on_track / due_soon / overdue / done -- see sla_rules.php::sla_evaluate()).
        // 'na' rows (no PR date, countdown never started) count as Overdue -- same
        // rule dashboard_mgr.php's slaBucket() uses for the workload chart. 'done' rows
        // (PO already created) are a real sla_status value, so they match directly below.
        //
        // slaExact turns that folding off. It is set only by the SLA pop-up's own
        // hand-off (includes/sla_alert.php via window.slaAlertApplyFilter), which
        // counts 'na' as neither colour -- without this the table would answer a
        // click on "379 lines" with the 'na' rows on top.
        if ($gfSla !== '') {
            $rowSla = (string)($row['sla_status'] ?? 'na');
            if ($rowSla === 'na' && !$gfSlaExact) $rowSla = 'overdue';
            if ($rowSla !== $gfSla) return false;
        }

        // 4. PR Date range (inclusive; ISO 'YYYY-MM-DD' strings compare lexicographically)
        if ($gfPrDateFrom !== '' || $gfPrDateTo !== '') {
            $prDate = substr((string)($row['pr_date'] ?? ''), 0, 10);
            if ($prDate === '') return false;
            if ($gfPrDateFrom !== '' && $prDate < $gfPrDateFrom) return false;
            if ($gfPrDateTo !== '' && $prDate > $gfPrDateTo) return false;
        }

        // 5. PO Date range -- same rule as the PO Date column: no PO number, no date
        if ($gfPoDateFrom !== '' || $gfPoDateTo !== '') {
            $poDate = !empty($row['po_number']) ? substr((string)($row['po_create_date'] ?? ''), 0, 10) : '';
            if ($poDate === '') return false;
            if ($gfPoDateFrom !== '' && $poDate < $gfPoDateFrom) return false;
            if ($gfPoDateTo !== '' && $poDate > $gfPoDateTo) return false;
        }

        return true;
    }));

    // --- Per-column search (DataTables' native columns[n][search] protocol) ---
    // Covers the free-text inputs (Ticket No/Item Description/Canvassing No/PO
    // Number) and the checkbox-dropdown filters (Status/Requestor/Assigned PIC,
    // which submit an already-escaped '^(A|B)$' regex) without any change
    // needed on the client -- serverSide mode sends this for every column
    // automatically whenever table.column(n).search(...) is called.
    if (isset($_POST['columns']) && is_array($_POST['columns'])) {
        foreach ($_POST['columns'] as $colIdx => $colDef) {
            $val = isset($colDef['search']['value']) ? trim((string)$colDef['search']['value']) : '';
            if ($val === '') continue;
            $isRegex = isset($colDef['search']['regex']) && $colDef['search']['regex'] === 'true';
            $colIdx = (int)$colIdx;
            $filtered = array_values(array_filter($filtered, function($row) use ($colIdx, $val, $isRegex) {
                $field = dt_search_field($row, $colIdx);
                if ($isRegex) {
                    return @preg_match('#' . str_replace('#', '\#', $val) . '#i', $field) === 1;
                }
                return stripos($field, $val) !== false;
            }));
        }
    }

    // --- Global search ----------------------------------------------------------
    if ($globalSearch !== '') {
        $words = preg_split('/\s+/', $globalSearch, -1, PREG_SPLIT_NO_EMPTY);
        $filtered = array_values(array_filter($filtered, function($row) use ($words) {
            $hay = strtolower(dt_search_haystack($row));
            foreach ($words as $w) {
                if (strpos($hay, strtolower($w)) === false) return false;
            }
            return true;
        }));
    }

    $recordsFiltered = count($filtered);

    usort($filtered, function($a, $b) use ($orderList) {
        return dt_compare_multi($a, $b, $orderList);
    });

    $page = ($length > 0) ? array_slice($filtered, $start, $length) : $filtered;

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $page,
    ]);
} catch (PDOException $e) {
    error_log('ajax_dashboard_table.php: ' . $e->getMessage());
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'Gagal memuat data dashboard. Coba muat ulang halaman.',
    ]);
}
