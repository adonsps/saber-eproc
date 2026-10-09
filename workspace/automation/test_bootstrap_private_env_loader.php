<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This test runner is CLI-only.\n");
    exit(1);
}

require_once __DIR__ . '/../api/k2_automation/bootstrap.php';

$tests = [];

$tests['path resolution walks up five directories from the bootstrap directory'] = function (): void {
    $fixtureBase = sys_get_temp_dir() . '/k2_bootstrap_path_test_' . bin2hex(random_bytes(6));
    $bootstrapDir = $fixtureBase . '/a/b/c/d/e';
    mkdir($bootstrapDir, 0777, true);

    try {
        $resolved = k2_automation_private_env_loader_path($bootstrapDir);
        assert_same($fixtureBase . '/k2-private-config/load-k2-env.php', $resolved);
    } finally {
        remove_directory_recursive($fixtureBase);
    }
};

$tests['bootstrap succeeds and emits nothing when the private loader is absent'] = function (): void {
    $fixtureDir = make_temp_dir();

    try {
        $missingLoader = $fixtureDir . '/does-not-exist/load-k2-env.php';

        ob_start();
        k2_automation_load_private_env($missingLoader);
        $output = ob_get_clean();

        assert_same('', $output);
        assert_false(defined('K2_BOOTSTRAP_TEST_LOADER_RAN'), 'Loader must not run when the file is absent.');
    } finally {
        remove_directory_recursive($fixtureDir);
    }
};

$tests['bootstrap loads the private env file when present and readable'] = function (): void {
    $fixtureDir = make_temp_dir();

    try {
        $loaderPath = $fixtureDir . '/load-k2-env.php';
        file_put_contents(
            $loaderPath,
            "<?php\nputenv('K2_BOOTSTRAP_TEST_MARKER=fixture-loaded');\n"
        );

        ob_start();
        k2_automation_load_private_env($loaderPath);
        $output = ob_get_clean();

        assert_same('', $output, 'Loading the private env file must not emit output.');
        assert_same('fixture-loaded', getenv('K2_BOOTSTRAP_TEST_MARKER'));
    } finally {
        putenv('K2_BOOTSTRAP_TEST_MARKER');
        remove_directory_recursive($fixtureDir);
    }
};

$tests['the loader runs before any K2 configuration value is read'] = function (): void {
    $fixtureDir = make_temp_dir();

    try {
        $loaderPath = $fixtureDir . '/load-k2-env.php';
        file_put_contents(
            $loaderPath,
            "<?php\nputenv('K2_AUTOMATION_CLIENT_ID=fixture-client-id');\n" .
            "putenv('K2_AUTOMATION_HMAC_SECRET=fixture-hmac-secret');\n"
        );

        assert_same('', (string) getenv('K2_AUTOMATION_CLIENT_ID'), 'Precondition: env must be unset before the loader runs.');

        k2_automation_load_private_env($loaderPath);

        $config = K2AutomationApiConfig::fromEnvironment();
        assert_same('fixture-client-id', $config->clientId);
        assert_same('fixture-hmac-secret', $config->hmacSecret);
    } finally {
        putenv('K2_AUTOMATION_CLIENT_ID');
        putenv('K2_AUTOMATION_HMAC_SECRET');
        remove_directory_recursive($fixtureDir);
    }
};

$tests['the loader executes only once even if invoked twice'] = function (): void {
    $fixtureDir = make_temp_dir();

    try {
        $loaderPath = $fixtureDir . '/load-k2-env.php';
        file_put_contents(
            $loaderPath,
            "<?php\n\$GLOBALS['k2_bootstrap_test_load_count'] = (\$GLOBALS['k2_bootstrap_test_load_count'] ?? 0) + 1;\n"
        );

        $GLOBALS['k2_bootstrap_test_load_count'] = 0;
        k2_automation_load_private_env($loaderPath);
        k2_automation_load_private_env($loaderPath);

        assert_same(1, $GLOBALS['k2_bootstrap_test_load_count']);
    } finally {
        unset($GLOBALS['k2_bootstrap_test_load_count']);
        remove_directory_recursive($fixtureDir);
    }
};

$tests['an unreadable loader is skipped, not silently forced'] = function (): void {
    if (DIRECTORY_SEPARATOR !== '/') {
        return;
    }

    $fixtureDir = make_temp_dir();

    try {
        $loaderPath = $fixtureDir . '/load-k2-env.php';
        file_put_contents(
            $loaderPath,
            "<?php\nputenv('K2_BOOTSTRAP_TEST_MARKER=should-not-run');\n"
        );
        chmod($loaderPath, 0000);

        if (is_readable($loaderPath)) {
            // Running as root inside a container can bypass permission bits; nothing meaningful to assert.
            return;
        }

        k2_automation_load_private_env($loaderPath);

        assert_same(false, getenv('K2_BOOTSTRAP_TEST_MARKER'));
    } finally {
        chmod($loaderPath, 0644);
        putenv('K2_BOOTSTRAP_TEST_MARKER');
        remove_directory_recursive($fixtureDir);
    }
};

function make_temp_dir(): string
{
    $dir = sys_get_temp_dir() . '/k2_bootstrap_loader_test_' . bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);

    return $dir;
}

function remove_directory_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $items = scandir($dir);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            remove_directory_recursive($path);
        } else {
            chmod($path, 0644);
            unlink($path);
        }
    }

    rmdir($dir);
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $suffix = $message !== '' ? " ({$message})" : '';
        throw new RuntimeException('Assertion failed' . $suffix . '. Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_false(bool $value, string $message = 'Expected false.'): void
{
    if ($value) {
        throw new RuntimeException($message);
    }
}

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

if ($failures > 0) {
    echo "{$failures} test(s) failed.\n";
    exit(1);
}

echo count($tests) . " test(s) passed.\n";
