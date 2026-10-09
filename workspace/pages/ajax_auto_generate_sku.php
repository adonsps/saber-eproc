<?php
// pages/ajax_auto_generate_sku.php
require_once '../includes/auth.php';
// Write endpoint reached only from Master Data. It carried no session
// check at all, so the read-only Viewer role (and any anonymous caller)
// could reach it; ws_require_write() closes both.
ws_require_write();
require_once '../config/database.php';
// Golden-rule name builders: md_gr_nma_instruction() shapes the prompt below and
// md_sanitize_hybrid_name() enforces the format on the way back.
require_once '../includes/master_data_enrichment.php';
require_once '../includes/master_data_audit.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$id = (int)$_POST['id'];
$role = $_SESSION['role'] ?? '';

try {
    // 1. Fetch the suggestion
    $stmt = $pdo->prepare("SELECT requested_item_name, suggested_by FROM master_data WHERE id = ?");
    $stmt->execute([$id]);
    $suggestion = $stmt->fetch();

    if (!$suggestion || empty($suggestion['requested_item_name'])) {
        echo json_encode(['status' => 'error', 'message' => 'No item name to process']);
        exit;
    }

    $item_desc = $suggestion['requested_item_name'];

    // 2. Fetch Golden Rules
    $golden_rules = "";
    $grStmt = $pdo->query("SELECT rule_text FROM ai_golden_rules LIMIT 1");
    $rule_text = $grStmt->fetchColumn();
    if ($rule_text) {
        $golden_rules = "GOLDEN RULES:\n" . $rule_text . "\n\n";
    }

    // 3. Fetch Master Data Taxonomy
    $stmtTaxonomy = $pdo->query("SELECT level_0, level_1, level_2, level_3, level_4 FROM master_data WHERE status IN ('active', 'registered') OR status IS NULL");
    $master_data = $stmtTaxonomy->fetchAll(PDO::FETCH_ASSOC);

    $categoriesList = [];
    $taxonomy_tree = [];
    foreach ($master_data as $row) {
        $l4 = trim($row['level_4']);
        if (!empty($l4) && strpos($l4, 'pending_') !== 0) {
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

    // 4. Build the Gemini Prompt
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

    $ai_text = call_gemini_api($prompt, 3, gemini_key_sku());
    
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
            // exact match
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
        
        // Forced onto GOLDEN RULE 3's "level_4 core - attributes" shape; the AI
        // picks the split, the house standard decides the format.
        $l5 = md_sanitize_hybrid_name($parsed['level_5'] ?? '', $best_l4_match);
        $pic_names = $suggestion['suggested_by'] ?? '';

        // Save to Database. The taxonomy written here is the AI's, so the audit
        // columns say 'ai' rather than the operator who pressed the button.
        $before = md_audit_snapshot($pdo, [$id]);
        $updateStmt = $pdo->prepare("UPDATE master_data SET level_0=?, level_1=?, level_2=?, level_3=?, level_4=?, level_5=?, pic_names=?, status='registered', last_modified_by=? WHERE id=?");
        $updateStmt->execute([$l0, $l1, $l2, $l3, $best_l4_match, $l5, $pic_names, MD_AUDIT_ACTOR_AI, $id]);
        md_audit_log_update($pdo, $before, MD_AUDIT_ACTOR_AI);

        echo json_encode([
            'status' => 'success',
            'confidence' => $parsed['confidence'],
            'taxonomy' => [
                'level_0' => $l0,
                'level_1' => $l1,
                'level_2' => $l2,
                'level_3' => $l3,
                'level_4' => $best_l4_match,
                'level_5' => $l5
            ]
        ]);
        exit;
    }
    
    echo json_encode(['status' => 'error', 'message' => 'Failed to parse JSON response from AI']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
