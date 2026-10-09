<?php
// pages/dashboard_project.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/header.php';

date_default_timezone_set('Asia/Jakarta');
$user_role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// Strict Access Control: Only QS and Project Director
if (!in_array($user_role, ['QS', 'Project Director'])) {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak. Halaman ini khusus untuk Project Director dan QS.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

// --- FETCH DASHBOARD METRICS ---
// Total RFQs
$stmt_total = $pdo->query("SELECT COUNT(*) FROM rfqs");
$total_rfqs = $stmt_total->fetchColumn();

// Active RFQs (Sealed/Waiting)
$stmt_active = $pdo->query("SELECT COUNT(*) FROM rfqs WHERE status = 'Active'");
$active_rfqs = $stmt_active->fetchColumn();

// Unsealed / Processed RFQs
$stmt_unsealed = $pdo->query("SELECT COUNT(*) FROM rfqs WHERE status = 'Unsealed'");
$unsealed_rfqs = $stmt_unsealed->fetchColumn();

// Closed RFQs (Winner Selected)
$stmt_closed = $pdo->query("SELECT COUNT(*) FROM rfqs WHERE status = 'Closed'");
$closed_rfqs = $stmt_closed->fetchColumn();

// Fetch 5 most recent RFQs for the mini-table
$recent_rfqs = $pdo->query("SELECT id, package_name, status, deadline, created_by FROM rfqs ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="max-w-7xl mx-auto py-8">
<?php
$last_import = null;
try {
    date_default_timezone_set('Asia/Jakarta');
    $li = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'last_pr_upload_at'")->fetchColumn();
    if (!empty($li)) { $last_import = date('d M Y, H:i', strtotime($li)) . ' WIB'; }
} catch (PDOException $e) {}
?>
    <div class="mb-8 flex flex-col md:flex-row md:justify-between md:items-end gap-4 border-b pb-4">
        <div>
            <h2 class="text-3xl font-bold text-gray-800">Tender Command Center</h2>
            <p class="text-sm text-gray-600 mt-1">Selamat datang, <?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars($user_role) ?>).</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($last_import): ?>
            <div class="text-xs text-gray-500 bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 mr-2">
                <span class="font-bold text-gray-600 uppercase tracking-wider">Last updated:</span>
                <span class="font-medium text-gray-700"><?= htmlspecialchars($last_import) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($user_role === 'Project Director'): ?>
                <a href="rfq_create.php" class="bg-blue-600 hover:bg-blue-700 text-white text-xs md:text-sm font-bold py-2 px-4 rounded-lg shadow-sm transition">+ Buat RFQ</a>
            <?php endif; ?>
            
            <a href="rfq_dashboard.php" class="bg-sky-600 hover:bg-sky-700 text-white text-xs md:text-sm font-bold py-2 px-4 rounded-lg shadow-sm transition">Daftar Semua Tender</a>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200 border-l-4 border-l-gray-500">
            <div class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Total Tender</div>
            <div class="text-3xl font-black text-gray-800"><?= number_format($total_rfqs) ?></div>
        </div>
        <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200 border-l-4 border-l-blue-500">
            <div class="text-xs font-bold text-blue-500 uppercase tracking-wider mb-1">Tender Aktif (Sealed)</div>
            <div class="text-3xl font-black text-blue-700"><?= number_format($active_rfqs) ?></div>
        </div>
        <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200 border-l-4 border-l-green-500">
            <div class="text-xs font-bold text-green-500 uppercase tracking-wider mb-1">Sedang Evaluasi (Unsealed)</div>
            <div class="text-3xl font-black text-green-700"><?= number_format($unsealed_rfqs) ?></div>
        </div>
        <div class="bg-white rounded-lg shadow-md p-6 border border-gray-200 border-l-4 border-l-yellow-500">
            <div class="text-xs font-bold text-yellow-500 uppercase tracking-wider mb-1">Selesai (Winner Selected)</div>
            <div class="text-3xl font-black text-yellow-700"><?= number_format($closed_rfqs) ?></div>
        </div>
    </div>

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="text-lg font-bold text-gray-800">Aktivitas Tender Terakhir</h3>
            <a href="rfq_dashboard.php" class="text-sm font-bold text-indigo-600 hover:text-indigo-800">Lihat Semua &rarr;</a>
        </div>
        
        <?php if(empty($recent_rfqs)): ?>
            <p class="text-gray-500 text-sm italic py-4">Belum ada aktivitas tender.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th class="p-3 font-bold uppercase text-xs">Nama Tender</th>
                            <th class="p-3 font-bold uppercase text-xs">PIC / Kreator</th>
                            <th class="p-3 font-bold uppercase text-xs">Batas Waktu</th>
                            <th class="p-3 font-bold uppercase text-xs text-center">Status</th>
                            <th class="p-3 font-bold uppercase text-xs text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recent_rfqs as $r): 
                            $ui_status = $r['status'];
                            $badge = 'bg-gray-100 text-gray-800';
                            if ($ui_status === 'Active') $badge = 'bg-blue-100 text-blue-800';
                            if ($ui_status === 'Unsealed') $badge = 'bg-green-100 text-green-800';
                            if ($ui_status === 'Closed') $badge = 'bg-yellow-100 text-yellow-800';
                        ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 font-bold text-indigo-700"><?= htmlspecialchars($r['package_name']) ?></td>
                            <td class="p-3 text-gray-700"><?= htmlspecialchars($r['created_by']) ?></td>
                            <td class="p-3 text-gray-600 font-mono text-xs"><?= date('d F Y H:i', strtotime($r['deadline'])) ?> WIB</td>
                            <td class="p-3 text-center">
                                <span class="<?= $badge ?> px-2 py-1 rounded text-[10px] font-bold uppercase"><?= $ui_status ?></span>
                            </td>
                            <td class="p-3 text-right">
                                <a href="rfq_dashboard.php?id=<?= $r['id'] ?>" class="text-indigo-600 hover:text-indigo-800 font-bold text-xs bg-indigo-50 px-3 py-1.5 rounded border border-indigo-100">Buka Detail</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>