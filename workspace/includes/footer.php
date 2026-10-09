<?php require_once __DIR__ . '/../../includes/branding.php'; ?>
</div> </main> <footer class="bg-slate-50 p-4 text-center text-xs text-slate-500 border-t border-slate-200">
            &copy; <?php echo date("Y"); ?> Saber eProc &bull; Enterprise Procurement Platform. All rights reserved.
        </footer>
        
    </div> <div id="mobileSidebar" class="fixed inset-0 bg-slate-900 bg-opacity-50 z-40 hidden md:hidden">
        <div class="fixed inset-y-0 left-0 w-64 bg-white shadow-xl flex flex-col transform -translate-x-full transition-transform duration-300" id="mobileSidebarContent">
            <div class="h-16 flex items-center justify-between px-4 border-b border-slate-100 flex-shrink-0">
                <span class="flex items-center text-base font-bold text-slate-900">
                    <?= vss_brand_logo(28, 'mr-2') ?>
                    Saber <span class="text-blue-700">&nbsp;eProc</span>
                </span>
                <button id="closeMobileMenuBtn" class="text-slate-400 hover:text-red-500 focus:outline-none p-1">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <nav id="mobileNav" class="flex-1 overflow-y-auto py-4 px-3 space-y-4"></nav>
        </div>
    </div>

</div> <script>
    $(document).ready(function() {
        // Clone Desktop links to Mobile Sidebar perfectly
        $('#desktopSidebar nav').children().clone().appendTo('#mobileNav');

        const mobileOverlay = $('#mobileSidebar');
        const mobileContent = $('#mobileSidebarContent');
        const desktopSidebar = $('#desktopSidebar');

        // Main Hamburger Button Logic
        $('#mainMenuBtn').click(function() {
            if (window.innerWidth >= 768) {
                // DESKTOP: Toggle classes to shrink width to 0 and remove border
                desktopSidebar.toggleClass('w-64 w-0 border-r');
            } else {
                // MOBILE: Show overlay and slide in
                mobileOverlay.removeClass('hidden');
                setTimeout(() => { mobileContent.removeClass('-translate-x-full'); }, 10);
            }
        });

        // Close Mobile Menu Logic
        $('#closeMobileMenuBtn, #mobileSidebar').click(function(e) {
            if (e.target === this || e.target.id === 'closeMobileMenuBtn' || $(e.target).closest('#closeMobileMenuBtn').length) {
                mobileContent.addClass('-translate-x-full');
                setTimeout(() => { mobileOverlay.addClass('hidden'); }, 300);
            }
        });
        
        // Handle window resize gracefully
        $(window).resize(function() {
            if (window.innerWidth >= 768 && !mobileOverlay.hasClass('hidden')) {
                // Force close mobile menu if user rotates/resizes to desktop size
                mobileContent.addClass('-translate-x-full');
                mobileOverlay.addClass('hidden');
            }
        });
    });

    // =============================================
    // SESSION INACTIVITY TIMER
    // =============================================
    // Sumber angkanya adalah konstanta WS_IDLE_LIMIT_* di includes/auth.php, yang
    // juga menegakkan batas ini di server (ws_enforce_idle_timeout()). Timer di
    // sini hanyalah lapisan ramah: peringatan 60 detik lalu logout bersih. Kalau
    // JS mati atau tab ditutup, server tetap yang memutuskan.
    // Viewer (read-only) memakai batas panjang karena akun itu memang ditujukan
    // untuk dibiarkan terbuka menampilkan dashboard.
    const SESSION_TIMEOUT = <?= (function_exists('ws_idle_limit') ? ws_idle_limit() : 1800) ?>;
    const WARNING_AT = 60; // Show warning with 60 seconds left
    let inactivityTimer = SESSION_TIMEOUT;
    let warningShown = false;

    // Path + query saja (bukan href lengkap) supaya server melihatnya same-site.
    const wpIntendedPath = window.location.pathname + window.location.search;

    // Sesi habis di tengah XHR: require_login() membalas 401 JSON. 403 dibiarkan
    // lewat — itu penolakan role, bukan sesi habis.
    if (window.jQuery) {
        jQuery(document).ajaxError(function (event, xhr) {
            if (xhr && xhr.status === 401) {
                window.location.href = 'login.php?redirect=' + encodeURIComponent(wpIntendedPath);
            }
        });
    }

    const logoutLink = document.querySelector('a[href*="logout.php"]');
    if (logoutLink) {
        const logoutBaseUrl = logoutLink.getAttribute('href').replace('logout.php', '');

        const ticker = setInterval(function() {
            inactivityTimer--;

            if (inactivityTimer <= WARNING_AT && !warningShown) {
                warningShown = true;
                showTimeoutWarning(logoutBaseUrl);
            }

            if (warningShown && inactivityTimer > 0) {
                const countdownEl = document.getElementById('wpTimeoutCountdown');
                if (countdownEl) countdownEl.textContent = inactivityTimer;
            }

            if (inactivityTimer <= 0) {
                clearInterval(ticker);
                window.location.href = logoutBaseUrl + 'logout.php?reason=timeout&redirect='
                    + encodeURIComponent(wpIntendedPath);
            }
        }, 1000);

        const resetTimer = function() {
            inactivityTimer = SESSION_TIMEOUT;
            if (warningShown) {
                warningShown = false;
                hideTimeoutWarning();
            }
        };

        ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'].forEach(function(evt) {
            document.addEventListener(evt, resetTimer, { passive: true });
        });
    }

    function showTimeoutWarning(logoutBaseUrl) {
        const existing = document.getElementById('wpTimeoutModal');
        if (existing) existing.remove();

        const modalHTML = `
        <div id="wpTimeoutModal" class="fixed inset-0 z-[9999] flex items-center justify-center bg-slate-900 bg-opacity-50">
            <div class="bg-white rounded-xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden">
                <div class="px-6 pt-6 pb-4 text-center">
                    <div class="mx-auto mb-3 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
                        <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <h3 class="text-lg font-bold text-slate-800">Sesi Akan Berakhir</h3>
                    <p class="text-sm text-slate-500 mt-1">Tidak ada aktivitas terdeteksi.</p>
                    <div class="mt-4 text-3xl font-extrabold text-red-500" id="wpTimeoutCountdown">${WARNING_AT}</div>
                    <p class="text-xs text-slate-400 mt-1">detik tersisa</p>
                </div>
                <div class="flex gap-2 px-6 pb-6">
                    <button type="button" id="wpStaySignedInBtn" class="flex-1 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2">
                        Saya Masih di Sini
                    </button>
                    <a href="${logoutBaseUrl}logout.php" class="flex-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-bold py-2 text-center">
                        Logout
                    </a>
                </div>
            </div>
        </div>`;

        document.body.insertAdjacentHTML('beforeend', modalHTML);
        document.getElementById('wpStaySignedInBtn').addEventListener('click', function() {
            inactivityTimer = SESSION_TIMEOUT;
            warningShown = false;
            hideTimeoutWarning();
        });
    }

    function hideTimeoutWarning() {
        const modal = document.getElementById('wpTimeoutModal');
        if (modal) modal.remove();
    }
</script>

</body>
</html>