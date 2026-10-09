<?php
// pages/sku_dictionary.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';

$role = $_SESSION['role'] ?? '';
$current_user = $_SESSION['name'] ?? '';

// Only Category Manager gets the leading checkbox column, the Mapping Status
// column and the trailing Actions column, so every other role's column indexes
// shift. Mapping Status is an internal taxonomy-review state and only the
// Category Manager acts on it; for everyone else it was noise in the table and
// in the filter bar. Keep header, sorting and export in sync.
$is_category_manager = ($role === 'Category Manager');
$col_off = $is_category_manager ? 1 : 0;   // shift caused by the checkbox column
// Index of the first hidden column (sku): CM has 11 visible columns before it
// (checkbox, expand, detail, group owner, est price, AON rem qty, validity,
// taxonomy, is active, mapping status, actions); everyone else has 8.
$hidden_base = $is_category_manager ? 11 : 8;

// Handle POST actions for Category Manager
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'Category Manager') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];

        $isAjax = !empty($_POST['ajax']);
        
        if ($action === 'add') {
            $stmt = $pdo->prepare("
                INSERT INTO sku_dictionary 
                (sku, group_owner, area, detail, uom, est_price, buying_price, hashtag, article_sap, validity_from, validity_to, max_delivery_time_days, min_qty, warranty, extend_to_group, is_reguler, is_active, auto_create_po, modified_by, modified_date, level_0, level_1, level_2, level_3, level_4, mapping_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['sku'], $_POST['group_owner'], $_POST['area'], $_POST['detail'], $_POST['uom'],
                $_POST['est_price'] ?: null, $_POST['buying_price'] ?: null, $_POST['hashtag'], $_POST['article_sap'],
                $_POST['validity_from'] ?: null, $_POST['validity_to'] ?: null, $_POST['max_delivery_time_days'] ?: null,
                $_POST['min_qty'] ?: null, $_POST['warranty'], $_POST['extend_to_group'], $_POST['is_reguler'],
                $_POST['is_active'], $_POST['auto_create_po'], $current_user,
                $_POST['level_0'] ?: null, $_POST['level_1'] ?: null, $_POST['level_2'] ?: null, $_POST['level_3'] ?: null, $_POST['level_4'] ?: null, $_POST['mapping_status'] ?: null
            ]);
            if ($isAjax) { echo json_encode(['status' => 'success']); exit; }
            header("Location: sku_dictionary.php?success=add");
            exit;
        } elseif ($action === 'edit' && !empty($_POST['id'])) {
            $stmt = $pdo->prepare("
                UPDATE sku_dictionary SET 
                sku=?, group_owner=?, area=?, detail=?, uom=?, est_price=?, buying_price=?, hashtag=?, article_sap=?, validity_from=?, validity_to=?, max_delivery_time_days=?, min_qty=?, warranty=?, extend_to_group=?, is_reguler=?, is_active=?, auto_create_po=?, modified_by=?, modified_date=NOW(), level_0=?, level_1=?, level_2=?, level_3=?, level_4=?, mapping_status=?
                WHERE id=?
            ");
            $stmt->execute([
                $_POST['sku'], $_POST['group_owner'], $_POST['area'], $_POST['detail'], $_POST['uom'],
                $_POST['est_price'] ?: null, $_POST['buying_price'] ?: null, $_POST['hashtag'], $_POST['article_sap'],
                $_POST['validity_from'] ?: null, $_POST['validity_to'] ?: null, $_POST['max_delivery_time_days'] ?: null,
                $_POST['min_qty'] ?: null, $_POST['warranty'], $_POST['extend_to_group'], $_POST['is_reguler'],
                $_POST['is_active'], $_POST['auto_create_po'], $current_user,
                $_POST['level_0'] ?: null, $_POST['level_1'] ?: null, $_POST['level_2'] ?: null, $_POST['level_3'] ?: null, $_POST['level_4'] ?: null, $_POST['mapping_status'] ?: null,
                $_POST['id']
            ]);
            if ($isAjax) { echo json_encode(['status' => 'success']); exit; }
            header("Location: sku_dictionary.php?success=edit");
            exit;
        } elseif ($action === 'delete' && !empty($_POST['id'])) {
            $stmt = $pdo->prepare("DELETE FROM sku_dictionary WHERE id=?");
            $stmt->execute([$_POST['id']]);
            if ($isAjax) { echo json_encode(['status' => 'success']); exit; }
            header("Location: sku_dictionary.php?success=delete");
            exit;
        } elseif ($action === 'toggle_active' && !empty($_POST['id'])) {
            $new_status = $_POST['new_status'];
            $stmt = $pdo->prepare("UPDATE sku_dictionary SET is_active = ?, modified_by = ?, modified_date = NOW() WHERE id = ?");
            $stmt->execute([$new_status, $current_user, $_POST['id']]);
            if ($isAjax) { echo json_encode(['status' => 'success']); exit; }
            exit;
        }
    }
}

// Fetch Taxonomy lists for dropdowns
$taxonomy_levels = [];
try {
    for ($i = 0; $i <= 4; $i++) {
        $stmt = $pdo->query("SELECT DISTINCT level_{$i} FROM master_data WHERE status IN ('active', 'registered') AND level_{$i} IS NOT NULL AND level_{$i} != '' ORDER BY level_{$i} ASC");
        $taxonomy_levels["level_{$i}"] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    for ($i = 0; $i <= 4; $i++) $taxonomy_levels["level_{$i}"] = [];
}

// Option lists for the Group Owner / Area searchable dropdowns. Read from the
// dictionary itself (not master_data) so every value that can appear in the
// table is selectable and nothing else is.
$filter_options = ['group_owner' => [], 'area' => []];
try {
    foreach (array_keys($filter_options) as $col) {
        $stmt = $pdo->query("SELECT DISTINCT `{$col}` FROM sku_dictionary WHERE `{$col}` IS NOT NULL AND TRIM(`{$col}`) != '' ORDER BY `{$col}` ASC");
        $filter_options[$col] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (PDOException $e) {
    $filter_options = ['group_owner' => [], 'area' => []];
}

// Data is fetched via AJAX for Server-Side Processing

require_once '../includes/header.php';
?>

<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-extrabold text-slate-800 tracking-tight">SKU Dictionary</h1>
        <p class="text-sm text-slate-500 mt-1">Manage and view the complete SKU dictionary mapping.</p>
    </div>
    <div class="flex items-center space-x-3">
        <?php if ($role === 'Category Manager' || $role === 'Procurement Manager'): ?>
        <div class="relative group z-30">
            <button type="button" class="inline-flex items-center px-4 py-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-md shadow-sm transition focus:outline-none text-sm">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Export
                <svg class="w-4 h-4 ml-2 -mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
            </button>
            <div class="absolute right-0 mt-1 w-40 bg-white border border-gray-200 rounded-md shadow-lg hidden group-hover:block transition-all opacity-0 group-hover:opacity-100 overflow-hidden">
                <div class="py-1">
                    <button type="button" id="customExportExcel" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">Excel</button>
                    <button type="button" id="customExportCSV" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">CSV</button>
                    <button type="button" id="customExportPDF" class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 font-medium">PDF</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($role === 'Category Manager'): ?>
            <button type="button" onclick="openBulkStatusModal()" class="inline-flex items-center justify-center px-4 py-2 border border-blue-300 shadow-sm text-sm font-medium rounded-md text-blue-700 bg-blue-50 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                Bulk Update Status
            </button>
            <button type="button" onclick="processAIMapping()" class="inline-flex items-center justify-center px-4 py-2 border border-purple-300 shadow-sm text-sm font-medium rounded-md text-purple-700 bg-purple-50 hover:bg-purple-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                Process AI Mapping
            </button>
            <button type="button" onclick="openAddModal()" class="inline-flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Add New SKU
            </button>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Success!</p>
        <p>The SKU dictionary has been successfully updated.</p>
    </div>
<?php endif; ?>

<div class="relative w-full max-w-3xl mx-auto mb-8">
    <div class="relative group">
        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
            <svg class="h-6 w-6 text-indigo-400 group-focus-within:text-indigo-600 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>
        <input type="text" id="ecommerceSearch" class="block w-full pl-12 pr-4 py-4 border-2 border-indigo-100 rounded-full leading-5 bg-white shadow-sm placeholder-gray-400 focus:outline-none focus:border-indigo-500 focus:ring-0 sm:text-lg transition-all hover:shadow-md focus:shadow-lg" placeholder="Search item details (e.g., 'sapu', 'kertas', 'laptop')...">
    </div>
    <!-- Autocomplete Dropdown -->
    <div id="autocompleteDropdown" class="hidden absolute z-50 mt-2 w-full bg-white shadow-2xl max-h-80 rounded-2xl py-2 text-base ring-1 ring-black ring-opacity-5 overflow-auto focus:outline-none sm:text-sm border border-gray-100">
        <ul id="autocompleteList" class="divide-y divide-gray-50"></ul>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 p-4 mb-6">
    <h3 class="text-sm font-bold text-slate-700 mb-3">Custom Filters</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4 text-sm">
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Group Owner</label>
            <select id="filter_group_owner" class="filter-searchable w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($filter_options['group_owner'] as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Area</label>
            <select id="filter_area" class="filter-searchable w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($filter_options['area'] as $opt): ?>
                    <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1" title="Keeps SKUs whose Validity From is on or after this date">Validity From</label>
            <input type="date" id="filter_validity_from" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1" title="Keeps SKUs whose Validity To is on or before this date">Validity To</label>
            <input type="date" id="filter_validity_to" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500">
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Taxonomy Level 0</label>
            <select id="filter_level_0" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($taxonomy_levels['level_0'] as $lvl): ?>
                    <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($lvl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Taxonomy Level 1</label>
            <select id="filter_level_1" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($taxonomy_levels['level_1'] as $lvl): ?>
                    <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($lvl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Taxonomy Level 2</label>
            <select id="filter_level_2" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($taxonomy_levels['level_2'] as $lvl): ?>
                    <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($lvl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Taxonomy Level 3</label>
            <select id="filter_level_3" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($taxonomy_levels['level_3'] as $lvl): ?>
                    <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($lvl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Taxonomy Level 4</label>
            <select id="filter_level_4" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="">All</option>
                <?php foreach($taxonomy_levels['level_4'] as $lvl): ?>
                    <option value="<?= htmlspecialchars($lvl) ?>"><?= htmlspecialchars($lvl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($is_category_manager): ?>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1">Mapping Status</label>
            <select id="filter_mapping_status" class="w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500">
                <option value="">All</option>
                <option value="AI Mapped">AI Mapped</option>
                <option value="Need Review">Need Review</option>
                <option value="Empty">(Not Mapped Yet)</option>
            </select>
        </div>
        <?php endif; ?>
        <div>
            <label class="block text-xs font-semibold text-slate-500 mb-1" title="Active = flagged Is Active AND today is inside the validity window. Expired = flagged active but past its end date. Inactive = everything else. Pick more than one to combine them.">Is Active</label>
            <!-- Multi-select because the states are not a single ladder: cleaning
                 up the catalogue often means looking at Active + Expired at once.
                 Three states, and they cover the whole table — Inactive is the
                 remainder, so a row with no validity dates or one that hasn't
                 started yet is still reachable. Nothing selected = All. -->
            <select id="filter_is_active" multiple class="filter-searchable w-full border rounded p-2 focus:ring-indigo-500 focus:border-indigo-500 bg-white">
                <option value="Yes" selected>Active</option>
                <option value="Expired">Expired</option>
                <option value="No">Inactive</option>
            </select>
        </div>
    </div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="overflow-x-auto">
        <table id="skuTable" class="min-w-full divide-y divide-gray-200 text-left text-sm whitespace-nowrap">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <?php if ($is_category_manager): ?>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider w-8 text-center">
                        <input type="checkbox" id="selectAllSkus" class="focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">
                    </th>
                    <?php endif; ?>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider w-8"></th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">
                        <div class="flex flex-col">
                            <span class="cursor-pointer hover:text-indigo-600 transition" onclick="customSort(<?= $hidden_base + 1 ?>)">Detail ↕</span>
                            <span class="cursor-pointer hover:text-indigo-600 transition text-[10px] mt-0.5" onclick="customSort(<?= $hidden_base ?>)">SKU ↕</span>
                        </div>
                    </th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Group Owner</th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Est Price</th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider" title="Remaining contract quantity for this SKU on the Master AON list. Shows a dash when the SKU is not an AON item.">AON Rem. Qty</th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">
                        <div class="flex flex-col">
                            <span class="cursor-pointer hover:text-indigo-600 transition" onclick="customSort(<?= $hidden_base + 2 ?>)">Validity From ↕</span>
                            <span class="cursor-pointer hover:text-indigo-600 transition text-[10px] mt-0.5" onclick="customSort(<?= $hidden_base + 3 ?>)">Validity To ↕</span>
                        </div>
                    </th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">
                        <div class="flex flex-col">
                            <span class="cursor-pointer hover:text-indigo-600 transition" onclick="customSort(<?= $hidden_base + 4 ?>)">L3 ↕</span>
                            <span class="cursor-pointer hover:text-indigo-600 transition text-[10px] mt-0.5" onclick="customSort(<?= $hidden_base + 5 ?>)">L4 ↕</span>
                        </div>
                    </th>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Is Active</th>
                    <?php if ($is_category_manager): ?>
                    <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider">Mapping Status</th>
                    <?php endif; ?>
                    <?php if ($role === 'Category Manager'): ?>
                        <th class="px-3 py-3 text-xs font-bold text-gray-500 uppercase tracking-wider sticky right-0 bg-gray-50">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                <!-- Data populated by DataTables -->
            </tbody>
        </table>
    </div>
</div>

<?php if ($role === 'Category Manager'): ?>
<!-- Form Modal -->
<div id="skuFormModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeFormModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full">
            <form id="skuForm" method="POST" action="sku_dictionary.php">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="modalTitle">Add New SKU</h3>
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                        <div><label class="block text-xs font-bold text-gray-700">SKU <span class="text-red-500">*</span></label><input type="text" name="sku" id="inp_sku" required class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Group Owner</label><input type="text" name="group_owner" id="inp_group_owner" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Area</label><input type="text" name="area" id="inp_area" class="w-full border rounded p-2 text-sm"></div>
                        <div class="col-span-1 sm:col-span-2 md:col-span-3"><label class="block text-xs font-bold text-gray-700">Detail</label><textarea name="detail" id="inp_detail" class="w-full border rounded p-2 text-sm" rows="2"></textarea></div>
                        <div><label class="block text-xs font-bold text-gray-700">UOM</label><input type="text" name="uom" id="inp_uom" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Est Price</label><input type="number" step="0.01" name="est_price" id="inp_est_price" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Buying Price</label><input type="number" step="0.01" name="buying_price" id="inp_buying_price" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Hashtag</label><input type="text" name="hashtag" id="inp_hashtag" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Article SAP</label><input type="text" name="article_sap" id="inp_article_sap" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Max Delivery (Days)</label><input type="number" name="max_delivery_time_days" id="inp_max_delivery_time_days" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Validity From</label><input type="date" name="validity_from" id="inp_validity_from" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Validity To</label><input type="date" name="validity_to" id="inp_validity_to" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Min Qty</label><input type="number" name="min_qty" id="inp_min_qty" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Warranty</label><input type="text" name="warranty" id="inp_warranty" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Extend To Group</label><input type="text" name="extend_to_group" id="inp_extend_to_group" class="w-full border rounded p-2 text-sm"></div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700">Is Reguler</label>
                            <select name="is_reguler" id="inp_is_reguler" class="w-full border rounded p-2 text-sm">
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700">Is Active</label>
                            <select name="is_active" id="inp_is_active" class="w-full border rounded p-2 text-sm">
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div><label class="block text-xs font-bold text-gray-700">Auto Create PO</label>
                            <select name="auto_create_po" id="inp_auto_create_po" class="w-full border rounded p-2 text-sm">
                                <option value="Yes">Yes</option>
                                <option value="No">No</option>
                            </select>
                        </div>
                        <div><label class="block text-xs font-bold text-gray-700">Level 0</label><input type="text" name="level_0" id="inp_level_0" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Level 1</label><input type="text" name="level_1" id="inp_level_1" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Level 2</label><input type="text" name="level_2" id="inp_level_2" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Level 3</label><input type="text" name="level_3" id="inp_level_3" class="w-full border rounded p-2 text-sm"></div>
                        <div><label class="block text-xs font-bold text-gray-700">Level 4</label><input type="text" name="level_4" id="inp_level_4" class="w-full border rounded p-2 text-sm"></div>
                        <div>
                            <label class="block text-xs font-bold text-gray-700">Mapping Status</label>
                            <select name="mapping_status" id="inp_mapping_status" class="w-full border rounded p-2 text-sm">
                                <option value="">(None)</option>
                                <option value="AI Mapped">AI Mapped</option>
                                <option value="Need Review">Need Review</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:ml-3 sm:w-auto sm:text-sm">Save</button>
                    <button type="button" onclick="closeFormModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeDeleteModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md sm:w-full">
            <form method="POST" action="sku_dictionary.php">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-red-100 sm:mx-0 sm:h-10 sm:w-10">
                            <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900">Delete SKU Dictionary Row</h3>
                            <div class="mt-2">
                                <p class="text-sm text-gray-500">Are you sure you want to delete this row? This action cannot be undone.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 sm:ml-3 sm:w-auto sm:text-sm">Delete</button>
                    <button type="button" onclick="closeDeleteModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Status Update Modal -->
<div id="bulkStatusModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-md w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Bulk Update Mapping Status</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-500 mb-4">Select a new status for the <span id="bulkCountDisplay" class="font-bold">0</span> selected items.</p>
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Status</label>
                            <select id="bulk_new_status" class="mt-1 block w-full border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                <option value="AI Mapped">AI Mapped</option>
                                <option value="Need Review">Need Review</option>
                                <option value="Verified">Verified</option>
                                <option value="">(Clear Status)</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="submitBulkStatus()" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                    Apply Status
                </button>
                <button type="button" onclick="closeBulkStatusModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Hashtag Modal -->
<div id="hashtagModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeHashtagModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4" id="modal-title">Hashtag Full Detail</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-700 whitespace-pre-wrap break-words" id="hashtagFullText"></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="closeHashtagModal()" class="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto sm:text-sm">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Detail (Level 5) Modal. The Detail column is truncated to keep the table
     readable, and a `title` tooltip is no good for a name that runs to several
     lines, so the full item name gets its own dialog. -->
<div id="detailModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="detail-modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeDetailModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-2xl sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="mt-3 text-center sm:mt-0 sm:text-left w-full">
                    <h3 class="text-lg leading-6 font-medium text-gray-900" id="detail-modal-title">Item Detail &mdash; Level 5</h3>
                    <p class="text-xs text-gray-500 mt-1">SKU <span class="font-mono font-semibold text-gray-700" id="detailModalSku"></span></p>
                    <div class="mt-4">
                        <p class="text-sm text-gray-800 whitespace-pre-wrap break-words leading-relaxed" id="detailModalText"></p>
                    </div>
                    <div class="mt-4 pt-3 border-t border-gray-100 text-xs text-gray-500">
                        <span class="font-semibold">Taxonomy:</span> <span id="detailModalTaxonomy"></span>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="closeDetailModal()" class="w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:w-auto sm:text-sm">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Select2 (searchable dropdowns for the Group Owner / Area filters) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
    /* Tailwind override for Select2 so it matches the neighbouring filter inputs */
    .select2-container .select2-selection--single {
        height: 42px;
        border-color: #d1d5db !important;
        border-radius: 0.25rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__rendered {
        line-height: 42px;
        padding-left: 0.5rem;
        color: #334155;
        font-size: 0.875rem;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px; }
    /* Same footprint for the multi-select (Is Active) so the filter row stays even */
    .select2-container .select2-selection--multiple {
        min-height: 42px;
        border-color: #d1d5db !important;
        border-radius: 0.25rem;
    }
    .select2-container--default .select2-selection--multiple .select2-selection__choice {
        background-color: #eef2ff;
        border-color: #c7d2fe;
        color: #3730a3;
        font-size: 0.8125rem;
    }
    .select2-container--default.select2-container--focus .select2-selection--single,
    .select2-container--default.select2-container--open .select2-selection--single {
        border-color: #6366f1 !important;
        box-shadow: 0 0 0 1px #6366f1;
    }
    .select2-dropdown { font-size: 0.875rem; }
</style>
<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.4/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.4/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script>
    function openHashtagModal(btn) {
        var fullText = btn.getAttribute('data-full');
        document.getElementById('hashtagFullText').textContent = fullText;
        document.getElementById('hashtagModal').classList.remove('hidden');
    }

    function closeHashtagModal() {
        document.getElementById('hashtagModal').classList.add('hidden');
    }

    // Detail is the item name — Level 5 of the taxonomy. The table truncates it,
    // so this shows the whole thing. Takes the row object rather than markup so
    // nothing has to be squeezed through an HTML attribute.
    function openDetailModal(row) {
        document.getElementById('detailModalSku').textContent = row.sku || '-';
        document.getElementById('detailModalText').textContent = row.detail || '(no detail recorded)';
        var path = [row.level_0, row.level_1, row.level_2, row.level_3, row.level_4]
            .filter(function(v) { return v && String(v).trim() !== ''; });
        document.getElementById('detailModalTaxonomy').textContent = path.length ? path.join(' › ') : 'Not mapped yet';
        document.getElementById('detailModal').classList.remove('hidden');
    }

    function closeDetailModal() {
        document.getElementById('detailModal').classList.add('hidden');
    }

    // The item name is free text from the catalogue; render it as text, never as
    // markup, so a stray < in a name can't break the cell it sits in.
    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // How many characters of the item name the Detail column shows before it is
    // shortened. Roughly what fits in the column's max-w-xs at this font size.
    var DETAIL_PREVIEW_CHARS = 45;

    // Shorten to whole words and mark it with "...", instead of letting CSS clip
    // the line mid-word. The full name is one click away (openDetailModal).
    function truncateWords(s, max) {
        s = String(s == null ? '' : s).trim();
        if (s.length <= max) return s;
        var cut = s.slice(0, max);
        var lastSpace = cut.lastIndexOf(' ');
        // Back up to the last word boundary — unless that would throw away most
        // of the preview (a long unbroken code has no space to back up to), in
        // which case a hard cut is the lesser evil.
        if (lastSpace > max * 0.5) {
            cut = cut.slice(0, lastSpace);
        }
        return cut.replace(/[\s.,;:\-\/]+$/, '') + '...';
    }

    function formatDetails(d) {
        // d is the original data object for the row
        let hashtagHTML = d.hashtag || '-';
        if (hashtagHTML.length > 50) {
            hashtagHTML = hashtagHTML.substring(0, 50) + '... <button type="button" onclick="openHashtagModal(this)" data-full="' + (d.hashtag ? d.hashtag.replace(/"/g, '&quot;') : '') + '" class="text-indigo-600 hover:underline text-xs ml-2 font-semibold bg-indigo-50 px-2 py-1 rounded">See more</button>';
        }

        return `
            <div class="p-4 bg-slate-50 border border-slate-200 rounded-md shadow-inner m-2">
                <h4 class="font-bold text-slate-800 mb-3 border-b pb-2">SKU Complete Details</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm mb-4">
                    <?php if ($is_category_manager): ?>
                    <div class="col-span-1 md:col-span-2 lg:col-span-3">
                        <span class="text-slate-500 block text-xs font-semibold">Taxonomy Mapping Status</span>
                        <span class="text-slate-800 font-medium">${d.mapping_status ? d.mapping_status : '-'}</span>
                    </div>
                    <?php endif; ?>
                    <div><span class="text-slate-500 block text-xs font-semibold">Level 0</span><span class="text-slate-800">${d.level_0 || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Level 1</span><span class="text-slate-800">${d.level_1 || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Level 2</span><span class="text-slate-800">${d.level_2 || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Level 3</span><span class="text-slate-800">${d.level_3 || '-'}</span></div>
                    <div class="col-span-1 md:col-span-2"><span class="text-slate-500 block text-xs font-semibold">Level 4</span><span class="text-slate-800">${d.level_4 || '-'}</span></div>
                    <!-- Level 5 is the item detail/name itself — the bottom of the
                         taxonomy, not a separate column, so it is read off the
                         row's detail field. (No backticks in here: this comment
                         lives inside a JS template literal.) -->
                    <div class="col-span-1 md:col-span-2 lg:col-span-3">
                        <span class="text-slate-500 block text-xs font-semibold">Level 5 <span class="font-normal text-slate-400">(item detail / name)</span></span>
                        <span class="text-slate-800 whitespace-normal break-words">${d.detail || '-'}</span>
                    </div>
                </div>
                <h4 class="font-bold text-slate-800 mb-3 border-b pb-2">Other Attributes</h4>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                    <div><span class="text-slate-500 block text-xs font-semibold">Area</span><span class="text-slate-800">${d.area || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">UOM</span><span class="text-slate-800">${d.uom || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Buying Price</span><span class="text-slate-800">${parseFloat(d.buying_price || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</span></div>
                    <div class="col-span-1 md:col-span-2 lg:col-span-3"><span class="text-slate-500 block text-xs font-semibold">Hashtag</span><span class="text-slate-800">${hashtagHTML}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Article SAP</span><span class="text-slate-800">${d.article_sap || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Max Delivery Time</span><span class="text-slate-800">${d.max_delivery_time_days || '-'} days</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Min Qty</span><span class="text-slate-800">${d.min_qty || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">AON Remaining Qty</span><span class="text-slate-800">${(d.aon_remaining_qty === null || d.aon_remaining_qty === undefined || d.aon_remaining_qty === '') ? '— (not an AON SKU)' : (parseFloat(d.aon_remaining_qty).toLocaleString(undefined, {maximumFractionDigits: 2}) + (d.aon_contract_qty ? ' / ' + parseFloat(d.aon_contract_qty).toLocaleString(undefined, {maximumFractionDigits: 2}) + ' contracted' : ''))}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Warranty</span><span class="text-slate-800">${d.warranty || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Extend To Group</span><span class="text-slate-800">${d.extend_to_group || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Is Reguler</span><span class="text-slate-800">${d.is_reguler || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Auto Create PO</span><span class="text-slate-800">${d.auto_create_po || '-'}</span></div>
                    <div><span class="text-slate-500 block text-xs font-semibold">Modified By</span><span class="text-slate-800">${d.modified_by || '-'}</span></div>
                </div>
            </div>
        `;
    }

    // The Is Active badge text. Shared by the table renderer and the export so a
    // downloaded file can never disagree with the screen. is_effectively_active is
    // computed server-side (ajax_sku_dictionary_list.php): flagged active AND today
    // inside the validity window.
    function skuActiveLabel(row) {
        if (parseInt(row.is_effectively_active || 0, 10) === 1) return 'Active';
        if (((row.is_active || '') + '').toUpperCase() !== 'YES') return 'Inactive';
        // Flagged Yes but not effectively active — say which way it missed.
        var today = new Date().toISOString().slice(0, 10);
        if (!row.validity_from || !row.validity_to) return 'No Validity';
        if (row.validity_to <= today) return 'Expired';
        if (row.validity_from >= today) return 'Not Yet Valid';
        return 'Out of Validity';
    }

    // The custom filter payload. Shared by the table's own ajax (sent on every
    // draw) and by the export, which re-sends it verbatim so the file covers
    // exactly the rows the filters select.
    function skuFilterPayload(d) {
        d.filter_group_owner = $('#filter_group_owner').val();
        d.filter_area = $('#filter_area').val();
        d.filter_validity_from = $('#filter_validity_from').val();
        d.filter_validity_to = $('#filter_validity_to').val();
        d.filter_level_0 = $('#filter_level_0').val();
        d.filter_level_1 = $('#filter_level_1').val();
        d.filter_level_2 = $('#filter_level_2').val();
        d.filter_level_3 = $('#filter_level_3').val();
        d.filter_level_4 = $('#filter_level_4').val();
        <?php if ($is_category_manager): ?>
        d.filter_mapping_status = $('#filter_mapping_status').val();
        <?php endif; ?>
        // Multi-select: an array of states, OR-ed together server-side.
        d.filter_is_active = $('#filter_is_active').val() || [];
        return d;
    }

    $(document).ready(function() {
        var table = $('#skuTable').DataTable({
            "pageLength": 50,
            "order": [[ <?= $hidden_base + 1 ?>, 'asc']],
            "serverSide": true,
            "processing": true,
            "dom": 'rtip',
            "ajax": {
                "url": "ajax_sku_dictionary_list.php",
                "type": "POST",
                "data": skuFilterPayload
            },
            "columns": [
                <?php if ($role === 'Category Manager'): ?>
                {
                    "data": null,
                    "orderable": false,
                    "className": "px-3 py-3 text-center",
                    "render": function(data, type, row) {
                        return `<input type="checkbox" value="${row.id}" class="sku-checkbox focus:ring-indigo-500 h-4 w-4 text-indigo-600 border-gray-300 rounded">`;
                    }
                },
                <?php endif; ?>
                {
                    "data": null,
                    "orderable": false,
                    "className": "px-3 py-3 text-center cursor-pointer details-control text-indigo-600 hover:text-indigo-800",
                    "defaultContent": '<svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
                },
                {
                    "data": null,
                    "orderable": false,
                    "className": "px-3 py-3 text-slate-700 max-w-xs",
                    "render": function(data, type, row) {
                        // Shortened at a word boundary and ended with "..." rather
                        // than clipped mid-word by CSS. The whole name is on the
                        // tooltip and one click away: openDetailModal() reads the
                        // row object from DataTables, so nothing has to be stuffed
                        // into a data- attribute here.
                        var full    = row.detail || '';
                        var preview = truncateWords(full, DETAIL_PREVIEW_CHARS);
                        return `<div class="detail-full-link font-bold text-gray-900 cursor-pointer hover:text-indigo-700 hover:underline truncate" title="${escHtml(full)}">${escHtml(preview)}</div>
                                <div class="text-xs text-gray-500 mt-1 font-mono">${escHtml(row.sku)}</div>`;
                    }
                },
                { "data": "group_owner", "className": "px-3 py-3 text-slate-700", "defaultContent": "" },
                {
                    "data": "est_price",
                    "className": "px-3 py-3 text-slate-700",
                    "render": function(data, type, row) {
                        return data ? parseFloat(data).toLocaleString(undefined, {minimumFractionDigits: 2}) : '0.00';
                    }
                },
                {
                    // Remaining AON contract qty, pulled from Master AON via the
                    // list endpoint. Plain text, same colour as every other cell
                    // (no low-stock highlight here -- that's Master AON's job).
                    // Dash = not an AON SKU.
                    "data": "aon_remaining_qty",
                    "orderable": false,
                    "className": "px-3 py-3 text-slate-700 text-right whitespace-nowrap",
                    "render": function(data, type, row) {
                        if (data === null || data === undefined || data === '') {
                            return '<span class="text-gray-300" title="Not on the Master AON list">&mdash;</span>';
                        }
                        var n = parseFloat(data);
                        var txt = isNaN(n) ? escHtml(String(data)) : n.toLocaleString(undefined, {maximumFractionDigits: 2});
                        var tip = '';
                        if (row.aon_contract_qty) {
                            tip = 'Remaining ' + txt + ' of ' + parseFloat(row.aon_contract_qty).toLocaleString(undefined, {maximumFractionDigits: 2}) + ' contracted';
                            if (parseInt(row.aon_row_count || 0, 10) > 1) tip += ' — summed across ' + row.aon_row_count + ' AON contract rows';
                        }
                        return '<span class="text-slate-700" title="' + escHtml(tip) + '">' + txt + '</span>';
                    }
                },
                {
                    "data": null,
                    "orderable": false,
                    "className": "px-3 py-3 text-slate-700",
                    "render": function(data, type, row) {
                        return `<div class="text-xs space-y-1">
                                    <div><span class="text-gray-400">From:</span> ${row.validity_from || '-'}</div>
                                    <div><span class="text-gray-400">To:</span> ${row.validity_to || '-'}</div>
                                </div>`;
                    }
                },
                {
                    "data": null,
                    "orderable": false,
                    "className": "px-3 py-3 text-slate-700 max-w-[200px] whitespace-normal",
                    "render": function(data, type, row) {
                        return `<div class="text-xs">
                                    <div class="font-semibold text-gray-800">${row.level_3 || '-'}</div>
                                    <div class="text-gray-500 mt-0.5">${row.level_4 || '-'}</div>
                                </div>`;
                    }
                },
                {
                    "data": "is_active",
                    "className": "px-3 py-3 text-slate-700",
                    "render": function(data, type, row) {
                        // Active means BOTH: flagged active, and today inside the
                        // validity window — see skuActiveLabel(), which the export
                        // uses too so the badge, the Is Active filter and the file
                        // can never disagree.
                        // A row that is flagged Yes but out of window gets its own
                        // amber badge rather than a plain "Inactive", so the reason
                        // is visible without opening the row.
                        var flaggedYes = (data || '').toUpperCase() === 'YES';
                        var currentAttr = flaggedYes ? 'Yes' : 'No';
                        var label = skuActiveLabel(row);

                        if (label === 'Active') {
                            return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800 cursor-pointer hover:bg-green-200 transition toggle-active-btn" data-id="'+row.id+'" data-current="Yes" title="Click to toggle status">Active</span>';
                        }
                        if (flaggedYes) {
                            return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-amber-100 text-amber-800 cursor-pointer hover:bg-amber-200 transition toggle-active-btn" data-id="'+row.id+'" data-current="'+currentAttr+'" title="Flagged Is Active = Yes, but outside its validity window ('+(row.validity_from || '-')+' to '+(row.validity_to || '-')+'). Click to toggle the flag.">'+label+'</span>';
                        }
                        return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800 cursor-pointer hover:bg-red-200 transition toggle-active-btn" data-id="'+row.id+'" data-current="No" title="Click to toggle status">Inactive</span>';
                    }
                },
                <?php if ($is_category_manager): ?>
                {
                    "data": "mapping_status",
                    "className": "px-3 py-3 text-slate-700",
                    "render": function(data, type, row) {
                        if (data === 'AI Mapped') {
                            return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">AI Mapped</span>';
                        } else if (data === 'Need Review') {
                            return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Need Review</span>';
                        } else if (data === 'Verified') {
                            return '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-emerald-100 text-emerald-800">Verified</span>';
                        }
                        return '<span class="text-gray-400">-</span>';
                    }
                },
                {
                    "data": null,
                    "orderable": false,
                    "className": "px-3 py-3 text-center sticky right-0 bg-white",
                    "render": function(data, type, row) {
                        return `
                            <button type="button" title="Edit" class="text-indigo-600 hover:text-indigo-900 mr-3" onclick='openEditModal(${JSON.stringify(row).replace(/'/g, "&#39;")})'>
                                <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            </button>
                            <button type="button" title="Delete" class="text-red-600 hover:text-red-900 mr-3" onclick='openDeleteModal(${row.id})'>
                                <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                            <button type="button" title="AI Map" class="text-purple-600 hover:text-purple-900" onclick='processSingleAIMapping(${row.id})'>
                                <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path></svg>
                            </button>
                        `;
                    }
                },
                <?php endif; ?>
                { "data": "sku", "visible": false },
                { "data": "detail", "visible": false },
                { "data": "validity_from", "visible": false },
                { "data": "validity_to", "visible": false },
                { "data": "level_3", "visible": false },
                { "data": "level_4", "visible": false }
            ]
        });

        // --- Export -------------------------------------------------------
        // The table is server-side, so only the 50 rows of the current page are
        // ever in the DOM — exporting from it would give a file of whatever
        // happened to be on screen. Instead the same query is re-run with
        // length -1 (all filtered rows) and the file is built from a detached
        // table that is never displayed, leaving the visible table's paging,
        // expanded rows and checkbox selection untouched.
        var exportColumns = [
            { data: 'sku',           title: 'SKU',           defaultContent: '' },
            { data: 'detail',        title: 'Detail',        defaultContent: '' },
            { data: 'group_owner',   title: 'Group Owner',   defaultContent: '' },
            {
                data: 'est_price', title: 'Est Price', defaultContent: '',
                render: function(d) {
                    return d ? parseFloat(d).toLocaleString(undefined, {minimumFractionDigits: 2}) : '0.00';
                }
            },
            {
                data: 'aon_remaining_qty', title: 'AON Remaining Qty', defaultContent: '',
                render: function(d) {
                    if (d === null || d === undefined || d === '') return '';
                    var n = parseFloat(d);
                    return isNaN(n) ? d : n;
                }
            },
            { data: 'validity_from', title: 'Validity From', defaultContent: '' },
            { data: 'validity_to',   title: 'Validity To',   defaultContent: '' },
            { data: 'level_3',       title: 'L3',            defaultContent: '' },
            { data: 'level_4',       title: 'L4',            defaultContent: '' },
            {
                data: null, title: 'Is Active',
                render: function(d, type, row) { return skuActiveLabel(row); }
            }
            <?php if ($is_category_manager): ?>
            // Mapping Status is a Category Manager-only column; no other role
            // sees it in the table, so no other role gets it in the file either.
            , { data: 'mapping_status', title: 'Mapping Status', defaultContent: '' }
            <?php endif; ?>
        ];

        function buildExportFile(type, rows) {
            var $holder = $('<div class="hidden"></div>').appendTo('body');
            var $tmp = $('<table></table>').appendTo($holder);
            var stamp = new Date().toISOString().slice(0, 10);
            var common = {
                title: 'SKU Dictionary',
                filename: 'sku_dictionary_' + stamp,
                // page:'all' is explicit — every fetched row goes in the file.
                exportOptions: { modifier: { page: 'all', search: 'none', order: 'applied' } }
            };

            var tmpTable = $tmp.DataTable({
                data: rows,
                columns: exportColumns,
                // The scratch table is never looked at, so it is kept to a single
                // rendered row: deferRender means the other thousands never become
                // DOM nodes. The export reads the data cache (page:'all' below),
                // not the DOM, so the file is still complete.
                pageLength: 1,
                deferRender: true,
                ordering: false,
                searching: false,
                info: false,
                dom: '<"hidden"B>t',
                buttons: [
                    $.extend({ extend: 'excelHtml5', className: 'buttons-excel' }, common),
                    $.extend({ extend: 'csvHtml5',   className: 'buttons-csv'   }, common),
                    $.extend({ extend: 'pdfHtml5',   className: 'buttons-pdf',
                               orientation: 'landscape', pageSize: 'LEGAL' }, common)
                ]
            });

            tmpTable.button('.buttons-' + type).trigger();
            // The trigger builds and hands off the file synchronously; tear the
            // scratch table down once the click handler has unwound.
            setTimeout(function() {
                tmpTable.destroy();
                $holder.remove();
            }, 100);
        }

        function runExport(type) {
            Swal.fire({
                title: 'Preparing export…',
                text: 'Fetching every row that matches the current filters.',
                allowOutsideClick: false,
                didOpen: function() { Swal.showLoading(); }
            });

            // Same endpoint, same filters and search as the table — only the
            // paging is dropped.
            var payload = skuFilterPayload({
                draw: 1,
                start: 0,
                length: -1,
                search: { value: table.search(), regex: false },
                order: [{ column: table.order()[0][0], dir: table.order()[0][1] }]
            });

            $.post('ajax_sku_dictionary_list.php', payload).done(function(res) {
                if (!res || res.error || !res.data) {
                    Swal.fire('Export failed', (res && res.error) || 'No data was returned.', 'error');
                    return;
                }
                if (res.data.length === 0) {
                    Swal.fire('Nothing to export', 'No SKU matches the current filters.', 'info');
                    return;
                }
                // pdfmake renders the whole document in the browser, so a large
                // PDF can take a while and eat a lot of memory — Excel and CSV
                // handle the same row count without complaint.
                if (type === 'pdf' && res.data.length > 2000) {
                    Swal.fire({
                        icon: 'warning',
                        title: res.data.length.toLocaleString() + ' rows',
                        text: 'A PDF this large can take a minute and may make the browser unresponsive. Excel or CSV is faster.',
                        showCancelButton: true,
                        confirmButtonText: 'Build the PDF anyway',
                        cancelButtonText: 'Cancel'
                    }).then(function(r) {
                        if (r.isConfirmed) buildExportFile(type, res.data);
                    });
                    return;
                }
                buildExportFile(type, res.data);
                Swal.close();
            }).fail(function() {
                Swal.fire('Export failed', 'Could not reach the server.', 'error');
            });
        }

        $('#customExportExcel').on('click', function() { runExport('excel'); });
        $('#customExportCSV').on('click', function() { runExport('csv'); });
        $('#customExportPDF').on('click', function() { runExport('pdf'); });

        window.customSort = function(colIndex) {
            let currentOrder = table.order()[0];
            let dir = 'asc';
            if (currentOrder[0] === colIndex) {
                dir = currentOrder[1] === 'asc' ? 'desc' : 'asc';
            }
            table.order([colIndex, dir]).draw();
        };

        // Searchable dropdowns. dropdownParent isn't needed here (the filter card
        // is not inside a modal), but width:'100%' is — Select2 otherwise measures
        // the hidden <select> and collapses to a few pixels inside the grid cell.
        $('.filter-searchable').select2({
            placeholder: 'All',
            allowClear: true,
            width: '100%'
        });

        // Trigger reload on filter change
        $('#filter_group_owner, #filter_area, #filter_validity_from, #filter_validity_to, #filter_level_0, #filter_level_1, #filter_level_2, #filter_level_3, #filter_level_4, <?= $is_category_manager ? '#filter_mapping_status, ' : '' ?>#filter_is_active').on('change', function() {
            table.draw();
        });

        // --- E-Commerce Advanced Search Logic ---
        let typingTimer;
        const doneTypingInterval = 300;
        const $searchInp = $('#ecommerceSearch');
        const $dropdown = $('#autocompleteDropdown');
        const $list = $('#autocompleteList');

        $searchInp.on('keyup', function(e) {
            clearTimeout(typingTimer);
            if (e.key === 'Enter') {
                $dropdown.addClass('hidden');
                table.search($searchInp.val()).draw();
                return;
            }
            if ($searchInp.val().trim().length >= 2) {
                typingTimer = setTimeout(fetchSuggestions, doneTypingInterval);
            } else {
                $dropdown.addClass('hidden');
                if ($searchInp.val().trim().length === 0) {
                    table.search('').draw();
                }
            }
        });
        
        $searchInp.on('focus', function() {
            if ($searchInp.val().trim().length >= 2) fetchSuggestions();
        });
        
        $(document).on('click', function(e) {
            if (!$(e.target).closest('.relative.w-full.max-w-3xl').length) {
                $dropdown.addClass('hidden');
            }
        });

        function fetchSuggestions() {
            $.ajax({
                url: 'ajax_sku_autocomplete.php',
                data: { q: $searchInp.val() },
                success: function(res) {
                    $list.empty();
                    let hasItems = false;
                    
                    if (res.did_you_mean) {
                        hasItems = true;
                        $list.append(`
                            <li class="cursor-pointer select-none relative py-3 pl-5 pr-9 bg-indigo-50/50 hover:bg-indigo-100 text-indigo-700 font-medium transition-colors" onclick="selectSuggestion('${res.did_you_mean}')">
                                <span class="mr-2 opacity-60">💡</span> Did you mean: <i class="font-bold">${res.did_you_mean}</i> ?
                            </li>
                        `);
                    }
                    
                    if (res.suggestions && res.suggestions.length > 0) {
                        hasItems = true;
                        res.suggestions.forEach(item => {
                            let display = item;
                            if (res.expanded_terms) {
                                res.expanded_terms.forEach(term => {
                                    if (term.length > 2) {
                                        let regex = new RegExp("("+term+")", "gi");
                                        display = display.replace(regex, "<span class='font-bold text-indigo-600 bg-indigo-50 px-0.5 rounded'>$1</span>");
                                    }
                                });
                            }
                            
                            $list.append(`
                                <li class="cursor-pointer select-none relative py-2.5 pl-5 pr-9 hover:bg-slate-50 text-slate-700 border-l-4 border-transparent hover:border-indigo-500 transition-colors" onclick="selectSuggestion('${item.replace(/'/g, "\\'")}')">
                                    ${display}
                                </li>
                            `);
                        });
                    }
                    
                    if (hasItems) {
                        $dropdown.removeClass('hidden');
                    } else {
                        $dropdown.addClass('hidden');
                    }
                }
            });
        }
        
        window.selectSuggestion = function(val) {
            $searchInp.val(val);
            $dropdown.addClass('hidden');
            table.search(val).draw();
        };
        
        // Hide default DataTables search
        $('.dataTables_filter').hide();
        // ----------------------------------------

        // Handle select all
        $('#selectAllSkus').on('click', function(){
            var rows = table.rows({ 'search': 'applied' }).nodes();
            $('input[type="checkbox"].sku-checkbox', rows).prop('checked', this.checked);
        });

        // Handle single checkbox click to manage selectAll state
        $('#skuTable tbody').on('change', 'input[type="checkbox"].sku-checkbox', function(){
            if(!this.checked){
                var el = $('#selectAllSkus').get(0);
                if(el && el.checked && ('indeterminate' in el)){
                    el.indeterminate = true;
                }
            }
        });

        // Add event listener for opening and closing details
        $('#skuTable tbody').on('click', 'td.details-control', function () {
            var tr = $(this).closest('tr');
            var row = table.row(tr);

            if (row.child.isShown()) {
                // This row is already open - close it
                row.child.hide();
                tr.removeClass('shown');
                $(this).html('<svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>');
            } else {
                // Open this row
                var rowData = table.row(tr).data();
                row.child(formatDetails(rowData)).show();
                tr.addClass('shown');
                $(this).html('<svg class="w-5 h-5 mx-auto text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>');
            }
        });

        // Clicking the truncated item name shows it in full (Level 5).
        $('#skuTable tbody').on('click', '.detail-full-link', function() {
            var rowData = table.row($(this).closest('tr')).data();
            if (rowData) openDetailModal(rowData);
        });

        // Esc closes whichever of the two read-only dialogs is open, so a long
        // name doesn't trap the keyboard user on a mouse-only Close button.
        $(document).on('keydown', function(e) {
            if (e.key !== 'Escape') return;
            closeDetailModal();
            closeHashtagModal();
        });
    });

    <?php if ($role === 'Category Manager'): ?>
    function openAddModal() {
        $('#formAction').val('add');
        $('#formId').val('');
        $('#modalTitle').text('Add New SKU');
        // Reset inputs
        $('#inp_sku, #inp_group_owner, #inp_area, #inp_detail, #inp_uom, #inp_est_price, #inp_buying_price, #inp_hashtag, #inp_article_sap, #inp_validity_from, #inp_validity_to, #inp_max_delivery_time_days, #inp_min_qty, #inp_warranty, #inp_extend_to_group, #inp_level_0, #inp_level_1, #inp_level_2, #inp_level_3, #inp_level_4').val('');
        $('#inp_is_reguler, #inp_is_active').val('Yes');
        $('#inp_auto_create_po').val('No');
        $('#inp_mapping_status').val('');
        $('#skuFormModal').removeClass('hidden');
    }

    function openEditModal(row) {
        $('#formAction').val('edit');
        $('#formId').val(row.id);
        $('#modalTitle').text('Edit SKU');
        
        $('#inp_sku').val(row.sku);
        $('#inp_group_owner').val(row.group_owner);
        $('#inp_area').val(row.area);
        $('#inp_detail').val(row.detail);
        $('#inp_uom').val(row.uom);
        $('#inp_est_price').val(row.est_price);
        $('#inp_buying_price').val(row.buying_price);
        $('#inp_hashtag').val(row.hashtag);
        $('#inp_article_sap').val(row.article_sap);
        $('#inp_validity_from').val(row.validity_from);
        $('#inp_validity_to').val(row.validity_to);
        $('#inp_max_delivery_time_days').val(row.max_delivery_time_days);
        $('#inp_min_qty').val(row.min_qty);
        $('#inp_warranty').val(row.warranty);
        $('#inp_extend_to_group').val(row.extend_to_group);
        $('#inp_is_reguler').val(row.is_reguler || 'Yes');
        $('#inp_is_active').val(row.is_active || 'Yes');
        $('#inp_auto_create_po').val(row.auto_create_po || 'No');
        $('#inp_level_0').val(row.level_0);
        $('#inp_level_1').val(row.level_1);
        $('#inp_level_2').val(row.level_2);
        $('#inp_level_3').val(row.level_3);
        $('#inp_level_4').val(row.level_4);
        $('#inp_mapping_status').val(row.mapping_status || '');

        $('#skuFormModal').removeClass('hidden');
    }

    function closeFormModal() {
        $('#skuFormModal').addClass('hidden');
    }

    function openDeleteModal(id) {
        $('#deleteId').val(id);
        $('#deleteModal').removeClass('hidden');
    }

    function closeDeleteModal() {
        $('#deleteModal').addClass('hidden');
    }

    function deleteRow() {
        $('#deleteForm').submit();
    }
    
    // --- Bulk Status Logic ---
    function openBulkStatusModal() {
        var checked = $('.sku-checkbox:checked');
        if (checked.length === 0) {
            Swal.fire('No Selection', 'Please select at least one SKU to update.', 'warning');
            return;
        }
        $('#bulkCountDisplay').text(checked.length);
        $('#bulkStatusModal').removeClass('hidden');
    }
    function closeBulkStatusModal() {
        $('#bulkStatusModal').addClass('hidden');
    }
    function submitBulkStatus() {
        var checked = $('.sku-checkbox:checked');
        if (checked.length === 0) return;
        
        var ids = [];
        checked.each(function(){ ids.push($(this).val()); });
        var newStatus = $('#bulk_new_status').val();

        $.ajax({
            url: 'ajax_sku_bulk_mapping.php',
            type: 'POST',
            data: { sku_ids: ids, new_status: newStatus },
            success: function(res) {
                if(res.status === 'success') {
                    Swal.fire('Success!', `Updated ${res.updated_count} items.`, 'success');
                    closeBulkStatusModal();
                    $('#selectAllSkus').prop('checked', false);
                    $('#skuTable').DataTable().ajax.reload(null, false);
                } else {
                    Swal.fire('Error', res.error || 'Failed to update items.', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Server error during update.', 'error');
            }
        });
    }

    function processSingleAIMapping(id) {
        processAIMapping([id]);
    }

    function processAIMapping(ids = null) {
        let selectedIds = ids;
        if (!selectedIds) {
            selectedIds = [];
            $('.sku-checkbox:checked').each(function() {
                selectedIds.push($(this).val());
            });
        }

        if (selectedIds.length === 0) {
            Swal.fire('No Selection', 'Please select at least one SKU to process.', 'warning');
            return;
        }

        Swal.fire({
            title: 'Processing AI Mapping',
            text: 'This might take a while depending on the number of selected SKUs...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        $.ajax({
            url: 'ajax_ai_map_sku_dictionary.php',
            type: 'POST',
            data: {
                sku_ids: JSON.stringify(selectedIds)
            },
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        title: 'Success!',
                        text: response.message,
                        icon: 'success'
                    }).then(() => {
                        $('#skuTable').DataTable().ajax.reload(null, false); // Reload table without resetting paging
                    });
                } else {
                    Swal.fire('Error', response.message || 'An error occurred during AI processing', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'Failed to reach the server', 'error');
            }
        });
    }
    
    // Quick toggle active status
    $('#skuTable').on('click', '.toggle-active-btn', function(e) {
        e.stopPropagation();
        var id = $(this).data('id');
        var currentStatus = $(this).data('current');
        var newStatus = currentStatus === 'Yes' ? 'No' : 'Yes';

        // No optimistic badge swap here: the badge is no longer a straight
        // reflection of the is_active flag (it also depends on the validity
        // window, and the Is Active filter defaults to Active), so guessing the
        // resulting badge — or whether the row still belongs in the current
        // result set at all — is not something the client can do. Redraw from
        // the server instead, keeping the current page/filters.
        $(this).addClass('opacity-50');

        $.post('sku_dictionary.php', {
            action: 'toggle_active',
            id: id,
            new_status: newStatus,
            ajax: 1
        }, function(res) {
            try {
                var response = typeof res === 'string' ? JSON.parse(res) : res;
                if(response.status !== 'success') {
                    throw new Error('Update failed');
                }
                $('#skuTable').DataTable().ajax.reload(null, false);
            } catch(e) {
                // Revert on error
                $('#skuTable').DataTable().ajax.reload(null, false);
                Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Failed to update status', showConfirmButton: false, timer: 3000 });
            }
        }).fail(function() {
            $('#skuTable').DataTable().ajax.reload(null, false);
            Swal.fire({ toast: true, position: 'top-end', icon: 'error', title: 'Network error', showConfirmButton: false, timer: 3000 });
        });
    });

    // AJAX form submission for add/edit
    $('#skuForm').on('submit', function(e) {
        e.preventDefault();
        var formData = $(this).serialize() + '&ajax=1';
        
        // Disable submit button and show loading state
        var $btn = $(this).find('button[type="submit"]');
        var originalText = $btn.html();
        $btn.html('<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Saving...');
        $btn.prop('disabled', true);
        
        $.post('sku_dictionary.php', formData, function(res) {
            closeFormModal();
            $('#skuTable').DataTable().ajax.reload(null, false); // Reload without resetting paging/filters
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Row saved successfully',
                showConfirmButton: false,
                timer: 3000
            });
        }).fail(function() {
            Swal.fire('Error', 'Failed to save data. Please try again.', 'error');
        }).always(function() {
            $btn.html(originalText);
            $btn.prop('disabled', false);
        });
    });

    <?php endif; ?>
</script>
<style>
/* Datatables Overrides to match theme */
.dataTables_wrapper .dataTables_filter input {
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    padding: 0.25rem 0.5rem;
    margin-left: 0.5rem;
    outline: none;
}
.dataTables_wrapper .dataTables_filter input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 1px #6366f1;
}
.dataTables_wrapper .dataTables_length select {
    border: 1px solid #e2e8f0;
    border-radius: 0.375rem;
    padding: 0.25rem 1.5rem 0.25rem 0.5rem;
    outline: none;
}
</style>
<?php require_once '../includes/footer.php'; ?>
