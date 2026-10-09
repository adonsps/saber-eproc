<?php
// modules/vendor/profile_action.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/profile_helper.php';
require_once $path_to_root . 'includes/vendor_context.php';
require_once $path_to_root . 'includes/vendor_profile_draft.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    header("Location: ../../login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
// Multi-CP: resolve the vendor by company id (works for primary AND member CPs).
$vendor_id = (int)($_SESSION['vendor_id'] ?? 0);

// Uploads live in two places depending on deployment: submit_pq.php writes to
// <root>/uploads — the path the production compose file mounts the persistent
// volume on — while this file historically wrote to <root>/../uploads, which on
// the container is /var/www and is NOT writable by www-data (mkdir() fails with
// "Permission denied"). Read from both so existing files keep resolving; write
// only to a base that actually exists and is writable, preferring <root>/uploads.
$vendor_upload_bases = function () {
    $root = dirname(dirname(__DIR__));
    $bases = [];
    foreach ([$root . '/uploads', $root . '/../uploads'] as $candidate) {
        $real = realpath($candidate);
        if ($real && is_dir($real)) $bases[$real] = true;
    }
    return array_keys($bases);
};

// Base to write new files into: first existing candidate that is writable.
$vendor_upload_write_base = function () use ($vendor_upload_bases) {
    foreach ($vendor_upload_bases() as $base) {
        if (is_writable($base)) return $base;
    }
    return null;
};

// Absolute path of a stored relative path ("vendor_1_Foo/NPWP_123.pdf"), or null.
$vendor_upload_resolve = function ($stored) use ($vendor_upload_bases) {
    if (empty($stored)) return null;
    foreach ($vendor_upload_bases() as $base) {
        $path = realpath($base . DIRECTORY_SEPARATOR . $stored);
        if ($path && strpos($path, $base . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            return $path;
        }
    }
    return null;
};

// Nomor hasil ekstraksi AI + status verifikasinya milik dokumen yang dibaca,
// bukan data yang berdiri sendiri: tanpa filenya, keduanya tidak ada buktinya.
// Dipakai di jalur hapus (bawah) dan saat menyimpan form.
$document_number_columns = [
    'director_id_card_file'       => ['director_id_number', 'director_id_status'],
    'bank_cover'                  => ['bank_account_number', 'bank_account_status'],
    'npwp_file'                   => ['npwp_number', 'npwp_status'],
    'nib_file'                    => ['nib_number', 'nib_status'],
    'deed_of_establishment_file'  => ['deed_of_establishment_number', 'deed_of_establishment_status'],
    'decree_of_legal_entity_file' => ['decree_of_legal_entity_number', 'decree_of_legal_entity_status'],
    'umkm_cert_file'              => ['umkm_cert_number', 'umkm_cert_status'],
];

// --- PENANGANAN HAPUS DOKUMEN ---
if (isset($_GET['action']) && $_GET['action'] == 'delete_file' && isset($_GET['file_type'])) {
    // Profile lock: no document changes while the profile is in the PQ /
    // penilaian pipeline. It unlocks on approval or a revision request.
    // Exception: staff editing through the lock (see
    // modules/shared/edit_vendor_profile.php) whose role is allowed to touch
    // documents — vendor_staff_can_edit_documents() decides which; any other
    // impersonator falls through to the same refusal the vendor gets.
    $lockChk = $conn->prepare("SELECT registration_status FROM vendors WHERE id = ?");
    $lockChk->bind_param("i", $vendor_id);
    $lockChk->execute();
    $lockRow = $lockChk->get_result()->fetch_assoc();
    $delete_status_locked = $lockRow && vendor_profile_is_locked($lockRow['registration_status'] ?? '');
    if ($delete_status_locked && !vendor_staff_can_edit_documents()) {
        header("Location: profile.php?error=" . urlencode("Profil terkunci selama proses Pra-Kualifikasi & penilaian. Anda dapat memperbaruinya kembali setelah disetujui oleh tim Procurement.") . "&tab=docs");
        exit();
    }
    // Least-privilege: deleting company documents is destructive → primary only.
    if (!vendor_is_primary()) {
        header("Location: profile.php?error=" . urlencode("Hanya narahubung utama (primary) yang dapat menghapus dokumen.") . "&tab=docs");
        exit();
    }
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
        'business_entity_cert_file' => 'business_entity_cert_file',
        'umkm_cert_file' => 'umkm_cert_file',
        'tax_statement_letter_file' => 'tax_statement_letter_file',
        'bank_holder_statement_letter_file' => 'bank_holder_statement_letter_file'
    ];

    if (array_key_exists($file_type, $allowed_files)) {
        $db_column = $allowed_files[$file_type];
        
        $stmt_get = $conn->prepare("SELECT $db_column FROM vendors WHERE id = ?");
        $stmt_get->bind_param("i", $vendor_id);
        $stmt_get->execute();
        $res = $stmt_get->get_result()->fetch_assoc();
        
        if ($res && !empty($res[$db_column])) {
            $file_path = $vendor_upload_resolve($res[$db_column]);
            if ($file_path) unlink($file_path);
            
            // Nomor & status verifikasi ikut dihapus: menyisakannya membuat
            // halaman reviewer menampilkan badge "Verified" di sebelah dokumen
            // yang sudah tidak ada.
            $clear_sql = "UPDATE vendors SET $db_column = NULL";
            if (isset($document_number_columns[$file_type])) {
                [$number_column, $status_column] = $document_number_columns[$file_type];
                $clear_sql .= ", $number_column = NULL, $status_column = 'empty'";
            }

            $stmt_update = $conn->prepare($clear_sql . " WHERE id = ?");
            $stmt_update->bind_param("i", $vendor_id);
            $stmt_update->execute();

            vca_log($conn, $vendor_id, 'document_deleted', ['details' => 'Dokumen dihapus: ' . $db_column]);
            vendor_doc_audit_log($conn, $vendor_id, $file_type, 'deleted', vendor_profile_actor($delete_status_locked));
            // Deleting it outright is the vendor's own decision about this
            // document, so a later "Buang draf" must not bring the previous
            // file back for it.
            vendor_profile_doc_rollback_forget($vendor_id, $file_type);
            header("Location: profile.php?msg=" . urlencode("Dokumen berhasil dihapus.") . "&tab=docs");
            exit();
        }
    }
    header("Location: profile.php?error=" . urlencode("Gagal menghapus dokumen.") . "&tab=docs");
    exit();
}

// --- PENANGANAN UPDATE PROFIL ---
if (isset($_POST['update_profile'])) {
    $stmt_vid = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
    $stmt_vid->bind_param("i", $vendor_id);
    $stmt_vid->execute();
    $vendor_db_data = $stmt_vid->get_result()->fetch_assoc();

    $vendor_id = $vendor_db_data['id'];
    $current_status = $vendor_db_data['registration_status'] ?? '';
    $current_legal_entity = $vendor_db_data['legal_entity_type'] ?? '';
    $company_name = $vendor_db_data['company_name'] ?? 'Vendor';

    // profile.php drops the HTML `required` attribute for a staff impersonator
    // (they can save partial progress on a vendor's behalf) — the "no
    // placeholder junk" checks below must respect the same relaxation, or a
    // staff member intentionally leaving a field blank would get bounced.
    $is_impersonating = !empty($_SESSION['impersonator']);

    // "Simpan Draft": vendor menyimpan progres yang belum lengkap. Isian ditulis
    // apa adanya ke baris vendors, pemeriksaan "wajib diisi" dilewati, dan
    // vendor TIDAK dinaikkan ke Pra-Kualifikasi — itu hanya terjadi lewat
    // Submit. Pemeriksaan yang sama sudah dilonggarkan untuk staf yang mengisi
    // atas nama vendor, jadi keduanya memakai satu bendera.
    $is_draft      = isset($_POST['save_draft']);
    $allow_partial = $is_impersonating || $is_draft;

    // Derived Contractor/Supplier track (see includes/vendor_business_model.php)
    // — SBUJK stays Contractor-only below. WHT Tax Type/Code is required for
    // every vendor (see profile.php's matching check).
    $company_type_lower = strtolower($vendor_db_data['company_type'] ?? '');
    $is_contractor_vendor = strpos($company_type_lower, 'contractor') !== false || strpos($company_type_lower, 'kontraktor') !== false;

    // Profile lock: reject any edit while the profile is in the PQ / penilaian
    // pipeline. Checked against the status BEFORE this save, so the completing
    // save (which promotes registration_submitted -> invited_pq below) is still
    // allowed; only subsequent edits are blocked until approval/revision.
    //
    // Sourcing/VM editing through modules/shared/edit_vendor_profile.php are the
    // sanctioned exception — see includes/vendor_context.php for who may touch
    // what. The block right below strips the document half from any staff role
    // that is not allowed it.
    $status_locked = vendor_profile_is_locked($current_status);
    if ($status_locked && !vendor_staff_can_edit_profile()) {
        header("Location: profile.php?error=" . urlencode("Profil terkunci selama proses Pra-Kualifikasi & penilaian. Anda dapat memperbaruinya kembali setelah disetujui oleh tim Procurement atau bila diminta melakukan revisi."));
        exit();
    }

    // A staff override may cover profile fields only (no such role today — every
    // reviewing role gets documents too — but the split is kept, see
    // vendor_context.php). Rather than sprinkle role checks through the 200
    // lines of document handling below, neutralise the document half of the
    // request up front: drop the uploads and the delete list, and pin every
    // document-derived field back to what is stored. That last part matters —
    // the form renders those inputs disabled, so the browser posts NOTHING for
    // them, and without this they would be saved as empty.
    // Tracks whether bank_account_number below is the vendor's own fresh input
    // (vs. pinned back to the stored value while documents are locked) — only
    // validate it as "new data" in the former case.
    $docs_editable = !($status_locked && !vendor_staff_can_edit_documents());

    if (!$docs_editable) {
        $_FILES = [];
        unset($_POST['delete_files'], $_POST['apply_npwp_as_company_name']);

        // OCR number fields live inside the Dokumen Legal tab. '<field>_status'
        // = 'saved' makes getOcrData() below reuse the stored verification
        // status instead of overwriting it.
        foreach (['nib_number', 'npwp_number', 'director_id_number',
                  'deed_of_establishment_number', 'decree_of_legal_entity_number',
                  'bank_account_number', 'umkm_cert_number'] as $doc_field) {
            $_POST[$doc_field] = $vendor_db_data[$doc_field] ?? '';
            $_POST[$doc_field . '_status'] = 'saved';
        }
        // npwp_name and company_address are extracted from the NPWP scan and
        // rendered in the same tab, so they belong to the document half too.
        $_POST['npwp_name']       = $vendor_db_data['npwp_name'] ?? '';
        $_POST['company_address'] = $vendor_db_data['company_address'] ?? '';
    }

    // Every validation failure below bounces back to the form. Stash what the
    // vendor typed first so profile.php can re-render their input instead of the
    // last saved row — otherwise one rejected field wipes the whole attempt.
    // Documents are handled separately: they are committed before any of these
    // checks run (see below), so the message says so instead of leaving the
    // vendor to guess whether the files they picked survived.
    $committed_docs = [];
    $reject_and_keep_draft = function (string $message, string $tab = '') use ($vendor_id, &$committed_docs) {
        vendor_profile_draft_save($vendor_id, $_POST);
        if (!empty($committed_docs)) {
            $message .= " Dokumen yang Anda unggah sudah tersimpan — tidak perlu diunggah ulang.";
        }
        header("Location: profile.php?error=" . urlencode($message) . ($tab !== '' ? "&tab=" . urlencode($tab) : ''));
        exit();
    };

    // A field that fails its own check no longer costs the whole save: the
    // stored value is written in its place, the field is named in the message,
    // and every other field still goes through. $reject_and_keep_draft stays
    // for the two kinds of check that cannot be settled one field at a time —
    // the accuracy statement, and a checkbox whose mandatory document is
    // missing (saving is_sppkp = 1 with no SPPKP on file is not a half-save,
    // it is a contradiction).
    $held_fields = [];
    $held_notes  = [];
    $hold_field = function (string $label) use (&$held_fields) {
        if (!in_array($label, $held_fields, true)) $held_fields[] = $label;
    };

    $deletable_files = [
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
        'business_entity_cert_file' => 'business_entity_cert_file',
        'umkm_cert_file' => 'umkm_cert_file',
        'tax_statement_letter_file' => 'tax_statement_letter_file',
        'bank_holder_statement_letter_file' => 'bank_holder_statement_letter_file'
    ];
    $requested_deletes = array_values(array_intersect(
        array_keys($deletable_files),
        is_array($_POST['delete_files'] ?? null) ? $_POST['delete_files'] : []
    ));

    // --- DOKUMEN DISIMPAN LEBIH DULU ---
    //
    // Uploads used to run in the middle of the file, after the field checks.
    // Any $reject_and_keep_draft() before that point exits the request, PHP
    // discards the temp uploads with it, and a browser cannot refill an
    // <input type="file"> — so a single bad NPWP digit made the vendor pick
    // every document again. The session draft cannot help there; it only
    // carries text. Processing and committing the files up front, before the
    // first validation, is what actually keeps them: a rejected save then
    // costs the typing (which the draft restores) and nothing else.
    //
    // Deletes deliberately stay behind, in the success path — unlinking a file
    // cannot be undone if the save is then rejected.

    // Profile "last updated by / at" — actor from session; timestamp in WIB
    // (config.php sets Asia/Jakarta as the default timezone).
    //
    // During a staff override the session IS the vendor, so a naive read would
    // credit the edit to the vendor's contact person. Both view_profile.php
    // pages surface this name to reviewers, so record the real actor instead.
    // Scoped to the override only — Direct Registration keeps its existing
    // behaviour of saving under the vendor account it is building.
    $profile_actor   = vendor_profile_actor($status_locked);
    $updated_by_id   = $profile_actor['id'];
    $updated_by_name = $profile_actor['name'];
    $updated_at      = date('Y-m-d H:i:s');

    // Helper function for File Upload
    // Aturan ukuran: company profile maks 10MB; Akta Pendirian/Perubahan, SBUJK,
    // NIB, NPWP, KTP, SPPKP, SK Pengesahan Badan Hukum, Sertifikasi UMKM, Surat
    // Pernyataan Perbedaan Nama Rekening maks 3MB; Tax Statement Letter/Pakta
    // Integritas maks 1MB; Buku Rekening maks 5MB.
    $upload_errors = [];

    function handleFileUpload($input_name, $vendor_id, $company_name, $prefix) {
        global $upload_errors, $vendor_upload_bases, $vendor_upload_write_base;
        $docs_3mb = ['deed_of_establishment_file', 'deed_of_amendment_file', 'business_entity_cert_file', 'nib_file', 'npwp_file', 'director_id_card_file', 'sppkp_file', 'decree_of_legal_entity_file', 'umkm_cert_file', 'bank_holder_statement_letter_file'];
        $docs_1mb = ['business_ethics_commitment_file', 'tax_statement_letter_file'];

        if (isset($_FILES[$input_name]) && in_array($_FILES[$input_name]['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            $upload_errors[] = "File \"{$prefix}\" melebihi batas ukuran upload server.";
            return null;
        }

        if (isset($_FILES[$input_name]) && $_FILES[$input_name]['error'] == UPLOAD_ERR_OK) {
            // Check file size
            if ($input_name === 'company_profile') {
                $max_size = 10 * 1024 * 1024;
                $max_label = '10MB';
            } elseif (in_array($input_name, $docs_3mb, true)) {
                $max_size = 3 * 1024 * 1024;
                $max_label = '3MB';
            } elseif (in_array($input_name, $docs_1mb, true)) {
                $max_size = 1 * 1024 * 1024;
                $max_label = '1MB';
            } else {
                $max_size = 5 * 1024 * 1024;
                $max_label = '5MB';
            }
            if ($_FILES[$input_name]['size'] > $max_size) {
                $size_mb = round($_FILES[$input_name]['size'] / (1024 * 1024), 1);
                $upload_errors[] = "File \"{$prefix}\" terlalu besar ({$size_mb}MB). Maksimal {$max_label}.";
                return null;
            }
            
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            $filename = $_FILES[$input_name]['name'];
            $tmp_name = $_FILES[$input_name]['tmp_name'];
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (in_array($ext, $allowed)) {
                $v_name_safe = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);

                // Reuse the vendor's existing folder wherever it already lives.
                $target_dir = null;
                foreach ($vendor_upload_bases() as $base) {
                    $existing = glob($base . "/vendor_" . $vendor_id . "_*", GLOB_ONLYDIR);
                    if ($existing && count($existing) > 0 && is_writable($existing[0])) {
                        $target_dir = $existing[0];
                        break;
                    }
                }

                if ($target_dir === null) {
                    $write_base = $vendor_upload_write_base();
                    if ($write_base === null) {
                        $upload_errors[] = "File \"{$prefix}\" gagal disimpan: folder uploads tidak dapat ditulis.";
                        return null;
                    }
                    $target_dir = $write_base . "/vendor_" . $vendor_id . "_" . $v_name_safe;
                    if (!is_dir($target_dir) && !@mkdir($target_dir, 0775, true) && !is_dir($target_dir)) {
                        $upload_errors[] = "File \"{$prefix}\" gagal disimpan: folder tujuan tidak dapat dibuat.";
                        return null;
                    }
                }

                $new_filename = $prefix . "_" . time() . "." . $ext;
                if (@move_uploaded_file($tmp_name, $target_dir . "/" . $new_filename)) {
                    return basename($target_dir) . "/" . $new_filename;
                }
                $upload_errors[] = "File \"{$prefix}\" gagal disimpan ke server.";
            }
        }
        return null;
    }
    
    // File Upload Execution
    $profile_path = handleFileUpload('company_profile', $vendor_id, $company_name, 'Profile');
    $bank_path = handleFileUpload('bank_cover', $vendor_id, $company_name, 'Bank');
    $npwp_path = handleFileUpload('npwp_file', $vendor_id, $company_name, 'NPWP');
    $sppkp_path = handleFileUpload('sppkp_file', $vendor_id, $company_name, 'SPPKP');
    $umkm_cert_path = handleFileUpload('umkm_cert_file', $vendor_id, $company_name, 'UMKM_Cert');
    $nib_path = handleFileUpload('nib_file', $vendor_id, $company_name, 'NIB_Doc'); 
    $deed_est_path = handleFileUpload('deed_of_establishment_file', $vendor_id, $company_name, 'Deed_Est');
    $deed_amd_path = handleFileUpload('deed_of_amendment_file', $vendor_id, $company_name, 'Deed_Amd');
    $decree_path = handleFileUpload('decree_of_legal_entity_file', $vendor_id, $company_name, 'Decree');
    $director_id_path = handleFileUpload('director_id_card_file', $vendor_id, $company_name, 'Dir_ID');
    $business_cert_path = handleFileUpload('business_entity_cert_file', $vendor_id, $company_name, 'Bus_Cert');
    $ethics_doc_path = handleFileUpload('business_ethics_commitment_file', $vendor_id, $company_name, 'Ethics_Doc');
    $tax_statement_letter_path = handleFileUpload('tax_statement_letter_file', $vendor_id, $company_name, 'Tax_Statement');
    $bank_holder_statement_path = handleFileUpload('bank_holder_statement_letter_file', $vendor_id, $company_name, 'Bank_Holder_Statement');

    $uploaded_files = [
        'company_profile' => $profile_path,
        'bank_cover' => $bank_path,
        'npwp_file' => $npwp_path,
        'sppkp_file' => $sppkp_path,
        'umkm_cert_file' => $umkm_cert_path,
        'nib_file' => $nib_path,
        'deed_of_establishment_file' => $deed_est_path,
        'deed_of_amendment_file' => $deed_amd_path,
        'decree_of_legal_entity_file' => $decree_path,
        'director_id_card_file' => $director_id_path,
        'business_entity_cert_file' => $business_cert_path,
        'business_ethics_commitment_file' => $ethics_doc_path,
        'tax_statement_letter_file' => $tax_statement_letter_path,
        'bank_holder_statement_letter_file' => $bank_holder_statement_path
    ];

    // --- NPWP DIPERIKSA SEBAGAI SATU KESATUAN, SEBELUM COMMIT ---
    //
    // The 16-digit rule used to run far below, after the documents had already
    // been committed: the number was put back to the stored one while the new
    // scan stayed on the row — a fresh NPWP card sitting behind an old number,
    // with npwp_name and company_address from that same scan saved alongside it.
    // The card, its number and the two fields extracted from it are one
    // document, so they are refused together, here, before anything is written.
    if ($docs_editable && !empty($_POST['npwp_number']) && !is_array($_POST['npwp_number'])) {
        $npwp_digits        = preg_replace('/\D+/', '', (string) $_POST['npwp_number']);
        $stored_npwp_digits = preg_replace('/\D+/', '', (string) ($vendor_db_data['npwp_number'] ?? ''));
        // Only a NEW/changed number is refused, so a legacy 15-digit record
        // editing unrelated fields still saves.
        if ($npwp_digits !== $stored_npwp_digits && strlen($npwp_digits) !== 16) {
            if (!empty($npwp_path)) {
                $npwp_abs = $vendor_upload_resolve($npwp_path);
                if ($npwp_abs) @unlink($npwp_abs);
                $npwp_path = null;
                $uploaded_files['npwp_file'] = null;
            }
            // Pinned back so neither the commit below nor the big UPDATE writes
            // any part of this document. '_status' = 'saved' keeps the stored
            // verification badge — the same rule getOcrData() applies.
            $_POST['npwp_number']        = (string) ($vendor_db_data['npwp_number'] ?? '');
            $_POST['npwp_number_status'] = 'saved';
            $_POST['npwp_name']          = (string) ($vendor_db_data['npwp_name'] ?? '');
            $_POST['company_address']    = (string) ($vendor_db_data['company_address'] ?? '');
            $hold_field("Nomor NPWP (harus 16 digit — unggah ulang dokumen NPWP format terbaru)");
        }
    }

    // Commit the paths now, independently of whether the rest of the form
    // passes. The big UPDATE further down writes the same values again on a
    // successful save, so this is only ever a no-op repeat, never a conflict.
    // $vendor_db_data keeps the PRE-save paths on purpose — the delete/unlink
    // pass at the bottom still needs them to find the old file on disk.
    // The OCR numbers belong to the document they were extracted from, so they
    // ride along in the same early commit. Committing the file but leaving its
    // number in the session draft is what let "Buang Perubahan" wipe the number
    // while the document stayed on the row. Keyed number field => its status
    // column, null for the two NPWP extras that carry no verification badge.
    $document_ocr_fields = [
        'npwp_file'                   => ['npwp_number' => 'npwp_status', 'npwp_name' => null, 'company_address' => null],
        'nib_file'                    => ['nib_number' => 'nib_status'],
        'director_id_card_file'       => ['director_id_number' => 'director_id_status'],
        'deed_of_establishment_file'  => ['deed_of_establishment_number' => 'deed_of_establishment_status'],
        'decree_of_legal_entity_file' => ['decree_of_legal_entity_number' => 'decree_of_legal_entity_status'],
        'bank_cover'                  => ['bank_account_number' => 'bank_account_status'],
        'umkm_cert_file'              => ['umkm_cert_number' => 'umkm_cert_status'],
    ];

    $document_commit_sets   = [];
    $document_commit_values = [];
    $document_commit_types  = '';
    $document_ocr_previous  = [];
    foreach ($uploaded_files as $doc_key => $new_path) {
        if (empty($new_path)) continue;
        $committed_docs[] = $doc_key;
        // Paths are server-generated (prefix + timestamp + extension inside the
        // vendor's own folder), never vendor input, so inlining them is safe —
        // same as the big UPDATE below does.
        $document_commit_sets[] = $deletable_files[$doc_key] . " = '" . $new_path . "'";

        foreach ($document_ocr_fields[$doc_key] ?? [] as $number_field => $status_column) {
            if (!array_key_exists($number_field, $_POST) || is_array($_POST[$number_field])) continue;

            // Only ever ADD what the extraction produced. An empty value here
            // would blank a stored number on an attempt that was never
            // accepted, and leave the form showing an empty required field.
            // The success path below still writes the empty value when the
            // vendor really did clear it.
            $ocr_value = (string) $_POST[$number_field];
            if (trim($ocr_value) === '') continue;

            $document_ocr_previous[$doc_key][$number_field] = $vendor_db_data[$number_field] ?? null;
            $document_commit_sets[]   = $number_field . " = ?";
            $document_commit_values[] = $ocr_value;
            $document_commit_types   .= 's';

            if ($status_column === null) continue;
            // Same rule getOcrData() applies below: 'saved' means "keep the
            // stored verification status", not "overwrite it with the word".
            $post_status = (string) ($_POST[$number_field . '_status'] ?? 'empty');
            $document_ocr_previous[$doc_key][$status_column] = $vendor_db_data[$status_column] ?? null;
            $document_commit_sets[]   = $status_column . " = ?";
            $document_commit_values[] = ($post_status === 'saved') ? (string) ($vendor_db_data[$status_column] ?? 'empty') : $post_status;
            $document_commit_types   .= 's';
        }
    }

    if (!empty($document_commit_sets)) {
        $commit_sql = "UPDATE vendors SET " . implode(', ', $document_commit_sets)
            . ", last_updated_by_user_id = ?, last_updated_by_name = ?, last_updated_at = ? WHERE id = ?";
        $commit_stmt = $conn->prepare($commit_sql);
        $commit_params = array_merge($document_commit_values, [$updated_by_id, $updated_by_name, $updated_at, $vendor_id]);
        $commit_stmt->bind_param($document_commit_types . "issi", ...$commit_params);
        $commit_stmt->execute();

        // Logged here rather than in the success block: the file is on record
        // from this moment, even if the save is rejected a few lines down. A
        // later successful save re-writes the same path but uploads nothing, so
        // there is no second entry for it.
        foreach ($committed_docs as $doc_key) {
            $audit_id = null;
            vendor_doc_audit_log($conn, $vendor_id, $doc_key, 'uploaded', $profile_actor, $audit_id);

            // The commit above is not the vendor's final word — they may still
            // hit "Buang draf" instead of fixing the rejected field. Leave a
            // receipt so that button can put the old document back, file, audit
            // row and OCR numbers included.
            $rollback_column = $deletable_files[$doc_key];
            vendor_profile_doc_rollback_record(
                $vendor_id,
                $doc_key,
                $rollback_column,
                $vendor_db_data[$rollback_column] ?? null,
                $uploaded_files[$doc_key],
                $audit_id,
                $document_ocr_previous[$doc_key] ?? []
            );
        }
    }

    // A refused file never made it into $uploaded_files, so nothing about it is
    // half-written — the rest of the form saves around it and the reason is
    // carried through to the message.
    foreach ($upload_errors as $upload_error) {
        $held_notes[] = $upload_error;
    }

    $is_entity_locked = !in_array($current_status, ['registration_submitted', 'registered', 'invited_pq', 'migrated', 'revision_requested']) && !empty($current_legal_entity);
    $legal_entity_type = $is_entity_locked ? $current_legal_entity : ($_POST['legal_entity_type'] ?? null);

    // Basic Data
    $director_name  = mysqli_real_escape_string($conn, $_POST['director_name']  ?? '');
    $director_phone = mysqli_real_escape_string($conn, trim($_POST['director_phone'] ?? ''));
    $is_sppkp       = isset($_POST['is_sppkp']) ? 1 : 0;
    // UMKM decides which PQ question set the vendor answers, so it is frozen by
    // the same lock as legal_entity_type (and the checkbox is rendered disabled,
    // which means the browser posts nothing for it — fall back to the stored value).
    $is_umkm        = $is_entity_locked
        ? (int) ($vendor_db_data['is_umkm'] ?? 0)
        : (isset($_POST['is_umkm']) ? 1 : 0);
    $est_date = !empty($_POST['establishment_date']) ? $_POST['establishment_date'] : null; 
    
    $raw_phone = trim($_POST['phone'] ?? '');
    // intl-tel-input sends E.164 format (e.g. +6281234567890) — strip to digits only
    $clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);
    
    // Validate: 7-15 digits per ITU E.164 standard
    if (!empty($clean_phone) && (strlen($clean_phone) < 7 || strlen($clean_phone) > 15)) {
        $clean_phone = preg_replace('/[^0-9]/', '', (string) ($vendor_db_data['phone'] ?? ''));
        $hold_field("Nomor Telepon");
    }
    $phone = mysqli_real_escape_string($conn, $clean_phone);

    $contact_person       = mysqli_real_escape_string($conn, $_POST['contact_person'] ?? '');
    $pic_finance = mysqli_real_escape_string($conn, $_POST['pic_finance'] ?? '');
    $email_pic_finance = mysqli_real_escape_string($conn, $_POST['email_pic_finance'] ?? '');

    // NEW FIELDS: Bank Name & Branch
    $bank_name = mysqli_real_escape_string($conn, $_POST['bank_name'] ?? '');
    $bank_branch = mysqli_real_escape_string($conn, $_POST['bank_branch'] ?? '');
    // "Nama Pemilik Rekening sama dengan Nama Perusahaan?" — a UI-only checkbox,
    // nothing about it is stored. When checked the field renders readonly and
    // locked to company_name, but a readonly input still POSTs whatever value
    // it holds, so the name is pinned to company_name server-side too rather
    // than trusting whatever came in. profile.php re-derives the dropdown's
    // selected state on the next render by comparing the saved bank_holder_name
    // back against company_name.
    $bank_holder_is_company = (($_POST['bank_holder_is_company'] ?? '') === '1') ? 1 : 0;
    $bank_holder_name = $bank_holder_is_company === 1
        ? mysqli_real_escape_string($conn, trim($company_name))
        : mysqli_real_escape_string($conn, trim($_POST['bank_holder_name'] ?? ''));

    // Reject placeholder junk ("-", ".", "--") and keyboard-mash values with
    // stray symbols ("@bca-") in every required free-text field — these pass
    // the HTML `required` check (non-empty) but aren't real data. Email
    // fields are excluded (type="email" already has its own format check).
    // Skipped entirely for a staff impersonator and for "Simpan Draft", both
    // of which are allowed to leave these blank (see profile.php's $req).
    if (!$allow_partial) {
        $required_text_fields = [
            'director_name'     => ['Nama Direktur / Pemilik', $director_name],
            'contact_person'    => ['Contact Person / Narahubung', $contact_person],
            'pic_finance'       => ['PIC Finance', $pic_finance],
            'bank_name'         => ['Nama Bank', $bank_name],
            'bank_branch'       => ['Cabang Bank', $bank_branch],
            'bank_holder_name'  => ['Nama Pemilik Rekening', $bank_holder_name],
        ];
        // The keys above are both the column name and the local variable name,
        // so holding one back is a matter of putting the stored value back into
        // the variable the big UPDATE binds.
        foreach ($required_text_fields as $column => [$label, $value]) {
            if (vendor_is_blank_placeholder($value) || vendor_has_invalid_name_characters($value) || vendor_has_edge_dash($value)) {
                $$column = mysqli_real_escape_string($conn, (string) ($vendor_db_data[$column] ?? ''));
                $hold_field($label);
            }
        }
    }

    // NEW FIELD: Company Address (extracted from NPWP by Gemini, editable)
    $company_address = mysqli_real_escape_string($conn, trim($_POST['company_address'] ?? ''));

    // NEW FIELD: Jumlah Karyawan — required, whole number, must be > 0. The form
    // posts it with "." thousand separators; strip everything non-numeric first.
    $employee_digits = preg_replace('/\D/', '', (string) ($_POST['number_of_employees'] ?? ''));
    $number_of_employees = ($employee_digits === '') ? null : (int) $employee_digits;
    // Sourcing filling this in on the vendor's behalf (Direct Registration) is
    // allowed to save partial progress, so only hard-require a value outside
    // impersonation — but any value that IS given must be a positive number.
    if (($number_of_employees === null && !$allow_partial)
        || ($number_of_employees !== null && $number_of_employees < 1)) {
        $stored_employees    = $vendor_db_data['number_of_employees'] ?? null;
        $number_of_employees = ($stored_employees === null || $stored_employees === '') ? null : (int) $stored_employees;
        $hold_field("Jumlah Karyawan");
    }

    // With Holding Tax (WHT) — picked from a fixed reference list in
    // profile.php, which includes an "N/A" entry for non-construction
    // (Supplier) vendors. Required for every vendor.
    $wht_tax_type = mysqli_real_escape_string($conn, trim($_POST['wht_tax_type'] ?? ''));
    $wht_tax_code = mysqli_real_escape_string($conn, trim($_POST['wht_tax_code'] ?? ''));
    if (!$allow_partial && (empty($wht_tax_type) || empty($wht_tax_code))) {
        // The pair is picked together from one reference list, so it is held
        // together — half of it would not identify a tax treatment.
        $wht_tax_type = mysqli_real_escape_string($conn, (string) ($vendor_db_data['wht_tax_type'] ?? ''));
        $wht_tax_code = mysqli_real_escape_string($conn, (string) ($vendor_db_data['wht_tax_code'] ?? ''));
        $hold_field("WHT Tax Type & Code");
    }

    // Data accuracy declaration — the vendor must tick the checkbox in
    // profile.php's Dokumen Legal tab before the save goes through. The
    // checkbox's own `required` attribute is JS/browser-only, so this mirrors
    // that gate server-side, and the resulting flag is persisted below so the
    // confirmation is on record against this profile (with who/when already
    // captured by last_updated_by_name / last_updated_at further down).
    $data_accuracy_confirmed = (($_POST['data_accuracy_confirmed'] ?? '') === '1') ? 1 : 0;
    if (!$allow_partial && !$data_accuracy_confirmed) {
        $reject_and_keep_draft("Anda harus menyetujui pernyataan kesesuaian data sebelum menyimpan.");
    }

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
    list($umkm_cert_number, $umkm_cert_status) = getOcrData('umkm_cert_number', 'umkm_cert_status', $vendor_db_data, $conn);

    if (!$allow_partial && $docs_editable && (vendor_is_blank_placeholder($bank_acc_number) || vendor_has_invalid_account_number_characters($bank_acc_number) || vendor_has_edge_dash($bank_acc_number))) {
        $bank_acc_number = mysqli_real_escape_string($conn, (string) ($vendor_db_data['bank_account_number'] ?? ''));
        $bank_acc_status = $vendor_db_data['bank_account_status'] ?? 'empty';
        $hold_field("Nomor Rekening");
    }

    // Tangkap Field Nama NPWP
    $npwp_name = mysqli_real_escape_string($conn, $_POST['npwp_name'] ?? '');

    // The 16-digit NPWP rule ran here before. It now runs above, before the
    // documents are committed, so the card and its number are refused together
    // — by this point $_POST already holds the stored values.

    // Validasi Duplikasi NIB
    if (!empty($nib_number)) {
        $stmt_check_nib = $conn->prepare("SELECT id FROM vendors WHERE nib_number = ? AND id != ?");
        $stmt_check_nib->bind_param("si", $nib_number, $vendor_id);
        $stmt_check_nib->execute();
        if ($stmt_check_nib->get_result()->num_rows > 0) {
            $nib_number = mysqli_real_escape_string($conn, (string) ($vendor_db_data['nib_number'] ?? ''));
            $nib_status = $vendor_db_data['nib_status'] ?? 'empty';
            $hold_field("Nomor NIB (sudah terdaftar atas vendor lain)");
        }
    }



    // The three conditional "wajib" documents below (SPPKP, Sertifikasi UMKM,
    // Surat Pernyataan Perbedaan Nama Rekening) are still demanded of the
    // vendor and of Direct Registration. A staff CORRECTION session is exempt,
    // like the SBUJK check further down already exempts every impersonator:
    // the reviewer came to replace one wrong file, and a profile that was
    // submitted before one of these rules existed must not block that fix.
    // The vendor is asked for the missing document again the next time they
    // save their own profile, exactly as before.
    $staff_correction = vendor_staff_edit_session();

    // SPPKP doc is mandatory when the vendor declares PKP status
    // and has not already uploaded the file in a previous session.
    if ($is_sppkp === 1 && !$staff_correction && !$is_draft) {
        $stmt_spk = $conn->prepare("SELECT sppkp_path FROM vendors WHERE id = ?");
        $stmt_spk->bind_param("i", $vendor_id);
        $stmt_spk->execute();
        $spk_row = $stmt_spk->get_result()->fetch_assoc();
        $existing_sppkp = $spk_row['sppkp_path'] ?? '';

        $sppkp_marked_for_deletion = in_array('sppkp_file', $requested_deletes, true);
        if ((empty($existing_sppkp) || $sppkp_marked_for_deletion) && empty($sppkp_path)) {
            $reject_and_keep_draft("Dokumen SPPKP wajib diunggah karena Anda terdaftar sebagai Pengusaha Kena Pajak (PKP).", "docs");
        }
    }

    // Sertifikasi UMKM doc is mandatory when the vendor declares itself UMKM
    // and has not already uploaded the file in a previous session.
    if ($is_umkm === 1 && !$staff_correction && !$is_draft) {
        $stmt_umkm = $conn->prepare("SELECT umkm_cert_file FROM vendors WHERE id = ?");
        $stmt_umkm->bind_param("i", $vendor_id);
        $stmt_umkm->execute();
        $umkm_row = $stmt_umkm->get_result()->fetch_assoc();
        $existing_umkm_cert = $umkm_row['umkm_cert_file'] ?? '';

        $umkm_cert_marked_for_deletion = in_array('umkm_cert_file', $requested_deletes, true);
        if ((empty($existing_umkm_cert) || $umkm_cert_marked_for_deletion) && empty($umkm_cert_path)) {
            $reject_and_keep_draft("Dokumen Sertifikasi UMKM wajib diunggah karena Anda terdaftar sebagai UMKM.", "docs");
        }
    }

    // Mandatory when bank account holder name != company name.
    if ($bank_holder_is_company === 0 && !$staff_correction && !$is_draft) {
        $stmt_bhs = $conn->prepare("SELECT bank_holder_statement_letter_file FROM vendors WHERE id = ?");
        $stmt_bhs->bind_param("i", $vendor_id);
        $stmt_bhs->execute();
        $bhs_row = $stmt_bhs->get_result()->fetch_assoc();
        $existing_bank_holder_statement = $bhs_row['bank_holder_statement_letter_file'] ?? '';

        $bank_holder_statement_marked_for_deletion = in_array('bank_holder_statement_letter_file', $requested_deletes, true);
        if ((empty($existing_bank_holder_statement) || $bank_holder_statement_marked_for_deletion) && empty($bank_holder_statement_path)) {
            $reject_and_keep_draft("Surat Pernyataan Perbedaan Nama Rekening wajib diunggah karena nama pemilik rekening berbeda dengan nama perusahaan.", "docs");
        }
    }

    // SBUJK is mandatory for a Badan Usaha Contractor and has not already been
    // uploaded in a previous session; a Perseorangan Contractor may upload it,
    // but is not required to.
    $sbujk_required = $is_contractor_vendor && (($legal_entity_type ?: $current_legal_entity) === 'Badan Usaha');
    if (!$allow_partial && $sbujk_required) {
        $stmt_sbujk = $conn->prepare("SELECT business_entity_cert_file FROM vendors WHERE id = ?");
        $stmt_sbujk->bind_param("i", $vendor_id);
        $stmt_sbujk->execute();
        $sbujk_row = $stmt_sbujk->get_result()->fetch_assoc();
        $existing_sbujk = $sbujk_row['business_entity_cert_file'] ?? '';

        $sbujk_marked_for_deletion = in_array('business_entity_cert_file', $requested_deletes, true);
        if ((empty($existing_sbujk) || $sbujk_marked_for_deletion) && empty($business_cert_path)) {
            $reject_and_keep_draft("Sertifikat Badan Usaha Jasa Konstruksi (SBUJK) wajib diunggah untuk vendor jasa konstruksi.", "docs");
        }
    }

    // Tangkap data Kategori Bisnis Utama
    $core_business = mysqli_real_escape_string($conn, $_POST['core_business'] ?? '');
    $business_description = mysqli_real_escape_string($conn, trim($_POST['business_description'] ?? ''));

    // --- NPWP NAME → COMPANY NAME OVERRIDE ---
    $apply_npwp_name = trim($_POST['apply_npwp_as_company_name'] ?? '');
    $new_company_name = '';
    if (!empty($apply_npwp_name)) {
        $new_company_name = mysqli_real_escape_string($conn, $apply_npwp_name);
    }

    // --- NOMOR OCR HANYA IKUT TERSIMPAN BILA DOKUMENNYA ADA ---
    //
    // Nomor hasil ekstraksi dikirim dari input teks yang terpisah dari input
    // file, jadi ia tetap sampai ke sini walau filenya batal tersimpan —
    // ekstensi di luar daftar, ukuran lewat batas, folder tujuan gagal ditulis,
    // atau dokumennya justru sedang dihapus. Menyimpannya sendirian
    // meninggalkan baris "Extracted ID + Verified" di samping tombol
    // "Not Uploaded" pada halaman reviewer.
    $doc_will_be_cleared = function (string $doc_key) use ($uploaded_files, $requested_deletes): bool {
        if (!in_array($doc_key, $requested_deletes, true)) return false;
        if (!empty($uploaded_files[$doc_key])) return false;     // diganti file baru
        return empty($_FILES[$doc_key]['name']);                  // sama seperti syarat penghapusan di bawah
    };
    $doc_has_file = function (string $doc_key, string $file_column) use ($uploaded_files, $vendor_db_data, $doc_will_be_cleared): bool {
        if (!empty($uploaded_files[$doc_key])) return true;
        if ($doc_will_be_cleared($doc_key)) return false;
        return !empty($vendor_db_data[$file_column]);
    };
    $keep_ocr_with_doc = function (string $doc_key, string $file_column, string $number, string $status) use ($doc_has_file): array {
        return $doc_has_file($doc_key, $file_column) ? [$number, $status] : ['', 'empty'];
    };

    list($director_id_number, $director_id_status) = $keep_ocr_with_doc('director_id_card_file', 'director_id_card_file', $director_id_number, $director_id_status);
    list($bank_acc_number, $bank_acc_status)       = $keep_ocr_with_doc('bank_cover', 'bank_cover_path', $bank_acc_number, $bank_acc_status);
    list($npwp_number, $npwp_status)               = $keep_ocr_with_doc('npwp_file', 'npwp_path', $npwp_number, $npwp_status);
    list($nib_number, $nib_status)                 = $keep_ocr_with_doc('nib_file', 'nib_path', $nib_number, $nib_status);
    list($deed_est_number, $deed_est_status)       = $keep_ocr_with_doc('deed_of_establishment_file', 'deed_of_establishment_file', $deed_est_number, $deed_est_status);
    list($decree_number, $decree_status)           = $keep_ocr_with_doc('decree_of_legal_entity_file', 'decree_of_legal_entity_file', $decree_number, $decree_status);
    list($umkm_cert_number, $umkm_cert_status)     = $keep_ocr_with_doc('umkm_cert_file', 'umkm_cert_file', $umkm_cert_number, $umkm_cert_status);

    // Build BIG Update Query (Ditambah core_business, bank_name, bank_branch, bank_holder_name, company_address, number_of_employees)
    $sql = "UPDATE vendors SET
            legal_entity_type = ?, number_of_employees = ?, director_name = ?, director_phone = ?, establishment_date = ?, phone = ?, contact_person = ?,
            pic_finance = ?, email_pic_finance = ?, bank_name = ?, bank_branch = ?, bank_holder_name = ?,
            nib_number = ?, nib_status = ?, npwp_number = ?, npwp_status = ?, npwp_name = ?, company_address = ?,
            director_id_number = ?, director_id_status = ?,
            deed_of_establishment_number = ?, deed_of_establishment_status = ?,
            decree_of_legal_entity_number = ?, decree_of_legal_entity_status = ?,
            bank_account_number = ?, bank_account_status = ?, core_business = ?, business_description = ?,
            umkm_cert_number = ?, umkm_cert_status = ?,
            wht_tax_type = ?, wht_tax_code = ?,
            is_sppkp = ?, is_umkm = ?, data_accuracy_confirmed = ?,
            last_updated_by_user_id = ?, last_updated_by_name = ?, last_updated_at = ?";

    // Apply NPWP name as company name if vendor chose this option
    if (!empty($new_company_name)) {
        $sql .= ", company_name = '$new_company_name'";
    }

    if ($profile_path) $sql .= ", company_profile_file = '$profile_path'";
    if ($bank_path)    $sql .= ", bank_cover_path = '$bank_path'";
    if ($npwp_path)    $sql .= ", npwp_path = '$npwp_path'";
    if ($sppkp_path)   $sql .= ", sppkp_path = '$sppkp_path'";
    if ($umkm_cert_path) $sql .= ", umkm_cert_file = '$umkm_cert_path'";
    if ($nib_path)     $sql .= ", nib_path = '$nib_path'"; 
    if ($deed_est_path)      $sql .= ", deed_of_establishment_file = '$deed_est_path'";
    if ($deed_amd_path)      $sql .= ", deed_of_amendment_file = '$deed_amd_path'";
    if ($decree_path)        $sql .= ", decree_of_legal_entity_file = '$decree_path'";
    if ($director_id_path)   $sql .= ", director_id_card_file = '$director_id_path'";
    if ($business_cert_path) $sql .= ", business_entity_cert_file = '$business_cert_path'";
    if ($ethics_doc_path)    $sql .= ", business_ethics_commitment_file = '$ethics_doc_path'";
    if ($tax_statement_letter_path) $sql .= ", tax_statement_letter_file = '$tax_statement_letter_path'";
    if ($bank_holder_statement_path) $sql .= ", bank_holder_statement_letter_file = '$bank_holder_statement_path'";

    $files_to_remove = [];
    foreach ($requested_deletes as $file_type) {
        $replacement_attempted = !empty($_FILES[$file_type]['name']);
        if (!empty($uploaded_files[$file_type]) || !$replacement_attempted) {
            $db_column = $deletable_files[$file_type];
            $files_to_remove[] = $db_column;
            if (empty($uploaded_files[$file_type])) {
                $sql .= ", $db_column = NULL";
            }
        }
    }

    $sql .= " WHERE id = ?";

    // Bind 39 Parameters: number_of_employees(i) + 30 Strings, is_sppkp(i),
    // is_umkm(i), data_accuracy_confirmed(i), updated_by_id(i),
    // updated_by_name(s), updated_at(s), vendor_id(i).
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sissssssssssssssssssssssssssssssiiiissi",
        $legal_entity_type, $number_of_employees, $director_name, $director_phone, $est_date, $phone, $contact_person,
        $pic_finance, $email_pic_finance, $bank_name, $bank_branch, $bank_holder_name,
        $nib_number, $nib_status, $npwp_number, $npwp_status, $npwp_name, $company_address,
        $director_id_number, $director_id_status,
        $deed_est_number, $deed_est_status,
        $decree_number, $decree_status,
        $bank_acc_number, $bank_acc_status,
        $core_business, $business_description,
        $umkm_cert_number, $umkm_cert_status,
        $wht_tax_type, $wht_tax_code,
        $is_sppkp, $is_umkm, $data_accuracy_confirmed,
        $updated_by_id, $updated_by_name, $updated_at,
        $vendor_id
    );
    
    if ($stmt->execute()) {
        // The recovery draft has served its purpose. The document receipts are
        // deliberately NOT dropped here: when a field below is held back the
        // save is only partly accepted, "Buang draf" is still offered on the
        // page this lands on, and that button needs the receipts to put the
        // early-committed documents back. They go once the save comes out clean.
        vendor_profile_draft_clear();

        foreach ($files_to_remove as $db_column) {
            $stored_path = $vendor_upload_resolve($vendor_db_data[$db_column] ?? '');
            if ($stored_path) unlink($stored_path);
        }

        // Per-document "last updated by / at" (Dokumen Legal rows). Uploads are
        // already logged where they are committed, up top; what is left here is
        // the clear half — $requested_deletes minus anything simultaneously
        // replaced, since a delete only ever runs on a successful save.
        foreach ($requested_deletes as $doc_key) {
            if (empty($uploaded_files[$doc_key])) {
                vendor_doc_audit_log($conn, $vendor_id, $doc_key, 'deleted', $profile_actor);
            }
        }

        // Seven of these rows also carry an OCR-extracted number next to the
        // file (KTP, NPWP, Buku Rekening, NIB, Akta Pendirian, SK Pengesahan
        // Badan Hukum, Sertifikasi UMKM) — the number can be corrected by hand
        // without touching the file, and that is still a real change to the
        // row. Skip a doc_key whose file also changed this save; that already
        // logged 'uploaded' above.
        $doc_number_fields = [
            'director_id_card_file'       => ['director_id_number', $director_id_number],
            'npwp_file'                   => ['npwp_number', $npwp_number],
            'bank_cover'                  => ['bank_account_number', $bank_acc_number],
            'nib_file'                    => ['nib_number', $nib_number],
            'deed_of_establishment_file'  => ['deed_of_establishment_number', $deed_est_number],
            'decree_of_legal_entity_file' => ['decree_of_legal_entity_number', $decree_number],
            'umkm_cert_file'              => ['umkm_cert_number', $umkm_cert_number],
        ];
        foreach ($doc_number_fields as $doc_key => [$db_column, $new_value]) {
            if (!empty($uploaded_files[$doc_key])) {
                continue;
            }
            // Nomor yang dikosongkan karena dokumennya tidak ada bukan
            // penyuntingan oleh vendor, dan penghapusannya sudah dicatat di atas.
            if ((string) $new_value === '' && !$doc_has_file($doc_key, $deletable_files[$doc_key])) {
                continue;
            }
            $old_value = (string) ($vendor_db_data[$db_column] ?? '');
            if ((string) $new_value !== $old_value) {
                vendor_doc_audit_log($conn, $vendor_id, $doc_key, 'uploaded', $profile_actor);
            }
        }

        // Auto-promote a pre-PQ vendor once the profile is fully complete:
        // registration_submitted / registered / migrated -> invited_pq (ready for Pre-Qualification).
        // A held-back field means the save was only partly accepted, so the
        // profile is knowingly still wrong — promoting there is what let a
        // vendor into Pra-Kualifikasi with a refused NPWP number behind them.
        // "Simpan Draft" tidak pernah mempromosikan: itu hak Submit.
        $auto_promote_statuses = ['registration_submitted', 'registered', 'migrated'];
        if (!$is_draft && empty($held_fields) && empty($held_notes) && in_array($current_status, $auto_promote_statuses, true)) {
            $fresh = $conn->prepare("SELECT * FROM vendors WHERE id = ?");
            $fresh->bind_param("i", $vendor_id);
            $fresh->execute();
            $fresh_vendor = $fresh->get_result()->fetch_assoc();

            if ($fresh_vendor && isVendorProfileComplete($fresh_vendor)['complete']) {
                $promote = $conn->prepare("UPDATE vendors SET registration_status = 'invited_pq' WHERE id = ? AND registration_status IN ('registration_submitted', 'registered', 'migrated')");
                $promote->bind_param("i", $vendor_id);
                $promote->execute();
                $_SESSION['status'] = 'invited_pq';
            }
        }

        // Update session if company name was changed via NPWP override
        $saved_label = $is_draft ? "Draf tersimpan." : "Profil berhasil diperbarui.";
        if (!empty($new_company_name)) {
            $_SESSION['company_name'] = $apply_npwp_name;
            $msg = $saved_label . " Nama Perusahaan telah diubah sesuai NPWP menjadi: " . $apply_npwp_name;
        } else {
            $msg = $saved_label;
        }
        // Something was held back. Keep the vendor on the page — a "save and
        // leave" must not carry them off before they have seen what did not go
        // through — and put their rejected input back in the draft, so the
        // field still shows what they typed instead of quietly reverting to the
        // stored value on screen.
        if (!empty($held_fields) || !empty($held_notes)) {
            vendor_profile_draft_save($vendor_id, $_POST);

            $problems = $held_notes;
            if (!empty($held_fields)) {
                array_unshift($problems, "Belum tersimpan: " . implode('; ', $held_fields)
                    . ". Nilai sebelumnya dipertahankan — perbaiki lalu simpan lagi.");
            }

            $partial_msg = $is_draft ? "Draf tersimpan." : "Perubahan yang lolos pemeriksaan sudah tersimpan.";
            if (!empty($new_company_name)) {
                $partial_msg .= " Nama Perusahaan telah diubah sesuai NPWP menjadi: " . $apply_npwp_name;
            }
            header("Location: profile.php?msg=" . urlencode($partial_msg)
                . "&error=" . urlencode(implode(' ', $problems)));
            exit();
        }

        // Nothing was held back: the save is clean, so the documents committed
        // up front are no longer provisional and there is nothing to undo.
        vendor_profile_doc_rollback_clear();

        $redirect_after_save = trim($_POST['redirect_after_save'] ?? '');
        $redirect_parts = $redirect_after_save !== '' ? parse_url($redirect_after_save) : false;
        $is_safe_redirect = $redirect_parts !== false
            && empty($redirect_parts['scheme'])
            && empty($redirect_parts['host'])
            && strpos($redirect_after_save, '//') !== 0;
        header("Location: " . ($is_safe_redirect ? $redirect_after_save : "profile.php?msg=" . urlencode($msg)));
        exit();
    } else {
        $reject_and_keep_draft("Database Error: " . $conn->error);
    }
} else {
    header("Location: profile.php");
    exit();
}
?>
