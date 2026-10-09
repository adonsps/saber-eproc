<?php
// modules/admin/holidays_action.php
session_start();
require '../../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access Denied");
}

// ── CSRF guard ───────────────────────────────────────────────────────────────
// Generate token on first page load (done in header or here on first visit)
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$submitted_token = $_POST['csrf_token'] ?? '';
if (!hash_equals($_SESSION['csrf_token'], $submitted_token)) {
    die("Invalid CSRF token.");
}

/**
 * Recalculate sla_deadline for every open PQ SLA leg.
 * Called after any holiday add/remove so SLA deadlines stay accurate.
 */
function recalculateOpenSlaLegs($conn): void {
    require_once dirname(__DIR__, 2) . '/includes/sla_helper.php';

    $open = $conn->query(
        "SELECT id, submission_id, sourcing_submitted_at
         FROM pq_sla_logs
         WHERE vm_action_at IS NULL"
    );
    if (!$open || $open->num_rows === 0) return;

    $update_leg = $conn->prepare("UPDATE pq_sla_logs SET sla_deadline = ? WHERE id = ?");
    $update_sub = $conn->prepare("UPDATE pq_submissions SET pq_sla_deadline = ? WHERE id = ?");

    while ($leg = $open->fetch_assoc()) {
        $new_deadline = addWorkingHours($conn, $leg['sourcing_submitted_at'], PQ_SLA_TARGET_HOURS);

        $update_leg->bind_param("si", $new_deadline, $leg['id']);
        $update_leg->execute();

        $update_sub->bind_param("si", $new_deadline, $leg['submission_id']);
        $update_sub->execute();
    }
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $date = $_POST['holiday_date'] ?? '';
    $name = trim($_POST['holiday_name'] ?? '');
    $type = ($_POST['type'] ?? '') === 'joint_leave' ? 'joint_leave' : 'national';
    $uid  = (int)$_SESSION['user_id'];

    // Validate date format (YYYY-MM-DD)
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        header("Location: holidays.php?error=invalid_date");
        exit();
    }

    if ($name === '') {
        header("Location: holidays.php?error=empty_name");
        exit();
    }

    $stmt = $conn->prepare("
        INSERT INTO holidays (holiday_date, holiday_name, type, created_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name), type = VALUES(type)
    ");
    $stmt->bind_param("sssi", $date, $name, $type, $uid);
    $stmt->execute();

    // Recalculate open SLA legs — deadline may shift forward
    recalculateOpenSlaLegs($conn);

} elseif ($action === 'delete') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        // FIX: original used direct query with interpolation — use prepared statement
        $del = $conn->prepare("DELETE FROM holidays WHERE id = ?");
        $del->bind_param("i", $id);
        $del->execute();

        // Recalculate open SLA legs — deadline may shift back
        recalculateOpenSlaLegs($conn);
    }
}

header("Location: holidays.php");
exit();
?>