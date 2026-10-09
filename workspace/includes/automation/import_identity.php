<?php
declare(strict_types=1);

require_once __DIR__ . '/import_state.php';

final class K2ImportIdentityException extends InvalidArgumentException
{
}

final class K2ImportSourceFileMetadata
{
    public readonly string $datasetType;
    public readonly string $driveFileId;
    public readonly string $originalFilename;
    public readonly string $modifiedTimestamp;
    public readonly int $fileSizeBytes;
    public readonly string $sha256ContentHash;

    public function __construct(
        string $datasetType,
        string $driveFileId,
        string $originalFilename,
        string|DateTimeInterface $modifiedTimestamp,
        int $fileSizeBytes,
        string $sha256ContentHash
    ) {
        K2ImportDatasetType::assertValid($datasetType);

        $driveFileId = trim($driveFileId);
        $originalFilename = trim($originalFilename);
        $sha256ContentHash = strtolower(trim($sha256ContentHash));

        if ($driveFileId === '') {
            throw new K2ImportIdentityException('Drive file ID is required.');
        }

        if ($originalFilename === '') {
            throw new K2ImportIdentityException('Original filename is required.');
        }

        if ($fileSizeBytes < 0) {
            throw new K2ImportIdentityException('File size must not be negative.');
        }

        if (preg_match('/\A[a-f0-9]{64}\z/', $sha256ContentHash) !== 1) {
            throw new K2ImportIdentityException('SHA-256 content hash must be 64 lowercase hexadecimal characters.');
        }

        $this->datasetType = $datasetType;
        $this->driveFileId = $driveFileId;
        $this->originalFilename = $originalFilename;
        $this->modifiedTimestamp = self::normalizeTimestamp($modifiedTimestamp);
        $this->fileSizeBytes = $fileSizeBytes;
        $this->sha256ContentHash = $sha256ContentHash;
    }

    public function canonicalArray(): array
    {
        return [
            'dataset_type' => $this->datasetType,
            'drive_file_id' => $this->driveFileId,
            'original_filename' => $this->originalFilename,
            'modified_timestamp' => $this->modifiedTimestamp,
            'file_size_bytes' => $this->fileSizeBytes,
            'sha256_content_hash' => $this->sha256ContentHash,
        ];
    }

    public function modifiedTimestampForSql(): string
    {
        $modifiedAt = new DateTimeImmutable($this->modifiedTimestamp);

        return $modifiedAt->format('Y-m-d H:i:s.u');
    }

    private static function normalizeTimestamp(string|DateTimeInterface $timestamp): string
    {
        try {
            $date = $timestamp instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($timestamp)
                : new DateTimeImmutable(trim($timestamp));
        } catch (Throwable $e) {
            throw new K2ImportIdentityException('Modified timestamp must be parseable.', 0, $e);
        }

        return $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}

final class K2ImportScheduleMetadata
{
    public const DEFAULT_OPERATIONAL_TIMEZONE = 'Asia/Jakarta';

    public readonly ?string $scheduledSlotAtUtc;
    public readonly ?string $scheduleTimezone;

    public function __construct(
        string|DateTimeInterface|null $scheduledSlotAtUtc = null,
        ?string $scheduleTimezone = null
    ) {
        if ($scheduledSlotAtUtc === null) {
            if ($scheduleTimezone !== null && trim($scheduleTimezone) !== '') {
                throw new K2ImportIdentityException('Schedule timezone requires a scheduled slot timestamp.');
            }

            $this->scheduledSlotAtUtc = null;
            $this->scheduleTimezone = null;

            return;
        }

        $timezone = $scheduleTimezone === null || trim($scheduleTimezone) === ''
            ? self::DEFAULT_OPERATIONAL_TIMEZONE
            : trim($scheduleTimezone);

        if (strlen($timezone) > 64) {
            throw new K2ImportIdentityException('Schedule timezone must be 64 characters or less.');
        }

        try {
            $dateTimezone = new DateTimeZone($timezone);
            $date = $scheduledSlotAtUtc instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($scheduledSlotAtUtc)
                : new DateTimeImmutable(trim($scheduledSlotAtUtc), $dateTimezone);
        } catch (Throwable $e) {
            throw new K2ImportIdentityException('Scheduled slot timestamp and timezone must be valid.', 0, $e);
        }

        $this->scheduledSlotAtUtc = $date
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z');
        $this->scheduleTimezone = $timezone;
    }

    public function scheduledSlotForSql(): ?string
    {
        if ($this->scheduledSlotAtUtc === null) {
            return null;
        }

        return (new DateTimeImmutable($this->scheduledSlotAtUtc))->format('Y-m-d H:i:s.u');
    }

    public function canonicalArray(): ?array
    {
        if ($this->scheduledSlotAtUtc === null) {
            return null;
        }

        return [
            'scheduled_slot_at_utc' => $this->scheduledSlotAtUtc,
            'schedule_timezone' => $this->scheduleTimezone,
        ];
    }
}

final class K2ImportIdentity
{
    public static function normalizeSourceFiles(array $files): array
    {
        $byType = [];
        foreach ($files as $file) {
            if (!$file instanceof K2ImportSourceFileMetadata) {
                throw new K2ImportIdentityException('Source files must be K2ImportSourceFileMetadata instances.');
            }

            if (isset($byType[$file->datasetType])) {
                throw new K2ImportIdentityException("Duplicate K2 import dataset type: {$file->datasetType}");
            }

            $byType[$file->datasetType] = $file;
        }

        K2ImportDatasetType::assertExactlyRequired(array_keys($byType));

        $ordered = [];
        foreach (K2ImportDatasetType::requiredTypes() as $datasetType) {
            $ordered[$datasetType] = $byType[$datasetType];
        }

        return $ordered;
    }

    public static function sourceManifest(array $files): array
    {
        return array_map(
            static fn(K2ImportSourceFileMetadata $file): array => $file->canonicalArray(),
            self::normalizeSourceFiles($files)
        );
    }

    public static function sourceManifestHash(array $files): string
    {
        return hash('sha256', self::canonicalJson([
            'schema' => 'k2-import-source-manifest-v1',
            'files' => self::sourceManifest($files),
        ]));
    }

    public static function batchUid(array $files): string
    {
        return 'k2b_' . self::sourceManifestHash($files);
    }

    public static function fileIdentityHash(K2ImportSourceFileMetadata $file): string
    {
        return hash('sha256', self::canonicalJson([
            'schema' => 'k2-import-source-file-v1',
            'file' => $file->canonicalArray(),
        ]));
    }

    public static function contentIdentityHash(K2ImportSourceFileMetadata $file): string
    {
        return hash('sha256', self::canonicalJson([
            'schema' => 'k2-import-source-content-v1',
            'dataset_type' => $file->datasetType,
            'sha256_content_hash' => $file->sha256ContentHash,
        ]));
    }

    /**
     * Request key canonical payload:
     * {
     *   "schema": "k2-import-operation-request-v1",
     *   "batch_uid": batchUid(files),
     *   "operation_type": operationType,
     *   "files": sourceManifest(files),
     *   "schedule": scheduleMetadata.canonicalArray() or null
     * }
     */
    public static function requestIdempotencyKey(
        string $operationType,
        array $files,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): string {
        return 'k2req_' . hash('sha256', self::canonicalJson(
            self::requestIdempotencyPayload($operationType, $files, $scheduleMetadata)
        ));
    }

    public static function requestIdempotencyPayload(
        string $operationType,
        array $files,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): array {
        K2ImportOperationType::assertValid($operationType);

        return [
            'schema' => 'k2-import-operation-request-v1',
            'batch_uid' => self::batchUid($files),
            'operation_type' => $operationType,
            'files' => self::sourceManifest($files),
            'schedule' => $scheduleMetadata?->canonicalArray(),
        ];
    }

    public static function operationRequestIdempotencyKey(
        string $batchUid,
        string $operationType,
        string $logicalRequestId,
        int $attemptNumber
    ): string {
        return 'k2req_' . hash('sha256', self::canonicalJson(
            self::operationRequestIdempotencyPayload($batchUid, $operationType, $logicalRequestId, $attemptNumber)
        ));
    }

    public static function operationRequestIdempotencyPayload(
        string $batchUid,
        string $operationType,
        string $logicalRequestId,
        int $attemptNumber
    ): array {
        K2ImportOperationType::assertValid($operationType);
        $batchUid = trim($batchUid);
        $logicalRequestId = trim($logicalRequestId);
        if ($batchUid === '' || !str_starts_with($batchUid, 'k2b_')) {
            throw new K2ImportIdentityException('Batch UID is required for operation request identity.');
        }
        if ($logicalRequestId === '') {
            throw new K2ImportIdentityException('Logical request ID is required.');
        }
        if ($attemptNumber < 1) {
            throw new K2ImportIdentityException('Attempt number must be greater than zero.');
        }

        return [
            'schema' => 'k2-import-operation-request-v2',
            'batch_uid' => $batchUid,
            'operation_type' => $operationType,
            'logical_request_id' => $logicalRequestId,
            'attempt_number' => $attemptNumber,
        ];
    }

    /**
     * Attempt key canonical payload:
     * {
     *   "schema": "k2-import-operation-attempt-v1",
     *   "batch_uid": batchUid(files),
     *   "operation_type": operationType,
     *   "attempt_number": attemptNumber,
     *   "files": sourceManifest(files),
     *   "schedule": scheduleMetadata.canonicalArray() or null
     * }
     */
    public static function attemptIdempotencyKey(
        string $operationType,
        array $files,
        int $attemptNumber,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): string {
        return 'k2att_' . hash('sha256', self::canonicalJson(
            self::attemptIdempotencyPayload($operationType, $files, $attemptNumber, $scheduleMetadata)
        ));
    }

    public static function attemptIdempotencyPayload(
        string $operationType,
        array $files,
        int $attemptNumber,
        ?K2ImportScheduleMetadata $scheduleMetadata = null
    ): array {
        K2ImportOperationType::assertValid($operationType);

        if ($attemptNumber < 1) {
            throw new K2ImportIdentityException('Attempt number must be greater than zero.');
        }

        return [
            'schema' => 'k2-import-operation-attempt-v1',
            'batch_uid' => self::batchUid($files),
            'operation_type' => $operationType,
            'attempt_number' => $attemptNumber,
            'files' => self::sourceManifest($files),
            'schedule' => $scheduleMetadata?->canonicalArray(),
        ];
    }

    public static function canonicalJson(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new K2ImportIdentityException('Could not encode canonical identity payload.');
        }

        return $json;
    }
}
