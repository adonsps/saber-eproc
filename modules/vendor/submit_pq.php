<?php
// modules/vendor/submit_pq.php
session_start();
require '../../config.php';
require_once '../../includes/exception_request_helper.php';
require_once '../../includes/pq_risk_helper.php';
require_once '../../includes/upload_paths.php';
require_once '../../includes/pq_calc_details.php';
require_once '../../includes/vendor_context.php';
// require '../../libs/tcpdf/tcpdf.php'; // Digunakan jika ingin generate PDF saat submit

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] != 'vendor') {
    die("Akses Ditolak");
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php — submitting a PQ is out of scope for it.
vendor_staff_edit_restrict(fn() => die("Akses Ditolak"));

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $user_id = $_SESSION['user_id'];
    
    // Fetch Vendor Data
    $vendor_id = (int)($_SESSION['vendor_id'] ?? 0); // multi-CP: resolve by company, not by CP user
    $stmt = $conn->prepare("SELECT id, company_name, company_type, is_umkm, registration_status FROM vendors WHERE id = ?");
    $stmt->bind_param("i", $vendor_id);
    $stmt->execute();
    $v_data = $stmt->get_result()->fetch_assoc();

    if (!$v_data) die("Error: Profil vendor tidak ditemukan.");

    $vendor_id = $v_data['id'];
    $company_name = $v_data['company_name'];
    $current_vendor_status = $v_data['registration_status'];
    // Which question/weight set did this vendor actually answer? Perseorangan
    // company_type and self-declared UMKM both answer the standalone set.
    $uses_perseorangan_pq = vendor_uses_perseorangan_pq($v_data['company_type'], $v_data['is_umkm'] ?? 0);

    $total_weighted_score = 0;
    $max_possible_score = 0;
    
    // --- DETEKSI APAKAH INI HANYA SIMPAN SEMENTARA (DRAFT) ---
    $is_draft = isset($_POST['save_draft_pq']);

    // --- GUARD: TOLAK POST YANG DATANG SETELAH PENGAJUAN LEPAS DARI VENDOR ---
    // This endpoint is reachable directly and, unlike pq_form.php, had no status
    // gate at all — only the "are you a vendor" check above. So a form page the
    // vendor still had open (back button, second tab, double submit) could POST
    // here long after the PQ was finally submitted, and everything below would
    // run: DELETE FROM pq_answers + re-insert whatever that stale page held, and
    // on a "Simpan" also push the submission back to 'draft' while leaving
    // vendors.registration_status at 'pq_submitted'. That desync is invisible on
    // the vendor list (it reads registration_status) but locks the assessment in
    // review_pq.php, which only unlocks for
    // pq_submitted / under_review_sourcing / returned_to_sourcing.
    //
    // A vendor may only write a submission that is still in their own hands.
    $vendor_editable_sub_statuses = ['draft', 'revision_needed'];
    if (!empty($_SESSION['impersonator'])) {
        // Direct Registration: when VM returns a submission it goes back to
        // Sourcing, who edits it through this same form while impersonating the
        // vendor (see the matching exception in pq_form.php).
        $vendor_editable_sub_statuses[] = 'returned_to_sourcing';
    }

    $stmt_guard = $conn->prepare("SELECT status FROM pq_submissions WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
    $stmt_guard->bind_param("i", $vendor_id);
    $stmt_guard->execute();
    $guard_row = $stmt_guard->get_result()->fetch_assoc();
    $stmt_guard->close();

    if ($guard_row && !in_array($guard_row['status'], $vendor_editable_sub_statuses, true)) {
        header("Location: dashboard.php?error=" . urlencode(
            "Formulir Pra-Kualifikasi Anda sudah dikirim dan sedang ditinjau, sehingga tidak dapat diubah lagi dari halaman ini. "
            . "Silakan hubungi tim Sourcing bila ada data yang perlu diperbaiki."
        ));
        exit();
    }

    // Folder setup for potential file uploads.
    // Resolved through includes/upload_paths.php: reuse the vendor's existing
    // folder wherever it lives, otherwise create one under a base that is
    // actually writable. The old "<root>/../uploads" spelling resolved to
    // /var/www in the container — root-owned — so every PQ attachment was
    // silently dropped while the form still reported success.
    $upload_dir_abs  = null;
    $upload_failures = [];
    $vendor_dir      = vendor_upload_dir_for((int)$vendor_id, $company_name);
    if ($vendor_dir === null) {
        error_log("submit_pq.php: no writable uploads base; vendor_id={$vendor_id} — attachments cannot be stored");
    } else {
        $upload_dir_abs = $vendor_dir . "/";
    }

    $conn->begin_transaction();
    try {
        // 2. Manage Submission Record
        $stmt_check = $conn->prepare("SELECT id, status FROM pq_submissions WHERE vendor_id = ? ORDER BY id DESC LIMIT 1");
        $stmt_check->bind_param("i", $vendor_id);
        $stmt_check->execute();
        $res = $stmt_check->get_result();
        
        if ($res->num_rows > 0) {
            $row = $res->fetch_assoc();
            $submission_id = $row['id'];
            
            if (!$is_draft) {
                // Jika Final Submit, naikkan status menjadi dalam tinjauan
                $conn->query("UPDATE pq_submissions SET status = 'under_review_sourcing', submitted_at = NOW() WHERE id = $submission_id");
            } else {
                // Jika menyimpan Draft saat status sedang Revisi, biarkan statusnya tetap 'revision_needed'
                if ($row['status'] !== 'revision_needed') {
                    $conn->query("UPDATE pq_submissions SET status = 'draft' WHERE id = $submission_id");
                }
            }
            // Hapus jawaban lama agar tidak duplikat saat diinsert ulang
            $conn->query("DELETE FROM pq_answers WHERE submission_id = $submission_id");
        } else {
            // Pembuatan rekam jejak pengajuan pertama kali
            $sub_status = $is_draft ? 'draft' : 'under_review_sourcing';
            $stmt_sub = $conn->prepare("INSERT INTO pq_submissions (vendor_id, status, submitted_at) VALUES (?, ?, NOW())");
            $stmt_sub->bind_param("is", $vendor_id, $sub_status);
            $stmt_sub->execute();
            $submission_id = $conn->insert_id;

            // If this vendor had an Exception Request, its submitter automatically
            // becomes the responsible Sourcing reviewer on this new PQ submission.
            $conn->query("UPDATE pq_submissions ps JOIN vendors v ON v.id = ps.vendor_id
                          " . EXCEPTION_REQUEST_LATEST_JOIN . "
                          SET ps.sourcing_reviewer_id = er.submitted_by
                          WHERE ps.id = $submission_id AND er.submitted_by IS NOT NULL AND ps.sourcing_reviewer_id IS NULL");
        }

        // We will build a unified array of answers first to prevent duplicate inserts
        $final_answers = [];

        // 3. Collect Standard Answers (Radio Buttons)
        if (isset($_POST['answers'])) {
            foreach ($_POST['answers'] as $q_id => $score) {
                $final_answers[$q_id]['score'] = $score;
            }
        }
        
        // 4. Collect Standard Remarks (Financial / Single Text Box)
        if (isset($_POST['remarks'])) {
            foreach ($_POST['remarks'] as $q_id => $text) {
                if (trim($text) !== '') {
                    $final_answers[$q_id]['remarks'] = trim($text);
                }
            }
        }

        // 5. Collect Multiple Dynamic Text Boxes (remarks_multi)
        if (isset($_POST['remarks_multi'])) {
            foreach ($_POST['remarks_multi'] as $q_id => $text_array) {
                $clean_rows = array_filter(array_map('trim', $text_array), function($v) { 
                    return $v !== ''; 
                });
                
                if (!empty($clean_rows)) {
                    $joined_text = implode("\n", $clean_rows);
                    $final_answers[$q_id]['remarks'] = $joined_text;
                }
            }
        }

        // 5b. Collect Calculator Working Figures (Quick Ratio, Debt to Equity)
        // The amounts the vendor typed into each calculator. Kept beside the
        // answer as evidence for the reviewers -- the score itself still comes
        // from the band the calculator picked, untouched here.
        $calc_encoders = [
            'calc_qr'  => 'pqCalcEncodeQuickRatio',
            'calc_der' => 'pqCalcEncodeDebtToEquity',
        ];
        foreach ($calc_encoders as $post_key => $encoder) {
            if (!isset($_POST[$post_key]) || !is_array($_POST[$post_key])) continue;
            foreach ($_POST[$post_key] as $q_id => $figures) {
                if (!is_array($figures)) continue;
                $json = $encoder($figures);
                if ($json !== null) {
                    $final_answers[$q_id]['calc_details'] = $json;
                }
            }
        }

        // 6. Handle File Attachments
        if (isset($_FILES) && !empty($_FILES)) {
            foreach ($_FILES as $key => $file_array) {
                if (strpos($key, 'attachment_') === 0) {
                    $q_id = str_replace('attachment_', '', $key);
                    
                    $uploaded_paths = [];
                    
                    // Re-add existing attachments that weren't deleted
                    if (isset($_POST['existing_attachments'][$q_id])) {
                        foreach ($_POST['existing_attachments'][$q_id] as $existing) {
                            $uploaded_paths[] = $existing;
                        }
                    }

                    // Process new uploads
                    if (is_array($file_array['name'])) {
                        for ($i = 0; $i < count($file_array['name']); $i++) {
                            if ($file_array['error'][$i] == 0) {
                                // Skip files larger than 5MB
                                if ($file_array['size'][$i] > 5 * 1024 * 1024) {
                                    $upload_failures[] = $file_array['name'][$i] . ' (melebihi 5MB)';
                                    continue;
                                }

                                $ext = pathinfo($file_array['name'][$i], PATHINFO_EXTENSION);
                                $new_name = "PQ_DOC_" . $vendor_id . "_" . $q_id . "_" . time() . "_" . $i . "." . $ext;

                                // A failed move must never pass silently — that is
                                // exactly how attachments went missing in prod.
                                if ($upload_dir_abs !== null
                                    && @move_uploaded_file($file_array['tmp_name'][$i], $upload_dir_abs . $new_name)) {
                                    $uploaded_paths[] = vendor_upload_relative_path($upload_dir_abs, $new_name);
                                } else {
                                    $upload_failures[] = $file_array['name'][$i];
                                    error_log("submit_pq.php: attachment not stored — vendor_id={$vendor_id}, q_id={$q_id}, file=" . $file_array['name'][$i]);
                                }
                            }
                        }
                    }
                    
                    if (!empty($uploaded_paths)) {
                        $final_answers[$q_id]['attachment'] = implode(',', $uploaded_paths);
                    }
                }
            }
        }

        // 7. Insert Unified Data into pq_answers and Calculate Score
        $stmt_ans = $conn->prepare("INSERT INTO pq_answers (submission_id, question_id, selected_score, remarks, calc_details, attachment_path) VALUES (?, ?, ?, ?, ?, ?)");
        
        $question_map = [];
        $q_res = $conn->query("SELECT id, LOWER(TRIM(question_text)) as normalized_text FROM pq_questions");
        while ($row = $q_res->fetch_assoc()) { 
            $question_map[$row['id']] = $row; 
        }

        // 7a. Quick Ratio and Debt to Equity must come from the calculator
        // The band alone is not an acceptable answer: the figures behind it are
        // what Sourcing/VM check the percentage against, so a final submit
        // without them is refused outright. Drafts are exempt -- a vendor is
        // allowed to save halfway and come back to the calculator later.
        //
        // Two ways such an answer can fall short, both caught here:
        //   - a score arrived with no figures (an answer picked by hand), and
        //   - the question was on the form but came back empty, which the
        //     browser cannot flag because the bands are rendered disabled until
        //     the calculator runs. calc_<kind>_required[] carries those ids.
        if (!$is_draft) {
            $calc_rules = [
                [
                    'required_key' => 'calc_qr_required',
                    'matcher'      => 'pqIsQuickRatioQuestion',
                    'message'      => 'Pertanyaan Quick Ratio harus diisi melalui Kalkulator Quick Ratio. '
                                    . 'Buka "Bantuan Hitung Quick Ratio", masukkan Total Aset Lancar, Total Persediaan '
                                    . 'dan Kewajiban Lancar, lalu tekan "Hitung Otomatis". '
                                    . 'Opsi jawaban akan terisi otomatis dari hasil perhitungan.',
                ],
                [
                    'required_key' => 'calc_der_required',
                    'matcher'      => 'pqIsDebtToEquityQuestion',
                    'message'      => 'Pertanyaan Debt to Equity harus diisi melalui Kalkulator Debt to Equity. '
                                    . 'Buka "Bantuan Hitung Debt to Equity", masukkan Total Utang dan Total Ekuitas, '
                                    . 'lalu tekan "Hitung Otomatis". '
                                    . 'Opsi jawaban akan terisi otomatis dari hasil perhitungan.',
                ],
            ];

            foreach ($calc_rules as $rule) {
                $must_have_calc = [];
                $declared = is_array($_POST[$rule['required_key']] ?? null) ? $_POST[$rule['required_key']] : [];
                foreach ($declared as $req_qid) {
                    $req_qid = (int) $req_qid;
                    if ($req_qid > 0) $must_have_calc[$req_qid] = true;
                }
                foreach ($final_answers as $q_id => $data) {
                    $q_text = $question_map[$q_id]['normalized_text'] ?? null;
                    if ($q_text !== null && $rule['matcher']($q_text)
                        && isset($data['score']) && $data['score'] !== '') {
                        $must_have_calc[(int) $q_id] = true;
                    }
                }

                foreach (array_keys($must_have_calc) as $calc_qid) {
                    $has_figures = !empty($final_answers[$calc_qid]['calc_details']);
                    $has_score   = isset($final_answers[$calc_qid]['score']) && $final_answers[$calc_qid]['score'] !== '';
                    if (!$has_figures || !$has_score) {
                        $conn->rollback();
                        header("Location: pq_form.php?error=" . urlencode($rule['message']));
                        exit();
                    }
                }
            }
        }

        $processed_texts = [];

        foreach ($final_answers as $q_id => $data) {
            $target_info = $question_map[$q_id] ?? null;
            
            if ($target_info) {
                $target_text = $target_info['normalized_text'];
                // Jika belum diisi (dalam mode draft), set score ke 0 agar database tidak error
                $score = isset($data['score']) && $data['score'] !== '' ? intval($data['score']) : 0;
                $remarks = $data['remarks'] ?? null;
                $calc_details = $data['calc_details'] ?? null;
                $attachment = $data['attachment'] ?? null;

                // Insert for all questions matching this text
                foreach ($question_map as $db_qid => $db_info) {
                    if ($db_info['normalized_text'] === $target_text) {
                        $stmt_ans->bind_param("iiisss", $submission_id, $db_qid, $score, $remarks, $calc_details, $attachment);
                        $stmt_ans->execute();
                    }
                }
                
                // Calculate weight only once per unique question text
                if (!in_array($target_text, $processed_texts)) {
                    $processed_texts[] = $target_text;
                    
                    // Pastikan yang dihitung hanya jika score sudah ada (tidak kosong)
                    if (isset($data['score']) && $data['score'] !== '') {
                        // Perseorangan has its own standalone question/weight set, so its
                        // questions are weighted from the 'Perseorangan' pq_weight row.
                        if ($uses_perseorangan_pq) {
                            $w_query = $conn->query("SELECT weight FROM pq_weight WHERE question_id = $q_id AND sub_variant_target = 'Perseorangan' LIMIT 1");
                        } else {
                            $w_query = $conn->query("SELECT weight FROM pq_weight WHERE question_id = $q_id AND (sub_variant_target IS NULL OR sub_variant_target = '') LIMIT 1");
                        }
                        
                        $weight = 0;
                        if ($w_query && $w_query->num_rows > 0) {
                            $weight = floatval($w_query->fetch_assoc()['weight']);
                        } 

                        $total_weighted_score += ($score / 5) * $weight; 
                        $max_possible_score += $weight; 
                    }
                }
            }
        }

        // 8. Calculate Risk and Update Header
        // 5-TIER RISK LOGIC (satu sumber di includes/pq_risk_helper.php):
        // persentase = tingkat risiko (makin tinggi makin buruk).
        $risk_percentage = pqRiskPercentage($total_weighted_score, $max_possible_score);
        $risk_category = pqRiskCategory($risk_percentage);

        $stmt_upd = $conn->prepare("UPDATE pq_submissions SET total_weighted_score=?, max_possible_weighted_score=?, risk_percentage=?, risk_category=? WHERE id=?");
        $stmt_upd->bind_param("didsi", $total_weighted_score, $max_possible_score, $risk_percentage, $risk_category, $submission_id);
        $stmt_upd->execute();

        // Direct Registration draft saves also run the real scoring engine
        // (not just the placeholder above), so the score shown when the page
        // reloads is always the true figure — same logic final submit uses,
        // just without advancing status.
        if ($is_draft && !empty($_SESSION['impersonator'])) {
            require_once '../../includes/pq_workflow_helper.php';
            $sub_type_row = $conn->query("SELECT sub_company_type FROM vendors WHERE id = $vendor_id")->fetch_assoc();
            if (!empty($sub_type_row['sub_company_type'])) {
                finalizeSubmissionScoring($conn, $submission_id, $vendor_id, $v_data['company_type'], $sub_type_row['sub_company_type']);
            }
        }

        // 9. Update Vendor Status & Dynamic Message
        $is_direct_registration = !empty($_SESSION['impersonator']);

        if (!$is_draft) {
            // HANYA kunci statusnya jika yang ditekan adalah tombol "Kirim Final"
            $conn->query("UPDATE vendors SET registration_status = 'pq_submitted' WHERE id = $vendor_id");
            $msg = "Formulir Pra-Kualifikasi berhasil dikirim dan masuk dalam tahap tinjauan Sourcing.";

            if ($is_direct_registration) {
                // Direct Registration: Sourcing entered this data themselves, so
                // the normal Sourcing-review step (and the real scoring pass
                // that step normally does — update_assessment.php) is skipped
                // entirely. Run that same scoring engine here — correct
                // sub_variant_target weight lookup + financial-question
                // scoring — so VM never sees the weak placeholder numbers from
                // step 8 above. Vendor Sub-Category was already declared
                // earlier (see the gate in pq_form.php / classify_vendor.php).
                require_once '../../includes/pq_workflow_helper.php';
                $sub_type_row = $conn->query("SELECT sub_company_type FROM vendors WHERE id = $vendor_id")->fetch_assoc();
                finalizeSubmissionScoring($conn, $submission_id, $vendor_id, $v_data['company_type'], $sub_type_row['sub_company_type'] ?? '');

                // advanceSubmissionToVm() still independently verifies
                // vendors.sub_company_type is non-empty before handing off,
                // as a safety net.
                advanceSubmissionToVm($conn, $submission_id, $vendor_id, (int)$_SESSION['impersonator']['user_id']);

                // Surface the actual computed result right here — the score
                // genuinely is final at this point (finalizeSubmissionScoring
                // already ran above), but this whole flow ends in a redirect
                // to Sourcing's own dashboard, not a page that shows PQ
                // detail. Without this, Sourcing has no way to see the real
                // number until they separately navigate to check it, which
                // reads as "did this actually work?".
                $final_result = $conn->query("SELECT risk_percentage, risk_category FROM pq_submissions WHERE id = $submission_id")->fetch_assoc();
                $msg = "Pendaftaran Langsung berhasil dikirim ke Tim VM. Hasil akhir: "
                    . number_format((float)($final_result['risk_percentage'] ?? 0), 1) . "% — "
                    . ($final_result['risk_category'] ?? '-');
            }
        } else {
            // Jika Draft, biarkan status vendor seperti sebelumnya
            $msg = "Draft isian Pra-Kualifikasi Anda berhasil disimpan sementara.";
        }

        $conn->commit();

        // Any attachment that did not reach disk is reported alongside the
        // success message, so the vendor knows to re-attach instead of assuming
        // the evidence was received.
        if (!empty($upload_failures)) {
            $msg .= " PERHATIAN: lampiran berikut GAGAL tersimpan dan perlu diunggah ulang: "
                  . implode(', ', array_unique($upload_failures)) . ".";
        }

        if (!$is_draft && $is_direct_registration) {
            // Notify the vendor only now that the submission is actually final and
            // committed — not at account creation, since the vendor didn't initiate
            // this and an earlier email would arrive with no context yet to explain
            // why. Reuses the same reset_token/reset_expires columns as
            // forgot_password.php so the vendor can set a password and log in to
            // track status themselves.
            notifyVendorOfDirectRegistration($conn, $vendor_id);

            // Auto-exit impersonation and return Sourcing to their own dashboard.
            header("Location: ../sourcing/exit_direct_registration.php?msg=" . urlencode($msg));
            exit();
        }

        if ($is_draft && $is_direct_registration) {
            // Land back on the PQ form itself (real page reload, no AJAX) —
            // pq_form.php already renders the just-saved score straight from
            // the database on load, so this is the simplest reliable way for
            // Sourcing to see the update after clicking Simpan.
            header("Location: pq_form.php?msg=" . urlencode($msg));
            exit();
        }

        // Mengembalikan user ke dashboard dengan pesan sukses
        header("Location: dashboard.php?msg=" . urlencode($msg));
        exit();
        
    } catch (Exception $e) { 
        $conn->rollback(); 
        die("Terjadi kesalahan sistem: " . $e->getMessage()); 
    }
} else {
    header("Location: dashboard.php");
    exit();
}
?>