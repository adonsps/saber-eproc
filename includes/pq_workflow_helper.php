<?php
// includes/pq_workflow_helper.php
require_once __DIR__ . '/sla_helper.php';
require_once __DIR__ . '/pq_risk_helper.php';
// Money questions score from the bands configured on the question in Manage PQ.
// calculateFinancialScore() below stays as the fallback for labels that cannot
// be read as bands -- see includes/pq_financial_scoring.php.
require_once __DIR__ . '/pq_financial_scoring.php';

/**
 * Scoring engine for financial questions (Pendapatan/Modal per tahun).
 * Extracted from modules/sourcing/update_assessment.php — the only place
 * that previously computed these thresholds — so it can also run for
 * Direct Registration submissions (see finalizeSubmissionScoring() below).
 */
function calculateFinancialScore($amount, $financial_type, $sub_variant) {
    // Rupiah amounts in this app never carry decimals — dots are always
    // thousand-separators (e.g. "6.000.000.000" = Rp 6B), never a decimal
    // point. Stripping only non-digits (not just non-digit-non-dot) avoids
    // misreading a dotted value as a tiny float if it ever reaches here
    // un-stripped (the client-side JS normally strips dots before submit,
    // but this function shouldn't depend on that alone).
    $amount = (float) preg_replace('/[^0-9]/', '', $amount);
    $B = 1000000000;
    $M = 1000000;
    $sub_variant = strtolower(trim($sub_variant));

    if ($financial_type === 'income') {
        if (strpos($sub_variant, 'project') !== false || strpos($sub_variant, 'foreman') !== false || strpos($sub_variant, 'perseorangan') !== false) {
            if ($amount > 100 * $B) return 5;
            if ($amount >= 50 * $B) return 3;
            if ($amount >= 10 * $B) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'retail & maintenance') !== false) {
            if ($amount > 5 * $B) return 5;
            if ($amount >= 1 * $B) return 3;
            if ($amount >= 500 * $M) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'principal') !== false || strpos($sub_variant, 'distributor') !== false || strpos($sub_variant, 'retail') !== false || strpos($sub_variant, 'service') !== false) {
            if ($amount > 50 * $B) return 5;
            if ($amount >= 15 * $B) return 3;
            if ($amount >= 2 * $B) return 1;
            return 0;
        }
    }

    if ($financial_type === 'capital') {
        if (strpos($sub_variant, 'project') !== false || strpos($sub_variant, 'foreman') !== false || strpos($sub_variant, 'perseorangan') !== false) {
            if ($amount > 20 * $B) return 5;
            if ($amount >= 5 * $B) return 3;
            if ($amount >= 1 * $B) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'retail & maintenance') !== false) {
            if ($amount > 5 * $B) return 5;
            if ($amount >= 1 * $B) return 3;
            if ($amount >= 500 * $M) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'principal') !== false || strpos($sub_variant, 'distributor') !== false || strpos($sub_variant, 'service') !== false) {
            if ($amount > 50 * $B) return 5;
            if ($amount >= 15 * $B) return 3;
            if ($amount >= 2 * $B) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'retail') !== false) {
            if ($amount > 2 * $B) return 5;
            if ($amount >= 500 * $M) return 3;
            if ($amount >= 100 * $M) return 1;
            return 0;
        }
    }
    return 0;
}

/**
 * Compute the final weighted score / risk result for a submission, exactly
 * like Sourcing's review pass in update_assessment.php does — correct
 * sub_variant_target-specific weight lookup (not the always-empty lookup
 * submit_pq.php's own initial calc uses) plus real financial-question
 * scoring via calculateFinancialScore(). Writes selected_score for
 * financial rows and the final totals/risk onto pq_submissions.
 *
 * Direct Registration has no separate Sourcing review pass, so this must
 * run once, right before advanceSubmissionToVm(), so VM never sees the
 * weak placeholder numbers submit_pq.php computes at initial submit.
 */
function finalizeSubmissionScoring(mysqli $conn, int $submissionId, int $vendorId, string $companyType, string $subCompanyType): void {
    $total_weighted_score = 0.0;
    $max_possible_score = 0.0;

    // pq_weight rows (and calculateFinancialScore()'s tiers) are still keyed
    // by the older 'Individual / Foreman' / 'Contractor' labels — 'Perseorangan'
    // is a newer top-level company_type/sub_company_type value that reuses
    // those same rows/questions. Same translation update_assessment.php does;
    // vendors.company_type / sub_company_type themselves keep the real value.
    [$main_type_for_match, $pq_weight_sub_type] = pqScoringVariants($companyType, $subCompanyType);

    $q_sql = "SELECT
                q.id as qid,
                q.variant_target,
                q.question_text,
                (SELECT weight FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1) as weight,
                COALESCE((SELECT help_text FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1), q.help_text) as help_text,
                (SELECT GROUP_CONCAT(sub_variant_target SEPARATOR ',') FROM pq_weight WHERE question_id = q.id) as all_sub_targets
              FROM pq_questions q";
    $q_stmt = $conn->prepare($q_sql);
    $q_stmt->bind_param("ss", $pq_weight_sub_type, $pq_weight_sub_type);
    $q_stmt->execute();
    $q_res = $q_stmt->get_result();

    while ($q = $q_res->fetch_assoc()) {
        $qid = $q['qid'];
        $weight = floatval($q['weight'] ?? 0);
        $v_target = $q['variant_target'];
        $sv_targets = $q['all_sub_targets'];

        if (!pqQuestionApplies($v_target, $sv_targets, $main_type_for_match, $pq_weight_sub_type)) {
            continue;
        }

        $ans_res = $conn->query("SELECT selected_score, remarks FROM pq_answers WHERE submission_id = $submissionId AND question_id = $qid");
        $ans = $ans_res ? $ans_res->fetch_assoc() : null;
        if (!$ans) {
            continue;
        }

        $score = $ans['selected_score'] !== null ? intval($ans['selected_score']) : null;
        $remark = $ans['remarks'] ?? '';

        // Financial questions never get a selected_score at initial submit
        // (pq_form.php only collects a Rupiah amount for these) — compute
        // it now, the same way update_assessment.php does: from the bands
        // configured on the question, falling back to the hardcoded ladder only
        // when those labels cannot be read. Direct Registration has no Sourcing
        // review pass, so if this path used a different rule from the review
        // path, the same vendor would score differently depending on which door
        // they came in through.
        if ($remark !== '') {
            $fin_type = pqFinancialType($q['question_text']);
            if ($fin_type !== null) {
                $from_options = pqFinancialScoreFromOptions($remark, $q['help_text']);
                $score = $from_options !== null
                    ? $from_options
                    : calculateFinancialScore($remark, $fin_type, $pq_weight_sub_type);
                $conn->query("UPDATE pq_answers SET selected_score = $score WHERE submission_id = $submissionId AND question_id = $qid");
            }
        }

        if ($weight > 0 && $score !== null) {
            $total_weighted_score += ($score / 5) * $weight;
            $max_possible_score += $weight;
        }
    }

    $risk_percentage = pqRiskPercentage($total_weighted_score, $max_possible_score);
    $risk_category = pqRiskCategory($risk_percentage);

    $stmt_upd = $conn->prepare("UPDATE pq_submissions SET total_weighted_score=?, max_possible_weighted_score=?, risk_percentage=?, risk_category=? WHERE id=?");
    $stmt_upd->bind_param("didsi", $total_weighted_score, $max_possible_score, $risk_percentage, $risk_category, $submissionId);
    $stmt_upd->execute();
}

/**
 * Advance a PQ submission from Sourcing to VM Team review.
 * Shared by modules/sourcing/review_action.php (approve_sourcing) and
 * modules/vendor/submit_pq.php (Direct Registration impersonation final submit).
 *
 * Requires vendors.sub_company_type to already be set — throws otherwise,
 * matching the pre-existing gate in review_action.php.
 */
function advanceSubmissionToVm(mysqli $conn, int $submissionId, int $vendorId, int $sourcingUserId, ?string $sourcingNotes = null): void {
    $v_check = $conn->query("SELECT sub_company_type, npwp_number FROM vendors WHERE id = " . $vendorId)->fetch_assoc();
    if (empty($v_check['sub_company_type'])) {
        throw new Exception('Vendor Sub-Category Classification must be set before submitting to VM.');
    }

    // Same NPWP 16-digit gate review_action.php enforces for the normal
    // Sourcing-review path — Direct Registration must not be able to shortcut
    // around it just because it skips that page.
    $npwp_digits = preg_replace('/\D+/', '', (string) ($v_check['npwp_number'] ?? ''));
    if (strlen($npwp_digits) !== 16) {
        throw new Exception('NPWP vendor masih ' . strlen($npwp_digits) . ' digit (format lama). NPWP 16 digit wajib diisi sebelum dapat dikirim ke VM.');
    }

    $stmt = $conn->prepare("UPDATE pq_submissions SET status = 'under_review_vm', sourcing_notes = ?, sourcing_reviewer_id = ? WHERE id = ?");
    $stmt->bind_param("sii", $sourcingNotes, $sourcingUserId, $submissionId);

    if (!$stmt->execute()) {
        throw new Exception("pq_submissions update failed: " . $stmt->error);
    }

    if (!$conn->query("UPDATE vendors SET registration_status = 'under_review_vm' WHERE id = " . $vendorId)) {
        throw new Exception("vendors update failed: " . $conn->error);
    }

    openPqSlaLeg($conn, $submissionId, $sourcingUserId);
}

/**
 * Notify a vendor by email after Sourcing finishes a Direct Registration
 * submission on their behalf — sent only now (post-submit), not at account
 * creation, since the vendor didn't initiate this and needs the context of
 * what happened plus a way to actually log in (they never set a password).
 *
 * Reuses the same users.reset_token / reset_expires columns and
 * reset_password.php flow as forgot_password.php's "forgot password" email,
 * just with different framing copy. Best-effort: failures are swallowed so
 * a broken mail server never blocks the Sourcing user's own flow.
 */
function notifyVendorOfDirectRegistration(mysqli $conn, int $vendorId): void {
    $stmt = $conn->prepare("SELECT u.id, u.email, u.full_name, v.company_name
                             FROM vendors v JOIN users u ON u.id = v.user_id
                             WHERE v.id = ?");
    $stmt->bind_param("i", $vendorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        return;
    }

    $token = bin2hex(random_bytes(32));
    $expiry = date("Y-m-d H:i:s", strtotime('+24 hours'));

    $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
    $upd->bind_param("ssi", $token, $expiry, $row['id']);
    $upd->execute();

    require_once __DIR__ . '/smtp_config.php';
    require_once __DIR__ . '/mail_rate_limiter.php';
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        configureSmtp($mail);
        $mail->Timeout = 3;
        $mail->SMTPKeepAlive = true;

        $mail->setFrom(mail_smtp_config()['from_email'], 'VM Corporate Procurement');
        $mail->addAddress($row['email'], $row['full_name']);
        $mail->isHTML(true);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'] ?? '';
        // submit_pq.php runs from modules/vendor/ — link must point at the app root's reset_password.php.
        $reset_link = $protocol . $domainName . '/reset_password.php?token=' . $token;

        $mail->Subject = 'Pendaftaran Perusahaan Anda Telah Diajukan - Vendor Sourcing System';
        $mail->Body = 'Halo ' . htmlspecialchars($row['full_name']) . ',<br><br>'
            . 'Tim Corporate Procurement Kawan Lama Group telah mendaftarkan perusahaan Anda, <strong>' . htmlspecialchars($row['company_name']) . '</strong>, '
            . 'ke dalam Vendor Sourcing System dan mengajukan Formulir Pra-Kualifikasi atas nama Anda berdasarkan data yang telah dikoordinasikan dengan tim kami. '
            . 'Pengajuan ini kini berada dalam tahap tinjauan oleh Tim Vendor Management.<br><br>'
            . 'Untuk membuat kata sandi dan mengakses akun Anda sendiri guna memantau status pengajuan, silakan klik tautan di bawah ini (berlaku selama 24 jam):<br><br>'
            . '<a href="' . $reset_link . '" style="display:inline-block;padding:10px 20px;color:#fff;background-color:#0d6efd;text-decoration:none;border-radius:5px;">Buat Kata Sandi &amp; Masuk</a><br><br>'
            . 'Jika Anda merasa ini adalah kekeliruan atau memiliki pertanyaan, silakan hubungi tim Corporate Procurement kami.<br><br>Terima kasih.';

        mail_rate_limited_send($mail, 'direct-registration-notice');
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // Best-effort only — a mail failure must never block the Sourcing user's flow.
    }
}
