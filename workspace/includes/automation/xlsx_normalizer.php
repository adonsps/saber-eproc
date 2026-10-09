<?php
declare(strict_types=1);

require_once __DIR__ . '/k2_file_schemas.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

final class K2XlsxNormalizationException extends RuntimeException
{
    public function __construct(
        private readonly string $reason,
        string $message,
        private readonly array $context = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function context(): array
    {
        return $this->context;
    }
}

final class K2XlsxNormalizer
{
    private const EXPECTED_XLSX_SIGNATURE = "PK\x03\x04";

    public function normalize(string $datasetType, string $sourcePath, string $outputDirectory): array
    {
        $schema = k2_file_schema($datasetType);
        $context = $this->prepareRun($datasetType, $schema, $sourcePath, $outputDirectory);
        $reader = null;

        try {
            $options = new Options();
            $options->SHOULD_FORMAT_DATES = false;
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;

            $reader = new Reader($options);
            $reader->open($context['source_path']);

            $sheetNames = [];
            $selectedRows = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetNames[] = $sheet->getName();
                if ($sheet->getName() === $schema['worksheet']) {
                    $selectedRows = $sheet->getRowIterator();
                    break;
                }
            }

            if ($selectedRows === null) {
                throw new K2XlsxNormalizationException(
                    'missing_worksheet',
                    'Required worksheet is missing.',
                    ['required_worksheet' => $schema['worksheet'], 'worksheets' => $sheetNames]
                );
            }

            return $this->processRows($datasetType, $schema, $context, $selectedRows, $sheetNames);
        } catch (K2XlsxNormalizationException $e) {
            self::cleanupTemporaryCsv($context['temporary_csv_path']);
            throw $e;
        } catch (Throwable $e) {
            self::cleanupTemporaryCsv($context['temporary_csv_path']);
            throw new K2XlsxNormalizationException(
                'invalid_xlsx',
                'OpenSpout could not read the XLSX workbook.',
                ['source_filename' => basename($sourcePath)],
                $e
            );
        } finally {
            if ($reader instanceof Reader) {
                $reader->close();
            }
        }
    }

    public function normalizeSyntheticSheets(
        string $datasetType,
        string $sourcePath,
        string $outputDirectory,
        array $sheets
    ): array {
        $schema = k2_file_schema($datasetType);
        $context = $this->prepareRun($datasetType, $schema, $sourcePath, $outputDirectory);
        $sheetNames = array_keys($sheets);

        try {
            if (!array_key_exists($schema['worksheet'], $sheets)) {
                throw new K2XlsxNormalizationException(
                    'missing_worksheet',
                    'Required worksheet is missing.',
                    ['required_worksheet' => $schema['worksheet'], 'worksheets' => $sheetNames]
                );
            }

            return $this->processRows($datasetType, $schema, $context, $sheets[$schema['worksheet']], $sheetNames);
        } catch (K2XlsxNormalizationException $e) {
            self::cleanupTemporaryCsv($context['temporary_csv_path']);
            throw $e;
        } catch (Throwable $e) {
            self::cleanupTemporaryCsv($context['temporary_csv_path']);
            throw $e;
        }
    }

    public static function cleanupTemporaryCsv(?string $csvPath): bool
    {
        if ($csvPath === null || $csvPath === '' || !is_file($csvPath)) {
            return false;
        }

        return unlink($csvPath);
    }

    private function prepareRun(string $datasetType, array $schema, string $sourcePath, string $outputDirectory): array
    {
        $sourceRealPath = realpath($sourcePath);
        if ($sourceRealPath === false || !is_file($sourceRealPath)) {
            throw new K2XlsxNormalizationException(
                'source_missing',
                'Source workbook does not exist.',
                ['source_path' => $sourcePath]
            );
        }

        $sourceExtension = strtolower(pathinfo($sourceRealPath, PATHINFO_EXTENSION));
        if ($sourceExtension !== '' && $sourceExtension !== 'xlsx') {
            throw new K2XlsxNormalizationException(
                'invalid_extension',
                'Source workbook must use the .xlsx extension.',
                ['source_filename' => basename($sourceRealPath)]
            );
        }

        $signature = $this->readSignature($sourceRealPath);
        if ($signature !== self::EXPECTED_XLSX_SIGNATURE) {
            throw new K2XlsxNormalizationException(
                'invalid_signature',
                'Source workbook does not have the expected XLSX ZIP signature.',
                ['source_filename' => basename($sourceRealPath)]
            );
        }

        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
            throw new K2XlsxNormalizationException(
                'output_directory_unavailable',
                'Temporary CSV output directory could not be created.',
                ['output_directory' => $outputDirectory]
            );
        }

        $outputRealPath = realpath($outputDirectory);
        if ($outputRealPath === false || !is_dir($outputRealPath) || !is_writable($outputRealPath)) {
            throw new K2XlsxNormalizationException(
                'output_directory_unavailable',
                'Temporary CSV output directory is not writable.',
                ['output_directory' => $outputDirectory]
            );
        }

        if ($this->samePath(dirname($sourceRealPath), $outputRealPath)) {
            throw new K2XlsxNormalizationException(
                'unsafe_output_directory',
                'Temporary CSV output directory must not be the source fixture directory.',
                ['source_directory' => dirname($sourceRealPath), 'output_directory' => $outputRealPath]
            );
        }

        $temporaryPath = tempnam($outputRealPath, 'k2_' . $datasetType . '_');
        if ($temporaryPath === false) {
            throw new K2XlsxNormalizationException(
                'temporary_file_unavailable',
                'Temporary CSV file could not be created.',
                ['output_directory' => $outputRealPath]
            );
        }

        $csvPath = $temporaryPath . '.csv';
        if (!rename($temporaryPath, $csvPath)) {
            @unlink($temporaryPath);
            throw new K2XlsxNormalizationException(
                'temporary_file_unavailable',
                'Temporary CSV file could not be renamed to .csv.',
                ['output_directory' => $outputRealPath]
            );
        }

        return [
            'dataset_type' => $datasetType,
            'schema_label' => $schema['label'],
            'source_path' => $sourceRealPath,
            'source_filename' => basename($sourceRealPath),
            'source_hash' => hash_file('sha256', $sourceRealPath),
            'source_modified_at' => filemtime($sourceRealPath),
            'temporary_csv_path' => $csvPath,
            'started_at' => microtime(true),
            'memory_start' => memory_get_usage(true),
        ];
    }

    private function processRows(string $datasetType, array $schema, array $context, iterable $rows, array $sheetNames): array
    {
        $csvHandle = fopen($context['temporary_csv_path'], 'wb');
        if ($csvHandle === false) {
            throw new K2XlsxNormalizationException(
                'temporary_file_unavailable',
                'Temporary CSV file could not be opened for writing.',
                ['temporary_csv_path' => $context['temporary_csv_path']]
            );
        }

        $sourceRowCount = 0;
        $writtenCsvRowCount = 0;
        $headers = [];
        $expectedColumnCount = $schema['expected_column_count'];
        $warnings = [];
        $typeSummary = $this->emptyTypeSummary($schema['headers']);
        $rowsPadded = 0;
        $maxObservedColumnCount = 0;
        $formulaCells = 0;
        $formulaCellsWithCachedValues = 0;
        $formulaCellsWithoutCachedValues = 0;
        $errorCells = 0;
        $dateTimeCellsWithTime = 0;

        try {
            foreach ($rows as $row) {
                $sourceRowCount++;
                $cells = $this->rowToCells($row);
                $observedColumnCount = count($cells);
                $maxObservedColumnCount = max($maxObservedColumnCount, $observedColumnCount);

                if ($sourceRowCount === 1) {
                    $headers = $this->normalizeHeaderRow($cells);
                    $this->validateHeaders($datasetType, $schema, $headers);
                    // Optional trailing headers may be absent; follow what the file actually has.
                    $expectedColumnCount = count($headers);
                    $this->writeCsvRow($csvHandle, $headers);
                    $writtenCsvRowCount++;
                    continue;
                }

                if ($observedColumnCount > $expectedColumnCount) {
                    throw new K2XlsxNormalizationException(
                        'unexpected_data_column_count',
                        'A data row has more cells than the schema column count.',
                        [
                            'row_number' => $sourceRowCount,
                            'observed_column_count' => $observedColumnCount,
                            'expected_column_count' => $expectedColumnCount,
                        ]
                    );
                }

                if ($observedColumnCount < $expectedColumnCount) {
                    $rowsPadded++;
                }

                $csvRow = [];
                for ($i = 0; $i < $expectedColumnCount; $i++) {
                    $header = $headers[$i];
                    $columnStats = [
                        'formula_cells' => 0,
                        'formula_with_cached_value' => 0,
                        'formula_without_cached_value' => 0,
                        'error_cells' => 0,
                        'datetime_with_time' => 0,
                    ];
                    $normalizedValue = $this->normalizeCellValue($cells[$i] ?? null, $typeSummary[$header], $columnStats);
                    if (in_array($header, $schema['date_headers'], true)) {
                        $normalizedValue = $this->normalizeDateColumnValue($normalizedValue, $sourceRowCount, $header);
                    }
                    $csvRow[] = $normalizedValue;
                    $formulaCells += $columnStats['formula_cells'];
                    $formulaCellsWithCachedValues += $columnStats['formula_with_cached_value'];
                    $formulaCellsWithoutCachedValues += $columnStats['formula_without_cached_value'];
                    $errorCells += $columnStats['error_cells'];
                    $dateTimeCellsWithTime += $columnStats['datetime_with_time'];
                }

                $this->writeCsvRow($csvHandle, $csvRow);
                $writtenCsvRowCount++;
            }
        } finally {
            fclose($csvHandle);
        }

        if ($sourceRowCount === 0) {
            throw new K2XlsxNormalizationException('empty_workbook', 'Selected worksheet contains no rows.');
        }

        if ($rowsPadded > 0) {
            $warnings[] = "Rows with fewer cells than expected were right-padded with empty CSV fields: {$rowsPadded}.";
        }
        if ($formulaCellsWithoutCachedValues > 0) {
            $warnings[] = "Formula cells without cached values were serialized as formula text: {$formulaCellsWithoutCachedValues}.";
        }
        if ($errorCells > 0) {
            $warnings[] = "XLSX error cells were serialized as empty CSV fields: {$errorCells}.";
        }
        if ($dateTimeCellsWithTime > 0) {
            $warnings[] = "DateTime cells with a non-midnight time were serialized as Y-m-d H:i:s: {$dateTimeCellsWithTime}.";
        }

        clearstatcache(true, $context['source_path']);
        $sourceHashAfter = hash_file('sha256', $context['source_path']);
        $sourceModifiedAtAfter = filemtime($context['source_path']);
        if ($sourceHashAfter !== $context['source_hash'] || $sourceModifiedAtAfter !== $context['source_modified_at']) {
            throw new K2XlsxNormalizationException(
                'source_modified',
                'Source workbook hash or modification time changed during normalization.',
                ['source_filename' => $context['source_filename']]
            );
        }

        return [
            'dataset_type' => $datasetType,
            'source_filename' => $context['source_filename'],
            'source_hash' => $context['source_hash'],
            'source_hash_after' => $sourceHashAfter,
            'source_modified_at' => $context['source_modified_at'],
            'source_modified_at_after' => $sourceModifiedAtAfter,
            'selected_worksheet' => $schema['worksheet'],
            'available_worksheets' => $sheetNames,
            'header_list' => $headers,
            'source_row_count' => $sourceRowCount,
            'written_csv_row_count' => $writtenCsvRowCount,
            'column_count' => $expectedColumnCount,
            'temporary_csv_path' => $context['temporary_csv_path'],
            'elapsed_time_seconds' => round(microtime(true) - $context['started_at'], 6),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'warning_list' => $warnings,
            'row_column_policy' => [
                'expected_column_count' => $expectedColumnCount,
                'max_observed_column_count' => $maxObservedColumnCount,
                'short_rows_padded' => $rowsPadded,
                'extra_cell_rows_rejected' => true,
            ],
            'cell_type_summary' => $typeSummary,
            'formula_summary' => [
                'formula_cells' => $formulaCells,
                'formula_cells_with_cached_values' => $formulaCellsWithCachedValues,
                'formula_cells_without_cached_values' => $formulaCellsWithoutCachedValues,
            ],
        ];
    }

    private function validateHeaders(string $datasetType, array $schema, array $headers): void
    {
        $optionalTrailing = count($schema['optional_trailing_headers'] ?? []);
        $minColumnCount = $schema['expected_column_count'] - $optionalTrailing;
        if (count($headers) < $minColumnCount || count($headers) > $schema['expected_column_count']) {
            throw new K2XlsxNormalizationException(
                'unexpected_header_count',
                'Header column count does not match the schema.',
                [
                    'dataset_type' => $datasetType,
                    'expected_column_count' => $schema['expected_column_count'],
                    'actual_column_count' => count($headers),
                ]
            );
        }

        if ($headers === [] || array_filter($headers, static fn(string $header): bool => trim($header) !== '') === []) {
            throw new K2XlsxNormalizationException('empty_header_row', 'Header row is empty.');
        }

        $duplicates = array_keys(array_filter(array_count_values($headers), static fn(int $count): bool => $count > 1));
        if ($duplicates !== []) {
            throw new K2XlsxNormalizationException(
                'duplicate_headers',
                'Header row contains duplicate headers.',
                ['duplicate_headers' => $duplicates]
            );
        }

        $missingMandatory = array_values(array_diff($schema['mandatory_headers'], $headers));
        if ($missingMandatory !== []) {
            throw new K2XlsxNormalizationException(
                'missing_mandatory_headers',
                'Header row is missing mandatory schema headers.',
                ['missing_headers' => $missingMandatory]
            );
        }

        $acceptedHeaders = array_slice($schema['headers'], 0, count($headers));
        if ($headers !== $acceptedHeaders) {
            $mismatches = [];
            foreach ($acceptedHeaders as $index => $expectedHeader) {
                $actualHeader = $headers[$index] ?? null;
                if ($actualHeader !== $expectedHeader) {
                    $mismatches[] = [
                        'column_number' => $index + 1,
                        'expected_header' => $expectedHeader,
                        'actual_header' => $actualHeader,
                    ];
                }
                if (count($mismatches) >= 10) {
                    break;
                }
            }

            throw new K2XlsxNormalizationException(
                'unexpected_headers',
                'Header wording or order does not match the schema.',
                ['first_mismatches' => $mismatches]
            );
        }
    }

    private function rowToCells(mixed $row): array
    {
        if ($row instanceof Row) {
            $cells = [];
            for ($i = 0; $i < $row->getNumCells(); $i++) {
                $cells[] = $row->getCellAtIndex($i);
            }

            return $cells;
        }

        if (is_array($row)) {
            return array_values($row);
        }

        throw new InvalidArgumentException('Rows must be OpenSpout Row instances or arrays.');
    }

    private function normalizeHeaderRow(array $cells): array
    {
        return array_map(function (mixed $cell): string {
            if ($cell instanceof FormulaCell) {
                $value = $cell->getComputedValue() ?? $cell->getValue();
            } elseif ($cell instanceof Cell) {
                $value = $cell->getValue();
            } else {
                $value = $cell;
            }

            if ($value instanceof DateTimeInterface) {
                return $this->formatDateTime($value);
            }

            if (is_bool($value)) {
                return $value ? 'TRUE' : 'FALSE';
            }

            if (is_float($value)) {
                return $this->formatFloat($value);
            }

            return $value === null ? '' : (string) $value;
        }, $cells);
    }

    private function normalizeCellValue(mixed $cell, array &$typeSummary, array &$columnStats): string
    {
        if ($cell instanceof FormulaCell) {
            $typeSummary['formula']++;
            $columnStats['formula_cells']++;

            $computedValue = $cell->getComputedValue();
            if ($computedValue !== null) {
                $typeSummary['formula_cached_result']++;
                $columnStats['formula_with_cached_value']++;
                return $this->normalizeCellValue($computedValue, $typeSummary, $columnStats);
            }

            $typeSummary['formula_without_cached_result']++;
            $columnStats['formula_without_cached_value']++;
            return $cell->getValue();
        }

        if ($cell instanceof ErrorCell) {
            $typeSummary['error']++;
            $columnStats['error_cells']++;
            return '';
        }

        $value = $cell instanceof Cell ? $cell->getValue() : $cell;

        if ($value === null || $value === '') {
            $typeSummary['null']++;
            return '';
        }

        if (is_string($value)) {
            $typeSummary['string']++;
            $typeSummary['non_empty']++;
            return $value;
        }

        if (is_int($value)) {
            $typeSummary['integer']++;
            $typeSummary['numeric']++;
            $typeSummary['non_empty']++;
            return (string) $value;
        }

        if (is_float($value)) {
            $typeSummary['float']++;
            $typeSummary['numeric']++;
            $typeSummary['non_empty']++;
            return $this->formatFloat($value);
        }

        if (is_bool($value)) {
            $typeSummary['boolean']++;
            $typeSummary['non_empty']++;
            return $value ? 'TRUE' : 'FALSE';
        }

        if ($value instanceof DateTimeInterface) {
            $typeSummary['datetime']++;
            $typeSummary['non_empty']++;
            if ($value->format('H:i:s') !== '00:00:00') {
                $columnStats['datetime_with_time']++;
            }

            return $this->formatDateTime($value);
        }

        if ($value instanceof DateInterval) {
            $typeSummary['dateinterval']++;
            $typeSummary['non_empty']++;
            return $value->format('P%yY%mM%dDT%hH%iM%sS');
        }

        $typeSummary['string']++;
        $typeSummary['non_empty']++;
        return (string) $value;
    }

    private function writeCsvRow(mixed $handle, array $fields): void
    {
        $result = fputcsv($handle, $fields, ',', '"', '', "\r\n");
        if ($result === false) {
            throw new K2XlsxNormalizationException('csv_write_failed', 'A CSV row could not be written.');
        }
    }

    private function normalizeDateColumnValue(string $value, int $rowNumber, string $header): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (is_numeric($value) && (float) $value > 1.0 && (float) $value < 80000.0) {
            $seconds = (int) round(((float) $value - 25569.0) * 86400);
            return $this->formatDateTime((new DateTimeImmutable('@' . $seconds))->setTimezone(new DateTimeZone('UTC')));
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new K2XlsxNormalizationException(
                'invalid_date_value',
                'A configured date column contains an unparseable value.',
                ['row_number' => $rowNumber, 'header' => $header]
            );
        }

        return $this->formatDateTime((new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('UTC')));
    }

    private function formatDateTime(DateTimeInterface $value): string
    {
        if ($value->format('H:i:s') === '00:00:00') {
            return $value->format('Y-m-d');
        }

        return $value->format('Y-m-d H:i:s');
    }

    private function formatFloat(float $value): string
    {
        if (!is_finite($value)) {
            return '';
        }

        if (floor($value) === $value) {
            return number_format($value, 0, '.', '');
        }

        return rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
    }

    private function readSignature(string $path): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new K2XlsxNormalizationException(
                'source_unreadable',
                'Source workbook could not be opened for signature validation.',
                ['source_filename' => basename($path)]
            );
        }

        try {
            return (string) fread($handle, 4);
        } finally {
            fclose($handle);
        }
    }

    private function samePath(string $left, string $right): bool
    {
        return strtolower(str_replace('\\', '/', $left)) === strtolower(str_replace('\\', '/', $right));
    }

    private function emptyTypeSummary(array $headers): array
    {
        $summary = [];
        foreach ($headers as $header) {
            $summary[$header] = [
                'null' => 0,
                'string' => 0,
                'integer' => 0,
                'float' => 0,
                'numeric' => 0,
                'boolean' => 0,
                'datetime' => 0,
                'dateinterval' => 0,
                'formula' => 0,
                'formula_cached_result' => 0,
                'formula_without_cached_result' => 0,
                'error' => 0,
                'non_empty' => 0,
            ];
        }

        return $summary;
    }
}
