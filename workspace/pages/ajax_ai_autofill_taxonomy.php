<?php
// pages/ajax_ai_autofill_taxonomy.php
require_once '../includes/auth.php';
require_manager(); // Only managers can hit this, ideally Category Manager
require_once '../config/database.php';
// Golden-rule name builders: md_gr_nma_instruction() shapes the prompt below and
// md_sanitize_hybrid_name() enforces the format on the way back.
require_once '../includes/master_data_enrichment.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['item_desc'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$item_desc = trim($_POST['item_desc']);
$role = $_SESSION['role'] ?? '';

if ($role !== 'Category Manager') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

// 1. Fetch Golden Rules
$golden_rules = "";
$grStmt = $pdo->query("SELECT rule_text FROM ai_golden_rules LIMIT 1");
$rule_text = $grStmt->fetchColumn();
if ($rule_text) {
    $golden_rules = "GOLDEN RULES:\n" . $rule_text . "\n\n";
}

// 1.5 Fetch Master Data Taxonomy
$stmtTaxonomy = $pdo->query("SELECT level_0, level_1, level_2, level_3, level_4 FROM master_data WHERE status IN ('active', 'registered') OR status IS NULL");
$master_data = $stmtTaxonomy->fetchAll(PDO::FETCH_ASSOC);

$categoriesList = [];
$taxonomy_tree = [];
foreach ($master_data as $row) {
    $l4 = trim($row['level_4']);
    if (!empty($l4)) {
        $categoriesList[] = $l4;
        $lower_l4 = strtolower($l4);
        if (!isset($taxonomy_tree[$lower_l4]) || empty(trim((string)$taxonomy_tree[$lower_l4]['level_0']))) {
            $taxonomy_tree[$lower_l4] = [
                'level_0' => $row['level_0'],
                'level_1' => $row['level_1'],
                'level_2' => $row['level_2'],
                'level_3' => $row['level_3']
            ];
        }
    }
}
$categoriesList = array_unique($categoriesList);

// 2. Build the Gemini Prompt
$prompt = "You are an expert procurement taxonomy classifier.\n";
$prompt .= $golden_rules;
$prompt .= "AVAILABLE EXACT CATEGORIES (Level 4):\n" . implode(", ", $categoriesList) . "\n\n";
$prompt .= "Task: Map the item description below to the single most accurate category from the EXACT CATEGORIES list. Then, generate a clean, standardized SKU name based on the item description.\n";
$prompt .= "Item Description: \"{$item_desc}\"\n\n";
$prompt .= "CRITICAL RULES:\n";
$prompt .= "1. Output ONLY a valid JSON object. Do not add markdown or comments.\n";
$prompt .= "2. The 'level_4' MUST be an exact match from the AVAILABLE EXACT CATEGORIES list. If no exact match, pick the closest one.\n";
$prompt .= "3. " . md_gr_nma_instruction();
$prompt .= "4. Format:\n";
$prompt .= "{\n";
$prompt .= "  \"level_4\": \"Matched Category from list\",\n";
$prompt .= "  \"level_5\": \"SKU detail description, Scenario A: the level_4 noun and its modifiers MERGED into the first segment, then each critical technical attribute as its own segment, separated by '; '. ALL CAPITALS. No commas, no dashes, no parentheses (GOLDEN RULE 2 and 3). Example: NOTEBOOK DESIGNER; 14.5 INCH; RAM 32GB; SSD 512GB\",\n";
$prompt .= "  \"confidence\": 85\n";
$prompt .= "}\n";

require_once '../config/gemini_api.php';

try {
    $ai_text = call_gemini_api($prompt, 3, gemini_key_po_taxonomy());
    
    // Clean markdown if present
    $ai_text = preg_replace('/```json/i', '', $ai_text);
    $ai_text = preg_replace('/```/i', '', $ai_text);
    $ai_text = trim($ai_text);

    $parsed = json_decode($ai_text, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($parsed['confidence'])) {
        
        $l4_guess = $parsed['level_4'] ?? '';
        $search_key = strtolower(trim($l4_guess));
        
        // Find best L4 match locally
        $best_l4_match = $l4_guess;
        if (!isset($taxonomy_tree[$search_key])) {
            $highest_sim = 0;
            $best_match = '';
            foreach ($taxonomy_tree as $l4_key => $tree) {
                similar_text($search_key, $l4_key, $percent);
                if ($percent > $highest_sim) {
                    $highest_sim = $percent;
                    $best_match = $l4_key;
                }
            }
            if ($highest_sim >= 70) {
                foreach ($categoriesList as $orig_l4) {
                    if (strtolower($orig_l4) === $best_match) {
                        $best_l4_match = $orig_l4;
                        break;
                    }
                }
                $search_key = $best_match;
            }
        } else {
            // we have an exact lowercase match, let's try to restore its original case
            foreach ($categoriesList as $orig_l4) {
                if (strtolower($orig_l4) === $search_key) {
                    $best_l4_match = $orig_l4;
                    break;
                }
            }
        }
        
        // Resolve L0-L3
        $l0 = ''; $l1 = ''; $l2 = ''; $l3 = '';
        if (isset($taxonomy_tree[$search_key])) {
            $tree = $taxonomy_tree[$search_key];
            $l0 = $tree['level_0'];
            $l1 = $tree['level_1'];
            $l2 = $tree['level_2'];
            $l3 = $tree['level_3'];
        }

        echo json_encode([
            'status' => 'success',
            'confidence' => $parsed['confidence'],
            'taxonomy' => [
                'level_0' => $l0,
                'level_1' => $l1,
                'level_2' => $l2,
                'level_3' => $l3,
                'level_4' => $best_l4_match,
                // The AI is trusted to pick the core/attribute split, not to keep
                // to the format: the name is forced back onto GOLDEN RULE 3's
                // "level_4 core - attributes" before it reaches the form.
                'level_5' => md_sanitize_hybrid_name($parsed['level_5'] ?? '', $best_l4_match)
            ]
        ]);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Failed to parse JSON response from AI']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
