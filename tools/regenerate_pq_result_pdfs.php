<?php
// tools/regenerate_pq_result_pdfs.php
//
// One-time backfill: rebuild the lost "PQ Result" certificate PDF for vendors
// that were ALREADY approved (pq_submissions.status = 'approved') but whose
// PQ_Result_*.pdf never reached the browser-reachable uploads volume because of
// the pre-fix upload-path bug (see commits 699066a / d4ed303 and the read/write
// path fix in approve_action.php + all_vendors.php). Once the file exists in the
// correct vendor folder, the red "PDF Result" badge on all_vendors.php reappears
// and the certificate is downloadable — exactly as if the approval had saved it
// the first time.
//
// SAFETY: this tool is READ-ONLY on the database. It performs NO re-approval and
// writes NOTHING to any table — status, risk, K2 gate, SLA deadline and the
// admin notification email already ran correctly at the original approval and
// are left untouched. The certificate content (risk, reviewer, notes) and its
// printed dates are taken from the stored approval record (approved_at), so the
// regenerated PDF matches what the original would have contained. The only side
// effect is writing the PDF file into the vendor's uploads folder.
//
// Targets the VSS / vmsportal database via the app's own config.php.
//
// Usage:
//   Dry run (default, lists candidates, writes nothing):
//     docker-compose exec app php tools/regenerate_pq_result_pdfs.php
//   Limit to one vendor:
//     docker-compose exec app php tools/regenerate_pq_result_pdfs.php --vendor=10
//   Apply (write the PDFs). --confirm-db must equal the connected database:
//     docker-compose exec app php tools/regenerate_pq_result_pdfs.php --apply --confirm-db=u894129685_vmsportal
//   Also regenerate for vendors that already have a PDF on disk:
//     ... --apply --confirm-db=... --force

if (php_sapi_name() !== 'cli') exit("CLI only.\n");

// ---------------------------------------------------------------------------
// Argument parsing
// ---------------------------------------------------------------------------
$apply      = in_array('--apply', $argv, true);
$force      = in_array('--force', $argv, true);
$confirmDb  = null;
$onlyVendor = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--confirm-db=')) $confirmDb  = substr($a, 13);
    if (str_starts_with($a, '--vendor='))     $onlyVendor = (int) substr($a, 9);
}

// ---------------------------------------------------------------------------
// Bootstrap the app (DB connection + PDF library + risk helper).
// Loading config.php runs the app's standard idempotent bootstrap migrations —
// the same ones that run on any normal page load — and nothing more.
// ---------------------------------------------------------------------------
require __DIR__ . '/../config.php';                 // provides $conn (mysqli, vmsportal DB)
require_once __DIR__ . '/../includes/pq_risk_helper.php';
require_once __DIR__ . '/../includes/pq_calc_details.php';
require_once __DIR__ . '/../libs/tcpdf/tcpdf.php';

// ---------------------------------------------------------------------------
// Identity gate + banner
// ---------------------------------------------------------------------------
$id = $conn->query("SELECT DATABASE() AS d, USER() AS u, @@hostname AS h, VERSION() AS v")->fetch_assoc();
fwrite(STDERR, "----------------------------------------------------------------\n");
fwrite(STDERR, "PQ Result PDF backfill\n");
fwrite(STDERR, "  DATABASE : {$id['d']}\n");
fwrite(STDERR, "  USER     : {$id['u']}\n");   // user@host — not a secret; password is never read/printed
fwrite(STDERR, "  HOSTNAME : {$id['h']}\n");
fwrite(STDERR, "  VERSION  : {$id['v']}\n");
fwrite(STDERR, "  MODE     : " . ($apply ? "APPLY (will write PDF files)" : "DRY RUN (no files written)") . "\n");
fwrite(STDERR, "----------------------------------------------------------------\n");

if (!$apply) {
    echo "[DRY RUN] No files will be written. Re-run with --apply --confirm-db={$id['d']} to generate.\n\n";
} else {
    if ($confirmDb === null || $confirmDb !== $id['d']) {
        fwrite(STDERR, "ABORT: --apply requires --confirm-db=<connected database>.\n");
        fwrite(STDERR, "       Expected --confirm-db={$id['d']}" . ($confirmDb === null ? " (none given).\n" : ", got '{$confirmDb}'.\n"));
        exit(2);
    }
    echo "[APPLY] Writing PDFs to the connected database's vendors ({$id['d']}).\n\n";
}

// ---------------------------------------------------------------------------
// Upload base resolution (mirrors approve_action.php / profile_action.php):
// read from both historical bases, write only to an existing writable one,
// preferring <root>/uploads (the persistent, browser-reachable prod volume).
// ---------------------------------------------------------------------------
$root_path = dirname(__DIR__);
$upload_bases = [];
foreach ([$root_path . '/uploads', $root_path . '/../uploads'] as $cand) {
    $real = realpath($cand);
    if ($real && is_dir($real)) $upload_bases[$real] = true;
}
$upload_bases = array_keys($upload_bases);
if (empty($upload_bases)) {
    fwrite(STDERR, "ABORT: no uploads directory found (looked for <root>/uploads and <root>/../uploads).\n");
    exit(3);
}

/** Find the existing vendor_<id>_* folder across bases, or null. */
function find_vendor_folder(array $bases, int $vendor_id): ?string {
    foreach ($bases as $base) {
        $hits = glob($base . "/vendor_" . $vendor_id . "_*", GLOB_ONLYDIR);
        if ($hits && count($hits) > 0) return $hits[0];
    }
    return null;
}

/** True if the vendor folder already holds a PQ_Result_*.pdf (across bases). */
function has_pq_result_pdf(array $bases, int $vendor_id): bool {
    $folder = find_vendor_folder($bases, $vendor_id);
    if ($folder === null) return false;
    $pdfs = glob($folder . "/PQ_Result_*.pdf");
    return $pdfs && count($pdfs) > 0;
}

/** Ensure a writable target folder for the vendor; returns path or null. */
function resolve_write_folder(array $bases, int $vendor_id, string $company_name): ?string {
    $existing = find_vendor_folder($bases, $vendor_id);
    if ($existing !== null && is_writable($existing)) return $existing;

    $write_base = null;
    foreach ($bases as $base) {
        if (is_writable($base)) { $write_base = $base; break; }
    }
    if ($write_base === null) return null;

    $safe_name  = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
    $target_dir = $write_base . "/vendor_" . $vendor_id . "_" . $safe_name;
    if (!is_dir($target_dir) && !mkdir($target_dir, 0775, true) && !is_dir($target_dir)) {
        return null;
    }
    return $target_dir;
}

// ---------------------------------------------------------------------------
// Certificate renderer — a faithful copy of the PDF-building block in
// modules/vendormgt/approve_action.php (the 'approve' action). The ONLY changes:
//   * reviewer name / VM notes / printed dates come from the stored approval
//     record instead of the live $_SESSION and NOW(), so a re-run reproduces the
//     original certificate rather than stamping today's date;
//   * it renders to a temp file and returns the path — the caller places it.
// It fires NO database writes and NO notifications.
// Returns the temp PDF path, or null if the submission could not be loaded.
// ---------------------------------------------------------------------------
function render_pq_result_pdf(mysqli $conn, int $sub_id): ?string {
    // --- main submission + vendor row (same SELECT as approve_action) ---
    $stmt = $conn->prepare(
        "SELECT s.*, v.id as vid, v.company_name, v.company_type, v.sub_company_type,
                v.contact_person, v.email, v.k2_status, v.registered_by_sourcing_id
         FROM pq_submissions s
         JOIN vendors v ON s.vendor_id = v.id
         WHERE s.id = ?"
    );
    $stmt->bind_param("i", $sub_id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    if (!$data) return null;

    $vendor_id    = (int) $data['vid'];
    $company_name = $data['company_name'];

    // Risk: recompute from stored scores — identical result to the live approval.
    $max_score       = (float) $data['max_possible_weighted_score'];
    $total_score     = (float) $data['total_weighted_score'];
    $risk_percentage = pqRiskPercentage($total_score, $max_score);
    $risk_category   = pqRiskCategory($risk_percentage);

    // Faithful date basis: the original approval time. Fall back to created_at,
    // then to now, so the field is never blank on legacy rows.
    $approved_at = $data['approved_at'] ?: ($data['created_at'] ?? null);
    $ts = $approved_at ? strtotime($approved_at) : time();

    // Reviewer + notes come from the stored record (not the live session).
    $vm_notes  = $data['vm_notes'] ?? '';
    $vm_reviewer = '-';
    if (!empty($data['vm_reviewer_id'])) {
        $r = $conn->query("SELECT full_name FROM users WHERE id = " . intval($data['vm_reviewer_id']));
        if ($r && ($rr = $r->fetch_assoc())) $vm_reviewer = $rr['full_name'];
    }

    // --- extended vendor data ---
    $v_stmt = $conn->prepare("SELECT v.*, u.email as user_email FROM vendors v JOIN users u ON v.user_id = u.id WHERE v.id = ?");
    $v_stmt->bind_param("i", $vendor_id);
    $v_stmt->execute();
    $vendor_full = $v_stmt->get_result()->fetch_assoc();

    // --- PQ answers with weights + sections (same SELECT as approve_action) ---
    $sub_type = $data['sub_company_type'] ?? '';
    $pq_weight_sub_type = ($data['company_type'] === 'Perseorangan') ? 'Perseorangan' : $sub_type;
    $pq_sql = "SELECT q.question_text, q.help_text as q_help,
                a.selected_score, a.remarks, a.calc_details, a.attachment_path,
                a.reviewer_notes, a.vm_row_notes,
                COALESCE(
                    (SELECT weight FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1),
                    0
                ) as weight,
                COALESCE(
                    (SELECT help_text FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1),
                    q.help_text
                ) as help_text,
                sc.section_name
               FROM pq_questions q
               LEFT JOIN pq_answers a ON q.id = a.question_id AND a.submission_id = ?
               LEFT JOIN pq_sections sc ON q.section_id = sc.id
               WHERE a.id IS NOT NULL
               ORDER BY sc.id ASC, q.id ASC";
    $pq_stmt = $conn->prepare($pq_sql);
    $pq_stmt->bind_param("ssi", $pq_weight_sub_type, $pq_weight_sub_type, $sub_id);
    $pq_stmt->execute();
    $pq_result = $pq_stmt->get_result();

    $pq_sections = [];
    while ($pq_row = $pq_result->fetch_assoc()) {
        $raw_weight = trim((string) $pq_row['weight']);
        if ($raw_weight === '' || $raw_weight === '-' || (float) $raw_weight == 0) {
            continue;
        }
        $sec = $pq_row['section_name'] ?? 'General';
        $pq_sections[$sec][] = $pq_row;
    }

    // --- sourcing reviewer name ---
    $sourcing_reviewer = '-';
    if (!empty($data['sourcing_reviewer_id'])) {
        $rev_q = $conn->query("SELECT full_name FROM users WHERE id = " . intval($data['sourcing_reviewer_id']));
        if ($rev_q && ($rev_row = $rev_q->fetch_assoc())) $sourcing_reviewer = $rev_row['full_name'];
    }

    // ===================== BUILD PDF (verbatim layout) =====================
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('Kawan Lama VMS');
    $pdf->SetAuthor('Vendor Management System');
    $pdf->SetTitle('PQ Result - ' . $company_name);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 20);

    // ============ PAGE 1: COVER + VENDOR PROFILE ============
    $pdf->AddPage();

    $cover_html = '
    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td width="100%" style="background-color:#0056b3; color:#ffffff; padding:18px 20px; border-radius:6px;">
                <h1 style="font-size:20px; margin:0;"> PRE-QUALIFICATION RESULT</h1>
                <p style="font-size:11px; margin:4px 0 0 0; opacity:0.8;"> Vendor Sourcing System &mdash; Kawan Lama Group</p>
            </td>
        </tr>
    </table>
    <br>

    <table cellpadding="6" cellspacing="0" width="100%" style="border:1px solid #dee2e6;">
        <tr style="background-color:#f8f9fa;">
            <td width="25%" style="border-bottom:1px solid #dee2e6;"><strong>Company</strong></td>
            <td width="75%" style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($company_name) . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Type</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($data['company_type']) . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Sub-Category</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($data['sub_company_type'] ?? 'Unclassified') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Date Approved</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . date('d M Y H:i', $ts) . ' WIB</td>
        </tr>
        <tr style="background-color:#d4edda;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Risk Category</strong></td>
            <td style="border-bottom:1px solid #dee2e6; color:#155724;"><strong>' . htmlspecialchars($risk_category) . '</strong></td>
        </tr>
        <tr style="background-color:#d4edda;">
            <td><strong>Risk Percentage</strong></td>
            <td style="color:#155724;"><strong>' . number_format($risk_percentage, 1) . '%</strong></td>
        </tr>
    </table>
    <br>

    <h3 style="color:#155724; font-size:14px;"> APPROVAL STATUS: APPROVED</h3>
    <br>

    <h3 style="color:#0056b3; font-size:13px; border-bottom:2px solid #0056b3; padding-bottom:4px;">A. VENDOR PROFILE</h3>
    <table cellpadding="5" cellspacing="0" width="100%" style="border:1px solid #dee2e6; font-size:10px;">
        <tr style="background-color:#f8f9fa;">
            <td width="30%" style="border-bottom:1px solid #dee2e6;"><strong>Legal Entity</strong></td>
            <td width="70%" style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['legal_entity_type'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Number of Employees</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . (($vendor_full['number_of_employees'] ?? '') !== '' ? number_format((int) $vendor_full['number_of_employees'], 0, ',', '.') : '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Director / Owner</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['director_name'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Contact Person</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['contact_person'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Phone</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['phone'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>PIC Finance</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['pic_finance'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Email PIC Finance</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['email_pic_finance'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Email</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['user_email'] ?? $vendor_full['email'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Establishment Date</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . (!empty($vendor_full['establishment_date']) ? date('d M Y', strtotime($vendor_full['establishment_date'])) : '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Core Business</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['core_business'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>NIB Number</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['nib_number'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>NPWP Number</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['npwp_number'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>NPWP Name</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['npwp_name'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Status PKP</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . (!empty($vendor_full['is_sppkp']) ? 'PKP (Pengusaha Kena Pajak)' : 'Non-PKP') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>WHT Tax Type</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['wht_tax_type'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>WHT Tax Code</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['wht_tax_code'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Bank Account</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['bank_account_number'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Bank Account Holder</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['bank_holder_name'] ?? '-') . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>No. UMKM</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['umkm_cert_number'] ?? '-') . '</td>
        </tr>
        <tr style="background-color:#f8f9fa;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>Director ID (KTP)</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['director_id_number'] ?? '-') . '</td>
        </tr>
        <tr>
            <td><strong>Deed of Establishment</strong></td>
            <td>' . htmlspecialchars($vendor_full['deed_of_establishment_number'] ?? '-') . '</td>
        </tr>
    </table>';

    $pdf->writeHTML($cover_html, true, false, true, false, '');

    // ============ PQ ANSWERS ============
    // No forced AddPage() here: the vendor profile table above already spills
    // onto a fresh page on its own via SetAutoPageBreak whenever it doesn't
    // fit page 1, so forcing another page break right after it leaves that
    // spillover page mostly blank. Let this section flow straight on — TCPDF
    // still breaks pages automatically once it runs out of room, same as the
    // profile table and the notes section below.
    $pq_header = '
    <h3 style="color:#0056b3; font-size:13px; border-bottom:2px solid #0056b3; padding-bottom:4px;">B. PRE-QUALIFICATION ANSWERS</h3>
    <table cellpadding="4" cellspacing="0" width="100%" style="font-size:9px;">
        <tr style="background-color:#0056b3; color:#ffffff;">
            <td width="5%" style="border:1px solid #004494;" align="center"><strong>No</strong></td>
            <td width="22%" style="border:1px solid #004494;"><strong>Question</strong></td>
            <td width="28%" style="border:1px solid #004494;"><strong>Vendor Response</strong></td>
            <td width="20%" style="border:1px solid #004494;"><strong>Reviewer Notes</strong></td>
            <td width="8%" style="border:1px solid #004494;" align="center"><strong>Weight</strong></td>
            <td width="8%" style="border:1px solid #004494;" align="center"><strong>Score</strong></td>
            <td width="9%" style="border:1px solid #004494;" align="center"><strong>Weighted</strong></td>
        </tr>';

    $pq_rows = '';
    $row_num = 0;
    foreach ($pq_sections as $section => $answers) {
        $pq_rows .= '
        <tr style="background-color:#e2e6ea;">
            <td colspan="7" style="border:1px solid #dee2e6; font-size:10px;"><strong>' . htmlspecialchars($section) . '</strong></td>
        </tr>';

        foreach ($answers as $ans) {
            $row_num++;
            $bg = ($row_num % 2 == 0) ? '#f8f9fa' : '#ffffff';

            $q_lower = strtolower($ans['question_text']);
            $is_financial = (strpos($q_lower, 'income') !== false || strpos($q_lower, 'pendapatan') !== false || strpos($q_lower, 'capital') !== false || strpos($q_lower, 'modal') !== false);

            $response_parts = [];

            if ($is_financial && !empty($ans['remarks'])) {
                $amount = str_replace(['.', ','], '', trim($ans['remarks']));
                $response_parts[] = '<strong>Rp ' . number_format((float) $amount, 0, ',', '.') . '</strong>';
            } elseif (!empty($ans['help_text']) && $ans['selected_score'] !== null) {
                preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $ans['help_text'], $matches, PREG_SET_ORDER);
                $label = 'Score: ' . $ans['selected_score'];
                foreach ($matches as $m) {
                    if ($m[1] == $ans['selected_score']) {
                        $label = trim($m[2], "- \t\n\r\0\x0B");
                        break;
                    }
                }
                $response_parts[] = '<strong>' . htmlspecialchars($label) . '</strong>';

                if (!empty($ans['remarks'])) {
                    $remarks_text = $ans['remarks'];
                    $lines = explode("\n", $remarks_text);
                    if (count($lines) > 1) {
                        $items = [];
                        foreach ($lines as $line) {
                            $line = trim($line);
                            if ($line !== '') $items[] = '&bull; ' . htmlspecialchars($line);
                        }
                        $response_parts[] = '<br><span style="color:#495057; font-size:8px;">' . implode('<br>', $items) . '</span>';
                    } else {
                        $response_parts[] = '<br><span style="color:#495057; font-size:8px;"><em>Keterangan:</em> ' . htmlspecialchars(mb_strimwidth($remarks_text, 0, 150, '...')) . '</span>';
                    }
                }
            } elseif (!empty($ans['remarks'])) {
                $remarks_text = $ans['remarks'];
                $lines = explode("\n", $remarks_text);
                if (count($lines) > 1) {
                    $items = [];
                    foreach ($lines as $line) {
                        $line = trim($line);
                        if ($line !== '') $items[] = '&bull; ' . htmlspecialchars($line);
                    }
                    $response_parts[] = implode('<br>', $items);
                } else {
                    $response_parts[] = htmlspecialchars(mb_strimwidth($remarks_text, 0, 150, '...'));
                }
            } else {
                $response_parts[] = '<span style="color:#999;">-</span>';
            }

            // The same calculator figures approve_action.php prints, so a regenerated
            // certificate matches what the original approval would have produced.
            // Submissions answered before the calculator stored its figures simply
            // have none, and the block is omitted.
            $response_parts[] = pqCalcPdfHtml($ans['calc_details'] ?? null);

            $response = implode('', $response_parts);

            $notes_parts = [];
            if (!empty($ans['reviewer_notes'])) {
                $notes_parts[] = '<span style="color:#0056b3;"><strong>Sourcing:</strong></span><br>' . htmlspecialchars(mb_strimwidth($ans['reviewer_notes'], 0, 120, '...'));
            }
            if (!empty($ans['vm_row_notes'])) {
                if (!empty($notes_parts)) $notes_parts[] = '<br>';
                $notes_parts[] = '<span style="color:#fd7e14;"><strong>VM:</strong></span><br>' . htmlspecialchars(mb_strimwidth($ans['vm_row_notes'], 0, 120, '...'));
            }
            $reviewer_notes_html = !empty($notes_parts) ? implode('', $notes_parts) : '<span style="color:#ccc;">-</span>';

            $weight = (float) ($ans['weight'] ?? 0);
            $score = $ans['selected_score'] ?? '-';
            $weighted = '-';
            if ($score !== '-' && $weight > 0) {
                $weighted = number_format(((float) $score / 5) * $weight, 2);
            }

            $pq_rows .= '
            <tr style="background-color:' . $bg . ';">
                <td style="border:1px solid #dee2e6;" align="center">' . $row_num . '</td>
                <td style="border:1px solid #dee2e6;">' . htmlspecialchars($ans['question_text']) . '</td>
                <td style="border:1px solid #dee2e6;">' . $response . '</td>
                <td style="border:1px solid #dee2e6; font-size:8px;">' . $reviewer_notes_html . '</td>
                <td style="border:1px solid #dee2e6;" align="center">' . number_format($weight, 1) . '</td>
                <td style="border:1px solid #dee2e6;" align="center">' . $score . '</td>
                <td style="border:1px solid #dee2e6;" align="center">' . $weighted . '</td>
            </tr>';
        }
    }

    $pq_rows .= '
    <tr style="background-color:#d4edda;">
        <td colspan="5" style="border:1px solid #dee2e6;" align="right"><strong>Total Weighted Score</strong></td>
        <td colspan="2" style="border:1px solid #dee2e6;" align="center"><strong>' . number_format($total_score, 2) . ' / ' . number_format($max_score, 2) . '</strong></td>
    </tr>
    <tr style="background-color:#d4edda;">
        <td colspan="5" style="border:1px solid #dee2e6;" align="right"><strong>Risk Percentage</strong></td>
        <td colspan="2" style="border:1px solid #dee2e6;" align="center"><strong>' . number_format($risk_percentage, 1) . '% (' . htmlspecialchars($risk_category) . ')</strong></td>
    </tr>';

    $pq_table = $pq_header . $pq_rows . '</table>';
    $pdf->writeHTML($pq_table, true, false, true, false, '');

    // ============ NOTES SECTION ============
    $notes_html = '
    <br>
    <h3 style="color:#0056b3; font-size:13px; border-bottom:2px solid #0056b3; padding-bottom:4px;">C. REVIEW NOTES</h3>

    <table cellpadding="8" cellspacing="0" width="100%" style="font-size:10px; border:1px solid #dee2e6;">
        <tr style="background-color:#e7f1ff;">
            <td width="25%" style="border-bottom:1px solid #dee2e6;"><strong>Sourcing Reviewer</strong></td>
            <td width="75%" style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($sourcing_reviewer) . '</td>
        </tr>
        <tr>
            <td style="border-bottom:1px solid #dee2e6;"><strong>Sourcing Notes</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . nl2br(htmlspecialchars($data['sourcing_notes'] ?? '-')) . '</td>
        </tr>
        <tr style="background-color:#e7f1ff;">
            <td style="border-bottom:1px solid #dee2e6;"><strong>VM Head Approver</strong></td>
            <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vm_reviewer) . '</td>
        </tr>
        <tr>
            <td><strong>VM Notes</strong></td>
            <td>' . nl2br(htmlspecialchars($vm_notes ?: '-')) . '</td>
        </tr>
    </table>

    <br><br>
    <table cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td style="border-top:2px solid #dee2e6; padding-top:8px; font-size:8px; color:#999;">
                This document is automatically generated by Kawan Lama Vendor Sourcing System on ' . date('d M Y H:i:s', $ts) . ' WIB.
                <br>Document ID: PQ-' . $vendor_id . '-' . $sub_id . '-' . date('Ymd', $ts) . '
            </td>
        </tr>
    </table>';

    $pdf->writeHTML($notes_html, true, false, true, false, '');

    $temp_path = sys_get_temp_dir() . '/PQ_regen_' . uniqid('', true) . '.pdf';
    $pdf->Output($temp_path, 'F');
    return $temp_path;
}

// ---------------------------------------------------------------------------
// Select candidates: the latest approved submission per vendor.
// ---------------------------------------------------------------------------
$sql = "SELECT s.id AS sub_id, s.vendor_id, s.approved_at, v.company_name, v.registration_status
        FROM pq_submissions s
        JOIN vendors v ON v.id = s.vendor_id
        JOIN (
            SELECT vendor_id, MAX(id) AS max_id
            FROM pq_submissions
            WHERE status = 'approved'
            GROUP BY vendor_id
        ) latest ON latest.vendor_id = s.vendor_id AND latest.max_id = s.id
        WHERE s.status = 'approved'";
if ($onlyVendor) {
    $sql .= " AND s.vendor_id = " . intval($onlyVendor);
}
$sql .= " ORDER BY s.vendor_id ASC";

$res = $conn->query($sql);

$total = 0; $skipped_have_pdf = 0; $generated = 0; $failed = 0; $candidates = 0;

while ($row = $res->fetch_assoc()) {
    $total++;
    $vid  = (int) $row['vendor_id'];
    $sid  = (int) $row['sub_id'];
    $name = $row['company_name'];

    $already = has_pq_result_pdf($upload_bases, $vid);
    if ($already && !$force) {
        $skipped_have_pdf++;
        continue;
    }
    $candidates++;

    if (!$apply) {
        $tag = $already ? " (has PDF — would regenerate due to --force)" : "";
        printf("  WOULD GENERATE  vendor #%-5d sub #%-5d  %s%s\n", $vid, $sid, $name, $tag);
        continue;
    }

    // APPLY: render then place the file.
    $folder = resolve_write_folder($upload_bases, $vid, $name);
    if ($folder === null) {
        printf("  FAIL (no writable folder)  vendor #%-5d  %s\n", $vid, $name);
        $failed++;
        continue;
    }

    try {
        $temp = render_pq_result_pdf($conn, $sid);
    } catch (\Throwable $e) {
        printf("  FAIL (render error)  vendor #%-5d sub #%-5d  %s :: %s\n", $vid, $sid, $name, $e->getMessage());
        $failed++;
        continue;
    }
    if ($temp === null || !is_file($temp)) {
        printf("  FAIL (no output)  vendor #%-5d sub #%-5d  %s\n", $vid, $sid, $name);
        $failed++;
        continue;
    }

    // Filename anchored to approval time so it's stable across re-runs.
    $ts_file = $row['approved_at'] ? date('Ymd_His', strtotime($row['approved_at'])) : date('Ymd_His');
    $file_name = 'PQ_Result_' . $ts_file . '.pdf';
    $dest = $folder . '/' . $file_name;

    if (!@rename($temp, $dest)) {
        if (!@copy($temp, $dest)) {
            @unlink($temp);
            printf("  FAIL (move error)  vendor #%-5d  %s\n", $vid, $name);
            $failed++;
            continue;
        }
        @unlink($temp);
    }
    @chmod($dest, 0664);

    printf("  OK  vendor #%-5d sub #%-5d  %s\n      -> %s\n", $vid, $sid, $name, $dest);
    $generated++;
}

echo "\n----------------------------------------------------------------\n";
echo "Latest-approved submissions scanned : {$total}\n";
echo "Already had a PDF (skipped)         : {$skipped_have_pdf}\n";
if ($apply) {
    echo "PDFs generated                      : {$generated}\n";
    echo "Failures                            : {$failed}\n";
} else {
    echo "Candidates that WOULD be generated  : {$candidates}\n";
    echo "\n[DRY RUN] Nothing was written. Add --apply --confirm-db={$id['d']} to generate.\n";
}
echo "----------------------------------------------------------------\n";
