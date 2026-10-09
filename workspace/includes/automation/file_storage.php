<?php
declare(strict_types=1);

final class K2AutomationFileStorageException extends RuntimeException
{
    public function __construct(
        private readonly string $stableCode,
        string $safeMessage,
        private readonly bool $retryable = true,
        ?Throwable $previous = null
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function stableCode(): string
    {
        return $this->stableCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }
}

final class K2AutomationFileStorageConfig
{
    public readonly string $storageRoot;

    public function __construct(
        string $storageRoot,
        public readonly int $retentionSeconds = 86400,
        public readonly int $failedRetentionSeconds = 259200
    ) {
        $storageRoot = rtrim(str_replace('\\', '/', trim($storageRoot)), '/');
        if ($storageRoot === '') {
            throw new InvalidArgumentException('K2 automation storage root is required.');
        }

        self::assertAbsoluteAndSafe($storageRoot);
        self::assertOutsideApplicationRoot($storageRoot);

        $this->storageRoot = $storageRoot;

        if ($retentionSeconds < 0) {
            throw new InvalidArgumentException('K2_AUTOMATION_STAGED_RETENTION_SECONDS must not be negative.');
        }

        if ($failedRetentionSeconds < 0) {
            throw new InvalidArgumentException('K2_AUTOMATION_FAILED_RETENTION_SECONDS must not be negative.');
        }
    }

    public static function fromEnvironment(): self
    {
        $root = getenv('K2_AUTOMATION_STORAGE_ROOT');
        $retention = getenv('K2_AUTOMATION_STAGED_RETENTION_SECONDS');
        $failedRetention = getenv('K2_AUTOMATION_FAILED_RETENTION_SECONDS');

        return new self(
            $root === false || trim($root) === '' ? self::defaultStorageRoot() : trim($root),
            $retention === false || trim($retention) === '' ? 86400 : (int) $retention,
            $failedRetention === false || trim($failedRetention) === '' ? 259200 : (int) $failedRetention
        );
    }

    /**
     * Sibling of the repository checkout. On Hostinger the checkout itself is the
     * public_html document root, so a sibling directory sits outside the web-servable
     * tree. Always set K2_AUTOMATION_STORAGE_ROOT explicitly for a real deployment
     * instead of relying on this default.
     */
    private static function defaultStorageRoot(): string
    {
        return dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'k2_automation_storage';
    }

    /** Rejects relative paths and any literal '..' / '.' traversal segment. */
    private static function assertAbsoluteAndSafe(string $storageRoot): void
    {
        $isAbsolute = str_starts_with($storageRoot, '/') || preg_match('#\A[A-Za-z]:/#', $storageRoot) === 1;
        if (!$isAbsolute) {
            throw new InvalidArgumentException('K2 automation storage root must be an absolute path.');
        }

        foreach (explode('/', $storageRoot) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('K2 automation storage root must not contain "." or ".." segments.');
            }
        }
    }

    /**
     * Rejects a storage root that is the application checkout itself or a
     * subdirectory of it. On Hostinger the checkout root IS public_html, so this
     * is the concrete, checkable stand-in for "must be outside public_html".
     */
    private static function assertOutsideApplicationRoot(string $storageRoot): void
    {
        $applicationRoot = rtrim(str_replace('\\', '/', dirname(__DIR__, 3)), '/');
        if ($storageRoot === $applicationRoot || str_starts_with($storageRoot . '/', $applicationRoot . '/')) {
            throw new InvalidArgumentException(
                'K2 automation storage root must be outside the application checkout (public_html).'
            );
        }

        foreach (['public_html', 'htdocs', 'www', 'wwwroot'] as $publicSegmentName) {
            if (in_array($publicSegmentName, explode('/', strtolower($storageRoot)), true)) {
                throw new InvalidArgumentException(
                    "K2 automation storage root must not live under a '{$publicSegmentName}' segment."
                );
            }
        }
    }
}

final class K2AutomationStagedFileStore
{
    public function __construct(private readonly K2AutomationFileStorageConfig $config)
    {
    }

    /** The configured storage root — safe to log/expose; never contains secrets. */
    public function storageRoot(): string
    {
        return $this->config->storageRoot;
    }

    /**
     * Stages every file required for one operation attempt, returning the full
     * manifest. If any file after the first fails to stage, every file already
     * staged in this call is removed before the exception propagates — the HTTP
     * handler never persists queue state for a partially-staged attempt.
     *
     * @param array<int, array{field: string, datasetType: string, tmpPath: string, originalFilename: string, expectedSha256: string}> $items
     */
    public function stageManifest(string $batchUid, string $operationType, int $attemptNumber, array $items): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('At least one file is required to stage a manifest.');
        }

        $manifest = [];
        try {
            foreach ($items as $item) {
                $manifest[] = $this->stage(
                    $batchUid,
                    $operationType,
                    $attemptNumber,
                    (string) $item['field'],
                    (string) $item['datasetType'],
                    (string) $item['tmpPath'],
                    (string) $item['originalFilename'],
                    (string) $item['expectedSha256']
                );
            }
        } catch (Throwable $e) {
            $this->cleanupAttempt($batchUid, $operationType, $attemptNumber);
            throw $e;
        }

        return $manifest;
    }

    /**
     * Moves an already hash-verified uploaded file into private storage and returns
     * a manifest entry describing where it landed.
     */
    public function stage(
        string $batchUid,
        string $operationType,
        int $attemptNumber,
        string $field,
        string $datasetType,
        string $tmpPath,
        string $originalFilename,
        string $expectedSha256
    ): array {
        $directory = $this->attemptDirectory($batchUid, $operationType, $attemptNumber);
        $this->ensureDirectory($directory);

        $safeExtension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION)) === 'xlsx' ? 'xlsx' : 'bin';
        $safeField = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $field) ?? 'file';
        $destination = $directory . DIRECTORY_SEPARATOR . $safeField . '.' . $safeExtension;

        // Defense in depth: the batch/operation/attempt/field components are already
        // sanitized above, so this can only trip if a future caller changes that
        // sanitization; it must never silently write outside the storage root.
        $resolvedDirectory = realpath($directory);
        $resolvedRoot = realpath($this->config->storageRoot);
        if ($resolvedDirectory === false || $resolvedRoot === false
            || !str_starts_with($resolvedDirectory . DIRECTORY_SEPARATOR, $resolvedRoot . DIRECTORY_SEPARATOR)
        ) {
            throw new K2AutomationFileStorageException(
                'STAGED_DIRECTORY_UNSAFE',
                'Resolved staging directory is outside the configured storage root.',
                false
            );
        }

        if (!@rename($tmpPath, $destination)) {
            if (!@copy($tmpPath, $destination)) {
                throw new K2AutomationFileStorageException(
                    'STAGED_FILE_WRITE_FAILED',
                    'Could not persist uploaded file to private storage.'
                );
            }
            @unlink($tmpPath);
        }
        @chmod($destination, 0640);

        $actualHash = hash_file('sha256', $destination);
        $actualSize = filesize($destination);
        if ($actualHash === false || $actualSize === false || !hash_equals(strtolower($expectedSha256), $actualHash)) {
            @unlink($destination);
            throw new K2AutomationFileStorageException(
                'STAGED_FILE_CHECKSUM_MISMATCH',
                'Staged file checksum does not match the verified upload.'
            );
        }

        return [
            'field' => $field,
            'dataset_type' => $datasetType,
            'storage_path' => $destination,
            'sha256_content_hash' => $actualHash,
            'file_size_bytes' => $actualSize,
            'original_filename' => $originalFilename,
        ];
    }

    /** Re-checks a staged file against its recorded manifest hash before the worker uses it. */
    public function verifyStagedFile(array $manifestEntry): void
    {
        $path = (string) ($manifestEntry['storage_path'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new K2AutomationFileStorageException(
                'STAGED_FILE_MISSING',
                'Staged upload file is unavailable.'
            );
        }

        $actualHash = hash_file('sha256', $path);
        $expectedHash = strtolower((string) ($manifestEntry['sha256_content_hash'] ?? ''));
        if ($actualHash === false || $expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
            throw new K2AutomationFileStorageException(
                'STAGED_FILE_CHECKSUM_MISMATCH',
                'Staged file checksum no longer matches the recorded manifest.'
            );
        }
    }

    public function cleanupAttempt(string $batchUid, string $operationType, int $attemptNumber): void
    {
        $directory = $this->attemptDirectory($batchUid, $operationType, $attemptNumber);
        if (!is_dir($directory)) {
            return;
        }

        foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($directory);
    }

    /**
     * Removes staged attempt directories older than a retention window. Defaults to
     * the general (short) retention — pass failedRetentionSeconds explicitly to sweep
     * with the longer diagnostic window instead. This class has no DB access and
     * cannot tell a still-queued attempt from an abandoned one by filesystem age
     * alone; the CLI worker is expected to clean up known-terminal attempts by exact
     * identifier via cleanupAttempt() first, then call this as a blunt backstop for
     * anything orphaned (e.g. a crash before any DB row was written).
     */
    public function sweepExpired(?DateTimeImmutable $now = null, ?int $retentionSecondsOverride = null): int
    {
        if (!is_dir($this->config->storageRoot)) {
            return 0;
        }

        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $retentionSeconds = $retentionSecondsOverride ?? $this->config->retentionSeconds;
        $cutoff = $now->getTimestamp() - $retentionSeconds;
        $removed = 0;

        foreach (glob($this->config->storageRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $batchDir) {
            foreach (glob($batchDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $attemptDir) {
                $mtime = @filemtime($attemptDir);
                if ($mtime === false || $mtime > $cutoff) {
                    continue;
                }

                foreach (glob($attemptDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
                if (@rmdir($attemptDir)) {
                    $removed++;
                }
            }
            @rmdir($batchDir);
        }

        return $removed;
    }

    private function attemptDirectory(string $batchUid, string $operationType, int $attemptNumber): string
    {
        $safeBatch = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $batchUid) ?? 'batch';
        $safeOperation = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $operationType) ?? 'op';

        return $this->config->storageRoot
            . DIRECTORY_SEPARATOR . $safeBatch
            . DIRECTORY_SEPARATOR . $safeOperation . '_' . $attemptNumber;
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new K2AutomationFileStorageException(
                'STAGED_DIRECTORY_UNAVAILABLE',
                'Could not create private storage directory for staged uploads.'
            );
        }
    }
}
