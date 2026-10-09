<?php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/announcement_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: announcement_list.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$user = announcement_current_user($pdo);

if (!can_create_announcement($user)) {
    http_response_code(403);
    require_once '../includes/header.php';
    echo "<div class='p-8 text-center mt-10'><h2 class='text-2xl font-bold text-red-600'>Akses Ditolak.</h2></div>";
    require_once '../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("UPDATE announcements SET is_active = 0 WHERE id = ? AND created_by = ?");
$stmt->execute([$id, $_SESSION['user_id']]);

header('Location: announcement_list.php');
exit;
?>
