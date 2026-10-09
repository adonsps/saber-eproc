<?php
// modules/sourcing/update_assessment.php
session_start();
require '../../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'sourcing') {
    if (isset($_POST['ajax_request'])) {
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => 'Access Denied']);
        exit;
    }
    die("Access Denied");
}

$current_user_id = $_SESSION['user_id'];
$current_user_name = $_SESSION['user_name'] ?? 'Sourcing Team';

/**
 * Scoring engine for financial questions
 */
function calculateFinancialScore($amount, $financial_type, $sub_variant) {
    $amount = (float) preg_replace('/[^0-9.]/', '', $amount);
    $B = 1000000000;
    $M = 1000000;
    $sub_variant = strtolower(trim($sub_variant));
    
    if ($financial_type === 'income') {
        if (strpos($sub_variant, 'project') !== false || strpos($sub_variant, 'foreman') !== false) {
            if ($amount > 100 * $B) return 5;
            if ($amount >= 50 * $B) return 3;
            if ($amount >= 10 * $B) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'retail & maintenance') !== false) {
            if ($amount > 5 * $B) return 5;
            if ($amount >= 1 * $B) return 3;
            if ($amount >= 500 * $M) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'principal') !== false || strpos($sub_variant, 'distributor') !== false || strpos($sub_variant, 'retail') !== false) {
            if ($amount > 50 * $B) return 5;
            if ($amount >= 15 * $B) return 3;
            if ($amount >= 2 * $B) return 1;
            return 0;
        }
    }
    
    if ($financial_type === 'capital') {
        if (strpos($sub_variant, 'project') !== false || strpos($sub_variant, 'foreman') !== false) {
            if ($amount > 20 * $B) return 5;
            if ($amount >= 5 * $B) return 3;
            if ($amount >= 1 * $B) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'retail & maintenance') !== false) {
            if ($amount > 5 * $B) return 5;
            if ($amount >= 1 * $B) return 3;
            if ($amount >= 500 * $M) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'principal') !== false || strpos($sub_variant, 'distributor') !== false) {
            if ($amount > 50 * $B) return 5;
            if ($amount >= 15 * $B) return 3;
            if ($amount >= 2 * $B) return 1;
            return 0;
        }
        if (strpos($sub_variant, 'retail') !== false) {
            if ($amount > 2 * $B) return 5;
            if ($amount >= 500 * $M) return 3;
            if ($amount >= 100 * $M) return 1;
            return 0;
        }
    }
    return 0; 
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $submission_id = intval($_POST['submission_id']);
    $vendor_id = intval($_POST['vendor_id']);
    $is_ajax = isset($_POST['ajax_request']) && $_POST['ajax_request'] == 1;
    
    $scores = $_POST['scores'] ?? []; 
    $reviewer_notes = $_POST['reviewer_notes'] ?? []; 
    $flags = $_POST['revision_flags'] ?? []; 
    $remarks_raw = $_POST['remarks'] ?? []; 
    
    $sub_company_type = isset($_POST['sub_company_type']) ? trim($_POST['sub_company_type']) : null;
    $total_weighted_score = 0;
    $max_possible_score = 0;
    
    $updated_questions_data = [];

    $conn->begin_transaction();
    try {
        // --- LOG CATEGORY CHANGES ---
        // Fetch existing vendor type and sub-type FIRST to compare
        $v_res = $conn->query("SELECT company_type, sub_company_type FROM vendors WHERE id = $vendor_id");
        $v_data = $v_res->fetch_assoc();
        $main_type = $v_data['company_type'];
        $old_sub_type = $v_data['sub_company_type'];

        // Check if Sourcing changed the Sub-Category mapping
        if (!empty($sub_company_type) && $sub_company_type !== $old_sub_type) {
            $safe_sub_type = $conn->real_escape_string($sub_company_type);
            $conn->query("UPDATE vendors SET sub_company_type = '$safe_sub_type' WHERE id = $vendor_id");
            
            // Insert into audit log directly (question_id is NULL for general updates)
            $old_val_disp = empty($old_sub_type) ? 'Unclassified' : $old_sub_type;
            $detail_str = "Changed Classification from [" . $old_val_disp . "] to [" . $sub_company_type . "]";
            
            $log_stmt = $conn->prepare("INSERT INTO pq_audit_logs (submission_id, question_id, user_id, action_detail) VALUES (?, NULL, ?, ?)");
            $log_stmt->bind_param("iis", $submission_id, $current_user_id, $detail_str);
            $log_stmt->execute();
        }
        // ------------------------------

        // Reset all flags first; we will re-apply them based on the POST data
        $conn->query("UPDATE pq_answers SET needs_revision = 0 WHERE submission_id = $submission_id");

        $q_sql = "SELECT 
                    q.id as qid, 
                    q.variant_target, 
                    q.question_text,
                    (SELECT weight FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1) as weight,
                    COALESCE((SELECT help_text FROM pq_weight WHERE question_id = q.id AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL) ORDER BY sub_variant_target DESC LIMIT 1), q.help_text) as help_text,
                    (SELECT GROUP_CONCAT(sub_variant_target SEPARATOR ',') FROM pq_weight WHERE question_id = q.id) as all_sub_targets
                  FROM pq_questions q";
        
        $q_stmt = $conn->prepare($q_sql);
        $q_stmt->bind_param("ss", $sub_company_type, $sub_company_type);
        $q_stmt->execute();
        $q_res = $q_stmt->get_result();
        
        while($q = $q_res->fetch_assoc()) {
            $qid = $q['qid'];
            $weight = floatval($q['weight'] ?? 0); 
            $help_text = $q['help_text']; 
            $v_target = $q['variant_target'];
            $sv_targets = $q['all_sub_targets'];
            $q_text_check = strtolower($q['question_text']);
            
            $is_applicable = false;
            if (empty($v_target) || stripos($v_target, $main_type) !== false) {
                if (empty($sv_targets) || (!empty($sub_company_type) && stripos($sv_targets, $sub_company_type) !== false)) {
                    $is_applicable = true;
                }
            }

            // Fetch EXACT existing state from DB for comparison
            $s_check = $conn->query("SELECT id, selected_score, reviewer_notes, needs_revision, reviewer_id, remarks FROM pq_answers WHERE submission_id=$submission_id AND question_id=$qid");
            $ex_row = $s_check->fetch_assoc();
            
            $row_exists = $ex_row ? true : false;
            $existing_score = $ex_row && $ex_row['selected_score'] !== null ? intval($ex_row['selected_score']) : null;
            $existing_note = $ex_row ? $ex_row['reviewer_notes'] : '';
            $existing_flag = $ex_row ? intval($ex_row['needs_revision']) : 0;
            $existing_remark = $ex_row ? $ex_row['remarks'] : '';
            $existing_reviewer_id = $ex_row ? $ex_row['reviewer_id'] : null;

            if (isset($scores[$qid]) || isset($reviewer_notes[$qid]) || isset($flags[$qid]) || isset($remarks_raw[$qid])) {
                
                $score_submitted = $existing_score;
                if (isset($scores[$qid])) {
                    if ($scores[$qid] === '') {
                        $score_submitted = null; // Treat empty dropdowns as null
                    } else {
                        $score_submitted = intval($scores[$qid]);
                    }
                }
                
                $note_submitted = isset($reviewer_notes[$qid]) ? trim($reviewer_notes[$qid]) : '';
                $flag_submitted = isset($flags[$qid]) ? 1 : 0;
                $remark_submitted = isset($remarks_raw[$qid]) ? trim($remarks_raw[$qid]) : $existing_remark;

                $calculated_score = $score_submitted;

                // Handle automatic scoring for financials
                if ($is_applicable && !empty($sub_company_type) && $remark_submitted !== '') {
                    if (strpos($q_text_check, 'income') !== false || strpos($q_text_check, 'pendapatan') !== false) {
                        $calculated_score = calculateFinancialScore($remark_submitted, 'income', $sub_company_type);
                    } elseif (strpos($q_text_check, 'capital') !== false || strpos($q_text_check, 'modal') !== false) {
                        $calculated_score = calculateFinancialScore($remark_submitted, 'capital', $sub_company_type);
                    }
                }

                // --- STRICT MODIFICATION CHECK & AUDIT LOGGING ---
                $was_modified = false;
                $action_details = []; // Array to store exact changes
                
                // 1. Normalize line endings to avoid browser \r\n vs DB \n false positives
                $note_submitted_clean = preg_replace("/\r\n|\r|\n/", "\n", trim($note_submitted));
                $existing_note_clean = preg_replace("/\r\n|\r|\n/", "\n", trim($existing_note ?? ''));
                
                $remark_submitted_clean = preg_replace("/\r\n|\r|\n/", "\n", trim($remark_submitted));
                $existing_remark_clean = preg_replace("/\r\n|\r|\n/", "\n", trim($existing_remark ?? ''));
                
                // 2. Strict Evaluation (Check manual inputs, NOT auto-calculated score)
                if ($score_submitted !== $existing_score) {
                    $was_modified = true;
                    $action_details[] = "Changed Score from [" . ($existing_score ?? 'None') . "] to [" . ($score_submitted ?? 'None') . "]";
                }
                
                if ($note_submitted_clean !== $existing_note_clean) {
                    $was_modified = true;
                    $action_details[] = "Updated Sourcing Note";
                }
                
                if ($flag_submitted !== $existing_flag) {
                    $was_modified = true;
                    $action_details[] = $flag_submitted ? "Flagged for Revision" : "Removed Revision Flag";
                }
                
                // Log Before and After values for Remarks/Amounts
                if ($remark_submitted_clean !== $existing_remark_clean) {
                    $was_modified = true;
                    
                    $old_val = $existing_remark_clean === '' ? 'Empty' : $existing_remark_clean;
                    $new_val = $remark_submitted_clean === '' ? 'Empty' : $remark_submitted_clean;
                    
                    $is_fin = (strpos($q_text_check, 'income') !== false || strpos($q_text_check, 'pendapatan') !== false || strpos($q_text_check, 'capital') !== false || strpos($q_text_check, 'modal') !== false);
                    
                    if ($is_fin) {
                        if ($old_val !== 'Empty' && is_numeric(str_replace(['.', ','], '', $old_val))) {
                            $old_val = 'Rp ' . number_format((float)str_replace(['.', ','], '', $old_val), 0, ',', '.');
                        }
                        if ($new_val !== 'Empty' && is_numeric(str_replace(['.', ','], '', $new_val))) {
                            $new_val = 'Rp ' . number_format((float)str_replace(['.', ','], '', $new_val), 0, ',', '.');
                        }
                    } else {
                        if (strlen($old_val) > 40) $old_val = substr($old_val, 0, 37) . '...';
                        if (strlen($new_val) > 40) $new_val = substr($new_val, 0, 37) . '...';
                    }

                    $action_details[] = "Changed Response/Amount from [" . htmlspecialchars($old_val) . "] to [" . htmlspecialchars($new_val) . "]";
                }

                // If ANY change occurred on this row during this save, stamp the current user.
                $reviewer_to_save = $was_modified ? $current_user_id : $existing_reviewer_id;

                if ($row_exists) {
                    $stmt = $conn->prepare("UPDATE pq_answers SET remarks = ?, selected_score = ?, reviewer_notes = ?, needs_revision = ?, reviewer_id = ? WHERE submission_id = ? AND question_id = ?");
                    $stmt->bind_param("sisiiii", $remark_submitted, $calculated_score, $note_submitted, $flag_submitted, $reviewer_to_save, $submission_id, $qid);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare("INSERT INTO pq_answers (submission_id, question_id, remarks, selected_score, reviewer_notes, needs_revision, reviewer_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("iisisii", $submission_id, $qid, $remark_submitted, $calculated_score, $note_submitted, $flag_submitted, $reviewer_to_save);
                    $stmt->execute();
                }
                
                // 3. INSERT INTO AUDIT LOG TABLE
                if ($was_modified && !empty($action_details)) {
                    $detail_str = implode(" | ", $action_details);
                    $log_stmt = $conn->prepare("INSERT INTO pq_audit_logs (submission_id, question_id, user_id, action_detail) VALUES (?, ?, ?, ?)");
                    $log_stmt->bind_param("iiis", $submission_id, $qid, $current_user_id, $detail_str);
                    $log_stmt->execute();
                }
                
                if ($is_applicable && $weight > 0 && $calculated_score !== null) {
                    $total_weighted_score += ($calculated_score / 5) * $weight;
                    $max_possible_score += $weight;
                }
                
                // Fetch the actual name associated with the saved reviewer ID for the UI
                $saved_reviewer_name = '';
                if ($reviewer_to_save) {
                    $name_res = $conn->query("SELECT full_name FROM users WHERE id = $reviewer_to_save");
                    if ($name_res && $name_row = $name_res->fetch_assoc()) {
                        $saved_reviewer_name = $name_row['full_name'];
                    }
                }
                
                // Gather the updated state for each question to return to the Canvas
                $updated_questions_data[$qid] = [
                    'weight' => $weight,
                    'help_text' => $help_text,
                    'selected_score' => $calculated_score,
                    'reviewer_name' => $saved_reviewer_name,
                    // Signal the UI to display the badge if a reviewer is actively attached to this row
                    'has_note_or_flag' => ($reviewer_to_save !== null) 
                ];

            } else {
                // If not submitted in the current form, add existing score to totals if applicable
                if ($is_applicable && $existing_score !== null && $weight > 0) {
                    $total_weighted_score += ($existing_score / 5) * $weight;
                    $max_possible_score += $weight;
                }
            }
        }

        $risk_percentage = ($max_possible_score > 0) ? (($max_possible_score - $total_weighted_score) / $max_possible_score) * 100 : 0;
        
        // --- 5-TIER RISK LOGIC ---
        $risk_category = 'Bad';
        if ($risk_percentage <= 15) {
            $risk_category = 'Excellent';
        } elseif ($risk_percentage <= 29) { 
            $risk_category = 'Good';
        } elseif ($risk_percentage <= 50) {
            $risk_category = 'Fair';
        } elseif ($risk_percentage <= 61) {
            $risk_category = 'Poor';
        }

        $stmt_upd = $conn->prepare("UPDATE pq_submissions SET total_weighted_score=?, max_possible_weighted_score=?, risk_percentage=?, risk_category=? WHERE id=?");
        $stmt_upd->bind_param("didsi", $total_weighted_score, $max_possible_score, $risk_percentage, $risk_category, $submission_id);
        $stmt_upd->execute();

        $conn->commit();
        
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'total_score' => number_format($total_weighted_score, 2),
                'max_possible_score' => (float)$max_possible_score,
                'risk_percentage' => number_format($risk_percentage, 1),
                'risk_category' => $risk_category,
                'questions_data' => $updated_questions_data
            ]);
            exit;
        } else {
            header("Location: review_pq.php?id=$vendor_id&msg=" . urlencode("Assessment updated successfully."));
            exit();
        }

    } catch (Exception $e) {
        $conn->rollback();
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        } else {
            die("Error updating assessment: " . $e->getMessage());
        }
    }
}
?>