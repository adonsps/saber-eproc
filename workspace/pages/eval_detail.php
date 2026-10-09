<?php
// pages/eval_detail.php
require_once '../includes/auth.php';

// Pastikan session terbaca
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. RBAC: Izinkan Manager, Category Manager, PIC, dan Admin
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['Category Manager', 'Manager', 'PIC', 'Admin'])) {
    header("Location: login.php");
    exit;
}

require_once '../config/database.php';
require_once '../includes/header.php';

$po = $_GET['po'] ?? '';

// Fetch all evaluations for this PO
$stmt = $pdo->prepare("SELECT * FROM vendor_evaluations WHERE po_number = ?");
$stmt->execute([$po]);
$evals = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$evals) die("Evaluation not found.");

// Calculate the Overall Average
$sum_scores = 0;
foreach ($evals as $e) { $sum_scores += $e['total_score']; }
$avg_score = $sum_scores / count($evals);
?>

<div class="max-w-5xl mx-auto py-8 px-4">
    <div class="mb-6 flex items-center justify-between border-b pb-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">PO Performance: <span class="text-indigo-600"><?= htmlspecialchars($po) ?></span></h2>
            <p class="text-gray-500 text-sm">Vendor: <strong><?= htmlspecialchars($evals[0]['vendor_name']) ?></strong></p>
        </div>
        <div class="flex items-center space-x-4">
            <div class="text-right">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wide">Overall Average</p>
                <p class="text-3xl font-black text-green-600"><?= number_format($avg_score, 2) ?></p>
            </div>
            <a href="eval_monitor.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded shadow h-10 flex items-center">&larr; Back</a>
        </div>
    </div>

    <div class="space-y-8">
        <?php foreach ($evals as $eval): ?>
        <div class="bg-white rounded-lg shadow-md border border-gray-200 overflow-hidden">
            
            <div class="bg-indigo-50 border-b border-indigo-100 px-6 py-4 flex justify-between items-center">
                <div>
                    <span class="text-xs font-bold text-indigo-400 uppercase tracking-wider">Evaluated By</span>
                    <h3 class="text-lg font-bold text-indigo-900"><?= htmlspecialchars($eval['requestor_name']) ?></h3>
                </div>
                <div class="text-right">
                    <span class="text-2xl font-bold text-indigo-700"><?= number_format($eval['total_score'], 2) ?></span>
                    <span class="text-sm text-gray-500">/ 4.00</span>
                </div>
            </div>

            <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-1 border-r border-gray-100 pr-4">
                    <p class="text-sm mb-2"><span class="font-bold text-gray-500 uppercase">Location:</span> <br><?= htmlspecialchars($eval['location']) ?></p>
                    <p class="text-sm mb-4"><span class="font-bold text-gray-500 uppercase">Duration:</span> <br><?= htmlspecialchars($eval['work_duration']) ?></p>
                    <p class="text-xs text-gray-400 mt-6 pt-4 border-t">Submitted:<br><?= date('d M Y, H:i', strtotime($eval['evaluation_date'])) ?></p>
                </div>

                <div class="md:col-span-2 space-y-2">
                    <?php
                    $criteria = [
                        ['Creative Quality', $eval['score_creative'], '20%'],
                        ['Engagement', $eval['score_engagement'], '30%'],
                        ['Execution', $eval['score_execution'], '15%'],
                        ['Reach', $eval['score_reach'], '15%'],
                        ['Professionalism', $eval['score_professionalism'], '10%'],
                        ['Reporting', $eval['score_reporting'], '10%']
                    ];
                    foreach ($criteria as $c): ?>
                    <div class="flex justify-between items-center border-b border-gray-100 pb-2">
                        <span class="text-sm font-medium text-gray-700"><?= $c[0] ?> <span class="text-[10px] text-gray-400 ml-1">(<?= $c[2] ?>)</span></span>
                        <span class="font-bold text-sm text-gray-800 bg-gray-100 px-2 py-1 rounded"><?= $c[1] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>