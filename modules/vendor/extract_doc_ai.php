<?php
// modules/vendor/extract_doc_ai.php
session_start();
require '../../config.php'; // Needed for vendor name comparison

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['document']) || !isset($_POST['doc_type'])) {
    echo json_encode(['error' => 'Missing document or document type.']);
    exit();
}

$file = $_FILES['document'];
$docType = $_POST['doc_type']; 

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['error' => 'File upload error.']);
    exit();
}

// File size validation: max 5MB for all document extractions
$max_size = 5 * 1024 * 1024; // 5MB
if ($file['size'] > $max_size) {
    $size_mb = round($file['size'] / (1024 * 1024), 1);
    echo json_encode(['error' => "File terlalu besar ({$size_mb}MB). Maksimal 5MB."]);
    exit();
}

$filePath = $file['tmp_name'];
$base64Data = base64_encode(file_get_contents($filePath));
$mimeType = mime_content_type($filePath);

// Set Dynamic Prompts based on Document Type
$promptText = "";
$descValid = "";

switch ($docType) {
    case 'NIB':
        $promptText = "Analyze the document. Determine if it is a valid Indonesian NIB (Nomor Induk Berusaha) and extract the 13-digit NIB number.";
        $descValid = "True if the document is an Indonesian NIB certificate.";
        break;
    case 'NPWP':
        // A modern e-NPWP card prints TWO tax numbers: the legacy 15-digit one in
        // large type, and the current 16-digit one in small print below it. Asking
        // for "the NPWP number" reliably returns the big legacy one, which is then
        // rejected by the 16-digit rule below even though the card does carry a
        // valid 16-digit number — hence the very explicit instructions here.
        $promptText = "Analyze the document. Determine if it is a valid Indonesian NPWP (Nomor Pokok Wajib Pajak) tax card or document.\n\n"
            . "CRITICAL — READING THE TAX NUMBER. Indonesian tax IDs migrated to a 16-digit format, and a modern e-NPWP card normally shows BOTH numbers at once:\n"
            . "  (a) the LEGACY 15-digit number, printed large and dot/dash formatted, for example '25.177.720.7-404.000';\n"
            . "  (b) the CURRENT 16-digit number, printed much smaller — usually on a line labelled 'NPWP16', 'NPWP 16', 'NPWP (16 digit)' or near the QR code — for example 'NPWP16 : 3271 0655 0986 0007'. For an individual taxpayer this is identical to their 16-digit NIK.\n"
            . "Read the ENTIRE document including small print, labels, footnotes and any text beside the QR code before answering. Do NOT stop at the large number.\n\n"
            . "Return these fields:\n"
            . "  - extracted_number_16: the 16-digit tax number, DIGITS ONLY (strip spaces, dots and dashes). Return null ONLY if no 16-digit tax number appears anywhere on the document.\n"
            . "  - extracted_number_legacy: the legacy 15-digit number, digits only. Return null if the document does not show one.\n"
            . "  - extracted_number: the same value as extracted_number_16 whenever a 16-digit number exists; only fall back to the 15-digit number when the document genuinely has no 16-digit one.\n"
            . "  - extracted_name: the Name (Nama Wajib Pajak) printed on it.\n"
            . "  - extracted_address: the full Address (Alamat Wajib Pajak) exactly as printed, including street, RT/RW, kelurahan, kecamatan, kota/kabupaten and postal code if present, joined into a single string with commas.\n"
            . "Return null for any field that is not found or not legible.";
        $descValid = "True if the document is an Indonesian NPWP tax document.";
        break;
    case 'KTP':
        $promptText = "Analyze the document. Determine if it is a valid Indonesian KTP (Kartu Tanda Penduduk) and extract the 16-digit NIK (Nomor Induk Kependudukan).";
        $descValid = "True if the document is a valid Indonesian KTP.";
        break;
    case 'Akta':
        $promptText = "Analyze the document. Determine if it is an Indonesian Deed of Establishment (Akta Pendirian Perusahaan) or its cover page. IMPORTANT: Do NOT extract the short Deed Number (Nomor Akta) like '01' or '26'. Instead, extract the LONG SK or Registration number printed on the front cover page (usually formatted like 'AHU-xxxxxx.AH.xx.xx.Tahun' or a similar long alphanumeric string).";
        $descValid = "True if the document appears to be an Indonesian Deed of Establishment (Akta Notaris) or its cover.";
        break;
    case 'SK':
        $promptText = "Analyze the document. Determine if it is a valid Indonesian SK Kemenkumham (Surat Keputusan Pengesahan Badan Hukum) and extract the SK Number (Nomor SK), usually formatted like 'AHU-xxxxxx.AH.xx.xx.Tahun'.";
        $descValid = "True if the document is a valid SK Kemenkumham (Decree of Legal Entity).";
        break;
    case 'Rekening':
        $promptText = "Analyze the document. Determine if it is a valid Indonesian Bank Passbook (Buku Rekening) or Bank Statement. Extract ONLY the Bank Account Number (Nomor Rekening). Do not include the bank name or owner name in the extracted_number field.";
        $descValid = "True if the document clearly shows a Bank Account Number from an Indonesian Bank.";
        break;
    case 'UMKM':
        $promptText = "Analyze the document. Determine if it is a valid Indonesian UMKM (Usaha Mikro, Kecil, dan Menengah) registration document — a certificate or letter of declaration, commonly issued by a village/kelurahan office, Dinas Koperasi dan UKM, or via the OSS/oss.go.id system as part of an NIB with UMKM classification.\n\n"
            . "IMPORTANT — do NOT judge validity by the document's title alone. Many genuine, valid documents are titled generically 'SURAT KETERANGAN' (without the word 'UMKM' anywhere in the title) and only mention UMKM in the body text. Treat the document as VALID whenever its body content confirms UMKM registration/classification, for example by referencing: Undang-Undang Nomor 20 Tahun 2008 tentang Usaha Mikro, Kecil, dan Menengah; a classification as Usaha Mikro / Usaha Kecil / Usaha Menengah; or registration ('terdaftar') at a Dinas Koperasi dan Usaha Kecil dan Menengah, kelurahan/desa office, or OSS/oss.go.id — even if the header/title just says 'Surat Keterangan'.\n\n"
            . "Extract the document's reference number — labelled as 'Nomor Surat', 'Nomor', or 'No.' — exactly as printed, including any slashes or dashes.";
        $descValid = "True if the document's content confirms Indonesian UMKM registration/classification (per UU No 20/2008 or issued by Dinas Koperasi/kelurahan/OSS), regardless of whether its title literally contains the word 'UMKM'.";
        break;
    default:
        echo json_encode(['error' => 'Unknown document type.']);
        exit();
}
//jan lupa apus
$apiKey = trim((string) getenv('AI_VENDOR_DOC_EXTRACT_API_KEY'));
if ($apiKey === '') {
    echo json_encode(['error' => 'AI document extraction is not configured.']);
    exit();
}

// Spend guard: per-user throttle + per-key budget/circuit breaker.
require_once __DIR__ . '/../../includes/gemini_guard.php';
if (!gemini_guard_user('vendor_doc_extract', (string) $_SESSION['user_id'], 20, 600)) {
    echo json_encode(['error' => 'Terlalu banyak permintaan. Coba lagi dalam beberapa menit.']);
    exit();
}
if (!gemini_guard_allow($apiKey)['ok']) {
    echo json_encode(['error' => 'AI document extraction is temporarily unavailable.']);
    exit();
}
// The key goes in a header, never in the URL.
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent';

$payload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $promptText],
                [
                    "inline_data" => [
                        "mime_type" => $mimeType,
                        "data" => $base64Data
                    ]
                ]
            ]
        ]
    ],
    "generationConfig" => [
        "responseMimeType" => "application/json",
        "responseSchema" => [
            "type" => "OBJECT",
            "properties" => [
                "is_valid_document" => [
                    "type" => "BOOLEAN",
                    "description" => $descValid
                ],
                "extracted_number" => [
                    "type" => "STRING",
                    "description" => "The extracted document number (only numbers/text, remove formatting if needed). For an NPWP this must be the 16-digit number whenever the document shows one. Return null if not found."
                ],
                "extracted_number_16" => [
                    "type" => "STRING",
                    "description" => "NPWP only: the CURRENT 16-digit tax number, digits only. It is the small-print line usually labelled 'NPWP16' / 'NPWP 16', not the large legacy number. Return null if the document shows no 16-digit number, or if not requested."
                ],
                "extracted_number_legacy" => [
                    "type" => "STRING",
                    "description" => "NPWP only: the LEGACY 15-digit tax number, digits only (the large dot-formatted one, e.g. 25.177.720.7-404.000). Return null if not present or not requested."
                ],
                "extracted_name" => [
                    "type" => "STRING",
                    "description" => "The extracted name of the entity/person from the document. Return null if not found or not requested."
                ],
                "extracted_address" => [
                    "type" => "STRING",
                    "description" => "The full taxpayer address (Alamat Wajib Pajak) printed on the NPWP, combined into a single string with commas separating street, RT/RW, kelurahan, kecamatan, kota, and postal code. Return null if not found or not requested."
                ]
            ],
            "required" => ["is_valid_document", "extracted_number"]
        ]
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
    echo json_encode(['error' => 'Gemini API Error']);
    exit();
}

$responseData = json_decode($response, true);
$rawText = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
$result = json_decode($rawText, true);

if (!is_array($result)) {
    echo $rawText;
    exit();
}

// =============================================
// NPWP NAME vs COMPANY NAME COMPARISON
// Runs server-side right after extraction for immediate feedback
// =============================================
if ($docType === 'NPWP' && !empty($result['extracted_name']) && $result['is_valid_document']) {
    
    $user_id = $_SESSION['user_id'];
    $vendor_id_ai = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
    $stmt_v = $conn->prepare("SELECT company_name, legal_entity_type FROM vendors WHERE id = ?");
    $stmt_v->bind_param("i", $vendor_id_ai);
    $stmt_v->execute();
    $vendor_info = $stmt_v->get_result()->fetch_assoc();
    
    if ($vendor_info) {
        $company_name = $vendor_info['company_name'] ?? '';
        $legal_entity = $vendor_info['legal_entity_type'] ?? '';
        $npwp_name = $result['extracted_name'];
        
        // Normalize: strip legal prefixes, dots, dashes, lowercase
        $prefixes = '/^(pt\.?\s*|cv\.?\s*|ud\.?\s*|tb\.?\s*|toko\.?\s*|firma\.?\s*|koperasi\.?\s*|yayasan\.?\s*|perum\.?\s*|persero\.?\s*)/i';
        
        $norm_company = preg_replace($prefixes, '', strtolower(trim($company_name)));
        $norm_company = preg_replace('/[.,\-\/\\\\()]+/', ' ', $norm_company);
        $norm_company = preg_replace('/\s+/', ' ', trim($norm_company));
        
        $norm_npwp = preg_replace($prefixes, '', strtolower(trim($npwp_name)));
        $norm_npwp = preg_replace('/[.,\-\/\\\\()]+/', ' ', $norm_npwp);
        $norm_npwp = preg_replace('/\s+/', ' ', trim($norm_npwp));
        
        // Calculate similarity
        $is_match = false;
        
        if ($norm_company === $norm_npwp) {
            $is_match = true;
        } elseif (strpos($norm_company, $norm_npwp) !== false || strpos($norm_npwp, $norm_company) !== false) {
            $is_match = true; // One contains the other
        } else {
            // Levenshtein similarity
            $max_len = max(strlen($norm_company), strlen($norm_npwp));
            if ($max_len > 0) {
                $distance = levenshtein($norm_company, $norm_npwp);
                $similarity = 1 - ($distance / $max_len);
                if ($similarity >= 0.7) {
                    $is_match = true;
                }
            }
        }
        
        if (!$is_match) {
            $result['name_anomaly'] = true;
            $result['anomaly_company_name'] = $company_name;
            $result['anomaly_npwp_name'] = $npwp_name;
            
            if ($legal_entity === 'Perseorangan') {
                $result['anomaly_reason'] = 'Bentuk badan hukum Anda adalah Perseorangan, sehingga nama NPWP boleh berupa nama pribadi pemilik. Namun pastikan nama tersebut memang sesuai dengan identitas pemilik usaha.';
            } else {
                $result['anomaly_reason'] = 'Untuk Badan Usaha (PT/CV), nama pada NPWP seharusnya sama dengan nama perusahaan yang terdaftar. Jika terdapat perbedaan, pastikan Anda mendaftarkan entitas yang benar atau perbaiki data sebelum melanjutkan.';
            }
        } else {
            $result['name_anomaly'] = false;
        }
    }
}

// =============================================
// NPWP 16-DIGIT VALIDATION
// The Indonesian NPWP has migrated to a 16-digit format; legacy cards carry only
// 15 digits. Reject a legible-but-not-16-digit number so the vendor re-uploads a
// document that shows the new 16-digit NPWP.
//
// A modern e-NPWP card carries BOTH numbers — the legacy 15-digit one in large
// type and the 16-digit one in small print — and the model may report either as
// `extracted_number`. Rejecting on that single field alone bounced cards that DO
// show a valid 16-digit number, so every candidate is considered and whichever
// one actually has 16 digits wins.
// =============================================
if ($docType === 'NPWP' && !empty($result['is_valid_document'])) {
    $candidates = [
        $result['extracted_number_16']     ?? null,  // what the prompt asks for first
        $result['extracted_number']        ?? null,
        $result['extracted_number_legacy'] ?? null,  // last resort: sometimes the 16-digit lands here
    ];

    $npwp16   = '';  // first candidate that is genuinely 16 digits
    $fallback = '';  // longest legible candidate, for the rejection message

    foreach ($candidates as $candidate) {
        $digits = preg_replace('/\D+/', '', (string) $candidate);
        if ($digits === '') continue;
        if ($npwp16 === '' && strlen($digits) === 16) $npwp16 = $digits;
        if (strlen($digits) > strlen($fallback)) $fallback = $digits;
    }

    if ($npwp16 !== '') {
        // Store it digits-only so the save-time 16-digit check in
        // profile_action.php sees exactly what was validated here.
        $result['extracted_number']   = $npwp16;
        $result['npwp_digit_invalid'] = false;
    } elseif ($fallback !== '') {
        $digit_count = strlen($fallback);
        $result['npwp_digit_invalid'] = true;
        $result['npwp_digit_count']   = $digit_count;
        $result['npwp_digit_message'] = ($digit_count === 15)
            ? 'NPWP yang terdeteksi masih menggunakan format lama (15 digit) dan tidak ditemukan nomor 16 digit pada dokumen. Harap unggah kembali dokumen NPWP dengan format terbaru 16 digit.'
            : 'Nomor NPWP yang terdeteksi tidak berjumlah 16 digit. Harap unggah kembali file dokumen NPWP yang jelas dan menampilkan 16 digit NPWP.';
    }
}

echo json_encode($result);
?>
