<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "K2 automation readiness check is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/api_config.php';
require_once __DIR__ . '/../includes/automation/file_storage.php';

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(k2_readiness_main());
}

function k2_readiness_main(): int
{
    $results = k2_readiness_collect_results();
    $failed = array_values(array_filter($results, static fn(array $row): bool => !$row['pass']));

    foreach ($results as $row) {
        echo sprintf(
            "[%s] %s: %s%s\n",
            $row['pass'] ? 'PASS' : 'FAIL',
            $row['name'],
            $row['detail'],
            $row['pass'] ? '' : ' | ' . $row['remediation']
        );
    }

    $capacity = k2_readiness_capacity_plan(
        k2_readiness_configured_max_upload_bytes(),
        k2_readiness_sample_file_sizes()
    );
    echo "\nEffective multipart capacity is calculated per real endpoint request, not by adding AON and PR together.\n";
    echo "Multipart overhead uses a conservative 10% allowance with a 1M minimum per request.\n";
    echo sprintf(
        "Configured application max: %s per file. Required upload_max_filesize: %s. Required post_max_size: %s.\n",
        ini_display($capacity['configured_app_max_file_bytes']),
        ini_display($capacity['required_upload_bytes']),
        ini_display($capacity['required_post_bytes'])
    );
    echo sprintf(
        "Current structural sample need: upload %s, post %s. Operational headroom target: upload %s, post %s.\n",
        ini_display($capacity['sample_upload_bytes']),
        ini_display($capacity['sample_post_bytes']),
        ini_display($capacity['recommended_upload_bytes']),
        ini_display($capacity['recommended_post_bytes'])
    );
    echo "These recommendations are not a verified Hostinger configuration.\n";

    return $failed === [] ? 0 : 2;
}

function k2_readiness_collect_results(
    ?array $iniOverrides = null,
    ?array $envOverrides = null,
    ?array $sampleFileSizes = null
): array {
    $results = [];
    $ini = static function (string $name) use ($iniOverrides): string {
        return $iniOverrides !== null && array_key_exists($name, $iniOverrides)
            ? (string) $iniOverrides[$name]
            : (string) ini_get($name);
    };
    $env = static function (string $name) use ($envOverrides): string|false {
        return $envOverrides !== null && array_key_exists($name, $envOverrides)
            ? (string) $envOverrides[$name]
            : getenv($name);
    };

    check($results, 'php_version', PHP_VERSION_ID >= 80200, 'PHP ' . PHP_VERSION, 'PHP 8.2 or newer is recommended.');
    check($results, 'ziparchive_ext_zip', class_exists('ZipArchive'), class_exists('ZipArchive') ? 'ZipArchive available' : 'ZipArchive missing', 'Install/enable ext-zip.');
    check($results, 'pdo_mysql', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'pdo_mysql available' : 'pdo_mysql missing', 'Install/enable PDO MySQL.');
    check($results, 'xmlreader', class_exists('XMLReader'), class_exists('XMLReader') ? 'XMLReader available' : 'XMLReader missing', 'Install/enable XMLReader.');
    check($results, 'xmlwriter', class_exists('XMLWriter'), class_exists('XMLWriter') ? 'XMLWriter available' : 'XMLWriter missing', 'Install/enable XMLWriter.');
    check($results, 'mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'mbstring available' : 'mbstring missing', 'Install/enable mbstring.');
    check($results, 'fileinfo', extension_loaded('fileinfo'), extension_loaded('fileinfo') ? 'fileinfo available' : 'fileinfo missing', 'Install/enable fileinfo.');
    check($results, 'temp_dir_writable', is_writable(sys_get_temp_dir()), sys_get_temp_dir(), 'Make PHP temporary directory writable.');

    $memoryBytes = ini_bytes($ini('memory_limit'));
    $maxExecution = (int) $ini('max_execution_time');
    $uploadMax = ini_bytes($ini('upload_max_filesize'));
    $postMax = ini_bytes($ini('post_max_size'));
    $maxUploads = (int) $ini('max_file_uploads');
    $configuredMaxUploadBytes = k2_readiness_configured_max_upload_bytes($env);
    $capacity = k2_readiness_capacity_plan($configuredMaxUploadBytes, $sampleFileSizes ?? k2_readiness_sample_file_sizes());

    check($results, 'memory_limit', $memoryBytes === -1 || $memoryBytes >= 256 * 1024 * 1024, ini_display($memoryBytes), 'Recommend at least 256M.');
    check($results, 'max_execution_time', $maxExecution === 0 || $maxExecution >= 300, (string) $maxExecution, 'Recommend at least 300 seconds for large XLSX imports.');
    check($results, 'app_max_upload_bytes', $configuredMaxUploadBytes >= 1, ini_display($configuredMaxUploadBytes), 'Set K2_AUTOMATION_MAX_UPLOAD_BYTES to a positive integer byte value.');
    check(
        $results,
        'upload_max_filesize',
        $uploadMax === -1 || $uploadMax >= $capacity['required_upload_bytes'],
        ini_display($uploadMax) . ' effective; app max ' . ini_display($capacity['configured_app_max_file_bytes']) . '; sample max ' . ini_display($capacity['sample_upload_bytes']),
        'Increase upload_max_filesize or lower K2_AUTOMATION_MAX_UPLOAD_BYTES.',
        ['required_bytes' => $capacity['required_upload_bytes'], 'sample_required_bytes' => $capacity['sample_upload_bytes']]
    );
    check(
        $results,
        'post_max_size',
        $postMax === -1 || $postMax >= $capacity['required_post_bytes'],
        ini_display($postMax) . ' effective; largest configured endpoint request ' . ini_display($capacity['required_post_bytes']) . '; sample need ' . ini_display($capacity['sample_post_bytes']),
        'Increase post_max_size for the largest actual endpoint request: max(AON catalog+price multipart, PR multipart).',
        ['required_bytes' => $capacity['required_post_bytes'], 'sample_required_bytes' => $capacity['sample_post_bytes']]
    );
    check($results, 'max_file_uploads', $maxUploads >= 2, (string) $maxUploads, 'At least 2 uploads are required for the AON endpoint request.');

    $publicPath = $env('K2_AUTOMATION_PUBLIC_API_BASE_PATH') ?: '';
    $publicPathOk = false;
    $publicPathDetail = $publicPath === '' ? 'not configured' : $publicPath;
    try {
        if ($publicPath !== '') {
            $publicPathDetail = K2AutomationPublicApiPath::normalizeBasePath($publicPath);
            $publicPathOk = true;
        }
    } catch (Throwable $e) {
        $publicPathDetail = 'invalid: ' . $e->getMessage();
    }
    check($results, 'public_api_base_path', $publicPathOk, $publicPathDetail, 'Set K2_AUTOMATION_PUBLIC_API_BASE_PATH to the public route, for example /vms/workspace/api/k2_automation.');
    check($results, 'api_client_id_present', trim((string) $env('K2_AUTOMATION_CLIENT_ID')) !== '', present_detail('K2_AUTOMATION_CLIENT_ID', $env), 'Configure API client ID.');
    check($results, 'hmac_secret_present', trim((string) $env('K2_AUTOMATION_HMAC_SECRET')) !== '', present_detail('K2_AUTOMATION_HMAC_SECRET', $env), 'Configure HMAC secret. Value is intentionally not printed.');

    $ratio = $env('K2_AON_MAX_REMOVAL_RATIO');
    $ratioOk = $ratio !== false && trim($ratio) !== '' && is_numeric($ratio) && (float) $ratio >= 0.0 && (float) $ratio <= 1.0;
    check($results, 'aon_removal_ratio', $ratioOk, $ratioOk ? (string) (float) $ratio : 'not configured/invalid', 'Set K2_AON_MAX_REMOVAL_RATIO between 0 and 1.');

    checkPositiveEnv($results, 'stale_operation_seconds', 'K2_AUTOMATION_STALE_OPERATION_SECONDS', 60, $env);
    checkPositiveEnv($results, 'batch_lock_seconds', 'K2_AUTOMATION_LOCK_SECONDS', 60, $env);
    checkNonNegativeEnv($results, 'aon_global_lock_timeout_seconds', 'K2_AON_GLOBAL_LOCK_TIMEOUT_SECONDS', $env);

    k2_readiness_check_async_queue($results, $env);

    return $results;
}

/** Async worker queue readiness (Area J): private storage, worker timing, migration presence, diagnostics. */
function k2_readiness_check_async_queue(array &$results, callable $env): void
{
    $storageRootEnv = $env('K2_AUTOMATION_STORAGE_ROOT');
    try {
        $storageConfig = new K2AutomationFileStorageConfig(
            $storageRootEnv === false || trim($storageRootEnv) === ''
                ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k2_automation_storage_readiness_probe'
                : trim($storageRootEnv)
        );
        $storageRoot = $storageConfig->storageRoot;
        check(
            $results,
            'storage_root_outside_public_html',
            true,
            $storageRootEnv === false || trim($storageRootEnv) === '' ? 'not configured — using an unsafe default; set explicitly for production' : $storageRoot,
            'Set K2_AUTOMATION_STORAGE_ROOT explicitly for production deployments.'
        );
    } catch (Throwable $e) {
        check($results, 'storage_root_outside_public_html', false, (string) $storageRootEnv, 'K2_AUTOMATION_STORAGE_ROOT is unsafe: ' . $e->getMessage());
        $storageRoot = null;
    }

    if ($storageRoot !== null) {
        $exists = is_dir($storageRoot);
        check(
            $results,
            'storage_root_exists',
            $exists,
            $exists ? $storageRoot : 'does not exist yet',
            'Create the directory (or let the worker create it on first use) and confirm the CLI user can write to its parent.'
        );

        $checkableDir = $exists ? $storageRoot : dirname($storageRoot);
        $writable = is_dir($checkableDir) && is_writable($checkableDir);
        check($results, 'storage_root_writable', $writable, $checkableDir, 'Make the storage root (or its parent, if not yet created) writable by the PHP/CLI user.');

        if (is_dir($checkableDir)) {
            $freeBytes = @disk_free_space($checkableDir);
            $minimumBytes = 200 * 1024 * 1024;
            check(
                $results,
                'storage_root_disk_space',
                $freeBytes !== false && $freeBytes >= $minimumBytes,
                $freeBytes === false ? 'unknown' : ini_display((int) $freeBytes) . ' free',
                'Ensure at least ' . ini_display($minimumBytes) . ' free for staged AON/PR uploads.'
            );
        }
    }

    check($results, 'cli_php_available', PHP_SAPI === 'cli', PHP_SAPI, 'The worker (workspace/cron/process_k2_import_queue.php) must be invoked via CLI PHP, not a web SAPI.');

    checkPositiveEnv($results, 'worker_lease_seconds', 'K2_AUTOMATION_WORKER_LEASE_SECONDS', 60, $env);
    checkPositiveEnv($results, 'worker_lock_seconds', 'K2_AUTOMATION_WORKER_LOCK_SECONDS', 60, $env);
    checkNonNegativeEnv($results, 'staged_retention_seconds', 'K2_AUTOMATION_STAGED_RETENTION_SECONDS', $env);
    checkNonNegativeEnv($results, 'failed_retention_seconds', 'K2_AUTOMATION_FAILED_RETENTION_SECONDS', $env);

    // Two engine-specific variants exist (MySQL uses DROP CHECK, MariaDB uses
    // DROP CONSTRAINT — see db_backups/k2_drive_automation_migration_readme.md).
    // This check is deliberately engine-agnostic: it accepts whichever variant
    // is present on disk rather than requiring a DB connection to detect the
    // engine, since this checker otherwise has no DB dependency at all.
    $migrationCandidates = [
        dirname(__DIR__, 2) . '/db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql',
        dirname(__DIR__, 2) . '/db_backups/k2_drive_automation_phase8_async_queue_schema_mariadb.sql',
    ];
    $foundMigrationPath = null;
    foreach ($migrationCandidates as $candidate) {
        if (is_file($candidate)) {
            $foundMigrationPath = $candidate;
            break;
        }
    }
    $migrationPresent = $foundMigrationPath !== null;
    check(
        $results,
        'phase8_migration_present',
        $migrationPresent,
        $migrationPresent ? ('present: ' . basename($foundMigrationPath)) : 'missing (checked _mysql.sql and _mariadb.sql variants)',
        'Ensure the engine-appropriate db_backups/k2_drive_automation_phase8_async_queue_schema_{mysql,mariadb}.sql (or the fresh-install schema, for new installs) is deployed and applied.'
    );
    if ($migrationPresent) {
        $migrationSql = (string) file_get_contents($foundMigrationPath);
        $hasClaimIndex = str_contains($migrationSql, 'idx_k2_auto_attempt_queue_claim');
        check(
            $results,
            'queue_claim_index_defined',
            $hasClaimIndex,
            $hasClaimIndex ? 'present' : 'missing',
            'The worker claim query depends on idx_k2_auto_attempt_queue_claim — the migration file appears to have been modified; restore it from source control.'
        );
    }

    $logPathEnv = $env('K2_AUTOMATION_DIAGNOSTIC_LOG_PATH');
    $logPath = $logPathEnv === false || trim($logPathEnv) === ''
        ? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'k2_automation_diagnostics.log'
        : trim($logPathEnv);
    $logDir = dirname($logPath);
    check(
        $results,
        'diagnostic_log_location_writable',
        is_dir($logDir) && is_writable($logDir),
        $logDir,
        'Ensure the diagnostic log directory exists and is writable by the PHP/CLI user (K2_AUTOMATION_DIAGNOSTIC_LOG_PATH).'
    );
}

function check(array &$results, string $name, bool $pass, string $detail, string $remediation, array $extra = []): void
{
    $results[] = $extra + [
        'name' => $name,
        'pass' => $pass,
        'detail' => $detail,
        'remediation' => $remediation,
    ];
}

function checkPositiveEnv(array &$results, string $name, string $envName, int $minimum, ?callable $env = null): void
{
    $env ??= static fn(string $name): string|false => getenv($name);
    $value = $env($envName);
    $ok = $value !== false && trim($value) !== '' && ctype_digit(trim($value)) && (int) $value >= $minimum;
    check($results, $name, $ok, $ok ? trim($value) : 'not configured/invalid', "Set {$envName} to an integer >= {$minimum}.");
}

function checkNonNegativeEnv(array &$results, string $name, string $envName, ?callable $env = null): void
{
    $env ??= static fn(string $name): string|false => getenv($name);
    $value = $env($envName);
    $ok = $value !== false && trim($value) !== '' && ctype_digit(trim($value));
    check($results, $name, $ok, $ok ? trim($value) : 'not configured/invalid', "Set {$envName} to a non-negative integer.");
}

function present_detail(string $envName, ?callable $env = null): string
{
    $env ??= static fn(string $name): string|false => getenv($name);
    $value = $env($envName);
    return $value === false || trim($value) === '' ? 'missing' : 'present';
}

function k2_readiness_configured_max_upload_bytes(?callable $env = null): int
{
    $env ??= static fn(string $name): string|false => getenv($name);
    $value = $env('K2_AUTOMATION_MAX_UPLOAD_BYTES');

    return $value === false || trim($value) === ''
        ? 67108864
        : (int) $value;
}

function k2_readiness_capacity_plan(int $configuredMaxUploadBytes, array $sampleFileSizes): array
{
    $catalog = max(0, (int) ($sampleFileSizes['catalog'] ?? 0));
    $price = max(0, (int) ($sampleFileSizes['price'] ?? 0));
    $pr = max(0, (int) ($sampleFileSizes['pr'] ?? 0));
    $sampleAonRequest = k2_readiness_multipart_request_bytes($catalog + $price);
    $samplePrRequest = k2_readiness_multipart_request_bytes($pr);
    $requiredAonRequest = k2_readiness_multipart_request_bytes($configuredMaxUploadBytes * 2);
    $requiredPrRequest = k2_readiness_multipart_request_bytes($configuredMaxUploadBytes);
    $requiredPostBytes = max($requiredAonRequest, $requiredPrRequest);

    return [
        'configured_app_max_file_bytes' => $configuredMaxUploadBytes,
        'required_upload_bytes' => $configuredMaxUploadBytes,
        'required_post_bytes' => $requiredPostBytes,
        'sample_upload_bytes' => max($catalog, $price, $pr),
        'sample_post_bytes' => max($sampleAonRequest, $samplePrRequest),
        'recommended_upload_bytes' => (int) ceil($configuredMaxUploadBytes * 1.10),
        'recommended_post_bytes' => (int) ceil($requiredPostBytes * 1.10),
    ];
}

function k2_readiness_multipart_request_bytes(int $payloadFileBytes): int
{
    $payloadFileBytes = max(0, $payloadFileBytes);
    $percentageOverhead = (int) ceil($payloadFileBytes * 0.10);
    $fixedMinimumOverhead = 1024 * 1024;

    return $payloadFileBytes + max($percentageOverhead, $fixedMinimumOverhead);
}

function k2_readiness_sample_file_sizes(): array
{
    $repoRoot = dirname(__DIR__, 2);
    $fixtureDir = $repoRoot . DIRECTORY_SEPARATOR . 'local-fixtures';
    $defaults = [
        'pr' => 20 * 1024 * 1024,
        'catalog' => 20 * 1024 * 1024,
        'price' => 20 * 1024 * 1024,
    ];
    $filenames = [
        'pr' => 'Report Ticket Monitoring GP Officer.xlsx',
        'catalog' => 'Master Item Catalog.xlsx',
        'price' => 'Price Per Vendor And Tiering.xlsx',
    ];

    foreach ($filenames as $datasetType => $filename) {
        $path = $fixtureDir . DIRECTORY_SEPARATOR . $filename;
        if (is_file($path)) {
            $defaults[$datasetType] = (int) filesize($path);
        }
    }

    return $defaults;
}

function ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return $value === '-1' ? -1 : 0;
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

function ini_display(int $bytes): string
{
    if ($bytes === -1) {
        return 'unlimited';
    }
    if ($bytes >= 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . 'M';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . 'K';
    }

    return $bytes . 'B';
}
