<?php
// pages/evaluations.php
require_once '../includes/auth.php';
require_login(); 
require_once '../config/database.php';
require_once '../includes/header.php';

$current_user = $_SESSION['name'];
$role = $_SESSION['role'];

// --- HANDLE FORM SUBMISSION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_number'])) {
    $po_number = $_POST['po_number'];
    $vendor_name = $_POST['vendor_name'];
    $requestor_name = $_POST['requestor_name'];
    $location = $_POST['location'];
    $work_duration = $_POST['work_duration'];
    
    $q1 = (int)$_POST['q1'];
    $q2 = (int)$_POST['q2'];
    $q3 = (int)$_POST['q3'];
    $q4 = (int)$_POST['q4'];
    $q5 = (int)$_POST['q5'];
    $q6 = (int)$_POST['q6'];
    
    // Calculate Weighted Total Score (Max 4.0)
    $total_score = ($q1 * 0.20) + ($q2 * 0.30) + ($q3 * 0.15) + ($q4 * 0.15) + ($q5 * 0.10) + ($q6 * 0.10);

    $stmt = $pdo->prepare("
        INSERT INTO vendor_evaluations 
        (po_number, vendor_name, requestor_name, location, work_duration, score_creative, score_engagement, score_execution, score_reach, score_professionalism, score_reporting, total_score)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$po_number, $vendor_name, $requestor_name, $location, $work_duration, $q1, $q2, $q3, $q4, $q5, $q6, $total_score]);
    
    header("Location: evaluations.php?success=1");
    exit;
}

// --- FETCH PENDING EVALUATIONS ---
// Logic: GR Date is today or earlier AND PO is not in the vendor_evaluations table.
// Group by PO to combine multiple tickets into one evaluation.
$query = "
    SELECT 
        p.po_number, p.vendor_name, p.requestor_name, MAX(p.gr_date) as gr_date,
        GROUP_CONCAT(DISTINCT p.ticket_no SEPARATOR ', ') as tickets,
        GROUP_CONCAT(DISTINCT p.item_description SEPARATOR '||') as items,
        SUM(p.qty) as total_qty
    FROM prs p
    LEFT JOIN vendor_evaluations v ON p.po_number = v.po_number
    WHERE p.po_number IS NOT NULL AND p.po_number != ''
      AND p.vendor_name IS NOT NULL AND p.vendor_name != ''
      AND p.gr_date IS NOT NULL AND p.gr_date <= CURDATE()
      AND v.id IS NULL
";

// If standard PIC, only show POs where they handled at least one of the tickets
if ($role === 'PIC') {
    $query .= " AND p.assigned_pic = ?";
    $stmt = $pdo->prepare($query . " GROUP BY p.po_number, p.vendor_name, p.requestor_name");
    $stmt->execute([$current_user]);
} else {
    // Managers see all pending evaluations
    $stmt = $pdo->query($query . " GROUP BY p.po_number, p.vendor_name, p.requestor_name");
}
$pending_evals = $stmt->fetchAll();
?>

<div class="mb-6 flex justify-between items-end border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">Vendor Performance Evaluations</h2>
        <p class="text-gray-600">POs ready for evaluation based on completed Goods Receipt (GR) dates.</p>
    </div>
    <div class="flex space-x-3">
        <a href="<?= $role === 'PIC' ? 'dashboard_pic.php' : 'dashboard_mgr.php' ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow">&larr; Back to Dashboard</a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Evaluation Submitted!</p>
        <p>The vendor score has been successfully recorded in the database.</p>
    </div>
<?php endif; ?>

<div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
    <?php if (count($pending_evals) === 0): ?>
        <div class="text-center py-10 text-gray-500">
            <svg class="mx-auto h-12 w-12 text-gray-400 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
            <p class="text-lg font-medium">All caught up!</p>
            <p>There are currently no pending vendor evaluations for received goods/services.</p>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($pending_evals as $eval): ?>
                <?php 
                    $items_list = explode('||', $eval['items']); 
                ?>
                <div class="border border-gray-200 rounded-lg shadow-sm hover:shadow-md transition bg-white overflow-hidden flex flex-col">
                    <div class="bg-yellow-50 border-b border-yellow-100 p-4">
                        <div class="flex justify-between items-start">
                            <div>
                                <span class="text-xs font-bold text-yellow-800 uppercase tracking-wider bg-yellow-200 px-2 py-1 rounded-full">Action Required</span>
                            </div>
                            <div class="text-right">
                                <p class="text-xs text-gray-500">GR Date: <strong class="text-gray-800"><?= htmlspecialchars($eval['gr_date']) ?></strong></p>
                            </div>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 mt-3 truncate" title="<?= htmlspecialchars($eval['vendor_name']) ?>"><?= htmlspecialchars($eval['vendor_name']) ?></h3>
                        <p class="text-sm font-mono text-indigo-600 font-semibold mt-1">PO: <?= htmlspecialchars($eval['po_number']) ?></p>
                    </div>
                    
                    <div class="p-4 flex-grow bg-gray-50">
                        <p class="text-xs text-gray-500 font-semibold mb-2 uppercase">Items Received (Total Qty: <?= htmlspecialchars($eval['total_qty']) ?>):</p>
                        <ul class="text-xs text-gray-700 list-disc pl-4 space-y-1 mb-4 h-24 overflow-y-auto">
                            <?php foreach ($items_list as $item): ?>
                                <li><?= htmlspecialchars($item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="text-xs text-gray-500">Tickets: <span class="font-mono"><?= htmlspecialchars($eval['tickets']) ?></span></p>
                        <p class="text-xs text-gray-500 mt-1">Requestor: <strong><?= htmlspecialchars($eval['requestor_name']) ?></strong></p>
                    </div>
                    
                    <div class="p-4 border-t border-gray-200 bg-white">
                        <button type="button" 
                                class="open-eval-modal w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded transition shadow-sm text-sm"
                                data-po="<?= htmlspecialchars($eval['po_number']) ?>"
                                data-vendor="<?= htmlspecialchars($eval['vendor_name']) ?>"
                                data-requestor="<?= htmlspecialchars($eval['requestor_name']) ?>"
                                data-items="<?= htmlspecialchars(json_encode($items_list)) ?>">
                            Fill Evaluation Form
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<div id="evalModal" class="fixed z-50 inset-0 hidden overflow-y-auto">
  <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
    <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity backdrop-blur-sm" id="modalOverlay"></div>
    <span class="hidden sm:inline-block sm:align-middle sm:h-screen">&#8203;</span>
    <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
      <form method="POST" action="">
          
          <div class="bg-indigo-700 px-6 py-4">
              <h3 class="text-xl font-bold text-white">Vendor Performance Evaluation Form</h3>
              <p class="text-indigo-200 text-sm mt-1">Evaluate performance based on quality, engagement, and execution.</p>
          </div>

          <div class="bg-gray-50 px-6 py-5 border-b border-gray-200">
              <div class="grid grid-cols-2 gap-4">
                  <input type="hidden" name="po_number" id="modal-po">
                  <input type="hidden" name="vendor_name" id="modal-vendor">
                  <input type="hidden" name="requestor_name" id="modal-requestor">
                  
                  <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase">Vendor Name</label>
                      <p class="font-semibold text-gray-900 text-lg" id="display-vendor"></p>
                  </div>
                  <div>
                      <label class="block text-xs font-bold text-gray-500 uppercase">PO Number</label>
                      <p class="font-mono text-indigo-700 font-bold text-lg" id="display-po"></p>
                  </div>
              </div>
              
              <div class="mt-4">
                  <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Deskripsi Pekerjaan / Items</label>
                  <div class="bg-white p-3 border border-gray-200 rounded text-sm text-gray-700 max-h-24 overflow-y-auto" id="display-items"></div>
              </div>

              <div class="grid grid-cols-2 gap-4 mt-4">
                  <div>
                      <label class="block text-xs font-bold text-gray-700 mb-1">Lokasi Pekerjaan *</label>
                      <input type="text" name="location" required class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                  </div>
                  <div>
                      <label class="block text-xs font-bold text-gray-700 mb-1">Jangka Waktu Pekerjaan *</label>
                      <input type="text" name="work_duration" required placeholder="e.g., 2 Weeks, 1 Month" class="w-full px-3 py-2 border border-gray-300 rounded focus:ring-indigo-500 focus:border-indigo-500 text-sm">
                  </div>
              </div>
          </div>

          <div class="bg-white px-6 py-6 space-y-6 max-h-[50vh] overflow-y-auto">
              
              <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
                  <h4 class="font-bold text-gray-900 mb-3">I. Creative Quality & Concept <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 20%</span></h4>
                  <div class="space-y-2 text-sm">
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q1" value="4" required class="mt-1 mr-3 text-indigo-600"> <span class="font-medium text-gray-800">4: Konsep sangat kreatif, kuat, dan highly engaging</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q1" value="3" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">3: Konsep baik, sesuai brand & menarik</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q1" value="2" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">2: Konsep cukup, namun kurang impactful / kurang kreatif</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q1" value="1" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">1: Konsep lemah, tidak menarik, tidak sesuai brand</span></label>
                  </div>
              </div>

              <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
                  <h4 class="font-bold text-gray-900 mb-3">II. Audience Engagement / Impact <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 30%</span></h4>
                  <div class="space-y-2 text-sm">
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q2" value="4" required class="mt-1 mr-3 text-indigo-600"> <span class="font-medium text-gray-800">4: Engagement tinggi, melebihi target secara signifikan</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q2" value="3" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">3: Engagement sesuai target</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q2" value="2" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">2: Engagement cukup, namun di bawah ekspektasi</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q2" value="1" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">1: Engagement sangat rendah, jauh dari target</span></label>
                  </div>
              </div>

              <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
                  <h4 class="font-bold text-gray-900 mb-3">III. Execution Quality (Delivery/Event) <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 15%</span></h4>
                  <div class="space-y-2 text-sm">
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q3" value="4" required class="mt-1 mr-3 text-indigo-600"> <span class="font-medium text-gray-800">4: Eksekusi sangat baik, smooth, tanpa issue & profesional</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q3" value="3" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">3: Eksekusi baik, sesuai brief</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q3" value="2" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">2: Eksekusi cukup, namun ada beberapa issue</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q3" value="1" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">1: Eksekusi buruk, banyak kesalahan / tidak sesuai brief</span></label>
                  </div>
              </div>

              <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
                  <h4 class="font-bold text-gray-900 mb-3">IV. Reach & Exposure <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 15%</span></h4>
                  <div class="space-y-2 text-sm">
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q4" value="4" required class="mt-1 mr-3 text-indigo-600"> <span class="font-medium text-gray-800">4: Reach sangat tinggi, jauh melebihi target</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q4" value="3" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">3: Reach sesuai target</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q4" value="2" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">2: Reach cukup, namun belum optimal</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q4" value="1" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">1: Reach sangat rendah, jauh dari target</span></label>
                  </div>
              </div>

              <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
                  <h4 class="font-bold text-gray-900 mb-3">V. Professionalism & Responsiveness <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 10%</span></h4>
                  <div class="space-y-2 text-sm">
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q5" value="4" required class="mt-1 mr-3 text-indigo-600"> <span class="font-medium text-gray-800">4: Sangat responsif, proaktif & solutif</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q5" value="3" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">3: Respons baik & kooperatif</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q5" value="2" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">2: Respons cukup, namun lambat / tidak konsisten</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q5" value="1" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">1: Tidak responsif, sulit koordinasi</span></label>
                  </div>
              </div>

              <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
                  <h4 class="font-bold text-gray-900 mb-3">VI. Reporting & Analytics <span class="text-indigo-600 text-xs bg-indigo-100 px-2 py-1 rounded ml-2">Bobot: 10%</span></h4>
                  <div class="space-y-2 text-sm">
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q6" value="4" required class="mt-1 mr-3 text-indigo-600"> <span class="font-medium text-gray-800">4: Laporan lengkap, insightful & ada rekomendasi improvement</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q6" value="3" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">3: Laporan lengkap & cukup informatif</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q6" value="2" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">2: Laporan standar, minim insight</span></label>
                      <label class="flex items-start cursor-pointer hover:bg-gray-100 p-2 rounded"><input type="radio" name="q6" value="1" class="mt-1 mr-3 text-indigo-600"> <span class="text-gray-700">1: Laporan tidak lengkap, tidak informatif, atau terlambat</span></label>
                  </div>
              </div>

          </div>
          
          <div class="bg-white px-6 py-4 border-t border-gray-200 flex sm:flex-row-reverse rounded-b-lg">
            <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-6 py-2 bg-indigo-600 text-base font-bold text-white hover:bg-indigo-700 sm:ml-3 sm:w-auto sm:text-sm">Submit Evaluation</button>
            <button type="button" id="closeModalBtn" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-6 py-2 bg-white text-base font-bold text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script>
    $(document).ready(function() {
        const modal = $('#evalModal');
        
        $('.open-eval-modal').on('click', function() {
            var po = $(this).data('po');
            var vendor = $(this).data('vendor');
            var requestor = $(this).data('requestor');
            var itemsArray = $(this).data('items'); // JSON array
            
            $('#modal-po').val(po);
            $('#modal-vendor').val(vendor);
            $('#modal-requestor').val(requestor);
            
            $('#display-po').text(po);
            $('#display-vendor').text(vendor);
            
            // Build the HTML list of items
            var itemsHtml = '<ul class="list-disc pl-5 space-y-1">';
            itemsArray.forEach(function(item) {
                itemsHtml += '<li>' + item + '</li>';
            });
            itemsHtml += '</ul>';
            $('#display-items').html(itemsHtml);
            
            // Uncheck all radios in case they closed it previously
            $('input[type="radio"]').prop('checked', false);
            $('input[name="location"]').val('');
            $('input[name="work_duration"]').val('');
            
            modal.removeClass('hidden');
        });

        $('#closeModalBtn, #modalOverlay').on('click', function() {
            modal.addClass('hidden');
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>