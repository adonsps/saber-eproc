<?php
// includes/smtp_config.php
// Satu-satunya tempat kredensial SMTP disimpan untuk aplikasi utama --
// dipakai oleh notification_helper.php, verification_email.php,
// pq_workflow_helper.php, dan forgot_password.php. Kredensial dibaca dari
// includes/mail_config.php (environment), bukan ditulis di sini.

$smtp_config_root = dirname(__DIR__);
if (file_exists($smtp_config_root . '/libs/PHPMailer/Exception.php')) {
    require_once $smtp_config_root . '/libs/PHPMailer/Exception.php';
    require_once $smtp_config_root . '/libs/PHPMailer/PHPMailer.php';
    require_once $smtp_config_root . '/libs/PHPMailer/SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/mail_config.php';

function configureSmtp(PHPMailer $mail): void
{
    $mail->isSMTP();
    $cfg = mail_smtp_config();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = $cfg['port'];
}
