<?php
// pages/rfq_monitoring.php
require_once '../includes/auth.php';
require_login(); 
require_once '../config/database.php';

$user_role = $_SESSION['role'] ?? '';
$username = $_SESSION['username'] ?? '';

// Only Category Manager, Manager, Project Director, QS can access
if (!in_array($user_role, ['Category Manager', 'Manager', 'Project Director', 'QS'])) {
    die("Access Denied");
}

$team_usernames = [];

if (in_array($user_role, ['Manager', 'Category Manager'])) {
    $subordinates = get_all_subordinates($pdo, $username);
    foreach ($subordinates as $sub) {
        $team_usernames[] = $sub['username'];
    }
} elseif (in_array($user_role, ['Project Director', 'QS'])) {
    // Can see everyone
    $stmt = $pdo->query("SELECT username FROM users");
    $team_usernames = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Ensure the array has strings
$team_usernames = array_unique($team_usernames);

// Filter
$selected_member = $_GET['member'] ?? 'all';

$where_clause = "1=1";
$params = [];

if (!empty($team_usernames)) {
    if ($selected_member !== 'all' && in_array($selected_member, $team_usernames)) {
        $where_clause .= " AND r.created_by = ?";
        $params[] = $selected_member;
    } else {
        $in = str_repeat('?,', count($team_usernames) - 1) . '?';
        $where_clause .= " AND r.created_by IN ($in)";
        $params = array_merge($params, $team_usernames);
    }
} else {
    // If team is empty, show nothing
    $where_clause .= " AND 1=0";
}

$query = "
    SELECT r.id, r.package_name, r.created_by, r.created_at, r.deadline, r.status, r.round_number, r.rfq_type,
        (SELECT COUNT(*) FROM rfq_vendors v WHERE v.rfq_id = r.id) as total_vendors,
        (SELECT COUNT(*) FROM rfq_vendors v WHERE v.rfq_id = r.id AND v.submitted_at IS NOT NULL) as total_submissions
    FROM rfqs r 
    WHERE r.id IN (SELECT MAX(id) FROM rfqs GROUP BY IFNULL(parent_id, id))
    AND $where_clause
    ORDER BY r.created_at DESC
";
$stmt_rfqs = $pdo->prepare($query);
$stmt_rfqs->execute($params);
$rfqs = $stmt_rfqs->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">

<div class="max-w-7xl mx-auto py-8">
    <div class="mb-6 flex flex-col md:flex-row justify-between items-start md:items-end border-b pb-4 gap-4">
        <div>
            <h2 class="text-3xl font-bold text-gray-800">Team RFQ Monitoring</h2>
            <p class="text-sm text-gray-500 mt-1">Pantau progress RFQ dari anggota tim Anda.</p>
        </div>
        <div class="flex gap-2">
            <form method="GET" class="flex gap-2 items-center">
                <label class="text-sm font-bold text-gray-600">Filter Anggota Tim:</label>
                <select name="member" class="p-2 border border-gray-300 rounded text-sm bg-white" onchange="this.form.submit()">
                    <option value="all">Semua Anggota</option>
                    <?php foreach($team_usernames as $member): ?>
                        <option value="<?= htmlspecialchars($member) ?>" <?= $selected_member === $member ? 'selected' : '' ?>>
                            <?= htmlspecialchars($member) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
        <table id="monitoringTable" class="min-w-full leading-normal w-full text-left">
            <thead>
                <tr class="bg-gray-100 border-b-2 border-gray-200">
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Tender Name</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Tipe</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Creator</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Ronde</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase">Timeline</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Submissions</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Status</th>
                    <th class="p-3 text-xs font-bold text-gray-600 uppercase text-center">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rfqs as $r): 
                    $raw_status = $r['status'];
                    $deadline_passed = time() > strtotime($r['deadline']);
                    if ($raw_status === 'Unsealed') { $ui_status = 'Opened'; $badge = 'bg-blue-100 text-blue-800 border-blue-200'; }
                    elseif ($raw_status === 'Locked') { $ui_status = 'Locked'; $badge = 'bg-purple-100 text-purple-800 border-purple-200'; }
                    elseif ($raw_status === 'Closed') { $ui_status = 'Closed'; $badge = 'bg-gray-100 text-gray-800 border-gray-200'; } 
                    else {
                        if ($deadline_passed || ($r['total_submissions'] > 0 && $r['total_submissions'] == $r['total_vendors'])) {
                            $ui_status = 'Waiting Opening'; $badge = 'bg-orange-100 text-orange-800 border-orange-200';
                        } else {
                            $ui_status = 'Waiting Submission'; $badge = 'bg-yellow-100 text-yellow-800 border-yellow-200';
                        }
                    }
                ?>
                <tr class="border-b hover:bg-indigo-50 transition">
                    <td class="p-3">
                        <div class="text-[10px] text-gray-500 font-mono font-bold mb-0.5">ID: #<?= $r['id'] ?></div>
                        <div class="font-bold text-indigo-700"><?= htmlspecialchars($r['package_name']) ?></div>
                    </td>
                    <td class="p-3 text-xs font-bold text-gray-600"><?= htmlspecialchars($r['rfq_type'] ?? 'Construction') ?></td>
                    <td class="p-3 text-sm font-bold text-gray-800 bg-gray-50 border-x border-white"><?= htmlspecialchars($r['created_by']) ?></td>
                    <td class="p-3 text-center text-sm font-bold text-gray-600"><?= $r['round_number'] ?></td>
                    <td class="p-3 text-xs text-gray-600"><strong>Deadline:</strong> <br><?= date('d M Y H:i', strtotime($r['deadline'])) ?> WIB</td>
                    <td class="p-3 text-center font-bold text-gray-700"><span class="text-lg <?= $r['total_submissions'] === $r['total_vendors'] ? 'text-green-600' : '' ?>"><?= $r['total_submissions'] ?></span> / <?= $r['total_vendors'] ?></td>
                    <td class="p-3 text-center"><span class="<?= $badge ?> py-1 px-3 rounded-full text-xs font-bold border inline-block whitespace-nowrap"><?= $ui_status ?></span></td>
                    <td class="p-3 text-center">
                        <a href="rfq_dashboard.php?id=<?= $r['id'] ?>" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-1.5 px-3 rounded shadow text-xs inline-block">Monitor RFQ</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#monitoringTable').DataTable({
            "order": [],
            "language": {
                "search": "Cari RFQ:",
                "lengthMenu": "Tampilkan _MENU_ data per halaman",
                "zeroRecords": "Data tidak ditemukan",
                "info": "Menampilkan halaman _PAGE_ dari _PAGES_",
                "infoEmpty": "Tidak ada data yang tersedia",
                "infoFiltered": "(difilter dari _MAX_ total data)"
            }
        });
    });
</script>

<?php require_once '../includes/footer.php'; ?>
