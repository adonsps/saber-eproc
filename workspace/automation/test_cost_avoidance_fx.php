<?php
declare(strict_types=1);

// Pure-logic test for the Initial Quotation Price / Manual Cost Avoidance
// exchange-rate work: one shared parser + FX resolver, the rate-aware SQL
// fragment, the price snapshot/diff helpers and the Savings Report predicate.
// No DB connection needed (all of it is validation / SQL-shape logic).
//   docker-compose exec app php workspace/automation/test_cost_avoidance_fx.php

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/cost_avoidance_helper.php';   // pulls in currency_helper.php
require_once __DIR__ . '/../includes/cost_avoidance_history.php';
require_once __DIR__ . '/../includes/vendor_quotation_helper.php';
require_once __DIR__ . '/../includes/quotation_bulk.php';

function fx_currencies(): array {
    return [
        'IDR' => ['name' => 'Rupiah', 'symbol' => 'Rp', 'rate_to_idr' => 1.0],
        'USD' => ['name' => 'US Dollar', 'symbol' => '$', 'rate_to_idr' => 16000.0],
        'SGD' => ['name' => 'Singapore Dollar', 'symbol' => 'S$', 'rate_to_idr' => 0.0],   // active but no rate
    ];
}

$tests = [];

// --- ca_parse_amount ---------------------------------------------------------
$tests['parse: plain decimals are taken as-is (what the modal sends)'] = function (): void {
    assert_same(1250000.0, ca_parse_amount('1250000')['value']);
    assert_same(1.25, ca_parse_amount('1.250')['value'], '"1.250" from <input type=number> is 1.25, not 1250');
    assert_same(0.5, ca_parse_amount('0.5')['value']);
};
$tests['parse: lenient Indonesian / English separators still work (bulk CSV cells)'] = function (): void {
    assert_same(1250000.5, ca_parse_amount('1.250.000,50')['value']);
    assert_same(1250000.5, ca_parse_amount('Rp 1,250,000.50')['value']);
    assert_same(1.5, ca_parse_amount('1,5')['value']);
};
$tests['parse: empty is ok/null, junk is not ok'] = function (): void {
    assert_same(null, ca_parse_amount('')['value']);
    assert_true(ca_parse_amount('')['ok']);
    assert_false(ca_parse_amount('abc')['ok']);
    assert_false(ca_parse_amount('-')['ok']);
};

// --- ca_resolve_fx -----------------------------------------------------------
$tests['fx: IDR needs no rate and never stores one'] = function (): void {
    $r = ca_resolve_fx('IDR', '999', fx_currencies());
    assert_true($r['ok']);
    assert_same(null, $r['rate']);
    $r = ca_resolve_fx('', null, fx_currencies());
    assert_same('IDR', $r['currency']);
};
$tests['fx: blank rate defaults to today\'s rate'] = function (): void {
    $r = ca_resolve_fx('usd', '', fx_currencies());
    assert_true($r['ok']);
    assert_same('USD', $r['currency']);
    assert_same(16000.0, $r['rate']);
};
$tests['fx: a typed rate overrides today\'s and is kept to 4 dp'] = function (): void {
    $r = ca_resolve_fx('USD', '15850.12345', fx_currencies());
    assert_true($r['ok']);
    assert_same(15850.1235, $r['rate']);
};
$tests['fx: bad rate, unknown currency and rate-less currency are refused'] = function (): void {
    assert_false(ca_resolve_fx('USD', '0', fx_currencies())['ok']);
    assert_false(ca_resolve_fx('USD', '-5', fx_currencies())['ok']);
    assert_false(ca_resolve_fx('USD', 'abc', fx_currencies())['ok']);
    assert_false(ca_resolve_fx('EUR', '', fx_currencies())['ok'], 'EUR is not active');
    assert_false(ca_resolve_fx('SGD', '', fx_currencies())['ok'], 'SGD has no rate and none typed');
    assert_true(ca_resolve_fx('SGD', '11500', fx_currencies())['ok'], 'SGD with a typed rate is fine');
};

// --- conversion --------------------------------------------------------------
$tests['ca_to_idr: snapshot rate wins, NULL falls back to current'] = function (): void {
    assert_same(15000000.0, ca_to_idr(1000.0, 'USD', fx_currencies(), 15000.0));
    assert_same(16000000.0, ca_to_idr(1000.0, 'USD', fx_currencies(), null));
    assert_same(16000000.0, ca_to_idr(1000.0, 'USD', fx_currencies(), 0.0), 'a 0 snapshot is treated as missing');
    assert_same(1000.0, ca_to_idr(1000.0, 'IDR', fx_currencies(), 15000.0), 'IDR is never converted');
};
$tests['SQL: rate-aware CASE uses the rate column for foreign rows only, else the current-rate CASE'] = function (): void {
    $sql = ca_currency_case_expr_rate('p.baseline_price', 'p.baseline_currency', 'p.baseline_fx_rate', fx_currencies());
    assert_true(str_contains($sql, "<> 'IDR'"), 'IDR rows must skip the snapshot branch.');
    assert_true(str_contains($sql, 'p.baseline_fx_rate'), 'must read the snapshot rate column.');
    assert_true(str_contains($sql, "WHEN 'USD' THEN"), 'must keep the current-rate fallback for legacy rows.');
    assert_false(str_contains($sql, "WHEN 'SGD'"), 'a rate-0 currency has no fallback branch.');
};
$tests['ctx: baseline expression only uses the snapshot column when it exists'] = function (): void {
    $ctx = ['currencies' => fx_currencies()];
    assert_false(str_contains(ca_baseline_idr_expr($ctx), 'baseline_fx_rate'));
    $ctx['has_baseline_fx'] = true;
    assert_true(str_contains(ca_baseline_idr_expr($ctx), 'baseline_fx_rate'));
};

// --- shared price validation -------------------------------------------------
$tests['validate_price: same rules for every caller'] = function (): void {
    $cur = fx_currencies();
    $ok = quotation_validate_price('1250.5', 'usd', '15900', $cur);
    assert_true($ok['ok']);
    assert_same(['price' => 1250.5, 'currency' => 'USD', 'fx_rate' => 15900.0], $ok['snapshot']);
    $idr = quotation_validate_price('1250000', 'IDR', '15900', $cur);
    assert_same(null, $idr['snapshot']['fx_rate'], 'IDR drops any typed rate');
    assert_false(quotation_validate_price('', 'IDR', '', $cur)['ok']);
    assert_false(quotation_validate_price('0', 'IDR', '', $cur)['ok']);
    assert_false(quotation_validate_price('100', 'EUR', '', $cur)['ok']);
    assert_false(quotation_validate_price('100', 'USD', '-1', $cur)['ok']);
};

// --- change detection / history ----------------------------------------------
$tests['history: a legacy NULL-rate row that is re-saved at today\'s rate is NOT a change'] = function (): void {
    $rates = ['USD' => 16000.0];
    $old = ['price' => 100.0, 'currency' => 'USD', 'fx_rate' => null];
    $new = ['price' => 100.0, 'currency' => 'USD', 'fx_rate' => 16000.0];
    assert_false(ca_hist_price_changed($old, $new, $rates));
};
$tests['history: price, currency or rate differences are changes'] = function (): void {
    $rates = ['USD' => 16000.0];
    $base = ['price' => 100.0, 'currency' => 'USD', 'fx_rate' => 15900.0];
    assert_true(ca_hist_price_changed($base, ['price' => 101.0] + $base, $rates));
    assert_true(ca_hist_price_changed($base, ['fx_rate' => 16100.0] + $base, $rates));
    assert_true(ca_hist_price_changed($base, ['currency' => 'IDR', 'fx_rate' => null] + $base, $rates));
    assert_false(ca_hist_price_changed($base, $base, $rates));
    assert_true(ca_hist_price_changed(null, $base, $rates), 'no old value = a new price');
};
$tests['snapshot: IDR never keeps a rate'] = function (): void {
    assert_same(['price' => 10.0, 'currency' => 'IDR', 'fx_rate' => null], quotation_snapshot(10, 'idr', 15000));
    assert_same(['price' => 10.0, 'currency' => 'USD', 'fx_rate' => 15000.0], quotation_snapshot('10', 'usd', '15000'));
};

// --- bulk preview text --------------------------------------------------------
$tests['bulk: preview text shows rate and IDR equivalent'] = function (): void {
    assert_same('IDR 1,250,000.00', qb_money_summary(['price' => 1250000.0, 'currency' => 'IDR', 'fx_rate' => null]));
    assert_same('USD 100.00 @ 16,000 (= Rp 1,600,000)', qb_money_summary(['price' => 100.0, 'currency' => 'USD', 'fx_rate' => 16000.0]));
    assert_true(str_contains(qb_change_text(null, ['price' => 1.0, 'currency' => 'IDR', 'fx_rate' => null]), '(none) ->'));
};
$tests['bulk: templates carry the optional exchange_rate column'] = function (): void {
    assert_true(in_array('exchange_rate', qb_columns('project'), true));
    assert_true(in_array('exchange_rate', qb_columns('nonproject'), true));
};

// --- CSV template: item name column + Google Sheets / Excel round trip -----------
function fx_parse_csv(string $csv): array {
    $f = tempnam(sys_get_temp_dir(), 'qb');
    file_put_contents($f, $csv);
    $r = quotation_bulk_parse_csv($f);
    unlink($f);
    return $r;
}
$tests['template: Non-Project carries sku, item name and qty (reference) before the price; Project has no item name'] = function (): void {
    assert_same(['ticket_no', 'ticket_line_item', 'sku', 'item_description', 'qty'], array_slice(qb_columns('nonproject'), 0, 5));
    assert_same('canvassing_no', qb_columns('project')[0]);
    assert_false(in_array('item_description', qb_columns('project'), true));
    assert_false(in_array('qty', qb_columns('project'), true), 'Project template is unchanged: no qty column');
};
$tests['template: rows carry the item name and qty; a leading = + - @ is neutralised against spreadsheet formulas'] = function (): void {
    ob_start();
    @quotation_bulk_write_template(['rows' => [
        ['ticket_no' => 'T1', 'ticket_line_item' => '10', 'item_description' => 'Kursi Kantor, Hitam', 'qty' => '10'],
        ['ticket_no' => 'T1', 'ticket_line_item' => '20', 'item_description' => '=HYPERLINK("x")'],
    ]], 'nonproject', 'x.csv');
    $out = ob_get_clean();
    assert_true(str_contains($out, 'ticket_no,ticket_line_item,sku,item_description,qty,baseline_currency'), 'header order');
    assert_true(str_contains($out, 'T1,10,,"Kursi Kantor, Hitam",10,IDR'), 'item name quoted, qty present');
    assert_true(str_contains($out, 'T1,20,," =HYPERLINK(""x"")",,IDR'), 'formula-looking name is prefixed with a space');
};
$tests['import: qty is a reference column and never reaches the save path'] = function (): void {
    $csv = "ticket_no,ticket_line_item,sku,item_description,qty,baseline_currency,baseline_price,exchange_rate,quotation_link,remark\r\nT1,10,S1,Claude,10,IDR,300000,,,\r\n";
    $r = fx_parse_csv($csv);
    assert_true($r['ok']);
    assert_same('300000', $r['rows'][0]['baseline_price']);
    assert_false(array_key_exists('qty', $r['rows'][0]));
};
$tests['import: a file re-exported from Google Sheets ("sep=,,,,,," first line) is accepted'] = function (): void {
    $hdr = "ticket_no,ticket_line_item,item_description,baseline_currency,baseline_price,exchange_rate,quotation_link,remark\r\n";
    foreach (["sep=,\r\n", "sep=,,,,,,,\r\n", "\"sep=\",,,,,,,\r\n", ",,,,,,,\r\n", ""] as $pre) {
        $r = fx_parse_csv($pre . $hdr . "T1,10,Kursi,SGD,12.5,11800,,\r\n");
        assert_true($r['ok'], 'prefix ' . json_encode($pre) . ' -> ' . ($r['error'] ?? ''));
        assert_same('nonproject', $r['type']);
        assert_same('11800', $r['rows'][0]['exchange_rate']);
        assert_same('SGD', $r['rows'][0]['baseline_currency']);
    }
};
$tests['import: item_description is ignored; templates without it (older downloads) still work'] = function (): void {
    $old = "ticket_no,ticket_line_item,baseline_currency,baseline_price,exchange_rate,quotation_link,remark\r\nT1,10,IDR,100,,,\r\n";
    $r = fx_parse_csv($old);
    assert_true($r['ok']);
    $new = "ticket_no,ticket_line_item,item_description,baseline_currency,baseline_price,exchange_rate,quotation_link,remark\r\nT1,10,Anything here,IDR,100,,,\r\n";
    $r2 = fx_parse_csv($new);
    assert_true($r2['ok']);
    assert_same($r['rows'][0]['baseline_price'], $r2['rows'][0]['baseline_price']);
    assert_false(array_key_exists('item_description', $r2['rows'][0]), 'the reference column never reaches the save path');
};
$tests['import: a garbage file is still rejected with the template message'] = function (): void {
    $r = fx_parse_csv("foo,bar\r\n1,2\r\n");
    assert_false($r['ok']);
};

// --- currency safety: fail closed, never IDR-by-accident -------------------------
$tests['currency: fail-closed CASE takes only IDR/blank at face value; unknown or rate-less codes give NULL'] = function (): void {
    $sql = ca_currency_case_expr('p.po_amount', 'p.po_currency', fx_currencies(), true);
    assert_true(str_contains($sql, "WHEN 'IDR' THEN"));
    assert_true(str_contains($sql, 'ELSE NULL'), 'an unconvertible code must not pass through as rupiah');
    assert_true(str_contains($sql, 'TRIM('), 'codes are trimmed, so "SGD " cannot slip through');
    assert_false(str_contains($sql, "WHEN 'SGD'"), 'a rate-0 currency has no conversion branch');
    $legacy = ca_currency_case_expr('p.po_amount', 'p.po_currency', fx_currencies());
    assert_true(str_contains($legacy, 'ELSE (p.po_amount + 0e0) END'), 'default (Cost Savings) SQL is unchanged');
};
$tests['currency: ca_to_idr fails closed on request, tolerates stray spaces, honours a snapshot'] = function (): void {
    assert_same(0.0, ca_to_idr(1000.0, 'SGD', fx_currencies(), null, true), 'no usable SGD rate -> excluded, not 1000 rupiah');
    assert_same(1000.0, ca_to_idr(1000.0, 'SGD', fx_currencies(), null, false), 'legacy callers keep their old behaviour');
    assert_same(1000.0, ca_to_idr(1000.0, ' idr ', fx_currencies(), null, true));
    assert_same(16000000.0, ca_to_idr(1000.0, ' usd ', fx_currencies(), null, true));
    assert_same(14000000.0, ca_to_idr(1000.0, 'SGD', fx_currencies(), 14000.0, true), 'a saved snapshot still converts');
};
$tests['currency: conversion uses every rated currency; validation stays active-only'] = function (): void {
    $all = fx_currencies() + ['EUR' => ['name' => 'Euro', 'symbol' => 'E', 'rate_to_idr' => 20000.0]];
    $ctx = ['currencies' => fx_currencies(), 'currencies_all' => $all];
    assert_true(isset(ca_effective_currencies($ctx)['EUR']), 'an inactive-but-rated currency still converts');
    assert_false(ca_is_valid_currency('EUR', $ctx['currencies']), 'but it cannot be picked for a new entry');
    assert_true(ca_is_strict_ctx($ctx));
    assert_false(ca_is_strict_ctx(['currencies' => fx_currencies()]), 'callers without ca_context() keep the legacy rules');
};
$tests['currency: PO converts at the baseline snapshot only for Cost Avoidance and only for the same foreign currency'] = function (): void {
    $ctx = ['currencies' => fx_currencies(), 'currencies_all' => fx_currencies(), 'has_baseline_fx' => true];
    $frozen = ca_po_idr_expr($ctx, true);
    assert_true(str_contains($frozen, 'p.baseline_fx_rate') && str_contains($frozen, 'p.baseline_currency'));
    assert_false(str_contains(ca_po_idr_expr($ctx, false), 'baseline_fx_rate'), 'default conversion has no snapshot branch');
    $legacyCtx = ['currencies' => fx_currencies(), 'has_baseline_fx' => true];
    assert_false(str_contains(ca_po_idr_expr($legacyCtx, true), 'baseline_fx_rate'), 'Cost Savings (no currencies_all) is untouched');
    assert_true(str_contains(ca_nett_unit_expr($ctx), 'p.baseline_fx_rate'), 'nett unit price uses the frozen PO conversion');
};

// --- Intercompany exception list (special request) -----------------------------
$tests['intercompany: only the listed tickets are let through, everything else stays blocked'] = function (): void {
    $lines = function (string $type, string $ticket): array { return [['ticket_type' => $type, 'ticket_no' => $ticket]]; };
    assert_true(quotation_block_intercompany($lines('Intercompany', 'T2610A90099')), 'an unlisted Intercompany ticket is still blocked');
    assert_true(quotation_block_intercompany($lines('Intercompany New Store', 'T9999X00001')));
    assert_false(quotation_block_intercompany($lines('Intercompany', 'T2610A90001')), 'listed ticket may be priced');
    assert_false(quotation_block_intercompany($lines('intercompany', 't2610a90003')), 'case-insensitive');
    assert_false(quotation_block_intercompany($lines('Standard', 'T2610A90099')), 'normal tickets were never blocked');
    assert_same(['T2610A90001', 'T2610A90003'], ca_intercompany_exception_tickets(), 'the list is exactly the two requested tickets');
};
$tests['intercompany: SQL predicate keeps the type test and adds only the exact tickets'] = function (): void {
    $sql = ca_not_intercompany_sql('p');
    assert_true(str_contains($sql, "p.ticket_type, '') NOT IN ('Intercompany', 'Intercompany New Store')"));
    assert_true(str_contains($sql, "p.ticket_no IN ('T2610A90001','T2610A90003')"));
    assert_false(str_contains($sql, '%'), 'no wildcards: exact ticket numbers only');
    assert_true(str_contains(ca_not_intercompany_sql(''), "IFNULL(ticket_type, '')"), 'unaliased form for the PO-currency header update');
    assert_true(str_contains(ca_from_where(['mode' => 'pgroup', 'currencies' => fx_currencies(), 'has_2025' => false, 'has_project_pgroups' => false], ca_normalize_filters(['g_filter' => 'all', 'g_from' => '', 'g_to' => '']), 'report')[0], "p.ticket_no IN ('T2610A90001','T2610A90003')"), 'Cost Avoidance report/baselines use the same predicate');
};

// --- Savings Report predicate -------------------------------------------------
$tests['report: dashboard-entered manual rows are part of the Savings Report subset'] = function (): void {
    assert_true(str_contains(ca_manual_gp_subset_cond('mca'), "mca.source_type = 'DASHBOARD'"));
    assert_true(str_contains(ca_manual_report_select(['currencies' => fx_currencies()]), "'DASHBOARD'"));
};

$failures = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo "All tests passed.\n";

function assert_same(mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ' ' : '') . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $value, string $message = 'Expected true.'): void {
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assert_false(bool $value, string $message = 'Expected false.'): void {
    if ($value) {
        throw new RuntimeException($message);
    }
}
