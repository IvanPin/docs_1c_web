<?php
declare(strict_types=1);

// php -S 127.0.0.1:18091 local/tools/certificates/mock_exchange_router.php
// Требует CERTIFICATE_MOCK_STATE и CERTIFICATE_MOCK_KEY. Доступен только в отдельном локальном PHP CLI server.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404); exit;
}
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/api/1c/certificates/index.php') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/php_interface/include/certificates/Purchase.php';
require_once dirname(__DIR__, 2) . '/php_interface/include/certificates/Exchange.php';
require_once __DIR__ . '/MockExchangeService.php';
class_alias(\App\Certificates\Testing\MockExchangeService::class, \App\Certificates\Service::class);
$key = (string)getenv('CERTIFICATE_MOCK_KEY');
$provided = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
$scopes = $key !== '' && hash_equals($key, $provided) ? ['certificates:read', 'certificates:issue'] : null;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Certificate-Mock: true');
header('X-Certificate-Contract-Version: ' . \App\Certificates\Exchange::VERSION);
header('Allow: GET, POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$response = \App\Certificates\Exchange::handle($_SERVER['REQUEST_METHOD'], $_GET,
    (string)file_get_contents('php://input', false, null, 0, 16001), $scopes);
http_response_code($response['httpStatus']);
echo json_encode($response['body'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
