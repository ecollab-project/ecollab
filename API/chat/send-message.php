<?php
declare(strict_types=1);

// ── Catch absolutely everything, including fatal errors ──────────────────────
// This runs even if PHP dies mid-execution
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // Only output if no response sent yet, or if headers are still changeable
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
        }
        $debug = defined('APP_DEBUG') && APP_DEBUG;
        echo json_encode([
            'error' => $debug
                ? '[FATAL] ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line']
                : 'Server error',
        ]);
    }
});

require_once dirname(__DIR__, 2) . '/config.php';

// Override .htaccess suppression in debug mode
if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', '0'); // keep display off (avoid corrupting JSON)
    ini_set('log_errors', '1');
    error_reporting(E_ALL);
}

require_once dirname(__DIR__, 2) . '/database/config/db.php';
require_once dirname(__DIR__, 2) . '/security/middleware/AuthMiddleware.php';
require_once dirname(__DIR__, 2) . '/services/MessageService.php';

header('Content-Type: application/json');
AuthMiddleware::startSession();
$user = AuthMiddleware::requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

AuthMiddleware::verifyCsrf();

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $channelId = filter_var($body['channel_id'] ?? 0, FILTER_VALIDATE_INT);
    if (!$channelId) {
        http_response_code(400);
        echo json_encode(['error' => 'channel_id is required']);
        exit;
    }

    $service = new MessageService();
    $message = $service->sendMessage((int)$channelId, $user['id'], $body);

    http_response_code(201);
    echo json_encode(['success' => true, 'message' => $message]);

} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);

} catch (RuntimeException $e) {
    $code = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 500;
    http_response_code($code);
    echo json_encode(['error' => $e->getMessage()]);

} catch (Throwable $e) {
    error_log('[Ecollab] send-message Throwable: ' . $e->getMessage()
        . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    echo json_encode([
        'error' => (defined('APP_DEBUG') && APP_DEBUG)
            ? $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'Server error',
    ]);
}
