<?php
declare(strict_types=1);

require_once __DIR__ . '/api_config.php';
require_once __DIR__ . '/import_identity.php';

final class K2AutomationApiAuthException extends RuntimeException
{
    public function __construct(
        private readonly string $stableCode,
        string $message,
        private readonly int $httpStatus = 401
    ) {
        parent::__construct($message);
    }

    public function stableCode(): string
    {
        return $this->stableCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}

final class K2AutomationJson
{
    public static function canonicalJson(mixed $value): string
    {
        $normalized = self::sortRecursively($value);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new InvalidArgumentException('Could not encode canonical JSON.');
        }

        return $json;
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::canonicalJson($value));
    }

    private static function sortRecursively(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'sortRecursively'], $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::sortRecursively($item);
        }

        return $value;
    }
}

final class K2AutomationSignature
{
    public const REQUIRED_HEADERS = [
        'x-k2-client-id',
        'x-k2-timestamp',
        'x-k2-nonce',
        'x-k2-request-id',
        'x-k2-batch-uid',
        'x-k2-operation',
        'x-k2-metadata-sha256',
        'x-k2-file-manifest-sha256',
        'x-k2-signature',
    ];

    public static function canonicalString(
        string $method,
        string $path,
        string $clientId,
        string $timestamp,
        string $nonce,
        string $requestId,
        string $operation,
        string $batchUid,
        string $metadataSha256,
        string $fileManifestSha256
    ): string {
        return implode("\n", [
            strtoupper($method),
            self::normalizePath($path),
            $clientId,
            $timestamp,
            $nonce,
            $requestId,
            $operation,
            $batchUid,
            strtolower($metadataSha256),
            strtolower($fileManifestSha256),
        ]);
    }

    public static function sign(string $canonicalString, string $secret): string
    {
        return hash_hmac('sha256', $canonicalString, $secret);
    }

    public static function normalizePath(string $path): string
    {
        $path = parse_url($path, PHP_URL_PATH) ?: '/';
        $path = preg_replace('#/+#', '/', $path) ?? $path;

        return str_starts_with($path, '/') ? $path : '/' . $path;
    }

    public static function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = trim(is_array($value) ? (string) reset($value) : (string) $value);
        }

        return $normalized;
    }

    public static function verify(
        string $method,
        string $path,
        array $headers,
        K2AutomationApiConfig $config,
        K2AutomationNonceStoreInterface $nonceStore,
        DateTimeImmutable $now,
        ?string $endpoint = null
    ): array {
        $headers = self::normalizeHeaders($headers);
        foreach (self::REQUIRED_HEADERS as $headerName) {
            if (($headers[$headerName] ?? '') === '') {
                throw new K2AutomationApiAuthException('MISSING_AUTH_HEADER', 'A required authentication header is missing.');
            }
        }

        if ($headers['x-k2-client-id'] !== $config->clientId) {
            throw new K2AutomationApiAuthException('UNKNOWN_CLIENT', 'Unknown automation client.');
        }

        foreach (['x-k2-metadata-sha256', 'x-k2-file-manifest-sha256', 'x-k2-signature'] as $hashHeader) {
            if (preg_match('/\A[a-f0-9]{64}\z/', $headers[$hashHeader]) !== 1) {
                throw new K2AutomationApiAuthException('INVALID_AUTH_HASH', 'Authentication hash headers must be lowercase SHA-256 hex values.');
            }
        }

        $requestTimestamp = self::parseTimestamp($headers['x-k2-timestamp']);
        $delta = abs($now->getTimestamp() - $requestTimestamp->getTimestamp());
        if ($delta > $config->allowedClockSkewSeconds) {
            throw new K2AutomationApiAuthException('EXPIRED_TIMESTAMP', 'Request timestamp is outside the allowed clock skew.');
        }

        $canonicalPath = self::normalizePath($path);
        if ($endpoint !== null) {
            $expectedPath = $config->canonicalEndpointPath($endpoint);
            if (!hash_equals($expectedPath, $canonicalPath)) {
                throw new K2AutomationApiAuthException(
                    'PUBLIC_API_PATH_MISMATCH',
                    'Request path does not match the configured public API path.',
                    400
                );
            }
            $canonicalPath = $expectedPath;
        }

        $canonical = self::canonicalString(
            $method,
            $canonicalPath,
            $headers['x-k2-client-id'],
            $headers['x-k2-timestamp'],
            $headers['x-k2-nonce'],
            $headers['x-k2-request-id'],
            $headers['x-k2-operation'],
            $headers['x-k2-batch-uid'],
            $headers['x-k2-metadata-sha256'],
            $headers['x-k2-file-manifest-sha256']
        );
        $expected = self::sign($canonical, $config->hmacSecret);
        if (!hash_equals($expected, $headers['x-k2-signature'])) {
            throw new K2AutomationApiAuthException('INVALID_SIGNATURE', 'Invalid request signature.');
        }

        $expiresAt = $requestTimestamp->modify('+' . $config->allowedClockSkewSeconds . ' seconds');
        if (!$nonceStore->storeNonce(
            $headers['x-k2-client-id'],
            $headers['x-k2-nonce'],
            $headers['x-k2-request-id'],
            $requestTimestamp,
            $expiresAt
        )) {
            throw new K2AutomationApiAuthException('NONCE_REPLAY', 'Nonce has already been used.', 409);
        }

        return [
            'client_id' => $headers['x-k2-client-id'],
            'timestamp' => $headers['x-k2-timestamp'],
            'nonce' => $headers['x-k2-nonce'],
            'request_id' => $headers['x-k2-request-id'],
            'batch_uid' => $headers['x-k2-batch-uid'],
            'operation' => $headers['x-k2-operation'],
            'metadata_sha256' => $headers['x-k2-metadata-sha256'],
            'file_manifest_sha256' => $headers['x-k2-file-manifest-sha256'],
            'canonical_string' => $canonical,
        ];
    }

    private static function parseTimestamp(string $timestamp): DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw new K2AutomationApiAuthException('INVALID_TIMESTAMP', 'Request timestamp is invalid.');
        }
    }
}

interface K2AutomationNonceStoreInterface
{
    public function storeNonce(
        string $clientId,
        string $nonce,
        string $requestId,
        DateTimeInterface $requestTimestamp,
        DateTimeInterface $expiresAt
    ): bool;

    public function cleanupExpired(DateTimeInterface $now): int;
}

final class PdoK2AutomationNonceStore implements K2AutomationNonceStoreInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function storeNonce(
        string $clientId,
        string $nonce,
        string $requestId,
        DateTimeInterface $requestTimestamp,
        DateTimeInterface $expiresAt
    ): bool {
        $stmt = $this->pdo->prepare(
            'INSERT INTO k2_automation_api_nonces ' .
            '(client_id, nonce, request_id, request_timestamp, expires_at) VALUES (?, ?, ?, ?, ?)'
        );

        try {
            $stmt->execute([
                $clientId,
                $nonce,
                $requestId,
                self::sqlDateTime($requestTimestamp),
                self::sqlDateTime($expiresAt),
            ]);

            return true;
        } catch (PDOException $e) {
            $errorInfo = $e->errorInfo;
            if (($errorInfo[0] ?? null) === '23000' || (int) ($errorInfo[1] ?? 0) === 1062) {
                return false;
            }

            throw $e;
        }
    }

    public function cleanupExpired(DateTimeInterface $now): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM k2_automation_api_nonces WHERE expires_at <= ?');
        $stmt->execute([self::sqlDateTime($now)]);

        return $stmt->rowCount();
    }

    private static function sqlDateTime(DateTimeInterface $dateTime): string
    {
        return DateTimeImmutable::createFromInterface($dateTime)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.u');
    }
}
