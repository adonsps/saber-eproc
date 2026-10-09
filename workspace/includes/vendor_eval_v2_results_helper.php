<?php
// includes/vendor_eval_v2_results_helper.php
//
// Shared SQL builders for the two Vendor Evaluation v2 server-side DataTables
// (v1 PO Worklist, v2 Grade Results) on pages/vendor_eval_v2_results.php and
// their ajax_vendor_eval_v1_table.php / ajax_vendor_eval_v2_table.php endpoints.
// Keeping the WHERE/CASE logic here means the page (for the status legend
// counts / grade chart) and the endpoints (for paging) can never drift apart.

/** Today's date in the timezone the page uses everywhere else (Asia/Jakarta),
 *  not MySQL's CURDATE() — the DB server's timezone is not guaranteed to match. */
function ve1_today_jakarta(): string {
    return (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
}

// ─────────────────────────────────────────────────────────────────────────
// v1 Legacy PO Worklist
// ─────────────────────────────────────────────────────────────────────────

/**
 * One row per PO. $has_bu (see ve2_has_table()) adds a business_units column
 * — GROUP_CONCAT'd the same way ve2_grade_status_sql() does for the Results
 * tab, via the same pgroup_business_unit_mappings join on prs.p_group — so
 * the PO Worklist tab's Business Unit filter can never disagree with the
 * Results tab about what a PO's business unit is. Degrades to business_units
 * always NULL if the mapping table isn't applied on this environment yet.
 */
function ve1_worklist_lines_sql(bool $has_bu = false): string {
    $bu_join   = $has_bu ? "LEFT JOIN pgroup_business_unit_mappings bu ON bu.p_group = p.p_group" : '';
    $bu_select = $has_bu
        ? "GROUP_CONCAT(DISTINCT bu.business_unit_name ORDER BY bu.business_unit_name SEPARATOR ', ') AS business_units"
        : "NULL AS business_units";

    return "
        SELECT p.po_number,
               GROUP_CONCAT(DISTINCT p.requestor_name SEPARATOR ', ') AS all_requestors,
               p.vendor_name,
               MAX(p.gr_date) AS gr_date,
               MAX(p.delivery_date) AS delivery_date,
               MAX(p.otf_score) AS otf_score,
               MAX(p.otf_status) AS otf_status,
               COUNT(DISTINCT p.requestor_name) AS total_requestors,
               (SELECT COUNT(DISTINCT requestor_name) FROM vendor_evaluations WHERE po_number = p.po_number) AS eval_count,
               (SELECT AVG(total_score) FROM vendor_evaluations WHERE po_number = p.po_number) AS avg_score,
               MAX(mv.top) AS vendor_top,
               MAX(p.top_score) AS top_score,
               MAX(mv.id) AS mv_id,
               {$bu_select}
        FROM prs p
        LEFT JOIN master_vendor mv ON p.vendor_name = mv.description
        LEFT JOIN quant_top_scores q ON mv.top = q.top_value
        {$bu_join}
        WHERE p.po_number IS NOT NULL AND p.po_number != ''
        GROUP BY p.po_number, p.vendor_name
    ";
}

/**
 * Wraps ve1_worklist_lines_sql() and adds two computed columns:
 *   - v1_status   — same status rules the PHP loop used to apply per row.
 *   - final_score — same weighted-score arithmetic the PHP <td> used to compute.
 * $quant_config comes from quant_eval_config (DB-controlled ints, safe to inline).
 * $today is ve1_today_jakarta() — passed in rather than computed here so the
 * status-legend count query and the paginated endpoint always agree on "today".
 * $has_bu — see ve1_worklist_lines_sql(); the status-legend count query
 * doesn't need it (business_units isn't part of that CASE) and passes false.
 */
function ve1_worklist_status_sql(array $quant_config, string $today, bool $has_bu = false): string {
    $lines  = ve1_worklist_lines_sql($has_bu);
    $qual_w = (int)($quant_config['qualitative_weight_percent'] ?? 50);
    $otf_w  = (int)($quant_config['otf_weight_percent'] ?? 25);
    $top_w  = (int)($quant_config['top_weight_percent'] ?? 25);
    // $today is always a Y-m-d string from ve1_today_jakarta(); safe to inline.
    $today  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $today) ? $today : date('Y-m-d');

    return "
        SELECT z.*,
            CASE
                WHEN z.eval_count = 0 AND z.gr_date IS NOT NULL AND z.gr_date <= '{$today}' THEN 'Need Evaluation'
                WHEN z.eval_count > 0 AND z.eval_count < z.total_requestors                  THEN 'Partially Evaluated'
                WHEN z.eval_count = z.total_requestors                                       THEN 'Evaluated'
                ELSE 'Waiting for GR'
            END AS v1_status,
            CASE
                WHEN z.avg_score IS NOT NULL AND z.otf_score IS NOT NULL AND z.top_score > 0
                    THEN (z.avg_score / 4.0 * 100) * ({$qual_w} / 100)
                       + (z.otf_score  / 3.0 * 100) * ({$otf_w}  / 100)
                       + (z.top_score  / 3.0 * 100) * ({$top_w}  / 100)
                ELSE NULL
            END AS final_score
        FROM ({$lines}) z
    ";
}

// Column index -> the display text that column's filter box matches against.
// Mirrors each column's render() branch in vendor_eval_v2_results.php's <script>.
// Shared by ajax_vendor_eval_v1_table.php (paginated) and
// export_vendor_eval_v1_worklist.php (full export) so both can never
// disagree about what a column filter matches.
function v1_display_field(array $row, int $col): string {
    switch ($col) {
        case 1: return (string)($row['po_number'] ?? '');
        case 2: return $row['avg_score']   !== null ? number_format((float)$row['avg_score'], 2)   : '--';
        case 3: return $row['otf_score']   !== null ? (string)(int)$row['otf_score']               : '--';
        case 4: return $row['top_score']   !== null ? (string)(int)$row['top_score']               : '--';
        case 5: return $row['final_score'] !== null ? number_format((float)$row['final_score'], 2) : 'Inc. Data';
        case 6: return (string)($row['all_requestors'] ?? '');
        case 7: return (string)($row['vendor_name'] ?? '');
        case 8: return $row['delivery_date'] ? date('d M Y', strtotime($row['delivery_date'])) : '-';
        case 9: return $row['gr_date']       ? date('d M Y', strtotime($row['gr_date']))       : '-';
        case 10: return (string)($row['business_units'] ?? '');
        default: return '';
    }
}

// Status -> display priority for the default (column 0) sort: red/"Need
// Evaluation" rows surface first, everything else keeps following.
function v1_status_rank(string $status): int {
    switch ($status) {
        case 'Need Evaluation':     return 0;
        case 'Partially Evaluated': return 1;
        case 'Waiting for GR':      return 2;
        case 'Evaluated':           return 3;
        default:                    return 4;
    }
}

// Column index -> the raw value that column sorts by (numeric columns stay
// numeric so usort compares them as numbers, not strings).
function v1_sort_value(array $row, int $col) {
    switch ($col) {
        // Status rank first, then most recent GR date on top within the same
        // status — e.g. the reddest/most urgent "Need Evaluation" POs lead,
        // and among those the one that most recently became eligible is on top.
        case 0:
            $rank  = v1_status_rank((string)($row['v1_status'] ?? ''));
            $gr_ts = !empty($row['gr_date']) ? (int)strtotime((string)$row['gr_date']) : 0;
            return (float)($rank * 10000000000 - $gr_ts);
        case 1: return (string)($row['po_number'] ?? '');
        case 2: return $row['avg_score']   !== null ? (float)$row['avg_score']   : -INF;
        case 3: return $row['otf_score']   !== null ? (float)$row['otf_score']   : -INF;
        case 4: return $row['top_score']   !== null ? (float)$row['top_score']   : -INF;
        case 5: return $row['final_score'] !== null ? (float)$row['final_score'] : -INF;
        case 6: return (string)($row['all_requestors'] ?? '');
        case 7: return (string)($row['vendor_name'] ?? '');
        case 8: return (string)($row['delivery_date'] ?? '');
        case 9: return (string)($row['gr_date'] ?? '');
        case 10: return (string)($row['business_units'] ?? '');
        default: return '';
    }
}

function v1_compare(array $a, array $b, int $col): int {
    $av = v1_sort_value($a, $col);
    $bv = v1_sort_value($b, $col);
    if (is_float($av)) return $av <=> $bv;
    return strcmp((string)$av, (string)$bv);
}

function v1_compare_multi(array $a, array $b, array $orderList): int {
    foreach ($orderList as $o) {
        $cmp = v1_compare($a, $b, $o['column']);
        if ($cmp !== 0) return $o['dir'] === 'asc' ? $cmp : -$cmp;
    }
    return 0;
}

// Multi-select boxes (currently just the Business Unit dropdown, col 10) join
// their selected values with this delimiter before they reach v1ColFilters —
// an ASCII Unit Separator, not a character anyone can type into a text box or
// that appears in a business unit name, so plain single-value text filters on
// every other column are completely unaffected by this.
const V1_MULTI_FILTER_SEP = "\x1F";

/**
 * Applies the status legend filter + per-column "Apply Filter" boxes to the
 * full ve1_worklist_status_sql() result set — the shared filtering step
 * behind both the paginated table endpoint and the full-export endpoint.
 * A colFilters value may carry several V1_MULTI_FILTER_SEP-joined choices
 * (the Business Unit dropdown allows multi-select); a row matches that
 * column if ANY one of them is a substring match — OR, not AND, since a PO
 * only ever has one (or a GROUP_CONCAT of a few) business unit(s), never all
 * of the selected ones at once.
 * @return array{0: array, 1: int} [filtered_rows, records_total_before_col_filters]
 */
function v1_worklist_filter_rows(array $rows, string $status, array $colFilters): array {
    if ($status !== '') {
        $rows = array_values(array_filter($rows, fn($r) => ($r['v1_status'] ?? '') === $status));
    }
    $records_total = count($rows);

    foreach ($colFilters as $col => $val) {
        $val = trim((string)$val);
        if ($val === '') continue;
        $col = (int)$col;
        $choices = array_values(array_filter(array_map('trim', explode(V1_MULTI_FILTER_SEP, $val)), fn($c) => $c !== ''));
        if (empty($choices)) continue;
        $rows = array_values(array_filter($rows, function ($r) use ($col, $choices) {
            $field = v1_display_field($r, $col);
            foreach ($choices as $choice) {
                if (stripos($field, $choice) !== false) return true;
            }
            return false;
        }));
    }

    return [$rows, $records_total];
}

/**
 * Distinct business unit names for the PO Worklist tab's "Business Unit"
 * filter dropdown (data-v1col="10"). Empty array if
 * pgroup_business_unit_mappings isn't applied on this environment yet (see
 * ve2_has_table()) — the filter box then just has no options and matches
 * nothing, rather than erroring.
 */
function ve1_business_unit_options(PDO $pdo): array {
    if (!ve2_has_table($pdo, 'pgroup_business_unit_mappings')) return [];
    return $pdo->query("
        SELECT DISTINCT business_unit_name
          FROM pgroup_business_unit_mappings
         WHERE business_unit_name IS NOT NULL AND business_unit_name != ''
         ORDER BY business_unit_name
    ")->fetchAll(PDO::FETCH_COLUMN);
}

// ─────────────────────────────────────────────────────────────────────────
// v2 Evaluation Grade Results
// ─────────────────────────────────────────────────────────────────────────

/**
 * Parses a filter array (vendor/l2/l3/grade/date_from/date_to — the same shape
 * whether it came from $_GET on first page load or the `gf` JSON blob the
 * table/detail AJAX endpoints receive once filtering moved client-side) and
 * returns the pre-aggregation WHERE + params for vendor_eval_submissions, plus
 * the grade filter (applied post-aggregation by the caller, since grade is
 * computed from AVG(total_score)).
 * @return array{0:string,1:array,2:string} [where_sql, params, grade]
 */
function ve2_grade_where_from_filters(array $f): array {
    $f_vendor    = trim((string)($f['vendor']    ?? ''));
    $f_l2        = trim((string)($f['l2']        ?? ''));
    $f_l3        = trim((string)($f['l3']        ?? ''));
    $f_grade     = in_array($f['grade'] ?? '', ['A', 'B', 'C', 'D'], true) ? $f['grade'] : '';
    $f_date_from = trim((string)($f['date_from'] ?? ''));
    $f_date_to   = trim((string)($f['date_to']   ?? ''));

    $where  = ["status = 'submitted'"];
    $params = [];

    if ($f_vendor !== '') {
        $where[]           = 'ves.vendor_name LIKE :vendor';
        $params['vendor']  = '%' . $f_vendor . '%';
    }
    if ($f_l2 !== '') {
        $where[]       = 'taxonomy_level_2 = :l2';
        $params['l2']  = $f_l2;
    }
    if ($f_l3 !== '') {
        $where[]       = 'taxonomy_level_3 = :l3';
        $params['l3']  = $f_l3;
    }
    if ($f_date_from !== '') {
        $where[]              = 'DATE(submitted_at) >= :date_from';
        $params['date_from']  = $f_date_from;
    }
    if ($f_date_to !== '') {
        $where[]            = 'DATE(submitted_at) <= :date_to';
        $params['date_to']  = $f_date_to;
    }

    return [implode(' AND ', $where), $params, $f_grade];
}

/**
 * True if $table exists in the current database. pgroup_business_unit_mappings
 * (db_backups/pgroup_business_unit_mappings_01_schema.sql) is, by design, NOT
 * wired into config/database.php's auto-migration — it's a deliberate,
 * manually-applied migration (same as project_pgroups). An environment that
 * hasn't run it yet must not take down the whole Grade Results table over a
 * column (business_units) nobody there can see anyway.
 */
function ve2_has_table(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

/**
 * Grouped aggregation + grade CASE, wrapping the GET-filtered base query.
 * Joins in gr_date (from prs, pre-aggregated to one row per po_number so the
 * join can't fan out and skew AVG(ves.total_score)) and business_units (via
 * pgroup_business_unit_mappings) — added alongside the Store Opening Date
 * feature so the Results table can show them without a second round trip.
 * Degrades to gr_date/business_units always NULL if pgroup_business_unit_mappings
 * doesn't exist yet on this environment (see ve2_has_table()) — prs is a core
 * table always assumed present, same as everywhere else in this codebase.
 */
function ve2_grade_status_sql(PDO $pdo, string $where_sql): string {
    $has_bu = ve2_has_table($pdo, 'pgroup_business_unit_mappings');

    $bu_join   = $has_bu ? "LEFT JOIN pgroup_business_unit_mappings bu ON bu.p_group = p.p_group" : '';
    $bu_select = $has_bu
        ? "GROUP_CONCAT(DISTINCT bu.business_unit_name ORDER BY bu.business_unit_name SEPARATOR ', ') AS business_units"
        : "NULL AS business_units";

    return "
        SELECT g.*,
            CASE WHEN g.avg_score >= 3.5 THEN 'A'
                 WHEN g.avg_score >= 2.5 THEN 'B'
                 WHEN g.avg_score >= 1.5 THEN 'C'
                 ELSE 'D' END AS grade
        FROM (
            SELECT
                ves.vendor_name,
                ves.taxonomy_level_2,
                ves.taxonomy_level_3,
                COUNT(DISTINCT ves.po_number)      AS po_count,
                AVG(ves.total_score)               AS avg_score,
                MAX(ves.submitted_at)              AS last_submitted_at,
                COUNT(DISTINCT ves.requestor_name) AS requestor_count,
                MAX(p.gr_date)                     AS gr_date,
                {$bu_select}
              FROM vendor_eval_submissions ves
              LEFT JOIN (
                  SELECT po_number, MAX(p_group) AS p_group, MAX(gr_date) AS gr_date
                  FROM prs
                  GROUP BY po_number
              ) p ON p.po_number = ves.po_number
              {$bu_join}
             WHERE {$where_sql}
             GROUP BY ves.vendor_name, ves.taxonomy_level_2, ves.taxonomy_level_3
        ) g
    ";
}

// Column index -> the display text that column's "Apply Filter" box matches
// against. Mirrors each column's render() branch in vendor_eval_v2_results.php's
// <script>. Shared by ajax_vendor_eval_v2_table.php (paginated) and
// export_vendor_eval_v2_results.php (full export).
function ve2_grade_display_field(array $r, int $col): string {
    switch ($col) {
        case 0: return (string)($r['vendor_name'] ?? '');
        case 1: return trim(($r['taxonomy_level_2'] ?? '') . ' ' . ($r['taxonomy_level_3'] ?? ''));
        case 2: return (string)(int)($r['po_count'] ?? 0);
        case 3: return $r['avg_score'] !== null ? number_format((float)$r['avg_score'], 1) : '';
        case 4: return (string)($r['grade'] ?? '');
        case 5: return (string)(int)($r['requestor_count'] ?? 0);
        case 6: return (string)($r['business_units'] ?? '');
        case 7: return $r['gr_date'] ? date('d M Y', strtotime($r['gr_date'])) : 'Pending';
        default: return '';
    }
}

// Column index -> the raw value that column sorts by (numeric columns stay
// numeric so the comparator sorts them as numbers, not strings).
function ve2_grade_sort_value(array $r, int $col) {
    switch ($col) {
        case 0: return (string)($r['vendor_name'] ?? '');
        case 1: return (string)($r['taxonomy_level_2'] ?? '');
        case 2: return (float)($r['po_count'] ?? 0);
        case 3: return $r['avg_score'] !== null ? (float)$r['avg_score'] : -INF;
        case 4: return (string)($r['grade'] ?? '');
        case 5: return (float)($r['requestor_count'] ?? 0);
        case 6: return (string)($r['business_units'] ?? '');
        case 7: return (string)($r['gr_date'] ?? '');
        default: return '';
    }
}

function ve2_grade_compare(array $a, array $b, int $col): int {
    $av = ve2_grade_sort_value($a, $col);
    $bv = ve2_grade_sort_value($b, $col);
    if (is_float($av)) return $av <=> $bv;
    return strcmp((string)$av, (string)$bv);
}

// Tiebreaks on vendor/L2/L3 to match the old SQL ORDER BY's trailing
// "v.vendor_name ASC, v.taxonomy_level_2 ASC, v.taxonomy_level_3 ASC".
function ve2_grade_compare_multi(array $a, array $b, array $orderList): int {
    foreach ($orderList as $o) {
        $cmp = ve2_grade_compare($a, $b, $o['column']);
        if ($cmp !== 0) return $o['dir'] === 'asc' ? $cmp : -$cmp;
    }
    $cmp = strcmp((string)($a['vendor_name'] ?? ''), (string)($b['vendor_name'] ?? ''));
    if ($cmp !== 0) return $cmp;
    $cmp = strcmp((string)($a['taxonomy_level_2'] ?? ''), (string)($b['taxonomy_level_2'] ?? ''));
    if ($cmp !== 0) return $cmp;
    return strcmp((string)($a['taxonomy_level_3'] ?? ''), (string)($b['taxonomy_level_3'] ?? ''));
}

/**
 * Applies the grade filter, free-text search and per-column "Apply Filter"
 * boxes to the full ve2_grade_status_sql() result set — the shared filtering
 * step behind both the paginated table endpoint and the full-export endpoint.
 * @return array{0: array, 1: int} [filtered_rows, records_total_before_search_and_col_filters]
 */
function ve2_grade_filter_rows(array $rows, string $f_grade, string $search, array $colFilters): array {
    if ($f_grade !== '') {
        $rows = array_values(array_filter($rows, fn($r) => ($r['grade'] ?? '') === $f_grade));
    }
    $records_total = count($rows);

    if ($search !== '') {
        $needle = mb_strtolower($search);
        $rows = array_values(array_filter($rows, function ($r) use ($needle) {
            return str_contains(mb_strtolower((string)($r['vendor_name'] ?? '')), $needle)
                || str_contains(mb_strtolower((string)($r['taxonomy_level_2'] ?? '')), $needle)
                || str_contains(mb_strtolower((string)($r['taxonomy_level_3'] ?? '')), $needle);
        }));
    }

    foreach ($colFilters as $col => $val) {
        $val = trim((string)$val);
        if ($val === '') continue;
        $col = (int)$col;
        $rows = array_values(array_filter($rows, fn($r) => stripos(ve2_grade_display_field($r, $col), $val) !== false));
    }

    return [$rows, $records_total];
}

// ─────────────────────────────────────────────────────────────────────────
// Small formatting helpers shared with the detail-modal endpoint
// (ajax_vendor_eval_v2_detail.php). Named distinctly from the page's own
// scoreGrade()/fmtDate() (which stay as-is for the unfiltered chart aggregate)
// since both this file and the page are loaded in the same request.
// ─────────────────────────────────────────────────────────────────────────

function ve2_score_grade(float $avg): string {
    if ($avg >= 3.5) return 'A';
    if ($avg >= 2.5) return 'B';
    if ($avg >= 1.5) return 'C';
    return 'D';
}

function ve2_format_date(?string $dt): string {
    if (!$dt) return '—';
    return (new DateTime($dt, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Asia/Jakarta'))
        ->format('d M Y H:i');
}
