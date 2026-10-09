<?php
// modules/vendor/suggest_biz_ai.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// Selalu kembalikan JSON ke frontend
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$desc = trim($_POST['description'] ?? '');
if(empty($desc)) {
    echo json_encode(['success' => false, 'error' => 'Deskripsi kosong.']);
    exit();
}

// 1. Tarik Data Taxonomy (Aman dari limit GROUP_CONCAT)
$tax_stmt = $conn->query("SELECT sub_category, commodity FROM taxonomy");
$cat_map = [];
if ($tax_stmt) {
    while($row = $tax_stmt->fetch_assoc()) {
        $sub = trim($row['sub_category']);
        if (!empty($sub)) {
            if (!isset($cat_map[$sub])) $cat_map[$sub] = [];
            if (!empty($row['commodity'])) $cat_map[$sub][] = trim($row['commodity']);
        }
    }
}

// 2. Rangkai Data untuk diajarkan ke AI (Dibatasi panjangnya agar AI tidak bingung)
$cat_context = "";
foreach($cat_map as $sub => $comms) {
    $comms_str = implode(', ', array_unique($comms));
    // Jika komoditas sangat panjang, potong untuk menghemat token API
    if (strlen($comms_str) > 150) $comms_str = substr($comms_str, 0, 150) . "..."; 
    $cat_context .= "- $sub (Contoh isi: $comms_str)\n";
}

if (empty($cat_context)) {
    echo json_encode(['success' => false, 'error' => 'Kamus kategori kosong di database.']);
    exit();
}

// 3. Prompt AI yang sangat Tegas
$prompt = "Anda adalah sistem klasifikasi.
Deskripsi bisnis vendor: \"$desc\"

Daftar Kategori:
$cat_context

Tugas Anda: Pilih 1 sampai 3 nama Kategori Utama (hanya teks sebelum tanda kurung) yang relevan dengan deskripsi vendor.
ATURAN MUTLAK: Anda HANYA boleh merespons dengan array JSON murni. Jangan tambahkan teks lain, penjelasan, atau format object.
Contoh Respons yang Benar: [\"IT Equipment\", \"Stationery\"]";

$apiKey = trim((string) getenv('AI_VENDOR_BIZ_SUGGEST_API_KEY'));
if ($apiKey === '') {
    echo json_encode(['success' => false, 'error' => 'AI business-category suggestion is not configured.']);
    exit();
}

// Spend guard: per-user throttle + per-key budget/circuit breaker.
require_once $path_to_root . 'includes/gemini_guard.php';
if (!gemini_guard_user('vendor_biz_suggest', (string) $_SESSION['user_id'], 20, 600)) {
    echo json_encode(['success' => false, 'error' => 'Terlalu banyak permintaan. Coba lagi dalam beberapa menit.']);
    exit();
}
if (!gemini_guard_allow($apiKey)['ok']) {
    echo json_encode(['success' => false, 'error' => 'AI suggestion is temporarily unavailable.']);
    exit();
}
// The key goes in a header, never in the URL.
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

$payload = [
    "contents" => [["parts" => [["text" => $prompt]]]],
    "generationConfig" => [
        "temperature" => 0.0, // Suhu 0 agar AI menjawab kaku layaknya mesin
        "responseMimeType" => "application/json"
    ]
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
gemini_guard_report($apiKey, (int) $httpCode);

if ($httpCode !== 200) {
    // Google's raw error body is no longer echoed to the browser.
    echo json_encode(['success' => false, 'error' => "HTTP Error $httpCode"]);
    exit();
}

$responseData = json_decode($response, true);
$resultText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '';

// 4. PEMBERSIH EKSTREM (Extreme Sanitizer)
$final_categories = [];

// Memaksa mencari pola array [ ... ] di dalam teks balasan AI menggunakan Regex
if (preg_match('/\[(.*?)\]/s', $resultText, $matches)) {
    $json_array_str = '[' . $matches[1] . ']'; // Ambil isi dalam kurung siku
    $parsed = json_decode($json_array_str, true);
    
    // Pastikan hasil parse adalah Array
    if (is_array($parsed)) {
        foreach ($parsed as $item) {
            if (is_string($item)) {
                $final_categories[] = trim($item);
            }
        }
    }
}

// 5. Kembalikan Respon ke Frontend
if(count($final_categories) > 0) {
    echo json_encode(['success' => true, 'categories' => $final_categories, 'raw' => $resultText]);
} else {
    echo json_encode(['success' => false, 'categories' => [], 'raw' => $resultText]);
}
?>