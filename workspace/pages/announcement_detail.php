<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

$id = (int)($_GET['id'] ?? 0);
$userId = (int)$_SESSION['user_id'];

$user = announcement_current_user($pdo);
$accessList = announcement_accessible_confidentialities($user);
$accessPlaceholders = implode(',', array_fill(0, count($accessList), '?'));

$stmt = $pdo->prepare("
    SELECT a.*, u.name AS creator_name
    FROM announcements a
    JOIN users u ON u.id = a.created_by
    WHERE a.id = ?
      AND a.is_active = 1
      AND (a.status = 'PUBLISHED' OR a.created_by = ?)
      AND (a.confidentiality IN ($accessPlaceholders) OR a.created_by = ?)
");
$stmt->execute(array_merge([$id, $userId], $accessList, [$userId]));
$announcement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$announcement) {
    require_once '../includes/header.php';
    echo "<div class='p-8 text-center'><h2 class='text-2xl font-bold text-slate-800'>Announcement not found.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

$canManageOwn = can_create_announcement($user) && (int)$announcement['created_by'] === $userId;

// Quick-publish action for the owner
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'publish' && $canManageOwn && $announcement['status'] === 'DRAFT') {
    $pdo->prepare("UPDATE announcements SET status = 'PUBLISHED' WHERE id = ? AND created_by = ?")->execute([$id, $userId]);
    announcement_send_wa_on_publish($pdo, $id);

    header('Location: announcement_detail.php?id=' . $id);
    exit;
}

$pdo->prepare("UPDATE announcements SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);

if ($announcement['status'] === 'PUBLISHED') {
    $stmt = $pdo->prepare("
        INSERT INTO announcement_reads (announcement_id, user_id, read_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE read_at = read_at
    ");
    $stmt->execute([$id, $userId]);
}

$stmtAtt = $pdo->prepare("SELECT * FROM announcement_attachments WHERE announcement_id = ? ORDER BY id ASC");
$stmtAtt->execute([$id]);
$attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

require_once '../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="mb-5">
        <a href="announcement_list.php" class="inline-flex items-center gap-2 text-sm font-bold text-slate-600 hover:text-slate-900">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            Back to Announcements
        </a>
    </div>
    <div class="text-center border-b border-slate-200 pb-6 mb-6">
        <div class="flex flex-wrap justify-center gap-2 mb-4">
            <span class="text-[11px] font-bold px-2 py-1 rounded <?= announcement_confidentiality_class($announcement['confidentiality']) ?>"><?= announcement_escape(announcement_confidentiality_label($announcement['confidentiality'])) ?></span>
            <span class="text-[11px] font-bold px-2 py-1 rounded <?= $announcement['urgency'] === 'URGENT' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700' ?>"><?= announcement_escape(announcement_label($announcement['urgency'])) ?></span>
            <?php if ($announcement['status'] === 'DRAFT'): ?>
                <span class="text-[11px] font-bold px-2 py-1 rounded bg-amber-100 text-amber-700">DRAFT</span>
            <?php endif; ?>
        </div>

        <h2 class="text-3xl font-extrabold text-slate-900 leading-tight uppercase"><?= announcement_escape($announcement['title']) ?></h2>
        <div class="mt-4 text-sm text-slate-500 space-y-1">
            <p>Created By: <span class="font-semibold text-slate-700"><?= announcement_escape($announcement['creator_name']) ?></span></p>
            <p>Created At: <span class="font-semibold text-slate-700"><?= announcement_escape(announcement_format_datetime($announcement['created_at'])) ?></span></p>
            <p>Views: <span class="font-semibold text-slate-700"><?= (int)$announcement['view_count'] + 1 ?></span></p>
        </div>

        <?php if ($canManageOwn): ?>
            <div class="flex flex-wrap justify-center gap-3 mt-5">
                <a href="announcement_edit.php?id=<?= (int)$announcement['id'] ?>" class="px-4 py-2 rounded-lg border border-slate-200 bg-white text-slate-700 text-sm font-bold hover:bg-slate-50">Edit</a>
                <?php if ($announcement['status'] === 'DRAFT'): ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="publish">
                        <button type="submit" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">Publish</button>
                    </form>
                <?php endif; ?>
                <form method="POST" action="announcement_delete.php" class="delete-form">
                    <input type="hidden" name="id" value="<?= (int)$announcement['id'] ?>">
                    <button type="button" class="delete-trigger px-4 py-2 rounded-lg border border-red-200 bg-red-50 text-red-700 text-sm font-bold hover:bg-red-100">Delete</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="mb-6 rounded-lg bg-slate-50 border border-slate-200 p-4">
        <h3 class="text-xs font-bold text-slate-400 uppercase mb-2">Summary</h3>
        <p class="text-sm text-slate-700 leading-relaxed"><?= announcement_escape($announcement['summary']) ?></p>
    </div>

    <div class="announcement-content text-slate-800 leading-relaxed">
        <?= $announcement['content'] ?>
    </div>

    <?php
        $legacyMime      = $announcement['attachment_mime'] ?? '';
        $legacyIsImage   = !empty($announcement['attachment_path']) && strpos($legacyMime, 'image/') === 0;
        $legacyIsFile    = !empty($announcement['attachment_path']) && !$legacyIsImage;
        $imageAtts       = array_values(array_filter($attachments, function($a) { return strpos($a['mime'], 'image/') === 0; }));
        $fileAtts        = array_values(array_filter($attachments, function($a) { return strpos($a['mime'], 'image/') !== 0; }));
        $hasImages       = $legacyIsImage || !empty($imageAtts);
        $hasFiles        = $legacyIsFile  || !empty($fileAtts) || !empty($announcement['optional_link']);
    ?>

    <?php if ($hasImages || $hasFiles): ?>
        <div class="mt-8 border-t border-slate-200 pt-5 space-y-4">

            <?php if ($hasImages): ?>
                <div>
                    <p class="text-xs font-bold text-slate-400 uppercase mb-3">Images</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php if ($legacyIsImage): ?>
                            <div class="rounded-lg overflow-hidden border border-slate-200 bg-slate-100">
                                <img src="announcement_download.php?id=<?= (int)$announcement['id'] ?>&inline=1"
                                     alt="<?= announcement_escape($announcement['attachment_name']) ?>"
                                     class="zoomable-image w-full max-h-80 object-contain">
                                <p class="px-3 py-1 text-xs text-slate-500 bg-white border-t border-slate-100 truncate"><?= announcement_escape($announcement['attachment_name']) ?></p>
                            </div>
                        <?php endif; ?>
                        <?php foreach ($imageAtts as $att): ?>
                            <div class="rounded-lg overflow-hidden border border-slate-200 bg-slate-100">
                                <img src="announcement_download.php?att_id=<?= (int)$att['id'] ?>&inline=1"
                                     alt="<?= announcement_escape($att['name']) ?>"
                                     class="zoomable-image w-full max-h-80 object-contain">
                                <p class="px-3 py-1 text-xs text-slate-500 bg-white border-t border-slate-100 truncate"><?= announcement_escape($att['name']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($hasFiles): ?>
                <div class="flex flex-wrap gap-3">
                    <?php if (!empty($announcement['optional_link'])): ?>
                        <a href="<?= announcement_escape($announcement['optional_link']) ?>" target="_blank" rel="noopener noreferrer" class="inline-flex px-4 py-2 rounded-lg bg-slate-800 text-white text-sm font-bold hover:bg-slate-900">Open Link</a>
                    <?php endif; ?>
                    <?php if ($legacyIsFile): ?>
                        <a href="announcement_download.php?id=<?= (int)$announcement['id'] ?>" class="inline-flex px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">Download File</a>
                    <?php endif; ?>
                    <?php foreach ($fileAtts as $att): ?>
                        <a href="announcement_download.php?att_id=<?= (int)$att['id'] ?>" class="inline-flex px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">Download File</a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>
</div>

<style>
    .announcement-content a { color: #4f46e5; font-weight: 600; text-decoration: underline; }
    .announcement-content img { max-width: 100%; height: auto; border-radius: 8px; border: 1px solid #e2e8f0; margin: 1rem 0; cursor: zoom-in; }
    .announcement-content ul { list-style: disc; padding-left: 1.5rem; }
    .announcement-content ol { list-style: decimal; padding-left: 1.5rem; }
    .announcement-content blockquote { border-left: 4px solid #c7d2fe; padding-left: 1rem; color: #475569; }
    /* Preserve the paragraph structure the author typed: contenteditable emits a
       <div> (or trailing <br>) per Enter-separated line. Render each as its own
       line/paragraph instead of collapsing them together. */
    .announcement-content p    { margin: 0 0 0.75rem; }
    .announcement-content div  { margin: 0 0 0.35rem; }
    .announcement-content p:last-child, .announcement-content div:last-child { margin-bottom: 0; }
    /* Blank lines (an empty <div><br></div>) keep a visible gap. */
    .announcement-content div:empty { min-height: 1em; }

    /* Uploaded/attachment images are click-to-zoom too. */
    .zoomable-image { cursor: zoom-in; }
    #imageLightbox { transition: opacity .15s ease; }
    #imageLightbox img { cursor: zoom-out; }
</style>

<script>
    (function() {
        var BLOCK_TAGS = ['P', 'H2', 'H3', 'H4', 'LI', 'BLOCKQUOTE', 'DIV', 'BR'];
        function capitalizeSentences(root) {
            var capitalizeNext = true;
            var prevBlock = null;
            var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
            var node;
            while ((node = walker.nextNode())) {
                var el = node.parentElement;
                var block = null;
                while (el && el !== root) {
                    if (BLOCK_TAGS.indexOf(el.tagName) !== -1) { block = el; break; }
                    el = el.parentElement;
                }
                if (block !== prevBlock) { capitalizeNext = true; prevBlock = block; }
                var text = node.nodeValue;
                var result = '';
                for (var i = 0; i < text.length; i++) {
                    var c = text[i];
                    if (capitalizeNext && /\S/.test(c)) { result += c.toUpperCase(); capitalizeNext = false; }
                    else { result += c; }
                    if (c === '.' || c === '!' || c === '?') { capitalizeNext = true; }
                }
                node.nodeValue = result;
            }
        }
        document.addEventListener('DOMContentLoaded', function() {
            var el = document.querySelector('.announcement-content');
            if (el) capitalizeSentences(el);
        });
    })();
</script>

<!-- Image zoom lightbox -->
<div id="imageLightbox" class="fixed inset-0 bg-black/80 z-[60] hidden items-center justify-center p-4 opacity-0">
    <button type="button" id="lightboxClose" class="absolute top-4 right-4 text-white/80 hover:text-white transition" aria-label="Close">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
    <img id="lightboxImage" src="" alt="" class="max-w-[92vw] max-h-[90vh] object-contain rounded-lg shadow-2xl">
</div>

<script>
    (function() {
        var lightbox = document.getElementById('imageLightbox');
        var lightboxImg = document.getElementById('lightboxImage');

        function openLightbox(src, alt) {
            lightboxImg.src = src;
            lightboxImg.alt = alt || '';
            lightbox.classList.remove('hidden');
            lightbox.classList.add('flex');
            // next frame so the opacity transition runs
            requestAnimationFrame(function() { lightbox.classList.remove('opacity-0'); });
        }
        function closeLightbox() {
            lightbox.classList.add('opacity-0');
            setTimeout(function() {
                lightbox.classList.add('hidden');
                lightbox.classList.remove('flex');
                lightboxImg.src = '';
            }, 150);
        }

        // Attachment images + any image inside the rich content are zoomable.
        document.querySelectorAll('.zoomable-image, .announcement-content img').forEach(function(img) {
            img.addEventListener('click', function() { openLightbox(this.src, this.alt); });
        });

        document.getElementById('lightboxClose').addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', function(e) { if (e.target === lightbox) closeLightbox(); });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !lightbox.classList.contains('hidden')) closeLightbox();
        });
    })();
</script>

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
