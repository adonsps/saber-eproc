<?php
// includes/currency_helper.php
//
// Shared multi-currency support for Cost Avoidance: the `currencies` table
// (code, name, symbol, rate_to_idr, is_active) plus the PHP/SQL conversion
// helpers built on top of it. cost_avoidance_helper.php's to_idr()-era
// functions (ca_po_idr_expr(), ca_baseline_idr_expr()) delegate here so the
// wider Cost Avoidance code doesn't need to change call sites.
//
// Rates are our own trusted DECIMAL config from the `currencies` table, not
// user input, so inlining them as SQL literals (same idiom the old
// ca_rate_literal() used for the single USD rate) is safe.

/**
 * code => ['name'=>, 'symbol'=>, 'rate_to_idr'=>float] for every active
 * currency. Call once per request and pass the result around.
 */
function ca_get_active_currencies(PDO $pdo): array {
    $rows = $pdo->query("SELECT code, name, symbol, rate_to_idr FROM currencies WHERE is_active = 1 ORDER BY code")
                ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[strtoupper($r['code'])] = [
            'name'        => $r['name'],
            'symbol'      => $r['symbol'],
            'rate_to_idr' => (float)$r['rate_to_idr'],
        ];
    }
    return $out;
}

/**
 * code => [...] for every currency that has a usable rate (> 0), ACTIVE OR NOT.
 * This is the map IDR conversion uses: "active" only controls what a person can
 * pick for a NEW entry (ca_get_active_currencies()), so switching a currency off
 * in Settings can never re-value rows that were already saved in it.
 */
function ca_get_convertible_currencies(PDO $pdo): array {
    $rows = $pdo->query("SELECT code, name, symbol, rate_to_idr FROM currencies WHERE rate_to_idr > 0 ORDER BY code")
                ->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($rows as $r) {
        $out[strtoupper(trim($r['code']))] = [
            'name'        => $r['name'],
            'symbol'      => $r['symbol'],
            'rate_to_idr' => (float)$r['rate_to_idr'],
        ];
    }
    return $out;
}

/**
 * Does any saved row use this currency? (baseline / PO on prs, Manual Cost
 * Avoidance, saved Initial Quotation Prices.) Used to refuse taking a rate away
 * from a currency that still has data. Tables/columns that don't exist yet are
 * skipped, same fail-open idiom as the rest of the helper.
 */
function ca_currency_in_use(PDO $pdo, string $code): bool {
    $code = strtoupper(trim($code));
    foreach ([['prs', 'baseline_currency'], ['prs', 'po_currency'], ['manual_cost_avoidance', 'currency'], ['vendor_quotation_last_price', 'currency']] as [$table, $col]) {
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM `{$table}` WHERE UPPER(TRIM(`{$col}`)) = ? LIMIT 1");
            $stmt->execute([$code]);
            if ($stmt->fetchColumn() !== false) return true;
        } catch (\PDOException $e) {
            // missing table/column: nothing to protect there
        }
    }
    return false;
}

/** True when $ctx came from ca_context() and so carries the convertible map (Cost Avoidance fails closed). */
function ca_is_strict_ctx(array $ctx): bool {
    return array_key_exists('currencies_all', $ctx);
}

/**
 * Convert an amount to IDR using an active-currency map (see
 * ca_get_active_currencies()). IDR and any code missing from the map (not
 * active / not configured) pass through unchanged — same fail-open rule the
 * old binary USD-only to_idr() used for "anything that isn't USD".
 */
function ca_to_idr(float $amount, ?string $currency, array $currencies, ?float $rateOverride = null, bool $failClosed = false): float {
    $code = strtoupper(trim((string)$currency));
    if ($code === '' || $code === 'IDR') {
        return $amount;
    }
    // A per-row snapshot rate (the rate the user entered the price at) wins
    // over the current currencies.rate_to_idr; NULL/<=0 = legacy row -> current.
    if ($rateOverride !== null && $rateOverride > 0) {
        return $amount * $rateOverride;
    }
    if (!isset($currencies[$code]) || $currencies[$code]['rate_to_idr'] <= 0) {
        // $failClosed (Cost Avoidance): a foreign amount with no usable rate is
        // left out (0), never passed through as if it were rupiah.
        return $failClosed ? 0.0 : $amount;
    }
    return $amount * $currencies[$code]['rate_to_idr'];
}

/**
 * Parse an amount typed by a person. A plain decimal ("1250000", "1250000.50",
 * "0.5") is taken as-is -- that is what <input type="number"> and the DB send,
 * and it must NOT be re-read as a thousands pattern ("1.250" is 1.25 there).
 * Anything else ("Rp 1.250.000,50", "1,250,000.50", "1250000,5") goes through
 * the lenient Indonesian/English separator inference the bulk CSV import has
 * always used. Returns ['ok'=>bool, 'value'=>?float]; empty input is ok/null.
 */
function ca_parse_amount($v): array {
    if ($v === null) return ['ok' => true, 'value' => null];
    if (is_int($v) || is_float($v)) return ['ok' => true, 'value' => (float)$v];
    $s = trim((string)$v);
    if ($s === '') return ['ok' => true, 'value' => null];
    if (preg_match('/^-?\d+(\.\d+)?$/', $s)) return ['ok' => true, 'value' => (float)$s];
    $s = preg_replace('/[^0-9.,\-]/', '', $s);
    if ($s === '' || $s === '-') return ['ok' => false, 'value' => null];
    $hasDot = strpos($s, '.') !== false;
    $hasComma = strpos($s, ',') !== false;
    if ($hasDot && $hasComma) {
        // Whichever separator comes last is the decimal one.
        if (strrpos($s, ',') > strrpos($s, '.')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        else { $s = str_replace(',', '', $s); }
    } elseif ($hasDot) {
        if (preg_match('/^-?\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);
    } elseif ($hasComma) {
        $s = preg_match('/^-?\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
    }
    if (!is_numeric($s)) return ['ok' => false, 'value' => null];
    return ['ok' => true, 'value' => (float)$s];
}

/**
 * THE one place a currency + optional exchange rate typed by a user is
 * validated and resolved, shared by the Initial Quotation Price modal, the
 * bulk CSV import and Manual Cost Avoidance so they can never disagree.
 *
 *  - blank currency  -> IDR (rate null: IDR needs no rate);
 *  - the currency must be active (ca_get_active_currencies());
 *  - non-IDR: a blank rate defaults to the CURRENT currencies.rate_to_idr; a
 *    typed rate must be a number > 0 (rounded to 4 dp, the column's scale);
 *    with no typed rate and no configured rate the currency is unusable.
 *
 * Returns ['ok'=>bool, 'currency'=>string, 'rate'=>?float, 'message'=>string].
 * 'rate' is the snapshot to store (null for IDR).
 */
function ca_resolve_fx($currency, $rateInput, array $currencies): array {
    $code = strtoupper(trim((string)$currency));
    if ($code === '') $code = 'IDR';
    if (!ca_is_valid_currency($code, $currencies)) {
        $avail = implode(', ', array_keys($currencies));
        return ['ok' => false, 'currency' => $code, 'rate' => null,
                'message' => "Currency \"$code\" is not enabled in the system" . ($avail !== '' ? " (available: $avail)" : '')
                           . ' - use one of those, or ask an admin to enable it under Cost Avoidance > Settings.'];
    }
    if ($code === 'IDR') {
        return ['ok' => true, 'currency' => 'IDR', 'rate' => null, 'message' => ''];
    }
    $current = (float)($currencies[$code]['rate_to_idr'] ?? 0);
    $raw = is_string($rateInput) ? trim($rateInput) : $rateInput;
    if ($raw === null || $raw === '') {
        if ($current <= 0) {
            return ['ok' => false, 'currency' => $code, 'rate' => null, 'message' => "No exchange rate is set for $code - enter one."];
        }
        return ['ok' => true, 'currency' => $code, 'rate' => round($current, 4), 'message' => ''];
    }
    $p = ca_parse_amount($raw);
    if (!$p['ok'] || $p['value'] === null || $p['value'] <= 0) {
        return ['ok' => false, 'currency' => $code, 'rate' => null, 'message' => "Exchange rate for $code must be a number greater than 0."];
    }
    $rate = round($p['value'], 4);
    if ($rate <= 0) {
        return ['ok' => false, 'currency' => $code, 'rate' => null, 'message' => "Exchange rate for $code is too small."];
    }
    return ['ok' => true, 'currency' => $code, 'rate' => $rate, 'message' => ''];
}

/**
 * Back-compat shim: some callers (e.g. cost_savings_helper.php's cs_context())
 * still build a $ctx with only a flat 'usd_rate' float, not a 'currencies'
 * map. Prefer $ctx['currencies'] when present; otherwise synthesize a
 * USD-only map from $ctx['usd_rate'] so those callers keep their exact
 * pre-multi-currency SQL/behavior.
 */
function ca_effective_currencies(array $ctx): array {
    // Conversion map (every currency with a rate, active or not) when ca_context()
    // supplied one; it is a superset of the active map used for validation.
    if (isset($ctx['currencies_all']) && is_array($ctx['currencies_all'])) {
        return $ctx['currencies_all'];
    }
    if (!empty($ctx['currencies']) && is_array($ctx['currencies'])) {
        return $ctx['currencies'];
    }
    return ['USD' => ['name' => 'US Dollar', 'symbol' => '$', 'rate_to_idr' => (float)($ctx['usd_rate'] ?? 16000)]];
}

/**
 * SQL CASE fragment converting an $amountCol tagged by $currencyCol to IDR,
 * built from an active-currency map. `+ 0e0` on every branch preserves the
 * DOUBLE-arithmetic numeric-parity idiom the rest of cost_avoidance_helper.php
 * uses (see its file-level NUMERIC PARITY note).
 */
function ca_currency_case_expr(string $amountCol, string $currencyCol, array $currencies, bool $failClosed = false): string {
    $whens = [];
    foreach ($currencies as $code => $c) {
        if ($code === 'IDR' || (float)$c['rate_to_idr'] <= 0) continue;
        $rate = sprintf('%.10F', (float)$c['rate_to_idr']);
        $whens[] = "WHEN '{$code}' THEN ({$amountCol} + 0e0) * {$rate}";
    }
    $whenSql = implode("\n                  ", $whens);
    if ($failClosed) {
        // Cost Avoidance: only IDR (or a blank code) is taken at face value. Any
        // other code with no usable rate (unknown, zero-rate, typo) yields NULL, so
        // the row drops out of SUMs instead of being valued as rupiah.
        return "(CASE " . ca_currency_code_expr($currencyCol) . "
                  {$whenSql}
                  WHEN 'IDR' THEN ({$amountCol} + 0e0)
                  ELSE NULL END)";
    }
    return "(CASE UPPER(COALESCE({$currencyCol}, 'IDR'))
                  {$whenSql}
                  ELSE ({$amountCol} + 0e0) END)";
}

/** Normalised currency code for SQL: trimmed, upper-case, blank/NULL = IDR. */
function ca_currency_code_expr(string $currencyCol): string {
    return "UPPER(TRIM(COALESCE(NULLIF(TRIM({$currencyCol}), ''), 'IDR')))";
}

/**
 * Same as ca_currency_case_expr() but honours a per-row SNAPSHOT rate column:
 * a non-IDR row whose $rateCol is > 0 converts at that stored rate (the rate
 * the price was entered at); a NULL/0 rate (legacy row) falls through to the
 * current-rate CASE, so existing figures do not move. Used for the Cost
 * Avoidance baseline (prs.baseline_fx_rate) and manual entries (fx_rate).
 */
function ca_currency_case_expr_rate(string $amountCol, string $currencyCol, string $rateCol, array $currencies, bool $failClosed = false): string {
    $current = ca_currency_case_expr($amountCol, $currencyCol, $currencies, $failClosed);
    $code = $failClosed ? ca_currency_code_expr($currencyCol) : "UPPER(COALESCE({$currencyCol}, 'IDR'))";
    return "(CASE WHEN {$code} <> 'IDR' AND COALESCE({$rateCol}, 0) > 0
                  THEN ({$amountCol} + 0e0) * ({$rateCol} + 0e0)
                  ELSE {$current} END)";
}

/**
 * PO amount converted to IDR at the FROZEN rate of its baseline: when the PO and
 * the baseline are in the same foreign currency and the baseline carries a
 * snapshot rate, the PO is converted at that same rate, so avoidance reflects
 * price only and not exchange-rate drift between quoting and today. Every other
 * row (IDR, no snapshot, different currency) converts at the current rate.
 * Fails closed like ca_currency_case_expr(..., true).
 */
function ca_currency_case_expr_frozen(string $amountCol, string $poCurrencyCol, string $baseCurrencyCol, string $rateCol, array $currencies): string {
    $current = ca_currency_case_expr($amountCol, $poCurrencyCol, $currencies, true);
    $po = ca_currency_code_expr($poCurrencyCol);
    $bc = ca_currency_code_expr($baseCurrencyCol);
    return "(CASE WHEN {$po} <> 'IDR' AND {$po} = {$bc} AND COALESCE({$rateCol}, 0) > 0
                  THEN ({$amountCol} + 0e0) * ({$rateCol} + 0e0)
                  ELSE {$current} END)";
}

/** Whether $code is one of the active currencies in the given map (case-insensitive). */
function ca_is_valid_currency(?string $code, array $currencies): bool {
    return isset($currencies[strtoupper((string)$code)]);
}

/** code => symbol for every active currency, for money-display lookups. */
function ca_currency_symbols(array $currencies): array {
    $out = ['IDR' => 'Rp'];
    foreach ($currencies as $code => $c) {
        $out[$code] = $c['symbol'];
    }
    return $out;
}
