<?php
declare(strict_types=1);

require_once __DIR__ . '/import_orchestrator.php';
require_once __DIR__ . '/xlsx_normalizer.php';
require_once __DIR__ . '/aon_global_lock.php';

final class K2AutomationParserAdapterException extends RuntimeException
{
    public function __construct(
        private readonly string $stableCode,
        private readonly string $safeMessage,
        private readonly bool $retryable = false,
        private readonly array $safeContext = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }

    public function stableCode(): string
    {
        return $this->stableCode;
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function safeContext(): array
    {
        return $this->safeContext;
    }
}

interface K2AutomationWorkbookNormalizerInterface
{
    public function normalize(string $datasetType, string $sourcePath, string $outputDirectory): array;
}

final class K2AutomationOpenSpoutWorkbookNormalizer implements K2AutomationWorkbookNormalizerInterface
{
    public function __construct(private readonly K2XlsxNormalizer $normalizer = new K2XlsxNormalizer())
    {
    }

    public function normalize(string $datasetType, string $sourcePath, string $outputDirectory): array
    {
        return $this->normalizer->normalize($datasetType, $sourcePath, $outputDirectory);
    }
}

final class K2AutomationParserAdapterConfig
{
    public function __construct(
        public readonly string $temporaryDirectory,
        public readonly string $importedBy = 'k2_automation',
        public readonly ?float $aonMaxRemovalRatio = null,
        public readonly int $aonGlobalLockTimeoutSeconds = 30
    ) {
        if (trim($temporaryDirectory) === '') {
            throw new InvalidArgumentException('Automation temporary directory is required.');
        }

        if ($aonMaxRemovalRatio !== null && ($aonMaxRemovalRatio < 0.0 || $aonMaxRemovalRatio > 1.0)) {
            throw new InvalidArgumentException('K2_AON_MAX_REMOVAL_RATIO must be between 0 and 1.');
        }

        if ($aonGlobalLockTimeoutSeconds < 0) {
            throw new InvalidArgumentException('K2_AON_GLOBAL_LOCK_TIMEOUT_SECONDS must not be negative.');
        }
    }

    public static function fromEnvironment(): self
    {
        $tempDir = getenv('K2_AUTOMATION_TEMP_DIR');
        $importedBy = getenv('K2_AUTOMATION_IMPORTED_BY');
        $removalRatio = getenv('K2_AON_MAX_REMOVAL_RATIO');
        $globalLockTimeout = getenv('K2_AON_GLOBAL_LOCK_TIMEOUT_SECONDS');

        return new self(
            $tempDir === false || trim($tempDir) === ''
                ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k2-automation'
                : trim($tempDir),
            $importedBy === false || trim($importedBy) === '' ? 'k2_automation' : trim($importedBy),
            $removalRatio === false || trim($removalRatio) === '' ? null : (float) $removalRatio,
            $globalLockTimeout === false || trim($globalLockTimeout) === '' ? 30 : (int) $globalLockTimeout
        );
    }
}

final class K2AutomationPreparedParserImport
{
    private bool $cleaned = false;

    public function __construct(
        public readonly string $operationType,
        public readonly array $csvPaths,
        public readonly array $preflight,
        public readonly array $normalization,
        public readonly ?K2ImportAdapterResult $preparedResult = null,
        private readonly ?K2AonGlobalImportLock $aonGlobalLock = null
    ) {
    }

    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }

        foreach ($this->csvPaths as $path) {
            K2XlsxNormalizer::cleanupTemporaryCsv(is_string($path) ? $path : null);
        }

        try {
            $this->aonGlobalLock?->release();
        } finally {
            $this->cleaned = true;
        }
    }
}

final class K2AutomationAonXlsxAdapter implements K2PreparedAonImportExecutorInterface
{
    private readonly K2AutomationParserAdapterConfig $config;

    public function __construct(
        private readonly PDO $pdo,
        private readonly K2AutomationWorkbookNormalizerInterface $normalizer = new K2AutomationOpenSpoutWorkbookNormalizer(),
        ?K2AutomationParserAdapterConfig $config = null
    ) {
        $this->config = $config ?? K2AutomationParserAdapterConfig::fromEnvironment();
    }

    public function importAon(array $context): K2ImportAdapterResult
    {
        return K2AutomationParserAdapterSupport::captureResult(function () use ($context): K2ImportAdapterResult {
            $prepared = $this->prepareAon($context);
            try {
                if ($prepared->preparedResult !== null) {
                    return $prepared->preparedResult;
                }

                return K2AutomationParserAdapterSupport::runInTransaction(
                    $this->pdo,
                    fn(): K2ImportAdapterResult => $this->executePreparedAon($prepared)
                );
            } finally {
                $prepared->cleanup();
            }
        });
    }

    public function prepareAon(array $context): K2AutomationPreparedParserImport
    {
        $catalogCsv = null;
        $priceCsv = null;
        $globalLock = null;

        try {
            $catalogPath = K2AutomationParserAdapterSupport::uploadedPath($context, 'catalog_file');
            $pricePath = K2AutomationParserAdapterSupport::uploadedPath($context, 'price_file');
            K2AutomationParserAdapterSupport::assertUploadedWorkbookMatchesSource(
                $context,
                K2ImportDatasetType::CATALOG,
                $catalogPath,
                'catalog_file'
            );
            K2AutomationParserAdapterSupport::assertUploadedWorkbookMatchesSource(
                $context,
                K2ImportDatasetType::PRICE,
                $pricePath,
                'price_file'
            );

            $catalogNormalization = $this->normalizer->normalize(
                K2ImportDatasetType::CATALOG,
                $catalogPath,
                $this->config->temporaryDirectory
            );
            $priceNormalization = $this->normalizer->normalize(
                K2ImportDatasetType::PRICE,
                $pricePath,
                $this->config->temporaryDirectory
            );
            $catalogCsv = (string) $catalogNormalization['temporary_csv_path'];
            $priceCsv = (string) $priceNormalization['temporary_csv_path'];

            $globalLock = K2AonGlobalImportLock::acquire($this->pdo, $this->config->aonGlobalLockTimeoutSeconds);
            $globalLock->requireAcquired();
            $preflight = K2AutomationAonPreflight::analyze($this->pdo, $catalogCsv, $priceCsv);
            K2AutomationAonPreflight::assertSafeToImport($preflight, $this->config->aonMaxRemovalRatio);

            $normalization = [
                'catalog_csv_rows' => (int) $catalogNormalization['written_csv_row_count'],
                'price_csv_rows' => (int) $priceNormalization['written_csv_row_count'],
            ];

            if ((bool) ($context['payload']['aon_preflight_only'] ?? false)) {
                return new K2AutomationPreparedParserImport(
                    K2ImportOperationType::AON,
                    [$catalogCsv, $priceCsv],
                    $preflight,
                    $normalization,
                    new K2ImportAdapterResult(
                        true,
                        [
                            'inserted_count' => 0,
                            'updated_count' => 0,
                            'skipped_count' => 0,
                            'warning_count' => 0,
                        ],
                        null,
                        null,
                        false,
                        [
                            'dry_run' => true,
                            'preflight' => K2AutomationParserAdapterSupport::safeCounts($preflight),
                            'normalization' => $normalization,
                        ]
                    ),
                    $globalLock
                );
            }

            return new K2AutomationPreparedParserImport(
                K2ImportOperationType::AON,
                [$catalogCsv, $priceCsv],
                $preflight,
                $normalization,
                null,
                $globalLock
            );
        } catch (Throwable $e) {
            K2XlsxNormalizer::cleanupTemporaryCsv($catalogCsv);
            K2XlsxNormalizer::cleanupTemporaryCsv($priceCsv);
            $globalLock?->release();
            throw $e;
        }
    }

    public function executePreparedAon(K2AutomationPreparedParserImport $prepared): K2ImportAdapterResult
    {
        if ($prepared->preparedResult !== null) {
            return $prepared->preparedResult;
        }

        if (!$this->pdo->inTransaction()) {
            throw new K2AutomationParserAdapterException(
                'SHARED_TRANSACTION_REQUIRED',
                'Prepared AON import requires a caller-owned database transaction.',
                true
            );
        }

        K2AutomationParserAdapterSupport::loadAonParser($this->pdo);
        $result = k2_parse_and_insert_dual_aon_internal(
            (string) $prepared->csvPaths[0],
            (string) $prepared->csvPaths[1],
            $this->config->importedBy,
            ['strict' => true]
        );
        if (isset($result['error'])) {
            throw new K2AutomationParserAdapterException(
                'AON_PARSER_VALIDATION_FAILED',
                'AON parser rejected the normalized CSV input.'
            );
        }

        return new K2ImportAdapterResult(
            true,
            K2AutomationParserAdapterSupport::aonCounters($result),
            null,
            null,
            false,
            [
                'preflight' => K2AutomationParserAdapterSupport::safeCounts($prepared->preflight),
                'normalization' => $prepared->normalization,
            ]
        );
    }
}

final class K2AutomationPrXlsxAdapter implements K2PreparedPrImportExecutorInterface
{
    private readonly K2AutomationParserAdapterConfig $config;

    public function __construct(
        private readonly PDO $pdo,
        private readonly K2AutomationWorkbookNormalizerInterface $normalizer = new K2AutomationOpenSpoutWorkbookNormalizer(),
        ?K2AutomationParserAdapterConfig $config = null
    ) {
        $this->config = $config ?? K2AutomationParserAdapterConfig::fromEnvironment();
    }

    public function importPr(array $context): K2ImportAdapterResult
    {
        return K2AutomationParserAdapterSupport::captureResult(function () use ($context): K2ImportAdapterResult {
            $prepared = $this->preparePr($context);
            try {
                return K2AutomationParserAdapterSupport::runInTransaction(
                    $this->pdo,
                    fn(): K2ImportAdapterResult => $this->executePreparedPr($prepared)
                );
            } finally {
                $prepared->cleanup();
            }
        });
    }

    public function preparePr(array $context): K2AutomationPreparedParserImport
    {
        $csv = null;
        try {
            $prPath = K2AutomationParserAdapterSupport::uploadedPath($context, 'pr_file');
            K2AutomationParserAdapterSupport::assertUploadedWorkbookMatchesSource(
                $context,
                K2ImportDatasetType::PR,
                $prPath,
                'pr_file'
            );

            $normalization = $this->normalizer->normalize(
                K2ImportDatasetType::PR,
                $prPath,
                $this->config->temporaryDirectory
            );
            $csv = (string) $normalization['temporary_csv_path'];
            $preflight = K2AutomationPrPreflight::analyze($this->pdo, $csv);
            K2AutomationPrPreflight::assertSafeToImport($preflight);

            return new K2AutomationPreparedParserImport(
                K2ImportOperationType::PR,
                [$csv],
                $preflight,
                ['pr_csv_rows' => (int) $normalization['written_csv_row_count']]
            );
        } catch (Throwable $e) {
            K2XlsxNormalizer::cleanupTemporaryCsv($csv);
            throw $e;
        }
    }

    public function executePreparedPr(K2AutomationPreparedParserImport $prepared): K2ImportAdapterResult
    {
        if (!$this->pdo->inTransaction()) {
            throw new K2AutomationParserAdapterException(
                'SHARED_TRANSACTION_REQUIRED',
                'Prepared PR import requires a caller-owned database transaction.',
                true
            );
        }

        $csv = (string) $prepared->csvPaths[0];
        K2AutomationParserAdapterSupport::loadPrParser($this->pdo);
        $result = k2_parse_and_insert_csv_internal(
            $csv,
            basename($csv),
            $this->config->importedBy,
            ['strict' => true]
        );
        if (isset($result['error'])) {
            throw new K2AutomationParserAdapterException(
                'PR_PARSER_VALIDATION_FAILED',
                'PR parser rejected the normalized CSV input.'
            );
        }

        return new K2ImportAdapterResult(
            true,
            K2AutomationParserAdapterSupport::prCounters($result),
            null,
            null,
            false,
            [
                'preflight' => K2AutomationParserAdapterSupport::safeCounts($prepared->preflight),
                'normalization' => $prepared->normalization,
            ]
        );
    }
}

final class K2AutomationAonPreflight
{
    public static function analyze(PDO $pdo, string $catalogCsv, string $priceCsv): array
    {
        $catalogSkuNames = [];
        $nameToSkus = [];
        $catalogRowCount = 0;

        K2AutomationCsvInspector::forEachDataRow($catalogCsv, static function (array $row) use (
            &$catalogRowCount,
            &$catalogSkuNames,
            &$nameToSkus
        ): void {
            $catalogRowCount++;
            $sku = trim((string) ($row['SKU'] ?? ''));
            $name = trim((string) (($row['DETAIL'] ?? '') ?: ($row['ITEM NAME'] ?? '')));
            if ($sku === '') {
                return;
            }
            $catalogSkuNames[$sku][] = $name;
            if ($name !== '') {
                $nameToSkus[$name][$sku] = true;
            }
        });

        $validAonSkus = [];
        $priceSkus = [];
        $priceRowCount = 0;
        K2AutomationCsvInspector::forEachDataRow($priceCsv, static function (array $row) use (
            &$priceRowCount,
            &$priceSkus,
            &$validAonSkus
        ): void {
            $priceRowCount++;
            $sku = trim((string) ($row['SKU'] ?? ''));
            $alokasi = trim((string) ($row['ALOKASI'] ?? ''));
            if ($sku !== '') {
                $priceSkus[$sku] = true;
            }
            if ($sku !== '' && $alokasi !== '') {
                $validAonSkus[$sku] = true;
            }
        });

        $activeSkus = self::activeAonSkus($pdo);
        $catalogSkus = array_fill_keys(array_keys($catalogSkuNames), true);
        $validSkuList = array_keys($validAonSkus);
        $activeSkuList = array_keys($activeSkus);
        $predictedDeletes = array_values(array_diff($activeSkuList, $validSkuList));
        $predictedInserts = array_values(array_diff($validSkuList, $activeSkuList));
        $predictedUpdates = array_values(array_intersect($validSkuList, $activeSkuList));

        return [
            'catalog_row_count' => $catalogRowCount,
            'price_row_count' => $priceRowCount,
            'catalog_unique_sku_count' => count($catalogSkuNames),
            'price_unique_sku_count' => count($priceSkus),
            'valid_aon_sku_count' => count($validAonSkus),
            'duplicate_sku_count' => count(array_filter(
                $catalogSkuNames,
                static fn(array $names): bool => count(array_unique($names)) > 1
            )),
            'duplicate_name_count' => count(array_filter(
                $nameToSkus,
                static fn(array $skus): bool => count($skus) > 1
            )),
            'matched_catalog_count' => count(array_intersect($validSkuList, array_keys($catalogSkus))),
            'unmatched_catalog_count' => count(array_diff($validSkuList, array_keys($catalogSkus))),
            'current_active_aon_count' => count($activeSkus),
            'predicted_inserted_count' => count($predictedInserts),
            'predicted_updated_count' => count($predictedUpdates),
            'predicted_soft_delete_count' => count($predictedDeletes),
            'predicted_soft_delete_ratio' => count($activeSkus) === 0 ? 0.0 : count($predictedDeletes) / count($activeSkus),
        ];
    }

    public static function assertSafeToImport(array $preflight, ?float $maxRemovalRatio): void
    {
        if ((int) $preflight['catalog_row_count'] < 1) {
            throw new K2AutomationParserAdapterException('AON_EMPTY_CATALOG', 'AON catalog file contains no data rows.');
        }
        if ((int) $preflight['price_row_count'] < 1) {
            throw new K2AutomationParserAdapterException('AON_EMPTY_PRICE', 'AON price file contains no data rows.');
        }
        if ((int) $preflight['valid_aon_sku_count'] < 1) {
            throw new K2AutomationParserAdapterException('AON_EMPTY_ALOKASI_SET', 'AON price file contains no valid allocation set.');
        }

        $removalCount = (int) $preflight['predicted_soft_delete_count'];
        $removalRatio = (float) $preflight['predicted_soft_delete_ratio'];
        if ($removalCount > 0 && $maxRemovalRatio === null) {
            throw new K2AutomationParserAdapterException(
                'AON_REMOVAL_GUARD_UNCONFIGURED',
                'AON import would remove active SKUs but no removal threshold is configured.',
                false,
                self::removalGuardContext($preflight, $maxRemovalRatio)
            );
        }
        if ($maxRemovalRatio !== null && $removalRatio > $maxRemovalRatio) {
            throw new K2AutomationParserAdapterException(
                'AON_REMOVAL_RATIO_EXCEEDED',
                'AON import predicted removal ratio exceeds the configured threshold.',
                false,
                self::removalGuardContext($preflight, $maxRemovalRatio)
            );
        }
    }

    // Numbers only, no SKU codes/PII -- safe to attach to the exception and log,
    // otherwise this computed ratio is discarded the moment it fires and can
    // never be reconstructed later (aon_skus keeps changing).
    private static function removalGuardContext(array $preflight, ?float $maxRemovalRatio): array
    {
        return [
            "current_active_aon_count" => $preflight["current_active_aon_count"] ?? null,
            "valid_aon_sku_count" => $preflight["valid_aon_sku_count"] ?? null,
            "predicted_soft_delete_count" => $preflight["predicted_soft_delete_count"] ?? null,
            "predicted_soft_delete_ratio" => $preflight["predicted_soft_delete_ratio"] ?? null,
            "max_removal_ratio" => $maxRemovalRatio,
        ];
    }

    private static function activeAonSkus(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT sku_code FROM aon_skus WHERE is_deleted = 0');
        $active = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sku) {
            $active[(string) $sku] = true;
        }

        return $active;
    }
}

final class K2AutomationPrPreflight
{
    public static function analyze(PDO $pdo, string $csv): array
    {
        $ticketIdentities = [];
        $emptyTicketCount = 0;
        $rowCount = 0;
        K2AutomationCsvInspector::forEachDataRow($csv, static function (array $row) use (
            &$rowCount,
            &$ticketIdentities,
            &$emptyTicketCount
        ): void {
            $rowCount++;
            $ticketNo = trim((string) ($row['TICKET NO.'] ?? ''));
            $ticketLine = trim((string) ($row['TICKET LINE ITEM'] ?? ''));
            if ($ticketNo === '') {
                $emptyTicketCount++;
                return;
            }
            $key = $ticketNo . '|' . $ticketLine;
            $ticketIdentities[$key] = ($ticketIdentities[$key] ?? 0) + 1;
        });

        $existing = [];
        try {
            $stmt = $pdo->query('SELECT ticket_no, ticket_line_item FROM prs');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $existing[(string) $row['ticket_no'] . '|' . (string) $row['ticket_line_item']] = true;
            }
        } catch (PDOException) {
            $existing = [];
        }

        $uniqueIncoming = array_keys($ticketIdentities);

        return [
            'row_count' => $rowCount,
            'valid_ticket_count' => count($uniqueIncoming),
            'empty_ticket_count' => $emptyTicketCount,
            'duplicate_ticket_line_identity_count' => count(array_filter(
                $ticketIdentities,
                static fn(int $count): bool => $count > 1
            )),
            'likely_insert_count' => count(array_diff($uniqueIncoming, array_keys($existing))),
            'likely_update_count' => count(array_intersect($uniqueIncoming, array_keys($existing))),
            'likely_noop_count' => 0,
        ];
    }

    public static function assertSafeToImport(array $preflight): void
    {
        if ((int) $preflight['row_count'] < 1 || (int) $preflight['valid_ticket_count'] < 1) {
            throw new K2AutomationParserAdapterException('PR_EMPTY_TICKET_SET', 'PR workbook contains no valid tickets.');
        }
    }
}

final class K2AutomationCsvInspector
{
    public static function forEachDataRow(string $csvPath, callable $callback): array
    {
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new K2AutomationParserAdapterException('CSV_UNREADABLE', 'Normalized CSV could not be opened.');
        }

        try {
            $headers = fgetcsv($handle, 0, ',');
            if ($headers === false) {
                return ['headers' => [], 'row_count' => 0];
            }
            $headers = array_map(static fn(mixed $header): string => trim((string) $header), $headers);
            $rowCount = 0;
            while (($values = fgetcsv($handle, 0, ',')) !== false) {
                if (array_filter($values, static fn(mixed $value): bool => trim((string) $value) !== '') === []) {
                    continue;
                }
                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = $values[$index] ?? '';
                }
                $rowCount++;
                $callback($row, $rowCount, $headers);
            }

            return ['headers' => $headers, 'row_count' => $rowCount];
        } finally {
            fclose($handle);
        }
    }

    public static function read(string $csvPath): array
    {
        $handle = fopen($csvPath, 'rb');
        if ($handle === false) {
            throw new K2AutomationParserAdapterException('CSV_UNREADABLE', 'Normalized CSV could not be opened.');
        }

        try {
            $headers = fgetcsv($handle, 0, ',');
            if ($headers === false) {
                return ['headers' => [], 'rows' => []];
            }
            $headers = array_map(static fn(mixed $header): string => trim((string) $header), $headers);
            $rows = [];
            while (($values = fgetcsv($handle, 0, ',')) !== false) {
                if (array_filter($values, static fn(mixed $value): bool => trim((string) $value) !== '') === []) {
                    continue;
                }
                $row = [];
                foreach ($headers as $index => $header) {
                    $row[$header] = $values[$index] ?? '';
                }
                $rows[] = $row;
            }

            return ['headers' => $headers, 'rows' => $rows];
        } finally {
            fclose($handle);
        }
    }
}

final class K2AutomationParserAdapterSupport
{
    public static function captureResult(callable $callback): K2ImportAdapterResult
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            return self::resultFromThrowable($e);
        }
    }

    public static function resultFromThrowable(Throwable $e): K2ImportAdapterResult
    {
        if ($e instanceof K2AutomationParserAdapterException) {
            return new K2ImportAdapterResult(
                false,
                [],
                $e->stableCode(),
                $e->safeMessage(),
                $e->retryable(),
                self::safeCounts($e->safeContext())
            );
        }

        if ($e instanceof K2XlsxNormalizationException) {
            return new K2ImportAdapterResult(
                false,
                [],
                'XLSX_VALIDATION_FAILED',
                'XLSX workbook failed automation validation: ' . $e->reason(),
                false,
                self::safeCounts($e->context())
            );
        }

        if ($e instanceof K2AonGlobalLockBusyException) {
            return new K2ImportAdapterResult(
                false,
                [],
                'AON_IMPORT_BUSY',
                'Another AON import is already running.',
                true
            );
        }

        return new K2ImportAdapterResult(
            false,
            [],
            'AUTOMATION_PARSER_FAILED',
            'Automation parser execution failed.',
            true
        );
    }

    public static function uploadedPath(array $context, string $field): string
    {
        $path = (string) ($context['files'][$field]['tmp_name'] ?? '');
        if ($path === '' || !is_file($path)) {
            throw new K2AutomationParserAdapterException('UPLOAD_FILE_MISSING', 'Uploaded XLSX file is unavailable.');
        }

        return $path;
    }

    public static function assertUploadedWorkbookMatchesSource(
        array $context,
        string $datasetType,
        string $path,
        string $field
    ): void
    {
        $uploadedName = (string) ($context['files'][$field]['name'] ?? '');
        if (strtolower(pathinfo($uploadedName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new K2AutomationParserAdapterException('XLSX_EXTENSION_REQUIRED', 'Uploaded file must use the .xlsx extension.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new K2AutomationParserAdapterException('XLSX_UNREADABLE', 'Uploaded XLSX file could not be opened.');
        }
        try {
            $signature = (string) fread($handle, 4);
        } finally {
            fclose($handle);
        }
        if ($signature !== "PK\x03\x04") {
            throw new K2AutomationParserAdapterException('XLSX_SIGNATURE_INVALID', 'Uploaded file is not a valid XLSX ZIP container.');
        }

        $source = self::sourceFileMetadata($context, $datasetType);
        $actualHash = hash_file('sha256', $path);
        if (!hash_equals(strtolower((string) ($source['sha256_content_hash'] ?? '')), $actualHash)) {
            throw new K2AutomationParserAdapterException('UPLOAD_HASH_MISMATCH', 'Uploaded XLSX hash does not match registered metadata.');
        }
    }

    public static function sourceFileMetadata(array $context, string $datasetType): array
    {
        foreach (($context['payload']['source_files'] ?? []) as $file) {
            if ((string) ($file['dataset_type'] ?? '') === $datasetType) {
                return $file;
            }
        }

        throw new K2AutomationParserAdapterException('SOURCE_METADATA_MISSING', 'Registered source metadata is missing.');
    }

    public static function runInTransaction(PDO $pdo, callable $callback): K2ImportAdapterResult
    {
        if ($pdo->inTransaction()) {
            throw new K2AutomationParserAdapterException(
                'NESTED_TRANSACTION_UNSAFE',
                'Automation parser cannot run inside an existing database transaction.',
                true
            );
        }

        $pdo->beginTransaction();
        try {
            $result = $callback();
            $pdo->commit();

            if ($result instanceof K2ImportAdapterResult) {
                return $result;
            }

            return new K2ImportAdapterResult(true, is_array($result) ? $result : []);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    public static function loadPrParser(PDO $pdo): void
    {
        $GLOBALS['pdo'] = $pdo;
        require_once __DIR__ . '/../excel_parser.php';
    }

    public static function loadAonParser(PDO $pdo): void
    {
        $GLOBALS['pdo'] = $pdo;
        require_once __DIR__ . '/../aon_parser.php';
    }

    public static function prCounters(array $result): array
    {
        return [
            'inserted_count' => (int) ($result['inserted'] ?? 0),
            'updated_count' => (int) ($result['updated'] ?? 0),
            'skipped_count' => (int) ($result['skipped'] ?? 0),
            'warning_count' => (int) ($result['warning_count'] ?? 0),
        ];
    }

    public static function aonCounters(array $result): array
    {
        return [
            'inserted_count' => (int) ($result['inserted'] ?? 0),
            'updated_count' => (int) ($result['updated'] ?? 0),
            'skipped_count' => (int) ($result['skipped'] ?? 0),
            'warning_count' => (int) ($result['dup_sku_count'] ?? 0)
                + (int) ($result['dup_name_count'] ?? 0)
                + (int) ($result['unmatched_count'] ?? 0),
        ];
    }

    public static function safeCounts(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
