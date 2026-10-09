<?php
// pages/ajax_ai_golden_rule_update.php
require_once '../includes/auth.php';
require_manager(); // Only managers can hit this, ideally Category Manager
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$id = $_POST['id'];
$role = $_SESSION['role'] ?? '';

if ($role !== 'Category Manager') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

try {
    // Fetch the approved suggestion data
    $stmt = $pdo->prepare("SELECT requested_item_name, level_4, level_5 FROM master_data WHERE id = ? AND status IN ('active', 'approved_new', 'registered')");
    $stmt->execute([$id]);
    $suggestion = $stmt->fetch();

    if (!$suggestion || empty($suggestion['requested_item_name'])) {
        echo json_encode(['status' => 'skipped', 'message' => 'No relevant data to analyze']);
        exit;
    }

    $requested_item = $suggestion['requested_item_name'];
    $approved_category = $suggestion['level_4'];
    $approved_sku = $suggestion['level_5'];

    // Fetch Golden Rules
    $grStmt = $pdo->query("SELECT rule_text FROM ai_golden_rules LIMIT 1");
    $current_rule_text = $grStmt->fetchColumn() ?: "";

    // Build the Gemini Prompt
    $prompt = "You are an expert AI system administrator maintaining naming conventions (Golden Rules) for a procurement system.\n";
    $prompt .= "The user (Category Manager) has just approved a new SKU request with the following mapping:\n";
    $prompt .= "- Original Requested Item: \"{$requested_item}\"\n";
    $prompt .= "- Approved Category (L4): \"{$approved_category}\"\n";
    $prompt .= "- Approved Standardized SKU (L5): \"{$approved_sku}\"\n\n";
    $prompt .= "CURRENT GOLDEN RULES:\n" . ($current_rule_text ? $current_rule_text : "(None)") . "\n\n";
    $prompt .= "Task: Determine if the CURRENT GOLDEN RULES need to be updated to adapt to how the Category Manager mapped this item.\n";
    $prompt .= "If the mapping is already well covered by the current rules, or is a standard transformation, NO update is needed.\n";
    $prompt .= "If the Category Manager used a new naming convention, a specific abbreviation, or a novel categorization logic that isn't covered, output an updated version of the golden rules that incorporates this new pattern.\n";
    $prompt .= "CRITICAL RULES:\n";
    $prompt .= "1. Output ONLY a valid JSON object. Do not add markdown or comments.\n";
    $prompt .= "2. Format:\n";
    $prompt .= "{\n";
    $prompt .= "  \"needs_update\": true/false,\n";
    $prompt .= "  \"new_golden_rules\": \"The completely updated text for the golden rules (if needs_update is true). Keep existing rules but append/modify to incorporate the new logic.\"\n";
    $prompt .= "}\n";

    require_once '../config/gemini_api.php';

    $ai_text = call_gemini_api($prompt, 3, gemini_key_po_taxonomy());
    
    // Clean markdown if present
    $ai_text = preg_replace('/```json/i', '', $ai_text);
    $ai_text = preg_replace('/```/i', '', $ai_text);
    $ai_text = trim($ai_text);

    $parsed = json_decode($ai_text, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($parsed['needs_update'])) {
        
        if ($parsed['needs_update'] && !empty($parsed['new_golden_rules'])) {
            $new_rules = trim($parsed['new_golden_rules']);
            // DELETE inside a transaction, never TRUNCATE. TRUNCATE is DDL: it
            // commits implicitly and cannot be rolled back, so a failing INSERT
            // after it destroys the golden rules outright -- which is what
            // happened on 2026-08-05 via the sibling save path in
            // pages/master_data.php. Here the loss would be worse still, since
            // nothing on screen would say the rules had gone.
            try {
                $pdo->beginTransaction();
                $pdo->exec("DELETE FROM ai_golden_rules");
                $insertStmt = $pdo->prepare("INSERT INTO ai_golden_rules (rule_text) VALUES (?)");
                $insertStmt->execute([$new_rules]);
                $pdo->commit();
                echo json_encode(['status' => 'updated', 'message' => 'Golden rules updated via AI']);
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('AI golden rules update failed, previous text kept: ' . $e->getMessage());
                echo json_encode(['status' => 'error', 'message' => 'Could not save the updated rules; the previous ones are unchanged.']);
            }
        } else {
            echo json_encode(['status' => 'unchanged', 'message' => 'No update needed']);
        }
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to parse JSON response from AI']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
