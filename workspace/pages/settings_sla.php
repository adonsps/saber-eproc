<?php
// pages/settings_sla.php
require_once '../includes/auth.php';
require_manager();
require_once '../config/database.php';
require_once '../includes/sla_rules.php';
require_once '../includes/header.php';

// Creates + seeds `sla_rules` the first time a manager opens this page. Idempotent
// and manager-gated; every other consumer reads the table and falls back to the
// catalogue defaults when it is not there yet.
sla_rules_bootstrap($pdo);
// Repairs company_holidays.id (PK without AUTO_INCREMENT), which silently dropped
// every holiday added after the first. Idempotent.
sla_holidays_ensure_schema($pdo);

// --- HANDLE POST ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_sla_rule'])) {
        sla_rules_update($pdo, $_POST['rule_key'] ?? '', $_POST['sla_days'] ?? 0);
    } elseif (isset($_POST['add_holiday'])) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO company_holidays (holiday_date, description) VALUES (?, ?)");
        $stmt->execute([$_POST['holiday_date'], $_POST['description']]);
    } elseif (isset($_POST['delete_holiday'])) {
        $stmt = $pdo->prepare("DELETE FROM company_holidays WHERE id = ?");
        $stmt->execute([$_POST['holiday_id']]);
    }
    header("Location: settings_sla.php");
    exit;
}

// --- FETCH DATA ---
// The mapping-engine switch and the PGroup -> Team allocation editor used to live
// here. They were removed from this screen; `system_settings.sla_mapping_mode` and
// `pgroup_team_mappings` are untouched and the SLA Report still reads them.
$holidays = $pdo->query("SELECT * FROM company_holidays ORDER BY holiday_date ASC")->fetchAll();
$sla_rules = sla_rules_for_settings($pdo);

// How many whitelisted P.Groups each label-driven rule currently covers, so the
// editor shows the blast radius of a change instead of a bare number.
$label_counts = ['Team GP Project' => 0, 'Team GP Non Project' => 0];
try {
    foreach ($pdo->query("SELECT label, COUNT(*) AS c FROM allowed_pgroups WHERE status = 'ACTIVE' GROUP BY label")->fetchAll() as $lc) {
        if (isset($label_counts[$lc['label']])) $label_counts[$lc['label']] = (int)$lc['c'];
    }
} catch (PDOException $e) {}
$rule_scope = [
    SLA_RULE_GP_PROJECT     => $label_counts['Team GP Project'] . ' P.Group(s)',
    SLA_RULE_GP_NON_PROJECT => $label_counts['Team GP Non Project'] . ' P.Group(s)',
];

?>

<div class="mb-6 flex justify-between items-end border-b pb-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">SLA Configuration</h2>
        <p class="text-sm text-gray-600">Manage the SLA calendar and the target lead time per ticket category.</p>
    </div>
    <a href="sla_report.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded-lg text-sm shadow transition">&larr; Back to SLA Report</a>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
        <h3 class="font-bold text-lg text-gray-700 mb-4 border-b pb-2">Calendar Holidays</h3>
        <form method="POST" class="space-y-3 mb-6">
            <input type="hidden" name="add_holiday" value="1">
            <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Date</label><input type="date" name="holiday_date" required class="w-full p-2 border rounded text-sm"></div>
            <div><label class="block text-xs font-bold text-gray-600 uppercase mb-1">Description</label><input type="text" name="description" placeholder="e.g., Idul Fitri" class="w-full p-2 border rounded text-sm"></div>
            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 rounded text-sm transition">Add Holiday</button>
        </form>
        <div class="max-h-60 overflow-y-auto space-y-2 checkbox-list pr-2">
            <?php foreach ($holidays as $h): ?>
                <div class="flex justify-between items-center bg-gray-50 p-2 rounded border border-gray-100 text-xs">
                    <div><p class="font-bold text-gray-800"><?= date('d M Y', strtotime($h['holiday_date'])) ?></p><p class="text-gray-500"><?= htmlspecialchars($h['description'] ?? '') ?></p></div>
                    <form method="POST" onsubmit="return confirm('Delete this holiday?');"><input type="hidden" name="delete_holiday" value="1"><input type="hidden" name="holiday_id" value="<?= $h['id'] ?>"><button type="submit" class="text-red-500 hover:text-red-700 font-bold">Delete</button></form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="bg-white p-6 rounded-lg shadow-md border border-gray-200">
        <h3 class="font-bold text-lg text-gray-700 mb-1 border-b pb-2">SLA Tracking</h3>
        <p class="text-xs text-gray-500 mb-4">Working days left, counted from the PR date. Weekends and the holidays on the left never count. <span class="font-bold text-green-700">Green</span> 2+ &middot; <span class="font-bold text-yellow-700">yellow</span> 1&ndash;0 &middot; <span class="font-bold text-red-700">red</span> overdue.</p>
        <div class="overflow-y-auto" style="max-height: 500px;">
            <table class="min-w-full text-left text-sm">
                <thead><tr class="bg-gray-50 border-b"><th class="p-3 font-bold text-gray-600 text-xs uppercase">Ticket Category</th><th class="p-3 font-bold text-gray-600 text-xs uppercase w-32">SLA (Days)</th></tr></thead>
                <tbody>
                    <?php foreach ($sla_rules as $r): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3">
                                <div class="font-bold text-indigo-700"><?= htmlspecialchars($r['rule_label']) ?></div>
                                <div class="text-[11px] text-gray-500 mt-0.5"><?= htmlspecialchars($r['hint']) ?></div>
                                <?php if (isset($rule_scope[$r['rule_key']])): ?>
                                    <div class="text-[10px] text-gray-400 mt-1">Covers <?= htmlspecialchars($rule_scope[$r['rule_key']]) ?>.</div>
                                <?php endif; ?>
                            </td>
                            <td class="p-3 align-top">
                                <form method="POST" class="flex items-center gap-2">
                                    <input type="hidden" name="update_sla_rule" value="1">
                                    <input type="hidden" name="rule_key" value="<?= htmlspecialchars($r['rule_key']) ?>">
                                    <input type="number" name="sla_days" min="1" max="365" required value="<?= (int)$r['sla_days'] ?>" class="w-16 p-1.5 border rounded text-xs bg-white">
                                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-3 rounded text-xs transition">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-[11px] text-gray-400 mt-4">A work-type code at the start of the Canvassing Description (<span class="font-semibold">[PR] [PP] [PM] [OS]</span>, written by STT) takes precedence over everything else; after that, Intercompany takes precedence over the P.Group label. Labels are set on the <a href="pgroups.php" class="text-indigo-600 hover:underline font-semibold">P.Group Whitelist</a>.</p>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>