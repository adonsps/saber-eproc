<?php
// includes/notification_helper.php

require_once __DIR__ . '/smtp_config.php';
require_once __DIR__ . '/mail_rate_limiter.php';

use PHPMailer\PHPMailer\PHPMailer;

function _buildMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    configureSmtp($mail);
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom(mail_smtp_config()['from_email'], 'Saber eProc');

    return $mail;
}

function _sendMail(PHPMailer $mail): bool
{
    try {
        return mail_rate_limited_send($mail, 'notification');
    } catch (\Exception $e) {
        error_log("[notify] Mail failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Build an absolute URL to an app-relative path (e.g. "modules/vendormgt/x.php?id=1")
 * for use inside emails, where relative links do not work. Derived from the current
 * web request: scheme + host + the app root (the path segment before "/modules/").
 * Returns '' when there is no HTTP context (e.g. CLI/cron), so callers can fall back.
 */
function _appUrl(string $relative): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return '';

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443)
        || (strtolower((string)($_SERVER['REQUEST_SCHEME'] ?? '')) === 'https');
    $scheme = $https ? 'https' : 'http';

    $script  = $_SERVER['SCRIPT_NAME'] ?? '';
    $pos     = strpos($script, '/modules/');
    $appRoot = $pos !== false ? substr($script, 0, $pos) : rtrim(str_replace('\\', '/', dirname($script)), '/');

    return $scheme . '://' . $host . $appRoot . '/' . ltrim($relative, '/');
}

/**
 * Fired after Head_VM approves a vendor.
 * Notifies:
 * - all active admin users via email and in-app bell
 * - vendor contact via email approval confirmation
 *
 * @return array {
 *     emailed: int,         Backward-compatible alias for emailed_admin.
 *     emailed_admin: int,
 *     emailed_vendor: int,
 *     inapp: int,
 *     failed: int
 * }
 */
function notifyAdminsOnVendorApproval(
    int $triggeredByUserId,
    int $vendorId,
    string $vendorName,
    string $pdfPath
): array {
    global $conn;

    $stats = [
        'emailed'        => 0,
        'emailed_admin'  => 0,
        'inapp'          => 0,
        'failed'         => 0,
    ];

    if (!($conn instanceof mysqli)) {
        error_log("[notify] \$conn not available - skipping approval notifications.");
        return $stats;
    }

    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $vendorName);
    $payload  = json_encode(['vendor_name' => $vendorName, 'pdf' => $pdfPath]);
    $subject  = "[VSS] Vendor Disetujui - Daftarkan di K2: {$vendorName}";
    $inappMsg = "Vendor \"{$vendorName}\" telah disetujui oleh Head Vendor Management dan membutuhkan pendaftaran di K2.";

    $admins = [];
    $res = $conn->query("
        SELECT id, email, full_name
        FROM users
        WHERE role = 'admin' AND is_active = 1
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $admins[] = $row;
        }
    }

    $vendorEmail = '';
    $vendorContact = $vendorName;
    $vendorStmt = $conn->prepare("SELECT email, contact_person FROM vendors WHERE id = ? LIMIT 1");
    if ($vendorStmt) {
        $vendorStmt->bind_param("i", $vendorId);
        if ($vendorStmt->execute()) {
            $vendorResult = $vendorStmt->get_result();
            if ($vendorResult && ($vendor = $vendorResult->fetch_assoc())) {
                $vendorEmail   = $vendor['email'] ?? '';
                $vendorContact = $vendor['contact_person'] ?? $vendorName;
            }
        } else {
            error_log("[notify] Vendor lookup failed for vendor #{$vendorId}: " . $vendorStmt->error);
        }
    } else {
        error_log("[notify] Vendor lookup prepare failed for vendor #{$vendorId}: " . $conn->error);
    }

    foreach ($admins as $admin) {
        $recipientId   = (int)$admin['id'];
        $recipientName = $admin['full_name'] ?? '';
        $recipientMail = $admin['email'] ?? '';

        $logStmt = $conn->prepare("
            INSERT INTO notifications
                (type, triggered_by, vendor_id, recipient_id, channel, status, message, payload)
            VALUES
                ('vendor_approved', ?, ?, ?, 'email', 'queued', ?, ?)
        ");
        if (!$logStmt) {
            error_log("[notify] EMAIL prepare failed for vendor #{$vendorId}: " . $conn->error);
            $stats['failed']++;
            continue;
        }

        $logStmt->bind_param("iiiss", $triggeredByUserId, $vendorId, $recipientId, $subject, $payload);
        if (!$logStmt->execute()) {
            error_log("[notify] EMAIL insert failed for admin #{$recipientId}: " . $logStmt->error);
            $stats['failed']++;
            continue;
        }
        $emailNotifId = (int)$conn->insert_id;

        $sent = false;
        if (!empty($recipientMail) && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
            $mail = _buildMailer();
            $mail->addAddress($recipientMail, $recipientName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = "
                <p>Halo " . htmlspecialchars($recipientName) . ",</p>
                <p>Vendor <strong>" . htmlspecialchars($vendorName) . "</strong>
                   - Email: <a href='mailto:" . htmlspecialchars($vendorEmail) . "'>" . htmlspecialchars($vendorEmail) . "</a></p>
                <p>Vendor tersebut telah disetujui oleh Head Vendor Management dan
                   <strong>membutuhkan pendaftaran di sistem K2</strong>
                   sebagai tahap akhir onboarding.</p>
                <p>SLA: <strong>3 hari kerja</strong> sejak email ini dikirim.</p>
                <p>Surat penerimaan (PQ Result) terlampir untuk dokumentasi Anda.</p>
                <p>Silakan login ke dashboard admin untuk memulai proses registrasi K2.</p>
                <br><p>Terima kasih,<br><em>Sistem VSS - Kawan Lama Group</em></p>
            ";
            $mail->AltBody = "Vendor \"{$vendorName}\" telah disetujui dan menunggu pendaftaran di K2. SLA: 3 hari kerja.";

            if (!empty($pdfPath) && file_exists($pdfPath)) {
                $mail->addAttachment($pdfPath, "Surat_Penerimaan_{$safeName}.pdf");
            }

            $sent = _sendMail($mail);
            if ($sent) {
                $stats['emailed']++;
                $stats['emailed_admin']++;
            }
        }

        $newStatus = $sent ? 'sent' : 'failed';
        $sentAt = $sent ? date('Y-m-d H:i:s') : null;
        $upd = $conn->prepare("UPDATE notifications SET status = ?, sent_at = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param("ssi", $newStatus, $sentAt, $emailNotifId);
            $upd->execute();
        } else {
            error_log("[notify] EMAIL status update prepare failed for notification #{$emailNotifId}: " . $conn->error);
        }

        if (!$sent) {
            $stats['failed']++;
        }

        $inappStmt = $conn->prepare("
            INSERT INTO notifications
                (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, payload, sent_at)
            VALUES
                ('vendor_approved', ?, ?, ?, 'inapp', 'sent', 0, ?, ?, NOW())
        ");
        if (!$inappStmt) {
            error_log("[notify] INAPP prepare failed for vendor #{$vendorId}: " . $conn->error);
            $stats['failed']++;
            continue;
        }

        $inappStmt->bind_param("iiiss", $triggeredByUserId, $vendorId, $recipientId, $inappMsg, $payload);
        if ($inappStmt->execute()) {
            $stats['inapp']++;
        } else {
            error_log("[notify] INAPP insert failed for admin #{$recipientId}: " . $inappStmt->error);
            $stats['failed']++;
        }
    }

    error_log(sprintf(
        "[notify] Vendor #%d (%s): emailed_admin=%d, inapp=%d, failed=%d",
        $vendorId,
        $vendorName,
        $stats['emailed_admin'],
        $stats['inapp'],
        $stats['failed']
    ));

    return $stats;
}

/**
 * Fired after admin inputs vendor code (finish_k2).
 * Notifies sourcing requestor via email that vendor is fully registered in K2.
 */
function notifyOnK2Registered(
    int $adminId,
    int $vendorId,
    string $vendorName,
    string $vendorCode,
    int $sourcingUserId
): array {
    global $conn;

    $stats = ['emailed_sourcing' => 0, 'emailed_vendor' => 0, 'failed' => 0];
    if (!($conn instanceof mysqli)) {
        return $stats;
    }

    // --- Lookup sourcing user ---
    $sEmail = '';
    $sName  = 'Tim Sourcing';
    if ($sourcingUserId > 0) {
        $sourcingStmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
        if (!$sourcingStmt) {
            error_log("[notify] Sourcing lookup prepare failed for user #{$sourcingUserId}: " . $conn->error);
            $stats['failed']++;
        } else {
            $sourcingStmt->bind_param("i", $sourcingUserId);
            if (!$sourcingStmt->execute()) {
                error_log("[notify] Sourcing lookup failed for user #{$sourcingUserId}: " . $sourcingStmt->error);
                $stats['failed']++;
            } else {
                $sourcingResult = $sourcingStmt->get_result();
                if ($sourcingResult && ($sourcing = $sourcingResult->fetch_assoc())) {
                    $sEmail = $sourcing['email'] ?? '';
                    $sName  = $sourcing['full_name'] ?? 'Tim Sourcing';
                }
            }
        }
    }

    // --- Lookup vendor email ---
    $vendorEmail   = '';
    $vendorContact = $vendorName;
    $vendorStmt = $conn->prepare("SELECT email, contact_person FROM vendors WHERE id = ? LIMIT 1");
    if (!$vendorStmt) {
        error_log("[notify] Vendor lookup prepare failed for vendor #{$vendorId}: " . $conn->error);
        $stats['failed']++;
    } else {
        $vendorStmt->bind_param("i", $vendorId);
        if ($vendorStmt->execute()) {
            $vendorResult = $vendorStmt->get_result();
            if ($vendorResult && ($vendor = $vendorResult->fetch_assoc())) {
                $vendorEmail   = $vendor['email'] ?? '';
                $vendorContact = $vendor['contact_person'] ?? $vendorName;
            }
        } else {
            error_log("[notify] Vendor lookup failed for vendor #{$vendorId}: " . $vendorStmt->error);
            $stats['failed']++;
        }
    }

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        return $stats;
    }

    // --- Email sourcing user ---
    if (!empty($sEmail)) {
        $mail = _buildMailer();
        $mail->addAddress($sEmail, $sName);
        $mail->isHTML(true);
        $mail->Subject = "[VSS] Vendor Code Ditetapkan - {$vendorName}";
        $mail->Body = "
            <p>Halo " . htmlspecialchars($sName) . ",</p>
            <p>Vendor yang Anda ajukan, <strong>" . htmlspecialchars($vendorName) . "</strong>,
               telah berhasil didaftarkan ke sistem K2 oleh tim Admin.</p>
            <table style='border-collapse:collapse;margin:16px 0;'>
                <tr>
                    <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Vendor Name</td>
                    <td style='padding:8px 14px;border:1px solid #dee2e6;'>" . htmlspecialchars($vendorName) . "</td>
                </tr>
                <tr>
                    <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Vendor Code</td>
                    <td style='padding:8px 14px;border:1px solid #dee2e6;font-family:monospace;font-size:1.1em;color:#198754;'>
                        <strong>" . htmlspecialchars($vendorCode) . "</strong>
                    </td>
                </tr>
                <tr>
                    <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Vendor Email</td>
                    <td style='padding:8px 14px;border:1px solid #dee2e6;font-family:monospace;font-size:1.1em;color:#198754;'>
                        <strong>" . htmlspecialchars($vendorEmail) . "</strong>
                    </td>
                </tr>
            </table>
            <p>Proses onboarding vendor ini telah selesai. Vendor Code dapat digunakan untuk keperluan transaksi pengadaan.</p>
            <br><p>Terima kasih,<br><em>Sistem VSS - Kawan Lama Group</em></p>
        ";
        $mail->AltBody = "Vendor \"{$vendorName}\" telah didaftarkan ke K2. Vendor Code: {$vendorCode}.";

        $sent = _sendMail($mail);
        if ($sent) {
            $stats['emailed_sourcing']++;
            $msg = "Vendor \"{$vendorName}\" telah didaftarkan ke K2 dengan Vendor Code: {$vendorCode}.";
            $notifStmt = $conn->prepare("
                INSERT INTO notifications
                    (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, sent_at)
                VALUES
                    ('k2_registered', ?, ?, ?, 'email', 'sent', 0, ?, NOW())
            ");
            if ($notifStmt) {
                $notifStmt->bind_param("iiis", $adminId, $vendorId, $sourcingUserId, $msg);
                if (!$notifStmt->execute()) {
                    error_log("[notify] K2 registered sourcing log failed for vendor #{$vendorId}: " . $notifStmt->error);
                }
            } else {
                error_log("[notify] K2 registered sourcing log prepare failed for vendor #{$vendorId}: " . $conn->error);
            }
        } else {
            $stats['failed']++;
        }
    }

    // --- Email vendor ---
    if (!empty($vendorEmail)) {
        $mail = _buildMailer();
        $mail->addAddress($vendorEmail, $vendorContact);
        $mail->isHTML(true);
        $mail->Subject = "[VSS] Vendor Code Anda Telah Ditetapkan - {$vendorName}";
        $mail->Body = "
            <p>Yth. " . htmlspecialchars($vendorContact) . ",</p>
            <p>Kami dengan senang hati memberitahukan bahwa proses pendaftaran vendor
               <strong>" . htmlspecialchars($vendorName) . "</strong>
               ke sistem K2 telah <strong>selesai</strong>.</p>
            <table style='border-collapse:collapse;margin:16px 0;'>
                <tr>
                    <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Vendor Name</td>
                    <td style='padding:8px 14px;border:1px solid #dee2e6;'>" . htmlspecialchars($vendorName) . "</td>
                </tr>
                <tr>
                    <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Vendor Code</td>
                    <td style='padding:8px 14px;border:1px solid #dee2e6;font-family:monospace;font-size:1.1em;color:#198754;'>
                        <strong>" . htmlspecialchars($vendorCode) . "</strong>
                    </td>
                </tr>
                 <tr>
                    <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Vendor Email</td>
                    <td style='padding:8px 14px;border:1px solid #dee2e6;font-family:monospace;font-size:1.1em;color:#198754;'>
                        <strong>" . htmlspecialchars($vendorEmail) . "</strong>
                    </td>
                </tr>
            </table>
            <p>Vendor Code ini dapat digunakan untuk keperluan transaksi pengadaan bersama Kawan Lama Group.</p>
            <p>Jika Anda memiliki pertanyaan, silakan hubungi tim Corporate Procurement kami.</p>
            <br><p>Terima kasih atas kepercayaan Anda,<br>
            <em>Tim Corporate Procurement - Kawan Lama Group</em></p>
        ";
        $mail->AltBody = "Vendor Code Anda ({$vendorCode}) untuk \"{$vendorName}\" telah ditetapkan di sistem K2.";

        $sent = _sendMail($mail);
        if ($sent) {
            $stats['emailed_vendor']++;
            $msg = "Vendor Code \"{$vendorCode}\" telah ditetapkan untuk vendor \"{$vendorName}\" di sistem K2.";
            $vendorNotifStmt = $conn->prepare("
                INSERT INTO notifications
                    (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, sent_at)
                VALUES
                    ('k2_registered', ?, ?, 0, 'email', 'sent', 0, ?, NOW())
            ");
            if ($vendorNotifStmt) {
                $vendorNotifStmt->bind_param("iis", $adminId, $vendorId, $msg);
                if (!$vendorNotifStmt->execute()) {
                    error_log("[notify] K2 registered vendor log failed for vendor #{$vendorId}: " . $vendorNotifStmt->error);
                }
            } else {
                error_log("[notify] K2 registered vendor log prepare failed for vendor #{$vendorId}: " . $conn->error);
            }
        } else {
            $stats['failed']++;
        }
    }

    error_log(sprintf(
        "[notify] K2 finish vendor #%d (%s) code=%s: emailed_sourcing=%d, emailed_vendor=%d, failed=%d",
        $vendorId,
        $vendorName,
        $vendorCode,
        $stats['emailed_sourcing'],
        $stats['emailed_vendor'],
        $stats['failed']
    ));

    return $stats;
}

/**
 * Fired when a PQ submission is returned to the vendor for revision,
 * by either Sourcing or Vendor Management (VM).
 *
 * - Always emails the vendor the revision note so they know to revise & resubmit.
 * - When $source === 'vm', additionally emails and in-app notifies the Sourcing
 *   reviewer ($sourcingUserId) that the vendor they handled was returned by VM.
 *
 * Every send is logged into the notifications table for auditability, matching
 * the pattern used by the other notify* helpers.
 *
 * @param int    $triggeredByUserId  User who performed the return (VM/sourcing).
 * @param int    $vendorId
 * @param string $vendorName
 * @param string $revisionNote       Clean, vendor-facing note (no "; nama" suffix).
 * @param string $source             'sourcing' | 'vm'
 * @param int    $sourcingUserId     Sourcing reviewer to inform; only used when
 *                                   $source === 'vm'. 0 = skip.
 * @return array { emailed_vendor:int, emailed_sourcing:int, inapp:int, failed:int }
 */
function notifyOnVendorRevision(
    int $triggeredByUserId,
    int $vendorId,
    string $vendorName,
    string $revisionNote,
    string $source,
    int $sourcingUserId = 0
): array {
    global $conn;

    $stats = ['emailed_vendor' => 0, 'emailed_sourcing' => 0, 'inapp' => 0, 'failed' => 0];
    if (!($conn instanceof mysqli)) {
        error_log("[notify] \$conn not available - skipping revision notifications.");
        return $stats;
    }

    $sourceLabel = ($source === 'vm') ? 'Tim Vendor Management' : 'Tim Sourcing';
    $noteHtml    = nl2br(htmlspecialchars($revisionNote));
    $hasMailer   = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

    // --- Lookup vendor email ---
    $vendorEmail             = '';
    $vendorContact           = $vendorName;
    $vendorIsDirectRegistered = false;
    $vendorStmt = $conn->prepare("SELECT email, contact_person, registered_by_sourcing_id FROM vendors WHERE id = ? LIMIT 1");
    if ($vendorStmt) {
        $vendorStmt->bind_param("i", $vendorId);
        if ($vendorStmt->execute()) {
            $vres = $vendorStmt->get_result();
            if ($vres && ($v = $vres->fetch_assoc())) {
                $vendorEmail              = $v['email'] ?? '';
                $vendorContact            = $v['contact_person'] ?? $vendorName;
                $vendorIsDirectRegistered = !empty($v['registered_by_sourcing_id']);
            }
        } else {
            error_log("[notify] Vendor lookup failed for vendor #{$vendorId}: " . $vendorStmt->error);
        }
    }

    // --- Email the vendor ---
    if ($hasMailer && !empty($vendorEmail)) {
        $subject = "[VSS] Pengajuan Anda Membutuhkan Revisi - {$vendorName}";
        $mail = _buildMailer();
        $mail->addAddress($vendorEmail, $vendorContact);
        $mail->isHTML(true);
        $mail->Subject = $subject;

        // A vendor registered directly by Sourcing (Direct Registration) never
        // set their own password — pointing them at a generic "please log in"
        // is a dead end. Send them straight to "Lupa Kata Sandi" instead, with
        // their email pre-filled, so they can actually get in and fix things.
        if ($vendorIsDirectRegistered) {
            $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
            $domainName = $_SERVER['HTTP_HOST'] ?? '';
            $reset_link = $protocol . $domainName . '/forgot_password.php?email=' . urlencode($vendorEmail);
            $loginInstruction = '<p>Karena pendaftaran perusahaan Anda dibantu oleh Tim Procurement, silakan klik tautan berikut untuk membuat kata sandi baru sebelum masuk ke portal:</p>
                <p><a href="' . $reset_link . '" style="display:inline-block;padding:10px 20px;color:#fff;background-color:#0d6efd;text-decoration:none;border-radius:5px;">Buat Kata Sandi Baru</a></p>';
        } else {
            $loginInstruction = '<p>Mohon login ke portal, perbaiki bagian yang diminta, lalu kirim ulang pengajuan Anda.</p>';
        }

        $mail->Body = "
            <p>Yth. " . htmlspecialchars($vendorContact) . ",</p>
            <p>Pengajuan Pra-Kualifikasi untuk <strong>" . htmlspecialchars($vendorName) . "</strong>
               telah ditinjau oleh " . htmlspecialchars($sourceLabel) . " dan
               <strong>membutuhkan revisi</strong> sebelum dapat diproses lebih lanjut.</p>
            <p><strong>Catatan dari Tim Procurement:</strong></p>
            <div style='padding:12px 16px;background:#fff8e1;border-left:4px solid #fd7e14;margin:12px 0;'>
                {$noteHtml}
            </div>
            {$loginInstruction}
            <br><p>Terima kasih,<br><em>Tim Corporate Procurement - Kawan Lama Group</em></p>
        ";
        $mail->AltBody = "Pengajuan \"{$vendorName}\" membutuhkan revisi. Catatan: {$revisionNote}";

        $sent        = _sendMail($mail);
        $notifStatus = $sent ? 'sent' : 'failed';
        $sentAt      = $sent ? date('Y-m-d H:i:s') : null;
        if ($sent) { $stats['emailed_vendor']++; } else { $stats['failed']++; }

        $msg = "Pengajuan \"{$vendorName}\" dikembalikan oleh {$sourceLabel} untuk revisi.";
        $log = $conn->prepare("
            INSERT INTO notifications
                (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, sent_at)
            VALUES
                ('vendor_revision', ?, ?, 0, 'email', ?, 0, ?, ?)
        ");
        if ($log) {
            $log->bind_param("iisss", $triggeredByUserId, $vendorId, $notifStatus, $msg, $sentAt);
            $log->execute();
        }
    } elseif (empty($vendorEmail)) {
        error_log("[notify] Revision: vendor #{$vendorId} has no email - vendor mail skipped.");
    }

    // --- When VM returns: inform the Sourcing reviewer ---
    if ($source === 'vm' && $sourcingUserId > 0) {
        $sEmail = '';
        $sName  = 'Tim Sourcing';
        $sStmt = $conn->prepare("SELECT email, full_name FROM users WHERE id = ? LIMIT 1");
        if ($sStmt) {
            $sStmt->bind_param("i", $sourcingUserId);
            if ($sStmt->execute()) {
                $sres = $sStmt->get_result();
                if ($sres && ($s = $sres->fetch_assoc())) {
                    $sEmail = $s['email'] ?? '';
                    $sName  = $s['full_name'] ?? 'Tim Sourcing';
                }
            }
        }

        // In-app bell notification for the sourcing reviewer
        $inappMsg = "Vendor \"{$vendorName}\" dikembalikan oleh Tim Vendor Management ke vendor untuk revisi.";
        $inapp = $conn->prepare("
            INSERT INTO notifications
                (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, sent_at)
            VALUES
                ('vendor_revision', ?, ?, ?, 'inapp', 'sent', 0, ?, NOW())
        ");
        if ($inapp) {
            $inapp->bind_param("iiis", $triggeredByUserId, $vendorId, $sourcingUserId, $inappMsg);
            if ($inapp->execute()) { $stats['inapp']++; } else { $stats['failed']++; }
        }

        // Email the sourcing reviewer
        if ($hasMailer && !empty($sEmail)) {
            $subject = "[VSS] Vendor Dikembalikan oleh VM - {$vendorName}";
            $mail = _buildMailer();
            $mail->addAddress($sEmail, $sName);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = "
                <p>Halo " . htmlspecialchars($sName) . ",</p>
                <p>Vendor <strong>" . htmlspecialchars($vendorName) . "</strong>
                   telah dikembalikan oleh <strong>Tim Vendor Management</strong> langsung ke vendor untuk revisi.</p>
                <p><strong>Catatan yang dikirim ke vendor:</strong></p>
                <div style='padding:12px 16px;background:#fff8e1;border-left:4px solid #fd7e14;margin:12px 0;'>
                    {$noteHtml}
                </div>
                <p>Informasi ini dikirim agar Anda mengetahui status terbaru vendor tersebut.</p>
                <br><p>Terima kasih,<br><em>Sistem VSS - Kawan Lama Group</em></p>
            ";
            $mail->AltBody = "Vendor \"{$vendorName}\" dikembalikan oleh VM ke vendor untuk revisi. Catatan: {$revisionNote}";

            $sent        = _sendMail($mail);
            $notifStatus = $sent ? 'sent' : 'failed';
            $sentAt      = $sent ? date('Y-m-d H:i:s') : null;
            if ($sent) { $stats['emailed_sourcing']++; } else { $stats['failed']++; }

            $emsg = "Vendor \"{$vendorName}\" dikembalikan oleh VM ke vendor untuk revisi.";
            $elog = $conn->prepare("
                INSERT INTO notifications
                    (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, sent_at)
                VALUES
                    ('vendor_revision', ?, ?, ?, 'email', ?, 0, ?, ?)
            ");
            if ($elog) {
                $elog->bind_param("iiisss", $triggeredByUserId, $vendorId, $sourcingUserId, $notifStatus, $emsg, $sentAt);
                $elog->execute();
            }
        }
    }

    error_log(sprintf(
        "[notify] Revision vendor #%d (%s) source=%s: emailed_vendor=%d, emailed_sourcing=%d, inapp=%d, failed=%d",
        $vendorId, $vendorName, $source,
        $stats['emailed_vendor'], $stats['emailed_sourcing'], $stats['inapp'], $stats['failed']
    ));

    return $stats;
}

// ─────────────────────────────────────────────────────────────────────────────
// EXCEPTION REQUEST notifications (in-app bell only). These mirror the lightweight
// in-app inserts used elsewhere (channel='inapp', status='sent', is_read=0).
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Low-level helper: insert one in-app notification row.
 */
function _insertInappNotif(
    string $type,
    int $triggeredBy,
    int $vendorId,
    int $recipientId,
    string $message
): bool {
    global $conn;
    if (!($conn instanceof mysqli) || $recipientId <= 0) {
        return false;
    }
    $stmt = $conn->prepare("
        INSERT INTO notifications
            (type, triggered_by, vendor_id, recipient_id, channel, status, is_read, message, sent_at)
        VALUES
            (?, ?, ?, ?, 'inapp', 'sent', 0, ?, NOW())
    ");
    if (!$stmt) {
        error_log("[notify] exception-request inapp prepare failed: " . $conn->error);
        return false;
    }
    $stmt->bind_param("siiis", $type, $triggeredBy, $vendorId, $recipientId, $message);
    if (!$stmt->execute()) {
        error_log("[notify] exception-request inapp insert failed: " . $stmt->error);
        return false;
    }
    return true;
}

/**
 * Sourcing submitted a Exception Request request → notify all active VM Head users
 * via in-app bell and email.
 */
function notifyVmHeadOnExceptionRequest(
    int $sourcingId,
    int $vendorId,
    string $vendorName,
    string $reason = '',
    string $notes = ''
): int
{
    global $conn;
    if (!($conn instanceof mysqli)) return 0;

    $msg     = "Pengajuan Exception Request untuk vendor \"{$vendorName}\" menunggu persetujuan Anda (VM Head).";
    $subject = "[VSS] Exception Request Baru - Menunggu Persetujuan: {$vendorName}";

    $sourcingName = 'Tim Sourcing';
    $sStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
    if ($sStmt) {
        $sStmt->bind_param("i", $sourcingId);
        if ($sStmt->execute()) {
            $sres = $sStmt->get_result();
            if ($sres && ($s = $sres->fetch_assoc()) && !empty($s['full_name'])) {
                $sourcingName = $s['full_name'];
            }
        }
    }

    $reasonHtml = htmlspecialchars($reason);
    $notesHtml  = nl2br(htmlspecialchars($notes));
    $hasMailer  = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

    $sent = 0;
    $res = $conn->query("SELECT id, email, full_name FROM users WHERE role = 'vendormgt_head' AND is_active = 1");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recipientId = (int)$row['id'];
            if (_insertInappNotif('exception_request_submitted', $sourcingId, $vendorId, $recipientId, $msg)) {
                $sent++;
            }

            if ($hasMailer && !empty($row['email'])) {
                $mail = _buildMailer();
                $mail->addAddress($row['email'], $row['full_name'] ?? '');
                $mail->isHTML(true);
                $mail->Subject = $subject;

                // Direct link (button) to the Exception Request review page for this
                // vendor. Falls back to a plain instruction when no HTTP context is
                // available to build an absolute URL (see _appUrl()).
                $reviewUrl  = _appUrl('modules/vendormgt/exception_request_review.php?id=' . $vendorId);
                $reviewHtml = $reviewUrl !== ''
                    ? "<p style='margin:22px 0;'>
                           <a href='" . htmlspecialchars($reviewUrl) . "'
                              style='display:inline-block;background:#0d6efd;color:#ffffff;text-decoration:none;
                                     font-weight:bold;padding:12px 26px;border-radius:6px;'>
                               Tinjau Exception Request
                           </a>
                       </p>
                       <p style='font-size:12px;color:#6c757d;'>Jika tombol tidak berfungsi, salin tautan ini ke browser:<br>
                           <a href='" . htmlspecialchars($reviewUrl) . "'>" . htmlspecialchars($reviewUrl) . "</a></p>"
                    : "<p>Silakan login ke dashboard Vendor Management untuk meninjau dan memproses pengajuan ini.</p>";

                $mail->Body = "
                    <p>Halo " . htmlspecialchars($row['full_name'] ?? '') . ",</p>
                    <p>Sourcing (<strong>" . htmlspecialchars($sourcingName) . "</strong>) telah mengajukan
                       <strong>Exception Request</strong> untuk vendor <strong>" . htmlspecialchars($vendorName) . "</strong>,
                       yang memungkinkan vendor didaftarkan ke K2 tanpa menunggu proses Pre-Qualification.</p>
                    <table style='border-collapse:collapse;margin:16px 0;'>
                        <tr>
                            <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;'>Kondisi Vendor</td>
                            <td style='padding:8px 14px;border:1px solid #dee2e6;'>{$reasonHtml}</td>
                        </tr>
                        <tr>
                            <td style='padding:8px 14px;background:#f8f9fa;border:1px solid #dee2e6;font-weight:bold;vertical-align:top;'>Catatan Sourcing</td>
                            <td style='padding:8px 14px;border:1px solid #dee2e6;'>{$notesHtml}</td>
                        </tr>
                    </table>
                    <p>Pengajuan ini <strong>menunggu persetujuan Anda</strong> selaku VM Head.</p>
                    {$reviewHtml}
                    <br><p>Terima kasih,<br><em>Sistem VSS - Kawan Lama Group</em></p>
                ";
                $mail->AltBody = "Exception Request untuk vendor \"{$vendorName}\" menunggu persetujuan Anda. Kondisi: {$reason}. Catatan: {$notes}";
                _sendMail($mail);
            }
        }
    }
    return $sent;
}

/**
 * VM Head approved a Exception Request request → notify all active admin users that the
 * vendor can be registered in K2 WITHOUT waiting for PQ.
 */
function notifyAdminsOnExceptionRequestApproved(int $vmHeadId, int $vendorId, string $vendorName, string $pdfPath = ''): int
{
    global $conn;
    if (!($conn instanceof mysqli)) return 0;

    $msg = "Vendor \"{$vendorName}\" disetujui via EXCEPTION REQUEST (tanpa PQ) dan siap didaftarkan ke K2.";
    $subject = "[VSS] Vendor Disetujui (Exception Request) - Daftarkan di K2: {$vendorName}";
    $safeName = preg_replace('/[^A-Za-z0-9_\-]/', '_', $vendorName);

    $sent = 0;
    $res = $conn->query("SELECT id, email, full_name FROM users WHERE role = 'admin' AND is_active = 1");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $recipientId = (int)$row['id'];
            if (_insertInappNotif('exception_request_approved', $vmHeadId, $vendorId, $recipientId, $msg)) {
                $sent++;
            }

            // Attach the Exception Request Approval PDF (stand-in for the PQ Result document,
            // since PQ has not been done yet) if one was generated.
            if (!empty($pdfPath) && file_exists($pdfPath) && !empty($row['email']) && class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
                $mail = _buildMailer();
                $mail->addAddress($row['email'], $row['full_name'] ?? '');
                $mail->isHTML(true);
                $mail->Subject = $subject;
                $mail->Body = "
                    <p>Halo " . htmlspecialchars($row['full_name'] ?? '') . ",</p>
                    <p>Vendor <strong>" . htmlspecialchars($vendorName) . "</strong> telah disetujui via <strong>Exception Request</strong>
                       (tanpa menunggu proses Pre-Qualification) dan <strong>membutuhkan pendaftaran di sistem K2</strong>.</p>
                    <p>Dokumen Exception Request Approval (sementara) terlampir untuk dokumentasi Anda. Dokumen PQ Result resmi
                       akan menyusul dan menggantikan dokumen ini setelah proses PQ vendor tersebut selesai.</p>
                    <p>Silakan login ke dashboard admin untuk memulai proses registrasi K2.</p>
                    <br><p>Terima kasih,<br><em>Sistem VSS - Kawan Lama Group</em></p>
                ";
                $mail->AltBody = $msg;
                $mail->addAttachment($pdfPath, "ExceptionRequest_Approval_{$safeName}.pdf");
                _sendMail($mail);
            }
        }
    }
    return $sent;
}

/**
 * VM Head rejected a Exception Request request → notify the Sourcing user who submitted it.
 */
function notifyOnExceptionRequestRejected(
    int $vmHeadId,
    int $vendorId,
    string $vendorName,
    string $reason,
    int $sourcingId
): bool {
    $msg = "Pengajuan Exception Request untuk vendor \"{$vendorName}\" ditolak oleh VM Head.";
    if (trim($reason) !== '') {
        $msg .= " Alasan: " . trim($reason);
    }
    return _insertInappNotif('exception_request_rejected', $vmHeadId, $vendorId, $sourcingId, $msg);
}
