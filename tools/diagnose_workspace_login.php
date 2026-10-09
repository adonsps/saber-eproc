<?php
// tools/diagnose_workspace_login.php
//
// Diagnosa "kenapa user X tidak bisa login ke Workspace, sementara user Y bisa".
//
// Sebuah akun Workspace butuh DUA baris di DUA database:
//   * VSS      (u894129685_vmsportal.users)  role: viewer | project | requestor
//              -> baris yang diverifikasi password-nya oleh login_action.php
//   * Workspace(u894129685_workspace.users)  role: Manager | QS | Admin | ...
//              -> baris yang menentukan dashboard & hak akses
// Kalau salah satunya hilang atau email/username-nya tidak cocok, password benar
// pun login tetap ditolak.
//
// READ-ONLY. Tidak pernah menulis / mengubah / menghapus apa pun.
//
// Usage:
//   php tools/diagnose_workspace_login.php                 # semua akun
//   php tools/diagnose_workspace_login.php budi@kawanlama.com   # satu akun

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../config.php';                        // $conn (MySQLi, VSS)
require_once __DIR__ . '/../workspace/config/database.php'; // $pdo  (PDO, Workspace)

$filter = $argv[1] ?? null;
$norm = fn($v) => trim(strtolower((string)$v));

function out($l = '') { fwrite(STDOUT, $l . PHP_EOL); }

// --- Ambil semua baris Workspace, indeks by email dan by username ----------
$ws_rows = $pdo->query("SELECT id, username, name, email, role, team_name FROM users")->fetchAll();
$ws_by_email = $ws_by_user = [];
foreach ($ws_rows as $r) {
    if ($r['email']    !== null && $r['email']    !== '') $ws_by_email[$norm($r['email'])]   = $r;
    if ($r['username'] !== null && $r['username'] !== '') $ws_by_user[$norm($r['username'])] = $r;
}

// --- Ambil user VSS yang memang diarahkan ke Workspace ---------------------
$sql = "SELECT id, email, username, full_name, role, is_active, lockout_time, failed_attempts
          FROM users WHERE role IN ('viewer','project','requestor')";
$params = [];
if ($filter !== null) { $sql .= " AND (email = ? OR username = ?)"; $params = [$filter, $filter]; }
$sql .= " ORDER BY email";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param("ss", $params[0], $params[1]);
$stmt->execute();
$vss_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$ok = $broken = [];
$matched_ws_ids = [];

foreach ($vss_rows as $u) {
    $problems = [];

    if ((int)$u['is_active'] === 0) {
        $problems[] = "VSS is_active = 0 (login ditolak: 'Akun Anda belum aktif')";
    }
    if (!empty($u['lockout_time']) && strtotime($u['lockout_time']) > time()) {
        $problems[] = "terkunci sampai {$u['lockout_time']} ({$u['failed_attempts']}x gagal)";
    }

    // Persis logika pencocokan di login_action.php setelah perbaikan
    $e = $norm($u['email']);
    $n = $norm($u['username']);
    $ws = $ws_by_email[$e] ?? $ws_by_user[$e] ?? $ws_by_email[$n] ?? $ws_by_user[$n] ?? null;

    if (!$ws) {
        $problems[] = "TIDAK ADA baris di database Workspace (email={$e} username={$n})";
    } else {
        $matched_ws_ids[$ws['id']] = true;
        if ($norm($ws['email']) !== $e && $e !== '') {
            $problems[] = "email beda antar DB: VSS={$e} vs Workspace=" . $norm($ws['email'])
                        . " -> sebelum patch login_action.php, user ini GAGAL login";
        }
        if (empty($ws['role'])) {
            $problems[] = "role Workspace kosong -> jatuh ke dashboard_pic.php";
        }
    }

    $label = sprintf("%-40s %-12s %s", $u['email'], $u['role'], $u['full_name']);
    if ($problems) $broken[] = [$label, $problems, $ws];
    else           $ok[]     = $label . "  ->  Workspace role: " . $ws['role'];
}

out("DIAGNOSA LOGIN WORKSPACE  (" . date('Y-m-d H:i') . ")");
out(str_repeat('=', 78));
out();
out("BISA LOGIN (" . count($ok) . ")");
out(str_repeat('-', 78));
foreach ($ok as $l) out("  [OK]  " . $l);
out();
out("BERMASALAH (" . count($broken) . ")");
out(str_repeat('-', 78));
foreach ($broken as [$label, $problems, $ws]) {
    out("  [!!]  " . $label);
    foreach ($problems as $p) out("          - " . $p);
}

// --- Baris Workspace yatim: ada di Workspace tapi tak punya akun VSS -------
if ($filter === null) {
    $orphans = array_filter($ws_rows, fn($r) => !isset($matched_ws_ids[$r['id']]));
    out();
    out("BARIS WORKSPACE TANPA AKUN VSS (" . count($orphans) . ") - tidak bisa login sama sekali");
    out(str_repeat('-', 78));
    foreach ($orphans as $r) {
        out(sprintf("  [??]  %-40s %-20s %s", $r['email'] ?: '(email kosong)', $r['role'], $r['name']));
    }
}

out();
out("Catatan: script ini READ-ONLY. Untuk memperbaiki, tambahkan baris yang hilang");
out("lewat setup_workspace_*.php atau samakan email di kedua database.");
