<?php
// modules/vendor/contacts.php
// "Kelola Narahubung" — manage the vendor company's Contact Persons (CPs).
// All CPs can view the list; only the PRIMARY contact can add/revoke/reactivate.

session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';

// Security check
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever be on profile.php.
vendor_staff_edit_restrict();

$vendor_id = current_vendor_id($conn);
if (!$vendor_id) {
    die("Vendor profile not found.");
}
$is_primary = vendor_is_primary();
$me_id      = (int) $_SESSION['user_id'];
$needs_reattest = $is_primary && vendor_needs_reattestation($conn, $vendor_id);

// Load all CPs for this vendor (primary first, then by creation order).
$stmt = $conn->prepare(
    "SELECT id, email, username, full_name, phone, contact_role, contact_status, is_active,
            verified_at, invited_by_user_id, reset_token, reset_expires, last_login_at, created_at
       FROM users
      WHERE vendor_id = ?
      ORDER BY (contact_role = 'primary') DESC, id ASC"
);
$stmt->bind_param("i", $vendor_id);
$stmt->execute();
$contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// When each CP was last mailed an activation link (from the audit trail), so the
// primary can see whether a resend is warranted before clicking.
$last_invite_sent = cp_last_invite_sent_map($conn, $vendor_id);

// CPs still waiting to activate, and how many of those have a dead link — i.e.
// the "invited but never clicked within VENDOR_CP_INVITE_DAYS" case this page
// exists to resolve.
$pending_cps = array_values(array_filter($contacts, 'cp_activation_pending'));
$expired_cps = array_values(array_filter($pending_cps, 'cp_invite_link_expired'));

// My own current username (for the self-service username card).
$my_username = '';
foreach ($contacts as $c) {
    if ((int) $c['id'] === $me_id) {
        $my_username = (string) ($c['username'] ?? '');
        break;
    }
}

$page_title = "Kelola Narahubung";
include $path_to_root . 'includes/header.php';

// Helper: status badge
function cp_status_badge(array $c): string
{
    if ($c['contact_status'] === 'revoked') {
        return '<span class="badge bg-danger"><i class="fas fa-ban me-1"></i>Dinonaktifkan</span>';
    }
    if (cp_activation_pending($c)) {
        if (cp_invite_link_expired($c)) {
            return '<span class="badge bg-danger-subtle text-danger border border-danger"><i class="fas fa-link-slash me-1"></i>Tautan Kedaluwarsa</span>';
        }
        return '<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>Menunggu Aktivasi</span>';
    }
    if ((int) $c['is_active'] === 0) {
        return '<span class="badge bg-warning text-dark"><i class="fas fa-hourglass-half me-1"></i>Menunggu Aktivasi</span>';
    }
    return '<span class="badge bg-success"><i class="fas fa-check me-1"></i>Aktif</span>';
}

/**
 * One-line activation trail for a pending CP: when the last activation mail went
 * out and when its link dies (or that it already died).
 */
function cp_invite_hint(array $c, ?string $sent_at): string
{
    $bits = [];
    if ($sent_at) {
        $bits[] = 'Email aktivasi terakhir dikirim ' . date('d M Y H:i', strtotime($sent_at));
    }
    if (cp_invite_link_expired($c)) {
        $bits[] = '<span class="text-danger fw-bold">tautan sudah kedaluwarsa</span>';
    } elseif (!empty($c['reset_expires'])) {
        $bits[] = 'berlaku sampai ' . date('d M Y H:i', strtotime($c['reset_expires']));
    }
    return $bits ? implode(' &middot; ', $bits) : 'Belum ada catatan pengiriman email aktivasi.';
}
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/css/intlTelInput.css">

<style>
    /* intl-tel-input styling — mirrors the vendor profile page */
    .iti { width: 100%; }
    .iti .iti__tel-input { background-color: #fff; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 0.375rem 0.75rem; font-size: 1rem; transition: border-color 0.15s ease, box-shadow 0.15s ease; width: 100%; height: calc(1.5em + 0.75rem + 2px); }
    .iti .iti__tel-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.25rem rgba(13,110,253,.25); outline: none; }
    .iti__selected-country { border-radius: 0.375rem 0 0 0.375rem; padding: 0 8px 0 12px; }
    .iti__dropdown-content { border-radius: 10px; box-shadow: 0 10px 30px rgba(0,0,0,0.12); border: 1px solid #e8e8e8; margin-top: 4px; }
    .iti__search-input { border-radius: 8px; border: 1px solid #dee2e6; padding: 8px 12px; font-size: 0.85rem; }
    .iti__search-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 0.15rem rgba(13,110,253,.15); outline: none; }
    .iti__country { padding: 8px 12px; }
    .iti__country:hover { background-color: #e8f0fe; }
    .phone-status-cp { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; font-weight: 600; margin-top: 4px; }
    .phone-status-cp.valid { color: #198754; }
    .phone-status-cp.invalid { color: #dc3545; }
    .phone-status-cp.empty { color: #6c757d; }
</style>

<nav class="navbar navbar-vss mb-4 sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="dashboard.php"><?= vss_brand_logo() ?>Portal</a>
        <div class="d-flex align-items-center gap-2">
            <a href="dashboard.php" class="btn btn-sm btn-nav"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
            <a href="../../logout.php" class="btn btn-sm btn-nav" title="Keluar"><i class="fas fa-sign-out-alt"></i></a>
        </div>
    </div>
</nav>

<div class="container py-2">
    <div class="row justify-content-center">
        <div class="col-lg-10">

            <?php if (isset($_GET['ok'])): ?>
                <div class="alert alert-success alert-dismissible fade show shadow-sm border-0 border-start border-4 border-success">
                    <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_GET['ok']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if (isset($_GET['err'])): ?>
                <div class="alert alert-danger alert-dismissible fade show shadow-sm border-0 border-start border-4 border-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($_GET['err']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($needs_reattest): ?>
                <div class="alert alert-warning border-0 shadow-sm rounded-4 d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                    <div>
                        <i class="fas fa-clipboard-check me-2"></i>
                        <strong>Konfirmasi berkala:</strong> Mohon pastikan daftar narahubung di bawah masih sesuai — nonaktifkan yang sudah tidak lagi mewakili perusahaan Anda.
                    </div>
                    <form method="POST" action="contacts_action.php" class="flex-shrink-0">
                        <input type="hidden" name="action" value="attest_cp_list">
                        <button class="btn btn-sm btn-warning fw-bold"><i class="fas fa-check me-1"></i>Ya, Daftar Sudah Sesuai</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ($is_primary && !empty($pending_cps)): ?>
                <?php $expired_n = count($expired_cps); ?>
                <div class="alert <?= $expired_n ? 'alert-danger' : 'alert-warning' ?> border-0 shadow-sm rounded-4">
                    <i class="fas fa-envelope-circle-check me-2"></i>
                    <strong><?= count($pending_cps) ?> narahubung belum mengaktifkan akun.</strong>
                    <?php if ($expired_n): ?>
                        <?= $expired_n ?> di antaranya sudah melewati <?= (int) VENDOR_CP_INVITE_DAYS ?> hari, sehingga tautan aktivasinya
                        <strong>tidak berlaku lagi</strong> — gunakan tombol <em>Kirim Ulang Aktivasi</em> pada baris yang bertanda merah
                        agar mereka menerima tautan baru.
                    <?php else: ?>
                        Tautan aktivasi berlaku <?= (int) VENDOR_CP_INVITE_DAYS ?> hari. Bila email tidak diterima (cek folder spam),
                        Anda dapat mengirim ulang melalui tombol <em>Kirim Ulang Aktivasi</em>.
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="d-flex align-items-center justify-content-between mb-3">
                <div>
                    <h4 class="fw-bold mb-1"><i class="fas fa-users me-2 text-primary"></i>Kelola Narahubung</h4>
                    <p class="text-muted small mb-0">Narahubung (contact person) yang dapat mengakses portal atas nama <strong><?= htmlspecialchars($_SESSION['company_name'] ?? '') ?></strong>.</p>
                </div>
            </div>

            <?php if (!$is_primary): ?>
                <div class="alert alert-info border-0 shadow-sm small">
                    <i class="fas fa-info-circle me-1"></i> Hanya <strong>narahubung utama</strong> yang dapat menambah atau menonaktifkan narahubung. Anda dapat melihat daftar di bawah ini.
                </div>
            <?php endif; ?>

            <!-- CP list -->
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mb-4">
                <div class="card-header bg-light py-3 border-0">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-address-book me-2 text-primary"></i>Daftar Narahubung (<?= count($contacts) ?>)</h6>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Nama</th>
                                <th>Email (Login)</th>
                                <th>Username</th>
                                <th>Telepon</th>
                                <th class="text-center">Peran</th>
                                <th class="text-center">Status</th>
                                <th>Login Terakhir</th>
                                <?php if ($is_primary): ?><th class="pe-3">Aksi</th><?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($contacts as $c): ?>
                            <?php
                                $is_pending = cp_activation_pending($c);
                                $is_expired = $is_pending && cp_invite_link_expired($c);
                                $sent_at    = $last_invite_sent[(int) $c['id']] ?? null;
                            ?>
                            <tr<?= $is_expired ? ' class="table-danger"' : '' ?>>
                                <td class="ps-3 text-nowrap">
                                    <?= htmlspecialchars($c['full_name'] ?? '-') ?>
                                    <?php if ((int)$c['id'] === $me_id): ?><span class="badge bg-secondary ms-1" style="font-size:0.6rem;">Anda</span><?php endif; ?>
                                </td>
                                <td>
                                    <?= htmlspecialchars($c['email']) ?>
                                    <?php if (is_free_email_domain($c['email'])): ?>
                                        <span class="badge bg-light text-warning border border-warning ms-1" style="font-size:0.6rem;" title="Email umum/pribadi — pastikan CP masih mewakili perusahaan"><i class="fas fa-triangle-exclamation me-1"></i>Email umum</span>
                                    <?php endif; ?>
                                    <?php if ($is_pending): ?>
                                        <div class="text-muted" style="font-size:0.7rem;"><?= cp_invite_hint($c, $sent_at) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-nowrap"><?= !empty($c['username']) ? '<span class="font-monospace">' . htmlspecialchars($c['username']) . '</span>' : '<span class="text-muted">-</span>' ?></td>
                                <td><?= $c['phone'] ? htmlspecialchars($c['phone']) : '<span class="text-muted">-</span>' ?></td>
                                <td class="text-center">
                                    <?php if ($c['contact_role'] === 'primary'): ?>
                                        <span class="badge bg-primary"><i class="fas fa-crown me-1"></i>Utama</span>
                                    <?php else: ?>
                                        <span class="badge bg-info text-dark">Member</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= cp_status_badge($c) ?></td>
                                <td class="small text-muted text-nowrap"><?= $c['last_login_at'] ? htmlspecialchars(date('d M Y H:i', strtotime($c['last_login_at']))) : 'Belum pernah' ?></td>
                                <?php if ($is_primary): ?>
                                <td class="pe-3 text-nowrap">
                                    <?php if ($c['contact_role'] === 'primary'): ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php else: ?>
                                        <?php // Each action is its own <form>, so btn-group-sm (a direct-child
                                              // selector) can't reach the buttons — they carry btn-sm themselves. ?>
                                        <div class="d-inline-flex gap-2 align-items-center flex-nowrap">
                                            <?php if ($c['contact_status'] === 'revoked'): ?>
                                                <form method="POST" action="contacts_action.php" class="js-confirm" data-confirm="Aktifkan kembali akses untuk <?= htmlspecialchars($c['email'], ENT_QUOTES) ?>?">
                                                    <input type="hidden" name="action" value="reactivate_cp">
                                                    <input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                                                    <button class="btn btn-sm btn-outline-success text-nowrap"><i class="fas fa-undo me-1"></i>Aktifkan</button>
                                                </form>
                                            <?php else: ?>
                                                <?php if ($is_pending): ?>
                                                    <form method="POST" action="contacts_action.php" class="js-confirm"
                                                          data-confirm="Kirim ulang email aktivasi ke <?= htmlspecialchars($c['email'], ENT_QUOTES) ?>? Tautan lama akan otomatis tidak berlaku.">
                                                        <input type="hidden" name="action" value="resend_invite">
                                                        <input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                                                        <button class="btn btn-sm <?= $is_expired ? 'btn-warning fw-bold' : 'btn-outline-secondary' ?> text-nowrap"
                                                                title="Kirim tautan aktivasi baru yang berlaku <?= (int) VENDOR_CP_INVITE_DAYS ?> hari">
                                                            <i class="fas fa-paper-plane me-1"></i>Kirim Ulang Aktivasi
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST" action="contacts_action.php" class="js-confirm" data-confirm="Nonaktifkan akses untuk <?= htmlspecialchars($c['email'], ENT_QUOTES) ?>? Mereka tidak akan bisa login lagi.">
                                                    <input type="hidden" name="action" value="revoke_cp">
                                                    <input type="hidden" name="target_id" value="<?= (int)$c['id'] ?>">
                                                    <button class="btn btn-sm btn-outline-danger text-nowrap"><i class="fas fa-user-slash me-1"></i>Nonaktifkan</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Add CP form (primary only) -->
            <?php if ($is_primary): ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-primary text-white py-3 border-0">
                    <h6 class="mb-0 fw-bold text-white"><i class="fas fa-user-plus me-2"></i>Tambah Narahubung Baru</h6>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-4">
                        Narahubung baru akan menerima email berisi tautan untuk membuat kata sandi. Dengan mengklik tautan tersebut,
                        mereka membuktikan bahwa email tersebut benar milik mereka. Nonaktifkan narahubung kapan saja jika mereka sudah tidak lagi mewakili perusahaan.
                    </p>
                    <form method="POST" action="contacts_action.php" autocomplete="off">
                        <input type="hidden" name="action" value="add_cp">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Nama Lengkap <span class="text-danger">*</span></label>
                                <input type="text" name="cp_name" class="form-control" placeholder="Nama narahubung" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Email <span class="text-danger">*</span></label>
                                <input type="email" name="cp_email" class="form-control" placeholder="nama@perusahaan.com" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Nomor Telepon <span class="text-danger">*</span></label>
                                <input type="tel" id="cpPhoneInput" class="form-control" placeholder="812 3456 7890" autocomplete="off">
                                <input type="hidden" name="cp_phone" id="cpPhoneHidden">
                                <div id="cpPhoneStatus" class="phone-status-cp empty">
                                    <i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>
                                </div>
                            </div>
                        </div>
                        <div class="d-grid d-md-flex justify-content-md-end mt-4">
                            <button type="submit" class="btn btn-primary fw-bold px-4"><i class="fas fa-paper-plane me-2"></i>Kirim Undangan</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- Transfer primary role (primary only) -->
            <?php
            $active_members = array_filter($contacts, function ($c) {
                return $c['contact_role'] === 'member' && $c['contact_status'] === 'active' && (int)$c['is_active'] === 1;
            });
            ?>
            <?php if ($is_primary && !empty($active_members)): ?>
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mt-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-crown me-2 text-warning"></i>Alihkan Peran Narahubung Utama</h6>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        Alihkan peran <strong>narahubung utama</strong> ke rekan Anda yang sudah aktif. Setelah dialihkan, Anda menjadi
                        narahubung biasa (member) dan tidak lagi dapat mengelola daftar ini. Gunakan ini jika Anda akan berpindah tugas.
                    </p>
                    <form method="POST" action="contacts_action.php" class="js-confirm" data-confirm="Alihkan peran utama? Anda akan menjadi member setelah ini. Tindakan ini tidak dapat dibatalkan sendiri.">
                        <input type="hidden" name="action" value="transfer_primary">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Pilih narahubung tujuan</label>
                                <select name="target_id" class="form-select" required>
                                    <option value="" disabled selected>Pilih narahubung aktif...</option>
                                    <?php foreach ($active_members as $m): ?>
                                        <option value="<?= (int)$m['id'] ?>"><?= htmlspecialchars($m['full_name']) ?> — <?= htmlspecialchars($m['email']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 d-grid">
                                <button class="btn btn-warning fw-bold"><i class="fas fa-exchange-alt me-2"></i>Alihkan Peran</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- My login username (any CP) -->
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mt-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-user-tag me-2 text-primary"></i>Nama Pengguna (Username) Saya</h6>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        Username adalah <strong>ID masuk alternatif</strong>: jika email Anda tidak dapat diakses,
                        Anda tetap dapat login menggunakan username + kata sandi. Username Anda saat ini:
                        <strong><?= $my_username !== '' ? htmlspecialchars($my_username) : '<span class="text-muted">(belum diatur)</span>' ?></strong>.
                    </p>
                    <form method="POST" action="contacts_action.php" autocomplete="off">
                        <input type="hidden" name="action" value="set_username">
                        <label class="form-label fw-bold small">Username Baru <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text" name="new_username" class="form-control" placeholder="mis. majubersama"
                                   value="<?= htmlspecialchars($my_username) ?>"
                                   minlength="4" maxlength="30" pattern="[A-Za-z0-9._-]{4,30}" required>
                            <button class="btn btn-outline-primary fw-bold px-4 text-nowrap"><i class="fas fa-save me-2"></i>Simpan Username</button>
                        </div>
                        <div class="form-text small" style="font-size:0.7rem;">4–30 karakter. Hanya huruf, angka, titik, garis bawah, dan strip. Harus unik.</div>
                    </form>
                </div>
            </div>

            <!-- Change my own login email (any CP) -->
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden mt-4">
                <div class="card-header bg-white py-3 border-bottom">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-envelope me-2 text-primary"></i>Ubah Email Login Saya</h6>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        Email login Anda saat ini: <strong><?= htmlspecialchars($_SESSION['email'] ?? '') ?></strong>.
                        Demi keamanan, perubahan hanya berlaku setelah Anda mengonfirmasi melalui tautan yang dikirim ke email baru.
                    </p>
                    <form method="POST" action="contacts_action.php" autocomplete="off">
                        <input type="hidden" name="action" value="change_email">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Email Baru <span class="text-danger">*</span></label>
                                <input type="email" name="new_email" class="form-control" placeholder="email.baru@perusahaan.com" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kata Sandi Saat Ini <span class="text-danger">*</span></label>
                                <input type="password" name="current_password" class="form-control" placeholder="Konfirmasi identitas Anda" required autocomplete="current-password">
                            </div>
                        </div>
                        <div class="d-grid d-md-flex justify-content-md-end mt-4">
                            <button class="btn btn-outline-primary fw-bold px-4"><i class="fas fa-paper-plane me-2"></i>Kirim Konfirmasi</button>
                        </div>
                    </form>
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

<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/intlTelInput.min.js"></script>
<script>
// ── Add-CP phone: country-code picker (same config as the profile page) ──────
document.addEventListener('DOMContentLoaded', function () {
    var cpPhoneEl     = document.getElementById('cpPhoneInput');
    var cpPhoneHidden = document.getElementById('cpPhoneHidden');
    var cpPhoneStatus = document.getElementById('cpPhoneStatus');
    if (!cpPhoneEl || typeof window.intlTelInput === 'undefined') return; // field absent for non-primary

    var cpIti = window.intlTelInput(cpPhoneEl, {
        initialCountry: "id",
        preferredCountries: ["id", "sg", "my", "th", "ph", "vn", "jp", "cn", "kr", "au", "sa", "ae"],
        separateDialCode: true,
        nationalMode: false,
        formatAsYouType: true,
        formatOnDisplay: true,
        strictMode: true,
        validationNumberType: "MOBILE",
        countrySearch: true,
        containerClass: "w-100",
        // The enclosing .card uses overflow-hidden (to clip its header to the
        // rounded corners), which would crop the dropdown. Rendering it on
        // <body> lifts it out of that clipping context.
        dropdownContainer: document.body,
        loadUtilsOnInit: "https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/utils.js"
    });

    // Keep the hidden field (the one actually submitted) in sync, and mirror
    // the profile page's inline valid/invalid feedback.
    function syncCpPhone() {
        var fullNumber = cpIti.getNumber();
        cpPhoneHidden.value = fullNumber;

        var inputVal = cpPhoneEl.value.replace(/\s/g, '');
        if (!inputVal || inputVal.length < 4) {
            cpPhoneStatus.className = 'phone-status-cp empty';
            cpPhoneStatus.innerHTML = '<i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>';
            return false;
        }
        if (cpIti.isValidNumber()) {
            var countryName = cpIti.getSelectedCountryData().name || '';
            cpPhoneStatus.className = 'phone-status-cp valid';
            cpPhoneStatus.innerHTML = '<i class="fas fa-check-circle"></i> <span>Nomor valid — ' + countryName + ' (' + fullNumber + ')</span>';
            return true;
        }
        var errorMap = ["Nomor tidak valid", "Kode negara tidak valid", "Nomor terlalu pendek", "Nomor terlalu panjang", "Nomor tidak valid"];
        cpPhoneStatus.className = 'phone-status-cp invalid';
        cpPhoneStatus.innerHTML = '<i class="fas fa-times-circle"></i> <span>' + (errorMap[cpIti.getValidationError()] || "Nomor tidak valid") + '</span>';
        return false;
    }

    cpPhoneEl.addEventListener('input', syncCpPhone);
    cpPhoneEl.addEventListener('countrychange', syncCpPhone);

    // The visible input can't carry `required` (it isn't submitted), so block
    // submission here instead — the server still re-validates.
    var addForm = cpPhoneEl.closest('form');
    if (addForm) {
        addForm.addEventListener('submit', function (e) {
            if (!syncCpPhone()) {
                e.preventDefault();
                e.stopImmediatePropagation();
                cpPhoneEl.focus();
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('cpConfirmModal');
    if (!modalEl || typeof bootstrap === 'undefined') return; // graceful fallback: forms still submit
    var bsModal   = new bootstrap.Modal(modalEl);
    var msgEl     = document.getElementById('cpConfirmMsg');
    var okBtn     = document.getElementById('cpConfirmOk');
    var pending   = null;

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
        f.submit(); // bypasses the submit listener above, so it goes through
    });
});
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
