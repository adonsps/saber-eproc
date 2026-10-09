<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "HTTP harness setup is CLI-only.\n");
    exit(1);
}

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';

if ($dsn === '' || $user === '') {
    fwrite(STDERR, "K2_AUTOMATION_TEST_DSN and K2_AUTOMATION_TEST_USER are required.\n");
    exit(1);
}

$pdo = connect_with_retries($dsn, $user, $password);
$pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
if ((getenv('K2_AUTOMATION_TEST_SCHEMA') ?: '') === 'fresh') {
    apply_migration($pdo, __DIR__ . '/../../../db_backups/k2_drive_automation_fresh_install_schema.sql');
} else {
    apply_migration($pdo, __DIR__ . '/../../../db_backups/k2_drive_automation_phase2_schema.sql');
    apply_migration($pdo, __DIR__ . '/../../../db_backups/k2_drive_automation_phase4_api_nonce_schema.sql');
    // Required for the async queue: the AON_QUEUED/PR_QUEUED batch states and the
    // worker claim/staged-manifest columns the CLI worker and controller depend on.
    apply_migration($pdo, __DIR__ . '/../../../db_backups/k2_drive_automation_phase8_async_queue_schema_mysql.sql');
}
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS k2_http_harness_events (' .
    'id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, ' .
    'operation_type VARCHAR(32) NOT NULL, ' .
    'request_id VARCHAR(128) NOT NULL, ' .
    'created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), ' .
    'KEY idx_operation_request (operation_type, request_id)' .
    ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
);

echo "HTTP harness database ready.\n";

function connect_with_retries(string $dsn, string $user, string $password): PDO
{
    $last = null;
    for ($i = 0; $i < 60; $i++) {
        try {
            return new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            $last = $e;
            usleep(500000);
        }
    }

    throw $last ?? new RuntimeException('Could not connect to MySQL.');
}

function apply_migration(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Could not read migration {$path}.");
    }

    $pdo->exec($sql);
}
