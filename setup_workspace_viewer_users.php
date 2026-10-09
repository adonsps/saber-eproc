<?php
// setup_workspace_viewer_users.php
//
// Creates read-only 'Viewer' accounts: Dashboard, Cost Avoidance, Cost Savings
// and SLA Tracking, with no Create/Update/Delete anywhere in the app.
//
// CLI ONLY — run it as:
//     php setup_workspace_viewer_users.php
//
// The web guard below is not cosmetic: this script creates accounts with no
// authentication of any kind, so a browser-reachable copy is an open account
// factory for anyone who guesses the URL. Refusing non-CLI SAPIs means leaving
// the file on the server is untidy rather than dangerous.
//
// An account needs a row in BOTH databases:
//   - VSS (users.role = 'viewer')     : the row login_action.php authenticates
//     against. The VSS role only picks WHICH app you land in — 'viewer' shares the
//     'project'/'requestor' branch that routes into Workspace, but is named
//     separately so a Viewer is not mislabelled as a project user in the VSS list.
//   - Workspace (users.role = 'Viewer'): the row that decides what the session may
//     do, because login_action.php overwrites $_SESSION['role'] with this value.
// A row in only one of the two produces a broken account: no VSS row means the
// login is rejected outright; no Workspace row means login succeeds and then dies
// on "Akun Anda belum memiliki akses ke Workspace."

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/config.php';                     // $conn (MySQLi, VSS)
require_once __DIR__ . '/workspace/config/database.php';  // $pdo  (PDO, Workspace)

// ---------------------------------------------------------------------------
// EDIT THIS LIST, then run the script.
// ---------------------------------------------------------------------------
$viewer_users = [
    ['name' => 'Viewer Workspace', 'email' => 'viewer@kawanlama.com'],
];

// Not 'Vendor123': that value is hardcoded in login_action.php as the "still on
// the default password" marker and would force a reset on first login.
$default_password_plain = 'Kawanlama123!';
// ---------------------------------------------------------------------------

$hashed_password = password_hash($default_password_plain, PASSWORD_DEFAULT);

function out($line = '') { fwrite(STDOUT, $line . PHP_EOL); }

out('Setup Viewer (View Only) Users');
out(str_repeat('=', 60));

// Fail fast rather than inserting rows the login flow cannot use. The ENUM is
// widened by the guarded auto-migrate in workspace/config/database.php, which has
// already run by this point (the require above).
$col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
if (!$col || stripos($col['Type'], "'Viewer'") === false) {
    out("[STOP] Kolom users.role di Workspace belum menerima nilai 'Viewer'.");
    out("       Muat ulang halaman Workspace mana pun sekali agar auto-migrate");
    out("       berjalan, lalu jalankan script ini lagi.");
    exit(1);
}

$success_count = 0;
$skip_count = 0;

foreach ($viewer_users as $user) {
    $email = trim(strtolower($user['email']));
    $name  = trim($user['name']);
    $username = explode('@', $email)[0];

    out("- {$name} <{$email}>");

    // 1. Workspace: the row that grants (and limits) what the session can do.
    $stmt_ws_check = $pdo->prepare("SELECT username FROM users WHERE email = ? OR username = ?");
    $stmt_ws_check->execute([$email, $username]);

    $ws_inserted = false;
    if ($stmt_ws_check->rowCount() > 0) {
        out("    [WORKSPACE] SKIP  - sudah terdaftar ({$username}).");
    } else {
        $stmt_ws_insert = $pdo->prepare("INSERT INTO users (username, name, email, role, password) VALUES (?, ?, ?, 'Viewer', ?)");
        if ($stmt_ws_insert->execute([$username, $name, $email, $hashed_password])) {
            out("    [WORKSPACE] OK    - terdaftar sebagai Viewer.");
            $ws_inserted = true;
        } else {
            out("    [WORKSPACE] ERROR - gagal mendaftarkan.");
        }
    }

    // 2. VSS: 'viewer' routes login_action.php into the Workspace lookup above.
    $stmt_vss_check = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt_vss_check->bind_param("s", $email);
    $stmt_vss_check->execute();

    $vss_inserted = false;
    if ($stmt_vss_check->get_result()->num_rows > 0) {
        out("    [VSS]       SKIP  - sudah terdaftar.");
    } else {
        $vss_role = 'viewer';
        $stmt_vss_insert = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt_vss_insert->bind_param("ssss", $email, $hashed_password, $name, $vss_role);
        if ($stmt_vss_insert->execute()) {
            out("    [VSS]       OK    - terdaftar.");
            $vss_inserted = true;
        } else {
            out("    [VSS]       ERROR - " . $conn->error);
        }
    }

    if ($ws_inserted || $vss_inserted) { $success_count++; } else { $skip_count++; }
    out();
}

out(str_repeat('=', 60));
out("Berhasil ditambahkan (WS/VSS) : {$success_count}");
out("Dilewati keduanya (sudah ada) : {$skip_count}");
out("Password awal                 : {$default_password_plain}");
