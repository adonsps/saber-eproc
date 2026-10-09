<?php
// pages/ajax_sku_autocomplete.php
require_once '../includes/auth.php';
require_login();
require_once '../config/database.php';
require_once '../includes/synonym_dictionary.php';

header('Content-Type: application/json');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($q)) {
    echo json_encode(['suggestions' => [], 'did_you_mean' => null]);
    exit;
}

$expanded_terms = expand_search_synonyms($q);
if (empty($expanded_terms)) {
    $expanded_terms = [$q];
}

$suggestions = [];
$did_you_mean = null;

try {
    // 1. Try to find actual matches in the database for the autocomplete drop-down
    // We use a combination of FULLTEXT (if possible) and LIKE for partial typing.
    // For real-time autocomplete, LIKE is usually better for partial words.
    
    $where_clauses = [];
    $params = [];
    
    foreach ($expanded_terms as $term) {
        $where_clauses[] = "detail LIKE ?";
        $params[] = "%{$term}%";
    }
    
    $where_sql = implode(' OR ', $where_clauses);
    
    $sql = "SELECT DISTINCT detail FROM sku_dictionary 
            WHERE ($where_sql) AND detail IS NOT NULL AND detail != '' 
            LIMIT 10";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($results as $row) {
        $suggestions[] = $row['detail'];
    }
    
    // 2. If no exact matches are found, generate a "Did you mean?" suggestion
    if (empty($suggestions)) {
        $closest = get_did_you_mean_suggestion($q);
        if ($closest) {
            $did_you_mean = $closest;
        }
    }
    
    echo json_encode([
        'suggestions' => $suggestions,
        'did_you_mean' => $did_you_mean,
        'expanded_terms' => $expanded_terms
    ]);
    
} catch (PDOException $e) {
    // Return empty on error to avoid breaking frontend
    echo json_encode(['suggestions' => [], 'did_you_mean' => null, 'error' => $e->getMessage()]);
}
?>
