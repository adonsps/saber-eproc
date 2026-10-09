<?php
declare(strict_types=1);

// DB-backed test for the Initial Quotation Price / Manual Cost Avoidance
// exchange-rate + edit-with-history work. It WRITES to prs, vendor_quotation_last_price,
// manual_cost_avoidance and cost_avoidance_edit_history, so it only ever runs
// against a DISPOSABLE MySQL whose database is literally named `fxtest`
// (it refuses anything else -- never point it at the shared dev DB):
//
//   docker run -d --name ca-fx-test --network <compose_net> -e MYSQL_ROOT_PASSWORD=fxtest \
//       -e MYSQL_DATABASE=fxtest mysql:8.0
//   (load a --no-data dump of the workspace schema into it)
//   docker-compose exec -T app php workspace/automation/test_cost_avoidance_fx_db.php
//
// Env: FX_TEST_HOST (default ca-fx-test), FX_TEST_USER (root), FX_TEST_PASS (fxtest).
// Gemini is stubbed (no API call): classify_manual_ca_category() is defined here
// before the helper loads.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

$pdo = new PDO(
    'mysql:host=' . (getenv('FX_TEST_HOST') ?: 'ca-fx-test') . ';dbname=fxtest;charset=utf8mb4',
    getenv('FX_TEST_USER') ?: 'root',
    getenv('FX_TEST_PASS') ?: 'fxtest',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'fxtest') {
    fwrite(STDERR, "Refusing to run: not connected to the disposable `fxtest` database.\n");
    exit(1);
}

// The same migration blocks database.php runs (columns the code needs).
foreach ([
    ['vendor_quotation_last_price', 'fx_rate',          'DECIMAL(18,4) NULL'],
    ['prs',                         'baseline_fx_rate', 'DECIMAL(18,4) NULL'],
    ['manual_cost_avoidance',       'fx_rate',          'DECIMAL(18,4) NULL'],
] as [$t, $c, $def]) {
    if ($pdo->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'")->rowCount() === 0) $pdo->exec("ALTER TABLE {$t} ADD COLUMN {$c} {$def}");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS cost_avoidance_edit_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY, entity_type VARCHAR(30) NOT NULL, entity_key VARCHAR(255) NOT NULL,
    action VARCHAR(10) NOT NULL, old_price DECIMAL(18,2) NULL, old_currency VARCHAR(5) NULL, old_fx_rate DECIMAL(18,4) NULL,
    new_price DECIMAL(18,2) NULL, new_currency VARCHAR(5) NULL, new_fx_rate DECIMAL(18,4) NULL, detail TEXT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'MODAL', actor_id INT NULL, actor_name VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_cah_entity (entity_type, entity_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// --- stubs so we can load the helpers without auth.php / Gemini -------------------
function get_project_head_groups($pdo, $session) { return [1, 2, 3]; }
// Stub instead of Gemini: records each call and whether it ran inside a DB
// transaction / while the duplicate-guard lock was held (it must do neither).
$GLOBALS['classify_calls'] = [];
function classify_manual_ca_category(PDO $pdo, string $item_desc): ?string {
    $held = (int)$pdo->query("SELECT IS_USED_LOCK(CONCAT('mca_', MD5('nothing')))")->fetchColumn();
    $locks = (int)$pdo->query("SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE OBJECT_TYPE = 'USER LEVEL LOCK' AND OWNER_THREAD_ID = (SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID = CONNECTION_ID())")->fetchColumn();
    $GLOBALS['classify_calls'][] = ['item' => $item_desc, 'in_txn' => $pdo->inTransaction(), 'locks' => $locks];
    return null;
}

require_once __DIR__ . '/../includes/vendor_quotation_helper.php';
require_once __DIR__ . '/../includes/quotation_bulk.php';
require_once __DIR__ . '/../includes/manual_ca_helper.php';

$SESSION = ['user_id' => 7, 'name' => 'Test Manager', 'username' => 'test.mgr', 'role' => 'Category Manager'];
$SESSION2 = ['user_id' => 8, 'name' => 'Second User', 'username' => 'second', 'role' => 'Category Manager'];

// --- fixtures -----------------------------------------------------------------------
foreach (['prs', 'vendor_quotation_last_price', 'manual_cost_avoidance', 'cost_avoidance_edit_history', 'project_pgroups', 'currencies'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}
$pdo->exec("INSERT INTO currencies (code, name, symbol, rate_to_idr, is_active) VALUES
    ('IDR','Rupiah','Rp',1,1), ('USD','US Dollar','\$',16000,1), ('SGD','Singapore Dollar','S\$',0,1)");
$pdo->exec("INSERT INTO project_pgroups (p_group, project_group_number, project_group_name, head_user_id, head_name, head_username, status) VALUES ('PGP', 1, 'PKR', 1, 'Head', 'head', 'ACTIVE')");

function seed_line(PDO $pdo, string $ticket, string $line, string $canv, string $pg, string $item): void {
    $pdo->prepare("INSERT INTO prs (ticket_no, ticket_line_item, canvassing_no, item_description, p_group, ticket_type, vendor_name, uom, qty, status_ticket, po_number, po_amount, po_currency)
                   VALUES (?, ?, ?, ?, ?, 'GP', 'ACME', 'EA', 2, 'Open', 'PO1', 1000, 'IDR')")
        ->execute([$ticket, $line, $canv, $item, $pg]);
}
seed_line($pdo, 'T1', '1', 'C-NP1', 'NPG', 'Widget A');
seed_line($pdo, 'T1', '2', 'C-NP1', 'NPG', 'Widget B');
seed_line($pdo, 'T2', '1', 'C-P1',  'PGP', 'Concrete');
seed_line($pdo, 'T3', '1', 'C-NP3', 'NPG', 'Widget C');
seed_line($pdo, 'T3', '2', 'C-NP3', 'NPG', 'Widget D');

function vq(PDO $pdo, string $ticket, string $line): ?array {
    $s = $pdo->prepare("SELECT * FROM vendor_quotation_last_price WHERE scope='item' AND ticket_no=? AND ticket_line_item=?");
    $s->execute([$ticket, $line]);
    return $s->fetch() ?: null;
}
function prs_line(PDO $pdo, string $ticket, string $line): array {
    $s = $pdo->prepare("SELECT baseline_price, baseline_currency, baseline_fx_rate FROM prs WHERE ticket_no=? AND ticket_line_item=?");
    $s->execute([$ticket, $line]);
    return $s->fetch();
}
function hist_count(PDO $pdo, string $type, string $key): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM cost_avoidance_edit_history WHERE entity_type=? AND entity_key=?");
    $s->execute([$type, $key]);
    return (int)$s->fetchColumn();
}
function line_input(string $li, $price, string $cur, $rate = ''): array {
    return ['ticket_line_item' => $li, 'last_price' => (string)$price, 'currency' => $cur, 'fx_rate' => (string)$rate, 'gdrive_link' => '', 'notes' => ''];
}

$tests = [];

// ===== Non-Project ticket (modal + bulk share this path) ==========================
$tests['ticket: first save stores price + snapshot rate on both tables and logs CREATE'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T1', ['lines' => [line_input('1', '100', 'USD', '15900'), line_input('2', '500000', 'IDR', '15900')]]);
    assert_true($r['ok'], $r['message']);
    assert_same(2, $r['lines_saved']);
    $l1 = vq($pdo, 'T1', '1'); $p1 = prs_line($pdo, 'T1', '1');
    assert_same('USD', $l1['currency']); assert_same('15900.0000', $l1['fx_rate']);
    assert_same('15900.0000', $p1['baseline_fx_rate']); assert_same('100.00', $p1['baseline_price']);
    $l2 = vq($pdo, 'T1', '2'); $p2 = prs_line($pdo, 'T1', '2');
    assert_same(null, $l2['fx_rate'], 'IDR never keeps a rate'); assert_same(null, $p2['baseline_fx_rate']);
    assert_same(1, hist_count($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1'));
};
$tests['ticket: a foreign price with a BLANK rate is saved at today\'s rate'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '10', 'USD', ''), line_input('2', '20', 'IDR')]]);
    assert_true($r['ok'], $r['message']);
    assert_same('16000.0000', vq($pdo, 'T3', '1')['fx_rate']);
};
$tests['ticket: unpriced lines must all be priced on the first save (no partial first save)'] = function () use ($pdo, $SESSION): void {
    $pdo->exec("DELETE FROM vendor_quotation_last_price WHERE ticket_no='T3'");
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '10', 'IDR')]]);
    assert_false($r['ok']);
    assert_true(str_contains($r['message'], 'missing on line 2'), $r['message']);
};
$tests['ticket: the modal and bulk reject an invalid / rate-less currency the same way (no silent IDR)'] = function () use ($pdo, $SESSION): void {
    foreach (['EUR', 'SGD'] as $bad) {
        $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '10', $bad), line_input('2', '20', 'IDR')]]);
        assert_false($r['ok'], "$bad must be refused");
    }
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '10', 'USD', '-3'), line_input('2', '20', 'IDR')]]);
    assert_false($r['ok'], 'negative rate refused');
};
$tests['ticket: re-saving identical values is a no-op (no confirm, no history)'] = function () use ($pdo, $SESSION): void {
    $before = hist_count($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1');
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T1', ['lines' => [line_input('1', '100', 'USD', '15900'), line_input('2', '500000', 'IDR')]]);
    assert_true($r['ok'], $r['message']);
    assert_same(0, $r['lines_saved']);
    assert_same($before, hist_count($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1'));
};
$tests['ticket: editing a saved price needs confirmation and changes nothing until confirmed'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T1', ['lines' => [line_input('1', '120', 'USD', '15900'), line_input('2', '500000', 'IDR')]]);
    assert_false($r['ok']);
    assert_true(!empty($r['needs_confirm']));
    assert_same(100.0, $r['changes'][0]['old']['price']);
    assert_same(120.0, $r['changes'][0]['new']['price']);
    assert_same('100.00', vq($pdo, 'T1', '1')['last_price'], 'unconfirmed edit must not be written');
};
$tests['ticket: confirmed edit writes both tables and logs old -> new with the actor'] = function () use ($pdo, $SESSION2): void {
    $r = quotation_save_ticket_items($pdo, $SESSION2, 'T1', ['lines' => [line_input('1', '120', 'USD', '15900'), line_input('2', '500000', 'IDR')]],
        ['confirm_edit' => true, 'source' => 'MODAL']);
    assert_true($r['ok'], $r['message']);
    assert_same('120.00', vq($pdo, 'T1', '1')['last_price']);
    assert_same('120.00', prs_line($pdo, 'T1', '1')['baseline_price']);
    $rows = ca_hist_list($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1');
    assert_same(2, count($rows));
    assert_same('UPDATE', $rows[0]['action']);
    assert_same('Second User', $rows[0]['actor']);
    assert_same('100.00', $rows[0]['old']['price']);
    assert_same('120.00', $rows[0]['new']['price']);
    assert_same('CREATE', $rows[1]['action']);
};
$tests['ticket: changing ONLY the exchange rate counts as an edit'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T1', ['lines' => [line_input('1', '120', 'USD', '16100'), line_input('2', '500000', 'IDR')]]);
    assert_true(!empty($r['needs_confirm']), 'rate-only edit must ask');
};
$tests['bulk: dry run reports an edit (old -> new) and never writes'] = function () use ($pdo, $SESSION): void {
    $rows = [
        ['row_number' => 2, 'ticket_no' => 'T1', 'ticket_line_item' => '1', 'baseline_currency' => 'USD', 'baseline_price' => '130', 'exchange_rate' => '16000', 'quotation_link' => '', 'remark' => ''],
        ['row_number' => 3, 'ticket_no' => 'T1', 'ticket_line_item' => '2', 'baseline_currency' => 'IDR', 'baseline_price' => '500000', 'exchange_rate' => '', 'quotation_link' => '', 'remark' => ''],
    ];
    $out = quotation_bulk_classify($pdo, $SESSION, $rows, 'nonproject');
    assert_same('READY', $out[0]['status'], $out[0]['message']);
    assert_true(!empty($out[0]['is_edit']));
    assert_true(str_contains($out[0]['change_text'], '->'));
    assert_same('120.00', vq($pdo, 'T1', '1')['last_price'], 'dry run must not write');
};
$tests['bulk: the exchange_rate column is optional, a blank uses today\'s rate, a bad currency is an ERROR'] = function () use ($pdo, $SESSION): void {
    $good = [['row_number' => 2, 'ticket_no' => 'T3', 'ticket_line_item' => '1', 'baseline_currency' => 'USD', 'baseline_price' => '10', 'exchange_rate' => '', 'quotation_link' => '', 'remark' => ''],
             ['row_number' => 3, 'ticket_no' => 'T3', 'ticket_line_item' => '2', 'baseline_currency' => 'IDR', 'baseline_price' => '20', 'exchange_rate' => '', 'quotation_link' => '', 'remark' => '']];
    $out = quotation_bulk_classify($pdo, $SESSION, $good, 'nonproject');
    assert_same('READY', $out[0]['status'], $out[0]['message']);
    assert_true(str_contains($out[0]['summary'], 'USD'));
    $good[0]['baseline_currency'] = 'EUR';
    $out = quotation_bulk_classify($pdo, $SESSION, $good, 'nonproject');
    assert_same('ERROR', $out[0]['status']);
};
$tests['bulk: confirm path saves with confirm_edit and logs source BULK'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T1', ['lines' => [line_input('1', '130', 'USD', '16000'), line_input('2', '500000', 'IDR')]],
        ['confirm_edit' => true, 'source' => 'BULK']);
    assert_true($r['ok'], $r['message']);
    $rows = ca_hist_list($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1');
    assert_same('BULK', $rows[0]['source']);
};

// ===== Project canvassing ============================================================
$tests['canvas: first save, then edit needs confirmation; rate is mirrored to the lines'] = function () use ($pdo, $SESSION): void {
    $in = ['currency' => 'USD', 'last_price' => '5000', 'fx_rate' => '15500', 'taxonomy' => 'J1', 'gdrive_link' => '', 'notes' => ''];
    $r = quotation_save_project_canvas($pdo, $SESSION, 'C-P1', $in);
    assert_true($r['ok'], $r['message']);
    assert_same('15500.0000', prs_line($pdo, 'T2', '1')['baseline_fx_rate']);
    $in['last_price'] = '5200';
    $r = quotation_save_project_canvas($pdo, $SESSION, 'C-P1', $in);
    assert_true(!empty($r['needs_confirm']), 'edit must ask');
    assert_same('5000.00', prs_line($pdo, 'T2', '1')['baseline_price'], 'unconfirmed edit must not be written');
    $r = quotation_save_project_canvas($pdo, $SESSION, 'C-P1', $in, ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    $rows = ca_hist_list($pdo, CA_HIST_QUOTATION_PROJECT, 'C-P1');
    assert_same(2, count($rows));
    assert_same('5000.00', $rows[0]['old']['price']);
};

// ===== conversion: snapshot vs current rate ==========================================
$tests['conversion: a snapshot rate survives a later change to the current rate; a legacy NULL row moves'] = function () use ($pdo): void {
    $ctx = ['currencies' => ca_get_active_currencies($pdo), 'has_baseline_fx' => true];
    $expr = ca_baseline_idr_expr($ctx);
    $idr = function (string $t, string $l) use ($pdo, &$expr) {
        return (float)$pdo->query("SELECT {$expr} FROM prs p WHERE p.ticket_no='{$t}' AND p.ticket_line_item='{$l}'")->fetchColumn();
    };
    assert_same(130.0 * 16000, $idr('T1', '1'));                 // snapshot 16000 at the time of the last edit
    $pdo->exec("UPDATE prs SET baseline_fx_rate = 15000 WHERE ticket_no='T1' AND ticket_line_item='1'");
    $pdo->exec("UPDATE currencies SET rate_to_idr = 17000 WHERE code='USD'");
    $ctx['currencies'] = ca_get_active_currencies($pdo);
    $expr = ca_baseline_idr_expr($ctx);
    assert_same(130.0 * 15000, $idr('T1', '1'), 'snapshot must NOT follow the current rate');
    $pdo->exec("UPDATE prs SET baseline_fx_rate = NULL WHERE ticket_no='T1' AND ticket_line_item='1'");
    assert_same(130.0 * 17000, $idr('T1', '1'), 'legacy NULL row follows the current rate');
    $pdo->exec("UPDATE currencies SET rate_to_idr = 16000 WHERE code='USD'");
};

// ===== Manual Input ===================================================================
function manual_post(array $over = []): array {
    return $over + ['m_entry_date' => '2026-09-01', 'm_team' => 'Technology, Industrial, and General Services', 'm_vendor_name' => 'ACME',
        'm_item_description' => 'Laptop', 'm_quantity' => '3', 'm_uom' => 'EA', 'm_currency' => 'USD', 'm_fx_rate' => '15800',
        'm_unit_price_before' => '1000', 'm_avoided_price' => '900', 'm_remarks' => '', 'm_quotation_link' => ''];
}
$manualId = 0;
$tests['manual: dashboard create computes the total server-side and stores the rate + source'] = function () use ($pdo, $SESSION, &$manualId): void {
    $post = manual_post(['m_total_cost_avoidance' => '999999999']);   // a forged total must be ignored
    $r = manual_ca_save($pdo, $SESSION, null, $post, 'DASHBOARD', false);
    assert_true($r['ok'], $r['message']);
    $manualId = $r['id'];
    $row = $pdo->query("SELECT * FROM manual_cost_avoidance WHERE id = {$manualId}")->fetch();
    assert_same('300.00', $row['total_cost_avoidance']);           // (1000-900) * 3
    assert_same('15800.0000', $row['fx_rate']);
    assert_same('DASHBOARD', $row['source_type']);
    assert_same(1, hist_count($pdo, CA_HIST_MANUAL, (string)$manualId));
};
$tests['manual: validation - missing vendor / bad qty / bad currency are refused'] = function () use ($pdo, $SESSION): void {
    assert_false(manual_ca_save($pdo, $SESSION, null, manual_post(['m_vendor_name' => '']), 'DASHBOARD', false)['ok']);
    assert_false(manual_ca_save($pdo, $SESSION, null, manual_post(['m_quantity' => '0']), 'DASHBOARD', false)['ok']);
    assert_false(manual_ca_save($pdo, $SESSION, null, manual_post(['m_currency' => 'EUR']), 'DASHBOARD', false)['ok']);
    assert_false(manual_ca_save($pdo, $SESSION, null, manual_post(['m_fx_rate' => '0']), 'DASHBOARD', false)['ok']);
};
$tests['manual: team scope is enforced on create, edit and delete'] = function () use ($pdo, $SESSION, &$manualId): void {
    $pkr = ['PKR'];
    assert_false(manual_ca_save($pdo, $SESSION, $pkr, manual_post(), 'DASHBOARD', false)['ok'], 'TIGS entry by a PKR-scoped session');
    assert_false(manual_ca_save($pdo, $SESSION, $pkr, manual_post(['m_id' => $manualId, 'm_team' => 'Project Konstruksi Retail']), 'DASHBOARD', true)['ok'], 'editing another team\'s row');
    assert_false(manual_ca_delete($pdo, $SESSION, $pkr, $manualId, 'DASHBOARD')['ok']);
    assert_true(manual_ca_save($pdo, $SESSION, ['TIGS'], manual_post(['m_item_description' => 'Laptop']), 'DASHBOARD', false)['ok'], 'own team is fine');
};
$tests['manual: unchanged re-save is a no-op; an edit needs confirmation, then writes + logs'] = function () use ($pdo, $SESSION2, &$manualId): void {
    $same = manual_ca_save($pdo, $SESSION2, null, manual_post(['m_id' => $manualId]), 'DASHBOARD', false);
    assert_true($same['ok'] && !empty($same['unchanged']), 'identical re-save');
    $edit = manual_post(['m_id' => $manualId, 'm_avoided_price' => '850']);
    $r = manual_ca_save($pdo, $SESSION2, null, $edit, 'DASHBOARD', false);
    assert_true(!empty($r['needs_confirm']));
    assert_same('900.00', $pdo->query("SELECT avoided_price FROM manual_cost_avoidance WHERE id = {$manualId}")->fetchColumn());
    $r = manual_ca_save($pdo, $SESSION2, null, $edit, 'DASHBOARD', true);
    assert_true($r['ok'], $r['message']);
    assert_same('450.00', $pdo->query("SELECT total_cost_avoidance FROM manual_cost_avoidance WHERE id = {$manualId}")->fetchColumn());   // (1000-850)*3
    $h = ca_hist_list($pdo, CA_HIST_MANUAL, (string)$manualId);
    assert_same('UPDATE', $h[0]['action']);
    assert_same('Second User', $h[0]['actor']);
    assert_true(str_contains((string)($h[0]['detail']['summary'] ?? ''), 'Final price'));
};
$tests['manual: dashboard entries reach the Savings Report arm with their own source label'] = function () use ($pdo, $SESSION): void {
    $ctx = ca_context($pdo);
    $filters = ['team' => '', 'g_filter' => 'all', 'g_from' => '', 'g_to' => ''];
    [$fw, $params] = ca_manual_report_from_where($ctx, $filters, null, 'm');
    $n = $pdo->prepare("SELECT COUNT(*) {$fw}"); $n->execute($params);
    assert_true((int)$n->fetchColumn() >= 1, 'dashboard row must be in the report subset');
    $sel = $pdo->prepare(ca_manual_report_select($ctx) . " {$fw} LIMIT 1"); $sel->execute($params);
    $row = $sel->fetch();
    assert_same('DASHBOARD', $row['row_source']);
    // (1000-850)*3 = 450 USD at the entry's own 15800 rate = 7,110,000 IDR
    assert_same(450.0 * 15800, (float)$row['cost_avoidance'], 'manual arm must use the entry\'s snapshot rate');
};
$tests['manual: the PHP aggregate uses the same snapshot rate as the SQL arm'] = function () use ($pdo): void {
    $agg = ca_manual_aggregate($pdo, ['team' => '', 'g_filter' => 'all', 'g_from' => '', 'g_to' => ''], ca_get_active_currencies($pdo), null);
    $sum = 0.0;
    foreach ($agg['entries'] as $e) { $sum += 1; }
    assert_true($sum >= 1);
    assert_same(true, $agg['total_idr'] > 0);
    // 450 USD @15800 (edited row) + 300... only the edited row and the extra own-team row exist; compare against SQL
    $rows = $pdo->query("SELECT total_cost_avoidance, fx_rate FROM manual_cost_avoidance WHERE is_active = 1")->fetchAll();
    $expected = 0.0;
    foreach ($rows as $r) { $expected += (float)$r['total_cost_avoidance'] * (float)$r['fx_rate']; }
    assert_same(round($expected, 2), round($agg['total_idr'], 2));
};
$tests['manual: AI categorisation runs once per NEW entry, after the commit, holding no lock'] = function () use ($pdo, $SESSION): void {
    $GLOBALS['classify_calls'] = [];
    $r = manual_ca_save($pdo, $SESSION, null, manual_post(['m_item_description' => 'Gemini order test']), 'DASHBOARD', false);
    assert_true($r['ok'], $r['message']);
    assert_same(1, count($GLOBALS['classify_calls']), 'exactly one classify call for a new entry');
    assert_same(false, $GLOBALS['classify_calls'][0]['in_txn'], 'must not run inside the transaction');
    assert_same(0, $GLOBALS['classify_calls'][0]['locks'], 'must not run while the duplicate-guard lock is held');
    $id = $r['id'];
    $GLOBALS['classify_calls'] = [];
    manual_ca_save($pdo, $SESSION, null, manual_post(['m_id' => $id, 'm_item_description' => 'Gemini order test', 'm_avoided_price' => '800']), 'DASHBOARD', true);
    assert_same(0, count($GLOBALS['classify_calls']), 'editing a price (same description) must not call the AI');
    manual_ca_save($pdo, $SESSION, null, manual_post(['m_id' => $id, 'm_item_description' => 'Gemini order test v2', 'm_avoided_price' => '800']), 'DASHBOARD', true);
    assert_same(1, count($GLOBALS['classify_calls']), 'changing the description calls the AI once');
    assert_same(false, $GLOBALS['classify_calls'][0]['in_txn']);
    $before = count($GLOBALS['classify_calls']);
    $dup1 = manual_ca_save($pdo, $SESSION, null, manual_post(['m_item_description' => 'Dup guard item']), 'DASHBOARD', false);
    $dup2 = manual_ca_save($pdo, $SESSION, null, manual_post(['m_item_description' => 'Dup guard item']), 'DASHBOARD', false);
    assert_same($dup1['id'], $dup2['id'], 'an identical resubmit returns the first entry');
    assert_true(!empty($dup2['duplicate']));
    assert_same($before + 1, count($GLOBALS['classify_calls']), 'the duplicate does not call the AI again');
};
$tests['manual: delete is a soft delete and is logged'] = function () use ($pdo, $SESSION, &$manualId): void {
    $r = manual_ca_delete($pdo, $SESSION, null, $manualId, 'DASHBOARD');
    assert_true($r['ok'], $r['message']);
    assert_same('0', (string)$pdo->query("SELECT is_active FROM manual_cost_avoidance WHERE id = {$manualId}")->fetchColumn());
    assert_same('DELETE', ca_hist_list($pdo, CA_HIST_MANUAL, (string)$manualId)[0]['action']);
    assert_false(manual_ca_delete($pdo, $SESSION, null, $manualId, 'DASHBOARD')['ok'], 'second delete');
};

// ===== PO currency switch (Initial Quotation Price modal) =========================
function po_cur(PDO $pdo, string $po): array {
    $s = $pdo->prepare("SELECT DISTINCT po_currency FROM prs WHERE po_number = ?");
    $s->execute([$po]);
    return $s->fetchAll(PDO::FETCH_COLUMN);
}
$tests['po currency: switching a PO updates every line of it and logs on each in-scope line'] = function () use ($pdo, $SESSION): void {
    $pdo->exec("DELETE FROM cost_avoidance_edit_history");
    $r = quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T1', 'PO1', 'usd');
    assert_true($r['ok'], $r['message']);
    assert_true($r['changed']);
    assert_same('IDR', $r['from']); assert_same('USD', $r['to']);
    assert_same(['USD'], po_cur($pdo, 'PO1'), 'one PO is one currency, across tickets');
    assert_same('1000.00', (string)$pdo->query("SELECT MIN(po_amount) FROM prs WHERE po_number='PO1'")->fetchColumn(), 'the amount keeps its number');
    assert_same(1, hist_count($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1'));
    assert_same(1, hist_count($pdo, CA_HIST_QUOTATION_TICKET, 'T1|2'));
    $h = ca_hist_list($pdo, CA_HIST_QUOTATION_TICKET, 'T1|1')[0];
    assert_same('PO_CUR', $h['action']); assert_same('PO_CURRENCY', $h['detail']['kind']);
    assert_same('IDR', $h['detail']['from']); assert_same('USD', $h['detail']['to']);
};
$tests['po currency: same currency again is a no-op (no write, no history)'] = function () use ($pdo, $SESSION): void {
    $before = (int)$pdo->query("SELECT COUNT(*) FROM cost_avoidance_edit_history")->fetchColumn();
    $r = quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T1', 'PO1', 'USD');
    assert_true($r['ok']); assert_false($r['changed']);
    assert_same($before, (int)$pdo->query("SELECT COUNT(*) FROM cost_avoidance_edit_history")->fetchColumn());
};
$tests['po currency: refuses a currency with no rate, an unknown one, and a PO that is not on the ticket'] = function () use ($pdo, $SESSION): void {
    assert_false(quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T1', 'PO1', 'SGD')['ok'], 'SGD has rate 0');
    assert_false(quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T1', 'PO1', 'XYZ')['ok'], 'unknown currency');
    assert_false(quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T1', 'PO-OTHER', 'IDR')['ok'], 'PO not on this ticket');
    assert_false(quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T1', '', 'IDR')['ok'], 'PO number required');
    assert_same(['USD'], po_cur($pdo, 'PO1'), 'nothing changed');
};
$tests['po currency: Project canvassing switches back to IDR, logged once on the canvassing'] = function () use ($pdo, $SESSION): void {
    $r = quotation_change_po_currency($pdo, $SESSION, 'project', 'C-P1', 'PO1', 'IDR');
    assert_true($r['ok'], $r['message']);
    assert_same(['IDR'], po_cur($pdo, 'PO1'));
    assert_same(1, hist_count($pdo, CA_HIST_QUOTATION_PROJECT, 'C-P1'));
};
$tests['po currency: wrong granularity and unauthorised users are refused'] = function () use ($pdo, $SESSION): void {
    assert_false(quotation_change_po_currency($pdo, $SESSION, 'project', 'C-NP1', 'PO1', 'USD')['ok'], 'Non-Project canvassing');
    assert_false(quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T2', 'PO1', 'USD')['ok'], 'Project ticket');
    $pic = ['user_id' => 9, 'name' => 'Nobody', 'username' => 'nobody', 'role' => 'PIC'];
    assert_false(quotation_change_po_currency($pdo, $pic, 'ticket', 'T1', 'PO1', 'USD')['ok'], 'PIC with no assigned lines');
    assert_same(['IDR'], po_cur($pdo, 'PO1'));
};

// ===== Cost avoidance maths with a foreign-currency PO (single, bulk and Project) ====
// Seed lines: qty 2, po_amount 1000 on PO1 -> PO nett unit price 500 in the PO's currency.
// Today's USD rate in the fixtures is 16000.
function avoid(PDO $pdo, string $ticket, string $line): int {
    $expr = ca_avoidance_expr(ca_context($pdo));
    $s = $pdo->prepare("SELECT ROUND({$expr}) FROM prs p WHERE p.ticket_no = ? AND p.ticket_line_item = ?");
    $s->execute([$ticket, $line]);
    return (int)$s->fetchColumn();
}
$tests['po currency maths: USD PO + USD quotation compare at the quotation\'s rate (rate cancels out)'] = function () use ($pdo, $SESSION): void {
    assert_true(quotation_change_po_currency($pdo, $SESSION, 'ticket', 'T3', 'PO1', 'USD')['ok']);
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '600', 'USD', '17000'), line_input('2', '600', 'USD', '17000')]], ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    // (600 - 500) USD x 17,000 x qty 2 -- NOT 3,200,000, which is what today's 16,000 would give.
    assert_same(3400000, avoid($pdo, 'T3', '1'));
    assert_same(3400000, avoid($pdo, 'T3', '2'));
};
$tests['po currency maths: an exchange-rate edit on the quotation moves nothing when both sides are USD'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '600', 'USD', '18000'), line_input('2', '600', 'USD', '18000')]], ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    assert_same(3600000, avoid($pdo, 'T3', '1'), '(600-500) x 18,000 x 2: both sides move together, the USD gap stays 100');
};
$tests['po currency maths: IDR quotation vs USD PO converts the PO at today\'s rate'] = function () use ($pdo, $SESSION): void {
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '9000000', 'IDR'), line_input('2', '9000000', 'IDR')]], ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    assert_same(2000000, avoid($pdo, 'T3', '1'), '(9,000,000 - 500 x 16,000) x 2');
};
$tests['po currency maths: the bulk path (source BULK) gives the same result, and is not blocked by a USD PO'] = function () use ($pdo, $SESSION): void {
    $rows = [
        ['row_number' => 2, 'ticket_no' => 'T3', 'ticket_line_item' => '1', 'baseline_currency' => 'USD', 'baseline_price' => '600', 'exchange_rate' => '17000', 'quotation_link' => '', 'remark' => ''],
        ['row_number' => 3, 'ticket_no' => 'T3', 'ticket_line_item' => '2', 'baseline_currency' => 'USD', 'baseline_price' => '600', 'exchange_rate' => '17000', 'quotation_link' => '', 'remark' => ''],
    ];
    $out = quotation_bulk_classify($pdo, $SESSION, $rows, 'nonproject');
    assert_same('READY', $out[0]['status'], $out[0]['message']);
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '600', 'USD', '17000'), line_input('2', '600', 'USD', '17000')]], ['confirm_edit' => true, 'source' => 'BULK']);
    assert_true($r['ok'], $r['message']);
    assert_same(3400000, avoid($pdo, 'T3', '1'));
};
$tests['po currency maths: Project canvassing with a USD PO uses the same rule'] = function () use ($pdo, $SESSION): void {
    assert_true(quotation_change_po_currency($pdo, $SESSION, 'project', 'C-P1', 'PO1', 'USD')['ok']);
    $in = ['currency' => 'USD', 'last_price' => '600', 'fx_rate' => '17000', 'taxonomy' => 'J1', 'gdrive_link' => '', 'notes' => ''];
    $r = quotation_save_project_canvas($pdo, $SESSION, 'C-P1', $in, ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    assert_same(3400000, avoid($pdo, 'T2', '1'));
};
$tests['po currency maths: Project and ticket with an IDR PO are unchanged (regression)'] = function () use ($pdo, $SESSION): void {
    assert_true(quotation_change_po_currency($pdo, $SESSION, 'project', 'C-P1', 'PO1', 'IDR')['ok']);
    $in = ['currency' => 'IDR', 'last_price' => '600', 'fx_rate' => '', 'taxonomy' => 'J1', 'gdrive_link' => '', 'notes' => ''];
    $r = quotation_save_project_canvas($pdo, $SESSION, 'C-P1', $in, ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    assert_same(200, avoid($pdo, 'T2', '1'), '(600 - 500) x 2');
    $r = quotation_save_ticket_items($pdo, $SESSION, 'T3', ['lines' => [line_input('1', '600', 'IDR'), line_input('2', '600', 'IDR')]], ['confirm_edit' => true]);
    assert_true($r['ok'], $r['message']);
    assert_same(200, avoid($pdo, 'T3', '1'));
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
function assert_true(bool $value, string $message = 'Expected true.'): void { if (!$value) throw new RuntimeException($message); }
function assert_false(bool $value, string $message = 'Expected false.'): void { if ($value) throw new RuntimeException($message); }
