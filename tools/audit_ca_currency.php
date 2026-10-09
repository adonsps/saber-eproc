<?php
// tools/audit_ca_currency.php
//
// READ-ONLY audit of the currency data behind Cost Avoidance. Finds the rows
// that can be mis-valued in IDR:
//   1. a currency code that is not in `currencies` (typo, retired code),
//   2. a code with stray spaces / lower-case (looks like IDR to naive code),
//   3. a code whose rate is 0 / missing (cannot be converted),
//   4. a foreign baseline with no snapshot rate (follows today's rate),
//   5. a baseline and PO in different currencies (cross-currency avoidance).
//
// Usage (parity with the app's PHP): docker-compose exec app php tools/audit_ca_currency.php
//
// SAFETY:
//   - CLI only. SELECT queries only: no DB writes, no AI/Gemini, no WA/email.
//   - Prints counts and a few sample ticket lines; never prints credentials.

if (php_sapi_name() !== 'cli') {
    exit("CLI only.\n");
}

$_SERVER['REQUEST_URI'] = '';
define('RUNNING_AS_CLI_TOOL', true);
$root = dirname(__DIR__);
require_once $root . '/workspace/config/database.php';

echo "[READ ONLY] No changes will be written.\n";
$id = $pdo->query("SELECT DATABASE() d, VERSION() v")->fetch(PDO::FETCH_ASSOC);
echo "DB: {$id['d']} (MySQL {$id['v']})\n\n";

function ca_audit_table(PDO $pdo, string $title, string $sql, int $limit = 15): void {
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    echo "== $title: " . count($rows) . " row(s)" . (count($rows) > $limit ? " (first $limit shown)" : '') . "\n";
    foreach (array_slice($rows, 0, $limit) as $r) {
        echo '   ' . implode(' | ', array_map(function ($k, $v) { return "$k=" . ($v === null ? 'NULL' : $v); }, array_keys($r), $r)) . "\n";
    }
    echo "\n";
}

echo "== currencies table\n";
foreach ($pdo->query("SELECT code, rate_to_idr, is_active FROM currencies ORDER BY code")->fetchAll(PDO::FETCH_ASSOC) as $c) {
    echo "   {$c['code']}: rate=" . (float)$c['rate_to_idr'] . ' active=' . (int)$c['is_active'] . "\n";
}
echo "\n";

$known = "SELECT UPPER(TRIM(code)) FROM currencies WHERE rate_to_idr > 0 OR code = 'IDR'";

foreach ([['prs', 'baseline_currency', 'baseline_price'], ['prs', 'po_currency', 'po_amount']] as [$t, $col, $amt]) {
    ca_audit_table($pdo, "$t.$col: not a known currency with a rate",
        "SELECT IFNULL($col,'NULL') code, COUNT(*) n FROM $t
         WHERE $col IS NOT NULL AND TRIM($col) <> '' AND UPPER(TRIM($col)) NOT IN ($known)
           AND ($amt IS NOT NULL AND $amt <> 0)
         GROUP BY $col");
    ca_audit_table($pdo, "$t.$col: stray spaces or lower-case",
        "SELECT CONCAT('[', $col, ']') code, COUNT(*) n FROM $t
         WHERE $col IS NOT NULL AND BINARY $col <> UPPER(TRIM($col)) GROUP BY $col");
}

ca_audit_table($pdo, 'manual_cost_avoidance.currency: not a known currency with a rate',
    "SELECT IFNULL(currency,'NULL') code, COUNT(*) n FROM manual_cost_avoidance
     WHERE currency IS NOT NULL AND TRIM(currency) <> '' AND UPPER(TRIM(currency)) NOT IN ($known) GROUP BY currency");
ca_audit_table($pdo, 'vendor_quotation_last_price.currency: not a known currency with a rate',
    "SELECT IFNULL(currency,'NULL') code, COUNT(*) n FROM vendor_quotation_last_price
     WHERE currency IS NOT NULL AND TRIM(currency) <> '' AND UPPER(TRIM(currency)) NOT IN ($known) GROUP BY currency");

ca_audit_table($pdo, 'foreign baselines with NO snapshot rate (follow today\'s rate)',
    "SELECT UPPER(TRIM(baseline_currency)) code, COUNT(*) n FROM prs
     WHERE baseline_price IS NOT NULL AND baseline_price <> 0
       AND UPPER(TRIM(IFNULL(NULLIF(TRIM(baseline_currency),''),'IDR'))) <> 'IDR'
       AND (baseline_fx_rate IS NULL OR baseline_fx_rate <= 0)
     GROUP BY UPPER(TRIM(baseline_currency))");

ca_audit_table($pdo, 'baseline currency <> PO currency (needs a human look)',
    "SELECT ticket_no, ticket_line_item ln, po_number po,
            UPPER(TRIM(IFNULL(NULLIF(TRIM(baseline_currency),''),'IDR'))) base_cur,
            UPPER(TRIM(IFNULL(NULLIF(TRIM(po_currency),''),'IDR'))) po_cur,
            baseline_price, po_amount
     FROM prs
     WHERE baseline_price IS NOT NULL AND baseline_price <> 0 AND po_number IS NOT NULL AND po_number <> '' AND po_amount > 0
       AND UPPER(TRIM(IFNULL(NULLIF(TRIM(baseline_currency),''),'IDR'))) <> UPPER(TRIM(IFNULL(NULLIF(TRIM(po_currency),''),'IDR')))
     ORDER BY po_amount DESC");

echo "Done. Fix any rows above through the app (edit history is kept), not by SQL.\n";
