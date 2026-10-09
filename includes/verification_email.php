<?php
// includes/verification_email.php
//
// Satu jalur pengiriman email verifikasi registrasi, dipakai oleh
// register_action.php (kiriman pertama) dan resend_verification.php
// (kirim ulang), supaya isi email dan tautannya tidak pernah berbeda.

require_once __DIR__ . '/smtp_config.php';
require_once __DIR__ . '/mail_rate_limiter.php';

if (!defined('VERIFICATION_RESEND_COOLDOWN_SEC')) {
    define('VERIFICATION_RESEND_COOLDOWN_SEC', 60);
}

/**
 * Kirim email verifikasi ke satu calon vendor. Mengembalikan false bila SMTP
 * gagal atau PHPMailer tidak tersedia — pemanggil yang memutuskan apa yang
 * ditampilkan ke pengguna.
 */
function send_vendor_verification_email(string $email, string $name, string $token, string $companyName = '', string $context = 'Registrasi vendor'): bool
{
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log('[verification] PHPMailer tidak tersedia; email verifikasi untuk ' . $email . ' tidak dikirim');
        return false;
    }

    // Transkrip percakapan SMTP, dilampirkan ke email alert bila pengiriman
    // gagal. Level dikunci di DEBUG_SERVER: mulai DEBUG_LOWLEVEL (4) PHPMailer
    // berhenti menyensor kredensial (SMTP.php::client_send).
    $transcript = [];

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->SMTPDebug = \PHPMailer\PHPMailer\SMTP::DEBUG_SERVER;
        $mail->Debugoutput = static function ($str) use (&$transcript) {
            $transcript[] = rtrim($str, "\r\n");
        };
        configureSmtp($mail);

        $mail->Timeout = 300;
        $mail->SMTPKeepAlive = true;

        $mail->setFrom(mail_smtp_config()['from_email'], 'Saber eProc Team');
        $mail->addAddress($email, $name !== '' ? $name : $email);

        // Copy procurement, sama seperti email undangan vendor.
        if (defined('REGISTRATION_BCC_RECIPIENTS')) {
            foreach (REGISTRATION_BCC_RECIPIENTS as $bcc) {
                $mail->addBCC($bcc);
            }
        }

        $mail->isHTML(true);

        $mail->Subject = 'Verifikasi Akun Rekanan Saber eProc'
            . ($companyName !== '' ? ' - ' . $companyName : '');
        $mail->Body = 'Halo ' . htmlspecialchars($name) . ',<br><br>Terima kasih telah mendaftar di platform <strong>Saber eProc</strong>. Akun Anda telah berhasil dibuat namun masih dalam status menunggu verifikasi.<br><br>Silakan klik tautan di bawah ini untuk memverifikasi alamat email dan mengaktifkan akun Anda:<br><br><a href="' . vendor_verification_link($email, $token) . '" style="display:inline-block;padding:12px 24px;color:#fff;background-color:#1e40af;text-decoration:none;border-radius:8px;font-weight:bold;">Verifikasi Email Saya</a><br><br>Atau salin dan tempel URL berikut ke peramban (browser) Anda:<br>' . vendor_verification_link($email, $token) . '<br><br>Salam hangat,<br><strong>Tim Saber eProc</strong>';

        if (!mail_rate_limited_send($mail, 'registration-verification')) {
            return false;
        }
        return true;
    } catch (\Exception $e) {
        error_log('[verification] Gagal mengirim email verifikasi ke ' . $email . ': ' . $e->getMessage());
        send_mail_failure_alert(
            $context,
            $email,
            $name,
            $e->getMessage(),
            isset($mail) ? (string) $mail->ErrorInfo : '',
            $transcript
        );
        return false;
    }
}

/**
 * Beri tahu tim bahwa satu email verifikasi gagal terkirim.
 *
 * Catatan penting: alert ini lewat SMTP yang sama dengan yang baru saja gagal,
 * jadi saat server benar-benar mati alert pun tidak akan sampai. Yang tertangkap
 * adalah kegagalan per-pesan — alamat ditolak, mailbox penuh, pesan di-reject.
 * error_log tetap menjadi jaring terakhir.
 */
function send_mail_failure_alert(
    string $context,
    string $recipient,
    string $recipientName,
    string $error,
    string $errorInfo,
    array $transcript
): void {
    // Satu alert per request: kalau SMTP sedang bermasalah, halaman ini tidak
    // ikut menghabiskan waktu mencoba mengirim alert berkali-kali.
    static $already_alerted = false;
    if ($already_alerted) {
        return;
    }
    $already_alerted = true;

    if (!defined('MAIL_FAILURE_ALERT_RECIPIENTS') || !class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return;
    }

    try {
        // Mailer baru tanpa hook debug — alert ini tidak boleh ikut merekam
        // dirinya sendiri, dan kegagalannya tidak boleh memanggil fungsi ini lagi.
        $alert = new \PHPMailer\PHPMailer\PHPMailer(true);
        configureSmtp($alert);
        $alert->Timeout = 20;
        $alert->CharSet = 'UTF-8';

        $alert->setFrom(mail_smtp_config()['from_email'], 'VSS Mail Monitor');
        foreach (MAIL_FAILURE_ALERT_RECIPIENTS as $to) {
            $alert->addAddress($to);
        }

        // Copy procurement, sama seperti email verifikasi yang gagal itu sendiri.
        if (defined('REGISTRATION_BCC_RECIPIENTS')) {
            foreach (REGISTRATION_BCC_RECIPIENTS as $bcc) {
                $alert->addBCC($bcc);
            }
        }

        // Transkrip dipotong dari belakang: kegagalan selalu ada di ujung.
        $tail = array_slice($transcript, -80);
        $transcript_block = $tail === []
            ? '(tidak ada transkrip SMTP — koneksi gagal sebelum sempat berbicara)'
            : htmlspecialchars(implode("\n", $tail));

        $alert->isHTML(true);
        $alert->Subject = '[VSS] GAGAL kirim email verifikasi — ' . $recipient;
        $alert->Body =
            '<p>Email verifikasi <strong>gagal terkirim</strong>.</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;font-family:monospace;font-size:13px;">'
            . '<tr><td><strong>Waktu</strong></td><td>' . date('Y-m-d H:i:s') . ' WIB</td></tr>'
            . '<tr><td><strong>Konteks</strong></td><td>' . htmlspecialchars($context) . '</td></tr>'
            . '<tr><td><strong>Tujuan</strong></td><td>' . htmlspecialchars($recipient) . '</td></tr>'
            . '<tr><td><strong>Nama</strong></td><td>' . htmlspecialchars($recipientName) . '</td></tr>'
            . '<tr><td><strong>Error</strong></td><td>' . htmlspecialchars($error) . '</td></tr>'
            . '<tr><td><strong>ErrorInfo</strong></td><td>' . htmlspecialchars($errorInfo) . '</td></tr>'
            . '</table>'
            . '<p><strong>Transkrip SMTP:</strong></p>'
            . '<pre style="background:#f4f4f4;padding:12px;border-radius:4px;font-size:12px;white-space:pre-wrap;">'
            . $transcript_block
            . '</pre>';

        mail_rate_limited_send($alert, 'registration-verification-failure-alert');
    } catch (\Exception $e) {
        error_log('[verification] Alert kegagalan email tidak bisa dikirim: ' . $e->getMessage());
    }
}

/**
 * Tautan verifikasi absolut. Dibangun dari request yang sedang berjalan,
 * jadi pemanggilnya harus berada di root aplikasi (register_action.php,
 * resend_verification.php) — sama seperti verify.php yang dituju.
 */
function vendor_verification_link(string $email, string $token): string
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'] ?? '';
    $base_dir = rtrim(dirname($_SERVER['PHP_SELF'] ?? '/'), '/\\');

    return $protocol . $domainName . $base_dir . '/verify.php?email=' . urlencode($email) . '&token=' . $token;
}

/**
 * Catat kapan email verifikasi terakhir dikirim. Kegagalan hanya dicatat ke
 * log: registrasi yang sudah berhasil tidak boleh gagal gara-gara stempel ini.
 */
function stamp_verification_sent(mysqli $conn, string $email): void
{
    try {
        $stmt = $conn->prepare("UPDATE users SET verification_sent_at = NOW() WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
    } catch (\Exception $e) {
        error_log('[verification] stamp verification_sent_at gagal untuk ' . $email . ': ' . $e->getMessage());
    }
}

/**
 * Sisa detik sebelum kirim ulang diizinkan. 0 berarti boleh kirim sekarang.
 */
function vendor_verification_resend_wait(?string $last_sent): int
{
    if ($last_sent === null || $last_sent === '' || $last_sent === '0000-00-00 00:00:00') {
        return 0;
    }

    $elapsed = time() - strtotime($last_sent);
    $remaining = (int) VERIFICATION_RESEND_COOLDOWN_SEC - $elapsed;

    return $remaining > 0 ? $remaining : 0;
}

/**
 * Kapan email verifikasi terakhir dikirim ke alamat ini, null bila belum
 * pernah atau kolomnya belum ada (instalasi lama sebelum migrasi jalan).
 */
function vendor_verification_last_sent(mysqli $conn, string $email): ?string
{
    try {
        $stmt = $conn->prepare("SELECT verification_sent_at FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        return $row['verification_sent_at'] ?? null;
    } catch (\Exception $e) {
        error_log('[verification] baca verification_sent_at gagal untuk ' . $email . ': ' . $e->getMessage());
        return null;
    }
}
