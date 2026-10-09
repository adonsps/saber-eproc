<?php
// modules/vendor/validate_project_ai.php
session_start();
// config.php is required purely for the maintenance gate: this endpoint has no
// database work of its own, but without it the whole file used to answer 200 and
// call Gemini while the portal was closed for maintenance.
require '../../config.php';
header('Content-Type: application/json');

// This endpoint used to have no login check at all, so anyone on the internet
// could spend the Gemini key. Same gate as the other vendor AI modules; the
// answer stays "valid" so a vendor whose session just expired is never blocked.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') != 'vendor') {
    http_response_code(401);
    echo json_encode(['is_valid' => true, 'message' => '']);
    exit;
}

// 1. Ambil isian dari vendor
$text = $_POST['text'] ?? '';

if (empty(trim($text))) {
    echo json_encode(['is_valid' => false, 'message' => 'Teks tidak boleh kosong.']);
    exit;
}

// 2. Konfigurasi API Google Gemini
// ==========================================
$gemini_api_key = trim((string) getenv('AI_VENDOR_PROJECT_VALIDATE_API_KEY'));
if ($gemini_api_key === '') {
    // Same "AI unavailable" fallback as the API-error path below: don't block
    // a vendor's submission just because the AI isn't configured.
    echo json_encode(['is_valid' => true, 'message' => '']);
    exit();
}
// ==========================================

// Spend guard: per-user throttle + per-key budget/circuit breaker. Over the
// limit behaves like "AI unavailable": let the vendor through.
require_once __DIR__ . '/../../includes/gemini_guard.php';
if (!gemini_guard_user('vendor_project_validate', (string) $_SESSION['user_id'], 40, 600)
    || !gemini_guard_allow($gemini_api_key)['ok']) {
    echo json_encode(['is_valid' => true, 'message' => '']);
    exit();
}

// UPGRADED ke Gemini 2.5 Flash (key goes in a header, never in the URL)
$gemini_url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent";

// 3. PROMPT SUPER KETAT (STRICT SYSTEM VALIDATOR)
$prompt = "Kamu adalah sistem validator data (strict system validator). Periksa teks isian pengalaman proyek ini: \"$text\"\n\n";
$prompt .= "Teks tersebut HARUS memiliki 3 informasi ini agar dinyatakan valid:\n";
$prompt .= "1. NAMA PROYEK/PEKERJAAN (Contoh: Pembangunan Mall, Pengadaan Seragam, Renovasi)\n";
$prompt .= "2. NAMA KLIEN/CUSTOMER (Contoh: PT ABC, Kawan Lama Group, CV Jaya)\n";
$prompt .= "3. TAHUN (Contoh: 2023, 2024)\n\n";
$prompt .= "ATURAN MUTLAK:\n";
$prompt .= "- Jika teks tidak memiliki makna (contoh: 'baju celana', 'test', 'asd', '123'), WAJIB is_valid: false.\n";
$prompt .= "- Jika ada SATU SAJA komponen (Proyek, Klien, atau Tahun) yang hilang, WAJIB is_valid: false, dan beritahu apa yang kurang di variabel 'message'.\n";
$prompt .= "- Hanya jika KETIGA komponen tersebut ada dan masuk akal, maka is_valid: true, dan kosongkan 'message'.\n";

// Menggunakan generationConfig agar Gemini merespon 100% dengan format JSON murni
$data = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt]
            ]
        ]
    ],
    "generationConfig" => [
        "responseMimeType" => "application/json"
    ]
];

$ch = curl_init($gemini_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $gemini_api_key]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
gemini_guard_report($gemini_api_key, (int) $http_code);

if ($http_code == 200) {
    $result = json_decode($response, true);
    $ai_text = $result['candidates'][0]['content']['parts'][0]['text'] ?? '';
    
    // Pastikan tidak ada sisa markdown backticks (```) yang bisa merusak parse JSON di JavaScript
    $ai_text = str_replace(['```json', '```'], '', $ai_text);
    
    echo trim($ai_text);
} else {
    // Fallback jika API limit / error, biarkan lewat agar vendor tidak stuck
    echo json_encode(['is_valid' => true, 'message' => '']);
}
?>