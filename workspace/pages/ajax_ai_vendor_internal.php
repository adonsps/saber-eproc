<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../config/gemini_api.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$pr_items = $data['pr_items'] ?? [];

if (empty($pr_items)) {
    echo json_encode(['error' => 'No PR items provided.']);
    exit;
}

try {
    // 1. Connect to VSS Database
    $vss_pdo = get_vss_pdo();

    // 2. Fetch Active Vendors
    // We get company_name, core_business, email, pic_finance (or contact_person), phone
    $stmt = $vss_pdo->query("
        SELECT v.id, v.company_name, v.core_business, v.email, v.contact_person as pic, u.phone_number as phone
        FROM vendors v 
        LEFT JOIN users u ON v.user_id = u.id 
        WHERE v.registration_status = 'approved' OR v.registration_status = 'registered'
    ");
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch products if core_business is empty
    foreach ($vendors as &$v) {
        if (empty(trim($v['core_business']))) {
            $pst = $vss_pdo->prepare("SELECT product_name FROM vendor_products WHERE vendor_id = ?");
            $pst->execute([$v['id']]);
            $products = $pst->fetchAll(PDO::FETCH_COLUMN);
            $v['core_business'] = implode(', ', $products);
        }
        unset($v['id']); // Remove ID to save token space
    }
    unset($v);

    // 3. Prompt Gemini
    $prompt = "You are a procurement expert. I have the following Purchase Request (PR) items:\n";
    $prompt .= json_encode($pr_items, JSON_PRETTY_PRINT) . "\n\n";
    $prompt .= "And I have the following list of registered internal vendors:\n";
    $prompt .= json_encode($vendors, JSON_PRETTY_PRINT) . "\n\n";
    $prompt .= "Suggest up to 5 most suitable vendors from the internal list based on the PR items. ";
    $prompt .= "If 'core_business' is blank or doesn't match well, rely on the closest match you can find. ";
    $prompt .= "Provide a confidence percentage for each suggestion. ";
    $prompt .= "Return ONLY a valid JSON array of objects with exactly these keys: 'company_name', 'core_business', 'email', 'phone', 'pic', 'confidence' (integer 0-100), 'description' (short reason). ";
    $prompt .= "DO NOT wrap the output in markdown ```json blocks. Just output raw JSON.";

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
