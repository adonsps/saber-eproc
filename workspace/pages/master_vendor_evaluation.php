<?php
// workspace/pages/master_vendor_evaluation.php
//
// Vendor Evaluation v2 — Category-Manager-only master management page.
// This is the LIST page. Two tabs only:
//   • Question Sets      — a read-only-style list/table; create + activate/
//                          deactivate here, but ALL editing (metadata,
//                          taxonomy, questions, preview) happens on the
//                          separate detail page master_vendor_evaluation_set.php.
//   • Requestor Contacts — the requestor contact master.
//
// Read tables : vendor_eval_question_sets, vendor_eval_questions (counts),
//               vendor_eval_question_set_taxonomy_map, v_item_taxonomy,
//               requestors (manager_email is read directly),
//               vendor_eval_v2_form2_team_contacts
// Write tables: vendor_eval_question_sets, vendor_eval_question_set_taxonomy_map,
//               requestors, vendor_eval_v2_form2_team_contacts
//
// Safety invariants:
//   - Category Manager only (require_category_manager() from auth.php).
//   - Never reads or writes vendor_evaluations (v1) or v1 pages.
//   - No WA / email / Gemini / external API calls.
//   - All DB writes use prepared statements.
//   - Prefer is_active = 0 over hard delete; no destructive DELETEs here.
//   - Never inserts/updates the users table — manager accounts are created
//     via user_management.php, not here.

require_once '../includes/auth.php';
require_category_manager();
require_once '../config/database.php';
require_once '../includes/vendor_eval_v2_form2_worklist_token.php'; // Form 2 shared worklist token (get/regenerate)
require_once '../includes/vendor_eval_v2_sender.php';               // ve2_web_base_url() only — pure library, no side effects on require

const SENTINEL_ANY = '**ANY**';

function h($s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
 *     L2/L3 selections are ignored when default is checked.
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
 * the set. Caller owns the surrounding transaction and must run the friendly
 * duplicate-taxonomy pre-check (assertTaxonomyMapFree) for each desired mapping.
 *
 * Removed mappings are hard-DELETEd (not soft-deactivated): a taxonomy-map row is
 * pure routing config (submissions snapshot their own question_set_id + taxonomy,
 * so deleting a map row never rewrites history), and soft-deactivating would leave
 * the (map_level,L2,L3) key reserved under uq_map_l2_l3 and block re-assigning that
 * taxonomy elsewhere. Whole-set deactivate/reactivate still toggles is_active.
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
 * Business rule: a (map_level, L2, L3) combination may be owned by at most ONE
 * question set. Enforced in the DB by uq_map_l2_l3, but that surfaces as a raw
 * SQLSTATE 23000 duplicate-key error. This pre-check produces a friendly,
 * actionable message BEFORE any write, and reports the conflicting set id (via
 * $conflict_id) so the UI can offer an "edit the mapped set" shortcut.
 *
 * $self_set_id excludes a set's own map row (0 = none, e.g. on create).
 * The DB unique key spans active AND inactive rows, so we check both and word
 * the message according to whether the conflicting set is currently active.
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

$current_user = $_SESSION['name'] ?? '';
$tab_get      = $_GET['tab'] ?? 'questions';
$tab          = in_array($tab_get, ['questions', 'requestors', 'quantitative']) ? $tab_get : 'questions';

// ══════════════════════════════════════════════════════════════════════════
// POST handlers — processed BEFORE any HTML output (including header.php) so
// the header('Location: ...') redirect below actually works. Results are
// handed to the next request via a one-shot session flash.
// ══════════════════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action     = $_POST['action'] ?? '';
    $redirect_tab = $_POST['redirect_tab'] ?? $tab;
    $tab        = in_array($redirect_tab, ['questions', 'requestors', 'quantitative']) ? $redirect_tab : 'questions';
    $flash      = null;
    $flash_type = 'success';
    $flash_conflict_set_id = null;
    $redirect   = null; // set by create to jump straight to the new set's detail page

    // ── AJAX pre-validation (read-only) ──────────────────────────────────────
    // Lets the New Question Set modal surface duplicate-taxonomy / validation
    // errors INSIDE the modal (keeping the user's typed values) instead of a
    // full-page redirect. Reuses the exact same validation helpers as the real
    // create path below and performs NO writes; the authoritative check still
    // runs in create_question_set.
    if ($action === 'validate_question_set') {
        header('Content-Type: application/json');
        $conflict_id = null;
        try {
            $code   = trim((string)($_POST['question_set_code'] ?? ''));
            $name   = trim((string)($_POST['question_set_name'] ?? ''));
            $l2_sel = (isset($_POST['taxonomy_l2']) && is_array($_POST['taxonomy_l2'])) ? $_POST['taxonomy_l2'] : [];
            $l3_sel = (isset($_POST['taxonomy_l3']) && is_array($_POST['taxonomy_l3'])) ? $_POST['taxonomy_l3'] : [];
            $is_def = !empty($_POST['taxonomy_is_default']);
            if ($code === '')           throw new RuntimeException('Question set code is required.');
            if ($name === '')           throw new RuntimeException('Question set name is required.');
            if (mb_strlen($code) > 100) throw new RuntimeException('Question set code is too long (max 100).');
            [$valid_l2, $valid_l3] = loadTaxonomyValidationSets($pdo);
            $desired = buildDesiredTaxonomyMaps($l2_sel, $l3_sel, $is_def, $valid_l2, $valid_l3);
            foreach ($desired as $d) {
                assertTaxonomyMapFree($pdo, $d['map_level'], $d['l2'], $d['l3'], 0, $conflict_id);
            }
            echo json_encode(['ok' => true]);
        } catch (RuntimeException $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'conflict_set_id' => $conflict_id]);
        } catch (\Throwable $e) {
            echo json_encode(['ok' => false, 'error' => 'Validation failed unexpectedly. Please try again.']);
        }
        exit;
    }

    try {
        switch ($action) {

            // ── Question set create (full taxonomy editing continues on detail page) ──
            case 'create_question_set': {
                $code   = trim((string)($_POST['question_set_code'] ?? ''));
                $name   = trim((string)($_POST['question_set_name'] ?? ''));
                $desc   = trim((string)($_POST['description'] ?? ''));
                $l2_sel = (isset($_POST['taxonomy_l2']) && is_array($_POST['taxonomy_l2'])) ? $_POST['taxonomy_l2'] : [];
                $l3_sel = (isset($_POST['taxonomy_l3']) && is_array($_POST['taxonomy_l3'])) ? $_POST['taxonomy_l3'] : [];
                $is_def = !empty($_POST['taxonomy_is_default']);

                if ($code === '')            throw new RuntimeException('Question set code is required.');
                if ($name === '')            throw new RuntimeException('Question set name is required.');
                if (mb_strlen($code) > 100)  throw new RuntimeException('Question set code is too long (max 100).');

                // Build + validate the desired mapping set against the known taxonomy.
                [$valid_l2, $valid_l3] = loadTaxonomyValidationSets($pdo);
                $desired = buildDesiredTaxonomyMaps($l2_sel, $l3_sel, $is_def, $valid_l2, $valid_l3);

                $act = 1; // new question sets are active by default.

                // Friendly pre-check BEFORE any write (self=0: new set owns no row yet).
                foreach ($desired as $d) {
                    assertTaxonomyMapFree($pdo, $d['map_level'], $d['l2'], $d['l3'], 0, $flash_conflict_set_id);
                }

                $pdo->beginTransaction();
                try {
                    $verStmt = $pdo->prepare("SELECT COALESCE(MAX(version),0) FROM vendor_eval_question_sets WHERE set_code = ?");
                    $verStmt->execute([$code]);
                    $nextVersion = (int)$verStmt->fetchColumn() + 1;

                    $ins = $pdo->prepare("INSERT INTO vendor_eval_question_sets
                            (set_code, set_name, version, is_active, description, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                    $ins->execute([$code, $name, $nextVersion, $act, ($desc !== '' ? $desc : null)]);
                    $set_id = (int)$pdo->lastInsertId();

                    syncTaxonomyMaps($pdo, $set_id, $desired, $act);
                    $pdo->commit();
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                $_SESSION['flash_message'] = 'Question set created. ' . summarizeTaxonomy($desired) . '. Now add its questions below.';
                $_SESSION['flash_type'] = 'success';
                // Jump straight into the detail page to build the questions.
                header('Location: master_vendor_evaluation_set.php?set_id=' . $set_id);
                exit;
            }

            // ── Question set soft delete / reactivate (never hard delete) ────
            case 'deactivate_question_set':
            case 'reactivate_question_set': {
                $set_id = (int)($_POST['set_id'] ?? 0);
                if ($set_id <= 0) throw new RuntimeException('Missing set_id.');
                $new_state = $action === 'reactivate_question_set' ? 1 : 0;

                $pdo->prepare("UPDATE vendor_eval_question_sets SET is_active = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$new_state, $set_id]);
                // Keep the routing map row in step so an inactive set stops matching POs.
                $pdo->prepare("UPDATE vendor_eval_question_set_taxonomy_map SET is_active = ? WHERE question_set_id = ?")
                    ->execute([$new_state, $set_id]);

                $flash = $new_state === 1
                    ? 'Question set reactivated. It is available for routing again.'
                    : 'Question set deactivated. It will no longer be used for routing.';
                break;
            }

            // ── Requestor Contact Master: update contact ─────────────────────
            case 'update_requestor': {
                $requestor_id = (int)($_POST['requestor_id'] ?? 0);
                if ($requestor_id <= 0) throw new RuntimeException('Missing requestor_id.');

                $email       = trim((string)($_POST['email'] ?? ''));
                $wa          = trim((string)($_POST['whatsapp_number'] ?? ''));
                $mgr_email   = trim((string)($_POST['manager_email'] ?? ''));

                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Requestor email is not a valid email address.');
                }
                if ($mgr_email !== '' && !filter_var($mgr_email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Manager email is not a valid email address.');
                }

                // v2 stores the manager contact as requestors.manager_email
                // directly; only the contact fields are written here.
                $upd = $pdo->prepare("UPDATE requestors
                                         SET email = ?, whatsapp_number = ?, manager_email = ?
                                       WHERE id = ?");
                $upd->execute([
                    $email !== '' ? $email : null,
                    $wa !== '' ? $wa : null,
                    $mgr_email !== '' ? $mgr_email : null,
                    $requestor_id,
                ]);
                $flash = 'Requestor contact updated.';
                break;
            }

            // ── Form 2 Team Contacts: add / update / remove ──────────────────
            case 'add_form2_contact': {
                $name  = trim((string)($_POST['name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $wa    = trim((string)($_POST['whatsapp_number'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));

                if ($name === '') throw new RuntimeException('Contact name is required.');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Contact email is not a valid email address.');
                }
                if (mb_strlen($notes) > 255) throw new RuntimeException('Notes are too long (max 255).');

                $ins = $pdo->prepare("INSERT INTO vendor_eval_v2_form2_team_contacts
                        (name, email, whatsapp_number, notes, added_at)
                    VALUES (?, ?, ?, ?, NOW())");
                $ins->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $wa !== '' ? $wa : null,
                    $notes !== '' ? $notes : null,
                ]);
                $flash = 'Form 2 contact added.';
                break;
            }

            case 'update_form2_contact': {
                $contact_id = (int)($_POST['contact_id'] ?? 0);
                if ($contact_id <= 0) throw new RuntimeException('Missing contact_id.');

                $name  = trim((string)($_POST['name'] ?? ''));
                $email = trim((string)($_POST['email'] ?? ''));
                $wa    = trim((string)($_POST['whatsapp_number'] ?? ''));
                $notes = trim((string)($_POST['notes'] ?? ''));

                if ($name === '') throw new RuntimeException('Contact name is required.');
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Contact email is not a valid email address.');
                }
                if (mb_strlen($notes) > 255) throw new RuntimeException('Notes are too long (max 255).');

                $upd = $pdo->prepare("UPDATE vendor_eval_v2_form2_team_contacts
                                         SET name = ?, email = ?, whatsapp_number = ?, notes = ?
                                       WHERE id = ?");
                $upd->execute([
                    $name,
                    $email !== '' ? $email : null,
                    $wa !== '' ? $wa : null,
                    $notes !== '' ? $notes : null,
                    $contact_id,
                ]);
                $flash = 'Form 2 contact updated.';
                break;
            }

            case 'delete_form2_contact': {
                $contact_id = (int)($_POST['contact_id'] ?? 0);
                if ($contact_id <= 0) throw new RuntimeException('Missing contact_id.');

                $pdo->prepare("DELETE FROM vendor_eval_v2_form2_team_contacts WHERE id = ?")
                    ->execute([$contact_id]);
                $flash = 'Form 2 contact removed.';
                break;
            }

            // ── Form 2 shared worklist token (regenerate invalidates the old link) ──
            case 'regenerate_form2_worklist_token': {
                ve2_form2_worklist_token_regenerate($pdo);
                $flash = 'Form 2 worklist link regenerated. The previous link no longer works — distribute the new one to the Operations and Project teams.';
                break;
            }

            // ── Quantitative Evaluation Config ───────────────────────────────
            case 'update_quant_eval': {
                $qual_weight = (int)($_POST['qualitative_weight_percent'] ?? 50);
                $otf_weight = (int)($_POST['otf_weight_percent'] ?? 25);
                $top_weight = (int)($_POST['top_weight_percent'] ?? 25);
                
                if ($qual_weight + $otf_weight + $top_weight !== 100) {
                    throw new RuntimeException('Total weight (Qualitative + OTF + TOP) must equal exactly 100%.');
                }
                
                $otf_3_max = (int)($_POST['otf_score_3_max_days'] ?? 0);
                $otf_2_max = (int)($_POST['otf_score_2_max_days'] ?? 2);
                $otf_1_min = (int)($_POST['otf_score_1_min_days'] ?? 3);
                
                $pdo->beginTransaction();
                try {
                    // Update config
                    $updConfig = $pdo->prepare("UPDATE quant_eval_config SET qualitative_weight_percent = ?, otf_weight_percent = ?, top_weight_percent = ?, otf_score_3_max_days = ?, otf_score_2_max_days = ?, otf_score_1_min_days = ? WHERE id = 1");
                    $updConfig->execute([$qual_weight, $otf_weight, $top_weight, $otf_3_max, $otf_2_max, $otf_1_min]);
                    
                    // Update TOP scores
                    if (isset($_POST['top_scores']) && is_array($_POST['top_scores'])) {
                        $updTop = $pdo->prepare("INSERT INTO quant_top_scores (top_value, score) VALUES (?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score)");
                        foreach ($_POST['top_scores'] as $top_val => $score) {
                            $updTop->execute([$top_val, (int)$score]);
                        }
                    }
                    
                    // Recalculate PRs OTF and TOP score
                    require_once '../includes/quant_eval.php';
                    $updated_prs = recalculate_all_quantitative_scores($pdo);
                    
                    $pdo->commit();
                    $flash = "Quantitative Evaluation configuration updated successfully. Recalculated $updated_prs PR records.";
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }
                break;
            }
        }
    } catch (RuntimeException $e) {
        $flash_type = 'error';
        $flash      = $e->getMessage();
    } catch (PDOException $e) {
        $flash_type = 'error';
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

    header('Location: master_vendor_evaluation.php?tab=' . urlencode($tab));
    exit;
}

// One-shot flash from a prior POST redirect (if any) — read once, then cleared.
$flash               = $_SESSION['flash_message'] ?? null;
$flash_type          = $_SESSION['flash_type'] ?? 'success';
$flash_conflict_set  = isset($_SESSION['flash_conflict_set_id']) ? (int)$_SESSION['flash_conflict_set_id'] : 0;
unset($_SESSION['flash_message'], $_SESSION['flash_type'], $_SESSION['flash_conflict_set_id']);

require_once '../includes/header.php';

// ── Form 2 shared worklist link (single global token; see
//    vendor_eval_v2_form2_worklist_token.php) ─────────────────────────────
$form2_token = ve2_form2_worklist_token_get($pdo);
$form2_worklist_url = null;
if ($form2_token !== null) {
    try {
        $form2_worklist_url = ve2_web_base_url() . '/public_eval_v2_project_worklist.php?token=' . urlencode($form2_token);
    } catch (\Throwable $e) {
        $form2_worklist_url = null; // unrecognised Host header — show the "not yet generated" state instead of a broken link
    }
}

// ══════════════════════════════════════════════════════════════════════════
// GET data — Question Sets tab (list only)
// ══════════════════════════════════════════════════════════════════════════
// One row per set (a set may own MANY taxonomy mappings; those are attached below).
$question_sets = $pdo->query("
    SELECT qs.*,
           (SELECT COUNT(*) FROM vendor_eval_questions q WHERE q.question_set_id = qs.id) AS question_count,
           (SELECT COUNT(*) FROM vendor_eval_questions q WHERE q.question_set_id = qs.id AND q.is_active = 1) AS active_question_count
      FROM vendor_eval_question_sets qs
     ORDER BY qs.is_active DESC, qs.set_code ASC, qs.version DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Attach every taxonomy mapping to its owning set (0..N per set).
$maps_by_set = [];
foreach ($pdo->query("
    SELECT question_set_id, map_level, taxonomy_level_2, taxonomy_level_3, is_active
      FROM vendor_eval_question_set_taxonomy_map
     ORDER BY map_level, taxonomy_level_2, taxonomy_level_3
")->fetchAll(PDO::FETCH_ASSOC) as $mr) {
    $maps_by_set[(int)$mr['question_set_id']][] = $mr;
}

// Taxonomy lists power the New Question Set modal's multi-select L2 + grouped L3 controls.
$taxonomy_rows = $pdo->query("SELECT DISTINCT level_2, level_3 FROM v_item_taxonomy ORDER BY level_2, level_3")->fetchAll(PDO::FETCH_ASSOC);
$l2_list   = [];
$l2_to_l3  = [];
foreach ($taxonomy_rows as $tr) {
    $l2 = trim((string)($tr['level_2'] ?? ''));
    $l3 = trim((string)($tr['level_3'] ?? ''));
    if ($l2 === '') continue;
    if (!in_array($l2, $l2_list, true)) $l2_list[] = $l2;
    if ($l3 !== '' && !in_array($l3, $l2_to_l3[$l2] ?? [], true)) $l2_to_l3[$l2][] = $l3;
}
sort($l2_list);

// ══════════════════════════════════════════════════════════════════════════
// GET data — Requestor Contact Master tab
// ══════════════════════════════════════════════════════════════════════════
$requestors = $pdo->query("
    SELECT r.*
      FROM requestors r
     ORDER BY r.requestor_name
")->fetchAll(PDO::FETCH_ASSOC);

$form2_contacts = $pdo->query("
    SELECT id, name, email, whatsapp_number, notes, added_at
      FROM vendor_eval_v2_form2_team_contacts
     ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════════════════
// GET data — Quantitative Evaluation tab
// ══════════════════════════════════════════════════════════════════════════
// Load config
$quant_config = $pdo->query("SELECT * FROM quant_eval_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
if (!$quant_config) {
    $quant_config = ['otf_weight_percent' => 50, 'top_weight_percent' => 50, 'otf_score_3_max_days' => 0, 'otf_score_2_max_days' => 2, 'otf_score_1_min_days' => 3];
}

// Load TOP list from master vendor and joined with scores
try {
    $top_list = $pdo->query("
        SELECT DISTINCT m.top, COALESCE(q.score, 0) as score
          FROM master_vendor m
          LEFT JOIN quant_top_scores q ON m.top = q.top_value
         WHERE m.top IS NOT NULL AND m.top != ''
         ORDER BY m.top
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $top_list = [];
}

?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>

<!-- Select2: searchable multi-select for taxonomy routing (same library/CDN already used on dashboard_mgr.php). -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<style>
    /* Vendor Eval taxonomy multi-selects (Select2) — compact styling aligned with Workspace Pro. */
    .ve2-tax .select2-container { z-index: 1060; }
    .ve2-tax .select2-selection--multiple {
        border-color: #d1d5db !important;
        border-radius: 0.375rem;
        min-height: 34px;
        max-height: 6.5rem;
        overflow-y: auto;
        padding: 1px 4px;
        font-size: 14px;
    }
    /* Selected chips: shorter, tidier, consistent spacing. */
    .ve2-tax .select2-selection__choice {
        font-size: 0.75rem;
        line-height: 1.15rem;
        padding: 0 6px;
        margin-top: 4px;
        margin-right: 4px;
    }
    /* Inline search + typed text ~14px, vertically centred in the shorter control. */
    .ve2-tax .select2-search--inline .select2-search__field { font-size: 14px; margin-top: 5px; }
    /* Placeholder: slightly smaller and muted. */
    .ve2-tax .select2-selection__placeholder { font-size: 13px; color: #9ca3af; }
    /* Dropdown options ~14px so the list matches the control and reads less bulky. */
    #newSetModal .select2-results__option { font-size: 14px; padding: 6px 10px; }
    /* Dropdown must sit above the z-50 modal overlay it is parented to. */
    #newSetModal .select2-container { z-index: 10000; }
    .select2-dropdown { z-index: 10000; }
</style>

<div class="max-w-7xl mx-auto py-2">
    <div class="mb-6 flex justify-between items-end border-b pb-4">
        <div>
            <h2 class="text-2xl font-bold text-gray-800">Master Vendor Evaluation</h2>
            <p class="text-gray-600">Manage Vendor Evaluation question sets and requestor contacts.</p>
        </div>
        <a href="dashboard_mgr.php" class="bg-gray-500 hover:bg-gray-600 text-white font-bold py-2 px-4 rounded transition shadow">&larr; Back</a>
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
            <?php if ($flash_conflict_set > 0): ?>
            <p class="mt-2">
                <a href="master_vendor_evaluation_set.php?set_id=<?= $flash_conflict_set ?>"
                   class="inline-block bg-white border border-red-300 text-red-700 hover:bg-red-50 font-bold py-1.5 px-3 rounded text-sm">
                    Edit the mapped set &rarr;
                </a>
            </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="flex gap-2 mb-6 border-b border-gray-200">
        <a href="?tab=questions"
           class="px-4 py-2 text-sm font-bold rounded-t-lg border-b-2 <?= $tab === 'questions' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            Question Sets
        </a>
        <a href="?tab=requestors"
           class="px-4 py-2 text-sm font-bold rounded-t-lg border-b-2 <?= $tab === 'requestors' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            Requestor Contacts
        </a>
        <a href="?tab=quantitative"
           class="px-4 py-2 text-sm font-bold rounded-t-lg border-b-2 <?= $tab === 'quantitative' ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-gray-500 hover:text-gray-700' ?>">
            Quantitative Evaluation
        </a>
    </div>

    <?php if ($tab === 'questions'): ?>
    <!-- ═══════════════════════════════ QUESTION SETS TAB (LIST ONLY) ═══════════════════════════════ -->

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
        <div class="flex justify-between items-center mb-4 border-b pb-2">
            <h3 class="text-lg font-bold">Question Sets</h3>
            <button type="button" onclick="openNewSetModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm shadow">+ New Question Set</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm whitespace-nowrap">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="p-3">Question Set</th>
                        <th class="p-3">Taxonomy Mapping</th>
                        <th class="p-3 text-center">Questions</th>
                        <th class="p-3 text-center">Status</th>
                        <th class="p-3 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($question_sets as $qs): ?>
                    <tr class="border-b hover:bg-gray-50 <?= (int)$qs['is_active'] === 1 ? '' : 'opacity-60' ?>">
                        <td class="p-3">
                            <div class="font-semibold text-gray-800"><?= h($qs['set_name']) ?></div>
                            <div class="font-mono text-[11px] text-indigo-600"><?= h($qs['set_code']) ?> <span class="text-gray-400">v<?= h($qs['version']) ?></span></div>
                        </td>
                        <td class="p-3 text-xs text-gray-600">
                            <?php
                                $set_maps = $maps_by_set[(int)$qs['id']] ?? [];
                                $has_default = false;
                                $chips = [];
                                foreach ($set_maps as $mm) {
                                    if ($mm['map_level'] === 'default') {
                                        $has_default = true;
                                    } elseif ($mm['map_level'] === 'l2') {
                                        $chips[] = ['L2', $mm['taxonomy_level_2']];
                                    } elseif ($mm['map_level'] === 'l3') {
                                        $chips[] = ['L3', $mm['taxonomy_level_2'] . ' › ' . $mm['taxonomy_level_3']];
                                    }
                                }
                            ?>
                            <?php if ($has_default): ?>
                                <span class="bg-indigo-50 border border-indigo-200 text-indigo-700 rounded px-2 py-0.5">Default · all categories</span>
                            <?php elseif (empty($chips)): ?>
                                <span class="text-amber-600 italic">not mapped</span>
                            <?php else: ?>
                                <div class="flex flex-wrap gap-1 max-w-md">
                                    <?php foreach (array_slice($chips, 0, 4) as $c): ?>
                                        <span class="bg-gray-100 border border-gray-200 rounded px-2 py-0.5"><span class="text-[9px] font-bold text-gray-400"><?= $c[0] ?></span> <?= h($c[1]) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (count($chips) > 4): ?>
                                        <span class="text-gray-400 self-center">+<?= count($chips) - 4 ?> more</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-center text-xs">
                            <span class="font-bold text-gray-800"><?= (int)$qs['active_question_count'] ?></span>
                            <span class="text-gray-400">/ <?= (int)$qs['question_count'] ?></span>
                        </td>
                        <td class="p-3 text-center">
                            <?php if ((int)$qs['is_active'] === 1): ?>
                                <span class="bg-green-100 text-green-800 py-1 px-2 rounded text-[10px] font-bold border border-green-200">Active</span>
                            <?php else: ?>
                                <span class="bg-gray-100 text-gray-500 py-1 px-2 rounded text-[10px] font-bold border border-gray-200">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="p-3 text-right">
                            <div class="inline-flex items-center gap-1 justify-end">
                                <a href="master_vendor_evaluation_set.php?set_id=<?= (int)$qs['id'] ?>" title="Open / edit question set"
                                   class="inline-flex items-center justify-center gap-1 h-8 px-3 rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100 text-xs font-bold">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    Open
                                </a>
                                <?php if ((int)$qs['is_active'] === 1): ?>
                                <form method="POST" class="inline" id="deactivateSetForm_<?= (int)$qs['id'] ?>">
                                    <input type="hidden" name="action" value="deactivate_question_set">
                                    <input type="hidden" name="set_id" value="<?= (int)$qs['id'] ?>">
                                    <input type="hidden" name="redirect_tab" value="questions">
                                    <button type="button" title="Deactivate question set"
                                            onclick="showConfirmModal('deactivateSetForm_<?= (int)$qs['id'] ?>', 'Deactivate Question Set', 'Deactivate this question set? It will no longer be used for routing.', true)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded border border-red-200 bg-red-50 text-red-700 hover:bg-red-100">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="inline" id="reactivateSetForm_<?= (int)$qs['id'] ?>">
                                    <input type="hidden" name="action" value="reactivate_question_set">
                                    <input type="hidden" name="set_id" value="<?= (int)$qs['id'] ?>">
                                    <input type="hidden" name="redirect_tab" value="questions">
                                    <button type="button" title="Reactivate question set"
                                            onclick="showConfirmModal('reactivateSetForm_<?= (int)$qs['id'] ?>', 'Reactivate Question Set', 'Reactivate this question set? It can be used for routing again.', false)"
                                            class="inline-flex items-center justify-center w-8 h-8 rounded border border-green-200 bg-green-50 text-green-700 hover:bg-green-100">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($question_sets)): ?>
                    <tr><td colspan="5" class="p-6 text-center text-gray-400 italic">No question sets yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- New Question Set modal -->
    <div id="newSetModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden">
            <div class="flex justify-between items-center px-6 py-4 border-b shrink-0">
                <h3 class="text-lg font-bold">New Question Set</h3>
                <button type="button" onclick="closeNewSetModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <form method="POST" id="newSetForm" class="flex flex-col min-h-0 flex-1">
                <input type="hidden" name="action" value="create_question_set">
                <input type="hidden" name="redirect_tab" value="questions">

                <!-- In-modal validation alert (populated by the AJAX pre-check). -->
                <div id="newSetModalError" class="hidden shrink-0 mx-6 mt-4 border-l-4 bg-red-100 border-red-500 text-red-700 p-3 rounded text-sm">
                    <p class="font-bold">Cannot create question set</p>
                    <p id="newSetModalErrorMsg"></p>
                    <p id="newSetModalErrorLink" class="mt-2 hidden">
                        <a href="#" class="inline-block bg-white border border-red-300 text-red-700 hover:bg-red-50 font-bold py-1 px-3 rounded text-xs">Edit the mapped set &rarr;</a>
                    </p>
                </div>

                <div class="flex-1 min-h-0 overflow-y-auto px-6 py-4 grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold mb-1">Question Set Code</label>
                        <input type="text" name="question_set_code" required maxlength="100"
                               placeholder="VE_L2_L3_V1" class="w-full p-2 border rounded text-sm font-mono">
                        <p class="text-[10px] text-gray-400 mt-1">Use a stable code, e.g. VE_OUTSOURCING_PARKING_V1</p>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold mb-1">Question Set Name</label>
                        <input type="text" name="question_set_name" required
                               placeholder="Vendor Evaluation Form..." class="w-full p-2 border rounded text-sm">
                    </div>

                    <div class="md:col-span-2 border-t pt-4 ve2-tax grid grid-cols-1 md:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-xs font-bold mb-1">Sub Categories <span class="text-gray-400 font-normal">— select one or more</span></label>
                            <select id="new_taxonomy_l2" name="taxonomy_l2[]" multiple class="w-full">
                                <?php foreach ($l2_list as $l2opt): ?>
                                    <option value="<?= h($l2opt) ?>"><?= h($l2opt) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-[10px] text-gray-500 mt-1"><span id="new_l2_counter"></span></p>
                        </div>
                        <div>
                            <label class="block text-xs font-bold mb-1">Commodities <span class="text-gray-400 font-normal">— select one or more</span></label>
                            <select id="new_taxonomy_l3" name="taxonomy_l3[]" multiple class="w-full"></select>
                            <p class="text-[10px] text-gray-500 mt-1"><span id="new_l3_counter"></span> <span class="text-gray-400">Narrowed to the Sub Categories you pick above.</span></p>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-xs font-bold mb-1">Description / Notes <span class="text-gray-400 font-normal">(optional)</span></label>
                        <textarea name="description" rows="2" class="w-full p-2 border rounded text-sm"></textarea>
                    </div>
                </div>

                <div class="flex justify-end gap-2 px-6 py-4 border-t shrink-0 bg-white">
                    <button type="button" onclick="closeNewSetModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded text-sm shadow">Create &amp; Add Questions</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm action modal (deactivate/reactivate question set) -->
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

    <script>
        // Reusable confirm modal for deactivate/reactivate actions (replaces native confirm()).
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
            if (_confirmModalFormId) {
                document.getElementById(_confirmModalFormId)?.submit();
            }
            closeConfirmModal();
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') closeConfirmModal();
        });

        // ── New Question Set modal: searchable dependent taxonomy ──
        // New sets are always specific taxonomy-based; taxonomy_is_default is not
        // posted, so the backend records is_default = false (see !empty() check).
        // The single Default Fallback set is managed on the edit page, not created here.
        const NEW_L2_TO_L3 = <?= json_encode($l2_to_l3, JSON_UNESCAPED_UNICODE) ?>;
        let _newTaxReady = false;

        function updateNewCounters() {
            const n2 = ($('#new_taxonomy_l2').val() || []).length;
            const n3 = ($('#new_taxonomy_l3').val() || []).length;
            document.getElementById('new_l2_counter').textContent = n2 ? (n2 + ' selected') : '';
            document.getElementById('new_l3_counter').textContent = n3 ? (n3 + ' selected') : '';
        }

        // Rebuild the Commodity (L3) options scoped to the selected Sub Categories (L2).
        // No L2 selected → every commodity is available. Selections whose parent L2 is
        // no longer selected are dropped (no orphaned commodities).
        function rebuildNewL3(preserve) {
            const $l2 = $('#new_taxonomy_l2');
            const $l3 = $('#new_taxonomy_l3');
            const selectedL2 = $l2.val() || [];
            const prev = preserve ? ($l3.val() || []) : [];
            const l2keys = selectedL2.length ? selectedL2 : Object.keys(NEW_L2_TO_L3);
            const allowed = new Set();
            if ($l3.data('select2')) $l3.select2('destroy');
            $l3.empty();
            l2keys.forEach(function (l2) {
                const l3s = NEW_L2_TO_L3[l2] || [];
                if (!l3s.length) return;
                const og = document.createElement('optgroup');
                og.label = l2;
                l3s.forEach(function (l3) {
                    const val = JSON.stringify({ l2: l2, l3: l3 });
                    allowed.add(val);
                    og.appendChild(new Option(l3, val, false, false));
                });
                $l3.append(og);
            });
            const keep = prev.filter(function (v) { return allowed.has(v); });
            $l3.val(keep);
            $l3.select2({ dropdownParent: $('#newSetModal'), placeholder: 'Select Commodities…', width: '100%', closeOnSelect: false });
            $l3.off('change.ve2').on('change.ve2', updateNewCounters);
            updateNewCounters();
        }

        function initNewSetTaxonomy() {
            if (_newTaxReady) return;
            $('#new_taxonomy_l2').select2({ dropdownParent: $('#newSetModal'), placeholder: 'Select Sub Categories…', width: '100%', closeOnSelect: false })
                .on('change.ve2', function () { rebuildNewL3(true); });
            rebuildNewL3(false);
            _newTaxReady = true;
        }

        function openNewSetModal() {
            document.getElementById('newSetModal').classList.remove('hidden');
            initNewSetTaxonomy();
        }
        function closeNewSetModal() {
            document.getElementById('newSetModal').classList.add('hidden');
            hideNewSetModalError();
        }

        // In-modal validation error display (from the AJAX pre-check).
        function showNewSetModalError(msg, conflictSetId) {
            document.getElementById('newSetModalErrorMsg').textContent = msg || 'Please review the form and try again.';
            const linkWrap = document.getElementById('newSetModalErrorLink');
            const link = linkWrap.querySelector('a');
            if (conflictSetId) {
                link.href = 'master_vendor_evaluation_set.php?set_id=' + encodeURIComponent(conflictSetId);
                linkWrap.classList.remove('hidden');
            } else {
                linkWrap.classList.add('hidden');
            }
            const box = document.getElementById('newSetModalError');
            box.classList.remove('hidden');
            box.scrollIntoView({ block: 'nearest' });
        }
        function hideNewSetModalError() {
            document.getElementById('newSetModalError')?.classList.add('hidden');
        }

        // Save → AJAX validate → stay in modal on error (values preserved) → native
        // submit (server re-validates authoritatively + redirects) only when valid.
        document.getElementById('newSetForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const form = this;
            const fd = new FormData(form);
            fd.set('action', 'validate_question_set');
            hideNewSetModalError();
            const btn = form.querySelector('button[type="submit"]');
            if (btn) btn.disabled = true;
            fetch('master_vendor_evaluation.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (btn) btn.disabled = false;
                    if (res && res.ok) { form.submit(); }
                    else { showNewSetModalError(res ? res.error : null, res ? res.conflict_set_id : null); }
                })
                .catch(function () {
                    if (btn) btn.disabled = false;
                    showNewSetModalError('Could not reach the server to validate. Please check your connection and try again.', null);
                });
        });

        window.addEventListener('DOMContentLoaded', function () {
            initNewSetTaxonomy();   // init up-front so the first modal open is instant
        });
    </script>

    <?php elseif ($tab === 'requestors'): ?>
    <!-- ═══════════════════════════════ REQUESTOR CONTACTS TAB ═══════════════════════════════ -->

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
        <div class="flex justify-between items-center mb-4 border-b pb-2 flex-wrap gap-2">
            <div>
                <h3 class="text-lg font-bold">Form 2 (Project Retail — New Store) Team Contacts</h3>
                <p class="text-xs text-gray-500 mt-1">List of people to notify regarding New Store vendor evaluations.</p>
            </div>
            <button type="button" onclick="openForm2ContactAdd()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-4 rounded text-sm shadow">+ Add Contact</button>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="p-2">Name</th>
                        <th class="p-2">Email</th>
                        <th class="p-2">WhatsApp</th>
                        <th class="p-2">Notes</th>
                        <th class="p-2 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($form2_contacts as $c): ?>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="p-2 font-bold text-gray-800"><?= h($c['name']) ?></td>
                        <td class="p-2"><?= h((string)($c['email'] ?? '')) ?></td>
                        <td class="p-2"><?= h((string)($c['whatsapp_number'] ?? '')) ?></td>
                        <td class="p-2 whitespace-normal max-w-xs"><?= h((string)($c['notes'] ?? '')) ?></td>
                        <td class="p-2 text-center whitespace-nowrap">
                            <button type="button" onclick="openForm2ContactEdit(<?= (int)$c['id'] ?>)" title="Edit"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('Remove ' + <?= json_encode($c['name'], JSON_UNESCAPED_UNICODE) ?> + ' from the Form 2 contact list?');">
                                <input type="hidden" name="action" value="delete_form2_contact">
                                <input type="hidden" name="redirect_tab" value="requestors">
                                <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" title="Remove"
                                        class="inline-flex items-center justify-center w-8 h-8 rounded border border-red-200 bg-red-50 text-red-700 hover:bg-red-100">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($form2_contacts)): ?>
                    <tr><td colspan="5" class="p-6 text-center text-gray-400 italic">No Form 2 contacts yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <details class="mt-5 border-t pt-3">
            <summary class="text-xs font-bold text-gray-500 cursor-pointer hover:text-gray-700 select-none">Advanced: Shared Worklist Link</summary>
            <div class="mt-3">
                <p class="text-xs text-gray-500 mb-3">
                    One global link, shared by both the Operations (~4 people) and Project (~10 people) teams — not tied to any
                    individual, site, or PO. It always shows the live list of currently eligible New Store POs.
                    Regenerating replaces this link immediately; the old one stops working. Distribution is normally handled by
                    the automated reminder sender — only use this if you need to share the link manually or rotate it for security.
                </p>
                <?php if ($form2_worklist_url !== null): ?>
                <div class="flex flex-wrap items-center gap-3">
                    <input type="text" readonly value="<?= h($form2_worklist_url) ?>"
                           onclick="this.select()"
                           class="flex-1 min-w-[260px] p-2 border rounded text-sm font-mono bg-gray-50 text-gray-700">
                    <form method="POST" class="inline"
                          onsubmit="return confirm('Regenerate the Form 2 worklist link? The current link will stop working immediately for anyone who still has it.');">
                        <input type="hidden" name="action" value="regenerate_form2_worklist_token">
                        <input type="hidden" name="redirect_tab" value="requestors">
                        <button type="submit"
                                class="bg-orange-600 hover:bg-orange-700 text-white text-sm font-bold py-2 px-4 rounded shadow transition">
                            Regenerate Link
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="regenerate_form2_worklist_token">
                    <input type="hidden" name="redirect_tab" value="requestors">
                    <button type="submit"
                            class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-bold py-2 px-4 rounded shadow transition">
                        Generate Link
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </details>

        <div class="mt-5 border-t-2 border-red-300 pt-4">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                <div>
                    <h4 class="text-sm font-bold text-red-700">⚠ Form 2 Reminder Sender — LIVE SEND</h4>
                    <p class="text-xs text-red-700 mt-0.5 font-semibold">
                        This button sends REAL WhatsApp messages and REAL emails to the real
                        <?= (int)count($form2_contacts) ?>-person roster above, for whichever New Store POs
                        are currently due. It is NOT a preview or a dry run — clicking it, then confirming,
                        notifies real people immediately.
                    </p>
                </div>
                <button type="button" id="f2ReminderBtn" onclick="shootForm2Reminder()"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded text-sm shadow disabled:opacity-50 whitespace-nowrap border-2 border-red-800">
                    ⚠ Send Real Reminder
                </button>
            </div>
            <pre id="f2ReminderResult" class="hidden mt-3 text-xs bg-gray-50 border border-gray-200 rounded p-3 whitespace-pre-wrap font-mono max-h-96 overflow-y-auto"></pre>
        </div>

        <div class="mt-5 border-t-2 border-amber-300 pt-4">
            <div class="flex items-center justify-between flex-wrap gap-2 mb-2">
                <div>
                    <h4 class="text-sm font-bold text-amber-700">↺ Re-send failed WhatsApp only</h4>
                    <p class="text-xs text-amber-700 mt-0.5 font-semibold">
                        Sends ONLY the WhatsApp copy (never email) to the roster, for the currently
                        eligible New Store POs — ignoring the weekly cadence. Anyone who already received
                        the identical WhatsApp is skipped, so this is safe to click again and will never
                        send the same message twice.
                    </p>
                </div>
                <button type="button" id="f2WaResendBtn" onclick="previewResendWa()"
                        class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-4 rounded text-sm shadow disabled:opacity-50 whitespace-nowrap border-2 border-amber-800">
                    ↺ Re-send WhatsApp
                </button>
            </div>
            <pre id="f2WaResendResult" class="hidden mt-3 text-xs bg-gray-50 border border-gray-200 rounded p-3 whitespace-pre-wrap font-mono max-h-96 overflow-y-auto"></pre>
        </div>
    </div>

    <!-- Form 2 reminder LIVE SEND confirmation modal — replaces the native
         window.confirm() so the confirm step matches the app's own modal styling.
         The live send is STILL only reachable via confirmF2Reminder() below, wired
         to this modal's confirm button — the safety gate is unchanged, only the UI. -->
    <div id="f2ReminderConfirmModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6 border-t-4 border-red-600">
            <h3 class="text-lg font-bold mb-3 text-red-700 flex items-center gap-2"><span>⚠</span> Confirm LIVE Send</h3>
            <div class="text-sm text-gray-700 space-y-3 border-y py-4">
                <p>
                    This will send <strong class="text-red-700">REAL WhatsApp + REAL email</strong> to all
                    <strong id="f2ConfirmRecipients">0</strong> real people on the Form 2 roster, about
                    <strong id="f2ConfirmTotal">0</strong> real PO(s)
                    (<span id="f2ConfirmFirst">0</span> new, <span id="f2ConfirmWeekly">0</span> follow-up).
                </p>
                <p class="font-bold text-red-700">This is NOT a test or a preview. Messages go out immediately.</p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="cancelF2Reminder()"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                <button type="button" onclick="confirmF2Reminder()"
                        class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-6 rounded text-sm shadow border-2 border-red-800">⚠ Yes, send now</button>
            </div>
        </div>
    </div>

    <!-- Form 2 WhatsApp-only RE-SEND confirmation modal. The live re-send is only
         reachable via confirmResendWa() below, wired to this modal's button. -->
    <div id="f2WaResendConfirmModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6 border-t-4 border-amber-600">
            <h3 class="text-lg font-bold mb-3 text-amber-700 flex items-center gap-2"><span>↺</span> Confirm WhatsApp re-send</h3>
            <div class="text-sm text-gray-700 space-y-3 border-y py-4">
                <p>
                    This will send <strong class="text-amber-700">REAL WhatsApp only</strong> (no email) to
                    <strong id="f2WaConfirmRecipients">0</strong> recipient(s) on the Form 2 roster, about
                    <strong id="f2WaConfirmPos">0</strong> PO(s). <span id="f2WaConfirmSkip"></span>
                </p>
                <p class="font-bold text-amber-700">Recipients who already got this exact WhatsApp are skipped — it will not send twice.</p>
            </div>
            <div class="flex justify-end gap-2 pt-4">
                <button type="button" onclick="cancelResendWa()"
                        class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                <button type="button" onclick="confirmResendWa()"
                        class="bg-amber-600 hover:bg-amber-700 text-white font-bold py-2 px-6 rounded text-sm shadow border-2 border-amber-800">↺ Yes, re-send WhatsApp</button>
            </div>
        </div>
    </div>

    <script>
        const F2_REMINDER_IDLE_LABEL = '⚠ Send Real Reminder';

        function resetF2ReminderBtn() {
            const btn = document.getElementById('f2ReminderBtn');
            btn.disabled = false;
            btn.textContent = F2_REMINDER_IDLE_LABEL;
        }

        // Steps 1–2: read-only preview, then open the confirmation modal.
        function shootForm2Reminder() {
            const btn = document.getElementById('f2ReminderBtn');
            const out = document.getElementById('f2ReminderResult');
            btn.disabled = true;
            btn.textContent = 'Checking what is due…';
            out.classList.remove('hidden');
            out.textContent = 'Checking which POs are currently due (read-only check — nothing sent yet)…';

            // Step 1: read-only preview call — this NEVER sends anything (see
            // ajax_preview_form2_reminder.php, hard-coded preview=true).
            fetch('ajax_preview_form2_reminder.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.result) {
                        resetF2ReminderBtn();
                        out.textContent = (res && res.message) ? res.message : 'Could not check due POs — nothing was sent.';
                        return;
                    }
                    const r = res.result;
                    const firstPos  = Object.keys(r.due_first  || {});
                    const weeklyPos = Object.keys(r.due_weekly || {});
                    const totalPos  = firstPos.length + weeklyPos.length;
                    const recipients = r.recipient_count || 0;

                    if (totalPos === 0) {
                        resetF2ReminderBtn();
                        out.textContent = 'Nothing is currently due — no send needed. Nothing was sent.';
                        return;
                    }

                    // Step 2: explicit human confirmation via the on-page modal (replaces window.confirm).
                    document.getElementById('f2ConfirmRecipients').textContent = recipients;
                    document.getElementById('f2ConfirmTotal').textContent = totalPos;
                    document.getElementById('f2ConfirmFirst').textContent = firstPos.length;
                    document.getElementById('f2ConfirmWeekly').textContent = weeklyPos.length;
                    out.textContent = 'Ready to send — confirm in the dialog. Nothing sent yet.';
                    document.getElementById('f2ReminderConfirmModal').classList.remove('hidden');
                    // Button stays disabled while the modal decides; cancel/confirm re-enable it.
                })
                .catch(function () {
                    resetF2ReminderBtn();
                    out.textContent = 'Could not reach the server — nothing was sent.';
                });
        }

        // Modal Cancel — nothing is sent.
        function cancelF2Reminder() {
            document.getElementById('f2ReminderConfirmModal').classList.add('hidden');
            resetF2ReminderBtn();
            document.getElementById('f2ReminderResult').textContent = 'Cancelled by user — nothing was sent.';
        }

        // Modal Confirm — Step 3: the actual live send, only reachable after this explicit click.
        function confirmF2Reminder() {
            document.getElementById('f2ReminderConfirmModal').classList.add('hidden');
            const btn = document.getElementById('f2ReminderBtn');
            const out = document.getElementById('f2ReminderResult');
            btn.disabled = true;
            btn.textContent = 'Sending…';
            out.textContent = 'Sending real WA/email now…';

            fetch('ajax_send_form2_reminder.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r2) { return r2.json(); })
                .then(function (res2) {
                    resetF2ReminderBtn();
                    if (!res2 || !res2.result) {
                        out.textContent = (res2 && res2.message) ? res2.message : 'Unexpected response from the server.';
                        return;
                    }
                    const rr = res2.result;
                    const firstPos2  = Object.keys(rr.due_first  || {});
                    const weeklyPos2 = Object.keys(rr.due_weekly || {});
                    let text = 'LIVE SEND COMPLETE.\n\n';
                    text += 'New (first-send)   : ' + firstPos2.length + (firstPos2.length ? '  [' + firstPos2.join(', ') + ']' : '') + '\n';
                    text += 'Follow-up (weekly) : ' + weeklyPos2.length + (weeklyPos2.length ? '  [' + weeklyPos2.join(', ') + ']' : '') + '\n';
                    text += 'Already complete   : ' + rr.skipped_complete + ' (both teams submitted)\n';
                    text += 'Recipients         : ' + rr.recipient_count + '\n';
                    text += 'Email sent/failed  : ' + rr.email_sent + ' / ' + rr.email_failed + '\n';
                    text += 'WA sent/failed     : ' + rr.wa_sent + ' / ' + rr.wa_failed + '\n\n';
                    if (rr.recipients && rr.recipients.length) {
                        rr.recipients.forEach(function (rec) {
                            text += '  - ' + rec.name + '  email=' + (rec.email.recipient || '(none)') + ' (' + rec.email.status + ')'
                                  + '  wa=' + (rec.wa.recipient || '(none)') + ' (' + rec.wa.status + ')\n';
                        });
                        text += '\n';
                    }
                    if (rr.warnings && rr.warnings.length) { text += '\nWARNINGS:\n' + rr.warnings.join('\n') + '\n'; }
                    if (rr.errors && rr.errors.length)     { text += '\nERRORS:\n'   + rr.errors.join('\n')   + '\n'; }
                    out.textContent = text;
                })
                .catch(function () {
                    resetF2ReminderBtn();
                    out.textContent = 'Could not reach the server while sending — check the invite log before retrying, some messages may already have gone out.';
                });
        }
    </script>

    <script>
        const F2_WA_RESEND_IDLE_LABEL = '↺ Re-send WhatsApp';

        function resetF2WaResendBtn() {
            const btn = document.getElementById('f2WaResendBtn');
            btn.disabled = false;
            btn.textContent = F2_WA_RESEND_IDLE_LABEL;
        }

        // Step 1: read-only preview (never sends), then open the confirm modal.
        function previewResendWa() {
            const btn = document.getElementById('f2WaResendBtn');
            const out = document.getElementById('f2WaResendResult');
            btn.disabled = true;
            btn.textContent = 'Checking…';
            out.classList.remove('hidden');
            out.textContent = 'Checking which WhatsApp still need sending (read-only — nothing sent yet)…';

            fetch('ajax_preview_resend_form2_wa.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.success || !res.result) {
                        resetF2WaResendBtn();
                        out.textContent = (res && res.message) ? res.message : 'Could not check — nothing was sent.';
                        return;
                    }
                    const r = res.result;
                    const would   = r.would_send || 0;
                    const skipped = r.wa_skipped || 0;
                    const pos     = r.po_count || 0;
                    if (would === 0) {
                        resetF2WaResendBtn();
                        out.textContent = (skipped > 0)
                            ? ('Nothing to re-send — all ' + skipped + ' recipient(s) already have this WhatsApp. Nothing was sent.')
                            : 'Nothing eligible to re-send right now. Nothing was sent.';
                        return;
                    }
                    document.getElementById('f2WaConfirmRecipients').textContent = would;
                    document.getElementById('f2WaConfirmPos').textContent = pos;
                    document.getElementById('f2WaConfirmSkip').textContent =
                        skipped > 0 ? (skipped + ' recipient(s) already have it and will be skipped.') : '';
                    out.textContent = 'Ready to re-send — confirm in the dialog. Nothing sent yet.';
                    document.getElementById('f2WaResendConfirmModal').classList.remove('hidden');
                })
                .catch(function () {
                    resetF2WaResendBtn();
                    out.textContent = 'Could not reach the server — nothing was sent.';
                });
        }

        // Modal Cancel — nothing is sent.
        function cancelResendWa() {
            document.getElementById('f2WaResendConfirmModal').classList.add('hidden');
            resetF2WaResendBtn();
            document.getElementById('f2WaResendResult').textContent = 'Cancelled by user — nothing was sent.';
        }

        // Modal Confirm — the live WhatsApp-only re-send, only reachable after this explicit click.
        function confirmResendWa() {
            document.getElementById('f2WaResendConfirmModal').classList.add('hidden');
            const btn = document.getElementById('f2WaResendBtn');
            const out = document.getElementById('f2WaResendResult');
            btn.disabled = true;
            btn.textContent = 'Re-sending…';
            out.textContent = 'Re-sending WhatsApp now…';

            fetch('ajax_resend_form2_wa.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r2) { return r2.json(); })
                .then(function (res2) {
                    resetF2WaResendBtn();
                    if (!res2 || !res2.result) {
                        out.textContent = (res2 && res2.message) ? res2.message : 'Unexpected response from the server.';
                        return;
                    }
                    const rr = res2.result;
                    let text = 'WHATSAPP RE-SEND COMPLETE.\n\n';
                    text += 'POs referenced : ' + (rr.po_count || 0) + '\n';
                    text += 'WA sent        : ' + (rr.wa_sent || 0) + '\n';
                    text += 'WA failed      : ' + (rr.wa_failed || 0) + '\n';
                    text += 'Skipped        : ' + (rr.wa_skipped || 0) + ' (already delivered or no number)\n\n';
                    if (rr.recipients && rr.recipients.length) {
                        rr.recipients.forEach(function (rec) {
                            text += '  - ' + rec.name + '  wa=' + (rec.wa || '(none)') + ' (' + rec.status + ')'
                                  + (rec.error ? ' — ' + rec.error : '') + '\n';
                        });
                        text += '\n';
                    }
                    if (rr.errors && rr.errors.length) { text += '\nERRORS:\n' + rr.errors.join('\n') + '\n'; }
                    out.textContent = text;
                })
                .catch(function () {
                    resetF2WaResendBtn();
                    out.textContent = 'Could not reach the server while re-sending — check the invite log before retrying.';
                });
        }
    </script>

    <!-- Single reusable add/edit modal for Form 2 Team Contacts -->
    <div id="form2ContactModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-lg w-full p-6">
            <h3 class="text-lg font-bold mb-4 border-b pb-2" id="f2c_modal_title">Add Form 2 Contact</h3>
            <form method="POST" class="grid grid-cols-1 gap-3">
                <input type="hidden" name="action" id="f2c_modal_action" value="add_form2_contact">
                <input type="hidden" name="redirect_tab" value="requestors">
                <input type="hidden" name="contact_id" id="f2c_modal_id">
                <div><label class="block text-xs font-bold mb-1">Name</label><input type="text" name="name" id="f2c_modal_name" required class="w-full p-2 border rounded text-sm"></div>
                <div><label class="block text-xs font-bold mb-1">Email</label><input type="email" name="email" id="f2c_modal_email" class="w-full p-2 border rounded text-sm"></div>
                <div><label class="block text-xs font-bold mb-1">WhatsApp</label><input type="text" name="whatsapp_number" id="f2c_modal_wa" class="w-full p-2 border rounded text-sm"></div>
                <div><label class="block text-xs font-bold mb-1">Notes</label><input type="text" name="notes" id="f2c_modal_notes" maxlength="255" class="w-full p-2 border rounded text-sm"></div>
                <div class="flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeForm2ContactModal()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded text-sm shadow">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const FORM2_CONTACTS_DATA = <?= json_encode(array_map(fn($c) => [
            'id' => (int)$c['id'],
            'name' => $c['name'],
            'email' => $c['email'],
            'whatsapp_number' => $c['whatsapp_number'],
            'notes' => $c['notes'],
        ], $form2_contacts), JSON_UNESCAPED_UNICODE) ?>;

        function openForm2ContactAdd() {
            document.getElementById('f2c_modal_title').textContent = 'Add Form 2 Contact';
            document.getElementById('f2c_modal_action').value = 'add_form2_contact';
            document.getElementById('f2c_modal_id').value = '';
            document.getElementById('f2c_modal_name').value = '';
            document.getElementById('f2c_modal_email').value = '';
            document.getElementById('f2c_modal_wa').value = '';
            document.getElementById('f2c_modal_notes').value = '';
            document.getElementById('form2ContactModal').classList.remove('hidden');
        }
        function openForm2ContactEdit(id) {
            const c = FORM2_CONTACTS_DATA.find(function (x) { return x.id === id; });
            if (!c) return;
            document.getElementById('f2c_modal_title').textContent = 'Edit Form 2 Contact — ' + (c.name || '');
            document.getElementById('f2c_modal_action').value = 'update_form2_contact';
            document.getElementById('f2c_modal_id').value = c.id;
            document.getElementById('f2c_modal_name').value = c.name || '';
            document.getElementById('f2c_modal_email').value = c.email || '';
            document.getElementById('f2c_modal_wa').value = c.whatsapp_number || '';
            document.getElementById('f2c_modal_notes').value = c.notes || '';
            document.getElementById('form2ContactModal').classList.remove('hidden');
        }
        function closeForm2ContactModal() {
            document.getElementById('form2ContactModal').classList.add('hidden');
        }
    </script>

    <div class="bg-white shadow-md rounded-lg p-4 border border-gray-200 mb-4 flex flex-wrap items-center gap-3">
        <label for="requestorSearchInput" class="text-xs font-bold text-gray-600 whitespace-nowrap">Search</label>
        <input type="text" id="requestorSearchInput" placeholder="Search requestor / email..."
               class="flex-1 min-w-[180px] p-2 border rounded text-sm">
    </div>

    <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200 mb-8">
        <div class="flex justify-between items-center mb-4 border-b pb-2 flex-wrap gap-2">
            <h3 class="text-lg font-bold">Requestor Contact Master</h3>
        </div>
        <div class="overflow-x-auto">
            <table id="requestorTable" class="w-full text-left text-xs whitespace-nowrap">
                <thead class="bg-gray-100 border-b">
                    <tr>
                        <th class="p-2">Requestor</th>
                        <th class="p-2">Email</th>
                        <th class="p-2">WhatsApp</th>
                        <th class="p-2">Manager Email</th>
                        <th class="p-2 text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requestors as $r):
                        $effective_mgr_email = trim((string)($r['manager_email'] ?? ''));
                        $email_val = trim((string)($r['email'] ?? ''));
                        $wa_val    = trim((string)($r['whatsapp_number'] ?? ''));
                        $missing_email = $email_val === '';
                        $missing_wa    = $wa_val === '';
                        $missing_mgr   = $effective_mgr_email === '';
                    ?>
                    <tr class="border-b hover:bg-gray-50">
                        <td class="p-2 font-bold text-gray-800"><?= h($r['requestor_name']) ?></td>
                        <td class="p-2">
                            <?php if ($missing_email): ?>
                                <span class="inline-flex items-center bg-red-50 text-red-700 border border-red-200 rounded px-2 py-0.5 text-[10px] font-bold">Missing Requestor Email</span>
                            <?php else: ?>
                                <?= h($email_val) ?>
                            <?php endif; ?>
                        </td>
                        <td class="p-2">
                            <?php if ($missing_wa): ?>
                                <span class="inline-flex items-center bg-red-50 text-red-700 border border-red-200 rounded px-2 py-0.5 text-[10px] font-bold">Missing WhatsApp</span>
                            <?php else: ?>
                                <?= h($wa_val) ?>
                            <?php endif; ?>
                        </td>
                        <td class="p-2">
                            <?php if ($missing_mgr): ?>
                                <span class="inline-flex items-center bg-red-50 text-red-700 border border-red-200 rounded px-2 py-0.5 text-[10px] font-bold">Missing Manager Email</span>
                            <?php else: ?>
                                <?= h($effective_mgr_email) ?>
                            <?php endif; ?>
                        </td>
                        <td class="p-2 text-center">
                            <button type="button" onclick="openRequestorEdit(<?= (int)$r['id'] ?>)" title="Edit"
                                    class="inline-flex items-center justify-center w-8 h-8 rounded border border-blue-200 bg-blue-50 text-blue-700 hover:bg-blue-100">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($requestors)): ?>
                    <tr><td colspan="5" class="p-6 text-center text-gray-400 italic">No requestors yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Single reusable edit modal (avoids breaking DataTables row/column counting) -->
    <div id="requestorEditModal" class="hidden fixed inset-0 bg-slate-800 bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full p-6">
            <h3 class="text-lg font-bold mb-4 border-b pb-2">Edit Requestor Contact — <span id="rq_modal_name"></span></h3>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <input type="hidden" name="action" value="update_requestor">
                <input type="hidden" name="redirect_tab" value="requestors">
                <input type="hidden" name="requestor_id" id="rq_modal_id">
                <div><label class="block text-xs font-bold mb-1">Email</label><input type="email" name="email" id="rq_modal_email" class="w-full p-2 border rounded text-sm"></div>
                <div><label class="block text-xs font-bold mb-1">WhatsApp</label><input type="text" name="whatsapp_number" id="rq_modal_wa" class="w-full p-2 border rounded text-sm"></div>
                <div class="md:col-span-2"><label class="block text-xs font-bold mb-1">Manager Email</label><input type="email" name="manager_email" id="rq_modal_mgr_email" class="w-full p-2 border rounded text-sm"></div>
                <div class="md:col-span-2 flex justify-end gap-2 pt-2 border-t">
                    <button type="button" onclick="closeRequestorEdit()" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-4 rounded text-sm">Cancel</button>
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded text-sm shadow">Save</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const REQUESTORS_DATA = <?= json_encode(array_map(fn($r) => [
            'id' => (int)$r['id'],
            'requestor_name' => $r['requestor_name'],
            'email' => $r['email'],
            'whatsapp_number' => $r['whatsapp_number'],
            'manager_email' => $r['manager_email'],
        ], $requestors), JSON_UNESCAPED_UNICODE) ?>;

        function openRequestorEdit(id) {
            const r = REQUESTORS_DATA.find(function (x) { return x.id === id; });
            if (!r) return;
            document.getElementById('rq_modal_name').textContent = r.requestor_name || '';
            document.getElementById('rq_modal_id').value = r.id;
            document.getElementById('rq_modal_email').value = r.email || '';
            document.getElementById('rq_modal_wa').value = r.whatsapp_number || '';
            document.getElementById('rq_modal_mgr_email').value = r.manager_email || '';
            document.getElementById('requestorEditModal').classList.remove('hidden');
        }
        function closeRequestorEdit() {
            document.getElementById('requestorEditModal').classList.add('hidden');
        }

        $(document).ready(function () {
            // Built-in filter box ('f') is intentionally excluded from `dom` — the
            // custom #requestorSearchInput toolbar above the card replaces it so the
            // search control stays outside the horizontally-scrollable table area.
            const requestorTable = $('#requestorTable').DataTable({
                "pageLength": 25,
                "lengthChange": false,
                dom: 'rt<"flex items-center justify-between mt-3 flex-wrap gap-2 text-xs text-gray-500"ip>'
            });
            $('#requestorSearchInput').on('keyup', function () {
                requestorTable.search(this.value).draw();
            });
        });
    </script>

    <!-- ═══════════════════════════════ QUANTITATIVE EVAL TAB ═══════════════════════════════ -->
    <?php elseif ($tab === 'quantitative'): ?>
    
    <form method="POST" action="master_vendor_evaluation.php" class="space-y-6">
        <input type="hidden" name="action" value="update_quant_eval">
        <input type="hidden" name="redirect_tab" value="quantitative">
        
        <div class="bg-indigo-50 border border-indigo-200 rounded p-4 flex justify-between items-center shadow-sm">
            <div>
                <h3 class="text-lg font-bold text-indigo-900">Quantitative Evaluation Configuration</h3>
                <p class="text-sm text-indigo-700">Define the weights and scoring logic for On Time Fulfilment (OTF) and Term of Payment (TOP).</p>
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2 px-6 rounded shadow transition">
                Save & Recalculate
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Weights Card -->
            <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
                <h3 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">Scoring Weights</h3>
                <p class="text-sm text-gray-600 mb-4">Set the percentage weight for each evaluation matrix. The total must be exactly 100%.</p>
                <div class="flex gap-4">
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-gray-700 mb-1">Qualitative (%)</label>
                        <input type="number" name="qualitative_weight_percent" value="<?= (int)($quant_config['qualitative_weight_percent'] ?? 50) ?>" required min="0" max="100" class="w-full p-2 border rounded focus:ring focus:ring-indigo-200 text-lg font-bold">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-gray-700 mb-1">OTF Weight (%)</label>
                        <input type="number" name="otf_weight_percent" value="<?= (int)($quant_config['otf_weight_percent'] ?? 25) ?>" required min="0" max="100" class="w-full p-2 border rounded focus:ring focus:ring-indigo-200 text-lg font-bold">
                    </div>
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-gray-700 mb-1">TOP Weight (%)</label>
                        <input type="number" name="top_weight_percent" value="<?= (int)($quant_config['top_weight_percent'] ?? 25) ?>" required min="0" max="100" class="w-full p-2 border rounded focus:ring focus:ring-indigo-200 text-lg font-bold">
                    </div>
                </div>
            </div>

            <!-- OTF Thresholds Card -->
            <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
                <h3 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">OTF Scoring Rules</h3>
                <p class="text-sm text-gray-600 mb-4">Define the maximum number of late days to achieve each score.</p>
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="bg-green-100 text-green-800 py-1 px-3 rounded font-bold">Score 3</span>
                            <span class="text-sm font-medium text-gray-700">Late days &le;</span>
                        </div>
                        <input type="number" name="otf_score_3_max_days" value="<?= (int)$quant_config['otf_score_3_max_days'] ?>" required min="0" class="w-20 p-2 border rounded text-center font-bold focus:ring focus:ring-indigo-200">
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="bg-yellow-100 text-yellow-800 py-1 px-3 rounded font-bold">Score 2</span>
                            <span class="text-sm font-medium text-gray-700">Late days &le;</span>
                        </div>
                        <input type="number" name="otf_score_2_max_days" value="<?= (int)$quant_config['otf_score_2_max_days'] ?>" required min="0" class="w-20 p-2 border rounded text-center font-bold focus:ring focus:ring-indigo-200">
                    </div>
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="bg-red-100 text-red-800 py-1 px-3 rounded font-bold">Score 1</span>
                            <span class="text-sm font-medium text-gray-700">Late days &ge;</span>
                        </div>
                        <input type="number" name="otf_score_1_min_days" value="<?= (int)$quant_config['otf_score_1_min_days'] ?>" required min="0" class="w-20 p-2 border rounded text-center font-bold focus:ring focus:ring-indigo-200">
                    </div>
                </div>
            </div>
        </div>

        <!-- TOP Scores Card -->
        <div class="bg-white shadow-md rounded-lg p-6 border border-gray-200">
            <h3 class="text-xl font-bold mb-4 text-gray-800 border-b pb-2">TOP Scoring Configuration</h3>
            <p class="text-sm text-gray-600 mb-4">Assign a score (1, 2, or 3) to each Term of Payment registered in the Master Vendor data.</p>
            
            <?php if (empty($top_list)): ?>
                <div class="p-4 bg-yellow-50 text-yellow-800 border border-yellow-200 rounded text-sm italic">
                    No Term of Payment data found in the Master Vendor table. Please ensure the Master Vendor table is populated.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm border-collapse">
                        <thead class="bg-gray-100">
                            <tr>
                                <th class="p-3 border border-gray-200 w-2/3">Term of Payment (TOP)</th>
                                <th class="p-3 border border-gray-200 text-center w-1/3">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($top_list as $idx => $t): ?>
                                <tr class="hover:bg-gray-50 border-b border-gray-200">
                                    <td class="p-3 font-medium text-gray-800"><?= h($t['top']) ?></td>
                                    <td class="p-2 text-center">
                                        <select name="top_scores[<?= h($t['top']) ?>]" class="w-full p-2 border rounded focus:ring focus:ring-indigo-200 bg-white font-bold <?= $t['score'] > 0 ? 'text-indigo-700' : 'text-gray-500' ?>">
                                            <option value="0" <?= $t['score'] == 0 ? 'selected' : '' ?>>Unassigned (0)</option>
                                            <option value="1" <?= $t['score'] == 1 ? 'selected' : '' ?>>Score 1</option>
                                            <option value="2" <?= $t['score'] == 2 ? 'selected' : '' ?>>Score 2</option>
                                            <option value="3" <?= $t['score'] == 3 ? 'selected' : '' ?>>Score 3</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </form>

    <?php endif; ?>

</div>

<?php require_once '../includes/footer.php'; ?>
