<?php
// tools/test_auth_intended.php
//
// Tes validator return-to-intended-page (includes/auth_intended.php).
// Tidak menyentuh database maupun sesi, jadi aman dijalankan kapan saja.
//
// Usage:
//   php tools/test_auth_intended.php
//
// Exit code 0 = semua lolos, 1 = ada yang gagal (bisa dipakai di CI).

require_once __DIR__ . '/../includes/auth_intended.php';

$pass = 0;
$fail = 0;

function cek(string $judul, $hasil, $harap) {
    global $pass, $fail;
    $ok = ($hasil === $harap);
    $ok ? $pass++ : $fail++;
    printf("  %s  %-52s  %s\n",
        $ok ? 'ok  ' : 'GAGAL',
        $judul,
        $ok ? '' : "dapat: " . var_export($hasil, true) . ", harap: " . var_export($harap, true)
    );
}

function terima(string $url) { cek($url, auth_sanitize_intended($url) !== null, true); }
function tolak(string $url)  { cek($url, auth_sanitize_intended($url) === null, true); }

echo "\n== 1. Tujuan yang SAH ==\n";
terima('/workspace/pages/sla_report.php');
terima('/workspace/pages/sla_report.php?team=GP&status=overdue');  // query dibawa utuh
terima('/qa/workspace/pages/sla_report.php');                      // deploy di subdirektori
terima('/modules/vendor/dashboard.php');
terima('/modules/vendormgt/exception_request_review.php?id=7');

echo "\n== 2. Open redirect ==\n";
tolak('https://evil.com/modules/x.php');
tolak('//evil.com/modules/x.php');                                 // protocol-relative
tolak('/\evil.com/modules/x.php');                                 // browser baca '\' = '/'
tolak('/%5Cevil.com/modules/x.php');
tolak('https://db.vssportal.online.evil.com/');                    // sufiks, bukan host yang sama
tolak('https://db.vssportal.online@evil.com/');                    // userinfo palsu

echo "\n== 3. Bukan halaman ==\n";
tolak('/workspace/pages/ajax_sla_table.php');                      // endpoint XHR
tolak('/workspace/pages/login.php');
tolak('/workspace/pages/logout.php');
tolak('/modules/vendor/delete_product_action.php?id=1');           // menghapus via $_GET
tolak('/modules/vendormgt/approve_action.php');
tolak('/modules/sourcing/exit_direct_registration.php');           // ubah sesi via GET

echo "\n== 4. Path tidak valid ==\n";
tolak('/modules/../config.php');
tolak('/modules/%2e%2e/config.php');
tolak('/workspace/pages/berkas_tidak_ada.php');
tolak('/config.php');                                              // di luar segmen yang diizinkan
tolak("/modules/vendor/dashboard.php\r\nSet-Cookie: a=b");         // header injection
cek('string kosong', auth_sanitize_intended(''), null);
cek('null', auth_sanitize_intended(null), null);

echo "\n== 5. Lintas host ==\n";
$hosts = auth_allowed_redirect_hosts();
echo "  allowlist aktif: " . ($hosts ? implode(', ', $hosts) : '(kosong)') . "\n";
if ($hosts) {
    terima('https://' . $hosts[0] . '/');
    terima('https://' . strtoupper($hosts[0]) . '/index.php?db=vms');  // host tidak case-sensitive
    tolak('http://' . $hosts[0] . '/');                                // jangan turun ke HTTP
}

echo "\n== 6. Sekali pakai (one-shot) ==\n";
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$_SESSION['intended_url'] = '/workspace/pages/sla_report.php';
$_SESSION['intended_at']  = time();
cek('konsumsi pertama mengembalikan tujuan', auth_consume_intended(), '/workspace/pages/sla_report.php');
cek('konsumsi kedua sudah kosong',           auth_consume_intended(), null);

$_SESSION['intended_url'] = '/workspace/pages/sla_report.php';
$_SESSION['intended_at']  = time() - (AUTH_INTENDED_TTL + 60);
cek('tujuan kedaluwarsa diabaikan', auth_consume_intended(), null);

printf("\n%s  lolos: %d, gagal: %d\n\n", $fail === 0 ? 'SEMUA LOLOS.' : 'ADA YANG GAGAL.', $pass, $fail);
exit($fail === 0 ? 0 : 1);
