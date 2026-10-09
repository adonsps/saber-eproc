<?php
declare(strict_types=1);

final class K2AutomationApiConfig
{
    public function __construct(
        public readonly string $clientId,
        public readonly string $hmacSecret,
        public readonly int $allowedClockSkewSeconds = 300,
        public readonly int $maxUploadBytes = 67108864,
        public readonly int $lockSeconds = 900,
        public readonly string $publicApiBasePath = '/api/k2_automation',
        public readonly int $staleOperationSeconds = 1800
    ) {
        if (trim($clientId) === '') {
            throw new InvalidArgumentException('K2 automation client ID is required.');
        }

        if (trim($hmacSecret) === '') {
            throw new InvalidArgumentException('K2 automation HMAC secret is required.');
        }

        if ($allowedClockSkewSeconds < 1) {
            throw new InvalidArgumentException('Allowed clock skew must be at least one second.');
        }

        if ($maxUploadBytes < 1) {
            throw new InvalidArgumentException('Max upload bytes must be at least one byte.');
        }

        if ($lockSeconds < 1) {
            throw new InvalidArgumentException('Lock duration must be at least one second.');
        }

        K2AutomationPublicApiPath::normalizeBasePath($publicApiBasePath);

        if ($staleOperationSeconds < 1) {
            throw new InvalidArgumentException('Stale operation timeout must be at least one second.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::env('K2_AUTOMATION_CLIENT_ID'),
            self::env('K2_AUTOMATION_HMAC_SECRET'),
            self::envInt('K2_AUTOMATION_ALLOWED_CLOCK_SKEW_SECONDS', 300),
            self::envInt('K2_AUTOMATION_MAX_UPLOAD_BYTES', 67108864),
            self::envInt('K2_AUTOMATION_LOCK_SECONDS', 900),
            self::env('K2_AUTOMATION_PUBLIC_API_BASE_PATH') !== ''
                ? self::env('K2_AUTOMATION_PUBLIC_API_BASE_PATH')
                : '/api/k2_automation',
            self::envInt('K2_AUTOMATION_STALE_OPERATION_SECONDS', 1800)
        );
    }

    public function normalizedPublicApiBasePath(): string
    {
        return K2AutomationPublicApiPath::normalizeBasePath($this->publicApiBasePath);
    }

    public function canonicalEndpointPath(string $endpoint): string
    {
        return K2AutomationPublicApiPath::endpointPath($this->publicApiBasePath, $endpoint);
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return $value === false ? '' : trim($value);
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || trim($value) === '') {
            return $default;
        }

        return (int) $value;
    }
}

final class K2AutomationPublicApiPath
{
    private const ENDPOINT_FILES = [
        'register_batch' => 'register_batch.php',
        'batch_status' => 'batch_status.php',
        'import_aon' => 'import_aon.php',
        'import_pr' => 'import_pr.php',
        'start_archive' => 'start_archive.php',
        'archive_result' => 'archive_result.php',
    ];

    public static function normalizeBasePath(string $basePath): string
    {
        $basePath = trim(str_replace('\\', '/', $basePath));
        if ($basePath === '') {
            throw new InvalidArgumentException('K2 automation public API base path is required.');
        }

        if (preg_match('/[?#]/', $basePath) === 1) {
            throw new InvalidArgumentException('K2 automation public API base path must not include query or fragment data.');
        }

        $basePath = preg_replace('#/+#', '/', '/' . trim($basePath, '/')) ?? $basePath;
        $segments = explode('/', trim($basePath, '/'));
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || $segment === '') {
                throw new InvalidArgumentException('K2 automation public API base path must not contain path traversal.');
            }
        }

        return $basePath;
    }

    public static function endpointPath(string $basePath, string $endpoint): string
    {
        if (!isset(self::ENDPOINT_FILES[$endpoint])) {
            throw new InvalidArgumentException("Unknown K2 automation endpoint: {$endpoint}");
        }

        return self::normalizeBasePath($basePath) . '/' . self::ENDPOINT_FILES[$endpoint];
    }

    public static function endpointFiles(): array
    {
        return self::ENDPOINT_FILES;
    }
}
