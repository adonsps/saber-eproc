<?php
declare(strict_types=1);

// DB-backed checkpoint test for Phase 1 of the Cost Avoidance team-scoping
// feature (ClickUp 86ey1pj66): ca_resolve_session_team_scope(), covering all
// seven branches per the confirmed access matrix (Addenda #1/#2/#3).
//
// Read-only against the normal Docker dev DB (via config/database.php), NOT
// a disposable ephemeral schema like the K2 _db.php tests: this exercises
// pre-existing shared reference tables (project_head_groups,
// project_pgroup_officers, users) that the feasibility report's own
// verification plan (§10) names real people against — Ewaldo Tanton, Rio
// Tandy Darmawan, Hadi Sanjaya Risali, etc. No INSERT/UPDATE/DELETE is
// issued anywhere in this file.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/cost_avoidance_helper.php';

/** @var PDO $pdo */

$tests = [];

$tests['Category Manager (Andreas Yonathan, id 1) is unrestricted'] = function () use ($pdo): void {
    assert_same(null, ca_resolve_session_team_scope($pdo, 1, 'Category Manager'));
};

$tests['Category Manager (Teofilus Franky, id 33) is unrestricted'] = function () use ($pdo): void {
    assert_same(null, ca_resolve_session_team_scope($pdo, 33, 'Category Manager'));
};

$tests['exception Manager (Hadi Sanjaya Risali, id 32) is unrestricted'] = function () use ($pdo): void {
    assert_same(null, ca_resolve_session_team_scope($pdo, 32, 'Manager'));
};

$tests['Rio Tandy Darmawan (id 35, Manager) resolves to PKR+MFM union'] = function () use ($pdo): void {
    $scope = ca_resolve_session_team_scope($pdo, 35, 'Manager');
    assert_true(is_array($scope), 'Expected a non-null array for a project-head Manager.');
    sort($scope);
    assert_same(['MFM', 'PKR'], $scope);
};

$tests['single-team head (Fabian Adhinata Suhalim, id 34, Manager) resolves to PKP only'] = function () use ($pdo): void {
    assert_same(['PKP'], ca_resolve_session_team_scope($pdo, 34, 'Manager'));
};

$tests['non-Project Manager (Jimmy Setiawan, id 4) resolves via users.team_name to TIGS'] = function () use ($pdo): void {
    assert_same(['TIGS'], ca_resolve_session_team_scope($pdo, 4, 'Manager'));
};

$tests['Project PIC officer (Ewaldo Tanton, id 41) resolves to MFM only'] = function () use ($pdo): void {
    assert_same(['MFM'], ca_resolve_session_team_scope($pdo, 41, 'PIC'));
};

$tests['non-Project PIC (Yoseph Kuntoro Nugrahanto, id 6) resolves via users.team_name to FNBOM (newly granted access)'] = function () use ($pdo): void {
    assert_same(['FNBOM'], ca_resolve_session_team_scope($pdo, 6, 'PIC'));
};

$tests['deny case: PIC with no resolvable team (nonexistent user_id) returns []'] = function () use ($pdo): void {
    assert_same([], ca_resolve_session_team_scope($pdo, 999999, 'PIC'));
};

$tests['deny case: Manager with no resolvable team (nonexistent user_id) returns []'] = function () use ($pdo): void {
    assert_same([], ca_resolve_session_team_scope($pdo, 999999, 'Manager'));
};

$tests['deny case: role outside CM/Manager/PIC returns [] regardless of user_id'] = function () use ($pdo): void {
    // e.g. QS/Project Director/Admin/STC/Unassigned Role — none are in the
    // confirmed access matrix, so none should ever resolve to a scope.
    assert_same([], ca_resolve_session_team_scope($pdo, 35, 'QS'));
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
