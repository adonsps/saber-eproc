<?php
// pages/ajax_ai_map_sku_dictionary.php
require_once '../includes/auth.php';
require_manager();
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sku_ids'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$sku_ids = json_decode($_POST['sku_ids'], true);
if (!is_array($sku_ids) || empty($sku_ids)) {
    echo json_encode(['status' => 'error', 'message' => 'No SKUs selected']);
    exit;
}

$role = $_SESSION['role'] ?? '';
if ($role !== 'Category Manager') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// Fetch all selected SKUs
$inQuery = implode(',', array_fill(0, count($sku_ids), '?'));
$stmt = $pdo->prepare("SELECT id, sku, detail FROM sku_dictionary WHERE id IN ($inQuery)");
$stmt->execute($sku_ids);
$skus_to_process = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($skus_to_process)) {
    echo json_encode(['status' => 'error', 'message' => 'SKUs not found']);
    exit;
}

// Fetch Master Data Taxonomy. pic_names comes along so a Master Data enrichment
// row can propose the closest category's PIC -- see md_enrich_master_data().
$stmtTaxonomy = $pdo->query("SELECT DISTINCT level_0, level_1, level_2, level_3, level_4, pic_names FROM master_data WHERE status IN ('active', 'registered') OR status IS NULL");
$master_data = $stmtTaxonomy->fetchAll(PDO::FETCH_ASSOC);

$taxonomy_tree = [];
$categoriesList = [];
foreach ($master_data as $row) {
    $l4 = trim((string)$row['level_4']);
    if (!empty($l4)) {
        $categoriesList[] = $l4;
        $lower_l4 = strtolower($l4);
        if (!isset($taxonomy_tree[$lower_l4]) || empty(trim((string)$taxonomy_tree[$lower_l4]['level_0']))) {
            $taxonomy_tree[$lower_l4] = [
                'level_0' => $row['level_0'],
                'level_1' => $row['level_1'],
                'level_2' => $row['level_2'],
                'level_3' => $row['level_3'],
                'level_4' => $row['level_4'], // Keep original case
                'pic'     => $row['pic_names']
            ];
        }
    }
}
$categoriesList = array_unique($categoriesList);
$categoriesString = implode(", ", $categoriesList);

require_once '../config/gemini_api.php';
require_once '../includes/master_data_enrichment.php';

// The house naming standard (ai_golden_rules) is what the AI has to follow when
// it proposes a NEW category below, so it goes into the prompt here the same way
// the ticket-import batch (ajax_process_batch.php) already sends it.
$golden_rules = "";
try {
    $rules = $pdo->query("SELECT rule_text FROM ai_golden_rules ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($rules)) {
        $golden_rules = "GOLDEN RULES:\n" . implode("\n", $rules) . "\n\n";
    }
} catch (PDOException $e) {
    // Mapping still works without them; only the proposed names get less
    // house-standard, and md_standardize_*() sanitizes those anyway.
    error_log('SKU dictionary mapping: golden rules unavailable - ' . $e->getMessage());
}

$results = [];
$success_count = 0;
$enriched_count = 0;

$updateStmt = $pdo->prepare("UPDATE sku_dictionary SET level_0 = ?, level_1 = ?, level_2 = ?, level_3 = ?, level_4 = ?, mapping_status = ? WHERE id = ?");

foreach ($skus_to_process as $sku_row) {
    $item_desc = trim((string)$sku_row['detail']);
    $sku_name = trim((string)$sku_row['sku']);
    
    // Fallback if detail is completely empty
    if (empty($item_desc)) {
        $item_desc = $sku_name;
    }
    
    $min_match = md_enrichment_min_match();
    $prompt = "You are an expert procurement taxonomy classifier.\n";
    $prompt .= $golden_rules;
    $prompt .= "AVAILABLE EXACT CATEGORIES (Level 4):\n" . $categoriesString . "\n\n";
    $prompt .= "Task: Map the item description and SKU below to the single most accurate category from the EXACT CATEGORIES list.\n";
    $prompt .= "SKU Name: \"{$sku_name}\"\n";
    $prompt .= "Item Description: \"{$item_desc}\"\n\n";
    $prompt .= "CRITICAL RULES:\n";
    $prompt .= "1. Output ONLY a valid JSON object. Do not add markdown or comments.\n";
    $prompt .= "2. The 'level_4' MUST be an exact match from the AVAILABLE EXACT CATEGORIES list. If no exact match, pick the closest one.\n";
    // Same NEW CATEGORY contract as the ticket-import batch: a poor fit still
    // gets mapped to its nearest neighbour so the dictionary row is usable now,
    // but the item's real category is proposed alongside it and becomes a
    // pending Master Data row rather than being absorbed by that neighbour.
    $prompt .= "3. NEW CATEGORY: when this item does not genuinely belong to ANY category in the list, keep the closest one in 'level_4' with a confidence below {$min_match}, and additionally return:\n";
    $prompt .= "   - 'new_level_4': the category this item should have. PRODUCT TYPE ONLY (GOLDEN RULE 3) - no brands, models, sizes, capacities, colors or specs. Prefix \"JASA \" for services (GOLDEN RULE III). UPPERCASE.\n";
    $prompt .= "   - 'new_level_5': the SKU detail description, Scenario A - the new_level_4 noun and its modifiers MERGED into the first segment, then each critical technical attribute as its own segment, separated by \"; \". ALL CAPITALS. No commas, no dashes, no parentheses (GOLDEN RULE 2 and 3). Example: NOTEBOOK DESIGNER; 14.5 INCH; RAM 32GB; SSD 512GB\n";
    $prompt .= "   " . md_gr_nma_instruction();
    $prompt .= "   Leave BOTH as empty strings when the item does belong to a category in the list.\n";
    $prompt .= "4. Format:\n";
    $prompt .= "{\n";
    $prompt .= "  \"level_4\": \"Matched Category from list\",\n";
    $prompt .= "  \"confidence\": 85,\n";
    $prompt .= "  \"new_level_4\": \"\",\n";
    $prompt .= "  \"new_level_5\": \"\"\n";
    $prompt .= "}\n";

    try {
        $ai_text = call_gemini_api($prompt, 3, gemini_key_sku());
        
        $ai_text = preg_replace('/```json/i', '', $ai_text);
        $ai_text = preg_replace('/```/i', '', $ai_text);
        $ai_text = trim($ai_text);

        $parsed = json_decode($ai_text, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($parsed['confidence'])) {
            $l4_guess = $parsed['level_4'] ?? '';
            $confidence = (int)$parsed['confidence'];
            $search_key = strtolower(trim($l4_guess));
            
            $best_match_key = '';
            $highest_sim = 0;
            // How well the AI's answer resolves onto a category that really
            // exists in Master Data -- 100 when it named one, otherwise the
            // fuzzy-match percentage. Feeds the enrichment test below.
            $resolution_similarity = 0;

            if (isset($taxonomy_tree[$search_key])) {
                $best_match_key = $search_key;
                $resolution_similarity = 100;
            } else {
                foreach ($taxonomy_tree as $l4_key => $tree) {
                    similar_text($search_key, $l4_key, $percent);
                    if ($percent > $highest_sim) {
                        $highest_sim = $percent;
                        $best_match_key = $l4_key;
                    }
                }
                $resolution_similarity = $highest_sim;
            }

            if (!empty($best_match_key) && isset($taxonomy_tree[$best_match_key])) {
                $tree = $taxonomy_tree[$best_match_key];

                $status = ($confidence > 90) ? 'AI Mapped' : 'Need Review';

                $updateStmt->execute([
                    $tree['level_0'],
                    $tree['level_1'],
                    $tree['level_2'],
                    $tree['level_3'],
                    $tree['level_4'],
                    $status,
                    $sku_row['id']
                ]);
                $success_count++;

                // --- MASTER DATA ENRICHMENT ------------------------------------
                // The dictionary row now points at the closest Master Data
                // category, but a weak match means this SKU is not actually in
                // Master Data yet. Write it back as a pending row so the knowledge
                // base gains the item instead of just absorbing it into a
                // neighbour -- the new row carries its OWN level_4 (the AI's
                // proposal, or one derived from the description), inheriting only
                // level_0..level_3 from $tree. Not date-gated: this runs only on
                // rows a Category Manager explicitly selected.
                $enriched_id = null;
                if (md_should_enrich($confidence, $resolution_similarity)) {
                    $enriched_id = md_enrich_master_data(
                        $pdo,
                        $tree,
                        $item_desc,
                        (int)md_match_score($confidence, $resolution_similarity),
                        $tree['pic'] ?? null,
                        'System',
                        [
                            'level_4' => trim((string)($parsed['new_level_4'] ?? '')),
                            'level_5' => trim((string)($parsed['new_level_5'] ?? '')),
                        ]
                    );
                    if ($enriched_id !== null) {
                        $enriched_count++;
                    }
                }

                $results[] = [
                    'id' => $sku_row['id'],
                    'status' => 'success',
                    'mapping_status' => $status,
                    'enriched_master_data' => $enriched_id !== null
                ];
            } else {
                $results[] = ['id' => $sku_row['id'], 'status' => 'error', 'message' => 'Could not map to any valid taxonomy'];
            }
        } else {
             $results[] = ['id' => $sku_row['id'], 'status' => 'error', 'message' => 'Invalid AI JSON format'];
        }
    } catch (Exception $e) {
        $results[] = ['id' => $sku_row['id'], 'status' => 'error', 'message' => $e->getMessage()];
    }
}

$message = "Successfully processed $success_count out of " . count($skus_to_process) . " SKUs.";
if ($enriched_count > 0) {
    $message .= " $enriched_count item(s) matched Master Data below "
        . md_enrichment_min_match() . "% and were added there for review.";
}

echo json_encode([
    'status' => 'success',
    'message' => $message,
    'enriched_count' => $enriched_count,
    'results' => $results
]);
