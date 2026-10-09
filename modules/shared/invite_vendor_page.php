<?php
// modules/shared/invite_vendor_page.php
// Shared "Invite Vendor" page used by both modules/sourcing/invite_vendor.php and
// modules/admin/invite_vendor.php. It only sends an email containing a link to the
// public register.php page — it does NOT create any user/vendor record itself
//
// The including wrapper MUST define, BEFORE requiring this file:
//   $path_to_root          e.g. "../../"  (relative path from the wrapper to the app root)
//   $invite_required_role  e.g. "vendor_mgt", "vendormgt_head", "sourcing" or "admin" — the role allowed to use this page.
//   $invite_dashboard_url  e.g. "modules/sourcing/dashboard.php" (Dashboard link, relative to root)

if (!isset($invite_required_role) || !isset($invite_dashboard_url) || !isset($path_to_root)) {
    die('invite_vendor_page.php requires $path_to_root, $invite_required_role and $invite_dashboard_url to be set.');
}

require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/notification_helper.php'; // _buildMailer() / _sendMail()

// 1. Security Check — same guard as dashboard.php.
//    $invite_required_role may be a single role string or an array of roles.
$invite_allowed_roles = (array) $invite_required_role;
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $invite_allowed_roles, true)) {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

$error   = '';

// One Time Running to Create table
$conn->query("CREATE TABLE IF NOT EXISTS vendor_invitations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                invitee_email VARCHAR(255) NOT NULL,
                invitee_name VARCHAR(255) DEFAULT NULL,
                company_name VARCHAR(255) DEFAULT NULL,
                sub_category ENUM('Kontraktor','Supplier','Perseorangan') DEFAULT NULL,
                cc_emails TEXT DEFAULT NULL,
                personal_note TEXT DEFAULT NULL,
                invited_by_id INT DEFAULT NULL,
                invited_by_name VARCHAR(255) DEFAULT NULL,
                status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
                invite_code VARCHAR(32) DEFAULT NULL UNIQUE,
                invite_token VARCHAR(64) DEFAULT NULL UNIQUE,
                opened_at TIMESTAMP NULL DEFAULT NULL,
                open_count INT NOT NULL DEFAULT 0,
                registered_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// vendor_invitations.invite_code (the Invitation ID) is migrated in config.php,
// required above, so it exists on every request — not just when this page is opened.

// Schema migration: existing installs created the table before sub_category existed.
try {
    $conn->query("ALTER TABLE vendor_invitations ADD COLUMN sub_category ENUM('Kontraktor','Supplier','Perseorangan') DEFAULT NULL AFTER company_name");
} catch (mysqli_sql_exception $e) {
    // 1060 = Duplicate column name (already applied). Anything else is unexpected.
    if ($e->getCode() != 1060) {
        throw $e;
    }
}

// 2. Handle invitation submit (POST) — Post/Redirect/Get on success
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite_btn'])) {

    $invitee_email   = trim($_POST['invitee_email'] ?? '');
    $invitee_name    = trim($_POST['invitee_name'] ?? '');
    $company_name    = trim($_POST['company_name'] ?? '');
    $sub_category    = trim($_POST['sub_category'] ?? '');
    $cc_emails       = trim($_POST['cc_emails'] ?? '');
    $personal_note   = trim($_POST['personal_note'] ?? '');

    $allowed_sub_categories = ['Kontraktor', 'Supplier', 'Perseorangan'];

    // --- Validation ---
    if ($invitee_email === '' || !filter_var($invitee_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Alamat email tidak valid. Mohon periksa kembali.';
    } elseif (!in_array($sub_category, $allowed_sub_categories, true)) {
        $error = 'Sub kategori vendor tidak valid. Mohon pilih salah satu.';
    } else {
        // Reject if the email already belongs to an existing account.
        $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chk->bind_param("s", $invitee_email);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            $error = 'Email ini sudah terdaftar di sistem. Vendor dapat langsung masuk (login).';
        }
    }

    // --- Parse & validate optional CC recipients (comma / semicolon separated) ---
    $cc_list = [];
    if ($error === '' && $cc_emails !== '') {
        foreach (preg_split('/[,;]+/', $cc_emails) as $tok) {
            $tok = trim($tok);
            if ($tok === '') continue;
            if (!filter_var($tok, FILTER_VALIDATE_EMAIL)) {
                $error = 'Alamat email CC tidak valid: ' . $tok;
                break;
            }
            $cc_list[] = $tok;
        }
    }

    if ($error === '') {
        // --- Build the public registration link (root register.php) ---
        $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'] ?? '';
        // This page lives in /modules/<role> — strip that segment to reach the app root.
        $script_dir    = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
        $root_url_path = preg_replace('#/modules/[^/]+/?$#', '', $script_dir);
        $register_link = $protocol . $domainName . $root_url_path . '/register.php';

        // Unique token used to detect when this specific invitation link is opened.
        // Appended to every link in the email; register.php records the open.
        $invite_token  = bin2hex(random_bytes(16)); // 32 hex chars
        $register_link = $register_link . '?invite=' . $invite_token;

        // Human-readable Invitation ID quoted in the subject, in the email body and in
        // the history table
        $period      = date('ym'); // WIB — config.php pins Asia/Jakarta and MySQL +07:00
        $invite_code = '';
        $seqStmt = $conn->prepare("
            SELECT MAX(CAST(SUBSTRING(invite_code, 9) AS UNSIGNED)) AS max_seq
            FROM vendor_invitations
            WHERE invite_code REGEXP ?
        ");
        if ($seqStmt) {
            $seq_pattern = '^IVT-' . $period . '[0-9]+$';
            $seqStmt->bind_param("s", $seq_pattern);
            if ($seqStmt->execute()) {
                // No rows yet this month -> MAX() is NULL -> (int) gives 0 -> starts at 001.
                $next_seq = (int)($seqStmt->get_result()->fetch_assoc()['max_seq'] ?? 0) + 1;
                // %03d pads to 3 digits; a month that ever exceeds 999 simply grows to
                // 4 digits rather than wrapping around onto an existing code.
                $invite_code = 'IVT-' . $period . sprintf('%03d', $next_seq);
            }
        }
        if ($invite_code === '') {
            // Sequence lookup failed — fall back to a random code so the invitation
            // still carries an ID instead of going out with a blank one. The dashed
            // shape makes these stand out in history as needing a look, and the REGEXP
            // above ignores them so they never disturb the running number.
            error_log("[invite] invite_code sequence lookup failed, using fallback: " . $conn->error);
            $invite_code = 'IVT-' . $period . '-' . strtoupper(bin2hex(random_bytes(2)));
        }

        $inviter_name = $_SESSION['user_name'] ?? 'Tim Procurement Kawan Lama Group';

        // Who this invitation is for, used in the subject so CC recipients can tell at a
        // glance which vendor company was invited (a bare PIC name says nothing).
        if ($company_name !== '') {
            // Company alone is what CC recipients need to recognise the vendor; the PIC
            // name would only lengthen the subject, and it already leads the salutation.
            $invite_target = $company_name;
        } elseif ($invitee_name !== '') {
            $invite_target = $invitee_name;
        } else {
            // Neither name was entered — drop the segment entirely rather than falling
            // back to the raw recipient address, which only repeats what the To: field
            // already shows. The Invitation ID still makes the subject unique.
            $invite_target = '';
        }

        // Salutation — always "Halo Bapak/Ibu <nama>,". The PIC leads and the company
        // follows in brackets when both are known, so the reader sees who is addressed
        // and which vendor they represent in one line.
        if ($company_name !== '' && $invitee_name !== '') {
            $greeting_name = $invitee_name . ' (' . $company_name . ')';
        } elseif ($company_name !== '') {
            $greeting_name = $company_name;
        } elseif ($invitee_name !== '') {
            $greeting_name = $invitee_name;
        } else {
            $greeting_name = 'Calon Mitra Vendor';
        }
        $salutation_html = 'Halo Bapak/Ibu ' . htmlspecialchars($greeting_name) . ',';
        $salutation_text = 'Halo Bapak/Ibu ' . $greeting_name . ',';

        // --- Compose & send the invitation email (same SMTP path as registration) ---
        $mail = _buildMailer();
        $mail->addAddress($invitee_email, $invitee_name !== '' ? $invitee_name : $invitee_email);

        // Static CC: always copy procurement, regardless of the form input.
        // PHPMailer's addCC() accepts ONE address per call (it does NOT split a
        // comma-separated list), so each recipient must be added individually.
        // Duplicates are skipped by PHPMailer, so a manual CC of the same
        // address won't be added twice.
        $static_cc = [
            'hadi.risali@kawanlamacorp.com',
            'teofilus.franky@kawanlamacorp.com',
            'andreas.yonathan@kawanlamacorp.com',
            'alvin.anggawijaya@kawanlamacorp.com',
            'jason.sunjaya@kawanlamacorp.com',
            'sigit.ramadan@kawanlamacorp.com',
            'group_gp@kawanlamaretail.com',
        ];
        foreach ($static_cc as $cc) {
            $mail->addCC($cc);
        }

        // Optional manual CC recipients entered by the admin.
        foreach ($cc_list as $cc) {
            $mail->addCC($cc);
        }

        // Keep procurement copied, consistent with registration emails.
        if (defined('REGISTRATION_BCC_RECIPIENTS')) {
            foreach (REGISTRATION_BCC_RECIPIENTS as $bcc) {
                $mail->addBCC($bcc);
            }
        }

        $mail->isHTML(true);
        // Subject leads with the invited company/PIC (kept early so it survives inbox
        // truncation) and closes with the Invitation ID for reference.
        $mail->Subject = 'Undangan Pendaftaran Vendor VSS'
                       . ($invite_target !== '' ? ' — ' . $invite_target : '')
                       . ' [' . $invite_code . ']';

        // Optional admin note — rendered as a highlighted block inside the email.
        $note_block = '';
        if ($personal_note !== '') {
            $note_block = '<div style="margin:0 0 30px;padding:12px 16px;background:#f8f9fa;border-left:4px solid #007bff;border-radius:4px;color:#333;font-size:14px;">'
                        . nl2br(htmlspecialchars($personal_note))
                        . '</div>';
        }

        // Banner image is embedded (cid:bannerImage) ONLY if the asset exists,
        // so a missing file never produces a broken image in the email.
        // This file lives in /modules/shared, two levels below the app root.
        $banner_img_tag = '';
        $banner_file    = dirname(__DIR__, 2) . '/assets/img/Banner for VSS.png';
        if (is_file($banner_file)) {
            $mail->addEmbeddedImage($banner_file, 'bannerImage');
            $banner_img_tag =
                '<a href="' . $register_link . '" target="_blank" style="text-decoration: none;">'
              . '<img src="cid:bannerImage" alt="Banner VSS Kawan Lama Group" style="width: 100%; max-width: 100%; display: block; border: none;">'
              . '</a>';
        }

        $mail->Body = <<<HTML
<div style="background-color: #f4f7f6; padding: 40px 10px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
  <div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 10px rgba(0,0,0,0.05);">

    {$banner_img_tag}

    <div style="padding: 30px; color: #333333; line-height: 1.6; font-size: 15px;">

      <p style="margin-top: 0;"><strong>{$salutation_html}</strong></p>

      <p>Punya produk unggulan dan ingin menjadi bagian dari jaringan bisnis <strong>Kawan Lama Group</strong>? Kini, langkah Anda untuk bergabung jadi jauh lebih mudah, cepat, dan transparan!</p>

      <p>Perkenalkan, <strong>Vendor Sourcing System (VSS)</strong>, portal digital terbaru dari Tim Corporate Procurement Kawan Lama Group yang dirancang khusus untuk mempermudah langkah Anda menjadi mitra kami.</p>

      <p>Melalui portal VSS, Anda bisa melakukan semuanya secara mandiri dalam satu pintu:</p>

      <ul style="padding-left: 20px;">
        <li style="margin-bottom: 10px;">&#128187; <strong>Self-Registration (Daftar Mandiri):</strong> Tidak perlu lagi kirim dokumen fisik. Buat akun perusahaan Anda kapan saja dan di mana saja.</li>
        <li style="margin-bottom: 10px;">&#128221; <strong>Lengkapi Profil &amp; Pra-Kualifikasi:</strong> Isi data perusahaan dan lengkapi form kelayakan secara <em>online</em> dengan sistem yang sangat <em>user-friendly</em>.</li>
        <li style="margin-bottom: 10px;">&#128230; <strong>Tawarkan Produk Terbaik:</strong> Jadikan ini sebagai etalase Anda! Langsung tawarkan produk dan layanan terbaik Anda kepada tim kami.</li>
      </ul>

      <p style="margin-bottom: 30px;">Jangan lewatkan kesempatan untuk berkembang dan berkolaborasi bersama kami. Yuk, mulai proses pendaftaran Anda sekarang juga!</p>

      {$note_block}

      <div style="text-align: center; margin-bottom: 30px;">
        <a href="{$register_link}" target="_blank" style="background-color: #007bff; color: #ffffff; display: inline-block; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-size: 16px; font-weight: bold;">
          Daftar Sekarang
        </a>
      </div>

      <hr style="border: none; border-top: 1px solid #eeeeee; margin: 20px 0;">

      <p style="font-size: 13px; color: #666666; margin-bottom: 20px;">
        Jika Bapak/Ibu mengalami kendala atau memiliki pertanyaan seputar cara penggunaan portal, jangan ragu untuk membalas email ini atau menghubungi tim support kami di <strong>sigit.ramadan@kawanlamacorp.com</strong>.
      </p>

      <p style="margin-bottom: 5px;">Salam hangat,</p>
      <p style="margin-top: 0; font-weight: bold; color: #000000;">Tim Corporate Procurement<br>Kawan Lama Group</p>

      <p style="font-size: 12px; color: #999999; margin: 24px 0 0;">
        Nomor Undangan: <strong style="color: #666666;">{$invite_code}</strong>
        &nbsp;&middot;&nbsp; Mohon sertakan nomor ini bila menghubungi kami terkait undangan ini.
      </p>

    </div>
  </div>
</div>
HTML;

        $mail->AltBody =
            $salutation_text . "\n\n"
            . "Perkenalkan Vendor Sourcing System (VSS), portal digital dari Tim Corporate Procurement Kawan Lama Group untuk mempermudah Anda menjadi mitra kami.\n\n"
            . ($personal_note !== '' ? ($personal_note . "\n\n") : '')
            . "Silakan mulai pendaftaran di sini:\n" . $register_link . "\n\n"
            . "Jika ada kendala, hubungi tim support kami di sigit.ramadan@kawanlamacorp.com.\n\n"
            . "Salam hangat,\nTim Corporate Procurement\nKawan Lama Group\n\n"
            . "Nomor Undangan: {$invite_code}";

        $sent_ok = _sendMail($mail);

        // --- Record this invitation into history (best-effort; never blocks the flow) ---
        // invited_by_id  = users.id of the admin who sent it (from session)
        // invited_by_name = that admin's display name, stored so history needs no JOIN
        $log_status = $sent_ok ? 'sent' : 'failed';
        $cc_joined  = implode(', ', $cc_list);
        $invited_by = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
        $logStmt = $conn->prepare("
            INSERT INTO vendor_invitations
                (invitee_email, invitee_name, company_name, sub_category, cc_emails, personal_note, invited_by_id, invited_by_name, status, invite_code, invite_token)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if ($logStmt) {
            $logStmt->bind_param(
                "ssssssissss",
                $invitee_email,
                $invitee_name,
                $company_name,
                $sub_category,
                $cc_joined,
                $personal_note,
                $invited_by,
                $inviter_name,
                $log_status,
                $invite_code,
                $invite_token
            );
            if (!$logStmt->execute()) {
                error_log("[invite] history insert failed for {$invitee_email}: " . $logStmt->error);
            }
        } else {
            error_log("[invite] history prepare failed for {$invitee_email}: " . $conn->error);
        }

        if ($sent_ok) {
            header("Location: invite_vendor.php?sent=" . urlencode($invitee_email));
            exit();
        } else {
            $why = mail_send_status();
            if ($why['type'] === 'cooldown') {
                $error = 'Pengiriman email dijeda otomatis karena server email baru saja menolak pesan '
                       . '(untuk mencegah akun diblokir). Coba lagi sekitar '
                       . max(1, (int) ceil($why['retry_after'] / 60)) . ' menit lagi. Undangan ini belum terkirim ke vendor.';
            } elseif ($why['type'] === 'rate_limited') {
                // Our own send cap, not an SMTP fault: each invitation uses 10
                // recipient slots (To + 8 Cc + Bcc), so only a few fit per hour.
                $error = 'Kuota pengiriman email sedang penuh (batas dibuat agar akun email tidak diblokir). '
                       . 'Silakan kirim undangan berikutnya sekitar ' . max(1, (int) ceil($why['retry_after'] / 60))
                       . ' menit lagi. Undangan ini belum terkirim ke vendor.';
            } else {
                $error = 'Gagal mengirim email undangan. Periksa koneksi SMTP / coba lagi beberapa saat lagi.';
            }
        }
    }
}

// --- Load recent invitation history (latest first) for display below the form ---
$invitations = [];
$histRes = $conn->query("
    SELECT invite_code, invitee_email, invitee_name, company_name, sub_category, cc_emails, invited_by_name, status, created_at, opened_at, open_count, registered_at
    FROM vendor_invitations
    ORDER BY created_at DESC, id DESC
    LIMIT 50
");
if ($histRes) {
    while ($row = $histRes->fetch_assoc()) {
        $invitations[] = $row;
    }
}

// --- AJAX: return invitation history as JSON so the table can refresh live (no page reload) ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    header('Content-Type: application/json; charset=utf-8');
    $out = [];
    foreach ($invitations as $inv) {
        $out[] = [
            'invite_code'     => $inv['invite_code'],
            'invitee_email'   => $inv['invitee_email'],
            'invitee_name'    => $inv['invitee_name'],
            'company_name'    => $inv['company_name'],
            'sub_category'    => $inv['sub_category'],
            'invited_by_name' => $inv['invited_by_name'],
            'status'          => $inv['status'],
            'created_fmt'     => $inv['created_at'] ? date('d M Y H:i', strtotime($inv['created_at'])) : '',
            'opened_at'       => $inv['opened_at'],
            'opened_fmt'      => $inv['opened_at'] ? date('d M Y H:i', strtotime($inv['opened_at'])) : '',
            'open_count'      => (int)$inv['open_count'],
            'registered_at'   => $inv['registered_at'],
            'registered_fmt'  => $inv['registered_at'] ? date('d M Y H:i', strtotime($inv['registered_at'])) : '',
        ];
    }
    echo json_encode($out);
    exit();
}

$page_title = "Invite Vendor";
include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Invite Vendor
        </span>
        <a href="<?= $path_to_root . $invite_dashboard_url ?>" class="btn btn-sm btn-nav">
            <i class="fas fa-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</nav>

<div class="container py-2">
    <div class="row justify-content-center">
        <div class="col-lg-10 col-md-11">

            <?php if (isset($_GET['sent'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-4 border-success" role="alert">
                    <i class="fas fa-paper-plane me-2"></i>
                    Undangan berhasil dikirim ke <strong><?= htmlspecialchars($_GET['sent']) ?></strong>.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-4 border-danger" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-primary text-white py-3 border-0">
                    <h5 class="mb-0 fw-bold text-white">
                        <i class="fas fa-envelope-open-text me-2"></i> Undang Vendor untuk Mendaftar
                    </h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">
                        Masukkan alamat email vendor. Sistem akan mengirimkan tautan pendaftaran agar vendor dapat
                        membuat akun sendiri di halaman registrasi. Akun vendor dibuat oleh vendor itu sendiri.
                    </p>

                    <form method="POST" action="invite_vendor.php" autocomplete="off" id="inviteForm">
                        <!-- Dikirim sebagai hidden agar tetap ada di payload meski tombol di-disable saat loading. -->
                        <input type="hidden" name="invite_btn" value="1">
                        <div class="mb-3">
                            <label class="form-label fw-bold small">Alamat Email Vendor <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-envelope text-muted"></i></span>
                                <input type="email" name="invitee_email" class="form-control"
                                       placeholder="nama@perusahaan.com" required
                                       value="<?= isset($_POST['invitee_email']) ? htmlspecialchars($_POST['invitee_email']) : '' ?>">
                            </div>
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">Nama Narahubung <span class="text-muted fw-normal">(opsional)</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="fas fa-user-tie text-muted"></i></span>
                                        <input type="text" name="invitee_name" class="form-control"
                                               placeholder="Nama lengkap"
                                               value="<?= isset($_POST['invitee_name']) ? htmlspecialchars($_POST['invitee_name']) : '' ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold small">Nama Perusahaan <span class="text-muted fw-normal">(opsional)</span></label>
                                    <div class="input-group">
                                        <span class="input-group-text bg-light"><i class="fas fa-building text-muted"></i></span>
                                        <input type="text" name="company_name" class="form-control"
                                               placeholder="mis. PT Maju Bersama"
                                               value="<?= isset($_POST['company_name']) ? htmlspecialchars($_POST['company_name']) : '' ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">Sub Kategori Vendor <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-layer-group text-muted"></i></span>
                                <?php $selected_sub_category = $_POST['sub_category'] ?? ''; ?>
                                <select name="sub_category" class="form-select" required>
                                    <option value="" disabled <?= $selected_sub_category === '' ? 'selected' : '' ?>>Pilih Sub Kategori...</option>
                                    <option value="Kontraktor" <?= $selected_sub_category === 'Kontraktor' ? 'selected' : '' ?>>construction (PT, CV, dll)</option>
                                    <option value="Supplier" <?= $selected_sub_category === 'Supplier' ? 'selected' : '' ?>>Supplier (PT, CV, dll)</option>
                                    <option value="Perseorangan" <?= $selected_sub_category === 'Perseorangan' ? 'selected' : '' ?>>Perseorangan (UMKM)</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-bold small">CC Email <span class="text-muted fw-normal">(opsional)</span></label>
                            <div class="input-group">
                                <span class="input-group-text bg-light"><i class="fas fa-copy text-muted"></i></span>
                                <input type="text" name="cc_emails" class="form-control"
                                       placeholder="email1@contoh.com, email2@contoh.com"
                                       value="<?= isset($_POST['cc_emails']) ? htmlspecialchars($_POST['cc_emails']) : '' ?>">
                            </div>
                            <div class="form-text small text-muted">Pisahkan beberapa email dengan koma (,) jika lebih dari satu.</div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-bold small">Pesan Tambahan <span class="text-muted fw-normal">(opsional)</span></label>
                            <textarea name="personal_note" class="form-control" rows="3" maxlength="500"
                                      placeholder="Catatan singkat yang akan disertakan dalam email undangan..."><?= isset($_POST['personal_note']) ? htmlspecialchars($_POST['personal_note']) : '' ?></textarea>
                        </div>

                        <div class="d-grid">
                            <button type="submit" id="inviteSubmitBtn" class="btn btn-primary fw-bold py-2">
                                <span class="btn-label"><i class="fas fa-paper-plane me-2"></i> Kirim Undangan</span>
                                <span class="btn-loading d-none">
                                    <span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>
                                    Mengirim undangan...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Riwayat Undangan -->
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mt-4">
                <div class="card-header bg-light py-3 border-0 d-flex align-items-center justify-content-between">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-history me-2 text-primary"></i> Riwayat Undangan
                    </h6>
                    <span class="badge bg-secondary" id="historyCount"><?= count($invitations) ?></span>
                </div>
                <div class="card-body p-0">
                    <div id="historyEmpty" class="<?= empty($invitations) ? '' : 'd-none' ?>">
                        <p class="text-muted small text-center my-4 mb-0">Belum ada undangan yang dikirim.</p>
                    </div>
                    <div id="historyTableWrap" class="table-responsive <?= empty($invitations) ? 'd-none' : '' ?>" style="max-height: 450px; overflow-y: auto;">
                        <table class="table table-sm table-hover align-middle mb-0 small">
                            <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                                <tr>
                                    <th class="ps-3">Waktu</th>
                                    <th>ID Undangan</th>
                                    <th>Email Vendor</th>
                                    <th>Narahubung</th>
                                    <th>Perusahaan</th>
                                    <th>Sub Kategori</th>
                                    <th>Dikirim Oleh</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Dibuka</th>
                                    <th class="text-center pe-3">Registrasi</th>
                                </tr>
                            </thead>
                            <tbody id="historyTbody">
                                <?php foreach ($invitations as $inv): ?>
                                        <tr>
                                            <td class="ps-3 text-nowrap"><?= htmlspecialchars(date('d M Y H:i', strtotime($inv['created_at']))) ?></td>
                                            <td class="text-nowrap"><?= !empty($inv['invite_code']) ? '<code>' . htmlspecialchars($inv['invite_code']) . '</code>' : '<span class="text-muted">-</span>' ?></td>
                                            <td><?= htmlspecialchars($inv['invitee_email']) ?></td>
                                            <td><?= $inv['invitee_name'] !== '' ? htmlspecialchars($inv['invitee_name']) : '<span class="text-muted">-</span>' ?></td>
                                            <td><?= $inv['company_name'] !== '' ? htmlspecialchars($inv['company_name']) : '<span class="text-muted">-</span>' ?></td>
                                            <td><?= !empty($inv['sub_category']) ? htmlspecialchars($inv['sub_category']) : '<span class="text-muted">-</span>' ?></td>
                                            <td><?= $inv['invited_by_name'] !== '' ? htmlspecialchars($inv['invited_by_name']) : '<span class="text-muted">-</span>' ?></td>
                                            <td class="text-center">
                                                <?php if ($inv['status'] === 'sent'): ?>
                                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Terkirim</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Gagal</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center text-nowrap">
                                                <?php if (!empty($inv['opened_at'])): ?>
                                                    <span class="badge bg-info text-dark"
                                                          title="Dibuka <?= (int)$inv['open_count'] ?>x — pertama kali <?= htmlspecialchars(date('d M Y H:i', strtotime($inv['opened_at']))) ?>">
                                                        <i class="fas fa-eye me-1"></i>Dibuka
                                                        <?php if ((int)$inv['open_count'] > 1): ?>
                                                            (<?= (int)$inv['open_count'] ?>x)
                                                        <?php endif; ?>
                                                    </span>
                                                    <div class="text-muted mt-1" style="font-size: 0.7rem;">
                                                        <i class="far fa-clock me-1"></i><?= htmlspecialchars(date('d M Y H:i', strtotime($inv['opened_at']))) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border"><i class="fas fa-clock me-1"></i>Belum</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center pe-3 text-nowrap">
                                                <?php if (!empty($inv['registered_at'])): ?>
                                                    <span class="badge bg-success" title="Terdaftar <?= htmlspecialchars(date('d M Y H:i', strtotime($inv['registered_at']))) ?>">
                                                        <i class="fas fa-user-check me-1"></i>Sudah Registrasi
                                                    </span>
                                                    <div class="text-muted mt-1" style="font-size: 0.7rem;">
                                                        <i class="far fa-clock me-1"></i><?= htmlspecialchars(date('d M Y H:i', strtotime($inv['registered_at']))) ?>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="badge bg-light text-muted border"><i class="fas fa-clock me-1"></i>Belum</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Overlay loading: memblokir seluruh interaksi selama undangan dikirim (cegah klik berulang). -->
<div id="inviteOverlay"
     style="display:none; position:fixed; inset:0; z-index:20000; background:rgba(255,255,255,.75);
            backdrop-filter:blur(2px); align-items:center; justify-content:center;">
    <div class="text-center bg-white shadow rounded-4 px-4 py-4" style="min-width:260px;">
        <div class="spinner-border text-primary mb-3" role="status" style="width:2.5rem;height:2.5rem;">
            <span class="visually-hidden">Loading...</span>
        </div>
        <div class="fw-bold">Mengirim undangan...</div>
        <div class="text-muted small mt-1">Mohon tunggu, jangan tutup atau muat ulang halaman ini.</div>
    </div>
</div>

<script>
// --- Anti double-submit form undangan vendor ---
(function () {
    var form    = document.getElementById('inviteForm');
    var btn     = document.getElementById('inviteSubmitBtn');
    var overlay = document.getElementById('inviteOverlay');
    if (!form || !btn) return;

    var submitting = false;

    function setLoading(on) {
        submitting = on;
        btn.disabled = on;
        var label = btn.querySelector('.btn-label');
        var load  = btn.querySelector('.btn-loading');
        if (label) label.classList.toggle('d-none', on);
        if (load)  load.classList.toggle('d-none', !on);
        if (overlay) overlay.style.display = on ? 'flex' : 'none';
    }

    form.addEventListener('submit', function (e) {
        // Submit kedua dan seterusnya diblokir total.
        if (submitting) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        }
        // Biarkan validasi HTML5 (required/email) jalan lebih dulu.
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;
        setLoading(true);
    });

    // Reset saat user menekan tombol Back (halaman diambil dari bfcache),
    // supaya overlay tidak nyangkut dan form bisa dipakai lagi.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) setLoading(false);
    });
})();
</script>

<script>
// Live-refresh the invitation history (esp. the "Dibuka" status) without reloading the page.
(function () {
    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }
    function dash(v) {
        return (v !== null && v !== undefined && v !== '') ? esc(v) : '<span class="text-muted">-</span>';
    }
    function renderRow(inv) {
        var statusBadge = inv.status === 'sent'
            ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Terkirim</span>'
            : '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>Gagal</span>';
        var openedCell;
        if (inv.opened_at) {
            var extra = inv.open_count > 1 ? ' (' + inv.open_count + 'x)' : '';
            openedCell =
                '<span class="badge bg-info text-dark" title="Dibuka ' + inv.open_count + 'x — pertama kali ' + esc(inv.opened_fmt) + '">' +
                    '<i class="fas fa-eye me-1"></i>Dibuka' + extra +
                '</span>' +
                '<div class="text-muted mt-1" style="font-size: 0.7rem;">' +
                    '<i class="far fa-clock me-1"></i>' + esc(inv.opened_fmt) +
                '</div>';
        } else {
            openedCell = '<span class="badge bg-light text-muted border"><i class="fas fa-clock me-1"></i>Belum</span>';
        }
        var registeredCell;
        if (inv.registered_at) {
            registeredCell =
                '<span class="badge bg-success" title="Terdaftar ' + esc(inv.registered_fmt) + '">' +
                    '<i class="fas fa-user-check me-1"></i>Sudah Registrasi' +
                '</span>' +
                '<div class="text-muted mt-1" style="font-size: 0.7rem;">' +
                    '<i class="far fa-clock me-1"></i>' + esc(inv.registered_fmt) +
                '</div>';
        } else {
            registeredCell = '<span class="badge bg-light text-muted border"><i class="fas fa-clock me-1"></i>Belum</span>';
        }
        return '<tr>' +
            '<td class="ps-3 text-nowrap">' + esc(inv.created_fmt) + '</td>' +
            '<td class="text-nowrap">' + (inv.invite_code ? '<code>' + esc(inv.invite_code) + '</code>' : '<span class="text-muted">-</span>') + '</td>' +
            '<td>' + esc(inv.invitee_email) + '</td>' +
            '<td>' + dash(inv.invitee_name) + '</td>' +
            '<td>' + dash(inv.company_name) + '</td>' +
            '<td>' + dash(inv.sub_category) + '</td>' +
            '<td>' + dash(inv.invited_by_name) + '</td>' +
            '<td class="text-center">' + statusBadge + '</td>' +
            '<td class="text-center text-nowrap">' + openedCell + '</td>' +
            '<td class="text-center pe-3 text-nowrap">' + registeredCell + '</td>' +
        '</tr>';
    }
    function refresh() {
        fetch('invite_vendor.php?ajax=history', { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (data) {
                if (!data) return;
                var tbody = document.getElementById('historyTbody');
                var wrap  = document.getElementById('historyTableWrap');
                var empty = document.getElementById('historyEmpty');
                var count = document.getElementById('historyCount');
                if (count) count.textContent = data.length;
                if (data.length === 0) {
                    if (wrap) wrap.classList.add('d-none');
                    if (empty) empty.classList.remove('d-none');
                    if (tbody) tbody.innerHTML = '';
                    return;
                }
                if (empty) empty.classList.add('d-none');
                if (wrap) wrap.classList.remove('d-none');
                if (tbody) tbody.innerHTML = data.map(renderRow).join('');
            })
            .catch(function () { /* ignore transient network errors */ });
    }
    // Poll every 8s; pause while the tab is hidden to avoid wasted requests.
    setInterval(function () {
        if (!document.hidden) refresh();
    }, 8000);
})();
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
