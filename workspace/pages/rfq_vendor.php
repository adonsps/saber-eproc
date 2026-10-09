<?php
// pages/rfq_vendor.php
require_once __DIR__ . '/../../includes/branding.php';
require_once '../config/database.php';
date_default_timezone_set('Asia/Jakarta');

$rfq_id = $_GET['id'] ?? null;
$rfq_info = $pdo->query("SELECT rfq_type, status, deadline FROM rfqs WHERE id = " . (int)$rfq_id)->fetch(PDO::FETCH_ASSOC);
$rfq_type = $rfq_info['rfq_type'] ?? 'Construction';

$rfq_items = [];
if ($rfq_type === 'Standard') {
    $rfq_items = $pdo->query("SELECT id, ticket_line_item, item_description, qty, uom FROM rfq_items WHERE rfq_id = " . (int)$rfq_id)->fetchAll(PDO::FETCH_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start(); // Buffer any stray warnings/errors
    header('Content-Type: application/json');
    
    set_exception_handler(function($e) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage() . ' | File: ' . basename($e->getFile()) . ' Line: ' . $e->getLine()]);
        exit;
    });
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    });

    
    $password = trim(strtoupper($_POST['secret_password']));
    $is_final = isset($_POST['is_final_upload']);
    
    if (!$is_final) {
        // The client formats this field Indonesian-style: '.' as the thousands
        // separator, ',' as the decimal separator (e.g. "10.000.000,50").
        // Strip the thousands separators, then normalize the decimal comma to
        // a decimal point before it reaches a DECIMAL column.
        $raw_price = isset($_POST['total_price']) ? trim((string)$_POST['total_price']) : '0';
        $raw_price = str_replace('.', '', $raw_price);
        $raw_price = str_replace(',', '.', $raw_price);
        $lumpsum = is_numeric($raw_price) ? $raw_price : 0;
        $ppn = 'Exclude';
        $validity = $_POST['price_validity'];
    }

    // --- CHECK TOTAL FILE SIZE (MAX 20 MB) ---
    $max_size = 20 * 1024 * 1024; // 20 MB in bytes
    $total_size = $_FILES['file_cover']['size'] + $_FILES['file_boq']['size'];
    
    if (!empty($_FILES['file_supporting']['name'][0])) {
        $total_size += array_sum($_FILES['file_supporting']['size']);
    }
    
    if ($total_size > $max_size) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Total ukuran semua file melebihi batas 20 MB. Harap kompres file Anda.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT v.id, v.submitted_at, r.deadline, r.rfq_type FROM rfq_vendors v JOIN rfqs r ON v.rfq_id = r.id WHERE v.rfq_id = ? AND TRIM(v.secret_password) = ?");
    $stmt->execute([$rfq_id, $password]);
    $vendor = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$vendor) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Password Rahasia tidak valid.']);
        exit;
    } elseif (!$is_final && strtotime($vendor['deadline']) < time()) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Batas waktu (deadline) untuk tender ini telah berlalu.']);
        exit;
    } elseif (!$is_final && $vendor['submitted_at'] !== null) {
        ob_end_clean();
        echo json_encode(['status' => 'error', 'message' => 'Anda sudah melakukan submit penawaran. Sistem tidak menerima submit ganda.']);
        exit;
    } else {
        // Check if RFQ has committee
        $stmt_comm_count = $pdo->prepare("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = ?");
        $stmt_comm_count->execute([$rfq_id]);
        $has_committee = ($stmt_comm_count->fetchColumn() > 0);
        $status_rfq = $pdo->query("SELECT status FROM rfqs WHERE id = " . (int)$rfq_id)->fetchColumn();
        $winner_id = $pdo->query("SELECT winner_vendor_id FROM rfqs WHERE id = " . (int)$rfq_id)->fetchColumn();

        if ($has_committee) {
            $is_locked_by_committee = $pdo->query("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = " . (int)$rfq_id . " AND has_opened = 1")->fetchColumn() > 0;
            if (!$is_final && ($status_rfq !== 'Active' || $is_locked_by_committee)) {
                ob_end_clean();
                echo json_encode(['status' => 'error', 'message' => 'Segel tender telah dibuka oleh panitia. Penawaran Anda digugurkan atau submit ditutup.']);
                exit;
            }
        } else {
            if (!$is_final && in_array($status_rfq, ['Closed', 'Locked'])) {
                ob_end_clean();
                echo json_encode(['status' => 'error', 'message' => 'Tender telah ditutup. Submit tidak lagi diterima.']);
                exit;
            }
        }
        
        if ($is_final) {
            if ($status_rfq !== 'Closed' || $winner_id != $vendor['id']) {
                ob_end_clean();
                echo json_encode(['status' => 'error', 'message' => 'Akses ditolak. Anda bukan pemenang tender ini.']);
                exit;
            }
        }

        // Dynamically resolve upload directory for Local, QA, and Production
        $upload_dir = __DIR__ . '/../uploads/rfq_uploads/'; // Default workspace uploads
        
        $prod_base = realpath(__DIR__ . '/../../..'); // public_html
        $qa_base = realpath(__DIR__ . '/../..');      // vms (if QA)
        
        if ($prod_base && is_dir($prod_base . '/uploads/')) {
            $upload_dir = $prod_base . '/uploads/rfq_uploads/';
        } elseif ($qa_base && is_dir($qa_base . '/uploads/')) {
            $upload_dir = $qa_base . '/uploads/rfq_uploads/';
        }
        
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0777, true);
        }
        
        $timestamp = time();
        
        $cover_letter_name = $timestamp . "_cover_" . basename($_FILES["file_cover"]["name"]);
        $boq_name = $timestamp . "_boq_" . basename($_FILES["file_boq"]["name"]);
        
        @move_uploaded_file($_FILES["file_cover"]["tmp_name"], $upload_dir . $cover_letter_name);
        @move_uploaded_file($_FILES["file_boq"]["tmp_name"], $upload_dir . $boq_name);

        // --- HANDLE MULTIPLE SUPPORTING DOCUMENTS (ZIP THEM) ---
        $supporting_name = null;
        if (!empty($_FILES['file_supporting']['name'][0])) {
            $zip = new ZipArchive();
            $zip_filename = $timestamp . "_supporting_" . $vendor['id'] . ".zip";
            $zip_path = $upload_dir . $zip_filename;
            
            if ($zip->open($zip_path, ZipArchive::CREATE) === TRUE) {
                $count = count($_FILES['file_supporting']['name']);
                for ($i = 0; $i < $count; $i++) {
                    $tmp_name = $_FILES['file_supporting']['tmp_name'][$i];
                    $name = basename($_FILES['file_supporting']['name'][$i]);
                    $zip->addFile($tmp_name, $name);
                }
                $zip->close();
                $supporting_name = $zip_filename;
            }
        }

        try {
            // --- HANDLE LINE ITEMS IF STANDARD ---
            if ($vendor['rfq_type'] === 'Standard' && isset($_POST['item_price'])) {
                $total_lumpsum = 0;
                $stmt_vi = $pdo->prepare("INSERT INTO rfq_vendor_items (rfq_vendor_id, rfq_item_id, price, lead_time, remarks) VALUES (?, ?, ?, ?, ?)");
                foreach ($_POST['item_price'] as $item_id => $price_str) {
                    $price_clean = str_replace('.', '', trim((string)$price_str));
                    $price_clean = str_replace(',', '.', $price_clean);
                    $price = is_numeric($price_clean) ? (float)$price_clean : 0;
                    $qty = $pdo->query("SELECT qty FROM rfq_items WHERE id = " . (int)$item_id)->fetchColumn();
                    $total_lumpsum += ($price * $qty);
                    $lead = $_POST['item_lead_time'][$item_id] ?? '';
                    $remarks = $_POST['item_remarks'][$item_id] ?? '';
                    $stmt_vi->execute([$vendor['id'], $item_id, $price, $lead, $remarks]);
                }
                $lumpsum = $total_lumpsum;
            }

            if ($is_final) {
                // Update final documents only
                $upd = $pdo->prepare("UPDATE rfq_vendors SET final_cover_letter=?, final_boq=?, final_supporting=? WHERE id=?");
                $upd->execute([$cover_letter_name, $boq_name, $supporting_name, $vendor['id']]);
                
                ob_end_clean();
                echo json_encode(['status' => 'success', 'message' => 'Dokumen Final berhasil diunggah!']);
                exit;
            }

            // Update rfq_vendors
            $upd = $pdo->prepare("UPDATE rfq_vendors SET total_price=?, ppn_status=?, price_validity=?, file_cover_letter=?, file_boq=?, file_supporting=?, submitted_at=NOW() WHERE id=?");
            $upd->execute([$lumpsum, $ppn, $validity, $cover_letter_name, $boq_name, $supporting_name, $vendor['id']]);
            
            // Check if all vendors have submitted
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM rfq_vendors WHERE rfq_id = ? AND submitted_at IS NULL");
            $stmt_check->execute([$rfq_id]);
            if ($stmt_check->fetchColumn() == 0) {
                require_once '../includes/rfq_notification_helper.php';
                notify_all_vendors_submitted($pdo, $rfq_id);
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['status' => 'error', 'message' => 'System error: ' . $e->getMessage()]);
            exit;
        }
        
        ob_end_clean();
        echo json_encode(['status' => 'success', 'message' => 'Penawaran Anda berhasil disubmit dan disegel!']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Portal Vendor - Submit Penawaran</title>
    <?= vss_favicon_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen font-sans">

<div class="bg-white p-8 rounded-lg shadow-xl w-full max-w-3xl border-t-4 border-indigo-600 my-8">
    <h2 class="text-2xl font-bold text-gray-800 mb-2">Submit Penawaran (Tender Tertutup)</h2>
    <p class="text-sm text-gray-500 mb-6">Maksimal TOTAL ukuran semua file yang diupload adalah <strong>20 MB</strong>.</p>

    <div id="alertBox"></div>
    <?php 
        $stmt_comm_count = $pdo->prepare("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = ?");
        $stmt_comm_count->execute([$rfq_id]);
        $has_committee = ($stmt_comm_count->fetchColumn() > 0);
        $status_rfq = $pdo->query("SELECT status FROM rfqs WHERE id = " . (int)$rfq_id)->fetchColumn();
        $is_locked_by_committee = $pdo->query("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = " . (int)$rfq_id . " AND has_opened = 1")->fetchColumn() > 0;
        $winner_id = $pdo->query("SELECT winner_vendor_id FROM rfqs WHERE id = " . (int)$rfq_id)->fetchColumn();
        
        $is_closed = $has_committee ? ($status_rfq !== 'Active' || $is_locked_by_committee) : in_array($status_rfq, ['Closed', 'Locked']);
        
        if ($is_closed): 
            if ($status_rfq === 'Closed' && $winner_id): 
    ?>
        <div class="bg-green-100 border border-green-200 text-green-800 p-4 rounded text-center mt-4 mb-4 shadow-sm">
            <h3 class="font-bold text-lg">Tender Telah Ditutup</h3>
            <p>Jika perusahaan Anda terpilih sebagai <strong>Pemenang</strong>, silakan masukkan Password Rahasia Anda di bawah ini untuk mengunggah <strong>Dokumen Final</strong> (Final Negotiated).</p>
        </div>
        
        <form id="vendorFinalSubmitForm" enctype="multipart/form-data" class="space-y-4 bg-white p-6 rounded shadow border border-green-200">
            <input type="hidden" name="is_final_upload" value="1">
            <div>
                <label class="block text-sm font-bold text-gray-700">Password Rahasia Anda</label>
                <input type="text" name="secret_password" required class="w-full p-2 border border-gray-300 rounded bg-gray-50 uppercase tracking-widest font-mono text-center text-lg" placeholder="******">
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t pt-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700">Surat Penawaran Final (Cover Letter Final)</label>
                    <input type="file" name="file_cover" required accept=".pdf,.doc,.docx" class="w-full p-2 border border-gray-300 rounded">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">BoQ Final (Wajib Excel)</label>
                    <input type="file" name="file_boq" required accept=".xls,.xlsx" class="w-full p-2 border border-gray-300 rounded">
                </div>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700">Dokumen Pendukung Final (Multiple file dibolehkan)</label>
                <input type="file" name="file_supporting[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.zip,.rar" class="w-full p-2 border border-gray-300 rounded">
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 px-4 rounded shadow-lg transition">Unggah Dokumen Final 📤</button>
        </form>

    <?php else: ?>
        <div class="bg-red-100 border border-red-200 text-red-800 p-4 rounded font-bold text-center mt-4 shadow-sm">
            Tender ini sudah tidak menerima penawaran karena penerimaan panawaran telah ditutup.
        </div>
    <?php 
            endif;
        else: 
    ?>
    <form id="vendorSubmitForm" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label class="block text-sm font-bold text-gray-700">Password Rahasia</label>
            <input type="text" name="secret_password" required class="w-full p-2 border border-gray-300 rounded bg-gray-50 uppercase tracking-widest font-mono">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 border-t pt-4">
            <?php if ($rfq_type === 'Construction'): ?>
            <div>
                <label class="block text-sm font-bold text-gray-700">Total Harga Lumpsum (IDR)</label>
                <input type="text" id="total_price" name="total_price" required class="w-full p-2 border border-gray-300 rounded" placeholder="Contoh: 50,000,000">
            </div>
            <?php else: ?>
            <div>
                <label class="block text-sm font-bold text-gray-700">Total Harga Penawaran (IDR)</label>
                <input type="text" id="total_price_display" readonly class="w-full p-2 border border-gray-300 rounded bg-gray-100 font-bold" value="0">
            </div>
            <?php endif; ?>
            <div>
                <label class="block text-sm font-bold text-gray-700">Status PPN 11%</label>
                <input type="text" value="Exclude PPN" readonly class="w-full p-2 border border-gray-200 rounded bg-gray-100 text-gray-600 font-semibold cursor-not-allowed">
                <input type="hidden" name="ppn_status" value="Exclude">
            </div>
        </div>
        
        <div class="bg-yellow-50 border border-yellow-200 p-3 rounded text-xs text-yellow-800 font-semibold flex items-start gap-2">
            <svg class="w-4 h-4 mt-0.5 text-yellow-600 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"></path></svg>
            <p><strong>PENTING:</strong> Nilai di atas otomatis tidak termasuk PPN (Exclude). Namun, jika penawaran dikenakan Pajak Penghasilan (PPh), maka nilai PPh <strong>HARUS SUDAH TERMASUK</strong> dalam Total Harga.</p>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700">Masa Berlaku Harga</label>
            <input type="text" name="price_validity" required class="w-full p-2 border border-gray-300 rounded" placeholder="Contoh: 30 Hari">
        </div>

        <?php if ($rfq_type === 'Standard' && !empty($rfq_items)): ?>
        <div class="border-t pt-4">
            <h3 class="text-md font-bold text-gray-800 mb-3">Detail Penawaran per Item</h3>
            <div class="space-y-4">
                <?php foreach($rfq_items as $item): ?>
                <div class="border border-gray-200 rounded-lg overflow-hidden bg-white shadow-sm hover:shadow transition">
                    <!-- Accordion Trigger Header -->
                    <div class="bg-gray-50 p-4 flex justify-between items-center cursor-pointer hover:bg-gray-100/70 transition select-none accordion-toggle" data-target="item-details-<?= $item['id'] ?>">
                        <div class="flex-grow pr-4">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="bg-indigo-100 text-indigo-800 text-[10px] font-bold px-2 py-0.5 rounded">Item #<?= htmlspecialchars($item['ticket_line_item'] ?? '') ?></span>
                                <span class="text-xs text-gray-500 font-mono">Qty: <?= (float)$item['qty'] ?><?= !empty($item['uom']) ? ' ' . htmlspecialchars($item['uom']) : '' ?></span>
                            </div>
                            <h4 class="font-bold text-gray-800 text-sm leading-snug"><?= htmlspecialchars($item['item_description'] ?? '') ?></h4>
                        </div>
                        <div class="text-right flex items-center gap-3 flex-shrink-0">
                            <div class="hidden sm:block whitespace-nowrap">
                                <span class="text-[9px] text-gray-400 block font-semibold uppercase tracking-wider">Subtotal</span>
                                <span class="font-bold text-indigo-600 font-mono text-sm whitespace-nowrap" id="subtotal-badge-<?= $item['id'] ?>">Rp 0</span>
                            </div>
                            <svg class="w-5 h-5 text-gray-400 transform rotate-180 transition-transform duration-200 chevron-icon" id="chevron-<?= $item['id'] ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                            </svg>
                        </div>
                    </div>
                    
                    <!-- Accordion Content Body -->
                    <div id="item-details-<?= $item['id'] ?>" class="p-4 border-t border-gray-200 bg-white space-y-4 accordion-content">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Harga Satuan (IDR) <span class="text-red-500">*</span></label>
                                <div class="relative rounded-md shadow-sm">
                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                        <span class="text-gray-500 sm:text-sm">Rp</span>
                                    </div>
                                    <input type="text" name="item_price[<?= $item['id'] ?>]" required 
                                           class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded focus:ring-indigo-500 focus:border-indigo-500 text-sm font-mono text-right item-price-input" 
                                           data-qty="<?= (float)$item['qty'] ?>" data-item-id="<?= $item['id'] ?>" placeholder="0">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Lead Time Pengiriman <span class="text-red-500">*</span></label>
                                <input type="text" name="item_lead_time[<?= $item['id'] ?>]" required 
                                       class="w-full p-2 border border-gray-300 rounded text-sm focus:ring-indigo-500 focus:border-indigo-500" 
                                       placeholder="e.g. 7 Hari">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-700 uppercase mb-1">Keterangan / Remarks</label>
                            <input type="text" name="item_remarks[<?= $item['id'] ?>]" 
                                   class="w-full p-2 border border-gray-300 rounded text-sm focus:ring-indigo-500 focus:border-indigo-500" 
                                   placeholder="Opsional">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="border-t pt-4 space-y-4">
            <div>
                <label class="block text-sm font-bold text-gray-700">Surat Penawaran (PDF)</label>
                <input type="file" name="file_cover" accept=".pdf" required class="w-full p-2 border border-gray-300 rounded bg-gray-50 text-sm">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700">Detail Penawaran / BoQ (Excel)</label>
                <input type="file" name="file_boq" accept=".xls,.xlsx" required class="w-full p-2 border border-gray-300 rounded bg-gray-50 text-sm">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700">Dokumen Pendukung Lainnya (Bisa pilih lebih dari 1)</label>
                <p class="text-xs text-gray-500 mb-1">Pilih beberapa file sekaligus. Sistem otomatis akan menggabungkannya menjadi .ZIP.</p>
                <input type="file" name="file_supporting[]" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.png" class="w-full p-2 border border-gray-300 rounded bg-gray-50 text-sm">
            </div>
        </div>

        <button type="submit" id="submitBtn" class="w-full mt-6 bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded shadow transition">Submit & Segel Penawaran</button>
    </form>
    <?php endif; ?>
</div>

<!-- Final Submit Confirmation Modal -->
<div id="finalSubmitConfirmModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-md p-6">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-2xl">⚠️</span>
            <h3 class="text-lg font-bold text-gray-800">PERHATIAN</h3>
        </div>
        <p class="text-sm text-gray-700 mb-6">Penawaran bersifat <strong>FINAL</strong>. Anda TIDAK BISA mengubah harga/file setelah disegel. Lanjutkan?</p>
        <div class="flex justify-end gap-2">
            <button type="button" onclick="closeFinalSubmitConfirmModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded text-sm">Batal</button>
            <button type="button" onclick="confirmFinalSubmit()" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded text-sm shadow">Ya, Lanjutkan</button>
        </div>
    </div>
</div>

<script>
    // Format Rupiah
    const priceInput = document.getElementById('total_price');
    if (priceInput) {
        priceInput.addEventListener('keyup', function(e) {
            let val = this.value.replace(/[^,\d]/g, '').toString();
            let split = val.split(',');
            let sisa = split[0].length % 3;
            let rupiah = split[0].substr(0, sisa);
            let ribuan = split[0].substr(sisa).match(/\d{3}/gi);
            if(ribuan){
                let separator = sisa ? '.' : '';
                rupiah += separator + ribuan.join('.');
            }
            this.value = split[1] != undefined ? rupiah + ',' + split[1] : rupiah;
        });
    }

    let pendingSubmitForm = null;

    function closeFinalSubmitConfirmModal() {
        document.getElementById('finalSubmitConfirmModal').classList.add('hidden');
        pendingSubmitForm = null;
    }

    function confirmFinalSubmit() {
        document.getElementById('finalSubmitConfirmModal').classList.add('hidden');
        if (pendingSubmitForm) {
            proceedFormSubmit(pendingSubmitForm, 'vendorSubmitForm');
            pendingSubmitForm = null;
        }
    }

    function handleFormSubmit(formId) {
        const form = document.getElementById(formId);
        if (!form) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();

            if (formId === 'vendorSubmitForm') {
                pendingSubmitForm = this;
                document.getElementById('finalSubmitConfirmModal').classList.remove('hidden');
                return;
            }

            proceedFormSubmit(this, formId);
        });
    }

    function proceedFormSubmit(form, formId) {
        const btn = form.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.innerHTML = 'Mengunggah... ⏳';
        const alertBox = document.getElementById('alertBox');
        alertBox.innerHTML = '';

        let formData = new FormData(form);

        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            try {
                const data = JSON.parse(text);
                if (data.status === 'error') {
                    alertBox.innerHTML = '<div class="bg-red-100 border border-red-200 text-red-800 p-4 mb-4 rounded font-bold">' + data.message + '</div>';
                    btn.disabled = false;
                    btn.innerHTML = formId === 'vendorFinalSubmitForm' ? 'Unggah Dokumen Final 📤' : 'Submit & Segel Penawaran 🔒';
                    window.scrollTo(0,0);
                } else {
                    alertBox.innerHTML = '<div class="bg-green-100 border border-green-200 text-green-800 p-4 mb-4 rounded font-bold text-center text-lg">' + data.message + '</div>';
                    form.style.display = 'none';
                    window.scrollTo(0,0);
                }
            } catch(e) {
                console.error(text);
                let errMsg = e.message.substring(0, 200).replace(/</g, "&lt;").replace(/>/g, "&gt;");
                alertBox.innerHTML = '<div class="bg-red-100 text-red-800 p-4 mb-4 rounded font-bold">Terjadi kesalahan sistem:<br><span class="font-normal text-sm">' + errMsg + '</span></div>';
                btn.disabled = false;
                btn.innerHTML = formId === 'vendorFinalSubmitForm' ? 'Unggah Dokumen Final 📤' : 'Submit & Segel Penawaran 🔒';
            }
        })
        .catch(error => {
            alertBox.innerHTML = '<div class="bg-red-100 border border-red-200 text-red-800 p-4 mb-4 rounded font-bold">Terjadi kesalahan jaringan.</div>';
            btn.disabled = false;
            btn.innerHTML = formId === 'vendorFinalSubmitForm' ? 'Unggah Dokumen Final 📤' : 'Submit & Segel Penawaran 🔒';
            window.scrollTo(0,0);
        });
    }

    handleFormSubmit('vendorSubmitForm');
    handleFormSubmit('vendorFinalSubmitForm');

    function calcTotal() {
        let grandTotal = 0;
        document.querySelectorAll('.item-price-input').forEach(input => {
            let val = input.value.replace(/[^\d]/g, '');
            let qty = parseFloat(input.dataset.qty);
            let itemId = input.dataset.itemId;
            let price = parseFloat(val);
            let subtotal = qty * price;
            
            const badge = document.getElementById('subtotal-badge-' + itemId);
            if (badge) {
                badge.innerText = 'Rp ' + subtotal.toLocaleString('en-US');
            }
            
            grandTotal += subtotal;
        });
        const tpDisplay = document.getElementById('total_price_display');
        if (tpDisplay) {
            tpDisplay.value = grandTotal.toLocaleString('en-US');
        }
    }

    document.querySelectorAll('.accordion-toggle').forEach(header => {
        header.addEventListener('click', function() {
            const targetId = this.dataset.target;
            const target = document.getElementById(targetId);
            const chevron = this.querySelector('.chevron-icon');
            
            if (target.classList.contains('hidden')) {
                target.classList.remove('hidden');
                chevron.classList.add('rotate-180');
            } else {
                target.classList.add('hidden');
                chevron.classList.remove('rotate-180');
            }
        });
    });

    document.querySelectorAll('.item-price-input').forEach(input => {
        input.addEventListener('input', calcTotal);
    });
</script>
</body>
</html>