<?php
// pages/upload_masterplan.php
// Imports the "Sourcing Masterplan – Data cost saving item AoN 2026" CSV.
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';
require_once '../includes/header.php';

$can_access = aon_can_access($_SESSION['role'] ?? '');
$result     = null;
$error      = '';

if ($can_access && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $file = $_FILES['masterplan_file'] ?? null;
    $ok   = $file && $file['error'] === UPLOAD_ERR_OK;

    if (!$ok) {
        $error = 'Please select the Sourcing Masterplan CSV file.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $error = 'File must be exported as CSV (.csv) before uploading.';
        } else {
            require_once '../config/database.php';
            require_once '../includes/aon_masterplan_parser.php';
            $imported_by = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);
            $result = parse_and_insert_masterplan($file['tmp_name'], $imported_by);
            if (isset($result['error'])) {
                $error  = $result['error'];
                $result = null;
            }
        }
    }
}
?>

<div class="max-w-2xl mx-auto py-8">
    <div class="mb-6">
        <a href="master_aon.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-indigo-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            Back to Master AON
        </a>
    </div>

    <h2 class="text-2xl font-bold mb-1 text-gray-800">Upload Sourcing Masterplan</h2>
    <p class="text-sm text-gray-500 mb-6">Import the <strong>Data cost saving item AoN 2026</strong> sheet. This populates item names, PIC/STC assignments, vendor, validity dates, and prices.</p>

    <?php if (!$can_access): ?>
        <div class="p-4 rounded-lg bg-red-100 text-red-800 border-l-4 border-red-500 font-medium">
            You do not have permission to upload AON data.
        </div>

    <?php else: ?>

        <?php if ($error): ?>
        <div class="p-4 mb-6 rounded-lg bg-red-100 text-red-800 border-l-4 border-red-500 font-medium">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($result): ?>
        <div class="p-5 mb-6 rounded-xl bg-green-50 border border-green-200 text-green-800 space-y-1">
            <p class="font-bold text-green-700 text-base mb-3">Masterplan Import Complete</p>
            <div class="grid grid-cols-3 gap-3 text-sm">
                <div class="bg-white rounded-lg border border-green-200 p-3 text-center">
                    <div class="text-2xl font-bold text-green-700"><?= $result['inserted'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Inserted</div>
                </div>
                <div class="bg-white rounded-lg border border-blue-200 p-3 text-center">
                    <div class="text-2xl font-bold text-blue-700"><?= $result['updated'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Updated</div>
                </div>
                <div class="bg-white rounded-lg border border-slate-200 p-3 text-center">
                    <div class="text-2xl font-bold text-slate-500"><?= $result['skipped'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Skipped (no SKU)</div>
                </div>
            </div>
            <div class="mt-4">
                <a href="master_aon.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2 rounded-lg shadow text-sm transition">
                    View Master AON
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">

            <!-- What this file contains -->
            <div class="mb-5 p-4 bg-indigo-50 border border-indigo-100 rounded-lg text-sm text-indigo-800">
                <p class="font-bold mb-1">Expected file format</p>
                <ul class="list-disc list-inside space-y-0.5 text-xs text-indigo-700">
                    <li>Sheet: <strong>Data cost saving item AoN 2026</strong>, exported as CSV</li>
                    <li>Row 1: main headers &mdash; Row 2: month sub-headers &mdash; data starts row 3</li>
                    <li>Required columns: <code>SKU</code>, <code>Item Name</code></li>
                    <li>Optional: <code>Category</code>, <code>Vendor</code>, <code>Validity Date</code>, <code>PIC STC/STT</code>, <code>Average Unit Price 2025</code>, <code>Unit Price 2026</code>, <code>Detail List Name</code></li>
                </ul>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-5">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">
                        Sourcing Masterplan CSV <span class="text-red-500">*</span>
                    </label>
                    <div id="mp_zone" class="flex items-center justify-center px-6 pt-5 pb-6 border-2 border-dashed border-slate-300 rounded-lg hover:border-indigo-400 transition bg-slate-50 cursor-pointer" onclick="document.getElementById('masterplan_file').click()">
                        <div id="mp_empty" class="space-y-1 text-center">
                            <svg class="mx-auto h-10 w-10 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <p class="text-sm text-slate-500">Click to select or drag &amp; drop</p>
                            <p class="text-xs text-slate-400">CSV files only</p>
                        </div>
                        <div id="mp_selected" class="hidden w-full">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0 w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <p id="mp_filename" class="text-sm font-semibold text-slate-800 truncate"></p>
                                    <p id="mp_filesize" class="text-xs text-slate-500 mt-0.5"></p>
                                </div>
                                <button type="button" onclick="clearMpFile(); event.stopPropagation();" class="flex-shrink-0 text-slate-400 hover:text-red-500 transition" title="Remove file">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <input id="masterplan_file" name="masterplan_file" type="file" accept=".csv" required class="sr-only">
                </div>

                <div class="flex items-center justify-between pt-1">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg shadow transition text-sm">
                        Import Masterplan
                    </button>
                    <a href="master_aon.php" class="text-sm text-indigo-600 hover:underline font-medium">View Master AON →</a>
                </div>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
(function () {
    function fmt(b) {
        if (b < 1024) return b + ' B';
        if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
        return (b/1048576).toFixed(1) + ' MB';
    }
    var zone  = document.getElementById('mp_zone');
    var input = document.getElementById('masterplan_file');

    function showFile(f) {
        document.getElementById('mp_empty').classList.add('hidden');
        document.getElementById('mp_selected').classList.remove('hidden');
        document.getElementById('mp_filename').textContent = f.name;
        document.getElementById('mp_filesize').textContent = fmt(f.size);
        zone.classList.remove('border-slate-300','bg-slate-50','hover:border-indigo-400');
        zone.classList.add('border-green-400','bg-green-50');
    }
    window.clearMpFile = function () {
        input.value = '';
        document.getElementById('mp_empty').classList.remove('hidden');
        document.getElementById('mp_selected').classList.add('hidden');
        zone.classList.remove('border-green-400','bg-green-50');
        zone.classList.add('border-slate-300','bg-slate-50','hover:border-indigo-400');
    };
    input.addEventListener('change', function () { if (this.files.length) showFile(this.files[0]); });
    zone.addEventListener('dragover',  function (e) { e.preventDefault(); this.classList.add('border-indigo-400','bg-indigo-50'); });
    zone.addEventListener('dragleave', function ()  { this.classList.remove('border-indigo-400','bg-indigo-50'); });
    zone.addEventListener('drop', function (e) {
        e.preventDefault();
        this.classList.remove('border-indigo-400','bg-indigo-50');
        var f = e.dataTransfer.files[0];
        if (f) { var dt = new DataTransfer(); dt.items.add(f); input.files = dt.files; showFile(f); }
    });
})();
</script>

<?php require_once '../includes/footer.php'; ?>
