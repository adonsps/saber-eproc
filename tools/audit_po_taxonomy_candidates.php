<?php
// tools/audit_po_taxonomy_candidates.php
//
// READ-ONLY audit tool: surveys prs data and exports a candidate CSV
// for PO taxonomy classification. Run BEFORE building the mapping engine
// so that fuzzy/AI thresholds can be calibrated against real data.
//
// Usage:
//   C:\xampp\php\php.exe tools/audit_po_taxonomy_candidates.php
//
// Output:
//   db_backups/local_runtime_exports/po_taxonomy_candidate_audit_YYYYMMDD_HHMMSS.csv
//
// SAFETY:
//   - Refuses browser/web access (CLI only).
//   - No DB writes, no AI/Gemini calls, no WA/email.
//   - SELECT queries only.

// ── CLI guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/audit_po_taxonomy_candidates.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI'])) {
    exit("Browser access refused. Run from command line.\n");
}

$tool_start = microtime(true);

// ── Bootstrap ──────────────────────────────────────────────────────────────
// Simulate cli-server detection so database.php picks local XAMPP credentials.
// We set SERVER_SOFTWARE so REQUEST_URI guard doesn't fire, but NOT HTTP_HOST.
$_SERVER['REQUEST_URI'] = '';          // allows database.php path detection
define('RUNNING_AS_CLI_TOOL', true);

$root = dirname(__DIR__);
require_once $root . '/workspace/config/database.php';

// ── Output directory ───────────────────────────────────────────────────────
$export_dir = $root . '/db_backups/local_runtime_exports';
if (!is_dir($export_dir)) {
    mkdir($export_dir, 0755, true);
}
$timestamp  = date('Ymd_His');
$csv_path   = $export_dir . '/po_taxonomy_candidate_audit_' . $timestamp . '.csv';

echo "\n";
echo "=======================================================================\n";
echo " PO Taxonomy Candidate Audit\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n\n";

// ── Helper: classify description quality ──────────────────────────────────
function classify_description(string $desc): string {
    $trimmed = trim($desc);
    if ($trimmed === '') return 'blank';
    if (strlen($trimmed) < 8) return 'short';
    // Numeric/code-like: >= 60% non-alpha characters
    $nonalpha = preg_replace('/[A-Za-z ]/', '', $trimmed);
    if (strlen($trimmed) > 0 && (strlen($nonalpha) / strlen($trimmed)) >= 0.6) {
        return 'code';
    }
    // Very short meaningful text
    if (strlen($trimmed) < 15) return 'short';
    return 'ok';
}

function mapping_quality(array $descriptions): array {
    $total       = count($descriptions);
    $blank       = 0;
    $short_code  = 0;
    $ok          = 0;
    $total_len   = 0;

    foreach ($descriptions as $d) {
        $cls = classify_description($d);
        $total_len += strlen(trim($d));
        if ($cls === 'blank')              $blank++;
        elseif ($cls === 'short' || $cls === 'code') $short_code++;
        else                               $ok++;
    }

    $avg_len = $total > 0 ? round($total_len / $total, 1) : 0;

    if ($total === 0 || $blank === $total) {
        $quality = 'POOR';
        $notes   = 'All descriptions are blank.';
    } elseif ($ok === 0) {
        $quality = 'POOR';
        $notes   = "All {$total} descriptions are short/code-like (avg {$avg_len} chars). Fuzzy matching will be unreliable.";
    } elseif ($ok >= $total * 0.6) {
        $quality = 'GOOD';
        $notes   = "{$ok}/{$total} descriptions are substantive (avg {$avg_len} chars).";
    } else {
        $quality = 'MEDIUM';
        $notes   = "{$ok}/{$total} substantive, {$short_code} short/code-like, {$blank} blank (avg {$avg_len} chars).";
    }

    return [
        'quality'            => $quality,
        'avg_len'            => $avg_len,
        'blank_count'        => $blank,
        'short_code_count'   => $short_code,
        'ok_count'           => $ok,
        'notes'              => $notes,
    ];
}

// ── Base filter conditions (shared) ───────────────────────────────────────
// Intercompany exclusion, has PO, has GR, has vendor.
$base_where = "
    WHERE p.po_number IS NOT NULL
      AND p.po_number != ''
      AND p.gr_date IS NOT NULL
      AND p.gr_date <= CURDATE()
      AND p.vendor_name IS NOT NULL
      AND p.vendor_name != ''
      AND LOWER(IFNULL(p.ticket_type,'')) NOT IN ('intercompany','intercompany new store')
";

// ── Count: no date cutoff ─────────────────────────────────────────────────
$sql_count_all = "
    SELECT COUNT(DISTINCT p.po_number) AS po_count, COUNT(*) AS line_count
    FROM prs p
    {$base_where}
";
$row_all = $pdo->query($sql_count_all)->fetch(PDO::FETCH_ASSOC);

// ── Count: with May 2026 cutoff ───────────────────────────────────────────
$sql_count_may = "
    SELECT COUNT(DISTINCT p.po_number) AS po_count, COUNT(*) AS line_count
    FROM prs p
    {$base_where}
      AND p.pr_date >= '2026-05-01'
";
$has_pr_date = true;
try {
    $row_may = $pdo->query($sql_count_may)->fetch(PDO::FETCH_ASSOC);
} catch (\PDOException $e) {
    $has_pr_date = false;
    $row_may = ['po_count' => 'N/A (no pr_date column)', 'line_count' => 'N/A'];
}

echo "Scope comparison:\n";
echo "  No date cutoff:          {$row_all['po_count']} POs, {$row_all['line_count']} line items\n";
echo "  pr_date >= 2026-05-01:   {$row_may['po_count']} POs, {$row_may['line_count']} line items\n";
echo "  (Audit below uses NO date cutoff — matches full eval candidate pool)\n\n";

// ── Fetch all in-scope line items ─────────────────────────────────────────
echo "Fetching all in-scope prs rows...\n";
$sql_lines = "
    SELECT
        p.po_number,
        p.vendor_name,
        p.requestor_name,
        p.p_group,
        p.ticket_type,
        p.gr_date,
        p.item_description
    FROM prs p
    {$base_where}
    ORDER BY p.po_number, p.gr_date DESC
";
$all_lines = $pdo->query($sql_lines)->fetchAll(PDO::FETCH_ASSOC);
$total_line_items = count($all_lines);
echo "  Fetched {$total_line_items} line items.\n\n";

// ── Group by PO ───────────────────────────────────────────────────────────
$po_map = [];
foreach ($all_lines as $row) {
    $po = $row['po_number'];
    if (!isset($po_map[$po])) {
        $po_map[$po] = [
            'po_number'    => $po,
            'vendor_name'  => $row['vendor_name'],
            'requestors'   => [],
            'p_groups'     => [],
            'ticket_types' => [],
            'gr_dates'     => [],
            'descriptions' => [],
        ];
    }
    if (!empty($row['requestor_name']) && !in_array($row['requestor_name'], $po_map[$po]['requestors'])) {
        $po_map[$po]['requestors'][] = $row['requestor_name'];
    }
    if (!empty($row['p_group']) && !in_array($row['p_group'], $po_map[$po]['p_groups'])) {
        $po_map[$po]['p_groups'][] = $row['p_group'];
    }
    if (!empty($row['ticket_type']) && !in_array($row['ticket_type'], $po_map[$po]['ticket_types'])) {
        $po_map[$po]['ticket_types'][] = $row['ticket_type'];
    }
    if (!empty($row['gr_date'])) {
        $po_map[$po]['gr_dates'][] = $row['gr_date'];
    }
    // Keep all descriptions (including duplicates) for line_item_count;
    // track distinct separately.
    $po_map[$po]['descriptions'][] = $row['item_description'] ?? '';
}

$total_pos = count($po_map);
echo "  Grouped into {$total_pos} distinct POs.\n\n";

// ── Summary accumulators ───────────────────────────────────────────────────
$sum = [
    'total_pos'                  => $total_pos,
    'total_line_items'           => $total_line_items,
    'pos_single_line'            => 0,
    'pos_multi_line'             => 0,
    'pos_multi_requestor'        => 0,
    'pos_multi_pgroup'           => 0,
    'pos_blank_description'      => 0,
    'pos_poor_quality'           => 0,
    'pos_medium_quality'         => 0,
    'pos_good_quality'           => 0,
    'p_group_freq'               => [],
    'ticket_type_freq'           => [],
    'vendor_po_count'            => [],
];

// ── Build per-PO rows for CSV ─────────────────────────────────────────────
$csv_rows = [];

foreach ($po_map as $po => $data) {
    $all_descs      = $data['descriptions'];
    $distinct_descs = array_values(array_unique($all_descs));
    $line_count     = count($all_descs);
    $distinct_count = count($distinct_descs);

    // Quality assessment
    $qinfo = mapping_quality($distinct_descs);

    // gr_date range
    if (!empty($data['gr_dates'])) {
        sort($data['gr_dates']);
        $gr_min = $data['gr_dates'][0];
        $gr_max = $data['gr_dates'][count($data['gr_dates']) - 1];
    } else {
        $gr_min = $gr_max = '';
    }

    // Sample: first 3 distinct descriptions, truncated to 120 chars each
    $sample_parts = [];
    foreach (array_slice($distinct_descs, 0, 3) as $i => $d) {
        $d_clean = str_replace(["\r", "\n", '"'], [' ', ' ', "'"], $d);
        $d_clean = strlen($d_clean) > 120 ? substr($d_clean, 0, 117) . '...' : $d_clean;
        $sample_parts[] = '[' . ($i + 1) . '] ' . $d_clean;
    }
    if ($distinct_count > 3) {
        $sample_parts[] = '... +' . ($distinct_count - 3) . ' more';
    }
    $sample = implode(' | ', $sample_parts);

    // Summary counters
    if ($line_count === 1) $sum['pos_single_line']++;
    else                   $sum['pos_multi_line']++;
    if (count($data['requestors']) > 1) $sum['pos_multi_requestor']++;
    if (count($data['p_groups'])   > 1) $sum['pos_multi_pgroup']++;
    if ($qinfo['blank_count'] >= $distinct_count) $sum['pos_blank_description']++;

    if ($qinfo['quality'] === 'POOR')   $sum['pos_poor_quality']++;
    elseif ($qinfo['quality'] === 'MEDIUM') $sum['pos_medium_quality']++;
    else                                 $sum['pos_good_quality']++;

    // p_group / ticket_type frequency
    foreach ($data['p_groups'] as $pg) {
        $sum['p_group_freq'][$pg] = ($sum['p_group_freq'][$pg] ?? 0) + 1;
    }
    foreach ($data['ticket_types'] as $tt) {
        $sum['ticket_type_freq'][$tt] = ($sum['ticket_type_freq'][$tt] ?? 0) + 1;
    }

    $vendor_key = $data['vendor_name'];
    $sum['vendor_po_count'][$vendor_key] = ($sum['vendor_po_count'][$vendor_key] ?? 0) + 1;

    $csv_rows[] = [
        'po_number'                   => $po,
        'vendor_name'                 => $data['vendor_name'],
        'requestor_names'             => implode(' | ', $data['requestors']),
        'p_group_values'              => implode(' | ', $data['p_groups']),
        'ticket_type_values'          => implode(' | ', $data['ticket_types']),
        'gr_date_min'                 => $gr_min,
        'gr_date_max'                 => $gr_max,
        'line_item_count'             => $line_count,
        'distinct_item_description_count' => $distinct_count,
        'item_descriptions_sample'    => $sample,
        'avg_description_length'      => $qinfo['avg_len'],
        'short_description_count'     => $qinfo['short_code_count'],
        'numeric_or_code_like_count'  => 0,  // refined below
        'likely_mapping_quality'      => $qinfo['quality'],
        'notes'                       => $qinfo['notes'],
    ];

    // Re-count code-like specifically (separate from short)
    $code_count = 0;
    foreach ($distinct_descs as $d) {
        if (classify_description($d) === 'code') $code_count++;
    }
    $csv_rows[count($csv_rows) - 1]['numeric_or_code_like_count'] = $code_count;
    // Recalculate short_description_count as pure short (not code)
    $short_only = 0;
    foreach ($distinct_descs as $d) {
        if (classify_description($d) === 'short') $short_only++;
    }
    $csv_rows[count($csv_rows) - 1]['short_description_count'] = $short_only;
}

// ── Sort CSV by quality (POOR first) then po_number ───────────────────────
usort($csv_rows, function($a, $b) {
    $q_order = ['POOR' => 0, 'MEDIUM' => 1, 'GOOD' => 2];
    $qa = $q_order[$a['likely_mapping_quality']] ?? 3;
    $qb = $q_order[$b['likely_mapping_quality']] ?? 3;
    if ($qa !== $qb) return $qa - $qb;
    return strcmp($a['po_number'], $b['po_number']);
});

// ── Write CSV ─────────────────────────────────────────────────────────────
$fp = fopen($csv_path, 'w');
if (!$fp) {
    exit("ERROR: Cannot open output file: {$csv_path}\n");
}

// UTF-8 BOM for Excel compatibility
fwrite($fp, "\xEF\xBB\xBF");

$headers = [
    'po_number', 'vendor_name', 'requestor_names', 'p_group_values',
    'ticket_type_values', 'gr_date_min', 'gr_date_max', 'line_item_count',
    'distinct_item_description_count', 'item_descriptions_sample',
    'avg_description_length', 'short_description_count',
    'numeric_or_code_like_count', 'likely_mapping_quality', 'notes',
];
fputcsv($fp, $headers);

foreach ($csv_rows as $row) {
    fputcsv($fp, array_map(fn($k) => $row[$k], $headers));
}
fclose($fp);

// ── Top 20 helpers ────────────────────────────────────────────────────────
function top_n(array $freq, int $n = 20): array {
    arsort($freq);
    return array_slice($freq, 0, $n, true);
}

$top_pgroups      = top_n($sum['p_group_freq']);
$top_ticket_types = top_n($sum['ticket_type_freq']);
$top_vendors      = top_n($sum['vendor_po_count']);

// ── Console summary ───────────────────────────────────────────────────────
$elapsed = round(microtime(true) - $tool_start, 2);

echo "=======================================================================\n";
echo " AUDIT SUMMARY\n";
echo "=======================================================================\n\n";

echo "── Volume ──────────────────────────────────────────────────────────────\n";
printf("  Total in-scope POs:               %d\n",   $sum['total_pos']);
printf("  Total line items:                 %d\n",   $sum['total_line_items']);
printf("  POs with 1 line item:             %d\n",   $sum['pos_single_line']);
printf("  POs with multiple line items:     %d\n",   $sum['pos_multi_line']);
printf("  POs with multiple requestors:     %d\n",   $sum['pos_multi_requestor']);
printf("  POs with multiple p_group values: %d\n",   $sum['pos_multi_pgroup']);
echo "\n";

echo "── Mapping quality forecast ─────────────────────────────────────────────\n";
printf("  GOOD quality  (substantive desc):  %d POs\n",  $sum['pos_good_quality']);
printf("  MEDIUM quality (mixed):            %d POs\n",  $sum['pos_medium_quality']);
printf("  POOR quality  (blank/code/short):  %d POs\n",  $sum['pos_poor_quality']);
printf("  POs with all-blank descriptions:   %d POs\n",  $sum['pos_blank_description']);
echo "\n";

echo "── Top 20 p_group values ────────────────────────────────────────────────\n";
foreach ($top_pgroups as $k => $v) {
    printf("  %-35s %d POs\n", ($k === '' ? '(blank)' : $k), $v);
}
echo "\n";

echo "── Top 20 ticket_type values ────────────────────────────────────────────\n";
foreach ($top_ticket_types as $k => $v) {
    printf("  %-35s %d POs\n", ($k === '' ? '(blank)' : $k), $v);
}
echo "\n";

echo "── Top 20 vendors by PO count ───────────────────────────────────────────\n";
foreach ($top_vendors as $k => $v) {
    printf("  %-45s %d POs\n", $k, $v);
}
echo "\n";

echo "=======================================================================\n";
printf(" Output CSV:  %s\n", $csv_path);
printf(" Elapsed:     %.2f seconds\n", $elapsed);
echo " DB writes:   NONE\n";
echo " AI calls:    NONE\n";
echo "=======================================================================\n\n";
