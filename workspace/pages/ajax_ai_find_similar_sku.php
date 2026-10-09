<?php
// pages/ajax_ai_find_similar_sku.php
require_once '../includes/auth.php';
// Write endpoint reached only from Master Data. It carried no session
// check at all, so the read-only Viewer role (and any anonymous caller)
// could reach it; ws_require_write() closes both.
ws_require_write();
require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request']);
    exit;
}

$id = (int)$_POST['id'];

try {
    // 1. Fetch the suggestion
    $stmt = $pdo->prepare("SELECT requested_item_name FROM master_data WHERE id = ?");
    $stmt->execute([$id]);
    $suggestion = $stmt->fetch();

    if (!$suggestion || empty($suggestion['requested_item_name'])) {
        echo json_encode(['status' => 'skipped', 'message' => 'No item name to search for']);
        exit;
    }

    $item_name = $suggestion['requested_item_name'];

    // 2. Fetch top existing SKUs to compare (using simple LIKE)
    $words = explode(' ', preg_replace('/[^a-zA-Z0-9]+/', ' ', $item_name));
    $words = array_filter($words, function($w) { return strlen($w) > 2; });
    
    $where_clauses = [];
    $params = [];
    foreach ($words as $w) {
        $where_clauses[] = "(sku LIKE ? OR detail LIKE ?)";
        $params[] = "%$w%";
        $params[] = "%$w%";
    }
    
    $where_sql = count($where_clauses) > 0 ? implode(' OR ', $where_clauses) : '1=1';

    $sql = "SELECT id, sku, detail, group_owner, uom FROM existing_skus WHERE is_active = 1 AND ($where_sql) LIMIT 50";
    $searchStmt = $pdo->prepare($sql);
    $searchStmt->execute($params);
    $candidates = $searchStmt->fetchAll(PDO::FETCH_ASSOC);

    if (count($candidates) === 0) {
        // Still save an empty array so UI knows it finished
        $pdo->prepare("UPDATE master_data SET ai_similar_skus_json = '[]' WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'no_candidates', 'message' => 'No basic keyword matches found']);
        exit;
    }

    // 3. Ask Gemini to evaluate similarity
    $candidates_json = json_encode($candidates);

    $prompt = "You are an expert procurement AI assistant. A user has requested a new SKU with the following name: \"{$item_name}\".\n";
    $prompt .= "Below is a JSON array of existing active SKUs in the database that might match this request:\n";
    $prompt .= $candidates_json . "\n\n";
    $prompt .= "Task: Identify any existing SKUs from the list that have a >90% similarity or confidence of being the exact same item requested.\n";
    $prompt .= "Output ONLY a valid JSON object matching this format:\n";
    $prompt .= "{\n";
    $prompt .= "  \"matches\": [\n";
    $prompt .= "     { \"id\": 123, \"sku\": \"...\", \"detail\": \"...\", \"confidence\": 95, \"reasoning\": \"Brief explanation\" }\n";
    $prompt .= "  ]\n";
    $prompt .= "}\n";
    $prompt .= "If there are no matches >90%, return an empty array for \"matches\". Do not include markdown or comments.";

    require_once '../config/gemini_api.php';

    $ai_text = call_gemini_api($prompt, 3, gemini_key_sku());
    
    // Clean markdown if present
    $ai_text = preg_replace('/```json/i', '', $ai_text);
    $ai_text = preg_replace('/```/i', '', $ai_text);
    $ai_text = trim($ai_text);

    $parsed = json_decode($ai_text, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($parsed['matches'])) {
        $matches = $parsed['matches'];
        
        // Save to master_data
        $updateStmt = $pdo->prepare("UPDATE master_data SET ai_similar_skus_json = ? WHERE id = ?");
        $updateStmt->execute([json_encode($matches), $id]);
        
        echo json_encode(['status' => 'success', 'matches' => count($matches), 'data' => $matches]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Failed to parse JSON response from AI']);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>
