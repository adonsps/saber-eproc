<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/api_controller.php';
require_once __DIR__ . '/../includes/automation/parser_adapters.php';

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
if ((getenv('K2_AUTOMATION_TEST_SCHEMA') ?: '') === 'fresh') {
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_fresh_install_schema.sql');
} else {
    // phase2_schema.sql already includes what phase7 would add (see
    // test_api_controller_db.php's identical note); only phase8 is needed here.
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase2_schema.sql');
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
}
create_parser_schema($pdo);

$tests = [];
$tempFiles = [];

$tests['PR preflight streams large CSV under Phase 8 memory limit'] = function () use ($pdo): void {
    reset_parser_tables($pdo);
    $csv = large_pr_preflight_csv(70000);
    $previousLimit = ini_get('memory_limit');
    try {
        ini_set('memory_limit', '256M');
        $peakBefore = memory_get_peak_usage(true);
        $preflight = K2AutomationPrPreflight::analyze($pdo, $csv);
        $peakDelta = memory_get_peak_usage(true) - $peakBefore;

        assert_same(70000, (int) $preflight['row_count']);
        assert_same(70000, (int) $preflight['valid_ticket_count']);
        assert_same(0, (int) $preflight['duplicate_ticket_line_identity_count']);
        assert_true($peakDelta < 64 * 1024 * 1024, 'Streaming preflight exceeded the expected memory envelope.');
    } finally {
        if ($previousLimit !== false) {
            ini_set('memory_limit', $previousLimit);
        }
        @unlink($csv);
    }
};

$tests['manual tolerant PR behavior and result keys remain available'] = function () use ($pdo): void {
    reset_parser_tables($pdo);
    $csv = temp_csv(pr_csv_with_bad_second_row());
    try {
        K2AutomationParserAdapterSupport::loadPrParser($pdo);
        $result = k2_parse_and_insert_csv_internal($csv, 'manual.csv', 'manual-user', ['strict' => false]);
        assert_same(1, (int) $result['inserted']);
        assert_same(1, (int) $result['skipped']);
        foreach (['total_rows', 'inserted', 'updated', 'skipped', 'ai_tickets', 'ai_lines', 'warning_count'] as $key) {
            assert_true(array_key_exists($key, $result), "Missing result key {$key}.");
        }
        assert_same(1, table_count($pdo, 'prs'));
    } finally {
        @unlink($csv);
    }
};

$tests['strict automated PR failure rolls back'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    $upload = temp_xlsx('pr-upload.xlsx', $tempFiles);
    $normalizer = new FakeWorkbookNormalizer([K2ImportDatasetType::PR => pr_csv_with_bad_second_row()]);
    $adapter = new K2AutomationPrXlsxAdapter(
        $pdo,
        $normalizer,
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test')
    );

    $result = $adapter->importPr(adapter_context([
        K2ImportDatasetType::PR => $upload,
    ], ['pr_file' => $upload]));

    assert_same(false, $result->success);
    assert_same('AUTOMATION_PARSER_FAILED', $result->errorCode);
    assert_same(0, table_count($pdo, 'prs'));
    assert_normalizer_cleaned($normalizer);
};

$tests['PR adapter succeeds with normalized counters and cleanup'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    $upload = temp_xlsx('pr-success.xlsx', $tempFiles);
    $normalizer = new FakeWorkbookNormalizer([K2ImportDatasetType::PR => pr_csv_valid('PR001')]);
    $adapter = new K2AutomationPrXlsxAdapter(
        $pdo,
        $normalizer,
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test')
    );

    $result = $adapter->importPr(adapter_context([
        K2ImportDatasetType::PR => $upload,
    ], ['pr_file' => $upload]));

    assert_same(true, $result->success);
    assert_same(1, $result->counters['inserted_count']);
    assert_same(0, $result->counters['updated_count']);
    assert_same(0, $result->counters['skipped_count']);
    assert_same(1, table_count($pdo, 'prs'));
    assert_normalizer_cleaned($normalizer);
};

$tests['AON empty allocation set is rejected before writes'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    $catalog = temp_xlsx('catalog-empty-alokasi.xlsx', $tempFiles);
    $price = temp_xlsx('price-empty-alokasi.xlsx', $tempFiles);
    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['AON1']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'AON1', 'alokasi' => '']]),
    ]);
    $adapter = new K2AutomationAonXlsxAdapter(
        $pdo,
        $normalizer,
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test', 1.0)
    );

    $result = $adapter->importAon(adapter_context([
        K2ImportDatasetType::CATALOG => $catalog,
        K2ImportDatasetType::PRICE => $price,
    ], [
        'catalog_file' => $catalog,
        'price_file' => $price,
    ]));

    assert_same(false, $result->success);
    assert_same('AON_EMPTY_ALOKASI_SET', $result->errorCode);
    assert_same(0, table_count($pdo, 'aon_skus'));
};

$tests['AON removal guard blocks unconfigured destructive imports and permits boundary'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    seed_active_aon($pdo, ['AON1', 'OLD1']);
    $catalog = temp_xlsx('catalog-removal.xlsx', $tempFiles);
    $price = temp_xlsx('price-removal.xlsx', $tempFiles);
    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['AON1']),
        K2ImportDatasetType::PRICE => price_csv([['sku' => 'AON1', 'alokasi' => '1']]),
    ]);

    $blocked = (new K2AutomationAonXlsxAdapter(
        $pdo,
        $normalizer,
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test')
    ))->importAon(adapter_context([
        K2ImportDatasetType::CATALOG => $catalog,
        K2ImportDatasetType::PRICE => $price,
    ], [
        'catalog_file' => $catalog,
        'price_file' => $price,
    ]));
    assert_same(false, $blocked->success);
    assert_same('AON_REMOVAL_GUARD_UNCONFIGURED', $blocked->errorCode);
    assert_same(2, active_aon_count($pdo));

    $allowed = (new K2AutomationAonXlsxAdapter(
        $pdo,
        $normalizer,
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test', 0.5)
    ))->importAon(adapter_context([
        K2ImportDatasetType::CATALOG => $catalog,
        K2ImportDatasetType::PRICE => $price,
    ], [
        'catalog_file' => $catalog,
        'price_file' => $price,
    ]));
    assert_same(true, $allowed->success);
    assert_same(1, active_aon_count($pdo));
    assert_same(1, deleted_aon_count($pdo));
};

$tests['AON preflight-only mode makes no writes'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    seed_active_aon($pdo, ['AON1']);
    $catalog = temp_xlsx('catalog-dry-run.xlsx', $tempFiles);
    $price = temp_xlsx('price-dry-run.xlsx', $tempFiles);
    $normalizer = new FakeWorkbookNormalizer([
        K2ImportDatasetType::CATALOG => catalog_csv(['AON1', 'AON2']),
        K2ImportDatasetType::PRICE => price_csv([
            ['sku' => 'AON1', 'alokasi' => '1'],
            ['sku' => 'AON2', 'alokasi' => '1'],
        ]),
    ]);
    $context = adapter_context([
        K2ImportDatasetType::CATALOG => $catalog,
        K2ImportDatasetType::PRICE => $price,
    ], [
        'catalog_file' => $catalog,
        'price_file' => $price,
    ], ['aon_preflight_only' => true]);

    $result = (new K2AutomationAonXlsxAdapter(
        $pdo,
        $normalizer,
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test', 1.0)
    ))->importAon($context);

    assert_same(true, $result->success);
    assert_same(true, $result->result['dry_run']);
    assert_same(1, table_count($pdo, 'aon_skus'));
    assert_same(0, table_count($pdo, 'sku_dictionary'));
};

$tests['global AON lock serializes separate database connections'] = function () use ($pdo, $dsn, $user, $password): void {
    $other = connect_with_retries($dsn, $user, $password);
    $lockOne = K2AonGlobalImportLock::acquire($pdo, 0);
    try {
        assert_same(true, $lockOne->acquired());
        $lockTwo = K2AonGlobalImportLock::acquire($other, 0);
        assert_same(false, $lockTwo->acquired());
    } finally {
        $lockOne->release();
    }

    $lockAfterRelease = K2AonGlobalImportLock::acquire($other, 0);
    try {
        assert_same(true, $lockAfterRelease->acquired());
    } finally {
        $lockAfterRelease->release();
    }
};

$tests['AON adapter busy global lock performs no writes'] = function () use ($pdo, $dsn, $user, $password, &$tempFiles): void {
    reset_parser_tables($pdo);
    $other = connect_with_retries($dsn, $user, $password);
    $held = K2AonGlobalImportLock::acquire($other, 0);
    assert_same(true, $held->acquired());
    try {
        $catalog = temp_xlsx('catalog-busy-lock.xlsx', $tempFiles);
        $price = temp_xlsx('price-busy-lock.xlsx', $tempFiles);
        $normalizer = new FakeWorkbookNormalizer([
            K2ImportDatasetType::CATALOG => catalog_csv(['AONBUSY']),
            K2ImportDatasetType::PRICE => price_csv([['sku' => 'AONBUSY', 'alokasi' => '1']]),
        ]);
        $adapter = new K2AutomationAonXlsxAdapter(
            $pdo,
            $normalizer,
            new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test', 1.0, 0)
        );
        $result = $adapter->importAon(adapter_context([
            K2ImportDatasetType::CATALOG => $catalog,
            K2ImportDatasetType::PRICE => $price,
        ], [
            'catalog_file' => $catalog,
            'price_file' => $price,
        ]));

        assert_same(false, $result->success);
        assert_same('AON_IMPORT_BUSY', $result->errorCode);
        assert_same(0, table_count($pdo, 'aon_skus'));
    } finally {
        $held->release();
    }
};

$tests['PR endpoint queues without normalizing; worker executes the real adapter'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    $paths = [
        K2ImportDatasetType::PR => temp_xlsx('endpoint-pr.xlsx', $tempFiles),
        K2ImportDatasetType::CATALOG => temp_xlsx('endpoint-catalog.xlsx', $tempFiles),
        K2ImportDatasetType::PRICE => temp_xlsx('endpoint-price.xlsx', $tempFiles),
    ];
    $normalizer = new FakeWorkbookNormalizer([K2ImportDatasetType::PR => pr_csv_valid('PR101')]);
    [$controller, $batchUid, $service, $registeredFiles] = pr_endpoint_controller($pdo, $paths, $normalizer);
    $payload = api_operation_payload($paths, [
        'pr_file' => upload_manifest_entry('pr_file', $paths[K2ImportDatasetType::PR]),
    ]);
    $prBytes = file_get_contents($paths[K2ImportDatasetType::PR]);

    $first = signed_controller_request(
        $controller,
        'import_pr',
        $payload,
        K2ImportOperationType::PR,
        'nonce-pr-real-1',
        'request-pr-real'
    );
    // A transport retry re-sends the same bytes as a fresh upload (its own
    // PHP-assigned tmp path) — the first call's pr_file was already moved by
    // stage(). Reuse $payload's already-computed source_files/batch_uid (rebuilding
    // them now would hash_file() a path stage() already moved away) and only swap
    // in a fresh copy (captured before the first call) for the file_manifest entry.
    $retryPath = temp_xlsx('endpoint-pr-retry.xlsx', $tempFiles);
    file_put_contents($retryPath, $prBytes);
    $retryPayload = $payload;
    $retryPayload['file_manifest'] = [upload_manifest_entry('pr_file', $retryPath)];
    $second = signed_controller_request(
        $controller,
        'import_pr',
        $retryPayload,
        K2ImportOperationType::PR,
        'nonce-pr-real-2',
        'request-pr-real'
    );

    assert_same($batchUid, $first->body['batch_uid']);
    assert_same(202, $first->status);
    assert_same(202, $second->status);
    assert_same(K2ImportResponseCode::REQUEST_IN_PROGRESS, $second->body['code']);
    // The whole point of the async queue: neither HTTP call ever normalizes.
    assert_same(0, $normalizer->callsByDataset[K2ImportDatasetType::PR] ?? 0);
    assert_same(0, table_count($pdo, 'prs'));

    // Simulate the CLI worker claiming and executing what was queued, using the
    // SAME real K2AutomationPrXlsxAdapter the endpoint wired up (only the actual
    // normalize step is faked, via $normalizer). The worker reads from the STAGED
    // copy (private storage), never the original upload tmp path — stage() already
    // moved that away when the HTTP request queued this attempt.
    $claimed = $service->claimNextForProcessing('worker-token', 'worker-host', 999, 900);
    assert_true($claimed !== null, 'Nothing claimable for the real PR adapter worker simulation.');
    assert_same($batchUid, $claimed['batch_uid']);
    $stagedManifest = json_decode((string) $claimed['staged_file_manifest'], true);
    assert_true(is_array($stagedManifest) && isset($stagedManifest[0]['storage_path']), 'Expected a decodable staged file manifest.');
    $stagedPrPath = (string) $stagedManifest[0]['storage_path'];
    $adapter = new K2AutomationPrXlsxAdapter($pdo, $normalizer, new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'automation-test'));
    $prepared = $adapter->preparePr([
        'payload' => ['source_files' => source_files_payload($registeredFiles)],
        'files' => ['pr_file' => ['tmp_name' => $stagedPrPath, 'name' => basename($stagedPrPath)]],
    ]);
    $lock = $service->claimBatchLock($batchUid, null, 'worker-request');
    assert_true($lock['success']);
    $completed = $service->recordOperationSuccessWithExecution(
        $batchUid,
        K2ImportOperationType::PR,
        $claimed['request_idempotency_key'],
        (int) $claimed['attempt_id'],
        fn(): K2ImportAdapterResult => $adapter->executePreparedPr($prepared),
        (string) $lock['result']['lock_token'],
        'worker-request'
    );
    if ($completed['state'] === K2ImportBatchState::IMPORT_SUCCESS) {
        $completed = $service->markArchivePending($batchUid, (string) $lock['result']['lock_token'], 'worker-request');
    }
    $service->releaseBatchLock($batchUid, (string) $lock['result']['lock_token'], 'worker-request');

    assert_same(K2ImportBatchState::ARCHIVE_PENDING, $completed['state']);
    assert_same(1, $normalizer->callsByDataset[K2ImportDatasetType::PR] ?? 0);
    assert_same(1, table_count($pdo, 'prs'));
};

$tests['archive state cannot trigger PR parser'] = function () use ($pdo, &$tempFiles): void {
    reset_parser_tables($pdo);
    $paths = [
        K2ImportDatasetType::PR => temp_xlsx('archive-pr.xlsx', $tempFiles),
        K2ImportDatasetType::CATALOG => temp_xlsx('archive-catalog.xlsx', $tempFiles),
        K2ImportDatasetType::PRICE => temp_xlsx('archive-price.xlsx', $tempFiles),
    ];
    $normalizer = new FakeWorkbookNormalizer([K2ImportDatasetType::PR => pr_csv_valid('PR201')]);
    [$controller] = archive_pending_controller($pdo, $paths, $normalizer);
    $payload = api_operation_payload($paths, [
        'pr_file' => upload_manifest_entry('pr_file', $paths[K2ImportDatasetType::PR]),
    ]);
    $response = signed_controller_request(
        $controller,
        'import_pr',
        $payload,
        K2ImportOperationType::PR,
        'nonce-archive-pr',
        'request-archive-pr'
    );

    assert_same(409, $response->status);
    assert_same(K2ImportResponseCode::INVALID_TRANSITION, $response->body['code']);
    assert_same(0, $normalizer->callsByDataset[K2ImportDatasetType::PR] ?? 0);
};

// These two exercise recordOperationSuccessWithExecution()'s rollback guarantee
// directly against the orchestrator — this is exactly what the CLI worker calls
// after prepareAon()/preparePr() (Area F), now that the HTTP controller never
// executes a prepared import itself.
$tests['prepared PR parser write failure rolls back business data'] = function () use ($pdo): void {
    reset_parser_tables($pdo);
    $executor = new PreparedPrWriteThenFailExecutor($pdo);
    [$service, $batchUid, $requestKey, $attemptId, $lockToken] = pr_ready_to_execute($pdo, 'prepared-write-fail');

    $prepared = $executor->preparePr(['payload' => [], 'files' => []]);
    $threw = false;
    try {
        $service->recordOperationSuccessWithExecution(
            $batchUid,
            K2ImportOperationType::PR,
            $requestKey,
            $attemptId,
            fn(): K2ImportAdapterResult => $executor->executePreparedPr($prepared),
            $lockToken,
            'request-prepared-write-fail'
        );
    } catch (K2ImportPreparedExecutionFailedException $e) {
        $threw = true;
        $adapterResult = $e->adapterResult();
        $failure = $service->recordOperationFailure(
            $batchUid,
            K2ImportOperationType::PR,
            $requestKey,
            $attemptId,
            $adapterResult->errorCode ?? 'PR_IMPORT_FAILED',
            $adapterResult->safeMessage ?? 'PR import failed.',
            $adapterResult->retryable,
            $lockToken,
            'request-prepared-write-fail'
        );
    }
    $service->releaseBatchLock($batchUid, $lockToken, 'request-prepared-write-fail');

    assert_true($threw, 'Expected prepared PR execution to throw after writing.');
    assert_same(K2ImportBatchState::PR_FAILED_RETRYABLE, $failure['state']);
    assert_same(0, table_count($pdo, 'prs'));
};

$tests['forced success-state failure rolls prepared parser writes back'] = function () use ($pdo): void {
    reset_parser_tables($pdo);
    $executor = new PreparedPrStateConflictExecutor($pdo);
    [$service, $batchUid, $requestKey, $attemptId, $lockToken] = pr_ready_to_execute($pdo, 'prepared-state-conflict');
    $executor->batchUid = $batchUid;

    // The executor mutates batch state mid-flight (simulating a conflicting
    // concurrent write) before returning success. transitionBatchState's CAS
    // inside recordOperationSuccessWithExecution's own transaction then finds the
    // batch no longer in PR_PROCESSING and throws — rolling back the executor's PR
    // insert AND its forced state UPDATE together, back to the last COMMITTED
    // state (PR_PROCESSING, not the transient uncommitted AON_SUCCESS write).
    $prepared = $executor->preparePr(['payload' => [], 'files' => []]);
    $errorCode = null;
    $retryable = null;
    try {
        $service->recordOperationSuccessWithExecution(
            $batchUid,
            K2ImportOperationType::PR,
            $requestKey,
            $attemptId,
            fn(): K2ImportAdapterResult => $executor->executePreparedPr($prepared),
            $lockToken,
            'request-prepared-state-conflict'
        );
    } catch (K2ImportPreparedExecutionFailedException $e) {
        $errorCode = $e->adapterResult()->errorCode ?? 'PR_IMPORT_FAILED';
        $retryable = $e->adapterResult()->retryable;
    } catch (K2ImportOrchestrationException $e) {
        $errorCode = $e->stableCode();
        $retryable = $e->retryable();
    }
    assert_true($errorCode !== null, 'Expected recordOperationSuccessWithExecution to fail.');

    $failure = $service->recordOperationFailure(
        $batchUid,
        K2ImportOperationType::PR,
        $requestKey,
        $attemptId,
        $errorCode,
        'Synthetic state-conflict failure.',
        $retryable,
        $lockToken,
        'request-prepared-state-conflict'
    );
    $service->releaseBatchLock($batchUid, $lockToken, 'request-prepared-state-conflict');

    assert_same(K2ImportBatchState::PR_FAILED_RETRYABLE, $failure['state']);
    assert_same(0, table_count($pdo, 'prs'));
};

final class FakeWorkbookNormalizer implements K2AutomationWorkbookNormalizerInterface
{
    public array $createdCsvs = [];
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
        $this->createdCsvs[] = $csvPath;

        return [
            'temporary_csv_path' => $csvPath,
            'written_csv_row_count' => substr_count(trim($this->csvByDataset[$datasetType]), "\n") + 1,
        ];
    }
}

final class PreparedPrWriteThenFailExecutor implements K2PreparedPrImportExecutorInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function importPr(array $context): K2ImportAdapterResult
    {
        return new K2ImportAdapterResult(false, [], 'TEST_DIRECT_UNUSED', 'Direct path is unused.', true);
    }

    public function preparePr(array $context): K2AutomationPreparedParserImport
    {
        return new K2AutomationPreparedParserImport(K2ImportOperationType::PR, [], [], []);
    }

    public function executePreparedPr(K2AutomationPreparedParserImport $prepared): K2ImportAdapterResult
    {
        insert_min_pr($this->pdo, 'PRFAIL');
        throw new K2AutomationParserAdapterException('PR_FORCED_AFTER_WRITE', 'Forced parser failure after write.', true);
    }
}

final class PreparedPrStateConflictExecutor implements K2PreparedPrImportExecutorInterface
{
    public string $batchUid = '';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function importPr(array $context): K2ImportAdapterResult
    {
        return new K2ImportAdapterResult(false, [], 'TEST_DIRECT_UNUSED', 'Direct path is unused.', true);
    }

    public function preparePr(array $context): K2AutomationPreparedParserImport
    {
        return new K2AutomationPreparedParserImport(K2ImportOperationType::PR, [], [], []);
    }

    public function executePreparedPr(K2AutomationPreparedParserImport $prepared): K2ImportAdapterResult
    {
        insert_min_pr($this->pdo, 'PRSTATE');
        $stmt = $this->pdo->prepare('UPDATE k2_automation_import_batches SET state = ? WHERE batch_uid = ?');
        $stmt->execute([K2ImportBatchState::AON_SUCCESS, $this->batchUid]);

        return new K2ImportAdapterResult(true, ['inserted_count' => 1]);
    }
}

try {
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
} finally {
    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures > 0) {
    echo "{$failures} parser adapter database test(s) failed.\n";
    exit(1);
}

echo count($tests) . " parser adapter database test(s) passed.\n";

function pr_endpoint_controller(PDO $pdo, array $paths, FakeWorkbookNormalizer $normalizer): array
{
    $repo = new PdoK2ImportRunRepository($pdo);
    $service = new K2ImportOrchestrator($repo, 60, static fn(): string => 'endpoint-lock-' . bin2hex(random_bytes(4)));
    $files = source_file_objects($paths);
    $schedule = schedule_metadata();
    $batch = $service->registerBatch($files, $schedule, 'request-register-pr-endpoint');
    $lock = $service->claimBatchLock($batch['batch_uid'], 'manual-aon-lock');
    assert_same(K2ImportResponseCode::LOCK_ACQUIRED, $lock['code']);
    $aonKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $aon = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $aonKey, 'manual-aon-lock');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $service->recordOperationSuccess(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $aonKey,
        (int) $aon['result']['attempt_id'],
        [],
        'manual-aon-lock'
    );
    $service->releaseBatchLock($batch['batch_uid'], 'manual-aon-lock');

    return [
        new K2AutomationApiController(
            api_config(),
            new PdoK2AutomationNonceStore($pdo),
            $service,
            test_file_store(),
            new DateTimeImmutable('2026-07-22T02:00:00Z')
        ),
        $batch['batch_uid'],
        $service,
        $files,
    ];
}

function archive_pending_controller(PDO $pdo, array $paths, FakeWorkbookNormalizer $normalizer): array
{
    $repo = new PdoK2ImportRunRepository($pdo);
    $service = new K2ImportOrchestrator($repo, 60, static fn(): string => 'archive-state-lock');
    $files = source_file_objects($paths);
    $schedule = schedule_metadata();
    $batch = $service->registerBatch($files, $schedule, 'request-register-archive-state');
    $lock = $service->claimBatchLock($batch['batch_uid'], 'archive-state-lock');
    assert_same(K2ImportResponseCode::LOCK_ACQUIRED, $lock['code']);
    $aonKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $aon = $service->beginOperation($batch['batch_uid'], K2ImportOperationType::AON, $aonKey, 'archive-state-lock');
    force_claim_for_processing($repo, $batch['batch_uid'], K2ImportOperationType::AON);
    $service->recordOperationSuccess(
        $batch['batch_uid'],
        K2ImportOperationType::AON,
        $aonKey,
        (int) $aon['result']['attempt_id'],
        [],
        'archive-state-lock'
    );
    $row = $repo->findBatchByUid($batch['batch_uid']);
    $batchId = (int) $row['id'];
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
    $service->releaseBatchLock($batch['batch_uid'], 'archive-state-lock');
    $controller = new K2AutomationApiController(
        api_config(),
        new PdoK2AutomationNonceStore($pdo),
        $service,
        test_file_store(),
        new DateTimeImmutable('2026-07-22T02:00:00Z')
    );

    return [$controller, $batch['batch_uid']];
}

/**
 * Registers a batch, records AON success, opens a PR attempt, and forces the
 * batch straight to PR_PROCESSING (bypassing the queue/claim machinery, which
 * these particular tests are not about) so recordOperationSuccessWithExecution()
 * can be exercised directly exactly as the CLI worker (Area F) will call it.
 *
 * @return array{0: K2ImportOrchestrator, 1: string, 2: string, 3: int, 4: string}
 */
function pr_ready_to_execute(PDO $pdo, string $suffix): array
{
    $repo = new PdoK2ImportRunRepository($pdo);
    $service = new K2ImportOrchestrator($repo, 60, static fn(): string => 'prepared-pr-lock-' . bin2hex(random_bytes(4)));
    $paths = source_paths_for_payload($suffix);
    $files = source_file_objects($paths);
    $schedule = schedule_metadata();
    $batch = $service->registerBatch($files, $schedule, 'request-register-' . $suffix);
    $batchUid = (string) $batch['batch_uid'];

    $lock = $service->claimBatchLock($batchUid, null, 'setup-request');
    assert_same(K2ImportResponseCode::LOCK_ACQUIRED, $lock['code']);
    $lockToken = (string) $lock['result']['lock_token'];

    $aonKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::AON, $files, $schedule);
    $aon = $service->beginOperation($batchUid, K2ImportOperationType::AON, $aonKey, $lockToken);
    force_claim_for_processing($repo, $batchUid, K2ImportOperationType::AON);
    $service->recordOperationSuccess($batchUid, K2ImportOperationType::AON, $aonKey, (int) $aon['result']['attempt_id'], [], $lockToken);

    $prKey = K2ImportIdentity::requestIdempotencyKey(K2ImportOperationType::PR, $files, $schedule);
    $pr = $service->beginOperation($batchUid, K2ImportOperationType::PR, $prKey, $lockToken);
    $attemptId = (int) $pr['result']['attempt_id'];
    force_claim_for_processing($repo, $batchUid, K2ImportOperationType::PR);

    return [$service, $batchUid, $prKey, $attemptId, $lockToken];
}

/** Force-claims a queued attempt for processing without going through the real claim/worker path (these tests are not about claiming). */
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

function api_config(): K2AutomationApiConfig
{
    return new K2AutomationApiConfig('client-a', 'test-secret', 300, 4096, 60, '/vms/workspace/api/k2_automation');
}

function test_file_store(): K2AutomationStagedFileStore
{
    return new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig(
        sys_get_temp_dir() . '/k2-automation-test-parser-storage-' . bin2hex(random_bytes(6))
    ));
}

function signed_controller_request(
    K2AutomationApiController $controller,
    string $endpoint,
    array $payload,
    string $operation,
    string $nonce,
    string $requestId
): K2AutomationApiResponse {
    $manifest = $payload['file_manifest'] ?? [];
    $metadataHash = K2AutomationJson::sha256($payload);
    $manifestHash = K2AutomationJson::sha256(['files' => $manifest]);
    $batchUid = (string) $payload['batch_uid'];
    $canonical = K2AutomationSignature::canonicalString(
        'POST',
        endpoint_path($endpoint),
        'client-a',
        '2026-07-22T02:00:00Z',
        $nonce,
        $requestId,
        $operation,
        $batchUid,
        $metadataHash,
        $manifestHash
    );
    $headers = [
        'X-K2-Client-ID' => 'client-a',
        'X-K2-Timestamp' => '2026-07-22T02:00:00Z',
        'X-K2-Nonce' => $nonce,
        'X-K2-Request-ID' => $requestId,
        'X-K2-Batch-UID' => $batchUid,
        'X-K2-Operation' => $operation,
        'X-K2-Metadata-SHA256' => $metadataHash,
        'X-K2-File-Manifest-SHA256' => $manifestHash,
        'X-K2-Signature' => K2AutomationSignature::sign($canonical, 'test-secret'),
    ];
    $files = [];
    foreach ($manifest as $entry) {
        $files[(string) $entry['field']] = [
            'tmp_name' => (string) $entry['test_path'],
            'name' => (string) $entry['filename'],
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'size' => (int) $entry['file_size_bytes'],
            'error' => UPLOAD_ERR_OK,
        ];
    }

    return $controller->handle(
        $endpoint,
        'POST',
        endpoint_path($endpoint),
        $headers,
        K2AutomationJson::canonicalJson($payload),
        $files,
        ['metadata' => K2AutomationJson::canonicalJson($payload)],
        ['CONTENT_TYPE' => 'multipart/form-data; boundary=k2-test', 'CONTENT_LENGTH' => '1024']
    );
}

function endpoint_path(string $endpoint): string
{
    return match ($endpoint) {
        'import_pr' => '/vms/workspace/api/k2_automation/import_pr.php',
        'import_aon' => '/vms/workspace/api/k2_automation/import_aon.php',
        default => '/vms/workspace/api/k2_automation/' . $endpoint . '.php',
    };
}

function api_operation_payload(array $paths, array $manifest): array
{
    $files = source_file_objects($paths);

    return [
        'batch_uid' => K2ImportIdentity::batchUid($files),
        'scheduled_slot_at_utc' => '2026-07-22 09:00:00',
        'schedule_timezone' => 'Asia/Jakarta',
        'source_files' => source_files_payload($files),
        'attempt_number' => 1,
        'file_manifest' => array_values($manifest),
    ];
}

function source_paths_for_payload(string $suffix): array
{
    return [
        K2ImportDatasetType::PR => temp_placeholder_path(K2ImportDatasetType::PR . '-' . $suffix),
        K2ImportDatasetType::CATALOG => temp_placeholder_path(K2ImportDatasetType::CATALOG . '-' . $suffix),
        K2ImportDatasetType::PRICE => temp_placeholder_path(K2ImportDatasetType::PRICE . '-' . $suffix),
    ];
}

function adapter_context(array $sourcePathsByType, array $uploadsByField, array $extraPayload = []): array
{
    $files = source_file_objects($sourcePathsByType);
    $payload = array_merge([
        'batch_uid' => K2ImportIdentity::batchUid($files),
        'scheduled_slot_at_utc' => '2026-07-22 09:00:00',
        'schedule_timezone' => 'Asia/Jakarta',
        'source_files' => source_files_payload($files),
    ], $extraPayload);
    $uploads = [];
    foreach ($uploadsByField as $field => $path) {
        $uploads[$field] = [
            'tmp_name' => $path,
            'name' => basename($path),
        ];
    }

    return ['payload' => $payload, 'files' => $uploads];
}

function upload_manifest_entry(string $field, string $path): array
{
    return [
        'field' => $field,
        'filename' => basename($path),
        'file_size_bytes' => filesize($path),
        'sha256_content_hash' => hash_file('sha256', $path),
        'test_path' => $path,
    ];
}

function source_file_objects(array $pathsByType): array
{
    $files = [];
    foreach (K2ImportDatasetType::requiredTypes() as $index => $datasetType) {
        $path = $pathsByType[$datasetType] ?? temp_placeholder_path($datasetType);
        $files[$datasetType] = new K2ImportSourceFileMetadata(
            $datasetType,
            'drive-' . md5($path . $datasetType),
            basename($path),
            sprintf('2026-07-22T01:0%d:00+07:00', $index),
            filesize($path) ?: 0,
            hash_file('sha256', $path)
        );
    }

    return $files;
}

function temp_placeholder_path(string $datasetType): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "k2-placeholder-{$datasetType}.xlsx";
    if (!is_file($path)) {
        file_put_contents($path, "PK\x03\x04{$datasetType}");
    }

    return $path;
}

function insert_min_pr(PDO $pdo, string $ticketNo): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO prs (ticket_no, ticket_line_item, p_group, status_ticket, ticket_type, requestor_name) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$ticketNo, '1', 'P001', 'open', 'standard', 'Requestor']);
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

function source_files_payload(array $files): array
{
    return array_values(array_map(
        static fn(K2ImportSourceFileMetadata $file): array => $file->canonicalArray(),
        K2ImportIdentity::normalizeSourceFiles($files)
    ));
}

function schedule_metadata(): K2ImportScheduleMetadata
{
    return new K2ImportScheduleMetadata('2026-07-22 09:00:00', 'Asia/Jakarta');
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

    return csv_from_rows([k2_file_schema(K2ImportDatasetType::PR)['headers'], $row]);
}

function pr_csv_with_bad_second_row(): string
{
    $rows = str_getcsv(pr_csv_valid('PR001'), "\n");
    $bad = array_fill(0, count(k2_file_schema(K2ImportDatasetType::PR)['headers']), '');
    $bad[0] = 'PR-TICKET-TOO-LONG';
    $bad[1] = 'standard';
    $bad[2] = 'open';
    $bad[4] = 'P001';
    $bad[5] = '2';
    $bad[10] = 'Requestor';

    return $rows[0] . "\n" . $rows[1] . "\n" . csv_line($bad) . "\n";
}

function large_pr_preflight_csv(int $rowCount): string
{
    $path = tempnam(sys_get_temp_dir(), 'k2_large_pr_preflight_');
    if ($path === false) {
        throw new RuntimeException('Could not create large PR CSV.');
    }

    $handle = fopen($path, 'wb');
    if ($handle === false) {
        @unlink($path);
        throw new RuntimeException('Could not open large PR CSV.');
    }

    try {
        $headers = k2_file_schema(K2ImportDatasetType::PR)['headers'];
        fputcsv($handle, $headers);
        $row = array_fill(0, count($headers), '');
        $row[1] = 'standard';
        $row[2] = 'open';
        $row[4] = 'P001';
        $row[5] = '1';
        for ($i = 1; $i <= $rowCount; $i++) {
            $row[0] = 'PR' . str_pad((string) $i, 8, '0', STR_PAD_LEFT);
            fputcsv($handle, $row);
        }
    } finally {
        fclose($handle);
    }

    return $path;
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

function temp_csv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'k2_pr_csv_');
    if ($path === false) {
        throw new RuntimeException('Could not create CSV.');
    }
    file_put_contents($path, $contents);

    return $path;
}

function temp_xlsx(string $filename, array &$tempFiles): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '-' . $filename;
    file_put_contents($path, "PK\x03\x04" . $filename);
    $tempFiles[] = $path;

    return $path;
}

function assert_normalizer_cleaned(FakeWorkbookNormalizer $normalizer): void
{
    foreach ($normalizer->createdCsvs as $csv) {
        assert_false(is_file($csv), "Temporary CSV was not removed: {$csv}");
    }
}

function reset_parser_tables(PDO $pdo): void
{
    foreach (['prs', 'aon_skus', 'sku_dictionary'] as $table) {
        $pdo->exec("DELETE FROM {$table}");
    }
}

function seed_active_aon(PDO $pdo, array $skus): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO aon_skus (sku_code, item_name, effective_year, status, is_deleted) VALUES (?, ?, 2026, 'ACTIVE', 0)"
    );
    foreach ($skus as $sku) {
        $stmt->execute([$sku, "Existing {$sku}"]);
    }
}

function table_count(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
}

function active_aon_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM aon_skus WHERE is_deleted = 0')->fetchColumn();
}

function deleted_aon_count(PDO $pdo): int
{
    return (int) $pdo->query('SELECT COUNT(*) FROM aon_skus WHERE is_deleted = 1')->fetchColumn();
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS prs (
        ticket_no VARCHAR(10) NOT NULL,
        ticket_line_item VARCHAR(10) NOT NULL,
        p_org VARCHAR(50), p_group VARCHAR(50), requestor_name VARCHAR(100),
        site_allocation VARCHAR(100), pr_date DATE NULL, item_description VARCHAR(255),
        status_ticket VARCHAR(100), canvassing_no VARCHAR(100), canvassing_status VARCHAR(100),
        canvassing_date DATE NULL, po_number VARCHAR(100), ticket_type VARCHAR(100),
        vendor_name VARCHAR(100), qty DECIMAL(10,2) NULL, delivery_date DATE NULL,
        gr_date DATE NULL, tr_date_gp DATE NULL, canvassing_date_approver_1 DATE NULL,
        created_at DATETIME NULL, sku VARCHAR(100), uom VARCHAR(50), po_amount DECIMAL(15,2) NULL,
        assigned_pic VARCHAR(100), assigned_by_ai TINYINT NULL, gp_officer VARCHAR(100),
        otf_score INT NULL, otf_status VARCHAR(100), top_score INT NULL,
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
