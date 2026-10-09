<?php
// workspace/includes/vendor_eval_v2_form2_team_engine.php
//
// Vendor Evaluation v2 — Form 2 (Project Retail — New Store) dual-team
// submission engine.
//
// Form 2 uses ONE shared, no-login public link per PO/site: both the
// Operations team (~4 people) and Project team (~10 people) reach the same
// worklist and the same per-PO form. There is no login/identity system
// distinguishing them — which team a submission belongs to is determined
// purely by which of two buttons the filler clicked ("Fill as Operations" /
// "Fill as Project"), carried forward as ?team=ops|project. This is a known,
// accepted trade-off (self-declared, not identity-verified).
//
// This is a SEPARATE engine from vendor_eval_v2_public_engine.php's
// ve2_render_public_eval_form() — Form 1's single-submission, requestor-
// token-gated behavior is completely unaffected by anything in this file.
// Form 2's semantics differ in ways that don't fit the shared function:
//   • No requestor_names_json allowlist check — the filler's name is
//     free-text, captured via the question set's own identification
//     question ("Nama"), not validated against any list.
//   • Two independent slots per PO (one per team), not one.
//   • Edits are allowed on your own team's submission for as long as the
//     OTHER team has not yet submitted; once both have submitted, both
//     submissions lock.
//   • The final grade (simple average of both teams' total_score) is
//     computed on read, never cached, so an edit made before both teams
//     lock is never left stale.
//
// Read tables  : prs (PO existence/vendor_name lookup + eligibility gate via
//                ve2_form2_po_eligibility()), vendor_eval_po_taxonomy
//                (display-only Sub Category/Commodity labels, never a gate),
//                vendor_eval_question_sets, vendor_eval_questions,
//                vendor_eval_submissions, vendor_eval_answers,
//                vendor_eval_store_opening_dates (via eligibility gate).
// Write tables : vendor_eval_submissions, vendor_eval_answers (POST only).
//
// Safety invariants (same posture as the shared engine):
//   • Never writes vendor_evaluations (v1) or the prs table.
//   • No auth.php / session-role requirement — public page.
//   • No WA / email / Gemini / external API calls.
//   • All DB access uses prepared statements (PDO).
//   • All output goes through h() (htmlspecialchars).
//   • No DB error messages exposed to users.
//
// Caller contract: same as ve2_render_public_eval_form() — the caller must
// have already called date_default_timezone_set('Asia/Jakarta'),
// session_start(), and required config/database.php (for $pdo). This file
// requires vendor_eval_v2_public_engine.php (for h(), ve2_block_page(),
// computeScoreBand(), csrfToken()/csrfCheck(), ve2_load_forced_set(),
// VE2_MAX_PARAM_LEN) and vendor_eval_v2_form2_eligibility.php (for
// ve2_form2_po_eligibility()) itself, so callers only need to require this
// one file.

require_once __DIR__ . '/vendor_eval_v2_public_engine.php'; // h(), ve2_block_page(), computeScoreBand(), csrfToken/csrfCheck, ve2_load_forced_set, VE2_MAX_PARAM_LEN
require_once __DIR__ . '/vendor_eval_v2_form2_eligibility.php'; // ve2_form2_po_eligibility(), ve2_form2_eligibility_message()

/** Display label for a team code. */
function ve2_form2_team_label(string $team): string
{
    return $team === 'ops' ? 'Operations' : 'Project';
}

/** The other of the two team codes. */
function ve2_form2_other_team(string $team): string
{
    return $team === 'ops' ? 'project' : 'ops';
}

/**
 * Final grade = simple average of both teams' total_score, computed on read
 * (never cached) so an edit made before both teams lock is never stale.
 * Returns null unless BOTH submissions are present.
 */
function ve2_form2_final_grade(?array $ops_sub, ?array $project_sub): ?array
{
    if ($ops_sub === null || $project_sub === null) {
        return null;
    }
    $avg = round(((float)$ops_sub['total_score'] + (float)$project_sub['total_score']) / 2, 4);
    return ['avg_score' => $avg, 'band' => computeScoreBand($avg)];
}

/** Both team submissions for a PO, keyed 'ops'/'project' (null if not submitted). */
function ve2_form2_load_submissions(PDO $pdo, string $po_number): array
{
    $stmt = $pdo->prepare("
        SELECT id, team, requestor_name, total_score, score_band, submitted_at, updated_at
          FROM vendor_eval_submissions
         WHERE po_number = ? AND team IN ('ops','project') AND status = 'submitted'
    ");
    $stmt->execute([$po_number]);
    $out = ['ops' => null, 'project' => null];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[$row['team']] = $row;
    }
    return $out;
}

/** Full-page "both teams have submitted, this PO is locked" info page (never returns). */
function ve2_form2_render_locked_page(string $po_number, array $subs, string $token = ''): void
{
    $final = ve2_form2_final_grade($subs['ops'], $subs['project']);
    $band_text = [
        'A' => 'text-green-700 bg-green-100', 'B' => 'text-blue-700 bg-blue-100',
        'C' => 'text-yellow-700 bg-yellow-100', 'D' => 'text-red-700 bg-red-100',
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Locked — <?= h($po_number) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 py-10 px-4">
<div class="max-w-2xl mx-auto space-y-4">
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="bg-indigo-700 px-6 py-5">
            <h2 class="text-2xl font-bold text-white">Evaluation Complete</h2>
            <p class="text-indigo-200 mt-1">Both teams have submitted for PO <?= h($po_number) ?>. No further edits are possible.</p>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-4 mb-6">
                <?php foreach (['ops' => 'Operations', 'project' => 'Project'] as $tk => $tl): $s = $subs[$tk]; ?>
                <div class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-center">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider"><?= h($tl) ?></p>
                    <p class="text-2xl font-black text-gray-800 mt-1"><?= h(number_format((float)$s['total_score'], 2)) ?></p>
                    <span class="inline-block text-xs font-bold mt-1 px-2 py-0.5 rounded <?= h($band_text[$s['score_band']] ?? 'text-gray-700 bg-gray-100') ?>"><?= h((string)$s['score_band']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($final !== null): ?>
            <div class="border-t border-gray-100 pt-4 text-center">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Final Grade (Average)</p>
                <p class="text-3xl font-black text-indigo-700 mt-1"><?= h(number_format($final['avg_score'], 2)) ?></p>
                <span class="inline-block text-lg font-black mt-1 px-3 py-1 rounded <?= h($band_text[$final['band']] ?? 'text-gray-700 bg-gray-100') ?>"><?= h($final['band']) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($token !== ''): ?>
    <div class="text-center">
        <a href="public_eval_v2_project_worklist.php?token=<?= h(urlencode($token)) ?>"
           class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2 px-4 rounded-lg transition">
            &larr; Back to Worklist
        </a>
    </div>
    <?php endif; ?>
</div>
</body>
</html><?php
    exit;
}

/**
 * Renders (and, on POST, processes) the Form 2 dual-team no-login evaluation
 * form for one PO + team.
 */
function ve2_render_form2_team_eval(PDO $pdo, string $forced_set_code): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $src    = $method === 'POST' ? $_POST : $_GET;

    $po_number = trim((string)($src['po']   ?? ''));
    $team      = trim((string)($src['team'] ?? ''));
    // Worklist token, carried through purely to build a "Back to Worklist" link
    // on the thank-you page — never used as an access gate here (the worklist
    // page itself re-validates it via ve2_form2_worklist_token_validate()).
    $token     = trim((string)($src['token'] ?? ''));
    if (strlen($token) > 64) {
        $token = '';
    }
    // Back-to-worklist link, reused by every ve2_block_page() call in this
    // function so no Form 2 response is ever a dead end — same token contract
    // as the thank-you page's link (never an access gate, just a courtesy).
    $worklist_back = $token !== '' ? ('public_eval_v2_project_worklist.php?token=' . urlencode($token)) : '';

    if ($po_number === '' || strlen($po_number) > VE2_MAX_PARAM_LEN) {
        ve2_block_page(
            'Invalid Evaluation Link',
            'This evaluation link is missing required parameters or contains invalid data. '
            . 'Please use the link provided to you and try again.',
            'bg-red-600',
            $worklist_back,
            'Back to Worklist'
        );
    }
    if (!in_array($team, ['ops', 'project'], true)) {
        ve2_block_page(
            'Invalid Evaluation Link',
            'This evaluation link does not specify a valid team. '
            . 'Please return to the worklist and choose "Fill as Operations" or "Fill as Project".',
            'bg-red-600',
            $worklist_back,
            'Back to Worklist'
        );
    }
    $other_team = ve2_form2_other_team($team);

    // PO lookup — existence + vendor_name from prs directly, the SAME source
    // ve2_form2_po_eligibility() and the worklist candidate query both use.
    // NOT vendor_eval_po_taxonomy: that table only ever gets a row once
    // gr_date is populated, so a fresh pre-GR construction PO — exactly what
    // Form 2 exists to evaluate — would otherwise be told "PO Not
    // Recognised" despite being a legitimate, eligible candidate.
    $stmt = $pdo->prepare("SELECT po_number, vendor_name FROM prs WHERE po_number = ? LIMIT 1");
    $stmt->execute([$po_number]);
    $prs_po = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prs_po) {
        ve2_block_page(
            'PO Not Recognised',
            'The purchase order referenced in this link was not found. '
            . 'Please contact the procurement team.',
            'bg-red-600',
            $worklist_back,
            'Back to Worklist'
        );
    }

    // Form 2 eligibility gate — the sole authority on whether this PO may be
    // evaluated. Re-checked independently on both GET and POST. There is no
    // separate "status=mapped" gate here (that was Form 1's model, keyed off
    // vendor_eval_po_taxonomy) — a PO that passes this gate is eligible,
    // full stop.
    $elig = ve2_form2_po_eligibility($pdo, $po_number);
    if (!$elig['eligible']) {
        [$et, $em, $eb] = ve2_form2_eligibility_message($elig['reason']);
        ve2_block_page($et, $em, $eb, $worklist_back, 'Back to Worklist');
    }

    // Taxonomy labels (Sub Category / Commodity) for display only, where a
    // row happens to exist — never a gate for Form 2.
    $tax_stmt = $pdo->prepare("SELECT taxonomy_level_2, taxonomy_level_3 FROM vendor_eval_po_taxonomy WHERE po_number = ? LIMIT 1");
    $tax_stmt->execute([$po_number]);
    $tax_row = $tax_stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $po = [
        'po_number'        => $prs_po['po_number'],
        'vendor_name'      => $prs_po['vendor_name'],
        'taxonomy_level_2' => $tax_row['taxonomy_level_2'] ?? null,
        'taxonomy_level_3' => $tax_row['taxonomy_level_3'] ?? null,
    ];

    // Both teams' current state.
    $subs   = ve2_form2_load_submissions($pdo, $po_number);
    $mine   = $subs[$team];
    $theirs = $subs[$other_team];
    $is_editing = ($mine !== null);

    // Both submitted → locked, no further edits from either side.
    if ($mine !== null && $theirs !== null) {
        ve2_form2_render_locked_page($po_number, $subs, $token);
    }

    // Question set — forced (Form 2 bypasses the L3/L2/Default chain entirely).
    $set = ve2_load_forced_set($pdo, $forced_set_code);
    if (!$set) {
        ve2_block_page(
            'Form Not Configured',
            'An evaluation form has not been configured for this purchase order category. '
            . 'Please contact the procurement team.',
            'bg-gray-600',
            $worklist_back,
            'Back to Worklist'
        );
    }

    $qstmt = $pdo->prepare("
        SELECT * FROM vendor_eval_questions
         WHERE question_set_id = ? AND is_active = 1
         ORDER BY (question_section = 'identification') DESC, question_no
    ");
    $qstmt->execute([$set['id']]);
    $questions = $qstmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($questions)) {
        ve2_block_page(
            'Form Not Configured',
            'The evaluation form for this purchase order has no active questions. '
            . 'Please contact the procurement team.',
            'bg-gray-600',
            $worklist_back,
            'Back to Worklist'
        );
    }

    // ══════════════════════════════════════════════════════════════════
    // POST — validate, write (insert or edit-in-place), thank-you page.
    // ══════════════════════════════════════════════════════════════════
    if ($method === 'POST') {
        if (!csrfCheck(trim((string)($_POST['_csrf_v2'] ?? '')))) {
            ve2_block_page(
                'Session Expired',
                'Your session has expired or the form was submitted from an invalid context. '
                . 'Please reload the evaluation link and try again.',
                'bg-yellow-600',
                $worklist_back,
                'Back to Worklist'
            );
        }

        $errors      = [];
        $answer_rows = [];
        $total_score = 0.0;
        $filler_name = '';

        foreach ($questions as $q) {
            $qid     = (int)$q['id'];
            $qtype   = ($q['question_type'] ?? 'rating');
            $section = ($q['question_section'] ?? 'evaluation');
            $comment = trim((string)($_POST['comment_' . $qid] ?? ''));
            $req     = (int)$q['comment_required'] === 1;
            $weight  = (float)$q['weight'];

            if ($section === 'identification') {
                $ident_val = trim((string)($_POST['ident_' . $qid] ?? ''));
                if ($req && $ident_val === '') {
                    $errors[] = 'Please fill in: ' . $q['criterion_name'];
                }
                if ($filler_name === '' && $ident_val !== '') {
                    $filler_name = $ident_val; // first identification answer = who filled this in
                }
                $answer_rows[] = [
                    'question_id' => $qid, 'question_no' => (int)$q['question_no'],
                    'criterion_name' => (string)$q['criterion_name'], 'question_text' => (string)$q['question_text'],
                    'question_type' => 'identification', 'score' => null,
                    'selected_score_label' => mb_substr($ident_val, 0, 255),
                    'weight' => '0.0000', 'weighted_score' => '0.0000',
                    'comment' => $ident_val !== '' ? $ident_val : null,
                ];
                continue;
            }

            if ($qtype === 'text') {
                if ($req && $comment === '') {
                    $errors[] = 'A response is required for: ' . $q['criterion_name'];
                }
                $answer_rows[] = [
                    'question_id' => $qid, 'question_no' => (int)$q['question_no'],
                    'criterion_name' => (string)$q['criterion_name'], 'question_text' => (string)$q['question_text'],
                    'question_type' => 'text', 'score' => null, 'selected_score_label' => '',
                    'weight' => '0.0000', 'weighted_score' => '0.0000',
                    'comment' => $comment !== '' ? $comment : null,
                ];
            } else {
                $score_raw = trim((string)($_POST['score_' . $qid] ?? ''));
                if (!in_array($score_raw, ['1', '2', '3', '4'], true)) {
                    $errors[] = 'Please select a score for: ' . $q['criterion_name'];
                    continue;
                }
                $score = (int)$score_raw;
                if ($req && $comment === '') {
                    $errors[] = 'A comment is required for: ' . $q['criterion_name'];
                }
                $weighted_score = round($score * $weight, 4);
                $total_score   += $weighted_score;
                $label_key      = 'score_' . $score . '_label';
                $answer_rows[]  = [
                    'question_id' => $qid, 'question_no' => (int)$q['question_no'],
                    'criterion_name' => (string)$q['criterion_name'], 'question_text' => (string)$q['question_text'],
                    'question_type' => 'rating', 'score' => $score,
                    'selected_score_label' => (string)($q[$label_key] ?? (string)$score),
                    'weight' => number_format($weight, 4, '.', ''),
                    'weighted_score' => number_format($weighted_score, 4, '.', ''),
                    'comment' => $comment !== '' ? $comment : null,
                ];
            }
        }

        if (!empty($errors)) {
            ve2_block_page(
                'Incomplete Submission',
                'Please complete all required fields before submitting: '
                . implode('; ', $errors)
                . '. Use your browser\'s back button to return to the form.',
                'bg-yellow-600',
                $worklist_back,
                'Back to Worklist'
            );
        }

        // Re-check the lock right before writing (a race where the other team
        // submitted between GET and this POST must still be caught).
        $subs_now = ve2_form2_load_submissions($pdo, $po_number);
        if ($subs_now[$team] !== null && $subs_now[$other_team] !== null) {
            ve2_form2_render_locked_page($po_number, $subs_now, $token);
        }

        $total_score = round($total_score, 4);
        $score_band  = computeScoreBand($total_score);
        $now         = date('Y-m-d H:i:s');
        $l2          = trim((string)($po['taxonomy_level_2'] ?? ''));
        $l3          = trim((string)($po['taxonomy_level_3'] ?? ''));
        $requestor_name = $filler_name !== '' ? $filler_name : (ve2_form2_team_label($team) . ' Team');

        try {
            $pdo->beginTransaction();

            $existing = $subs_now[$team];
            if ($existing !== null) {
                // Edit-in-place: allowed because the other team hasn't submitted yet.
                $submission_id = (int)$existing['id'];
                $upd = $pdo->prepare("
                    UPDATE vendor_eval_submissions
                       SET requestor_name = ?, question_set_id = ?, taxonomy_level_2 = ?, taxonomy_level_3 = ?,
                           total_score = ?, score_band = ?, updated_at = ?
                     WHERE id = ?
                ");
                $upd->execute([
                    $requestor_name, $set['id'], ($l2 !== '' ? $l2 : null), ($l3 !== '' ? $l3 : null),
                    number_format($total_score, 4, '.', ''), $score_band, $now, $submission_id,
                ]);
                $del = $pdo->prepare("DELETE FROM vendor_eval_answers WHERE submission_id = ?");
                $del->execute([$submission_id]);
            } else {
                $ins_sub = $pdo->prepare("
                    INSERT INTO vendor_eval_submissions
                        (po_number, vendor_name, requestor_name, team, question_set_id,
                         taxonomy_level_2, taxonomy_level_3,
                         total_score, score_band, status, submitted_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?)
                ");
                $ins_sub->execute([
                    $po_number, (string)($po['vendor_name'] ?? ''), $requestor_name, $team, $set['id'],
                    ($l2 !== '' ? $l2 : null), ($l3 !== '' ? $l3 : null),
                    number_format($total_score, 4, '.', ''), $score_band, $now, $now,
                ]);
                $submission_id = (int)$pdo->lastInsertId();
            }

            $ins_ans = $pdo->prepare("
                INSERT INTO vendor_eval_answers
                    (submission_id, question_id, question_no, criterion_name, question_text,
                     score, selected_score_label, weight, weighted_score, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($answer_rows as $ar) {
                $ins_ans->execute([
                    $submission_id, $ar['question_id'], $ar['question_no'], $ar['criterion_name'], $ar['question_text'],
                    $ar['score'], $ar['selected_score_label'], $ar['weight'], $ar['weighted_score'], $ar['comment'],
                ]);
            }

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (strpos((string)$e->getCode(), '23') === 0) {
                ve2_block_page(
                    'Already Submitted',
                    "Your team's evaluation for PO {$po_number} has already been recorded.",
                    'bg-green-600',
                    $worklist_back,
                    'Back to Worklist'
                );
            }
            ve2_block_page(
                'Submission Error',
                'A system error occurred while saving your evaluation. '
                . 'Please try again or contact the procurement team.',
                'bg-red-600',
                $worklist_back,
                'Back to Worklist'
            );
        }

        // Reload sibling state fresh (may have changed if the other team
        // submitted concurrently) to decide what the thank-you page shows.
        $final_subs = ve2_form2_load_submissions($pdo, $po_number);
        $final      = ve2_form2_final_grade($final_subs['ops'], $final_subs['project']);

        ve2_form2_render_thank_you($po_number, $team, $total_score, $score_band, $answer_rows, $final, $final_subs[$other_team] !== null, $token);
        exit;
    }

    // ══════════════════════════════════════════════════════════════════
    // GET — render the form (fresh, or pre-filled if editing).
    // ══════════════════════════════════════════════════════════════════
    $prior_answers = [];
    if ($is_editing) {
        $astmt = $pdo->prepare("SELECT question_id, score, selected_score_label, comment FROM vendor_eval_answers WHERE submission_id = ?");
        $astmt->execute([(int)$mine['id']]);
        foreach ($astmt->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $prior_answers[(int)$a['question_id']] = $a;
        }
    }

    $ident_questions = [];
    $eval_questions  = [];
    foreach ($questions as $q) {
        if (($q['question_section'] ?? 'evaluation') === 'identification') {
            $ident_questions[] = $q;
        } else {
            $eval_questions[] = $q;
        }
    }

    ve2_form2_render_question_form($po_number, $team, $po, $set, $ident_questions, $eval_questions, $prior_answers, $is_editing, $token);
}

/** GET-flow renderer: the actual question form, optionally pre-filled for an edit. */
function ve2_form2_render_question_form(
    string $po_number,
    string $team,
    array $po,
    array $set,
    array $ident_questions,
    array $eval_questions,
    array $prior_answers,
    bool $is_editing,
    string $token = ''
): void {
    $csrf_token  = csrfToken();
    $vendor_name = !empty($po['vendor_name']) ? (string)$po['vendor_name'] : '(vendor not specified)';
    $l2 = trim((string)($po['taxonomy_level_2'] ?? ''));
    $l3 = trim((string)($po['taxonomy_level_3'] ?? ''));
    $team_label = ve2_form2_team_label($team);
    $form_action = '?po=' . urlencode($po_number) . '&team=' . urlencode($team);

    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Evaluation — <?= h($po_number) ?> (<?= h($team_label) ?>)</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 py-10 px-4">
<div class="max-w-3xl mx-auto space-y-4">
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="bg-indigo-700 px-6 py-5">
            <h2 class="text-2xl font-bold text-white">Vendor Performance Evaluation</h2>
            <p class="text-indigo-200 mt-1">Project Retail — New Store. Please rate the vendor's performance for the purchase order below.</p>
        </div>

        <div class="p-6 bg-gray-50 border-b border-gray-200">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">PO Number</span>
                    <span class="font-mono font-bold text-indigo-700 text-lg"><?= h($po_number) ?></span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Vendor</span>
                    <span class="font-semibold text-gray-900"><?= h($vendor_name) ?></span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Filling In As</span>
                    <span class="inline-block text-xs font-bold text-indigo-700 bg-indigo-100 border border-indigo-200 rounded px-2 py-0.5"><?= h($team_label) ?> Team</span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Category</span>
                    <span class="text-gray-700">
                        <?= h($l2) ?>
                        <?php if ($l3 !== ''): ?><span class="text-gray-400 mx-1">&rsaquo;</span><?= h($l3) ?><?php endif; ?>
                    </span>
                </div>
            </div>
            <?php if ($is_editing): ?>
            <div class="mt-4 pt-4 border-t border-gray-200">
                <span class="inline-flex items-center gap-1 text-xs font-bold text-orange-700 bg-orange-100 border border-orange-200 rounded-full px-2.5 py-1">
                    Editing your team's previous submission — the other team has not submitted yet.
                </span>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" action="<?= h($form_action) ?>" class="p-6"
              onsubmit="var b=document.getElementById('ve2f2-submit-btn'); if(b){b.disabled=true; b.textContent='Submitting…';}">
            <input type="hidden" name="_csrf_v2" value="<?= h($csrf_token) ?>">
            <input type="hidden" name="po" value="<?= h($po_number) ?>">
            <input type="hidden" name="team" value="<?= h($team) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">

            <?php if (!empty($ident_questions)): ?>
            <div class="mb-8">
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Your Details</h3>
                <div class="space-y-4">
                    <?php foreach ($ident_questions as $q):
                        $qid   = (int)$q['id'];
                        $prior = (string)($prior_answers[$qid]['selected_score_label'] ?? '');
                        ve2_render_ident_field($q, $prior);
                    endforeach; ?>
                </div>
            </div>
            <?php if (!empty($eval_questions)): ?>
            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Vendor Performance</h3>
            <?php endif; ?>
            <?php endif; ?>

            <div class="space-y-6">
                <?php foreach ($eval_questions as $q):
                    $qid           = (int)$q['id'];
                    $prior         = $prior_answers[$qid] ?? null;
                    $prior_score   = $prior['score'] ?? null;
                    $prior_score   = $prior_score !== null ? (int)$prior_score : null;
                    $prior_comment = (string)($prior['comment'] ?? '');
                    ve2_render_eval_question_card($q, $prior_score, $prior_comment);
                endforeach; ?>
            </div>

            <div class="mt-8">
                <p class="text-xs text-gray-500 mb-3">
                    By submitting this form you confirm that your rating reflects the <?= h($team_label) ?> team's
                    direct experience with the vendor for PO <?= h($po_number) ?>.
                </p>
                <button type="submit" id="ve2f2-submit-btn"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold py-3 px-4 rounded-lg shadow transition text-lg disabled:opacity-60 disabled:cursor-not-allowed">
                    <?= $is_editing ? 'Update Evaluation' : 'Submit Evaluation' ?>
                </button>
            </div>
        </form>
    </div>
    <p class="text-center text-xs text-gray-400">Vendor Evaluation v2 &middot; <?= h(date('d M Y')) ?></p>
</div>
</body>
</html><?php
}

/** POST-flow renderer: thank-you page, with final grade if the other team has already submitted. */
function ve2_form2_render_thank_you(
    string $po_number,
    string $team,
    float $total_score,
    string $score_band,
    array $answer_rows,
    ?array $final,
    bool $other_submitted,
    string $token = ''
): void {
    $team_label = ve2_form2_team_label($team);
    $band_label = ['A' => 'Excellent', 'B' => 'Good', 'C' => 'Acceptable', 'D' => 'Poor'];
    $band_bar   = ['A' => 'bg-green-600', 'B' => 'bg-blue-600', 'C' => 'bg-yellow-500', 'D' => 'bg-red-600'];
    $band_text  = [
        'A' => 'text-green-700 bg-green-100', 'B' => 'text-blue-700 bg-blue-100',
        'C' => 'text-yellow-700 bg-yellow-100', 'D' => 'text-red-700 bg-red-100',
    ];
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Evaluation Submitted — <?= h($po_number) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 py-10 px-4">
<div class="max-w-2xl mx-auto space-y-4">
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">
        <div class="<?= h($band_bar[$score_band] ?? 'bg-green-600') ?> px-6 py-5">
            <h2 class="text-2xl font-bold text-white">Thank You, <?= h($team_label) ?> Team!</h2>
            <p class="text-green-100 mt-1">Your evaluation for PO <?= h($po_number) ?> has been recorded.</p>
        </div>
        <div class="p-6">
            <div class="flex flex-wrap items-center gap-6 mb-6 pb-6 border-b border-gray-100">
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Your Team's Score</p>
                    <p class="text-3xl font-black text-gray-800"><?= h(number_format($total_score, 1)) ?> <span class="text-sm font-normal text-gray-500">/ 4.0</span></p>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Your Team's Grade</p>
                    <span class="inline-block text-xl font-black px-3 py-1 rounded <?= h($band_text[$score_band] ?? 'text-gray-700 bg-gray-100') ?>">
                        <?= h($score_band) ?> &mdash; <?= h($band_label[$score_band] ?? '') ?>
                    </span>
                </div>
            </div>

            <?php if ($final !== null): ?>
            <div class="mb-6 pb-6 border-b border-gray-100 text-center">
                <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">Both Teams Submitted — Final Grade</p>
                <p class="text-3xl font-black text-indigo-700 mt-1"><?= h(number_format($final['avg_score'], 2)) ?></p>
                <span class="inline-block text-lg font-black mt-1 px-3 py-1 rounded <?= h($band_text[$final['band']] ?? 'text-gray-700 bg-gray-100') ?>"><?= h($final['band']) ?></span>
            </div>
            <?php elseif (!$other_submitted): ?>
            <div class="mb-6 pb-6 border-b border-gray-100 text-center text-sm text-gray-500">
                Waiting for the other team to submit before the final grade is available. You may still edit your
                answers until they do.
            </div>
            <?php endif; ?>

            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-3">Score Breakdown</p>
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-gray-400 uppercase border-b border-gray-200">
                        <th class="pb-2 font-bold">Criterion</th>
                        <th class="pb-2 font-bold text-right">Score</th>
                        <th class="pb-2 font-bold text-right">Weight</th>
                        <th class="pb-2 font-bold text-right">Weighted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($answer_rows as $ar):
                        $is_text_q = in_array($ar['question_type'] ?? 'rating', ['text', 'identification'], true);
                    ?>
                    <tr class="border-b border-gray-100 last:border-0">
                        <td class="py-2 text-gray-700"><?= h($ar['criterion_name']) ?></td>
                        <?php if ($is_text_q): ?>
                        <td class="py-2 text-left text-gray-400 italic text-xs" colspan="3">
                            <?= $ar['comment'] !== null ? h(mb_strimwidth($ar['comment'], 0, 100, '…')) : '(no response)' ?>
                        </td>
                        <?php else: ?>
                        <td class="py-2 text-right font-bold text-gray-800">
                            <?= h((string)$ar['score']) ?>/4
                            <span class="block text-xs font-normal text-gray-400"><?= h($ar['selected_score_label']) ?></span>
                        </td>
                        <td class="py-2 text-right text-gray-500"><?= h(number_format((float)$ar['weight'] * 100, 0)) ?>%</td>
                        <td class="py-2 text-right font-bold text-indigo-700"><?= h(number_format((float)$ar['weighted_score'], 1)) ?></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($token !== ''): ?>
    <div class="text-center">
        <a href="public_eval_v2_project_worklist.php?token=<?= urlencode($token) ?>"
           class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2 px-4 rounded-lg transition">
            &larr; Back to Worklist
        </a>
    </div>
    <?php endif; ?>
    <p class="text-center text-xs text-gray-400">Vendor Evaluation v2 &middot; <?= h(date('d M Y')) ?></p>
</div>
</body>
</html><?php
}
