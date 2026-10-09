<?php
// pages/profile.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/header.php';

$username = $_SESSION['username'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $stmt = $pdo->prepare("SELECT email, password FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (password_verify($_POST['current_password'], $user['password'])) {
        if ($_POST['new_password'] === $_POST['confirm_password']) {
            $new_hash = password_hash($_POST['new_password'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password = ? WHERE username = ?")->execute([$new_hash, $username]);

            // Login actually authenticates against VSS's password_hash, not the
            // Workspace column above (see login_action.php) — keep both in sync,
            // same as the admin reset_password action in user_management.php.
            if (!empty($user['email'])) {
                try {
                    $vss_pdo = get_vss_pdo();
                    $vss_stmt = $vss_pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
                    $vss_stmt->execute([$new_hash, $user['email']]);
                    $msg = $vss_stmt->rowCount() > 0
                        ? "Password berhasil diubah!"
                        : "Password Workspace berhasil diubah, tapi email ini tidak ditemukan di VSS — password login TIDAK berubah. Hubungi admin.";
                } catch (PDOException $e) {
                    $msg = "Password Workspace berhasil diubah, tapi gagal menghubungi database VSS — password login TIDAK berubah. Hubungi admin.";
                }
            } else {
                $msg = "Password Workspace berhasil diubah, tapi akun ini tidak punya email terdaftar — password login TIDAK berubah. Hubungi admin.";
            }
        } else {
            $error = "Konfirmasi password baru tidak cocok.";
        }
    } else {
        $error = "Password saat ini salah.";
    }
}
?>

<div class="max-w-xl mx-auto py-12">
    <div class="bg-white shadow-lg rounded-lg p-8 border border-gray-200 border-t-4 border-t-indigo-600">
        <h2 class="text-2xl font-bold text-gray-800 mb-6">Profil Saya</h2>
        
        <div class="mb-6 p-4 bg-gray-50 rounded border">
            <p class="text-sm text-gray-600"><strong>Nama:</strong> <?= htmlspecialchars($_SESSION['name']) ?></p>
            <p class="text-sm text-gray-600"><strong>Role:</strong> <?= htmlspecialchars($_SESSION['role']) ?></p>
        </div>

        <h3 class="text-lg font-bold text-gray-800 mb-4 border-b pb-2">Ubah Password</h3>
        
        <?php if(isset($msg)): ?><div class="bg-green-100 text-green-800 p-3 mb-4 rounded font-bold text-sm"><?= $msg ?></div><?php endif; ?>
        <?php if(isset($error)): ?><div class="bg-red-100 text-red-800 p-3 mb-4 rounded font-bold text-sm"><?= $error ?></div><?php endif; ?>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="change_password" value="1">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Password Saat Ini</label>
                <input type="password" name="current_password" required class="w-full p-2 border rounded">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Password Baru</label>
                <input type="password" name="new_password" required minlength="6" class="w-full p-2 border rounded">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Konfirmasi Password Baru</label>
                <input type="password" name="confirm_password" required minlength="6" class="w-full p-2 border rounded">
            </div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded shadow">Simpan Password Baru</button>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>