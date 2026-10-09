<?php
declare(strict_types=1);

// Currency combination matrix for the Initial Quotation Price (modal payloads and
// the bulk CSV go through the same server code). Runs every combination of
// currencies / exchange-rate filled-or-blank / full-or-partial ticket coverage and
// asserts each ends in either a correct saved snapshot or ONE clear, actionable
// message -- never a half-saved ticket, never a silent currency change.
//
// WRITES to prs / vendor_quotation_last_price / currencies, so like
// test_cost_avoidance_fx_db.php it only runs against a disposable DB named `fxtest`:
//   docker-compose exec -T app php workspace/automation/test_currency_matrix_db.php
// Env: FX_TEST_HOST (ca-fx-test), FX_TEST_USER (root), FX_TEST_PASS (fxtest).

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$pdo = new PDO(
    'mysql:host=' . (getenv('FX_TEST_HOST') ?: 'ca-fx-test') . ';dbname=fxtest;charset=utf8mb4',
    getenv('FX_TEST_USER') ?: 'root', getenv('FX_TEST_PASS') ?: 'fxtest',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'fxtest') { fwrite(STDERR, "Refusing: not `fxtest`.\n"); exit(1); }

foreach ([['vendor_quotation_last_price', 'fx_rate', 'DECIMAL(18,4) NULL'], ['prs', 'baseline_fx_rate', 'DECIMAL(18,4) NULL']] as [$t, $c, $def]) {
    if ($pdo->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'")->rowCount() === 0) $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$c} {$def}");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS cost_avoidance_edit_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(30) NOT NULL, entity_key VARCHAR(255) NOT NULL,
    action VARCHAR(10) NOT NULL, old_price DECIMAL(18,2) NULL, old_currency VARCHAR(5) NULL, old_fx_rate DECIMAL(18,4) NULL,
    new_price DECIMAL(18,2) NULL, new_currency VARCHAR(5) NULL, new_fx_rate DECIMAL(18,4) NULL, detail TEXT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'MODAL', actor_id INT NULL, actor_name VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_cah_entity (entity_type, entity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function get_project_head_groups($pdo, $session) { return [1, 2, 3]; }
require_once __DIR__ . '/../includes/vendor_quotation_helper.php';
require_once __DIR__ . '/../includes/quotation_bulk.php';

$S = ['user_id' => 7, 'name' => 'Test Manager', 'username' => 'test.mgr', 'role' => 'Category Manager'];

function reset_fixtures(PDO $pdo): void {
    foreach (['prs', 'vendor_quotation_last_price', 'cost_avoidance_edit_history', 'project_pgroups', 'currencies'] as $t) $pdo->exec("DELETE FROM {$t}");
    $pdo->exec("INSERT INTO currencies (code, name, symbol, rate_to_idr, is_active) VALUES
        ('IDR','Rupiah','Rp',1,1), ('USD','US Dollar','\$',16000,1), ('CNY','Yuan','CN',2300,1),
        ('EUR','Euro','E',18000,1), ('JPY','Yen','Y',110,1), ('SGD','Singapore Dollar','S\$',0,1),
        ('THB','Baht','B',1000,0)");
    $pdo->exec("INSERT INTO project_pgroups (p_group, project_group_number, project_group_name, head_user_id, head_name, head_username, status) VALUES ('PGP', 1, 'PKR', 1, 'Head', 'head', 'ACTIVE')");
    $ins = $pdo->prepare("INSERT INTO prs (ticket_no, ticket_line_item, canvassing_no, item_description, p_group, ticket_type, vendor_name, uom, qty, status_ticket, po_number, po_amount, po_currency)
                          VALUES (?, ?, ?, ?, ?, 'GP', 'ACME', 'EA', 2, 'Open', 'PO1', 1000, 'IDR')");
    foreach (['A', 'B', 'C'] as $t) foreach (['1', '2', '3'] as $l) $ins->execute(["T-$t", $l, "C-$t", "Item $t$l", 'NPG']);
}
function vq(PDO $pdo, string $t, string $l): ?array {
    $s = $pdo->prepare("SELECT * FROM vendor_quotation_last_price WHERE scope='item' AND ticket_no=? AND ticket_line_item=?");
    $s->execute([$t, $l]); return $s->fetch() ?: null;
}
function saved_count(PDO $pdo, string $t): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM vendor_quotation_last_price WHERE scope='item' AND ticket_no=? AND last_price IS NOT NULL");
    $s->execute([$t]); return (int)$s->fetchColumn();
}
function L(string $li, string $price, string $cur = '', string $rate = ''): array {
    return ['ticket_line_item' => $li, 'last_price' => $price, 'currency' => $cur, 'fx_rate' => $rate, 'gdrive_link' => '', 'notes' => ''];
}
// Same as the bulk file: one CSV row per line, run through parse -> classify -> confirm.
function bulk(PDO $pdo, array $S, array $rows, bool $confirm = true): array {
    $f = tempnam(sys_get_temp_dir(), 'qb');
    $csv = "ticket_no,ticket_line_item,item_description,baseline_currency,baseline_price,exchange_rate,quotation_link,remark\n";
    foreach ($rows as $r) $csv .= implode(',', [$r[0], $r[1], '', $r[2], $r[3], $r[4] ?? '', '', '']) . "\n";
    file_put_contents($f, $csv);
    $p = quotation_bulk_parse_csv($f); unlink($f);
    if (!$p['ok']) return ['parse_error' => $p['error']];
    $cl = quotation_bulk_classify($pdo, $S, $p['rows'], $p['type']);
    $out = [];
    foreach ($cl as $u) {
        $o = ['status' => $u['status'], 'message' => $u['message'], 'summary' => $u['summary']];
        if ($confirm && $u['status'] === 'READY') {
            $r = quotation_save_ticket_items($pdo, $S, $u['key'], $u['input'], ['confirm_edit' => true, 'source' => 'BULK']);
            $o['saved'] = $r['ok'] ? $r['lines_saved'] : 'FAILED: ' . $r['message'];
        }
        $out[$u['key']] = $o;
    }
    return $out;
}

$tests = [];
$msgs = [];   // printed for review

// ---- A. Non-project, FIRST save, every currency x rate combination -----------------
$tests['A1 all three lines in three different foreign currencies, rates blank -> each at its own today rate'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '100', 'USD'), L('2', '5000', 'CNY'), L('3', '90', 'EUR')]]);
    t_true($r['ok'], $r['message']);
    t_eq('16000.0000', vq($pdo, 'T-A', '1')['fx_rate']); t_eq('2300.0000', vq($pdo, 'T-A', '2')['fx_rate']); t_eq('18000.0000', vq($pdo, 'T-A', '3')['fx_rate']);
    t_eq('CNY', vq($pdo, 'T-A', '2')['currency']);
};
$tests['A2 mixed: IDR + USD typed rate + CNY blank rate'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '1000000', 'IDR', '9999'), L('2', '100', 'USD', '15850.5'), L('3', '5000', 'CNY', '')]]);
    t_true($r['ok'], $r['message']);
    t_eq(null, vq($pdo, 'T-A', '1')['fx_rate'], 'IDR ignores a stray rate');
    t_eq('15850.5000', vq($pdo, 'T-A', '2')['fx_rate']); t_eq('2300.0000', vq($pdo, 'T-A', '3')['fx_rate']);
};
$tests['A3 lowercase / padded / blank currency codes behave (blank = IDR)'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', ' usd '), L('2', '20', ''), L('3', '30', 'cny')]]);
    t_true($r['ok'], $r['message']);
    t_eq('USD', vq($pdo, 'T-A', '1')['currency']); t_eq('IDR', vq($pdo, 'T-A', '2')['currency']); t_eq('CNY', vq($pdo, 'T-A', '3')['currency']);
};
$tests['A4 a currency with NO rate configured (SGD) and no typed rate -> clear message naming the line + currency; nothing saved'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'USD'), L('2', '20', 'SGD'), L('3', '30', 'IDR')]]);
    t_false($r['ok']); $msgs['A4'] = $r['message'];
    t_true(str_contains($r['message'], 'SGD') && str_contains($r['message'], '2'), $r['message']);
    t_eq(0, saved_count($pdo, 'T-A'), 'all-or-nothing');
};
$tests['A5 the same SGD line WITH a typed rate saves'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'SGD', '11800'), L('2', '20', 'IDR'), L('3', '30', 'IDR')]]);
    t_true($r['ok'], $r['message']); t_eq('11800.0000', vq($pdo, 'T-A', '1')['fx_rate']);
};
$tests['A6 an unknown / inactive currency (THB inactive, GBP unknown) -> message lists what IS available'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    foreach (['THB', 'GBP'] as $bad) {
        $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', $bad), L('2', '20', 'IDR'), L('3', '30', 'IDR')]]);
        t_false($r['ok']); $msgs["A6-$bad"] = $r['message'];
        t_true(str_contains($r['message'], $bad), $r['message']);
        t_true(str_contains($r['message'], 'USD') && str_contains($r['message'], 'CNY'), 'should list available currencies: ' . $r['message']);
    }
};
$tests['A7 bad rates (0, negative, text, absurdly tiny) on a foreign line are refused with the line named'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    foreach (['0', '-5', 'abc', '0.00001'] as $bad) {
        $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'USD', $bad), L('2', '20', 'IDR'), L('3', '30', 'IDR')]]);
        t_false($r['ok'], "rate '$bad' must be refused");
        t_true(str_contains($r['message'], 'Line 1'), $r['message']);
    }
    t_eq(0, saved_count($pdo, 'T-A'));
};
$tests['A8 SEVERAL bad lines are ALL reported in one message (not one at a time)'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'THB'), L('2', 'abc', 'USD'), L('3', '30', 'USD', '-1')]]);
    t_false($r['ok']); $msgs['A8'] = $r['message'];
    t_true(str_contains($r['message'], 'Line 1') && str_contains($r['message'], 'Line 2') && str_contains($r['message'], 'Line 3'), $r['message']);
    t_eq(0, saved_count($pdo, 'T-A'));
};

// ---- B. Coverage of the ticket ------------------------------------------------------
$tests['B1 first save covering only SOME lines -> refused, ALL missing lines listed, nothing saved'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'USD')]]);
    t_false($r['ok']); $msgs['B1'] = $r['message'];
    t_true(str_contains($r['message'], '2') && str_contains($r['message'], '3'), $r['message']);
    t_eq(0, saved_count($pdo, 'T-A'));
};
$tests['B2 partial coverage + a bad currency on a present line -> both problems reported'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'THB')]]);
    t_false($r['ok']); $msgs['B2'] = $r['message'];
    t_true(str_contains($r['message'], 'THB') && str_contains($r['message'], '2') && str_contains($r['message'], '3'), $r['message']);
};
$tests['B3 later edit may cover just ONE line of an already-priced ticket, in a different currency'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('1', '10', 'USD'), L('2', '20', 'IDR'), L('3', '30', 'IDR')]]);
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('2', '500', 'CNY')]]);
    t_false($r['ok']); t_true(!empty($r['needs_confirm']));
    $r = quotation_save_ticket_items($pdo, $S, 'T-A', ['lines' => [L('2', '500', 'CNY')]], ['confirm_edit' => true]);
    t_true($r['ok'], $r['message']);
    t_eq('CNY', vq($pdo, 'T-A', '2')['currency']); t_eq('USD', vq($pdo, 'T-A', '1')['currency'], 'untouched line keeps its currency');
};

// ---- C. Bulk CSV: the same matrix through parse -> classify -> confirm ------------
$tests['C1 CSV: one ticket, three lines, three currencies, blank + typed rates -> READY and saved'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    $o = bulk($pdo, $S, [['T-A', '1', 'USD', '100', ''], ['T-A', '2', 'CNY', '5000', '2250'], ['T-A', '3', 'EUR', '90', '']]);
    t_eq('READY', $o['T-A']['status'], $o['T-A']['message']); t_eq(3, $o['T-A']['saved']);
    t_eq('2250.0000', vq($pdo, 'T-A', '2')['fx_rate']); t_eq('18000.0000', vq($pdo, 'T-A', '3')['fx_rate']);
};
$tests['C2 CSV: CNY when CNY is inactive -> the error says CNY is not enabled and what to do'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $pdo->exec("UPDATE currencies SET is_active=0, rate_to_idr=0 WHERE code='CNY'");
    $o = bulk($pdo, $S, [['T-A', '1', 'CNY', '5000', ''], ['T-A', '2', 'IDR', '1', ''], ['T-A', '3', 'IDR', '1', '']]);
    t_eq('ERROR', $o['T-A']['status']); $msgs['C2'] = $o['T-A']['message'];
    t_true(str_contains($o['T-A']['message'], 'CNY'), $o['T-A']['message']);
    t_eq(0, saved_count($pdo, 'T-A'));
};
$tests['C3 CSV: three tickets in one file (good / partial coverage / bad currency) -> good saved, others untouched, each gets its own message'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $o = bulk($pdo, $S, [
        ['T-A', '1', 'USD', '10', ''], ['T-A', '2', 'CNY', '20', ''], ['T-A', '3', 'IDR', '30', ''],
        ['T-B', '1', 'USD', '10', ''],                                                      // partial
        ['T-C', '1', 'THB', '10', ''], ['T-C', '2', 'USD', '10', ''], ['T-C', '3', 'USD', '10', 'abc'],
    ]);
    t_eq('READY', $o['T-A']['status']); t_eq(3, $o['T-A']['saved']);
    t_eq('ERROR', $o['T-B']['status']); t_eq('ERROR', $o['T-C']['status']);
    $msgs['C3-B'] = $o['T-B']['message']; $msgs['C3-C'] = $o['T-C']['message'];
    t_eq(0, saved_count($pdo, 'T-B')); t_eq(0, saved_count($pdo, 'T-C'));
    t_true(str_contains($o['T-C']['message'], 'Line 1') && str_contains($o['T-C']['message'], 'Line 3'), $o['T-C']['message']);
};
$tests['C4 CSV: some lines of a ticket left completely blank (untouched row) while others filled -> treated as partial coverage'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $o = bulk($pdo, $S, [['T-A', '1', 'USD', '10', ''], ['T-A', '2', 'USD', '', ''], ['T-A', '3', 'USD', '', '']]);
    t_eq('ERROR', $o['T-A']['status']); $msgs['C4'] = $o['T-A']['message'];
    t_eq(0, saved_count($pdo, 'T-A'));
};
$tests['C5 CSV: price with thousands separators / decimals in a foreign currency'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    $o = bulk($pdo, $S, [['T-A', '1', 'USD', '"1,250.50"', ''], ['T-A', '2', 'CNY', '"5.000,25"', ''], ['T-A', '3', 'JPY', '100000', '']]);
    t_eq('READY', $o['T-A']['status'], $o['T-A']['message']);
    t_eq('1250.50', vq($pdo, 'T-A', '1')['last_price']); t_eq('5000.25', vq($pdo, 'T-A', '2')['last_price']);
};
$tests['C6 CSV re-import over a saved ticket with different currencies -> flagged as CHANGE, saved on confirm, rates per line kept'] = function () use ($pdo, $S) {
    reset_fixtures($pdo);
    bulk($pdo, $S, [['T-A', '1', 'USD', '10', ''], ['T-A', '2', 'USD', '10', ''], ['T-A', '3', 'USD', '10', '']]);
    $o = bulk($pdo, $S, [['T-A', '1', 'CNY', '70', ''], ['T-A', '2', 'USD', '10', ''], ['T-A', '3', 'EUR', '9', '17000']], false);
    t_eq('READY', $o['T-A']['status'], $o['T-A']['message']);
    t_true(str_contains($o['T-A']['message'], 'CHANGED'), $o['T-A']['message']);
    t_true(str_contains($o['T-A']['message'], 'line 1') && str_contains($o['T-A']['message'], 'line 3') && !str_contains($o['T-A']['message'], 'line 2:'), $o['T-A']['message']);
};

// ---- D. Project canvassing ----------------------------------------------------------
$tests['D1 project canvas: every currency, blank vs typed rate, bad ones refused clearly'] = function () use ($pdo, $S, &$msgs) {
    reset_fixtures($pdo);
    $pdo->exec("INSERT INTO prs (ticket_no, ticket_line_item, canvassing_no, item_description, p_group, ticket_type, vendor_name, uom, qty, status_ticket, po_number, po_amount, po_currency)
                VALUES ('T-P','1','C-P','Concrete','PGP','GP','ACME','EA',2,'Open','PO1',1000,'IDR')");
    $in = fn($cur, $rate, $price = '100') => ['last_price' => $price, 'currency' => $cur, 'fx_rate' => $rate, 'taxonomy' => '', 'gdrive_link' => '', 'notes' => ''];
    foreach ([['USD', ''], ['CNY', '2250'], ['EUR', ''], ['IDR', '5']] as [$c, $r]) {
        $x = quotation_save_project_canvas($pdo, $S, 'C-P', $in($c, $r), ['dry_run' => true]);
        // taxonomy may legitimately be required -- the currency part must not be what fails
        t_true($x['ok'] || !str_contains($x['message'], 'xchange') && !str_contains($x['message'], 'urrency'), "$c: " . $x['message']);
    }
    foreach ([['THB', ''], ['SGD', ''], ['USD', '-1'], ['USD', 'abc']] as [$c, $r]) {
        $x = quotation_save_project_canvas($pdo, $S, 'C-P', $in($c, $r), ['dry_run' => true]);
        t_false($x['ok']); $msgs["D1-$c/$r"] = $x['message'];
        t_true(str_contains($x['message'], $c) || str_contains($x['message'], 'xchange'), $x['message']);
    }
};

$fail = 0;
foreach ($tests as $name => $fn) {
    try { $fn(); echo "PASS $name\n"; }
    catch (Throwable $e) { $fail++; echo "FAIL $name: " . $e->getMessage() . "\n"; }
}
echo "\n--- messages the user would see ---\n";
foreach ($msgs as $k => $m) echo "[$k] $m\n";
echo $fail ? "\n$fail failed.\n" : "\nAll passed.\n";
exit($fail ? 1 : 0);

function t_eq($e, $a, string $m = ''): void { if ($e !== $a) throw new RuntimeException(($m ? "$m " : '') . 'Expected ' . var_export($e, true) . ', got ' . var_export($a, true)); }
function t_true($v, string $m = 'Expected true.'): void { if (!$v) throw new RuntimeException($m); }
function t_false($v, string $m = 'Expected false.'): void { if ($v) throw new RuntimeException($m); }
