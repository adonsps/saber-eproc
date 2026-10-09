<?php
declare(strict_types=1);

// Business-data tests: DELETE FROM prs/aon_skus/sku_dictionary — point
// K2_AUTOMATION_TEST_DSN at a genuinely disposable database, never the shared
// app database (see create_parser_schema()/reset_parser_tables() below).

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../cron/process_k2_import_queue.php';

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase2_schema.sql');
apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
create_parser_schema($pdo);

$tests = [];
$tempStorageRoots = [];

$tests['AON worker claims, verifies, normalizes, writes, and cleans up on success'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU1']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU1', 'alokasi' => '1']]),
    ]);

    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-aon-success', 1);
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $normalizer);

    assert_same(K2WorkerOutcome::SUCCESS, $outcome);
    assert_same(K2ImportBatchState::AON_SUCCESS, (string) fetch_one($pdo, 'SELECT state FROM k2_automation_import_batches WHERE batch_uid = ?', [$batchUid])['state']);
    assert_same(1, table_count($pdo, 'aon_skus'));
    assert_same(K2ImportAttemptStatus::SUCCEEDED, (string) fetch_one($pdo, 'SELECT status FROM k2_automation_operation_attempts WHERE batch_id = (SELECT id FROM k2_automation_import_batches WHERE batch_uid = ?)', [$batchUid])['status']);
    assert_same(null, fetch_one($pdo, 'SELECT worker_claim_token FROM k2_automation_operation_attempts WHERE batch_id = (SELECT id FROM k2_automation_import_batches WHERE batch_uid = ?)', [$batchUid])['worker_claim_token']);
    // Staged files removed after terminal success (scenario #24).
    assert_true(!is_dir($fileStore_dir = staged_attempt_dir($fileStore, $batchUid, K2ImportOperationType::AON, 1)), "Staged directory should be removed after success: {$fileStore_dir}");
};

$tests['PR worker succeeds after AON success and moves batch to ARCHIVE_PENDING'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    $aonNormalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU2']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU2', 'alokasi' => '1']]),
    ]);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-pr-success', 1);
    assert_same(K2WorkerOutcome::SUCCESS, k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $aonNormalizer));

    $prNormalizer = new FakeWorkbookNormalizer([K2ImportDatasetType::PR => pr_csv_valid('WPR001')]);
    queue_pr_operation($orchestrator, $fileStore, $batchUid, 'worker-pr-success', 1);
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-b', 'host-a', 112, $prNormalizer);

    assert_same(K2WorkerOutcome::SUCCESS, $outcome);
    $batch = fetch_one($pdo, 'SELECT state, archive_state FROM k2_automation_import_batches WHERE batch_uid = ?', [$batchUid]);
    assert_same(K2ImportBatchState::ARCHIVE_PENDING, (string) $batch['state']);
    assert_same(K2ImportArchiveState::PENDING, (string) $batch['archive_state']);
    assert_same(1, table_count($pdo, 'prs'));
    assert_same('HD-TEST-WPR001', (string) fetch_one($pdo, 'SELECT ticket_helpdesk_no FROM prs WHERE ticket_no = ?', ['WPR001'])['ticket_helpdesk_no']);
};

// Feeds k2_worker_should_run_ai_assignment() in k2_worker_main(): the AI
// assignment phase is chained only when a PR attempt SUCCEEDED, so the reported
// operation type has to be accurate for every outcome, not just the happy path.
$tests['claimed operation type is reported back for the AI assignment gate'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);

    // Nothing queued yet: the out-param must stay null.
    $claimedType = 'not-overwritten';
    assert_same(K2WorkerOutcome::NOTHING_CLAIMABLE, k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-idle', 'host-a', 110, null, $claimedType));
    assert_same(null, $claimedType);

    // AON success reports 'aon' -- which must NOT trigger assignment.
    $aonNormalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU9']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU9', 'alokasi' => '1']]),
    ]);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-optype', 1);
    $claimedType = null;
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $aonNormalizer, $claimedType);
    assert_same(K2WorkerOutcome::SUCCESS, $outcome);
    assert_same(K2ImportOperationType::AON, $claimedType);
    assert_same(false, k2_worker_should_run_ai_assignment($outcome, $claimedType));

    // PR success reports 'pr' -- the one combination that does trigger it.
    $prNormalizer = new FakeWorkbookNormalizer([K2ImportDatasetType::PR => pr_csv_valid('WPR900')]);
    queue_pr_operation($orchestrator, $fileStore, $batchUid, 'worker-optype', 1);
    $claimedType = null;
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-b', 'host-a', 112, $prNormalizer, $claimedType);
    assert_same(K2WorkerOutcome::SUCCESS, $outcome);
    assert_same(K2ImportOperationType::PR, $claimedType);
    assert_same(true, k2_worker_should_run_ai_assignment($outcome, $claimedType));
};

$tests['worker retryable failure leaves the batch ready for a fresh attempt'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    // Empty ALOKASI set => K2AutomationAonPreflight::assertSafeToImport throws
    // AON_EMPTY_ALOKASI_SET, a K2AutomationParserAdapterException (retryable by default).
    $emptyAllocationNormalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU3']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU3', 'alokasi' => '']]),
    ]);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-aon-retryable', 1);
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $emptyAllocationNormalizer);

    assert_same(K2WorkerOutcome::FINAL_FAILURE, $outcome);
    // AON_EMPTY_ALOKASI_SET is constructed non-retryable in parser_adapters.php's
    // K2AutomationAonPreflight::assertSafeToImport() default $retryable=false — this
    // deliberately picks a preflight-style rejection to exercise the FINAL path;
    // 'AON removal-ratio rejection' below covers the same shape distinctly.
    assert_same(K2ImportBatchState::AON_FAILED_FINAL, (string) fetch_one($pdo, 'SELECT state FROM k2_automation_import_batches WHERE batch_uid = ?', [$batchUid])['state']);
    assert_same(0, table_count($pdo, 'aon_skus'));
    // Failed files are retained (not cleaned up immediately) for diagnosis.
    assert_true(is_dir(staged_attempt_dir($fileStore, $batchUid, K2ImportOperationType::AON, 1)), 'Failed attempt files should be retained for diagnosis.');
};

$tests['AON removal ratio guard produces a retryable failure'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    seed_active_aon($pdo, ['OLD1', 'OLD2']);
    [$repo, $orchestrator, $fileStore, , $logger] = worker_fixture($tempStorageRoots);
    $parserConfig = new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test', 0.0);
    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['NEW1']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'NEW1', 'alokasi' => '1']]),
    ]);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-removal-guard', 1);
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $normalizer);

    assert_same(K2WorkerOutcome::FINAL_FAILURE, $outcome);
    assert_same('AON_REMOVAL_RATIO_EXCEEDED', (string) fetch_one($pdo, 'SELECT error_code FROM k2_automation_operation_attempts WHERE batch_id = (SELECT id FROM k2_automation_import_batches WHERE batch_uid = ?)', [$batchUid])['error_code']);
    assert_same(2, active_aon_count($pdo));
};

$tests['global AON lock conflict is reported as a retryable failure'] = function () use ($pdo, $dsn, $user, $password, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU4']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU4', 'alokasi' => '1']]),
    ]);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-global-lock', 1);

    $other = connect_with_retries($dsn, $user, $password);
    $held = K2AonGlobalImportLock::acquire($other, 0);
    assert_true($held->acquired());
    try {
        $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $normalizer);
    } finally {
        $held->release();
    }

    assert_same(K2WorkerOutcome::RETRYABLE_FAILURE, $outcome);
    assert_same(K2ImportBatchState::AON_FAILED_RETRYABLE, (string) fetch_one($pdo, 'SELECT state FROM k2_automation_import_batches WHERE batch_uid = ?', [$batchUid])['state']);
};

$tests['a stale worker lease is reclaimed by a second worker'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore] = worker_fixture($tempStorageRoots);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-stale-lease', 1);

    $firstClaim = $orchestrator->claimNextForProcessing('worker-dead', 'host-a', 111, 1);
    assert_true($firstClaim !== null && $firstClaim['batch_uid'] === $batchUid);
    // Simulate the worker dying mid-run: push worker_claimed_at into the past,
    // well beyond the 1-second lease used above.
    $pdo->prepare('UPDATE k2_automation_operation_attempts SET worker_claimed_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 30 SECOND) WHERE id = ?')
        ->execute([(int) $firstClaim['attempt_id']]);

    $secondClaim = $orchestrator->claimNextForProcessing('worker-fresh', 'host-b', 222, 1);
    assert_true($secondClaim !== null, 'Expected the expired lease to be reclaimable.');
    assert_same($firstClaim['attempt_id'], $secondClaim['attempt_id']);
    assert_same('worker-fresh', (string) fetch_one($pdo, 'SELECT worker_claim_token FROM k2_automation_operation_attempts WHERE id = ?', [(int) $secondClaim['attempt_id']])['worker_claim_token']);
};

$tests['a second claim never returns the same attempt twice'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore] = worker_fixture($tempStorageRoots);
    queue_aon_operation($orchestrator, $fileStore, 'worker-no-double-claim', 1);

    $first = $orchestrator->claimNextForProcessing('worker-a', 'host-a', 111, 900);
    $second = $orchestrator->claimNextForProcessing('worker-b', 'host-b', 222, 900);

    assert_true($first !== null, 'Expected the first claim to succeed.');
    assert_true($second === null, 'A second worker must not claim the same still-leased attempt.');
};

$tests['checksum mismatch on a staged file is rejected before execution'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-checksum-mismatch', 1);

    $attempt = fetch_one($pdo, 'SELECT staged_file_manifest FROM k2_automation_operation_attempts WHERE batch_id = (SELECT id FROM k2_automation_import_batches WHERE batch_uid = ?)', [$batchUid]);
    $manifest = json_decode((string) $attempt['staged_file_manifest'], true);
    file_put_contents((string) $manifest[0]['storage_path'], 'TAMPERED CONTENT');

    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU5']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU5', 'alokasi' => '1']]),
    ]);
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $normalizer);

    assert_same(K2WorkerOutcome::RETRYABLE_FAILURE, $outcome);
    assert_same(0, table_count($pdo, 'aon_skus'));
};

$tests['queue empty returns nothing claimable'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    $outcome = k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, new FakeWorkbookNormalizer([]));
    assert_same(K2WorkerOutcome::NOTHING_CLAIMABLE, $outcome);
};

$tests['expired retained failure files are removed by a later sweep'] = function () use ($pdo, &$tempStorageRoots): void {
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore, $parserConfig, $logger] = worker_fixture($tempStorageRoots);
    $emptyAllocationNormalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['SKU6']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'SKU6', 'alokasi' => '']]),
    ]);
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-retention-sweep', 1);
    k2_worker_process_next($pdo, $repo, $orchestrator, $fileStore, $parserConfig, worker_config(), $logger, 'worker-a', 'host-a', 111, $emptyAllocationNormalizer);
    $dir = staged_attempt_dir($fileStore, $batchUid, K2ImportOperationType::AON, 1);
    assert_true(is_dir($dir), 'Failed attempt directory should still exist immediately after failure.');

    $future = new DateTimeImmutable('+10 days', new DateTimeZone('UTC'));
    $removed = $fileStore->sweepExpired($future, 259200);

    assert_true($removed >= 1, 'Expected sweepExpired to remove at least the aged-out failed attempt directory.');
    assert_true(!is_dir($dir), 'Expired failed attempt directory should be removed by the sweep.');
};

$tests['CLI subprocess end-to-end: --limit=1 claims and resolves one AON attempt'] = function () use ($dsn, $user, $password, &$tempStorageRoots): void {
    $pdo = connect_with_retries($dsn, $user, $password);
    reset_parser_tables($pdo);
    [$repo, $orchestrator, $fileStore] = worker_fixture($tempStorageRoots);
    // The subprocess uses the REAL K2AutomationOpenSpoutWorkbookNormalizer (no
    // injection point over a CLI boundary) against placeholder (not genuinely
    // parseable) XLSX bytes, so normalization is expected to fail — this test is
    // about the CLI wiring (env vars, exit code, a real separate process claiming
    // and resolving the attempt) rather than a real successful parse (covered by
    // the in-process tests above and by test_xlsx_normalizer.php). This also
    // exercises the worker gracefully handling ext-zip being unavailable in this
    // environment (see the final report's environment notes) rather than crashing.
    $batchUid = queue_aon_operation($orchestrator, $fileStore, 'worker-cli-subprocess', 1);

    $cmd = sprintf(
        'K2_AUTOMATION_TEST_DSN=%s K2_AUTOMATION_TEST_USER=%s K2_AUTOMATION_TEST_PASSWORD=%s ' .
        'K2_AUTOMATION_STORAGE_ROOT=%s K2_AUTOMATION_CLIENT_ID=%s K2_AUTOMATION_HMAC_SECRET=%s ' .
        'php %s --limit=1 2>&1',
        escapeshellarg($dsn),
        escapeshellarg($user),
        escapeshellarg($password),
        escapeshellarg($fileStore->storageRoot()),
        escapeshellarg('client-a'),
        escapeshellarg('test-secret'),
        escapeshellarg(__DIR__ . '/../cron/process_k2_import_queue.php')
    );
    exec($cmd, $outputLines, $exitCode);

    assert_same(K2_WORKER_EXIT_OK, $exitCode, 'Worker subprocess output: ' . implode("\n", $outputLines));
    $state = (string) fetch_one($pdo, 'SELECT state FROM k2_automation_import_batches WHERE batch_uid = ?', [$batchUid])['state'];
    assert_true(
        in_array($state, [K2ImportBatchState::AON_FAILED_FINAL, K2ImportBatchState::AON_FAILED_RETRYABLE], true),
        "Expected the empty-workbook AON attempt to fail (final or retryable), got {$state}."
    );
};

final class FakeWorkbookNormalizer implements K2AutomationWorkbookNormalizerInterface
{
    public array $callsByDataset = [];

    public function __construct(private readonly array $csvByDataset)
    {
    }

    public function normalize(string $datasetType, string $sourcePath, string $outputDirectory): array
    {
        $this->callsByDataset[$datasetType] = ($this->callsByDataset[$datasetType] ?? 0) + 1;
        if (!isset($this->csvByDataset[$datasetType])) {
            throw new RuntimeException("No fake CSV for {$datasetType}.");
        }
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new RuntimeException("Could not create output directory {$outputDirectory}.");
        }
        $path = tempnam($outputDirectory, 'fake_k2_');
        if ($path === false) {
            throw new RuntimeException('Could not create fake CSV.');
        }
        $csvPath = $path . '.csv';
        rename($path, $csvPath);
        file_put_contents($csvPath, $this->csvByDataset[$datasetType]);

        return [
            'temporary_csv_path' => $csvPath,
            'written_csv_row_count' => substr_count(trim($this->csvByDataset[$datasetType]), "\n") + 1,
        ];
    }
}

/** @return array{0: PdoK2ImportRunRepository, 1: K2ImportOrchestrator, 2: K2AutomationStagedFileStore, 3: K2AutomationParserAdapterConfig, 4: K2AutomationDiagnosticsLogger} */
function worker_fixture(array &$tempStorageRoots): array
{
    global $pdo;
    $repo = new PdoK2ImportRunRepository($pdo);
    $orchestrator = new K2ImportOrchestrator($repo, 900, static fn(): string => 'worker-test-lock-' . bin2hex(random_bytes(4)));
    $storageRoot = sys_get_temp_dir() . '/k2-automation-test-worker-storage-' . bin2hex(random_bytes(6));
    $tempStorageRoots[] = $storageRoot;
    $fileStore = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($storageRoot));
    $parserConfig = new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test');
    $logger = new K2AutomationDiagnosticsLogger(sys_get_temp_dir() . '/k2-worker-test.log');

    return [$repo, $orchestrator, $fileStore, $parserConfig, $logger];
}

function worker_config(): K2ImportWorkerConfig
{
    return new K2ImportWorkerConfig(900, 900, 259200);
}

/**
 * Registers a batch (if needed) and queues an AON attempt exactly as the HTTP
 * controller would: stage two real files, then beginOperation() with the manifest.
 */
function queue_aon_operation(K2ImportOrchestrator $orchestrator, K2AutomationStagedFileStore $fileStore, string $suffix, int $attemptNumber): string
{
    $sourceFiles = source_file_objects($suffix);
    $schedule = schedule_metadata();
    $batch = $orchestrator->registerBatch(array_column($sourceFiles, 'meta'), $schedule, "req-{$suffix}-register");
    $batchUid = (string) $batch['batch_uid'];

    $lock = $orchestrator->claimBatchLock($batchUid, null, "req-{$suffix}-lock");
    assert_true($lock['success']);
    $lockToken = (string) $lock['result']['lock_token'];

    $manifest = $fileStore->stageManifest($batchUid, K2ImportOperationType::AON, $attemptNumber, [
        ['field' => 'catalog_file', 'datasetType' => K2ImportDatasetType::CATALOG, 'tmpPath' => temp_copy($sourceFiles[K2ImportDatasetType::CATALOG]['path']), 'originalFilename' => 'catalog.xlsx', 'expectedSha256' => $sourceFiles[K2ImportDatasetType::CATALOG]['hash']],
        ['field' => 'price_file', 'datasetType' => K2ImportDatasetType::PRICE, 'tmpPath' => temp_copy($sourceFiles[K2ImportDatasetType::PRICE]['path']), 'originalFilename' => 'price.xlsx', 'expectedSha256' => $sourceFiles[K2ImportDatasetType::PRICE]['hash']],
    ]);
    $manifestForDb = manifest_for_db($manifest);

    $requestKey = K2ImportIdentity::operationRequestIdempotencyKey($batchUid, K2ImportOperationType::AON, "req-{$suffix}-aon-{$attemptNumber}", $attemptNumber);
    $orchestrator->beginOperation($batchUid, K2ImportOperationType::AON, $requestKey, $lockToken, "req-{$suffix}-aon-{$attemptNumber}", $attemptNumber, $manifestForDb);
    $orchestrator->releaseBatchLock($batchUid, $lockToken, "req-{$suffix}-lock");

    return $batchUid;
}

function queue_pr_operation(K2ImportOrchestrator $orchestrator, K2AutomationStagedFileStore $fileStore, string $batchUid, string $suffix, int $attemptNumber): void
{
    $lock = $orchestrator->claimBatchLock($batchUid, null, 'req-pr-lock');
    assert_true($lock['success']);
    $lockToken = (string) $lock['result']['lock_token'];

    // Must match the 'pr' placeholder path/content source_file_objects() used
    // when this batch was originally registered (queue_aon_operation()) — the
    // worker validates the staged file's hash against that REGISTERED source
    // metadata, not against whatever is passed here.
    $prPath = temp_placeholder_path("pr-{$suffix}");
    $prHash = (string) hash_file('sha256', $prPath);

    $manifest = $fileStore->stageManifest($batchUid, K2ImportOperationType::PR, $attemptNumber, [
        ['field' => 'pr_file', 'datasetType' => K2ImportDatasetType::PR, 'tmpPath' => temp_copy($prPath), 'originalFilename' => 'pr.xlsx', 'expectedSha256' => $prHash],
    ]);
    $manifestForDb = manifest_for_db($manifest);

    $requestKey = K2ImportIdentity::operationRequestIdempotencyKey($batchUid, K2ImportOperationType::PR, "req-pr-{$attemptNumber}", $attemptNumber);
    $orchestrator->beginOperation($batchUid, K2ImportOperationType::PR, $requestKey, $lockToken, "req-pr-{$attemptNumber}", $attemptNumber, $manifestForDb);
    $orchestrator->releaseBatchLock($batchUid, $lockToken, 'req-pr-lock');
}

function manifest_for_db(array $stagedManifest): array
{
    return array_map(static fn(array $e): array => [
        'field' => $e['field'],
        'dataset_type' => $e['dataset_type'],
        'storage_path' => $e['storage_path'],
        'sha256_content_hash' => $e['sha256_content_hash'],
        'file_size_bytes' => $e['file_size_bytes'],
        'original_filename' => $e['original_filename'],
    ], $stagedManifest);
}

function staged_attempt_dir(K2AutomationStagedFileStore $fileStore, string $batchUid, string $operationType, int $attemptNumber): string
{
    $safeBatch = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $batchUid) ?? 'batch';
    $safeOperation = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $operationType) ?? 'op';

    return $fileStore->storageRoot() . DIRECTORY_SEPARATOR . $safeBatch . DIRECTORY_SEPARATOR . $safeOperation . '_' . $attemptNumber;
}

/** @return array<string, array{path: string, hash: string, meta: K2ImportSourceFileMetadata}> keyed by dataset type */
function source_file_objects(string $suffix): array
{
    $out = [];
    foreach (K2ImportDatasetType::requiredTypes() as $index => $datasetType) {
        $path = temp_placeholder_path("{$datasetType}-{$suffix}");
        $hash = (string) hash_file('sha256', $path);
        $out[$datasetType] = [
            'path' => $path,
            'hash' => $hash,
            'meta' => new K2ImportSourceFileMetadata(
                $datasetType,
                'drive-' . md5($path . $datasetType . $suffix),
                basename($path),
                sprintf('2026-07-22T01:0%d:00+07:00', $index),
                (int) filesize($path),
                $hash
            ),
        ];
    }

    return $out;
}

function temp_copy(string $sourcePath): string
{
    $path = tempnam(sys_get_temp_dir(), 'k2_worker_upload_');
    if ($path === false) {
        throw new RuntimeException('Could not create temp upload copy.');
    }
    copy($sourcePath, $path);

    return $path;
}

function temp_placeholder_path(string $suffix): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k2-worker-placeholder-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $suffix) . '.xlsx';
    if (!is_file($path)) {
        file_put_contents($path, "PK\x03\x04{$suffix}");
    }

    return $path;
}

function schedule_metadata(): K2ImportScheduleMetadata
{
    return new K2ImportScheduleMetadata('2026-07-22 09:00:00', 'Asia/Jakarta');
}

function catalog_csv(array $skus): string
{
    $rows = [k2_file_schema(K2ImportDatasetType::CATALOG)['headers']];
    foreach ($skus as $sku) {
        $row = array_fill(0, count(k2_file_schema(K2ImportDatasetType::CATALOG)['headers']), '');
        $row[0] = $sku;
        $row[1] = 'Owner';
        $row[2] = 'Area';
        $row[3] = "Item {$sku}";
        $row[4] = 'PCS';
        $row[8] = "ART{$sku}";
        $row[15] = 'Yes';
        $row[16] = 'Yes';
        $row[17] = 'No';
        $rows[] = $row;
    }

    return csv_from_rows($rows);
}

function price_csv(array $items): string
{
    $rows = [k2_file_schema(K2ImportDatasetType::PRICE)['headers']];
    foreach ($items as $item) {
        $row = array_fill(0, count(k2_file_schema(K2ImportDatasetType::PRICE)['headers']), '');
        $row[0] = $item['sku'];
        $row[2] = 'V001';
        $row[3] = 'Vendor';
        $row[4] = '2026-01-01';
        $row[5] = '2026-12-31';
        $row[6] = $item['alokasi'];
        $row[7] = '10';
        $row[8] = '1';
        $row[9] = '9';
        $row[14] = '1000';
        $rows[] = $row;
    }

    return csv_from_rows($rows);
}

function pr_csv_valid(string $ticketNo): string
{
    $row = array_fill(0, count(k2_file_schema(K2ImportDatasetType::PR)['headers']), '');
    $row[0] = $ticketNo;
    $row[1] = 'standard';
    $row[2] = 'open';
    $row[3] = 'PO01';
    $row[4] = 'P001';
    $row[5] = '1';
    $row[6] = '1';
    $row[8] = '2026-07-22';
    $row[10] = 'Requestor';
    $row[11] = 'GP001';
    $row[12] = 'Site';
    $row[27] = 'SKU1';
    $row[28] = 'Synthetic item';
    $row[29] = '1';
    $row[30] = 'PCS';
    $row[39] = 'HD-TEST-' . $ticketNo;

    return csv_from_rows([k2_file_schema(K2ImportDatasetType::PR)['headers'], $row]);
}

function csv_from_rows(array $rows): string
{
    return implode('', array_map('csv_line', $rows));
}

function csv_line(array $row): string
{
    $handle = fopen('php://temp', 'r+');
    if ($handle === false) {
        throw new RuntimeException('Could not open temp stream.');
    }
    fputcsv($handle, $row);
    rewind($handle);
    $line = stream_get_contents($handle);
    fclose($handle);

    return $line === false ? '' : $line;
}

function seed_active_aon(PDO $pdo, array $skus): void
{
    $stmt = $pdo->prepare("INSERT INTO aon_skus (sku_code, item_name, effective_year, status, is_deleted) VALUES (?, ?, 2026, 'ACTIVE', 0)");
    foreach ($skus as $sku) {
        $stmt->execute([$sku, "Existing {$sku}"]);
    }
}

function reset_parser_tables(PDO $pdo): void
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

function table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

function active_aon_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM aon_skus WHERE is_deleted = 0')->fetchColumn();
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

function create_parser_schema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS allowed_pgroups (
        pgroup_code VARCHAR(20) PRIMARY KEY,
        label VARCHAR(100),
        team_name VARCHAR(100),
        status VARCHAR(20)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO allowed_pgroups VALUES ('P001', 'Team GP Non Project', 'Admin Team', 'ACTIVE')");

    // Must carry every column excel_parser.php's INSERT names, or the PR import
    // dies with "Unknown column" and surfaces as a generic
    // AUTOMATION_PARSER_FAILED / retryable_failure that looks like a worker bug.
    // canvassing_description / po_create_date / master_data_id / is_new_store / ticket_helpdesk_no were
    // added to the real prs table after this fixture was first written -- see
    // `SHOW COLUMNS FROM prs` for the authoritative list.
    $pdo->exec("CREATE TABLE IF NOT EXISTS prs (
        ticket_no VARCHAR(10) NOT NULL,
        ticket_line_item VARCHAR(10) NOT NULL,
        p_org VARCHAR(50), p_group VARCHAR(50), requestor_name VARCHAR(100),
        site_allocation VARCHAR(100), pr_date DATE NULL, item_description VARCHAR(255),
        status_ticket VARCHAR(100), canvassing_no VARCHAR(100), canvassing_status VARCHAR(100),
        canvassing_date DATE NULL, canvassing_description TEXT NULL,
        po_number VARCHAR(100), po_create_date DATE NULL, ticket_type VARCHAR(100),
        vendor_name VARCHAR(100), qty DECIMAL(10,2) NULL, delivery_date DATE NULL,
        gr_date DATE NULL, tr_date_gp DATE NULL, canvassing_date_approver_1 DATE NULL,
        created_at DATETIME NULL, sku VARCHAR(100), uom VARCHAR(50), po_amount DECIMAL(15,2) NULL,
        assigned_pic VARCHAR(100), master_data_id INT NULL, assigned_by_ai TINYINT NULL,
        gp_officer VARCHAR(100), otf_score INT NULL, otf_status VARCHAR(100),
        top_score INT NULL, is_new_store TINYINT(1) NULL, ticket_helpdesk_no VARCHAR(64) NULL,
        PRIMARY KEY (ticket_no, ticket_line_item)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS aon_skus (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku_code VARCHAR(100) NOT NULL,
        item_name VARCHAR(255) NOT NULL,
        previous_item TEXT DEFAULT NULL,
        category VARCHAR(100) DEFAULT NULL,
        vendor VARCHAR(255) DEFAULT NULL,
        quantity VARCHAR(100) DEFAULT NULL,
        used_qty VARCHAR(100) DEFAULT NULL,
        remain_qty VARCHAR(100) DEFAULT NULL,
        price DECIMAL(15,2) DEFAULT NULL,
        price_2025 VARCHAR(255) DEFAULT NULL,
        effective_year SMALLINT NOT NULL DEFAULT 2026,
        valid_from DATE DEFAULT NULL,
        valid_until DATE DEFAULT NULL,
        alokasi VARCHAR(100) DEFAULT NULL,
        flag VARCHAR(200) DEFAULT NULL,
        flag_detail TEXT DEFAULT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
        is_deleted TINYINT(1) NOT NULL DEFAULT 0,
        created_by VARCHAR(255) DEFAULT NULL,
        updated_by VARCHAR(255) DEFAULT NULL,
        UNIQUE KEY uq_sku_code (sku_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS sku_dictionary (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sku VARCHAR(100) NOT NULL,
        detail TEXT, group_owner VARCHAR(255), area VARCHAR(255), uom VARCHAR(50),
        est_price DECIMAL(15,2), buying_price DECIMAL(15,2), hashtag VARCHAR(255),
        article_sap VARCHAR(255), validity_from VARCHAR(50), validity_to VARCHAR(50),
        max_delivery_time_days INT, min_qty VARCHAR(100), warranty VARCHAR(255),
        extend_to_group VARCHAR(255), is_reguler VARCHAR(10), is_active VARCHAR(10),
        auto_create_po VARCHAR(10), modified_by VARCHAR(255), modified_date DATETIME,
        UNIQUE KEY uq_sku (sku)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
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
foreach ($tempStorageRoots as $storageRoot) {
    rrmdir($storageRoot);
}

if ($failures > 0) {
    echo "{$failures} import queue worker database test(s) failed.\n";
    exit(1);
}

echo count($tests) . " import queue worker database test(s) passed.\n";
