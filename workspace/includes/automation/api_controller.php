<?php
declare(strict_types=1);

require_once __DIR__ . '/api_request.php';
require_once __DIR__ . '/api_auth.php';
require_once __DIR__ . '/import_orchestrator.php';
require_once __DIR__ . '/file_storage.php';
require_once __DIR__ . '/diagnostics_logger.php';

final class K2AutomationApiResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $body,
        public readonly array $headers = []
    ) {
    }
}

final class K2AutomationMalformedRequestException extends InvalidArgumentException
{
}

final class K2AutomationApiController
{
    public function __construct(
        private readonly K2AutomationApiConfig $config,
        private readonly K2AutomationNonceStoreInterface $nonceStore,
        private readonly K2ImportOrchestrator $orchestrator,
        private readonly K2AutomationStagedFileStore $fileStore,
        private readonly DateTimeImmutable $now,
        private readonly ?K2AutomationDiagnosticsLogger $logger = null
    ) {
    }

    public function handle(
        string $endpoint,
        string $method,
        string $path,
        array $headers,
        string $rawBody,
        array $files = [],
        array $post = [],
        array $server = []
    ): K2AutomationApiResponse {
        try {
            if (strtoupper($method) !== 'POST') {
                return $this->error(405, 'METHOD_NOT_ALLOWED', 'Unsupported request method.');
            }

            $request = K2AutomationRequestExtractor::extract(
                $endpoint,
                $method,
                $path,
                $headers,
                $rawBody,
                $post,
                $files,
                $server,
                $this->config
            );

            $auth = K2AutomationSignature::verify(
                $request->method,
                $request->path,
                $request->headers,
                $this->config,
                $this->nonceStore,
                $this->now,
                $endpoint
            );

            return match ($endpoint) {
                'register_batch' => $this->registerBatch($auth, $request->metadataRaw),
                'batch_status' => $this->batchStatus($auth, $request->metadataRaw),
                'import_aon' => $this->importAon($auth, $request->metadataRaw, $request->files),
                'import_pr' => $this->importPr($auth, $request->metadataRaw, $request->files),
                'start_archive' => $this->startArchive($auth, $request->metadataRaw),
                'archive_result' => $this->archiveResult($auth, $request->metadataRaw),
                default => $this->error(404, 'UNKNOWN_ENDPOINT', 'Unknown K2 automation endpoint.'),
            };
        } catch (K2AutomationRequestExtractionException $e) {
            return $this->error($e->httpStatus(), $e->stableCode(), $e->getMessage());
        } catch (K2AutomationApiAuthException $e) {
            return $this->error($e->httpStatus(), $e->stableCode(), $e->getMessage());
        } catch (K2AutomationMalformedRequestException $e) {
            return $this->error(400, 'MALFORMED_REQUEST', $this->safeMessage($e->getMessage()));
        } catch (K2ImportDuplicateKeyConflictException) {
            return $this->error(409, 'IDEMPOTENCY_CONFLICT', 'Conflicting idempotency key or source metadata.');
        } catch (K2ImportOrchestrationException $e) {
            return $this->error($this->statusForCode($e->stableCode()), $e->stableCode(), $e->getMessage(), $e->retryable());
        } catch (InvalidArgumentException $e) {
            return $this->error(422, 'INVALID_REQUEST', $this->safeMessage($e->getMessage()));
        } catch (Throwable) {
            return $this->error(500, 'UNEXPECTED_SERVER_ERROR', 'Unexpected server failure.');
        }
    }

    private function registerBatch(array $auth, string $rawBody): K2AutomationApiResponse
    {
        $this->assertOperationHeader($auth, 'register_batch');
        $this->assertEmptyFileManifest($auth);
        $payload = $this->decodeJsonPayload($rawBody, $auth['metadata_sha256']);
        $files = $this->sourceFilesFromPayload($payload);
        $this->assertSignedBatchUidMatches($auth['batch_uid'], K2ImportIdentity::batchUid($files));
        $schedule = $this->scheduleFromPayload($payload);
        $response = $this->orchestrator->registerBatch($files, $schedule, $auth['request_id']);

        return $this->json($response['replayed'] ? 200 : 201, $response);
    }

    private function batchStatus(array $auth, string $rawBody): K2AutomationApiResponse
    {
        $this->assertOperationHeader($auth, 'batch_status');
        $this->assertEmptyFileManifest($auth);
        $payload = $this->decodeJsonPayload($rawBody, $auth['metadata_sha256']);
        $batchUid = (string) ($payload['batch_uid'] ?? $auth['batch_uid']);
        $this->assertSignedBatchUidMatches($auth['batch_uid'], $batchUid);
        $response = $this->orchestrator->batchStatus($batchUid, $auth['request_id']);

        return $this->json($response['success'] ? 200 : 404, $response);
    }

    private function importAon(array $auth, string $rawBody, array $files): K2AutomationApiResponse
    {
        $this->assertOperationHeader($auth, K2ImportOperationType::AON);
        $payload = $this->decodeJsonPayload($rawBody, $auth['metadata_sha256']);
        $this->verifyUploadedFiles($files, $payload['file_manifest'] ?? [], $auth['file_manifest_sha256'], [
            'catalog_file',
            'price_file',
        ]);

        return $this->queueOperation($auth, $payload, $files, K2ImportOperationType::AON, [
            'catalog_file' => K2ImportDatasetType::CATALOG,
            'price_file' => K2ImportDatasetType::PRICE,
        ]);
    }

    private function importPr(array $auth, string $rawBody, array $files): K2AutomationApiResponse
    {
        $this->assertOperationHeader($auth, K2ImportOperationType::PR);
        $payload = $this->decodeJsonPayload($rawBody, $auth['metadata_sha256']);
        $this->verifyUploadedFiles($files, $payload['file_manifest'] ?? [], $auth['file_manifest_sha256'], [
            'pr_file',
        ]);

        return $this->queueOperation($auth, $payload, $files, K2ImportOperationType::PR, [
            'pr_file' => K2ImportDatasetType::PR,
        ]);
    }

    /**
     * Shared AON/PR queueing path (Area E). Validates upload identity, stages the
     * files privately (Area B), then persists a queued attempt (Area C/D) and
     * returns immediately — XLSX normalization and business writes happen later in
     * workspace/cron/process_k2_import_queue.php, never inside this request.
     *
     * @param array<string, string> $datasetTypeByField e.g. ['catalog_file' => 'catalog', 'price_file' => 'price']
     */
    private function queueOperation(
        array $auth,
        array $payload,
        array $files,
        string $operationType,
        array $datasetTypeByField
    ): K2AutomationApiResponse {
        $sourceFiles = $this->sourceFilesFromPayload($payload);
        $this->assertSignedBatchUidMatches($auth['batch_uid'], (string) ($payload['batch_uid'] ?? ''));
        $this->assertSignedBatchUidMatches($auth['batch_uid'], K2ImportIdentity::batchUid($sourceFiles));
        $attemptNumber = $this->attemptNumberFromPayload($payload);
        $batchUid = (string) $payload['batch_uid'];
        $requestKey = K2ImportIdentity::operationRequestIdempotencyKey(
            $auth['batch_uid'],
            $operationType,
            $auth['request_id'],
            $attemptNumber
        );
        $logContext = ['batch_uid' => $batchUid, 'operation' => $operationType, 'attempt_number' => $attemptNumber, 'request_id' => $auth['request_id']];

        // Fast path: a transport-level retry of an already queued/processing/completed
        // attempt must be replayed, not re-staged and re-queued (Apps Script keeps the
        // logical request ID stable across a retry of the same attempt).
        $existing = $this->orchestrator->checkExistingOperationRequest(
            $batchUid,
            $operationType,
            $requestKey,
            $attemptNumber,
            $auth['request_id']
        );
        if ($existing !== null) {
            $this->logger?->info('QUEUE_REQUEST_REPLAYED', $logContext);

            return $this->json($this->statusForQueuedResponse($existing), $existing);
        }

        $manifestItems = [];
        foreach ($datasetTypeByField as $field => $datasetType) {
            $manifestItems[] = [
                'field' => $field,
                'datasetType' => $datasetType,
                'tmpPath' => (string) ($files[$field]['tmp_name'] ?? ''),
                'originalFilename' => (string) ($files[$field]['name'] ?? ''),
                'expectedSha256' => $this->expectedSha256ForDataset($payload, $datasetType),
            ];
        }

        $this->logger?->info('STAGED_PERSISTENCE_STARTED', $logContext);
        $stageStart = microtime(true);
        try {
            $manifest = $this->fileStore->stageManifest($batchUid, $operationType, $attemptNumber, $manifestItems);
        } catch (K2AutomationFileStorageException $e) {
            $this->logger?->exception('STAGED_PERSISTENCE_FAILED', $e, $logContext);

            return $this->error($e->retryable() ? 503 : 500, $e->stableCode(), 'Could not persist uploaded file to private storage.', $e->retryable());
        }
        $this->logger?->info('STAGED_PERSISTENCE_COMPLETED', $logContext + ['elapsed_ms' => (int) round((microtime(true) - $stageStart) * 1000)]);

        $manifestForDb = array_map(static fn(array $entry): array => [
            'field' => $entry['field'],
            'dataset_type' => $entry['dataset_type'],
            'storage_path' => $entry['storage_path'],
            'sha256_content_hash' => $entry['sha256_content_hash'],
            'file_size_bytes' => $entry['file_size_bytes'],
            'original_filename' => $entry['original_filename'],
        ], $manifest);

        $lock = $this->orchestrator->claimBatchLock($batchUid, null, $auth['request_id']);
        if (!$lock['success']) {
            $this->fileStore->cleanupAttempt($batchUid, $operationType, $attemptNumber);

            return $this->json(409, $lock);
        }
        $lockToken = (string) $lock['result']['lock_token'];

        try {
            $this->logger?->info('QUEUE_PERSISTENCE_STARTED', $logContext);
            $queued = $this->orchestrator->beginOperation(
                $batchUid,
                $operationType,
                $requestKey,
                $lockToken,
                $auth['request_id'],
                $attemptNumber,
                $manifestForDb
            );
        } catch (Throwable $e) {
            // Queue persistence failed after staging succeeded — the staged files
            // must not be left behind with no DB record pointing at them.
            $this->fileStore->cleanupAttempt($batchUid, $operationType, $attemptNumber);
            $this->safeReleaseBatchLock($batchUid, $lockToken, $auth['request_id']);
            $this->logger?->exception('QUEUE_PERSISTENCE_FAILED', $e, $logContext);

            throw $e;
        }
        $this->safeReleaseBatchLock($batchUid, $lockToken, $auth['request_id']);

        if (!$queued['success'] && $queued['code'] !== K2ImportResponseCode::REQUEST_IN_PROGRESS) {
            $this->fileStore->cleanupAttempt($batchUid, $operationType, $attemptNumber);
        }
        $this->logger?->info('QUEUE_PERSISTENCE_COMPLETED', $logContext + ['code' => $queued['code']]);

        return $this->json($this->statusForQueuedResponse($queued), $queued);
    }

    private function statusForQueuedResponse(array $response): int
    {
        return match ($response['code']) {
            K2ImportResponseCode::REQUEST_REPLAYED => 200,
            K2ImportResponseCode::OPERATION_STARTED, K2ImportResponseCode::REQUEST_IN_PROGRESS => 202,
            K2ImportResponseCode::INVALID_ATTEMPT_NUMBER, K2ImportResponseCode::INVALID_TRANSITION => 409,
            default => $response['success'] ? 200 : ($response['retryable'] ? 503 : 422),
        };
    }

    private function expectedSha256ForDataset(array $payload, string $datasetType): string
    {
        foreach (($payload['source_files'] ?? $payload['files'] ?? []) as $file) {
            if ((string) ($file['dataset_type'] ?? '') === $datasetType) {
                return strtolower((string) ($file['sha256_content_hash'] ?? ''));
            }
        }

        throw new K2AutomationMalformedRequestException("Registered source metadata for {$datasetType} is missing.");
    }

    private function startArchive(array $auth, string $rawBody): K2AutomationApiResponse
    {
        $this->assertOperationHeader($auth, K2ImportOperationType::ARCHIVE);
        $this->assertEmptyFileManifest($auth);
        $payload = $this->decodeJsonPayload($rawBody, $auth['metadata_sha256']);
        $batchUid = (string) ($payload['batch_uid'] ?? $auth['batch_uid']);
        $this->assertSignedBatchUidMatches($auth['batch_uid'], $batchUid);
        $attemptNumber = $this->attemptNumberFromPayload($payload);
        $requestKey = K2ImportIdentity::operationRequestIdempotencyKey(
            $auth['batch_uid'],
            K2ImportOperationType::ARCHIVE,
            $auth['request_id'],
            $attemptNumber
        );
        $lock = $this->orchestrator->claimBatchLock($batchUid, null, $auth['request_id']);
        if (!$lock['success']) {
            return $this->json(409, $lock);
        }

        $lockToken = (string) $lock['result']['lock_token'];
        try {
            $response = $this->orchestrator->beginOperation(
                $batchUid,
                K2ImportOperationType::ARCHIVE,
                $requestKey,
                $lockToken,
                $auth['request_id'],
                $attemptNumber
            );
            if ($response['code'] === K2ImportResponseCode::REQUEST_IN_PROGRESS
                && (string) ($response['result']['operation_type'] ?? '') === K2ImportOperationType::ARCHIVE
            ) {
                $response['success'] = true;
                $response['retryable'] = false;
                $response['error'] = null;
                $response['message'] = 'Archive operation is already processing.';
            }
        } finally {
            $this->safeReleaseBatchLock($batchUid, $lockToken, $auth['request_id']);
        }

        return $this->json($response['success'] ? 200 : 409, $response);
    }

    private function archiveResult(array $auth, string $rawBody): K2AutomationApiResponse
    {
        $this->assertOperationHeader($auth, K2ImportOperationType::ARCHIVE);
        $this->assertEmptyFileManifest($auth);
        $payload = $this->decodeJsonPayload($rawBody, $auth['metadata_sha256']);
        $sourceFiles = $this->sourceFilesFromPayload($payload);
        $this->assertSignedBatchUidMatches($auth['batch_uid'], (string) ($payload['batch_uid'] ?? ''));
        $this->assertSignedBatchUidMatches($auth['batch_uid'], K2ImportIdentity::batchUid($sourceFiles));
        $attemptNumber = $this->attemptNumberFromPayload($payload);
        $requestKey = K2ImportIdentity::operationRequestIdempotencyKey(
            $auth['batch_uid'],
            K2ImportOperationType::ARCHIVE,
            $auth['request_id'],
            $attemptNumber
        );
        $lock = $this->orchestrator->claimBatchLock((string) $payload['batch_uid'], null, $auth['request_id']);
        if (!$lock['success']) {
            return $this->json(409, $lock);
        }

        $lockToken = (string) $lock['result']['lock_token'];
        try {
            $start = $this->orchestrator->beginOperation(
                (string) $payload['batch_uid'],
                K2ImportOperationType::ARCHIVE,
                $requestKey,
                $lockToken,
                $auth['request_id'],
                $attemptNumber
            );
            if ($start['code'] === K2ImportResponseCode::REQUEST_REPLAYED) {
                return $this->json(200, $start);
            }
            if (!$start['success'] && $start['code'] !== K2ImportResponseCode::REQUEST_IN_PROGRESS) {
                return $this->json(409, $start);
            }
            if ($start['code'] === K2ImportResponseCode::REQUEST_IN_PROGRESS
                && (string) ($start['result']['operation_type'] ?? '') !== K2ImportOperationType::ARCHIVE
            ) {
                return $this->json(409, $start);
            }
            $attemptId = (int) ($start['result']['attempt_id'] ?? 0);
            if ($attemptId < 1) {
                throw new K2AutomationMalformedRequestException('Archive attempt is not available for completion.');
            }

            $success = (bool) ($payload['archive_success'] ?? false);
            $response = $success
                ? $this->orchestrator->recordOperationSuccess(
                    (string) $payload['batch_uid'],
                    K2ImportOperationType::ARCHIVE,
                    $requestKey,
                    $attemptId,
                    [],
                    $lockToken,
                    $auth['request_id']
                )
                : $this->orchestrator->recordOperationFailure(
                    (string) $payload['batch_uid'],
                    K2ImportOperationType::ARCHIVE,
                    $requestKey,
                    $attemptId,
                    (string) ($payload['error_code'] ?? 'ARCHIVE_FAILED'),
                    (string) ($payload['safe_message'] ?? 'Archive move failed.'),
                    (bool) ($payload['retryable'] ?? true),
                    $lockToken,
                    $auth['request_id']
                );
        } finally {
            $this->safeReleaseBatchLock((string) $payload['batch_uid'], $lockToken, $auth['request_id']);
        }

        return $this->json($response['success'] ? 200 : ($response['retryable'] ? 503 : 422), $response);
    }

    private function decodeJsonPayload(string $rawBody, string $expectedHash): array
    {
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            throw new K2AutomationMalformedRequestException('Request body must be valid JSON.');
        }

        $actualHash = K2AutomationJson::sha256($payload);
        if (!hash_equals($expectedHash, $actualHash)) {
            throw new InvalidArgumentException('Metadata SHA-256 does not match the signed payload.');
        }

        return $payload;
    }

    private function assertOperationHeader(array $auth, string $expectedOperation): void
    {
        if ((string) ($auth['operation'] ?? '') !== $expectedOperation) {
            throw new InvalidArgumentException('Signed operation does not match the endpoint.');
        }
    }

    private function assertEmptyFileManifest(array $auth): void
    {
        $emptyManifestHash = K2AutomationJson::sha256(['files' => []]);
        if (!hash_equals($emptyManifestHash, (string) ($auth['file_manifest_sha256'] ?? ''))) {
            throw new InvalidArgumentException('JSON-only endpoint file manifest must be empty.');
        }
    }

    private function assertSignedBatchUidMatches(string $signedBatchUid, string $actualBatchUid): void
    {
        if ($actualBatchUid === '' || !hash_equals($signedBatchUid, $actualBatchUid)) {
            throw new InvalidArgumentException('Signed batch UID does not match request metadata.');
        }
    }

    private function verifyUploadedFiles(
        array $files,
        array $expectedManifest,
        string $expectedManifestHash,
        array $requiredFields
    ): void {
        if (K2AutomationJson::sha256(['files' => $expectedManifest]) !== $expectedManifestHash) {
            throw new InvalidArgumentException('File manifest SHA-256 does not match signed manifest.');
        }

        $byField = [];
        foreach ($expectedManifest as $entry) {
            $byField[(string) ($entry['field'] ?? '')] = $entry;
        }

        foreach ($requiredFields as $field) {
            if (!isset($files[$field]) || !is_file((string) ($files[$field]['tmp_name'] ?? ''))) {
                throw new InvalidArgumentException("Uploaded file {$field} is required.");
            }

            $size = filesize((string) $files[$field]['tmp_name']);
            if ($size === false || $size > $this->config->maxUploadBytes) {
                throw new K2AutomationApiAuthException('UPLOAD_TOO_LARGE', 'Uploaded file exceeds the configured size limit.', 413);
            }

            $expectedSize = $byField[$field]['file_size_bytes'] ?? null;
            if ($expectedSize !== null && (int) $expectedSize !== (int) $size) {
                throw new InvalidArgumentException('Uploaded file size does not match signed manifest.');
            }

            $actualHash = hash_file('sha256', (string) $files[$field]['tmp_name']);
            $expectedHash = strtolower((string) ($byField[$field]['sha256_content_hash'] ?? ''));
            if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
                throw new InvalidArgumentException('Uploaded file hash does not match signed manifest.');
            }
        }
    }

    private function sourceFilesFromPayload(array $payload): array
    {
        $files = [];
        foreach (($payload['source_files'] ?? $payload['files'] ?? []) as $file) {
            $files[] = new K2ImportSourceFileMetadata(
                (string) ($file['dataset_type'] ?? ''),
                (string) ($file['drive_file_id'] ?? ''),
                (string) ($file['original_filename'] ?? ''),
                (string) ($file['modified_timestamp'] ?? ''),
                (int) ($file['file_size_bytes'] ?? -1),
                (string) ($file['sha256_content_hash'] ?? '')
            );
        }

        return K2ImportIdentity::normalizeSourceFiles($files);
    }

    private function scheduleFromPayload(array $payload): K2ImportScheduleMetadata
    {
        return new K2ImportScheduleMetadata(
            (string) ($payload['scheduled_slot_at_utc'] ?? ''),
            (string) ($payload['schedule_timezone'] ?? '')
        );
    }

    private function attemptNumberFromPayload(array $payload): int
    {
        $attemptNumber = $payload['attempt_number'] ?? null;
        if (!is_int($attemptNumber) || $attemptNumber < 1) {
            throw new InvalidArgumentException('Operation metadata attempt_number must be a positive integer.');
        }

        return $attemptNumber;
    }

    private function json(int $status, array $body): K2AutomationApiResponse
    {
        return new K2AutomationApiResponse($status, $body, self::jsonHeaders());
    }

    private function error(int $status, string $code, string $message, bool $retryable = false): K2AutomationApiResponse
    {
        return $this->json($status, K2ImportResponseFactory::build(
            false,
            $code,
            $message,
            null,
            null,
            false,
            null,
            null,
            K2ImportNextAction::NONE,
            $retryable,
            [],
            ['code' => $code, 'message' => $message]
        ));
    }

    private function statusForCode(string $code): int
    {
        return match ($code) {
            K2ImportResponseCode::LOCK_CONFLICT,
            K2ImportResponseCode::LOCK_RELEASE_CONFLICT,
            K2ImportResponseCode::INVALID_ATTEMPT_NUMBER,
            K2ImportResponseCode::INVALID_TRANSITION => 409,
            K2ImportResponseCode::BATCH_NOT_FOUND => 404,
            default => 422,
        };
    }

    private function safeReleaseBatchLock(string $batchUid, string $lockToken, ?string $requestId): void
    {
        try {
            $this->orchestrator->releaseBatchLock($batchUid, $lockToken, $requestId);
        } catch (Throwable) {
            error_log('K2 automation batch lock release failed.');
        }
    }

    private function safeMessage(string $message): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 500);
    }

    public static function jsonHeaders(): array
    {
        return [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ];
    }
}
