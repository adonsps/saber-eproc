<?php
// includes/auth_intended.php
// Return-to-intended-page: menyimpan halaman yang ingin dibuka pengguna sebelum
// ia dilempar ke Login, dan membacanya kembali setelah login berhasil. Dipakai
// oleh kedua login handler (login_action.php dan workspace/pages/login.php)
// supaya validasinya tidak bercabang.
//
// Tidak memanggil session_start(); pemanggilnya sudah melakukannya.

if (!defined('AUTH_INTENDED_TTL')) {
    define('AUTH_INTENDED_TTL', 900); // tujuan kedaluwarsa setelah 15 menit
}

if (!defined('VSS_IDLE_LIMIT_SECONDS')) {
    // Batas diam untuk paruh VSS/root (login.php, modules/**), dipakai oleh
    // includes/footer.php dan pesan timeout di logout.php.
    //
    // Terpisah dari WS_IDLE_LIMIT_DEFAULT di workspace/includes/auth.php dengan
    // terpaksa, bukan karena desain: konstanta itu hidup satu berkas dengan
    // session_start(), jadi paruh root tidak bisa memuatnya tanpa efek samping.
    // Angkanya SENGAJA dibuat sama (1800). Kalau salah satu diubah, ubah
    // keduanya -- perbedaan di antara keduanya adalah bug, bukan kebijakan.
    //
    // Paruh root hanya punya hitung mundur JavaScript; tidak ada penegakan di
    // sisi server seperti ws_enforce_idle_timeout(), karena tidak ada satu pun
    // gerbang auth bersama di sini (82 berkas memeriksa sesi masing-masing).
    define('VSS_IDLE_LIMIT_SECONDS', 1800); // 30 menit
}

/** Root aplikasi di disk (folder yang memuat login.php, modules/, workspace/). */
function auth_app_root(): string {
    static $root = null;
    if ($root === null) {
        $root = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
    }
    return $root;
}

/**
 * Tujuan redirect yang aman, atau null bila tidak layak dipercaya.
 *
 * Yang dinilai hanya komponen PATH, dan pencocokannya per SEGMEN lalu diverifikasi
 * berkasnya ada — bukan `strpos($url, '/modules/')` seperti filter lama, yang bisa
 * ditembus `/\evil.com/modules/x.php` (browser membaca '\' sama dengan '/').
 */
function auth_sanitize_intended(?string $url): ?string {
    if (!is_string($url) || $url === '') return null;
    if (strpbrk($url, "\\\r\n\0") !== false) return null;                 // backslash + header injection
    if (preg_match('/[\x00-\x1f\x7f]/', $url)) return null;               // control char

    if ($url[0] !== '/') return auth_sanitize_cross_host($url);           // URL absolut
    if (strncmp($url, '//', 2) === 0) return null;                        // protocol-relative

    $path = parse_url($url, PHP_URL_PATH);
    if (!is_string($path) || $path === '' || strpos($path, '..') !== false) return null;

    // Segmen penanda, bukan prefix absolut, supaya deploy di subdirektori (/qa/) tetap jalan.
    foreach (['/workspace/pages/', '/modules/'] as $marker) {
        $pos = strpos($path, $marker);
        if ($pos === false) continue;

        $rel  = ltrim(substr($path, $pos), '/');
        $base = basename($rel);

        if (substr($base, -4) !== '.php') return null;
        if (strncmp($base, 'ajax_', 5) === 0) return null;                // endpoint, bukan halaman
        if (in_array($base, ['login.php', 'logout.php'], true)) return null;

        // Handler yang mengubah state. delete_product_action.php mis. menghapus
        // langsung dari $_GET['id'] tanpa cek method, jadi menjadikannya tujuan
        // redirect = CSRF yang dibonceng alur login.
        if (substr($base, -11) === '_action.php') return null;
        if ($base === 'exit_direct_registration.php') return null;        // idem, beda penamaan

        $root   = auth_app_root();
        $target = realpath($root . '/' . $rel);
        if ($target === false || !is_file($target)) return null;
        if (strncmp($target, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) !== 0) return null;

        return $url;                                                      // query string dibawa utuh
    }
    return null;
}

/**
 * Host lain yang boleh menjadi tujuan redirect. Hanya masuk akal untuk subdomain
 * yang berbagi sesi lewat session.cookie_domain (Dockerfile).
 */
function auth_allowed_redirect_hosts(): array {
    static $hosts = null;
    if ($hosts !== null) return $hosts;

    // Environment hanya untuk deployment yang butuh daftar BERBEDA (mis. QA);
    // nilainya mengganti daftar bawaan, bukan menambah.
    $raw = (string)(getenv('APP_REDIRECT_HOSTS') ?: '');

    if (trim($raw) === '') {
        // =============== DAFTAR BAWAAN — UBAH DI SINI ===============
        // Hapus entri begitu subdomainnya dipensiunkan: subdomain menganggur
        // bisa diambil alih orang lain dan tetap dianggap tepercaya di sini.
        $raw = implode(',', [
            'db.vssportal.online',   // phpMyAdmin, dijaga workspace/pages/auth_pma.php
        ]);
        // ============================================================
    }

    $hosts = array_values(array_unique(array_filter(
        array_map(static function ($h) { return strtolower(trim($h)); }, explode(',', $raw)),
        static function ($h) { return $h !== ''; }
    )));

    return $hosts;
}

/**
 * Tujuan di host lain. Tidak ada verifikasi berkas seperti cabang same-site —
 * berkasnya bukan milik aplikasi ini, jadi dasar kepercayaannya murni allowlist host.
 */
function auth_sanitize_cross_host(string $url): ?string {
    $allowed = auth_allowed_redirect_hosts();
    if ($allowed === []) return null;

    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
    if (strtolower($parts['scheme']) !== 'https') return null;            // jangan turunkan ke HTTP
    if (isset($parts['user']) || isset($parts['pass'])) return null;      // "https://db.tepercaya@evil.com/"
    if (!in_array(strtolower($parts['host']), $allowed, true)) return null;

    return $url;
}

/**
 * True untuk XHR/fetch. Guard memakainya untuk membalas 401 JSON alih-alih 302 ke
 * HTML login, yang membuat JSON.parse gagal dengan pesan menyesatkan.
 */
function auth_is_ajax(): bool {
    if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') return true;
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (strpos($accept, 'application/json') !== false && strpos($accept, 'text/html') === false) return true;
    return strncmp(basename($_SERVER['SCRIPT_NAME'] ?? ''), 'ajax_', 5) === 0;
}

/**
 * Menghapus cookie sesi SECARA TUNTAS.
 *
 * setcookie() biasa hanya menghapus cookie yang domain/path-nya persis sama.
 * Browser pengguna lama bisa menyimpan DUA cookie PHPSESSID sekaligus: satu
 * host-only (dibuat saat session.cookie_domain masih kosong) dan satu ber-domain
 * '.vssportal.online' (setelan sekarang, lihat Dockerfile). Menghapus salah satu
 * saja menyisakan yang lain, browser tetap mengirim keduanya, dan pengguna
 * tertinggal dengan sesi rusak yang tidak bisa dibuang lewat tombol Logout --
 * gejalanya: gagal login terus di browser biasa, tapi lancar di incognito.
 *
 * Karena itu cookie dihapus untuk KEDUA cakupan: host-only dan domain induk.
 */
function auth_clear_session_cookie(): void {
    if (!ini_get('session.use_cookies')) return;

    $params = session_get_cookie_params();
    $name   = session_name();
    $past   = time() - 42000;

    $domains = ['', $params['domain'] ?? ''];              // '' = host-only
    $host    = $_SERVER['HTTP_HOST'] ?? '';
    if ($host !== '') {
        $host = strtolower(explode(':', $host)[0]);        // buang port
        $domains[] = $host;
        // Cookie ber-domain induk, mis. host 'www.vssportal.online' -> '.vssportal.online'.
        $parts = explode('.', $host);
        if (count($parts) > 2) $domains[] = '.' . implode('.', array_slice($parts, -2));
    }

    foreach (array_unique($domains) as $domain) {
        setcookie($name, '', [
            'expires'  => $past,
            'path'     => $params['path'] ?: '/',
            'domain'   => $domain,
            'secure'   => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }
}

/**
 * Membatalkan login yang sudah terlanjur mengisi $_SESSION tetapi gagal
 * menemukan tujuannya.
 *
 * login_action.php mengisi user_id/role/email di langkah 3, SEBELUM switch role
 * di langkah 5 tahu apakah pengguna punya dashboard. Bila cabangnya buntu (tidak
 * ada baris di database Workspace, koneksi Workspace mati, role tak dikenal),
 * sesi separuh itu tertinggal: login.php melihat user_id ada, tidak menemukan
 * dashboard untuk role tersebut, lalu menampilkan form login lagi -- sehingga
 * pengguna terjebak mengetik kata sandi berulang kali tanpa pernah berpindah
 * halaman. Membuang sesinya membuat kegagalan berhenti sebagai satu pesan error
 * yang jelas, bukan lingkaran.
 *
 * intended_url sengaja ikut hilang: tujuan itu milik login yang gagal.
 */
function auth_abandon_partial_login(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;

    $_SESSION = [];
    // Sesi baru yang bersih, bukan session_destroy(): halaman login berikutnya
    // masih butuh sesi yang bisa dipakai (mis. untuk mencatat intended_url lagi).
    session_regenerate_id(true);
}

/**
 * Mencatat URL saat ini sebagai tujuan setelah login. Aman dipanggil dari mana saja
 * (termasuk config.php): diam untuk request non-GET, pengguna yang sudah login, dan
 * URL yang tidak lolos validasi.
 */
function auth_remember_intended(?string $url = null): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    if (isset($_SESSION['user_id'])) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;          // POST tak bisa diulang lewat redirect
    if (auth_is_ajax()) return;

    $safe = auth_sanitize_intended($url ?? ($_SERVER['REQUEST_URI'] ?? ''));
    if ($safe === null) return;

    $_SESSION['intended_url'] = $safe;
    $_SESSION['intended_at']  = time();
}

/**
 * Mengambil tujuan tersimpan SEKALI PAKAI lalu menghapusnya. Divalidasi ulang saat
 * dibaca karena isinya bisa berasal dari query string (jalur timeout, lihat logout.php).
 */
function auth_consume_intended(?string $fallback = null): ?string {
    $stored = $_SESSION['intended_url'] ?? null;
    $at     = (int)($_SESSION['intended_at'] ?? 0);
    unset($_SESSION['intended_url'], $_SESSION['intended_at']);

    if ($stored !== null && $at > 0 && (time() - $at) <= AUTH_INTENDED_TTL) {
        $safe = auth_sanitize_intended($stored);
        if ($safe !== null) return $safe;
    }
    return auth_sanitize_intended($fallback);
}

/**
 * Membunuh cookie sesi KEMBAR di cakupan lain, menyisakan hanya cookie di
 * cakupan yang sedang dipakai PHP.
 */
function auth_purge_rival_session_cookies(): void {
    if (!ini_get('session.use_cookies')) return;

    $params = session_get_cookie_params();
    $name   = session_name();
    $past   = time() - 42000;

    $normalize = static function (string $d): string {
        return ltrim(strtolower(trim($d)), '.');
    };
    $live = $normalize((string)($params['domain'] ?? ''));   // '' = host-only

    // Cakupan yang MUNGKIN menyimpan kembaran.
    $rivals = [''];                                          // host-only
    $host   = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]);
    $host   = rtrim($host, '.');                             // bentuk absolut "host."
    $parts  = $host === '' ? [] : explode('.', $host);
    if (count($parts) > 2) {
        // Host 'www.vssportal.online' -> domain induk '.vssportal.online'.
        // Untuk host apex (2 label) baris ini sengaja dilewati: domain induknya
        // ADALAH cakupan aktif, dan menghapusnya sama saja membatalkan login.
        $rivals[] = '.' . implode('.', array_slice($parts, -2));
    }

    foreach ($rivals as $domain) {
        if ($normalize($domain) === $live) continue;         // jangan bunuh yang aktif
        setcookie($name, '', [
            'expires'  => $past,
            'path'     => $params['path'] ?: '/',
            'domain'   => $domain,
            'secure'   => (bool)($params['secure'] ?? false),
            'httponly' => (bool)($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?: 'Lax',
        ]);
    }
}
