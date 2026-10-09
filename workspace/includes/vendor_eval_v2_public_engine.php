<?php
// workspace/includes/vendor_eval_v2_public_engine.php
//
// Vendor Evaluation v2 — shared render engine for the no-login public forms.
//
// Form 1 (public_eval_v2.php) and Form 2 (public_eval_v2_project.php,
// Project Retail — New Store) are both thin wrappers around
// ve2_render_public_eval_form() defined here. This file holds the GET/POST/
// render/write logic exactly once so the two forms can never drift from each
// other except in the one thing that differs: $forced_set_code.
//
// Read tables  : vendor_eval_po_taxonomy, vendor_eval_question_sets,
//                vendor_eval_questions, vendor_eval_question_set_taxonomy_map,
//                vendor_eval_submissions (duplicate gate), prs +
//                vendor_eval_store_opening_dates (Form 2 eligibility only).
// Write tables : vendor_eval_submissions, vendor_eval_answers  (POST only)
//
// Safety invariants:
//   • Never writes vendor_evaluations (v1) or the prs table.
//   • No auth.php / session-role requirement — public page.
//   • No WA / email / Gemini / external API calls.
//   • All DB access uses prepared statements (PDO).
//   • All output goes through h() (htmlspecialchars).
//   • No DB error messages exposed to users.
//   • session_start() (for the CSRF token) is the caller's responsibility —
//     this file has no side effects on require.
//
// Caller contract:
//   - Must have already called date_default_timezone_set('Asia/Jakarta'),
//     session_start(), and required config/database.php (for $pdo) and
//     vendor_eval_v2_routing.php (for ve2_override_set()).
//   - When $forced_set_code is passed, must also have required
//     vendor_eval_v2_form2_eligibility.php — this file requires it directly
//     below since resolveQuestionSet()'s pattern exclusion needs
//     ve2_form2_is_in_scope_pgroup() regardless of which form calls it.

require_once __DIR__ . '/vendor_eval_v2_form2_eligibility.php';

// ── Constants ───────────────────────────────────────────────────────────────
if (!defined('VE2_SENTINEL_ANY_PUBLIC')) { define('VE2_SENTINEL_ANY_PUBLIC', '**ANY**'); }
if (!defined('VE2_MAX_PARAM_LEN')) { define('VE2_MAX_PARAM_LEN', 255); }

// ── Helper: HTML-safe output ─────────────────────────────────────────────────
if (!function_exists('h')) {
    function h(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── Helper: full-page block (never returns) ──────────────────────────────────
function ve2_block_page(string $title, string $message, string $bar_class = 'bg-indigo-600', string $back_url = '', string $back_label = 'Back to Evaluation List'): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($title) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-10 px-4">
<div class="max-w-lg w-full bg-white rounded-lg shadow-xl overflow-hidden">
    <div class="<?= h($bar_class) ?> px-6 py-5">
        <h2 class="text-xl font-bold text-white"><?= h($title) ?></h2>
    </div>
    <div class="p-6 text-gray-700 text-sm leading-relaxed">
        <?= h($message) ?>
        <?php if ($back_url !== ''): ?>
        <div class="mt-4">
            <a href="<?= h($back_url) ?>"
               class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2 px-4 rounded-lg transition">
                &larr; <?= h($back_label) ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html><?php
    exit;
}

// ── Helper: resolve question set (L3 → L2 → default) ────────────────────────
// $p_group is optional so existing callers keep working; when supplied, the
// L2 branch is excluded from matching VE_PROJECT_RETAIL_V1 for any PGroup in
// Form 2's scope (see vendor_eval_v2_form2_eligibility.php) — Form 2 is now
// the exclusive route for those PGroups at the L2-fallback level. Pattern-
// based, not a hardcoded PGroup list, so new *97 codes stay excluded
// automatically. The L3 branch is untouched (an exact L3 match, e.g.
// Civil-Architecture, still resolves normally on Form 1).
function resolveQuestionSet(PDO $pdo, string $l2, ?string $l3, ?string $p_group = null): ?array
{
    $l3_val = ($l3 !== null && $l3 !== '') ? $l3 : '';
    $exclude_l2_project_v1 = ($p_group !== null && $p_group !== '' && ve2_form2_is_in_scope_pgroup($p_group));

    $stmt = $pdo->prepare("
        SELECT qs.id, qs.set_code, qs.set_name, m.map_level,
               m.taxonomy_level_2, m.taxonomy_level_3
          FROM vendor_eval_question_set_taxonomy_map m
          JOIN vendor_eval_question_sets qs ON qs.id = m.question_set_id
         WHERE qs.is_active = 1
           AND m.is_active  = 1
           AND (
                   (m.map_level = 'l3'
                    AND m.taxonomy_level_2 = :l2a
                    AND m.taxonomy_level_3 = :l3a)
                OR (m.map_level = 'l2'
                    AND m.taxonomy_level_2 = :l2b
                    AND m.taxonomy_level_3 = :any_l2
                    AND NOT (:exclude_l2_project = 1 AND qs.set_code = :project_set_code))
                OR (m.map_level = 'default'
                    AND m.taxonomy_level_2 = :any_def
                    AND m.taxonomy_level_3 = :any_def2)
               )
         ORDER BY
               CASE m.map_level
                   WHEN 'l3'      THEN 1
                   WHEN 'l2'      THEN 2
                   ELSE                3
               END
         LIMIT 1
    ");
    $stmt->execute([
        ':l2a'      => $l2,
        ':l3a'      => $l3_val,
        ':l2b'      => $l2,
        ':any_l2'   => VE2_SENTINEL_ANY_PUBLIC,
        ':any_def'  => VE2_SENTINEL_ANY_PUBLIC,
        ':any_def2' => VE2_SENTINEL_ANY_PUBLIC,
        ':exclude_l2_project' => $exclude_l2_project_v1 ? 1 : 0,
        ':project_set_code'   => 'VE_PROJECT_RETAIL_V1',
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

// ── Helper: forced question set (Form 2 — bypasses the L3/L2/Default chain
// entirely) ───────────────────────────────────────────────────────────────
function ve2_load_forced_set(PDO $pdo, string $set_code): ?array
{
    $stmt = $pdo->prepare("SELECT id, set_code, set_name FROM vendor_eval_question_sets WHERE set_code = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$set_code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }
    $row['id']        = (int)$row['id'];
    $row['map_level'] = 'forced';
    return $row;
}

// ── Helper: score band ───────────────────────────────────────────────────────
function computeScoreBand(float $total): string
{
    if ($total >= 3.50) return 'A';
    if ($total >= 2.50) return 'B';
    if ($total >= 1.50) return 'C';
    return 'D';
}

// ── Helper: CSRF ─────────────────────────────────────────────────────────────
function csrfToken(): string
{
    if (empty($_SESSION['_csrf_v2'])) {
        $_SESSION['_csrf_v2'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['_csrf_v2'];
}

function csrfCheck(string $submitted): bool
{
    return !empty($_SESSION['_csrf_v2'])
        && hash_equals($_SESSION['_csrf_v2'], $submitted);
}

// ── Shared question-card renderers ───────────────────────────────────────────
// Form 1 (ve2_render_public_eval_form() below) and Form 2
// (ve2_form2_render_question_form() in vendor_eval_v2_form2_team_engine.php)
// both call these for every question row so the two forms render identically
// — including the rubric table — and can't drift from each other again.
// Form 1 always renders fresh (no prior answer); Form 2 passes the prior
// answer when editing an existing submission.

/** Renders one "Your Details" (identification) field. */
function ve2_render_ident_field(array $q, string $prior_value = ''): void
{
    $qid     = (int)$q['id'];
    $itype   = in_array(($q['input_type'] ?? 'text'), ['text', 'email', 'date', 'textarea'], true)
             ? (string)$q['input_type'] : 'text';
    $ireq    = (int)$q['comment_required'] === 1;
    $iprompt = trim((string)($q['question_text'] ?? ''));
    $ihelp   = trim((string)($q['question_help_text'] ?? ''));
    ?>
    <div class="bg-gray-50 p-4 border border-gray-200 rounded-lg">
        <label for="ident_<?= $qid ?>" class="block text-sm font-bold text-gray-800 mb-1">
            <?= h((string)$q['criterion_name']) ?>
            <?php if ($ireq): ?><span class="text-red-500">*</span><?php else: ?><span class="text-gray-400 font-normal text-xs">(optional)</span><?php endif; ?>
        </label>
        <?php if ($iprompt !== ''): ?><p class="text-xs text-gray-600 mb-2"><?= h($iprompt) ?></p><?php endif; ?>
        <?php if ($ihelp !== ''): ?><p class="text-xs text-gray-500 italic mb-2"><?= h($ihelp) ?></p><?php endif; ?>
        <?php if ($itype === 'textarea'): ?>
        <textarea id="ident_<?= $qid ?>" name="ident_<?= $qid ?>" rows="3" <?= $ireq ? 'required' : '' ?>
                  class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 resize-y"><?= h($prior_value) ?></textarea>
        <?php else: ?>
        <input id="ident_<?= $qid ?>" name="ident_<?= $qid ?>" type="<?= h($itype) ?>" value="<?= h($prior_value) ?>" <?= $ireq ? 'required' : '' ?>
               class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400">
        <?php endif; ?>
    </div>
    <?php
}

/** Renders one evaluation-section question card (header, rubric table, score radios or text). */
function ve2_render_eval_question_card(array $q, ?int $prior_score = null, string $prior_comment = ''): void
{
    $qid      = (int)$q['id'];
    $qno      = (int)$q['question_no'];
    $qtype    = ($q['question_type'] ?? 'rating');
    $wt_pct   = number_format((float)$q['weight'] * 100, 0);
    $required = (int)$q['comment_required'] === 1;

    // Rubric descriptions (null when columns empty or schema pre-migration)
    $descs = [
        1 => trim((string)($q['score_1_description'] ?? '')),
        2 => trim((string)($q['score_2_description'] ?? '')),
        3 => trim((string)($q['score_3_description'] ?? '')),
        4 => trim((string)($q['score_4_description'] ?? '')),
    ];
    $has_rubric = array_filter($descs) !== [];

    // Per-criterion comment prompt; null = use default wording
    $cprompt = trim((string)($q['comment_prompt'] ?? ''));
    $cprompt = $cprompt !== '' ? $cprompt : null;

    // Optional supporting helper text / image (null/empty when unset or pre-migration)
    $help_text = trim((string)($q['question_help_text'] ?? ''));
    $img_path  = trim((string)($q['question_image_path'] ?? ''));
    ?>
    <div class="bg-gray-50 p-5 border border-gray-200 rounded-lg">
        <div class="flex gap-3 md:gap-4">
            <div class="shrink-0 w-8 md:w-10 pt-0.5 text-xs font-bold text-gray-400 uppercase tracking-wider">Q<?= $qno ?></div>
            <div class="flex-1 min-w-0">

                <!-- Criterion header -->
                <div class="flex flex-wrap items-baseline gap-2 mb-2">
                    <h4 class="font-bold text-gray-900"><?= h((string)$q['criterion_name']) ?></h4>
                    <?php if ($qtype !== 'text' && (float)$q['weight'] > 0): ?>
                    <span class="text-xs font-bold text-indigo-700 bg-indigo-100 px-2 py-0.5 rounded">Weight: <?= h($wt_pct) ?>%</span>
                    <?php endif; ?>
                </div>
                <?php if ($qtype === 'text'): ?>
                <p class="text-sm text-gray-700 mb-4"><?= h((string)$q['question_text']) ?></p>
                <?php endif; ?>

                <?php if ($help_text !== ''): ?>
                <p class="text-xs text-gray-500 italic mb-3"><?= h($help_text) ?></p>
                <?php endif; ?>

                <?php if ($img_path !== ''): ?>
                <div class="mb-4">
                    <img src="<?= h('../uploads/vendor_eval_questions/' . ltrim($img_path, '/\\')) ?>"
                         alt="" loading="lazy"
                         class="max-w-full max-h-64 rounded border border-gray-200"
                         onerror="this.style.display='none'">
                </div>
                <?php endif; ?>

                <?php if ($qtype === 'text'): ?>
                <!-- Text / conclusion question: textarea only, no score radio -->
                <div>
                    <label for="comment_<?= $qid ?>" class="block text-xs font-bold text-gray-600 mb-1">
                        Your Response <span class="text-red-500">*</span>
                    </label>
                    <textarea
                        id="comment_<?= $qid ?>"
                        name="comment_<?= $qid ?>"
                        rows="4"
                        <?= $required ? 'required' : '' ?>
                        placeholder="<?= h($cprompt ?? 'Please provide your overall assessment.') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 resize-y"><?= h($prior_comment) ?></textarea>
                </div>

                <?php else: ?>
                <!-- Rating question -->

                <?php if ($has_rubric): ?>
                <!-- Rubric reference table (shown when descriptions are populated) -->
                <div class="mb-4 overflow-x-auto">
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2">Scoring Rubric</p>
                    <table class="w-full text-xs border border-gray-200 rounded">
                        <thead>
                            <tr class="bg-gray-100">
                                <?php foreach ([1, 2, 3, 4] as $sv): ?>
                                <th class="px-3 py-2 text-left font-bold text-gray-600 border-r border-gray-200 last:border-r-0 w-1/4">
                                    <?= $sv ?> — <?= h((string)($q['score_' . $sv . '_label'] ?? '')) ?>
                                </th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php foreach ([1, 2, 3, 4] as $sv): ?>
                                <td class="px-3 py-2 text-gray-700 align-top border-r border-gray-200 last:border-r-0 leading-snug">
                                    <?= $descs[$sv] !== ''
                                        ? h($descs[$sv])
                                        : '<span class="text-gray-300">—</span>' ?>
                                </td>
                                <?php endforeach; ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <!-- Score radio options (4 best → 1 worst) -->
                <fieldset>
                    <legend class="sr-only">Score for <?= h((string)$q['criterion_name']) ?></legend>
                    <div class="space-y-1.5">
                        <?php foreach ([4, 3, 2, 1] as $score_val): ?>
                        <label class="flex items-start gap-3 p-2.5 rounded-lg border border-transparent cursor-pointer hover:bg-white hover:border-indigo-200 transition">
                            <input type="radio"
                                   name="score_<?= $qid ?>"
                                   value="<?= $score_val ?>"
                                   class="mt-0.5 accent-indigo-600"
                                   required
                                   <?= ($prior_score === $score_val) ? 'checked' : '' ?>>
                            <span class="text-sm text-gray-700">
                                <span class="font-bold text-gray-800"><?= $score_val ?>.</span>
                                <?= h((string)($q['score_' . $score_val . '_label'] ?? '')) ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <!-- Comment for rating question -->
                <div class="mt-4">
                    <label for="comment_<?= $qid ?>" class="block text-xs font-bold text-gray-600 mb-1">
                        <?= h($cprompt ?? 'Additional Comments') ?>
                        <?php if ($required): ?>
                            <span class="text-red-500">*</span>
                        <?php else: ?>
                            <span class="text-gray-400 font-normal">(optional)</span>
                        <?php endif; ?>
                    </label>
                    <textarea
                        id="comment_<?= $qid ?>"
                        name="comment_<?= $qid ?>"
                        rows="2"
                        <?= $required ? 'required' : '' ?>
                        placeholder="<?= $required
                            ? h($cprompt ?? 'Comment is required for this criterion.')
                            : h('Optional — add any relevant notes.') ?>"
                        class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 resize-y"><?= h($prior_comment) ?></textarea>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
    <?php
}

/**
 * Renders (and, on POST, processes) the public no-login evaluation form.
 *
 * @param PDO         $pdo             Active DB connection.
 * @param string|null $forced_set_code When set (Form 2), the question set is
 *                                     always this exact set_code — the L3/L2/
 *                                     Default chain and any manual override
 *                                     are bypassed entirely — and the PO must
 *                                     additionally pass ve2_form2_po_eligibility()
 *                                     before the form is shown.
 */
function ve2_render_public_eval_form(PDO $pdo, ?string $forced_set_code = null): void
{
    $worklist_page = 'public_eval_v2_worklist.php';

    // ── Input: resolve po, then requestor from token (preferred) or req fallback ─
    $raw_po    = trim((string)($_GET['po']    ?? ''));
    $raw_token = trim((string)($_GET['token'] ?? ''));
    $raw_req   = trim((string)($_GET['req']   ?? ''));

    if ($raw_po === '' || strlen($raw_po) > VE2_MAX_PARAM_LEN) {
        ve2_block_page(
            'Invalid Evaluation Link',
            'This evaluation link is missing required parameters or contains invalid data. '
            . 'Please use the link provided to you and try again.',
            'bg-red-600'
        );
    }

    $po_number    = $raw_po;
    $requestor_in = '';
    $active_token = '';

    if ($raw_token !== '') {
        // Token path: validate hex format, then lookup requestors table.
        if (strlen($raw_token) > VE2_MAX_PARAM_LEN || !preg_match('/^[0-9a-f]{32,128}$/i', $raw_token)) {
            ve2_block_page(
                'Invalid Evaluation Link',
                'This evaluation link contains an invalid access token. '
                . 'Please use the link provided to you.',
                'bg-red-600'
            );
        }
        $tok_stmt = $pdo->prepare(
            "SELECT requestor_name FROM requestors WHERE public_token = ? LIMIT 1"
        );
        $tok_stmt->execute([$raw_token]);
        $tok_row = $tok_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$tok_row) {
            ve2_block_page(
                'Invalid Evaluation Link',
                'This evaluation link is not recognised or has expired. '
                . 'Please use the link provided to you.',
                'bg-red-600'
            );
        }
        $requestor_in = (string)$tok_row['requestor_name'];
        $active_token = $raw_token;

    } elseif ($raw_req !== '') {
        // req fallback (local/debug only).
        if (strlen($raw_req) > VE2_MAX_PARAM_LEN) {
            ve2_block_page(
                'Invalid Evaluation Link',
                'This evaluation link is missing required parameters or contains invalid data. '
                . 'Please use the link provided to you and try again.',
                'bg-red-600'
            );
        }
        $requestor_in = $raw_req;

    } else {
        ve2_block_page(
            'Invalid Evaluation Link',
            'This evaluation link is missing required parameters or contains invalid data. '
            . 'Please use the link provided to you and try again.',
            'bg-red-600'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // POST HANDLER — executed before any GET rendering; always exits.
    // ══════════════════════════════════════════════════════════════════════════
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {

        // P1 — CSRF
        if (!csrfCheck(trim((string)($_POST['_csrf_v2'] ?? '')))) {
            ve2_block_page(
                'Session Expired',
                'Your session has expired or the form was submitted from an invalid context. '
                . 'Please reload the evaluation link and try again.',
                'bg-yellow-600'
            );
        }

        // P2 — Re-load PO (never trust hidden fields for routing logic)
        $stmt = $pdo->prepare(
            "SELECT * FROM vendor_eval_po_taxonomy WHERE po_number = ? LIMIT 1"
        );
        $stmt->execute([$po_number]);
        $po = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$po) {
            ve2_block_page(
                'PO Not Recognised',
                'The purchase order referenced in this link was not found. '
                . 'Please contact the procurement team.',
                'bg-red-600'
            );
        }

        // P2b — Form 2 eligibility gate (re-checked on POST, never trust the GET check alone)
        if ($forced_set_code !== null) {
            $elig_post = ve2_form2_po_eligibility($pdo, $po_number);
            if (!$elig_post['eligible']) {
                [$et, $em, $eb] = ve2_form2_eligibility_message($elig_post['reason']);
                ve2_block_page($et, $em, $eb);
            }
        }

        // Check if there is an override (which bypasses unmapped/needs_review status)
        $override_set = ve2_override_set($pdo, $po_number);

        if (!$override_set && $po['status'] !== 'mapped') {
            ve2_block_page(
                'Evaluation Not Available',
                'This PO is not currently available for evaluation. '
                . 'Please contact the procurement team.',
                'bg-orange-500',
                $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
            );
        }

        // P3 — Re-validate requestor
        $allowed_post = json_decode((string)($po['requestor_names_json'] ?? '[]'), true);
        if (!is_array($allowed_post)
        ||  !in_array(trim($requestor_in), array_map('trim', $allowed_post), true)) {
            ve2_block_page(
                'Not Authorised',
                'You are not authorised to evaluate this purchase order.',
                'bg-red-600'
            );
        }

        // P4 — Re-check duplicate
        $dup = $pdo->prepare(
            "SELECT id FROM vendor_eval_submissions
              WHERE po_number = ? AND requestor_name = ? AND status = 'submitted'
              LIMIT 1"
        );
        $dup->execute([$po_number, $requestor_in]);
        if ($dup->fetch()) {
            ve2_block_page(
                'Already Submitted',
                "Thank you, {$requestor_in}. Your evaluation for PO {$po_number} has already been recorded.",
                'bg-green-600',
                $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
            );
        }

        $l2_post  = trim((string)($po['taxonomy_level_2'] ?? ''));
        $l3_post  = trim((string)($po['taxonomy_level_3'] ?? ''));
        $pg_post  = trim((string)($po['p_group'] ?? ''));
        $set_post = $forced_set_code !== null
                 ?  ve2_load_forced_set($pdo, $forced_set_code)
                 :  ($override_set ?? resolveQuestionSet($pdo, $l2_post, $l3_post !== '' ? $l3_post : null, $pg_post));
        if (!$set_post) {
            ve2_block_page(
                'Form Not Configured',
                'An evaluation form has not been configured for this purchase order category. '
                . 'Please contact the procurement team.',
                'bg-gray-600'
            );
        }

        // P6 — Reload active questions
        $qst = $pdo->prepare(
            "SELECT * FROM vendor_eval_questions
              WHERE question_set_id = ? AND is_active = 1
              ORDER BY (question_section = 'identification') DESC, question_no"
        );
        $qst->execute([$set_post['id']]);
        $questions_post = $qst->fetchAll(PDO::FETCH_ASSOC);
        if (empty($questions_post)) {
            ve2_block_page(
                'Form Not Configured',
                'The evaluation form for this purchase order has no active questions. '
                . 'Please contact the procurement team.',
                'bg-gray-600'
            );
        }

        // P7 — Validate scores and comments; build answer rows
        $errors      = [];
        $answer_rows = [];
        $total_score = 0.0;

        foreach ($questions_post as $q) {
            $qid         = (int)$q['id'];
            $qtype       = ($q['question_type'] ?? 'rating');
            $section     = ($q['question_section'] ?? 'evaluation');
            $comment     = trim((string)($_POST['comment_' . $qid] ?? ''));
            $req_comment = (int)$q['comment_required'] === 1;
            $weight      = (float)$q['weight'];

            if ($section === 'identification') {
                // Identification field: free input, no score, no weight contribution.
                // Stored in vendor_eval_answers with score = NULL; the typed value goes
                // into selected_score_label (<=255) and, in full, into comment.
                $ident_val = trim((string)($_POST['ident_' . $qid] ?? ''));
                if ($req_comment && $ident_val === '') {
                    $errors[] = 'Please fill in: ' . $q['criterion_name'];
                }
                $answer_rows[] = [
                    'question_id'          => $qid,
                    'question_no'          => (int)$q['question_no'],
                    'criterion_name'       => (string)$q['criterion_name'],
                    'question_text'        => (string)$q['question_text'],
                    'question_type'        => 'identification',
                    'score'                => null,
                    'selected_score_label' => mb_substr($ident_val, 0, 255),
                    'weight'               => '0.0000',
                    'weighted_score'       => '0.0000',
                    'comment'              => $ident_val !== '' ? $ident_val : null,
                ];
                continue;
            }

            if ($qtype === 'text') {
                // Text-type (conclusion): no score, comment is the answer.
                if ($req_comment && $comment === '') {
                    $errors[] = 'A response is required for: ' . $q['criterion_name'];
                }
                $answer_rows[] = [
                    'question_id'          => $qid,
                    'question_no'          => (int)$q['question_no'],
                    'criterion_name'       => (string)$q['criterion_name'],
                    'question_text'        => (string)$q['question_text'],
                    'question_type'        => 'text',
                    'score'                => null,
                    'selected_score_label' => '',
                    'weight'               => '0.0000',
                    'weighted_score'       => '0.0000',
                    'comment'              => $comment !== '' ? $comment : null,
                ];
            } else {
                // Rating-type: validate score 1–4, compute weighted contribution.
                $score_raw = trim((string)($_POST['score_' . $qid] ?? ''));
                if (!in_array($score_raw, ['1', '2', '3', '4'], true)) {
                    $errors[] = 'Please select a score for: ' . $q['criterion_name'];
                    continue;
                }
                $score = (int)$score_raw;
                if ($req_comment && $comment === '') {
                    $errors[] = 'A comment is required for: ' . $q['criterion_name'];
                }
                $weighted_score       = round($score * $weight, 4);
                $total_score         += $weighted_score;
                $label_key            = 'score_' . $score . '_label';
                $selected_score_label = (string)($q[$label_key] ?? (string)$score);

                $answer_rows[] = [
                    'question_id'          => $qid,
                    'question_no'          => (int)$q['question_no'],
                    'criterion_name'       => (string)$q['criterion_name'],
                    'question_text'        => (string)$q['question_text'],
                    'question_type'        => 'rating',
                    'score'                => $score,
                    'selected_score_label' => $selected_score_label,
                    'weight'               => number_format($weight, 4, '.', ''),
                    'weighted_score'       => number_format($weighted_score, 4, '.', ''),
                    'comment'              => $comment !== '' ? $comment : null,
                ];
            }
        }

        if (!empty($errors)) {
            ve2_block_page(
                'Incomplete Submission',
                'Please complete all required fields before submitting: '
                . implode('; ', $errors)
                . '. Use your browser\'s back button to return to the form.',
                'bg-yellow-600'
            );
        }

        $total_score = round($total_score, 4);
        $score_band  = computeScoreBand($total_score);
        $now         = date('Y-m-d H:i:s');

        // P8 — Write in transaction
        try {
            $pdo->beginTransaction();

            $ins_sub = $pdo->prepare("
                INSERT INTO vendor_eval_submissions
                    (po_number, vendor_name, requestor_name, question_set_id,
                     taxonomy_level_2, taxonomy_level_3,
                     total_score, score_band, status, submitted_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'submitted', ?, ?)
            ");
            $ins_sub->execute([
                $po_number,
                (string)($po['vendor_name'] ?? ''),
                $requestor_in,
                $set_post['id'],
                ($l2_post !== '' ? $l2_post : null),
                ($l3_post !== '' ? $l3_post : null),
                number_format($total_score, 4, '.', ''),
                $score_band,
                $now,
                $now,
            ]);
            $submission_id = (int)$pdo->lastInsertId();

            $ins_ans = $pdo->prepare("
                INSERT INTO vendor_eval_answers
                    (submission_id, question_id, question_no, criterion_name, question_text,
                     score, selected_score_label, weight, weighted_score, comment)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            foreach ($answer_rows as $ar) {
                $ins_ans->execute([
                    $submission_id,
                    $ar['question_id'],
                    $ar['question_no'],
                    $ar['criterion_name'],
                    $ar['question_text'],
                    $ar['score'],
                    $ar['selected_score_label'],
                    $ar['weight'],
                    $ar['weighted_score'],
                    $ar['comment'],
                ]);
            }

            $pdo->commit();

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // SQLSTATE 23xxx = integrity constraint (duplicate key)
            if (strpos((string)$e->getCode(), '23') === 0) {
                ve2_block_page(
                    'Already Submitted',
                    "Your evaluation for PO {$po_number} has already been recorded. Thank you.",
                    'bg-green-600',
                    $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
                );
            }
            ve2_block_page(
                'Submission Error',
                'A system error occurred while saving your evaluation. '
                . 'Please try again or contact the procurement team.',
                'bg-red-600'
            );
        }

        // P9 — Thank-you page
        $band_label = ['A' => 'Excellent', 'B' => 'Good', 'C' => 'Acceptable', 'D' => 'Poor'];
        $band_bar   = [
            'A' => 'bg-green-600',
            'B' => 'bg-blue-600',
            'C' => 'bg-yellow-500',
            'D' => 'bg-red-600',
        ];
        $band_text  = [
            'A' => 'text-green-700 bg-green-100',
            'B' => 'text-blue-700 bg-blue-100',
            'C' => 'text-yellow-700 bg-yellow-100',
            'D' => 'text-red-700 bg-red-100',
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
            <h2 class="text-2xl font-bold text-white">Thank You, <?= h($requestor_in) ?>!</h2>
            <p class="text-green-100 mt-1">Your evaluation for PO <?= h($po_number) ?> has been successfully recorded.</p>
        </div>
        <div class="p-6">
            <div class="flex flex-wrap items-center gap-6 mb-6 pb-6 border-b border-gray-100">
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">PO Evaluation Score</p>
                    <p class="text-3xl font-black text-gray-800">
                        <?= h(number_format($total_score, 1)) ?>
                        <span class="text-sm font-normal text-gray-500">/ 4.0</span>
                    </p>
                </div>
                <div>
                    <p class="text-xs font-bold text-gray-500 uppercase tracking-wider">PO Evaluation Grade</p>
                    <span class="inline-block text-xl font-black px-3 py-1 rounded <?= h($band_text[$score_band] ?? 'text-gray-700 bg-gray-100') ?>">
                        <?= h($score_band) ?> &mdash; <?= h($band_label[$score_band] ?? '') ?>
                    </span>
                </div>
            </div>
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
                            <?= $ar['comment'] !== null
                                ? h(mb_strimwidth($ar['comment'], 0, 100, '…'))
                                : '(no response)' ?>
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
    <div class="bg-white rounded-lg shadow px-6 py-4 text-xs text-gray-500 border-l-4 border-indigo-300">
        Note: Evaluation scores are recorded per PO/category, so the same vendor may have different scores for different item categories.
    </div>
    <?php if ($active_token !== ''): ?>
    <div class="text-center">
        <a href="<?= h($worklist_page) ?>?token=<?= h(urlencode($active_token)) ?>"
           class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2 px-6 rounded-lg shadow transition">
            &larr; Back to Evaluation List
        </a>
    </div>
    <?php endif; ?>
    <p class="text-center text-xs text-gray-400">Vendor Evaluation v2 &middot; <?= h(date('d M Y')) ?></p>
</div>
</body>
</html><?php
        exit;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // GET FLOW — validate, load, render form.
    // ══════════════════════════════════════════════════════════════════════════

    // G1 — PO lookup
    $stmt = $pdo->prepare(
        "SELECT * FROM vendor_eval_po_taxonomy WHERE po_number = ? LIMIT 1"
    );
    $stmt->execute([$po_number]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        ve2_block_page(
            'PO Not Recognised',
            'The purchase order referenced in this link was not found. '
            . 'Please check the link or contact the procurement team.',
            'bg-red-600'
        );
    }

    // G1b — Form 2 eligibility gate
    if ($forced_set_code !== null) {
        $elig = ve2_form2_po_eligibility($pdo, $po_number);
        if (!$elig['eligible']) {
            [$et, $em, $eb] = ve2_form2_eligibility_message($elig['reason']);
            ve2_block_page(
                $et,
                $em,
                $eb,
                $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
            );
        }
    }

    $override_set_get = ve2_override_set($pdo, $po_number);

    if (!$override_set_get && $po['status'] === 'needs_review') {
        ve2_block_page(
            'Evaluation Not Available Yet',
            'This PO is currently under taxonomy review and cannot be evaluated yet. '
            . 'Please try again later or contact the procurement team.',
            'bg-orange-500',
            $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
        );
    }

    if (!$override_set_get && $po['status'] !== 'mapped') {
        ve2_block_page(
            'Evaluation Not Available',
            'This PO is not currently available for evaluation. '
            . 'Please contact the procurement team.',
            'bg-gray-600',
            $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
        );
    }

    // G2 — Requestor validation
    $allowed_raw  = (string)($po['requestor_names_json'] ?? '[]');
    $allowed      = json_decode($allowed_raw, true);

    if (!is_array($allowed)) {
        ve2_block_page(
            'Configuration Error',
            'Unable to validate your access for this PO. '
            . 'Please contact the procurement team.',
            'bg-red-600'
        );
    }

    if (!in_array(trim($requestor_in), array_map('trim', $allowed), true)) {
        ve2_block_page(
            'Not Authorised',
            'You are not authorised to evaluate this purchase order. '
            . 'If you believe this is an error, please contact the procurement team.',
            'bg-red-600'
        );
    }

    // G3 — Duplicate submission check
    $dup = $pdo->prepare(
        "SELECT id, submitted_at FROM vendor_eval_submissions
          WHERE po_number = ? AND requestor_name = ? AND status = 'submitted'
          LIMIT 1"
    );
    $dup->execute([$po_number, $requestor_in]);
    $existing_sub = $dup->fetch(PDO::FETCH_ASSOC);

    if ($existing_sub) {
        $sub_fmt = date('d M Y, H:i', strtotime((string)$existing_sub['submitted_at']));
        ve2_block_page(
            'Already Submitted',
            "Thank you, {$requestor_in}. You already submitted an evaluation for PO {$po_number} on {$sub_fmt}.",
            'bg-green-600',
            $active_token !== '' ? $worklist_page . '?token=' . urlencode($active_token) : ''
        );
    }

    // G4 — Question-set routing (forced set for Form 2; else manual override
    // wins, then existing L3→L2→Default chain)
    $l2  = trim((string)($po['taxonomy_level_2'] ?? ''));
    $l3  = trim((string)($po['taxonomy_level_3'] ?? ''));
    $pg  = trim((string)($po['p_group'] ?? ''));
    $set = $forced_set_code !== null
        ?  ve2_load_forced_set($pdo, $forced_set_code)
        :  ($override_set_get ?? resolveQuestionSet($pdo, $l2, $l3 !== '' ? $l3 : null, $pg));

    if (!$set) {
        ve2_block_page(
            'Form Not Configured',
            'An evaluation form has not been configured for this purchase order category. '
            . 'Please contact the procurement team.',
            'bg-gray-600'
        );
    }

    // G5 — Load active questions
    $qstmt = $pdo->prepare(
        "SELECT * FROM vendor_eval_questions
          WHERE question_set_id = ? AND is_active = 1
          ORDER BY (question_section = 'identification') DESC, question_no"
    );
    $qstmt->execute([$set['id']]);
    $questions = $qstmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($questions)) {
        ve2_block_page(
            'Form Not Configured',
            'The evaluation form for this purchase order category has no active questions. '
            . 'Please contact the procurement team.',
            'bg-gray-600'
        );
    }

    // Split into the Identification section (rendered first, no score) and the
    // Evaluation section (rating/text questions that carry the score).
    $ident_questions = [];
    $eval_questions  = [];
    foreach ($questions as $q) {
        if (($q['question_section'] ?? 'evaluation') === 'identification') {
            $ident_questions[] = $q;
        } else {
            $eval_questions[] = $q;
        }
    }

    // G6 — CSRF token and display data
    $csrf_token   = csrfToken();
    $vendor_name  = !empty($po['vendor_name']) ? (string)$po['vendor_name'] : '(vendor not specified)';
    $form_action  = '?po=' . urlencode($po_number)
                  . ($active_token !== ''
                      ? '&token=' . urlencode($active_token)
                      : '&req='   . urlencode($requestor_in));

    $route_labels = [
        'manual'  => 'Manual Assignment',
        'l3'      => 'Commodity Level (L3)',
        'l2'      => 'Sub Category Level (L2)',
        'default' => 'Default Form',
        'forced'  => 'Project Retail — New Store',
    ];
    $route_label = $route_labels[$set['map_level']] ?? $set['map_level'];

    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vendor Evaluation — <?= h($po_number) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 py-10 px-4">
<div class="max-w-3xl mx-auto space-y-4">

    <!-- Main card -->
    <div class="bg-white rounded-lg shadow-xl overflow-hidden">

        <!-- Header bar -->
        <div class="bg-indigo-700 px-6 py-5">
            <h2 class="text-2xl font-bold text-white">Vendor Performance Evaluation</h2>
            <p class="text-indigo-200 mt-1">Please rate the vendor's performance for the purchase order below.</p>
        </div>

        <!-- PO context -->
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
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Requestor</span>
                    <span class="text-gray-700"><?= h($requestor_in) ?></span>
                </div>
                <div>
                    <span class="block text-xs font-bold text-gray-500 uppercase tracking-wider mb-1">Category</span>
                    <span class="text-gray-700">
                        <?= h($l2) ?>
                        <?php if ($l3 !== ''): ?>
                            <span class="text-gray-400 mx-1">&rsaquo;</span><?= h($l3) ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>

            <!-- Question set info -->
            <div class="mt-4 pt-4 border-t border-gray-200 flex flex-wrap gap-2 items-center">
                <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Form:</span>
                <span class="text-xs font-mono bg-indigo-50 text-indigo-700 border border-indigo-200 rounded px-2 py-0.5"><?= h($set['set_code']) ?></span>
                <span class="text-xs text-gray-700"><?= h($set['set_name']) ?></span>
                <span class="text-xs bg-gray-100 text-gray-600 border border-gray-200 rounded px-2 py-0.5">Route: <?= h($route_label) ?></span>
            </div>
        </div>

        <!-- Evaluation form -->
        <form method="POST" action="<?= h($form_action) ?>" class="p-6">
            <input type="hidden" name="_csrf_v2" value="<?= h($csrf_token) ?>">

            <?php if (!empty($ident_questions)): ?>
            <!-- Identification section (rendered first; no score) -->
            <div class="mb-8">
                <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Your Details</h3>
                <div class="space-y-4">
                    <?php foreach ($ident_questions as $q): ve2_render_ident_field($q); endforeach; ?>
                </div>
            </div>
            <?php if (!empty($eval_questions)): ?>
            <h3 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-3 pb-2 border-b border-gray-200">Vendor Performance</h3>
            <?php endif; ?>
            <?php endif; ?>

            <div class="space-y-6">
                <?php foreach ($eval_questions as $q): ve2_render_eval_question_card($q); endforeach; ?>
            </div>

            <!-- Submit -->
            <div class="mt-8">
                <p class="text-xs text-gray-500 mb-3">
                    By submitting this form you confirm that your rating reflects your direct experience
                    with the vendor for PO <?= h($po_number) ?>.
                </p>
                <button type="submit"
                        class="w-full bg-indigo-600 hover:bg-indigo-700 active:bg-indigo-800 text-white font-bold py-3 px-4 rounded-lg shadow transition text-lg">
                    Submit Evaluation
                </button>
            </div>
        </form>
    </div>

    <p class="text-center text-xs text-gray-400">Vendor Evaluation v2 &middot; <?= h(date('d M Y')) ?></p>
</div>
</body>
</html><?php
}
