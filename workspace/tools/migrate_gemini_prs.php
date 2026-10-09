<?php
// workspace/tools/migrate_gemini_prs.php
require_once '../config/database.php';
require_once '../config/gemini_api.php';

$action = $_POST['action'] ?? '';
$start_date = $_POST['start_date'] ?? '';
$end_date = $_POST['end_date'] ?? '';
$team_filter = $_POST['team'] ?? '';
$is_intercompany = $_POST['is_intercompany'] ?? '';
$is_aon = $_POST['is_aon'] ?? '';

// Fetch available teams for dropdown
$teamsStmt = $pdo->query("SELECT DISTINCT team_name FROM pgroup_team_mappings WHERE team_name IS NOT NULL AND team_name != '' ORDER BY team_name ASC");
$all_teams = $teamsStmt->fetchAll(PDO::FETCH_COLUMN);

// Build SQL dynamically based on filters
$where = "WHERE (prs.master_data_id IS NULL OR prs.master_data_id = 0 OR prs.master_data_id = '') 
          AND prs.po_number IS NOT NULL AND prs.po_number != ''
          AND prs.item_description IS NOT NULL AND prs.item_description != ''";
$params = [];

if ($start_date !== '') {
    $where .= " AND DATE(prs.pr_date) >= ?";
    $params[] = $start_date;
}
if ($end_date !== '') {
    $where .= " AND DATE(prs.pr_date) <= ?";
    $params[] = $end_date;
}
if ($team_filter !== '') {
    $where .= " AND m.team_name = ?";
    $params[] = $team_filter;
}
if ($is_intercompany === 'yes') {
    $where .= " AND LOWER(prs.ticket_type) LIKE '%intercompany%'";
} elseif ($is_intercompany === 'no') {
    $where .= " AND (prs.ticket_type IS NULL OR LOWER(prs.ticket_type) NOT LIKE '%intercompany%')";
}
if ($is_aon === 'yes') {
    $where .= " AND (prs.canvassing_date_approver_1 IS NULL OR prs.canvassing_date_approver_1 = '')";
} elseif ($is_aon === 'no') {
    $where .= " AND prs.canvassing_date_approver_1 IS NOT NULL AND prs.canvassing_date_approver_1 != ''";
}

$join = "LEFT JOIN pgroup_team_mappings m ON prs.p_group = m.p_group";

?>
<!DOCTYPE html>
<html>
<head>
    <title>Bulk PR Mapping: Gemini</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 text-gray-800 p-8">
    <div class="max-w-4xl mx-auto bg-white p-6 rounded-lg shadow">
        <h1 class="text-2xl font-bold mb-4 text-indigo-600">Gemini Batch Mapping Tool</h1>
        
        <form method="POST" id="mapForm" class="space-y-4 mb-8">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700">PR Date (Start)</label>
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" class="mt-1 block w-full border border-gray-300 rounded p-2">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">PR Date (End)</label>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" class="mt-1 block w-full border border-gray-300 rounded p-2">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">Team</label>
                    <select name="team" class="mt-1 block w-full border border-gray-300 rounded p-2">
                        <option value="">-- All Teams --</option>
                        <?php foreach($all_teams as $t): ?>
                            <option value="<?= htmlspecialchars($t) ?>" <?= $team_filter === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">Ticket Type</label>
                    <select name="is_intercompany" class="mt-1 block w-full border border-gray-300 rounded p-2">
                        <option value="">-- All --</option>
                        <option value="yes" <?= $is_intercompany === 'yes' ? 'selected' : '' ?>>Intercompany Only</option>
                        <option value="no" <?= $is_intercompany === 'no' ? 'selected' : '' ?>>Exclude Intercompany</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">AON Ticket</label>
                    <select name="is_aon" class="mt-1 block w-full border border-gray-300 rounded p-2">
                        <option value="">-- All --</option>
                        <option value="yes" <?= $is_aon === 'yes' ? 'selected' : '' ?>>AON Only</option>
                        <option value="no" <?= $is_aon === 'no' ? 'selected' : '' ?>>Exclude AON</option>
                    </select>
                </div>
            </div>
            
            <div class="flex space-x-4">
                <button type="submit" name="action" value="calculate" class="bg-gray-600 text-white px-4 py-2 rounded shadow hover:bg-gray-700 font-bold">Calculate Scope</button>
                <?php if ($action === 'calculate' || $action === 'map'): ?>
                    <button type="submit" name="action" value="map" class="bg-indigo-600 text-white px-4 py-2 rounded shadow hover:bg-indigo-700 font-bold">Start Mapping</button>
                <?php endif; ?>
            </div>
        </form>

        <?php
        if ($action === 'calculate') {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM prs $join $where");
            $countStmt->execute($params);
            $totalRows = $countStmt->fetchColumn();

            // Fetch Master Data dictionary size to estimate prompt size
            $mdStmt = $pdo->query("SELECT COUNT(*) FROM master_data WHERE level_4 IS NOT NULL AND level_4 != ''");
            $dictSize = $mdStmt->fetchColumn();
            
            // Rough estimation: Dictionary (5 tokens per item) + Batches of 25 PRs (10 tokens per PR).
            $estimatedTokensPerBatch = ($dictSize * 5) + (25 * 10);
            $totalBatches = ceil($totalRows / 25);
            $totalTokens = $estimatedTokensPerBatch * $totalBatches;
            $costPer1M = 0.075; // USD
            $estimatedCostUsd = ($totalTokens / 1000000) * $costPer1M;
            $estimatedCostIdr = $estimatedCostUsd * 16200;

            echo "<div class='bg-blue-50 border-l-4 border-blue-500 p-4 mb-4'>";
            echo "<h3 class='text-lg font-bold text-blue-800'>Calculation Results</h3>";
            echo "<p class='text-blue-700'>Rows matching filters: <strong>" . number_format($totalRows) . "</strong></p>";
            echo "<p class='text-blue-700'>Total Batches (25/batch): <strong>" . number_format($totalBatches) . "</strong></p>";
            echo "<p class='text-blue-700'>Estimated Total Tokens: <strong>" . number_format($totalTokens) . "</strong></p>";
            echo "<p class='text-blue-700'>Estimated Total Cost: <strong>IDR " . number_format($estimatedCostIdr, 2) . "</strong></p>";
            echo "</div>";
            
            if ($totalRows == 0) {
                echo "<p class='text-green-600 font-bold'>No rows left to map for these filters!</p>";
            }

        } elseif ($action === 'map') {
            $limit = 25;
            $prStmt = $pdo->prepare("SELECT prs.ticket_no, prs.ticket_line_item, prs.item_description FROM prs $join $where LIMIT $limit");
            $prStmt->execute($params);
            $unmappedPrs = $prStmt->fetchAll(PDO::FETCH_ASSOC);

            if (count($unmappedPrs) === 0) {
                echo "<div class='bg-green-100 border-l-4 border-green-500 p-4 text-green-700 font-bold'>All done! No unmapped PRs left for the selected filters.</div>";
            } else {
                echo "<div class='bg-yellow-50 border-l-4 border-yellow-500 p-4 mb-4'>";
                echo "<h3 class='text-lg font-bold text-yellow-800'>Processing Batch...</h3>";
                
                $mdStmt = $pdo->query("SELECT id, level_4 FROM master_data WHERE level_4 IS NOT NULL AND level_4 != ''");
                $masterData = $mdStmt->fetchAll(PDO::FETCH_ASSOC);

                $prompt = "You are a data mapping assistant. Map the following unmapped Purchase Requisition (PR) items to the closest Master Data SKU. 
If an item doesn't closely match any SKU, return null for that item.
Return ONLY a valid JSON array of objects with keys: 'ticket_no', 'ticket_line_item', 'master_data_id'.

Master Data Dictionary (ID -> SKU Name):\n";
                foreach ($masterData as $md) {
                    $prompt .= $md['id'] . " -> " . $md['level_4'] . "\n";
                }
                $prompt .= "\nUnmapped PRs (Ticket/Line -> Description):\n";
                foreach ($unmappedPrs as $pr) {
                    $prompt .= $pr['ticket_no'] . "/" . $pr['ticket_line_item'] . " -> " . $pr['item_description'] . "\n";
                }

                $estimatedTokens = strlen($prompt) / 4;
                $costPer1M = 0.075;
                $estimatedCostIdr = (($estimatedTokens / 1000000) * $costPer1M) * 16200;

                echo "<p class='text-sm text-yellow-700'>Sent <strong>" . count($unmappedPrs) . "</strong> PRs to Gemini. Estimated batch cost: IDR " . number_format($estimatedCostIdr, 2) . "</p>";

                try {
                    $ai_response = call_gemini_api($prompt, 3, gemini_key_admin_misc());

                    // Re-establish PDO connection to prevent "MySQL server has gone away" 
                    // after a potentially long Gemini API response delay
                    try {
                        $pdo = new PDO($dsn, $user, $pass, $options);
                    } catch (Exception $ex) {
                        // If reconnect fails, rely on existing connection
                    }

                    $clean_json = preg_replace('/```json\s*/i', '', $ai_response);
                    $clean_json = preg_replace('/```\s*/', '', $clean_json);
                    $clean_json = trim($clean_json);
                    
                    $mappings = json_decode($clean_json, true);
                    if (!$mappings || !is_array($mappings)) {
                        throw new Exception("Invalid JSON returned by Gemini: " . htmlspecialchars($clean_json));
                    }
                    
                    $updateStmt = $pdo->prepare("UPDATE prs SET master_data_id = ? WHERE ticket_no = ? AND ticket_line_item = ?");
                    $updatedCount = 0;
                    foreach ($mappings as $map) {
                        if (!empty($map['master_data_id'])) {
                            $updateStmt->execute([$map['master_data_id'], $map['ticket_no'], $map['ticket_line_item']]);
                            $updatedCount++;
                        }
                    }
                    
                    echo "<p class='text-green-700 mt-2'>Gemini returned " . count($mappings) . " results. Successfully updated <strong>$updatedCount</strong> PRs.</p>";
                    
                    // Trigger auto-refresh for the next batch
                    echo "<p class='text-indigo-600 font-bold mt-2 animate-pulse'>Refreshing in 3 seconds to process next batch...</p>";
                    echo "<script>setTimeout(function() { document.getElementById('mapForm').submit(); }, 3000);</script>";

                } catch (Exception $e) {
                    echo "<p class='text-red-600 font-bold mt-2'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
                    echo "<p class='text-red-600 text-sm'>Stopping batch process. Please resolve the error and press Start Mapping again.</p>";
                }
                echo "</div>";
            }
        }
        ?>
    </div>
</body>
</html>
