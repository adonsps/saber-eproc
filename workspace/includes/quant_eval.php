<?php
// includes/quant_eval.php

/**
 * Recalculates the OTF Score for a specific GR and Delivery Date 
 * based on the provided configuration rules.
 */
function calculate_otf_score($gr_date, $delivery_date, $config) {
    if (empty($delivery_date)) {
        return ['score' => null, 'status' => null];
    }
    
    $deliv_dt = strtotime($delivery_date);
    
    if (!empty($gr_date)) {
        $gr_dt = strtotime($gr_date);
        $diff_days = floor(($gr_dt - $deliv_dt) / (60 * 60 * 24));
        
        if ($diff_days <= $config['otf_score_3_max_days']) {
            return ['score' => 3, 'status' => 'On Time'];
        } elseif ($diff_days <= $config['otf_score_2_max_days']) {
            return ['score' => 2, 'status' => 'Late 1-2 days'];
        } else {
            return ['score' => 1, 'status' => 'Late > 2 days'];
        }
    } else {
        $current_dt = strtotime(date('Y-m-d'));
        if ($current_dt <= $deliv_dt) {
            return ['score' => null, 'status' => 'Waiting Delivery'];
        } else {
            return ['score' => 1, 'status' => 'Waiting Delivery - Late'];
        }
    }
}

/**
 * Backfills / recalculates all existing PR quantitative scores (OTF & TOP) using the latest config and master vendor data.
 */
function recalculate_all_quantitative_scores(PDO $pdo) {
    // Get config
    $stmt = $pdo->query("SELECT * FROM quant_eval_config WHERE id = 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$config) return 0;
    
    // Fetch all PRs with their vendor's TOP score
    $stmt = $pdo->query("
        SELECT p.ticket_no, p.ticket_line_item, p.gr_date, p.delivery_date, COALESCE(q.score, 0) as top_score
        FROM prs p
        LEFT JOIN master_vendor mv ON p.vendor_name = mv.description
        LEFT JOIN quant_top_scores q ON mv.top = q.top_value
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $updated = 0;
    // The trailing NOT (... <=> ...) guard keeps this whole-table recalculation
    // from touching lines whose scores come out identical.
    //
    // Deliberately NOT stamped with last_updated_by/last_updated_date: those two
    // columns track assigned_pic only (see includes/pr_audit_stamp.php), and a
    // scoring sweep changes no PIC.
    $updStmt = $pdo->prepare("
        UPDATE prs
        SET otf_score = ?, otf_status = ?, top_score = ?
        WHERE ticket_no = ? AND ticket_line_item = ?
        AND NOT (otf_score <=> ? AND otf_status <=> ? AND top_score <=> ?)
    ");

    foreach ($rows as $row) {
        $res = calculate_otf_score($row['gr_date'], $row['delivery_date'], $config);
        if ($res['score'] !== null || $res['status'] !== null || $row['top_score'] !== null) {
            $updStmt->execute([
                $res['score'], $res['status'], $row['top_score'],
                $row['ticket_no'], $row['ticket_line_item'],
                $res['score'], $res['status'], $row['top_score']
            ]);
            $updated++;
        }
    }
    return $updated;
}
