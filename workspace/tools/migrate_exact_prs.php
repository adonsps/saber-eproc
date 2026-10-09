<?php
// workspace/tools/migrate_exact_prs.php
require_once '../config/database.php';

echo "<h1>Bulk PR Mapping: Exact & Partial Text Match</h1>";

// 1. Fetch all active Master Data items (the "dictionary")
$mdStmt = $pdo->query("SELECT id, level_4 FROM master_data WHERE level_4 IS NOT NULL AND level_4 != ''");
$masterData = $mdStmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Loaded " . count($masterData) . " master data SKUs.</p>";

// 2. Fetch all unmapped PRs that have a PO
$prStmt = $pdo->query("
    SELECT ticket_no, ticket_line_item, item_description 
    FROM prs 
    WHERE (master_data_id IS NULL OR master_data_id = 0) 
      AND po_number IS NOT NULL 
      AND po_number != ''
      AND item_description IS NOT NULL
");
$unmappedPrs = $prStmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($unmappedPrs) . " unmapped PRs with PO numbers.</p>";

if (count($unmappedPrs) === 0) {
    echo "<p><strong>Nothing to map!</strong></p>";
    exit;
}

$updateStmt = $pdo->prepare("UPDATE prs SET master_data_id = ? WHERE ticket_no = ? AND ticket_line_item = ?");

$exactMatches = 0;
$partialMatches = 0;
$failed = 0;

foreach ($unmappedPrs as $pr) {
    $desc = trim(strtolower($pr['item_description']));
    if ($desc === '') continue;

    $matchedId = null;
    $matchType = null;

    // A. Try exact match first
    foreach ($masterData as $md) {
        $sku = trim(strtolower($md['level_4']));
        if ($sku === '') continue;

        if ($desc === $sku) {
            $matchedId = $md['id'];
            $matchType = 'exact';
            break;
        }
    }

    // B. Try partial match if no exact match
    if (!$matchedId) {
        foreach ($masterData as $md) {
            $sku = trim(strtolower($md['level_4']));
            // Only allow partial matches if the SKU is at least 6 characters long to prevent generic matching like "AC"
            if (strlen($sku) < 6) continue;

            // If PR description contains the SKU (e.g. PR is "Laptop Lenovo X1" and SKU is "Laptop Lenovo")
            // Or if SKU contains the PR description (e.g. SKU is "Laptop Lenovo X1" and PR is "Laptop Lenovo")
            if (strpos($desc, $sku) !== false || strpos($sku, $desc) !== false) {
                $matchedId = $md['id'];
                $matchType = 'partial';
                break;
            }
        }
    }

    // C. Perform update
    if ($matchedId) {
        $updateStmt->execute([$matchedId, $pr['ticket_no'], $pr['ticket_line_item']]);
        if ($matchType === 'exact') $exactMatches++;
        else $partialMatches++;
    } else {
        $failed++;
    }
}

echo "<p><strong>Results:</strong></p>";
echo "<ul>";
echo "<li>Exact Matches Found & Updated: <strong>$exactMatches</strong></li>";
echo "<li>Partial Matches Found & Updated: <strong>$partialMatches</strong></li>";
echo "<li>Still Unmapped (Send to Gemini later): <strong>$failed</strong></li>";
echo "</ul>";
echo "<p><a href='migrate_gemini_prs.php' style='padding: 10px 15px; background: #4f46e5; color: white; border-radius: 5px; text-decoration: none;'>Proceed to Gemini Mapping</a></p>";
