<?php
// pages/ajax_sku_bulk_mapping.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';

header('Content-Type: application/json');

$role = $_SESSION['role'] ?? '';
if ($role !== 'Category Manager') {
    echo json_encode(['error' => 'Unauthorized action.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sku_ids = isset($_POST['sku_ids']) ? $_POST['sku_ids'] : [];
    $new_status = isset($_POST['new_status']) ? $_POST['new_status'] : '';

    if (empty($sku_ids) || !is_array($sku_ids)) {
        echo json_encode(['error' => 'No items selected.']);
        exit;
    }

    try {
        // Prepare the IN clause with placeholders
        $inQuery = implode(',', array_fill(0, count($sku_ids), '?'));
        
        $sql = "UPDATE sku_dictionary SET mapping_status = ?, modified_date = NOW(), modified_by = ? WHERE id IN ($inQuery)";
        
        $params = array_merge([$new_status, $_SESSION['username']], $sku_ids);
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        echo json_encode(['status' => 'success', 'updated_count' => $stmt->rowCount()]);
    } catch (PDOException $e) {
        error_log("Bulk Update Mapping Error: " . $e->getMessage());
        echo json_encode(['error' => 'Database error during bulk update.']);
    }
} else {
    echo json_encode(['error' => 'Invalid request method.']);
}
