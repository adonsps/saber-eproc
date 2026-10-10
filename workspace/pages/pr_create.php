<?php
// pages/pr_create.php
// Direct Purchase Requisition (PR) Creation for Technical Department (Dept Teknis) & Sourcing Managers.
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
date_default_timezone_set('Asia/Jakarta');

$user_role = $_SESSION['role'] ?? '';
$allowed_roles = ['Technical Department', 'Category Manager', 'Manager', 'Admin'];

if (!in_array($user_role, $allowed_roles, true)) {
    header('Location: ' . ($user_role === 'STC' ? 'dashboard_stc.php' : 'dashboard_pic.php'));
    exit;
}

$team_name     = $_SESSION['team_name'] ?? '';
$current_user  = $_SESSION['username'] ?? '';
$display_name  = $_SESSION['name'] ?? $current_user;
$user_email    = $_SESSION['email'] ?? '';

// --- 1. FETCH REFERENCE DATA ---

// User's mapped PGroups for Technical Department
$my_pgroups = [];
if (!empty($team_name)) {
    try {
        $st = $pdo->prepare("SELECT pgroup FROM technical_team_pgroups WHERE team_name = ? ORDER BY pgroup ASC");
        $st->execute([$team_name]);
        $my_pgroups = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {}
}

// All PGroup mappings (p_group -> team_name, pic_name)
$all_pgroups = [];
try {
    $st = $pdo->query("SELECT p_group, team_name, pic_name FROM pgroup_team_mappings ORDER BY p_group ASC");
    $all_pgroups = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

// Keyed map for fast lookup in JS/PHP
$pgroup_map = [];
foreach ($all_pgroups as $pg) {
    $pgroup_map[$pg['p_group']] = [
        'team_name' => $pg['team_name'],
        'pic_name'  => $pg['pic_name']
    ];
}

// Distinct P.Orgs
$common_porgs = [];
try {
    $st = $pdo->query("SELECT p_org, COUNT(*) as cnt FROM prs WHERE p_org IS NOT NULL AND p_org != '' GROUP BY p_org ORDER BY cnt DESC LIMIT 20");
    $common_porgs = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}
if (empty($common_porgs)) {
    $common_porgs = ['RA30', 'RH30', 'FF30', 'RJ30', 'PA30', 'RT30', 'MD30', 'PT30', 'SS30', 'II30'];
}

// Distinct Sites
$common_sites = [];
try {
    $st = $pdo->query("SELECT DISTINCT site_allocation FROM prs WHERE site_allocation IS NOT NULL AND site_allocation != '' ORDER BY site_allocation ASC LIMIT 150");
    $common_sites = $st->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

// Standard UOMs
$uom_options = ['PCS', 'UNIT', 'SET', 'BOX', 'ROL', 'PAC', 'KG', 'M', 'M2', 'RIM', 'LOT', 'CAN', 'CAR', 'EA', 'AU', 'PAK'];

// Auto-generate the next default ticket_no: PR + YYMM + 4-digit sequence
$prefix = 'PR' . date('ym');
$auto_ticket_no = $prefix . '0001';
try {
    $st = $pdo->prepare("SELECT ticket_no FROM prs WHERE ticket_no LIKE ? ORDER BY ticket_no DESC LIMIT 1");
    $st->execute([$prefix . '%']);
    $latest = $st->fetchColumn();
    if ($latest && preg_match('/^' . $prefix . '(\d{4})$/', $latest, $m)) {
        $seq = (int)$m[1] + 1;
        $auto_ticket_no = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
    }
} catch (\Throwable $e) {}

// --- 2. HANDLE FORM SUBMISSION ---
$errors = [];
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_pr') {
    $ticket_no          = strtoupper(trim($_POST['ticket_no'] ?? ''));
    $pr_date            = trim($_POST['pr_date'] ?? date('Y-m-d'));
    $requestor_name     = trim($_POST['requestor_name'] ?? $display_name);
    $p_org              = strtoupper(trim($_POST['p_org'] ?? ''));
    $p_group            = strtoupper(trim($_POST['p_group'] ?? ''));
    $site_allocation    = trim($_POST['site_allocation'] ?? '');
    $ticket_type        = trim($_POST['ticket_type'] ?? 'Reguler');
    $delivery_date      = trim($_POST['delivery_date'] ?? '');
    $ticket_helpdesk_no = trim($_POST['ticket_helpdesk_no'] ?? '');

    // Basic Validations
    if ($ticket_no === '') {
        $errors[] = 'Ticket / PR Number tidak boleh kosong.';
    } elseif (!preg_match('/^[A-Z0-9_\-\.]{4,30}$/', $ticket_no)) {
        $errors[] = 'Format Ticket / PR Number tidak valid (gunakan kombinasi huruf, angka, tanda strip min. 4 karakter).';
    } else {
        // Check duplicate ticket_no
        $chk = $pdo->prepare("SELECT COUNT(*) FROM prs WHERE ticket_no = ?");
        $chk->execute([$ticket_no]);
        if ((int)$chk->fetchColumn() > 0) {
            $errors[] = "Nomor tiket PR '{$ticket_no}' sudah terdaftar dalam sistem. Silakan gunakan nomor lain atau tekan tombol Generate.";
        }
    }

    if ($pr_date === '' || !strtotime($pr_date)) {
        $errors[] = 'Tanggal PR tidak valid.';
    }
    if ($requestor_name === '') {
        $errors[] = 'Nama Pemohon (Requestor) wajib diisi.';
    }
    if ($p_org === '') {
        $errors[] = 'Purchasing Organization (P.Org) wajib dipilih/diisi.';
    }
    if ($p_group === '') {
        $errors[] = 'Purchasing Group (P.Group) wajib dipilih.';
    }
    if ($site_allocation === '') {
        $errors[] = 'Site Allocation / Lokasi wajib dipilih/diisi.';
    }

    // Line Items Validation
    $raw_items = $_POST['items'] ?? [];
    $valid_items = [];
    $total_estimated_amount = 0;

    if (!is_array($raw_items) || empty($raw_items)) {
        $errors[] = 'Minimal harus menambahkan 1 (satu) baris barang atau jasa pada PR.';
    } else {
        foreach ($raw_items as $idx => $row) {
            $desc      = trim($row['description'] ?? '');
            $qty       = (float)($row['qty'] ?? 0);
            $uom       = strtoupper(trim($row['uom'] ?? 'PCS'));
            $price     = (float)($row['baseline_price'] ?? 0);
            $item_type = trim($row['item_type'] ?? 'catalogue');
            $sku       = trim($row['sku'] ?? '');
            $qlink     = trim($row['quotation_link'] ?? '');
            $item_deliv = trim($row['delivery_date'] ?? $delivery_date);

            // Skip empty rows if multiple rows exist
            if ($desc === '' && $qty <= 0 && count($raw_items) > 1) {
                continue;
            }

            if ($item_type === 'new_item' && ($sku === '' || $sku === 'NEW-ITEM')) {
                $sku = 'NEW-ITEM';
            }

            if ($desc === '') {
                $errors[] = "Baris " . ($idx + 1) . ": Deskripsi barang/jasa tidak boleh kosong.";
            }
            if ($qty <= 0) {
                $errors[] = "Baris " . ($idx + 1) . ": Jumlah (Quantity) harus lebih besar dari 0.";
            }

            $valid_items[] = [
                'description'    => $desc,
                'qty'            => $qty,
                'uom'            => $uom ?: 'PCS',
                'baseline_price' => $price >= 0 ? $price : 0,
                'sku'            => $sku,
                'item_type'      => $item_type,
                'quotation_link' => $qlink,
                'delivery_date'  => $item_deliv ?: null,
            ];

            $total_estimated_amount += ($qty * ($price >= 0 ? $price : 0));
        }

        if (empty($valid_items) && empty($errors)) {
            $errors[] = 'Minimal harus mengisi 1 (satu) baris barang atau jasa yang valid.';
        }
    }

    // If valid, execute transactional insert
    if (empty($errors)) {
        // Resolve Assigned PIC from pgroup_team_mappings
        $assigned_pic = null;
        if (isset($pgroup_map[$p_group])) {
            $assigned_pic = $pgroup_map[$p_group]['pic_name'] ?? null;
        }

        try {
            $pdo->beginTransaction();

            $insertStmt = $pdo->prepare("
                INSERT INTO prs (
                    ticket_no, ticket_line_item, p_org, p_group, requestor_name,
                    site_allocation, pr_date, item_description, status_ticket,
                    ticket_type, vendor_name, qty, uom, baseline_price, baseline_currency,
                    delivery_date, sku, ticket_helpdesk_no, assigned_pic, quotation_link,
                    created_at, last_updated_by, last_updated_date
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, 'Approved',
                    ?, '', ?, ?, ?, 'IDR',
                    ?, ?, ?, ?, ?,
                    NOW(), ?, NOW()
                )
            ");

            foreach ($valid_items as $line_idx => $item) {
                $line_number = (string)(($line_idx + 1) * 10); // Standard ERP Line numbering: 10, 20, 30...

                $insertStmt->execute([
                    $ticket_no,
                    $line_number,
                    $p_org,
                    $p_group,
                    $requestor_name,
                    $site_allocation,
                    $pr_date,
                    $item['description'],
                    $ticket_type,
                    $item['qty'],
                    $item['uom'],
                    $item['baseline_price'] > 0 ? $item['baseline_price'] : null,
                    $item['delivery_date'],
                    $item['sku'] ?: null,
                    $ticket_helpdesk_no ?: null,
                    $assigned_pic ?: null,
                    $item['quotation_link'] ?: null,
                    $current_user
                ]);
            }

            $pdo->commit();

            // Set session flash for worklist feedback
            $_SESSION['flash_pr_success'] = [
                'ticket_no'    => $ticket_no,
                'lines_count'  => count($valid_items),
                'total_amount' => $total_estimated_amount,
                'assigned_pic' => $assigned_pic ?: 'Unassigned'
            ];

            header("Location: dashboard_tech.php?created_ticket=" . urlencode($ticket_no));
            exit;

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Gagal menyimpan PR ke database: ' . $e->getMessage();
        }
    }
}

require_once '../includes/header.php';
?>

<div class="mb-6">
    <!-- Breadcrumb -->
    <nav class="flex items-center space-x-2 text-xs font-semibold text-slate-500 mb-3">
        <a href="dashboard_tech.php" class="hover:text-blue-700 transition">My Worklist</a>
        <span>/</span>
        <span class="text-slate-800">Create Purchase Requisition</span>
    </nav>

    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 pb-4 border-b border-slate-200">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <span class="px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-blue-100 text-blue-800 border border-blue-200 uppercase tracking-wide">
                    <?= htmlspecialchars($team_name ?: 'Dept Teknis') ?>
                </span>
                <span class="text-xs text-slate-400 font-medium">&bull; Direct Requisition Creator</span>
            </div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl bg-blue-700 text-white flex items-center justify-center text-base shadow-sm">
                    <i class="fas fa-file-signature"></i>
                </span>
                Buat Purchase Requisition (PR)
            </h1>
            <p class="text-sm text-slate-500 mt-1">
                Formulir pengajuan kebutuhan barang dan jasa langsung ke sistem Saber eProc untuk diteruskan ke tim Sourcing.
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="dashboard_tech.php" class="px-4 py-2 border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-semibold rounded-xl shadow-sm transition">
                <i class="fas fa-arrow-left mr-1.5 text-xs text-slate-400"></i> Kembali ke Worklist
            </a>
        </div>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="mb-6 p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-sm">
    <div class="flex items-center gap-2 font-bold mb-2">
        <i class="fas fa-exclamation-circle text-rose-600 text-base"></i> Terdapat kesalahan pada formulir:
    </div>
    <ul class="list-disc list-inside space-y-1 text-xs md:text-sm pl-2">
        <?php foreach ($errors as $err): ?>
        <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="pr_create.php" id="prForm" class="space-y-6">
    <input type="hidden" name="action" value="create_pr">

    <!-- Card 1: Informasi Dokumen Requisition -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-slate-50/70 border-b border-slate-200 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center text-xs font-bold border border-blue-100">1</span>
                <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Informasi Dokumen Requisition</h3>
            </div>
            <span class="text-xs text-slate-400 font-medium">Header PR</span>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
                
                <!-- Ticket / PR Number -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Ticket / PR Number <span class="text-rose-500">*</span>
                    </label>
                    <div class="relative flex rounded-xl shadow-sm">
                        <input type="text" name="ticket_no" id="ticketNoInput" 
                            value="<?= htmlspecialchars($_POST['ticket_no'] ?? $auto_ticket_no) ?>" required
                            class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-l-xl text-sm font-mono font-bold text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600 uppercase"
                            placeholder="e.g. PR26100001">
                        <button type="button" id="btnGenTicket" 
                            title="Generate Nomor PR Baru"
                            class="px-3.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold border-y border-r border-slate-300 rounded-r-xl text-xs transition flex items-center gap-1">
                            <i class="fas fa-sync-alt text-[10px]"></i> Auto
                        </button>
                    </div>
                    <div id="ticketCheckFeedback" class="text-[11px] mt-1 font-medium"></div>
                </div>

                <!-- PR Date -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Tanggal PR <span class="text-rose-500">*</span>
                    </label>
                    <input type="date" name="pr_date" id="prDateInput" 
                        value="<?= htmlspecialchars($_POST['pr_date'] ?? date('Y-m-d')) ?>" required
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600">
                </div>

                <!-- Requestor Name -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Nama Pemohon (Requestor) <span class="text-rose-500">*</span>
                    </label>
                    <input type="text" name="requestor_name" 
                        value="<?= htmlspecialchars($_POST['requestor_name'] ?? $display_name) ?>" required
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600">
                    <span class="text-[11px] text-slate-400 mt-0.5 block">Akun: <?= htmlspecialchars($current_user) ?></span>
                </div>

                <!-- P.Group -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Purchasing Group (P.Group) <span class="text-rose-500">*</span>
                    </label>
                    <select name="p_group" id="pGroupSelect" required
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600">
                        <option value="">-- Pilih P.Group --</option>
                        
                        <?php if (!empty($my_pgroups)): ?>
                        <optgroup label="P.Group Terdaftar pada Tim Anda (<?= htmlspecialchars($team_name) ?>)">
                            <?php foreach ($my_pgroups as $mpg): 
                                $sel = (($_POST['p_group'] ?? '') === $mpg) ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($mpg) ?>" <?= $sel ?>>
                                <?= htmlspecialchars($mpg) ?> - <?= htmlspecialchars($pgroup_map[$mpg]['team_name'] ?? 'In-Scope') ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>

                        <optgroup label="Seluruh Purchasing Groups">
                            <?php foreach ($all_pgroups as $row): 
                                $sel = (($_POST['p_group'] ?? '') === $row['p_group'] && !in_array($row['p_group'], $my_pgroups)) ? 'selected' : '';
                            ?>
                            <option value="<?= htmlspecialchars($row['p_group']) ?>" <?= $sel ?>>
                                <?= htmlspecialchars($row['p_group']) ?> - <?= htmlspecialchars($row['team_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                    </select>
                    
                    <!-- Dynamic PIC Live Card -->
                    <div id="picPreviewBox" class="mt-2 p-2.5 rounded-lg bg-blue-50/80 border border-blue-100 text-[11px] text-blue-900 hidden flex items-center gap-2">
                        <i class="fas fa-user-check text-blue-600"></i>
                        <div>
                            <span class="font-bold">Target PIC Sourcing:</span> 
                            <span id="picPreviewName" class="font-bold text-blue-700">--</span>
                            <span id="picPreviewTeam" class="text-slate-500 block text-[10px]">--</span>
                        </div>
                    </div>
                </div>

                <!-- P.Org -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Purchasing Organization (P.Org) <span class="text-rose-500">*</span>
                    </label>
                    <select name="p_org" id="pOrgSelect" required
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm font-semibold text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600">
                        <option value="">-- Pilih P.Org --</option>
                        <?php foreach ($common_porgs as $po): 
                            $sel = (($_POST['p_org'] ?? '') === $po) ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($po) ?>" <?= $sel ?>><?= htmlspecialchars($po) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Site Allocation -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Site Allocation / Lokasi / Cabang <span class="text-rose-500">*</span>
                    </label>
                    <input list="sitesList" name="site_allocation" id="siteAllocationInput" 
                        value="<?= htmlspecialchars($_POST['site_allocation'] ?? '') ?>" required
                        placeholder="e.g. Head Office, Living World, DC Cikarang..."
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600">
                    <datalist id="sitesList">
                        <?php foreach ($common_sites as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <!-- Ticket Type -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Tipe Pengadaan (Ticket Type)
                    </label>
                    <select name="ticket_type" 
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600 font-medium">
                        <?php 
                        $types = ['Reguler', 'Services / Jasa', 'Project New Store', 'Intercompany', 'Fast-Track'];
                        foreach ($types as $t): 
                            $sel = (($_POST['ticket_type'] ?? 'Reguler') === $t) ? 'selected' : '';
                        ?>
                        <option value="<?= htmlspecialchars($t) ?>" <?= $sel ?>><?= htmlspecialchars($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Target Delivery Date -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        Target Tanggal Kirim / Selesai
                    </label>
                    <input type="date" name="delivery_date" 
                        value="<?= htmlspecialchars($_POST['delivery_date'] ?? '') ?>"
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600">
                </div>

                <!-- Helpdesk / Memo Ref -->
                <div>
                    <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                        No. Ref Memo / Helpdesk (Opsional)
                    </label>
                    <input type="text" name="ticket_helpdesk_no" 
                        value="<?= htmlspecialchars($_POST['ticket_helpdesk_no'] ?? '') ?>"
                        placeholder="e.g. HD-2026-9921 / Memo GA-04"
                        class="w-full px-3.5 py-2.5 bg-white border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600 font-mono text-xs">
                </div>

            </div>
        </div>
    </div>

    <!-- Card 2: Line Items Barang / Jasa -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-6 py-4 bg-slate-50/70 border-b border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span class="w-7 h-7 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center text-xs font-bold border border-blue-100">2</span>
                <div>
                    <h3 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Daftar Barang / Jasa yang Dibutuhkan</h3>
                    <p class="text-[11px] text-slate-500">Pilih dari Kamus SKU / Katalog Produk, atau ajukan Item Baru (Non-Katalog) untuk barang/jasa khusus.</p>
                </div>
            </div>

            <div class="flex items-center flex-wrap gap-2">
                <button type="button" id="btnOpenSkuModalHeader" 
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl shadow-sm hover:shadow transition">
                    <i class="fas fa-search text-[10px]"></i> Cari dari SKU Dictionary
                </button>
                <button type="button" id="btnAddNewItemHeader" 
                    class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 text-xs font-bold rounded-xl shadow-sm hover:shadow transition">
                    <i class="fas fa-plus text-[10px]"></i> Request Item Baru (Non-Katalog)
                </button>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse" id="lineItemsTable">
                <thead>
                    <tr class="bg-slate-100/70 border-b border-slate-200 text-slate-600 font-bold uppercase text-[10px] tracking-wider">
                        <th class="py-3 px-3 w-12 text-center">Line</th>
                        <th class="py-3 px-3 min-w-[320px]">Deskripsi Barang / Jasa & Tipe <span class="text-rose-500">*</span></th>
                        <th class="py-3 px-3 w-36">SKU Code</th>
                        <th class="py-3 px-3 w-28 text-right">Jumlah (Qty) <span class="text-rose-500">*</span></th>
                        <th class="py-3 px-3 w-28 text-center">Satuan (UoM) <span class="text-rose-500">*</span></th>
                        <th class="py-3 px-3 w-36 text-right">Estimasi Harga (IDR)</th>
                        <th class="py-3 px-3 w-36 text-right">Subtotal</th>
                        <th class="py-3 px-3 w-40">Dokumen / Quotation Link</th>
                        <th class="py-3 px-3 w-12 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="lineItemsBody" class="divide-y divide-slate-100">
                    <!-- Dynamic rows inserted by JavaScript or prefilled from POST -->
                </tbody>
                <tfoot>
                    <tr class="bg-slate-50 border-t border-slate-200">
                        <td colspan="5" class="py-3.5 px-4 text-right uppercase text-xs font-bold text-slate-600">
                            Total Estimasi Anggaran (IDR):
                        </td>
                        <td colspan="2" class="py-3.5 px-3 text-right">
                            <span id="grandTotalLabel" class="text-base font-black text-blue-900 tracking-tight">Rp 0</span>
                        </td>
                        <td colspan="2" class="py-3.5 px-3 text-right text-xs text-slate-500">
                            <span id="totalItemsCountLabel" class="font-bold">0</span> Item(s)
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Card 3: Action & Ringkasan Dispatch -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex flex-col md:flex-row items-center justify-between gap-6">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl flex-shrink-0 border border-emerald-100">
                <i class="fas fa-check-double"></i>
            </div>
            <div>
                <h4 class="text-sm font-bold text-slate-900">Alur Pemrosesan Otomatis</h4>
                <p class="text-xs text-slate-500 mt-0.5">
                    PR yang disubmit akan langsung masuk ke status <span class="font-bold text-blue-700 bg-blue-50 px-1.5 py-0.5 rounded border border-blue-200">Outstanding / Worklist</span> dan diarahkan ke PIC pengadaan sesuai P.Group.
                </p>
            </div>
        </div>

        <div class="flex items-center gap-3 w-full md:w-auto">
            <a href="dashboard_tech.php" class="flex-1 md:flex-initial px-5 py-3 border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold rounded-xl text-sm transition text-center">
                Batal
            </a>
            <button type="submit" id="btnSubmitPr" 
                class="flex-1 md:flex-initial px-7 py-3 bg-blue-700 hover:bg-blue-800 text-white font-extrabold rounded-xl text-sm shadow-md hover:shadow-lg transform hover:-translate-y-0.5 transition-all flex items-center justify-center gap-2">
                <i class="fas fa-paper-plane text-xs"></i> Submit Purchase Requisition
            </button>
        </div>
    </div>

</form>

<!-- Modal: Browse & Search SKU Dictionary -->
<div id="skuSearchModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <!-- Backdrop with blur -->
    <div class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" id="skuModalBackdrop"></div>

    <div class="flex min-h-screen items-center justify-center p-4 text-center sm:p-6">
        <div class="relative transform overflow-hidden rounded-2xl bg-white text-left shadow-2xl transition-all sm:my-8 sm:w-full sm:max-w-3xl border border-slate-200 flex flex-col max-h-[88vh]">
            
            <!-- Modal Header -->
            <div class="px-6 py-4 bg-slate-50 border-b border-slate-200 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-3">
                    <span class="w-10 h-10 rounded-xl bg-blue-100 text-blue-700 flex items-center justify-center text-lg border border-blue-200 shadow-sm">
                        <i class="fas fa-book-open"></i>
                    </span>
                    <div>
                        <h3 class="text-base font-bold text-slate-900 flex items-center gap-2">
                            Kamus SKU & Katalog Produk
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">36.000+ Items</span>
                        </h3>
                        <p class="text-xs text-slate-500 mt-0.5">
                            Cari dan pilih barang yang sudah terdaftar, atau ajukan sebagai item baru jika belum ada di katalog.
                        </p>
                    </div>
                </div>
                <button type="button" id="btnCloseSkuModal" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition">
                    <i class="fas fa-times text-base"></i>
                </button>
            </div>

            <!-- Search Bar & Controls -->
            <div class="p-4 bg-white border-b border-slate-100 flex-shrink-0">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <i class="fas fa-search text-sm"></i>
                    </div>
                    <input type="text" id="skuSearchInput" 
                        class="w-full pl-10 pr-24 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:bg-white transition" 
                        placeholder="Ketik nama barang, deskripsi, atau nomor SKU (e.g. kursi, meja, kabel, toner, 31089)..."
                        autocomplete="off">
                    <button type="button" id="btnClearSkuSearch" class="absolute inset-y-0 right-2 px-2.5 my-1.5 text-xs text-slate-400 hover:text-slate-700 font-semibold rounded-lg hover:bg-slate-100 hidden">
                        Clear
                    </button>
                </div>
                <div class="flex items-center justify-between mt-2 px-1 text-[11px] text-slate-500">
                    <span id="skuSearchStatus">Menampilkan item populer/terbaru...</span>
                    <span class="text-slate-400">Klik "Pilih Item" untuk memasukkan ke baris PR</span>
                </div>
            </div>

            <!-- Results List / Scroll Area -->
            <div class="flex-1 overflow-y-auto p-4 space-y-2.5 bg-slate-50/50 min-h-[260px]" id="skuSearchResultsContainer">
                <!-- Live search items will be rendered here -->
            </div>

            <!-- Modal Footer: Call To Action for New Item Request -->
            <div class="p-4 bg-amber-50/80 border-t border-amber-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-3 flex-shrink-0">
                <div class="flex items-start gap-2.5">
                    <div class="w-7 h-7 rounded-lg bg-amber-100 text-amber-800 flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5 border border-amber-200">
                        <i class="fas fa-sparkles"></i>
                    </div>
                    <div>
                        <div class="text-xs font-bold text-amber-900">Barang tidak ditemukan di katalog / SKU dictionary?</div>
                        <div class="text-[11px] text-amber-800/80">Anda dapat langsung mengajukan permintaan sebagai barang baru (non-katalog).</div>
                    </div>
                </div>
                <button type="button" id="btnRequestNewItemFromModal" 
                    class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded-xl text-xs shadow-sm hover:shadow transition flex items-center justify-center gap-1.5 flex-shrink-0">
                    <i class="fas fa-plus-circle text-xs"></i> Request Item Baru (Non-Katalog)
                </button>
            </div>

        </div>
    </div>
</div>

<!-- JS Logic for Dynamic Line Items, Calculation, and SKU Search / New Item Modal -->
<script>
const pgroupMap   = <?= json_encode($pgroup_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const uomOptions  = <?= json_encode($uom_options, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const oldItems    = <?= json_encode($_POST['items'] ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

let rowCounter = 0;
let activeModalTargetRowId = null;
let skuSearchXhr = null;
let skuSearchDebounce = null;

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function formatRupiah(number) {
    return 'Rp ' + Number(number).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
}

function updateTotals() {
    let grandTotal = 0;
    let count = 0;

    $('#lineItemsBody tr').each(function(idx) {
        count++;
        // Update line number (10, 20, 30...)
        const lineNum = (idx + 1) * 10;
        $(this).find('.line-idx-badge').text(lineNum);

        const qty   = parseFloat($(this).find('.input-qty').val()) || 0;
        const price = parseFloat($(this).find('.input-price').val()) || 0;
        const subtotal = qty * price;

        $(this).find('.line-subtotal').text(subtotal > 0 ? formatRupiah(subtotal) : 'Rp 0');
        grandTotal += subtotal;
    });

    $('#grandTotalLabel').text(formatRupiah(grandTotal));
    $('#totalItemsCountLabel').text(count);
}

function addLineItemRow(data = {}) {
    rowCounter++;
    const idx = rowCounter;

    const itemType = (data.item_type === 'new_item' || data.sku === 'NEW-ITEM') ? 'new_item' : 'catalogue';
    const isNewItem = (itemType === 'new_item');

    const desc  = data.description || '';
    const qty   = data.qty || '';
    const uom   = (data.uom || 'PCS').toUpperCase();
    const price = data.baseline_price || '';
    const skuVal = isNewItem ? (data.sku || 'NEW-ITEM') : (data.sku || '');
    const qlink = data.quotation_link || '';

    let uomSelectHtml = `<select name="items[${idx}][uom]" class="select-uom w-full px-2 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-semibold text-slate-800 focus:outline-none focus:ring-1 focus:ring-blue-600 text-center">`;
    uomOptions.forEach(opt => {
        const sel = (opt === uom) ? 'selected' : '';
        uomSelectHtml += `<option value="${opt}" ${sel}>${opt}</option>`;
    });
    uomSelectHtml += `</select>`;

    const trHtml = `
    <tr class="item-row hover:bg-slate-50/70 transition" data-row-id="${idx}">
        <td class="py-3 px-3 text-center align-top pt-4">
            <span class="line-idx-badge px-2 py-1 rounded-md bg-slate-100 font-mono text-[11px] font-bold text-slate-700 border border-slate-200">10</span>
        </td>
        <td class="py-3 px-3 align-top">
            <!-- Row Type Badge and Switching Controls -->
            <div class="flex items-center justify-between mb-1.5 flex-wrap gap-1">
                <div class="item-type-badge-container">
                    ${isNewItem ? `
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
                            <i class="fas fa-sparkles text-[9px] text-amber-600"></i> Item Baru (Non-Katalog)
                        </span>
                    ` : `
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                            <i class="fas fa-barcode text-[9px]"></i> Katalog SKU
                        </span>
                    `}
                </div>
                <div class="flex items-center gap-2 text-[11px]">
                    <button type="button" class="btn-row-pick-sku text-blue-600 hover:text-blue-800 font-bold inline-flex items-center gap-1 transition" title="Cari di Kamus SKU">
                        <i class="fas fa-search text-[10px]"></i> <span class="btn-search-text">${isNewItem ? 'Cari di SKU' : 'Ganti SKU'}</span>
                    </button>
                    <span class="text-slate-300">|</span>
                    <button type="button" class="btn-row-toggle-type text-slate-500 hover:text-slate-800 font-medium transition" title="Ganti tipe pengadaan item ini">
                        <span class="btn-toggle-text">${isNewItem ? 'Jadikan Katalog' : 'Ubah ke Item Baru'}</span>
                    </button>
                </div>
            </div>
            
            <input type="hidden" name="items[${idx}][item_type]" class="input-item-type" value="${itemType}">

            <textarea name="items[${idx}][description]" required rows="2" 
                class="input-description w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs text-slate-900 focus:outline-none focus:ring-2 focus:ring-blue-600 leading-normal" 
                placeholder="${isNewItem ? 'Tuliskan nama barang/jasa baru, merek, spesifikasi teknis, atau ruang lingkup pekerjaan...' : 'Deskripsi barang/spesifikasi dari katalog SKU...'}">${$('<div/>').text(desc).html()}</textarea>
        </td>
        <td class="py-3 px-3 align-top">
            <div class="sku-input-wrapper">
                <input type="text" name="items[${idx}][sku]" value="${$('<div/>').text(skuVal).html()}" 
                    class="input-sku w-full px-2.5 py-1.5 border rounded-lg text-xs font-mono font-bold focus:outline-none focus:ring-1 ${isNewItem ? 'bg-amber-50/50 border-amber-200 text-amber-900 focus:ring-amber-500' : 'bg-slate-50 border-slate-300 text-slate-800 focus:ring-blue-600'}" 
                    placeholder="${isNewItem ? 'NEW-ITEM' : 'e.g. 10425283'}"
                    ${!isNewItem ? 'readonly' : ''}>
                <div class="sku-hint text-[10px] mt-0.5 font-medium ${isNewItem ? 'text-amber-700' : 'text-slate-400'}">
                    ${isNewItem ? 'Non-Katalog' : 'Terkoneksi Master'}
                </div>
            </div>
        </td>
        <td class="py-3 px-3 align-top">
            <input type="number" step="0.01" min="0.01" name="items[${idx}][qty]" value="${qty}" required 
                class="input-qty w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-bold text-right text-slate-900 focus:outline-none focus:ring-1 focus:ring-blue-600" 
                placeholder="1">
        </td>
        <td class="py-3 px-3 text-center align-top">
            ${uomSelectHtml}
        </td>
        <td class="py-3 px-3 align-top">
            <input type="number" step="100" min="0" name="items[${idx}][baseline_price]" value="${price}" 
                class="input-price w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-xs text-right text-slate-800 focus:outline-none focus:ring-1 focus:ring-blue-600 font-mono" 
                placeholder="0">
        </td>
        <td class="py-3 px-3 text-right align-top pt-4">
            <span class="line-subtotal font-bold text-blue-900 text-xs">Rp 0</span>
        </td>
        <td class="py-3 px-3 align-top">
            <input type="url" name="items[${idx}][quotation_link]" value="${$('<div/>').text(qlink).html()}" 
                class="w-full px-2.5 py-1.5 bg-white border border-slate-300 rounded-lg text-[11px] text-slate-700 focus:outline-none focus:ring-1 focus:ring-blue-600" 
                placeholder="https://drive.google.com/...">
        </td>
        <td class="py-3 px-3 text-center align-top pt-3">
            <button type="button" class="btn-remove-row text-slate-400 hover:text-rose-600 p-1.5 rounded-lg hover:bg-rose-50 transition" title="Hapus Baris">
                <i class="fas fa-trash-alt text-xs"></i>
            </button>
        </td>
    </tr>`;

    $('#lineItemsBody').append(trHtml);
    updateTotals();
}

// Apply selected SKU details to a row
function applySkuToRow(rowId, item) {
    const row = $(`#lineItemsBody tr[data-row-id="${rowId}"]`);
    if (row.length === 0) return;

    row.find('.input-item-type').val('catalogue');
    row.find('.input-description').val(item.detail);
    row.find('.input-description').attr('placeholder', 'Deskripsi barang/spesifikasi dari katalog SKU...');
    
    row.find('.input-sku').val(item.sku).prop('readonly', true)
        .removeClass('bg-amber-50/50 border-amber-200 text-amber-900')
        .addClass('bg-slate-50 border-slate-300 text-slate-800');
    row.find('.sku-hint').text('Terkoneksi Master')
        .removeClass('text-amber-700').addClass('text-slate-400');
    
    // Update badge & button texts
    row.find('.item-type-badge-container').html(`
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
            <i class="fas fa-barcode text-[9px]"></i> Katalog SKU
        </span>
    `);
    row.find('.btn-row-pick-sku .btn-search-text').text('Ganti SKU');
    row.find('.btn-row-toggle-type .btn-toggle-text').text('Ubah ke Item Baru');

    // Update UoM if matches options
    if (item.uom) {
        row.find('.select-uom').val(item.uom.toUpperCase());
    }

    // Update price if available
    if (parseFloat(item.price) > 0) {
        row.find('.input-price').val(item.price);
    }

    // Focus on Qty input
    row.find('.input-qty').focus();
    updateTotals();
}

// Convert row to New Item Request (Non-Catalogue)
function applyNewItemToRow(rowId, customDesc = '') {
    const row = $(`#lineItemsBody tr[data-row-id="${rowId}"]`);
    if (row.length === 0) return;

    row.find('.input-item-type').val('new_item');
    if (customDesc) {
        row.find('.input-description').val(customDesc);
    }
    row.find('.input-description').attr('placeholder', 'Tuliskan nama barang/jasa baru, merek, spesifikasi teknis, atau ruang lingkup pekerjaan...');

    row.find('.input-sku').val('NEW-ITEM').prop('readonly', false)
        .removeClass('bg-slate-50 border-slate-300 text-slate-800')
        .addClass('bg-amber-50/50 border-amber-200 text-amber-900 focus:ring-amber-500');
    row.find('.sku-hint').text('Non-Katalog')
        .removeClass('text-slate-400').addClass('text-amber-700');

    // Update badge & button texts
    row.find('.item-type-badge-container').html(`
        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-800 border border-amber-200">
            <i class="fas fa-sparkles text-[9px] text-amber-600"></i> Item Baru (Non-Katalog)
        </span>
    `);
    row.find('.btn-row-pick-sku .btn-search-text').text('Cari di SKU');
    row.find('.btn-row-toggle-type .btn-toggle-text').text('Jadikan Katalog');

    if (!customDesc) {
        row.find('.input-description').focus();
    } else {
        row.find('.input-qty').focus();
    }

    updateTotals();
}

// Open SKU Search Modal
function openSkuModal(targetRowId = null) {
    activeModalTargetRowId = targetRowId;
    $('#skuSearchModal').removeClass('hidden');

    let initialQuery = '';
    if (targetRowId) {
        const row = $(`#lineItemsBody tr[data-row-id="${targetRowId}"]`);
        const desc = row.find('.input-description').val().trim();
        if (desc && desc.length < 40) {
            initialQuery = desc;
        }
    }

    $('#skuSearchInput').val(initialQuery);
    loadSkuItems(initialQuery);
    setTimeout(() => $('#skuSearchInput').focus().select(), 100);
}

// Close SKU Search Modal
function closeSkuModal() {
    $('#skuSearchModal').addClass('hidden');
    activeModalTargetRowId = null;
    if (skuSearchXhr) {
        skuSearchXhr.abort();
    }
}

// Load SKU Items via AJAX
function loadSkuItems(query) {
    query = (query || '').trim();
    $('#skuSearchStatus').html('<span class="text-blue-700 font-semibold"><i class="fas fa-spinner fa-spin mr-1"></i> Memuat katalog SKU...</span>');
    $('#btnClearSkuSearch').toggleClass('hidden', query === '');

    if (skuSearchXhr) {
        skuSearchXhr.abort();
    }

    skuSearchXhr = $.getJSON('ajax_sku_search.php', { q: query, limit: 30 }, function(res) {
        if (res.status === 'success') {
            renderSkuResults(res.results, query);
        } else {
            $('#skuSearchResultsContainer').html('<div class="p-6 text-center text-rose-600 text-xs font-semibold">Gagal memuat katalog: ' + escapeHtml(res.message) + '</div>');
        }
    }).fail(function(jqXHR, textStatus) {
        if (textStatus === 'abort') return;
        $('#skuSearchResultsContainer').html('<div class="p-6 text-center text-rose-600 text-xs font-semibold">Terjadi kendala jaringan saat menghubungi katalog SKU.</div>');
    });
}

// Render Results inside Modal
function renderSkuResults(items, query) {
    const container = $('#skuSearchResultsContainer');
    container.empty();

    if (!items || items.length === 0) {
        $('#skuSearchStatus').text('0 item ditemukan');
        const emptyHtml = `
            <div class="text-center py-10 px-4 bg-white rounded-xl border border-dashed border-slate-200">
                <div class="w-12 h-12 mx-auto rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-lg mb-3 border border-amber-200">
                    <i class="fas fa-search"></i>
                </div>
                <h4 class="text-sm font-bold text-slate-800 mb-1">Item Tidak Ditemukan di SKU Dictionary</h4>
                <p class="text-xs text-slate-500 max-w-md mx-auto mb-4">
                    Tidak ditemukan katalog untuk "${escapeHtml(query)}". Anda dapat langsung mengajukan permintaan sebagai <strong>Item Baru (Non-Katalog)</strong>.
                </p>
                <button type="button" class="btn-create-new-from-query px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-xs font-bold rounded-xl shadow-sm inline-flex items-center gap-1.5 transition">
                    <i class="fas fa-plus text-[10px]"></i> Ajukan "${escapeHtml(query)}" Sebagai Item Baru
                </button>
            </div>
        `;
        container.html(emptyHtml);
        return;
    }

    $('#skuSearchStatus').text(items.length + (query ? ' item ditemukan untuk "' + query + '"' : ' item terdaftar'));

    items.forEach(function(item) {
        const catBadge = item.category && item.category !== 'General' 
            ? `<span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-slate-100 text-slate-600 border border-slate-200">${escapeHtml(item.category)}</span>` 
            : '';

        const card = `
            <div class="sku-result-item bg-white p-3.5 rounded-xl border border-slate-200 hover:border-blue-400 hover:shadow-sm transition flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                <div class="space-y-1 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-blue-50 text-blue-700 border border-blue-200">
                            SKU: ${escapeHtml(item.sku)}
                        </span>
                        ${catBadge}
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">
                            UoM: ${escapeHtml(item.uom)}
                        </span>
                    </div>
                    <h4 class="text-xs font-bold text-slate-900 leading-snug">
                        ${escapeHtml(item.detail)}
                    </h4>
                    <div class="text-xs font-semibold text-emerald-700 flex items-center gap-1">
                        <span class="text-slate-400 text-[11px] font-normal">Est. Harga Satuan:</span>
                        <span>${escapeHtml(item.est_price_formatted)}</span>
                    </div>
                </div>
                <div class="sm:flex-shrink-0">
                    <button type="button" class="btn-select-sku-item w-full sm:w-auto px-4 py-2 bg-blue-700 hover:bg-blue-800 text-white text-xs font-bold rounded-xl shadow-sm transition flex items-center justify-center gap-1.5"
                        data-sku="${escapeHtml(item.sku)}"
                        data-detail="${escapeHtml(item.detail)}"
                        data-uom="${escapeHtml(item.uom)}"
                        data-price="${item.est_price}">
                        <i class="fas fa-check text-[10px]"></i> Pilih Item
                    </button>
                </div>
            </div>
        `;
        container.append(card);
    });
}

// Request New Item From Modal Action
function handleRequestNewItemFromModal() {
    const query = $('#skuSearchInput').val().trim();

    if (activeModalTargetRowId) {
        applyNewItemToRow(activeModalTargetRowId, query);
    } else {
        addLineItemRow({
            item_type: 'new_item',
            sku: 'NEW-ITEM',
            description: query
        });
    }

    closeSkuModal();
}

$(document).ready(function() {
    // Populate items: either from POST validation return or single blank row
    if (oldItems && oldItems.length > 0) {
        oldItems.forEach(item => addLineItemRow(item));
    } else {
        addLineItemRow();
    }

    // Header buttons
    $('#btnOpenSkuModalHeader').on('click', function() {
        openSkuModal(null);
    });

    $('#btnAddNewItemHeader').on('click', function() {
        addLineItemRow({
            item_type: 'new_item',
            sku: 'NEW-ITEM',
            description: ''
        });
    });

    // Row-level buttons
    $('#lineItemsBody').on('click', '.btn-row-pick-sku', function() {
        const rowId = $(this).closest('tr').data('row-id');
        openSkuModal(rowId);
    });

    $('#lineItemsBody').on('click', '.btn-row-toggle-type', function() {
        const row = $(this).closest('tr');
        const rowId = row.data('row-id');
        const currentType = row.find('.input-item-type').val();

        if (currentType === 'new_item') {
            openSkuModal(rowId);
        } else {
            applyNewItemToRow(rowId);
        }
    });

    // Remove row button
    $('#lineItemsBody').on('click', '.btn-remove-row', function() {
        if ($('#lineItemsBody tr').length <= 1) {
            alert('PR minimal harus memiliki 1 baris kebutuhan barang/jasa.');
            return;
        }
        $(this).closest('tr').remove();
        updateTotals();
    });

    // Qty or Price live change
    $('#lineItemsBody').on('input', '.input-qty, .input-price', function() {
        updateTotals();
    });

    // Modal Events
    $('#btnCloseSkuModal, #skuModalBackdrop').on('click', closeSkuModal);

    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && !$('#skuSearchModal').hasClass('hidden')) {
            closeSkuModal();
        }
    });

    $('#skuSearchInput').on('input', function() {
        clearTimeout(skuSearchDebounce);
        const val = $(this).val();
        skuSearchDebounce = setTimeout(() => loadSkuItems(val), 220);
    });

    $('#btnClearSkuSearch').on('click', function() {
        $('#skuSearchInput').val('').focus();
        loadSkuItems('');
    });

    // Select SKU Item from Modal
    $('#skuSearchResultsContainer').on('click', '.btn-select-sku-item', function() {
        const sku    = $(this).data('sku');
        const detail = $(this).data('detail');
        const uom    = $(this).data('uom');
        const price  = $(this).data('price');

        const item = { sku, detail, uom, price };

        if (activeModalTargetRowId) {
            applySkuToRow(activeModalTargetRowId, item);
        } else {
            addLineItemRow({
                item_type: 'catalogue',
                sku: sku,
                description: detail,
                uom: uom,
                baseline_price: price
            });
        }

        closeSkuModal();
    });

    $('#btnRequestNewItemFromModal').on('click', handleRequestNewItemFromModal);
    $('#skuSearchResultsContainer').on('click', '.btn-create-new-from-query', handleRequestNewItemFromModal);

    // PGroup live preview
    function updatePgroupPreview() {
        const pg = $('#pGroupSelect').val();
        if (pg && pgroupMap[pg]) {
            const info = pgroupMap[pg];
            $('#picPreviewName').text(info.pic_name || 'Unassigned / Sourcing Queue');
            $('#picPreviewTeam').text(info.team_name || 'Tim Sourcing');
            $('#picPreviewBox').removeClass('hidden');
        } else {
            $('#picPreviewBox').addClass('hidden');
        }
    }
    $('#pGroupSelect').on('change', updatePgroupPreview);
    updatePgroupPreview();

    // Ticket Number Check via AJAX
    let ticketCheckTimeout = null;
    function checkTicketAvailability(ticket) {
        if (!ticket || ticket.length < 4) {
            $('#ticketCheckFeedback').html('');
            return;
        }

        $('#ticketCheckFeedback').html('<span class="text-slate-400"><i class="fas fa-spinner fa-spin mr-1"></i> Memeriksa tiket...</span>');

        $.getJSON('ajax_check_ticket.php', { ticket_no: ticket }, function(res) {
            if (res.exists) {
                $('#ticketCheckFeedback').html('<span class="text-rose-600 font-bold"><i class="fas fa-times-circle mr-1"></i> Nomor tiket sudah ada di sistem. Silakan ganti.</span>');
            } else {
                $('#ticketCheckFeedback').html('<span class="text-emerald-600 font-bold"><i class="fas fa-check-circle mr-1"></i> Nomor tiket tersedia.</span>');
            }
        }).fail(function() {
            $('#ticketCheckFeedback').html('');
        });
    }

    $('#ticketNoInput').on('input', function() {
        clearTimeout(ticketCheckTimeout);
        const val = $(this).val().trim();
        ticketCheckTimeout = setTimeout(() => checkTicketAvailability(val), 400);
    });

    // Auto-generate button
    $('#btnGenTicket').on('click', function() {
        const now = new Date();
        const yy = String(now.getFullYear()).slice(-2);
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const rand = Math.floor(1000 + Math.random() * 9000);
        const gen = 'PR' + yy + mm + rand;
        $('#ticketNoInput').val(gen);
        checkTicketAvailability(gen);
    });

    // Form submission validation
    $('#prForm').on('submit', function(e) {
        const count = $('#lineItemsBody tr').length;
        if (count === 0) {
            e.preventDefault();
            alert('Minimal harus memiliki 1 baris kebutuhan barang/jasa.');
            return false;
        }

        const btn = $('#btnSubmitPr');
        btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin mr-2"></i> Menyimpan PR...');
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
