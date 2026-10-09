<?php
// pages/technical_teams.php
require_once '../includes/auth.php';
require_once '../config/database.php';

// Only Category Manager can manage technical team mapping
if ($_SESSION['role'] !== 'Category Manager') {
    die("Access Denied: Only Category Manager can access this page.");
}

// Ensure the mapping table exists
$pdo->exec("CREATE TABLE IF NOT EXISTS technical_team_pgroups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    team_name VARCHAR(255) NOT NULL,
    pgroup VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mapping (team_name, pgroup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$success_msg = '';
$error_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $team_name = trim($_POST['team_name'] ?? '');
    $pgroups = $_POST['pgroups'] ?? [];
    if (!is_array($pgroups)) {
        $pgroups = !empty($_POST['pgroup']) ? [$_POST['pgroup']] : [];
    }

    if ($action === 'add' && $team_name && !empty($pgroups)) {
        $inserted = 0;
        $failed = 0;
        $stmt = $pdo->prepare("INSERT IGNORE INTO technical_team_pgroups (team_name, pgroup) VALUES (?, ?)");
        
        foreach ($pgroups as $pg) {
            $pg = trim($pg);
            if ($pg) {
                $stmt->execute([$team_name, $pg]);
                if ($stmt->rowCount() > 0) $inserted++;
                else $failed++;
            }
        }
        
        if ($inserted > 0) {
            $success_msg = "Successfully added $inserted mapping(s).";
        }
        if ($failed > 0) {
            $error_msg = "$failed mapping(s) were ignored (already exist).";
        }
    } elseif ($action === 'remove' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM technical_team_pgroups WHERE id = ?");
        $stmt->execute([$id]);
        $success_msg = "Mapping removed successfully.";
    }
}

// Fetch existing mappings
$stmt = $pdo->query("SELECT id, team_name, pgroup, created_at FROM technical_team_pgroups ORDER BY team_name ASC, pgroup ASC");
$mappings = $stmt->fetchAll();

// Fetch all available PGroups from whitelist
$stmt = $pdo->query("SELECT pgroup_code, label FROM allowed_pgroups WHERE status = 'ACTIVE' ORDER BY pgroup_code ASC");
$available_pgroups = $stmt->fetchAll();

// Fetch distinct team names from users for autocomplete
$stmt = $pdo->query("SELECT DISTINCT team_name FROM users WHERE team_name IS NOT NULL AND team_name != '' ORDER BY team_name ASC");
$existing_teams = $stmt->fetchAll(PDO::FETCH_COLUMN);

require_once '../includes/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    /* Tailwind override for Select2 */
    .select2-container .select2-selection--multiple {
        border-color: #d1d5db !important;
        min-height: 42px;
        padding: 2px 8px;
        border-radius: 0.25rem;
    }
    .select2-container--default.select2-container--focus .select2-selection--multiple {
        border-color: #6366f1 !important;
        box-shadow: 0 0 0 1px #6366f1;
    }
</style>

<div class="container mx-auto px-4 py-8 max-w-5xl">
    <div class="mb-8 border-b pb-4">
        <h1 class="text-3xl font-bold text-gray-800">Technical Teams PGroup Mapping</h1>
        <p class="text-gray-600 mt-2">Manage which PGroups are accessible to each Technical Department team.</p>
    </div>

    <?php if ($success_msg): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4">
            <?= htmlspecialchars($success_msg) ?>
        </div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4">
            <?= htmlspecialchars($error_msg) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Add New Mapping</h2>
        <form method="POST" class="flex flex-col md:flex-row gap-4 items-end">
            <input type="hidden" name="action" value="add">
            
            <div class="w-full md:w-1/3">
                <label class="block text-sm font-medium text-gray-700 mb-1">Team Name</label>
                <input type="text" name="team_name" list="team_names" required class="w-full px-4 py-2 border border-gray-300 rounded focus:ring-indigo-500 focus:border-indigo-500" placeholder="e.g. General Affairs (GA)">
                <datalist id="team_names">
                    <?php foreach ($existing_teams as $t): ?>
                        <option value="<?= htmlspecialchars($t) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="w-full md:w-1/3">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                    PGroup 
                    <span class="text-xs text-indigo-600 font-normal ml-2">(from <a href="pgroups.php" class="underline hover:text-indigo-800">PGroup Whitelist</a>)</span>
                </label>
                <select name="pgroups[]" id="pgroups_select" multiple required class="w-full px-4 py-2 border border-gray-300 rounded focus:ring-indigo-500 focus:border-indigo-500">
                    <?php foreach ($available_pgroups as $pg): ?>
                        <option value="<?= htmlspecialchars($pg['pgroup_code']) ?>">
                            <?= htmlspecialchars($pg['pgroup_code']) ?> - <?= htmlspecialchars($pg['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="w-full md:w-auto">
                <button type="submit" class="w-full md:w-auto bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow transition duration-150">
                    Add Mapping
                </button>
            </div>
        </form>
    </div>

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
        <h2 class="text-xl font-bold mb-4 text-gray-800">Current Mappings</h2>
        <table class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider">Team Name</th>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider">Mapped PGroup</th>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mappings)): ?>
                    <tr><td colspan="3" class="px-5 py-4 text-center text-gray-500">No mappings found.</td></tr>
                <?php else: ?>
                    <?php foreach ($mappings as $map): ?>
                        <tr class="border-b border-gray-100 hover:bg-gray-50">
                            <td class="px-5 py-3 font-medium text-gray-800"><?= htmlspecialchars($map['team_name']) ?></td>
                            <td class="px-5 py-3 text-gray-600 font-mono text-sm"><?= htmlspecialchars($map['pgroup']) ?></td>
                            <td class="px-5 py-3 text-right">
                                <form method="POST" onsubmit="return confirm('Remove this mapping?');" class="inline">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="id" value="<?= $map['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-900 font-bold text-sm bg-red-50 hover:bg-red-100 px-3 py-1 rounded">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#pgroups_select').select2({
        placeholder: "Search and select PGroups...",
        allowClear: true,
        width: '100%'
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
