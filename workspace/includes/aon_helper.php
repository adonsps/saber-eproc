<?php
// includes/aon_helper.php
// Shared helpers for the Master AON (Always On SKU) feature.
// Centralizes access control and constants so the page and AJAX endpoints
// stay consistent.

/**
 * Roles permitted to view and maintain AON SKU master data.
 * STC is the primary maintainer. Category Manager is the main head and can
 * access everything STC can; Manager is included for oversight.
 */
function aon_allowed_roles() {
    return ['STC', 'Category Manager', 'Manager'];
}

function aon_can_access($role) {
    return in_array((string)$role, aon_allowed_roles(), true);
}

/**
 * Roles permitted to IMPORT the AON CSVs (Master AON's "Upload AON" button/menu).
 *
 * Category Manager only -- the CSVs are pulled from K2 on a schedule; Manager
 * and STC have no task to run this upload and don't have folder access or a
 * manual K2 export in the same format, so the button/menu is Category
 * Manager-only. Enforced server-side in pages/upload_aon.php, not just by
 * hiding the button/menu.
 */
function aon_upload_allowed_roles() {
    return ['Category Manager'];
}

function aon_can_upload($role) {
    return in_array((string)$role, aon_upload_allowed_roles(), true);
}

/**
 * Roles permitted to edit the contract-sourced fields of an AON SKU (vendor,
 * category, validity window, price, quantities) via the Edit modal.
 *
 * Wider than aon_upload_allowed_roles() on purpose: restricting the K2 upload
 * to Category Manager is about who runs that import, not about who may make a
 * manual correction afterward -- Manager keeps that, same as before. Narrower
 * than aon_allowed_roles(): an STC maintains the SKUs it has claimed -- the
 * 2025 names, the contract number, the PIC -- but the commercial terms belong
 * to Category Manager / Manager. Enforced server-side in
 * pages/ajax_aon_sku_edit.php, not just in the form.
 */
function aon_can_edit_contract_fields($role) {
    return in_array((string)$role, ['Category Manager', 'Manager'], true);
}

/**
 * The business years tracked for AON status (e.g. 2025 vs 2026).
 */
function aon_valid_years() {
    return [2025, 2026];
}

function aon_valid_statuses() {
    return ['ACTIVE', 'INACTIVE'];
}

/**
 * Reusable SQL predicate restricting a prs-based query to SKUs that are on the
 * Master AON list. Centralizes the aon_skus <-> prs.sku relationship in one
 * place so callers (Cost Savings AON mode, and any future "AON Only" toggle)
 * never re-implement it or drift from the master definition.
 *
 * "On the AON list" mirrors exactly what the Master AON page treats as a real
 * AON SKU (pages/master_aon.php): a non-soft-deleted row with a non-blank
 * allocation. Active/Inactive validity-window status is intentionally NOT
 * applied here — Developer A computes that per-row for display rather than
 * filtering rows out, so a status filter would be new "active" logic this
 * feature must not invent.
 *
 * The join key (aon_skus.sku_code = TRIM(prs.sku)) matches the established
 * relationship already used in aon_get_repeat_purchase_candidates(). Returns a
 * bare "EXISTS (...)" expression (no leading AND / alias interpolation of
 * caller input) so it is safe to splice into a WHERE clause without binding.
 *
 * @param string $prs_alias table alias used for the prs table in the caller's query
 * @return string an EXISTS(...) SQL fragment
 */
function aon_sku_filter_sql($prs_alias = 'p') {
    // Only a fixed set of internal aliases is ever passed; guard anyway so this
    // can never become an injection surface if a caller gets careless.
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prs_alias)) {
        $prs_alias = 'p';
    }
    return "EXISTS (
        SELECT 1 FROM aon_skus a
        WHERE a.sku_code = TRIM($prs_alias.sku)
          AND a.is_deleted = 0
          AND a.alokasi IS NOT NULL AND a.alokasi != ''
    )";
}

/**
 * Same as aon_sku_filter_sql(), but additionally scoped to the AON SKUs claimed
 * by one STC (aon_skus.handler). Used by the STC dashboard so an STC sees only
 * the tickets that draw on a contract they own.
 *
 * The fragment carries a single `?` placeholder for the handler name; the caller
 * binds it (push the handler onto the params array at the matching position).
 * Everything else mirrors aon_sku_filter_sql() so the two never drift.
 *
 * @param string $prs_alias table alias used for the prs table in the caller's query
 * @return string an EXISTS(...) SQL fragment with one `?` placeholder (handler)
 */
function aon_sku_handler_filter_sql($prs_alias = 'p') {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $prs_alias)) {
        $prs_alias = 'p';
    }
    return "EXISTS (
        SELECT 1 FROM aon_skus a
        WHERE a.sku_code = TRIM($prs_alias.sku)
          AND a.is_deleted = 0
          AND a.alokasi IS NOT NULL AND a.alokasi != ''
          AND a.handler = ?
    )";
}

/**
 * Emit a JSON error response and stop. For use by AJAX endpoints.
 */
function aon_json_error($message, $code = 422) {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

/**
 * Normalize a nullable string field: trims, returns null when empty.
 */
function aon_nullable_string($value) {
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

/**
 * Normalize a nullable numeric field: returns null when blank, else float.
 */
function aon_nullable_number($value) {
    if ($value === null || trim((string)$value) === '') {
        return null;
    }
    return (float)$value;
}

/**
 * Normalize a nullable date (YYYY-MM-DD): returns null when blank/invalid.
 */
function aon_nullable_date($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    $d = DateTime::createFromFormat('Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/**
 * Format a DB timestamp (stored in UTC — see PDO::MYSQL_ATTR_INIT_COMMAND in
 * config/database.php) as Asia/Jakarta local time, same convention as
 * announcement_format_datetime() in announcement_helper.php.
 */
function aon_format_datetime($dateString) {
    if (empty($dateString)) {
        return null;
    }
    try {
        $dt = new DateTime($dateString, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $dt->format('d M Y H:i') . ' WIB';
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Effective remaining contract quantity for an AON SKU.
 *
 * remain = contract − used. A blank Used Qty means nothing has been drawn down
 * from the contract yet, so the full contract quantity is still available —
 * callers should show that instead of a blank. An explicitly stored/derived
 * remain_qty (from the Price CSV or a manual edit) always wins when present.
 * Returns null only when there is no contract qty to fall back to.
 *
 * @return float|null
 */
function aon_effective_remain_qty($quantity, $used_qty, $remain_qty = null) {
    $num = function ($v) {
        if ($v === null) return null;
        $v = str_replace([',', ' '], '', trim((string)$v));
        return ($v !== '' && is_numeric($v)) ? (float)$v : null;
    };
    $rem = $num($remain_qty);
    if ($rem !== null) return $rem;

    $q = $num($quantity);
    if ($q === null) return null;          // no contract qty → nothing to fall back to

    $u = $num($used_qty);
    if ($u === null) return $q;            // nothing used yet → full contract qty remains
    return $q - $u;
}

/**
 * Share of the contract quantity at or below which a SKU counts as running out.
 * 30%: contract qty 100 is low once 30 or fewer units remain.
 *
 * Raised from 10% in Aug 2026. Ten percent left is already too late to open a
 * renegotiation -- by the time the STC acts the contract is all but consumed --
 * so the warning now fires while there is still a third of the commitment to
 * work with. Everything that reads this moves in one step: the red Rem. Qty
 * figure on Master AON, the STC pop-up, and the WhatsApp reminder.
 */
const AON_LOW_REMAIN_RATIO = 0.30;

/**
 * Is this SKU running out of its contracted quantity?
 *
 * Inclusive at the threshold on purpose -- "contract 100, remaining 10" is the
 * case the STC warning was specified around, so it has to count as low rather
 * than sit one unit outside it. Both the red Rem. Qty figure on Master AON and
 * the STC pop-up (includes/aon_stock_alert.php) read this, so they can never
 * disagree about which rows are in trouble.
 *
 * @param mixed $quantity contract qty (raw column value)
 * @param mixed $remain   effective remaining qty, i.e. aon_effective_remain_qty()
 */
function aon_remain_is_low($quantity, $remain) {
    $ratio = aon_remain_ratio($quantity, $remain);
    return $ratio !== null && $ratio <= AON_LOW_REMAIN_RATIO;
}

/**
 * Remaining qty as a share of the contract qty, or null when there is no usable
 * contract qty to measure against. Never negative: an overdrawn contract reads
 * as 0 (fully consumed) rather than as a negative headroom.
 */
function aon_remain_ratio($quantity, $remain) {
    if ($remain === null || $remain === '' || !is_numeric($remain)) return null;
    $q = str_replace([',', ' '], '', trim((string)$quantity));
    if ($q === '' || !is_numeric($q) || (float)$q <= 0) return null;
    return max(0.0, (float)$remain / (float)$q);
}

/**
 * Does an item name flag it as an AON ("Always On") item? Matches "AON" as a
 * standalone, case-insensitive token so unrelated substrings (e.g. "AONE...")
 * don't false-positive.
 */
function aon_name_flags_aon($item_description) {
    return (bool)preg_match('/\bAON\b/i', (string)$item_description);
}

/**
 * Had the AON contract already started when this PR was raised?
 *
 * A PR raised before its SKU's contract validity begins cannot draw on that
 * contract — e.g. a PR dated 22 June against a contract valid from 2 July. Such
 * a line is ordinary procurement work that happens to name a future-AON SKU, so
 * every AON path (import routing, the backfill script, the dashboard badge)
 * must treat it as a regular ticket rather than as AON.
 *
 * Unknown dates never take a line out of AON: a SKU with no valid_from has no
 * start to be "before", and a PR with no date can't be shown to precede one.
 *
 * @param string|null $valid_from aon_skus.valid_from (Y-m-d), nullable
 * @param string|null $pr_date    prs.pr_date (Y-m-d), nullable
 */
function aon_contract_started(?string $valid_from, ?string $pr_date): bool {
    $valid_from = trim((string)$valid_from);
    $pr_date    = trim((string)$pr_date);
    if ($valid_from === '' || $pr_date === '') {
        return true;
    }
    // Both are Y-m-d, so a plain string comparison orders them correctly.
    return $pr_date >= $valid_from;
}

/**
 * PPN (Indonesian VAT) rate carried inside the PO amounts of the purchasing
 * organizations listed in aon_ppn_inclusive_porg_prefixes(). Their POs are
 * booked gross, so a realized average taken straight from po_amount reads
 * ~11% higher than the contract price it is compared against — the tax gets
 * mistaken for a price overrun. Every realized-average calculation therefore
 * nets the tax out first.
 */
function aon_ppn_rate(): float {
    return 0.11;
}

/**
 * POrg prefixes whose po_amount includes PPN. Matched on the first two
 * characters, so FF30/FY30/DF30 and any future site code under the same
 * company (FF31, DF40, …) are all covered.
 */
function aon_ppn_inclusive_porg_prefixes(): array {
    return ['FF', 'FY', 'DF'];
}

/**
 * SQL expression yielding the PPN-exclusive (net) amount of a PO line: the
 * gross amount divided by (1 + rate) for the PPN-inclusive POrgs, and left
 * untouched for everyone else. Returns a bare expression so callers can drop
 * it straight into a SUM() without binding anything.
 *
 * Column/alias names are validated rather than trusted — only internal literals
 * are ever passed, but this keeps it from becoming an injection surface if a
 * caller later gets careless (same guard as aon_sku_filter_sql()).
 *
 * @param string $alias      table alias holding the PO row ('' for none)
 * @param string $amount_col gross amount column
 * @param string $porg_col   purchasing-organization column
 */
function aon_net_amount_sql(string $alias = 'p', string $amount_col = 'po_amount', string $porg_col = 'p_org'): string {
    $ident = function ($v, $fallback) {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $v) ? $v : $fallback;
    };
    $amount_col = $ident($amount_col, 'po_amount');
    $porg_col   = $ident($porg_col, 'p_org');
    $prefix     = ($alias !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $alias)) ? $alias . '.' : '';

    $prefixes = implode(',', array_map(
        fn($p) => "'" . strtoupper($p) . "'",
        aon_ppn_inclusive_porg_prefixes()
    ));
    $divisor = sprintf('%.4F', 1 + aon_ppn_rate());

    return "CASE WHEN LEFT(UPPER(IFNULL({$prefix}{$porg_col}, '')), 2) IN ({$prefixes})"
         . " THEN {$prefix}{$amount_col} / {$divisor}"
         . " ELSE {$prefix}{$amount_col} END";
}

/**
 * Classify a dashboard ticket line against the Master AON list so the
 * procurement team can filter/see AON coverage at a glance. Returns:
 *   status: 'green'  — on the Master AON list, contract valid AND remaining qty
 *                       covers the ticket qty
 *           'red'    — on the Master AON list, but validity expired/not-started
 *                       OR remaining qty can't cover the ticket qty
 *           'yellow' — item name says AON but the SKU isn't on the Master AON
 *                       list yet (potential AON the STC hasn't registered)
 *           'none'   — not an AON item, including a line raised before its
 *                       contract started (see aon_contract_started())
 *   reason: human-readable explanation (shown for yellow/red)
 *
 * @param array  $master  uppercased-trimmed sku_code => aon_skus row
 *                        (quantity, used_qty, remain_qty, valid_from, valid_until)
 * @param string|null $pr_date prs.pr_date for the line — decides whether the
 *                        contract had started yet when the PR was raised
 */
function aon_classify_ticket(array $master, $item_description, $sku, $ticket_qty, string $today, string $po_number = '', ?string $pr_date = null): array {
    $key      = strtoupper(trim((string)$sku));
    $on_list  = $key !== '' && isset($master[$key]);
    $name_aon = aon_name_flags_aon($item_description);

    if (!$on_list && !$name_aon) {
        return ['status' => 'none', 'reason' => ''];
    }

    // Raised before the contract's validity started -> this line could not draw
    // on the contract and is handled as an ordinary ticket, so it carries no AON
    // badge at all. Checked ahead of everything else (including the PO-created
    // grey) because it decides whether this is AON work in the first place, not
    // how healthy the contract is. An "AON" in the item name doesn't override it:
    // the contract it refers to genuinely wasn't live yet.
    if ($on_list && !aon_contract_started($master[$key]['valid_from'] ?? null, $pr_date)) {
        return ['status' => 'none', 'reason' => ''];
    }

    // If PO is created, active monitoring of validity/qty is no longer relevant.
    if (trim($po_number) !== '') {
        return [
            'status' => 'gray',
            'reason' => 'PO Created (AON Item)',
        ];
    }

    // AON by name only — not registered on the Master AON page yet.
    if (!$on_list) {
        return [
            'status' => 'yellow',
            'reason' => 'Not on Master AON list',
        ];
    }

    // On the Master AON list → green/red by validity window + remaining qty.
    $m = $master[$key];
    $vfrom  = $m['valid_from']  ?? null;
    $vuntil = $m['valid_until'] ?? null;
    $expired     = $vuntil && $vuntil < $today;
    $not_started = $vfrom  && $vfrom  > $today;
    $valid = !$expired && !$not_started;

    $remain = aon_effective_remain_qty($m['quantity'] ?? null, $m['used_qty'] ?? null, $m['remain_qty'] ?? null);
    $tq_raw = str_replace([',', ' '], '', trim((string)$ticket_qty));
    $tq     = ($tq_raw !== '' && is_numeric($tq_raw)) ? (float)$tq_raw : null;

    // Enough when remaining is known and (ticket qty unknown → can't disprove, or
    // remaining covers it). Unknown remaining can't be confirmed → treat as short.
    $enough = ($remain !== null) && ($tq === null || $remain >= $tq);

    if ($valid && $enough) {
        return [
            'status' => 'green',
            'reason' => 'On the Master AON list — contract is valid and remaining qty covers this ticket.',
        ];
    }

    $reasons = [];
    if (!$valid) {
        if ($expired)          $reasons[] = 'Validity has expired';
        elseif ($not_started)  $reasons[] = 'Validity not started yet';
        else                   $reasons[] = 'Validity date not set';
    }
    if (!$enough) {
        if ($remain === null) {
            $reasons[] = 'Remaining qty unknown';
        } else {
            // Only reachable when both numbers are known and remain < ticket qty
            // (an unknown ticket qty can't disprove coverage, so it counts as enough).
            // Show the shortfall itself — always negative — e.g. "Qty insufficient (-2)".
            $fmt = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ','), '0'), '.');
            $reasons[] = 'Qty insufficient (' . $fmt($remain - $tq) . ')';
        }
    }

    return [
        'status' => 'red',
        'reason' => implode(' · ', $reasons),
    ];
}

/**
 * Render the small inline AON badge shown next to the "Line …" note under an
 * item description on the manager dashboard. Kept to the same 10px scale as
 * that note so AON and non-AON rows stay the same height. The full reason is
 * exposed via the hover tooltip; the color legend above the table explains the
 * meaning of each color. Returns '' for non-AON rows.
 */
function aon_dashboard_badge_html(?string $status, ?string $reason): string {
    if (!$status || $status === 'none') return '';
    $map = [
        'green'  => ['bg-green-100 text-green-700 border-green-300',    'text-green-600'],
        'red'    => ['bg-red-100 text-red-700 border-red-300',          'text-red-600'],
        'yellow' => ['bg-yellow-100 text-yellow-800 border-yellow-300', 'text-yellow-700'],
        'gray'   => ['bg-gray-100 text-gray-700 border-gray-300',       'text-gray-600'],
    ];
    if (!isset($map[$status])) return '';
    [$badge_cls, $reason_cls] = $map[$status];

    $safe_reason = htmlspecialchars((string)$reason, ENT_QUOTES);
    $html = '<span class="inline-flex items-center text-[10px] font-bold px-1.5 rounded border leading-[15px] '
        . $badge_cls . '" title="' . $safe_reason . '">AON</span>';
    // Only red needs spelling out inline (what's wrong with the contract). Green
    // and yellow are carried by the badge color alone — see the legend above the
    // table — and keep their explanation in the hover tooltip.
    if ($status === 'red' && $reason !== null && $reason !== '') {
        $html .= '<span class="text-[10px] font-semibold leading-[15px] ' . $reason_cls . '">' . $safe_reason . '</span>';
    }
    return $html;
}

/**
 * Collapse raw (sku, name, vendor) purchases_2025 aggregates down to one
 * unit price per (sku, name). A single item name is sometimes purchased
 * from 2+ vendors — blending those together would misrepresent the price,
 * since a different vendor may not be a comparable quote. Instead:
 *   - if one of the vendors matches this SKU's current contract vendor
 *     (fuzzily — spelling is inconsistent between purchases_2025 and the
 *     Price Per Vendor / aon_skus vendor field), keep only that vendor's rows
 *   - otherwise keep whichever vendor accounts for the most purchased qty
 * The losing vendor(s) are dropped entirely rather than shown or blended in.
 *
 * @param array $raw        sku => name => vendor => ['qty' => float, 'amount' => float]
 * @param array $sku_vendor sku => current contract vendor name (nullable)
 * @return array sku => name => unit_price (float, or null if qty was 0)
 */
function aon_resolve_vendor_price(array $raw, array $sku_vendor) {
    $norm = fn($s) => preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s));

    $resolved = [];
    foreach ($raw as $sku => $names) {
        $current_vendor = $norm($sku_vendor[$sku] ?? '');
        foreach ($names as $name => $vendors) {
            if (count($vendors) === 1) {
                $v = reset($vendors);
                $resolved[$sku][$name] = $v['qty'] > 0 ? $v['amount'] / $v['qty'] : null;
                continue;
            }

            $winner = null;
            if ($current_vendor !== '') {
                foreach ($vendors as $vendor_name => $v) {
                    if ($norm($vendor_name) === $current_vendor) { $winner = $v; break; }
                }
            }
            if ($winner === null) {
                foreach ($vendors as $v) {
                    if ($winner === null || $v['qty'] > $winner['qty']) $winner = $v;
                }
            }
            $resolved[$sku][$name] = ($winner && $winner['qty'] > 0) ? $winner['amount'] / $winner['qty'] : null;
        }
    }
    return $resolved;
}

/**
 * Build the 2025 price-comparison pairs for one AON SKU, covering two cases
 * found in purchases_2025 (prices already vendor-resolved by
 * aon_resolve_vendor_price() before reaching here):
 *   - this SKU was purchased under 2+ distinct item names in 2025
 *   - this item name was purchased under 2+ distinct SKU codes in 2025
 *     (common when a SKU is re-coded between years but is still the same item)
 * Entries from a different SKU than $sku are tagged with that SKU in the
 * name text, since the name itself may be identical and otherwise indistinguishable.
 *
 * @param array $by_sku  sku => [['sku'=>,'name'=>,'price'=>], ...]
 * @param array $by_name NAME (uppercased) => [['sku'=>,'name'=>,'price'=>], ...]
 */
function aon_build_2025_pairs($sku, $item_name, array $by_sku, array $by_name) {
    $pairs = [];
    $seen  = [];

    $add = function ($entry) use (&$pairs, &$seen, $sku) {
        $key = $entry['sku'] . '|' . $entry['price'];
        if (isset($seen[$key])) return;
        $seen[$key] = true;
        $name = $entry['name'];
        // Cast before comparing: SKU codes that look like plain integers
        // (e.g. "17213") get silently turned into int array keys whenever
        // they pass through an associative array (PHP's numeric-key rule),
        // while others arrive as strings straight from PDO — a strict
        // comparison would then wrongly flag a SKU as "foreign" to itself.
        if ((string)$entry['sku'] !== (string)$sku) $name .= ' (SKU: ' . $entry['sku'] . ')';
        $pairs[] = ['name' => $name, 'price' => $entry['price']];
    };

    $names_to_check = [];
    foreach ($by_sku[$sku] ?? [] as $entry) {
        $add($entry);
        $names_to_check[strtoupper($entry['name'])] = true;
    }
    $item_name = trim((string)$item_name);
    if ($item_name !== '') $names_to_check[strtoupper($item_name)] = true;

    foreach (array_keys($names_to_check) as $norm_name) {
        foreach ($by_name[$norm_name] ?? [] as $entry) {
            $add($entry);
        }
    }
    return $pairs;
}

/**
 * Allowed time-window choices (in months) for the Repeat Purchase Alert filter.
 */
function aon_alert_window_choices() {
    return [1, 3, 6, 12, 24, 36];
}

/**
 * Find SKUs bought repeatedly via ad-hoc Purchase Requests (prs table) that
 * are NOT already an AON contract — candidates for Price Lock negotiation.
 *
 * "Repeated" = purchased under >= $min_count distinct PR tickets within the
 * last $window_months. Uses the same realized-purchase filters as the
 * Cost Savings Matrix (pages/cost_savings.php) so noise from cancelled/
 * intercompany/never-fulfilled PRs doesn't inflate the count.
 *
 * @return array of ['sku','item_name','taxonomy','purchase_count','po_count',
 *   'total_qty','total_po_price','avg_unit_price',
 *   'vendors' (comma-joined), 'po_rows' (list of the individual PO purchases —
 *   each ['po_number','ticket_no','pr_date','qty','vendor_name','po_amount'], newest first,
 *   so STC can verify which PO actually bought this SKU and when), plus
 *   aon_alert_reviews tracking (is_checked, review_status, remarks, checked_by, checked_at) — all nullable.
 */
function aon_get_repeat_purchase_candidates(PDO $pdo, int $window_months, int $min_count): array {
    $window_months = in_array($window_months, aon_alert_window_choices(), true) ? $window_months : 6;
    $min_count     = max(2, $min_count);
    $since         = date('Y-m-d', strtotime("-{$window_months} months"));

    // Aggregate the ad-hoc PR purchases per SKU first, then LEFT JOIN the
    // per-SKU review row afterward. aon_alert_reviews holds at most one row per
    // sku_code (UNIQUE uq_sku_code), so joining it after the GROUP BY yields the
    // exact same rows as joining before — but keeps the descriptive review
    // columns out of the aggregation, which ONLY_FULL_GROUP_BY requires (they
    // are per-SKU metadata, not grouping keys, and MySQL 8 will not treat them
    // as functionally dependent on an expression GROUP BY key like TRIM(p.sku)).
    // SKUs are grouped on TRIM(p.sku), so the grouped SKU is selected as
    // TRIM(p.sku) to match the group key exactly.
    $sql = "
        SELECT
            g.sku,
            g.item_names,
            g.purchase_count,
            g.po_count,
            g.last_purchase_date,
            g.total_qty,
            g.total_po_price,
            g.vendors,
            r.is_checked, r.review_status, r.remarks, r.checked_by, r.checked_at
        FROM (
            SELECT
                TRIM(p.sku)                                       AS sku,
                GROUP_CONCAT(p.item_description ORDER BY p.pr_date DESC SEPARATOR '||') AS item_names,
                COUNT(DISTINCT p.ticket_no)                       AS purchase_count,
                COUNT(DISTINCT p.po_number)                       AS po_count,
                MAX(p.pr_date)                                    AS last_purchase_date,
                SUM(p.qty)                                        AS total_qty,
                SUM(p.po_amount)                                  AS total_po_price,
                GROUP_CONCAT(DISTINCT p.vendor_name SEPARATOR '||') AS vendors
            FROM prs p
            WHERE p.sku IS NOT NULL AND TRIM(p.sku) != ''
              AND p.po_number IS NOT NULL AND p.po_number != ''
              AND p.qty > 0
              AND p.pr_date >= ?
              AND IFNULL(p.ticket_type, '') NOT IN ('Intercompany', 'Intercompany New Store')
              AND LOWER(IFNULL(p.status_ticket, '')) NOT LIKE '%cancel%'
              AND NOT EXISTS (
                  SELECT 1 FROM aon_skus a WHERE a.sku_code = TRIM(p.sku) AND a.is_deleted = 0
              )
            GROUP BY TRIM(p.sku)
            HAVING purchase_count >= ?
        ) g
        LEFT JOIN aon_alert_reviews r ON r.sku_code = g.sku
        ORDER BY g.purchase_count DESC, g.last_purchase_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$since, $min_count]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $names = $row['item_names'] ? explode('||', $row['item_names']) : [];
        $row['item_name'] = $names[0] ?? $row['sku'];
        unset($row['item_names']);

        $row['vendors'] = $row['vendors'] ? implode(', ', array_unique(explode('||', $row['vendors']))) : null;

        $row['total_po_price'] = $row['total_po_price'] !== null ? (float)$row['total_po_price'] : null;
        $row['avg_unit_price'] = ($row['total_po_price'] !== null && (float)$row['total_qty'] > 0)
            ? $row['total_po_price'] / (float)$row['total_qty']
            : null;
    }
    unset($row);

    // For these ad-hoc-PR SKUs (never assigned a proper AON SKU code), the
    // "SKU" value in prs.sku is itself the master_data.id of the item —
    // confirmed against live data (e.g. SKU "3301" is master_data.id 3301's
    // taxonomy row). Match directly on that numeric id.
    $taxonomy_by_id = aon_get_taxonomy_by_master_data_ids($pdo, array_column($rows, 'sku'));
    foreach ($rows as &$row) {
        $sku_id = (int)trim((string)$row['sku']);
        $tax = $taxonomy_by_id[$sku_id] ?? null;
        $row['taxonomy'] = aon_format_taxonomy($tax);
        // Raw per-level values (not the joined display string) so exports can put
        // each level in its own column instead of one "L0 > L1 > ..." string.
        $row['taxonomy_levels'] = [
            $tax['level_0'] ?? '',
            $tax['level_1'] ?? '',
            $tax['level_2'] ?? '',
            $tax['level_3'] ?? '',
            $tax['level_4'] ?? '',
        ];
    }
    unset($row);

    $po_rows_by_sku = aon_get_repeat_purchase_po_rows($pdo, array_column($rows, 'sku'), $since);
    foreach ($rows as &$row) {
        $row['po_rows'] = $po_rows_by_sku[strtoupper(trim((string)$row['sku']))] ?? [];

        // Top vendor = highest total qty purchased for this SKU, so the UI can
        // show one representative vendor instead of a long concatenated list.
        $vendor_qty = [];
        foreach ($row['po_rows'] as $p) {
            $vn = trim((string)($p['vendor_name'] ?? ''));
            if ($vn === '') continue;
            $vendor_qty[$vn] = ($vendor_qty[$vn] ?? 0) + (float)($p['qty'] ?? 0);
        }
        arsort($vendor_qty);
        $row['top_vendor'] = $vendor_qty ? array_key_first($vendor_qty) : null;
    }
    unset($row);

    return $rows;
}

/**
 * Batch-fetch the taxonomy path (level_0 > level_1 > level_2 > level_3 >
 * level_4) for a set of master_data.id values, so
 * aon_get_repeat_purchase_candidates() can show each SKU's category without
 * an N+1 query per row.
 *
 * @param (int|string|null)[] $master_data_ids
 * @return array keyed by master_data.id => ['level_0','level_1','level_2','level_3','level_4']
 */
function aon_get_taxonomy_by_master_data_ids(PDO $pdo, array $master_data_ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $master_data_ids))));
    if (empty($ids)) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, level_0, level_1, level_2, level_3, level_4 FROM master_data WHERE id IN ($placeholders)");
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['id']] = $r;
    }
    return $out;
}

/**
 * Join a master_data taxonomy row (level_0..level_4) into a single
 * "Level 0 > Level 1 > Level 2 > Level 3 > Level 4" display string, skipping
 * blank levels. Returns null when there's no taxonomy match at all.
 */
function aon_format_taxonomy(?array $taxonomy): ?string {
    if (!$taxonomy) return null;
    $parts = array_filter(
        [$taxonomy['level_0'] ?? '', $taxonomy['level_1'] ?? '', $taxonomy['level_2'] ?? '', $taxonomy['level_3'] ?? '', $taxonomy['level_4'] ?? ''],
        fn($v) => trim((string)$v) !== ''
    );
    return $parts ? implode(' > ', $parts) : null;
}

/**
 * Fetch the individual PO purchase line items backing aon_get_repeat_purchase_candidates()'s
 * counts, so the alert page can list each PO number and the date it purchased
 * the SKU (instead of only a total purchase count / a first→last date range).
 * Uses the same realized-purchase filters as that function.
 *
 * @param string[] $skus
 * @return array keyed by uppercased/trimmed SKU => list of
 *   ['po_number','ticket_no','pr_date','qty','vendor_name','po_amount'], newest first.
 */
function aon_get_repeat_purchase_po_rows(PDO $pdo, array $skus, string $since): array {
    $skus = array_values(array_unique(array_filter(array_map(fn($s) => trim((string)$s), $skus), fn($s) => $s !== '')));
    if (empty($skus)) return [];

    $placeholders = implode(',', array_fill(0, count($skus), '?'));
    $sql = "
        SELECT TRIM(p.sku) AS sku, p.po_number, p.ticket_no, p.pr_date, p.qty, p.vendor_name, p.po_amount
        FROM prs p
        WHERE TRIM(p.sku) IN ($placeholders)
          AND p.po_number IS NOT NULL AND p.po_number != ''
          AND p.qty > 0
          AND p.pr_date >= ?
          AND IFNULL(p.ticket_type, '') NOT IN ('Intercompany', 'Intercompany New Store')
          AND LOWER(IFNULL(p.status_ticket, '')) NOT LIKE '%cancel%'
        ORDER BY p.pr_date DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([...$skus, $since]);

    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $grouped[strtoupper($r['sku'])][] = [
            'po_number'   => $r['po_number'],
            'ticket_no'   => $r['ticket_no'],
            'pr_date'     => $r['pr_date'],
            'qty'         => $r['qty'],
            'vendor_name' => $r['vendor_name'],
            'po_amount'   => $r['po_amount'],
        ];
    }
    return $grouped;
}

/**
 * Realized average unit price per SKU for one calendar year, taken from the PRs
 * that actually produced a PO.
 *
 * aon_skus.price is the contracted 2026 price as last uploaded or edited — one
 * number that says nothing about what the SKU has really been bought for since
 * January. This averages the year's own purchases instead: SUM(po_amount) /
 * SUM(qty), which normalizes a multi-qty PO line down to a single unit exactly
 * like the 2025 reference price in aon_parser.php. MIN/MAX(pr_date) come back
 * with it so the caller can state which months the average actually covers.
 *
 * The realized-purchase filters match aon_get_repeat_purchase_candidates() and
 * pages/cost_savings.php, so cancelled, intercompany and never-fulfilled PRs
 * cannot skew the average. Every vendor that sold the SKU counts, not just the
 * AON contract vendor: an off-contract purchase is still part of what the year
 * really cost. No currency conversion is applied, as in cost_savings_helper.php.
 *
 * Amounts are netted of PPN first (aon_net_amount_sql()): the FF, FY and DF
 * POrgs book their POs gross, and averaging those against a PPN-exclusive
 * contract price made the realized figure read ~11% high — reported as a price
 * overrun that was really just the tax.
 *
 * @return array keyed by uppercased/trimmed SKU => [
 *   'avg_price' (float), 'total_qty' (float), 'total_amount' (float),
 *   'po_count' (int), 'first_date' (Y-m-d), 'last_date' (Y-m-d)]
 */
function aon_get_realized_year_prices(PDO $pdo, int $year): array {
    $net_amount = aon_net_amount_sql('p');
    $sql = "
        SELECT TRIM(p.sku)                 AS sku,
               SUM($net_amount)            AS total_amount,
               SUM(p.qty)                  AS total_qty,
               COUNT(DISTINCT p.po_number) AS po_count,
               MIN(p.pr_date)              AS first_date,
               MAX(p.pr_date)              AS last_date
        FROM prs p
        WHERE p.sku IS NOT NULL AND TRIM(p.sku) != ''
          AND p.po_number IS NOT NULL AND p.po_number != ''
          AND p.qty > 0
          AND p.po_amount IS NOT NULL
          AND p.pr_date >= ? AND p.pr_date < ?
          AND IFNULL(p.ticket_type, '') NOT IN ('Intercompany', 'Intercompany New Store')
          AND LOWER(IFNULL(p.status_ticket, '')) NOT LIKE '%cancel%'
        GROUP BY TRIM(p.sku)
    ";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1)]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return [];   // fail open: the caller renders an empty box, not an error
    }

    $out = [];
    foreach ($rows as $r) {
        $qty = (float)$r['total_qty'];
        if ($qty <= 0) continue;
        $out[strtoupper($r['sku'])] = [
            'avg_price'    => (float)$r['total_amount'] / $qty,
            'total_qty'    => $qty,
            'total_amount' => (float)$r['total_amount'],
            'po_count'     => (int)$r['po_count'],
            'first_date'   => $r['first_date'],
            'last_date'    => $r['last_date'],
        ];
    }
    return $out;
}

/**
 * Month span a realized average covers: "Jan – Jul 2026", "Mar 2026" when both
 * ends fall in one month, "Dec 2025 – Feb 2026" when they cross a year. Returns
 * '' when either end is missing, so callers can just test for truthiness.
 */
function aon_format_month_range(?string $from_iso, ?string $to_iso): string {
    $from = $from_iso ? strtotime($from_iso) : false;
    $to   = $to_iso   ? strtotime($to_iso)   : false;
    if ($from === false || $to === false) return '';

    $from_month = date('M', $from);
    $to_month   = date('M', $to);
    $from_year  = date('Y', $from);
    $to_year    = date('Y', $to);

    if ($from_year !== $to_year)   return "$from_month $from_year – $to_month $to_year";
    if ($from_month === $to_month) return "$from_month $from_year";
    return "$from_month – $to_month $to_year";
}
