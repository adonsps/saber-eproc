<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/xlsx_normalizer.php';

$repoRoot = dirname(__DIR__, 2);
$runtimeDir = $repoRoot . DIRECTORY_SEPARATOR . 'local-reports' . DIRECTORY_SEPARATOR . 'phase1-test-runtime';
$sourceDir = $runtimeDir . DIRECTORY_SEPARATOR . 'sources';
$outputDir = $runtimeDir . DIRECTORY_SEPARATOR . 'outputs';
safe_remove_tree($runtimeDir, $repoRoot);
mkdir($sourceDir, 0775, true);
mkdir($outputDir, 0775, true);

$tests = [];

$tests['correct dataset detection'] = function (): void {
    foreach (k2_file_schemas() as $datasetType => $schema) {
        assert_same($datasetType, k2_detect_file_schema_from_headers($schema['headers']));
    }
};

$tests['reject wrong dataset type'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'wrong-dataset.xlsx');
    expect_k2_exception('unexpected_header_count', function () use ($normalizer, $source, $outputDir): void {
        $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
            'Sheet0' => [
                k2_file_schema('catalog')['headers'],
                synthetic_row('catalog'),
            ],
        ]);
    });
};

$tests['missing Sheet0'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'missing-sheet.xlsx');
    expect_k2_exception('missing_worksheet', function () use ($normalizer, $source, $outputDir): void {
        $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
            'NotSheet0' => [
                k2_file_schema('price')['headers'],
                synthetic_row('price'),
            ],
        ]);
    });
};

$tests['missing required header'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'missing-required.xlsx');
    $headers = k2_file_schema('price')['headers'];
    $headers[2] = 'VENDOR IDENTIFIER';
    expect_k2_exception('missing_mandatory_headers', function () use ($normalizer, $source, $outputDir, $headers): void {
        $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
            'Sheet0' => [
                $headers,
                synthetic_row('price'),
            ],
        ]);
    });
};

$tests['duplicate header'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'duplicate-header.xlsx');
    $headers = k2_file_schema('catalog')['headers'];
    $headers[1] = $headers[0];
    expect_k2_exception('duplicate_headers', function () use ($normalizer, $source, $outputDir, $headers): void {
        $normalizer->normalizeSyntheticSheets('catalog', $source, $outputDir, [
            'Sheet0' => [
                $headers,
                synthetic_row('catalog'),
            ],
        ]);
    });
};

$tests['empty workbook'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'empty-workbook.xlsx');
    expect_k2_exception('empty_workbook', function () use ($normalizer, $source, $outputDir): void {
        $normalizer->normalizeSyntheticSheets('catalog', $source, $outputDir, ['Sheet0' => []]);
    });
};

$tests['temporary-file cleanup'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'cleanup.xlsx');
    $metadata = $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('price')['headers'],
            synthetic_row('price'),
        ],
    ]);
    assert_true(is_file($metadata['temporary_csv_path']), 'temporary CSV should exist before cleanup');
    assert_true(K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']), 'cleanup should remove the temporary CSV');
    assert_false(is_file($metadata['temporary_csv_path']), 'temporary CSV should not exist after cleanup');
};

$tests['source workbook immutability'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'immutability.xlsx');
    $beforeHash = hash_file('sha256', $source);
    $beforeMtime = filemtime($source);
    $metadata = $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('pr')['headers'],
            synthetic_row('pr'),
        ],
    ]);
    assert_same($beforeHash, $metadata['source_hash_after']);
    assert_same($beforeHash, hash_file('sha256', $source));
    assert_same($beforeMtime, filemtime($source));
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['stable CSV header output'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'stable-header.xlsx');
    $schema = k2_file_schema('catalog');
    $metadata = $normalizer->normalizeSyntheticSheets('catalog', $source, $outputDir, [
        'Sheet0' => [
            $schema['headers'],
            synthetic_row('catalog'),
        ],
    ]);
    $handle = fopen($metadata['temporary_csv_path'], 'rb');
    assert_true($handle !== false, 'CSV should reopen');
    $header = fgetcsv($handle);
    fclose($handle);
    assert_same($schema['headers'], $header);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['DateTime normalization'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'datetime.xlsx');
    $row = synthetic_row('price');
    $row[4] = new DateTimeImmutable('2026-01-02 00:00:00');
    $row[5] = new DateTimeImmutable('2026-12-31 13:14:15');
    $metadata = $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('price')['headers'],
            $row,
        ],
    ]);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same('2026-01-02', $data[1][4]);
    assert_same('2026-12-31 13:14:15', $data[1][5]);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['date string normalization for configured date columns'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'date-string.xlsx');
    $row = synthetic_row('price');
    $row[4] = '10/3/2025 12:00:00 AM';
    $row[5] = '12/31/2026 1:14:15 PM';
    $metadata = $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('price')['headers'],
            $row,
        ],
    ]);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same('2025-10-03', $data[1][4]);
    assert_same('2026-12-31 13:14:15', $data[1][5]);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['invalid date string in configured date column is rejected safely'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'invalid-date-string.xlsx');
    $row = synthetic_row('price');
    $row[4] = 'not-a-date';
    expect_k2_exception('invalid_date_value', function () use ($normalizer, $source, $outputDir, $row): void {
        $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
            'Sheet0' => [
                k2_file_schema('price')['headers'],
                $row,
            ],
        ]);
    });
};

$tests['boolean normalization'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'boolean.xlsx');
    $row = synthetic_row('catalog');
    $row[16] = true;
    $metadata = $normalizer->normalizeSyntheticSheets('catalog', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('catalog')['headers'],
            $row,
        ],
    ]);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same('TRUE', $data[1][16]);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['null handling'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'null.xlsx');
    $row = synthetic_row('price');
    $row[3] = null;
    $metadata = $normalizer->normalizeSyntheticSheets('price', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('price')['headers'],
            $row,
        ],
    ]);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same('', $data[1][3]);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['CSV quoting for commas quotes and line breaks'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'csv-quoting.xlsx');
    $row = synthetic_row('pr');
    $row[28] = "contains, comma and \"quote\" and\nline break";
    $metadata = $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
        'Sheet0' => [
            k2_file_schema('pr')['headers'],
            $row,
        ],
    ]);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same($row[28], $data[1][28]);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['PR file with TICKET HELPDESK NO. (40 columns) is accepted'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'pr-40col.xlsx');
    $row = synthetic_row('pr');
    $row[39] = '150926FACELIFTHCIR';
    $metadata = $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
        'Sheet0' => [k2_file_schema('pr')['headers'], $row],
    ]);
    assert_same(40, $metadata['column_count']);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same('TICKET HELPDESK NO.', $data[0][39]);
    assert_same('150926FACELIFTHCIR', $data[1][39]);
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['legacy PR file without the helpdesk column (39 columns) still validates'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'pr-39col.xlsx');
    $headers = array_slice(k2_file_schema('pr')['headers'], 0, 39);
    $row = array_slice(synthetic_row('pr'), 0, 39);
    assert_same('pr', k2_detect_file_schema_from_headers($headers));
    $metadata = $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
        'Sheet0' => [$headers, $row],
    ]);
    assert_same(39, $metadata['column_count']);
    $data = read_csv_rows($metadata['temporary_csv_path']);
    assert_same(39, count($data[0]));
    K2XlsxNormalizer::cleanupTemporaryCsv($metadata['temporary_csv_path']);
};

$tests['PR file with an unknown extra column is still rejected'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'pr-41col.xlsx');
    $headers = array_merge(k2_file_schema('pr')['headers'], ['SOMETHING NEW']);
    expect_k2_exception('unexpected_header_count', function () use ($normalizer, $source, $outputDir, $headers): void {
        $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
            'Sheet0' => [$headers, array_merge(synthetic_row('pr'), ['x'])],
        ]);
    });
};

$tests['PR file with a renamed helpdesk header is rejected'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = make_fake_xlsx_source($sourceDir, 'pr-renamed.xlsx');
    $headers = k2_file_schema('pr')['headers'];
    $headers[39] = 'HELP DESK NUMBER';
    expect_k2_exception('unexpected_headers', function () use ($normalizer, $source, $outputDir, $headers): void {
        $normalizer->normalizeSyntheticSheets('pr', $source, $outputDir, [
            'Sheet0' => [$headers, synthetic_row('pr')],
        ]);
    });
};

$tests['incorrect file signature'] = function () use ($sourceDir, $outputDir): void {
    $normalizer = new K2XlsxNormalizer();
    $source = $sourceDir . DIRECTORY_SEPARATOR . 'bad-signature.xlsx';
    file_put_contents($source, 'not an xlsx');
    expect_k2_exception('invalid_signature', function () use ($normalizer, $source, $outputDir): void {
        $normalizer->normalizeSyntheticSheets('catalog', $source, $outputDir, [
            'Sheet0' => [
                k2_file_schema('catalog')['headers'],
                synthetic_row('catalog'),
            ],
        ]);
    });
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

safe_remove_tree($runtimeDir, $repoRoot);

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo count($tests) . " test(s) passed.\n";

function synthetic_row(string $datasetType): array
{
    $schema = k2_file_schema($datasetType);
    $row = array_fill(0, $schema['expected_column_count'], 'synthetic');

    foreach ($schema['headers'] as $index => $header) {
        if (in_array($header, $schema['numeric_headers'], true)) {
            $row[$index] = 1;
        }
        if (in_array($header, $schema['date_headers'], true)) {
            $row[$index] = '2026-01-02';
        }
    }

    return $row;
}

function make_fake_xlsx_source(string $sourceDir, string $filename): string
{
    $path = $sourceDir . DIRECTORY_SEPARATOR . $filename;
    file_put_contents($path, "PK\x03\x04synthetic test workbook placeholder");
    return $path;
}

function expect_k2_exception(string $reason, callable $callback): void
{
    try {
        $callback();
    } catch (K2XlsxNormalizationException $e) {
        assert_same($reason, $e->reason());
        return;
    }

    throw new RuntimeException("Expected K2XlsxNormalizationException with reason {$reason}.");
}

function read_csv_rows(string $csvPath): array
{
    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Could not open CSV: {$csvPath}");
    }

    $rows = [];
    try {
        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }
    } finally {
        fclose($handle);
    }

    return $rows;
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

function safe_remove_tree(string $path, string $repoRoot): void
{
    $path = rtrim(str_replace('\\', '/', $path), '/');
    $repoRoot = rtrim(str_replace('\\', '/', realpath($repoRoot) ?: $repoRoot), '/');
    $allowedRoot = $repoRoot . '/local-reports/phase1-test-runtime';
    if ($path !== $allowedRoot || !is_dir($path)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        if ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }

    rmdir($path);
}
