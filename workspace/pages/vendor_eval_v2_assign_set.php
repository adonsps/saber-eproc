<?php
// workspace/pages/vendor_eval_v2_assign_set.php
//
// Vendor Evaluation v2 — Manual Question Set Assignment (write endpoint).
//
// Category-Manager-only. Assigns a specific question set to a PO that currently
// only matched the Default routing rule (i.e. it is in the Manual Assignment
// queue). This is the ONLY new write in the feature.
//
// Access   : require_category_manager() — never public.
// Write     : INSERT into vendor_eval_po_question_set_override (one row per PO).
// Reads     : vendor_eval_po_taxonomy, vendor_eval_question_sets, and the shared
//             queue predicate (vendor_eval_v2_routing.php).
// No WA/email, no external API. PRG: always redirects back, never renders HTML.
//
// Guardrails (all enforced server-side, in a transaction):
//   - PO must exist and currently be eligible (mapped, no submission, no override,
//     reached Default) — a PO with an automatic L3/L2 match can NEVER be assigned.
//   - question_set must exist and be active.
//   - One override per PO (UNIQUE(po_number) + friendly pre-check).

require_once '../includes/auth.php';
require_category_manager();
require_once '../config/database.php';                 // provides $pdo (PDO)
require_once '../includes/vendor_eval_v2_routing.php';

/** Redirect back to the results page with a one-shot status param, then stop. */
function ve2_assign_redirect(string $key, string $value): void
{
    header('Location: vendor_eval_v2_results.php?' . $key . '=' . urlencode($value), true, 303);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    ve2_assign_redirect('assign_error', 'method');
}

$po_number       = trim((string)($_POST['po_number'] ?? ''));
$question_set_id = (int)($_POST['question_set_id'] ?? 0);

if ($po_number === '' || $question_set_id <= 0) {
    ve2_assign_redirect('assign_error', 'input');
}

try {
    // 1. PO must exist in the taxonomy bridge.
    $poStmt = $pdo->prepare("SELECT po_number FROM vendor_eval_po_taxonomy WHERE po_number = ? LIMIT 1");
    $poStmt->execute([$po_number]);
    if (!$poStmt->fetch()) {
        ve2_assign_redirect('assign_error', 'notfound');
    }

    // 2. Question set must exist AND be active.
    $setStmt = $pdo->prepare("SELECT id FROM vendor_eval_question_sets WHERE id = ? AND is_active = 1 LIMIT 1");
    $setStmt->execute([$question_set_id]);
    if (!$setStmt->fetch()) {
        ve2_assign_redirect('assign_error', 'set');
    }

    // 3. PO must currently be eligible: mapped, not evaluated, no override, and it
    //    reached the Default rule (no specific L3/L2 match). This also blocks
    //    overriding an already-automatically-mapped PO and double-assignment.
    if (!ve2_po_needs_manual_mapping($pdo, $po_number)) {
        ve2_assign_redirect('assign_error', 'notqueued');
    }

    // 4. Insert the override in a transaction.
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("
            INSERT INTO vendor_eval_po_question_set_override
                (po_number, question_set_id, assigned_by, assigned_at)
            VALUES (?, ?, ?, NOW())
        ");
        $ins->execute([$po_number, $question_set_id, ($_SESSION['name'] ?? null)]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        // 23xxx = integrity constraint (duplicate po_number under UNIQUE) — a race
        // where the PO was assigned between the pre-check and the insert.
        if (strpos((string)$e->getCode(), '23') === 0) {
            ve2_assign_redirect('assign_error', 'dup');
        }
        throw $e;
    }
} catch (PDOException $e) {
    error_log('[vendor_eval_v2_assign_set] ' . $e->getMessage());
    ve2_assign_redirect('assign_error', 'db');
}

ve2_assign_redirect('assigned', $po_number);
