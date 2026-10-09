<?php
// modules/vendormgt/vendor_contacts.php
// Procurement (Vendor Management) view of a vendor's Contact Persons, with the
// ability to REASSIGN the primary role — the escape hatch for when a vendor's
// primary contact has left the company and cannot hand over themselves.

session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';

$allowed_roles = ['sourcing', 'vendor_mgt', 'vendormgt_head', 'admin'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    die("Access Denied");
}

$vendor_id = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['vendor_id'] ?? 0);
if ($vendor_id <= 0) {
    die("Error: No Vendor ID provided.");
}

// Load the vendor company.
$vres = $conn->prepare("SELECT id, company_name FROM vendors WHERE id = ? LIMIT 1");
$vres->bind_param("i", $vendor_id);
$vres->execute();
$vendor = $vres->get_result()->fetch_assoc();
if (!$vendor) {
    die("Vendor not found.");
}

$flash_ok = '';
$flash_err = '';

// -------------------------------------------------------------------------
// Handle POST actions (self-contained; PRG on success)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    = $_POST['action'] ?? '';
    $target_id = (int) ($_POST['target_id'] ?? 0);

    // The target must belong to THIS vendor.
    $tstmt = $conn->prepare("SELECT id, email, full_name, contact_role, contact_status, is_active,
                                    verified_at, last_login_at, invited_by_user_id
                               FROM users WHERE id = ? AND vendor_id = ? LIMIT 1");
    $tstmt->bind_param("ii", $target_id, $vendor_id);
    $tstmt->execute();
    $target = $tstmt->get_result()->fetch_assoc();

    if (!$target) {
        $flash_err = "Narahubung tidak ditemukan pada vendor ini.";
    } elseif ($action === 'vm_reassign_primary') {
        if ((int) $target['is_active'] !== 1 || $target['contact_status'] !== 'active') {
            $flash_err = "Peran utama hanya dapat diberikan ke narahubung yang aktif.";
        } else {
            $conn->begin_transaction();
            try {
                // Demote ANY existing primary (handles a stranded/revoked primary too).
                $conn->query("UPDATE users SET contact_role = 'member' WHERE vendor_id = {$vendor_id} AND contact_role = 'primary'");
                $p = $conn->prepare("UPDATE users SET contact_role = 'primary' WHERE id = ? AND vendor_id = ?");
                $p->bind_param("ii", $target_id, $vendor_id);
                $p->execute();
                $v = $conn->prepare("UPDATE vendors SET user_id = ? WHERE id = ?");
                $v->bind_param("ii", $target_id, $vendor_id);
                $v->execute();
                $conn->commit();
                vca_log($conn, $vendor_id, 'primary_reassigned_by_procurement', [
                    'target_user_id' => $target_id,
                    'target_email'   => $target['email'],
                ]);
                header("Location: vendor_contacts.php?id={$vendor_id}&ok=" . urlencode("Peran utama dialihkan ke {$target['email']}."));
                exit();
            } catch (mysqli_sql_exception $e) {
                $conn->rollback();
                $flash_err = "Gagal mengalihkan peran utama.";
            }
        }
    } elseif ($action === 'vm_revoke') {
        $u = $conn->prepare("UPDATE users SET contact_status = 'revoked', is_active = 0 WHERE id = ? AND vendor_id = ?");
        $u->bind_param("ii", $target_id, $vendor_id);
        $u->execute();
        vca_log($conn, $vendor_id, 'cp_revoked_by_procurement', ['target_user_id' => $target_id, 'target_email' => $target['email']]);
        header("Location: vendor_contacts.php?id={$vendor_id}&ok=" . urlencode("Akses {$target['email']} dinonaktifkan."));
        exit();
    } elseif ($action === 'vm_reactivate') {
        // Mirrors the vendor-side rule: un-revoking a CP who never completed
        // activation returns them to 'invited', not 'active' — only clicking the
        // emailed link proves the address is theirs. The vendor's primary can
        // then resend that link from "Kelola Narahubung".
        $never_activated = empty($target['verified_at'])
            && empty($target['last_login_at'])
            && !empty($target['invited_by_user_id']);

        $sql = $never_activated
            ? "UPDATE users SET contact_status = 'invited', is_active = 0 WHERE id = ? AND vendor_id = ?"
            : "UPDATE users SET contact_status = 'active',  is_active = 1 WHERE id = ? AND vendor_id = ?";
        $u = $conn->prepare($sql);
        $u->bind_param("ii", $target_id, $vendor_id);
        $u->execute();
        vca_log($conn, $vendor_id, 'cp_reactivated_by_procurement', ['target_user_id' => $target_id, 'target_email' => $target['email']]);

        $msg = $never_activated
            ? "Akses {$target['email']} dipulihkan, namun akun tersebut belum pernah diaktifkan — mintakan narahubung utama untuk mengirim ulang email aktivasi."
            : "Akses {$target['email']} diaktifkan kembali.";
        header("Location: vendor_contacts.php?id={$vendor_id}&ok=" . urlencode($msg));
        exit();
    }
}

if (isset($_GET['ok']))  $flash_ok  = $_GET['ok'];
if (isset($_GET['err'])) $flash_err = $_GET['err'];

// Load CPs.
$cstmt = $conn->prepare("SELECT id, email, full_name, phone, contact_role, contact_status, is_active, last_login_at
                           FROM users WHERE vendor_id = ? ORDER BY (contact_role='primary') DESC, id ASC");
$cstmt->bind_param("i", $vendor_id);
$cstmt->execute();
$contacts = $cstmt->get_result()->fetch_all(MYSQLI_ASSOC);

$page_title = "Narahubung: " . $vendor['company_name'];
include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Narahubung Vendor</span>
        <?php
            // Role-aware "back to profile": sourcing returns to its own profile
            // screen, VM/admin stay on the VM profile screen.
            $profile_href = ($_SESSION['role'] ?? '') === 'sourcing'
                ? $path_to_root . 'modules/sourcing/view_profile.php?id=' . (int) $vendor_id
                : 'view_profile.php?id=' . (int) $vendor_id;
        ?>
        <a href="<?= htmlspecialchars($profile_href) ?>" class="btn btn-sm btn-nav"><i class="fas fa-arrow-left me-1"></i> Profil Vendor</a>
    </div>
</nav>

<div class="container py-2">
    <div class="row justify-content-center">
        <div class="col-lg-11">

            <?php if ($flash_ok): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-4 border-success">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($flash_ok) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flash_err): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-4 border-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($flash_err) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <h4 class="fw-bold mb-1"><?= htmlspecialchars($vendor['company_name']) ?></h4>
            <p class="text-muted small mb-4">Kelola narahubung dan alihkan peran utama jika narahubung utama sudah tidak lagi di perusahaan.</p>

            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Nama</th><th>Email</th><th>Telepon</th>
                                <th class="text-center">Peran</th><th class="text-center">Status</th>
                                <th>Login Terakhir</th><th class="pe-3">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $c): ?>
                            <tr>
                                <td class="ps-3 text-nowrap"><?= htmlspecialchars($c['full_name'] ?? '-') ?></td>
                                <td>
                                    <?= htmlspecialchars($c['email']) ?>
                                    <?php if (is_free_email_domain($c['email'])): ?>
                                        <span class="badge bg-light text-warning border border-warning ms-1" style="font-size:0.6rem;" title="Email umum/pribadi"><i class="fas fa-triangle-exclamation me-1"></i>Email umum</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $c['phone'] ? htmlspecialchars($c['phone']) : '<span class="text-muted">-</span>' ?></td>
                                <td class="text-center">
                                    <?php if ($c['contact_role'] === 'primary'): ?>
                                        <span class="badge bg-primary"><i class="fas fa-crown me-1"></i>Utama</span>
                                    <?php else: ?><span class="badge bg-info text-dark">Member</span><?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($c['contact_status'] === 'revoked'): ?>
                                        <span class="badge bg-danger">Dinonaktifkan</span>
                                    <?php elseif ((int)$c['is_active'] === 0): ?>
                                        <span class="badge bg-warning text-dark">Menunggu Aktivasi</span>
                                    <?php else: ?><span class="badge bg-success">Aktif</span><?php endif; ?>
                                </td>
                                <td class="small text-muted text-nowrap"><?= $c['last_login_at'] ? htmlspecialchars(date('d M Y H:i', strtotime($c['last_login_at']))) : 'Belum pernah' ?></td>
                                <td class="pe-3 text-nowrap">
                                    <div class="d-inline-flex gap-2 align-items-center flex-nowrap">
                                        <?php if ($c['contact_role'] !== 'primary' && $c['contact_status'] === 'active' && (int)$c['is_active'] === 1): ?>
                                            <form method="POST" class="js-confirm" data-confirm="Jadikan <?= htmlspecialchars($c['email'], ENT_QUOTES) ?> sebagai narahubung utama?">
                                                <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">
                                                <input type="hidden" name="action" value="vm_reassign_primary">
                                                <input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                                                <button class="btn btn-sm btn-outline-primary text-nowrap"><i class="fas fa-crown me-1"></i>Jadikan Utama</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($c['contact_status'] === 'revoked'): ?>
                                            <form method="POST"><input type="hidden" name="vendor_id" value="<?= $vendor_id ?>"><input type="hidden" name="action" value="vm_reactivate"><input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                                                <button class="btn btn-sm btn-outline-success text-nowrap"><i class="fas fa-undo me-1"></i>Aktifkan</button></form>
                                        <?php elseif ($c['contact_role'] !== 'primary'): ?>
                                            <form method="POST" class="js-confirm" data-confirm="Nonaktifkan akses <?= htmlspecialchars($c['email'], ENT_QUOTES) ?>?"><input type="hidden" name="vendor_id" value="<?= $vendor_id ?>"><input type="hidden" name="action" value="vm_revoke"><input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger text-nowrap"><i class="fas fa-user-slash me-1"></i>Nonaktifkan</button></form>
                                        <?php endif; ?>
                                        <?php if ($c['contact_role'] === 'primary'): ?><span class="text-muted">&mdash;</span><?php endif; ?>
                                    </div>
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

<!-- Reusable confirmation modal (replaces the native browser confirm dialog) -->
<div class="modal fade" id="cpConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow rounded-4">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><i class="fas fa-circle-question text-warning me-2"></i>Konfirmasi</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2" id="cpConfirmMsg">Apakah Anda yakin?</div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary rounded-pill px-4" id="cpConfirmOk">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('cpConfirmModal');
    if (!modalEl || typeof bootstrap === 'undefined') return; // graceful fallback: forms still submit
    var bsModal = new bootstrap.Modal(modalEl);
    var msgEl   = document.getElementById('cpConfirmMsg');
    var okBtn   = document.getElementById('cpConfirmOk');
    var pending = null;

    document.querySelectorAll('form.js-confirm').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            pending = form;
            msgEl.textContent = form.getAttribute('data-confirm') || 'Apakah Anda yakin?';
            bsModal.show();
        });
    });

    okBtn.addEventListener('click', function () {
        if (!pending) return;
        var f = pending;
        pending = null;
        bsModal.hide();
        f.submit();
    });
});
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
