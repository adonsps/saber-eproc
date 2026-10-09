<?php
// modules/vendor/profile_action.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// --- PENANGANAN HAPUS DOKUMEN ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_file' && isset($_GET['file_type'])) {
    $file_type = $_GET['file_type'];
    
    $allowed_files = [
        'director_id_card_file' => 'director_id_card_file',
        'bank_cover' => 'bank_cover_path',
        'npwp_file' => 'npwp_path',
        'business_ethics_commitment_file' => 'business_ethics_commitment_file',
        'company_profile' => 'company_profile_file',
        'nib_file' => 'nib_path',
        'deed_of_establishment_file' => 'deed_of_establishment_file',
        'decree_of_legal_entity_file' => 'decree_of_legal_entity_file',
        'deed_of_amendment_file' => 'deed_of_amendment_file',
        'sppkp_file' => 'sppkp_path',
        'business_entity_cert_file' => 'business_entity_cert_file'
    ];

    if (array_key_exists($file_type, $allowed_files)) {
        $db_column = $allowed_files[$file_type];
        
        $stmt_get = $conn->prepare("SELECT $db_column FROM vendors WHERE user_id = ?");
        $stmt_get->bind_param("i", $user_id);
        $stmt_get->execute();
        $res = $stmt_get->get_result()->fetch_assoc();
        
        if ($res && !empty($res[$db_column])) {
            $file_path = dirname(dirname(__DIR__)) . '/../uploads/' . $res[$db_column];
            if (file_exists($file_path)) unlink($file_path);
            
            $stmt_update = $conn->prepare("UPDATE vendors SET $db_column = NULL WHERE user_id = ?");
            $stmt_update->bind_param("i", $user_id);
            $stmt_update->execute();
            
            header("Location: profile.php?msg=" . urlencode("Dokumen berhasil dihapus.") . "&tab=docs");
            exit();
        }
    }
    header("Location: profile.php?error=" . urlencode("Gagal menghapus dokumen.") . "&tab=docs");
    exit();
}

// --- PENANGANAN UPDATE PROFIL ---
if (isset($_POST['update_profile'])) {
    $stmt_vid = $conn->prepare("SELECT * FROM vendors WHERE user_id = ?");
    $stmt_vid->bind_param("i", $user_id);
    $stmt_vid->execute();
    $vendor_db_data = $stmt_vid->get_result()->fetch_assoc();
    
    $vendor_id = $vendor_db_data['id'];
    $current_status = $vendor_db_data['registration_status'] ?? '';
    $current_legal_entity = $vendor_db_data['legal_entity_type'] ?? '';
    $company_name = $vendor_db_data['company_name'] ?? 'Vendor';

    $is_entity_locked = !in_array($current_status, ['registered', 'invited_pq', 'revision_requested']) && !empty($current_legal_entity);
    $legal_entity_type = $is_entity_locked ? $current_legal_entity : ($_POST['legal_entity_type'] ?? null);

    // Basic Data
    $director_name  = mysqli_real_escape_string($conn, $_POST['director_name']  ?? '');
    $director_phone = mysqli_real_escape_string($conn, trim($_POST['director_phone'] ?? ''));
    $is_sppkp       = isset($_POST['is_sppkp']) ? 1 : 0;
    $est_date = !empty($_POST['establishment_date']) ? $_POST['establishment_date'] : null; 
    
    $raw_phone = trim($_POST['phone'] ?? '');
    // intl-tel-input sends E.164 format (e.g. +6281234567890) — strip to digits only
    $clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);
    
    // Validate: 7-15 digits per ITU E.164 standard
    if (!empty($clean_phone) && (strlen($clean_phone) < 7 || strlen($clean_phone) > 15)) {
        header("Location: profile.php?error=" . urlencode("Nomor telepon tidak valid."));
        exit();
    }
    $phone = mysqli_real_escape_string($conn, $clean_phone);

    $contact_person       = mysqli_real_escape_string($conn, $_POST['contact_person'] ?? '');
    $pic_finance = mysqli_real_escape_string($conn, $_POST['pic_finance'] ?? '');
    $email_pic_finance = mysqli_real_escape_string($conn, $_POST['email_pic_finance'] ?? '');
    
    // NEW FIELDS: Bank Name & Branch
    $bank_name = mysqli_real_escape_string($conn, $_POST['bank_name'] ?? '');
    $bank_branch = mysqli_real_escape_string($conn, $_POST['bank_branch'] ?? '');
    $bank_holder_name = mysqli_real_escape_string($conn, trim($_POST['bank_holder_name'] ?? ''));

    // NEW FIELD: Company Address (extracted from NPWP by Gemini, editable)
    $company_address = mysqli_real_escape_string($conn, trim($_POST['company_address'] ?? ''));
    
    // --- 6 DATA OCR & STATUSNYA ---
    function getOcrData($postField, $dbField, $vendor_db_data, $conn) {
        $number = mysqli_real_escape_string($conn, $_POST[$postField] ?? '');
        $post_status = $_POST[$postField . '_status'] ?? 'empty';
        $status = ($post_status === 'saved') ? ($vendor_db_data[$dbField] ?? 'empty') : mysqli_real_escape_string($conn, $post_status);
        return [$number, $status];
    }

    list($nib_number, $nib_status) = getOcrData('nib_number', 'nib_status', $vendor_db_data, $conn);
    list($npwp_number, $npwp_status) = getOcrData('npwp_number', 'npwp_status', $vendor_db_data, $conn);
    list($director_id_number, $director_id_status) = getOcrData('director_id_number', 'director_id_status', $vendor_db_data, $conn);
    list($deed_est_number, $deed_est_status) = getOcrData('deed_of_establishment_number', 'deed_of_establishment_status', $vendor_db_data, $conn);
    list($decree_number, $decree_status) = getOcrData('decree_of_legal_entity_number', 'decree_of_legal_entity_status', $vendor_db_data, $conn);
    list($bank_acc_number, $bank_acc_status) = getOcrData('bank_account_number', 'bank_account_status', $vendor_db_data, $conn);
    
    // Tangkap Field Nama NPWP
    $npwp_name = mysqli_real_escape_string($conn, $_POST['npwp_name'] ?? '');

    // Validasi Duplikasi NIB
    if (!empty($nib_number)) {
        $stmt_check_nib = $conn->prepare("SELECT id FROM vendors WHERE nib_number = ? AND id != ?");
        $stmt_check_nib->bind_param("si", $nib_number, $vendor_id);
        $stmt_check_nib->execute();
        if ($stmt_check_nib->get_result()->num_rows > 0) {
            header("Location: profile.php?error=" . urlencode("Gagal menyimpan! NIB sudah terdaftar.") . "&tab=docs");
            exit();
        }
    }

    // Helper function for File Upload (5MB limit, except company_profile)
    $upload_errors = [];
    
    function handleFileUpload($input_name, $vendor_id, $company_name, $prefix) {
        global $upload_errors;
        $max_size = 5 * 1024 * 1024; // 5MB
        $exempt = ['company_profile']; // No size limit for company profile
        
        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == 0) {
            // Check file size (skip for exempt files)
            if (!in_array($input_name, $exempt) && $_FILES[$input_name]['size'] > $max_size) {
                $size_mb = round($_FILES[$input_name]['size'] / (1024 * 1024), 1);
                $upload_errors[] = "File \"{$prefix}\" terlalu besar ({$size_mb}MB). Maksimal 5MB.";
                return null;
            }
            
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $filename = $_FILES[$input_name]['name'];
            $tmp_name = $_FILES[$input_name]['tmp_name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $root_path = dirname(dirname(__DIR__)); 
                $uploads_base = $root_path . "/../uploads/";
                $v_name_safe = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
                
                $existing_folders = glob($uploads_base . "vendor_" . $vendor_id . "_*", GLOB_ONLYDIR);
                if ($existing_folders && count($existing_folders) > 0) {
                    $target_dir = $existing_folders[0];
                } else {
                    $target_dir = $uploads_base . "vendor_" . $vendor_id . "_" . $v_name_safe;
                    if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
                }

                $new_filename = $prefix . "_" . time() . "." . $ext;
                if (move_uploaded_file($tmp_name, $target_dir . "/" . $new_filename)) {
                    return basename($target_dir) . "/" . $new_filename;
                }
            }
        }
        return null;
    }
    
    // File Upload Execution
    $profile_path = handleFileUpload('company_profile', $vendor_id, $company_name, 'Profile');
    $bank_path = handleFileUpload('bank_cover', $vendor_id, $company_name, 'Bank');
    $npwp_path = handleFileUpload('npwp_file', $vendor_id, $company_name, 'NPWP');
    $sppkp_path = handleFileUpload('sppkp_file', $vendor_id, $company_name, 'SPPKP');
    $nib_path = handleFileUpload('nib_file', $vendor_id, $company_name, 'NIB_Doc'); 
    $deed_est_path = handleFileUpload('deed_of_establishment_file', $vendor_id, $company_name, 'Deed_Est');
    $deed_amd_path = handleFileUpload('deed_of_amendment_file', $vendor_id, $company_name, 'Deed_Amd');
    $decree_path = handleFileUpload('decree_of_legal_entity_file', $vendor_id, $company_name, 'Decree');
    $director_id_path = handleFileUpload('director_id_card_file', $vendor_id, $company_name, 'Dir_ID');
    $business_cert_path = handleFileUpload('business_entity_cert_file', $vendor_id, $company_name, 'Bus_Cert');
    $ethics_doc_path = handleFileUpload('business_ethics_commitment_file', $vendor_id, $company_name, 'Ethics_Doc');

    // Check for file size errors
    if (!empty($upload_errors)) {
        $error_msg = implode(' | ', $upload_errors);
        header("Location: profile.php?error=" . urlencode($error_msg) . "&tab=docs");
        exit();
    }

    // SPPKP doc is mandatory when the vendor declares PKP status
    // and has not already uploaded the file in a previous session.
    if ($is_sppkp === 1) {
        $stmt_spk = $conn->prepare("SELECT sppkp_path FROM vendors WHERE id = ?");
        $stmt_spk->bind_param("i", $vendor_id);
        $stmt_spk->execute();
        $spk_row = $stmt_spk->get_result()->fetch_assoc();
        $existing_sppkp = $spk_row['sppkp_path'] ?? '';

        if (empty($existing_sppkp) && empty($_FILES['sppkp_file']['name'])) {
            header("Location: profile.php?error=" . urlencode("Dokumen SPPKP wajib diunggah karena Anda terdaftar sebagai Pengusaha Kena Pajak (PKP).") . "&tab=docs");
            exit();
        }
    }

    // Tangkap data Kategori Bisnis Utama
    $core_business = mysqli_real_escape_string($conn, $_POST['core_business'] ?? '');

    // --- NPWP NAME → COMPANY NAME OVERRIDE ---
    $apply_npwp_name = trim($_POST['apply_npwp_as_company_name'] ?? '');
    $new_company_name = '';
    if (!empty($apply_npwp_name)) {
        $new_company_name = mysqli_real_escape_string($conn, $apply_npwp_name);
    }

    // Build BIG Update Query (Ditambah core_business, bank_name, bank_branch, bank_holder_name, company_address)
    $sql = "UPDATE vendors SET 
            legal_entity_type = ?, director_name = ?, director_phone = ?, establishment_date = ?, phone = ?, contact_person = ?,
            pic_finance = ?, email_pic_finance = ?, bank_name = ?, bank_branch = ?, bank_holder_name = ?,
            nib_number = ?, nib_status = ?, npwp_number = ?, npwp_status = ?, npwp_name = ?, company_address = ?,
            director_id_number = ?, director_id_status = ?,
            deed_of_establishment_number = ?, deed_of_establishment_status = ?,
            decree_of_legal_entity_number = ?, decree_of_legal_entity_status = ?,
            bank_account_number = ?, bank_account_status = ?, core_business = ?,
            is_sppkp = ?";

    // Apply NPWP name as company name if vendor chose this option
    if (!empty($new_company_name)) {
        $sql .= ", company_name = '$new_company_name'";
    }

    if ($profile_path) $sql .= ", company_profile_file = '$profile_path'";
    if ($bank_path)    $sql .= ", bank_cover_path = '$bank_path'";
    if ($npwp_path)    $sql .= ", npwp_path = '$npwp_path'";
    if ($sppkp_path)   $sql .= ", sppkp_path = '$sppkp_path'";
    if ($nib_path)     $sql .= ", nib_path = '$nib_path'"; 
    if ($deed_est_path)      $sql .= ", deed_of_establishment_file = '$deed_est_path'";
    if ($deed_amd_path)      $sql .= ", deed_of_amendment_file = '$deed_amd_path'";
    if ($decree_path)        $sql .= ", decree_of_legal_entity_file = '$decree_path'";
    if ($director_id_path)   $sql .= ", director_id_card_file = '$director_id_path'";
    if ($business_cert_path) $sql .= ", business_entity_cert_file = '$business_cert_path'";
    if ($ethics_doc_path)    $sql .= ", business_ethics_commitment_file = '$ethics_doc_path'";

    $sql .= " WHERE id = ?";
    
    // Bind 28 Parameters: 26 Strings, 2 Integers (is_sppkp + vendor_id)
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssssssssssssssssssssssssssii",
        $legal_entity_type, $director_name, $director_phone, $est_date, $phone, $contact_person,
        $pic_finance, $email_pic_finance, $bank_name, $bank_branch, $bank_holder_name,
        $nib_number, $nib_status, $npwp_number, $npwp_status, $npwp_name, $company_address,
        $director_id_number, $director_id_status,
        $deed_est_number, $deed_est_status,
        $decree_number, $decree_status,
        $bank_acc_number, $bank_acc_status,
        $core_business,
        $is_sppkp,
        $vendor_id
    );
    
    if ($stmt->execute()) {
        // Update session if company name was changed via NPWP override
        if (!empty($new_company_name)) {
            $_SESSION['company_name'] = $apply_npwp_name;
            $msg = "Profil berhasil diperbarui. Nama Perusahaan telah diubah sesuai NPWP menjadi: " . $apply_npwp_name;
        } else {
            $msg = "Profil berhasil diperbarui.";
        }
        header("Location: profile.php?msg=" . urlencode($msg));
        exit();
    } else {
        $error = urlencode("Database Error: " . $conn->error);
        header("Location: profile.php?error=$error");
        exit();
    }
} else {
    header("Location: profile.php");
    exit();
}
?>