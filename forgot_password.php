<?php
session_start();
ob_start();
$page_title = "Lupa Kata Sandi | Saber eProc";
$path_to_root = "";            // this page lives at web root; header.php builds asset URLs from it
require 'config.php';          // must precede header.php — it consumes $conn
include 'includes/header.php';

require 'includes/smtp_config.php';
require 'includes/mail_rate_limiter.php';
use PHPMailer\PHPMailer\PHPMailer;

if (isset($_POST['forgot_btn'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    
    // Cek apakah email terdaftar
    $stmt = $conn->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();
        
        // Buat Token 
        $token = bin2hex(random_bytes(32));
        $expiry = date("Y-m-d H:i:s", strtotime('+1 hour'));

        $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE email = ?");
        $update->bind_param("sss", $token, $expiry, $email);
        $update->execute();

        // Kirim Email
        if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            try {
                $mail = new PHPMailer(true);
                configureSmtp($mail);

                $mail->setFrom(mail_smtp_config()['from_email'], 'Saber eProc Team');
                $mail->addAddress($email, $user['full_name']);
                $mail->isHTML(true);
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $domainName = $_SERVER['HTTP_HOST'];
                $base_dir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $reset_link = $protocol . $domainName . $base_dir . '/reset_password.php?token=' . $token;

                $mail->Subject = 'Reset Kata Sandi Saber eProc';
                $mail->Body = 'Halo ' . htmlspecialchars($user['full_name']) . ',<br><br>Kami menerima permintaan untuk mereset kata sandi akun Saber eProc Anda.<br>Silakan klik tautan di bawah ini (berlaku selama 1 jam):<br><br><a href="' . $reset_link . '" style="display:inline-block;padding:10px 20px;color:#fff;background-color:#1e40af;text-decoration:none;border-radius:8px;font-weight:bold;">Reset Kata Sandi</a><br><br>Jika Anda tidak meminta reset ini, abaikan saja email ini.<br><br>Salam hangat,<br><strong>Tim Saber eProc</strong>';
                
                mail_rate_limited_send($mail, 'forgot-password');
            } catch (Exception $e) { }
        }
    }
    
    // Selalu tunjukkan sukses (mencegah Hacker mengetahui email mana yang terdaftar)
    header("Location: forgot_password.php?success=1");
    exit();
}
ob_end_flush();
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm border rounded-4">
                <div class="card-body p-5 text-center">
                    <i class="fas fa-lock fa-4x text-primary mb-4 opacity-75"></i>
                    <h4 class="fw-bold mb-2">Lupa Kata Sandi?</h4>
                    
                    <?php if(isset($_GET['success'])): ?>
                        <div class="alert alert-success border-0 shadow-sm mt-4 text-start rounded-3">
                            <i class="fas fa-check-circle me-2"></i> Jika email terdaftar, tautan reset telah dikirim ke kotak masuk Anda.
                        </div>
                        <a href="login.php" class="btn btn-outline-primary rounded-3 mt-3 px-4 w-100">Kembali ke Halaman Masuk</a>
                    <?php else: ?>
                        <p class="text-muted small mb-4">Masukkan email akun Anda dan kami akan mengirimkan tautan untuk membuat kata sandi baru.</p>
                        <form action="" method="POST">
                            <div class="form-floating mb-4 text-start">
                                <input type="email" name="email" class="form-control rounded-3" id="floatingInput" placeholder="nama@email.com" value="<?= htmlspecialchars($_GET['email'] ?? '') ?>" required>
                                <label for="floatingInput">Alamat Email</label>
                            </div>
                            <button type="submit" name="forgot_btn" class="btn btn-primary rounded-3 w-100 fw-bold shadow-sm py-2">Kirim Tautan Reset</button>
                        </form>
                        <div class="mt-4"><a href="login.php" class="text-decoration-none small fw-bold"><i class="fas fa-arrow-left me-1"></i> Kembali untuk Masuk</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>