<?php
declare(strict_types=1);

function k2_automation_private_env_loader_path(string $bootstrapDir): string
{
    return dirname($bootstrapDir, 5) . '/k2-private-config/load-k2-env.php';
}

function k2_automation_load_private_env(string $loaderPath): void
{
    if (is_file($loaderPath) && is_readable($loaderPath)) {
        require_once $loaderPath;
    }
}

k2_automation_load_private_env(k2_automation_private_env_loader_path(__DIR__));

require_once __DIR__ . '/../../includes/automation/api_controller.php';
require_once __DIR__ . '/../../includes/automation/file_storage.php';
require_once __DIR__ . '/../../includes/automation/diagnostics_logger.php';

function k2_automation_read_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            return $headers;
        }
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$name] = (string) $value;
        }
    }

    return $headers;
}

function k2_automation_pdo(): PDO
{
    if (getenv('K2_AUTOMATION_TEST_DSN') !== false) {
        return new PDO(
            (string) getenv('K2_AUTOMATION_TEST_DSN'),
            (string) getenv('K2_AUTOMATION_TEST_USER'),
            (string) getenv('K2_AUTOMATION_TEST_PASSWORD'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    require __DIR__ . '/../../config/database.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new RuntimeException('Database connection is unavailable.');
    }

    return $pdo;
}

function k2_automation_emit(K2AutomationApiResponse $response): void
{
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value);
    }
    http_response_code($response->status);
    echo json_encode($response->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function k2_automation_handle_endpoint(string $endpoint): void
{
    try {
        $pdo = k2_automation_pdo();
        $config = K2AutomationApiConfig::fromEnvironment();
        $repo = new PdoK2ImportRunRepository($pdo);
        $orchestrator = new K2ImportOrchestrator($repo, $config->lockSeconds, null, $config->staleOperationSeconds);
        $fileStore = new K2AutomationStagedFileStore(K2AutomationFileStorageConfig::fromEnvironment());
        $controller = new K2AutomationApiController(
            $config,
            new PdoK2AutomationNonceStore($pdo),
            $orchestrator,
            $fileStore,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
            K2AutomationDiagnosticsLogger::fromEnvironment()
        );

        k2_automation_emit($controller->handle(
            $endpoint,
            (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH),
            k2_automation_read_headers(),
            (string) file_get_contents('php://input'),
            $_FILES,
            $_POST,
            $_SERVER
        ));
    } catch (Throwable) {
        k2_automation_emit(new K2AutomationApiResponse(
            500,
            K2ImportResponseFactory::build(
                false,
                'UNEXPECTED_SERVER_ERROR',
                'Unexpected server failure.',
                null,
                null,
                false,
                null,
                null,
                K2ImportNextAction::NONE,
                false,
                [],
                ['code' => 'UNEXPECTED_SERVER_ERROR', 'message' => 'Unexpected server failure.']
            ),
            K2AutomationApiController::jsonHeaders()
        ));
    }
}
