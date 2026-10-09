<?php
/**
 * Returns unread in-app notification count for the logged-in user.
 * Call this in header.php to power the bell badge.
 */
function getUnreadNotifCount($conn, $user_id): int {
    // Pages that render header.php without a DB connection in scope (root
    // login.php is one: it never requires config.php) used to pass null here and
    // fatal on ->query(). The bell is decoration on those pages, so degrade to
    // zero instead of taking the whole page down with a 500.
    if (!$conn instanceof mysqli) return 0;

    $uid = (int)$user_id;
    $r = $conn->query("
        SELECT COUNT(*) as cnt FROM notifications
        WHERE recipient_id = $uid AND channel = 'inapp' AND is_read = 0
    ");
    if (!$r) return 0;
    return (int)($r->fetch_assoc()['cnt'] ?? 0);
}

/**
 * Returns last N unread in-app notifications for dropdown rendering.
 */
function getUnreadNotifs($conn, $user_id, $limit = 10): array {
    // Same guard as getUnreadNotifCount() above.
    if (!$conn instanceof mysqli) return [];

    $uid = (int)$user_id;
    $lim = (int)$limit;
    $r = $conn->query("
        SELECT n.*, v.company_name
        FROM notifications n
        LEFT JOIN vendors v ON n.vendor_id = v.id
        WHERE n.recipient_id = $uid AND n.channel = 'inapp' AND n.is_read = 0
        ORDER BY n.created_at DESC LIMIT $lim
    ");
    if (!$r) return [];
    $rows = [];
    while ($row = $r->fetch_assoc()) $rows[] = $row;
    return $rows;
}