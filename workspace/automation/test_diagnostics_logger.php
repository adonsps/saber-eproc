<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/diagnostics_logger.php';
require_once __DIR__ . '/../includes/automation/import_state.php';
require_once __DIR__ . '/../includes/automation/file_storage.php';

$tests = [];
$logFiles = [];

$tests['redaction strips blocked key fragments regardless of nesting'] = function () use (&$logFiles): void {
    $path = fresh_log_path($logFiles);
    $logger = new K2AutomationDiagnosticsLogger($path);
    $logger->info('TEST_EVENT', [
        'hmac_secret' => 'must-not-appear',
        'password' => 'must-not-appear',
        'smtp_password' => 'must-not-appear',
        'raw_body' => 'must-not-appear',
        'workbook_bytes' => 'must-not-appear',
        'nested' => ['credential_value' => 'must-not-appear', 'safe_field' => 'keep-me'],
        'safe_field' => 'keep-me',
    ]);

    $line = last_log_line($path);
    assert_true(!str_contains($line, 'must-not-appear'), 'A blocked-fragment key leaked into the log line.');
    assert_true(str_contains($line, 'keep-me'), 'A safe field was incorrectly redacted.');
};

$tests['redactToken never logs the raw token, only a stable truncated hash'] = function (): void {
    $token = 'k2wrk_' . str_repeat('a', 32);
    $redacted = K2AutomationDiagnosticsLogger::redactToken($token);

    assert_true($redacted !== null);
    assert_true(!str_contains((string) $redacted, $token), 'redactToken must never return the raw token.');
    assert_true(str_starts_with((string) $redacted, 'sha256:'), 'redactToken must be prefixed to identify it as a hash, not a raw value.');
    assert_same($redacted, K2AutomationDiagnosticsLogger::redactToken($token), 'redactToken must be deterministic for the same input.');
    assert_true(K2AutomationDiagnosticsLogger::redactToken(null) === null);
    assert_true(K2AutomationDiagnosticsLogger::redactToken('') === null);
};

$tests['exception() attaches safe_message only for whitelisted domain exceptions'] = function () use (&$logFiles): void {
    $path = fresh_log_path($logFiles);
    $logger = new K2AutomationDiagnosticsLogger($path);

    $logger->exception('DOMAIN_FAILURE', new K2AutomationFileStorageException('SOME_CODE', 'This is a safe, external-facing message.'));
    $domainLine = last_log_line($path);
    assert_true(str_contains($domainLine, 'This is a safe, external-facing message.'), 'A whitelisted domain exception should have its message logged.');
    assert_true(str_contains($domainLine, 'K2AutomationFileStorageException'), 'exception_class should be logged.');

    $logger->exception('GENERIC_FAILURE', new RuntimeException('Possibly-sensitive internal detail: /var/www/html/secret/path'));
    $genericLine = last_log_line($path);
    assert_true(!str_contains($genericLine, 'Possibly-sensitive internal detail'), 'A non-whitelisted exception message must NOT be logged verbatim.');
    assert_true(str_contains($genericLine, 'RuntimeException'), 'exception_class should still be logged for non-whitelisted exceptions.');
};

$tests['timed() logs elapsed_ms on both success and failure, and rethrows'] = function () use (&$logFiles): void {
    $path = fresh_log_path($logFiles);
    $logger = new K2AutomationDiagnosticsLogger($path);

    $result = $logger->timed('some_stage', fn(): string => 'ok');
    assert_same('ok', $result);
    $successLine = last_log_line($path);
    assert_true(str_contains($successLine, 'STAGE_COMPLETED'));
    assert_true(str_contains($successLine, 'elapsed_ms'));

    expect_exception(RuntimeException::class, function () use ($logger): void {
        $logger->timed('failing_stage', function (): void {
            throw new RuntimeException('boom');
        });
    });
    $failureLine = last_log_line($path);
    assert_true(str_contains($failureLine, 'STAGE_FAILED'));
    assert_true(str_contains($failureLine, 'elapsed_ms'));
};

$tests['withContext scopes batch/operation/attempt/request without mutating the original logger'] = function () use (&$logFiles): void {
    $path = fresh_log_path($logFiles);
    $base = new K2AutomationDiagnosticsLogger($path);
    $scoped = $base->withContext('k2b_test', K2ImportOperationType::AON, 3, 'req-123');

    $scoped->info('SCOPED_EVENT', []);
    $scopedLine = last_log_line($path);
    assert_true(str_contains($scopedLine, 'k2b_test'));
    assert_true(str_contains($scopedLine, '"attempt_number":3'));
    assert_true(str_contains($scopedLine, 'req-123'));

    $base->info('UNSCOPED_EVENT', []);
    $baseLine = last_log_line($path);
    assert_true(!str_contains($baseLine, 'k2b_test'), 'withContext() must not mutate the original logger it was derived from.');
};

$tests['logging never throws even when the log path is unwritable'] = function (): void {
    $unwritableDir = sys_get_temp_dir() . '/k2-diag-unwritable-' . bin2hex(random_bytes(4));
    // A path whose parent does not exist and cannot be auto-created by error_log().
    $badPath = $unwritableDir . '/does/not/exist/diag.log';
    $logger = new K2AutomationDiagnosticsLogger($badPath);

    $threw = false;
    try {
        $logger->info('SHOULD_NOT_THROW', ['x' => 1]);
        $logger->error('SHOULD_NOT_THROW_EITHER', ['y' => 2]);
    } catch (Throwable) {
        $threw = true;
    }
    assert_false($threw, 'Diagnostics logging must never throw, even when the log path is unwritable.');
};

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
foreach ($logFiles as $path) {
    @unlink($path);
}

if ($failures > 0) {
    echo "{$failures} diagnostics logger test(s) failed.\n";
    exit(1);
}

echo count($tests) . " diagnostics logger test(s) passed.\n";

function fresh_log_path(array &$logFiles): string
{
    $path = sys_get_temp_dir() . '/k2-diag-test-' . bin2hex(random_bytes(6)) . '.log';
    $logFiles[] = $path;

    return $path;
}

function last_log_line(string $path): string
{
    if (!is_file($path)) {
        throw new RuntimeException("Log file was not created: {$path}");
    }
    $lines = array_filter(explode("\n", (string) file_get_contents($path)));

    return (string) end($lines);
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

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? "{$message} " : '') . 'Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
