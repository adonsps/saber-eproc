<?php
session_start();
require '../../config.php'; // Note the path: 2 levels up
require_once '../../includes/branding.php';
require_once '../../includes/vendor_context.php';

// 1. Security Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'vendor') {
    header("Location: ../../login.php");
    exit();
}

// A staff correction session (modules/shared/edit_vendor_profile.php) may only
// ever be on profile.php.
vendor_staff_edit_restrict();

// 2. If already completed, go to dashboard
if ($_SESSION['status'] === 'registration_submitted' || $_SESSION['status'] === 'registered' || $_SESSION['status'] === 'invited_pq' || $_SESSION['status'] === 'migrated') {
    header("Location: dashboard.php");
    exit();
}

// 3. Get existing info to display (Optional, but nice)
$vendor_id = $_SESSION['vendor_id'];
$query = "SELECT company_name, contact_person FROM vendors WHERE id = '$vendor_id'";
$result = $conn->query($query);
$vendor = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lengkapi Profil | VSS</title>
    <?= vss_favicon_tags() ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    /* This page predates includes/header.php and so never loaded
       assets/css/style.css. Rather than pull the whole stylesheet in (it would
       restyle every card, button and form on the page), the light navbar is
       reproduced here on its own. Keep in sync with style.css section 6b. */
    .navbar-vss {
        background-color: #ffffff;
        border-bottom: 1px solid #e4e9f0;
        box-shadow: 0 1px 3px rgba(16, 24, 40, 0.06);
    }
    .navbar-vss .navbar-brand { color: #00609C; font-weight: 700; }
    .navbar-vss .btn-nav {
        color: #00609C;
        background-color: #ffffff;
        border: 1px solid #dbe3ec;
        font-weight: 600;
        padding: 0.4rem 0.9rem;
        font-size: 0.82rem;
        border-radius: 50px;
        white-space: nowrap;
    }
    .navbar-vss .btn-nav:hover,
    .navbar-vss .btn-nav:focus {
        color: #004c7d;
        background-color: rgba(0, 96, 156, 0.07);
        border-color: #00609C;
    }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-vss mb-4">
    <div class="container">
        <span class="navbar-brand mb-0 h1 d-flex align-items-center gap-2">
            <?= vss_brand_logo() ?>
            VSS - Pendaftaran Vendor
        </span>
        <a href="../../logout.php" class="btn btn-sm btn-nav">Keluar</a>
    </div>
</nav>

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header">
                    <h4>Langkah 2: Selesaikan Registrasi</h4>
                    <p class="mb-0 text-muted">Selamat datang, <strong><?= htmlspecialchars($vendor['company_name']) ?></strong>. Mohon lengkapi data Anda.</p>
                </div>
                <div class="card-body">
                    
                    <form action="profile_action.php" method="POST">
                        <input type="hidden" name="vendor_id" value="<?= $vendor_id ?>">

                        <h5 class="text-primary mt-2">Informasi Rekening Bank</h5>
                        <hr>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nama Bank *</label>
                                <input type="text" name="bank_name" class="form-control" placeholder="e.g. BCA, Mandiri" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nomor Rekening *</label>
                                <input type="number" name="bank_account" class="form-control" required>
                            </div>
                            <div class="col-md-12 mb-3">
                                <label class="form-label">Nama Pemilik Rekening *</label>
                                <input type="text" name="bank_holder" class="form-control" placeholder="Harus sesuai dengan nama perusahaan atau badan hukum" required>
                            </div>
                        </div>

                        <h5 class="text-primary mt-3">Kemampuan Bisnis</h5>
                        <hr>
                        <div class="mb-3">
                            <label class="form-label">Daftar Barang / Jasa yang Disediakan *</label>
                            <textarea name="goods_list" class="form-control" rows="5" placeholder="Mohon tuliskan barang atau jasa spesifik yang dapat Anda supply..." required></textarea>
                            <div class="form-text">Ini membantu tim Procurement kami menemukan Anda untuk proyek tertentu.</div>
                        </div>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" name="save_profile" class="btn btn-success btn-lg">Selesaikan Registrasi</button>
                        </div>
                    </form>

                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>