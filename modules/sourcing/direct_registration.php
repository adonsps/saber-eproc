<?php
// modules/sourcing/direct_registration.php
session_start();
$path_to_root = "../../";
require $path_to_root . 'config.php';
require_once $path_to_root . 'includes/form_draft.php';

// Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    header("Location: " . $path_to_root . "login.php");
    exit();
}

// "Buang draf": drop the recovered entry and start from a blank form. The
// ?draft_discarded=1 on the way back also tells the localStorage autosave at the
// bottom of this page to drop its copy — otherwise it restores it right back.
if (isset($_GET['discard_draft'])) {
    form_draft_clear('direct_registration');
    header("Location: direct_registration.php?draft_discarded=1");
    exit();
}

// Draft recovery: when the previous attempt was rejected (duplicate email, bad
// phone, ...), re-render what Sourcing typed instead of an empty form.
$draft = form_draft_get('direct_registration', (int) $_SESSION['user_id']);
$val = function (string $field) use ($draft) {
    return htmlspecialchars((string) ($draft[$field] ?? ''));
};
$sel = function (string $field, string $option) use ($draft) {
    return ($draft[$field] ?? '') === $option ? 'selected' : '';
};

$page_title = "Pendaftaran Langsung | VSS";
include $path_to_root . 'includes/header.php';
?>

<!-- intl-tel-input Library -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/css/intlTelInput.css">
<style>
    .iti { width: 100%; }
    .phone-status { display: inline-flex; align-items: center; gap: 4px; font-size: 0.75rem; font-weight: 600; margin-top: 6px; }
    .phone-status.valid { color: #198754; }
    .phone-status.invalid { color: #dc3545; }
    .phone-status.empty { color: #6c757d; }
</style>

<nav class="navbar navbar-vss mb-4">
    <div class="container-fluid">
        <span class="navbar-brand fw-bold d-flex align-items-center gap-2"><?= vss_brand_logo() ?>Pendaftaran Langsung</span>
        <a href="dashboard.php" class="btn btn-sm btn-nav rounded-pill px-3"><i class="fas fa-arrow-left me-1"></i> Dashboard</a>
    </div>
</nav>

<div class="container pb-5" style="max-width: 800px;">

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i> <?= htmlspecialchars($_GET['error'] ?? '') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($draft)): ?>
        <div class="alert alert-primary border-0 shadow-sm rounded-4 d-flex align-items-start gap-2" role="alert">
            <i class="fas fa-rotate-left mt-1"></i>
            <div class="flex-grow-1">
                <strong>Data yang Anda isi sebelumnya sudah dipulihkan.</strong>
                Perbaiki bagian yang bermasalah, lalu kirim ulang formulir ini.
            </div>
            <a href="direct_registration.php?discard_draft=1" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                <i class="fas fa-xmark me-1"></i>Buang draf
            </a>
        </div>
    <?php endif; ?>

    <div class="alert alert-info border-0 shadow-sm rounded-4">
        <i class="fas fa-info-circle me-2"></i>
        Fitur ini membuat akun vendor dan menyerahkan kendalinya kepada Anda (seolah-olah Anda masuk sebagai
        vendor tersebut) sehingga Anda dapat melengkapi dokumen profil dan Formulir Pra-Kualifikasi atas nama
        mereka. Saat Anda mengirim formulir PQ final, pengajuan akan langsung masuk ke antrean tinjauan Tim VM —
        tahap tinjauan Sourcing dilewati karena Anda sendiri yang mengisi datanya.
    </div>

    <?php
    // Pendaftaran langsung yang belum selesai oleh sourcing ini dan belum
    // sampai ke VM — akun vendor tidak punya kata sandi yang bisa dipakai,
    // jadi tautan "Lanjutkan" ini adalah satu-satunya jalan kembali jika
    // pengisian ditinggalkan di tengah jalan.
    // Anything before the PQ is actually submitted to VM — profile_action.php
    // promotes registration_status through several of these (e.g.
    // registration_submitted -> invited_pq once docs are complete) while the
    // vendor still hasn't reached submit_pq.php, so all of them must stay
    // resumable, not just the very first one.
    // 'returned_to_sourcing' included so vendors VM sends back land here too
    // (Direct Registration only ever routes "return" to Sourcing — see the
    // approve_pq.php / approve_action.php guard against 'return_to_vendor').
    $resumable_statuses = ['registration_submitted', 'registered', 'min_info_submitted', 'invited_pq', 'draft', 'revision_requested', 'returned_to_sourcing'];
    $placeholders = implode(',', array_fill(0, count($resumable_statuses), '?'));
    $types = str_repeat('s', count($resumable_statuses)) . 'i';
    $params = array_merge($resumable_statuses, [$_SESSION['user_id']]);

    $stmt = $conn->prepare("SELECT id, company_name, contact_person, registration_status, created_at
                             FROM vendors
                             WHERE registration_status IN ($placeholders) AND registered_by_sourcing_id = ?
                             ORDER BY created_at DESC");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $in_progress = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $status_labels = [
        'registration_submitted' => 'Perlu Lengkapi Dokumen',
        'registered'             => 'Perlu Lengkapi Dokumen',
        'min_info_submitted'     => 'Perlu Lengkapi Dokumen',
        'invited_pq'             => 'Perlu Isi Formulir PQ',
        'draft'                  => 'Draft PQ Tersimpan',
        'revision_requested'     => 'Butuh Revisi',
        'returned_to_sourcing'   => 'Dikembalikan oleh VM',
    ];
    ?>

    <?php if (!empty($in_progress)): ?>
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-header bg-white fw-bold text-dark border-bottom py-3">
            <i class="fas fa-hourglass-half me-2 text-warning"></i> Pendaftaran Langsung Anda yang Belum Selesai
        </div>
        <div class="list-group list-group-flush">
            <?php foreach ($in_progress as $row): ?>
            <a href="direct_registration_resume.php?vendor_id=<?= (int)$row['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                <div>
                    <div class="fw-bold"><?= htmlspecialchars($row['company_name'] ?? '') ?></div>
                    <div class="small text-muted"><?= htmlspecialchars($row['contact_person'] ?? '') ?> &bull; dimulai <?= date('d M Y', strtotime($row['created_at'])) ?></div>
                </div>
                <span class="badge bg-secondary rounded-pill"><?= htmlspecialchars($status_labels[$row['registration_status']] ?? $row['registration_status']) ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm rounded-4">
        <div class="card-body p-4">
            <form action="direct_registration_action.php" method="POST" id="directRegForm">

                <h5 class="fw-bold text-primary mb-3">Informasi Perusahaan</h5>
                <div class="mb-3">
                    <label class="form-label fw-bold small">Nama Perusahaan (PT/CV) <span class="text-danger">*</span></label>
                    <input type="text" name="company_name" class="form-control" value="<?= $val('company_name') ?>" required>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-bold small">Model Bisnis <span class="text-danger">*</span></label>
                        <select name="company_type" id="company_type" class="form-select" required>
                            <option value="" disabled <?= empty($draft['company_type']) ? 'selected' : '' ?>>Pilih tipe...</option>
                            <option value="Supplier" <?= $sel('company_type', 'Supplier') ?>>Pemasok (Prinsipal / Distributor / Retail)</option>
                            <option value="Contractor" <?= $sel('company_type', 'Contractor') ?>>Kontraktor (Proyek / Pemeliharaan / Perorangan)</option>
                            <option value="Perseorangan" <?= $sel('company_type', 'Perseorangan') ?>>Perseorangan (UMKM)</option>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-bold small">Narahubung <span class="text-danger">*</span></label>
                        <input type="text" name="contact_person" class="form-control" value="<?= $val('contact_person') ?>" required>
                    </div>
                </div>

                <div class="row g-3 mt-1">
                    <div class="col-sm-6">
                        <label class="form-label fw-bold small">Email <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" value="<?= $val('email') ?>" required>
                        <div class="form-text small">Akan digunakan sebagai ID Masuk vendor.</div>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-bold small">Nomor Telepon <span class="text-danger">*</span></label>
                        <input type="tel" id="phoneInput" class="form-control" placeholder="812 3456 7890" required autocomplete="off">
                        <input type="hidden" name="phone" id="phoneHidden" value="<?= $val('phone') ?>">
                        <div id="phoneStatus" class="phone-status empty">
                            <i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>
                        </div>
                    </div>
                </div>

                <h5 class="fw-bold text-primary mb-3 mt-4">Klasifikasi</h5>
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-bold small">Jenis Badan Hukum <span class="text-danger">*</span></label>
                        <select name="legal_entity_type" id="legal_entity_type" class="form-select" required>
                            <option value="" disabled <?= empty($draft['legal_entity_type']) ? 'selected' : '' ?>>Pilih...</option>
                            <option value="Badan Usaha" <?= $sel('legal_entity_type', 'Badan Usaha') ?>>Badan Usaha</option>
                            <option value="Perseorangan" <?= $sel('legal_entity_type', 'Perseorangan') ?>>Perseorangan</option>
                        </select>
                    </div>
                </div>
                <div class="alert alert-light border small mt-2">
                    <i class="fas fa-info-circle me-1 text-muted"></i>
                    Sub-Kategori Vendor akan ditentukan nanti, setelah Formulir Pra-Kualifikasi diisi — sama seperti tinjauan Sourcing pada alur normal, klasifikasi ini didasarkan pada jawaban PQ, bukan ditentukan di awal.
                </div>

                <div class="d-grid mt-4">
                    <button type="submit" class="btn btn-primary fw-bold rounded-pill py-2">
                        <i class="fas fa-arrow-right me-1"></i> Buat Akun &amp; Lanjutkan sebagai Vendor
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- intl-tel-input JS (loaded before our script) -->
<script src="https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/intlTelInput.min.js"></script>

<script>
    // =============================================
    // INTL-TEL-INPUT INITIALIZATION (same pattern as register.php)
    // =============================================
    const phoneInputEl = document.getElementById('phoneInput');
    const phoneHidden = document.getElementById('phoneHidden');
    const phoneStatus = document.getElementById('phoneStatus');

    const iti = window.intlTelInput(phoneInputEl, {
        initialCountry: "id",
        preferredCountries: ["id", "sg", "my", "th", "ph", "vn", "jp", "cn", "kr", "au", "sa", "ae"],
        separateDialCode: true,
        nationalMode: false,
        formatAsYouType: true,
        formatOnDisplay: true,
        strictMode: true,
        validationNumberType: "MOBILE",
        countrySearch: true,
        containerClass: "w-100",
        loadUtilsOnInit: "https://cdn.jsdelivr.net/npm/intl-tel-input@24.6.0/build/js/utils.js"
    });

    // A recovered number lives in the hidden field; push it through intl-tel-input
    // so the visible box shows it too, otherwise the first sync wipes it.
    if (phoneHidden.value) {
        iti.setNumber(/^\d+$/.test(phoneHidden.value) ? '+' + phoneHidden.value : phoneHidden.value);
    }

    function syncPhone() {
        const fullNumber = iti.getNumber();
        phoneHidden.value = fullNumber;

        const inputVal = phoneInputEl.value.replace(/\s/g, '');
        if (!inputVal || inputVal.length < 4) {
            phoneStatus.className = 'phone-status empty';
            phoneStatus.innerHTML = '<i class="fas fa-info-circle"></i> <span>Pilih kode negara, lalu masukkan nomor.</span>';
            return false;
        }

        if (iti.isValidNumber()) {
            const countryName = iti.getSelectedCountryData().name || '';
            phoneStatus.className = 'phone-status valid';
            phoneStatus.innerHTML = '<i class="fas fa-check-circle"></i> <span>Nomor valid — ' + countryName + ' (' + fullNumber + ')</span>';
            return true;
        } else {
            phoneStatus.className = 'phone-status invalid';
            phoneStatus.innerHTML = '<i class="fas fa-times-circle"></i> <span>Nomor tidak valid</span>';
            return false;
        }
    }

    phoneInputEl.addEventListener('input', syncPhone);
    phoneInputEl.addEventListener('countrychange', syncPhone);

    document.getElementById('directRegForm').addEventListener('submit', function (e) {
        syncPhone();
        if (!iti.isValidNumber()) {
            e.preventDefault();
            phoneInputEl.focus();
            phoneStatus.className = 'phone-status invalid';
            phoneStatus.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span>Harap masukkan nomor telepon yang valid sebelum melanjutkan.</span>';
        }
    });

    // ── AUTOSAVE DRAF LOKAL ────────────────────────────────────────────────
    // The session draft above covers a REJECTED submit. This covers the other way
    // the entry gets lost: refreshing or closing the tab before submitting at all.
    (function () {
        const form = document.getElementById('directRegForm');
        if (!form || !window.localStorage) return;

        const STORAGE_KEY = 'direct_registration_draft:<?= (int) $_SESSION['user_id'] ?>';
        const MAX_AGE_MS  = 3 * 24 * 60 * 60 * 1000;

        function fields() {
            return Array.from(form.querySelectorAll('input[name], select[name]'));
        }

        function clearDraft() {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
        }

        // On submit the entry is either about to be created (draft spent) or about
        // to bounce back, where the SERVER draft repopulates the form. Either way
        // the local copy is done — and keeping it would pre-fill the next vendor.
        form.addEventListener('submit', function (e) {
            if (!e.defaultPrevented) clearDraft();
        });

        if (new URLSearchParams(window.location.search).has('draft_discarded')) clearDraft();

        let stored = null;
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) stored = JSON.parse(raw);
        } catch (e) { stored = null; }

        if (stored && (Date.now() - (stored.savedAt || 0)) > MAX_AGE_MS) {
            clearDraft();
            stored = null;
        }

        if (stored && stored.values) {
            let restored = false;
            fields().forEach(el => {
                const value = stored.values[el.name];
                if (value === undefined || value === '' || el.value === value) return;
                el.value = value;
                restored = true;
                if (el.id === 'phoneHidden') {
                    iti.setNumber(/^\d+$/.test(value) ? '+' + value : value);
                    syncPhone();
                }
            });

            if (restored && !document.querySelector('.alert-primary')) {
                const notice = document.createElement('div');
                notice.className = 'alert alert-primary border-0 shadow-sm rounded-4 d-flex align-items-start gap-2';
                notice.innerHTML =
                    '<i class="fas fa-clock-rotate-left mt-1"></i>' +
                    '<div class="flex-grow-1"><strong>Isian Anda yang belum sempat dikirim telah dipulihkan.</strong> ' +
                    'Periksa kembali datanya sebelum melanjutkan.</div>';

                const discard = document.createElement('button');
                discard.type = 'button';
                discard.className = 'btn btn-sm btn-outline-secondary flex-shrink-0';
                discard.innerHTML = '<i class="fas fa-xmark me-1"></i>Buang draf';
                discard.addEventListener('click', function () {
                    clearDraft();
                    window.location.href = 'direct_registration.php?discard_draft=1';
                });
                notice.appendChild(discard);
                form.closest('.container').insertBefore(notice, form.closest('.card'));
            }
        }

        let saveTimer = null;
        function persist() {
            const values = {};
            fields().forEach(el => { values[el.name] = el.value; });
            try {
                localStorage.setItem(STORAGE_KEY, JSON.stringify({ savedAt: Date.now(), values: values }));
            } catch (e) { /* quota / private mode — autosave is best-effort */ }
        }

        form.addEventListener('input', function () {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(persist, 400);
        });
        form.addEventListener('change', function () {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(persist, 400);
        });
        window.addEventListener('pagehide', function () {
            clearTimeout(saveTimer);
            // Never overwrite a draft that was just cleared on submit.
            if (localStorage.getItem(STORAGE_KEY) !== null) persist();
        });
    })();
</script>

<?php include $path_to_root . 'includes/footer.php'; ?>
