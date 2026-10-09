<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/check_k2_automation_readiness.php';

$tests = [];

$tests['64M PHP limits pass with realistic 20M application maximum'] = function (): void {
    $twentyMiB = 20 * 1024 * 1024;
    $results = k2_readiness_collect_results(
        sample_ini('64M', '64M'),
        sample_env(['K2_AUTOMATION_MAX_UPLOAD_BYTES' => (string) $twentyMiB]),
        ['catalog' => $twentyMiB, 'price' => $twentyMiB, 'pr' => $twentyMiB]
    );

    $upload = result_by_name($results, 'upload_max_filesize');
    $post = result_by_name($results, 'post_max_size');

    assert_true($upload['pass'], 'upload_max_filesize should allow the configured single-file maximum.');
    assert_true($post['pass'], 'post_max_size should use the largest actual endpoint request, not all three files.');
    assert_true($post['required_bytes'] < 64 * 1024 * 1024, '20M AON pair with overhead should fit inside 64M.');
};

$tests['configured application maximum fails when PHP upload capacity is too small'] = function (): void {
    $eightyMiB = 80 * 1024 * 1024;
    $results = k2_readiness_collect_results(
        sample_ini('64M', '64M'),
        sample_env(['K2_AUTOMATION_MAX_UPLOAD_BYTES' => (string) $eightyMiB]),
        ['catalog' => 10 * 1024 * 1024, 'price' => 10 * 1024 * 1024, 'pr' => 10 * 1024 * 1024]
    );

    assert_false(result_by_name($results, 'upload_max_filesize')['pass']);
    assert_false(result_by_name($results, 'post_max_size')['pass']);
};

$tests['capacity calculation reports sample need separately from operational headroom'] = function (): void {
    $twentyMiB = 20 * 1024 * 1024;
    $capacity = k2_readiness_capacity_plan($twentyMiB, [
        'catalog' => 4 * 1024 * 1024,
        'price' => 6 * 1024 * 1024,
        'pr' => 8 * 1024 * 1024,
    ]);

    assert_same($twentyMiB, $capacity['required_upload_bytes']);
    assert_same(8 * 1024 * 1024, $capacity['sample_upload_bytes']);
    assert_true($capacity['required_post_bytes'] > $capacity['sample_post_bytes']);
    assert_true($capacity['recommended_post_bytes'] > $capacity['required_post_bytes']);
};

$tests['async queue checks pass with a safe storage root and worker timing configured'] = function (): void {
    $storageRoot = sys_get_temp_dir() . '/k2-readiness-test-storage-' . bin2hex(random_bytes(4));
    $results = k2_readiness_collect_results(
        sample_ini('64M', '64M'),
        sample_env([
            'K2_AUTOMATION_STORAGE_ROOT' => $storageRoot,
            'K2_AUTOMATION_WORKER_LEASE_SECONDS' => '1800',
            'K2_AUTOMATION_WORKER_LOCK_SECONDS' => '1800',
            'K2_AUTOMATION_STAGED_RETENTION_SECONDS' => '86400',
            'K2_AUTOMATION_FAILED_RETENTION_SECONDS' => '259200',
        ]),
        ['catalog' => 1024, 'price' => 1024, 'pr' => 1024]
    );

    assert_true(result_by_name($results, 'storage_root_outside_public_html')['pass']);
    assert_true(result_by_name($results, 'worker_lease_seconds')['pass']);
    assert_true(result_by_name($results, 'worker_lock_seconds')['pass']);
    assert_true(result_by_name($results, 'staged_retention_seconds')['pass']);
    assert_true(result_by_name($results, 'failed_retention_seconds')['pass']);
    assert_true(result_by_name($results, 'phase8_migration_present')['pass']);
    assert_true(result_by_name($results, 'queue_claim_index_defined')['pass']);
};

$tests['async queue check rejects a storage root inside the application checkout'] = function (): void {
    $unsafeRoot = dirname(__DIR__, 2) . '/workspace/includes/automation';
    $results = k2_readiness_collect_results(
        sample_ini('64M', '64M'),
        sample_env(['K2_AUTOMATION_STORAGE_ROOT' => $unsafeRoot]),
        ['catalog' => 1024, 'price' => 1024, 'pr' => 1024]
    );

    assert_false(result_by_name($results, 'storage_root_outside_public_html')['pass']);
};

$tests['async queue check flags missing worker timing configuration'] = function (): void {
    $env = sample_env();
    unset($env['K2_AUTOMATION_WORKER_LEASE_SECONDS'], $env['K2_AUTOMATION_WORKER_LOCK_SECONDS']);
    $results = k2_readiness_collect_results(
        sample_ini('64M', '64M'),
        $env,
        ['catalog' => 1024, 'price' => 1024, 'pr' => 1024]
    );

    assert_false(result_by_name($results, 'worker_lease_seconds')['pass']);
    assert_false(result_by_name($results, 'worker_lock_seconds')['pass']);
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
    echo "{$failures} readiness checker test(s) failed.\n";
    exit(1);
}

echo count($tests) . " readiness checker test(s) passed.\n";

function sample_ini(string $uploadMax, string $postMax): array
{
    return [
        'memory_limit' => '256M',
        'max_execution_time' => '300',
        'upload_max_filesize' => $uploadMax,
        'post_max_size' => $postMax,
        'max_file_uploads' => '20',
    ];
}

function sample_env(array $overrides = []): array
{
    return $overrides + [
        'K2_AUTOMATION_PUBLIC_API_BASE_PATH' => '/vms/workspace/api/k2_automation',
        'K2_AUTOMATION_CLIENT_ID' => 'client-a',
        'K2_AUTOMATION_HMAC_SECRET' => 'test-secret',
        'K2_AON_MAX_REMOVAL_RATIO' => '0.5',
        'K2_AUTOMATION_STALE_OPERATION_SECONDS' => '1800',
        'K2_AUTOMATION_LOCK_SECONDS' => '900',
        'K2_AON_GLOBAL_LOCK_TIMEOUT_SECONDS' => '5',
    ];
}

function result_by_name(array $results, string $name): array
{
    foreach ($results as $row) {
        if ($row['name'] === $name) {
            return $row;
        }
    }

    throw new RuntimeException("Result {$name} not found.");
}

function assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $value, string $message = 'Expected true.'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assert_false(bool $value, string $message = 'Expected false.'): void
{
    if ($value) {
        throw new RuntimeException($message);
    }
}
