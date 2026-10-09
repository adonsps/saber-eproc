<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/import_identity.php';
require_once __DIR__ . '/../includes/automation/import_repository.php';

$tests = [];

$tests['deterministic batch ID generation'] = function (): void {
    $files = sample_files();
    $sameFilesDifferentOrder = [
        $files[K2ImportDatasetType::PRICE],
        $files[K2ImportDatasetType::PR],
        $files[K2ImportDatasetType::CATALOG],
    ];

    assert_same(K2ImportIdentity::batchUid($files), K2ImportIdentity::batchUid($sameFilesDifferentOrder));
    assert_same(
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files),
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $sameFilesDifferentOrder)
    );
};

$tests['differing file metadata generates differing identities'] = function (): void {
    $files = sample_files();
    $changed = sample_files([
        K2ImportDatasetType::PR => [
            'modifiedTimestamp' => '2026-07-22T08:30:00+07:00',
            'fileSizeBytes' => 424242,
            'sha256ContentHash' => str_repeat('d', 64),
        ],
    ]);

    assert_not_same(K2ImportIdentity::batchUid($files), K2ImportIdentity::batchUid($changed));
    assert_not_same(
        K2ImportIdentity::fileIdentityHash($files[K2ImportDatasetType::PR]),
        K2ImportIdentity::fileIdentityHash($changed[K2ImportDatasetType::PR])
    );
    assert_not_same(
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::PR, $files),
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::PR, $changed)
    );
};

$tests['operation attempt number changes idempotency key'] = function (): void {
    $files = sample_files();

    assert_not_same(
        K2ImportIdentity::attemptIdempotencyKey(K2ImportOperationType::PR, $files, 1),
        K2ImportIdentity::attemptIdempotencyKey(K2ImportOperationType::PR, $files, 2)
    );
};

$tests['request and attempt statuses are separated'] = function (): void {
    assert_same([
        'RECEIVED',
        'RUNNING',
        'SUCCEEDED',
        'FAILED_RETRYABLE',
        'FAILED_FINAL',
        'REPLAYED',
    ], K2ImportRequestStatus::all());
    assert_same([
        'STARTED',
        'SUCCEEDED',
        'FAILED_RETRYABLE',
        'FAILED_FINAL',
    ], K2ImportAttemptStatus::all());
    assert_false(in_array(K2ImportAttemptStatus::STARTED, K2ImportRequestStatus::all(), true));
    assert_false(in_array(K2ImportRequestStatus::RECEIVED, K2ImportAttemptStatus::all(), true));
};

$tests['stable request key across identical replay'] = function (): void {
    $files = sample_files();
    $schedule = new K2ImportScheduleMetadata('2026-07-22 08:30:15.123456', 'Asia/Jakarta');

    assert_same(
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule),
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, sample_files(), $schedule)
    );
    assert_true(str_starts_with(
        K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule),
        'k2req_'
    ));
    assert_true(str_starts_with(
        K2ImportIdentity::attemptIdempotencyKey(K2ImportOperationType::AON, $files, 1, $schedule),
        'k2att_'
    ));
};

$tests['exactly three required dataset types'] = function (): void {
    $normalized = K2ImportIdentity::normalizeSourceFiles(sample_files());

    assert_same(K2ImportDatasetType::requiredTypes(), array_keys($normalized));

    expect_exception(InvalidArgumentException::class, function (): void {
        K2ImportIdentity::normalizeSourceFiles([
            sample_file(K2ImportDatasetType::PR),
            sample_file(K2ImportDatasetType::CATALOG),
        ]);
    });
};

$tests['duplicate dataset type rejection'] = function (): void {
    expect_exception(K2ImportIdentityException::class, function (): void {
        K2ImportIdentity::normalizeSourceFiles([
            sample_file(K2ImportDatasetType::PR),
            sample_file(K2ImportDatasetType::PR, [
                'driveFileId' => 'drive-pr-duplicate',
                'sha256ContentHash' => str_repeat('e', 64),
            ]),
            sample_file(K2ImportDatasetType::PRICE),
        ]);
    });
};

$tests['valid state transitions'] = function (): void {
    $sequence = [
        [K2ImportBatchState::DETECTED, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::AON_QUEUED, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::AON_PROCESSING, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::AON_SUCCESS, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::PR_QUEUED, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::PR_PROCESSING, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::IMPORT_SUCCESS, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::ARCHIVE_PENDING, K2ImportArchiveState::PENDING],
        [K2ImportBatchState::ARCHIVE_PENDING, K2ImportArchiveState::PROCESSING],
        [K2ImportBatchState::COMPLETED, K2ImportArchiveState::COMPLETED],
    ];

    for ($i = 0; $i < count($sequence) - 1; $i++) {
        assert_true(K2ImportStateMachine::canTransition(
            $sequence[$i][0],
            $sequence[$i][1],
            $sequence[$i + 1][0],
            $sequence[$i + 1][1]
        ));
        K2ImportStateMachine::assertTransition(
            $sequence[$i][0],
            $sequence[$i][1],
            $sequence[$i + 1][0],
            $sequence[$i + 1][1]
        );
    }
};

$tests['invalid state transitions'] = function (): void {
    assert_false(K2ImportStateMachine::canTransition(
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::PR_PROCESSING,
        K2ImportArchiveState::NOT_READY
    ));

    // A queued attempt must be claimed (AON_QUEUED -> AON_PROCESSING) before it can
    // report success/failure; skipping straight from DETECTED/AON_SUCCESS into the
    // PROCESSING state would let an unclaimed attempt masquerade as claimed.
    assert_false(K2ImportStateMachine::canTransition(
        K2ImportBatchState::DETECTED,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::AON_PROCESSING,
        K2ImportArchiveState::NOT_READY
    ));
    assert_false(K2ImportStateMachine::canTransition(
        K2ImportBatchState::AON_SUCCESS,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::PR_PROCESSING,
        K2ImportArchiveState::NOT_READY
    ));
    assert_true(K2ImportStateMachine::canClaimForProcessing(K2ImportOperationType::AON, K2ImportBatchState::AON_QUEUED));
    assert_false(K2ImportStateMachine::canClaimForProcessing(K2ImportOperationType::AON, K2ImportBatchState::DETECTED));
    assert_true(K2ImportStateMachine::canClaimForProcessing(K2ImportOperationType::PR, K2ImportBatchState::PR_QUEUED));
    assert_false(K2ImportStateMachine::canClaimForProcessing(K2ImportOperationType::PR, K2ImportBatchState::AON_SUCCESS));

    expect_exception(K2ImportTransitionRejectedException::class, function (): void {
        K2ImportStateMachine::assertTransition(
            K2ImportBatchState::ARCHIVE_PENDING,
            K2ImportArchiveState::PENDING,
            K2ImportBatchState::PR_PROCESSING,
            K2ImportArchiveState::NOT_READY
        );
    });

    expect_exception(K2ImportTransitionRejectedException::class, function (): void {
        K2ImportStateMachine::assertTransition(
            K2ImportBatchState::COMPLETED,
            K2ImportArchiveState::COMPLETED,
            K2ImportBatchState::AON_PROCESSING,
            K2ImportArchiveState::NOT_READY
        );
    });
};

$tests['invalid batch archive state combinations'] = function (): void {
    foreach ([
        [K2ImportBatchState::DETECTED, K2ImportArchiveState::PENDING],
        [K2ImportBatchState::AON_QUEUED, K2ImportArchiveState::PROCESSING],
        [K2ImportBatchState::PR_QUEUED, K2ImportArchiveState::PENDING],
        [K2ImportBatchState::AON_PROCESSING, K2ImportArchiveState::PROCESSING],
        [K2ImportBatchState::AON_SUCCESS, K2ImportArchiveState::COMPLETED],
        [K2ImportBatchState::AON_FAILED_RETRYABLE, K2ImportArchiveState::FAILED_RETRYABLE],
        [K2ImportBatchState::AON_FAILED_FINAL, K2ImportArchiveState::FAILED_FINAL],
        [K2ImportBatchState::PR_PROCESSING, K2ImportArchiveState::PENDING],
        [K2ImportBatchState::PR_FAILED_RETRYABLE, K2ImportArchiveState::FAILED_RETRYABLE],
        [K2ImportBatchState::PR_FAILED_FINAL, K2ImportArchiveState::FAILED_FINAL],
        [K2ImportBatchState::IMPORT_SUCCESS, K2ImportArchiveState::PENDING],
        [K2ImportBatchState::ARCHIVE_PENDING, K2ImportArchiveState::NOT_READY],
        [K2ImportBatchState::ARCHIVE_PENDING, K2ImportArchiveState::COMPLETED],
        [K2ImportBatchState::COMPLETED, K2ImportArchiveState::NOT_READY],
    ] as [$batchState, $archiveState]) {
        assert_false(K2ImportStateMachine::isValidStatePair($batchState, $archiveState));
        expect_exception(K2ImportTransitionRejectedException::class, function () use ($batchState, $archiveState): void {
            K2ImportStateMachine::assertValidStatePair($batchState, $archiveState);
        });
    }
};

$tests['valid archive pending combinations'] = function (): void {
    foreach ([
        K2ImportArchiveState::PENDING,
        K2ImportArchiveState::PROCESSING,
        K2ImportArchiveState::FAILED_RETRYABLE,
        K2ImportArchiveState::FAILED_FINAL,
    ] as $archiveState) {
        assert_true(K2ImportStateMachine::isValidStatePair(
            K2ImportBatchState::ARCHIVE_PENDING,
            $archiveState
        ));
    }
};

$tests['completed requires archive completed'] = function (): void {
    assert_true(K2ImportStateMachine::isValidStatePair(
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));

    foreach ([
        K2ImportArchiveState::NOT_READY,
        K2ImportArchiveState::PENDING,
        K2ImportArchiveState::PROCESSING,
        K2ImportArchiveState::FAILED_RETRYABLE,
        K2ImportArchiveState::FAILED_FINAL,
    ] as $archiveState) {
        assert_false(K2ImportStateMachine::isValidStatePair(K2ImportBatchState::COMPLETED, $archiveState));
    }
};

$tests['transition compare and set inputs'] = function (): void {
    $method = new ReflectionMethod(K2ImportRunRepositoryInterface::class, 'transitionBatchState');
    assert_same(5, $method->getNumberOfParameters());
    assert_same('fromState', $method->getParameters()[1]->getName());
    assert_same('fromArchiveState', $method->getParameters()[2]->getName());
    assert_same('toState', $method->getParameters()[3]->getName());
    assert_same('toArchiveState', $method->getParameters()[4]->getName());

    assert_true(K2ImportStateMachine::canTransition(
        K2ImportBatchState::IMPORT_SUCCESS,
        K2ImportArchiveState::NOT_READY,
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::PENDING
    ));

    expect_exception(K2ImportTransitionRejectedException::class, function (): void {
        K2ImportStateMachine::canTransition(
            K2ImportBatchState::ARCHIVE_PENDING,
            K2ImportArchiveState::PENDING,
            K2ImportBatchState::COMPLETED,
            K2ImportArchiveState::NOT_READY
        );
    });

    assert_true(K2ImportStateMachine::canTransition(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::PROCESSING,
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));
};

$tests['AON-before-PR enforcement'] = function (): void {
    assert_false(K2ImportStateMachine::canStartOperation(
        K2ImportOperationType::PR,
        K2ImportBatchState::DETECTED
    ));

    expect_exception(K2ImportTransitionRejectedException::class, function (): void {
        K2ImportStateMachine::assertCanStartOperation(
            K2ImportOperationType::PR,
            K2ImportBatchState::DETECTED
        );
    });

    assert_true(K2ImportStateMachine::canStartOperation(
        K2ImportOperationType::PR,
        K2ImportBatchState::AON_SUCCESS
    ));
};

$tests['AON retry behavior'] = function (): void {
    assert_same(K2ImportOperationType::AON, K2ImportStateMachine::retryPhase(
        K2ImportBatchState::AON_FAILED_RETRYABLE
    ));
    assert_true(K2ImportStateMachine::mustRunAon(K2ImportBatchState::AON_FAILED_RETRYABLE));
    assert_true(K2ImportStateMachine::canStartOperation(
        K2ImportOperationType::AON,
        K2ImportBatchState::AON_FAILED_RETRYABLE
    ));
    assert_true(K2ImportStateMachine::isTerminal(K2ImportBatchState::AON_FAILED_FINAL));
    assert_same(null, K2ImportStateMachine::retryPhase(K2ImportBatchState::AON_FAILED_FINAL));
};

$tests['PR retry without repeating AON'] = function (): void {
    assert_same(K2ImportOperationType::PR, K2ImportStateMachine::retryPhase(
        K2ImportBatchState::PR_FAILED_RETRYABLE
    ));
    assert_false(K2ImportStateMachine::mustRunAon(K2ImportBatchState::PR_FAILED_RETRYABLE));
    assert_true(K2ImportStateMachine::mustRunPr(K2ImportBatchState::PR_FAILED_RETRYABLE));
    assert_true(K2ImportStateMachine::canStartOperation(
        K2ImportOperationType::PR,
        K2ImportBatchState::PR_FAILED_RETRYABLE
    ));
};

$tests['archive retry without re-import'] = function (): void {
    assert_same(K2ImportOperationType::ARCHIVE, K2ImportStateMachine::retryPhase(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::FAILED_RETRYABLE
    ));
    assert_true(K2ImportStateMachine::onlyArchiveShouldRun(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::FAILED_RETRYABLE
    ));
    assert_false(K2ImportStateMachine::mustRunAon(K2ImportBatchState::ARCHIVE_PENDING));
    assert_false(K2ImportStateMachine::mustRunPr(K2ImportBatchState::ARCHIVE_PENDING));
    assert_same(K2ImportOperationType::ARCHIVE, K2ImportStateMachine::nextRunnableOperation(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::FAILED_RETRYABLE
    ));
};

$tests['duplicate request handling'] = function (): void {
    assert_same(K2DuplicateRequestDecision::START_NEW, K2ImportStateMachine::duplicateRequestDecision(null));
    assert_same(K2DuplicateRequestDecision::IN_PROGRESS, K2ImportStateMachine::duplicateRequestDecision(
        K2ImportRequestStatus::RUNNING
    ));
    assert_same(K2DuplicateRequestDecision::REPLAY_SUCCESS, K2ImportStateMachine::duplicateRequestDecision(
        K2ImportRequestStatus::SUCCEEDED
    ));
    assert_same(K2DuplicateRequestDecision::REPLAY_SUCCESS, K2ImportStateMachine::duplicateRequestDecision(
        K2ImportRequestStatus::REPLAYED
    ));
    assert_same(K2DuplicateRequestDecision::RETRY_ALLOWED, K2ImportStateMachine::duplicateRequestDecision(
        K2ImportRequestStatus::FAILED_RETRYABLE
    ));
    assert_same(K2DuplicateRequestDecision::REJECT_FINAL_FAILURE, K2ImportStateMachine::duplicateRequestDecision(
        K2ImportRequestStatus::FAILED_FINAL
    ));
    expect_exception(InvalidArgumentException::class, function (): void {
        K2ImportStateMachine::duplicateRequestDecision(K2ImportAttemptStatus::STARTED);
    });
};

$tests['conflicting request idempotency key detection design'] = function (): void {
    $key = 'k2req_' . str_repeat('1', 64);
    $stored = [
        'id' => 10,
        'batch_id' => 20,
        'operation_type' => K2ImportOperationType::AON,
        'idempotency_key' => $key,
    ];

    K2ImportRepositoryGuards::assertOperationRequestMatchesSubmitted(
        $stored,
        20,
        K2ImportOperationType::AON,
        $key
    );

    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($stored, $key): void {
        K2ImportRepositoryGuards::assertOperationRequestMatchesSubmitted(
            $stored,
            21,
            K2ImportOperationType::AON,
            $key
        );
    });

    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($stored, $key): void {
        K2ImportRepositoryGuards::assertOperationRequestMatchesSubmitted(
            $stored,
            20,
            K2ImportOperationType::PR,
            $key
        );
    });
};

$tests['conflicting source metadata detection design'] = function (): void {
    $file = sample_file(K2ImportDatasetType::PR);
    $stored = sample_source_row($file);

    K2ImportRepositoryGuards::assertSourceFileMatchesSubmittedMetadata($stored, $file);

    $changedFilename = $stored;
    $changedFilename['original_filename'] = 'Different.xlsx';
    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($changedFilename, $file): void {
        K2ImportRepositoryGuards::assertSourceFileMatchesSubmittedMetadata($changedFilename, $file);
    });

    $changedHash = $stored;
    $changedHash['sha256_content_hash'] = str_repeat('f', 64);
    expect_exception(K2ImportDuplicateKeyConflictException::class, function () use ($changedHash, $file): void {
        K2ImportRepositoryGuards::assertSourceFileMatchesSubmittedMetadata($changedHash, $file);
    });
};

$tests['scheduled slot timestamp normalization'] = function (): void {
    $slot = new K2ImportScheduleMetadata('2026-07-22 08:30:15.123456', 'Asia/Jakarta');

    assert_same('2026-07-22T01:30:15.123456Z', $slot->scheduledSlotAtUtc);
    assert_same('2026-07-22 01:30:15.123456', $slot->scheduledSlotForSql());
    assert_same('Asia/Jakarta', $slot->scheduleTimezone);

    $defaultTimezoneSlot = new K2ImportScheduleMetadata('2026-07-22 08:30:15');
    assert_same(K2ImportScheduleMetadata::DEFAULT_OPERATIONAL_TIMEZONE, $defaultTimezoneSlot->scheduleTimezone);

    expect_exception(K2ImportIdentityException::class, function (): void {
        new K2ImportScheduleMetadata(null, 'Asia/Jakarta');
    });
};

$tests['completed batch terminal behavior'] = function (): void {
    assert_true(K2ImportStateMachine::isTerminal(
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));
    assert_same(null, K2ImportStateMachine::nextRunnableOperation(
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));
    assert_false(K2ImportStateMachine::canStartOperation(
        K2ImportOperationType::ARCHIVE,
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));
};

$tests['failed batch classification'] = function (): void {
    assert_true(K2ImportStateMachine::isFailed(K2ImportBatchState::AON_FAILED_RETRYABLE));
    assert_true(K2ImportStateMachine::isFailed(K2ImportBatchState::PR_FAILED_FINAL));
    assert_true(K2ImportStateMachine::isFailed(
        K2ImportBatchState::ARCHIVE_PENDING,
        K2ImportArchiveState::FAILED_FINAL
    ));
    assert_false(K2ImportStateMachine::isFailed(
        K2ImportBatchState::COMPLETED,
        K2ImportArchiveState::COMPLETED
    ));
};

$tests['retryable versus non-retryable errors'] = function (): void {
    assert_same(K2ImportBatchState::AON_FAILED_RETRYABLE, K2ImportStateMachine::failureStateFor(
        K2ImportOperationType::AON,
        true
    ));
    assert_same(K2ImportBatchState::AON_FAILED_FINAL, K2ImportStateMachine::failureStateFor(
        K2ImportOperationType::AON,
        false
    ));
    assert_same(K2ImportBatchState::PR_FAILED_RETRYABLE, K2ImportStateMachine::failureStateFor(
        K2ImportOperationType::PR,
        true
    ));
    assert_same(K2ImportBatchState::PR_FAILED_FINAL, K2ImportStateMachine::failureStateFor(
        K2ImportOperationType::PR,
        false
    ));
    assert_same(K2ImportArchiveState::FAILED_RETRYABLE, K2ImportStateMachine::archiveFailureStateFor(true));
    assert_same(K2ImportArchiveState::FAILED_FINAL, K2ImportStateMachine::archiveFailureStateFor(false));
    assert_true(K2ImportStateMachine::isRetryableFailure(K2ImportBatchState::PR_FAILED_RETRYABLE));
    assert_true(K2ImportStateMachine::isNonRetryableFailure(K2ImportBatchState::PR_FAILED_FINAL));
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

echo count($tests) . " test(s) passed.\n";

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

    $hashChars = [
        K2ImportDatasetType::PR => 'a',
        K2ImportDatasetType::CATALOG => 'b',
        K2ImportDatasetType::PRICE => 'c',
    ];

    $filenames = [
        K2ImportDatasetType::PR => 'Report Ticket Monitoring GP Officer.xlsx',
        K2ImportDatasetType::CATALOG => 'Master Item Catalog.xlsx',
        K2ImportDatasetType::PRICE => 'Price Per Vendor And Tiering.xlsx',
    ];

    $data = array_merge([
        'datasetType' => $datasetType,
        'driveFileId' => 'drive-' . $datasetType . '-001',
        'originalFilename' => $filenames[$datasetType],
        'modifiedTimestamp' => sprintf('2026-07-22T01:0%d:00+07:00', $index),
        'fileSizeBytes' => 1000 + $index,
        'sha256ContentHash' => str_repeat($hashChars[$datasetType], 64),
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

function sample_source_row(K2ImportSourceFileMetadata $file): array
{
    return [
        'id' => 1,
        'dataset_type' => $file->datasetType,
        'drive_file_id' => $file->driveFileId,
        'original_filename' => $file->originalFilename,
        'drive_modified_at' => $file->modifiedTimestampForSql(),
        'file_size_bytes' => (string) $file->fileSizeBytes,
        'sha256_content_hash' => $file->sha256ContentHash,
        'file_identity_hash' => K2ImportIdentity::fileIdentityHash($file),
        'content_identity_hash' => K2ImportIdentity::contentIdentityHash($file),
    ];
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

function assert_not_same(mixed $left, mixed $right): void
{
    if ($left === $right) {
        throw new RuntimeException('Assertion failed. Values should differ: ' . var_export($left, true));
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
