<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$user = announcement_current_user($pdo);
$canCreate = can_create_announcement($user);
$userId = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$announcement = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$announcement || !$canCreate || (int)$announcement['created_by'] !== $userId) {
    require_once '../includes/header.php';
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak.</h2><p class='text-sm text-slate-500 mt-2'>Anda hanya dapat mengedit announcement milik sendiri dengan permission create_announcement.</p></div>";
    require_once '../includes/footer.php';
    exit;
}

$errors = [];
$allowedConfidentiality = ['EXTERNAL', 'INTERNAL_KLG', 'GP_ONLY'];
$allowedUrgency = ['URGENT', 'NOT_URGENT'];
$allowedStatus = ['DRAFT', 'PUBLISHED'];
$values = $announcement;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $values['title']          = strtoupper(trim($_POST['title'] ?? ''));
    $values['summary']        = ucfirst(mb_strtolower(trim($_POST['summary'] ?? ''), 'UTF-8'));
    $values['content']        = announcement_sanitize_content($_POST['content'] ?? '');
    $values['confidentiality'] = $_POST['confidentiality'] ?? '';
    $values['urgency']        = isset($_POST['urgency']) ? 'URGENT' : 'NOT_URGENT';
    $values['optional_link']  = trim($_POST['optional_link'] ?? '');
    $values['status']         = $_POST['status'] ?? '';

    $isPublish = $values['status'] === 'PUBLISHED';

    if ($isPublish) {
        if ($values['title'] === '') $errors[] = 'Title wajib diisi.';
        if ($values['summary'] === '') $errors[] = 'Summary wajib diisi.';
        if (trim(strip_tags($values['content'])) === '' && strpos($values['content'], '<img') === false) $errors[] = 'Content wajib diisi.';
    }
    if ($values['confidentiality'] === '') {
        if ($isPublish) $errors[] = 'Confidentiality wajib dipilih.';
    } elseif (!in_array($values['confidentiality'], $allowedConfidentiality, true)) {
        $errors[] = 'Confidentiality tidak valid.';
    }
    if (!in_array($values['urgency'], $allowedUrgency, true)) $errors[] = 'Urgency tidak valid.';
    if (!in_array($values['status'], $allowedStatus, true)) $errors[] = 'Status tidak valid.';

    $cleanLink = null;
    if ($values['optional_link'] !== '') {
        $cleanLink = announcement_sanitize_url($values['optional_link']);
        if ($cleanLink === null) {
            $errors[] = 'Optional Link harus menggunakan URL http/https yang valid.';
        }
    }

    // A draft may be saved without a confidentiality choice. `confidentiality`
    // is a NOT NULL ENUM, so fall back to its column default instead of writing
    // '' — which the local DB's STRICT_TRANS_TABLES sql_mode rejects. Publishing
    // still requires an explicit choice (validated above).
    if ($values['confidentiality'] === '') {
        $values['confidentiality'] = 'EXTERNAL';
    }

    [$validAttachment, $attachmentMeta] = announcement_validate_attachment($_FILES['attachment'] ?? null);
    if (!$validAttachment) {
        $errors[] = $attachmentMeta;
    }

    if (empty($errors)) {
        try {
            $attachmentPath = $announcement['attachment_path'];
            $attachmentName = $announcement['attachment_name'];
            $attachmentMime = $announcement['attachment_mime'];

            if (!empty($_POST['remove_attachment'])) {
                $attachmentPath = null;
                $attachmentName = null;
                $attachmentMime = null;
            }

            if (!empty($attachmentMeta)) {
                [$attachmentPath, $attachmentName, $attachmentMime] = announcement_store_attachment($_FILES['attachment'], $attachmentMeta);
            }

            $stmt = $pdo->prepare("
                UPDATE announcements
                SET title = ?,
                    summary = ?,
                    content = ?,
                    confidentiality = ?,
                    urgency = ?,
                    optional_link = ?,
                    attachment_path = ?,
                    attachment_name = ?,
                    attachment_mime = ?,
                    status = ?
                WHERE id = ? AND created_by = ?
            ");
            $stmt->execute([
                $values['title'],
                $values['summary'],
                $values['content'],
                $values['confidentiality'],
                $values['urgency'],
                $cleanLink,
                $attachmentPath,
                $attachmentName,
                $attachmentMime,
                $values['status'],
                $id,
                $userId,
            ]);

            // Fire the WhatsApp group teaser if this edit published a GP Only
            // announcement. Once-only inside the helper (wa_notified_at), so a
            // draft->publish sends once and later edits/re-saves never resend.
            if ($values['status'] === 'PUBLISHED') {
                announcement_send_wa_on_publish($pdo, $id);
            }

            header('Location: announcement_detail.php?id=' . $id);
            exit;
        } catch (Exception $e) {
            $errors[] = 'Gagal menyimpan perubahan announcement.';
        }
    }
}

require_once '../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="border-b border-slate-200 pb-5 mb-6">
        <h2 class="text-2xl font-bold text-slate-800">Edit Announcement</h2>
        <p class="text-sm text-slate-500 mt-1">Update your announcement content and publication status.</p>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4 mb-5 text-sm">
            <?php foreach ($errors as $error): ?>
                <div><?= announcement_escape($error) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="text-xs text-slate-400 mb-5"><span class="text-red-500 font-bold">*</span> Required when publishing</p>

    <form method="POST" enctype="multipart/form-data" id="announcementForm" class="space-y-5">
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Title <span class="text-red-500">*</span></label>
            <input type="text" name="title" value="<?= announcement_escape($values['title']) ?>" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 uppercase">
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Summary <span class="text-red-500">*</span></label>
            <textarea name="summary" rows="3" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"><?= announcement_escape($values['summary']) ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Content <span class="text-red-500">*</span></label>
            <div class="border border-slate-200 rounded-lg overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-200 p-2 flex flex-wrap gap-2">
                    <button type="button" data-command="bold" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm font-bold">B</button>
                    <button type="button" data-command="italic" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm italic">I</button>
                    <button type="button" data-command="insertUnorderedList" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm">List</button>
                    <button type="button" id="insertLinkBtn" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm">Link</button>
                    <button type="button" id="insertImageBtn" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm">Image URL</button>
                </div>
                <div id="contentEditor" contenteditable="true" class="min-h-[260px] p-4 text-sm leading-relaxed focus:outline-none"><?= $values['content'] ?></div>
            </div>
            <input type="hidden" name="content" id="contentInput">
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-1">Confidentiality <span class="text-red-500">*</span></label>
                <select name="confidentiality" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="">-- Select --</option>
                    <?php foreach ($allowedConfidentiality as $option): ?>
                        <option value="<?= $option ?>" <?= $values['confidentiality'] === $option ? 'selected' : '' ?>><?= announcement_escape(announcement_confidentiality_label($option)) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-slate-400 mt-1">External = shared with vendors via VSS. Internal KLG = all KLG employees. GP Only = Group Procurement only.</p>
            </div>
            <div>
                <label class="block text-sm font-bold text-slate-700 mb-2">Urgency</label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="urgency" value="URGENT" <?= $values['urgency'] === 'URGENT' ? 'checked' : '' ?> class="w-4 h-4 rounded border-slate-300 accent-red-600">
                    <span class="text-sm font-semibold text-red-700">Urgent</span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Optional Link</label>
            <input type="url" name="optional_link" value="<?= announcement_escape($values['optional_link'] ?? '') ?>" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="https://example.com">
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Attachment Upload</label>
            <?php if (!empty($announcement['attachment_name'])): ?>
                <div class="mb-2 text-sm text-slate-600">
                    Current: <span class="font-semibold"><?= announcement_escape($announcement['attachment_name']) ?></span>
                    <label class="ml-3 inline-flex items-center gap-1 text-xs font-semibold text-red-600">
                        <input type="checkbox" name="remove_attachment" value="1"> Remove
                    </label>
                </div>
            <?php endif; ?>
            <input type="file" name="attachment" accept=".pdf,.docx,.xlsx,.pptx,.jpg,.jpeg,.png" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white">
            <p class="text-xs text-slate-400 mt-1">Uploading a new file replaces the current attachment reference. Max 5 MB.</p>
        </div>

        <input type="hidden" name="status" id="statusInput" value="<?= announcement_escape($values['status']) ?>">
        <div id="publishError" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm">
            Title, Summary, Content, and Confidentiality are required before publishing. You can save as Draft instead.
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-5">
            <a href="announcement_detail.php?id=<?= (int)$id ?>" class="px-4 py-2 rounded-lg border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</a>
            <button type="submit" id="draftBtn" class="px-5 py-2 rounded-lg border border-slate-300 bg-slate-100 text-slate-700 text-sm font-bold hover:bg-slate-200">Save as Draft</button>
            <button type="button" id="publishBtn" class="px-5 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700">Publish</button>
        </div>
    </form>
</div>

<script>
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

    document.getElementById('draftBtn').addEventListener('click', function() {
        document.getElementById('statusInput').value = 'DRAFT';
        document.getElementById('publishError').classList.add('hidden');
    });

    document.getElementById('publishBtn').addEventListener('click', function() {
        var title           = document.querySelector('input[name="title"]').value.trim();
        var summary         = document.querySelector('textarea[name="summary"]').value.trim();
        var content         = document.getElementById('contentEditor').innerText.trim();
        var confidentiality = document.querySelector('select[name="confidentiality"]').value;
        var err             = document.getElementById('publishError');
        if (!title || !summary || !content || !confidentiality) {
            err.classList.remove('hidden');
            err.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }
        err.classList.add('hidden');
        document.getElementById('statusInput').value = 'PUBLISHED';
        document.getElementById('announcementForm').requestSubmit();
    });

    $('#announcementForm').on('submit', function() {
        var editor = document.getElementById('contentEditor');
        capitalizeSentences(editor);
        $('#contentInput').val(editor.innerHTML);
    });

    $('[data-command]').on('click', function() {
        document.execCommand($(this).data('command'), false, null);
        $('#contentEditor').focus();
    });

    $('#insertLinkBtn').on('click', function() {
        const url = prompt('Enter http/https URL');
        if (url) document.execCommand('createLink', false, url);
        $('#contentEditor').focus();
    });

    $('#insertImageBtn').on('click', function() {
        const url = prompt('Enter image URL');
        if (url) document.execCommand('insertImage', false, url);
        $('#contentEditor').focus();
    });
</script>

<?php require_once '../includes/footer.php'; ?>
