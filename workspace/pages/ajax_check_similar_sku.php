<?php
// pages/ajax_check_similar_sku.php
require_once '../includes/auth.php';
require_login(); // Any user can suggest SKUs, so require_login is sufficient
require_once '../config/database.php';
require_once '../config/gemini_api.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['item_name'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$item_name = trim($_POST['item_name']);
$item_type = $_POST['item_type'] ?? 'Goods';

// 1. Fetch potential matches from sku_dictionary
// We split the item name into words (min 3 chars) and do a basic OR search
$words = preg_split('/[\s,\-]+/', $item_name);
$valid_words = [];
foreach ($words as $w) {
    if (strlen($w) > 2) {
        $valid_words[] = $w;
    }
}

// We don't early exit here anymore because we need AI to validate item_type even if no matches found.
if (!empty($valid_words)) {
    $conditions = [];
    $params = [];
    foreach ($valid_words as $w) {
        $conditions[] = "(detail LIKE ? OR sku LIKE ?)";
        $params[] = "%{$w}%";
        $params[] = "%{$w}%";
    }
    
    // Fetch top 50 matches to avoid overwhelming AI
    $query = "SELECT id, sku, detail FROM sku_dictionary WHERE " . implode(' OR ', $conditions) . " LIMIT 50";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $candidates = [];
}

// Removed early exit for empty candidates

// 2. Build Prompt for Gemini
$candidates_text = "";
foreach ($candidates as $c) {
    $candidates_text .= "- ID: {$c['id']}, SKU: {$c['sku']}, Detail: {$c['detail']}\n";
}

$prompt = "You are an AI tasked with validating SKU entries in a database.\n";
$prompt .= "A user wants to create a new SKU named: \"{$item_name}\"\n";
$prompt .= "The user has classified this item as: \"{$item_type}\"\n\n";

if (!empty($candidates)) {
    $prompt .= "Here are potential existing matches from the database:\n";
    $prompt .= $candidates_text . "\n";
} else {
    $prompt .= "No existing matches found in the database.\n";
}

$prompt .= "Task 1 (Similarity): Determine if any of the existing items are highly similar (>85% similarity) to the proposed new item.\n";
$prompt .= "Look deeply at the context and technical specifications. Even if the wording differs slightly, if they represent fundamentally the same class of product or specs, consider them a match.\n\n";

$prompt .= "Task 2 (Item Type Validation): Evaluate the item name based on the user's classification. Is it a Service (Jasa)? If the user selected 'Goods' (or Barang) but the item heavily sounds like a Service (e.g., Repair, Maintenance, Installation, Rental, Subscription, Consulting), flag it as 'suspicious_jasa'.\n\n";

$prompt .= "CRITICAL RULES:\n";
$prompt .= "1. Output ONLY a valid JSON object. Do not add markdown or comments.\n";
$prompt .= "2. Format:\n";
$prompt .= "{\n";
$prompt .= "  \"has_similar\": true/false,\n";
$prompt .= "  \"similar_items\": [\n";
$prompt .= "     {\"id\": 123, \"sku\": \"SKU-NAME\", \"detail\": \"Item Detail\", \"similarity_score\": 98, \"reason\": \"Exact same item\"}\n";
$prompt .= "  ],\n";
$prompt .= "  \"suspicious_jasa\": true/false,\n";
$prompt .= "  \"suspicious_reason\": \"Brief explanation of why this should be a service rather than goods (only if true)\"\n";
$prompt .= "}\n";

try {
    $ai_response = call_gemini_api($prompt, 3, gemini_key_sku());
    
    // Clean JSON response
    $ai_response = preg_replace('/```json/i', '', $ai_response);
    $ai_response = preg_replace('/```/i', '', $ai_response);
    $ai_response = trim($ai_response);

    $parsed = json_decode($ai_response, true);
    
    if (json_last_error() === JSON_ERROR_NONE && isset($parsed['has_similar'])) {
        // Return successful AI response
        echo json_encode($parsed);
    } else {
        // Fallback if AI JSON fails
        echo json_encode(['has_similar' => false, 'similar_items' => [], 'error' => 'AI parse failed']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
