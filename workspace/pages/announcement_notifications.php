<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$userId = (int)$_SESSION['user_id'];
$user   = announcement_current_user($pdo);
$count  = announcement_unread_count($pdo, $userId, $user);

$accessList   = announcement_accessible_confidentialities($user);
$placeholders = implode(',', array_fill(0, count($accessList), '?'));
$params       = array_merge([$userId], $accessList);

$stmt = $pdo->prepare("
    SELECT a.id, a.title, a.summary, a.confidentiality, a.urgency, a.created_at
    FROM announcements a
    LEFT JOIN announcement_reads ar
        ON ar.announcement_id = a.id AND ar.user_id = ?
    WHERE a.is_active = 1
      AND a.status = 'PUBLISHED'
      AND a.confidentiality IN ($placeholders)
      AND ar.id IS NULL
    ORDER BY (a.urgency = 'URGENT') DESC, a.created_at DESC
    LIMIT 20
");
$stmt->execute($params);
$items = [];

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $items[] = [
        'id'                  => (int)$row['id'],
        'title'               => $row['title'],
        'summary'             => $row['summary'],
        'confidentiality'     => announcement_confidentiality_label($row['confidentiality']),
        'confidentiality_raw' => $row['confidentiality'],
        'urgency'             => $row['urgency'],
        'urgency_label'       => announcement_label($row['urgency']),
        'created_at'          => announcement_format_datetime($row['created_at']),
        'time_ago'            => announcement_time_ago($row['created_at']),
    ];
}

echo json_encode(['count' => $count, 'items' => $items]);
?>
