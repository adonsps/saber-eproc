<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

$user = announcement_current_user($pdo);
$canCreate = can_create_announcement($user);
$userId = (int)$_SESSION['user_id'];

$search = trim($_GET['search'] ?? '');
$confidentiality = $_GET['confidentiality'] ?? '';
$urgency = $_GET['urgency'] ?? '';

$allowedConfidentiality = ['EXTERNAL', 'INTERNAL_KLG', 'GP_ONLY'];
$allowedUrgency = ['URGENT', 'NOT_URGENT'];

$where = ["a.is_active = 1"];
$params = [':reads_user' => $userId];

if ($canCreate) {
    $where[] = "(a.status = 'PUBLISHED' OR a.created_by = :viewer_id)";
    $params[':viewer_id'] = $userId;
} else {
    $where[] = "a.status = 'PUBLISHED'";
}

// Role-based confidentiality access
$accessList = announcement_accessible_confidentialities($user);
foreach ($accessList as $i => $val) {
    $params[":conf_{$i}"] = $val;
}
$confPH = implode(',', array_map(fn($i) => ":conf_{$i}", array_keys($accessList)));
$where[] = "(a.confidentiality IN ($confPH) OR a.created_by = :conf_creator)";
$params[':conf_creator'] = $userId;

if ($search !== '') {
    $where[] = "a.title LIKE :search";
    $params[':search'] = '%' . $search . '%';
}

if (in_array($confidentiality, $allowedConfidentiality, true)) {
    $where[] = "a.confidentiality = :confidentiality";
    $params[':confidentiality'] = $confidentiality;
}

if (in_array($urgency, $allowedUrgency, true)) {
    $where[] = "a.urgency = :urgency";
    $params[':urgency'] = $urgency;
}

$sql = "
    SELECT a.*, u.name AS creator_name, ar_read.read_at AS read_at
    FROM announcements a
    JOIN users u ON u.id = a.created_by
    LEFT JOIN announcement_reads ar_read ON ar_read.announcement_id = a.id AND ar_read.user_id = :reads_user
    WHERE " . implode(' AND ', $where) . "
    ORDER BY (a.urgency = 'URGENT') DESC, a.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="max-w-6xl mx-auto">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4 border-b border-slate-200 pb-5 mb-6">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Announcements</h2>
            <p class="text-sm text-slate-500 mt-1">Global announcement center for GP Workspace.</p>
        </div>
        <?php if ($canCreate): ?>
            <a href="announcement_create.php" class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700 shadow-sm">
                Create Announcement
            </a>
        <?php endif; ?>
    </div>

    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6 bg-slate-50 border border-slate-200 rounded-lg p-4">
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Search Title</label>
            <input type="text" name="search" value="<?= announcement_escape($search) ?>" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="Search by title">
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Confidentiality</label>
            <select name="confidentiality" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">All</option>
                <?php foreach ($allowedConfidentiality as $option): ?>
                    <option value="<?= $option ?>" <?= $confidentiality === $option ? 'selected' : '' ?>><?= announcement_escape(announcement_confidentiality_label($option)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-bold text-slate-600 mb-1">Urgency</label>
            <select name="urgency" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <option value="">All</option>
                <?php foreach ($allowedUrgency as $option): ?>
                    <option value="<?= $option ?>" <?= $urgency === $option ? 'selected' : '' ?>><?= announcement_escape(announcement_label($option)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="w-full px-4 py-2 rounded-lg bg-slate-800 text-white text-sm font-bold hover:bg-slate-900">Apply</button>
            <a href="announcement_list.php" class="px-4 py-2 rounded-lg border border-slate-200 text-sm font-bold text-slate-600 hover:bg-white">Reset</a>
        </div>
    </form>

    <?php if (empty($announcements)): ?>
        <div class="text-center py-14 border border-dashed border-slate-300 rounded-lg text-slate-500">
            No announcements found.
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <?php foreach ($announcements as $announcement): ?>
                <?php $isRead = !empty($announcement['read_at']); ?>
                <article class="border rounded-lg p-5 shadow-sm flex flex-col <?= $isRead ? 'border-slate-200 bg-slate-50' : 'border-slate-200 bg-white' ?>">
                    <div class="mb-3">
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <h3 class="text-lg font-bold text-slate-800 uppercase leading-snug"><?= announcement_escape($announcement['title']) ?></h3>
                            <?php if ($isRead): ?>
                                <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-slate-400 flex-shrink-0 mt-0.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    Read
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span class="text-[11px] font-bold px-2 py-1 rounded <?= announcement_confidentiality_class($announcement['confidentiality']) ?>"><?= announcement_escape(announcement_confidentiality_label($announcement['confidentiality'])) ?></span>
                            <?php if ($announcement['urgency'] === 'URGENT'): ?>
                            <span class="text-[11px] font-bold px-2 py-1 rounded bg-red-100 text-red-700">URGENT</span>
                            <?php endif; ?>
                            <?php if ($announcement['status'] === 'DRAFT'): ?>
                                <span class="text-[11px] font-bold px-2 py-1 rounded bg-amber-100 text-amber-700">DRAFT</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm mb-4">
                        <div>
                            <dt class="text-xs font-bold text-slate-400 uppercase">Created By</dt>
                            <dd class="font-semibold text-slate-700"><?= announcement_escape($announcement['creator_name']) ?></dd>
                        </div>
                        <div>
                            <dt class="text-xs font-bold text-slate-400 uppercase">Created At</dt>
                            <dd class="font-semibold text-slate-700"><?= announcement_escape(announcement_format_datetime($announcement['created_at'])) ?></dd>
                        </div>
                    </dl>

                    <p class="text-sm text-slate-600 leading-relaxed mb-4 line-clamp-3 cursor-default" title="<?= announcement_escape($announcement['summary']) ?>"><?= announcement_escape($announcement['summary']) ?></p>

                    <div class="flex flex-wrap items-center justify-between gap-2 mt-auto">
                        <a href="announcement_detail.php?id=<?= (int)$announcement['id'] ?>" class="inline-flex px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">Read More</a>
                        <?php if ($canCreate && (int)$announcement['created_by'] === (int)$_SESSION['user_id']): ?>
                            <form method="POST" action="announcement_delete.php" class="delete-form">
                                <input type="hidden" name="id" value="<?= (int)$announcement['id'] ?>">
                                <button type="button" class="delete-trigger text-xs font-bold text-red-600 hover:text-red-800">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Delete confirmation modal -->
<div id="deleteModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 hidden">
    <div class="bg-white rounded-xl shadow-2xl p-6 max-w-sm w-full mx-4">
        <h3 class="text-lg font-bold text-slate-800 mb-2">Delete Announcement</h3>
        <p class="text-sm text-slate-500 mb-6">Are you sure you want to delete this announcement? This action cannot be undone.</p>
        <div class="flex justify-end gap-3">
            <button type="button" id="cancelDelete" class="px-4 py-2 rounded-lg border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</button>
            <button type="button" id="confirmDelete" class="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-bold hover:bg-red-700">Delete</button>
        </div>
    </div>
</div>

<script>
    var pendingDeleteForm = null;

    document.querySelectorAll('.delete-trigger').forEach(function(btn) {
        btn.addEventListener('click', function() {
            pendingDeleteForm = this.closest('form');
            document.getElementById('deleteModal').classList.remove('hidden');
        });
    });

    document.getElementById('cancelDelete').addEventListener('click', function() {
        document.getElementById('deleteModal').classList.add('hidden');
        pendingDeleteForm = null;
    });

    document.getElementById('confirmDelete').addEventListener('click', function() {
        if (pendingDeleteForm) pendingDeleteForm.submit();
    });

    document.getElementById('deleteModal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
            pendingDeleteForm = null;
        }
    });
</script>

<?php require_once '../includes/footer.php'; ?>
