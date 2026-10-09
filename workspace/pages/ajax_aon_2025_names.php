<?php
// pages/ajax_aon_2025_names.php
// Returns every distinct 2025 item name (each with its average unit price)
// recorded in purchases_2025. The Master AON edit modal uses this to offer a
// searchable, strict dropdown — STC can pick any real 2025 name by keyword but
// can't invent names outside the table — with an auto-filled, still-editable
// average price beside each. The full list is loaded once and reused across
// rows/SKUs on the client.
require_once '../includes/auth.php';
require_login();
require_once '../includes/aon_helper.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!aon_can_access($_SESSION['role'] ?? '')) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Access denied.']);
    exit;
}

try {
    // Average unit price per item name = SUM(po_amount) / SUM(qty), matching the
    // "Avg Price 2025" figure the master importer derives from the same table.
    $stmt = $pdo->query("
        SELECT TRIM(item_description) AS name,
               SUM(po_amount)         AS amount,
               SUM(qty)               AS qty
        FROM purchases_2025
        WHERE qty > 0
          AND item_description IS NOT NULL AND TRIM(item_description) != ''
        GROUP BY TRIM(item_description)
        ORDER BY name
    ");

    $names = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['qty'];
        $avg = $qty > 0 ? (float)$r['amount'] / $qty : null;
        $names[] = [
            'name'      => $r['name'],
            'avg_price' => $avg !== null ? rtrim(rtrim(number_format($avg, 2, '.', ''), '0'), '.') : '',
        ];
    }
    echo json_encode(['status' => 'success', 'names' => $names]);
} catch (\PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
