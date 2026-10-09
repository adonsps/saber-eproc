<?php
// modules/vendor/view_pq.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever be on profile.php.
vendor_staff_edit_restrict();

// 2. Get Vendor ID
$user_id = $_SESSION['user_id'];
$vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
$stmt = $conn->prepare("SELECT id, company_name, registration_status, admin_notes FROM vendors WHERE id = ?");
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$vendor = $stmt->get_result()->fetch_assoc();
$vendor_id = $vendor['id'];

// 3. Fetch Submission Data (Header)
$submission_q = $conn->prepare("SELECT id, total_weighted_score, risk_category, approved_at, notes_to_vendor FROM pq_submissions WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
$submission_q->bind_param("i", $vendor_id);
$submission_q->execute();
$submission = $submission_q->get_result()->fetch_assoc();

// 4. Fetch Answers
$sql = "SELECT 
            q.question_text, 
            q.help_text,
            a.remarks, 
            a.attachment_path, 
            a.selected_score
        FROM pq_answers a 
        JOIN pq_submissions s ON a.submission_id = s.id
        JOIN pq_questions q ON a.question_id = q.id 
        WHERE s.vendor_id = ? 
        ORDER BY q.id ASC";

$stmt_pq = $conn->prepare($sql);
$stmt_pq->bind_param("i", $vendor_id);
$stmt_pq->execute();
$pq_result = $stmt_pq->get_result();

// Pre-process results to filter out duplicates based on normalized question_text
$unique_answers = [];
$seen_questions = [];

if ($pq_result && $pq_result->num_rows > 0) {
    while ($row = $pq_result->fetch_assoc()) {
        $q_text_normalized = strtolower(trim($row['question_text']));
        if (!in_array($q_text_normalized, $seen_questions)) {
            $seen_questions[] = $q_text_normalized;
            $unique_answers[] = $row;
        }
    }
}

$page_title = "Pengajuan Pra-Kualifikasi Saya";
include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Pengajuan Pra-Kualifikasi Saya</span>
        <a href="dashboard.php" class="btn btn-sm btn-nav"><i class="fas fa-arrow-left me-1"></i> Kembali ke Dashboard</a>
    </div>
</nav>

<div class="container pb-5">

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h5 class="card-title text-primary">Status Pra-Kualifikasi</h5>
                    <?php 
                        $status = $vendor['registration_status'];
                        $badge = 'bg-secondary';
                        if($status == 'approved') $badge = 'bg-success';
                        if($status == 'rejected') $badge = 'bg-danger';
                        if($status == 'revision_requested') $badge = 'bg-warning text-dark';
                    ?>
                    <span class="badge <?= $badge ?> fs-6"><?= strtoupper(str_replace('_', ' ', $status)) ?></span>
                    
                    <?php 
                    // Show Admin Feedback ONLY if not approved
                    if($status !== 'approved' && !empty($vendor['admin_notes'])): ?>
                        <div class="alert alert-warning mt-3 mb-0 shadow-sm border-start border-4 border-warning">
                            <strong><i class="fas fa-comment-dots me-1"></i> Feedback Procurement:</strong><br>
                            <?= nl2br(htmlspecialchars($vendor['admin_notes'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4 text-md-end mt-3 mt-md-0">
                    <div class="text-muted small mb-2">Pengajuan telah dikunci.</div>
                    <a href="download_result.php" class="btn btn-outline-danger btn-sm shadow-sm">
                        <i class="fas fa-file-pdf me-1"></i> Unduh Hasil PDF
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php
        // Catatan langsung untuk Vendor (notes_to_vendor), BUKAN jejak internal vm_notes.
        // Format tersimpan: "DD Month YYYY HH.mm - Pesan ; nama" — bagian setelah " ; "
        // terakhir pada tiap baris disembunyikan dari vendor. Sama seperti pq_form.php.
        $vendor_note_lines = [];
        foreach (explode("\n", (string)($submission['notes_to_vendor'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $sep = strrpos($line, ' ; ');
            if ($sep !== false) $line = rtrim(substr($line, 0, $sep));
            $vendor_note_lines[] = $line;
        }
        $vendor_note = trim(implode("\n", $vendor_note_lines));
    ?>
    <?php if($status !== 'approved' && $vendor_note !== ''): ?>
        <div class="alert alert-info mb-4 shadow-sm border-start border-4 border-info">
            <h6 class="fw-bold mb-2"><i class="fas fa-clipboard-check me-2"></i>Catatan Tinjauan Procurement</h6>
            <div class="text-dark small">
                <?= nl2br(htmlspecialchars($vendor_note)) ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white py-3">
            <h5 class="mb-0 fw-bold">Jawaban Terkirim</h5>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="bg-light text-secondary small text-uppercase">
                    <tr>
                        <th style="width: 50%;" class="ps-4">Pertanyaan</th>
                        <th class="ps-4">Jawaban & Bukti Anda</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($unique_answers)): ?>
                        <?php foreach ($unique_answers as $row): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="fw-bold text-dark" style="font-size: 0.95rem;"><?= htmlspecialchars($row['question_text']) ?></div>
                            </td>
                            <td class="ps-4 py-3">
                                <?php 
                                // Detect financial questions
                                $q_lower = strtolower($row['question_text']);
                                $is_financial = (strpos($q_lower, 'income') !== false || strpos($q_lower, 'pendapatan') !== false || strpos($q_lower, 'capital') !== false || strpos($q_lower, 'modal') !== false);

                                if ($is_financial && !empty($row['remarks'])) {
                                    // Financial: show Rupiah amount from remarks (what vendor actually submitted)
                                    $amount = str_replace(['.', ','], '', trim($row['remarks']));
                                    // echo '<div class="mb-1"><span class="badge bg-light text-dark border" style="font-size:0.7rem;"><i class="fas fa-coins text-warning me-1"></i> Jumlah yang diajukan</span></div>';
                                    echo '<div class="mb-2 text-dark fw-bold" style="font-size:1.1rem;">Rp ' . number_format((float)$amount, 0, ',', '.') . '</div>';
                                    if ($row['selected_score'] !== null) {
                                        // echo '<div class="text-muted small"><i class="fas fa-calculator me-1"></i> Skor otomatis: ' . $row['selected_score'] . ' / 5</div>';
                                    }

                                } elseif (!empty($row['help_text']) && $row['selected_score'] !== null) {
                                    $label = "Pilihan Terpilih"; 
                                    preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $row['help_text'], $matches, PREG_SET_ORDER);
                                    foreach ($matches as $m) {
                                        if ($m[1] == $row['selected_score']) {
                                            $label = trim($m[2], "- \t\n\r\0\x0B");
                                            break;
                                        }
                                    }
                                    echo '<div class="mb-2 text-dark fw-medium"><i class="fas fa-check-circle text-success me-2"></i>' . htmlspecialchars($label) . '</div>';
                                
                                } elseif (!empty($row['remarks'])) {
                                    $lines = explode("\n", $row['remarks']);
                                    if (count($lines) > 1) {
                                        echo '<ul class="mb-2 ps-3 text-dark small">';
                                        foreach($lines as $line) {
                                            if(trim($line) !== '') {
                                                echo '<li>' . htmlspecialchars(trim($line)) . '</li>';
                                            }
                                        }
                                        echo '</ul>';
                                    } else {
                                        if (is_numeric(str_replace(['.', ','], '', trim($row['remarks']))) && !str_contains($row['question_text'], 'clients') && !str_contains($row['question_text'], 'branches')) {
                                             echo '<div class="mb-2 text-dark fw-bold">Rp ' . number_format((float)str_replace(['.', ','], '', $row['remarks']), 0, ',', '.') . '</div>';
                                        } else {
                                            echo '<div class="mb-2 text-dark small">'.nl2br(htmlspecialchars($row['remarks'])).'</div>';
                                        }
                                    }
                                } else {
                                    echo '<div class="mb-2 text-muted fst-italic small">- Tidak ada jawaban tersimpan -</div>';
                                }
                                
                                if (!empty($row['attachment_path'])) {
                                    echo '<div class="mt-2 d-flex flex-wrap gap-2">';
                                    $files = explode(',', $row['attachment_path']);
                                    foreach($files as $idx => $f) {
                                        if(trim($f)) {
                                            echo '<a href="'.$path_to_root.'../uploads/'.htmlspecialchars(trim($f)).'" target="_blank" class="btn btn-xs btn-outline-primary rounded-pill px-3" style="font-size: 0.7rem;">
                                                    <i class="fas fa-paperclip me-1"></i> Dokumen '.($idx+1).'
                                                  </a>';
                                        }
                                    }
                                    echo '</div>';
                                }
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" class="text-center py-5 text-muted">Tidak ada pengajuan ditemukan.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include $path_to_root . 'includes/footer.php'; ?>