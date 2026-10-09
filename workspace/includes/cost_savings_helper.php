<?php
// includes/cost_savings_helper.php
//
// Shared SQL builders for the Cost Savings Matrix page and its AJAX endpoints.
//

require_once __DIR__ . '/cost_avoidance_helper.php';
require_once __DIR__ . '/aon_helper.php';

/**
 * Names of the per-connection temporary tables. See cs_prepare_benchmark().
 *
 * There are TWO because MySQL cannot refer to the same TEMPORARY table more
 * than once in a single query ("Can't reopen table", error 1137), and each
 * line needs both "the benchmark for my SKU + UOM" (a join) and "does this SKU
 * appear in 2025 under ANY uom?" (the unmatched reason). Splitting the SKU set
 * into its own table keeps each one referenced exactly once.
 */
const CS_BENCH_TABLE     = 'cs_bench_2025';
const CS_BENCH_SKU_TABLE = 'cs_bench_sku_2025';

/**
 * SKU+UOM matches where the 2025 unit price differs from the current price by
 * more than this multiple are treated as price-mismatches (a different pricing
 * structure, e.g. annual/blanket contract pricing) and kept out of the totals
 * until a Category Manager reviews them. Same values the page used inline.
 */
const CS_RATIO_HIGH = 10;
const CS_RATIO_LOW  = 0.1;

/**
 * purchases_2025.delivery_date is a VARCHAR holding a US-format timestamp
 * ("5/30/2025 12:00:00 AM"), not a DATE. The old PHP compared these with
 * strtotime(); sorting them as strings would order 10/1 before 2/1, so the
 * "latest 2025 PO" pick has to parse them the same way. Verified against the
 * full table: every non-blank value parses with this format, none are ISO.
 */
const CS_DELIVERY_DATE_FMT = '%c/%e/%Y %h:%i:%s %p';

function cs_context(PDO $pdo, bool $aon_only = false): array {
    $mode     = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sla_mapping_mode'")->fetchColumn() ?: 'pgroup';
    $usd_rate = (float)($pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'usd_exchange_rate'")->fetchColumn() ?: 16000);

    $tax_prefixes = [];
    $tax_safe     = true;
    foreach ($pdo->query("SELECT p_org_prefix FROM tax_rules")->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $prefix = strtoupper(trim((string)$raw));
        if ($prefix === '') continue;
        if (!preg_match('/^[A-Z0-9_-]+$/', $prefix)) {
            $tax_safe = false;
            break;
        }
        $tax_prefixes[] = $prefix;
    }

    return [
        'mode'                => $mode,
        'usd_rate'            => $usd_rate,
        'aon_only'            => $aon_only,
        'tax_prefixes'        => $tax_prefixes,
        'tax_literal'         => $tax_safe,
        'has_2025'            => ca_has_table($pdo, 'purchases_2025'),
        'has_corrections'     => ca_has_table($pdo, 'po_line_corrections'),
        'has_project_pgroups' => ca_has_table($pdo, 'project_pgroups'),
        // Filled in by cs_prepare_benchmark().
        'bench_mode'          => 'inline',
        'bench_ref'           => null,
        'bench_sku_ref'       => null,
    ];
}

function cs_inclusive_expr(array $ctx, string $alias = 'p'): string {
    // Only internal aliases are ever passed; guard anyway so this can never
    // become an injection surface if a caller gets careless.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) {
        $alias = 'p';
    }

    if (!$ctx['tax_literal']) {
        return "EXISTS (SELECT 1 FROM tax_rules t
                        WHERE LEFT(UPPER({$alias}.p_org), CHAR_LENGTH(t.p_org_prefix)) = t.p_org_prefix)";
    }

    if (empty($ctx['tax_prefixes'])) {
        return "(1 = 0)"; // no rules configured: nothing is PPN-inclusive
    }

    $parts = [];
    foreach ($ctx['tax_prefixes'] as $prefix) {
        // LEFT(UPPER(...), len) = 'PREFIX' is the exact SQL equivalent of the
        // old strpos(strtoupper($p_org), $prefix) === 0.
        $parts[] = "LEFT(UPPER({$alias}.p_org), " . strlen($prefix) . ") = '{$prefix}'";
    }
    return '(' . implode(' OR ', $parts) . ')';
}

function cs_prepare_benchmark(PDO $pdo, array &$ctx): void {
    if (!$ctx['has_2025']) {
        // No 2025 data at all: an empty inline source keeps every join valid and
        // every line simply lands in the "No 2025 Match" bucket.
        $ctx['bench_mode']    = 'empty';
        $ctx['bench_ref']     = "(SELECT '' AS sku_key, '' AS uom_key, 0e0 AS total_amount,
                                         0e0 AS total_qty, 0 AS po_count
                                  FROM DUAL WHERE 1 = 0)";
        $ctx['bench_sku_ref'] = "(SELECT '' AS sku_key FROM DUAL WHERE 1 = 0)";
        return;
    }

    $aggregate = cs_benchmark_aggregate_sql($ctx);

    try {
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS " . CS_BENCH_TABLE);
        $pdo->exec("DROP TEMPORARY TABLE IF EXISTS " . CS_BENCH_SKU_TABLE);
        // sku_key/uom_key are joined directly against prs.sku / prs.uom
        // (both utf8mb4_unicode_ci) in cs_joins(). Without an explicit
        // COLLATE here, MySQL falls back to the database's default collation,
        // which is utf8mb4_general_ci — a mismatch that throws "Illegal mix
        // of collations" (SQLSTATE HY000 1267) on that join.
        $pdo->exec("
            CREATE TEMPORARY TABLE " . CS_BENCH_TABLE . " (
                sku_key      VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                uom_key      VARCHAR(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                total_amount DOUBLE       NOT NULL DEFAULT 0,
                total_qty    DOUBLE       NOT NULL DEFAULT 0,
                po_count     INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (sku_key, uom_key)
            ) ENGINE=InnoDB
        ");
        $pdo->exec("INSERT INTO " . CS_BENCH_TABLE . " " . $aggregate);

        // The SKU-only set, for the "SKU exists in 2025 but the UOM differs"
        // message. Built FROM the table above rather than re-scanning
        // purchases_2025, so it can never disagree about which SKUs count.
        $pdo->exec("
            CREATE TEMPORARY TABLE " . CS_BENCH_SKU_TABLE . " (
                sku_key VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                PRIMARY KEY (sku_key)
            ) ENGINE=InnoDB
        ");
        $pdo->exec("INSERT INTO " . CS_BENCH_SKU_TABLE . "
                    SELECT DISTINCT sku_key FROM " . CS_BENCH_TABLE);

        $ctx['bench_mode']    = 'temp';
        $ctx['bench_ref']     = CS_BENCH_TABLE;
        $ctx['bench_sku_ref'] = CS_BENCH_SKU_TABLE;
        return;
    } catch (PDOException $e) {
        // Not fatal: fall through to the inline derived table.
        error_log('cs_prepare_benchmark: temporary table unavailable, using inline benchmark — ' . $e->getMessage());
    }

    $ctx['bench_mode']    = 'inline';
    $ctx['bench_ref']     = '(' . $aggregate . ')';
    $ctx['bench_sku_ref'] = "(SELECT TRIM(x2.sku) AS sku_key
                              FROM purchases_2025 x2
                              WHERE x2.sku IS NOT NULL AND TRIM(x2.sku) <> ''
                                AND x2.uom IS NOT NULL AND TRIM(x2.uom) <> ''
                                AND x2.po_amount > 0 AND x2.qty > 0
                              GROUP BY TRIM(x2.sku))";
}


function cs_benchmark_aggregate_sql(array $ctx): string {
    // NOTE there is no currency conversion here, deliberately: purchases_2025
    // has no po_currency column and the old loop read po_amount as-is.
    $inclusive = cs_inclusive_expr($ctx, 'x');

    return "
        SELECT
            TRIM(x.sku) AS sku_key,
            TRIM(x.uom) AS uom_key,
            SUM(CASE WHEN {$inclusive}
                     THEN (x.po_amount + 0e0) / 1.11
                     ELSE (x.po_amount + 0e0) END) AS total_amount,
            SUM(x.qty + 0e0)                       AS total_qty,
            COUNT(*)                               AS po_count
        FROM purchases_2025 x
        WHERE x.sku IS NOT NULL AND TRIM(x.sku) <> ''
          AND x.uom IS NOT NULL AND TRIM(x.uom) <> ''
          AND x.po_amount > 0 AND x.qty > 0
        GROUP BY TRIM(x.sku), TRIM(x.uom)";
}

/**
 * Vendor count and the "Sample PO" reference for a specific set of SKU+UOM
 * pairs — the 2025 Data column, fetched for one page of rows rather than for
 * all 6.5k benchmark keys (see the note in cs_benchmark_aggregate_sql()).
 *
 * @param array $pairs list of ['sku' => ..., 'uom' => ...] as shown on the page
 * @return array keyed "SKU|UOM" (upper-cased) => [vendor_count, sample_po, sample_date_technical]
 */
function cs_history_details(PDO $pdo, array $ctx, array $pairs): array {
    if (!$ctx['has_2025'] || empty($pairs)) return [];

    $skus = [];
    foreach ($pairs as $p) {
        $sku = trim((string)($p['sku'] ?? ''));
        if ($sku !== '') $skus[strtoupper($sku)] = $sku;
    }
    if (empty($skus)) return [];

    $order = "STR_TO_DATE(x.delivery_date, '" . CS_DELIVERY_DATE_FMT . "') DESC, x.po_number DESC";
    $sep   = "0x1F"; // ASCII unit separator — cannot occur in a PO number

    $place = [];
    $params = [];
    foreach (array_values($skus) as $i => $sku) {
        $place[] = ":sku{$i}";
        $params["sku{$i}"] = $sku;
    }

    // x.sku IN (...) rather than TRIM(x.sku) IN (...) so purchases_2025.idx_sku
    // still serves the lookup; the TRIM only normalises the grouping key.
    $sql = "SELECT TRIM(x.sku) AS sku_key,
                   TRIM(x.uom) AS uom_key,
                   COUNT(DISTINCT x.vendor_name) AS vendor_count,
                   SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(x.po_number, '')
                                   ORDER BY {$order} SEPARATOR {$sep}), {$sep}, 1) AS sample_po,
                   SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(x.tr_date_technical, '')
                                   ORDER BY {$order} SEPARATOR {$sep}), {$sep}, 1) AS sample_date_technical
            FROM purchases_2025 x
            WHERE x.sku IN (" . implode(', ', $place) . ")
              AND x.uom IS NOT NULL AND TRIM(x.uom) <> ''
              AND x.po_amount > 0 AND x.qty > 0
            GROUP BY TRIM(x.sku), TRIM(x.uom)";

    // Only the first element of each GROUP_CONCAT is read, so truncation at the
    // default 1024 bytes would be harmless — raising the cap just removes the
    // warning noise. Scoped to this connection.
    $pdo->exec("SET SESSION group_concat_max_len = 1000000");

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt as $row) {
        $key = strtoupper($row['sku_key'] . '|' . $row['uom_key']);
        $out[$key] = [
            'vendor_count'          => (int)$row['vendor_count'],
            'sample_po'             => $row['sample_po'],
            'sample_date_technical' => $row['sample_date_technical'],
        ];
    }
    return $out;
}

// --- CORRECTION PREDICATES --------------------------------------------------

function cs_has_correction(string $type): string {
    $legacy_both = in_array($type, ['TEAM_REASSIGNMENT', 'BENCHMARK_OVERRIDE'], true)
        ? " OR c.correction_type = 'BOTH'"
        : "";
    return "(c.correction_type IS NOT NULL
             AND (FIND_IN_SET('{$type}', REPLACE(c.correction_type, ' ', '')) > 0{$legacy_both}))";
}

/**
 * PHP's !empty() on a string: false for NULL, '' and the string '0'.
 * Reproduced literally so a stored value can never take a different branch here
 * than it did in the loop this replaces.
 */
function cs_not_empty(string $col): string {
    return "({$col} IS NOT NULL AND {$col} <> '' AND {$col} <> '0')";
}

// --- LEVEL 1: per-line raw values -------------------------------------------

/**
 * Team bucket for a PR line.
 *
 * Resolution order is the page's, and differs from Cost Avoidance's: an explicit
 * Category Manager reassignment always wins, then an ACTIVE Project PGroup, then
 * the Non-Project team name mapped through the acronym table. Cost Savings has
 * no pillar step, which is why ca_team_expr() cannot be reused here even though
 * the acronym list is identical.
 */
function cs_team_expr(array $ctx): string {
    // `trim($row['team_name'] ?: 'Unassigned')` — the ?: fires on NULL, '' and
    // '0', and the trim happens after it, not inside.
    //
    // PIC mode falls back to pgm (p_group -> pgroup_team_mappings, joined in
    // cs_joins()) before giving up: m.team_name alone leaves ~80% of eligible
    // lines "Unassigned" because most of the historical PR backfill has no
    // assigned_pic, while p_group is populated on every line. The PIC-based
    // team still wins when it IS available — it's the more specific, directly
    // human-assigned source; p_group is the structural fallback, not a
    // replacement.
    $raw_team = ($ctx['mode'] === 'pic')
        ? "TRIM(COALESCE(NULLIF(NULLIF(m.team_name, ''), '0'), NULLIF(NULLIF(pgm.team_name, ''), '0'), 'Unassigned'))"
        : "TRIM(COALESCE(NULLIF(NULLIF(m.team_name, ''), '0'), 'Unassigned'))";

    $acronym = "CASE {$raw_team}
                    WHEN 'Corporate Services, General Supplies & Marketing' THEN 'CSGSM'
                    WHEN 'Technology, Industrial, and General Services'     THEN 'TIGS'
                    WHEN 'FNB and Operational Merchandise'                  THEN 'FNBOM'
                    WHEN 'Admin Team'                                       THEN 'ADMIN'
                    WHEN 'Project Konstruksi Retail'                        THEN 'PKR'
                    WHEN 'Project Konstruksi Properti'                      THEN 'PKP'
                    WHEN 'Maintenance & Facility Management'                THEN 'MFM'
                    ELSE {$raw_team}
                END";

    if ($ctx['has_project_pgroups']) {
        $acronym = "CASE
                        WHEN pp.project_group_number = 1 THEN 'PKR'
                        WHEN pp.project_group_number = 2 THEN 'PKP'
                        WHEN pp.project_group_number = 3 THEN 'MFM'
                        WHEN pp.project_group_number IS NOT NULL THEN CONCAT('G', pp.project_group_number)
                        ELSE {$acronym}
                    END";
    }

    if (!$ctx['has_corrections']) {
        return $acronym;
    }

    return "CASE
                WHEN " . cs_has_correction('TEAM_REASSIGNMENT') . "
                     AND " . cs_not_empty('c.new_team') . " THEN c.new_team
                ELSE {$acronym}
            END";
}

function cs_joins(array $ctx, string $lookup_uom_expr): string {
    $sql = ($ctx['mode'] === 'pic')
        ? " LEFT JOIN users m ON p.assigned_pic = m.name"
        : " LEFT JOIN pgroup_team_mappings m ON p.p_group = m.p_group";

    // Fallback team source for cs_team_expr(), PIC mode only (pgroup mode's `m`
    // IS already this table, so there's nothing to fall back to). p.p_group is
    // populated on every eligible line, unlike p.assigned_pic — confirmed live
    // 2026-09-22 that ~80% of eligible lines have no assigned_pic (most of the
    // historical PR backfill was imported without PIC ownership), which is why
    // the team breakdown was mostly landing in "Unassigned". p_group ->
    // pgroup_team_mappings resolves ~94% of the same lines.
    if ($ctx['mode'] === 'pic') {
        $sql .= " LEFT JOIN pgroup_team_mappings pgm ON pgm.p_group = p.p_group";
    }

    if ($ctx['has_project_pgroups']) {

        $sql .= " LEFT JOIN (
                      SELECT p_group, MAX(project_group_number) AS project_group_number
                      FROM project_pgroups WHERE status = 'ACTIVE' GROUP BY p_group
                  ) pp ON pp.p_group = p.p_group";
    }

    // Taxonomy Level 2, for the Year/Quarter/Level 2 filter bar. Same join
    // pr_taxonomy_lookup.php and sla_helper.php already use elsewhere in the
    // app (p.master_data_id -> master_data.level_2), so the filter's options
    // and "Unmapped" fallback behave the same way here as they do there.
    $sql .= " LEFT JOIN master_data md ON p.master_data_id = md.id";

    if ($ctx['has_corrections']) {
        $sql .= " LEFT JOIN (
                      SELECT ticket_no, ticket_line_item, po_number, MAX(id) AS id
                      FROM po_line_corrections
                      WHERE is_active = 1
                      GROUP BY ticket_no, ticket_line_item, po_number
                  ) cx ON cx.ticket_no        = p.ticket_no
                      AND cx.ticket_line_item = p.ticket_line_item
                      AND cx.po_number        = p.po_number
                  LEFT JOIN po_line_corrections c ON c.id = cx.id";
    }

    $sql .= " LEFT JOIN " . $ctx['bench_ref'] . " b
                  ON b.sku_key = TRIM(p.sku) AND b.uom_key = {$lookup_uom_expr}";

    return $sql;
}

/**
 * The eligibility WHERE shared by every query — byte for byte the page's
 * $current_query filter, plus the AON restriction when the role demands it,
 * plus the optional quarter/year filter on p.pr_date (see cs_normalize_filters()).
 *
 * p.pr_date is used rather than p.po_create_date or p.created_at: pr_date has
 * no NULLs and spans the full PR history, while po_create_date is only stamped
 * going forward from when that column was added (82% NULL, and every non-null
 * value falls within the last month — confirmed live 2026-09-22) and created_at
 * is this system's row-insert timestamp, not a real-world date.
 *
 * $pdo is only needed to safely quote the free-text level2 filter value (it's
 * arbitrary master_data.level_2 text, not a validated int like year/quarter) —
 * same $pdo->quote() pattern already used in vendor_eval_v2_sender.php.
 */
function cs_eligibility_where(PDO $pdo, array $ctx, array $filters = []): string {
    $where = "p.po_number IS NOT NULL AND p.po_number != ''
              AND p.po_amount > 0 AND p.qty > 0
              AND p.sku IS NOT NULL AND p.sku != ''
              AND IFNULL(p.ticket_type, '') NOT IN ('Intercompany', 'Intercompany New Store')
              AND IFNULL(p.status_ticket, '') NOT IN ('Cancel', 'Cancelled', 'Cancel Ticket')
              AND (m.team_name IS NULL OR m.team_name != 'Admin Team')";

    if ($ctx['aon_only']) {
        $where .= " AND " . aon_sku_filter_sql('p');
    }

    // Both are pre-validated to bare integers by cs_normalize_filters(), so it's
    // safe to inline them rather than bind params (matches cs_team_expr()'s and
    // cs_has_correction()'s existing convention for internally-controlled values).
    if (!empty($filters['year'])) {
        $where .= " AND YEAR(p.pr_date) = " . (int)$filters['year'];
    }
    if (!empty($filters['quarter'])) {
        $where .= " AND QUARTER(p.pr_date) = " . (int)$filters['quarter'];
    }
    if (!empty($filters['month'])) {
        $where .= " AND MONTH(p.pr_date) = " . (int)$filters['month'];
    }

    // md.level_2 is free text (master_data.level_2), so it's quoted through the
    // driver rather than inlined like the validated int filters above. 'Unmapped'
    // is the same sentinel cs_line_base_sql()'s taxonomy_level_2 column falls
    // back to, so filtering on it here catches every row with no master_data
    // link or a blank level_2 — matching sla_report.php's Unmapped convention.
    if (!empty($filters['level2'])) {
        if ($filters['level2'] === 'Unmapped') {
            $where .= " AND (md.level_2 IS NULL OR TRIM(md.level_2) = '')";
        } else {
            $where .= " AND TRIM(md.level_2) = " . $pdo->quote($filters['level2']);
        }
    }

    return $where;
}

/**
 * LEVEL 1 — one row per eligible PR line with every value the corrections can
 * influence already resolved, but before the bucket is decided.
 */
function cs_line_base_sql(PDO $pdo, array $ctx, array $filters = []): string {
    $has_corr = $ctx['has_corrections'];

    $po_idr    = ca_po_idr_expr($ctx);
    $inclusive = cs_inclusive_expr($ctx, 'p');
    $qty       = "(p.qty + 0e0)";

    // PO total in IDR with PPN stripped when the purchasing org is inclusive.
    $nett_base = "(CASE WHEN {$inclusive} THEN {$po_idr} / 1.11 ELSE {$po_idr} END)";
    $price_override = $has_corr
        ? "(" . cs_has_correction('CURRENT_PRICE_OVERRIDE') . " AND c.new_current_unit_price IS NOT NULL)"
        : "FALSE";

    $current_unit = "CASE WHEN {$price_override}
                          THEN (c.new_current_unit_price + 0e0)
                          ELSE {$nett_base} / NULLIF({$qty}, 0) END";
    $current_nett = "CASE WHEN {$price_override}
                          THEN (c.new_current_unit_price + 0e0) * {$qty}
                          ELSE {$nett_base} END";

    if (!$has_corr) {
        $current_unit = "({$nett_base} / NULLIF({$qty}, 0))";
        $current_nett = $nett_base;
    }

    // A current-UOM override corrects a wrongly entered UOM on the PO line
    // itself; it is used for display AND as the default 2025 lookup UOM.
    $uom_display = $has_corr
        ? "CASE WHEN " . cs_has_correction('UOM_OVERRIDE_CURRENT') . "
                     AND " . cs_not_empty('c.new_uom_current') . "
                THEN UPPER(TRIM(c.new_uom_current))
                ELSE TRIM(p.uom) END"
        : "TRIM(p.uom)";

    // The benchmark override is applied here so the bucket logic downstream can
    // treat it as a confirmed comparison and skip the >10x mismatch check.
    $bench_override = $has_corr
        ? "(" . cs_has_correction('BENCHMARK_OVERRIDE') . " AND c.new_benchmark_unit_price IS NOT NULL)"
        : "FALSE";

    $benchmark_unit = "CASE WHEN {$bench_override} THEN (c.new_benchmark_unit_price + 0e0)
                            WHEN b.total_qty IS NOT NULL AND b.total_qty > 0
                                 THEN b.total_amount / b.total_qty
                            ELSE NULL END";

    $excluded = $has_corr ? "(" . cs_has_correction('EXCLUDE_FROM_CALCULATION') . ")" : "FALSE";

    $lookup_uom = cs_lookup_uom_expr($ctx);

    $sku_seen = "EXISTS (SELECT 1 FROM " . $ctx['bench_sku_ref'] . " bs WHERE bs.sku_key = TRIM(p.sku))";

    return "
        SELECT
            p.ticket_no, p.ticket_line_item, p.po_number, p.sku, p.p_org, p.p_group,
            p.item_description, p.vendor_name, p.ticket_type, p.status_ticket,
            p.assigned_pic, p.created_at, p.pr_date,
            COALESCE(NULLIF(TRIM(md.level_2), ''), 'Unmapped') AS taxonomy_level_2,
            {$qty}                         AS qty,
            TRIM(p.uom)                    AS uom_raw,
            {$uom_display}                 AS uom,
            {$lookup_uom}                  AS uom_2025,
            {$inclusive}                   AS is_inclusive,
            {$current_nett}                AS current_nett_total,
            {$current_unit}                AS current_unit_price,
            {$benchmark_unit}              AS benchmark_unit,
            {$bench_override}              AS bench_overridden,
            {$excluded}                    AS is_excluded,
            " . ($has_corr ? "(c.id IS NOT NULL)" : "FALSE") . " AS is_corrected,
            " . ($has_corr ? "c.reason" : "NULL")               . " AS correction_reason,
            b.total_amount                 AS history_total_amount,
            b.total_qty                    AS history_total_qty,
            b.po_count                     AS history_po_count,
            {$sku_seen}                    AS sku_seen_2025,
            " . cs_team_expr($ctx) . "     AS team
        FROM prs p" . cs_joins($ctx, $lookup_uom) . "
        WHERE " . cs_eligibility_where($pdo, $ctx, $filters);
}

function cs_lookup_uom_expr(array $ctx): string {
    $uom_display = $ctx['has_corrections']
        ? "CASE WHEN " . cs_has_correction('UOM_OVERRIDE_CURRENT') . "
                     AND " . cs_not_empty('c.new_uom_current') . "
                THEN UPPER(TRIM(c.new_uom_current))
                ELSE TRIM(p.uom) END"
        : "TRIM(p.uom)";

    if (!$ctx['has_corrections']) {
        return $uom_display;
    }

    return "CASE WHEN " . cs_has_correction('UOM_OVERRIDE_2025') . "
                     AND " . cs_not_empty('c.new_uom_2025') . "
                THEN UPPER(TRIM(c.new_uom_2025))
                ELSE {$uom_display} END";
}

// --- LEVEL 2: bucket + savings ----------------------------------------------

/**
 * LEVEL 2 — adds the price ratio, the bucket a line falls into, and the money
 * that follows from it. Every endpoint selects from this, so the four tabs, the
 * KPI cards and the chart can never disagree about which line is which.
 *
 * Bucket order reproduces the loop's control flow exactly: a manual exclusion
 * short-circuits before the benchmark is even looked at; a missing benchmark is
 * unmatched; a >10x (or <0.1x) deviation that has NOT been confirmed by an
 * override is held back for review; everything else counts.
 */
function cs_lines_sql(PDO $pdo, array $ctx, array $filters = []): string {
    $ratio = "CASE WHEN z0.bench_overridden THEN 1.0
                   WHEN z0.current_unit_price > 0
                        THEN z0.benchmark_unit / z0.current_unit_price
                   ELSE 0 END";

    $high = CS_RATIO_HIGH;
    $low  = CS_RATIO_LOW;

    return "
        SELECT z0.*,
               {$ratio} AS price_ratio,
               CASE
                   WHEN z0.is_excluded                 THEN 'excluded'
                   WHEN z0.benchmark_unit IS NULL      THEN 'unmatched'
                   WHEN NOT z0.bench_overridden
                        AND ({$ratio} > {$high} OR {$ratio} < {$low}) THEN 'review'
                   ELSE 'matched'
               END AS bucket,
               (z0.benchmark_unit * z0.qty)                            AS benchmark_total,
               (z0.benchmark_unit * z0.qty - z0.current_nett_total)    AS cost_savings
        FROM (" . cs_line_base_sql($pdo, $ctx, $filters) . ") z0";
}

// --- FILTERS ----------------------------------------------------------------

/**
 * Normalize the client-supplied filter payload into a fixed shape, so a missing
 * or malformed field can never reach the query builder. Counterpart of
 * ca_normalize_filters(). year/quarter filter on p.pr_date (see
 * cs_eligibility_where()) — a bare 4-digit year and 1-4 quarter, anything else
 * discarded to 'all'.
 */
function cs_normalize_filters($raw): array {
    $f = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($f)) $f = [];

    $year = (string)($f['year'] ?? '');
    if (!preg_match('/^\d{4}$/', $year)) $year = '';

    $quarter = (string)($f['quarter'] ?? '');
    if (!in_array($quarter, ['1', '2', '3', '4'], true)) $quarter = '';

    // Month and Quarter both filter p.pr_date at different granularities; the
    // UI keeps them mutually exclusive (picking one clears the other), but this
    // normalizer doesn't enforce that itself — cs_eligibility_where() just ANDs
    // whatever is non-empty, same as every other filter here.
    $month = (string)($f['month'] ?? '');
    if (!preg_match('/^([1-9]|1[0-2])$/', $month)) $month = '';

    // Free text (master_data.level_2, or the literal sentinel 'Unmapped'), so
    // only length-capped here — cs_eligibility_where() quotes it through the
    // driver before it ever reaches SQL. 150 matches master_data.level_2's
    // column width, so anything longer could never match a real row anyway.
    $level2 = trim((string)($f['level2'] ?? ''));
    if (mb_strlen($level2) > 150) $level2 = '';

    return [
        'team'    => trim((string)($f['team'] ?? '')),
        'year'    => $year,
        'quarter' => $quarter,
        'month'   => $month,
        'level2'  => $level2,
    ];
}

/**
 * WHERE fragment applied on top of level 2, plus its bound parameters.
 *
 * $bucket selects one tab ('matched' | 'review' | 'unmatched' | 'excluded') or
 * 'all' for the figures that span every line (the page's Current Nett Spend and
 * the Matched Coverage denominator both count every eligible line, including
 * excluded ones — the loop added to $total_current_spend before the exclusion
 * short-circuit).
 */
function cs_outer_where(array $filters, string $bucket = 'all'): array {
    $where  = "1 = 1";
    $params = [];

    if ($bucket !== 'all' && in_array($bucket, ['matched', 'review', 'unmatched', 'excluded'], true)) {
        $where .= " AND z.bucket = '{$bucket}'";
    }

    if ($filters['team'] !== '') {
        $where .= " AND z.team = :team";
        $params['team'] = $filters['team'];
    }

    return [$where, $params];
}

/**
 * Global-search predicate. Every whitespace-separated word must match at least
 * one searchable column (AND across words, OR across columns) — the same smart
 * search DataTables applied client-side, but over the underlying values rather
 * than the rendered cell text.
 *
 * @return array{0:string,1:array} [sql fragment (may be ''), extra params]
 */
function cs_search_where(string $search): array {
    $search = trim($search);
    if ($search === '') return ['', []];

    $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
    $cols  = ['z.ticket_no', 'z.po_number', 'z.sku', 'z.item_description', 'z.vendor_name', 'z.team', 'z.uom'];

    $clauses = [];
    $params  = [];
    foreach ($words as $i => $word) {
        $ors = [];

        foreach ($cols as $j => $col) {
            $key = "search{$i}_{$j}";
            $ors[] = "{$col} LIKE :{$key}";
            $params[$key] = '%' . $word . '%';
        }
        $clauses[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [' AND ' . implode(' AND ', $clauses), $params];
}

// --- AGGREGATES -------------------------------------------------------------

/**
 * ONE combined Team x Taxonomy-Level-2 breakdown, grouped after only
 * Year/Quarter/Month have been applied in SQL (Team and Taxonomy Level 2 are
 * left as free grouping dimensions, not filtered) — so both the team totals
 * (cs_breakdown_from_matrix()) AND the taxonomy option list
 * (cs_matrix_level2_options()) can be sliced out of this ONE query in PHP,
 * instead of two separate full scans of the eligible lines. An earlier version
 * ran cs_breakdown() and a standalone cs_level2_options() as two queries per
 * summary request; this replaces both and was the actual cause of the KPI
 * cards/chart feeling slower than the tables after the team/taxonomy
 * drill-down filter bar was added.
 */
function cs_breakdown_matrix(PDO $pdo, array $ctx, array $filters): array {
    $line_filters = $filters;
    $line_filters['level2'] = '';

    $sql = "SELECT z.team, z.taxonomy_level_2 AS level2,
                   COALESCE(SUM(z.current_nett_total), 0)                        AS spend,
                   COALESCE(SUM(CASE WHEN z.bucket = 'matched'
                                     THEN z.benchmark_total END), 0)             AS benchmark,
                   COALESCE(SUM(CASE WHEN z.bucket = 'matched'
                                     THEN z.cost_savings END), 0)                AS savings,
                   COALESCE(SUM(CASE WHEN z.bucket = 'matched'
                                     THEN z.current_nett_total END), 0)          AS matched_spend,
                   SUM(z.bucket = 'matched')                                     AS matched,
                   SUM(z.bucket = 'review')                                      AS review,
                   SUM(z.bucket = 'unmatched')                                   AS unmatched,
                   SUM(z.bucket = 'excluded')                                    AS excluded,
                   SUM(z.bucket = 'matched' AND z.cost_savings >= 0)             AS saving_lines,
                   SUM(z.bucket = 'matched' AND z.cost_savings <  0)             AS increase_lines
            FROM (" . cs_lines_sql($pdo, $ctx, $line_filters) . ") z
            GROUP BY z.team, z.taxonomy_level_2";

    $rows = [];
    foreach ($pdo->query($sql) as $row) {
        $rows[] = [
            'team'           => $row['team'],
            'level2'         => $row['level2'],
            'spend'          => (float)$row['spend'],
            'benchmark'      => (float)$row['benchmark'],
            'savings'        => (float)$row['savings'],
            'matched_spend'  => (float)$row['matched_spend'],
            'matched'        => (int)$row['matched'],
            'review'         => (int)$row['review'],
            'unmatched'      => (int)$row['unmatched'],
            'excluded'       => (int)$row['excluded'],
            'saving_lines'   => (int)$row['saving_lines'],
            'increase_lines' => (int)$row['increase_lines'],
        ];
    }
    return $rows;
}

/**
 * Collapses cs_breakdown_matrix() down to team => totals — the shape the KPI
 * cards, chart and tab counters consume — optionally restricted to one
 * Taxonomy Level 2 value (the currently selected filter, applied here in PHP
 * rather than in the matrix's own SQL so the same matrix also serves
 * cs_matrix_level2_options() unfiltered by it).
 */
function cs_breakdown_from_matrix(array $matrix, string $level2 = ''): array {
    $out = [];
    $keys = ['spend', 'benchmark', 'savings', 'matched_spend', 'matched', 'review', 'unmatched', 'excluded', 'saving_lines', 'increase_lines'];

    foreach ($matrix as $row) {
        if ($level2 !== '' && $row['level2'] !== $level2) continue;

        $team = $row['team'];
        if (!isset($out[$team])) {
            $out[$team] = array_fill_keys($keys, 0);
        }
        foreach ($keys as $k) {
            $out[$team][$k] += $row[$k];
        }
    }

    ksort($out);
    return $out;
}

/**
 * Distinct Taxonomy Level 2 values (including the 'Unmapped' sentinel) present
 * for the given team ('' = every team), sliced from the same matrix. Powers
 * the filter bar's team -> taxonomy drill-down: when a team is selected, the
 * Taxonomy Level 2 dropdown should only offer categories that team actually
 * has, rather than the full unfiltered list.
 */
function cs_matrix_level2_options(array $matrix, string $team = ''): array {
    $seen = [];
    foreach ($matrix as $row) {
        if ($team !== '' && $row['team'] !== $team) continue;
        $seen[$row['level2']] = true;
    }

    $options = array_keys($seen);
    usort($options, function ($a, $b) {
        if ($a === 'Unmapped' && $b !== 'Unmapped') return 1;
        if ($b === 'Unmapped' && $a !== 'Unmapped') return -1;
        return strcmp($a, $b);
    });
    return $options;
}

function cs_totals(array $breakdown, string $team = ''): array {
    $out = [
        'eligible_count' => 0, 'matched_count' => 0, 'review_count' => 0,
        'unmatched_count' => 0, 'excluded_count' => 0,
        'saving_lines' => 0, 'increase_lines' => 0,
        'total_current_spend' => 0.0, 'total_2025_benchmark' => 0.0,
        'total_savings' => 0.0, 'matched_current_spend' => 0.0,
    ];

    foreach ($breakdown as $name => $t) {
        if ($team !== '' && $name !== $team) continue;

        $out['matched_count']         += $t['matched'];
        $out['review_count']          += $t['review'];
        $out['unmatched_count']       += $t['unmatched'];
        $out['excluded_count']        += $t['excluded'];
        $out['saving_lines']          += $t['saving_lines'];
        $out['increase_lines']        += $t['increase_lines'];
        $out['total_current_spend']   += $t['spend'];
        $out['total_2025_benchmark']  += $t['benchmark'];
        $out['total_savings']         += $t['savings'];
        $out['matched_current_spend'] += $t['matched_spend'];
    }

    $out['eligible_count'] = $out['matched_count'] + $out['review_count']
                           + $out['unmatched_count'] + $out['excluded_count'];

    // Derived exactly as the page did, so the wording under the cards is unchanged.
    $out['coverage_pct'] = ($out['total_current_spend'] > 0 && $out['eligible_count'] > 0)
        ? ($out['matched_count'] / $out['eligible_count']) * 100
        : 0;
    $out['overall_savings_pct'] = ($out['total_2025_benchmark'] > 0)
        ? ($out['total_savings'] / $out['total_2025_benchmark']) * 100
        : 0;

    return $out;
}
