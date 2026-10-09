<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/import_orchestrator.php';

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
apply_automation_schema($pdo);

$tests = [];

$tests['deterministic batch registration and replay'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'registration');
    $first = $service->registerBatch($files, $schedule, 'req-register-1');
    $second = $service->registerBatch(array_reverse($files), $schedule, 'req-register-2');

    assert_same(K2ImportResponseCode::BATCH_REGISTERED, $first['code']);
    assert_same(K2ImportResponseCode::BATCH_REPLAYED, $second['code']);
    assert_same(true, $second['replayed']);
    assert_same($first['batch_uid'], $second['batch_uid']);
    assert_same(3, count($repo->findSourceFilesForBatch((int) $first['result']['batch_id'])));
};

$tests['conflicting registration rejection and rollback'] = function () use ($pdo): void {
    [$service, , $files, $schedule] = service_fixture($pdo, 'registration-conflict');
    $first = $service->registerBatch($files, $schedule, 'req-conflict-1');
    $otherSchedule = new K2ImportScheduleMetadata('2026-07-22 12:00:00', 'Asia/Jakarta');

    expect_exception(K2ImportOrchestrationException::class, function () use ($service, $files, $otherSchedule): void {
        $service->registerBatch($files, $otherSchedule, 'req-conflict-2');
    });

    $changed = sample_files('registration-conflict', [
        K2ImportDatasetType::PR => [
            'originalFilename' => 'Conflicting PR Name.xlsx',
            'sha256ContentHash' => str_repeat('f', 64),
        ],
    ]);
    $changedBatchUid = K2ImportIdentity::batchUid($changed);
    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($service, $changed, $schedule): void {
        $service->registerBatch($changed, $schedule, 'req-conflict-3');
    });

    assert_same(null, fetch_optional($pdo, 'SELECT id FROM k2_automation_import_batches WHERE batch_uid = ?', [$changedBatchUid]));
    assert_same(K2ImportBatchState::DETECTED, (string) fetch_one(
        $pdo,
        'SELECT state FROM k2_automation_import_batches WHERE batch_uid = ?',
        [$first['batch_uid']]
    )['state']);
};

$tests['exactly three source files'] = function () use ($pdo): void {
    [$service, , $files, $schedule] = service_fixture($pdo, 'exactly-three');
    unset($files[K2ImportDatasetType::PRICE]);

    expect_exception(InvalidArgumentException::class, function () use ($service, $files, $schedule): void {
        $service->registerBatch($files, $schedule, 'req-missing');
    });
};

$tests['scheduled slot storage'] = function () use ($pdo): void {
    [$service, , $files, $schedule] = service_fixture($pdo, 'p3-scheduled-slot');
    $response = $service->registerBatch($files, $schedule, 'req-slot');
    $row = fetch_one(
        $pdo,
        'SELECT scheduled_slot_at_utc, schedule_timezone FROM k2_automation_import_batches WHERE batch_uid = ?',
        [$response['batch_uid']]
    );

    assert_same('2026-07-22 02:00:00.000000', (string) $row['scheduled_slot_at_utc']);
    assert_same('Asia/Jakarta', (string) $row['schedule_timezone']);
};

$tests['atomic lock claim competing recovery and owner release'] = function () use ($pdo): void {
    [$service, , $files, $schedule] = service_fixture($pdo, 'locks', 1);
    $batch = $service->registerBatch($files, $schedule, 'req-lock-register');

    $lockOne = $service->claimBatchLock($batch['batch_uid'], 'owner-one', 'req-lock-1');
    $lockTwo = $service->claimBatchLock($batch['batch_uid'], 'owner-two', 'req-lock-2');
    assert_same(K2ImportResponseCode::LOCK_ACQUIRED, $lockOne['code']);
    assert_same(K2ImportResponseCode::LOCK_CONFLICT, $lockTwo['code']);

    $wrongRelease = $service->releaseBatchLock($batch['batch_uid'], 'owner-two', 'req-lock-release-wrong');
    assert_same(K2ImportResponseCode::LOCK_RELEASE_CONFLICT, $wrongRelease['code']);

    $pdo->prepare(
        'UPDATE k2_automation_import_batches SET run_lock_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE batch_uid = ?'
    )->execute([$batch['batch_uid']]);
    $recovered = $service->claimBatchLock($batch['batch_uid'], 'owner-two', 'req-lock-recover');
    assert_same(K2ImportResponseCode::LOCK_ACQUIRED, $recovered['code']);

    $released = $service->releaseBatchLock($batch['batch_uid'], 'owner-two', 'req-lock-release');
    assert_same(K2ImportResponseCode::LOCK_RELEASED, $released['code']);
};

$tests['AON success moves to PR'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'aon-success');
    $batch = register_and_lock($service, $files, $schedule, 'aon-success');
    $requestKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $requestKey, 'lock-aon-success');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $success = $service->recordOperationSuccess(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $requestKey,
        (int) $start['result']['attempt_id'],
        ['inserted_count' => 1],
        'lock-aon-success'
    );

    assert_same(K2ImportBatchState::AON_SUCCESS, $success['state']);
    assert_same(K2ImportNextAction::PR, $success['next_action']);
};

$tests['retryable and final AON failure'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'aon-retryable');
    $batch = register_and_lock($service, $files, $schedule, 'aon-retryable');
    $requestKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $requestKey, 'lock-aon-retryable');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $failure = $service->recordOperationFailure(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $requestKey,
        (int) $start['result']['attempt_id'],
        'TEMPORARY_AON_ERROR',
        'Synthetic retryable AON failure.',
        true,
        'lock-aon-retryable'
    );

    assert_same(K2ImportBatchState::AON_FAILED_RETRYABLE, $failure['state']);
    assert_same(K2ImportNextAction::AON, $failure['next_action']);

    [$serviceFinal, $repoFinal, $filesFinal, $scheduleFinal] = service_fixture($pdo, 'aon-final');
    $batchFinal = register_and_lock($serviceFinal, $filesFinal, $scheduleFinal, 'aon-final');
    $requestKeyFinal = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $filesFinal, $scheduleFinal);
    $startFinal = $serviceFinal->beginOperation(
        $batchFinal['batch_uid'],
        K2ImportOperationType::AON,
        $requestKeyFinal,
        'lock-aon-final'
    );
    force_claim_for_processing($repoFinal, $batchFinal['batch_uid'], K2ImportOperationType::AON);
    $finalFailure = $serviceFinal->recordOperationFailure(
        $batchFinal['batch_uid'],
        K2ImportOperationType::AON,
        $requestKeyFinal,
        (int) $startFinal['result']['attempt_id'],
        'AON_VALIDATION_FAILED',
        'Synthetic final AON failure.',
        false,
        'lock-aon-final'
    );

    assert_same(K2ImportBatchState::AON_FAILED_FINAL, $finalFailure['state']);
    assert_same(K2ImportNextAction::NONE, $finalFailure['next_action']);
};

$tests['PR retry preserves AON success'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'pr-retry');
    $batch = complete_aon($service, $repo, $files, $schedule, 'pr-retry');
    $prKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::PR, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::PR, $prKey, 'lock-pr-retry');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::PR);
    $failure = $service->recordOperationFailure(
        $batch['batch_uid'],
        K2ImportOperationType::PR,
        $prKey,
        (int) $start['result']['attempt_id'],
        'TEMPORARY_PR_ERROR',
        'Synthetic retryable PR failure.',
        true,
        'lock-pr-retry'
    );

    assert_same(K2ImportBatchState::PR_FAILED_RETRYABLE, $failure['state']);
    assert_same(K2ImportNextAction::PR, $failure['next_action']);

    $aonReplay = $service->beginOperation(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        'k2req_' . str_repeat('1', 64),
        'lock-pr-retry'
    );
    assert_same(K2ImportResponseCode::INVALID_TRANSITION, $aonReplay['code']);
};

$tests['final PR failure'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'pr-final');
    $batch = complete_aon($service, $repo, $files, $schedule, 'pr-final');
    $prKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::PR, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::PR, $prKey, 'lock-pr-final');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::PR);
    $failure = $service->recordOperationFailure(
        $batch['batch_uid'],
        K2ImportOperationType::PR,
        $prKey,
        (int) $start['result']['attempt_id'],
        'PR_VALIDATION_FAILED',
        'Synthetic final PR failure.',
        false,
        'lock-pr-final'
    );

    assert_same(K2ImportBatchState::PR_FAILED_FINAL, $failure['state']);
    assert_same(K2ImportNextAction::NONE, $failure['next_action']);
};

$tests['IMPORT_SUCCESS transitions to ARCHIVE_PENDING'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'archive-ready');
    $batch = complete_import($service, $repo, $files, $schedule, 'archive-ready');
    $ready = $service->markArchivePending($batch['batch_uid'], 'lock-archive-ready');

    assert_same(K2ImportBatchState::ARCHIVE_PENDING, $ready['state']);
    assert_same(K2ImportArchiveState::PENDING, $ready['archive_state']);
    assert_same(K2ImportNextAction::ARCHIVE, $ready['next_action']);
};

$tests['archive retry without import and completed terminal behavior'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'archive-retry');
    $batch = complete_import($service, $repo, $files, $schedule, 'archive-retry');
    $ready = $service->markArchivePending($batch['batch_uid'], 'lock-archive-retry');
    $archiveKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::ARCHIVE, $files, $schedule);
    $archiveStart = $service->beginOperation($ready['batch_uid'], K2ImportOperationType::ARCHIVE, $archiveKey, 'lock-archive-retry');
    $archiveFailure = $service->recordOperationFailure(
        $ready['batch_uid'],
        K2ImportOperationType::ARCHIVE,
        $archiveKey,
        (int) $archiveStart['result']['attempt_id'],
        'ARCHIVE_TEMPORARY_ERROR',
        'Synthetic retryable archive failure.',
        true,
        'lock-archive-retry'
    );

    assert_same(K2ImportBatchState::ARCHIVE_PENDING, $archiveFailure['state']);
    assert_same(K2ImportArchiveState::FAILED_RETRYABLE, $archiveFailure['archive_state']);
    assert_same(K2ImportNextAction::ARCHIVE, $archiveFailure['next_action']);

    $archiveKeyTwo = 'k2req_' . str_repeat('2', 64);
    $archiveStartTwo = $service->beginOperation(
        $ready['batch_uid'],
        K2ImportOperationType::ARCHIVE,
        $archiveKeyTwo,
        'lock-archive-retry'
    );
    assert_same(2, (int) $archiveStartTwo['result']['attempt_number']);
    $completed = $service->recordOperationSuccess(
        $ready['batch_uid'],
        K2ImportOperationType::ARCHIVE,
        $archiveKeyTwo,
        (int) $archiveStartTwo['result']['attempt_id'],
        [],
        'lock-archive-retry'
    );

    assert_same(K2ImportBatchState::COMPLETED, $completed['state']);
    assert_same(K2ImportArchiveState::COMPLETED, $completed['archive_state']);
    assert_same(K2ImportNextAction::NONE, $completed['next_action']);
};

$tests['request replay after timeout'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'request-replay');
    $batch = register_and_lock($service, $files, $schedule, 'request-replay');
    $key = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $key, 'lock-request-replay');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $service->recordOperationSuccess(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $key,
        (int) $start['result']['attempt_id'],
        [],
        'lock-request-replay'
    );

    $replay = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $key, 'lock-request-replay');
    assert_same(K2ImportResponseCode::REQUEST_REPLAYED, $replay['code']);
    assert_same(true, $replay['replayed']);
    assert_same(K2ImportBatchState::AON_SUCCESS, $replay['state']);
};

$tests['attempt number progression'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'attempt-progression');
    $batch = register_and_lock($service, $files, $schedule, 'attempt-progression');
    $keyOne = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $startOne = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $keyOne, 'lock-attempt-progression');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $service->recordOperationFailure(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $keyOne,
        (int) $startOne['result']['attempt_id'],
        'TEMPORARY',
        'Synthetic retry.',
        true,
        'lock-attempt-progression'
    );

    $keyTwo = 'k2req_' . str_repeat('3', 64);
    $startTwo = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $keyTwo, 'lock-attempt-progression');
    assert_same(1, (int) $startOne['result']['attempt_number']);
    assert_same(2, (int) $startTwo['result']['attempt_number']);
};

$tests['stale AON processing recovers to retryable failure'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'stale-aon', 60, 30);
    $batch = register_and_lock($service, $files, $schedule, 'stale-aon');
    $key = K2ImportIdentity::operationRequestIdempotencyKey($batch['batch_uid'], K2ImportOperationType::AON, 'request-stale-aon-1', 1);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $key, 'lock-stale-aon', 'request-stale-aon-1', 1);
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    force_stale_operation($pdo, $batch['batch_uid'], (int) $start['result']['attempt_id']);

    $status = $service->batchStatus($batch['batch_uid'], 'request-stale-aon-status');
    assert_same(K2ImportBatchState::AON_FAILED_RETRYABLE, $status['state']);
    assert_same(2, $status['next_attempt_number']);
    assert_same(null, fetch_one($pdo, 'SELECT run_lock_token FROM k2_automation_import_batches WHERE batch_uid = ?', [$batch['batch_uid']])['run_lock_token']);
    assert_same(K2ImportAttemptStatus::FAILED_RETRYABLE, (string) fetch_one(
        $pdo,
        'SELECT status FROM k2_automation_operation_attempts WHERE id = ?',
        [(int) $start['result']['attempt_id']]
    )['status']);
};

$tests['stale PR processing recovers while preserving AON success'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'stale-pr', 60, 30);
    $batch = complete_aon($service, $repo, $files, $schedule, 'stale-pr');
    $key = K2ImportIdentity::operationRequestIdempotencyKey($batch['batch_uid'], K2ImportOperationType::PR, 'request-stale-pr-1', 1);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::PR, $key, 'lock-stale-pr', 'request-stale-pr-1', 1);
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::PR);
    force_stale_operation($pdo, $batch['batch_uid'], (int) $start['result']['attempt_id']);

    $status = $service->batchStatus($batch['batch_uid'], 'request-stale-pr-status');
    assert_same(K2ImportBatchState::PR_FAILED_RETRYABLE, $status['state']);
    assert_same(K2ImportArchiveState::NOT_READY, $status['archive_state']);
    assert_same(K2ImportNextAction::PR, $status['next_action']);
    assert_same(2, $status['next_attempt_number']);
};

$tests['stale archive processing recovers while preserving import success'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'stale-archive', 60, 30);
    $batch = complete_import($service, $repo, $files, $schedule, 'stale-archive');
    $ready = $service->markArchivePending($batch['batch_uid'], 'lock-stale-archive');
    $key = K2ImportIdentity::operationRequestIdempotencyKey($batch['batch_uid'], K2ImportOperationType::ARCHIVE, 'request-stale-archive-1', 1);
    $start = $service->beginOperation($ready['batch_uid'], K2ImportOperationType::ARCHIVE, $key, 'lock-stale-archive', 'request-stale-archive-1', 1);
    force_stale_operation($pdo, $batch['batch_uid'], (int) $start['result']['attempt_id']);

    $status = $service->batchStatus($batch['batch_uid'], 'request-stale-archive-status');
    assert_same(K2ImportBatchState::ARCHIVE_PENDING, $status['state']);
    assert_same(K2ImportArchiveState::FAILED_RETRYABLE, $status['archive_state']);
    assert_same(K2ImportNextAction::ARCHIVE, $status['next_action']);
    assert_same(2, $status['next_attempt_number']);
};

$tests['active unexpired processing operation is not recovered'] = function () use ($pdo): void {
    [$service, $repo, $files, $schedule] = service_fixture($pdo, 'active-not-stale', 60, 30);
    $batch = register_and_lock($service, $files, $schedule, 'active-not-stale');
    $key = K2ImportIdentity::operationRequestIdempotencyKey($batch['batch_uid'], K2ImportOperationType::AON, 'request-active-not-stale-1', 1);
    $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $key, 'lock-active-not-stale', 'request-active-not-stale-1', 1);
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);

    $status = $service->batchStatus($batch['batch_uid'], 'request-active-not-stale-status');
    assert_same(K2ImportBatchState::AON_PROCESSING, $status['state']);
    assert_same(K2ImportResponseCode::BATCH_STATUS, $status['code']);
};

$tests['conditional transition conflict response'] = function () use ($pdo): void {
    [$service, , $files, $schedule] = service_fixture($pdo, 'transition-conflict');
    $batch = register_and_lock($service, $files, $schedule, 'transition-conflict');
    $bad = $service->beginOperation(
        $batch['batch_uid'],
        K2ImportOperationType::PR,
        'k2req_' . str_repeat('4', 64),
        'lock-transition-conflict'
    );

    assert_same(K2ImportResponseCode::INVALID_TRANSITION, $bad['code']);
    assert_same(false, $bad['success']);
};

$tests['stable response contract keys'] = function () use ($pdo): void {
    [$service, , $files, $schedule] = service_fixture($pdo, 'response-contract');
    $response = $service->registerBatch($files, $schedule, 'req-response-contract');
    assert_same([
        'success',
        'code',
        'message',
        'request_id',
        'batch_uid',
        'replayed',
        'state',
        'archive_state',
        'next_action',
        'retryable',
        'current_attempt_number',
        'next_attempt_number',
        'active_request_id',
        'operation_status',
        'result',
        'error',
    ], array_keys($response));
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
    echo "{$failures} orchestration database test(s) failed.\n";
    exit(1);
}

echo count($tests) . " orchestration database test(s) passed.\n";

function service_fixture(PDO $pdo, string $suffix, int $lockSeconds = 900, int $staleSeconds = 1800): array
{
    $repo = new PdoK2ImportRunRepository($pdo);
    $service = new K2ImportOrchestrator($repo, $lockSeconds, null, $staleSeconds);
    $files = sample_files($suffix);
    $schedule = new K2ImportScheduleMetadata('2026-07-22 09:00:00', 'Asia/Jakarta');

    return [$service, $repo, $files, $schedule];
}

function force_stale_operation(PDO $pdo, string $batchUid, int $attemptId): void
{
    $pdo->prepare(
        'UPDATE k2_automation_import_batches ' .
        'SET run_lock_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 60 SECOND) WHERE batch_uid = ?'
    )->execute([$batchUid]);
    $pdo->prepare(
        'UPDATE k2_automation_operation_attempts ' .
        'SET started_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 120 SECOND) WHERE id = ?'
    )->execute([$attemptId]);
}

function register_and_lock(
    K2ImportOrchestrator $service,
    array $files,
    K2ImportScheduleMetadata $schedule,
    string $suffix
): array {
    $batch = $service->registerBatch($files, $schedule, "req-{$suffix}-register");
    $lock = $service->claimBatchLock($batch['batch_uid'], "lock-{$suffix}", "req-{$suffix}-lock");
    assert_same(K2ImportResponseCode::LOCK_ACQUIRED, $lock['code']);

    return $batch;
}

function complete_aon(
    K2ImportOrchestrator $service,
    PdoK2ImportRunRepository $repo,
    array $files,
    K2ImportScheduleMetadata $schedule,
    string $suffix
): array {
    $batch = register_and_lock($service, $files, $schedule, $suffix);
    $key = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $key, "lock-{$suffix}");
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $service->recordOperationSuccess(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $key,
        (int) $start['result']['attempt_id'],
        ['inserted_count' => 1],
        "lock-{$suffix}"
    );

    return $batch;
}

function complete_import(
    K2ImportOrchestrator $service,
    PdoK2ImportRunRepository $repo,
    array $files,
    K2ImportScheduleMetadata $schedule,
    string $suffix
): array {
    $batch = complete_aon($service, $repo, $files, $schedule, $suffix);
    $key = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::PR, $files, $schedule);
    $start = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::PR, $key, "lock-{$suffix}");
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::PR);
    $service->recordOperationSuccess(
        $batch['batch_uid'],
        K2ImportOperationType::PR,
        $key,
        (int) $start['result']['attempt_id'],
        ['updated_count' => 1],
        "lock-{$suffix}"
    );

    return $batch;
}

/** Force-claims a queued attempt for processing without the real claim/worker path (these tests are not about claiming). */
function force_claim_for_processing(PdoK2ImportRunRepository $repo, string $batchUid, string $operationType): void
{
    $batch = $repo->findBatchByUid($batchUid);
    assert_true($batch !== null, "Batch {$batchUid} not found for forced claim.");
    assert_true($repo->transitionBatchState(
        (int) $batch['id'],
        K2ImportStateMachine::queuedStateFor($operationType),
        K2ImportArchiveState::NOT_READY,
        K2ImportStateMachine::processingStateFor($operationType),
        K2ImportArchiveState::NOT_READY
    ), "Could not force-claim {$batchUid} for {$operationType} processing.");
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

function apply_automation_schema(PDO $pdo): void
{
    if ((getenv('K2_AUTOMATION_TEST_SCHEMA') ?: '') === 'fresh') {
        apply_automation_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_fresh_install_schema.sql');
        return;
    }

    // phase2_schema.sql already includes what phase7 would add (see
    // test_api_controller_db.php's identical note); the orchestrator depends
    // on the AON_QUEUED/PR_QUEUED states added by phase8, so it must be
    // applied here too — matching every other *_db.php test bootstrap in
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
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
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

    $data = array_merge([
        'datasetType' => $datasetType,
        'driveFileId' => "drive-{$suffix}-{$datasetType}",
        'originalFilename' => "{$suffix}-{$datasetType}.xlsx",
        'modifiedTimestamp' => sprintf('2026-07-22T01:0%d:00+07:00', $index),
        'fileSizeBytes' => 1000 + $index,
        'sha256ContentHash' => hash('sha256', "{$datasetType}-{$suffix}"),
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
        throw new RuntimeException('Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_true(bool $value, string $message = 'Expected true.'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}
