<?php
declare(strict_types=1);

require_once __DIR__ . '/import_identity.php';

final class K2ImportDuplicateKeyConflictException extends DomainException
{
}

final class K2ImportRepositoryStateConflictException extends DomainException
{
}

interface K2ImportRunRepositoryInterface
{
    public function transactional(callable $callback): mixed;

    public function findBatchByUid(string $batchUid): ?array;

    public function findBatchById(int $batchId): ?array;

    public function insertDetectedBatch(
        string $batchUid,
        string $sourceManifestHash,
        array $files,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): int;

    public function findSourceFilesForBatch(int $batchId): array;

    public function findOperationRequestByKey(string $requestIdempotencyKey): ?array;

    public function findOperationRequestByLogicalRequestId(
        int $batchId,
        string $operationType,
        string $logicalRequestId
    ): ?array;

    public function latestOperationRequestForOperation(int $batchId, string $operationType): ?array;

    public function findOperationAttemptByRequestId(int $operationRequestId): ?array;

    public function upsertOperationRequest(
        int $batchId,
        string $operationType,
        string $requestIdempotencyKey,
        string $logicalRequestId,
        int $attemptNumber
    ): int;

    public function markOperationRequestRunning(int $requestId): void;

    public function markOperationRequestCompleted(
        int $requestId,
        string $requestStatus,
        string $batchState,
        string $archiveState,
        int $httpStatus,
        ?string $responseBodyHash
    ): void;

    public function recordOperationAttemptStarted(
        int $batchId,
        ?int $operationRequestId,
        string $operationType,
        int $attemptNumber,
        string $attemptIdempotencyKey
    ): int;

    public function nextAttemptNumber(int $batchId, string $operationType): int;

    public function markOperationAttemptSucceeded(int $attemptId, array $counters): void;

    public function markOperationAttemptFailed(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        bool $retryable
    ): void;

    public function transitionBatchState(
        int $batchId,
        string $fromState,
        string $fromArchiveState,
        string $toState,
        string $toArchiveState
    ): bool;

    public function claimBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool;

    public function extendBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool;

    public function releaseBatchLock(int $batchId, string $ownerToken): bool;

    public function hasActiveBatchLock(int $batchId, string $ownerToken): bool;

    public function recoverStaleProcessingBatch(int $batchId, int $staleSeconds): ?array;

    public function attachStagedFiles(int $attemptId, array $stagedFileManifest): void;

    public function findAttemptById(int $attemptId): ?array;

    public function claimNextQueuedAttempt(
        string $workerToken,
        string $workerHostname,
        int $workerPid,
        int $leaseSeconds
    ): ?array;

    public function extendWorkerLease(int $attemptId, string $workerToken): bool;

    public function releaseWorkerClaim(int $attemptId, string $workerToken): bool;
}

final class K2ImportRepositoryGuards
{
    public static function assertSourceFileMatchesSubmittedMetadata(
        array $stored,
        K2ImportSourceFileMetadata $submitted
    ): void {
        $expected = [
            'dataset_type' => $submitted->datasetType,
            'drive_file_id' => $submitted->driveFileId,
            'drive_modified_at' => $submitted->modifiedTimestampForSql(),
            'original_filename' => $submitted->originalFilename,
            'file_size_bytes' => (string) $submitted->fileSizeBytes,
            'sha256_content_hash' => $submitted->sha256ContentHash,
            'file_identity_hash' => K2ImportIdentity::fileIdentityHash($submitted),
            'content_identity_hash' => K2ImportIdentity::contentIdentityHash($submitted),
        ];

        $actual = [
            'dataset_type' => (string) ($stored['dataset_type'] ?? ''),
            'drive_file_id' => (string) ($stored['drive_file_id'] ?? ''),
            'drive_modified_at' => self::normalizeSqlDateTime((string) ($stored['drive_modified_at'] ?? '')),
            'original_filename' => (string) ($stored['original_filename'] ?? ''),
            'file_size_bytes' => (string) ($stored['file_size_bytes'] ?? ''),
            'sha256_content_hash' => (string) ($stored['sha256_content_hash'] ?? ''),
            'file_identity_hash' => (string) ($stored['file_identity_hash'] ?? ''),
            'content_identity_hash' => (string) ($stored['content_identity_hash'] ?? ''),
        ];

        foreach ($expected as $field => $expectedValue) {
            if ($actual[$field] !== $expectedValue) {
                throw new K2ImportDuplicateKeyConflictException(
                    "Conflicting K2 source-file duplicate for {$submitted->datasetType}: {$field} mismatch."
                );
            }
        }
    }

    public static function assertOperationRequestMatchesSubmitted(
        array $stored,
        int $batchId,
        string $operationType,
        string $requestIdempotencyKey,
        ?string $logicalRequestId = null,
        ?int $attemptNumber = null
    ): void {
        K2ImportOperationType::assertValid($operationType);

        if ((int) ($stored['batch_id'] ?? 0) !== $batchId
            || (string) ($stored['operation_type'] ?? '') !== $operationType
            || (string) ($stored['idempotency_key'] ?? '') !== $requestIdempotencyKey
            || ($logicalRequestId !== null && (string) ($stored['logical_request_id'] ?? '') !== $logicalRequestId)
            || ($attemptNumber !== null && (int) ($stored['attempt_number'] ?? 0) !== $attemptNumber)
        ) {
            throw new K2ImportDuplicateKeyConflictException(
                'Conflicting K2 operation-request idempotency key reuse.'
            );
        }
    }

    private static function normalizeSqlDateTime(string $value): string
    {
        $value = trim($value);
        if (preg_match('/\A\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?\z/', $value) === 1) {
            if (!str_contains($value, '.')) {
                return $value . '.000000';
            }

            [$prefix, $microseconds] = explode('.', $value, 2);

            return $prefix . '.' . str_pad(substr($microseconds, 0, 6), 6, '0');
        }

        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        } catch (Throwable) {
            return $value;
        }
    }
}

final class PdoK2ImportRunRepository implements K2ImportRunRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function transactional(callable $callback): mixed
    {
        if ($this->pdo->inTransaction()) {
            return $callback($this);
        }

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function findBatchByUid(string $batchUid): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_import_batches WHERE batch_uid = ? LIMIT 1'
        );
        $stmt->execute([$batchUid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findBatchById(int $batchId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_import_batches WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$batchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function insertDetectedBatch(
        string $batchUid,
        string $sourceManifestHash,
        array $files,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): int
    {
        $orderedFiles = K2ImportIdentity::normalizeSourceFiles($files);
        $scheduleMetadata ??= new K2ImportScheduleMetadata();

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $batchStmt = $this->pdo->prepare(
                'INSERT INTO k2_automation_import_batches ' .
                '(batch_uid, source_manifest_hash, scheduled_slot_at_utc, schedule_timezone, state, archive_state) ' .
                'VALUES (?, ?, ?, ?, ?, ?)'
            );
            $batchStmt->execute([
                $batchUid,
                $sourceManifestHash,
                $scheduleMetadata->scheduledSlotForSql(),
                $scheduleMetadata->scheduleTimezone,
                K2ImportBatchState::DETECTED,
                K2ImportArchiveState::NOT_READY,
            ]);

            $batchId = (int) $this->pdo->lastInsertId();
            $sourceFileStmt = $this->pdo->prepare(
                'INSERT INTO k2_automation_source_files ' .
                '(dataset_type, drive_file_id, original_filename, drive_modified_at, file_size_bytes, ' .
                'sha256_content_hash, file_identity_hash, content_identity_hash) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?, ?) ' .
                'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
            );
            $batchFileStmt = $this->pdo->prepare(
                'INSERT INTO k2_automation_import_batch_files ' .
                '(batch_id, dataset_type, source_file_id) VALUES (?, ?, ?)'
            );

            foreach ($orderedFiles as $file) {
                $sourceFileStmt->execute([
                    $file->datasetType,
                    $file->driveFileId,
                    $file->originalFilename,
                    $file->modifiedTimestampForSql(),
                    $file->fileSizeBytes,
                    $file->sha256ContentHash,
                    K2ImportIdentity::fileIdentityHash($file),
                    K2ImportIdentity::contentIdentityHash($file),
                ]);

                $sourceFileId = (int) $this->pdo->lastInsertId();
                $storedSourceFile = $this->findSourceFileById($sourceFileId);
                if ($storedSourceFile === null) {
                    throw new K2ImportRepositoryStateConflictException(
                        "Could not verify K2 source-file row {$sourceFileId}."
                    );
                }

                K2ImportRepositoryGuards::assertSourceFileMatchesSubmittedMetadata($storedSourceFile, $file);

                $batchFileStmt->execute([
                    $batchId,
                    $file->datasetType,
                    $sourceFileId,
                ]);
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $batchId;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }
    }

    public function findSourceFilesForBatch(int $batchId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sf.* ' .
            'FROM k2_automation_import_batch_files bf ' .
            'JOIN k2_automation_source_files sf ' .
            '  ON sf.id = bf.source_file_id AND sf.dataset_type = bf.dataset_type ' .
            'WHERE bf.batch_id = ? ' .
            'ORDER BY FIELD(bf.dataset_type, ?, ?, ?)'
        );
        $stmt->execute([
            $batchId,
            K2ImportDatasetType::PR,
            K2ImportDatasetType::CATALOG,
            K2ImportDatasetType::PRICE,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findOperationRequestByKey(string $requestIdempotencyKey): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_operation_requests WHERE idempotency_key = ? LIMIT 1'
        );
        $stmt->execute([$requestIdempotencyKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findOperationRequestByLogicalRequestId(
        int $batchId,
        string $operationType,
        string $logicalRequestId
    ): ?array {
        K2ImportOperationType::assertValid($operationType);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_operation_requests ' .
            'WHERE batch_id = ? AND operation_type = ? AND logical_request_id = ? LIMIT 1'
        );
        $stmt->execute([$batchId, $operationType, $logicalRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function latestOperationRequestForOperation(int $batchId, string $operationType): ?array
    {
        K2ImportOperationType::assertValid($operationType);

        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_operation_requests ' .
            'WHERE batch_id = ? AND operation_type = ? ORDER BY attempt_number DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$batchId, $operationType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function findOperationAttemptByRequestId(int $operationRequestId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_operation_attempts ' .
            'WHERE operation_request_id = ? ORDER BY attempt_number DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$operationRequestId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function upsertOperationRequest(
        int $batchId,
        string $operationType,
        string $requestIdempotencyKey,
        string $logicalRequestId,
        int $attemptNumber
    ): int
    {
        K2ImportOperationType::assertValid($operationType);
        if (trim($logicalRequestId) === '') {
            throw new InvalidArgumentException('Logical request ID is required.');
        }
        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Attempt number must be greater than zero.');
        }

        $existing = $this->findOperationRequestByKey($requestIdempotencyKey);
        if ($existing !== null) {
            return $this->reuseOperationRequest(
                $existing,
                $batchId,
                $operationType,
                $requestIdempotencyKey,
                $logicalRequestId,
                $attemptNumber
            );
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO k2_automation_operation_requests ' .
            '(batch_id, operation_type, idempotency_key, logical_request_id, attempt_number, request_status) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $batchId,
                $operationType,
                $requestIdempotencyKey,
                $logicalRequestId,
                $attemptNumber,
                K2ImportRequestStatus::RECEIVED,
            ]);

            return (int) $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            if (!$this->isDuplicateKeyException($e)) {
                throw $e;
            }

            $existing = $this->findOperationRequestByKey($requestIdempotencyKey);
            if ($existing === null) {
                throw $e;
            }

            return $this->reuseOperationRequest(
                $existing,
                $batchId,
                $operationType,
                $requestIdempotencyKey,
                $logicalRequestId,
                $attemptNumber
            );
        }
    }

    public function markOperationRequestRunning(int $requestId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_requests ' .
            'SET request_status = ?, last_seen_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE id = ? AND request_status IN (?, ?)'
        );
        $stmt->execute([
            K2ImportRequestStatus::RUNNING,
            $requestId,
            K2ImportRequestStatus::RECEIVED,
            K2ImportRequestStatus::RUNNING,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new K2ImportRepositoryStateConflictException(
                "Operation request {$requestId} could not be moved to RUNNING."
            );
        }
    }

    public function markOperationRequestCompleted(
        int $requestId,
        string $requestStatus,
        string $batchState,
        string $archiveState,
        int $httpStatus,
        ?string $responseBodyHash
    ): void {
        if (!in_array($requestStatus, [
            K2ImportRequestStatus::SUCCEEDED,
            K2ImportRequestStatus::FAILED_RETRYABLE,
            K2ImportRequestStatus::FAILED_FINAL,
        ], true)) {
            throw new InvalidArgumentException("Invalid completed request status: {$requestStatus}");
        }

        K2ImportStateMachine::assertValidStatePair($batchState, $archiveState);

        if ($responseBodyHash !== null && preg_match('/\A[a-f0-9]{64}\z/', $responseBodyHash) !== 1) {
            throw new InvalidArgumentException('Response body hash must be null or a lowercase SHA-256 hex digest.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_requests ' .
            'SET request_status = ?, response_batch_state = ?, response_archive_state = ?, ' .
            'response_http_status = ?, response_body_hash = ?, completed_at = CURRENT_TIMESTAMP(6), ' .
            'last_seen_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE id = ? AND request_status IN (?, ?)'
        );
        $stmt->execute([
            $requestStatus,
            $batchState,
            $archiveState,
            $httpStatus,
            $responseBodyHash,
            $requestId,
            K2ImportRequestStatus::RECEIVED,
            K2ImportRequestStatus::RUNNING,
        ]);

        if ($stmt->rowCount() !== 1) {
            throw new K2ImportRepositoryStateConflictException(
                "Operation request {$requestId} could not be completed from its current status."
            );
        }
    }

    public function recordOperationAttemptStarted(
        int $batchId,
        ?int $operationRequestId,
        string $operationType,
        int $attemptNumber,
        string $attemptIdempotencyKey
    ): int {
        K2ImportOperationType::assertValid($operationType);

        if ($attemptNumber < 1) {
            throw new InvalidArgumentException('Attempt number must be greater than zero.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO k2_automation_operation_attempts ' .
            '(batch_id, operation_request_id, operation_type, attempt_number, attempt_idempotency_key, status) ' .
            'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $batchId,
            $operationRequestId,
            $operationType,
            $attemptNumber,
            $attemptIdempotencyKey,
            K2ImportAttemptStatus::STARTED,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function markOperationAttemptSucceeded(int $attemptId, array $counters): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_attempts SET ' .
            'status = ?, inserted_count = ?, updated_count = ?, skipped_count = ?, warning_count = ?, ' .
            'worker_claim_token = NULL, worker_claimed_at = NULL, worker_hostname = NULL, worker_pid = NULL, ' .
            'finished_at = CURRENT_TIMESTAMP(6) WHERE id = ?'
        );
        $stmt->execute([
            K2ImportAttemptStatus::SUCCEEDED,
            self::counter($counters, 'inserted_count'),
            self::counter($counters, 'updated_count'),
            self::counter($counters, 'skipped_count'),
            self::counter($counters, 'warning_count'),
            $attemptId,
        ]);
    }

    public function markOperationAttemptFailed(
        int $attemptId,
        string $errorCode,
        string $errorMessage,
        bool $retryable
    ): void {
        $status = $retryable
            ? K2ImportAttemptStatus::FAILED_RETRYABLE
            : K2ImportAttemptStatus::FAILED_FINAL;

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_attempts SET ' .
            'status = ?, error_code = ?, error_message = ?, error_retryable = ?, ' .
            'worker_claim_token = NULL, worker_claimed_at = NULL, worker_hostname = NULL, worker_pid = NULL, ' .
            'finished_at = CURRENT_TIMESTAMP(6) WHERE id = ?'
        );
        $stmt->execute([
            $status,
            $errorCode,
            $errorMessage,
            $retryable ? 1 : 0,
            $attemptId,
        ]);
    }

    public function nextAttemptNumber(int $batchId, string $operationType): int
    {
        K2ImportOperationType::assertValid($operationType);

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(attempt_number), 0) + 1 AS next_attempt_number ' .
            'FROM k2_automation_operation_attempts WHERE batch_id = ? AND operation_type = ?'
        );
        $stmt->execute([$batchId, $operationType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['next_attempt_number'] ?? 1);
    }

    public function transitionBatchState(
        int $batchId,
        string $fromState,
        string $fromArchiveState,
        string $toState,
        string $toArchiveState
    ): bool {
        K2ImportStateMachine::assertTransition($fromState, $fromArchiveState, $toState, $toArchiveState);

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_import_batches ' .
            'SET state = ?, archive_state = ?, updated_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE id = ? AND state = ? AND archive_state = ?'
        );
        $stmt->execute([$toState, $toArchiveState, $batchId, $fromState, $fromArchiveState]);

        return $stmt->rowCount() === 1;
    }

    public function claimBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool
    {
        self::assertValidLock($ownerToken, $lockSeconds);

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_import_batches ' .
            'SET run_lock_token = ?, run_lock_acquired_at = UTC_TIMESTAMP(6), ' .
            'run_lock_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), ' .
            'updated_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE id = ? AND (' .
            'run_lock_token IS NULL OR run_lock_expires_at IS NULL OR ' .
            'run_lock_expires_at <= UTC_TIMESTAMP(6) OR run_lock_token = ?' .
            ')'
        );
        $stmt->execute([$ownerToken, $lockSeconds, $batchId, $ownerToken]);

        return $stmt->rowCount() === 1;
    }

    public function extendBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool
    {
        self::assertValidLock($ownerToken, $lockSeconds);

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_import_batches ' .
            'SET run_lock_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), ' .
            'updated_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE id = ? AND run_lock_token = ? AND run_lock_expires_at > UTC_TIMESTAMP(6)'
        );
        $stmt->execute([$lockSeconds, $batchId, $ownerToken]);

        return $stmt->rowCount() === 1;
    }

    public function releaseBatchLock(int $batchId, string $ownerToken): bool
    {
        if (trim($ownerToken) === '') {
            throw new InvalidArgumentException('Lock owner token is required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_import_batches ' .
            'SET run_lock_token = NULL, run_lock_acquired_at = NULL, run_lock_expires_at = NULL, ' .
            'updated_at = CURRENT_TIMESTAMP(6) ' .
            'WHERE id = ? AND run_lock_token = ?'
        );
        $stmt->execute([$batchId, $ownerToken]);

        return $stmt->rowCount() === 1;
    }

    public function hasActiveBatchLock(int $batchId, string $ownerToken): bool
    {
        if (trim($ownerToken) === '') {
            return false;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM k2_automation_import_batches ' .
            'WHERE id = ? AND run_lock_token = ? AND run_lock_expires_at > UTC_TIMESTAMP(6) LIMIT 1'
        );
        $stmt->execute([$batchId, $ownerToken]);

        return $stmt->fetchColumn() !== false;
    }

    public function recoverStaleProcessingBatch(int $batchId, int $staleSeconds): ?array
    {
        if ($staleSeconds < 1) {
            throw new InvalidArgumentException('Stale operation timeout must be at least one second.');
        }

        return $this->transactional(function () use ($batchId, $staleSeconds): ?array {
            $batchStmt = $this->pdo->prepare(
                'SELECT * FROM k2_automation_import_batches WHERE id = ? FOR UPDATE'
            );
            $batchStmt->execute([$batchId]);
            $batch = $batchStmt->fetch(PDO::FETCH_ASSOC);
            if ($batch === false) {
                return null;
            }

            $state = (string) $batch['state'];
            $archiveState = (string) $batch['archive_state'];
            $operationType = null;
            $targetState = null;
            $targetArchiveState = null;
            if ($state === K2ImportBatchState::AON_PROCESSING) {
                $operationType = K2ImportOperationType::AON;
                $targetState = K2ImportBatchState::AON_FAILED_RETRYABLE;
                $targetArchiveState = K2ImportArchiveState::NOT_READY;
            } elseif ($state === K2ImportBatchState::PR_PROCESSING) {
                $operationType = K2ImportOperationType::PR;
                $targetState = K2ImportBatchState::PR_FAILED_RETRYABLE;
                $targetArchiveState = K2ImportArchiveState::NOT_READY;
            } elseif ($state === K2ImportBatchState::ARCHIVE_PENDING && $archiveState === K2ImportArchiveState::PROCESSING) {
                $operationType = K2ImportOperationType::ARCHIVE;
                $targetState = K2ImportBatchState::ARCHIVE_PENDING;
                $targetArchiveState = K2ImportArchiveState::FAILED_RETRYABLE;
            } else {
                return null;
            }

            if (($batch['run_lock_expires_at'] ?? null) !== null) {
                $lockStmt = $this->pdo->prepare(
                    'SELECT (? > UTC_TIMESTAMP(6)) AS active_lock'
                );
                $lockStmt->execute([(string) $batch['run_lock_expires_at']]);
                if ((int) $lockStmt->fetchColumn() === 1) {
                    return null;
                }
            }

            $attemptStmt = $this->pdo->prepare(
                'SELECT * FROM k2_automation_operation_attempts ' .
                'WHERE batch_id = ? AND operation_type = ? ' .
                'ORDER BY attempt_number DESC, id DESC LIMIT 1 FOR UPDATE'
            );
            $attemptStmt->execute([$batchId, $operationType]);
            $attempt = $attemptStmt->fetch(PDO::FETCH_ASSOC);
            if ($attempt === false || (string) $attempt['status'] !== K2ImportAttemptStatus::STARTED) {
                return null;
            }

            $staleStmt = $this->pdo->prepare(
                'SELECT (? <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS stale_attempt'
            );
            $staleStmt->execute([(string) $attempt['started_at'], $staleSeconds]);
            if ((int) $staleStmt->fetchColumn() !== 1) {
                return null;
            }

            $errorCode = 'STALE_OPERATION_RECOVERED';
            $errorMessage = 'Stale processing operation recovered for retry.';
            $this->markOperationAttemptFailed((int) $attempt['id'], $errorCode, $errorMessage, true);

            if (($attempt['operation_request_id'] ?? null) !== null) {
                $requestStmt = $this->pdo->prepare(
                    'UPDATE k2_automation_operation_requests SET ' .
                    'request_status = ?, response_batch_state = ?, response_archive_state = ?, ' .
                    'response_http_status = 503, completed_at = CURRENT_TIMESTAMP(6), last_seen_at = CURRENT_TIMESTAMP(6) ' .
                    'WHERE id = ? AND request_status IN (?, ?)'
                );
                $requestStmt->execute([
                    K2ImportRequestStatus::FAILED_RETRYABLE,
                    $targetState,
                    $targetArchiveState,
                    (int) $attempt['operation_request_id'],
                    K2ImportRequestStatus::RECEIVED,
                    K2ImportRequestStatus::RUNNING,
                ]);
            }

            $update = $this->pdo->prepare(
                'UPDATE k2_automation_import_batches SET ' .
                'state = ?, archive_state = ?, run_lock_token = NULL, run_lock_acquired_at = NULL, run_lock_expires_at = NULL, ' .
                'last_error_phase = ?, last_error_code = ?, last_error_message = ?, last_error_retryable = 1, ' .
                'last_error_at = UTC_TIMESTAMP(6), updated_at = CURRENT_TIMESTAMP(6) ' .
                'WHERE id = ? AND state = ? AND archive_state = ?'
            );
            $update->execute([
                $targetState,
                $targetArchiveState,
                $operationType,
                $errorCode,
                $errorMessage,
                $batchId,
                $state,
                $archiveState,
            ]);
            if ($update->rowCount() !== 1) {
                return null;
            }

            return $this->findBatchById($batchId);
        });
    }

    public function attachStagedFiles(int $attemptId, array $stagedFileManifest): void
    {
        $json = json_encode(array_values($stagedFileManifest), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new InvalidArgumentException('Could not encode staged file manifest.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_attempts ' .
            'SET staged_file_manifest = ? ' .
            'WHERE id = ? AND status = ?'
        );
        $stmt->execute([$json, $attemptId, K2ImportAttemptStatus::STARTED]);

        if ($stmt->rowCount() !== 1) {
            throw new K2ImportRepositoryStateConflictException(
                "Operation attempt {$attemptId} could not be updated with a staged file manifest."
            );
        }
    }

    public function findAttemptById(int $attemptId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_operation_attempts WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function claimNextQueuedAttempt(
        string $workerToken,
        string $workerHostname,
        int $workerPid,
        int $leaseSeconds
    ): ?array {
        if (trim($workerToken) === '') {
            throw new InvalidArgumentException('Worker claim token is required.');
        }
        if ($leaseSeconds < 1) {
            throw new InvalidArgumentException('Worker lease duration must be at least one second.');
        }

        return $this->transactional(function () use ($workerToken, $workerHostname, $workerPid, $leaseSeconds): ?array {
            // A candidate is claimable if nobody holds it yet, or the previous
            // holder's lease expired (worker died/host rebooted mid-run) — this
            // lets a fresh worker retry the SAME attempt row without wasting an
            // attempt_number or forcing Apps Script to re-upload. The batch may
            // already be in the PROCESSING state (a reclaim after a dead worker),
            // not just QUEUED (a fresh claim) — both are valid candidates.
            $candidateStmt = $this->pdo->prepare(
                'SELECT a.id, a.operation_type, b.state AS batch_state ' .
                'FROM k2_automation_operation_attempts a ' .
                'JOIN k2_automation_import_batches b ON b.id = a.batch_id ' .
                'WHERE a.status = ? ' .
                'AND a.staged_file_manifest IS NOT NULL ' .
                'AND (a.worker_claim_token IS NULL OR a.worker_claimed_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) ' .
                'AND (' .
                '  (a.operation_type = ? AND b.state IN (?, ?)) OR ' .
                '  (a.operation_type = ? AND b.state IN (?, ?))' .
                ') ' .
                'ORDER BY FIELD(a.operation_type, ?, ?), a.started_at ASC ' .
                'LIMIT 1 FOR UPDATE SKIP LOCKED'
            );
            $candidateStmt->execute([
                K2ImportAttemptStatus::STARTED,
                $leaseSeconds,
                K2ImportOperationType::AON,
                K2ImportBatchState::AON_QUEUED,
                K2ImportBatchState::AON_PROCESSING,
                K2ImportOperationType::PR,
                K2ImportBatchState::PR_QUEUED,
                K2ImportBatchState::PR_PROCESSING,
                K2ImportOperationType::AON,
                K2ImportOperationType::PR,
            ]);
            $candidate = $candidateStmt->fetch(PDO::FETCH_ASSOC);
            if ($candidate === false) {
                return null;
            }

            $attemptId = (int) $candidate['id'];
            $operationType = (string) $candidate['operation_type'];

            $claimStmt = $this->pdo->prepare(
                'UPDATE k2_automation_operation_attempts ' .
                'SET worker_claim_token = ?, worker_claimed_at = UTC_TIMESTAMP(6), ' .
                'worker_hostname = ?, worker_pid = ? ' .
                'WHERE id = ? AND status = ? ' .
                'AND (worker_claim_token IS NULL OR worker_claimed_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND))'
            );
            $claimStmt->execute([
                $workerToken,
                $workerHostname,
                $workerPid,
                $attemptId,
                K2ImportAttemptStatus::STARTED,
                $leaseSeconds,
            ]);
            if ($claimStmt->rowCount() !== 1) {
                return null;
            }

            $attempt = $this->findAttemptById($attemptId);
            if ($attempt === null) {
                throw new K2ImportRepositoryStateConflictException(
                    "Claimed operation attempt {$attemptId} disappeared."
                );
            }

            $batchId = (int) $attempt['batch_id'];
            $batch = $this->findBatchById($batchId);
            if ($batch === null) {
                throw new K2ImportRepositoryStateConflictException(
                    "Batch {$batchId} for claimed attempt {$attemptId} was not found."
                );
            }

            $queuedState = K2ImportStateMachine::queuedStateFor($operationType);
            $processingState = K2ImportStateMachine::processingStateFor($operationType);
            $currentBatchState = (string) $batch['state'];

            if ($currentBatchState === $processingState) {
                // Reclaiming an expired lease: the batch is already PROCESSING from
                // the original (now-stale) claim, so there is no transition to make.
                return $this->findClaimedAttemptContext($attemptId);
            }

            if ($currentBatchState !== $queuedState) {
                throw new K2ImportRepositoryStateConflictException(
                    "Batch {$batchId} was not in {$queuedState} or {$processingState} when attempt {$attemptId} was claimed."
                );
            }

            if (!$this->transitionBatchState(
                $batchId,
                $queuedState,
                K2ImportArchiveState::NOT_READY,
                $processingState,
                K2ImportArchiveState::NOT_READY
            )) {
                throw new K2ImportRepositoryStateConflictException(
                    "Batch {$batchId} could not move to {$processingState} for claimed attempt {$attemptId}."
                );
            }

            return $this->findClaimedAttemptContext($attemptId);
        });
    }

    public function extendWorkerLease(int $attemptId, string $workerToken): bool
    {
        if (trim($workerToken) === '') {
            throw new InvalidArgumentException('Worker claim token is required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_attempts ' .
            'SET worker_claimed_at = UTC_TIMESTAMP(6) ' .
            'WHERE id = ? AND worker_claim_token = ? AND status = ?'
        );
        $stmt->execute([$attemptId, $workerToken, K2ImportAttemptStatus::STARTED]);

        return $stmt->rowCount() === 1;
    }

    public function releaseWorkerClaim(int $attemptId, string $workerToken): bool
    {
        if (trim($workerToken) === '') {
            throw new InvalidArgumentException('Worker claim token is required.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_attempts ' .
            'SET worker_claim_token = NULL, worker_claimed_at = NULL, worker_hostname = NULL, worker_pid = NULL ' .
            'WHERE id = ? AND worker_claim_token = ?'
        );
        $stmt->execute([$attemptId, $workerToken]);

        return $stmt->rowCount() === 1;
    }

    private function findClaimedAttemptContext(int $attemptId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id AS attempt_id, a.batch_id, a.operation_request_id, a.operation_type, ' .
            'a.attempt_number, a.attempt_idempotency_key, a.staged_file_manifest, a.worker_claim_token, ' .
            'a.worker_claimed_at, ' .
            'b.batch_uid, b.state AS batch_state, b.archive_state, ' .
            'r.idempotency_key AS request_idempotency_key, r.logical_request_id ' .
            'FROM k2_automation_operation_attempts a ' .
            'JOIN k2_automation_import_batches b ON b.id = a.batch_id ' .
            'LEFT JOIN k2_automation_operation_requests r ON r.id = a.operation_request_id ' .
            'WHERE a.id = ? LIMIT 1'
        );
        $stmt->execute([$attemptId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function findSourceFileById(int $sourceFileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM k2_automation_source_files WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$sourceFileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function reuseOperationRequest(
        array $existing,
        int $batchId,
        string $operationType,
        string $requestIdempotencyKey,
        string $logicalRequestId,
        int $attemptNumber
    ): int {
        K2ImportRepositoryGuards::assertOperationRequestMatchesSubmitted(
            $existing,
            $batchId,
            $operationType,
            $requestIdempotencyKey,
            $logicalRequestId,
            $attemptNumber
        );

        $requestId = (int) $existing['id'];
        $stmt = $this->pdo->prepare(
            'UPDATE k2_automation_operation_requests ' .
            'SET last_seen_at = CURRENT_TIMESTAMP(6), ' .
            'duplicate_replay_count = duplicate_replay_count + 1 ' .
            'WHERE id = ? AND batch_id = ? AND operation_type = ? AND idempotency_key = ?'
        );
        $stmt->execute([$requestId, $batchId, $operationType, $requestIdempotencyKey]);

        if ($stmt->rowCount() !== 1) {
            throw new K2ImportRepositoryStateConflictException(
                "Operation request {$requestId} could not be verified for duplicate reuse."
            );
        }

        return $requestId;
    }

    private function isDuplicateKeyException(PDOException $e): bool
    {
        $errorInfo = $e->errorInfo;

        return ($errorInfo[0] ?? null) === '23000'
            || (int) ($errorInfo[1] ?? 0) === 1062;
    }

    private static function assertValidLock(string $ownerToken, int $lockSeconds): void
    {
        if (trim($ownerToken) === '') {
            throw new InvalidArgumentException('Lock owner token is required.');
        }

        if ($lockSeconds < 1) {
            throw new InvalidArgumentException('Lock duration must be at least one second.');
        }
    }

    private static function counter(array $counters, string $key): ?int
    {
        if (!array_key_exists($key, $counters)) {
            return null;
        }

        $value = $counters[$key];
        if (!is_int($value) || $value < 0) {
            throw new InvalidArgumentException("Counter {$key} must be a non-negative integer.");
        }

        return $value;
    }
}
