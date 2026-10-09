<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/master_data_audit.php';
require_once '../includes/master_data_enrichment.php';
require_once '../includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date
header('Content-Type: application/json');

// Write endpoint: read-only Viewer sessions are refused before any row moves.
ws_require_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$itemsJson = isset($_POST['mappings']) ? $_POST['mappings'] : '';
$mappings = json_decode($itemsJson, true);

if (!is_array($mappings) || count($mappings) === 0) {
    echo json_encode(['status' => 'error', 'message' => 'No mappings provided.']);
    exit;
}

try {
    $mappedCount = 0;
    
    // Prepare statements outside the loop
    $checkMdStmt = $pdo->prepare("SELECT id FROM master_data WHERE level_4 = ? AND level_5 = ?");
    $audit_actor  = md_audit_actor();
    $insertMdStmt = $pdo->prepare("INSERT INTO master_data (level_0, level_1, level_2, level_3, level_4, level_5, pic_names, status, created_by, last_modified_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'registered', ?, ?)");
    // 'new' mode assigns a PIC, so it carries the edit-trail stamp (gated on the
    // PIC actually changing); the 'existing' branch below only re-points
    // master_data_id and is left unstamped. See includes/pr_audit_stamp.php.
    $updatePrStmt = $pdo->prepare("UPDATE prs SET " . pr_stamp_pic_set_sql()
        . ", master_data_id = ?, assigned_pic = ? WHERE ticket_no = ? AND ticket_line_item = ?");
    $updatePrNoPicStmt = $pdo->prepare("UPDATE prs SET master_data_id = ? WHERE ticket_no = ? AND ticket_line_item = ?");
    // One stamp for the whole batch — every line in it is the same edit.
    $pr_stamp_by = pr_stamp_by();
    $pr_stamp_at = pr_stamp_now();
    
    foreach ($mappings as $mapping) {
        $ticket_no = $mapping['ticket_no'] ?? '';
        $line_item = $mapping['ticket_line_item'] ?? '';
        $mode = $mapping['mode'] ?? 'existing';
        
        if (empty($ticket_no) || empty($line_item)) continue;

        if ($mode === 'new') {
            $l0 = trim($mapping['level_0'] ?? '');
            $l1 = trim($mapping['level_1'] ?? '');
            $l2 = trim($mapping['level_2'] ?? '');
            $l3 = trim($mapping['level_3'] ?? '');
            $l4 = trim($mapping['level_4'] ?? '');
            $l5 = trim($mapping['level_5'] ?? '');
            $pic_names = trim($mapping['pic_names'] ?? '');
            
            if (empty($l4)) continue; // L4 is required
            
            // Check if it already exists to avoid unique constraint violation
            $checkMdStmt->execute([$l4, $l5]);
            $existingMd = $checkMdStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existingMd) {
                $master_data_id = $existingMd['id'];
            } else {
                $insertMdStmt->execute([$l0, $l1, $l2, $l3, $l4, $l5, $pic_names, $audit_actor, $audit_actor]);
                $master_data_id = $pdo->lastInsertId();
                md_audit_log_insert($pdo, (int)$master_data_id, $audit_actor);
            }
            
            $assigned_pic = null;
            if (!empty($pic_names)) {
                if (strpos($pic_names, ',') !== false) {
                    $combo_pics = array_map('trim', explode(',', $pic_names));
                    $assigned_pic = $combo_pics[array_rand($combo_pics)]; 
                } else {
                    $assigned_pic = trim($pic_names);
                }
            }
            
            $updatePrStmt->execute(array_merge(
                pr_stamp_pic_params($assigned_pic, $pr_stamp_by, $pr_stamp_at),
                [$master_data_id, $assigned_pic, $ticket_no, $line_item]
            ));
            md_append_ticket_id($pdo, (int)$master_data_id, $ticket_no);
            $mappedCount++;

        } else {
            $master_data_id = $mapping['master_data_id'] ?? null;
            if (!empty($master_data_id)) {
                $updatePrNoPicStmt->execute([$master_data_id, $ticket_no, $line_item]);
                md_append_ticket_id($pdo, (int)$master_data_id, $ticket_no);
                $mappedCount++;
            }
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'mapped' => $mappedCount,
        'message' => "Successfully mapped $mappedCount items."
    ]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
