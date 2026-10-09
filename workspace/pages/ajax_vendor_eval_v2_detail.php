<?php
// pages/ajax_vendor_eval_v2_detail.php
//
// On-demand data source for the #evalGradeTable "View" modal. Replaces the old
// EVAL_DETAILS blob (every group's full submission+answer data preloaded into
// one inline <script> JSON dump on every page load, capped at LIMIT 2000
// overall) with a fetch scoped to exactly the one vendor/L2/L3 group the user
// clicked — no cap needed since a single group is always small.
//
// Not a DataTables endpoint: plain {success, data|error} JSON.
require_once '../includes/auth.php';
require_once '../includes/vendor_eval_v2_access.php';
ve2_require_ajax_access();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_results_helper.php';

header('Content-Type: application/json');

try {
    $vendor = trim((string)($_POST['vendor'] ?? ''));
    $l2     = trim((string)($_POST['l2']     ?? ''));
    $l3     = trim((string)($_POST['l3']     ?? ''));

    // Same client-side filter state (gf) the table listing — and thus this
    // row's group — was built from: a submitted-after-date-range PO must not
    // show up in a modal for a group the date filter otherwise excluded.
    $gf = json_decode($_POST['gf'] ?? '', true);
    if (!is_array($gf)) $gf = [];
    [$where_sql, $params] = ve2_grade_where_from_filters($gf);

    // A blank group value can mean either NULL or '' in the DB — some older
    // submissions stored '' for an unset taxonomy_level_2 instead of NULL
    // (see vendor_eval_v2_public_engine.php / vendor_eval_v2_form2_team_engine.php,
    // both now fixed to always store NULL going forward). A plain `<=> NULL`
    // match missed those existing '' rows entirely, so a group the listing
    // table counted would come back as "0 submissions" in this modal. Match
    // either representation for a blank value instead of assuming NULL.
    $group_sql    = '';
    $group_params = [];
    foreach (['vendor_name' => $vendor, 'taxonomy_level_2' => $l2, 'taxonomy_level_3' => $l3] as $col => $val) {
        if ($val === '') {
            $group_sql .= " AND ({$col} IS NULL OR {$col} = '')";
        } else {
            $key = 'g_' . $col;
            $group_sql .= " AND {$col} = :{$key}";
            $group_params[$key] = $val;
        }
    }

    $d_stmt = $pdo->prepare("
        SELECT id, po_number, vendor_name, requestor_name,
               taxonomy_level_2, taxonomy_level_3,
               total_score, score_band, submitted_at
        FROM vendor_eval_submissions ves
        WHERE {$where_sql}
          {$group_sql}
        ORDER BY submitted_at DESC
    ");
    $d_stmt->execute(array_merge($params, $group_params));

    $rows_by_sub = [];
    $data        = [];
    foreach ($d_stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $dg = ($d['score_band'] !== '' && $d['score_band'] !== null)
            ? $d['score_band']
            : ve2_score_grade((float)$d['total_score']);
        $entry = [
            'po'           => $d['po_number'],
            'requestor'    => $d['requestor_name'],
            'score'        => number_format((float)$d['total_score'], 1),
            'grade'        => $dg,
            'submitted_at' => ve2_format_date($d['submitted_at']),
            'answers'      => [],
        ];
        $data[] = $entry;
        $lastIdx = array_key_last($data);
        $rows_by_sub[(int)$d['id']] = &$data[$lastIdx];
    }

    // Per-submission answers, split into Identification / Evaluation / Text —
    // same classification the old inline preload used (see the removed block
    // in vendor_eval_v2_results.php for the original comment/rationale).
    $sub_ids = array_keys($rows_by_sub);
    if (!empty($sub_ids)) {
        $ph = implode(',', array_fill(0, count($sub_ids), '?'));
        $a_stmt = $pdo->prepare("
            SELECT a.submission_id, a.question_no, a.criterion_name, a.question_text,
                   a.score, a.selected_score_label, a.weight, a.weighted_score, a.comment,
                   COALESCE(q.question_section, 'evaluation') AS question_section,
                   q.input_type
            FROM vendor_eval_answers a
            LEFT JOIN vendor_eval_questions q ON q.id = a.question_id
            WHERE a.submission_id IN ($ph)
            ORDER BY a.submission_id,
                     (COALESCE(q.question_section,'evaluation') = 'identification') DESC,
                     a.question_no
        ");
        $a_stmt->execute($sub_ids);
        foreach ($a_stmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $sid = (int)$a['submission_id'];
            if (!isset($rows_by_sub[$sid])) continue;

            if (($a['question_section'] ?? 'evaluation') === 'identification') {
                $section = 'identification';
            } elseif ($a['score'] === null) {
                $section = 'text';
            } else {
                $section = 'evaluation';
            }
            $ident_value = ($a['comment'] !== null && $a['comment'] !== '')
                ? (string)$a['comment']
                : (string)$a['selected_score_label'];

            $rows_by_sub[$sid]['answers'][] = [
                'section'    => $section,
                'no'         => (int)$a['question_no'],
                'criterion'  => (string)$a['criterion_name'],
                'prompt'     => (string)$a['question_text'],
                'input_type' => (string)($a['input_type'] ?? ''),
                'score'      => $a['score'] !== null ? (int)$a['score'] : null,
                'label'      => (string)$a['selected_score_label'],
                'weight_pct' => number_format((float)$a['weight'] * 100, 0),
                'weighted'   => number_format((float)$a['weighted_score'], 2),
                'comment'    => $a['comment'],
                'value'      => $ident_value,
            ];
        }
    }
    unset($rows_by_sub);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (PDOException $e) {
    error_log('ajax_vendor_eval_v2_detail.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Gagal memuat detail submission. Coba lagi.']);
}
