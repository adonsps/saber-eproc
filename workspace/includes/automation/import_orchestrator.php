<?php
declare(strict_types=1);

require_once __DIR__ . '/import_repository.php';

final class K2ImportNextAction
{
    public const AON = 'aon';
    public const PR = 'pr';
    public const ARCHIVE = 'archive';
    public const NONE = 'none';
}

final class K2ImportResponseCode
{
    public const BATCH_REGISTERED = 'BATCH_REGISTERED';
    public const BATCH_REPLAYED = 'BATCH_REPLAYED';
    public const BATCH_STATUS = 'BATCH_STATUS';
    public const BATCH_NOT_FOUND = 'BATCH_NOT_FOUND';
    public const REGISTRATION_CONFLICT = 'REGISTRATION_CONFLICT';
    public const LOCK_ACQUIRED = 'LOCK_ACQUIRED';
    public const LOCK_CONFLICT = 'LOCK_CONFLICT';
    public const LOCK_RELEASED = 'LOCK_RELEASED';
    public const LOCK_RELEASE_CONFLICT = 'LOCK_RELEASE_CONFLICT';
    public const LOCK_EXTENDED = 'LOCK_EXTENDED';
    public const OPERATION_STARTED = 'OPERATION_STARTED';
    public const REQUEST_IN_PROGRESS = 'REQUEST_IN_PROGRESS';
    public const REQUEST_REPLAYED = 'REQUEST_REPLAYED';
    public const OPERATION_SUCCEEDED = 'OPERATION_SUCCEEDED';
    public const OPERATION_FAILED_RETRYABLE = 'OPERATION_FAILED_RETRYABLE';
    public const OPERATION_FAILED_FINAL = 'OPERATION_FAILED_FINAL';
    public const ARCHIVE_READY = 'ARCHIVE_READY';
    public const INVALID_TRANSITION = 'INVALID_TRANSITION';
    public const INVALID_ATTEMPT_NUMBER = 'INVALID_ATTEMPT_NUMBER';
    public const FINAL_FAILURE = 'FINAL_FAILURE';
}

final class K2ImportOrchestrationException extends DomainException
{
    public function __construct(
        private readonly string $stableCode,
        string $message,
        private readonly bool $retryable = false,
        private readonly array $safeContext = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function stableCode(): string
    {
        return $this->stableCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function safeContext(): array
    {
        return $this->safeContext;
    }
}

final class K2ImportAdapterResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $counters = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $safeMessage = null,
        public readonly bool $retryable = false,
        public readonly array $result = []
    ) {
    }
}

final class K2ImportPreparedExecutionFailedException extends RuntimeException
{
    public function __construct(
        private readonly K2ImportAdapterResult $adapterResult,
        ?Throwable $previous = null
    ) {
        parent::__construct($adapterResult->safeMessage ?? 'Prepared import execution failed.', 0, $previous);
    }

    public function adapterResult(): K2ImportAdapterResult
    {
        return $this->adapterResult;
    }
}

interface K2AonImportExecutorInterface
{
    public function importAon(array $context): K2ImportAdapterResult;
}

interface K2PreparedAonImportExecutorInterface extends K2AonImportExecutorInterface
{
    public function prepareAon(array $context): K2AutomationPreparedParserImport;

    public function executePreparedAon(K2AutomationPreparedParserImport $prepared): K2ImportAdapterResult;
}

interface K2PrImportExecutorInterface
{
    public function importPr(array $context): K2ImportAdapterResult;
}

interface K2PreparedPrImportExecutorInterface extends K2PrImportExecutorInterface
{
    public function preparePr(array $context): K2AutomationPreparedParserImport;

    public function executePreparedPr(K2AutomationPreparedParserImport $prepared): K2ImportAdapterResult;
}

interface K2ArchiveResultHandlerInterface
{
    public function handleArchiveResult(array $context): K2ImportAdapterResult;
}

final class K2ImportResponseFactory
{
    public static function build(
        bool $success,
        string $code,
        string $message,
        ?string $requestId,
        ?string $batchUid,
        bool $replayed,
        ?string $state,
        ?string $archiveState,
        ?string $nextAction,
        bool $retryable,
        array $result = [],
        ?array $error = null
    ): array {
        return [
            'success' => $success,
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
            'batch_uid' => $batchUid,
            'replayed' => $replayed,
            'state' => $state,
            'archive_state' => $archiveState,
            'next_action' => $nextAction ?? K2ImportNextAction::NONE,
            'retryable' => $retryable,
            'current_attempt_number' => isset($result['current_attempt_number']) ? (int) $result['current_attempt_number'] : null,
            'next_attempt_number' => isset($result['next_attempt_number']) ? (int) $result['next_attempt_number'] : null,
            'active_request_id' => isset($result['active_request_id']) ? (string) $result['active_request_id'] : null,
            'operation_status' => isset($result['operation_status']) ? (string) $result['operation_status'] : null,
            'result' => self::sanitize($result),
            'error' => $error === null ? null : self::sanitize($error),
        ];
    }

    private static function sanitize(array $value): array
    {
        $safe = [];
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $safe[$key] = self::sanitize($item);
            } elseif (is_scalar($item) || $item === null) {
                $safe[$key] = $item;
            }
        }

        return $safe;
    }
}

final class K2ImportOrchestrator
{
    public function __construct(
        private readonly K2ImportRunRepositoryInterface $repository,
        private readonly int $lockSeconds = 900,
        private readonly mixed $lockTokenGenerator = null,
        private readonly int $staleOperationSeconds = 1800
    ) {
        if ($lockSeconds < 1) {
            throw new InvalidArgumentException('Lock duration must be at least one second.');
        }
        if ($staleOperationSeconds < 1) {
            throw new InvalidArgumentException('Stale operation timeout must be at least one second.');
        }
    }

    public function registerBatch(
        array $files,
        K2ImportScheduleMetadata $scheduleMetadata,
        ?string $requestId = null
    ): array {
        if ($scheduleMetadata->scheduledSlotAtUtc === null) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::REGISTRATION_CONFLICT,
                'Scheduled slot metadata is required.'
            );
        }

        $orderedFiles = K2ImportIdentity::normalizeSourceFiles($files);
        $batchUid = K2ImportIdentity::batchUid($orderedFiles);
        $manifestHash = K2ImportIdentity::sourceManifestHash($orderedFiles);

        return $this->repository->transactional(function () use (
            $batchUid,
            $manifestHash,
            $orderedFiles,
            $scheduleMetadata,
            $requestId
        ): array {
            $existing = $this->repository->findBatchByUid($batchUid);
            if ($existing !== null) {
                $this->assertExistingBatchMatches($existing, $orderedFiles, $scheduleMetadata, $manifestHash);

                return $this->responseForBatch(
                    $existing,
                    K2ImportResponseCode::BATCH_REPLAYED,
                    'Batch registration replayed.',
                    $requestId,
                    true,
                    ['batch_id' => (int) $existing['id']]
                );
            }

            $batchId = $this->repository->insertDetectedBatch(
                $batchUid,
                $manifestHash,
                $orderedFiles,
                $scheduleMetadata
            );
            $batch = $this->requireBatchById($batchId);

            return $this->responseForBatch(
                $batch,
                K2ImportResponseCode::BATCH_REGISTERED,
                'Batch registered.',
                $requestId,
                false,
                ['batch_id' => $batchId]
            );
        });
    }

    public function batchStatus(string $batchUid, ?string $requestId = null): array
    {
        $batch = $this->repository->findBatchByUid($batchUid);
        if ($batch === null) {
            return K2ImportResponseFactory::build(
                false,
                K2ImportResponseCode::BATCH_NOT_FOUND,
                'Batch was not found.',
                $requestId,
                $batchUid,
                false,
                null,
                null,
                K2ImportNextAction::NONE,
                false,
                [],
                ['code' => K2ImportResponseCode::BATCH_NOT_FOUND, 'message' => 'Batch was not found.']
            );
        }

        $recovered = $this->recoverStaleBatchIfNeeded($batch);
        if ($recovered !== null) {
            $batch = $recovered;
        }

        return $this->responseForBatch(
            $batch,
            K2ImportResponseCode::BATCH_STATUS,
            'Batch status returned.',
            $requestId,
            false
        );
    }

    public function recoverStaleBatchIfNeeded(array $batch): ?array
    {
        if (!isset($batch['id'])) {
            return null;
        }

        return $this->repository->recoverStaleProcessingBatch((int) $batch['id'], $this->staleOperationSeconds);
    }

    public function claimBatchLock(string $batchUid, ?string $ownerToken = null, ?string $requestId = null): array
    {
        $batch = $this->requireBatchByUid($batchUid);
        $token = $ownerToken ?? $this->generateLockToken();
        $claimed = $this->repository->claimBatchLock((int) $batch['id'], $token, $this->lockSeconds);
        $latest = $this->requireBatchByUid($batchUid);

        if (!$claimed) {
            return $this->responseForBatch(
                $latest,
                K2ImportResponseCode::LOCK_CONFLICT,
                'Batch lock is already held.',
                $requestId,
                false,
                [],
                false,
                ['code' => K2ImportResponseCode::LOCK_CONFLICT, 'message' => 'Batch lock is already held.'],
                true
            );
        }

        return $this->responseForBatch(
            $latest,
            K2ImportResponseCode::LOCK_ACQUIRED,
            'Batch lock acquired.',
            $requestId,
            false,
            ['lock_token' => $token],
            true
        );
    }

    public function extendBatchLock(string $batchUid, string $ownerToken, ?string $requestId = null): array
    {
        $batch = $this->requireBatchByUid($batchUid);
        $extended = $this->repository->extendBatchLock((int) $batch['id'], $ownerToken, $this->lockSeconds);
        $latest = $this->requireBatchByUid($batchUid);

        if (!$extended) {
            return $this->responseForBatch(
                $latest,
                K2ImportResponseCode::LOCK_CONFLICT,
                'Batch lock could not be extended by this owner.',
                $requestId,
                false,
                [],
                false,
                ['code' => K2ImportResponseCode::LOCK_CONFLICT, 'message' => 'Lock owner mismatch or expired lock.'],
                true
            );
        }

        return $this->responseForBatch(
            $latest,
            K2ImportResponseCode::LOCK_EXTENDED,
            'Batch lock extended.',
            $requestId,
            false,
            ['lock_token' => $ownerToken],
            true
        );
    }

    public function releaseBatchLock(string $batchUid, string $ownerToken, ?string $requestId = null): array
    {
        $batch = $this->requireBatchByUid($batchUid);
        $released = $this->repository->releaseBatchLock((int) $batch['id'], $ownerToken);
        $latest = $this->requireBatchByUid($batchUid);

        if (!$released) {
            return $this->responseForBatch(
                $latest,
                K2ImportResponseCode::LOCK_RELEASE_CONFLICT,
                'Batch lock could not be released by this owner.',
                $requestId,
                false,
                [],
                false,
                ['code' => K2ImportResponseCode::LOCK_RELEASE_CONFLICT, 'message' => 'Lock owner mismatch.']
            );
        }

        return $this->responseForBatch(
            $latest,
            K2ImportResponseCode::LOCK_RELEASED,
            'Batch lock released.',
            $requestId,
            false
        );
    }

    /**
     * Read-only pre-check for an already-queued/processing/completed request, keyed
     * by the same requestIdempotencyKey beginOperation() would use. Callers that
     * stage files before calling beginOperation() (Area E) can use this to short-circuit
     * a transport-retry-of-the-same-attempt BEFORE paying the staging I/O cost.
     * beginOperation() performs the authoritative (transactional) version of this same
     * check itself, so skipping this pre-check is never unsafe, only less efficient.
     */
    public function checkExistingOperationRequest(
        string $batchUid,
        string $operationType,
        string $requestIdempotencyKey,
        ?int $expectedAttemptNumber = null,
        ?string $requestId = null
    ): ?array {
        K2ImportOperationType::assertValid($operationType);

        $existingRequest = $this->repository->findOperationRequestByKey($requestIdempotencyKey);
        if ($existingRequest === null) {
            return null;
        }

        $batch = $this->requireBatchByUid($batchUid);
        $submittedAttempt = $expectedAttemptNumber ?? (int) $existingRequest['attempt_number'];
        $logicalRequestId = $requestId ?? $requestIdempotencyKey;

        return $this->existingRequestResponse(
            $existingRequest,
            $batch,
            $operationType,
            $requestIdempotencyKey,
            $logicalRequestId,
            $submittedAttempt,
            $requestId
        );
    }

    public function beginOperation(
        string $batchUid,
        string $operationType,
        string $requestIdempotencyKey,
        string $ownerToken,
        ?string $requestId = null,
        ?int $expectedAttemptNumber = null,
        ?array $stagedFileManifest = null
    ): array {
        K2ImportOperationType::assertValid($operationType);

        return $this->repository->transactional(function () use (
            $batchUid,
            $operationType,
            $requestIdempotencyKey,
            $ownerToken,
            $requestId,
            $expectedAttemptNumber,
            $stagedFileManifest
        ): array {
            $batch = $this->requireBatchByUid($batchUid);
            $batchId = (int) $batch['id'];
            $this->assertActiveLock($batchId, $ownerToken);
            $logicalRequestId = $requestId ?? $requestIdempotencyKey;

            $existingRequest = $this->repository->findOperationRequestByKey($requestIdempotencyKey);
            if ($existingRequest !== null) {
                $submittedAttempt = $expectedAttemptNumber ?? (int) $existingRequest['attempt_number'];

                return $this->existingRequestResponse(
                    $existingRequest,
                    $batch,
                    $operationType,
                    $requestIdempotencyKey,
                    $logicalRequestId,
                    $submittedAttempt,
                    $requestId
                );
            }

            $existingLogicalRequest = $this->repository->findOperationRequestByLogicalRequestId(
                $batchId,
                $operationType,
                $logicalRequestId
            );
            if ($existingLogicalRequest !== null) {
                throw new K2ImportDuplicateKeyConflictException(
                    'Logical request ID cannot be reused with a different attempt number.'
                );
            }

            $attemptNumber = $this->repository->nextAttemptNumber($batchId, $operationType);
            if ($expectedAttemptNumber !== null && $expectedAttemptNumber !== $attemptNumber) {
                return $this->responseForBatch(
                    $batch,
                    K2ImportResponseCode::INVALID_ATTEMPT_NUMBER,
                    'Submitted attempt number does not match the expected next attempt.',
                    $requestId,
                    false,
                    ['operation_type' => $operationType, 'expected_attempt_number' => $attemptNumber],
                    false,
                    [
                        'code' => K2ImportResponseCode::INVALID_ATTEMPT_NUMBER,
                        'message' => 'Submitted attempt number does not match the expected next attempt.',
                    ]
                );
            }

            if (!K2ImportStateMachine::canStartOperation(
                $operationType,
                (string) $batch['state'],
                (string) $batch['archive_state']
            )) {
                return $this->responseForBatch(
                    $batch,
                    K2ImportResponseCode::INVALID_TRANSITION,
                    'Operation is not runnable for the current batch state.',
                    $requestId,
                    false,
                    ['operation_type' => $operationType],
                    false,
                    ['code' => K2ImportResponseCode::INVALID_TRANSITION, 'message' => 'Operation is not runnable.']
                );
            }

            [$targetState, $targetArchiveState] = $this->operationStartTarget($operationType, $batch);
            if (!$this->repository->transitionBatchState(
                $batchId,
                (string) $batch['state'],
                (string) $batch['archive_state'],
                $targetState,
                $targetArchiveState
            )) {
                throw new K2ImportOrchestrationException(
                    K2ImportResponseCode::INVALID_TRANSITION,
                    'Batch state changed before operation could start.',
                    true
                );
            }

            $requestRowId = $this->repository->upsertOperationRequest(
                $batchId,
                $operationType,
                $requestIdempotencyKey,
                $logicalRequestId,
                $attemptNumber
            );
            $this->repository->markOperationRequestRunning($requestRowId);

            $files = $this->sourceMetadataForBatch($batchId);
            $schedule = $this->scheduleMetadataFromBatch($batch);
            $attemptKey = K2ImportIdentity::attemptIdempotencyKey(
                $operationType,
                $files,
                $attemptNumber,
                $schedule
            );
            $attemptId = $this->repository->recordOperationAttemptStarted(
                $batchId,
                $requestRowId,
                $operationType,
                $attemptNumber,
                $attemptKey
            );
            if ($stagedFileManifest !== null) {
                $this->repository->attachStagedFiles($attemptId, $stagedFileManifest);
            }
            $latest = $this->requireBatchById($batchId);

            return $this->responseForBatch(
                $latest,
                K2ImportResponseCode::OPERATION_STARTED,
                'Operation started.',
                $requestId ?? $requestIdempotencyKey,
                false,
                [
                    'operation_type' => $operationType,
                    'request_row_id' => $requestRowId,
                    'attempt_id' => $attemptId,
                    'attempt_number' => $attemptNumber,
                    'attempt_idempotency_key' => $attemptKey,
                    'active_request_id' => $logicalRequestId,
                    'operation_status' => K2ImportRequestStatus::RUNNING,
                ],
                true
            );
        });
    }

    public function recordOperationSuccess(
        string $batchUid,
        string $operationType,
        string $requestIdempotencyKey,
        int $attemptId,
        array $counters,
        string $ownerToken,
        ?string $requestId = null
    ): array {
        K2ImportOperationType::assertValid($operationType);
        $normalizedCounters = self::normalizeCounters($counters);

        return $this->repository->transactional(function () use (
            $batchUid,
            $operationType,
            $requestIdempotencyKey,
            $attemptId,
            $normalizedCounters,
            $ownerToken,
            $requestId
        ): array {
            $batch = $this->requireBatchByUid($batchUid);
            $batchId = (int) $batch['id'];
            $this->assertActiveLock($batchId, $ownerToken);
            $request = $this->requireRequest($requestIdempotencyKey, $batchId, $operationType);

            [$toState, $toArchiveState] = $this->operationSuccessTarget($operationType, $batch);
            $this->repository->markOperationAttemptSucceeded($attemptId, $normalizedCounters);
            if (!$this->repository->transitionBatchState(
                $batchId,
                (string) $batch['state'],
                (string) $batch['archive_state'],
                $toState,
                $toArchiveState
            )) {
                throw new K2ImportOrchestrationException(
                    K2ImportResponseCode::INVALID_TRANSITION,
                    'Batch state changed before success could be recorded.',
                    true
                );
            }

            $latest = $this->requireBatchById($batchId);
            $this->repository->markOperationRequestCompleted(
                (int) $request['id'],
                K2ImportRequestStatus::SUCCEEDED,
                (string) $latest['state'],
                (string) $latest['archive_state'],
                200,
                null
            );

            return $this->responseForBatch(
                $latest,
                K2ImportResponseCode::OPERATION_SUCCEEDED,
                'Operation succeeded.',
                $requestId ?? $requestIdempotencyKey,
                false,
                [
                    'operation_type' => $operationType,
                    'counters' => $normalizedCounters,
                    'current_attempt_number' => (int) ($request['attempt_number'] ?? 0),
                    'active_request_id' => (string) ($request['logical_request_id'] ?? ''),
                    'operation_status' => K2ImportRequestStatus::SUCCEEDED,
                ],
                true
            );
        });
    }

    public function recordOperationSuccessWithExecution(
        string $batchUid,
        string $operationType,
        string $requestIdempotencyKey,
        int $attemptId,
        callable $executePrepared,
        string $ownerToken,
        ?string $requestId = null
    ): array {
        K2ImportOperationType::assertValid($operationType);

        return $this->repository->transactional(function () use (
            $batchUid,
            $operationType,
            $requestIdempotencyKey,
            $attemptId,
            $executePrepared,
            $ownerToken,
            $requestId
        ): array {
            $batch = $this->requireBatchByUid($batchUid);
            $batchId = (int) $batch['id'];
            $this->assertActiveLock($batchId, $ownerToken);
            $request = $this->requireRequest($requestIdempotencyKey, $batchId, $operationType);

            try {
                $adapterResult = $executePrepared();
            } catch (K2ImportPreparedExecutionFailedException $e) {
                throw $e;
            } catch (Throwable $e) {
                throw new K2ImportPreparedExecutionFailedException(
                    new K2ImportAdapterResult(
                        false,
                        [],
                        'AUTOMATION_PARSER_FAILED',
                        'Automation parser execution failed.',
                        true
                    ),
                    $e
                );
            }

            if (!$adapterResult instanceof K2ImportAdapterResult || !$adapterResult->success) {
                throw new K2ImportPreparedExecutionFailedException(
                    $adapterResult instanceof K2ImportAdapterResult
                        ? $adapterResult
                        : new K2ImportAdapterResult(false, [], 'AUTOMATION_PARSER_FAILED', 'Automation parser execution failed.', true)
                );
            }

            $normalizedCounters = self::normalizeCounters($adapterResult->counters);
            [$toState, $toArchiveState] = $this->operationSuccessTarget($operationType, $batch);
            $this->repository->markOperationAttemptSucceeded($attemptId, $normalizedCounters);
            if (!$this->repository->transitionBatchState(
                $batchId,
                (string) $batch['state'],
                (string) $batch['archive_state'],
                $toState,
                $toArchiveState
            )) {
                throw new K2ImportOrchestrationException(
                    K2ImportResponseCode::INVALID_TRANSITION,
                    'Batch state changed before success could be recorded.',
                    true
                );
            }

            $latest = $this->requireBatchById($batchId);
            $this->repository->markOperationRequestCompleted(
                (int) $request['id'],
                K2ImportRequestStatus::SUCCEEDED,
                (string) $latest['state'],
                (string) $latest['archive_state'],
                200,
                null
            );

            return $this->responseForBatch(
                $latest,
                K2ImportResponseCode::OPERATION_SUCCEEDED,
                'Operation succeeded.',
                $requestId ?? $requestIdempotencyKey,
                false,
                [
                    'operation_type' => $operationType,
                    'counters' => $normalizedCounters,
                    'current_attempt_number' => (int) ($request['attempt_number'] ?? 0),
                    'active_request_id' => (string) ($request['logical_request_id'] ?? ''),
                    'operation_status' => K2ImportRequestStatus::SUCCEEDED,
                    'adapter_result' => $adapterResult->result,
                ],
                true
            );
        });
    }

    public function recordOperationFailure(
        string $batchUid,
        string $operationType,
        string $requestIdempotencyKey,
        int $attemptId,
        string $errorCode,
        string $safeMessage,
        bool $retryable,
        string $ownerToken,
        ?string $requestId = null
    ): array {
        K2ImportOperationType::assertValid($operationType);
        $safeErrorCode = $this->safeToken($errorCode, 'K2_OPERATION_FAILED');
        $safeErrorMessage = $this->safeMessage($safeMessage);

        return $this->repository->transactional(function () use (
            $batchUid,
            $operationType,
            $requestIdempotencyKey,
            $attemptId,
            $safeErrorCode,
            $safeErrorMessage,
            $retryable,
            $ownerToken,
            $requestId
        ): array {
            $batch = $this->requireBatchByUid($batchUid);
            $batchId = (int) $batch['id'];
            $this->assertActiveLock($batchId, $ownerToken);
            $request = $this->requireRequest($requestIdempotencyKey, $batchId, $operationType);

            [$toState, $toArchiveState] = $this->operationFailureTarget($operationType, $batch, $retryable);
            $this->repository->markOperationAttemptFailed($attemptId, $safeErrorCode, $safeErrorMessage, $retryable);
            if (!$this->repository->transitionBatchState(
                $batchId,
                (string) $batch['state'],
                (string) $batch['archive_state'],
                $toState,
                $toArchiveState
            )) {
                throw new K2ImportOrchestrationException(
                    K2ImportResponseCode::INVALID_TRANSITION,
                    'Batch state changed before failure could be recorded.',
                    true
                );
            }

            $latest = $this->requireBatchById($batchId);
            $responseCode = $retryable
                ? K2ImportResponseCode::OPERATION_FAILED_RETRYABLE
                : K2ImportResponseCode::OPERATION_FAILED_FINAL;
            $this->repository->markOperationRequestCompleted(
                (int) $request['id'],
                $retryable ? K2ImportRequestStatus::FAILED_RETRYABLE : K2ImportRequestStatus::FAILED_FINAL,
                (string) $latest['state'],
                (string) $latest['archive_state'],
                $retryable ? 503 : 422,
                null
            );

            return $this->responseForBatch(
                $latest,
                $responseCode,
                $retryable ? 'Operation failed with a retryable error.' : 'Operation failed permanently.',
                $requestId ?? $requestIdempotencyKey,
                false,
                ['operation_type' => $operationType],
                false,
                ['code' => $safeErrorCode, 'message' => $safeErrorMessage],
                $retryable
            );
        });
    }

    public function markArchivePending(string $batchUid, string $ownerToken, ?string $requestId = null): array
    {
        return $this->repository->transactional(function () use ($batchUid, $ownerToken, $requestId): array {
            $batch = $this->requireBatchByUid($batchUid);
            $batchId = (int) $batch['id'];
            $this->assertActiveLock($batchId, $ownerToken);

            if ((string) $batch['state'] === K2ImportBatchState::ARCHIVE_PENDING) {
                return $this->responseForBatch(
                    $batch,
                    K2ImportResponseCode::ARCHIVE_READY,
                    'Batch is already archive-ready.',
                    $requestId,
                    true
                );
            }

            if (!$this->repository->transitionBatchState(
                $batchId,
                K2ImportBatchState::IMPORT_SUCCESS,
                K2ImportArchiveState::NOT_READY,
                K2ImportBatchState::ARCHIVE_PENDING,
                K2ImportArchiveState::PENDING
            )) {
                throw new K2ImportOrchestrationException(
                    K2ImportResponseCode::INVALID_TRANSITION,
                    'Batch is not ready to move to archive pending.',
                    false
                );
            }

            return $this->responseForBatch(
                $this->requireBatchById($batchId),
                K2ImportResponseCode::ARCHIVE_READY,
                'Batch is archive-ready.',
                $requestId,
                false
            );
        });
    }

    /**
     * Claims one queued AON or PR attempt for the CLI worker (workspace/cron/
     * process_k2_import_queue.php), atomically moving the batch from its QUEUED
     * state to the matching PROCESSING state. Returns null when nothing is claimable.
     */
    public function claimNextForProcessing(
        string $workerToken,
        string $workerHostname,
        int $workerPid,
        int $leaseSeconds
    ): ?array {
        return $this->repository->claimNextQueuedAttempt($workerToken, $workerHostname, $workerPid, $leaseSeconds);
    }

    public function extendWorkerLease(int $attemptId, string $workerToken): bool
    {
        return $this->repository->extendWorkerLease($attemptId, $workerToken);
    }

    public function releaseWorkerClaim(int $attemptId, string $workerToken): bool
    {
        return $this->repository->releaseWorkerClaim($attemptId, $workerToken);
    }

    private function existingRequestResponse(
        array $existingRequest,
        array $batch,
        string $operationType,
        string $requestIdempotencyKey,
        string $logicalRequestId,
        int $submittedAttempt,
        ?string $requestId
    ): array {
        K2ImportRepositoryGuards::assertOperationRequestMatchesSubmitted(
            $existingRequest,
            (int) $batch['id'],
            $operationType,
            $requestIdempotencyKey,
            $logicalRequestId,
            $submittedAttempt
        );

        if ($this->isCompletedRequestStatus((string) $existingRequest['request_status'])) {
            return $this->responseForCompletedRequest($existingRequest, (string) $batch['batch_uid'], $requestId);
        }

        $runningAttempt = $this->repository->findOperationAttemptByRequestId((int) $existingRequest['id']);

        return $this->responseForBatch(
            $batch,
            K2ImportResponseCode::REQUEST_IN_PROGRESS,
            'Request is already in progress.',
            $requestId,
            true,
            [
                'operation_type' => $operationType,
                'request_row_id' => (int) $existingRequest['id'],
                'attempt_id' => $runningAttempt === null ? null : (int) $runningAttempt['id'],
                'attempt_number' => (int) ($existingRequest['attempt_number'] ?? 0),
                'current_attempt_number' => (int) ($existingRequest['attempt_number'] ?? 0),
                'active_request_id' => (string) ($existingRequest['logical_request_id'] ?? ''),
                'operation_status' => (string) ($existingRequest['request_status'] ?? ''),
            ],
            false,
            ['code' => K2ImportResponseCode::REQUEST_IN_PROGRESS, 'message' => 'Request is already in progress.'],
            true
        );
    }

    public static function normalizeCounters(array $counters): array
    {
        $normalized = [];
        foreach (['inserted_count', 'updated_count', 'skipped_count', 'warning_count'] as $key) {
            $value = $counters[$key] ?? 0;
            if (!is_int($value) || $value < 0) {
                throw new InvalidArgumentException("Counter {$key} must be a non-negative integer.");
            }
            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public static function nextActionForState(string $batchState, string $archiveState): string
    {
        if ($batchState === K2ImportBatchState::IMPORT_SUCCESS && $archiveState === K2ImportArchiveState::NOT_READY) {
            return K2ImportNextAction::ARCHIVE;
        }

        return K2ImportStateMachine::nextRunnableOperation($batchState, $archiveState) ?? K2ImportNextAction::NONE;
    }

    private function responseForBatch(
        array $batch,
        string $code,
        string $message,
        ?string $requestId,
        bool $replayed,
        array $result = [],
        bool $success = true,
        ?array $error = null,
        ?bool $retryable = null
    ): array {
        $result = array_merge(
            isset($batch['id']) ? ['registered_manifest' => $this->registeredManifestForBatch((int) $batch['id'])] : [],
            $this->operationProgressForBatch($batch),
            $result
        );

        return K2ImportResponseFactory::build(
            $success,
            $code,
            $message,
            $requestId,
            (string) $batch['batch_uid'],
            $replayed,
            (string) $batch['state'],
            (string) $batch['archive_state'],
            self::nextActionForState((string) $batch['state'], (string) $batch['archive_state']),
            $retryable ?? ($error !== null && $code !== K2ImportResponseCode::INVALID_TRANSITION),
            $result,
            $error
        );
    }

    private function responseForCompletedRequest(array $request, string $batchUid, ?string $requestId): array
    {
        $status = (string) $request['request_status'];
        $success = $status === K2ImportRequestStatus::SUCCEEDED || $status === K2ImportRequestStatus::REPLAYED;
        $state = $request['response_batch_state'] === null ? null : (string) $request['response_batch_state'];
        $archiveState = $request['response_archive_state'] === null ? null : (string) $request['response_archive_state'];
        $retryable = $status === K2ImportRequestStatus::FAILED_RETRYABLE;
        $attemptNumber = (int) ($request['attempt_number'] ?? 0);

        return K2ImportResponseFactory::build(
            $success,
            K2ImportResponseCode::REQUEST_REPLAYED,
            'Completed request result replayed.',
            $requestId ?? (string) $request['idempotency_key'],
            $batchUid,
            true,
            $state,
            $archiveState,
            $state !== null && $archiveState !== null
                ? self::nextActionForState($state, $archiveState)
                : K2ImportNextAction::NONE,
            $retryable,
            [
                'operation_type' => (string) $request['operation_type'],
                'current_attempt_number' => $attemptNumber > 0 ? $attemptNumber : null,
                'next_attempt_number' => $retryable && $attemptNumber > 0 ? $attemptNumber + 1 : null,
                'active_request_id' => (string) ($request['logical_request_id'] ?? ''),
                'operation_status' => $status,
            ],
            $success ? null : ['code' => $status, 'message' => 'Completed request previously failed.']
        );
    }

    private function operationProgressForBatch(array $batch): array
    {
        $operationType = $this->operationTypeForProgress(
            (string) $batch['state'],
            (string) $batch['archive_state']
        );
        if ($operationType === null || !isset($batch['id'])) {
            return [];
        }

        $batchId = (int) $batch['id'];
        $nextAttempt = $this->repository->nextAttemptNumber($batchId, $operationType);
        $latestRequest = $this->repository->latestOperationRequestForOperation($batchId, $operationType);
        $currentAttempt = $latestRequest === null ? null : (int) ($latestRequest['attempt_number'] ?? 0);

        return [
            'current_attempt_number' => $currentAttempt !== null && $currentAttempt > 0 ? $currentAttempt : null,
            'next_attempt_number' => $nextAttempt,
            'active_request_id' => $latestRequest === null ? null : (string) ($latestRequest['logical_request_id'] ?? ''),
            'operation_status' => $latestRequest === null ? null : (string) ($latestRequest['request_status'] ?? ''),
        ];
    }

    private function operationTypeForProgress(string $batchState, string $archiveState): ?string
    {
        if ($batchState === K2ImportBatchState::AON_QUEUED
            || $batchState === K2ImportBatchState::AON_PROCESSING
            || $batchState === K2ImportBatchState::AON_FAILED_RETRYABLE
            || $batchState === K2ImportBatchState::DETECTED
        ) {
            return K2ImportOperationType::AON;
        }

        if ($batchState === K2ImportBatchState::PR_QUEUED
            || $batchState === K2ImportBatchState::PR_PROCESSING
            || $batchState === K2ImportBatchState::PR_FAILED_RETRYABLE
            || $batchState === K2ImportBatchState::AON_SUCCESS
        ) {
            return K2ImportOperationType::PR;
        }

        if ($batchState === K2ImportBatchState::ARCHIVE_PENDING
            && in_array($archiveState, [
                K2ImportArchiveState::PENDING,
                K2ImportArchiveState::PROCESSING,
                K2ImportArchiveState::FAILED_RETRYABLE,
            ], true)
        ) {
            return K2ImportOperationType::ARCHIVE;
        }

        return null;
    }

    private function registeredManifestForBatch(int $batchId): array
    {
        return array_values(array_map(
            static fn(K2ImportSourceFileMetadata $file): array => $file->canonicalArray(),
            $this->sourceMetadataForBatch($batchId)
        ));
    }

    private function assertExistingBatchMatches(
        array $batch,
        array $files,
        K2ImportScheduleMetadata $scheduleMetadata,
        string $manifestHash
    ): void {
        if ((string) $batch['source_manifest_hash'] !== $manifestHash) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::REGISTRATION_CONFLICT,
                'Existing batch manifest hash does not match submitted files.'
            );
        }

        if ($this->normalizeSqlDateTime((string) ($batch['scheduled_slot_at_utc'] ?? ''))
            !== (string) $scheduleMetadata->scheduledSlotForSql()
            || (string) ($batch['schedule_timezone'] ?? '') !== (string) $scheduleMetadata->scheduleTimezone
        ) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::REGISTRATION_CONFLICT,
                'Existing batch scheduled-slot metadata does not match submitted metadata.'
            );
        }

        $storedFiles = $this->repository->findSourceFilesForBatch((int) $batch['id']);
        if (count($storedFiles) !== 3) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::REGISTRATION_CONFLICT,
                'Existing batch does not have exactly three source files.'
            );
        }

        $byType = [];
        foreach ($storedFiles as $storedFile) {
            $byType[(string) $storedFile['dataset_type']] = $storedFile;
        }

        foreach ($files as $file) {
            if (!isset($byType[$file->datasetType])) {
                throw new K2ImportOrchestrationException(
                    K2ImportResponseCode::REGISTRATION_CONFLICT,
                    'Existing batch source-file links do not match submitted metadata.'
                );
            }

            K2ImportRepositoryGuards::assertSourceFileMatchesSubmittedMetadata($byType[$file->datasetType], $file);
        }
    }

    private function operationStartTarget(string $operationType, array $batch): array
    {
        return match ($operationType) {
            K2ImportOperationType::AON => [K2ImportBatchState::AON_QUEUED, K2ImportArchiveState::NOT_READY],
            K2ImportOperationType::PR => [K2ImportBatchState::PR_QUEUED, K2ImportArchiveState::NOT_READY],
            K2ImportOperationType::ARCHIVE => [K2ImportBatchState::ARCHIVE_PENDING, K2ImportArchiveState::PROCESSING],
            default => [(string) $batch['state'], (string) $batch['archive_state']],
        };
    }

    private function operationSuccessTarget(string $operationType, array $batch): array
    {
        return match ($operationType) {
            K2ImportOperationType::AON => [K2ImportBatchState::AON_SUCCESS, K2ImportArchiveState::NOT_READY],
            K2ImportOperationType::PR => [K2ImportBatchState::IMPORT_SUCCESS, K2ImportArchiveState::NOT_READY],
            K2ImportOperationType::ARCHIVE => [K2ImportBatchState::COMPLETED, K2ImportArchiveState::COMPLETED],
            default => [(string) $batch['state'], (string) $batch['archive_state']],
        };
    }

    private function operationFailureTarget(string $operationType, array $batch, bool $retryable): array
    {
        if ($operationType === K2ImportOperationType::ARCHIVE) {
            return [
                K2ImportBatchState::ARCHIVE_PENDING,
                K2ImportStateMachine::archiveFailureStateFor($retryable),
            ];
        }

        return [
            K2ImportStateMachine::failureStateFor($operationType, $retryable),
            (string) $batch['archive_state'],
        ];
    }

    private function requireBatchByUid(string $batchUid): array
    {
        $batch = $this->repository->findBatchByUid($batchUid);
        if ($batch === null) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::BATCH_NOT_FOUND,
                'Batch was not found.'
            );
        }

        return $batch;
    }

    private function requireBatchById(int $batchId): array
    {
        $batch = $this->repository->findBatchById($batchId);
        if ($batch === null) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::BATCH_NOT_FOUND,
                'Batch was not found.'
            );
        }

        return $batch;
    }

    private function requireRequest(string $requestIdempotencyKey, int $batchId, string $operationType): array
    {
        $request = $this->repository->findOperationRequestByKey($requestIdempotencyKey);
        if ($request === null) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::INVALID_TRANSITION,
                'Operation request was not found.'
            );
        }

        K2ImportRepositoryGuards::assertOperationRequestMatchesSubmitted(
            $request,
            $batchId,
            $operationType,
            $requestIdempotencyKey
        );

        return $request;
    }

    private function assertActiveLock(int $batchId, string $ownerToken): void
    {
        if (!$this->repository->hasActiveBatchLock($batchId, $ownerToken)) {
            throw new K2ImportOrchestrationException(
                K2ImportResponseCode::LOCK_CONFLICT,
                'A valid owner lock is required.',
                true
            );
        }
    }

    private function sourceMetadataForBatch(int $batchId): array
    {
        $files = [];
        foreach ($this->repository->findSourceFilesForBatch($batchId) as $row) {
            $files[] = new K2ImportSourceFileMetadata(
                (string) $row['dataset_type'],
                (string) $row['drive_file_id'],
                (string) $row['original_filename'],
                new DateTimeImmutable((string) $row['drive_modified_at'], new DateTimeZone('UTC')),
                (int) $row['file_size_bytes'],
                (string) $row['sha256_content_hash']
            );
        }

        return K2ImportIdentity::normalizeSourceFiles($files);
    }

    private function scheduleMetadataFromBatch(array $batch): ?K2ImportScheduleMetadata
    {
        if (($batch['scheduled_slot_at_utc'] ?? null) === null) {
            return null;
        }

        return new K2ImportScheduleMetadata(
            new DateTimeImmutable((string) $batch['scheduled_slot_at_utc'], new DateTimeZone('UTC')),
            (string) $batch['schedule_timezone']
        );
    }

    private function isCompletedRequestStatus(string $status): bool
    {
        return in_array($status, [
            K2ImportRequestStatus::SUCCEEDED,
            K2ImportRequestStatus::FAILED_RETRYABLE,
            K2ImportRequestStatus::FAILED_FINAL,
            K2ImportRequestStatus::REPLAYED,
        ], true);
    }

    private function generateLockToken(): string
    {
        if (is_callable($this->lockTokenGenerator)) {
            $token = (string) call_user_func($this->lockTokenGenerator);
            if ($token !== '') {
                return $token;
            }
        }

        return 'k2lock_' . bin2hex(random_bytes(16));
    }

    private function safeToken(string $value, string $fallback): string
    {
        $value = strtoupper(trim($value));
        $value = preg_replace('/[^A-Z0-9_:-]/', '_', $value) ?? '';

        return $value === '' ? $fallback : substr($value, 0, 100);
    }

    private function safeMessage(string $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return substr($value === '' ? 'Operation failed.' : $value, 0, 500);
    }

    private function normalizeSqlDateTime(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (!str_contains($value, '.')) {
            return $value . '.000000';
        }

        [$prefix, $microseconds] = explode('.', $value, 2);

        return $prefix . '.' . str_pad(substr($microseconds, 0, 6), 6, '0');
    }
}
