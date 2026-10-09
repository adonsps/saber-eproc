<?php
require_once '../config/database.php';
require_once '../includes/auth.php';
require_once '../includes/master_data_audit.php';
require_once '../includes/master_data_enrichment.php';
require_once '../includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date
header('Content-Type: application/json');

// Write endpoint: refuses the read-only Viewer role with 403 JSON. This file
// carried no auth include at all before, so the gate also brings it in line
// with the rest of the AJAX surface (401 JSON on an expired session).
ws_require_write();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request method.']);
    exit;
}

$ticket_no = trim($_POST['ticket_no'] ?? '');
$ticket_line_item = trim($_POST['ticket_line_item'] ?? '');
$mode = $_POST['mode'] ?? 'existing'; // 'existing' or 'new'

if (empty($ticket_no) || empty($ticket_line_item)) {
    echo json_encode(['status' => 'error', 'message' => 'Ticket No and Line Item are required.']);
    exit;
}

try {
    $master_data_id = null;
    $pic_names = null;
    
    if ($mode === 'new') {
        $level_0 = trim($_POST['level_0'] ?? '');
        $level_1 = trim($_POST['level_1'] ?? '');
        $level_2 = trim($_POST['level_2'] ?? '');
        $level_3 = trim($_POST['level_3'] ?? '');
        $level_4 = trim($_POST['level_4'] ?? '');
        $pic_names = trim($_POST['pic_names'] ?? '');
        
        if (empty($level_4)) {
            echo json_encode(['status' => 'error', 'message' => 'Level 4 is required for new taxonomy.']);
            exit;
        }

        $audit_actor = md_audit_actor();

        // This form never collects level_5, so every row it creates has
        // level_5 NULL -- and MySQL's unique key on (level_4, level_5) treats
        // every NULL as distinct, so it never catches a repeat. Look for an
        // existing active/registered row under this exact level_4 first and
        // reuse it -- the same "check before you insert" ajax_sla_save_taxonomy_batch.php
        // already does -- otherwise typing "new" twice for the same category
        // mints a second live branch every time.
        $findStmt = $pdo->prepare(
            "SELECT id, pic_names FROM master_data WHERE level_4 = ? AND status IN ('active', 'registered') LIMIT 1"
        );
        $findStmt->execute([$level_4]);
        $existing = $findStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $master_data_id = (int)$existing['id'];
            $pic_names = $existing['pic_names'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO master_data (level_0, level_1, level_2, level_3, level_4, pic_names, status, created_by, last_modified_by) VALUES (?, ?, ?, ?, ?, ?, 'registered', ?, ?)");
            $stmt->execute([$level_0, $level_1, $level_2, $level_3, $level_4, $pic_names, $audit_actor, $audit_actor]);
            $master_data_id = $pdo->lastInsertId();
            md_audit_log_insert($pdo, (int)$master_data_id, $audit_actor);
        }
    } else {
        $master_data_id = (int)($_POST['master_data_id'] ?? 0);
        if ($master_data_id <= 0) {
            echo json_encode(['status' => 'error', 'message' => 'Invalid Master Data ID.']);
            exit;
        }
        
        // Fetch PIC names for existing ID to update mapping
        $stmt = $pdo->prepare("SELECT pic_names FROM master_data WHERE id = ?");
        $stmt->execute([$master_data_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['status' => 'error', 'message' => 'Master Data ID not found.']);
            exit;
        }
        $pic_names = $row['pic_names'];
    }

    // Determine specific PIC string (if multiple comma-separated)
    $assigned_pic = null;
    if (!empty($pic_names)) {
        if (strpos($pic_names, ',') !== false) {
            $combo_pics = array_map('trim', explode(',', $pic_names));
            $assigned_pic = $combo_pics[array_rand($combo_pics)]; 
        } else {
            $assigned_pic = trim($pic_names);
        }
    }

    // Update the PR line item
    // Note: Do NOT override assigned_pic if it was already manually assigned or GP resolved, 
    // unless they specifically want to re-map. For now we just update assigned_pic if the AI mapped it.
    // Actually, to fully "fix the PIC mapping", we overwrite assigned_pic.
    // Stamp first in the SET list, gated on the PIC actually changing — re-mapping
    // a line to a category whose PIC is the same person is not a PIC edit.
    $updateStmt = $pdo->prepare("UPDATE prs SET " . pr_stamp_pic_set_sql()
        . ", master_data_id = ?, assigned_pic = ? WHERE ticket_no = ? AND ticket_line_item = ?");
    $updateStmt->execute(array_merge(
        pr_stamp_pic_params($assigned_pic),
        [$master_data_id, $assigned_pic, $ticket_no, $ticket_line_item]
    ));
    md_append_ticket_id($pdo, (int)$master_data_id, $ticket_no);

    echo json_encode([
        'status' => 'success', 
        'message' => 'Taxonomy updated successfully.',
        'updated_id' => $master_data_id,
        'assigned_pic' => $assigned_pic
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
}
