<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

$userId     = (int)$_SESSION['user_id'];
$user       = announcement_current_user($pdo);
$accessList = announcement_accessible_confidentialities($user);
$accessPH   = implode(',', array_fill(0, count($accessList), '?'));

if (isset($_GET['att_id'])) {
    $attId = (int)$_GET['att_id'];
    $stmt  = $pdo->prepare("
        SELECT aa.path, aa.name, aa.mime
        FROM announcement_attachments aa
        JOIN announcements a ON a.id = aa.announcement_id
        WHERE aa.id = ?
          AND a.is_active = 1
          AND (a.status = 'PUBLISHED' OR a.created_by = ?)
          AND (a.confidentiality IN ($accessPH) OR a.created_by = ?)
    ");
    $stmt->execute(array_merge([$attId, $userId], $accessList, [$userId]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); exit('Attachment not found.'); }

    $baseDir  = realpath(__DIR__ . '/uploads/announcements');
    $filePath = realpath(__DIR__ . '/../' . $row['path']);
} else {
    $id   = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("
        SELECT attachment_path AS path, attachment_name AS name, attachment_mime AS mime
        FROM announcements
        WHERE id = ?
          AND is_active = 1
          AND attachment_path IS NOT NULL
          AND (status = 'PUBLISHED' OR created_by = ?)
          AND (confidentiality IN ($accessPH) OR created_by = ?)
    ");
    $stmt->execute(array_merge([$id, $userId], $accessList, [$userId]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) { http_response_code(404); exit('Attachment not found.'); }

    $baseDir  = realpath(__DIR__ . '/uploads/announcements');
    $filePath = realpath(__DIR__ . '/../' . $row['path']);
}

if ($baseDir === false || $filePath === false || strpos($filePath, $baseDir) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit('Attachment not found.');
}

$downloadName = $row['name'] ?: basename($filePath);
$mime = $row['mime'] ?: 'application/octet-stream';

$allowedInlineMimes = ['image/jpeg', 'image/png'];
$serveInline = isset($_GET['inline']) && in_array($mime, $allowedInlineMimes, true);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
if ($serveInline) {
    header('Content-Disposition: inline');
} else {
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
}
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
?>
