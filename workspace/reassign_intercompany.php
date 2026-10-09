<?php
require_once 'config/database.php';
require_once __DIR__ . '/includes/pr_audit_stamp.php'; // prs.last_updated_by / last_updated_date

try {
    // Fetch dynamic rules
    $rules = $pdo->query("SELECT rule_type, rule_value, pic_name FROM pr_admin_routing_rules")->fetchAll(PDO::FETCH_ASSOC);
    
    $case_porg = "";
    $case_pgroup = "";
    $case_pgroup_label = "";
    
    foreach ($rules as $rule) {
        $val = $pdo->quote(strtoupper(trim($rule['rule_value'])));
        $pic = $pdo->quote($rule['pic_name']);
        
        if ($rule['rule_type'] === 'PORG') {
            $case_porg .= " WHEN UPPER(TRIM(prs.p_org)) = $val THEN $pic \n";
        } elseif ($rule['rule_type'] === 'PGROUP') {
            $case_pgroup .= " WHEN UPPER(TRIM(prs.p_group)) = $val THEN $pic \n";
        } elseif ($rule['rule_type'] === 'PGROUP_LABEL') {
            $case_pgroup_label .= " WHEN UPPER(TRIM(allowed_pgroups.label)) = $val THEN $pic \n";
        }
    }
    
    $pic_case = "CASE
            $case_porg
            $case_pgroup
            $case_pgroup_label
            ELSE NULL
        END";

    // The two stamp columns are assigned FIRST and behind an IF: MySQL applies
    // SET assignments left to right, so the comparison still sees the old PIC,
    // and a re-run of this sweep only stamps the lines it actually re-routes.
    $sql = "
        UPDATE prs
        LEFT JOIN allowed_pgroups ON prs.p_group = allowed_pgroups.pgroup_code
        SET " . pr_stamp_pic_set_sql($pic_case, 'prs.') . ",
        prs.assigned_pic = $pic_case,
        prs.assigned_by_ai = 0
        WHERE LOWER(prs.ticket_type) IN ('intercompany', 'intercompany new store')
          AND LOWER(prs.status_ticket) NOT LIKE '%cancel%'
          AND (prs.po_number IS NULL OR TRIM(prs.po_number) = '')
          AND (prs.canvassing_no IS NULL OR TRIM(prs.canvassing_no) = '')
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([pr_stamp_by(), pr_stamp_now()]);
    
    echo "Successfully updated " . $stmt->rowCount() . " outstanding intercompany PRs to the new PIC logic.";
} catch (PDOException $e) {
    echo "Error updating PRs: " . $e->getMessage();
}
