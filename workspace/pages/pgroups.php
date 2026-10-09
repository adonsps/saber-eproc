<?php
// pages/pgroups.php
require_once '../includes/auth.php';
require_manager(); // Both roles allowed
require_once '../config/database.php';


$role = $_SESSION['role'];
$current_user = $_SESSION['name'];

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $pgroup_code = strtoupper(trim($_POST['pgroup_code'] ?? ''));

    $label = $_POST['label'] ?? 'Team GP Non Project';
    $team_name = $_POST['team_name'] ?? null;
    if ($team_name === '') $team_name = null;

    if ($action && $pgroup_code) {
        if ($role === 'Category Manager') {
            // Category Manager direct actions
            if ($action === 'add' || $action === 'approve_add' || $action === 'reject_remove' || $action === 'update') {
                $stmt = $pdo->prepare("INSERT INTO allowed_pgroups (pgroup_code, status, suggested_by, label, team_name) VALUES (?, 'ACTIVE', NULL, ?, ?) ON DUPLICATE KEY UPDATE status = 'ACTIVE', suggested_by = NULL, label = VALUES(label), team_name = VALUES(team_name)");
                $stmt->execute([$pgroup_code, $label, $team_name]);
            } elseif ($action === 'remove' || $action === 'approve_remove' || $action === 'reject_add') {
                $stmt = $pdo->prepare("DELETE FROM allowed_pgroups WHERE pgroup_code = ?");
                $stmt->execute([$pgroup_code]);
            }
        } else {
            // Standard Manager suggestions
            if ($action === 'suggest_add') {
                // Only insert if it doesn't already exist
                $stmt = $pdo->prepare("INSERT IGNORE INTO allowed_pgroups (pgroup_code, status, suggested_by, label, team_name) VALUES (?, 'SUGGESTED_ADD', ?, ?, ?)");
                $stmt->execute([$pgroup_code, $current_user, $label, $team_name]);
            } elseif ($action === 'suggest_remove') {
                // Only mark as suggested remove if it's currently active
                $stmt = $pdo->prepare("UPDATE allowed_pgroups SET status = 'SUGGESTED_REMOVE', suggested_by = ? WHERE pgroup_code = ? AND status = 'ACTIVE'");
                $stmt->execute([$current_user, $pgroup_code]);
            }
        }
        header("Location: pgroups.php?success=1");
        exit;
    }
}

require_once '../includes/header.php';

// --- FETCH DATA ---
$stmt = $pdo->query("SELECT pgroup_code, status, suggested_by, label, team_name FROM allowed_pgroups ORDER BY pgroup_code ASC");
$pgroups = $stmt->fetchAll();

$teams = [
    'Corporate Services, General Supplies & Marketing',
    'Technology, Industrial, and General Services',
    'FNB and Operational Merchandise',
    'Admin Team',
    'Project Konstruksi Retail',
    'Project Konstruksi Properti',
    'Maintenance & Facility Management'
];
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<div class="mb-6 flex justify-between items-end border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">P.Group Whitelist</h2>
        <?php if ($role === 'Category Manager'): ?>
            <p class="text-gray-600">Category Manager Access: Manage allowed P.Groups and review suggestions.</p>
        <?php else: ?>
            <p class="text-gray-600">Team View: Suggest adding or removing P.Groups for the AI Gatekeeper.</p>
        <?php endif; ?>
    </div>
    <div class="flex space-x-3">
        <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow">&larr; Back</a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 shadow-sm rounded">
        <p class="font-bold">Action Completed</p>
        <p>The P.Group list has been updated successfully.</p>
    </div>
<?php endif; ?>

<form action="pgroups.php" method="POST" class="bg-white p-6 rounded-lg shadow-md border border-gray-200 mb-8">
    <label class="block text-gray-700 text-sm font-bold mb-2" for="pgroup_code">
        <?= $role === 'Category Manager' ? 'Add New P.Group to Whitelist' : 'Suggest New P.Group to Whitelist' ?>
    </label>
    <div class="flex items-end space-x-4">
        <div class="w-64">
            <input type="text" name="pgroup_code" id="pgroup_code" required maxlength="10" placeholder="e.g. A99"
                   class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 uppercase leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500">
        </div>
        <div class="w-48">
            <label class="block text-gray-600 text-xs mb-1">Label</label>
            <select name="label" class="shadow border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="Team GP Non Project">Team GP Non Project</option>
                <option value="Team GP Project">Team GP Project</option>
            </select>
        </div>
        <div class="w-64">
            <label class="block text-gray-600 text-xs mb-1">Team Fallback</label>
            <select name="team_name" class="shadow border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">-- None --</option>
                <?php foreach ($teams as $t): ?>
                    <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <input type="hidden" name="action" value="<?= $role === 'Category Manager' ? 'add' : 'suggest_add' ?>">
        <button type="submit" class="<?= $role === 'Category Manager' ? 'bg-indigo-600 hover:bg-indigo-700' : 'bg-yellow-500 hover:bg-yellow-600' ?> text-white font-bold py-2 px-6 rounded shadow transition duration-150">
            <?= $role === 'Category Manager' ? 'Add to Whitelist' : 'Submit Suggestion' ?>
        </button>
    </div>
</form>

<div class="mb-6 flex space-x-3">
    <button type="button" class="quick-filter-btn px-4 py-2 bg-gray-50 text-gray-700 font-bold rounded-lg shadow-sm border border-gray-200 hover:bg-gray-100 transition ring-2 ring-offset-2 ring-gray-400 focus:outline-none" data-status="">
        View All
    </button>
    <button type="button" class="quick-filter-btn px-4 py-2 bg-yellow-50 text-yellow-700 font-bold rounded-lg shadow-sm border border-yellow-200 hover:bg-yellow-100 transition focus:outline-none" data-status="SUGGESTED">
        🔔 Show Pending Suggestions
    </button>
</div>

<div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
    <div class="overflow-y-auto" style="max-height: 600px;">
        <table id="pgroupTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider w-1/5">P.Group Code</th>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider w-1/5">Status</th>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider w-1/5">Label</th>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider w-1/5">Team Fallback</th>
                    <th class="bg-gray-100 px-5 py-3 border-b-2 border-gray-300 text-xs font-bold text-gray-600 uppercase tracking-wider text-right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pgroups as $row): ?>
                    <?php 
                        $is_pending = strpos($row['status'], 'SUGGESTED') !== false;
                        $display_status = $row['status'] === 'ACTIVE' ? 'Active' : ($row['status'] === 'SUGGESTED_ADD' ? 'Pending Add' : 'Pending Remove');
                    ?>
                    <tr class="hover:bg-indigo-50 transition border-b border-gray-100 <?= $is_pending ? 'bg-yellow-50/30' : '' ?>">
                        <td class="px-5 py-3 text-sm font-bold text-gray-900"><?= htmlspecialchars($row['pgroup_code']) ?></td>
                        
                        <td class="px-5 py-3 text-sm" data-search="<?= $is_pending ? 'SUGGESTED' : 'ACTIVE' ?>">
                            <?php if ($row['status'] === 'ACTIVE'): ?>
                                <span class="bg-green-100 text-green-800 py-1 px-2 rounded text-[10px] font-bold border border-green-200">Active</span>
                            <?php else: ?>
                                <span class="bg-yellow-100 text-yellow-800 py-1 px-2 rounded text-[10px] font-bold border border-yellow-200"><?= $display_status ?></span>
                                <div class="text-[10px] text-gray-500 mt-1">Suggested by: <?= htmlspecialchars($row['suggested_by']) ?></div>
                            <?php endif; ?>
                        </td>

                        <form method="POST" action="pgroups.php" class="m-0 p-0">
                            <input type="hidden" name="pgroup_code" value="<?= htmlspecialchars($row['pgroup_code']) ?>">
                            <td class="px-5 py-3 text-sm">
                                <?php if ($role === 'Category Manager'): ?>
                                    <select name="label" class="border rounded px-2 py-1 text-xs w-full bg-white">
                                        <option value="Team GP Non Project" <?= $row['label'] === 'Team GP Non Project' ? 'selected' : '' ?>>Team GP Non Project</option>
                                        <option value="Team GP Project" <?= $row['label'] === 'Team GP Project' ? 'selected' : '' ?>>Team GP Project</option>
                                    </select>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['label']) ?>
                                <?php endif; ?>
                            </td>

                            <td class="px-5 py-3 text-sm">
                                <?php if ($role === 'Category Manager'): ?>
                                    <select name="team_name" class="border rounded px-2 py-1 text-xs w-full bg-white">
                                        <option value="">-- None --</option>
                                        <?php foreach ($teams as $t): ?>
                                            <option value="<?= htmlspecialchars($t) ?>" <?= $row['team_name'] === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <?= htmlspecialchars($row['team_name'] ?? '') ?>
                                <?php endif; ?>
                            </td>

                        <td class="px-5 py-3 text-sm text-right whitespace-nowrap">
                                
                                <?php if ($role === 'Category Manager'): ?>
                                    
                                    <?php if ($row['status'] === 'ACTIVE'): ?>
                                        <button type="submit" name="action" value="update" class="text-xs bg-indigo-50 text-indigo-600 border border-indigo-200 hover:bg-indigo-100 font-bold py-1 px-2 rounded transition mr-1">Save</button>
                                        <button type="submit" name="action" value="remove" class="text-xs bg-red-50 text-red-600 border border-red-200 hover:bg-red-100 font-bold py-1 px-2 rounded transition">Remove</button>
                                    <?php elseif ($row['status'] === 'SUGGESTED_ADD'): ?>
                                        <button type="submit" name="action" value="approve_add" class="text-xs bg-green-600 text-white hover:bg-green-700 font-bold py-1 px-2 rounded transition mr-1">Approve</button>
                                        <button type="submit" name="action" value="reject_add" class="text-xs bg-gray-200 text-gray-700 hover:bg-gray-300 font-bold py-1 px-2 rounded transition">Reject</button>
                                    <?php elseif ($row['status'] === 'SUGGESTED_REMOVE'): ?>
                                        <button type="submit" name="action" value="approve_remove" class="text-xs bg-red-600 text-white hover:bg-red-700 font-bold py-1 px-2 rounded transition mr-1">Approve Remove</button>
                                        <button type="submit" name="action" value="reject_remove" class="text-xs bg-gray-200 text-gray-700 hover:bg-gray-300 font-bold py-1 px-2 rounded transition">Keep</button>
                                    <?php endif; ?>

                                <?php else: ?>
                                    
                                    <?php if ($row['status'] === 'ACTIVE'): ?>
                                        <button type="submit" name="action" value="suggest_remove" class="text-xs bg-yellow-50 text-yellow-600 border border-yellow-200 hover:bg-yellow-100 font-bold py-1 px-3 rounded transition flex items-center">
                                            Suggest Remove
                                        </button>
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400 italic">Awaiting Approval</span>
                                    <?php endif; ?>

                                <?php endif; ?>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    $(document).ready(function() {
        var table = $('#pgroupTable').DataTable({
            "pageLength": 50,
            "language": { "search": "Search Code:" }
        });

        $('.quick-filter-btn').on('click', function() {
            var status = $(this).data('status');
            $('.quick-filter-btn').removeClass('ring-2 ring-offset-2 ring-gray-400 ring-yellow-400');
            $(this).addClass('ring-2 ring-offset-2 ' + (status === '' ? 'ring-gray-400' : 'ring-yellow-400'));
            
            // Search the hidden data-search flag in Column 1 (Status)
            table.column(1).search(status).draw();
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>