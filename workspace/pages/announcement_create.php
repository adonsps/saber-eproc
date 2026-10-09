<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

$user = announcement_current_user($pdo);
$canCreate = can_create_announcement($user);
$errors = [];

$allowedConfidentiality = ['EXTERNAL', 'INTERNAL_KLG', 'GP_ONLY'];
$allowedUrgency = ['URGENT', 'NOT_URGENT'];
$allowedStatus = ['DRAFT', 'PUBLISHED'];

$values = [
    'title' => '',
    'summary' => '',
    'content' => '',
    'confidentiality' => '',
    'urgency' => 'NOT_URGENT',
    'optional_link' => '',
    'status' => 'DRAFT',
];

if (!$canCreate) {
    require_once '../includes/header.php';
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak.</h2><p class='text-sm text-slate-500 mt-2'>Permission create_announcement diperlukan untuk membuat announcement.</p></div>";
    require_once '../includes/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $default) {
        $values[$key] = trim($_POST[$key] ?? $default);
    }

    $values['title']   = strtoupper($values['title']);
    $values['summary'] = ucfirst(mb_strtolower($values['summary'], 'UTF-8'));

    $isPublish = $values['status'] === 'PUBLISHED';

    if ($isPublish) {
        if ($values['title'] === '') $errors[] = 'Title wajib diisi.';
        if ($values['summary'] === '') $errors[] = 'Summary wajib diisi.';
    }
    if ($values['confidentiality'] === '') {
        if ($isPublish) $errors[] = 'Confidentiality wajib dipilih.';
    } elseif (!in_array($values['confidentiality'], $allowedConfidentiality, true)) {
        $errors[] = 'Confidentiality tidak valid.';
    }
    if (!in_array($values['urgency'], $allowedUrgency, true)) $errors[] = 'Urgency tidak valid.';
    if (!in_array($values['status'], $allowedStatus, true)) $errors[] = 'Status tidak valid.';

    $cleanContent = announcement_sanitize_content($_POST['content'] ?? '');
    $values['content'] = $cleanContent;
    if ($isPublish && trim(strip_tags($cleanContent)) === '' && strpos($cleanContent, '<img') === false) {
        $errors[] = 'Content wajib diisi.';
    }

    $cleanLink = null;
    if ($values['optional_link'] !== '') {
        $cleanLink = announcement_sanitize_url($values['optional_link']);
        if ($cleanLink === null) {
            $errors[] = 'Optional Link harus menggunakan URL http/https yang valid.';
        }
    }

    // A draft may be saved without a confidentiality choice. `confidentiality`
    // is a NOT NULL ENUM, so fall back to its column default instead of writing
    // '' — which the local DB's STRICT_TRANS_TABLES sql_mode rejects (prod's
    // MariaDB silently accepted it). Publishing still requires an explicit
    // choice (validated above), and drafts are only visible to their creator.
    if ($values['confidentiality'] === '') {
        $values['confidentiality'] = 'EXTERNAL';
    }

    $validatedFiles = [];
    if (!empty($_FILES['attachments']['name'][0])) {
        $count = count($_FILES['attachments']['name']);
        for ($i = 0; $i < $count; $i++) {
            if ((int)$_FILES['attachments']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
            $singleFile = [
                'name'     => $_FILES['attachments']['name'][$i],
                'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                'error'    => (int)$_FILES['attachments']['error'][$i],
                'size'     => (int)$_FILES['attachments']['size'][$i],
            ];
            [$valid, $meta] = announcement_validate_attachment($singleFile);
            if (!$valid) {
                $errors[] = $meta . ' (' . htmlspecialchars($singleFile['name'], ENT_QUOTES, 'UTF-8') . ')';
            } else {
                $validatedFiles[] = [$singleFile, $meta];
            }
        }
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO announcements
                    (title, summary, content, confidentiality, urgency, optional_link, created_by, status, is_active)
                VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $values['title'],
                $values['summary'],
                $cleanContent,
                $values['confidentiality'],
                $values['urgency'],
                $cleanLink,
                $_SESSION['user_id'],
                $values['status'],
            ]);
            $announcementId = (int)$pdo->lastInsertId();

            if (!empty($validatedFiles)) {
                $stmtAtt = $pdo->prepare("INSERT INTO announcement_attachments (announcement_id, path, name, mime) VALUES (?, ?, ?, ?)");
                foreach ($validatedFiles as [$file, $meta]) {
                    [$path, $name, $mime] = announcement_store_attachment($file, $meta);
                    $stmtAtt->execute([$announcementId, $path, $name, $mime]);
                }
            }

            // Fire the WhatsApp group teaser if this was published as GP Only.
            // Guarded + once-only inside the helper; never blocks the redirect.
            if ($values['status'] === 'PUBLISHED') {
                announcement_send_wa_on_publish($pdo, $announcementId);
            }

            header('Location: announcement_detail.php?id=' . $announcementId);
            exit;
        } catch (Exception $e) {
            $errors[] = 'Gagal menyimpan announcement.';
        }
    }
}

require_once '../includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="border-b border-slate-200 pb-5 mb-6">
        <h2 class="text-2xl font-bold text-slate-800">Create Announcement</h2>
        <p class="text-sm text-slate-500 mt-1">Post a global announcement to GP Workspace.</p>
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
                    <button type="button" data-command="italic" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm"><em>I</em></button>
                    <button type="button" data-command="underline" class="px-3 py-1 rounded border border-slate-200 bg-white text-sm underline">U</button>
                </div>
                <div id="contentEditor" contenteditable="true" class="min-h-[260px] p-4 text-sm leading-relaxed focus:outline-none"><?= $values['content'] ?></div>
            </div>
            <input type="hidden" name="content" id="contentInput">
            <p class="text-xs text-slate-400 mt-1">Images can be inserted by URL. Video uploads are not supported.</p>
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
            <input type="url" name="optional_link" value="<?= announcement_escape($values['optional_link']) ?>" class="w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500" placeholder="https://example.com">
        </div>

        <div>
            <label class="block text-sm font-bold text-slate-700 mb-1">Attachments</label>
            <div id="fileList" class="mb-2"></div>
            <button type="button" id="addFileBtn" class="px-4 py-2 rounded-lg border border-slate-200 bg-slate-50 text-sm font-bold text-slate-700 hover:bg-white">+ Add File</button>
            <input type="file" id="fileInput" name="attachments[]" multiple accept=".pdf,.docx,.xlsx,.pptx,.jpg,.jpeg,.png" class="hidden">
            <p class="text-xs text-slate-400 mt-2">Allowed: PDF, DOCX, XLSX, PPTX, JPG, JPEG, PNG. Max 5 MB each.</p>
        </div>

        <input type="hidden" name="status" id="statusInput" value="DRAFT">
        <div id="publishError" class="hidden bg-red-50 border border-red-200 text-red-700 rounded-lg p-3 text-sm">
            Title, Summary, Content, and Confidentiality are required before publishing. You can save as Draft instead.
        </div>
        <div class="flex flex-wrap justify-end gap-3 border-t border-slate-200 pt-5">
            <a href="announcement_list.php" class="px-4 py-2 rounded-lg border border-slate-200 text-sm font-bold text-slate-600 hover:bg-slate-50">Cancel</a>
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

    // Rich text editor
    $('[data-command]').on('click', function() {
        document.execCommand($(this).data('command'), false, null);
        $('#contentEditor').focus();
    });

    // Multi-file attachment with X buttons
    (function() {
        const fileInput = document.getElementById('fileInput');
        const fileList  = document.getElementById('fileList');
        let dt = new DataTransfer();

        document.getElementById('addFileBtn').addEventListener('click', function() {
            fileInput.value = '';
            fileInput.click();
        });

        fileInput.addEventListener('change', function() {
            for (const file of this.files) {
                const dupe = Array.from(dt.files).find(f => f.name === file.name && f.size === file.size);
                if (!dupe) dt.items.add(file);
            }
            render();
        });

        function render() {
            // Revoke old object URLs to avoid memory leaks
            fileList.querySelectorAll('img[data-objurl]').forEach(function(img) {
                URL.revokeObjectURL(img.src);
            });
            fileList.innerHTML = '';
            Array.from(dt.files).forEach(function(file, index) {
                const isImage = file.type.startsWith('image/');
                const row = document.createElement('div');
                row.className = 'flex items-center gap-3 px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm mb-1';

                let previewEl = '';
                if (isImage) {
                    const objUrl = URL.createObjectURL(file);
                    previewEl = '<img src="' + objUrl + '" data-objurl="1" alt="" class="flex-shrink-0 w-14 h-14 object-cover rounded border border-slate-200">';
                } else {
                    const ext = escHtml(file.name.split('.').pop().toUpperCase());
                    previewEl = '<div class="flex-shrink-0 w-14 h-14 flex items-center justify-center bg-slate-200 rounded border border-slate-200 text-slate-500 text-xs font-bold">' + ext + '</div>';
                }

                row.innerHTML = previewEl +
                    '<span class="text-slate-700 truncate flex-1">' + escHtml(file.name) + '</span>' +
                    '<button type="button" data-idx="' + index + '" class="remove-file flex-shrink-0 w-5 h-5 flex items-center justify-center rounded text-red-500 hover:text-red-700 hover:bg-red-50 font-bold text-xs leading-none">&#x2715;</button>';
                fileList.appendChild(row);
            });
            fileList.querySelectorAll('.remove-file').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const idx = parseInt(this.dataset.idx);
                    const newDt = new DataTransfer();
                    Array.from(dt.files).forEach(function(f, i) { if (i !== idx) newDt.items.add(f); });
                    dt = newDt;
                    render();
                });
            });
        }

        function escHtml(str) {
            return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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

        document.getElementById('announcementForm').addEventListener('submit', function() {
            var editor = document.getElementById('contentEditor');
            capitalizeSentences(editor);
            document.getElementById('contentInput').value = editor.innerHTML;
            fileInput.files = dt.files;
        });
    })();
</script>

<?php require_once '../includes/footer.php'; ?>
