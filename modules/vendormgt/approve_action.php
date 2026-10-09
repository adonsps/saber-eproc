<?php
// modules/vendormgt/approve_action.php
session_start();
require '../../config.php';
require '../../libs/tcpdf/tcpdf.php'; // LOAD PDF LIBRARY
require_once '../../includes/pq_risk_helper.php';
require_once '../../includes/pq_calc_details.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['vendor_mgt', 'vendormgt_head'])) {
    die("Access Denied");
}

/**
 * Render a self-contained, styled result popup and redirect afterwards.
 * No external CSS/JS needed (this handler's response has none loaded).
 *
 * @param string $title    Bold heading shown under the icon
 * @param string $message  Supporting line of text
 * @param string $redirect URL to send the user to
 * @param string $theme    'success' (green) | 'info' (orange) | 'danger' (red)
 */
function render_action_result($title, $message, $redirect = 'dashboard.php', $theme = 'success') {
    $themes = [
        'success' => ['#198754', '#0f5132', 'M20 6L9 17l-5-5'],                 // check
        'info'    => ['#fd7e14', '#cc5f00', 'M9 14l-4-4 4-4 M5 10h8a5 5 0 0 1 5 5v2'], // return arrow
        'danger'  => ['#dc3545', '#842029', 'M18 6L6 18 M6 6l12 12'],           // cross
    ];
    [$c1, $c2, $path] = $themes[$theme] ?? $themes['success'];
    $t = htmlspecialchars($title, ENT_QUOTES);
    $m = htmlspecialchars($message, ENT_QUOTES);
    $r = htmlspecialchars($redirect, ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>$t</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;}
  body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f4f7f6 0%,#e9eef3 100%);}
  .rp-card{background:#fff;width:92%;max-width:400px;border-radius:1.25rem;padding:2.5rem 2rem 2rem;text-align:center;
    box-shadow:0 20px 50px rgba(0,0,0,.15);animation:rpPop .45s cubic-bezier(.22,1.2,.36,1);position:relative;overflow:hidden;}
  .rp-icon{width:88px;height:88px;margin:0 auto 1.5rem;border-radius:50%;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,$c1 0%,$c2 100%);box-shadow:0 10px 24px rgba(0,0,0,.20);position:relative;}
  .rp-icon svg{width:44px;height:44px;stroke:#fff;stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round;
    animation:rpDraw .5s ease-out .2s both;}
  .rp-icon::after{content:"";position:absolute;inset:-8px;border-radius:50%;border:3px solid $c1;opacity:.35;animation:rpPulse 1.8s ease-out infinite;}
  .rp-title{font-size:1.35rem;font-weight:700;color:#212529;margin-bottom:.5rem;}
  .rp-msg{color:#6c757d;line-height:1.6;font-size:.95rem;margin-bottom:1.75rem;}
  .rp-btn{display:inline-block;border:none;cursor:pointer;color:#fff;font-weight:700;font-size:.95rem;
    padding:.7rem 2.2rem;border-radius:50rem;text-decoration:none;background:linear-gradient(135deg,$c1 0%,$c2 100%);
    box-shadow:0 4px 14px rgba(0,0,0,.18);transition:transform .2s ease,box-shadow .2s ease;}
  .rp-btn:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(0,0,0,.25);}
  .rp-bar{position:absolute;left:0;bottom:0;height:4px;width:100%;background:linear-gradient(90deg,$c1,$c2);
    transform-origin:left;animation:rpBar 2.6s linear forwards;}
  @keyframes rpPop{0%{transform:scale(.8);opacity:0;}100%{transform:scale(1);opacity:1;}}
  @keyframes rpDraw{0%{stroke-dasharray:60;stroke-dashoffset:60;}100%{stroke-dashoffset:0;}}
  @keyframes rpPulse{0%{transform:scale(1);opacity:.35;}100%{transform:scale(1.4);opacity:0;}}
  @keyframes rpBar{0%{transform:scaleX(1);}100%{transform:scaleX(0);}}
</style></head>
<body>
  <div class="rp-card">
    <div class="rp-icon"><svg viewBox="0 0 24 24"><path d="$path"/></svg></div>
    <div class="rp-title">$t</div>
    <div class="rp-msg">$m</div>
    <a href="$r" class="rp-btn">Go to Dashboard</a>
    <div class="rp-bar"></div>
  </div>
  <script>setTimeout(function(){window.location.href='$r';},2600);</script>
</body></html>
HTML;
    exit;
}

/**
 * Render a friendly "something went wrong — please try again" page with a button
 * that takes the user back to the previous page so they can retry the action.
 * Used for transient DB errors (e.g. InnoDB deadlocks) so users see a clear
 * retry prompt instead of a raw SQL error string.
 */
function render_error_retry($message = 'Terjadi kesalahan saat memproses permintaan. Silakan coba lagi.') {
    $m = htmlspecialchars($message, ENT_QUOTES);
    echo <<<HTML
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Terjadi Kesalahan</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',system-ui,-apple-system,sans-serif;}
  body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#f4f7f6 0%,#e9eef3 100%);}
  .rp-card{background:#fff;width:92%;max-width:400px;border-radius:1.25rem;padding:2.5rem 2rem 2rem;text-align:center;
    box-shadow:0 20px 50px rgba(0,0,0,.15);animation:rpPop .45s cubic-bezier(.22,1.2,.36,1);position:relative;overflow:hidden;}
  .rp-icon{width:88px;height:88px;margin:0 auto 1.5rem;border-radius:50%;display:flex;align-items:center;justify-content:center;
    background:linear-gradient(135deg,#dc3545 0%,#842029 100%);box-shadow:0 10px 24px rgba(0,0,0,.20);}
  .rp-icon svg{width:44px;height:44px;stroke:#fff;stroke-width:3;fill:none;stroke-linecap:round;stroke-linejoin:round;}
  .rp-title{font-size:1.35rem;font-weight:700;color:#212529;margin-bottom:.5rem;}
  .rp-msg{color:#6c757d;line-height:1.6;font-size:.95rem;margin-bottom:1.75rem;}
  .rp-btn{display:inline-block;border:none;cursor:pointer;color:#fff;font-weight:700;font-size:.95rem;
    padding:.7rem 2.2rem;border-radius:50rem;text-decoration:none;background:linear-gradient(135deg,#dc3545 0%,#842029 100%);
    box-shadow:0 4px 14px rgba(0,0,0,.18);transition:transform .2s ease,box-shadow .2s ease;}
  .rp-btn:hover{transform:translateY(-2px);box-shadow:0 8px 18px rgba(0,0,0,.25);}
  @keyframes rpPop{0%{transform:scale(.8);opacity:0;}100%{transform:scale(1);opacity:1;}}
</style></head>
<body>
  <div class="rp-card">
    <div class="rp-icon"><svg viewBox="0 0 24 24"><path d="M12 8v5"/><path d="M12 17h.01"/><circle cx="12" cy="12" r="10"/></svg></div>
    <div class="rp-title">Terjadi Kesalahan</div>
    <div class="rp-msg">$m</div>
    <button type="button" class="rp-btn" onclick="history.back()">Coba Lagi</button>
  </div>
</body></html>
HTML;
    exit;
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
    
    // Do NOT use mysqli_real_escape_string here — bind_param handles escaping internally.
    // Applying both causes double-escaping: apostrophes stored as \' in the DB and PDF.
    $vm_notes_new = str_replace(["\r\n", "\r"], "\n", $_POST['vm_notes'] ?? '');
    
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
    $sql = "SELECT s.*, v.id as vid, v.company_name, v.company_type, v.sub_company_type, v.contact_person, v.email, v.k2_status, v.registered_by_sourcing_id
            FROM pq_submissions s
            JOIN vendors v ON s.vendor_id = v.id
            WHERE s.id = '$sub_id'";
    $data = $conn->query($sql)->fetch_assoc();
    
    $vendor_id = $data['vid'];
    $company_name = $data['company_name'];
    
    // --- DYNAMIC RISK CALCULATION (5-TIER RISK LOGIC, satu sumber di includes/pq_risk_helper.php) ---
    $max_score = (float)$data['max_possible_weighted_score'];
    $total_score = (float)$data['total_weighted_score'];
    $risk_percentage = pqRiskPercentage($total_score, $max_score);
    $risk_category = pqRiskCategory($risk_percentage);
    // ------------------------------------------------------------------------

    // Logic based on Action
    if ($action == 'approve') {
        
        if ($_SESSION['role'] !== 'vendormgt_head') {
            die("<script>alert('ERROR: Only VM Head can perform final approval and PDF generation.'); window.history.back();</script>");
        }
        
        // --- PDF GENERATION LOGIC ---
        // Uploads live in two places depending on deployment: the production
        // compose file mounts the persistent volume at <root>/uploads, while this
        // code historically wrote to <root>/../uploads (= /var/www in the
        // container — root-owned, NOT writable by www-data, and not web-served).
        // That meant approved PQ certificates never reached the browser-reachable
        // volume in prod, so the "PDF Result" badge on all_vendors.php stayed
        // hidden. Reuse an existing vendor folder wherever it lives, but create /
        // write new ones in a base that actually exists and is writable,
        // preferring <root>/uploads. Mirrors profile_action.php (commit 699066a)
        // and download_vendor_docs.php (commit d4ed303).
        $root_path = dirname(__DIR__, 2);
        $upload_bases = [];
        foreach ([$root_path . '/uploads', $root_path . '/../uploads'] as $cand) {
            $real = realpath($cand);
            if ($real && is_dir($real)) $upload_bases[$real] = true;
        }
        $upload_bases = array_keys($upload_bases);

        $target_dir = null;
        foreach ($upload_bases as $base) {
            $existing = glob($base . "/vendor_" . $vendor_id . "_*", GLOB_ONLYDIR);
            if ($existing && count($existing) > 0 && is_writable($existing[0])) {
                $target_dir = $existing[0];
                break;
            }
        }

        if ($target_dir === null) {
            $write_base = null;
            foreach ($upload_bases as $base) {
                if (is_writable($base)) { $write_base = $base; break; }
            }
            if ($write_base === null) {
                die("<script>alert('ERROR: uploads folder is not writable; cannot save PQ certificate.'); window.history.back();</script>");
            }
            $safe_name = preg_replace('/[^A-Za-z0-9]/', '_', $company_name);
            $target_dir = $write_base . "/vendor_" . $vendor_id . "_" . $safe_name;
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0775, true);
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
        // Perseorangan has its own standalone question/weight set keyed by
        // 'Perseorangan' for the weight/help_text lookup below.
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
        // No forced AddPage() here: the vendor profile table above already
        // spills onto a fresh page on its own via SetAutoPageBreak whenever it
        // doesn't fit page 1, so forcing another page break right after it
        // leaves that spillover page mostly blank. Let this section flow
        // straight on — TCPDF still breaks pages automatically once it runs
        // out of room, same as the profile table and the notes section below.
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

                // Quick Ratio / Debt to Equity: print the figures the vendor's
                // percentage came from, right under the band it selected. The
                // approved PDF is the record an auditor reads, so the evidence
                // has to travel with the score.
                $response_parts[] = pqCalcPdfHtml($ans['calc_details'] ?? null);

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

        // Generate PDF to a temp file first — we only move it to the vendor folder
        // AFTER the DB transaction commits successfully. This prevents orphan PDFs
        // from landing on disk when the DB update fails.
        $temp_path = sys_get_temp_dir() . '/PQ_tmp_' . uniqid('', true) . '.pdf';
        $pdf->Output($temp_path, 'F');

        // Update Database Logic (Now storing the synchronized risk variables)
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='approved', vm_notes=?, approved_at=NOW(), vm_reviewer_id=?, risk_percentage=?, risk_category=? WHERE id=?");
            $stmt->bind_param("sidsi", $vm_notes, $_SESSION['user_id'], $risk_percentage, $risk_category, $sub_id);
            $stmt->execute();

            // Only open the K2 gate if the vendor has not already progressed past it.
            // (ER approval / migration may have already registered the vendor in K2;
            // never downgrade in_progress/registered back to pending.)
            $open_k2 = in_array((string)($data['k2_status'] ?? ''), ['', 'pending'], true);

            if ($open_k2) {
                require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';
                // Admin K2-registration SLA = 3 working days: the admin waits on
                // external Finance + MDM before the K2 vendor code exists.
                $sla_deadline = calculateSlaDeadline($conn, date('Y-m-d H:i:s'), 3);

                $stmt_sla = $conn->prepare("
                    UPDATE vendors SET registration_status='approved', k2_status='pending', sla_deadline=?, sla_status='pending'
                    WHERE id=?
                ");
                $stmt_sla->bind_param("si", $sla_deadline, $vendor_id);
                $stmt_sla->execute();
            } else {
                $stmt_sla = $conn->prepare("UPDATE vendors SET registration_status='approved' WHERE id=?");
                $stmt_sla->bind_param("i", $vendor_id);
                $stmt_sla->execute();
            }

            $conn->commit();

            // DB is committed — now it is safe to move the PDF to the vendor folder.
            // If rename() fails (e.g. cross-device), fall back to copy+delete.
            if (!rename($temp_path, $full_path)) {
                copy($temp_path, $full_path);
                @unlink($temp_path);
            }

            // --- Notify Admin for K2 registration (skip if vendor is already in/through K2) ---
            if ($open_k2) {
                require_once dirname(__DIR__, 2) . '/includes/notification_helper.php';
                notifyAdminsOnVendorApproval($_SESSION['user_id'], $vendor_id, $company_name, $full_path);
            }
            // --- END Notify ---

            echo "<script>
                alert('Vendor Approved! Result PDF generated and saved to vendor folder.');
                window.location.href = 'dashboard.php';
            </script>";

        } catch (Exception $e) {
            $conn->rollback();
            // Clean up the temp PDF — nothing should land in the vendor folder
            // if the DB update did not commit.
            @unlink($temp_path);
            render_error_retry();
        }

    } elseif ($action == 'forward_head') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='under_review_vm_head', vm_notes=? WHERE id=?");
            $stmt->bind_param("si", $vm_notes, $sub_id);
            $stmt->execute();

            if (!$conn->query("UPDATE vendors SET registration_status='under_review_vm_head' WHERE id='$vendor_id'")) {
                throw new Exception("Vendor status update failed: " . $conn->error);
            }

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
            if (!$conn->query("UPDATE vendors SET registration_status='returned_to_sourcing' WHERE id='$vendor_id'")) {
                throw new Exception("Vendor status update failed: " . $conn->error);
            }

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

    } elseif ($action == 'return_to_vendor') {
        // Direct Registration: Sourcing filled/submitted the PQ themselves —
        // the vendor never touched this submission, so "return to vendor"
        // makes no sense here. approve_pq.php already hides this option for
        // these vendors; this is the server-side backstop.
        if (!empty($data['registered_by_sourcing_id'])) {
            die("<script>alert('ERROR: This submission was filled by Sourcing via Direct Registration — it can only be returned to Sourcing, not the vendor.'); window.history.back();</script>");
        }

        // VM returns the application directly to the Vendor for revision.
        // The message is stored in notes_to_vendor as "HH.mm - Pesan ; nama VM"
        // (the "; nama VM" suffix is hidden from the vendor when displayed).
        $message = trim(str_replace(["\r\n", "\r"], "\n", $_POST['notes_to_vendor'] ?? ''));
        if ($message === '') {
            die("<script>alert('ERROR: A note for the vendor is required.'); window.history.back();</script>");
        }

        $vm_name = $_SESSION['user_name'] ?? '';
        // Overwrite the vendor-facing note with the latest message only (no trail).
        // The full history is still preserved internally in vm_notes below.
        $notes_to_vendor = date('d F Y H.i') . ' - ' . $message . ' ; ' . $vm_name;

        // Also record this message in the VM notes trail (internal history), reusing the
        // $note_header + $existing_notes already computed at the top of this handler so the
        // format stays consistent with the other VM actions.
        $vm_note_body = 'Returned to Vendor: ' . $message;
        $vm_notes = !empty($existing_notes)
            ? ($note_header . "\n" . $vm_note_body . "\n---\n" . $existing_notes)
            : ($note_header . "\n" . $vm_note_body);

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE pq_submissions SET status='revision_needed', notes_to_vendor=?, vm_notes=? WHERE id=?");
            $stmt->bind_param("ssi", $notes_to_vendor, $vm_notes, $sub_id);
            $stmt->execute();

            if (!$conn->query("UPDATE vendors SET registration_status='revision_requested' WHERE id='$vendor_id'")) {
                throw new Exception("Vendor status update failed: " . $conn->error);
            }

            // PQ SLA: close the current leg (application leaves the VM queue)
            require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';
            closePqSlaLeg($conn, $sub_id, $_SESSION['user_id'], 'returned_to_vendor');

            $conn->commit();

            // Email the vendor the revision note, and inform the Sourcing reviewer
            // (if any) that their vendor was returned to revision by VM.
            // $message holds the clean, vendor-facing note built above.
            require_once dirname(__DIR__, 2) . '/includes/notification_helper.php';
            notifyOnVendorRevision(
                (int)$_SESSION['user_id'],
                (int)$vendor_id,
                $company_name,
                $message,
                'vm',
                (int)($data['sourcing_reviewer_id'] ?? 0)
            );

            render_action_result(
                'Returned to Vendor',
                'The application has been sent back to the vendor for revision.',
                'dashboard.php',
                'info'
            );

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

            if (!$conn->query("UPDATE vendors SET registration_status='under_review_vm' WHERE id='$vendor_id'")) {
                throw new Exception("Vendor status update failed: " . $conn->error);
            }

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
            if (!$conn->query("UPDATE vendors SET registration_status='rejected' WHERE id='$vendor_id'")) {
                throw new Exception("Vendor status update failed: " . $conn->error);
            }

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