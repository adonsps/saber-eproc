<?php
require_once __DIR__ . '/notif_helper_inapp.php';
require_once __DIR__ . '/branding.php';
// $conn ?? null: not every page rendering this header has a DB connection in
// scope (root login.php never requires config.php), and reading it bare emitted
// an "Undefined variable" warning on every such request. The helpers treat a
// null connection as "no notifications".
$notif_count = isset($_SESSION['user_id']) ? getUnreadNotifCount($conn ?? null, $_SESSION['user_id']) : 0;
$notif_items = isset($_SESSION['user_id']) ? getUnreadNotifs($conn ?? null, $_SESSION['user_id']) : [];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <title><?= isset($page_title) ? $page_title : 'Saber eProc | Enterprise Procurement Portal'; ?></title>

    <?= vss_favicon_tags() ?>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">

    <link href="<?= $path_to_root ?>assets/css/style.css?v=<?= time(); ?>" rel="stylesheet">
    <style>
    /* 1. Better Spacing on Mobile */
    @media (max-width: 768px) {
        .container { padding-left: 20px; padding-right: 20px; }
        .card-body { padding: 1.25rem !important; }
        
        /* 2. Larger Touch Targets */
        .btn-lg, .btn-group-lg > .btn { font-size: 1.1rem; padding: 12px 20px; }
        .form-control, .form-select { font-size: 16px; /* Prevents iOS zoom */ padding: 10px 12px; }
        
        /* 3. Navbar adjustments */
        .navbar-brand { font-size: 1.1rem; }
        
        /* 4. Hide non-essential text on mobile to save space */
        .mobile-hidden { display: none !important; }
        
        /* 5. Stack tables with horizontal scroll */
        .table-responsive { border: 0; }
    }

    /* Floating Action Button style for "Save" actions on mobile */
    .mobile-fab {
        position: fixed; bottom: 20px; right: 20px; z-index: 1000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
    }
</style>
</head>
<body>
    <?php if (!empty($_SESSION['impersonator'])): ?>
    <?php
    // Two flows impersonate a vendor: Direct Registration (Sourcing finishing a
    // signup) and the staff profile edit (Sourcing/VM editing through the PQ
    // lock). Label the banner for whichever one is running — "menyelesaikan
    // pendaftaran" is wrong and alarming when VM is just fixing a document.
    $imp_role  = $_SESSION['impersonator']['role'] ?? 'sourcing';
    $imp_is_vm = in_array($imp_role, ['vendor_mgt', 'vendormgt_head'], true);
    $imp_label = $imp_is_vm ? 'Vendor Management' : 'Sourcing';
    $imp_what  = (($_SESSION['impersonator']['purpose'] ?? '') === 'staff_profile_edit')
        ? 'mengubah profil vendor'
        : 'menyelesaikan pendaftaran';
    $imp_exit  = isset($path_to_root)
        ? $path_to_root . 'modules/sourcing/exit_direct_registration.php'
        : 'exit_direct_registration.php';
    ?>
    <div class="w-100 text-white text-center py-2 small" style="position: sticky; top: 0; z-index: 1050; background: #0f2a4a; border-bottom: 1px solid rgba(255,193,7,0.4);">
        <i class="fas fa-user-shield me-1" style="color:#ffc107;"></i>
        Anda sedang <?= $imp_what ?> atas nama <strong><?= htmlspecialchars($_SESSION['company_name'] ?? 'vendor ini') ?></strong>
        sebagai <?= $imp_label ?> (<?= htmlspecialchars($_SESSION['impersonator']['user_name'] ?? 'Tim Procurement') ?>)
        &nbsp;&bull;&nbsp;
        <a href="<?= htmlspecialchars($imp_exit) ?>" class="text-white text-decoration-underline fw-bold">
            <i class="fas fa-sign-out-alt me-1"></i>Keluar &amp; kembali ke <?= $imp_label ?>
        </a>
    </div>
    <?php endif; ?>
    <main>