<?php
session_start();
$page_title = "Daftar | Saber eProc";
$path_to_root = "./";

require_once 'config.php';
require_once 'includes/vendor_name_similarity.php';
require_once 'includes/form_draft.php';
require_once 'includes/verification_email.php';

// --- Panel sukses: data untuk tombol "Kirim Ulang" ---------------------------
// Sisa cooldown dihitung dari stempel di database, bukan dari saat halaman
// dibuka, supaya menekan refresh tidak mengembalikan hitungan ke nol.
$pending_email = (string) ($_SESSION['pending_verification']['email'] ?? '');
$resend_wait   = 0;

if (isset($_GET['success']) && $pending_email !== '' && isset($conn)) {
    $resend_wait = vendor_verification_resend_wait(vendor_verification_last_sent($conn, $pending_email));
}

// --- Duplicate-company bounce -----------------------------------------------
// register_action.php sends the vendor back here with ?duplicate=1 when a
// >=95% name match exists and the confirmation was never answered — which means
// either JavaScript never ran, or the form was posted directly. Rebuild the
// answer server-side so the confirmation is shown either way, and repopulate
// from the saved draft so nothing typed is lost.
$reg_draft       = form_draft_get('vendor_register');
$dup_bounce      = isset($_GET['duplicate']) && $reg_draft !== [];
$dup_matches     = [];
$dup_ack_token   = '';

if ($dup_bounce && isset($conn)) {
    $dup_result    = vendor_duplicate_check($conn, (string) ($reg_draft['company_name'] ?? ''));
    $dup_matches   = $dup_result['matches'];
    $dup_ack_token = vendor_duplicate_ack_token((string) ($reg_draft['company_name'] ?? ''));
}

/** Value for a field: what the vendor last typed, else empty. */
function reg_old(array $draft, string $field): string
{
    return htmlspecialchars((string) ($draft[$field] ?? ''), ENT_QUOTES, 'UTF-8');
}
$draft_business_models = array_filter(explode(',', (string) ($reg_draft['business_model'] ?? '')));

// --- Invitation link validation & one-time lock -----------------------------
// When a vendor arrives via an invitation email link (register.php?invite=TOKEN),
// the token must resolve to a live vendor_invitations row (invite_token is
// cleared to NULL once the link is used — see register_action.php). An
// unresolved token means the link was never valid, was mistyped, or has
// already been consumed, so we bounce straight to login instead of showing
// a registration form for a dead link. No token at all means plain
// self-registration, which must keep working exactly as before.
$invite_token = '';
$invite_company_type = null; // mapped company_type to lock the form to, or null = no lock
$invite_sub_category = null; // raw sub_category label, for the locked-field display text

if (!empty($_GET['invite']) && is_string($_GET['invite']) && isset($conn)) {
    $invite_token = preg_replace('/[^a-f0-9]/i', '', $_GET['invite']);
    if ($invite_token !== '' && strlen($invite_token) <= 64) {
        $invite_row = null;
        try {
            $lookupStmt = $conn->prepare("SELECT sub_category FROM vendor_invitations WHERE invite_token = ?");
            $lookupStmt->bind_param("s", $invite_token);
            $lookupStmt->execute();
            $invite_row = $lookupStmt->get_result()->fetch_assoc();
        } catch (mysqli_sql_exception $e) {
            error_log("[register] invite lookup failed: " . $e->getMessage());
        }

        if (!$invite_row) {
            header("Location: login.php?error=" . urlencode("Link registrasi tidak valid atau sudah digunakan. Silakan login atau hubungi tim procurement."));
            exit();
        }

        // Valid token — record the open (best-effort) and resolve the lock.
        try {
            $openStmt = $conn->prepare(
                "UPDATE vendor_invitations
                    SET opened_at = COALESCE(opened_at, NOW()),
                        open_count = open_count + 1
                  WHERE invite_token = ?"
            );
            $openStmt->bind_param("s", $invite_token);
            $openStmt->execute();
        } catch (mysqli_sql_exception $e) {
            error_log("[register] invite open-tracking failed: " . $e->getMessage());
        }

        $invite_sub_category = $invite_row['sub_category'] ?? null;
        $sub_category_to_company_type = [
            'Kontraktor'   => 'Contractor',
            'Supplier'     => 'Supplier',
            'Perseorangan' => 'Perseorangan',
        ];
        // Older invitations sent before sub_category existed have it NULL —
        // treat those as unlocked so the link doesn't suddenly break.
        $invite_company_type = $sub_category_to_company_type[$invite_sub_category] ?? null;
    }
}

include 'includes/header.php';
?>

<!-- intl-tel-input Library -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/css/intlTelInput.cs<style>
    body { background-color: #f8fafc; }
    .register-card { border-radius: 20px; overflow: hidden; box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08); border: 1px solid #e2e8f0; background-color: #fff; }
    .register-sidebar { background: linear-gradient(145deg, #0F1E36 0%, #1e3a8a 55%, #1e40af 100%); position: relative; color: white; }
    .register-sidebar::before { content: ''; position: absolute; top: -60px; right: -60px; width: 280px; height: 280px; background: radial-gradient(circle, rgba(0, 144, 255, 0.22) 0%, rgba(0, 144, 255, 0) 70%); border-radius: 50%; z-index: 0; }
    .register-sidebar::after { content: "\f2b5"; font-family: "Font Awesome 5 Free"; font-weight: 900; position: absolute; bottom: -20px; right: -20px; font-size: 14rem; color: rgba(255,255,255,0.03); transform: rotate(-15deg); z-index: 0; pointer-events: none; }
    .sidebar-content { position: relative; z-index: 1; }
    .form-group-custom { position: relative; margin-bottom: 1.5rem; }
    .form-control-custom, .form-select-custom { background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 0.85rem 1.2rem 0.85rem 3rem; font-size: 0.95rem; transition: all 0.2s ease; color: #0f172a; }
    .form-control-custom:focus, .form-select-custom:focus { background-color: #ffffff; border-color: #1e40af; box-shadow: 0 0 0 0.25rem rgba(30, 64, 175, 0.15); }
    .input-icon { position: absolute; top: 50%; transform: translateY(-50%); left: 1.2rem; color: #94a3b8; font-size: 1.1rem; transition: color 0.2s ease; z-index: 5; pointer-events: none; }
    .form-group-custom:focus-within .input-icon { color: #1e40af; }
    .form-label-custom { font-size: 0.75rem; font-weight: 700; text-transform: uppercase; color: #475569; margin-bottom: 0.4rem; letter-spacing: 0.5px; display: block; }
    .password-toggle { position: absolute; top: 50%; transform: translateY(-50%); right: 1.2rem; cursor: pointer; color: #94a3b8; z-index: 10; transition: color 0.2s; }
    .password-toggle:hover { color: #1e40af; }
    .file-input-custom { padding-left: 1.2rem; }
    .btn-register { border-radius: 12px; padding: 0.85rem 2rem; font-weight: 700; font-size: 1.05rem; letter-spacing: -0.01em; transition: all 0.2s ease; background-color: #1e40af; border-color: #1e40af; box-shadow: 0 4px 12px rgba(30, 64, 175, 0.2); }
    .btn-register:hover:not(:disabled) { background-color: #1e3a8a; border-color: #1e3a8a; transform: translateY(-2px); box-shadow: 0 8px 18px rgba(30, 64, 175, 0.3); }
    .btn-register:disabled { background-color: #cbd5e1; border-color: #cbd5e1; cursor: not-allowed; }

    /* Model Bisnis: collapsed multi-select picker */
    .bm-select { position: relative; }
    .bm-toggle { display: flex; align-items: center; width: 100%; text-align: left; background-color: #ffffff; border: 1.5px solid #e2e8f0; border-radius: 12px; padding: 0.85rem 3rem 0.85rem 3rem; font-size: 0.95rem; color: #0f172a; transition: all 0.2s ease; }
    .bm-toggle:hover { background-color: #f8fafc; }
    .bm-toggle[aria-expanded="true"], .bm-toggle:focus-visible { background-color: #ffffff; border-color: #1e40af; box-shadow: 0 0 0 0.25rem rgba(30, 64, 175, 0.15); outline: none; }
    .bm-toggle .input-icon { color: #94a3b8; }
    .bm-toggle[aria-expanded="true"] .input-icon { color: #1e40af; }
    .bm-toggle-text { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .bm-toggle-text.is-placeholder { color: #94a3b8; }
    .bm-caret { position: absolute; right: 1.2rem; color: #94a3b8; font-size: 0.85rem; transition: transform 0.2s ease; pointer-events: none; }
    .bm-toggle[aria-expanded="true"] .bm-caret { transform: rotate(180deg); }

    .bm-menu { position: absolute; z-index: 20; top: calc(100% + 6px); left: 0; right: 0; background-color: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12); padding: 0.75rem; display: flex; flex-direction: column; gap: 0.4rem; }
    .bm-menu[hidden] { display: none; }
    .bm-menu-hint { font-size: 0.75rem; color: #64748b; padding: 0 0.25rem 0.25rem; }
    .bm-option { display: flex; align-items: flex-start; gap: 0.7rem; background-color: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 10px; padding: 0.65rem 0.9rem; font-size: 0.9rem; color: #0f172a; cursor: pointer; transition: all 0.2s ease; margin: 0; }
    .bm-option:hover { background-color: #eff6ff; border-color: #bfdbfe; }
    .bm-option:has(input:checked) { background-color: #ffffff; border-color: #1e40af; }
    .bm-option:focus-within { border-color: #1e40af; }
    .bm-option input { cursor: pointer; }
    .bm-done { align-self: stretch; margin-top: 0.25rem; border: none; border-radius: 8px; padding: 0.5rem 1rem; font-size: 0.8rem; font-weight: 700; color: #ffffff; background-color: #1e40af; transition: background-color 0.2s ease; }
    .bm-done:hover { background-color: #1e3a8a; }

    /* intl-tel-input */
    .iti { width: 100%; }
    .iti .iti__tel-input {
        background-color: #ffffff;
        border: 1.5px solid #e2e8f0;
        border-radius: 12px;
        padding: 0.85rem 1.2rem;
        font-size: 0.95rem;
        transition: all 0.2s ease;
        color: #0f172a;
        width: 100%;
        height: auto;
    }
    .iti .iti__tel-input:focus {
        background-color: #ffffff;
        border-color: #1e40af;
        box-shadow: 0 0 0 0.25rem rgba(30, 64, 175, 0.15);
        outline: none;
    }
    .iti__country-container { border-radius: 12px 0 0 12px; }
    .iti__selected-country { border-radius: 12px 0 0 12px; padding: 0 8px 0 12px; background-color: transparent; }
    .iti__dropdown-content { border-radius: 12px; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.12); border: 1px solid #e2e8f0; margin-top: 4px; }
    .iti__search-input { border-radius: 8px; border: 1px solid #cbd5e1; padding: 8px 12px; font-size: 0.85rem; }
    .iti__search-input:focus { border-color: #1e40af; box-shadow: 0 0 0 0.15rem rgba(30, 64, 175, 0.15); outline: none; }
    .iti__country { padding: 8px 12px; transition: background-color 0.15s ease; }
    .iti__country:hover, .iti__country--highlight { background-color: #eff6ff; }
    .phone-status { display: inline-flex; align-items: center; gap: 4px; font-size: 0.7rem; font-weight: 600; margin-top: 6px; transition: all 0.3s ease; }
    .phone-status.valid { color: #16a34a; }
    .phone-status.invalid { color: #be123c; }
    .phone-status.empty { color: #64748b; }
</style>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-xl-10 col-lg-11">
            <div class="register-card">
                <div class="row g-0">
                    
                    <div class="col-md-5 register-sidebar p-5 d-none d-md-flex flex-column justify-content-between">
                        <div class="sidebar-content">
                            <div class="mb-5">
                                <div class="d-flex align-items-center gap-2 mb-4">
                                    <div class="bg-white rounded-3 p-1.5 shadow-sm d-inline-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                                        <?= vss_brand_logo(32) ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold fs-5 text-white lh-1">SABER <span style="color:#00D8FF;">eProc</span></div>
                                        <small class="text-white-50" style="font-size: 0.68rem; letter-spacing: 1px;">ENTERPRISE WORKSPACE</small>
                                    </div>
                                </div>
                                <span class="badge bg-white text-primary rounded-pill px-3 py-1.5 mb-3 fw-bold shadow-sm" style="color: #1e40af !important;">Pendaftaran Rekanan Vendor</span>
                                <h2 class="fw-bold mb-3 display-6 text-white">Bergabunglah dalam Ekosistem Kami</h2>
                                <p class="opacity-75" style="line-height: 1.6;">Daftarkan perusahaan Anda untuk menjadi mitra strategis Saber Enterprise. Buka peluang tender dan sederhanakan proses pengadaan Anda.</p>
                            </div>
                            
                            <div class="d-flex flex-column gap-4 mt-5">
                                <div class="d-flex align-items-center">
                                    <div class="bg-white bg-opacity-25 rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;"><i class="fas fa-bolt fs-5"></i></div>
                                    <div><h6 class="fw-bold mb-1 text-white">Proses Pendaftaran Cepat</h6><small class="opacity-75">Pra-Kualifikasi 100% digital & transparan.</small></div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="bg-white bg-opacity-25 rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;"><i class="fas fa-chart-line fs-5"></i></div>
                                    <div><h6 class="fw-bold mb-1 text-white">Pelacakan Real-time</h6><small class="opacity-75">Pantau status tender dan persetujuan.</small></div>
                                </div>
                                <div class="d-flex align-items-center">
                                    <div class="bg-white bg-opacity-25 rounded-circle p-3 me-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;"><i class="fas fa-box-open fs-5"></i></div>
                                    <div><h6 class="fw-bold mb-1 text-white">Katalog Produk</h6><small class="opacity-75">Tawarkan lini SKU dan produk terbaik Anda.</small></div>
                                </div>
                            </div>
                        </div>
                        <div class="sidebar-content mt-5 pt-5 border-top border-light border-opacity-25">
                            <p class="small mb-0 opacity-75">&copy; <?= date('Y') ?> Saber Enterprise. All rights reserved.</p>
                        </div>
                    </div>

                    <div class="col-md-7 p-4 p-md-5">
                        <div class="d-md-none text-center mb-4">
                            <h3 class="fw-bold" style="color: #1e40af;">Saber eProc</h3>
                            <p class="text-muted small">Pendaftaran Rekanan Vendor</p>
                        </div>  </div>

                        <?php if(isset($_GET['error'])): ?>
                            <div class="alert alert-danger alert-dismissible fade show shadow-sm rounded-3 border-0 border-start border-4 border-danger" role="alert">
                                <i class="fas fa-exclamation-circle me-2 text-danger"></i> <?= htmlspecialchars($_GET['error']) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        <?php endif; ?>

                        <?php if(isset($_GET['success'])): ?>
                            <div class="text-center py-5">
                                <div class="mb-4">
                                    <i class="fas fa-envelope-open-text fa-5x text-primary"></i>
                                </div>
                                <h3 class="fw-bold text-dark">Satu Langkah Lagi!</h3>
                                
                                <?php if(!empty($_GET['upload_warning'])): ?>
                                    <div class="alert alert-danger border-0 shadow-sm mt-4 mb-0 text-start rounded-4">
                                        <p class="text-dark mb-2"><i class="fas fa-triangle-exclamation text-danger me-1"></i> <strong>Dokumen berikut gagal tersimpan:</strong> <?= htmlspecialchars($_GET['upload_warning']) ?>.</p>
                                        <p class="text-dark mb-0">Akun Anda tetap terdaftar. Silakan unggah ulang dokumen tersebut melalui menu <strong>Profil</strong> setelah Anda berhasil masuk (login).</p>
                                    </div>
                                <?php endif; ?>

                                <div class="alert alert-warning border-0 shadow-sm mt-4 mb-4 text-start rounded-4">
                                    <p class="text-dark mb-2">Akun Anda telah berhasil didaftarkan, namun saat ini <strong>masih berstatus tidak aktif</strong>.</p>
                                    <p class="text-dark mb-0"><i class="fas fa-exclamation-circle text-danger me-1"></i> <strong>Anda wajib memeriksa kotak masuk Email (atau folder Spam) Anda sekarang</strong>. Silakan klik tautan verifikasi yang telah kami kirimkan<?= $pending_email !== '' ? ' ke <strong>' . htmlspecialchars($pending_email) . '</strong>' : '' ?> agar Anda dapat masuk (login).</p>
                                </div>

                                <?php if(isset($_GET['resent'])): ?>
                                    <div class="alert alert-success border-0 shadow-sm mb-4 text-start rounded-4">
                                        <i class="fas fa-paper-plane me-1"></i> Email verifikasi telah dikirim ulang. Mohon periksa kembali kotak masuk dan folder Spam Anda.
                                    </div>
                                <?php endif; ?>

                                <?php if(!empty($_GET['resend_error'])): ?>
                                    <div class="alert alert-danger border-0 shadow-sm mb-4 text-start rounded-4">
                                        <i class="fas fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($_GET['resend_error']) ?>
                                    </div>
                                <?php endif; ?>

                                <?php if($pending_email !== ''): ?>
                                    <p class="text-muted small mb-2">Belum menerima emailnya?</p>
                                    <form action="resend_verification.php" method="POST" class="mb-3">
                                        <button type="submit" id="resendBtn" class="btn btn-primary rounded-pill px-5 fw-bold shadow-sm" data-wait="<?= (int) $resend_wait ?>">
                                            <i class="fas fa-rotate-right me-1"></i> Kirim Ulang Email Verifikasi
                                        </button>
                                    </form>
                                    <script>
                                        (function () {
                                            const btn = document.getElementById('resendBtn');
                                            const label = btn.innerHTML;
                                            let left = parseInt(btn.dataset.wait, 10) || 0;

                                            const tick = () => {
                                                if (left <= 0) {
                                                    btn.disabled = false;
                                                    btn.innerHTML = label;
                                                    return;
                                                }
                                                btn.disabled = true;
                                                btn.innerHTML = '<i class="fas fa-clock me-1"></i> Kirim ulang dalam ' + left + ' detik';
                                                left--;
                                                setTimeout(tick, 1000);
                                            };

                                            tick();

                                            // Kunci tombol begitu diklik: cooldown di server yang menentukan,
                                            // ini hanya mencegah klik ganda mengirim dua permintaan sekaligus.
                                            btn.form.addEventListener('submit', () => {
                                                btn.disabled = true;
                                                btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Mengirim...';
                                            });
                                        })();
                                    </script>
                                <?php endif; ?>

                                <a href="login.php" class="btn btn-outline-primary rounded-pill px-5 fw-bold shadow-sm">Kembali ke Halaman Masuk</a>
                            </div>
                        <?php else: ?>
                            
                            <div class="d-flex justify-content-between align-items-end mb-4 pb-2 border-bottom">
                                <h4 class="fw-bold text-dark mb-0">Buat Akun</h4>
                                <a href="login.php" class="text-decoration-none small fw-bold text-primary">Masuk</a>
                            </div>

                            <form action="register_action.php" method="POST" enctype="multipart/form-data" autocomplete="off" id="regForm">
                                <?php if ($invite_token !== ''): ?>
                                    <input type="hidden" name="invite_token" value="<?= htmlspecialchars($invite_token) ?>">
                                <?php endif; ?>

                                <?php /* Carries the vendor's "this is a different company" answer. Bound
                                         to the name it was given for, so editing the name clears it.
                                         Starts EMPTY even on a bounce — the token is handed to the
                                         page below and only written here once the vendor answers, so
                                         a bounced attempt cannot resubmit itself unanswered. */ ?>
                                <input type="hidden" name="duplicate_ack" id="duplicateAck" value="">

                                <div class="form-group-custom">
                                    <label class="form-label-custom">Nama Perusahaan (PT/CV) <span class="text-danger">*</span></label>
                                    <div class="position-relative">
                                        <i class="fas fa-building input-icon"></i>
                                        <input type="text" name="company_name" id="companyName" class="form-control form-control-custom" placeholder="mis. PT Maju Bersama" value="<?= reg_old($reg_draft, 'company_name') ?>" required>
                                    </div>
                                </div>

                                <?php /*
                                    Model Bisnis is a MULTI-select: a vendor often does
                                    several kinds of work at once. company_type is derived
                                    from the answers server-side (see register_action.php)
                                    — construction/MEP anywhere in the selection means
                                    Contractor, otherwise Supplier — so the vendor never
                                    picks the review track directly. Invitation links still
                                    override the derived type with the invited category.
                                */ ?>
                                <div class="form-group-custom">
                                    <label class="form-label-custom" id="bmLabel">Model Bisnis <span class="text-danger">*</span></label>

                                    <?php /*
                                        Collapsed by default so the options only appear once the
                                        vendor opens the picker. The derived category is
                                        deliberately NOT surfaced anywhere on this page — the
                                        vendor answers what it does, not which review track it
                                        lands on.
                                    */ ?>
                                    <div class="bm-select" id="bmSelect">
                                        <button type="button" class="bm-toggle" id="bmToggle" aria-expanded="false" aria-haspopup="true" aria-labelledby="bmLabel bmToggleText">
                                            <i class="fas fa-sitemap input-icon"></i>
                                            <span class="bm-toggle-text is-placeholder" id="bmToggleText">Pilih Model Bisnis...</span>
                                            <i class="fas fa-chevron-down bm-caret"></i>
                                        </button>

                                        <div class="bm-menu" id="bmMenu" hidden>
                                            <div class="bm-menu-hint">Pilih <strong>semua</strong> yang sesuai dengan bidang usaha Anda.</div>
                                            <?php foreach (vendor_business_model_options() as $bm_code => $bm_label): ?>
                                                <label class="bm-option" for="bm_<?= htmlspecialchars($bm_code) ?>">
                                                    <input class="form-check-input mt-0 flex-shrink-0" type="checkbox"
                                                           name="business_model[]" id="bm_<?= htmlspecialchars($bm_code) ?>"
                                                           value="<?= htmlspecialchars($bm_code) ?>"
                                                           <?= in_array($bm_code, $draft_business_models, true) ? 'checked' : '' ?>>
                                                    <span><?= htmlspecialchars($bm_label) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                            <button type="button" class="bm-done" id="bmDone">Selesai</button>
                                        </div>
                                    </div>

                                    <?php if ($invite_company_type !== null): ?>
                                        <input type="hidden" name="company_type" value="<?= htmlspecialchars($invite_company_type) ?>">
                                        <div class="form-text small mt-2 text-muted" style="font-size: 0.75rem;">
                                            <i class="fas fa-lock me-1"></i> Kategori vendor Anda sudah ditetapkan melalui undangan, namun jawaban ini tetap kami perlukan.
                                        </div>
                                    <?php endif; ?>

                                    <div id="bmError" class="form-text text-danger d-none mt-2 fw-bold" style="font-size: 0.75rem;">
                                        <i class="fas fa-exclamation-triangle me-1"></i> Pilih minimal satu model bisnis.
                                    </div>
                                </div>

                                <div class="row g-3">
                                    <div class="col-sm-6">
                                        <div class="form-group-custom">
                                            <label class="form-label-custom">Narahubung <span class="text-danger">*</span></label>
                                            <div class="position-relative">
                                                <i class="fas fa-user-tie input-icon"></i>
                                                <input type="text" name="contact_person" class="form-control form-control-custom" placeholder="Nama Lengkap" value="<?= reg_old($reg_draft, 'contact_person') ?>" required>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-sm-6">
                                        <div class="form-group-custom">
                                            <label class="form-label-custom">Alamat Email <span class="text-danger">*</span></label>
                                            <div class="position-relative">
                                                <i class="fas fa-envelope input-icon"></i>
                                                <input type="email" name="email" class="form-control form-control-custom" placeholder="nama@perusahaan.com" value="<?= reg_old($reg_draft, 'email') ?>" required autocomplete="off">
                                            </div>
                                            <div class="form-text small text-muted mt-1" style="font-size: 0.7rem;">Akan digunakan sebagai ID Masuk.</div>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group-custom mb-4">
                                    <label class="form-label-custom">Nomor Telepon <span class="text-danger">*</span></label>
                                    <!--
                                        intl-tel-input: Country code selector with flag dropdown
                                        - Hidden input 'phone' carries the full international number (e.g. +6281234567890)
                                        - Visible input is for user interaction only
                                    -->
                                    <input type="tel" id="phoneInput" class="form-control" placeholder="812 3456 7890" required autocomplete="off">
                                    <input type="hidden" name="phone" id="phoneHidden" value="<?= reg_old($reg_draft, 'phone') ?>">
                                    <div id="phoneStatus" class="phone-status empty">
                                        <i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>
                                    </div>
                                </div>

                                <div class="form-group-custom mb-2">
                                    <label class="form-label-custom">Kata Sandi Akun <span class="text-danger">*</span></label>
                                    <div class="position-relative">
                                        <i class="fas fa-lock input-icon"></i>
                                        <input type="password" name="password" id="regPassword" class="form-control form-control-custom" placeholder="Buat kata sandi yang kuat" required autocomplete="new-password">
                                        <i class="fas fa-eye password-toggle" id="togglePassword"></i>
                                    </div>
                                </div>
                                
                                <ul class="list-unstyled small mb-3 text-muted ps-2" style="font-size: 0.75rem;">
                                    <li id="reqLength" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 8 karakter</li>
                                    <li id="reqUpper" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 1 huruf besar (A-Z)</li>
                                    <li id="reqNumber" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 1 angka (0-9)</li>
                                    <li id="reqSymbol" class="text-danger"><i class="fas fa-times-circle me-1"></i> Minimal 1 simbol (!@#$%^&*)</li>
                                </ul>

                                <div class="form-group-custom mb-4">
                                    <label class="form-label-custom">Konfirmasi Kata Sandi <span class="text-danger">*</span></label>
                                    <div class="position-relative">
                                        <i class="fas fa-lock-open input-icon"></i>
                                        <input type="password" name="confirm_password" id="regConfirmPassword" class="form-control form-control-custom" placeholder="Ketik ulang kata sandi" required autocomplete="new-password">
                                        <i class="fas fa-eye password-toggle" id="toggleConfirmPassword"></i>
                                    </div>
                                    <div id="passwordError" class="form-text text-danger d-none mt-2 fw-bold"><i class="fas fa-exclamation-triangle me-1"></i> Kata sandi tidak cocok!</div>
                                </div>

                                <div class="form-group-custom mb-5 p-3 rounded-3" style="background-color: #f8f9fa; border: 1px dashed #ced4da;">
                                    <label class="form-label-custom text-dark mb-2"><i class="fas fa-file-upload me-2 text-primary"></i>Profil Perusahaan (Opsional)</label>
                                    <input type="file" name="registration_doc" class="form-control form-control-custom file-input-custom bg-white" accept=".pdf,.jpg,.jpeg,.png">
                                    <div class="form-text small text-muted mt-2"><i class="fas fa-info-circle me-1"></i> Format: PDF, JPG, PNG. Maksimal 30MB.</div>
                                </div>

                                <div class="d-grid mt-2">
                                    <button type="submit" name="register_btn" id="btnRegister" class="btn btn-primary btn-register d-flex justify-content-center align-items-center" disabled>
                                        Buat Akun Vendor <i class="fas fa-arrow-right ms-2"></i>
                                    </button>
                                </div>

                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php /*
    Duplicate-company confirmation.

    Shown when the company name the vendor typed already looks like one in the
    register. Two severities share this one dialog because the question put to
    the vendor is identical — "is this your company?" — and only the wording and
    what happens on "yes" differ:

      block  a >= 95% name match. Answering "yes" ends the registration; the
             account already exists and the vendor needs to log in, not register
             a second time.
      warn   the same distinctive words plus one. Answering "no" simply carries
             on, because this signal also links genuinely different companies.

    The vendor is never trapped: "this is a different company" always proceeds.
*/ ?>
<?php /*
    Sized to fit a laptop viewport without the page scrolling: modal-lg for width
    so the two answers sit side by side instead of stacking, and
    and a capped, independently scrolling match list so three matches cannot push
    the buttons off-screen. Deliberately NOT modal-dialog-scrollable: that class
    stretches the dialog to the full viewport height (measured 1024px tall on a
    1080p screen) regardless of how little content it holds.

    Icons are Font Awesome 6.0.0 (see includes/header.php) — 6.1-only names such
    as fa-building-circle-check render as an empty box, so every icon here is one
    that exists in 6.0.
*/ ?>
<div class="modal fade" id="dupModal" tabindex="-1" aria-labelledby="dupModalTitle" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg position-relative" style="border-radius: 20px;">

      <?php /* Dismiss is always available: this dialog asks a question, it never
               traps the vendor. Closing simply leaves the form as it was, and
               submitting again asks once more. */ ?>
      <button type="button" class="btn-close position-absolute"
              style="top:1rem; right:1rem; z-index:10;"
              data-bs-dismiss="modal" aria-label="Tutup"></button>

      <div class="modal-body p-4 px-md-5 py-md-4" id="dupAskBody">
        <div class="text-center mb-3">
          <div class="d-inline-flex align-items-center justify-content-center mb-2"
               style="width:54px;height:54px;border-radius:50%;background:#fff4e5;">
            <i class="fas fa-building fa-lg" style="color:#f59e0b;"></i>
          </div>
          <h5 class="fw-bold text-dark mb-2" id="dupModalTitle">Perusahaan Ini Sudah Terdaftar</h5>
          <p class="text-muted small mb-0 mx-auto" style="max-width:46ch;" id="dupModalLead">
            Kami menemukan perusahaan dengan nama yang sama di sistem kami.
          </p>
        </div>

        <div id="dupMatchList" class="mb-3" style="max-height:34vh; overflow-y:auto;"></div>

        <p class="text-center fw-semibold text-dark mb-3">Apakah ini perusahaan Anda?</p>

        <?php /* Side by side from sm up — stacking these was what pushed the
                 dialog past the fold on a laptop. */ ?>
        <div class="d-flex flex-column flex-sm-row gap-2">
          <button type="button" class="btn btn-primary rounded-pill py-2 px-4 fw-bold flex-fill" id="dupYes">
            Ya, ini perusahaan saya
          </button>
          <button type="button" class="btn btn-outline-secondary rounded-pill py-2 px-4 fw-bold flex-fill" id="dupNo">
            Bukan, perusahaan saya berbeda
          </button>
        </div>
      </div>

      <?php /* Shown after "yes" on a blocking match — the account exists, so the
               way forward is login or recovery, never a second registration. */ ?>
      <div class="modal-body p-4 px-md-5 py-md-4 d-none" id="dupStopBody">
        <div class="text-center mb-3">
          <div class="d-inline-flex align-items-center justify-content-center mb-2"
               style="width:54px;height:54px;border-radius:50%;background:#e7f1ff;">
            <i class="fas fa-circle-info fa-lg text-primary"></i>
          </div>
          <h5 class="fw-bold text-dark mb-2">Gunakan Akun yang Sudah Ada</h5>
          <p class="text-muted small mb-0 mx-auto" style="max-width:46ch;">
            Perusahaan Anda sudah terdaftar, jadi tidak perlu mendaftar lagi.
            Silakan masuk memakai email yang terdaftar di atas.
          </p>
        </div>

        <div class="alert alert-light border rounded-4 small text-dark mb-3">
          <p class="mb-2"><i class="fas fa-key text-primary me-1"></i> <strong>Lupa kata sandi?</strong>
            Gunakan menu <em>Lupa Kata Sandi</em> di halaman masuk.</p>
          <p class="mb-0"><i class="fas fa-user-plus text-primary me-1"></i> <strong>Anda rekan kerja baru di perusahaan yang sama?</strong>
            Minta admin akun perusahaan Anda menambahkan Anda sebagai narahubung, atau hubungi tim procurement.</p>
        </div>

        <div class="d-flex flex-column flex-sm-row gap-2">
          <a href="login.php" class="btn btn-primary rounded-pill py-2 px-4 fw-bold flex-fill">Ke Halaman Masuk</a>
          <button type="button" class="btn btn-outline-secondary rounded-pill py-2 px-4 fw-bold flex-fill" id="dupBack">Kembali ke formulir</button>
        </div>
      </div>

    </div>
  </div>
</div>

<?php if ($dup_bounce && $dup_matches): ?>
<?php /* The vendor got here by posting past the dialog (no JS, or a direct POST).
         Hand the same answer to the page so the confirmation still gets asked. */ ?>
<script>window.__dupBounce = <?= json_encode(['level' => 'block', 'matches' => $dup_matches, 'ack' => $dup_ack_token], JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<!-- intl-tel-input JS (loaded before our script) -->
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/intlTelInput.min.js"></script>

<script>
    document.addEventListener('DOMContentLoaded', function () {

        // =============================================
        // 1. INTL-TEL-INPUT INITIALIZATION
        // =============================================
        const phoneInputEl = document.getElementById('phoneInput');
        const phoneHidden = document.getElementById('phoneHidden');
        const phoneStatus = document.getElementById('phoneStatus');

        const iti = window.intlTelInput(phoneInputEl, {
            initialCountry: "id",                   // Default to Indonesia
            preferredCountries: ["id", "sg", "my", "th", "ph", "vn", "jp", "cn", "kr", "au", "sa", "ae"],
            separateDialCode: true,                  // Show dial code next to flag
            nationalMode: false,
            formatAsYouType: true,
            formatOnDisplay: true,
            strictMode: true,                        // Block non-numeric characters
            validationNumberType: "MOBILE",
            countrySearch: true,                     // Enable search in dropdown
            containerClass: "w-100",
            loadUtilsOnInit: "https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/utils.js"
        });

        // Update hidden field and validate on every keystroke & country change
        function syncPhone() {
            const fullNumber = iti.getNumber();       // E.164 format: +628123456789
            phoneHidden.value = fullNumber;

            const inputVal = phoneInputEl.value.replace(/\s/g, '');

            if (!inputVal || inputVal.length < 4) {
                phoneStatus.className = 'phone-status empty';
                phoneStatus.innerHTML = '<i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>';
                return false;
            }

            if (iti.isValidNumber()) {
                const countryData = iti.getSelectedCountryData();
                const countryName = countryData.name || '';
                phoneStatus.className = 'phone-status valid';
                phoneStatus.innerHTML = '<i class="fas fa-check-circle"></i> <span>Nomor valid — ' + countryName + ' (' + fullNumber + ')</span>';
                return true;
            } else {
                const errorMap = [
                    "Nomor tidak valid",
                    "Kode negara tidak valid",
                    "Nomor terlalu pendek",
                    "Nomor terlalu panjang",
                    "Nomor tidak valid"
                ];
                const errorCode = iti.getValidationError();
                const errorMsg = errorMap[errorCode] || "Nomor tidak valid";
                phoneStatus.className = 'phone-status invalid';
                phoneStatus.innerHTML = '<i class="fas fa-times-circle"></i> <span>' + errorMsg + '</span>';
                return false;
            }
        }

        phoneInputEl.addEventListener('input', syncPhone);
        phoneInputEl.addEventListener('countrychange', syncPhone);

        // A bounced attempt comes back with the phone already known — restore it
        // so the vendor doesn't retype a number the server already had.
        if (phoneHidden.value) {
            try { iti.setNumber(phoneHidden.value); } catch (err) { /* utils not up yet */ }
            syncPhone();
        }

        // Prevent form submit if phone invalid
        const regForm = document.getElementById('regForm');
        if (regForm) {
            regForm.addEventListener('submit', function(e) {
                // Model Bisnis drives company_type, so an empty answer can't pass.
                if (selectedBusinessModels().length === 0) {
                    e.preventDefault();
                    bmError.classList.remove('d-none');
                    setBusinessModelMenu(true);
                    bmToggle.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    return;
                }

                // Final sync before submit
                syncPhone();
                if (!iti.isValidNumber()) {
                    e.preventDefault();
                    phoneInputEl.focus();
                    phoneStatus.className = 'phone-status invalid';
                    phoneStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span>Harap masukkan nomor telepon yang valid sebelum melanjutkan.</span>';
                }
            });
        }


        // =============================================
        // DUPLICATE COMPANY CONFIRMATION
        // =============================================
        // Sits here, with the submit flow it extends, rather than in the
        // numbered sections below — it is the last gate before the account is
        // created: does a company by this name already exist? Only the vendor
        // can settle that, so we ask them.
        //
        // This listener is registered AFTER the validation one above and bails
        // out when that one has already stopped the submit — the phone and
        // Model Bisnis checks stay the first thing a vendor is told about.
        //
        // Everything here fails OPEN. A network error, a slow lookup, a
        // malformed reply: the form submits, and register_action.php runs the
        // same check server-side. Nothing about a de-duplication convenience
        // should be able to stop a vendor registering.
        const dupModalEl = document.getElementById('dupModal');
        const companyNameEl = document.getElementById('companyName');
        const dupAckEl = document.getElementById('duplicateAck');

        if (regForm && dupModalEl && companyNameEl) {
            const dupModal   = new bootstrap.Modal(dupModalEl);
            const askBody    = document.getElementById('dupAskBody');
            const stopBody   = document.getElementById('dupStopBody');
            const matchList  = document.getElementById('dupMatchList');
            const dupTitle   = document.getElementById('dupModalTitle');
            const dupLead    = document.getElementById('dupModalLead');

            // Cleared once the vendor has answered "different company" for the
            // name currently in the field. Editing the name revokes it.
            let dupAnswered = dupAckEl.value !== '';
            let checking = false;

            companyNameEl.addEventListener('input', function () {
                dupAnswered = false;
                dupAckEl.value = '';
            });

            function submitRegistration() {
                dupAnswered = true;
                // regForm.submit() does not carry the submit button's own name,
                // and register_action.php gates everything on register_btn being
                // present — so supply it explicitly.
                if (!regForm.querySelector('input[type="hidden"][name="register_btn"]')) {
                    const flag = document.createElement('input');
                    flag.type = 'hidden';
                    flag.name = 'register_btn';
                    flag.value = '1';
                    regForm.appendChild(flag);
                }
                regForm.submit();
            }

            function renderMatches(matches) {
                matchList.innerHTML = '';
                matches.forEach(function (m) {
                    const card = document.createElement('div');
                    card.className = 'px-3 py-2 mb-2 rounded-4 border bg-light';

                    const name = document.createElement('div');
                    name.className = 'fw-bold text-dark';
                    name.textContent = m.name;          // textContent: the name is vendor-supplied

                    const mail = document.createElement('div');
                    mail.className = 'small text-muted mt-1';
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-envelope me-1';
                    mail.appendChild(icon);
                    mail.appendChild(document.createTextNode(' Terdaftar dengan email ' + m.email));

                    card.appendChild(name);
                    card.appendChild(mail);
                    matchList.appendChild(card);
                });
            }

            function openDupModal(level, matches) {
                renderMatches(matches);

                if (level === 'warn') {
                    dupTitle.textContent = 'Nama Perusahaan Mirip Ditemukan';
                    dupLead.textContent = 'Kami menemukan perusahaan dengan nama yang mirip. '
                        + 'Mohon periksa sebentar — jika ini perusahaan yang berbeda, pendaftaran Anda bisa dilanjutkan.';
                } else {
                    dupTitle.textContent = 'Perusahaan Ini Sudah Terdaftar';
                    dupLead.textContent = 'Nama perusahaan yang Anda masukkan sama dengan perusahaan '
                        + 'yang sudah ada di sistem kami.';
                }

                askBody.classList.remove('d-none');
                stopBody.classList.add('d-none');
                dupModal.show();
            }

            document.getElementById('dupYes').addEventListener('click', function () {
                // It IS their company — for both severities. The account already
                // exists, so the way forward is login, not a second registration.
                askBody.classList.add('d-none');
                stopBody.classList.remove('d-none');
            });

            document.getElementById('dupNo').addEventListener('click', function () {
                dupModal.hide();
                submitRegistration();
            });

            document.getElementById('dupBack').addEventListener('click', function () {
                dupModal.hide();
                askBody.classList.remove('d-none');
                stopBody.classList.add('d-none');
            });

            regForm.addEventListener('submit', function (e) {
                if (e.defaultPrevented) return;   // phone / Model Bisnis stopped it first
                if (dupAnswered || checking) return;

                e.preventDefault();
                checking = true;
                btnRegister.disabled = true;
                const restore = btnRegister.innerHTML;
                btnRegister.innerHTML = 'Memeriksa data perusahaan...';

                const body = new FormData();
                body.append('company_name', companyNameEl.value);

                fetch('ajax_check_vendor_duplicate.php', {
                    method: 'POST',
                    body: body,
                    credentials: 'same-origin'
                })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        checking = false;
                        btnRegister.disabled = false;
                        btnRegister.innerHTML = restore;

                        if (!data || !data.ok || data.level === 'none' || !data.matches || !data.matches.length) {
                            submitRegistration();
                            return;
                        }
                        dupAckEl.value = data.ack || '';
                        openDupModal(data.level, data.matches);
                    })
                    .catch(function () {
                        // Fail open — register_action.php re-checks server-side.
                        checking = false;
                        btnRegister.disabled = false;
                        btnRegister.innerHTML = restore;
                        submitRegistration();
                    });
            });

            // Posted past the dialog: show it now, on the rebuilt form. The
            // token is staged here but only reaches the hidden field when the
            // vendor answers "different company", so dismissing this dialog and
            // pressing submit asks again rather than sailing through.
            if (window.__dupBounce && window.__dupBounce.matches && window.__dupBounce.matches.length) {
                dupAckEl.value = window.__dupBounce.ack || '';
                openDupModal(window.__dupBounce.level, window.__dupBounce.matches);
            }
        }


        // =============================================
        // 2. PASSWORD TOGGLE
        // =============================================
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('regPassword');
        const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');
        const confirmPasswordInput = document.getElementById('regConfirmPassword');

        function setupToggle(toggleBtn, inputEl) {
            if(toggleBtn && inputEl) {
                toggleBtn.addEventListener('click', function () {
                    const type = inputEl.getAttribute('type') === 'password' ? 'text' : 'password';
                    inputEl.setAttribute('type', type);
                    this.classList.toggle('fa-eye');
                    this.classList.toggle('fa-eye-slash');
                });
            }
        }
        setupToggle(togglePassword, passwordInput);
        setupToggle(toggleConfirmPassword, confirmPasswordInput);


        // =============================================
        // 3. MODEL BISNIS (collapsed multi-select picker)
        // =============================================
        // The options stay hidden until the vendor opens the picker, and the
        // derived vendor category is never shown here — company_type is worked
        // out server-side in register_action.php and is Sourcing's business, not
        // something the vendor should be steering its answers by.
        const bmSelect     = document.getElementById('bmSelect');
        const bmToggle     = document.getElementById('bmToggle');
        const bmToggleText = document.getElementById('bmToggleText');
        const bmMenu       = document.getElementById('bmMenu');
        const bmDone       = document.getElementById('bmDone');
        const bmBoxes      = Array.from(document.querySelectorAll('input[name="business_model[]"]'));
        const bmError      = document.getElementById('bmError');

        function selectedBusinessModels() {
            return bmBoxes.filter(cb => cb.checked).map(cb => cb.value);
        }

        function setBusinessModelMenu(open) {
            bmMenu.hidden = !open;
            bmToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        function refreshBusinessModelSummary() {
            const picked = bmBoxes.filter(cb => cb.checked);

            if (picked.length > 0) bmError.classList.add('d-none');

            if (picked.length === 0) {
                bmToggleText.textContent = 'Pilih Model Bisnis...';
                bmToggleText.classList.add('is-placeholder');
            } else {
                // One pick reads better as the answer itself; more than one would
                // overflow the control, so summarise.
                bmToggleText.textContent = picked.length === 1
                    ? picked[0].parentElement.querySelector('span').textContent
                    : picked.length + ' model bisnis dipilih';
                bmToggleText.classList.remove('is-placeholder');
            }
        }

        bmToggle.addEventListener('click', function () {
            setBusinessModelMenu(bmMenu.hidden);
        });

        bmDone.addEventListener('click', function () {
            setBusinessModelMenu(false);
            bmToggle.focus();
        });

        bmBoxes.forEach(cb => cb.addEventListener('change', function () {
            refreshBusinessModelSummary();
            validatePassword();
        }));

        // Close on outside click / Escape, like a native dropdown.
        document.addEventListener('click', function (e) {
            if (!bmMenu.hidden && !bmSelect.contains(e.target)) setBusinessModelMenu(false);
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !bmMenu.hidden) {
                setBusinessModelMenu(false);
                bmToggle.focus();
            }
        });


        // =============================================
        // 4. PASSWORD VALIDATION
        // =============================================
        const reqLength = document.getElementById('reqLength');
        const reqUpper = document.getElementById('reqUpper');
        const reqNumber = document.getElementById('reqNumber');
        const reqSymbol = document.getElementById('reqSymbol');
        const pwdError = document.getElementById('passwordError');
        const btnRegister = document.getElementById('btnRegister');

        function validatePassword() {
            const val = passwordInput.value;
            const cVal = confirmPasswordInput.value;
            let isValid = true;

            const setValid = (el) => { el.classList.replace('text-danger', 'text-success'); el.querySelector('i').classList.replace('fa-times-circle', 'fa-check-circle'); };
            const setInvalid = (el) => { el.classList.replace('text-success', 'text-danger'); el.querySelector('i').classList.replace('fa-check-circle', 'fa-times-circle'); isValid = false; };

            if(val.length >= 8) setValid(reqLength); else setInvalid(reqLength);
            if(/[A-Z]/.test(val)) setValid(reqUpper); else setInvalid(reqUpper);
            if(/[0-9]/.test(val)) setValid(reqNumber); else setInvalid(reqNumber);
            if(/[\W_]/.test(val)) setValid(reqSymbol); else setInvalid(reqSymbol);

            if(cVal !== '' && val !== cVal) {
                pwdError.classList.remove('d-none');
                isValid = false;
            } else {
                pwdError.classList.add('d-none');
            }

            if(cVal === '') isValid = false;

            // Also require valid phone
            if (!iti.isValidNumber()) isValid = false;

            // ...and at least one Model Bisnis, since company_type is derived from it
            if (selectedBusinessModels().length === 0) isValid = false;

            btnRegister.disabled = !isValid;
        }

        if(passwordInput && confirmPasswordInput) {
            passwordInput.addEventListener('input', validatePassword);
            confirmPasswordInput.addEventListener('input', validatePassword);
        }

        // Re-validate register button when phone changes too
        phoneInputEl.addEventListener('input', validatePassword);
        phoneInputEl.addEventListener('countrychange', validatePassword);
    });
</script>

<?php
// Tampilkan tombol bantuan vendor (floating) di footer meski pengunjung belum login,
// karena halaman pendaftaran diakses oleh calon vendor.
$show_vendor_help = true;
include 'includes/footer.php';
?>
