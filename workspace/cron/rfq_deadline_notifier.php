<?php
// cron/rfq_deadline_notifier.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/rfq_notification_helper.php';

echo "Running RFQ Deadline Notifier Cron Job...\n";

// 1. D-1 Reminder
$stmt = $pdo->query("SELECT id FROM rfqs WHERE status = 'Active' AND DATE(deadline) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
$d1_rfqs = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($d1_rfqs as $rfq_id) {
    $check = $pdo->prepare("SELECT id FROM rfq_notifications_log WHERE rfq_id = ? AND notification_type = 'D-1'");
    $check->execute([$rfq_id]);
    if ($check->rowCount() == 0) {
        notify_deadline_reminder($pdo, $rfq_id, 'D-1 (H-1)');
        $pdo->prepare("INSERT INTO rfq_notifications_log (rfq_id, notification_type) VALUES (?, 'D-1')")->execute([$rfq_id]);
        echo "Sent D-1 notification for RFQ ID: $rfq_id\n";
    }
}

// 2. D-Day Reminder
$stmt = $pdo->query("SELECT id FROM rfqs WHERE status = 'Active' AND DATE(deadline) = CURDATE()");
$dday_rfqs = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($dday_rfqs as $rfq_id) {
    $check = $pdo->prepare("SELECT id FROM rfq_notifications_log WHERE rfq_id = ? AND notification_type = 'D-Day'");
    $check->execute([$rfq_id]);
    if ($check->rowCount() == 0) {
        notify_deadline_reminder($pdo, $rfq_id, 'D-Day (Hari H)');
        $pdo->prepare("INSERT INTO rfq_notifications_log (rfq_id, notification_type) VALUES (?, 'D-Day')")->execute([$rfq_id]);
        echo "Sent D-Day notification for RFQ ID: $rfq_id\n";
    }
}

// 3. Post-Deadline Reminder (Overdue and not completely unsealed)
// Note: RFQ status might still be Active if deadline is passed but not yet Unsealed by committee.
// We'll send this once per day if the RFQ is overdue and still 'Active'.
$stmt = $pdo->query("SELECT id FROM rfqs WHERE status = 'Active' AND deadline < NOW()");
$overdue_rfqs = $stmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($overdue_rfqs as $rfq_id) {
    // Check if already sent today
    $check = $pdo->prepare("SELECT id FROM rfq_notifications_log WHERE rfq_id = ? AND notification_type = 'Post-Deadline' AND DATE(sent_at) = CURDATE()");
    $check->execute([$rfq_id]);
    if ($check->rowCount() == 0) {
        notify_post_deadline_reminder($pdo, $rfq_id);
        $pdo->prepare("INSERT INTO rfq_notifications_log (rfq_id, notification_type) VALUES (?, 'Post-Deadline')")->execute([$rfq_id]);
        echo "Sent Post-Deadline notification for RFQ ID: $rfq_id\n";
    }
}

echo "Cron job completed successfully.\n";
?>
