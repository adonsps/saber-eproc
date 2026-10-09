<?php
require_once '../includes/auth.php';
require_login(); 
require_once '../config/database.php';

$role = $_SESSION['role'] ?? '';
$current_user = $_SESSION['name'] ?? '';

// Only Category Manager is allowed to access this page
if ($role !== 'Category Manager') {
    header("Location: dashboard.php");
    exit;
}

$message = '';
$message_type = 'success';

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add') {
        $rule_type = trim($_POST['rule_type'] ?? '');
        $rule_value = strtoupper(trim($_POST['rule_value'] ?? ''));
        $pic_name = trim($_POST['pic_name'] ?? '');
        
        if ($rule_type && $rule_value && $pic_name) {
            try {
                $stmt = $pdo->prepare("INSERT INTO pr_admin_routing_rules (rule_type, rule_value, pic_name) VALUES (?, ?, ?)");
                $stmt->execute([$rule_type, $rule_value, $pic_name]);
                $message = "Rule added successfully.";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = "Error adding rule: " . $e->getMessage();
                $message_type = 'error';
            }
        } else {
            $message = "All fields are required.";
            $message_type = 'error';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                $stmt = $pdo->prepare("DELETE FROM pr_admin_routing_rules WHERE id = ?");
                $stmt->execute([$id]);
                $message = "Rule deleted successfully.";
                $message_type = 'success';
            } catch (PDOException $e) {
                $message = "Error deleting rule: " . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
}

// Fetch all rules
try {
    $rules = $pdo->query("
        SELECT * FROM pr_admin_routing_rules 
        ORDER BY 
            CASE rule_type 
                WHEN 'PORG' THEN 1 
                WHEN 'PGROUP' THEN 2 
                WHEN 'PGROUP_LABEL' THEN 3 
                ELSE 4 
            END ASC, 
            rule_value ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rules = [];
    $message = "Error fetching rules: " . $e->getMessage();
    $message_type = 'error';
}

$page_title = "Admin Routing Setup";
require_once '../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-slate-900">Admin Routing Setup</h1>
        <p class="mt-2 text-sm text-slate-500">Manage rules for bypassing AI assignment and routing PRs directly to specific PICs (Intercompany and AON setup).</p>
    </div>

    <?php if ($message): ?>
    <div class="mb-6 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Add Rule Form -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mb-8">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-lg font-semibold text-slate-800">Add New Rule</h3>
        </div>
        <div class="p-6">
            <form action="" method="POST" class="flex flex-col md:flex-row gap-4 items-end">
                <input type="hidden" name="action" value="add">
                
                <div class="flex-1 w-full">
                    <label for="rule_type" class="block text-sm font-medium text-slate-700 mb-1">Rule Type</label>
                    <select name="rule_type" id="rule_type" required class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                        <option value="PORG">POrg</option>
                        <option value="PGROUP">PGroup</option>
                        <option value="PGROUP_LABEL">PGroup Label</option>
                    </select>
                </div>
                
                <div class="flex-1 w-full">
                    <label for="rule_value" class="block text-sm font-medium text-slate-700 mb-1">Target Value</label>
                    <input type="text" name="rule_value" id="rule_value" required placeholder="e.g. FF30, PAC, Team GP Project" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
                
                <div class="flex-1 w-full">
                    <label for="pic_name" class="block text-sm font-medium text-slate-700 mb-1">Assign to PIC</label>
                    <input type="text" name="pic_name" id="pic_name" required placeholder="e.g. Felanny Lieke" class="w-full rounded-lg border-slate-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                </div>
                
                <div class="w-full md:w-auto">
                    <button type="submit" class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors">
                        Add Rule
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Rules Table -->
    <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-200 bg-slate-50">
            <h3 class="text-lg font-semibold text-slate-800">Current Routing Rules</h3>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Priority</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Rule Type</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Target Value</th>
                        <th scope="col" class="px-6 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Assigned PIC</th>
                        <th scope="col" class="px-6 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Action</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    <?php if (empty($rules)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-10 text-center text-sm text-slate-500">
                            No routing rules found. Use the form above to add one.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($rules as $rule): ?>
                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-500">
                                <?php 
                                    if ($rule['rule_type'] === 'PORG') echo '1 (Highest)';
                                    elseif ($rule['rule_type'] === 'PGROUP') echo '2';
                                    elseif ($rule['rule_type'] === 'PGROUP_LABEL') echo '3 (Lowest)';
                                    else echo '-';
                                ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <?php 
                                    $bg = 'bg-slate-100 text-slate-800';
                                    if ($rule['rule_type'] === 'PORG') $bg = 'bg-blue-100 text-blue-800';
                                    if ($rule['rule_type'] === 'PGROUP') $bg = 'bg-purple-100 text-purple-800';
                                    if ($rule['rule_type'] === 'PGROUP_LABEL') $bg = 'bg-orange-100 text-orange-800';
                                ?>
                                <span class="px-2.5 py-1 rounded-full text-xs font-medium <?php echo $bg; ?>">
                                    <?php echo htmlspecialchars($rule['rule_type']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">
                                <?php echo htmlspecialchars($rule['rule_value']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-600 font-medium">
                                <?php echo htmlspecialchars($rule['pic_name']); ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <form action="" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this rule?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $rule['id']; ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 font-medium transition-colors">
                                        Delete
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
