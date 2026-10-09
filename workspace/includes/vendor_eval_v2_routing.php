<?php
// workspace/includes/vendor_eval_v2_routing.php
//
// Vendor Evaluation v2 — manual question-set override + "needs manual mapping" queue.
//
// PURE LIBRARY: defines functions/constants only. Including it performs no DB
// connection, no auth, no session, no output, and has no side effects — so it is
// safe to require from the public no-login pages (public_eval_v2.php) exactly like
// vendor_eval_v2_sender.php.
//
// Runtime contract (unchanged engine + one wrapper):
//   Question-set routing is Manual Override -> L3 -> L2 -> Default. This file only
//   supplies the Manual Override step (ve2_override_set / ve2_load_overrides); the
//   existing L3/L2/Default resolvers in public_eval_v2.php and
//   vendor_eval_v2_sender.php are NOT modified. The Default safety net still routes
//   every PO that has no override, so routing never returns NULL.
//
// Queue contract (visibility only):
//   A PO "needs manual mapping" when it reached the Default rule — i.e. it has NO
//   active specific match at L3 or L2 — AND it is taxonomy-mapped, not yet
//   evaluated, and has no override. Being in the queue never stops routing or
//   reminders; it just flags the PO for a Category Manager to assign a better set.

if (!defined('VE2_SENTINEL_ANY')) { define('VE2_SENTINEL_ANY', '**ANY**'); }

/**
 * Active manual override for one PO, or null.
 *
 * Returns a set row shaped like the resolvers ({id,set_code,set_name,map_level})
 * so callers can use it as a drop-in for resolveQuestionSet()/ve2_resolve_set().
 * An override pointing at a since-deactivated set resolves to null, so the PO
 * safely falls back to the existing L3/L2/Default chain.
 */
function ve2_override_set(PDO $pdo, string $po): ?array
{
    $stmt = $pdo->prepare("
        SELECT qs.id, qs.set_code, qs.set_name
          FROM vendor_eval_po_question_set_override ov
          JOIN vendor_eval_question_sets qs ON qs.id = ov.question_set_id
         WHERE ov.po_number = ? AND qs.is_active = 1
         LIMIT 1
    ");
    $stmt->execute([$po]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }
    $row['id']        = (int)$row['id'];
    $row['map_level'] = 'manual';
    return $row;
}

/**
 * All active overrides keyed by po_number (for the reminder senders — avoids a
 * per-PO query / N+1). Each value has the same shape as ve2_override_set().
 */
function ve2_load_overrides(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT ov.po_number, qs.id, qs.set_code, qs.set_name
          FROM vendor_eval_po_question_set_override ov
          JOIN vendor_eval_question_sets qs ON qs.id = ov.question_set_id
         WHERE qs.is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['po_number']] = [
            'id'        => (int)$r['id'],
            'set_code'  => $r['set_code'],
            'set_name'  => $r['set_name'],
            'map_level' => 'manual',
        ];
    }
    return $out;
}

/**
 * Internal: the shared "reached the Default rule" predicate, on alias `pt`
 * (vendor_eval_po_taxonomy). This is the runtime chain MINUS the Default branch:
 * a PO satisfies it when no active specific L3/L2 map matches, it is 'mapped',
 * has no submitted evaluation, and has no override. Sentinel + precedence mirror
 * resolveQuestionSet(). Kept in one place so the card, the badge, and the endpoint
 * guard can never drift. Contains no user input — the sentinel is a fixed literal.
 */
function ve2_manual_queue_predicate(): string
{
    return "
        (
            pt.status != 'mapped'
            OR
            (
                pt.status = 'mapped'
                AND NOT EXISTS (
                    SELECT 1
                      FROM vendor_eval_question_set_taxonomy_map m
                      JOIN vendor_eval_question_sets qs ON qs.id = m.question_set_id
                     WHERE qs.is_active = 1 AND m.is_active = 1
                       AND (
                             (m.map_level = 'l3'
                              AND m.taxonomy_level_2 = pt.taxonomy_level_2
                              AND m.taxonomy_level_3 = pt.taxonomy_level_3)
                          OR (m.map_level = 'l2'
                              AND m.taxonomy_level_2 = pt.taxonomy_level_2
                              AND m.taxonomy_level_3 = '" . VE2_SENTINEL_ANY . "')
                           )
                )
            )
        )
        AND NOT EXISTS (
            SELECT 1 FROM vendor_eval_po_question_set_override ov
             WHERE ov.po_number = pt.po_number
        )
        AND NOT EXISTS (
            SELECT 1 FROM vendor_eval_submissions s
             WHERE s.po_number = pt.po_number AND s.status = 'submitted'
        )
    ";
}

/** Queue rows for the Manual Assignment card. One query, no N+1. */
function ve2_manual_queue_pos(PDO $pdo): array
{
    $sql = "
        SELECT pt.po_number, pt.vendor_name, pt.taxonomy_level_2, pt.taxonomy_level_3
          FROM vendor_eval_po_taxonomy pt
         WHERE " . ve2_manual_queue_predicate() . "
         ORDER BY pt.po_number
    ";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

/** Cheap existence check for the sidebar dot (no COUNT of the full set). */
function ve2_manual_queue_exists(PDO $pdo): bool
{
    $sql = "
        SELECT EXISTS (
            SELECT 1 FROM vendor_eval_po_taxonomy pt
             WHERE " . ve2_manual_queue_predicate() . "
             LIMIT 1
        )
    ";
    return (bool)$pdo->query($sql)->fetchColumn();
}

/** Endpoint guard: is this specific PO currently eligible for manual assignment? */
function ve2_po_needs_manual_mapping(PDO $pdo, string $po): bool
{
    $sql = "
        SELECT EXISTS (
            SELECT 1 FROM vendor_eval_po_taxonomy pt
             WHERE pt.po_number = ?
               AND " . ve2_manual_queue_predicate() . "
             LIMIT 1
        )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$po]);
    return (bool)$stmt->fetchColumn();
}
