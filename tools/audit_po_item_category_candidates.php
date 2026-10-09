<?php
// tools/audit_po_item_category_candidates.php
//
// READ-ONLY audit tool: previews a keyword-based Barang/Jasa classification
// against real local PO data, BEFORE any schema/table exists for it. Purpose
// is purely to let a human review the ruleset and its output — this tool
// makes no persistent decision and writes nothing.
//
// Source: vendor_eval_po_taxonomy.item_descriptions_json (a JSON array of
// distinct prs.item_description strings per PO, already aggregated by the
// existing taxonomy mapper — see tools/run_po_taxonomy_mapping.php). A PO is
// classified "jasa" when at least half of its distinct line-item
// descriptions match a JASA keyword, else "barang". POs with a genuine mix
// (some match, some don't) are also flagged "mixed" for extra scrutiny,
// since those are the ones most likely to need a manual override once the
// real classifier ships.
//
// Usage:
//   C:\xampp\php\php.exe tools/audit_po_item_category_candidates.php
//
// Output:
//   Console summary + sample rows per bucket.
//   db_backups/local_runtime_exports/po_item_category_audit_YYYYMMDD_HHMMSS.csv
//
// SAFETY:
//   - Refuses browser/web access (CLI only).
//   - No DB writes, no AI/Gemini calls, no WA/email. SELECT only.

// ── CLI guard ──────────────────────────────────────────────────────────────
if (php_sapi_name() !== 'cli' && php_sapi_name() !== 'cli-server') {
    http_response_code(403);
    exit("This tool is CLI-only. Run via: C:\\xampp\\php\\php.exe tools/audit_po_item_category_candidates.php\n");
}
if (isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_URI']) && $_SERVER['REQUEST_URI'] !== '') {
    exit("Browser access refused. Run from command line.\n");
}

$_SERVER['REQUEST_URI'] = '';
define('RUNNING_AS_CLI_TOOL', true);

$root = dirname(__DIR__);
require_once $root . '/workspace/config/database.php';
require_once $root . '/workspace/includes/vendor_eval_v2_item_category.php';

echo "\n";
echo "=======================================================================\n";
echo " PO Item Category (Barang/Jasa) Classification Preview\n";
echo " " . date('Y-m-d H:i:s') . "\n";
echo "=======================================================================\n\n";

$id = $pdo->query('SELECT DATABASE() AS db, USER() AS usr, VERSION() AS ver')->fetch(PDO::FETCH_ASSOC);
echo "DB identity: {$id['db']} / {$id['usr']} / {$id['ver']}\n\n";

// ── Keyword ruleset (JASA signal) — single source of truth lives in
// workspace/includes/vendor_eval_v2_item_category.php so this preview and
// the live worklist classification can never drift apart.
$JASA_KEYWORDS = ve2_item_category_keywords();

echo "Keyword ruleset (any match on a line item -> that line counts as 'jasa'):\n";
echo "  " . implode(', ', $JASA_KEYWORDS) . "\n";
echo "  A PO is classified 'jasa' when >= 50% of its distinct line-item\n";
echo "  descriptions match; 'mixed' is a sub-flag when it has BOTH matching\n";
echo "  and non-matching lines (regardless of which side wins the majority).\n\n";

// ── Pull PO-level item descriptions ─────────────────────────────────────────
$rows = $pdo->query("
    SELECT po_number, vendor_name, taxonomy_level_2, taxonomy_level_3,
           item_descriptions_json, line_item_count, status
      FROM vendor_eval_po_taxonomy
     WHERE item_descriptions_json IS NOT NULL AND item_descriptions_json <> ''
")->fetchAll(PDO::FETCH_ASSOC);

echo "Fetched " . count($rows) . " PO(s) with item descriptions from vendor_eval_po_taxonomy.\n\n";

$buckets = ['barang' => [], 'jasa' => [], 'unknown' => []];
$mixed_count = 0;

foreach ($rows as $r) {
    $descs = json_decode((string)$r['item_descriptions_json'], true);
    if (!is_array($descs) || empty($descs)) {
        $buckets['unknown'][] = $r + ['jasa_hits' => 0, 'total' => 0, 'sample' => ''];
        continue;
    }
    $result = ve2_classify_item_category($descs);
    if ($result['mixed']) {
        $mixed_count++;
    }
    $buckets[$result['category']][] = $r + [
        'jasa_hits' => $result['jasa_hits'],
        'total'     => $result['total'],
        'mixed'     => $result['mixed'],
        'sample'    => (string)$descs[0],
    ];
}

echo "-----------------------------------------------------------------------\n";
echo "Summary\n";
echo "-----------------------------------------------------------------------\n";
printf("  %-10s %6d PO(s)\n", 'Barang',  count($buckets['barang']));
printf("  %-10s %6d PO(s)\n", 'Jasa',    count($buckets['jasa']));
printf("  %-10s %6d PO(s)  (no description text at all)\n", 'Unknown', count($buckets['unknown']));
printf("  %-10s %6d PO(s)  (mixed signal across their own line items — extra scrutiny candidates)\n", 'Mixed', $mixed_count);
echo "\n";

function sample_rows(array $rows, int $n): array
{
    shuffle($rows);
    return array_slice($rows, 0, $n);
}

foreach (['jasa', 'barang'] as $cat) {
    echo "-----------------------------------------------------------------------\n";
    echo "Sample: " . strtoupper($cat) . " (" . count($buckets[$cat]) . " total, showing up to 12)\n";
    echo "-----------------------------------------------------------------------\n";
    foreach (sample_rows($buckets[$cat], 12) as $r) {
        $flag = !empty($r['mixed']) ? ' [MIXED]' : '';
        printf(
            "  %-16s %-28s %d/%d jasa-matched%s\n    -> %s\n",
            $r['po_number'],
            mb_substr((string)$r['vendor_name'], 0, 28),
            $r['jasa_hits'],
            $r['total'],
            $flag,
            mb_substr($r['sample'], 0, 90)
        );
    }
    echo "\n";
}

// ── CSV export for full review ──────────────────────────────────────────────
$export_dir = $root . '/db_backups/local_runtime_exports';
if (!is_dir($export_dir)) {
    mkdir($export_dir, 0755, true);
}
$csv_path = $export_dir . '/po_item_category_audit_' . date('Ymd_His') . '.csv';
$fh = fopen($csv_path, 'w');
fputcsv($fh, ['po_number', 'vendor_name', 'taxonomy_level_2', 'taxonomy_level_3', 'category', 'mixed', 'jasa_hits', 'total_lines', 'sample_description']);
foreach (['jasa', 'barang', 'unknown'] as $cat) {
    foreach ($buckets[$cat] as $r) {
        fputcsv($fh, [
            $r['po_number'], $r['vendor_name'], $r['taxonomy_level_2'], $r['taxonomy_level_3'],
            $cat, !empty($r['mixed']) ? 'yes' : 'no', $r['jasa_hits'], $r['total'], $r['sample'],
        ]);
    }
}
fclose($fh);
echo "Full CSV written to: " . str_replace($root . '\\', '', $csv_path) . "\n";
echo "(gitignored — db_backups/local_runtime_exports/)\n\n";

echo "This is a PREVIEW only. Nothing was written to the database.\n";
