<?php
// includes/pq_question_change_log.php
//
// Everything that has to happen when someone edits a LIVE PQ question on
// modules/sourcing/manage_questions_new.php: the justification trail, and the
// repair of the submissions that question has already been used to score.
//
// WHY THIS EXISTS. A PQ question is not a piece of content -- it is a scoring
// rule that vendors have already been measured against. Changing "(3) Non-Audited"
// to "(4) Non-Audited" silently re-grades nobody: pq_answers.selected_score
// stores the NUMBER the reviewer picked, not which option they picked, so every
// vendor who answered 3 keeps a 3 that no longer corresponds to any option on
// the question. The review screen then shows an empty dropdown and a stale
// score, which is exactly the "I changed the score and nothing changed" that
// prompted this file.
//
// So an edit to the scoring surface now does three things instead of one:
//
//   1. RECORDS WHY.  pq_question_change_log keeps the before/after, the remarks
//      the editor had to write, and an optional screenshot. Weights are a
//      100-point budget shared across a business model -- moving one means
//      taking points off another, and six months later nobody remembers why.
//   2. RE-MAPS ANSWERS.  Options are matched OLD -> NEW BY LABEL. A label that
//      kept its text but changed its number carries its existing answers to the
//      new number; a label that was renamed, added or deleted is left alone,
//      because there is no honest way to guess what its answers meant.
//   3. RE-SCORES the submissions those answers belong to, so the totals on the
//      review screen agree with the answers underneath them.
//
// ...BUT ONLY WHILE THE PQ IS STILL WITH SOURCING. See
// PQ_SCORING_OPEN_STATUSES below: a submission that has been forwarded to VM,
// approved or rejected is a decision that was made against the rules as they
// stood that day, and is never re-graded by a later edit.
//
// The arithmetic in pqRecalculateSubmission() deliberately mirrors
// modules/sourcing/update_assessment.php, which is the authority on what a
// submission's stored totals mean. Where the two could differ they are written
// the same way on purpose, and the differences are commented at the point they
// occur. If update_assessment.php's scoring changes, this must change with it.

require_once __DIR__ . '/pq_risk_helper.php';

/** Uploaded justification images live here, relative to the uploads/ root. */
const PQ_CHANGE_LOG_UPLOAD_DIR = 'pq_question_changes';

/** Hard ceiling for the optional attachment. Bytes. */
const PQ_CHANGE_LOG_MAX_BYTES = 1048576;   // 1 MB

/**
 * The submission states a question edit is still allowed to re-score.
 *
 * A PQ that is still with Sourcing has not been decided yet, so it should be
 * measured against the current rules -- if the weight of a question changes
 * today, a review happening tomorrow ought to use the new one.
 *
 * The moment it leaves Sourcing that stops being true. 'under_review_vm' and
 * 'under_review_vm_head' mean a human is looking at a number that was sent to
 * them; 'approved' and 'rejected' mean a decision has been recorded against it.
 * Re-grading either of those would change a figure somebody has already signed
 * off or is actively signing off, retroactively and without their knowledge --
 * an approved vendor's risk category could move after the fact because an
 * unrelated question was re-weighted months later.
 *
 * So the frozen states are exactly: under_review_vm, under_review_vm_head,
 * approved, rejected. Everything else here is still in the Sourcing loop --
 * 'revision_needed' and 'returned_to_sourcing' both come back to Sourcing for
 * another pass, and 'draft' has not been submitted at all.
 *
 * Mirrored client-side in modules/sourcing/manage_questions_new.php only as
 * wording in the confirmation dialog; this list is the authority.
 */
const PQ_SCORING_OPEN_STATUSES = ['draft', 'under_review_sourcing', 'revision_needed', 'returned_to_sourcing'];

/** The open statuses as a quoted SQL list. Constant strings, no user input. */
function pqScoringOpenStatusSql(): string
{
    return "'" . implode("','", PQ_SCORING_OPEN_STATUSES) . "'";
}

/**
 * Submissions that answered this question and are still open to re-scoring.
 *
 * Everything downstream takes this list rather than working it out again, so
 * "which submissions may an edit touch" is decided in one place.
 *
 * @return int[]
 */
function pqOpenSubmissionIdsForQuestion(mysqli $conn, int $question_id): array
{
    if ($question_id <= 0) return [];
    $res = $conn->query(
        "SELECT DISTINCT a.submission_id
         FROM pq_answers a
         JOIN pq_submissions s ON s.id = a.submission_id
         WHERE a.question_id = " . (int) $question_id . "
           AND s.status IN (" . pqScoringOpenStatusSql() . ")"
    );
    $ids = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $sid = (int) $r['submission_id'];
            if ($sid > 0) $ids[] = $sid;
        }
    }
    return $ids;
}

/**
 * How many submissions answered this question but are past Sourcing, and so are
 * being left alone. Reported back to the editor, because "12 answers re-scored"
 * without "and 79 were not" is a misleading thing to be told.
 */
function pqLockedSubmissionCountForQuestion(mysqli $conn, int $question_id): int
{
    if ($question_id <= 0) return 0;
    $res = $conn->query(
        "SELECT COUNT(DISTINCT a.submission_id) AS c
         FROM pq_answers a
         JOIN pq_submissions s ON s.id = a.submission_id
         WHERE a.question_id = " . (int) $question_id . "
           AND s.status NOT IN (" . pqScoringOpenStatusSql() . ")"
    );
    return $res ? (int) ($res->fetch_assoc()['c'] ?? 0) : 0;
}

/**
 * Open/locked submission counts for EVERY question, in one pass.
 *
 * For the Manage PQ listing, which needs the numbers for all 49 questions at
 * once -- a correlated sub-query per row would read pq_answers once per
 * question for no reason.
 *
 * @return array<int,array{open:int,locked:int}> keyed by question_id
 */
function pqSubmissionCountsByQuestion(mysqli $conn): array
{
    $open = pqScoringOpenStatusSql();
    $res = $conn->query(
        "SELECT a.question_id,
                COUNT(DISTINCT CASE WHEN s.status IN ($open) THEN a.submission_id END) AS open_subs,
                COUNT(DISTINCT CASE WHEN s.status NOT IN ($open) THEN a.submission_id END) AS locked_subs
         FROM pq_answers a
         JOIN pq_submissions s ON s.id = a.submission_id
         GROUP BY a.question_id"
    );
    $out = [];
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $out[(int) $r['question_id']] = [
                'open'   => (int) $r['open_subs'],
                'locked' => (int) $r['locked_subs'],
            ];
        }
    }
    return $out;
}

/**
 * Create the change-log table if it is not there yet.
 *
 * Called on both the page and the save handler, matching how the rest of VSS
 * ships tables (see includes/migrated_reminder_helper.php). Every column is
 * nullable or carries a DEFAULT so an INSERT that names only some of them
 * cannot fail, and CREATE TABLE IF NOT EXISTS is valid on MySQL 8 and MariaDB
 * alike -- unlike ADD COLUMN IF NOT EXISTS, which is MariaDB-only.
 *
 * Failure is swallowed: a missing log table must never be the reason an editor
 * cannot fix a wrong weight. The caller checks the return value to decide
 * whether logging is available, not whether to proceed.
 */
function pqChangeLogEnsureTable(mysqli $conn): bool
{
    $sql = "CREATE TABLE IF NOT EXISTS pq_question_change_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        question_id INT NULL,
        changed_by INT NULL,
        changed_by_name VARCHAR(255) NULL,
        change_type VARCHAR(32) NULL,
        old_weight DECIMAL(5,2) NULL,
        new_weight DECIMAL(5,2) NULL,
        old_help_text TEXT NULL,
        new_help_text TEXT NULL,
        remarks TEXT NULL,
        attachment_path VARCHAR(500) NULL,
        answers_remapped INT NOT NULL DEFAULT 0,
        submissions_rescored INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pqcl_question (question_id),
        KEY idx_pqcl_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        return (bool) $conn->query($sql);
    } catch (mysqli_sql_exception $e) {
        error_log('[pq-change-log] ensure table failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Parse a help_text option block into score/label pairs.
 *
 * The stored format is one option per line, "(3) Ada, Non-Audited". Same regex
 * the vendor form and the review screen parse with, so a string any of them can
 * read is read the same way here.
 *
 * @return array<int,array{score:int,label:string,key:string}> in file order.
 *         'key' is the label normalised for comparison -- case-folded and with
 *         runs of whitespace collapsed -- so that re-indenting an option or
 *         changing its capitalisation does not read as a different option.
 */
function pqParseScoreOptions(?string $help_text): array
{
    $out = [];
    if ($help_text === null || trim($help_text) === '') return $out;

    preg_match_all('/\((\d+)\)\s*(.*?)(?=\(\d+\)|$)/s', $help_text, $m, PREG_SET_ORDER);
    foreach ($m as $row) {
        $label = trim($row[2]);
        if ($label === '') continue;
        $out[] = [
            'score' => (int) $row[1],
            'label' => $label,
            'key'   => strtolower(preg_replace('/\s+/', ' ', $label)),
        ];
    }
    return $out;
}

/**
 * Work out which stored scores should move, by matching options on their label.
 *
 * ONLY labels present on both sides with a changed number are returned. A label
 * that disappeared, appeared, or was reworded is deliberately absent: its old
 * answers stay where they are and the reviewer re-picks them, because nothing
 * here knows whether a reworded option still means the same thing.
 *
 * A label appearing twice on either side is skipped outright -- an ambiguous
 * match is worse than no match when the result is a silent re-grade.
 *
 * @return array<int,int> old score => new score
 */
function pqBuildScoreRemap(?string $old_help_text, ?string $new_help_text): array
{
    $old = pqParseScoreOptions($old_help_text);
    $new = pqParseScoreOptions($new_help_text);
    if (empty($old) || empty($new)) return [];

    $index = function (array $opts) {
        $by = [];
        $dupes = [];
        foreach ($opts as $o) {
            if (isset($by[$o['key']])) { $dupes[$o['key']] = true; continue; }
            $by[$o['key']] = $o['score'];
        }
        foreach (array_keys($dupes) as $k) unset($by[$k]);
        return $by;
    };

    $oldBy = $index($old);
    $newBy = $index($new);

    $remap = [];
    foreach ($oldBy as $key => $oldScore) {
        if (!isset($newBy[$key])) continue;               // renamed / removed
        if ($newBy[$key] === $oldScore) continue;         // unchanged
        // Two old options collapsing onto one new score would make the mapping
        // non-invertible; the first one wins and the rest are left for a human.
        if (isset($remap[$oldScore])) continue;
        $remap[$oldScore] = $newBy[$key];
    }
    return $remap;
}

/**
 * Apply a score re-map to every stored answer for one question.
 *
 * Done as ONE UPDATE with a CASE, not a statement per pair, because a re-map is
 * frequently a swap -- 3 becomes 4 while 4 becomes 3 -- and sequential updates
 * would run the first result through the second and collapse both onto one
 * value. A single CASE evaluates every branch against the original column.
 *
 * @param array<int,int> $remap old score => new score
 * @param int[] $only_submissions the submissions this edit may touch -- the
 *        ones still with Sourcing, from pqOpenSubmissionIdsForQuestion(). An
 *        empty list means nothing may move, which is a normal outcome for a
 *        question whose submissions have all been approved.
 * @return array{answers:int,submissions:int[]} rows changed, and the
 *         submission ids that owned them (captured BEFORE the update, since
 *         afterwards the old scores no longer exist to look up).
 */
function pqRemapAnswerScores(mysqli $conn, int $question_id, array $remap, array $only_submissions): array
{
    if ($question_id <= 0 || empty($remap) || empty($only_submissions)) {
        return ['answers' => 0, 'submissions' => []];
    }

    $oldScores = array_map('intval', array_keys($remap));
    $in = implode(',', $oldScores);
    $sids = implode(',', array_map('intval', $only_submissions));

    $submissions = [];
    $res = $conn->query(
        "SELECT DISTINCT submission_id FROM pq_answers
         WHERE question_id = " . (int) $question_id . "
           AND selected_score IN ($in)
           AND submission_id IN ($sids)"
    );
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $sid = (int) $r['submission_id'];
            if ($sid > 0) $submissions[] = $sid;
        }
    }
    if (empty($submissions)) return ['answers' => 0, 'submissions' => []];

    $case = 'CASE selected_score';
    foreach ($remap as $from => $to) {
        $case .= ' WHEN ' . (int) $from . ' THEN ' . (int) $to;
    }
    $case .= ' ELSE selected_score END';

    $conn->query(
        "UPDATE pq_answers SET selected_score = $case
         WHERE question_id = " . (int) $question_id . "
           AND selected_score IN ($in)
           AND submission_id IN ($sids)"
    );

    return ['answers' => $conn->affected_rows, 'submissions' => $submissions];
}

/**
 * Recompute one submission's stored totals from the answers now on record.
 *
 * Mirrors modules/sourcing/update_assessment.php: a question contributes only
 * when it applies to this vendor, carries a weight, and has actually been
 * answered, and it contributes (score / 5) * weight out of a possible weight.
 *
 * Note the consequence, which is update_assessment.php's rule and not this
 * file's invention: max_possible_weighted_score counts only ANSWERED questions,
 * so an unanswered question lowers the ceiling rather than the score.
 *
 * Financial questions are NOT re-derived from their remarks here. Their score
 * was computed by calculateFinancialScore() at review time and stored; this is
 * a recount of what is on record, not a re-review of it.
 *
 * @return bool true when the totals were rewritten; false when the submission
 *              cannot be read, is past Sourcing and therefore frozen, or when
 *              the re-score was deliberately declined (see the
 *              mis-classification guard in the body). Callers count this as
 *              "not re-scored", which is what it is.
 */
function pqRecalculateSubmission(mysqli $conn, int $submission_id): bool
{
    if ($submission_id <= 0) return false;

    $stmt = $conn->prepare(
        "SELECT s.id, s.status, s.max_possible_weighted_score AS stored_max,
                v.company_type, v.sub_company_type
         FROM pq_submissions s
         JOIN vendors v ON v.id = s.vendor_id
         WHERE s.id = ? LIMIT 1"
    );
    $stmt->bind_param('i', $submission_id);
    $stmt->execute();
    $sub = $stmt->get_result()->fetch_assoc();
    if (!$sub) return false;

    // Last line of defence for the freeze. The callers already filter to open
    // submissions, but this is the function that actually rewrites a stored
    // score, so it refuses on its own rather than trusting whoever called it:
    // a submission with VM, approved or rejected keeps the totals it was
    // decided on. See PQ_SCORING_OPEN_STATUSES.
    if (!in_array((string) $sub['status'], PQ_SCORING_OPEN_STATUSES, true)) {
        return false;
    }

    [$main_type_for_match, $pq_weight_sub_type] = pqScoringVariants(
        $sub['company_type'] ?? '',
        $sub['sub_company_type'] ?? ''
    );

    // Same weight/help_text resolution update_assessment.php uses: the row whose
    // sub_variant_target matches this vendor, falling back to a blank/NULL one.
    $q_sql = "SELECT
                q.id AS qid,
                q.variant_target,
                (SELECT weight FROM pq_weight WHERE question_id = q.id
                   AND (sub_variant_target = ? OR sub_variant_target = '' OR sub_variant_target IS NULL)
                 ORDER BY sub_variant_target DESC LIMIT 1) AS weight,
                (SELECT GROUP_CONCAT(sub_variant_target SEPARATOR ',') FROM pq_weight
                  WHERE question_id = q.id) AS all_sub_targets,
                a.selected_score
              FROM pq_questions q
              LEFT JOIN pq_answers a ON a.question_id = q.id AND a.submission_id = ?";

    $q_stmt = $conn->prepare($q_sql);
    $q_stmt->bind_param('si', $pq_weight_sub_type, $submission_id);
    $q_stmt->execute();
    $q_res = $q_stmt->get_result();

    $total = 0.0;
    $max   = 0.0;

    while ($q = $q_res->fetch_assoc()) {
        $weight = (float) ($q['weight'] ?? 0);
        $score  = $q['selected_score'] !== null ? (int) $q['selected_score'] : null;
        if ($weight <= 0 || $score === null) continue;

        // Written out rather than calling pqQuestionApplies(), which treats an
        // empty sub-type as matching anything. update_assessment.php requires a
        // non-empty sub-type before a question with sub-targets can apply, and
        // this has to agree with update_assessment.php or the totals it writes
        // and the totals written here would disagree for unclassified vendors.
        $v_target   = $q['variant_target'];
        $sv_targets = $q['all_sub_targets'];
        $applies = (empty($v_target) || stripos($v_target, $main_type_for_match) !== false)
            && (empty($sv_targets) || (!empty($pq_weight_sub_type) && stripos($sv_targets, $pq_weight_sub_type) !== false));
        if (!$applies) continue;

        $total += ($score / 5) * $weight;
        $max   += $weight;
    }

    // REFUSE TO ZERO A SUBMISSION THAT USED TO HAVE A SCORE.
    //
    // max === 0 means no question in the bank applies to this vendor at all,
    // which in practice means its classification is wrong rather than that it
    // scored nothing: there is at least one live approved submission whose
    // company_type is Supplier while its sub_company_type is 'Project', a
    // Contractor sub-category, so nothing matches on either side of the join.
    // Recomputing that honestly gives 0/0 -- and writing it would erase an
    // approved vendor's score as a side effect of editing an unrelated
    // question. A mis-classified vendor is a job for sourcing to re-classify,
    // which rewrites the score through the normal review path.
    //
    // A submission that was ALREADY 0/0 is left writable, so the genuinely
    // unscored ones can still be filled in.
    if ($max <= 0 && (float) ($sub['stored_max'] ?? 0) > 0) {
        error_log(sprintf(
            '[pq-change-log] skipped re-score of submission %d: no applicable questions '
            . '(company_type/sub_company_type mismatch), stored max %s left intact',
            $submission_id, (string) $sub['stored_max']
        ));
        return false;
    }

    $risk_pct = pqRiskPercentage($total, $max);
    $risk_cat = pqRiskCategory($risk_pct);

    $upd = $conn->prepare(
        "UPDATE pq_submissions
            SET total_weighted_score = ?, max_possible_weighted_score = ?,
                risk_percentage = ?, risk_category = ?
          WHERE id = ?"
    );
    $upd->bind_param('dddsi', $total, $max, $risk_pct, $risk_cat, $submission_id);
    return $upd->execute();
}

/**
 * Store the optional justification image for a change-log entry.
 *
 * Images only, 1 MB ceiling, and the extension is re-derived from what the file
 * actually is rather than trusted from its name -- this file is uploaded by a
 * privileged user, but a privileged user is exactly who an attacker would like
 * to trick into uploading a .php named .png.
 *
 * @param array       $file         one entry from $_FILES
 * @param string      $uploads_root absolute path to the uploads/ directory
 * @return array{ok:bool,path:?string,error:?string} path is relative to uploads/
 */
function pqChangeLogSaveAttachment(array $file, string $uploads_root): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['ok' => true, 'path' => null, 'error' => null];   // optional
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'path' => null, 'error' => 'Image is larger than the 1 MB limit.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'path' => null, 'error' => 'Image upload failed. Please try again.'];
    }
    if (($file['size'] ?? 0) > PQ_CHANGE_LOG_MAX_BYTES) {
        return ['ok' => false, 'path' => null, 'error' => 'Image is larger than the 1 MB limit.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['ok' => false, 'path' => null, 'error' => 'Image upload rejected.'];
    }

    // getimagesize() both identifies the type and proves the bytes really are an
    // image, which an extension or a browser-supplied MIME type does not.
    $info = @getimagesize($file['tmp_name']);
    $ext_by_type = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    if ($info === false || !isset($ext_by_type[$info[2]])) {
        return ['ok' => false, 'path' => null, 'error' => 'Only JPG, PNG, GIF or WEBP images can be attached.'];
    }
    $ext = $ext_by_type[$info[2]];

    $dir = rtrim($uploads_root, '/\\') . DIRECTORY_SEPARATOR . PQ_CHANGE_LOG_UPLOAD_DIR;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return ['ok' => false, 'path' => null, 'error' => 'Could not create the upload folder.'];
    }

    $name = 'pqchg_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $name)) {
        return ['ok' => false, 'path' => null, 'error' => 'Could not save the uploaded image.'];
    }

    return ['ok' => true, 'path' => PQ_CHANGE_LOG_UPLOAD_DIR . '/' . $name, 'error' => null];
}

/**
 * Write one change-log row. Best-effort: a logging failure is reported to the
 * caller but must not roll back the edit it describes.
 */
function pqChangeLogRecord(mysqli $conn, array $entry): bool
{
    try {
        $stmt = $conn->prepare(
            "INSERT INTO pq_question_change_log
               (question_id, changed_by, changed_by_name, change_type,
                old_weight, new_weight, old_help_text, new_help_text,
                remarks, attachment_path, answers_remapped, submissions_rescored)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $question_id   = (int) ($entry['question_id'] ?? 0);
        $changed_by    = (int) ($entry['changed_by'] ?? 0);
        $changed_name  = (string) ($entry['changed_by_name'] ?? '');
        $change_type   = (string) ($entry['change_type'] ?? '');
        $old_weight    = $entry['old_weight'] !== null ? (float) $entry['old_weight'] : null;
        $new_weight    = $entry['new_weight'] !== null ? (float) $entry['new_weight'] : null;
        $old_help      = $entry['old_help_text'] ?? null;
        $new_help      = $entry['new_help_text'] ?? null;
        $remarks       = (string) ($entry['remarks'] ?? '');
        $attachment    = $entry['attachment_path'] ?? null;
        $remapped      = (int) ($entry['answers_remapped'] ?? 0);
        $rescored      = (int) ($entry['submissions_rescored'] ?? 0);

        $stmt->bind_param(
            'iissddssssii',
            $question_id, $changed_by, $changed_name, $change_type,
            $old_weight, $new_weight, $old_help, $new_help,
            $remarks, $attachment, $remapped, $rescored
        );
        return $stmt->execute();
    } catch (mysqli_sql_exception $e) {
        error_log('[pq-change-log] insert failed: ' . $e->getMessage());
        return false;
    }
}
