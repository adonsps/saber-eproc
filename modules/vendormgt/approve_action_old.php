<?php
// modules/vendormgt/approve_action.php
session_start();
require '../../config.php';
require '../../libs/tcpdf/tcpdf.php'; // LOAD PDF LIBRARY

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['vendor_mgt', 'vendormgt_head'])) {
    die("Access Denied");
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    $sub_id = intval($_POST['submission_id']);
    
    // --- 1. HANDLE ROW-LEVEL AJAX SAVE ---
    if (isset($_POST['action']) && $_POST['action'] === 'save_vm_rows') {
        
        $conn->begin_transaction();
        try {
            // Clear current VM flags to handle unchecked boxes
            $conn->query("UPDATE pq_answers SET vm_needs_revision = 0 WHERE submission_id = $sub_id");

            // Process Notes
            if (isset($_POST['vm_row_notes'])) {
                $stmt = $conn->prepare("UPDATE pq_answers SET vm_row_notes = ? WHERE submission_id = ? AND question_id = ?");
                foreach ($_POST['vm_row_notes'] as $qid => $note) {
                    $qid  = intval($qid);
                    $note = str_replace(["\r\n", "\r"], "\n", $note);
                    $stmt->bind_param("sii", $note, $sub_id, $qid);
                    $stmt->execute();
                }
            }
            
            // Process Flags
            if (isset($_POST['vm_revision_flags'])) {
                $stmt = $conn->prepare("UPDATE pq_answers SET vm_needs_revision = 1 WHERE submission_id = ? AND question_id = ?");
                foreach ($_POST['vm_revision_flags'] as $qid => $val) {
                    $qid = intval($qid);
                    $stmt->bind_param("ii", $sub_id, $qid);
                    $stmt->execute();
                }
            }

            $conn->commit();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'success']);
            exit;

        } catch (Exception $e) {
            $conn->rollback();
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
    }

    // --- 2. HANDLE FINAL DECISIONS ---
    
    $vm_notes_new = mysqli_real_escape_string($conn, str_replace(["\r\n", "\r"], "\n", $_POST['vm_notes'] ?? ''));
    
    // Fetch existing notes to append (preserve conversation trail)
    $existing_notes_q = $conn->query("SELECT vm_notes FROM pq_submissions WHERE id = $sub_id");
    $existing_notes_row = $existing_notes_q->fetch_assoc();
    $existing_notes = $existing_notes_row['vm_notes'] ?? '';
    
    // Build appended note with role + timestamp
    $role_label = ($_SESSION['role'] === 'vendormgt_head') ? 'VM Head' : 'VM Team';
    $note_header = '[' . date('d M Y H:i') . ' - ' . $role_label . ' (' . ($_SESSION['user_name'] ?? '') . ')]';
    
    if (!empty($existing_notes)) {
        $vm_notes = $note_header . "\n" . $vm_notes_new . "\n---\n" . $existing_notes;
    } else {
        $vm_notes = $note_header . "\n" . $vm_notes_new;
    }
    
    // Determine the action
    $action = '';
    if (isset($_POST['approve_btn'])) {
        $action = 'approve';
    } elseif (isset($_POST['action'])) {
        $action = $_POST['action'];
    }

    if (empty($action)) {
        die("No action specified.");
    }
    
    // Fetch Full Data for the PDF and Status Updates
    $sql = "SELECT s.*, v.id as vid, v.company_name, v.company_type, v.sub_company_type, v.contact_person, v.email
            FROM pq_submissions s
            JOIN vendors v ON s.vendor_id = v.id
            WHERE s.id = '$sub_id'";
    $data = $conn->query($sql)->fetch_assoc();
    
    $vendor_id = $data['vid'];
    $company_name = $data['company_name'];
    
    // --- DYNAMIC RISK CALCULATION (Ensures accurate 5-tier logic for PDF) ---
    $max_score = (float)$data['max_possible_weighted_score'];
    $total_score = (float)$data['total_weighted_score'];
    $risk_percentage = 0;

    if ($max_score > 0) {
        $risk_percentage = (($max_score - $total_score) / $max_score) * 100;
    }

    $risk_percentage = max(0, min(100, $risk_percentage));

    if ($risk_percentage > 60) {
        $risk_category = 'Bad';
    } elseif ($risk_percentage >= 50) { 
        $risk_category = 'Poor';
    } elseif ($risk_percentage >= 30) { 
        $risk_category = 'Fair';
    } elseif ($risk_percentage >= 15) { 
        $risk_category = 'Good';
    } else {                            
        $risk_category = 'Excellent';
    }
    // ------------------------------------------------------------------------

    // Logic based on Action
    if ($action == 'approve') {
        
        if ($_SESSION['role'] !== 'vendormgt_head') {
            die("<script>alert('ERROR: Only VM Head can perform final approval and PDF generation.'); window.history.back();</script>");
        }
        
        // --- PDF GENERATION LOGIC ---
        $root_path = dirname(__DIR__, 2);
        $uploads_dir_base = $root_path . "/../uploads/";
        
        $vendor_folders = glob($uploads_dir_base . "vendor_" . $vendor_id . "_*", GLOB_ONLYDIR);
        
        if ($vendor_folders && count($vendor_folders) > 0) {
            $target_dir = $vendor_folders[0];
        } else {
            $safe_name = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
            $target_dir = $uploads_dir_base . "vendor_" . $vendor_id . "_" . $safe_name;
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0777, true);
            }
        }

        // --- FETCH EXTENDED VENDOR DATA ---
        $v_sql = "SELECT v.*, u.email as user_email FROM vendors v JOIN users u ON v.user_id = u.id WHERE v.id = ?";
        $v_stmt = $conn->prepare($v_sql);
        $v_stmt->bind_param("i", $vendor_id);
        $v_stmt->execute();
        $vendor_full = $v_stmt->get_result()->fetch_assoc();

        // --- FETCH PQ ANSWERS WITH SECTIONS ---
        $sub_type = $data['sub_company_type'] ?? '';
        $pq_sql = "SELECT q.question_text, q.help_text as q_help,
                    a.selected_score, a.remarks, a.attachment_path,
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
        $pq_stmt->bind_param("ssi", $sub_type, $sub_type, $sub_id);
        $pq_stmt->execute();
        $pq_result = $pq_stmt->get_result();

        // Group answers by section
        // $pq_sections = [];
        // while ($pq_row = $pq_result->fetch_assoc()) {
        //     $sec = $pq_row['section_name'] ?? 'General';
        //     $pq_sections[$sec][] = $pq_row;
        // }
        
        // Group answers by section
        $pq_sections = [];
        while ($pq_row = $pq_result->fetch_assoc()) {
            
            // FIX: Filter out rows where weight is blank, '-', or 0.
            // (Note: Because of COALESCE in your SQL, 'null' weights become '0' here)
            $raw_weight = trim((string)$pq_row['weight']);
            if ($raw_weight === '' || $raw_weight === '-' || (float)$raw_weight == 0) {
                continue; // Skip this row entirely
            }

            $sec = $pq_row['section_name'] ?? 'General';
            $pq_sections[$sec][] = $pq_row;
        }

        // --- FETCH REVIEWER NAMES ---
        $sourcing_reviewer = '-';
        if (!empty($data['sourcing_reviewer_id'])) {
            $rev_q = $conn->query("SELECT full_name FROM users WHERE id = " . intval($data['sourcing_reviewer_id']));
            if ($rev_row = $rev_q->fetch_assoc()) $sourcing_reviewer = $rev_row['full_name'];
        }
        $vm_reviewer = $_SESSION['user_name'] ?? '-';

        if (ob_get_length()) ob_clean();

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

        // Cover header
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
                <td style="border-bottom:1px solid #dee2e6;">' . date('d M Y H:i') . ' WIB</td>
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
                <td style="border-bottom:1px solid #dee2e6;"><strong>Bank Account</strong></td>
                <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['bank_account_number'] ?? '-') . '</td>
            </tr>
            <tr>
                <td style="border-bottom:1px solid #dee2e6;"><strong>Director ID (KTP)</strong></td>
                <td style="border-bottom:1px solid #dee2e6;">' . htmlspecialchars($vendor_full['director_id_number'] ?? '-') . '</td>
            </tr>
            <tr style="background-color:#f8f9fa;">
                <td><strong>Deed of Establishment</strong></td>
                <td>' . htmlspecialchars($vendor_full['deed_of_establishment_number'] ?? '-') . '</td>
            </tr>
        </table>';

        $pdf->writeHTML($cover_html, true, false, true, false, '');

        // ============ PAGE 2+: PQ ANSWERS ============
        $pdf->AddPage();

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
            // Section header row
            $pq_rows .= '
            <tr style="background-color:#e2e6ea;">
                <td colspan="7" style="border:1px solid #dee2e6; font-size:10px;"><strong>' . htmlspecialchars($section) . '</strong></td>
            </tr>';

            foreach ($answers as $ans) {
                $row_num++;
                $bg = ($row_num % 2 == 0) ? '#f8f9fa' : '#ffffff';
                
                // Determine vendor response display
                $q_lower = strtolower($ans['question_text']);
                $is_financial = (strpos($q_lower, 'income') !== false || strpos($q_lower, 'pendapatan') !== false || strpos($q_lower, 'capital') !== false || strpos($q_lower, 'modal') !== false);
                
                $response_parts = [];
                
                if ($is_financial && !empty($ans['remarks'])) {
                    // Financial: show Rupiah amount only
                    $amount = str_replace(['.', ','], '', trim($ans['remarks']));
                    $response_parts[] = '<strong>Rp ' . number_format((float)$amount, 0, ',', '.') . '</strong>';
                } elseif (!empty($ans['help_text']) && $ans['selected_score'] !== null) {
                    // Standard: match score to help_text option label
                    preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $ans['help_text'], $matches, PREG_SET_ORDER);
                    $label = 'Score: ' . $ans['selected_score'];
                    foreach ($matches as $m) {
                        if ($m[1] == $ans['selected_score']) {
                            $label = trim($m[2], "- \t\n\r\0\x0B");
                            break;
                        }
                    }
                    $response_parts[] = '<strong>' . htmlspecialchars($label) . '</strong>';
                    
                    // Also show remarks if vendor provided additional text (reason/alasan)
                    if (!empty($ans['remarks'])) {
                        $remarks_text = $ans['remarks'];
                        // Check if remarks is multi-line (project list, etc.)
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
                    // Remarks only (no help_text match)
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

                $response = implode('', $response_parts);

                // Build reviewer notes column
                $notes_parts = [];
                if (!empty($ans['reviewer_notes'])) {
                    $notes_parts[] = '<span style="color:#0056b3;"><strong>Sourcing:</strong></span><br>' . htmlspecialchars(mb_strimwidth($ans['reviewer_notes'], 0, 120, '...'));
                }
                if (!empty($ans['vm_row_notes'])) {
                    if (!empty($notes_parts)) $notes_parts[] = '<br>';
                    $notes_parts[] = '<span style="color:#fd7e14;"><strong>VM:</strong></span><br>' . htmlspecialchars(mb_strimwidth($ans['vm_row_notes'], 0, 120, '...'));
                }
                $reviewer_notes_html = !empty($notes_parts) ? implode('', $notes_parts) : '<span style="color:#ccc;">-</span>';

                $weight = (float)($ans['weight'] ?? 0);
                $score = $ans['selected_score'] ?? '-';
                $weighted = '-';
                if ($score !== '-' && $weight > 0) {
                    $weighted = number_format(((float)$score / 5) * $weight, 2);
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

        // Score summary row
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
                    This document is automatically generated by Kawan Lama Vendor Sourcing System on ' . date('d M Y H:i:s') . ' WIB.
                    <br>Document ID: PQ-' . $vendor_id . '-' . $sub_id . '-' . date('Ymd') . '
                </td>
            </tr>
        </table>';

        $pdf->writeHTML($notes_html, true, false, true, false, '');

        $file_name = 'PQ_Result_' . date('Ymd_His') . '.pdf';
        $full_path = $target_dir . '/' . $file_name;
        
        $pdf->Output($full_path, 'F'); 

        // Update Database Logic (Now storing the synchronized risk variables)
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='approved', vm_notes=?, approved_at=NOW(), vm_reviewer_id=?, risk_percentage=?, risk_category=? WHERE id=?");
            $stmt->bind_param("sidsi", $vm_notes, $_SESSION['user_id'], $risk_percentage, $risk_category, $sub_id);
            $stmt->execute();

            require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';
            $sla_deadline = calculateSlaDeadline($conn, date('Y-m-d H:i:s'), 2);
            
            $stmt_sla = $conn->prepare("
                UPDATE vendors SET registration_status='approved', sla_deadline=?, sla_status='pending'
                WHERE id=?
            ");
            $stmt_sla->bind_param("si", $sla_deadline, $vendor_id);
            $stmt_sla->execute();

            $conn->commit();
            
            // --- NEW: Notify Admin for K2 registration ---
            require_once dirname(__DIR__, 2) . '/includes/notification_helper.php';
            notifyAdminsOnVendorApproval($_SESSION['user_id'], $vendor_id, $company_name, $full_path);
            // --- END NEW ---
            
            echo "<script>
                alert('Vendor Approved! Result PDF generated and saved to vendor folder.');
                window.location.href = 'dashboard.php';
            </script>";

        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }

    } elseif ($action == 'forward_head') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='under_review_vm_head', vm_notes=? WHERE id=?");
            $stmt->bind_param("si", $vm_notes, $sub_id);
            $stmt->execute();

            $conn->query("UPDATE vendors SET registration_status='under_review_vm_head' WHERE id='$vendor_id'");

            // PQ SLA: close the current leg as forward_head
            require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';
            closePqSlaLeg($conn, $sub_id, $_SESSION['user_id'], 'forward_head');

            $conn->commit();
            
            echo "<script>
                alert('Vendor Application successfully forwarded to VM Head.');
                window.location.href = 'dashboard.php';
            </script>";

        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }

    } elseif ($action == 'revision') {
        $conn->begin_transaction();
        try {
            // Update Submission Status (Returns to Sourcing)
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='returned_to_sourcing', vm_notes=? WHERE id=?");
            $stmt->bind_param("si", $vm_notes, $sub_id);
            $stmt->execute();

            // Update Vendor Status
            $conn->query("UPDATE vendors SET registration_status='returned_to_sourcing' WHERE id='$vendor_id'");

            // PQ SLA: close the current leg as returned_to_sourcing (log elapsed, reset clock)
            require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';
            closePqSlaLeg($conn, $sub_id, $_SESSION['user_id'], 'returned_to_sourcing');

            $conn->commit();
            
            echo "<script>
                alert('Vendor Application Returned to Sourcing Team.');
                window.location.href = 'dashboard.php';
            </script>";

        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }

    } elseif ($action == 'return_to_vm') {
        // VM Head returns to VM Team for further review
        if ($_SESSION['role'] !== 'vendormgt_head') {
            die("<script>alert('ERROR: Only VM Head can return to VM Team.'); window.history.back();</script>");
        }
        
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='under_review_vm', vm_notes=? WHERE id=?");
            $stmt->bind_param("si", $vm_notes, $sub_id);
            $stmt->execute();

            $conn->query("UPDATE vendors SET registration_status='under_review_vm' WHERE id='$vendor_id'");

            $conn->commit();
            
            echo "<script>
                alert('Vendor Application Returned to VM Team for further review.');
                window.location.href = 'dashboard.php';
            </script>";

        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }

    } elseif ($action == 'reject') {
        $conn->begin_transaction();
        try {
            // Update Submission Status
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='rejected', vm_notes=?, approved_at=NOW(), vm_reviewer_id=? WHERE id=?");
            $stmt->bind_param("sii", $vm_notes, $_SESSION['user_id'], $sub_id);
            $stmt->execute();

            // Update Vendor Status
            $conn->query("UPDATE vendors SET registration_status='rejected' WHERE id='$vendor_id'");

            $conn->commit();
            
            echo "<script>
                alert('Vendor Application Rejected.');
                window.location.href = 'dashboard.php';
            </script>";

        } catch (Exception $e) {
            $conn->rollback();
            echo "Error: " . $e->getMessage();
        }
    }
}
?>