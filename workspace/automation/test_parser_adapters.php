<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/parser_adapters.php';

$tests = [];
$tempFiles = [];

$tests['manual parser signatures remain backward compatible'] = function (): void {
    $prSource = file_get_contents(__DIR__ . '/../includes/excel_parser.php');
    $aonSource = file_get_contents(__DIR__ . '/../includes/aon_parser.php');
    if ($prSource === false || $aonSource === false) {
        throw new RuntimeException('Could not read parser sources.');
    }

    assert_true(str_contains(
        $prSource,
        'function parse_and_insert_csv($file_path, $original_filename = null, $imported_by = null)'
    ));
    assert_true(str_contains(
        $aonSource,
        'function parse_and_insert_dual_aon($catalog_path, $price_path, $imported_by = null)'
    ));
};

$tests['manual parser result keys remain available'] = function (): void {
    $prSource = file_get_contents(__DIR__ . '/../includes/excel_parser.php') ?: '';
    foreach ([
        'total_rows',
        'inserted',
        'updated',
        'skipped',
        'ai_tickets',
        'ai_lines',
        'warning_count',
        'warnings_stored',
        'warnings_truncated',
        'import_batch_logged',
    ] as $key) {
        assert_true(str_contains($prSource, "'{$key}'"), "Missing PR result key {$key}.");
    }

    $aonSource = file_get_contents(__DIR__ . '/../includes/aon_parser.php') ?: '';
    foreach ([
        'catalog_count',
        'price_count',
        'total',
        'inserted',
        'updated',
        'skipped',
        'removed',
        'dict_inserted',
        'dict_updated',
        'dup_sku_count',
        'dup_name_count',
        'unmatched_count',
        'price_columns',
    ] as $key) {
        assert_true(str_contains($aonSource, "'{$key}'"), "Missing AON result key {$key}.");
    }
};

$tests['AON removal ratio config parses safely'] = function (): void {
    $previous = getenv('K2_AON_MAX_REMOVAL_RATIO');
    putenv('K2_AON_MAX_REMOVAL_RATIO');
    $config = K2AutomationParserAdapterConfig::fromEnvironment();
    assert_same(null, $config->aonMaxRemovalRatio);

    putenv('K2_AON_MAX_REMOVAL_RATIO=0.25');
    $config = K2AutomationParserAdapterConfig::fromEnvironment();
    assert_same(0.25, $config->aonMaxRemovalRatio);

    expect_exception(InvalidArgumentException::class, function (): void {
        new K2AutomationParserAdapterConfig(sys_get_temp_dir(), 'tester', 1.5);
    });

    if ($previous === false) {
        putenv('K2_AON_MAX_REMOVAL_RATIO');
    } else {
        putenv('K2_AON_MAX_REMOVAL_RATIO=' . $previous);
    }
};

$tests['uploaded workbook signature and hash validation'] = function () use (&$tempFiles): void {
    $validPath = temp_xlsx_file('PK' . "\x03\x04" . 'valid', $tempFiles);
    $context = upload_context($validPath, hash_file('sha256', $validPath), 'pr_file');
    K2AutomationParserAdapterSupport::assertUploadedWorkbookMatchesSource(
        $context,
        K2ImportDatasetType::PR,
        $validPath,
        'pr_file'
    );

    $badSignature = temp_xlsx_file('not-xlsx', $tempFiles);
    expect_adapter_failure('XLSX_SIGNATURE_INVALID', function () use ($badSignature): void {
        K2AutomationParserAdapterSupport::assertUploadedWorkbookMatchesSource(
            upload_context($badSignature, hash_file('sha256', $badSignature), 'pr_file'),
            K2ImportDatasetType::PR,
            $badSignature,
            'pr_file'
        );
    });

    expect_adapter_failure('UPLOAD_HASH_MISMATCH', function () use ($validPath): void {
        K2AutomationParserAdapterSupport::assertUploadedWorkbookMatchesSource(
            upload_context($validPath, str_repeat('a', 64), 'pr_file'),
            K2ImportDatasetType::PR,
            $validPath,
            'pr_file'
        );
    });
};

$tests['temporary CSV cleanup and safe count redaction'] = function () use (&$tempFiles): void {
    $csv = tempnam(sys_get_temp_dir(), 'k2_cleanup_');
    if ($csv === false) {
        throw new RuntimeException('Could not create temp file.');
    }
    $csv .= '.csv';
    file_put_contents($csv, "a,b\n1,2\n");
    $tempFiles[] = $csv;
    assert_true(is_file($csv));
    assert_true(K2XlsxNormalizer::cleanupTemporaryCsv($csv));
    assert_false(is_file($csv));

    assert_same(['count' => 2, 'ratio' => 0.5], K2AutomationParserAdapterSupport::safeCounts([
        'count' => 2,
        'ratio' => 0.5,
        'secret' => 'not safe',
        'path' => __FILE__,
    ]));
};

try {
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
} finally {
    foreach ($tempFiles as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

if ($failures > 0) {
    echo "{$failures} parser adapter test(s) failed.\n";
    exit(1);
}

echo count($tests) . " parser adapter test(s) passed.\n";

function temp_xlsx_file(string $content, array &$tempFiles): string
{
    $path = tempnam(sys_get_temp_dir(), 'k2_xlsx_');
    if ($path === false) {
        throw new RuntimeException('Could not create temp xlsx.');
    }
    $xlsx = $path . '.xlsx';
    @unlink($path);
    file_put_contents($xlsx, $content);
    $tempFiles[] = $xlsx;

    return $xlsx;
}

function upload_context(string $path, string $hash, string $field): array
{
    return [
        'payload' => [
            'source_files' => [[
                'dataset_type' => K2ImportDatasetType::PR,
                'drive_file_id' => 'drive-pr',
                'original_filename' => 'pr.xlsx',
                'modified_timestamp' => '2026-07-22T02:00:00.000000Z',
                'file_size_bytes' => filesize($path),
                'sha256_content_hash' => $hash,
            ]],
        ],
        'files' => [
            $field => [
                'tmp_name' => $path,
                'name' => 'pr.xlsx',
            ],
        ],
    ];
}

function expect_adapter_failure(string $code, callable $callback): void
{
    try {
        $callback();
    } catch (K2AutomationParserAdapterException $e) {
        assert_same($code, $e->stableCode());
        return;
    }

    throw new RuntimeException("Expected adapter failure {$code}.");
}

function expect_exception(string $className, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $className) {
            return;
        }

        throw new RuntimeException("Expected {$className}, got " . get_class($e));
    }

    throw new RuntimeException("Expected {$className}.");
}

function assert_same(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException('Assertion failed. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
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
