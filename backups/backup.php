<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(0);
}

$config = require __DIR__ . '/backup_config.php';

// Positional CLI overrides for remote_receiver_url/secret, e.g.:
//   php backup.php "https://host/backups/receive_backup.php" <secret>
// Exists because Hostinger's cPanel Cron Jobs UI caps the command field at
// 255 characters — "VSS_BACKUP_RECEIVER_URL=... VSS_BACKUP_RECEIVER_SECRET=..."
// inline env var assignments blow past that limit, while positional args
// (no variable names) fit comfortably. getenv() (via backup_config.php)
// remains the primary source for Docker/local dev; this is a Hostinger-only
// fallback, checked after so an explicit CLI arg always wins if both are set.
if (($argv[1] ?? '') !== '') {
    $config['remote_receiver_url'] = $argv[1];
}
if (($argv[2] ?? '') !== '') {
    $config['remote_receiver_secret'] = $argv[2];
}

main($config);

function main(array $config): void
{
    $zipPath = null;

    try {
        if (empty($config['use_system_temp_zip'])) {
            ensureDirectory($config['local_backup_dir']);
        }

        ensureDirectory(dirname($config['log_file']));

        logMessage($config, 'INFO', 'Backup dimulai.');

        $zipPath = createBackupZip($config);
        logMessage($config, 'INFO', 'ZIP berhasil dibuat: ' . $zipPath);

        $result = uploadZipToRemoteReceiver($config, $zipPath);
        logMessage($config, 'INFO', 'Upload ke receiver VPS berhasil. File: ' . $result['file_name']);
        logMessage($config, 'INFO', 'Retention selesai. File lama dihapus: ' . $result['deleted_count']);
        logMessage($config, 'INFO', 'Backup ditemukan di receiver: ' . $result['matched_count'] . '. Disimpan: ' . $result['kept_count']);
        if ($result['deleted_files'] !== []) {
            logMessage($config, 'INFO', 'File yang dihapus: ' . implode(', ', $result['deleted_files']));
        }
        $uploadedFileName = $result['file_name'];
        $deletedCount = $result['deleted_count'];

        if (!empty($config['delete_local_zip_after_upload']) && is_file($zipPath)) {
            unlink($zipPath);
            logMessage($config, 'INFO', 'ZIP lokal dihapus: ' . $zipPath);
        }

        logMessage($config, 'INFO', 'Backup selesai.');
        fwrite(STDOUT, sprintf(
            "Backup berhasil.\nFile: %s\nDitulis di receiver sebagai: %s\nFile lama dihapus: %d\nLog: %s\n",
            basename($zipPath),
            $uploadedFileName,
            $deletedCount,
            $config['log_file']
        ));
    } catch (Throwable $exception) {
        logMessage($config, 'ERROR', $exception->getMessage());

        if (!empty($config['delete_temp_zip_on_failure']) && is_string($zipPath) && is_file($zipPath)) {
            unlink($zipPath);
            logMessage($config, 'INFO', 'ZIP temporary dihapus setelah gagal: ' . $zipPath);
        }

        fwrite(STDERR, 'Backup gagal: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

function uploadZipToRemoteReceiver(array $config, string $zipPath): array
{
    if (!extension_loaded('curl')) {
        throw new RuntimeException('Ekstensi PHP curl belum aktif.');
    }

    $receiverUrl = $config['remote_receiver_url'] ?? '';
    $secret = $config['remote_receiver_secret'] ?? '';

    if ($receiverUrl === '') {
        throw new RuntimeException('remote_receiver_url belum diisi (VSS_BACKUP_RECEIVER_URL di .env).');
    }

    if ($secret === '') {
        throw new RuntimeException('remote_receiver_secret belum diisi (VSS_BACKUP_RECEIVER_SECRET di .env).');
    }

    $zipContent = file_get_contents($zipPath);
    if ($zipContent === false) {
        throw new RuntimeException('Gagal membaca file ZIP untuk upload: ' . $zipPath);
    }

    $payload = json_encode([
        'token' => $secret,
        'file_name' => basename($zipPath),
        'mime_type' => 'application/zip',
        'backup_name_prefix' => $config['backup_name_prefix'],
        'retention_days' => (int) $config['retention_days'],
        'max_backup_files' => (int) ($config['max_backup_files'] ?? 3),
        'content_base64' => base64_encode($zipContent),
    ], JSON_UNESCAPED_SLASHES);

    if ($payload === false) {
        throw new RuntimeException('Gagal membuat payload receiver.');
    }

    $response = httpRequest(
        'POST',
        $receiverUrl,
        [
            'Content-Type: application/json',
        ],
        $payload
    );

    $data = json_decode($response['body'], true);
    if (!is_array($data)) {
        throw new RuntimeException('Response receiver tidak valid: ' . $response['body']);
    }

    if (empty($data['ok'])) {
        throw new RuntimeException('Upload ke receiver gagal: ' . ($data['error'] ?? $response['body']));
    }

    return [
        'file_name' => (string) ($data['fileName'] ?? basename($zipPath)),
        'deleted_count' => (int) ($data['deletedCount'] ?? 0),
        'matched_count' => (int) ($data['matchedCount'] ?? 0),
        'kept_count' => (int) ($data['keptCount'] ?? 0),
        'deleted_files' => is_array($data['deletedFiles'] ?? null) ? $data['deletedFiles'] : [],
    ];
}

function createBackupZip(array $config): string
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException('Ekstensi PHP zip belum aktif.');
    }

    $timestamp = date('Y-m-d_H-i-s');
    $zipName = $config['backup_name_prefix'] . $timestamp . '.zip';
    $zipDirectory = !empty($config['use_system_temp_zip'])
        ? sys_get_temp_dir()
        : $config['local_backup_dir'];
    $zipPath = rtrim($zipDirectory, '/\\') . DIRECTORY_SEPARATOR . $zipName;

    $zip = new ZipArchive();
    $temporaryFiles = [];

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Gagal membuat file ZIP: ' . $zipPath);
    }

    try {
        foreach ($config['paths_to_backup'] as $relativePath) {
            $absolutePath = realpath($config['project_root'] . DIRECTORY_SEPARATOR . $relativePath);

            if ($absolutePath === false) {
                logMessage($config, 'WARNING', 'Path dilewati karena tidak ditemukan: ' . $relativePath);
                continue;
            }

            addPathToZip($zip, $absolutePath, normalizeZipPath($relativePath), $config);
        }

        if (!empty($config['include_existing_sql_dumps'])) {
            addExistingSqlDumpsToZip($zip, $config);
        }

        if (!empty($config['include_live_database_dump'])) {
            foreach (resolveLiveDatabaseConfigs($config) as $dbConfig) {
                $liveDumpPath = createLiveDatabaseDump($dbConfig, $config);
                $temporaryFiles[] = $liveDumpPath;
                $zipLocalName = 'db_backups/' . basename($liveDumpPath);

                if (!$zip->addFile($liveDumpPath, $zipLocalName)) {
                    throw new RuntimeException('Gagal menambahkan live database dump ke ZIP: ' . $liveDumpPath);
                }
            }
        }

        if ($zip->close() !== true) {
            throw new RuntimeException('Gagal menutup file ZIP: ' . $zipPath);
        }
    } catch (Throwable $exception) {
        $zip->close();
        cleanupTemporaryFiles($temporaryFiles, $config);
        throw $exception;
    }

    cleanupTemporaryFiles($temporaryFiles, $config);

    if (!is_file($zipPath) || filesize($zipPath) === 0) {
        throw new RuntimeException('ZIP gagal dibuat atau kosong: ' . $zipPath);
    }

    return $zipPath;
}

function resolveLiveDatabaseConfigs(array $config): array
{
    $sources = $config['live_database_sources'] ?? [];

    if (!is_array($sources) || $sources === []) {
        throw new RuntimeException('live_database_sources belum diisi di backups/config.php.');
    }

    $databases = [];

    foreach ($sources as $source) {
        if (!is_array($source)) {
            continue;
        }

        $databases[] = loadDatabaseConfigFromProjectConfig($source);
    }

    if ($databases === []) {
        throw new RuntimeException('Tidak ada database live yang valid untuk dibackup.');
    }

    return $databases;
}

function loadDatabaseConfigFromProjectConfig(array $source): array
{
    $configFile = realpath((string) ($source['config_file'] ?? ''));

    if ($configFile === false || !is_file($configFile)) {
        throw new RuntimeException('File config database tidak ditemukan: ' . ($source['config_file'] ?? ''));
    }

    $host = null;
    $user = null;
    $pass = null;
    $db = null;
    $charset = 'utf8mb4';
    $conn = null;
    $pdo = null;

    ob_start();
    include $configFile;
    ob_end_clean();

    if ($conn instanceof mysqli) {
        $conn->close();
    }

    if ($pdo instanceof PDO) {
        $pdo = null;
    }

    if (!is_string($host) || !is_string($user) || !is_string($db)) {
        throw new RuntimeException('Config database tidak memiliki variable $host, $user, dan $db: ' . $configFile);
    }

    return [
        'label' => (string) ($source['label'] ?? $db),
        'host' => $host,
        'port' => (int) (getenv('DB_PORT') ?: 3306),
        'user' => $user,
        'password' => is_string($pass) ? $pass : '',
        'name' => $db,
        'charset' => is_string($charset) ? $charset : 'utf8mb4',
        'dump_file_prefix' => (string) ($source['dump_file_prefix'] ?? (($source['label'] ?? 'live') . '_live_database_')),
    ];
}

function createLiveDatabaseDump(array $dbConfig, array $config): string
{
    if (!extension_loaded('mysqli')) {
        throw new RuntimeException('Ekstensi PHP mysqli belum aktif.');
    }

    $dbName = (string) ($dbConfig['name'] ?? '');

    if ($dbName === '') {
        throw new RuntimeException('Nama database live tidak ditemukan dari config aplikasi.');
    }

    $dumpFileName = ($dbConfig['dump_file_prefix'] ?? 'live_database_') . date('Y-m-d_H-i-s') . '.sql';
    $dumpPath = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . $dumpFileName;
    $handle = fopen($dumpPath, 'wb');

    if ($handle === false) {
        throw new RuntimeException('Gagal membuat file dump temporary: ' . $dumpPath);
    }

    $mysqli = mysqli_init();
    if (!$mysqli instanceof mysqli) {
        fclose($handle);
        throw new RuntimeException('Gagal inisialisasi mysqli.');
    }

    $host = (string) ($dbConfig['host'] ?? 'localhost');
    $user = (string) ($dbConfig['user'] ?? '');
    $password = (string) ($dbConfig['password'] ?? '');
    $port = (int) ($dbConfig['port'] ?? 3306);
    $charset = (string) ($dbConfig['charset'] ?? 'utf8mb4');

    try {
        if (!$mysqli->real_connect($host, $user, $password, $dbName, $port)) {
            throw new RuntimeException('Koneksi database gagal: ' . mysqli_connect_error());
        }

        $mysqli->set_charset($charset);
        writeSqlLine($handle, '-- Live database backup');
        writeSqlLine($handle, '-- Database: ' . $dbName);
        writeSqlLine($handle, '-- Created at: ' . date('Y-m-d H:i:s'));
        writeSqlLine($handle, 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";');
        writeSqlLine($handle, 'SET time_zone = "+00:00";');
        writeSqlLine($handle, 'SET FOREIGN_KEY_CHECKS = 0;');
        writeSqlLine($handle, '');

        $tables = getDatabaseTables($mysqli, $dbName);

        foreach ($tables as $tableName) {
            dumpDatabaseTable($mysqli, $handle, $tableName);
        }

        writeSqlLine($handle, 'SET FOREIGN_KEY_CHECKS = 1;');
        fclose($handle);
        $mysqli->close();

        logMessage($config, 'INFO', 'Live database dump berhasil dibuat untuk ' . ($dbConfig['label'] ?? $dbName) . ': ' . $dumpPath);
        return $dumpPath;
    } catch (Throwable $exception) {
        fclose($handle);
        $mysqli->close();

        if (is_file($dumpPath)) {
            unlink($dumpPath);
        }

        throw $exception;
    }
}

function getDatabaseTables(mysqli $mysqli, string $dbName): array
{
    $tables = [];
    $result = $mysqli->query('SHOW FULL TABLES FROM ' . quoteSqlIdentifier($dbName) . " WHERE Table_type = 'BASE TABLE'");

    if (!$result instanceof mysqli_result) {
        throw new RuntimeException('Gagal mengambil daftar tabel: ' . $mysqli->error);
    }

    while ($row = $result->fetch_array(MYSQLI_NUM)) {
        $tables[] = (string) $row[0];
    }

    $result->free();
    return $tables;
}

function dumpDatabaseTable(mysqli $mysqli, $handle, string $tableName): void
{
    $quotedTableName = quoteSqlIdentifier($tableName);
    $createResult = $mysqli->query('SHOW CREATE TABLE ' . $quotedTableName);

    if (!$createResult instanceof mysqli_result) {
        throw new RuntimeException('Gagal membaca struktur tabel ' . $tableName . ': ' . $mysqli->error);
    }

    $createRow = $createResult->fetch_assoc();
    $createResult->free();

    if (empty($createRow['Create Table'])) {
        throw new RuntimeException('SHOW CREATE TABLE tidak mengembalikan struktur untuk tabel: ' . $tableName);
    }

    writeSqlLine($handle, 'DROP TABLE IF EXISTS ' . $quotedTableName . ';');
    writeSqlLine($handle, $createRow['Create Table'] . ';');
    writeSqlLine($handle, '');

    $dataResult = $mysqli->query('SELECT * FROM ' . $quotedTableName, MYSQLI_USE_RESULT);

    if (!$dataResult instanceof mysqli_result) {
        throw new RuntimeException('Gagal membaca data tabel ' . $tableName . ': ' . $mysqli->error);
    }

    $fields = $dataResult->fetch_fields();
    $columns = array_map(
        static fn (object $field): string => '`' . str_replace('`', '``', $field->name) . '`',
        $fields
    );
    $columnList = implode(', ', $columns);

    while ($row = $dataResult->fetch_assoc()) {
        $values = [];

        foreach ($fields as $field) {
            $value = $row[$field->name];
            $values[] = sqlDumpValue($mysqli, $value);
        }

        writeSqlLine($handle, 'INSERT INTO ' . $quotedTableName . ' (' . $columnList . ') VALUES (' . implode(', ', $values) . ');');
    }

    $dataResult->free();
    writeSqlLine($handle, '');
}

function sqlDumpValue(mysqli $mysqli, $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . $mysqli->real_escape_string((string) $value) . "'";
}

function quoteSqlIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function writeSqlLine($handle, string $line): void
{
    fwrite($handle, $line . PHP_EOL);
}

function cleanupTemporaryFiles(array $files, array $config): void
{
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }

        unlink($file);
        logMessage($config, 'INFO', 'File temporary dihapus: ' . $file);
    }
}

function addExistingSqlDumpsToZip(ZipArchive $zip, array $config): void
{
    $sqlDumpDir = realpath($config['sql_dump_dir']);

    if ($sqlDumpDir === false || !is_dir($sqlDumpDir)) {
        throw new RuntimeException('Folder SQL dump tidak ditemukan: ' . $config['sql_dump_dir']);
    }

    $pattern = rtrim($sqlDumpDir, '/\\') . DIRECTORY_SEPARATOR . $config['sql_dump_pattern'];
    $sqlFiles = glob($pattern) ?: [];

    if ($sqlFiles === []) {
        throw new RuntimeException('Tidak ada file SQL dump yang ditemukan di: ' . $sqlDumpDir);
    }

    foreach ($sqlFiles as $sqlFile) {
        if (!is_file($sqlFile)) {
            continue;
        }

        $zipLocalName = 'db_backups/' . basename($sqlFile);
        if (!$zip->addFile($sqlFile, $zipLocalName)) {
            throw new RuntimeException('Gagal menambahkan SQL dump ke ZIP: ' . $sqlFile);
        }
    }
}

function addPathToZip(ZipArchive $zip, string $absolutePath, string $zipPath, array $config): void
{
    if (shouldExclude($absolutePath, $config)) {
        return;
    }

    if (is_file($absolutePath)) {
        if (!$zip->addFile($absolutePath, $zipPath)) {
            throw new RuntimeException('Gagal menambahkan file ke ZIP: ' . $absolutePath);
        }
        return;
    }

    if (!is_dir($absolutePath)) {
        return;
    }

    $zip->addEmptyDir($zipPath);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file) use ($config): bool {
                return !shouldExclude($file->getPathname(), $config);
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        $relativeItemPath = $zipPath . '/' . substr($itemPath, strlen($absolutePath) + 1);
        $relativeItemPath = normalizeZipPath($relativeItemPath);

        if ($item->isDir()) {
            $zip->addEmptyDir($relativeItemPath);
            continue;
        }

        if (!$zip->addFile($itemPath, $relativeItemPath)) {
            throw new RuntimeException('Gagal menambahkan file ke ZIP: ' . $itemPath);
        }
    }
}

function httpRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Gagal membuat curl handle.');
    }

    $method = strtoupper($method);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Request HTTP gagal: ' . $error);
    }

    if ($statusCode < 200 || $statusCode >= 300) {
        throw new RuntimeException(sprintf(
            'Request HTTP gagal. Method: %s. URL: %s. Status: %d. Response: %s',
            $method,
            $url,
            $statusCode,
            $responseBody
        ));
    }

    return [
        'status' => $statusCode,
        'body' => (string) $responseBody,
    ];
}

function shouldExclude(string $path, array $config): bool
{
    $name = basename($path);
    return in_array($name, $config['exclude_names'], true);
}

function normalizeZipPath(string $path): string
{
    return str_replace('\\', '/', trim($path, '/\\'));
}

function ensureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0755, true) && !is_dir($path)) {
        throw new RuntimeException('Gagal membuat folder: ' . $path);
    }
}

function logMessage(array $config, string $level, string $message): void
{
    $logFile = $config['log_file'] ?? (__DIR__ . '/logs/backup.log');
    $line = sprintf("[%s] [%s] %s%s", date('Y-m-d H:i:s'), strtoupper($level), $message, PHP_EOL);

    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
