<?php
// workspace/pages/trigger_mapper.php
// Triggers the PO taxonomy mapping script from the web.
require_once '../includes/auth.php';
require_once '../config/database.php';

$db_name = $pdo->query('SELECT DATABASE()')->fetchColumn();
$db_host = $pdo->query('SELECT @@hostname')->fetchColumn();

$command = "php " . escapeshellarg(realpath(__DIR__ . '/../../tools/run_po_taxonomy_mapping.php')) . " --apply --confirm-db=" . escapeshellarg($db_name) . " --confirm-host=" . escapeshellarg($db_host) . " 2>&1";
$output = shell_exec($command);

echo "<h2>Taxonomy Mapper Triggered</h2>";
echo "<pre style='background: #f4f4f4; padding: 10px; border: 1px solid #ccc; max-height: 800px; overflow: auto;'>";
echo htmlspecialchars($output);
echo "</pre>";
echo "<br><a href='vendor_eval_v2_results.php'>Back to Vendor Evaluations</a>";
