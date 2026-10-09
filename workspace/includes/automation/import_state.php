<?php
declare(strict_types=1);

final class K2ImportDatasetType
{
    public const PR = 'pr';
    public const CATALOG = 'catalog';
    public const PRICE = 'price';

    public static function requiredTypes(): array
    {
        return [self::PR, self::CATALOG, self::PRICE];
    }

    public static function assertValid(string $datasetType): void
    {
        if (!in_array($datasetType, self::requiredTypes(), true)) {
            throw new InvalidArgumentException("Unknown K2 import dataset type: {$datasetType}");
        }
    }

    public static function assertExactlyRequired(array $datasetTypes): void
    {
        $counts = array_count_values($datasetTypes);
        foreach ($counts as $datasetType => $count) {
            self::assertValid((string) $datasetType);
            if ($count > 1) {
                throw new InvalidArgumentException("Duplicate K2 import dataset type: {$datasetType}");
            }
        }

        $missing = array_values(array_diff(self::requiredTypes(), $datasetTypes));
        $extra = array_values(array_diff($datasetTypes, self::requiredTypes()));
        if ($missing !== [] || $extra !== []) {
            throw new InvalidArgumentException(
                'A K2 import batch requires exactly one pr, one catalog, and one price source file.'
            );
        }
    }
}

final class K2ImportBatchState
{
    public const DETECTED = 'DETECTED';
    public const AON_QUEUED = 'AON_QUEUED';
    public const AON_PROCESSING = 'AON_PROCESSING';
    public const AON_SUCCESS = 'AON_SUCCESS';
    public const AON_FAILED_RETRYABLE = 'AON_FAILED_RETRYABLE';
    public const AON_FAILED_FINAL = 'AON_FAILED_FINAL';
    public const PR_QUEUED = 'PR_QUEUED';
    public const PR_PROCESSING = 'PR_PROCESSING';
    public const PR_FAILED_RETRYABLE = 'PR_FAILED_RETRYABLE';
    public const PR_FAILED_FINAL = 'PR_FAILED_FINAL';
    public const IMPORT_SUCCESS = 'IMPORT_SUCCESS';
    public const ARCHIVE_PENDING = 'ARCHIVE_PENDING';
    public const COMPLETED = 'COMPLETED';

    public static function all(): array
    {
        return [
            self::DETECTED,
            self::AON_QUEUED,
            self::AON_PROCESSING,
            self::AON_SUCCESS,
            self::AON_FAILED_RETRYABLE,
            self::AON_FAILED_FINAL,
            self::PR_QUEUED,
            self::PR_PROCESSING,
            self::PR_FAILED_RETRYABLE,
            self::PR_FAILED_FINAL,
            self::IMPORT_SUCCESS,
            self::ARCHIVE_PENDING,
            self::COMPLETED,
        ];
    }

    public static function assertValid(string $state): void
    {
        if (!in_array($state, self::all(), true)) {
            throw new InvalidArgumentException("Unknown K2 import batch state: {$state}");
        }
    }
}

final class K2ImportArchiveState
{
    public const NOT_READY = 'NOT_READY';
    public const PENDING = 'PENDING';
    public const PROCESSING = 'PROCESSING';
    public const FAILED_RETRYABLE = 'FAILED_RETRYABLE';
    public const FAILED_FINAL = 'FAILED_FINAL';
    public const COMPLETED = 'COMPLETED';

    public static function all(): array
    {
        return [
            self::NOT_READY,
            self::PENDING,
            self::PROCESSING,
            self::FAILED_RETRYABLE,
            self::FAILED_FINAL,
            self::COMPLETED,
        ];
    }

    public static function assertValid(string $state): void
    {
        if (!in_array($state, self::all(), true)) {
            throw new InvalidArgumentException("Unknown K2 import archive state: {$state}");
        }
    }
}

final class K2ImportOperationType
{
    public const DETECT = 'detect';
    public const AON = 'aon';
    public const PR = 'pr';
    public const ARCHIVE = 'archive';

    public static function all(): array
    {
        return [self::DETECT, self::AON, self::PR, self::ARCHIVE];
    }

    public static function assertValid(string $operationType): void
    {
        if (!in_array($operationType, self::all(), true)) {
            throw new InvalidArgumentException("Unknown K2 import operation type: {$operationType}");
        }
    }
}

final class K2ImportRequestStatus
{
    public const RECEIVED = 'RECEIVED';
    public const RUNNING = 'RUNNING';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED_RETRYABLE = 'FAILED_RETRYABLE';
    public const FAILED_FINAL = 'FAILED_FINAL';
    public const REPLAYED = 'REPLAYED';

    public static function all(): array
    {
        return [
            self::RECEIVED,
            self::RUNNING,
            self::SUCCEEDED,
            self::FAILED_RETRYABLE,
            self::FAILED_FINAL,
            self::REPLAYED,
        ];
    }

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::all(), true)) {
            throw new InvalidArgumentException("Unknown K2 import request status: {$status}");
        }
    }

    public static function canMoveToRunning(string $status): bool
    {
        self::assertValid($status);

        return in_array($status, [self::RECEIVED, self::RUNNING], true);
    }
}

final class K2ImportAttemptStatus
{
    public const STARTED = 'STARTED';
    public const SUCCEEDED = 'SUCCEEDED';
    public const FAILED_RETRYABLE = 'FAILED_RETRYABLE';
    public const FAILED_FINAL = 'FAILED_FINAL';

    public static function all(): array
    {
        return [
            self::STARTED,
            self::SUCCEEDED,
            self::FAILED_RETRYABLE,
            self::FAILED_FINAL,
        ];
    }

    public static function assertValid(string $status): void
    {
        if (!in_array($status, self::all(), true)) {
            throw new InvalidArgumentException("Unknown K2 import attempt status: {$status}");
        }
    }
}

final class K2DuplicateRequestDecision
{
    public const START_NEW = 'start_new';
    public const IN_PROGRESS = 'in_progress';
    public const REPLAY_SUCCESS = 'replay_success';
    public const RETRY_ALLOWED = 'retry_allowed';
    public const REJECT_FINAL_FAILURE = 'reject_final_failure';
}

final class K2ImportTransitionRejectedException extends DomainException
{
}

final class K2ImportStateMachine
{
    private const NOT_READY_BATCH_STATES = [
        K2ImportBatchState::DETECTED,
        K2ImportBatchState::AON_QUEUED,
        K2ImportBatchState::AON_PROCESSING,
        K2ImportBatchState::AON_SUCCESS,
        K2ImportBatchState::AON_FAILED_RETRYABLE,
        K2ImportBatchState::AON_FAILED_FINAL,
        K2ImportBatchState::PR_QUEUED,
        K2ImportBatchState::PR_PROCESSING,
        K2ImportBatchState::PR_FAILED_RETRYABLE,
        K2ImportBatchState::PR_FAILED_FINAL,
        K2ImportBatchState::IMPORT_SUCCESS,
    ];

    private const ARCHIVE_PENDING_ARCHIVE_STATES = [
        K2ImportArchiveState::PENDING,
        K2ImportArchiveState::PROCESSING,
        K2ImportArchiveState::FAILED_RETRYABLE,
        K2ImportArchiveState::FAILED_FINAL,
    ];

    private const ARCHIVE_STATE_TRANSITIONS = [
        K2ImportArchiveState::PENDING => [
            K2ImportArchiveState::PROCESSING,
        ],
        K2ImportArchiveState::PROCESSING => [
            K2ImportArchiveState::FAILED_RETRYABLE,
            K2ImportArchiveState::FAILED_FINAL,
        ],
        K2ImportArchiveState::FAILED_RETRYABLE => [
            K2ImportArchiveState::PROCESSING,
        ],
        K2ImportArchiveState::FAILED_FINAL => [],
    ];

    private const TRANSITIONS = [
        K2ImportBatchState::DETECTED => [
            K2ImportBatchState::AON_QUEUED,
        ],
        K2ImportBatchState::AON_QUEUED => [
            K2ImportBatchState::AON_PROCESSING,
        ],
        K2ImportBatchState::AON_PROCESSING => [
            K2ImportBatchState::AON_SUCCESS,
            K2ImportBatchState::AON_FAILED_RETRYABLE,
            K2ImportBatchState::AON_FAILED_FINAL,
        ],
        K2ImportBatchState::AON_FAILED_RETRYABLE => [
            K2ImportBatchState::AON_QUEUED,
        ],
        K2ImportBatchState::AON_SUCCESS => [
            K2ImportBatchState::PR_QUEUED,
        ],
        K2ImportBatchState::PR_QUEUED => [
            K2ImportBatchState::PR_PROCESSING,
        ],
        K2ImportBatchState::PR_PROCESSING => [
            K2ImportBatchState::IMPORT_SUCCESS,
            K2ImportBatchState::PR_FAILED_RETRYABLE,
            K2ImportBatchState::PR_FAILED_FINAL,
        ],
        K2ImportBatchState::PR_FAILED_RETRYABLE => [
            K2ImportBatchState::PR_QUEUED,
        ],
        K2ImportBatchState::IMPORT_SUCCESS => [
            K2ImportBatchState::ARCHIVE_PENDING,
        ],
        K2ImportBatchState::ARCHIVE_PENDING => [
            K2ImportBatchState::COMPLETED,
        ],
        K2ImportBatchState::AON_FAILED_FINAL => [],
        K2ImportBatchState::PR_FAILED_FINAL => [],
        K2ImportBatchState::COMPLETED => [],
    ];

    public static function validTransitions(): array
    {
        return self::TRANSITIONS;
    }

    public static function validArchiveStatesForBatchState(string $batchState): array
    {
        K2ImportBatchState::assertValid($batchState);

        if (in_array($batchState, self::NOT_READY_BATCH_STATES, true)) {
            return [K2ImportArchiveState::NOT_READY];
        }

        if ($batchState === K2ImportBatchState::ARCHIVE_PENDING) {
            return self::ARCHIVE_PENDING_ARCHIVE_STATES;
        }

        return [K2ImportArchiveState::COMPLETED];
    }

    public static function isValidStatePair(string $batchState, string $archiveState): bool
    {
        K2ImportBatchState::assertValid($batchState);
        K2ImportArchiveState::assertValid($archiveState);

        return in_array($archiveState, self::validArchiveStatesForBatchState($batchState), true);
    }

    public static function assertValidStatePair(string $batchState, string $archiveState): void
    {
        if (!self::isValidStatePair($batchState, $archiveState)) {
            throw new K2ImportTransitionRejectedException(
                "Invalid K2 import state pair {$batchState} / {$archiveState}."
            );
        }
    }

    public static function canTransition(
        string $fromState,
        string $fromArchiveState,
        string $toState,
        string $toArchiveState
    ): bool {
        self::assertValidStatePair($fromState, $fromArchiveState);
        self::assertValidStatePair($toState, $toArchiveState);

        if ($fromState === K2ImportBatchState::ARCHIVE_PENDING
            && $toState === K2ImportBatchState::ARCHIVE_PENDING
        ) {
            return in_array($toArchiveState, self::ARCHIVE_STATE_TRANSITIONS[$fromArchiveState], true);
        }

        if ($fromState === K2ImportBatchState::IMPORT_SUCCESS
            && $toState === K2ImportBatchState::ARCHIVE_PENDING
        ) {
            return $fromArchiveState === K2ImportArchiveState::NOT_READY
                && $toArchiveState === K2ImportArchiveState::PENDING;
        }

        if ($fromState === K2ImportBatchState::ARCHIVE_PENDING
            && $toState === K2ImportBatchState::COMPLETED
        ) {
            return $toArchiveState === K2ImportArchiveState::COMPLETED
                && in_array($fromArchiveState, [
                    K2ImportArchiveState::PENDING,
                    K2ImportArchiveState::PROCESSING,
                    K2ImportArchiveState::FAILED_RETRYABLE,
                ], true);
        }

        return in_array($toState, self::TRANSITIONS[$fromState], true);
    }

    public static function assertTransition(
        string $fromState,
        string $fromArchiveState,
        string $toState,
        string $toArchiveState
    ): void
    {
        if (!self::canTransition($fromState, $fromArchiveState, $toState, $toArchiveState)) {
            throw new K2ImportTransitionRejectedException(
                "Rejected K2 import transition from {$fromState} / {$fromArchiveState} " .
                "to {$toState} / {$toArchiveState}."
            );
        }
    }

    public static function canStartOperation(
        string $operationType,
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): bool {
        K2ImportOperationType::assertValid($operationType);
        self::assertValidStatePair($batchState, $archiveState);

        if ($operationType === K2ImportOperationType::AON) {
            return in_array($batchState, [
                K2ImportBatchState::DETECTED,
                K2ImportBatchState::AON_FAILED_RETRYABLE,
            ], true);
        }

        if ($operationType === K2ImportOperationType::PR) {
            return in_array($batchState, [
                K2ImportBatchState::AON_SUCCESS,
                K2ImportBatchState::PR_FAILED_RETRYABLE,
            ], true);
        }

        if ($operationType === K2ImportOperationType::ARCHIVE) {
            return $batchState === K2ImportBatchState::ARCHIVE_PENDING
                && in_array($archiveState, [
                    K2ImportArchiveState::PENDING,
                    K2ImportArchiveState::FAILED_RETRYABLE,
                ], true);
        }

        return $operationType === K2ImportOperationType::DETECT
            && $batchState === K2ImportBatchState::DETECTED;
    }

    public static function assertCanStartOperation(
        string $operationType,
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): void {
        if (!self::canStartOperation($operationType, $batchState, $archiveState)) {
            throw new K2ImportTransitionRejectedException(
                "Cannot start {$operationType} while batch is {$batchState} and archive is {$archiveState}."
            );
        }
    }

    /**
     * True when a worker may claim the given operation type for a batch currently
     * sitting in its QUEUED state. Distinct from canStartOperation(), which governs
     * when the HTTP layer may enqueue (DETECTED/AON_FAILED_RETRYABLE -> AON_QUEUED,
     * AON_SUCCESS/PR_FAILED_RETRYABLE -> PR_QUEUED). Archive has no queued phase.
     */
    public static function canClaimForProcessing(string $operationType, string $batchState): bool
    {
        K2ImportOperationType::assertValid($operationType);
        K2ImportBatchState::assertValid($batchState);

        if ($operationType === K2ImportOperationType::AON) {
            return $batchState === K2ImportBatchState::AON_QUEUED;
        }

        if ($operationType === K2ImportOperationType::PR) {
            return $batchState === K2ImportBatchState::PR_QUEUED;
        }

        return false;
    }

    public static function processingStateFor(string $operationType): string
    {
        return match ($operationType) {
            K2ImportOperationType::AON => K2ImportBatchState::AON_PROCESSING,
            K2ImportOperationType::PR => K2ImportBatchState::PR_PROCESSING,
            default => throw new InvalidArgumentException('Only AON and PR operations have a queued claim phase.'),
        };
    }

    public static function queuedStateFor(string $operationType): string
    {
        return match ($operationType) {
            K2ImportOperationType::AON => K2ImportBatchState::AON_QUEUED,
            K2ImportOperationType::PR => K2ImportBatchState::PR_QUEUED,
            default => throw new InvalidArgumentException('Only AON and PR operations have a queued phase.'),
        };
    }

    public static function nextRunnableOperation(
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): ?string {
        self::assertValidStatePair($batchState, $archiveState);

        if (self::canStartOperation(K2ImportOperationType::AON, $batchState, $archiveState)) {
            return K2ImportOperationType::AON;
        }

        if (self::canStartOperation(K2ImportOperationType::PR, $batchState, $archiveState)) {
            return K2ImportOperationType::PR;
        }

        if (self::canStartOperation(K2ImportOperationType::ARCHIVE, $batchState, $archiveState)) {
            return K2ImportOperationType::ARCHIVE;
        }

        return null;
    }

    public static function retryPhase(
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): ?string {
        self::assertValidStatePair($batchState, $archiveState);

        if ($batchState === K2ImportBatchState::AON_FAILED_RETRYABLE) {
            return K2ImportOperationType::AON;
        }

        if ($batchState === K2ImportBatchState::PR_FAILED_RETRYABLE) {
            return K2ImportOperationType::PR;
        }

        if ($batchState === K2ImportBatchState::ARCHIVE_PENDING
            && $archiveState === K2ImportArchiveState::FAILED_RETRYABLE
        ) {
            return K2ImportOperationType::ARCHIVE;
        }

        return null;
    }

    public static function mustRunAon(string $batchState): bool
    {
        K2ImportBatchState::assertValid($batchState);

        return in_array($batchState, [
            K2ImportBatchState::DETECTED,
            K2ImportBatchState::AON_FAILED_RETRYABLE,
        ], true);
    }

    public static function mustRunPr(string $batchState): bool
    {
        K2ImportBatchState::assertValid($batchState);

        return in_array($batchState, [
            K2ImportBatchState::AON_SUCCESS,
            K2ImportBatchState::PR_FAILED_RETRYABLE,
        ], true);
    }

    public static function onlyArchiveShouldRun(string $batchState, string $archiveState): bool
    {
        self::assertValidStatePair($batchState, $archiveState);

        return $batchState === K2ImportBatchState::ARCHIVE_PENDING
            && in_array($archiveState, [
                K2ImportArchiveState::PENDING,
                K2ImportArchiveState::FAILED_RETRYABLE,
            ], true);
    }

    public static function isTerminal(
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): bool {
        self::assertValidStatePair($batchState, $archiveState);

        return in_array($batchState, [
            K2ImportBatchState::COMPLETED,
            K2ImportBatchState::AON_FAILED_FINAL,
            K2ImportBatchState::PR_FAILED_FINAL,
        ], true) || (
            $batchState === K2ImportBatchState::ARCHIVE_PENDING
            && $archiveState === K2ImportArchiveState::FAILED_FINAL
        );
    }

    public static function isFailed(
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): bool {
        self::assertValidStatePair($batchState, $archiveState);

        return in_array($batchState, [
            K2ImportBatchState::AON_FAILED_RETRYABLE,
            K2ImportBatchState::AON_FAILED_FINAL,
            K2ImportBatchState::PR_FAILED_RETRYABLE,
            K2ImportBatchState::PR_FAILED_FINAL,
        ], true) || (
            $batchState === K2ImportBatchState::ARCHIVE_PENDING
            && in_array($archiveState, [
                K2ImportArchiveState::FAILED_RETRYABLE,
                K2ImportArchiveState::FAILED_FINAL,
            ], true)
        );
    }

    public static function isRetryableFailure(
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): bool {
        self::assertValidStatePair($batchState, $archiveState);

        return in_array($batchState, [
            K2ImportBatchState::AON_FAILED_RETRYABLE,
            K2ImportBatchState::PR_FAILED_RETRYABLE,
        ], true) || (
            $batchState === K2ImportBatchState::ARCHIVE_PENDING
            && $archiveState === K2ImportArchiveState::FAILED_RETRYABLE
        );
    }

    public static function isNonRetryableFailure(
        string $batchState,
        string $archiveState = K2ImportArchiveState::NOT_READY
    ): bool {
        self::assertValidStatePair($batchState, $archiveState);

        return in_array($batchState, [
            K2ImportBatchState::AON_FAILED_FINAL,
            K2ImportBatchState::PR_FAILED_FINAL,
        ], true) || (
            $batchState === K2ImportBatchState::ARCHIVE_PENDING
            && $archiveState === K2ImportArchiveState::FAILED_FINAL
        );
    }

    public static function failureStateFor(string $operationType, bool $retryable): string
    {
        K2ImportOperationType::assertValid($operationType);

        if ($operationType === K2ImportOperationType::AON) {
            return $retryable ? K2ImportBatchState::AON_FAILED_RETRYABLE : K2ImportBatchState::AON_FAILED_FINAL;
        }

        if ($operationType === K2ImportOperationType::PR) {
            return $retryable ? K2ImportBatchState::PR_FAILED_RETRYABLE : K2ImportBatchState::PR_FAILED_FINAL;
        }

        throw new InvalidArgumentException('Only AON and PR failures map to batch failure states.');
    }

    public static function archiveFailureStateFor(bool $retryable): string
    {
        return $retryable ? K2ImportArchiveState::FAILED_RETRYABLE : K2ImportArchiveState::FAILED_FINAL;
    }

    public static function duplicateRequestDecision(?string $existingStatus): string
    {
        if ($existingStatus === null) {
            return K2DuplicateRequestDecision::START_NEW;
        }

        K2ImportRequestStatus::assertValid($existingStatus);

        return match ($existingStatus) {
            K2ImportRequestStatus::RECEIVED,
            K2ImportRequestStatus::RUNNING => K2DuplicateRequestDecision::IN_PROGRESS,
            K2ImportRequestStatus::SUCCEEDED,
            K2ImportRequestStatus::REPLAYED => K2DuplicateRequestDecision::REPLAY_SUCCESS,
            K2ImportRequestStatus::FAILED_RETRYABLE => K2DuplicateRequestDecision::RETRY_ALLOWED,
            K2ImportRequestStatus::FAILED_FINAL => K2DuplicateRequestDecision::REJECT_FINAL_FAILURE,
        };
    }
}
