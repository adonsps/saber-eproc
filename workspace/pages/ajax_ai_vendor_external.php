<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/gemini_api.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$pr_items = $data['pr_items'] ?? [];

if (empty($pr_items)) {
    echo json_encode(['error' => 'No PR items provided.']);
    exit;
}

try {
    $prompt = "You are a procurement expert with access to real-world business knowledge. I have the following Purchase Request (PR) items in Indonesia:\n";
    $prompt .= json_encode($pr_items, JSON_PRETTY_PRINT) . "\n\n";
    $prompt .= "Suggest 5 real-world companies or suppliers in Indonesia that supply these items. ";
    $prompt .= "For each, provide a realistic email, phone number, and PIC name. If you cannot find real ones, LEAVE THEM BLANK (empty string). DO NOT use fake or placeholder data. ";
    $prompt .= "Provide a confidence percentage for each suggestion based on how well they match the items. ";
    $prompt .= "Also provide their official website URL and a source URL (an article, directory, or website link where you found this information). ";
    $prompt .= "Return ONLY a valid JSON array of objects with exactly these keys: 'company_name', 'core_business' (what they supply), 'email', 'phone', 'pic', 'confidence' (integer 0-100), 'website' (official website), 'source_url' (link to source/article), 'description' (short reason). ";

    $response = call_gemini_api($prompt, 3, gemini_key_vendor_suggest());
    
    // Clean markdown if Gemini still added it
    $response = str_replace(['```json', '```'], '', $response);
    
    $suggestions = json_decode(trim($response), true);
    if (!is_array($suggestions)) {
        echo json_encode(['error' => 'Failed to parse AI response.', 'raw' => $response]);
        exit;
    }

    echo json_encode(['suggestions' => $suggestions]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
