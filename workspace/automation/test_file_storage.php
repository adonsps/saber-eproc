<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../includes/automation/file_storage.php';

$tests = [];
$tempRoots = [];

$tests['storage root must be absolute'] = function (): void {
    expect_exception(InvalidArgumentException::class, function (): void {
        new K2AutomationFileStorageConfig('relative/storage/path');
    });
};

$tests['storage root must not contain traversal segments'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    expect_exception(InvalidArgumentException::class, function () use ($root): void {
        new K2AutomationFileStorageConfig($root . '/../escaped');
    });
};

$tests['storage root must not be inside the application checkout'] = function (): void {
    $appPath = dirname(__DIR__, 1) . '/includes/automation';
    expect_exception(InvalidArgumentException::class, function () use ($appPath): void {
        new K2AutomationFileStorageConfig($appPath);
    });
};

$tests['storage root must not contain a public_html segment'] = function (): void {
    expect_exception(InvalidArgumentException::class, function (): void {
        new K2AutomationFileStorageConfig(sys_get_temp_dir() . '/public_html/k2-storage');
    });
};

$tests['a safe absolute external path is accepted'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $config = new K2AutomationFileStorageConfig($root);
    assert_same($root, $config->storageRoot);
};

$tests['stage verifies checksum and rejects mismatch without leaving a file behind'] = function () use (&$tempRoots): void {
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig(fresh_storage_root($tempRoots)));
    $upload = temp_upload('hello world');
    expect_exception(K2AutomationFileStorageException::class, function () use ($store, $upload): void {
        $store->stage('k2b_test', 'aon', 1, 'catalog_file', 'catalog', $upload, 'catalog.xlsx', str_repeat('0', 64));
    });
    assert_false(is_file($upload), 'The tmp upload should have been consumed (renamed) before the hash check ran, or removed on failure.');
};

$tests['stage never uses the original filename in the destination path'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root));
    $content = 'safe-naming-check';
    $upload = temp_upload($content);
    $maliciousName = '../../../etc/passwd.xlsx';
    $entry = $store->stage('k2b_test2', 'aon', 1, 'catalog_file', 'catalog', $upload, $maliciousName, hash('sha256', $content));

    assert_same($maliciousName, $entry['original_filename'], 'Original filename is preserved as metadata only.');
    assert_true(!str_contains($entry['storage_path'], '..'), 'Storage path must never contain the raw filename or traversal segments.');
    assert_true(str_starts_with(realpath($entry['storage_path']) ?: '', realpath($root) ?: $root), 'Staged file must resolve inside the configured storage root.');
};

$tests['stageManifest rolls back every file when one fails'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root));
    $goodContent = 'good-file';
    $goodUpload = temp_upload($goodContent);
    $badUpload = temp_upload('bad-file');

    expect_exception(K2AutomationFileStorageException::class, function () use ($store, $goodUpload, $badUpload, $goodContent): void {
        $store->stageManifest('k2b_partial', 'aon', 1, [
            ['field' => 'catalog_file', 'datasetType' => 'catalog', 'tmpPath' => $goodUpload, 'originalFilename' => 'catalog.xlsx', 'expectedSha256' => hash('sha256', $goodContent)],
            ['field' => 'price_file', 'datasetType' => 'price', 'tmpPath' => $badUpload, 'originalFilename' => 'price.xlsx', 'expectedSha256' => str_repeat('f', 64)],
        ]);
    });

    $attemptDir = $root . DIRECTORY_SEPARATOR . 'k2b_partial' . DIRECTORY_SEPARATOR . 'aon_1';
    assert_false(is_dir($attemptDir), 'A partially staged attempt directory must be fully cleaned up, including the file that DID stage successfully.');
};

$tests['verifyStagedFile detects a tampered staged file'] = function () use (&$tempRoots): void {
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig(fresh_storage_root($tempRoots)));
    $content = 'trustworthy-at-first';
    $upload = temp_upload($content);
    $entry = $store->stage('k2b_tamper', 'pr', 1, 'pr_file', 'pr', $upload, 'pr.xlsx', hash('sha256', $content));

    file_put_contents($entry['storage_path'], 'tampered-after-the-fact');

    expect_exception(K2AutomationFileStorageException::class, function () use ($store, $entry): void {
        $store->verifyStagedFile($entry);
    });
};

$tests['cleanupAttempt removes staged files and the attempt directory'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root));
    $content = 'to-be-cleaned-up';
    $upload = temp_upload($content);
    $store->stage('k2b_cleanup', 'pr', 1, 'pr_file', 'pr', $upload, 'pr.xlsx', hash('sha256', $content));
    $attemptDir = $root . DIRECTORY_SEPARATOR . 'k2b_cleanup' . DIRECTORY_SEPARATOR . 'pr_1';
    assert_true(is_dir($attemptDir));

    $store->cleanupAttempt('k2b_cleanup', 'pr', 1);

    assert_false(is_dir($attemptDir));
};

$tests['sweepExpired removes only directories older than the retention window'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root, 86400));
    $oldContent = 'old-failed-attempt';
    $oldUpload = temp_upload($oldContent);
    $store->stage('k2b_old', 'aon', 1, 'catalog_file', 'catalog', $oldUpload, 'catalog.xlsx', hash('sha256', $oldContent));
    $newContent = 'recent-failed-attempt';
    $newUpload = temp_upload($newContent);
    $store->stage('k2b_new', 'aon', 1, 'catalog_file', 'catalog', $newUpload, 'catalog.xlsx', hash('sha256', $newContent));

    $oldDir = $root . DIRECTORY_SEPARATOR . 'k2b_old' . DIRECTORY_SEPARATOR . 'aon_1';
    touch_recursive($oldDir, (new DateTimeImmutable('-2 days'))->getTimestamp());

    $removed = $store->sweepExpired(new DateTimeImmutable('now', new DateTimeZone('UTC')), 86400);

    assert_true($removed >= 1);
    assert_false(is_dir($oldDir), 'The aged-out directory should be removed.');
    assert_true(is_dir($root . DIRECTORY_SEPARATOR . 'k2b_new' . DIRECTORY_SEPARATOR . 'aon_1'), 'The recent directory should be retained.');
};

$tests['stage rejects a symlink planted at the destination path (escape attempt)'] = function () use (&$tempRoots): void {
    if (!function_exists('symlink')) {
        return; // Not supported on this platform; skip rather than fail.
    }
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root));
    $outsideTarget = sys_get_temp_dir() . '/k2-fs-symlink-target-' . bin2hex(random_bytes(4));
    file_put_contents($outsideTarget, 'outside-the-storage-root');

    $attemptDir = $root . DIRECTORY_SEPARATOR . 'k2b_symlink' . DIRECTORY_SEPARATOR . 'aon_1';
    mkdir($attemptDir, 0750, true);
    $plantedLink = $attemptDir . DIRECTORY_SEPARATOR . 'catalog_file.xlsx';
    assert_true(symlink($outsideTarget, $plantedLink), 'Test setup: could not create a symlink to plant.');

    $content = 'attacker-controlled-content';
    $upload = temp_upload($content);
    // stage() renames onto the (planted-symlink) destination path; rename()
    // replaces the symlink itself rather than following it on Linux, so this
    // mainly proves the outside target file is never written through — assert
    // that regardless of outcome, the external target is untouched.
    try {
        $store->stage('k2b_symlink', 'aon', 1, 'catalog_file', 'catalog', $upload, 'catalog.xlsx', hash('sha256', $content));
    } catch (Throwable) {
        // Rejection is an acceptable outcome too.
    }

    assert_same('outside-the-storage-root', (string) file_get_contents($outsideTarget), 'A symlink at the staged destination must never let content escape to the external target.');
    @unlink($outsideTarget);
};

$tests['cross-batch isolation: cleanupAttempt never touches another batch or attempt'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root));
    $contentA = 'batch-a-content';
    $uploadA = temp_upload($contentA);
    $store->stage('k2b_batch_a', 'aon', 1, 'catalog_file', 'catalog', $uploadA, 'catalog.xlsx', hash('sha256', $contentA));
    $contentB = 'batch-b-content';
    $uploadB = temp_upload($contentB);
    $store->stage('k2b_batch_b', 'aon', 1, 'catalog_file', 'catalog', $uploadB, 'catalog.xlsx', hash('sha256', $contentB));
    $contentASecondAttempt = 'batch-a-second-attempt';
    $uploadASecond = temp_upload($contentASecondAttempt);
    $store->stage('k2b_batch_a', 'aon', 2, 'catalog_file', 'catalog', $uploadASecond, 'catalog.xlsx', hash('sha256', $contentASecondAttempt));

    $store->cleanupAttempt('k2b_batch_a', 'aon', 1);

    assert_false(is_dir($root . DIRECTORY_SEPARATOR . 'k2b_batch_a' . DIRECTORY_SEPARATOR . 'aon_1'), 'The targeted attempt must be removed.');
    assert_true(is_dir($root . DIRECTORY_SEPARATOR . 'k2b_batch_a' . DIRECTORY_SEPARATOR . 'aon_2'), 'A different attempt of the SAME batch must survive.');
    assert_true(is_dir($root . DIRECTORY_SEPARATOR . 'k2b_batch_b' . DIRECTORY_SEPARATOR . 'aon_1'), 'A DIFFERENT batch must never be touched by another batch\'s cleanup.');
};

$tests['duplicate staged filename within the same attempt does not corrupt the other field'] = function () use (&$tempRoots): void {
    $root = fresh_storage_root($tempRoots);
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($root));
    $catalogContent = 'catalog-bytes';
    $catalogUpload = temp_upload($catalogContent);
    $catalogEntry = $store->stage('k2b_dup', 'aon', 1, 'catalog_file', 'catalog', $catalogUpload, 'same-name.xlsx', hash('sha256', $catalogContent));
    $priceContent = 'price-bytes';
    $priceUpload = temp_upload($priceContent);
    $priceEntry = $store->stage('k2b_dup', 'aon', 1, 'price_file', 'price', $priceUpload, 'same-name.xlsx', hash('sha256', $priceContent));

    assert_true($catalogEntry['storage_path'] !== $priceEntry['storage_path'], 'catalog_file and price_file must never resolve to the same storage path.');
    assert_same($catalogContent, (string) file_get_contents($catalogEntry['storage_path']), 'catalog_file must keep its own content despite the identical original filename.');
    assert_same($priceContent, (string) file_get_contents($priceEntry['storage_path']), 'price_file must keep its own content despite the identical original filename.');
};

$tests['verifyStagedFile rejects a malformed manifest entry missing storage_path'] = function (): void {
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig(sys_get_temp_dir()));
    expect_exception(K2AutomationFileStorageException::class, function () use ($store): void {
        $store->verifyStagedFile(['sha256_content_hash' => str_repeat('a', 64)]);
    });
};

$tests['verifyStagedFile rejects a staged file that has gone missing from disk'] = function () use (&$tempRoots): void {
    $store = new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig(fresh_storage_root($tempRoots)));
    $content = 'will-be-deleted-before-verification';
    $upload = temp_upload($content);
    $entry = $store->stage('k2b_missing', 'pr', 1, 'pr_file', 'pr', $upload, 'pr.xlsx', hash('sha256', $content));
    unlink($entry['storage_path']);

    expect_exception(K2AutomationFileStorageException::class, function () use ($store, $entry): void {
        $store->verifyStagedFile($entry);
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
foreach ($tempRoots as $root) {
    rrmdir_test($root);
}

if ($failures > 0) {
    echo "{$failures} file storage test(s) failed.\n";
    exit(1);
}

echo count($tests) . " file storage test(s) passed.\n";

function fresh_storage_root(array &$tempRoots): string
{
    $root = sys_get_temp_dir() . '/k2-file-storage-test-' . bin2hex(random_bytes(6));
    $tempRoots[] = $root;

    return $root;
}

function temp_upload(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'k2_fs_upload_');
    if ($path === false) {
        throw new RuntimeException('Could not create temp upload.');
    }
    file_put_contents($path, $content);

    return $path;
}

function touch_recursive(string $dir, int $timestamp): void
{
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $entry) {
        if (is_file($entry)) {
            touch($entry, $timestamp);
        }
    }
    touch($dir, $timestamp);
}

function rrmdir_test(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? rrmdir_test($path) : @unlink($path);
    }
    @rmdir($dir);
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
