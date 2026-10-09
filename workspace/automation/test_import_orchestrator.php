<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/import_orchestrator.php';

$tests = [];

$tests['state based next action'] = function (): void {
    assert_same(K2ImportNextAction::AON, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY
    ));
    assert_same(K2ImportNextAction::AON, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::AON_FAILED_RETRYABLE,
        K2ImportArchiveState::NOT_READY
    ));
    assert_same(K2ImportNextAction::PR, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::AON_SUCCESS,
        K2ImportArchiveState::NOT_READY
    ));
    assert_same(K2ImportNextAction::PR, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::PR_FAILED_RETRYABLE,
        K2ImportArchiveState::NOT_READY
    ));
    assert_same(K2ImportNextAction::ARCHIVE, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::IMPORT_SUCCESS,
        K2ImportArchiveState::NOT_READY
    ));
    assert_same(K2ImportNextAction::ARCHIVE, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::PENDING
    ));
    assert_same(K2ImportNextAction::ARCHIVE, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::FAILED_RETRYABLE
    ));
    assert_same(K2ImportNextAction::NONE, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));
    assert_same(K2ImportNextAction::NONE, K2ImportOrchestrator::nextActionForState(
        K2ImportBatchState::AON_FAILED_FINAL,
        K2ImportArchiveState::NOT_READY
    ));
};

$tests['stable response contract'] = function (): void {
    $response = K2ImportResponseFactory::build(
        true,
        K2ImportResponseCode::BATCH_STATUS,
        'Batch status returned.',
        'req-001',
        'k2b_' . str_repeat('a', 64),
        false,
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY,
        K2ImportNextAction::AON,
        false,
        ['inserted_count' => 1],
        null
    );

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
    assert_same(true, $response['success']);
    assert_same(null, $response['error']);
};

$tests['response contract sanitizes unsafe values'] = function (): void {
    $response = K2ImportResponseFactory::build(
        false,
        K2ImportResponseCode::FINAL_FAILURE,
        'Safe failure.',
        'req-002',
        'k2b_' . str_repeat('b', 64),
        false,
        K2ImportBatchState::AON_FAILED_FINAL,
        K2ImportArchiveState::NOT_READY,
        K2ImportNextAction::NONE,
        false,
        ['callable' => static fn(): bool => true, 'safe' => 'value'],
        ['code' => 'SAFE_ERROR', 'trace' => static fn(): bool => true]
    );

    assert_false(isset($response['result']['callable']));
    assert_same('value', $response['result']['safe']);
    assert_false(isset($response['error']['trace']));
    assert_same('SAFE_ERROR', $response['error']['code']);
};

$tests['counter normalization'] = function (): void {
    assert_same([
        'inserted_count' => 2,
        'updated_count' => 0,
        'skipped_count' => 1,
        'warning_count' => 0,
    ], K2ImportOrchestrator::normalizeCounters([
        'inserted_count' => 2,
        'skipped_count' => 1,
    ]));

    expect_exception(InvalidArgumentException::class, function (): void {
        K2ImportOrchestrator::normalizeCounters(['inserted_count' => -1]);
    });
};

$tests['exactly three source files are required'] = function (): void {
    expect_exception(InvalidArgumentException::class, function (): void {
        K2ImportIdentity::normalizeSourceFiles([
            sample_file(K2ImportDatasetType::PR),
            sample_file(K2ImportDatasetType::CATALOG),
        ]);
    });

    expect_exception(K2ImportIdentityException::class, function (): void {
        K2ImportIdentity::normalizeSourceFiles([
            sample_file(K2ImportDatasetType::PR),
            sample_file(K2ImportDatasetType::PR, ['driveFileId' => 'duplicate-pr']),
            sample_file(K2ImportDatasetType::PRICE),
        ]);
    });
};

$tests['scheduled slot is required by orchestration'] = function (): void {
    $orchestrator = new K2ImportOrchestrator(new K2NullImportRepository());
    expect_exception(K2ImportOrchestrationException::class, function () use ($orchestrator): void {
        $orchestrator->registerBatch(sample_files(), new K2ImportScheduleMetadata(), 'req-missing-slot');
    });
};

$tests['fake adapter boundaries'] = function (): void {
    $aon = new FakeAonExecutor();
    $pr = new FakePrExecutor();
    $archive = new FakeArchiveHandler();

    assert_same(['inserted_count' => 1], $aon->importAon([])->counters);
    assert_same(['updated_count' => 1], $pr->importPr([])->counters);
    assert_same(true, $archive->handleArchiveResult([])->success);
};

final class FakeAonExecutor implements K2AonImportExecutorInterface
{
    public function importAon(array $context): K2ImportAdapterResult
    {
        return new K2ImportAdapterResult(true, ['inserted_count' => 1]);
    }
}

final class FakePrExecutor implements K2PrImportExecutorInterface
{
    public function importPr(array $context): K2ImportAdapterResult
    {
        return new K2ImportAdapterResult(true, ['updated_count' => 1]);
    }
}

final class FakeArchiveHandler implements K2ArchiveResultHandlerInterface
{
    public function handleArchiveResult(array $context): K2ImportAdapterResult
    {
        return new K2ImportAdapterResult(true);
    }
}

final class K2NullImportRepository implements K2ImportRunRepositoryInterface
{
    public function transactional(callable $callback): mixed
    {
        return $callback($this);
    }

    public function findBatchByUid(string $batchUid): ?array
    {
        return null;
    }

    public function findBatchById(int $batchId): ?array
    {
        return null;
    }

    public function insertDetectedBatch(
        string $batchUid,
        string $sourceManifestHash,
        array $files,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): int {
        return 1;
    }

    public function findSourceFilesForBatch(int $batchId): array
    {
        return [];
    }

    public function findOperationRequestByKey(string $requestIdempotencyKey): ?array
    {
        return null;
    }

    public function findOperationRequestByLogicalRequestId(
        int $batchId,
        string $operationType,
        string $logicalRequestId
    ): ?array {
        return null;
    }

    public function latestOperationRequestForOperation(int $batchId, string $operationType): ?array
    {
        return null;
    }

    public function findOperationAttemptByRequestId(int $operationRequestId): ?array
    {
        return null;
    }

    public function upsertOperationRequest(
        int $batchId,
        string $operationType,
        string $requestIdempotencyKey,
        string $logicalRequestId,
        int $attemptNumber
    ): int
    {
        return 1;
    }

    public function markOperationRequestRunning(int $requestId): void
    {
    }

    public function markOperationRequestCompleted(
        int $requestId,
        string $requestStatus,
        string $batchState,
        string $archiveState,
        int $httpStatus,
        ?string $responseBodyHash
    ): void {
    }

    public function recordOperationAttemptStarted(
        int $batchId,
        ?int $operationRequestId,
        string $operationType,
        int $attemptNumber,
        string $attemptIdempotencyKey
    ): int {
        return 1;
    }

    public function nextAttemptNumber(int $batchId, string $operationType): int
    {
        return 1;
    }

    public function markOperationAttemptSucceeded(int $attemptId, array $counters): void
    {
    }

    public function markOperationAttemptFailed(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        bool $retryable
    ): void {
    }

    public function transitionBatchState(
        int $batchId,
        string $fromState,
        string $fromArchiveState,
        string $toState,
        string $toArchiveState
    ): bool {
        return true;
    }

    public function claimBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool
    {
        return true;
    }

    public function extendBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool
    {
        return true;
    }

    public function releaseBatchLock(int $batchId, string $ownerToken): bool
    {
        return true;
    }

    public function hasActiveBatchLock(int $batchId, string $ownerToken): bool
    {
        return true;
    }

    public function recoverStaleProcessingBatch(int $batchId, int $staleSeconds): ?array
    {
        return null;
    }

    public function attachStagedFiles(int $attemptId, array $stagedFileManifest): void
    {
    }

    public function findAttemptById(int $attemptId): ?array
    {
        return null;
    }

    public function claimNextQueuedAttempt(
        string $workerToken,
        string $workerHostname,
        int $workerPid,
        int $leaseSeconds
    ): ?array {
        return null;
    }

    public function extendWorkerLease(int $attemptId, string $workerToken): bool
    {
        return true;
    }

    public function releaseWorkerClaim(int $attemptId, string $workerToken): bool
    {
        return true;
    }
}

function sample_files(array $overridesByType = []): array
{
    $files = [];
    foreach (K2ImportDatasetType::requiredTypes() as $datasetType) {
        $files[$datasetType] = sample_file($datasetType, $overridesByType[$datasetType] ?? []);
    }

    return $files;
}

function sample_file(string $datasetType, array $overrides = []): K2ImportSourceFileMetadata
{
    $index = array_search($datasetType, K2ImportDatasetType::requiredTypes(), true);
    if ($index === false) {
        throw new RuntimeException("Unsupported sample dataset type: {$datasetType}");
    }

    $data = array_merge([
        'datasetType' => $datasetType,
        'driveFileId' => 'drive-' . $datasetType . '-001',
        'originalFilename' => $datasetType . '.xlsx',
        'modifiedTimestamp' => sprintf('2026-07-22T01:0%d:00+07:00', $index),
        'fileSizeBytes' => 1000 + $index,
        'sha256ContentHash' => str_repeat(['a', 'b', 'c'][$index], 64),
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

function assert_false(bool $value, string $message = 'Expected false.'): void
{
    if ($value) {
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
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo count($tests) . " test(s) passed.\n";
