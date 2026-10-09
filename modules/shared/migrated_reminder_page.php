<?php
// modules/shared/migrated_reminder_page.php
// Shared "Reminder Vendor Migrasi" page, reused by modules/vendormgt and modules/admin.
// Lists every vendor still at registration_status = 'migrated' that has never filled
// the PQ, and mails them their login details + the steps they still owe us.
//
// The including wrapper MUST define, BEFORE requiring this file:
//   $path_to_root            e.g. "../../"
//   $reminder_required_role  role string or array of roles allowed to open this page
//   $reminder_dashboard_url  e.g. "modules/vendormgt/dashboard.php"

if (!isset($reminder_required_role) || !isset($reminder_dashboard_url) || !isset($path_to_root)) {
    die('migrated_reminder_page.php requires $path_to_root, $reminder_required_role and $reminder_dashboard_url to be set.');
}

require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/migrated_reminder_helper.php';

// 1. Security check — same shape as the Invite Vendor page.
$reminder_allowed_roles = (array) $reminder_required_role;
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $reminder_allowed_roles, true)) {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

migratedReminderEnsureTable($conn);

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$portal_base = migratedReminderPortalBase();
$login_url   = $portal_base . '/login.php';

// Filters (also drive the send endpoint, so "select all" and "send" agree on the set).
$reminder_filter = (string) ($_GET['reminder'] ?? 'all');
$profile_filter  = (string) ($_GET['profile'] ?? 'all');
$filters = [
    'search'   => trim((string) ($_GET['search'] ?? '')),
    'reminder' => in_array($reminder_filter, ['all', 'never', 'sent'], true) ? $reminder_filter : 'all',
    'profile'  => in_array($profile_filter, ['all', 'incomplete', 'complete'], true) ? $profile_filter : 'all',
];

// ---------------------------------------------------------------------------
// AJAX: "Hentikan". Records the stop for this run so the chunk already running
// server-side abandons the vendors it has not reached yet. Deliberately a
// separate request rather than aborting the send fetch: cutting a request mid
// SMTP conversation can leave a half-transmitted message in the vendor's inbox.
// ---------------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'stop' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Sesi tidak valid. Muat ulang halaman.']);
        exit();
    }

    migratedReminderRequestStop(
        $conn,
        substr(trim((string) ($_POST['run_id'] ?? '')), 0, 64),
        isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null
    );

    echo json_encode(['ok' => true]);
    exit();
}

// ---------------------------------------------------------------------------
// AJAX: send a chunk of reminders. The browser walks the selected vendors in
// small batches so 600 emails never ride on one PHP request.
// ---------------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        http_response_code(403);
        echo json_encode(['error' => 'Sesi tidak valid. Muat ulang halaman.']);
        exit();
    }

    // Server-side gate — the greyed-out button alone would not stop a direct POST.
    if (!migratedReminderSendEnabled()) {
        http_response_code(403);
        echo json_encode(['error' => MIGRATED_REMINDER_DISABLED_MESSAGE, 'disabled' => true]);
        exit();
    }

    $ids = array_slice(array_values(array_unique(array_map('intval', (array) ($_POST['ids'] ?? [])))), 0, 25);
    if ($ids === []) {
        echo json_encode(['results' => []]);
        exit();
    }

    $force  = !empty($_POST['force']);
    $run_id = substr(trim((string) ($_POST['run_id'] ?? '')), 0, 64);

    // Everything this request needs from the session, read before the lock goes.
    $sent_by_id   = $_SESSION['user_id'] ?? null;
    $sent_by_name = $_SESSION['user_name'] ?? '';

    // PHP holds an exclusive lock on the session file for the whole request. A
    // chunk takes tens of seconds, and "Hentikan" is a second request from the
    // same session — without this it would queue behind the chunk it is meant to
    // interrupt and only land once the damage was done.
    session_write_close();

    // A chunk is ~10 SMTP round trips; the default 30s limit is too tight for that.
    // ignore_user_abort keeps a chunk that is already mid-flight writing its history
    // rows even if the operator closes the tab, so nothing is sent without a log.
    @set_time_limit(180);
    ignore_user_abort(true);

    // Re-resolve every vendor from the DB rather than trusting the posted ids:
    // anything that has since left 'migrated' or submitted a PQ simply drops out.
    $targets = migratedReminderFetchTargets($conn, ['vendor_ids' => $ids]);

    // One SMTP session for the whole chunk.
    $mail = _buildMailer();
    $mail->SMTPKeepAlive = true;

    $results     = [];
    $stopped     = false;
    $blocked     = false;
    $capped      = false;
    $retry_after = 0;
    $sent_24h    = migratedReminderSentLast24h($conn);
    foreach ($ids as $vid) {
        // Daily cap reached: end the run; the remaining vendors stay unsent.
        if ($sent_24h >= MIGRATED_REMINDER_DAILY_CAP) {
            $capped = true;
            break;
        }

        // Re-read the stop signal before every vendor. Checking here — between
        // messages, never during one — means a pressed "Hentikan" costs at most
        // the email already in the SMTP conversation, and that one goes out whole.
        if (!$stopped && migratedReminderIsStopped($conn, $run_id)) {
            $stopped = true;
        }
        if ($stopped) {
            $results[] = ['id' => $vid, 'status' => 'cancelled', 'message' => 'Dibatalkan — tidak ada email yang dikirim.'];
            continue;
        }

        if (!isset($targets[$vid])) {
            $results[] = ['id' => $vid, 'status' => 'skipped', 'message' => 'Tidak lagi memenuhi kriteria.'];
            continue;
        }
        $outcome  = migratedReminderSendOne($conn, $targets[$vid], [
            'portal_base'  => $portal_base,
            'sent_by_id'   => $sent_by_id,
            'sent_by_name' => $sent_by_name,
            'force'        => $force,
            'mailer'       => $mail,
        ]);

        // A failed send can leave the kept-alive SMTP session mid-conversation;
        // the next message then rides a broken connection and can land as an
        // empty body. Drop the socket so the following vendor reconnects clean.
        if ($outcome['status'] === 'failed') {
            $mail->smtpClose();
        }

        // Our own send cap is full: hand this vendor back untouched. The browser
        // waits retry_after seconds and re-sends the same id; nothing is marked failed.
        if ($outcome['status'] === 'throttled') {
            $retry_after = (int) ($outcome['retry_after'] ?? 60);
            break;
        }

        if ($outcome['status'] === 'sent') {
            $sent_24h++;
        }

        $results[] = [
            'id'      => $vid,
            'status'  => $outcome['status'],
            'message' => $outcome['message'],
            'email'   => $targets[$vid]['login_email'],
            'sent_at' => date('d M Y H:i'),
        ];

        // SMTP itself refused the message (mailbox blocked/quota). Every following
        // message would be refused too, and hammering a blocked mailbox prolongs the
        // block — so end the run here and leave the remaining vendors unsent.
        if (!empty($outcome['fatal'])) {
            $blocked = true;
            break;
        }
    }

    $mail->smtpClose();

    echo json_encode([
        'results'     => $results,
        'stopped'     => $stopped,
        'blocked'     => $blocked,
        'capped'      => $capped,
        'retry_after' => $retry_after,
    ]);
    exit();
}

// ---------------------------------------------------------------------------
// AJAX: render the exact email one vendor would receive (banner omitted — it is
// attached at send time as an embedded image, which a browser cannot resolve).
// ---------------------------------------------------------------------------
if (($_GET['ajax'] ?? '') === 'preview') {
    $vid     = (int) ($_GET['vendor_id'] ?? 0);
    $targets = migratedReminderFetchTargets($conn, ['vendor_ids' => [$vid]]);
    header('Content-Type: text/html; charset=utf-8');
    if (!isset($targets[$vid])) {
        echo '<p style="font-family:sans-serif;padding:20px;">Vendor tidak ditemukan atau tidak lagi memenuhi kriteria.</p>';
        exit();
    }
    $content = migratedReminderBuildEmail($targets[$vid], ['portal_base' => $portal_base, 'with_banner' => false]);
    echo '<div style="font-family:sans-serif;padding:12px 20px;background:#111;color:#fff;font-size:13px;">'
       . '<strong>Subjek:</strong> ' . htmlspecialchars($content['subject'])
       . ' &nbsp;&middot;&nbsp; <strong>Kepada:</strong> ' . htmlspecialchars($targets[$vid]['login_email'])
       . '</div>';
    echo $content['html'];
    exit();
}

// --- Recipient list ---------------------------------------------------------
$targets = migratedReminderFetchTargets($conn, $filters);

$stat_total      = count($targets);
$stat_never      = 0;
$stat_incomplete = 0;
foreach ($targets as $t) {
    if (empty($t['send_count']))    $stat_never++;
    if (empty($t['profile_complete'])) $stat_incomplete++;
}

$send_enabled  = migratedReminderSendEnabled();
$sent_24h_page = migratedReminderSentLast24h($conn);

// --- Recent send history ----------------------------------------------------
$history = [];
$hist_res = $conn->query("
    SELECT company_name, recipient_email, status, sent_by_name, created_at
    FROM vendor_migration_reminders
    ORDER BY created_at DESC, id DESC
    LIMIT 100
");
if ($hist_res) {
    while ($row = $hist_res->fetch_assoc()) {
        $history[] = $row;
    }
}

$page_title = "Reminder Vendor Migrasi";
include $path_to_root . 'includes/header.php';
?>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>Reminder Vendor Migrasi
        </span>
        <a href="<?= $path_to_root . $reminder_dashboard_url ?>" class="btn btn-sm btn-nav">
            <i class="fas fa-arrow-left me-1"></i> Dashboard
        </a>
    </div>
</nav>

<div class="container-fluid px-4 pb-5">

    <?php if (!$send_enabled): ?>
        <div class="alert alert-secondary border-0 border-start border-4 border-secondary shadow-sm rounded-3">
            <i class="fas fa-ban me-2"></i>
            <strong><?= htmlspecialchars(MIGRATED_REMINDER_DISABLED_MESSAGE) ?></strong>
        </div>
    <?php else: ?>
        <div class="alert alert-warning border-0 border-start border-4 border-warning shadow-sm rounded-3 small">
            <i class="fas fa-gauge-high me-2"></i>
            Batas harian: <strong><?= (int) MIGRATED_REMINDER_DAILY_CAP ?> email per 24 jam</strong> (semua pengguna).
            Sudah terkirim <strong><?= (int) $sent_24h_page ?></strong> dalam 24 jam terakhir.
            Pengiriman berhenti otomatis saat batas tercapai.
        </div>
    <?php endif; ?>

    <div class="alert alert-info border-0 border-start border-4 border-info shadow-sm rounded-3 small">
        <i class="fas fa-info-circle me-2"></i>
        Daftar di bawah berisi vendor berstatus <strong>Migrated</strong> yang <strong>belum pernah mengisi PQ</strong>.
        Email berisi detail login (email &amp; kata sandi default), informasi bahwa mereka akan diarahkan membuat
        kata sandi baru saat login pertama, langkah melengkapi profil &amp; PQ, serta tautan portal:
        <code><?= htmlspecialchars($login_url) ?></code>.
        Vendor yang sudah dikirimi dalam <?= (int) MIGRATED_REMINDER_MIN_RESEND_HOURS ?> jam terakhir otomatis dilewati,
        kecuali opsi kirim ulang dicentang.
        <br>
        <strong>Pengiriman sengaja dibuat pelan</strong> (sekitar 1 email per 40 detik) agar akun email tidak diblokir
        oleh hosting. <strong>Biarkan tab ini tetap terbuka</strong> sampai selesai; jika server email menolak,
        proses berhenti otomatis dan vendor yang tersisa tidak dikirimi.
    </div>

    <?php if (MIGRATED_REMINDER_GUIDE_URL === ''): ?>
        <div class="alert alert-warning border-0 border-start border-4 border-warning shadow-sm rounded-3 small">
            <i class="fas fa-triangle-exclamation me-2"></i>
            Tautan <strong>Panduan Penggunaan VSS</strong> belum diatur, sehingga tombol panduan
            <strong>tidak akan muncul</strong> di email. Isi konstanta <code>MIGRATED_REMINDER_GUIDE_URL</code>
            pada <code>includes/migrated_reminder_helper.php</code> untuk menampilkannya.
        </div>
    <?php endif; ?>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted small fw-bold mb-1">Vendor Sesuai Filter</h6>
                    <h2 class="fw-bold mb-0"><?= $stat_total ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted small fw-bold mb-1">Belum Pernah Dikirimi</h6>
                    <h2 class="fw-bold mb-0 text-primary"><?= $stat_never ?></h2>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body">
                    <h6 class="text-uppercase text-muted small fw-bold mb-1">Dokumen Belum Lengkap</h6>
                    <h2 class="fw-bold mb-0 text-danger"><?= $stat_incomplete ?></h2>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
        <div class="card-header bg-white py-3 border-0">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-bold small mb-1">Cari</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Nama perusahaan, narahubung, atau email"
                           value="<?= htmlspecialchars($filters['search']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small mb-1">Status Reminder</label>
                    <select name="reminder" class="form-select form-select-sm">
                        <option value="all"   <?= $filters['reminder'] === 'all'   ? 'selected' : '' ?>>Semua</option>
                        <option value="never" <?= $filters['reminder'] === 'never' ? 'selected' : '' ?>>Belum pernah dikirimi</option>
                        <option value="sent"  <?= $filters['reminder'] === 'sent'  ? 'selected' : '' ?>>Sudah pernah dikirimi</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-bold small mb-1">Kelengkapan Dokumen</label>
                    <select name="profile" class="form-select form-select-sm">
                        <option value="all"        <?= $filters['profile'] === 'all'        ? 'selected' : '' ?>>Semua</option>
                        <option value="incomplete" <?= $filters['profile'] === 'incomplete' ? 'selected' : '' ?>>Belum lengkap</option>
                        <option value="complete"   <?= $filters['profile'] === 'complete'   ? 'selected' : '' ?>>Sudah lengkap</option>
                    </select>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-sm btn-primary fw-bold">
                        <i class="fas fa-filter me-1"></i> Terapkan
                    </button>
                </div>
            </form>
        </div>

        <div class="card-body border-top bg-light py-3">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <button type="button" id="btnSend" class="btn <?= $send_enabled ? 'btn-primary' : 'btn-secondary' ?> fw-bold" disabled
                        <?= $send_enabled ? '' : 'title="' . htmlspecialchars(MIGRATED_REMINDER_DISABLED_MESSAGE) . '"' ?>>
                    <i class="fas fa-paper-plane me-1"></i> Kirim Reminder (<span id="selCount">0</span>)
                </button>
                <button type="button" id="btnStop" class="btn btn-outline-danger d-none">
                    <i class="fas fa-stop me-1"></i> Hentikan
                </button>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="forceResend" <?= $send_enabled ? '' : 'disabled' ?>>
                    <label class="form-check-label small" for="forceResend">
                        Izinkan kirim ulang (abaikan jeda <?= (int) MIGRATED_REMINDER_MIN_RESEND_HOURS ?> jam)
                    </label>
                </div>
                <div id="sendSummary" class="small text-muted ms-auto"></div>
            </div>
            <div id="progressWrap" class="progress mt-3 d-none" style="height: 8px;">
                <div id="progressBar" class="progress-bar bg-primary" role="progressbar" style="width: 0%"></div>
            </div>
        </div>

        <div class="table-responsive" style="max-height: 620px; overflow-y: auto;">
            <table class="table table-sm table-hover align-middle mb-0 small">
                <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                    <tr>
                        <th class="ps-3" style="width: 36px;">
                            <input type="checkbox" class="form-check-input" id="checkAll">
                        </th>
                        <th>Perusahaan</th>
                        <th>Narahubung</th>
                        <th>Email Login</th>
                        <th class="text-center">Dokumen</th>
                        <th class="text-center">Kata Sandi</th>
                        <th class="text-center">Reminder Terakhir</th>
                        <th class="text-center pe-3">Hasil</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($targets === []): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Tidak ada vendor yang cocok dengan filter ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($targets as $vid => $v): ?>
                        <tr data-vendor-id="<?= (int) $vid ?>">
                            <td class="ps-3">
                                <input type="checkbox" class="form-check-input row-check" value="<?= (int) $vid ?>">
                            </td>
                            <td>
                                <div class="fw-bold"><?= htmlspecialchars($v['company_name'] ?? '-') ?></div>
                                <div class="text-muted" style="font-size: 0.72rem;">
                                    <?= htmlspecialchars($v['company_type'] ?? '') ?>
                                    <?= !empty($v['legal_entity_type']) ? ' · ' . htmlspecialchars($v['legal_entity_type']) : '' ?>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($v['contact_person'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($v['login_email']) ?></td>
                            <td class="text-center">
                                <?php if ($v['profile_complete']): ?>
                                    <span class="badge bg-success">Lengkap</span>
                                <?php else: ?>
                                    <!-- Just the count. Under a column headed "Dokumen"
                                         the number can only mean how many are missing,
                                         so "Kurang" was a word repeated down every row
                                         of the table without adding anything. The full
                                         list stays on hover. -->
                                    <span class="badge bg-danger" title="Belum ada: <?= htmlspecialchars(implode(', ', $v['profile_missing'])) ?>">
                                        <?= count($v['profile_missing']) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($v['uses_default_password']): ?>
                                    <span class="badge bg-secondary">Default</span>
                                <?php else: ?>
                                    <span class="badge bg-info text-dark" title="Vendor sudah membuat kata sandi sendiri — email tidak akan mencantumkan kata sandi default.">Sudah diubah</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-nowrap">
                                <?php if (!empty($v['last_sent_at'])): ?>
                                    <?= htmlspecialchars(date('d M Y H:i', strtotime($v['last_sent_at']))) ?>
                                    <div class="text-muted" style="font-size: 0.7rem;"><?= (int) $v['send_count'] ?>x</div>
                                <?php else: ?>
                                    <span class="text-muted">Belum pernah</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center pe-3 text-nowrap">
                                <span class="result-cell text-muted">—</span>
                                <a href="?ajax=preview&vendor_id=<?= (int) $vid ?>" target="_blank"
                                   class="ms-2 text-decoration-none" title="Pratinjau email">
                                    <i class="fas fa-eye"></i>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Riwayat pengiriman -->
    <div class="card shadow-sm border-0 rounded-4 overflow-hidden mt-4">
        <div class="card-header bg-light py-3 border-0 d-flex align-items-center justify-content-between">
            <h6 class="mb-0 fw-bold"><i class="fas fa-history me-2 text-primary"></i> Riwayat Pengiriman (100 terakhir)</h6>
            <span class="badge bg-secondary"><?= count($history) ?></span>
        </div>
        <div class="card-body p-0">
            <?php if ($history === []): ?>
                <p class="text-muted small text-center my-4 mb-0">Belum ada reminder yang dikirim.</p>
            <?php else: ?>
                <div class="table-responsive" style="max-height: 380px; overflow-y: auto;">
                    <table class="table table-sm table-hover align-middle mb-0 small">
                        <thead class="table-light" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th class="ps-3">Waktu</th>
                                <th>Perusahaan</th>
                                <th>Email</th>
                                <th>Dikirim Oleh</th>
                                <th class="text-center pe-3">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($history as $h): ?>
                            <tr>
                                <td class="ps-3 text-nowrap"><?= htmlspecialchars(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
                                <td><?= htmlspecialchars($h['company_name'] ?: '-') ?></td>
                                <td><?= htmlspecialchars($h['recipient_email']) ?></td>
                                <td><?= htmlspecialchars($h['sent_by_name'] ?: '-') ?></td>
                                <td class="text-center pe-3">
                                    <?php if ($h['status'] === 'sent'): ?>
                                        <span class="badge bg-success">Terkirim</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Gagal</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Konfirmasi kirim reminder (menggantikan confirm() bawaan browser) -->
<div class="modal fade" id="confirmSendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold text-white">
                    <i class="fas fa-paper-plane me-2"></i> Konfirmasi Pengiriman
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-3">
                    <i class="fas fa-envelope-open-text fa-4x text-primary opacity-50"></i>
                </div>
                <h4 class="fw-bold text-dark mb-2">
                    Kirim reminder ke <span id="confirmSendCount">0</span> vendor?
                </h4>
                <p class="text-muted mb-3">
                    Email dikirim satu per satu. <strong>Jangan tutup halaman ini</strong> sampai proses selesai.
                    Anda punya <strong>5 detik</strong> untuk membatalkan lewat tombol <strong>Hentikan</strong>
                    sebelum email pertama dikirim.
                </p>
                <div id="confirmForceNote" class="alert alert-warning border-0 small text-start mb-0 d-none">
                    <i class="fas fa-rotate-right me-1"></i>
                    Opsi <strong>kirim ulang</strong> aktif — vendor yang baru saja dikirimi
                    (&lt; <?= (int) MIGRATED_REMINDER_MIN_RESEND_HOURS ?> jam) tetap akan menerima email lagi.
                </div>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-pill" data-bs-dismiss="modal">Batal</button>
                <button type="button" id="confirmSendBtn" class="btn btn-primary fw-bold px-4 rounded-pill shadow-sm">
                    <i class="fas fa-paper-plane me-1"></i> Ya, Kirim Sekarang
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var CSRF       = <?= json_encode($_SESSION['csrf_token']) ?>;
    var SEND_ENABLED = <?= $send_enabled ? 'true' : 'false' ?>;   // server-side gate (MIGRATED_REMINDER_SEND_ENABLED)
    var CHUNK_SIZE = 1;            // one vendor per request: pacing happens between requests
    var PACE_SECONDS = 40;         // average gap between emails (jittered ±25%)
    var haltReason = '';           // set when the run ends itself (SMTP refusal, lost connection)
    var GRACE_SECONDS = 5;         // undo window before the first email goes out
    var stopped    = false;
    var runId      = '';           // minted per run; scopes the stop signal to it
    var graceTimer = null;
    var graceCancel = null;        // set while the countdown is still running

    var checkAll   = document.getElementById('checkAll');
    var btnSend    = document.getElementById('btnSend');
    var btnStop    = document.getElementById('btnStop');
    var selCount   = document.getElementById('selCount');
    var summary    = document.getElementById('sendSummary');
    var progWrap   = document.getElementById('progressWrap');
    var progBar    = document.getElementById('progressBar');
    var forceBox   = document.getElementById('forceResend');
    var btnStopHtml = btnStop.innerHTML;   // restored after a run ends

    function rowChecks() {
        return Array.prototype.slice.call(document.querySelectorAll('.row-check'));
    }
    function selectedIds() {
        return rowChecks().filter(function (c) { return c.checked; })
                          .map(function (c) { return parseInt(c.value, 10); });
    }
    function refreshCount() {
        var n = selectedIds().length;
        selCount.textContent = n;
        btnSend.disabled = !SEND_ENABLED || (n === 0);
    }

    if (checkAll) {
        checkAll.addEventListener('change', function () {
            rowChecks().forEach(function (c) { c.checked = checkAll.checked; });
            refreshCount();
        });
    }
    rowChecks().forEach(function (c) {
        c.addEventListener('change', refreshCount);
    });

    function setResult(id, status, message) {
        var row = document.querySelector('tr[data-vendor-id="' + id + '"]');
        if (!row) return;
        var cell = row.querySelector('.result-cell');
        if (!cell) return;
        var cls = status === 'sent' ? 'bg-success'
                : status === 'failed' ? 'bg-danger'
                : status === 'cancelled' ? 'bg-secondary' : 'bg-warning text-dark';
        var label = status === 'sent' ? 'Terkirim'
                  : status === 'failed' ? 'Gagal'
                  : status === 'cancelled' ? 'Dibatalkan' : 'Dilewati';
        cell.className = 'result-cell';
        cell.innerHTML = '<span class="badge ' + cls + '" title="' + (message || '').replace(/"/g, '&quot;') + '">' + label + '</span>';
    }

    function sendChunk(ids) {
        var body = new URLSearchParams();
        body.append('csrf_token', CSRF);
        body.append('run_id', runId);
        if (forceBox && forceBox.checked) body.append('force', '1');
        ids.forEach(function (id) { body.append('ids[]', id); });

        return fetch('?ajax=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) {
            if (!r.ok) {
                // Surface the server's own reason (e.g. sending disabled) instead of a bare status.
                return r.json().catch(function () { return {}; }).then(function (d) {
                    throw new Error(d.error || ('HTTP ' + r.status));
                });
            }
            return r.json();
        });
    }

    // Konfirmasi lewat modal Bootstrap. Bundle Bootstrap dimuat di footer, jadi
    // instance baru dibuat saat tombol diklik — bukan saat skrip ini di-parse.
    var confirmModalEl = document.getElementById('confirmSendModal');
    var confirmModal   = null;
    var confirmCount   = document.getElementById('confirmSendCount');
    var confirmForce   = document.getElementById('confirmForceNote');
    var confirmBtn     = document.getElementById('confirmSendBtn');
    var pendingIds     = [];

    btnSend.addEventListener('click', function () {
        var ids = selectedIds();
        if (ids.length === 0) return;

        pendingIds = ids;
        confirmCount.textContent = ids.length;
        confirmForce.classList.toggle('d-none', !(forceBox && forceBox.checked));

        if (!confirmModal) confirmModal = new bootstrap.Modal(confirmModalEl);
        confirmModal.show();
    });

    confirmBtn.addEventListener('click', function () {
        if (confirmModal) confirmModal.hide();
        runSend(pendingIds);
    });

    function runSend(ids) {
        if (!ids || ids.length === 0) return;

        stopped = false;
        haltReason = '';
        runId   = 'r' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
        btnSend.disabled = true;
        btnStop.disabled = false;
        btnStop.innerHTML = btnStopHtml;
        btnStop.classList.remove('d-none');
        progWrap.classList.remove('d-none');
        progBar.style.width = '0%';

        var done = 0, sent = 0, failed = 0, skipped = 0, cancelled = 0;

        // Vendors from `from` onwards were never mailed — label them so the row
        // says what happened rather than staying blank.
        function finish(from) {
            for (var k = from; k < ids.length; k++) {
                setResult(ids[k], 'cancelled', 'Dibatalkan — tidak ada email yang dikirim.');
                cancelled++;
            }
            graceCancel = null;
            btnStop.classList.add('d-none');
            btnStop.disabled = false;
            btnStop.innerHTML = btnStopHtml;
            refreshCount();
            summary.innerHTML = (stopped ? '<strong class="text-danger">Dihentikan.</strong>' : '<strong>Selesai.</strong>')
                              + ' Terkirim ' + sent + ' · Gagal ' + failed + ' · Dilewati ' + skipped
                              + (cancelled ? ' · Dibatalkan ' + cancelled : '')
                              + (haltReason ? '<div class="text-danger mt-1">' + haltReason + '</div>' : '');
        }

        // Count down in the summary line, then call next(). Honours "Hentikan".
        function waitThen(seconds, label, next) {
            var left = Math.ceil(seconds);
            (function tick() {
                if (stopped || left <= 0) { next(); return; }
                summary.textContent = progressText() + ' — ' + label + ' ' + left + ' detik…';
                left--;
                graceTimer = setTimeout(tick, 1000);
            })();
        }
        function progressText() {
            return 'Memproses ' + done + ' / ' + ids.length
                 + ' — terkirim ' + sent + ', gagal ' + failed + ', dilewati ' + skipped;
        }

        function step(i) {
            if (stopped || i >= ids.length) {
                finish(i);
                return;
            }

            var chunk = ids.slice(i, i + CHUNK_SIZE);
            var handled = 0, retryAfter = 0, halt = false;
            sendChunk(chunk).then(function (data) {
                (data.results || []).forEach(function (r) {
                    handled++;
                    setResult(r.id, r.status, r.message);
                    if (r.status === 'sent') sent++;
                    else if (r.status === 'failed') failed++;
                    else if (r.status === 'cancelled') cancelled++;
                    else skipped++;
                });
                // The server may have stopped mid-chunk before the browser knew.
                if (data.stopped) stopped = true;
                // SMTP refused the message: end the run, leave the rest unsent.
                if (data.blocked) {
                    halt = true;
                    haltReason = 'Server email menolak pengiriman (kemungkinan kuota/blokir). '
                               + 'Pengiriman dihentikan otomatis — tunggu atau hubungi admin hosting, lalu coba lagi.';
                }
                // Daily cap reached: end the run, leave the rest unsent.
                if (data.capped) {
                    halt = true;
                    haltReason = 'Batas harian <?= (int) MIGRATED_REMINDER_DAILY_CAP ?> email per 24 jam tercapai. '
                               + 'Vendor yang tersisa tidak dikirimi — lanjutkan besok.';
                }
                // Our own send cap is full: wait for it, then retry the same vendor.
                retryAfter = parseInt(data.retry_after, 10) || 0;
            }).catch(function (e) {
                // Unknown whether this one went out, so do not blindly carry on:
                // mark it, and stop the run so the operator can check history first.
                chunk.forEach(function (id) {
                    handled++;
                    setResult(id, 'failed', 'Koneksi terputus: ' + e.message);
                    failed++;
                });
                halt = true;
                haltReason = 'Koneksi terputus. Pengiriman dihentikan — periksa riwayat sebelum melanjutkan.';
            }).then(function () {
                done += handled;
                progBar.style.width = Math.round(done / ids.length * 100) + '%';
                summary.textContent = progressText();

                if (halt) { stopped = true; finish(i + handled); return; }
                if (retryAfter > 0 && handled === 0) {
                    waitThen(retryAfter + 2, 'kuota kirim penuh, menunggu', function () { step(i); });
                    return;
                }
                // Slow on purpose: a fixed, jittered gap between vendors keeps the
                // shared mailbox well under Hostinger's abuse thresholds.
                waitThen(PACE_SECONDS * (0.75 + Math.random() * 0.5), 'jeda berikutnya', function () {
                    step(i + handled);
                });
            });
        }

        // Jeda aman sebelum email pertama. Without it "Hentikan" is unwinnable on
        // a small batch: the first message is already inside the SMTP conversation
        // a few milliseconds after the click, long before a hand can reach the
        // button. Nothing at all is sent while this countdown runs.
        var left = GRACE_SECONDS;
        graceCancel = function () { clearTimeout(graceTimer); finish(0); };

        (function tick() {
            if (stopped) { graceCancel = null; finish(0); return; }
            if (left <= 0) {
                graceCancel = null;
                summary.textContent = 'Mulai mengirim…';
                step(0);
                return;
            }
            summary.innerHTML = '<strong>Mulai mengirim dalam ' + left + ' detik…</strong> '
                              + '<span class="text-muted">Tekan <strong>Hentikan</strong> untuk membatalkan — belum ada email yang dikirim.</span>';
            left--;
            graceTimer = setTimeout(tick, 1000);
        })();
    }

    // Stopping is a separate request, not an abort of the send fetch: killing a
    // request mid SMTP conversation is what leaves a half-written email in an
    // inbox. The server finishes the message it is on, then abandons the rest.
    btnStop.addEventListener('click', function () {
        stopped = true;
        btnStop.disabled = true;
        btnStop.innerHTML = '<i class="fas fa-hourglass-half me-1"></i> Menghentikan…';
        summary.innerHTML = '<span class="text-danger">Menghentikan — email yang sedang berjalan diselesaikan, sisanya dibatalkan.</span>';

        var body = new URLSearchParams();
        body.append('csrf_token', CSRF);
        body.append('run_id', runId);
        fetch('?ajax=stop', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).catch(function () { /* the browser-side stop still holds */ });

        // Pressed during the countdown: nothing has been requested yet, so the
        // whole run dies here — zero emails, no waiting for a tick to come round.
        if (graceCancel) {
            var cancel = graceCancel;
            graceCancel = null;
            cancel();
        }
    });

    refreshCount();
})();
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
