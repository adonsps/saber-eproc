<?php
// modules/vendor/download_result.php
session_start();
require '../../config.php';
require '../../libs/tcpdf/tcpdf.php'; // Ensure TCPDF is loaded
require_once '../../includes/vendor_context.php';

// 1. Security Check (Allow Vendor, Vendor Management, and Sourcing)
$allowed_roles = ['vendor', 'vendor_mgt', 'sourcing'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    die("Access Denied");
}

// A staff correction session (modules/shared/edit_vendor_profile.php) is a
// vendor-role session under the hood — restrict it to profile.php. A real
// vendor_mgt/sourcing session (no impersonator) reaching this page directly is
// unaffected.
vendor_staff_edit_restrict(fn() => die("Access Denied"));

$role = $_SESSION['role'];

// 2. Fetch Data dynamically based on Role
if ($role === 'vendor') {
    // Vendors can only download their own result
    $user_id = $_SESSION['user_id'];
    $sql = "SELECT v.id as vendor_id, v.company_name, v.company_type, v.sub_company_type, v.registration_status,
                   v.is_sppkp, v.wht_tax_type, v.wht_tax_code,
                   s.risk_percentage, s.risk_category, s.approved_at, s.sourcing_notes, s.vm_notes
            FROM vendors v
            JOIN pq_submissions s ON v.id = s.vendor_id
            WHERE v.user_id = ?
            ORDER BY s.id DESC LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
} else {
    // Internal Staff must pass the vendor_id via GET
    if (!isset($_GET['vendor_id'])) {
        die("Error: Vendor ID is required for internal downloads.");
    }
    $target_vendor_id = intval($_GET['vendor_id']);
    
    $sql = "SELECT v.id as vendor_id, v.company_name, v.company_type, v.sub_company_type, v.registration_status,
                   v.is_sppkp, v.wht_tax_type, v.wht_tax_code,
                   s.risk_percentage, s.risk_category, s.approved_at, s.sourcing_notes, s.vm_notes
            FROM vendors v
            JOIN pq_submissions s ON v.id = s.vendor_id
            WHERE v.id = ?
            ORDER BY s.id DESC LIMIT 1";
            
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $target_vendor_id);
}

$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

if (!$data) {
    die("Error: No submission data found.");
}

// 3. Status Check: Only allow download if Approved
if ($data['registration_status'] !== 'approved') {
    // Show a modern HTML alert instead of generating a PDF
    $page_title = "Unduhan Tidak Tersedia";
    $path_to_root = "../../";
    include $path_to_root . 'includes/header.php';
    
    // Determine dynamic styling based on exact status
    $status = $data['registration_status'];
    $status_label = strtoupper(str_replace('_', ' ', $status));
    $badge_class = 'bg-secondary';
    $icon = 'fa-hourglass-half';
    $icon_color = 'text-warning';
    
    if ($status == 'revision_requested') {
        $badge_class = 'bg-danger';
        $icon = 'fa-exclamation-circle';
        $icon_color = 'text-danger';
    } elseif (in_array($status, ['under_review_sourcing', 'under_review_vm', 'returned_to_sourcing'])) {
        $badge_class = 'bg-warning text-dark';
        $icon = 'fa-search';
        $icon_color = 'text-primary';
    } elseif ($status == 'rejected') {
        $badge_class = 'bg-dark';
        $icon = 'fa-times-circle';
        $icon_color = 'text-dark';
    }
    ?>
    <style>
        body { background-color: #f4f6f9; }
        .status-card {
            background: linear-gradient(145deg, #ffffff 0%, #f8f9fa 100%);
            border: 1px solid rgba(0,0,0,0.05);
            box-shadow: 0 15px 35px rgba(0,0,0,0.04);
            border-radius: 24px;
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        .status-card::before {
            content: "";
            position: absolute;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(13, 110, 253, 0.05) 0%, rgba(13, 110, 253, 0) 70%);
            top: -100px;
            right: -100px;
            border-radius: 50%;
            z-index: -1;
        }
        .floating-icon {
            animation: float 3s ease-in-out infinite;
            filter: drop-shadow(0 10px 10px rgba(0,0,0,0.1));
        }
        @keyframes float {
            0% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
            100% { transform: translateY(0px); }
        }
        .btn-interactive {
            transition: all 0.3s ease;
        }
        .btn-interactive:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(13, 110, 253, 0.2);
        }
    </style>

    <div class="container mt-5 pt-4 mb-5">
        <div class="row justify-content-center">
            <div class="col-lg-6 col-md-8">
                <div class="status-card text-center p-5">
                    
                    <div class="mb-4 pt-2">
                        <i class="fas <?= $icon ?> fa-5x <?= $icon_color ?> floating-icon"></i>
                    </div>
                    
                    <h3 class="fw-bold text-dark mb-3">Hasil Belum Siap</h3>
                    
                    <div class="mb-4">
                        <span class="text-muted small text-uppercase fw-bold me-2">Status Saat Ini:</span>
                        <span class="badge <?= $badge_class ?> rounded-pill px-3 py-2 fs-6 shadow-sm"><?= $status_label ?></span>
                    </div>
                    
                    <p class="text-secondary mb-5 px-md-3" style="line-height: 1.6;">
                        <?php if ($role === 'vendor'): ?>
                            Pengajuan Pra-Kualifikasi Anda saat ini sedang dalam proses verifikasi kami. Surat hasil resmi akan otomatis tersedia di sini setelah pengajuan Anda disetujui sepenuhnya oleh tim Procurement kami.
                        <?php else: ?>
                            Pengajuan Pra-Kualifikasi vendor ini belum mencapai persetujuan akhir. Sertifikat belum dapat dibuat pada tahap ini.
                        <?php endif; ?>
                    </p>
                    
                    <div class="d-grid gap-3 d-sm-flex justify-content-sm-center pb-2">
                        <?php if ($role === 'vendor'): ?>
                            <a href="view_pq.php" class="btn btn-primary rounded-pill px-4 py-2 fw-bold btn-interactive">
                                <i class="fas fa-file-contract me-2"></i> Lihat Pengajuan
                            </a>
                            <a href="dashboard.php" class="btn btn-outline-secondary rounded-pill px-4 py-2 fw-bold btn-interactive">
                                <i class="fas fa-home me-2"></i> Dashboard
                            </a>
                        <?php else: ?>
                            <a href="javascript:history.back()" class="btn btn-primary rounded-pill px-4 py-2 fw-bold btn-interactive">
                                <i class="fas fa-arrow-left me-2"></i> Kembali
                            </a>
                        <?php endif; ?>
                    </div>
                    
                </div>
            </div>
        </div>
    </div>
    <?php
    include $path_to_root . 'includes/footer.php';
    exit(); // Stop execution so PDF generation doesn't trigger
}

// 4. Generate PDF (Only runs if status == 'approved')
// Create new PDF document
$pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

// Set document information
$pdf->SetCreator('VMS Portal');
$pdf->SetAuthor('Kawan Lama Group');
$pdf->SetTitle('Hasil Pra-Kualifikasi - ' . $data['company_name']);

// Remove default header/footer
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);

// Set margins
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(TRUE, 15);

// Add a page
$pdf->AddPage();

// Set font
$pdf->SetFont('helvetica', '', 12);

// Base Content for everyone
$html = '
    <h1 style="color:#0056b3;">Hasil Pra-Kualifikasi</h1>
    <hr>
    <table cellpadding="5" cellspacing="2">
        <tr>
            <td width="30%"><strong>Perusahaan:</strong></td>
            <td width="70%">' . htmlspecialchars($data['company_name']) . '</td>
        </tr>
        <tr>
            <td><strong>Status Pengajuan:</strong></td>
            <td style="color:#198754; font-weight:bold;">' . strtoupper(str_replace('_', ' ', $data['registration_status'])) . '</td>
        </tr>
        <tr>
            <td><strong>Tipe:</strong></td>
            <td>' . htmlspecialchars($data['company_type']) . '</td>
        </tr>
        <tr>
            <td><strong>Sub-Kategori:</strong></td>
            <td>' . htmlspecialchars($data['sub_company_type'] ?? 'Belum Diklasifikasikan') . '</td>
        </tr>
        <tr>
            <td><strong>Status PKP:</strong></td>
            <td>' . (!empty($data['is_sppkp']) ? 'PKP (Pengusaha Kena Pajak)' : 'Non-PKP') . '</td>
        </tr>
        <tr>
            <td><strong>WHT Tax Type:</strong></td>
            <td>' . htmlspecialchars($data['wht_tax_type'] ?? '-') . '</td>
        </tr>
        <tr>
            <td><strong>WHT Tax Code:</strong></td>
            <td>' . htmlspecialchars($data['wht_tax_code'] ?? '-') . '</td>
        </tr>
        <tr>
            <td><strong>Tanggal Disetujui:</strong></td>
            <td>' . date('d M Y H:i', strtotime($data['approved_at'])) . '</td>
        </tr>

    </table>
';

// CONDITIONAL CONTENT: Only show sensitive notes if the user is Internal Staff
if ($role !== 'vendor') {
    $html .= '
    <br><br>
    <h3 style="color:#dc3545;">Catatan Penilaian Internal</h3>
    <table border="1" cellpadding="5" cellspacing="0" width="100%">
        <tr style="background-color:#f8d7da; color:#842029;">
            <th width="50%"><strong>Tinjauan Tim Procurement</strong></th>
            <th width="50%"><strong>Keputusan Akhir Procurement</strong></th>
        </tr>
        <tr>
            <td>' . nl2br(htmlspecialchars($data['sourcing_notes'])) . '</td>
            <td>' . nl2br(htmlspecialchars($data['vm_notes'])) . '</td>
        </tr>
    </table>';
}

$html .= '
    <br><br>
    <p style="font-size:10px; color:#666;">Dokumen ini dihasilkan secara otomatis oleh Kawan Lama VSS.</p>
';

// Output the HTML content
$pdf->writeHTML($html, true, false, true, false, '');

// Clean any output buffers before sending PDF
if (ob_get_length()) ob_clean();

// Output PDF to browser
$pdf->Output('Hasil_PQ_' . preg_replace('/[^A-Za-z0-9]/', '_', $data['company_name']) . '.pdf', 'I');
?>