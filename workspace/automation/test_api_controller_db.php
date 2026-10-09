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
if ((getenv('K2_AUTOMATION_TEST_SCHEMA') ?: '') === 'fresh') {
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_fresh_install_schema.sql');
} else {
    // phase2_schema.sql already includes the logical_request_id/attempt_number
    // columns phase7 would otherwise add (pre-existing drift between the two
    // files — see test_migration_paths_db.php's simulated_pre_phase7_schema_sql()
    // for the one place that still exercises the true pre-phase7 shape), so only
    // phase8 (the new worker-queue columns/states) is needed on top here.
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase2_schema.sql');
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
    apply_migration($pdo, __DIR__ . '/../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
}

$tempFiles = [];
$tempStorageRoots = [];
$tests = [];

// -----------------------------------------------------------------------
// Tests below this line exercise register_batch/batch_status/auth, which
// are unaffected by the async queue rework (Area E only changes
// import_aon.php/import_pr.php handling).
// -----------------------------------------------------------------------

$tests['nonce schema uniqueness and cleanup'] = function () use ($pdo): void {
    assert_same('datetime(6)', column_type($pdo, 'k2_automation_api_nonces', 'request_timestamp'));
    assert_same('datetime(6)', column_type($pdo, 'k2_automation_api_nonces', 'expires_at'));

    $store = new PdoK2AutomationNonceStore($pdo);
    $requestAt = new DateTimeImmutable('2026-07-22T02:00:00Z');
    $expiresAt = new DateTimeImmutable('2026-07-22T02:05:00Z');
    assert_true($store->storeNonce('client-a', 'nonce-db-unique', 'req-nonce-db', $requestAt, $expiresAt));
    assert_false($store->storeNonce('client-a', 'nonce-db-unique', 'req-nonce-db', $requestAt, $expiresAt));

    $oldAt = new DateTimeImmutable('2026-07-22T01:00:00Z');
    assert_true($store->storeNonce('client-a', 'nonce-db-expired', 'req-expired', $oldAt, $oldAt));
    assert_same(1, $store->cleanupExpired(new DateTimeImmutable('2026-07-22T02:00:00Z')));
};

$tests['register endpoint creates deterministic batch'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller, , , $files] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-register');
    $response = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-api-register');

    assert_same(201, $response->status);
    assert_same(K2ImportResponseCode::BATCH_REGISTERED, $response->body['code']);
    assert_same('application/json; charset=utf-8', $response->headers['Content-Type']);
    assert_same(K2ImportIdentity::batchUid($files('api-register')), $response->body['batch_uid']);

    $row = fetch_one($pdo, 'SELECT scheduled_slot_at_utc, schedule_timezone FROM k2_automation_import_batches WHERE batch_uid = ?', [
        $response->body['batch_uid'],
    ]);
    assert_same('2026-07-22 02:00:00.000000', (string) $row['scheduled_slot_at_utc']);
    assert_same('Asia/Jakarta', (string) $row['schedule_timezone']);
};

$tests['duplicate registration replays with new nonce'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-register-replay');
    $first = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-register-replay-1', 'request-register-replay');
    $second = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-register-replay-2', 'request-register-replay');

    assert_same(201, $first->status);
    assert_same(200, $second->status);
    assert_same(K2ImportResponseCode::BATCH_REPLAYED, $second->body['code']);
    assert_same(true, $second->body['replayed']);
};

$tests['duplicate nonce is rejected'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-nonce-replay');
    $first = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-repeat-api');
    $second = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-repeat-api');

    assert_same(201, $first->status);
    assert_same(409, $second->status);
    assert_same('NONCE_REPLAY', $second->body['code']);
};

$tests['metadata mutation and malformed JSON return safe errors'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-metadata-mutation');
    [$headers] = signed_request_parts('register_batch', $payload, 'register_batch', 'nonce-metadata-mutation');
    $mutated = $payload;
    $mutated['schedule_timezone'] = 'UTC';
    $response = $controller->handle(
        'register_batch',
        'POST',
        endpoint_path('register_batch'),
        $headers,
        json_encode($mutated, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'
    );
    assert_same(422, $response->status);
    assert_same('INVALID_REQUEST', $response->body['code']);

    [$badHeaders] = signed_request_parts(
        'register_batch',
        [],
        'register_batch',
        'nonce-malformed-json',
        'k2b_' . str_repeat('a', 64)
    );
    $bad = $controller->handle(
        'register_batch',
        'POST',
        endpoint_path('register_batch'),
        $badHeaders,
        '{'
    );
    assert_same(400, $bad->status);
    assert_same('MALFORMED_REQUEST', $bad->body['code']);
    assert_no_stack_leak($bad->body);
};

$tests['authentication failures map to stable statuses'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-auth-failure');
    [$headers, $rawBody] = signed_request_parts('register_batch', $payload, 'register_batch', 'nonce-auth-failure');

    $badSignature = $headers;
    $badSignature['X-K2-Signature'] = str_repeat('0', 64);
    $response = $controller->handle('register_batch', 'POST', endpoint_path('register_batch'), $badSignature, $rawBody);
    assert_same(401, $response->status);
    assert_same('INVALID_SIGNATURE', $response->body['code']);

    $missing = $headers;
    unset($missing['X-K2-Nonce']);
    $response = $controller->handle('register_batch', 'POST', endpoint_path('register_batch'), $missing, $rawBody);
    assert_same(401, $response->status);
    assert_same('MISSING_AUTH_HEADER', $response->body['code']);
};

$tests['uploaded file hash mismatch and oversized upload'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-upload-validation');
    signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-upload-register');

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $aonPayload = operation_payload('api-upload-validation', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog', 'not-catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);
    $response = signed_controller_request(
        $controller,
        'import_aon',
        $aonPayload,
        K2ImportOperationType::AON,
        'nonce-upload-hash',
        'request-upload-hash',
        [
            'catalog_file' => upload_file_array($catalogPath),
            'price_file' => upload_file_array($pricePath),
        ]
    );
    assert_same(422, $response->status);
    assert_same('INVALID_REQUEST', $response->body['code']);

    [$smallController] = controller_fixture($pdo, 4, $tempStorageRoots);
    signed_controller_request(
        $smallController,
        'register_batch',
        register_payload('api-upload-size'),
        'register_batch',
        'nonce-upload-size-register'
    );
    $response = signed_controller_request(
        $smallController,
        'import_aon',
        operation_payload('api-upload-size', [
            'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
            'price_file' => upload_manifest_entry('price_file', 'price'),
        ]),
        K2ImportOperationType::AON,
        'nonce-upload-size',
        'request-upload-size',
        [
            'catalog_file' => upload_file_array($catalogPath),
            'price_file' => upload_file_array($pricePath),
        ]
    );
    assert_same(413, $response->status);
    assert_same('UPLOAD_TOO_LARGE', $response->body['code']);
};

$tests['multipart metadata and field validation'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller, $service] = controller_fixture($pdo, 4096, $tempStorageRoots);
    signed_controller_request($controller, 'register_batch', register_payload('api-multipart-validation'), 'register_batch', 'nonce-multipart-register');

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $payload = operation_payload('api-multipart-validation', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);
    $files = [
        'catalog_file' => upload_file_array($catalogPath),
        'price_file' => upload_file_array($pricePath),
    ];

    // Its own dedicated upload pair: stage() moves (renames) a real upload's tmp
    // file on success, same as a genuine second HTTP request would arrive with a
    // fresh PHP-assigned tmp path — reusing $catalogPath/$pricePath here would
    // wrongly delete out from under the still-pending validation-only sub-cases below.
    $rawCaseFiles = [
        'catalog_file' => upload_file_array(temp_upload_file('catalog', $tempFiles)),
        'price_file' => upload_file_array(temp_upload_file('price', $tempFiles)),
    ];
    [$headers, $metadataJson] = signed_request_parts('import_aon', $payload, K2ImportOperationType::AON, 'nonce-multipart-raw', null, 'request-multipart-raw');
    $rawMultipartBody = "--k2-test\r\nContent-Disposition: form-data; name=\"metadata\"\r\n\r\nnot-json-here\r\n--k2-test--\r\n";
    $response = $controller->handle(
        'import_aon',
        'POST',
        endpoint_path('import_aon'),
        $headers,
        $rawMultipartBody,
        $rawCaseFiles,
        ['metadata' => $metadataJson],
        ['CONTENT_TYPE' => 'multipart/form-data; boundary=k2-test', 'CONTENT_LENGTH' => (string) strlen($rawMultipartBody)]
    );
    assert_same(202, $response->status);
    assert_same(K2ImportBatchState::AON_QUEUED, $response->body['state']);
    // Resolve what this queued so no dangling AON attempt is left claimable by a later test.
    simulate_worker_success($service, $response->body['batch_uid'], K2ImportOperationType::AON);

    $missing = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-multipart-missing',
        'request-multipart-missing',
        $files,
        []
    );
    assert_same(400, $missing->status);
    assert_same('MISSING_MULTIPART_METADATA', $missing->body['code']);

    $malformed = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-multipart-malformed',
        'request-multipart-malformed',
        $files,
        ['metadata' => '{']
    );
    assert_same(400, $malformed->status);
    assert_same('MALFORMED_REQUEST', $malformed->body['code']);

    $unexpectedForm = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-multipart-form',
        'request-multipart-form',
        $files,
        ['metadata' => $metadataJson, 'extra' => 'x']
    );
    assert_same(400, $unexpectedForm->status);
    assert_same('UNEXPECTED_MULTIPART_FIELD', $unexpectedForm->body['code']);

    $unexpectedFiles = $files;
    $unexpectedFiles['extra_file'] = upload_file_array($catalogPath);
    $unexpectedFile = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-multipart-file',
        'request-multipart-file',
        $unexpectedFiles,
        ['metadata' => $metadataJson]
    );
    assert_same(400, $unexpectedFile->status);
    assert_same('UNEXPECTED_FILE_FIELD', $unexpectedFile->body['code']);

    $multiple = $files;
    $multiple['catalog_file']['tmp_name'] = [$catalogPath];
    $multipleUpload = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-multipart-multiple',
        'request-multipart-multiple',
        $multiple,
        ['metadata' => $metadataJson]
    );
    assert_same(400, $multipleUpload->status);
    assert_same('MULTIPLE_UPLOADS_REJECTED', $multipleUpload->body['code']);
};

$tests['upload error codes and post_max_size truncation map safely'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    signed_controller_request($controller, 'register_batch', register_payload('api-upload-errors'), 'register_batch', 'nonce-upload-errors-register');
    $payload = operation_payload('api-upload-errors', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);

    $uploadError = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-upload-ini',
        'request-upload-ini',
        [
            'catalog_file' => ['name' => 'catalog.xlsx', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0],
            'price_file' => ['name' => 'price.xlsx', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0],
        ]
    );
    assert_same(413, $uploadError->status);
    assert_same('UPLOAD_EXCEEDS_INI_SIZE', $uploadError->body['code']);

    $truncated = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-upload-truncated',
        'request-upload-truncated',
        [],
        [],
        ['CONTENT_TYPE' => 'multipart/form-data; boundary=k2-test', 'CONTENT_LENGTH' => (string) (1024 * 1024 * 1024)]
    );
    assert_same(413, $truncated->status);
    assert_same('MULTIPART_POST_TOO_LARGE', $truncated->body['code']);
};

$tests['PR before AON is rejected by API state enforcement'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-pr-before-aon');
    signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-pr-before-register');

    $prPath = temp_upload_file('pr', $tempFiles);
    $prPayload = operation_payload('api-pr-before-aon', [
        'pr_file' => upload_manifest_entry('pr_file', 'pr'),
    ]);
    $response = signed_controller_request(
        $controller,
        'import_pr',
        $prPayload,
        K2ImportOperationType::PR,
        'nonce-pr-before',
        'request-pr-before',
        ['pr_file' => upload_file_array($prPath)]
    );

    assert_same(409, $response->status);
    assert_same(K2ImportResponseCode::INVALID_TRANSITION, $response->body['code']);
};

// -----------------------------------------------------------------------
// Async queue lifecycle: import_aon/import_pr now only queue. A "worker" is
// simulated via the orchestrator's claim/success/failure methods directly —
// the actual XLSX normalization/parsing is covered by
// test_parser_adapters(_db).php and the CLI worker's own tests, not here.
// -----------------------------------------------------------------------

$tests['AON upload queues successfully and worker completes it'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller, $service] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-full-flow');
    $registered = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-full-register');
    assert_same(201, $registered->status);

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $aon = signed_controller_request(
        $controller,
        'import_aon',
        operation_payload('api-full-flow', [
            'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
            'price_file' => upload_manifest_entry('price_file', 'price'),
        ]),
        K2ImportOperationType::AON,
        'nonce-full-aon',
        'request-full-aon',
        [
            'catalog_file' => upload_file_array($catalogPath),
            'price_file' => upload_file_array($pricePath),
        ]
    );
    assert_same(202, $aon->status);
    assert_same(K2ImportResponseCode::OPERATION_STARTED, $aon->body['code']);
    assert_same(K2ImportBatchState::AON_QUEUED, $aon->body['state']);
    assert_true(is_array($aon->body['result']['staged_file_manifest'] ?? null) === false, 'staged storage paths must never leak into the HTTP response');

    $afterAon = simulate_worker_success($service, $registered->body['batch_uid'], K2ImportOperationType::AON);
    assert_same(K2ImportBatchState::AON_SUCCESS, $afterAon['state']);
    assert_same(K2ImportNextAction::PR, $afterAon['next_action']);

    $prPath = temp_upload_file('pr', $tempFiles);
    $pr = signed_controller_request(
        $controller,
        'import_pr',
        operation_payload('api-full-flow', [
            'pr_file' => upload_manifest_entry('pr_file', 'pr'),
        ]),
        K2ImportOperationType::PR,
        'nonce-full-pr',
        'request-full-pr',
        ['pr_file' => upload_file_array($prPath)]
    );
    assert_same(202, $pr->status);
    assert_same(K2ImportBatchState::PR_QUEUED, $pr->body['state']);

    $afterPr = simulate_worker_success($service, $registered->body['batch_uid'], K2ImportOperationType::PR);
    assert_same(K2ImportBatchState::ARCHIVE_PENDING, $afterPr['state']);
    assert_same(K2ImportArchiveState::PENDING, $afterPr['archive_state']);
    assert_same(K2ImportNextAction::ARCHIVE, $afterPr['next_action']);

    $archive = signed_controller_request(
        $controller,
        'archive_result',
        archive_payload('api-full-flow', true),
        K2ImportOperationType::ARCHIVE,
        'nonce-full-archive',
        'request-full-archive'
    );
    assert_same(200, $archive->status);
    assert_same(K2ImportBatchState::COMPLETED, $archive->body['state']);
    assert_same(K2ImportArchiveState::COMPLETED, $archive->body['archive_state']);
    assert_same(K2ImportNextAction::NONE, $archive->body['next_action']);
};

$tests['batch status exposes registered manifest'] = function () use ($pdo, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $payload = register_payload('api-status-manifest');
    $registered = signed_controller_request($controller, 'register_batch', $payload, 'register_batch', 'nonce-status-manifest-register');
    assert_same(201, $registered->status);

    $status = signed_controller_request(
        $controller,
        'batch_status',
        ['batch_uid' => $registered->body['batch_uid']],
        'batch_status',
        'nonce-status-manifest',
        'request-status-manifest',
        []
    );

    assert_same(200, $status->status);
    assert_same(K2ImportResponseCode::BATCH_STATUS, $status->body['code']);
    assert_same(3, count($status->body['result']['registered_manifest']));
    assert_same('pr', (string) $status->body['result']['registered_manifest'][0]['dataset_type']);
    assert_same('drive-api-status-manifest-pr', (string) $status->body['result']['registered_manifest'][0]['drive_file_id']);
    assert_false(isset($status->body['result']['registered_manifest'][0]['server_path']));
};

$tests['archive start returns manifest and result completes same attempt'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller, $service] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $registered = signed_controller_request($controller, 'register_batch', register_payload('api-start-archive'), 'register_batch', 'nonce-start-archive-register');

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    signed_controller_request($controller, 'import_aon', operation_payload('api-start-archive', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]), K2ImportOperationType::AON, 'nonce-start-archive-aon', 'request-start-archive-aon', [
        'catalog_file' => upload_file_array($catalogPath),
        'price_file' => upload_file_array($pricePath),
    ]);
    simulate_worker_success($service, $registered->body['batch_uid'], K2ImportOperationType::AON);

    $prPath = temp_upload_file('pr', $tempFiles);
    $pr = signed_controller_request($controller, 'import_pr', operation_payload('api-start-archive', [
        'pr_file' => upload_manifest_entry('pr_file', 'pr'),
    ]), K2ImportOperationType::PR, 'nonce-start-archive-pr', 'request-start-archive-pr', [
        'pr_file' => upload_file_array($prPath),
    ]);
    assert_same(K2ImportBatchState::PR_QUEUED, $pr->body['state']);
    $afterPr = simulate_worker_success($service, $registered->body['batch_uid'], K2ImportOperationType::PR);
    assert_same(K2ImportBatchState::ARCHIVE_PENDING, $afterPr['state']);

    $startPayload = archive_start_payload('api-start-archive', 1);
    $start = signed_controller_request(
        $controller,
        'start_archive',
        $startPayload,
        K2ImportOperationType::ARCHIVE,
        'nonce-start-archive-1',
        'request-start-archive'
    );
    assert_same(200, $start->status);
    assert_same(K2ImportResponseCode::OPERATION_STARTED, $start->body['code']);
    assert_same(K2ImportArchiveState::PROCESSING, $start->body['archive_state']);
    assert_same(3, count($start->body['result']['registered_manifest']));
    assert_same('request-start-archive', $start->body['active_request_id']);

    $replayStart = signed_controller_request(
        $controller,
        'start_archive',
        $startPayload,
        K2ImportOperationType::ARCHIVE,
        'nonce-start-archive-2',
        'request-start-archive'
    );
    assert_same(200, $replayStart->status);
    assert_same(K2ImportResponseCode::REQUEST_IN_PROGRESS, $replayStart->body['code']);

    $result = signed_controller_request(
        $controller,
        'archive_result',
        archive_payload('api-start-archive', true),
        K2ImportOperationType::ARCHIVE,
        'nonce-start-archive-result',
        'request-start-archive'
    );
    assert_same(200, $result->status);
    assert_same(K2ImportBatchState::COMPLETED, $result->body['state']);
    assert_same(K2ImportArchiveState::COMPLETED, $result->body['archive_state']);

    $resultReplay = signed_controller_request(
        $controller,
        'archive_result',
        archive_payload('api-start-archive', true),
        K2ImportOperationType::ARCHIVE,
        'nonce-start-archive-result-replay',
        'request-start-archive'
    );
    assert_same(200, $resultReplay->status);
    assert_same(K2ImportResponseCode::REQUEST_REPLAYED, $resultReplay->body['code']);
};

$tests['retryable AON failure permits deliberate attempt two'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller, $service] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $registered = signed_controller_request($controller, 'register_batch', register_payload('api-aon-attempt-two'), 'register_batch', 'nonce-aon-attempt-register');

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $payload = operation_payload('api-aon-attempt-two', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);
    $files = [
        'catalog_file' => upload_file_array($catalogPath),
        'price_file' => upload_file_array($pricePath),
    ];
    $first = signed_controller_request($controller, 'import_aon', $payload, K2ImportOperationType::AON, 'nonce-aon-attempt-1', 'request-aon-attempt-1', $files);
    assert_same(202, $first->status);
    assert_same(K2ImportBatchState::AON_QUEUED, $first->body['state']);

    $failure = simulate_worker_failure($service, $registered->body['batch_uid'], K2ImportOperationType::AON, 'TEMPORARY_AON_ERROR', 'Synthetic retryable AON failure.', true);
    assert_same(K2ImportBatchState::AON_FAILED_RETRYABLE, $failure['state']);
    assert_same(1, $failure['current_attempt_number']);
    assert_same(2, $failure['next_attempt_number']);

    // A real retry arrives as a brand new upload (its own PHP-assigned tmp path);
    // attempt 1's files were already moved into private storage by stage().
    $payload['attempt_number'] = 2;
    $retryFiles = [
        'catalog_file' => upload_file_array(temp_upload_file('catalog', $tempFiles)),
        'price_file' => upload_file_array(temp_upload_file('price', $tempFiles)),
    ];
    $second = signed_controller_request($controller, 'import_aon', $payload, K2ImportOperationType::AON, 'nonce-aon-attempt-2', 'request-aon-attempt-2', $retryFiles);
    assert_same(202, $second->status);
    assert_same(K2ImportBatchState::AON_QUEUED, $second->body['state']);

    $success = simulate_worker_success($service, $registered->body['batch_uid'], K2ImportOperationType::AON);
    assert_same(K2ImportBatchState::AON_SUCCESS, $success['state']);
    assert_same('request-aon-attempt-2', $success['active_request_id']);
};

$tests['retryable PR failure returns next attempt two'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller, $service] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $registered = signed_controller_request($controller, 'register_batch', register_payload('api-pr-attempt-two'), 'register_batch', 'nonce-pr-attempt-register');

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $aonPayload = operation_payload('api-pr-attempt-two', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);
    signed_controller_request($controller, 'import_aon', $aonPayload, K2ImportOperationType::AON, 'nonce-pr-attempt-aon', 'request-pr-attempt-aon', [
        'catalog_file' => upload_file_array($catalogPath),
        'price_file' => upload_file_array($pricePath),
    ]);
    simulate_worker_success($service, $registered->body['batch_uid'], K2ImportOperationType::AON);

    $prPath = temp_upload_file('pr', $tempFiles);
    $prPayload = operation_payload('api-pr-attempt-two', [
        'pr_file' => upload_manifest_entry('pr_file', 'pr'),
    ]);
    $pr = signed_controller_request($controller, 'import_pr', $prPayload, K2ImportOperationType::PR, 'nonce-pr-attempt-1', 'request-pr-attempt-1', [
        'pr_file' => upload_file_array($prPath),
    ]);
    assert_same(202, $pr->status);
    assert_same(K2ImportBatchState::PR_QUEUED, $pr->body['state']);

    $failure = simulate_worker_failure($service, $registered->body['batch_uid'], K2ImportOperationType::PR, 'TEMPORARY_PR_ERROR', 'Synthetic retryable PR failure.', true);
    assert_same(K2ImportBatchState::PR_FAILED_RETRYABLE, $failure['state']);
    assert_same(1, $failure['current_attempt_number']);
    assert_same(2, $failure['next_attempt_number']);
};

$tests['invalid attempt number is rejected before queueing'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller, , $repo] = controller_fixture($pdo, 4096, $tempStorageRoots);
    $registered = signed_controller_request($controller, 'register_batch', register_payload('api-invalid-attempt'), 'register_batch', 'nonce-invalid-attempt-register');
    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $payload = operation_payload('api-invalid-attempt', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);
    $payload['attempt_number'] = 2;
    $response = signed_controller_request($controller, 'import_aon', $payload, K2ImportOperationType::AON, 'nonce-invalid-attempt', 'request-invalid-attempt', [
        'catalog_file' => upload_file_array($catalogPath),
        'price_file' => upload_file_array($pricePath),
    ]);
    assert_same(409, $response->status);
    assert_same(K2ImportResponseCode::INVALID_ATTEMPT_NUMBER, $response->body['code']);

    $batch = $repo->findBatchByUid($registered->body['batch_uid']);
    assert_same(K2ImportBatchState::DETECTED, (string) $batch['state']);
    $attemptCount = fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM k2_automation_operation_attempts WHERE batch_id = ?',
        [(int) $batch['id']]
    );
    assert_same(0, (int) $attemptCount['n']);
};

// Repurposes the old "completed request replays without rerunning adapter"
// case: with execution moved out of the request entirely, the meaningful
// duplicate-replay scenario is a transport-retry while the attempt is still
// queued (not yet claimed by a worker) — scenario K7. Deliberately left
// unresolved and run last so no later test's claim can pick up this batch's
// dangling AON_QUEUED attempt (claimNextQueuedAttempt has no per-test batch
// scoping — see simulate_worker_success()'s own defensive assertion).
$tests['duplicate queued request replay returns existing queued result'] = function () use ($pdo, &$tempFiles, &$tempStorageRoots): void {
    [$controller] = controller_fixture($pdo, 4096, $tempStorageRoots);
    signed_controller_request($controller, 'register_batch', register_payload('api-request-replay'), 'register_batch', 'nonce-replay-register');

    $catalogPath = temp_upload_file('catalog', $tempFiles);
    $pricePath = temp_upload_file('price', $tempFiles);
    $payload = operation_payload('api-request-replay', [
        'catalog_file' => upload_manifest_entry('catalog_file', 'catalog'),
        'price_file' => upload_manifest_entry('price_file', 'price'),
    ]);
    $files = [
        'catalog_file' => upload_file_array($catalogPath),
        'price_file' => upload_file_array($pricePath),
    ];
    $first = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-replay-aon-1',
        'request-replay-aon',
        $files
    );
    // A transport-level retry re-sends the same bytes as a fresh upload (its own
    // PHP-assigned tmp path) — attempt 1's files were already moved by stage().
    $retryFiles = [
        'catalog_file' => upload_file_array(temp_upload_file('catalog', $tempFiles)),
        'price_file' => upload_file_array(temp_upload_file('price', $tempFiles)),
    ];
    $second = signed_controller_request(
        $controller,
        'import_aon',
        $payload,
        K2ImportOperationType::AON,
        'nonce-replay-aon-2',
        'request-replay-aon',
        $retryFiles
    );

    assert_same(202, $first->status);
    assert_same(202, $second->status);
    assert_same(K2ImportResponseCode::REQUEST_IN_PROGRESS, $second->body['code']);
    assert_same(true, $second->body['replayed']);
    assert_same(K2ImportBatchState::AON_QUEUED, $second->body['state']);

    $row = fetch_one(
        $pdo,
        'SELECT COUNT(*) AS n FROM k2_automation_operation_attempts a ' .
        'JOIN k2_automation_import_batches b ON b.id = a.batch_id ' .
        'WHERE b.batch_uid = ? AND a.operation_type = ?',
        [$first->body['batch_uid'], K2ImportOperationType::AON]
    );
    assert_same(1, (int) $row['n']);
};

function simulate_worker_claim(K2ImportOrchestrator $service, string $workerToken = 'test-worker-token'): ?array
{
    return $service->claimNextForProcessing($workerToken, 'test-host', 424242, 900);
}

/** Simulates a worker successfully completing whatever it claims; asserts it claimed the expected batch/operation. */
function simulate_worker_success(K2ImportOrchestrator $service, string $expectedBatchUid, string $expectedOperationType, array $counters = []): array
{
    $claimed = simulate_worker_claim($service);
    if ($claimed === null) {
        throw new RuntimeException("Nothing claimable (expected {$expectedBatchUid}/{$expectedOperationType}).");
    }
    if ($claimed['batch_uid'] !== $expectedBatchUid || $claimed['operation_type'] !== $expectedOperationType) {
        throw new RuntimeException(
            "Claimed {$claimed['batch_uid']}/{$claimed['operation_type']} but test expected " .
            "{$expectedBatchUid}/{$expectedOperationType} — a dangling queued attempt from another test was claimed instead."
        );
    }

    $lock = $service->claimBatchLock($claimed['batch_uid'], null, 'worker-request');
    assert_true($lock['success'], 'Simulated worker could not claim batch lock: ' . json_encode($lock));
    $lockToken = (string) $lock['result']['lock_token'];
    $response = $service->recordOperationSuccess(
        $claimed['batch_uid'],
        $claimed['operation_type'],
        $claimed['request_idempotency_key'],
        (int) $claimed['attempt_id'],
        K2ImportOrchestrator::normalizeCounters($counters),
        $lockToken,
        'worker-request'
    );
    if ($claimed['operation_type'] === K2ImportOperationType::PR && $response['state'] === K2ImportBatchState::IMPORT_SUCCESS) {
        $response = $service->markArchivePending($claimed['batch_uid'], $lockToken, 'worker-request');
    }
    $service->releaseBatchLock($claimed['batch_uid'], $lockToken, 'worker-request');

    return $response;
}

/** Simulates a worker failing whatever it claims; asserts it claimed the expected batch/operation. */
function simulate_worker_failure(
    K2ImportOrchestrator $service,
    string $expectedBatchUid,
    string $expectedOperationType,
    string $errorCode,
    string $message,
    bool $retryable
): array {
    $claimed = simulate_worker_claim($service);
    if ($claimed === null) {
        throw new RuntimeException("Nothing claimable (expected {$expectedBatchUid}/{$expectedOperationType}).");
    }
    if ($claimed['batch_uid'] !== $expectedBatchUid || $claimed['operation_type'] !== $expectedOperationType) {
        throw new RuntimeException(
            "Claimed {$claimed['batch_uid']}/{$claimed['operation_type']} but test expected " .
            "{$expectedBatchUid}/{$expectedOperationType} — a dangling queued attempt from another test was claimed instead."
        );
    }

    $lock = $service->claimBatchLock($claimed['batch_uid'], null, 'worker-request');
    assert_true($lock['success'], 'Simulated worker could not claim batch lock: ' . json_encode($lock));
    $lockToken = (string) $lock['result']['lock_token'];
    $response = $service->recordOperationFailure(
        $claimed['batch_uid'],
        $claimed['operation_type'],
        $claimed['request_idempotency_key'],
        (int) $claimed['attempt_id'],
        $errorCode,
        $message,
        $retryable,
        $lockToken,
        'worker-request'
    );
    $service->releaseBatchLock($claimed['batch_uid'], $lockToken, 'worker-request');

    return $response;
}

function controller_fixture(PDO $pdo, int $maxUploadBytes, array &$tempStorageRoots): array
{
    $repo = new PdoK2ImportRunRepository($pdo);
    $service = new K2ImportOrchestrator($repo, 60, static fn(): string => 'api-test-lock-' . bin2hex(random_bytes(4)));
    $storageRoot = sys_get_temp_dir() . '/k2-automation-test-storage-' . bin2hex(random_bytes(6));
    $tempStorageRoots[] = $storageRoot;
    $fileStore = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($storageRoot));
    $controller = new K2AutomationApiController(
        new K2AutomationApiConfig('client-a', 'test-secret', 300, $maxUploadBytes, 60, '/vms/workspace/api/k2_automation'),
        new PdoK2AutomationNonceStore($pdo),
        $service,
        $fileStore,
        new DateTimeImmutable('2026-07-22T02:00:00Z')
    );

    return [$controller, $service, $repo, 'source_file_objects'];
}

function signed_controller_request(
    K2AutomationApiController $controller,
    string $endpoint,
    array $payload,
    string $operation,
    string $nonce,
    string $requestId = 'request-api',
    array $files = [],
    ?array $postOverride = null,
    ?array $serverOverride = null
): K2AutomationApiResponse {
    [$headers, $rawBody] = signed_request_parts($endpoint, $payload, $operation, $nonce, null, $requestId);
    $isMultipart = $files !== [] || in_array($endpoint, ['import_aon', 'import_pr'], true);
    $post = $postOverride ?? ($isMultipart ? ['metadata' => $rawBody] : []);
    $server = $serverOverride ?? ($isMultipart ? [
        'CONTENT_TYPE' => 'multipart/form-data; boundary=k2-test',
        'CONTENT_LENGTH' => (string) (strlen($rawBody) + 256),
    ] : []);

    return $controller->handle($endpoint, 'POST', endpoint_path($endpoint), $headers, $rawBody, $files, $post, $server);
}

function signed_request_parts(
    string $endpoint,
    array $payload,
    string $operation,
    string $nonce,
    ?string $batchUid = null,
    string $requestId = 'request-api',
    string $timestamp = '2026-07-22T02:00:00Z'
): array {
    $batchUid ??= (string) ($payload['batch_uid'] ?? K2ImportIdentity::batchUid(source_file_objects('unused-header')));
    $manifest = $payload['file_manifest'] ?? [];
    $metadataHash = K2AutomationJson::sha256($payload);
    $manifestHash = K2AutomationJson::sha256(['files' => $manifest]);
    $canonical = K2AutomationSignature::canonicalString(
        'POST',
        endpoint_path($endpoint),
        'client-a',
        $timestamp,
        $nonce,
        $requestId,
        $operation,
        $batchUid,
        $metadataHash,
        $manifestHash
    );

    return [[
        'X-K2-Client-ID' => 'client-a',
        'X-K2-Timestamp' => $timestamp,
        'X-K2-Nonce' => $nonce,
        'X-K2-Request-ID' => $requestId,
        'X-K2-Batch-UID' => $batchUid,
        'X-K2-Operation' => $operation,
        'X-K2-Metadata-SHA256' => $metadataHash,
        'X-K2-File-Manifest-SHA256' => $manifestHash,
        'X-K2-Signature' => K2AutomationSignature::sign($canonical, 'test-secret'),
    ], K2AutomationJson::canonicalJson($payload)];
}

function endpoint_path(string $endpoint): string
{
    return match ($endpoint) {
        'register_batch' => '/vms/workspace/api/k2_automation/register_batch.php',
        'batch_status' => '/vms/workspace/api/k2_automation/batch_status.php',
        'import_aon' => '/vms/workspace/api/k2_automation/import_aon.php',
        'import_pr' => '/vms/workspace/api/k2_automation/import_pr.php',
        'start_archive' => '/vms/workspace/api/k2_automation/start_archive.php',
        'archive_result' => '/vms/workspace/api/k2_automation/archive_result.php',
        default => '/vms/workspace/api/k2_automation/unknown.php',
    };
}

function register_payload(string $suffix): array
{
    $files = source_file_objects($suffix);

    return [
        'batch_uid' => K2ImportIdentity::batchUid($files),
        'scheduled_slot_at_utc' => '2026-07-22 09:00:00',
        'schedule_timezone' => 'Asia/Jakarta',
        'source_files' => source_files_payload($files),
    ];
}

function operation_payload(string $suffix, array $fileManifest): array
{
    return array_merge(register_payload($suffix), [
        'attempt_number' => 1,
        'file_manifest' => array_values($fileManifest),
    ]);
}

function archive_payload(string $suffix, bool $success): array
{
    return array_merge(register_payload($suffix), [
        'attempt_number' => 1,
        'archive_success' => $success,
        'retryable' => !$success,
        'error_code' => $success ? null : 'ARCHIVE_TEMPORARY_ERROR',
        'safe_message' => $success ? null : 'Synthetic archive failure.',
    ]);
}

function archive_start_payload(string $suffix, int $attemptNumber = 1): array
{
    return [
        'batch_uid' => K2ImportIdentity::batchUid(source_file_objects($suffix)),
        'attempt_number' => $attemptNumber,
    ];
}

function source_file_objects(string $suffix): array
{
    $contents = fixture_contents();
    $files = [];
    foreach (K2ImportDatasetType::requiredTypes() as $index => $datasetType) {
        $files[$datasetType] = new K2ImportSourceFileMetadata(
            $datasetType,
            "drive-{$suffix}-{$datasetType}",
            "{$suffix}-{$datasetType}.xlsx",
            sprintf('2026-07-22T01:0%d:00+07:00', $index),
            strlen($contents[$datasetType]),
            hash('sha256', $contents[$datasetType])
        );
    }

    return $files;
}

function source_files_payload(array $files): array
{
    return array_values(array_map(
        static fn(K2ImportSourceFileMetadata $file): array => $file->canonicalArray(),
        K2ImportIdentity::normalizeSourceFiles($files)
    ));
}

function fixture_contents(): array
{
    return [
        K2ImportDatasetType::PR => 'pr',
        K2ImportDatasetType::CATALOG => 'catalog',
        K2ImportDatasetType::PRICE => 'price',
    ];
}

function upload_manifest_entry(string $field, string $datasetType, ?string $hashContentOverride = null): array
{
    $contents = fixture_contents();
    $content = $hashContentOverride ?? $contents[$datasetType];

    return [
        'field' => $field,
        'filename' => "{$datasetType}.xlsx",
        'file_size_bytes' => strlen($contents[$datasetType]),
        'sha256_content_hash' => hash('sha256', $content),
    ];
}

function temp_upload_file(string $datasetType, array &$tempFiles): string
{
    $contents = fixture_contents();
    $path = tempnam(sys_get_temp_dir(), 'k2_api_upload_');
    if ($path === false) {
        throw new RuntimeException('Could not create temporary upload file.');
    }

    file_put_contents($path, $contents[$datasetType]);
    $tempFiles[] = $path;

    return $path;
}

function upload_file_array(string $path): array
{
    return [
        'tmp_name' => $path,
        'name' => basename($path),
        'size' => filesize($path),
        'error' => UPLOAD_ERR_OK,
    ];
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

function assert_no_stack_leak(array $body): void
{
    $encoded = json_encode($body);
    if ($encoded === false || str_contains($encoded, 'Stack trace') || str_contains($encoded, __DIR__)) {
        throw new RuntimeException('Response leaked diagnostic details.');
    }
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
        if (is_dir($path)) {
            rrmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

$failures = 0;
try {
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
    foreach ($tempFiles as $tempFile) {
        if (is_file($tempFile)) {
            @unlink($tempFile);
        }
    }
    foreach ($tempStorageRoots as $storageRoot) {
        rrmdir($storageRoot);
    }
}

if ($failures > 0) {
    echo "{$failures} API controller database test(s) failed.\n";
    exit(1);
}

echo count($tests) . " API controller database test(s) passed.\n";
