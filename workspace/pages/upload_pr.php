<?php
// pages/upload_pr.php
require_once '../includes/auth.php';
require_manager();
require_once '../includes/header.php';
require_once '../includes/excel_parser.php';

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['pr_file'])) {
    $file = $_FILES['pr_file'];

    if ($file['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        if (strtolower($ext) === 'csv') {
            $importedBy = $_SESSION['username'] ?? ($_SESSION['name'] ?? null);
            $results = parse_and_insert_csv($file['tmp_name'], $file['name'], $importedBy);
            
            if (isset($results['error'])) {
                 $message = "Error parsing file: " . htmlspecialchars($results['error']);
                 $messageType = "error";
            } else {
                 $message = "<strong>Database Sync Complete:</strong> Inserted {$results['inserted']} new lines, updated {$results['updated']} lines, and skipped {$results['skipped']} unchanged lines.<br><br>";
                 if (!empty($results['warning_count'])) {
                     $message .= "<span class='text-amber-700 font-bold'>Validation warnings:</span> {$results['warning_count']} warning(s) logged for review.";
                     if (!empty($results['warnings_truncated'])) {
                         $message .= " Stored first {$results['warnings_stored']} warning details.";
                     }
                     $message .= "<br><br>";
                 }
                 if (isset($results['import_batch_logged']) && !$results['import_batch_logged']) {
                     $message .= "<span class='text-amber-700 font-bold'>Import batch log warning:</span> The import completed, but the batch summary could not be written.<br><br>";
                 }
                 $message .= "<span class='text-indigo-700 font-bold'>► Ready for AI:</span> There are currently <strong>{$results['ai_tickets']} Tickets</strong> (comprising {$results['ai_lines']} Line Items) qualified and waiting for AI assignment.";
                 $messageType = "success";
            }
        } else {
            $message = "Invalid file type. Please upload a CSV file.";
            $messageType = "error";
        }
    } else {
        $message = "Error uploading file. Error code: " . $file['error'];
        $messageType = "error";
    }
}
?>

<div class="max-w-3xl mx-auto py-8">
    <div class="mb-6">
        <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow inline-block">&larr; Back</a>
    </div>

    <h2 class="text-2xl font-bold mb-6 text-gray-800">Upload Purchase Requests (PR)</h2>

    <?php if ($message): ?>
        <div class="p-4 mb-6 rounded-lg font-medium <?= $messageType === 'success' ? 'bg-green-100 text-green-800 border-l-4 border-green-500' : 'bg-red-100 text-red-800 border-l-4 border-red-500' ?>">
            <?= $message ?>
        </div>
    <?php endif; ?>

    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Select CSV File (from Temp Daily K2)</label>
                <div class="mt-1 flex justify-center px-6 pt-5 pb-6 border-2 border-gray-300 border-dashed rounded-md hover:border-indigo-500 transition-colors bg-gray-50">
                    <div class="space-y-1 text-center">
                        <svg class="mx-auto h-12 w-12 text-gray-400" stroke="currentColor" fill="none" viewBox="0 0 48 48"><path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>
                        <div class="flex text-sm text-gray-600 justify-center">
                            <label for="file-upload" class="relative cursor-pointer bg-white rounded-md font-medium text-indigo-600 hover:text-indigo-500 focus-within:outline-none focus-within:ring-2 focus-within:ring-offset-2 focus-within:ring-indigo-500">
                                <span>Upload a file</span>
                                <input id="file-upload" name="pr_file" type="file" class="sr-only" accept=".csv" required>
                            </label>
                            <p class="pl-1">or drag and drop</p>
                        </div>
                        <p class="text-xs text-gray-500">CSV files only. Make sure headers match the expected format.</p>
                    </div>
                </div>
            </div>
            <div class="flex items-center justify-between mt-8">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow transition duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    Upload & Parse
                </button>
                <a href="auto_assign.php" class="text-indigo-600 hover:text-indigo-900 font-medium hover:underline">
                    Proceed to AI Assignment &rarr;
                </a>
            </div>
        </form>
    </div>
</div>

<script>
    document.getElementById('file-upload').addEventListener('change', function(e) {
        var fileName = e.target.files[0].name;
        var label = e.target.closest('.text-center').querySelector('p.pl-1');
        label.textContent = "Selected: " + fileName;
        label.classList.add('text-indigo-600', 'font-bold');
    });
</script>

<?php require_once '../includes/footer.php'; ?>
