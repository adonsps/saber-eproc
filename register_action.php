<?php
// register_action.php
ob_start();
session_start();
require 'config.php';
require_once 'includes/upload_paths.php';
require_once 'includes/vendor_name_similarity.php';
require_once 'includes/form_draft.php';

// LOAD PHPMAILER
if (file_exists('libs/PHPMailer/Exception.php')) {
    require_once 'libs/PHPMailer/Exception.php';
    require_once 'libs/PHPMailer/PHPMailer.php';
    require_once 'libs/PHPMailer/SMTP.php';
}

require_once 'includes/verification_email.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (isset($_POST['register_btn'])) {
    
    // 1. Sanitize Inputs
    $company_name = mysqli_real_escape_string($conn, $_POST['company_name']);
    $contact_person = mysqli_real_escape_string($conn, $_POST['contact_person']);
    $email = mysqli_real_escape_string($conn, $_POST['email']);

    // --- Model Bisnis -> company_type -------------------------------------
    // The vendor never picks its review track directly: it declares what kinds
    // of work it does (multi-select) and company_type is derived from that.
    // Construction/MEP anywhere in the selection wins, so "a + b" is Contractor.
    // The raw answers are stored verbatim for Sourcing/STT to read on review_pq.
    $business_model_codes = sanitize_business_model_codes($_POST['business_model'] ?? []);
    $company_type = company_type_from_business_model($business_model_codes);
    if ($company_type === null) {
        header("Location: register.php?error=" . urlencode("Silakan pilih minimal satu Model Bisnis."));
        exit();
    }
    $business_model = implode(',', $business_model_codes);

    // --- Invitation lookup (only when this submission came from an invite link) ---
    // An invitation pins the category, so it overrides the Model Bisnis
    // derivation above; the vendor's own answers are still recorded. The
    // client-supplied company_type is never trusted here — it's re-derived
    // server-side from vendor_invitations.sub_category so a tampered hidden
    // field can't register outside the invited category.
    $invite_token_raw = preg_replace('/[^a-f0-9]/i', '', trim($_POST['invite_token'] ?? ''));
    if ($invite_token_raw !== '') {
        $inviteStmt = $conn->prepare("SELECT sub_category FROM vendor_invitations WHERE invite_token = ?");
        $inviteStmt->bind_param("s", $invite_token_raw);
        $inviteStmt->execute();
        $invite_row = $inviteStmt->get_result()->fetch_assoc();

        if (!$invite_row) {
            header("Location: login.php?error=" . urlencode("Link registrasi tidak valid atau sudah digunakan. Silakan login atau hubungi tim procurement."));
            exit();
        }

        $sub_category_to_company_type = [
            'Kontraktor'   => 'Contractor',
            'Supplier'     => 'Supplier',
            'Perseorangan' => 'Perseorangan',
        ];
        if (!empty($invite_row['sub_category']) && isset($sub_category_to_company_type[$invite_row['sub_category']])) {
            $company_type = $sub_category_to_company_type[$invite_row['sub_category']];
        }
    }

    // --- START PHONE VALIDATION ---
    // intl-tel-input sends full international number in E.164 format (e.g. +6281234567890)
    $raw_phone = trim($_POST['phone']);
    
    // Strip the leading '+' sign and any non-numeric characters
    $clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);

    // Validate: must be between 7 and 15 digits (ITU E.164 standard)
    if (strlen($clean_phone) < 7 || strlen($clean_phone) > 15) {
        header("Location: register.php?error=" . urlencode("Nomor telepon tidak valid. Silakan pilih kode negara dan masukkan nomor dengan benar."));
        exit();
    }

    $phone = mysqli_real_escape_string($conn, $clean_phone);
    // --- END PHONE VALIDATION ---

    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Validasi Cocok
    if ($password !== $confirm_password) {
        header("Location: register.php?error=" . urlencode("Konfirmasi kata sandi tidak cocok."));
        exit();
    }

    // Validasi Syarat Keamanan di sisi Server (Backend)
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\W_]/', $password)) {
        header("Location: register.php?error=" . urlencode("Kata sandi gagal memenuhi syarat keamanan server."));
        exit();
    }

    // 2. Check Duplicates
    $check = $conn->query("SELECT id FROM users WHERE email = '$email'");
    if ($check->num_rows > 0) {
        header("Location: register.php?error=Email already registered. Please login.");
        exit();
    }

    // 2b. Same COMPANY, different mailbox.
    //
    // The email check above is the only de-duplication this script ever had,
    // which is how one company came to hold an account per address it owned.
    // register.php now asks the vendor to confirm a near-identical name before
    // it submits, but a popup in a browser stops nobody who posts straight to
    // this script — so the same check runs here, and this is the one that
    // actually guards the INSERT.
    //
    // Only 'block' (>= 95% character similarity — typos, punctuation, case) is
    // enforced. 'warn' (same words plus one, e.g. "PT MITRA PRINTING" against
    // "CV MITRA ABADI PRINTING") is advisory by design: roughly half of those
    // pairs are unrelated companies, and walling a legitimate new vendor out of
    // the portal is far worse than letting a duplicate through for VM to spot.
    //
    // The raw POST value is matched, not the escaped $company_name — escaping
    // inserts backslashes that would change how the name normalises.
    $raw_company_name = (string) ($_POST['company_name'] ?? '');
    $dup_check = vendor_duplicate_check($conn, $raw_company_name);

    if ($dup_check['level'] === 'block'
        && (string) ($_POST['duplicate_ack'] ?? '') !== vendor_duplicate_ack_token($raw_company_name)) {
        // Hand the attempt back rather than discarding it — the vendor has to
        // re-read the confirmation, not retype the form.
        form_draft_save('vendor_register', form_draft_collect(
            $_POST,
            ['company_name', 'contact_person', 'email', 'phone']
        ) + ['business_model' => implode(',', $business_model_codes)]);

        ob_end_clean();
        header("Location: register.php?duplicate=1");
        exit();
    }

    // 3. Register User & Vendor
    $conn->begin_transaction();
    try {
        // Claim the invite token atomically (one-time use). This UPDATE only
        // affects a row whose token hasn't already been claimed, so a race
        // between two submissions of the same link resolves to exactly one
        // winner. If the vendor/user insert below fails, the whole
        // transaction (including this claim) rolls back and the token stays
        // usable.
        if ($invite_token_raw !== '') {
            $claimStmt = $conn->prepare("
                UPDATE vendor_invitations
                   SET invite_token = NULL, registered_at = NOW()
                 WHERE invite_token = ? AND invite_token IS NOT NULL
            ");
            $claimStmt->bind_param("s", $invite_token_raw);
            $claimStmt->execute();
            if ($claimStmt->affected_rows !== 1) {
                $conn->rollback();
                ob_end_clean();
                header("Location: login.php?error=" . urlencode("Link registrasi sudah tidak valid atau sudah digunakan. Silakan login."));
                exit();
            }
        }

        // A. Create User Login (Dengan Token & Nonaktif)
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $verification_token = bin2hex(random_bytes(16));
        
        // Multi-CP: the first registrant is the vendor's PRIMARY contact (admin/owner).
        $stmt = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, is_active, verification_token, contact_role, contact_status, phone) VALUES (?, ?, ?, 'vendor', 0, ?, 'primary', 'active', ?)");
        $stmt->bind_param("sssss", $email, $hashed, $contact_person, $verification_token, $phone);
        $stmt->execute();
        $user_id = $conn->insert_id;

        // B. Create Vendor Profile (Initial)
        $stmt_v = $conn->prepare("INSERT INTO vendors (user_id, company_name, company_type, business_model, contact_person, email, phone, registration_status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'registration_submitted', NOW())");
        $stmt_v->bind_param("issssss", $user_id, $company_name, $company_type, $business_model, $contact_person, $email, $phone);
        $stmt_v->execute();
        $vendor_id = $conn->insert_id;

        // Link the primary CP login to its vendor company (multi-CP model).
        $link = $conn->prepare("UPDATE users SET vendor_id = ? WHERE id = ?");
        $link->bind_param("ii", $vendor_id, $user_id);
        $link->execute();

        // --- C. FOLDER CREATION & FILE UPLOAD LOGIC ---
        // The vendor folder must be created on a base that actually exists and is
        // writable — under Docker that is <root>/uploads (the persistent volume,
        // inside the docroot). The old hardcoded "../uploads/" resolved to
        // /var/www in the container, which www-data cannot write, so every
        // registration document was silently discarded. See includes/upload_paths.php.
        $upload_failures   = [];
        $vendor_folder_path = vendor_upload_dir_for((int)$vendor_id, $company_name);

        if ($vendor_folder_path === null) {
            // Keep the account — the vendor can re-upload from their profile once
            // this is resolved — but never report success for documents that were
            // not stored.
            error_log("register_action.php: no writable uploads base; vendor_id={$vendor_id} registered WITHOUT documents");
        } else {
            $products_folder_path = $vendor_folder_path . "/products";
            if (!is_dir($products_folder_path)) {
                @mkdir($products_folder_path, 0775, true);
            }
        }

        /**
         * Returns the DB-relative path on success, or null. A null return with
         * $failed === true means the vendor DID submit a file and it was lost —
         * that must reach the user, which is what this incident was about.
         */
        function uploadFile($file_input_name, $target_dir, $vendor_id, $prefix, $max_mb = 5, &$failed = null) {
            $failed = false;
            if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == 0) {
                $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
                $filename = $_FILES[$file_input_name]['name'];
                $tmp_name = $_FILES[$file_input_name]['tmp_name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $max_size = $max_mb * 1024 * 1024;

                if (!in_array($ext, $allowed) || $_FILES[$file_input_name]['size'] > $max_size) {
                    $failed = true; // rejected by type/size — still worth telling the vendor
                    return null;
                }

                if ($target_dir === null) {
                    $failed = true;
                    return null;
                }

                $new_name = $prefix . "_" . $vendor_id . "_" . time() . "." . $ext;
                if (@move_uploaded_file($tmp_name, $target_dir . "/" . $new_name)) {
                    return vendor_upload_relative_path($target_dir, $new_name);
                }

                $failed = true;
                error_log("register_action.php: move_uploaded_file failed for {$file_input_name}, vendor_id={$vendor_id}, target={$target_dir}");
            }
            return null;
        }

        $doc_labels = [
            'registration_doc' => 'Company Profile',
            'bank_cover'       => 'Buku Tabungan/Bank',
            'npwp_file'        => 'NPWP',
            'sppkp_file'       => 'SPPKP',
            'nib_file'         => 'NIB',
        ];

        $profile_path = uploadFile('registration_doc', $vendor_folder_path, $vendor_id, 'REG_Profile', 30, $f_profile); // 30MB for company profile
        $bank_path    = uploadFile('bank_cover',       $vendor_folder_path, $vendor_id, 'REG_Bank',  5, $f_bank);
        $npwp_path    = uploadFile('npwp_file',        $vendor_folder_path, $vendor_id, 'REG_NPWP',  5, $f_npwp);
        $sppkp_path   = uploadFile('sppkp_file',       $vendor_folder_path, $vendor_id, 'REG_SPPKP', 5, $f_sppkp);
        $nib_path     = uploadFile('nib_file',         $vendor_folder_path, $vendor_id, 'REG_NIB',   5, $f_nib);

        foreach ([
            'registration_doc' => $f_profile, 'bank_cover' => $f_bank, 'npwp_file' => $f_npwp,
            'sppkp_file'       => $f_sppkp,   'nib_file'   => $f_nib,
        ] as $input => $did_fail) {
            if ($did_fail) $upload_failures[] = $doc_labels[$input];
        }

        $sql_update = "UPDATE vendors SET id = id"; 
        $params = [];
        $types = "";

        if ($profile_path) { 
            $sql_update .= ", registration_file = ?"; $params[] = $profile_path; $types .= "s"; 
            $sql_update .= ", company_profile_file = ?"; $params[] = $profile_path; $types .= "s"; 
        }

        // Column names are *_path, not *_file — the input names and the column
        // names differ. The old *_file spellings do not exist on `vendors`, so
        // this UPDATE could only ever have failed; it went unnoticed because it
        // is skipped entirely when no upload succeeded (count($params) > 1), and
        // no upload had succeeded since the Docker cutover.
        if ($bank_path)  { $sql_update .= ", bank_cover_path = ?"; $params[] = $bank_path;  $types .= "s"; }
        if ($npwp_path)  { $sql_update .= ", npwp_path = ?";       $params[] = $npwp_path;  $types .= "s"; }
        if ($sppkp_path) { $sql_update .= ", sppkp_path = ?";      $params[] = $sppkp_path; $types .= "s"; }
        if ($nib_path)   { $sql_update .= ", nib_path = ?";        $params[] = $nib_path;   $types .= "s"; }

        $sql_update .= " WHERE id = ?";
        $params[] = $vendor_id;
        $types .= "i";

        if (count($params) > 1) { 
            $stmt_upd = $conn->prepare($sql_update);
            $stmt_upd->bind_param($types, ...$params);
            $stmt_upd->execute();
        }

        $conn->commit();

        // The attempt landed — nothing left to hand back on the next render.
        form_draft_clear('vendor_register');

        // 4. Send Email Verifikasi
        if (send_vendor_verification_email($email, $contact_person, $verification_token, $company_name)) {
            stamp_verification_sent($conn, $email);
        }

        // Dipakai halaman sukses register.php untuk menawarkan "Kirim Ulang"
        // tanpa pernah menerima alamat email dari input pengguna.
        $_SESSION['pending_verification'] = [
            'email'   => $email,
            'name'    => $contact_person,
            'company' => $company_name,
        ];

        ob_end_clean();
        // Surface any document that did not store. The account exists either way,
        // so this is a warning on top of success — never a silent success.
        if (!empty($upload_failures)) {
            header("Location: register.php?success=1&upload_warning=" . urlencode(implode(', ', $upload_failures)));
            exit();
        }
        header("Location: register.php?success=1");
        exit();

    } catch (Exception $e) {
        $conn->rollback();
        ob_end_clean();
        header("Location: register.php?error=" . urlencode($e->getMessage()));
        exit();
    }
}
ob_end_flush();
?>
