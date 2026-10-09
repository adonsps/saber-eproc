<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/api_controller.php';

$tests = [];

$tests['canonical signature generation'] = function (): void {
    $canonical = K2AutomationSignature::canonicalString(
        'post',
        '/vms/workspace/api/k2_automation/register_batch.php?ignored=1',
        'client-a',
        '2026-07-22T02:00:00Z',
        'nonce-001',
        'request-001',
        'register_batch',
        'k2b_' . str_repeat('a', 64),
        str_repeat('b', 64),
        str_repeat('c', 64)
    );
    $expectedCanonical = "POST\n" .
        "/vms/workspace/api/k2_automation/register_batch.php\n" .
        "client-a\n" .
        "2026-07-22T02:00:00Z\n" .
        "nonce-001\n" .
        "request-001\n" .
        "register_batch\n" .
        'k2b_' . str_repeat('a', 64) . "\n" .
        str_repeat('b', 64) . "\n" .
        str_repeat('c', 64);

    assert_same($expectedCanonical, $canonical);
    assert_same(hash_hmac('sha256', $expectedCanonical, 'test-secret'), K2AutomationSignature::sign($canonical, 'test-secret'));
};

$tests['valid HMAC'] = function (): void {
    $store = new MemoryNonceStore();
    $headers = signed_headers('nonce-valid');
    $auth = K2AutomationSignature::verify(
        'POST',
        '/vms/workspace/api/k2_automation/register_batch.php',
        $headers,
        test_config(),
        $store,
        test_now(),
        'register_batch'
    );

    assert_same('client-a', $auth['client_id']);
    assert_same('nonce-valid', $auth['nonce']);
};

$tests['invalid HMAC'] = function (): void {
    $headers = signed_headers('nonce-invalid');
    $headers['X-K2-Signature'] = str_repeat('0', 64);

    expect_exception(K2AutomationApiAuthException::class, function () use ($headers): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });
};

$tests['public path mismatch is distinct from invalid signature'] = function (): void {
    $headers = signed_headers('nonce-path-mismatch');
    expect_exception_code('PUBLIC_API_PATH_MISMATCH', function () use ($headers): void {
        K2AutomationSignature::verify(
            'POST',
            '/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });
};

$tests['modified metadata or file manifest invalidates signature'] = function (): void {
    $headers = signed_headers('nonce-modified-metadata');
    $headers['X-K2-Metadata-SHA256'] = str_repeat('d', 64);
    expect_exception(K2AutomationApiAuthException::class, function () use ($headers): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });

    $headers = signed_headers('nonce-modified-manifest');
    $headers['X-K2-File-Manifest-SHA256'] = str_repeat('e', 64);
    expect_exception(K2AutomationApiAuthException::class, function () use ($headers): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });
};

$tests['expired and future timestamp'] = function (): void {
    $expired = signed_headers('nonce-expired', '2026-07-22T01:00:00Z');
    expect_exception(K2AutomationApiAuthException::class, function () use ($expired): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $expired,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });

    $future = signed_headers('nonce-future', '2026-07-22T03:00:00Z');
    expect_exception(K2AutomationApiAuthException::class, function () use ($future): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $future,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });
};

$tests['duplicate nonce'] = function (): void {
    $store = new MemoryNonceStore();
    $headers = signed_headers('nonce-repeat');
    K2AutomationSignature::verify(
        'POST',
        '/vms/workspace/api/k2_automation/register_batch.php',
        $headers,
        test_config(),
        $store,
        test_now(),
        'register_batch'
    );

    expect_exception(K2AutomationApiAuthException::class, function () use ($headers, $store): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            $store,
            test_now(),
            'register_batch'
        );
    });
};

$tests['wrong client and missing required header'] = function (): void {
    $headers = signed_headers('nonce-client');
    $headers['X-K2-Client-ID'] = 'other-client';
    expect_exception(K2AutomationApiAuthException::class, function () use ($headers): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });

    $headers = signed_headers('nonce-missing');
    unset($headers['X-K2-Nonce']);
    expect_exception(K2AutomationApiAuthException::class, function () use ($headers): void {
        K2AutomationSignature::verify(
            'POST',
            '/vms/workspace/api/k2_automation/register_batch.php',
            $headers,
            test_config(),
            new MemoryNonceStore(),
            test_now(),
            'register_batch'
        );
    });
};

$tests['incorrect HTTP method and no stack leakage'] = function (): void {
    $controller = new K2AutomationApiController(
        test_config(),
        new MemoryNonceStore(),
        new K2ImportOrchestrator(new NullImportRepository()),
        new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig(sys_get_temp_dir() . '/k2-automation-test-api-auth')),
        test_now()
    );
    $response = $controller->handle(
        'register_batch',
        'GET',
        '/vms/workspace/api/k2_automation/register_batch.php',
        [],
        '{}',
        []
    );

    assert_same(405, $response->status);
    assert_same('METHOD_NOT_ALLOWED', $response->body['code']);
    assert_false(str_contains(json_encode($response->body), 'Stack trace'));
};

function test_config(): K2AutomationApiConfig
{
    return new K2AutomationApiConfig('client-a', 'test-secret', 300, 64, 60, '/vms/workspace/api/k2_automation');
}

function test_now(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-07-22T02:00:00Z');
}

function signed_headers(string $nonce, string $timestamp = '2026-07-22T02:00:00Z'): array
{
    $clientId = 'client-a';
    $requestId = 'request-001';
    $operation = 'register_batch';
    $batchUid = 'k2b_' . str_repeat('a', 64);
    $metadataHash = str_repeat('b', 64);
    $manifestHash = str_repeat('c', 64);
    $canonical = K2AutomationSignature::canonicalString(
        'POST',
        '/vms/workspace/api/k2_automation/register_batch.php',
        $clientId,
        $timestamp,
        $nonce,
        $requestId,
        $operation,
        $batchUid,
        $metadataHash,
        $manifestHash
    );

    return [
        'X-K2-Client-ID' => $clientId,
        'X-K2-Timestamp' => $timestamp,
        'X-K2-Nonce' => $nonce,
        'X-K2-Request-ID' => $requestId,
        'X-K2-Batch-UID' => $batchUid,
        'X-K2-Operation' => $operation,
        'X-K2-Metadata-SHA256' => $metadataHash,
        'X-K2-File-Manifest-SHA256' => $manifestHash,
        'X-K2-Signature' => K2AutomationSignature::sign($canonical, 'test-secret'),
    ];
}

final class MemoryNonceStore implements K2AutomationNonceStoreInterface
{
    private array $seen = [];

    public function storeNonce(
        string $clientId,
        string $nonce,
        string $requestId,
        DateTimeInterface $requestTimestamp,
        DateTimeInterface $expiresAt
    ): bool {
        $key = $clientId . ':' . $nonce;
        if (isset($this->seen[$key])) {
            return false;
        }

        $this->seen[$key] = true;

        return true;
    }

    public function cleanupExpired(DateTimeInterface $now): int
    {
        return 0;
    }
}

final class NullImportRepository implements K2ImportRunRepositoryInterface
{
    public function transactional(callable $callback): mixed { return $callback($this); }
    public function findBatchByUid(string $batchUid): ?array { return null; }
    public function findBatchById(int $batchId): ?array { return null; }
    public function insertDetectedBatch(string $batchUid, string $sourceManifestHash, array $files, ?K2ImportScheduleMetadata $scheduleMetadata = null): int { return 1; }
    public function findSourceFilesForBatch(int $batchId): array { return []; }
    public function findOperationRequestByKey(string $requestIdempotencyKey): ?array { return null; }
    public function findOperationRequestByLogicalRequestId(int $batchId, string $operationType, string $logicalRequestId): ?array { return null; }
    public function latestOperationRequestForOperation(int $batchId, string $operationType): ?array { return null; }
    public function findOperationAttemptByRequestId(int $operationRequestId): ?array { return null; }
    public function upsertOperationRequest(int $batchId, string $operationType, string $requestIdempotencyKey, string $logicalRequestId, int $attemptNumber): int { return 1; }
    public function markOperationRequestRunning(int $requestId): void {}
    public function markOperationRequestCompleted(int $requestId, string $requestStatus, string $batchState, string $archiveState, int $httpStatus, ?string $responseBodyHash): void {}
    public function recordOperationAttemptStarted(int $batchId, ?int $operationRequestId, string $operationType, int $attemptNumber, string $attemptIdempotencyKey): int { return 1; }
    public function nextAttemptNumber(int $batchId, string $operationType): int { return 1; }
    public function markOperationAttemptSucceeded(int $attemptId, array $counters): void {}
    public function markOperationAttemptFailed(int $attemptId, string $errorCode, string $errorMessage, bool $retryable): void {}
    public function transitionBatchState(int $batchId, string $fromState, string $fromArchiveState, string $toState, string $toArchiveState): bool { return true; }
    public function claimBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool { return true; }
    public function extendBatchLock(int $batchId, string $ownerToken, int $lockSeconds): bool { return true; }
    public function releaseBatchLock(int $batchId, string $ownerToken): bool { return true; }
    public function hasActiveBatchLock(int $batchId, string $ownerToken): bool { return true; }
    public function recoverStaleProcessingBatch(int $batchId, int $staleSeconds): ?array { return null; }
    public function attachStagedFiles(int $attemptId, array $stagedFileManifest): void {}
    public function findAttemptById(int $attemptId): ?array { return null; }
    public function claimNextQueuedAttempt(string $workerToken, string $workerHostname, int $workerPid, int $leaseSeconds): ?array { return null; }
    public function extendWorkerLease(int $attemptId, string $workerToken): bool { return true; }
    public function releaseWorkerClaim(int $attemptId, string $workerToken): bool { return true; }
}

function expect_exception_code(string $stableCode, callable $callback): void
{
    try {
        $callback();
    } catch (K2AutomationApiAuthException $e) {
        if ($e->stableCode() === $stableCode) {
            return;
        }

        throw new RuntimeException("Expected auth code {$stableCode}, got " . $e->stableCode());
    } catch (Throwable $e) {
        throw new RuntimeException('Expected auth exception, got ' . get_class($e) . ': ' . $e->getMessage());
    }

    throw new RuntimeException("Expected auth code {$stableCode} to be thrown.");
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
