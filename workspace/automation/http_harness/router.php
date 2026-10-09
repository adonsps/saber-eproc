<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server' || getenv('K2_AUTOMATION_HTTP_HARNESS') !== '1') {
    http_response_code(404);
    echo "Not found.";
    return;
}

require_once __DIR__ . '/../../includes/automation/api_controller.php';

// NOTE: import_aon/import_pr are queue-only as of the async worker queue (Area E).
// This harness now only observes the QUEUED response for those two endpoints —
// it no longer exercises synchronous execution. k2_http_harness_events stays for
// any future worker-facing harness needs; nothing writes to it here anymore.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (preg_match('#^/(?:vms/)?workspace/api/k2_automation/([a-z_]+)\.php$#', $path, $matches) !== 1) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'HARNESS_NOT_FOUND']);
    return;
}

$fileToEndpoint = [
    'register_batch' => 'register_batch',
    'batch_status' => 'batch_status',
    'import_aon' => 'import_aon',
    'import_pr' => 'import_pr',
    'start_archive' => 'start_archive',
    'archive_result' => 'archive_result',
];
$endpoint = $fileToEndpoint[$matches[1]] ?? null;
if ($endpoint === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'code' => 'HARNESS_UNKNOWN_ENDPOINT']);
    return;
}

$dsn = getenv('K2_AUTOMATION_TEST_DSN') ?: '';
$user = getenv('K2_AUTOMATION_TEST_USER') ?: '';
$password = getenv('K2_AUTOMATION_TEST_PASSWORD') ?: '';
$pdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$harnessStorageRoot = getenv('K2_AUTOMATION_STORAGE_ROOT') ?: (sys_get_temp_dir() . '/k2-automation-http-harness-storage');
$controller = new K2AutomationApiController(
    new K2AutomationApiConfig(
        getenv('K2_AUTOMATION_CLIENT_ID') ?: 'client-a',
        getenv('K2_AUTOMATION_HMAC_SECRET') ?: 'test-secret',
        300,
        1024 * 1024,
        60,
        '/vms/workspace/api/k2_automation',
        2
    ),
    new PdoK2AutomationNonceStore($pdo),
    new K2ImportOrchestrator(new PdoK2ImportRunRepository($pdo), 60, null, 2),
    new K2AutomationStagedFileStore(new K2AutomationFileStorageConfig($harnessStorageRoot)),
    new DateTimeImmutable('2026-07-22T02:00:00Z')
);

$response = $controller->handle(
    $endpoint,
    $_SERVER['REQUEST_METHOD'] ?? 'GET',
    $path,
    request_headers(),
    file_get_contents('php://input') ?: '',
    $_FILES,
    $_POST,
    $_SERVER
);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
echo json_encode($response->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

function request_headers(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        return is_array($headers) ? $headers : [];
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
