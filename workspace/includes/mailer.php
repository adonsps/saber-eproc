<?php
// includes/mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Array of possible paths to check (Production, Dev exact, Dev lowercase, Dev Git name)
$possible_paths = [
    __DIR__ . '/../libs/PHPMailer/src/',
    __DIR__ . '/PHPMailer/src/',
    __DIR__ . '/phpmailer/src/',
    __DIR__ . '/PHPMailer-master/src/',
    __DIR__ . '/PHPMailer/',
    __DIR__ . '/phpmailer/'
];

$path = null;
foreach ($possible_paths as $p) {
    if (file_exists($p . 'PHPMailer.php')) {
        $path = $p;
        break;
    }
}

// Prevent Fatal Error by catching it early
if (!$path) {
    die("<div style='padding:20px; background:#ffebeb; color:#a10000; border:1px solid #dca7a7; border-radius:5px; font-family:sans-serif;'>
        <strong>PHPMailer Missing!</strong><br>
        The system cannot find the PHPMailer folder. Please check your Hostinger File Manager.<br>
        Ensure you uploaded the PHPMailer folder inside the <code>includes</code> directory, and that it contains a <code>src</code> folder.
        </div>");
}

require_once $path . 'Exception.php';
require_once $path . 'PHPMailer.php';
require_once $path . 'SMTP.php';

require_once dirname(__DIR__, 2) . '/includes/mail_rate_limiter.php';
require_once dirname(__DIR__, 2) . '/includes/mail_config.php';

/**
 * Fungsi untuk mengirim email tender
 */
function sendTenderEmail($to_email, $to_name, $subject, $message_body, $is_html = false, $cc = null) {
    $mail = new PHPMailer(true);

    try {
        // --- KONFIGURASI SMTP SERVER ---
        $mail->isSMTP();
        $cfg = mail_smtp_config();
        $mail->Host       = $cfg['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $cfg['port'];
        // PHPMailer defaults CharSet to ISO-8859-1, which mis-encodes the Subject header's
        // RFC 2047 =?...?= wrapper for any non-ASCII byte (e.g. an em dash) — the body can
        // look fine in some clients by luck while the Subject renders as mojibake. UTF-8
        // here fixes both header and body encoding for every caller of this function.
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;

        // --- PENGATURAN PENGIRIM & PENERIMA ---
        $mail->setFrom($cfg['from_email'], 'Corporate Procurement');
        $mail->addAddress($to_email, $to_name);

        // Optional CC (backward compatible: existing callers pass no $cc).
        // Accepts a single address or an array; only valid, non-empty,
        // non-duplicate addresses are added.
        if (!empty($cc)) {
            foreach ((array)$cc as $cc_addr) {
                $cc_addr = trim((string)$cc_addr);
                if ($cc_addr !== ''
                    && strcasecmp($cc_addr, $to_email) !== 0
                    && filter_var($cc_addr, FILTER_VALIDATE_EMAIL)) {
                    $mail->addCC($cc_addr);
                }
            }
        }

        // --- KONTEN EMAIL ---
        $mail->isHTML($is_html);
        $mail->Subject = $subject;
        $mail->Body    = $message_body;
        if ($is_html) {
            $mail->AltBody = strip_tags(str_replace(
                ['<br>', '<br/>', '<br />', '</p>', '</li>', '</tr>', '</td>'],
                "\n",
                $message_body
            ));
        }

        return mail_rate_limited_send($mail, 'tender-email');
    } catch (Exception $e) {
        $error_msg = "[" . date('Y-m-d H:i:s') . "] GAGAL kirim ke $to_email. Alasan: " . $mail->ErrorInfo . "\n";
        file_put_contents(__DIR__ . '/email_error_debug.txt', $error_msg, FILE_APPEND);
        return false;
    }
}
?>