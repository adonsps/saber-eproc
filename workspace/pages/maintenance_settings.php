<?php
// pages/maintenance_settings.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/header.php';

if ($_SESSION['role'] !== 'Category Manager') {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak. Khusus Category Manager.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

$vss_error = null;
$vss       = null;

try {
    $vss_host = getenv('DB_HOST') ?: 'localhost';
    $is_qa    = (strpos(__DIR__, '/qa/') !== false || strpos($_SERVER['REQUEST_URI'] ?? '', '/qa/') !== false);
    if (php_sapi_name() === 'cli-server') {
        $vss_dsn  = "mysql:host=127.0.0.1;dbname=u894129685_vmsportal;charset=utf8mb4";
        $vss_user = 'root'; $vss_pass = '';
    } else {
        $vss_db   = $is_qa ? 'u894129685_qa_vmsportal' : 'u894129685_vmsportal';
        $vss_dsn  = "mysql:host=$vss_host;dbname=$vss_db;charset=utf8mb4";
        $vss_user = 'u894129685_adonisvm'; $vss_pass = 'Xcel007!';
    }
    $vss = new PDO($vss_dsn, $vss_user, $vss_pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $vss->exec("CREATE TABLE IF NOT EXISTS maintenance_settings (
        id TINYINT(1) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 0,
        note TEXT DEFAULT NULL,
        updated_by VARCHAR(255) DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $vss->exec("CREATE TABLE IF NOT EXISTS maintenance_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        action ENUM('ON','OFF') NOT NULL,
        note TEXT DEFAULT NULL,
        changed_by VARCHAR(255) NOT NULL,
        changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $vss->exec("INSERT IGNORE INTO maintenance_settings (id, is_active) VALUES (1, 0)");

} catch (\PDOException $e) {
    $vss_error = $e->getMessage();
}

// The gate helper lives with the VSS app at the repo root. It owns the on-disk
// flag, which is what keeps maintenance mode switched on while the VSS database
// is being restored — a restore resets/recreates maintenance_settings, so the DB
// row alone silently turns maintenance off half way through.
require_once dirname(__DIR__, 2) . '/includes/maintenance_gate.php';

$msg        = null;
$msg_ok     = false;
$flag_error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $note       = trim($_POST['note'] ?? '');
    $changed_by = $_SESSION['name'] ?? $_SESSION['username'] ?? 'Unknown';

    if (empty($note)) {
        $msg = "Catatan tidak boleh kosong.";
    } else {
        $new_active   = ($_POST['action'] === 'toggle_on') ? 1 : 0;
        $action_label = $new_active ? 'ON' : 'OFF';

        // 1. Flag file first. It is the source the gate trusts most, and it is
        //    the only one that still works when the VSS DB is down/being fixed.
        [$flag_ok, $flag_msg] = vss_maintenance_set_file((bool)$new_active, $note, $changed_by);
        if (!$flag_ok) {
            $flag_error = $flag_msg;
        }

        // 2. Then the DB row + audit log, when the DB is reachable. A failure
        //    here no longer loses the toggle: the flag file already carries it.
        if (!$vss_error) {
            try {
                $vss->prepare("INSERT INTO maintenance_settings (id, is_active, note, updated_by, updated_at)
                               VALUES (1, ?, ?, ?, NOW())
                               ON DUPLICATE KEY UPDATE is_active=VALUES(is_active), note=VALUES(note),
                                                       updated_by=VALUES(updated_by), updated_at=NOW()")
                    ->execute([$new_active, $note, $changed_by]);
                $vss->prepare("INSERT INTO maintenance_log (action, note, changed_by) VALUES (?,?,?)")
                    ->execute([$action_label, $note, $changed_by]);
            } catch (\PDOException $e) {
                $vss_error = $e->getMessage();
            }
        }

        if ($flag_ok) {
            $msg    = $new_active ? "Maintenance diaktifkan. Vendor tidak dapat mengakses VSS." : "Maintenance dinonaktifkan. VSS kembali normal.";
            $msg_ok = true;
        } else {
            $msg = "Gagal menulis penanda maintenance ke disk. Status DB mungkin berubah, tetapi penanda yang tahan restore DB TIDAK aktif.";
        }
    }
}

$current   = null;
$history   = [];
if (!$vss_error) {
    try {
        $current = $vss->query("SELECT * FROM maintenance_settings WHERE id=1")->fetch();
        $history = $vss->query("SELECT * FROM maintenance_log ORDER BY changed_at DESC LIMIT 50")->fetchAll();
    } catch (\PDOException $e) {
        $vss_error = $e->getMessage();
    }
}

// What the gate itself would decide right now, from either source.
$flag_active = vss_maintenance_file_active();
$flag_meta   = vss_maintenance_file_meta();
$db_active   = (int)($current['is_active'] ?? 0);
$is_active   = ($flag_active || $db_active === 1) ? 1 : 0;
?>

<style>
@keyframes pulse-dot {
    0%, 100% { opacity: 1; transform: scale(1); }
    50%       { opacity: .5; transform: scale(1.4); }
}
.pulse-dot { animation: pulse-dot 1.8s ease-in-out infinite; }

@keyframes fade-in-up {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fade-in-up { animation: fade-in-up .35s ease both; }
</style>

<div class="max-w-6xl mx-auto py-8">

    <!-- ── Page Title + Toast row ── -->
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <h2 class="text-lg font-bold text-slate-700 tracking-tight">VSS Maintenance Mode</h2>
        </div>
        <!-- Live state pill -->
        <span class="inline-flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-full
            <?= $is_active ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' ?>">
            <span class="pulse-dot w-1.5 h-1.5 rounded-full <?= $is_active ? 'bg-red-500' : 'bg-emerald-500' ?>"></span>
            <?= $is_active ? 'MAINTENANCE AKTIF' : 'SISTEM NORMAL' ?>
        </span>
    </div>

    <!-- ── DB Error ── -->
    <?php if ($vss_error): ?>
    <div class="flex items-start gap-3 text-sm text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3 mb-5">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        <span>Koneksi VSS gagal: <?= htmlspecialchars($vss_error) ?></span>
    </div>
    <?php endif; ?>

    <!-- ── Flag file write failure ── -->
    <?php if ($flag_error): ?>
    <div class="flex items-start gap-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-5">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/></svg>
        <span><?= htmlspecialchars($flag_error) ?></span>
    </div>
    <?php endif; ?>

    <!-- ── Where the gate is reading its state from ── -->
    <div class="flex flex-wrap items-center gap-2 text-xs mb-5">
        <span class="inline-flex items-center gap-1.5 font-semibold px-2.5 py-1 rounded-lg
            <?= $flag_active ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-slate-100 text-slate-500' ?>">
            Penanda file: <?= $flag_active ? 'AKTIF' : 'tidak ada' ?>
        </span>
        <span class="inline-flex items-center gap-1.5 font-semibold px-2.5 py-1 rounded-lg
            <?= $db_active ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-slate-100 text-slate-500' ?>">
            Penanda database: <?= $db_active ? 'AKTIF' : 'tidak aktif' ?>
        </span>
        <span class="text-slate-400">
            Penanda file tetap bertahan saat database VSS di-restore; penanda database tidak.
        </span>
    </div>

    <!-- ── Toast ── -->
    <?php if ($msg): ?>
    <div class="fade-in-up flex items-center gap-3 text-sm px-4 py-3 rounded-xl border mb-5
        <?= $msg_ok ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-700' ?>">
        <?php if ($msg_ok): ?>
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <?php else: ?>
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01"/></svg>
        <?php endif; ?>
        <?= htmlspecialchars($msg) ?>
    </div>
    <?php endif; ?>

    <!-- ── Two-column grid ── -->
    <div class="grid grid-cols-1 lg:grid-cols-5 gap-5 items-start">

        <!-- LEFT: Status + Action (2 cols) -->
        <div class="lg:col-span-2 space-y-5">

            <!-- Status Hero Card -->
            <div class="relative overflow-hidden rounded-2xl shadow-md text-white
                <?= $is_active
                    ? 'bg-gradient-to-br from-red-500 to-rose-600'
                    : 'bg-gradient-to-br from-emerald-500 to-teal-600' ?>">
                <div class="absolute -top-8 -right-8 w-40 h-40 rounded-full bg-white opacity-5"></div>
                <div class="absolute -bottom-10 -left-6 w-32 h-32 rounded-full bg-white opacity-5"></div>

                <div class="relative p-6">
                    <div class="w-14 h-14 rounded-2xl bg-white/20 flex items-center justify-center mb-4 shadow-inner">
                        <?php if ($is_active): ?>
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                        </svg>
                        <?php else: ?>
                        <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8"
                                d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <?php endif; ?>
                    </div>

                    <p class="text-xs font-semibold uppercase tracking-widest opacity-75 mb-1">
                        <?= $is_active ? 'Maintenance Aktif' : 'Sistem Normal' ?>
                    </p>
                    <h3 class="text-xl font-extrabold leading-snug">
                        <?= $is_active ? 'VSS Sedang<br>Maintenance' : 'VSS Berjalan<br>Normal' ?>
                    </h3>

                    <?php if ($current && !empty($current['note'])): ?>
                    <p class="text-xs mt-3 opacity-75 leading-relaxed line-clamp-3">
                        <?= htmlspecialchars($current['note']) ?>
                    </p>
                    <?php endif; ?>
                </div>

                <?php if ($current && !empty($current['updated_by'])): ?>
                <div class="relative border-t border-white/20 px-6 py-3 flex items-center gap-2 text-xs opacity-70">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    <span class="truncate">
                        <b><?= htmlspecialchars($current['updated_by']) ?></b>
                        <?php if (!empty($current['updated_at'])): ?>
                        · <?= date('d M Y, H:i', strtotime($current['updated_at'])) ?> WIB
                        <?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action Card -->
            <?php if (!$vss_error): ?>
            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="flex">
                    <div class="w-1 flex-shrink-0 <?= $is_active ? 'bg-emerald-500' : 'bg-red-500' ?>"></div>
                    <div class="flex-1 p-5">

                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0
                                <?= $is_active ? 'bg-emerald-100' : 'bg-red-100' ?>">
                                <?php if ($is_active): ?>
                                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                <?php else: ?>
                                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                <?php endif; ?>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-800">
                                    <?= $is_active ? 'Nonaktifkan Maintenance' : 'Aktifkan Maintenance' ?>
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5 leading-relaxed">
                                    <?= $is_active
                                        ? 'Vendor akan bisa mengakses VSS kembali.'
                                        : 'Vendor akan diarahkan ke halaman maintenance.' ?>
                                </p>
                            </div>
                        </div>

                        <form method="POST" id="maintenanceForm">
                            <input type="hidden" name="action" value="<?= $is_active ? 'toggle_off' : 'toggle_on' ?>">
                            <label class="block text-xs font-semibold text-slate-500 mb-1.5">
                                Catatan <span class="text-red-400">*</span>
                            </label>
                            <textarea
                                id="noteField"
                                name="note"
                                rows="3"
                                placeholder="<?= $is_active
                                    ? 'Misal: Maintenance selesai, sistem kembali normal.'
                                    : 'Misal: Update database 00.00–02.00 WIB.' ?>"
                                class="w-full text-sm border border-slate-200 rounded-xl px-3.5 py-2.5 mb-4
                                       focus:outline-none focus:ring-2 focus:ring-indigo-300 focus:border-transparent
                                       resize-none placeholder-slate-300 transition"
                            ></textarea>

                            <?php if ($is_active): ?>
                            <button type="submit"
                                class="w-full inline-flex items-center justify-center gap-2 text-sm font-bold
                                       px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700
                                       active:scale-95 text-white transition-all shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                                Nonaktifkan — Buka Akses Vendor
                            </button>
                            <?php else: ?>
                            <button type="button" onclick="openConfirmModal()"
                                class="w-full inline-flex items-center justify-center gap-2 text-sm font-bold
                                       px-5 py-2.5 rounded-xl bg-red-600 hover:bg-red-700
                                       active:scale-95 text-white transition-all shadow-sm">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636"/>
                                </svg>
                                Aktifkan Maintenance
                            </button>
                            <?php endif; ?>
                        </form>

                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /LEFT -->

        <!-- RIGHT: History (3 cols) -->
        <div class="lg:col-span-3">
            <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-sm">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                        <span class="text-sm font-bold text-slate-700">Riwayat Perubahan</span>
                    </div>
                    <?php if (!empty($history)): ?>
                    <span class="text-xs font-medium text-slate-400 bg-slate-100 px-2.5 py-1 rounded-full">
                        <?= count($history) ?> entri
                    </span>
                    <?php endif; ?>
                </div>

                <?php if (empty($history)): ?>
                <div class="py-16 text-center">
                    <div class="w-12 h-12 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-3">
                        <svg class="w-6 h-6 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                    </div>
                    <p class="text-sm text-slate-400">Belum ada riwayat perubahan.</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-100 max-h-[520px] overflow-y-auto">
                    <?php foreach ($history as $log):
                        $words    = explode(' ', trim($log['changed_by']));
                        $initials = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                        $on       = ($log['action'] === 'ON');
                    ?>
                    <div class="flex items-start gap-3.5 px-5 py-4 hover:bg-slate-50 transition-colors">
                        <div class="w-9 h-9 rounded-full bg-indigo-100 text-indigo-600 text-xs font-bold
                                    flex items-center justify-center flex-shrink-0 mt-0.5">
                            <?= $initials ?>
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap mb-0.5">
                                <span class="text-sm font-semibold text-slate-700"><?= htmlspecialchars($log['changed_by']) ?></span>
                                <span class="inline-flex items-center gap-1 text-xs font-bold px-2 py-0.5 rounded-full
                                    <?= $on ? 'bg-red-100 text-red-600' : 'bg-emerald-100 text-emerald-700' ?>">
                                    <span class="w-1.5 h-1.5 rounded-full <?= $on ? 'bg-red-400' : 'bg-emerald-400' ?>"></span>
                                    <?= $on ? 'Maintenance ON' : 'Maintenance OFF' ?>
                                </span>
                            </div>
                            <?php if (!empty($log['note'])): ?>
                            <p class="text-xs text-slate-500 line-clamp-2">
                                "<?= htmlspecialchars($log['note']) ?>"
                            </p>
                            <?php endif; ?>
                            <p class="text-xs text-slate-400 mt-1 font-mono">
                                <?= date('d M Y, H:i', strtotime($log['changed_at'])) ?> WIB
                            </p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div><!-- /RIGHT -->

    </div><!-- /grid -->
</div>

<!-- ── Confirmation Modal ── -->
<div id="confirmModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onclick="closeConfirmModal()"></div>

    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm z-10 fade-in-up overflow-hidden">
        <!-- Red top bar -->
        <div class="h-1.5 w-full bg-gradient-to-r from-red-500 to-rose-500"></div>

        <div class="p-6">
            <!-- Icon + heading -->
            <div class="flex items-center gap-3 mb-5">
                <div class="w-11 h-11 rounded-xl bg-red-100 flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <div>
                    <h3 class="text-base font-bold text-slate-800">Aktifkan Maintenance?</h3>
                    <p class="text-xs text-slate-400 mt-0.5">Semua vendor tidak bisa akses VSS.</p>
                </div>
            </div>

            <!-- Note preview -->
            <div class="bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 mb-5">
                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wide mb-1">Catatan yang akan disimpan</p>
                <p id="notePreview" class="text-sm text-slate-700 leading-snug"></p>
            </div>

            <!-- Impact info -->
            <div class="flex items-start gap-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2.5 mb-5">
                <svg class="w-4 h-4 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Vendor yang sedang login akan langsung diarahkan ke halaman maintenance saat refresh.
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
                <button onclick="closeConfirmModal()"
                    class="flex-1 py-2.5 text-sm font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200
                           rounded-xl transition-all active:scale-95">
                    Batal
                </button>
                <button onclick="submitActivate()"
                    class="flex-1 py-2.5 text-sm font-bold text-white bg-red-600 hover:bg-red-700
                           rounded-xl transition-all active:scale-95 shadow-sm">
                    Ya, Aktifkan
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function openConfirmModal() {
    var note = document.getElementById('noteField').value.trim();
    if (!note) {
        var ta = document.getElementById('noteField');
        ta.focus();
        ta.classList.add('ring-2', 'ring-red-400', 'border-red-400');
        ta.addEventListener('input', function() {
            ta.classList.remove('ring-2', 'ring-red-400', 'border-red-400');
        }, { once: true });
        return;
    }
    document.getElementById('notePreview').textContent = note;
    document.getElementById('confirmModal').classList.remove('hidden');
}
function closeConfirmModal() {
    document.getElementById('confirmModal').classList.add('hidden');
}
function submitActivate() {
    document.getElementById('maintenanceForm').submit();
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeConfirmModal();
});
</script>

<?php require_once '../includes/footer.php'; ?>
