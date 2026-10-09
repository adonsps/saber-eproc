<?php
/**
 * includes/maintenance_gate.php
 *
 * Single source of truth for VSS maintenance mode.
 *
 * The maintenance switch has two independent sources, checked in this order:
 *
 *   1. A flag FILE on disk (storage/maintenance.flag).
 *   2. The maintenance_settings.is_active row in the VSS database.
 *
 * The file exists because the DB flag alone is not trustworthy during exactly
 * the situation maintenance mode is for: repairing or restoring the database.
 * Restoring a dump either overwrites the singleton row with the dump's own
 * is_active = 0, or drops the table entirely (config.php then recreates it
 * empty and seeds is_active = 0). Either way the gate silently opened while the
 * DB was mid-restore and vendors kept writing rows, which is how a restore ends
 * up fighting live traffic over AUTO_INCREMENT ids. The file lives outside the
 * database, so nothing a restore does can clear it.
 *
 * If neither source can be read (DB down, or table missing mid-restore) the
 * gate FAILS CLOSED for vendor-facing traffic: when we cannot prove the portal
 * is open, hold vendors off rather than let writes through.
 */

/** Absolute path of the on-disk maintenance flag. */
function vss_maintenance_flag_path(): string
{
    $override = getenv('VSS_MAINTENANCE_FLAG');
    if (is_string($override) && $override !== '') {
        return $override;
    }
    return dirname(__DIR__) . '/storage/maintenance.flag';
}

/** True when the on-disk flag is present. */
function vss_maintenance_file_active(): bool
{
    $path = vss_maintenance_flag_path();
    clearstatcache(true, $path);
    return is_file($path);
}

/** Contents of the flag file (note / who / when), or [] when absent. */
function vss_maintenance_file_meta(): array
{
    if (!vss_maintenance_file_active()) {
        return [];
    }
    $raw = @file_get_contents(vss_maintenance_flag_path());
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $meta = json_decode($raw, true);
    return is_array($meta) ? $meta : [];
}

/**
 * Create or remove the on-disk flag.
 *
 * Returns [ok, message]. Never throws: the caller (admin UI / CLI) reports the
 * failure rather than half-toggling maintenance.
 */
function vss_maintenance_set_file(bool $on, string $note = '', string $by = ''): array
{
    $path = vss_maintenance_flag_path();
    $dir  = dirname($path);

    if ($on) {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return [false, "Tidak dapat membuat folder flag: $dir"];
        }
        $payload = json_encode([
            'note'       => $note,
            'updated_by' => $by,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        if (@file_put_contents($path, $payload . "\n", LOCK_EX) === false) {
            return [false, "Tidak dapat menulis flag file: $path"];
        }
        return [true, "Flag file dibuat: $path"];
    }

    if (!is_file($path)) {
        return [true, 'Flag file tidak ada (sudah bersih).'];
    }
    if (!@unlink($path)) {
        return [false, "Tidak dapat menghapus flag file: $path"];
    }
    return [true, "Flag file dihapus: $path"];
}

/**
 * Records whether this request had to create the maintenance table or reseed its
 * singleton row — i.e. whether the previous DB state is unknowable.
 *
 * Pass a bool to set, call with no argument to read.
 */
function vss_maintenance_bootstrapped(?bool $set = null): bool
{
    static $fresh = false;
    if ($set !== null) {
        $fresh = $set;
    }
    return $fresh;
}

/**
 * Make sure the maintenance tables exist, WITHOUT laundering a missing table
 * into a confident "maintenance is off".
 *
 * The seeded value is the whole point. The old bootstrap seeded is_active = 0,
 * so the first page hit after a restore dropped the table rebuilt it as "portal
 * open" and every later hit read that back as a real decision — maintenance
 * turned itself off mid-restore and stayed off. A table or row we had to create
 * carries no operator decision, so it is seeded ON: the portal stays shut until
 * somebody deliberately opens it, from the admin page or tools/vss_maintenance.php.
 *
 * The trade-off is that a genuinely fresh install also comes up in maintenance
 * mode. That is the safe direction to be wrong in, it is visible on the admin
 * page, and it is one click to clear.
 */
function vss_maintenance_ensure_tables(mysqli $conn): void
{
    $had_table = false;
    $had_row   = false;

    try {
        $res       = @$conn->query("SHOW TABLES LIKE 'maintenance_settings'");
        $had_table = ($res !== false && $res->num_rows > 0);

        if ($had_table) {
            $res     = @$conn->query("SELECT 1 FROM maintenance_settings WHERE id = 1");
            $had_row = ($res !== false && $res->num_rows > 0);
        }
    } catch (\Throwable $e) {
        $had_table = false;
        $had_row   = false;
    }

    try {
        $conn->query("CREATE TABLE IF NOT EXISTS maintenance_settings (
            id TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 0,
            note TEXT DEFAULT NULL,
            updated_by VARCHAR(255) DEFAULT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $conn->query("CREATE TABLE IF NOT EXISTS maintenance_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action ENUM('ON','OFF') NOT NULL,
            note TEXT DEFAULT NULL,
            changed_by VARCHAR(255) NOT NULL,
            changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if (!$had_table || !$had_row) {
            error_log('[maintenance] settings table/row was absent; seeding is_active = 1 (fail closed)');
            $conn->query("INSERT IGNORE INTO maintenance_settings (id, is_active, note, updated_by)
                          VALUES (1, 1, 'Auto: tabel maintenance tidak ditemukan (kemungkinan restore database). Portal ditutup sampai dibuka manual.', 'system')");
        }
    } catch (\Throwable $e) {
        error_log('[maintenance] ensure_tables failed: ' . $e->getMessage());
        vss_maintenance_bootstrapped(true);
        return;
    }

    vss_maintenance_bootstrapped(!($had_table && $had_row));
}

/**
 * Resolve the current maintenance state.
 *
 * @param mysqli|null $conn VSS connection, or null when none is available.
 * @return array{active:bool,source:string,note:string}
 *   source: 'file' | 'db' | 'db_unavailable' | 'off'
 */
function vss_maintenance_state(?mysqli $conn): array
{
    if (vss_maintenance_file_active()) {
        $meta = vss_maintenance_file_meta();
        return ['active' => true, 'source' => 'file', 'note' => (string)($meta['note'] ?? '')];
    }

    if (!$conn instanceof mysqli) {
        // No DB handle at all: cannot prove the portal is open.
        return ['active' => true, 'source' => 'db_unavailable', 'note' => ''];
    }

    if (vss_maintenance_bootstrapped()) {
        // The row we are about to read is the default this very request seeded,
        // not a decision anyone made. During a restore that is the difference
        // between holding vendors off and letting them write into a half-loaded
        // database.
        return ['active' => true, 'source' => 'db_unavailable', 'note' => ''];
    }

    try {
        $res = @$conn->query("SELECT is_active, note FROM maintenance_settings WHERE id = 1");
    } catch (\Throwable $e) {
        $res = false;
    }

    if ($res === false) {
        // Table missing or server gone: almost certainly a restore in progress.
        error_log('[maintenance] settings unreadable, failing closed: ' . $conn->error);
        return ['active' => true, 'source' => 'db_unavailable', 'note' => ''];
    }

    $row = $res->fetch_assoc();
    if ($row === null) {
        // Table exists but the singleton row is gone: same story.
        error_log('[maintenance] settings row missing, failing closed');
        return ['active' => true, 'source' => 'db_unavailable', 'note' => ''];
    }

    if ((int)$row['is_active'] === 1) {
        return ['active' => true, 'source' => 'db', 'note' => (string)($row['note'] ?? '')];
    }
    return ['active' => false, 'source' => 'off', 'note' => ''];
}

/**
 * Roles that run the portal rather than use it as a vendor. These keep working
 * during maintenance, otherwise nobody could turn maintenance back off.
 */
function vss_maintenance_internal_roles(): array
{
    return ['sourcing', 'vendor_mgt', 'vendormgt_head', 'admin', 'project', 'requestor'];
}

/**
 * Scripts that must stay reachable even while maintenance is on, so that a
 * stale vendor session cannot lock an internal user out of the portal.
 */
function vss_maintenance_exempt_scripts(): array
{
    return ['login.php', 'login_action.php', 'logout.php', 'maintenance.php'];
}

/** Endpoints that answer with JSON, so the gate must not hand them a redirect. */
function vss_maintenance_json_scripts(): array
{
    return [
        'check_nib.php',
        'extract_doc_ai.php',
        'generate_profile_summary_ai.php',
        'suggest_biz_ai.php',
        'validate_project_ai.php',
    ];
}

/** True when the current request expects JSON rather than a page. */
function vss_maintenance_wants_json(string $script): bool
{
    if (in_array($script, vss_maintenance_json_scripts(), true)) {
        return true;
    }
    if (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0) {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    return stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false;
}

/**
 * URL prefix the app is mounted at, with a trailing slash ('/' or '/qa/').
 *
 * Derived from the request rather than from a page-local $path_to_root, because
 * roughly half the vendor endpoints never set that variable. Those used to
 * redirect to a maintenance.php sitting next to themselves, which does not
 * exist.
 */
function vss_maintenance_base_url(): string
{
    $script_name = $_SERVER['SCRIPT_NAME'] ?? '';
    $script_file = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $app_root    = realpath(dirname(__DIR__));

    if ($script_name !== '' && $script_file !== '' && $app_root !== false) {
        $real = realpath($script_file);
        if ($real !== false) {
            $real     = str_replace('\\', '/', $real);
            $app_root = str_replace('\\', '/', $app_root);
            if (strncmp($real, $app_root . '/', strlen($app_root) + 1) === 0) {
                // Relative path of this script inside the app, e.g.
                // "modules/vendor/submit_pq.php". Trimming it off SCRIPT_NAME
                // leaves the mount prefix, e.g. "/" or "/qa/".
                $rel  = substr($real, strlen($app_root) + 1);
                $base = substr($script_name, 0, strlen($script_name) - strlen($rel));
                if ($base !== '' && substr($base, -1) === '/') {
                    return $base;
                }
            }
        }
    }
    return '/';
}

/**
 * Hold vendor-facing traffic off while maintenance is on.
 *
 * Audience is decided by exclusion: anything that is not an authenticated
 * internal user is treated as vendor-facing. That deliberately includes
 * anonymous requests. Registration, e-mail verification and password reset all
 * write to `users` / `vendors` before a vendor session ever exists, so gating on
 * $_SESSION['role'] === 'vendor' let exactly the inserts we care about through.
 */
function vss_maintenance_guard(?mysqli $conn): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');

    if (in_array($script, vss_maintenance_exempt_scripts(), true)) {
        return;
    }

    $role = $_SESSION['role'] ?? '';
    if (in_array($role, vss_maintenance_internal_roles(), true)) {
        return;
    }

    $state = vss_maintenance_state($conn);
    if (!$state['active']) {
        return;
    }

    if (vss_maintenance_wants_json($script)) {
        http_response_code(503);
        header('Retry-After: 3600');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success'     => false,
            'is_valid'    => false,
            'maintenance' => true,
            'message'     => 'Portal sedang dalam pemeliharaan. Silakan coba beberapa saat lagi.',
        ]);
        exit;
    }

    header('Location: ' . vss_maintenance_base_url() . 'maintenance.php');
    exit;
}

/**
 * Self-contained 503 page for when the database itself is unreachable.
 *
 * Deliberately depends on nothing: no $conn, no header.php (which reads
 * notifications from the database), no stylesheet. It is what a visitor sees
 * while the DB is genuinely down, so it has to render with the DB down.
 */
function vss_maintenance_render_offline(): void
{
    if (PHP_SAPI === 'cli') {
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 3600');
    }

    if (vss_maintenance_wants_json(basename($_SERVER['SCRIPT_NAME'] ?? ''))) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success'     => false,
            'is_valid'    => false,
            'maintenance' => true,
            'message'     => 'Portal sedang dalam pemeliharaan. Silakan coba beberapa saat lagi.',
        ]);
        exit;
    }

    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
    }
    echo <<<'HTML'
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sedang Dalam Pemeliharaan</title>
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
         background:#f4f7f6; color:#264653; font-family:system-ui,-apple-system,"Segoe UI",sans-serif; }
  .panel { background:#fff; border-radius:20px; padding:2.5rem 2.75rem; max-width:520px; margin:1.5rem;
           box-shadow:0 20px 50px rgba(38,70,83,.12); }
  h1 { font-size:1.6rem; margin:0 0 .75rem; }
  p  { color:#6c757d; line-height:1.7; margin:0 0 .5rem; }
  .pill { display:inline-block; background:rgba(244,162,97,.16); color:#b65f20; font-weight:700;
          font-size:.72rem; letter-spacing:.05em; text-transform:uppercase;
          padding:.45rem .8rem; border-radius:999px; margin-bottom:1.25rem; }
</style>
</head>
<body>
  <div class="panel">
    <span class="pill">Sistem Dalam Pemeliharaan</span>
    <h1>Kami sedang meningkatkan layanan.</h1>
    <p>Saber eProc sementara tidak dapat diakses. Silakan coba kembali beberapa saat lagi.</p>
    <p>Terima kasih atas pengertiannya.</p>
  </div>
</body>
</html>
HTML;
    exit;
}
