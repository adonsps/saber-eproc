<?php
/**
 * includes/staff_edit_confirm_modal.php
 *
 * Confirmation step for the "Koreksi Profil" button on the Sourcing / VM
 * vendor profile pages. Replaces window.confirm(), which renders as a
 * chrome-styled "localhost:8080 says" box, cannot show the vendor's name or
 * status, and gives no room to spell out what the two roles are actually
 * allowed to change.
 *
 * Expects, from the including page (both view_profile.php files set these):
 *   $staff_edit_available  bool   — render nothing when false
 *   $staff_edit_url        string — where "Lanjutkan" navigates
 *   $staff_edit_docs_too   bool   — role may also change documents (see
 *                                   vendor_staff_document_editor_roles())
 *   $vendor                array  — for company_name / registration_status
 *
 * Bootstrap's JS bundle is loaded by includes/footer.php, i.e. AFTER this
 * partial, so the wiring below waits for DOMContentLoaded.
 */
if (empty($staff_edit_available)) {
    return;
}
$sec_company = $vendor['company_name'] ?? 'vendor ini';
$sec_status  = strtoupper(str_replace('_', ' ', $vendor['registration_status'] ?? ''));
?>
<div class="modal fade" id="staffEditConfirmModal" tabindex="-1" aria-labelledby="staffEditConfirmModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header border-0 bg-warning text-dark px-4 py-3">
                <h5 class="modal-title fw-bold" id="staffEditConfirmModalLabel">
                    <i class="fas fa-user-pen me-2"></i>Masuk ke Mode Koreksi Profil
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-3" style="line-height: 1.6;">
                    Anda akan mengoreksi profil
                    <strong class="text-dark"><?= htmlspecialchars($sec_company) ?></strong>
                    atas nama vendor. Profil ini terkunci untuk vendor karena berstatus
                    <span class="badge bg-secondary align-middle"><?= htmlspecialchars($sec_status) ?></span>.
                </p>

                <div class="border rounded-3 p-3 bg-light mb-3">
                    <div class="small fw-bold text-uppercase text-muted mb-2" style="letter-spacing:.04em;">Yang dapat Anda ubah</div>
                    <div class="d-flex align-items-start gap-2 mb-2">
                        <i class="fas fa-circle-check text-success mt-1"></i>
                        <span class="small">Data profil pada tab <strong>Informasi Umum</strong></span>
                    </div>
                    <div class="d-flex align-items-start gap-2">
                        <?php if (!empty($staff_edit_docs_too)): ?>
                            <i class="fas fa-circle-check text-success mt-1"></i>
                            <span class="small">Berkas pada tab <strong>Dokumen Legal</strong></span>
                        <?php else: ?>
                            <i class="fas fa-lock text-secondary mt-1"></i>
                            <span class="small text-muted">Tab <strong>Dokumen Legal</strong> tetap hanya-baca
                            untuk peran Anda.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <p class="small text-muted mb-0">
                    <i class="fas fa-circle-info me-1"></i>
                    Setiap perubahan tercatat atas nama Anda. Anda dapat keluar kapan saja
                    lewat tautan pada banner di atas halaman.
                </p>
            </div>
            <div class="modal-footer border-0 bg-light px-4 py-3 d-flex flex-nowrap gap-3">
                <button type="button" class="btn btn-outline-secondary fw-semibold" data-bs-dismiss="modal">
                    <i class="fas fa-xmark me-1"></i> Batal
                </button>
                <a href="<?= htmlspecialchars($staff_edit_url) ?>" id="staffEditConfirmGo" class="btn btn-warning fw-bold shadow-sm">
                    <i class="fas fa-arrow-right me-1"></i> Lanjutkan
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Once "Lanjutkan" is pressed the browser navigates into the impersonation
// entry point; that takes a moment, and a second click would fire a second
// request. Disable the link and show it is working, exactly like the rest of
// the app's confirm modals do.
document.addEventListener('DOMContentLoaded', function () {
    var go = document.getElementById('staffEditConfirmGo');
    if (!go) return;
    go.addEventListener('click', function () {
        go.classList.add('disabled');
        go.setAttribute('aria-disabled', 'true');
        go.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Membuka…';
    });
});
</script>
