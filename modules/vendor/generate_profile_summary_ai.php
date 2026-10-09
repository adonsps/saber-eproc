<?php
// modules/vendor/generate_profile_summary_ai.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/upload_paths.php';
require_once $path_to_root . 'includes/vendor_context.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php.
if (vendor_staff_edit_session()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];

// Cek apakah vendor ini butuh diekstrak
$vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
$stmt = $conn->prepare("SELECT id, company_profile_file, company_profile_summary FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor || $vendor['company_profile_summary'] !== 'PENDING_EXTRACT' || empty($vendor['company_profile_file'])) {
    echo json_encode(['success' => false, 'error' => 'Tidak ada dokumen antrean.']);
    exit();
}

// Resolved against both upload bases so a profile stored under either scheme is
// found — the hardcoded '<root>/../uploads/' never resolved under Docker.
$full_file_path = vendor_upload_resolve($vendor['company_profile_file']);
if ($full_file_path === null || !file_exists($full_file_path)) {
    $conn->query("UPDATE vendors SET company_profile_summary = '⚠️ File tidak ditemukan di server.' WHERE id = " . $vendor['id']);
    echo json_encode(['success' => false, 'error' => 'File not found.']);
    exit();
}

$apiKey = trim((string) getenv('AI_VENDOR_PROFILE_SUMMARY_API_KEY'));

$final_summary = "";
if ($apiKey === '') {
    $final_summary = "⚠️ Sistem AI belum dikonfigurasi (API key tidak ditemukan).";
} else {
    // Spend guard. Refusing here leaves the vendor row at PENDING_EXTRACT (no
    // failure text is saved), so the summary is simply retried later.
    require_once $path_to_root . 'includes/gemini_guard.php';
    if (!gemini_guard_user('vendor_profile_summary', (string) $user_id, 10, 600)
        || !gemini_guard_allow($apiKey)['ok']) {
        echo json_encode(['success' => false, 'error' => 'AI summary is temporarily unavailable. Please try again later.']);
        exit();
    }
    // The key goes in a header, never in the URL.
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

    $mimeType = mime_content_type($full_file_path);
    if (!$mimeType) $mimeType = 'application/pdf';

    $base64Data = base64_encode(file_get_contents($full_file_path));

    $prompt = "Tolong baca profil perusahaan ini dan buatkan ringkasan analitis untuk tim Procurement (Sourcing & Vendor Management). \n\nStruktur ringkasan HARUS seperti ini (gunakan format Markdown tebal/bold pada judul angka):\n**1. Siapa Vendor Ini:** (Deskripsi singkat profil vendor)\n**2. Produk/Jasa Utama:** (Apa saja yang mereka tawarkan)\n**3. Keunggulan Kompetitif:** (Apa nilai jual utama/pengalaman mereka)\n**4. Insight untuk Sourcing:** (Potensi yang bisa dimaksimalkan atau hal yang perlu diperhatikan dari vendor ini)";

    $payload = [
        "contents" => [["parts" => [["text" => $prompt], ["inline_data" => ["mime_type" => $mimeType, "data" => $base64Data]]]]],
        "generationConfig" => ["temperature" => 0.2]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    gemini_guard_report($apiKey, (int) $httpCode);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        $ai_text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if (!empty(trim($ai_text))) {
            $final_summary = trim($ai_text);
        } else {
            $final_summary = "⚠️ AI Server merespons sukses, tetapi hasil ringkasan kosong.";
        }
    } else {
        $err_data = json_decode($response, true);
        $err_msg = $err_data['error']['message'] ?? 'Time Out / Ukuran File Terlalu Besar';
        $final_summary = "⚠️ **Sistem AI Gagal Meringkas Dokumen.** \nKode Error: HTTP $httpCode \nPesan Server: $err_msg \n\n*Silakan baca dokumen asli secara manual.*";
    }
}

// Simpan hasil
$stmt_update = $conn->prepare("UPDATE vendors SET company_profile_summary = ? WHERE id = ?");
$stmt_update->bind_param("si", $final_summary, $vendor['id']);
$stmt_update->execute();

echo json_encode(['success' => true, 'summary' => $final_summary]);
?>