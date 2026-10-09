<?php
// modules/vendor/contacts_action.php
//
// Handles Contact Person (CP) lifecycle actions for the vendor portal:
//   add_cp        -> invite a new member CP (primary only)
//   revoke_cp     -> disable a member CP's access (primary only)
//   reactivate_cp -> restore a revoked member CP (primary only)
//   resend_invite -> re-send the set-password invite to a member CP (primary only)
//
// All actions are restricted to the vendor's PRIMARY contact and scoped to the
// primary's own vendor company. Every action is written to vendor_contact_audit.

session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/vendor_context.php';
require_once $path_to_root . 'includes/notification_helper.php'; // _buildMailer() / _sendMail()

// --- Guard: must be a logged-in vendor ------------------------------------
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'vendor') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever touch profile.php — CP lifecycle actions are out of scope for it.
vendor_staff_edit_restrict();

$vendor_id = current_vendor_id($conn);
if (!$vendor_id) {
    header("Location: contacts.php?err=" . urlencode("Perusahaan vendor tidak ditemukan."));
    exit();
}

$action = $_POST['action'] ?? '';

// Management actions require the caller to be the vendor's PRIMARY contact.
// 'change_email' is excluded: every CP may change their OWN login email.
$primary_only_actions = ['add_cp', 'revoke_cp', 'reactivate_cp', 'resend_invite', 'transfer_primary', 'attest_cp_list'];
if (in_array($action, $primary_only_actions, true) && !vendor_is_primary()) {
    header("Location: contacts.php?err=" . urlencode("Hanya narahubung utama (primary) yang dapat mengelola daftar narahubung."));
    exit();
}

/**
 * Build the absolute app root URL (e.g. https://host/base) from this script,
 * which lives in /modules/vendor. Mirrors the logic used elsewhere in the app.
 */
function app_root_url(): string
{
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? '') == 443) ? "https://" : "http://";
    $domainName = $_SERVER['HTTP_HOST'] ?? '';
    $script_dir = str_replace('\\', '/', dirname($_SERVER['PHP_SELF']));
    $root_path  = preg_replace('#/modules/[^/]+/?$#', '', $script_dir);
    return $protocol . $domainName . $root_path;
}

/**
 * Issue a fresh activation link for a CP and email it to them.
 *
 * Shared by add_cp and resend_invite so the first mail and every resend carry
 * the same instructions and the same validity window. Rotating the token here
 * (rather than reusing the old one) means a resend always supersedes whatever
 * was sent before — an old link that leaked can no longer be used.
 *
 * @return bool whether the mail was actually accepted for delivery
 */
function issue_cp_activation_invite(mysqli $conn, int $target_id, int $vendor_id, string $email, string $name, bool $is_resend): bool
{
    $reset_token   = bin2hex(random_bytes(32));
    $days          = (int) VENDOR_CP_INVITE_DAYS;
    $reset_expires = date("Y-m-d H:i:s", strtotime("+{$days} days"));

    $upd = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ? AND vendor_id = ?");
    $upd->bind_param("ssii", $reset_token, $reset_expires, $target_id, $vendor_id);
    $upd->execute();

    $company = $_SESSION['company_name'] ?? 'Perusahaan Anda';
    $link    = app_root_url() . '/reset_password.php?token=' . $reset_token;

    $mail = _buildMailer();
    $mail->Timeout = 20; // Hostinger's TLS handshake alone runs ~4s; 6s was too tight
    $mail->addAddress($email, $name);
    $mail->isHTML(true);
    $mail->Subject = $is_resend
        ? 'Pengingat: Aktivasi Akses Portal Vendor — ' . $company
        : 'Undangan Akses Portal Vendor — ' . $company;

    $intro = $is_resend
        ? 'Kami mengirim ulang tautan aktivasi akun narahubung Anda untuk <strong>' . htmlspecialchars($company) . '</strong> '
          . 'pada Vendor Sourcing System Kawan Lama Group, karena akun tersebut belum diaktifkan.<br><br>'
          . '<em>Tautan yang dikirim sebelumnya sudah tidak berlaku — mohon gunakan tautan terbaru di bawah ini.</em>'
        : 'Anda telah didaftarkan sebagai narahubung (contact person) untuk <strong>' . htmlspecialchars($company) . '</strong> '
          . 'pada Vendor Sourcing System Kawan Lama Group.';

    $mail->Body =
        'Halo ' . htmlspecialchars($name) . ',<br><br>'
        . $intro . '<br><br>'
        . 'Silakan klik tautan di bawah ini untuk membuat kata sandi dan mengaktifkan akses Anda. '
        . 'Tautan ini berlaku selama ' . $days . ' hari:<br><br>'
        . '<a href="' . $link . '" style="display:inline-block;padding:10px 20px;color:#fff;background-color:#0d6efd;text-decoration:none;border-radius:5px;">Buat Kata Sandi &amp; Aktifkan Akun</a>'
        . '<br><br>Atau salin URL berikut ke peramban Anda:<br>' . $link
        . '<br><br>Jika Anda tidak mengenali undangan ini, abaikan email ini.';
    $mail->AltBody =
        "Halo {$name},\n\n"
        . ($is_resend
            ? "Kami mengirim ulang tautan aktivasi akun narahubung Anda untuk {$company} pada Vendor Sourcing System Kawan Lama Group.\n"
            : "Anda didaftarkan sebagai narahubung untuk {$company} pada Vendor Sourcing System Kawan Lama Group.\n")
        . "Buat kata sandi & aktifkan akun (berlaku {$days} hari):\n{$link}\n";

    return (bool) _sendMail($mail);
}

/**
 * Load a CP row that MUST belong to the acting primary's vendor. Returns null
 * if not found or not in this vendor (prevents cross-vendor tampering).
 */
function load_vendor_member(mysqli $conn, int $target_id, int $vendor_id): ?array
{
    $stmt = $conn->prepare(
        "SELECT id, email, full_name, contact_role, contact_status, is_active,
                verified_at, last_login_at, invited_by_user_id, reset_token, reset_expires
           FROM users WHERE id = ? AND vendor_id = ? LIMIT 1"
    );
    $stmt->bind_param("ii", $target_id, $vendor_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $row ?: null;
}

// =========================================================================
// ADD CP  (invite a new member)
// =========================================================================
if ($action === 'add_cp') {

    $name  = trim($_POST['cp_name'] ?? '');
    $email = trim($_POST['cp_email'] ?? '');
    $phone_raw = trim($_POST['cp_phone'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', $phone_raw);

    // Validation
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        header("Location: contacts.php?err=" . urlencode("Nama dan email yang valid wajib diisi."));
        exit();
    }
    if (strlen($phone) < 7 || strlen($phone) > 15) {
        header("Location: contacts.php?err=" . urlencode("Nomor telepon tidak valid (7-15 digit)."));
        exit();
    }

    // Email must be globally unique (it is the login id).
    $chk = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $chk->bind_param("s", $email);
    $chk->execute();
    if ($chk->get_result()->fetch_assoc()) {
        header("Location: contacts.php?err=" . urlencode("Email tersebut sudah terdaftar di sistem."));
        exit();
    }

    // Optional cap on number of active CPs per vendor.
    $cap = 6;
    $cntRes = $conn->prepare("SELECT COUNT(*) AS n FROM users WHERE vendor_id = ? AND contact_status <> 'revoked'");
    $cntRes->bind_param("i", $vendor_id);
    $cntRes->execute();
    $n = (int) ($cntRes->get_result()->fetch_assoc()['n'] ?? 0);
    if ($n >= $cap) {
        header("Location: contacts.php?err=" . urlencode("Batas maksimum {$cap} narahubung aktif telah tercapai. Nonaktifkan salah satu terlebih dahulu."));
        exit();
    }

    // Create the member account in the PENDING-ACTIVATION state: an unusable
    // random password, is_active = 0 and contact_status = 'invited'. The invite
    // link (reset_token) lets the CP set their own password — and because that
    // link is delivered only to their inbox, using it proves they control the
    // email address. reset_password.php flips them to active on success.
    // The activation link itself is issued by issue_cp_activation_invite() below,
    // so the first mail and every resend go through exactly one code path.
    $random_pw   = bin2hex(random_bytes(16));
    $hashed      = password_hash($random_pw, PASSWORD_DEFAULT);
    $inviter_id  = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare(
        "INSERT INTO users
            (email, password_hash, full_name, role, is_active,
             vendor_id, contact_role, contact_status, phone, invited_by_user_id)
         VALUES (?, ?, ?, 'vendor', 0, ?, 'member', 'invited', ?, ?)"
    );
    $stmt->bind_param(
        "sssisi",
        $email, $hashed, $name, $vendor_id, $phone, $inviter_id
    );

    try {
        $stmt->execute();
        $new_user_id = $conn->insert_id;
    } catch (mysqli_sql_exception $e) {
        header("Location: contacts.php?err=" . urlencode("Gagal membuat akun narahubung. Silakan coba lagi."));
        exit();
    }

    vca_log($conn, $vendor_id, 'cp_invited', [
        'target_user_id' => $new_user_id,
        'target_email'   => $email,
        'details'        => 'Diundang oleh ' . ($_SESSION['user_name'] ?? '') . ' sebagai member.',
    ]);
    touch_cp_attestation($conn, $vendor_id); // managing the list = attesting it's current

    // Send the set-password invite email. Best-effort: the account already
    // exists, and the primary can always resend from the list.
    $sent = issue_cp_activation_invite($conn, $new_user_id, $vendor_id, $email, $name, false);

    if (!$sent) {
        header("Location: contacts.php?err=" . urlencode("Narahubung {$email} berhasil ditambahkan, tetapi email aktivasi gagal dikirim. Gunakan tombol \"Kirim Ulang Aktivasi\" pada daftar di bawah."));
        exit();
    }
    header("Location: contacts.php?ok=" . urlencode("Undangan telah dikirim ke {$email}. Narahubung dapat login setelah membuat kata sandi melalui tautan tersebut."));
    exit();
}

// =========================================================================
// REVOKE / REACTIVATE / RESEND  (operate on an existing member)
// =========================================================================
if (in_array($action, ['revoke_cp', 'reactivate_cp', 'resend_invite'], true)) {

    $target_id = (int) ($_POST['target_id'] ?? 0);
    $member = load_vendor_member($conn, $target_id, $vendor_id);

    if (!$member) {
        header("Location: contacts.php?err=" . urlencode("Narahubung tidak ditemukan."));
        exit();
    }
    // Never allow acting on the primary or on oneself here.
    if ($member['contact_role'] === 'primary' || $target_id === (int) $_SESSION['user_id']) {
        header("Location: contacts.php?err=" . urlencode("Aksi tidak diizinkan untuk narahubung utama."));
        exit();
    }

    if ($action === 'revoke_cp') {
        $upd = $conn->prepare("UPDATE users SET contact_status = 'revoked', is_active = 0 WHERE id = ? AND vendor_id = ?");
        $upd->bind_param("ii", $target_id, $vendor_id);
        $upd->execute();
        vca_log($conn, $vendor_id, 'cp_revoked', ['target_user_id' => $target_id, 'target_email' => $member['email']]);
        touch_cp_attestation($conn, $vendor_id);
        header("Location: contacts.php?ok=" . urlencode("Akses {$member['email']} telah dinonaktifkan."));
        exit();
    }

    if ($action === 'reactivate_cp') {
        // A CP who was revoked BEFORE ever activating goes back to the pending
        // state, not straight to active — being un-revoked must not hand them an
        // account they never proved they own. They need a fresh invite.
        $never_activated = empty($member['verified_at'])
            && empty($member['last_login_at'])
            && !empty($member['invited_by_user_id']);

        if ($never_activated) {
            $upd = $conn->prepare("UPDATE users SET contact_status = 'invited', is_active = 0 WHERE id = ? AND vendor_id = ?");
        } else {
            $upd = $conn->prepare("UPDATE users SET contact_status = 'active', is_active = 1 WHERE id = ? AND vendor_id = ?");
        }
        $upd->bind_param("ii", $target_id, $vendor_id);
        $upd->execute();
        vca_log($conn, $vendor_id, 'cp_reactivated', ['target_user_id' => $target_id, 'target_email' => $member['email']]);
        touch_cp_attestation($conn, $vendor_id);

        $msg = $never_activated
            ? "Akses {$member['email']} dipulihkan. Akun tersebut belum pernah diaktifkan — klik \"Kirim Ulang Aktivasi\" untuk mengirim tautan baru."
            : "Akses {$member['email']} telah diaktifkan kembali.";
        header("Location: contacts.php?ok=" . urlencode($msg));
        exit();
    }

    if ($action === 'resend_invite') {

        // Only makes sense for a CP who has never completed activation. Someone
        // who already set a password should use "Lupa Kata Sandi" instead — and
        // re-issuing a token for them would needlessly hand out a password reset.
        if (!cp_activation_pending($member)) {
            header("Location: contacts.php?err=" . urlencode("Narahubung {$member['email']} sudah mengaktifkan akunnya. Kirim ulang aktivasi hanya untuk yang belum aktif."));
            exit();
        }

        // Throttle: block a second mail to the same CP inside the cooldown so a
        // double-click (or an impatient primary) can't flood their inbox.
        $cooldown = (int) VENDOR_CP_RESEND_COOLDOWN_MIN;
        $last_sent = cp_last_invite_sent_map($conn, $vendor_id)[$target_id] ?? null;
        if ($last_sent && strtotime($last_sent) > strtotime("-{$cooldown} minutes")) {
            $wait = max(1, (int) ceil((strtotime($last_sent) + $cooldown * 60 - time()) / 60));
            header("Location: contacts.php?err=" . urlencode("Email aktivasi baru saja dikirim ke {$member['email']}. Mohon tunggu {$wait} menit lagi sebelum mengirim ulang (periksa juga folder spam)."));
            exit();
        }

        $sent = issue_cp_activation_invite($conn, $target_id, $vendor_id, $member['email'], (string) $member['full_name'], true);
        vca_log($conn, $vendor_id, 'cp_invite_resent', [
            'target_user_id' => $target_id,
            'target_email'   => $member['email'],
            'details'        => $sent ? 'Tautan aktivasi dikirim ulang.' : 'Pengiriman ulang GAGAL (SMTP).',
        ]);

        if (!$sent) {
            header("Location: contacts.php?err=" . urlencode("Gagal mengirim email aktivasi ke {$member['email']}. Silakan coba lagi beberapa saat lagi."));
            exit();
        }
        $days = (int) VENDOR_CP_INVITE_DAYS;
        header("Location: contacts.php?ok=" . urlencode("Email aktivasi telah dikirim ulang ke {$member['email']}. Tautan baru berlaku {$days} hari — mohon minta yang bersangkutan memeriksa folder spam bila tidak ditemukan."));
        exit();
    }
}

// =========================================================================
// CHANGE OWN EMAIL  (any CP; held pending until the NEW address is verified)
// =========================================================================
if ($action === 'change_email') {

    $new_email = trim($_POST['new_email'] ?? '');
    $current_pw = (string) ($_POST['current_password'] ?? '');
    $me_id = (int) $_SESSION['user_id'];

    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        header("Location: contacts.php?err=" . urlencode("Alamat email baru tidak valid."));
        exit();
    }

    // Verify the caller's current password before allowing an email change.
    $meStmt = $conn->prepare("SELECT email, password_hash FROM users WHERE id = ? LIMIT 1");
    $meStmt->bind_param("i", $me_id);
    $meStmt->execute();
    $me = $meStmt->get_result()->fetch_assoc();
    if (!$me || !password_verify($current_pw, $me['password_hash'])) {
        header("Location: contacts.php?err=" . urlencode("Kata sandi Anda salah. Perubahan email dibatalkan."));
        exit();
    }
    if (strcasecmp($new_email, $me['email']) === 0) {
        header("Location: contacts.php?err=" . urlencode("Email baru sama dengan email Anda saat ini."));
        exit();
    }

    // The new address must not already belong to another account.
    $dup = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
    $dup->bind_param("s", $new_email);
    $dup->execute();
    if ($dup->get_result()->fetch_assoc()) {
        header("Location: contacts.php?err=" . urlencode("Email tersebut sudah digunakan oleh akun lain."));
        exit();
    }

    $token   = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", strtotime('+1 day'));
    $upd = $conn->prepare("UPDATE users SET pending_email = ?, email_change_token = ?, email_change_expires = ? WHERE id = ?");
    $upd->bind_param("sssi", $new_email, $token, $expires, $me_id);
    $upd->execute();

    // Verification link goes to the NEW inbox — proving the CP controls it.
    $link = app_root_url() . '/verify_email_change.php?token=' . $token;
    $mail = _buildMailer();
    $mail->Timeout = 20; // Hostinger's TLS handshake alone runs ~4s; 6s was too tight
    $mail->addAddress($new_email);
    $mail->isHTML(true);
    $mail->Subject = 'Konfirmasi Perubahan Email — Vendor Sourcing System';
    $mail->Body = 'Halo,<br><br>Kami menerima permintaan untuk mengubah email login akun vendor Anda menjadi alamat ini. '
        . 'Klik tautan berikut untuk mengonfirmasi (berlaku 24 jam):<br><br>'
        . '<a href="' . $link . '">Konfirmasi Perubahan Email</a><br>' . $link
        . '<br><br>Jika Anda tidak meminta perubahan ini, abaikan email ini.';

    // If the verification mail never leaves, the CP has no way to complete the
    // change — so undo the pending state rather than leaving a dead token behind
    // and telling them to check an inbox that will stay empty.
    if (!_sendMail($mail)) {
        $undo = $conn->prepare(
            "UPDATE users
                SET pending_email = NULL, email_change_token = NULL, email_change_expires = NULL
              WHERE id = ?"
        );
        $undo->bind_param("i", $me_id);
        $undo->execute();
        header("Location: contacts.php?err=" . urlencode("Gagal mengirim email konfirmasi ke {$new_email}. Perubahan dibatalkan — silakan coba lagi beberapa saat lagi."));
        exit();
    }

    // Notify the OLD inbox so a hostile change is visible to the real owner.
    // Best-effort: the change is already recoverable via the link just sent.
    $notify = _buildMailer();
    $notify->Timeout = 20;
    $notify->addAddress($me['email']);
    $notify->isHTML(true);
    $notify->Subject = 'Pemberitahuan: Permintaan Perubahan Email Akun Vendor';
    $notify->Body = 'Halo,<br><br>Ada permintaan untuk mengubah email login akun Anda menjadi <strong>' . htmlspecialchars($new_email) . '</strong>. '
        . 'Perubahan hanya berlaku setelah dikonfirmasi dari email baru tersebut.<br><br>'
        . 'Jika ini bukan Anda, segera hubungi tim Procurement Kawan Lama.';
    _sendMail($notify);

    vca_log($conn, $vendor_id, 'email_change_requested', ['target_user_id' => $me_id, 'target_email' => $new_email, 'details' => 'Dari ' . $me['email']]);
    header("Location: contacts.php?ok=" . urlencode("Tautan konfirmasi telah dikirim ke {$new_email}. Perubahan berlaku setelah Anda mengklik tautan tersebut."));
    exit();
}

// =========================================================================
// SET USERNAME  (any CP; alternate login id used when email is inaccessible)
// =========================================================================
if ($action === 'set_username') {

    $me_id = (int) $_SESSION['user_id'];
    $new_username = trim($_POST['new_username'] ?? '');

    // Format: 4-30 chars, letters/digits/.-_ only. The '@' exclusion guarantees
    // a username can never collide with an email address (login matches either).
    if (!preg_match('/^[A-Za-z0-9._-]{4,30}$/', $new_username)) {
        header("Location: contacts.php?err=" . urlencode("Username tidak valid. Gunakan 4–30 karakter: huruf, angka, titik, garis bawah, atau strip."));
        exit();
    }

    // Must be unique across all users (case-insensitive), excluding myself.
    if (username_is_taken($conn, $new_username, $me_id)) {
        header("Location: contacts.php?err=" . urlencode("Username \"{$new_username}\" sudah digunakan. Silakan pilih yang lain."));
        exit();
    }

    try {
        $upd = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
        $upd->bind_param("si", $new_username, $me_id);
        $upd->execute();
    } catch (mysqli_sql_exception $e) {
        // Unique-index race or other DB error.
        header("Location: contacts.php?err=" . urlencode("Gagal menyimpan username. Silakan coba lagi."));
        exit();
    }

    vca_log($conn, $vendor_id, 'username_changed', ['target_user_id' => $me_id, 'target_email' => $_SESSION['email'] ?? null, 'details' => 'Username diubah menjadi ' . $new_username]);
    header("Location: contacts.php?ok=" . urlencode("Username Anda telah diperbarui menjadi \"{$new_username}\". Anda dapat login menggunakan username ini."));
    exit();
}

// =========================================================================
// TRANSFER PRIMARY  (current primary hands the owner role to an active member)
// =========================================================================
if ($action === 'transfer_primary') {

    $target_id = (int) ($_POST['target_id'] ?? 0);
    $member = load_vendor_member($conn, $target_id, $vendor_id);

    if (!$member) {
        header("Location: contacts.php?err=" . urlencode("Narahubung tujuan tidak ditemukan."));
        exit();
    }
    // Target must be an ACTIVE member who can actually log in.
    $canReceive = $member['contact_role'] === 'member'
        && $member['contact_status'] === 'active';
    // is_active must be 1 (has set a password / verified).
    $act = $conn->prepare("SELECT is_active FROM users WHERE id = ?");
    $act->bind_param("i", $target_id);
    $act->execute();
    $is_active = (int) ($act->get_result()->fetch_assoc()['is_active'] ?? 0);

    if (!$canReceive || $is_active !== 1) {
        header("Location: contacts.php?err=" . urlencode("Peran utama hanya dapat dialihkan ke narahubung yang sudah aktif."));
        exit();
    }

    $me_id = (int) $_SESSION['user_id'];
    $conn->begin_transaction();
    try {
        // Demote current primary -> member
        $d = $conn->prepare("UPDATE users SET contact_role = 'member' WHERE id = ? AND vendor_id = ?");
        $d->bind_param("ii", $me_id, $vendor_id);
        $d->execute();
        // Promote target -> primary
        $p = $conn->prepare("UPDATE users SET contact_role = 'primary' WHERE id = ? AND vendor_id = ?");
        $p->bind_param("ii", $target_id, $vendor_id);
        $p->execute();
        // Keep legacy 1:1 pointer (vendors.user_id) aimed at the new primary.
        $v = $conn->prepare("UPDATE vendors SET user_id = ? WHERE id = ?");
        $v->bind_param("ii", $target_id, $vendor_id);
        $v->execute();
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        header("Location: contacts.php?err=" . urlencode("Gagal mengalihkan peran utama. Silakan coba lagi."));
        exit();
    }

    // The acting user is now a member for the rest of this session.
    $_SESSION['contact_role'] = 'member';
    vca_log($conn, $vendor_id, 'primary_transferred', [
        'target_user_id' => $target_id,
        'target_email'   => $member['email'],
        'details'        => 'Dari user #' . $me_id . ' ke user #' . $target_id,
    ]);
    touch_cp_attestation($conn, $vendor_id);
    header("Location: contacts.php?ok=" . urlencode("Peran narahubung utama telah dialihkan ke {$member['email']}."));
    exit();
}

// =========================================================================
// ATTEST CP LIST  (primary confirms the list is current — resets the clock)
// =========================================================================
if ($action === 'attest_cp_list') {
    touch_cp_attestation($conn, $vendor_id);
    vca_log($conn, $vendor_id, 'cp_list_attested', ['details' => 'Daftar narahubung dikonfirmasi masih sesuai.']);
    header("Location: contacts.php?ok=" . urlencode("Terima kasih. Daftar narahubung telah dikonfirmasi terkini."));
    exit();
}

// Unknown action
header("Location: contacts.php");
exit();
