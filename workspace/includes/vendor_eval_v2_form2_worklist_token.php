<?php
// workspace/includes/vendor_eval_v2_form2_worklist_token.php
//
// Form 2 (Project Retail — New Store) shared worklist token.
//
// A single, global, unscoped secret — NOT tied to a site or PO — shared by
// both the Operations (~4 people) and Project (~10 people) teams to reach
// public_eval_v2_project_worklist.php. Exactly one row is expected in
// vendor_eval_v2_form2_worklist_token, ever, for the whole program.
//
// Read table : vendor_eval_v2_form2_worklist_token
// Write table: vendor_eval_v2_form2_worklist_token (regenerate only — an
//              admin action, never called from a public no-login page).
//
// Safety invariants:
//   • ve2_form2_worklist_token_get()/_validate() are read-only and safe to
//     call from the public no-login worklist page.
//   • ve2_form2_worklist_token_regenerate() WRITES and must only be called
//     from an authenticated, Category-Manager-gated admin action.

/** Current token, or null if none has been generated yet. */
function ve2_form2_worklist_token_get(PDO $pdo): ?string
{
    $row = $pdo->query("SELECT token FROM vendor_eval_v2_form2_worklist_token ORDER BY id ASC LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
    return $row ? (string)$row['token'] : null;
}

/** True when $token matches the current single token row (constant-time compare). */
function ve2_form2_worklist_token_validate(PDO $pdo, string $token): bool
{
    if ($token === '' || strlen($token) > 64) {
        return false;
    }
    $current = ve2_form2_worklist_token_get($pdo);
    return $current !== null && hash_equals($current, $token);
}

/**
 * Replace the single token row with a fresh random value, creating the row
 * on first use. The old token stops resolving immediately (it is simply no
 * longer the value any row holds). Returns the new token.
 */
function ve2_form2_worklist_token_regenerate(PDO $pdo): string
{
    $new_token = bin2hex(random_bytes(32));

    $existing_id = $pdo->query("SELECT id FROM vendor_eval_v2_form2_worklist_token ORDER BY id ASC LIMIT 1")
                        ->fetchColumn();

    if ($existing_id !== false) {
        $stmt = $pdo->prepare("UPDATE vendor_eval_v2_form2_worklist_token SET token = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$new_token, $existing_id]);

        // Defensive: if more than one row ever existed (should not happen),
        // collapse to a single row rather than leaving stray valid tokens.
        $del = $pdo->prepare("DELETE FROM vendor_eval_v2_form2_worklist_token WHERE id != ?");
        $del->execute([$existing_id]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO vendor_eval_v2_form2_worklist_token (token, created_at, updated_at) VALUES (?, NOW(), NOW())");
        $stmt->execute([$new_token]);
    }

    return $new_token;
}
