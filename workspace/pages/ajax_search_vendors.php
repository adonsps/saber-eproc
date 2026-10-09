<?php
require_once '../config/database.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$type = isset($_GET['type']) ? trim($_GET['type']) : '';
$core = isset($_GET['core']) ? trim($_GET['core']) : '';
$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = 25;
$offset = ($page - 1) * $limit;

$whereSql = "WHERE 1=1";
$params = [];

if ($type !== '') {
    $whereSql .= " AND company_type = ?";
    $params[] = $type;
}

if ($core !== '') {
    $whereSql .= " AND sub_company_type = ?";
    $params[] = $core;
}

if ($name !== '') {
    $whereSql .= " AND company_name LIKE ?";
    $params[] = "%$name%";
}

try {
    // 1. KONEKSI KE DATABASE VSS
    $db_host = getenv('DB_HOST') ?: "localhost";
    if (php_sapi_name() === 'cli-server') {
        $vss_pdo = new PDO("mysql:host=127.0.0.1;dbname=u894129685_vmsportal;charset=utf8mb4", "root", "");
    } else {
        $db_vss_pass = "Xcel007!";
        $vss_pdo = new PDO("mysql:host=$db_host;dbname=u894129685_vmsportal;charset=utf8mb4", "u894129685_adonisvm", $db_vss_pass);
    }
    $vss_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM vendors " . $whereSql;
    $countStmt = $vss_pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $totalPages = ceil($totalCount / $limit);

    // Get data
    $sql = "SELECT id, company_name, registration_status, k2_status, email, phone, contact_person, company_type, sub_company_type FROM vendors " . $whereSql . " ORDER BY company_name ASC LIMIT ? OFFSET ?";
    
    // Bind all params correctly
    $stmt = $vss_pdo->prepare($sql);
    $paramIdx = 1;
    foreach ($params as $param) {
        $stmt->bindValue($paramIdx++, $param);
    }
    $stmt->bindValue($paramIdx++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($paramIdx, $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $vendors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'status' => 'success', 
        'data' => $vendors,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_records' => $totalCount,
            'limit' => $limit
        ]
    ]);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
