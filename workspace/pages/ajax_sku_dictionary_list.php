<?php
// pages/ajax_sku_dictionary_list.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/synonym_dictionary.php';
require_once '../includes/aon_helper.php';

header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';

// DataTables parameters
$draw = isset($_POST['draw']) ? (int)$_POST['draw'] : 1;
$start = isset($_POST['start']) ? (int)$_POST['start'] : 0;
$length = isset($_POST['length']) ? (int)$_POST['length'] : 50;
$searchValue = isset($_POST['search']['value']) ? $_POST['search']['value'] : '';
$orderColumnIndex = isset($_POST['order'][0]['column']) ? (int)$_POST['order'][0]['column'] : -1;
$orderDir = isset($_POST['order'][0]['dir']) ? $_POST['order'][0]['dir'] : 'asc';

// A SKU counts as active only when it is BOTH flagged active AND inside its
// validity window (validity_from < today < validity_to). The explicit IS NOT
// NULL guards keep this a hard 0/1 — without them a NULL date makes the whole
// expression NULL, and "NOT NULL" is NULL too, so rows with no validity dates
// would fall out of BOTH the Yes and the No filter. Single definition, used for
// the badge, the Is Active filter and anything exported.
$sku_active_sql = "(UPPER(IFNULL(is_active, '')) = 'YES'
     AND validity_from IS NOT NULL AND validity_to IS NOT NULL
     AND validity_from < CURDATE() AND validity_to > CURDATE())";

// Flagged active but past its end date. Called out separately because "the
// validity says 2999 but nobody buys it" is the exact clean-up case this page
// exists for; every other reason a row isn't active is just Inactive.
$sku_expired_sql = "(UPPER(IFNULL(is_active, '')) = 'YES'
     AND validity_to IS NOT NULL AND validity_to <= CURDATE())";

// The Is Active filter is multi-select over three states, and they partition the
// table exactly: Inactive is defined as the remainder, so a row that is neither
// active nor expired — flagged No, not started yet, or missing its validity
// dates — is still reachable instead of falling out of every filter. Any number
// can be picked; they are OR-ed together. Nothing picked = no restriction.
//
// The IFNULL() wrappers keep each expression a hard 0/1. Without them a NULL
// is_active makes the comparison NULL, and NOT NULL is NULL too, so the row
// would answer "no" to Active AND "no" to Inactive and disappear entirely.
$sku_state_sql = [
    'Yes'     => $sku_active_sql,
    'Expired' => $sku_expired_sql,
    'No'      => "(NOT {$sku_active_sql} AND NOT {$sku_expired_sql})",
];

// Custom Filters
$filter_group_owner = isset($_POST['filter_group_owner']) ? $_POST['filter_group_owner'] : '';
$filter_area = isset($_POST['filter_area']) ? $_POST['filter_area'] : '';
$filter_validity_from = isset($_POST['filter_validity_from']) ? trim((string)$_POST['filter_validity_from']) : '';
$filter_validity_to = isset($_POST['filter_validity_to']) ? trim((string)$_POST['filter_validity_to']) : '';
$filter_level_0 = isset($_POST['filter_level_0']) ? $_POST['filter_level_0'] : '';
$filter_level_1 = isset($_POST['filter_level_1']) ? $_POST['filter_level_1'] : '';
$filter_level_2 = isset($_POST['filter_level_2']) ? $_POST['filter_level_2'] : '';
$filter_level_3 = isset($_POST['filter_level_3']) ? $_POST['filter_level_3'] : '';
$filter_level_4 = isset($_POST['filter_level_4']) ? $_POST['filter_level_4'] : '';
// Mapping Status is a Category Manager-only column and filter; no other role can
// see it in the table, so no other role gets to filter on it either.
$filter_mapping_status = ($role === 'Category Manager' && isset($_POST['filter_mapping_status']))
    ? $_POST['filter_mapping_status'] : '';
// Multi-select, but a single legacy string ("Yes"/"No") is still accepted.
$filter_is_active = isset($_POST['filter_is_active']) ? $_POST['filter_is_active'] : [];
if (!is_array($filter_is_active)) {
    $filter_is_active = ($filter_is_active === '') ? [] : [$filter_is_active];
}

// Define columns for ordering
// Index matches DataTables columns definition. Only Category Manager renders the leading
// checkbox column and the trailing Actions column, so every other role is shifted.
$columns = [];
if ($role === 'Category Manager') {
    $columns[] = 'id'; // Checkbox
}
$columns[] = 'id';             // Expand
$columns[] = 'detail';         // Visible Detail & SKU (fallback)
$columns[] = 'group_owner';    // Visible Group Owner
$columns[] = 'est_price';      // Visible Est Price
$columns[] = 'id';             // Visible AON Rem. Qty (computed from aon_skus, not orderable)
$columns[] = 'validity_from';  // Visible Validity (fallback)
$columns[] = 'level_3';        // Visible Taxonomy (fallback)
$columns[] = 'is_active';      // Visible Is Active
if ($role === 'Category Manager') {
    $columns[] = 'mapping_status'; // Visible Mapping Status (Category Manager only)
    $columns[] = 'id';             // Actions
}
$columns[] = 'sku';            // Hidden SKU
$columns[] = 'detail';         // Hidden Detail
$columns[] = 'validity_from';  // Hidden Validity From
$columns[] = 'validity_to';    // Hidden Validity To
$columns[] = 'level_3';        // Hidden L3
$columns[] = 'level_4';        // Hidden L4

$orderBy = isset($columns[$orderColumnIndex]) ? $columns[$orderColumnIndex] : 'detail';
$orderDir = (strtolower($orderDir) === 'desc') ? 'DESC' : 'ASC';

// Base Query
$query = "FROM sku_dictionary WHERE 1=1";
$params = [];
$select = "SELECT *, {$sku_active_sql} AS is_effectively_active";
$selectParams = [];

// Apply DataTables search
if (!empty($searchValue)) {
    $expanded = expand_search_synonyms($searchValue);
    if (empty($expanded)) $expanded = [$searchValue];
    
    $detail_likes = [];
    foreach ($expanded as $term) {
        $detail_likes[] = "detail LIKE ?";
    }
    $detail_sql = "(" . implode(" OR ", $detail_likes) . ")";
    
    $query .= " AND (sku LIKE ? OR group_owner LIKE ? OR $detail_sql OR hashtag LIKE ?)";
    
    $likeSearch = "%{$searchValue}%";
    
    $params[] = $likeSearch; // sku
    $params[] = $likeSearch; // group_owner
    foreach ($expanded as $term) {
        $params[] = "%{$term}%"; // detail
    }
    $params[] = $likeSearch; // hashtag
    
    // Add relevance sorting based on the matched terms
    $match_str = implode(" ", $expanded);
    $select = "SELECT *, {$sku_active_sql} AS is_effectively_active, MATCH(detail) AGAINST(? IN BOOLEAN MODE) AS relevance_score";
    $selectParams[] = $match_str;
    
    // Override sorting to relevance_score if doing a global search
    $orderBy = "relevance_score";
    $orderDir = "DESC";
}

// Apply Custom Filters
// Group Owner / Area now come from searchable dropdowns built off the table's own
// DISTINCT values, so they match exactly instead of by LIKE — picking "GA" no
// longer also drags in every other value that happens to contain "GA".
if (!empty($filter_group_owner)) {
    $query .= " AND group_owner = ?";
    $params[] = $filter_group_owner;
}
if (!empty($filter_area)) {
    $query .= " AND area = ?";
    $params[] = $filter_area;
}
// Two independent date-picker bounds: "from this start date onward" and
// "expiring by this end date". Either can be used on its own.
if ($filter_validity_from !== '') {
    $query .= " AND validity_from IS NOT NULL AND validity_from >= ?";
    $params[] = $filter_validity_from;
}
if ($filter_validity_to !== '') {
    $query .= " AND validity_to IS NOT NULL AND validity_to <= ?";
    $params[] = $filter_validity_to;
}
if (!empty($filter_level_0)) {
    $query .= " AND level_0 LIKE ?";
    $params[] = "%{$filter_level_0}%";
}
if (!empty($filter_level_1)) {
    $query .= " AND level_1 LIKE ?";
    $params[] = "%{$filter_level_1}%";
}
if (!empty($filter_level_2)) {
    $query .= " AND level_2 LIKE ?";
    $params[] = "%{$filter_level_2}%";
}
if (!empty($filter_level_3)) {
    $query .= " AND level_3 LIKE ?";
    $params[] = "%{$filter_level_3}%";
}
if (!empty($filter_level_4)) {
    $query .= " AND level_4 LIKE ?";
    $params[] = "%{$filter_level_4}%";
}
if (!empty($filter_mapping_status)) {
    if ($filter_mapping_status === 'Empty') {
        $query .= " AND (mapping_status IS NULL OR mapping_status = '')";
    } else {
        $query .= " AND mapping_status = ?";
        $params[] = $filter_mapping_status;
    }
}
// One OR-ed clause per selected state (see $sku_state_sql). A value that isn't a
// known state is dropped; if that leaves nothing, the filter simply doesn't
// apply, exactly as if the box had been cleared.
$state_clauses = [];
foreach ($filter_is_active as $state) {
    $state = trim((string)$state);
    if (isset($sku_state_sql[$state])) {
        $state_clauses[] = $sku_state_sql[$state];
    }
}
if (!empty($state_clauses)) {
    $query .= " AND (" . implode(' OR ', $state_clauses) . ")";
}

try {
    // Total records without filtering
    $stmtTotal = $pdo->query("SELECT COUNT(id) FROM sku_dictionary");
    $recordsTotal = $stmtTotal->fetchColumn();

    // Total records with filtering
    $stmtFiltered = $pdo->prepare("SELECT COUNT(id) " . $query);
    $stmtFiltered->execute($params);
    $recordsFiltered = $stmtFiltered->fetchColumn();

    // Fetch the data. length = -1 is DataTables' "all rows" and is what the export
    // sends: the table on screen always pages, but a file that only holds the 50
    // rows that happened to be visible is not an export of the filtered set.
    $limitSql = ($length < 0) ? "" : " LIMIT " . (int)$start . ", " . (int)$length;
    $sqlData = $select . " " . $query . " ORDER BY " . $orderBy . " " . $orderDir . $limitSql;
    $stmtData = $pdo->prepare($sqlData);
    $finalParams = array_merge($selectParams, $params);
    $stmtData->execute($finalParams);
    $data = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    // --- AON remaining quantity -------------------------------------------
    // Attach the remaining contract quantity each SKU still has on the Master
    // AON list, so a requestor can see it before raising a ticket and avoid
    // ordering past what the contract still covers. Mirrors pages/master_aon.php
    // exactly: only non-soft-deleted rows with a real allocation count, and the
    // per-row remaining figure is aon_effective_remain_qty() (remain_qty, else
    // contract qty minus used qty, else the full contract qty). A SKU with more
    // than one AON contract row (dup_sku) is summed. SKUs not on the AON list
    // get null and render as a dash. Done in PHP with the shared helper rather
    // than in SQL because quantity/used_qty/remain_qty are free-text VARCHARs.
    $sku_keys = [];
    foreach ($data as $row) {
        $k = strtoupper(trim((string)($row['sku'] ?? '')));
        if ($k !== '') $sku_keys[$k] = true;
    }
    $aon_rem_map = [];
    if (!empty($sku_keys)) {
        $keys = array_keys($sku_keys);
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $aStmt = $pdo->prepare(
            "SELECT sku_code, quantity, used_qty, remain_qty
               FROM aon_skus
              WHERE is_deleted = 0
                AND alokasi IS NOT NULL AND alokasi != ''
                AND UPPER(TRIM(sku_code)) IN ($ph)"
        );
        $aStmt->execute($keys);
        foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $ar) {
            $k = strtoupper(trim((string)$ar['sku_code']));
            $rem = aon_effective_remain_qty($ar['quantity'] ?? null, $ar['used_qty'] ?? null, $ar['remain_qty'] ?? null);
            if ($rem === null) continue;
            $qraw = str_replace([',', ' '], '', trim((string)($ar['quantity'] ?? '')));
            $qty  = ($qraw !== '' && is_numeric($qraw)) ? (float)$qraw : 0.0;
            if (!isset($aon_rem_map[$k])) $aon_rem_map[$k] = ['rem' => 0.0, 'qty' => 0.0, 'rows' => 0];
            $aon_rem_map[$k]['rem']  += $rem;
            $aon_rem_map[$k]['qty']  += $qty;
            $aon_rem_map[$k]['rows'] += 1;
        }
    }
    foreach ($data as &$row) {
        $k = strtoupper(trim((string)($row['sku'] ?? '')));
        if (isset($aon_rem_map[$k])) {
            $m = $aon_rem_map[$k];
            $row['aon_remaining_qty'] = $m['rem'];
            $row['aon_contract_qty']  = $m['qty'] > 0 ? $m['qty'] : null;
            $row['aon_row_count']     = $m['rows'];
            $row['aon_is_low']        = aon_remain_is_low($m['qty'], $m['rem']) ? 1 : 0;
        } else {
            $row['aon_remaining_qty'] = null;
            $row['aon_contract_qty']  = null;
            $row['aon_row_count']     = 0;
            $row['aon_is_low']        = 0;
        }
    }
    unset($row);

    echo json_encode([
        "draw" => $draw,
        "recordsTotal" => (int)$recordsTotal,
        "recordsFiltered" => (int)$recordsFiltered,
        "data" => $data
    ]);
} catch (PDOException $e) {
    echo json_encode([
        "error" => "Database error: " . $e->getMessage()
    ]);
}
