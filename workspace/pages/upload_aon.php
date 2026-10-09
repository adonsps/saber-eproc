<?php
// pages/upload_aon.php
// Upload two CSV files to populate the Master AON list:
//   1. Master Item Catalog (SKU, Item Name)
//   2. Price Per Vendor    (SKU, Vendor Name, Validity From/To, Contract Qty, Used Qty, Remain Qty, Price)
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';
require_once '../includes/header.php';

// Category Manager / Manager only -- narrower than aon_can_access(), which also
// admits STC. An STC still uses Master AON; the import that rewrites every
// contract field on it is not theirs to run. See includes/aon_helper.php.
$can_access = aon_can_upload($_SESSION['role'] ?? '');
$result     = null;
$error      = '';
$reminder   = null;   // aon_reminder_after_import() outcome, reported below

if ($can_access && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $catalog_file = $_FILES['catalog_file']  ?? null;
    $price_file   = $_FILES['price_file']    ?? null;

    $catalog_ok = $catalog_file && $catalog_file['error'] === UPLOAD_ERR_OK;
    $price_ok   = $price_file   && $price_file['error']   === UPLOAD_ERR_OK;

    if (!$catalog_ok || !$price_ok) {
        $error = 'Both files are required. Please select the Master Item Catalog and the Price Per Vendor CSV.';
    } else {
        $cat_ext   = strtolower(pathinfo($catalog_file['name'], PATHINFO_EXTENSION));
        $price_ext = strtolower(pathinfo($price_file['name'],   PATHINFO_EXTENSION));

        if ($cat_ext !== 'csv' || $price_ext !== 'csv') {
            $error = 'Both files must be CSV (.csv). Export each sheet to CSV format before uploading.';
        } else {
            require_once '../config/database.php';
            require_once '../includes/aon_parser.php';
            require_once '../includes/automation/aon_global_lock.php';
            $imported_by = $_SESSION['name'] ?? ($_SESSION['username'] ?? null);
            $aon_lock = null;
            try {
                $aon_lock = K2AonGlobalImportLock::acquire($pdo, 0);
                if (!$aon_lock->acquired()) {
                    $error = 'Another AON import is already running. Please retry after it finishes.';
                } else {
                    $result = parse_and_insert_dual_aon(
                        $catalog_file['tmp_name'],
                        $price_file['tmp_name'],
                        $imported_by
                    );
                    if (isset($result['error'])) {
                        $error  = $result['error'];
                        $result = null;
                    }
                }
            } finally {
                $aon_lock?->release();
            }

            // The reminder check runs on the data this import just wrote: a SKU
            // that only became low on quantity, or only entered its final month,
            // because of these files is chased within seconds instead of waiting
            // for a nightly job. Deliberately AFTER the lock is released and only
            // on a clean import — sending about rows a failed import never wrote
            // would be worse than sending nothing.
            //
            // aon_reminder_after_import() never throws and dedups per SKU per day
            // (see includes/aon_reminder.php), so the second and third upload of
            // the same day add nothing to the group chat unless the data changed.
            if ($result !== null) {
                require_once '../includes/aon_reminder.php';
                $reminder = aon_reminder_after_import($pdo, 'upload_aon');
            }
        }
    }
}
?>

<div class="max-w-3xl mx-auto py-8">
    <div class="mb-6">
        <a href="master_aon.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-indigo-700 transition">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
            Back to Master AON
        </a>
    </div>

    <h2 class="text-2xl font-bold mb-1 text-gray-800">Upload AON Data</h2>
    <p class="text-sm text-gray-500 mb-6">Upload both CSV files together. Rows are matched on SKU code; mismatches will be flagged automatically.</p>

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
            <p class="font-bold text-green-700 text-base mb-2">AON Import Complete</p>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <div class="bg-white rounded-lg border border-green-200 p-3 text-center">
                    <div class="text-2xl font-bold text-green-700"><?= $result['catalog_count'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Catalog SKUs</div>
                </div>
                <div class="bg-white rounded-lg border border-green-200 p-3 text-center">
                    <div class="text-2xl font-bold text-green-700"><?= $result['price_count'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Price File SKUs</div>
                </div>
                <div class="bg-white rounded-lg border border-green-200 p-3 text-center">
                    <div class="text-2xl font-bold text-green-700"><?= $result['total'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Valid AON SKUs</div>
                </div>
                <div class="bg-white rounded-lg border border-blue-200 p-3 text-center">
                    <div class="text-2xl font-bold text-blue-700"><?= $result['inserted'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Inserted</div>
                </div>
                <div class="bg-white rounded-lg border border-blue-200 p-3 text-center">
                    <div class="text-2xl font-bold text-blue-700"><?= $result['updated'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Updated</div>
                </div>
                <div class="bg-white rounded-lg border border-slate-200 p-3 text-center">
                    <div class="text-2xl font-bold text-slate-500"><?= $result['skipped'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Skipped</div>
                </div>
                <?php if (($result['removed'] ?? 0) > 0): ?>
                <div class="sm:col-span-3 bg-white rounded-lg border border-red-200 p-3 text-center">
                    <div class="text-2xl font-bold text-red-500"><?= $result['removed'] ?></div>
                    <div class="text-xs text-slate-500 mt-0.5">Removed (not in current AON list)</div>
                </div>
                <?php endif; ?>
            </div>

            <?php if (($result['dict_inserted'] ?? 0) + ($result['dict_updated'] ?? 0) > 0): ?>
            <div class="mt-3 p-3 rounded-lg bg-indigo-50 border border-indigo-200 text-indigo-800">
                <p class="font-bold text-indigo-700 text-sm mb-2">SKU Dictionary Updated</p>
                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div class="bg-white rounded-lg border border-indigo-200 p-3 text-center">
                        <div class="text-xl font-bold text-indigo-700"><?= $result['dict_inserted'] ?? 0 ?></div>
                        <div class="text-xs text-slate-500 mt-0.5">New SKUs added to Dictionary</div>
                    </div>
                    <div class="bg-white rounded-lg border border-indigo-200 p-3 text-center">
                        <div class="text-xl font-bold text-indigo-500"><?= $result['dict_updated'] ?? 0 ?></div>
                        <div class="text-xs text-slate-500 mt-0.5">Existing SKUs updated in Dictionary</div>
                    </div>
                </div>
                <p class="text-xs text-indigo-600 mt-2">
                    All <?= $result['catalog_count'] ?> catalog SKUs were synced. Taxonomy levels and mapping status were preserved.
                    <a href="sku_dictionary.php" class="font-semibold underline hover:text-indigo-800">View SKU Dictionary →</a>
                </p>
            </div>
            <?php endif; ?>

            <?php if ($result['dup_sku_count'] + $result['dup_name_count'] + $result['unmatched_count'] > 0): ?>
            <div class="mt-3 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm space-y-1">
                <p class="font-bold">Issues flagged — review in Master AON:</p>
                <?php if ($result['dup_sku_count']): ?>
                <p>• <strong><?= $result['dup_sku_count'] ?></strong> SKU(s) with conflicting item names (dup_sku)</p>
                <?php endif; ?>
                <?php if ($result['dup_name_count']): ?>
                <p>• <strong><?= $result['dup_name_count'] ?></strong> item name(s) mapped to multiple SKUs (dup_name)</p>
                <?php endif; ?>
                <?php if ($result['unmatched_count']): ?>
                <p>• <strong><?= $result['unmatched_count'] ?></strong> AON SKU(s) not found in Item Catalog — item name will show SKU code (unmatched_catalog)</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            // Show which column names were found in the Price CSV so the user can verify mappings
            $priceCols = $result['price_columns'] ?? [];
            $fieldMap = [
                'CONTRACT QTY'  => ['CONTRACT QTY','CONTRACT QUANTITY','QTY'],
                'USED QTY'      => ['USED QTY','USED QUANTITY'],
                'REMAIN QTY'    => ['REMAIN QTY','REMAINING QTY','REMAINING QUANTITY','REMAIN CONTRACT QUANTITY','REMAINING CONTRACT QUANTITY'],
                'PRICE'         => ['PRICE','NEGOTIATION PRICE','BUYING PRICE'],
                'ALOKASI'       => ['ALOKASI','ALLOCATION','ALOC'],
                'VENDOR'        => ['VENDOR NAME','VENDOR'],
                'VALIDITY FROM' => ['VALIDITY FROM','VALIDITY DATE','VALID FROM'],
                'VALIDITY TO'   => ['VALIDITY TO','VALIDITY UNTIL','VALID TO'],
            ];
            $matched = []; $missing = [];
            foreach ($fieldMap as $label => $aliases) {
                $found = null;
                foreach ($aliases as $a) { if (in_array($a, $priceCols, true)) { $found = $a; break; } }
                if ($found) $matched[$label] = $found;
                else $missing[] = $label;
            }
            ?>
            <?php if (!empty($priceCols)): ?>
            <div class="mt-3 p-3 rounded-lg bg-slate-50 border border-slate-200 text-slate-700 text-xs">
                <p class="font-bold text-slate-600 mb-2">Price CSV Column Mapping:</p>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-1.5">
                    <?php foreach ($matched as $label => $col): ?>
                    <div class="flex items-center gap-1">
                        <span class="text-green-600 font-bold">✓</span>
                        <span class="text-slate-500"><?= htmlspecialchars($label) ?>:</span>
                        <span class="font-mono font-semibold text-slate-700"><?= htmlspecialchars($col) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php foreach ($missing as $label): ?>
                    <div class="flex items-center gap-1">
                        <span class="text-amber-500 font-bold">—</span>
                        <span class="text-slate-400"><?= htmlspecialchars($label) ?>: not found</span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($reminder !== null): ?>
            <?php
                // What the import's own reminder check did. Shown because it is a
                // side effect the uploader cannot otherwise see — a message went
                // to a WhatsApp group off the back of the file they just chose.
                if ($reminder['due'] === 0) {
                    $rem_cls = 'bg-slate-50 border-slate-200 text-slate-600';
                    $rem_msg = 'No AON SKU crossed a reminder threshold — nothing was sent.';
                } elseif ($reminder['sent']) {
                    $rem_cls = 'bg-emerald-50 border-emerald-200 text-emerald-800';
                    $rem_msg = 'WhatsApp reminder sent to the STC group for <strong>' . (int)$reminder['due']
                             . '</strong> SKU' . ($reminder['due'] === 1 ? '' : 's') . '.';
                } elseif ($reminder['dry_run']) {
                    $rem_cls = 'bg-slate-50 border-slate-200 text-slate-600';
                    $rem_msg = '<strong>' . (int)$reminder['due'] . '</strong> SKU'
                             . ($reminder['due'] === 1 ? '' : 's') . ' due, but no WhatsApp group is configured — nothing was sent, and nothing was marked as reminded.';
                } else {
                    $rem_cls = 'bg-amber-50 border-amber-200 text-amber-800';
                    $rem_msg = '<strong>' . (int)$reminder['due'] . '</strong> SKU'
                             . ($reminder['due'] === 1 ? '' : 's') . ' due, but the WhatsApp send did not go through. They stay due and the next import will retry.';
                }
            ?>
            <div class="mt-3 p-3 rounded-lg border text-sm <?= $rem_cls ?>">
                <span class="font-bold">AON Reminder:</span> <?= $rem_msg ?>
            </div>
            <?php endif; ?>

            <div class="mt-4">
                <a href="master_aon.php" class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2 rounded-lg shadow text-sm transition">
                    View Master AON
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <div class="bg-white p-6 rounded-xl shadow-sm border border-slate-200">
            <form method="POST" enctype="multipart/form-data" class="space-y-6">

                <!-- File 1: Master Item Catalog -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">
                        1. Master Item Catalog <span class="text-red-500">*</span>
                    </label>
                    <p class="text-xs text-slate-500 mb-2">Required columns: <code>SKU</code>, <code>Item Name / Detail</code></p>
                    <div id="catalog_zone" class="file-drop-zone flex items-center justify-center px-6 pt-5 pb-6 border-2 border-dashed border-slate-300 rounded-lg hover:border-indigo-400 transition bg-slate-50 cursor-pointer" onclick="document.getElementById('catalog_file').click()">
                        <div id="catalog_empty" class="space-y-1 text-center">
                            <svg class="mx-auto h-10 w-10 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <p class="text-sm text-slate-500">Click to select or drag &amp; drop</p>
                            <p class="text-xs text-slate-400">CSV files only</p>
                        </div>
                        <div id="catalog_selected" class="hidden w-full">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0 w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <p id="catalog_filename" class="text-sm font-semibold text-slate-800 truncate"></p>
                                    <p id="catalog_filesize" class="text-xs text-slate-500 mt-0.5"></p>
                                </div>
                                <button type="button" onclick="clearFile('catalog'); event.stopPropagation();" class="flex-shrink-0 text-slate-400 hover:text-red-500 transition" title="Remove file">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                        </div>
                        <div id="catalog_wrong" class="hidden w-full">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0 w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <p id="catalog_wrong_msg" class="text-sm font-semibold text-red-700"></p>
                                    <p class="text-xs text-red-500 mt-0.5">Click to select the correct file</p>
                                </div>
                                <button type="button" onclick="clearFile('catalog'); event.stopPropagation();" class="flex-shrink-0 text-red-300 hover:text-red-600 transition" title="Remove file">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <input id="catalog_file" name="catalog_file" type="file" accept=".csv" required class="sr-only">
                </div>

                <!-- File 2: Price Per Vendor -->
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">
                        2. Price Per Vendor <span class="text-red-500">*</span>
                    </label>
                    <p class="text-xs text-slate-500 mb-2">Required columns: <code>SKU</code> — Optional: <code>Vendor Name</code>, <code>Validity From</code>, <code>Validity To</code>, <code>Contract Qty</code>, <code>Used Qty</code>, <code>Remain Qty</code>, <code>Price</code></p>
                    <div id="price_zone" class="file-drop-zone flex items-center justify-center px-6 pt-5 pb-6 border-2 border-dashed border-slate-300 rounded-lg hover:border-indigo-400 transition bg-slate-50 cursor-pointer" onclick="document.getElementById('price_file').click()">
                        <div id="price_empty" class="space-y-1 text-center">
                            <svg class="mx-auto h-10 w-10 text-slate-400" stroke="currentColor" fill="none" viewBox="0 0 48 48">
                                <path d="M28 8H12a4 4 0 00-4 4v20m32-12v8m0 0v8a4 4 0 01-4 4H12a4 4 0 01-4-4v-4m32-4l-3.172-3.172a4 4 0 00-5.656 0L28 28M8 32l9.172-9.172a4 4 0 015.656 0L28 28m0 0l4 4m4-24h8m-4-4v8m-12 4h.02" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <p class="text-sm text-slate-500">Click to select or drag &amp; drop</p>
                            <p class="text-xs text-slate-400">CSV files only</p>
                        </div>
                        <div id="price_selected" class="hidden w-full">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0 w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <p id="price_filename" class="text-sm font-semibold text-slate-800 truncate"></p>
                                    <p id="price_filesize" class="text-xs text-slate-500 mt-0.5"></p>
                                </div>
                                <button type="button" onclick="clearFile('price'); event.stopPropagation();" class="flex-shrink-0 text-slate-400 hover:text-red-500 transition" title="Remove file">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                        </div>
                        <div id="price_wrong" class="hidden w-full">
                            <div class="flex items-center gap-4">
                                <div class="flex-shrink-0 w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                                    <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path></svg>
                                </div>
                                <div class="flex-1 min-w-0 text-left">
                                    <p id="price_wrong_msg" class="text-sm font-semibold text-red-700"></p>
                                    <p class="text-xs text-red-500 mt-0.5">Click to select the correct file</p>
                                </div>
                                <button type="button" onclick="clearFile('price'); event.stopPropagation();" class="flex-shrink-0 text-red-300 hover:text-red-600 transition" title="Remove file">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <input id="price_file" name="price_file" type="file" accept=".csv" required class="sr-only">
                </div>

                <div class="flex items-center justify-between pt-2">
                    <button id="submit_btn" type="submit" disabled
                        class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded-lg shadow transition text-sm opacity-50 cursor-not-allowed">
                        Upload &amp; Import
                    </button>
                    <a href="master_aon.php" class="text-sm text-indigo-600 hover:underline font-medium">View Master AON →</a>
                </div>
            </form>
        </div>

    <?php endif; ?>
</div>

<script>
function formatBytes(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
}

// Read just the first 1 KB to detect column headers without loading the whole file
function readCsvHeaders(file, callback) {
    var reader = new FileReader();
    reader.onload = function (e) {
        var firstLine = e.target.result.split('\n')[0] || '';
        // Simple CSV split — handles quoted values at the header level
        var cols = [];
        var inQuote = false, cur = '';
        for (var i = 0; i < firstLine.length; i++) {
            var ch = firstLine[i];
            if (ch === '"') { inQuote = !inQuote; }
            else if (ch === ',' && !inQuote) { cols.push(cur.trim().toUpperCase()); cur = ''; }
            else { cur += ch; }
        }
        if (cur.trim()) cols.push(cur.trim().toUpperCase());
        callback(cols);
    };
    reader.readAsText(file.slice(0, 1024));
}

// Returns 'catalog', 'price', or 'unknown'
function detectFileType(cols) {
    var has = {};
    cols.forEach(function (c) { has[c] = true; });

    // Master Item Catalog — columns unique to the SAP master item export
    var catalogMarkers = ['GROUP OWNER', 'IS ACTIVE', 'IS REGULER', 'ARTICLE SAP',
                          'MAX DELIVERY', 'EXTEND TO GF', 'HASHTAG'];
    for (var i = 0; i < catalogMarkers.length; i++) {
        if (has[catalogMarkers[i]]) return 'catalog';
    }

    // Price Per Vendor — columns unique to the vendor price/contract export
    var priceMarkers = ['VENDOR CODE', 'VENDOR NAME', 'NEGOTIATION', 'AUTO CREATE',
                        'BUYING PRICE', 'VALIDITY FROM', 'VALIDITY TO'];
    // Require at least 2 matches to avoid false positives on generic column names
    var priceHits = 0;
    for (var j = 0; j < priceMarkers.length; j++) {
        if (has[priceMarkers[j]]) priceHits++;
    }
    if (priceHits >= 1) return 'price';

    return 'unknown';
}

var zoneValidity = { catalog: false, price: false };

function setZoneState(prefix, state, file, wrongMsg) {
    var zone     = document.getElementById(prefix + '_zone');
    var elEmpty  = document.getElementById(prefix + '_empty');
    var elOk     = document.getElementById(prefix + '_selected');
    var elWrong  = document.getElementById(prefix + '_wrong');

    // Hide all inner panels first
    elEmpty.classList.add('hidden');
    elOk.classList.add('hidden');
    elWrong.classList.add('hidden');
    zone.classList.remove(
        'border-slate-300', 'bg-slate-50', 'hover:border-indigo-400',
        'border-green-400', 'bg-green-50',
        'border-red-400',  'bg-red-50'
    );

    if (state === 'empty') {
        elEmpty.classList.remove('hidden');
        zone.classList.add('border-slate-300', 'bg-slate-50', 'hover:border-indigo-400');
        zoneValidity[prefix] = false;
    } else if (state === 'ok') {
        elOk.classList.remove('hidden');
        document.getElementById(prefix + '_filename').textContent = file.name;
        document.getElementById(prefix + '_filesize').textContent = formatBytes(file.size);
        zone.classList.add('border-green-400', 'bg-green-50');
        zoneValidity[prefix] = true;
    } else if (state === 'wrong') {
        elWrong.classList.remove('hidden');
        document.getElementById(prefix + '_wrong_msg').textContent = wrongMsg;
        zone.classList.add('border-red-400', 'bg-red-50');
        zoneValidity[prefix] = false;
    }

    updateSubmitButton();
}

function updateSubmitButton() {
    var btn = document.getElementById('submit_btn');
    if (!btn) return;
    var allOk = zoneValidity['catalog'] && zoneValidity['price'];
    btn.disabled = !allOk;
    btn.classList.toggle('opacity-50', !allOk);
    btn.classList.toggle('cursor-not-allowed', !allOk);
}

function handleFile(prefix, file) {
    var expectedLabel = prefix === 'catalog' ? 'Master Item Catalog' : 'Price Per Vendor';
    var oppositeLabel = prefix === 'catalog' ? 'Price Per Vendor'    : 'Master Item Catalog';

    readCsvHeaders(file, function (cols) {
        var detected = detectFileType(cols);

        if (detected === (prefix === 'catalog' ? 'price' : 'catalog')) {
            // Clearly the wrong file
            setZoneState(prefix, 'wrong', null,
                'This looks like a ' + oppositeLabel + ' file — please upload the ' + expectedLabel + ' here.');
        } else {
            // Correct file or unrecognized (we still allow unknown — server will validate)
            setZoneState(prefix, 'ok', file, null);
        }
    });
}

function clearFile(prefix) {
    var input = document.getElementById(prefix + '_file');
    input.value = '';
    setZoneState(prefix, 'empty', null, null);
}

function setupDropZone(prefix) {
    var zone  = document.getElementById(prefix + '_zone');
    var input = document.getElementById(prefix + '_file');

    input.addEventListener('change', function () {
        if (this.files.length) handleFile(prefix, this.files[0]);
    });

    zone.addEventListener('dragover', function (e) {
        e.preventDefault();
        if (!zoneValidity[prefix]) this.classList.add('border-indigo-400', 'bg-indigo-50');
    });
    zone.addEventListener('dragleave', function () {
        this.classList.remove('border-indigo-400', 'bg-indigo-50');
    });
    zone.addEventListener('drop', function (e) {
        e.preventDefault();
        this.classList.remove('border-indigo-400', 'bg-indigo-50');
        var files = e.dataTransfer.files;
        if (files.length) {
            var dt = new DataTransfer();
            dt.items.add(files[0]);
            input.files = dt.files;
            handleFile(prefix, files[0]);
        }
    });
}

setupDropZone('catalog');
setupDropZone('price');
updateSubmitButton();
</script>

<?php require_once '../includes/footer.php'; ?>
