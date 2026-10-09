<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This validation runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/xlsx_normalizer.php';

$repoRoot = dirname(__DIR__, 2);
$fixtureDir = $repoRoot . DIRECTORY_SEPARATOR . 'local-fixtures';
$reportDir = $repoRoot . DIRECTORY_SEPARATOR . 'local-reports';
$csvDir = $reportDir . DIRECTORY_SEPARATOR . 'phase1-csv';
$reportPath = $reportDir . DIRECTORY_SEPARATOR . 'phase1-xlsx-poc-report.md';

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Cannot create local report directory: {$reportDir}\n");
    exit(1);
}

if (!is_dir($csvDir) && !mkdir($csvDir, 0775, true) && !is_dir($csvDir)) {
    fwrite(STDERR, "Cannot create local CSV output directory: {$csvDir}\n");
    exit(1);
}

$schemas = k2_file_schemas();
$normalizer = new K2XlsxNormalizer();
$zipRuntimeAvailable = extension_loaded('zip') && class_exists('ZipArchive');
$results = [];
$errors = [];

foreach ($schemas as $datasetType => $schema) {
    $sourcePath = $fixtureDir . DIRECTORY_SEPARATOR . $schema['fixture_filename'];
    $basic = [
        'dataset_type' => $datasetType,
        'source_filename' => $schema['fixture_filename'],
        'source_path' => $sourcePath,
        'exists' => is_file($sourcePath),
        'extension_is_xlsx' => strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION)) === 'xlsx',
        'signature_valid' => is_file($sourcePath) ? read_k2_signature($sourcePath) === "PK\x03\x04" : false,
        'source_hash_before' => is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null,
        'source_modified_at_before' => is_file($sourcePath) ? filemtime($sourcePath) : null,
        'expected_range' => $schema['expected_range'],
        'expected_total_rows' => $schema['expected_total_rows'],
        'expected_column_count' => $schema['expected_column_count'],
        'expected_headers' => $schema['headers'],
    ];

    if (!$zipRuntimeAvailable) {
        $basic['runtime_status'] = 'blocked';
        $basic['runtime_blocker'] = 'PHP zip extension / ZipArchive is not available in this runtime.';
        $basic['source_hash_after'] = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null;
        $basic['source_modified_at_after'] = is_file($sourcePath) ? filemtime($sourcePath) : null;
        $results[$datasetType] = $basic;
        continue;
    }

    try {
        $metadata = $normalizer->normalize($datasetType, $sourcePath, $csvDir);
        $metadata['csv_header_reopen_matches'] = csv_header_matches($metadata['temporary_csv_path'], $schema['headers']);
        $metadata['temporary_output_inside_fixture_dir'] = path_is_inside($metadata['temporary_csv_path'], $fixtureDir);
        $metadata['expected_total_rows'] = $schema['expected_total_rows'];
        $metadata['expected_column_count'] = $schema['expected_column_count'];
        $metadata['row_count_matches_expected'] = $metadata['source_row_count'] === $schema['expected_total_rows'];
        $metadata['csv_row_count_matches_source'] = $metadata['written_csv_row_count'] === $metadata['source_row_count'];
        $metadata['column_count_matches_expected'] = $metadata['column_count'] === $schema['expected_column_count'];
        $metadata['source_hash_before'] = $metadata['source_hash'];
        $metadata['runtime_status'] = 'passed';
        $results[$datasetType] = $metadata;
    } catch (Throwable $e) {
        $basic['runtime_status'] = 'failed';
        $basic['error'] = $e instanceof K2XlsxNormalizationException ? $e->reason() : get_class($e);
        $basic['error_message'] = $e->getMessage();
        $basic['source_hash_after'] = is_file($sourcePath) ? hash_file('sha256', $sourcePath) : null;
        $basic['source_modified_at_after'] = is_file($sourcePath) ? filemtime($sourcePath) : null;
        $errors[$datasetType] = $basic['error_message'];
        $results[$datasetType] = $basic;
    }
}

$markdown = build_report($results, $zipRuntimeAvailable, $errors);
file_put_contents($reportPath, $markdown);

echo "Report written: {$reportPath}\n";
if (!$zipRuntimeAvailable || $errors !== []) {
    exit(2);
}

exit(0);

function read_k2_signature(string $path): string
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return '';
    }

    try {
        return (string) fread($handle, 4);
    } finally {
        fclose($handle);
    }
}

function csv_header_matches(string $csvPath, array $expectedHeaders): bool
{
    $handle = fopen($csvPath, 'rb');
    if ($handle === false) {
        return false;
    }

    try {
        $headers = fgetcsv($handle);
        return $headers === $expectedHeaders;
    } finally {
        fclose($handle);
    }
}

function path_is_inside(string $path, string $directory): bool
{
    $realPath = realpath($path);
    $realDirectory = realpath($directory);
    if ($realPath === false || $realDirectory === false) {
        return false;
    }

    $realPath = strtolower(str_replace('\\', '/', $realPath));
    $realDirectory = rtrim(strtolower(str_replace('\\', '/', $realDirectory)), '/') . '/';

    return str_starts_with($realPath, $realDirectory);
}

function build_report(array $results, bool $zipRuntimeAvailable, array $errors): string
{
    $lines = [];
    $lines[] = '# Phase 1 XLSX Normalization POC Report';
    $lines[] = '';
    $lines[] = 'Generated: ' . date('Y-m-d H:i:s T');
    $lines[] = '';
    $lines[] = 'Scope: safe read-only XLSX ingestion and temporary CSV normalization proof of concept only. This runner does not include database configuration, does not call existing import parsers, does not connect to Google Drive, and does not perform HTTP requests.';
    $lines[] = '';
    $lines[] = '## Runtime';
    $lines[] = '';
    $lines[] = '- PHP version: `' . PHP_VERSION . '`';
    $lines[] = '- ZipArchive available: `' . ($zipRuntimeAvailable ? 'yes' : 'no') . '`';
    $lines[] = '- Runtime validation status: `' . ($zipRuntimeAvailable && $errors === [] ? 'passed' : 'blocked_or_failed') . '`';
    if (!$zipRuntimeAvailable) {
        $lines[] = '- Runtime blocker: `OpenSpout requires ext-zip/ZipArchive to open XLSX files.`';
    }
    $lines[] = '';
    $lines[] = '## Files';
    $lines[] = '';
    $lines[] = '- `workspace/includes/automation/k2_file_schemas.php`';
    $lines[] = '- `workspace/includes/automation/xlsx_normalizer.php`';
    $lines[] = '- `workspace/automation/validate_k2_xlsx.php`';
    $lines[] = '- `workspace/automation/test_xlsx_normalizer.php`';
    $lines[] = '';
    $lines[] = '## Fixture Results';
    $lines[] = '';

    foreach ($results as $result) {
        $lines[] = '### ' . $result['source_filename'];
        $lines[] = '';
        $lines[] = '- Exists: `' . (($result['exists'] ?? true) ? 'yes' : 'no') . '`';
        $lines[] = '- Extension .xlsx: `' . (($result['extension_is_xlsx'] ?? true) ? 'yes' : 'no') . '`';
        $lines[] = '- ZIP/XLSX signature valid: `' . (($result['signature_valid'] ?? true) ? 'yes' : 'no') . '`';
        $lines[] = '- SHA-256 before: `' . ($result['source_hash_before'] ?? $result['source_hash'] ?? 'unavailable') . '`';
        $lines[] = '- SHA-256 after: `' . ($result['source_hash_after'] ?? 'unavailable') . '`';
        $lines[] = '- Expected worksheet: `Sheet0`';
        $lines[] = '- Expected range: `' . ($result['expected_range'] ?? 'unavailable') . '`';
        $lines[] = '- Expected rows: `' . ($result['expected_total_rows'] ?? 'unavailable') . '`';
        $lines[] = '- Expected columns: `' . ($result['expected_column_count'] ?? 'unavailable') . '`';
        $lines[] = '- Runtime status: `' . ($result['runtime_status'] ?? 'unknown') . '`';
        if (($result['runtime_blocker'] ?? '') !== '') {
            $lines[] = '- Runtime blocker: `' . $result['runtime_blocker'] . '`';
        }
        if (isset($result['source_row_count'])) {
            $lines[] = '- Rows read: `' . $result['source_row_count'] . '`';
            $lines[] = '- CSV rows written: `' . $result['written_csv_row_count'] . '`';
            $lines[] = '- Temporary CSV: `' . $result['temporary_csv_path'] . '`';
            $lines[] = '- CSV header reopen matched: `' . (($result['csv_header_reopen_matches'] ?? false) ? 'yes' : 'no') . '`';
            $lines[] = '- Temporary output inside fixture directory: `' . (($result['temporary_output_inside_fixture_dir'] ?? false) ? 'yes' : 'no') . '`';
            $lines[] = '- Elapsed seconds: `' . $result['elapsed_time_seconds'] . '`';
            $lines[] = '- Peak memory bytes: `' . $result['peak_memory_bytes'] . '`';
        }
        $lines[] = '';
    }

    $lines[] = '## Date Normalization Policy';
    $lines[] = '';
    $lines[] = 'OpenSpout DateTimeInterface values are serialized explicitly as `Y-m-d` when the time is midnight, otherwise as `Y-m-d H:i:s`. The existing PR and AON CSV parsers call `strtotime()` and then store `Y-m-d`, so both serialized forms are compatible without relying on locale-specific dates.';
    $lines[] = '';
    $lines[] = '## Phase 2 Risks';
    $lines[] = '';
    $lines[] = '- Production Hostinger PHP environment must confirm `ext-zip`, `xmlreader`, `mbstring`, and PHP 8.2+ availability.';
    $lines[] = '- Archive folder ID remains pending.';
    $lines[] = '- Production or staging database test strategy remains pending.';
    $lines[] = '- Existing PR and AON parsers perform database writes and must stay behind an adapter/orchestration boundary in a later phase.';
    $lines[] = '';

    return implode("\n", $lines) . "\n";
}
