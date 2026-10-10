<?php
// pages/ajax_sku_search.php
// Fast JSON search endpoint for SKU Dictionary / Product Catalogue.
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? '');
$limit = isset($_GET['limit']) ? min(50, max(5, (int)$_GET['limit'])) : 25;

try {
    if ($q === '') {
        // Return popular / recent active items if query is empty
        $stmt = $pdo->prepare("
            SELECT id, sku, detail, uom, est_price, level_0, level_1, level_2, level_3
            FROM sku_dictionary
            WHERE (is_active IS NULL OR is_active IN ('Yes', 'TRUE', '1', ''))
              AND detail IS NOT NULL AND detail != ''
            ORDER BY id DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $exactTerm  = $q;
        $likeTerm   = '%' . $q . '%';
        $prefixTerm = $q . '%';

        $stmt = $pdo->prepare("
            SELECT id, sku, detail, uom, est_price, level_0, level_1, level_2, level_3
            FROM sku_dictionary
            WHERE (is_active IS NULL OR is_active IN ('Yes', 'TRUE', '1', ''))
              AND (sku LIKE :term1 OR detail LIKE :term2 OR hashtag LIKE :term3)
            ORDER BY 
              CASE 
                WHEN sku = :exact THEN 1 
                WHEN sku LIKE :prefix1 THEN 2 
                WHEN detail LIKE :prefix2 THEN 3
                ELSE 4 
              END,
              detail ASC
            LIMIT :lim
        ");
        $stmt->bindValue(':term1', $likeTerm, PDO::PARAM_STR);
        $stmt->bindValue(':term2', $likeTerm, PDO::PARAM_STR);
        $stmt->bindValue(':term3', $likeTerm, PDO::PARAM_STR);
        $stmt->bindValue(':exact', $exactTerm, PDO::PARAM_STR);
        $stmt->bindValue(':prefix1', $prefixTerm, PDO::PARAM_STR);
        $stmt->bindValue(':prefix2', $prefixTerm, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $results = [];
    foreach ($items as $item) {
        $price = (float)($item['est_price'] ?? 0);
        $cat = array_filter([$item['level_1'] ?? '', $item['level_2'] ?? '']);

        $results[] = [
            'id'                  => (int)$item['id'],
            'sku'                 => (string)($item['sku'] ?? ''),
            'detail'              => (string)($item['detail'] ?? ''),
            'uom'                 => strtoupper(trim((string)($item['uom'] ?: 'PCS'))),
            'est_price'           => $price,
            'est_price_formatted' => $price > 0 ? 'Rp ' . number_format($price, 0, ',', '.') : 'Rp 0',
            'category'            => !empty($cat) ? implode(' / ', $cat) : 'General'
        ];
    }

    echo json_encode([
        'status'  => 'success',
        'query'   => $q,
        'count'   => count($results),
        'results' => $results
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}
