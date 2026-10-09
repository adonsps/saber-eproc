<?php
declare(strict_types=1);

require_once __DIR__ . '/api_config.php';

final class K2AutomationRequestExtractionException extends InvalidArgumentException
{
    public function __construct(
        private readonly string $stableCode,
        string $message,
        private readonly int $httpStatus = 400
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

final class K2AutomationHttpRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $headers,
        public readonly string $metadataRaw,
        public readonly array $files = []
    ) {
    }
}

final class K2AutomationRequestExtractor
{
    private const JSON_ENDPOINTS = [
        'register_batch',
        'batch_status',
        'start_archive',
        'archive_result',
    ];

    private const MULTIPART_ENDPOINT_FILE_FIELDS = [
        'import_aon' => ['catalog_file', 'price_file'],
        'import_pr' => ['pr_file'],
    ];

    private const UPLOAD_ERROR_MAP = [
        UPLOAD_ERR_INI_SIZE => ['UPLOAD_EXCEEDS_INI_SIZE', 'Uploaded file exceeds the server upload limit.', 413],
        UPLOAD_ERR_FORM_SIZE => ['UPLOAD_EXCEEDS_FORM_SIZE', 'Uploaded file exceeds the submitted form limit.', 413],
        UPLOAD_ERR_PARTIAL => ['UPLOAD_PARTIAL', 'Uploaded file was only partially received.', 400],
        UPLOAD_ERR_NO_FILE => ['UPLOAD_FILE_MISSING', 'Uploaded file is required.', 400],
        UPLOAD_ERR_NO_TMP_DIR => ['UPLOAD_TEMP_DIR_MISSING', 'Server upload temporary directory is unavailable.', 500],
        UPLOAD_ERR_CANT_WRITE => ['UPLOAD_TEMP_WRITE_FAILED', 'Server could not write the uploaded file.', 500],
        UPLOAD_ERR_EXTENSION => ['UPLOAD_BLOCKED_BY_EXTENSION', 'Uploaded file was rejected by a server extension.', 400],
    ];

    public static function extract(
        string $endpoint,
        string $method,
        string $path,
        array $headers,
        string $rawBody,
        array $post,
        array $files,
        array $server,
        K2AutomationApiConfig $config
    ): K2AutomationHttpRequest {
        if (in_array($endpoint, self::JSON_ENDPOINTS, true)) {
            self::rejectUnexpectedFiles($files, []);

            return new K2AutomationHttpRequest($method, $path, $headers, $rawBody, []);
        }

        if (!isset(self::MULTIPART_ENDPOINT_FILE_FIELDS[$endpoint])) {
            return new K2AutomationHttpRequest($method, $path, $headers, $rawBody, $files);
        }

        self::assertMultipartRequest($headers, $server);
        self::detectLikelyPostMaxSizeTruncation($headers, $server, $post, $files);
        self::rejectUnexpectedFormFields($post, ['metadata']);
        self::rejectUnexpectedFiles($files, self::MULTIPART_ENDPOINT_FILE_FIELDS[$endpoint]);

        if (!array_key_exists('metadata', $post)) {
            throw new K2AutomationRequestExtractionException(
                'MISSING_MULTIPART_METADATA',
                'Multipart metadata field is required.'
            );
        }
        if (is_array($post['metadata'])) {
            throw new K2AutomationRequestExtractionException(
                'INVALID_MULTIPART_METADATA_FIELD',
                'Multipart metadata field must be a single string.'
            );
        }

        $metadataRaw = (string) $post['metadata'];
        if (trim($metadataRaw) === '') {
            throw new K2AutomationRequestExtractionException(
                'MISSING_MULTIPART_METADATA',
                'Multipart metadata field is required.'
            );
        }

        foreach (self::MULTIPART_ENDPOINT_FILE_FIELDS[$endpoint] as $field) {
            self::validateUploadField($field, $files[$field] ?? null, $config);
        }

        return new K2AutomationHttpRequest($method, $path, $headers, $metadataRaw, $files);
    }

    private static function assertMultipartRequest(array $headers, array $server): void
    {
        $contentType = strtolower((string) (
            $server['CONTENT_TYPE']
            ?? $server['HTTP_CONTENT_TYPE']
            ?? self::header($headers, 'content-type')
            ?? ''
        ));

        if (!str_starts_with($contentType, 'multipart/form-data')) {
            throw new K2AutomationRequestExtractionException(
                'MULTIPART_REQUIRED',
                'Endpoint requires multipart/form-data.'
            );
        }
    }

    private static function detectLikelyPostMaxSizeTruncation(
        array $headers,
        array $server,
        array $post,
        array $files
    ): void {
        if ($post !== [] || $files !== []) {
            return;
        }

        $contentLength = (int) (
            $server['CONTENT_LENGTH']
            ?? self::header($headers, 'content-length')
            ?? 0
        );
        if ($contentLength <= 0) {
            return;
        }

        $postMaxBytes = self::iniBytes((string) ini_get('post_max_size'));
        if ($postMaxBytes > 0 && $contentLength > (int) floor($postMaxBytes * 0.95)) {
            throw new K2AutomationRequestExtractionException(
                'MULTIPART_POST_TOO_LARGE',
                'Multipart request exceeds the configured server post size limit.',
                413
            );
        }

        throw new K2AutomationRequestExtractionException(
            'MULTIPART_BODY_TRUNCATED',
            'Multipart request body was not parsed by the server.',
            413
        );
    }

    private static function rejectUnexpectedFormFields(array $post, array $expectedFields): void
    {
        $unexpected = array_values(array_diff(array_keys($post), $expectedFields));
        if ($unexpected !== []) {
            throw new K2AutomationRequestExtractionException(
                'UNEXPECTED_MULTIPART_FIELD',
                'Unexpected multipart form field.'
            );
        }
    }

    private static function rejectUnexpectedFiles(array $files, array $expectedFields): void
    {
        $unexpected = array_values(array_diff(array_keys($files), $expectedFields));
        if ($unexpected !== []) {
            throw new K2AutomationRequestExtractionException(
                'UNEXPECTED_FILE_FIELD',
                'Unexpected multipart file field.'
            );
        }
    }

    private static function validateUploadField(string $field, mixed $file, K2AutomationApiConfig $config): void
    {
        if (!is_array($file)) {
            throw new K2AutomationRequestExtractionException(
                'UPLOAD_FILE_MISSING',
                'Uploaded file is required.'
            );
        }

        foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
            if (isset($file[$key]) && is_array($file[$key])) {
                throw new K2AutomationRequestExtractionException(
                    'MULTIPLE_UPLOADS_REJECTED',
                    'Multiple uploads are not accepted for a single file field.'
                );
            }
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            [$code, $message, $status] = self::UPLOAD_ERROR_MAP[$error]
                ?? ['UPLOAD_UNKNOWN_ERROR', 'Uploaded file failed with an unknown server error.', 400];
            throw new K2AutomationRequestExtractionException($code, $message, $status);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '') {
            throw new K2AutomationRequestExtractionException(
                'UPLOAD_TEMP_FILE_INVALID',
                'Uploaded file temporary path is invalid.'
            );
        }

        $isValidUploadedFile = is_uploaded_file($tmpName);
        if (!$isValidUploadedFile && PHP_SAPI === 'cli') {
            $isValidUploadedFile = is_file($tmpName);
        }
        if (!$isValidUploadedFile || !is_readable($tmpName)) {
            throw new K2AutomationRequestExtractionException(
                'UPLOAD_TEMP_FILE_INVALID',
                'Uploaded file temporary path is invalid.'
            );
        }

        $size = filesize($tmpName);
        if ($size === false) {
            throw new K2AutomationRequestExtractionException(
                'UPLOAD_TEMP_FILE_INVALID',
                'Uploaded file temporary path is invalid.'
            );
        }

        if ($size > $config->maxUploadBytes) {
            throw new K2AutomationRequestExtractionException(
                'UPLOAD_TOO_LARGE',
                'Uploaded file exceeds the configured size limit.',
                413
            );
        }

        if (isset($file['size']) && (int) $file['size'] !== (int) $size) {
            throw new K2AutomationRequestExtractionException(
                'UPLOAD_SIZE_MISMATCH',
                'Uploaded file size metadata does not match the temporary file.'
            );
        }
    }

    private static function header(array $headers, string $name): ?string
    {
        $target = strtolower($name);
        foreach ($headers as $headerName => $value) {
            if (strtolower((string) $headerName) === $target) {
                return trim(is_array($value) ? (string) reset($value) : (string) $value);
            }
        }

        return null;
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;
        return match ($unit) {
            'g' => (int) ($number * 1024 * 1024 * 1024),
            'm' => (int) ($number * 1024 * 1024),
            'k' => (int) ($number * 1024),
            default => (int) $number,
        };
    }
}
