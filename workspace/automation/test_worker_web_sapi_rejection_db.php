<?php
declare(strict_types=1);

// Verifies the fix for the 2026-07-27 production finding: a browser/web-SAPI
// request to workspace/cron/process_k2_import_queue.php used to throw an
// uncaught fatal error (fwrite(STDERR, ...) — STDERR is undefined outside the
// CLI SAPI) instead of being cleanly rejected, leaking an internal server
// file path in PHP's default stack trace output.
//
// This launches the REAL worker script under PHP's built-in server (SAPI
// 'cli-server', the same non-'cli' path a real Apache/php-fpm request takes)
// and issues a genuine HTTP request against it, so the test exercises the
// actual PHP_SAPI check rather than a simulation. It also runs a genuine CLI
// subprocess against an empty queue to confirm the fix did not disturb real
// CLI invocation.

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$workerScriptPath = __DIR__ . '/../cron/process_k2_import_queue.php';

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase2_schema.sql');
apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
create_business_tables($pdo);

$tests = [];

$tests['a web-SAPI request is rejected with 404 before any queue claim or write'] = function () use ($pdo, $workerScriptPath): void {
    reset_all_tables($pdo);
    $batchId = seed_queued_attempt($pdo, 'web-sapi-rejection-batch');
    $beforeAttempt = fetch_attempt($pdo, $batchId);
    $beforeBatchState = fetch_one($pdo, 'SELECT state, archive_state FROM k2_automation_import_batches WHERE id = ?', [$batchId]);
    $beforeCounts = business_table_counts($pdo);

    $result = http_request_against_worker($workerScriptPath);

    assert_same(404, $result['status'], 'Expected the worker to answer a web request with HTTP 404. Raw response: ' . $result['raw']);
    assert_true(!str_contains_any($result['body'], [
        'Fatal error', 'Uncaught', 'Stack trace', 'STDERR', '.php on line', __DIR__, 'k2_automation_',
    ]), 'Response body must not leak a stack trace, file path, or implementation detail: ' . $result['body']);
    assert_true(strlen($result['body']) < 200, 'Expected a short, clean rejection body, got: ' . $result['body']);

    $afterAttempt = fetch_attempt($pdo, $batchId);
    assert_same($beforeAttempt, $afterAttempt, 'The queued attempt must be completely untouched by a rejected web request.');
    assert_same(null, $afterAttempt['worker_claim_token'], 'No worker claim token may be set by a rejected web request.');
    assert_same('STARTED', (string) $afterAttempt['status'], 'Attempt status must remain STARTED (no claim, no processing).');

    $afterBatchState = fetch_one($pdo, 'SELECT state, archive_state FROM k2_automation_import_batches WHERE id = ?', [$batchId]);
    assert_same($beforeBatchState, $afterBatchState, 'Batch state must be unchanged by a rejected web request.');

    $afterCounts = business_table_counts($pdo);
    assert_same($beforeCounts, $afterCounts, 'No business table (prs/aon_skus/sku_dictionary) row count may change.');
    assert_same(['prs' => 0, 'aon_skus' => 0, 'sku_dictionary' => 0], $afterCounts, 'No business write should ever have occurred.');
};

$tests['a second web-SAPI request path/method is also rejected with 404, not a fatal error'] = function () use ($workerScriptPath): void {
    $result = http_request_against_worker($workerScriptPath, '/some/other/path.php', 'POST');
    assert_same(404, $result['status'], 'Expected 404 regardless of request path/method. Raw response: ' . $result['raw']);
    assert_true(!str_contains_any($result['body'], ['Fatal error', 'Uncaught', 'Stack trace', 'STDERR']), 'Response body must not leak a stack trace: ' . $result['body']);
};

$tests['CLI invocation is still accepted and reaches real worker logic (empty queue)'] = function () use ($dsn, $user, $password, $pdo, $workerScriptPath): void {
    reset_all_tables($pdo);

    $storageRoot = sys_get_temp_dir() . '/k2-automation-web-sapi-test-storage-' . bin2hex(random_bytes(6));
    $cmd = sprintf(
        'K2_AUTOMATION_TEST_DSN=%s K2_AUTOMATION_TEST_USER=%s K2_AUTOMATION_TEST_PASSWORD=%s ' .
        'K2_AUTOMATION_STORAGE_ROOT=%s K2_AUTOMATION_CLIENT_ID=%s K2_AUTOMATION_HMAC_SECRET=%s ' .
        'php %s --limit=1 2>&1',
        escapeshellarg($dsn),
        escapeshellarg($user),
        escapeshellarg($password),
        escapeshellarg($storageRoot),
        escapeshellarg('client-a'),
        escapeshellarg('test-secret'),
        escapeshellarg($workerScriptPath)
    );
    exec($cmd, $outputLines, $exitCode);
    $output = implode("\n", $outputLines);

    assert_same(0, $exitCode, 'CLI invocation must still be accepted and exit cleanly on an empty queue. Output: ' . $output);
    assert_true(!str_contains($output, 'CLI-only'), 'A genuine CLI invocation must never hit the web-rejection branch: ' . $output);
    assert_true(!str_contains($output, 'Fatal error'), 'A genuine CLI invocation must not fatal: ' . $output);

    if (is_dir($storageRoot)) {
        rrmdir($storageRoot);
    }
};

/** @return array{status: int, body: string, headers: array<string>, raw: string} */
function http_request_against_worker(string $workerScriptPath, string $path = '/workspace/cron/process_k2_import_queue.php', string $method = 'GET'): array
{
    $port = (string) random_int(20000, 40999);
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $cmd = escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($workerScriptPath);
    $process = proc_open($cmd, $descriptorSpec, $pipes);
    if ($process === false) {
        throw new RuntimeException('Could not start the built-in PHP server for the web-SAPI simulation.');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    try {
        $connected = false;
        for ($i = 0; $i < 40; $i++) {
            $socket = @fsockopen('127.0.0.1', (int) $port, $errno, $errstr, 0.25);
            if ($socket !== false) {
                fclose($socket);
                $connected = true;
                break;
            }
            usleep(100000);
        }
        if (!$connected) {
            throw new RuntimeException("Built-in PHP server on port {$port} never became reachable.");
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'ignore_errors' => true,
                'timeout' => 5,
                'header' => "Connection: close\r\n",
            ],
        ]);
        $body = @file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        $body = $body === false ? '' : $body;
        $headers = $http_response_header ?? [];

        $status = 0;
        if (isset($headers[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $m) === 1) {
            $status = (int) $m[1];
        }

        return [
            'status' => $status,
            'body' => $body,
            'headers' => $headers,
            'raw' => implode("\n", $headers) . "\n\n" . $body,
        ];
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

function str_contains_any(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}

function seed_queued_attempt(PDO $pdo, string $batchUidSuffix): int
{
    $batchUid = $batchUidSuffix . '-' . bin2hex(random_bytes(4));
    $manifestHash = hash('sha256', $batchUid);
    $pdo->prepare('INSERT INTO k2_automation_import_batches (batch_uid, source_manifest_hash, state, archive_state) VALUES (?, ?, ?, ?)')
        ->execute([$batchUid, $manifestHash, 'AON_QUEUED', 'NOT_READY']);
    $batchId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO k2_automation_operation_attempts ' .
        '(batch_id, operation_type, attempt_number, attempt_idempotency_key, status, staged_file_manifest, worker_claim_token, worker_claimed_at, worker_hostname, worker_pid) ' .
        'VALUES (?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL)'
    )->execute([$batchId, 'aon', 1, 'attempt-' . $batchUid, 'STARTED', '[]']);

    return $batchId;
}

function fetch_attempt(PDO $pdo, int $batchId): array
{
    return fetch_one(
        $pdo,
        'SELECT status, worker_claim_token, worker_claimed_at, worker_hostname, worker_pid, staged_file_manifest ' .
        'FROM k2_automation_operation_attempts WHERE batch_id = ?',
        [$batchId]
    );
}

/** @return array{prs: int, aon_skus: int, sku_dictionary: int} */
function business_table_counts(PDO $pdo): array
{
    return [
        'prs' => table_count($pdo, 'prs'),
        'aon_skus' => table_count($pdo, 'aon_skus'),
        'sku_dictionary' => table_count($pdo, 'sku_dictionary'),
    ];
}

function create_business_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS prs (
        ticket_no VARCHAR(10) NOT NULL,
        ticket_line_item VARCHAR(10) NOT NULL,
        PRIMARY KEY (ticket_no, ticket_line_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_skus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku_code VARCHAR(100) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uq_sku_code (sku_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sku_dictionary (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(100) NOT NULL,
        UNIQUE KEY uq_sku (sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function reset_all_tables(PDO $pdo): void
{
    foreach (['prs', 'aon_skus', 'sku_dictionary'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'k2_automation_api_nonces',
        'k2_automation_operation_attempts',
        'k2_automation_operation_requests',
        'k2_automation_import_batch_files',
        'k2_automation_source_files',
        'k2_automation_import_batches',
    ] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

function fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException("Expected one row for query: {$sql}");
    }

    return $row;
}

function connect_with_retries(string $dsn, string $user, string $password): PDO
{
    $lastError = null;
    for ($attempt = 1; $attempt <= 60; $attempt++) {
        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $lastError = $e;
            usleep(500000);
        }
    }

    throw $lastError ?? new RuntimeException('Could not connect to disposable database.');
}

function apply_migration(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Could not read migration: {$path}");
    }

    try {
        $pdo->exec($sql);
    } catch (PDOException $e) {
        // Tolerate "duplicate column"/"already exists"-shaped errors so this
        // file can run repeatedly against the same disposable database
        // (phase2/phase4 use CREATE TABLE IF NOT EXISTS already; phase8 uses
        // plain ALTER ADD COLUMN, which is not itself idempotent).
        if (!str_contains($e->getMessage(), 'Duplicate column') && !str_contains($e->getMessage(), 'check constraint') && !str_contains($e->getMessage(), "Duplicate key name")) {
            throw $e;
        }
    }
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? "{$message} " : '') . 'Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $value, string $message = 'Expected true.'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

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
    echo "{$failures} worker web-SAPI rejection test(s) failed.\n";
    exit(1);
}

echo count($tests) . " worker web-SAPI rejection test(s) passed.\n";
