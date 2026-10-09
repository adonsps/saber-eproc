<?php
// modules/vendor/pq_form.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/pq_calc_details.php';
require_once $path_to_root . 'includes/vendor_context.php';

// 1. Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever be on profile.php — the PQ form is out of scope for it.
vendor_staff_edit_restrict();

// 2. Fetch Vendor Data
$user_id = $_SESSION['user_id'];
$vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
$stmt = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) die("Vendor profile not found.");

// --- CEK GATEKEEPER KELENGKAPAN PROFIL ---
$is_profile_complete = true;
$legal_entity_type = $vendor['legal_entity_type'] ?? null;

if (empty($legal_entity_type) || empty($vendor['director_id_card_file']) || empty($vendor['bank_cover_path']) || empty($vendor['npwp_path']) || empty($vendor['business_ethics_commitment_file']) || empty($vendor['tax_statement_letter_file'])) {
    $is_profile_complete = false;
}

if ($legal_entity_type === 'Badan Usaha') {
    if (empty($vendor['nib_path']) || empty($vendor['deed_of_establishment_file']) || empty($vendor['decree_of_legal_entity_file'])) {
        $is_profile_complete = false;
    }
    $ctype = strtolower($vendor['company_type'] ?? '');
    if ((strpos($ctype, 'contractor') !== false || strpos($ctype, 'kontraktor') !== false) && empty($vendor['business_entity_cert_file'])) {
        $is_profile_complete = false;
    }
}

if (!$is_profile_complete) {
    header("Location: dashboard.php?error=" . urlencode("Akses Ditolak! Silakan lengkapi profil dan dokumen legal wajib terlebih dahulu sebelum mengisi Formulir Pra-Kualifikasi."));
    exit();
}

$vendor_id = $vendor['id'];
$vendor_type = $vendor['company_type'];
// Perseorangan is a standalone company_type (individual/sole-proprietor
// vendors invited via invite_vendor), but it must see the exact same PQ
// questions as a Contractor — reuse the Contractor question set for
// matching only; $vendor_type itself stays 'Perseorangan' for display.
$vendor_type_for_query = ($vendor_type === 'Perseorangan') ? 'Contractor' : $vendor_type;
$current_status = $vendor['registration_status'];

// Sub-category is deterministic for Perseorangan — it always maps to
// 'Perseorangan', so unlike regular Contractor/Supplier vendors it doesn't
// need Sourcing to classify it after submission. Set it now so the question
// set below can be scoped via pq_weight the same way review_pq.php scopes it
// later. $is_individual_foreman still gates the pq_weight lookup further
// down, since those weight rows are keyed by the older 'Individual / Foreman'
// label shared with Contractor + Perseorangan-legal-entity vendors.
//
// A vendor that ticked "Apakah Anda UMKM?" on its profile lands here too,
// whatever its company_type is: UMKM means the shorter Perseorangan set.
$is_individual_foreman = vendor_uses_perseorangan_pq($vendor_type, $vendor['is_umkm'] ?? 0);
if ($is_individual_foreman && trim($vendor['sub_company_type'] ?? '') !== 'Perseorangan') {
    $conn->query("UPDATE vendors SET sub_company_type = 'Perseorangan' WHERE id = $vendor_id");
    $vendor['sub_company_type'] = 'Perseorangan';
}

// --- DIRECT REGISTRATION: CLASSIFY BEFORE SHOWING PQ QUESTIONS ---
// Every pq_questions row maps to exactly one pq_weight.sub_variant_target
// (no shared/universal questions), so the PQ form can only be simplified to
// the relevant subset once the sub-category is known. Sourcing declares it
// here, right after the profile and before the PQ — unlike the normal
// vendor flow, where Sourcing only sees/declares it later in review_pq.php
// after reading the (unfiltered) answers. Not reached for company_type =
// 'Perseorangan' — that case is auto-set deterministically just above.
$is_impersonating = !empty($_SESSION['impersonator']);
if ($is_impersonating && empty($vendor['sub_company_type'])) {
    $legal_entity = $vendor['legal_entity_type'] ?? '';
    if ($vendor_type === 'Contractor') {
        $sub_opts = ($legal_entity === 'Perseorangan')
            ? ['Individual / Foreman']
            : ['Project', 'Retail & Maintenance', 'Individual / Foreman'];
    } else {
        $sub_opts = ['Principal', 'Distributor', 'Retail', 'Service'];
    }

    $page_title = "Klasifikasi Vendor";
    include $path_to_root . 'includes/header.php';
    ?>
    <div class="container py-5" style="max-width: 600px;">
        <div class="card border-0 shadow-sm rounded-4">
            <div class="card-body p-4">
                <h5 class="fw-bold text-primary mb-2"><i class="fas fa-sitemap me-2"></i>Klasifikasi Sub-Kategori Vendor</h5>
                <p class="text-muted small mb-4">Sebelum mengisi Formulir Pra-Kualifikasi, tentukan Sub-Kategori Vendor terlebih dahulu. Formulir PQ akan disesuaikan agar hanya menampilkan pertanyaan yang relevan untuk kategori ini.</p>
                <?php if (isset($_GET['error'])): ?>
                    <div class="alert alert-danger small"><?= htmlspecialchars($_GET['error']) ?></div>
                <?php endif; ?>
                <form action="classify_vendor.php" method="POST">
                    <select name="sub_company_type" class="form-select fw-bold border-primary mb-3" required>
                        <option value="" disabled selected>-- Pilih Klasifikasi Spesifik --</option>
                        <?php foreach ($sub_opts as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary fw-bold rounded-pill py-2"><i class="fas fa-arrow-right me-1"></i> Lanjut ke Formulir PQ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    include $path_to_root . 'includes/footer.php';
    exit();
}

// 3. Logic: Check for existing submission
$check = $conn->query("SELECT id FROM pq_submissions WHERE vendor_id = $vendor_id");
$has_submitted = ($check->num_rows > 0);

// 'returned_to_sourcing' only bypasses this redirect while impersonating —
// Direct Registration resumes here when VM sends a submission back (Direct
// Registration only ever routes "return" to Sourcing, never to the vendor —
// see approve_pq.php). A real vendor's own session must NOT get this
// exception: in the normal flow, 'returned_to_sourcing' is Sourcing-only
// territory handled via review_pq.php, and this page also surfaces the
// internal VM notes trail, which isn't meant for the vendor to see directly.
$returned_to_sourcing_editable = $is_impersonating && $current_status === 'returned_to_sourcing';
if ($has_submitted && $current_status !== 'revision_requested' && $current_status !== 'invited_pq' && $current_status !== 'migrated' && $current_status !== 'registered' && !$returned_to_sourcing_editable) {
    header("Location: dashboard.php");
    exit();
}

// 4. PRE-FILL DATA (Termasuk Sourcing & VM Notes)
$existing_answers = [];
$sourcing_notes = "";
$vm_notes = "";
$notes_to_vendor = "";
$saved_risk_percentage = null;
$saved_risk_category = null;

if ($has_submitted) {
    $sub_q = $conn->query("SELECT id, sourcing_notes, vm_notes, notes_to_vendor, risk_percentage, risk_category FROM pq_submissions WHERE vendor_id = $vendor_id ORDER BY id DESC LIMIT 1");
    if ($sub_row = $sub_q->fetch_assoc()) {
        $last_sub_id = $sub_row['id'];
        $sourcing_notes = $sub_row['sourcing_notes'];
        $vm_notes = $sub_row['vm_notes'];
        $notes_to_vendor = $sub_row['notes_to_vendor'] ?? '';
        // So the live badge shows the already-saved score immediately on page
        // load — clicking "Simpan" is a full-page navigation (submit_pq.php
        // saves the draft, then redirects), not an AJAX call, so without this
        // the badge would sit hidden/blank until a further edit re-triggers
        // the JS preview, even though the real score was already saved.
        $saved_risk_percentage = $sub_row['risk_percentage'];
        $saved_risk_category = $sub_row['risk_category'];

        $ans_q = $conn->query("
            SELECT a.question_id, a.selected_score, a.remarks, a.calc_details, a.attachment_path, a.needs_revision, a.reviewer_notes, q.question_text
            FROM pq_answers a
            JOIN pq_questions q ON a.question_id = q.id
            WHERE a.submission_id = $last_sub_id
        ");

        while ($ans = $ans_q->fetch_assoc()) {
            $norm_text = strtolower(trim($ans['question_text']));
            if (!isset($existing_answers[$norm_text])) {
                $existing_answers[$norm_text] = [
                    'score' => $ans['selected_score'],
                    'remarks' => $ans['remarks'],
                    'calc'  => $ans['calc_details'],
                    'file'  => $ans['attachment_path'],
                    'needs_revision' => $ans['needs_revision'],
                    'reviewer_notes' => $ans['reviewer_notes']
                ];
            } else {
                if ($ans['needs_revision']) $existing_answers[$norm_text]['needs_revision'] = 1;
                if (!empty($ans['reviewer_notes'])) $existing_answers[$norm_text]['reviewer_notes'] = $ans['reviewer_notes'];
            }
        }
    }
}

$page_title = "Penilaian Vendor";
include $path_to_root . 'includes/header.php';
?>

<style>
    body { background-color: #f8f9fa; }
    .option-card { border: 2px solid #e9ecef; border-radius: 12px; transition: all 0.2s ease-in-out; cursor: pointer; background-color: #fff; }
    .option-card:hover { border-color: #b8d4fe; background-color: #f8faff; transform: translateY(-2px); }
    .btn-check:checked + .option-card { border-color: #0d6efd; background-color: #e7f1ff; box-shadow: 0 4px 15px rgba(13, 110, 253, 0.15); }
    .btn-check:checked + .option-card .check-icon { color: #0d6efd !important; opacity: 1 !important; transform: scale(1.2); }
    .check-icon { opacity: 0.2; transition: all 0.2; }
    input[type=number]::-webkit-inner-spin-button, input[type=number]::-webkit-outer-spin-button { -webkit-appearance: none; margin: 0; }
    input[type=number] { -moz-appearance: textfield; }
    .question-block { padding: 1.5rem; border-radius: 12px; background: #fff; border: 1px solid #eaedf1; margin-bottom: 1.5rem; transition: box-shadow 0.3s; }
    .question-block:hover { box-shadow: 0 0.5rem 1rem rgba(0,0,0,0.05); }
    .question-block.flagged { border: 2px solid #dc3545; background: #fffafa; }
    .progress-container { height: 8px; background-color: #e9ecef; border-radius: 4px; overflow: hidden; }
    .progress-bar-fill { height: 100%; background-color: #0d6efd; transition: width 0.4s ease; }
    .modal-backdrop.show { backdrop-filter: blur(5px); opacity: 0.8; }

    /* Quick Ratio and Debt to Equity bands are output, not input: the calculator
       picks one and the vendor cannot. They stay permanently non-interactive —
       the radios are also `disabled`, this just makes that legible — while the
       band the calculator landed on keeps its normal selected styling so the
       result reads clearly. */
    .calc-locked .option-card { opacity: 0.45; cursor: not-allowed; background-color: #f8f9fa; pointer-events: none; }
    .calc-locked .option-card:hover { border-color: #e9ecef; background-color: #f8f9fa; transform: none; }
    .calc-locked .btn-check:checked + .option-card { opacity: 1; background-color: #e7f1ff; }

    /* File Size Toast */
    .file-size-toast { margin-top: 10px; background: #fff; border-radius: 14px; border: 1.5px solid #f5c6cb; overflow: hidden; animation: fstSlideIn 0.4s cubic-bezier(0.16,1,0.3,1); }
    .fst-progress-bar { height: 3px; background: linear-gradient(90deg, #dc3545, #fd7e14); animation: fstShrink 10s linear forwards; }
    .fst-body { padding: 12px 14px; display: flex; align-items: flex-start; gap: 12px; }
    .fst-icon { width: 38px; height: 38px; border-radius: 10px; background: linear-gradient(135deg, #dc3545, #c82333); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .fst-content { flex: 1; min-width: 0; }
    .fst-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 3px; }
    .fst-title { font-weight: 700; font-size: 0.78rem; color: #842029; }
    .fst-close { background: none; border: none; color: #adb5bd; cursor: pointer; font-size: 18px; padding: 0 2px; line-height: 1; transition: color 0.2s; }
    .fst-close:hover { color: #dc3545; }
    .fst-desc { font-size: 0.73rem; color: #6c757d; line-height: 1.5; margin-bottom: 6px; }
    .fst-desc strong { color: #212529; }
    .fst-badges { display: flex; gap: 6px; flex-wrap: wrap; }
    .fst-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 6px; font-size: 0.65rem; font-weight: 700; }
    .fst-badge-red { background: #f8d7da; color: #842029; }
    .fst-badge-green { background: #d1e7dd; color: #0f5132; }
    .fst-pulse { animation: fstPulse 1.5s ease infinite; }
    @keyframes fstSlideIn { from { opacity: 0; transform: translateY(-8px) scale(0.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
    @keyframes fstShrink { from { width: 100%; } to { width: 0%; } }
    @keyframes fstPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Penilaian Vendor</span>
        <div class="d-flex align-items-center">
            <div class="me-3 text-end d-none d-md-block">
                <div class="fw-bold small" style="color: var(--primary-color);"><?= htmlspecialchars($vendor['company_name']) ?></div>
                <div class="nav-muted" style="font-size: 0.7rem;">Vendor Portal &bull; <?= htmlspecialchars($vendor_type) ?></div>
            </div>
            <a href="dashboard.php" class="btn btn-sm btn-nav rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
        </div>
    </div>
</nav>

<div class="container pb-5" style="max-width: 900px;">
    <?php if(isset($_GET['msg'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-check-circle me-2"></i> <?= htmlspecialchars($_GET['msg']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php // Rejections bounced back here by submit_pq.php (e.g. Quick Ratio without the calculator). ?>
    <?php if(isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm mb-4" role="alert">
            <i class="fas fa-exclamation-triangle me-2"></i> <?= htmlspecialchars($_GET['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($current_status == 'revision_requested'): ?>
    <div class="alert alert-warning shadow-sm border-0 rounded-4 mb-4 position-relative overflow-hidden">
        <div class="position-absolute top-0 start-0 w-100 h-100 bg-warning opacity-10" style="z-index: 0;"></div>
        <div class="position-relative z-1 p-2">
            <h5 class="alert-heading fw-bold text-dark"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Butuh Revisi</h5>
            <p class="mb-3 text-dark">Tim Procurement telah meninjau pengajuan Anda dan meminta beberapa pembenahan. Mohon periksa kolom yang ditandai di bawah ini dan ajukan ulang.</p>
            <?php
                // Catatan langsung dari Vendor Management ke Vendor.
                // Format tersimpan: "DD Month YYYY HH.mm - Pesan ; nama VM" — nama VM disembunyikan
                // dari vendor dengan membuang bagian setelah " ; " terakhir pada tiap baris.
                $vendor_note_lines = [];
                foreach (explode("\n", (string)$notes_to_vendor) as $line) {
                    $line = trim($line);
                    if ($line === '') continue;
                    $sep = strrpos($line, ' ; ');
                    if ($sep !== false) $line = rtrim(substr($line, 0, $sep));
                    $vendor_note_lines[] = $line;
                }

                // "Catatan Umum dari Tim Procurement" = murni catatan ke vendor (notes_to_vendor).
                // Catatan Sourcing (sourcing_notes) & VM (vm_notes) TIDAK ditampilkan di sini.
                $combined_notes = trim(implode("\n", $vendor_note_lines));
            ?>
            <?php if(!empty($combined_notes)): ?>
                <div class="p-3 bg-white rounded-3 border border-warning shadow-sm text-dark mb-2">
                    <strong class="text-warning-emphasis"><i class="fas fa-comment-dots me-2"></i>Catatan Umum dari Tim Procurement:</strong><br>
                    <div class="mt-2"><?= nl2br(htmlspecialchars($combined_notes)) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php elseif ($current_status == 'returned_to_sourcing'): ?>
    <div class="alert alert-warning shadow-sm border-0 rounded-4 mb-4 position-relative overflow-hidden">
        <div class="position-absolute top-0 start-0 w-100 h-100 bg-warning opacity-10" style="z-index: 0;"></div>
        <div class="position-relative z-1 p-2">
            <h5 class="alert-heading fw-bold text-dark"><i class="fas fa-undo text-danger me-2"></i>Dikembalikan oleh Tim VM</h5>
            <p class="mb-3 text-dark">Tim Vendor Management mengembalikan pengajuan ini untuk ditinjau ulang. Periksa jawaban di bawah, lalu <strong>centang ulang kedua pernyataan persetujuan</strong> di bagian bawah formulir sebelum mengirim — keduanya wajib dicentang lagi setiap kali Anda mengirim ulang.</p>
            <?php if (!empty($vm_notes)): ?>
                <div class="p-3 bg-white rounded-3 border border-warning shadow-sm text-dark mb-2">
                    <strong class="text-warning-emphasis"><i class="fas fa-comment-dots me-2"></i>Catatan dari Tim VM:</strong><br>
                    <div class="mt-2"><?= nl2br(htmlspecialchars($vm_notes)) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="text-center mb-5">
        <h2 class="fw-bold text-dark mb-2">Formulir Pra-Kualifikasi</h2>
        <p class="text-muted">Mohon lengkapi semua kolom yang wajib diisi dengan akurat untuk membantu kami mengevaluasi perusahaan Anda.</p>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill shadow-sm">
            <i class="fas fa-briefcase me-1"></i> Kategori: <strong><?= htmlspecialchars($vendor_type) ?></strong>
        </span>
    </div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['impersonator']) && !empty($vendor['sub_company_type']) && $vendor_type !== 'Perseorangan'): ?>
    <div class="alert alert-primary border-0 shadow-sm rounded-4 d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <i class="fas fa-sitemap me-2"></i>
            Sub-Kategori Vendor: <strong class="ms-1"><?= htmlspecialchars($vendor['sub_company_type']) ?></strong>
            <span class="text-muted small ms-2">(pertanyaan di bawah sudah disesuaikan dengan kategori ini)</span>
        </div>
        <a href="#" data-bs-toggle="modal" data-bs-target="#confirmChangeCategoryModal" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold">
            <i class="fas fa-edit me-1"></i> Ubah Sub-Kategori
        </a>
    </div>

    <?php $has_saved_score = ($saved_risk_category !== null && $saved_risk_percentage !== null); ?>
    <?php if ($has_saved_score): ?>
    <div class="alert alert-light border shadow-sm rounded-4 d-flex align-items-center gap-2 mb-4">
        <i class="fas fa-chart-line text-muted"></i>
        <span class="text-muted small">Hasil Saat Ini:</span>
        <span class="fw-bold text-dark"><?= number_format((float)$saved_risk_percentage, 1) . '% — ' . htmlspecialchars($saved_risk_category) ?></span>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <form action="submit_pq.php" method="POST" enctype="multipart/form-data" id="pqForm">
        <input type="hidden" name="is_revision" value="<?= ($current_status == 'revision_requested' ? 1 : 0) ?>">

        <?php
        $legal_reviewer_notes_json = '';
        // Direct Registration: every pq_questions row maps to exactly one
        // pq_weight.sub_variant_target, so once Sourcing has declared the
        // vendor's sub-category (see the classify_vendor.php gate above),
        // narrow the form to only that category's questions. Normal
        // self-service vendors still see the full company_type-filtered set,
        // since sub_company_type isn't known until Sourcing reviews the PQ.
        $declared_sub_type = (!empty($_SESSION['impersonator']) && !$is_individual_foreman) ? ($vendor['sub_company_type'] ?? '') : '';
        if ($is_individual_foreman) {
            // Perseorangan (and self-declared UMKM) has its own standalone question
            // set (sub_variant_target = 'Perseorangan'); scope the form to exactly
            // those questions.
            $sql = "SELECT q.id, q.question_text, COALESCE(pw.help_text, q.help_text) as help_text, sc.section_name
                    FROM pq_questions q
                    INNER JOIN pq_weight pw ON pw.question_id = q.id AND pw.sub_variant_target = 'Perseorangan'
                    LEFT JOIN pq_sections sc ON q.section_id = sc.id
                    WHERE q.is_active = 1
                    ORDER BY sc.sort_order ASC, q.id ASC";
        } elseif ($declared_sub_type !== '') {
            $sql = "SELECT q.*, sc.section_name FROM pq_questions q
                    LEFT JOIN pq_sections sc ON q.section_id = sc.id
                    JOIN pq_weight w ON w.question_id = q.id
                    WHERE q.is_active = 1 AND (q.variant_target LIKE '%$vendor_type_for_query%' OR q.variant_target IS NULL OR q.variant_target = '')
                      AND w.sub_variant_target = '" . $conn->real_escape_string($declared_sub_type) . "'
                    ORDER BY sc.sort_order ASC, q.id ASC";
        } else {
            $sql = "SELECT q.*, sc.section_name FROM pq_questions q LEFT JOIN pq_sections sc ON q.section_id = sc.id WHERE q.is_active = 1 AND (q.variant_target LIKE '%$vendor_type_for_query%' OR q.variant_target IS NULL OR q.variant_target = '') ORDER BY sc.sort_order ASC, q.id ASC";
        }
        $result = $conn->query($sql);
        $grouped_questions = [];
        $seen_questions = []; 
        
        if ($result && $result->num_rows > 0) {
            while($row = $result->fetch_assoc()) {
                $q_text_normalized = strtolower(trim($row['question_text']));
                if (in_array($q_text_normalized, $seen_questions)) continue; 
                $seen_questions[] = $q_text_normalized;
                $sec = $row['section_name'] ?: 'Informasi Umum';
                $grouped_questions[$sec][] = $row;
            }
        }
        
        if (!empty($grouped_questions)): 
            $q_index = 1;
            foreach ($grouped_questions as $section_name => $questions): 
        ?>
            <div class="d-flex align-items-center mb-3 mt-5">
                <h4 class="fw-bold text-primary mb-0 me-3"><?= htmlspecialchars($section_name) ?></h4>
                <div class="flex-grow-1 border-bottom border-2 opacity-25"></div>
            </div>

            <?php foreach ($questions as $row): 
                $qid = $row['id'];
                $help_text = $row['help_text'];
                $norm_text = strtolower(trim($row['question_text']));
                
                $prev_score = $existing_answers[$norm_text]['score'] ?? null;
                $prev_remark = $existing_answers[$norm_text]['remarks'] ?? '';
                $prev_calc = pqCalcDecode($existing_answers[$norm_text]['calc'] ?? null);
                $prev_file = $existing_answers[$norm_text]['file'] ?? null;
                $is_flagged = $existing_answers[$norm_text]['needs_revision'] ?? 0;
                $reviewer_note = $existing_answers[$norm_text]['reviewer_notes'] ?? '';

                $flag_class = ($is_flagged && $current_status == 'revision_requested') ? 'flagged' : '';
                
                // Deteksi Kalkulator
                $is_quick_ratio = (strpos($norm_text, 'quick') !== false || strpos($norm_text, 'rasio cepat') !== false);
                $is_der = (strpos($norm_text, 'debt') !== false || preg_match('/\bder\b/', $norm_text) || strpos($norm_text, 'equity') !== false || strpos($norm_text, 'ekuitas') !== false);

                // Which calculator drives this question's answer, if any. Quick
                // Ratio wins a tie so a question can never be wired to both.
                // 'qr'/'der' are the prefixes every id, field name and JS helper
                // below is built from, so the two paths stay one code path.
                $calc_kind = $is_quick_ratio ? 'qr' : ($is_der ? 'der' : null);
                $is_calc   = ($calc_kind !== null);
                // Everything that differs between the two calculators, in one place.
                $calc_conf = [
                    'qr'  => ['label' => 'Quick Ratio',    'button' => 'Bantuan Hitung Quick Ratio',    'fields' => ['aset', 'persediaan', 'kewajiban']],
                    'der' => ['label' => 'Debt to Equity', 'button' => 'Bantuan Hitung Debt to Equity', 'fields' => ['utang', 'ekuitas']],
                ];
                $calc_label  = $is_calc ? $calc_conf[$calc_kind]['label'] : '';
                $calc_button = $is_calc ? $calc_conf[$calc_kind]['button'] : '';

                // Saved figures only count if they came from THIS question's
                // calculator -- a Quick Ratio record sitting on the Debt to Equity
                // question has no fields to prefill, so it is treated as absent.
                $calc_types = ['qr' => 'quick_ratio', 'der' => 'debt_to_equity'];
                if ($prev_calc !== null && (!$is_calc || $prev_calc['type'] !== $calc_types[$calc_kind])) {
                    $prev_calc = null;
                }
                // Neither calculated question may be answered by hand: the bands
                // stay locked until the calculator has produced a ratio. A
                // submission carried over from before the calculators existed has
                // no figures, so it starts locked too and has to be recalculated
                // on resubmit.
                $calc_locked = ($prev_calc === null);

                // --- PERBAIKAN DETEKSI PERTANYAAN KHUSUS ---
                $is_legalitas = (strpos($norm_text, 'legalitas') !== false);
                $is_laporan = (strpos($norm_text, 'laporan keuangan') !== false);
                // 1. Perbaikan kata kunci deteksi tempo (Apapun yang ada "tempo" dan "pembayaran/bayar")
                $is_tempo = (strpos($norm_text, 'tempo') !== false && (strpos($norm_text, 'pembayaran') !== false || strpos($norm_text, 'bayar') !== false));
                $is_fin = (strpos($norm_text, 'capital') !== false || strpos($norm_text, 'income') !== false || strpos($norm_text, 'modal') !== false || strpos($norm_text, 'pendapatan') !== false);
                
                // 2. Perbaikan perluasan kata kunci deteksi pengalaman untuk Supplier & Contractor
                $is_experience = (strpos($norm_text, 'pengalaman') !== false || 
                                  strpos($norm_text, 'proyek') !== false || 
                                  strpos($norm_text, 'project') !== false || 
                                  strpos($norm_text, 'pekerjaan') !== false || 
                                  strpos($norm_text, 'pelanggan') !== false || 
                                  strpos($norm_text, 'klien') !== false || 
                                  strpos($norm_text, 'riwayat') !== false);
                
                // Hybrid: has radio options BUT also needs a conditional free-text list
                // e.g. "Riwayat Proyek Sejenis" — show text rows only when score=5 is chosen
                $is_client_history = false; // resolved below after $options is built

                if ($is_legalitas) { $legal_reviewer_notes_json = $reviewer_note; }
            ?>
                
                <div class="question-block <?= $flag_class ?> shadow-sm" data-qid="<?= $qid ?>">
                    
                    <?php if ($is_flagged && $current_status == 'revision_requested' && !$is_legalitas): ?>
                        <div class="alert alert-danger py-2 px-3 small fw-bold mb-3 d-flex align-items-start">
                            <i class="fas fa-flag mt-1 me-2"></i>
                            <div>
                                <div class="text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Tindakan Diperlukan</div>
                                <?= !empty($reviewer_note) ? nl2br(htmlspecialchars($reviewer_note)) : 'Mohon tinjau dan perbarui jawaban ini.' ?>
                            </div>
                        </div>
                    <?php elseif ($is_flagged && $current_status == 'revision_requested' && $is_legalitas): ?>
                        <div class="alert alert-danger py-2 px-3 small fw-bold mb-3 d-flex align-items-start">
                            <i class="fas fa-flag mt-1 me-2"></i>
                            <div>
                                <div class="text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.5px;">Dokumen Anda Butuh Revisi</div>
                                Procurement meminta perbaikan pada dokumen profil Anda. Silakan klik tombol <b>Cek Dokumen Saya</b> di bawah.
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="d-flex mb-3">
                        <div class="text-muted fw-bold me-3 fs-5"><?= $q_index ?>.</div>
                        <div>
                            <h5 class="fw-bold text-dark mb-1" style="line-height: 1.4;"><?= htmlspecialchars($row['question_text']) ?></h5>
                            <?php if ($is_calc): ?>
                                <button type="button" class="btn btn-sm btn-info text-white mt-1 mb-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modal<?= strtoupper($calc_kind) ?>" onclick="openCalc('<?= $calc_kind ?>', <?= $qid ?>)"><i class="fas fa-calculator me-1"></i> <?= htmlspecialchars($calc_button) ?></button>

                                <?php
                                // The figures behind the ratio, carried with the answer so
                                // Sourcing/VM can check the percentage against the vendor's
                                // financial statement. Filled by hitungCalc(); never scored.
                                ?>
                                <?php foreach ($calc_conf[$calc_kind]['fields'] as $calc_field): ?>
                                <input type="hidden" name="calc_<?= $calc_kind ?>[<?= $qid ?>][<?= $calc_field ?>]" id="<?= $calc_kind ?>_val_<?= $calc_field ?>_<?= $qid ?>" value="<?= $prev_calc ? (int) $prev_calc['amounts'][$calc_field] : '' ?>">
                                <?php endforeach; ?>

                                <div id="<?= $calc_kind ?>_summary_<?= $qid ?>" class="mt-1 mb-2 p-2 bg-info bg-opacity-10 border border-info border-opacity-25 rounded-3 small <?= $prev_calc ? '' : 'd-none' ?>" style="max-width: 420px;">
                                    <div class="fw-bold text-dark mb-1" style="font-size: 0.7rem;">
                                        <i class="fas fa-check-circle text-info me-1"></i>Rincian perhitungan Anda (ikut terkirim ke Procurement)
                                    </div>
                                    <div class="text-muted" id="<?= $calc_kind ?>_summary_text_<?= $qid ?>" style="font-size: 0.72rem; line-height: 1.6;">
                                        <?php if ($prev_calc): ?>
                                            <?php foreach ($prev_calc['rows'] as $calc_row_label => $calc_row_amount): ?>
                                                <?= htmlspecialchars($calc_row_label) ?>: <b>Rp <?= number_format($calc_row_amount, 0, ',', '.') ?></b><br>
                                            <?php endforeach; ?>
                                            <?= htmlspecialchars($calc_label) ?>: <b class="text-info"><?= number_format($prev_calc['result'], 2, ',', '.') ?>%</b>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ps-md-5">
                        <?php 
                        $options = [];
                        if (!empty($help_text)) {
                            preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $help_text, $matches, PREG_SET_ORDER);
                            foreach ($matches as $m) $options[$m[1]] = trim($m[2]);
                        }

                        // --- 1. RENDER OPSI PILIHAN GANDA ---
                        if (!empty($options) && !$is_fin) { 
                            $show_auditor_box = false;
                            $must_attach = false;
                            // Hybrid: experience + opsi radio → free-text list conditional
                            $is_client_history = $is_experience;
                            $show_history_box  = ($is_client_history && (int)$prev_score === 5);
                        ?>
                            <?php if ($is_calc): ?>
                            <?php
                            // Quick Ratio and Debt to Equity are not questions the vendor
                            // answers by choosing. The band below is output, not input: it is
                            // set from whatever the calculator computes and can only be
                            // changed by editing the figures and recalculating. So the radios
                            // are permanently disabled (display only, never submitted) and the
                            // score that actually reaches submit_pq.php travels in this hidden
                            // field.
                            //
                            // calc_<kind>_required[] tells submit_pq.php the bands really were
                            // shown, so a submit that omits the answer is rejected rather
                            // than scored as unanswered. Emitted inside the options branch,
                            // so a calculated question rendered without bands is never demanded.
                            ?>
                            <input type="hidden" name="calc_<?= $calc_kind ?>_required[]" value="<?= $qid ?>">
                            <?php
                            // Disabled while empty so it does not submit at all: an unanswered
                            // calculated question must stay absent from $_POST['answers']
                            // exactly as an unchecked radio would be, or a half-filled draft
                            // would save a bogus score-0 answer row. applyCalcBand() enables it.
                            ?>
                            <input type="hidden" name="answers[<?= $qid ?>]" id="<?= $calc_kind ?>_score_<?= $qid ?>"
                                   value="<?= ($prev_calc && $prev_score !== null) ? htmlspecialchars($prev_score) : '' ?>"
                                   <?= ($prev_calc && $prev_score !== null) ? '' : 'disabled' ?>>

                            <div id="<?= $calc_kind ?>_lock_notice_<?= $qid ?>" class="alert <?= $calc_locked ? 'alert-warning' : 'alert-info' ?> py-2 px-3 small mb-3 d-flex align-items-start">
                                <i class="fas fa-<?= $calc_locked ? 'lock' : 'circle-info' ?> mt-1 me-2"></i>
                                <div id="<?= $calc_kind ?>_lock_notice_text_<?= $qid ?>">
                                    <?php if ($calc_locked): ?>
                                        Gunakan tombol <b><?= htmlspecialchars($calc_button) ?></b> di atas terlebih dahulu.
                                        Opsi di bawah terisi otomatis dari hasil perhitungan dan tidak dapat dipilih manual.
                                    <?php else: ?>
                                        Opsi di bawah terisi otomatis dari hasil perhitungan Anda.
                                        Untuk mengubahnya, buka kembali <b><?= htmlspecialchars($calc_button) ?></b> dan perbarui angkanya.
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="row g-3 mb-3<?= $is_calc ? ' calc-locked' : '' ?>"<?= $is_calc ? ' id="' . $calc_kind . '_options_' . $qid . '"' : '' ?>>
                                <?php foreach ($options as $score => $label):
                                    $unique_id = "q" . $qid . "_opt" . $score;
                                    $checked = ($prev_score !== null && $prev_score == $score) ? 'checked' : '';

                                    // Calculated bands are display-only, driven by the
                                    // calculator: they carry their own group name so they can
                                    // never be mistaken for the hidden answers[] field that
                                    // actually posts the score. A saved band with no figures
                                    // behind it (answered before the calculator existed) is
                                    // not shown as selected -- it has to be recalculated.
                                    $band_name = $is_calc ? "{$calc_kind}_band_$qid" : "answers[$qid]";
                                    if ($is_calc && !$prev_calc) $checked = '';

                                    // Deteksi Opsi
                                    $is_audited_option = (stripos($label, 'audit') !== false && stripos($label, 'belum') === false && stripos($label, 'non') === false) ? 'true' : 'false';
                                    $is_tidak_option = (stripos($label, 'tidak') !== false) ? 'true' : 'false'; // Khusus pertanyaan Tempo
                                    
                                    if ($checked === 'checked' && $is_laporan) {
                                        if ($is_audited_option === 'true') $show_auditor_box = true;
                                        if ($score > 1) $must_attach = true; 
                                    }
                                    
                                    $js_trigger = "onchange=\"updateProgress()\"";
                                    // Client history: toggle text list
                                    if ($is_client_history) $js_trigger = "onchange=\"toggleClientHistoryBox($qid, this.value)\"";
                                    // 3. Perbaikan pemanggilan trigger Tempo (mengirimkan status apakah ini opsi "Tidak")
                                    if ($is_tempo) $js_trigger = "onchange=\"toggleReasonBox($qid, $is_tidak_option)\"";
                                    if ($is_laporan) $js_trigger = "onchange=\"toggleLaporanBox($qid, $score, $is_audited_option)\"";
                                ?>
                                    <div class="col-md-6">
                                        <input type="radio" class="btn-check answer-input" name="<?= $band_name ?>" id="<?= $unique_id ?>" value="<?= $score ?>" <?= $checked ?> <?= $is_calc ? 'disabled' : 'required ' . $js_trigger ?>>
                                        <label class="option-card h-100 p-3 w-100 d-flex align-items-center m-0" for="<?= $unique_id ?>">
                                            <i class="fas fa-check-circle fs-4 me-3 text-muted check-icon"></i>
                                            <span class="fw-medium text-dark"><?= htmlspecialchars($label) ?></span>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php 
                            // 4. Perbaikan kotak alasan Tempo agar dirender jika "Tidak" pernah dipilih sebelumnya
                            if ($is_tempo): 
                                $show_reason_box = false;
                                if ($prev_score !== null && isset($options[$prev_score]) && stripos($options[$prev_score], 'tidak') !== false) {
                                    $show_reason_box = true;
                                }
                            ?>
                            <div id="reason_box_<?= $qid ?>" class="mt-2 p-3 bg-danger bg-opacity-10 border border-danger border-opacity-25 rounded-3 <?= ($show_reason_box) ? '' : 'd-none' ?>">
                                <label class="form-label small fw-bold text-danger mb-2"><i class="fas fa-pen me-1"></i> Mohon berikan alasan Anda <span class="text-danger">*</span></label>
                                <input type="text" name="remarks[<?= $qid ?>]" id="reason_input_<?= $qid ?>" class="form-control border-danger border-opacity-50" value="<?= htmlspecialchars($prev_remark) ?>" placeholder="Tuliskan alasan mengapa tidak bersedia..." <?= ($show_reason_box) ? 'required' : '' ?>>
                            </div>
                            <?php endif; ?>

                            <?php if ($is_laporan): ?>
                            <div id="auditor_box_<?= $qid ?>" class="mt-2 p-3 bg-primary rounded-3 shadow-sm <?= ($show_auditor_box) ? '' : 'd-none' ?>">
                                <label class="form-label small fw-bold text-white mb-2"><i class="fas fa-building me-1"></i> Nama Lembaga Auditor / KAP <span class="text-white">*</span></label>
                                <input type="text" name="remarks[<?= $qid ?>]" id="auditor_input_<?= $qid ?>" class="form-control border-0 shadow-sm" value="<?= htmlspecialchars($prev_remark) ?>" placeholder="Contoh: KAP Purwantono, Sungkoro & Surja (EY)" <?= ($show_auditor_box) ? 'required' : '' ?>>
                            </div>
                            <?php endif; ?>

                            <?php if ($is_client_history): ?>
                            <div id="client_history_box_<?= $qid ?>" class="mt-3 p-4 bg-light border border-primary border-opacity-25 rounded-4 shadow-sm <?= $show_history_box ? '' : 'd-none' ?>">
                                <label class="form-label small fw-bold text-dark mb-3">
                                    <i class="fas fa-list-ul text-primary me-2"></i>Daftar Rincian Klien / Proyek <span class="text-danger">*</span>
                                </label>
                                <div id="dynamic_text_container_<?= $qid ?>">
                                    <?php
                                    $ch_items = ($show_history_box && !empty($prev_remark)) ? explode("\n", $prev_remark) : [''];
                                    foreach ($ch_items as $ch_idx => $ch_val): ?>
                                    <div class="mb-2">
                                        <div class="input-group dynamic-text-row shadow-sm rounded-3 overflow-hidden bg-white">
                                            <input type="text"
                                                   name="remarks_multi[<?= $qid ?>][]"
                                                   class="form-control answer-input border-0 py-2 project-input"
                                                   value="<?= htmlspecialchars(trim($ch_val)) ?>"
                                                   placeholder="Cth: Suplai material PT ABC (2024)"
                                                   <?= $show_history_box ? 'required' : '' ?>
                                                   onblur="validateProjectAI(this)">
                                            <?php if ($ch_idx > 0): ?>
                                                <button type="button" class="btn btn-danger px-3" title="Hapus"
                                                        onclick="this.closest('.mb-2').remove(); updateProgress();">
                                                    <i class="fas fa-times"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="ai-feedback small ms-2 mt-1" style="display:none;"></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary mt-2 rounded-pill px-3 fw-bold"
                                        onclick="addTextRow(<?= $qid ?>, true)">
                                    <i class="fas fa-plus me-1"></i> Tambah Baris
                                </button>
                            </div>
                            <?php endif; ?>
                        <?php 
                        }

                        // --- 2. RENDER ISIAN KEUANGAN ---
                        if ($is_fin) { 
                        ?>
                            <div class="mb-3">
                                <label class="form-label small fw-bold text-muted">Nominal (Rp) <span class="text-danger">*</span></label>
                                <div class="input-group input-group-lg shadow-sm rounded-3 overflow-hidden">
                                    <span class="input-group-text bg-light border-end-0 fw-bold text-secondary px-4">Rp</span>
                                    <input type="text" name="remarks[<?= $qid ?>]" class="form-control border-start-0 ps-0 answer-input currency-input fw-bold text-primary" placeholder="0" value="<?= htmlspecialchars($prev_remark) ?>" required autocomplete="off">
                                </div>
                            </div>
                        <?php 
                        } 

                        // --- 3. RENDER DYNAMIC TEXT PENGALAMAN ---
                        if ((empty($options) && !$is_fin) || ($is_experience && !$is_client_history)) { 
                        ?>
                            <div class="mb-3 <?= (!empty($options) ? 'mt-4 p-4 bg-light border border-secondary border-opacity-25 rounded-4 shadow-sm' : '') ?>">
                                <?php if (!empty($options)): ?>
                                    <label class="form-label small fw-bold text-dark mb-3"><i class="fas fa-list-ul text-primary me-2"></i>Daftar Rincian Pengalaman Proyek/Klien <span class="text-danger">*</span></label>
                                <?php else: ?>
                                    <label class="form-label small fw-bold text-muted">Mohon berikan rincian <span class="text-danger">*</span></label>
                                <?php endif; ?>
                                <div id="dynamic_text_container_<?= $qid ?>">
                                    <?php 
                                    $items = !empty($prev_remark) ? explode("\n", $prev_remark) : ['']; 
                                    foreach($items as $idx => $val):
                                    ?>
                                    <div class="mb-2">
                                        <div class="input-group dynamic-text-row shadow-sm rounded-3 overflow-hidden bg-white">
                                            <input type="text" name="remarks_multi[<?= $qid ?>][]" class="form-control answer-input border-0 py-2 project-input" value="<?= htmlspecialchars(trim($val)) ?>" placeholder="<?= $is_experience ? 'Cth: Pekerjaan / Suplai Material PT ABC (2024)' : 'Masukkan rincian...' ?>" required <?= $is_experience ? 'onblur="validateProjectAI(this)"' : '' ?>>
                                            <?php if($idx > 0): ?><button type="button" class="btn btn-danger px-3" title="Hapus" onclick="this.closest('.mb-2').remove(); updateProgress();"><i class="fas fa-times"></i></button><?php endif; ?>
                                        </div>
                                        <?php if ($is_experience): ?><div class="ai-feedback small ms-2 mt-1" style="display:none;"></div><?php endif; ?>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-primary mt-2 rounded-pill px-3 fw-bold" onclick="addTextRow(<?= $qid ?>, <?= $is_experience ? 'true' : 'false' ?>)"><i class="fas fa-plus me-1"></i> Tambah Baris</button>
                            </div>
                        <?php 
                        } 

                        // --- 4. RENDER UPLOAD DOKUMEN ---
                        if (!$is_tempo && !$is_legalitas) {
                            $is_laporan_required = ($is_laporan && isset($must_attach) && $must_attach) ? true : false;
                            $doc_label = $is_laporan_required ? '<i class="fas fa-paperclip me-2 text-primary"></i> Dokumen Pendukung <span class="text-danger">*</span>' : '<i class="fas fa-paperclip me-2 text-primary"></i> Dokumen Pendukung';
                        ?>
                        <div class="mt-4 p-3 bg-light rounded-4 border">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <label id="label_doc_<?= $qid ?>" class="fw-bold text-dark mb-0 small"><?= $doc_label ?></label>
                                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="addFileInput(<?= $qid ?>)"><i class="fas fa-plus me-1"></i> Tambah Dokumen</button>
                            </div>
                            
                            <?php if ($prev_file): ?>
                                <div class="row g-2 mb-3">
                                <?php 
                                $files = explode(',', $prev_file);
                                foreach($files as $idx => $f): 
                                    $f = trim($f); if(empty($f)) continue;
                                ?>
                                    <div class="col-sm-6">
                                        <div class="d-flex justify-content-between align-items-center p-2 border rounded-3 bg-white shadow-sm">
                                            <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($f) ?>" target="_blank" class="text-truncate text-decoration-none small fw-bold text-primary" style="max-width: 80%;"><i class="fas fa-file-alt me-2 text-danger"></i>File <?= $idx + 1 ?></a>
                                            <input type="hidden" name="existing_attachments[<?= $qid ?>][]" value="<?= htmlspecialchars($f) ?>">
                                            <button type="button" class="btn btn-sm btn-light text-danger rounded-circle p-1" style="width: 28px; height: 28px; line-height: 1;" onclick="this.parentElement.parentElement.remove()" title="Hapus"><i class="fas fa-times"></i></button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div id="file_inputs_<?= $qid ?>">
                                <div class="input-group input-group-sm mb-2 shadow-sm rounded-3 overflow-hidden bg-white">
                                    <input type="file" name="attachment_<?= $qid ?>[]" class="form-control px-3 py-2 border-0 pq-file-input" <?= ($is_laporan_required && empty($prev_file)) ? 'required' : '' ?>>
                                </div>
                            </div>
                        </div>
                        <?php } ?>

                        <?php 
                        // --- 5. KHUSUS LEGALITAS ---
                        if ($is_legalitas) { 
                        ?>
                        <div class="mt-4 p-4 bg-light rounded-4 border border-primary border-opacity-25 text-center shadow-sm position-relative overflow-hidden <?= ($is_flagged && $current_status == 'revision_requested') ? 'border-danger border-opacity-50' : '' ?>">
                            <div class="position-absolute top-50 start-0 translate-middle-y text-primary ms-3" style="z-index: 0; opacity: 0.04;"><i class="fas fa-shield-alt" style="font-size: 6rem;"></i></div>
                            <div class="position-relative z-1">
                                <h6 class="fw-bold text-dark mb-2">Dokumen Terhubung Otomatis</h6>
                                <p class="small text-muted mb-3">Seluruh dokumen legal telah ditarik langsung dari profil Anda. Tidak perlu mengunggah ulang.</p>
                                <button type="button" class="btn <?= ($is_flagged && $current_status == 'revision_requested') ? 'btn-danger' : 'btn-primary' ?> rounded-pill px-4 py-2 fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalProfileDocs">
                                    <i class="fas <?= ($is_flagged && $current_status == 'revision_requested') ? 'fa-exclamation-circle' : 'fa-search' ?> me-2"></i> Cek Dokumen Saya
                                </button>
                            </div>
                        </div>
                        <?php } ?>

                    </div>
                </div>
            <?php $q_index++; endforeach; ?>
        <?php 
            endforeach; 
        else: 
        ?>
            <div class="alert alert-secondary text-center py-5 rounded-4"><i class="fas fa-clipboard fa-3x text-muted opacity-25 mb-3"></i><h5>Penilaian Tidak Ditemukan</h5></div>
        <?php endif; ?>

        <?php if (!empty($grouped_questions)): ?>
        <div class="card border-0 shadow-sm rounded-4 mt-5 mb-4" style="border-left: 5px solid #198754 !important;">
            <div class="card-body p-4">
                <h5 class="fw-bold text-success mb-3"><i class="fas fa-file-contract me-2"></i>Pernyataan Integritas & Persetujuan</h5>
                
                <div class="d-flex align-items-start mt-3 p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3 mb-2">
                    <input class="form-check-input flex-shrink-0 border-success shadow-sm mt-1" type="checkbox" id="declarationCheck1" name="declaration_check1" style="width: 1.5rem; height: 1.5rem; cursor: pointer;" required>
                    <label class="form-check-label text-dark fw-bold user-select-none ms-3" for="declarationCheck1" style="font-size: 0.9rem; cursor: pointer; line-height: 1.5;">
                        Saya menyatakan bahwa semua informasi dan dokumen yang saya berikan dalam formulir ini adalah benar, akurat, dan dapat dipertanggungjawabkan secara hukum.
                    </label>
                </div>

                <div class="d-flex align-items-start p-3 bg-success bg-opacity-10 border border-success border-opacity-25 rounded-3">
                    <input class="form-check-input flex-shrink-0 border-success shadow-sm mt-1" type="checkbox" id="declarationCheck2" name="declaration_check2" style="width: 1.5rem; height: 1.5rem; cursor: pointer;" required>
                    <label class="form-check-label text-dark fw-bold user-select-none ms-3" for="declarationCheck2" style="font-size: 0.9rem; cursor: pointer; line-height: 1.5;">
                        Saya memberikan persetujuan dan wewenang penuh kepada tim Procurement Kawan Lama Group untuk melakukan penyesuaian atau koreksi atas data isian saya, apabila ditemukan ketidaksesuaian dengan dokumen resmi yang telah saya lampirkan.
                    </label>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="position-fixed bottom-0 start-0 w-100 bg-white border-top shadow-lg p-3" style="z-index: 1030;">
            <div class="container" style="max-width: 900px;">
                <div class="row align-items-center">
                    <div class="col-md-5 mb-3 mb-md-0 d-none d-sm-block">
                        <div class="d-flex justify-content-between mb-1">
                            <small class="fw-bold text-muted text-uppercase" style="letter-spacing: 1px; font-size: 0.7rem;">Progres Pengisian</small>
                            <small class="fw-bold text-primary" id="progress-text">0%</small>
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar-fill" id="progress-bar" style="width: 0%;"></div>
                        </div>
                    </div>
                    <div class="col-md-7 text-end">
                        <button type="button" class="btn btn-outline-primary fw-bold px-4 rounded-pill shadow-sm me-2" onclick="executeSaveDraft()"><i class="fas fa-save me-1"></i> Simpan</button>
                        <button type="button" id="btnInitiateSubmit" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm"><i class="fas fa-paper-plane me-1"></i> <?= ($current_status == 'revision_requested') ? 'Kirim Ulang' : 'Kirim Form' ?></button>
                    </div>
                </div>
            </div>
        </div>
        <div style="height: 100px;"></div>
    </form>
</div>

<?php
$legal_notes_data = ['docs' => [], 'general' => ''];
if (!empty($legal_reviewer_notes_json)) {
    $decoded = json_decode($legal_reviewer_notes_json, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        if (isset($decoded['docs'])) $legal_notes_data = $decoded;
        else $legal_notes_data['general'] = $legal_reviewer_notes_json;
    } else {
        $legal_notes_data['general'] = $legal_reviewer_notes_json;
    }
}
?>
<div class="modal fade" id="modalProfileDocs" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-folder-open me-2"></i>Dokumen Legal Perusahaan</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 bg-light">
                <p class="text-muted small mb-4 text-center">Berikut adalah dokumen legal yang telah Anda unggah pada halaman Profil.</p>
                <?php if (!empty($legal_notes_data['general'])): ?>
                    <div class="alert alert-warning border-0 shadow-sm mb-4 d-flex align-items-start rounded-4">
                        <i class="fas fa-exclamation-triangle text-warning-emphasis fs-4 mt-1 me-3"></i>
                        <div>
                            <strong class="text-dark d-block mb-1">Catatan Umum Legalitas:</strong>
                            <span class="text-dark small" style="line-height: 1.5;"><?= nl2br(htmlspecialchars($legal_notes_data['general'])) ?></span>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="row g-3">
                    <?php
                    $legal_docs = [
                        'KTP Direktur' => $vendor['director_id_card_file'] ?? '',
                        'NPWP' => $vendor['npwp_path'] ?? '',
                        'Buku Rekening' => $vendor['bank_cover_path'] ?? '',
                        'Surat Pernyataan Perbedaan Nama Rekening' => $vendor['bank_holder_statement_letter_file'] ?? '',
                        'Komitmen Etika Bisnis' => $vendor['business_ethics_commitment_file'] ?? '',
                        'Tax Statement Letter' => $vendor['tax_statement_letter_file'] ?? '',
                        'Profil Perusahaan' => $vendor['company_profile_file'] ?? '',
                        'NIB' => $vendor['nib_path'] ?? '',
                        'Akta Pendirian' => $vendor['deed_of_establishment_file'] ?? '',
                        'SK Pengesahan' => $vendor['decree_of_legal_entity_file'] ?? '',
                        'Akta Perubahan' => $vendor['deed_of_amendment_file'] ?? '',
                        'SPPKP' => $vendor['sppkp_path'] ?? '',
                        'Sertifikat Badan Usaha Jasa Konstruksi (SBUJK)' => $vendor['business_entity_cert_file'] ?? '',
                        'Sertifikasi UMKM' => $vendor['umkm_cert_file'] ?? ''
                    ];
                    $has_doc = false;
                    foreach($legal_docs as $docName => $docPath):
                        if (!empty($docPath)):
                            $has_doc = true;
                            $specific_note = $legal_notes_data['docs'][$docName] ?? '';
                            $has_note = !empty($specific_note);
                            $card_border = $has_note ? 'border-danger border-2 shadow' : 'border-0 shadow-sm';
                            $bg_icon = $has_note ? 'bg-danger' : 'bg-primary';
                    ?>
                        <div class="col-md-6">
                            <div class="card <?= $card_border ?> h-100 rounded-3">
                                <div class="card-body p-3">
                                    <div class="d-flex align-items-center justify-content-between mb-2">
                                        <div class="d-flex align-items-center">
                                            <div class="d-flex align-items-center justify-content-center <?= $bg_icon ?> rounded-circle me-3 shadow-sm" style="width: 42px; height: 42px; min-width: 42px;"><i class="fas fa-file-alt text-white fs-6"></i></div>
                                            <span class="fw-bold text-dark" style="font-size: 0.9rem;"><?= $docName ?></span>
                                        </div>
                                        <a href="<?= $path_to_root ?>../uploads/<?= htmlspecialchars($docPath) ?>" target="_blank" class="btn btn-sm btn-outline-primary rounded-pill px-3 fw-bold flex-shrink-0">Lihat</a>
                                    </div>
                                    <?php if ($has_note): ?>
                                        <div class="mt-3 p-2 bg-danger bg-opacity-10 rounded border border-danger border-opacity-25 d-flex align-items-start">
                                            <i class="fas fa-pen text-danger mt-1 me-2" style="font-size: 0.8rem;"></i>
                                            <div>
                                                <div class="small fw-bold text-danger text-uppercase mb-1" style="font-size: 0.65rem;">Instruksi Revisi Procurement:</div>
                                                <div class="small text-dark" style="line-height: 1.4;"><?= nl2br(htmlspecialchars($specific_note)) ?></div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; endforeach; ?>
                </div>
            </div>
            <div class="modal-footer border-0 d-flex justify-content-center bg-white py-3">
                <?php if ($is_flagged && $current_status == 'revision_requested'): ?>
                    <a href="profile.php?tab=docs" class="btn btn-warning rounded-pill px-5 fw-bold shadow-sm me-2"><i class="fas fa-upload me-1"></i> Perbaiki di Profil</a>
                <?php endif; ?>
                <button type="button" class="btn btn-secondary rounded-pill px-5 fw-bold shadow-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="disclaimerModal" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden" style="border-top: 5px solid #0d6efd !important;">
            <div class="modal-header bg-white border-0 py-3 pb-0 text-center d-block">
                <div class="mb-3 mt-2"><i class="fas fa-user-shield fa-4x text-primary opacity-75"></i></div>
                <h4 class="modal-title fw-bold text-primary">Jaminan Kerahasiaan Data</h4>
            </div>
            <div class="modal-body p-4 pt-3">
                <p class="mb-3 text-dark text-center" style="font-size: 0.95rem; line-height: 1.6;">
                    Formulir Pra-Kualifikasi ini merupakan tahapan <strong>penilaian risiko</strong> bagi vendor di internal Procurement.
                </p>
                <div class="p-3 bg-light rounded-3 border border-secondary border-opacity-25 mb-4">
                    <p class="mb-0 text-dark text-center" style="font-size: 0.9rem; line-height: 1.6;">
                        Seluruh data keuangan dan dokumen bisnis yang Anda ajukan bersifat rahasia dan <strong>HANYA digunakan untuk keperluan internal Corporate Procurement Kawan Lama Group</strong>. Kami menjamin bahwa pengisian data ini <strong>TIDAK TERKAIT dengan instansi perpajakan</strong>.
                    </p>
                </div>
                <div class="form-check d-flex justify-content-center align-items-center bg-white p-2 rounded">
                    <input class="form-check-input me-2 border-primary" type="checkbox" id="checkAgreeDisclaimer" style="transform: scale(1.3); cursor: pointer;">
                    <label class="form-check-label text-dark fw-bold user-select-none" for="checkAgreeDisclaimer" style="font-size: 0.9rem; cursor: pointer;">
                        Saya telah membaca dan memahami pernyataan di atas.
                    </label>
                </div>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-secondary fw-bold px-5 py-2 rounded-pill shadow-sm w-100" data-bs-dismiss="modal" id="btnAgreeDisclaimer" disabled>Saya Mengerti & Setuju</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="confirmSubmitModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="fas fa-question-circle me-2"></i>Konfirmasi Pengiriman</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-4"><i class="fas fa-paper-plane fa-4x text-primary opacity-50"></i></div>
                <h4 class="fw-bold text-dark mb-3">Siap untuk dikirim?</h4>
                <p class="text-muted">Apakah Anda yakin ingin mengirimkan Penilaian Pra-Kualifikasi ini?</p>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Kembali</button>
                <button type="button" class="btn btn-primary fw-bold px-4 rounded-pill" onclick="executeFinalSubmit()">Ya, Kirim Sekarang</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalQR" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-calculator me-2"></i>Kalkulator Quick Ratio</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 border mb-4 d-flex flex-column align-items-center shadow-sm">
                    <span class="badge bg-secondary mb-2"><i class="fas fa-info-circle me-1"></i>Formula</span>
                    <div class="d-flex align-items-center text-dark mt-1">
                        <div class="fw-bold me-2">QR = </div>
                        <div class="text-center">
                            <div class="border-bottom border-dark border-2 px-3 pb-1 fw-bold" style="font-size: 0.85rem;">(Aset Lancar - Persediaan)</div>
                            <div class="pt-1 fw-bold text-secondary" style="font-size: 0.85rem;">Kewajiban Lancar</div>
                        </div>
                        <div class="fw-bold ms-2"> × 100%</div>
                    </div>
                </div>
                <div class="mb-3"><label class="form-label small fw-bold text-dark">Total Aset Lancar</label><div class="input-group"><span class="input-group-text bg-light fw-bold">Rp</span><input type="text" id="qr_aset" class="form-control calc-input text-primary fw-bold" placeholder="0"></div></div>
                <div class="mb-3"><label class="form-label small fw-bold text-dark">Total Persediaan</label><div class="input-group"><span class="input-group-text bg-light fw-bold">Rp</span><input type="text" id="qr_persediaan" class="form-control calc-input text-primary fw-bold" placeholder="0"></div></div>
                <div class="mb-4"><label class="form-label small fw-bold text-dark">Kewajiban Lancar</label><div class="input-group"><span class="input-group-text bg-light fw-bold">Rp</span><input type="text" id="qr_kewajiban" class="form-control calc-input text-primary fw-bold" placeholder="0"></div></div>
                <div class="d-grid mb-3"><button type="button" class="btn btn-primary fw-bold rounded-pill shadow-sm" onclick="hitungCalc('qr')">Hitung Otomatis</button></div>
                <div id="qr_result_box" class="alert alert-success d-none text-center mb-0 rounded-3 shadow-sm border-0"><div class="small fw-bold text-success mb-1">Hasil:</div><h1 class="fw-bold text-success mb-3" id="qr_result_val">0,00%</h1><button type="button" class="btn btn-success fw-bold rounded-pill px-4 mb-2 shadow-sm w-100" onclick="applyCalcResult('modalQR')"><i class="fas fa-check-circle me-1"></i> Terapkan Hasil</button></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalDER" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-primary border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-calculator me-2"></i>Kalkulator Debt to Equity</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div class="p-3 bg-light rounded-3 border mb-4 d-flex flex-column align-items-center shadow-sm">
                    <span class="badge bg-secondary mb-2"><i class="fas fa-info-circle me-1"></i>Formula</span>
                    <div class="d-flex align-items-center text-dark mt-1">
                        <div class="fw-bold me-2">DER = </div>
                        <div class="text-center">
                            <div class="border-bottom border-dark border-2 px-4 pb-1 fw-bold" style="font-size: 0.85rem;">Total Utang</div>
                            <div class="pt-1 fw-bold text-secondary" style="font-size: 0.85rem;">Total Ekuitas (Modal)</div>
                        </div>
                        <div class="fw-bold ms-2"> × 100%</div>
                    </div>
                </div>
                <div class="mb-3"><label class="form-label small fw-bold text-dark">Total Utang (Liabilities)</label><div class="input-group"><span class="input-group-text bg-light fw-bold">Rp</span><input type="text" id="der_utang" class="form-control calc-input text-primary fw-bold" placeholder="0"></div></div>
                <div class="mb-4"><label class="form-label small fw-bold text-dark">Total Ekuitas (Modal)</label><div class="input-group"><span class="input-group-text bg-light fw-bold">Rp</span><input type="text" id="der_ekuitas" class="form-control calc-input text-primary fw-bold" placeholder="0"></div></div>
                <div class="d-grid mb-3"><button type="button" class="btn btn-primary fw-bold rounded-pill shadow-sm" onclick="hitungCalc('der')">Hitung Otomatis</button></div>
                <div id="der_result_box" class="alert alert-success d-none text-center mb-0 rounded-3 shadow-sm border-0"><div class="small fw-bold text-success mb-1">Hasil:</div><h1 class="fw-bold text-success mb-3" id="der_result_val">0,00%</h1><button type="button" class="btn btn-success fw-bold rounded-pill px-4 mb-2 shadow-sm w-100" onclick="applyCalcResult('modalDER')"><i class="fas fa-check-circle me-1"></i> Terapkan Hasil</button></div>
            </div>
        </div>
    </div>
</div>

<script>
    let currentCalcQid = null; let currentCalcValue = 0;
    function formatNumber(n) { return n.replace(/\D/g, "").replace(/\B(?=(\d{3})+(?!\d))/g, "."); }

    function initCurrencyInputs() {
        document.querySelectorAll('.currency-input').forEach(input => {
            if(input.value) input.value = formatNumber(input.value.replace(/\D/g, ''));
            input.addEventListener('input', function(e) {
                let selectionStart = this.selectionStart;
                let oldLength = this.value.length;
                let cleanValue = this.value.replace(/\D/g, '');
                this.value = cleanValue === '' ? '' : formatNumber(cleanValue);
                let newLength = this.value.length;
                this.setSelectionRange(selectionStart + (newLength - oldLength), selectionStart + (newLength - oldLength));
            });
        });
        document.querySelectorAll('.calc-input').forEach(input => {
            input.addEventListener('input', function() { this.value = this.value.replace(/\D/g, '') === '' ? '' : formatNumber(this.value.replace(/\D/g, '')); });
        });
    }

    // Everything the two calculators do not share, in one place — the PHP that
    // renders the questions builds its ids from the same 'qr'/'der' prefixes.
    //   fields    modal input suffix -> hidden-store suffix, in display order
    //   required  the fields a ratio cannot be computed without (Persediaan may
    //             legitimately be 0, Total Utang may be 0 for a debt-free vendor)
    //   compute   the ratio, as a percentage, from the parsed amounts
    // Kept in step with includes/pq_calc_details.php, which re-derives the same
    // percentage server-side and is what the reviewers actually see.
    const CALC_KINDS = {
        qr: {
            label:    'Quick Ratio',
            button:   'Bantuan Hitung Quick Ratio',
            fields:   ['aset', 'persediaan', 'kewajiban'],
            required: ['aset', 'kewajiban'],
            rowLabels: { aset: 'Total Aset Lancar', persediaan: 'Total Persediaan', kewajiban: 'Kewajiban Lancar' },
            hint:     'masukkan Aset Lancar, Persediaan dan Kewajiban Lancar',
            compute:  (v) => ((v.aset - v.persediaan) / v.kewajiban) * 100,
        },
        der: {
            label:    'Debt to Equity',
            button:   'Bantuan Hitung Debt to Equity',
            fields:   ['utang', 'ekuitas'],
            required: ['utang', 'ekuitas'],
            rowLabels: { utang: 'Total Utang (Liabilities)', ekuitas: 'Total Ekuitas (Modal)' },
            hint:     'masukkan Total Utang dan Total Ekuitas',
            compute:  (v) => (v.utang / v.ekuitas) * 100,
        },
    };
    // Which calculator the open modal belongs to, so hitungCalc() knows what to
    // compute without every modal button having to say so twice.
    let currentCalcKind = null;

    // Reopening the calculator brings back what was typed last time (from the
    // hidden fields that travel with the answer), so a vendor correcting one
    // figure does not have to retype the others.
    function openCalc(kind, qid) {
        currentCalcKind = kind;
        currentCalcQid = qid;
        CALC_KINDS[kind].fields.forEach(f => {
            const store = document.getElementById(kind + '_val_' + f + '_' + qid);
            const input = document.getElementById(kind + '_' + f);
            if (!input) return;
            const raw = store && store.value ? store.value.replace(/\D/g, '') : '';
            input.value = raw === '' ? '' : formatNumber(raw);
        });
        document.getElementById(kind + '_result_box').classList.add('d-none');
    }

    // Compute the ratio for whichever calculator is open, show it, and apply it.
    // Wired to both modals' "Hitung Otomatis" button.
    function hitungCalc(kind) {
        const conf = CALC_KINDS[kind];
        const vals = {};
        conf.fields.forEach(f => {
            const el = document.getElementById(kind + '_' + f);
            vals[f] = el ? (parseFloat(el.value.replace(/\./g, '')) || 0) : 0;
        });

        // Only the divisor and the numerator's own required parts are demanded;
        // conf.required says which, since a 0 is a real answer for the others.
        for (const f of conf.required) {
            if (vals[f] === 0) {
                alert('Mohon isi ' + conf.rowLabels[f] + '.');
                return;
            }
        }

        currentCalcKind = kind;
        currentCalcValue = conf.compute(vals);
        document.getElementById(kind + '_result_val').innerText =
            currentCalcValue.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%';
        document.getElementById(kind + '_result_box').classList.remove('d-none');

        // The figures AND the band they imply are applied the moment a ratio
        // exists, not only on "Terapkan Hasil" — the band is the calculator's
        // output, so it must never lag behind the numbers on screen.
        storeCalcFigures(kind, currentCalcQid, vals, currentCalcValue);
        applyCalcBand(kind, currentCalcQid, currentCalcValue);
    }

    // Write the calculator's inputs into the hidden fields that get submitted
    // with the answer, and mirror them under the question so the vendor can see
    // what Procurement will receive.
    function storeCalcFigures(kind, qid, vals, result) {
        if (!qid) return;
        const conf = CALC_KINDS[kind];
        conf.fields.forEach(f => {
            const el = document.getElementById(kind + '_val_' + f + '_' + qid);
            if (el) el.value = vals[f];
        });

        const box = document.getElementById(kind + '_summary_' + qid);
        const txt = document.getElementById(kind + '_summary_text_' + qid);
        if (!box || !txt) return;
        const rp = (n) => 'Rp ' + n.toLocaleString('id-ID', {maximumFractionDigits: 0});
        txt.innerHTML =
            conf.fields.map(f => conf.rowLabels[f] + ': <b>' + rp(vals[f]) + '</b>').join('<br>')
            + '<br>' + conf.label + ': <b class="text-info">'
            + result.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%</b>';
        box.classList.remove('d-none');
    }

    // Read the numeric range a band label describes, e.g. "60% - 119%" or "> 180 %".
    // Returns {min, max, minEx, maxEx} in percent, or null when the label carries
    // no numbers. minEx/maxEx mark a bound the band excludes.
    //
    // "> 180 %" means strictly more than 180, so its lower bound is exclusive —
    // otherwise it would claim exactly 180% alongside "120% - 180%", and which of
    // the two won would come down to the order the options happen to be listed in.
    // That is fine for Quick Ratio, where the listed order puts the correct band
    // first anyway, but wrong for Debt to Equity: 270% would land in "> 270%" and
    // score 0 rather than in "201% - 270%" where the arithmetic puts it.
    function parseCalcBandRange(label) {
        const str = label.replace(/,/g, '.').replace(/%/g, '').toLowerCase();
        const nums = (str.match(/\d+(\.\d+)?/g) || []).map(Number);
        if (nums.length === 0) return null;
        if (nums.length >= 2) {
            return { min: Math.min(...nums), max: Math.max(...nums), minEx: false, maxEx: false };
        }
        if (str.includes('<') || str.includes('kurang') || str.includes('under')) {
            return { min: -Infinity, max: nums[0], minEx: false, maxEx: true };
        }
        if (str.includes('>') || str.includes('lebih') || str.includes('atas') || str.includes('above')) {
            return { min: nums[0], max: Infinity, minEx: true, maxEx: false };
        }
        return { min: nums[0], max: nums[0], minEx: false, maxEx: false };
    }

    // Does this band contain the value, honouring its exclusive bounds?
    function calcBandContains(range, val) {
        const aboveMin = range.minEx ? val > range.min : val >= range.min;
        const belowMax = range.maxEx ? val < range.max : val <= range.max;
        return aboveMin && belowMax;
    }

    // Set the band from the calculated percentage. This is the ONLY way a Quick
    // Ratio or Debt to Equity answer is ever set: the radios are rendered
    // permanently disabled (display only, never submitted) and the score that
    // actually posts lives in the hidden answers[qid] field written here.
    // Recalculating with different figures moves the band; nothing else can.
    function applyCalcBand(kind, qid, val) {
        const wrap  = document.getElementById(kind + '_options_' + qid);
        const store = document.getElementById(kind + '_score_' + qid);
        if (!wrap || !store) return null;

        const radios = Array.from(wrap.querySelectorAll('input[type="radio"]'));
        const bands  = radios.map(r => {
            const lbl = document.querySelector('label[for="' + r.id + '"] span');
            return { radio: r, score: Number(r.value), range: lbl ? parseCalcBandRange(lbl.innerText) : null };
        }).filter(b => b.range !== null);

        // Exclusive open-ended bounds (see parseCalcBandRange) mean the configured
        // bands never overlap, so at most one can contain the value and this does
        // not depend on the order the options were listed in.
        let matched = bands.find(b => calcBandContains(b.range, val)) || null;

        // The configured bands do not always meet: Quick Ratio's "0% - 59%" and
        // "60% - 119%" leave 59.5% in a gap, Debt to Equity's "100% - 200%" and
        // "201% - 270%" leave 200.5%, and a negative Quick Ratio (all current
        // assets are inventory) falls below every band. There is no manual
        // fallback any more, so these must still resolve — and they must resolve
        // to whichever neighbouring band carries the LOWER score. Picking the
        // nearer edge instead would hand 59.5% the top Quick Ratio score, and
        // since Debt to Equity is scored the other way round (less debt is
        // better) "resolve downward" would do the same for 200.5%. Scoring
        // direction is read off the bands themselves, so neither question needs
        // its own rule.
        if (!matched && bands.length > 0) {
            const below = bands.filter(b => (b.range.maxEx ? b.range.max <= val : b.range.max < val));
            const above = bands.filter(b => (b.range.minEx ? b.range.min >= val : b.range.min > val));
            const candidates = [];
            if (below.length > 0) candidates.push(below.reduce((a, b) => (b.range.max > a.range.max ? b : a)));
            if (above.length > 0) candidates.push(above.reduce((a, b) => (b.range.min < a.range.min ? b : a)));
            if (candidates.length > 0) matched = candidates.reduce((a, b) => (b.score < a.score ? b : a));
        }
        if (!matched) return null;

        radios.forEach(r => { r.checked = false; });
        matched.radio.checked = true;
        store.value = matched.radio.value;
        store.disabled = false; // now it has a score, so let it submit

        const notice = document.getElementById(kind + '_lock_notice_' + qid);
        const noticeText = document.getElementById(kind + '_lock_notice_text_' + qid);
        if (notice && noticeText) {
            notice.classList.remove('alert-warning');
            notice.classList.add('alert-info');
            const icon = notice.querySelector('i');
            if (icon) icon.className = 'fas fa-circle-info mt-1 me-2';
            noticeText.innerHTML = 'Opsi di bawah terisi otomatis dari hasil perhitungan Anda. '
                + 'Untuk mengubahnya, buka kembali <b>' + CALC_KINDS[kind].button + '</b> dan perbarui angkanya.';
        }

        updateProgress();
        return matched.radio;
    }

    // Final-submit gate for every calculated question. The browser cannot do this
    // for us: the bands are disabled, so they are exempt from `required` and an
    // untouched Quick Ratio or Debt to Equity question would otherwise sail
    // through form.checkValidity(). submit_pq.php enforces the same rule
    // server-side.
    function validateCalcQuestions() {
        for (const kind of Object.keys(CALC_KINDS)) {
            const conf = CALC_KINDS[kind];
            for (const wrap of document.querySelectorAll('[id^="' + kind + '_options_"]')) {
                const qid = wrap.id.replace(kind + '_options_', '');
                const store = document.getElementById(kind + '_score_' + qid);
                const hasFigures = conf.required.every(f => {
                    const el = document.getElementById(kind + '_val_' + f + '_' + qid);
                    return el && el.value.trim() !== '';
                });
                const hasScore = store && store.value.trim() !== '';

                if (!hasFigures || !hasScore) {
                    alert('Pertanyaan ' + conf.label + ' harus diisi melalui kalkulator. '
                        + 'Klik "' + conf.button + '", ' + conf.hint + ', '
                        + 'lalu tekan "Hitung Otomatis". '
                        + 'Opsi jawaban akan terisi otomatis dari hasil perhitungan.');
                    wrap.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return false;
                }
            }
        }
        return true;
    }

    // "Terapkan Hasil". The band was already applied the moment hitungCalc() ran,
    // so this only re-applies it (harmless, keeps the two paths in step) and
    // closes the modal. There is no manual-selection fallback: every calculator
    // on this form drives a locked band, so a value that matches nothing is a
    // misconfigured question set rather than something the vendor can work around.
    function applyCalcResult(modalId) {
        if (!currentCalcQid || !currentCalcKind) return;

        const matched = applyCalcBand(currentCalcKind, currentCalcQid, currentCalcValue);
        if (!matched) {
            alert('Hasil perhitungan tidak dapat dicocokkan dengan opsi manapun. '
                + 'Mohon hubungi tim Procurement.');
            return;
        }

        // --- FIX BACKDROP GELAP BOOTSTRAP ---
        const modalEl = document.getElementById(modalId);
        const closeBtn = modalEl.querySelector('[data-bs-dismiss="modal"]');

        // Cara teraman: Simulasikan klik pada tombol Tutup bawaan Bootstrap
        if (closeBtn) {
            closeBtn.click();
        } else {
            let modalInstance = bootstrap.Modal.getInstance(modalEl);
            if (modalInstance) modalInstance.hide();
        }

        // Fallback: Sapu bersih layar setelah 300ms (Jaga-jaga jika Bootstrap glitch)
        setTimeout(() => {
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
            document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());

            // Gulir otomatis ke opsi yang dipilih
            matched.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 300);
        // ------------------------------------
    }

    // --- 5. LOGIKA LAPORAN KEUANGAN ---
    function toggleLaporanBox(qid, score, isAudited) {
        const box = document.getElementById('auditor_box_' + qid);
        const input = document.getElementById('auditor_input_' + qid);
        
        if (box && input) {
            if (isAudited === true || isAudited === 'true') { 
                box.classList.remove('d-none');
                input.setAttribute('required', 'required');
                setTimeout(() => input.focus(), 100); 
            } else {
                box.classList.add('d-none');
                input.removeAttribute('required');
                input.value = ''; 
            }
        }

        const fileInput = document.querySelector(`input[name="attachment_${qid}[]"]`);
        const existingFile = document.querySelector(`input[name="existing_attachments[${qid}][]"]`);
        const labelDoc = document.getElementById('label_doc_' + qid);

        if (score > 1) {
            if (labelDoc) labelDoc.innerHTML = '<i class="fas fa-paperclip me-2 text-primary"></i> Dokumen Pendukung <span class="text-danger">*</span>';
            if (fileInput && !existingFile) fileInput.setAttribute('required', 'required');
            else if (fileInput) fileInput.removeAttribute('required');
        } else {
            if (labelDoc) labelDoc.innerHTML = '<i class="fas fa-paperclip me-2 text-primary"></i> Dokumen Pendukung';
            if (fileInput) fileInput.removeAttribute('required');
        }
        updateProgress();
    }


    // --- 7. RIWAYAT CLIENT: toggle daftar proyek berdasarkan pilihan radio ---
    function toggleClientHistoryBox(qid, score) {
        const box = document.getElementById('client_history_box_' + qid);
        if (!box) return;
        const inputs = box.querySelectorAll('input[type="text"]');
        if (parseInt(score) === 5) {
            box.classList.remove('d-none');
            // Pastikan minimal ada 1 baris input dan semua required
            inputs.forEach(inp => inp.setAttribute('required', 'required'));
            if (inputs.length === 0) addTextRow(qid, true);
            setTimeout(() => box.querySelector('input[type="text"]')?.focus(), 100);
        } else {
            box.classList.add('d-none');
            // Hapus required agar form bisa disubmit tanpa isian
            inputs.forEach(inp => inp.removeAttribute('required'));
        }
        updateProgress();
    }

    // --- 6. PERBAIKAN LOGIKA KOTAK TEMPO ---
    function toggleReasonBox(qid, isTidak) {
        const box = document.getElementById('reason_box_' + qid);
        const input = document.getElementById('reason_input_' + qid);
        if (box && input) {
            // Sekarang memvalidasi nilai boolean true/false atau string 'true'/'false'
            if (isTidak === true || isTidak === 'true') { 
                box.classList.remove('d-none');
                input.setAttribute('required', 'required');
                setTimeout(() => input.focus(), 100); 
            } else {
                box.classList.add('d-none');
                input.removeAttribute('required');
                input.value = ''; 
            }
            updateProgress();
        }
    }

    async function validateProjectAI(input) {
        const text = input.value.trim();
        const feedbackEl = input.parentElement.nextElementSibling;
        if (!text) { feedbackEl.style.display = 'none'; return; }
        feedbackEl.innerHTML = '<span class="text-primary"><i class="fas fa-spinner fa-spin me-1"></i> Menganalisis format...</span>';
        feedbackEl.style.display = 'block';
        try {
            const formData = new FormData(); formData.append('text', text);
            const response = await fetch('validate_project_ai.php', { method: 'POST', body: formData });
            const data = await response.json();
            if (data.is_valid) { feedbackEl.innerHTML = '<span class="text-success"><i class="fas fa-check-circle me-1"></i> Format disetujui.</span>'; setTimeout(() => { feedbackEl.style.display = 'none'; }, 3000); }
            else feedbackEl.innerHTML = '<span class="text-danger fw-bold"><i class="fas fa-robot me-1"></i> Saran: </span><span class="text-danger">' + data.message + '</span>';
        } catch (error) { feedbackEl.style.display = 'none'; }
    }

    function addTextRow(qid, isExperience = false) {
        const container = document.getElementById('dynamic_text_container_' + qid);
        const div = document.createElement('div'); div.className = 'mb-2';
        let placeholder = isExperience ? 'Cth: Pekerjaan / Suplai PT ABC (2024)' : 'Masukkan rincian...';
        let blurEvent = isExperience ? 'onblur="validateProjectAI(this)"' : '';
        div.innerHTML = `<div class="input-group dynamic-text-row shadow-sm rounded-3 overflow-hidden bg-white"><input type="text" name="remarks_multi[${qid}][]" class="form-control answer-input border-0 py-2 project-input" placeholder="${placeholder}" required ${blurEvent}><button type="button" class="btn btn-danger px-3" onclick="this.closest('.mb-2').remove(); updateProgress();"><i class="fas fa-times"></i></button></div>` + (isExperience ? '<div class="ai-feedback small ms-2 mt-1" style="display:none;"></div>' : '');
        container.appendChild(div); updateProgress();
    }

    function addFileInput(qid) {
        const container = document.getElementById('file_inputs_' + qid);
        const div = document.createElement('div'); div.className = 'input-group input-group-sm mb-2 shadow-sm rounded-3 overflow-hidden bg-white mt-2';
        div.innerHTML = `<input type="file" name="attachment_${qid}[]" class="form-control px-3 py-2 border-0 pq-file-input"><button type="button" class="btn btn-danger px-3" onclick="this.parentElement.remove()" title="Hapus"><i class="fas fa-times"></i></button>`;
        container.appendChild(div);
    }

    function updateProgress() {
        const blocks = document.querySelectorAll('.question-block'); let answered = 0;
        blocks.forEach(block => {
            const radioChecked = block.querySelector('input[type="radio"]:checked');
            const entryInputs = block.querySelectorAll('input[type="text"].answer-input, input[type="number"].answer-input, textarea.answer-input');
            let hasEntry = false; entryInputs.forEach(input => { if (input.value.trim() !== '') hasEntry = true; });
            if (radioChecked || hasEntry) answered++; 
        });
        const percentage = blocks.length > 0 ? Math.round((answered / blocks.length) * 100) : 0;
        document.getElementById('progress-bar').style.width = percentage + '%';
        document.getElementById('progress-text').innerText = percentage + '%';
    }

    function executeSaveDraft() {
        if (!checkAllFileSizes()) return;
        document.querySelectorAll('.currency-input').forEach(input => { input.value = input.value.replace(/\./g, ''); });
        const hiddenBtn = document.createElement('input'); hiddenBtn.type = 'hidden'; hiddenBtn.name = 'save_draft_pq'; hiddenBtn.value = '1';
        document.getElementById('pqForm').appendChild(hiddenBtn);
        document.getElementById('pqForm').querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
        document.getElementById('pqForm').submit();
    }

    function executeFinalSubmit() {
        if (!checkAllFileSizes()) return;
        if (!validateCalcQuestions()) return;
        document.querySelectorAll('.currency-input').forEach(input => { input.value = input.value.replace(/\./g, ''); });
        const hiddenBtn = document.createElement('input'); hiddenBtn.type = 'hidden'; hiddenBtn.name = 'submit_pq'; hiddenBtn.value = '1';
        document.getElementById('pqForm').appendChild(hiddenBtn);
        document.getElementById('pqForm').submit();
    }

    // =============================================
    // FILE SIZE VALIDATION (5MB max for PQ attachments)
    // =============================================
    const PQ_MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    // Delegated listener — works for both static and dynamically added file inputs
    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('pq-file-input')) return;
        const input = e.target;

        // Remove any existing toast
        const existingErr = input.closest('.input-group')?.parentElement?.querySelector('.file-size-toast');
        if (existingErr) existingErr.remove();

        if (!input.files || !input.files[0]) return;

        if (input.files[0].size > PQ_MAX_FILE_SIZE) {
            const sizeMB = (input.files[0].size / (1024 * 1024)).toFixed(1);
            
            const toast = document.createElement('div');
            toast.className = 'file-size-toast';
            toast.innerHTML = `
                <div class="fst-progress-bar"></div>
                <div class="fst-body">
                    <div class="fst-icon">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"><path d="M13 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V9z"/><polyline points="13 2 13 9 20 9"/><line x1="9" y1="15" x2="15" y2="15" class="fst-pulse"/></svg>
                    </div>
                    <div class="fst-content">
                        <div class="fst-header">
                            <span class="fst-title">File melebihi batas ukuran</span>
                            <button type="button" class="fst-close" onclick="this.closest('.file-size-toast').remove()">&times;</button>
                        </div>
                        <div class="fst-desc">Ukuran file <strong style="color:#dc3545;">${sizeMB} MB</strong> melebihi batas maksimal <strong>5 MB</strong>. Silakan kompres atau pilih file lain.</div>
                        <div class="fst-badges">
                            <span class="fst-badge fst-badge-red"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> ${sizeMB} MB</span>
                            <span class="fst-badge fst-badge-green"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Maks: 5 MB</span>
                        </div>
                    </div>
                </div>`;
            input.closest('.input-group').parentElement.appendChild(toast);
            input.value = '';
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 10000);
        }
    });

    // Check all file inputs before form submit
    function checkAllFileSizes() {
        let hasOversized = false;
        document.querySelectorAll('.pq-file-input').forEach(input => {
            if (input.files && input.files[0] && input.files[0].size > PQ_MAX_FILE_SIZE) {
                hasOversized = true;
                input.dispatchEvent(new Event('change', { bubbles: true })); // Trigger error display
            }
        });
        if (hasOversized) {
            alert('Terdapat file lampiran yang melebihi batas 5MB. Silakan hapus atau ganti file tersebut sebelum mengirim.');
            return false;
        }
        return true;
    }

    document.addEventListener('DOMContentLoaded', () => {
        // MUNCULKAN DISCLAIMER (HANYA SEKALI PER SESI)
        const sessionKey = 'pq_disclaimer_agreed_final';
        if (!sessionStorage.getItem(sessionKey)) {
            new bootstrap.Modal(document.getElementById('disclaimerModal')).show();
        }

        const checkAgree = document.getElementById('checkAgreeDisclaimer');
        const btnAgree = document.getElementById('btnAgreeDisclaimer');
        if(checkAgree && btnAgree) {
            checkAgree.addEventListener('change', function() {
                btnAgree.disabled = !this.checked;
                if(this.checked) { btnAgree.classList.replace('btn-secondary', 'btn-primary'); } 
                else { btnAgree.classList.replace('btn-primary', 'btn-secondary'); }
            });
            btnAgree.addEventListener('click', () => sessionStorage.setItem(sessionKey, 'true'));
        }
        
        const btnInitiateSubmit = document.getElementById('btnInitiateSubmit');
        if (btnInitiateSubmit) {
            btnInitiateSubmit.addEventListener('click', () => {
                const form = document.getElementById('pqForm');
                if (!form.checkValidity()) { form.reportValidity(); return; }
                // Checked after the browser's own pass so the vendor fixes the
                // ordinary blanks first, and only then hears about the calculator.
                if (!validateCalcQuestions()) return;
                new bootstrap.Modal(document.getElementById('confirmSubmitModal')).show();
            });
        }

        initCurrencyInputs(); updateProgress();
        document.addEventListener('change', (e) => { if(e.target.classList.contains('answer-input')) updateProgress(); });
        document.addEventListener('input', (e) => { if(e.target.classList.contains('answer-input')) updateProgress(); });
        document.getElementById('pqForm').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') e.preventDefault(); });
    });
</script>

<div class="modal fade" id="confirmChangeCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-warning border-0 py-3">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-exclamation-triangle me-2"></i>Ubah Sub-Kategori Vendor?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-4"><i class="fas fa-sync-alt fa-4x text-warning opacity-75"></i></div>
                <h5 class="fw-bold text-dark mb-3">Semua jawaban PQ saat ini akan dihapus</h5>
                <p class="text-muted mb-0">Mengubah Sub-Kategori Vendor akan mengganti seluruh daftar pertanyaan, sehingga jawaban yang sudah diisi di kategori saat ini tidak dapat dipertahankan.</p>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                <a href="classify_vendor.php?reset=1" class="btn btn-warning fw-bold px-4 rounded-pill text-dark">Ya, Ubah Kategori</a>
            </div>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>