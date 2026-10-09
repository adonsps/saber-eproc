<?php
// pages/rfq_create.php
require_once '../includes/auth.php';
require_login(); 
require_once '../config/database.php';
require_once '../includes/fonnte.php';
require_once '../includes/mailer.php';
date_default_timezone_set('Asia/Jakarta');

// --- 1. HANDLE AJAX (SAVE TEMPLATE) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_template') {
    header('Content-Type: application/json');
    $name = trim($_POST['template_name']);
    $members = $_POST['committee'] ?? [];
    
    if ($name && !empty($members)) {
        $pdo->prepare("INSERT INTO rfq_committee_templates (template_name, created_by) VALUES (?, ?)")->execute([$name, $_SESSION['username']]);
        $tid = $pdo->lastInsertId();
        
        $st = $pdo->prepare("INSERT INTO rfq_committee_template_users (template_id, username) VALUES (?, ?)");
        foreach($members as $m) {
            $st->execute([$tid, $m]);
        }
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error']);
    }
    exit;
}

// --- 1.5 FETCH PR ITEMS IF SELECTED ---
$pr_items = [];
$ticket_no = '';
if (isset($_GET['selected_items']) && is_array($_GET['selected_items'])) {
    foreach ($_GET['selected_items'] as $item) {
        $parts = explode('|', $item);
        if (count($parts) === 2) {
            $t_no = $parts[0];
            $t_line = $parts[1];
            $stmt = $pdo->prepare("SELECT ticket_no, ticket_line_item, item_description, qty, uom FROM prs WHERE ticket_no = ? AND ticket_line_item = ?");
            $stmt->execute([$t_no, $t_line]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $pr_items[] = $res;
                $ticket_no = $t_no;
            }
        }
    }
}
$is_standard_rfq = count($pr_items) > 0;

// --- 2. HANDLE FORM SUBMISSION (CREATE RFQ) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_rfq'])) {
    $use_committee = !$is_standard_rfq || isset($_POST['use_committee']);
    if ($use_committee && empty($_POST['committee'])) {
        $error = "Anda harus memilih minimal satu anggota komite.";
    } else {
        $pdo->beginTransaction();
        try {
            $rfq_type = $is_standard_rfq ? 'Standard' : 'Construction';
            $status = ($is_standard_rfq && !$use_committee) ? 'Unsealed' : 'Active';

            $stmt = $pdo->prepare("INSERT INTO rfqs (package_name, deadline, created_by, rfq_type, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['package_name'], $_POST['deadline'], $_SESSION['username'], $rfq_type, $status]);
            $rfq_id = $pdo->lastInsertId();

            if ($is_standard_rfq) {
                $stmt_item = $pdo->prepare("INSERT INTO rfq_items (rfq_id, ticket_no, ticket_line_item, item_description, qty, uom) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($pr_items as $item) {
                    $stmt_item->execute([$rfq_id, $item['ticket_no'], $item['ticket_line_item'], $item['item_description'], $item['qty'], $item['uom']]);
                }
            }

            if ($use_committee) {
                $stmt_comm = $pdo->prepare("INSERT INTO rfq_committee (rfq_id, username) VALUES (?, ?)");
                foreach ($_POST['committee'] as $comm_user) {
                    $stmt_comm->execute([$rfq_id, $comm_user]);
                }
            }

            $stmt_ven = $pdo->prepare("INSERT INTO rfq_vendors (rfq_id, vendor_name, vendor_email, vendor_wa, vendor_pic, secret_password) VALUES (?, ?, ?, ?, ?, ?)");
            
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
            $base_url = $protocol . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']) . "/rfq_vendor.php?id=" . $rfq_id;
            
            foreach ($_POST['v_name'] as $i => $vName) {
                if (empty($vName)) continue;
                
                $vEmail = $_POST['v_email'][$i];
                $vWa = $_POST['v_wa'][$i];
                $secret_pass = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
                
                $stmt_ven->execute([$rfq_id, $vName, $vEmail, $vWa, $_POST['v_pic'][$i], $secret_pass]);
                
                $packageName = $_POST['package_name'];
                $deadlineStr = date('d F Y H:i', strtotime($_POST['deadline']));
                $msg = "Halo {$vName},\n\nAnda diundang untuk berpartisipasi dalam tender tertutup untuk: {$packageName}.\n\nBatas Waktu: {$deadlineStr} WIB\nLink Submit Penawaran: {$base_url}\nPassword Rahasia Anda: {$secret_pass}\n\nHarap submit dokumen penawaran Anda sebelum batas waktu yang ditentukan.";
                
                sendFonnteWA($vWa, $msg);

                if (!empty($vEmail)) {
                    $subject = "Undangan Tender: " . $packageName;
                    sendTenderEmail($vEmail, $vName, $subject, $msg);
                }
            }

            $pdo->commit();
            
            // Send internal notifications
            require_once '../includes/rfq_notification_helper.php';
            notify_new_rfq($pdo, $rfq_id);

            // THIS REDIRECT WILL NOW WORK BECAUSE NO HTML HAS BEEN SENT YET!
            header("Location: rfq_dashboard.php?id=$rfq_id&success=1");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Gagal membuat RFQ: " . $e->getMessage();
        }
    }
}

// --- 3. NOW LOAD THE HTML HEADER ---
require_once '../includes/header.php';

// Prevent QS from creating RFQs
if ($_SESSION['role'] === 'QS') {
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak. QS hanya dapat melihat (View Only).</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

// Fetch all users for Committee Selection
$users = $pdo->query("SELECT username, name FROM users ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch existing Committee Templates
$templates = $pdo->query("SELECT * FROM rfq_committee_templates ORDER BY template_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$template_map = [];
foreach ($templates as $t) {
    $t_users = $pdo->prepare("SELECT username FROM rfq_committee_template_users WHERE template_id = ?");
    $t_users->execute([$t['id']]);
    $template_map[$t['id']] = $t_users->fetchAll(PDO::FETCH_COLUMN);
}

// Connect to VSS Database to fetch Taxonomy for Vendor Search
try {
    $db_host = getenv('DB_HOST') ?: "localhost";
    if (php_sapi_name() === 'cli-server') {
        $vss_pdo_taxonomy = new PDO("mysql:host=127.0.0.1;dbname=u894129685_vmsportal;charset=utf8mb4", "root", "");
    } else {
        $db_vss_pass = "Xcel007!";
        $vss_pdo_taxonomy = new PDO("mysql:host=$db_host;dbname=u894129685_vmsportal;charset=utf8mb4", "u894129685_adonisvm", $db_vss_pass);
    }
    $vss_pdo_taxonomy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $vss_company_types = $vss_pdo_taxonomy->query("SELECT DISTINCT company_type FROM vendors WHERE company_type IS NOT NULL AND company_type != '' ORDER BY company_type ASC")->fetchAll(PDO::FETCH_COLUMN);
    $vss_core_businesses = $vss_pdo_taxonomy->query("SELECT DISTINCT sub_company_type FROM vendors WHERE sub_company_type IS NOT NULL AND sub_company_type != '' ORDER BY sub_company_type ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $vss_company_types = [];
    $vss_core_businesses = [];
}

// Determine the correct dashboard link based on role
$dash_link = "dashboard_pic.php";
if ($_SESSION['role'] === 'Category Manager') $dash_link = "dashboard_mgr.php";
if ($_SESSION['role'] === 'Project Director') $dash_link = "dashboard_project.php";
?>

<div class="max-w-5xl mx-auto py-8">
    <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end border-b pb-4 gap-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Buat Tender RFQ Baru</h2>
            <p class="text-sm text-gray-600 mt-1">Siapkan tender tertutup, tentukan komite, dan undang vendor.</p>
        </div>
        <a href="<?= $dash_link ?>" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow transition whitespace-nowrap">&larr; Kembali ke Dashboard</a>
    </div>
    
    <?php if(isset($error)): ?><div class="bg-red-100 text-red-800 p-4 mb-4 rounded font-bold"><?= $error ?></div><?php endif; ?>

    <form method="POST" class="bg-white shadow-md rounded-lg p-6 border border-gray-200" id="rfqForm">
        <input type="hidden" name="create_rfq" value="1">
        <?php if ($is_standard_rfq): ?>
        <div class="mb-6 bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
            <h3 class="text-md font-bold text-blue-800 mb-2">Item PR Terpilih (Ticket: <?= htmlspecialchars($ticket_no ?? '') ?>)</h3>
            <ul class="list-disc ml-5 text-sm text-blue-700">
                <?php foreach($pr_items as $pi): ?>
                <li><strong><?= htmlspecialchars($pi['ticket_line_item'] ?? '') ?></strong>: <?= htmlspecialchars($pi['item_description'] ?? '') ?> (Qty: <?= (float)$pi['qty'] ?><?= !empty($pi['uom']) ? ' ' . htmlspecialchars($pi['uom']) : '' ?>)</li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-2 gap-6 mb-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Nama Paket Tender</label>
                <input type="text" name="package_name" required class="w-full p-2 border border-gray-300 rounded focus:ring-indigo-500">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">Batas Waktu (Deadline)</label>
                <input type="datetime-local" name="deadline" required class="w-full p-2 border border-gray-300 rounded focus:ring-indigo-500">
            </div>
        </div>

        <?php if ($is_standard_rfq): ?>
        <div class="mb-6">
            <label class="inline-flex items-center cursor-pointer bg-slate-50 border border-slate-200 rounded-lg p-3 w-full shadow-sm hover:bg-slate-100/70 transition">
                <input type="checkbox" name="use_committee" id="use_committee" value="1" checked class="w-4 h-4 text-indigo-600 rounded focus:ring-indigo-500">
                <span class="ml-3 text-sm font-bold text-slate-700">Gunakan Komite Tender (Multi-Signature)</span>
            </label>
        </div>
        <?php endif; ?>

        <div id="committeeDefaultSummary" class="mb-8 border p-4 rounded bg-gray-50 flex justify-between items-center">
            <div>
                <label class="block text-md font-bold text-gray-800">Komite Tender (Multi-Signature)</label>
                <p class="text-sm text-gray-600 mt-1">Default Komite: <strong>Hadi Risali, Hartanto, Agnes Sari, Lanny Sularko</strong></p>
            </div>
            <button type="button" onclick="document.getElementById('committeeSection').classList.remove('hidden'); document.getElementById('committeeDefaultSummary').classList.add('hidden');" class="bg-white border border-gray-300 text-gray-700 px-4 py-2 rounded text-sm font-bold hover:bg-gray-100 shadow-sm transition">Ubah Komite</button>
        </div>

        <div id="committeeSection" class="hidden mb-8 border p-4 rounded bg-gray-50">
            <div class="flex justify-between items-end mb-2">
                <label class="block text-md font-bold text-gray-800">Ubah Komite Tender</label>
                
                <div class="flex gap-2">
                    <select id="template_select" onchange="loadTemplate()" class="p-1.5 border border-gray-300 rounded text-xs bg-white">
                        <option value="">-- Pilih Template Komite --</option>
                        <?php foreach($templates as $t): ?>
                            <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['template_name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" onclick="openSaveTemplateModal()" class="bg-green-100 hover:bg-green-200 text-green-800 px-3 py-1.5 rounded text-xs font-bold border border-green-300">Save as Template</button>
                </div>
            </div>
            
            <p class="text-xs text-gray-600 mb-4">Cari pengguna dan klik dua kali (double-click) untuk menambah/menghapus dari komite.</p>
            
            <input type="text" id="search_users" placeholder="Cari nama pengguna..." class="w-full p-2 mb-2 border rounded text-sm">
            
            <div class="flex gap-4">
                <div class="w-1/2">
                    <div class="text-xs font-bold text-gray-500 mb-1 uppercase">Daftar Pengguna</div>
                    <select id="available_users" multiple class="w-full p-2 border border-gray-300 rounded h-48 text-sm" ondblclick="moveItem('available_users', 'selected_committee')">
                        <?php foreach($users as $u): ?>
                            <?php if (!in_array($u['username'], ['hadi.risali', 'hartanto', 'agnes.astuti', 'lanny.sularko'])): ?>
                            <option value="<?= htmlspecialchars($u['username'] ?? '') ?>"><?= htmlspecialchars($u['name'] ?? '') ?> (<?= htmlspecialchars($u['username'] ?? '') ?>)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex flex-col justify-center gap-2">
                    <button type="button" onclick="moveItem('available_users', 'selected_committee')" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 p-2 rounded font-bold">&rarr;</button>
                    <button type="button" onclick="moveItem('selected_committee', 'available_users')" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 p-2 rounded font-bold">&larr;</button>
                </div>
                <div class="w-1/2">
                    <div class="text-xs font-bold text-indigo-600 mb-1 uppercase">Komite Terpilih</div>
                    <select id="selected_committee" multiple class="w-full p-2 border border-indigo-300 bg-indigo-50 rounded h-48 text-sm font-bold" ondblclick="moveItem('selected_committee', 'available_users')">
                        <?php 
                        $default_users = ['hadi.risali' => null, 'hartanto' => null, 'agnes.astuti' => null, 'lanny.sularko' => null];
                        foreach($users as $u) {
                            if(array_key_exists($u['username'], $default_users)) {
                                $default_users[$u['username']] = $u;
                            }
                        }
                        foreach($default_users as $uname => $u) {
                            if ($u) {
                                echo "<option value='{$uname}'>" . htmlspecialchars($u['name']) . " ({$uname})</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <div id="hidden_committee_inputs"></div>
        </div>

        <div class="mb-6">
            <div class="flex justify-between items-center border-b pb-2 mb-3">
                <h3 class="text-md font-bold text-gray-800">Vendor yang Diundang</h3>
                <div class="flex gap-2">
                    <button type="button" onclick="openVendorModal()" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 text-xs font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1">
                        🔍 Search Database
                    </button>
                    <?php if ($is_standard_rfq): ?>
                    <button type="button" id="btnAiInternal" class="bg-gradient-to-r from-purple-500 to-indigo-500 hover:from-purple-600 hover:to-indigo-600 text-white text-xs font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1">
                        ✨ AI Suggest Internal
                    </button>
                    <button type="button" id="btnAiExternal" class="bg-gradient-to-r from-teal-400 to-emerald-500 hover:from-teal-500 hover:to-emerald-600 text-white text-xs font-bold py-1.5 px-3 rounded shadow-sm flex items-center gap-1">
                        🌐 AI Suggest External
                    </button>
                    <?php endif; ?>
                </div>
            </div>

            <div id="aiSuggestionsContainer" class="mb-4 hidden">
                <h4 class="text-sm font-bold text-gray-700 mb-2" id="aiSuggestionTitle">Saran Vendor AI</h4>
                <div id="aiSuggestionsList" class="space-y-2 max-h-64 overflow-y-auto p-2 bg-gray-50 border rounded"></div>
                <div id="aiLoading" class="text-xs text-indigo-600 font-bold hidden italic">Memproses dengan Gemini AI...</div>
            </div>

            <table class="w-full text-sm text-left mb-2" id="vendorTable">
                <thead class="bg-gray-200 text-gray-700 font-bold uppercase text-xs">
                    <tr>
                        <th class="p-2 rounded-tl">Nama Vendor</th>
                        <th class="p-2">Email</th>
                        <th class="p-2">No. WhatsApp</th>
                        <th class="p-2">Nama PIC</th>
                        <th class="p-2 rounded-tr w-10 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody id="vendorBody">
                    <tr>
                        <td class="p-1"><input type="text" name="v_name[]" required class="w-full p-2 border rounded"></td>
                        <td class="p-1"><input type="email" name="v_email[]" required class="w-full p-2 border rounded"></td>
                        <td class="p-1"><input type="text" name="v_wa[]" placeholder="628..." required class="w-full p-2 border rounded"></td>
                        <td class="p-1"><input type="text" name="v_pic[]" required class="w-full p-2 border rounded"></td>
                        <td class="p-1 text-center"><button type="button" onclick="removeVendorRow(this)" class="text-red-500 hover:text-red-700 pt-1"><svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button></td>
                    </tr>
                </tbody>
            </table>
            <button type="button" onclick="addVendorRow()" class="text-xs bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-1 px-3 rounded">+ Tambah Vendor</button>
        </div>

        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-3 rounded shadow transition">Buat RFQ & Kirim Undangan</button>
    </form>
</div>

<!-- Submitting Overlay -->
<div id="rfqSubmittingOverlay" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl px-8 py-6 flex flex-col items-center gap-3">
        <svg class="animate-spin h-8 w-8 text-indigo-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <p class="text-sm font-bold text-gray-700">Membuat RFQ & Mengirim Undangan ke Vendor...</p>
        <p class="text-xs text-gray-500">Mohon tunggu, jangan tutup halaman ini.</p>
    </div>
</div>

<!-- Save Template Modal -->
<div id="saveTemplateModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-md p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-2">Simpan Template Komite</h3>
        <p class="text-sm text-gray-600 mb-3">Masukkan nama untuk Template Komite ini:</p>
        <input type="text" id="saveTemplateName" onkeydown="if(event.key==='Enter'){ event.preventDefault(); confirmSaveTemplate(); }" class="w-full p-2 border border-gray-300 rounded focus:ring-indigo-500" placeholder="Contoh: Komite IT Standard">
        <div class="flex justify-end gap-2 mt-4">
            <button type="button" onclick="closeSaveTemplateModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded text-sm">Batal</button>
            <button type="button" onclick="confirmSaveTemplate()" class="bg-green-600 hover:bg-green-700 text-white font-bold py-2 px-4 rounded text-sm shadow">Simpan</button>
        </div>
    </div>
</div>

<!-- Vendor Search Modal -->
<div id="vendorSearchModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black bg-opacity-50">
    <div class="bg-white rounded-lg shadow-xl w-11/12 max-w-4xl flex flex-col max-h-[90vh]">
        <div class="flex justify-between items-center p-4 border-b">
            <h3 class="text-lg font-bold text-gray-800">Search Vendor Database</h3>
            <button type="button" onclick="closeVendorModal()" class="text-gray-500 hover:text-gray-800 text-xl font-bold">&times;</button>
        </div>
        <div class="p-4 border-b bg-gray-50 flex gap-2 items-end">
            <div class="flex flex-col flex-1">
                <label class="text-xs font-bold text-gray-600 mb-1">Company Name</label>
                <input type="text" id="vsName" class="text-sm p-1.5 border rounded focus:ring-indigo-500" placeholder="e.g. PT Jaya...">
            </div>
            <div class="flex flex-col flex-1">
                <label class="text-xs font-bold text-gray-600 mb-1">Company Type</label>
                <select id="vsType" class="text-sm p-1.5 border rounded focus:ring-indigo-500 bg-white">
                    <option value="">All Types</option>
                    <?php foreach($vss_company_types as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>"><?= htmlspecialchars($type) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex flex-col flex-1">
                <label class="text-xs font-bold text-gray-600 mb-1">Core Business</label>
                <select id="vsCore" class="text-sm p-1.5 border rounded focus:ring-indigo-500 bg-white">
                    <option value="">All Core Business</option>
                    <?php foreach($vss_core_businesses as $core): ?>
                        <option value="<?= htmlspecialchars($core) ?>"><?= htmlspecialchars($core) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" onclick="searchVendors(1)" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-4 rounded text-sm shadow">Search</button>
        </div>
        <div class="px-4 pb-4 overflow-y-auto flex-1 bg-gray-50">
            <div id="vsLoading" class="text-sm text-indigo-600 font-bold hidden mt-4 mb-2 italic">Searching...</div>
            <table class="w-full text-left text-sm border-collapse bg-white shadow-sm border border-gray-200 mt-4">
                <thead class="bg-gray-100 text-gray-700 text-xs uppercase font-bold sticky top-0 z-10 shadow-sm">
                    <tr>
                        <th class="p-2 border-b"><input type="checkbox" id="vsSelectAll" onclick="toggleAllVendors(this)"></th>
                        <th class="p-2 border-b">Company Name</th>
                        <th class="p-2 border-b">Core Business</th>
                        <th class="p-2 border-b">PQ Status</th>
                        <th class="p-2 border-b">Risk Status</th>
                    </tr>
                </thead>
                <tbody id="vsResultsBody">
                    <tr><td colspan="5" class="p-4 text-center text-gray-500 text-xs">Enter search criteria and click Search.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t bg-gray-50 flex justify-between items-center rounded-b-lg">
            <div id="vsPagination" class="flex gap-2 items-center text-xs">
                <!-- Pagination will be injected here -->
            </div>
            <div class="flex gap-2">
                <button type="button" onclick="closeVendorModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                <button type="button" onclick="addSelectedVendors()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm shadow">Add Selected</button>
            </div>
        </div>
    </div>
</div>

<script>
const templatesMap = <?= json_encode($template_map) ?>;

let currentVendorResults = [];

function openVendorModal() {
    document.getElementById('vendorSearchModal').classList.remove('hidden');
    if (document.getElementById('vsResultsBody').innerHTML.trim() === '') {
        searchVendors(1);
    }
}

function closeVendorModal() {
    document.getElementById('vendorSearchModal').classList.add('hidden');
}

let currentVSPage = 1;

function searchVendors(page = 1) {
    currentVSPage = page;
    const type = document.getElementById('vsType').value;
    const core = document.getElementById('vsCore').value;
    const name = document.getElementById('vsName').value;
    
    document.getElementById('vsLoading').classList.remove('hidden');
    document.getElementById('vsResultsBody').innerHTML = '';
    
    fetch(`ajax_search_vendors.php?type=${encodeURIComponent(type)}&core=${encodeURIComponent(core)}&name=${encodeURIComponent(name)}&page=${page}`)
        .then(r => r.json())
        .then(res => {
            document.getElementById('vsLoading').classList.add('hidden');
            if (res.status === 'success') {
                currentVendorResults = res.data;
                renderVendorResults(res.data);
                renderVSPagination(res.pagination);
            } else {
                alert('Error searching vendors: ' + (res.message || 'Unknown error'));
            }
        })
        .catch(e => {
            document.getElementById('vsLoading').classList.add('hidden');
            console.error(e);
            alert('Error fetching vendors');
        });
}

function renderVSPagination(pagination) {
    const container = document.getElementById('vsPagination');
    if (!pagination || pagination.total_pages <= 1) {
        container.innerHTML = `<span class="text-gray-500 font-semibold text-xs">Total: ${pagination ? pagination.total_records : 0} vendors</span>`;
        return;
    }
    
    let html = `<span class="text-gray-500 font-semibold mr-2">Page ${pagination.current_page} of ${pagination.total_pages} (${pagination.total_records} vendors)</span>`;
    
    if (pagination.current_page > 1) {
        html += `<button onclick="searchVendors(${pagination.current_page - 1})" class="px-2 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100 text-gray-700">Prev</button>`;
    }
    
    if (pagination.current_page < pagination.total_pages) {
        html += `<button onclick="searchVendors(${pagination.current_page + 1})" class="px-2 py-1 bg-white border border-gray-300 rounded hover:bg-gray-100 text-gray-700">Next</button>`;
    }
    
    container.innerHTML = html;
}

function renderVendorResults(data) {
    const tbody = document.getElementById('vsResultsBody');
    if (data.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-gray-500 text-xs">No vendors found.</td></tr>';
        return;
    }
    
    let html = '';
    data.forEach((v, index) => {
        let pqBadge = v.registration_status === 'approved' ? 'text-green-600 bg-green-100' : 'text-gray-600 bg-gray-100';
        let pqText = (v.registration_status || 'Unknown').toUpperCase().replace(/_/g, ' ');
        
        let riskBadge = v.k2_status === 'approved' ? 'text-green-600 bg-green-100' : (v.k2_status === 'rejected' ? 'text-red-600 bg-red-100' : 'text-yellow-600 bg-yellow-100');
        let riskText = (v.k2_status || 'Unknown').toUpperCase();
        
        let coreBus = v.sub_company_type || '-';
        if (coreBus.length > 30) coreBus = coreBus.substring(0, 30) + '...';
        
        html += `<tr class="border-b hover:bg-gray-50">
            <td class="p-2"><input type="checkbox" class="vs-checkbox" value="${index}"></td>
            <td class="p-2 font-semibold text-gray-800">${v.company_name}</td>
            <td class="p-2 text-xs text-gray-600" title="${v.sub_company_type}">${coreBus}</td>
            <td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold ${pqBadge}">${pqText}</span></td>
            <td class="p-2"><span class="px-2 py-0.5 rounded text-[10px] font-bold ${riskBadge}">${riskText}</span></td>
        </tr>`;
    });
    tbody.innerHTML = html;
}

function toggleAllVendors(chk) {
    document.querySelectorAll('.vs-checkbox').forEach(c => c.checked = chk.checked);
}

function escapeHtmlQuotes(str) {
    if (!str) return '';
    return str.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function addSelectedVendors() {
    const checkboxes = document.querySelectorAll('.vs-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select at least one vendor.');
        return;
    }
    
    let firstRow = document.querySelector('#vendorBody tr');
    let firstRowEmpty = false;
    if (firstRow) {
        let inputs = firstRow.querySelectorAll('input');
        if (inputs.length >= 4 && !inputs[0].value && !inputs[1].value && !inputs[2].value && !inputs[3].value) {
            firstRowEmpty = true;
        }
    }
    
    checkboxes.forEach((chk, idx) => {
        const v = currentVendorResults[chk.value];
        const rowHtml = `<tr>
            <td class="p-1"><input type="text" name="v_name[]" required class="w-full p-2 border rounded" value="${escapeHtmlQuotes(v.company_name)}"></td>
            <td class="p-1"><input type="email" name="v_email[]" required class="w-full p-2 border rounded" value="${escapeHtmlQuotes(v.email)}"></td>
            <td class="p-1"><input type="text" name="v_wa[]" placeholder="628..." required class="w-full p-2 border rounded" value="${escapeHtmlQuotes(v.phone)}"></td>
            <td class="p-1"><input type="text" name="v_pic[]" required class="w-full p-2 border rounded" value="${escapeHtmlQuotes(v.contact_person)}"></td>
            <td class="p-1 text-center"><button type="button" onclick="removeVendorRow(this)" class="text-red-500 hover:text-red-700 pt-1"><svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button></td>
        </tr>`;
        
        if (idx === 0 && firstRowEmpty) {
            firstRow.outerHTML = rowHtml;
            firstRowEmpty = false; 
        } else {
            document.getElementById('vendorBody').insertAdjacentHTML('beforeend', rowHtml);
        }
    });
    
    closeVendorModal();
}

function addVendorRow() {
    const row = `<tr>
        <td class="p-1"><input type="text" name="v_name[]" required class="w-full p-2 border rounded"></td>
        <td class="p-1"><input type="email" name="v_email[]" required class="w-full p-2 border rounded"></td>
        <td class="p-1"><input type="text" name="v_wa[]" placeholder="628..." required class="w-full p-2 border rounded"></td>
        <td class="p-1"><input type="text" name="v_pic[]" required class="w-full p-2 border rounded"></td>
        <td class="p-1 text-center"><button type="button" onclick="removeVendorRow(this)" class="text-red-500 hover:text-red-700 pt-1"><svg class="w-5 h-5 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button></td>
    </tr>`;
    document.getElementById('vendorBody').insertAdjacentHTML('beforeend', row);
}

function removeVendorRow(btn) {
    btn.closest('tr').remove();
}

function moveItem(fromId, toId) {
    const fromBox = document.getElementById(fromId);
    const toBox = document.getElementById(toId);
    Array.from(fromBox.selectedOptions).forEach(option => { toBox.add(option); });
    updateHiddenInputs();
}

function moveAll(fromId, toId) {
    const fromBox = document.getElementById(fromId);
    const toBox = document.getElementById(toId);
    Array.from(fromBox.options).forEach(option => { toBox.add(option); });
}

function updateHiddenInputs() {
    const container = document.getElementById('hidden_committee_inputs');
    container.innerHTML = '';
    const selected = document.getElementById('selected_committee');
    Array.from(selected.options).forEach(opt => {
        container.innerHTML += `<input type="hidden" name="committee[]" value="${opt.value}">`;
    });
}

function loadTemplate() {
    const tid = document.getElementById('template_select').value;
    if(!tid) return;
    
    const members = templatesMap[tid] || [];
    moveAll('selected_committee', 'available_users'); // Reset
    
    const available = document.getElementById('available_users');
    const selected = document.getElementById('selected_committee');
    
    Array.from(available.options).forEach(opt => {
        if(members.includes(opt.value)) selected.add(opt);
    });
    updateHiddenInputs();
}

function openSaveTemplateModal() {
    updateHiddenInputs();
    const selected = document.getElementById('selected_committee');
    if(selected.options.length === 0) {
        alert("Pilih minimal satu anggota komite sebelum menyimpan template.");
        return;
    }

    document.getElementById('saveTemplateName').value = '';
    document.getElementById('saveTemplateModal').classList.remove('hidden');
    document.getElementById('saveTemplateName').focus();
}

function closeSaveTemplateModal() {
    document.getElementById('saveTemplateModal').classList.add('hidden');
}

function confirmSaveTemplate() {
    const name = document.getElementById('saveTemplateName').value.trim();
    if(!name) return;

    const formData = new FormData(document.getElementById('rfqForm'));
    formData.append('action', 'save_template');
    formData.append('template_name', name);

    fetch(window.location.href, {
        method: 'POST',
        body: formData
    }).then(r => r.json()).then(res => {
        if(res.status === 'success') {
            closeSaveTemplateModal();
            alert("Template berhasil disimpan!");
            location.reload();
        } else {
            alert("Terjadi kesalahan saat menyimpan template.");
        }
    });
}

// Initialize hidden inputs on load
updateHiddenInputs();

document.getElementById('search_users').addEventListener('keyup', function() {
    let filter = this.value.toLowerCase();
    let options = document.getElementById('available_users').options;
    for (let i = 0; i < options.length; i++) {
        let txt = options[i].text.toLowerCase();
        options[i].style.display = txt.includes(filter) ? '' : 'none';
    }
});

if (document.getElementById('use_committee')) {
    document.getElementById('use_committee').addEventListener('change', function() {
        const commSection = document.getElementById('committeeSection');
        if (this.checked) {
            commSection.classList.remove('hidden');
        } else {
            commSection.classList.add('hidden');
        }
    });
}

document.getElementById('rfqForm').addEventListener('submit', function(e) {
    const useComm = document.getElementById('use_committee') ? document.getElementById('use_committee').checked : true;
    if(useComm && document.getElementById('selected_committee').options.length === 0) {
        e.preventDefault();
        alert("Anda harus memilih minimal satu anggota komite!");
        return;
    }

    const submitBtn = this.querySelector('button[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;
    document.getElementById('rfqSubmittingOverlay').classList.remove('hidden');
});

const prItems = <?= json_encode($pr_items) ?>;

function renderAiSuggestions(suggestions) {
    const container = document.getElementById('aiSuggestionsContainer');
    const list = document.getElementById('aiSuggestionsList');
    list.innerHTML = '';
    
    if(!suggestions || suggestions.length === 0) {
        list.innerHTML = '<div class="text-sm text-gray-500 p-2">Tidak ada saran ditemukan.</div>';
    } else {
        suggestions.forEach(v => {
            const confidenceHtml = v.confidence ? `<span class="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded ml-2">Confidence: ${v.confidence}%</span>` : '';
            const websiteHtml = v.website ? `<a href="${v.website.startsWith('http') ? v.website : 'https://'+v.website}" target="_blank" class="text-xs text-blue-600 hover:underline ml-2" title="Website"><svg class="inline w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path></svg> ${v.website}</a>` : '';
            const sourceHtml = v.source_url ? `<a href="${v.source_url.startsWith('http') ? v.source_url : 'https://'+v.source_url}" target="_blank" class="text-xs text-indigo-500 hover:underline ml-2 italic">Source</a>` : '';

            const html = `
            <div class="flex justify-between items-center p-2 border-b bg-white rounded shadow-sm">
                <div>
                    <div class="font-bold text-sm text-gray-800 flex items-center flex-wrap">
                        ${v.company_name} 
                        ${confidenceHtml}
                        ${websiteHtml}
                        ${sourceHtml}
                    </div>
                    <div class="text-xs text-gray-600 mt-1">${v.core_business || v.description || '-'}</div>
                </div>
                <button type="button" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 text-xs font-bold py-1 px-2 rounded add-vendor-btn whitespace-nowrap" data-name="${v.company_name.replace(/"/g, '&quot;')}" data-email="${v.email || ''}" data-wa="${v.phone || ''}" data-pic="${v.pic || ''}">
                    + Tambah
                </button>
            </div>`;
            list.insertAdjacentHTML('beforeend', html);
        });
        
        // Bind add buttons
        document.querySelectorAll('.add-vendor-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const row = `<tr>
                    <td class="p-1"><input type="text" name="v_name[]" value="${this.dataset.name}" required class="w-full p-2 border rounded"></td>
                    <td class="p-1"><input type="email" name="v_email[]" value="${this.dataset.email}" required class="w-full p-2 border rounded"></td>
                    <td class="p-1"><input type="text" name="v_wa[]" value="${this.dataset.wa}" placeholder="628..." required class="w-full p-2 border rounded"></td>
                    <td class="p-1"><input type="text" name="v_pic[]" value="${this.dataset.pic}" required class="w-full p-2 border rounded"></td>
                </tr>`;
                document.getElementById('vendorBody').insertAdjacentHTML('beforeend', row);
                this.disabled = true;
                this.classList.add('opacity-50', 'cursor-not-allowed');
                this.innerText = 'Ditambahkan';
            });
        });
    }
    
    container.classList.remove('hidden');
}

if(document.getElementById('btnAiInternal')) {
    document.getElementById('btnAiInternal').addEventListener('click', function() {
        if(prItems.length === 0) return;
        document.getElementById('aiSuggestionsContainer').classList.remove('hidden');
        document.getElementById('aiSuggestionTitle').innerText = "Saran Vendor AI (Internal Database)";
        document.getElementById('aiSuggestionsList').innerHTML = '';
        document.getElementById('aiLoading').classList.remove('hidden');
        
        fetch('ajax_ai_vendor_internal.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ pr_items: prItems })
        })
        .then(r => r.json())
        .then(res => {
            document.getElementById('aiLoading').classList.add('hidden');
            renderAiSuggestions(res.suggestions);
        })
        .catch(e => {
            document.getElementById('aiLoading').classList.add('hidden');
            alert('Gagal memproses AI Internal');
            console.error(e);
        });
    });
}

if(document.getElementById('btnAiExternal')) {
    document.getElementById('btnAiExternal').addEventListener('click', function() {
        if(prItems.length === 0) return;
        document.getElementById('aiSuggestionsContainer').classList.remove('hidden');
        document.getElementById('aiSuggestionTitle').innerText = "Saran Vendor AI (External Internet)";
        document.getElementById('aiSuggestionsList').innerHTML = '';
        document.getElementById('aiLoading').classList.remove('hidden');
        
        fetch('ajax_ai_vendor_external.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ pr_items: prItems })
        })
        .then(r => r.json())
        .then(res => {
            document.getElementById('aiLoading').classList.add('hidden');
            renderAiSuggestions(res.suggestions);
        })
        .catch(e => {
            document.getElementById('aiLoading').classList.add('hidden');
            alert('Gagal memproses AI External');
            console.error(e);
        });
    });
}

</script>

<?php require_once '../includes/footer.php'; ?>