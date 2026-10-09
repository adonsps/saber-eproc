<?php
// modules/vendor/pq_form.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

// 1. Security
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// 2. Fetch Vendor Data
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT * FROM vendors WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();

if (!$vendor) die("Vendor profile not found.");

// --- CEK GATEKEEPER KELENGKAPAN PROFIL ---
$is_profile_complete = true;
$legal_entity_type = $vendor['legal_entity_type'] ?? null;

if (empty($legal_entity_type) || empty($vendor['director_id_card_file']) || empty($vendor['bank_cover_path']) || empty($vendor['npwp_path']) || empty($vendor['business_ethics_commitment_file'])) {
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
$current_status = $vendor['registration_status'];

// 3. Logic: Check for existing submission
$check = $conn->query("SELECT id FROM pq_submissions WHERE vendor_id = $vendor_id");
$has_submitted = ($check->num_rows > 0);

if ($has_submitted && $current_status !== 'revision_requested' && $current_status !== 'invited_pq' && $current_status !== 'registered') {
    header("Location: dashboard.php"); 
    exit();
}

// 4. PRE-FILL DATA (Termasuk Sourcing Notes)
$existing_answers = [];
$sourcing_notes = ""; 

if ($has_submitted) {
    $sub_q = $conn->query("SELECT id, sourcing_notes FROM pq_submissions WHERE vendor_id = $vendor_id ORDER BY id DESC LIMIT 1");
    if ($sub_row = $sub_q->fetch_assoc()) {
        $last_sub_id = $sub_row['id'];
        $sourcing_notes = $sub_row['sourcing_notes']; 
        
        $ans_q = $conn->query("
            SELECT a.question_id, a.selected_score, a.remarks, a.attachment_path, a.needs_revision, a.reviewer_notes, q.question_text 
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

<nav class="navbar navbar-dark bg-primary mb-4 shadow-sm sticky-top">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold"><i class="fas fa-clipboard-check me-2"></i> Penilaian Vendor</span>
        <div class="d-flex align-items-center text-white">
            <div class="me-3 text-end d-none d-md-block">
                <div class="fw-bold small"><?= htmlspecialchars($vendor['company_name']) ?></div>
                <div style="font-size: 0.7rem; opacity: 0.8;">Vendor Portal &bull; <?= htmlspecialchars($vendor_type) ?></div>
            </div>
            <a href="dashboard.php" class="btn btn-sm btn-outline-light me-2 rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
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

    <?php if ($current_status == 'revision_requested'): ?>
    <div class="alert alert-warning shadow-sm border-0 rounded-4 mb-4 position-relative overflow-hidden">
        <div class="position-absolute top-0 start-0 w-100 h-100 bg-warning opacity-10" style="z-index: 0;"></div>
        <div class="position-relative z-1 p-2">
            <h5 class="alert-heading fw-bold text-dark"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Butuh Revisi</h5>
            <p class="mb-3 text-dark">Tim Procurement telah meninjau pengajuan Anda dan meminta beberapa pembenahan. Mohon periksa kolom yang ditandai di bawah ini dan ajukan ulang.</p>
            <?php if(!empty($sourcing_notes)): ?>
                <div class="p-3 bg-white rounded-3 border border-warning shadow-sm text-dark mb-2">
                    <strong class="text-warning-emphasis"><i class="fas fa-comment-dots me-2"></i>Catatan Umum dari Tim Procurement:</strong><br>
                    <div class="mt-2"><?= nl2br(htmlspecialchars($sourcing_notes)) ?></div>
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

    <form action="submit_pq.php" method="POST" enctype="multipart/form-data" id="pqForm">
        <input type="hidden" name="is_revision" value="<?= ($current_status == 'revision_requested' ? 1 : 0) ?>">

        <?php
        $legal_reviewer_notes_json = ''; 
        $sql = "SELECT q.*, sc.section_name FROM pq_questions q LEFT JOIN pq_sections sc ON q.section_id = sc.id WHERE q.is_active = 1 AND (q.variant_target LIKE '%$vendor_type%' OR q.variant_target IS NULL OR q.variant_target = '') ORDER BY sc.sort_order ASC, q.id ASC";
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
                $prev_file = $existing_answers[$norm_text]['file'] ?? null;
                $is_flagged = $existing_answers[$norm_text]['needs_revision'] ?? 0;
                $reviewer_note = $existing_answers[$norm_text]['reviewer_notes'] ?? '';

                $flag_class = ($is_flagged && $current_status == 'revision_requested') ? 'flagged' : '';
                
                // Deteksi Kalkulator
                $is_quick_ratio = (strpos($norm_text, 'quick') !== false || strpos($norm_text, 'rasio cepat') !== false);
                $is_der = (strpos($norm_text, 'debt') !== false || preg_match('/\bder\b/', $norm_text) || strpos($norm_text, 'equity') !== false || strpos($norm_text, 'ekuitas') !== false);

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
                            <?php if ($is_quick_ratio): ?>
                                <button type="button" class="btn btn-sm btn-info text-white mt-1 mb-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalQR" onclick="openQRCalc(<?= $qid ?>)"><i class="fas fa-calculator me-1"></i> Bantuan Hitung Quick Ratio</button>
                            <?php endif; ?>
                            <?php if ($is_der): ?>
                                <button type="button" class="btn btn-sm btn-info text-white mt-1 mb-2 rounded-pill fw-bold shadow-sm" data-bs-toggle="modal" data-bs-target="#modalDER" onclick="openDERCalc(<?= $qid ?>)"><i class="fas fa-calculator me-1"></i> Bantuan Hitung Debt to Equity</button>
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
                        ?>
                            <div class="row g-3 mb-3">
                                <?php foreach ($options as $score => $label): 
                                    $unique_id = "q" . $qid . "_opt" . $score;
                                    $checked = ($prev_score !== null && $prev_score == $score) ? 'checked' : '';
                                    
                                    // Deteksi Opsi
                                    $is_audited_option = (stripos($label, 'audit') !== false && stripos($label, 'belum') === false && stripos($label, 'non') === false) ? 'true' : 'false';
                                    $is_tidak_option = (stripos($label, 'tidak') !== false) ? 'true' : 'false'; // Khusus pertanyaan Tempo
                                    
                                    if ($checked === 'checked' && $is_laporan) {
                                        if ($is_audited_option === 'true') $show_auditor_box = true;
                                        if ($score > 1) $must_attach = true; 
                                    }
                                    
                                    $js_trigger = "onchange=\"updateProgress()\"";
                                    // 3. Perbaikan pemanggilan trigger Tempo (mengirimkan status apakah ini opsi "Tidak")
                                    if ($is_tempo) $js_trigger = "onchange=\"toggleReasonBox($qid, $is_tidak_option)\"";
                                    if ($is_laporan) $js_trigger = "onchange=\"toggleLaporanBox($qid, $score, $is_audited_option)\"";
                                ?>
                                    <div class="col-md-6">
                                        <input type="radio" class="btn-check answer-input" name="answers[<?= $qid ?>]" id="<?= $unique_id ?>" value="<?= $score ?>" <?= $checked ?> required <?= $js_trigger ?>>
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
                        if ((empty($options) && !$is_fin) || $is_experience) { 
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
                        'Komitmen Etika Bisnis' => $vendor['business_ethics_commitment_file'] ?? '',
                        'Profil Perusahaan' => $vendor['company_profile_file'] ?? '',
                        'NIB' => $vendor['nib_path'] ?? '',
                        'Akta Pendirian' => $vendor['deed_of_establishment_file'] ?? '',
                        'SK Pengesahan' => $vendor['decree_of_legal_entity_file'] ?? '',
                        'Akta Perubahan' => $vendor['deed_of_amendment_file'] ?? '',
                        'SPPKP' => $vendor['sppkp_path'] ?? '',
                        'Surat Ijin Usaha (SIUJK)' => $vendor['business_entity_cert_file'] ?? ''
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
                <div class="d-grid mb-3"><button type="button" class="btn btn-primary fw-bold rounded-pill shadow-sm" onclick="hitungQR()">Hitung Otomatis</button></div>
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
                <div class="d-grid mb-3"><button type="button" class="btn btn-primary fw-bold rounded-pill shadow-sm" onclick="hitungDER()">Hitung Otomatis</button></div>
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

    function openQRCalc(qid) { currentCalcQid = qid; document.getElementById('qr_aset').value = ''; document.getElementById('qr_persediaan').value = ''; document.getElementById('qr_kewajiban').value = ''; document.getElementById('qr_result_box').classList.add('d-none'); }
    function openDERCalc(qid) { currentCalcQid = qid; document.getElementById('der_utang').value = ''; document.getElementById('der_ekuitas').value = ''; document.getElementById('der_result_box').classList.add('d-none'); }

    function hitungQR() {
        let aset = parseFloat(document.getElementById('qr_aset').value.replace(/\./g, '')) || 0;
        let pers = parseFloat(document.getElementById('qr_persediaan').value.replace(/\./g, '')) || 0;
        let kew  = parseFloat(document.getElementById('qr_kewajiban').value.replace(/\./g, '')) || 0;
        if (kew === 0) { alert("Mohon isi Kewajiban Lancar."); return; }
        currentCalcValue = ((aset - pers) / kew) * 100;
        document.getElementById('qr_result_val').innerText = currentCalcValue.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%';
        document.getElementById('qr_result_box').classList.remove('d-none');
    }

    function hitungDER() {
        let utang = parseFloat(document.getElementById('der_utang').value.replace(/\./g, '')) || 0;
        let ekuit = parseFloat(document.getElementById('der_ekuitas').value.replace(/\./g, '')) || 0;
        if (ekuit === 0) { alert("Mohon isi Total Ekuitas."); return; }
        currentCalcValue = (utang / ekuit) * 100;
        document.getElementById('der_result_val').innerText = currentCalcValue.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '%';
        document.getElementById('der_result_box').classList.remove('d-none');
    }

    function applyCalcResult(modalId) {
        if (!currentCalcQid) return;
        let val = currentCalcValue; 
        let radios = document.querySelectorAll(`input[name="answers[${currentCalcQid}]"]`); 
        let matched = null;
        
        for (let r of radios) {
            let lbl = document.querySelector(`label[for="${r.id}"] span`); if(!lbl) continue;
            let str = lbl.innerText.replace(/,/g, '.').replace(/%/g, '').toLowerCase();
            let nums = str.match(/\d+(\.\d+)?/g); if (!nums) continue; nums = nums.map(Number);
            
            if (nums.length >= 2) { 
                let min = Math.min(...nums); let max = Math.max(...nums); 
                if (val >= min && val <= max) { matched = r; break; } 
            } else if (nums.length === 1) {
                if ((str.includes('<') || str.includes('kurang') || str.includes('under')) && val <= nums[0]) { matched = r; break; }
                else if ((str.includes('>') || str.includes('lebih') || str.includes('atas') || str.includes('above')) && val >= nums[0]) { matched = r; break; }
            }
        }
        
        if (matched) {
            matched.checked = true; 
            updateProgress(); 
            
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
            
        } else {
            alert('Hasil (' + val.toLocaleString('id-ID',{maximumFractionDigits:2}) + '%) tidak otomatis cocok dengan opsi. Silakan pilih manual.');
        }
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
                if (form.checkValidity()) new bootstrap.Modal(document.getElementById('confirmSubmitModal')).show();
                else form.reportValidity();
            });
        }

        initCurrencyInputs(); updateProgress();
        document.addEventListener('change', (e) => { if(e.target.classList.contains('answer-input')) updateProgress(); });
        document.addEventListener('input', (e) => { if(e.target.classList.contains('answer-input')) updateProgress(); });
        document.getElementById('pqForm').addEventListener('keydown', (e) => { if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') e.preventDefault(); });
    });
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>