<?php
// login_action.php
session_start();
require 'config.php';
// Eksplisit meski config.php sudah memuatnya: bergantung pada require transitif
// membuat login pecah dengan fatal error bila baris di config.php dipindah.
require_once __DIR__ . '/includes/auth_intended.php';

if (isset($_POST['login_btn'])) {
    // The login field accepts EITHER the email OR the username. A vendor whose
    // email inbox is compromised/inaccessible can still sign in via username.
    $login = mysqli_real_escape_string($conn, trim($_POST['email']));
    $password = $_POST['password'];

    // 1. Tarik data user beserta kolom pelacak kegagalan (failed_attempts & lockout_time)
    //    Cocokkan dengan email ATAU username.
    $stmt = $conn->prepare("SELECT u.id, u.email, u.username, u.password_hash, u.role, u.full_name, u.is_active, u.failed_attempts, u.lockout_time, u.vendor_id, u.contact_role, u.contact_status, COALESCE(v.is_active, 1) AS vendor_is_active FROM users u LEFT JOIN vendors v ON v.id = u.vendor_id WHERE u.email = ? OR u.username = ? LIMIT 1");
    $stmt->bind_param("ss", $login, $login);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // --- CEK STATUS PENGUNCIAN AKUN (LOCKOUT) ---
        if (!empty($user['lockout_time'])) {
            $lockout_time = strtotime($user['lockout_time']);
            $current_time = time();
            
            if ($current_time < $lockout_time) {
                // Jika waktu saat ini masih kurang dari batas waktu kunci (Masih Terkunci)
                $diff_seconds = $lockout_time - $current_time;
                $minutes_left = ceil($diff_seconds / 60); // Bulatkan ke atas
                header("Location: login.php?error=" . urlencode("Akun Anda sementara terkunci karena terlalu banyak percobaan gagal. Silakan coba lagi dalam " . $minutes_left . " menit."));
                exit();
            } else {
                // Waktu hukuman sudah habis, reset counter menjadi 0
                $reset = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL WHERE id = ?");
                $reset->bind_param("i", $user['id']);
                $reset->execute();
                $user['failed_attempts'] = 0; // Update variabel lokal agar bisa lanjut login
            }
        }
        // ---------------------------------------------

        // 2. Verifikasi Password
        if (password_verify($password, $user['password_hash'])) {
            
            // --- BLOKIR CONTACT PERSON YANG SUDAH DICABUT AKSESNYA ---
            // Dicek LEBIH DULU daripada is_active, karena revoke juga menyetel
            // is_active = 0. Akses CP dapat dicabut oleh primary/procurement
            // (mis. CP sudah keluar dari perusahaan). Password masih benar, tapi
            // login tetap ditolak dengan pesan yang tepat.
            if (($user['contact_status'] ?? 'active') === 'revoked') {
                header("Location: login.php?error=" . urlencode("Akses akun ini telah dinonaktifkan. Silakan hubungi narahubung utama (primary) perusahaan Anda atau tim Procurement Kawan Lama."));
                exit();
            }
            // ---------------------------------------------------------

            // --- BLOKIR LOGIN JIKA BELUM VERIFIKASI EMAIL ---
            if ($user['is_active'] == 0) {
                header("Location: login.php?error=" . urlencode("Akun Anda belum aktif. Silakan periksa email Anda (termasuk folder spam) dan klik tautan verifikasi."));
                exit();
            }
            // ------------------------------------------------

            // --- BLOKIR LOGIN JIKA VENDOR DINONAKTIFKAN ---
            // Vendor duplikat dinonaktifkan, bukan dihapus (lihat
            // modules/vendormgt/duplicate_vendors.php): datanya tetap utuh,
            // tetapi akunnya tidak boleh dipakai login lagi. COALESCE di query
            // di atas membuat user internal (vendor_id NULL) selalu lolos.
            if ((int) ($user['vendor_is_active'] ?? 1) === 0) {
                header("Location: login.php?error=" . urlencode("Akun perusahaan ini sudah tidak aktif. Jika Anda merasa ini keliru, silakan hubungi tim Procurement Kawan Lama."));
                exit();
            }
            // ------------------------------------------------

            $default_password = 'Vendor123';
            if (password_verify($default_password, $user['password_hash'])) {
                $token = bin2hex(random_bytes(32));
                $expiry = date("Y-m-d H:i:s", strtotime('+1 hour'));
                $update = $conn->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
                $update->bind_param("ssi", $token, $expiry, $user['id']);
                $update->execute();

                header("Location: reset_password.php?token=" . urlencode($token));
                exit();
            }
            
            // RESET PERCOBAAN GAGAL (KARENA SUDAH BERHASIL LOGIN) + catat waktu login terakhir
            $reset = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_time = NULL, last_login_at = NOW() WHERE id = ?");
            $reset->bind_param("i", $user['id']);
            $reset->execute();

            // Buang cookie PHPSESSID kembar di cakupan lain SEBELUM ID baru
            // dipasang, supaya browser hanya menyisakan satu. Browser yang masih
            // menyimpan cookie host-only dari era sebelum session.cookie_domain
            // dipasang akan terus membaca ID basi dan terjebak di halaman login
            // selamanya -- lancar di incognito, gagal di browser biasa. Lihat
            // catatan lengkapnya di includes/auth_intended.php.
            auth_purge_rival_session_cookies();

            // Anti session fixation. Penting di deployment ini karena
            // session.cookie_domain mencakup semua subdomain, jadi ID sesi bisa
            // ditanam dari mana saja. true = hapus berkas sesi lama; isi $_SESSION
            // ikut terbawa sehingga intended_url tetap utuh untuk langkah 4b.
            session_regenerate_id(true);

            // 3. Set Basic Session Variables
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email']; // always the real email, even if they logged in by username
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['contact_role'] = $user['contact_role']; // 'primary' | 'member' | NULL (non-vendor)
            $_SESSION['last_activity'] = time(); // Session timeout tracker

            // 4. Jika role = vendor, tarik data spesifiknya.
            //    Multi-CP: member CP tidak memiliki baris di vendors.user_id, jadi
            //    resolve lewat users.vendor_id dulu; fallback ke pemetaan lama
            //    vendors.user_id agar akun primary lama tetap berfungsi.
            if ($user['role'] === 'vendor') {
                if (!empty($user['vendor_id'])) {
                    $v_stmt = $conn->prepare("SELECT id, company_name, registration_status FROM vendors WHERE id = ?");
                    $v_stmt->bind_param("i", $user['vendor_id']);
                } else {
                    $v_stmt = $conn->prepare("SELECT id, company_name, registration_status FROM vendors WHERE user_id = ?");
                    $v_stmt->bind_param("i", $user['id']);
                }
                $v_stmt->execute();
                $v_res = $v_stmt->get_result();

                if ($v_res->num_rows > 0) {
                    $vendor = $v_res->fetch_assoc();
                    $_SESSION['vendor_id'] = $vendor['id'];
                    $_SESSION['company_name'] = $vendor['company_name'];
                    $_SESSION['status'] = $vendor['registration_status'];
                }
            }

            // 4b. Return-to-intended-page, sebelum jatuh ke dashboard per role.
            // Sesi diutamakan atas $_POST['redirect'] supaya tujuan tidak hilang saat
            // percobaan login sebelumnya gagal. URL ini bukan bukti akses — halaman
            // tujuan tetap menegakkan cek role-nya sendiri. Vendor yang masih
            // 'min_info_submitted' dikecualikan: onboarding tak boleh dilewati deep-link.
            $redirect = auth_consume_intended($_POST['redirect'] ?? null);
            $force_onboarding = ($user['role'] === 'vendor' && ($_SESSION['status'] ?? '') === 'min_info_submitted');
            if ($redirect !== null && !$force_onboarding) {
                header("Location: " . $redirect);
                exit();
            }

            // 5. Role-Based Redirection
            switch ($user['role']) {
                case 'vendor':
                    if ($_SESSION['status'] == 'min_info_submitted') {
                        header("Location: modules/vendor/complete_profile.php");
                    } else {
                        header("Location: modules/vendor/dashboard.php");
                    }
                    break;

                case 'sourcing':
                    $_SESSION['show_er_risk_popup'] = true;
                    header("Location: modules/sourcing/dashboard.php");
                    break;

                case 'vendor_mgt':
                case 'vendormgt_head': 
                    header("Location: modules/vendormgt/dashboard.php");
                    break;
                
                case 'admin':
                    header("Location: modules/admin/dashboard.php");
                    break;

                // 'viewer' is the read-only Workspace account. It shares this branch
                // rather than getting a copy of it: the VSS role only decides WHICH
                // app you land in, and the Workspace role fetched below (which
                // overwrites $_SESSION['role'] a few lines down) is what decides
                // what you may do there. Kept as its own case anyway so a Viewer is
                // not mislabelled 'project' in the VSS user list.
                case 'viewer':
                case 'project':
                case 'requestor':
                    $ws = getWorkspaceConnection();
                    if ($ws) {
                        // Cocokkan dengan IDENTITAS RECORD VSS yang barusan lolos
                        // autentikasi, bukan dengan apa yang diketik di form.
                        // Sebelumnya hanya $_POST['email'] yang dipakai, sehingga user
                        // yang login memakai username gagal ditemukan bila di Workspace
                        // yang terdaftar emailnya (atau sebaliknya) -- password benar
                        // tapi tetap ditolak "belum memiliki akses ke Workspace".
                        // Input yang diketik tetap dicoba sebagai fallback terakhir
                        // untuk akun lama yang emailnya berbeda di dua database.
                        $vss_email    = trim(strtolower($user['email'] ?? ''));
                        $vss_username = trim(strtolower($user['username'] ?? ''));
                        $typed_login  = trim(strtolower($_POST['email'] ?? ''));
                        $clean_email  = $vss_email !== '' ? $vss_email : $typed_login;

                        $stmt_ws = $ws->prepare(
                            "SELECT id, username, name, email, role, team_name FROM users
                              WHERE email = ? OR username = ? OR email = ? OR username = ? OR email = ? OR username = ?
                              LIMIT 1"
                        );
                        $stmt_ws->bind_param(
                            "ssssss",
                            $vss_email, $vss_email,
                            $vss_username, $vss_username,
                            $typed_login, $typed_login
                        );
                        $stmt_ws->execute();
                        $ws_res = $stmt_ws->get_result();
                        if ($ws_res->num_rows > 0) {
                            $ws_user = $ws_res->fetch_assoc();
                            $_SESSION['user_id'] = $ws_user['id'];
                            $_SESSION['name'] = $ws_user['name'];
                            $_SESSION['role'] = $ws_user['role'];
                            $_SESSION['username'] = $ws_user['username'];
                            $_SESSION['email'] = $ws_user['email'] ?? $clean_email;
                            $_SESSION['team_name'] = $ws_user['team_name'];
                            
                            // Dynamic routing based on Workspace role
                            // 'Viewer' is the read-only role: it lands on the same
                            // Command Center, where require_dashboard_view() admits it
                            // for reading and every write path refuses it. Without this
                            // it would fall through to the dashboard_pic.php default.
                            if (in_array($ws_user['role'], ['Manager', 'Category Manager', 'Viewer'])) {
                                header("Location: workspace/pages/dashboard_mgr.php");
                            } elseif (in_array($ws_user['role'], ['Project Director', 'QS'])) {
                                header("Location: workspace/pages/dashboard_project.php");
                            } elseif ($ws_user['role'] === 'Admin') {
                                header("Location: workspace/pages/dashboard_admin.php");
                            } elseif ($ws_user['role'] === 'Technical Department') {
                                header("Location: workspace/pages/dashboard_tech.php");
                            } elseif ($ws_user['role'] === 'STC') {
                                // STC lands on their own AON dashboard (same target
                                // as header.php's $dashboard_link), not the PIC worklist.
                                header("Location: workspace/pages/dashboard_stc.php");
                            } else {
                                header("Location: workspace/pages/dashboard_pic.php");
                            }
                            exit();
                        } else {
                            // Catat identitas yang dicoba supaya tim support tahu baris
                            // Workspace mana yang perlu dibuat/diperbaiki, tanpa
                            // membocorkannya ke layar pengguna.
                            error_log(sprintf(
                                "[login] VSS user #%d (role=%s) tidak punya baris di Workspace. Dicoba: email=%s username=%s input=%s",
                                $user['id'], $user['role'], $vss_email, $vss_username, $typed_login
                            ));
                            // Buang sesi separuh jadi. $_SESSION['user_id'] sudah diisi di
                            // langkah 3, jadi tanpa ini pengguna kembali ke login.php sambil
                            // "sudah login" dengan role VSS mentah -- login.php tidak punya
                            // dashboard untuk role itu, menampilkan form lagi, dan setiap
                            // percobaan berikutnya mengulang siklus yang sama tanpa akhir.
                            auth_abandon_partial_login();
                            header("Location: login.php?error=" . urlencode(
                                "Akun Anda belum terdaftar di Workspace. Silakan hubungi tim Procurement Kawan Lama untuk pendaftaran akses Workspace."
                            ));
                            exit();
                        }
                    } else {
                        error_log("[login] getWorkspaceConnection() gagal untuk user #" . $user['id']);
                        auth_abandon_partial_login();   // lihat catatan di cabang di atas
                        header("Location: login.php?error=" . urlencode(
                            "Koneksi ke database Workspace sedang bermasalah. Silakan coba beberapa saat lagi."
                        ));
                        exit();
                    }
                    break;

                default:
                    error_log("[login] role VSS tidak dikenali untuk user #" . $user['id'] . ": " . $user['role']);
                    auth_abandon_partial_login();   // lihat catatan di cabang Workspace
                    header("Location: login.php?error=" . urlencode(
                        "Akun Anda belum memiliki hak akses yang terdaftar. Silakan hubungi tim Procurement Kawan Lama."
                    ));
                    exit();
            }
            exit();

        } else {
            // --- KATA SANDI SALAH: TAMBAH COUNTER GAGAL ---
            $attempts = $user['failed_attempts'] + 1;
            
            if ($attempts >= 5) {
                // Kunci akun selama 5 menit
                $lock_time = date("Y-m-d H:i:s", strtotime("+5 minutes"));
                $lock = $conn->prepare("UPDATE users SET failed_attempts = ?, lockout_time = ? WHERE id = ?");
                $lock->bind_param("isi", $attempts, $lock_time, $user['id']);
                $lock->execute();
                
                header("Location: login.php?error=" . urlencode("Akun terkunci karena 5x gagal masuk. Silakan coba lagi dalam 5 menit."));
            } else {
                // Cuma update counter ke database
                $inc = $conn->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?");
                $inc->bind_param("ii", $attempts, $user['id']);
                $inc->execute();
                
                $sisa = 5 - $attempts;
                header("Location: login.php?error=" . urlencode("Kata sandi salah. Sisa percobaan Anda: " . $sisa . " kali."));
            }
            exit();
            // ----------------------------------------------
        }
    } else {
        // Demi keamanan, jika email tidak ada, kita beri pesan umum (agar hacker tidak tahu email mana yang valid)
        header("Location: login.php?error=" . urlencode("Email atau kata sandi tidak ditemukan."));
        exit();
    }
} else {
    header("Location: login.php");
    exit();
}
?>