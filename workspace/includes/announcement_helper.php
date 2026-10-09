<?php
// includes/announcement_helper.php

function announcement_current_user($pdo) {
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $stmt = $pdo->prepare("SELECT id, username, name, role, create_announcement FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function announcement_confidentiality_class($value) {
    switch (strtoupper((string)$value)) {
        case 'GP_ONLY':      return 'bg-red-100 text-red-700';
        case 'INTERNAL_KLG': return 'bg-amber-100 text-amber-700';
        case 'EXTERNAL':     return 'bg-green-100 text-green-700';
        default:             return 'bg-slate-100 text-slate-700';
    }
}

/**
 * Human-readable name of a confidentiality level. Kept separate from
 * announcement_label() (which handles status) so the audience wording can stay
 * explicit — the old PUBLIC/RESTRICTED/CONFIDENTIAL options were ambiguous
 * enough that publishers regularly picked the wrong one.
 */
function announcement_confidentiality_label($value) {
    $map = [
        'EXTERNAL'     => 'External',
        'INTERNAL_KLG' => 'Internal KLG',
        'GP_ONLY'      => 'GP Only',
    ];
    $key = strtoupper((string)$value);
    return $map[$key] ?? announcement_label($value);
}

function announcement_accessible_confidentialities($user) {
    if (empty($user)) return ['EXTERNAL'];

    $role      = $user['role'] ?? '';
    $canCreate = isset($user['create_announcement']) && (int)$user['create_announcement'] === 1;

    $canSeeInternalKlg = $canCreate || in_array($role, ['Category Manager', 'Manager', 'PIC', 'QS', 'Project Director', 'Admin'], true);
    $canSeeGpOnly      = $canCreate || in_array($role, ['Category Manager', 'Manager', 'PIC', 'Admin'], true);

    $list = ['EXTERNAL'];
    if ($canSeeInternalKlg) $list[] = 'INTERNAL_KLG';
    if ($canSeeGpOnly)      $list[] = 'GP_ONLY';
    return $list;
}

function can_create_announcement($user) {
    return isset($user['create_announcement']) && (int)$user['create_announcement'] === 1;
}

function announcement_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function announcement_label($value) {
    $map = ['PUBLISHED' => 'PUBLISH'];
    return $map[$value] ?? str_replace('_', ' ', (string)$value);
}

function announcement_format_datetime($dateString) {
    if (empty($dateString)) {
        return '-';
    }

    try {
        $dt = new DateTime($dateString, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('d M Y H:i') . ' WIB';
    } catch (Exception $e) {
        return '-';
    }
}

function announcement_time_ago($dateString) {
    if (empty($dateString)) {
        return '-';
    }

    try {
        $then = new DateTime($dateString, new DateTimeZone('Asia/Jakarta'));
        $now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
        $diff = max(0, $now->getTimestamp() - $then->getTimestamp());
    } catch (Exception $e) {
        return '-';
    }

    if ($diff < 60) return 'just now';
    if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
    if ($diff < 604800) return floor($diff / 86400) . ' days ago';

    return announcement_format_datetime($dateString);
}

function announcement_sanitize_url($url) {
    $url = trim((string)$url);
    if ($url === '') {
        return null;
    }

    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }

    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

function announcement_sanitize_content($html) {
    $html = trim((string)$html);
    if ($html === '') {
        return '';
    }

    // 'div' is kept because contenteditable (the Create/Edit editor) wraps each
    // Enter-separated line in a <div>. Dropping it here silently merged every
    // line into one run of text, destroying the paragraph structure the author
    // typed. Attributes on div are stripped below just like every other tag.
    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'ol', 'ul', 'li', 'blockquote', 'a', 'img', 'h2', 'h3', 'h4', 'div'];
    $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    foreach ($xpath->query('//*') as $node) {
        if (!in_array(strtolower($node->nodeName), array_merge($allowedTags, ['div']), true)) {
            $node->parentNode->removeChild($node);
            continue;
        }

        if (!$node->hasAttributes()) {
            continue;
        }

        $removeAttrs = [];
        foreach ($node->attributes as $attr) {
            $name = strtolower($attr->name);
            $value = trim($attr->value);
            $keep = false;

            if ($node->nodeName === 'a' && in_array($name, ['href', 'target', 'rel'], true)) {
                $keep = $name !== 'href' || announcement_sanitize_url($value) !== null;
            } elseif ($node->nodeName === 'img' && in_array($name, ['src', 'alt', 'title'], true)) {
                $keep = $name !== 'src' || announcement_sanitize_url($value) !== null;
            }

            if (!$keep) {
                $removeAttrs[] = $attr->name;
            }
        }

        foreach ($removeAttrs as $attrName) {
            $node->removeAttribute($attrName);
        }

        if ($node->nodeName === 'a') {
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    $container = $dom->getElementsByTagName('div')->item(0);
    $clean = '';
    if ($container) {
        foreach ($container->childNodes as $child) {
            $clean .= $dom->saveHTML($child);
        }
    }

    return $clean;
}

function announcement_validate_attachment($file) {
    if (empty($file) || empty($file['name']) || (int)$file['error'] === UPLOAD_ERR_NO_FILE) {
        return [true, null];
    }

    if ((int)$file['error'] !== UPLOAD_ERR_OK) {
        return [false, 'Attachment gagal diupload.'];
    }

    if ((int)$file['size'] > 5 * 1024 * 1024) {
        return [false, 'Ukuran attachment maksimal 5 MB.'];
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExtensions = ['pdf', 'docx', 'xlsx', 'pptx', 'jpg', 'jpeg', 'png'];
    if (!in_array($extension, $allowedExtensions, true)) {
        return [false, 'Tipe file attachment tidak diizinkan.'];
    }

    $blockedExtensions = ['php', 'py', 'exe', 'js', 'bat', 'asp', 'jsp'];
    if (in_array($extension, $blockedExtensions, true)) {
        return [false, 'Tipe file attachment diblokir.'];
    }

    $allowedMimes = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, $allowedMimes[$extension], true)) {
        return [false, 'MIME type attachment tidak valid.'];
    }

    return [true, ['extension' => $extension, 'mime' => $mime]];
}

function announcement_store_attachment($file, $meta) {
    if (empty($file) || empty($meta)) {
        return [null, null, null];
    }

    $uploadDir = realpath(__DIR__ . '/uploads');
    if ($uploadDir === false) {
        mkdir(__DIR__ . '/../uploads', 0755, true);
        $uploadDir = realpath(__DIR__ . '/uploads');
    }

    $announcementDir = $uploadDir . DIRECTORY_SEPARATOR . 'announcements';
    if (!is_dir($announcementDir)) {
        mkdir($announcementDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $meta['extension'];
    $target = $announcementDir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Gagal menyimpan attachment.');
    }

    return ['uploads/announcements/' . $filename, $file['name'], $meta['mime']];
}

function announcement_unread_count($pdo, $userId, $user = null) {
    $accessList   = announcement_accessible_confidentialities($user);
    $placeholders = implode(',', array_fill(0, count($accessList), '?'));
    $params       = array_merge([$userId], $accessList);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM announcements a
        LEFT JOIN announcement_reads ar
            ON ar.announcement_id = a.id AND ar.user_id = ?
        WHERE a.is_active = 1
          AND a.status = 'PUBLISHED'
          AND a.confidentiality IN ($placeholders)
          AND ar.id IS NULL
    ");
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Build the WhatsApp group teaser for a published announcement. GP-only
 * content is never included — only title + summary + a link back to the detail
 * page (which stays gated behind login + role). Urgent announcements get a
 * marker line on top.
 */
function announcement_build_wa_message(array $announcement, string $link) {
    $title   = trim((string)($announcement['title'] ?? ''));
    $summary = trim((string)($announcement['summary'] ?? ''));

    // Urgent marker and the header share one line, joined by "||".
    $header = '';
    if (strtoupper((string)($announcement['urgency'] ?? '')) === 'URGENT') {
        $header .= "🔴 *URGENT* || ";
    }
    $header .= "📢 *NEW ANNOUNCEMENT — GP WORKSPACE*";

    $message  = $header . "\n\n";
    $message .= "*{$title}*\n";
    $message .= "{$summary}\n\n";
    $message .= "*Selengkapnya, silahkan klik link berikut:*\n";
    // Bare URL on its own line so WhatsApp auto-detects and hyperlinks it.
    $message .= $link;

    return $message;
}

/**
 * Fire the WhatsApp group notification for an announcement, exactly once.
 *
 * Sends ONLY when: status = PUBLISHED, confidentiality = GP_ONLY, and it
 * has not been notified before (wa_notified_at IS NULL). Safe to call from every
 * publish path (create-as-published AND draft->publish): the wa_notified_at
 * stamp guarantees a re-save/re-publish/edit never resends. Never throws — a
 * Fonnte failure must not block publishing.
 */
function announcement_send_wa_on_publish($pdo, $announcementId) {
    try {
        $stmt = $pdo->prepare(
            "SELECT id, title, summary, confidentiality, urgency, status, wa_notified_at
             FROM announcements WHERE id = ?"
        );
        $stmt->execute([$announcementId]);
        $a = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$a) return;
        if ($a['status'] !== 'PUBLISHED') return;
        if (strtoupper((string)$a['confidentiality']) !== 'GP_ONLY') return;
        if (!empty($a['wa_notified_at'])) return; // already sent once

        $configPath = __DIR__ . '/../config/announcement_wa_groups.php';
        if (!is_file($configPath)) return;
        $config = require $configPath;
        $groups = $config['groups'] ?? [];
        if (empty($groups)) return;

        // Build the detail-page link from the current request, mirroring how the
        // RFQ notifications derive their links (rfq_create.php) — auto-adapts to
        // whatever host the announcement is published from (prod or local).
        $protocol = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'] ?? '';
        $dir      = isset($_SERVER['REQUEST_URI']) ? rtrim(str_replace('\\', '/', dirname($_SERVER['REQUEST_URI'])), '/') : '';
        $link     = $protocol . '://' . $host . $dir . '/announcement_detail.php?id=' . (int)$a['id'];

        $message = announcement_build_wa_message($a, $link);

        require_once __DIR__ . '/fonnte.php';
        foreach ($groups as $groupId) {
            if (trim((string)$groupId) === '') continue;
            sendFonnteGroup($groupId, $message);
        }

        // Stamp AFTER dispatch so a hard failure before this leaves it eligible
        // to retry on the next publish/edit rather than silently swallowing it.
        $mark = $pdo->prepare("UPDATE announcements SET wa_notified_at = NOW() WHERE id = ?");
        $mark->execute([$announcementId]);
    } catch (\Throwable $e) {
        error_log('announcement_send_wa_on_publish failed for id ' . $announcementId . ': ' . $e->getMessage());
    }
}
?>
