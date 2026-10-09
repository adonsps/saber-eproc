<?php
// includes/manual_ca_helper.php
//
// Manual Cost Avoidance (records of avoided cost NOT tied to a PR/PO,
// table manual_cost_avoidance) as ONE shared implementation, used by both
// pages/cost_avoidance.php (the original Manual tab) and the dashboards'
// "Manual Input" modal (pages/ajax_manual_ca_save.php), so the two can never
// drift apart on currency, validation, permissions or history.
//
// What "price" means in the history for a manual entry: the INITIAL price
// (unit_price_before) with its currency + exchange-rate snapshot; the final
// price, quantity and other fields are summarised in the history "detail".
//
// The total is computed here, server-side: (initial - final) x quantity, in the
// entry's own currency (the page's JS shows the same number; the server no
// longer trusts a posted total). The exchange rate the entry was made at is
// stored (fx_rate) and used for every later IDR conversion.

require_once __DIR__ . '/currency_helper.php';
require_once __DIR__ . '/cost_avoidance_helper.php';
require_once __DIR__ . '/cost_avoidance_history.php';

if (!function_exists('classify_manual_ca_category')) {

// Classify a Manual Cost Avoidance item description into a Category, using the
// same Golden Rules + master_data taxonomy (level_4) that ajax_ai_autofill_taxonomy.php
// uses for SKU registration, so both features agree on what counts as a valid
// category. Fails open (returns null) on any AI/parsing error so a save/update
// is never blocked by an AI outage -- the row is just left uncategorized.
// (Moved verbatim from pages/cost_avoidance.php.)
function classify_manual_ca_category(PDO $pdo, string $item_desc): ?string {
    require_once __DIR__ . '/../config/gemini_api.php';
    $item_desc = trim($item_desc);
    if ($item_desc === '') {
        return null;
    }

    $golden_rules = "";
    $rule_text = $pdo->query("SELECT rule_text FROM ai_golden_rules LIMIT 1")->fetchColumn();
    if ($rule_text) {
        $golden_rules = "GOLDEN RULES:\n" . $rule_text . "\n\n";
    }

    $categories_raw = $pdo->query("
        SELECT DISTINCT level_4 FROM master_data
        WHERE status IN ('active', 'registered') OR status IS NULL
    ")->fetchAll(PDO::FETCH_COLUMN);
    $categories_list = array_values(array_unique(array_filter(array_map('trim', $categories_raw))));
    if (empty($categories_list)) {
        return null;
    }

    $prompt = "You are an expert procurement taxonomy classifier.\n";
    $prompt .= $golden_rules;
    $prompt .= "AVAILABLE EXACT CATEGORIES:\n" . implode(", ", $categories_list) . "\n\n";
    $prompt .= "Task: Map the item description below to the single most accurate category from the EXACT CATEGORIES list.\n";
    $prompt .= "Item Description: \"{$item_desc}\"\n\n";
    $prompt .= "CRITICAL RULES:\n";
    $prompt .= "1. Output ONLY a valid JSON object. Do not add markdown or comments.\n";
    $prompt .= "2. The 'category' MUST be copied VERBATIM and IN FULL from the AVAILABLE EXACT CATEGORIES list.\n";
    $prompt .= "   Copy the entire string character-for-character, including EVERY leading word (e.g. brand prefixes\n";
    $prompt .= "   like 'WHATSAPP', 'FRESHCALLER', 'ONETALK'). Do NOT shorten, paraphrase, drop the first word, or\n";
    $prompt .= "   omit any part. If no exact match, pick the closest one but still copy it in full.\n";
    $prompt .= "3. Format:\n{\n  \"category\": \"Matched Category from list\"\n}\n";

    try {
        $ai_text = call_gemini_api($prompt, 3, gemini_key_admin_misc());
        $ai_text = trim(preg_replace('/```json|```/i', '', $ai_text));
        $parsed = json_decode($ai_text, true);
        $guess = trim($parsed['category'] ?? '');
        if ($guess === '') {
            return null;
        }

        // 1. Exact match (case-insensitive) -- the happy path.
        foreach ($categories_list as $cat) {
            if (strcasecmp($cat, $guess) === 0) {
                return $cat;
            }
        }

        // 2. Containment match. The AI frequently truncates the category, most
        $norm = function (string $s): string {
            return trim(preg_replace('/\s+/', ' ', strtoupper($s)));
        };
        $guess_n = $norm($guess);
        $contained = [];
        foreach ($categories_list as $cat) {
            $cat_n = $norm($cat);
            if ($cat_n === '') {
                continue;
            }
            if (strpos($cat_n, $guess_n) !== false || strpos($guess_n, $cat_n) !== false) {
                $contained[] = $cat;
            }
        }
        if (count($contained) === 1) {
            return $contained[0];
        }

        // 3. Fall back to the closest known category by similarity. When the
        $pool = !empty($contained) ? $contained : $categories_list;
        $highest_sim = 0;
        $best_match = null;
        foreach ($pool as $cat) {
            similar_text(strtolower($guess), strtolower($cat), $percent);
            if ($percent > $highest_sim) {
                $highest_sim = $percent;
                $best_match = $cat;
            }
        }
        return ($highest_sim >= 90) ? $best_match : null;
    } catch (Exception $e) {
        return null;
    }
}

}

if (!function_exists('manual_ca_parse')) {

/**
 * Validate + normalise one submitted manual entry ($post uses the m_* field
 * names of the Manual Input form). Pure w.r.t. the database apart from reading
 * the active currencies. Returns
 *   ['ok'=>bool, 'message'=>string, 'row'=>[entry_date, vendor_name,
 *    item_description, quantity, uom, unit_price_before, avoided_price,
 *    total_cost_avoidance, currency, fx_rate, team, remarks, quotation_link]].
 */
function manual_ca_parse(PDO $pdo, array $post): array {
    $fail = function($msg) { return ['ok' => false, 'message' => $msg, 'row' => null]; };

    $date = trim((string)($post['m_entry_date'] ?? ''));
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) return $fail('Transaction date is required.');

    $vendor = trim((string)($post['m_vendor_name'] ?? ''));
    if ($vendor === '') return $fail('Vendor name is required.');
    $item = trim((string)($post['m_item_description'] ?? ''));
    if ($item === '') return $fail('Item description is required.');
    $team = trim((string)($post['m_team'] ?? ''));
    if ($team === '') return $fail('Team is required.');
    // Only the 7 real teams (stored as the full name). A bare code is folded to
    // its full name; anything else is refused even for unrestricted sessions.
    $teamOpts = ca_manual_team_options();
    if (isset($teamOpts[$team])) $team = $teamOpts[$team];
    if (!in_array($team, $teamOpts, true)) return $fail('Unknown team.');
    if (mb_strlen($vendor) > 255) return $fail('Vendor name is too long (max 255 characters).');
    if (mb_strlen($item) > 500) return $fail('Item description is too long (max 500 characters).');
    $uomRaw = trim((string)($post['m_uom'] ?? ''));
    if (mb_strlen($uomRaw) > 50) return $fail('UoM is too long.');
    $link = ca_clean_link($post['m_quotation_link'] ?? '');
    if (!$link['ok']) return $fail($link['message']);

    $qty = ca_parse_amount(str_replace(',', '', (string)($post['m_quantity'] ?? '1')));
    if (!$qty['ok'] || $qty['value'] === null || $qty['value'] <= 0) return $fail('Quantity must be greater than 0.');
    if ($qty['value'] >= 1.0E+10) return $fail('Quantity is too large.');

    $beforeRaw = str_replace(',', '', (string)($post['m_unit_price_before'] ?? ''));
    $avoidedRaw = str_replace(',', '', (string)($post['m_avoided_price'] ?? ''));
    $before = ca_parse_amount($beforeRaw);
    $avoided = ca_parse_amount($avoidedRaw);
    if (!$before['ok'] || $before['value'] === null || $before['value'] < 0) return $fail('Initial price is required.');
    if (!$avoided['ok'] || $avoided['value'] === null || $avoided['value'] < 0) return $fail('Final price is required.');
    if ($before['value'] >= 1.0E+12 || $avoided['value'] >= 1.0E+12) return $fail('Price is too large.');

    $fx = ca_resolve_fx($post['m_currency'] ?? 'IDR', $post['m_fx_rate'] ?? null, ca_get_active_currencies($pdo));
    if (!$fx['ok']) return $fail($fx['message']);

    $qtyV = round($qty['value'], 4);
    $total = round(($before['value'] - $avoided['value']) * $qtyV, 2);
    if (abs($total) >= 1.0E+13) return $fail('Total cost avoidance is too large.');

    return ['ok' => true, 'message' => '', 'row' => [
        'entry_date'           => $date,
        'vendor_name'          => $vendor,
        'item_description'     => $item,
        'quantity'             => $qtyV,
        'uom'                  => trim((string)($post['m_uom'] ?? '')) ?: null,
        'unit_price_before'    => round($before['value'], 2),
        'avoided_price'        => round($avoided['value'], 2),
        'total_cost_avoidance' => $total,
        'currency'             => $fx['currency'],
        'fx_rate'              => $fx['rate'],
        'team'                 => $team,
        'remarks'              => trim((string)($post['m_remarks'] ?? '')) ?: null,
        'quotation_link'       => $link['value'] !== '' ? $link['value'] : null,
    ]];
}

/** History/confirm snapshot of a manual row (initial price + currency + rate). */
function manual_ca_snapshot(array $row): array {
    return quotation_snapshot_manual($row['unit_price_before'], $row['currency'], $row['fx_rate'] ?? null);
}

function quotation_snapshot_manual($price, $currency, $fx): array {
    $cur = strtoupper(trim((string)$currency)) ?: 'IDR';
    return [
        'price'    => $price === null ? null : round((float)$price, 2),
        'currency' => $cur,
        'fx_rate'  => ($cur === 'IDR' || $fx === null || (float)$fx <= 0) ? null : round((float)$fx, 4),
    ];
}

/** Human-readable list of what differs between two manual rows (for confirm + history). */
function manual_ca_diff(array $old, array $new, array $rates): array {
    $labels = [
        'entry_date' => 'Date', 'vendor_name' => 'Vendor', 'item_description' => 'Item', 'quantity' => 'Qty',
        'uom' => 'UoM', 'avoided_price' => 'Final price', 'team' => 'Team', 'remarks' => 'Remarks', 'quotation_link' => 'Link',
    ];
    $out = [];
    foreach ($labels as $k => $label) {
        $a = $old[$k] ?? null; $b = $new[$k] ?? null;
        if (in_array($k, ['quantity', 'avoided_price'], true)) {
            if (abs((float)$a - (float)$b) < 0.00005) continue;
        } elseif ((string)$a === (string)$b) {
            continue;
        }
        $out[] = $label . ': ' . ($a === null || $a === '' ? '-' : $a) . ' -> ' . ($b === null || $b === '' ? '-' : $b);
    }
    if (ca_hist_price_changed(manual_ca_snapshot($old), manual_ca_snapshot($new), $rates)) {
        $out[] = 'Initial price / currency / rate';
    }
    return $out;
}

/**
 * Create or update one manual entry (update when $post['m_id'] > 0).
 *   $scope        = ca_resolve_session_team_scope() (null = unrestricted).
 *   $source       = 'MANUAL' (Cost Avoidance page) | 'DASHBOARD' -- stored in
 *                   source_type on create and in the history row.
 *   $confirm_edit = the user has said yes to "change this saved entry?".
 * Editing an existing entry without $confirm_edit returns
 *   ['ok'=>false, 'needs_confirm'=>true, 'changes'=>[[old,new,note]]]
 * instead of saving. Returns ['ok','message','id','needs_confirm','changes'].
 */
function manual_ca_save(PDO $pdo, array $session, ?array $scope, array $post, string $source, bool $confirm_edit): array {
    $res = function($ok, $msg, $id = 0, $extra = []) {
        return array_merge(['ok' => $ok, 'message' => $msg, 'id' => $id, 'needs_confirm' => false, 'changes' => []], $extra);
    };
    $id = (int)($post['m_id'] ?? 0);
    $old = null;

    try {
        if ($id > 0) {
            // Ownership FIRST: the row being edited must already belong to a team within scope.
            ca_assert_manual_team_allowed($scope, ca_manual_ca_owner_team($pdo, $id));
        }
        ca_assert_manual_team_allowed($scope, $post['m_team'] ?? null);
    } catch (RuntimeException $e) {
        return $res(false, 'Outside your assigned team scope.');
    }

    $p = manual_ca_parse($pdo, $post);
    if (!$p['ok']) return $res(false, $p['message']);
    $row = $p['row'];

    $currencies = ca_get_active_currencies($pdo);
    $rates = [];
    foreach ($currencies as $code => $c) { $rates[$code] = (float)$c['rate_to_idr']; }

    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT * FROM manual_cost_avoidance WHERE id = ? AND is_active = 1");
        $stmt->execute([$id]);
        $old = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$old) return $res(false, 'This entry no longer exists.');

        $diff = manual_ca_diff($old, $row, $rates);
        if (!$diff) return $res(true, '', $id, ['unchanged' => true]);     // nothing to save
        if (!$confirm_edit) {
            return $res(false, 'This entry is already saved - please confirm the change.', $id, [
                'needs_confirm' => true,
                'changes' => [[
                    'old' => manual_ca_snapshot($old), 'new' => manual_ca_snapshot($row), 'note' => implode('; ', $diff),
                ]],
            ]);
        }
    }

    $user = $session['name'] ?? $session['username'] ?? ('user#' . ($session['user_id'] ?? '?'));

    // Double-click / retry guard for CREATE: a per-user named lock serialises
    // identical submissions, and an identical entry by the same person in the
    // last 30 seconds is returned instead of inserted again (the UI disables the
    // button, but a network retry or a fast double submit still reaches here).
    $lockName = null;
    if ($id === 0) {
        $lockName = 'mca_' . md5($user . '|' . json_encode($row));
        $pdo->query('SELECT GET_LOCK(' . $pdo->quote($lockName) . ', 10)')->fetchColumn();
        $dup = $pdo->prepare("
            SELECT id FROM manual_cost_avoidance
            WHERE is_active = 1 AND created_by = ? AND entry_date = ? AND vendor_name = ? AND item_description = ?
              AND quantity = ? AND unit_price_before <=> ? AND avoided_price <=> ? AND currency = ? AND team <=> ?
              AND created_at >= (NOW() - INTERVAL 30 SECOND)
            ORDER BY id DESC LIMIT 1
        ");
        $dup->execute([$user, $row['entry_date'], $row['vendor_name'], $row['item_description'], $row['quantity'],
                       $row['unit_price_before'], $row['avoided_price'], $row['currency'], $row['team']]);
        $dupId = $dup->fetchColumn();
        if ($dupId) {
            $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')')->fetchColumn();
            return $res(true, '', (int)$dupId, ['duplicate' => true]);
        }
    }

    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) $pdo->beginTransaction();
    try {
        if ($id > 0) {
            // Category is classified AFTER the commit (below), and only when the
            // description actually changed; until then the old category stays.
            $category = $old['category'];
            $needClassify = ((string)$old['item_description'] !== $row['item_description']);
            $stmt = $pdo->prepare("
                UPDATE manual_cost_avoidance
                SET entry_date = ?, vendor_name = ?, item_description = ?, quantity = ?, uom = ?,
                    unit_price_before = ?, avoided_price = ?, total_cost_avoidance = ?, currency = ?, fx_rate = ?,
                    category = ?, team = ?, remarks = ?, quotation_link = ?, updated_by = ?
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([
                $row['entry_date'], $row['vendor_name'], $row['item_description'], $row['quantity'], $row['uom'],
                $row['unit_price_before'], $row['avoided_price'], $row['total_cost_avoidance'], $row['currency'], $row['fx_rate'],
                $category, $row['team'], $row['remarks'], $row['quotation_link'], $user, $id,
            ]);
            ca_hist_log($pdo, $session, CA_HIST_MANUAL, (string)$id, 'UPDATE', manual_ca_snapshot($old), manual_ca_snapshot($row), $source, [
                'summary' => implode('; ', manual_ca_diff($old, $row, $rates)),
            ]);
        } else {
            $category = null;            // classified after the commit (below)
            $needClassify = true;
            $stmt = $pdo->prepare("
                INSERT INTO manual_cost_avoidance
                    (entry_date, vendor_name, item_description, quantity, uom, unit_price_before, avoided_price,
                     total_cost_avoidance, currency, fx_rate, category, team, remarks, quotation_link, source_type,
                     created_by, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $row['entry_date'], $row['vendor_name'], $row['item_description'], $row['quantity'], $row['uom'],
                $row['unit_price_before'], $row['avoided_price'], $row['total_cost_avoidance'], $row['currency'], $row['fx_rate'],
                $category, $row['team'], $row['remarks'], $row['quotation_link'],
                $source === 'DASHBOARD' ? 'DASHBOARD' : 'MANUAL', $user, $user,
            ]);
            $id = (int)$pdo->lastInsertId();
            ca_hist_log($pdo, $session, CA_HIST_MANUAL, (string)$id, 'CREATE', null, manual_ca_snapshot($row), $source, [
                'summary' => 'Final ' . $row['avoided_price'] . ' x ' . $row['quantity'] . ' = total ' . $row['total_cost_avoidance'],
            ]);
        }
        if ($ownTxn) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        if ($lockName !== null) $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lockName) . ')')->fetchColumn();
    }

    // Best-effort AI categorisation, deliberately OUTSIDE the transaction and the
    // duplicate-guard lock: a slow or failing Gemini call can no longer hold a DB
    // lock or lose the save, it just leaves the category empty (fail-open, exactly
    // as the classifier always did). One call per NEW entry, or per edit that
    // changed the description -- never per save.
    if (!empty($needClassify)) {
        try {
            $cat = classify_manual_ca_category($pdo, $row['item_description']);
            if ($cat !== null) {
                $pdo->prepare("UPDATE manual_cost_avoidance SET category = ? WHERE id = ?")->execute([$cat, $id]);
            }
        } catch (Throwable $e) {
            error_log('[cost-avoidance] category classify failed: ' . $e->getMessage());
        }
    }
    return $res(true, '', $id);
}

/** Soft-delete one manual entry (team ownership checked), logged to history. */
function manual_ca_delete(PDO $pdo, array $session, ?array $scope, int $id, string $source): array {
    try {
        ca_assert_manual_team_allowed($scope, ca_manual_ca_owner_team($pdo, $id));
    } catch (RuntimeException $e) {
        return ['ok' => false, 'message' => 'Outside your assigned team scope.'];
    }
    $stmt = $pdo->prepare("SELECT * FROM manual_cost_avoidance WHERE id = ? AND is_active = 1");
    $stmt->execute([$id]);
    $old = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) return ['ok' => false, 'message' => 'This entry no longer exists.'];

    $user = $session['name'] ?? $session['username'] ?? ('user#' . ($session['user_id'] ?? '?'));
    $ownTxn = !$pdo->inTransaction();
    if ($ownTxn) $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE manual_cost_avoidance SET is_active = 0, updated_by = ? WHERE id = ?")->execute([$user, $id]);
        ca_hist_log($pdo, $session, CA_HIST_MANUAL, (string)$id, 'DELETE', manual_ca_snapshot($old), null, $source, [
            'summary' => 'Entry removed: ' . $old['item_description'],
        ]);
        if ($ownTxn) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownTxn && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return ['ok' => true, 'message' => ''];
}

}
