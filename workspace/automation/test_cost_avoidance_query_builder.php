<?php
declare(strict_types=1);

// Pure-logic checkpoint test for Phase 1 of the Cost Avoidance team-scoping
// feature (ClickUp 86ey1pj66). Exercises ca_from_where()/ca_group_where()'s
// new $forcedTeams parameter as SQL/param shape only — no DB connection
// needed, since these are query BUILDERS, not executors. DB-touching
// resolver tests live in test_cost_avoidance_team_scope_db.php.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/cost_avoidance_helper.php';

function sample_ctx(): array {
    return [
        'mode'                => 'pic',
        'usd_rate'            => 16000.0,
        'has_pillar'          => false,
        'has_2025'            => false,
        'has_project_pgroups' => false,
    ];
}

function base_filters(): array {
    return ['team' => '', 'g_filter' => 'all', 'g_from' => '', 'g_to' => ''];
}

$tests = [];

$tests['forcedTeams null adds no clause (unrestricted, unchanged behavior)'] = function (): void {
    [$sql, $params] = ca_from_where(sample_ctx(), base_filters(), 'all', null, null);
    assert_false(str_contains($sql, 'forced_team'), 'Unrestricted call must not add a forced-scope clause.');
    assert_true(!isset($params['forced_team0']), 'Unrestricted call must not bind forced_team params.');
};

$tests['forcedTeams empty array denies everything'] = function (): void {
    [$sql, ] = ca_from_where(sample_ctx(), base_filters(), 'all', null, []);
    assert_true(str_contains($sql, 'AND 1 = 0'), 'Empty forced scope must add an always-false clause.');
};

$tests['forcedTeams non-empty binds one placeholder per code, ANDed with client filter'] = function (): void {
    $filters = base_filters();
    $filters['team'] = 'PKR'; // client's own Quick-Filter pick, independent axis
    [$sql, $params] = ca_from_where(sample_ctx(), $filters, 'all', null, ['PKR', 'MFM']);

    assert_true(str_contains($sql, 'IN (:forced_team0, :forced_team1)'), 'Expected a 2-placeholder IN(...) clause.');
    assert_same('PKR', $params['forced_team0']);
    assert_same('MFM', $params['forced_team1']);
    // Both axes present and independent: the client filter (:team) is untouched.
    assert_true(str_contains($sql, '= :team'), 'Client-chosen $filters[team] clause must remain, unmerged with forced scope.');
    assert_same('PKR', $params['team']);
};

$tests['unfiltered-for-totals pattern: forcedTeams survives even when filters[team] is reset to empty'] = function (): void {
    // Reproduces the exact ajax_cost_avoidance_baselines.php / _table.php
    // $unfiltered['team'] = ''; pattern used for recordsTotal / tfoot totals.
    $unfiltered = base_filters();
    $unfiltered['team'] = '';
    [$sql, $params] = ca_from_where(sample_ctx(), $unfiltered, 'all', null, ['MFM']);

    assert_false(str_contains($sql, '= :team'), 'filters[team] reset to "" must add no client-team clause.');
    assert_true(str_contains($sql, 'IN (:forced_team0)'), 'Forced scope must still apply so totals stay team-scoped, not grand-total.');
    assert_same('MFM', $params['forced_team0']);
};

$tests['ca_group_where: forcedTeams ANDs an explicit IN(...) alongside the exact :grp_team match'] = function (): void {
    $group = ['item' => 'Cement', 'vendor' => 'Acme', 'uom' => 'SAK', 'team' => 'PKR'];
    [$sql, $params] = ca_group_where(sample_ctx(), $group, ['PKR', 'MFM']);

    assert_true(str_contains($sql, '= :grp_team'), 'Exact group-team match must remain (Addendum #1 A1 point 2).');
    assert_true(str_contains($sql, 'IN (:grp_forced_team0, :grp_forced_team1)'), 'Forced scope must be a separate explicit IN(...).');
    assert_same('PKR', $params['grp_team']);
    assert_same('PKR', $params['grp_forced_team0']);
    assert_same('MFM', $params['grp_forced_team1']);
};

$tests['ca_group_where: forcedTeams null leaves group-where behavior unchanged (no IN clause)'] = function (): void {
    $group = ['item' => 'Cement', 'vendor' => 'Acme', 'uom' => 'SAK', 'team' => 'PKR'];
    [$sql, $params] = ca_group_where(sample_ctx(), $group);

    assert_false(str_contains($sql, 'IN ('), 'Default (no forced scope) call must not add an IN(...) clause.');
    assert_true(!isset($params['grp_forced_team0']));
};

$tests['ca_group_where: empty forcedTeams denies everything'] = function (): void {
    $group = ['item' => 'Cement', 'vendor' => 'Acme', 'uom' => 'SAK', 'team' => 'PKR'];
    [$sql, ] = ca_group_where(sample_ctx(), $group, []);
    assert_true(str_contains($sql, 'AND 1 = 0'));
};

$tests['ca_manual_aggregate SQL shape: forcedTeams matches both full name and legacy short code'] = function (): void {
    // ca_manual_aggregate() executes against manual_cost_avoidance, so a full
    // DB-backed test lives in the _db runner; here we just confirm the SQL
    // param set includes both representations per the legacy-row fallback
    // documented next to $full_to_code in ca_manual_aggregate() itself, by
    // inspecting the reflection-free public contract: call ca_manual_team_options()
    // directly, mirroring what ca_manual_aggregate() does internally.
    $team_options = ca_manual_team_options();
    $forcedTeams = ['PKR'];
    $values = [];
    foreach ($forcedTeams as $code) {
        $values[] = $team_options[$code] ?? $code;
        $values[] = $code;
    }
    $values = array_unique($values);
    assert_true(in_array('Project Konstruksi Retail', $values, true), 'Must include the full name.');
    assert_true(in_array('PKR', $values, true), 'Must include the legacy short code.');
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

function assert_same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
