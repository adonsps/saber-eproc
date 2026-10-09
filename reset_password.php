<?php
session_start();
ob_start();
$page_title = "Buat Kata Sandi Baru | Saber eProc";
$path_to_root = "";            // this page lives at web root; header.php builds asset URLs from it
require 'config.php';          // must precede header.php — it consumes $conn
include 'includes/header.php';

$error = "";
$success = false;

if (!isset($_GET['token']) && !isset($_POST['reset_submit'])) {
    die("<div class='container mt-5 alert alert-danger'>Akses ditolak. Token tidak ditemukan.</div>");
}

$token = isset($_GET['token']) ? mysqli_real_escape_string($conn, $_GET['token']) : mysqli_real_escape_string($conn, $_POST['token']);

// Verifikasi Token
$stmt = $conn->prepare("SELECT id, email, reset_expires FROM users WHERE reset_token = ? LIMIT 1");
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $error = "Tautan tidak valid atau sudah digunakan.";
} else {
    $user = $result->fetch_assoc();
    if (strtotime($user['reset_expires']) < time()) {
        $error = "Tautan ini sudah kedaluwarsa. Silakan minta tautan baru.";
    }
}

// Proses Ganti Password
if (isset($_POST['reset_submit']) && empty($error)) {
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if ($password !== $confirm_password) {
        $error = "Kata sandi dan konfirmasi tidak cocok.";
    } elseif (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[\W_]/', $password)) {
        $error = "Kata sandi tidak memenuhi syarat keamanan.";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $update = $conn->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
        $update->bind_param("si", $hashed, $user['id']);
        $update->execute();

        // A vendor Contact Person invited via "Kelola Narahubung" starts as
        // contact_status='invited' / is_active=0 and activates HERE: reaching this
        // point means they opened a link that only their inbox received, which is
        // the same proof the registration flow's verification mail gives.
        // Scoped to contact_status='invited' so no other account type is affected.
        try {
            $activate = $conn->prepare(
                "UPDATE users
                    SET contact_status = 'active', is_active = 1, verified_at = NOW()
                  WHERE id = ? AND contact_status = 'invited'"
            );
            $activate->bind_param("i", $user['id']);
            $activate->execute();
        } catch (mysqli_sql_exception $e) {
            // Pre-multi-CP schema (no contact_status column): nothing to activate.
            error_log("[reset_password] CP activation skipped: " . $e->getMessage());
        }

        $success = true;
    }
}
ob_end_flush();
?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-5">
            <div class="card shadow-sm border-0 rounded-4">
                <div class="card-body p-4 p-md-5">
                    
                    <?php if ($success): ?>
                        <div class="text-center">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h4 class="fw-bold mb-3">Berhasil!</h4>
                            <p class="text-muted mb-4">Kata sandi Anda telah berhasil diubah. Silakan masuk menggunakan kata sandi baru Anda.</p>
                            <a href="login.php" class="btn btn-primary rounded-3 w-100 fw-bold shadow-sm">Masuk Sekarang</a>
                        </div>
                    <?php elseif (!empty($error)): ?>
                        <div class="text-center">
                            <i class="fas fa-times-circle fa-4x text-danger mb-3"></i>
                            <h5 class="fw-bold text-danger mb-3">Terjadi Kesalahan</h5>
                            <p class="text-dark"><?= htmlspecialchars($error) ?></p>
                            <a href="forgot_password.php" class="btn btn-outline-danger rounded-3 mt-3 px-4">Kirim Tautan Baru</a>
                        </div>
                    <?php else: ?>
                        <h4 class="fw-bold mb-1 text-center">Buat Sandi Baru</h4>
                        <p class="text-muted small text-center mb-4">Silakan buat kata sandi baru untuk akun Anda.</p>
                        
                        <form action="" method="POST" id="resetForm">
                            <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                            
                            <div class="mb-3 position-relative">
                                <label class="form-label small fw-bold text-muted">Kata Sandi Baru</label>
                                <input type="password" name="password" id="regPassword" class="form-control rounded-3" required>
                            </div>

                            <ul class="list-unstyled small mb-3 text-muted" style="font-size: 0.75rem;">
                                <li id="reqLength" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 8 karakter</li>
                                <li id="reqUpper" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 1 huruf besar (A-Z)</li>
                                <li id="reqNumber" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 1 angka (0-9)</li>
                                <li id="reqSymbol" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 1 simbol (!@#$%^&*)</li>
                            </ul>

                            <div class="mb-4 position-relative">
                                <label class="form-label small fw-bold text-muted">Konfirmasi Kata Sandi Baru</label>
                                <input type="password" name="confirm_password" id="regConfirmPassword" class="form-control rounded-3" required>
                                <div id="passwordError" class="form-text text-danger d-none mt-1 fw-bold">Kata sandi tidak cocok!</div>
                            </div>
                            
                            <button type="submit" name="reset_submit" id="btnSubmit" class="btn btn-primary rounded-3 w-100 fw-bold shadow-sm" disabled>Simpan Kata Sandi</button>
                        </form>
                        
                        <script>
                            // Script validasi yang sama dengan register.php
                            const pwd = document.getElementById('regPassword');
                            const cpwd = document.getElementById('regConfirmPassword');
                            const btn = document.getElementById('btnSubmit');
                            
                            function validate() {
                                const val = pwd.value; const cVal = cpwd.value; let isValid = true;
                                const setValid = (id) => { let el = document.getElementById(id); el.className='text-success'; el.querySelector('i').className='fas fa-check-circle me-1'; };
                                const setInvalid = (id) => { let el = document.getElementById(id); el.className='text-danger'; el.querySelector('i').className='fas fa-times-circle me-1'; isValid = false; };
                                
                                if(val.length >= 8) setValid('reqLength'); else setInvalid('reqLength');
                                if(/[A-Z]/.test(val)) setValid('reqUpper'); else setInvalid('reqUpper');
                                if(/[0-9]/.test(val)) setValid('reqNumber'); else setInvalid('reqNumber');
                                if(/[\W_]/.test(val)) setValid('reqSymbol'); else setInvalid('reqSymbol');
                                
                                if(cVal !== '' && val !== cVal) { document.getElementById('passwordError').classList.remove('d-none'); isValid = false; } 
                                else { document.getElementById('passwordError').classList.add('d-none'); }
                                if(cVal === '') isValid = false;
                                btn.disabled = !isValid;
                            }
                            pwd.addEventListener('input', validate); cpwd.addEventListener('input', validate);
                        </script>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'includes/footer.php'; ?>