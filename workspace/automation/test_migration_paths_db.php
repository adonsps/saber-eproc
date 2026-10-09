<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/api_controller.php';

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

$tests = [];
$freshSignature = null;
$upgradeSignature = null;

$tests['fresh install schema applies to an empty database'] = function () use ($pdo, &$freshSignature): void {
    reset_automation_schema($pdo);
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_fresh_install_schema.sql');

    assert_true(column_exists($pdo, 'k2_automation_operation_requests', 'logical_request_id'));
    assert_true(column_exists($pdo, 'k2_automation_operation_requests', 'attempt_number'));
    assert_true(index_exists($pdo, 'k2_automation_operation_requests', 'uq_k2_auto_operation_logical_request'));
    assert_true(table_exists($pdo, 'k2_automation_api_nonces'));

    $freshSignature = schema_signature($pdo);
};

$tests['phase 7 additive migration clearly fails after fresh install'] = function () use ($pdo): void {
    expect_pdo_exception(function () use ($pdo): void {
        apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase7_schema.sql');
    });
};

$tests['phase 8 additive migration clearly fails after fresh install'] = function () use ($pdo): void {
    expect_pdo_exception(function () use ($pdo): void {
        apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
    });
};

$tests['pre phase 7 schema upgrades to the same final shape'] = function () use ($pdo, &$freshSignature, &$upgradeSignature): void {
    reset_automation_schema($pdo);
    apply_sql($pdo, simulated_pre_phase7_schema_sql());
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');

    assert_false(column_exists($pdo, 'k2_automation_operation_requests', 'logical_request_id'));
    assert_false(column_exists($pdo, 'k2_automation_operation_requests', 'attempt_number'));
    assert_false(index_exists($pdo, 'k2_automation_operation_requests', 'uq_k2_auto_operation_logical_request'));

    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase7_schema.sql');

    assert_false(column_exists($pdo, 'k2_automation_operation_attempts', 'staged_file_manifest'));
    assert_false(column_exists($pdo, 'k2_automation_operation_attempts', 'worker_claim_token'));

    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
    $upgradeSignature = schema_signature($pdo);
    assert_same($freshSignature, $upgradeSignature);
};

$tests['repeated phase 7 additive migration clearly fails after upgrade'] = function () use ($pdo): void {
    expect_pdo_exception(function () use ($pdo): void {
        apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase7_schema.sql');
    });
};

$tests['repeated phase 8 additive migration clearly fails after upgrade'] = function () use ($pdo): void {
    expect_pdo_exception(function () use ($pdo): void {
        apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
    });
};

$tests['existing production-like rows remain valid after the phase 8 enum/check widening'] = function () use ($pdo): void {
    reset_automation_schema($pdo);
    apply_sql($pdo, simulated_pre_phase7_schema_sql());
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase7_schema.sql');

    // Rows representing every pre-phase-8 (state, archive_state) pair a real
    // production database could hold at the moment of the upgrade — inserted
    // BEFORE phase 8 runs its DROP CHECK + ADD CONSTRAINT (which MySQL validates
    // against every existing row, not just new ones).
    $preExistingPairs = [
        ['DETECTED', 'NOT_READY'],
        ['AON_PROCESSING', 'NOT_READY'],
        ['AON_SUCCESS', 'NOT_READY'],
        ['AON_FAILED_RETRYABLE', 'NOT_READY'],
        ['AON_FAILED_FINAL', 'NOT_READY'],
        ['IMPORT_SUCCESS', 'NOT_READY'],
        ['ARCHIVE_PENDING', 'PENDING'],
        ['ARCHIVE_PENDING', 'PROCESSING'],
        ['ARCHIVE_PENDING', 'FAILED_RETRYABLE'],
        ['ARCHIVE_PENDING', 'FAILED_FINAL'],
        ['COMPLETED', 'COMPLETED'],
    ];
    $insertedIds = [];
    $insertStmt = $pdo->prepare(
        'INSERT INTO k2_automation_import_batches ' .
        '(batch_uid, source_manifest_hash, scheduled_slot_at_utc, schedule_timezone, state, archive_state) ' .
        "VALUES (?, ?, '2026-07-22 09:00:00.000000', 'Asia/Jakarta', ?, ?)"
    );
    foreach ($preExistingPairs as $index => [$state, $archiveState]) {
        $insertStmt->execute(["k2b_prod_like_{$index}_" . str_repeat('a', 40), hash('sha256', "prod-like-row-{$index}"), $state, $archiveState]);
        $insertedIds[] = (int) $pdo->lastInsertId();
    }

    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');

    foreach ($insertedIds as $index => $id) {
        $row = fetch_all($pdo, "SELECT state, archive_state FROM k2_automation_import_batches WHERE id = {$id}")[0] ?? null;
        assert_true($row !== null, "Pre-existing row {$id} disappeared after the phase 8 migration.");
        assert_same($preExistingPairs[$index][0], $row['state'], "Pre-existing row {$id} state was altered by the migration.");
        assert_same($preExistingPairs[$index][1], $row['archive_state'], "Pre-existing row {$id} archive_state was altered by the migration.");
    }

    // The widened CHECK constraint must still be enforced for genuinely invalid
    // pairs after the migration — the widening must not have accidentally
    // disabled validation entirely.
    expect_pdo_exception(function () use ($pdo): void {
        $pdo->exec(
            "INSERT INTO k2_automation_import_batches " .
            "(batch_uid, source_manifest_hash, scheduled_slot_at_utc, schedule_timezone, state, archive_state) " .
            "VALUES ('k2b_invalid_pair_check', '" . str_repeat('z', 64) . "', '2026-07-22 09:00:00.000000', 'Asia/Jakarta', 'AON_QUEUED', 'COMPLETED')"
        );
    });
};

$tests['fresh install schema supports repository and nonce smoke tests'] = function () use ($pdo): void {
    reset_automation_schema($pdo);
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_fresh_install_schema.sql');

    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('fresh-smoke');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files,
        new K2ImportScheduleMetadata('2026-07-22 09:00:00', 'Asia/Jakarta')
    );
    assert_true($batchId > 0);

    $nonceStore = new PdoK2AutomationNonceStore($pdo);
    assert_true($nonceStore->storeNonce(
        'client-a',
        'nonce-fresh-smoke',
        'request-fresh-smoke',
        new DateTimeImmutable('2026-07-22T02:00:00Z'),
        new DateTimeImmutable('2026-07-22T02:05:00Z')
    ));
    assert_false($nonceStore->storeNonce(
        'client-a',
        'nonce-fresh-smoke',
        'request-fresh-smoke',
        new DateTimeImmutable('2026-07-22T02:00:00Z'),
        new DateTimeImmutable('2026-07-22T02:05:00Z')
    ));
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

reset_automation_schema($pdo);

if ($failures > 0) {
    echo "{$failures} migration path test(s) failed.\n";
    exit(1);
}

echo count($tests) . " migration path test(s) passed.\n";

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
    apply_sql($pdo, $sql);
}

function apply_sql(PDO $pdo, string $sql): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    if ($sql === null) {
        throw new RuntimeException('Could not strip SQL comments.');
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function simulated_pre_phase7_schema_sql(): string
{
    $path = __DIR__ . '/../../db_backups/k2_drive_automation_phase2_schema.sql';
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Could not read migration: {$path}");
    }

    $start = strpos($sql, 'CREATE TABLE IF NOT EXISTS `k2_automation_operation_requests`');
    $end = strpos($sql, 'CREATE TABLE IF NOT EXISTS `k2_automation_operation_attempts`');
    if ($start === false || $end === false || $end <= $start) {
        throw new RuntimeException('Could not locate operation request table in Phase 2 schema.');
    }

    $before = substr($sql, 0, $start);
    $block = substr($sql, $start, $end - $start);
    $after = substr($sql, $end);
    $block = preg_replace('/^\s*`logical_request_id` VARCHAR\(128\) NOT NULL,\R/m', '', $block);
    $block = preg_replace('/^\s*`attempt_number` SMALLINT UNSIGNED NOT NULL,\R/m', '', (string) $block);
    $block = preg_replace('/^\s*UNIQUE KEY `uq_k2_auto_operation_logical_request` \(`batch_id`, `operation_type`, `logical_request_id`\),\R/m', '', (string) $block);
    if ($block === null || str_contains($block, 'logical_request_id') || str_contains($block, 'uq_k2_auto_operation_logical_request')) {
        throw new RuntimeException('Could not derive simulated pre-Phase-7 schema.');
    }

    return $before . $block . $after;
}

function schema_signature(PDO $pdo): array
{
    $tables = fetch_all(
        $pdo,
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'k2\\_automation\\_%' ORDER BY TABLE_NAME"
    );
    $columns = fetch_all(
        $pdo,
        "SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY " .
        "FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'k2\\_automation\\_%' " .
        "ORDER BY TABLE_NAME, ORDINAL_POSITION"
    );
    $indexes = fetch_all(
        $pdo,
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART " .
        "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'k2\\_automation\\_%' " .
        "ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX"
    );

    return ['tables' => $tables, 'columns' => $columns, 'indexes' => $indexes];
}

function reset_automation_schema(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ([
        'k2_automation_api_nonces',
        'k2_automation_operation_attempts',
        'k2_automation_operation_requests',
        'k2_automation_import_batch_files',
        'k2_automation_source_files',
        'k2_automation_import_batches',
    ] as $table) {
        $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);

    return (bool) $stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);

    return (bool) $stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);

    return (bool) $stmt->fetchColumn();
}

function fetch_all(PDO $pdo, string $sql): array
{
    $stmt = $pdo->query($sql);
    if ($stmt === false) {
        throw new RuntimeException("Query failed: {$sql}");
    }

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function sample_files(string $suffix): array
{
    $files = [];
    foreach (K2ImportDatasetType::requiredTypes() as $index => $datasetType) {
        $files[$datasetType] = new K2ImportSourceFileMetadata(
            $datasetType,
            "drive-{$suffix}-{$datasetType}",
            "{$suffix}-{$datasetType}.xlsx",
            sprintf('2026-07-22T01:0%d:00+07:00', $index),
            1000 + $index,
            hash('sha256', "{$datasetType}-{$suffix}")
        );
    }

    return $files;
}

function expect_pdo_exception(callable $callback): void
{
    try {
        $callback();
    } catch (PDOException) {
        return;
    }

    throw new RuntimeException('Expected PDOException to be thrown.');
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
