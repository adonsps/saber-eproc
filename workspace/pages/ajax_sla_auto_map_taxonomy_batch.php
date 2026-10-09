<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../config/gemini_api.php';
header('Content-Type: application/json');

// Write endpoint (persists taxonomy AND spends Gemini quota): Viewer refused.
ws_require_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$itemsJson = isset($_POST['items']) ? $_POST['items'] : '';
$items = json_decode($itemsJson, true);

if (!is_array($items) || count($items) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No items provided for mapping.']);
    exit;
}

try {
    // 1. Fetch item descriptions for the selected items
    $unmappedPrs = [];
    $stmt = $pdo->prepare("SELECT ticket_no, ticket_line_item, item_description FROM prs WHERE ticket_no = ? AND ticket_line_item = ?");
    foreach ($items as $item) {
        $stmt->execute([$item['ticket_no'], $item['ticket_line_item']]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $unmappedPrs[] = $row;
        }
    }

    if (count($unmappedPrs) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Failed to retrieve item descriptions.']);
        exit;
    }

    // 2. Fetch Master Data dictionary
    $mdStmt = $pdo->query("SELECT id, level_4 FROM master_data WHERE level_4 IS NOT NULL AND level_4 != '' AND status IN ('active', 'registered')");
    $masterData = $mdStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2.1 Fetch Knowledge Master Taxonomy
    $taxStmt = $pdo->query("SELECT DISTINCT `Tower` as level_0, `Category` as level_1, `Sub Category` as level_2, `Commodity` as level_3 FROM item_taxonomy_db_view WHERE `Commodity` IS NOT NULL AND `Commodity` != ''");
    $knowledgeTaxonomy = $taxStmt->fetchAll(PDO::FETCH_ASSOC);

    // 2.2 Fetch Golden Rules
    $golden_rules = "";
    $grStmt = $pdo->query("SELECT rule_text FROM ai_golden_rules LIMIT 1");
    $rule_text = $grStmt->fetchColumn();
    if ($rule_text) {
        $golden_rules = "GOLDEN RULES FOR NEW SKU GENERATION:\n" . $rule_text . "\n\n";
    }

    // 3. Build Gemini Prompt
    $prompt = "You are a data mapping assistant. Map the following unmapped Purchase Requisition (PR) items to the closest Master Data SKU.\n";
    $prompt .= "If an item closely matches an existing Master Data SKU from the dictionary, return its ID in 'master_data_id' and set 'mode' to 'existing'.\n";
    $prompt .= "If an item DOES NOT closely match any existing SKU, you MUST suggest a new Master Data row:\n";
    $prompt .= "  a) Select the most appropriate Level 0, Level 1, Level 2, and Level 3 from the provided Knowledge Master Taxonomy.\n";
    $prompt .= "  b) Generate a suggested Level 4 (Category) and Level 5 (SKU Name) following the GOLDEN RULES.\n";
    $prompt .= "  c) Set 'mode' to 'new', 'master_data_id' to null, and provide 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5'.\n\n";
    $prompt .= $golden_rules;
    $prompt .= "Return ONLY a valid JSON array of objects with keys: 'ticket_no', 'ticket_line_item', 'mode', 'master_data_id', 'level_0', 'level_1', 'level_2', 'level_3', 'level_4', 'level_5'.\n\n";
    
    $prompt .= "Knowledge Master Taxonomy (Level 0 -> Level 1 -> Level 2 -> Level 3):\n";
    foreach ($knowledgeTaxonomy as $tax) {
        $prompt .= $tax['level_0'] . " -> " . $tax['level_1'] . " -> " . $tax['level_2'] . " -> " . $tax['level_3'] . "\n";
    }

    $prompt .= "\nMaster Data Dictionary (ID -> SKU Name):\n";
    foreach ($masterData as $md) {
        $prompt .= $md['id'] . " -> " . $md['level_4'] . "\n";
    }
    
    $prompt .= "\nUnmapped PRs (Ticket/Line -> Description):\n";
    foreach ($unmappedPrs as $pr) {
        $prompt .= $pr['ticket_no'] . "/" . $pr['ticket_line_item'] . " -> " . $pr['item_description'] . "\n";
    }

    // Pre-fetch PICs before API call to avoid MySQL timeout if API is slow
    $picStmt = $pdo->query("SELECT name FROM users WHERE role IN ('PIC', 'Admin') ORDER BY name ASC");
    $picList = $picStmt->fetchAll(PDO::FETCH_COLUMN);

    // 4. Call Gemini API
    $textResponse = call_gemini_api($prompt, 3, gemini_key_po_taxonomy());
    
    // Clean markdown if present
    $textResponse = preg_replace('/```json/i', '', $textResponse);
    $textResponse = preg_replace('/```/i', '', $textResponse);
    $textResponse = trim($textResponse);

    $mappedItems = json_decode($textResponse, true);
    
    if (is_array($mappedItems)) {
        // Build a lookup map of id -> sku name for frontend
        $mdMap = [];
        foreach ($masterData as $md) {
            $mdMap[$md['id']] = $md['level_4'];
        }

        // Augment AI mapped items with original description and suggested SKU name
        $previewResults = [];
        foreach ($mappedItems as $mapping) {
            $ticketNo = $mapping['ticket_no'] ?? '';
            $lineItem = $mapping['ticket_line_item'] ?? '';
            $mode = $mapping['mode'] ?? 'existing';
            $mdId = $mapping['master_data_id'] ?? null;
            
            // Find original description
            $desc = '';
            foreach ($unmappedPrs as $pr) {
                if ($pr['ticket_no'] == $ticketNo && $pr['ticket_line_item'] == $lineItem) {
                    $desc = $pr['item_description'];
                    break;
                }
            }

            if ($ticketNo && $lineItem) {
                $previewResults[] = [
                    'ticket_no' => $ticketNo,
                    'ticket_line_item' => $lineItem,
                    'item_description' => $desc,
                    'mode' => $mode,
                    'master_data_id' => $mdId,
                    'sku_name' => $mdId && isset($mdMap[$mdId]) ? $mdMap[$mdId] : null,
                    'level_0' => $mapping['level_0'] ?? '',
                    'level_1' => $mapping['level_1'] ?? '',
                    'level_2' => $mapping['level_2'] ?? '',
                    'level_3' => $mapping['level_3'] ?? '',
                    'level_4' => $mapping['level_4'] ?? '',
                    'level_5' => $mapping['level_5'] ?? ''
                ];
            }
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'preview' => $previewResults,
                'master_data_list' => $masterData,
                'pic_list' => $picList
            ]
        ]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'AI returned invalid JSON format.']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
