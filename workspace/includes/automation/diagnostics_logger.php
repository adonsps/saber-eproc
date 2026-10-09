<?php
declare(strict_types=1);

/**
 * Structured, append-only diagnostics log for the K2 async queue (HTTP handlers
 * and the CLI worker). One JSON object per line. Never throws — a logging failure
 * must never break the request/worker it is observing.
 */
final class K2AutomationDiagnosticsLogger
{
    private const BLOCKED_KEY_FRAGMENTS = [
        'secret', 'hmac', 'password', 'passwd', 'credential', 'smtp',
        'workbook', 'raw_body', 'body_content', 'file_contents',
    ];

    public function __construct(
        private readonly string $logPath,
        private readonly ?string $batchUid = null,
        private readonly ?string $operation = null,
        private readonly ?int $attemptNumber = null,
        private readonly ?string $requestId = null
    ) {
    }

    public static function fromEnvironment(
        ?string $batchUid = null,
        ?string $operation = null,
        ?int $attemptNumber = null,
        ?string $requestId = null
    ): self {
        $path = getenv('K2_AUTOMATION_DIAGNOSTIC_LOG_PATH');

        return new self(
            $path === false || trim($path) === ''
                ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k2_automation_diagnostics.log'
                : trim($path),
            $batchUid,
            $operation,
            $attemptNumber,
            $requestId
        );
    }

    /** Returns a copy scoped to a specific batch/operation/attempt/request for a later stage. */
    public function withContext(
        ?string $batchUid = null,
        ?string $operation = null,
        ?int $attemptNumber = null,
        ?string $requestId = null
    ): self {
        return new self(
            $this->logPath,
            $batchUid ?? $this->batchUid,
            $operation ?? $this->operation,
            $attemptNumber ?? $this->attemptNumber,
            $requestId ?? $this->requestId
        );
    }

    public function info(string $eventCode, array $context = []): void
    {
        $this->write('INFO', $eventCode, $context);
    }

    public function warn(string $eventCode, array $context = []): void
    {
        $this->write('WARN', $eventCode, $context);
    }

    public function error(string $eventCode, array $context = []): void
    {
        $this->write('ERROR', $eventCode, $context);
    }

    /** Logs a start/end pair around a callable, including elapsed milliseconds either way. */
    public function timed(string $stage, callable $callback, array $context = []): mixed
    {
        $start = microtime(true);
        $this->write('INFO', 'STAGE_STARTED', array_merge($context, ['stage' => $stage]));

        try {
            $result = $callback();
            $this->write('INFO', 'STAGE_COMPLETED', array_merge(
                $context,
                ['stage' => $stage, 'elapsed_ms' => self::elapsedMs($start)]
            ));

            return $result;
        } catch (Throwable $e) {
            $this->write('ERROR', 'STAGE_FAILED', array_merge(
                $context,
                ['stage' => $stage, 'elapsed_ms' => self::elapsedMs($start)],
                self::classifyException($e)
            ));

            throw $e;
        }
    }

    public function exception(string $eventCode, Throwable $e, array $context = []): void
    {
        $this->write('ERROR', $eventCode, array_merge($context, self::classifyException($e)));
    }

    /**
     * Redacted/hashed form of a worker claim token (or any other sensitive
     * identifier) for logging — never the raw value.
     */
    public static function redactToken(?string $token): ?string
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        return 'sha256:' . substr(hash('sha256', $token), 0, 16);
    }

    /**
     * Registers a shutdown handler that logs a FATAL_SHUTDOWN event when the
     * process ends due to an uncaught fatal error/timeout rather than a normal
     * return, so CLI worker crashes remain diagnosable after the fact.
     */
    public static function installShutdownHandler(self $logger, array $context = []): void
    {
        register_shutdown_function(static function () use ($logger, $context): void {
            $lastError = error_get_last();
            if ($lastError === null) {
                return;
            }

            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
            if (!in_array($lastError['type'], $fatalTypes, true)) {
                return;
            }

            $logger->error('FATAL_SHUTDOWN', array_merge($context, [
                'error_type' => $lastError['type'],
                'error_file' => basename((string) $lastError['file']),
                'error_line' => $lastError['line'],
            ]));
        });
    }

    private static function classifyException(Throwable $e): array
    {
        $classification = [
            'exception_class' => get_class($e),
        ];

        if (method_exists($e, 'stableCode')) {
            $classification['stable_code'] = (string) $e->stableCode();
        }
        if (method_exists($e, 'retryable')) {
            $classification['retryable'] = (bool) $e->retryable();
        }

        // Our own domain exceptions carry deliberately-external-safe messages
        // (see safeMessage()/getMessage() call sites elsewhere in automation/).
        // Anything else (framework/PDO/TypeError/...) may embed file paths or
        // query fragments, so only a generic marker is logged for those.
        $safeExceptionBases = [
            'K2AutomationParserAdapterException',
            'K2ImportOrchestrationException',
            'K2AutomationFileStorageException',
            'K2AutomationApiAuthException',
            'K2AutomationRequestExtractionException',
            'K2ImportTransitionRejectedException',
            'K2ImportRepositoryStateConflictException',
            'K2ImportDuplicateKeyConflictException',
            'K2XlsxNormalizationException',
            'K2AonGlobalLockBusyException',
        ];
        if (in_array(get_class($e), $safeExceptionBases, true)) {
            $classification['safe_message'] = $e->getMessage();
        }

        return $classification;
    }

    private static function elapsedMs(float $startMicrotime): int
    {
        return (int) round((microtime(true) - $startMicrotime) * 1000);
    }

    private function write(string $severity, string $eventCode, array $context): void
    {
        try {
            $record = array_filter(
                [
                    'timestamp' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
                    'severity' => $severity,
                    'event_code' => $eventCode,
                    'batch_uid' => $this->batchUid,
                    'operation' => $this->operation,
                    'attempt_number' => $this->attemptNumber,
                    'request_id' => $this->requestId,
                ],
                static fn(mixed $value): bool => $value !== null
            );
            $record += self::redact($context);

            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($line === false) {
                return;
            }

            @error_log($line . PHP_EOL, 3, $this->logPath);
        } catch (Throwable) {
            // Logging must never break the caller.
        }
    }

    private static function redact(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $blocked = false;
            foreach (self::BLOCKED_KEY_FRAGMENTS as $fragment) {
                if (str_contains($lowerKey, $fragment)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::redact($value);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
