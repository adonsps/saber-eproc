<?php require_once __DIR__ . '/auth_intended.php'; ?>
</main> <footer>
        <div class="container">
            &copy; <?= date('Y'); ?> Saber eProc &bull; Enterprise Procurement Platform <br>
            <small>Saber Enterprise Systems</small>
        </div>
    </footer>
    
    <!--default_language = IND-->
    <!--<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>-->
    
    <!--<div class="gtranslate_wrapper"></div>-->
    <!--<script>-->
        <!--// 1. Force the page to auto-translate from English (en) to Indonesian (id)-->
    <!--    document.cookie = "googtrans=/en/id; path=/";-->
    <!--    document.cookie = "googtrans=/en/id; domain=." + document.domain + "; path=/";-->
    
        <!--// 2. Load your standard GTranslate settings-->
    <!--    window.gtranslateSettings = {-->
    <!--        "default_language":"en",-->
    <!--        "native_language_names":true,-->
    <!--        "languages":["en","id","zh-CN"],-->
    <!--        "wrapper_selector":".gtranslate_wrapper",-->
    <!--        "flag_style":"3d"-->
    <!--    };-->
    <!--</script>-->
    <!--<script src="https://cdn.gtranslate.net/widgets/latest/float.js" defer></script>-->
    
    <!--default_language = EN-->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <div class="gtranslate_wrapper"></div>
    <script>window.gtranslateSettings = {"default_language":"en","native_language_names":true,"languages":["en","id","zh-CN","ja","ar","jw","su"],"wrapper_selector":".gtranslate_wrapper","flag_style":"3d"}</script>
    <script src="https://cdn.gtranslate.net/widgets/latest/float.js" defer></script>
    <!--<script>window.gtranslateSettings = {"default_language":"en","native_language_names":true,"languages":["en","fr","es","id","ja","ar","zh-CN","de","jw","ru","su"],"wrapper_selector":".gtranslate_wrapper","flag_style":"3d"}</script>-->
    <!--<script src="https://cdn.gtranslate.net/widgets/latest/float.js" defer></script>-->
    <!-- Global Logout Confirmation Modal -->
<div class="modal fade" id="globalLogoutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg rounded-4 overflow-hidden">
            <div class="modal-header bg-danger text-white border-0 py-3">
                <h5 class="modal-title fw-bold text-white"><i class="fas fa-sign-out-alt me-2"></i> Confirm Logout</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4 text-center">
                <div class="mb-4">
                    <i class="fas fa-door-open fa-4x text-danger opacity-50"></i>
                </div>
                <h4 class="fw-bold text-dark mb-3">Leaving so soon?</h4>
                <p class="text-muted mb-0">Are you sure you want to end your current session and log out of Saber eProc?</p>
            </div>
            <div class="modal-footer bg-light border-0 d-flex justify-content-center py-3">
                <button type="button" class="btn btn-light border fw-bold px-4 rounded-3" data-bs-dismiss="modal">Cancel</button>
                <a href="#" id="confirmLogoutBtn" class="btn btn-danger fw-bold px-4 rounded-3 shadow-sm">Yes, Log Me Out</a>
            </div>
        </div>
    </div>
</div>

<!-- Global Javascript Overrides -->
<!-- Pop-Up Exit Confirmation -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    
    // 1. Intercept all links pointing to logout.php
    const logoutLinks = document.querySelectorAll('a[href*="logout.php"]');
    
    if (logoutLinks.length > 0) {
        // Initialize the Bootstrap Modal
        const logoutModalEl = document.getElementById('globalLogoutModal');
        const logoutModal = new bootstrap.Modal(logoutModalEl);
        const confirmBtn = document.getElementById('confirmLogoutBtn');

        logoutLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault(); // Stop the link from redirecting immediately
                
                // Get the exact logout URL (handles relative paths like ../../logout.php)
                const logoutUrl = this.getAttribute('href'); 
                
                // Attach the URL to our "Yes, Log Me Out" button
                confirmBtn.setAttribute('href', logoutUrl); 
                
                // Show the beautifully styled modal
                logoutModal.show(); 
            });
        });
    }

    // =============================================
    // SESSION INACTIVITY TIMER (30 minutes)
    // =============================================
    const SESSION_TIMEOUT = <?= VSS_IDLE_LIMIT_SECONDS ?>;
    const WARNING_AT = 60; // Show warning with 60 seconds left
    let inactivityTimer = SESSION_TIMEOUT;
    let warningShown = false;

    // Only run on logged-in pages (check if logout link exists)
    if (document.querySelector('a[href*="logout.php"]')) {

        // Find the correct logout URL from any logout link on the page
        const anyLogoutLink = document.querySelector('a[href*="logout.php"]');
        const logoutBaseUrl = anyLogoutLink ? anyLogoutLink.getAttribute('href').replace('logout.php', '') : '';

        // Countdown ticker
        const ticker = setInterval(() => {
            inactivityTimer--;

            // Show warning at 60 seconds remaining
            if (inactivityTimer <= WARNING_AT && !warningShown) {
                warningShown = true;
                showTimeoutWarning(logoutBaseUrl);
            }

            // Update countdown display
            if (warningShown && inactivityTimer > 0) {
                const countdownEl = document.getElementById('timeoutCountdown');
                if (countdownEl) countdownEl.textContent = inactivityTimer;
            }

            // Auto-logout at 0
            if (inactivityTimer <= 0) {
                clearInterval(ticker);
                // Path + query saja agar server melihatnya sebagai path same-site.
                window.location.href = logoutBaseUrl + 'logout.php?reason=timeout&redirect='
                    + encodeURIComponent(window.location.pathname + window.location.search);
            }
        }, 1000);

        // Reset timer on any user activity
        const resetTimer = () => {
            inactivityTimer = SESSION_TIMEOUT;
            if (warningShown) {
                warningShown = false;
                const modal = bootstrap.Modal.getInstance(document.getElementById('sessionTimeoutModal'));
                if (modal) modal.hide();
            }
        };

        ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(evt => {
            document.addEventListener(evt, resetTimer, { passive: true });
        });
    }

    function showTimeoutWarning(logoutBaseUrl) {
        // Remove existing modal if any
        const existing = document.getElementById('sessionTimeoutModal');
        if (existing) existing.remove();

        const modalHTML = `
        <div class="modal fade" id="sessionTimeoutModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg overflow-hidden" style="border-radius: 20px;">
                    <div class="position-relative overflow-hidden" style="background: linear-gradient(135deg, #fd7e14, #dc3545); padding: 1.5rem 1.5rem 2.2rem;">
                        <div class="position-absolute" style="top:-30px;right:-30px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,0.08);"></div>
                        <div class="position-absolute" style="bottom:-15px;left:30px;width:60px;height:60px;border-radius:50%;background:rgba(255,255,255,0.05);"></div>
                        <div class="position-relative d-flex align-items-center gap-3" style="z-index:1;">
                            <div style="width:46px;height:46px;background:rgba(255,255,255,0.2);border-radius:12px;backdrop-filter:blur(10px);display:flex;align-items:center;justify-content:center;">
                                <i class="fas fa-hourglass-half text-white fs-4" style="animation: fa-spin 2s linear infinite;"></i>
                            </div>
                            <div>
                                <h5 class="fw-bold text-white mb-1" style="font-size:1rem;">Sesi Akan Berakhir</h5>
                                <div class="text-white small" style="opacity:0.85;">Tidak ada aktivitas terdeteksi</div>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top:-1rem;padding:0 1.5rem;position:relative;z-index:2;">
                        <div class="bg-white rounded-4 shadow-sm p-3 text-center" style="border:1px solid rgba(0,0,0,0.06);">
                            <div style="font-size:2.5rem;font-weight:800;color:#dc3545;line-height:1;" id="timeoutCountdown">${WARNING_AT}</div>
                            <div style="font-size:0.75rem;color:#6c757d;margin-top:4px;">detik tersisa</div>
                        </div>
                    </div>
                    <div class="modal-body px-4 pt-3 pb-2 text-center">
                        <p style="font-size:0.88rem;color:#495057;line-height:1.6;">
                            Anda akan otomatis <strong>keluar dari sistem</strong> karena tidak ada aktivitas.
                        </p>
                        <p style="font-size:0.8rem;color:#6c757d;">
                            Klik tombol di bawah atau gerakkan mouse untuk tetap masuk.
                        </p>
                    </div>
                    <div class="modal-footer border-0 px-4 pb-4 pt-0 d-flex gap-2">
                        <button type="button" class="btn btn-primary fw-bold rounded-pill flex-fill py-2" data-bs-dismiss="modal" style="font-size:0.88rem;">
                            <i class="fas fa-hand-pointer me-1"></i> Saya Masih di Sini
                        </button>
                        <a href="${logoutBaseUrl}logout.php" class="btn btn-outline-secondary fw-bold rounded-pill flex-fill py-2" style="font-size:0.88rem;">
                            <i class="fas fa-sign-out-alt me-1"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>`;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        new bootstrap.Modal(document.getElementById('sessionTimeoutModal')).show();

        // Dismiss modal = reset timer
        document.getElementById('sessionTimeoutModal').addEventListener('hidden.bs.modal', function() {
            inactivityTimer = SESSION_TIMEOUT;
            warningShown = false;
        });
    }
});
</script>

<!-- Floating Help Button (sourcing only) -->
<?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'sourcing'): ?>
<a href="https://docs.google.com/document/d/12TPnB7kzAjXmX9XtJdjrlyNn6m8UU1CpJu8yyE_ljzw/preview" target="_blank" rel="noopener" title="Bantuan" style="
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 9999;
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: linear-gradient(135deg, #673DE6, #4a1fb8);
    box-shadow: 0 4px 18px rgba(103,61,230,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.2s, box-shadow 0.2s;
" onmouseenter="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 24px rgba(103,61,230,0.6)'"
   onmouseleave="this.style.transform='scale(1)';this.style.boxShadow='0 4px 18px rgba(103,61,230,0.45)'">
    <i class="fas fa-question" style="color:#fff;font-size:22px;"></i>
    <span style="
        position: absolute;
        top: -6px;
        right: -6px;
        background: #fff;
        color: #673DE6;
        font-size: 9px;
        font-weight: 700;
        border-radius: 20px;
        padding: 2px 6px;
        box-shadow: 0 1px 6px rgba(0,0,0,0.15);
        letter-spacing: 0.3px;
        white-space: nowrap;
    ">Bantuan</span>
</a>
<?php endif; ?>

<!-- Floating Help Button (vendor, atau halaman publik vendor seperti register) -->
<?php if ((isset($_SESSION['role']) && $_SESSION['role'] === 'vendor') || !empty($show_vendor_help)): ?>
<a href="https://docs.google.com/document/d/1LnKeaEBQ4y5OB-NBhAoGKdniWLhIkZxX8oKr8vO0wAQ/preview" target="_blank" rel="noopener" title="Bantuan" style="
    position: fixed;
    bottom: 28px;
    right: 28px;
    z-index: 9999;
    width: 54px;
    height: 54px;
    border-radius: 50%;
    background: linear-gradient(135deg, #673DE6, #4a1fb8);
    box-shadow: 0 4px 18px rgba(103,61,230,0.45);
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    text-decoration: none;
    transition: transform 0.2s, box-shadow 0.2s;
" onmouseenter="this.style.transform='scale(1.1)';this.style.boxShadow='0 6px 24px rgba(103,61,230,0.6)'"
   onmouseleave="this.style.transform='scale(1)';this.style.boxShadow='0 4px 18px rgba(103,61,230,0.45)'">
    <i class="fas fa-question" style="color:#fff;font-size:22px;"></i>
    <span style="
        position: absolute;
        top: -6px;
        right: -6px;
        background: #fff;
        color: #673DE6;
        font-size: 9px;
        font-weight: 700;
        border-radius: 20px;
        padding: 2px 6px;
        box-shadow: 0 1px 6px rgba(0,0,0,0.15);
        letter-spacing: 0.3px;
        white-space: nowrap;
    ">Bantuan</span>
</a>
<?php endif; ?>

<!-- Close the main container and body tags usually found in the header -->
    <!-- Bootstrap bundle is already loaded above (see line ~29); duplicate load re-binds
         data-api click handlers and breaks toggles like the navbar collapse, so it stays removed. -->

</body>
</html>