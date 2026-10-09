<?php
// modules/admin/download_vendor_docs.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// --- Auth ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    die("Access Denied");
}

$vendor_id     = intval($_GET['id']     ?? 0);
$submission_id = intval($_GET['sub_id'] ?? 0);

if (!$vendor_id) {
    http_response_code(400);
    die("No vendor ID provided.");
}

// --- Fetch vendor row ---
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) {
    http_response_code(404);
    die("Vendor not found.");
}

// --- Build legal doc file list (mirrors review_pq.php logic) ---
$entity_type = $vendor['legal_entity_type'] ?? '';
$comp_type   = strtolower($vendor['company_type'] ?? '');

$legal_docs = [
    'KTP Direktur'                      => $vendor['director_id_card_file']         ?? '',
    'NPWP'                              => $vendor['npwp_path']                      ?? '',
    'Buku Rekening'                     => $vendor['bank_cover_path']                ?? '',
    'Surat Pernyataan Perbedaan Nama Rekening' => $vendor['bank_holder_statement_letter_file'] ?? '',
    'Pakta Integritas Kawan Lama Group' => $vendor['business_ethics_commitment_file']?? '',
    'Tax Statement Letter'              => $vendor['tax_statement_letter_file']      ?? '',
    'Profil Perusahaan'                 => $vendor['company_profile_file']           ?? '',
    // UMKM applies to Perseorangan and Badan Usaha alike, so it stays outside
    // the Badan Usaha-only block below.
    'Sertifikasi UMKM'                  => $vendor['umkm_cert_file']                 ?? '',
];

if ($entity_type === 'Badan Usaha') {
    $legal_docs['NIB']             = $vendor['nib_path']                   ?? '';
    $legal_docs['Akta Pendirian']  = $vendor['deed_of_establishment_file'] ?? '';
    $legal_docs['SK Pengesahan']   = $vendor['decree_of_legal_entity_file']?? '';
    $legal_docs['Akta Perubahan']  = $vendor['deed_of_amendment_file']     ?? '';
    $legal_docs['SPPKP']           = $vendor['sppkp_path']                 ?? '';
}

if (strpos($comp_type, 'contractor') !== false || strpos($comp_type, 'kontraktor') !== false) {
    $legal_docs['Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)'] = $vendor['business_entity_cert_file'] ?? '';
}

// --- Collect PQ answer attachments (if submission_id provided) ---
$pq_attachments = [];
if ($submission_id > 0) {
    $stmt_pq = $conn->prepare(
        "SELECT q.question_text, a.attachment_path
         FROM pq_answers a
         JOIN pq_questions q ON a.question_id = q.id
         WHERE a.submission_id = ? AND a.attachment_path IS NOT NULL AND a.attachment_path <> ''"
    );
    $stmt_pq->bind_param("i", $submission_id);
    $stmt_pq->execute();
    $res_pq = $stmt_pq->get_result();
    while ($row = $res_pq->fetch_assoc()) {
        $pq_attachments[$row['question_text']] = $row['attachment_path'];
    }
}

// --- Build a flat list of [zip_filename => absolute_path] ---
// Uploads live inside the docroot in prod (<docroot>/uploads, the app_uploads
// volume) but one level above it in some dev setups (<docroot>/../uploads, the
// double-mount in docker-compose.yml). Anchor the candidates to this file's
// location (__DIR__) so resolution is independent of the request CWD, and try
// both layouts — whichever actually holds the file wins. Keep only the ones
// that exist on disk. Mirrors the multi-base pattern profile_action.php uses
// for vendor upload writes/reads (see commit 699066a).
$upload_base_candidates = array_values(array_filter([
    realpath(__DIR__ . '/../../uploads'),      // <docroot>/uploads    (prod volume)
    realpath(__DIR__ . '/../../../uploads'),   // <docroot>/../uploads (dev double-mount)
]));
$files_to_zip = [];

// Helper: sanitize label into a safe filename
function safeFilename(string $label, string $originalPath): string {
    $ext   = strtolower(pathinfo($originalPath, PATHINFO_EXTENSION));
    $clean = preg_replace('/[^A-Za-z0-9\-_\.]/', '_', $label);
    $clean = trim($clean, '_');
    return $ext ? "{$clean}.{$ext}" : $clean;
}

// Resolve a DB-stored relative upload path against the candidate base dirs.
// Returns the absolute file path, or null if not found under any base. The
// strpos() check retains the original path-traversal guard, per base.
function resolveUploadFile(array $bases, string $rel_path): ?string {
    foreach ($bases as $base) {
        $abs = realpath($base . '/' . $rel_path);
        if ($abs && strpos($abs, $base) === 0 && is_file($abs)) {
            return $abs;
        }
    }
    return null;
}

foreach ($legal_docs as $label => $rel_path) {
    if (empty($rel_path)) continue;
    $abs = resolveUploadFile($upload_base_candidates, $rel_path);
    if ($abs) {
        $zip_name = 'Legal/' . safeFilename($label, $rel_path);
        $files_to_zip[$zip_name] = $abs;
    }
}

foreach ($pq_attachments as $question => $rel_path) {
    if (empty($rel_path)) continue;
    $abs = resolveUploadFile($upload_base_candidates, $rel_path);
    if ($abs) {
        $short_q  = mb_substr($question, 0, 60);
        $zip_name = 'PQ_Attachments/' . safeFilename($short_q, $rel_path);
        // Deduplicate if two questions share the same filename
        if (isset($files_to_zip[$zip_name])) {
            $zip_name = 'PQ_Attachments/' . uniqid() . '_' . safeFilename($short_q, $rel_path);
        }
        $files_to_zip[$zip_name] = $abs;
    }
}

if (empty($files_to_zip)) {
    http_response_code(404);
    die("No documents found for this vendor.");
}

// --- Build ZIP in temp ---
if (!class_exists('ZipArchive')) {
    http_response_code(500);
    die("ZipArchive extension is not available on this server.");
}

$company_slug = preg_replace('/[^A-Za-z0-9\-_]/', '_', $vendor['company_name'] ?? 'vendor');
$zip_filename = "Docs_{$company_slug}_ID{$vendor_id}.zip";
$tmp_zip      = sys_get_temp_dir() . '/' . $zip_filename;

$zip = new ZipArchive();
if ($zip->open($tmp_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    die("Failed to create ZIP archive.");
}

foreach ($files_to_zip as $zip_entry => $abs_path) {
    $zip->addFile($abs_path, $zip_entry);
}
$zip->close();

// --- Stream to browser ---
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
header('Content-Length: ' . filesize($tmp_zip));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tmp_zip);
@unlink($tmp_zip); // clean up temp file
exit;