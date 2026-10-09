<?php
// workspace/pages/master_vendor_evaluation_set.php
//
// Vendor Evaluation v2 — Category-Manager-only question-set DETAIL page.
// Reached from master_vendor_evaluation.php (the list page) via
// ?set_id=<id>. Everything about ONE question set lives here:
//   • set metadata editor (name / description)
//   • taxonomy mapping editor (L2 / L3 routing)
//   • data health panel (weight total, taxonomy routing, Kesimpulan warnings)
//   • question list + add/edit/deactivate/reorder
//   • configurable Identification fields (question_section = 'identification')
//   • live preview (Identification section first, then Evaluation)
//
// Read tables : vendor_eval_question_sets, vendor_eval_questions,
//               vendor_eval_question_set_taxonomy_map, v_item_taxonomy
// Write tables: vendor_eval_question_sets, vendor_eval_questions,
//               vendor_eval_question_set_taxonomy_map
//
// Safety invariants:
//   - Category Manager only (require_category_manager() from auth.php).
//   - Never reads or writes vendor_evaluations (v1) or v1 pages.
//   - No WA / email / Gemini / external API calls.
//   - All DB writes use prepared statements.
//   - Prefer is_active = 0 over hard delete; no destructive DELETEs here.
//   - Never inserts/updates the users table.

require_once '../includes/auth.php';
require_category_manager();
require_once '../config/database.php';

const SENTINEL_ANY = '**ANY**';

// App-level allowed values (DB stores plain VARCHARs; validation lives here).
const IDENT_INPUT_TYPES = ['text', 'email', 'date', 'textarea'];

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a 0-1 weight fraction as a percent string without trailing zeros (0.125 -> "12.5", 0.20 -> "20"). */
function fmt_pct($fraction): string {
    $pct = number_format((float)$fraction * 100, 2, '.', '');
    return rtrim(rtrim($pct, '0'), '.');
}

/** Load lookup sets of valid L2 and 'l2||l3' composite keys from v_item_taxonomy (tamper validation). */
function loadTaxonomyValidationSets(PDO $pdo): array {
    $rows = $pdo->query("SELECT DISTINCT level_2, level_3 FROM v_item_taxonomy")->fetchAll(PDO::FETCH_ASSOC);
    $l2 = [];
    $l3 = [];
    foreach ($rows as $r) {
        $a = trim((string)($r['level_2'] ?? ''));
        $b = trim((string)($r['level_3'] ?? ''));
        if ($a === '') continue;
        $l2[$a] = true;
        if ($b !== '') $l3[$a . '||' . $b] = true;
    }
    return [$l2, $l3];
}

/**
 * Build the desired set of taxonomy-map rows from multi-select form input.
 * Returns a list of ['map_level','l2','l3'] tuples, de-duplicated. Routing
 * semantics are unchanged from the old single-mapping model — only the
 * cardinality changed from 1 → N:
 *   - "default" is exclusive: a fallback set matches every taxonomy, so specific
 *     L2/L3 selections are ignored when default is checked (an ANY-L2 mapping
 *     always collapsed to 'default' in the previous single-mapping model too).
 *   - each selected L2 → ('l2', <l2>, ANY)   — matches every commodity under it.
 *   - each selected L3 → ('l3', <l2>, <l3>)  — exact commodity; the option value
 *     is a JSON {l2,l3} pair so the parent Sub Category travels with it.
 * $valid_l2 / $valid_l3 are the known-taxonomy sets from loadTaxonomyValidationSets().
 */
function buildDesiredTaxonomyMaps(array $l2_sel, array $l3_sel, bool $is_default, array $valid_l2, array $valid_l3): array {
    if ($is_default) {
        return [['map_level' => 'default', 'l2' => SENTINEL_ANY, 'l3' => SENTINEL_ANY]];
    }
    $out  = [];
    $seen = [];
    foreach ($l2_sel as $l2) {
        $l2 = trim((string)$l2);
        if ($l2 === '' || $l2 === SENTINEL_ANY) continue;
        if (!isset($valid_l2[$l2])) throw new RuntimeException('Unknown Sub Category (Level 2): "' . $l2 . '".');
        $k = 'l2|' . $l2 . '|' . SENTINEL_ANY;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = ['map_level' => 'l2', 'l2' => $l2, 'l3' => SENTINEL_ANY];
    }
    foreach ($l3_sel as $raw) {
        $raw = trim((string)$raw);
        if ($raw === '') continue;
        $pair = json_decode($raw, true);
        if (!is_array($pair) || !isset($pair['l2'], $pair['l3'])) {
            throw new RuntimeException('Malformed Commodity (Level 3) selection.');
        }
        $l2 = trim((string)$pair['l2']);
        $l3 = trim((string)$pair['l3']);
        if ($l2 === '' || $l3 === '' || $l3 === SENTINEL_ANY) continue;
        if (!isset($valid_l3[$l2 . '||' . $l3])) {
            throw new RuntimeException('Unknown Commodity (Level 3): "' . $l2 . ' › ' . $l3 . '".');
        }
        $k = 'l3|' . $l2 . '|' . $l3;
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $out[] = ['map_level' => 'l3', 'l2' => $l2, 'l3' => $l3];
    }
    return $out;
}

/**
 * Reconcile a question set's taxonomy-map rows to exactly $desired. Inserts
 * newly-added mappings, deletes removed ones, and keeps is_active in step with
 * the set. The caller owns the surrounding transaction and must run the friendly
 * duplicate-taxonomy pre-check (assertTaxonomyMapFree) for each desired mapping.
 *
 * Removed mappings are hard-DELETEd (not soft-deactivated). A taxonomy-map row is
 * pure routing config: existing submissions snapshot their own question_set_id +
 * taxonomy_level_2/3, so deleting a map row never rewrites historical evaluations.
 * Soft-deactivating instead would leave the (map_level,L2,L3) key permanently
 * reserved under uq_map_l2_l3 and block re-assigning that taxonomy to another set,
 * defeating "removing an assignment should work". Whole-set deactivate/reactivate
 * (on the list page) still toggles is_active and is unaffected.
 */
function syncTaxonomyMaps(PDO $pdo, int $set_id, array $desired, int $is_active): void {
    $stmt = $pdo->prepare("SELECT id, map_level, taxonomy_level_2, taxonomy_level_3, is_active
                             FROM vendor_eval_question_set_taxonomy_map WHERE question_set_id = ?");
    $stmt->execute([$set_id]);
    $existing_by_key = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing_by_key[$r['map_level'] . '|' . $r['taxonomy_level_2'] . '|' . $r['taxonomy_level_3']] = $r;
    }
    $desired_by_key = [];
    foreach ($desired as $d) {
        $desired_by_key[$d['map_level'] . '|' . $d['l2'] . '|' . $d['l3']] = $d;
    }

    $del = $pdo->prepare("DELETE FROM vendor_eval_question_set_taxonomy_map WHERE id = ?");
    foreach ($existing_by_key as $key => $r) {
        if (!isset($desired_by_key[$key])) $del->execute([$r['id']]);
    }
    $ins = $pdo->prepare("INSERT INTO vendor_eval_question_set_taxonomy_map
                               (question_set_id, map_level, taxonomy_level_2, taxonomy_level_3, is_active, created_at)
                           VALUES (?, ?, ?, ?, ?, NOW())");
    $upd = $pdo->prepare("UPDATE vendor_eval_question_set_taxonomy_map SET is_active = ? WHERE id = ?");
    foreach ($desired_by_key as $key => $d) {
        if (isset($existing_by_key[$key])) {
            if ((int)$existing_by_key[$key]['is_active'] !== $is_active) {
                $upd->execute([$is_active, $existing_by_key[$key]['id']]);
            }
        } else {
            $ins->execute([$set_id, $d['map_level'], $d['l2'], $d['l3'], $is_active]);
        }
    }
}

/** Human summary of a desired mapping set, for the save flash message. */
function summarizeTaxonomy(array $desired): string {
    if (empty($desired)) return 'No taxonomy mapping — this set will not be routed to any PO';
    foreach ($desired as $d) {
        if ($d['map_level'] === 'default') return 'Taxonomy mapping: Default fallback set (matches any taxonomy)';
    }
    $n_l2 = 0;
    $n_l3 = 0;
    foreach ($desired as $d) {
        if ($d['map_level'] === 'l2') $n_l2++;
        elseif ($d['map_level'] === 'l3') $n_l3++;
    }
    $parts = [];
    if ($n_l2) $parts[] = $n_l2 . ' Sub Category (L2)';
    if ($n_l3) $parts[] = $n_l3 . ' Commodity (L3)';
    return 'Taxonomy mapping: ' . implode(' + ', $parts);
}

/**
 * A (map_level, L2, L3) combination may be owned by at most ONE question set.
 * Friendly pre-check BEFORE any write (see master_vendor_evaluation.php for the
 * full rationale). $self_set_id excludes this set's own row.
 */
function assertTaxonomyMapFree(PDO $pdo, string $map_level, string $l2, string $l3, int $self_set_id, ?int &$conflict_id = null): void {
    $stmt = $pdo->prepare("
        SELECT qs.id, qs.set_name, qs.is_active
          FROM vendor_eval_question_set_taxonomy_map m
          JOIN vendor_eval_question_sets qs ON qs.id = m.question_set_id
         WHERE m.map_level = ? AND m.taxonomy_level_2 = ? AND m.taxonomy_level_3 = ?
           AND m.question_set_id <> ?
         LIMIT 1");
    $stmt->execute([$map_level, $l2, $l3, $self_set_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $conflict_id = (int)$row['id'];
        if ((int)$row['is_active'] === 1) {
            throw new RuntimeException(
                'This taxonomy is already mapped to "' . $row['set_name'] . '". '
              . 'One taxonomy can only be mapped to one active question set. '
              . 'Choose another taxonomy or edit the existing mapped set.'
            );
        }
        throw new RuntimeException(
            'This taxonomy is already reserved by the inactive question set "' . $row['set_name'] . '". '
          . 'Reactivate/edit that set to use this taxonomy, or choose another taxonomy.'
        );
    }
}

// The set this page operates on. Resolved from GET on render, POST on write.
$set_id = isset($_GET['set_id']) ? (int)$_GET['set_id'] : 0;

// ══════════════════════════════════════════════════════════════════════════
// POST handlers — processed BEFORE any HTML output so the redirect works.
// Every write here redirects back to THIS detail page for the same set.
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $set_id     = (int)($_POST['set_id'] ?? $set_id);
    $flash      = null;
    $flash_type = 'success';
    $flash_conflict_set_id = null;

    try {
        if ($set_id <= 0) throw new RuntimeException('Missing set_id.');

        switch ($action) {

            // ── Question set metadata + taxonomy update (many L2 / many L3) ──
            case 'update_question_set': {
                $name   = trim((string)($_POST['question_set_name'] ?? ''));
                $desc   = trim((string)($_POST['description'] ?? ''));
                $l2_sel = (isset($_POST['taxonomy_l2']) && is_array($_POST['taxonomy_l2'])) ? $_POST['taxonomy_l2'] : [];
                $l3_sel = (isset($_POST['taxonomy_l3']) && is_array($_POST['taxonomy_l3'])) ? $_POST['taxonomy_l3'] : [];
                $is_def = !empty($_POST['taxonomy_is_default']);

                if ($name === '') throw new RuntimeException('Question set name is required.');

                // Build + validate the desired mapping set against the known taxonomy.
                [$valid_l2, $valid_l3] = loadTaxonomyValidationSets($pdo);
                $desired = buildDesiredTaxonomyMaps($l2_sel, $l3_sel, $is_def, $valid_l2, $valid_l3);

                // Preserve current active state (no Active checkbox on this form).
                $curStmt = $pdo->prepare("SELECT is_active FROM vendor_eval_question_sets WHERE id = ?");
                $curStmt->execute([$set_id]);
                $act = (int)$curStmt->fetchColumn();

                // Friendly pre-check BEFORE any write for EACH mapping; exclude this set's own rows.
                foreach ($desired as $d) {
                    assertTaxonomyMapFree($pdo, $d['map_level'], $d['l2'], $d['l3'], $set_id, $flash_conflict_set_id);
                }

                $pdo->beginTransaction();
                try {
                    $upd = $pdo->prepare("UPDATE vendor_eval_question_sets
                                             SET set_name = ?, is_active = ?, description = ?, updated_at = NOW()
                                           WHERE id = ?");
                    $upd->execute([$name, $act, ($desc !== '' ? $desc : null), $set_id]);

                    syncTaxonomyMaps($pdo, $set_id, $desired, $act);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                $flash = 'Question set updated. ' . summarizeTaxonomy($desired) . '.';
                break;
            }

            // ── Question create/update (rating / text / identification) ─────
            case 'create_question':
            case 'update_question': {
                $qno       = (int)($_POST['question_no'] ?? 0);
                $section   = ($_POST['question_section'] ?? 'evaluation') === 'identification' ? 'identification' : 'evaluation';
                $criterion = trim((string)($_POST['criterion_name'] ?? ''));
                $qtext     = trim((string)($_POST['question_text'] ?? ''));
                $creq      = isset($_POST['comment_required']) ? 1 : 0;
                $cprompt   = trim((string)($_POST['comment_prompt'] ?? ''));
                $helptext  = trim((string)($_POST['question_help_text'] ?? ''));
                $imgpath   = trim((string)($_POST['question_image_path'] ?? ''));

                if ($section === 'identification') {
                    // Identification field: non-scored free input; input_type picks the widget.
                    $qtype      = 'text';
                    $input_type = in_array(($_POST['input_type'] ?? 'text'), IDENT_INPUT_TYPES, true)
                                ? (string)$_POST['input_type'] : 'text';
                    $weight     = 0.0;
                    if ($qtext === '') throw new RuntimeException('Prompt (text shown to the evaluator) is required for an identification field.');
                } else {
                    $qtype      = ($_POST['question_type'] ?? 'rating') === 'text' ? 'text' : 'rating';
                    $input_type = 'text'; // ignored for evaluation questions
                    $weight     = $qtype === 'text' ? 0.0 : (float)($_POST['weight'] ?? 0);
                    if ($qtype === 'text' && $qtext === '') throw new RuntimeException('Question text is required.');
                }

                if ($qno <= 0)         throw new RuntimeException('Question No. must be a positive integer.');
                if ($criterion === '') throw new RuntimeException('Criterion / field label is required.');
                if ($weight < 0)       throw new RuntimeException('Weight must be >= 0.');

                // Rating questions display criterion_name as the title (no separate
                // question_text input) — keep the stored value in sync.
                if ($section === 'evaluation' && $qtype === 'rating') {
                    $qtext = $criterion;
                }

                // Score labels/descriptions only apply to rating questions.
                $labels = [];
                $descs  = [];
                foreach ([1, 2, 3, 4] as $n) {
                    $is_rating = ($section === 'evaluation' && $qtype === 'rating');
                    $labels[$n] = $is_rating ? trim((string)($_POST["score_{$n}_label"] ?? '')) : '';
                    $descs[$n]  = $is_rating ? trim((string)($_POST["score_{$n}_description"] ?? '')) : '';
                    if ($is_rating && $labels[$n] === '') {
                        throw new RuntimeException("Score {$n} label is required for a rating question.");
                    }
                }

                // Uniqueness of question_no within the set (excluding self on update).
                $dupStmt = $pdo->prepare("SELECT id FROM vendor_eval_questions WHERE question_set_id = ? AND question_no = ?" .
                    ($action === 'update_question' ? " AND id <> ?" : ""));
                $dupParams = [$set_id, $qno];
                if ($action === 'update_question') $dupParams[] = (int)($_POST['question_id'] ?? 0);
                $dupStmt->execute($dupParams);
                if ($dupStmt->fetch()) {
                    throw new RuntimeException("Question No. {$qno} already exists in this question set.");
                }

                if ($action === 'create_question') {
                    $act = 1; // new questions are active by default; removal is soft delete only
                    $ins = $pdo->prepare("INSERT INTO vendor_eval_questions
                            (question_set_id, question_no, criterion_name, question_text, weight,
                             score_1_label, score_2_label, score_3_label, score_4_label,
                             comment_required, is_active, created_at, question_type, question_section, input_type,
                             score_1_description, score_2_description, score_3_description, score_4_description,
                             comment_prompt, question_help_text, question_image_path)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([
                        $set_id, $qno, $criterion, $qtext, number_format($weight, 4, '.', ''),
                        $labels[1], $labels[2], $labels[3], $labels[4],
                        $creq, $act, $qtype, $section, $input_type,
                        $descs[1] !== '' ? $descs[1] : null, $descs[2] !== '' ? $descs[2] : null,
                        $descs[3] !== '' ? $descs[3] : null, $descs[4] !== '' ? $descs[4] : null,
                        $cprompt !== '' ? $cprompt : null,
                        $helptext !== '' ? $helptext : null,
                        $imgpath !== '' ? $imgpath : null,
                    ]);
                    $flash = "Question Q{$qno} added.";
                } else {
                    $question_id = (int)($_POST['question_id'] ?? 0);
                    if ($question_id <= 0) throw new RuntimeException('Missing question_id for update.');
                    // Preserve current active state (no Active checkbox on the form).
                    $curq = $pdo->prepare("SELECT is_active FROM vendor_eval_questions WHERE id = ? AND question_set_id = ?");
                    $curq->execute([$question_id, $set_id]);
                    $act = (int)$curq->fetchColumn();
                    $upd = $pdo->prepare("UPDATE vendor_eval_questions SET
                            question_no = ?, criterion_name = ?, question_text = ?, weight = ?,
                            score_1_label = ?, score_2_label = ?, score_3_label = ?, score_4_label = ?,
                            comment_required = ?, is_active = ?, question_type = ?, question_section = ?, input_type = ?,
                            score_1_description = ?, score_2_description = ?, score_3_description = ?, score_4_description = ?,
                            comment_prompt = ?, question_help_text = ?, question_image_path = ?
                        WHERE id = ? AND question_set_id = ?");
                    $upd->execute([
                        $qno, $criterion, $qtext, number_format($weight, 4, '.', ''),
                        $labels[1], $labels[2], $labels[3], $labels[4],
                        $creq, $act, $qtype, $section, $input_type,
                        $descs[1] !== '' ? $descs[1] : null, $descs[2] !== '' ? $descs[2] : null,
                        $descs[3] !== '' ? $descs[3] : null, $descs[4] !== '' ? $descs[4] : null,
                        $cprompt !== '' ? $cprompt : null,
                        $helptext !== '' ? $helptext : null,
                        $imgpath !== '' ? $imgpath : null,
                        $question_id, $set_id,
                    ]);
                    $flash = "Question Q{$qno} updated.";
                }
                break;
            }

            // ── Deactivate question (soft delete only) ──────────────────────
            case 'deactivate_question': {
                $question_id = (int)($_POST['question_id'] ?? 0);
                $upd = $pdo->prepare("UPDATE vendor_eval_questions SET is_active = 0 WHERE id = ? AND question_set_id = ?");
                $upd->execute([$question_id, $set_id]);
                $flash = 'Question deactivated (not deleted).';
                break;
            }

            // ── Reactivate question ─────────────────────────────────────────
            case 'reactivate_question': {
                $question_id = (int)($_POST['question_id'] ?? 0);
                $upd = $pdo->prepare("UPDATE vendor_eval_questions SET is_active = 1 WHERE id = ? AND question_set_id = ?");
                $upd->execute([$question_id, $set_id]);
                $flash = 'Question reactivated.';
                break;
            }

            // ── Reorder (swap question_no with the adjacent question) ────────
            case 'move_question_up':
            case 'move_question_down': {
                $question_id = (int)($_POST['question_id'] ?? 0);

                $cur = $pdo->prepare("SELECT id, question_no FROM vendor_eval_questions WHERE id = ? AND question_set_id = ?");
                $cur->execute([$question_id, $set_id]);
                $curRow = $cur->fetch(PDO::FETCH_ASSOC);

                if ($curRow) {
                    $dirDesc = $action === 'move_question_up' ? 'DESC' : 'ASC';
                    $dirOp   = $action === 'move_question_up' ? '<' : '>';
                    $nbr = $pdo->prepare("SELECT id, question_no FROM vendor_eval_questions
                                            WHERE question_set_id = ? AND question_no {$dirOp} ?
                                            ORDER BY question_no {$dirDesc} LIMIT 1");
                    $nbr->execute([$set_id, $curRow['question_no']]);
                    $nbrRow = $nbr->fetch(PDO::FETCH_ASSOC);

                    if ($nbrRow) {
                        $pdo->beginTransaction();
                        try {
                            // 3-step swap via a temp negative sentinel to avoid the unique-key collision.
                            $pdo->prepare("UPDATE vendor_eval_questions SET question_no = ? WHERE id = ?")
                                ->execute([-$curRow['id'], $curRow['id']]);
                            $pdo->prepare("UPDATE vendor_eval_questions SET question_no = ? WHERE id = ?")
                                ->execute([$curRow['question_no'], $nbrRow['id']]);
                            $pdo->prepare("UPDATE vendor_eval_questions SET question_no = ? WHERE id = ?")
                                ->execute([$nbrRow['question_no'], $curRow['id']]);
                            $pdo->commit();
                            $flash = 'Question order updated.';
                        } catch (\Throwable $e) {
                            $pdo->rollBack();
                            throw $e;
                        }
                    } else {
                        $flash = 'Already at the ' . ($action === 'move_question_up' ? 'top' : 'bottom') . '.';
                    }
                }
                break;
            }
        }
    } catch (RuntimeException $e) {
        $flash_type = 'error';
        $flash      = $e->getMessage();
        if (in_array($action, ['create_question', 'update_question'], true)) {
            $_SESSION['qf_repopulate'] = $_POST;
        }
        // Keep the Set Details & Taxonomy form's typed values on a failed save
        // (e.g. duplicate-taxonomy conflict) instead of silently reverting to DB.
        if ($action === 'update_question_set') {
            $_SESSION['set_form_repopulate'] = $_POST;
        }
    } catch (PDOException $e) {
        $flash_type = 'error';
        if ($action === 'update_question_set') {
            $_SESSION['set_form_repopulate'] = $_POST;
        }
        if (strpos((string)$e->getCode(), '23') === 0) {
            $flash = 'This taxonomy combination (Sub Category / Commodity) is already mapped to another question set. '
                   . 'Choose a different Sub Category/Commodity, or deactivate the conflicting set first.';
        } else {
            $flash = 'A database error occurred while saving. No changes were made outside the failed statement.';
        }
    }

    $_SESSION['flash_message'] = $flash;
    $_SESSION['flash_type']    = $flash_type;
    if ($flash_conflict_set_id !== null) {
        $_SESSION['flash_conflict_set_id'] = $flash_conflict_set_id;
    }

    header('Location: master_vendor_evaluation_set.php?set_id=' . $set_id);
    exit;
}

// One-shot flash from a prior POST redirect (if any).
$flash              = $_SESSION['flash_message'] ?? null;
$flash_type         = $_SESSION['flash_type'] ?? 'success';
$flash_conflict_set = isset($_SESSION['flash_conflict_set_id']) ? (int)$_SESSION['flash_conflict_set_id'] : 0;
$qf_repopulate      = $_SESSION['qf_repopulate'] ?? null;
unset($_SESSION['flash_message'], $_SESSION['flash_type'], $_SESSION['flash_conflict_set_id'], $_SESSION['qf_repopulate']);

require_once '../includes/header.php';

// ══════════════════════════════════════════════════════════════════════════
// Load the target set (+ its taxonomy map row).
// ══════════════════════════════════════════════════════════════════════════
$edit_set = null;
if ($set_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM vendor_eval_question_sets WHERE id = ?");
    $stmt->execute([$set_id]);
    $edit_set = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$edit_set) {
    ?>
    <div class="max-w-3xl mx-auto py-10">
        <div class="bg-white shadow-md rounded-lg p-8 border border-gray-200 text-center">
            <h2 class="text-xl font-bold text-gray-800 mb-2">Question Set Not Found</h2>
            <p class="text-gray-600 mb-6">The requested question set does not exist or was removed.</p>
            <a href="master_vendor_evaluation.php?tab=questions" class="inline-block bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-5 rounded text-sm shadow">&larr; Back to Question Sets</a>
        </div>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}

// Questions, ordered Identification first then by question_no (matches the
// order shown to evaluators on the public form).
$qstmt = $pdo->prepare("
    SELECT * FROM vendor_eval_questions
     WHERE question_set_id = ?
     ORDER BY (question_section = 'identification') DESC, question_no");
$qstmt->execute([$set_id]);
$edit_questions = $qstmt->fetchAll(PDO::FETCH_ASSOC);

// Taxonomy lists that power the multi-select L2 / grouped L3 controls.
$taxonomy_rows = $pdo->query("SELECT DISTINCT level_2, level_3 FROM v_item_taxonomy ORDER BY level_2, level_3")->fetchAll(PDO::FETCH_ASSOC);
$l2_list  = [];
$l2_to_l3 = [];
foreach ($taxonomy_rows as $tr) {
    $l2 = trim((string)($tr['level_2'] ?? ''));
    $l3 = trim((string)($tr['level_3'] ?? ''));
    if ($l2 === '') continue;
    if (!in_array($l2, $l2_list, true)) $l2_list[] = $l2;
    if ($l3 !== '' && !in_array($l3, $l2_to_l3[$l2] ?? [], true)) $l2_to_l3[$l2][] = $l3;
}
sort($l2_list);

// This set's current taxonomy mappings (0..N rows) → preselect the controls.
$mstmt = $pdo->prepare("SELECT map_level, taxonomy_level_2, taxonomy_level_3, is_active
                          FROM vendor_eval_question_set_taxonomy_map
                         WHERE question_set_id = ?
                         ORDER BY map_level, taxonomy_level_2, taxonomy_level_3");
$mstmt->execute([$set_id]);
$set_maps = $mstmt->fetchAll(PDO::FETCH_ASSOC);

$sel_l2_keys        = [];   // 'IT Equipment' => true
$sel_l3_keys        = [];   // 'IT Equipment||Laptop' => true
$is_default_set     = false;
$has_active_map_row = false;
foreach ($set_maps as $m) {
    if ((int)$m['is_active'] === 1) $has_active_map_row = true;
    if ($m['map_level'] === 'default') {
        $is_default_set = true;
    } elseif ($m['map_level'] === 'l2') {
        $sel_l2_keys[$m['taxonomy_level_2']] = true;
    } elseif ($m['map_level'] === 'l3') {
        $sel_l3_keys[$m['taxonomy_level_2'] . '||' . $m['taxonomy_level_3']] = true;
    }
}

// Commodity (L3) preselection as a list of {l2,l3} pairs for the JS-built control.
$sel_l3_arr = [];
foreach (array_keys($sel_l3_keys) as $k) {
    [$a, $b] = array_pad(explode('||', $k, 2), 2, '');
    if ($a !== '' && $b !== '') $sel_l3_arr[] = ['l2' => $a, 'l3' => $b];
}

// Form pre-selection: defaults to the saved DB state, but a failed submit's own
// values win so the editor keeps what the user typed (mirrors qf_repopulate).
// NOTE: the Data Health panel above keeps using the DB-derived $sel_* vars, so it
// always reflects what is actually saved — only the editable form echoes the
// unsaved attempt.
$form_is_default = $is_default_set;
$form_l2_keys    = $sel_l2_keys;
$form_l3_arr     = $sel_l3_arr;
$set_form_repop  = $_SESSION['set_form_repopulate'] ?? null;
unset($_SESSION['set_form_repopulate']);
if (is_array($set_form_repop) && (int)($set_form_repop['set_id'] ?? 0) === $set_id) {
    $form_is_default = !empty($set_form_repop['taxonomy_is_default']);
    $form_l2_keys = [];
    foreach ((array)($set_form_repop['taxonomy_l2'] ?? []) as $v) {
        $v = trim((string)$v);
        if ($v !== '') $form_l2_keys[$v] = true;
    }
    $form_l3_arr = [];
    foreach ((array)($set_form_repop['taxonomy_l3'] ?? []) as $raw) {
        $p = json_decode((string)$raw, true);
        if (is_array($p) && isset($p['l2'], $p['l3'])) {
            $form_l3_arr[] = ['l2' => (string)$p['l2'], 'l3' => (string)$p['l3']];
        }
    }
}

// ── Data health computations ────────────────────────────────────────────────
$active_weight_sum        = 0.0;
$max_question_no          = 0;
$has_active_text_question = false;
$active_ident_count       = 0;
$active_eval_count        = 0;
foreach ($edit_questions as $q) {
    $is_active  = (int)$q['is_active'] === 1;
    $is_ident   = ($q['question_section'] ?? 'evaluation') === 'identification';
    $qtype      = $q['question_type'] ?? 'rating';
    if ($is_active && !$is_ident && $qtype === 'rating') {
        $active_weight_sum += (float)$q['weight'];
    }
    if ($is_active && !$is_ident && $qtype === 'text') {
        $has_active_text_question = true;
    }
    if ($is_active && $is_ident)  $active_ident_count++;
    if ($is_active && !$is_ident) $active_eval_count++;
    $max_question_no = max($max_question_no, (int)$q['question_no']);
}
$weight_ok        = abs($active_weight_sum - 1.0) <= 0.0001;
$next_question_no = $max_question_no + 1;

// A set is "routable" when it has at least one ACTIVE taxonomy map row.
$has_active_taxmap = $has_active_map_row;
$set_is_active     = (int)$edit_set['is_active'] === 1;

// Backup UX: repopulate the Add/Edit Question form if the last POST failed validation.
$qf_prefill = ($qf_repopulate && (int)($qf_repopulate['set_id'] ?? 0) === $set_id) ? $qf_repopulate : null;
?>

<!-- Select2: searchable multi-select for taxonomy routing (same library/CDN already used on dashboard_mgr.php). -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
    .ve2-tax .select2-container { z-index: 1060; }
    .ve2-tax .select2-selection--multiple { border-color: #d1d5db !important; min-height: 38px; max-height: 7.5rem; overflow-y: auto; }
    .ve2-tax .select2-selection__choice { font-size: 0.75rem; }
    .select2-dropdown { z-index: 10000; }
</style>

<div class="max-w-5xl mx-auto py-2">
    <div class="mb-6 flex justify-between items-end border-b pb-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-800"><?= h($edit_set['set_name']) ?></h2>
            <p class="text-gray-600 font-mono text-xs">
                <?= h($edit_set['set_code']) ?> <span class="text-gray-400">v<?= h($edit_set['version']) ?></span>
                <?php if ($set_is_active): ?>
                    <span class="ml-2 bg-green-100 text-green-800 py-0.5 px-2 rounded text-[10px] font-bold border border-green-200">Active</span>
                <?php else: ?>
                    <span class="ml-2 bg-gray-100 text-gray-500 py-0.5 px-2 rounded text-[10px] font-bold border border-gray-200">Inactive</span>
                <?php endif; ?>
            </p>
        </div>
        <a href="master_vendor_evaluation.php?tab=questions" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow">&larr; Back to List</a>
    </div>

    <?php if ($flash !== null): ?>
        <?php
            $flash_classes = [
                'success' => 'bg-green-100 border-green-500 text-green-700',
                'error'   => 'bg-red-100 border-red-500 text-red-700',
                'warning' => 'bg-yellow-100 border-yellow-500 text-yellow-700',
            ][$flash_type] ?? 'bg-gray-100 border-gray-400 text-gray-700';
        ?>
        <div class="border-l-4 <?= $flash_classes ?> p-4 mb-6 shadow-sm rounded">
            <p class="font-bold"><?= $flash_type === 'error' ? 'Error' : ($flash_type === 'warning' ? 'Saved with warning' : 'Success') ?></p>
            <p><?= h($flash) ?></p>
            <?php if ($flash_conflict_set > 0 && $flash_conflict_set !== $set_id): ?>
            <p class="mt-2">
                <a href="master_vendor_evaluation_set.php?set_id=<?= $flash_conflict_set ?>"
                   class="inline-block bg-white border border-red-300 text-red-700 hover:bg-red-50 font-bold py-1.5 px-3 rounded text-sm">
                    Edit the mapped set &rarr;
                </a>
            </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ── Data health panel ─────────────────────────────────────────────── -->
    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-6">
        <h3 class="text-lg font-bold border-b pb-2 mb-4">Data Health</h3>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
            <div class="p-3 rounded border <?= $weight_ok ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200' ?>">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Rating Weight Total</p>
                <p class="text-2xl font-black <?= $weight_ok ? 'text-green-700' : 'text-yellow-700' ?>"><?= fmt_pct($active_weight_sum) ?>%</p>
                <p class="text-xs <?= $weight_ok ? 'text-green-600' : 'text-yellow-700' ?>"><?= $weight_ok ? 'OK — sums to 100%.' : 'Should total 100% (active rating questions).' ?></p>
            </div>
            <div class="p-3 rounded border <?= $has_active_taxmap ? 'bg-green-50 border-green-200' : ($set_is_active ? 'bg-yellow-50 border-yellow-200' : 'bg-gray-50 border-gray-200') ?>">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Taxonomy Routing</p>
                <p class="text-sm font-bold <?= $has_active_taxmap ? 'text-green-700' : ($set_is_active ? 'text-yellow-700' : 'text-gray-500') ?>">
                    <?php if ($is_default_set): ?>
                        Default · All categories
                    <?php elseif (!empty($set_maps)): ?>
                        <?php
                            $n_l2 = count($sel_l2_keys);
                            $n_l3 = count($sel_l3_keys);
                            $bits = [];
                            if ($n_l2) $bits[] = $n_l2 . ' Sub Category';
                            if ($n_l3) $bits[] = $n_l3 . ' Commodity';
                            echo h(implode(' · ', $bits));
                        ?>
                    <?php else: ?>
                        Not mapped
                    <?php endif; ?>
                </p>
                <?php if (!$has_active_taxmap && $set_is_active): ?>
                <p class="text-xs text-yellow-700">This active set is not mapped to any taxonomy and will not be routed to any PO.</p>
                <?php endif; ?>
            </div>
            <div class="p-3 rounded border <?= $has_active_text_question ? 'bg-green-50 border-green-200' : 'bg-yellow-50 border-yellow-200' ?>">
                <p class="text-[10px] font-bold uppercase tracking-wider text-gray-500">Kesimpulan / Text</p>
                <p class="text-sm font-bold <?= $has_active_text_question ? 'text-green-700' : 'text-yellow-700' ?>"><?= $has_active_text_question ? 'Present' : 'Missing' ?></p>
                <?php if (!$has_active_text_question): ?>
                <p class="text-xs text-yellow-700">No active Kesimpulan / final text question. Consider adding a text question (weight 0) for the evaluator's summary.</p>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-xs text-gray-400 mt-3">Active: <?= $active_ident_count ?> identification · <?= $active_eval_count ?> evaluation question(s). Warnings do not block editing.</p>
    </div>

    <!-- ── Set metadata + taxonomy editor ────────────────────────────────── -->
    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-6">
        <h3 class="text-lg font-bold border-b pb-2 mb-4">Set Details &amp; Taxonomy Mapping</h3>
        <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <input type="hidden" name="action" value="update_question_set">
            <input type="hidden" name="set_id" value="<?= (int)$edit_set['id'] ?>">

            <div>
                <label class="block text-xs font-bold mb-1">Question Set Code</label>
                <input type="text" value="<?= h($edit_set['set_code'] ?? '') ?>" readonly
                       class="w-full p-2 border rounded text-sm bg-gray-100 text-gray-500 font-mono">
                <p class="text-[10px] text-gray-400 mt-1">Code is locked after creation because it is used for routing. Create a new version for major changes.</p>
            </div>
            <div class="md:col-span-2">
                <label class="block text-xs font-bold mb-1">Question Set Name</label>
                <input type="text" name="question_set_name" required
                       value="<?= h($edit_set['set_name'] ?? '') ?>" class="w-full p-2 border rounded text-sm">
            </div>

            <div class="md:col-span-3 border-t pt-4 mt-1 ve2-tax">
                <p class="text-sm font-bold text-gray-700 mb-2">Taxonomy Routing
                    <span class="font-normal text-gray-400">— assign this set to one or more Sub Categories and/or Commodities</span></p>
                <div class="flex flex-col sm:flex-row sm:flex-wrap gap-2 sm:gap-6 mb-3 text-sm">
                    <label class="inline-flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="taxonomy_is_default" value="0" <?= $form_is_default ? '' : 'checked' ?> onchange="syncRoutingType()" class="mt-0.5">
                        <span><b>Specific Taxonomy</b><br><span class="text-[11px] text-gray-500">Route selected Sub Categories / Commodities</span></span>
                    </label>
                    <label class="inline-flex items-start gap-2 cursor-pointer">
                        <input type="radio" name="taxonomy_is_default" value="1" <?= $form_is_default ? 'checked' : '' ?> onchange="syncRoutingType()" class="mt-0.5">
                        <span><b>Default Fallback</b><br><span class="text-[11px] text-gray-500">Matches any PO no more-specific set matches</span></span>
                    </label>
                </div>
                <div id="taxonomy_specific_wrap" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold mb-1">Sub Categories <span class="text-gray-400 font-normal">— select one or more</span></label>
                        <select id="taxonomy_l2" name="taxonomy_l2[]" multiple class="w-full">
                            <?php foreach ($l2_list as $l2opt): ?>
                                <option value="<?= h($l2opt) ?>" <?= isset($form_l2_keys[$l2opt]) ? 'selected' : '' ?>><?= h($l2opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-gray-500 mt-1"><span id="l2_counter"></span> <span class="text-gray-400">A Sub Category covers every Commodity under it.</span></p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold mb-1">Commodities <span class="text-gray-400 font-normal">— select one or more</span></label>
                        <select id="taxonomy_l3" name="taxonomy_l3[]" multiple class="w-full"></select>
                        <p class="text-[10px] text-gray-500 mt-1"><span id="l3_counter"></span> <span class="text-gray-400">Narrowed to the Sub Categories you pick.</span></p>
                    </div>
                </div>
            </div>

            <div class="md:col-span-3">
                <label class="block text-xs font-bold mb-1">Description / Notes</label>
                <textarea name="description" rows="2" class="w-full p-2 border rounded text-sm"><?= h($edit_set['description'] ?? '') ?></textarea>
            </div>

            <div class="md:col-span-3 flex items-center justify-end border-t pt-3">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded text-sm shadow">Save Set Details</button>
            </div>
        </form>
    </div>

    <!-- ── Question builder ──────────────────────────────────────────────── -->
    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-6">
        <div class="flex justify-between items-center mb-2 border-b pb-2">
            <h3 class="text-lg font-bold">Questions</h3>
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold px-3 py-1 rounded <?= $weight_ok ? 'bg-green-100 text-green-700 border border-green-200' : 'bg-yellow-100 text-yellow-700 border border-yellow-200' ?>">
                    Rating weight total: <?= fmt_pct($active_weight_sum) ?>% <?= $weight_ok ? '(OK)' : '(should be 100%)' ?>
                </span>
                <button type="button" id="qfOpenBtn" onclick="openQuestionForm()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm shadow">+ Add Question</button>
            </div>
        </div>
        <p class="text-xs text-gray-500 mb-4">Identification fields (name/email/date/notes) are shown first to the evaluator and carry no score. Evaluation questions follow; active rating weights should total 100%. Text questions such as Kesimpulan do not affect the score.</p>

        <div class="overflow-x-auto mb-6">
            <table class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="p-2">No</th>
                        <th class="p-2">Section</th>
                        <th class="p-2">Type</th>
                        <th class="p-2">Criterion / Field</th>
                        <th class="p-2">Weight</th>
                        <th class="p-2 text-center">Comment/Req.</th>
                        <th class="p-2 text-center">Status</th>
                        <th class="p-2 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($edit_questions as $q):
                        $is_ident = ($q['question_section'] ?? 'evaluation') === 'identification';
                        $qtype    = $q['question_type'] ?? 'rating';
                    ?>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="p-2 font-bold">Q<?= (int)$q['question_no'] ?></td>
                        <td class="p-2">
                            <?php if ($is_ident): ?>
                                <span class="px-2 py-0.5 rounded border bg-amber-50 text-amber-700 border-amber-200">Identification</span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded border bg-slate-50 text-slate-600 border-slate-200">Evaluation</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-2">
                            <?php if ($is_ident): ?>
                                <span class="px-2 py-0.5 rounded border bg-teal-50 text-teal-700 border-teal-200"><?= h($q['input_type'] ?? 'text') ?></span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded border <?= $qtype === 'text' ? 'bg-purple-50 text-purple-700 border-purple-200' : 'bg-indigo-50 text-indigo-700 border-indigo-200' ?>"><?= h(ucfirst($qtype)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="p-2"><?= h($q['criterion_name']) ?></td>
                        <td class="p-2"><?= ($is_ident || $qtype === 'text') ? '—' : fmt_pct($q['weight']) . '%' ?></td>
                        <td class="p-2 text-center"><?= (int)$q['comment_required'] === 1 ? 'Yes' : 'No' ?></td>
                        <td class="p-2 text-center">
                            <?= (int)$q['is_active'] === 1
                                ? '<span class="bg-green-100 text-green-800 py-1 px-2 rounded text-[10px] font-bold border border-green-200">Active</span>'
                                : '<span class="bg-gray-100 text-gray-500 py-1 px-2 rounded text-[10px] font-bold border border-gray-200">Inactive</span>' ?>
                        </td>
                        <td class="p-2 text-right whitespace-nowrap">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="move_question_up">
                                <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                <button type="submit" class="px-2 py-1 rounded border bg-gray-50 hover:bg-gray-100" title="Move up">&uarr;</button>
                            </form>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="move_question_down">
                                <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                <button type="submit" class="px-2 py-1 rounded border bg-gray-50 hover:bg-gray-100" title="Move down">&darr;</button>
                            </form>
                            <button type="button" onclick="loadQuestionForm(<?= (int)$q['id'] ?>)" title="Edit question" class="inline-flex items-center justify-center w-7 h-7 rounded border bg-blue-50 text-blue-700 hover:bg-blue-100 align-middle">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php if ((int)$q['is_active'] === 1): ?>
                            <form method="POST" class="inline" id="deactivateQuestionForm_<?= (int)$q['id'] ?>">
                                <input type="hidden" name="action" value="deactivate_question">
                                <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                <button type="button" title="Deactivate question"
                                        onclick="showConfirmModal('deactivateQuestionForm_<?= (int)$q['id'] ?>', 'Deactivate Question', 'Deactivate this question? It will no longer appear in the form.', true)"
                                        class="inline-flex items-center justify-center w-7 h-7 rounded border bg-red-50 text-red-700 hover:bg-red-100 align-middle">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="reactivate_question">
                                <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">
                                <input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>">
                                <button type="submit" title="Reactivate question"
                                        class="inline-flex items-center justify-center w-7 h-7 rounded border bg-green-50 text-green-700 hover:bg-green-100 align-middle">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($edit_questions)): ?>
                    <tr><td colspan="8" class="p-4 text-center text-gray-400 italic">No questions yet — add one below.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Question create/edit form -->
        <div id="questionFormWrap" class="border-t pt-4 hidden">
            <h4 class="font-bold text-sm mb-3" id="questionFormTitle"><?= ($qf_prefill && ($qf_prefill['action'] ?? '') === 'update_question') ? 'Edit Question Q' . (int)($qf_prefill['question_no'] ?? 0) : 'Add Question' ?></h4>
            <form method="POST" id="questionForm" class="grid grid-cols-1 md:grid-cols-4 gap-3">
                <input type="hidden" name="action" id="qf_action" value="<?= h($qf_prefill['action'] ?? 'create_question') ?>">
                <input type="hidden" name="question_id" id="qf_question_id" value="<?= h($qf_prefill['question_id'] ?? '') ?>">
                <input type="hidden" name="set_id" value="<?= (int)$set_id ?>">

                <div>
                    <label class="block text-xs font-bold mb-1">Question No.</label>
                    <input type="number" min="1" name="question_no" id="qf_question_no" required value="<?= (int)($qf_prefill['question_no'] ?? $next_question_no) ?>" class="w-full p-2 border rounded text-sm">
                </div>
                <div>
                    <label class="block text-xs font-bold mb-1">Section</label>
                    <select name="question_section" id="qf_question_section" onchange="toggleSectionType()" class="w-full p-2 border rounded text-sm">
                        <option value="evaluation" <?= ($qf_prefill['question_section'] ?? 'evaluation') === 'evaluation' ? 'selected' : '' ?>>Evaluation</option>
                        <option value="identification" <?= ($qf_prefill['question_section'] ?? '') === 'identification' ? 'selected' : '' ?>>Identification</option>
                    </select>
                </div>
                <div id="qf_type_wrap">
                    <label class="block text-xs font-bold mb-1">Type</label>
                    <select name="question_type" id="qf_question_type" onchange="toggleSectionType()" class="w-full p-2 border rounded text-sm">
                        <option value="rating" <?= ($qf_prefill['question_type'] ?? 'rating') === 'rating' ? 'selected' : '' ?>>Rating</option>
                        <option value="text" <?= ($qf_prefill['question_type'] ?? '') === 'text' ? 'selected' : '' ?>>Text</option>
                    </select>
                </div>
                <div id="qf_input_type_wrap" style="display:none;">
                    <label class="block text-xs font-bold mb-1">Input Type</label>
                    <select name="input_type" id="qf_input_type" class="w-full p-2 border rounded text-sm">
                        <?php foreach (IDENT_INPUT_TYPES as $it): ?>
                        <option value="<?= h($it) ?>" <?= ($qf_prefill['input_type'] ?? 'text') === $it ? 'selected' : '' ?>><?= h(ucfirst($it)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold mb-1" id="qf_criterion_label">Criterion Name</label>
                    <input type="text" name="criterion_name" id="qf_criterion_name" required value="<?= h($qf_prefill['criterion_name'] ?? '') ?>" class="w-full p-2 border rounded text-sm">
                    <p class="text-[10px] text-gray-400 mt-1" id="qf_criterion_helper">This will be shown as the question title.</p>
                </div>
                <div id="qf_weight_wrap" class="md:col-span-2">
                    <label class="block text-xs font-bold mb-1">Weight (0-1)</label>
                    <input type="number" step="0.0001" min="0" max="1" name="weight" id="qf_weight" value="<?= h($qf_prefill['weight'] ?? '0.00') ?>" class="w-full p-2 border rounded text-sm">
                    <p class="text-[10px] text-gray-400 mt-1">Use decimal weight, e.g. 0.125 = 12.5%, 0.20 = 20%.</p>
                </div>

                <div class="md:col-span-4" id="qf_question_text_wrap">
                    <label class="block text-xs font-bold mb-1" id="qf_question_text_label">Text Prompt</label>
                    <textarea name="question_text" id="qf_question_text" rows="2" class="w-full p-2 border rounded text-sm"><?= h($qf_prefill['question_text'] ?? '') ?></textarea>
                    <p class="text-[10px] text-gray-400 mt-1" id="qf_question_text_helper">This prompt will be shown to the evaluator, e.g. Kesimpulan instruction.</p>
                </div>

                <div id="qf_rating_fields" class="md:col-span-4 grid grid-cols-1 md:grid-cols-4 gap-3 border-t pt-3">
                    <?php foreach ([1, 2, 3, 4] as $n): ?>
                    <div class="bg-gray-50 p-2 rounded border">
                        <label class="block text-[10px] font-bold mb-1">Score <?= $n ?> Label</label>
                        <input type="text" name="score_<?= $n ?>_label" id="qf_score_<?= $n ?>_label" value="<?= h($qf_prefill['score_' . $n . '_label'] ?? '') ?>" class="w-full p-1 border rounded text-xs mb-1">
                        <label class="block text-[10px] font-bold mb-1">Score <?= $n ?> Description</label>
                        <textarea name="score_<?= $n ?>_description" id="qf_score_<?= $n ?>_description" rows="2" class="w-full p-1 border rounded text-xs"><?= h($qf_prefill['score_' . $n . '_description'] ?? '') ?></textarea>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="md:col-span-2">
                    <label class="block text-xs font-bold mb-1">Comment Prompt / Catatan Instruction</label>
                    <input type="text" name="comment_prompt" id="qf_comment_prompt" value="<?= h($qf_prefill['comment_prompt'] ?? '') ?>" class="w-full p-2 border rounded text-sm">
                    <p class="text-[10px] text-gray-400 mt-1">Text shown above the Catatan box. Leave blank to use the default.</p>
                </div>
                <div class="md:col-span-2 flex items-center gap-2" id="qf_comment_required_wrap">
                    <input type="checkbox" name="comment_required" value="1" id="qf_comment_required" <?= !empty($qf_prefill['comment_required']) ? 'checked' : '' ?> class="rounded border-gray-300 text-indigo-600">
                    <label for="qf_comment_required" class="text-xs font-bold text-gray-700" id="qf_comment_required_label">Comment required</label>
                </div>

                <div class="md:col-span-4">
                    <details class="border rounded bg-gray-50" <?= (!empty($qf_prefill['question_help_text']) || !empty($qf_prefill['question_image_path'])) ? 'open' : '' ?>>
                        <summary class="cursor-pointer select-none px-3 py-2 text-xs font-bold text-gray-600">Advanced options</summary>
                        <div class="p-3 grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-bold mb-1">Additional Help Text (optional)</label>
                                <input type="text" name="question_help_text" id="qf_question_help_text" value="<?= h($qf_prefill['question_help_text'] ?? '') ?>" class="w-full p-2 border rounded text-sm">
                                <p class="text-[10px] text-gray-400 mt-1">Extra instruction shown below the question.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-bold mb-1">Optional Image Reference</label>
                                <input type="text" name="question_image_path" id="qf_question_image_path" value="<?= h($qf_prefill['question_image_path'] ?? '') ?>" placeholder="e.g. example.png" class="w-full p-2 border rounded text-sm">
                                <p class="text-[10px] text-gray-400 mt-1">Only use if an image already exists in workspace/uploads/vendor_eval_questions/.</p>
                            </div>
                        </div>
                    </details>
                </div>

                <div class="md:col-span-4 flex justify-end gap-2">
                    <button type="button" onclick="closeQuestionForm()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded text-sm shadow" id="qf_submit_btn"><?= ($qf_prefill && ($qf_prefill['action'] ?? '') === 'update_question') ? 'Save Question' : 'Add Question' ?></button>
                </div>
            </form>
        </div>

        <!-- Duplicate question_no warning modal -->
        <div id="duplicateQuestionModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if (event.target === this) closeDuplicateQuestionModal();">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                <h3 class="text-lg font-bold mb-2 text-red-700">Duplicate Question No.</h3>
                <p class="text-sm text-gray-600 mb-6" id="duplicateQuestionMessage"></p>
                <div class="flex justify-end">
                    <button type="button" onclick="closeDuplicateQuestionModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded text-sm shadow">OK</button>
                </div>
            </div>
        </div>

        <!-- Confirm action modal (deactivate question) -->
        <div id="confirmActionModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4" onclick="if (event.target === this) closeConfirmModal();">
            <div class="bg-white rounded-lg shadow-xl max-w-md w-full p-6">
                <h3 class="text-lg font-bold mb-2" id="confirmModalTitle">Confirm</h3>
                <p class="text-sm text-gray-600 mb-6" id="confirmModalMessage"></p>
                <div class="flex justify-end gap-2">
                    <button type="button" onclick="closeConfirmModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                    <button type="button" onclick="confirmModalProceed()" id="confirmModalConfirmBtn" class="font-bold py-2 px-6 rounded text-sm shadow text-white bg-red-600 hover:bg-red-700">Confirm</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Preview panel (Identification first, then Evaluation) ─────────────── -->
    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
        <h3 class="text-lg font-bold border-b pb-2 mb-4">Preview — <?= h($edit_set['set_name']) ?></h3>
        <?php
            $active_only  = array_values(array_filter($edit_questions, fn($q) => (int)$q['is_active'] === 1));
            $ident_q      = array_values(array_filter($active_only, fn($q) => ($q['question_section'] ?? 'evaluation') === 'identification'));
            $eval_q       = array_values(array_filter($active_only, fn($q) => ($q['question_section'] ?? 'evaluation') !== 'identification'));
        ?>
        <?php if (empty($active_only)): ?>
            <p class="text-gray-400 italic">No active questions to preview yet.</p>
        <?php else: ?>
        <div class="max-w-4xl space-y-4 bg-gray-50 p-4 rounded border border-gray-200">

            <?php if (!empty($ident_q)): ?>
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider">Identification</p>
            <?php foreach ($ident_q as $q): $it = $q['input_type'] ?? 'text'; ?>
            <div class="bg-white p-4 border border-gray-200 rounded-lg">
                <label class="block font-bold text-gray-900 mb-1"><?= h($q['criterion_name']) ?><?php if ((int)$q['comment_required'] === 1): ?> <span class="text-red-500">*</span><?php endif; ?></label>
                <?php if (($q['question_text'] ?? '') !== ''): ?><p class="text-sm text-gray-600 mb-2"><?= h($q['question_text']) ?></p><?php endif; ?>
                <?php if ($it === 'textarea'): ?>
                    <textarea disabled rows="2" class="w-full p-2 border border-gray-200 bg-gray-100 rounded text-sm"></textarea>
                <?php else: ?>
                    <input disabled type="<?= h($it) ?>" class="w-full p-2 border border-gray-200 bg-gray-100 rounded text-sm">
                <?php endif; ?>
                <p class="text-[10px] text-gray-400 mt-1">input type: <?= h($it) ?> · no score</p>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>

            <?php if (!empty($eval_q)): ?>
            <p class="text-xs font-bold text-gray-400 uppercase tracking-wider pt-2">Evaluation</p>
            <?php foreach ($eval_q as $q):
                $descs = [1 => $q['score_1_description'] ?? '', 2 => $q['score_2_description'] ?? '', 3 => $q['score_3_description'] ?? '', 4 => $q['score_4_description'] ?? ''];
                $has_rubric = array_filter($descs) !== [];
            ?>
            <div class="bg-white p-4 border border-gray-200 rounded-lg">
                <div class="flex gap-3">
                    <div class="shrink-0 w-8 md:w-10 pt-0.5 text-xs font-bold text-gray-400 uppercase">Q<?= (int)$q['question_no'] ?></div>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-baseline gap-2 mb-1">
                            <h4 class="font-bold text-gray-900"><?= h($q['criterion_name']) ?></h4>
                            <?php if ($q['question_type'] === 'rating' && (float)$q['weight'] > 0): ?>
                                <span class="text-xs font-bold text-indigo-700 bg-indigo-100 px-2 py-0.5 rounded">Weight: <?= fmt_pct($q['weight']) ?>%</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($q['question_type'] === 'text'): ?>
                        <p class="text-sm text-gray-700 mb-2"><?= h($q['question_text']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($q['question_help_text'])): ?>
                            <p class="text-xs text-gray-500 italic mb-2"><?= h($q['question_help_text']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($q['question_image_path'])): ?>
                            <p class="text-[10px] text-gray-400 mb-2">[image reference: <?= h($q['question_image_path']) ?>]</p>
                        <?php endif; ?>

                        <?php if ($q['question_type'] === 'text'): ?>
                            <textarea disabled rows="2" class="w-full p-2 border border-gray-200 bg-gray-100 rounded text-sm" placeholder="<?= h($q['comment_prompt'] ?: 'Please provide your overall assessment.') ?>"></textarea>
                        <?php else: ?>
                            <?php if ($has_rubric): ?>
                            <div class="overflow-x-auto mb-2">
                                <table class="w-full text-xs border border-gray-200 rounded">
                                    <thead><tr class="bg-gray-100">
                                        <?php foreach ([1, 2, 3, 4] as $sv): ?>
                                        <th class="px-2 py-1 border-r border-gray-200 last:border-r-0 text-left"><?= $sv ?> — <?= h($q['score_' . $sv . '_label'] ?? '') ?></th>
                                        <?php endforeach; ?>
                                    </tr></thead>
                                    <tbody><tr>
                                        <?php foreach ([1, 2, 3, 4] as $sv): ?>
                                        <td class="px-2 py-1 border-r border-gray-200 last:border-r-0 align-top"><?= h($descs[$sv] ?: '—') ?></td>
                                        <?php endforeach; ?>
                                    </tr></tbody>
                                </table>
                            </div>
                            <?php else: ?>
                                <p class="text-xs text-gray-500 mb-2">Labels: <?= h($q['score_1_label']) ?> / <?= h($q['score_2_label']) ?> / <?= h($q['score_3_label']) ?> / <?= h($q['score_4_label']) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const QUESTIONS_DATA = <?= json_encode($edit_questions, JSON_UNESCAPED_UNICODE) ?>;

    // ── Taxonomy editor: routing type + searchable dependent taxonomy ─────────
    const L2_TO_L3 = <?= json_encode($l2_to_l3, JSON_UNESCAPED_UNICODE) ?>;
    const PRESELECT_L3 = <?= json_encode(array_values($form_l3_arr), JSON_UNESCAPED_UNICODE) ?>;

    function routingIsDefault() {
        const el = document.querySelector('input[name="taxonomy_is_default"]:checked');
        return el ? el.value === '1' : false;
    }
    function applyRoutingVisibility() {
        document.getElementById('taxonomy_specific_wrap').style.display = routingIsDefault() ? 'none' : '';
    }
    // Radio change (user-driven only): Default Fallback hides + clears the
    // selectors (backend ignores L2/L3 when taxonomy_is_default is set); Specific
    // shows them again with commodity scope refreshed to the current Sub Categories.
    function syncRoutingType() {
        applyRoutingVisibility();
        if (routingIsDefault()) {
            $('#taxonomy_l2').val(null).trigger('change.select2');
            $('#taxonomy_l3').val(null).trigger('change.select2');
            updateTaxCounters();
        } else {
            rebuildL3(true, null);
        }
    }
    function updateTaxCounters() {
        const n2 = ($('#taxonomy_l2').val() || []).length;
        const n3 = ($('#taxonomy_l3').val() || []).length;
        document.getElementById('l2_counter').textContent = n2 ? (n2 + ' selected') : '';
        document.getElementById('l3_counter').textContent = n3 ? (n3 + ' selected') : '';
    }
    // Rebuild the Commodity (L3) options scoped to the selected Sub Categories (L2).
    // No L2 selected → every commodity is available. On the first build (wantKeys set)
    // the scope also includes the parent Sub Categories of already-saved commodities
    // so no saved mapping is hidden. On later L2 changes, selections whose parent L2
    // is no longer selected are dropped (no orphaned commodities).
    function rebuildL3(preserve, wantKeys) {
        const $l2 = $('#taxonomy_l2');
        const $l3 = $('#taxonomy_l3');
        const selectedL2 = $l2.val() || [];
        const prev = preserve ? ($l3.val() || []) : [];

        let scope;
        if (wantKeys && wantKeys.size) {
            const s = new Set(selectedL2);
            wantKeys.forEach(function (k) { s.add(k.split('||')[0]); });
            scope = Array.from(s);
        } else {
            scope = selectedL2.length ? selectedL2 : Object.keys(L2_TO_L3);
        }

        const allowed = new Set();
        const initialSel = [];
        if ($l3.data('select2')) $l3.select2('destroy');
        $l3.empty();
        scope.forEach(function (l2) {
            const l3s = L2_TO_L3[l2] || [];
            if (!l3s.length) return;
            const og = document.createElement('optgroup');
            og.label = l2;
            l3s.forEach(function (l3) {
                const val = JSON.stringify({ l2: l2, l3: l3 });
                allowed.add(val);
                og.appendChild(new Option(l3, val, false, false));
                if (wantKeys && wantKeys.has(l2 + '||' + l3)) initialSel.push(val);
            });
            $l3.append(og);
        });
        const keep = wantKeys ? initialSel : prev.filter(function (v) { return allowed.has(v); });
        $l3.val(keep);
        $l3.select2({ placeholder: 'Select Commodities…', width: '100%', closeOnSelect: false });
        $l3.off('change.ve2').on('change.ve2', updateTaxCounters);
        updateTaxCounters();
    }
    function initTaxonomyEditor() {
        $('#taxonomy_l2').select2({ placeholder: 'Select Sub Categories…', width: '100%', closeOnSelect: false })
            .on('change.ve2', function () { rebuildL3(true, null); });
        const wantKeys = new Set(PRESELECT_L3.map(function (p) { return p.l2 + '||' + p.l3; }));
        rebuildL3(false, wantKeys);
    }

    // ── Confirm modal (deactivate question) ────────────────────────────────
    let _confirmModalFormId = null;
    function showConfirmModal(formId, title, message, danger) {
        document.getElementById('confirmModalTitle').textContent = title;
        document.getElementById('confirmModalMessage').textContent = message;
        const btn = document.getElementById('confirmModalConfirmBtn');
        btn.className = 'font-bold py-2 px-6 rounded text-sm shadow text-white ' + (danger ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700');
        _confirmModalFormId = formId;
        document.getElementById('confirmActionModal').classList.remove('hidden');
    }
    function closeConfirmModal() {
        document.getElementById('confirmActionModal')?.classList.add('hidden');
        _confirmModalFormId = null;
    }
    function confirmModalProceed() {
        if (_confirmModalFormId) document.getElementById(_confirmModalFormId)?.submit();
        closeConfirmModal();
    }
    function closeDuplicateQuestionModal() {
        document.getElementById('duplicateQuestionModal')?.classList.add('hidden');
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeConfirmModal(); closeDuplicateQuestionModal(); }
    });

    // ── Question form: section/type-aware field visibility ─────────────────
    function toggleSectionType() {
        const section = document.getElementById('qf_question_section').value;
        const isIdent = section === 'identification';
        const isText  = document.getElementById('qf_question_type').value === 'text';

        // Type select only applies to Evaluation; Input Type only to Identification.
        document.getElementById('qf_type_wrap').style.display       = isIdent ? 'none' : 'block';
        document.getElementById('qf_input_type_wrap').style.display = isIdent ? 'block' : 'none';

        // Rating-only fields (scores + weight) hidden for identification and text.
        const showRating = !isIdent && !isText;
        document.getElementById('qf_rating_fields').style.display = showRating ? 'grid' : 'none';
        document.getElementById('qf_weight_wrap').style.display   = showRating ? 'block' : 'none';
        if (!showRating) document.getElementById('qf_weight').value = '0.00';

        // Prompt (question_text) is required for identification and evaluation-text.
        const needPrompt = isIdent || isText;
        document.getElementById('qf_question_text_wrap').style.display = needPrompt ? 'block' : 'none';
        document.getElementById('qf_question_text').required = needPrompt;

        // Comment-required checkbox doubles as "field is required" for identification.
        document.getElementById('qf_comment_required_label').textContent = isIdent ? 'Required field' : 'Comment required';

        // Labels/helpers reflect the mode.
        document.getElementById('qf_criterion_label').textContent = isIdent ? 'Field Label' : 'Criterion Name';
        document.getElementById('qf_criterion_helper').textContent = isIdent
            ? 'Shown as the field label to the evaluator (e.g. "Your Name").'
            : 'This will be shown as the question title.';
        document.getElementById('qf_criterion_helper').style.display = (isIdent || isText) ? 'block' : 'block';
        document.getElementById('qf_question_text_label').textContent = isIdent ? 'Prompt / Instruction' : 'Text Prompt';
        document.getElementById('qf_question_text_helper').textContent = isIdent
            ? 'Optional helper prompt shown above the input (required to have content).'
            : 'This prompt will be shown to the evaluator, e.g. Kesimpulan instruction.';
    }

    function resetQuestionForm() {
        document.getElementById('questionForm').reset();
        document.getElementById('qf_action').value = 'create_question';
        document.getElementById('qf_question_id').value = '';
        document.getElementById('questionFormTitle').textContent = 'Add Question';
        document.getElementById('qf_submit_btn').textContent = 'Add Question';
        toggleSectionType();
    }
    function openQuestionForm() {
        document.getElementById('questionFormWrap').classList.remove('hidden');
        document.getElementById('qfOpenBtn').classList.add('hidden');
    }
    function closeQuestionForm() {
        resetQuestionForm();
        document.getElementById('questionFormWrap').classList.add('hidden');
        document.getElementById('qfOpenBtn').classList.remove('hidden');
    }
    function loadQuestionForm(questionId) {
        const q = QUESTIONS_DATA.find(function (r) { return parseInt(r.id, 10) === questionId; });
        if (!q) return;
        openQuestionForm();
        document.getElementById('qf_action').value = 'update_question';
        document.getElementById('qf_question_id').value = q.id;
        document.getElementById('qf_question_no').value = q.question_no;
        document.getElementById('qf_question_section').value = q.question_section || 'evaluation';
        document.getElementById('qf_question_type').value = q.question_type || 'rating';
        document.getElementById('qf_input_type').value = q.input_type || 'text';
        document.getElementById('qf_criterion_name').value = q.criterion_name || '';
        document.getElementById('qf_weight').value = (parseFloat(q.weight) || 0).toString();
        document.getElementById('qf_question_text').value = q.question_text || '';
        [1, 2, 3, 4].forEach(function (n) {
            document.getElementById('qf_score_' + n + '_label').value = q['score_' + n + '_label'] || '';
            document.getElementById('qf_score_' + n + '_description').value = q['score_' + n + '_description'] || '';
        });
        document.getElementById('qf_comment_prompt').value = q.comment_prompt || '';
        document.getElementById('qf_question_help_text').value = q.question_help_text || '';
        document.getElementById('qf_question_image_path').value = q.question_image_path || '';
        document.getElementById('qf_comment_required').checked = parseInt(q.comment_required, 10) === 1;
        document.getElementById('questionFormTitle').textContent = 'Edit Question Q' + q.question_no;
        document.getElementById('qf_submit_btn').textContent = 'Save Question';
        toggleSectionType();
        document.getElementById('questionFormWrap').scrollIntoView({ behavior: 'smooth' });
    }

    // Block submit client-side on a duplicate question_no (server-side check stays as backup).
    document.getElementById('questionForm').addEventListener('submit', function (e) {
        const qno = parseInt(document.getElementById('qf_question_no').value, 10);
        const questionIdVal = document.getElementById('qf_question_id').value;
        const currentId = questionIdVal !== '' ? parseInt(questionIdVal, 10) : null;
        const conflict = QUESTIONS_DATA.find(function (q) {
            return parseInt(q.question_no, 10) === qno && parseInt(q.id, 10) !== currentId;
        });
        if (conflict) {
            e.preventDefault();
            document.getElementById('duplicateQuestionMessage').textContent =
                'Question No. ' + qno + ' already exists in this question set. Please choose another number.';
            document.getElementById('duplicateQuestionModal').classList.remove('hidden');
        }
    });

    window.addEventListener('DOMContentLoaded', function () {
        initTaxonomyEditor();
        applyRoutingVisibility();
        toggleSectionType();
        <?php if ($qf_prefill): ?>
        // Re-open the form after a server-side validation error so typed values aren't lost.
        openQuestionForm();
        <?php endif; ?>
    });
</script>

<?php require_once '../includes/footer.php'; ?>
