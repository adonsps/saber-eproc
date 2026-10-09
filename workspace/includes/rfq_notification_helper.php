<?php
// includes/rfq_notification_helper.php
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/fonnte.php';

function get_rfq_recipients($pdo, $rfq_id) {
    $recipients = []; // mapped by username
    
    // 1. Get RFQ details and creator
    $stmt = $pdo->prepare("SELECT r.package_name, r.deadline, r.created_by, r.status, u.name as creator_name, u.email as creator_email, u.phone_number as creator_phone, u.manager_username 
                           FROM rfqs r 
                           LEFT JOIN users u ON r.created_by = u.username 
                           WHERE r.id = ?");
    $stmt->execute([$rfq_id]);
    $rfq = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rfq) return ['rfq' => null, 'users' => [], 'committee' => []];

    // Add Creator
    if ($rfq['creator_email'] || $rfq['creator_phone']) {
        $recipients[$rfq['created_by']] = [
            'username' => $rfq['created_by'], 'name' => $rfq['creator_name'], 'email' => $rfq['creator_email'], 'phone' => $rfq['creator_phone'], 'roles' => ['Creator']
        ];
    }

    // 2. Add Creator's Superior
    if ($rfq['manager_username']) {
        $stmt_mgr = $pdo->prepare("SELECT username, name, email, phone_number FROM users WHERE username = ?");
        $stmt_mgr->execute([$rfq['manager_username']]);
        $mgr = $stmt_mgr->fetch(PDO::FETCH_ASSOC);
        if ($mgr && ($mgr['email'] || $mgr['phone_number'])) {
            if (!isset($recipients[$mgr['username']])) {
                $recipients[$mgr['username']] = ['username' => $mgr['username'], 'name' => $mgr['name'], 'email' => $mgr['email'], 'phone' => $mgr['phone_number'], 'roles' => []];
            }
            $recipients[$mgr['username']]['roles'][] = 'Superior';
        }
    }

    // 3. Add Tender Committee
    $stmt_comm = $pdo->prepare("SELECT c.username, c.has_opened, u.name, u.email, u.phone_number 
                                FROM rfq_committee c 
                                JOIN users u ON c.username = u.username 
                                WHERE c.rfq_id = ?");
    $stmt_comm->execute([$rfq_id]);
    $committee = $stmt_comm->fetchAll(PDO::FETCH_ASSOC);
    foreach ($committee as $c) {
        if ($c['email'] || $c['phone_number']) {
            if (!isset($recipients[$c['username']])) {
                $recipients[$c['username']] = ['username' => $c['username'], 'name' => $c['name'], 'email' => $c['email'], 'phone' => $c['phone_number'], 'roles' => []];
            }
            $recipients[$c['username']]['roles'][] = 'Tender Committee';
            $recipients[$c['username']]['has_opened'] = $c['has_opened'];
        }
    }
    
    // 4. Add QS and Project Director
    $stmt_global = $pdo->query("SELECT username, name, email, phone_number, role FROM users WHERE role IN ('QS', 'Project Director')");
    $globals = $stmt_global->fetchAll(PDO::FETCH_ASSOC);
    foreach ($globals as $g) {
        if ($g['email'] || $g['phone_number']) {
            if (!isset($recipients[$g['username']])) {
                $recipients[$g['username']] = ['username' => $g['username'], 'name' => $g['name'], 'email' => $g['email'], 'phone' => $g['phone_number'], 'roles' => []];
            }
            $recipients[$g['username']]['roles'][] = $g['role'];
        }
    }

    return ['rfq' => $rfq, 'committee' => $committee, 'users' => array_values($recipients)];
}

function send_rfq_notifications($users, $subject, $messageTemplate) {
    foreach ($users as $u) {
        $personalizedMessage = str_replace('{RECIPIENT_NAME}', $u['name'], $messageTemplate);
        if (!empty($u['email'])) {
            sendTenderEmail($u['email'], $u['name'], $subject, $personalizedMessage);
        }
        if (!empty($u['phone'])) {
            sendFonnteWA($u['phone'], $personalizedMessage);
        }
    }
}

function notify_new_rfq($pdo, $rfq_id) {
    $data = get_rfq_recipients($pdo, $rfq_id);
    if (!$data['rfq']) return;
    $rfq = $data['rfq'];
    $deadlineStr = date('d F Y H:i', strtotime($rfq['deadline']));
    
    $subject = "RFQ Baru Telah Dibuat: {$rfq['package_name']}";
    $message = "Halo {RECIPIENT_NAME},\n\nRFQ baru telah dibuat oleh {$rfq['creator_name']} dengan detail sebagai berikut:\n";
    $message .= "- Paket: {$rfq['package_name']}\n";
    $message .= "- Batas Waktu: {$deadlineStr} WIB\n\n";
    $message .= "Mohon partisipasi dan pantauan Anda terkait proses tender ini.\n\nSalam,\nProcurement System";

    send_rfq_notifications($data['users'], $subject, $message);
}

function notify_seal_opened($pdo, $rfq_id, $opener_username) {
    $data = get_rfq_recipients($pdo, $rfq_id);
    if (!$data['rfq']) return;
    $rfq = $data['rfq'];
    
    $opener_name = $opener_username;
    $stmt = $pdo->prepare("SELECT name FROM users WHERE username = ?");
    $stmt->execute([$opener_username]);
    $res = $stmt->fetchColumn();
    if ($res) $opener_name = $res;

    $subject = "Segel Tender Dibuka: {$rfq['package_name']}";
    $message = "Halo {RECIPIENT_NAME},\n\nAnggota komite {$opener_name} baru saja membuka segel tender untuk paket: {$rfq['package_name']}.\n\n";
    $message .= "Salam,\nProcurement System";

    send_rfq_notifications($data['users'], $subject, $message);
}

function notify_seal_completely_opened($pdo, $rfq_id) {
    $data = get_rfq_recipients($pdo, $rfq_id);
    if (!$data['rfq']) return;
    $rfq = $data['rfq'];

    $subject = "Tender Terbuka Sepenuhnya: {$rfq['package_name']}";
    $message = "Halo {RECIPIENT_NAME},\n\nSeluruh anggota komite telah membuka segel tender untuk paket: {$rfq['package_name']}. Tender kini berstatus Unsealed.\n\n";
    $message .= "Salam,\nProcurement System";

    send_rfq_notifications($data['users'], $subject, $message);
}

function notify_all_vendors_submitted($pdo, $rfq_id) {
    $data = get_rfq_recipients($pdo, $rfq_id);
    if (!$data['rfq']) return;
    $rfq = $data['rfq'];

    $subject = "Semua Vendor Telah Submit: {$rfq['package_name']}";
    $message = "Halo {RECIPIENT_NAME},\n\nSeluruh vendor yang diundang telah berhasil melakukan submit penawaran untuk paket: {$rfq['package_name']}.\n\n";
    $message .= "Tender sudah dapat dibuka jika batas waktu telah lewat.\n\nSalam,\nProcurement System";

    send_rfq_notifications($data['users'], $subject, $message);
}

function notify_deadline_reminder($pdo, $rfq_id, $type) {
    $data = get_rfq_recipients($pdo, $rfq_id);
    if (!$data['rfq']) return;
    $rfq = $data['rfq'];
    $deadlineStr = date('d F Y H:i', strtotime($rfq['deadline']));

    $subject = "Reminder {$type} RFQ: {$rfq['package_name']}";
    $message = "Halo {RECIPIENT_NAME},\n\nIni adalah pengingat bahwa batas waktu untuk RFQ: {$rfq['package_name']} adalah pada {$deadlineStr} WIB.\n\n";
    $message .= "Salam,\nProcurement System";

    send_rfq_notifications($data['users'], $subject, $message);
}

function notify_post_deadline_reminder($pdo, $rfq_id) {
    $data = get_rfq_recipients($pdo, $rfq_id);
    if (!$data['rfq']) return;
    $rfq = $data['rfq'];
    $committee = $data['committee'];
    
    if (empty($committee)) return; // if no committee, standard RFQ automatically unsealed

    $opened_names = [];
    $unopened_names = [];
    foreach ($committee as $c) {
        if ($c['has_opened']) {
            $opened_names[] = $c['name'];
        } else {
            $unopened_names[] = $c['name'];
        }
    }

    if (empty($opened_names)) {
        $status_text = "Belum ada anggota komite yang membuka segel.";
    } else {
        $status_text = "Beberapa anggota komite telah membuka segel.\n- Sudah buka: " . implode(', ', $opened_names) . "\n- Belum buka: " . implode(', ', $unopened_names);
    }

    $subject = "Reminder Lewat Batas Waktu RFQ: {$rfq['package_name']}";
    $message = "Halo {RECIPIENT_NAME},\n\nBatas waktu untuk RFQ: {$rfq['package_name']} telah terlewati, namun segel belum terbuka sepenuhnya.\n\n";
    $message .= "Status Segel:\n{$status_text}\n\n";
    $message .= "Mohon kepada komite terkait untuk segera membuka segel tender di portal.\n\nSalam,\nProcurement System";

    send_rfq_notifications($data['users'], $subject, $message);
}
?>
