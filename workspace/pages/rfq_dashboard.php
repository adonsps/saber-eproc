<?php
// pages/rfq_dashboard.php
require_once '../includes/auth.php';
require_login(); 
require_once '../config/database.php';
require_once '../includes/fonnte.php';
require_once '../includes/mailer.php';

function get_download_path($filename) {
    if (empty($filename)) return '';

    $fs_prod_base = realpath(__DIR__ . '/../../..'); // public_html
    $fs_qa_base = realpath(__DIR__ . '/../..');      // vms (if QA)

    // Derive the URL prefix for each candidate base from the actual request
    // path (SCRIPT_NAME), instead of assuming a fixed '/vms/'-style prefix —
    // that assumption breaks whenever the app isn't served from that exact
    // subdirectory (e.g. local Docker, where it's served from the domain root).
    $script_dir = dirname($_SERVER['SCRIPT_NAME']); // .../workspace/pages
    $url_qa_base = rtrim(dirname(dirname($script_dir)), '/');
    $url_prod_base = rtrim(dirname(dirname(dirname($script_dir))), '/');

    // Check Production structure first (<url_prod_base>/uploads/rfq_uploads/)
    if ($fs_prod_base && file_exists($fs_prod_base . '/uploads/rfq_uploads/' . $filename)) {
        return $url_prod_base . '/uploads/rfq_uploads/' . $filename;
    }
    // Check QA structure
    if ($fs_qa_base && file_exists($fs_qa_base . '/uploads/rfq_uploads/' . $filename)) {
        return $url_qa_base . '/uploads/rfq_uploads/' . $filename;
    }

    // Fallback to standard workspace structure
    return '../uploads/rfq_uploads/' . $filename;
}

date_default_timezone_set('Asia/Jakarta');
$user_role = $_SESSION['role'] ?? '';

// --- POST/REDIRECT/GET ACTION HANDLER ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'delete_rfq') {
        if ($user_role === 'Category Manager') {
            $pdo->prepare("DELETE FROM rfqs WHERE id = ?")->execute([$_POST['rfq_id']]);
            header("Location: rfq_dashboard.php?success=deleted");
            exit;
        } else {
            die("Unauthorized.");
        }
    }

    if ($_POST['action'] === 'request_edit_deadline' && in_array($user_role, ['Project Design', 'Project Management'])) {
        $new_dt = $_POST['new_deadline'];
        $pdo->prepare("UPDATE rfqs SET pending_deadline = ?, deadline_request_by = ?, deadline_request_status = 'PENDING' WHERE id = ?")->execute([$new_dt, $_SESSION['username'], $_POST['rfq_id']]);
        $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type) VALUES (?, ?, ?)")->execute([$_POST['rfq_id'], $_SESSION['username'], "REQUEST_CHANGE_DEADLINE|$new_dt"]);
        header("Location: rfq_dashboard.php?id=" . $_POST['rfq_id'] . "&msg=deadline_requested");
        exit;
    }

    if ($_POST['action'] === 'approve_deadline' && in_array($user_role, ['Project Design', 'Project Management'])) {
        $stmt_rfq = $pdo->prepare("SELECT pending_deadline FROM rfqs WHERE id = ?");
        $stmt_rfq->execute([$_POST['rfq_id']]);
        $pd = $stmt_rfq->fetchColumn();

        $pdo->prepare("UPDATE rfqs SET deadline = ?, pending_deadline = NULL, deadline_request_by = NULL, deadline_request_status = 'APPROVED' WHERE id = ?")->execute([$pd, $_POST['rfq_id']]);
        $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type) VALUES (?, ?, ?)")->execute([$_POST['rfq_id'], $_SESSION['username'], "APPROVE_CHANGE_DEADLINE|$pd"]);
        header("Location: rfq_dashboard.php?id=" . $_POST['rfq_id'] . "&msg=deadline_approved");
        exit;
    }

    if ($_POST['action'] === 'reject_deadline' && in_array($user_role, ['Project Design', 'Project Management'])) {
        $pdo->prepare("UPDATE rfqs SET deadline_request_status = 'REJECTED' WHERE id = ?")->execute([$_POST['rfq_id']]);
        $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type) VALUES (?, ?, ?)")->execute([$_POST['rfq_id'], $_SESSION['username'], "REJECT_CHANGE_DEADLINE"]);
        header("Location: rfq_dashboard.php?id=" . $_POST['rfq_id'] . "&msg=deadline_rejected");
        exit;
    }
    
    if ($_POST['action'] === 'log_download') {
        $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id) VALUES (?, ?, ?, ?)")->execute([$_POST['rfq_id'], $_SESSION['username'], 'DOWNLOAD_' . $_POST['doc_type'], $_POST['vendor_id']]);
        echo json_encode(['status' => 'logged']);
        exit;
    }

    if ($_POST['action'] === 'close_tender') {
        $checkOwner = $pdo->prepare("SELECT created_by FROM rfqs WHERE id = ?");
        $checkOwner->execute([$_POST['rfq_id']]);
        $creator = $checkOwner->fetchColumn();
        
        // Allow Creator OR PIC/Manager roles
        if ($_SESSION['username'] === $creator || in_array($user_role, ['Category Manager', 'PIC'])) {
            $final_price = str_replace(',', '', $_POST['final_price']);
            $pdo->prepare("UPDATE rfqs SET status = 'Closed', winner_vendor_id = ?, final_price = ?, closed_by = ?, closed_at = NOW() WHERE id = ?")->execute([$_POST['vendor_id'], $final_price, $_SESSION['username'], $_POST['rfq_id']]);
            $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id) VALUES (?, ?, 'CLOSED_TENDER_AND_SET_PRICE', ?)")->execute([$_POST['rfq_id'], $_SESSION['username'], $_POST['vendor_id']]);
            // 3. --- NOTIFIKASI KE QS (Data diambil dari DB Truth) ---
            // Ambil Nama Tender dan Vendor Pemenang melalui join tabel
            $stmt_info = $pdo->prepare("
                SELECT r.package_name, v.vendor_name 
                FROM rfqs r 
                JOIN rfq_vendors v ON r.winner_vendor_id = v.id 
                WHERE r.id = ?
            ");
            $stmt_info->execute([$_POST['rfq_id']]);
            $info = $stmt_info->fetch(PDO::FETCH_ASSOC);

            if ($info) {
                $pkg_name = $info['package_name'];
                $winner_name = $info['vendor_name'];

                $stmt_qs = $pdo->query("SELECT phone_number, email, name FROM users WHERE role = 'QS'");
                $qs_list = $stmt_qs->fetchAll(PDO::FETCH_ASSOC);

                foreach ($qs_list as $qs) {
                    $msg = "Notifikasi Tender Ditutup:\n\nTender: *{$pkg_name}*\nPemenang: *{$winner_name}*\nDitutup oleh: *{$_SESSION['name']}*";
                    
                    if (!empty($qs['phone_number'])) {
                        sendFonnteWA($qs['phone_number'], $msg);
                    }
                    if (!empty($qs['email'])) {
                        sendTenderEmail($qs['email'], $qs['name'], "Tender Awarded: {$pkg_name} // Winner Vendor: {$winner_name}", $msg);
                    }
                }
                
                // --- 4. NOTIFIKASI KE PEMENANG & VENDOR KALAH ---
                $rfq_id_clean = (int)$_POST['rfq_id'];
                $winner_id = (int)$_POST['vendor_id'];
                
                $clean_uri = strtok($_SERVER['REQUEST_URI'], '?'); 
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
                $base_url = $protocol . $_SERVER['HTTP_HOST'] . str_replace('rfq_dashboard.php', 'rfq_vendor.php', $clean_uri) . "?id=" . $rfq_id_clean;
                
                $stmt_vendors = $pdo->prepare("SELECT id, vendor_name, vendor_email, vendor_wa, secret_password FROM rfq_vendors WHERE rfq_id = ?");
                $stmt_vendors->execute([$rfq_id_clean]);
                $vendors = $stmt_vendors->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($vendors as $v) {
                    if ($v['id'] == $winner_id) {
                        // Winner
                        $msg = "Selamat!\n\nPerusahaan Anda (*{$v['vendor_name']}*) telah terpilih sebagai pemenang untuk tender: *{$pkg_name}*.\n\nMohon segera unggah dokumen Final (Cover Letter Final, BoQ Final, Dok Pendukung Final) melalui tautan berikut:\n\nLink: {$base_url}\nPassword: {$v['secret_password']}\n\nTerima kasih.";
                        $subj = "Pemenang Tender: {$pkg_name}";
                    } else {
                        // Loser
                        $msg = "Terima kasih atas partisipasi Anda dalam tender: *{$pkg_name}*.\n\nDengan berat hati kami informasikan bahwa penawaran dari perusahaan Anda belum berhasil kali ini. Tender ini telah dimenangkan oleh *{$winner_name}*.\n\nKami sangat menghargai waktu dan usaha Anda. Sampai jumpa pada kesempatan berikutnya.";
                        $subj = "Hasil Tender: {$pkg_name}";
                    }
                    
                    if (!empty($v['vendor_wa'])) {
                        sendFonnteWA($v['vendor_wa'], $msg);
                    }
                    if (!empty($v['vendor_email'])) {
                        sendTenderEmail($v['vendor_email'], $v['vendor_name'], $subj, $msg);
                    }
                }
            }
            header("Location: rfq_dashboard.php?id=" . $_POST['rfq_id'] . "&msg=closed");
            exit;
        }
    }

    if ($_POST['action'] === 'lock_negotiation' && $user_role === 'QS') {
        $rfq_id = $_POST['rfq_id'];
        $pdo->prepare("UPDATE rfqs SET status = 'Locked' WHERE id = ?")->execute([$rfq_id]);
        $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type) VALUES (?, ?, 'LOCKED')")->execute([$rfq_id, $_SESSION['username']]);
        
        $stmt_creator = $pdo->prepare("SELECT u.phone_number, u.name, r.package_name FROM rfqs r JOIN users u ON r.created_by = u.username WHERE r.id = ?");
        $stmt_creator->execute([$rfq_id]);
        $creator = $stmt_creator->fetch();

        if ($creator) {
            $msg = "Halo {$creator['name']},\n\nTender *{$creator['package_name']}* telah di-Lock untuk Penutupan Tender oleh {$_SESSION['name']} ({$user_role}).\n\nSilakan cek sistem untuk melakukan Negosiasi Final dan Penentuan Pemenang.";
            
            if (!empty($creator['phone_number'])) {
                sendFonnteWA($creator['phone_number'], $msg);
            }
            if (!empty($creator['email'])) {
                sendTenderEmail($creator['email'], $creator['name'], "Action Required: Tender Locked for Final Negotiation and Approval", $msg);
            }
        }
        
        header("Location: rfq_dashboard.php?id=" . $rfq_id . "&msg=locked");
        exit;
    }

    if ($_POST['action'] === 'add_round') {
        $old_id = $_POST['rfq_id'];
        $invited_vendors = $_POST['invited_vendors'] ?? [];
        if (empty($invited_vendors)) { header("Location: rfq_dashboard.php?id=$old_id&error=no_vendors"); exit; }

        $old = $pdo->prepare("SELECT * FROM rfqs WHERE id = ?"); $old->execute([$old_id]); $o = $old->fetch();
        $parent_id = $o['parent_id'] ? $o['parent_id'] : $old_id;
        $new_round = $o['round_number'] + 1;
        $base_name = preg_replace('/ \(Putaran \d+\)$/', '', $o['package_name']);

        // Check if there is a committee for the old RFQ
        $stmt_comm_check = $pdo->prepare("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = ?");
        $stmt_comm_check->execute([$old_id]);
        $has_committee = ($stmt_comm_check->fetchColumn() > 0);
        $status = ($o['rfq_type'] === 'Standard' && !$has_committee) ? 'Unsealed' : 'Active';

        // Preserve original creator, set rfq_type and status
        $ins = $pdo->prepare("INSERT INTO rfqs (package_name, deadline, created_by, parent_id, round_number, rfq_type, status) VALUES (?, DATE_ADD(NOW(), INTERVAL 7 DAY), ?, ?, ?, ?, ?)");
        $ins->execute([$base_name . " (Putaran $new_round)", $o['created_by'], $parent_id, $new_round, $o['rfq_type'], $status]);
        $new_id = $pdo->lastInsertId();
        
        $pdo->prepare("INSERT INTO rfq_committee (rfq_id, username) SELECT ?, username FROM rfq_committee WHERE rfq_id = ?")->execute([$new_id, $old_id]);
        
        $placeholders = implode(',', array_fill(0, count($invited_vendors), '?'));
        $v_old = $pdo->prepare("SELECT * FROM rfq_vendors WHERE rfq_id = ? AND id IN ($placeholders)");
        $v_old->execute(array_merge([$old_id], $invited_vendors));
        
        $ins_v = $pdo->prepare("INSERT INTO rfq_vendors (rfq_id, vendor_name, vendor_email, vendor_wa, vendor_pic, secret_password) VALUES (?, ?, ?, ?, ?, ?)");
        $clean_uri = strtok($_SERVER['REQUEST_URI'], '?'); 
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $base_url = $protocol . $_SERVER['HTTP_HOST'] . str_replace('rfq_dashboard.php', 'rfq_vendor.php', $clean_uri) . "?id=" . $new_id;
        
        foreach($v_old->fetchAll() as $v) {
            $sec = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
            $ins_v->execute([$new_id, $v['vendor_name'], $v['vendor_email'], $v['vendor_wa'], $v['vendor_pic'], $sec]);
            
            $msg = "Halo {$v['vendor_name']},\n\nKami telah membuka Ronde baru untuk tender: *{$base_name} (Putaran {$new_round})*.\n\nLink Submit: {$base_url}\n*Password Rahasia BARU Anda:* {$sec}";
            
            // Kirim WhatsApp
            sendFonnteWA($v['vendor_wa'], $msg);
            
            // Kirim Email via PHPMailer
            $subject = "Undangan Tender: " . $base_name . " (Putaran {$new_round})";
            sendTenderEmail($v['vendor_email'], $v['vendor_name'], $subject, $msg);
        }
        header("Location: rfq_dashboard.php?id=" . $new_id . "&msg=round_added");
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'invite_vendor') {
    $rfq_id = $_POST['rfq_id'];
    $name = $_POST['vendor_name'];
    $pic = $_POST['vendor_pic'];
    $email = $_POST['vendor_email'];
    $wa = $_POST['vendor_wa'];
    $reason = $_POST['addition_reason'] ?? '';
    
    // Generate secure secret password (8 char)
    $sec = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
    
    $stmt = $pdo->prepare("INSERT INTO rfq_vendors (rfq_id, vendor_name, vendor_email, vendor_pic, vendor_wa, secret_password, addition_status, addition_reason) VALUES (?, ?, ?, ?, ?, ?, 'PENDING', ?)");
    $stmt->execute([$rfq_id, $name, $email, $pic, $wa, $sec, $reason]);
    $new_vendor_id = $pdo->lastInsertId();
    
    // Create pending approval for each committee member
    $stmt_comm = $pdo->prepare("SELECT username FROM rfq_committee WHERE rfq_id = ?");
    $stmt_comm->execute([$rfq_id]);
    $committee_members = $stmt_comm->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($committee_members) > 0) {
        $ins_app = $pdo->prepare("INSERT INTO rfq_vendor_approvals (rfq_vendor_id, committee_username, status) VALUES (?, ?, 'PENDING')");
        foreach ($committee_members as $c_user) {
            $ins_app->execute([$new_vendor_id, $c_user]);
        }
    } else {
        // If there's no committee (e.g., standard RFQ without committee?), auto-approve
        $pdo->prepare("UPDATE rfq_vendors SET addition_status = 'APPROVED' WHERE id = ?")->execute([$new_vendor_id]);
        
        // Notify immediately if auto-approved
        $pkg = $pdo->prepare("SELECT package_name, round_number FROM rfqs WHERE id = ?");
        $pkg->execute([$rfq_id]);
        $p = $pkg->fetch();
        
        $clean_uri = strtok($_SERVER['REQUEST_URI'], '?'); 
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $base_url = $protocol . $_SERVER['HTTP_HOST'] . str_replace('rfq_dashboard.php', 'rfq_vendor.php', $clean_uri) . "?id=" . $rfq_id;
        
        $msg = "Halo {$name},\n\nAnda diundang untuk mengikuti tender: *{$p['package_name']} (Putaran {$p['round_number']})*.\n\nLink Submit: {$base_url}\n*Password Rahasia Anda:* {$sec}";
        
        if (!empty($wa)) sendFonnteWA($wa, $msg);
        if (!empty($email)) sendTenderEmail($email, $name, "Undangan Tender Tambahan: " . $p['package_name'], $msg);
    }
    
    // Log
    $log_name = $name; // Log the specific vendor name instead of just the ID
    $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id, description) VALUES (?, ?, 'REQUEST_ADD_VENDOR', ?, ?)")->execute([$rfq_id, $_SESSION['username'], $new_vendor_id, "Requested to add vendor: $log_name. Reason: $reason"]);
    
    header("Location: rfq_dashboard.php?id=" . $rfq_id . "&msg=vendor_requested");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_vendor') {
    $rfq_id = $_POST['rfq_id'];
    $vendor_id = $_POST['vendor_id'];
    $username = $_SESSION['username'];
    
    // Update approval status
    $pdo->prepare("UPDATE rfq_vendor_approvals SET status = 'APPROVED', approved_at = NOW() WHERE rfq_vendor_id = ? AND committee_username = ?")
        ->execute([$vendor_id, $username]);
        
    // Log
    $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id, description) VALUES (?, ?, 'APPROVE_ADD_VENDOR', ?, ?)")
        ->execute([$rfq_id, $username, $vendor_id, "Approved addition of vendor."]);
        
    // Check if ALL committee members have approved
    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM rfq_vendor_approvals WHERE rfq_vendor_id = ? AND status != 'APPROVED'");
    $stmt_check->execute([$vendor_id]);
    $pending_count = $stmt_check->fetchColumn();
    
    if ($pending_count == 0) {
        // Fully approved
        $pdo->prepare("UPDATE rfq_vendors SET addition_status = 'APPROVED' WHERE id = ?")->execute([$vendor_id]);
        
        // Fetch vendor details for email/WA
        $stmt_v = $pdo->prepare("SELECT vendor_name, vendor_email, vendor_wa, secret_password FROM rfq_vendors WHERE id = ?");
        $stmt_v->execute([$vendor_id]);
        $v = $stmt_v->fetch(PDO::FETCH_ASSOC);
        
        // Notify
        $pkg = $pdo->prepare("SELECT package_name, round_number FROM rfqs WHERE id = ?");
        $pkg->execute([$rfq_id]);
        $p = $pkg->fetch();
        
        $clean_uri = strtok($_SERVER['REQUEST_URI'], '?'); 
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $base_url = $protocol . $_SERVER['HTTP_HOST'] . str_replace('rfq_dashboard.php', 'rfq_vendor.php', $clean_uri) . "?id=" . $rfq_id;
        
        $msg = "Halo {$v['vendor_name']},\n\nAnda diundang untuk mengikuti tender: *{$p['package_name']} (Putaran {$p['round_number']})*.\n\nLink Submit: {$base_url}\n*Password Rahasia Anda:* {$v['secret_password']}";
        
        if (!empty($v['vendor_wa'])) sendFonnteWA($v['vendor_wa'], $msg);
        if (!empty($v['vendor_email'])) sendTenderEmail($v['vendor_email'], $v['vendor_name'], "Undangan Tender Tambahan: " . $p['package_name'], $msg);
        
        // Log final invitation
        $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id, description) VALUES (?, ?, 'INVITED_ADDITIONAL', ?, ?)")
            ->execute([$rfq_id, 'System', $vendor_id, "Vendor fully approved and invited."]);
    }
    
    header("Location: rfq_dashboard.php?id=" . $rfq_id . "&msg=vendor_approved");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_vendor') {
    $rfq_id = $_POST['rfq_id'];
    $vendor_id = $_POST['vendor_id'];
    $username = $_SESSION['username'];
    
    // Update approval status to rejected
    $pdo->prepare("UPDATE rfq_vendor_approvals SET status = 'REJECTED', approved_at = NOW() WHERE rfq_vendor_id = ? AND committee_username = ?")
        ->execute([$vendor_id, $username]);
        
    // Update vendor overall status to rejected immediately (if anyone rejects, it's rejected)
    $pdo->prepare("UPDATE rfq_vendors SET addition_status = 'REJECTED' WHERE id = ?")->execute([$vendor_id]);
    
    // Log
    $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id, description) VALUES (?, ?, 'REJECT_ADD_VENDOR', ?, ?)")
        ->execute([$rfq_id, $username, $vendor_id, "Rejected addition of vendor."]);
        
    header("Location: rfq_dashboard.php?id=" . $rfq_id . "&msg=vendor_rejected");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['unseal'])) {
    $rfq_id = $_POST['rfq_id'];
    $pdo->prepare("UPDATE rfq_committee SET has_opened = 1, opened_at = NOW() WHERE rfq_id = ? AND username = ?")->execute([$rfq_id, $_SESSION['username']]);
    $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type) VALUES (?, ?, 'UNSEAL')")->execute([$rfq_id, $_SESSION['username']]);
        
    // 1. INSTANT ELIMINATION: Jika ini adalah orang pertama yang buka segel, langsung eliminasi
    $check_first = $pdo->prepare("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = ? AND has_opened = 1");
    $check_first->execute([$rfq_id]);
    if ($check_first->fetchColumn() == 1) { 
        $stmt_unsub = $pdo->prepare("SELECT id FROM rfq_vendors WHERE rfq_id = ? AND submitted_at IS NULL");
        $stmt_unsub->execute([$rfq_id]);
        foreach($stmt_unsub->fetchAll() as $u) {
            $pdo->prepare("INSERT INTO rfq_audit_logs (rfq_id, username, action_type, target_vendor_id) VALUES (?, ?, 'ELIMINATED', ?)")->execute([$rfq_id, 'Sistem (' . $_SESSION['username'] . ')', $u['id']]);
        }
    }

    // 2. STATUS UNSEALED: Hanya ubah status setelah semua komite buka segel
    $check_all = $pdo->prepare("SELECT COUNT(*) FROM rfq_committee WHERE rfq_id = ? AND has_opened = 0");
    $check_all->execute([$rfq_id]);
    $all_opened = ($check_all->fetchColumn() == 0);
    
    if ($all_opened) {
        $pdo->prepare("UPDATE rfqs SET status = 'Unsealed' WHERE id = ?")->execute([$rfq_id]);
    }
    
    // Notifications
    require_once '../includes/rfq_notification_helper.php';
    notify_seal_opened($pdo, $rfq_id, $_SESSION['username']);
    if ($all_opened) {
        notify_seal_completely_opened($pdo, $rfq_id);
    }
    
    header("Location: rfq_dashboard.php?id=" . $rfq_id);
    exit;
}

require_once '../includes/header.php';

// ==========================================
// VIEW 1: DETAIL VIEW (UNSEALING & MATRIX)
// ==========================================
if (isset($_GET['id'])) {
    $rfq_id = $_GET['id'];
    $rfq = $pdo->prepare("SELECT * FROM rfqs WHERE id = ?");
    $rfq->execute([$rfq_id]);
    $rfq_data = $rfq->fetch();

    if (!$rfq_data) {
        echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold'>Tender tidak ditemukan.</h2><a href='rfq_dashboard.php' class='text-indigo-600 underline mt-2 block'>Kembali</a></div>";
        require_once '../includes/footer.php'; exit;
    }

    $family_group = $rfq_data['parent_id'] ?: $rfq_data['id'];
    $stmt_family = $pdo->prepare("SELECT id, round_number, status, package_name FROM rfqs WHERE id = ? OR parent_id = ? ORDER BY round_number ASC");
    $stmt_family->execute([$family_group, $family_group]);
    $rfq_family = $stmt_family->fetchAll(PDO::FETCH_ASSOC);

    // Identify if this is the absolute latest round in the family
    $max_round = max(array_column($rfq_family, 'round_number'));
    $is_latest_round = ($rfq_data['round_number'] == $max_round);

    $prev_prices = [];
    if ($rfq_data['round_number'] > 1) {
        $prev_round = $rfq_data['round_number'] - 1;
        $stmt_prev = $pdo->prepare("SELECT v.vendor_name, v.total_price FROM rfq_vendors v JOIN rfqs r ON v.rfq_id = r.id WHERE (r.id = ? OR r.parent_id = ?) AND r.round_number = ? AND v.total_price IS NOT NULL");
        $stmt_prev->execute([$family_group, $family_group, $prev_round]);
        foreach($stmt_prev->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $prev_prices[$p['vendor_name']] = $p['total_price'];
        }
    }

    $vendors = $pdo->prepare("SELECT * FROM rfq_vendors WHERE rfq_id = ? ORDER BY CASE WHEN submitted_at IS NULL THEN 1 ELSE 0 END ASC, total_price ASC");
    $vendors->execute([$rfq_id]);
    $vendor_data = $vendors->fetchAll(PDO::FETCH_ASSOC);

    $total_submissions = 0;
    $vendor_map = [];
    foreach ($vendor_data as $v) {
        if (!empty($v['submitted_at'])) $total_submissions++;
        $vendor_map[$v['id']] = $v['vendor_name'];
    }

    $vendor_items = [];
    if (($rfq_data['rfq_type'] ?? 'Construction') === 'Standard') {
        $stmt_vi = $pdo->prepare("SELECT vi.*, ri.ticket_line_item, ri.item_description, ri.qty, ri.uom 
                                  FROM rfq_vendor_items vi 
                                  JOIN rfq_items ri ON vi.rfq_item_id = ri.id 
                                  WHERE vi.rfq_vendor_id = ?");
        foreach($vendor_data as $v) {
            $stmt_vi->execute([$v['id']]);
            $vendor_items[$v['id']] = $stmt_vi->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    $committee = $pdo->prepare("SELECT c.username, c.has_opened, c.opened_at, u.name FROM rfq_committee c JOIN users u ON c.username = u.username WHERE c.rfq_id = ?");
    $committee->execute([$rfq_id]);
    $committee_data = $committee->fetchAll(PDO::FETCH_ASSOC);

    $is_committee = false;
    $my_unseal_status = 0;
    foreach($committee_data as $c) {
        if ($c['username'] === $_SESSION['username']) {
            $is_committee = true;
            $my_unseal_status = $c['has_opened'];
        }
    }
    
    $logs = $pdo->prepare("SELECT * FROM rfq_audit_logs WHERE rfq_id = ? ORDER BY created_at DESC");
    $logs->execute([$rfq_id]);
    $audit_logs = $logs->fetchAll(PDO::FETCH_ASSOC);

    $user_names = $pdo->query("SELECT username, name FROM users")->fetchAll(PDO::FETCH_KEY_PAIR);
    function display_log_user($username, $user_names) {
        if (preg_match('/^Sistem \(([^)]+)\)$/', $username, $m)) {
            return 'Sistem (' . ($user_names[$m[1]] ?? $m[1]) . ')';
        }
        return $user_names[$username] ?? $username;
    }

    function getCheck($val) { return !empty($val) ? '<span class="text-green-500 font-bold text-lg">✅</span>' : '<span class="text-red-400 font-bold text-lg">❌</span>'; }
    ?>
    <div class="max-w-7xl mx-auto py-8">
        <div class="mb-4">
            <a href="rfq_dashboard.php" class="inline-flex items-center text-sm font-bold text-gray-500 hover:text-gray-700 transition">&larr; Kembali ke Daftar RFQ</a>
        </div>

        <?php if (count($rfq_family) > 1): ?>
        <div class="flex flex-wrap gap-2 mb-6 border-b border-gray-300 pb-2">
            <?php foreach($rfq_family as $fam): 
                $isActive = ($fam['id'] == $rfq_id);
                $tabClass = $isActive ? 'bg-indigo-600 text-white shadow-md' : 'bg-gray-200 text-gray-600 hover:bg-gray-300 hover:text-gray-800';
            ?>
                <a href="rfq_dashboard.php?id=<?= $fam['id'] ?>" class="px-5 py-2 rounded-t-lg font-bold text-sm transition-all <?= $tabClass ?>">
                    Ronde <?= $fam['round_number'] ?> 
                    <?php if($fam['status'] === 'Closed') echo '🔒'; elseif($fam['status'] === 'Locked') echo '⛔'; elseif($fam['status'] === 'Unsealed') echo '🔓'; else echo '⏳'; ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <?php if(isset($_GET['msg'])): ?>
            <?php if($_GET['msg'] === 'round_added'): ?><div class="bg-blue-100 text-blue-800 p-4 mb-4 rounded font-bold">Ronde Baru Berhasil Dibuat! Undangan dikirim ke vendor terpilih.</div>
            <?php elseif($_GET['msg'] === 'deadline_updated'): ?><div class="bg-green-100 text-green-800 p-4 mb-4 rounded font-bold">Batas Waktu berhasil diubah.</div>
            <?php elseif($_GET['msg'] === 'deadline_requested'): ?><div class="bg-yellow-100 text-yellow-800 p-4 mb-4 rounded font-bold">Permintaan perubahan deadline terkirim.</div>
            <?php elseif($_GET['msg'] === 'deadline_approved'): ?><div class="bg-green-100 text-green-800 p-4 mb-4 rounded font-bold">Perubahan deadline disetujui.</div>
            <?php elseif($_GET['msg'] === 'deadline_rejected'): ?><div class="bg-red-100 text-red-800 p-4 mb-4 rounded font-bold">Perubahan deadline ditolak.</div>
            <?php elseif($_GET['msg'] === 'locked'): ?><div class="bg-purple-100 text-purple-800 p-4 mb-4 rounded font-bold">Tender dikunci untuk Penutupan Tender. Notifikasi telah dikirim ke PIC.</div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if(isset($_GET['error']) && $_GET['error'] === 'no_vendors'): ?>
            <div class="bg-red-100 text-red-800 p-4 mb-4 rounded font-bold">Gagal: Anda harus memilih minimal 1 vendor untuk ronde selanjutnya.</div>
        <?php endif; ?>

        <?php if (($rfq_data['deadline_request_status'] ?? '') === 'PENDING'): ?>
            <?php if ($_SESSION['username'] === $rfq_data['deadline_request_by']): ?>
                <div class="bg-yellow-100 text-yellow-800 p-4 mb-4 rounded font-bold border border-yellow-200 flex items-center justify-between">
                    <span>⏳ Menunggu Persetujuan Perubahan Deadline ke <?= date('d M Y H:i', strtotime($rfq_data['pending_deadline'])) ?></span>
                </div>
            <?php elseif (in_array($user_role, ['Project Design', 'Project Management'])): ?>
                <div class="bg-yellow-100 text-yellow-800 p-4 mb-4 rounded font-bold border border-yellow-300 flex items-center justify-between">
                    <span>⚠️ Permintaan Perubahan Deadline ke <?= date('d M Y H:i', strtotime($rfq_data['pending_deadline'])) ?> oleh <?= htmlspecialchars($rfq_data['deadline_request_by']) ?></span>
                    <div class="flex gap-2">
                        <form method="POST"><input type="hidden" name="action" value="approve_deadline"><input type="hidden" name="rfq_id" value="<?= $rfq_id ?>"><button class="bg-green-600 text-white px-3 py-1 rounded text-sm hover:bg-green-700">Setujui</button></form>
                        <form method="POST"><input type="hidden" name="action" value="reject_deadline"><input type="hidden" name="rfq_id" value="<?= $rfq_id ?>"><button class="bg-red-600 text-white px-3 py-1 rounded text-sm hover:bg-red-700">Tolak</button></form>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="flex flex-col md:flex-row justify-between items-start mb-6 border-b pb-4 gap-3">
            <div class="min-w-0">
                <h2 class="text-3xl font-bold text-gray-800">Tender: <?= htmlspecialchars($rfq_data['package_name']) ?></h2>
                <p class="text-gray-500 mt-1 flex items-center gap-2">
                    Batas Waktu: <?= date('d F Y H:i', strtotime($rfq_data['deadline'])) ?> WIB
                    <?php if (in_array($user_role, ['Project Design', 'Project Management']) && $rfq_data['status'] === 'Active' && ($rfq_data['deadline_request_status'] ?? '') !== 'PENDING'): ?>
                        <button onclick="document.getElementById('deadlineModal').classList.remove('hidden')" class="text-xs bg-gray-200 hover:bg-gray-300 px-2 py-1 rounded text-gray-700 font-bold">✏️ Edit</button>
                    <?php endif; ?>
                </p>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <?php if ($rfq_data['status'] === 'Active'): ?>
                    <span class="bg-red-100 text-red-800 font-bold text-sm px-4 py-2 rounded-full border border-red-200 inline-flex items-center gap-2 whitespace-nowrap">TENDER DISEGEL</span>
                <?php elseif ($rfq_data['status'] === 'Unsealed'): ?>
                    <span class="bg-green-100 text-green-800 font-bold text-sm px-4 py-2 rounded-full border border-green-200 inline-flex items-center gap-2 whitespace-nowrap">TENDER DIBUKA</span>
                <?php elseif ($rfq_data['status'] === 'Locked'): ?>
                    <span class="bg-purple-100 text-purple-800 font-bold text-sm px-4 py-2 rounded-full border border-purple-200 inline-flex items-center gap-2 whitespace-nowrap">PENUTUPAN TENDER</span>
                <?php elseif ($rfq_data['status'] === 'Closed'): ?>
                    <span class="bg-gray-200 text-gray-800 font-bold text-sm px-4 py-2 rounded-full border border-gray-300 inline-flex items-center gap-2 whitespace-nowrap">TENDER DITUTUP</span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($rfq_data['status'] === 'Active'): ?>
        <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200 mb-8 border-t-4 border-t-blue-500">
            <div class="flex justify-between items-center mb-1">
                <h3 class="text-lg font-bold text-gray-800">Status Submit Vendor</h3>
                <?php if ($_SESSION['username'] === $rfq_data['created_by']): ?>
                    <button type="button" onclick="document.getElementById('inviteVendorModal').classList.remove('hidden')" class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-1 px-3 text-sm rounded shadow transition">+ Undang Vendor Tambahan</button>
                <?php endif; ?>
            </div>
            <div class="overflow-x-auto mt-4">
                <table class="min-w-full text-left text-sm border-collapse">
                    <thead class="bg-gray-100 border-b-2 border-gray-300 text-gray-600">
                        <tr>
                            <th class="p-3 font-bold uppercase text-center">Vendor</th>
                            <th class="p-3 font-bold uppercase text-center">Harga Lumpsum</th>
                            <th class="p-3 font-bold uppercase text-center">Price Validity</th>
                            <th class="p-3 font-bold uppercase text-center">Cover Letter</th>
                            <th class="p-3 font-bold uppercase text-center">BoQ (Excel)</th>
                            <th class="p-3 font-bold uppercase text-center">Dok Pendukung</th>
                            <th class="p-3 font-bold uppercase text-center">Waktu Submit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($vendor_data as $v): ?>
                            <?php if (($v['addition_status'] ?? 'APPROVED') === 'PENDING'): ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-3"><div class="font-bold text-indigo-700"><?= htmlspecialchars($v['vendor_name'] ?? '') ?></div><div class="text-xs text-gray-500">PIC: <?= htmlspecialchars($v['vendor_pic'] ?? '') ?></div></td>
                                    <td colspan="5" class="p-3 text-center bg-yellow-50 text-yellow-800 border-x border-white">
                                        Menunggu Persetujuan Komite<br>
                                        <span class="text-xs text-gray-600 font-normal">Alasan: <?= htmlspecialchars($v['addition_reason'] ?? '') ?></span>
                                    </td>
                                    <td class="p-3 text-center">
                                        <?php if ($is_committee): ?>
                                            <?php
                                                $my_app = $pdo->prepare("SELECT status FROM rfq_vendor_approvals WHERE rfq_vendor_id = ? AND committee_username = ?");
                                                $my_app->execute([$v['id'], $_SESSION['username']]);
                                                $my_status = $my_app->fetchColumn();
                                            ?>
                                            <?php if ($my_status === 'PENDING'): ?>
                                                <div class="flex gap-1 justify-center">
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="approve_vendor">
                                                        <input type="hidden" name="vendor_id" value="<?= $v['id'] ?>">
                                                        <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                                                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-2 py-1 text-xs rounded font-bold shadow">Approve</button>
                                                    </form>
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="reject_vendor">
                                                        <input type="hidden" name="vendor_id" value="<?= $v['id'] ?>">
                                                        <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                                                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-2 py-1 text-xs rounded font-bold shadow">Reject</button>
                                                    </form>
                                                </div>
                                            <?php elseif ($my_status === 'REJECTED'): ?>
                                                <span class="text-xs font-bold text-red-600">Rejected</span>
                                            <?php else: ?>
                                                <span class="text-xs font-bold text-green-600">Approved</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-xs font-bold text-yellow-600">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php elseif (($v['addition_status'] ?? 'APPROVED') === 'REJECTED'): ?>
                                <tr class="border-b bg-red-50">
                                    <td class="p-3"><div class="font-bold text-gray-500 line-through"><?= htmlspecialchars($v['vendor_name'] ?? '') ?></div></td>
                                    <td colspan="6" class="p-3 text-center text-red-600 font-bold">Ditolak oleh Komite</td>
                                </tr>
                            <?php else: ?>
                                <tr class="border-b hover:bg-gray-50">
                                    <td class="p-3"><div class="font-bold text-indigo-700"><?= htmlspecialchars($v['vendor_name'] ?? '') ?></div><div class="text-xs text-gray-500">PIC: <?= htmlspecialchars($v['vendor_pic'] ?? '') ?></div></td>
                                    <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['total_price']) ?></td>
                                    <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['price_validity']) ?></td>
                                    <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['file_cover_letter']) ?></td>
                                    <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['file_boq']) ?></td>
                                    <td class="p-3 text-center bg-gray-50 border-x border-white"><?= getCheck($v['file_supporting']) ?></td>
                                    <td class="p-3 text-center font-mono text-xs">
                                        <?php if (!empty($v['submitted_at'])): ?>
                                            <?php $dt = new DateTime($v['submitted_at'], new DateTimeZone('UTC')); $dt->setTimezone(new DateTimeZone('Asia/Jakarta')); echo $dt->format('d F Y H:i'); ?>
                                        <?php else: ?>
                                            <span class="text-red-500 font-bold">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (count($committee_data) > 0): ?>
        <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200 mb-8">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">Status Unseal Komite</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <?php foreach($committee_data as $c): ?>
                    <div class="p-3 rounded border <?= $c['has_opened'] ? 'bg-green-50 border-green-200' : 'bg-gray-50 border-gray-200' ?>">
                        <div class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($c['name'] ?? $c['username']) ?></div>
                        <?php if($c['has_opened']): ?>
                            <?php $cd = new DateTime($c['opened_at'], new DateTimeZone('UTC')); $cd->setTimezone(new DateTimeZone('Asia/Jakarta')); ?>
                            <div class="text-xs text-green-600 font-bold mt-1">Unlocked at <?= $cd->format('H:i, d M Y') ?></div>
                        <?php else: ?>
                            <div class="text-xs text-red-500 font-bold mt-1">Menunggu Unlock...</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($is_committee && !$my_unseal_status && $rfq_data['status'] === 'Active'): ?>
                <?php if ($total_submissions > 0): ?>
                    <?php 
                        $unsub_count = count($vendor_data) - $total_submissions;
                        $confirm_msg = "Tindakan ini akan menyimpan tanda tangan digital Anda untuk membuka segel tender.";
                        if ($unsub_count > 0) $confirm_msg = "PERINGATAN: Ada $unsub_count vendor yang BELUM submit. Jika segel terbuka, mereka otomatis DIELIMINASI oleh sistem. Lanjutkan?";
                    ?>
                    <form id="unsealForm" method="POST" onsubmit="event.preventDefault(); document.getElementById('unsealConfirmMessage').textContent = <?= json_encode($confirm_msg) ?>; document.getElementById('unsealConfirmModal').classList.remove('hidden');">
                        <input type="hidden" name="unseal" value="1">
                        <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                        <button type="submit" id="unsealSubmitBtn" class="w-full bg-red-600 hover:bg-red-700 text-white font-bold py-3 rounded shadow transition flex items-center justify-center gap-2 text-lg">Buka Segel Tender</button>
                    </form>

                    <!-- Unseal Confirmation Modal -->
                    <div id="unsealConfirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                        <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-md p-6">
                            <h3 class="text-lg font-bold text-gray-800 mb-2">Buka Segel Tender</h3>
                            <p id="unsealConfirmMessage" class="text-sm text-gray-700 mb-6"></p>
                            <div class="flex justify-end gap-2">
                                <button type="button" onclick="document.getElementById('unsealConfirmModal').classList.add('hidden')" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded text-sm">Batal</button>
                                <button type="button" onclick="confirmUnseal()" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded text-sm shadow">Ya, Buka Segel</button>
                            </div>
                        </div>
                    </div>
                    <script>
                    function confirmUnseal() {
                        document.getElementById('unsealConfirmModal').classList.add('hidden');
                        const btn = document.getElementById('unsealSubmitBtn');
                        btn.disabled = true;
                        btn.innerHTML = '<svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg> Membuka Segel...';
                        document.getElementById('unsealForm').submit();
                    }
                    </script>
                <?php else: ?>
                    <button disabled class="w-full bg-gray-400 text-white font-bold py-3 rounded shadow cursor-not-allowed text-lg">Belum Ada Penawaran Masuk</button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if ($is_latest_round && in_array($rfq_data['status'], ['Unsealed', 'Locked'])): ?>
            <div class="flex justify-end mb-4 gap-4">
                <?php if ($user_role === 'QS' && $rfq_data['status'] !== 'Locked'): ?>
                    <form id="lockTenderForm" method="POST" onsubmit="event.preventDefault(); document.getElementById('lockTenderConfirmModal').classList.remove('hidden');">
                        <input type="hidden" name="action" value="lock_negotiation">
                        <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-bold py-2 px-6 rounded shadow transition">🔒 Penutupan Tender</button>
                    </form>
                <?php endif; ?>

                <?php if ($user_role === 'QS'): ?>
                    <button type="button" onclick="document.getElementById('addRoundModal').classList.remove('hidden')" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow transition">+ Tambah Ronde</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (in_array($rfq_data['status'], ['Unsealed', 'Locked', 'Closed'])): ?>
            <h3 class="text-2xl font-bold text-gray-800 mb-4 flex items-center gap-2">📊 Matriks Perbandingan Vendor</h3>
            <div class="overflow-x-auto pb-4 mb-8">
                <div class="flex gap-4 min-w-max p-2">
                    <?php 
                    $rank = 1;
                    foreach($vendor_data as $v): 
                        $has_submitted = !empty($v['submitted_at']);
                        $current_val = $v['total_price'];
                        $lumpsum = $current_val ? number_format($current_val, 0, ',', '.') : 'N/A';
                        $is_winner = ($rfq_data['status'] === 'Closed' && $rfq_data['winner_vendor_id'] == $v['id']);
                        $card_style = $is_winner ? 'border-yellow-400 ring-4 ring-yellow-400 bg-yellow-50' : 'border-gray-200 bg-white';
                        
                        $prev_val = $prev_prices[$v['vendor_name']] ?? null;
                        $discount_badge = '';
                        if ($prev_val && $current_val && $current_val < $prev_val) {
                            $diff = $prev_val - $current_val;
                            $pct = round(($diff / $prev_val) * 100, 2);
                            $discount_badge = "<div class='text-xs font-bold text-emerald-600 bg-emerald-100 px-2 py-1 rounded inline-block mt-1'>📉 Turun Rp " . number_format($diff, 0, ',', '.') . " ($pct%)</div>";
                        } elseif ($prev_val && $current_val && $current_val > $prev_val) {
                            $discount_badge = "<div class='text-xs font-bold text-red-600 bg-red-100 px-2 py-1 rounded inline-block mt-1'>📈 Naik Rp " . number_format($current_val - $prev_val, 0, ',', '.') . "</div>";
                        }
                    ?>
                    <div class="border <?= $card_style ?> rounded-lg shadow-md w-80 flex-shrink-0 flex flex-col relative overflow-hidden">
                        <?php if($is_winner): ?><div class="bg-yellow-400 text-yellow-900 text-center text-sm font-black py-2 uppercase tracking-widest">🏆 WINNER 🏆</div>
                        <?php elseif($rank === 1 && $has_submitted && $rfq_data['status'] !== 'Closed'): ?><div class="bg-green-500 text-white text-center text-xs font-bold py-1 uppercase tracking-widest">Penawaran Termurah</div><?php endif; ?>

                        <div class="p-4 border-b <?= $is_winner ? 'bg-yellow-100' : 'bg-gray-50' ?> flex-grow">
                            <?php if ($has_submitted): ?>
                                <div class="text-xs text-gray-500 font-bold uppercase mb-1">Peringkat #<?= $rank ?></div>
                            <?php else: ?>
                                <div class="text-xs text-red-500 font-bold uppercase mb-1">Eliminated vendor</div>
                            <?php endif; ?>
                            <h4 class="font-bold text-lg text-indigo-700 mb-1"><?= htmlspecialchars($v['vendor_name'] ?? '') ?></h4>
                            <p class="text-xs text-gray-600">PIC: <?= htmlspecialchars($v['vendor_pic'] ?? '') ?> (<?= htmlspecialchars($v['vendor_wa'] ?? '') ?>)</p>
                        </div>

                        <div class="p-4 flex-grow space-y-4">
                            <?php if($has_submitted): ?>
                                <div>
                                    <?php if($prev_val): ?><div class="text-[10px] text-gray-500 font-bold uppercase line-through mb-1">Harga Ronde Sebelumnya: Rp <?= number_format($prev_val, 0, ',', '.') ?></div><?php endif; ?>
                                    <div class="text-[10px] text-gray-800 font-bold uppercase">Harga Lumpsum (Ronde Ini)</div>
                                    <div class="text-2xl font-bold font-mono text-gray-800">Rp <?= $lumpsum ?></div>
                                    <?= $discount_badge ?>
                                    <?php if($is_winner && $rfq_data['final_price'] != $v['total_price']): ?>
                                        <div class="mt-2 p-2 bg-yellow-200 text-yellow-800 rounded text-sm font-bold border border-yellow-300">Final Negotiated: Rp <?= number_format($rfq_data['final_price'], 0, ',', '.') ?></div>
                                    <?php endif; ?>
                                </div>
                                <div class="grid grid-cols-1 gap-2 border-t pt-2 mt-2">
                                    <div><div class="text-[10px] text-gray-500 font-bold uppercase">Price Validity</div><div class="font-bold text-sm text-gray-800"><?= htmlspecialchars($v['price_validity'] ?? '') ?></div></div>
                                </div>
                                <?php if(($rfq_data['rfq_type'] ?? 'Construction') === 'Standard' && !empty($vendor_items[$v['id']])): ?>
                                    <div class="mt-4 border-t pt-2">
                                        <div class="text-[10px] text-gray-500 font-bold uppercase mb-2">Detail Item</div>
                                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1 custom-scrollbar">
                                            <?php foreach($vendor_items[$v['id']] as $vi): ?>
                                            <div class="bg-white p-2 rounded border border-gray-200 shadow-sm text-xs">
                                                <div class="font-bold text-gray-800"><?= htmlspecialchars($vi['ticket_line_item'] ?? '') ?></div>
                                                <div class="flex justify-between mt-1">
                                                    <span class="text-gray-500">Qty:</span>
                                                    <span class="font-bold"><?= (float)$vi['qty'] ?><?= !empty($vi['uom']) ? ' ' . htmlspecialchars($vi['uom']) : '' ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-500">Harga/Unit:</span>
                                                    <span class="font-bold text-indigo-600">Rp <?= number_format($vi['price'], 0, ',', '.') ?></span>
                                                </div>
                                                <div class="flex justify-between">
                                                    <span class="text-gray-500">Lead Time:</span>
                                                    <span class="font-bold"><?= htmlspecialchars($vi['lead_time'] ?? '') ?></span>
                                                </div>
                                                <?php if(!empty($vi['remarks'])): ?>
                                                    <div class="text-gray-500 italic mt-1 bg-gray-50 p-1 rounded border border-gray-100">"<?= htmlspecialchars($vi['remarks'] ?? '') ?>"</div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="h-full flex items-center justify-center py-8"><span class="text-red-500 font-bold bg-red-50 px-3 py-1 rounded">Belum Submit di Ronde Ini</span></div>
                            <?php endif; ?>
                        </div>

                        <div class="p-4 <?= $is_winner ? 'bg-yellow-100' : 'bg-gray-50' ?> border-t space-y-2 mt-auto">
                            <?php if($has_submitted): ?>
                                <?php if ($user_role !== 'Project Design'): ?>
                                    <?php if(!empty($v['final_cover_letter'])): ?>
                                        <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'COVER', '<?= get_download_path($v['final_cover_letter']) ?>')" class="w-full text-center text-xs font-bold text-indigo-800 border border-indigo-400 bg-indigo-100 hover:bg-indigo-200 py-2 rounded shadow-sm">📄 View Final Cover Letter</button>
                                    <?php else: ?>
                                        <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'COVER', '<?= get_download_path($v['file_cover_letter']) ?>')" class="w-full text-center text-xs font-bold text-indigo-600 border border-indigo-200 bg-white hover:bg-indigo-50 py-2 rounded">📄 View Cover Letter</button>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($v['final_boq'])): ?>
                                        <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'BOQ', '<?= get_download_path($v['final_boq']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-emerald-800 border border-emerald-400 bg-emerald-100 hover:bg-emerald-200 py-2 rounded shadow-sm">📊 Download Final BoQ</button>
                                    <?php else: ?>
                                        <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'BOQ', '<?= get_download_path($v['file_boq']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-emerald-600 border border-emerald-200 bg-white hover:bg-emerald-50 py-2 rounded">📊 Download BoQ (Excel)</button>
                                    <?php endif; ?>

                                    <?php if(!empty($v['final_supporting'])): ?>
                                        <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'SUPPORT', '<?= get_download_path($v['final_supporting']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-gray-800 border border-gray-400 bg-gray-200 hover:bg-gray-300 py-2 rounded shadow-sm">🗂️ Final Dok Pendukung (ZIP)</button>
                                    <?php elseif(!empty($v['file_supporting'])): ?>
                                        <button type="button" onclick="logAndDownload(<?= $rfq_id ?>, <?= $v['id'] ?>, 'SUPPORT', '<?= get_download_path($v['file_supporting']) ?>')" class="w-full mt-2 text-center text-xs font-bold text-gray-600 border border-gray-300 bg-gray-50 hover:bg-gray-100 py-2 rounded">🗂️ Dok Pendukung (ZIP)</button>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="mt-2 text-center text-xs font-bold text-gray-400 border border-gray-200 bg-gray-50 py-2 rounded">❌ Unduh File Dinonaktifkan (Role Anda)</div>
                                <?php endif; ?>
                                
                                <?php if($is_latest_round && in_array($rfq_data['status'], ['Unsealed', 'Locked']) && $is_committee && in_array($user_role, ['PIC', 'Procurement Manager', 'Manager', 'Category Manager', 'vendormgt_head'])): ?>
                                    <button type="button" onclick="openWinnerModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['vendor_name']) ?>', '<?= $v['total_price'] ?>')" class="w-full mt-2 bg-yellow-400 hover:bg-yellow-500 text-yellow-900 font-bold py-2 rounded shadow">🏆 Tetapkan Pemenang & Tutup</button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if($has_submitted) $rank++; endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="bg-white shadow-sm rounded-lg p-6 border border-gray-200 mb-10">
            <h3 class="text-lg font-bold text-gray-800 border-b pb-2 mb-4">Log Aktivitas</h3>
            <div class="max-h-64 overflow-y-auto">
                <table class="w-full text-sm text-left">
                    <tbody>
                        <?php foreach($audit_logs as $log):
                            $vendor_name = $log['target_vendor_id'] ? ($vendor_map[$log['target_vendor_id']] ?? 'Vendor') : '';
                            $action_text = $log['action_type'];

                            if (strpos($action_text, 'REQUEST_CHANGE_DEADLINE') === 0) {
                                $parts = explode('|', $action_text);
                                $action_text = '<span class="text-blue-600 font-bold">Requested Deadline Change</span>' . (isset($parts[1]) ? " to " . date('d M Y H:i', strtotime($parts[1])) . " WIB" : '');
                            } elseif (strpos($action_text, 'APPROVE_CHANGE_DEADLINE') === 0) {
                                $parts = explode('|', $action_text);
                                $action_text = '<span class="text-green-600 font-bold">Approved Deadline Change</span>' . (isset($parts[1]) ? " to " . date('d M Y H:i', strtotime($parts[1])) . " WIB" : '');
                            } elseif ($action_text === 'REJECT_CHANGE_DEADLINE') {
                                $action_text = '<span class="text-red-600 font-bold">Rejected Deadline Change Request</span>';
                            } elseif ($action_text === 'UNSEAL') {
                                $action_text = '<span class="text-red-600 font-bold">Opened Tender Seal</span>';
                            } elseif ($action_text === 'LOCKED') {
                                $action_text = '<span class="text-purple-600 font-bold">Locked Tender for Final Negotiation</span>';
                            } elseif ($action_text === 'DOWNLOAD_COVER') {
                                $action_text = 'Viewed Cover Letter from <span class="font-bold">'.$vendor_name.'</span>';
                            } elseif ($action_text === 'DOWNLOAD_BOQ') {
                                $action_text = 'Downloaded BoQ from <span class="font-bold">'.$vendor_name.'</span>';
                            } elseif ($action_text === 'DOWNLOAD_SUPPORT') {
                                $action_text = 'Downloaded Supporting Docs (ZIP) from <span class="font-bold">'.$vendor_name.'</span>';
                            } elseif ($action_text === 'CLOSED_TENDER_AND_SET_PRICE') {
                                $action_text = '<span class="text-yellow-600 font-bold">Awarded Winner ('.$vendor_name.') & Closed Tender</span>';
                            } elseif ($action_text === 'ELIMINATED') {
                                $action_text = '<span class="font-bold">'.$vendor_name.'</span> <span class="text-red-500 font-bold">Eliminated (Did Not Submit Before Seal Opened)</span>';
                            } elseif ($action_text === 'INVITED' || $action_text === 'INVITED_ADDITIONAL') {
                                $action_text = '<span class="text-indigo-600 font-bold">Invited Additional Vendor</span>' . ($vendor_name ? ' (<span class="font-bold">'.$vendor_name.'</span>)' : '');
                            } elseif ($action_text === 'REQUEST_ADD_VENDOR') {
                                $action_text = '<span class="text-blue-600 font-bold">Requested to Add Vendor</span>' . ($vendor_name ? ' (<span class="font-bold">'.$vendor_name.'</span>)' : '');
                            } elseif ($action_text === 'APPROVE_ADD_VENDOR') {
                                $action_text = '<span class="text-green-600 font-bold">Approved Vendor Addition</span>' . ($vendor_name ? ' (<span class="font-bold">'.$vendor_name.'</span>)' : '');
                            } elseif ($action_text === 'REJECT_ADD_VENDOR') {
                                $action_text = '<span class="text-red-600 font-bold">Rejected Vendor Addition</span>' . ($vendor_name ? ' (<span class="font-bold">'.$vendor_name.'</span>)' : '');
                            } elseif ($action_text === 'CREATE') {
                                $action_text = '<span class="text-gray-700 font-bold">Created Tender</span>';
                            } elseif ($action_text === 'INVITE') {
                                $action_text = '<span class="text-indigo-600 font-bold">Invited Vendors</span>';
                            } elseif ($action_text === 'SUBMIT_QUOTATION') {
                                $action_text = 'Submitted Quotation' . ($vendor_name ? ' (<span class="font-bold">'.$vendor_name.'</span>)' : '');
                            }

                            $ld = new DateTime($log['created_at'], new DateTimeZone('UTC'));
                            $ld->setTimezone(new DateTimeZone('Asia/Jakarta'));
                        ?>
                        <tr class="border-b last:border-b-0 hover:bg-gray-50">
                            <td class="py-2 text-gray-500 font-mono text-xs w-48"><?= $ld->format('d M Y H:i') ?> WIB</td>
                            <td class="py-2 font-bold text-gray-800 w-48"><?= htmlspecialchars(display_log_user($log['username'], $user_names)) ?></td>
                            <td class="py-2 text-gray-700"><?= $action_text ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($audit_logs)): ?><tr><td class="py-4 text-gray-500 italic">Belum ada aktivitas.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Lock Tender Confirmation Modal -->
    <div id="lockTenderConfirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
        <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-md p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-2">Penutupan Tender</h3>
            <p class="text-sm text-gray-700 mb-6">Lakukan Penutupan Tender? Sistem akan mengirim notifikasi ke PIC.</p>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="document.getElementById('lockTenderConfirmModal').classList.add('hidden')" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded text-sm">Batal</button>
                <button type="button" onclick="document.getElementById('lockTenderForm').submit()" class="bg-gray-800 hover:bg-gray-900 text-white font-bold py-2 px-4 rounded text-sm shadow">🔒 Ya, Tutup Tender</button>
            </div>
        </div>
    </div>

    <div id="addRoundModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-40 mx-auto p-6 border w-full max-w-lg shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">+ Tambah Ronde Negosiasi</h3>
            <p class="text-sm text-gray-600 mb-4">Pilih vendor dari ronde saat ini yang akan Anda undang ke ronde selanjutnya.</p>
            <form method="POST" onsubmit="return confirm('Ini akan menggandakan RFQ dan otomatis mengirim password baru ke vendor terpilih. Lanjutkan?');">
                <input type="hidden" name="action" value="add_round">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                <div class="bg-gray-50 border border-gray-200 rounded p-4 mb-4 max-h-48 overflow-y-auto">
                    <?php foreach($vendor_data as $v): 
                        $eliminated = empty($v['submitted_at']);
                    ?>
                        <label class="flex items-center space-x-3 mb-2 <?= $eliminated ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer hover:bg-gray-100' ?> p-2 rounded">
                            <input type="checkbox" name="invited_vendors[]" value="<?= $v['id'] ?>" <?= $eliminated ? 'disabled' : 'checked' ?> class="w-4 h-4 text-indigo-600 rounded">
                            <span class="text-sm font-bold text-gray-700"><?= htmlspecialchars($v['vendor_name']) ?></span>
                            <?= $eliminated ? '<span class="text-xs text-red-500 font-bold ml-2">(Tereliminasi - Tidak Dapat Diundang)</span>' : '' ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('addRoundModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded font-bold text-sm shadow">Buat Ronde Baru & Undang</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Invite Vendor -->
    <div id="inviteVendorModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-6 border w-full max-w-lg shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Undang Vendor Tambahan</h3>
            <p class="text-sm text-gray-600 mb-4">Vendor akan menerima WhatsApp dan Email berisi link serta password rahasia untuk submit dokumen pada ronde ini.</p>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="invite_vendor">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                
                <div>
                    <label class="block text-sm font-bold text-gray-700">Nama Perusahaan Vendor</label>
                    <input type="text" name="vendor_name" required class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">Nama PIC Vendor</label>
                    <input type="text" name="vendor_pic" class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700">Email Vendor</label>
                        <input type="email" name="vendor_email" required class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700">No. WhatsApp</label>
                        <input type="text" name="vendor_wa" placeholder="Contoh: 0812345678" required class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700">Alasan Penambahan Vendor</label>
                    <textarea name="addition_reason" required rows="2" class="w-full p-2 border border-gray-300 rounded focus:ring focus:ring-indigo-200" placeholder="Wajib diisi. Mengapa vendor ini perlu ditambahkan?"></textarea>
                </div>

                <div class="flex justify-end gap-2 mt-4">
                    <button type="button" onclick="document.getElementById('inviteVendorModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded font-bold text-sm shadow">Undang Sekarang</button>
                </div>
            </form>
        </div>
    </div>

    <div id="winnerModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-40 mx-auto p-6 border w-96 shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Tutup Tender & Tetapkan Pemenang</h3>
            <form method="POST">
                <input type="hidden" name="action" value="close_tender">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                <input type="hidden" name="vendor_id" id="w_id">
                <input type="hidden" name="vendor_name" id="w_name">
                <label class="block text-sm font-bold text-gray-700 mb-1">Harga Final Negosiasi (IDR)</label>
                <input type="text" name="final_price" id="w_price" required class="w-full p-2 border rounded font-mono font-bold text-lg text-gray-800 mb-4" oninput="formatRupiah(this)">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('winnerModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-yellow-400 hover:bg-yellow-500 text-yellow-900 px-4 py-2 rounded font-bold text-sm shadow">Tetapkan Pemenang</button>
                </div>
            </form>
        </div>
    </div>

    <div id="deadlineModal" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-40 mx-auto p-6 border w-96 shadow-xl rounded-md bg-white">
            <h3 class="text-xl font-bold text-gray-900 mb-2">Edit Batas Waktu (Deadline)</h3>
            <form method="POST">
                <input type="hidden" name="action" value="request_edit_deadline">
                <input type="hidden" name="rfq_id" value="<?= $rfq_id ?>">
                <input type="datetime-local" name="new_deadline" required class="w-full p-2 border rounded mb-4" value="<?= date('Y-m-d\TH:i', strtotime($rfq_data['deadline'])) ?>">
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="document.getElementById('deadlineModal').classList.add('hidden')" class="bg-gray-300 px-4 py-2 rounded font-bold text-sm">Batal</button>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded font-bold text-sm shadow">Simpan Deadline</button>
                </div>
            </form>
        </div>
    </div>

<?php 
} else { 
    // ==========================================
    // VIEW 2: MASTER LIST VIEW
    // ==========================================
    $query = "
        SELECT r.id, r.package_name, r.created_by, COALESCE(u.name, r.created_by) as creator_name, r.created_at, r.deadline, r.status, r.round_number,
            (SELECT COUNT(*) FROM rfq_vendors v WHERE v.rfq_id = r.id) as total_vendors,
            (SELECT COUNT(*) FROM rfq_vendors v WHERE v.rfq_id = r.id AND v.submitted_at IS NOT NULL) as total_submissions
        FROM rfqs r
        LEFT JOIN users u ON u.username = r.created_by
        WHERE r.id IN (SELECT MAX(id) FROM rfqs GROUP BY IFNULL(parent_id, id))
        ORDER BY r.created_at DESC
    ";
    $rfqs = $pdo->query($query)->fetchAll(PDO::FETCH_ASSOC);
    ?>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <style>
    /* Matches the global search box look used on the PIC/Manager dashboards. */
    .filt-input { padding: 5px 8px; font-size: 12px; line-height: 1.2; border: 1px solid #d1d5db; border-radius: 6px; background: #fff; color: #374151; box-sizing: border-box; }
    .filt-input:focus { outline: none; border-color: #6366f1; box-shadow: 0 0 0 1px #6366f1; }
    #rfqListTable thead th { text-align: center; }
    </style>

    <div class="max-w-7xl mx-auto py-8">
        <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end border-b pb-4 gap-4">
            <div>
                <h2 class="text-3xl font-bold text-gray-800">Daftar Tender & RFQ</h2>
                <p class="text-sm text-gray-500 mt-1">Hanya menampilkan putaran terakhir dari setiap tender.</p>
            </div>
            <div class="flex gap-2">
                <?php if ($user_role === 'QS' || $user_role === 'Project Director'): ?>
                    <a href="dashboard_project.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">&larr; Dashboard</a>
                <?php elseif ($user_role === 'Category Manager'): ?>
                    <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">&larr; Dashboard</a>
                <?php else: ?>
                    <a href="dashboard_pic.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">&larr; Dashboard</a>
                <?php endif; ?>

                <?php if ($user_role !== 'QS'): ?>
                    <a href="rfq_create.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded-lg text-sm shadow">+ Buat RFQ Baru</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <div class="flex justify-end mb-3">
                <input type="text" id="rfqGlobalSearch" class="filt-input" placeholder="Search all columns..." style="width: 240px;">
            </div>
            <div class="overflow-x-auto">
            <table id="rfqListTable" class="min-w-full leading-normal w-full text-left">
                <thead>
                    <tr class="bg-gray-100 border-b-2 border-gray-200">
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Tender Name</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Date</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Round</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Creator</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Timeline</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Submissions</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Status</th>
                        <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rfqs as $r):
                        $raw_status = $r['status'];
                        $deadline_passed = time() > strtotime($r['deadline']);
                        if ($raw_status === 'Unsealed') { $ui_status = 'Opened'; $badge = 'bg-blue-100 text-blue-800 border-blue-200'; }
                        elseif ($raw_status === 'Locked') { $ui_status = 'Locked'; $badge = 'bg-purple-100 text-purple-800 border-purple-200'; }
                        elseif ($raw_status === 'Closed') { $ui_status = 'Closed'; $badge = 'bg-gray-100 text-gray-800 border-gray-200'; } 
                        else {
                            if ($deadline_passed || ($r['total_submissions'] > 0 && $r['total_submissions'] == $r['total_vendors'])) {
                                $ui_status = 'Waiting Opening'; $badge = 'bg-orange-100 text-orange-800 border-orange-200';
                            } else {
                                $ui_status = 'Waiting Submission'; $badge = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                            }
                        }
                    ?>
                    <tr class="border-b hover:bg-indigo-50 transition">
                        <td class="p-3 text-left">
                            <div class="text-[10px] text-gray-500 font-mono font-bold mb-0.5">ID: #<?= $r['id'] ?></div>
                            <div class="text-sm font-bold text-indigo-700"><?= htmlspecialchars($r['package_name']) ?></div>
                        </td>
                        <td class="p-3 text-xs text-gray-600 font-mono text-center"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
                        <td class="p-3 text-center text-sm font-bold text-gray-600"><?= $r['round_number'] ?></td>
                        <td class="p-3 text-sm text-gray-800 text-center"><?= htmlspecialchars($r['creator_name']) ?></td>
                        <td class="p-3 text-xs text-gray-600 text-center"><strong>Deadline:</strong> <br><?= date('d M Y H:i', strtotime($r['deadline'])) ?> WIB</td>
                        <td class="p-3 text-center font-bold text-gray-700 text-sm"><span class="<?= $r['total_submissions'] === $r['total_vendors'] ? 'text-green-600' : '' ?>"><?= $r['total_submissions'] ?></span> / <?= $r['total_vendors'] ?></td>
                        <td class="p-3 text-center"><span class="<?= $badge ?> py-1 px-3 rounded-full text-xs font-bold border inline-block whitespace-nowrap"><?= $ui_status ?></span></td>
                        <td class="p-3 text-center">
                            <div class="flex justify-center items-center gap-2">
                                <a href="rfq_dashboard.php?id=<?= $r['id'] ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-3 rounded text-xs shadow transition whitespace-nowrap">Open</a>
                                <?php if ($user_role === 'Category Manager'): ?>
                                    <form method="POST" class="inline" onsubmit="return confirm('Hapus Tender ini? Semua putaran, data vendor, dan histori akan terhapus permanen.');">
                                        <input type="hidden" name="action" value="delete_rfq">
                                        <input type="hidden" name="rfq_id" value="<?= $r['id'] ?>">
                                        <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 font-bold py-1.5 px-3 rounded text-xs transition">Del</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
<?php } ?>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<?php if (!isset($_GET['id'])): ?>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
$(document).ready(function() {
    var rfqTable = $('#rfqListTable').DataTable({
        "pageLength": 25,
        "lengthChange": false,
        "order": [[ 1, "desc" ]],
        "dom": "rtip"
    });
    $('#rfqGlobalSearch').on('keyup', function() {
        rfqTable.search(this.value).draw();
    });
});
</script>
<?php endif; ?>

<script>
jQuery(document).ready(function($) {
    window.logAndDownload = function(rfqId, vendorId, docType, url) {
        $.post('rfq_dashboard.php', { action: 'log_download', rfq_id: rfqId, vendor_id: vendorId, doc_type: docType }, function() {
            window.open(url, '_blank');
            setTimeout(() => location.reload(), 800);
        });
    };

    window.openWinnerModal = function(vid, vname, price) {
        document.getElementById('w_id').value = vid;
        document.getElementById('w_name').innerText = vname;
        document.getElementById('w_price').value = price.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        document.getElementById('winnerModal').classList.remove('hidden');
    };

    window.formatRupiah = function(obj) {
        let val = obj.value.replace(/[^\d]/g, '');
        if (val.length > 0) val = val.replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        obj.value = val;
    };
});
</script>

<?php require_once '../includes/footer.php'; ?>