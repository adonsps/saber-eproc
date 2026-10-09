<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date

try {
    // Check role, ensure only admin/manager can execute
    if (!in_array($_SESSION['role'], ['Admin', 'Category Manager', 'Manager'])) {
        die("Unauthorized access.");
    }

    $pdo->beginTransaction();

    // 1. Build GP Officer Map from users table (supporting comma-separated IDs)
    $stmt = $pdo->query("SELECT gp_officer_id, name FROM users WHERE gp_officer_id IS NOT NULL AND gp_officer_id != ''");
    $gp_officer_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $gp_officer_map = [];
    foreach ($gp_officer_rows as $row) {
        $ids = explode(',', $row['gp_officer_id']);
        foreach ($ids as $id) {
            $clean_id = trim($id);
            if ($clean_id !== '') {
                $gp_officer_map[$clean_id] = $row['name'];
            }
        }
    }

    if (empty($gp_officer_map)) {
        die("No GP Officers found in user mappings.");
    }

    echo "<h3>Updating PRs based on GP Officer mapping...</h3>";
    echo "Found " . count($gp_officer_map) . " GP Officer ID mappings.<br>";

    // 2. Fetch all Outstanding 'Team GP Project' PRs
    // Based on the logic in excel_parser.php
    $stmt = $pdo->query("
        SELECT p.ticket_no, p.ticket_line_item, p.gp_officer, p.p_group, a.label 
        FROM prs p
        JOIN allowed_pgroups a ON p.p_group = a.pgroup_code
        WHERE a.label = 'Team GP Project'
          AND LOWER(p.ticket_type) NOT IN ('intercompany', 'intercompany new store')
          AND p.gp_officer IS NOT NULL AND p.gp_officer != ''
    ");
    
    $prs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // The stamp columns come first in the SET list and are gated on the PIC
    // actually changing, so a re-run of this sweep re-clears assigned_by_ai on
    // rows that already carry the right PIC without restamping the edit trail.
    $update_stmt = $pdo->prepare("
        UPDATE prs
        SET " . pr_stamp_pic_set_sql() . ",
            assigned_pic = ?, assigned_by_ai = 0
        WHERE ticket_no = ? AND ticket_line_item = ?
    ");
    $stamp_by = pr_stamp_by();
    $stamp_at = pr_stamp_now();

    $updatedCount = 0;

    foreach ($prs as $pr) {
        $gp_key = trim($pr['gp_officer']);
        if ($gp_key !== '' && isset($gp_officer_map[$gp_key])) {
            $matched_pic = $gp_officer_map[$gp_key];
            
            $update_stmt->execute(array_merge(
                pr_stamp_pic_params($matched_pic, $stamp_by, $stamp_at),
                [$matched_pic, $pr['ticket_no'], $pr['ticket_line_item']]
            ));
            $updatedCount++;
        }
    }

    $pdo->commit();
    echo "<b>Successfully updated {$updatedCount} PRs based on GP Officer mapping.</b><br>";
    echo "<a href='pages/dashboard_mgr.php'>Back to Dashboard</a>";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error updating PRs: " . $e->getMessage();
}
