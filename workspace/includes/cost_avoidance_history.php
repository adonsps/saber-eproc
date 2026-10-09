<?php
// includes/cost_avoidance_history.php
//
// Append-only "who changed what price, when" log for Initial Quotation Price
// (Project per canvassing, Non-Project per ticket line) and Manual Cost
// Avoidance. Table: cost_avoidance_edit_history (guarded migration in
// config/database.php). Rows are written INSIDE the same transaction as the
// save they describe, so a rolled-back save leaves no history behind.
//
//   entity_type       entity_key
//   QUOTATION_PROJECT canvassing_no
//   QUOTATION_TICKET  "ticket_no|ticket_line_item"
//   MANUAL            manual_cost_avoidance.id
//
// old/new snapshots are ['price'=>?float, 'currency'=>?string, 'fx_rate'=>?float].
// Anything else worth showing (item, quantity, link ...) goes in $detail.

if (!defined('CA_HIST_QUOTATION_PROJECT')) define('CA_HIST_QUOTATION_PROJECT', 'QUOTATION_PROJECT');
if (!defined('CA_HIST_QUOTATION_TICKET'))  define('CA_HIST_QUOTATION_TICKET',  'QUOTATION_TICKET');
if (!defined('CA_HIST_MANUAL'))            define('CA_HIST_MANUAL',            'MANUAL');

if (!function_exists('ca_hist_actor')) {

/** Who is acting, from the session: ['id' => ?int, 'name' => string]. */
function ca_hist_actor(array $session): array {
    $name = trim((string)($session['name'] ?? $session['username'] ?? ''));
    if ($name === '') $name = 'user#' . (int)($session['user_id'] ?? 0);
    $id = isset($session['user_id']) ? (int)$session['user_id'] : null;
    return ['id' => $id ?: null, 'name' => $name];
}

function ca_hist_key_ticket(string $ticket_no, string $line): string {
    return $ticket_no . '|' . $line;
}

/**
 * Whether two price snapshots differ in anything a user would call "the
 * price": amount (2 dp), currency, or exchange rate (4 dp). A NULL rate on a
 * non-IDR side means "current rate" and is treated as equal to $currentRates
 * [code] so an untouched legacy row does not look edited.
 */
function ca_hist_price_changed(?array $old, array $new, array $currentRates = []): bool {
    if ($old === null) return true;
    $norm = function($s) use ($currentRates) {
        $cur = strtoupper((string)($s['currency'] ?? 'IDR')) ?: 'IDR';
        $rate = $s['fx_rate'] ?? null;
        if ($cur === 'IDR') $rate = null;
        elseif ($rate === null || (float)$rate <= 0) $rate = $currentRates[$cur] ?? null;
        return [
            $s['price'] === null ? null : number_format((float)$s['price'], 2, '.', ''),
            $cur,
            $rate === null ? null : number_format((float)$rate, 4, '.', ''),
        ];
    };
    return $norm($old) !== $norm($new);
}

function ca_hist_log(PDO $pdo, array $session, string $type, string $key, string $action,
                     ?array $old, ?array $new, string $source, ?array $detail = null): void {
    $actor = ca_hist_actor($session);
    $stmt = $pdo->prepare("
        INSERT INTO cost_avoidance_edit_history
            (entity_type, entity_key, action, old_price, old_currency, old_fx_rate,
             new_price, new_currency, new_fx_rate, detail, source, actor_id, actor_name)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $type, $key, $action,
        $old['price'] ?? null, $old['currency'] ?? null, $old['fx_rate'] ?? null,
        $new['price'] ?? null, $new['currency'] ?? null, $new['fx_rate'] ?? null,
        $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        $source, $actor['id'], $actor['name'],
    ]);
}

/** Newest first. Rows are display-ready arrays (see ca_hist_row_public()). */
function ca_hist_list(PDO $pdo, string $type, string $key, int $limit = 200): array {
    $limit = max(1, min(500, $limit));
    $stmt = $pdo->prepare("
        SELECT id, action, old_price, old_currency, old_fx_rate, new_price, new_currency, new_fx_rate,
               detail, source, actor_name, created_at
        FROM cost_avoidance_edit_history
        WHERE entity_type = ? AND entity_key = ?
        ORDER BY id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$type, $key]);
    return array_map('ca_hist_row_public', $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function ca_hist_row_public(array $r): array {
    return [
        'id'       => (int)$r['id'],
        'action'   => (string)$r['action'],
        'old'      => $r['old_price'] === null && $r['old_currency'] === null ? null
                        : ['price' => $r['old_price'], 'currency' => $r['old_currency'], 'fx_rate' => $r['old_fx_rate']],
        'new'      => $r['new_price'] === null && $r['new_currency'] === null ? null
                        : ['price' => $r['new_price'], 'currency' => $r['new_currency'], 'fx_rate' => $r['new_fx_rate']],
        'detail'   => $r['detail'] !== null && $r['detail'] !== '' ? json_decode($r['detail'], true) : null,
        'source'   => (string)$r['source'],
        'actor'    => (string)$r['actor_name'],
        'at'       => (string)$r['created_at'],
    ];
}

}
