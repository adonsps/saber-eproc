<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/import_repository.php';

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(
        STDERR,
        "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required for disposable DB tests.\n"
    );
    exit(1);
}

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
apply_automation_schema($pdo);

$tests = [];

$tests['schema enums and datetime precision'] = function () use ($pdo): void {
    assert_same(
        "enum('RECEIVED','RUNNING','SUCCEEDED','FAILED_RETRYABLE','FAILED_FINAL','REPLAYED')",
        column_type($pdo, 'k2_automation_operation_requests', 'request_status')
    );
    assert_same(
        "enum('STARTED','SUCCEEDED','FAILED_RETRYABLE','FAILED_FINAL')",
        column_type($pdo, 'k2_automation_operation_attempts', 'status')
    );
    assert_same('datetime(6)', column_type($pdo, 'k2_automation_import_batches', 'scheduled_slot_at_utc'));
    assert_same('varchar(64)', column_type($pdo, 'k2_automation_import_batches', 'schedule_timezone'));
};

$tests['scheduled slot persists with microsecond UTC normalization'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('scheduled-slot');
    $schedule = new K2ImportScheduleMetadata('2026-07-22 08:30:15.123456', 'Asia/Jakarta');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files,
        $schedule
    );

    $row = fetch_one($pdo, 'SELECT scheduled_slot_at_utc, schedule_timezone FROM k2_automation_import_batches WHERE id = ?', [$batchId]);
    assert_same('2026-07-22 01:30:15.123456', (string) $row['scheduled_slot_at_utc']);
    assert_same('Asia/Jakarta', (string) $row['schedule_timezone']);
};

$tests['state pair check constraint rejects impossible combinations'] = function () use ($pdo): void {
    expect_exception(PDOException::class, function () use ($pdo): void {
        $stmt = $pdo->prepare(
            'INSERT INTO k2_automation_import_batches ' .
            '(batch_uid, source_manifest_hash, state, archive_state) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'invalid-pair-batch',
            str_repeat('9', 64),
            K2ImportBatchState::DETECTED,
            K2ImportArchiveState::PENDING,
        ]);
    });
};

$tests['dataset type composite foreign key rejects mismatched source link'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('fk-source');
    $sourceBatchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );
    $sourceFileId = (int) fetch_one(
        $pdo,
        'SELECT source_file_id FROM k2_automation_import_batch_files WHERE batch_id = ? AND dataset_type = ?',
        [$sourceBatchId, K2ImportDatasetType::PR]
    )['source_file_id'];

    $targetBatchId = insert_empty_batch($pdo, 'fk-target');
    expect_exception(PDOException::class, function () use ($pdo, $targetBatchId, $sourceFileId): void {
        $stmt = $pdo->prepare(
            'INSERT INTO k2_automation_import_batch_files ' .
            '(batch_id, dataset_type, source_file_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$targetBatchId, K2ImportDatasetType::CATALOG, $sourceFileId]);
    });
};

$tests['batch file uniqueness retains one link per dataset and source'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('unique-links');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );
    $sourceFileId = (int) fetch_one(
        $pdo,
        'SELECT source_file_id FROM k2_automation_import_batch_files WHERE batch_id = ? AND dataset_type = ?',
        [$batchId, K2ImportDatasetType::PR]
    )['source_file_id'];

    expect_exception(PDOException::class, function () use ($pdo, $batchId, $sourceFileId): void {
        $stmt = $pdo->prepare(
            'INSERT INTO k2_automation_import_batch_files ' .
            '(batch_id, dataset_type, source_file_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$batchId, K2ImportDatasetType::PR, $sourceFileId]);
    });
};

$tests['source duplicate metadata mismatch rolls back batch creation'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('source-conflict');
    $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );

    $changed = sample_files('source-conflict', [
        K2ImportDatasetType::PR => [
            'originalFilename' => 'Conflicting PR Filename.xlsx',
            'fileSizeBytes' => 909090,
            'sha256ContentHash' => str_repeat('f', 64),
        ],
    ]);
    $changedBatchUid = K2ImportIdentity::batchUid($changed);

    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($repo, $changed, $changedBatchUid): void {
        $repo->insertDetectedBatch(
            $changedBatchUid,
            K2ImportIdentity::sourceManifestHash($changed),
            $changed
        );
    });

    assert_same(null, fetch_optional(
        $pdo,
        'SELECT id FROM k2_automation_import_batches WHERE batch_uid = ?',
        [$changedBatchUid]
    ));
};

$tests['operation request duplicate key validation and replay counter'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('request-duplicate');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );
    $requestKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files);

    $requestId = $repo->upsertOperationRequest($batchId, K2ImportOperationType::AON, $requestKey, 'logical-request-duplicate', 1);
    assert_same($requestId, $repo->upsertOperationRequest($batchId, K2ImportOperationType::AON, $requestKey, 'logical-request-duplicate', 1));
    assert_same('1', (string) fetch_one(
        $pdo,
        'SELECT duplicate_replay_count FROM k2_automation_operation_requests WHERE id = ?',
        [$requestId]
    )['duplicate_replay_count']);

    $otherBatchId = insert_empty_batch($pdo, 'request-conflict');
    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($repo, $otherBatchId, $requestKey): void {
        $repo->upsertOperationRequest($otherBatchId, K2ImportOperationType::AON, $requestKey, 'logical-request-duplicate', 1);
    });
    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($repo, $batchId, $requestKey): void {
        $repo->upsertOperationRequest($batchId, K2ImportOperationType::PR, $requestKey, 'logical-request-duplicate', 1);
    });
    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($repo, $batchId, $requestKey): void {
        $repo->upsertOperationRequest($batchId, K2ImportOperationType::AON, $requestKey, 'logical-request-duplicate', 2);
    });

    assert_same('1', (string) fetch_one(
        $pdo,
        'SELECT duplicate_replay_count FROM k2_automation_operation_requests WHERE id = ?',
        [$requestId]
    )['duplicate_replay_count']);
};

$tests['request running update is conditional after completion'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('request-status');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );
    $requestId = $repo->upsertOperationRequest(
        $batchId,
        K2ImportOperationType::AON,
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files),
        'logical-request-status',
        1
    );

    $repo->markOperationRequestRunning($requestId);
    $repo->markOperationRequestCompleted(
        $requestId,
        K2ImportRequestStatus::SUCCEEDED,
        K2ImportBatchState::AON_SUCCESS,
        K2ImportArchiveState::NOT_READY,
        200,
        str_repeat('a', 64)
    );

    expect_exception(K2ImportRepositoryStateConflictException::class, function () use ($repo, $requestId): void {
        $repo->markOperationRequestRunning($requestId);
    });
    assert_same(K2ImportRequestStatus::SUCCEEDED, (string) fetch_one(
        $pdo,
        'SELECT request_status FROM k2_automation_operation_requests WHERE id = ?',
        [$requestId]
    )['request_status']);
};

$tests['attempt uniqueness and statuses'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('attempts');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );

    $attemptId = $repo->recordOperationAttemptStarted(
        $batchId,
        null,
        K2ImportOperationType::PR,
        1,
        K2ImportIdentity::attemptIdempotencyKey(K2ImportOperationType::PR, $files, 1)
    );
    assert_same(K2ImportAttemptStatus::STARTED, (string) fetch_one(
        $pdo,
        'SELECT status FROM k2_automation_operation_attempts WHERE id = ?',
        [$attemptId]
    )['status']);

    expect_exception(PDOException::class, function () use ($repo, $batchId, $files): void {
        $repo->recordOperationAttemptStarted(
            $batchId,
            null,
            K2ImportOperationType::PR,
            1,
            K2ImportIdentity::attemptIdempotencyKey(K2ImportOperationType::PR, $files, 1)
        );
    });
};

$tests['conditional batch transitions compare both previous values'] = function () use ($pdo): void {
    $repo = new PdoK2ImportRunRepository($pdo);
    $files = sample_files('transitions');
    $batchId = $repo->insertDetectedBatch(
        K2ImportIdentity::batchUid($files),
        K2ImportIdentity::sourceManifestHash($files),
        $files
    );

    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::AON_QUEUED,
        K2ImportArchiveState::NOT_READY
    ));
    assert_false($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::AON_QUEUED,
        K2ImportArchiveState::NOT_READY
    ));

    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::AON_QUEUED,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::AON_PROCESSING,
        K2ImportArchiveState::NOT_READY
    ));
    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::AON_PROCESSING,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::AON_SUCCESS,
        K2ImportArchiveState::NOT_READY
    ));
    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::AON_SUCCESS,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::PR_QUEUED,
        K2ImportArchiveState::NOT_READY
    ));
    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::PR_QUEUED,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::PR_PROCESSING,
        K2ImportArchiveState::NOT_READY
    ));
    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::PR_PROCESSING,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::IMPORT_SUCCESS,
        K2ImportArchiveState::NOT_READY
    ));
    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::IMPORT_SUCCESS,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::PENDING
    ));

    expect_exception(K2ImportTransitionRejectedException::class, function () use ($repo, $batchId): void {
        $repo->transitionBatchState(
            $batchId,
            K2ImportBatchState::ARCHIVE_PENDING,
            K2ImportArchiveState::PENDING,
            K2ImportBatchState::COMPLETED,
            K2ImportArchiveState::NOT_READY
        );
    });

    assert_true($repo->transitionBatchState(
        $batchId,
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::PENDING,
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
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

if ($failures > 0) {
    echo "{$failures} database test(s) failed.\n";
    exit(1);
}

echo count($tests) . " database test(s) passed.\n";

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

function apply_automation_schema(PDO $pdo): void
{
    if ((getenv('K2_AUTOMATION_TEST_SCHEMA') ?: '') === 'fresh') {
        apply_automation_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_fresh_install_schema.sql');
        return;
    }

    // phase2_schema.sql already includes what phase7 would add (see
    // test_api_controller_db.php's identical note); this batch state machine
    // depends on the AON_QUEUED/PR_QUEUED states added by phase8, so it must
    // be applied here too — matching every other *_db.php test bootstrap in
    // this suite (test_api_controller_db.php, test_parser_adapters_db.php,
    // test_import_queue_worker_db.php, test_worker_web_sapi_rejection_db.php).
    apply_automation_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase2_schema.sql');
    apply_automation_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
    apply_automation_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
}

function apply_automation_migration(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Could not read migration: {$path}");
    }

    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    if ($sql === null) {
        throw new RuntimeException('Could not strip SQL comments from migration.');
    }

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if ($statement === '') {
            continue;
        }

        $pdo->exec($statement);
    }
}

function column_type(PDO $pdo, string $table, string $column): string
{
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
    $stmt->execute([$column]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row === false) {
        throw new RuntimeException("Column {$table}.{$column} not found.");
    }

    return (string) $row['Type'];
}

function insert_empty_batch(PDO $pdo, string $suffix): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO k2_automation_import_batches ' .
        '(batch_uid, source_manifest_hash, state, archive_state) VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        'empty-' . $suffix . '-' . bin2hex(random_bytes(4)),
        hash('sha256', 'empty-' . $suffix . random_bytes(8)),
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY,
    ]);

    return (int) $pdo->lastInsertId();
}

function sample_files(string $suffix, array $overridesByType = []): array
{
    $files = [];
    foreach (K2ImportDatasetType::requiredTypes() as $datasetType) {
        $files[$datasetType] = sample_file($datasetType, $suffix, $overridesByType[$datasetType] ?? []);
    }

    return $files;
}

function sample_file(string $datasetType, string $suffix, array $overrides = []): K2ImportSourceFileMetadata
{
    $index = array_search($datasetType, K2ImportDatasetType::requiredTypes(), true);
    if ($index === false) {
        throw new RuntimeException("Unsupported sample dataset type: {$datasetType}");
    }

    $hashChars = [
        K2ImportDatasetType::PR => 'a',
        K2ImportDatasetType::CATALOG => 'b',
        K2ImportDatasetType::PRICE => 'c',
    ];

    $data = array_merge([
        'datasetType' => $datasetType,
        'driveFileId' => "drive-{$suffix}-{$datasetType}",
        'originalFilename' => "{$suffix}-{$datasetType}.xlsx",
        'modifiedTimestamp' => sprintf('2026-07-22T01:0%d:00+07:00', $index),
        'fileSizeBytes' => 1000 + $index,
        'sha256ContentHash' => hash('sha256', "{$hashChars[$datasetType]}-{$suffix}"),
    ], $overrides);

    return new K2ImportSourceFileMetadata(
        $data['datasetType'],
        $data['driveFileId'],
        $data['originalFilename'],
        $data['modifiedTimestamp'],
        $data['fileSizeBytes'],
        $data['sha256ContentHash']
    );
}

function fetch_one(PDO $pdo, string $sql, array $params = []): array
{
    $row = fetch_optional($pdo, $sql, $params);
    if ($row === null) {
        throw new RuntimeException("Expected one row for query: {$sql}");
    }

    return $row;
}

function fetch_optional(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row === false ? null : $row;
}

function expect_exception(string $className, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $className) {
            return;
        }

        throw new RuntimeException("Expected {$className}, got " . get_class($e) . ': ' . $e->getMessage());
    }

    throw new RuntimeException("Expected {$className} to be thrown.");
}

function assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            'Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
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
