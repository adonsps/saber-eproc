<?php
// pages/public_eval.php
require_once __DIR__ . '/../../includes/branding.php';
require_once '../config/database.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_GET['po']) || empty($_GET['po']) || !isset($_GET['req']) || empty($_GET['req'])) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Invalid Link</h2><p>PO Number or Requestor missing.</p></div>");
}

$po_number = $_GET['po'];
$req_name = $_GET['req'];

// Check if THIS SPECIFIC REQUESTOR already evaluated THIS PO
$checkStmt = $pdo->prepare("SELECT id FROM vendor_evaluations WHERE po_number = ? AND requestor_name = ?");
$checkStmt->execute([$po_number, $req_name]);
if ($checkStmt->fetch()) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#f0fdf4; color:#166534;'><h2>Thank You!</h2><p>You ($req_name) have already evaluated PO $po_number.</p></div>");
}

// Fetch ONLY the rows for this PO that belong to THIS Requestor
$stmt = $pdo->prepare("
    SELECT vendor_name, requestor_name, gr_date, ticket_no, item_description, site_allocation, delivery_date 
    FROM prs 
    WHERE po_number = ? AND requestor_name = ?
");
$stmt->execute([$po_number, $req_name]);
$pr_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$pr_rows || count($pr_rows) === 0) {
    die("<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Not Found</h2><p>No items found for this PO and Requestor combination.</p></div>");
}

$vendor_name = '';
$latest_gr_date = '';
$delivery_date = '';
$locations = [];
$items_list = [];

foreach ($pr_rows as $row) {
    if (empty($vendor_name) && !empty($row['vendor_name'])) $vendor_name = $row['vendor_name'];
    
    if (!empty($row['gr_date']) && $row['gr_date'] > $latest_gr_date) $latest_gr_date = $row['gr_date'];
    if (!empty($row['delivery_date']) && $row['delivery_date'] > $delivery_date) $delivery_date = $row['delivery_date'];
    
    if (!empty($row['site_allocation']) && !in_array($row['site_allocation'], $locations)) {
        $locations[] = $row['site_allocation'];
    }

    $t_no = !empty($row['ticket_no']) ? $row['ticket_no'] : 'Unknown';
    $i_desc = !empty($row['item_description']) ? $row['item_description'] : 'No Description';
    $item_gr = !empty($row['gr_date']) ? date('d M Y', strtotime($row['gr_date'])) : 'NOT YET GR';
    
    $item_text = "[{$t_no}] {$i_desc} (GR: {$item_gr})";
    if (!in_array($item_text, $items_list)) {
        $items_list[] = $item_text;
    }
}

$po_data = [
    'vendor_name' => $vendor_name,
    'requestor_name' => $req_name,
    'gr_date' => $latest_gr_date, 
    'site_allocation' => implode(', ', $locations),
    'delivery_date' => $delivery_date
];

// Handle Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $location = $_POST['location'];
    $work_duration = $_POST['work_duration'];
    $q1 = (int)$_POST['q1']; $q2 = (int)$_POST['q2']; $q3 = (int)$_POST['q3'];
    $q4 = (int)$_POST['q4']; $q5 = (int)$_POST['q5']; $q6 = (int)$_POST['q6'];
    
    $total_score = ($q1 * 0.20) + ($q2 * 0.30) + ($q3 * 0.15) + ($q4 * 0.15) + ($q5 * 0.10) + ($q6 * 0.10);
    $literal_jakarta_now = date('Y-m-d H:i:s'); 

    try {
        $insertStmt = $pdo->prepare("
            INSERT INTO vendor_evaluations 
            (po_number, vendor_name, requestor_name, location, work_duration, score_creative, score_engagement, score_execution, score_reach, score_professionalism, score_reporting, total_score, evaluation_date)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $insertStmt->execute([
            $po_number, 
            $po_data['vendor_name'], 
            $po_data['requestor_name'], 
            $location, 
            $work_duration, 
            $q1, $q2, $q3, $q4, $q5, $q6, 
            $total_score,
            $literal_jakarta_now 
        ]);
        
        die("<div style='font-family:sans-serif; text-align:center; padding:50px; background:#f0fdf4; color:#166534;'><h2>Evaluation Submitted!</h2><p>Thank you, $req_name. Your evaluation has been recorded.</p></div>");
        
    } catch (PDOException $e) {
        die("Submission error: " . htmlspecialchars($e->getMessage()));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Evaluation - <?= htmlspecialchars($po_number) ?></title>
    <?= vss_favicon_tags() ?>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 py-10 px-4">
    <div class="max-w-3xl mx-auto bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="bg-indigo-700 px-6 py-5">
            <h2 class="text-2xl font-bold text-white">Vendor Performance Evaluation</h2>
            <p class="text-indigo-200 mt-1">Please evaluate the vendor's performance for the completed items below.</p>
        </div>
        
        <div class="p-6 bg-gray-50 border-b border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div><span class="block text-xs font-bold text-gray-500 uppercase">Vendor Name</span><span class="font-bold text-gray-900"><?= htmlspecialchars($po_data['vendor_name']) ?></span></div>
                <div><span class="block text-xs font-bold text-gray-500 uppercase">PO Number</span><span class="font-mono text-indigo-700 font-bold"><?= htmlspecialchars($po_number) ?></span></div>
                <div><span class="block text-xs font-bold text-gray-500 uppercase">Requestor</span><span class="text-gray-800"><?= htmlspecialchars($po_data['requestor_name']) ?></span></div>
                <div><span class="block text-xs font-bold text-gray-500 uppercase">Latest GR Date</span><span class="text-gray-800"><?= htmlspecialchars(date('d M Y', strtotime($po_data['gr_date']))) ?></span></div>
            </div>
            <div class="mt-2">
                <span class="block text-xs font-bold text-gray-500 uppercase mb-2">Items Evaluated:</span>
                <ul class="list-none text-sm text-gray-700 space-y-2">
                    <?php foreach($items_list as $item): ?>
                        <?php 
                            if (strpos($item, 'NOT YET GR') !== false) {
                                echo "<li class='bg-red-50 border border-red-100 p-2 rounded'>" . htmlspecialchars($item) . "</li>";
                            } else {
                                echo "<li class='bg-white border border-gray-200 p-2 rounded'>" . htmlspecialchars($item) . "</li>";
                            }
                        ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>

        <form method="POST" action="" class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Lokasi Pekerjaan *</label>
                    <input type="text" name="location" value="<?= htmlspecialchars($po_data['site_allocation'] ?? '') ?>" required class="w-full px-3 py-2 border rounded focus:ring-indigo-500">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Jangka Waktu Pekerjaan *</label>
                    <input type="text" name="work_duration" value="<?= htmlspecialchars($po_data['delivery_date'] ?? '') ?>" required placeholder="e.g., 2 Weeks" class="w-full px-3 py-2 border rounded focus:ring-indigo-500">
                </div>
            </div>

            <div class="space-y-6">
                <div class="bg-gray-50 p-4 border rounded-lg">
                    <h4 class="font-bold text-gray-900 mb-2">I. Creative Quality & Concept <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 20%</span></h4>
                    <div class="space-y-2 text-sm flex flex-col">
                        <label><input type="radio" name="q1" value="4" required class="mr-2"> 4: Konsep sangat kreatif, kuat, dan highly engaging</label>
                        <label><input type="radio" name="q1" value="3" class="mr-2"> 3: Konsep baik, sesuai brand & menarik</label>
                        <label><input type="radio" name="q1" value="2" class="mr-2"> 2: Konsep cukup, namun kurang impactful / kurang kreatif</label>
                        <label><input type="radio" name="q1" value="1" class="mr-2"> 1: Konsep lemah, tidak menarik, tidak sesuai brand</label>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 border rounded-lg">
                    <h4 class="font-bold text-gray-900 mb-2">II. Audience Engagement / Impact <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 30%</span></h4>
                    <div class="space-y-2 text-sm flex flex-col">
                        <label><input type="radio" name="q2" value="4" required class="mr-2"> 4: Engagement tinggi, melebihi target signifikan</label>
                        <label><input type="radio" name="q2" value="3" class="mr-2"> 3: Engagement sesuai target</label>
                        <label><input type="radio" name="q2" value="2" class="mr-2"> 2: Engagement cukup, namun di bawah ekspektasi</label>
                        <label><input type="radio" name="q2" value="1" class="mr-2"> 1: Engagement sangat rendah, jauh dari target</label>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 border rounded-lg">
                    <h4 class="font-bold text-gray-900 mb-2">III. Execution Quality <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 15%</span></h4>
                    <div class="space-y-2 text-sm flex flex-col">
                        <label><input type="radio" name="q3" value="4" required class="mr-2"> 4: Eksekusi sangat baik, smooth, tanpa issue & profesional</label>
                        <label><input type="radio" name="q3" value="3" class="mr-2"> 3: Eksekusi baik, sesuai brief</label>
                        <label><input type="radio" name="q3" value="2" class="mr-2"> 2: Eksekusi cukup, namun ada beberapa issue</label>
                        <label><input type="radio" name="q3" value="1" class="mr-2"> 1: Eksekusi buruk, banyak kesalahan / tidak sesuai brief</label>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 border rounded-lg">
                    <h4 class="font-bold text-gray-900 mb-2">IV. Reach & Exposure <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 15%</span></h4>
                    <div class="space-y-2 text-sm flex flex-col">
                        <label><input type="radio" name="q4" value="4" required class="mr-2"> 4: Reach sangat tinggi, jauh melebihi target</label>
                        <label><input type="radio" name="q4" value="3" class="mr-2"> 3: Reach sesuai target</label>
                        <label><input type="radio" name="q4" value="2" class="mr-2"> 2: Reach cukup, namun belum optimal</label>
                        <label><input type="radio" name="q4" value="1" class="mr-2"> 1: Reach sangat rendah, jauh dari target</label>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 border rounded-lg">
                    <h4 class="font-bold text-gray-900 mb-2">V. Professionalism & Responsiveness <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 10%</span></h4>
                    <div class="space-y-2 text-sm flex flex-col">
                        <label><input type="radio" name="q5" value="4" required class="mr-2"> 4: Sangat responsif, proaktif & solutif</label>
                        <label><input type="radio" name="q5" value="3" class="mr-2"> 3: Respons baik & kooperatif</label>
                        <label><input type="radio" name="q5" value="2" class="mr-2"> 2: Respons cukup, lambat / tidak konsisten</label>
                        <label><input type="radio" name="q5" value="1" class="mr-2"> 1: Tidak responsif, sulit koordinasi</label>
                    </div>
                </div>
                <div class="bg-gray-50 p-4 border rounded-lg">
                    <h4 class="font-bold text-gray-900 mb-2">VI. Reporting & Analytics <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 10%</span></h4>
                    <div class="space-y-2 text-sm flex flex-col">
                        <label><input type="radio" name="q6" value="4" required class="mr-2"> 4: Laporan lengkap, insightful & ada rekomendasi</label>
                        <label><input type="radio" name="q6" value="3" class="mr-2"> 3: Laporan lengkap & cukup informatif</label>
                        <label><input type="radio" name="q6" value="2" class="mr-2"> 2: Laporan standar, minim insight</label>
                        <label><input type="radio" name="q6" value="1" class="mr-2"> 1: Laporan tidak lengkap, tidak informatif / terlambat</label>
                    </div>
                </div>
            </div>
            
            <div class="mt-8">
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 px-4 rounded-lg shadow transition text-lg">Submit Evaluation</button>
            </div>
        </form>
    </div>
</body>
</html>