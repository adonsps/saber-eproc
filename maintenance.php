<?php

session_start();
$path_to_root = "./";
require 'config.php';

// Internal users run the portal rather than wait on it, so bounce them to their
// own dashboard. Everyone else belongs on this page — vendors AND anonymous
// visitors. The anonymous case matters: register/verify/reset write to
// `users`/`vendors` before a vendor session exists, so they are exactly the
// traffic maintenance mode has to hold off.
$role = $_SESSION['role'] ?? '';
if (in_array($role, vss_maintenance_internal_roles(), true)) {
    $role_destinations = [
        'sourcing' => 'modules/sourcing/dashboard.php',
        'vendor_mgt' => 'modules/vendormgt/dashboard.php',
        'vendormgt_head' => 'modules/vendormgt/dashboard.php',
        'admin' => 'modules/admin/dashboard.php',
    ];

    $destination = $role_destinations[$role] ?? 'login.php';
    header('Location: ' . $path_to_root . $destination);
    exit;
}

// If maintenance is no longer active, send the visitor back where they belong.
if (!vss_maintenance_state($conn)['active']) {
    $back = ($role === 'vendor') ? 'modules/vendor/dashboard.php' : 'login.php';
    header('Location: ' . $path_to_root . $back);
    exit;
}

http_response_code(503);
header('Retry-After: 3600');

$page_title = "Under Maintenance | VMS Portal";
include 'includes/header.php';
?>

<style>
    body {
        background:
            linear-gradient(135deg, rgba(0, 96, 156, 0.08), rgba(244, 162, 97, 0.08)),
            #f4f7f6;
    }

    .maintenance-wrapper {
        min-height: calc(100vh - 160px);
        display: flex;
        align-items: center;
        padding: 3rem 0;
    }

    .maintenance-panel {
        background: #ffffff;
        border-radius: 24px;
        box-shadow: 0 24px 60px rgba(38, 70, 83, 0.12);
        overflow: hidden;
    }

    .maintenance-visual {
        min-height: 100%;
        background: linear-gradient(145deg, #0F1E36 0%, #1e3a8a 55%, #1e40af 100%);
        color: #ffffff;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem;
    }

    .maintenance-visual::before,
    .maintenance-visual::after {
        content: "";
        position: absolute;
        border-radius: 50%;
        background: rgba(0, 144, 255, 0.15);
    }

    .maintenance-visual::before {
        width: 220px;
        height: 220px;
        top: -80px;
        left: -70px;
    }

    .maintenance-visual::after {
        width: 160px;
        height: 160px;
        right: -45px;
        bottom: -45px;
    }

    .maintenance-icon {
        width: 132px;
        height: 132px;
        border-radius: 32px;
        background: rgba(255, 255, 255, 0.12);
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        z-index: 1;
        box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18);
    }

    .maintenance-icon i {
        font-size: 4.6rem;
        color: #00D8FF;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        border-radius: 999px;
        background: #ffedd5;
        color: #9a3412;
        font-weight: 700;
        font-size: 0.78rem;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        padding: 0.55rem 0.9rem;
    }

    .maintenance-title {
        color: #0F1E36;
        font-size: clamp(2rem, 5vw, 3.2rem);
        line-height: 1.08;
        letter-spacing: -0.02em;
    }

    .maintenance-copy {
        color: #475569;
        line-height: 1.8;
        max-width: 560px;
    }

    .maintenance-info {
        border-left: 4px solid #1e40af;
        background: #f8fafc;
        border-radius: 14px;
        padding: 1rem 1.1rem;
    }

    @media (max-width: 767.98px) {
        .maintenance-wrapper {
            min-height: auto;
            padding: 1.5rem 0;
        }

        .maintenance-visual {
            min-height: 220px;
            padding: 2rem;
        }

        .maintenance-icon {
            width: 108px;
            height: 108px;
            border-radius: 26px;
        }

        .maintenance-icon i {
            font-size: 3.6rem;
        }
    }
</style>

<div class="container maintenance-wrapper">
    <div class="row justify-content-center w-100 m-0">
        <div class="col-xl-10 col-lg-11">
            <section class="maintenance-panel">
                <div class="row g-0">
                    <div class="col-md-5 maintenance-visual">
                        <div class="maintenance-icon" aria-hidden="true">
                            <i class="fas fa-screwdriver-wrench"></i>
                        </div>
                    </div>

                    <div class="col-md-7 p-4 p-md-5">
                        <span class="status-pill mb-4">
                            <i class="fas fa-circle-info"></i>
                            Sistem Dalam Pemeliharaan
                        </span>

                        <h1 class="maintenance-title fw-bold mb-3">Kami sedang meningkatkan layanan.</h1>

                        <p class="maintenance-copy mb-4">
                            Saber eProc sementara tidak dapat diakses karena sedang dilakukan pemeliharaan sistem.
                            Silakan coba kembali beberapa saat lagi.
                        </p>

                        <div class="maintenance-info mb-4">
                            <div class="fw-bold text-dark mb-1">Terima kasih atas pengertiannya.</div>
                            <div class="text-muted small">Tim Saber Enterprise sedang memastikan platform kembali beroperasi dengan stabil.</div>
                        </div>

                    </div>
                </div>
            </section>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
