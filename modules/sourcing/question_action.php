<?php
// modules/sourcing/question_action.php
session_start();
require '../../config.php';

// Security Check — same roles allowed to view manage_questions_new.php.
$allowed_roles = ['sourcing', 'vendor_mgt', 'vendormgt_head'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles, true)) {
    die("Access Denied");
}

// The management page is manage_questions_new.php (there is no manage_questions.php).
//
// The filters the editor had applied travel with the round-trip in `return_query`
// so the page they land back on is the page they left, rather than an unfiltered
// list scrolled to the top. Only the three known filter keys are carried across,
// rebuilt with http_build_query() -- the value reaches a Location: header, so
// nothing arbitrary from the request is allowed near it.
$redirect_page = 'manage_questions_new.php';

$return_params = [];
if (!empty($_REQUEST['return_query'])) {
    parse_str((string) $_REQUEST['return_query'], $parsed);
    foreach (['search', 'section_filter', 'variant_filter'] as $k) {
        if (isset($parsed[$k]) && is_string($parsed[$k]) && $parsed[$k] !== '') {
            $return_params[$k] = $parsed[$k];
        }
    }
}

/** manage_questions_new.php with the editor's filters plus one result message. */
function pqBackTo(string $page, array $params, string $key, string $value): string
{
    $params[$key] = $value;
    return $page . '?' . http_build_query($params);
}

// Justification trail + the repair of already-scored submissions that a change
// to a live question makes necessary. See the header of that file for why an
// edit here is never just an edit.
require_once __DIR__ . '/../../includes/pq_question_change_log.php';
// Money questions ("Pendapatan per Tahun", "Jumlah Modal") score from the bands
// written into their option list, so editing those options has to re-derive the
// answers already stored against them.
require_once __DIR__ . '/../../includes/pq_financial_scoring.php';

// uploads/ sits one level ABOVE the application root, matching every other
// uploader in this codebase (see modules/sourcing/upload_profile_file.php and
// modules/admin/download_vendor_docs.php).
$uploads_root = dirname(__DIR__, 2) . '/../uploads';

/**
 * Total of pq_weight.weight for one sub_variant_target group, optionally
 * excluding a question (used on edit). NULL/'' sub-variants form their own
 * group. Used to enforce the 100-point cap per business-model/sub-category.
 */
function pqGroupWeightSum(mysqli $conn, ?string $subVariant, ?int $excludeQuestionId = null): float {
    // Only ACTIVE questions consume the 100-point budget; inactive/deactivated
    // questions are excluded (they are not part of the live PQ).
    $base = "SELECT COALESCE(SUM(pw.weight),0) AS s
             FROM pq_weight pw
             JOIN pq_questions q ON q.id = pw.question_id AND q.is_active = 1
             WHERE ";
    if ($subVariant === null || $subVariant === '') {
        $sql = $base . "(pw.sub_variant_target IS NULL OR pw.sub_variant_target = '')";
        $types = ''; $params = [];
    } else {
        $sql = $base . "pw.sub_variant_target = ?";
        $types = 's'; $params = [$subVariant];
    }
    if ($excludeQuestionId !== null) {
        $sql .= " AND pw.question_id != ?";
        $types .= 'i'; $params[] = $excludeQuestionId;
    }
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    return (float) ($stmt->get_result()->fetch_assoc()['s'] ?? 0);
}

// Handle GET requests (Toggle Status) — lives on pq_questions only.
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['action'])) {
    if ($_GET['action'] == 'toggle' && isset($_GET['id']) && isset($_GET['status'])) {
        $id = intval($_GET['id']);
        $status = intval($_GET['status']);

        try {
            $stmt = $conn->prepare("UPDATE pq_questions SET is_active = ? WHERE id = ?");
            $stmt->bind_param("ii", $status, $id);
            $stmt->execute();
            header("Location: " . pqBackTo($redirect_page, $return_params, 'msg', "Question status updated successfully."));
        } catch (mysqli_sql_exception $e) {
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error', "Database error: " . $e->getMessage()));
        }
        exit();
    }
}

// Handle POST requests (Add/Edit).
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {

    // Guarded with ?? because on EDIT the business-model / sub-category / section
    // selects are disabled and therefore not submitted (they can't change once set).
    $section_id         = intval($_POST['section_id'] ?? 0);
    $weight             = floatval($_POST['weight'] ?? 0);
    $question_text      = trim($_POST['question_text'] ?? '');
    $help_text          = trim($_POST['help_text'] ?? '');
    $variant_target     = trim($_POST['variant_target'] ?? '');
    $sub_variant_target = trim($_POST['sub_variant_target'] ?? '');

    // Convert empty strings to NULL for DB consistency.
    if ($help_text === '') $help_text = null;
    if ($variant_target === '') $variant_target = null;
    if ($sub_variant_target === '') $sub_variant_target = null;

    // Data model: the question text/section/variant live on pq_questions, while
    // its weight + sub-variant live on pq_weight (1:1 via question_id). Both are
    // written together in a single transaction. help_text is stored on both so
    // the management listing (reads q.help_text) and the vendor PQ form (reads
    // COALESCE(pw.help_text, q.help_text)) stay in sync.
    if ($_POST['action'] == 'add') {
        // Enforce the 100-point cap for this sub-category group.
        $group_sum = pqGroupWeightSum($conn, $sub_variant_target);
        if ($group_sum + $weight > 100.0 + 0.001) {
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error',
                "Total weight for this business model/sub-category would be "
                . number_format($group_sum + $weight, 2) . " (max 100). Existing "
                . number_format($group_sum, 2) . " + " . number_format($weight, 2) . "."));
            exit();
        }
        try {
            $conn->begin_transaction();

            $stmt = $conn->prepare("INSERT INTO pq_questions (section_id, question_text, help_text, variant_target, is_active) VALUES (?, ?, ?, ?, 1)");
            $stmt->bind_param("isss", $section_id, $question_text, $help_text, $variant_target);
            $stmt->execute();
            $new_id = $conn->insert_id;

            $stmt = $conn->prepare("INSERT INTO pq_weight (question_id, sub_variant_target, weight, help_text) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isds", $new_id, $sub_variant_target, $weight, $help_text);
            $stmt->execute();

            $conn->commit();
            header("Location: " . pqBackTo($redirect_page, $return_params, 'msg', "New question added successfully."));
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error', "Failed to add question: " . $e->getMessage()));
        }
        exit();
    }

    if ($_POST['action'] == 'edit') {
        $id = intval($_POST['question_id']);

        // The state BEFORE this edit. Needed three times over: to decide whether
        // the scoring surface moved at all (and so whether a justification is
        // required), to work out which stored answers have to follow the options,
        // and to record the before/after in the change log.
        $before_res = $conn->query(
            "SELECT q.help_text AS q_help, pw.sub_variant_target, pw.weight, pw.help_text AS pw_help
             FROM pq_questions q
             LEFT JOIN pq_weight pw ON pw.question_id = q.id
             WHERE q.id = " . $id . " LIMIT 1"
        );
        $before = ($before_res && $before_res->num_rows) ? $before_res->fetch_assoc() : null;
        if (!$before) {
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error', "That question no longer exists."));
            exit();
        }

        $grp        = $before['sub_variant_target'] ?? $sub_variant_target;
        $old_weight = (float) ($before['weight'] ?? 0);

        // The options SCORING actually used, which is not necessarily the ones the
        // edit modal was showing. The vendor form and the review screen both read
        // COALESCE(pq_weight.help_text, pq_questions.help_text), so pq_weight wins
        // where the two have drifted apart -- and they have, on a good number of
        // live questions. Re-mapping against what the modal displayed instead of
        // against what scored the vendors would move the wrong answers.
        $old_effective_help = $before['pw_help'] !== null && trim((string) $before['pw_help']) !== ''
            ? $before['pw_help']
            : $before['q_help'];

        $weight_changed  = abs($weight - $old_weight) > 0.0001;
        $options_changed = trim((string) $old_effective_help) !== trim((string) $help_text);
        $score_remap     = $options_changed ? pqBuildScoreRemap($old_effective_help, $help_text) : [];

        // A justification is mandatory the moment the scoring surface moves --
        // weights are a shared 100-point budget and option scores re-grade
        // vendors, so both are decisions somebody will need explaining later.
        // Text edits and help wording are not, and are left unencumbered.
        $change_remarks = trim($_POST['change_remarks'] ?? '');
        if (($weight_changed || $options_changed) && $change_remarks === '') {
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error',
                "Please write a note explaining why the weight or the option scores are changing."));
            exit();
        }

        // Enforce the 100-point cap. sub_variant_target is locked on edit, so use
        // the question's stored group and exclude its own current weight from the sum.
        // Only block when the new weight INCREASES the group total past 100, so a
        // legacy group already over 100 doesn't lock out non-weight edits.
        //
        // Every group currently sits at exactly 100, which makes this the thing
        // that quietly refuses most weight edits -- so the message says which
        // group is full, by how much this would overshoot, and what to do about
        // it, and the page itself now shows the same budget before anyone starts
        // typing (see the Weight budget panel in manage_questions_new.php).
        $group_sum = pqGroupWeightSum($conn, $grp, $id);
        if (($group_sum + $weight > 100.0 + 0.001) && ($weight > $old_weight)) {
            $over = ($group_sum + $weight) - 100.0;
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error',
                '"' . ($grp !== null && $grp !== '' ? $grp : 'All Sub-types') . '" is a 100-point budget and is '
                . "already using " . number_format($group_sum + $old_weight, 2) . ". Setting this question to "
                . number_format($weight, 2) . " would take the group to " . number_format($group_sum + $weight, 2)
                . " — " . number_format($over, 2) . " over. Lower another question in the same group by "
                . number_format($over, 2) . " first, then make this change."));
            exit();
        }

        // DDL before the transaction on purpose: CREATE TABLE commits implicitly
        // in MySQL, so running it inside would silently end the transaction that
        // is supposed to be protecting the re-map below.
        $log_available = pqChangeLogEnsureTable($conn);

        // Saved before the transaction as well -- a file written to disk cannot be
        // rolled back, so a failure here has to stop the edit before it starts
        // rather than leave an orphan image behind.
        $attachment_path = null;
        if (isset($_FILES['change_image'])) {
            $up = pqChangeLogSaveAttachment($_FILES['change_image'], $uploads_root);
            if (!$up['ok']) {
                header("Location: " . pqBackTo($redirect_page, $return_params, 'error', $up['error']));
                exit();
            }
            $attachment_path = $up['path'];
        }

        try {
            $conn->begin_transaction();

            // section_id, variant_target and sub_variant_target are intentionally NOT
            // updated: they are locked after creation (disabled in the edit modal).
            $stmt = $conn->prepare("UPDATE pq_questions SET question_text = ?, help_text = ? WHERE id = ?");
            $stmt->bind_param("ssi", $question_text, $help_text, $id);
            $stmt->execute();

            // Update the editable pq_weight fields (weight + help_text), keeping the
            // locked sub_variant_target as-is. Insert a row only if one is missing
            // (legacy questions created before a weight row existed).
            $check = $conn->prepare("SELECT id FROM pq_weight WHERE question_id = ? LIMIT 1");
            $check->bind_param("i", $id);
            $check->execute();
            $has_weight = $check->get_result()->fetch_assoc();

            if ($has_weight) {
                $stmt = $conn->prepare("UPDATE pq_weight SET weight = ?, help_text = ? WHERE question_id = ?");
                $stmt->bind_param("dsi", $weight, $help_text, $id);
            } else {
                $stmt = $conn->prepare("INSERT INTO pq_weight (question_id, weight, help_text) VALUES (?, ?, ?)");
                $stmt->bind_param("ids", $id, $weight, $help_text);
            }
            $stmt->execute();

            // --- Carry the already-submitted answers across -----------------
            // An option that kept its wording but changed its number takes its
            // existing answers with it; anything renamed, added or removed is
            // left for a reviewer, because guessing there would re-grade a
            // vendor on a question nobody asked them again.
            //
            // AND ONLY WHILE THE PQ IS STILL WITH SOURCING. $open_subs is the
            // whole reach of this edit: submissions in draft, under Sourcing
            // review, out for revision or returned to Sourcing. Anything already
            // forwarded to VM, approved or rejected was decided against the rules
            // as they stood that day and keeps the score it was decided on --
            // nothing below may touch it. See PQ_SCORING_OPEN_STATUSES in
            // includes/pq_question_change_log.php for why.
            $open_subs   = pqOpenSubmissionIdsForQuestion($conn, $id);
            $locked_subs = pqLockedSubmissionCountForQuestion($conn, $id);
            $open_lookup = array_flip($open_subs);

            $remapped = 0;
            $rescored = 0;
            $touched  = [];      // submission id => true, so each is re-totalled once

            // A money question ("Pendapatan per Tahun", "Jumlah Modal") is scored
            // from the BANDS written into its option list, not from a chosen
            // option -- the vendor typed an amount. Label matching means nothing
            // to it, so its answers are re-derived by running each stored amount
            // through the new bands instead.
            if (pqFinancialType($question_text) !== null) {
                if ($options_changed) {
                    $fin = pqRedriveFinancialAnswers($conn, $id, $help_text, $open_subs);
                    $remapped = $fin['answers'];
                    foreach ($fin['submissions'] as $sid) $touched[$sid] = true;
                }
            } elseif (!empty($score_remap)) {
                $res = pqRemapAnswerScores($conn, $id, $score_remap, $open_subs);
                $remapped = $res['answers'];
                foreach ($res['submissions'] as $sid) $touched[$sid] = true;
            }

            // A weight change moves the totals of every submission that answered
            // this question, whether or not its own score moved -- so those join
            // the set rather than being handled as an alternative to it. Both can
            // happen in one save. Still only the open ones.
            if ($weight_changed) {
                $r = $conn->query(
                    "SELECT DISTINCT submission_id FROM pq_answers
                     WHERE question_id = " . $id . " AND selected_score IS NOT NULL"
                );
                if ($r) {
                    while ($x = $r->fetch_assoc()) {
                        $sid = (int) $x['submission_id'];
                        if (isset($open_lookup[$sid])) $touched[$sid] = true;
                    }
                }
            }

            foreach (array_keys($touched) as $sid) {
                if (pqRecalculateSubmission($conn, $sid)) $rescored++;
            }

            if ($log_available && ($weight_changed || $options_changed)) {
                pqChangeLogRecord($conn, [
                    'question_id'          => $id,
                    'changed_by'           => (int) ($_SESSION['user_id'] ?? 0),
                    'changed_by_name'      => (string) ($_SESSION['user_name'] ?? ''),
                    'change_type'          => trim(($weight_changed ? 'weight ' : '') . ($options_changed ? 'options' : '')),
                    'old_weight'           => $old_weight,
                    'new_weight'           => $weight,
                    'old_help_text'        => $old_effective_help,
                    'new_help_text'        => $help_text,
                    'remarks'              => $change_remarks,
                    'attachment_path'      => $attachment_path,
                    'answers_remapped'     => $remapped,
                    'submissions_rescored' => $rescored,
                ]);
            }

            $conn->commit();

            $msg = "Question updated successfully.";
            if ($remapped > 0) {
                $msg .= " $remapped submitted answer" . ($remapped === 1 ? '' : 's')
                     . (pqFinancialType($question_text) !== null
                        ? " re-scored against the new amount bands."
                        : " moved to the new option score.");
            }
            if ($rescored > 0) {
                $msg .= " $rescored submission" . ($rescored === 1 ? '' : 's') . " re-scored.";
            }
            // Said out loud, always, whenever the scoring surface moved: "12
            // answers re-scored" on its own reads as if that were all of them.
            if (($weight_changed || $options_changed) && $locked_subs > 0) {
                $msg .= " $locked_subs submission" . ($locked_subs === 1 ? '' : 's')
                     . " already with VM, approved or rejected " . ($locked_subs === 1 ? 'was' : 'were')
                     . " left unchanged.";
            }
            header("Location: " . pqBackTo($redirect_page, $return_params, 'msg', $msg));
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            header("Location: " . pqBackTo($redirect_page, $return_params, 'error', "Failed to update question: " . $e->getMessage()));
        }
        exit();
    }
}

// Fallback
header("Location: " . $redirect_page . (empty($return_params) ? '' : '?' . http_build_query($return_params)));
exit();
?>
